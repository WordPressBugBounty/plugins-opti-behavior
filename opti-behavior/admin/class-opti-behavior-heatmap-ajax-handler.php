<?php
/**
 * AJAX Handler Class
 *
 * Handles all AJAX requests for the plugin.
 *
 * @package opti-behavior
 * @copyright 2025 OptiUser
 * @version 1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * AJAX Handler Class
 *
 * Manages all AJAX endpoints for heatmap data collection, session recording,
 * and admin operations.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Heatmap_Ajax_Handler {

	// A/B Testing AJAX endpoints.
	use Opti_Behavior_AB_Tests_Ajax;

	/**
	 * Core instance.
	 *
	 * @since 1.0.0
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;


	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		$this->init_hooks();
	}


	/**
	 * Resolve the WordPress user ID for the current heatmap / session-tracking request.
	 *
	 * The JS `is_logged_in` flag is the primary authority for the L/G (Logged-in /
	 * Guest) classification. It is set at PHP page-render time when WordPress auth
	 * is fully available and is the most reliable signal — `is_user_logged_in()`
	 * inside admin-ajax.php can be unreliable because the browser `fetch()` call
	 * may not always send auth cookies, and it can flap between requests for the
	 * same visitor.
	 *
	 * Keeping the DB `user_id` column in lock-step with the JS flag ensures the
	 * UI "Logged-in Users" / "Guest Visitors" / "All Visitors" segments produce
	 * consistent counts regardless of whether the filter implementation reads
	 * the file-system L/G marker or the DB `user_id` column.
	 *
	 * @since 1.0.5
	 *
	 * @return int|null WordPress user ID when the visitor is logged in and can be
	 *                  identified; null when the visitor is a guest (or the auth
	 *                  context cannot resolve an ID even though the JS flag says
	 *                  logged-in — treated conservatively as guest for DB).
	 */
	private function resolve_request_user_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies nonce upstream.
		$js_logged_in = isset( $_POST['is_logged_in'] ) ? absint( wp_unslash( $_POST['is_logged_in'] ) ) : 0;

		// JS says guest — honour it even if is_user_logged_in() is stale.
		// This is the "logout-between-render-and-AJAX" case: JS saw the user
		// log out and sends is_logged_in=0, so we must not record a user ID.
		if ( $js_logged_in === 0 ) {
			return null;
		}

		// JS says logged-in. Prefer the WP-API user ID.
		$wp_user_id = get_current_user_id();
		if ( $wp_user_id > 0 ) {
			return $wp_user_id;
		}

		// Secondary check — sometimes get_current_user_id() returns 0 even though
		// the session cookie is valid; is_user_logged_in() is a safer predicate.
		if ( is_user_logged_in() ) {
			$wp_user_id = get_current_user_id();
			if ( $wp_user_id > 0 ) {
				return $wp_user_id;
			}
		}

		// JS says logged-in but server auth cannot identify the user (race or
		// cookie issue). File marker will still be `_L_` from the JS flag; the
		// DB row stays NULL — best effort until we add a dedicated boolean column.
		return null;
	}


	/**
	 * Initialize AJAX hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'wp_ajax_opti_behavior_heatmap', array( $this, 'ajax_opti_behavior_heatmap' ) );
		add_action( 'wp_ajax_nopriv_opti_behavior_heatmap', array( $this, 'ajax_opti_behavior_heatmap' ) );

		add_action( 'wp_ajax_opti_behavior_heatmap_record', array( $this, 'ajax_session_record' ) );
		add_action( 'wp_ajax_nopriv_opti_behavior_heatmap_record', array( $this, 'ajax_session_record' ) );

		add_action( 'wp_ajax_opti_behavior_heatmap_geo', array( $this, 'ajax_geo' ) );
		add_action( 'wp_ajax_nopriv_opti_behavior_heatmap_geo', array( $this, 'ajax_geo' ) );

		add_action( 'wp_ajax_optibehavior_top_users', array( $this, 'ajax_top_users' ) );
		add_action( 'wp_ajax_optibehavior_backfill_pageview_times', array( $this, 'ajax_backfill_pageview_times' ) );

		// Heartbeat handler for session duration and scroll depth updates
		add_action( 'wp_ajax_opti_behavior_heatmap_heartbeat', array( $this, 'ajax_heartbeat' ) );
		add_action( 'wp_ajax_nopriv_opti_behavior_heatmap_heartbeat', array( $this, 'ajax_heartbeat' ) );

		// Support form email handler
		add_action( 'wp_ajax_opti_behavior_send_support_email', array( $this, 'ajax_send_support_email' ) );

		// Country data cleanup handler
		add_action( 'wp_ajax_opti_behavior_reset_country_data', array( $this, 'ajax_reset_country_data' ) );

		// Database optimization handler
		add_action( 'wp_ajax_opti_behavior_run_db_optimization', array( $this, 'ajax_run_db_optimization' ) );

		// Country backfill handler — retries country detection for visitors with NULL country
		add_action( 'wp_ajax_opti_behavior_backfill_countries', array( $this, 'ajax_backfill_countries' ) );
	}

	/**
	 * Main AJAX handler for heatmap data collection.
	 *
	 * Processes heatmap events sent from the frontend.
	 *
	 * @since 1.0.0
	 */
	public function ajax_opti_behavior_heatmap() {
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'AJAX request received: opti_behavior_heatmap', 'debug', 'ajax' );

		// Verify nonce for security
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'opti_behavior_heatmap_nonce' ) ) {
			$debug_manager->log( 'AJAX request failed: invalid nonce', 'warning', 'ajax' );
			// Return HTTP 403 (not the default 200) so the reporter JS detects the
			// expired nonce and triggers refreshNonceAndRequeue(), re-sending the
			// events with a fresh nonce. On full-page-cached pages (e.g. the posts
			// index / front page) the localized nonce can outlive its lifetime,
			// which previously caused those events to be silently dropped.
			wp_send_json_error( __( 'Invalid nonce', 'opti-behavior' ), 403 );
		}

		// Ingest gate: silently ignore events from bot user agents / excluded IPs.
		// Trackers now always render on (page-cached) pages, so this is the
		// authoritative per-visitor filter. Respond 200-success so the reporter
		// JS does not enter the 403 nonce-refresh retry loop.
		if ( Opti_Behavior_Ingest_Gate::should_reject( 'heatmap_events' ) ) {
			wp_send_json_success( array( 'status' => 'ignored' ) );
		}

		// Don't track admin users unless track_admin_users setting is enabled (or in test mode)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET/COOKIE check for test mode (permission check follows)
		$is_test_mode = isset( $_GET['opti_behavior_test_tracking'] ) || isset( $_COOKIE['opti_behavior_test_tracking'] ) || isset( $_GET['opti_behavior_test_recording'] ) || isset( $_COOKIE['opti_behavior_test_recording'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( current_user_can( 'manage_options' ) && ! $is_test_mode ) {
			$traffic_settings = get_option( 'opti_behavior_traffic_settings', array() );
			$track_admin      = isset( $traffic_settings['track_admin_users'] ) ? (bool) $traffic_settings['track_admin_users'] : true;
			if ( ! $track_admin ) {
				$debug_manager->log( 'Session record skipped: Admin user tracking disabled in settings', 'info', 'ajax' );
				wp_send_json_success( array( 'message' => 'Admin tracking disabled' ) );
				return;
			}
		}

		// Handle FormData format (data is sent as data[0][event], data[0][x], etc.)
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array sanitized via map_deep below
		$data_array = isset( $_POST['data'] ) ? map_deep( wp_unslash( $_POST['data'] ), 'sanitize_text_field' ) : array();

		if ( empty( $data_array ) || ! is_array( $data_array ) ) {
			$debug_manager->log( 'AJAX request failed: invalid or empty data array', 'warning', 'ajax' );
			wp_send_json_error( __( 'Invalid data', 'opti-behavior' ) );
		}

		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		$debug_manager->log( 'Processing heatmap data for URL: ' . $url . ' (' . count( $data_array ) . ' events)', 'info', 'ajax' );

		$session_id_from_js = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : null;
		$visitor_id_from_js = isset( $_POST['visitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_id'] ) ) : null;

		// Extract screen resolution from POST for visitor tracking
		$screen_width = isset( $_POST['screen_width'] ) ? intval( $_POST['screen_width'] ) : null;
		$screen_height = isset( $_POST['screen_height'] ) ? intval( $_POST['screen_height'] ) : null;

		// Extract referrer and UTM parameters from POST
		$referrer = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';
		$utm_source = isset( $_POST['utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_source'] ) ) : '';
		$utm_medium = isset( $_POST['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_medium'] ) ) : '';
		$utm_campaign = isset( $_POST['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ) ) : '';
		$utm_term = isset( $_POST['utm_term'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_term'] ) ) : '';
		$utm_content = isset( $_POST['utm_content'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_content'] ) ) : '';

		$processed_count  = 0;
		$touched_sessions = array();
		foreach ( $data_array as $event_data ) {
			if ( ! is_array( $event_data ) ) {
				continue;
			}

			$event_data['url']   = $url;
			$event_data['title'] = $title;

			if ( isset( $event_data['event'] ) ) {
				$resolved_session_id = $this->handle_heatmap_event( $event_data, $session_id_from_js, $visitor_id_from_js, $screen_width, $screen_height, $referrer, $utm_source, $utm_medium, $utm_campaign, $utm_term, $utm_content );
				if ( ! empty( $resolved_session_id ) ) {
					$touched_sessions[ $resolved_session_id ] = true;
				}
				$processed_count++;
			}
		}

		if ( $processed_count > 0 ) {
			$this->clear_behavior_classification_caches();

			// Deterministic free-side re-classification once the engagement events are
			// persisted. Pro's recording-save re-classifier never runs when Pro is
			// inactive (a normal deployment), so Free must self-correct here. Passing
			// allow_spam_verdict = false means this only ever eagerly downgrades a
			// transiently-flagged session back to human when its clicks/scrolls have
			// now landed; it never introduces a spam verdict mid-session. It also
			// recovers a session whose end-beacon finalized spam before the click
			// batch flushed (the permanent-stick case).
			if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
				foreach ( array_keys( $touched_sessions ) as $touched_session_id ) {
					Opti_Behavior_Stats_Spam_Filter::classify_session(
						$touched_session_id,
						array( 'allow_spam_verdict' => false )
					);
				}
			}
		}

		$debug_manager->log( 'Successfully processed ' . $processed_count . ' heatmap events', 'info', 'ajax' );

		wp_send_json_success( array( 'status' => 'recorded' ) );
	}

	/**
	 * Handle individual heatmap event from FormData.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array       $event_data          Event data.
	 * @param string|null $session_id_from_js  Optional session ID from JavaScript.
	 * @param string|null $visitor_id_from_js  Optional visitor ID from JavaScript.
	 * @param int|null    $screen_width        Optional screen width.
	 * @param int|null    $screen_height       Optional screen height.
	 * @param string      $referrer            Referrer URL.
	 * @param string      $utm_source          UTM source parameter.
	 * @param string      $utm_medium          UTM medium parameter.
	 * @param string      $utm_campaign        UTM campaign parameter.
	 * @param string      $utm_term            UTM term parameter.
	 * @param string      $utm_content         UTM content parameter.
	 */
	private function handle_heatmap_event( $event_data, $session_id_from_js = null, $visitor_id_from_js = null, $screen_width = null, $screen_height = null, $referrer = '', $utm_source = '', $utm_medium = '', $utm_campaign = '', $utm_term = '', $utm_content = '' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'optibehavior_events';

		// DEBUG: Log incoming event data
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( "handle_heatmap_event called with event_data: " . json_encode($event_data), 'debug', 'ajax' );

		$event_type = isset( $event_data['event'] ) ? sanitize_text_field( $event_data['event'] ) : '';
		$url        = isset( $event_data['url'] ) ? esc_url_raw( $event_data['url'] ) : '';
		$title      = isset( $event_data['title'] ) ? sanitize_text_field( $event_data['title'] ) : '';
		$x          = isset( $event_data['x'] ) ? intval( $event_data['x'] ) : 0;
		$y          = isset( $event_data['y'] ) ? intval( $event_data['y'] ) : 0;
		$width      = isset( $event_data['width'] ) ? intval( $event_data['width'] ) : 0;
		$height        = isset( $event_data['height'] ) ? intval( $event_data['height'] ) : 0;
		$window_height = isset( $event_data['windowHeight'] ) ? intval( $event_data['windowHeight'] ) : 0;
		$time          = isset( $event_data['time'] ) ? intval( $event_data['time'] ) : 0;
		$device        = isset( $event_data['device'] ) ? sanitize_text_field( $event_data['device'] ) : 'pc';

		$analytics = $this->core->get_analytics();
		$page_id   = $analytics->get_or_create_page_id( $url, $title );

		$is_mobile      = ( $device === 'mobile' || $device === 'sp' );
		$event_type_map = array(
			'click_pc'         => 16,
			'click_mobile'     => 17,
			'breakaway_pc'     => 32,
			'breakaway_mobile' => 33,
			'attention_pc'     => 48,
			'attention_mobile' => 49,
		);

		$event_key     = $event_type . '_' . ( $is_mobile ? 'mobile' : 'pc' );
		$event_numeric = isset( $event_type_map[ $event_key ] ) ? $event_type_map[ $event_key ] : ( $is_mobile ? 17 : 16 );

		// DEBUG: Log event processing details
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( "Processing event - type=$event_type, device=$device, is_mobile=" . ( $is_mobile ? 'YES' : 'NO' ) . ", event_key=$event_key, event_numeric=$event_numeric, page_id=$page_id", 'debug', 'ajax' );

		$session_id = $session_id_from_js ? $session_id_from_js : $this->get_or_create_session_id();
		$visitor_id = $visitor_id_from_js ? $visitor_id_from_js : $this->get_or_create_visitor_id();

		// In "effectively anonymous" mode (anonymous PHP mode, OR full mode without consent —
		// JS front-end runs in anonymous mode), prefer the client-supplied visitor_id from
		// the POST body when present. Anonymous Mode is cookieless: the tracker sources its
		// visitor_id from optiBehaviorHeatmapConfig.anon_vid (the server daily hash) and
		// carries it in the AJAX body, so the client value already matches the server-side
		// value. Cross-tab dedup holds because every tab derives the same server hash.
		// Bug #R4 — fixes "Total Visitors stuck" by not overriding a client-supplied
		// anon_ id. Server falls back to the daily hash (sha256 of IP + UA + site_url +
		// daily_salt) only when the client supplied nothing (curl, ancient browser).
		$is_effectively_anon = ! is_user_logged_in() && (
			'anonymous' === $this->get_privacy_mode()
			|| ( 'full' === $this->get_privacy_mode() && ! $this->visitor_has_consent() )
		);
		if ( $is_effectively_anon ) {
			$visitor_id = $this->resolve_anon_visitor_id( $visitor_id );
		}

		// Multi-tab session deduplication: use the earliest active session for this
		// visitor (within 30 min) as the canonical one, so heatmap events from a
		// second tab are recorded under the same session.
		if ( ! empty( $visitor_id ) && ! empty( $session_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$canonical_sid = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}optibehavior_sessions
				 WHERE visitor_id = %s
				 AND start_time >= DATE_SUB( NOW(), INTERVAL 30 MINUTE )
				 ORDER BY start_time ASC, id ASC
				 LIMIT 1",
				$visitor_id
			) );
			if ( ! empty( $canonical_sid ) && $canonical_sid !== $session_id ) {
				$session_id = $canonical_sid;
			}
		}

		$this->ensure_session_exists( $session_id, $visitor_id, $url, $title, $screen_width, $screen_height, $referrer, $utm_source, $utm_medium, $utm_campaign, $utm_term, $utm_content );
		// Heatmap click/scroll/move batches are engagement telemetry, NOT navigations.
		// Passing is_navigation_event=false means an existing pageview row for this
		// session+page is touched instead of inserting a new one; real navigations
		// create their rows via the session-start / page_load handlers. Previously
		// this used the navigation default, so an event batch flushed more than 5s
		// after the initial pageview (the tracker flush interval is longer than the
		// 5s navigation dedup window) inserted a duplicate pageview row — a single
		// one-page visit with one scroll was reported as 2 Page Views. The INSERT
		// fallback inside ensure_pageview_exists() still covers a missed navigation
		// event (no row at all yet for this session+page).
		$this->ensure_pageview_exists( $session_id, $visitor_id, $page_id, $url, $title, false );

		// Always use database for raw event storage (for analytics/session replay)
		// DEBUG: Log database insert attempt
		$debug_manager->log( "Attempting DATABASE insert - event=$event_numeric, device=$device, page_id=$page_id", 'debug', 'ajax' );

		$event_row = array(
			'page_id'    => $page_id,
			'page_id2'   => $page_id,
			'session_id' => $session_id,
			'event'      => $event_numeric,
			'x'          => $x,
			'y'          => $y,
			'width'      => $width,
			'height'     => $height,
			'insert_at'  => current_time( 'mysql' ),
		);
		$event_row_formats = array( '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s' );

		// Element identification (Top Clicked Elements): persisted for click
		// events only (16/17) so move/scroll rows stay lean. Fields arrive
		// pre-sanitized by map_deep(sanitize_text_field) in the AJAX entry
		// point; sanitized again here with length caps mirroring column sizes.
		if ( ( 16 === $event_numeric || 17 === $event_numeric ) && ! empty( $event_data['element_selector'] ) ) {
			$element_fields = array(
				'element_tag'      => 50,
				'element_id'       => 100,
				'element_class'    => 200,
				'element_text'     => 150,
				'element_type'     => 40,
				'element_builder'  => 50,
				'element_selector' => 191,
				'element_href'     => 191,
			);
			foreach ( $element_fields as $element_field => $element_max_len ) {
				if ( isset( $event_data[ $element_field ] ) && '' !== $event_data[ $element_field ] ) {
					$event_row[ $element_field ] = mb_substr( sanitize_text_field( $event_data[ $element_field ] ), 0, $element_max_len );
					$event_row_formats[]         = '%s';
				}
			}
		}

		// Element anchor (element-anchored heatmap reprojection): XPath +
		// click offset relative to the element box. Clicks only (16/17),
		// purely additive — the absolute x/y above stay authoritative and
		// legacy rows simply have NULL anchors (rendered via absolute path).
		$anchor_fields = array();
		if ( ( 16 === $event_numeric || 17 === $event_numeric )
			&& ! empty( $event_data['element_xpath'] )
			&& isset( $event_data['element_rel_x'] ) && is_numeric( $event_data['element_rel_x'] )
			&& isset( $event_data['element_rel_y'] ) && is_numeric( $event_data['element_rel_y'] )
		) {
			$anchor_rel_x = min( 1, max( 0, (float) $event_data['element_rel_x'] ) );
			$anchor_rel_y = min( 1, max( 0, (float) $event_data['element_rel_y'] ) );

			$anchor_fields = array(
				'element_xpath' => true,
				'element_rel_x' => true,
				'element_rel_y' => true,
			);

			$event_row['element_xpath'] = mb_substr( sanitize_text_field( $event_data['element_xpath'] ), 0, 512 );
			$event_row_formats[]        = '%s';
			$event_row['element_rel_x'] = $anchor_rel_x;
			$event_row_formats[]        = '%f';
			$event_row['element_rel_y'] = $anchor_rel_y;
			$event_row_formats[]        = '%f';
		}

		$insert_result = $wpdb->insert(
			$table_name,
			$event_row,
			$event_row_formats
		);

		// Self-healing fallback: if the insert failed and element/anchor columns
		// were included, the events table may pre-date that schema (dbDelta
		// only runs on activation/upgrade). Rather than silently losing the
		// click, retry without the element_* / anchor fields so the heatmap dot
		// is still recorded; element data resumes once the schema is upgraded.
		$optional_event_fields = ( isset( $element_fields ) ? $element_fields : array() ) + $anchor_fields;
		if ( false === $insert_result && $optional_event_fields && array_intersect_key( $event_row, $optional_event_fields ) ) {
			$debug_manager->log( 'Event insert failed with element fields (' . $wpdb->last_error . ') - retrying without element data. Deactivate/reactivate the plugin to upgrade the events table schema.', 'error', 'ajax' );
			$base_field_count  = 9; // page_id, event, device, x, y, width, height, time, insert_at.
			$event_row         = array_diff_key( $event_row, $optional_event_fields );
			$event_row_formats = array_slice( $event_row_formats, 0, $base_field_count );

			$insert_result = $wpdb->insert(
				$table_name,
				$event_row,
				$event_row_formats
			);
		}

		// DEBUG: Log insert result
		if ( $insert_result === false ) {
			$debug_manager->log( "Database insert FAILED! Error: " . $wpdb->last_error . ", event=$event_numeric, device=$device", 'error', 'ajax' );
		} else {
			$inserted_id = $wpdb->insert_id;
			$debug_manager->log( "Event inserted with ID=$inserted_id (event=$event_numeric, device=$device)", 'info', 'ajax' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}optibehavior_sessions SET events_count = events_count + 1 WHERE id = %s",
				$session_id
			)
		);

		// Keep the per-page click counter (session_pages.clicks_count) in sync with the
		// heatmap tracker. That counter is normally written by the session-recording
		// beacon (rrweb), which can miss clicks the heatmap tracker captured, leaving a
		// genuinely-clicked page reading 0 and getting hidden by the spam allow-list.
		if ( 16 === $event_numeric || 17 === $event_numeric ) {
			// A click is engagement — the session is not a bounce. Mirrors the
			// batch-events handler; without this, a single-page engaged session
			// whose clicks arrive via this heatmap event endpoint stayed
			// flagged as a bounce forever.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$wpdb->update(
				"{$wpdb->prefix}optibehavior_sessions",
				array( 'is_bounce' => 0 ),
				array( 'id' => $session_id ),
				array( '%d' ),
				array( '%s' )
			);
			$debug_manager->log( 'Bounce status updated to 0 due to heatmap click engagement for session: ' . $session_id, 'debug', 'ajax' );
			$this->reconcile_session_page_click_count( $session_id, $page_id );
		}

		// Save to optimized heatmap storage (for fast heatmap retrieval).
		// Element anchor (when captured) rides along so the file fast path can
		// serve it back for element-anchored click reprojection.
		$storage_anchor = null;
		if ( $anchor_fields && isset( $event_row['element_xpath'] ) ) {
			$storage_anchor = array(
				'xp' => $event_row['element_xpath'],
				'rx' => $event_row['element_rel_x'],
				'ry' => $event_row['element_rel_y'],
			);
		}
		$this->save_to_heatmap_storage( $url, $session_id, $visitor_id, $event_type, $x, $y, $width, $height, $is_mobile, $page_id, $window_height, $storage_anchor );

		// Return the resolved (multi-tab canonical) session id so the batch handler
		// can re-classify exactly the sessions that just received engagement events.
		return $session_id;
	}

	/**
	 * Reconcile session_pages.clicks_count with the heatmap click events for a page.
	 *
	 * The click counter is authored by the session-recording beacon (rrweb), but the
	 * heatmap tracker records clicks independently and can capture clicks rrweb missed,
	 * leaving a legitimately-clicked page with clicks_count = 0. A 0 counter fails the
	 * spam allow-list (SUM(clicks_count) >= threshold) and the page vanishes from the
	 * Heatmaps table even though real clicks exist.
	 *
	 * This uses a monotonic MAX reconciliation, NOT an additive increment: it recomputes
	 * the authoritative heatmap click COUNT for the (session, page) pair and raises
	 * clicks_count to that value only when the stored counter currently under-reports it.
	 * Consequences:
	 *   - Pages where the beacon already counted the clicks store a value >= the heatmap
	 *     count, so the WHERE guard makes this a no-op — those rows stay numerically
	 *     identical (no double counting, no inflation).
	 *   - Heatmap-only / under-counted pages get corrected up to the real click count.
	 * Because we SET a recomputed COUNT (never clicks_count + 1) the same physical click
	 * can never be counted twice.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param int    $page_id    Page ID.
	 * @return void
	 */
	private function reconcile_session_page_click_count( $session_id, $page_id ) {
		global $wpdb;

		$session_id = (string) $session_id;
		$page_id    = absint( $page_id );
		if ( '' === $session_id || 0 === $page_id ) {
			return;
		}

		$events_table        = $wpdb->prefix . 'optibehavior_events';
		$session_pages_table = $wpdb->prefix . 'optibehavior_session_pages';

		// Authoritative heatmap click count for this (session, page): desktop + mobile clicks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$heatmap_clicks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events_table} WHERE session_id = %s AND page_id = %d AND event IN (16, 17)",
				$session_id,
				$page_id
			)
		);

		if ( $heatmap_clicks <= 0 ) {
			return;
		}

		// Monotonic MAX reconciliation guarded by COALESCE(clicks_count, 0) < heatmap count:
		// never lowers a value, never adds to a beacon-owned value that already meets/exceeds it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$session_pages_table}
				SET clicks_count = %d
				WHERE session_id = %s AND page_id = %d AND COALESCE(clicks_count, 0) < %d",
				$heatmap_clicks,
				$session_id,
				$page_id,
				$heatmap_clicks
			)
		);
	}

	/**
	 * Save event to optimized heatmap storage.
	 *
	 * This saves event data to the file-based storage system for fast heatmap retrieval.
	 * Data is organized by URL hash and stored with metadata in filenames.
	 * Supports all event types: clicks, moves (attention), and scrolls (breakaway).
	 *
	 * @since 1.0.0
	 * @param string $url        Page URL.
	 * @param string $session_id Session ID.
	 * @param string $visitor_id Visitor ID.
	 * @param string $event_type Event type (click, breakaway, attention).
	 * @param int    $x          X coordinate.
	 * @param int    $y          Y coordinate.
	 * @param int    $width      Viewport width.
	 * @param int    $height     Viewport height.
	 * @param bool   $is_mobile  Whether device is mobile.
	 * @param int    $page_id    Page ID.
	 */
	private function save_to_heatmap_storage( $url, $session_id, $visitor_id, $event_type, $x, $y, $width, $height, $is_mobile, $page_id, $window_height = 0, $anchor = null ) {
		$heatmap_storage = $this->core->get_heatmap_storage();
		if ( ! $heatmap_storage ) {
			return;
		}

		$debug_manager = $this->core->get_debug_manager();

		// Map event types to storage folder names
		$type_map = array(
			'click'      => 'clicks',
			'attention'  => 'moves',
			'breakaway'  => 'scrolls',
		);

		// Skip if event type is not supported
		if ( ! isset( $type_map[ $event_type ] ) ) {
			$debug_manager->log( sprintf( 'Unsupported event type: %s', $event_type ), 'warning', 'ajax' );
			return;
		}

		$storage_type = $type_map[ $event_type ];

		// Get URL hash
		$url_hash = $heatmap_storage->get_url_hash( $url );

		// Prepare event data
		$event_data = array(
			'x'     => $x,
			'y'     => $y,
			'vw'    => $width,
			'vh'    => $height,
			'wh'    => $window_height, // Actual browser viewport height (window.innerHeight)
			't'     => time() * 1000, // Timestamp in milliseconds
			'value' => 1,
		);

		// Element anchor for click reprojection (clicks only; additive).
		// Short keys match the compact vw/vh/wh convention of the file format.
		if ( is_array( $anchor ) && ! empty( $anchor['xp'] ) && isset( $anchor['rx'], $anchor['ry'] ) ) {
			$event_data['xp'] = (string) $anchor['xp'];
			$event_data['rx'] = (float) $anchor['rx'];
			$event_data['ry'] = (float) $anchor['ry'];
		}

		// Get visitor info for metadata
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$visitor = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT country, browser, device_type, os FROM {$wpdb->prefix}optibehavior_visitors WHERE id = %s",
				$visitor_id
			)
		);

		// Fetch the session row once: user_id (logged-in reconciliation below)
		// plus UTM attribution used to tag the heatmap filename for fast
		// filename-scan filtering on the heatmap detail page.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$session_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, utm_campaign, utm_source, utm_medium FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
				$session_id
			)
		);

		// Determine if visitor is logged in.
		// Reconciled L/G classification:
		// The file-system L/G marker and the DB `user_id` column MUST agree so the
		// UI's file-based and DB-based visitor-type filters return consistent counts.
		//
		// Rule: mark the file `_L_` (and record the user_id) only when BOTH
		//  (a) the JS page-render flag says logged-in, AND
		//  (b) the AJAX context can identify a real WP user (via session cookie or
		//      a pre-existing session row that already resolved user_id).
		//
		// Rationale: a private-browser visitor sometimes reaches a page whose PHP
		// render inherited an `is_logged_in=1` flag (e.g. stale admin cookie on the
		// same IP). Without this reconciliation the click would be stored as _L_
		// while the DB session row stayed user_id=NULL — precisely the Session
		// Ghost inconsistency that hides the click from the Guest filter while
		// showing it in All Visitors. Treating the classification as Guest whenever
		// we cannot confirm the user identity is the safe default.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Already verified above.
		$js_logged_in       = isset( $_POST['is_logged_in'] ) ? absint( $_POST['is_logged_in'] ) : 0;
		$current_wp_user_id = is_user_logged_in() ? get_current_user_id() : 0;

		// Resolve the authoritative session user_id (may be 0 when AJAX auth
		// cannot confirm the WP user — the helper falls back to the DB session
		// row for completeness).
		$session_user_id = $current_wp_user_id > 0 ? $current_wp_user_id : 0;

		if ( ! $session_user_id ) {
			// Tertiary: check session DB row for user_id (e.g. backfilled by an
			// earlier request for the same session). Row already fetched above.
			$session_user_id = $session_row && ! empty( $session_row->user_id ) ? (int) $session_row->user_id : 0;
		}

		// L/G marker for the file is only set when JS AND server agree on a user.
		// If the JS says logged-in but the server cannot identify the user, we
		// treat this as Guest to keep file marker consistent with `user_id`.
		$is_logged_in = ( $js_logged_in > 0 && $session_user_id > 0 );

		// Detect A/B test variant from POST data.
		// The frontend JS sends ab_test_id/variant_id from window.optiBehaviorAB,
		// which is only set on pages where an A/B test is actively running.
		// We intentionally do NOT read from cookies here because opti_ab_* cookies
		// persist across all pages, which would incorrectly tag heatmaps on non-test pages.
		$opti_ab_heatmap_test_id    = 0;
		$opti_ab_heatmap_variant_id = 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified above.
		if ( isset( $_POST['ab_test_id'] ) && absint( $_POST['ab_test_id'] ) > 0 ) {
			$opti_ab_heatmap_test_id    = absint( $_POST['ab_test_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified above.
			$opti_ab_heatmap_variant_id = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;
		}

		// Prepare metadata
		$metadata = array(
			'page_id'      => $page_id,
			'url'          => $url,
			'session_id'   => $session_id,
			'visitor_id'   => $visitor_id,
			'timestamp'    => time(),
			// Device classification for the click heatmap file. The visitor row's
			// device_type is authoritative and TABLET-aware (set server-side by
			// Opti_Behavior_Heatmap_Session::detect_device_type via wp_is_mobile +
			// tablet/ipad UA match). The tracker only reports a coarse mobile/pc
			// flag ($is_mobile), which folds tablets into "mobile" — so prefer the
			// visitor device_type and fall back to the coarse guess only when no
			// visitor row exists. Without this, tablet clicks are never stored as
			// 'tablet' and the Tablet device filter shows nothing.
			'device_type'  => ( $visitor && ! empty( $visitor->device_type ) )
				? strtolower( $visitor->device_type )
				: ( $is_mobile ? 'mobile' : 'desktop' ),
			'browser'      => $visitor ? $visitor->browser : 'Other',
			'country'      => $visitor ? $visitor->country : 'XX',
			'is_logged_in' => $is_logged_in,
			'user_id'      => $session_user_id,
			'ab_test_id'   => $opti_ab_heatmap_test_id,
			'variant_id'   => $opti_ab_heatmap_variant_id,
			'os'           => ( $visitor && ! empty( $visitor->os ) ) ? $visitor->os : '',
			'utm_campaign' => ( $session_row && ! empty( $session_row->utm_campaign ) ) ? $session_row->utm_campaign : '',
			'utm_source'   => ( $session_row && ! empty( $session_row->utm_source ) ) ? $session_row->utm_source : '',
			'utm_medium'   => ( $session_row && ! empty( $session_row->utm_medium ) ) ? $session_row->utm_medium : '',
			'viewport'     => array(
				'width'  => $width,
				'height' => $height,
			),
		);

		// Buffer the event (will be flushed on shutdown or when threshold is reached)
		$heatmap_storage->buffer_event( $url_hash, $storage_type, $session_id, $event_data, $metadata );

		$debug_manager->log( sprintf( 'Buffered %s event for heatmap storage (url_hash=%s, session=%s)', $event_type, $url_hash, substr( $session_id, 0, 16 ) ), 'debug', 'ajax' );
	}


	/**
	 * Get or create session ID from cookie or generate new one.
	 *
	 * @since 1.0.0
	 * @return string Session ID.
	 */
	private function get_or_create_session_id() {
		if ( isset( $_COOKIE['optibehavior_sid'] ) && ! empty( $_COOKIE['optibehavior_sid'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['optibehavior_sid'] ) );
		}

		if ( isset( $_COOKIE['opti_behavior_session_id'] ) && ! empty( $_COOKIE['opti_behavior_session_id'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['opti_behavior_session_id'] ) );
		}

		$session_id = 'session_' . time() . '_' . wp_generate_password( 12, false );

		setcookie( 'optibehavior_sid', $session_id, time() + 1800, '/', '', false, true );

		return $session_id;
	}

	/**
	 * Get or create visitor ID from cookie or generate new one.
	 *
	 * Ensures consistency with client-side visitor tracking.
	 *
	 * @since 1.0.0
	 * @return string Visitor ID.
	 */
	private function get_or_create_visitor_id() {
		if ( isset( $_COOKIE['optibehavior_vid'] ) && ! empty( $_COOKIE['optibehavior_vid'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['optibehavior_vid'] ) );
		}

		if ( isset( $_COOKIE['opti_behavior_visitor_id'] ) && ! empty( $_COOKIE['opti_behavior_visitor_id'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['opti_behavior_visitor_id'] ) );
		}

		$visitor_id = 'visitor_' . time() . '_' . wp_generate_password( 12, false );

		// httponly=false: must match the change in class-opti-behavior-heatmap-session.php
		// so session-recorder.js can read the cookie and reuse the same visitor_id.
		setcookie( 'optibehavior_vid', $visitor_id, time() + 31536000, '/', '', false, false );

		return $visitor_id;
	}

	/**
	 * Ensure session exists in database.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param string $session_id Session ID.
	 * @param string $visitor_id Visitor ID.
	 * @param string $url        Page URL.
	 * @param string $title      Page title.
	 * @param int    $screen_width Optional screen width.
	 * @param int    $screen_height Optional screen height.
	 * @param string $referrer   Referrer URL.
	 * @param string $utm_source UTM source parameter.
	 * @param string $utm_medium UTM medium parameter.
	 * @param string $utm_campaign UTM campaign parameter.
	 * @param string $utm_term   UTM term parameter.
	 * @param string $utm_content UTM content parameter.
	 */
	private function ensure_session_exists( $session_id, $visitor_id, $url, $title, $screen_width = null, $screen_height = null, $referrer = '', $utm_source = '', $utm_medium = '', $utm_campaign = '', $utm_term = '', $utm_content = '' ) {
		global $wpdb;

		// Filter out internal referrers (same domain) - server-side safety net
		// Internal navigations within the same site should not be recorded as referrals
		if ( ! empty( $referrer ) ) {
			$referrer_host = wp_parse_url( $referrer, PHP_URL_HOST );
			$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $referrer_host && $site_host ) {
				// Strip www. prefix for robust comparison (handles www vs non-www mismatch)
				$ref_clean  = preg_replace( '/^www\./i', '', $referrer_host );
				$site_clean = preg_replace( '/^www\./i', '', $site_host );
				if ( strcasecmp( $ref_clean, $site_clean ) === 0 ) {
					$referrer = '';
				}
			}
		}

		// Always ensure visitor exists and update screen resolution if provided
		$this->ensure_visitor_exists( $visitor_id, $screen_width, $screen_height );

		// Check if session already exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, start_time, page_views, user_id FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
			$session_id
		) );

		if ( $session ) {
			// Update end_time and calculate duration from start_time to end_time
			$current_time = current_time( 'mysql' );

			// Prepare update data - always update end_time
			$update_data = array(
				'end_time' => $current_time,
			);
			$update_format = array( '%s' );

			// Backfill user_id when the existing row is guest (NULL/0) but this
			// request is authoritatively logged-in. This handles the multi-tab
			// and login-after-session-created cases where the first AJAX hit
			// created a guest row before the user logged in (or before auth
			// cookies were reliably present).
			$current_uid = $this->resolve_request_user_id();
			if ( $current_uid > 0 && ( $session->user_id === null || (int) $session->user_id === 0 ) ) {
				$update_data['user_id'] = $current_uid;
				$update_format[]        = '%d';
			}

			// If referrer fields are NULL in the existing session, update them with new values
			// This handles the case where the session was created on a direct visit (no referrer)
			// and then the user navigates to another page with a referrer
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$session_full = $wpdb->get_row( $wpdb->prepare(
				"SELECT referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
				$session_id
			) );

			if ( $session_full ) {
				if ( is_null( $session_full->referrer ) && ! empty( $referrer ) ) {
					$update_data['referrer'] = $referrer;
					$update_format[] = '%s';
				}
				if ( is_null( $session_full->utm_source ) && ! empty( $utm_source ) ) {
					$update_data['utm_source'] = $utm_source;
					$update_format[] = '%s';
				}
				if ( is_null( $session_full->utm_medium ) && ! empty( $utm_medium ) ) {
					$update_data['utm_medium'] = $utm_medium;
					$update_format[] = '%s';
				}
				if ( is_null( $session_full->utm_campaign ) && ! empty( $utm_campaign ) ) {
					$update_data['utm_campaign'] = $utm_campaign;
					$update_format[] = '%s';
				}
				if ( is_null( $session_full->utm_term ) && ! empty( $utm_term ) ) {
					$update_data['utm_term'] = $utm_term;
					$update_format[] = '%s';
				}
				if ( is_null( $session_full->utm_content ) && ! empty( $utm_content ) ) {
					$update_data['utm_content'] = $utm_content;
					$update_format[] = '%s';
				}
			}

			// Idle-aware duration update. This must run BEFORE end_time is
			// overwritten below because it measures the activity gap against the
			// PREVIOUS end_time. The old behavior recomputed
			// duration = TIMESTAMPDIFF(start_time, end_time) (full wall-clock
			// span), so a tab left open with occasional activity kept advancing
			// end_time and produced absurd 10+ hour sessions that inflated the
			// Avg Session Time KPI and the Top Engaged Users table. Now the
			// duration only grows by the gap since the previous ping, and only
			// when that gap is within the inactivity window (GA-style): idle
			// time is never counted as engagement time.
			$idle_gap = max( 60, (int) apply_filters( 'opti_behavior_session_idle_gap', 1800 ) );
			// Hard ceiling on TOTAL accumulated duration. Without it a bot (or a
			// tab with periodic activity) pinging at intervals shorter than the
			// idle gap accumulates wall-clock time without bound across days,
			// producing impossible 100+ hour sessions that spike the Avg Session
			// Time KPI sparkline. 7200s (2h) matches the caps already enforced by
			// the heartbeat (3600s) and session-end (7200s) write paths.
			$duration_cap = max( $idle_gap, (int) apply_filters( 'opti_behavior_session_duration_cap_total', 7200 ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}optibehavior_sessions
				SET duration = LEAST( %d, duration + (
					CASE
						WHEN TIMESTAMPDIFF(SECOND, COALESCE(end_time, start_time), %s) BETWEEN 0 AND %d
						THEN TIMESTAMPDIFF(SECOND, COALESCE(end_time, start_time), %s)
						ELSE 0
					END
				) )
				WHERE id = %s",
				$duration_cap,
				$current_time,
				$idle_gap,
				$current_time,
				$session_id
			) );

			$wpdb->update(
				"{$wpdb->prefix}optibehavior_sessions",
				$update_data,
				array( 'id' => $session_id ),
				$update_format,
				array( '%s' )
			);

			// Track referrer in the referrers table if we have referrer data and it wasn't tracked before
			if ( ( ! empty( $referrer ) || ! empty( $utm_source ) ) && is_null( $session_full->referrer ) && is_null( $session_full->utm_source ) ) {
				$analytics = $this->core->get_analytics();
				$page_id = $analytics->get_or_create_page_id( $url, $title );

				$session_handler = $this->core->get_session();
				$referrer_data = array(
					'session_id'   => $session_id,
					'page_id'      => $page_id,
					'entry_url'    => $url,
					'referrer_url' => ! empty( $referrer ) ? $referrer : null,
					'utm_source'   => ! empty( $utm_source ) ? $utm_source : null,
					'utm_medium'   => ! empty( $utm_medium ) ? $utm_medium : null,
					'utm_campaign' => ! empty( $utm_campaign ) ? $utm_campaign : null,
					'utm_term'     => ! empty( $utm_term ) ? $utm_term : null,
					'utm_content'  => ! empty( $utm_content ) ? $utm_content : null,
				);

				$session_handler->track_referrer( $referrer_data );
			}

			return;
		}

		// Create new session with referrer and UTM data
		// Capture WordPress user ID using the JS-flag-primary resolver, which
		// stays in lock-step with the L/G marker used in heatmap file storage.
		// Using is_user_logged_in() alone caused a ghost-session bug where files
		// were marked `_L_` but the DB row had user_id=NULL, breaking the
		// Logged-in/Guest/All Visitors segment filters.
		$user_id = $this->resolve_request_user_id();

		// Determine if this visitor is effectively anonymous:
		// - Always anonymous when the global setting is 'anonymous'.
		// - In full mode: anonymous only when the visitor has NOT granted consent
		//   (checked via the optibehavior_consent cookie). The visitor_id 'anon_' prefix
		//   is NOT used here because it is also present when a visitor accepted consent
		//   after a consent-transition (the pre-consent anonymous ID is promoted to full
		//   mode, keeping its prefix).  Relying on the prefix would incorrectly mark
		//   consented visitors as anonymous and hide their real IP.
		$is_effectively_anonymous = ( 'anonymous' === $this->get_privacy_mode() )
			|| ( 'full' === $this->get_privacy_mode() && ! $this->visitor_has_consent() );

		$session_data = array(
			'id'           => $session_id,
			'visitor_id'   => $visitor_id,
			'user_id'      => $user_id,
			'start_time'   => current_time( 'mysql' ),
			'end_time'     => current_time( 'mysql' ),
			'entry_page'   => $url,
			// In anonymous/non-consented mode, store 'Anonymous' instead of real IP.
			'ip'           => $is_effectively_anonymous ? 'Anonymous' : $this->get_client_ip(),
			'referrer'     => ! empty( $referrer ) ? $referrer : null,
			'utm_source'   => ! empty( $utm_source ) ? $utm_source : null,
			'utm_medium'   => ! empty( $utm_medium ) ? $utm_medium : null,
			'utm_campaign' => ! empty( $utm_campaign ) ? $utm_campaign : null,
			'utm_term'     => ! empty( $utm_term ) ? $utm_term : null,
			'utm_content'  => ! empty( $utm_content ) ? $utm_content : null,
			'is_bounce'    => 1,
		);

		$wpdb->insert(
			"{$wpdb->prefix}optibehavior_sessions",
			$session_data,
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		// Track referrer in the referrers table for detailed analytics
		if ( ! empty( $referrer ) || ! empty( $utm_source ) ) {
			$analytics = $this->core->get_analytics();
			$page_id = $analytics->get_or_create_page_id( $url, $title );

			$session_handler = $this->core->get_session();
			$referrer_data = array(
				'session_id'   => $session_id,
				'page_id'      => $page_id,
				'entry_url'    => $url,
				'referrer_url' => ! empty( $referrer ) ? $referrer : null,
				'utm_source'   => ! empty( $utm_source ) ? $utm_source : null,
				'utm_medium'   => ! empty( $utm_medium ) ? $utm_medium : null,
				'utm_campaign' => ! empty( $utm_campaign ) ? $utm_campaign : null,
				'utm_term'     => ! empty( $utm_term ) ? $utm_term : null,
				'utm_content'  => ! empty( $utm_content ) ? $utm_content : null,
			);

			$session_handler->track_referrer( $referrer_data );
		}
	}

	/**
	 * Ensure visitor exists in database
	 *
	 * @param string $visitor_id Visitor ID.
	 * @param int $screen_width Optional screen width.
	 * @param int $screen_height Optional screen height.
	 */
	private function ensure_visitor_exists( $visitor_id, $screen_width = null, $screen_height = null ) {
		global $wpdb;

		// Single query to check existence AND get screen + geo data (avoids 3 separate queries)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, screen_width, screen_height, country, country_name, timezone FROM {$wpdb->prefix}optibehavior_visitors WHERE id = %s",
			$visitor_id
		) );

		if ( $existing ) {
			// Update last_visit and screen resolution if provided
			$update_data = array( 'last_visit' => current_time( 'mysql' ) );
			$update_format = array( '%s' );

			// Only update screen resolution if provided and not already set
			if ( $screen_width !== null && $screen_height !== null ) {
				// Update if not set or if values changed
				if ( $existing->screen_width === null || $existing->screen_height === null ) {
					$update_data['screen_width'] = $screen_width;
					$update_data['screen_height'] = $screen_height;
					$update_format[] = '%d';
					$update_format[] = '%d';
				}
			}

			// Update geolocation if country is missing or unknown.
			// GDPR: IP is used for geo lookup only, never stored (session stores 'Anonymous').
			if ( $existing->country === null || $existing->country === 'UN' || $existing->country_name === 'Unknown' ) {
				$ip = $this->get_client_ip();
				// Use stored timezone as fallback hint for geolocation
				$stored_tz = ! empty( $existing->timezone ) ? $existing->timezone : '';
				$geo_data = $this->geolocate_ip( $ip, $stored_tz );

				if ( ! empty( $geo_data['country'] ) && $geo_data['country'] !== 'UN' && $geo_data['country'] !== null ) {
					$update_data['country'] = $geo_data['country'];
					$update_data['country_name'] = $geo_data['country_name'];
					$update_data['region'] = ! empty( $geo_data['region'] ) ? $geo_data['region'] : null;
					$update_data['city'] = ! empty( $geo_data['city'] ) ? $geo_data['city'] : null;
					$update_data['timezone'] = ! empty( $geo_data['timezone'] ) ? $geo_data['timezone'] : null;
					$update_format[] = '%s';
					$update_format[] = '%s';
					$update_format[] = '%s';
					$update_format[] = '%s';
					$update_format[] = '%s';
				}
			}

			$wpdb->update(
				"{$wpdb->prefix}optibehavior_visitors",
				$update_data,
				array( 'id' => $visitor_id ),
				$update_format,
				array( '%s' )
			);
			return;
		}

		// Get visitor metadata from user agent
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$metadata = $this->parse_user_agent( $user_agent );

		// Perform geolocation — GDPR: IP is used for geo only, never stored.
		$country = null;
		$country_name = null;
		$region = null;
		$city = null;
		$timezone = null;

		$ip = $this->get_client_ip();
		$geo_data = $this->geolocate_ip( $ip, '' );

		if ( ! empty( $geo_data['country'] ) && $geo_data['country'] !== 'UN' && $geo_data['country'] !== null ) {
			$country = $geo_data['country'];
			$country_name = $geo_data['country_name'];
			$region = ! empty( $geo_data['region'] ) ? $geo_data['region'] : null;
			$city = ! empty( $geo_data['city'] ) ? $geo_data['city'] : null;
			$timezone = ! empty( $geo_data['timezone'] ) ? $geo_data['timezone'] : null;
		}

		// Create new visitor with metadata
		$wpdb->insert(
			"{$wpdb->prefix}optibehavior_visitors",
			array(
				'id'           => $visitor_id,
				'first_visit'  => current_time( 'mysql' ),
				'last_visit'   => current_time( 'mysql' ),
				'device_type'  => $metadata['device_type'],
				'browser'      => $metadata['browser'],
				'browser_version' => $metadata['browser_version'],
				'os'           => $metadata['os'],
				'os_version'   => $metadata['os_version'],
				'screen_width' => $screen_width,
				'screen_height' => $screen_height,
				'country'      => $country,
				'country_name' => $country_name,
				'region'       => $region,
				'city'         => $city,
				'timezone'     => $timezone,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Ensure pageview exists for this session and page.
	 *
	 * Two distinct callers rely on this method and need different dedup rules:
	 *  - Navigation events (heatmap tracker on page load, recorder session-start,
	 *    recorder page_load sub-events) each represent a *genuinely new page view*.
	 *    The heatmap tracker and the session recorder can both fire their navigation
	 *    event for the exact same page load within milliseconds of one another, so we
	 *    only dedup against a row inserted in the last few seconds (the race window);
	 *    anything older is a real repeat visit (reload/back-button/re-navigation) and
	 *    must insert a fresh row.
	 *  - The periodic heartbeat (ajax_heartbeat, used to keep scroll_depth current)
	 *    is NOT a navigation event: it fires repeatedly for as long as the visitor
	 *    stays on the same page. It must never create a new pageview row just because
	 *    time has passed — it only touches the latest existing row, and only inserts a
	 *    row as a last-resort safety net when none exists yet at all.
	 *
	 * @param string $session_id Session ID.
	 * @param string $visitor_id Visitor ID.
	 * @param int    $page_id Page ID.
	 * @param string $url Page URL.
	 * @param string $title Page title.
	 * @param bool   $is_navigation_event Whether this call represents a genuine new
	 *                                    page view (true) or a periodic heartbeat
	 *                                    touch on an already-recorded view (false).
	 */
	private function ensure_pageview_exists( $session_id, $visitor_id, $page_id, $url, $title, $is_navigation_event = true ) {
		global $wpdb;
		$pageviews_table = "{$wpdb->prefix}optibehavior_pageviews";

		// Keep sessions.exit_page current: every call to this method (navigation,
		// recorder page-load, heartbeat touch) means the visitor is on $url RIGHT
		// NOW, so the last write naturally converges on the session's exit page.
		// Done at the top so the early-return dedup/touch branches below are
		// covered too. Without this the column is never written by the tracker
		// and the dashboard's Exit Page filter/suggestions have nothing to match.
		$this->sync_session_exit_page( $session_id, $url );

		if ( $is_navigation_event ) {
			// Only treat a row as "the same navigation" (race-condition dedup) if it
			// was written within the last few seconds; older rows are a genuine
			// separate visit and must not block a new insert.
			//
			// The cutoff is computed in PHP from current_time( 'mysql' ) -- the same
			// clock basis used to stamp view_time below -- rather than the SQL NOW()
			// function. The web/app server and DB server clocks can differ (seen in
			// practice: a 2-hour skew between PHP's current_time() and MySQL's NOW()
			// on this stack), which would silently break this comparison and either
			// defeat the dedup (clock ahead) or wrongly dedup real repeat visits
			// (clock behind).
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 5 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$recent_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$pageviews_table}
				 WHERE session_id = %s AND page_id = %d
				   AND view_time >= %s
				 ORDER BY id DESC LIMIT 1",
				$session_id,
				$page_id,
				$cutoff
			) );

			if ( $recent_id ) {
				$wpdb->update(
					$pageviews_table,
					array( 'view_time' => current_time( 'mysql' ) ),
					array( 'id' => $recent_id ),
					array( '%s' ),
					array( '%d' )
				);
				return;
			}
		} else {
			// Non-navigation caller (heartbeat): never create a brand-new pageview
			// purely because time has passed. Touch the most recent row for this
			// session+page if one exists; only fall through to INSERT when none
			// exists at all yet (safety net for a missed navigation event).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$latest_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$pageviews_table}
				 WHERE session_id = %s AND page_id = %d
				 ORDER BY id DESC LIMIT 1",
				$session_id,
				$page_id
			) );

			if ( $latest_id ) {
				$wpdb->update(
					$pageviews_table,
					array( 'view_time' => current_time( 'mysql' ) ),
					array( 'id' => $latest_id ),
					array( '%s' ),
					array( '%d' )
				);
				return;
			}
		}

		// Create new pageview with scroll depth of 0 (will be updated by JavaScript tracking)
		$wpdb->insert(
			$pageviews_table,
			array(
				'session_id'  => $session_id,
				'visitor_id'  => $visitor_id,
				'page_id'     => $page_id,
				'url'         => $url,
				'title'       => $title,
				'view_time'   => current_time( 'mysql' ),
				'scroll_depth' => 0,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);

		// Sync session page_views count from actual pageview records.
		// Counts every row (not DISTINCT page_id) so repeat visits to the same page
		// within a session are reflected in the "Page Views" KPI / pages-per-session
		// metrics, matching how Top Pages already counts rows from this table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}optibehavior_sessions SET page_views = (
				SELECT COUNT(*) FROM {$pageviews_table} WHERE session_id = %s
			) WHERE id = %s",
			$session_id,
			$session_id
		) );

		// A multi-page session is by definition not a bounce. Clearing it here
		// (single atomic statement, every pageview-recording path funnels through
		// this sync) closes the gap where a second navigation arrived via the
		// session-continue path, which incremented page_views without ever
		// touching is_bounce.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}optibehavior_sessions SET is_bounce = 0 WHERE id = %s AND page_views > 1",
			$session_id
		) );
	}

	/**
	 * Record the page the visitor is currently on as the session's exit page.
	 *
	 * Last-writer-wins: called from every pageview-recording path, so the final
	 * call of a session leaves the true exit page behind. The WHERE guard skips
	 * the write entirely when the stored value is already this URL, keeping the
	 * per-heartbeat overhead at a single indexed no-op SELECT.
	 *
	 * @param string $session_id Session ID.
	 * @param string $url        URL the visitor is currently viewing.
	 */
	private function sync_session_exit_page( $session_id, $url ) {
		global $wpdb;

		$url = (string) $url;
		if ( '' === $url || '' === (string) $session_id ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}optibehavior_sessions
			 SET exit_page = %s
			 WHERE id = %s AND ( exit_page IS NULL OR exit_page != %s )",
			$url,
			$session_id,
			$url
		) );
	}

	/**
	 * Check whether an analytics dimension value is just a placeholder.
	 *
	 * @param mixed $value Dimension value.
	 * @return bool True when the value should not overwrite a known value.
	 */
	private function is_unknown_dimension_value( $value ) {
		if ( null === $value ) {
			return true;
		}

		$value = strtolower( trim( (string) $value ) );
		return '' === $value || in_array( $value, array( 'unknown', 'undefined', 'other', 'null' ), true );
	}

	/**
	 * Parse user agent to extract browser, OS, and device information
	 *
	 * @param string $user_agent User agent string.
	 * @return array Parsed metadata.
	 */
	private function parse_user_agent( $user_agent ) {
		$metadata = array(
			'browser'         => 'Unknown',
			'browser_version' => '',
			'os'              => 'Unknown',
			'os_version'      => '',
			'device_type'     => 'Desktop',
		);

		if ( empty( $user_agent ) ) {
			return $metadata;
		}

		// Detect browser. Order matters: many Chromium-based browsers also include Chrome/Safari tokens.
		$browser_patterns = array(
			'Edge'                    => '/(?:Edg|EdgA|EdgiOS|Edge)\/([0-9.]+)/i',
			'Opera'                   => '/(?:OPR|Opera|OPiOS)\/([0-9.]+)/i',
			'Vivaldi'                 => '/Vivaldi\/([0-9.]+)/i',
			'Yandex'                  => '/YaBrowser\/([0-9.]+)/i',
			'Samsung Internet'        => '/SamsungBrowser\/([0-9.]+)/i',
			'UC Browser'              => '/(?:UCBrowser|UCWEB)\/([0-9.]+)/i',
			'DuckDuckGo'              => '/DuckDuckGo\/([0-9.]+)/i',
			'Huawei Browser'          => '/HuaweiBrowser\/([0-9.]+)/i',
			'Mi Browser'              => '/MiuiBrowser\/([0-9.]+)/i',
			'QQ Browser'              => '/QQBrowser\/([0-9.]+)/i',
			'Naver Whale'             => '/Whale\/([0-9.]+)/i',
			'Maxthon'                 => '/Maxthon\/([0-9.]+)/i',
			'Pale Moon'               => '/PaleMoon\/([0-9.]+)/i',
			'Waterfox'                => '/Waterfox\/([0-9.]+)/i',
			'Firefox'                 => '/(?:Firefox|FxiOS)\/([0-9.]+)/i',
			'Chrome'                  => '/(?:Chrome|CriOS|Chromium)\/([0-9.]+)/i',
			'Internet Explorer'       => '/MSIE ([0-9.]+)/i',
			'Internet Explorer Legacy' => '/Trident\/.*rv:([0-9.]+)/i',
		);

		foreach ( $browser_patterns as $browser_name => $pattern ) {
			if ( preg_match( $pattern, $user_agent, $matches ) ) {
				$metadata['browser'] = 'Internet Explorer Legacy' === $browser_name ? 'Internet Explorer' : $browser_name;
				$metadata['browser_version'] = isset( $matches[1] ) ? $matches[1] : '';
				break;
			}
		}

		if ( 'Unknown' === $metadata['browser'] ) {
			if ( preg_match( '/Instagram/i', $user_agent ) ) {
				$metadata['browser'] = 'Instagram In-App Browser';
			} elseif ( preg_match( '/FBAN|FBAV/i', $user_agent ) ) {
				$metadata['browser'] = 'Facebook In-App Browser';
			} elseif ( preg_match( '/Version\/([0-9.]+).*Safari\/[0-9.]+/i', $user_agent, $matches ) ) {
				$metadata['browser'] = 'Safari';
				$metadata['browser_version'] = $matches[1];
			} elseif ( preg_match( '/Safari\/([0-9.]+)/i', $user_agent, $matches ) && ! preg_match( '/Chrome|CriOS|Chromium|Android/i', $user_agent ) ) {
				$metadata['browser'] = 'Safari';
				$metadata['browser_version'] = $matches[1];
			}
		}

		// Detect OS. Mobile platforms must be checked before generic Linux/Mac tokens.
		if ( preg_match( '/Android ([0-9.]+)/', $user_agent, $matches ) ) {
			$metadata['os'] = 'Android';
			$metadata['os_version'] = $matches[1];
			$metadata['device_type'] = 'Mobile';
		} elseif ( preg_match( '/iPhone OS ([0-9_]+)/', $user_agent, $matches ) ) {
			$metadata['os'] = 'iOS';
			$metadata['os_version'] = str_replace( '_', '.', $matches[1] );
			$metadata['device_type'] = 'Mobile';
		} elseif ( preg_match( '/iPad/', $user_agent ) ) {
			$metadata['os'] = 'iOS';
			$metadata['device_type'] = 'Tablet';
		} elseif ( preg_match( '/Windows NT ([0-9.]+)/', $user_agent, $matches ) ) {
			$metadata['os'] = 'Windows';
			$metadata['os_version'] = $matches[1];
		} elseif ( preg_match( '/Mac OS X ([0-9_]+)/', $user_agent, $matches ) ) {
			$metadata['os'] = 'macOS';
			$metadata['os_version'] = str_replace( '_', '.', $matches[1] );
		} elseif ( preg_match( '/Linux/', $user_agent ) ) {
			$metadata['os'] = 'Linux';
		}

		// Detect device type (if not already set by OS detection)
		if ( $metadata['device_type'] === 'Desktop' ) {
			if ( preg_match( '/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/', $user_agent ) ) {
				$metadata['device_type'] = 'Mobile';
			}
		}

		return $metadata;
	}

	/**
	 * Handle events data
	 *
	 * @param array $data Request data.
	 */
	private function handle_events( $data ) {
		$session = $this->core->get_session();
		$analytics = $this->core->get_analytics();

		$session_id = $data['session_id'];
		$visitor_id = $data['visitor_id'];
		$page_id = $analytics->get_or_create_page_id( $data['url'], $data['title'] );

		foreach ( $data['events'] as $event ) {
			$event['page_id'] = $page_id;
			$session->store_session_event( $session_id, $visitor_id, $event );
		}
	}

	/**
	 * Handle pageview data
	 *
	 * @param array $data Request data.
	 */
	private function handle_pageview( $data ) {
		$session = $this->core->get_session();
		$analytics = $this->core->get_analytics();

		$pageview_data = $data['pageview'];
		$pageview_data['page_id'] = $analytics->get_or_create_page_id( $pageview_data['url'], $pageview_data['title'] );

		$session->track_pageview( $pageview_data );
	}

	/**
	 * Handle session start data
	 *
	 * @param array $data Request data.
	 */
	private function handle_session_start( $data ) {
		$session = $this->core->get_session();

		$session_data = $data['session_start'];
		// Returns true when a new session row was inserted, false when an existing one was updated.
		$is_new_session = $session->track_session_start( $session_data );

		// Also track visitor if provided.
		// Only pass $is_new_session = true on the first page load so that visit_count
		// is not incremented on every subsequent page view within the same session.
		if ( isset( $data['visitor'] ) ) {
			$session->track_visitor( $data['visitor'], $is_new_session );
		}
	}

	/**
	 * AJAX handler for session recording
	 */
	public function ajax_session_record() {
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'AJAX request received: session_record', 'debug', 'ajax' );

		// Verify nonce for security
		// WordPress nonces work for both logged-in and anonymous users
		// The nonce is generated on the frontend and passed with each request
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'opti_behavior_heatmap_nonce' ) ) {
			$debug_manager->log( 'Session record failed: Invalid nonce', 'warning', 'ajax' );
			// Return HTTP 403 (not the default 200) so the reporter JS sendPageView()
			// handler detects the expired nonce and refreshes it. Matches the click
			// ingest path; prevents cached-page session-start requests from being
			// silently dropped with a stale nonce.
			wp_send_json_error( __( 'Invalid nonce', 'opti-behavior' ), 403 );
		}

		// Ingest gate: silently ignore session starts from bots / excluded IPs
		// (200 success so tracker JS does not retry).
		if ( Opti_Behavior_Ingest_Gate::should_reject( 'session_record' ) ) {
			wp_send_json_success( array( 'status' => 'ignored' ) );
		}

		// Don't track admin users unless track_admin_users setting is enabled (or in test mode)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET/COOKIE check for test mode (permission check follows)
		$is_test_mode = isset( $_GET['opti_behavior_test_tracking'] ) || isset( $_COOKIE['opti_behavior_test_tracking'] ) || isset( $_GET['opti_behavior_test_recording'] ) || isset( $_COOKIE['opti_behavior_test_recording'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( current_user_can( 'manage_options' ) && ! $is_test_mode ) {
			$traffic_settings = get_option( 'opti_behavior_traffic_settings', array() );
			$track_admin      = isset( $traffic_settings['track_admin_users'] ) ? (bool) $traffic_settings['track_admin_users'] : true;
			if ( ! $track_admin ) {
				$debug_manager->log( 'Session record skipped: Admin user tracking disabled in settings', 'info', 'ajax' );
				wp_send_json_success( array( 'message' => 'Admin tracking disabled' ) );
				return;
			}
		}

		if ( ! isset( $_POST['data'] ) ) {
			$debug_manager->log( 'Session record failed: No data provided', 'warning', 'ajax' );
			wp_send_json_error( __( 'No data provided', 'opti-behavior' ) );
		}

		// Sanitize JSON input: wp_unslash first, then json_decode, then sanitize the decoded values
		// Note: We cannot sanitize before json_decode as it would break the JSON structure
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON data must be decoded first, then individual values are sanitized in the processing logic
		$raw_data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data = json_decode( $raw_data, true );

		// Validate json_decode result
		if ( ! is_array( $data ) ) {
			$debug_manager->log( 'Session record failed: Invalid JSON data', 'error', 'ajax' );
			wp_send_json_error( __( 'Invalid JSON data', 'opti-behavior' ) );
		}

		// Sanitize the decoded JSON data recursively
		// This ensures all string values from JSON are properly sanitized
		$data = $this->sanitize_json_data( $data );

		$debug_manager->log( 'Processing session recording data', 'info', 'ajax' );

		// Check for session_id in both formats (session_id and sessionId)
		$session_id = null;
		if ( isset( $data['session_id'] ) ) {
			$session_id = sanitize_text_field( $data['session_id'] );
		} elseif ( isset( $data['sessionId'] ) ) {
			$session_id = sanitize_text_field( $data['sessionId'] );
		}

		if ( ! $data || ! $session_id ) {
			$debug_manager->log( 'Session record failed: Invalid session data', 'error', 'ajax' );
			wp_send_json_error( __( 'Invalid session data', 'opti-behavior' ) );
		}

		global $wpdb;
		$analytics = $this->core->get_analytics();

		// Handle different types of session recording data
		$action_type = isset( $data['action'] ) ? sanitize_text_field( $data['action'] ) : 'unknown';

		switch ( $action_type ) {
			case 'session_start':
				$this->handle_recorder_session_start( $data, $session_id );
				break;
			case 'events':
				$this->handle_recorder_events( $data, $session_id );
				break;
			case 'page_load':
				$this->handle_recorder_page_load( $data, $session_id );
				break;
			case 'referrer_tracking':
				$this->handle_referrer_tracking( $data, $session_id );
				break;
			case 'outbound_click':
				$this->handle_outbound_click( $data, $session_id );
				break;
			default:
				// Legacy recording data format
				if ( isset( $data['recording'] ) ) {
					$recording_data = array(
						'session_id'     => $session_id,
						'page_id'        => $analytics->get_or_create_page_id(
							isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '',
							isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : ''
						),
						'recording_data' => json_encode( $data['recording'] ),
						'start_time'     => current_time( 'mysql' ),
						'duration'       => isset( $data['duration'] ) ? absint( $data['duration'] ) : 0,
						'file_size'      => strlen( json_encode( $data['recording'] ) ),
					);

					$wpdb->insert(
						"{$wpdb->prefix}optibehavior_recordings",
						$recording_data,
						array( '%s', '%d', '%s', '%s', '%d', '%d' )
					);
				}
				break;
		}

		wp_send_json_success( array( 'status' => 'recorded' ) );
	}

	/**
	 * AJAX handler for geolocation
	 */
	public function ajax_geo() {
		// Ingest gate: silently ignore geo lookups from bots / excluded IPs
		// (200 success so tracker JS does not retry). No nonce on this endpoint,
		// so the gate runs first.
		if ( Opti_Behavior_Ingest_Gate::should_reject( 'geo' ) ) {
			wp_send_json_success( array( 'status' => 'ignored' ) );
		}

		// Security Note: Geolocation endpoint for frontend visitors
		// This endpoint is called from the frontend to get visitor location data
		// WordPress nonces work for both logged-in and anonymous users
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Frontend geolocation from anonymous visitors, no sensitive data exposed
		$ip = $this->get_client_ip();

		// Accept client timezone from POST data so that timezone-based geo works
		// even in anonymous mode (where external API calls are disabled).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Frontend geolocation from anonymous visitors, no sensitive data exposed
		$client_timezone = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : '';

		$geo_data = $this->geolocate_ip( $ip, $client_timezone );

		wp_send_json_success( $geo_data );
	}

	/**
	 * AJAX handler for top users widget
	 */
	public function ajax_top_users() {
		// Verify nonce
		check_ajax_referer( 'optibehavior_top_users', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'opti-behavior' ) );
		}

		/*
		 * The dashboard owns the canonical Top Engaged Users contract (date range,
		 * spam policy, Visitors KPI cap, and pages-per-session fallback). This class
		 * registers earlier than the dashboard, so delegate when the dashboard is
		 * available to prevent the legacy handler from shadowing the canonical one.
		 */
		$dashboard = ( is_object( $this->core ) && method_exists( $this->core, 'get_dashboard' ) ) ? $this->core->get_dashboard() : null;
		if ( is_object( $dashboard ) && method_exists( $dashboard, 'ajax_top_users' ) ) {
			$dashboard->ajax_top_users();
			return;
		}

		global $wpdb;

		// Get period from request, default to last 7 days
		$period     = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'last7days';
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

		// Build date condition directly in SQL based on whitelisted period
		$allowed_periods = array( 'today', 'yesterday', 'last7days', 'last30days', 'thismonth', 'custom' );
		if ( ! in_array( $period, $allowed_periods, true ) ) {
			$period = 'last7days';
		}

		// Validate custom dates if period is custom
		if ( $period === 'custom' && ( empty( $start_date ) || empty( $end_date ) ) ) {
			$period = 'last7days';
		}

		$range_end_timestamp = current_time( 'timestamp' );
		switch ( $period ) {
			case 'today':
				$range_start = gmdate( 'Y-m-d 00:00:00', $range_end_timestamp );
				$range_end   = gmdate( 'Y-m-d 23:59:59', $range_end_timestamp );
				break;
			case 'yesterday':
				$range_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day', $range_end_timestamp ) );
				$range_end   = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day', $range_end_timestamp ) );
				break;
			case 'last30days':
				$range_start = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days', $range_end_timestamp ) );
				$range_end   = gmdate( 'Y-m-d H:i:s', $range_end_timestamp );
				break;
			case 'thismonth':
				$range_start = gmdate( 'Y-m-01 00:00:00', $range_end_timestamp );
				$range_end   = gmdate( 'Y-m-d H:i:s', $range_end_timestamp );
				break;
			case 'custom':
				$range_start = $start_date . ' 00:00:00';
				$range_end   = $end_date . ' 23:59:59';
				break;
			case 'last7days':
			default:
				$range_start = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days', $range_end_timestamp ) );
				$range_end   = gmdate( 'Y-m-d H:i:s', $range_end_timestamp );
				break;
		}

		$visitor_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT visitor_id) FROM {$wpdb->prefix}optibehavior_sessions WHERE start_time BETWEEN %s AND %s AND visitor_id IS NOT NULL AND visitor_id != ''",
				$range_start,
				$range_end
			)
		);
		$row_limit     = min( 10, max( 0, $visitor_total ) );

		if ( 0 === $row_limit ) {
			wp_send_json_success(
				array(
					'items'         => array(),
					'visitor_total' => $visitor_total,
					'limit'         => $row_limit,
					'count_basis'   => 'unique_visitors_with_sessions',
				)
			);
		}

		// Build SQL with conditional date filtering
		$sql = "SELECT
			v.id as visitor_id,
			v.country_name,
			v.country,
			v.device_type,
			v.last_visit,
			MAX(s.user_id) as user_id,
			COUNT(DISTINCT s.id) as session_count,
			COALESCE(SUM(s.duration), 0) as total_duration,
			COALESCE(AVG(s.duration), 0) as avg_duration,
			SUM(
				CASE
					WHEN COALESCE(s.page_views, 0) > 0 THEN COALESCE(s.page_views, 0)
					ELSE COALESCE(pvc.pageview_count, 0)
				END
			) as total_pageviews,
			COALESCE(AVG(pvc.avg_time_on_page), 0) as avg_time_on_page
		FROM {$wpdb->prefix}optibehavior_visitors v
		LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON v.id = s.visitor_id AND ";

		// Add date condition based on validated period
		switch ( $period ) {
			case 'today':
				$sql .= 's.start_time >= CURDATE()';
				break;
			case 'yesterday':
				$sql .= 's.start_time >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND s.start_time < CURDATE()';
				break;
			case 'last30days':
				$sql .= 's.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
				break;
			case 'thismonth':
				$sql .= "s.start_time >= DATE_FORMAT(NOW(), '%Y-%m-01')";
				break;
			case 'custom':
				// Use prepared statement for custom dates
				$sql .= $wpdb->prepare( 's.start_time >= %s AND s.start_time <= DATE_ADD(%s, INTERVAL 1 DAY)', $start_date, $end_date );
				break;
			case 'last7days':
			default:
				$sql .= 's.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
				break;
		}

		// Add visitor date filter based on same period
		$visitor_date_filter = '';
		switch ( $period ) {
			case 'today':
				$visitor_date_filter = ' AND v.last_visit >= CURDATE()';
				break;
			case 'yesterday':
				$visitor_date_filter = ' AND v.last_visit >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND v.last_visit < CURDATE()';
				break;
			case 'last30days':
				$visitor_date_filter = ' AND v.last_visit >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
				break;
			case 'thismonth':
				$visitor_date_filter = " AND v.last_visit >= DATE_FORMAT(NOW(), '%Y-%m-01')";
				break;
			case 'custom':
				$visitor_date_filter = $wpdb->prepare( ' AND v.last_visit >= %s AND v.last_visit <= DATE_ADD(%s, INTERVAL 1 DAY)', $start_date, $end_date );
				break;
			case 'last7days':
			default:
				$visitor_date_filter = ' AND v.last_visit >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
				break;
		}


		$sql .= "
		LEFT JOIN (
			SELECT
				session_id,
				COUNT(*) AS pageview_count,
				AVG(time_on_page) AS avg_time_on_page
			FROM {$wpdb->prefix}optibehavior_pageviews
			WHERE view_time BETWEEN '" . esc_sql( $range_start ) . "' AND '" . esc_sql( $range_end ) . "'
			GROUP BY session_id
		) pvc ON pvc.session_id = s.id
	WHERE v.id IS NOT NULL AND v.id != ''";
		$sql .= $visitor_date_filter;
		$sql .= "
		GROUP BY v.id, v.country_name, v.country, v.device_type, v.last_visit
		HAVING session_count > 0
		ORDER BY session_count DESC, total_duration DESC
		LIMIT " . (int) $row_limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL built from whitelisted period values only, custom dates use $wpdb->prepare(), no user input interpolated
		$results = $wpdb->get_results( $sql );

		// Debug: Check sessions table for user_id values
		$debug_manager = $this->core->get_debug_manager();
		if ( $debug_manager ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Debug query with hardcoded values.
			$debug_sessions = $wpdb->get_results( "SELECT visitor_id, user_id, start_time FROM {$wpdb->prefix}optibehavior_sessions WHERE visitor_id LIKE 'visitor_1764698224486%' ORDER BY start_time DESC LIMIT 3" );
			$debug_manager->log( 'Sessions for hamdo visitor: ' . wp_json_encode( $debug_sessions ), 'debug', 'top-users' );

			// Debug: Log the SQL query and first result
			$debug_manager->log( 'Top Users SQL: ' . $sql, 'debug', 'top-users' );
			if ( ! empty( $results ) ) {
				$debug_manager->log( 'First result username: ' . ( isset( $results[0]->username ) ? $results[0]->username : 'NULL' ), 'debug', 'top-users' );
				$debug_manager->log( 'First result visitor_id: ' . $results[0]->visitor_id, 'debug', 'top-users' );
			}
		}

		// Format the results for frontend consumption with correct field names
		$formatted_results = array();
		foreach ( $results as $index => $row ) {
			// Calculate additional metrics
			$daily_freq = round( floatval( $row->session_count ) / 7, 2 ); // Approximate daily frequency
			$pages_per_session = $row->session_count > 0 ? round( floatval( $row->total_pageviews ) / floatval( $row->session_count ), 2 ) : 0;

			// Format last visit time for human display
			$last_seen_human = '';
			if ( $row->last_visit ) {
				$last_visit_time = strtotime( $row->last_visit );
				if ( $last_visit_time ) {
					$time_diff = current_time( 'timestamp' ) - $last_visit_time;
					if ( $time_diff < 60 ) {
						$last_seen_human = __( 'just now', 'opti-behavior' );
					} elseif ( $time_diff < 3600 ) {
						/* translators: %s: number of minutes */
						$last_seen_human = sprintf( __( '%sm ago', 'opti-behavior' ), floor( $time_diff / 60 ) );
					} elseif ( $time_diff < 86400 ) {
						/* translators: %s: number of hours */
						$last_seen_human = sprintf( __( '%sh ago', 'opti-behavior' ), floor( $time_diff / 3600 ) );
					} else {
						/* translators: %s: number of days */
						$last_seen_human = sprintf( __( '%sd ago', 'opti-behavior' ), floor( $time_diff / 86400 ) );
					}
				}
			}

			// Create visitor display - show username for logged-in users, visitor ID otherwise
			// Get username for logged-in users
		$username = null;
		if ( ! empty( $row->user_id ) && $row->user_id > 0 ) {
			$user = get_user_by( 'id', $row->user_id );
			if ( $user ) {
				$username = $user->display_name;
			}
		}
		$visitor_short = ! empty( $username ) ? $username : substr( $row->visitor_id, 0, 8 ) . '...';

			$formatted_results[] = array(
				'rank'              => $index + 1,
				'visitor_id'        => $row->visitor_id,
				'visitor_short'     => $visitor_short,
				'display_name'      => $username,
				'user_id'           => ! empty( $row->user_id ) ? intval( $row->user_id ) : null,
				'profile_url'       => ! empty( $username ) ? admin_url( 'user-edit.php?user_id=' . $row->user_id ) : '',
				'country'           => ( $row->country_name ?: ( ( $row->country && strtoupper($row->country) !== 'UN' ) ? $this->get_country_name( strtoupper($row->country) ) : __( 'Unknown', 'opti-behavior' ) ) ),
				'country_name'      => ( $row->country_name ?: ( ( $row->country && strtoupper($row->country) !== 'UN' ) ? $this->get_country_name( strtoupper($row->country) ) : __( 'Unknown', 'opti-behavior' ) ) ),
				'country_code'      => ( $row->country ? strtoupper($row->country) : 'UN' ),
				'device_type'       => ucfirst( $row->device_type ?: __( 'Unknown', 'opti-behavior' ) ),
				'sessions'          => intval( $row->session_count ),        // Frontend expects 'sessions'
				'session_count'     => intval( $row->session_count ),        // Keep for compatibility
				'total_time'        => intval( $row->total_duration ),       // Frontend expects 'total_time'
				'total_duration'    => intval( $row->total_duration ),       // Keep for compatibility
				'avg_session_time'  => round( floatval( $row->avg_duration ), 1 ), // Frontend expects 'avg_session_time'
				'avg_duration'      => round( floatval( $row->avg_duration ), 1 ), // Keep for compatibility
				'total_pageviews'   => intval( $row->total_pageviews ),
				'pages_per_session' => $pages_per_session,                   // Frontend expects this
				'avg_time_on_page'  => round( floatval( $row->avg_time_on_page ), 1 ),
				'daily_freq'        => $daily_freq,                          // Frontend expects 'daily_freq'
				'daily_frequency'   => $daily_freq,                          // Keep for compatibility
				'last_visit'        => $row->last_visit,
				'last_seen_human'   => $last_seen_human,                     // Frontend expects this
			);
		}

		if ( $debug_manager ) {
			$debug_manager->log( 'Formatted results count: ' . count( $formatted_results ), 'debug', 'top-users' );
			if ( ! empty( $formatted_results ) ) {
				$debug_manager->log( 'First formatted result visitor_short: ' . $formatted_results[0]['visitor_short'], 'debug', 'top-users' );
				$debug_manager->log( 'First formatted result display_name: ' . ( $formatted_results[0]['display_name'] ?? 'NULL' ), 'debug', 'top-users' );
				$debug_manager->log( 'First formatted result user_id: ' . ( $formatted_results[0]['user_id'] ?? 'NULL' ), 'debug', 'top-users' );
			}
		}
		wp_send_json_success(
			array(
				'items'         => array_slice( $formatted_results, 0, $row_limit ),
				'visitor_total' => $visitor_total,
				'limit'         => $row_limit,
				'count_basis'   => 'unique_visitors_with_sessions',
			)
		);
	}

	/**
	 * AJAX handler for backfilling pageview times
	 */
	public function ajax_backfill_pageview_times() {
		// Verify nonce
		check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'opti-behavior' ) );
		}

		global $wpdb;

		// Backfill missing pageview times based on session data
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix, no user input
		$wpdb->query(
			"UPDATE {$wpdb->prefix}optibehavior_pageviews pv
			JOIN {$wpdb->prefix}optibehavior_sessions s ON pv.session_id = s.id
			SET pv.time_on_page = GREATEST(0, TIMESTAMPDIFF(SECOND, pv.view_time, s.end_time))
			WHERE pv.time_on_page = 0 AND s.end_time IS NOT NULL"
		);

		$affected_rows = $wpdb->rows_affected;

		wp_send_json_success( array( 'updated_rows' => $affected_rows ) );
	}

	/**
	 * Get the current privacy mode setting.
	 *
	 * Reads `privacy_mode` from the plugin options. Defaults to `'anonymous'`
	 * so that new or upgraded installs are GDPR-safe by default.
	 *
	 * @since 1.0.4
	 * @return string Either 'anonymous' or 'full'.
	 */
	private function get_privacy_mode() {
		$options = $this->core->get_options();
		return isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous';
	}

	/**
	 * Check whether the current visitor has explicitly granted consent.
	 *
	 * Reads the `optibehavior_consent` cookie set by the frontend consent banner.
	 * Returns true only when the value is exactly 'granted', so rejected/absent
	 * consent always defaults to anonymous-safe behaviour.
	 *
	 * @since 1.0.5
	 * @return bool True if visitor consented, false otherwise.
	 */
	private function visitor_has_consent() {
		if ( ! isset( $_COOKIE['optibehavior_consent'] ) ) {
			return false;
		}
		return 'granted' === sanitize_text_field( wp_unslash( $_COOKIE['optibehavior_consent'] ) );
	}

	/**
	 * Resolve the canonical visitor_id for an "effectively anonymous" request.
	 *
	 * Priority:
	 *   1. The visitor_id passed in the POST body (caller already pulled it from
	 *      $_POST or $_REQUEST and validated it with sanitize_text_field()) — if
	 *      it starts with 'anon_' it is a client-reported anonymous id and is
	 *      preserved verbatim. In cookieless Anonymous Mode the tracker sources
	 *      this from optiBehaviorHeatmapConfig.anon_vid (the server daily hash),
	 *      so it already matches the server-side value below.
	 *   2. Server daily hash (anon_<sha256(IP + UA + site_url + daily_salt)>)
	 *      — used when the client supplied nothing (curl, very old browsers,
	 *      blocked storage). No cookie is read: Anonymous Mode is cookieless and
	 *      the optibehavior_anon_vid cookie is no longer written.
	 *
	 * Bug #R4 — replaces the previous unconditional override that collapsed
	 * every private-browser session on the same machine + same UA + same UTC
	 * day into the same visitor_id, blocking new rows in
	 * wp_optibehavior_ab_impressions via the UNIQUE KEY (test_id, visitor_id)
	 * and freezing the dashboard's "Total Visitors" counter.
	 *
	 * @since 1.0.7
	 * @param string $client_visitor_id Pre-sanitized visitor_id pulled from the request payload.
	 * @return string Canonical visitor_id for downstream storage.
	 */
	private function resolve_anon_visitor_id( $client_visitor_id ) {
		if ( ! empty( $client_visitor_id ) && 0 === strpos( $client_visitor_id, 'anon_' ) ) {
			return $client_visitor_id;
		}

		$session_handler = $this->core->get_session();
		if ( $session_handler && method_exists( $session_handler, 'get_anonymous_hash' ) ) {
			return $session_handler->get_anonymous_hash();
		}
		if ( $session_handler && method_exists( $session_handler, 'get_visitor_id' ) ) {
			return $session_handler->get_visitor_id();
		}

		return $client_visitor_id;
	}


	/**
	 * Get client IP address
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				$server_value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				foreach ( explode( ',', $server_value ) as $ip ) {
					$ip = trim( $ip );
					// Accept any valid IP address (including private ranges for local development)
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) {
						return $ip;
					}
				}
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Geolocate IP address
	 *
	 * @param string $ip IP address.
	 * @return array
	 */
	private function geolocate_ip( $ip, $client_timezone = '' ) {
		// Determine privacy mode once up-front.
		// In anonymous mode, Layers 3 and 3.5 (ip-api.com / ipwho.is) are skipped
		// entirely so that no visitor IP is ever sent to a third-party service.
		$is_anonymous_mode = ( 'anonymous' === $this->get_privacy_mode() );

		// === Layer 1: CloudFlare CF-IPCountry header (instant, free) ===
		if ( isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$cf_country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( $cf_country && $cf_country !== 'XX' ) {
				return array(
					'country'      => $cf_country,
					'country_name' => $this->get_country_name( $cf_country ),
					'region'       => '',
					'city'         => '',
					'timezone'     => $client_timezone ?: '',
					'source'       => 'cloudflare',
				);
			}
		}

		// For local/private IPs, try timezone and browser language fallbacks
		if ( $this->is_private_ip( $ip ) ) {
			// Try timezone-based detection for local IPs
			if ( ! empty( $client_timezone ) ) {
				$tz_result = $this->get_country_from_timezone( $client_timezone );
				if ( ! empty( $tz_result['country'] ) ) {
					return $tz_result;
				}
			}
			// Try browser language as last resort for local IPs
			$browser_result = $this->detect_country_from_browser();
			if ( ! empty( $browser_result['country'] ) ) {
				return $browser_result;
			}
			return array(
				'country'      => null,
				'country_name' => null,
				'region'       => null,
				'city'         => null,
				'timezone'     => $client_timezone ?: '',
				'source'       => 'local',
			);
		}

		// === Layer 2: WordPress transient cache (1 hour TTL) ===
		$cache_key = 'opti_geo_' . md5( $ip );
		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			// Return successful cached result (has country)
			if ( ! empty( $cached['country'] ) ) {
				return $cached;
			}
			// Previously failed result is cached — skip the API call to avoid
			// blocking a PHP worker for 3s on every request. Fall through to
			// instant timezone/browser fallbacks only.
			$api_failed_recently = true;
		} else {
			$api_failed_recently = false;
		}

		// === Layer 3: ip-api.com API call (rate-limited: 45 req/min) ===
		// Free for non-commercial use, no API key required
		// IMPORTANT: Free tier only supports HTTP (not HTTPS)
		// Server-side call so no mixed content issues
		// Skip if a recent attempt already failed (error cached) to protect PHP workers.
		// Also skip entirely in anonymous mode to avoid sending visitor IPs externally.
		if ( $is_anonymous_mode ) {
			$api_failed_recently = true;
		}
		if ( ! $api_failed_recently ) {
			$api_url = 'http://ip-api.com/json/' . $ip . '?fields=status,message,countryCode,country,regionName,city,timezone';

			$response = wp_remote_get( $api_url, array(
				'timeout' => 3,
				'headers' => array(
					'Accept' => 'application/json',
				),
			) );

			if ( ! is_wp_error( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body, true );

				// Log the API response for debugging
				if ( $this->core ) {
					$debug_manager = $this->core->get_debug_manager();
					if ( $debug_manager ) {
						$debug_manager->log( 'Geolocation API response for IP ' . $ip . ': ' . wp_json_encode( $data ), 'debug', 'geolocation' );
					}
				}

				if ( isset( $data['status'] ) && $data['status'] === 'success' ) {
					$result = array(
						'country'      => isset( $data['countryCode'] ) ? strtoupper( sanitize_text_field( $data['countryCode'] ) ) : null,
						'country_name' => isset( $data['country'] ) ? sanitize_text_field( $data['country'] ) : null,
						'region'       => isset( $data['regionName'] ) ? sanitize_text_field( $data['regionName'] ) : null,
						'city'         => isset( $data['city'] ) ? sanitize_text_field( $data['city'] ) : null,
						'timezone'     => isset( $data['timezone'] ) ? sanitize_text_field( $data['timezone'] ) : ( $client_timezone ?: '' ),
						'source'       => 'ip-api',
					);
					// Cache for 1 hour
					set_transient( $cache_key, $result, HOUR_IN_SECONDS );
					return $result;
				}

				// Log API error
				if ( $this->core ) {
					$debug_manager = $this->core->get_debug_manager();
					if ( $debug_manager ) {
						$debug_manager->log( 'Geolocation API failed for IP ' . $ip . ': ' . ( isset( $data['message'] ) ? $data['message'] : 'Unknown error' ), 'error', 'geolocation' );
					}
				}
			} else {
				// Log WP error
				if ( $this->core ) {
					$debug_manager = $this->core->get_debug_manager();
					if ( $debug_manager ) {
						$debug_manager->log( 'Geolocation API request error for IP ' . $ip . ': ' . $response->get_error_message(), 'error', 'geolocation' );
					}
				}
			}
		}

		// === Layer 3.5: ipwho.is secondary API (free, HTTPS, no rate limit) ===
		// Fallback when ip-api.com is rate-limited (45 req/min on free tier).
		// ipwho.is has no rate limit and supports HTTPS.
		// Note: intentionally NOT guarded by $api_failed_recently — if ip-api.com
		// cached a failure, ipwho.is (with no rate limit) may still resolve it.
		// On success, the transient cache is updated with the correct country.
		// Skipped entirely in anonymous mode (no visitor IP sent to third parties).
		if ( ! $is_anonymous_mode ) {
			$ipwho_url = 'https://ipwho.is/' . $ip;

			$ipwho_response = wp_remote_get( $ipwho_url, array(
				'timeout' => 3,
				'headers' => array(
					'Accept' => 'application/json',
				),
			) );

			if ( ! is_wp_error( $ipwho_response ) ) {
				$ipwho_body = wp_remote_retrieve_body( $ipwho_response );
				$ipwho_data = json_decode( $ipwho_body, true );

				if ( $this->core ) {
					$debug_manager = $this->core->get_debug_manager();
					if ( $debug_manager ) {
						$debug_manager->log( 'ipwho.is API response for IP ' . $ip . ': ' . wp_json_encode( $ipwho_data ), 'debug', 'geolocation' );
					}
				}

				if ( isset( $ipwho_data['success'] ) && $ipwho_data['success'] === true && ! empty( $ipwho_data['country_code'] ) ) {
					$result = array(
						'country'      => strtoupper( sanitize_text_field( $ipwho_data['country_code'] ) ),
						'country_name' => isset( $ipwho_data['country'] ) ? sanitize_text_field( $ipwho_data['country'] ) : null,
						'region'       => isset( $ipwho_data['region'] ) ? sanitize_text_field( $ipwho_data['region'] ) : null,
						'city'         => isset( $ipwho_data['city'] ) ? sanitize_text_field( $ipwho_data['city'] ) : null,
						'timezone'     => isset( $ipwho_data['timezone'], $ipwho_data['timezone']['id'] ) ? sanitize_text_field( $ipwho_data['timezone']['id'] ) : ( $client_timezone ?: '' ),
						'source'       => 'ipwho',
					);
					// Cache for 1 hour
					set_transient( $cache_key, $result, HOUR_IN_SECONDS );
					return $result;
				}
			} else {
				if ( $this->core ) {
					$debug_manager = $this->core->get_debug_manager();
					if ( $debug_manager ) {
						$debug_manager->log( 'ipwho.is request error for IP ' . $ip . ': ' . $ipwho_response->get_error_message(), 'error', 'geolocation' );
					}
				}
			}
		}

		// === Layer 4: Timezone → Country mapping (instant, local, no API) ===
		if ( ! empty( $client_timezone ) ) {
			$tz_result = $this->get_country_from_timezone( $client_timezone );
			if ( ! empty( $tz_result['country'] ) ) {
				// Cache the timezone-based result for 1 hour (replaces failed cache entry)
				set_transient( $cache_key, $tz_result, HOUR_IN_SECONDS );
				return $tz_result;
			}
		}

		// === Layer 5: Browser Accept-Language header fallback ===
		$browser_result = $this->detect_country_from_browser();
		if ( ! empty( $browser_result['country'] ) ) {
			// Cache browser-based result for 1 hour (replaces failed cache entry)
			set_transient( $cache_key, $browser_result, HOUR_IN_SECONDS );
			return $browser_result;
		}

		// All methods failed — cache failure for 30 minutes to prevent
		// re-hitting the API on every request (protects PHP worker pool)
		$result = array(
			'country'      => null,
			'country_name' => null,
			'region'       => null,
			'city'         => null,
			'timezone'     => $client_timezone ?: '',
			'source'       => 'failed',
		);
		set_transient( $cache_key, $result, 30 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Get country from IANA timezone using PHP's built-in DateTimeZone
	 *
	 * Maps timezone strings like 'Europe/Paris' → 'FR', 'Asia/Tokyo' → 'JP'.
	 * Uses PHP's DateTimeZone::getLocation() — no external API needed.
	 *
	 * @param string $timezone IANA timezone string (e.g., 'Europe/Paris').
	 * @return array Country data array.
	 */
	private function get_country_from_timezone( $timezone ) {
		$timezone = sanitize_text_field( $timezone );

		if ( empty( $timezone ) ) {
			return array(
				'country'      => null,
				'country_name' => null,
				'region'       => null,
				'city'         => null,
				'timezone'     => '',
				'source'       => 'timezone_empty',
			);
		}

		try {
			$dtz = new \DateTimeZone( $timezone );
			$location = $dtz->getLocation();

			if ( $location && ! empty( $location['country_code'] ) && $location['country_code'] !== '??' ) {
				$country_code = strtoupper( $location['country_code'] );
				$country_name = $this->get_country_name( $country_code );

				return array(
					'country'      => $country_code,
					'country_name' => $country_name,
					'region'       => null,
					'city'         => null,
					'timezone'     => $timezone,
					'source'       => 'timezone',
				);
			}
		} catch ( \Exception $e ) {
			// Invalid timezone string — fall through
			if ( $this->core ) {
				$debug_manager = $this->core->get_debug_manager();
				if ( $debug_manager ) {
					$debug_manager->log( 'Invalid timezone for geo lookup: ' . $timezone . ' — ' . $e->getMessage(), 'debug', 'geolocation' );
				}
			}
		}

		return array(
			'country'      => null,
			'country_name' => null,
			'region'       => null,
			'city'         => null,
			'timezone'     => $timezone,
			'source'       => 'timezone_failed',
		);
	}

	/**
	 * Check if IP is private/local
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private function is_private_ip( $ip ) {
		// Check for localhost
		if ( $ip === '127.0.0.1' || $ip === '::1' ) {
			return true;
		}

		// Check if it's an IPv6 address
		if ( strpos( $ip, ':' ) !== false ) {
			// IPv6 private ranges
			$ipv6_private_prefixes = array(
				'fc00:', // Unique local addresses
				'fd00:', // Unique local addresses
				'fe80:', // Link-local addresses
			);

			foreach ( $ipv6_private_prefixes as $prefix ) {
				if ( stripos( $ip, $prefix ) === 0 ) {
					return true;
				}
			}

			// Public IPv6 addresses should use geolocation
			return false;
		}

		// Check for private IPv4 ranges
		$private_ranges = array(
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
		);

		foreach ( $private_ranges as $range ) {
			if ( $this->ip_in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if IP is in CIDR range
	 *
	 * @param string $ip IP address.
	 * @param string $range CIDR range.
	 * @return bool
	 */
	private function ip_in_range( $ip, $range ) {
		list( $subnet, $mask ) = explode( '/', $range );
		$ip_long = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		$mask_long = -1 << ( 32 - (int) $mask );
		$subnet_long &= $mask_long;
		return ( $ip_long & $mask_long ) === $subnet_long;
	}

	/**
	 * Get country from browser Accept-Language header
	 *
	 * @return array
	 */
	private function get_country_from_browser_language() {
		$country = null;
		$country_name = null;

		// Get Accept-Language header
		if ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$accept_language = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );

			// Parse Accept-Language header (e.g., "fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7")
			// Extract the first language code
			if ( preg_match( '/^([a-z]{2})-([A-Z]{2})/', $accept_language, $matches ) ) {
				// Full locale format (e.g., "fr-FR")
				$country = strtoupper( $matches[2] );
				$country_name = $this->get_country_name( $country );
			} elseif ( preg_match( '/^([a-z]{2})/', $accept_language, $matches ) ) {
				// Language code only (e.g., "fr")
				// Map common language codes to countries
				$lang_to_country = array(
					'fr' => 'FR',
					'de' => 'DE',
					'es' => 'ES',
					'it' => 'IT',
					'pt' => 'PT',
					'nl' => 'NL',
					'pl' => 'PL',
					'ru' => 'RU',
					'ja' => 'JP',
					'zh' => 'CN',
					'ko' => 'KR',
					'ar' => 'SA',
					'en' => 'US',
				);

				$lang = $matches[1];
				if ( isset( $lang_to_country[ $lang ] ) ) {
					$country = $lang_to_country[ $lang ];
					$country_name = $this->get_country_name( $country );
				}
			}
		}

		return array(
			'country'      => $country,
			'country_name' => $country_name,
			'region'       => null,
			'city'         => null,
			'timezone'     => null,
			'source'       => 'browser_language',
		);
	}

	/**
	 * Handle session start data from recorder
	 */
	private function handle_recorder_session_start( $data, $session_id ) {
		global $wpdb;
		$debug_manager = $this->core->get_debug_manager();
		$analytics = $this->core->get_analytics();

		$debug_manager->log( 'Handling session start for session: ' . $session_id, 'debug', 'ajax' );

		// Get visitor ID
		$visitor_id = isset( $data['visitorId'] ) ? $data['visitorId'] : ( isset( $data['visitor_id'] ) ? $data['visitor_id'] : null );

		if ( ! $visitor_id ) {
			$debug_manager->log( 'No visitor ID found in session start data', 'warning', 'ajax' );
			wp_send_json_error( __( 'No visitor ID', 'opti-behavior' ) );
			return;
		}

		// Bug #R4 — see resolve_anon_visitor_id() docblock. Prefer the client-supplied
		// anon_ id from the POST body over recomputing the server daily hash. In
		// cookieless Anonymous Mode the tracker already sources that id from the
		// server daily hash (optiBehaviorHeatmapConfig.anon_vid), so every tab of the
		// same browser converges on the same value — cross-tab dedup holds without
		// any cookie or server override.
		$is_effectively_anon = ! is_user_logged_in() && (
			'anonymous' === $this->get_privacy_mode()
			|| ( 'full' === $this->get_privacy_mode() && ! $this->visitor_has_consent() )
		);
		if ( $is_effectively_anon ) {
			$visitor_id = $this->resolve_anon_visitor_id( $visitor_id );
		}

		// -----------------------------------------------------------------------
		// Multi-tab session deduplication: when two tabs open simultaneously and
		// each generates a different session_id, select the earliest active session
		// for this visitor (within 30 min) as the canonical one.  The "losing" tab
		// is redirected to the canonical session so all tracking data (sessions,
		// recordings, heatmap events) are consolidated under one session.
		// -----------------------------------------------------------------------
		if ( ! empty( $visitor_id ) && ! empty( $session_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$canonical_sid = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}optibehavior_sessions
				 WHERE visitor_id = %s
				 AND start_time >= DATE_SUB( NOW(), INTERVAL 30 MINUTE )
				 ORDER BY start_time ASC, id ASC
				 LIMIT 1",
				$visitor_id
			) );
			if ( ! empty( $canonical_sid ) && $canonical_sid !== $session_id ) {
				$session_id = $canonical_sid;
			}
		}

		// Extract location data from client
		$location_data = isset( $data['location'] ) ? $data['location'] : array();
		$country = isset( $location_data['country'] ) ? strtoupper( $location_data['country'] ) : null;
		$country_name = isset( $location_data['country_name'] ) ? $location_data['country_name'] : null;
		$region = isset( $location_data['region'] ) ? $location_data['region'] : null;
		$city = isset( $location_data['city'] ) ? $location_data['city'] : null;
		$timezone = isset( $location_data['timezone'] ) ? $location_data['timezone'] : null;

		// Extract client timezone from session_start data (sent by frontend JS)
		$client_timezone = '';
		if ( ! empty( $data['timezone'] ) ) {
			$client_timezone = sanitize_text_field( $data['timezone'] );
		}
		// Use client timezone if location timezone is empty
		if ( empty( $timezone ) && ! empty( $client_timezone ) ) {
			$timezone = $client_timezone;
		}

		// Try server-side IP geolocation only if client didn't provide valid country data.
		// GDPR: IP is used for geolocation only, then discarded — never stored in anonymous mode.
		// The IP itself is stored as 'Anonymous' in the session record (see ensure_session_exists).
		if ( empty( $country ) || $country === 'UN' ) {
			$ip = $this->get_client_ip();
			$geo_data = $this->geolocate_ip( $ip, $client_timezone );

			// Only use geo_data if it provides a valid country (not null, not 'UN')
			if ( ! empty( $geo_data['country'] ) && $geo_data['country'] !== 'UN' && $geo_data['country'] !== null ) {
				$country = $geo_data['country'];
				$country_name = ! empty( $geo_data['country_name'] ) ? $geo_data['country_name'] : '';
				$region = ! empty( $geo_data['region'] ) ? $geo_data['region'] : null;
				$city = ! empty( $geo_data['city'] ) ? $geo_data['city'] : null;
				$timezone = ! empty( $geo_data['timezone'] ) ? $geo_data['timezone'] : $timezone;
			}
		}

		// If country code exists but country_name is empty, resolve it from the code
		if ( ! empty( $country ) && empty( $country_name ) && $country !== 'UN' ) {
			$country_name = $this->get_country_name( $country );
		}

		// Final fallback: if still no valid country, don't store 'UN' - leave as null
		// This allows the system to retry detection on next visit
		if ( empty( $country ) || $country === 'UN' ) {
			$country = null;
			$country_name = null;
		}

		$user_agent = isset( $data['userAgent'] ) ? sanitize_text_field( $data['userAgent'] ) : '';
		if ( '' === $user_agent && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}
		$user_agent_metadata = $this->parse_user_agent( $user_agent );
		$browser             = isset( $data['device']['browser'] ) ? sanitize_text_field( $data['device']['browser'] ) : '';
		$os                  = isset( $data['device']['os'] ) ? sanitize_text_field( $data['device']['os'] ) : '';
		$device_type         = isset( $data['device']['deviceType'] ) ? sanitize_text_field( $data['device']['deviceType'] ) : '';

		if ( $this->is_unknown_dimension_value( $browser ) && ! $this->is_unknown_dimension_value( $user_agent_metadata['browser'] ) ) {
			$browser = $user_agent_metadata['browser'];
		}
		if ( $this->is_unknown_dimension_value( $os ) && ! $this->is_unknown_dimension_value( $user_agent_metadata['os'] ) ) {
			$os = $user_agent_metadata['os'];
		}
		if ( $this->is_unknown_dimension_value( $device_type ) && ! $this->is_unknown_dimension_value( $user_agent_metadata['device_type'] ) ) {
			$device_type = strtolower( $user_agent_metadata['device_type'] );
		}

		$browser     = $this->is_unknown_dimension_value( $browser ) ? 'Unknown' : $browser;
		$os          = $this->is_unknown_dimension_value( $os ) ? 'Unknown' : $os;
		$device_type = $this->is_unknown_dimension_value( $device_type ) ? 'unknown' : $device_type;

		// Create or update visitor record
		$visitor_data = array(
			'id'           => $visitor_id,
			'first_visit'  => current_time( 'mysql' ),
			'last_visit'   => current_time( 'mysql' ),
			'visit_count'  => 1,
			'device_type'  => $device_type,
			'browser'      => $browser,
			'os'           => $os,
			'screen_width' => isset( $data['screen_width'] ) ? intval( $data['screen_width'] ) : null,
			'screen_height' => isset( $data['screen_height'] ) ? intval( $data['screen_height'] ) : null,
			'country'      => $country,
			'country_name' => $country_name,
			'region'       => $region,
			'city'         => $city,
			'timezone'     => $timezone,
		);

		// Check if visitor exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$existing_visitor = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}optibehavior_visitors WHERE id = %s",
			$visitor_id
		) );

		if ( $existing_visitor ) {
			// Update existing visitor with device and location data
			// NOTE: visit_count is NOT incremented here — it is only incremented
			// when a genuinely NEW session is created (see below after session existence check).
			$update_data = array(
				'last_visit'  => current_time( 'mysql' ),
			);
			$update_format = array( '%s' );

			if ( ! $this->is_unknown_dimension_value( $device_type ) ) {
				$update_data['device_type'] = $device_type;
				$update_format[] = '%s';
			}
			if ( ! $this->is_unknown_dimension_value( $browser ) ) {
				$update_data['browser'] = $browser;
				$update_format[] = '%s';
			}
			if ( ! $this->is_unknown_dimension_value( $os ) ) {
				$update_data['os'] = $os;
				$update_format[] = '%s';
			}

			// Only update screen resolution when a valid (non-zero) value is provided
			// and the existing record does not already have one. This mirrors the guard in
			// ensure_visitor_exists() and prevents a race condition where a late session_start
			// with missing dimensions could zero-out a previously stored resolution.
			$new_screen_width  = isset( $data['screen_width'] ) ? intval( $data['screen_width'] ) : 0;
			$new_screen_height = isset( $data['screen_height'] ) ? intval( $data['screen_height'] ) : 0;
			if ( $new_screen_width > 0 && $new_screen_height > 0 &&
				( $existing_visitor->screen_width === null || $existing_visitor->screen_height === null ) ) {
				$update_data['screen_width']  = $new_screen_width;
				$update_data['screen_height'] = $new_screen_height;
				$update_format[]              = '%d';
				$update_format[]              = '%d';
			}

			// Add location data to update if available
			if ( $country ) {
				$update_data['country'] = $country;
				$update_data['country_name'] = $country_name;
				$update_data['region'] = $region;
				$update_data['city'] = $city;
				$update_data['timezone'] = $timezone;
				$update_format = array_merge( $update_format, array( '%s', '%s', '%s', '%s', '%s' ) );
			}

			$wpdb->update(
				"{$wpdb->prefix}optibehavior_visitors",
				$update_data,
				array( 'id' => $visitor_id ),
				$update_format,
				array( '%s' )
			);
		} else {
			// Insert new visitor
			$wpdb->insert(
				"{$wpdb->prefix}optibehavior_visitors",
				$visitor_data,
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		// Create or update session record
		$session_url = isset( $data['url'] ) ? $data['url'] : ( isset( $data['entry_page'] ) ? $data['entry_page'] : '' );
		$session_title = isset( $data['title'] ) ? $data['title'] : '';

		// Check if session already exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$existing_session = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
			$session_id
		) );

		if ( $existing_session ) {
			// Update existing session (update end_time to track activity).
			// Backfill user_id when the row was originally created as guest but
			// the current request is authoritatively logged-in — keeps the DB
			// user_id in lock-step with the L/G file marker and prevents the
			// segment filters from giving inconsistent counts.
			$update_data    = array( 'end_time' => current_time( 'mysql' ) );
			$update_formats = array( '%s' );

			$current_uid = $this->resolve_request_user_id();
			if ( $current_uid > 0 && ( $existing_session->user_id === null || (int) $existing_session->user_id === 0 ) ) {
				$update_data['user_id'] = $current_uid;
				$update_formats[]       = '%d';
			}

			$wpdb->update(
				"{$wpdb->prefix}optibehavior_sessions",
				$update_data,
				array( 'id' => $session_id ),
				$update_formats,
				array( '%s' )
			);
			$debug_manager->log( 'Session updated (existing session): ' . $session_id, 'debug', 'ajax' );
		} else {
			// Insert new session.
			// Capture WordPress user ID using the JS-flag-primary resolver so the
			// DB row stays consistent with the L/G marker in heatmap file storage.
			$user_id = $this->resolve_request_user_id();

			// Determine if this visitor is effectively anonymous:
			// Either the global setting is 'anonymous', OR we are in Full mode but the
			// visitor has not granted consent yet.  Note: we intentionally do NOT use the
			// visitor_id prefix (anon_) for this check — that prefix is preserved by the
			// consent-transition migration and would incorrectly mark consented visitors
			// as anonymous.
			$is_effectively_anonymous = ( 'anonymous' === $this->get_privacy_mode() )
				|| ( 'full' === $this->get_privacy_mode() && ! $this->visitor_has_consent() );

			$session_data = array(
				'id'         => $session_id,
				'visitor_id' => $visitor_id,
				'user_id'    => $user_id,
				'start_time' => current_time( 'mysql' ),
				'referrer'   => isset( $data['referrer'] ) ? $data['referrer'] : '',
				'entry_page' => $session_url,
				// In anonymous mode, store 'Anonymous' instead of real IP for analytics counting.
				'ip'         => $is_effectively_anonymous ? 'Anonymous' : $this->get_client_ip(),
				'is_bounce'  => 1,
			);

			$session_inserted = $wpdb->insert(
				"{$wpdb->prefix}optibehavior_sessions",
				$session_data,
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
			);

			// Do NOT mask a failed write as success. When the analytics tables are
			// missing (e.g. the SQLite GET_LOCK setup regression) insert() returns
			// false; previously this method still emitted wp_send_json_success()
			// below, yielding the "on ne détecte rien" false positive (HTTP 200,
			// zero rows). Surface the failure with a real error instead.
			if ( false === $session_inserted ) {
				$debug_manager->log(
					'Session insert FAILED for ' . $session_id . '. DB error: ' . $wpdb->last_error,
					'error',
					'ajax'
				);
				wp_send_json_error(
					array(
						'message' => __( 'Session could not be recorded', 'opti-behavior' ),
						'reason'  => 'db_insert_failed',
					),
					500
				);
				return;
			}
			$debug_manager->log( 'New session created: ' . $session_id, 'debug', 'ajax' );

			// Increment visit_count only when a genuinely new session is created AND
			// the visitor already existed before (not just created above with visit_count=1).
			// This ensures a brand-new visitor's first session keeps visit_count=1 ("New"),
			// while truly returning visitors (existing record) get incremented ("Returning").
			if ( $existing_visitor ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}optibehavior_visitors SET visit_count = visit_count + 1 WHERE id = %s",
					$visitor_id
				) );
			}
		}

		// Create initial pageview record so real-time visitors always have a page title
		if ( ! empty( $session_url ) ) {
			$page_id = $analytics->get_or_create_page_id( $session_url, $session_title );

			// Use ensure_pageview_exists to avoid duplicates
			$this->ensure_pageview_exists( $session_id, $visitor_id, $page_id, $session_url, $session_title );

			$debug_manager->log( 'Initial pageview ensured for session start', 'debug', 'ajax' );
		}

		$debug_manager->log( 'Session start processed successfully', 'debug', 'ajax' );
		wp_send_json_success(
			array(
				'message'    => __( 'Session started', 'opti-behavior' ),
				'visitor_id' => $visitor_id,
				'session_id' => $session_id,
			)
		);
	}

	/**
	 * Handle events data from recorder
	 */
	private function handle_recorder_events( $data, $session_id ) {
		global $wpdb;
		$debug_manager = $this->core->get_debug_manager();
		$analytics = $this->core->get_analytics();

		$debug_manager->log( 'Handling events for session: ' . $session_id, 'debug', 'ajax' );

		if ( ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
			$debug_manager->log( 'No events array found', 'warning', 'ajax' );
			wp_send_json_error( __( 'No events data', 'opti-behavior' ) );
			return;
		}

		$events_processed = 0;
		foreach ( $data['events'] as $event ) {
			if ( ! isset( $event['type'] ) ) {
				continue;
			}

			// Create page view record for page_load events
			if ( $event['type'] === 'page_load' && isset( $event['data']['url'] ) ) {
				$page_id = $analytics->get_or_create_page_id( $event['data']['url'], $event['data']['title'] ?? '' );
				$visitor_id_for_pageview = isset( $data['visitorId'] ) ? $data['visitorId'] : '';

				// In anonymous mode, override the JS-provided visitor_id with the stable server-side
				// daily hash — keeps visitor identity consistent across all AJAX handlers.
				if ( 'anonymous' === $this->get_privacy_mode() && ! is_user_logged_in() ) {
					$session_handler         = $this->core->get_session();
					$visitor_id_for_pageview = $session_handler->get_visitor_id();
				}

				// Use ensure_pageview_exists to avoid duplicates
				$this->ensure_pageview_exists( $session_id, $visitor_id_for_pageview, $page_id, $event['data']['url'], $event['data']['title'] ?? '' );

				// Update bounce status: if page_views > 1, it's not a bounce
				// This ensures bounce is correctly set to 0 when user views multiple pages
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
				$updated_page_views = $wpdb->get_var( $wpdb->prepare(
					"SELECT page_views FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
					$session_id
				) );

				if ( $updated_page_views > 1 ) {
					$wpdb->update(
						"{$wpdb->prefix}optibehavior_sessions",
						array( 'is_bounce' => 0 ),
						array( 'id' => $session_id ),
						array( '%d' ),
						array( '%s' )
					);
					$debug_manager->log( 'Bounce status updated to 0 for multi-page session: ' . $session_id, 'debug', 'ajax' );
				}

				$debug_manager->log( 'Page view recorded and session page_views incremented for session: ' . $session_id, 'debug', 'ajax' );
			}

			// Handle click events for heatmaps
			if ( $event['type'] === 'click' && isset( $event['data']['x'], $event['data']['y'] ) ) {
				// A click indicates engagement - session is not a bounce
				$wpdb->update(
					"{$wpdb->prefix}optibehavior_sessions",
					array( 'is_bounce' => 0 ),
					array( 'id' => $session_id ),
					array( '%d' ),
					array( '%s' )
				);
				$debug_manager->log( 'Bounce status updated to 0 due to click engagement for session: ' . $session_id, 'debug', 'ajax' );

				$current_url = isset( $event['data']['url'] ) ? $event['data']['url'] : ( isset( $data['url'] ) ? $data['url'] : '' );
				if ( empty( $current_url ) ) {
					$current_url = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
				}

				if ( ! empty( $current_url ) ) {
					$page_id = $analytics->get_or_create_page_id( $current_url, $event['data']['title'] ?? '' );

					// Determine if mobile or desktop based on screen width or user agent
					$is_mobile = false;
					if ( isset( $event['data']['screenWidth'] ) ) {
						$is_mobile = $event['data']['screenWidth'] <= 768;
					} else {
						// Fallback to user agent detection
						$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
						$is_mobile = preg_match( '/Mobile|Android|iPhone|iPad/', $user_agent );
					}

					// Use the same event constants as the original system
					$event_type = $is_mobile ? 0x11 : 0x10; // CLICK_MOBILE : CLICK_PC

					// Extract raw pixel values and document dimensions
					$raw_x    = isset( $event['data']['x'] ) ? intval( $event['data']['x'] ) : 0;
					$raw_y    = isset( $event['data']['y'] ) ? intval( $event['data']['y'] ) : 0;
					$doc_w    = isset( $event['data']['screenWidth'] ) ? intval( $event['data']['screenWidth'] ) : 1920;
					$doc_h    = isset( $event['data']['screenHeight'] ) ? intval( $event['data']['screenHeight'] ) : 1080;

					// Compute normalized coordinates to make clicks consistent across screen sizes
					$x_norm = ( $doc_w > 0 ) ? ( $raw_x / $doc_w ) : null;
					$y_norm = ( $doc_h > 0 ) ? ( $raw_y / $doc_h ) : null;

					$click_data = array(
						'page_id'         => $page_id,
						'page_id2'        => $page_id,
						'session_id'      => $session_id,
						'event'           => $event_type,
						'x'               => $raw_x,
						'y'               => $raw_y,
						'width'           => $doc_w,
						'height'          => $doc_h,
						'x_normalized'    => is_null( $x_norm ) ? null : (float) $x_norm,
						'y_normalized'    => is_null( $y_norm ) ? null : (float) $y_norm,
						'viewport_width'  => isset( $event['data']['viewportWidth'] ) ? intval( $event['data']['viewportWidth'] ) : null,
						'viewport_height' => isset( $event['data']['viewportHeight'] ) ? intval( $event['data']['viewportHeight'] ) : null,
						'element_tag'     => isset( $event['data']['element']['tagName'] ) ? $event['data']['element']['tagName'] : null,
						'element_id'      => isset( $event['data']['element']['id'] ) ? $event['data']['element']['id'] : null,
						'element_class'   => isset( $event['data']['element']['className'] ) ? $event['data']['element']['className'] : null,
						'insert_at'       => current_time( 'mysql' ),
					);

					// Save click data to database for session recording
					$result = $wpdb->insert(
						"{$wpdb->prefix}optibehavior_events",
						$click_data,
						array( '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%f', '%f', '%d', '%d', '%s', '%s', '%s', '%s' )
					);

					if ( $result === false ) {
						$debug_manager->log( 'Click event insert failed - Error: ' . $wpdb->last_error, 'error', 'ajax' );
						$debug_manager->log( 'Failed query: ' . $wpdb->last_query, 'error', 'ajax' );
					} else {
						$debug_manager->log( 'Click event recorded successfully - ID: ' . $wpdb->insert_id . ' X:' . $click_data['x'] . ' Y:' . $click_data['y'] . ' Event:' . $event_type, 'debug', 'ajax' );

						// Increment events_count in sessions table for proper tracking
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
						$wpdb->query(
							$wpdb->prepare(
								"UPDATE {$wpdb->prefix}optibehavior_sessions SET events_count = events_count + 1 WHERE id = %s",
								$session_id
							)
						);
					}

					$debug_manager->log( 'Click event recorded - X:' . $click_data['x'] . ' Y:' . $click_data['y'] . ' Event:' . $event_type, 'debug', 'ajax' );
				}
			}

			// Handle scroll events for scroll depth tracking
			if ( $event['type'] === 'scroll' && isset( $event['data']['scrollY'], $event['data']['scrollHeight'] ) ) {
				$current_url = isset( $event['data']['url'] ) ? $event['data']['url'] : ( isset( $data['url'] ) ? $data['url'] : '' );
				if ( empty( $current_url ) ) {
					$current_url = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
				}

				if ( ! empty( $current_url ) ) {
					$page_id = $analytics->get_or_create_page_id( $current_url, $event['data']['title'] ?? '' );

					// Calculate scroll depth percentage
					$scroll_depth = 0;
					if ( isset( $event['data']['scrollHeight'] ) && $event['data']['scrollHeight'] > 0 ) {
						$scroll_position = $event['data']['scrollY'] + ( $event['data']['clientHeight'] ?? 0 );
						$scroll_depth = min( 100, ( $scroll_position / $event['data']['scrollHeight'] ) * 100 );
					}

					// If user scrolled more than 25%, consider it engagement - not a bounce
					if ( $scroll_depth > 25 ) {
						$wpdb->update(
							"{$wpdb->prefix}optibehavior_sessions",
							array( 'is_bounce' => 0 ),
							array( 'id' => $session_id ),
							array( '%d' ),
							array( '%s' )
						);
						$debug_manager->log( 'Bounce status updated to 0 due to scroll engagement (' . intval( $scroll_depth ) . '%) for session: ' . $session_id, 'debug', 'ajax' );
					}

					// Update the pageview record with the latest scroll depth
					$wpdb->update(
						"{$wpdb->prefix}optibehavior_pageviews",
						array( 'scroll_depth' => intval( $scroll_depth ) ),
						array(
							'session_id' => $session_id,
							'page_id' => $page_id
						),
						array( '%d' ),
						array( '%s', '%d' )
					);

					$debug_manager->log( 'Scroll depth updated - ' . intval( $scroll_depth ) . '%', 'debug', 'ajax' );
				}
			}

			// Handle session end events for duration calculation
			if ( $event['type'] === 'page_unload' || $event['type'] === 'session_inactive' ) {
				$duration = 0;

				// First try: use timeOnPage from JavaScript if available
				if ( isset( $event['data']['timeOnPage'] ) ) {
					$duration = intval( $event['data']['timeOnPage'] / 1000 ); // Convert ms to seconds
				}

				// Fallback: calculate duration from database timestamps if JS data is missing or unrealistic
				if ( $duration <= 0 || $duration > 7200 ) { // If no duration or > 2 hours (unrealistic)
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
					$session_start = $wpdb->get_var( $wpdb->prepare(
						"SELECT start_time FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
						$session_id
					) );

					if ( $session_start ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics queries.
						$duration = intval( $wpdb->get_var( $wpdb->prepare(
							"SELECT TIMESTAMPDIFF(SECOND, %s, NOW())",
							$session_start
						) ) );

						// Cap duration at 2 hours for realistic values
						$duration = min( $duration, 7200 );
					}
				}

				$stored_duration = intval(
					$wpdb->get_var(
						$wpdb->prepare(
							"SELECT COALESCE(duration, 0) FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
							$session_id
						)
					)
				);
				$duration        = min( 7200, max( $duration, $stored_duration ) );

				// Calculate bounce rate - session is a bounce if it's a single-page session
				// Standard definition: A bounce is when a visitor views only one page
				$is_bounce = 0;

				// Get the page_views count from the session record
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
				$pageview_count = $wpdb->get_var( $wpdb->prepare(
					"SELECT page_views FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
					$session_id
				) );

				// It's a bounce if only one page was viewed AND no prior engagement was detected
				// Don't override is_bounce = 0 that was set by scroll/click handlers
				// First, check current bounce status to preserve engagement flags
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
				$current_bounce = $wpdb->get_var( $wpdb->prepare(
					"SELECT is_bounce FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
					$session_id
				) );

				if ( $pageview_count <= 1 && intval( $current_bounce ) !== 0 ) {
					$is_bounce = 1;
				} else if ( intval( $current_bounce ) === 0 ) {
					// Preserve engagement flag - user scrolled > 25% or clicked
					$is_bounce = 0;
				}

				// Update session duration and bounce status.
				// IMPORTANT: sessions are created with is_bounce = 1 by default, so
				// this handler only ever needs to WRITE is_bounce = 0. Writing 1 here
				// re-introduced a race: the unload beacon and the click/scroll event
				// beacon fire in parallel on pagehide, and the unload request could
				// read the pre-engagement value (1) and overwrite the engagement
				// handler's 0 after it committed. Never lowering engagement makes the
				// write order irrelevant.
				$session_end_update  = array(
					'duration' => $duration,
					'end_time' => current_time( 'mysql' ),
				);
				$session_end_formats = array( '%d', '%s' );
				if ( 0 === $is_bounce ) {
					$session_end_update['is_bounce'] = 0;
					$session_end_formats[]           = '%d';
				}
				$wpdb->update(
					"{$wpdb->prefix}optibehavior_sessions",
					$session_end_update,
					array( 'id' => $session_id ),
					$session_end_formats,
					array( '%s' )
				);

				// Record a synthetic "Left page" exit when there was no outbound click for this page in this session
				if ( $event['type'] === 'page_unload' && isset( $event['data']['url'] ) && ! empty( $event['data']['url'] ) ) {
					$current_url = $event['data']['url'];
					$page_id_for_exit = $analytics->get_or_create_page_id( $current_url, $event['data']['title'] ?? '' );
					if ( $page_id_for_exit ) {
						// If client hinted an explicit exit (e.g., link click), skip synthetic 'Left page'
						if ( isset( $_COOKIE['optibehavior_exit'] ) && $_COOKIE['optibehavior_exit'] === '1' ) {
							// Do nothing; outbound/new-tab/internal navigation already recorded as exit
						} else {
							// Race-condition guard: give a brief window for an outbound_click to arrive first
							$has_recent_outbound = 0;
							for ( $attempt = 0; $attempt < 20; $attempt++ ) { // up to ~2s total
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
								$has_recent_outbound = intval( $wpdb->get_var( $wpdb->prepare(
									"SELECT COUNT(*) FROM {$wpdb->prefix}optibehavior_outbound_clicks WHERE session_id = %s AND page_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
									$session_id,
									$page_id_for_exit
								) ) );
								if ( $has_recent_outbound > 0 ) {
									break; // outbound arrived; don't create synthetic left
								}
								usleep( 100000 ); // 100ms
							}
							if ( $has_recent_outbound === 0 ) {
								// De-duplicate: avoid inserting multiple 'Left page' rows for same session+page within 2 minutes
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
								$has_recent_left = intval( $wpdb->get_var( $wpdb->prepare(
									"SELECT COUNT(*) FROM {$wpdb->prefix}optibehavior_outbound_clicks WHERE session_id = %s AND page_id = %d AND target_url = 'Left page' AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)",
									$session_id,
									$page_id_for_exit
								) ) );
								if ( $has_recent_left === 0 ) {
									$wpdb->insert(
										"{$wpdb->prefix}optibehavior_outbound_clicks",
										array(
											'session_id'  => $session_id,
											'page_id'     => $page_id_for_exit,
											'source_url'  => $current_url,
											'target_url'  => 'Left page',
											'click_type'  => 'left',
											'created_at'  => current_time( 'mysql' ),
										),
										array( '%s', '%d', '%s', '%s', '%s', '%s' )
									);
								}
							}
						}
					}
				}

				$debug_manager->log( 'Session updated - Duration: ' . $duration . 's, Bounce: ' . $is_bounce, 'debug', 'ajax' );
			}

			$events_processed++;
		}

		$debug_manager->log( 'Processed ' . $events_processed . ' events', 'debug', 'ajax' );
		if ( $events_processed > 0 ) {
			$this->clear_behavior_classification_caches();
		}
		/* translators: %d: number of events processed */
		wp_send_json_success( sprintf( __( 'Events processed: %d', 'opti-behavior' ), $events_processed ) );
	}

	/**
	 * Handle page load data from recorder
	 */
	private function handle_recorder_page_load( $data, $session_id ) {
		global $wpdb;
		$debug_manager = $this->core->get_debug_manager();
		$analytics = $this->core->get_analytics();

		$debug_manager->log( 'Handling page load for session: ' . $session_id, 'debug', 'ajax' );

		if ( ! isset( $data['url'] ) ) {
			$debug_manager->log( 'No URL found in page load data', 'warning', 'ajax' );
			wp_send_json_error( __( 'No URL provided', 'opti-behavior' ) );
			return;
		}

		$page_id = $analytics->get_or_create_page_id( $data['url'], $data['title'] ?? '' );

		// In anonymous mode, override the JS-provided visitor_id with the stable server-side
		// daily hash — keeps visitor identity consistent across all AJAX handlers.
		$visitor_id_for_pageload = isset( $data['visitorId'] ) ? $data['visitorId'] : '';
		if ( 'anonymous' === $this->get_privacy_mode() && ! is_user_logged_in() ) {
			$session_handler         = $this->core->get_session();
			$visitor_id_for_pageload = $session_handler->get_visitor_id();
		}

		$pageview_data = array(
			'session_id' => $session_id,
			'visitor_id' => $visitor_id_for_pageload,
			'page_id'    => $page_id,
			'url'        => $data['url'],
			'title'      => $data['title'] ?? '',
			'view_time'  => current_time( 'mysql' ),
		);

		$wpdb->insert(
			"{$wpdb->prefix}optibehavior_pageviews",
			$pageview_data,
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		// This direct-insert path bypasses ensure_pageview_exists(), so mirror
		// its exit-page bookkeeping here.
		$this->sync_session_exit_page( $session_id, $data['url'] );

		$debug_manager->log( 'Page load processed successfully', 'debug', 'ajax' );
		wp_send_json_success( __( 'Page load recorded', 'opti-behavior' ) );
	}

	/**
	 * Handle referrer tracking data
	 *
	 * @param array  $data Session data.
	 * @param string $session_id Session ID.
	 */
	private function handle_referrer_tracking( $data, $session_id ) {
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'Processing referrer tracking', 'debug', 'ajax' );

		$analytics = $this->core->get_analytics();
		$session = $this->core->get_session();

		// Get or create page ID for the entry URL
		$page_id = $analytics->get_or_create_page_id( $data['entryUrl'], '' );

		$referrer_data = array(
			'session_id'   => $session_id,
			'page_id'      => $page_id,
			'entry_url'    => $data['entryUrl'],
			'referrer_url' => isset( $data['referrerUrl'] ) ? $data['referrerUrl'] : null,
			'utm_source'   => isset( $data['utmSource'] ) ? $data['utmSource'] : null,
			'utm_medium'   => isset( $data['utmMedium'] ) ? $data['utmMedium'] : null,
			'utm_campaign' => isset( $data['utmCampaign'] ) ? $data['utmCampaign'] : null,
			'utm_term'     => isset( $data['utmTerm'] ) ? $data['utmTerm'] : null,
			'utm_content'  => isset( $data['utmContent'] ) ? $data['utmContent'] : null,
		);

		$session->track_referrer( $referrer_data );

		$debug_manager->log( 'Referrer tracking processed successfully', 'debug', 'ajax' );
	}

	/**
	 * Handle outbound click data
	 *
	 * @param array  $data Session data.
	 * @param string $session_id Session ID.
	 */
	private function handle_outbound_click( $data, $session_id ) {
		$analytics = $this->core->get_analytics();
		$session = $this->core->get_session();

		// Get or create page ID for the source URL
		$page_id = $analytics->get_or_create_page_id( $data['sourceUrl'], '' );

		$click_data = array(
			'session_id'    => $session_id,
			'page_id'       => $page_id,
			'source_url'    => $data['sourceUrl'],
			'target_url'    => $data['targetUrl'],
			'element_tag'   => isset( $data['elementTag'] ) ? $data['elementTag'] : null,
			'element_id'    => isset( $data['elementId'] ) ? $data['elementId'] : null,
			'element_class' => isset( $data['elementClass'] ) ? $data['elementClass'] : null,
			'element_text'  => isset( $data['elementText'] ) ? $data['elementText'] : null,
			'x'             => isset( $data['x'] ) ? intval( $data['x'] ) : null,
			'y'             => isset( $data['y'] ) ? intval( $data['y'] ) : null,
		);

		$session->track_outbound_click( $click_data );
	}

	/**
	 * Get country name from country code (ISO alpha-2)
	 */
	private function get_country_name( $country_code ) {
		$country_code = strtoupper( trim( (string) $country_code ) );
		$countries = array(
			'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa',
			'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica',
			'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba',
			'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas',
			'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus',
			'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda',
			'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana',
			'BR' => 'Brazil', 'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
			'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cape Verde',
			'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile',
			'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo - Brazzaville',
			'CD' => 'Congo - Kinshasa', 'CR' => 'Costa Rica', 'CI' => 'Côte d’Ivoire', 'HR' => 'Croatia',
			'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DK' => 'Denmark', 'DJ' => 'Djibouti',
			'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt',
			'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia',
			'ET' => 'Ethiopia', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GA' => 'Gabon',
			'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GR' => 'Greece',
			'GD' => 'Grenada', 'GT' => 'Guatemala', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
			'HT' => 'Haiti', 'HN' => 'Honduras', 'HK' => 'Hong Kong SAR China', 'HU' => 'Hungary',
			'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq',
			'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan',
			'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KW' => 'Kuwait',
			'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho',
			'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
			'MO' => 'Macao SAR China', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives',
			'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MR' => 'Mauritania', 'MU' => 'Mauritius',
			'YT' => 'Mayotte', 'MX' => 'Mexico', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia',
			'ME' => 'Montenegro', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar (Burma)',
			'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NZ' => 'New Zealand',
			'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NO' => 'Norway', 'OM' => 'Oman',
			'PK' => 'Pakistan', 'PS' => 'Palestinian Territories', 'PA' => 'Panama', 'PG' => 'Papua New Guinea',
			'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal',
			'PR' => 'Puerto Rico', 'QA' => 'Qatar', 'RE' => 'Réunion', 'RO' => 'Romania', 'RU' => 'Russia',
			'RW' => 'Rwanda', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles',
			'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands',
			'SO' => 'Somalia', 'ZA' => 'South Africa', 'KR' => 'South Korea', 'SS' => 'South Sudan', 'ES' => 'Spain',
			'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria',
			'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste',
			'TG' => 'Togo', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkey',
			'TM' => 'Turkmenistan', 'TC' => 'Turks and Caicos Islands', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine',
			'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States', 'UY' => 'Uruguay',
			'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela', 'VN' => 'Vietnam', 'VG' => 'British Virgin Islands',
			'VI' => 'U.S. Virgin Islands', 'WF' => 'Wallis and Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe'
		);

		// Fallback: if code not in map, show the code itself (never "Unknown")
		return isset( $countries[ $country_code ] ) ? $countries[ $country_code ] : ( $country_code ?: 'Other' );
	}

	/**
	 * Detect country from browser Accept-Language header
	 * Used as fallback for local/private IPs when client-side detection fails
	 *
	 * @return array Country data array
	 */
	private function detect_country_from_browser() {
		$country = null;
		$country_name = null;

		// Try to get country from Accept-Language header
		if ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$accept_lang = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );

			// Parse Accept-Language header (e.g., "en-US,en;q=0.9,fr;q=0.8")
			// Extract the first locale with country code
			if ( preg_match( '/([a-z]{2})-([A-Z]{2})/', $accept_lang, $matches ) ) {
				$detected_country = strtoupper( $matches[2] );

				// Validate it's a real country code
				$detected_name = $this->get_country_name( $detected_country );
				if ( $detected_name !== 'Unknown' ) {
					$country = $detected_country;
					$country_name = $detected_name;
				}
			}
		}

		return array(
			'country'      => $country,
			'country_name' => $country_name,
			'region'       => null,
			'city'         => null,
			'timezone'     => null,
			'source'       => 'browser',
		);
	}

	/**
	 * Recursively sanitize data from json_decode
	 *
	 * Sanitizes all string values in a decoded JSON array/object structure.
	 * Numbers, booleans, and null values are preserved as-is.
	 *
	 * @param mixed $data Data to sanitize.
	 * @return mixed Sanitized data.
	 */
	private function sanitize_json_data( $data ) {
		if ( is_array( $data ) ) {
			// Recursively sanitize array values
			return array_map( array( $this, 'sanitize_json_data' ), $data );
		} elseif ( is_string( $data ) ) {
			// Sanitize string values
			// Use sanitize_text_field for most strings
			// URLs and HTML are handled separately where needed
			return sanitize_text_field( $data );
		} else {
			// Return other types (int, float, bool, null) as-is
			return $data;
		}
	}

	/**
	 * AJAX handler for sending support emails.
	 *
	 * @since 1.0.4
	 */
	public function ajax_send_support_email() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'opti_behavior_support_email' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'opti-behavior' ) ) );
		}

		// Verify user is logged in
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to send a message', 'opti-behavior' ) ) );
		}

		// Sanitize and validate inputs
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		// Validate required fields
		if ( empty( $name ) || empty( $email ) || empty( $subject ) || empty( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in all fields', 'opti-behavior' ) ) );
		}

		// Validate email
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address', 'opti-behavior' ) ) );
		}

		// Prepare email
		$to      = 'contact@optiuser.com';
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $name . ' <' . $email . '>',
			'Reply-To: ' . $email,
		);

		// Build email body
		$email_body = '<html><body>';
		$email_body .= '<h2>' . esc_html__( 'New Support Message from Opti-Behavior Plugin', 'opti-behavior' ) . '</h2>';
		$email_body .= '<p><strong>' . esc_html__( 'Name:', 'opti-behavior' ) . '</strong> ' . esc_html( $name ) . '</p>';
		$email_body .= '<p><strong>' . esc_html__( 'Email:', 'opti-behavior' ) . '</strong> ' . esc_html( $email ) . '</p>';
		$email_body .= '<p><strong>' . esc_html__( 'Subject:', 'opti-behavior' ) . '</strong> ' . esc_html( $subject ) . '</p>';
		$email_body .= '<p><strong>' . esc_html__( 'Message:', 'opti-behavior' ) . '</strong></p>';
		$email_body .= '<p>' . nl2br( esc_html( $message ) ) . '</p>';
		$email_body .= '<hr>';
		$email_body .= '<p><small>' . esc_html__( 'Sent from:', 'opti-behavior' ) . ' ' . esc_url( get_site_url() ) . '</small></p>';
		$email_body .= '</body></html>';

		// Send email
		$sent = wp_mail( $to, '[Opti-Behavior Support] ' . $subject, $email_body, $headers );

		if ( $sent ) {
			wp_send_json_success( array(
				'message' => __( 'Your message has been sent successfully! We\'ll get back to you soon.', 'opti-behavior' ),
			) );
		} else {
			// If wp_mail fails, provide fallback message
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: support email address */
					__( 'We couldn\'t send your message automatically. Please email us directly at %s', 'opti-behavior' ),
					'<a href="mailto:contact@optiuser.com">contact@optiuser.com</a>'
				),
			) );
		}
	}

	/**
	 * AJAX handler for heartbeat updates (session duration and scroll depth)
	 *
	 * @since 1.0.6.7
	 */
	public function ajax_heartbeat() {
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'AJAX request received: opti_behavior_heatmap_heartbeat', 'debug', 'ajax' );

		// Verify nonce for security
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'opti_behavior_heatmap_nonce' ) ) {
			$debug_manager->log( 'Heartbeat request failed: invalid nonce', 'warning', 'ajax' );
			wp_send_json_error( __( 'Invalid nonce', 'opti-behavior' ) );
		}

		// Ingest gate: silently ignore heartbeats from bots / excluded IPs
		// (200 success so tracker JS does not retry).
		if ( Opti_Behavior_Ingest_Gate::should_reject( 'heartbeat' ) ) {
			wp_send_json_success( array( 'status' => 'ignored' ) );
		}

		// Don't track admin users unless track_admin_users setting is enabled (or in test mode)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET/COOKIE check for test mode (permission check follows)
		$is_test_mode = isset( $_GET['opti_behavior_test_tracking'] ) || isset( $_COOKIE['opti_behavior_test_tracking'] ) || isset( $_GET['opti_behavior_test_recording'] ) || isset( $_COOKIE['opti_behavior_test_recording'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( current_user_can( 'manage_options' ) && ! $is_test_mode ) {
			$traffic_settings = get_option( 'opti_behavior_traffic_settings', array() );
			$track_admin      = isset( $traffic_settings['track_admin_users'] ) ? (bool) $traffic_settings['track_admin_users'] : true;
			if ( ! $track_admin ) {
				$debug_manager->log( 'Session record skipped: Admin user tracking disabled in settings', 'info', 'ajax' );
				wp_send_json_success( array( 'message' => 'Admin tracking disabled' ) );
				return;
			}
		}

		global $wpdb;

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$visitor_id = isset( $_POST['visitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_id'] ) ) : '';
		$url        = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$duration   = isset( $_POST['duration'] ) ? intval( $_POST['duration'] ) : 0;
		$scroll_depth = isset( $_POST['scroll_depth'] ) ? intval( $_POST['scroll_depth'] ) : 0;

		// VALIDATION: Cap session duration at 1 hour (3600 seconds) to prevent inflated averages
		// This protects against sessions left open for hours/days in background tabs
		$max_duration = 3600; // 1 hour in seconds
		if ( $duration > $max_duration ) {
			$debug_manager->log( "Duration capped from {$duration}s to {$max_duration}s for session: {$session_id}", 'warning', 'ajax' );
			$duration = $max_duration;
		}

		// Per-page dwell time for THIS pageview row (time on the current page).
		// The frontend timer (sessionStartTime in opti-behavior-heatmap-simple.js) is
		// re-initialised on every page load, so the incoming duration is the time spent
		// on the CURRENT page before it is merged into the session-wide max below.
		// Capture the raw per-page value now, already capped and clamped >= 0, so the
		// later $duration reassignment cannot leak the session total into the pageview row.
		$page_time_on_page = max( 0, min( $max_duration, $duration ) );

		if ( empty( $session_id ) || empty( $visitor_id ) ) {
			$debug_manager->log( 'Heartbeat request failed: missing session_id or visitor_id', 'warning', 'ajax' );
			wp_send_json_error( __( 'Invalid session', 'opti-behavior' ) );
		}

		// In anonymous mode, override the JS-provided visitor_id with the stable server-side
		// daily hash — keeps visitor identity consistent across all AJAX handlers.
		if ( 'anonymous' === $this->get_privacy_mode() && ! is_user_logged_in() ) {
			$session_handler = $this->core->get_session();
			$visitor_id      = $session_handler->get_visitor_id();
		}

		$debug_manager->log( "Heartbeat update - Session: $session_id, Duration: {$duration}s, Scroll: {$scroll_depth}%", 'info', 'ajax' );

		// Ensure session exists before updating
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$session_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
			$session_id
		) );

		if ( ! $session_exists ) {
			// Session doesn't exist yet, create it using ensure_session_exists
			$this->ensure_session_exists( $session_id, $visitor_id, $url, '', null, null );
		}

		$stored_duration = intval(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(duration, 0) FROM {$wpdb->prefix}optibehavior_sessions WHERE id = %s",
					$session_id
				)
			)
		);
		$duration        = min( $max_duration, max( $duration, $stored_duration ) );

		// Update session duration and end_time
		$update_data = array(
			'end_time' => current_time( 'mysql' ),
			'duration' => $duration,
		);
		$format_array = array( '%s', '%d' );

		// If user scrolled more than 25%, consider it engagement - not a bounce
		if ( $scroll_depth > 25 ) {
			$update_data['is_bounce'] = 0;
			$format_array[] = '%d';
			$debug_manager->log( 'Bounce status updated to 0 due to scroll engagement (' . intval( $scroll_depth ) . '%) in heartbeat for session: ' . $session_id, 'debug', 'ajax' );
		}

		// Spam traffic classification is handled AFTER the session row is persisted
		// (below), so it delegates to the canonical multi-source classifier reading
		// the freshly-written duration. Doing it here on a raw, heatmap-events-only
		// click count is exactly the premature/transient window that flagged genuine
		// engaged sessions as spam before their click events flushed.
		$result = $wpdb->update(
			"{$wpdb->prefix}optibehavior_sessions",
			$update_data,
			array( 'id' => $session_id ),
			$format_array,
			array( '%s' )
		);

		$debug_manager->log( "Session update result: " . ($result !== false ? 'success' : 'failed') . ", rows affected: " . ($result ? $result : '0'), 'info', 'ajax' );
		if ( false !== $result ) {
			$this->clear_behavior_classification_caches();
		}

		// Delegate spam classification to the canonical multi-source classifier now
		// that the session duration is persisted. Lifecycle rule (see spec Fix A.3):
		//  - Periodic heartbeat (session still active): allow_spam_verdict = false, so
		//    a fresh spam verdict is deferred (it may be a transient pre-flush window
		//    before heatmap click/scroll events land) and only an eager downgrade to
		//    human is written. Kills the minutes-long "everything is spam" zeros window.
		//  - Session-end beacon (is_final): finalize via a short grace window (see
		//    finalize_session_with_grace_window()) rather than classifying instantly.
		//    The frontend's finalizeSession() fires the event-flush and this
		//    session-end heartbeat as two independent sendBeacon/keepalive-fetch
		//    requests with no delivery-order guarantee, so an instant classify here
		//    could lock in 'spam' before the sibling click/scroll flush commits. The
		//    grace window gives that in-flight request a few seconds to land first.
		// The final flag is a plain hint on the already-nonce-verified heartbeat POST.
		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at the top of ajax_heartbeat(); this is a boolean lifecycle hint only.
			$is_final = isset( $_POST['is_final'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['is_final'] ) );
			if ( $is_final ) {
				Opti_Behavior_Stats_Spam_Filter::finalize_session_with_grace_window( $session_id );
			} else {
				Opti_Behavior_Stats_Spam_Filter::classify_session(
					$session_id,
					array( 'allow_spam_verdict' => false )
				);
			}
		}

		// Update scroll depth in pageviews table
		$analytics = $this->core->get_analytics();
		$page_id   = $analytics->get_or_create_page_id( $url, $title );

		// Ensure pageview exists before updating scroll depth. This is a periodic
		// heartbeat, not a navigation event, so it must not create a new pageview
		// row just because time has passed since the last one (see ensure_pageview_exists()).
		$this->ensure_pageview_exists( $session_id, $visitor_id, $page_id, $url, $title, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		// Persist scroll depth AND per-page dwell time on the current pageview row.
		// Both use GREATEST() so successive heartbeats and the is_final unload beacon
		// only advance the values, never regress. Writing time_on_page here is what makes
		// repository avg_time (AVG(NULLIF(pv.time_on_page,0)), human/spam-excluded) non-zero
		// for pages tracked after this change; historic rows keep 0 and drop out of the avg.
		$scroll_result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}optibehavior_pageviews
			 SET scroll_depth = GREATEST(scroll_depth, %d),
			     time_on_page = GREATEST(COALESCE(time_on_page, 0), %d)
			 WHERE session_id = %s AND page_id = %d",
			$scroll_depth,
			$page_time_on_page,
			$session_id,
			$page_id
		) );

		$debug_manager->log( "Scroll depth update result: rows affected: " . $wpdb->rows_affected, 'info', 'ajax' );
		$debug_manager->log( "Heartbeat completed - Session duration: {$duration}s, Scroll depth: {$scroll_depth}%", 'info', 'ajax' );

		wp_send_json_success( array(
			'status' => 'updated',
			'duration' => $duration,
			'scroll_depth' => $scroll_depth,
		) );
	}

	/**
	 * Clear dashboard caches that depend on live session engagement.
	 *
	 * Traffic Classification and User Intent can change during a visit as
	 * heartbeat duration, clicks, and scroll depth arrive. Without clearing
	 * these transients, the dashboard can keep showing an early short visit as
	 * spam/low intent even after the same session becomes engaged.
	 *
	 * @since 1.3.8
	 */
	private function clear_behavior_classification_caches() {
		global $wpdb;

		$options_table = esc_sql( $wpdb->prefix . 'options' );
		$patterns      = array(
			'_transient_opti_behavior_traffic_class_%',
			'_transient_timeout_opti_behavior_traffic_class_%',
			'_transient_opti_behavior_user_intent_%',
			'_transient_timeout_opti_behavior_user_intent_%',
		);

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clearing dashboard transients after live tracking updates.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$options_table} WHERE option_name LIKE %s",
					$pattern
				)
			);
		}

		// Bug #3 (Part B) fix: this method runs on every heartbeat session-row
		// update (i.e. potentially before classify_session() below has run/flipped
		// anything), so also proactively clear the dashboard_traffic_series KPI
		// cache family here — same reasoning as
		// Opti_Behavior_Stats_Spam_Filter::clear_traffic_classification_caches(),
		// kept in sync with it so neither cache-clear path can be updated without
		// the other.
		if ( function_exists( 'opti_behavior_invalidate_dashboard_caches' ) ) {
			opti_behavior_invalidate_dashboard_caches();
		}
	}

	/**
	 * AJAX handler to reset country data for all visitors.
	 *
	 * This clears country data that was incorrectly detected from browser language settings.
	 * After running this, visitors will have their country re-detected on their next visit.
	 *
	 * @since 1.0.7
	 */
	public function ajax_reset_country_data() {
		// Security checks
		check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(
				'message' => __( 'You do not have permission to perform this action.', 'opti-behavior' ),
			) );
		}

		global $wpdb;
		$visitors_table = esc_sql( $wpdb->prefix . 'optibehavior_visitors' );

		// Reset country data to NULL for all visitors
		// This allows the system to re-detect countries on next visit
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix with hardcoded suffix, properly escaped with esc_sql()
		$result = $wpdb->query(
			"UPDATE {$visitors_table} SET country = NULL, country_name = NULL WHERE country IS NOT NULL"
		);

		if ( $result === false ) {
			wp_send_json_error( array(
				'message' => __( 'Failed to reset country data. Please try again.', 'opti-behavior' ),
			) );
		}

		// Clear the geo-location cache
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup query.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_opti_geo_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_opti_geo_' ) . '%'
			)
		);

		// Clear the top users cache
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup query.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_optibehavior_top_users_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_optibehavior_top_users_' ) . '%'
			)
		);

		$affected_rows = $result !== false ? $result : 0;

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of visitor records updated */
				__( 'Successfully reset country data for %d visitors. Countries will be re-detected on their next visit.', 'opti-behavior' ),
				$affected_rows
			),
			'affected_rows' => $affected_rows,
		) );
	}

	/**
	 * AJAX handler for backfilling country data on visitors with NULL country.
	 *
	 * Uses timezone and browser language fallbacks to resolve countries
	 * for visitors where ip-api.com previously failed (rate limiting, etc.).
	 * Processes in batches to avoid timeouts.
	 *
	 * @since 1.1.2
	 */
	public function ajax_backfill_countries() {
		// Security checks
		check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(
				'message' => __( 'You do not have permission to perform this action.', 'opti-behavior' ),
			) );
		}

		global $wpdb;
		$visitors_table = esc_sql( $wpdb->prefix . 'optibehavior_visitors' );
		$batch_size = isset( $_POST['batch_size'] ) ? min( absint( wp_unslash( $_POST['batch_size'] ) ), 500 ) : 100;

		// Get visitors with NULL country that have a timezone stored
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics backfill query; table name from $wpdb->prefix.
		$visitors = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, timezone, language FROM {$visitors_table}
			WHERE (country IS NULL OR country = '' OR country = 'UN')
			ORDER BY last_visit DESC
			LIMIT %d",
			$batch_size
		) );

		$updated = 0;
		$skipped = 0;

		foreach ( $visitors as $visitor ) {
			$country = null;
			$country_name = null;
			$source = '';

			// Try timezone-based detection first
			if ( ! empty( $visitor->timezone ) ) {
				$tz_result = $this->get_country_from_timezone( $visitor->timezone );
				if ( ! empty( $tz_result['country'] ) ) {
					$country = $tz_result['country'];
					$country_name = $tz_result['country_name'];
					$source = 'timezone_backfill';
				}
			}

			// Try language-based detection as fallback
			if ( empty( $country ) && ! empty( $visitor->language ) ) {
				$lang = sanitize_text_field( $visitor->language );
				// Parse language string (e.g., "fr-FR" or "fr")
				if ( preg_match( '/^([a-z]{2})-([A-Z]{2})/', $lang, $matches ) ) {
					$lang_country = strtoupper( $matches[2] );
					$lang_name = $this->get_country_name( $lang_country );
					if ( $lang_name && $lang_name !== 'Unknown' && $lang_name !== $lang_country ) {
						$country = $lang_country;
						$country_name = $lang_name;
						$source = 'language_backfill';
					}
				}
			}

			if ( ! empty( $country ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics backfill update; table name from $wpdb->prefix.
				$wpdb->update(
					$wpdb->prefix . 'optibehavior_visitors',
					array(
						'country'      => $country,
						'country_name' => $country_name,
					),
					array( 'id' => $visitor->id ),
					array( '%s', '%s' ),
					array( '%s' )
				);
				$updated++;
			} else {
				$skipped++;
			}
		}

		// Count total remaining visitors with NULL country
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics count query; table name from $wpdb->prefix.
		$remaining = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$visitors_table} WHERE country IS NULL OR country = '' OR country = 'UN'"
		);

		// Clear countries cache so dashboard refreshes
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup query.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_opti_behavior_countries_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_opti_behavior_countries_' ) . '%'
			)
		);

		wp_send_json_success( array(
			'updated'   => $updated,
			'skipped'   => $skipped,
			'remaining' => intval( $remaining ),
			'message'   => sprintf(
				/* translators: 1: updated count, 2: remaining count */
				__( 'Updated %1$d visitors. %2$d visitors still have unknown country.', 'opti-behavior' ),
				$updated,
				intval( $remaining )
			),
		) );
	}

	/**
	 * AJAX handler for database optimization.
	 *
	 * Clears redundant metadata columns from recordings and session_pages tables.
	 * This data is now stored in the visitors and sessions tables (normalized).
	 *
	 * @since 1.0.8
	 */
	public function ajax_run_db_optimization() {
		// Security checks
		if ( ! check_ajax_referer( 'opti_behavior_db_optimization', 'nonce', false ) ) {
			wp_send_json_error( array(
				'message' => __( 'Security check failed. Please refresh the page and try again.', 'opti-behavior' ),
			) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(
				'message' => __( 'You do not have permission to perform this action.', 'opti-behavior' ),
			) );
		}

		global $wpdb;

		$recordings_table = $wpdb->prefix . 'optibehavior_recordings';
		$session_pages_table = $wpdb->prefix . 'optibehavior_session_pages';

		$total_cleaned = 0;

		// Clear redundant columns from recordings table
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for bulk update optimization
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$recordings_result = $wpdb->query(
			"UPDATE {$recordings_table} SET
				device_type = NULL,
				browser = NULL,
				os = NULL,
				country = NULL,
				country_name = NULL,
				screen_width = NULL,
				screen_height = NULL,
				referrer = NULL
			WHERE device_type IS NOT NULL
				OR browser IS NOT NULL
				OR os IS NOT NULL
				OR country IS NOT NULL"
		);

		if ( $recordings_result !== false ) {
			$total_cleaned += $recordings_result;
		}

		// Clear redundant columns from session_pages table
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for bulk update optimization
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$session_pages_result = $wpdb->query(
			"UPDATE {$session_pages_table} SET
				device_type = NULL,
				browser = NULL,
				os = NULL,
				country = NULL,
				country_name = NULL,
				screen_width = NULL,
				screen_height = NULL,
				referrer = NULL,
				visitor_type = NULL,
				utm_campaign = NULL,
				utm_source = NULL,
				utm_medium = NULL,
				traffic_channel = NULL
			WHERE device_type IS NOT NULL
				OR browser IS NOT NULL
				OR os IS NOT NULL
				OR country IS NOT NULL"
		);

		if ( $session_pages_result !== false ) {
			$total_cleaned += $session_pages_result;
		}

		// Save optimization status
		update_option( 'opti_behavior_db_optimization_status', array(
			'last_run'        => current_time( 'timestamp' ),
			'records_cleaned' => $total_cleaned,
		) );

		if ( $recordings_result === false && $session_pages_result === false ) {
			wp_send_json_error( array(
				'message' => __( 'Failed to optimize database. Please try again.', 'opti-behavior' ),
			) );
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of records cleaned */
				__( 'Successfully optimized %d records. Redundant metadata has been cleared.', 'opti-behavior' ),
				$total_cleaned
			),
			'records_cleaned' => $total_cleaned,
		) );
	}

}
