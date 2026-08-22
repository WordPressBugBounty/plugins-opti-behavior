<?php
/**
 * Consent Detector Class
 *
 * Detects which (if any) supported cookie consent plugin is active on the site.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consent Detector Class
 *
 * Detects active cookie consent plugins via PHP class/function/constant checks.
 * Detection is performed purely via reflection — no WordPress options queries.
 *
 * Supported plugins:
 * - CookieBot
 * - Complianz
 * - CookieYes / Cookie Law Info
 * - GDPR Cookie Compliance (Moove)
 * - Cookie Notice by TFC
 * - Real Cookie Banner
 * - Borlabs Cookie
 * - WP DSGVO Tools
 *
 * @since 1.0.3
 */
class Opti_Behavior_Consent_Detector {

	/**
	 * Slug of the detected consent plugin, or null if none found.
	 *
	 * @since 1.0.3
	 * @var string|null
	 */
	private $detected_plugin = null;

	/**
	 * Human-readable label of the detected consent plugin, or empty string.
	 *
	 * @since 1.0.3
	 * @var string
	 */
	private $detected_plugin_label = '';

	/**
	 * Map of plugin slug → human-readable label.
	 *
	 * @since 1.0.3
	 * @var array<string, string>
	 */
	private static $labels = array(
		'cookiebot'          => 'CookieBot',
		'complianz'          => 'Complianz',
		'cookieyes'          => 'CookieYes / Cookie Law Info',
		'moove'              => 'GDPR Cookie Compliance (Moove)',
		'cookie-notice'      => 'Cookie Notice by TFC',
		'real-cookie-banner' => 'Real Cookie Banner',
		'borlabs'            => 'Borlabs Cookie',
		'wp-dsgvo'           => 'WP DSGVO Tools',
	);

	/**
	 * Constructor. Runs detection once and caches the result.
	 *
	 * @since 1.0.3
	 */
	public function __construct() {
		$this->detect();
	}

