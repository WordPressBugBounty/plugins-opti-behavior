<?php
/**
 * Optimizer / Cache Compatibility Layer
 *
 * Centralizes all page-optimizer and cache-plugin exclusion filters plus the
 * unified script_loader_tag attribute protection for Opti-Behavior tracking
 * scripts. Previously this logic was duplicated across several Free and Pro
 * feature classes; it now lives in one place and exposes a single extension
 * point — the `opti_behavior_protected_scripts` filter — so Pro (and any other
 * add-on) can contribute additional files, handles and inline-config keys.
 *
 * Registry buckets:
 *  - files:       script filenames matched by cache plugins (combine/defer/delay).
 *  - handles:     wp_enqueue_script handles protected via script_loader_tag attrs.
 *  - inline_keys: inline localize/config variable names (used by Phase B filters).
 *
 * @package opti-behavior
 * @copyright 2025-2026 OptiUser
 * @version 1.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central optimizer/cache compatibility handler.
 *
 * @since 1.7.1
 */
class Opti_Behavior_Optimizer_Compat {

	/**
	 * Static, cache-plugin-visible cookie name used to signal "this visitor
	 * has an A/B variant assignment, vary the cache" (R1).
	 *
	 * The real per-visitor assignment cookies (Opti_Behavior_AB_Test_Bucketer::
	 * COOKIE_PREFIX, 'opti_ab_<test_id>') are dynamically named per test, so
	 * they cannot be registered with cache plugins ahead of time. This
	 * companion cookie has a fixed name; Opti_Behavior_AB_Test_Bucketer sets
	 * it alongside every per-test cookie, and it is the one actually
	 * registered with WP Rocket / LiteSpeed below so a page cached for one
	 * visitor's variant is never served to a visitor with a different one.
	 *
	 * @since 1.7.1
	 * @var string
	 */
	const AB_VARIANT_COOKIE = 'opti_ab_v';

	/**
	 * Singleton instance.
	 *
	 * @since 1.7.1
	 * @var Opti_Behavior_Optimizer_Compat|null
	 */
	private static $instance = null;

