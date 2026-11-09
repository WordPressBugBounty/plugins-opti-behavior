<?php
/**
 * "Get Pro free" banner on Smart Insights and Reports (OPT-3054, point 2).
 *
 * One dismissible banner at the top of our own two screens when the Pro
 * modules are not measuring: Pro absent, or the Pro trial over. Nothing is
 * locked (WP.org guideline 5), no network call is made (guideline 7), nothing
 * is ever downloaded or installed (guideline 8), and the banner is printed on
 * Opti-Behavior screens only and can be closed (guideline 11).
 *
 * It never doubles the quota banner of the Pro free plan: that state
 * (`quota_blocked`) is Pro's own message, on its own screens.
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Pro upsell banner of Smart Insights / Reports.
 */
class Opti_Behavior_Trial_Upsell_Banner {

	/**
	 * Screens that carry the banner.
	 */
	const PAGES = array( 'opti-behavior-smart-insights', 'opti-behavior-reports' );

	/**
	 * User meta: when this user closed the banner (unix time).
	 */
	const DISMISS_META = 'opti_behavior_pro_upsell_dismissed';

	/**
	 * A closed banner comes back after this long (30 days).
	 */
	const SNOOZE = 2592000;

	/**
	 * Query argument of the close link.
	 */
	const DISMISS_ARG = 'ob_pro_upsell_dismiss';

	/**
	 * Nonce action of the close link.
	 */
	const NONCE = 'opti_behavior_pro_upsell_dismiss';

	/**
	 * Hook the banner and its close link.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * Is this screen one of the two that carry the banner?
	 *
	 * @param string $page Value of the `page` query argument.
	 * @return bool
	 */
	public static function is_target_page( $page ) {
		return in_array( (string) $page, self::PAGES, true );
	}

	/**
	 * The `page` of the current request.
	 *
	 * @return string
	 */
	private static function current_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * State the banner answers to: 'no_pro', 'trial_ended' or '' (no banner).
	 *
	 * Reuses the state of the report notice: the same memoized read, never a
	 * second quota read, never an HTTP call. `quota_blocked` belongs to the Pro
	 * free-plan banner, so it prints nothing here (no duplicate).
	 *
	 * @return string
	 */
	public static function level() {
		if ( ! class_exists( 'Opti_Behavior_Report_Pro_Notice' ) ) {
			return '';
		}
		$level = Opti_Behavior_Report_Pro_Notice::state()['level'];

		return in_array(
			$level,
			array( Opti_Behavior_Report_Pro_Notice::NO_PRO, Opti_Behavior_Report_Pro_Notice::TRIAL_ENDED ),
			true
		) ? $level : '';
	}

	/**
	 * Has this user closed the banner less than SNOOZE ago?
	 *
	 * @param mixed $stored Stored meta value.
	 * @param int   $now    Unix time.
	 * @return bool
	 */
	public static function is_dismissed( $stored, $now ) {
		$at = is_numeric( $stored ) ? (int) $stored : 0;

		return $at > 0 && $at <= $now && ( $now - $at ) < self::SNOOZE;
	}

	/**
	 * Close the banner for this user (link of the banner).
	 *
	 * @return void
	 */
	public static function maybe_dismiss() {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, time() );

