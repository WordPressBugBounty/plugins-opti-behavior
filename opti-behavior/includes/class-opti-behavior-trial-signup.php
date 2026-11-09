<?php
/**
 * "Pro in one click" form (OPT-3054, HX 2026-11-07).
 *
 * One single place asks for the trial: the Pro page of the plugin. The admin
 * types (or keeps) an email, reads the sentence of the Juriste, clicks once,
 * and this class posts the email and the site address to the API, which
 * creates the account, the licence and sends the email.
 *
 * The class is named `Opti_Behavior_Trial_*` on purpose: the Free autoloader
 * only serves a known list of prefixes, and `Opti_Behavior_Pro_*` belongs to
 * the Pro plugin.
 *
 * Rules kept word for word (wordpress.org g7 / g8, Juriste OPT-2983):
 * - NOTHING leaves the site before the click: the form prints no script that
 *   calls anything, and `render_form()` never touches the network;
 * - the email is never stored in an option: it travels with the click only;
 * - the plugin never downloads, installs or activates Pro by itself: the API
 *   answers by email, with a download link;
 * - every answer is a readable message, never a spinner that stays.
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server side of the one-click Pro trial form.
 */
class Opti_Behavior_Trial_Signup {

	/**
	 * AJAX action of the click.
	 */
	const AJAX_ACTION = 'opti_behavior_pro_signup';

	/**
	 * Nonce action / field name.
	 */
	const NONCE = 'opti_behavior_pro_signup';

	/**
	 * Path of the API endpoint that creates the account + the licence.
	 */
	const ENDPOINT = 'pro-signup';

	/**
	 * Page that holds THE form.
	 */
	const PAGE_SLUG = 'opti-behavior-recordings';

	/**
	 * Anchor of the form inside that page.
	 */
	const ANCHOR = 'ob-pro-cta';

	/**
	 * Customer account of optiuser.com, where Pro is downloaded (OPT-3109,
	 * HX 2026-11-08). Used when the API answer carries no one-time login link;
	 * the tab is always opened by the admin's own click (guideline 7).
	 */
	const ACCOUNT_URL = 'https://optiuser.com/my-account/';

	/**
	 * Hosts a login link of the API answer may point to (OPT-3135).
	 */
	const LOGIN_HOSTS = array( 'optiuser.com', 'www.optiuser.com' );

	/**
	 * Seconds the API has to answer (HX: a clear message in under 30 s).
	 */
	const TIMEOUT = 10;

	/**
	 * Clicks allowed per admin and per hour before "too many tries".
	 */
	const MAX_TRIES = 5;

