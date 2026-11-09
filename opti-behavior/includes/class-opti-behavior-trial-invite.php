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
	 * Recorded sessions a month of the free Pro plan, DISPLAY ONLY, NEVER
	 * ENFORCED (OPT-3026): it is the sentence of the offer and of the readme,
	 * like a price on a page. The limit that really applies comes from the API
	 * alone (OPT-3025 / OPT-3006); no Free or Pro code must ever compare a
	 * counter with this number.
	 *
	 * OPT-3054 (HX 2026-11-07): the figure is no longer written in the plugin.
	 * It is the one the API states (`pro_free_monthly_sessions` of the
	 * broadcast answer, kept by `Opti_Behavior_Broadcast`); when there is none
	 * to show, every sentence is printed WITHOUT a figure.
	 *
	 * OPT-3109 (HX 2026-11-08): a sentence without a figure was not clear, so
	 * the offer always shows a number. The API figure wins; when the API has
	 * said nothing yet, the DISPLAY fallback below is printed. Still never
	 * enforced anywhere.
	 *
	 * @return int Sessions a month to print.
	 */
	public static function free_sessions() {
		if ( class_exists( 'Opti_Behavior_Broadcast' ) ) {
			$said = Opti_Behavior_Broadcast::free_monthly_sessions();
			if ( is_numeric( $said ) && (int) $said > 0 ) {
				return (int) $said;
			}
		}

		return self::FALLBACK_SESSIONS;
	}

	/**
	 * Sessions a month printed when the API has not stated a figure yet
	 * (OPT-3109). Display only, never compared with any counter.
	 */
	const FALLBACK_SESSIONS = 1000;

	/**
	 * Fallback figure of the trust strip, kept empty on purpose (OPT-3042): the
	 * rating and the active installs are read from wordpress.org itself
	 * (`wporg_info()`), never written by hand. '' = nothing to show → the
	 * element is not printed.
	 */
	const ACTIVE_INSTALLS = '';

	/**
	 * This plugin on wordpress.org.
	 */
	const WPORG_SLUG = 'opti-behavior';

	/**
	 * Transient holding the wordpress.org answer (rating + active installs).
	 */
	const WPORG_CACHE_KEY = 'opti_behavior_wporg_info';

	/**
	 * One-off cron that reads wordpress.org in the background (never at render).
	 */
	const REFRESH_HOOK = 'opti_behavior_wporg_info_refresh';

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
		</p>
		<?php
	}

	/**
	 * The two offer lines, word for word, of the welcome screen and of the
	 * four locked Pro pages (OPT-3026). ONE place: both callers print this.
	 *
	 * OPT-3051 (HX 2026-11-07): the block said "6 months free" three times on
	 * its own and a fourth time under the button. It now says the trial once
	 * (the button alone) and keeps only what comes AFTER the trial.
	 *
	 * @return array List of sentences, in printing order.
	 */
	public static function offer_lines() {
		$sessions = self::free_sessions();

		/*
		 * OPT-3109 (HX 2026-11-08, word for word): WHERE the Free plugin looks,
		 * WHY the Pro module is needed, and what the offer is — the figure comes
		 * from GET /offer (display fallback 1,000), never from a literal.
		 */
		return array(
			__( 'This free plugin shows WHERE visitors click and leave: unlimited, forever.', 'opti-behavior' ),
			__( 'The Pro module shows WHY: session replays, form drop-offs, JavaScript errors, user journeys, Page X-Ray and full Smart Insights.', 'opti-behavior' ),
			sprintf(
				/* translators: %s: number of recorded sessions per month, e.g. 1,000 */
				__( 'Months 1 to 6: Pro unlimited. After that, Pro stays free up to %s recorded sessions a month on 1 site. Need more? Paid plans are unlimited.', 'opti-behavior' ),
				number_format_i18n( $sessions )
			),
		);
	}

	/**
	 * The title of the single Pro block (OPT-3109).
	 *
	 * @return string
	 */
	public static function offer_title() {
		return __( 'Get the Pro module free — see WHY visitors leave', 'opti-behavior' );
	}

	/**
	 * Print THE single Pro block: title + the three offer lines, right above the
	 * Pro button.
	 *
	 * OPT-3109 (HX 2026-11-08): this one block replaces the amber "Smart Insights
	 * needs the Pro modules" box and the "After 6 months…" offer box, which said
	 * the same thing twice without ever saying WHY Pro has to be installed by hand.
	 * ONE function: the welcome screen and the locked Pro pages print the same words.
	 *
	 * @return void
	 */
	public static function render_offer() {
		$lines = self::offer_lines();
		?>
		<div class="ob-pro-offer">
			<p class="ob-pro-offer__title"><?php echo esc_html( self::offer_title() ); ?></p>
			<ul class="ob-pro-offer__list">
				<?php foreach ( $lines as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Rating and active installs read on wordpress.org (OPT-3042).
	 *
	 * The figures are NEVER written by hand: they come from `plugins_api()`,
	 * the function the Plugins › Add New screen of WordPress itself uses, and
	 * are kept in a transient for 24 h. **The screen never calls
	 * wordpress.org while it renders** (OPT-259 rule: no HTTP request at
	 * render): a missing cache only queues the one-off cron
	 * `REFRESH_HOOK`, and until it has run the trust strip simply drops the
	 * rating and the installs. A failed call is remembered for one hour.
	 *
	 * @return array { installs: int, rating: float (0-100), num_ratings: int }
	 */
	public static function wporg_info() {
		/**
		 * Filters the wordpress.org answer (test seam; null = read it).
		 *
		 * @param array|null $info { installs, rating, num_ratings }.
		 */
		$pre = apply_filters( 'opti_behavior_wporg_plugin_info', null );
		if ( null !== $pre ) {
			return self::clean_wporg_info( is_array( $pre ) ? $pre : array() );
		}

		$cached = get_transient( self::WPORG_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return self::clean_wporg_info( $cached );
		}

		return self::clean_wporg_info( array() );
	}

	/**
	 * Register the background refresh of the wordpress.org figures.
	 *
	 * Called from the admin block of Opti-Behavior.php.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh_wporg' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_queue_wporg_refresh' ) );
	}

	/**
	 * Queue one background read of wordpress.org when the cache is empty.
	 *
	 * On `admin_init` only: a render never writes anything (QA rule of the Pro
	 * pages, OPT-701 row QA-05 = 0 DB write, 0 HTTP request at render).
	 *
	 * @return void
	 */
	public static function maybe_queue_wporg_refresh() {
		if ( false !== get_transient( self::WPORG_CACHE_KEY ) ) {
			return;
		}
		if ( wp_next_scheduled( self::REFRESH_HOOK ) ) {
			return;
		}
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return;
		}

		wp_schedule_single_event( time() + 30, self::REFRESH_HOOK );
	}

	/**
	 * Cron: one `plugins_api()` call, cached (24 h on success, 1 h on failure).
	 *
	 * @return array
	 */
	public static function refresh_wporg() {
		if ( ! function_exists( 'plugins_api' ) ) {
			$file = ABSPATH . 'wp-admin/includes/plugin-install.php';
			if ( ! file_exists( $file ) ) {
				return array();
			}
			require_once $file;
		}

		$answer = plugins_api(
			'plugin_information',
			array(
				'slug'   => self::WPORG_SLUG,
				'fields' => array(
					'active_installs' => true,
					'rating'          => true,
					'num_ratings'     => true,
				),
			)
		);

		if ( is_wp_error( $answer ) || ! is_object( $answer ) || ! isset( $answer->active_installs ) ) {
			set_transient( self::WPORG_CACHE_KEY, 'failed', HOUR_IN_SECONDS );
			return array();
		}

		$info = array(
			'installs'    => isset( $answer->active_installs ) ? (int) $answer->active_installs : 0,
			'rating'      => isset( $answer->rating ) ? (float) $answer->rating : 0,
			'num_ratings' => isset( $answer->num_ratings ) ? (int) $answer->num_ratings : 0,
		);
		set_transient( self::WPORG_CACHE_KEY, $info, DAY_IN_SECONDS );

		return $info;
	}

	/**
	 * Shape of the wordpress.org answer, whatever came in.
	 *
	 * @param array $info Raw answer.
	 * @return array
	 */
	private static function clean_wporg_info( $info ) {
		$rating = isset( $info['rating'] ) ? (float) $info['rating'] : 0;
		$rating = max( 0, min( 100, $rating ) );

		return array(
			'installs'    => isset( $info['installs'] ) ? max( 0, (int) $info['installs'] ) : 0,
			'rating'      => $rating,
			'num_ratings' => isset( $info['num_ratings'] ) ? max( 0, (int) $info['num_ratings'] ) : 0,
		);
	}

	/**
	 * Rating of the trust strip, as printed ('5.0'), or '' when wordpress.org
	 * shows none (no rating yet, call failed and nothing cached).
	 *
	 * wordpress.org gives the rating out of 100: 100 = 5.0.
	 *
	 * @return string
	 */
	public static function wporg_rating() {
		$info = self::wporg_info();
		if ( $info['rating'] <= 0 || $info['num_ratings'] < 1 ) {
			return '';
		}

		return number_format_i18n( $info['rating'] / 20, 1 );
	}

	/**
	 * Number of full stars to draw next to the rating (0-5, decorative).
	 *
	 * @return int
	 */
	public static function wporg_stars() {
		$info = self::wporg_info();
		if ( $info['rating'] <= 0 || $info['num_ratings'] < 1 ) {
			return 0;
		}

		return (int) max( 1, min( 5, (int) round( $info['rating'] / 20 ) ) );
	}

	/**
	 * Number of active installs shown by the trust strip, as a plain string.
	 *
	 * Read on wordpress.org (`wporg_info()`) and printed the way wordpress.org
	 * prints it ('2,000+'): NEVER invented, never rounded up. '' means
	 * wordpress.org shows nothing → the element is skipped (OPT-3026). Filter
	 * `opti_behavior_active_installs` stays, for a build or a test.
	 *
	 * @return string
	 */
	public static function active_installs() {
		$installs = (string) apply_filters( 'opti_behavior_active_installs', self::wporg_installs_text() );
		$installs = trim( $installs );

		return preg_match( '/^[0-9][0-9,.\s]*\+?$/', $installs ) ? $installs : '';
	}

	/**
	 * Active installs of wordpress.org in the wordpress.org format ('2,000+').
	 *
	 * The API already answers the rounded-down bucket it shows on the plugin
	 * page (10, 20, … 1 000, 2 000, 10 000): nothing is rounded here. Under 10
	 * installs wordpress.org shows no figure, so neither do we.
	 *
	 * @return string
	 */
	private static function wporg_installs_text() {
		$installs = self::wporg_info()['installs'];
		if ( $installs < 10 ) {
			return self::ACTIVE_INSTALLS;
		}

		return number_format_i18n( $installs ) . '+';
	}

	/**
	 * Print the trust strip shown under the Pro button (OPT-3026): rating,
	 * active installs, WooCommerce, self-hosted.
	 *
	 * Every picture is an inline SVG of this plugin: nothing is loaded from
	 * wordpress.org or from any other server while the screen renders.
	 *
	 * @return void
	 */
	public static function render_trust_strip() {
		$installs = self::active_installs();
		$rating   = self::wporg_rating();
		$items    = array();

		if ( '' !== $rating ) {
			$items[] = array(
				'mod'   => 'rating',
				'icon'  => 'wordpress',
				'num'   => $rating,
				'stars' => self::wporg_stars(),
				'sub'   => __( 'Rated on WordPress.org', 'opti-behavior' ),
			);
		}

		if ( '' !== $installs ) {
			$items[] = array(
				'mod'   => 'installs',
				'icon'  => 'trend',
				'title' => sprintf(
					/* translators: %s: number of active installs as shown on wordpress.org, e.g. 2,000+ */
					__( '%s active installs', 'opti-behavior' ),
					$installs
				),
				'sub'   => __( 'Sites running Opti-Behavior today', 'opti-behavior' ),
			);
		}

		$items[] = array(
			'icon'  => 'woocommerce',
			'title' => __( 'Built for WooCommerce', 'opti-behavior' ),
			'sub'   => __( 'Checkout, cart and product-page funnels', 'opti-behavior' ),
		);
		$items[] = array(
			'icon'  => 'shield',
			'title' => __( 'Self-hosted', 'opti-behavior' ),
			'sub'   => __( 'Recordings and analytics stay on your own server — no cookies required', 'opti-behavior' ),
		);
		?>
		<ul class="ob-trust">
			<?php foreach ( $items as $item ) : ?>
			<li class="ob-trust__item<?php echo isset( $item['mod'] ) ? ' ob-trust__item--' . esc_attr( $item['mod'] ) : ''; ?>">
				<span class="ob-trust__icon" aria-hidden="true"><?php self::trust_icon( $item['icon'] ); ?></span>
				<span class="ob-trust__text">
					<span class="ob-trust__title">
						<?php if ( isset( $item['num'] ) ) : ?>
							<span class="ob-trust__num"><?php echo esc_html( $item['num'] ); ?></span>
							<span class="ob-trust__stars" aria-hidden="true"><?php self::trust_stars( (int) $item['stars'] ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $item['title'] ); ?>
						<?php endif; ?>
					</span>
					<span class="ob-trust__sub"><?php echo esc_html( $item['sub'] ); ?></span>
				</span>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Print nothing (OPT-3109, HX 2026-11-08).
	 *
	 * The amber "Smart Insights needs the Pro modules" box of OPT-3042 said the
	 * same thing as the offer box right under it. HX asked for ONE block, so the
	 * words moved into `render_offer()`. The method stays as a no-op: it is called
	 * by older tests and by the Pro repo, and an empty box is the wanted result.
	 *
	 * @return void
	 */
	public static function render_need_box() {
	}

	/**
	 * Print the gold stars of the rating (decorative, local SVG).
	 *
	 * @param int $stars Number of stars, 0-5.
	 * @return void
	 */
	private static function trust_stars( $stars ) {
		$stars = max( 0, min( 5, (int) $stars ) );
		for ( $i = 0; $i < $stars; $i++ ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal SVG markup, no variable part.
			echo '<svg viewBox="0 0 24 24" width="13" height="13" focusable="false" aria-hidden="true"><path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4 6.1 20.5l1.2-6.5L2.5 9.4l6.6-.9z"/></svg>';
		}
	}

	/**
	 * Print one inline SVG of the trust strip (local, decorative).
	 *
	 * @param string $name wordpress|trend|alert|woocommerce|shield|users.
	 * @return void
	 */
	private static function trust_icon( $name ) {
		if ( 'alert' === $name ) {
			// Soft warning triangle of the "needs Pro" box (filled, not stroked).
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal SVG markup, no variable part.
			echo '<svg viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true"><path d="M12 3.2 1.8 20.8h20.4L12 3.2Zm0 5.4a1 1 0 0 1 1 1v4.6a1 1 0 0 1-2 0V9.6a1 1 0 0 1 1-1Zm0 8.1a1.15 1.15 0 1 1 0 2.3 1.15 1.15 0 0 1 0-2.3Z"/></svg>';
			return;
		}

		if ( 'wordpress' === $name ) {
			/*
			 * OPT-3051 (HX 2026-11-07): the strip used to draw two strokes that
			 * read like a typed "W". The real WordPress mark is the Dashicons
			 * glyph `dashicons-wordpress`, the one WordPress itself prints in
			 * the admin: no SVG of ours to keep in step with the brand, and the
			 * font is already loaded on every admin screen.
			 */
			echo '<span class="dashicons dashicons-wordpress ob-trust__dashicon" aria-hidden="true"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup, no variable part.
			return;
		}

		$open  = '<svg class="ob-trust__svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">';
		$icons = array(
			// Active installs: the rising curve of optiuser.com.
			'trend'       => '<path d="M3.2 17.6C6.4 16.8 8.8 15.2 11.2 12.8 13.6 10.4 16 7.2 20.8 4.8" stroke-width="2.1"/><circle cx="20.8" cy="4.8" r="1.9" fill="currentColor" stroke="none"/>',
			// WooCommerce: the rounded speech bubble with its double stroke.
			'woocommerce' => '<path d="M3.6 5h16.8c.9 0 1.6.7 1.6 1.6v7.2c0 .9-.7 1.6-1.6 1.6h-6.1l-3.4 3.4v-3.4H3.6c-.9 0-1.6-.7-1.6-1.6V6.6C2 5.7 2.7 5 3.6 5Z"/><path d="M6 8.4l1.5 4 1.5-4 1.5 4 1.5-4"/><path d="M15.2 8.4l1.3 4 1.3-4"/>',
			'shield'      => '<path d="M12 3l7 3v5.5c0 4.3-2.9 7.6-7 9.5-4.1-1.9-7-5.2-7-9.5V6l7-3Z"/><path d="M9.2 12.2l2 2 3.6-3.8"/>',
			'users'       => '<path d="M16 19v-1.6a3.4 3.4 0 0 0-3.4-3.4H6.4A3.4 3.4 0 0 0 3 17.4V19"/><circle cx="9.5" cy="7.5" r="3.2"/><path d="M21 19v-1.6a3.4 3.4 0 0 0-2.6-3.3"/><path d="M15.6 4.6a3.2 3.2 0 0 1 0 6.2"/>',
		);

		if ( ! isset( $icons[ $name ] ) ) {
			return;
		}

		echo $open . $icons[ $name ] . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal SVG markup of this file, no variable part.
	}
}
