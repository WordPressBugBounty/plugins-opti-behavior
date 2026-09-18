<?php
/**
 * A/B Tests AJAX Handler Trait
 *
 * Handles all AJAX endpoints for A/B testing: admin CRUD operations
 * and frontend tracking (impressions, conversions).
 *
 * @package opti-behavior
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- A/B analytics SQL uses hard-coded plugin tables, generated %d placeholders, and internal spam-filter fragments.
trait Opti_Behavior_AB_Tests_Ajax {

	/**
	 * Resolve A/B analytics spam filter state from request input.
	 *
	 * @return bool|null Null means use global Traffic Behavior setting.
	 */
	private function resolve_ab_spam_exclusion_from_request() {
		if ( array_key_exists( 'exclude_spam', $_REQUEST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
			return '1' === (string) sanitize_text_field( wp_unslash( $_REQUEST['exclude_spam'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		}

		return null;
	}

	/**
	 * Register all A/B test AJAX actions.
	 *
	 * @since 1.3.0
	 */
	public function register_ab_test_ajax_actions() {
		// Admin endpoints (require manage_options).
		add_action( 'wp_ajax_opti_behavior_ab_create_test', array( $this, 'ajax_ab_create_test' ) );
		add_action( 'wp_ajax_opti_behavior_ab_update_test', array( $this, 'ajax_ab_update_test' ) );
		add_action( 'wp_ajax_opti_behavior_ab_delete_test', array( $this, 'ajax_ab_delete_test' ) );
		add_action( 'wp_ajax_opti_behavior_ab_start_test', array( $this, 'ajax_ab_start_test' ) );
		add_action( 'wp_ajax_opti_behavior_ab_pause_test', array( $this, 'ajax_ab_pause_test' ) );
		add_action( 'wp_ajax_opti_behavior_ab_stop_test', array( $this, 'ajax_ab_stop_test' ) );
		add_action( 'wp_ajax_opti_behavior_ab_get_results', array( $this, 'ajax_ab_get_results' ) );
		add_action( 'wp_ajax_opti_behavior_ab_get_tests', array( $this, 'ajax_ab_get_tests' ) );
		add_action( 'wp_ajax_opti_behavior_ab_apply_winner', array( $this, 'ajax_ab_apply_winner' ) );
		add_action( 'wp_ajax_opti_behavior_ab_revert_winner', array( $this, 'ajax_ab_revert_winner' ) );
		add_action( 'wp_ajax_opti_behavior_ab_preflight_apply', array( $this, 'ajax_ab_preflight_apply' ) );
		add_action( 'wp_ajax_opti_behavior_ab_duplicate_test', array( $this, 'ajax_ab_duplicate_test' ) );
		add_action( 'wp_ajax_opti_behavior_ab_save_test_full', array( $this, 'ajax_ab_save_test_full' ) );
		add_action( 'wp_ajax_opti_behavior_ab_update_free_limits', array( $this, 'ajax_ab_update_free_limits' ) );
		add_action( 'wp_ajax_opti_behavior_ab_update_variant_data', array( $this, 'ajax_ab_update_variant_data' ) );
		add_action( 'wp_ajax_opti_behavior_ab_search_targets', array( $this, 'ajax_ab_search_targets' ) );

		// Frontend endpoints (public, nopriv).
		add_action( 'wp_ajax_opti_behavior_ab_track_batch', array( $this, 'ajax_ab_track_batch' ) );
		add_action( 'wp_ajax_nopriv_opti_behavior_ab_track_batch', array( $this, 'ajax_ab_track_batch' ) );
		add_action( 'wp_ajax_opti_behavior_ab_track_conversion', array( $this, 'ajax_ab_track_conversion' ) );
		add_action( 'wp_ajax_nopriv_opti_behavior_ab_track_conversion', array( $this, 'ajax_ab_track_conversion' ) );
	}

	// =========================================================================
	// Admin AJAX Handlers
	// =========================================================================

	/**
	 * AJAX: Create a new test.
	 */
	public function ajax_ab_create_test() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below
		$data = isset( $_POST['test_data'] ) ? json_decode( wp_unslash( $_POST['test_data'] ), true ) : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();
		$result  = $manager->create_test( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'test_id' => $result ) );
	}

	/**
	 * AJAX: Update an existing test.
	 */
	public function ajax_ab_update_test() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below
		$data    = isset( $_POST['test_data'] ) ? json_decode( wp_unslash( $_POST['test_data'] ), true ) : array();

		if ( ! $test_id || ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'opti-behavior' ) ) );
		}

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();
		$result  = $manager->update_test( $test_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'updated' => true ) );
	}

	/**
	 * AJAX: Delete a test.
	 */
	public function ajax_ab_delete_test() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();
		$result  = $manager->delete_test( $test_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'deleted' => true ) );
	}

	/**
	 * AJAX: Start a test.
	 */
	public function ajax_ab_start_test() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();
		$result  = $manager->start_test( $test_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'started' => true ) );
	}

	/**
	 * AJAX: Pause a test.
	 */
	public function ajax_ab_pause_test() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();
		$result  = $manager->pause_test( $test_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'paused' => true ) );
	}

	/**
	 * AJAX: Stop/complete a test.
	 *
	 * Accepts either:
	 *   - winner_variant_id (int)  — explicit variant ID to declare as winner
	 *   - auto_winner (1)          — auto-detect the best-performing variant
	 */
	public function ajax_ab_stop_test() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id     = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;
		$variant_id  = isset( $_POST['winner_variant_id'] ) ? absint( $_POST['winner_variant_id'] ) : 0;
		$auto_winner = isset( $_POST['auto_winner'] ) ? absint( $_POST['auto_winner'] ) : 0;

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();

		// Complete the test (skip if already completed, e.g. when declaring winner after stop).
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			wp_send_json_error( array( 'message' => __( 'Test not found.', 'opti-behavior' ) ) );
		}
		if ( 'completed' !== $test->status ) {
			$result = $manager->complete_test( $test_id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		}

		// Auto-detect winner: find the variant with the highest conversion rate.
		if ( ! $variant_id && $auto_winner ) {
			$variant_stats = Opti_Behavior_AB_Test_Database::get_test_stats( $test_id );
			$results       = Opti_Behavior_AB_Test_Engine::calculate_results( $variant_stats );

			$best_rate = -1;
			foreach ( $results as $r ) {
				if ( $r->conversion_rate > $best_rate ) {
					$best_rate  = $r->conversion_rate;
					$variant_id = $r->variant_id;
				}
			}
		}

		// Declare winner if we have a variant.
		if ( $variant_id ) {
			$result = $manager->declare_winner( $test_id, $variant_id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		}

		wp_send_json_success( array( 'stopped' => true ) );
	}

	/**
	 * AJAX: Get test results.
	 */
	public function ajax_ab_get_results() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;
		$goal_id = isset( $_POST['goal_id'] ) ? absint( $_POST['goal_id'] ) : null;

		$manager      = Opti_Behavior_AB_Test_Manager::get_instance();
		$exclude_spam = $this->resolve_ab_spam_exclusion_from_request();
		$data         = $manager->get_results( $test_id, $goal_id, $exclude_spam );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		// Fetch goals for the test so JS can build a goal selector.
		$goals = Opti_Behavior_AB_Test_Database::get_goals( $test_id );

		// QA-B-AB-012 / QA-B-AB-075: the results view used to fire one full
		// admin-ajax round trip per goal tab, so every tab paid the whole
		// WordPress bootstrap again (measured ~8 s per tab on a 20-goal test).
		// The per-goal numbers themselves cost only a few milliseconds, so the
		// first load now returns every goal's payload and the admin JS serves
		// each tab from its own cache. Only the initial request (no goal_id)
		// prefetches; a goal-specific request stays cheap.
		$goal_results = array();
		if ( empty( $goal_id ) && count( $goals ) > 1 ) {
			foreach ( $goals as $opti_ab_goal ) {
				$opti_ab_goal_id = (int) $opti_ab_goal->id;
				$opti_ab_payload = $manager->get_results( $test_id, $opti_ab_goal_id, $exclude_spam );
				if ( is_wp_error( $opti_ab_payload ) ) {
					continue;
				}

				// `test` and `goals` are goal-independent and are filled in by
				// the admin JS from the main payload, so they are not repeated
				// once per goal here.
				$goal_results[] = array(
					'results'               => $opti_ab_payload['variants'],
					'significance'          => $opti_ab_payload['significance'],
					'significance_progress' => $opti_ab_payload['progress'],
					'daily_stats'           => $opti_ab_payload['daily_stats'],
					'active_goal_id'        => $opti_ab_goal_id,
				);
			}
		}

		// Enrich the test object with the target product/page name so the
		// admin Goal Configuration card can label WooCommerce goals (add-to-cart,
		// purchase) with the actual product name instead of a generic label.
		$test_payload = $data['test'];
		if ( is_object( $test_payload ) ) {
			$target_name = '';
			if ( ! empty( $test_payload->target_post_id ) ) {
				$target_name = get_the_title( (int) $test_payload->target_post_id );
			}
			if ( empty( $target_name ) && ! empty( $test_payload->target_url ) ) {
				$target_name = wp_parse_url( $test_payload->target_url, PHP_URL_PATH );
			}
			$test_payload->target_product_name = $target_name ? (string) $target_name : '';
		}

		// Normalise keys for the admin JS.
		wp_send_json_success( array(
			'test'                  => $test_payload,
			'results'               => $data['variants'],
			'significance'          => $data['significance'],
			'significance_progress' => $data['progress'],
			'daily_stats'           => $data['daily_stats'],
			'goals'                 => $goals,
			'active_goal_id'        => $goal_id,
			'goal_results'          => $goal_results,
		) );
	}

	/**
	 * AJAX: Get test list.
	 *
	 * Uses SQL aggregates instead of per-test loops so the test list is O(1)
	 * in number of queries regardless of test count. Key KPI semantics:
	 *
	 * - `completed` card counts completed + archived tests (both are concluded).
	 * - `total_impressions` is the LIFETIME total across every non-deleted
	 *   test, not just running ones — archived tests still represent real
	 *   collected traffic and must contribute to the headline figure.
	 */
	public function ajax_ab_get_tests() {
		global $wpdb;

		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		// Accept both 'type' (from JS) and 'test_type' for the type filter.
		$opti_ab_type_raw = '';
		if ( isset( $_POST['test_type'] ) ) {
			$opti_ab_type_raw = sanitize_key( wp_unslash( $_POST['test_type'] ) );
		} elseif ( isset( $_POST['type'] ) ) {
			$opti_ab_type_raw = sanitize_key( wp_unslash( $_POST['type'] ) );
		}

		$args = array(
			'status'    => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
			'test_type' => $opti_ab_type_raw,
			'search'    => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
			'page'      => isset( $_POST['page_num'] ) ? absint( $_POST['page_num'] ) : 1,
			'per_page'  => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 25,
		);

		$tests = Opti_Behavior_AB_Test_Database::get_tests( $args );
		$total = Opti_Behavior_AB_Test_Database::count_tests( $args );

		// Quick stats for all tests (ignore pagination/search filters).
		$running_count   = Opti_Behavior_AB_Test_Database::count_tests( array( 'status' => 'running' ) );
		$draft_count     = Opti_Behavior_AB_Test_Database::count_tests( array( 'status' => 'draft' ) );
		$completed_count = Opti_Behavior_AB_Test_Database::count_tests( array( 'status' => 'completed' ) );
		$archived_count  = Opti_Behavior_AB_Test_Database::count_tests( array( 'status' => 'archived' ) );

		// Enrich tests with impression/conversion counts + winner variant name
		// using a single grouped query per table instead of N+1 loops.
		$test_ids = array();
		foreach ( $tests as $opti_ab_test ) {
			$test_ids[] = (int) $opti_ab_test->id;
		}

		$impressions_by_test = array();
		$conversions_by_test = array();
		$primary_conversions_by_test = array();
		$winners_by_test     = array();

		if ( ! empty( $test_ids ) ) {
			$placeholders       = implode( ',', array_fill( 0, count( $test_ids ), '%d' ) );
			$impressions_table  = esc_sql( $wpdb->prefix . 'optibehavior_ab_impressions' );
			$conversions_table  = esc_sql( $wpdb->prefix . 'optibehavior_ab_conversions' );
			$goals_table        = esc_sql( $wpdb->prefix . 'optibehavior_ab_goals' );
			$variants_table     = esc_sql( $wpdb->prefix . 'optibehavior_ab_variants' );
			$exclude_spam     = $this->resolve_ab_spam_exclusion_from_request();
			$imp_spam_where   = Opti_Behavior_AB_Test_Database::get_ab_spam_session_filter_sql( 'i', $exclude_spam );
			$conv_spam_where  = Opti_Behavior_AB_Test_Database::get_ab_spam_session_filter_sql( 'c', $exclude_spam );

			// Impressions per test.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql_imp = "SELECT i.test_id, COUNT(*) AS total_impressions FROM {$impressions_table} i WHERE i.test_id IN ({$placeholders}){$imp_spam_where} GROUP BY i.test_id";
			$rows_imp = $wpdb->get_results( $wpdb->prepare( $sql_imp, $test_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows_imp as $opti_ab_row ) {
				$impressions_by_test[ (int) $opti_ab_row->test_id ] = (int) $opti_ab_row->total_impressions;
			}

			// Conversions per test.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql_conv = "SELECT c.test_id, COUNT(*) AS total_conversions FROM {$conversions_table} c WHERE c.test_id IN ({$placeholders}){$conv_spam_where} GROUP BY c.test_id";
			$rows_conv = $wpdb->get_results( $wpdb->prepare( $sql_conv, $test_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows_conv as $opti_ab_row ) {
				$conversions_by_test[ (int) $opti_ab_row->test_id ] = (int) $opti_ab_row->total_conversions;
			}

			// Primary-goal conversions per test. This is the meaningful conversion-rate numerator.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql_primary_conv = "SELECT c.test_id, COUNT(*) AS primary_conversions
				FROM {$conversions_table} c
				INNER JOIN {$goals_table} g ON g.id = c.goal_id AND g.test_id = c.test_id AND g.is_primary = 1
				WHERE c.test_id IN ({$placeholders}){$conv_spam_where}
				GROUP BY c.test_id";
			$rows_primary_conv = $wpdb->get_results( $wpdb->prepare( $sql_primary_conv, $test_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows_primary_conv as $opti_ab_row ) {
				$primary_conversions_by_test[ (int) $opti_ab_row->test_id ] = (int) $opti_ab_row->primary_conversions;
			}

			// Winner variant name per test (only for tests that have one).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql_win = "SELECT t.id AS test_id, v.name AS variant_name
				FROM {$wpdb->prefix}optibehavior_ab_tests t
				INNER JOIN {$variants_table} v ON v.id = t.winner_variant_id
				WHERE t.id IN ({$placeholders}) AND t.winner_variant_id IS NOT NULL";
			$rows_win = $wpdb->get_results( $wpdb->prepare( $sql_win, $test_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows_win as $opti_ab_row ) {
				$winners_by_test[ (int) $opti_ab_row->test_id ] = (string) $opti_ab_row->variant_name;
			}
		}

		foreach ( $tests as &$opti_ab_test ) {
			$tid = (int) $opti_ab_test->id;
			$opti_ab_test->total_impressions          = isset( $impressions_by_test[ $tid ] ) ? $impressions_by_test[ $tid ] : 0;
			$opti_ab_test->primary_conversions        = isset( $primary_conversions_by_test[ $tid ] ) ? $primary_conversions_by_test[ $tid ] : 0;
			$opti_ab_test->all_goal_conversions       = isset( $conversions_by_test[ $tid ] ) ? $conversions_by_test[ $tid ] : 0;
			$opti_ab_test->total_conversions          = $opti_ab_test->primary_conversions;
			$opti_ab_test->winner_variant_name = isset( $winners_by_test[ $tid ] ) ? $winners_by_test[ $tid ] : '';
		}
		unset( $opti_ab_test );

		// Lifetime total impressions across every non-deleted test.
		$tests_table       = esc_sql( $wpdb->prefix . 'optibehavior_ab_tests' );
		$impressions_table = esc_sql( $wpdb->prefix . 'optibehavior_ab_impressions' );
		$grand_spam_where = Opti_Behavior_AB_Test_Database::get_ab_spam_session_filter_sql( 'i', $this->resolve_ab_spam_exclusion_from_request() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix + hardcoded identifiers, escaped with esc_sql(); no user input.
		$sql_grand = "SELECT COUNT(*) FROM {$impressions_table} i INNER JOIN {$tests_table} t ON t.id = i.test_id WHERE t.status <> 'deleted'{$grand_spam_where}";
		$grand_total_impressions = (int) $wpdb->get_var( $sql_grand ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input; table names hardcoded and escaped with esc_sql().

		wp_send_json_success( array(
			'tests' => $tests,
			'total' => $total,
			'stats' => array(
				'running'           => $running_count,
				'draft'             => $draft_count,
				// "Completed" card represents concluded tests (completed + archived).
				'completed'         => $completed_count + $archived_count,
				'completed_only'    => $completed_count,
				'archived'          => $archived_count,
				'total_impressions' => $grand_total_impressions,
			),
		) );
	}

	/**
	 * AJAX: Search targetable WordPress content for Element A/B tests.
	 *
	 * Returns paginated, normalized target data without preloading large sites
	 * into the builder UI. Targetable content includes posts, pages,
	 * WooCommerce products when available, and other public post types that are
	 * publicly queryable.
	 */
	public function ajax_ab_search_targets() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
		$per_page = max( 1, min( 50, $per_page ) );

		$targetable_post_types = $this->ab_get_targetable_post_types();
		$query_post_types      = $targetable_post_types;
		$post_type_input       = isset( $_POST['post_type'] ) ? map_deep( wp_unslash( $_POST['post_type'] ), 'sanitize_key' ) : '';

		if ( is_array( $post_type_input ) || '' !== trim( (string) $post_type_input ) ) {
			$post_type_list  = is_array( $post_type_input ) ? $post_type_input : explode( ',', (string) $post_type_input );
			$query_post_types = array();

			foreach ( $post_type_list as $post_type ) {
				$post_type = sanitize_key( $post_type );
				if ( in_array( $post_type, $targetable_post_types, true ) ) {
					$query_post_types[] = $post_type;
				}
			}

			$query_post_types = array_values( array_unique( $query_post_types ) );
		}

		if ( empty( $query_post_types ) ) {
			wp_send_json_success( array(
				'targets'  => array(),
				'total'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
			) );
		}

		$query_args = array(
			'post_type'              => $query_post_types,
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			's'                      => $search,
			'orderby'                => '' === $search ? 'title' : 'relevance',
			'order'                  => '' === $search ? 'ASC' : 'DESC',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$query   = new WP_Query( $query_args );
		$targets = array();

		foreach ( $query->posts as $post ) {
			$post_type_object = get_post_type_object( $post->post_type );
			$permalink        = get_permalink( $post );
			$type_label       = $post_type_object && ! empty( $post_type_object->labels->singular_name ) ? $post_type_object->labels->singular_name : $post->post_type;

			$targets[] = array(
				'id'         => (int) $post->ID,
				'title'      => get_the_title( $post ),
				'post_type'  => $post->post_type,
				'type_label' => $type_label,
				'url'        => $permalink ? $permalink : '',
				'status'     => $post->post_status,
			);
		}

		wp_reset_postdata();

		wp_send_json_success( array(
			'targets'  => $targets,
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
		) );
	}

	/**
	 * Get public post types that can be selected as Element test targets.
	 *
	 * @return string[] Targetable post type names.
	 */
	private function ab_get_targetable_post_types() {
		$post_type_objects = get_post_types( array( 'public' => true ), 'objects' );
		$targetable        = array();

		foreach ( $post_type_objects as $post_type => $post_type_object ) {
			$is_core_target = in_array( $post_type, array( 'page', 'post' ), true );
			$is_product     = 'product' === $post_type && ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) );
			$is_public_cpt  = ! $is_core_target && ! $is_product && ! empty( $post_type_object->publicly_queryable );

			if ( $is_core_target || $is_product || $is_public_cpt ) {
				$targetable[] = $post_type;
			}
		}

		foreach ( array( 'page', 'post' ) as $core_post_type ) {
			if ( post_type_exists( $core_post_type ) && ! in_array( $core_post_type, $targetable, true ) ) {
				$targetable[] = $core_post_type;
			}
		}

		return array_values( array_unique( $targetable ) );
	}

	/**
	 * AJAX: Preflight apply — read-only diff computation (Phase A).
	 *
	 * Returns a field-by-field diff payload for the diff-preview dialog without
	 * mutating any post, meta, or audit-log data. The idempotency_key in the
	 * response can be passed back to ajax_ab_apply_winner() as an optimisation.
	 *
	 * @since 1.3.3
	 */
	public function ajax_ab_preflight_apply() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;
		if ( ! $test_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid test ID.', 'opti-behavior' ) ) );
		}

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();
		$payload = $manager->build_apply_diff( $test_id );

		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ) );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * AJAX: Apply winner.
	 *
	 * Accepts an optional idempotency_key (from the preflight Phase A response)
	 * to skip server-side recomputation and hit the UNIQUE constraint cleanly
	 * when the user double-clicks the Commit button.
	 */
	public function ajax_ab_apply_winner() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;

		// Optional: idempotency_key pre-computed by the preflight endpoint.
		// Validate it has the expected SHA-1 hex shape before trusting it.
		$idempotency_key = '';
		if ( ! empty( $_POST['idempotency_key'] ) ) {
			$opti_ab_raw_key = sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) );
			if ( preg_match( '/^[0-9a-f]{40}$/i', $opti_ab_raw_key ) ) {
				$idempotency_key = $opti_ab_raw_key;
			}
		}

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();
		$result  = $manager->apply_winner( $test_id, $idempotency_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// $result is now an array with success, applied_at, changed_fields, audit_id.
		$changed_count = isset( $result['changed_fields'] ) ? count( $result['changed_fields'] ) : 0;

		wp_send_json_success( array(
			'applied'        => true,
			'applied_at'     => isset( $result['applied_at'] ) ? $result['applied_at'] : '',
			'changed_fields' => isset( $result['changed_fields'] ) ? $result['changed_fields'] : array(),
			'changed_count'  => $changed_count,
			'audit_id'       => isset( $result['audit_id'] ) ? (int) $result['audit_id'] : 0,
		) );
	}

	/**
	 * AJAX: Revert/disable an applied winner.
	 */
	public function ajax_ab_revert_winner() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;
		if ( ! $test_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid test ID.', 'opti-behavior' ) ) );
		}

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();
		$result  = $manager->revert_applied_winner( $test_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$reverted_count = isset( $result['reverted_fields'] ) ? count( $result['reverted_fields'] ) : 0;

		wp_send_json_success( array(
			'reverted'        => true,
			'reverted_at'     => isset( $result['reverted_at'] ) ? $result['reverted_at'] : '',
			'reverted_fields' => isset( $result['reverted_fields'] ) ? $result['reverted_fields'] : array(),
			'reverted_count'  => $reverted_count,
			'audit_id'        => isset( $result['audit_id'] ) ? (int) $result['audit_id'] : 0,
		) );
	}

	/**
	 * AJAX: Duplicate a test.
	 */
	public function ajax_ab_duplicate_test() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$test_id = isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0;

		$manager    = Opti_Behavior_AB_Test_Manager::get_instance();
		$new_test_id = $manager->duplicate_test( $test_id );

		if ( is_wp_error( $new_test_id ) ) {
			wp_send_json_error( array( 'message' => $new_test_id->get_error_message() ) );
		}

		wp_send_json_success( array( 'new_test_id' => $new_test_id ) );
	}

	/**
	 * AJAX: Save full test (wizard).
	 *
	 * Creates/updates test, variants, and goals in one request.
	 */
	public function ajax_ab_save_test_full() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		// DEF-AB-017/018/019 fix: the wizard's `autoSaveDraft` (fires on each Next click)
		// and `finishWizard` (fires on Launch) both POST to this endpoint. If they overlap
		// in-flight, both do `delete_goals_for_test + insert` concurrently — each DELETE
		// sees 0 rows, so both INSERT 7 goals → 14 duplicated rows. Same for variants.
		// Serialize with a per-test-id transient lock so one request completes before the
		// next starts. Acts as a coarse mutex in single-process environments; degrades to
		// "last writer wins" in clustered setups where it's not a correctness issue
		// because the wizard only fires one save per user action at a time anyway.
		$lock_test_id = 0;
		$raw_probe    = isset( $_POST['test_data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_data'] ) ) : ( isset( $_POST['payload'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payload'] ) ) : '' );
		if ( is_string( $raw_probe ) ) {
			$probe = json_decode( $raw_probe, true );
			if ( is_array( $probe ) ) {
				$pt = isset( $probe['test'] ) ? $probe['test'] : $probe;
				$lock_test_id = isset( $pt['test_id'] ) ? absint( $pt['test_id'] ) : ( isset( $pt['id'] ) ? absint( $pt['id'] ) : 0 );
			}
		}
		if ( $lock_test_id > 0 ) {
			$lock_key = 'opti_ab_save_lock_' . $lock_test_id;
			$waited   = 0;
			while ( get_transient( $lock_key ) && $waited < 5000 ) {
				usleep( 50000 ); // 50 ms
				$waited += 50;
			}
			set_transient( $lock_key, 1, 10 ); // auto-expire after 10 s
		}

		// Accept both flat format (from wizard JS) and nested payload format.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded below.
		$raw = isset( $_POST['test_data'] ) ? wp_unslash( $_POST['test_data'] ) : ( isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '' );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payload.', 'opti-behavior' ) ) );
		}

		// Normalise: if nested under 'test' key, extract.
		$test_input    = isset( $data['test'] ) ? $data['test'] : $data;
		$variants_input = isset( $data['variants'] ) ? $data['variants'] : array();
		$goals_input    = isset( $data['goals'] ) ? $data['goals'] : array();
		$should_launch  = ! empty( $_POST['launch'] );

		// Convert min_duration_days to min_duration_hours for the DB.
		if ( isset( $test_input['min_duration_days'] ) && ! isset( $test_input['min_duration_hours'] ) ) {
			$test_input['min_duration_hours'] = absint( $test_input['min_duration_days'] ) * 24;
		}

		// Convert traffic_percentage (from JS) to traffic_percent (DB column).
		if ( isset( $test_input['traffic_percentage'] ) && ! isset( $test_input['traffic_percent'] ) ) {
			$test_input['traffic_percent'] = absint( $test_input['traffic_percentage'] );
		}

		$manager = Opti_Behavior_AB_Test_Manager::get_instance();
		$test_id = isset( $test_input['test_id'] ) ? absint( $test_input['test_id'] ) : ( isset( $test_input['id'] ) ? absint( $test_input['id'] ) : 0 );

		// Remove non-column keys that would cause $wpdb->update() to fail.
		// The JS wizard sends a flat payload with variants, goals, and aliased
		// field names that don't match DB columns.
		unset( $test_input['test_id'] );
		unset( $test_input['variants'] );
		unset( $test_input['goals'] );
		unset( $test_input['min_duration_days'] );
		unset( $test_input['traffic_percentage'] );

		// Smart Insights origin link. The builder carries it when the test was
		// started from an insight story; it is written once and never cleared by
		// a later wizard save so the learning loop keeps its back-reference.
		$opti_ab_origin_insight_id = isset( $test_input['origin_insight_id'] ) ? absint( $test_input['origin_insight_id'] ) : 0;
		if ( $opti_ab_origin_insight_id > 0 ) {
			$test_input['origin_insight_id'] = $opti_ab_origin_insight_id;
		} else {
			unset( $test_input['origin_insight_id'] );
		}

		// Create or update test.
		if ( $test_id ) {
			$result = $manager->update_test( $test_id, $test_input );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		} else {
			$test_id = $manager->create_test( $test_input );
			if ( is_wp_error( $test_id ) ) {
				wp_send_json_error( array( 'message' => $test_id->get_error_message() ) );
			}
		}

		$opti_ab_variant_id_map = array();

		// Save variants.
		if ( ! empty( $variants_input ) && is_array( $variants_input ) ) {
			$existing_variants               = Opti_Behavior_AB_Test_Database::get_variants( $test_id );
			$opti_ab_existing_variants_by_id = array();
			$opti_ab_existing_variants_by_name = array();
			$opti_ab_existing_name_counts    = array();

			foreach ( $existing_variants as $opti_ab_ev ) {
				$opti_ab_existing_variants_by_id[ (int) $opti_ab_ev->id ] = $opti_ab_ev;
				$opti_ab_existing_variants_by_name[ $opti_ab_ev->name ] = $opti_ab_ev;
				$opti_ab_existing_name_counts[ $opti_ab_ev->name ] = isset( $opti_ab_existing_name_counts[ $opti_ab_ev->name ] ) ? $opti_ab_existing_name_counts[ $opti_ab_ev->name ] + 1 : 1;
			}

			$limits                = Opti_Behavior_AB_Test_Database::get_free_limits();
			$opti_ab_is_pro_active = function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();
			$max_variants          = $opti_ab_is_pro_active ? 10 : ( isset( $limits['max_variants_per_test'] ) ? absint( $limits['max_variants_per_test'] ) : 3 );
			if ( count( $variants_input ) > $max_variants ) {
				if ( $test_id > 0 ) {
					delete_transient( 'opti_ab_save_lock_' . $test_id );
				}
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %d: maximum variant count */
							__( 'Maximum %d variants allowed per test.', 'opti-behavior' ),
							$max_variants
						),
					)
				);
			}

			$opti_ab_seen_variant_ids = array();

			foreach ( $variants_input as $idx => $opti_ab_vd ) {
				$opti_ab_incoming_variant_id = 0;
				if ( isset( $opti_ab_vd['id'] ) ) {
					$opti_ab_incoming_variant_id = absint( $opti_ab_vd['id'] );
				} elseif ( isset( $opti_ab_vd['variant_id'] ) ) {
					$opti_ab_incoming_variant_id = absint( $opti_ab_vd['variant_id'] );
				}

				unset( $opti_ab_vd['id'] );
				unset( $opti_ab_vd['variant_id'] );

				$opti_ab_vd['test_id']    = $test_id;
				$opti_ab_vd['sort_order'] = $idx;

				$opti_ab_existing_for_variant = null;
				if ( $opti_ab_incoming_variant_id && isset( $opti_ab_existing_variants_by_id[ $opti_ab_incoming_variant_id ] ) ) {
					$opti_ab_existing_for_variant = $opti_ab_existing_variants_by_id[ $opti_ab_incoming_variant_id ];
				} else {
					$opti_ab_vname = isset( $opti_ab_vd['name'] ) ? $opti_ab_vd['name'] : '';
					if ( $opti_ab_vname && isset( $opti_ab_existing_variants_by_name[ $opti_ab_vname ] ) && 1 === (int) $opti_ab_existing_name_counts[ $opti_ab_vname ] ) {
						$opti_ab_existing_for_variant = $opti_ab_existing_variants_by_name[ $opti_ab_vname ];
						$opti_ab_incoming_variant_id  = (int) $opti_ab_existing_for_variant->id;
					}
				}

				// Carry forward visual editor changes if the wizard didn't supply new ones.
				$opti_ab_new_data = isset( $opti_ab_vd['variant_data'] ) ? $opti_ab_vd['variant_data'] : '{}';
				if ( is_string( $opti_ab_new_data ) ) {
					$opti_ab_parsed = json_decode( $opti_ab_new_data, true );
				} else {
					$opti_ab_parsed = $opti_ab_new_data;
				}
				if ( ! is_array( $opti_ab_parsed ) ) {
					$opti_ab_parsed = array();
				}

				if ( $opti_ab_existing_for_variant ) {
					$opti_ab_existing_data = json_decode( $opti_ab_existing_for_variant->variant_data, true );
					if ( ! is_array( $opti_ab_existing_data ) ) {
						$opti_ab_existing_data = array();
					}

					if ( empty( $opti_ab_parsed ) ) {
						$opti_ab_parsed = $opti_ab_existing_data;
					} elseif ( ! array_key_exists( 'changes', $opti_ab_parsed ) && array_key_exists( 'changes', $opti_ab_existing_data ) ) {
						$opti_ab_parsed['changes'] = $opti_ab_existing_data['changes'];
					}

					$opti_ab_vd['variant_data'] = wp_json_encode( $opti_ab_parsed );
				}

				if ( $opti_ab_incoming_variant_id && isset( $opti_ab_existing_variants_by_id[ $opti_ab_incoming_variant_id ] ) ) {
					$result = Opti_Behavior_AB_Test_Database::update_variant( $opti_ab_incoming_variant_id, $opti_ab_vd );
					if ( ! $result ) {
						if ( $test_id > 0 ) {
							delete_transient( 'opti_ab_save_lock_' . $test_id );
						}
						wp_send_json_error( array( 'message' => __( 'Failed to update variant.', 'opti-behavior' ) ) );
					}

					$saved_variant_id = $opti_ab_incoming_variant_id;
				} else {
					$saved_variant_id = Opti_Behavior_AB_Test_Database::insert_variant( $opti_ab_vd );
					if ( false === $saved_variant_id ) {
						if ( $test_id > 0 ) {
							delete_transient( 'opti_ab_save_lock_' . $test_id );
						}
						wp_send_json_error( array( 'message' => __( 'Failed to add variant.', 'opti-behavior' ) ) );
					}
				}

				$opti_ab_seen_variant_ids[ (int) $saved_variant_id ] = true;
				$opti_ab_variant_id_map[] = array(
					'index' => (int) $idx,
					'id'    => (int) $saved_variant_id,
					'name'  => isset( $opti_ab_vd['name'] ) ? sanitize_text_field( $opti_ab_vd['name'] ) : '',
				);
			}

			foreach ( $existing_variants as $opti_ab_ev ) {
				if ( empty( $opti_ab_seen_variant_ids[ (int) $opti_ab_ev->id ] ) ) {
					Opti_Behavior_AB_Test_Database::delete_variant( $opti_ab_ev->id );
				}
			}
		}

		// Save goals.
		if ( ! empty( $goals_input ) && is_array( $goals_input ) ) {
			// QA-F-AB-004/005: validate the whole incoming goal set BEFORE the stored
			// goals are dropped, so a rejected payload leaves the saved goals intact,
			// and release the per-test save mutex before every error response.
			$opti_ab_goal_limits = Opti_Behavior_AB_Test_Database::get_free_limits();
			$opti_ab_max_goals   = ( function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active() )
				? 20
				: absint( $opti_ab_goal_limits['max_goals_per_test'] );

			if ( count( $goals_input ) > $opti_ab_max_goals ) {
				if ( $test_id > 0 ) {
					delete_transient( 'opti_ab_save_lock_' . $test_id );
				}
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %d: max goals */
							__( 'Maximum %d goals allowed per test.', 'opti-behavior' ),
							$opti_ab_max_goals
						),
					)
				);
			}

			foreach ( $goals_input as $opti_ab_gd ) {
				$opti_ab_goal_check = $manager->validate_goal( $test_id, $opti_ab_gd );
				if ( is_wp_error( $opti_ab_goal_check ) ) {
					if ( $test_id > 0 ) {
						delete_transient( 'opti_ab_save_lock_' . $test_id );
					}
					wp_send_json_error( array( 'message' => $opti_ab_goal_check->get_error_message() ) );
				}
			}

			Opti_Behavior_AB_Test_Database::delete_goals_for_test( $test_id );

			foreach ( $goals_input as $opti_ab_gd ) {
				$opti_ab_gd['test_id'] = $test_id;
				$result = $manager->add_goal( $test_id, $opti_ab_gd );
				if ( is_wp_error( $result ) ) {
					if ( $test_id > 0 ) {
						delete_transient( 'opti_ab_save_lock_' . $test_id );
					}
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}
			}
		}

		// Auto-start if launch requested.
		if ( $should_launch ) {
			$start_result = $manager->start_test( $test_id );
			if ( is_wp_error( $start_result ) ) {
				if ( $test_id > 0 ) {
					delete_transient( 'opti_ab_save_lock_' . $test_id );
				}
				// Test is saved but couldn't start — return the error with the test ID.
				wp_send_json_error( array(
					'message' => $start_result->get_error_message(),
					'test_id' => $test_id,
				) );
			}
		}

		if ( $opti_ab_origin_insight_id > 0 && $test_id > 0 ) {
			/**
			 * Fires when an A/B test is saved from a Smart Insight story.
			 *
			 * Lets the Smart Insights layer write the reciprocal experiment link
			 * (`experiment_json.test_id`) on the originating insight row.
			 *
			 * @since 1.3.9
			 *
			 * @param int   $test_id    Saved test ID.
			 * @param int   $insight_id Originating Smart Insight ID.
			 * @param array $test_input Sanitized test fields that were saved.
			 * @param bool  $launched   Whether the test was launched by this save.
			 */
			do_action( 'opti_behavior_ab_test_linked_to_insight', $test_id, $opti_ab_origin_insight_id, $test_input, (bool) $should_launch );
		}

		// DEF-AB-017/018/019 fix: release the per-test-id save lock before responding.
		if ( $test_id > 0 ) {
			delete_transient( 'opti_ab_save_lock_' . $test_id );
		}

		wp_send_json_success( array(
			'test_id' => $test_id,
			'variants' => $opti_ab_variant_id_map,
			'message' => $should_launch ? __( 'Test launched!', 'opti-behavior' ) : __( 'Test saved.', 'opti-behavior' ),
		) );
	}

	/**
	 * AJAX: Update a single variant's data (from the results-page modal).
	 *
	 * Merges the incoming JSON fields into the existing variant_data so
	 * fields not present in the request (e.g. image_url) are preserved.
	 */
	public function ajax_ab_update_variant_data() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$variant_id = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$new_json   = isset( $_POST['variant_data'] ) ? wp_unslash( $_POST['variant_data'] ) : '{}';
		$new_data   = json_decode( $new_json, true );

		if ( ! $variant_id || ! is_array( $new_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'opti-behavior' ) ) );
		}

		global $wpdb;
		$table   = esc_sql( $wpdb->prefix . 'optibehavior_ab_variants' );
		$variant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $variant_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $variant ) {
			wp_send_json_error( array( 'message' => __( 'Variant not found.', 'opti-behavior' ) ) );
		}

		// Merge new fields into existing data so unmodified fields are kept.
		$existing = json_decode( $variant->variant_data, true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$merged = array_merge( $existing, $new_data );

		$result = $wpdb->update(
			$table,
			array( 'variant_data' => wp_json_encode( $merged ) ),
			array( 'id' => $variant_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Database error.', 'opti-behavior' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Variant updated.', 'opti-behavior' ) ) );
	}

	/**
	 * AJAX: Update free plan limits.
	 */
	public function ajax_ab_update_free_limits() {
		check_ajax_referer( 'opti_behavior_ab_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'opti-behavior' ) ) );
		}

		$new_limits = array();

		if ( isset( $_POST['max_concurrent_tests'] ) ) {
			$new_limits['max_concurrent_tests'] = absint( $_POST['max_concurrent_tests'] );
		}
		if ( isset( $_POST['max_variants_per_test'] ) ) {
			$new_limits['max_variants_per_test'] = absint( $_POST['max_variants_per_test'] );
		}
		if ( isset( $_POST['max_goals_per_test'] ) ) {
			$new_limits['max_goals_per_test'] = absint( $_POST['max_goals_per_test'] );
		}

		Opti_Behavior_AB_Test_Database::update_free_limits( $new_limits );

		wp_send_json_success( array(
			'limits' => Opti_Behavior_AB_Test_Database::get_free_limits(),
		) );
	}

	// =========================================================================
	// Frontend AJAX Handlers (Public)
	// =========================================================================

	/**
	 * AJAX: Track batch of impressions and conversions.
	 *
	 * This is the main frontend tracking endpoint, called by ab-test-tracker.js.
	 */
	public function ajax_ab_track_batch() {
		// Verify nonce (frontend nonce).
		if ( ! check_ajax_referer( 'opti_behavior_ab_frontend_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
		}

		// Ingest gate: silently ignore A/B events from bots / excluded IPs
		// (200 success so the tracker JS does not retry).
		if ( Opti_Behavior_Ingest_Gate::should_reject( 'ab_track_batch' ) ) {
			wp_send_json_success( array( 'status' => 'ignored' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$events_json = isset( $_POST['events'] ) ? wp_unslash( $_POST['events'] ) : '[]';
		$events      = json_decode( $events_json, true );

		if ( ! is_array( $events ) ) {
			wp_send_json_error( array( 'message' => 'Invalid events.' ) );
		}

		$processed = 0;

		foreach ( $events as $event ) {
			if ( ! isset( $event['type'] ) || ! isset( $event['data'] ) ) {
				continue;
			}

			$data = $event['data'];

			switch ( $event['type'] ) {
				case 'impression':
					$this->process_impression( $data );
					++$processed;
					break;

				case 'conversion':
					$this->process_conversion( $data );
					++$processed;
					break;
			}
		}

		wp_send_json_success( array( 'processed' => $processed ) );
	}

	/**
	 * AJAX: Track single conversion (legacy/fallback endpoint).
	 */
	public function ajax_ab_track_conversion() {
		if ( ! check_ajax_referer( 'opti_behavior_ab_frontend_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
		}

		// Ingest gate: silently ignore A/B conversions from bots / excluded IPs
		// (200 success so the tracker JS does not retry).
		if ( Opti_Behavior_Ingest_Gate::should_reject( 'ab_track_conversion' ) ) {
			wp_send_json_success( array( 'status' => 'ignored' ) );
		}

		$data = array(
			'test_id'    => isset( $_POST['test_id'] ) ? absint( $_POST['test_id'] ) : 0,
			'variant_id' => isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0,
			'goal_id'    => isset( $_POST['goal_id'] ) ? absint( $_POST['goal_id'] ) : 0,
			'revenue'    => isset( $_POST['revenue'] ) ? floatval( $_POST['revenue'] ) : 0,
		);

		$this->process_conversion( $data );

		wp_send_json_success();
	}

	// =========================================================================
	// Processing Helpers
	// =========================================================================

	/**
	 * Process an impression event.
	 *
	 * @param array $data Impression data { test_id, variant_id }.
	 */
	private function process_impression( $data ) {
		if ( empty( $data['test_id'] ) || empty( $data['variant_id'] ) ) {
			return;
		}

		$visitor_id = $this->get_ab_visitor_id();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		Opti_Behavior_AB_Test_Database::record_impression( array(
			'test_id'     => absint( $data['test_id'] ),
			'variant_id'  => absint( $data['variant_id'] ),
			'visitor_id'  => $visitor_id,
			'session_id'  => $this->get_ab_session_id(),
			'device_type' => $this->ab_detect_device_type( $user_agent ),
			'browser'     => $this->ab_detect_browser( $user_agent ),
			'country'     => $this->ab_get_visitor_country(),
		) );
	}

	/**
	 * Detect device type from a User-Agent string.
	 *
	 * Returns 'mobile', 'tablet', or 'desktop'.
	 *
	 * @param string $user_agent Raw User-Agent string.
	 * @return string
	 */
	private function ab_detect_device_type( $user_agent ) {
		if ( preg_match( '/tablet|ipad|playbook|silk/i', $user_agent ) ) {
			return 'tablet';
		}
		if ( preg_match( '/mobile|android|iphone|ipod|blackberry|opera mini|iemobile|wpdesktop/i', $user_agent ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Detect browser name from a User-Agent string.
	 *
	 * Detection order matters: Edge and Opera must be checked before Chrome
	 * because their UA strings also contain the "Chrome" token.
	 *
	 * @param string $user_agent Raw User-Agent string.
	 * @return string Browser name ('Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', or 'Other').
	 */
	private function ab_detect_browser( $user_agent ) {
		if ( preg_match( '/Edg\//i', $user_agent ) ) {
			return 'Edge';
		}
		if ( preg_match( '/OPR\//i', $user_agent ) || preg_match( '/Opera\//i', $user_agent ) ) {
			return 'Opera';
		}
		if ( preg_match( '/Firefox\//i', $user_agent ) ) {
			return 'Firefox';
		}
		if ( preg_match( '/Chrome\//i', $user_agent ) ) {
			return 'Chrome';
		}
		if ( preg_match( '/Safari\//i', $user_agent ) ) {
			return 'Safari';
		}
		return 'Other';
	}

	/**
	 * Resolve the visitor's country from request headers.
	 *
	 * Checks common geo-enrichment headers (CloudFlare, generic reverse-proxy,
	 * Apache mod_geoip) in priority order. Returns a 2-character ISO country
	 * code (upper-case) or NULL when no geo header is present.
	 *
	 * @return string|null Two-letter ISO 3166-1 alpha-2 country code, or null.
	 */
	private function ab_get_visitor_country() {
		$geo_headers = array(
			'HTTP_CF_IPCOUNTRY',   // CloudFlare.
			'HTTP_X_COUNTRY_CODE', // Generic reverse-proxy enrichment.
			'GEOIP_COUNTRY_CODE',  // Apache mod_geoip.
		);

		foreach ( $geo_headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				// 'XX' is CloudFlare's placeholder for unknown — treat as absent.
				if ( strlen( $code ) === 2 && 'XX' !== $code ) {
					return $code;
				}
			}
		}

		return null;
	}

	/**
	 * Process a conversion event.
	 *
	 * @param array $data Conversion data { test_id, variant_id, goal_id, revenue }.
	 */
	private function process_conversion( $data ) {
		if ( empty( $data['test_id'] ) || empty( $data['goal_id'] ) ) {
			return;
		}

		$test_id    = absint( $data['test_id'] );
		$visitor_id = $this->get_ab_visitor_id();
		$session_id = $this->get_ab_session_id();

		// Get variant_id from the impression if not provided.
		$variant_id = ! empty( $data['variant_id'] ) ? absint( $data['variant_id'] ) : 0;
		if ( ! $variant_id ) {
			// Look up from bucketer/cookie.
			$cookie_name = 'opti_ab_' . absint( $data['test_id'] );
			if ( isset( $_COOKIE[ $cookie_name ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$variant_id = absint( $_COOKIE[ $cookie_name ] );
			}
		}

		if ( ! $variant_id ) {
			return; // Can't attribute conversion without variant.
		}

		$visitor_id = $this->resolve_ab_conversion_visitor_id( $test_id, $visitor_id, $session_id, $variant_id );

		$should_track = apply_filters( 'opti_behavior_ab_should_track_conversion', true, $data, $visitor_id );
		if ( ! $should_track ) {
			return;
		}

		Opti_Behavior_AB_Test_Database::record_conversion( array(
			'test_id'    => $test_id,
			'variant_id' => $variant_id,
			'goal_id'    => absint( $data['goal_id'] ),
			'visitor_id' => $visitor_id,
			'session_id' => $session_id,
			'revenue'    => isset( $data['revenue'] ) ? floatval( $data['revenue'] ) : 0,
		) );

		do_action(
			'opti_behavior_ab_conversion_tracked',
			absint( $data['test_id'] ),
			$variant_id,
			absint( $data['goal_id'] ),
			$visitor_id
		);
	}

	/**
	 * Resolve the canonical visitor ID for a conversion.
	 *
	 * In anonymous/effectively-anonymous mode the session recorder can replace
	 * the JS-readable visitor cookie with a server-side daily hash after the A/B
	 * impression has already been recorded with the per-session visitor id that
	 * was posted with that impression batch. If later conversion batches use the
	 * daily hash, the UNIQUE (test_id, goal_id, visitor_id) key collapses many
	 * fresh-cookie Playwright/private sessions into one conversion per goal.
	 *
	 * The session id stays stable across the impression and conversion batches,
	 * so prefer the impression's visitor_id when we can safely find the current
	 * test/session/variant impression row.
	 *
	 * @param int    $test_id    A/B test ID.
	 * @param string $visitor_id Visitor ID from the conversion request.
	 * @param string $session_id Session ID from the conversion request.
	 * @param int    $variant_id Variant ID from the conversion event.
	 * @return string Canonical visitor ID for conversion de-duping.
	 */
	private function resolve_ab_conversion_visitor_id( $test_id, $visitor_id, $session_id, $variant_id ) {
		if ( empty( $test_id ) || empty( $session_id ) ) {
			return $visitor_id;
		}

		global $wpdb;
		$impressions_table = esc_sql( $wpdb->prefix . 'optibehavior_ab_impressions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$impression_visitor_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT visitor_id FROM {$impressions_table} WHERE test_id = %d AND variant_id = %d AND session_id = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$test_id,
				$variant_id,
				$session_id
			)
		);

		if ( ! empty( $impression_visitor_id ) ) {
			return sanitize_text_field( $impression_visitor_id );
		}

		return $visitor_id;
	}

	/**
	 * Get visitor ID for A/B tracking.
	 *
	 * Resolution order:
	 *  1. POST payload  — visitor_id sent by ab-test-tracker.js. In cookieless
	 *     Anonymous Mode this is the server daily hash (from
	 *     optiBehaviorHeatmapConfig.anon_vid); in full mode it is the value stored
	 *     by session-recorder.js in wp_optibehavior_sessions.visitor_id, enabling
	 *     the recording JOIN.
	 *  2. optibehavior_vid cookie  — full-mode (post-consent) visitor cookie.
	 *  3. opti_behavior_vid cookie  — legacy / manual-test cookie.
	 *  4. Free-plugin session system — anonymous daily hash, or the httpOnly
	 *     optibehavior_vid cookie.  Final fallback only. Anonymous Mode is
	 *     cookieless: the optibehavior_anon_vid cookie is no longer read.
	 *
	 * @return string Visitor ID.
	 */
	private function get_ab_visitor_id() {
		// 0. Logged-in WP user: canonical id is always wp_user_<id>.
		//    The JS payload must NOT override this — prevents Cause B (JS/PHP race)
		//    and stale localStorage values from inflating visitor counts.
		if ( is_user_logged_in() ) {
			return 'wp_user_' . get_current_user_id();
		}

		// 1. JS-supplied value (from localStorage / JS cookie set by session-recorder.js).
		//    This is the canonical visitor ID that matches wp_optibehavior_sessions.
		if ( ! empty( $_POST['visitor_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by calling AJAX handler.
			return sanitize_text_field( wp_unslash( $_POST['visitor_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by calling AJAX handler.
		}

		// 2. Full-mode (post-consent) JS-readable visitor cookie. Anonymous Mode
		// is cookieless — the optibehavior_anon_vid cookie is no longer written or
		// read; the anonymous id arrives via the POST payload above (step 1).
		if ( isset( $_COOKIE['optibehavior_vid'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return sanitize_text_field( wp_unslash( $_COOKIE['optibehavior_vid'] ) );
		}

		// 3. Legacy cookie set by some integrations / manual tests.
		if ( isset( $_COOKIE['opti_behavior_vid'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return sanitize_text_field( wp_unslash( $_COOKIE['opti_behavior_vid'] ) );
		}

		// 4. Free-plugin session system (httpOnly cookie or anonymous hash).
		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			$core    = Opti_Behavior_Heatmap_Core::get_instance();
			$session = $core->get_session();
			if ( $session && method_exists( $session, 'get_visitor_id' ) ) {
				return $session->get_visitor_id();
			}
		}

		return 'anonymous_' . wp_generate_uuid4();
	}

	/**
	 * Get session ID for A/B tracking.
	 *
	 * Resolution order:
	 *  1. POST payload  — session_id sent by ab-test-tracker.js from the Pro
	 *     session-recorder's localStorage blob.  This matches the session_id in
	 *     wp_optibehavior_recordings, enabling the direct session_id JOIN in
	 *     ajax_get_variant_recordings() Strategy B.
	 *  2. Free-plugin session system  — generates a PHP UUID4 per AJAX request;
	 *     does NOT match recordings.session_id but still gives a non-empty value
	 *     for the Heatmap Impact session count.
	 *
	 * @return string Session ID.
	 */
	private function get_ab_session_id() {
		// 1. JS-supplied value from the Free heatmap session cookie,
		// anonymous session storage, or Pro session-recorder localStorage.
		if ( ! empty( $_POST['session_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by calling AJAX handler.
			return sanitize_text_field( wp_unslash( $_POST['session_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by calling AJAX handler.
		}

		// 2. Free-plugin session cookies are available to the AJAX request even
		// when JavaScript did not include them in the payload.
		if ( isset( $_COOKIE['optibehavior_sid'] ) && ! empty( $_COOKIE['optibehavior_sid'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return sanitize_text_field( wp_unslash( $_COOKIE['optibehavior_sid'] ) );
		}
		if ( isset( $_COOKIE['opti_behavior_session_id'] ) && ! empty( $_COOKIE['opti_behavior_session_id'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return sanitize_text_field( wp_unslash( $_COOKIE['opti_behavior_session_id'] ) );
		}

		// 3. Free-plugin session system fallback.
		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			$core    = Opti_Behavior_Heatmap_Core::get_instance();
			$session = $core->get_session();
			if ( $session && method_exists( $session, 'get_session_id' ) ) {
				return $session->get_session_id();
			}
		}

		return '';
	}
}
