<?php
/**
 * WordPress locale for the numbers and dates printed by the admin scripts.
 *
 * QA tour 2026-10-03 (OPT-348, pb 7): an English WordPress admin opened in a
 * French browser showed "1 046", "0,3%" and "4 sept." because the scripts
 * called `toLocaleString()` / `Intl.NumberFormat()` without a locale (= the
 * browser language). Every admin script of this plugin now formats through
 * `window.OptiBehaviorLocale` (assets/js/opti-behavior-locale.js), fed here
 * with the locale of the screen (`determine_locale()`: user profile
 * language, else site language) and the WordPress date / time formats.
 *
 * The helper is attached as a dependency of every script this plugin
 * enqueues in the admin (and of the front-end stats bar shown to admins),
 * so no enqueue site has to list it. Visitor trackers are never touched:
 * they report the visitor's own language and time zone.
 *
 * @package Opti-Behavior
 * @since   1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locale helper registration + dependency wiring.
 */
class Opti_Behavior_Admin_Locale {

	/** Script handle of the helper. */
	const HANDLE = 'opti-behavior-locale';

	/** Front-end scripts shown to logged-in admins (never trackers). */
	const FRONT_HANDLES = array( 'opti-behavior-frontend-stats-bar' );

	/**
	 * Hook the wiring (admin + front end).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ), 1 );
		// Late enough for scripts enqueued from page callbacks, before printing.
		add_action( 'admin_print_scripts', array( __CLASS__, 'attach_admin' ), 1 );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'attach_admin' ), 1 );
		add_action( 'wp_print_scripts', array( __CLASS__, 'attach_front' ), 1 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'attach_front' ), 1 );
	}

	/**
	 * BCP 47 tag of a WordPress locale: `fr_FR` → `fr-FR`,
	 * `de_DE_formal` → `de-DE`, `ja` → `ja`.
	 *
	 * @param string $wp_locale WordPress locale.
	 * @return string
	 */
	public static function to_bcp47( $wp_locale ) {
		$parts = array_values( array_filter( explode( '_', str_replace( '-', '_', (string) $wp_locale ) ), 'strlen' ) );
		if ( empty( $parts ) ) {
			return 'en-US';
		}
		return isset( $parts[1] ) ? $parts[0] . '-' . $parts[1] : $parts[0];
	}

	/**
	 * Data printed before the helper.
	 *
	 * @return array{locale:string,wpLocale:string,dateFormat:string,timeFormat:string}
	 */
	public static function data() {
		$wp_locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return array(
			'locale'     => self::to_bcp47( $wp_locale ),
			'wpLocale'   => (string) $wp_locale,
			'dateFormat' => (string) get_option( 'date_format', 'F j, Y' ),
			'timeFormat' => (string) get_option( 'time_format', 'g:i a' ),
		);
	}

	/**
	 * Register the helper handle (enqueued only through dependencies).
	 *
	 * @return void
	 */
	public static function register() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$rel = 'assets/js/opti-behavior-locale.js';
		$abs = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . $rel;
		wp_register_script(
			self::HANDLE,
			OPTI_BEHAVIOR_HEATMAP_PLUGIN_URL . $rel,
			array( 'wp-date' ),
			OPTI_BEHAVIOR_HEATMAP_VERSION . ( file_exists( $abs ) ? '.' . filemtime( $abs ) : '' ),
			false
		);
		wp_add_inline_script( self::HANDLE, 'window.OptiBehaviorLocaleData = ' . wp_json_encode( self::data() ) . ';', 'before' );
	}

	/**
	 * Admin: every queued script served from this plugin's assets/js/.
	 *
	 * @return void
	 */
	public static function attach_admin() {
		self::attach( OPTI_BEHAVIOR_HEATMAP_PLUGIN_URL . 'assets/js/', array() );
	}

	/**
	 * Front end: only the admin stats bar.
	 *
	 * @return void
	 */
	public static function attach_front() {
		self::attach( '', self::FRONT_HANDLES );
	}

	/**
	 * Add the helper to the dependencies of the matching registered scripts
	 * (queued ones and the ones only pulled in as a dependency; a script
	 * that is never printed is unaffected).
	 *
	 * @param string   $src_prefix Script URL prefix to match ('' = none).
	 * @param string[] $handles    Handles to match by name.
	 * @return string[] Handles that received the dependency.
	 */
	public static function attach( $src_prefix, array $handles ) {
		$scripts = wp_scripts();
		if ( ! isset( $scripts->registered[ self::HANDLE ] ) ) {
			self::register();
		}
		$done = array();
		foreach ( $scripts->registered as $handle => $script ) {
			if ( self::HANDLE === $handle || ! is_object( $script ) ) {
				continue;
			}
			$src = is_string( $script->src ) ? $script->src : '';
			$match  = in_array( $handle, $handles, true ) || ( '' !== $src_prefix && 0 === strpos( $src, $src_prefix ) );
			if ( ! $match || in_array( self::HANDLE, (array) $script->deps, true ) ) {
				continue;
			}
			$script->deps[] = self::HANDLE;
			$done[]         = $handle;
		}
		return $done;
	}
}
