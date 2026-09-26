<?php
/**
 * Setup Guide
 *
 * Guides the site owner after installing or updating the plugin:
 * - the "Setup check" card on the "How it works" page (visits received,
 *   own visits counted, background tasks) built only from checks that
 *   already exist (tracker heartbeat, cron health, traffic settings);
 * - a one-time "exclude your own visits" message, 48 hours after the
 *   install, while admin visits are still counted;
 * - a one-time "the menu changed" message for sites that were already
 *   installed before this version.
 *
 * Both messages are printed on the plugin's own screens only, and every
 * action goes through admin-post.php with a nonce (no JavaScript).
 *
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Setup_Guide
 */
class Opti_Behavior_Setup_Guide {

	/** Option: owner's answer to the "own visits" message ('excluded' or 'kept'). */
	const OPTION_ADMIN_VISITS = 'opti_behavior_admin_visits_choice';

	/** Option: "the menu changed" message state ('pending' or 'done'). */
	const OPTION_MENU_NOTICE = 'opti_behavior_menu_notice';

	/** Option: plugin version the guide last saw (tells a fresh install from an update). */
	const OPTION_SEEN_VERSION = 'opti_behavior_guide_seen_version';

	/** Delay before the "own visits" message: time to test the plugin first. */
	const ADMIN_VISITS_DELAY = 2 * DAY_IN_SECONDS;

	/** Background tasks are only judged after the first hour. */
	const CRON_GRACE = HOUR_IN_SECONDS;

	/** A site with no visit for this long gets a warning. */
	const QUIET_AFTER = DAY_IN_SECONDS;

	/** admin-post action + nonce action. */
	const ACTION_NAME = 'opti_behavior_setup_guide';

	/** Query arg carrying the confirmation shown after an action. */
	const DONE_ARG = 'ob_guide_done';

	/** Slug of the "How it works" page. */
	const GUIDE_SLUG = 'opti-behavior-ai-insights';

	/** @var self|null Singleton instance. */
	private static $instance = null;

	/**
	 * Initialize the singleton (admin only).
	 *
	 * @return self|null
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return null;
		}
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'record_version' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_' . self::ACTION_NAME, array( $this, 'handle_action' ) );
	}

	// ---------------------------------------------------------------------
	// State
	// ---------------------------------------------------------------------

	/**
	 * Whether the site owner's own visits are recorded (setting "Track admin users").
	 *
	 * Same default as every tracker reading it: true.
	 *
	 * @return bool
	 */
	public static function admin_visits_tracked() {
		$settings = get_option( 'opti_behavior_traffic_settings', array() );
		return isset( $settings['track_admin_users'] ) ? (bool) $settings['track_admin_users'] : true;
	}

	/**
	 * First install time (shared with the review banner, never overwritten by updates).
	 *
	 * @return int Unix timestamp, 0 when unknown.
	 */
	private static function installed_at() {
		$option = class_exists( 'Opti_Behavior_Review_Banner' ) ? Opti_Behavior_Review_Banner::OPTION_INSTALLED_AT : 'opti_behavior_review_installed_at';
		return (int) get_option( $option, 0 );
	}

	/**
	 * Whether the 48-hour test period is over.
	 *
	 * @return bool
	 */
	private static function test_period_over() {
		$installed_at = self::installed_at();
		return $installed_at > 0 && time() >= $installed_at + self::ADMIN_VISITS_DELAY;
	}

	/**
	 * Remember the running version; a site already set up before gets the
	 * "the menu changed" message once.
	 *
	 * A fresh install reaches its first admin screen before the welcome
	 * consent is given, so only an update sees consent already recorded.
	 *
	 * @return void
	 */
	public function record_version() {
		$seen = get_option( self::OPTION_SEEN_VERSION, '' );
		if ( OPTI_BEHAVIOR_HEATMAP_VERSION === $seen ) {
			return;
		}
		if ( '' === $seen && class_exists( 'Opti_Behavior_Welcome' ) && Opti_Behavior_Welcome::has_consent() ) {
			add_option( self::OPTION_MENU_NOTICE, 'pending', '', false );
		}
		update_option( self::OPTION_SEEN_VERSION, OPTI_BEHAVIOR_HEATMAP_VERSION, false );
	}

