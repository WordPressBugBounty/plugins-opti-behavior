<?php
/**
 * Funnel Recipe Registry
 *
 * The catalog of ready-made funnels (spec.md §2.2 / §2.4) and the ONLY place
 * where funnel steps are generated from the detected site context
 * (`Opti_Behavior_Funnel_Site_Detector::detect()`).
 *
 * Design rules (spec.md §3.4 — non-negotiable, every one of them is a defect
 * that shipped at least once in this plugin):
 *
 * 1. **Never hardcode a URL.** Every pattern comes from a context key that the
 *    detector resolved through the source plugin's own API, so localized
 *    stores (`/panier/`, `/commander/`, `/kasse/`) work with no translation
 *    table. A recipe whose required context keys did not resolve is simply not
 *    offered — it never falls back to an English guess.
 * 2. **`contains` by default.** It is the only match type with identical
 *    semantics in the PHP matcher, the JS tracker and the Pro backfill matcher.
 * 3. **`exact` only for the site home.** A `contains` pattern equal to the home
 *    path matches every pageview (the exact defect covered by
 *    funnel-regression-test.php). The home step therefore uses `exact` with
 *    `$context['site']['home']` — never a hardcoded '/', which does not match
 *    on a sub-directory install.
 * 4. **Never emit `any` / `pageview` with a pattern.** `url_matches_pattern()`
 *    silently rewrites that combination to `contains`; generating it is a
 *    latent bug. This registry emits neither type at all.
 * 5. **Strictly funnel-forward.** A later step's pattern may not be a substring
 *    of an earlier step's pattern (`/checkout` before `/checkout/order-received`
 *    is fine — the reverse would make the later step match the earlier URL).
 *    Violating steps are dropped, not reordered.
 * 6. **Regexes are pre-validated** with `@preg_match()` before a step is
 *    accepted (no recipe emits one today; the guard covers Pro appends).
 *
 * Recipe shape (contract):
 *
 *     array(
 *       'id'          => 'woo_purchase',
 *       'label'       => 'Purchase Funnel',
 *       'description' => 'Shop → Product → Cart → Checkout → Order received',
 *       'icon'        => 'shopping-cart',
 *       'requires'    => array( 'woocommerce.cart', array( 'blog.index', 'blog.post_base' ) ),
 *       'priority'    => 10,
 *       'tier'        => 'free' | 'pro',
 *       'phase'       => 1 | 2,
 *       'build_steps' => callable( array $context ) : array | null,
 *     );
 *
 * `requires` entries are dotted context paths. A bare family (`'woocommerce'`)
 * means "the family resolved at least one URL"; `'woocommerce.cart'` means that
 * specific key resolved. A nested array is an ANY-OF group.
 *
 * `build_steps` is null for the Pro-tier recipes: they are registered here so
 * the suggestions panel can render them locked with an upsell, and the Pro
 * plugin supplies their step builders through the
 * `opti_behavior_funnel_recipes` filter (spec.md §3.3).
 *
 * @package opti-behavior
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Funnel Recipe Registry.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Funnel_Recipes {

	/**
	 * Free tier marker.
	 *
	 * @var string
	 */
	const TIER_FREE = 'free';

	/**
	 * Pro tier marker.
	 *
	 * @var string
	 */
	const TIER_PRO = 'pro';

	/**
	 * Shipping phase. Recipes marked phase 2 are documented in the catalog but
	 * excluded from get_available() — they need the event-based match types that
	 * are out of scope for v1 (spec.md §4.5-a).
	 *
	 * @var int
	 */
	const PHASE_SHIPPED = 1;

	/**
	 * Deferred (documented, not shipped) phase.
	 *
	 * @var int
	 */
	const PHASE_DEFERRED = 2;

	/**
	 * Minimum number of steps a generated funnel must have to be offered. One
	 * step is not a funnel — it is a pageview counter.
	 *
	 * @var int
	 */
	const MIN_STEPS = 2;

	/**
	 * Context keys that are metadata, not URLs. Mirrors the detector's
	 * get_meta_keys(): they never satisfy a bare-family requirement.
	 *
	 * @var array<int,string>
	 */
	const META_KEYS = array( 'form_plugin', 'provider' );

	/**
	 * Match types accepted by url_matches_pattern() / urlMatchesPattern().
	 *
	 * @var array<int,string>
	 */
	const MATCH_TYPES = array( 'any', 'exact', 'contains', 'starts_with', 'ends_with', 'regex', 'pageview' );

	/**
	 * Match types that ignore their pattern and therefore must never be emitted
	 * together with one (rule 4).
	 *
	 * @var array<int,string>
	 */
	const WILDCARD_MATCH_TYPES = array( 'any', 'pageview' );

	/**
	 * Singleton instance.
	 *
	 * @var Opti_Behavior_Funnel_Recipes|null
	 */
	private static $instance = null;

	/**
	 * Shared instance.
	 *
	 * @return Opti_Behavior_Funnel_Recipes
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * The whole catalog, keyed by recipe id — including Pro-tier and deferred
	 * (phase 2) entries, and whatever the `opti_behavior_funnel_recipes` filter
	 * appended.
	 *
	 * @param array $context Site context (passed to the filter so Pro can offer
	 *                       context-dependent recipes).
	 * @return array<string,array>
	 */
	public function get_all( array $context = array() ) {
		$recipes = array();

		foreach ( $this->get_catalog() as $recipe ) {
			$normalized = $this->normalize_recipe( $recipe );
			if ( null !== $normalized ) {
				$recipes[ $normalized['id'] ] = $normalized;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'opti_behavior_funnel_recipes', $recipes, $context );
			if ( is_array( $filtered ) ) {
				$recipes = $this->normalize_catalog( $filtered );
			}
		}

		uasort( $recipes, array( $this, 'compare_recipes' ) );

		return $recipes;
	}

	/**
	 * A single recipe by id, or null.
	 *
	 * @param string $id      Recipe id.
	 * @param array  $context Site context.
	 * @return array|null
	 */
	public function get( $id, array $context = array() ) {
		if ( ! is_string( $id ) || '' === $id ) {
			return null;
		}

		$recipes = $this->get_all( $context );

		return isset( $recipes[ $id ] ) ? $recipes[ $id ] : null;
	}

	/**
	 * The recipes that can be OFFERED for this site, ordered by priority.
	 *
	 * A recipe is offered when:
	 *  - it is not marked `phase: 2` (documented but not shipped — spec.md §4.5-a);
	 *  - every entry of `requires` resolves against the context;
	 *  - and, when it has a step builder, that builder yields at least
	 *    MIN_STEPS valid steps.
	 *
	 * Pro-tier recipes ARE returned (with no steps until the Pro plugin supplies
	 * their builders) so the panel can render them locked with an upsell. The
	 * tier gate is enforced server-side by the create endpoint, not here.
	 *
	 * Each returned entry carries an extra `steps` key.
	 *
	 * @param array $context Site context from the detector.
	 * @return array<int,array> Ordered list.
	 */
	public function get_available( array $context ) {
		$available = array();

		foreach ( $this->get_all( $context ) as $recipe ) {
			if ( self::PHASE_SHIPPED !== $recipe['phase'] ) {
				continue;
			}
			if ( ! $this->requirements_met( $recipe['requires'], $context ) ) {
				continue;
			}

			$steps = $this->get_steps( $recipe['id'], $context, $recipe );

			// A recipe WITH a builder must produce a real funnel. A recipe
			// without one (Pro placeholder) is offered on its requirements
			// alone — its steps arrive with the Pro plugin.
			if ( null !== $recipe['build_steps'] && count( $steps ) < self::MIN_STEPS ) {
				continue;
			}

			$recipe['steps'] = $steps;
			$available[]     = $recipe;
		}

		return $available;
	}

	/**
	 * Generated, validated steps of one recipe.
	 *
	 * Always returns an array — an unbuildable recipe yields an empty one, never
	 * a partially-invalid funnel.
	 *
	 * @param string     $recipe_id Recipe id.
	 * @param array      $context   Site context.
	 * @param array|null $recipe    Pre-resolved recipe (internal, avoids a
	 *                              second catalog build).
	 * @return array<int,array>
	 */
	public function get_steps( $recipe_id, array $context, $recipe = null ) {
		if ( ! is_array( $recipe ) ) {
			$recipe = $this->get( $recipe_id, $context );
		}
		if ( ! is_array( $recipe ) ) {
			return array();
		}

		$steps = array();
		if ( null !== $recipe['build_steps'] && is_callable( $recipe['build_steps'] ) ) {
			$built = call_user_func( $recipe['build_steps'], $context );
			$steps = is_array( $built ) ? $built : array();
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'opti_behavior_funnel_recipe_steps', $steps, $recipe['id'], $context );
			if ( is_array( $filtered ) ) {
				$steps = $filtered;
			}
		}

		return $this->validate_steps( $steps );
	}

	/**
	 * Drop every step that would violate the generation rules and return the
	 * survivors, re-indexed.
	 *
	 * Also the guard the auto-builder runs before persisting: `ajax_save_funnel()`
	 * validates nothing beyond "non-empty array" (spec.md §1.3), so anything a
	 * filter injected is checked here.
	 *
	 * @param array $steps Candidate steps.
	 * @return array<int,array>
	 */
	public function validate_steps( $steps ) {
		if ( ! is_array( $steps ) ) {
			return array();
		}

		$valid = array();

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$name       = isset( $step['name'] ) ? trim( (string) $step['name'] ) : '';
			$pattern    = isset( $step['url_pattern'] ) ? (string) $step['url_pattern'] : '';
			$match_type = isset( $step['match_type'] ) ? (string) $step['match_type'] : '';

			if ( '' === $name || '' === $pattern ) {
				continue;
			}
			if ( ! in_array( $match_type, self::MATCH_TYPES, true ) ) {
				continue;
			}
			// Rule 4: `any` / `pageview` ignore their pattern; the matcher
			// rewrites the pair to `contains` behind our back.
			if ( in_array( $match_type, self::WILDCARD_MATCH_TYPES, true ) ) {
				continue;
			}
			// Rule 6: an invalid regex silently never matches.
			if ( 'regex' === $match_type && ! $this->is_valid_regex( $pattern ) ) {
				continue;
			}
			// Rule 5: a later pattern that is a substring of an earlier one
			// would match the earlier step's URL and break the ordering.
			if ( $this->is_substring_of_earlier( $pattern, $valid ) ) {
				continue;
			}

			$valid[] = array(
				'name'        => $name,
				'match_type'  => $match_type,
				'url_pattern' => $pattern,
			);
		}

		return $valid;
	}

	// -------------------------------------------------------------------------
	// Catalog (spec.md §2.2 / §2.4)
	// -------------------------------------------------------------------------

	/**
	 * The built-in catalog, in spec order.
	 *
	 * Numbers in the labels below are the spec.md §2.2 catalog numbers, kept in
	 * the comments so the tier table can be diffed against the spec:
	 *
	 *  Free : 1 2 3 8 9 12 14 20
	 *  Pro  : 4 5 6 10 11 13 15 16 17 21 22   (18 / 19 are added by the Pro plugin)
	 *  Phase 2 (documented, never offered) : 7
	 *
	 * @return array<int,array>
	 */
	protected function get_catalog() {
		return array(

			// --- WooCommerce ------------------------------------------------
			array(
				'id'          => 'woo_purchase',
				'label'       => __( 'Purchase Funnel', 'opti-behavior' ),
				'description' => __( 'The full store journey: shop, product page, cart, checkout and order received.', 'opti-behavior' ),
				'icon'        => 'shopping-cart',
				'requires'    => array( 'woocommerce.shop', 'woocommerce.cart', 'woocommerce.checkout' ),
				'priority'    => 10,
				'tier'        => self::TIER_FREE,
				'build_steps' => array( $this, 'steps_woo_purchase' ),
			),
			array(
				'id'          => 'woo_express_checkout',
				'label'       => __( 'Express Checkout Funnel', 'opti-behavior' ),
				'description' => __( 'Ad and direct traffic that lands straight on a product and buys without browsing the shop.', 'opti-behavior' ),
				'icon'        => 'zap',
				'requires'    => array( 'woocommerce.product_base', 'woocommerce.cart', 'woocommerce.checkout' ),
				'priority'    => 20,
				'tier'        => self::TIER_FREE,
				'build_steps' => array( $this, 'steps_woo_express_checkout' ),
			),
			array(
				'id'          => 'woo_cart_recovery',
				'label'       => __( 'Cart Recovery Funnel', 'opti-behavior' ),
				'description' => __( 'Isolates the biggest known leak: how many shoppers go from cart to a completed order.', 'opti-behavior' ),
				'icon'        => 'shopping-bag',
				'requires'    => array( 'woocommerce.cart', 'woocommerce.checkout' ),
				'priority'    => 30,
				'tier'        => self::TIER_FREE,
				'build_steps' => array( $this, 'steps_woo_cart_recovery' ),
			),
			array(
				'id'          => 'woo_category_purchase',
				'label'       => __( 'Category to Cart Funnel', 'opti-behavior' ),
				'description' => __( 'How category browsing turns into product views and cart additions.', 'opti-behavior' ),
				'icon'        => 'layout-grid',
				'requires'    => array( 'woocommerce.category_base', 'woocommerce.product_base', 'woocommerce.cart' ),
				'priority'    => 110,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),
			array(
				'id'          => 'woo_search_purchase',
				'label'       => __( 'Search to Purchase Funnel', 'opti-behavior' ),
				'description' => __( 'What shoppers who use the store search do next.', 'opti-behavior' ),
				'icon'        => 'search',
				'requires'    => array( 'woocommerce.product_base', 'woocommerce.cart', 'woocommerce.checkout' ),
				'priority'    => 120,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),
			array(
				'id'          => 'woo_account_purchase',
				'label'       => __( 'Account to Purchase Funnel', 'opti-behavior' ),
				'description' => __( 'Returning customers who start from their account and buy again.', 'opti-behavior' ),
				'icon'        => 'user-check',
				'requires'    => array( 'woocommerce.account', 'woocommerce.checkout' ),
				'priority'    => 130,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),
			array(
				// Documented, never offered: the add-to-cart click is an EVENT,
				// and event match types are out of scope for v1 (spec.md §4.5-a).
				'id'          => 'woo_add_to_cart',
				'label'       => __( 'Add-to-Cart Micro-funnel', 'opti-behavior' ),
				'description' => __( 'Product view to add-to-cart click. Requires event-based steps, planned for a future release.', 'opti-behavior' ),
				'icon'        => 'mouse-pointer-click',
				'requires'    => array( 'woocommerce.product_base', 'woocommerce.cart' ),
				'priority'    => 140,
				'tier'        => self::TIER_PRO,
				'phase'       => self::PHASE_DEFERRED,
				'build_steps' => null,
			),

			// --- Easy Digital Downloads --------------------------------------
			array(
				'id'          => 'edd_digital_purchase',
				'label'       => __( 'Digital Purchase Funnel', 'opti-behavior' ),
				'description' => __( 'Downloads archive, download page, checkout and purchase confirmation.', 'opti-behavior' ),
				'icon'        => 'download',
				'requires'    => array( 'edd.checkout' ),
				'priority'    => 40,
				'tier'        => self::TIER_FREE,
				'build_steps' => array( $this, 'steps_edd_digital_purchase' ),
			),

			// --- Membership ---------------------------------------------------
			array(
				'id'          => 'membership_subscription',
				'label'       => __( 'Membership Subscription Funnel', 'opti-behavior' ),
				'description' => __( 'From the membership plans to a confirmed subscription.', 'opti-behavior' ),
				'icon'        => 'crown',
				'requires'    => array( 'membership' ),
				'priority'    => 50,
				'tier'        => self::TIER_FREE,
				'build_steps' => array( $this, 'steps_membership_subscription' ),
			),
			array(
				'id'          => 'membership_retention',
				'label'       => __( 'Member Retention Funnel', 'opti-behavior' ),
				'description' => __( 'Existing members returning to the plans page and upgrading.', 'opti-behavior' ),
				'icon'        => 'repeat',
				'requires'    => array( 'membership.account', 'membership.levels' ),
				'priority'    => 220,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),

			// --- Signup / SaaS -------------------------------------------------
			array(
				'id'          => 'signup',
				'label'       => __( 'Signup Funnel', 'opti-behavior' ),
				'description' => __( 'Pricing to registration to the new account.', 'opti-behavior' ),
				'icon'        => 'user-plus',
				'requires'    => array( 'signup.register' ),
				'priority'    => 60,
				'tier'        => self::TIER_FREE,
				'build_steps' => array( $this, 'steps_signup' ),
			),
			array(
				'id'          => 'signup_onboarding',
				'label'       => __( 'Onboarding Funnel', 'opti-behavior' ),
				'description' => __( 'What new accounts do after signing up.', 'opti-behavior' ),
				'icon'        => 'compass',
				'requires'    => array( 'signup.account' ),
				'priority'    => 180,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),

			// --- Lead generation ----------------------------------------------
			array(
				'id'          => 'lead_generation',
				'label'       => __( 'Lead Generation Funnel', 'opti-behavior' ),
				'description' => __( 'Home page to offer page to contact form to thank-you.', 'opti-behavior' ),
				'icon'        => 'mail',
				'requires'    => array( 'lead.contact' ),
				'priority'    => 70,
				'tier'        => self::TIER_FREE,
				'build_steps' => array( $this, 'steps_lead_generation' ),
			),
			array(
				'id'          => 'lead_quote_request',
				'label'       => __( 'Quote Request Funnel', 'opti-behavior' ),
				'description' => __( 'Pricing page to the request form to the thank-you page.', 'opti-behavior' ),
				'icon'        => 'file-signature',
				'requires'    => array( 'signup.pricing', 'lead.contact', 'lead.thankyou' ),
				'priority'    => 170,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),

			// --- Blog / content -------------------------------------------------
			array(
				'id'          => 'blog_engagement',
				'label'       => __( 'Blog Engagement Funnel', 'opti-behavior' ),
				'description' => __( 'Home page to blog index to a single post to your contact page.', 'opti-behavior' ),
				'icon'        => 'file-text',
				'requires'    => array( array( 'blog.index', 'blog.post_base' ) ),
				'priority'    => 80,
				'tier'        => self::TIER_FREE,
				'build_steps' => array( $this, 'steps_blog_engagement' ),
			),
			array(
				'id'          => 'blog_content_conversion',
				'label'       => __( 'Content to Conversion Funnel', 'opti-behavior' ),
				'description' => __( 'Which articles actually send readers to your offer and convert them.', 'opti-behavior' ),
				'icon'        => 'trending-up',
				'requires'    => array( 'blog.post_base', 'lead.thankyou' ),
				'priority'    => 150,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),
			array(
				'id'          => 'blog_category_comment',
				'label'       => __( 'Category to Comment Funnel', 'opti-behavior' ),
				'description' => __( 'Category browsing to a post to a posted comment.', 'opti-behavior' ),
				'icon'        => 'message-square',
				'requires'    => array( 'blog.category_base', 'blog.post_base' ),
				'priority'    => 160,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),

			// --- LMS ------------------------------------------------------------
			array(
				'id'          => 'lms_course_enrollment',
				'label'       => __( 'Course Enrollment Funnel', 'opti-behavior' ),
				'description' => __( 'Course catalog to a course page to checkout to the student dashboard.', 'opti-behavior' ),
				'icon'        => 'graduation-cap',
				'requires'    => array( array( 'lms.archive', 'lms.course_base' ), 'lms.checkout' ),
				'priority'    => 190,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),
			array(
				'id'          => 'lms_course_completion',
				'label'       => __( 'Course Completion Funnel', 'opti-behavior' ),
				'description' => __( 'Course page to lessons to the student dashboard.', 'opti-behavior' ),
				'icon'        => 'award',
				'requires'    => array( 'lms.course_base', 'lms.lesson_base' ),
				'priority'    => 200,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),

			// --- Booking ----------------------------------------------------------
			array(
				'id'          => 'booking',
				'label'       => __( 'Booking Funnel', 'opti-behavior' ),
				'description' => __( 'Services list to the booking form to the confirmation page.', 'opti-behavior' ),
				'icon'        => 'calendar-check',
				'requires'    => array( 'booking.form' ),
				'priority'    => 210,
				'tier'        => self::TIER_PRO,
				'build_steps' => null,
			),
		);
	}

	// -------------------------------------------------------------------------
	// Step builders (Free tier)
	// -------------------------------------------------------------------------

	/**
	 * Recipe 1 — WooCommerce Purchase Funnel.
	 *
	 * @param array $context Site context.
	 * @return array
	 */
	public function steps_woo_purchase( array $context ) {
		return array(
			$this->step( __( 'Shop', 'opti-behavior' ), $this->url( $context, 'woocommerce.shop' ) ),
			$this->step( __( 'Product page', 'opti-behavior' ), $this->url( $context, 'woocommerce.product_base' ) ),
			$this->step( __( 'Cart', 'opti-behavior' ), $this->url( $context, 'woocommerce.cart' ) ),
			$this->step( __( 'Checkout', 'opti-behavior' ), $this->url( $context, 'woocommerce.checkout' ) ),
			$this->step( __( 'Order received', 'opti-behavior' ), $this->url( $context, 'woocommerce.thankyou' ) ),
		);
	}

	/**
	 * Recipe 2 — WooCommerce Express Checkout (no shop hop).
	 *
	 * @param array $context Site context.
	 * @return array
	 */
	public function steps_woo_express_checkout( array $context ) {
		return array(
			$this->step( __( 'Product page', 'opti-behavior' ), $this->url( $context, 'woocommerce.product_base' ) ),
			$this->step( __( 'Cart', 'opti-behavior' ), $this->url( $context, 'woocommerce.cart' ) ),
			$this->step( __( 'Checkout', 'opti-behavior' ), $this->url( $context, 'woocommerce.checkout' ) ),
			$this->step( __( 'Order received', 'opti-behavior' ), $this->url( $context, 'woocommerce.thankyou' ) ),
		);
	}

	/**
	 * Recipe 3 — WooCommerce Cart Recovery.
	 *
	 * @param array $context Site context.
	 * @return array
	 */
	public function steps_woo_cart_recovery( array $context ) {
		return array(
			$this->step( __( 'Cart', 'opti-behavior' ), $this->url( $context, 'woocommerce.cart' ) ),
			$this->step( __( 'Checkout', 'opti-behavior' ), $this->url( $context, 'woocommerce.checkout' ) ),
			$this->step( __( 'Order received', 'opti-behavior' ), $this->url( $context, 'woocommerce.thankyou' ) ),
		);
	}

	/**
	 * Recipe 8 — EDD Digital Purchase.
	 *
	 * The archive and the download base are frequently the same path; the
	 * duplicate is dropped by validate_steps() rather than special-cased here.
	 *
	 * @param array $context Site context.
	 * @return array
	 */
	public function steps_edd_digital_purchase( array $context ) {
		return array(
			$this->step( __( 'Downloads', 'opti-behavior' ), $this->url( $context, 'edd.archive' ) ),
			$this->step( __( 'Download page', 'opti-behavior' ), $this->url( $context, 'edd.download_base' ) ),
			$this->step( __( 'Checkout', 'opti-behavior' ), $this->url( $context, 'edd.checkout' ) ),
			$this->step( __( 'Purchase confirmation', 'opti-behavior' ), $this->url( $context, 'edd.success' ) ),
		);
	}

	/**
	 * Recipe 9 — Blog Engagement.
	 *
	 * @param array $context Site context.
	 * @return array
	 */
	public function steps_blog_engagement( array $context ) {
		return array(
			$this->home_step( $context ),
			$this->step( __( 'Blog index', 'opti-behavior' ), $this->url( $context, 'blog.index' ) ),
			$this->step( __( 'Article', 'opti-behavior' ), $this->url( $context, 'blog.post_base' ) ),
			$this->step( __( 'Contact', 'opti-behavior' ), $this->url( $context, 'lead.contact' ) ),
		);
	}

	/**
	 * Recipe 12 — Lead Generation.
	 *
	 * @param array $context Site context.
	 * @return array
	 */
	public function steps_lead_generation( array $context ) {
		return array(
			$this->home_step( $context ),
			$this->step( __( 'Offer page', 'opti-behavior' ), $this->url( $context, 'signup.pricing' ) ),
			$this->step( __( 'Contact', 'opti-behavior' ), $this->url( $context, 'lead.contact' ) ),
			$this->step( __( 'Thank you', 'opti-behavior' ), $this->url( $context, 'lead.thankyou' ) ),
		);
	}

	/**
	 * Recipe 14 — Signup.
	 *
	 * @param array $context Site context.
	 * @return array
	 */
	public function steps_signup( array $context ) {
		return array(
			$this->step( __( 'Pricing', 'opti-behavior' ), $this->url( $context, 'signup.pricing' ) ),
			$this->step( __( 'Registration', 'opti-behavior' ), $this->url( $context, 'signup.register' ) ),
			$this->step( __( 'Account', 'opti-behavior' ), $this->url( $context, 'signup.account' ) ),
		);
	}

	/**
	 * Recipe 20 — Membership Subscription.
	 *
	 * PER-PROVIDER step map (locked user decision): the three membership plugins
	 * expose genuinely different journeys, so each gets the steps it can really
	 * resolve instead of one aliased lowest-common-denominator funnel.
	 *
	 *  - MemberPress : plans (membership CPT base) -> confirmation -> account.
	 *    Registration AND payment both happen on the membership product page,
	 *    so there is no separate checkout URL to point a step at.
	 *  - PMPro       : levels -> checkout -> confirmation -> account.
	 *  - RCP         : registration -> confirmation -> account. RCP has no
	 *    levels page; its registration form IS the plan chooser.
	 *
	 * An unknown provider (appended by a filter) yields no steps rather than a
	 * guessed journey.
	 *
	 * @param array $context Site context.
	 * @return array
	 */
	public function steps_membership_subscription( array $context ) {
		$provider = $this->url( $context, 'membership.provider' );

		$maps = array(
			'memberpress' => array(
				array( 'levels', __( 'Membership plans', 'opti-behavior' ) ),
				array( 'confirmation', __( 'Confirmation', 'opti-behavior' ) ),
				array( 'account', __( 'Member account', 'opti-behavior' ) ),
			),
			'pmpro'       => array(
				array( 'levels', __( 'Membership levels', 'opti-behavior' ) ),
				array( 'checkout', __( 'Checkout', 'opti-behavior' ) ),
				array( 'confirmation', __( 'Confirmation', 'opti-behavior' ) ),
				array( 'account', __( 'Member account', 'opti-behavior' ) ),
			),
			'rcp'         => array(
				array( 'register', __( 'Registration', 'opti-behavior' ) ),
				array( 'confirmation', __( 'Confirmation', 'opti-behavior' ) ),
				array( 'account', __( 'Member account', 'opti-behavior' ) ),
			),
		);

		if ( ! isset( $maps[ $provider ] ) ) {
			return array();
		}

		$steps = array();
		foreach ( $maps[ $provider ] as $entry ) {
			$steps[] = $this->step( $entry[1], $this->url( $context, 'membership.' . $entry[0] ) );
		}

		return $steps;
	}

	// -------------------------------------------------------------------------
	// Step helpers
	// -------------------------------------------------------------------------

	/**
	 * A `contains` step, or null when the URL did not resolve.
	 *
	 * `contains` is the default on purpose (rule 2): it is the only match type
	 * with identical semantics in the PHP matcher, the JS tracker and the Pro
	 * backfill matcher, and it survives permalink and query-string variations.
	 *
	 * @param string $name       Step label.
	 * @param string $pattern    Site-relative path from the context.
	 * @param string $match_type Match type.
	 * @return array|null
	 */
	protected function step( $name, $pattern, $match_type = 'contains' ) {
		if ( ! is_string( $pattern ) || '' === $pattern ) {
			return null;
		}

		return array(
			'name'        => (string) $name,
			'match_type'  => $match_type,
			'url_pattern' => $pattern,
		);
	}

	/**
	 * The site home step — the ONLY `exact` step this registry generates
	 * (rule 3).
	 *
	 * The pattern comes from `site.home`, never a hardcoded '/': on a
	 * sub-directory install the home path is '/wordpress/' and '/' would match
	 * nothing.
	 *
	 * @param array  $context Site context.
	 * @param string $name    Optional step label.
	 * @return array|null
	 */
	protected function home_step( array $context, $name = '' ) {
		$home = isset( $context['site']['home'] ) ? (string) $context['site']['home'] : '';
		if ( '' === $name ) {
			$name = __( 'Home page', 'opti-behavior' );
		}

		return $this->step( $name, $home, 'exact' );
	}

	/**
	 * Whether a pattern is a substring of any earlier accepted pattern (rule 5).
	 *
	 * Case-insensitive, because both matchers compare case-insensitively. An
	 * identical repeat is a substring of itself, so duplicates are dropped by
	 * the same rule.
	 *
	 * @param string $pattern  Candidate pattern.
	 * @param array  $accepted Already accepted steps.
	 * @return bool
	 */
	protected function is_substring_of_earlier( $pattern, array $accepted ) {
		foreach ( $accepted as $step ) {
			if ( false !== stripos( $step['url_pattern'], $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a regex pattern compiles (rule 6). Mirrors the matcher's own
	 * delimiter handling so a pattern that validates here cannot fail there.
	 *
	 * @param string $pattern Regex source.
	 * @return bool
	 */
	protected function is_valid_regex( $pattern ) {
		$safe = str_replace( '#', '\#', $pattern );
		return false !== @preg_match( '#' . $safe . '#', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional: an invalid pattern must be rejected, not raise a warning. Mirrors url_matches_pattern().
	}

	// -------------------------------------------------------------------------
	// Context access
	// -------------------------------------------------------------------------

	/**
	 * Value of a dotted context path ('woocommerce.cart'), or ''.
	 *
	 * @param array  $context Site context.
	 * @param string $path    Dotted path.
	 * @return string
	 */
	protected function url( array $context, $path ) {
		$parts = explode( '.', (string) $path, 2 );
		if ( count( $parts ) < 2 ) {
			return '';
		}

		list( $family, $key ) = $parts;

		if ( ! isset( $context[ $family ][ $key ] ) || ! is_string( $context[ $family ][ $key ] ) ) {
			return '';
		}

		return $context[ $family ][ $key ];
	}

	/**
	 * Whether every requirement resolves.
	 *
	 * @param array $requires Requirement list (dotted paths / ANY-OF groups).
	 * @param array $context  Site context.
	 * @return bool
	 */
	protected function requirements_met( array $requires, array $context ) {
		foreach ( $requires as $requirement ) {
			if ( is_array( $requirement ) ) {
				$any = false;
				foreach ( $requirement as $alternative ) {
					if ( $this->context_has( $context, $alternative ) ) {
						$any = true;
						break;
					}
				}
				if ( ! $any ) {
					return false;
				}
				continue;
			}

			if ( ! $this->context_has( $context, $requirement ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a requirement resolves.
	 *
	 * A bare family ('woocommerce') means the family published at least one URL
	 * — metadata alone (`provider`, `form_plugin`) does not count, because a
	 * detected-but-unresolved family must never make a recipe offerable
	 * (spec.md §2.1).
	 *
	 * @param array  $context Site context.
	 * @param string $path    Dotted path or bare family.
	 * @return bool
	 */
	protected function context_has( array $context, $path ) {
		$path = (string) $path;

		if ( false !== strpos( $path, '.' ) ) {
			return '' !== $this->url( $context, $path );
		}

		if ( ! isset( $context[ $path ] ) || ! is_array( $context[ $path ] ) ) {
			return false;
		}

		foreach ( $context[ $path ] as $key => $value ) {
			if ( in_array( $key, self::META_KEYS, true ) ) {
				continue;
			}
			if ( is_string( $value ) && '' !== $value ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Normalization
	// -------------------------------------------------------------------------

	/**
	 * Normalize a whole catalog (used on the filtered result, so a malformed
	 * third-party append can never reach the panel).
	 *
	 * @param array $recipes Raw recipes (list or id-keyed).
	 * @return array<string,array>
	 */
	protected function normalize_catalog( array $recipes ) {
		$out = array();

		foreach ( $recipes as $key => $recipe ) {
			if ( ! is_array( $recipe ) ) {
				continue;
			}
			if ( ! isset( $recipe['id'] ) && is_string( $key ) ) {
				$recipe['id'] = $key;
			}
			$normalized = $this->normalize_recipe( $recipe );
			if ( null !== $normalized ) {
				$out[ $normalized['id'] ] = $normalized;
			}
		}

		return $out;
	}

	/**
	 * Fill in defaults and reject a recipe that cannot be rendered.
	 *
	 * @param array $recipe Raw recipe.
	 * @return array|null
	 */
	protected function normalize_recipe( $recipe ) {
		if ( ! is_array( $recipe ) ) {
			return null;
		}

		$id = isset( $recipe['id'] ) ? trim( (string) $recipe['id'] ) : '';
		if ( '' === $id ) {
			return null;
		}

		$label = isset( $recipe['label'] ) ? trim( (string) $recipe['label'] ) : '';
		if ( '' === $label ) {
			return null;
		}

		$tier = isset( $recipe['tier'] ) ? (string) $recipe['tier'] : self::TIER_FREE;
		if ( self::TIER_PRO !== $tier ) {
			$tier = self::TIER_FREE;
		}

		$phase = isset( $recipe['phase'] ) ? (int) $recipe['phase'] : self::PHASE_SHIPPED;
		if ( $phase < self::PHASE_SHIPPED ) {
			$phase = self::PHASE_SHIPPED;
		}

		$builder = isset( $recipe['build_steps'] ) ? $recipe['build_steps'] : null;
		if ( null !== $builder && ! is_callable( $builder ) ) {
			$builder = null;
		}

		return array(
			'id'          => $id,
			'label'       => $label,
			'description' => isset( $recipe['description'] ) ? (string) $recipe['description'] : '',
			'icon'        => isset( $recipe['icon'] ) ? (string) $recipe['icon'] : 'filter',
			'requires'    => ( isset( $recipe['requires'] ) && is_array( $recipe['requires'] ) ) ? array_values( $recipe['requires'] ) : array(),
			'priority'    => isset( $recipe['priority'] ) ? (int) $recipe['priority'] : 500,
			'tier'        => $tier,
			'phase'       => $phase,
			'build_steps' => $builder,
		);
	}

	/**
	 * Catalog ordering: priority ascending, then id, so the order is stable
	 * whatever the filter appended.
	 *
	 * @param array $a First recipe.
	 * @param array $b Second recipe.
	 * @return int
	 */
	protected function compare_recipes( $a, $b ) {
		if ( $a['priority'] === $b['priority'] ) {
			return strcmp( $a['id'], $b['id'] );
		}
		return ( $a['priority'] < $b['priority'] ) ? -1 : 1;
	}
}
