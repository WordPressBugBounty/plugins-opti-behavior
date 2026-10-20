<?php
/**
 * Review Reminder Banner
 *
 * Shows a friendly admin review reminder after the "first useful moment":
 * an administrator has opened a heatmap with clicks or received a funnel
 * report with entries. The banner is printed from the next Opti-Behavior
 * screen on, never on the page view of the moment itself, and never during
 * the first 48 hours after installation.
 *
 * @package opti-behavior
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Review_Banner
 *
 * Handles display and actions for the delayed WordPress.org review banner.
 *
 * @since 1.3.0
 */
class Opti_Behavior_Review_Banner {

	/** WordPress option storing the first install timestamp. */
	const OPTION_INSTALLED_AT = 'opti_behavior_review_installed_at';

	/** WordPress option storing 'done' once the user asked not to be asked again. */
	const OPTION_STATUS = 'opti_behavior_review_banner_status';

	/** WordPress option storing when an administrator first saw the plugin work (written once). */
	const OPTION_VALUE_SEEN_AT = 'opti_behavior_review_value_seen_at';

	/**
	 * WordPress option set to '1' by an earlier version once a heatmap with
	 * clicks existed. Read only: it counts as "moment seen" on updated sites.
	 */
	const OPTION_HAS_CLICKS = 'opti_behavior_review_has_clicks';

	/** WordPress option storing the next reminder timestamp. */
	const OPTION_REMIND_AT = 'opti_behavior_review_banner_remind_at';

	/** Admin-post action name. */
	const ACTION_NAME = 'opti_behavior_review_banner_action';

	/** Nonce action name. */
	const NONCE_ACTION = 'opti_behavior_review_banner_action';

	/** WordPress.org review URL. */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/opti-behavior/reviews/#new-post';

	/** Minimum age of the install before the banner may show. */
	const MIN_INSTALL_AGE = 172800; // 2 * DAY_IN_SECONDS.

	/** Delay used by the "Maybe later" action (and by the 'hide' action of old URLs). */
	const REMIND_DELAY = 2592000; // 30 * DAY_IN_SECONDS.

	/** @var self|null Singleton instance. */
	private static $instance = null;

	/** @var bool True when the "first useful moment" was recorded during this request. */
	private static $marked_in_request = false;

