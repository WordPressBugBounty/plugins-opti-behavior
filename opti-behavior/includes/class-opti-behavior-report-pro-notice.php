<?php
/**
 * Reports: how reliable a report is without the Pro modules (OPT-3054).
 *
 * No report is ever blocked (HX 07/11): a report is always built with the data
 * available. When Pro is off, the Pro trial is over or the free Pro plan has
 * paused new recordings, the reader is told so twice — while the report is being
 * written (waiting page) and at the top of the finished document — with one
 * button to the Pro trial.
 *
 * The state is read from what is already in this request / the cached licence
 * answer: never an HTTP call, never a second quota read.
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "this report misses the Pro modules" state and its texts.
 */
class Opti_Behavior_Report_Pro_Notice {

	/**
	 * Nothing missing.
	 */
	const OK = 'ok';

	/**
	 * Pro is not active on this site.
	 */
	const NO_PRO = 'no_pro';

	/**
	 * The Pro trial is over.
	 */
	const TRIAL_ENDED = 'trial_ended';

	/**
	 * Free Pro plan: the monthly recordings are used up.
	 */
	const QUOTA_BLOCKED = 'quota_blocked';

	/**
	 * Memoized state of this request.
	 *
	 * @var array|null
	 */
	private static $state = null;

	/**
	 * State of the Pro modules for a report, memoized per request.
	 *
	 * `level`    one of the constants above.
	 * `used`     recorded sessions this month, or null (no figure to show).
	 * `resumes`  date when recordings start again (site format), or ''.
	 *
	 * @return array { level: string, used: int|null, resumes: string }
	 */
	public static function state() {
		if ( null !== self::$state ) {
			return self::$state;
		}
		$state = array(
			'level'   => self::OK,
			'used'    => null,
			'resumes' => '',
		);
		if ( ! function_exists( 'opti_behavior_pro_active' ) || ! opti_behavior_pro_active() ) {
			$state['level'] = self::NO_PRO;
			self::$state    = $state;

			return self::$state;
		}
		/**
		 * Pro answers what its modules cannot give this report (OPT-3054).
		 *
		 * Pro reads its cached licence answer only (`Opti_Behavior_Pro_Free_Plan`),
		 * never the licence server: an administrator opening a report must not
		 * wait on the network.
		 *
		 * @param array $limits { level: ok|trial_ended|quota_blocked, used: int|null, resumes: string }.
		 */
		$limits = apply_filters( 'opti_behavior_report_pro_limits', array() );
		if ( is_array( $limits ) && isset( $limits['level'] ) && in_array( (string) $limits['level'], array( self::TRIAL_ENDED, self::QUOTA_BLOCKED ), true ) ) {
			$state['level']   = (string) $limits['level'];
			$state['used']    = isset( $limits['used'] ) && is_numeric( $limits['used'] ) ? (int) $limits['used'] : null;
			$state['resumes'] = isset( $limits['resumes'] ) ? (string) $limits['resumes'] : '';
		}
		self::$state = $state;

		return self::$state;
	}

	/**
	 * Forget the memoized state (tests).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$state = null;
	}

	/**
	 * Is part of this report missing because of the Pro modules?
	 *
	 * @return bool
	 */
	public static function limited() {
		$state = self::state();

		return self::OK !== $state['level'];
	}

	/**
	 * One line: what is missing in this report, for this state.
	 *
	 * TODO-TEXT (OPT-3054): provisional wording, the final sentences come from
	 * the Marketing card.
	 *
	 * @param array|null $state State to read, null = the state of this request.
	 * @return string Plain text ('' when nothing is missing).
	 */
	public static function headline( $state = null ) {
		$state = is_array( $state ) ? $state : self::state();
		if ( self::OK === $state['level'] ) {
			return '';
		}

		return __( 'This report is not reliable without all Pro modules.', 'opti-behavior' );
	}

