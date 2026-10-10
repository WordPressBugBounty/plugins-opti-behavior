<?php
/**
 * Welcome & Consent Page
 *
 * Displays a full-screen professional welcome/onboarding page on first activation.
 * All plugin admin pages are blocked until the admin clicks "Accept & Continue".
 * After acceptance, the consent flag is stored and the tracker may send data.
 *
 * @package opti-behavior
 * @since 1.2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Welcome
 *
 * Manages the first-run consent flow for Opti-Behavior.
 *
 * @since 1.2.4
 */
class Opti_Behavior_Welcome {

	/** WordPress option that stores whether consent was accepted. */
	const OPTION_CONSENT  = 'opti_behavior_consent_accepted';

	/** WordPress option storing the admin's data-sharing choice: 'accepted' or 'declined'. */
	const OPTION_CHOICE = 'opti_behavior_consent_choice';

	/** WordPress option storing the version of the data-sharing text the admin accepted. */
	const OPTION_CONSENT_VERSION = 'opti_behavior_consent_version';

	/** Current version of the data-sharing text (2 = itemised list + has_data_7d). */
	const CONSENT_VERSION = 2;

	/** Cron hook of the daily status message (Opti_Behavior_Free_Tracker). */
	const HEARTBEAT_HOOK = 'opti_behavior_daily_heartbeat';

	/** Transient/option used to trigger the post-activation redirect. */
	const OPTION_REDIRECT = 'opti_behavior_activation_redirect';

	/** Unix time of the first Free activation (written once; absent = installed before this rule). */
	const OPTION_FIRST_ACTIVATED = 'opti_behavior_first_activated_at';

	/** Wait after the first activation before asking about data sharing (0 = welcome screen right away, OPT-697). */
	const CONSENT_PROMPT_DELAY = 0;

	/** Slug for the hidden welcome admin page. */
	const WELCOME_SLUG = 'opti-behavior-welcome';

	/**
	 * Trial licence key of a trial started from the welcome screen before OPT-765.
	 * Read only (hides the trial card); Free no longer writes it: the trial starts
	 * on optiuser.com.
	 */
	const OPTION_TRIAL_LICENSE_KEY = 'opti_behavior_trial_license_key';

	/** Option set to 'declined' by "Continue with Free only": the welcome trial card is not shown again. */
	const OPTION_TRIAL_CHOICE = 'opti_behavior_trial_choice';

	/**
	 * Plugin page slugs that require consent before access.
	 *
	 * @var string[]
	 */
	private static $protected_pages = array(
		'opti-behavior-analytics',
		'opti-behavior-heatmaps',
		'opti-behavior-recordings',
		'opti-behavior-settings',
		'opti-behavior-funnels',
		'opti-behavior-ab-testing',
		'opti-behavior-ab-visual-editor',
		'opti-behavior-ai-insights',
		'opti-behavior-smart-insights',
		'opti-behavior-heatmap-detail',
		'opti-behavior-user-journey',
		'opti-behavior-errors',
		'opti-behavior-form-analytics',
	);

	/** Singleton instance. @var self|null */
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

