<?php
/**
 * Review Reminder Banner
 *
 * Shows a friendly admin review reminder after the Free plugin has been
 * installed for at least seven days.
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

	/** WordPress option storing whether the user clicked Done. */
	const OPTION_STATUS = 'opti_behavior_review_banner_status';

	/** WordPress option storing the next reminder timestamp. */
	const OPTION_REMIND_AT = 'opti_behavior_review_banner_remind_at';

	/** Admin-post action name. */
	const ACTION_NAME = 'opti_behavior_review_banner_action';

	/** Nonce action name. */
	const NONCE_ACTION = 'opti_behavior_review_banner_action';

	/** WordPress.org review URL. */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/opti-behavior/reviews/#new-post';

	/** Delay before showing the banner for the first time. */
	const INITIAL_DELAY = 604800; // 7 * DAY_IN_SECONDS.

	/** Delay used by the "Remind me later" action. */
	const REMIND_DELAY = 604800; // 7 * DAY_IN_SECONDS.

	/** Delay used by the "Hide" action. */
	const HIDE_DELAY = 604800; // 7 * DAY_IN_SECONDS.

	/** @var self|null Singleton instance. */
	private static $instance = null;

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
	 * Check whether the current admin should see the banner.
	 *
	 * @return bool
	 */
	private function should_show() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( class_exists( 'Opti_Behavior_Welcome' ) && ! Opti_Behavior_Welcome::has_consent() ) {
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

		return $installed_at > 0 && $now >= ( $installed_at + self::INITIAL_DELAY );
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
		$hide_url   = $this->get_action_url( 'hide' );
		$icon_url   = OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'images/256x256.png';
		?>
		<div class="ob-rating-banner" role="status">
			<div class="ob-rating-banner__icon" aria-hidden="true">
				<img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="44" height="44" />
			</div>
			<div class="ob-rating-banner__content">
				<h2 class="ob-rating-banner__title">
					<?php esc_html_e( 'You have been using Opti-Behavior for some time now. Thank you!', 'opti-behavior' ); ?>
					<span class="dashicons dashicons-heart ob-rating-banner__heart" aria-hidden="true"></span>
				</h2>
				<p class="ob-rating-banner__text">
					<?php esc_html_e( 'If you have a minute, can you write a ', 'opti-behavior' ); ?>
					<a href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'little review', 'opti-behavior' ); ?></a>
					<?php esc_html_e( ' for Opti-Behavior? It really helps us keep improving privacy-first heatmaps, funnels, and A/B testing.', 'opti-behavior' ); ?>
				</p>
				<p class="ob-rating-banner__text">
					<?php esc_html_e( 'Do not hesitate to share your feature requests in the review - we read them and use them to improve the plugin.', 'opti-behavior' ); ?>
				</p>
				<div class="ob-rating-banner__actions">
					<a class="button button-primary ob-rating-banner__button" href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-edit" aria-hidden="true"></span>
						<?php esc_html_e( 'Write Review', 'opti-behavior' ); ?>
					</a>
					<a class="button ob-rating-banner__button" href="<?php echo esc_url( $done_url ); ?>">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Done!', 'opti-behavior' ); ?>
					</a>
				</div>
			</div>
			<div class="ob-rating-banner__secondary">
				<a class="button ob-rating-banner__button" href="<?php echo esc_url( $remind_url ); ?>">
					<span class="dashicons dashicons-clock" aria-hidden="true"></span>
					<?php esc_html_e( 'Remind me later', 'opti-behavior' ); ?>
				</a>
				<a class="ob-rating-banner__hide" href="<?php echo esc_url( $hide_url ); ?>">
					<?php esc_html_e( 'Hide', 'opti-behavior' ); ?>
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
				update_option( self::OPTION_REMIND_AT, (string) ( time() + self::REMIND_DELAY ), false );
				break;

			case 'hide':
				update_option( self::OPTION_REMIND_AT, (string) ( time() + self::HIDE_DELAY ), false );
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

