<?php
/**
 * Session Class
 *
 * Handles session and visitor tracking.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session Class
 *
 * Provides session and visitor tracking functionality.
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database queries required for analytics plugin functionality. Custom tables used for high-volume event tracking. Caching not appropriate for real-time analytics data.
class Opti_Behavior_Heatmap_Session {

	/**
	 * Core instance.
	 *
	 * @since 1.0.0
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Current session ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $session_id = null;

	/**
	 * Current visitor ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $visitor_id = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		add_action( 'wp_ajax_opti_behavior_refresh_nonces', array( $this, 'ajax_refresh_nonces' ) );
		add_action( 'wp_ajax_nopriv_opti_behavior_refresh_nonces', array( $this, 'ajax_refresh_nonces' ) );
	}

	/**
	 * Get current session ID
	 *
	 * @return string
	 */
	public function get_session_id() {
		if ( null === $this->session_id ) {
			$this->session_id = $this->generate_unique_id();
		}
		return $this->session_id;
	}

	/**
	 * Get current visitor ID
	 *
	 * @return string
	 */
	public function get_visitor_id() {
		if ( null === $this->visitor_id ) {
			$this->visitor_id = $this->get_or_create_visitor_id();
		}
		return $this->visitor_id;
	}

	/**
	 * Get or create visitor ID from cookie or generate new one.
	 *
	 * Behaviour depends on the current privacy mode:
	 * - Logged-in WP user (any mode): returns 'wp_user_<id>' — no cookie needed.
	 * - Guest + anonymous mode:       returns a daily rotating hash — no cookie set.
	 * - Guest + full mode:            existing cookie behaviour (unchanged).
	 *
	 * @return string
	 */
	private function get_or_create_visitor_id() {
		// Logged-in WordPress users are identified by their user ID in all modes.
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return 'wp_user_' . $user_id;
		}

		$privacy_mode = $this->get_privacy_mode();

		// Anonymous mode: derive a daily rotating hash — no cookie ever written.
		if ( 'anonymous' === $privacy_mode ) {
			return $this->get_anonymous_daily_hash();
		}

		// Full mode: use the persistent cookie (existing behaviour).

		// First, check if visitor ID exists in cookie (set by JavaScript).
		if ( isset( $_COOKIE['optibehavior_vid'] ) && ! empty( $_COOKIE['optibehavior_vid'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['optibehavior_vid'] ) );
		}

		// Fallback to old cookie name for backward compatibility.
		if ( isset( $_COOKIE['opti_behavior_visitor_id'] ) && ! empty( $_COOKIE['opti_behavior_visitor_id'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['opti_behavior_visitor_id'] ) );
		}

		// Generate new visitor ID using same format as JavaScript.
		$visitor_id = 'visitor_' . time() . '_' . wp_generate_password( 12, false );

		// Set cookie for 1 year.
		// httponly=false: the session-recorder.js reads this cookie via document.cookie
		// to reuse the same visitor_id instead of generating a competing JS-side ID.
		// Keeping httponly=true would make the cookie invisible to JS, causing a format
		// mismatch between wp_optibehavior_ab_impressions.visitor_id (PHP-set cookie)
		// and wp_optibehavior_sessions.visitor_id (JS-generated ID stored via AJAX).
		setcookie( 'optibehavior_vid', $visitor_id, time() + 31536000, '/', '', false, false );

		return $visitor_id;
	}

	/**
	 * Get the configured privacy mode.
	 *
	 * Falls back to 'anonymous' when the option is unset, ensuring GDPR-safe
	 * behaviour on new installs and upgrades before the admin saves the setting.
	 *
	 * @since 1.0.4
	 * @return string 'anonymous' or 'full'
	 */
	private function get_privacy_mode() {
		$options = $this->core->get_options();
		$mode    = isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous';
		return ( 'full' === $mode ) ? 'full' : 'anonymous';
	}

	/**
	 * Return the anonymous daily hash for the current request.
	 *
	 * Public wrapper around get_anonymous_daily_hash() so that AJAX handlers
	 * in both the free and Pro plugins can force a server-authoritative hash
	 * when the visitor is "effectively anonymous" (anonymous mode, or full
	 * mode with no consent yet granted). Unlike get_visitor_id(), this method
	 * always returns the hash regardless of the configured privacy mode, which
	 * is required when the JS is running in anonymous mode but the PHP option
	 * is set to full.
	 *
	 * @since 1.0.6
	 * @return string Visitor ID in the form 'anon_<sha256-hex>'.
	 */
	public function get_anonymous_hash() {
		return $this->get_anonymous_daily_hash();
	}

	/**
	 * Server-authoritative anonymous identity for the CURRENT request.
	 *
	 * Cache-immune single source of truth for the (visitor_id, session_id) pair
	 * used by every cacheable ingest path in Anonymous Mode. Recomputed per
	 * request from IP + UA + daily salt, so a full-page cache that freezes the
	 * first visitor's anon_vid / anon_sid_seed into the HTML cannot merge two
	 * distinct visitors: admin-ajax is never full-page cached, so this method
	 * always sees the real per-visitor IP/UA at ingest time.
	 *
	 * Shapes mirror the values localized by the frontend (anon_vid / anon_sid_seed)
	 * and the JS broker's computeFallbackSeed() so the client and server converge
	 * on byte-identical ids for the same (visitor, 30-min bucket):
	 *   - visitor_id : logged-in  -> 'wp_user_<id>'
	 *                  guest       -> get_anonymous_hash() ('anon_<sha256…>')
	 *   - session_id : 'sess_<hash40>_<intdiv(time(),1800)>' (current 30-min UTC
	 *                  bucket), where hash40 = substr( anon_daily_hash, 0, 40 );
	 *                  '' when no hash is resolvable.
	 *
	 * No PII, no cookies, no client storage: the IP is used in memory only and
	 * the salt rotates at UTC midnight.
	 *
	 * @since 1.0.8
	 * @return array{visitor_id:string, session_id:string}
	 */
	public function get_anonymous_identity() {
		$anon_hash = $this->get_anonymous_daily_hash();

		$user_id    = get_current_user_id();
		$visitor_id = $user_id > 0 ? 'wp_user_' . $user_id : $anon_hash;

		// Deterministic 30-minute UTC session bucket. MUST match the frontend
		// $anon_sid_seed (class-opti-behavior-heatmap-frontend.php) and the JS
		// broker's computeFallbackSeed(): 'sess_' + hash(0,40) + '_' + bucket.
		$session_id = ( '' !== $anon_hash )
			? 'sess_' . substr( $anon_hash, 0, 40 ) . '_' . intdiv( time(), 1800 )
			: '';

		return array(
			'visitor_id' => $visitor_id,
			'session_id' => $session_id,
		);
	}

	/**
	 * Build an anonymous daily rotating visitor hash.
	 *
	 * The IP address is used only in memory to compute the hash and is never
	 * stored in the database. The daily salt rotates at midnight (UTC) so the
	 * same browser cannot be tracked across calendar days.
	 *
	 * @since 1.0.4
	 * @return string Visitor ID in the form 'anon_<sha256-hex>'.
	 */
	private function get_anonymous_daily_hash() {
		$ip         = $this->get_client_ip();
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$site_url   = get_site_url();
		$daily_salt = $this->get_daily_salt();

		// Truncate hash to 59 chars so the full ID fits within VARCHAR(64): 'anon_' (5) + 59 = 64.
		return 'anon_' . substr( hash( 'sha256', $ip . $user_agent . $site_url . $daily_salt ), 0, 59 );
	}

	/**
	 * Get (or regenerate) the daily salt used for anonymous visitor hashing.
	 *
	 * The salt rotates once per UTC day so anonymous hashes cannot be correlated
	 * across calendar days.
	 *
	 * Implementation note — race-free salt generation:
	 * The previous implementation used wp_generate_password() (random) and then
	 * persisted the result.  If two AJAX requests arrived simultaneously before
	 * any salt was stored (fresh install or midnight rotation), each request
	 * generated a DIFFERENT random salt → identical visitors received different
	 * hashes → inflated unique-visitor counts for a brief window each day.
	 *
	 * Fix: derive the salt deterministically from (date + AUTH_KEY) via SHA-256.
	 * AUTH_KEY is defined once in wp-config.php, is site-specific and secret, and
	 * never changes, so all simultaneous requests compute exactly the same salt
	 * for the same calendar day without any shared-state coordination.
	 *
	 * @since 1.0.4 (race-free since 1.0.6)
	 * @return string 32-character deterministic salt for today.
	 */
	private function get_daily_salt() {
		$today = gmdate( 'Y-m-d' );

		// Check cached value in options table first — avoids recomputing on every call.
		$salt_option = get_option( 'opti_behavior_daily_salt', '' );
		$salt_date   = get_option( 'opti_behavior_daily_salt_date', '' );

		if ( $salt_date === $today && ! empty( $salt_option ) ) {
			return $salt_option;
		}

		// Derive a deterministic, site-secret-seeded salt for today.
		// Using AUTH_KEY (unique per site, defined in wp-config.php) as the HMAC
		// key ensures the salt cannot be guessed from public information (IP, UA, date)
		// while guaranteeing every PHP process on the same host computes the same value.
		$site_secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
		$salt_option = substr( hash( 'sha256', $today . $site_secret . 'opti_behavior_anon_salt_v2' ), 0, 32 );

		// Persist so it can be quickly read by subsequent AJAX calls without recomputing.
		update_option( 'opti_behavior_daily_salt', $salt_option, false );
		update_option( 'opti_behavior_daily_salt_date', $today, false );

		return $salt_option;
	}

	/**
	 * Generate unique ID for sessions
	 *
	 * @return string
	 */
	protected function generate_unique_id() {
		return wp_generate_uuid4();
	}

	/**
	 * AJAX handler: return fresh nonces for all frontend tracking scripts.
	 *
	 * Called by JS trackers when they detect a 403 nonce-expired response.
	 * Works for both logged-in (wp_ajax_*) and guest (wp_ajax_nopriv_*) visitors.
	 * admin-ajax.php is always excluded from full-page caches, so this endpoint
	 * always executes PHP and returns a valid, freshly-generated nonce.
	 *
	 * @since 1.0.0
	 */
	public function ajax_refresh_nonces() {
		wp_send_json_success(
			array(
				'nonce_heatmap'        => wp_create_nonce( 'opti_behavior_heatmap_nonce' ),
				'nonce_recording'      => wp_create_nonce( 'opti_behavior_recording' ),
				'nonce_errors'         => wp_create_nonce( 'opti_behavior_errors' ),
				'nonce_form_analytics' => wp_create_nonce( 'opti_behavior_form_analytics' ),
				'nonce_funnels'        => wp_create_nonce( 'opti_behavior_funnels' ),
				// A/B tracker (R5): both opti_behavior_ab_track_conversion and
				// opti_behavior_ab_track_batch verify the SAME
				// 'opti_behavior_ab_frontend_nonce' action (see
				// trait-opti-behavior-ab-tests-ajax.php ajax_ab_track_conversion()/
				// ajax_ab_track_batch()), so both keys carry the same fresh value.
				'nonce_ab'              => wp_create_nonce( 'opti_behavior_ab_frontend_nonce' ),
				'nonce_ab_batch'        => wp_create_nonce( 'opti_behavior_ab_frontend_nonce' ),
			)
		);
	}

	/**
	 * Track session start
	 *
	 * @param array $data Session data.
	 * @return bool True when a new session row was inserted, false when an existing session was updated.
	 */
	public function track_session_start( $data ) {
		global $wpdb;

		$session_id = $data['session_id'];
		$visitor_id = isset( $data['visitor_id'] ) ? $data['visitor_id'] : '';

		// Check if session already exists
		$existing_session = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
			$session_id
		) );

		if ( $existing_session ) {
			// Update existing session (update end_time to track activity)
			$wpdb->update(
				"{$wpdb->prefix}optibehavior_sessions",
				array(
					'end_time' => current_time( 'mysql' ),
				),
				array( 'id' => $session_id ),
				array( '%s' ),
				array( '%s' )
			);
			// Invalidate dashboard caches so activity shows up on the next load.
			if ( function_exists( 'opti_behavior_invalidate_dashboard_caches' ) ) {
				opti_behavior_invalidate_dashboard_caches();
			}
			return false; // Session already existed — not a new session.
		}

		// Server-side session canonicalization (mirrors handle_heatmap_event()).
		// The session_id was not found. Before inserting a brand-new session row,
		// check whether this visitor already has an ACTIVE session (start_time within
		// the last 30 min). Stale/legacy client JS (e.g. an old cached
		// opti-behavior-heatmap-simple.js whose anon cookie was wiped by the newer
		// anon-broker) mints a fresh random `session_<ms>_<rand>` id on every page
		// load, which would otherwise INSERT a new session row per page view —
		// reporting every page visit as a new session. Reusing the canonical active
		// session instead collapses those multi-page visits back into one session,
		// regardless of the sid format the client sent.
		if ( ! empty( $visitor_id ) ) {
			// Compute the 30-minute activity cutoff using WordPress local time so it
			// matches how start_time is written (current_time('mysql')). Comparing
			// against the MySQL server clock (NOW()) breaks on hosts where the DB
			// server timezone differs from the WordPress timezone, silently excluding
			// the just-created row and defeating the dedup.
			$active_cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * MINUTE_IN_SECONDS );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$canonical_sid = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}optibehavior_sessions
				 WHERE visitor_id = %s
				 AND start_time >= %s
				 ORDER BY start_time ASC, id ASC
				 LIMIT 1",
				$visitor_id,
				$active_cutoff
			) );
			if ( ! empty( $canonical_sid ) ) {
				// Reuse the existing active session: touch its activity timestamp,
				// do NOT insert a new row.
				$wpdb->update(
					"{$wpdb->prefix}optibehavior_sessions",
					array(
						'end_time' => current_time( 'mysql' ),
					),
					array( 'id' => $canonical_sid ),
					array( '%s' ),
					array( '%s' )
				);
				if ( function_exists( 'opti_behavior_invalidate_dashboard_caches' ) ) {
					opti_behavior_invalidate_dashboard_caches();
				}
				return false; // Merged into the visitor's active session — not a new session.
			}
		}

		{
			// Insert new session
			$session_data = array(
				'id'           => $session_id,
				'visitor_id'   => $data['visitor_id'],
				'start_time'   => current_time( 'mysql' ),
				'referrer'     => isset( $data['referrer'] ) ? $data['referrer'] : '',
				'utm_source'   => isset( $data['utm_source'] ) ? $data['utm_source'] : '',
				'utm_medium'   => isset( $data['utm_medium'] ) ? $data['utm_medium'] : '',
				'utm_campaign' => isset( $data['utm_campaign'] ) ? $data['utm_campaign'] : '',
				'entry_page'   => isset( $data['entry_page'] ) ? $data['entry_page'] : '',
				// Seed exit_page with the entry page: for a bounce session the only
				// page IS the exit page. Later pageviews overwrite it via
				// sync_session_exit_page() in the AJAX handler.
				'exit_page'    => isset( $data['entry_page'] ) ? $data['entry_page'] : '',
				'ip'           => $this->get_client_ip(),
				'is_bounce'    => 1,
			);

			$inserted = $wpdb->insert(
				"{$wpdb->prefix}optibehavior_sessions",
				$session_data,
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);

			// Do NOT mask a failed write as success. When the insert fails (e.g.
			// the analytics tables were never created — see the SQLite GET_LOCK
			// setup regression), $wpdb->insert() returns false. Previously this
			// method returned true unconditionally, so the AJAX handler still
			// emitted wp_send_json_success() with zero rows written — the "on ne
			// détecte rien" false positive. Surface it instead.
			if ( false === $inserted ) {
				$debug_manager = $this->core->get_debug_manager();
				if ( $debug_manager ) {
					$debug_manager->log(
						'Session insert FAILED for ' . $session_id . '. DB error: ' . $wpdb->last_error,
						'error',
						'session'
					);
				}
				return false;
			}

			// New session: invalidate dashboard caches so the stat cards reflect
			// the new visitor within the next 1-second AJAX tick instead of 15 min.
			if ( function_exists( 'opti_behavior_invalidate_dashboard_caches' ) ) {
				opti_behavior_invalidate_dashboard_caches();
			}
			return true; // Brand-new session row was inserted.
		}
	}

	/**
	 * Track visitor information
	 *
	 * @param array $data           Visitor data.
	 * @param bool  $is_new_session True when this call originates from a brand-new session (first
	 *                              page load). False when the session already existed in the DB
	 *                              before this request (e.g. subsequent page loads reusing the
	 *                              same session_id). Counters are only incremented for new sessions
	 *                              to prevent visit_count being inflated by multi-page navigation.
	 */
	public function track_visitor( $data, $is_new_session = true ) {
		global $wpdb;

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}optibehavior_visitors WHERE id = %s",
			$data['visitor_id']
		) );

		if ( $existing ) {
			// Always update the last-seen timestamp.
			$update = array( 'last_visit' => current_time( 'mysql' ) );

			// Only bump counters when this is genuinely a new session; avoids
			// incrementing visit_count 5× for a visitor who browses 5 pages.
			if ( $is_new_session ) {
				$update['visit_count']   = $existing->visit_count + 1;
				$update['total_sessions'] = $existing->total_sessions + 1;
			}

			$formats = array( '%s' );
			if ( $is_new_session ) {
				$formats[] = '%d';
				$formats[] = '%d';
			}

			$wpdb->update(
				"{$wpdb->prefix}optibehavior_visitors",
				$update,
				array( 'id' => $data['visitor_id'] ),
				$formats,
				array( '%s' )
			);
		} else {
			// Create new visitor
			$visitor_data = array(
				'id'              => $data['visitor_id'],
				'first_visit'     => current_time( 'mysql' ),
				'last_visit'      => current_time( 'mysql' ),
				'visit_count'     => 1,
				'total_sessions'  => 1,
				'device_type'     => $this->detect_device_type( $data['user_agent'] ),
				'browser'         => $this->detect_browser( $data['user_agent'] )['name'],
				'browser_version' => $this->detect_browser( $data['user_agent'] )['version'],
				'os'              => $this->detect_os( $data['user_agent'] )['name'],
				'os_version'      => $this->detect_os( $data['user_agent'] )['version'],
				'screen_width'    => isset( $data['screen_width'] ) ? $data['screen_width'] : null,
				'screen_height'   => isset( $data['screen_height'] ) ? $data['screen_height'] : null,
				'language'        => isset( $data['language'] ) ? $data['language'] : '',
			);

			$wpdb->insert(
				"{$wpdb->prefix}optibehavior_visitors",
				$visitor_data,
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Track pageview
	 *
	 * @param array $data Pageview data.
	 */
	public function track_pageview( $data ) {
		global $wpdb;

		$pageview_data = array(
			'session_id'   => $data['session_id'],
			'visitor_id'   => $data['visitor_id'],
			'page_id'      => $data['page_id'],
			'url'          => $data['url'],
			'title'        => $data['title'],
			'view_time'    => current_time( 'mysql' ),
			'time_on_page' => isset( $data['time_on_page'] ) ? $data['time_on_page'] : 0,
			'scroll_depth' => isset( $data['scroll_depth'] ) ? $data['scroll_depth'] : 0,
		);

		$wpdb->insert(
			"{$wpdb->prefix}optibehavior_pageviews",
			$pageview_data,
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	/**
	 * Get client IP address
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				$server_value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				foreach ( explode( ',', $server_value ) as $ip ) {
					$ip = trim( $ip );
					// Accept any valid IP address (including private ranges for local development)
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) {
						return $ip;
					}
				}
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Detect device type from user agent
	 *
	 * @param string $user_agent User agent string.
	 * @return string
	 */
	private function detect_device_type( $user_agent ) {
		if ( wp_is_mobile() ) {
			if ( preg_match( '/tablet|ipad/i', $user_agent ) ) {
				return 'tablet';
			}
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Detect browser from user agent
	 *
	 * @param string $user_agent User agent string.
	 * @return array
	 */
	private function detect_browser( $user_agent ) {
		$browsers = array(
			'Edge'             => '/(?:Edg|EdgA|EdgiOS|Edge)\/([0-9.]+)/i',
			'Opera'            => '/(?:OPR|Opera|OPiOS)\/([0-9.]+)/i',
			'Vivaldi'          => '/Vivaldi\/([0-9.]+)/i',
			'Yandex'           => '/YaBrowser\/([0-9.]+)/i',
			'Samsung Internet' => '/SamsungBrowser\/([0-9.]+)/i',
			'UC Browser'       => '/(?:UCBrowser|UCWEB)\/([0-9.]+)/i',
			'DuckDuckGo'       => '/DuckDuckGo\/([0-9.]+)/i',
			'Huawei Browser'   => '/HuaweiBrowser\/([0-9.]+)/i',
			'Mi Browser'       => '/MiuiBrowser\/([0-9.]+)/i',
			'QQ Browser'       => '/QQBrowser\/([0-9.]+)/i',
			'Naver Whale'      => '/Whale\/([0-9.]+)/i',
			'Maxthon'          => '/Maxthon\/([0-9.]+)/i',
			'Pale Moon'        => '/PaleMoon\/([0-9.]+)/i',
			'Waterfox'         => '/Waterfox\/([0-9.]+)/i',
			'Firefox'          => '/(?:Firefox|FxiOS)\/([0-9.]+)/i',
			'Chrome'           => '/(?:Chrome|CriOS|Chromium)\/([0-9.]+)/i',
		);

		foreach ( $browsers as $browser => $pattern ) {
			if ( preg_match( $pattern, $user_agent, $matches ) ) {
				return array(
					'name'    => $browser,
					'version' => $matches[1],
				);
			}
		}

		if ( preg_match( '/Instagram/i', $user_agent ) ) {
			return array(
				'name'    => 'Instagram In-App Browser',
				'version' => '',
			);
		}

		if ( preg_match( '/FBAN|FBAV/i', $user_agent ) ) {
			return array(
				'name'    => 'Facebook In-App Browser',
				'version' => '',
			);
		}

		if ( preg_match( '/Version\/([0-9.]+).*Safari\/[0-9.]+/i', $user_agent, $matches ) ) {
			return array(
				'name'    => 'Safari',
				'version' => $matches[1],
			);
		}

		if ( preg_match( '/MSIE ([0-9.]+)/i', $user_agent, $matches ) || preg_match( '/Trident\/.*rv:([0-9.]+)/i', $user_agent, $matches ) ) {
			return array(
				'name'    => 'Internet Explorer',
				'version' => $matches[1],
			);
		}

		return array(
			'name'    => 'Unknown',
			'version' => '',
		);
	}

	/**
	 * Detect operating system from user agent
	 *
	 * @param string $user_agent User agent string.
	 * @return array
	 */
	private function detect_os( $user_agent ) {
		// Prioritize mobile OS detection first to avoid misclassification
		// Return simplified OS names without versions for cleaner analytics

		// Mobile OS (check first to avoid Linux false positives)
		// Enhanced Android detection to catch all variations
		if ( preg_match( '/Android/i', $user_agent ) ) {
			$version = '';
			if ( preg_match( '/Android[\s\/]?([0-9.]+)?/i', $user_agent, $matches ) ) {
				$version = isset( $matches[1] ) ? $matches[1] : '';
			}
			return array( 'name' => 'Android', 'version' => $version );
		}

		if ( preg_match( '/(?:iPhone|iPad|iPod).*OS\s([0-9_]+)/i', $user_agent, $matches ) ) {
			$version = isset( $matches[1] ) ? str_replace( '_', '.', $matches[1] ) : '';
			return array( 'name' => 'iOS', 'version' => $version );
		}

		// Windows (all versions return just "Windows")
		if ( preg_match( '/Windows NT ([0-9.]+)/i', $user_agent, $matches ) ) {
			$version = isset( $matches[1] ) ? $matches[1] : '';
			return array( 'name' => 'Windows', 'version' => $version );
		}

		// macOS (all variations return just "macOS")
		if ( preg_match( '/Mac OS X\s*([0-9_.]+)/i', $user_agent, $matches ) ) {
			$version = isset( $matches[1] ) ? str_replace( '_', '.', $matches[1] ) : '';
			return array( 'name' => 'macOS', 'version' => $version );
		}

		if ( preg_match( '/Macintosh.*Intel/i', $user_agent ) ) {
			return array( 'name' => 'macOS', 'version' => '' );
		}

		// Chrome OS
		if ( preg_match( '/CrOS/i', $user_agent ) ) {
			return array( 'name' => 'Chrome OS', 'version' => '' );
		}

		// Linux and Unix-like (all return just "Linux")
		// This will only be reached if Android was not detected above
		if ( preg_match( '/(Ubuntu|Debian|Fedora|CentOS|Red Hat|SUSE|Linux|FreeBSD|OpenBSD|NetBSD)/i', $user_agent ) ) {
			return array( 'name' => 'Linux', 'version' => '' );
		}

		return array(
			'name'    => 'Unknown',
			'version' => '',
		);
	}

	/**
	 * Update session end time and duration
	 *
	 * @param string $session_id Session ID.
	 * @param int    $duration Duration in seconds.
	 */
	public function update_session_end( $session_id, $duration ) {
		global $wpdb;

		$wpdb->update(
			"{$wpdb->prefix}optibehavior_sessions",
			array(
				'end_time' => current_time( 'mysql' ),
				'duration' => $duration,
			),
			array( 'id' => $session_id ),
			array( '%s', '%d' ),
			array( '%s' )
		);
	}

	/**
	 * Store session event
	 *
	 * @param string $session_id Session ID.
	 * @param string $visitor_id Visitor ID.
	 * @param array  $event Event data.
	 */
	public function store_session_event( $session_id, $visitor_id, $event ) {
		global $wpdb;

		// Check storage mode - if file storage is enabled, don't save to database
		$file_storage = $this->core->get_file_storage();
		$storage_settings = $file_storage ? $file_storage->get_settings() : array( 'storage_mode' => 'database' );

		if ( $storage_settings['storage_mode'] === 'file' ) {
			// File storage mode - events should be handled by the AJAX handler's buffer system
			// Don't save to database
			return;
		}

		// Normalize event code: accept numeric or string (e.g., 'click_pc')
		$event_code = null;
		if ( isset( $event['event'] ) ) {
			if ( is_numeric( $event['event'] ) ) {
				$event_code = intval( $event['event'] );
			} elseif ( is_string( $event['event'] ) ) {
				$event_names = Opti_Behavior_Heatmap_Core::EVENT_NAMES;
				if ( isset( $event_names[ $event['event'] ] ) ) {
					$event_code = $event_names[ $event['event'] ];
				}
			}
		}
		if ( null === $event_code ) {
			return; // Unknown event, skip
		}

		$event_data = array(
			'page_id'         => intval( $event['page_id'] ),
			'page_id2'        => intval( $event['page_id'] ),
			'session_id'      => $session_id,
			'event'           => $event_code,
			'x'               => intval( $event['x'] ),
			'y'               => intval( $event['y'] ),
			'width'           => intval( $event['width'] ),
			'height'          => intval( $event['height'] ),
			'x_normalized'    => isset( $event['x_normalized'] ) ? floatval( $event['x_normalized'] ) : null,
			'y_normalized'    => isset( $event['y_normalized'] ) ? floatval( $event['y_normalized'] ) : null,
			'viewport_width'  => isset( $event['viewport_width'] ) ? intval( $event['viewport_width'] ) : null,
			'viewport_height' => isset( $event['viewport_height'] ) ? intval( $event['viewport_height'] ) : null,
			'element_tag'     => isset( $event['element_tag'] ) ? $event['element_tag'] : null,
			'element_id'      => isset( $event['element_id'] ) ? $event['element_id'] : null,
			'element_class'   => isset( $event['element_class'] ) ? $event['element_class'] : null,
			'element_text'    => isset( $event['element_text'] ) ? mb_substr( sanitize_text_field( $event['element_text'] ), 0, 150 ) : null,
			'element_type'    => isset( $event['element_type'] ) ? mb_substr( sanitize_text_field( $event['element_type'] ), 0, 40 ) : null,
			'element_builder' => isset( $event['element_builder'] ) ? mb_substr( sanitize_text_field( $event['element_builder'] ), 0, 50 ) : null,
			'element_selector' => isset( $event['element_selector'] ) ? mb_substr( sanitize_text_field( $event['element_selector'] ), 0, 191 ) : null,
			// Element anchor for click reprojection (element-anchored heatmap).
			// Nullable/additive: legacy events simply store NULL.
			'element_xpath'   => isset( $event['element_xpath'] ) && '' !== $event['element_xpath'] ? mb_substr( sanitize_text_field( $event['element_xpath'] ), 0, 512 ) : null,
			'element_rel_x'   => isset( $event['element_rel_x'] ) && is_numeric( $event['element_rel_x'] ) ? min( 1, max( 0, floatval( $event['element_rel_x'] ) ) ) : null,
			'element_rel_y'   => isset( $event['element_rel_y'] ) && is_numeric( $event['element_rel_y'] ) ? min( 1, max( 0, floatval( $event['element_rel_y'] ) ) ) : null,
			'insert_at'       => current_time( 'mysql' ),
		);

		// Database storage mode - save to database
		$wpdb->insert(
			"{$wpdb->prefix}optibehavior_events",
			$event_data,
			array( '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%f', '%f', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s' )
		);
	}

	/**
	 * Track referrer information
	 *
	 * @param array $data Referrer data.
	 */
	public function track_referrer( $data ) {
		global $wpdb;

		// Filter out internal referrers (same domain) - should not be stored as referrals
		if ( ! empty( $data['referrer_url'] ) ) {
			$referrer_host = wp_parse_url( $data['referrer_url'], PHP_URL_HOST );
			$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $referrer_host && $site_host ) {
				$ref_clean  = preg_replace( '/^www\./i', '', $referrer_host );
				$site_clean = preg_replace( '/^www\./i', '', $site_host );
				if ( strcasecmp( $ref_clean, $site_clean ) === 0 ) {
					$data['referrer_url'] = null; // Internal referrer, treat as Direct
				}
			}
		}

		$referrer_data = array(
			'session_id'   => $data['session_id'],
			'page_id'      => $data['page_id'],
			'referrer_url' => isset( $data['referrer_url'] ) ? $data['referrer_url'] : null,
			'referrer_type' => $this->determine_referrer_type( $data['referrer_url'] ?? '', $data['entry_url'] ?? '' ),
			'entry_url'    => $data['entry_url'],
			'utm_source'   => isset( $data['utm_source'] ) ? $data['utm_source'] : null,
			'utm_medium'   => isset( $data['utm_medium'] ) ? $data['utm_medium'] : null,
			'utm_campaign' => isset( $data['utm_campaign'] ) ? $data['utm_campaign'] : null,
			'utm_term'     => isset( $data['utm_term'] ) ? $data['utm_term'] : null,
			'utm_content'  => isset( $data['utm_content'] ) ? $data['utm_content'] : null,
			'created_at'   => current_time( 'mysql' ),
		);

		$wpdb->insert(
			"{$wpdb->prefix}optibehavior_referrers",
			$referrer_data,
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Track outbound click
	 *
	 * @param array $data Outbound click data.
	 */
	public function track_outbound_click( $data ) {
		global $wpdb;

		$click_data = array(
			'session_id'    => $data['session_id'],
			'page_id'       => $data['page_id'],
			'source_url'    => $data['source_url'],
			'target_url'    => $data['target_url'],
			'click_type'    => $this->determine_click_type( $data['target_url'], $data['source_url'] ),
			'element_tag'   => isset( $data['element_tag'] ) ? $data['element_tag'] : null,
			'element_id'    => isset( $data['element_id'] ) ? $data['element_id'] : null,
			'element_class' => isset( $data['element_class'] ) ? $data['element_class'] : null,
			'element_text'  => isset( $data['element_text'] ) ? substr( $data['element_text'], 0, 500 ) : null,
			'x'             => isset( $data['x'] ) ? intval( $data['x'] ) : null,
			'y'             => isset( $data['y'] ) ? intval( $data['y'] ) : null,
			'created_at'    => current_time( 'mysql' ),
		);

		$wpdb->insert(
			"{$wpdb->prefix}optibehavior_outbound_clicks",
			$click_data,
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Determine referrer type
	 *
	 * @param string $referrer_url Referrer URL.
	 * @param string $entry_url Entry URL.
	 * @return string
	 */
	private function determine_referrer_type( $referrer_url, $entry_url ) {
		if ( empty( $referrer_url ) ) {
			return 'direct';
		}

		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$referrer_domain = wp_parse_url( $referrer_url, PHP_URL_HOST );

		if ( $referrer_domain === $site_domain ) {
			return 'internal';
		}

		return 'external';
	}

	/**
	 * Determine click type
	 *
	 * @param string $target_url Target URL.
	 * @param string $source_url Source URL.
	 * @return string
	 */
	private function determine_click_type( $target_url, $source_url ) {
		$site_domain   = wp_parse_url( home_url(), PHP_URL_HOST );
		$target_domain = wp_parse_url( $target_url, PHP_URL_HOST );

		if ( $target_domain === $site_domain ) {
			// Treat common internal redirect patterns as external exits
			$path = wp_parse_url( $target_url, PHP_URL_PATH );
			if ( $path && preg_match( '#^/(go|out|redirect|recommends)/#i', $path ) ) {
				return 'external';
			}
			return 'internal';
		}

		return 'external';
	}

	/**
	 * Get referrer data for a page
	 *
	 * @param int $page_id Page ID.
	 * @param int $limit Limit results.
	 * @return array
	 */
	public function get_page_referrers( $page_id, $limit = 10 ) {
		global $wpdb;

		// Get referrer data from sessions table, excluding internal referrers.
		// Internal referrers (same site domain) are reclassified as 'direct' so the
		// site's own URL never appears as a referring website.
		$sessions = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				CASE
					WHEN s.referrer IS NULL OR TRIM(s.referrer) = '' THEN NULL
					WHEN s.referrer LIKE CONCAT('%%', %s, '%%') THEN NULL
					ELSE COALESCE(NULLIF(TRIM(s.referrer), ''), NULL)
				END as referrer_url,
				CASE
					WHEN s.referrer IS NULL OR TRIM(s.referrer) = '' THEN 'direct'
					WHEN s.referrer LIKE CONCAT('%%', %s, '%%') THEN 'direct'
					ELSE 'external'
				END as referrer_type,
				COUNT(DISTINCT s.id) as count,
				COALESCE(NULLIF(TRIM(s.utm_source), ''), NULL) as utm_source,
				COALESCE(NULLIF(TRIM(s.utm_medium), ''), NULL) as utm_medium,
				COALESCE(NULLIF(TRIM(s.utm_campaign), ''), NULL) as utm_campaign,
				MAX(s.start_time) as last_visit
			 FROM {$wpdb->prefix}optibehavior_sessions s
			 INNER JOIN {$wpdb->prefix}optibehavior_pageviews pv ON s.id = pv.session_id
			 WHERE pv.page_id = %d
			 GROUP BY referrer_url,
					  referrer_type,
					  COALESCE(NULLIF(TRIM(s.utm_source), ''), 'NONE'),
					  COALESCE(NULLIF(TRIM(s.utm_medium), ''), 'NONE'),
					  COALESCE(NULLIF(TRIM(s.utm_campaign), ''), 'NONE')
			 ORDER BY count DESC, last_visit DESC
			 LIMIT %d",
			wp_parse_url( home_url(), PHP_URL_HOST ),
			wp_parse_url( home_url(), PHP_URL_HOST ),
			$page_id,
			$limit
		), ARRAY_A );

		return $sessions;
	}

	/**
	 * Get outbound clicks for a page
	 *
	 * @param int $page_id Page ID.
	 * @param int $limit Limit results.
	 * @return array
	 */
	public function get_page_outbound_clicks( $page_id, $limit = 10 ) {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT target_url, click_type, COUNT(*) as count,
			        element_tag, element_text,
			        MAX(created_at) as last_click
			 FROM {$wpdb->prefix}optibehavior_outbound_clicks
			 WHERE page_id = %d
			 GROUP BY target_url, click_type
			 ORDER BY count DESC, last_click DESC
			 LIMIT %d",
			$page_id, $limit
		), ARRAY_A );
	}
}