		$back = remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) );
		if ( ! headers_sent() ) {
			wp_safe_redirect( $back );
			exit;
		}
	}

	/**
	 * The close link of the current screen.
	 *
	 * @return string
	 */
	private static function dismiss_url() {
		return wp_nonce_url( add_query_arg( self::DISMISS_ARG, '1' ), self::NONCE );
	}

	/**
	 * The two sentences of the banner, for a state.
	 *
	 * TODO-TEXT (OPT-3054): provisional wording, the final sentences come from
	 * the Marketing card.
	 *
	 * @param string $level 'no_pro' or 'trial_ended'.
	 * @return array { headline: string, line: string }
	 */
	public static function texts( $level ) {
		$sessions = class_exists( 'Opti_Behavior_Trial_Invite' ) ? Opti_Behavior_Trial_Invite::free_sessions() : null;

		if ( 'trial_ended' === $level ) {
			$headline = __( 'Your Pro trial is over: the Pro modules stopped measuring.', 'opti-behavior' );
			$line     = ( null === $sessions )
				? __( 'Pro stays free after the trial, with a monthly allowance of recorded sessions. No credit card, nothing is ever billed automatically.', 'opti-behavior' )
				: sprintf(
					/* translators: %s: number of recorded sessions per month, e.g. 1,000 */
					__( 'Pro stays free after the trial: %s recorded sessions every month. No credit card, nothing is ever billed automatically.', 'opti-behavior' ),
					number_format_i18n( $sessions )
				);

			return array(
				'headline' => $headline,
				'line'     => $line,
			);
		}

		$headline = __( 'Half of what happens on your pages is measured by Opti-Behavior Pro.', 'opti-behavior' );
		$line     = ( null === $sessions )
			? __( 'Session recordings, JavaScript errors, form drop-offs and user journeys: 6 months free, then it stays free with a monthly allowance of recorded sessions. No credit card.', 'opti-behavior' )
			: sprintf(
				/* translators: %s: number of recorded sessions per month, e.g. 1,000 */
				__( 'Session recordings, JavaScript errors, form drop-offs and user journeys: 6 months free, then %s recorded sessions every month, for free. No credit card.', 'opti-behavior' ),
				number_format_i18n( $sessions )
			);

		return array(
			'headline' => $headline,
			'line'     => $line,
		);
	}

	/**
	 * Label of the button, for a state.
	 *
	 * @param string $level 'no_pro' or 'trial_ended'.
	 * @return string
	 */
	public static function cta_label( $level ) {
		return 'trial_ended' === $level
			? __( 'See the Pro offer', 'opti-behavior' )
			: __( 'Get Pro free', 'opti-behavior' );
	}

	/**
	 * Address of the button: the Pro trial invitation ('' when it is not there).
	 *
	 * @return string
	 */
	public static function cta_url() {
		// OPT-3054 point 3: the button leads to THE form of the plugin (Pro
		// page), never to a second form; the old external page stays the
		// fallback of the form itself.
		if ( class_exists( 'Opti_Behavior_Trial_Signup' ) ) {
			return Opti_Behavior_Trial_Signup::form_url( 'upsell-banner' );
		}

		return class_exists( 'Opti_Behavior_Trial_Invite' ) ? Opti_Behavior_Trial_Invite::link_url( 'upsell-banner' ) : '';
	}

	/**
	 * admin_notices: print the banner when it is due.
	 *
	 * @return void
	 */
	public static function maybe_render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::is_target_page( self::current_page() ) ) {
			return;
		}
		$level = self::level();
		if ( '' === $level ) {
			return;
		}
		if ( self::is_dismissed( get_user_meta( get_current_user_id(), self::DISMISS_META, true ), time() ) ) {
			return;
		}

		self::render( $level );
	}

	/**
	 * Print the banner.
	 *
	 * The classes `notice inline opti-behavior-notice` are required: without
	 * them Free's admin-notices.css hides anything that looks like a notice
	 * (OPT-731) and notice-cleanup.js removes it.
	 *
	 * @param string $level 'no_pro' or 'trial_ended'.
	 * @return void
	 */
	public static function render( $level ) {
		$texts = self::texts( $level );
		$url   = self::cta_url();
		?>
		<div class="ob-pro-upsell notice notice-info inline opti-behavior-notice">
			<div class="ob-pro-upsell__body">
				<p class="ob-pro-upsell__lead">
					<span class="ob-pro-upsell__badge">Pro</span>
					<strong><?php echo esc_html( $texts['headline'] ); ?></strong>
				</p>
				<p class="ob-pro-upsell__text"><?php echo esc_html( $texts['line'] ); ?></p>
			</div>
			<p class="ob-pro-upsell__actions">
				<?php if ( '' !== $url ) : ?>
					<a class="button button-primary ob-pro-upsell__btn" href="<?php echo esc_url( $url ); ?>">
						<?php echo esc_html( self::cta_label( $level ) ); ?>
					</a>
				<?php endif; ?>
				<a class="ob-pro-upsell__close" href="<?php echo esc_url( self::dismiss_url() ); ?>">
					<?php esc_html_e( 'Not now', 'opti-behavior' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
