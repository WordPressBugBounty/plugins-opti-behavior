<?php
/**
 * Plugin Install Tracker
 *
 * Lightweight tracker that sends anonymous install data with a 24-hour
 * heartbeat to confirm the plugin is still active. Works for both Free
 * and Pro versions - automatically detects which is active.
 *
 * Data collected:
 * - Site URL
 * - Admin email
 * - Plugin type (free or pro)
 * - Plugin version
 * - WordPress version
 * - PHP version
 *
 * @package Opti-Behavior
 * @since 1.0.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Free_Tracker
 *
 * Handles anonymous installation tracking for Opti-Behavior.
 * Uses a 24-hour heartbeat mechanism to track active installations.
 * Works for both Free-only and Free+Pro setups.
 *
 * @since 1.0.9.6
 */
class Opti_Behavior_Free_Tracker {

	/**
	 * Option name for tracking the last heartbeat time.
	 *
	 * @var string
	 */
	const OPTION_LAST_HEARTBEAT = 'opti_behavior_tracker_last_heartbeat';

	/**
	 * Option name for tracking if initial registration was done.
	 *
	 * @var string
	 */
	const OPTION_TRACKED = 'opti_behavior_tracked';

	/**
	 * Option caching the final has_data_7d answer ('1' or '0').
	 *
	 * @var string
	 */
	const OPTION_HAS_DATA_7D = 'opti_behavior_has_data_7d';

	/**
	 * Heartbeat interval in seconds (24 hours).
	 *
	 * @var int
	 */
	const HEARTBEAT_INTERVAL = 86400; // 24 hours

	/**
	 * Cron hook of the status message (daily event + one-off catch-up runs).
	 *
	 * @var string
	 */
	const HEARTBEAT_HOOK = 'opti_behavior_daily_heartbeat';

	/**
	 * Transient holding the timestamp before which no new attempt is made after
	 * a failed send (API unreachable, 5xx...). Covered by the uninstall
	 * `_transient_opti_behavior_%` sweep.
	 *
	 * @var string
	 */
	const RETRY_AFTER_TRANSIENT = 'opti_behavior_tracker_retry_after';

	/**
	 * Pause after a failed send, in seconds (12 hours).
	 *
	 * @var int
	 */
	const RETRY_PAUSE = 43200;

	/**
	 * HTTP timeout of every call to the API, in seconds.
	 *
	 * @var int
	 */
	const HTTP_TIMEOUT = 3;

	/**
	 * HTTP timeout of track-deactivate, in seconds. The call runs inside the
	 * Deactivate click, so an unreachable API must not hold the Plugins page
	 * (OPT-643: click -> deactivated < 4 s with the survey).
	 *
	 * @var int
	 */
	const DEACTIVATE_TIMEOUT = 2;

	/**
	 * Outcome of the last send_heartbeat() call: 'ok', 'rate_limited',
	 * 'transport' (WP_Error), 'http' (other status) or 'skipped'.
	 *
	 * @var string
	 */
	private $last_result = '';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - private for singleton pattern.
	 *
	 * Always initializes regardless of whether Pro is active.
	 * The tracker handles both Free-only and Free+Pro setups.
	 */
	private function __construct() {
		// Initialize tracking on admin_init (only for logged-in admins)
		add_action( 'admin_init', array( $this, 'maybe_track' ) );

		// Daily heartbeat cron (also runs the one-off catch-up queued by maybe_track()).
		add_action( self::HEARTBEAT_HOOK, array( $this, 'run_scheduled_heartbeat' ) );

		// Register activation hook for scheduling
		register_activation_hook( OPTI_BEHAVIOR_HEATMAP, array( $this, 'schedule_heartbeat' ) );

		// Register deactivation hook to notify API
		register_deactivation_hook( OPTI_BEHAVIOR_HEATMAP, array( $this, 'send_deactivation' ) );
	}

	/**
	 * Initialize the tracker.
	 *
	 * @return self
	 */
	public static function init() {
		return self::get_instance();
	}