	/** Private constructor — use init(). */
	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_welcome_page' ), 5 );
		add_action( 'admin_init',            array( $this, 'maybe_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_welcome_styles' ) );
		add_action( 'admin_post_opti_behavior_accept_consent', array( $this, 'handle_accept' ) );
		add_action( 'admin_post_opti_behavior_decline_consent', array( $this, 'handle_decline' ) );
		add_action( 'admin_post_opti_behavior_share_status', array( $this, 'handle_share_status_setting' ) );
		add_action( 'admin_post_opti_behavior_welcome_skip_trial', array( $this, 'handle_skip_trial' ) );
		add_action( 'admin_notices',         array( $this, 'render_trial_failed_notice' ) );
		add_action( 'admin_notices',         array( $this, 'render_consent_update_notice' ) );
		// Fix PHP 8.2 deprecation: pages registered with parent_slug=null are hidden pages that
		// WordPress's get_admin_page_title() cannot find, leaving $title as null.
		// admin-header.php then calls strip_tags(null) → deprecation warning.
		// We hook into 'current_screen' (fires before admin-header.php) to set $title manually.
		add_action( 'current_screen', array( $this, 'maybe_set_hidden_page_title' ) );
	}

	/**
	 * Set the global $title for hidden admin pages (those registered with parent_slug = null).
	 *
	 * WordPress's get_admin_page_title() only scans $menu and $submenu[parent] — it cannot
	 * resolve hidden pages, leaving the global $title as null. On PHP 8.2 the subsequent
	 * strip_tags($title) in admin-header.php produces a deprecation warning. We set $title
	 * here so WordPress finds it correctly.
	 *
	 * @return void
	 */
	public function maybe_set_hidden_page_title() {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional: setting $title for hidden admin pages that WP cannot resolve automatically.
		global $title;

		if ( ! empty( $title ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: GET param used for page identification only.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( self::WELCOME_SLUG === $current_page ) {
			$title = __( 'Welcome to Opti-Behavior', 'opti-behavior' );
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Return true if the admin has already accepted the terms.
	 *
	 * @return bool
	 */
	public static function has_consent() {
		return (bool) get_option( self::OPTION_CONSENT, false );
	}

	/**
	 * Return true once the admin answered the welcome screen: accepted OR declined.
	 * Sites that accepted before the choice option existed count as accepted.
	 *
	 * @return bool
	 */
	public static function has_made_choice() {
		if ( self::has_consent() ) {
			return true;
		}
		return in_array( get_option( self::OPTION_CHOICE, '' ), array( 'accepted', 'declined' ), true );
	}

	/**
	 * Version of the data-sharing text the admin accepted (0 = none, 1 = accepted before v2).
	 *
	 * @return int
	 */
	public static function get_consent_version() {
		if ( ! self::has_consent() ) {
			return 0;
		}
		return max( 1, (int) get_option( self::OPTION_CONSENT_VERSION, 1 ) );
	}

	/**
	 * True when the admin accepted an older data-sharing text and has not answered the current one.
	 *
	 * @return bool
	 */
	public static function needs_consent_update() {
		return self::has_consent() && self::get_consent_version() < self::CONSENT_VERSION;
	}

	/**
	 * True when a registered Pro sends its own daily site status (OPT-693): the Free
	 * "Share installation status" card, its save handler and the update notice step aside.
	 *
	 * @return bool
	 */
	public static function pro_handles_status() {
		return class_exists( 'Opti_Behavior_Free_Tracker' ) && Opti_Behavior_Free_Tracker::pro_handles_status();
	}

	/**
	 * True when Opti-Behavior Pro runs with a licence or a trial (gate bucket "pro"), or when the
	 * Pro plugin is active at all, even before it checked its licence (OPT-697): Pro then talks to
	 * the API under the contract (GDPR Art. 6(1)(b)), so no data-sharing prompt.
	 * Pro absent = Free behaviour.
	 *
	 * @return bool
	 */
	public static function is_pro_licensed() {
		// Pro plugin loaded (constant defined at the top of opti-behavior-pro.php), licence checked or not.
		$licensed = defined( 'OPTI_BEHAVIOR_PRO_VERSION' );
		if ( ! $licensed && class_exists( 'Opti_Behavior_Gate' ) && method_exists( 'Opti_Behavior_Gate', 'pro_active' ) ) {
			try {
				$licensed = (bool) Opti_Behavior_Gate::pro_active();
			} catch ( \Throwable $e ) {
				$licensed = false;
			}
		}
		/**
		 * Filters whether the data-sharing prompts treat this site as running a licensed Pro.
		 *
		 * @param bool $licensed Pro plugin active, or gate bucket "pro" (licence, trial or grace period).
		 */
		return (bool) apply_filters( 'opti_behavior_consent_pro_licensed', $licensed );
	}

	/**
	 * True once the data-sharing question may be asked: at least CONSENT_PROMPT_DELAY after
	 * the first activation (default 0 since OPT-697: right away; filter kept for sites that
	 * want a wait). Sites installed before this rule (no stored time) are past the delay.
	 *
	 * @return bool
	 */
	public static function consent_prompt_due() {
		$activated_at = (int) get_option( self::OPTION_FIRST_ACTIVATED, 0 );
		if ( $activated_at <= 0 ) {
			return true;
		}
		$delay = (int) apply_filters( 'opti_behavior_consent_prompt_delay', self::CONSENT_PROMPT_DELAY );
		return time() >= $activated_at + max( 0, $delay );
	}

	/**
	 * Whether the data-sharing question (welcome screen or update notice) may be shown now:
	 * Free alone (no licensed Pro) and the post-activation delay is over.
	 *
	 * @return bool
	 */
	public static function may_prompt_for_consent() {
		return ! self::is_pro_licensed() && self::consent_prompt_due();
	}

	/**
	 * Record "share the installation status": consent flag, choice, text version, daily cron.
	 *
	 * @return void
	 */
	public static function accept_sharing() {
		update_option( self::OPTION_CONSENT, '1' );
		update_option( self::OPTION_CHOICE, 'accepted' );
		update_option( self::OPTION_CONSENT_VERSION, (string) self::CONSENT_VERSION );

		if ( ! wp_next_scheduled( self::HEARTBEAT_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HEARTBEAT_HOOK );
		}
	}

	/**
	 * Record "do not share": the consent flag every API call checks is removed and the
	 * daily status message is unscheduled, so nothing is sent to api.optiuser.com.
	 *
	 * @return void
	 */
	public static function decline_sharing() {
		delete_option( self::OPTION_CONSENT );
		delete_option( self::OPTION_CONSENT_VERSION );
		update_option( self::OPTION_CHOICE, 'declined' );
		wp_clear_scheduled_hook( self::HEARTBEAT_HOOK );
	}

	/**
	 * Called on plugin activation via register_activation_hook().
	 * Sets a flag that triggers a redirect to the welcome page.
	 *
	 * @return void
	 */
	public static function on_activation() {
		if ( ! self::has_made_choice() ) {
			update_option( self::OPTION_REDIRECT, '1' );
			// First activation only: an existing time is never overwritten.
			if ( ! get_option( self::OPTION_FIRST_ACTIVATED ) ) {
				update_option( self::OPTION_FIRST_ACTIVATED, (string) time() );
			}
		}
	}

	/**
	 * Register the hidden welcome page.
	 *
	 * @return void
	 */
	public function register_welcome_page() {
		add_submenu_page(
			'', // hidden — not attached to any parent menu (empty string avoids PHP 8.2 null deprecation)
			__( 'Welcome to Opti-Behavior', 'opti-behavior' ),
			__( 'Welcome', 'opti-behavior' ),
			'manage_options',
			self::WELCOME_SLUG,
			array( $this, 'render_welcome_page' )
		);
	}

	/**
	 * On every admin_init, redirect to the welcome page when:
	 * (a) plugin was just activated and no consent exists, OR
	 * (b) user navigates to a protected plugin page without consent.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: GET param used for page identification only.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Never redirect when already on the welcome page.
		if ( self::WELCOME_SLUG === $current_page ) {
			return;
		}

		// Once the admin answered (accepted OR declined), the screen never blocks again:
		// every feature works without sharing (WordPress.org guideline 7).
		if ( self::has_made_choice() ) {
			return;
		}

		// Pro active (licence calls = contract) or still inside an optional filtered delay:
		// no choice screen. The activation flag is dropped so it never hijacks a later admin page;
		// once the delay is over, the screen comes from the plugin pages below.
		if ( ! self::may_prompt_for_consent() ) {
			if ( get_option( self::OPTION_REDIRECT ) ) {
				delete_option( self::OPTION_REDIRECT );
			}
			return;
		}

		// Handle post-activation redirect flag.
		if ( get_option( self::OPTION_REDIRECT ) ) {
			delete_option( self::OPTION_REDIRECT );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::WELCOME_SLUG ) );
			exit;
		}

		// Show the choice screen once before any plugin page while no choice was made.
		if ( in_array( $current_page, self::$protected_pages, true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::WELCOME_SLUG ) );
			exit;
		}
	}

	/**
	 * Process the "Accept & Continue" form submission: daily status sharing only.
	 * The Pro trial starts on optiuser.com (link on the trial card, OPT-765): a
	 * start_trial field posted by an old form is ignored, no trial request is made.
	 *
	 * @return void
	 */
	public function handle_accept() {
		check_admin_referer( 'opti_behavior_accept_consent' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
		}

		self::accept_sharing();

		// Answer from the "updated data-sharing text" notice: back to the screen it was shown on.
		if ( $this->is_notice_submission() ) {
			$this->redirect_back();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=opti-behavior-analytics' ) );
		exit;
	}

	/**
	 * "Continue with Free only" on the welcome screen: no network call, the trial card is
	 * hidden from now on, back to the data-sharing question (still to answer).
	 *
	 * @return void
	 */
	public function handle_skip_trial() {
		check_admin_referer( 'opti_behavior_welcome_skip_trial' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
		}

		update_option( self::OPTION_TRIAL_CHOICE, 'declined' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::WELCOME_SLUG . '#ob-consent-form' ) );
		exit;
	}

	/**
	 * Process "Continue without sharing" (welcome screen or update notice).
	 * Nothing is sent to the API: no status message, no trial request.
	 *
	 * @return void
	 */
	public function handle_decline() {
		check_admin_referer( 'opti_behavior_decline_consent' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
		}

		self::decline_sharing();

		if ( $this->is_notice_submission() ) {
			$this->redirect_back();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=opti-behavior-analytics' ) );
		exit;
	}

	/**
	 * Save the Settings → Privacy "Share installation status with Opti-Behavior" choice.
	 *
	 * @return void
	 */
	public function handle_share_status_setting() {
		check_admin_referer( 'opti_behavior_share_status' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer
		$share = isset( $_POST['ob_share_status'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ob_share_status'] ) );

		// Pro sends the site status: the stored Yes / No is kept as is for a return to Free alone.
		if ( self::pro_handles_status() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=privacy-gdpr' ) );
			exit;
		}

		if ( $share ) {
			self::accept_sharing();
		} else {
			self::decline_sharing();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=privacy-gdpr&ob_share_status=saved' ) );
		exit;
	}

	/**
	 * Whether the current consent POST comes from the update notice (not the welcome screen).
	 *
	 * @return bool
	 */
	private function is_notice_submission() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
		return isset( $_POST['ob_from_notice'] ) && '1' === $_POST['ob_from_notice'];
	}

	/**
	 * Redirect to the admin screen the form was posted from (Analytics as fallback).
	 *
	 * @return void
	 */
	private function redirect_back() {
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=opti-behavior-analytics' ) );
		exit;
	}

	/**
	 * Show a dismissible admin notice on the Analytics page when trial activation failed.
	 *
	 * @return void
	 */
	public function render_trial_failed_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET param for page detection.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'opti-behavior-analytics' !== $current_page ) {
			return;
		}

		if ( ! get_transient( 'opti_behavior_trial_failed' ) ) {
			return;
		}

		delete_transient( 'opti_behavior_trial_failed' );

		// The class name MUST carry "opti-behavior": assets/js/notice-cleanup.js
		// strips every `.notice-warning` that is not ours from our own screens, and
		// this notice is one-shot (the transient is consumed above), so a stripped
		// render is a notice the user never sees again (QA-B-GATE-021).
		echo '<div id="opti-behavior-trial-failed-notice" class="notice notice-warning is-dismissible opti-behavior-notice"><p>';
		printf(
			/* translators: %s: link to Pro page */
			esc_html__( 'The Pro Trial could not be activated automatically. You can %s to start your trial manually.', 'opti-behavior' ),
			'<a href="https://optiuser.com/opti-behavior/pricing/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'see Pro plans', 'opti-behavior' ) . '</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Print the data-sharing text (welcome screen, update notice).
	 * The retention period lives in ONE string, so a change touches one msgid.
	 *
	 * @return void
	 */
	public static function render_privacy_text() {
		$policy_link = '<a href="https://optiuser.com/privacy-policy/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'opti-behavior' ) . '</a>';
		$items       = array(
			__( 'your site URL', 'opti-behavior' ),
			__( 'plugin type (Free or Pro), the Free and Pro version numbers, and whether Pro is running', 'opti-behavior' ),
			__( 'your WordPress and PHP versions', 'opti-behavior' ),
			__( 'one administrator email address of this site', 'opti-behavior' ),
			__( 'whether this site recorded at least one page view in its first 7 days (yes / no)', 'opti-behavior' ),
		);
		?>
		<p><?php esc_html_e( 'Self-hosted: visitor data stays on your own server and is never sent to us.', 'opti-behavior' ); ?></p>
		<p><?php esc_html_e( 'If you click "Accept & Continue", the plugin sends a short status message to api.optiuser.com once every 24 hours. It contains:', 'opti-behavior' ); ?></p>
		<ul class="ob-consent-data-list">
			<?php foreach ( $items as $item ) : ?>
				<li><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p><?php esc_html_e( 'We use it to count active installations, plan compatibility, manage trials and licences, and send technical and account emails about your installation. Every email has a one-click unsubscribe link. We delete this data 12 months after the last status message.', 'opti-behavior' ); ?></p>
		<p><?php esc_html_e( 'Sharing this data is optional. Opti-Behavior works fully if you decline, and you can change your choice at any time in Settings.', 'opti-behavior' ); ?></p>
		<p>
			<?php
			printf(
				/* translators: %s: Privacy Policy hyperlink */
				esc_html__( 'IP anonymization, consent-mode support and data deletion controls support a privacy-first setup; check your own legal requirements. See our %s.', 'opti-behavior' ),
				$policy_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html__ + a constant URL.
			);
			?>
		</p>
		<?php
	}

	/**
	 * Print the "Continue without sharing" form.
	 *
	 * @param bool $from_notice True when printed inside the update notice.
	 * @return void
	 */
	private function render_decline_form( $from_notice ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-consent-decline-form">
			<?php wp_nonce_field( 'opti_behavior_decline_consent' ); ?>
			<input type="hidden" name="action" value="opti_behavior_decline_consent" />
			<?php if ( $from_notice ) : ?>
				<input type="hidden" name="ob_from_notice" value="1" />
			<?php endif; ?>
			<button type="submit" class="<?php echo esc_attr( $from_notice ? 'button' : 'ob-consent-decline' ); ?>" id="<?php echo esc_attr( $from_notice ? 'ob-consent-notice-decline' : 'ob-consent-decline' ); ?>">
				<?php esc_html_e( 'Continue without sharing', 'opti-behavior' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Non-blocking notice for sites that accepted the previous data-sharing text:
	 * same text and the same two answers as the welcome screen. Until they accept
	 * this version, the status message keeps its old content (no has_data_7d).
	 *
	 * @return void
	 */
	public function render_consent_update_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::needs_consent_update() || self::pro_handles_status() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET param for page detection.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Settings has its own Yes / No card (render_share_status_setting): never ask twice there.
		if ( ! in_array( $current_page, self::$protected_pages, true ) || 'opti-behavior-settings' === $current_page ) {
			return;
		}

		if ( ! self::may_prompt_for_consent() ) {
			return;
		}

		// Class must carry "opti-behavior": notice-cleanup.js strips foreign notices on our screens.
		// "inline": WordPress common.js would otherwise move it into our page header (clipped).
		?>
		<?php // No "update" / "welcome" / "cookie" in class names: assets/css/admin-notices.css hides such divs. ?>
		<div id="opti-behavior-consent-update-notice" class="notice notice-info inline opti-behavior-notice ob-consent-v2-notice" style="margin:12px 20px 12px 0;">
			<p><strong><?php esc_html_e( 'Opti-Behavior: please review what the plugin shares with us', 'opti-behavior' ); ?></strong></p>
			<div class="ob-consent-v2-text">
				<style>.ob-consent-v2-text .ob-consent-data-list{list-style:disc;margin:0 0 8px 20px;}</style>
				<?php self::render_privacy_text(); ?>
			</div>
			<div class="ob-consent-v2-actions" style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 12px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'opti_behavior_accept_consent' ); ?>
					<input type="hidden" name="action" value="opti_behavior_accept_consent" />
					<input type="hidden" name="ob_from_notice" value="1" />
					<button type="submit" class="button button-primary" id="ob-consent-notice-accept"><?php esc_html_e( 'Accept & Continue', 'opti-behavior' ); ?></button>
				</form>
				<?php $this->render_decline_form( true ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings → Privacy & GDPR: "Installation status: Shared / Not shared · Change" line (OPT-712),
	 * folded Yes / No choice. Own admin-post form, printed outside the privacy settings form.
	 *
	 * @return void
	 */
	public static function render_share_status_setting() {
		// Pro sends the site status and shows its own box (licence tab): no Free card, no licence block.
		if ( ! current_user_can( 'manage_options' ) || self::pro_handles_status() ) {
			return;
		}
		// An answer to the previous text is not an answer to this one: nothing pre-ticked then.
		$text_changed = self::needs_consent_update();
		$sharing      = self::has_consent() && ! $text_changed;
		$declined     = ! self::has_consent();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only confirmation flag.
		$saved = isset( $_GET['ob_share_status'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['ob_share_status'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="settings-section" id="ob-share-status-setting">
			<?php if ( self::is_pro_licensed() ) : ?>
				<?php $policy_link = '<a href="https://optiuser.com/privacy-policy/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'opti-behavior' ) . '</a>'; ?>
				<div class="ob-share-status-licence">
					<span class="ob-share-status-licence-icon" aria-hidden="true"><i data-lucide="info"></i></span>
					<p class="ob-share-status-licence-info">
						<?php
						printf(
							/* translators: %s: Privacy Policy hyperlink */
							esc_html__( 'Opti-Behavior Pro contacts api.optiuser.com to activate and check your licence or trial: site domain, licence key, installation ID, plugin versions, and, after each saved recording, the recording\'s internal session ID (a random identifier) so it counts towards your quota. No recordings, IP addresses, page content or form data are sent. Legal basis: performance of our contract (GDPR Art. 6(1)(b)). The daily installation status below is a separate, optional choice: your Pro licence works the same whether you answer Yes or No. Details: %s.', 'opti-behavior' ),
							$policy_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html__ + a constant URL.
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php
			// OPT-712: one discreet line, same look and place for Yes and No; "Change" unfolds the choice
			// in place (native <details>: keyboard + screen readers, no script). Folded by default.
			$status_label = self::has_consent() ? __( 'Shared', 'opti-behavior' ) : __( 'Not shared', 'opti-behavior' );
			?>
			<details class="ob-share-status-details">
				<summary class="ob-share-status-summary">
					<span class="ob-share-status-state">
						<?php
						/* translators: %s: "Shared" or "Not shared" */
						printf( esc_html__( 'Installation status: %s', 'opti-behavior' ), esc_html( $status_label ) );
						?>
					</span>
					<span class="ob-share-status-sep" aria-hidden="true">·</span>
					<span class="ob-share-status-change"><?php esc_html_e( 'Change', 'opti-behavior' ); ?></span>
				</summary>
				<div class="ob-share-status-panel">
					<p class="ob-share-status-help" id="ob-share-status-help">
						<?php esc_html_e( 'Yes: once every 24 hours the plugin sends a short status message to api.optiuser.com: site URL, administrator email, Free / Pro and plugin versions, WordPress and PHP versions, and whether visits were recorded in the first 7 days. It helps us count active installations. No: nothing is sent. Visitor data is never sent to us, and Opti-Behavior (Free and Pro) works fully either way.', 'opti-behavior' ); ?>
					</p>
					<?php if ( $text_changed ) : ?>
						<p class="ob-share-status-text-changed"><?php esc_html_e( 'The text below changed since you last answered. Please confirm your choice.', 'opti-behavior' ); ?></p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-share-status-form">
						<?php wp_nonce_field( 'opti_behavior_share_status' ); ?>
						<input type="hidden" name="action" value="opti_behavior_share_status" />
						<fieldset class="ob-share-status-choices" aria-describedby="ob-share-status-help">
							<legend class="screen-reader-text" id="ob-share-status-title"><?php esc_html_e( 'Share installation status with Opti-Behavior', 'opti-behavior' ); ?></legend>
							<label class="ob-share-status-choice">
								<input type="radio" name="ob_share_status" value="1" <?php checked( $sharing ); ?> <?php echo $text_changed ? 'required' : ''; ?> />
								<?php esc_html_e( 'Yes, share the installation status', 'opti-behavior' ); ?>
							</label>
							<label class="ob-share-status-choice">
								<input type="radio" name="ob_share_status" value="0" <?php checked( $declined ); ?> />
								<?php esc_html_e( 'No, do not share anything', 'opti-behavior' ); ?>
							</label>
						</fieldset>
						<button type="submit" class="button"><?php esc_html_e( 'Save Settings', 'opti-behavior' ); ?></button>
					</form>
				</div>
			</details>
			<?php if ( $saved ) : ?>
				<p class="ob-share-status-saved" role="status"><?php esc_html_e( 'Your choice was saved.', 'opti-behavior' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue inline CSS for the welcome page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_welcome_styles( $hook ) {
		if ( 'admin_page_opti-behavior-welcome' !== $hook ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', $this->get_welcome_css() );
	}

	/**
	 * Render the full welcome/onboarding page.
	 *
	 * @return void
	 */
	public function render_welcome_page() {
		$logo_url          = OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'images/256x256.png';
		$action_url        = esc_url( admin_url( 'admin-post.php' ) );
		$trial_already_set = (bool) get_option( self::OPTION_TRIAL_LICENSE_KEY );
		// Not asked again once declined or started; hidden from admins who cannot install plugins.
		$show_trial_card   = ! $trial_already_set
			&& 'declined' !== get_option( self::OPTION_TRIAL_CHOICE, '' )
			&& current_user_can( 'install_plugins' );
		$version          = defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1.0.0';
		?>
		<div class="ob-consent-wrap">

			<!-- Accent bar -->
			<div class="ob-consent-accent-bar"></div>

			<!-- Header -->
			<div class="ob-consent-header">
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Opti-Behavior Logo', 'opti-behavior' ); ?>" class="ob-consent-logo" />
				<div class="ob-consent-brand-sub"><?php esc_html_e( 'by OptiUser', 'opti-behavior' ); ?></div>
				<h1><?php esc_html_e( 'Welcome to Opti-Behavior', 'opti-behavior' ); ?></h1>
				<p class="ob-consent-tagline">
					<?php esc_html_e( 'See where visitors click, scroll, hesitate, abandon forms, hit errors, and leave funnels — then improve your pages with A/B testing. All visitor behavior data stays on your own WordPress server.', 'opti-behavior' ); ?>
				</p>
			</div>

			<div class="ob-consent-divider"></div>

			<?php if ( $show_trial_card ) : ?>
			<!-- Pro Trial Card: its own decision, separate from the daily status sharing below (OPT-617).
			     Since OPT-765 the button is a plain link to the trial page on optiuser.com:
			     the plugin sends nothing, only the site address travels in the URL. -->
			<div class="ob-consent-trial-card" id="ob-trial-card">
				<div class="ob-consent-trial-badge"><?php esc_html_e( '✨ Free Offer', 'opti-behavior' ); ?></div>
				<h3><?php esc_html_e( 'Start Your Free 6-Month Pro Trial', 'opti-behavior' ); ?></h3>
				<p>
					<?php esc_html_e( 'Get full access to Session Recordings, Form Analytics, Error Tracking, User Journey Maps, and Smart Insights — completely free for 6 months. No credit card required.', 'opti-behavior' ); ?>
				</p>
				<p class="ob-trial-disclosure">
					<?php
					printf(
						/* translators: %s: Privacy Policy hyperlink */
						esc_html__( 'Opens the Pro trial page on optiuser.com. Only your site address is passed in the link; you choose what to share there. See our %s.', 'opti-behavior' ),
						'<a href="https://optiuser.com/privacy-policy/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'opti-behavior' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html__ + constant URL.
					);
					?>
				</p>
				<p class="ob-trial-form" id="ob-trial-form">
					<a href="<?php echo esc_url( Opti_Behavior_Trial_Invite::link_url( 'welcome' ) ); ?>" class="ob-trial-start" id="ob-start-trial-btn" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Start your Pro trial on optiuser.com', 'opti-behavior' ); ?></a>
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="ob-trial-skip-form" id="ob-trial-skip-form">
					<?php wp_nonce_field( 'opti_behavior_welcome_skip_trial' ); ?>
					<input type="hidden" name="action" value="opti_behavior_welcome_skip_trial" />
					<button type="submit" class="ob-trial-skip" id="ob-trial-skip"><?php esc_html_e( 'Continue with Free only', 'opti-behavior' ); ?></button>
				</form>
				<p class="ob-consent-trial-note">
					<?php esc_html_e( 'The Free plugin does not install Pro for you: you choose whether to download and install it. No payment details are asked and nothing is billed automatically: after the trial, Pro features stop unless you buy a licence. You can remove Pro at any time.', 'opti-behavior' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<h2 class="ob-consent-section-title"><?php esc_html_e( "What's Included", 'opti-behavior' ); ?></h2>

			<!-- Feature grid -->
			<div class="ob-consent-features">

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-heatmap">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12c0-3 2.5-6 2.5-6s2.5 3 2.5 6a2.5 2.5 0 1 1-5 0Z"/><path d="M12 2v1"/><path d="M12 21v1"/><path d="m4.6 4.6.7.7"/><path d="m18.7 18.7.7.7"/><path d="M2 12h1"/><path d="M21 12h1"/><path d="m4.6 19.4.7-.7"/><path d="m18.7 5.3.7-.7"/></svg>
					</div>
					<h3><?php esc_html_e( 'Visual Heatmaps', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Click heatmaps on every page (scroll, move and attention layers in Pro).', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-analytics">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
					</div>
					<h3><?php esc_html_e( 'Advanced Analytics', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Real-time dashboards, traffic sources, conversion funnels and visitor behavior reports.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-recordings">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
					</div>
					<h3><?php esc_html_e( 'Session Recordings', 'opti-behavior' ); ?> <span class="opti-menu-pro-badge"><?php echo esc_html_x( 'PRO', 'badge', 'opti-behavior' ); ?></span></h3>
					<p><?php esc_html_e( 'Replay full visitor sessions to see precisely how users interact with every page.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-funnels">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
					</div>
					<h3><?php esc_html_e( 'Conversion Funnels', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Track multi-step user flows, identify drop-off points and optimize conversion paths.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-forms">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="2"/><path d="M7 8h10"/><path d="M7 12h7"/><path d="M7 16h4"/></svg>
					</div>
					<h3><?php esc_html_e( 'Form Analytics', 'opti-behavior' ); ?> <span class="opti-menu-pro-badge"><?php echo esc_html_x( 'PRO', 'badge', 'opti-behavior' ); ?></span></h3>
					<p><?php esc_html_e( 'Measure form field interactions, abandonment rates and completion times.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-errors">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
					</div>
					<h3><?php esc_html_e( 'Errors & Performance', 'opti-behavior' ); ?> <span class="opti-menu-pro-badge"><?php echo esc_html_x( 'PRO', 'badge', 'opti-behavior' ); ?></span></h3>
					<p><?php esc_html_e( 'Capture JavaScript errors and performance bottlenecks in real time.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-journey">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="6" r="3"/><path d="M5 9v6"/><circle cx="5" cy="18" r="3"/><path d="M12 3h7a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-7"/><path d="M12 13h7a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-7"/></svg>
					</div>
					<h3><?php esc_html_e( 'User Journey Analytics', 'opti-behavior' ); ?> <span class="opti-menu-pro-badge"><?php echo esc_html_x( 'PRO', 'badge', 'opti-behavior' ); ?></span></h3>
					<p><?php esc_html_e( 'Visualize complete visitor navigation paths from entry to exit across your site.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-abtesting">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h5"/><path d="M4 17h5"/><path d="M15 5h5"/><path d="M15 19h5"/><path d="M9 7c4 0 3 10 6 10"/><path d="M9 17c4 0 3-10 6-10"/></svg>
					</div>
					<h3><?php esc_html_e( 'A/B Testing', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Run page split and element tests to compare variants and identify winning experiences.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-privacy-shield">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 5 6v5c0 4.5 2.9 8.5 7 10 4.1-1.5 7-5.5 7-10V6l-7-3Z"/><path d="m9.5 12 1.8 1.8 3.7-4.1"/></svg>
					</div>
					<h3><?php esc_html_e( 'Privacy-first by design', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'IP anonymization, configurable consent and full data controls. Visitor data stays on your own server.', 'opti-behavior' ); ?></p>
				</div>

			</div><!-- /.ob-consent-features -->

			<!-- Privacy & Compliance -->
			<div class="ob-consent-privacy">
				<div class="ob-consent-privacy-header">
					<div class="ob-consent-privacy-badge">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
					</div>
					<strong><?php esc_html_e( 'Privacy & Data Protection', 'opti-behavior' ); ?></strong>
				</div>
				<div class="ob-consent-privacy-body">
					<?php self::render_privacy_text(); ?>
				</div>
				<div class="ob-consent-badges">
					<span class="ob-consent-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e( 'Self-Hosted Data', 'opti-behavior' ); ?></span>
					<span class="ob-consent-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e( 'No Third-Party Sharing', 'opti-behavior' ); ?></span>
					<span class="ob-consent-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e( 'IP Anonymization', 'opti-behavior' ); ?></span>
				</div>
			</div>

			<!-- Accept form -->
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="ob-consent-cta-section" id="ob-consent-form">
				<?php wp_nonce_field( 'opti_behavior_accept_consent' ); ?>
				<input type="hidden" name="action" value="opti_behavior_accept_consent" />
				<button type="submit" class="ob-consent-cta">
					<?php esc_html_e( 'Accept & Continue', 'opti-behavior' ); ?>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
				</button>
			</form>

			<!-- Decline form (separate admin-post action: nothing is sent) -->
			<div class="ob-consent-cta-section ob-consent-decline-section">
				<?php $this->render_decline_form( false ); ?>
				<p class="ob-consent-footer-note">
					<?php esc_html_e( 'You can review and adjust all privacy and tracking settings at any time from the Settings page.', 'opti-behavior' ); ?>
				</p>
			</div>

			<!-- Bottom brand -->
			<div class="ob-consent-bottom-brand">
				<a href="https://optiuser.com" target="_blank" rel="noopener noreferrer">OptiUser</a> &middot;
				<?php
				/* translators: %s: plugin version number */
				printf( esc_html__( 'Version %s', 'opti-behavior' ), esc_html( $version ) );
				?>
			</div>

		</div><!-- /.ob-consent-wrap -->
		<?php
	}

	/**
	 * Return the inline CSS used for the welcome page.
	 *
	 * @return string
	 */
	private function get_welcome_css() {
		return '
/* ── Opti-Behavior First-Run Consent Page ── */
.ob-consent-wrap {
	max-width: 880px;
	margin: 16px auto;
	padding: 0 20px 36px;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, sans-serif;
	color: #1a1a2e;
}
.ob-consent-accent-bar {
	height: 4px;
	background: linear-gradient(90deg, #6366f1, #8b5cf6, #a78bfa, #6366f1);
	background-size: 200% 100%;
	border-radius: 2px;
	margin-bottom: 22px;
	animation: ob-shimmer 3s ease infinite;
}
@keyframes ob-shimmer {
	0%   { background-position: 0% 50%; }
	50%  { background-position: 100% 50%; }
	100% { background-position: 0% 50%; }
}
.ob-consent-header {
	text-align: center;
	margin-bottom: 18px;
}
.ob-consent-logo {
	width: 60px;
	height: 60px;
	border-radius: 13px;
	box-shadow: 0 4px 16px rgba(99,102,241,.20);
	margin-bottom: 10px;
	display: block;
	margin-inline: auto;
}
.ob-consent-brand-sub {
	font-size: 10px;
	color: #6366f1;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 1.8px;
	margin-bottom: 6px;
}
.ob-consent-header h1 {
	font-size: 22px;
	font-weight: 700;
	color: #1a1a2e;
	margin: 0 0 6px;
	line-height: 1.2;
}
.ob-consent-tagline {
	font-size: 13px;
	color: #64748b;
	margin: 0 auto;
	max-width: 500px;
	line-height: 1.6;
}
.ob-consent-divider {
	width: 36px;
	height: 3px;
	background: linear-gradient(90deg, #6366f1, #8b5cf6);
	border-radius: 2px;
	margin: 14px auto;
}
.ob-consent-section-title {
	text-align: center;
	font-size: 14px;
	font-weight: 600;
	color: #1a1a2e;
	margin-bottom: 14px;
}
/* Feature grid */
.ob-consent-features {
	display: grid;
	grid-template-columns: repeat(3,1fr);
	gap: 10px;
	margin-bottom: 18px;
}
@media (max-width: 782px) {
	.ob-consent-features { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 520px) {
	.ob-consent-features { grid-template-columns: 1fr; }
}
.ob-consent-feature {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 9px;
	padding: 14px 13px;
	transition: border-color .18s, box-shadow .18s;
}
.ob-consent-feature:hover {
	border-color: #c7d2fe;
	box-shadow: 0 3px 10px rgba(99,102,241,.09);
}
.ob-consent-feature-icon {
	width: 32px;
	height: 32px;
	border-radius: 7px;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-bottom: 8px;
}
.ob-consent-feature-icon svg { width: 16px; height: 16px; }
.ob-consent-feature-icon.icon-heatmap    { background: #fef2f2; color: #ef4444; }
.ob-consent-feature-icon.icon-analytics  { background: #eff6ff; color: #3b82f6; }
.ob-consent-feature-icon.icon-recordings { background: #f5f3ff; color: #7c3aed; }
.ob-consent-feature-icon.icon-funnels    { background: #fdf4ff; color: #a855f7; }
.ob-consent-feature-icon.icon-forms      { background: #fff7ed; color: #ea580c; }
.ob-consent-feature-icon.icon-errors     { background: #fef2f2; color: #dc2626; }
.ob-consent-feature-icon.icon-journey    { background: #f0fdfa; color: #0d9488; }
.ob-consent-feature-icon.icon-selfhosted { background: #ecfdf5; color: #10b981; }
.ob-consent-feature-icon.icon-abtesting  { background: #eef2ff; color: #4f46e5; }
.ob-consent-feature-icon.icon-privacy-shield { background: #eff6ff; color: #6366f1; }
.ob-consent-feature h3 {
	font-size: 12.5px;
	font-weight: 600;
	color: #1a1a2e;
	margin: 0 0 3px;
}
.ob-consent-feature p {
	font-size: 11.5px;
	color: #64748b;
	margin: 0;
	line-height: 1.5;
}
/* Privacy section */
.ob-consent-privacy {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 9px;
	padding: 14px 18px;
	margin-bottom: 16px;
}
.ob-consent-privacy-header {
	display: flex;
	align-items: center;
	gap: 9px;
	margin-bottom: 8px;
}
.ob-consent-privacy-badge {
	width: 28px;
	height: 28px;
	border-radius: 6px;
	background: #eff6ff;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.ob-consent-privacy-badge svg { width: 14px; height: 14px; color: #6366f1; }
.ob-consent-privacy-header strong { font-size: 13px; color: #1a1a2e; }
.ob-consent-privacy-body {
	font-size: 12px;
	color: #475569;
	line-height: 1.6;
	margin-bottom: 10px;
}
.ob-consent-privacy-body a { color: #6366f1; text-decoration: none; font-weight: 500; }
.ob-consent-privacy-body a:hover { text-decoration: underline; }
.ob-consent-privacy-body p { margin: 0 0 6px; font-size: 12px; }
.ob-consent-data-list { margin: 0 0 6px 18px; padding: 0; list-style: disc; }
.ob-consent-data-list li { margin: 0 0 2px; }
.ob-consent-badges { display: flex; flex-wrap: wrap; gap: 5px; }
.ob-consent-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 10.5px;
	font-weight: 500;
	color: #475569;
	background: #f8fafc;
	border: 1px solid #e5e7eb;
	border-radius: 5px;
	padding: 3px 7px;
}
.ob-consent-badge svg { width: 10px; height: 10px; color: #10b981; flex-shrink: 0; }
/* Trial opt-in card */
.ob-consent-trial-card {
	background: linear-gradient(135deg,#fef9c3 0%,#fef3c7 100%);
	border: 2px solid #fcd34d;
	border-radius: 11px;
	padding: 18px 22px;
	margin-bottom: 16px;
}
.ob-consent-trial-badge {
	display: inline-block;
	background: linear-gradient(135deg,#f59e0b,#d97706);
	color: #fff;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: .05em;
	text-transform: uppercase;
	padding: 3px 9px;
	border-radius: 20px;
	margin-bottom: 8px;
}
.ob-consent-trial-card h3 {
	font-size: 15px;
	font-weight: 700;
	color: #92400e;
	margin: 0 0 7px;
	line-height: 1.3;
}
.ob-consent-trial-card > p {
	font-size: 12.5px;
	color: #78350f;
	margin: 0 0 12px;
	line-height: 1.55;
}
.ob-trial-disclosure {
	font-size: 13px;
	color: #451a03;
	background: rgba(255,255,255,.65);
	border: 1px solid #fde68a;
	border-radius: 8px;
	padding: 10px 12px;
	margin: 0 0 14px;
	line-height: 1.55;
}
.ob-trial-disclosure a { color: #1d4ed8; text-decoration: underline; }
.ob-trial-form, .ob-trial-skip-form { margin: 0; text-align: center; }
.ob-trial-start {
	display: inline-block;
	background: #b45309;
	color: #fff;
	font-size: 15px;
	font-weight: 700;
	padding: 13px 34px;
	border: none;
	border-radius: 8px;
	cursor: pointer;
	box-shadow: 0 2px 12px rgba(180,83,9,.30);
	line-height: 1.2;
	transition: background .2s, box-shadow .2s;
}
.ob-trial-start:hover { background: #92400e; box-shadow: 0 4px 16px rgba(146,64,14,.38); }
.ob-trial-start, .ob-trial-start:hover, .ob-trial-start:focus, .ob-trial-start:visited { color: #fff; text-decoration: none; }
.ob-trial-start:focus-visible, .ob-trial-skip:focus-visible { outline: 2px solid #1d4ed8; outline-offset: 2px; }
.ob-trial-skip {
	background: none;
	border: none;
	padding: 8px 6px;
	margin-top: 4px;
	color: #78350f;
	font-size: 14px;
	font-weight: 500;
	text-decoration: underline;
	cursor: pointer;
}
.ob-trial-skip:hover { color: #451a03; }
.ob-consent-trial-note {
	font-size: 12px;
	color: #78350f;
	margin: 8px 0 0;
	text-align: center;
	line-height: 1.5;
}
@media (max-width: 520px) {
	.ob-consent-trial-card { padding: 16px 14px; }
	.ob-trial-start { display: block; width: 100%; box-sizing: border-box; padding: 13px 12px; text-align: center; }
}
/* CTA form */
.ob-consent-cta-section { text-align: center; padding: 4px 0 0; }
.ob-consent-cta {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	background: linear-gradient(135deg,#6366f1,#7c3aed);
	color: #fff !important;
	font-size: 14px;
	font-weight: 600;
	padding: 12px 32px;
	border: none;
	border-radius: 8px;
	cursor: pointer;
	transition: background .2s, box-shadow .2s, transform .15s;
	box-shadow: 0 2px 12px rgba(99,102,241,.30);
	text-decoration: none !important;
	line-height: 1;
	letter-spacing: .2px;
}
.ob-consent-cta:hover {
	background: linear-gradient(135deg,#4f46e5,#6d28d9);
	box-shadow: 0 4px 18px rgba(99,102,241,.42);
	transform: translateY(-1px);
}
.ob-consent-cta:active { transform: translateY(0); }
.ob-consent-cta svg { width: 15px; height: 15px; flex-shrink: 0; }
.ob-consent-decline-section { padding-top: 10px; }
.ob-consent-decline-form { margin: 0; }
.ob-consent-decline {
	background: transparent;
	border: 1px solid #cbd5e1;
	border-radius: 8px;
	color: #475569;
	font-size: 13px;
	font-weight: 500;
	padding: 9px 24px;
	cursor: pointer;
}
.ob-consent-decline:hover { background: #f1f5f9; color: #1e293b; }
.ob-consent-footer-note {
	margin-top: 10px;
	font-size: 11px;
	color: #94a3b8;
	line-height: 1.5;
}
/* Bottom brand */
.ob-consent-bottom-brand {
	text-align: center;
	margin-top: 20px;
	padding-top: 14px;
	border-top: 1px solid #e5e7eb;
	font-size: 11px;
	color: #94a3b8;
}
.ob-consent-bottom-brand a { color: #6366f1; text-decoration: none; font-weight: 500; }
.ob-consent-bottom-brand a:hover { text-decoration: underline; }
		';
	}
}
