<?php
/**
 * Cron Health Watchdog
 *
 * Lightweight admin-only watchdog that warns administrators when WP-Cron
 * appears not to be firing. It inspects a single sentinel event
 * (`opti_behavior_send_scheduled_reports`, 15-minute cadence) and shows a
 * dismissible admin notice when that event is overdue by more than twice its
 * interval (and more than 15 minutes in absolute terms).
 *
 * Behavior-neutral by design: no auto-repair, no cron events of its own,
 * zero front-end cost (hooks only in the admin area, never AJAX/cron).
 *
 * @package opti-behavior
 * @since   1.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Cron_Health
 *
 * Admin-notice watchdog for stalled WP-Cron.
 *
 * @since 1.7.2
 */
class Opti_Behavior_Cron_Health {

	/**
	 * Sentinel cron hook checked for overdue-ness.
	 *
	 * The scheduled-reports worker runs every 15 minutes on every install,
	 * which makes it the best signal that WP-Cron is alive.
	 */
	const SENTINEL_HOOK = 'opti_behavior_send_scheduled_reports';

	/** Fallback sentinel interval in seconds (every_fifteen_minutes). */
	const SENTINEL_INTERVAL = 900;

	/** Transient throttling the check to at most once per hour. Stores 'ok' or 'overdue'. */
	const TRANSIENT_STATUS = 'opti_behavior_cron_health_checked';

	/** Transient set when an admin dismisses the notice. Expires after one week. */
	const TRANSIENT_DISMISSED = 'opti_behavior_cron_health_dismissed';

	/** Query arg / nonce action used by the dismiss link. */
	const DISMISS_ACTION = 'opti_behavior_cron_health_dismiss';

	/** @var self|null Singleton instance. */
	private static $instance = null;

	/**
	 * Initialize the singleton.
	 *
	 * No-op outside the admin area and during AJAX/cron requests so the
	 * watchdog carries zero front-end and zero background cost.
	 *
	 * @since 1.7.2
	 * @return self|null
	 */
	public static function init() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return null;
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Hooks admin-side callbacks only.
	 *
	 * @since 1.7.2
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
	}

	/**
	 * Persist dismissal when the notice's dismiss link is clicked.
	 *
	 * Dismissal is stored in a one-week transient so admins are re-warned
	 * eventually if WP-Cron is still broken.
	 *
	 * @since 1.7.2
	 * @return void
	 */
	public function handle_dismiss() {
		if ( ! isset( $_GET[ self::DISMISS_ACTION ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ACTION ) ) {
			return;
		}

		set_transient( self::TRANSIENT_DISMISSED, 1, WEEK_IN_SECONDS );

		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ACTION, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Render the warning notice when the sentinel event is overdue.
	 *
	 * @since 1.7.2
	 * @return void
	 */
	public function maybe_render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_transient( self::TRANSIENT_DISMISSED ) ) {
			return;
		}

		if ( 'overdue' !== $this->get_status() ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_ACTION, '1' ),
			self::DISMISS_ACTION
		);

		$message = __( 'Opti Behavior: WP-Cron does not appear to be firing on this site. Scheduled background tasks (reports, aggregation, cleanup) are overdue. Low-traffic sites or a misconfigured cron setup are common causes.', 'opti-behavior' );

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$message .= ' ' . __( 'DISABLE_WP_CRON is set to true in wp-config.php — make sure a real (system) cron job requests wp-cron.php regularly.', 'opti-behavior' );
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p><p><a href="%s">%s</a></p></div>',
			esc_html( $message ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss for one week', 'opti-behavior' )
		);
	}

	/**
	 * Get the cached health status, recomputing at most once per hour.
	 *
	 * Public: the "How it works" setup check reads it.
	 *
	 * @since 1.7.2
	 * @return string 'ok' or 'overdue'.
	 */
	public function get_status() {
		$status = get_transient( self::TRANSIENT_STATUS );

		if ( 'ok' === $status || 'overdue' === $status ) {
			return $status;
		}

		$status = $this->compute_status();

		set_transient( self::TRANSIENT_STATUS, $status, HOUR_IN_SECONDS );

		return $status;
	}

	/**
	 * Check whether the sentinel cron event is overdue.
	 *
	 * Overdue means: next-run timestamp is in the past by more than twice the
	 * sentinel interval AND more than 15 minutes absolute. When the event is
	 * not scheduled at all we report 'ok' — the plugin's own ensure_* self-heal
	 * re-arms events, so a missing event is not a cron-stall signal.
	 *
	 * @since 1.7.2
	 * @return string 'ok' or 'overdue'.
	 */
	private function compute_status() {
		$next = wp_next_scheduled( self::SENTINEL_HOOK );

		if ( ! $next ) {
			return 'ok';
		}

		$interval = self::SENTINEL_INTERVAL;
		$schedule = wp_get_schedule( self::SENTINEL_HOOK );

		if ( $schedule ) {
			$schedules = wp_get_schedules();

			if ( isset( $schedules[ $schedule ]['interval'] ) && (int) $schedules[ $schedule ]['interval'] > 0 ) {
				$interval = (int) $schedules[ $schedule ]['interval'];
			}
		}

		$overdue_by = time() - (int) $next;

		if ( $overdue_by > ( 2 * $interval ) && $overdue_by > ( 15 * MINUTE_IN_SECONDS ) ) {
			return 'overdue';
		}

		return 'ok';
	}
}
