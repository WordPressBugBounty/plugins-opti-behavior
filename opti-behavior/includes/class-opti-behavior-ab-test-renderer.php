<?php
/**
 * A/B Test Server-Side Variant Renderer
 *
 * Delivers variants server-side for flicker-free A/B testing.
 * Handles page splits (redirect), headline tests (content filter),
 * element tests (output buffering), and shortcodes.
 *
 * @package opti-behavior
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opti_Behavior_AB_Test_Renderer {

	/**
	 * Singleton instance.
	 *
	 * @var Opti_Behavior_AB_Test_Renderer|null
	 */
	private static $instance = null;

	/**
	 * Active tests for the current page.
	 *
	 * @var array
	 */
	private $active_tests = array();

	/**
	 * Tests whose target page is NOT the current request, but whose page_visit
	 * goal URL matches the current request. We emit variant data for these
	 * (using the existing opti_ab_{test_id} cookie assignment) so the tracker
	 * can fire the page_visit conversion, but we do NOT run them through the
	 * full active-test pipeline (no redirects, no content filtering, no new
	 * bucket assignment, no impression).
	 *
	 * @var array  array of test objects.
	 */
	private $goal_only_tests = array();

	/**
	 * Current variant assignments for this request.
	 *
	 * @var array { test_id => variant_object }
	 */
	private $current_variants = array();

	/**
	 * Whether full-page caching was already disabled for this request.
	 *
	 * @since 1.7.1
	 * @var bool
	 */
	private $page_cache_disabled = false;

	/**
	 * Whether output buffering is active.
	 *
	 * @var bool
	 */
	private $ob_active = false;

	/**
	 * Guards against re-running load_active_tests() on requests that produced
	 * an empty $active_tests but a non-empty $goal_only_tests (where the legacy
	 * `if ( ! empty( $this->active_tests ) ) return;` short-circuit would
	 * otherwise re-execute the loader on every call site and duplicate
	 * goal-only entries — DEF-AB-PV-FIX).
	 *
	 * @var bool
	 */
	private $active_tests_loaded = false;

	/**
	 * Permanent winner changes to apply (from archived tests).
	 *
	 * @var array
	 */
	private $permanent_changes = array();

	/**
	 * Get singleton instance.
	 *
	 * @return Opti_Behavior_AB_Test_Renderer
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.3.0
	 */
	public function init() {
		// Heatmap variant preview: force-apply a specific variant in the heatmap iframe.
		add_action( 'template_redirect', array( $this, 'handle_heatmap_variant_preview' ), 0 );

		// Visual editor preview: bypass bucketer so no impression is recorded.
		add_action( 'template_redirect', array( $this, 'handle_visual_editor_preview' ), 0 );

		// Permanent page-split winners: redirect control URL to the applied winner.
		add_action( 'template_redirect', array( $this, 'handle_applied_page_split_redirect' ), 0 );

		// Early redirect for page split and split URL tests (priority 1 = before everything).
		add_action( 'template_redirect', array( $this, 'handle_redirects' ), 1 );

		// Headline test: filter post title and content.
		add_filter( 'the_title', array( $this, 'filter_title' ), 1, 2 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 1 );

		// Element test: output buffering.
		add_action( 'template_redirect', array( $this, 'maybe_start_output_buffer' ), 2 );

		// Permanent winner changes: apply archived/completed test winners.
		add_action( 'template_redirect', array( $this, 'apply_permanent_winner_changes' ), 3 );

		// Register shortcodes.
		add_shortcode( 'opti_ab', array( $this, 'shortcode_handler' ) );
		add_shortcode( 'opti_ab_goal', array( $this, 'goal_shortcode_handler' ) );

		// Inject anti-flicker CSS in head.
		add_action( 'wp_head', array( $this, 'inject_anti_flicker_css' ), 1 );

		// Pass variant data to frontend JS.
		add_action( 'wp_footer', array( $this, 'output_variant_data_script' ), 1 );
	}

	/**
	 * Handle variant preview.
	 *
	 * When a page is loaded with opti_ab_preview_variant and
	 * opti_ab_preview_test query params, force-apply that specific
	 * variant's element/CSS changes to the page — bypassing normal
	 * cookie-based bucketing and skipping impression tracking.
	 *
	 * Two callers use this path:
	 *  - Heatmap variant preview (legacy, also sets opti_heatmap_preview)
	 *  - Visual editor's "Preview" button (opens the live frontend in a
	 *    new tab so the admin sees exactly what real visitors will see)
	 *
	 * Access is gated via current_user_can('manage_options') because the
	 * ability to force-render an arbitrary variant bypasses bucketing.
	 *
	 * @since 1.3.1
	 */
	public function handle_heatmap_variant_preview() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['opti_ab_preview_variant'] ) || empty( $_GET['opti_ab_preview_test'] ) ) {
			return;
		}

		// Admin-only — forcing a specific variant bypasses normal bucketing.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$variant_id = absint( $_GET['opti_ab_preview_variant'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$test_id    = absint( $_GET['opti_ab_preview_test'] );

		if ( ! $variant_id || ! $test_id ) {
			return;
		}

		// Load the test — even if completed/paused — so heatmaps work post-test.
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return;
		}

		// Load variants for this test.
		$variants = Opti_Behavior_AB_Test_Database::get_variants( $test_id );
		if ( empty( $variants ) ) {
			return;
		}

		// Find the specific variant.
		$target_variant = null;
		foreach ( $variants as $opti_behavior_variant ) {
			if ( (int) $opti_behavior_variant->id === $variant_id ) {
				$target_variant = $opti_behavior_variant;
				break;
			}
		}

		if ( ! $target_variant ) {
			return;
		}

		// Force this variant into current_variants — bypasses the bucketer.
		$this->current_variants[ $test_id ] = $target_variant;

		// Ensure the test is in the active_tests array.
		$opti_behavior_found = false;
		foreach ( $this->active_tests as $opti_behavior_test ) {
			if ( (int) $opti_behavior_test->id === $test_id ) {
				$opti_behavior_found = true;
				break;
			}
		}
		if ( ! $opti_behavior_found ) {
			$this->active_tests[] = $test;
		}

		// Preview mode applies variant_data.changes regardless of test_type
		// — in production page_split redirects visitors away, but in preview
		// we want to SHOW the same DOM changes the visual editor stored so
		// admins can verify their work on the live frontend too.
		$opti_behavior_vdata = json_decode( $target_variant->variant_data, true );
		if ( is_array( $opti_behavior_vdata ) && ! empty( $opti_behavior_vdata['changes'] ) && ! $this->ob_active ) {
			$this->ob_active = true;
			ob_start( array( $this, 'process_output_buffer' ) );
		}
	}

	/**
	 * Handle visual editor preview — bypass bucketer, record no impression.
	 *
	 * When the visual editor loads the target page in its iframe
	 * (?opti_ab_visual_editor=1&opti_ab_test_id=X&opti_ab_variant_id=Y),
	 * pre-populate current_variants with the requested variant so the
	 * bucketer is never called and no impression row is written.
	 *
	 * @since 1.3.2
	 */
	public function handle_visual_editor_preview() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['opti_ab_visual_editor'] ) ) {
			return;
		}

		// Restrict to logged-in admins only.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$test_id = isset( $_GET['opti_ab_test_id'] ) ? absint( $_GET['opti_ab_test_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$variant_id = isset( $_GET['opti_ab_variant_id'] ) ? absint( $_GET['opti_ab_variant_id'] ) : 0;

		if ( ! $test_id || ! $variant_id ) {
			return;
		}

		// Load the test regardless of status (editor is used before and during running).
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return;
		}

		$variants = Opti_Behavior_AB_Test_Database::get_variants( $test_id );
		if ( empty( $variants ) ) {
			return;
		}

		$target_variant = null;
		foreach ( $variants as $opti_behavior_variant ) {
			if ( (int) $opti_behavior_variant->id === $variant_id ) {
				$target_variant = $opti_behavior_variant;
				break;
			}
		}

		if ( ! $target_variant ) {
			return;
		}

		// Pre-populate current_variants — get_current_variant() returns this
		// immediately without calling the bucketer, so no impression is recorded.
		$this->current_variants[ $test_id ] = $target_variant;

		// Ensure this test is in active_tests so content filters apply correctly.
		$opti_behavior_found = false;
		foreach ( $this->active_tests as $opti_behavior_test ) {
			if ( (int) $opti_behavior_test->id === $test_id ) {
				$opti_behavior_found = true;
				break;
			}
		}
		if ( ! $opti_behavior_found ) {
			$this->active_tests[] = $test;
		}

		// For element/css tests, start output buffering if the variant has changes.
		if ( in_array( $test->test_type, array( 'element', 'css' ), true ) ) {
			$opti_behavior_vdata = json_decode( $target_variant->variant_data, true );
			if ( is_array( $opti_behavior_vdata ) && ! empty( $opti_behavior_vdata['changes'] ) && ! $this->ob_active ) {
				$this->ob_active = true;
				ob_start( array( $this, 'process_output_buffer' ) );
			}
		}
	}

	/**
	 * Handle redirect-based tests (page_split, split URL).
	 *
	 * Fires at template_redirect priority 1 — before any output.
	 *
	 * @since 1.3.0
	 */
	public function handle_redirects() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Don't run for bots or preview requests.
		if ( is_preview() || is_customize_preview() ) {
			return;
		}

		// Don't redirect when page is loaded inside the heatmap preview iframe.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['opti_heatmap_preview'] ) ) {
			return;
		}

		// Don't redirect when the visual editor is previewing the page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['opti_ab_visual_editor'] ) ) {
			return;
		}

		$this->load_active_tests();

		foreach ( $this->active_tests as $test ) {
			if ( ! in_array( $test->test_type, array( 'page_split' ), true ) ) {
				continue;
			}

			$variant = $this->get_current_variant( $test );
			if ( ! $variant || ! empty( $variant->is_control ) ) {
				continue; // Control = show original page.
			}

			$variant_data = json_decode( $variant->variant_data, true );
			if ( ! is_array( $variant_data ) ) {
				continue;
			}

			// Page split: redirect to variant URL.
			if ( 'page_split' === $test->test_type && ! empty( $variant_data['redirect_url'] ) ) {
				$redirect_url = esc_url_raw( $variant_data['redirect_url'] );
				// Compare by path only so the presence of ?opti_ab_variant=XXX
				// (or any other query string) on the current request does not
				// cause a redirect loop when the visitor is already on the
				// variant page.
				$redirect_path = untrailingslashit( wp_parse_url( $redirect_url, PHP_URL_PATH ) );
				$current_path  = untrailingslashit( wp_parse_url( $this->get_current_url(), PHP_URL_PATH ) );
				if ( $redirect_url && $redirect_path !== $current_path ) {
					// Add parameter so the destination page can identify its variant.
					$redirect_url = add_query_arg( 'opti_ab_variant', $variant->id, $redirect_url );
					wp_safe_redirect( $redirect_url, 302 );
					exit;
				}
			}
		}
	}

	/**
	 * Redirect permanently-applied page-split winners.
	 *
	 * apply_winner() stores page-split redirects in options keyed by test ID.
	 * Active-test redirects only run for status=running tests, so applied
	 * page-split winners need their own frontend handler.
	 *
	 * @since 1.3.4
	 */
	public function handle_applied_page_split_redirect() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( is_preview() || is_customize_preview() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['opti_heatmap_preview'] ) || ! empty( $_GET['opti_ab_visual_editor'] ) ) {
			return;
		}

		global $wpdb;

		$current_url  = $this->get_current_url();
		$current_path = $this->normalize_url_path( $current_url );
		if ( '' === $current_path ) {
			return;
		}

		$cache_key = 'applied_page_split_redirect_tests';
		$tests     = wp_cache_get( $cache_key, 'opti_behavior_ab' );

		if ( false === $tests ) {
			$table = esc_sql( $wpdb->prefix . 'optibehavior_ab_tests' );
			$tests = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"SELECT id, target_url FROM {$table} WHERE status = %s AND test_type = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'applied',
					'page_split'
				)
			);
			wp_cache_set( $cache_key, $tests, 'opti_behavior_ab', MINUTE_IN_SECONDS );
		}

		if ( empty( $tests ) ) {
			return;
		}

		foreach ( $tests as $test ) {
			$option = get_option( 'opti_ab_page_split_redirect_' . absint( $test->id ), array() );
			if ( ! is_array( $option ) || empty( $option['to'] ) ) {
				continue;
			}

			$from_url  = ! empty( $option['from'] ) ? $option['from'] : $test->target_url;
			$from_path = $this->normalize_url_path( $from_url );
			if ( '' === $from_path || $from_path !== $current_path ) {
				continue;
			}

			$redirect_url  = esc_url_raw( $option['to'] );
			$redirect_path = $this->normalize_url_path( $redirect_url );
			if ( ! $redirect_url || '' === $redirect_path || $redirect_path === $current_path ) {
				continue;
			}

			$current_query  = wp_parse_url( $current_url, PHP_URL_QUERY );
			$redirect_query = wp_parse_url( $redirect_url, PHP_URL_QUERY );
			if ( $current_query && ! $redirect_query ) {
				$redirect_url .= ( false === strpos( $redirect_url, '?' ) ? '?' : '&' ) . $current_query;
			}

			wp_safe_redirect( $redirect_url, 301 );
			exit;
		}
	}

	/**
	 * Filter post title for headline tests.
	 *
	 * @since 1.3.0
	 * @param string $title   Post title.
	 * @param int    $post_id Post ID.
	 * @return string Filtered title.
	 */
	public function filter_title( $title, $post_id = 0 ) {
		if ( is_admin() || ! $post_id ) {
			return $title;
		}

		foreach ( $this->active_tests as $test ) {
			if ( 'headline' !== $test->test_type ) {
				continue;
			}

			if ( (int) $test->target_post_id !== (int) $post_id ) {
				continue;
			}

			$variant = $this->get_current_variant( $test );
			if ( ! $variant ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$opti_behavior_is_preview = ! empty( $_GET['opti_ab_preview_test'] );
			// In normal mode, skip control variants (original page). In preview mode, apply all changes.
			if ( ! $opti_behavior_is_preview && ! empty( $variant->is_control ) ) {
				continue;
			}

			$variant_data = json_decode( $variant->variant_data, true );
			if ( is_array( $variant_data ) && ! empty( $variant_data['title'] ) ) {
				return sanitize_text_field( $variant_data['title'] );
			}
		}

		return $title;
	}

	/**
	 * Filter post content for headline and element tests.
	 *
	 * @since 1.3.0
	 * @param string $content Post content.
	 * @return string Filtered content.
	 */
	public function filter_content( $content ) {
		if ( is_admin() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		foreach ( $this->active_tests as $test ) {
			if ( (int) $test->target_post_id !== (int) $post_id ) {
				continue;
			}

			$variant = $this->get_current_variant( $test );
			if ( ! $variant ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$opti_behavior_is_preview = ! empty( $_GET['opti_ab_preview_test'] );
			if ( ! $opti_behavior_is_preview && ! empty( $variant->is_control ) ) {
				continue;
			}

			$variant_data = json_decode( $variant->variant_data, true );
			if ( ! is_array( $variant_data ) ) {
				continue;
			}

			// Headline test: replace H1 in content.
			if ( 'headline' === $test->test_type && ! empty( $variant_data['title'] ) ) {
				$new_title = esc_html( $variant_data['title'] );
				$content   = preg_replace(
					'/<h1([^>]*)>.*?<\/h1>/is',
					'<h1$1>' . $new_title . '</h1>',
					$content,
					1 // Only replace first H1.
				);
			}

			// Element test: apply CSS selector-based changes.
			if ( 'element' === $test->test_type && ! empty( $variant_data['changes'] ) ) {
				$content = $this->apply_element_changes( $content, $variant_data['changes'] );
			}
		}

		return $content;
	}

	/**
	 * Start output buffering for element tests that need full-page DOM manipulation.
	 *
	 * @since 1.3.0
	 */
	public function maybe_start_output_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$this->load_active_tests();

		// Page-cache compatibility: a page with an active A/B test must NOT be
		// served from (or stored in) a full-page cache. Variant bucketing,
		// element/headline rendering and page_split redirects all happen
		// server-side, so a cached copy freezes the first visitor's variant
		// assignment into the HTML and every subsequent visitor silently
		// receives that same variant (100%/0% split instead of the configured
		// weights).
		if ( ! empty( $this->active_tests ) ) {
			$this->disable_page_cache_for_active_tests();
		}

		$needs_ob = false;
		foreach ( $this->active_tests as $test ) {
			if ( in_array( $test->test_type, array( 'element', 'css' ), true ) ) {
				$variant = $this->get_current_variant( $test );
				if ( $variant && empty( $variant->is_control ) ) {
					$needs_ob = true;
					break;
				}
			}
		}

		if ( $needs_ob && ! $this->ob_active ) {
			$this->ob_active = true;
			ob_start( array( $this, 'process_output_buffer' ) );
		}
	}

	/**
	 * Disable full-page caching for the current request.
	 *
	 * Called when at least one active A/B test matches the current page.
	 * Server-side bucketing means the generated HTML is visitor-specific,
	 * so page caches (WP Rocket, LiteSpeed, W3TC, WP Super Cache, ...)
	 * must neither store nor serve this page.
	 *
	 * @since 1.7.1
	 */
	private function disable_page_cache_for_active_tests() {
		if ( $this->page_cache_disabled ) {
			return;
		}
		$this->page_cache_disabled = true;

		// Generic constant honored by WP Rocket, W3TC, WP Super Cache, etc.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// WP Rocket: explicit belt-and-braces on top of DONOTCACHEPAGE.
		add_filter( 'do_rocket_generate_caching_files', '__return_false', PHP_INT_MAX );

		// LiteSpeed Cache.
		do_action( 'litespeed_control_set_nocache', 'opti-behavior: active A/B test on this page' );

		// Prevent browser/CDN caching of visitor-specific HTML.
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Apply permanent winner changes to the page.
	 *
	 * When a test winner is declared and "applied", the winning variant's
	 * changes are stored in post meta `_opti_behavior_ab_applied_changes`.
	 * This method reads that meta for the current page and applies the
	 * CSS/HTML/structural changes permanently via output buffering.
	 *
	 * Works on any WordPress page, any theme, any page builder — the
	 * changes are applied to the raw HTML via output buffering + inline
	 * JS, identical to how the live A/B test renderer works.
	 *
	 * @since 1.3.3
	 */
	public function apply_permanent_winner_changes() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Determine the post ID for the current request.
		// Works for singular posts/pages, static front pages, WooCommerce
		// shop pages, and any custom post type.
		$post_id = 0;
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
		} elseif ( is_front_page() && 'page' === get_option( 'show_on_front' ) ) {
			$post_id = (int) get_option( 'page_on_front' );
		} elseif ( is_home() && 'page' === get_option( 'show_on_front' ) ) {
			$post_id = (int) get_option( 'page_for_posts' );
		} elseif ( function_exists( 'wc_get_page_id' ) && is_shop() ) {
			$post_id = wc_get_page_id( 'shop' );
		}

		if ( ! $post_id ) {
			return;
		}

		$applied = get_post_meta( $post_id, '_opti_behavior_ab_applied_changes', true );
		if ( empty( $applied ) || ! is_array( $applied ) ) {
			return;
		}

		// Collect all changes from all applied winners for this post.
		$all_changes = array();
		foreach ( $applied as $test_id => $variant_data ) {
			if ( is_array( $variant_data ) && ! empty( $variant_data['changes'] ) ) {
				foreach ( $variant_data['changes'] as $change ) {
					$all_changes[] = $change;
				}
			}
		}

		if ( empty( $all_changes ) ) {
			return;
		}

		$this->permanent_changes = $all_changes;

		// If OB is already active (from an active A/B test), the existing
		// process_output_buffer will also apply permanent changes.
		// If not, start our own buffer.
		if ( ! $this->ob_active ) {
			$this->ob_active = true;
			ob_start( array( $this, 'process_permanent_changes_buffer' ) );
		}
	}

	/**
	 * Process the output buffer for permanent winner changes.
	 *
	 * @since 1.3.3
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	public function process_permanent_changes_buffer( $html ) {
		if ( empty( $html ) || empty( $this->permanent_changes ) ) {
			return $html;
		}

		return $this->apply_element_changes_to_html( $html, $this->permanent_changes );
	}

	/**
	 * Process the output buffer: apply element-level variant changes to the full HTML.
	 *
	 * @since 1.3.0
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	public function process_output_buffer( $html ) {
		if ( empty( $html ) ) {
			return $html;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$opti_behavior_is_pv = ! empty( $_GET['opti_ab_preview_test'] );

		foreach ( $this->active_tests as $test ) {
			// In production only element/css tests get their DOM patched.
			// In preview mode any variant with stored visual-editor changes
			// should render them so admins can verify the styling.
			if ( ! $opti_behavior_is_pv && ! in_array( $test->test_type, array( 'element', 'css' ), true ) ) {
				continue;
			}

			$variant = $this->get_current_variant( $test );
			if ( ! $variant ) {
				continue;
			}
			if ( ! $opti_behavior_is_pv && ! empty( $variant->is_control ) ) {
				continue;
			}

			$variant_data = json_decode( $variant->variant_data, true );
			if ( is_array( $variant_data ) && ! empty( $variant_data['changes'] ) ) {
				$html = $this->apply_element_changes_to_html( $html, $variant_data['changes'] );
			}
		}

		// Also apply permanent winner changes if any (from archived tests
		// whose winner was "shipped"). This ensures both active tests and
		// permanently applied winners coexist on the same page.
		if ( ! empty( $this->permanent_changes ) ) {
			$html = $this->apply_element_changes_to_html( $html, $this->permanent_changes );
		}

		return $html;
	}

	/**
	 * Apply element changes to full HTML.
	 *
	 * Supports two variant_data formats:
	 * - Action-based: { selector, action, value } (for headline/shortcode tests)
	 * - Element-test:  { selector, text, hide }   (for visual editor element tests)
	 *
	 * Uses regex for simple #id selectors and falls back to JavaScript injection
	 * for complex CSS selectors (class, tag.class, attribute, etc.).
	 *
	 * @since 1.3.0
	 * @param string $html    Full page HTML.
	 * @param array  $changes Array of change objects.
	 * @return string Modified HTML.
	 */
	private function apply_element_changes_to_html( $html, $changes ) {
		if ( empty( $changes ) || ! is_array( $changes ) ) {
			return $html;
		}

		$opti_behavior_js_changes = array(); // Changes that need JS (complex selectors).
		$opti_behavior_cascade   = array(); // Inheritable CSS rules for cascade <style>.

		// Properties that cascade to descendants (CSS inheritance). When a user
		// styles a container we emit `.sel, .sel *` rules so children's direct
		// theme rules don't win — matches the iframe helper's logic.
		$opti_behavior_inheritable = array(
			'color', 'font-family', 'font-weight', 'font-style', 'font-size',
			'line-height', 'letter-spacing', 'text-align', 'text-transform',
			'text-decoration', 'text-decoration-line',
		);

		// When the page is loaded inside the visual editor iframe, the VE
		// helper script handles structural changes (insert_after / duplicate /
		// move_delta) via applyChanges with proper marker tracking
		// (data-opti-ab-inserted) and revert support. The inline JS applier
		// does NOT mark inserted nodes, so if we also inject them here,
		// revertChanges can't remove them, causing duplication.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$opti_behavior_is_ve_iframe = ! empty( $_GET['opti_ab_visual_editor'] );

		foreach ( $changes as $change ) {
			// DOM-structural changes (Add Block / Paste / Duplicate / Move) can't
			// be applied with id-regex replacement — they always need the JS
			// fallback so the applier can query by selector and manipulate the
			// live DOM tree. They may legitimately carry an empty selector
			// meaning "append to main content area", so this branch runs
			// BEFORE the empty-selector guard below.
			//
			// Skip structural changes entirely when inside the visual editor
			// iframe — the VE helper will apply them with proper revert support.
			if ( ! empty( $change['insert_after'] )
				|| ! empty( $change['duplicate'] )
				|| isset( $change['move_delta'] ) ) {
				if ( ! $opti_behavior_is_ve_iframe ) {
					$opti_behavior_js_changes[] = $change;
				}
				continue;
			}

			if ( empty( $change['selector'] ) ) {
				continue;
			}

			$selector = $change['selector'];

			// Normalise: map element-test format { text, hide, css } to action-based format.
			$action = isset( $change['action'] ) ? $change['action'] : '';
			$value  = isset( $change['value'] ) ? $change['value'] : '';

			if ( ! $action && isset( $change['text'] ) && '' !== $change['text'] ) {
				$action = 'text';
				$value  = $change['text'];
			}
			if ( ! $action && isset( $change['html'] ) && '' !== $change['html'] ) {
				$action = 'html';
				$value  = $change['html'];
			}
			if ( ! $action && isset( $change['css'] ) && '' !== $change['css'] ) {
				$action = 'css';
				$value  = $change['css'];
			}
			if ( ! $action && ! empty( $change['hide'] ) ) {
				$action = 'visibility';
				$value  = 'hidden';
			}

			// Additional visual-editor-only change kinds are always handled by
			// the JS fallback (attributes, classes, js snippet, image_src).
			$opti_behavior_has_advanced = ! empty( $change['attributes'] )
				|| ! empty( $change['classes_add'] )
				|| ! empty( $change['classes_remove'] )
				|| ! empty( $change['js'] )
				|| ! empty( $change['image_src'] );

			if ( ! $action && ! $opti_behavior_has_advanced ) {
				continue;
			}

			// For css actions, always ALSO emit a cascade rule for inheritable
			// props so descendants of a container inherit the styling even when
			// they carry their own direct theme rules.
			if ( 'css' === $action && '' !== $value ) {
				$opti_behavior_inherit_decls = array();
				$opti_behavior_decls = explode( ';', $value );
				foreach ( $opti_behavior_decls as $opti_behavior_decl ) {
					$opti_behavior_parts = explode( ':', $opti_behavior_decl, 2 );
					if ( count( $opti_behavior_parts ) !== 2 ) {
						continue;
					}
					$opti_behavior_prop = strtolower( trim( $opti_behavior_parts[0] ) );
					$opti_behavior_val  = trim( $opti_behavior_parts[1] );
					$opti_behavior_val  = preg_replace( '/\s*!important\s*$/i', '', $opti_behavior_val );
					if ( '' === $opti_behavior_prop || '' === $opti_behavior_val ) {
						continue;
					}
					if ( in_array( $opti_behavior_prop, $opti_behavior_inheritable, true ) ) {
						$opti_behavior_inherit_decls[] = $opti_behavior_prop . ': ' . $opti_behavior_val . ' !important';
					}
				}
				if ( ! empty( $opti_behavior_inherit_decls ) ) {
					$opti_behavior_cascade[] = $selector . ', ' . $selector . ' * { ' . implode( '; ', $opti_behavior_inherit_decls ) . '; }';
				}
			}

			// Check whether the selector is a simple #id (regex-safe).
			$opti_behavior_is_id = preg_match( '/^#([\w-]+)$/', $selector, $opti_behavior_id_match );

			if ( $opti_behavior_is_id ) {
				$opti_behavior_id = $opti_behavior_id_match[1];

				switch ( $action ) {
					case 'text':
						$html = preg_replace(
							'/(<[^>]+id=["\']' . preg_quote( $opti_behavior_id, '/' ) . '["\'][^>]*>)(.*?)(<\/[^>]+>)/is',
							'$1' . esc_html( $value ) . '$3',
							$html,
							1
						);
						break;

					case 'html':
						$html = preg_replace(
							'/(<[^>]+id=["\']' . preg_quote( $opti_behavior_id, '/' ) . '["\'][^>]*>)(.*?)(<\/[^>]+>)/is',
							'$1' . wp_kses_post( $value ) . '$3',
							$html,
							1
						);
						break;

					case 'css':
						if ( ! empty( $value ) ) {
							$opti_behavior_css = $selector . ' { ' . wp_strip_all_tags( $value ) . ' }';
							$html = str_replace(
								'</head>',
								'<style class="opti-ab-variant-css">' . $opti_behavior_css . '</style></head>',
								$html
							);
						}
						break;

					case 'visibility':
						if ( 'hidden' === $value || '0' === $value || false === $value ) {
							$opti_behavior_css = $selector . ' { display: none !important; }';
							$html = str_replace(
								'</head>',
								'<style class="opti-ab-variant-css">' . $opti_behavior_css . '</style></head>',
								$html
							);
						}
						break;

					case 'attribute':
						if ( ! empty( $change['attr_name'] ) ) {
							$opti_behavior_attr = sanitize_key( $change['attr_name'] );
							$html = preg_replace(
								'/(<[^>]+id=["\']' . preg_quote( $opti_behavior_id, '/' ) . '["\'][^>]*?)(' . preg_quote( $opti_behavior_attr, '/' ) . '=["\'][^"\']*["\'])/is',
								'$1' . $opti_behavior_attr . '="' . esc_attr( $value ) . '"',
								$html,
								1
							);
						}
						break;
				}
			} else {
				// Complex CSS selector — defer to client-side JavaScript.
				// Pass the ORIGINAL change object so the JS can access hide,
				// css, attributes, classes_add/remove, js, image_src — not
				// just the flattened action/value pair.
				//
				// BUT: the inline JS applier only reads the visual-editor keys
				// (ch.text / ch.html / ch.css / ch.hide / ch.attributes). Wizard
				// element-test changes are stored in the flattened
				// { action, value } format instead, which the applier would
				// silently ignore — the variant then never renders for any
				// non-#id selector (e.g. "h1.wp-block-post-title"). Merge the
				// already-normalised action/value into the applier's keys
				// without overwriting explicit visual-editor keys.
				$opti_behavior_js_change = $change;
				if ( 'text' === $action && '' !== $value && ( ! isset( $opti_behavior_js_change['text'] ) || '' === $opti_behavior_js_change['text'] ) ) {
					$opti_behavior_js_change['text'] = $value;
				} elseif ( 'html' === $action && '' !== $value && ( ! isset( $opti_behavior_js_change['html'] ) || '' === $opti_behavior_js_change['html'] ) ) {
					$opti_behavior_js_change['html'] = $value;
				} elseif ( 'css' === $action && '' !== $value && ( ! isset( $opti_behavior_js_change['css'] ) || '' === $opti_behavior_js_change['css'] ) ) {
					$opti_behavior_js_change['css'] = $value;
				} elseif ( 'visibility' === $action && ( 'hidden' === $value || '0' === $value || false === $value ) && empty( $opti_behavior_js_change['hide'] ) ) {
					$opti_behavior_js_change['hide'] = true;
				} elseif ( 'attribute' === $action && ! empty( $change['attr_name'] ) ) {
					if ( ! isset( $opti_behavior_js_change['attributes'] ) || ! is_array( $opti_behavior_js_change['attributes'] ) ) {
						$opti_behavior_js_change['attributes'] = array();
					}
					if ( ! array_key_exists( $change['attr_name'], $opti_behavior_js_change['attributes'] ) ) {
						$opti_behavior_js_change['attributes'][ $change['attr_name'] ] = $value;
					}
				}
				$opti_behavior_js_changes[] = $opti_behavior_js_change;
			}
		}

		// Inject cascade stylesheet for inheritable props (text / font / align
		// …). This uses `.sel, .sel *` so descendants inherit even when they
		// have direct theme rules that would win over parent inheritance.
		if ( ! empty( $opti_behavior_cascade ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$opti_behavior_cascade_tag = '<style id="opti-ab-variant-cascade" data-no-optimize="1">' . implode( "\n", $opti_behavior_cascade ) . '</style>';
			$html = str_replace( '</head>', $opti_behavior_cascade_tag . '</head>', $html );
		}

		// Inject JavaScript for changes that use complex selectors OR advanced
		// change kinds (hide / attributes / classes / js / image_src / css).
		// The applier mirrors the iframe helper so preview renders identically.
		if ( ! empty( $opti_behavior_js_changes ) ) {
			$opti_behavior_json = wp_json_encode( $opti_behavior_js_changes );
			$opti_behavior_script = '<script id="opti-ab-variant-js" data-no-optimize="1">'
				. '(function(){'
				. 'var C=' . $opti_behavior_json . ';'
				// DOM-structural changes (Add Block, Paste, Duplicate, Move).
				// Runs FIRST so subsequent styling changes can target inserted
				// nodes if needed.
				// Empty selector AND "body" selector both mean "no specific
				// target — append to main content area". Legacy changes were
				// saved with selector='body' but body.parentNode.insertBefore
				// puts content outside </body>, which lands at page bottom.
				. 'function insertHTML(sel,html){'
				. 'var append=!sel||sel==="body";var t;'
				. 'if(!append){try{t=document.querySelector(sel);}catch(e){}}'
				. 'if(!t){append=true;t=document.querySelector(".entry-content")||document.querySelector(".site-content")||document.querySelector("#content")||document.querySelector("main")||document.body;}'
				. 'var w=document.createElement("div");w.innerHTML=html;'
				. 'while(w.firstChild){if(!append&&t&&t.parentNode){t.parentNode.insertBefore(w.firstChild,t.nextSibling);}else{t.appendChild(w.firstChild);}}'
				. '}'
				. 'function duplicateEl(sel){try{var e=document.querySelector(sel);if(!e)return;var c=e.cloneNode(true);e.parentNode.insertBefore(c,e.nextSibling);}catch(e){}}'
				. 'function moveEl(sel,d){try{var e=document.querySelector(sel);if(!e||!e.parentNode)return;if(d<0){var p=e.previousElementSibling;if(p)e.parentNode.insertBefore(e,p);}else{var n=e.nextElementSibling;if(n)e.parentNode.insertBefore(e,n.nextSibling);}}catch(e){}}'
				. 'function applyStructural(ch){'
				. 'if(ch.insert_after){insertHTML(ch.selector,ch.insert_after);return true;}'
				. 'if(ch.duplicate){duplicateEl(ch.selector);return true;}'
				. 'if(typeof ch.move_delta==="number"&&ch.move_delta!==0){moveEl(ch.selector,ch.move_delta);return true;}'
				. 'return false;'
				. '}'
				// Per-element edits (hide / text / html / css / attrs / classes / js / image).
				. 'function applyOne(ch){'
				. 'if(applyStructural(ch))return;'
				. 'var els;try{els=document.querySelectorAll(ch.selector);}catch(e){return;}'
				. 'for(var j=0;j<els.length;j++){var el=els[j];'
				. 'if(ch.hide){el.style.setProperty("display","none","important");continue;}'
				. 'if(ch.image_src&&el.tagName&&el.tagName.toLowerCase()==="img"){el.src=ch.image_src;el.removeAttribute("srcset");}'
				. 'if(ch.text&&!ch.html)el.textContent=ch.text;'
				. 'if(ch.html)el.innerHTML=ch.html;'
				. 'if(ch.css){var ds=ch.css.split(";");for(var k=0;k<ds.length;k++){var p=ds[k].split(":");if(p.length<2)continue;var nm=p.shift().trim();var vl=p.join(":").replace(/!important$/i,"").trim();if(!nm||!vl)continue;try{el.style.setProperty(nm,vl,"important");}catch(e){}}}'
				. 'if(ch.attributes&&typeof ch.attributes==="object"){for(var a in ch.attributes){if(!ch.attributes.hasOwnProperty(a))continue;if(/^on[a-z]+$/i.test(a))continue;var av=ch.attributes[a];try{if(av===null||typeof av==="undefined"||av==="")el.removeAttribute(a);else el.setAttribute(a,String(av));}catch(e){}}}'
				. 'if(ch.classes_add){for(var ia=0;ia<ch.classes_add.length;ia++){try{el.classList.add(ch.classes_add[ia]);}catch(e){}}}'
				. 'if(ch.classes_remove){for(var ir=0;ir<ch.classes_remove.length;ir++){try{el.classList.remove(ch.classes_remove[ir]);}catch(e){}}}'
				. 'if(ch.js){try{(new Function("el","window","document",ch.js))(el,window,document);}catch(e){}}'
				. '}'
				. '}'
				. 'function a(){for(var i=0;i<C.length;i++)applyOne(C[i]);}'
				. 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",a);'
				. 'else a();'
				. '})();'
				. '</script>';
			$html = str_replace( '</body>', $opti_behavior_script . '</body>', $html );
		}

		return $html;
	}

	/**
	 * Apply element changes to content (subset of the full-page approach).
	 *
	 * @since 1.3.0
	 * @param string $content Post content.
	 * @param array  $changes Changes array.
	 * @return string Modified content.
	 */
	private function apply_element_changes( $content, $changes ) {
		// For content-level changes, we only apply text/html changes.
		foreach ( $changes as $change ) {
			if ( empty( $change['action'] ) ) {
				continue;
			}

			if ( 'text' === $change['action'] && ! empty( $change['value'] ) ) {
				if ( ! empty( $change['selector'] ) && preg_match( '/^#([\w-]+)$/', $change['selector'], $m ) ) {
					$content = preg_replace(
						'/(<[^>]+id=["\']' . preg_quote( $m[1], '/' ) . '["\'][^>]*>)(.*?)(<\/[^>]+>)/is',
						'$1' . esc_html( $change['value'] ) . '$3',
						$content,
						1
					);
				}
			}
		}

		return $content;
	}

	/**
	 * Shortcode handler: [opti_ab id="123" variant="a"]Content[/opti_ab]
	 *
	 * @since 1.3.0
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Shortcode content (variant text).
	 * @return string Output.
	 */
	public function shortcode_handler( $atts, $content = '' ) {
		$atts = shortcode_atts( array(
			'id'      => 0,
			'variant' => '',
		), $atts, 'opti_ab' );

		$test_id = absint( $atts['id'] );
		if ( ! $test_id ) {
			return '';
		}

		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test || 'running' !== $test->status ) {
			// If test isn't running, show control content (variant "a" or "control").
			$variant_name = strtolower( trim( $atts['variant'] ) );
			if ( 'a' === $variant_name || 'control' === $variant_name ) {
				return do_shortcode( $content );
			}
			return '';
		}

		$variants = Opti_Behavior_AB_Test_Database::get_variants( $test_id );
		$variant  = $this->get_current_variant( $test, $variants );

		if ( ! $variant ) {
			return '';
		}

		// Match variant by name or index (a=0, b=1, c=2, etc.).
		$variant_name = strtolower( trim( $atts['variant'] ) );
		$variant_idx  = ord( $variant_name ) - ord( 'a' );

		$assigned_idx = (int) $variant->sort_order;

		if ( $assigned_idx === $variant_idx || strtolower( $variant->name ) === $variant_name ) {
			return do_shortcode( $content );
		}

		return '';
	}

	/**
	 * Goal shortcode: [opti_ab_goal test="123" goal="456"]Thank you![/opti_ab_goal]
	 *
	 * Outputs content and triggers conversion tracking via JS.
	 *
	 * @since 1.3.0
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Content.
	 * @return string Output.
	 */
	public function goal_shortcode_handler( $atts, $content = '' ) {
		$atts = shortcode_atts( array(
			'test' => 0,
			'goal' => 0,
		), $atts, 'opti_ab_goal' );

		$test_id = absint( $atts['test'] );
		$goal_id = absint( $atts['goal'] );

		$output = do_shortcode( $content );

		if ( $test_id && $goal_id ) {
			$output .= '<script>document.addEventListener("DOMContentLoaded",function(){if(window.optiBehavior&&window.optiBehavior.trackConversion){window.optiBehavior.trackConversion(' . $test_id . ',' . $goal_id . ')}});</script>';
		}

		return $output;
	}

	/**
	 * Inject anti-flicker CSS for pages with active element tests.
	 *
	 * Hides the body briefly while variant changes are applied.
	 *
	 * @since 1.3.0
	 */
	public function inject_anti_flicker_css() {
		if ( is_admin() || empty( $this->active_tests ) ) {
			return;
		}

		$has_client_side = false;
		foreach ( $this->active_tests as $test ) {
			if ( in_array( $test->test_type, array( 'element', 'css' ), true ) ) {
				$variant = $this->get_current_variant( $test );
				if ( $variant && empty( $variant->is_control ) ) {
					$has_client_side = true;
					break;
				}
			}
		}

		if ( ! $has_client_side ) {
			return;
		}

		echo '<style id="opti-ab-anti-flicker">.opti-ab-loading{opacity:0!important;}</style>';
		echo '<script>document.documentElement.className+=" opti-ab-loading";setTimeout(function(){document.documentElement.classList.remove("opti-ab-loading")},500);</script>';
	}

	/**
	 * Output variant data as inline script for the frontend tracker.
	 *
	 * @since 1.3.0
	 */
	public function output_variant_data_script() {
		if ( is_admin() ) {
			return;
		}

		$data = array();
		foreach ( $this->current_variants as $test_id => $variant ) {
			$data[] = array(
				'test_id'    => (int) $test_id,
				'variant_id' => (int) $variant->id,
				'is_control' => (bool) $variant->is_control,
				'sort_order' => (int) $variant->sort_order,
			);
		}

		// DEF-AB-PV-FIX: Emit a "goal_only" entry for each test whose
		// page_visit goal URL matches the current page (see load_active_tests
		// for the matching pass). The variant comes ONLY from the existing
		// opti_ab_{test_id} cookie — no bucketer call — so first-time
		// visitors landing directly on the goal URL produce no entry and
		// therefore no spurious conversion. The goal_only flag tells
		// ab-test-tracker.js to skip the impression record for these
		// entries (the impression must come from the actual variant page).
		if ( ! empty( $this->goal_only_tests ) ) {
			$opti_behavior_already_emitted = array();
			foreach ( $data as $opti_behavior_emitted ) {
				$opti_behavior_already_emitted[ (int) $opti_behavior_emitted['test_id'] ] = true;
			}

			foreach ( $this->goal_only_tests as $opti_behavior_gtest ) {
				$opti_behavior_gtest_id = (int) $opti_behavior_gtest->id;
				if ( ! empty( $opti_behavior_already_emitted[ $opti_behavior_gtest_id ] ) ) {
					continue;
				}

				$opti_behavior_cookie_name = 'opti_ab_' . $opti_behavior_gtest_id;
				$opti_behavior_cookie_vid  = 0;
				if ( ! empty( $_COOKIE[ $opti_behavior_cookie_name ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$opti_behavior_cookie_vid = absint( wp_unslash( $_COOKIE[ $opti_behavior_cookie_name ] ) );
				}

				// Cookieless Anonymous Mode: the opti_ab_<test_id> assignment
				// cookie is never written, so fall back to the RECORDED
				// impression for this visitor's server daily hash. Read-only —
				// a first-time visitor landing directly on the goal URL has no
				// impression row and still produces no entry (and therefore no
				// spurious conversion), preserving DEF-AB-PV-FIX semantics.
				if ( $opti_behavior_cookie_vid <= 0 && class_exists( 'Opti_Behavior_AB_Test_Bucketer' ) ) {
					$opti_behavior_db_assignment = Opti_Behavior_AB_Test_Bucketer::get_instance()->get_recorded_assignment(
						$opti_behavior_gtest_id,
						$this->get_visitor_id()
					);
					if ( $opti_behavior_db_assignment ) {
						$opti_behavior_cookie_vid = (int) $opti_behavior_db_assignment;
					}
				}

				if ( $opti_behavior_cookie_vid <= 0 ) {
					continue;
				}

				// Validate that the cookie's variant still belongs to this test.
				$opti_behavior_gvariants = Opti_Behavior_AB_Test_Database::get_variants( $opti_behavior_gtest_id );
				$opti_behavior_resolved  = null;
				if ( ! empty( $opti_behavior_gvariants ) ) {
					foreach ( $opti_behavior_gvariants as $opti_behavior_gv ) {
						if ( (int) $opti_behavior_gv->id === $opti_behavior_cookie_vid ) {
							$opti_behavior_resolved = $opti_behavior_gv;
							break;
						}
					}
				}
				if ( null === $opti_behavior_resolved ) {
					continue;
				}

				$data[] = array(
					'test_id'    => $opti_behavior_gtest_id,
					'variant_id' => (int) $opti_behavior_resolved->id,
					'is_control' => (bool) $opti_behavior_resolved->is_control,
					'sort_order' => (int) $opti_behavior_resolved->sort_order,
					'goal_only'  => true,
				);
			}
		}

		if ( empty( $data ) ) {
			return;
		}

		// data-no-optimize="1" keeps LiteSpeed Cache, Autoptimize, WP Rocket and
		// SG Optimizer from combining this inline block into a deferred JS bundle.
		// Without this, the bundle runs AFTER ab-test-tracker.js and the tracker
		// IIFE bails because window.optiBehaviorAB is undefined at its load time.
		// See Bug #3 in the investigation artifact for the full root-cause chain.
		echo '<script id="opti-ab-variant-data" data-no-optimize="1">window.optiBehaviorAB=' . wp_json_encode( $data ) . ';</script>';
	}

	// =========================================================================
	// Internal Helpers
	// =========================================================================

	/**
	 * Load active tests from cache/DB and match to current page.
	 *
	 * @since 1.3.0
	 */
	private function load_active_tests() {
		if ( $this->active_tests_loaded ) {
			return;
		}
		$this->active_tests_loaded = true;

		$all_active = Opti_Behavior_AB_Test_Database::get_active_tests();
		if ( empty( $all_active ) ) {
			return;
		}

		$current_url     = $this->get_current_url();
		$current_post_id = $this->get_current_post_id();
		$current_path    = untrailingslashit( wp_parse_url( $current_url, PHP_URL_PATH ) );

		foreach ( $all_active as $test ) {
			$matches = false;

			// Match by post ID.
			if ( $test->target_post_id && $current_post_id && (int) $test->target_post_id === (int) $current_post_id ) {
				$matches = true;
			}

			// Match by URL.
			if ( ! $matches && $test->target_url ) {
				$test_url = untrailingslashit( wp_parse_url( $test->target_url, PHP_URL_PATH ) );
				if ( $test_url === $current_path ) {
					$matches = true;
				}
			}

			// Match page_split variants by their redirect_url.
			// Ensures the test is recognized when a visitor lands on a variant page
			// (either via our own redirect that appended ?opti_ab_variant, or by
			// directly visiting / bookmarking the variant URL). Without this,
			// window.optiBehaviorAB is never emitted on variant pages, which
			// breaks impression, goal and heatmap A/B attribution.
			if ( ! $matches && 'page_split' === $test->test_type ) {
				$split_variants = Opti_Behavior_AB_Test_Database::get_variants( (int) $test->id );
				if ( ! empty( $split_variants ) ) {
					foreach ( $split_variants as $opti_behavior_split_variant ) {
						if ( empty( $opti_behavior_split_variant->variant_data ) ) {
							continue;
						}
						$opti_behavior_vdata = json_decode( $opti_behavior_split_variant->variant_data, true );
						if ( ! is_array( $opti_behavior_vdata ) || empty( $opti_behavior_vdata['redirect_url'] ) ) {
							continue;
						}
						$opti_behavior_v_path = untrailingslashit( wp_parse_url( $opti_behavior_vdata['redirect_url'], PHP_URL_PATH ) );
						if ( $opti_behavior_v_path !== '' && $opti_behavior_v_path === $current_path ) {
							$matches = true;
							break;
						}
					}
				}
			}

			// Shortcode tests match globally (they are rendered inline).
			if ( 'shortcode' === $test->test_type ) {
				$matches = true;
			}

			if ( $matches ) {
				$this->active_tests[] = $test;
			}
		}

		// DEF-AB-PV-FIX: Goal-URL match pass.
		//
		// A `page_visit` goal lives on a DIFFERENT URL than the test itself
		// (e.g. test variants under /shop/, goal URL = /thank-you/). Without
		// this pass, the renderer emits no `window.optiBehaviorAB` on the
		// goal page, so ab-test-tracker.js bails before it can fire the
		// page_visit conversion — leaving the Page Visit goal stuck at 0
		// while Click / Form / Scroll goals on the variant page work fine.
		//
		// Strategy: scan goals of every active test we did NOT already match,
		// and if any page_visit goal URL resolves to the current path, file
		// the test under $goal_only_tests. We deliberately keep these out of
		// $active_tests so handle_redirects(), filter_content(),
		// maybe_start_output_buffer(), etc. cannot accidentally redirect or
		// re-render the goal page as if it were the test page. The variant
		// for these tests is read from the existing opti_ab_{test_id} cookie
		// inside output_variant_data_script() — we do NOT call the bucketer
		// on goal-only pages, otherwise a first-time visitor landing directly
		// on the goal URL would be assigned a variant they never saw, which
		// would inflate the variant audience and corrupt conversion math.
		foreach ( $all_active as $opti_behavior_goal_test ) {
			// Skip tests already matched above.
			$opti_behavior_already_matched = false;
			foreach ( $this->active_tests as $opti_behavior_at ) {
				if ( (int) $opti_behavior_at->id === (int) $opti_behavior_goal_test->id ) {
					$opti_behavior_already_matched = true;
					break;
				}
			}
			if ( $opti_behavior_already_matched ) {
				continue;
			}

			$opti_behavior_test_goals = Opti_Behavior_AB_Test_Database::get_goals( (int) $opti_behavior_goal_test->id );
			if ( empty( $opti_behavior_test_goals ) ) {
				continue;
			}

			foreach ( $opti_behavior_test_goals as $opti_behavior_goal ) {
				if ( 'page_visit' !== $opti_behavior_goal->goal_type ) {
					continue;
				}
				$opti_behavior_gcfg = json_decode( $opti_behavior_goal->goal_config, true );
				if ( ! is_array( $opti_behavior_gcfg ) || empty( $opti_behavior_gcfg['url'] ) ) {
					continue;
				}

				// Resolve relative / path-only URLs against home_url() so that
				// "/thank-you/" on a /wordpress/ subdir install resolves to
				// /wordpress/thank-you (mirrors trackPageVisitGoal in the JS).
				//
				// trim(): a goal URL saved with leading/trailing whitespace
				// (e.g. "http://host/thank-you/ ") would keep the space inside
				// the parsed path — untrailingslashit() only strips slashes —
				// so the strict path comparison below could never match and
				// page_visit conversions were silently never tracked. The JS
				// side is immune (the WHATWG URL parser trims), so mirror that
				// behavior here.
				$opti_behavior_goal_url_raw = trim( (string) $opti_behavior_gcfg['url'] );
				if ( ! preg_match( '#^https?://#i', $opti_behavior_goal_url_raw ) ) {
					$opti_behavior_goal_url_raw = trailingslashit( home_url( '/' ) ) . ltrim( $opti_behavior_goal_url_raw, '/' );
				}
				$opti_behavior_goal_path = untrailingslashit( (string) wp_parse_url( $opti_behavior_goal_url_raw, PHP_URL_PATH ) );
				if ( '' === $opti_behavior_goal_path ) {
					continue;
				}

				if ( $opti_behavior_goal_path === $current_path ) {
					$this->goal_only_tests[] = $opti_behavior_goal_test;
					break; // One match per test is enough.
				}
			}
		}

		// When the page_split redirect adds ?opti_ab_variant=XXX, honor that as the
		// authoritative variant assignment for this request. Pre-populating
		// current_variants here prevents get_current_variant() from re-running the
		// bucketer on the variant URL (where the bucketer might produce a different
		// result if visitor_id changed, breaking sticky attribution).
		// Security: only honor the hint for page_split tests that are ACTIVE; the
		// worst-case impact of a forged param would be attributing a visitor's own
		// events to a chosen variant of a test they are already eligible for.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$opti_behavior_variant_hint = isset( $_GET['opti_ab_variant'] ) ? absint( wp_unslash( $_GET['opti_ab_variant'] ) ) : 0;
		if ( $opti_behavior_variant_hint > 0 ) {
			$opti_behavior_hinted = Opti_Behavior_AB_Test_Database::get_variant( $opti_behavior_variant_hint );
			if ( $opti_behavior_hinted && ! empty( $opti_behavior_hinted->test_id ) ) {
				$opti_behavior_hint_test_id = (int) $opti_behavior_hinted->test_id;
				foreach ( $all_active as $opti_behavior_hint_test ) {
					if ( (int) $opti_behavior_hint_test->id !== $opti_behavior_hint_test_id ) {
						continue;
					}
					if ( 'page_split' !== $opti_behavior_hint_test->test_type ) {
						break;
					}
					// Ensure the test is listed as active for this request.
					$opti_behavior_already_active = false;
					foreach ( $this->active_tests as $opti_behavior_at ) {
						if ( (int) $opti_behavior_at->id === $opti_behavior_hint_test_id ) {
							$opti_behavior_already_active = true;
							break;
						}
					}
					if ( ! $opti_behavior_already_active ) {
						$this->active_tests[] = $opti_behavior_hint_test;
					}
					// Pre-populate the variant so get_current_variant() skips the
					// bucketer for this test (see handle_visual_editor_preview for
					// the same technique).
					if ( ! isset( $this->current_variants[ $opti_behavior_hint_test_id ] ) ) {
						$this->current_variants[ $opti_behavior_hint_test_id ] = $opti_behavior_hinted;
					}
					break;
				}
			}
		}
	}

	/**
	 * Get the current variant for a test, using the bucketer.
	 *
	 * @since 1.3.0
	 * @param object     $test     Test object.
	 * @param array|null $variants Variant objects (loaded if null).
	 * @return object|null Variant object or null.
	 */
	private function get_current_variant( $test, $variants = null ) {
		$test_id = (int) $test->id;

		if ( isset( $this->current_variants[ $test_id ] ) ) {
			return $this->current_variants[ $test_id ];
		}

		if ( null === $variants ) {
			$variants = Opti_Behavior_AB_Test_Database::get_variants( $test_id );
		}

		if ( empty( $variants ) ) {
			return null;
		}

		// Get visitor_id from existing session system.
		$visitor_id = $this->get_visitor_id();
		$session_id = $this->get_session_id();

		$bucketer   = Opti_Behavior_AB_Test_Bucketer::get_instance();
		$variant_id = $bucketer->get_variant_for_visitor( $test_id, $visitor_id, $variants, $session_id );

		if ( false === $variant_id ) {
			return null;
		}

		// Find the variant object.
		foreach ( $variants as $v ) {
			if ( (int) $v->id === (int) $variant_id ) {
				$this->current_variants[ $test_id ] = $v;

				// Fire action for tracking.
				do_action( 'opti_behavior_ab_variant_served', $test_id, $variant_id, $visitor_id );

				return $v;
			}
		}

		return null;
	}

	/**
	 * Get current page URL.
	 *
	 * @return string Current URL.
	 */
	private function get_current_url() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$protocol = is_ssl() ? 'https' : 'http';
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return $protocol . '://' . $host . $uri;
	}

	/**
	 * Normalize a URL to its path for A/B URL matching.
	 *
	 * Existing page-split matching uses path-only comparison so the same rule
	 * works across local domains and with/without trailing slashes.
	 *
	 * @since 1.3.4
	 * @param string $url URL to normalize.
	 * @return string Normalized path without trailing slash.
	 */
	private function normalize_url_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		return untrailingslashit( rawurldecode( $path ) );
	}

	/**
	 * Get current post ID (for singular pages).
	 *
	 * @return int Post ID or 0.
	 */
	private function get_current_post_id() {
		if ( is_singular() ) {
			return get_queried_object_id();
		}

		if ( is_front_page() && get_option( 'page_on_front' ) ) {
			return (int) get_option( 'page_on_front' );
		}

		return 0;
	}

	/**
	 * Get visitor ID from existing Opti-Behavior session system.
	 *
	 * In anonymous mode (and full mode before consent) the id is the server
	 * daily-rotating hash (get_anonymous_hash()); in full mode after consent it
	 * is the optibehavior_vid cookie. Falls back to a generated id only when the
	 * session system isn't available.
	 *
	 * @return string Visitor ID hash.
	 */
	private function get_visitor_id() {
		// Logged-in WP user: canonical id is always wp_user_<id> so the bucketer
		// (variant assignment) and the AB AJAX endpoint (impression record) agree
		// on the same UNIQUE key, preventing double-row inflation.
		if ( is_user_logged_in() ) {
			return 'wp_user_' . get_current_user_id();
		}

		// Bug (Visitors-double-count): the server-side bucketer and the
		// client-side ab-test-tracker must use the SAME visitor_id, otherwise
		// they insert two separate impression rows for one physical visitor.
		//
		// The tracker's JS resolution order is:
		//   1. Full mode, post-consent: optibehavior_vid cookie.
		//   2. Anonymous mode (or full mode pre-consent): the server daily hash
		//      localized as optiBehaviorHeatmapConfig.anon_vid. The tracker no
		//      longer mints a random anon_<ts>_<rand> id and no longer writes the
		//      optibehavior_anon_vid cookie (cookieless Anonymous Mode).
		//
		// We mirror that order here so the bucketer records with the same ID the
		// tracker will later send. The presence of optibehavior_vid distinguishes
		// full-mode-post-consent from the effectively-anonymous states.
		if ( isset( $_COOKIE['optibehavior_vid'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return sanitize_text_field( wp_unslash( $_COOKIE['optibehavior_vid'] ) );
		}

		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			$core = Opti_Behavior_Heatmap_Core::get_instance();
			$session = $core->get_session();
			// Anonymous mode (or full mode before consent): bucket on the server
			// daily-rotating hash — the same value the tracker sources from
			// optiBehaviorHeatmapConfig.anon_vid. No cookie is read or written.
			if ( $session && method_exists( $session, 'get_anonymous_hash' ) ) {
				$anon_hash = $session->get_anonymous_hash();
				if ( $anon_hash ) {
					return $anon_hash;
				}
			}
			if ( $session && method_exists( $session, 'get_visitor_id' ) ) {
				$vid = $session->get_visitor_id();
				if ( $vid ) {
					return $vid;
				}
			}
		}

		// Legacy cookie fallback (pre-Pro integrations / manual tests).
		if ( isset( $_COOKIE['opti_behavior_vid'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return sanitize_text_field( wp_unslash( $_COOKIE['opti_behavior_vid'] ) );
		}

		// Generate a random visitor ID.
		$vid = wp_generate_uuid4();
		if ( ! headers_sent() ) {
			$path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
			$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
			setcookie( 'opti_behavior_vid', $vid, time() + YEAR_IN_SECONDS, $path, $domain, is_ssl(), false );
		}

		return $vid;
	}

	/**
	 * Get session ID from existing Opti-Behavior session system.
	 *
	 * @return string Session ID.
	 */
	private function get_session_id() {
		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			$core = Opti_Behavior_Heatmap_Core::get_instance();
			$session = $core->get_session();
			if ( $session && method_exists( $session, 'get_session_id' ) ) {
				return $session->get_session_id();
			}
		}

		return '';
	}
}