	/**
	 * Initialize the singleton.
	 *
	 * @return self
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Store the initial install timestamp on first activation.
	 *
	 * Uses add_option() so updates or re-activations never overwrite the
	 * original first-install timestamp.
	 *
	 * @return void
	 */
	public static function on_activation() {
		add_option( self::OPTION_INSTALLED_AT, (string) time(), '', false );
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->maybe_seed_installed_at();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION_NAME, array( $this, 'handle_action' ) );
	}

	/**
	 * Seed install timestamp for existing installs that predate this feature.
	 *
	 * @return void
	 */
	private function maybe_seed_installed_at() {
		$installed_at = (int) get_option( self::OPTION_INSTALLED_AT, 0 );

		if ( 0 >= $installed_at ) {
			add_option( self::OPTION_INSTALLED_AT, (string) time(), '', false );
		}
	}

	/**
	 * Whether the current admin screen is one of the plugin's own screens.
	 *
	 * The banner is never printed on WordPress screens (Dashboard, Plugins,
	 * Posts...) nor on the welcome / trial install steps.
	 *
	 * @return bool
	 */
	public static function is_plugin_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'opti-behavior' ) ) {
			return false;
		}

		$skip = array( 'opti-behavior-welcome', 'opti-behavior-install-trial' );
		if ( class_exists( 'Opti_Behavior_Welcome' ) && defined( 'Opti_Behavior_Welcome::WELCOME_SLUG' ) ) {
			$skip[] = Opti_Behavior_Welcome::WELCOME_SLUG;
		}

		return ! in_array( $page, $skip, true );
	}

	/**
	 * Check whether the current admin should see the banner.
	 *
	 * @return bool
	 */
	private function should_show() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return false;
		}

		if ( ! self::is_plugin_screen() ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( class_exists( 'Opti_Behavior_Welcome' ) && ! Opti_Behavior_Welcome::has_made_choice() ) {
			return false;
		}

		if ( 'done' === get_option( self::OPTION_STATUS, '' ) ) {
			return false;
		}

		$now          = time();
		$installed_at = (int) get_option( self::OPTION_INSTALLED_AT, 0 );
		$remind_at    = (int) get_option( self::OPTION_REMIND_AT, 0 );

		if ( 0 < $remind_at && $now < $remind_at ) {
			return false;
		}

		// Never on the day of the installation.
		if ( 0 >= $installed_at || $now < ( $installed_at + self::MIN_INSTALL_AGE ) ) {
			return false;
		}

		// Ask only once the admin has seen the plugin work, and not on the
		// page view of that moment: from the next Opti-Behavior screen on.
		if ( self::$marked_in_request || ! self::value_seen() ) {
			return false;
		}

		// One Opti-Behavior banner at a time. The "country database" notice is
		// checked here, not in other_banner_visible(): that notice calls it.
		if ( class_exists( 'Opti_Behavior_Geo_Country' ) && Opti_Behavior_Geo_Country::notice_wanted() ) {
			return false;
		}

		return ! self::other_banner_visible();
	}

	/**
	 * Whether an administrator has already seen the plugin work.
	 *
	 * True once mark_value_seen() stored its timestamp, or when an earlier
	 * version stored "this site has a heatmap with clicks" (updated sites).
	 * Option reads only.
	 *
	 * @return bool
	 */
	public static function value_seen() {
		if ( 0 < (int) get_option( self::OPTION_VALUE_SEEN_AT, 0 ) ) {
			return true;
		}

		return '1' === (string) get_option( self::OPTION_HAS_CLICKS, '' );
	}

	/**
	 * Record the "first useful moment": an administrator has just seen their
	 * own data (a heatmap with clicks, a funnel report with entries).
	 *
	 * Written once with add_option(), never overwritten. The banner stays
	 * hidden for the rest of this request and may show on the next
	 * Opti-Behavior screen.
	 *
	 * @return void
	 */
	public static function mark_value_seen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 0 < (int) get_option( self::OPTION_VALUE_SEEN_AT, 0 ) ) {
			return;
		}

		self::$marked_in_request = true;
		add_option( self::OPTION_VALUE_SEEN_AT, (string) time(), '', false );
	}

	/**
	 * Record the moment when the heatmap opened by an administrator has clicks.
	 *
	 * One light query on the heatmap registry, and none at all once the
	 * moment is stored.
	 *
	 * @param int[] $page_ids Page IDs of the displayed heatmap.
	 * @return void
	 */
	public static function mark_value_seen_for_heatmap( $page_ids ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 0 < (int) get_option( self::OPTION_VALUE_SEEN_AT, 0 ) ) {
			return;
		}

		$page_ids = array_values( array_filter( array_map( 'absint', (array) $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return;
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$in       = implode( ',', $page_ids );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, IDs cast with absint(); runs until the moment is stored, then never again.
		$found = $wpdb->get_var( "SELECT 1 FROM `{$table}` WHERE page_id IN ({$in}) AND click_count > 0 LIMIT 1" );
		$wpdb->suppress_errors( $suppress );

		if ( $found ) {
			self::mark_value_seen();
		}
	}

	/**
	 * Whether another Opti-Behavior banner is shown on the current screen:
	 * Pro trial notice, data-sharing (welcome) notice, setup guide message or
	 * onboarding popup. Mirrors the conditions of those classes.
	 *
	 * @return bool
	 */
	public static function other_banner_visible() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page       = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$guide_done = class_exists( 'Opti_Behavior_Setup_Guide' ) && isset( $_GET[ Opti_Behavior_Setup_Guide::DONE_ARG ] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Pro trial notice: Traffic, Settings, How it works.
		if ( in_array( $page, array( 'opti-behavior-analytics', 'opti-behavior-settings', 'opti-behavior-ai-insights' ), true ) && class_exists( 'Opti_Behavior_Trial_Invite' ) ) {
			$state = Opti_Behavior_Trial_Invite::state();
			if ( isset( $state['display'] ) && 'invite' === $state['display'] ) {
				return true;
			}
		}

		// Data-sharing text changed (welcome): every plugin screen but Settings.
		if ( 'opti-behavior-settings' !== $page && class_exists( 'Opti_Behavior_Welcome' )
			&& Opti_Behavior_Welcome::needs_consent_update()
			&& ! Opti_Behavior_Welcome::pro_handles_status()
			&& Opti_Behavior_Welcome::may_prompt_for_consent() ) {
			return true;
		}

		// Setup guide: confirmation, "the menu changed", "your own visits are counted".
		if ( class_exists( 'Opti_Behavior_Setup_Guide' ) ) {
			if ( $guide_done ) {
				return true;
			}
			if ( Opti_Behavior_Setup_Guide::GUIDE_SLUG !== $page && 'pending' === get_option( Opti_Behavior_Setup_Guide::OPTION_MENU_NOTICE, '' ) ) {
				return true;
			}
			if ( Opti_Behavior_Setup_Guide::admin_visits_notice_due() ) {
				return true;
			}
		}

		// Onboarding popup (Traffic screen of a fresh install).
		if ( 'opti-behavior-analytics' === $page && class_exists( 'Opti_Behavior_Onboarding' ) && Opti_Behavior_Onboarding::init()->should_show() ) {
			return true;
		}

		return false;
	}

	/**
	 * Enqueue banner styles only when the banner is visible.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->should_show() ) {
			return;
		}

		$css_path    = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/review-banner.css';
		$css_version = file_exists( $css_path ) ? (string) filemtime( $css_path ) : OPTI_BEHAVIOR_HEATMAP_VERSION;

		wp_enqueue_style(
			'opti-behavior-review-banner',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/review-banner.css',
			array(),
			$css_version
		);
	}

	/**
	 * Render the admin review banner.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->should_show() ) {
			return;
		}

		$review_url = $this->get_action_url( 'review' );
		$done_url   = $this->get_action_url( 'done' );
		$remind_url = $this->get_action_url( 'remind' );
		$icon_url   = OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'images/256x256.png';
		?>
		<div class="ob-rating-banner" role="status">
			<div class="ob-rating-banner__icon" aria-hidden="true">
				<img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="44" height="44" />
			</div>
			<div class="ob-rating-banner__content">
				<h2 class="ob-rating-banner__title">
					<?php esc_html_e( 'Is Opti-Behavior useful on your site?', 'opti-behavior' ); ?>
				</h2>
				<p class="ob-rating-banner__text">
					<?php esc_html_e( 'A short review on WordPress.org helps other site owners find it. Honest feedback helps us too.', 'opti-behavior' ); ?>
				</p>
				<p class="ob-rating-banner__text">
					<?php esc_html_e( '— The Opti-Behavior Team', 'opti-behavior' ); ?>
				</p>
				<div class="ob-rating-banner__actions">
					<a class="button button-primary ob-rating-banner__button" href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-edit" aria-hidden="true"></span>
						<?php esc_html_e( 'Leave a review', 'opti-behavior' ); ?>
					</a>
					<a class="button ob-rating-banner__button" href="<?php echo esc_url( $remind_url ); ?>">
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<?php esc_html_e( 'Maybe later', 'opti-behavior' ); ?>
					</a>
				</div>
			</div>
			<div class="ob-rating-banner__secondary">
				<a class="ob-rating-banner__hide" href="<?php echo esc_url( $done_url ); ?>">
					<?php esc_html_e( "Don't ask again", 'opti-behavior' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle banner actions.
	 *
	 * @return void
	 */
	public function handle_action() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'opti-behavior' ),
				esc_html__( 'Unauthorized', 'opti-behavior' ),
				array( 'response' => 403 )
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$banner_action = isset( $_GET['banner_action'] ) ? sanitize_key( wp_unslash( $_GET['banner_action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		switch ( $banner_action ) {
			case 'review':
				$this->redirect_to_review_url();
				break;

			case 'done':
				update_option( self::OPTION_STATUS, 'done', false );
				delete_option( self::OPTION_REMIND_AT );
				break;

			case 'remind':
			case 'hide': // Old "Hide" link (page still open, bookmark): same as "Maybe later".
				update_option( self::OPTION_REMIND_AT, (string) ( time() + self::REMIND_DELAY ), false );
				break;
		}

		$redirect_url = wp_get_referer();
		if ( ! $redirect_url ) {
			$redirect_url = admin_url();
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Build a nonce-protected action URL.
	 *
	 * @param string $banner_action Action key.
	 * @return string
	 */
	private function get_action_url( $banner_action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => self::ACTION_NAME,
					'banner_action' => sanitize_key( $banner_action ),
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
		);
	}

	/**
	 * Redirect safely to the WordPress.org review page.
	 *
	 * @return void
	 */
	private function redirect_to_review_url() {
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_review_redirect_host' ) );
		wp_safe_redirect( self::REVIEW_URL );
		remove_filter( 'allowed_redirect_hosts', array( $this, 'allow_review_redirect_host' ) );
		exit;
	}

	/**
	 * Allow the WordPress.org review host for the review action redirect.
	 *
	 * @param array $hosts Allowed redirect hosts.
	 * @return array
	 */
	public function allow_review_redirect_host( $hosts ) {
		$hosts[] = 'wordpress.org';

		return array_unique( $hosts );
	}

}