	// ---------------------------------------------------------------------
	// Notices
	// ---------------------------------------------------------------------

	/**
	 * Current plugin screen slug, or '' outside the plugin's screens.
	 *
	 * @return string
	 */
	private function plugin_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'opti-behavior' ) ) {
			return '';
		}
		$skip = array( 'opti-behavior-welcome', 'opti-behavior-install-trial' );
		if ( class_exists( 'Opti_Behavior_Welcome' ) && defined( 'Opti_Behavior_Welcome::WELCOME_SLUG' ) ) {
			$skip[] = Opti_Behavior_Welcome::WELCOME_SLUG;
		}
		return in_array( $page, $skip, true ) ? '' : $page;
	}

	/**
	 * Whether the "own visits" message is due.
	 *
	 * @return bool
	 */
	public static function admin_visits_notice_due() {
		return self::admin_visits_tracked()
			&& '' === (string) get_option( self::OPTION_ADMIN_VISITS, '' )
			&& self::test_period_over();
	}

	/**
	 * Print the confirmation, then at most one guide message, on plugin screens.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = $this->plugin_screen();
		if ( '' === $screen ) {
			return;
		}
		if ( class_exists( 'Opti_Behavior_Welcome' ) && ! Opti_Behavior_Welcome::has_consent() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only confirmation after a nonce-checked action.
		$done = isset( $_GET[ self::DONE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::DONE_ARG ] ) ) : '';
		if ( 'excluded' === $done ) {
			$this->print_confirmation( __( 'Done. Your own visits are no longer counted.', 'opti-behavior' ) );
		} elseif ( 'kept' === $done ) {
			$this->print_confirmation( __( 'OK, we will not ask again. You can change it in Settings › Admin Tracking.', 'opti-behavior' ) );
		}

		// Opening the guide is the answer to "the menu changed".
		if ( 'pending' === get_option( self::OPTION_MENU_NOTICE, '' ) ) {
			if ( self::GUIDE_SLUG === $screen ) {
				update_option( self::OPTION_MENU_NOTICE, 'done', false );
			} else {
				$this->print_menu_notice();
				return;
			}
		}

		if ( '' === $done && self::admin_visits_notice_due() ) {
			$this->print_admin_visits_notice();
		}
	}

	/**
	 * Nonce-protected admin-post URL for an action.
	 *
	 * @param string $do Action key.
	 * @return string
	 */
	public static function action_url( $do ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_NAME,
					'do'     => sanitize_key( $do ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_NAME
		);
	}

	/**
	 * Shared notice shell.
	 *
	 * @param string $icon    Emoji shown in the badge.
	 * @param string $title   Bold first line.
	 * @param string $text    Second line.
	 * @param array  $buttons List of array( url, label, primary ).
	 * @return void
	 */
	private function print_notice( $icon, $title, $text, $buttons ) {
		?>
		<?php // opti-behavior-notice: the plugin screens hide every other notice (admin-notices.css + notice-cleanup.js). ?>
		<div class="notice opti-behavior-notice ob-guide-notice" role="status">
			<span class="ob-guide-notice__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
			<div class="ob-guide-notice__text">
				<b><?php echo esc_html( $title ); ?></b>
				<span><?php echo esc_html( $text ); ?></span>
			</div>
			<div class="ob-guide-notice__actions">
				<?php foreach ( $buttons as $button ) : ?>
					<a class="ob-guide-btn<?php echo $button[2] ? ' is-primary' : ''; ?>" href="<?php echo esc_url( $button[0] ); ?>"><?php echo esc_html( $button[1] ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		$this->print_styles();
	}

	/**
	 * "Your own visits are counted" message.
	 *
	 * @return void
	 */
	private function print_admin_visits_notice() {
		$this->print_notice(
			'👤',
			__( 'Your own visits are counted in your stats.', 'opti-behavior' ),
			__( 'That was useful while you tested Opti-Behavior. Exclude them now so your numbers show real visitors only.', 'opti-behavior' ),
			array(
				array( self::action_url( 'exclude' ), __( 'Exclude my visits', 'opti-behavior' ), true ),
				array( self::action_url( 'keep' ), __( 'Keep counting them', 'opti-behavior' ), false ),
			)
		);
	}

	/**
	 * "The menu changed" message (sites updated from an older version).
	 *
	 * @return void
	 */
	private function print_menu_notice() {
		$this->print_notice(
			'🧭',
			__( 'Opti-Behavior was updated: the menu has a new shape.', 'opti-behavior' ),
			__( '“Dashboard” is now Traffic, under “Your data”. Problems to fix are in Smart Insights, under “What to fix”. A 2-minute guide shows it all.', 'opti-behavior' ),
			array(
				array( self::action_url( 'guide' ), __( 'Open the guide', 'opti-behavior' ), true ),
				array( self::action_url( 'menu_done' ), __( 'Dismiss', 'opti-behavior' ), false ),
			)
		);
	}

	/**
	 * One-line success notice.
	 *
	 * @param string $message Text.
	 * @return void
	 */
	private function print_confirmation( $message ) {
		printf( '<div class="notice notice-success opti-behavior-notice"><p style="margin:0">%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Inline styles, printed once with the first message.
	 *
	 * @return void
	 */
	private function print_styles() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
			/* Beats the `display:block !important` of the admin-notices.css exception. */
			#wpbody-content div.notice.opti-behavior-notice.ob-guide-notice{display:flex !important;align-items:center;gap:12px;flex-wrap:wrap;border-left:4px solid #7c3aed;padding:10px 14px !important;margin:12px 0 !important}
			.ob-guide-notice__icon{width:34px;height:34px;border-radius:10px;background:#f5f3ff;display:grid;place-items:center;font-size:17px;flex:none}
			.ob-guide-notice__text{flex:1;min-width:240px}
			.ob-guide-notice__text b{display:block;font-size:14px;color:#1d2327}
			.ob-guide-notice__text span{color:#50575e;font-size:13px}
			.ob-guide-notice__actions{display:flex;gap:8px;flex-wrap:wrap}
			.ob-guide-btn,.ob-guide-btn:visited{display:inline-flex;align-items:center;min-height:36px;padding:0 14px;border-radius:8px;font-weight:600;font-size:13px;text-decoration:none;background:#fff;color:#4c1d95;border:1px solid #ddd6fe}
			.ob-guide-btn.is-primary,.ob-guide-btn.is-primary:visited{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;border-color:transparent}
			.ob-guide-btn:hover,.ob-guide-btn:focus{filter:brightness(1.05);color:inherit}
			.ob-guide-btn.is-primary:hover,.ob-guide-btn.is-primary:focus{color:#fff}
		</style>
		<?php
	}

	/**
	 * Handle a message / setup-check button.
	 *
	 * @return void
	 */
	public function handle_action() {
		check_admin_referer( self::ACTION_NAME );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'opti-behavior' ),
				esc_html__( 'Unauthorized', 'opti-behavior' ),
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$do       = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=' . self::GUIDE_SLUG );
		}
		$redirect = remove_query_arg( self::DONE_ARG, $redirect );

		switch ( $do ) {
			case 'exclude':
				// Same merge as the Settings › Traffic "Admin Tracking" form.
				$settings = get_option( 'opti_behavior_traffic_settings', array() );
				if ( ! is_array( $settings ) ) {
					$settings = array();
				}
				$settings['track_admin_users'] = false;
				update_option( 'opti_behavior_traffic_settings', $settings );
				update_option( self::OPTION_ADMIN_VISITS, 'excluded', false );
				$redirect = add_query_arg( self::DONE_ARG, 'excluded', $redirect );
				break;

			case 'keep':
				update_option( self::OPTION_ADMIN_VISITS, 'kept', false );
				$redirect = add_query_arg( self::DONE_ARG, 'kept', $redirect );
				break;

			case 'guide':
				update_option( self::OPTION_MENU_NOTICE, 'done', false );
				$redirect = admin_url( 'admin.php?page=' . self::GUIDE_SLUG );
				break;

			case 'menu_done':
				update_option( self::OPTION_MENU_NOTICE, 'done', false );
				break;
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	// ---------------------------------------------------------------------
	// Setup check card ("How it works" page)
	// ---------------------------------------------------------------------

	/**
	 * Last page view time (site-local MySQL datetime), '' when none.
	 *
	 * @return string
	 */
	private static function last_visit() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Hard-coded plugin table name, no input; one MAX() on an indexed column, admin screen only.
		$last = $wpdb->get_var( "SELECT MAX(view_time) FROM {$wpdb->prefix}optibehavior_pageviews" );
		return $last ? (string) $last : '';
	}

	/**
	 * The three checks.
	 *
	 * Each row: state (ok / info / warn / bad), title, text, link url, link label.
	 *
	 * @return array[]
	 */
	public static function get_checks() {
		$now_local = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Compared with site-local `view_time` values.
		$rows      = array();

		// 1. Visits received.
		$last       = self::last_visit();
		$mismatches = class_exists( 'Opti_Behavior_Tracker_Heartbeat' ) ? Opti_Behavior_Tracker_Heartbeat::instance()->get_cached_mismatches() : array();
		$doc_url    = class_exists( 'Opti_Behavior_Tracker_Heartbeat' ) ? Opti_Behavior_Tracker_Heartbeat::DOC_URL : 'https://optiuser.com/support/';
		if ( '' === $last ) {
			$rows['tracker'] = array( 'info', __( 'Waiting for your first visit', 'opti-behavior' ), __( 'Open your site in a private window: your visit shows up here within a minute.', 'opti-behavior' ), home_url( '/' ), __( 'Open your site', 'opti-behavior' ) );
		} elseif ( ! empty( $mismatches ) ) {
			$culprits        = class_exists( 'Opti_Behavior_Tracker_Heartbeat' ) ? Opti_Behavior_Tracker_Heartbeat::instance()->detect_culprit_plugins() : array();
			$rows['tracker'] = array(
				'bad',
				__( 'Some trackers send nothing', 'opti-behavior' ),
				empty( $culprits )
					/* translators: %s: comma-separated tracker names, e.g. "Funnel Tracking, A/B Testing". */
					? sprintf( __( '%s: no data for 24 hours while your site had visits. A caching or optimization plugin may block them.', 'opti-behavior' ), implode( ', ', $mismatches ) )
					/* translators: 1: comma-separated tracker names, 2: comma-separated plugin names, e.g. "WP Rocket". */
					: sprintf( __( '%1$s: no data for 24 hours while your site had visits. %2$s may block them.', 'opti-behavior' ), implode( ', ', $mismatches ), implode( ', ', $culprits ) ),
				$doc_url,
				__( 'How to fix it', 'opti-behavior' ),
			);
		} else {
			$last_ts = strtotime( $last );
			$ago     = human_time_diff( min( $last_ts, $now_local ), $now_local );
			if ( $now_local - $last_ts > self::QUIET_AFTER ) {
				$rows['tracker'] = array(
					'warn',
					__( 'No visit in the last 24 hours', 'opti-behavior' ),
					/* translators: %s: time since the last visit, e.g. "3 days". */
					sprintf( __( 'Last visit %s ago. If your site had visitors, a caching plugin may block the tracker.', 'opti-behavior' ), $ago ),
					$doc_url,
					__( 'How to fix it', 'opti-behavior' ),
				);
			} else {
				$rows['tracker'] = array(
					'ok',
					__( 'Visits are received', 'opti-behavior' ),
					/* translators: %s: time since the last visit, e.g. "3 mins". */
					sprintf( __( 'Last visit %s ago.', 'opti-behavior' ), $ago ),
					admin_url( 'admin.php?page=opti-behavior-analytics' ),
					__( 'See your traffic', 'opti-behavior' ),
				);
			}
		}

		// 2. Own visits.
		if ( ! self::admin_visits_tracked() ) {
			$rows['admin'] = array( 'ok', __( 'Your own visits are excluded', 'opti-behavior' ), __( 'Your numbers show real visitors only.', 'opti-behavior' ), '', '' );
		} elseif ( 'kept' === get_option( self::OPTION_ADMIN_VISITS, '' ) ) {
			$rows['admin'] = array( 'info', __( 'Your own visits are counted', 'opti-behavior' ), __( 'You chose to keep them.', 'opti-behavior' ), self::action_url( 'exclude' ), __( 'Exclude them now', 'opti-behavior' ) );
		} elseif ( ! self::test_period_over() ) {
			$rows['admin'] = array( 'info', __( 'Your own visits are counted', 'opti-behavior' ), __( 'Good for testing. We will remind you in 2 days.', 'opti-behavior' ), self::action_url( 'exclude' ), __( 'Exclude them now', 'opti-behavior' ) );
		} else {
			$rows['admin'] = array( 'warn', __( 'Your own visits are counted', 'opti-behavior' ), __( 'They make your numbers look better or worse than they are.', 'opti-behavior' ), self::action_url( 'exclude' ), __( 'Exclude my visits', 'opti-behavior' ) );
		}

		// 3. Background tasks.
		$cron = class_exists( 'Opti_Behavior_Cron_Health' ) ? Opti_Behavior_Cron_Health::init() : null;
		if ( self::installed_at() > time() - self::CRON_GRACE ) {
			$rows['cron'] = array( 'info', __( 'Background tasks', 'opti-behavior' ), __( 'Checked after the first hour.', 'opti-behavior' ), '', '' );
		} elseif ( $cron && 'overdue' === $cron->get_status() ) {
			$rows['cron'] = array(
				'bad',
				__( 'Background tasks are late', 'opti-behavior' ),
				( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
					? __( 'WP-Cron is turned off in wp-config.php and no system cron runs it: Smart Insights, reports and cleanup wait.', 'opti-behavior' )
					: __( 'WP-Cron is not running on this site: Smart Insights, reports and cleanup wait.', 'opti-behavior' ),
				$doc_url,
				__( 'How to fix it', 'opti-behavior' ),
			);
		} else {
			$rows['cron'] = array( 'ok', __( 'Background tasks run on time', 'opti-behavior' ), __( 'Smart Insights and reports are up to date.', 'opti-behavior' ), '', '' );
		}

		return $rows;
	}

	/**
	 * Print the "Setup check" card. One line when everything is OK.
	 *
	 * @return void
	 */
	public static function render_card() {
		$rows  = self::get_checks();
		$ok    = count( wp_list_filter( $rows, array( 0 => 'ok' ) ) );
		$total = count( $rows );
		$marks = array(
			'ok'   => '✓',
			'info' => 'i',
			'warn' => '!',
			'bad'  => '!',
		);

		if ( $ok === $total ) :
			?>
			<section class="ob-hiw-card ob-hiw-setup is-all-ok" aria-label="<?php esc_attr_e( 'Setup check', 'opti-behavior' ); ?>">
				<span class="ob-hiw-mk" aria-hidden="true">✓</span>
				<span><?php esc_html_e( 'Setup check: visits are received, your own visits are excluded, background tasks run on time.', 'opti-behavior' ); ?></span>
			</section>
			<?php
			return;
		endif;
		?>
		<section class="ob-hiw-card ob-hiw-setup" aria-labelledby="ob-hiw-setup-title">
			<div class="ob-hiw-setup-head">
				<span class="ob-hiw-kicker"><?php esc_html_e( 'Setup check', 'opti-behavior' ); ?></span>
				<h2 class="ob-hiw-title" id="ob-hiw-setup-title"><?php esc_html_e( 'Is everything working?', 'opti-behavior' ); ?></h2>
				<b class="ob-hiw-setup-sum">
					<?php
					/* translators: 1: checks that pass, 2: total checks. */
					echo esc_html( sprintf( __( '%1$d of %2$d OK', 'opti-behavior' ), $ok, $total ) );
					?>
				</b>
			</div>
			<ul class="ob-hiw-setup-list">
				<?php foreach ( $rows as $key => $row ) : ?>
					<li class="is-<?php echo esc_attr( $row[0] ); ?>" data-check="<?php echo esc_attr( $key ); ?>">
						<span class="ob-hiw-mk" aria-hidden="true"><?php echo esc_html( $marks[ $row[0] ] ); ?></span>
						<span>
							<b><?php echo esc_html( $row[1] ); ?></b>
							<small><?php echo esc_html( $row[2] ); ?></small>
							<?php if ( '' !== $row[3] ) : ?>
								<?php $external = 0 !== strpos( $row[3], admin_url() ); // The site itself and the docs open in a new tab. ?>
								<a class="ob-hiw-go" href="<?php echo esc_url( $row[3] ); ?>"<?php echo $external ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $row[4] ); ?> →</a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}
}
