<?php
/**
 * Pro trial invitation: the two information lines and the discreet text link.
 *
 * OPT-551 (decision OPT-550, 2026-10-06). The trial banner itself stays on
 * Traffic, Settings and How it works only (WordPress.org guideline 11); since
 * OPT-763 it is a dismissible notice with a link to the trial page on
 * optiuser.com (`link_url( 'dashboard-banner' )`), no one-click trial. This
 * class holds what is shared by the banner, the four locked Pro pages and the
 * discreet links next to locked Pro controls (Heatmaps tabs, Smart Insights
 * locked cards):
 *  - `render_info_lines( $kind )`: what is sent to optiuser.com + "no automatic
 *    billing" line, printed under the download buttons;
 *  - `link_url( $placement )`: THE Pro trial page URL of every Pro link (OPT-764);
 *  - `invite_url()` / `render_link()`: "Try Pro free for 6 months" text link,
 *    only while the banner state is `invite` (trial never started and banner not
 *    dismissed): hidden when a licence or trial runs, after a dismissal, during
 *    or after a trial (the link would promise a trial the site already used).
 *
 * The banner state comes from the dashboard (`get_trial_banner_state_public()`
 * of the assets trait); any failure = no link.
 *
 * @package OptiBehavior
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro trial invitation helpers (all static).
 */
class Opti_Behavior_Trial_Invite {

	/**
	 * Privacy policy page shared with the Welcome screen.
	 */
	const PRIVACY_URL = 'https://optiuser.com/privacy-policy/';

	/**
	 * The single Pro trial page on optiuser.com (OPT-763).
	 */
	const TRIAL_PAGE_URL = 'https://optiuser.com/opti-behavior/pro-trial/';

	/**
	 * Request-level memo of the banner state.
	 *
	 * @var array|null
	 */
	private static $state = null;

	/**
	 * Banner state of the dashboard (display), memoized.
	 *
	 * @return array { display: invite|hidden }
	 */
	public static function state() {
		if ( null !== self::$state ) {
			return self::$state;
		}

		$state = array(
			'display' => 'hidden',
		);

		try {
			if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) && method_exists( 'Opti_Behavior_Heatmap_Core', 'get_instance' ) ) {
				$core      = Opti_Behavior_Heatmap_Core::get_instance();
				$dashboard = ( $core && method_exists( $core, 'get_dashboard' ) ) ? $core->get_dashboard() : null;
				if ( $dashboard && method_exists( $dashboard, 'get_trial_banner_state_public' ) ) {
					$read = $dashboard->get_trial_banner_state_public();
					if ( is_array( $read ) && isset( $read['display'] ) ) {
						$state = array_merge( $state, $read );
					}
				}
			}
		} catch ( \Throwable $e ) {
			$state['display'] = 'hidden';
		}

		/**
		 * Filters the trial banner state read by the discreet trial links.
		 *
		 * @param array $state { display, ... }.
		 */
		$state = apply_filters( 'opti_behavior_trial_invite_state', $state );

		self::$state = is_array( $state ) ? $state : array( 'display' => 'hidden' );
		return self::$state;
	}

	/**
	 * Forget the memoized state (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$state = null;
	}

	/**
	 * THE "try / upgrade to Pro" URL of Free (OPT-763, OPT-764).
	 *
	 * Every Pro link of Free (trial notice, heatmap bubble, the 4 Pro pages,
	 * Smart Insights, A/B, Settings, discreet links) opens the single Pro trial
	 * page on optiuser.com: `site` = this site's address, `utm_medium` =
	 * placement. Nothing else goes in the URL (never an email, a username or
	 * an access code) and the plugin itself sends nothing: the browser opens
	 * the page on click. Callers decide whether the link shows (Pro active =
	 * no link).
	 *
	 * @param string $placement Where the link is shown (dashboard-banner, heatmap-bubble, …).
	 * @return string
	 */
	public static function link_url( $placement = '' ) {
		$placement = sanitize_key( (string) $placement );
		if ( '' === $placement ) {
			$placement = 'plugin';
		}
		return add_query_arg(
			array(
				'site'       => rawurlencode( home_url() ),
				'utm_source' => 'wp-plugin',
				'utm_medium' => $placement,
			),
			self::TRIAL_PAGE_URL
		);
	}

	/**
	 * URL of the discreet "Try Pro free for 6 months" link, '' when it must
	 * not show (banner state other than `invite`: Pro loaded, notice
	 * dismissed or a trial already asked for).
	 *
	 * @param string $placement utm_medium of the link.
	 * @return string
	 */
	public static function invite_url( $placement ) {
		$state = self::state();
		if ( ! isset( $state['display'] ) || 'invite' !== $state['display'] ) {
			return '';
		}
		return self::link_url( $placement );
	}

	/**
	 * Text of the discreet link.
	 *
	 * @return string
	 */
	public static function link_text() {
		return __( 'Try Pro free for 6 months', 'opti-behavior' );
	}

	/**
	 * Print the discreet text link (nothing when hidden).
	 *
	 * @param string $context Short context slug added as a CSS modifier (heatmaps, smart-insights).
	 * @return void
	 */
	public static function render_link( $context = '' ) {
		$url = self::invite_url( '' !== $context ? 'try-pro-' . $context : 'try-pro' );
		if ( '' === $url ) {
			return;
		}
		$class = 'ob-try-pro-link';
		if ( '' !== $context ) {
			$class .= ' ob-try-pro-link--' . sanitize_html_class( $context );
		}
		printf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
			esc_attr( $class ),
			esc_url( $url ),
			esc_html( self::link_text() )
		);
	}

	/**
	 * Print the two information lines under a download button.
	 *
	 * Since OPT-764 the button is a plain link to the trial page and WordPress
	 * sends nothing; only the site address travels in the URL (`link_url()`).
	 * OPT-771: the line says exactly that (GDPR art. 13). The banner's
	 * one-click trial button is gone (OPT-763), so there is no 'trial' text.
	 *
	 * @param string $kind 'download' (locked pages).
	 * @return void
	 */
	public static function render_info_lines( $kind = 'download' ) {
		$policy_link = '<a href="' . esc_url( self::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'opti-behavior' ) . '</a>';

		/* translators: %s: Privacy Policy hyperlink */
		$sent = esc_html__( 'Opens the Pro trial page on optiuser.com. Only your site address is passed in the link; you choose what to share there. See our %s.', 'opti-behavior' );
		?>
		<p class="ob-trial-info">
			<?php
			printf(
				$sent, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html__ above.
				$policy_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url + esc_html__.
			);
			?>
			<br />
			<?php esc_html_e( 'No automatic billing: after the trial, Pro features stop unless you buy a licence.', 'opti-behavior' ); ?>
		</p>
		<?php
	}
}