	/**
	 * True when an active, registered Opti-Behavior Pro sends its own daily signed
	 * site status (OPT-690/693): Free then sends no track-install and hides its
	 * "Share installation status" card. Old Pro, unregistered Pro or no Pro = false.
	 * The stored Yes / No choice is never touched here.
	 *
	 * @return bool
	 */
	public static function pro_handles_status() {
		if ( ! function_exists( 'opti_behavior_pro_sends_site_status' ) ) {
			return false;
		}
		try {
			return true === (bool) opti_behavior_pro_sends_site_status();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Schedule the daily heartbeat cron job and send an immediate heartbeat.
	 *
	 * Called on plugin activation. Sends a heartbeat right away so the API
	 * knows the plugin is active (re-activates disabled status if needed).
	 *
	 * @return void
	 */
	public function schedule_heartbeat() {
		// Pro sends the site status itself: nothing from Free.
		if ( self::pro_handles_status() ) {
			$this->last_result = 'skipped';
			return;
		}

		// No consent (never answered, or "Continue without sharing"): no cron, nothing sent.
		if ( ! get_option( 'opti_behavior_consent_accepted' ) && ! $this->is_local_debug() ) {
			return;
		}

		$this->ensure_daily_event();

		// Send immediate heartbeat on activation to re-activate status in the API
		// (short timeout, skipped while a failed send is still paused).
		$this->run_scheduled_heartbeat();
	}

	/**
	 * Schedule the daily cron event when nothing is queued on the hook.
	 *
	 * @return void
	 */
	private function ensure_daily_event() {
		if ( ! wp_next_scheduled( self::HEARTBEAT_HOOK ) ) {
			wp_schedule_event( time() + self::HEARTBEAT_INTERVAL, 'daily', self::HEARTBEAT_HOOK );
		}
	}

	/**
	 * Cron callback: send the status message unless a failed send is still paused.
	 *
	 * A transport failure (API unreachable, timeout) or any answer other than
	 * 200 / 429 pauses every new attempt for RETRY_PAUSE; a 200 clears the pause.
	 *
	 * @return bool True if the API accepted the heartbeat.
	 */
	public function run_scheduled_heartbeat() {
		// Pro sends the site status itself: the cron stays, the run sends nothing.
		if ( self::pro_handles_status() || self::is_retry_paused() ) {
			$this->last_result = 'skipped';
			return false;
		}

		$sent = $this->send_heartbeat();
		if ( 'transport' === $this->last_result || 'http' === $this->last_result ) {
			set_transient( self::RETRY_AFTER_TRANSIENT, time() + self::RETRY_PAUSE, self::RETRY_PAUSE );
		}
		return $sent;
	}

	/**
	 * Whether a failed send is still inside its retry pause.
	 *
	 * @return bool
	 */
	public static function is_retry_paused() {
		$until = (int) get_transient( self::RETRY_AFTER_TRANSIENT );
		return $until > time();
	}

	/**
	 * Maybe track the installation.
	 *
	 * Never talks to the API itself (admin_init must not wait on a remote
	 * server): when a heartbeat is due (first time, or 24 hours since the last
	 * one) it queues a one-off run of the cron hook, which sends it in the
	 * background.
	 *
	 * @return void
	 */
	public function maybe_track() {
		// Only track for admins
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Pro sends the site status itself. Nothing queued; once Pro is gone the next
		// admin_init resumes here (only with a stored Yes, checked just below).
		if ( self::pro_handles_status() ) {
			return;
		}

		// Do not track until the admin has accepted the welcome/consent screen.
		// Local-debug bypass: skip consent gate when OPTI_BEHAVIOR_ENVIRONMENT === 'local'
		// AND WP_DEBUG is on, so devs can exercise the heartbeat without clicking consent.
		if ( ! get_option( 'opti_behavior_consent_accepted' ) && ! $this->is_local_debug() ) {
			return;
		}

		// Check if we need to send a heartbeat
		$last_heartbeat = get_option( self::OPTION_LAST_HEARTBEAT, 0 );
		$time_since_last = time() - (int) $last_heartbeat;

		// Ensure the daily cron is scheduled (no send here).
		$this->ensure_daily_event();

		// Heartbeat due when never tracked before (last_heartbeat = 0) or the
		// interval has elapsed, and no failed send is paused: queue a one-off
		// run now unless the hook already runs within the next minute.
		if ( ( 0 === $last_heartbeat || $time_since_last >= self::HEARTBEAT_INTERVAL ) && ! self::is_retry_paused() ) {
			$next = wp_next_scheduled( self::HEARTBEAT_HOOK );
			if ( false === $next || $next > time() + MINUTE_IN_SECONDS ) {
				wp_schedule_single_event( time(), self::HEARTBEAT_HOOK );
			}
		}
	}

	/**
	 * Send a heartbeat/tracking request to the API.
	 *
	 * Automatically detects whether Pro is active and sets plugin_type accordingly.
	 * Always sends both free_plugin_version and pro_plugin_version independently
	 * so the API can track each plugin's version separately.
	 *
	 * @return bool True if successful, false otherwise.
	 */
	public function send_heartbeat() {
		// Pro sends the site status itself (also covers Pro's own deactivation call).
		if ( self::pro_handles_status() ) {
			$this->last_result = 'skipped';
			return false;
		}

		// Respect admin consent — do not transmit any data until accepted.
		// Local-debug bypass: when running against the local API with WP_DEBUG on,
		// allow the heartbeat to fire without requiring the welcome-screen consent.
		if ( ! get_option( 'opti_behavior_consent_accepted' ) && ! $this->is_local_debug() ) {
			$this->last_result = 'skipped';
			return false;
		}

		$api_url   = $this->get_api_url();
		$sslverify = $this->should_verify_ssl( $api_url );

		// Detect plugin type: if Pro is active, report as 'pro'.
		// Defensive guard: if Pro was active within the last 24 hours but is temporarily
		// unavailable (e.g. cron context, memory limit), keep reporting 'pro' to prevent
		// a transient free heartbeat from downgrading the API installation record and
		// blocking recording encryption.
		$is_pro_active = defined( 'OPTI_BEHAVIOR_PRO_VERSION' );
		if ( $is_pro_active ) {
			$plugin_type = 'pro';
		} else {
			$pro_last_active = (int) get_option( 'opti_behavior_pro_last_active', 0 );
			$plugin_type     = ( $pro_last_active > 0 && ( time() - $pro_last_active ) < DAY_IN_SECONDS )
				? 'pro'
				: 'free';
		}

		// Free version is always available (this IS the free plugin)
		$free_version = defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1.0.0';

		// Pro version only if Pro add-on is active
		$pro_version = $is_pro_active ? OPTI_BEHAVIOR_PRO_VERSION : null;

		// Legacy plugin_version field (kept for backward compatibility)
		$plugin_version = $is_pro_active ? OPTI_BEHAVIOR_PRO_VERSION : $free_version;

		// Resolve one stable contact email for tracking. The heartbeat is not an
		// interactive flow, so do not prefer whichever administrator happens to
		// trigger it; this keeps API-side email automation to one deterministic
		// contact per site instead of one per admin.
		$admin_email = $this->resolve_tracking_admin_email();

		// Prepare tracking data
		// `pro_runtime_active` is the REAL-TIME Pro state (no 24h sticky guard).
		// The dashboard uses this to display the badge + quota cell, while the
		// `plugin_type` field above remains sticky-upward so encryption tier
		// doesn't downgrade on transient Pro-load failures (cron, memory limit).
		$data = array(
			'site_url'            => get_site_url(),
			'plugin_type'         => $plugin_type,
			'plugin_version'      => $plugin_version,
			'free_plugin_version' => $free_version,
			'pro_plugin_version'  => $pro_version,
			'pro_runtime_active'  => $is_pro_active ? 1 : 0,
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'admin_email'         => $admin_email,
		);

		// has_data_7d is part of data-sharing text v2 only: sites that accepted the
		// previous text keep the old payload until they accept the new one.
		if ( class_exists( 'Opti_Behavior_Welcome' ) && Opti_Behavior_Welcome::get_consent_version() >= 2 ) {
			$has_data_7d = self::get_has_data_7d();
			if ( null !== $has_data_7d ) {
				$data['has_data_7d'] = $has_data_7d;
			}
		}

		// Send tracking request
		$response = wp_remote_post(
			$api_url . 'track-install',
			array(
				'body'        => wp_json_encode( $data ),
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
				'timeout'     => self::HTTP_TIMEOUT,
				'sslverify'   => $sslverify,
				'blocking'    => true,
			)
		);

		// Check for errors
		if ( is_wp_error( $response ) ) {
			$this->last_result = 'transport';
			// Log error but don't expose it to user
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is on.
				error_log( '[Opti-Behavior Tracker] Tracking error: ' . $response->get_error_message() );
			}
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// Handle success
		if ( 200 === $response_code ) {
			// Update last heartbeat time
			update_option( self::OPTION_LAST_HEARTBEAT, time() );
			update_option( self::OPTION_TRACKED, true );
			if ( false !== get_transient( self::RETRY_AFTER_TRANSIENT ) ) {
				delete_transient( self::RETRY_AFTER_TRANSIENT );
			}
			$this->last_result = 'ok';
			return true;
		}

		// Handle rate limiting - still update timestamp to avoid hammering
		if ( 429 === $response_code ) {
			// Rate limited - try again next interval
			update_option( self::OPTION_LAST_HEARTBEAT, time() );
			$this->last_result = 'rate_limited';
			return false;
		}

		$this->last_result = 'http';

		// Log other errors
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is on.
			error_log( '[Opti-Behavior Tracker] Tracking failed with code ' . $response_code . ': ' . $response_body );
		}
		return false;
	}

	/**
	 * Send a deactivation notification to the API.
	 *
	 * Called when the plugin is deactivated via register_deactivation_hook.
	 * Notifies the API so the site status is marked as disabled immediately
	 * instead of waiting for the 48h staleness check.
	 *
	 * @return bool True if successful, false otherwise.
	 */
	public function send_deactivation() {
		// Only notify the API if consent was given (i.e., we previously sent heartbeats).
		// Local-debug bypass: in dev we still want deactivation events to flow.
		if ( ! get_option( 'opti_behavior_consent_accepted' ) && ! $this->is_local_debug() ) {
			delete_option( self::OPTION_LAST_HEARTBEAT );
			wp_clear_scheduled_hook( 'opti_behavior_daily_heartbeat' );
			return false;
		}

		$api_url   = $this->get_api_url();
		$sslverify = $this->should_verify_ssl( $api_url );

		$data = array(
			'site_url' => get_site_url(),
		);

		$response = wp_remote_post(
			$api_url . 'track-deactivate',
			array(
				'body'      => wp_json_encode( $data ),
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
				'timeout'   => self::DEACTIVATE_TIMEOUT,
				'sslverify' => $sslverify,
				'blocking'  => true,
			)
		);

		// Reset heartbeat timer so re-activation triggers an immediate heartbeat
		delete_option( self::OPTION_LAST_HEARTBEAT );

		// Clear the scheduled cron (will be re-scheduled on activation)
		wp_clear_scheduled_hook( self::HEARTBEAT_HOOK );

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is on.
				error_log( '[Opti-Behavior Tracker] Deactivation notification error: ' . $response->get_error_message() );
			}
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $response_code ) {
			return true;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is on.
			error_log( '[Opti-Behavior Tracker] Deactivation notification failed with code ' . $response_code );
		}
		return false;
	}

	/**
	 * Get the API URL based on environment setting.
	 *
	 * Honors the OPTI_BEHAVIOR_ENVIRONMENT constant (defined in the main plugin
	 * file) so local development hits the localhost API instead of production.
	 * The local URL only activates when BOTH OPTI_BEHAVIOR_ENVIRONMENT === 'local'
	 * AND WP_DEBUG === true, matching the pattern in class-opti-behavior-welcome.php
	 * and the Pro license-manager. Production sites have neither defined.
	 *
	 * @return string API base URL with trailing slash.
	 */
	private function get_api_url() {
		if ( $this->is_local_debug() ) {
			return 'http://localhost/API/'; // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- dev-only URL, only active when OPTI_BEHAVIOR_ENVIRONMENT=local and WP_DEBUG are set; never active in production.
		}
		return 'https://api.optiuser.com/';
	}

	/**
	 * Whether the plugin is running in local-debug mode.
	 *
	 * True only when both OPTI_BEHAVIOR_ENVIRONMENT === 'local' and WP_DEBUG === true.
	 *
	 * @return bool
	 */
	private function is_local_debug() {
		return ( defined( 'OPTI_BEHAVIOR_ENVIRONMENT' ) && 'local' === OPTI_BEHAVIOR_ENVIRONMENT
			&& defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Whether wp_remote_* should verify SSL for the given URL.
	 *
	 * Disables verification only for plain-HTTP localhost URLs so local devs can
	 * exercise the heartbeat without TLS. All other URLs keep verification on.
	 *
	 * @param string $url Target URL.
	 * @return bool
	 */
	private function should_verify_ssl( $url ) {
		return ( strpos( $url, 'http://localhost' ) !== 0 );
	}

	/**
	 * Resolve a stable, valid admin contact email for install tracking.
	 *
	 * The track-install endpoint accepts a null email, so never send an invalid
	 * or placeholder value. Current-user candidates are intentionally excluded
	 * because heartbeats are site-level events and should not vary by admin user.
	 *
	 * @return string|null Valid email address, or null when no contact is available.
	 */
	private function resolve_tracking_admin_email() {
		if ( class_exists( 'Opti_Behavior_Heatmap_Admin_Email_Resolver' ) ) {
			return Opti_Behavior_Heatmap_Admin_Email_Resolver::get_email(
				array(
					'prefer_current_user'  => false,
					'include_current_user' => false,
				)
			);
		}

		$admin_email = sanitize_email( get_option( 'admin_email' ) );

		return is_email( $admin_email ) ? $admin_email : null;
	}

	/**
	 * Whether this site recorded at least one page view in its first 7 days.
	 *
	 * 1 = a pageview with view_time <= install time + 7 days (sticky: once 1, stays 1);
	 * 0 = none and the 7 days are over (final too); null = no install time, or still
	 * inside the 7 days without data (the field is then not sent).
	 *
	 * @return int|null
	 */
	public static function get_has_data_7d() {
		$stored = get_option( self::OPTION_HAS_DATA_7D, '' );
		if ( '1' === $stored || '0' === $stored ) {
			return (int) $stored;
		}

		$installed_at = (int) get_option( 'opti_behavior_review_installed_at', 0 );
		if ( $installed_at <= 0 ) {
			return null;
		}

		global $wpdb;
		$window_end = $installed_at + 7 * DAY_IN_SECONDS;
		// view_time is stored in site time (current_time( 'mysql' )).
		$cutoff = wp_date( 'Y-m-d H:i:s', $window_end );
		$table  = $wpdb->prefix . 'optibehavior_pageviews';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- one indexed row probe, once a day; table name from $wpdb->prefix.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE view_time <= %s LIMIT 1", $cutoff ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $found ) {
			update_option( self::OPTION_HAS_DATA_7D, '1', false );
			return 1;
		}
		if ( time() >= $window_end && empty( $wpdb->last_error ) ) {
			update_option( self::OPTION_HAS_DATA_7D, '0', false );
			return 0;
		}
		return null;
	}

	/**
	 * Clear tracking data on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall() {
		delete_option( self::OPTION_LAST_HEARTBEAT );
		delete_option( self::OPTION_TRACKED );
		delete_option( self::OPTION_HAS_DATA_7D );
		delete_transient( self::RETRY_AFTER_TRANSIENT );
		wp_clear_scheduled_hook( self::HEARTBEAT_HOOK );
	}
}