	/**
	 * The sentence under the headline, by state.
	 *
	 * TODO-TEXT (OPT-3054): provisional wording.
	 *
	 * @param array|null $state State to read, null = the state of this request.
	 * @return string Plain text ('' when nothing is missing).
	 */
	public static function line( $state = null ) {
		$state = is_array( $state ) ? $state : self::state();
		$state = array_merge(
			array(
				'level'   => self::OK,
				'used'    => null,
				'resumes' => '',
			),
			$state
		);
		switch ( $state['level'] ) {
			case self::NO_PRO:
				return __( 'Session recordings, JavaScript errors, form drop-offs and user journeys are measured by Opti-Behavior Pro. This report is built with the data available without them.', 'opti-behavior' );

			case self::TRIAL_ENDED:
				return __( 'Your Pro trial is over, so the Pro modules stopped measuring. This report is built with the data available without them.', 'opti-behavior' );

			case self::QUOTA_BLOCKED:
				$used = null !== $state['used'] ? number_format_i18n( $state['used'] ) : '';
				if ( '' !== $used && '' !== $state['resumes'] ) {
					/* translators: 1: number of recorded sessions used this month, 2: date when recording starts again. */
					return sprintf( __( '%1$s recorded sessions used this month: new recordings are paused until %2$s, so part of this period is not measured.', 'opti-behavior' ), $used, $state['resumes'] );
				}
				if ( '' !== $used ) {
					/* translators: %s: number of recorded sessions used this month. */
					return sprintf( __( '%s recorded sessions used this month: new recordings are paused, so part of this period is not measured.', 'opti-behavior' ), $used );
				}

				return __( 'The free monthly recordings are used up, so part of this period is not measured.', 'opti-behavior' );

			default:
				return '';
		}
	}

	/**
	 * Label of the button.
	 *
	 * TODO-TEXT (OPT-3054): provisional wording.
	 *
	 * @param array|null $state State to read, null = the state of this request.
	 * @return string
	 */
	public static function cta_label( $state = null ) {
		$state = is_array( $state ) ? $state : self::state();

		return self::NO_PRO === $state['level']
			? __( 'Get Pro free', 'opti-behavior' )
			: __( 'See the Pro offer', 'opti-behavior' );
	}

	/**
	 * Address of the button: the Pro trial invitation, '' when it is not there.
	 *
	 * @param string $placement utm_medium of the link.
	 * @return string
	 */
	public static function cta_url( $placement = 'report' ) {
		/*
		 * OPT-3054 point 3: an administrator reading the report goes to THE
		 * form of the plugin (one click, no retyping). A report is also read by
		 * people who have no account on this site: for them the button keeps
		 * the public trial page of optiuser.com, which anyone can open.
		 */
		if ( class_exists( 'Opti_Behavior_Trial_Signup' ) && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return Opti_Behavior_Trial_Signup::form_url( $placement );
		}

		return class_exists( 'Opti_Behavior_Trial_Invite' ) ? Opti_Behavior_Trial_Invite::link_url( $placement ) : '';
	}

	/**
	 * What the waiting page shows while the report is written, or null.
	 *
	 * @return array|null { headline, line, cta, url }
	 */
	public static function wait_notice() {
		if ( ! self::limited() ) {
			return null;
		}

		return array(
			'headline' => self::headline(),
			'line'     => self::line(),
			'cta'      => self::cta_label(),
			'url'      => self::cta_url( 'report-wait' ),
		);
	}

	/**
	 * The warning box printed at the top of a finished report, or ''.
	 *
	 * Escaped here: the document prints it as it is.
	 *
	 * @return string
	 */
	public static function box_html() {
		if ( ! self::limited() ) {
			return '';
		}
		$url  = self::cta_url( 'report-box' );
		$html = '<section class="obr-section obr-prowarn"><div class="obr-prowarn__in">';
		// OPT-3055: the same PRO pill as the locked lines of the document, so the
		// three Pro places (waiting screen, box, banner) read as one family.
		$html .= '<p class="obr-prowarn__t"><span class="obr-prowarn__badge">PRO</span>' . esc_html( self::headline() ) . '</p>';
		$html .= '<p class="obr-prowarn__l">' . esc_html( self::line() ) . '</p>';
		if ( '' !== $url ) {
			$html .= '<p class="obr-prowarn__a"><a href="' . esc_url( $url ) . '">' . esc_html( self::cta_label() ) . '</a></p>';
		}

		return $html . '</div></section>';
	}
}