	/**
	 * Run detection in priority order and cache the first match.
	 *
	 * Detection order is chosen so that the most widely deployed plugins are
	 * checked first, minimising the number of checks on typical installs.
	 *
	 * @since 1.0.3
	 * @return void
	 */
	private function detect() {
		$checks = array(
			'cookiebot'          => array( $this, 'is_cookiebot_active' ),
			'complianz'          => array( $this, 'is_complianz_active' ),
			'cookieyes'          => array( $this, 'is_cookieyes_active' ),
			'moove'              => array( $this, 'is_moove_active' ),
			'cookie-notice'      => array( $this, 'is_cookie_notice_active' ),
			'real-cookie-banner' => array( $this, 'is_real_cookie_banner_active' ),
			'borlabs'            => array( $this, 'is_borlabs_active' ),
			'wp-dsgvo'           => array( $this, 'is_wp_dsgvo_active' ),
		);

		foreach ( $checks as $slug => $callback ) {
			if ( call_user_func( $callback ) ) {
				$this->detected_plugin       = $slug;
				$this->detected_plugin_label = self::$labels[ $slug ];
				return;
			}
		}
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Return the slug of the detected consent plugin, or null if none found.
	 *
	 * Possible return values:
	 * - null
	 * - 'cookiebot'
	 * - 'complianz'
	 * - 'cookieyes'
	 * - 'moove'
	 * - 'cookie-notice'
	 * - 'real-cookie-banner'
	 * - 'borlabs'
	 * - 'wp-dsgvo'
	 *
	 * @since 1.0.3
	 * @return string|null Plugin slug or null.
	 */
	public function get_detected_plugin() {
		return $this->detected_plugin;
	}

	/**
	 * Return the human-readable name of the detected consent plugin.
	 *
	 * Returns an empty string when no consent plugin is detected.
	 *
	 * @since 1.0.3
	 * @return string Human-readable plugin name, or empty string.
	 */
	public function get_detected_plugin_label() {
		return $this->detected_plugin_label;
	}

	/**
	 * Return true when at least one supported consent plugin is active.
	 *
	 * @since 1.0.3
	 * @return bool
	 */
	public function has_consent_plugin() {
		return null !== $this->detected_plugin;
	}

	// -------------------------------------------------------------------------
	// Per-plugin detection helpers
	// -------------------------------------------------------------------------

	/**
	 * Detect CookieBot (Usercentrics Cookiebot).
	 *
	 * CookieBot versions differ:
	 *  - Legacy: class `CYBOT_A_Cookiebot` (no longer present in current releases)
	 *  - v4+: class `\Cookiebot_WP` (deprecated global) or `\cybot\cookiebot\lib\Cookiebot_WP` (current namespaced)
	 *  - Stable marker: constant `CYBOT_COOKIEBOT_PLUGIN_URL` — defined unconditionally by the main plugin file at load time.
	 *
	 * The constant check is the most reliable across all versions.
	 *
	 * @since 1.0.3
	 * @return bool
	 */
	private function is_cookiebot_active() {
		return defined( 'CYBOT_COOKIEBOT_PLUGIN_URL' )
			|| class_exists( 'Cookiebot_WP' )
			|| class_exists( '\\cybot\\cookiebot\\lib\\Cookiebot_WP' )
			|| class_exists( 'CYBOT_A_Cookiebot' );
	}

	/**
	 * Detect Complianz GDPR/CCPA Cookie Consent.
	 *
	 * Reliable signatures across versions:
	 *  - constant `cmplz_free` (free edition marker, defined at main-file load)
	 *  - constant `cmplz_premium` (premium marker, defined at main-file load)
	 *  - class `COMPLIANZ` (main plugin class, present in 7.x)
	 *
	 * Legacy signature `cmplz_get_cookie_category()` is kept as a fallback.
	 *
	 * @since 1.0.3
	 * @return bool
	 */
	private function is_complianz_active() {
		return defined( 'cmplz_free' )
			|| defined( 'cmplz_premium' )
			|| class_exists( 'COMPLIANZ' )
			|| function_exists( 'cmplz_get_cookie_category' );
	}

	/**
	 * Detect CookieYes / Cookie Law Info.
	 *
	 * Reliable signatures across versions:
	 *  - constant `CLI_VERSION` (current, 3.x+)
	 *  - constant `CLI_PLUGIN_BASENAME` / `CLI_SETTINGS_FIELD` (defined at main-file load)
	 *  - class `Cookie_Law_Info` (legacy)
	 *  - namespaced `CookieYes\Lite\App` (some intermediate versions)
	 *  - legacy constant `CLI_PLUGIN_NAME` (pre-3.x)
	 *
	 * @since 1.0.3
	 * @return bool
	 */
	private function is_cookieyes_active() {
		return defined( 'CLI_VERSION' )
			|| defined( 'CLI_PLUGIN_BASENAME' )
			|| defined( 'CLI_SETTINGS_FIELD' )
			|| defined( 'CLI_PLUGIN_NAME' )
			|| class_exists( 'Cookie_Law_Info' )
			|| class_exists( 'CookieYes\Lite\App' );
	}

	/**
	 * Detect GDPR Cookie Compliance by Moove Agency.
	 *
	 * Reliable signatures across versions:
	 *  - constant `MOOVE_GDPR_VERSION` (defined at main-file load, current 5.x+)
	 *  - class `Moove_GDPR_Cookie_Compliance` (legacy)
	 *
	 * @since 1.0.3
	 * @return bool
	 */
	private function is_moove_active() {
		return defined( 'MOOVE_GDPR_VERSION' )
			|| class_exists( 'Moove_GDPR_Cookie_Compliance' );
	}

	/**
	 * Detect Cookie Notice & Compliance for GDPR/CCPA (TFC / hu-manity.co).
	 *
	 * @since 1.0.3
	 * @return bool
	 */
	private function is_cookie_notice_active() {
		return class_exists( 'Cookie_Notice' );
	}

	/**
	 * Detect Real Cookie Banner (devowl.io).
	 *
	 * The plugin uses a namespaced core class in all versions.
	 *
	 * @since 1.0.3
	 * @return bool
	 */
	private function is_real_cookie_banner_active() {
		return class_exists( 'DevOwl\\RealCookieBanner\\Core' );
	}

	/**
	 * Detect Borlabs Cookie.
	 *
	 * Borlabs v3+ uses a namespaced class hierarchy. v2 used a global class.
	 * Both are checked to support legacy installs.
	 *
	 * @since 1.0.3
	 * @return bool
	 */
	private function is_borlabs_active() {
		return class_exists( 'BorlabsCookie\\System\\Cookie\\Stat' )
			|| class_exists( 'Borlabs_Cookie' );
	}

	/**
	 * Detect WP DSGVO Tools (formerly Borlabs v1 / wp-dsgvo-tools).
	 *
	 * This plugin registers a standalone global helper function.
	 *
	 * @since 1.0.3
	 * @return bool
	 */
	private function is_wp_dsgvo_active() {
		return function_exists( 'borlabs_cookie_status_helper' );
	}
}
