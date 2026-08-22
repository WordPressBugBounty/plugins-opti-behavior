<?php
/**
 * A/B Test Visitor Bucketing & Cookie System
 *
 * Handles deterministic visitor-to-variant assignment, cookie management,
 * and traffic weight distribution.
 *
 * @package opti-behavior
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opti_Behavior_AB_Test_Bucketer {

	/**
	 * Cookie prefix for A/B test assignments.
	 *
	 * @var string
	 */
	const COOKIE_PREFIX = 'opti_ab_';

	/**
	 * Cookie duration in days.
	 *
	 * @var int
	 */
	const COOKIE_DAYS = 365;

	/**
	 * Singleton instance.
	 *
	 * @var Opti_Behavior_AB_Test_Bucketer|null
	 */
	private static $instance = null;

	/**
	 * In-memory cache of assignments for the current request.
	 *
	 * @var array  { test_id => variant_id }
	 */
	private $assignments = array();

	/**
	 * Get singleton instance.
	 *
	 * @return Opti_Behavior_AB_Test_Bucketer
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the assigned variant for a visitor on a specific test.
	 *
	 * 1. Check in-memory cache (current request).
	 * 2. Check cookie for existing assignment.
	 * 3. If no assignment, deterministically assign based on visitor_id + test config.
	 * 4. Persist assignment in cookie and in DB.
	 *
	 * @since 1.3.0
	 * @param int    $test_id    Test ID.
	 * @param string $visitor_id Opti-Behavior visitor hash.
	 * @param array  $variants   Array of variant objects (each must have id, traffic_weight).
	 * @param string $session_id Optional session ID.
	 * @return int|false Variant ID or false if test has no variants.
	 */
	public function get_variant_for_visitor( $test_id, $visitor_id, $variants, $session_id = '' ) {
		$test_id = absint( $test_id );

		if ( empty( $variants ) ) {
			return false;
		}

		// 1. Check in-memory cache.
		if ( isset( $this->assignments[ $test_id ] ) ) {
			return $this->assignments[ $test_id ];
		}

		// 2. Check cookie.
		$cookie_variant = $this->get_cookie_assignment( $test_id );
		if ( false !== $cookie_variant ) {
			// Validate that this variant still exists in the test.
			foreach ( $variants as $variant ) {
				if ( (int) $variant->id === $cookie_variant ) {
					$this->assignments[ $test_id ] = $cookie_variant;
					return $cookie_variant;
				}
			}
			// Cookie references a deleted variant — reassign below.
		}

		// 3. Check database for existing assignment.
		$db_variant = $this->get_db_assignment( $test_id, $visitor_id );
		if ( false !== $db_variant ) {
			foreach ( $variants as $variant ) {
				if ( (int) $variant->id === $db_variant ) {
					$this->set_cookie_assignment( $test_id, $db_variant );
					$this->assignments[ $test_id ] = $db_variant;
					return $db_variant;
				}
			}
		}

		// 4. Deterministic assignment.
		$assigned_variant_id = $this->assign_variant( $test_id, $visitor_id, $variants );

		// Persist.
		$this->set_cookie_assignment( $test_id, $assigned_variant_id );
		$this->save_db_assignment( $test_id, $assigned_variant_id, $visitor_id, $session_id );
		$this->assignments[ $test_id ] = $assigned_variant_id;

		// Allow filtering.
		$assigned_variant_id = apply_filters( 'opti_behavior_ab_variant_assignment', $assigned_variant_id, $test_id, $visitor_id );

		return $assigned_variant_id;
	}

	/**
	 * Deterministically assign a visitor to a variant based on traffic weights.
	 *
	 * Uses a hash of visitor_id + test_id for deterministic, repeatable assignment.
	 *
	 * @since 1.3.0
	 * @param int    $test_id    Test ID.
	 * @param string $visitor_id Visitor hash.
	 * @param array  $variants   Array of variant objects.
	 * @return int Assigned variant ID.
	 */
	private function assign_variant( $test_id, $visitor_id, $variants ) {
		// Generate a deterministic hash (0.0–1.0).
		$salt = get_option( 'opti_behavior_master_secret', 'opti-behavior-salt' );
		$hash = md5( $visitor_id . '|' . $test_id . '|' . $salt );

		// Convert first 8 hex chars to a float between 0 and 1.
		$bucket = hexdec( substr( $hash, 0, 8 ) ) / 0xFFFFFFFF;

		// Build cumulative weight distribution.
		$total_weight = 0;
		foreach ( $variants as $variant ) {
			$total_weight += max( 1, (int) $variant->traffic_weight );
		}

		$cumulative = 0;
		foreach ( $variants as $variant ) {
			$weight     = max( 1, (int) $variant->traffic_weight );
			$cumulative += $weight / $total_weight;

			if ( $bucket <= $cumulative ) {
				return (int) $variant->id;
			}
		}

		// Fallback: return last variant (should not happen).
		$last = end( $variants );
		return (int) $last->id;
	}

	/**
	 * Get variant assignment from cookie.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return int|false Variant ID or false if no cookie.
	 */
	private function get_cookie_assignment( $test_id ) {
		$cookie_name = self::COOKIE_PREFIX . $test_id;

		if ( isset( $_COOKIE[ $cookie_name ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = absint( $_COOKIE[ $cookie_name ] );
			if ( $value > 0 ) {
				return $value;
			}
		}

		return false;
	}

	/**
	 * Set variant assignment cookie (server-side, first-party).
	 *
	 * @since 1.3.0
	 * @param int $test_id    Test ID.
	 * @param int $variant_id Variant ID.
	 */
	private function set_cookie_assignment( $test_id, $variant_id ) {
		// Anonymous Mode is cookieless: no assignment cookie may be written
		// (GDPR/PECR — the "no cookies, no client-side storage" guarantee).
		// Variant stickiness is preserved without the cookie because
		// assign_variant() is deterministic: hash( visitor_id + test_id )
		// always yields the same bucket for the same daily visitor hash, and
		// get_db_assignment() resolves prior impressions for the same hash.
		if ( ! $this->is_full_privacy_mode() ) {
			return;
		}

		// Check consent before setting cookie.
		if ( ! $this->has_consent() ) {
			return;
		}

		$cookie_name = self::COOKIE_PREFIX . $test_id;
		$expiry      = time() + ( self::COOKIE_DAYS * DAY_IN_SECONDS );
		$secure      = is_ssl();
		$path        = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
		$domain      = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

		// Set cookie — must be called before any output.
		if ( ! headers_sent() ) {
			setcookie( $cookie_name, (string) $variant_id, $expiry, $path, $domain, $secure, false );
			// Also set in $_COOKIE for the current request.
			$_COOKIE[ $cookie_name ] = (string) $variant_id;

			// Also set a static, cache-plugin-visible "vary" cookie (R1). The
			// per-test cookie above is dynamically named ('opti_ab_<test_id>'),
			// so cache plugins (WP Rocket, LiteSpeed) can't be pre-configured
			// to vary on it. This companion cookie has a FIXED name so
			// Opti_Behavior_Optimizer_Compat can register it once with those
			// plugins' vary-cookie filters, ensuring a page cached for one
			// visitor's variant is never served to a visitor in another.
			$vary_cookie = class_exists( 'Opti_Behavior_Optimizer_Compat' )
				? Opti_Behavior_Optimizer_Compat::AB_VARIANT_COOKIE
				: 'opti_ab_v';
			setcookie( $vary_cookie, '1', $expiry, $path, $domain, $secure, false );
			$_COOKIE[ $vary_cookie ] = '1';
		}
	}

	/**
	 * Get existing assignment from database.
	 *
	 * @since 1.3.0
	 * @param int    $test_id    Test ID.
	 * @param string $visitor_id Visitor hash.
	 * @return int|false Variant ID or false.
	 */
	private function get_db_assignment( $test_id, $visitor_id ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'optibehavior_ab_impressions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$variant_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT variant_id FROM {$table} WHERE test_id = %d AND visitor_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $test_id ),
				sanitize_text_field( $visitor_id )
			)
		);

		return $variant_id ? (int) $variant_id : false;
	}

	/**
	 * Save variant assignment to database via impression record.
	 *
	 * @since 1.3.0
	 * @param int    $test_id    Test ID.
	 * @param int    $variant_id Variant ID.
	 * @param string $visitor_id Visitor hash.
	 * @param string $session_id Session ID.
	 */
	private function save_db_assignment( $test_id, $variant_id, $visitor_id, $session_id = '' ) {
		// Intentional no-op — do NOT insert an impression row from the
		// server-side bucketer path.
		//
		// Rationale: the bucketer runs during page render. In full mode the
		// JS-facing optibehavior_vid cookie may not be populated yet, so the
		// visitor_id the bucketer sees can differ from the id ab-test-tracker.js
		// later sends via the AJAX batch. (In cookieless Anonymous Mode both
		// sides use the same server daily hash, so they already agree.) Two
		// different visitor_ids ⇒ two
		// impression rows for ONE physical visitor, inflating the variant
		// card "Visitors" counter beyond the real audience and making it
		// inconsistent with the Heatmap Impact SESSIONS and Session
		// Recordings modal counters.
		//
		// ab-test-tracker.js already inserts the impression on its first
		// batch flush (visibilitychange / beforeunload / pagehide) using
		// the canonical JS-side visitor_id and session_id. Leaving this
		// method as a no-op ensures exactly ONE impression row per
		// physical visitor per test, keeping all three counters
		// consistent.
		//
		// The variant assignment itself is still persisted via the
		// opti_ab_{test_id} cookie (365 days) set by
		// set_cookie_assignment(), so a returning visitor stays on the
		// same variant even before the tracker batch inserts any DB row.
		unset( $test_id, $variant_id, $visitor_id, $session_id );
	}

	/**
	 * Check if visitor has given consent for tracking.
	 *
	 * Integrates with existing Opti_Behavior_Consent_Detector.
	 * A/B cookies are classified as "functional" (necessary for page functionality),
	 * so they may be allowed even without full tracking consent. However, we still
	 * respect the global consent setting.
	 *
	 * @since 1.3.0
	 * @return bool True if consent is given or not required.
	 */
	/**
	 * Whether the plugin is configured for 'full' privacy mode.
	 *
	 * Anonymous Mode (the default) is cookieless: the bucketer must never
	 * write the opti_ab_<test_id> / vary cookies in that mode. Falls back to
	 * 'anonymous' when the option is unset (GDPR-safe default, mirrors
	 * Opti_Behavior_Heatmap_Session::get_privacy_mode()).
	 *
	 * @since 1.3.2
	 * @return bool True when privacy_mode is 'full'.
	 */
	private function is_full_privacy_mode() {
		// The option is persisted via maybe_serialize() (see
		// Opti_Behavior_Heatmap_Options::save()), so unserialize defensively.
		$options = maybe_unserialize( get_option( 'opti_behavior_heatmap_option', array() ) );
		$mode    = is_array( $options ) && isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous';
		return 'full' === $mode;
	}

	private function has_consent() {
		// If consent detection is disabled or not loaded, assume consent.
		if ( ! class_exists( 'Opti_Behavior_Consent_Detector' ) ) {
			return true;
		}

		// Check the global Opti-Behavior consent setting.
		$consent_mode = get_option( 'opti_behavior_heatmap_option', array() );
		if ( is_array( $consent_mode ) && isset( $consent_mode['require_consent'] ) && ! $consent_mode['require_consent'] ) {
			return true;
		}

		// Use the consent detector.
		// A/B test cookies are functional — allow if no consent plugin detected.
		$detector = new Opti_Behavior_Consent_Detector();
		if ( ! $detector->has_consent_plugin() ) {
			return true;
		}

		// A consent plugin is active — cookies will be managed by JS-side consent logic.
		// Default to true for A/B tests (functional cookies), but allow filtering.
		return apply_filters( 'opti_behavior_ab_has_consent', true );
	}

	/**
	 * Remove assignment for a specific test (used when test is reset/deleted).
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 */
	/**
	 * Read-only lookup of a previously RECORDED assignment (no side effects).
	 *
	 * Unlike get_variant_for_visitor(), this never buckets, never writes a
	 * cookie and never creates a row: it only reports the variant a visitor
	 * was already exposed to (impression row). Used by the renderer's
	 * goal-only pass in cookieless Anonymous Mode, where the opti_ab_<id>
	 * assignment cookie does not exist: a first-time visitor landing directly
	 * on a goal URL yields false, so no spurious conversion is recorded.
	 *
	 * @since 1.3.2
	 * @param int    $test_id    Test ID.
	 * @param string $visitor_id Visitor hash.
	 * @return int|false Variant ID or false when the visitor has no impression.
	 */
	public function get_recorded_assignment( $test_id, $visitor_id ) {
		if ( empty( $visitor_id ) ) {
			return false;
		}
		return $this->get_db_assignment( (int) $test_id, $visitor_id );
	}

	public function clear_assignment( $test_id ) {
		unset( $this->assignments[ $test_id ] );

		// Expire the cookie.
		$cookie_name = self::COOKIE_PREFIX . $test_id;
		$path        = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
		$domain      = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

		if ( ! headers_sent() ) {
			setcookie( $cookie_name, '', time() - 3600, $path, $domain, is_ssl(), false );
		}
		unset( $_COOKIE[ $cookie_name ] );
	}

	/**
	 * Get all current assignments for this visitor (useful for session tagging).
	 *
	 * @since 1.3.0
	 * @return array { test_id => variant_id }
	 */
	public function get_all_assignments() {
		return $this->assignments;
	}

	/**
	 * Update traffic weights for a test (used by Multi-Armed Bandit).
	 *
	 * @since 1.3.0
	 * @param int   $test_id Test ID.
	 * @param array $weights { variant_id => new_weight }
	 * @return bool True on success.
	 */
	public function update_traffic_weights( $test_id, $weights ) {
		if ( ! class_exists( 'Opti_Behavior_AB_Test_Database' ) ) {
			return false;
		}

		$weights = apply_filters( 'opti_behavior_ab_traffic_weights', $weights, $test_id );

		foreach ( $weights as $variant_id => $weight ) {
			Opti_Behavior_AB_Test_Database::update_variant(
				$variant_id,
				array( 'traffic_weight' => min( 100, max( 0, absint( $weight ) ) ) )
			);
		}

		// Invalidate cache so new weights take effect.
		Opti_Behavior_AB_Test_Database::invalidate_active_tests_cache();

		return true;
	}
}
