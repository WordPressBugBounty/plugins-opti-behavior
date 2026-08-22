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
	 * Heartbeat interval in seconds (24 hours).
	 *
	 * @var int
	 */
	const HEARTBEAT_INTERVAL = 86400; // 24 hours

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

		// Schedule daily heartbeat cron
		add_action( 'opti_behavior_daily_heartbeat', array( $this, 'send_heartbeat' ) );

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
	 * Schedule the daily heartbeat cron job and send an immediate heartbeat.
	 *
	 * Called on plugin activation. Sends a heartbeat right away so the API
	 * knows the plugin is active (re-activates disabled status if needed).
	 *
	 * @return void
	 */
	public function schedule_heartbeat() {
		if ( ! wp_next_scheduled( 'opti_behavior_daily_heartbeat' ) ) {
			wp_schedule_event( time() + self::HEARTBEAT_INTERVAL, 'daily', 'opti_behavior_daily_heartbeat' );
		}

		// Send immediate heartbeat on activation to re-activate status in the API
		$this->send_heartbeat();
	}

	/**
	 * Maybe track the installation.
	 *
	 * Checks if tracking is needed based on:
	 * 1. First time install (never tracked before)
	 * 2. Heartbeat interval elapsed (24 hours since last heartbeat)
	 *
	 * @return void
	 */
	public function maybe_track() {
		// Only track for admins
		if ( ! current_user_can( 'manage_options' ) ) {
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

		// Send heartbeat if:
		// 1. Never tracked before (last_heartbeat = 0)
		// 2. Heartbeat interval has elapsed
		if ( 0 === $last_heartbeat || $time_since_last >= self::HEARTBEAT_INTERVAL ) {
			$this->send_heartbeat();
		}

		// Ensure cron is scheduled — guard with wp_next_scheduled() to avoid
		// an unnecessary DB query on every admin page load.
		if ( ! wp_next_scheduled( 'opti_behavior_daily_heartbeat' ) ) {
			$this->schedule_heartbeat();
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
		// Respect admin consent — do not transmit any data until accepted.
		// Local-debug bypass: when running against the local API with WP_DEBUG on,
		// allow the heartbeat to fire without requiring the welcome-screen consent.
		if ( ! get_option( 'opti_behavior_consent_accepted' ) && ! $this->is_local_debug() ) {
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

		// Send tracking request
		$response = wp_remote_post(
			$api_url . 'track-install',
			array(
				'body'        => wp_json_encode( $data ),
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
				'timeout'     => 15,
				'sslverify'   => $sslverify,
				'blocking'    => true,
			)
		);

		// Check for errors
		if ( is_wp_error( $response ) ) {
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
			return true;
		}

		// Handle rate limiting - still update timestamp to avoid hammering
		if ( 429 === $response_code ) {
			// Rate limited - try again next interval
			update_option( self::OPTION_LAST_HEARTBEAT, time() );
			return false;
		}

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
				'timeout'   => 10,
				'sslverify' => $sslverify,
				'blocking'  => true,
			)
		);

		// Reset heartbeat timer so re-activation triggers an immediate heartbeat
		delete_option( self::OPTION_LAST_HEARTBEAT );

		// Clear the scheduled cron (will be re-scheduled on activation)
		wp_clear_scheduled_hook( 'opti_behavior_daily_heartbeat' );

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
	 * Clear tracking data on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall() {
		delete_option( self::OPTION_LAST_HEARTBEAT );
		delete_option( self::OPTION_TRACKED );
		wp_clear_scheduled_hook( 'opti_behavior_daily_heartbeat' );
	}
}