	/**
	 * Double-registration guard. True once hooks have been registered.
	 *
	 * @since 1.7.1
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Per-request cache of the resolved protected-scripts registry.
	 *
	 * Null until first computed (lazy). Computed after plugins_loaded / during
	 * enqueue so Pro contributions to the filter are already registered.
	 *
	 * @since 1.7.1
	 * @var array|null
	 */
	private $protected = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since 1.7.1
	 * @return Opti_Behavior_Optimizer_Compat
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap the compatibility layer once.
	 *
	 * Idempotent: repeated calls are no-ops thanks to the static boot guard.
	 * This replaces the hooks that used to be registered from five separate
	 * feature-class constructors.
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
	 * Register all Phase-A optimizer/cache exclusion filters.
	 *
	 * @since 1.7.1
	 * @return void
	 */
	private function register_hooks() {
		// Autoptimize: exclude tracking scripts from JS aggregation.
		add_filter( 'autoptimize_filter_js_exclude', array( $this, 'autoptimize_js_excludes' ) );

		// LiteSpeed Cache: exclude from JS combine (files only — combine matches
		// the external script src) and defer (files AND inline keys — the defer
		// exclusion list is also matched against inline script content, same as
		// WP Rocket's rocket_delay_js_exclusions below).
		add_filter( 'litespeed_optimize_js_excludes', array( $this, 'litespeed_js_excludes' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( $this, 'litespeed_defer_exclusions' ) );

		// WP Rocket: exclude from JS combine and delay-JS execution.
		add_filter( 'rocket_exclude_js', array( $this, 'rocket_js_excludes' ) );
		add_filter( 'rocket_delay_js_exclusions', array( $this, 'rocket_delay_exclusions' ) );

		// WP Rocket: exclude inline-config blocks from JS Combine's inline-content
		// bundling. Verified filter (docs.wp-rocket.me). Fed inline-signature
		// keys, sibling of rocket_defer_inline_exclusions (Phase B) below.
		add_filter( 'rocket_excluded_inline_js_content', array( $this, 'rocket_combine_inline_content_excludes' ) );

		// WP Rocket / LiteSpeed: cache-vary cookie for A/B variant assignment
		// (R1). See AB_VARIANT_COOKIE doc comment above.
		add_filter( 'rocket_cache_dynamic_cookies', array( $this, 'rocket_dynamic_cookies' ) );
		add_filter( 'litespeed_vary_cookies', array( $this, 'litespeed_vary_cookies_list' ) );

		// SG Optimizer: exclude from JS combine. Verified filter
		// (siteground.com/tutorials/wordpress/speed-optimizer/custom-filters/).
		// Fed HANDLES per SG Optimizer's own docs (`$exclude_list[] =
		// 'script-handle'`), not filenames.
		add_filter( 'sgo_javascript_combine_exclude', array( $this, 'sgo_js_excludes' ) );

		// -- Phase B: extended optimizer coverage (all fed from same registry) --

		// WP Rocket: exclude from JS defer. Verified filter (docs.wp-rocket.me).
		// Matches how rocket_exclude_js is formatted (regex patterns).
		add_filter( 'rocket_exclude_defer_js', array( $this, 'rocket_js_excludes' ) );

		// WP Rocket: exclude inline configs from deferred inline JS. Verified
		// filter (docs.wp-rocket.me). Fed inline-signature keys.
		add_filter( 'rocket_defer_inline_exclusions', array( $this, 'rocket_defer_inline_exclusions' ) );

		// SG Optimizer: also exclude from JS minify and async. Verified filters
		// (siteground.com/tutorials/wordpress/speed-optimizer/custom-filters/),
		// siblings of sgo_javascript_combine_exclude. Fed HANDLES, same as combine
		// above -- SG Optimizer's own docs show `$exclude_list[] = 'script-handle'`
		// for all three filters, not filenames.
		add_filter( 'sgo_js_minify_exclude', array( $this, 'sgo_js_excludes' ) );
		add_filter( 'sgo_js_async_exclude', array( $this, 'sgo_js_excludes' ) );

		// SG Optimizer: exclude inline configs from JS combine. Verified filter
		// (core/Combinator/Js_Combinator.php). Fed inline-signature keys.
		add_filter( 'sgo_javascript_combine_excluded_inline_content', array( $this, 'sgo_inline_content_excludes' ) );

		// Perfmatters: exclude from delay-, defer- and minify-JS. Verified filters
		// (perfmatters.io/docs/filters). All fed plain filename strings.
		add_filter( 'perfmatters_delay_js_exclusions', array( $this, 'perfmatters_delay_exclusions' ) );
		add_filter( 'perfmatters_defer_js_exclusions', array( $this, 'perfmatters_defer_exclusions' ) );
		add_filter( 'perfmatters_minify_js_exclusions', array( $this, 'perfmatters_minify_exclusions' ) );

		// WP-Optimize: exclude from JS minify. Verified filter
		// (plugins.svn.wordpress.org/wp-optimize/trunk/minify/
		// class-wp-optimize-minify-functions.php, get_default_ignore()).
		// Matched via case-insensitive substring against the script URL, so
		// this feeds filenames only (handles never appear in the URL).
		add_filter( 'wp-optimize-minify-default-exclusions', array( $this, 'wp_optimize_minify_exclusions' ) );

		// Jetpack Boost: exclude from JS concatenation. Verified filter
		// (Automattic/jetpack, projects/plugins/boost/app/lib/minify/
		// class-concatenate-js.php, Concatenate_JS::do_items() — the same
		// page-optimize-lineage code Jetpack Boost bundles for its "Concatenate
		// JS" feature). Bool filter keyed on handle: apply_filters(
		// 'js_do_concat', $do_concat, $handle ).
		add_filter( 'js_do_concat', array( $this, 'jetpack_boost_do_concat' ), 10, 2 );

		// Jetpack Boost render-blocking / defer JS: no verifiable PHP exclusion
		// filter found in the Boost source (only documented mechanism is the
		// `data-jetpack-boost="ignore"` tag attribute — jetpack.com/support/
		// jetpack-boost/exclude-javascript-files-from-jetpack-boost-deferral/).
		// TODO(verify-needed): re-check if Boost adds a handle-array filter for
		// render-blocking/defer exclusion in a future release. Until then rely
		// on the attribute emitted by protect_script_tags() below.

		// W3 Total Cache: skip per-tag JS minification. Verified filter
		// (Minify_AutoJs.php). Bool filter keyed on tag/file containing a
		// registry filename.
		add_filter( 'w3tc_minify_js_do_tag_minification', array( $this, 'w3tc_do_tag_minification' ), 10, 3 );

		// Breeze: exclude from JS aggregation/move. Verified filter
		// (inc/minification/breeze-minification-scripts.php). URL-substring array
		// fed plain filenames.
		add_filter( 'breeze_js_dontmove', array( $this, 'breeze_js_dontmove' ) );

		// WP Fastest Cache: no PHP exclusion filter exists (UI-only exclude
		// rules); relies entirely on the tag-level attributes emitted by
		// protect_script_tags() below.
		//
		// Flying Press: third-party sources (not FlyingPress's own docs/source,
		// which is closed-source) describe a `flying_press_exclude_from_minify:js`
		// filter (array of filename/keyword substrings) but this is not primary-
		// source verified. TODO(verify-needed): confirm against FlyingPress's own
		// documentation/source before registering. Until verified, relies on the
		// tag-level attributes (data-noptimize / data-no-optimize / data-no-defer
		// / data-cfasync / nowprocket) emitted by protect_script_tags() below —
		// FlyingPress's docs confirm it checks the full script tag (src/id/inline
		// content) for exclusion keywords, so protected handles/filenames already
		// present in those attributes and src paths give partial coverage even
		// without the dedicated filter.

		// Other optimizers detected by the tracker heartbeat (see
		// Opti_Behavior_Tracker_Heartbeat::detect_culprit_plugins()) that have
		// no primary-source-verified PHP exclusion filter:
		//  - WP Super Cache / Cache Enabler: full-page caches only, never
		//    rewrite or merge JS — nothing to exclude.
		//  - Hummingbird, Swift Performance, Speed Booster Pack: exclusions are
		//    UI-driven; third-party sources mention filters but none verified
		//    against plugin source/docs. TODO(verify-needed). Tag attributes
		//    below give partial coverage.
		//  - NitroPack: optimization happens in their external service; no
		//    local PHP filter exists. Relies on tag attributes below.
		//  - Asset CleanUp: only unloads assets the admin explicitly selects —
		//    no automatic merging; nothing to exclude programmatically.
		//  - Flying Scripts: delays only user-listed keywords (opt-in) — no
		//    automatic mangling.
		//  - WP Meteor: defers ALL scripts with no exclusion filter; relies on
		//    tag attributes below plus the Phase C early-event queue.

		// Unified attribute protection for all tracking script tags.
		add_filter( 'script_loader_tag', array( $this, 'protect_script_tags' ), 10, 3 );

		// Preview/visual-editor bypass (R6 render fidelity): never let a cache
		// plugin persist a heatmap-preview or A/B visual-editor iframe render.
		$this->maybe_bypass_for_preview();
	}

	/**
	 * Resolve the protected-scripts registry (lazy, cached per request).
	 *
	 * Merges Free defaults through the `opti_behavior_protected_scripts` filter,
	 * allowing Pro/add-ons to contribute additional entries. Computed once on
	 * first filter callback so that contributions registered up to that point
	 * (including Pro's init-priority-11 / file-load registration) are present.
	 *
	 * @since 1.7.1
	 * @return array {
	 *     @type string[] $files       Script filenames.
	 *     @type string[] $handles     Enqueue handles.
	 *     @type string[] $inline_keys Inline config variable names.
	 * }
	 */
	private function get_protected() {
		if ( null !== $this->protected ) {
			return $this->protected;
		}

		$defaults = array(
			'files'       => array(
				'opti-behavior-heatmap-simple.js',
				// Cookieless anonymous ID broker (window.OptiBehaviorAnonBroker).
				// Dependency of EVERY anonymous-mode tracker (heatmap reporter, A/B,
				// funnel, and Pro's form-analytics / errors / session-recorder).
				// If an optimizer delays/defers this file (e.g. WP Rocket "Delay
				// JavaScript execution" rewrites it to type="rocketlazyloadscript"),
				// the broker is undefined when the already-excluded consumers run, so
				// their fallbacks mint random session_/anon_ ids per pageview → one
				// rogue session row per page view. Must be excluded from every
				// optimization (delay, defer, minify/combine, tag attrs) like the
				// trackers that depend on it.
				'opti-behavior-anon-broker.js',
				// Main heatmap script (handle 'opti-behavior', registered from
				// this file — see class-opti-behavior-heatmap-frontend.php
				// register_scripts()). Missing prior to 1.7.5 (R4 gap): WP
				// Rocket Combine JS could bundle it since only the *-simple.js
				// reporter file was registered.
				'opti-behavior-heatmap.min.js',
				'opti-behavior-consent.js',
				'ab-test-tracker.js',
				'funnel-tracker.js',
				// Debug logger + page-title override (R7 gap, 1.7.7): their
				// HANDLES were protected (tag attributes) but the filenames
				// were missing here, so URL-matched optimizations — WP Rocket
				// Minify/Defer via rocket_exclude_js / rocket_exclude_defer_js,
				// which ignore tag attributes — still rewrote them into
				// /cache/min/ and deferred them. Deferring the debug script
				// inverts load order with the reporter that depends on it;
				// deferring frontend-page-title.js makes its
				// `optiBehaviorHeatmapConfig.page_title = document.title`
				// override timing-dependent.
				'opti-behavior-debug.js',
				'frontend-page-title.js',
			),
			'handles'     => array(
				'opti-behavior',
				'opti-behavior-debug',
				// Anon ID broker handle — feeds script_loader_tag attribute
				// protection (data-cfasync/data-no-optimize/data-no-defer) and
				// Jetpack Boost concat skip. Paired with the file entry above.
				'opti-behavior-anon-broker',
				'opti-behavior-reporter',
				'opti-behavior-consent',
				'opti-behavior-frontend-page-title',
				'opti-behavior-funnel-tracker',
				'opti-behavior-ab-tracker',
				'opti-behavior-errors-tracker',
				'opti-behavior-form-analytics-tracker',
			),
			'inline_keys' => array(
				'optiBehavior',
				'opti_behavior',
				// Granular inline-config/localize variable names (R2/R3/R4),
				// listed explicitly alongside the 'optiBehavior' catch-all
				// above for optimizers that require an exact signature match
				// rather than a prefix/substring match.
				'optiBehaviorABConfig',
				'optiBehaviorAB',
				'optiBehaviorHeatmapConfig',
				'optiBehaviorConsentConfig',
				// Phase C early-event queue stub (wp_head priority 0, see
				// class-opti-behavior-heatmap-frontend.php print_early_event_queue()).
				'_obq',
			),
		);

		/**
		 * Filter the registry of Opti-Behavior scripts protected from optimizers.
		 *
		 * @since 1.7.1
		 *
		 * @param array $defaults {
		 *     @type string[] $files       Script filenames.
		 *     @type string[] $handles     Enqueue handles.
		 *     @type string[] $inline_keys Inline config variable names.
		 * }
		 */
		$registry = apply_filters( 'opti_behavior_protected_scripts', $defaults );

		// Normalize: guarantee all three buckets exist as unique-value arrays.
		$this->protected = array(
			'files'       => $this->normalize_bucket( $registry, 'files' ),
			'handles'     => $this->normalize_bucket( $registry, 'handles' ),
			'inline_keys' => $this->normalize_bucket( $registry, 'inline_keys' ),
		);

		return $this->protected;
	}

	/**
	 * Extract and normalize a single registry bucket to a unique string array.
	 *
	 * @since 1.7.1
	 * @param array  $registry Resolved registry (possibly malformed by a filter).
	 * @param string $bucket   Bucket key.
	 * @return string[] Unique, re-indexed list of non-empty string values.
	 */
	private function normalize_bucket( $registry, $bucket ) {
		if ( empty( $registry[ $bucket ] ) || ! is_array( $registry[ $bucket ] ) ) {
			return array();
		}
		$values = array_filter( $registry[ $bucket ], 'is_string' );
		return array_values( array_unique( $values ) );
	}

	/**
	 * Convenience accessor for the files bucket.
	 *
	 * @since 1.7.1
	 * @return string[]
	 */
	private function get_files() {
		$registry = $this->get_protected();
		return $registry['files'];
	}

	/**
	 * Convenience accessor for the handles bucket.
	 *
	 * @since 1.7.1
	 * @return string[]
	 */
	private function get_handles() {
		$registry = $this->get_protected();
		return $registry['handles'];
	}

	/**
	 * Convenience accessor for the inline_keys bucket.
	 *
	 * @since 1.7.1
	 * @return string[]
	 */
	private function get_inline_keys() {
		$registry = $this->get_protected();
		return $registry['inline_keys'];
	}

	/**
	 * Autoptimize: exclude tracking scripts from JS aggregation.
	 *
	 * Format: comma-separated string, each filename appended as ', file.js'.
	 *
	 * @since 1.7.1
	 * @param string $exclude Comma-separated list of exclusion patterns.
	 * @return string Updated exclusion list.
	 */
	public function autoptimize_js_excludes( $exclude ) {
		foreach ( $this->get_files() as $file ) {
			$exclude .= ', ' . $file;
		}
		return $exclude;
	}

	/**
	 * LiteSpeed Cache: exclude tracking scripts from JS combine/defer.
	 *
	 * Format: array of plain filename strings.
	 *
	 * @since 1.7.1
	 * @param array $excludes Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function litespeed_js_excludes( $excludes ) {
		if ( ! is_array( $excludes ) ) {
			$excludes = array();
		}
		foreach ( $this->get_files() as $file ) {
			$excludes[] = $file;
		}
		return $excludes;
	}

	/**
	 * LiteSpeed Cache: exclude tracking scripts AND inline config blocks from
	 * JS defer.
	 *
	 * Format: array of plain strings. `litespeed_optm_js_defer_exc` is matched
	 * against both external script URLs and inline script content (mirrors
	 * WP Rocket's rocket_delay_js_exclusions), so this feeds BOTH the tracker
	 * filenames AND the inline-config variable names.
	 *
	 * @since 1.7.1
	 * @param array $excludes Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function litespeed_defer_exclusions( $excludes ) {
		if ( ! is_array( $excludes ) ) {
			$excludes = array();
		}
		foreach ( $this->get_files() as $file ) {
			$excludes[] = $file;
		}
		foreach ( $this->get_inline_keys() as $key ) {
			$excludes[] = $key;
		}
		return $excludes;
	}

	/**
	 * WP Rocket: exclude tracking scripts from JS minify/combine/defer.
	 *
	 * Format: array of BARE regex fragments (no self-added delimiters).
	 *
	 * WP Rocket does NOT treat `rocket_exclude_js` / `rocket_exclude_defer_js`
	 * entries as self-delimited patterns — it joins them with `|` and wraps the
	 * whole thing in its own `#( ... )#` delimiters before `preg_match()`:
	 *  - AbstractJSOptimization::should_minify() (minify + combine, both extend
	 *    this class): `preg_match( '#(' . $this->excluded_files . ')#', $file_path )`
	 *  - DeferJS::defer_js(): `preg_match( '#(' . $exclude_defer_js . ')#i', $tag['url'] )`
	 * A previously self-delimited entry like `/opti-behavior(.*)file\.js/` turns
	 * its leading/trailing `/` into LITERAL characters the URL must contain, so
	 * it never matches a real enqueued URL (which ends in `.js?ver=...`, not a
	 * literal `/`). Emitting bare fragments here lets WP Rocket's own delimiters
	 * do their job. `(.*)` already matches through `opti-behavior-pro/` as well
	 * as `opti-behavior/` (substring of the same wildcard), so both Free and Pro
	 * plugin paths are covered without a separate pattern.
	 *
	 * @since 1.7.1
	 * @since 1.7.1 Fixed self-delimited regex that never matched (see above).
	 * @param array $excludes Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function rocket_js_excludes( $excludes ) {
		if ( ! is_array( $excludes ) ) {
			$excludes = array();
		}
		foreach ( $this->get_files() as $file ) {
			$excludes[] = 'opti-behavior(.*)' . preg_quote( $file, '#' );
		}
		return $excludes;
	}

	/**
	 * WP Rocket: exclude tracking scripts from delay-JS execution.
	 *
	 * Format: array of plain strings. `rocket_delay_js_exclusions` is matched
	 * against both external script URLs and inline script content, so this feeds
	 * BOTH the tracker filenames AND the inline-config variable names — ensuring
	 * inline localize/config blocks are never delayed (Phase B).
	 *
	 * @since 1.7.1
	 * @param array $exclusions Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function rocket_delay_exclusions( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			$exclusions = array();
		}
		foreach ( $this->get_files() as $file ) {
			$exclusions[] = $file;
		}
		foreach ( $this->get_inline_keys() as $key ) {
			$exclusions[] = $key;
		}
		return $exclusions;
	}

	/**
	 * Perfmatters: exclude tracking scripts from delay-JS execution.
	 *
	 * Format: array of plain filename strings (Phase B).
	 *
	 * @since 1.7.1
	 * @param array $exclusions Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function perfmatters_delay_exclusions( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			$exclusions = array();
		}
		foreach ( $this->get_files() as $file ) {
			$exclusions[] = $file;
		}
		return $exclusions;
	}

	/**
	 * Perfmatters: exclude tracking scripts from defer-JS.
	 *
	 * Format: array of plain filename strings (Phase B).
	 *
	 * @since 1.7.1
	 * @param array $exclusions Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function perfmatters_defer_exclusions( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			$exclusions = array();
		}
		foreach ( $this->get_files() as $file ) {
			$exclusions[] = $file;
		}
		return $exclusions;
	}

	/**
	 * Perfmatters: exclude tracking scripts from JS minify.
	 *
	 * Format: array of plain filename strings (Phase B).
	 *
	 * @since 1.7.1
	 * @param array $exclusions Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function perfmatters_minify_exclusions( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			$exclusions = array();
		}
		foreach ( $this->get_files() as $file ) {
			$exclusions[] = $file;
		}
		return $exclusions;
	}

	/**
	 * WP Rocket: exclude inline configs from deferred inline JS.
	 *
	 * Format: array of inline-signature strings. Fed inline_keys so localize/
	 * config blocks (`optiBehavior*`, `opti_behavior`) are never deferred (Phase B).
	 *
	 * @since 1.7.1
	 * @param array $exclusions Current inline-signature exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function rocket_defer_inline_exclusions( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			$exclusions = array();
		}
		foreach ( $this->get_inline_keys() as $key ) {
			$exclusions[] = $key;
		}
		return $exclusions;
	}

	/**
	 * WP Rocket: exclude inline configs from JS Combine's inline-content
	 * bundling.
	 *
	 * Format: array of inline-signature strings ← inline_keys. Sibling of
	 * rocket_defer_inline_exclusions() above, but for the separate Combine JS
	 * optimization instead of Defer JS.
	 *
	 * @since 1.7.1
	 * @param array $exclusions Current inline-signature exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function rocket_combine_inline_content_excludes( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			$exclusions = array();
		}
		foreach ( $this->get_inline_keys() as $key ) {
			$exclusions[] = $key;
		}
		return $exclusions;
	}

	/**
	 * SG Optimizer: exclude inline configs from JS combine.
	 *
	 * Format: array of inline-signature strings ← inline_keys (Phase B).
	 *
	 * @since 1.7.1
	 * @param array $exclusions Current inline-signature exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function sgo_inline_content_excludes( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			$exclusions = array();
		}
		foreach ( $this->get_inline_keys() as $key ) {
			$exclusions[] = $key;
		}
		return $exclusions;
	}

	/**
	 * WP-Optimize: exclude tracking scripts from JS minify.
	 *
	 * Format: array of URL/path substrings, matched via case-insensitive
	 * `stripos()` against the full script src (Wp_Optimize_Minify_Functions::
	 * get_default_ignore() / in_arrayi()) — e.g. '/genericons.css',
	 * 'elementor-admin-bar'. Enqueue handles are NOT matched (they don't appear
	 * in the script URL), so this feeds filenames only.
	 *
	 * @since 1.7.1
	 * @since 1.7.1 Removed handles (never matched; URL/path substrings only).
	 * @param array $exclusions Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function wp_optimize_minify_exclusions( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			$exclusions = array();
		}
		foreach ( $this->get_files() as $file ) {
			$exclusions[] = $file;
		}
		return $exclusions;
	}

	/**
	 * Jetpack Boost: skip JS concatenation for protected handles.
	 *
	 * Format: bool filter, args ( $do_concat, $handle ). Returns false when the
	 * handle is a protected tracker, else passes $do_concat through (Phase B).
	 *
	 * @since 1.7.1
	 * @param bool   $do_concat Whether Jetpack Boost should concatenate the script.
	 * @param string $handle    Script handle under consideration.
	 * @return bool Filtered decision.
	 */
	public function jetpack_boost_do_concat( $do_concat, $handle = '' ) {
		if ( is_string( $handle ) && in_array( $handle, $this->get_handles(), true ) ) {
			return false;
		}
		return $do_concat;
	}

	/**
	 * W3 Total Cache: skip per-tag JS minification for protected scripts.
	 *
	 * Format: bool filter, args ( $do, $script_tag, $w3tc_file ). Returns false
	 * when the tag or file contains a protected registry filename, else passes
	 * $do through (Phase B).
	 *
	 * @since 1.7.1
	 * @param bool   $do         Whether W3TC should minify this tag.
	 * @param string $script_tag Full script tag markup.
	 * @param string $w3tc_file  Resolved script file path/URL.
	 * @return bool Filtered decision.
	 */
	public function w3tc_do_tag_minification( $do, $script_tag = '', $w3tc_file = '' ) {
		$haystack = ( is_string( $script_tag ) ? $script_tag : '' ) . ' ' . ( is_string( $w3tc_file ) ? $w3tc_file : '' );
		foreach ( $this->get_files() as $file ) {
			if ( false !== strpos( $haystack, $file ) ) {
				return false;
			}
		}
		return $do;
	}

	/**
	 * Breeze: exclude tracking scripts from JS aggregation/move.
	 *
	 * Format: array of URL substrings, fed plain filenames (Phase B).
	 *
	 * @since 1.7.1
	 * @param array $exclusions Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function breeze_js_dontmove( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			$exclusions = array();
		}
		foreach ( $this->get_files() as $file ) {
			$exclusions[] = $file;
		}
		return $exclusions;
	}

	/**
	 * SG Optimizer: exclude tracking scripts from JS combine/minify/async.
	 *
	 * Format: array of enqueue HANDLES. SiteGround Optimizer's own docs
	 * (siteground.com/tutorials/wordpress/speed-optimizer/custom-filters/) show
	 * `$exclude_list[] = 'script-handle'` for `sgo_javascript_combine_exclude`,
	 * `sgo_js_minify_exclude` and `sgo_js_async_exclude` alike — filenames never
	 * match. Corrected from a pre-existing filename-based bug (the filter never
	 * excluded anything since the plugin compares against enqueue handles).
	 *
	 * @since 1.7.1
	 * @since 1.7.1 Fixed to feed handles instead of filenames (never matched).
	 * @param array $excludes Current exclusion list.
	 * @return array Updated exclusion list.
	 */
	public function sgo_js_excludes( $excludes ) {
		if ( ! is_array( $excludes ) ) {
			$excludes = array();
		}
		foreach ( $this->get_handles() as $handle ) {
			$excludes[] = $handle;
		}
		return $excludes;
	}

	/**
	 * Unified script-tag protection.
	 *
	 * Adds the full protective attribute set to every registered tracking-script
	 * tag, each only when not already present:
	 *  - nowprocket                  → WP Rocket combine/defer/delay exclusion.
	 *  - data-cfasync="false"        → Cloudflare Rocket Loader exclusion.
	 *  - data-noptimize="1"          → Autoptimize / Breeze (no-hyphen spelling).
	 *  - data-no-optimize="1"        → LiteSpeed / general optimizer.
	 *  - data-no-defer="1"           → generic defer-prevention hint.
	 *  - data-jetpack-boost="ignore" → Jetpack Boost render-blocking exclusion.
	 *
	 * @since 1.7.1
	 * @since 1.7.1 Added data-noptimize (Autoptimize/Breeze) and
	 *              data-jetpack-boost="ignore" (Jetpack Boost) to the set.
	 * @param string $tag    Full script HTML tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL (unused).
	 * @return string Modified script tag.
	 */
	public function protect_script_tags( $tag, $handle, $src ) {
		if ( ! in_array( $handle, $this->get_handles(), true ) ) {
			return $tag;
		}

		$attrs = '';
		if ( strpos( $tag, 'nowprocket' ) === false ) {
			$attrs .= ' nowprocket';
		}
		if ( strpos( $tag, 'data-cfasync' ) === false ) {
			$attrs .= ' data-cfasync="false"';
		}
		if ( strpos( $tag, 'data-noptimize' ) === false ) {
			$attrs .= ' data-noptimize="1"';
		}
		if ( strpos( $tag, 'data-no-optimize' ) === false ) {
			$attrs .= ' data-no-optimize="1"';
		}
		if ( strpos( $tag, 'data-no-defer' ) === false ) {
			$attrs .= ' data-no-defer="1"';
		}
		if ( strpos( $tag, 'data-jetpack-boost' ) === false ) {
			$attrs .= ' data-jetpack-boost="ignore"';
		}

		if ( '' !== $attrs ) {
			$tag = str_replace( '<script ', '<script' . $attrs . ' ', $tag );
		}

		return $tag;
	}

	/**
	 * WP Rocket: register the A/B cache-vary cookie as a dynamic cookie (R1).
	 *
	 * Verified filter (docs.wp-rocket.me/article/cache-dynamic-cookies/).
	 * WP Rocket varies its page cache by the VALUE of every listed cookie
	 * name, so a page cached while opti_ab_v is set is never served to a
	 * visitor without it (or vice versa).
	 *
	 * @since 1.7.1
	 * @param array $cookies Current dynamic-cookie list.
	 * @return array Updated list.
	 */
	public function rocket_dynamic_cookies( $cookies ) {
		if ( ! is_array( $cookies ) ) {
			$cookies = array();
		}
		$cookies[] = self::AB_VARIANT_COOKIE;
		return $cookies;
	}

	/**
	 * LiteSpeed Cache: register the A/B cache-vary cookie. Verified filter
	 * (docs.litespeedtech.com — "Vary Cookies" setting is filterable via
	 * `litespeed_vary_cookies`).
	 *
	 * @since 1.7.1
	 * @param array $cookies Current vary-cookie list.
	 * @return array Updated list.
	 */
	public function litespeed_vary_cookies_list( $cookies ) {
		if ( ! is_array( $cookies ) ) {
			$cookies = array();
		}
		$cookies[] = self::AB_VARIANT_COOKIE;
		return $cookies;
	}

	/**
	 * Bypass page-optimizer/cache plugins for heatmap-preview and A/B
	 * visual-editor iframe requests (R6 render fidelity).
	 *
	 * Preview requests can render guest/mobile-simulated or unpublished
	 * variant markup; without this bypass a caching plugin could persist that
	 * preview-only render and serve it to real visitors. Defines the two
	 * de-facto standard bypass constants recognized by WP Rocket
	 * (DONOTROCKETOPTIMIZE) and most cache plugins including WP Rocket, W3TC,
	 * WP Super Cache, LiteSpeed and Breeze (DONOTCACHEPAGE).
	 *
	 * @since 1.7.1
	 * @return void
	 */
	public function maybe_bypass_for_preview() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only preview-mode detection, no state change.
		$is_preview = ! empty( $_GET['opti_heatmap_preview'] )
			|| ! empty( $_GET['opti_preview_as_guest'] )
			|| ! empty( $_GET['opti_preview_as_mobile'] )
			|| ! empty( $_GET['opti_ab_visual_editor'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $is_preview ) {
			return;
		}

		if ( ! defined( 'DONOTROCKETOPTIMIZE' ) ) {
			define( 'DONOTROCKETOPTIMIZE', true );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}
}
