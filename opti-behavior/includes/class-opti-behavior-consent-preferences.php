<?php
/**
 * Cookie Preferences shortcode.
 *
 * `[opti_behavior_cookie_settings]` renders a preference centre a site owner can
 * place on any page (privacy policy, cookie policy, footer widget). It lets a
 * visitor see the choice they made on the consent banner and change it at any
 * time — GDPR art. 7(3): withdrawing consent must be as easy as giving it.
 *
 * The markup is fully server-rendered and cache-safe: nothing visitor-specific
 * is printed. The visitor's current choice is read from the
 * `optibehavior_consent` cookie by assets/js/opti-behavior-consent.js, which
 * also wires the controls.
 *
 * @package opti-behavior
 * @since   1.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opti_Behavior_Consent_Preferences {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'opti_behavior_cookie_settings';

	/**
	 * Default lifetime of the visitor's choice, in days (CNIL good practice: 6 months).
	 *
	 * @var int
	 */
	const DEFAULT_CONSENT_DAYS = 180;

	/**
	 * Register the shortcode.
	 *
	 * @since 1.9.1
	 */
	public static function register_hooks() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Allowed lifetimes for the visitor's choice, in days.
	 *
	 * @since 1.9.1
	 * @return int[]
	 */
	public static function allowed_consent_days() {
		return array( 180, 365 );
	}

	/**
	 * Lifetime of the visitor's choice, in days.
	 *
	 * @since 1.9.1
	 * @param array $options Plugin options array.
	 * @return int
	 */
	public static function get_consent_days( $options ) {
		$days = isset( $options['consent_cookie_days'] ) ? (int) $options['consent_cookie_days'] : self::DEFAULT_CONSENT_DAYS;
		if ( ! in_array( $days, self::allowed_consent_days(), true ) ) {
			$days = self::DEFAULT_CONSENT_DAYS;
		}

		/**
		 * Filter the lifetime of the visitor's consent choice.
		 *
		 * @since 1.9.1
		 * @param int   $days    Lifetime in days.
		 * @param array $options Plugin options array.
		 */
		return max( 1, (int) apply_filters( 'opti_behavior_consent_cookie_days', $days, $options ) );
	}

	/**
	 * Plugin options array.
	 *
	 * @since 1.9.1
	 * @return array
	 */
	private static function get_options() {
		$options = maybe_unserialize( get_option( 'opti_behavior_heatmap_option', array() ) );
		return is_array( $options ) ? $options : array();
	}

	/**
	 * Which consent system is in charge for visitors.
	 *
	 * Mirrors the decision made in
	 * Opti_Behavior_Heatmap_Frontend::enqueue_consent_assets().
	 *
	 * @since 1.9.1
	 * @param array $options Plugin options array.
	 * @return array { mode: anonymous|builtin|thirdparty, label: string }
	 */
	public static function get_consent_context( $options ) {
		$privacy_mode = isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous';
		if ( 'full' !== $privacy_mode ) {
			return array(
				'mode'  => 'anonymous',
				'label' => '',
			);
		}

		$prefer = isset( $options['consent_banner_prefer'] ) ? $options['consent_banner_prefer'] : 'auto';
		if ( 'builtin' === $prefer ) {
			return array(
				'mode'  => 'builtin',
				'label' => '',
			);
		}

		$label = '';
		$slug  = null;
		if ( class_exists( 'Opti_Behavior_Consent_Detector' ) ) {
			$detector = new Opti_Behavior_Consent_Detector();
			$slug     = $detector->get_detected_plugin();
			$label    = (string) $detector->get_detected_plugin_label();
		}

		if ( 'thirdparty' === $prefer || null !== $slug ) {
			return array(
				'mode'  => 'thirdparty',
				'label' => $label,
			);
		}

		return array(
			'mode'  => 'builtin',
			'label' => '',
		);
	}

	/**
	 * Make sure the consent stylesheet is on the page (it carries the card styles).
	 *
	 * @since 1.9.1
	 */
	private static function enqueue_style() {
		if ( ! wp_style_is( 'opti-behavior-consent', 'registered' ) ) {
			$path = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/opti-behavior-consent.css';
			wp_register_style(
				'opti-behavior-consent',
				OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/opti-behavior-consent.css',
				array(),
				file_exists( $path ) ? (string) filemtime( $path ) : OPTI_BEHAVIOR_HEATMAP_VERSION
			);
		}
		wp_enqueue_style( 'opti-behavior-consent' );
	}

	/**
	 * Render the shortcode.
	 *
	 * Attributes: `display` (card | link | button), `label` (link / button
	 * text), `title`, `description` (override the saved texts).
	 *
	 * @since 1.9.1
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'display'     => 'card',
				'label'       => '',
				'title'       => '',
				'description' => '',
			),
			$atts,
			self::SHORTCODE
		);

		$options = self::get_options();
		$context = self::get_consent_context( $options );
		$display = in_array( $atts['display'], array( 'card', 'link', 'button' ), true ) ? $atts['display'] : 'card';

		self::enqueue_style();

		if ( 'card' !== $display ) {
			return self::render_trigger( $display, $atts, $context );
		}

		$title = '' !== $atts['title'] ? $atts['title'] : ( ! empty( $options['consent_prefs_title'] ) ? $options['consent_prefs_title'] : __( 'Cookie preferences', 'opti-behavior' ) );

		if ( 'anonymous' === $context['mode'] ) {
			return self::render_notice_card(
				$title,
				__( 'No consent needed', 'opti-behavior' ),
				__( 'This website measures its audience with anonymous, cookie-free statistics. No analytics cookie is stored on your device, so there is nothing to accept or refuse.', 'opti-behavior' )
			);
		}

		if ( 'thirdparty' === $context['mode'] ) {
			$message = '' !== $context['label']
				/* translators: %s: name of the cookie consent plugin managing the banner. */
				? sprintf( __( 'Your cookie choices on this website are managed by %s. Use its settings button to review or change your choice at any time.', 'opti-behavior' ), $context['label'] )
				: __( 'Your cookie choices on this website are managed by our cookie consent tool. Use its settings button to review or change your choice at any time.', 'opti-behavior' );

			return self::render_notice_card( $title, __( 'Managed by the cookie banner', 'opti-behavior' ), $message );
		}

		return self::render_preferences_card( $title, $atts, $options );
	}

	/**
	 * Link / button that reopens the consent banner.
	 *
	 * @since 1.9.1
	 * @param string $display link | button.
	 * @param array  $atts    Shortcode attributes.
	 * @param array  $context Consent context.
	 * @return string HTML.
	 */
	private static function render_trigger( $display, $atts, $context ) {
		// Nothing to reopen unless the built-in banner is in charge.
		if ( 'builtin' !== $context['mode'] ) {
			return '';
		}

		$label = '' !== $atts['label'] ? $atts['label'] : __( 'Manage my cookies', 'opti-behavior' );
		$class = 'button' === $display ? 'ob-cookie-prefs-trigger ob-cookie-prefs-trigger--button' : 'ob-cookie-prefs-trigger';

		return sprintf(
			'<a href="#" role="button" class="%1$s" data-ob-consent-open="1">%2$s</a>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	/**
	 * Informational card (Anonymous Mode / third-party banner).
	 *
	 * @since 1.9.1
	 * @param string $title   Card title.
	 * @param string $status  Status pill text.
	 * @param string $message Card body.
	 * @return string HTML.
	 */
	private static function render_notice_card( $title, $status, $message ) {
		ob_start();
		?>
		<section class="ob-cookie-prefs ob-cookie-prefs--notice" aria-label="<?php echo esc_attr( $title ); ?>">
			<header class="ob-cookie-prefs__header">
				<span class="ob-cookie-prefs__icon" aria-hidden="true"><?php echo self::shield_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG. ?></span>
				<h3 class="ob-cookie-prefs__title"><?php echo esc_html( $title ); ?></h3>
				<span class="ob-cookie-prefs__status ob-cookie-prefs__status--granted"><?php echo esc_html( $status ); ?></span>
			</header>
			<p class="ob-cookie-prefs__intro"><?php echo esc_html( $message ); ?></p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Interactive preference centre (Full mode + built-in banner).
	 *
	 * @since 1.9.1
	 * @param string $title   Card title.
	 * @param array  $atts    Shortcode attributes.
	 * @param array  $options Plugin options array.
	 * @return string HTML.
	 */
	private static function render_preferences_card( $title, $atts, $options ) {
		static $instance = 0;
		$instance++;
		$uid = 'ob-cookie-prefs-' . $instance;

		$description = '' !== $atts['description']
			? $atts['description']
			: ( ! empty( $options['consent_prefs_description'] )
				? $options['consent_prefs_description']
				: __( 'Choose whether we may use analytics cookies. You can change your mind at any time — your new choice applies immediately.', 'opti-behavior' ) );

		$analytics_label = ! empty( $options['consent_banner_analytics_label'] ) ? $options['consent_banner_analytics_label'] : __( 'Analytics cookies', 'opti-behavior' );
		$analytics_desc  = ! empty( $options['consent_banner_analytics_description'] ) ? $options['consent_banner_analytics_description'] : __( 'Help us understand how visitors interact with our site by collecting anonymous usage data.', 'opti-behavior' );
		$accept_label    = ! empty( $options['consent_banner_accept_label'] ) ? $options['consent_banner_accept_label'] : __( 'Accept All', 'opti-behavior' );
		$reject_label    = ! empty( $options['consent_banner_reject_label'] ) ? $options['consent_banner_reject_label'] : __( 'Reject All', 'opti-behavior' );
		$save_label      = ! empty( $options['consent_banner_save_label'] ) ? $options['consent_banner_save_label'] : __( 'Save my choice', 'opti-behavior' );
		$policy_label    = ! empty( $options['consent_banner_policy_label'] ) ? $options['consent_banner_policy_label'] : __( 'Cookie Policy', 'opti-behavior' );
		$policy_url      = ! empty( $options['consent_banner_policy_url'] ) ? $options['consent_banner_policy_url'] : ( function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '' );

		$months = (int) round( self::get_consent_days( $options ) / 30 );
		/* translators: %d: number of months the visitor's cookie choice is remembered. */
		$duration = sprintf( _n( 'Your choice is remembered for %d month.', 'Your choice is remembered for %d months.', $months, 'opti-behavior' ), $months );

		ob_start();
		?>
		<section class="ob-cookie-prefs" id="<?php echo esc_attr( $uid ); ?>" data-ob-cookie-prefs="1" aria-labelledby="<?php echo esc_attr( $uid ); ?>-title"
			data-label-granted="<?php esc_attr_e( 'Accepted', 'opti-behavior' ); ?>"
			data-label-rejected="<?php esc_attr_e( 'Refused', 'opti-behavior' ); ?>"
			data-label-pending="<?php esc_attr_e( 'No choice yet', 'opti-behavior' ); ?>"
			data-label-saved="<?php esc_attr_e( 'Your choice has been saved.', 'opti-behavior' ); ?>">
			<header class="ob-cookie-prefs__header">
				<span class="ob-cookie-prefs__icon" aria-hidden="true"><?php echo self::shield_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG. ?></span>
				<h3 class="ob-cookie-prefs__title" id="<?php echo esc_attr( $uid ); ?>-title"><?php echo esc_html( $title ); ?></h3>
				<span class="ob-cookie-prefs__status ob-cookie-prefs__status--pending" data-ob-status="1"><?php esc_html_e( 'No choice yet', 'opti-behavior' ); ?></span>
			</header>

			<p class="ob-cookie-prefs__intro"><?php echo esc_html( $description ); ?></p>

			<ul class="ob-cookie-prefs__list">
				<li class="ob-cookie-prefs__row">
					<div class="ob-cookie-prefs__row-text">
						<span class="ob-cookie-prefs__row-title"><?php esc_html_e( 'Essential cookies', 'opti-behavior' ); ?></span>
						<span class="ob-cookie-prefs__row-desc"><?php esc_html_e( 'Required for the website to work and to remember your cookie choice. They cannot be switched off.', 'opti-behavior' ); ?></span>
					</div>
					<span class="ob-cookie-prefs__always"><?php esc_html_e( 'Always active', 'opti-behavior' ); ?></span>
				</li>
				<li class="ob-cookie-prefs__row">
					<div class="ob-cookie-prefs__row-text">
						<label class="ob-cookie-prefs__row-title" for="<?php echo esc_attr( $uid ); ?>-analytics"><?php echo esc_html( $analytics_label ); ?></label>
						<span class="ob-cookie-prefs__row-desc"><?php echo esc_html( $analytics_desc ); ?></span>
					</div>
					<span class="ob-cookie-prefs__switch">
						<input type="checkbox" id="<?php echo esc_attr( $uid ); ?>-analytics" class="ob-cookie-prefs__checkbox" data-ob-analytics="1" disabled>
						<span class="ob-cookie-prefs__slider" aria-hidden="true"></span>
					</span>
				</li>
			</ul>

			<div class="ob-cookie-prefs__actions">
				<button type="button" class="ob-cookie-prefs__btn ob-cookie-prefs__btn--ghost" data-ob-action="reject" disabled><?php echo esc_html( $reject_label ); ?></button>
				<button type="button" class="ob-cookie-prefs__btn ob-cookie-prefs__btn--ghost" data-ob-action="accept" disabled><?php echo esc_html( $accept_label ); ?></button>
				<button type="button" class="ob-cookie-prefs__btn ob-cookie-prefs__btn--primary" data-ob-action="save" disabled><?php echo esc_html( $save_label ); ?></button>
			</div>

			<p class="ob-cookie-prefs__feedback" data-ob-feedback="1" role="status" aria-live="polite"></p>

			<p class="ob-cookie-prefs__note" data-ob-inactive="1"><?php esc_html_e( 'Analytics is not active for your visit, so there is nothing to choose right now.', 'opti-behavior' ); ?></p>
			<p class="ob-cookie-prefs__note" data-ob-admin-note="1" hidden><?php esc_html_e( 'You are logged in as an administrator: consent is always granted for you. Your visitors can change their choice here.', 'opti-behavior' ); ?></p>

			<footer class="ob-cookie-prefs__footer">
				<span><?php echo esc_html( $duration ); ?></span>
				<?php if ( ! empty( $policy_url ) ) : ?>
					<a class="ob-cookie-prefs__policy" href="<?php echo esc_url( $policy_url ); ?>"><?php echo esc_html( $policy_label ); ?></a>
				<?php endif; ?>
			</footer>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Inline shield icon.
	 *
	 * @since 1.9.1
	 * @return string SVG markup.
	 */
	private static function shield_icon() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>';
	}
}