	/**
	 * Register the AJAX handler of the click.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_signup' ) );
	}

	/**
	 * Address of the one place that holds the form.
	 *
	 * Every "Try Pro / Get Pro free" button of the banner and of the report
	 * leads here (OPT-3054 point 3), never to a second form.
	 *
	 * @param string $placement Where the button was (banner, report-wait, …), kept for the form.
	 * @return string Admin URL of the Pro page, on the form anchor.
	 */
	public static function form_url( $placement = '' ) {
		$args = array( 'page' => self::PAGE_SLUG );

		$placement = sanitize_key( (string) $placement );
		if ( '' !== $placement ) {
			$args['ob_pro_from'] = $placement;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) ) . '#' . self::ANCHOR;
	}

	/**
	 * Base URL of the API, the same one the broadcast call uses (local / prod).
	 *
	 * @return string With a trailing slash.
	 */
	public static function api_base() {
		if ( class_exists( 'Opti_Behavior_Broadcast' ) ) {
			return Opti_Behavior_Broadcast::api_base();
		}

		return 'https://api.optiuser.com/';
	}

	/**
	 * Full URL of the sign-up endpoint.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return self::api_base() . self::ENDPOINT;
	}

	/**
	 * Fallback address when the endpoint answers 404 or cannot be reached: the
	 * trial page of optiuser.com, which has always worked.
	 *
	 * @param string $placement utm_medium of the link.
	 * @return string
	 */
	public static function fallback_url( $placement = 'pro-signup-fallback' ) {
		return class_exists( 'Opti_Behavior_Trial_Invite' )
			? Opti_Behavior_Trial_Invite::link_url( $placement )
			: '';
	}

	/**
	 * Email proposed in the field: the one the admin already told us.
	 *
	 * @return string
	 */
	public static function default_email() {
		if ( class_exists( 'Opti_Behavior_Heatmap_Admin_Email_Resolver' ) ) {
			$email = (string) Opti_Behavior_Heatmap_Admin_Email_Resolver::get_email();
			if ( '' !== $email ) {
				return $email;
			}
		}

		return (string) get_option( 'admin_email', '' );
	}

	/**
	 * Print THE form (nothing is sent before the click).
	 *
	 * @param string $placement utm_medium written on the request (pro-page, banner…).
	 * @param bool   $show_demo Print the "See it live in the demo" button. False on the
	 *                          welcome screen, where HX wants ONE Pro button (OPT-3109).
	 * @return void
	 */
	public static function render_form( $placement = 'pro-page', $show_demo = true ) {
		$placement = sanitize_key( (string) $placement );
		if ( '' === $placement ) {
			$placement = 'pro-page';
		}

		$from = isset( $_GET['ob_pro_from'] ) ? sanitize_key( wp_unslash( $_GET['ob_pro_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only label of the screen the button was on, no action taken.
		if ( '' !== $from ) {
			$placement = $from;
		}
		?>
		<form class="ob-pro-trial-form ob-pro-signup" id="ob-pro-trial-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ); ?>" />
			<input type="hidden" name="placement" value="<?php echo esc_attr( $placement ); ?>" />
			<?php wp_nonce_field( self::NONCE, 'nonce' ); ?>
			<p class="ob-welcome-field">
				<label for="ob-pro-trial-email"><?php esc_html_e( 'Email for your trial licence', 'opti-behavior' ); ?></label>
				<input type="email" id="ob-pro-trial-email" name="email" value="<?php echo esc_attr( self::default_email() ); ?>" autocomplete="email" required />
			</p>
			<p class="ob-welcome-field ob-welcome-field--site">
				<label for="ob-pro-trial-site"><?php esc_html_e( 'Site', 'opti-behavior' ); ?></label>
				<input type="text" id="ob-pro-trial-site" name="site" value="<?php echo esc_attr( home_url( '/' ) ); ?>" readonly />
			</p>
			<div class="ob-pro-cta__actions">
				<?php // OPT-3109 (HX 2026-11-08): the button says what you get, not how long. ?>
				<button type="submit" class="ob-welcome-cta" id="ob-pro-start-trial-btn"><?php esc_html_e( 'Get Pro free', 'opti-behavior' ); ?></button>
				<?php if ( $show_demo ) : ?>
				<a href="https://demo.optiuser.com/?demo_login=go" class="ob-pro-demo-btn" target="_blank" rel="noopener">
					<i data-lucide="play-circle"></i>
					<?php esc_html_e( 'See it live in the demo', 'opti-behavior' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'opti-behavior' ); ?></span>
				</a>
				<?php endif; ?>
			</div>
			<?php
			/*
			 * OPT-3109 (HX 2026-11-08), word for word: WHY the admin has to
			 * install Pro by hand (wordpress.org guideline 8 forbids a free
			 * plugin from installing it), and that nothing is billed.
			 */
			?>
			<p class="ob-pro-signup__why"><?php esc_html_e( 'We e-mail you the Pro download link. Install it in Plugins › Add New › Upload: WordPress does not let a free plugin install Pro by itself. No card. Nothing is billed.', 'opti-behavior' ); ?></p>
			<?php
			/*
			 * The sentence of the Juriste, word for word (OPT-2983 / OPT-2990):
			 * one single wording for the whole plugin, printed by the welcome
			 * class so there is one place to change it.
			 */
			if ( class_exists( 'Opti_Behavior_Welcome' ) ) {
				Opti_Behavior_Welcome::render_trial_privacy_text();
			}
			?>
			<p class="ob-pro-signup__msg" id="ob-pro-signup-msg" role="status" aria-live="polite" hidden></p>
		</form>
		<?php
	}

	/**
	 * Answer the click: post the email + the site address to the API.
	 *
	 * @return void
	 */
	public static function ajax_signup() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to ask for a trial on this site.', 'opti-behavior' ) ), 403 );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'That email address does not look right. Check it and click again.', 'opti-behavior' ) ) );
		}

		if ( ! self::take_try() ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Too many tries in the last hour. Wait a little, or open the trial page.', 'opti-behavior' ),
					'fallback' => self::fallback_url(),
				)
			);
		}

		$placement = isset( $_POST['placement'] ) ? sanitize_key( wp_unslash( $_POST['placement'] ) ) : 'pro-page';

		$answer = self::call_api( $email, $placement );

		if ( is_wp_error( $answer ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'optiuser.com could not be reached. Open the trial page to finish, or try again in a minute.', 'opti-behavior' ),
					'fallback' => self::fallback_url(),
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $answer );
		$body = json_decode( (string) wp_remote_retrieve_body( $answer ), true );
		$said = ( is_array( $body ) && ! empty( $body['message'] ) ) ? sanitize_text_field( (string) $body['message'] ) : '';

		if ( 200 === $code || 201 === $code ) {
			$ok = ! is_array( $body ) || ! isset( $body['success'] ) || ! empty( $body['success'] );
			if ( $ok ) {
				/*
				 * OPT-3109 (HX 2026-11-08): the message names the address the
				 * mail went to, and the welcome screen stops standing in front
				 * of the plugin pages — the admin answered it by clicking here.
				 */
				if ( class_exists( 'Opti_Behavior_Welcome' ) ) {
					Opti_Behavior_Welcome::mark_choice_made_by_pro_signup();
				}
				/*
				 * OPT-3109 (HX 2026-11-08, 21h35): the answer also offers the
				 * direct way in — the customer account where Pro is downloaded —
				 * next to the e-mail.
				 */
				wp_send_json_success(
					array(
						'message'      => sprintf(
							/* translators: %s: email address the admin typed. */
							__( 'Done! Your Pro licence is ready. Check %s, or download Pro now from your account:', 'opti-behavior' ),
							$email
						),
						'accountUrl'   => self::login_url_from_answer( $body ),
						'accountLabel' => __( 'Open my account →', 'opti-behavior' ),
					)
				);
			}

			wp_send_json_error(
				array(
					'message'  => ( '' !== $said ) ? $said : __( 'optiuser.com could not create the trial. Open the trial page to finish.', 'opti-behavior' ),
					'fallback' => self::fallback_url(),
				)
			);
		}

		if ( 429 === $code ) {
			wp_send_json_error( array( 'message' => ( '' !== $said ) ? $said : __( 'Too many tries for this site. Wait a little before asking again.', 'opti-behavior' ) ) );
		}

		if ( 400 === $code || 409 === $code || 422 === $code ) {
			wp_send_json_error(
				array(
					'message'  => ( '' !== $said ) ? $said : __( 'optiuser.com refused the request. Open the trial page to finish.', 'opti-behavior' ),
					'fallback' => self::fallback_url(),
				)
			);
		}

		// 404 (endpoint not there yet) and every other code: the old path.
		wp_send_json_error(
			array(
				'message'  => __( 'The one-click trial is not available right now. Open the trial page to finish in your browser.', 'opti-behavior' ),
				'fallback' => self::fallback_url(),
			)
		);
	}

	/**
	 * Where the "Open my account" button goes (OPT-3135, HX 2026-11-09).
	 *
	 * The API answer of /pro-signup may carry `login_url`: a one-time link that
	 * logs the new customer in on optiuser.com. It is used as it comes and
	 * never kept anywhere (no option, no transient, no log) — the admin clicks
	 * it once, right after the sign-up. Anything else (no field, another host,
	 * plain http, a login_url of a second sign-up the API refused to renew)
	 * falls back to the normal account page.
	 *
	 * @param mixed $body Decoded body of the API answer.
	 * @return string Absolute https URL on optiuser.com.
	 */
	public static function login_url_from_answer( $body ) {
		if ( ! is_array( $body ) || empty( $body['login_url'] ) || ! is_string( $body['login_url'] ) ) {
			return self::ACCOUNT_URL;
		}

		$url = esc_url_raw( trim( $body['login_url'] ) );
		if ( '' === $url ) {
			return self::ACCOUNT_URL;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::ACCOUNT_URL;
		}

		if ( ! in_array( strtolower( $parts['host'] ), self::LOGIN_HOSTS, true ) ) {
			return self::ACCOUNT_URL;
		}

		return $url;
	}

	/**
	 * The call itself. Separate so a test can watch it.
	 *
	 * @param string $email     Address the admin typed.
	 * @param string $placement Where the button was.
	 * @return array|WP_Error
	 */
	protected static function call_api( $email, $placement ) {
		return wp_remote_post(
			self::endpoint_url(),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'email'     => $email,
						// The API reads 'site_url' (OPT-3127); 'site' stays for older copies of it.
						'site_url'  => home_url( '/' ),
						'site'      => home_url( '/' ),
						'source'    => 'wp-plugin',
						'placement' => $placement,
						'plugin'    => defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '',
					)
				),
			)
		);
	}

	/**
	 * Count the clicks of this admin: five an hour, then a clear message.
	 *
	 * The counter is a transient of the user, never the email.
	 *
	 * @return bool True when the click may go out.
	 */
	private static function take_try() {
		$key   = 'opti_behavior_pro_signup_' . get_current_user_id();
		$tries = (int) get_transient( $key );

		if ( $tries >= self::MAX_TRIES ) {
			return false;
		}

		set_transient( $key, $tries + 1, HOUR_IN_SECONDS );

		return true;
	}
}
