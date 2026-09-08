<?php
/**
 * Funnel Site-Type Detector
 *
 * Builds the normalized `$context` array consumed by the funnel recipe registry
 * and the auto-builder: which commerce / content / lead families this site runs,
 * and the REAL, plugin-resolved URLs for each of them.
 *
 * Design rules (spec.md §2.1, §3.4 — non-negotiable):
 *
 * 1. **Never hardcode an English slug.** Every URL is resolved through the
 *    source plugin's own API (`wc_get_page_permalink`, `wc_get_endpoint_url`,
 *    `edd_get_checkout_uri`, `get_permalink`) so localized stores
 *    (`/panier/`, `/commander/`, `/kasse/`) work without any translation table.
 * 2. **Never guess a URL.** A family whose signal is present but whose URLs do
 *    not resolve is reported as *detected but unresolved*: it appears in
 *    `site_types` AND in `unresolved`, and carries no URL keys — so no recipe
 *    that `requires` those keys is ever offered.
 * 3. **Never emit the site home as a family URL.** A `contains` pattern equal to
 *    the home path matches every pageview (the exact defect covered by
 *    funnel-regression-test.php). Home-equal resolutions are dropped; the home
 *    path is exposed once, separately, under `site.home`.
 * 4. **Default language only.** WPML / Polylang are out of scope for v1
 *    (spec.md §4.5-b) — URLs are resolved for the default language.
 *
 * Result shape (contract — spec.md §2.1):
 *
 *     array(
 *       'site_types'  => array( 'woocommerce', 'blog' ), // ordered by confidence
 *       'unresolved'  => array( 'lead' ),                // detected, no usable URL
 *       'site'        => array( 'home' => '/', 'front_page_is_blog' => false ),
 *       'woocommerce' => array( 'shop' => '/shop/', 'cart' => '/panier/', ... ),
 *       'edd'         => array( 'archive' => '/downloads/', ... ),
 *       'blog'        => array( 'index' => '/blog/', ... ),
 *       'lead'        => array( 'contact' => '/contact/', 'form_plugin' => 'wpforms' ),
 *       'signup'      => array( 'pricing' => '/pricing/', 'register' => '/register/' ),
 *       'detected_at' => '2026-08-30 12:00:00',
 *       'forced'      => true,                           // ONLY on a forced pass
 *     );
 *
 * `forced` is ephemeral and request-scoped: it is set on the returned array of a
 * `detect( true )` call only, never written to the transient. See detect().
 *
 * A family key is present only when the family was detected. It contains the
 * URL keys that actually resolved (possibly a partial set) plus its meta keys
 * (`form_plugin`, `provider`).
 *
 * The membership / LMS / booking families are multi-provider: the first
 * provider whose signature class is present wins (declaration order = the
 * confidence order of spec.md §2.1) and is published as `provider`. Providers
 * that expose no page-id API at all (Amelia, Bookly) fall back to a single
 * capped, memoized shortcode/block content scan over published pages; a scan
 * that resolves nothing degrades the family to *detected but unresolved* — it
 * never yields a guessed URL.
 *
 * @package opti-behavior
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Funnel Site-Type Detector.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Funnel_Site_Detector {

	/**
	 * Transient holding the cached detection result (spec.md §4.3).
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'opti_behavior_funnel_site_context';

	/**
	 * Cache lifetime in seconds (12 hours).
	 *
	 * @var int
	 */
	const CACHE_TTL = 43200;

	/**
	 * Hard cap on any page lookup issued by a detector. Detection must never
	 * turn into an unbounded table scan on a large site.
	 *
	 * @var int
	 */
	const PAGE_SCAN_CAP = 200;

	/**
	 * Minimum number of published posts before a site counts as a blog. A stock
	 * install ships with the single "Hello world!" post, which is not a blog.
	 *
	 * @var int
	 */
	const MIN_BLOG_POSTS = 3;

	/**
	 * Singleton instance.
	 *
	 * @var Opti_Behavior_Funnel_Site_Detector|null
	 */
	private static $instance = null;

	/**
	 * Whether the invalidation hooks were already registered.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Memoized home path (site-relative, always trailing-slashed).
	 *
	 * @var string|null
	 */
	private $home_path = null;

	/**
	 * Memoized home host (lower-case, no port stripping).
	 *
	 * @var string|null
	 */
	private $home_host = null;

	/**
	 * Memoized result of the capped page-content scan (Amelia / Bookly).
	 *
	 * Null until the first scan. The scan is lazy — it only runs when a provider
	 * with no page-id API was actually detected — and it runs at most once per
	 * detection pass however many providers or URL keys consult it.
	 *
	 * @var array|null
	 */
	private $scanned_pages = null;

	/**
	 * Shared instance.
	 *
	 * @return Opti_Behavior_Funnel_Site_Detector
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register cache-invalidation hooks.
	 *
	 * Activating or deactivating ANY plugin can change the detected families or
	 * the resolved URLs (a store gets installed, a form plugin is removed), so
	 * the cached context is dropped on both events.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( self::$hooks_registered || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooks_registered = true;
		add_action( 'activated_plugin', array( __CLASS__, 'flush' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Drop the cached context. Safe to call as a hook callback (args ignored).
	 *
	 * @return void
	 */
	public static function flush() {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT_KEY );
		}
	}

	/**
	 * Detect the site context.
	 *
	 * A forced pass adds an ephemeral `forced => true` marker to the RETURNED
	 * context. It is deliberately added AFTER `set_transient()`, so it is never
	 * part of the cached payload and can never leak into a later ordinary read.
	 * The marker exists so downstream consumers that keep a cache of their own
	 * (the Pro traffic-mining transient) can tell a user-initiated "Re-scan site"
	 * apart from a plain cache miss and refresh instead of serving — the
	 * "a forced re-scan must see current traffic" rule of spec.md §3.5.
	 *
	 * A non-forced cache MISS does NOT set the marker: rebuilding an expired
	 * context is not a user-initiated re-scan.
	 *
	 * @param bool $force When true, bypass the transient and re-run detection.
	 * @return array Context array (see class docblock).
	 */
	public function detect( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) && isset( $cached['detected_at'] ) ) {
				return $this->filter_context( $cached );
			}
		}

		$context = $this->build_context();
		set_transient( self::TRANSIENT_KEY, $context, self::CACHE_TTL );

		if ( $force ) {
			$context['forced'] = true;
		}

		return $this->filter_context( $context );
	}

	/**
	 * Family slug => detector method, ordered by confidence.
	 *
	 * The order is the order of `site_types`. Later steps append the
	 * membership / LMS / booking families here; a listed method that does not
	 * exist is skipped, so the map can be extended incrementally.
	 *
	 * @return array<string,string>
	 */
	protected function get_detectors() {
		return array(
			'woocommerce' => 'detect_woocommerce',
			'edd'         => 'detect_edd',
			'membership'  => 'detect_membership',
			'lms'         => 'detect_lms',
			'booking'     => 'detect_booking',
			'lead'        => 'detect_lead',
			'signup'      => 'detect_signup',
			'blog'        => 'detect_blog',
		);
	}

	/**
	 * Keys of a family payload that are metadata, not URLs. They never count
	 * towards "this family resolved at least one URL".
	 *
	 * @return array<int,string>
	 */
	protected function get_meta_keys() {
		return array( 'form_plugin', 'provider' );
	}

	/**
	 * Run every detector and assemble the context.
	 *
	 * @return array
	 */
	protected function build_context() {
		// A forced re-scan must see the site as it is NOW, so the page-content
		// scan memo is dropped at the start of every detection pass.
		$this->scanned_pages = null;

		$context = array(
			'site_types'  => array(),
			'unresolved'  => array(),
			'site'        => $this->detect_site(),
			'detected_at' => $this->now(),
		);

		foreach ( $this->get_detectors() as $family => $method ) {
			if ( ! method_exists( $this, $method ) ) {
				continue;
			}

			$result = $this->{$method}();
			if ( ! is_array( $result ) || empty( $result['detected'] ) ) {
				continue;
			}

			$context['site_types'][] = $family;

			$urls = isset( $result['urls'] ) && is_array( $result['urls'] )
				? $this->filter_urls( $result['urls'] )
				: array();
			$meta = isset( $result['meta'] ) && is_array( $result['meta'] )
				? array_filter( $result['meta'], array( $this, 'is_non_empty_string' ) )
				: array();

			if ( empty( $urls ) ) {
				// Detected but unresolved: no URL key is published, so no recipe
				// requiring one can be offered. Never guess (spec.md §2.1).
				$context['unresolved'][] = $family;
			}

			$payload = array_merge( $meta, $urls );
			if ( ! empty( $payload ) ) {
				$context[ $family ] = $payload;
			}
		}

		return $context;
	}

	/**
	 * Apply the public context filter (spec.md §4.4).
	 *
	 * @param array $context Context array.
	 * @return array
	 */
	protected function filter_context( array $context ) {
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'opti_behavior_funnel_site_context', $context );
			if ( is_array( $filtered ) ) {
				return $filtered;
			}
		}
		return $context;
	}

	// -------------------------------------------------------------------------
	// Site-level facts
	// -------------------------------------------------------------------------

	/**
	 * Site-level facts every recipe may need.
	 *
	 * `home` is the ONLY place the site root is published. Recipes that start at
	 * the home page must use it with `match_type = 'exact'` (spec.md §3.4) —
	 * `contains` on the home path matches every pageview.
	 *
	 * @return array
	 */
	protected function detect_site() {
		return array(
			'home'               => $this->get_home_path(),
			'front_page_is_blog' => ( 'posts' === (string) $this->option( 'show_on_front', 'posts' ) ),
		);
	}

	// -------------------------------------------------------------------------
	// Family detectors
	// -------------------------------------------------------------------------

	/**
	 * WooCommerce.
	 *
	 * All page URLs come from `wc_get_page_permalink()` with an EMPTY fallback:
	 * WooCommerce otherwise returns the site home URL for a page that was never
	 * configured, which would silently produce a match-everything pattern.
	 *
	 * @return array|null
	 */
	protected function detect_woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}

		$urls = array();

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$checkout = wc_get_page_permalink( 'checkout', '' );

			$urls['shop']     = $this->to_site_path( wc_get_page_permalink( 'shop', '' ) );
			$urls['cart']     = $this->to_site_path( wc_get_page_permalink( 'cart', '' ) );
			$urls['checkout'] = $this->to_site_path( $checkout );
			$urls['account']  = $this->to_site_path( wc_get_page_permalink( 'myaccount', '' ) );

			// Order-received lives UNDER the checkout page as an endpoint, so it
			// is localized twice (page slug + endpoint slug) and can only be
			// resolved by WooCommerce itself.
			if ( $this->is_non_empty_string( $checkout ) && function_exists( 'wc_get_endpoint_url' ) ) {
				$urls['thankyou'] = $this->to_site_path( wc_get_endpoint_url( 'order-received', '', $checkout ) );
			}
		}

		$permalinks = $this->option( 'woocommerce_permalinks', array() );
		$permalinks = is_array( $permalinks ) ? $permalinks : array();

		$product_base = isset( $permalinks['product_base'] ) ? $permalinks['product_base'] : '';
		if ( ! $this->is_non_empty_string( $product_base ) ) {
			$product_base = $this->get_post_type_base( 'product' );
		}
		$urls['product_base'] = $this->to_base_path( $product_base );

		$category_base = isset( $permalinks['category_base'] ) ? $permalinks['category_base'] : '';
		$urls['category_base'] = $this->to_base_path( $category_base );

		return array(
			'detected' => true,
			'urls'     => $urls,
		);
	}

	/**
	 * Easy Digital Downloads.
	 *
	 * @return array|null
	 */
	protected function detect_edd() {
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			return null;
		}

		$urls = array();

		$urls['archive'] = $this->to_site_path( $this->post_type_archive_link( 'download' ) );

		$download_base = $this->get_post_type_base( 'download' );
		if ( ! $this->is_non_empty_string( $download_base ) && function_exists( 'edd_get_option' ) ) {
			$download_base = (string) edd_get_option( 'download_slug', '' );
		}
		$urls['download_base'] = $this->to_base_path( $download_base );

		if ( function_exists( 'edd_get_checkout_uri' ) ) {
			$urls['checkout'] = $this->to_site_path( edd_get_checkout_uri() );
		}
		if ( function_exists( 'edd_get_success_page_uri' ) ) {
			$urls['success'] = $this->to_site_path( edd_get_success_page_uri() );
		}

		return array(
			'detected' => true,
			'urls'     => $urls,
		);
	}

	/**
	 * Blog / content.
	 *
	 * @return array|null
	 */
	protected function detect_blog() {
		if ( $this->count_published_posts() < self::MIN_BLOG_POSTS ) {
			return null;
		}

		$urls = array();

		// Dedicated posts page only. When the front page IS the blog index its
		// URL is the home path, which filter_urls() drops on purpose — the fact
		// is still available as site.front_page_is_blog.
		$posts_page = (int) $this->option( 'page_for_posts', 0 );
		if ( $posts_page > 0 && function_exists( 'get_permalink' ) ) {
			$urls['index'] = $this->to_site_path( get_permalink( $posts_page ) );
		}

		$structure = (string) $this->option( 'permalink_structure', '' );
		if ( '' !== $structure ) {
			// Static prefix of the permalink structure: '/blog/%postname%/' =>
			// '/blog/'. A structure that starts with a rewrite tag has no usable
			// static prefix and to_base_path() drops it.
			$percent     = strpos( $structure, '%' );
			$static_part = ( false === $percent ) ? $structure : substr( $structure, 0, $percent );
			$urls['post_base'] = $this->to_base_path( $static_part );

			$category_base = (string) $this->option( 'category_base', '' );
			if ( '' === $category_base ) {
				$category_base = 'category';
			}
			$urls['category_base'] = $this->to_base_path( $category_base );
		}

		return array(
			'detected' => true,
			'urls'     => $urls,
		);
	}

	/**
	 * Lead generation / forms.
	 *
	 * Detected when a supported form plugin is active OR a contact page exists.
	 *
	 * @return array|null
	 */
	protected function detect_lead() {
		$form_plugin = $this->detect_form_plugin();

		$contact = $this->find_page_path(
			array(
				'contact',
				'contact-us',
				'contactez-nous',
				'nous-contacter',
				'contacto',
				'contactanos',
				'contato',
				'kontakt',
				'kontakt-os',
				'kontakta-oss',
				'contatti',
				'contattaci',
				'contacteer-ons',
				'iletisim',
			)
		);

		$thankyou = $this->find_page_path(
			array(
				'thank-you',
				'thanks',
				'thank-you-page',
				'merci',
				'danke',
				'vielen-dank',
				'gracias',
				'grazie',
				'bedankt',
				'obrigado',
				'tack',
			)
		);

		if ( '' === $form_plugin && '' === $contact ) {
			return null;
		}

		return array(
			'detected' => true,
			'meta'     => array( 'form_plugin' => $form_plugin ),
			'urls'     => array(
				'contact'  => $contact,
				'thankyou' => $thankyou,
			),
		);
	}

	/**
	 * Signup / registration.
	 *
	 * Detected when open registration is enabled OR a registration page exists.
	 *
	 * @return array|null
	 */
	protected function detect_signup() {
		$can_register = (bool) $this->option( 'users_can_register', 0 );

		$register = $this->find_page_path(
			array(
				'register',
				'signup',
				'sign-up',
				'create-account',
				'inscription',
				's-inscrire',
				'registrieren',
				'anmelden',
				'registro',
				'registrati',
				'registreren',
				'cadastro',
			)
		);

		if ( ! $can_register && '' === $register ) {
			return null;
		}

		if ( '' === $register && $can_register && function_exists( 'wp_registration_url' ) ) {
			// Core registration screen. Keeps its query string on purpose — the
			// path alone (wp-login.php) is also the login screen.
			$register = $this->to_site_path( wp_registration_url() );
		}

		$pricing = $this->find_page_path(
			array(
				'pricing',
				'plans',
				'plans-pricing',
				'tarifs',
				'prix',
				'abonnements',
				'preise',
				'precios',
				'planes',
				'prezzi',
				'abbonamenti',
				'tarieven',
			)
		);

		$account = $this->find_page_path(
			array(
				'account',
				'my-account',
				'mon-compte',
				'mein-konto',
				'mi-cuenta',
				'il-mio-account',
				'minha-conta',
				'mijn-account',
			)
		);

		return array(
			'detected' => true,
			'urls'     => array(
				'pricing'  => $pricing,
				'register' => $register,
				'account'  => $account,
			),
		);
	}

	/**
	 * Slug of the first active supported form plugin, or ''.
	 *
	 * @return string
	 */
	protected function detect_form_plugin() {
		$plugins = array(
			'wpcf7'         => 'WPCF7',
			'wpforms'       => 'WPForms',
			'gravityforms'  => 'GFForms',
			'fluentform'    => 'FluentForm',
			'ninja-forms'   => 'Ninja_Forms',
			'formidable'    => 'FrmForm',
			'elementor-pro' => 'ElementorPro\\Plugin',
		);

		foreach ( $plugins as $slug => $class_name ) {
			if ( class_exists( $class_name ) ) {
				return $slug;
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Multi-provider families (membership / LMS / booking)
	// -------------------------------------------------------------------------

	/**
	 * Run a provider map and return the first provider whose signature is
	 * present, in declaration (confidence) order.
	 *
	 * A resolver returns `null` when its signature class is absent, or an array
	 * of URL candidates otherwise. An empty/unresolvable array is still a
	 * detection: the family is published as *detected but unresolved* by
	 * build_context(), which is the whole point — the plugin IS installed, we
	 * just have no URL we can honestly point a funnel step at.
	 *
	 * @param array<string,string> $providers provider slug => resolver method.
	 * @return array|null
	 */
	protected function detect_provider_family( array $providers ) {
		foreach ( $providers as $slug => $method ) {
			if ( ! method_exists( $this, $method ) ) {
				continue;
			}

			$urls = $this->{$method}();
			if ( ! is_array( $urls ) ) {
				continue;
			}

			return array(
				'detected' => true,
				'meta'     => array( 'provider' => $slug ),
				'urls'     => $urls,
			);
		}

		return null;
	}

	/**
	 * Membership — MemberPress, Paid Memberships Pro, Restrict Content Pro.
	 *
	 * @return array|null
	 */
	protected function detect_membership() {
		return $this->detect_provider_family(
			array(
				'memberpress' => 'resolve_memberpress',
				'pmpro'       => 'resolve_pmpro',
				'rcp'         => 'resolve_rcp',
			)
		);
	}

	/**
	 * LMS — LearnDash, Tutor LMS, LifterLMS.
	 *
	 * @return array|null
	 */
	protected function detect_lms() {
		return $this->detect_provider_family(
			array(
				'learndash' => 'resolve_learndash',
				'tutor'     => 'resolve_tutor',
				'lifterlms' => 'resolve_lifterlms',
			)
		);
	}

	/**
	 * Booking — Amelia, Bookly, WooCommerce Bookings.
	 *
	 * @return array|null
	 */
	protected function detect_booking() {
		return $this->detect_provider_family(
			array(
				'amelia'               => 'resolve_amelia',
				'bookly'               => 'resolve_bookly',
				'woocommerce_bookings' => 'resolve_wc_bookings',
			)
		);
	}

	// --- Membership providers -------------------------------------------------

	/**
	 * MemberPress.
	 *
	 * `MeprOptions::fetch()` is the only public source for the account and
	 * thank-you page ids; the membership products (= the checkout/registration
	 * screens) live under the `memberpressproduct` CPT whose rewrite slug is
	 * user-configurable and localizable.
	 *
	 * @return array|null
	 */
	protected function resolve_memberpress() {
		if ( ! class_exists( 'MeprCtrlFactory' ) ) {
			return null;
		}

		$urls = array(
			'levels' => $this->to_base_path( $this->get_post_type_base( 'memberpressproduct' ) ),
		);

		if ( class_exists( 'MeprOptions' ) && method_exists( 'MeprOptions', 'fetch' ) ) {
			$options = MeprOptions::fetch();
			if ( is_object( $options ) ) {
				$urls['account']      = $this->page_path( isset( $options->account_page_id ) ? $options->account_page_id : 0 );
				$urls['confirmation'] = $this->page_path( isset( $options->thankyou_page_id ) ? $options->thankyou_page_id : 0 );
			}
		}

		return $urls;
	}

	/**
	 * Paid Memberships Pro.
	 *
	 * @return array|null
	 */
	protected function resolve_pmpro() {
		if ( ! class_exists( 'PMPro_Membership_Level' ) ) {
			return null;
		}

		return array(
			'levels'       => $this->page_path( $this->pmpro_option( 'levels_page_id' ) ),
			'checkout'     => $this->page_path( $this->pmpro_option( 'checkout_page_id' ) ),
			'confirmation' => $this->page_path( $this->pmpro_option( 'confirmation_page_id' ) ),
			'account'      => $this->page_path( $this->pmpro_option( 'account_page_id' ) ),
		);
	}

	/**
	 * Restrict Content Pro.
	 *
	 * Verified against Restrict Content 3.2.12 (wordpress.org stable zip — the
	 * free edition of the same core):
	 *   - `core/includes/class-restrict-content.php`  → `final class Restrict_Content_Pro`
	 *   - `core/includes/registration-functions.php`  → `rcp_get_registration_page_url()` (since 3.1)
	 *   - `core/includes/registration-functions.php`  → `rcp_get_return_url()`
	 *   - the account page has no accessor; it is the `account_page` key of the
	 *     `rcp_settings` option (`$rcp_options`), read here as a page id.
	 *
	 * The `..._page_uri()` spellings this method used previously do not exist in
	 * RCP, so `register` / `account` silently never resolved and the family
	 * degraded to detected-but-unresolved on every real install.
	 *
	 * @return array|null
	 */
	protected function resolve_rcp() {
		if ( ! class_exists( 'Restrict_Content_Pro' ) && ! class_exists( 'RCP_Requirements_Check' ) ) {
			return null;
		}

		$urls = array();

		if ( function_exists( 'rcp_get_registration_page_url' ) ) {
			$urls['register'] = $this->to_site_path( rcp_get_registration_page_url() );
		}
		if ( function_exists( 'rcp_get_return_url' ) ) {
			$urls['confirmation'] = $this->to_site_path( rcp_get_return_url() );
		}

		$rcp_settings = $this->option( 'rcp_settings', array() );
		if ( is_array( $rcp_settings ) && isset( $rcp_settings['account_page'] ) ) {
			$urls['account'] = $this->page_path( $rcp_settings['account_page'] );
		}

		return $urls;
	}

	/**
	 * PMPro option reader — `pmpro_getOption()` when available, otherwise the
	 * `pmpro_`-prefixed option it wraps.
	 *
	 * @param string $key Option key without the prefix.
	 * @return mixed
	 */
	protected function pmpro_option( $key ) {
		if ( function_exists( 'pmpro_getOption' ) ) {
			return pmpro_getOption( $key );
		}
		return $this->option( 'pmpro_' . $key, 0 );
	}

	// --- LMS providers --------------------------------------------------------

	/**
	 * LearnDash.
	 *
	 * Course and lesson bases come from the registered CPT rewrite slugs, which
	 * LearnDash rewrites from its own permalink settings — so a store that
	 * renamed `courses` to `formations` resolves correctly.
	 *
	 * NESTED URLS (verified against LearnDash LMS 5.1.6.1 on a real install):
	 * when the "nested URLs" permalink option is on, a lesson does NOT live at
	 * its own rewrite base — it is nested under its course:
	 *
	 *     /courses/{course-slug}/lessons/{lesson-slug}/
	 *
	 * so the CPT-base pattern `/lessons/` prefixed with the site path would match
	 * ZERO real lesson pageviews. The option is on by default: LearnDash forces
	 * it whenever shared course steps are enabled
	 * (`class-ld-settings-section-permalinks.php` — `learndash_is_course_shared_steps_enabled()`
	 * ⇒ `nested_urls = 'yes'`), which is the stock configuration.
	 *
	 * Under nested URLs the only fragment every lesson URL really contains is the
	 * lesson segment itself, so the `contains` pattern is emitted WITHOUT the site
	 * path prefix — the segment is read from LearnDash's own permalink setting,
	 * it is not a guess, and it still cannot match the course page or the course
	 * archive.
	 *
	 * CHECKOUT / CONFIRMATION: LearnDash has no page of its own called "checkout".
	 * Its Registration/Login page IS the checkout — the paid-enrollment form is
	 * rendered there and the gateway returns to it
	 * (`includes/payments/ld-login-registration-functions.php:659` —
	 * `$checkout_page = get_permalink( $registration_page_id )`), and the
	 * "Registration Success" page of the same settings section is where a
	 * completed enrollment lands. Both are optional: an unset page yields no key
	 * at all, so the Course Enrollment recipe is simply not offered rather than
	 * pointed at a guessed URL.
	 *
	 * @return array|null
	 */
	protected function resolve_learndash() {
		if ( ! class_exists( 'SFWD_LMS' ) ) {
			return null;
		}

		$lesson_slug = $this->get_post_type_base( 'sfwd-lessons' );
		$lesson_base = $this->learndash_uses_nested_urls()
			? $this->to_segment_path( $lesson_slug )
			: $this->to_base_path( $lesson_slug );

		// LearnDash's own accessor when it is loaded (since 4.5.0), otherwise the
		// setting it reads — the accessor lives in an optional payments include.
		$checkout_page = function_exists( 'learndash_registration_page_get_id' )
			? learndash_registration_page_get_id()
			: $this->learndash_page_setting( 'registration' );

		return array(
			'archive'      => $this->to_site_path( $this->post_type_archive_link( 'sfwd-courses' ) ),
			'course_base'  => $this->to_base_path( $this->get_post_type_base( 'sfwd-courses' ) ),
			'lesson_base'  => $lesson_base,
			'checkout'     => $this->page_path( $checkout_page ),
			'confirmation' => $this->page_path( $this->learndash_page_setting( 'registration_success' ) ),
		);
	}

	/**
	 * Whether LearnDash nests course-step URLs under their course.
	 *
	 * Read through LearnDash's own settings API when it is loaded, otherwise from
	 * the option that API stores the section in
	 * (`setting_option_key = 'learndash_settings_permalinks'`).
	 *
	 * @return bool
	 */
	protected function learndash_uses_nested_urls() {
		if ( class_exists( 'LearnDash_Settings_Section' )
			&& method_exists( 'LearnDash_Settings_Section', 'get_section_setting' ) ) {
			$nested = LearnDash_Settings_Section::get_section_setting(
				'LearnDash_Settings_Section_Permalinks',
				'nested_urls'
			);
			return ( 'yes' === $nested );
		}

		$permalinks = $this->option( 'learndash_settings_permalinks', array() );

		return ( is_array( $permalinks )
			&& isset( $permalinks['nested_urls'] )
			&& 'yes' === $permalinks['nested_urls'] );
	}

	/**
	 * Page id stored in LearnDash's Registration/Login settings section.
	 *
	 * Read through LearnDash's own settings API when it is loaded, otherwise from
	 * the option that API stores the section in
	 * (`setting_option_key = 'learndash_settings_registration_pages'`).
	 *
	 * @param string $key Setting key ('registration', 'registration_success').
	 * @return mixed Page id, or '' when unset.
	 */
	protected function learndash_page_setting( $key ) {
		if ( class_exists( 'LearnDash_Settings_Section' )
			&& method_exists( 'LearnDash_Settings_Section', 'get_section_setting' ) ) {
			return LearnDash_Settings_Section::get_section_setting(
				'LearnDash_Settings_Section_Registration_Pages',
				$key
			);
		}

		$pages = $this->option( 'learndash_settings_registration_pages', array() );

		return ( is_array( $pages ) && isset( $pages[ $key ] ) ) ? $pages[ $key ] : '';
	}

	/**
	 * Tutor LMS.
	 *
	 * @return array|null
	 */
	protected function resolve_tutor() {
		/*
		 * Signature is Tutor's own main class / version constant — NOT
		 * `tutor_utils()`, which is only the option accessor used afterwards.
		 *
		 * Verified against Tutor LMS 4.0.7 (wordpress.org stable zip):
		 *   - `tutor.php:29`        defines TUTOR_VERSION
		 *   - `classes/Tutor.php`   is `namespace TUTOR; final class Tutor`
		 *
		 * `TUTOR` alone is Tutor's *namespace*, not a class, so the earlier
		 * `class_exists( 'TUTOR' )` could never be true. The fully-qualified
		 * `TUTOR\Tutor` is the real main class.
		 */
		if ( ! class_exists( 'TUTOR\\Tutor' ) && ! defined( 'TUTOR_VERSION' ) ) {
			return null;
		}

		$checkout_page = $this->tutor_option( 'tutor_checkout_page_id' );
		if ( ! $this->is_positive_id( $checkout_page ) ) {
			$checkout_page = $this->tutor_option( 'tutor_cart_page_id' );
		}

		return array(
			'archive'     => $this->to_site_path( $this->post_type_archive_link( 'courses' ) ),
			'course_base' => $this->to_base_path( $this->tutor_option( 'course_permalink_base' ) ),
			'lesson_base' => $this->to_base_path( $this->tutor_option( 'lesson_permalink_base' ) ),
			'checkout'    => $this->page_path( $checkout_page ),
			'dashboard'   => $this->page_path( $this->tutor_option( 'tutor_dashboard_page_id' ) ),
		);
	}

	/**
	 * LifterLMS.
	 *
	 * @return array|null
	 */
	protected function resolve_lifterlms() {
		if ( ! class_exists( 'LifterLMS' ) ) {
			return null;
		}

		$urls = array(
			'course_base' => $this->to_base_path( $this->get_post_type_base( 'course' ) ),
			'lesson_base' => $this->to_base_path( $this->get_post_type_base( 'lesson' ) ),
		);

		if ( function_exists( 'llms_get_page_url' ) ) {
			$urls['archive']   = $this->to_site_path( llms_get_page_url( 'courses' ) );
			$urls['checkout']  = $this->to_site_path( llms_get_page_url( 'checkout' ) );
			$urls['dashboard'] = $this->to_site_path( llms_get_page_url( 'myaccount' ) );
		}

		return $urls;
	}

	/**
	 * Tutor LMS option reader.
	 *
	 * @param string $key Option key.
	 * @return mixed
	 */
	protected function tutor_option( $key ) {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return '';
		}

		$utils = tutor_utils();
		if ( ! is_object( $utils ) || ! method_exists( $utils, 'get_option' ) ) {
			return '';
		}

		return $utils->get_option( $key, '' );
	}

	// --- Booking providers ----------------------------------------------------

	/**
	 * Amelia.
	 *
	 * Amelia exposes NO page-id API — the booking form is wherever the site
	 * owner dropped the shortcode/block. Hence the capped content scan; when it
	 * finds nothing the family stays detected-but-unresolved.
	 *
	 * @return array|null
	 */
	protected function resolve_amelia() {
		if ( ! class_exists( 'AmeliaBooking\\Plugin' ) ) {
			return null;
		}

		return array(
			'services' => $this->find_page_path_by_content(
				array( '[ameliacatalog', '[ameliaservices', 'wp:amelia/catalog' )
			),
			'form'     => $this->find_page_path_by_content(
				array( '[ameliabooking', '[ameliastepbooking', '[ameliaevents', 'wp:amelia/step-booking', 'wp:amelia/booking' )
			),
		);
	}

	/**
	 * Bookly.
	 *
	 * Same constraint as Amelia — no page-id API, so the form page is located
	 * by its shortcode/block.
	 *
	 * @return array|null
	 */
	protected function resolve_bookly() {
		if ( ! class_exists( 'Bookly\\Lib\\Plugin' ) ) {
			return null;
		}

		return array(
			'form' => $this->find_page_path_by_content(
				array( '[bookly-form', '[bookly-catalog', 'wp:bookly/' )
			),
		);
	}

	/**
	 * WooCommerce Bookings.
	 *
	 * Bookings piggybacks on the WooCommerce funnel, so its URLs come from the
	 * WooCommerce API (localized automatically). The booking form is the
	 * bookable product page — published only when a bookable product actually
	 * exists, otherwise the product base would point at ordinary products.
	 *
	 * @return array|null
	 */
	protected function resolve_wc_bookings() {
		if ( ! class_exists( 'WC_Bookings' ) ) {
			return null;
		}

		$urls = array();

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls['services'] = $this->to_site_path( wc_get_page_permalink( 'shop', '' ) );

			$checkout = wc_get_page_permalink( 'checkout', '' );
			if ( $this->is_non_empty_string( $checkout ) && function_exists( 'wc_get_endpoint_url' ) ) {
				$urls['confirmation'] = $this->to_site_path( wc_get_endpoint_url( 'order-received', '', $checkout ) );
			}
		}

		if ( $this->has_bookable_product() ) {
			$urls['form'] = $this->resolve_product_base();
		}

		return $urls;
	}

	/**
	 * Whether at least one published bookable product exists.
	 *
	 * @return bool
	 */
	protected function has_bookable_product() {
		if ( ! function_exists( 'get_posts' ) ) {
			return false;
		}

		$ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- single bounded existence probe, run at most once per 12 h detection pass.
				'tax_query'              => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => 'booking',
					),
				),
			)
		);

		return is_array( $ids ) && ! empty( $ids );
	}

	/**
	 * WooCommerce product rewrite base, normalized.
	 *
	 * @return string
	 */
	protected function resolve_product_base() {
		$permalinks = $this->option( 'woocommerce_permalinks', array() );
		$permalinks = is_array( $permalinks ) ? $permalinks : array();

		$base = isset( $permalinks['product_base'] ) ? $permalinks['product_base'] : '';
		if ( ! $this->is_non_empty_string( $base ) ) {
			$base = $this->get_post_type_base( 'product' );
		}

		return $this->to_base_path( $base );
	}

	// -------------------------------------------------------------------------
	// Resolution helpers
	// -------------------------------------------------------------------------

	/**
	 * Site-relative path of a page id, or '' for a missing / unset id.
	 *
	 * @param mixed $page_id Page id.
	 * @return string
	 */
	protected function page_path( $page_id ) {
		if ( ! $this->is_positive_id( $page_id ) || ! function_exists( 'get_permalink' ) ) {
			return '';
		}
		return $this->to_site_path( get_permalink( (int) $page_id ) );
	}

	/**
	 * Whether a value is usable as a post id.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected function is_positive_id( $value ) {
		return ( is_int( $value ) || is_string( $value ) || is_float( $value ) ) && (int) $value > 0;
	}

	/**
	 * First published page whose content contains one of the given shortcode /
	 * block needles, as a site-relative path.
	 *
	 * Needles are matched case-insensitively against the raw post content, so
	 * both the classic shortcode (`[bookly-form]`) and the block comment
	 * (`<!-- wp:bookly/... -->`) forms are found.
	 *
	 * @param array $needles Lower-case needles, in priority order.
	 * @return string Site-relative path, or '' when nothing matched.
	 */
	protected function find_page_path_by_content( array $needles ) {
		if ( empty( $needles ) || ! function_exists( 'get_permalink' ) ) {
			return '';
		}

		foreach ( $this->get_scannable_pages() as $page ) {
			if ( ! is_object( $page ) || ! isset( $page->post_content ) ) {
				continue;
			}

			$content = strtolower( (string) $page->post_content );
			foreach ( $needles as $needle ) {
				if ( ! $this->is_non_empty_string( $needle ) || false === strpos( $content, strtolower( $needle ) ) ) {
					continue;
				}
				$path = $this->to_site_path( get_permalink( $page ) );
				if ( '' !== $path ) {
					return $path;
				}
			}
		}

		return '';
	}

	/**
	 * Published pages available to the content scan — ONE bounded query
	 * (`PAGE_SCAN_CAP`), memoized for the whole detection pass and then frozen
	 * inside the 12 h context transient.
	 *
	 * @return array
	 */
	protected function get_scannable_pages() {
		if ( null !== $this->scanned_pages ) {
			return $this->scanned_pages;
		}

		$this->scanned_pages = array();

		if ( ! function_exists( 'get_posts' ) ) {
			return $this->scanned_pages;
		}

		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => self::PAGE_SCAN_CAP,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( is_array( $pages ) ) {
			$this->scanned_pages = $pages;
		}

		return $this->scanned_pages;
	}

	/**
	 * Resolve the first existing published page among a candidate slug list and
	 * return its site-relative path.
	 *
	 * One bounded query for the whole list (`post_name__in`), then candidate
	 * priority order decides the winner — so `/contact/` beats `/kontakt/` when
	 * both exist. Never falls back to a guessed path.
	 *
	 * @param array $slugs Candidate slugs in priority order.
	 * @return string Site-relative path, or '' when nothing resolved.
	 */
	protected function find_page_path( array $slugs ) {
		$found = $this->find_pages_by_slug( $slugs );

		foreach ( $slugs as $slug ) {
			if ( isset( $found[ $slug ] ) && $this->is_non_empty_string( $found[ $slug ] ) ) {
				return $found[ $slug ];
			}
		}

		return '';
	}

	/**
	 * Map of slug => site-relative path for the published pages matching the
	 * given slugs.
	 *
	 * @param array $slugs Candidate slugs.
	 * @return array<string,string>
	 */
	protected function find_pages_by_slug( array $slugs ) {
		if ( empty( $slugs ) || ! function_exists( 'get_posts' ) || ! function_exists( 'get_permalink' ) ) {
			return array();
		}

		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'post_name__in'          => array_slice( array_values( $slugs ), 0, self::PAGE_SCAN_CAP ),
				'posts_per_page'         => self::PAGE_SCAN_CAP,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( ! is_array( $pages ) ) {
			return array();
		}

		$out = array();
		foreach ( $pages as $page ) {
			if ( ! is_object( $page ) || ! isset( $page->post_name ) ) {
				continue;
			}
			$slug = (string) $page->post_name;
			if ( isset( $out[ $slug ] ) ) {
				continue;
			}
			$path = $this->to_site_path( get_permalink( $page ) );
			if ( '' !== $path ) {
				$out[ $slug ] = $path;
			}
		}

		return $out;
	}

	/**
	 * Rewrite slug of a custom post type, or ''.
	 *
	 * @param string $post_type Post type name.
	 * @return string
	 */
	protected function get_post_type_base( $post_type ) {
		if ( ! function_exists( 'get_post_type_object' ) ) {
			return '';
		}

		$object = get_post_type_object( $post_type );
		if ( ! is_object( $object ) ) {
			return '';
		}

		if ( isset( $object->rewrite ) && is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
			return (string) $object->rewrite['slug'];
		}

		return '';
	}

	/**
	 * Post-type archive link, or ''.
	 *
	 * @param string $post_type Post type name.
	 * @return string
	 */
	protected function post_type_archive_link( $post_type ) {
		if ( ! function_exists( 'get_post_type_archive_link' ) ) {
			return '';
		}
		$link = get_post_type_archive_link( $post_type );
		return is_string( $link ) ? $link : '';
	}

	/**
	 * Published post count for the `post` type.
	 *
	 * @return int
	 */
	protected function count_published_posts() {
		if ( ! function_exists( 'wp_count_posts' ) ) {
			return 0;
		}
		$counts = wp_count_posts( 'post' );
		if ( is_object( $counts ) && isset( $counts->publish ) ) {
			return (int) $counts->publish;
		}
		if ( is_array( $counts ) && isset( $counts['publish'] ) ) {
			return (int) $counts['publish'];
		}
		return 0;
	}

	// -------------------------------------------------------------------------
	// Path normalization
	// -------------------------------------------------------------------------

	/**
	 * Convert an absolute (or already relative) URL into a site-relative path.
	 *
	 * The funnel matchers compare against `window.location.href` (JS tracker)
	 * and `REQUEST_URI` (PHP), both of which include the WordPress subdirectory
	 * on a sub-folder install — so the subdirectory is KEPT.
	 *
	 * Off-site URLs return '' (a cross-host pattern can never match).
	 * The query string is preserved when present (`wp-login.php?action=register`
	 * needs it); the fragment never is.
	 *
	 * @param string $url URL or path.
	 * @return string Site-relative path, or ''.
	 */
	protected function to_site_path( $url ) {
		if ( ! $this->is_non_empty_string( $url ) ) {
			return '';
		}

		$url  = trim( $url );
		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}
		if ( '' === $url ) {
			return '';
		}

		$parts = $this->parse_url_parts( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' !== $host ) {
			$home_host = $this->get_home_host();
			if ( '' !== $home_host && $host !== $home_host ) {
				return '';
			}
		}

		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = ( isset( $parts['query'] ) && '' !== $parts['query'] ) ? '?' . $parts['query'] : '';

		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		// Trailing slash for extension-less paths only, so '/panier' and
		// '/panier/' normalize to one pattern while '/wp-login.php' is left
		// alone.
		if ( '' === $query && '/' !== substr( $path, -1 ) && false === strpos( basename( $path ), '.' ) ) {
			$path .= '/';
		}

		return $path . $query;
	}

	/**
	 * Normalize a rewrite base ('produit', '/produit', '/blog/%postname%/')
	 * into a site-relative, slash-wrapped path.
	 *
	 * Returns '' when the base is empty or degenerates to the site home — a
	 * home-wide `contains` pattern would match every pageview.
	 *
	 * @param string $base Rewrite base.
	 * @return string
	 */
	protected function to_base_path( $base ) {
		if ( ! $this->is_non_empty_string( $base ) ) {
			return '';
		}

		$percent = strpos( $base, '%' );
		if ( false !== $percent ) {
			$base = substr( $base, 0, $percent );
		}

		$base = trim( trim( $base ), '/' );
		if ( '' === $base ) {
			return '';
		}

		return $this->get_home_path() . $base . '/';
	}

	/**
	 * Normalize a single URL segment ('lessons', '/lecons/') into a slash-wrapped
	 * `contains` fragment WITHOUT the site path prefix.
	 *
	 * Used only where the segment is genuinely mid-path and therefore cannot be
	 * anchored at the site root — LearnDash's nested course-step URLs
	 * (`/courses/{course}/lessons/{lesson}/`). Everything else must go through
	 * to_base_path(), which keeps the sub-directory prefix.
	 *
	 * @param string $segment Path segment.
	 * @return string
	 */
	protected function to_segment_path( $segment ) {
		if ( ! $this->is_non_empty_string( $segment ) ) {
			return '';
		}

		$percent = strpos( $segment, '%' );
		if ( false !== $percent ) {
			$segment = substr( $segment, 0, $percent );
		}

		$segment = trim( trim( $segment ), '/' );
		if ( '' === $segment ) {
			return '';
		}

		return '/' . $segment . '/';
	}

	/**
	 * Drop unusable URL values: empty ones, and any value equal to the site
	 * home (an over-broad pattern — see class docblock rule 3).
	 *
	 * @param array $urls Raw url map.
	 * @return array
	 */
	protected function filter_urls( array $urls ) {
		$home = $this->get_home_path();
		$out  = array();

		foreach ( $urls as $key => $value ) {
			if ( ! $this->is_non_empty_string( $value ) ) {
				continue;
			}
			if ( $this->paths_equal( $value, $home ) ) {
				continue;
			}
			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * Slash/case-insensitive path comparison.
	 *
	 * @param string $a First path.
	 * @param string $b Second path.
	 * @return bool
	 */
	protected function paths_equal( $a, $b ) {
		$a = rtrim( strtolower( (string) $a ), '/' );
		$b = rtrim( strtolower( (string) $b ), '/' );
		return $a === $b;
	}

	/**
	 * Site-relative home path, always trailing-slashed ('/' or '/wordpress/').
	 *
	 * @return string
	 */
	protected function get_home_path() {
		if ( null !== $this->home_path ) {
			return $this->home_path;
		}

		$path = '';
		$parts = $this->parse_url_parts( $this->home_url() );
		if ( is_array( $parts ) && isset( $parts['path'] ) ) {
			$path = (string) $parts['path'];
		}

		$path = '/' . trim( $path, '/' );
		if ( '/' !== substr( $path, -1 ) ) {
			$path .= '/';
		}

		$this->home_path = $path;
		return $this->home_path;
	}

	/**
	 * Host of the site home, lower-case.
	 *
	 * @return string
	 */
	protected function get_home_host() {
		if ( null !== $this->home_host ) {
			return $this->home_host;
		}

		$host  = '';
		$parts = $this->parse_url_parts( $this->home_url() );
		if ( is_array( $parts ) && isset( $parts['host'] ) ) {
			$host = strtolower( (string) $parts['host'] );
		}

		$this->home_host = $host;
		return $this->home_host;
	}

	/**
	 * Home URL with a trailing slash.
	 *
	 * @return string
	 */
	protected function home_url() {
		if ( function_exists( 'home_url' ) ) {
			return (string) home_url( '/' );
		}
		return '/';
	}

	/**
	 * `wp_parse_url()` with a plain-PHP fallback (keeps the class unit-testable
	 * outside a booted WordPress).
	 *
	 * @param string $url URL.
	 * @return array|false
	 */
	protected function parse_url_parts( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() unavailable (unit-test context); identical semantics on PHP 7.4+.
		return parse_url( $url );
	}

	// -------------------------------------------------------------------------
	// Small utilities
	// -------------------------------------------------------------------------

	/**
	 * `get_option()` wrapper tolerant of a non-WordPress context.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected function option( $name, $default = false ) {
		if ( function_exists( 'get_option' ) ) {
			return get_option( $name, $default );
		}
		return $default;
	}

	/**
	 * Current site time in MySQL format.
	 *
	 * @return string
	 */
	protected function now() {
		if ( function_exists( 'current_time' ) ) {
			return (string) current_time( 'mysql' );
		}
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected function is_non_empty_string( $value ) {
		return is_string( $value ) && '' !== $value;
	}
}

// Cache invalidation must be wired as soon as the class is loaded: installing or
// removing a store/form plugin changes the answer, and the 12 h transient would
// otherwise keep serving the stale one.
if ( function_exists( 'add_action' ) ) {
	Opti_Behavior_Funnel_Site_Detector::register_hooks();
}
