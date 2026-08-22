<?php
/**
 * Heatmap Detail Page
 *
 * Displays detailed heatmap analysis for a specific page with multiple visualization types.
 * Shows click maps, move maps, and scroll maps with device switching capabilities.
 *
 * @package OptiBehaviorPro
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Opti_Behavior_Heatmap_Detail_Page', false ) ) {
	return;
}

// Skip loading if Pro version is active (Pro has its own enhanced version).
if ( defined( 'OPTI_BEHAVIOR_PRO_VERSION' ) ) {
	return;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Class Opti_Behavior_Heatmap_Detail_Page
 *
 * Handles the heatmap detail admin page display and functionality.
 */
class Opti_Behavior_Heatmap_Detail_Page {

	/**
	 * Page ID being viewed.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * Canonical page IDs being viewed.
	 *
	 * @var array
	 */
	private $page_ids = array();

	/**
	 * Page data.
	 *
	 * @var array
	 */
	private $page_data;

	/**
	 * Whether page hooks were already registered by an earlier instance.
	 *
	 * Free and Pro share this class name and can both instantiate it in one
	 * request (Free fallback + Pro module). Without this guard the hidden
	 * admin page gets registered twice and render_page() runs twice,
	 * duplicating the whole detail page markup.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		add_action( 'admin_menu', array( $this, 'add_hidden_page' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Parse canonical page IDs from request, preserving page_id as fallback.
	 *
	 * @return array Positive unique page IDs.
	 */
	private function get_request_page_ids() {
		$page_ids = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only heatmap detail routing parameters.
		if ( isset( $_GET['page_ids'] ) ) {
			$raw_page_ids = sanitize_text_field( wp_unslash( $_GET['page_ids'] ) );
			$page_ids     = array_map( 'absint', explode( ',', $raw_page_ids ) );
		}

		if ( isset( $_GET['page_id'] ) ) {
			array_unshift( $page_ids, absint( $_GET['page_id'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$page_ids = array_values( array_unique( array_filter( $page_ids ) ) );

		return array_slice( $page_ids, 0, 100 );
	}

	/**
	 * Add hidden admin page (not in menu, accessible via direct URL).
	 */
	public function add_hidden_page() {
		add_submenu_page(
			'', // No parent = hidden page (empty string avoids PHP 8.1 null deprecation).
			__( 'Heatmap Details', 'opti-behavior' ),
			__( 'Heatmap Details', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-heatmap-detail',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue page assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'admin_page_opti-behavior-heatmap-detail' !== $hook ) {
			return;
		}

		// Enqueue Lucide Icons JavaScript (bundled locally, MIT licensed)
		wp_enqueue_script(
			'lucide-icons',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/lucide.min.js',
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . time(),
			true
		);

		// Enqueue heatmap.js library from Free version (local file is more reliable than CDN).
		wp_enqueue_script(
			'heatmap-js',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/heatmap.min.js',
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			true
		);

		// Enqueue html2canvas library for heatmap image download.
		wp_enqueue_script(
			'html2canvas',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/html2canvas.min.js',
			array(),
			'1.4.1-' . OPTI_BEHAVIOR_HEATMAP_VERSION, // Lib ver + plugin ver so updates cache-bust.
			true
		);

		// Enqueue detail page JS with cache busting.
		wp_enqueue_script(
			'opti-behavior-heatmap-detail',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/heatmap-detail.js',
			array( 'jquery', 'heatmap-js', 'lucide-icons', 'html2canvas' ),
			OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . time(),
			true
		);

		// Get page data for URL.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for admin page display only.
		$page_ids  = $this->get_request_page_ids();
		$page_id   = ! empty( $page_ids ) ? absint( $page_ids[0] ) : 0;
		$page_data = $this->get_page_data( $page_id );

		// Resolve A/B test variant name for the localized JS config.
		$opti_behavior_variant_name_js = '';
		if ( isset( $_GET['variant_id'] ) && absint( $_GET['variant_id'] ) > 0 && class_exists( 'Opti_Behavior_AB_Test_Database' ) ) {
			$opti_behavior_vobj = Opti_Behavior_AB_Test_Database::get_variant( absint( $_GET['variant_id'] ) );
			if ( $opti_behavior_vobj && ! empty( $opti_behavior_vobj->name ) ) {
				$opti_behavior_variant_name_js = $opti_behavior_vobj->name;
			}
		}

		$exclude_spam_default = $this->get_global_spam_exclusion_default() ? '1' : '0';
		$exclude_spam_value   = isset( $_GET['exclude_spam'] )
			? ( '1' === sanitize_text_field( wp_unslash( $_GET['exclude_spam'] ) ) ? '1' : '0' )
			: $exclude_spam_default;

		// Localize script with data.
		wp_localize_script(
			'opti-behavior-heatmap-detail',
			'optiHeatmapDetail',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'opti_heatmap_detail' ),
				'page_id'       => $page_id,
				'page_ids'      => $page_ids,
				'device'        => isset( $_GET['device'] ) ? sanitize_text_field( wp_unslash( $_GET['device'] ) ) : 'desktop',
				'type'          => isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : 'click',
				'date_range'    => isset( $_GET['date_range'] ) ? sanitize_text_field( wp_unslash( $_GET['date_range'] ) ) : 'all',
				'start_date'    => isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : ( isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '' ),
				'end_date'      => isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : ( isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '' ),
				'exclude_spam'   => $exclude_spam_value,
				'page_url'      => $page_data ? $page_data['url'] : '',
				'ab_test_id'    => isset( $_GET['ab_test_id'] ) ? absint( $_GET['ab_test_id'] ) : 0,
				'variant_id'    => isset( $_GET['variant_id'] ) ? absint( $_GET['variant_id'] ) : 0,
				'variant_name'  => $opti_behavior_variant_name_js,
				'assets_url'    => OPTI_BEHAVIOR_HEATMAP_ASSETS_URL,
				'upgrade_url'   => 'https://optiuser.com/',
				// "Count Bar Display" (Settings → Data Collection): gates the
				// click-count hover bubble on the Click heatmap. Pro-only feature —
				// always 0 when the Pro plugin is inactive.
				'count_bar'     => $this->get_count_bar_setting(),
				'i18n'          => array(
					/* translators: {views}: number of views, {percent}: scroll reach percentage. */
					'scroll_depth_tooltip' => __( '{views} views ({percent}%) reached up to this point', 'opti-behavior' ),
					/* translators: {count}: number of clicks. */
					'click_count_tooltip' => __( '{count} clicks in this area', 'opti-behavior' ),
					'attention_avg_time_label' => __( 'Avg time spent', 'opti-behavior' ),
					'attention_session_pct_label' => __( '% of session length', 'opti-behavior' ),
					'loading'        => __( 'Loading heatmap data...', 'opti-behavior' ),
					'error'          => __( 'Failed to load heatmap data', 'opti-behavior' ),
					'no_data'        => __( 'No heatmap data available', 'opti-behavior' ),
					'processing'     => __( 'Processing recordings...', 'opti-behavior' ),
					'coming_soon'    => __( 'Will be available soon', 'opti-behavior' ),
					'top_elements_empty'          => __( 'No element data yet', 'opti-behavior' ),
					'top_elements_empty_hint'     => __( 'Element details are collected from new visits — check back soon.', 'opti-behavior' ),
					'top_elements_wrong_type'     => __( 'Available on click heatmaps', 'opti-behavior' ),
					'top_elements_wrong_type_hint' => __( 'Switch to the Click heatmap type to see the top clicked elements.', 'opti-behavior' ),
					'top_elements_locked'      => __( 'Top Clicked Elements is a Pro feature', 'opti-behavior' ),
					'top_elements_locked_hint' => __( 'Upgrade to Opti-Behavior Pro to see which elements your visitors click the most.', 'opti-behavior' ),
					'upgrade_cta'    => __( 'Upgrade to Pro', 'opti-behavior' ),
					'dismiss'        => __( 'Dismiss', 'opti-behavior' ),
					'of_clicks'      => __( 'of clicks', 'opti-behavior' ),
					'element_types'  => array(
						'button'     => __( 'Button', 'opti-behavior' ),
						'menu'       => __( 'Menu', 'opti-behavior' ),
						'link'       => __( 'Link', 'opti-behavior' ),
						'image'      => __( 'Image', 'opti-behavior' ),
						'video'      => __( 'Video', 'opti-behavior' ),
						'form-field' => __( 'Form field', 'opti-behavior' ),
						'checkbox'   => __( 'Checkbox', 'opti-behavior' ),
						'radio'      => __( 'Radio', 'opti-behavior' ),
						'dropdown'   => __( 'Dropdown', 'opti-behavior' ),
						'accordion'  => __( 'Accordion', 'opti-behavior' ),
						'tab'        => __( 'Tab', 'opti-behavior' ),
						'slider'     => __( 'Slider', 'opti-behavior' ),
						'list'       => __( 'List', 'opti-behavior' ),
						'heading'    => __( 'Heading', 'opti-behavior' ),
						'text'       => __( 'Text', 'opti-behavior' ),
						'section'    => __( 'Background / Section', 'opti-behavior' ),
						'search'     => __( 'Search', 'opti-behavior' ),
						'login'      => __( 'Login / Register', 'opti-behavior' ),
						'comment'    => __( 'Comment', 'opti-behavior' ),
						'captcha'    => __( 'Captcha', 'opti-behavior' ),
						'popup'      => __( 'Popup', 'opti-behavior' ),
						'pagination' => __( 'Pagination', 'opti-behavior' ),
						'breadcrumb' => __( 'Breadcrumb', 'opti-behavior' ),
						'element'    => __( 'Element', 'opti-behavior' ),
					),
					'downloading'    => __( 'Downloading...', 'opti-behavior' ),
					'download_error' => __( 'Failed to generate image', 'opti-behavior' ),
					'all_countries'  => __( 'All Countries', 'opti-behavior' ),
					'all_browsers'   => __( 'All Browsers', 'opti-behavior' ),
					'all_os'         => __( 'All Operating Systems', 'opti-behavior' ),
					'no_os_found'    => __( 'No OS data available', 'opti-behavior' ),
					'all_campaigns'  => __( 'All Campaigns', 'opti-behavior' ),
					'all_sources'    => __( 'All Sources', 'opti-behavior' ),
					'all_mediums'    => __( 'All Mediums', 'opti-behavior' ),
					'no_campaigns_found' => __( 'No campaign data available', 'opti-behavior' ),
					'no_sources_found'   => __( 'No source data available', 'opti-behavior' ),
					'no_mediums_found'   => __( 'No medium data available', 'opti-behavior' ),
					'loading_preview'    => __( 'Loading page preview...', 'opti-behavior' ),
					'measuring_preview'  => __( 'Measuring page preview...', 'opti-behavior' ),
					'rendering_heatmap'  => __( 'Rendering heatmap overlay...', 'opti-behavior' ),
					'finalizing_heatmap' => __( 'Finalizing heatmap...', 'opti-behavior' ),
					'loading_paused'     => __( 'Loading paused...', 'opti-behavior' ),
					'loading_stopped'    => __( 'Stopping after current render...', 'opti-behavior' ),
					'high'               => __( 'High', 'opti-behavior' ),
					'low'                => __( 'Low', 'opti-behavior' ),
					'attention'          => __( 'Attention', 'opti-behavior' ),
					'scroll_label'       => __( '% Scrolled', 'opti-behavior' ),
					'unknown'            => __( 'Unknown', 'opti-behavior' ),
					'clicks_label'       => __( 'clicks', 'opti-behavior' ),
					'selected'           => __( 'selected', 'opti-behavior' ),
					'loading_countries'  => __( 'Loading countries...', 'opti-behavior' ),
					'loading_browsers'   => __( 'Loading browsers...', 'opti-behavior' ),
					'no_countries_available' => __( 'No countries available', 'opti-behavior' ),
					'no_browsers_available'  => __( 'No browsers available', 'opti-behavior' ),
					'no_countries_found' => __( 'No countries found', 'opti-behavior' ),
					'no_browsers_found'  => __( 'No browsers found', 'opti-behavior' ),
					'stop'               => __( 'Stop', 'opti-behavior' ),
					'stop_loading'       => __( 'Stop Loading', 'opti-behavior' ),
					'stop_loading_more'  => __( 'Stop loading more data', 'opti-behavior' ),
					'loading_stopped_final' => __( 'Loading stopped', 'opti-behavior' ),
					'no_data_yet'        => __( 'No heatmap data yet for this page', 'opti-behavior' ),
					'rendering_preview'  => __( 'Rendering page preview...', 'opti-behavior' ),
					'resuming'           => __( 'Resuming...', 'opti-behavior' ),
					'resuming_loading'   => __( 'Resuming heatmap loading...', 'opti-behavior' ),
					'loading_paused_hidden' => __( 'Loading paused (tab hidden)...', 'opti-behavior' ),
					'loading_paused_retry'  => __( 'Loading paused (will retry)...', 'opti-behavior' ),
					'loading_fallback'   => __( 'Loading heatmap data with fallback...', 'opti-behavior' ),
					'loading_preview_proxy'   => __( 'Loading page preview through proxy...', 'opti-behavior' ),
					'measuring_preview_proxy' => __( 'Measuring proxied page preview...', 'opti-behavior' ),
					'interactive_on'     => __( 'Interactive preview ON — click inside the page to reveal hidden menus/accordions, then hotspots realign', 'opti-behavior' ),
					'interactive_off'    => __( 'Interactive preview OFF', 'opti-behavior' ),
					'interactive_hint'   => __( 'Interactive preview: click inside the page to reveal hidden menus/accordions so click hotspots line up', 'opti-behavior' ),
					'refreshing_cache'   => __( 'Refreshing heatmap cache...', 'opti-behavior' ),
					/* translators: %s: batch number. */
					'loading_batch'      => __( 'Loading heatmap data... (batch %s)', 'opti-behavior' ),
					/* translators: 1: current attempt number, 2: maximum attempts. */
					'retrying_count'     => __( 'Retrying... (%1$s/%2$s)', 'opti-behavior' ),
					/* translators: 1: batch number, 2: attempt number. */
					'retrying_batch'     => __( 'Retrying batch %1$s... (attempt %2$s)', 'opti-behavior' ),
					/* translators: 1: status message, 2: number of points loaded. */
					'points_loaded_status' => __( '%1$s - %2$s points loaded', 'opti-behavior' ),
					/* translators: 1: current batch, 2: total batches, 3: number of points. */
					'batch_loaded'       => __( 'Batch %1$s/%2$s loaded (%3$s points)', 'opti-behavior' ),
					/* translators: %s: number of points loaded. */
					'stopped_points_loaded'  => __( 'Stopped - %s points loaded', 'opti-behavior' ),
					/* translators: %s: number of points loaded. */
					'complete_points_loaded' => __( 'Complete! %s points loaded', 'opti-behavior' ),
				),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Enqueue detail page CSS.
		wp_enqueue_style(
			'opti-behavior-heatmap-detail',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/heatmap-detail.css',
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . time()
		);
	}

	/**
	 * Global default for excluding spam, controlled by Traffic Behavior settings.
	 *
	 * @return bool
	 */
	private function get_global_spam_exclusion_default() {
		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled' => true,
			)
		);

		if ( ! is_array( $traffic_settings ) ) {
			return true;
		}

		return ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] );
	}

	/**
	 * Read the "Count Bar Display" toggle (Settings → Data Collection).
	 *
	 * Gates the click-count hover bubble on the Click heatmap. Pro-only
	 * feature: returns 0 whenever the Pro plugin is inactive, regardless of
	 * the stored setting. Stored as 0|1 inside the opti_behavior_heatmap_option
	 * blob; defaults to 1 (show) when Pro is active.
	 *
	 * @return int 1 when the count bubble should show, 0 otherwise.
	 */
	private function get_count_bar_setting() {
		if ( ! function_exists( 'opti_behavior_pro_active' ) || ! opti_behavior_pro_active() ) {
			return 0;
		}

		$options = maybe_unserialize( get_option( 'opti_behavior_heatmap_option', array() ) );
		if ( ! is_array( $options ) ) {
			return 1;
		}

		return ( ! isset( $options['count_bar'] ) || 0 !== intval( $options['count_bar'] ) ) ? 1 : 0;
	}

	/**
	 * Render the heatmap detail page.
	 */
	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for admin page display only.
		// Get page ID from URL.
		$this->page_ids = $this->get_request_page_ids();
		$this->page_id  = ! empty( $this->page_ids ) ? absint( $this->page_ids[0] ) : 0;

		if ( ! $this->page_id ) {
			$this->render_error( __( 'Invalid page ID', 'opti-behavior' ) );
			return;
		}

		// Get page data.
		$this->page_data = $this->get_page_data( $this->page_id );

		if ( ! $this->page_data ) {
			$this->render_error( __( 'Page not found', 'opti-behavior' ) );
			return;
		}

		$this->maybe_handle_reset_heatmap_data();

		// Get current view parameters.
		$current_device = isset( $_GET['device'] ) ? sanitize_text_field( wp_unslash( $_GET['device'] ) ) : 'desktop';
		$current_type   = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : 'click';

		// One-shot success notice tied to an actual deletion (consumed on render),
		// not a sticky URL param that re-shows on every reload.
		$reset_notice_key = 'opti_behavior_heatmap_reset_' . get_current_user_id() . '_' . $this->page_id;
		$reset_notice     = (bool) get_transient( $reset_notice_key );
		if ( $reset_notice ) {
			delete_transient( $reset_notice_key );
		}

		// Resolve A/B test variant name if this heatmap is viewed per-variant.
		$opti_behavior_ab_test_id   = isset( $_GET['ab_test_id'] ) ? absint( $_GET['ab_test_id'] ) : 0;
		$opti_behavior_variant_id   = isset( $_GET['variant_id'] ) ? absint( $_GET['variant_id'] ) : 0;
		$opti_behavior_variant_name = '';
		$opti_behavior_test_name    = '';
		if ( $opti_behavior_variant_id && class_exists( 'Opti_Behavior_AB_Test_Database' ) ) {
			$opti_behavior_variant_obj = Opti_Behavior_AB_Test_Database::get_variant( $opti_behavior_variant_id );
			if ( $opti_behavior_variant_obj && ! empty( $opti_behavior_variant_obj->name ) ) {
				$opti_behavior_variant_name = $opti_behavior_variant_obj->name;
			}
			if ( $opti_behavior_ab_test_id ) {
				$opti_behavior_test_obj = Opti_Behavior_AB_Test_Database::get_test( $opti_behavior_ab_test_id );
				if ( $opti_behavior_test_obj && ! empty( $opti_behavior_test_obj->name ) ) {
					$opti_behavior_test_name = $opti_behavior_test_obj->name;
				}
			}
		}

		// Detect A/B tests for this page (for variant selector dropdown).
		// Include running AND completed tests — completed tests still have heatmap data.
		$opti_behavior_ab_variants = array();
		if ( class_exists( 'Opti_Behavior_AB_Test_Database' ) && ! empty( $this->page_data['url'] ) ) {
			$opti_behavior_page_tests = $this->get_ab_tests_for_url( $this->page_data['url'] );
			if ( ! empty( $opti_behavior_page_tests ) ) {
				foreach ( $opti_behavior_page_tests as $opti_behavior_ab_test ) {
					$opti_behavior_test_variants = Opti_Behavior_AB_Test_Database::get_variants( $opti_behavior_ab_test->id );
					if ( ! empty( $opti_behavior_test_variants ) ) {
						$opti_behavior_ab_variants[] = array(
							'test_id'   => $opti_behavior_ab_test->id,
							'test_name' => $opti_behavior_ab_test->name,
							'variants'  => $opti_behavior_test_variants,
						);
					}
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Get tooltips for heatmap detail page.
		$heatmap_tooltips = opti_behavior_get_heatmaps_tooltips();

		?>
		<div class="wrap opti-heatmap-detail-wrap">
			<?php if ( $reset_notice ) : ?>
				<div class="notice notice-success is-dismissible opti-behavior-notice opti-heatmap-reset-notice">
					<p><?php esc_html_e( 'Heatmap data for this page has been deleted. New heatmap stats will start from now.', 'opti-behavior' ); ?></p>
				</div>
			<?php endif; ?>
			<?php $this->render_header( $current_device, $current_type, $opti_behavior_variant_name, $opti_behavior_test_name, $heatmap_tooltips ); ?>
			<?php if ( function_exists( 'opti_behavior_pro_sodium_banner' ) ) { opti_behavior_pro_sodium_banner(); } ?>
			<?php $this->render_filters_bar( $heatmap_tooltips, $opti_behavior_ab_variants, $opti_behavior_ab_test_id, $opti_behavior_variant_id, $current_device, $current_type ); ?>
			<?php $this->render_device_stats_bar( $current_device, $current_type, $heatmap_tooltips ); ?>
			<?php $this->render_heatmap_container( $current_type ); ?>
			<?php $this->render_stats_panel( $heatmap_tooltips ); ?>
			<?php $this->render_reset_confirm_modal(); ?>
		</div>
		<?php
	}

	/**
	 * Handle current-page heatmap reset submissions.
	 */
	private function maybe_handle_reset_heatmap_data() {
		if ( empty( $_POST['opti_behavior_reset_heatmap_page_id'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete heatmap data.', 'opti-behavior' ) );
		}

		$posted_page_id = absint( wp_unslash( $_POST['opti_behavior_reset_heatmap_page_id'] ) );
		if ( $posted_page_id !== (int) $this->page_id ) {
			wp_die( esc_html__( 'Invalid heatmap page.', 'opti-behavior' ) );
		}

		check_admin_referer( 'opti_behavior_reset_heatmap_' . $this->page_id, 'opti_behavior_reset_heatmap_nonce' );

		$this->delete_current_page_heatmap_data( $this->page_id );

		// Flag a one-shot success notice tied to this real deletion.
		set_transient( 'opti_behavior_heatmap_reset_' . get_current_user_id() . '_' . $this->page_id, 1, 60 );

		// Preserve the user's current view so the redirect keeps context instead of
		// snapping back to the hardcoded click/desktop default.
		$reset_type   = isset( $_POST['opti_behavior_reset_type'] ) ? sanitize_text_field( wp_unslash( $_POST['opti_behavior_reset_type'] ) ) : 'click';
		$reset_device = isset( $_POST['opti_behavior_reset_device'] ) ? sanitize_text_field( wp_unslash( $_POST['opti_behavior_reset_device'] ) ) : 'desktop';

		$redirect_url = admin_url(
			'admin.php?page=opti-behavior-heatmap-detail&page_id=' . $this->page_id . '&type=' . rawurlencode( $reset_type ) . '&device=' . rawurlencode( $reset_device )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Delete heatmap-only data for a page while keeping the page, recordings, sessions, and analytics history.
	 *
	 * @param int $page_id Page ID from optibehavior_pages.
	 */
	private function delete_current_page_heatmap_data( $page_id ) {
		global $wpdb;

		$page_id  = absint( $page_id );
		$reset_at = current_time( 'mysql' );
		$resets   = get_option( 'opti_behavior_heatmap_page_resets', array() );

		if ( ! is_array( $resets ) ) {
			$resets = array();
		}

		$resets[ $page_id ] = $reset_at;
		update_option( 'opti_behavior_heatmap_page_resets', $resets, false );

		$events_table = esc_sql( $wpdb->prefix . 'optibehavior_events' );

		// Delete legacy database heatmap event rows for this page only.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names are prefixed by wpdb; placeholders are prepared below.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$events_table} WHERE ( page_id2 = %d OR page_id = %d ) AND event IN (%d,%d,%d,%d,%d,%d)",
				$page_id,
				$page_id,
				16,
				17,
				32,
				33,
				48,
				49
			)
		);

		$this->delete_page_heatmap_files( $page_id );
		$this->reset_heatmap_page_counters( $page_id );
		$this->clear_heatmap_page_caches( $page_id );
	}

	/**
	 * Delete optimized heatmap files for a page URL hash.
	 *
	 * @param int $page_id Page ID from optibehavior_pages.
	 */
	private function delete_page_heatmap_files( $page_id ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return;
		}

		$storage = Opti_Behavior_Heatmap_Storage::get_instance();
		if ( ! $storage || ! method_exists( $storage, 'get_url_hash_for_page' ) || ! method_exists( $storage, 'get_base_dir' ) ) {
			return;
		}

		$url_hash = $storage->get_url_hash_for_page( $page_id );
		if ( ! $url_hash ) {
			return;
		}

		$base_dir = trailingslashit( $storage->get_base_dir() );
		$page_dir = $base_dir . sanitize_file_name( $url_hash ) . '/';
		$real_base = realpath( $base_dir );
		$real_page = realpath( $page_dir );

		if ( ! $real_base || ! $real_page || 0 !== strpos( $real_page, $real_base ) ) {
			return;
		}

		foreach ( array( 'clicks', 'moves', 'scrolls' ) as $type_dir ) {
			$dir = trailingslashit( $page_dir . $type_dir );
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = glob( $dir . '*.json' );
			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}
	}

	/**
	 * Reset optimized heatmap aggregate counters for a page.
	 *
	 * @param int $page_id Page ID from optibehavior_pages.
	 */
	private function reset_heatmap_page_counters( $page_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return;
		}

		$data    = array(
			'click_count'  => 0,
			'move_count'   => 0,
			'scroll_count' => 0,
			'last_data_at' => null,
			'updated_at'   => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%d', '%d', '%s', '%s' );

		// Phase 3: zero the precomputed aggregate columns directly. Marking the
		// row stale (agg_synced_at = NULL) is NOT enough: the resync query in
		// sync_heatmap_aggregate_columns() skips rows where all raw counters
		// are zero, so a reset page would keep serving its old agg_* numbers
		// (Interactions / Sessions / device split) in the Available Heatmaps
		// table forever. The post-reset state is exactly zero, so we can store
		// it as already-synced instead of waiting for a recompute.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema capability probe.
		$has_agg = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				DB_NAME,
				$table,
				'agg_synced_at'
			)
		);
		if ( $has_agg > 0 ) {
			$agg_zero_cols = array(
				'agg_click_pc',
				'agg_click_mobile',
				'agg_break_pc',
				'agg_break_mobile',
				'agg_att_pc',
				'agg_att_mobile',
				'agg_sessions_desktop',
				'agg_sessions_mobile',
				'agg_sessions',
				'agg_sort_sessions',
				'agg_interactions',
				'agg_has_data',
			);
			foreach ( $agg_zero_cols as $agg_col ) {
				$data[ $agg_col ] = 0;
				$formats[]        = '%d';
			}
			$data['agg_last_event'] = null;
			$formats[]              = '%s';
			$data['agg_synced_at']  = current_time( 'mysql' );
			$formats[]              = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin reset updates the plugin aggregate table directly.
		$wpdb->update(
			$table,
			$data,
			array( 'page_id' => $page_id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Clear processed heatmap caches after deleting page heatmap data.
	 *
	 * @param int $page_id Page ID from optibehavior_pages.
	 */
	private function clear_heatmap_page_caches( $page_id ) {
		global $wpdb;

		if ( ! class_exists( 'Opti_Behavior_Heatmap_Cache' ) && file_exists( OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-cache.php' ) ) {
			require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-cache.php';
		}

		if ( class_exists( 'Opti_Behavior_Heatmap_Cache' ) ) {
			$cache = new Opti_Behavior_Heatmap_Cache();
			$cache->clear( $page_id );
		}

		wp_cache_delete( 'opti_url_hash_' . (int) $page_id, 'opti-behavior' );

		$patterns = array(
			'_transient_' . $wpdb->esc_like( 'page_' . (int) $page_id . '_device_' ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( 'page_' . (int) $page_id . '_device_' ) . '%',
			'_transient_' . $wpdb->esc_like( 'opti_heatmap_' . (int) $page_id . '_' ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( 'opti_heatmap_' . (int) $page_id . '_' ) . '%',
			'_transient_' . $wpdb->esc_like( 'opti_hm_list_' ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( 'opti_hm_list_' ) . '%',
		);

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup by transient name pattern.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		}

		// Rotate the version-namespaced dashboard caches (ob_hm_stats_*,
		// ob_hm_metrics_*, ...): the Available Heatmaps table is served from
		// these keys, which embed the cache version instead of the page id, so
		// the per-page transient deletes above never touch them. Without the
		// version bump the table keeps showing the pre-reset Interactions /
		// Sessions numbers until the TTL safety net expires.
		if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
			opti_behavior_flush_heatmap_caches();
		}
	}

	/**
	 * Render professional confirmation modal for heatmap reset.
	 */
	private function render_reset_confirm_modal() {
		?>
		<div id="opti-heatmap-reset-modal" class="opti-ab-confirm-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="opti-heatmap-reset-modal-title" aria-describedby="opti-heatmap-reset-modal-message">
			<div class="opti-ab-confirm-modal">
				<div class="opti-ab-confirm-modal__icon" aria-hidden="true">
					<i data-lucide="trash-2"></i>
				</div>
				<h2 id="opti-heatmap-reset-modal-title" class="opti-ab-confirm-modal__title"><?php esc_html_e( 'Delete Heatmap Data?', 'opti-behavior' ); ?></h2>
				<p id="opti-heatmap-reset-modal-message" class="opti-ab-confirm-modal__message"><?php esc_html_e( 'This will permanently delete the heatmap data collected for this page. Session recordings and analytics records will be kept. New heatmap stats will start from now.', 'opti-behavior' ); ?></p>
				<div class="opti-ab-confirm-modal__actions">
					<button type="button" id="opti-heatmap-reset-modal-cancel" class="opti-ab-confirm-modal__btn-cancel"><?php esc_html_e( 'Cancel', 'opti-behavior' ); ?></button>
					<button type="button" id="opti-heatmap-reset-modal-confirm" class="opti-ab-confirm-modal__btn-danger"><?php esc_html_e( 'Delete Data', 'opti-behavior' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the first/last recorded session dates for the current page.
	 *
	 * Uses an indexed DB MIN/MAX query (session_pages entry_time, with a
	 * pageviews view_time fallback) instead of scanning heatmap JSON files,
	 * so this stays cheap at any data volume. The result is ONLY used to
	 * prefill the hero From/To inputs for UX when the user picks "Custom
	 * range" — it is never applied as an active filter.
	 *
	 * @return array { first: 'Y-m-d'|'', last: 'Y-m-d'|'' }
	 */
	private function get_session_date_bounds() {
		global $wpdb;

		$bounds = array(
			'first' => '',
			'last'  => '',
		);

		$page_id = absint( $this->page_id );
		if ( $page_id <= 0 ) {
			return $bounds;
		}

		// Preferred source: session_pages (entry_time stored as UTC datetime,
		// indexed by page_id).
		$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_session_pages ) ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT MIN(entry_time) AS first_time, MAX(entry_time) AS last_time FROM {$table_session_pages} WHERE page_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$page_id
				)
			);
			if ( $row && ! empty( $row->first_time ) ) {
				$bounds['first'] = wp_date( 'Y-m-d', strtotime( $row->first_time . ' UTC' ) );
				$bounds['last']  = wp_date( 'Y-m-d', strtotime( $row->last_time . ' UTC' ) );
				return $bounds;
			}
		}

		// Fallback: pageviews (view_time stored via current_time('mysql'),
		// i.e. site-local time; indexed by page_id/view_time).
		$table_pageviews = $wpdb->prefix . 'optibehavior_pageviews';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_pageviews ) ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT MIN(view_time) AS first_time, MAX(view_time) AS last_time FROM {$table_pageviews} WHERE page_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$page_id
				)
			);
			if ( $row && ! empty( $row->first_time ) ) {
				$bounds['first'] = substr( $row->first_time, 0, 10 );
				$bounds['last']  = substr( $row->last_time, 0, 10 );
			}
		}

		return $bounds;
	}

	/**
	 * Render page header with breadcrumb and controls.
	 *
	 * @param string $current_device Current device filter.
	 * @param string $current_type     Current heatmap type.
	 * @param string $variant_name     Variant name (empty if not viewing per-variant).
	 * @param string $test_name        A/B test name (empty if not viewing per-variant).
	 * @param array  $heatmap_tooltips Tooltips array (for the period tooltip in the hero toolbar).
	 */
	private function render_header( $current_device, $current_type, $variant_name = '', $test_name = '', $heatmap_tooltips = array() ) {
		$back_url = admin_url( 'admin.php?page=opti-behavior-heatmaps' );

		// Try to get the WordPress post ID from the page URL for edit link
		$edit_url = '';
		$wp_post_id = url_to_postid( $this->page_data['url'] );
		if ( $wp_post_id > 0 && current_user_can( 'edit_post', $wp_post_id ) ) {
			$edit_url = admin_url( 'post.php?post=' . $wp_post_id . '&action=edit' );
		}

		// Initial custom range values (moved into the hero toolbar with the period controls).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only date filter controls.
		$initial_start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : ( isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '' );
		$initial_end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : ( isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Dashboard-style autofill: no explicit dates -> show the full recorded
		// range (first session date -> today, "All time" always ends today).
		$session_bounds = $this->get_session_date_bounds();
		if ( '' === $initial_start_date && ! empty( $session_bounds['first'] ) ) {
			$initial_start_date = $session_bounds['first'];
		}
		if ( '' === $initial_end_date ) {
			$initial_end_date = wp_date( 'Y-m-d' );
		}
		?>
		<div class="opti-heatmap-header opti-heatmap-hero">
			<div class="opti-heatmap-hero-main">
				<a href="<?php echo esc_url( $back_url ); ?>" class="opti-heatmap-hero-back" title="<?php esc_attr_e( 'Back to Heatmaps', 'opti-behavior' ); ?>" aria-label="<?php esc_attr_e( 'Back to Heatmaps', 'opti-behavior' ); ?>">
					<i data-lucide="arrow-left"></i>
				</a>
				<div class="opti-heatmap-title">
					<h1>
						<?php echo esc_html( $this->page_data['title'] ); ?>
						<span class="recordings-count" data-count="0">
							<?php
							// "Delete Heatmap Data" dual display: the hidden
							// all-time suffix is shown by JS ("45 / 108") when the
							// page has a reset floor and the counts differ.
							?>
							(<span class="count-num">0</span><span class="recordings-reset-alltime" style="display:none;"> / <span class="all-time-num">0</span></span> <?php esc_html_e( 'sessions', 'opti-behavior' ); ?>)
						</span>
						<span class="opti-heatmap-scope-label" style="font-size:12px;font-weight:400;color:rgba(255,255,255,0.85);margin-left:4px;"><?php esc_html_e( '· All time', 'opti-behavior' ); ?></span>
					</h1>
					<?php if ( ! empty( $variant_name ) ) : ?>
					<div class="opti-heatmap-variant-badge">
						<i data-lucide="flask-conical" style="width:14px;height:14px;"></i>
						<?php if ( ! empty( $test_name ) ) : ?>
							<span><?php echo esc_html( $test_name ); ?> &rarr;</span>
						<?php endif; ?>
						<strong><?php echo esc_html( $variant_name ); ?></strong>
					</div>
					<?php endif; ?>
					<div class="page-url">
						<a href="<?php echo esc_url( $this->page_data['url'] ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $this->page_data['url'] ); ?>
							<i data-lucide="external-link"></i>
						</a>
						<?php if ( ! empty( $edit_url ) ) : ?>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="edit-page-link" title="<?php esc_attr_e( 'Edit page', 'opti-behavior' ); ?>">
								<i data-lucide="pencil"></i>
								<?php esc_html_e( 'Edit Page', 'opti-behavior' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<div class="opti-heatmap-hero-controls" data-first-session="<?php echo esc_attr( $session_bounds['first'] ); ?>" data-last-session="<?php echo esc_attr( $session_bounds['last'] ); ?>">
				<!-- Period Filter (dashboard-style; Custom always selectable) -->
				<select id="filter-date-range" class="filter-select" data-filter="date_range" aria-label="<?php esc_attr_e( 'Period', 'opti-behavior' ); ?>">
					<option value="all" selected><?php esc_html_e( 'All time', 'opti-behavior' ); ?></option>
					<option value="today"><?php esc_html_e( 'Today', 'opti-behavior' ); ?></option>
					<option value="yesterday"><?php esc_html_e( 'Yesterday', 'opti-behavior' ); ?></option>
					<option value="last7days"><?php esc_html_e( 'Last 7 days', 'opti-behavior' ); ?></option>
					<option value="last30days"><?php esc_html_e( 'Last 30 days', 'opti-behavior' ); ?></option>
					<option value="custom"><?php esc_html_e( 'Custom range', 'opti-behavior' ); ?></option>
				</select>

				<!-- Custom date range inputs (dashboard-style) -->
				<input type="date" id="heatmap-start-date" class="heatmap-date-input" value="<?php echo esc_attr( $initial_start_date ); ?>" aria-label="<?php esc_attr_e( 'From', 'opti-behavior' ); ?>" />
				<input type="date" id="heatmap-end-date" class="heatmap-date-input" value="<?php echo esc_attr( $initial_end_date ); ?>" aria-label="<?php esc_attr_e( 'To', 'opti-behavior' ); ?>" />

				<!-- Apply selected range -->
				<button type="button" class="button hero-btn filter-apply-btn" id="apply-heatmap-range" title="<?php esc_attr_e( 'Apply the selected date range', 'opti-behavior' ); ?>">
					<i data-lucide="calendar"></i>
					<?php esc_html_e( 'Apply', 'opti-behavior' ); ?>
				</button>

				<!-- Hero refresh (mirrors #refresh-heatmap in the filters bar below) -->
				<button type="button" class="button hero-btn opti-hero-refresh" title="<?php esc_attr_e( 'Refresh heatmap data', 'opti-behavior' ); ?>">
					<i data-lucide="refresh-cw"></i>
					<?php esc_html_e( 'Refresh', 'opti-behavior' ); ?>
				</button>

				<!-- Advanced Filters toggle (dashboard-style) -->
				<button type="button" class="button hero-btn filter-toggle-btn" id="toggle-advanced-filters" aria-expanded="false" aria-controls="advanced-filters-panel">
					<i data-lucide="sliders-horizontal"></i>
					<?php esc_html_e( 'Filters', 'opti-behavior' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render filters bar with date range, country, browser, visitor type, and variant filters.
	 * Country and Browser support multi-select with checkboxes.
	 *
	 * @param array $heatmap_tooltips  Tooltips array.
	 * @param array  $ab_variants       Active A/B test variants for this page.
	 * @param int    $current_ab_test   Currently selected A/B test ID (from URL).
	 * @param int    $current_variant   Currently selected variant ID (from URL).
	 * @param string $current_device    Current device filter (for the delete-form hidden fields).
	 * @param string $current_type      Current heatmap type (for the delete-form hidden fields).
	 */
	private function render_filters_bar( $heatmap_tooltips = array(), $ab_variants = array(), $current_ab_test = 0, $current_variant = 0, $current_device = 'desktop', $current_type = 'click' ) {
		?>
		<div class="opti-heatmap-filters-bar opti-heatmap-toolbar">
			<div class="filters-controls">
				<?php if ( ! empty( $ab_variants ) ) : ?>
				<!-- A/B Test Variant Filter (standalone single select) -->
				<div class="filter-group">
					<label for="filter-ab-variant"><?php esc_html_e( 'A/B VARIANT', 'opti-behavior' ); ?></label>
					<select id="filter-ab-variant" class="filter-select" data-filter="ab_variant">
						<option value=""><?php esc_html_e( 'All Variants', 'opti-behavior' ); ?></option>
						<?php foreach ( $ab_variants as $opti_behavior_ab_group ) : ?>
							<?php foreach ( $opti_behavior_ab_group['variants'] as $opti_behavior_v ) : ?>
								<option
									value="<?php echo esc_attr( $opti_behavior_ab_group['test_id'] . ':' . $opti_behavior_v->id ); ?>"
									<?php selected( $current_ab_test === (int) $opti_behavior_ab_group['test_id'] && $current_variant === (int) $opti_behavior_v->id ); ?>
								>
									<?php echo esc_html( $opti_behavior_v->name ); ?>
								</option>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>

				<!-- Strict Matching Toggle (element-anchored clicks) -->
				<button type="button" class="button filter-reset-btn" id="strict-matching-toggle" aria-pressed="true" title="<?php esc_attr_e( 'Show only clicks whose element still exists in the current page preview', 'opti-behavior' ); ?>">
					<i data-lucide="crosshair"></i>
					<?php esc_html_e( 'Strict Matching', 'opti-behavior' ); ?>
				</button>

				<!-- Interactive Preview Toggle (click inside the page to reveal hidden UI so hotspots align). Default OFF. -->
				<button type="button" class="button filter-reset-btn" id="interactive-preview-toggle" aria-pressed="false" title="<?php esc_attr_e( 'Interactive preview: click inside the page to reveal hidden menus/accordions so click hotspots line up', 'opti-behavior' ); ?>">
					<i data-lucide="mouse-pointer-click"></i>
					<?php esc_html_e( 'Interactive Preview', 'opti-behavior' ); ?>
				</button>

				<!-- Refresh Heatmap Button -->
				<button type="button" class="button filter-reset-btn" id="refresh-heatmap" title="<?php esc_attr_e( 'Refresh heatmap data', 'opti-behavior' ); ?>">
					<i data-lucide="refresh-cw"></i>
					<?php esc_html_e( 'Refresh', 'opti-behavior' ); ?>
				</button>

				<!-- Reset Filters Button -->
				<button type="button" class="button filter-reset-btn" id="reset-filters" title="<?php esc_attr_e( 'Reset all filters', 'opti-behavior' ); ?>">
					<i data-lucide="x"></i>
					<?php esc_html_e( 'Reset', 'opti-behavior' ); ?>
				</button>
			</div>

			<!-- Right-corner page actions (moved from the old header) -->
			<div class="opti-heatmap-bar-actions">
				<form method="post" class="opti-reset-heatmap-form">
					<?php wp_nonce_field( 'opti_behavior_reset_heatmap_' . $this->page_id, 'opti_behavior_reset_heatmap_nonce' ); ?>
					<input type="hidden" name="opti_behavior_reset_heatmap_page_id" value="<?php echo esc_attr( $this->page_id ); ?>">
					<input type="hidden" name="opti_behavior_reset_type" value="<?php echo esc_attr( $current_type ); ?>">
					<input type="hidden" name="opti_behavior_reset_device" value="<?php echo esc_attr( $current_device ); ?>">
					<button type="submit" class="button button-secondary opti-reset-heatmap-button" title="<?php esc_attr_e( 'Delete old heatmap data for this page', 'opti-behavior' ); ?>">
						<i data-lucide="trash-2"></i>
						<?php esc_html_e( 'Delete Heatmap Data', 'opti-behavior' ); ?>
					</button>
				</form>
				<button type="button" class="button button-secondary" id="download-heatmap" disabled title="<?php esc_attr_e( 'Download heatmap as image', 'opti-behavior' ); ?>">
					<i data-lucide="image-down"></i>
					<?php esc_html_e( 'Download', 'opti-behavior' ); ?>
				</button>
			</div>
		</div>
		<?php
		$this->render_advanced_filters_panel( $heatmap_tooltips );
	}

	/**
	 * Render the collapsible advanced filters panel (dashboard-style).
	 *
	 * Grouped columns: Visitor Attributes, Session Attributes, Pages & Traffic,
	 * UTM Parameters. Hidden by default; toggled by #toggle-advanced-filters.
	 *
	 * @param array $heatmap_tooltips Tooltips array.
	 */
	private function render_advanced_filters_panel( $heatmap_tooltips = array() ) {
		?>
		<div id="advanced-filters-panel" class="advanced-filters-panel" style="display: none;">
			<div class="advanced-filters-grid">
				<!-- Column 1: Visitor Attributes -->
				<div class="filter-column">
					<h4 class="filter-column-title"><?php esc_html_e( 'Visitor Attributes', 'opti-behavior' ); ?></h4>

					<div class="filter-group filter-multiselect">
						<label><?php esc_html_e( 'Browser Name', 'opti-behavior' ); ?> <?php if ( ! empty( $heatmap_tooltips['filter_browser'] ) ) { opti_behavior_tooltip_e( $heatmap_tooltips['filter_browser']['title'], $heatmap_tooltips['filter_browser']['content'], $heatmap_tooltips['filter_browser']['simple'], '', array( 'position' => 'bottom' ) ); } ?></label>
						<div class="multiselect-dropdown loading" id="filter-browser-dropdown" data-filter="browser">
							<div class="multiselect-trigger">
								<span class="multiselect-label"><?php esc_html_e( 'Loading...', 'opti-behavior' ); ?></span>
								<i data-lucide="chevron-down" class="dropdown-arrow"></i>
							</div>
							<div class="multiselect-menu">
								<div class="multiselect-search">
									<input type="text" placeholder="<?php esc_attr_e( 'Search browsers...', 'opti-behavior' ); ?>" class="multiselect-search-input" />
								</div>
								<div class="multiselect-options">
									<div class="multiselect-loading">
										<span class="loading-spinner-small"></span>
										<?php esc_html_e( 'Loading browsers...', 'opti-behavior' ); ?>
									</div>
								</div>
								<div class="multiselect-actions">
									<button type="button" class="multiselect-clear"><?php esc_html_e( 'Clear', 'opti-behavior' ); ?></button>
									<button type="button" class="multiselect-apply"><?php esc_html_e( 'Apply', 'opti-behavior' ); ?></button>
								</div>
							</div>
						</div>
					</div>

					<div class="filter-group filter-multiselect">
						<label><?php esc_html_e( 'Country', 'opti-behavior' ); ?> <?php if ( ! empty( $heatmap_tooltips['filter_country'] ) ) { opti_behavior_tooltip_e( $heatmap_tooltips['filter_country']['title'], $heatmap_tooltips['filter_country']['content'], $heatmap_tooltips['filter_country']['simple'], '', array( 'position' => 'bottom' ) ); } ?></label>
						<div class="multiselect-dropdown loading" id="filter-country-dropdown" data-filter="country">
							<div class="multiselect-trigger">
								<span class="multiselect-label"><?php esc_html_e( 'Loading...', 'opti-behavior' ); ?></span>
								<i data-lucide="chevron-down" class="dropdown-arrow"></i>
							</div>
							<div class="multiselect-menu">
								<div class="multiselect-search">
									<input type="text" placeholder="<?php esc_attr_e( 'Search countries...', 'opti-behavior' ); ?>" class="multiselect-search-input" />
								</div>
								<div class="multiselect-options">
									<div class="multiselect-loading">
										<span class="loading-spinner-small"></span>
										<?php esc_html_e( 'Loading countries...', 'opti-behavior' ); ?>
									</div>
								</div>
								<div class="multiselect-actions">
									<button type="button" class="multiselect-clear"><?php esc_html_e( 'Clear', 'opti-behavior' ); ?></button>
									<button type="button" class="multiselect-apply"><?php esc_html_e( 'Apply', 'opti-behavior' ); ?></button>
								</div>
							</div>
						</div>
					</div>

					<div class="filter-group filter-multiselect">
						<label><?php esc_html_e( 'Operating System', 'opti-behavior' ); ?></label>
						<div class="multiselect-dropdown" id="filter-os-dropdown" data-filter="os">
							<div class="multiselect-trigger">
								<span class="multiselect-label"><?php esc_html_e( 'All Operating Systems', 'opti-behavior' ); ?></span>
								<i data-lucide="chevron-down" class="dropdown-arrow"></i>
							</div>
							<div class="multiselect-menu">
								<div class="multiselect-search">
									<input type="text" placeholder="<?php esc_attr_e( 'Search operating systems...', 'opti-behavior' ); ?>" class="multiselect-search-input" />
								</div>
								<div class="multiselect-options">
									<div class="multiselect-empty"><?php esc_html_e( 'No OS data available', 'opti-behavior' ); ?></div>
								</div>
								<div class="multiselect-actions">
									<button type="button" class="multiselect-clear"><?php esc_html_e( 'Clear', 'opti-behavior' ); ?></button>
									<button type="button" class="multiselect-apply"><?php esc_html_e( 'Apply', 'opti-behavior' ); ?></button>
								</div>
							</div>
						</div>
					</div>

					<div class="filter-group">
						<label for="filter-visitor-type"><?php esc_html_e( 'Visitor Type', 'opti-behavior' ); ?> <?php if ( ! empty( $heatmap_tooltips['filter_visitor_type'] ) ) { opti_behavior_tooltip_e( $heatmap_tooltips['filter_visitor_type']['title'], $heatmap_tooltips['filter_visitor_type']['content'], $heatmap_tooltips['filter_visitor_type']['simple'], '', array( 'position' => 'bottom' ) ); } ?></label>
						<select id="filter-visitor-type" class="filter-select advanced-filter-select" data-filter="visitor_type">
							<option value=""><?php esc_html_e( 'All Visitors', 'opti-behavior' ); ?></option>
							<option value="guest" selected="selected"><?php esc_html_e( 'Guest Visitor', 'opti-behavior' ); ?></option>
							<option value="logged_in"><?php esc_html_e( 'Logged In', 'opti-behavior' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Column 2: Session Attributes -->
				<div class="filter-column">
					<h4 class="filter-column-title"><?php esc_html_e( 'Session Attributes', 'opti-behavior' ); ?></h4>

					<div class="filter-group">
						<label for="filter-duration-min"><?php esc_html_e( 'Min Duration (seconds)', 'opti-behavior' ); ?></label>
						<input type="number" id="filter-duration-min" class="advanced-filter-input" min="0" step="1" placeholder="<?php esc_attr_e( 'e.g. 10', 'opti-behavior' ); ?>" />
					</div>

					<div class="filter-group">
						<label for="filter-duration-max"><?php esc_html_e( 'Max Duration (seconds)', 'opti-behavior' ); ?></label>
						<input type="number" id="filter-duration-max" class="advanced-filter-input" min="0" step="1" placeholder="<?php esc_attr_e( 'e.g. 300', 'opti-behavior' ); ?>" />
					</div>
				</div>

				<!-- Column 3: Pages & Traffic -->
				<div class="filter-column">
					<h4 class="filter-column-title"><?php esc_html_e( 'Pages & Traffic', 'opti-behavior' ); ?></h4>

					<div class="filter-group">
						<label for="filter-entry-page"><?php esc_html_e( 'Entry Page', 'opti-behavior' ); ?></label>
						<input type="text" id="filter-entry-page" class="advanced-filter-input" placeholder="<?php esc_attr_e( 'e.g. /landing-page', 'opti-behavior' ); ?>" />
					</div>

					<div class="filter-group">
						<label for="filter-exit-page"><?php esc_html_e( 'Exit Page', 'opti-behavior' ); ?></label>
						<input type="text" id="filter-exit-page" class="advanced-filter-input" placeholder="<?php esc_attr_e( 'e.g. /checkout', 'opti-behavior' ); ?>" />
					</div>

					<div class="filter-group">
						<label for="filter-referrer"><?php esc_html_e( 'Referrer URL', 'opti-behavior' ); ?></label>
						<input type="text" id="filter-referrer" class="advanced-filter-input" placeholder="<?php esc_attr_e( 'e.g. google.com', 'opti-behavior' ); ?>" />
					</div>

					<div class="filter-group">
						<label for="filter-traffic-channel"><?php esc_html_e( 'Traffic Channel', 'opti-behavior' ); ?></label>
						<select id="filter-traffic-channel" class="advanced-filter-select">
							<option value=""><?php esc_html_e( 'All Channels', 'opti-behavior' ); ?></option>
							<option value="Direct"><?php esc_html_e( 'Direct', 'opti-behavior' ); ?></option>
							<option value="Organic Search"><?php esc_html_e( 'Organic Search', 'opti-behavior' ); ?></option>
							<option value="Paid Ads"><?php esc_html_e( 'Paid Ads', 'opti-behavior' ); ?></option>
							<option value="Social Media"><?php esc_html_e( 'Social Media', 'opti-behavior' ); ?></option>
							<option value="Email"><?php esc_html_e( 'Email', 'opti-behavior' ); ?></option>
							<option value="Referral"><?php esc_html_e( 'Referral', 'opti-behavior' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Column 4: UTM Parameters -->
				<div class="filter-column">
					<h4 class="filter-column-title"><?php esc_html_e( 'UTM Parameters', 'opti-behavior' ); ?></h4>

					<div class="filter-group filter-multiselect">
						<label><?php esc_html_e( 'Campaign', 'opti-behavior' ); ?></label>
						<div class="multiselect-dropdown" id="filter-utm-campaign-dropdown" data-filter="utm_campaign">
							<div class="multiselect-trigger">
								<span class="multiselect-label"><?php esc_html_e( 'All Campaigns', 'opti-behavior' ); ?></span>
								<i data-lucide="chevron-down" class="dropdown-arrow"></i>
							</div>
							<div class="multiselect-menu">
								<div class="multiselect-search">
									<input type="text" placeholder="<?php esc_attr_e( 'Search campaigns...', 'opti-behavior' ); ?>" class="multiselect-search-input" />
								</div>
								<div class="multiselect-options">
									<div class="multiselect-empty"><?php esc_html_e( 'No campaign data available', 'opti-behavior' ); ?></div>
								</div>
								<div class="multiselect-actions">
									<button type="button" class="multiselect-clear"><?php esc_html_e( 'Clear', 'opti-behavior' ); ?></button>
									<button type="button" class="multiselect-apply"><?php esc_html_e( 'Apply', 'opti-behavior' ); ?></button>
								</div>
							</div>
						</div>
					</div>

					<div class="filter-group filter-multiselect">
						<label><?php esc_html_e( 'Source', 'opti-behavior' ); ?></label>
						<div class="multiselect-dropdown" id="filter-utm-source-dropdown" data-filter="utm_source">
							<div class="multiselect-trigger">
								<span class="multiselect-label"><?php esc_html_e( 'All Sources', 'opti-behavior' ); ?></span>
								<i data-lucide="chevron-down" class="dropdown-arrow"></i>
							</div>
							<div class="multiselect-menu">
								<div class="multiselect-search">
									<input type="text" placeholder="<?php esc_attr_e( 'Search sources...', 'opti-behavior' ); ?>" class="multiselect-search-input" />
								</div>
								<div class="multiselect-options">
									<div class="multiselect-empty"><?php esc_html_e( 'No source data available', 'opti-behavior' ); ?></div>
								</div>
								<div class="multiselect-actions">
									<button type="button" class="multiselect-clear"><?php esc_html_e( 'Clear', 'opti-behavior' ); ?></button>
									<button type="button" class="multiselect-apply"><?php esc_html_e( 'Apply', 'opti-behavior' ); ?></button>
								</div>
							</div>
						</div>
					</div>

					<div class="filter-group filter-multiselect">
						<label><?php esc_html_e( 'Medium', 'opti-behavior' ); ?></label>
						<div class="multiselect-dropdown" id="filter-utm-medium-dropdown" data-filter="utm_medium">
							<div class="multiselect-trigger">
								<span class="multiselect-label"><?php esc_html_e( 'All Mediums', 'opti-behavior' ); ?></span>
								<i data-lucide="chevron-down" class="dropdown-arrow"></i>
							</div>
							<div class="multiselect-menu">
								<div class="multiselect-search">
									<input type="text" placeholder="<?php esc_attr_e( 'Search mediums...', 'opti-behavior' ); ?>" class="multiselect-search-input" />
								</div>
								<div class="multiselect-options">
									<div class="multiselect-empty"><?php esc_html_e( 'No medium data available', 'opti-behavior' ); ?></div>
								</div>
								<div class="multiselect-actions">
									<button type="button" class="multiselect-clear"><?php esc_html_e( 'Clear', 'opti-behavior' ); ?></button>
									<button type="button" class="multiselect-apply"><?php esc_html_e( 'Apply', 'opti-behavior' ); ?></button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="advanced-filters-actions">
				<button type="button" class="button" id="reset-advanced-filters">
					<i data-lucide="rotate-ccw"></i>
					<?php esc_html_e( 'Reset', 'opti-behavior' ); ?>
				</button>
				<button type="button" class="button button-primary" id="apply-advanced-filters">
					<i data-lucide="check"></i>
					<?php esc_html_e( 'Apply Filters', 'opti-behavior' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Get available filter options from database for this page.
	 *
	 * @param int|array $page_ids Page ID or canonical page IDs.
	 * @return array Filter options.
	 */
	private function get_filter_options( $page_ids ) {
		global $wpdb;

		$options = array(
			'countries' => array(),
			'browsers'  => array(),
		);

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return $options;
		}

		$page_placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );

		// Get unique countries from session_pages for this page.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$countries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT sp.country, sp.country_name
				FROM {$wpdb->prefix}optibehavior_session_pages sp
				WHERE sp.page_id IN ($page_placeholders)
				AND sp.country IS NOT NULL
				AND sp.country != ''
				ORDER BY sp.country_name ASC",
				$page_ids
			)
		);

		foreach ( $countries as $row ) {
			if ( ! empty( $row->country ) ) {
				$options['countries'][ $row->country ] = ! empty( $row->country_name ) ? $row->country_name : $row->country;
			}
		}

		// Get unique browsers from session_pages for this page.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$browsers = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT sp.browser
				FROM {$wpdb->prefix}optibehavior_session_pages sp
				WHERE sp.page_id IN ($page_placeholders)
				AND sp.browser IS NOT NULL
				AND sp.browser != ''
				ORDER BY sp.browser ASC",
				$page_ids
			)
		);

		$options['browsers'] = array_filter( $browsers );

		return $options;
	}

	/**
	 * Render device switcher and stats bar with type tabs.
	 *
	 * @param string $current_device Current device filter.
	 * @param string $current_type Current heatmap type.
	 * @param array  $heatmap_tooltips Tooltips array.
	 */
	private function render_device_stats_bar( $current_device, $current_type = 'click', $heatmap_tooltips = array() ) {
		// Check if Pro features are LICENSED + ACTIVE (not just plugin file present).
		//
		// Bug 6 fix: when the Pro plugin file is loaded but the license is in any
		// revocation state (suspended / disabled / expired / chargebacked /
		// blacklisted / missing manifest), we must behave as if the Pro plugin is
		// NOT installed — Move / Attention / Scroll tabs get the PRO badge +
		// disabled styling, identical to a Free-only install. The previous check
		// (`defined( 'OPTI_BEHAVIOR_PRO_VERSION' )`) was license-blind because the
		// constant is defined as soon as the Pro plugin file loads, regardless of
		// license validity.
		$is_pro = function_exists( 'opti_behavior_pro_validate_env' ) && opti_behavior_pro_validate_env();

		$types = array(
			'click'  => array(
				'label' => __( 'Click Heatmap', 'opti-behavior' ),
				'icon'  => 'mouse-pointer-click',
				'pro'   => false,
				'title' => __( 'Shows where visitors click on your page. Hot spots (red/orange) indicate popular areas, cool spots (blue/green) show less clicked areas.', 'opti-behavior' ),
			),
			'move'   => array(
				'label' => __( 'Move Heatmap', 'opti-behavior' ),
				'icon'  => 'move',
				'pro'   => true,
				'title' => __( 'Tracks mouse movement patterns. Mouse movement often follows eye movement, helping you understand what visitors look at.', 'opti-behavior' ),
			),
			'attention' => array(
				'label' => __( 'Attention Heatmap', 'opti-behavior' ),
				'icon'  => 'eye',
				'pro'   => true,
				'title' => __( 'Combines scroll depth and time spent to show which parts of your page get the most attention from visitors.', 'opti-behavior' ),
			),
			'scroll' => array(
				'label' => __( 'Scroll Heatmap', 'opti-behavior' ),
				'icon'  => 'mouse',
				'pro'   => true,
				'title' => __( 'Shows how far down the page visitors scroll. Use this to ensure important content is placed where most visitors will see it.', 'opti-behavior' ),
			),
		);
		?>
		<div class="opti-device-stats-bar">
			<div class="device-type-controls">
				<div class="device-switcher">
					<button type="button" class="device-btn <?php echo 'desktop' === $current_device ? 'active' : ''; ?>" data-device="desktop" title="<?php esc_attr_e( 'Desktop', 'opti-behavior' ); ?>">
						<i data-lucide="monitor"></i>
						<span class="device-count" data-device="desktop">0</span>
					</button>
					<button type="button" class="device-btn <?php echo 'mobile' === $current_device ? 'active' : ''; ?>" data-device="mobile" title="<?php esc_attr_e( 'Mobile', 'opti-behavior' ); ?>">
						<i data-lucide="smartphone"></i>
						<span class="device-count" data-device="mobile">0</span>
					</button>
					<button type="button" class="device-btn <?php echo 'tablet' === $current_device ? 'active' : ''; ?>" data-device="tablet" title="<?php esc_attr_e( 'Tablet', 'opti-behavior' ); ?>">
						<i data-lucide="tablet-smartphone"></i>
						<span class="device-count" data-device="tablet">0</span>
					</button>
				</div>
				<div class="opti-heatmap-tabs">
					<?php foreach ( $types as $type => $config ) : ?>
						<?php
						$is_pro_feature = $config['pro'];
						$disabled = $is_pro_feature && ! $is_pro;
						$classes = array( 'heatmap-tab' );
						if ( $type === $current_type ) {
							$classes[] = 'active';
						}
						if ( $disabled ) {
							$classes[] = 'disabled pro-only';
						}
						$tooltip_key = 'type_' . $type;
						?>
						<button type="button" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
							<i data-lucide="<?php echo esc_attr( $config['icon'] ); ?>"></i>
							<?php echo esc_html( $config['label'] ); ?>
							<?php if ( $is_pro_feature && ! $is_pro ) : ?>
								<span class="pro-badge">PRO</span>
								<span class="pro-tooltip"><?php echo esc_html( $config['title'] ); ?> <a href="https://optiuser.com/" target="_blank"><?php esc_html_e( 'Upgrade to PRO', 'opti-behavior' ); ?> &rarr;</a></span>
							<?php endif; ?>
							<?php if ( ! $disabled && ! empty( $heatmap_tooltips[ $tooltip_key ] ) ) { opti_behavior_tooltip_e( $heatmap_tooltips[ $tooltip_key ]['title'], $heatmap_tooltips[ $tooltip_key ]['content'], $heatmap_tooltips[ $tooltip_key ]['simple'], '', array( 'class' => 'ob-tooltip-xs' ) ); } ?>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="quick-stats">
				<div class="stat-item">
					<i data-lucide="eye" class="stat-icon"></i>
					<span class="stat-label"><?php esc_html_e( 'Views', 'opti-behavior' ); ?>:</span>
					<span class="stat-value" id="stat-views">-</span>
				</div>
				<div class="stat-item stat-clicks-item">
					<i data-lucide="mouse-pointer-click" class="stat-icon"></i>
					<span class="stat-label"><?php esc_html_e( 'Clicks', 'opti-behavior' ); ?>:</span>
					<span class="stat-value" id="stat-clicks">-</span>
				</div>
				<div class="stat-item stat-move-item" style="display: none;">
					<i data-lucide="move" class="stat-icon"></i>
					<span class="stat-label"><?php esc_html_e( 'Moves', 'opti-behavior' ); ?>:</span>
					<span class="stat-value" id="stat-moves">-</span>
				</div>
				<div class="stat-item stat-scroll-item" style="display: none;">
					<i data-lucide="mouse" class="stat-icon"></i>
					<span class="stat-label"><?php esc_html_e( 'Scrolls', 'opti-behavior' ); ?>:</span>
					<span class="stat-value" id="stat-scrolls">-</span>
				</div>
				<div class="stat-item">
					<i data-lucide="clock" class="stat-icon"></i>
					<span class="stat-label"><?php esc_html_e( 'Avg Time', 'opti-behavior' ); ?>:</span>
					<span class="stat-value" id="stat-avg-time">-</span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render heatmap type tabs.
	 *
	 * @param string $current_type Current heatmap type.
	 */
	private function render_type_tabs( $current_type ) {
		// Bug 6 fix: license-aware Pro check (see render_device_stats_bar comment).
		// `opti_behavior_pro_active()` is hardcoded `true` whenever the Pro plugin
		// file is loaded — license-blind. Use validate_env() instead.
		$is_pro = function_exists( 'opti_behavior_pro_validate_env' ) && opti_behavior_pro_validate_env();

		$types = array(
			'click'  => array(
				'label' => __( 'Click Heatmap', 'opti-behavior' ),
				'pro'   => false,
			),
			'move'   => array(
				'label' => __( 'Move Heatmap', 'opti-behavior' ),
				'pro'   => true,
			),
			'scroll' => array(
				'label' => __( 'Scroll Heatmap', 'opti-behavior' ),
				'pro'   => true,
			),
		);
		?>
		<div class="opti-heatmap-tabs">
			<?php foreach ( $types as $type => $config ) : ?>
				<?php
				$disabled = $config['pro'] && ! $is_pro;
				$classes = array( 'heatmap-tab' );
				if ( $type === $current_type ) {
					$classes[] = 'active';
				}
				if ( $disabled ) {
					$classes[] = 'disabled pro-only';
				}
				?>
				<button
					type="button"
					class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
					data-type="<?php echo esc_attr( $type ); ?>"
					<?php echo $disabled ? 'disabled' : ''; ?>
					<?php echo $disabled ? 'title="' . esc_attr__( 'Available in Pro version', 'opti-behavior' ) . '"' : ''; ?>
				>
					<?php echo esc_html( $config['label'] ); ?>
					<?php if ( $config['pro'] && ! $is_pro ) : ?>
						<span class="pro-badge">PRO</span>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render heatmap container with loading state.
	 *
	 * @param string $current_type Current heatmap type.
	 */
	private function render_heatmap_container( $current_type ) {
		// Bug 6 fix: license-aware Pro check (see render_device_stats_bar comment).
		$is_pro = function_exists( 'opti_behavior_pro_validate_env' ) && opti_behavior_pro_validate_env();
		$is_pro_feature = in_array( $current_type, array( 'move', 'scroll' ), true );
		$show_pro_message = $is_pro_feature && ! $is_pro;
		$wrapper_classes = array( 'heatmap-canvas-wrapper' );

		if ( ! $show_pro_message ) {
			$wrapper_classes[] = 'is-pending';
		}

		$wrapper_aria_busy = $show_pro_message ? 'false' : 'true';
		?>
		<div class="opti-heatmap-main-container">
			<div
				class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>"
				aria-busy="<?php echo esc_attr( $wrapper_aria_busy ); ?>"
			>
				<?php if ( $show_pro_message ) : ?>
					<div class="heatmap-pro-feature-notice">
						<i data-lucide="lock"></i>
						<h3><?php esc_html_e( 'Pro Feature', 'opti-behavior' ); ?></h3>
						<p>
							<?php
							printf(
								/* translators: %s: feature name */
								esc_html__( '%s is available in the Pro version. Upgrade to unlock advanced heatmap features.', 'opti-behavior' ),
								'<strong>' . esc_html( ucfirst( $current_type ) . ' Heatmap' ) . '</strong>'
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<div class="heatmap-loading opti-heatmap-pending-overlay" role="status" aria-live="polite">
						<div class="loading-spinner"></div>
						<p><?php esc_html_e( 'Loading heatmap data...', 'opti-behavior' ); ?></p>
					</div>
					<div
						id="heatmap-container"
						class="heatmap-container"
						data-type="<?php echo esc_attr( $current_type ); ?>"
						aria-hidden="true"
					>
						<!-- Heatmap will be rendered here by JavaScript -->
					</div>
					<div class="heatmap-no-data" style="display: none;">
						<i data-lucide="circle-alert"></i>
						<p><?php esc_html_e( 'No heatmap data available for this page and device combination.', 'opti-behavior' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render statistics panel.
	 *
	 * @param array $heatmap_tooltips Tooltips array.
	 */
	private function render_stats_panel( $heatmap_tooltips = array() ) {
		?>
		<div class="opti-stats-panel">
			<div class="stats-panel-inner">
				<div class="stats-header">
					<div class="stats-title-group">
						<div class="stats-title">
							<i data-lucide="sparkles" class="ai-icon"></i>
							<h2><?php esc_html_e( 'AI Insights', 'opti-behavior' ); ?></h2>
							<span class="stats-badge"><?php esc_html_e( 'Beta', 'opti-behavior' ); ?></span>
							<?php if ( ! empty( $heatmap_tooltips['ai_insights'] ) ) { opti_behavior_tooltip_e( $heatmap_tooltips['ai_insights']['title'], $heatmap_tooltips['ai_insights']['content'], $heatmap_tooltips['ai_insights']['simple'], '', array( 'position' => 'left' ) ); } ?>
						</div>
						<p class="stats-subtitle"><?php esc_html_e( 'Top Clicked Elements', 'opti-behavior' ); ?></p>
					</div>
					<div class="view-toggle">
						<button type="button" class="view-btn active" data-view="simple">
							<?php esc_html_e( 'Simple', 'opti-behavior' ); ?>
						</button>
						<button type="button" class="view-btn" data-view="detailed">
							<?php esc_html_e( 'Detailed', 'opti-behavior' ); ?>
						</button>
					</div>
				</div>
				<div class="stats-content">
					<div class="stats-loading" style="display: none;">
						<div class="loading-spinner"></div>
						<p><?php esc_html_e( 'Loading statistics...', 'opti-behavior' ); ?></p>
					</div>
					<div id="top-elements-list" class="top-elements-list">
						<div class="top-elements-empty">
							<i data-lucide="mouse-pointer-click" class="top-elements-empty-icon"></i>
							<p class="top-elements-empty-title"><?php esc_html_e( 'No element data yet', 'opti-behavior' ); ?></p>
							<p class="top-elements-empty-hint"><?php esc_html_e( 'Element details are collected from new visits — check back soon.', 'opti-behavior' ); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render error message.
	 *
	 * @param string $message Error message.
	 */
	private function render_error( $message ) {
		// The `opti-behavior-notice` class is required so the plugin's own
		// notice-cleanup.js does not strip this error before the user sees it
		// (the cleanup script preserves elements whose class contains
		// 'opti-behavior' or starts with 'ob-'). An <h1> is also emitted so WP
		// admin-common.js relocates the notice beside a page heading instead
		// of leaving it orphaned.
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Heatmap Detail', 'opti-behavior' ); ?></h1>
			<hr class="wp-header-end">
			<div class="notice notice-error opti-behavior-notice">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-heatmaps' ) ); ?>" class="button">
					<?php esc_html_e( 'Back to Heatmaps', 'opti-behavior' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Get page data from database.
	 *
	 * @param int $page_id Page ID.
	 * @return array|false Page data or false if not found.
	 */
	private function get_page_data( $page_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_pages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$page = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, url, title FROM {$table} WHERE id = %d",
				$page_id
			),
			ARRAY_A
		);

		return $page ? $page : false;
	}

	/**
	 * Get A/B tests that target a specific URL.
	 *
	 * Includes running and completed tests (completed tests still have heatmap data).
	 * Excludes draft tests which have never collected data.
	 *
	 * @param string $url The page URL.
	 * @return array Array of test objects.
	 */
	private function get_ab_tests_for_url( $url ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_tests';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . $table . " WHERE target_url = %s AND status IN ('running', 'completed', 'paused') ORDER BY id ASC",
				$url
			)
		);
	}
}

// Note: Do NOT auto-instantiate here.
// The class is instantiated explicitly by Opti_Behavior_Heatmap_Core (line 323)
// or by OptiBehavior_Pro_Core (line 103) depending on which plugin is active.
// Auto-instantiation here would create a DUPLICATE instance causing the heatmap
// to render twice on the detail page.
