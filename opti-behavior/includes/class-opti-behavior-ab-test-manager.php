<?php
/**
 * A/B Test Manager — Test CRUD, Lifecycle & State Machine
 *
 * Provides the business logic layer for creating, managing, and running
 * A/B tests. Orchestrates between the database, bucketer, and stats engine.
 *
 * @package opti-behavior
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opti_Behavior_AB_Test_Manager {

	/**
	 * Valid test types.
	 *
	 * @var array
	 */
	const TEST_TYPES = array(
		'page_split',
		'headline',
		'element',
		'shortcode',
		'woocommerce',
		'css',
		'menu',
		'widget',
		'mvt',
		'funnel',
	);

	/**
	 * Valid test statuses.
	 *
	 * @var array
	 */
	const STATUSES = array( 'draft', 'running', 'paused', 'completed', 'applied', 'archived' );

	/**
	 * Free plan test types (subset) — types available without Pro.
	 *
	 * @var array
	 */
	const FREE_TEST_TYPES = array( 'page_split', 'element' );

	/**
	 * Types allowed for new test creation (excludes legacy removed types).
	 *
	 * @var array
	 */
	const CREATABLE_TEST_TYPES = array( 'page_split', 'element', 'woocommerce' );

	/**
	 * Legacy test types — existing DB tests remain renderable but new creation is blocked.
	 *
	 * @var array
	 */
	const LEGACY_TEST_TYPES = array( 'headline', 'shortcode', 'css' );

	/**
	 * Free plan goal types.
	 *
	 * @var array
	 */
	const FREE_GOAL_TYPES = array( 'page_visit', 'click', 'form_submit' );

	/**
	 * Valid state transitions: old_status => array of allowed new statuses.
	 *
	 * @var array
	 */
	const TRANSITIONS = array(
		'draft'     => array( 'running', 'deleted' ),
		'running'   => array( 'paused', 'completed', 'deleted' ),
		'paused'    => array( 'running', 'completed', 'deleted' ),
		'completed' => array( 'applied', 'archived', 'deleted' ),
		'applied'   => array( 'completed', 'archived', 'deleted' ),
		'archived'  => array( 'deleted' ),
	);

	/**
	 * Singleton instance.
	 *
	 * @var Opti_Behavior_AB_Test_Manager|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Opti_Behavior_AB_Test_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// =========================================================================
	// Test CRUD
	// =========================================================================

	/**
	 * Create a new A/B test.
	 *
	 * Enforces free plan limits (dynamic via WordPress options).
	 *
	 * @since 1.3.0
	 * @param array $data Test data.
	 * @return int|WP_Error Test ID or error.
	 */
	public function create_test( $data ) {
		// Validate test type.
		$test_type = isset( $data['test_type'] ) ? sanitize_key( $data['test_type'] ) : 'page_split';
		if ( ! in_array( $test_type, self::CREATABLE_TEST_TYPES, true ) ) {
			return new \WP_Error( 'invalid_test_type', __( 'Invalid test type.', 'opti-behavior' ) );
		}

		// Check if Pro is required for this test type.
		if ( ! in_array( $test_type, self::FREE_TEST_TYPES, true ) && ! $this->is_pro_active() ) {
			return new \WP_Error( 'pro_required', __( 'This test type requires Opti-Behavior Pro.', 'opti-behavior' ) );
		}

		// Enforce concurrent test limit (dynamic).
		$limits      = Opti_Behavior_AB_Test_Database::get_free_limits();
		$max_tests   = $this->is_pro_active() ? PHP_INT_MAX : $limits['max_concurrent_tests'];
		$active_count = Opti_Behavior_AB_Test_Database::count_tests( array( 'status' => 'running' ) );
		$active_count += Opti_Behavior_AB_Test_Database::count_tests( array( 'status' => 'paused' ) );

		// Draft tests don't count toward the limit, but we still cap total.
		// The limit applies when trying to START a test (see start_test method).

		// Validate required fields.
		if ( empty( $data['name'] ) ) {
			return new \WP_Error( 'missing_name', __( 'Test name is required.', 'opti-behavior' ) );
		}

		$data['test_type'] = $test_type;
		$data['status']    = 'draft';

		$test_id = Opti_Behavior_AB_Test_Database::insert_test( $data );

		if ( false === $test_id ) {
			return new \WP_Error( 'db_error', __( 'Failed to create test.', 'opti-behavior' ) );
		}

		do_action( 'opti_behavior_ab_test_status_changed', $test_id, '', 'draft' );

		return $test_id;
	}

	/**
	 * Update a test.
	 *
	 * Prevents changing core settings on a running test.
	 *
	 * @since 1.3.0
	 * @param int   $test_id Test ID.
	 * @param array $data    Fields to update.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_test( $test_id, $data ) {
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		// Prevent editing core settings on running tests.
		$locked_fields = array( 'test_type', 'target_url', 'target_post_id', 'stat_engine' );
		if ( 'running' === $test->status ) {
			foreach ( $locked_fields as $field ) {
				if ( isset( $data[ $field ] ) && $data[ $field ] !== $test->$field ) {
					return new \WP_Error(
						'test_running',
						/* translators: %s: field name */
						sprintf( __( 'Cannot change "%s" while test is running. Pause the test first.', 'opti-behavior' ), $field )
					);
				}
			}
		}

		return Opti_Behavior_AB_Test_Database::update_test( $test_id, $data );
	}

	/**
	 * Get a test with its variants and goals.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return object|null Test object with ->variants and ->goals populated.
	 */
	public function get_test( $test_id ) {
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return null;
		}

		$test->variants = Opti_Behavior_AB_Test_Database::get_variants( $test_id );
		$test->goals    = Opti_Behavior_AB_Test_Database::get_goals( $test_id );
		$test->settings = $test->settings ? json_decode( $test->settings, true ) : array();

		return $test;
	}

	/**
	 * Delete a test and all associated data.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return bool|WP_Error True on success.
	 */
	public function delete_test( $test_id ) {
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		// Must stop running test first.
		if ( 'running' === $test->status ) {
			$this->transition_status( $test_id, 'completed' );
		}

		Opti_Behavior_AB_Test_Database::delete_test( $test_id );
		Opti_Behavior_AB_Test_Database::invalidate_active_tests_cache();

		return true;
	}

	/**
	 * Duplicate a test.
	 *
	 * Creates an independent copy with draft status.
	 *
	 * @since 1.3.0
	 * @param int $test_id Source test ID.
	 * @return int|WP_Error New test ID or error.
	 */
	public function duplicate_test( $test_id ) {
		$source = $this->get_test( $test_id );
		if ( ! $source ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		// Create new test.
		$new_test_id = Opti_Behavior_AB_Test_Database::insert_test( array(
			/* translators: %s: original test name */
			'name'               => sprintf( __( '%s (Copy)', 'opti-behavior' ), $source->name ),
			'description'        => $source->description,
			'test_type'          => $source->test_type,
			'status'             => 'draft',
			'stat_engine'        => $source->stat_engine,
			'optimization'       => $source->optimization,
			'confidence_level'   => $source->confidence_level,
			'min_sample_size'    => $source->min_sample_size,
			'min_duration_hours' => $source->min_duration_hours,
			'traffic_percent'    => $source->traffic_percent,
			'target_url'         => $source->target_url,
			'target_post_id'     => $source->target_post_id,
			'target_selector'    => $source->target_selector,
			'settings'           => $source->settings,
		) );

		if ( ! $new_test_id ) {
			return new \WP_Error( 'db_error', __( 'Failed to duplicate test.', 'opti-behavior' ) );
		}

		// Copy variants.
		foreach ( $source->variants as $variant ) {
			Opti_Behavior_AB_Test_Database::insert_variant( array(
				'test_id'        => $new_test_id,
				'name'           => $variant->name,
				'is_control'     => $variant->is_control,
				'traffic_weight' => $variant->traffic_weight,
				'variant_data'   => $variant->variant_data,
				'sort_order'     => $variant->sort_order,
			) );
		}

		// Copy goals.
		foreach ( $source->goals as $goal ) {
			Opti_Behavior_AB_Test_Database::insert_goal( array(
				'test_id'     => $new_test_id,
				'name'        => $goal->name,
				'goal_type'   => $goal->goal_type,
				'goal_config' => $goal->goal_config,
				'is_primary'  => $goal->is_primary,
			) );
		}

		return $new_test_id;
	}

	// =========================================================================
	// Variant Management
	// =========================================================================

	/**
	 * Add a variant to a test.
	 *
	 * @since 1.3.0
	 * @param int   $test_id Test ID.
	 * @param array $data    Variant data.
	 * @return int|WP_Error Variant ID or error.
	 */
	public function add_variant( $test_id, $data ) {
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		if ( 'running' === $test->status ) {
			return new \WP_Error( 'test_running', __( 'Cannot add variants while test is running.', 'opti-behavior' ) );
		}

		// Check variant limit (dynamic).
		$limits       = Opti_Behavior_AB_Test_Database::get_free_limits();
		$max_variants = $this->is_pro_active() ? 10 : $limits['max_variants_per_test'];
		$current      = count( Opti_Behavior_AB_Test_Database::get_variants( $test_id ) );

		if ( $current >= $max_variants ) {
			return new \WP_Error(
				'variant_limit',
				sprintf(
					/* translators: %d: max variants */
					__( 'Maximum %d variants allowed per test.', 'opti-behavior' ),
					$max_variants
				)
			);
		}

		$data['test_id']    = $test_id;
		$data['sort_order'] = $current;

		$variant_id = Opti_Behavior_AB_Test_Database::insert_variant( $data );

		if ( false === $variant_id ) {
			return new \WP_Error( 'db_error', __( 'Failed to add variant.', 'opti-behavior' ) );
		}

		return $variant_id;
	}

	// =========================================================================
	// Goal Management
	// =========================================================================

	/**
	 * Validate a goal payload without writing anything.
	 *
	 * Used by the wizard's full-save endpoint so an invalid goal set can be
	 * rejected before the stored goals are replaced (QA-F-AB-005).
	 *
	 * @since 1.9.0.8
	 * @param int   $test_id Test ID.
	 * @param array $data    Goal data.
	 * @return true|WP_Error True when the goal may be inserted.
	 */
	public function validate_goal( $test_id, $data ) {
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		// Check goal type is allowed for free.
		$goal_type = isset( $data['goal_type'] ) ? sanitize_key( $data['goal_type'] ) : 'page_visit';
		if ( ! in_array( $goal_type, self::FREE_GOAL_TYPES, true ) && ! $this->is_pro_active() ) {
			return new \WP_Error( 'pro_required', __( 'This goal type requires Opti-Behavior Pro.', 'opti-behavior' ) );
		}

		return true;
	}

	/**
	 * Add a goal to a test.
	 *
	 * @since 1.3.0
	 * @param int   $test_id Test ID.
	 * @param array $data    Goal data.
	 * @return int|WP_Error Goal ID or error.
	 */
	public function add_goal( $test_id, $data ) {
		$opti_ab_valid = $this->validate_goal( $test_id, $data );
		if ( is_wp_error( $opti_ab_valid ) ) {
			return $opti_ab_valid;
		}

		// Check goal limit (dynamic).
		$limits    = Opti_Behavior_AB_Test_Database::get_free_limits();
		$max_goals = $this->is_pro_active() ? 20 : $limits['max_goals_per_test'];
		$current   = count( Opti_Behavior_AB_Test_Database::get_goals( $test_id ) );

		if ( $current >= $max_goals ) {
			return new \WP_Error(
				'goal_limit',
				sprintf(
					/* translators: %d: max goals */
					__( 'Maximum %d goals allowed per test.', 'opti-behavior' ),
					$max_goals
				)
			);
		}

		$data['test_id'] = $test_id;

		$goal_id = Opti_Behavior_AB_Test_Database::insert_goal( $data );

		if ( false === $goal_id ) {
			return new \WP_Error( 'db_error', __( 'Failed to add goal.', 'opti-behavior' ) );
		}

		return $goal_id;
	}

	// =========================================================================
	// Cron scheduling
	// =========================================================================

	/**
	 * Canonical local time slot of each daily A/B cron hook.
	 *
	 * @since 1.9.0.8
	 * @var array
	 */
	const CRON_DAILY_SLOTS = array(
		'opti_behavior_ab_aggregate_daily' => '0315',
		'opti_behavior_ab_cleanup'         => '0500',
	);

	/**
	 * Arm the A/B cron events and repair a drifted slot.
	 *
	 * wp_next_scheduled() only proves that *an* event exists, so an install
	 * upgraded from an older version keeps whatever time that version picked
	 * (QA-B-AB-019 observed 18:13 for the 05:00 cleanup) forever. Re-arm any
	 * daily hook that sits more than an hour away from its canonical slot,
	 * the same repair the heatmap daily hooks already perform.
	 *
	 * Cost: one cron-array read, so it is safe to call on every admin load.
	 *
	 * @since 1.9.0.8
	 */
	public static function ensure_cron_schedules() {
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$now = new \DateTime( 'now', $tz );

		foreach ( self::CRON_DAILY_SLOTS as $hook => $hhmm ) {
			$slot = new \DateTime( 'T' . $hhmm, $tz );
			if ( $slot < $now ) {
				$slot->add( new \DateInterval( 'P1D' ) );
			}
			$slot_ts = $slot->getTimestamp();
			$next    = wp_next_scheduled( $hook );

			if ( ! $next ) {
				wp_schedule_event( $slot_ts, 'daily', $hook );
			} elseif ( $next < $slot_ts || $slot_ts + HOUR_IN_SECONDS < $next ) {
				wp_clear_scheduled_hook( $hook );
				wp_schedule_event( $slot_ts, 'daily', $hook );
			}
		}

		// Hourly auto-winner check: armed when missing, never re-slotted.
		if ( ! wp_next_scheduled( 'opti_behavior_ab_auto_winner_check' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'opti_behavior_ab_auto_winner_check' );
		}
	}

	// =========================================================================
	// State Machine / Lifecycle
	// =========================================================================

	/**
	 * Transition a test to a new status.
	 *
	 * @since 1.3.0
	 * @param int    $test_id    Test ID.
	 * @param string $new_status Target status.
	 * @return bool|WP_Error True on success.
	 */
	public function transition_status( $test_id, $new_status ) {
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		$old_status = $test->status;

		// Validate transition.
		if ( ! isset( self::TRANSITIONS[ $old_status ] ) || ! in_array( $new_status, self::TRANSITIONS[ $old_status ], true ) ) {
			return new \WP_Error(
				'invalid_transition',
				sprintf(
					/* translators: 1: current status, 2: target status */
					__( 'Cannot transition from "%1$s" to "%2$s".', 'opti-behavior' ),
					$old_status,
					$new_status
				)
			);
		}

		return $this->execute_transition( $test_id, $test, $old_status, $new_status );
	}

	/**
	 * Start a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return bool|WP_Error
	 */
	public function start_test( $test_id ) {
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		// Validate: at least 2 variants required.
		$variants = Opti_Behavior_AB_Test_Database::get_variants( $test_id );
		if ( count( $variants ) < 2 ) {
			return new \WP_Error( 'not_enough_variants', __( 'At least 2 variants are required to start a test.', 'opti-behavior' ) );
		}

		// Validate: at least 1 goal required.
		$goals = Opti_Behavior_AB_Test_Database::get_goals( $test_id );
		if ( empty( $goals ) ) {
			return new \WP_Error( 'no_goals', __( 'At least 1 conversion goal is required to start a test.', 'opti-behavior' ) );
		}

		// Validate: must have exactly 1 control.
		$controls = array_filter( $variants, function( $v ) { return (int) $v->is_control === 1; } );
		if ( empty( $controls ) ) {
			return new \WP_Error( 'no_control', __( 'One variant must be marked as control.', 'opti-behavior' ) );
		}

		// Validate: element tests must have at least one non-control variant with visual changes.
		if ( 'element' === $test->test_type ) {
			$opti_ab_has_changes = false;
			foreach ( $variants as $opti_ab_v ) {
				if ( (int) $opti_ab_v->is_control === 1 ) {
					continue;
				}
				$opti_ab_vdata = json_decode( $opti_ab_v->variant_data, true );
				if ( is_array( $opti_ab_vdata ) && ! empty( $opti_ab_vdata['changes'] ) ) {
					$opti_ab_has_changes = true;
					break;
				}
			}
			if ( ! $opti_ab_has_changes ) {
				return new \WP_Error(
					'no_variant_changes',
					__( 'Element tests require at least one variant with visual changes. Use the Visual Editor to configure changes before launching.', 'opti-behavior' )
				);
			}
		}

		// Enforce concurrent test limit (dynamic).
		$limits      = Opti_Behavior_AB_Test_Database::get_free_limits();
		$max_tests   = $this->is_pro_active() ? PHP_INT_MAX : $limits['max_concurrent_tests'];
		$active_count = Opti_Behavior_AB_Test_Database::count_tests( array( 'status' => 'running' ) );

		if ( $test->status !== 'running' && $active_count >= $max_tests ) {
			return new \WP_Error(
				'test_limit',
				sprintf(
					/* translators: %d: max concurrent tests */
					__( 'Maximum %d concurrent tests allowed. Stop or complete an existing test first.', 'opti-behavior' ),
					$max_tests
				)
			);
		}

		return $this->transition_status( $test_id, 'running' );
	}

	/**
	 * Pause a running test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return bool|WP_Error
	 */
	public function pause_test( $test_id ) {
		return $this->transition_status( $test_id, 'paused' );
	}

	/**
	 * Resume a paused test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return bool|WP_Error
	 */
	public function resume_test( $test_id ) {
		return $this->transition_status( $test_id, 'running' );
	}

	/**
	 * Complete a test (stop and finalize).
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return bool|WP_Error
	 */
	public function complete_test( $test_id ) {
		return $this->transition_status( $test_id, 'completed' );
	}

	/**
	 * Archive a completed test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return bool|WP_Error
	 */
	public function archive_test( $test_id ) {
		return $this->transition_status( $test_id, 'archived' );
	}

	/**
	 * Declare a winner for a completed test.
	 *
	 * @since 1.3.0
	 * @param int $test_id    Test ID.
	 * @param int $variant_id Winning variant ID.
	 * @return bool|WP_Error True on success.
	 */
	public function declare_winner( $test_id, $variant_id ) {
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		if ( 'completed' !== $test->status && 'running' !== $test->status ) {
			return new \WP_Error( 'invalid_status', __( 'Test must be running or completed to declare a winner.', 'opti-behavior' ) );
		}

		// Verify variant belongs to this test.
		$variant = Opti_Behavior_AB_Test_Database::get_variant( $variant_id );
		if ( ! $variant || (int) $variant->test_id !== (int) $test_id ) {
			return new \WP_Error( 'invalid_variant', __( 'Variant does not belong to this test.', 'opti-behavior' ) );
		}

		// If running, complete first.
		if ( 'running' === $test->status ) {
			$this->execute_transition( $test_id, $test, 'running', 'completed' );
		}

		Opti_Behavior_AB_Test_Database::update_test( $test_id, array(
			'winner_variant_id' => absint( $variant_id ),
		) );

		// Write a 'declared' audit log entry so the full decision history is available.
		$declared_key = sha1( 'declared_' . $test_id . '_' . $variant_id . '_' . get_current_user_id() );
		Opti_Behavior_AB_Test_Database::insert_decision_log( array(
			'test_id'           => $test_id,
			'event'             => 'declared',
			'actor_user_id'     => get_current_user_id(),
			'winner_variant_id' => absint( $variant_id ),
			'target_post_id'    => isset( $test->target_post_id ) ? absint( $test->target_post_id ) : null,
			'idempotency_key'   => $declared_key,
			'notes'             => sprintf( 'Winner declared: variant #%d', absint( $variant_id ) ),
		) );

		do_action( 'opti_behavior_ab_winner_declared', $test_id, $variant_id );

		return true;
	}

	/**
	 * Compute a dry-run diff of what apply_winner() would change, without writing anything.
	 *
	 * Called by the preflight AJAX endpoint to power the Phase-A diff preview dialog.
	 * Reads current post/product values and compares them to the winning variant_data.
	 * Never mutates any data.
	 *
	 * Returns a structured payload that the JS diff dialog can render directly:
	 *   {
	 *     test_type        : string,
	 *     target_post_id   : int|null,
	 *     target_post_title: string,
	 *     target_edit_url  : string,
	 *     changed_fields   : { field: { from, to } },
	 *     unknown_keys     : string[],
	 *     idempotency_key  : string,  // same key apply_winner() will use for the current minute-bucket
	 *     warnings         : string[],
	 *   }
	 *
	 * @since 1.3.3
	 * @param int $test_id Test ID.
	 * @return array|WP_Error Diff payload or error.
	 */
	public function build_apply_diff( $test_id ) {
		$test = $this->get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		if ( empty( $test->winner_variant_id ) ) {
			return new \WP_Error( 'no_winner', __( 'No winner has been declared yet.', 'opti-behavior' ) );
		}

		$variant = Opti_Behavior_AB_Test_Database::get_variant( $test->winner_variant_id );
		if ( ! $variant ) {
			return new \WP_Error( 'variant_not_found', __( 'Winning variant not found.', 'opti-behavior' ) );
		}

		$variant_data = json_decode( $variant->variant_data, true );
		if ( ! is_array( $variant_data ) ) {
			$variant_data = array();
		}

		// Same idempotency key the commit phase will use — passes through so the
		// commit endpoint can skip recomputing and hit the UNIQUE constraint cleanly.
		$idempotency_key = sha1( 'apply_' . $test_id . '_' . $test->winner_variant_id . '_' . gmdate( 'YmdHi' ) );

		$changed_fields = array();
		$unknown_keys   = array();
		$warnings       = array();

		$opti_ab_target_post    = null;
		$opti_ab_target_title   = '';
		$opti_ab_target_edit    = '';

		if ( ! empty( $test->target_post_id ) ) {
			$opti_ab_target_post = get_post( $test->target_post_id );
			if ( $opti_ab_target_post ) {
				$opti_ab_target_title = $opti_ab_target_post->post_title;
				$opti_ab_target_edit  = get_edit_post_link( $test->target_post_id, 'raw' );
			} else {
				$warnings[] = sprintf(
					/* translators: %d: post ID */
					__( 'The target post (#%d) could not be found. It may have been trashed or deleted.', 'opti-behavior' ),
					absint( $test->target_post_id )
				);
			}
		}

		switch ( $test->test_type ) {

			case 'headline':
				if ( $opti_ab_target_post ) {
					if ( isset( $variant_data['title'] ) ) {
						$changed_fields['title'] = array(
							'from' => $opti_ab_target_post->post_title,
							'to'   => sanitize_text_field( $variant_data['title'] ),
						);
					}
					if ( isset( $variant_data['content'] ) || isset( $variant_data['full_description'] ) ) {
						$opti_ab_new_content = isset( $variant_data['full_description'] )
							? $variant_data['full_description']
							: $variant_data['content'];
						$changed_fields['content'] = array(
							'from' => $opti_ab_target_post->post_content,
							'to'   => wp_kses_post( $opti_ab_new_content ),
						);
					}
					if ( isset( $variant_data['excerpt'] ) ) {
						$changed_fields['excerpt'] = array(
							'from' => $opti_ab_target_post->post_excerpt,
							'to'   => sanitize_textarea_field( $variant_data['excerpt'] ),
						);
					}
				}
				break;

			case 'element':
				if ( $opti_ab_target_post ) {
					$opti_ab_existing = get_post_meta( $test->target_post_id, '_opti_behavior_ab_applied_changes', true );
					if ( ! is_array( $opti_ab_existing ) ) {
						$opti_ab_existing = array();
					}
					$changed_fields['element_changes'] = array(
						'from' => isset( $opti_ab_existing[ $test_id ] ) ? $opti_ab_existing[ $test_id ] : null,
						'to'   => $variant_data,
					);
				}
				break;

			case 'page_split':
				$opti_ab_control_url = isset( $test->target_url ) ? $test->target_url : '';
				$opti_ab_winner_url  = '';
				if ( ! empty( $variant_data['url'] ) ) {
					$opti_ab_winner_url = $variant_data['url'];
				} elseif ( ! empty( $variant_data['redirect_url'] ) ) {
					$opti_ab_winner_url = $variant_data['redirect_url'];
				} elseif ( ! empty( $variant_data['page_url'] ) ) {
					$opti_ab_winner_url = $variant_data['page_url'];
				}
				$changed_fields['redirect'] = array(
					'from' => $opti_ab_control_url,
					'to'   => $opti_ab_winner_url,
				);
				break;

			case 'woocommerce':
				// Delegate to the Pro mapper's read-only preview() method.
				if ( class_exists( 'Opti_Behavior_AB_WooCommerce_Field_Mapper' ) && ! empty( $test->target_post_id ) ) {
					$opti_ab_mapper = new Opti_Behavior_AB_WooCommerce_Field_Mapper();
					$opti_ab_result = $opti_ab_mapper->preview( (int) $test->target_post_id, $variant_data );
					$changed_fields = isset( $opti_ab_result['changed_fields'] ) ? $opti_ab_result['changed_fields'] : array();
					$unknown_keys   = isset( $opti_ab_result['unknown_keys'] ) ? $opti_ab_result['unknown_keys'] : array();
					if ( ! empty( $opti_ab_result['error'] ) ) {
						$warnings[] = $opti_ab_result['error'];
					}
					if ( ! empty( $opti_ab_result['warnings'] ) ) {
						$warnings = array_merge( $warnings, $opti_ab_result['warnings'] );
					}
				} else {
					// Pro plugin not active — free plugin cannot compute WooCommerce diff.
					$warnings[] = __( 'WooCommerce test — Pro plugin required to compute the full field diff. You can still apply the winner.', 'opti-behavior' );
				}
				break;

			default:
				$warnings[] = sprintf(
					/* translators: %s: test type slug */
					__( 'Diff preview is not available for test type "%s".', 'opti-behavior' ),
					esc_html( $test->test_type )
				);
				break;
		}

		return array(
			'test_type'         => $test->test_type,
			'target_post_id'    => $opti_ab_target_post ? (int) $test->target_post_id : null,
			'target_post_title' => $opti_ab_target_title,
			'target_edit_url'   => $opti_ab_target_edit ? $opti_ab_target_edit : '',
			'changed_fields'    => $changed_fields,
			'unknown_keys'      => $unknown_keys,
			'idempotency_key'   => $idempotency_key,
			'warnings'          => $warnings,
		);
	}

	/**
	 * Apply the winning variant permanently.
	 *
	 * Handles all 4 test types: headline, element, page_split, woocommerce.
	 * Writes a snapshot to the decision-log audit table before making any
	 * changes so that a future Revert operation can restore the previous values.
	 * Uses an idempotency key (minute-bucket SHA-1) to prevent double-apply
	 * race conditions.
	 *
	 * Returns a structured result array on success so the AJAX layer can
	 * surface field-level detail in the success toast.
	 *
	 * @since 1.3.2
	 * @param int    $test_id         Test ID.
	 * @param string $idempotency_key Optional pre-computed idempotency key from preflight. If empty, a new key is generated.
	 * @return array|WP_Error Result array { success, applied_at, changed_fields, audit_id } or error.
	 */
	public function apply_winner( $test_id, $idempotency_key = '' ) {
		$test = $this->get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		// Guard: already applied — surface a clear error instead of silently
		// re-applying (which could corrupt the snapshot chain).
		if ( 'applied' === $test->status ) {
			return new \WP_Error( 'already_applied', __( 'This winner has already been applied.', 'opti-behavior' ) );
		}

		if ( empty( $test->winner_variant_id ) ) {
			return new \WP_Error( 'no_winner', __( 'No winner has been declared yet.', 'opti-behavior' ) );
		}

		$variant = Opti_Behavior_AB_Test_Database::get_variant( $test->winner_variant_id );
		if ( ! $variant ) {
			return new \WP_Error( 'variant_not_found', __( 'Winning variant not found.', 'opti-behavior' ) );
		}

		$variant_data = json_decode( $variant->variant_data, true );
		if ( ! is_array( $variant_data ) ) {
			$variant_data = array();
		}

		// Build an idempotency key: minute-bucket prevents same-minute double-clicks
		// while still allowing re-apply after a future Revert.
		// If the caller (commit endpoint) passes the key that the preflight computed,
		// use it directly — both keys are identical for the same minute bucket.
		if ( empty( $idempotency_key ) ) {
			$idempotency_key = sha1( 'apply_' . $test_id . '_' . $test->winner_variant_id . '_' . gmdate( 'YmdHi' ) );
		}

		// INSERT IGNORE — returns 0 if the key already exists (duplicate in same minute).
		$log_id = Opti_Behavior_AB_Test_Database::insert_decision_log( array(
			'test_id'           => $test_id,
			'event'             => 'applied',
			'actor_user_id'     => get_current_user_id(),
			'winner_variant_id' => absint( $test->winner_variant_id ),
			'target_post_id'    => isset( $test->target_post_id ) ? absint( $test->target_post_id ) : null,
			'idempotency_key'   => $idempotency_key,
			'notes'             => sprintf( 'Apply winner: test #%d variant #%d', absint( $test_id ), absint( $test->winner_variant_id ) ),
		) );

		if ( false === $log_id ) {
			return new \WP_Error( 'db_error', __( 'Failed to write audit log entry.', 'opti-behavior' ) );
		}

		if ( 0 === $log_id ) {
			return new \WP_Error( 'already_applied', __( 'Another apply for this test is already in progress. Please refresh.', 'opti-behavior' ) );
		}

		// -----------------------------------------------------------------
		// Per-type apply with before-snapshot capture.
		// -----------------------------------------------------------------
		$snapshot       = array();
		$changed_fields = array();

		switch ( $test->test_type ) {

			case 'headline':
				if ( $test->target_post_id ) {
					$post = get_post( $test->target_post_id );
					if ( $post ) {
						// Snapshot all fields that will change.
						$opti_ab_update = array( 'ID' => (int) $test->target_post_id );

						if ( isset( $variant_data['title'] ) ) {
							$snapshot['title'] = array( 'from' => $post->post_title, 'to' => sanitize_text_field( $variant_data['title'] ) );
							$opti_ab_update['post_title'] = sanitize_text_field( $variant_data['title'] );
							$changed_fields[] = 'title';
						}

						if ( isset( $variant_data['content'] ) || isset( $variant_data['full_description'] ) ) {
							$opti_ab_new_content = isset( $variant_data['full_description'] ) ? $variant_data['full_description'] : $variant_data['content'];
							$snapshot['content'] = array( 'from' => $post->post_content, 'to' => wp_kses_post( $opti_ab_new_content ) );
							$opti_ab_update['post_content'] = wp_kses_post( $opti_ab_new_content );
							$changed_fields[] = 'content';
						}

						if ( isset( $variant_data['excerpt'] ) ) {
							$snapshot['excerpt'] = array( 'from' => $post->post_excerpt, 'to' => sanitize_textarea_field( $variant_data['excerpt'] ) );
							$opti_ab_update['post_excerpt'] = sanitize_textarea_field( $variant_data['excerpt'] );
							$changed_fields[] = 'excerpt';
						}

						if ( count( $opti_ab_update ) > 1 ) {
							wp_update_post( $opti_ab_update );
							clean_post_cache( (int) $test->target_post_id );
						}
					}
				}
				break;

			case 'page_split':
				// Register a 301 redirect from the control URL to the winner URL.
				// The redirect rule is stored as an option keyed by test_id so that
				// the public-facing renderer can read and fire it for applied tests.
				// Snapshot contains the previous option state so Revert can clear it.
				$opti_ab_winner_url  = '';
				$opti_ab_control_url = isset( $test->target_url ) ? $test->target_url : '';

				if ( ! empty( $variant_data['url'] ) ) {
					$opti_ab_winner_url = esc_url_raw( $variant_data['url'] );
				} elseif ( ! empty( $variant_data['redirect_url'] ) ) {
					$opti_ab_winner_url = esc_url_raw( $variant_data['redirect_url'] );
				} elseif ( ! empty( $variant_data['page_url'] ) ) {
					$opti_ab_winner_url = esc_url_raw( $variant_data['page_url'] );
				}

				$opti_ab_option_key      = 'opti_ab_page_split_redirect_' . absint( $test_id );
				$opti_ab_previous_option = get_option( $opti_ab_option_key, '' );

				$snapshot['redirect_option'] = array( 'from' => $opti_ab_previous_option, 'to' => $opti_ab_winner_url );

				if ( $opti_ab_winner_url ) {
					update_option( $opti_ab_option_key, array(
						'from' => $opti_ab_control_url,
						'to'   => $opti_ab_winner_url,
					), false );
					$changed_fields[] = 'redirect_url';

					// Allow integrations to observe the registered redirect rule.
					do_action( 'opti_behavior_ab_page_split_redirect_registered', $test_id, $opti_ab_control_url, $opti_ab_winner_url );
				}
				break;

			case 'element':
				if ( $test->target_post_id ) {
					// Snapshot existing applied-changes meta before overwriting.
					$opti_ab_existing = get_post_meta( $test->target_post_id, '_opti_behavior_ab_applied_changes', true );
					if ( ! is_array( $opti_ab_existing ) ) {
						$opti_ab_existing = array();
					}

					$snapshot['element_meta_previous'] = $opti_ab_existing;

					$opti_ab_new_meta = $opti_ab_existing;
					$opti_ab_new_meta[ $test_id ] = $variant_data;
					update_post_meta( $test->target_post_id, '_opti_behavior_ab_applied_changes', $opti_ab_new_meta );
					clean_post_cache( (int) $test->target_post_id );
					$changed_fields[] = 'element_changes';
				}
				break;

			case 'woocommerce':
				// Delegate to the Pro WooCommerce field mapper.
				// If Pro is not active the mapper class won't exist and we skip gracefully.
				if ( class_exists( 'Opti_Behavior_AB_WooCommerce_Field_Mapper' ) && $test->target_post_id ) {
					$opti_ab_mapper = new Opti_Behavior_AB_WooCommerce_Field_Mapper();
					$opti_ab_result = $opti_ab_mapper->apply( (int) $test->target_post_id, $variant_data );

					if ( ! empty( $opti_ab_result['error'] ) ) {
						return new \WP_Error( 'woo_apply_failed', $opti_ab_result['error'] );
					}

					$snapshot       = isset( $opti_ab_result['changed_fields'] ) ? $opti_ab_result['changed_fields'] : array();
					$changed_fields = array_keys( $snapshot );
				}
				break;
		}

		// -----------------------------------------------------------------
		// Write snapshot and changed-fields detail into the audit log row.
		// -----------------------------------------------------------------
		$opti_ab_snapshot_json       = wp_json_encode( $snapshot );
		$opti_ab_changed_fields_json = wp_json_encode( $changed_fields );
		Opti_Behavior_AB_Test_Database::update_decision_log( $log_id, $opti_ab_snapshot_json, $opti_ab_changed_fields_json );

		// -----------------------------------------------------------------
		// Transition test status to 'applied' and record who applied it.
		// -----------------------------------------------------------------
		Opti_Behavior_AB_Test_Database::update_test( $test_id, array(
			'applied_at' => current_time( 'mysql' ),
			'applied_by' => get_current_user_id(),
		) );

		$this->transition_status( $test_id, 'applied' );

		// -----------------------------------------------------------------
		// Fire observability action so third-party code can react.
		// -----------------------------------------------------------------
		do_action( 'opti_behavior_ab_winner_applied', $test_id, $test->winner_variant_id, $snapshot, $changed_fields );

		return array(
			'success'        => true,
			'applied_at'     => current_time( 'c' ),
			'changed_fields' => $changed_fields,
			'audit_id'       => $log_id,
		);
	}

	/**
	 * Revert a permanently applied winner.
	 *
	 * Restores the latest "applied" snapshot where possible, records a
	 * "reverted" audit event, and moves the test back to Completed so the
	 * winner remains visible without keeping the permanent change live.
	 *
	 * @since 1.3.4
	 * @param int $test_id Test ID.
	 * @return array|WP_Error Result array { success, reverted_at, reverted_fields, audit_id } or error.
	 */
	public function revert_applied_winner( $test_id ) {
		$test = $this->get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		if ( 'applied' !== $test->status ) {
			return new \WP_Error( 'not_applied', __( 'This winner is not currently applied.', 'opti-behavior' ) );
		}

		$applied_log = Opti_Behavior_AB_Test_Database::get_latest_decision_log( $test_id, 'applied' );
		$snapshot    = array();
		if ( $applied_log && ! empty( $applied_log->snapshot_json ) ) {
			$decoded = json_decode( $applied_log->snapshot_json, true );
			if ( is_array( $decoded ) ) {
				$snapshot = $decoded;
			}
		}

		$reverted_fields = array();

		switch ( $test->test_type ) {
			case 'page_split':
				$option_key = 'opti_ab_page_split_redirect_' . absint( $test_id );
				$previous   = isset( $snapshot['redirect_option']['from'] ) ? $snapshot['redirect_option']['from'] : '';

				if ( is_array( $previous ) && ! empty( $previous['to'] ) ) {
					update_option( $option_key, $previous, false );
				} else {
					delete_option( $option_key );
				}

				$reverted_fields[] = 'redirect_url';
				break;

			case 'element':
				if ( ! empty( $test->target_post_id ) ) {
					$previous_meta = isset( $snapshot['element_meta_previous'] ) && is_array( $snapshot['element_meta_previous'] )
						? $snapshot['element_meta_previous']
						: array();

					if ( empty( $previous_meta ) ) {
						delete_post_meta( $test->target_post_id, '_opti_behavior_ab_applied_changes' );
					} else {
						update_post_meta( $test->target_post_id, '_opti_behavior_ab_applied_changes', $previous_meta );
					}

					clean_post_cache( (int) $test->target_post_id );
					$reverted_fields[] = 'element_changes';
				}
				break;

			case 'headline':
				if ( ! empty( $test->target_post_id ) ) {
					$update = array( 'ID' => (int) $test->target_post_id );

					if ( isset( $snapshot['title']['from'] ) ) {
						$update['post_title'] = sanitize_text_field( $snapshot['title']['from'] );
						$reverted_fields[]    = 'title';
					}
					if ( isset( $snapshot['content']['from'] ) ) {
						$update['post_content'] = wp_kses_post( $snapshot['content']['from'] );
						$reverted_fields[]      = 'content';
					}
					if ( isset( $snapshot['excerpt']['from'] ) ) {
						$update['post_excerpt'] = sanitize_textarea_field( $snapshot['excerpt']['from'] );
						$reverted_fields[]      = 'excerpt';
					}

					if ( count( $update ) > 1 ) {
						wp_update_post( $update );
						clean_post_cache( (int) $test->target_post_id );
					}
				}
				break;

			default:
				return new \WP_Error(
					'unsupported_revert_type',
					sprintf(
						/* translators: %s: test type slug */
						__( 'Revert is not available for test type "%s" yet.', 'opti-behavior' ),
						esc_html( $test->test_type )
					)
				);
		}

		$idempotency_key = sha1( 'revert_' . $test_id . '_' . get_current_user_id() . '_' . gmdate( 'YmdHis' ) );
		$log_id = Opti_Behavior_AB_Test_Database::insert_decision_log( array(
			'test_id'           => $test_id,
			'event'             => 'reverted',
			'actor_user_id'     => get_current_user_id(),
			'winner_variant_id' => isset( $test->winner_variant_id ) ? absint( $test->winner_variant_id ) : null,
			'target_post_id'    => isset( $test->target_post_id ) ? absint( $test->target_post_id ) : null,
			'idempotency_key'   => $idempotency_key,
			'notes'             => sprintf( 'Revert applied winner: test #%d', absint( $test_id ) ),
		) );

		if ( false === $log_id ) {
			return new \WP_Error( 'db_error', __( 'Failed to write revert audit log entry.', 'opti-behavior' ) );
		}

		Opti_Behavior_AB_Test_Database::update_decision_log(
			$log_id,
			wp_json_encode( array( 'reverted_from' => $snapshot ) ),
			wp_json_encode( $reverted_fields )
		);

		Opti_Behavior_AB_Test_Database::update_test( $test_id, array(
			'status'     => 'completed',
			'applied_at' => null,
			'applied_by' => null,
		) );
		Opti_Behavior_AB_Test_Database::invalidate_active_tests_cache();
		$this->purge_page_cache_for_test( $test_id );

		do_action( 'opti_behavior_ab_winner_reverted', $test_id, $test->winner_variant_id, $snapshot, $reverted_fields );
		do_action( 'opti_behavior_ab_test_status_changed', $test_id, 'applied', 'completed' );

		return array(
			'success'         => true,
			'reverted_at'     => current_time( 'c' ),
			'reverted_fields' => $reverted_fields,
			'audit_id'        => $log_id,
		);
	}

	/**
	 * Get test results with statistics.
	 *
	 * @since 1.3.0
	 * @param int      $test_id Test ID.
	 * @param int|null  $goal_id Optional goal ID (null = primary).
	 * @param bool|null $exclude_spam Optional spam filter override.
	 * @return array|WP_Error Results array or error.
	 */
	public function get_results( $test_id, $goal_id = null, $exclude_spam = null ) {
		$test = $this->get_test( $test_id );
		if ( ! $test ) {
			return new \WP_Error( 'not_found', __( 'Test not found.', 'opti-behavior' ) );
		}

		$variant_stats = Opti_Behavior_AB_Test_Database::get_test_stats( $test_id, $goal_id, $exclude_spam );

		$results = Opti_Behavior_AB_Test_Engine::calculate_results(
			$variant_stats,
			floatval( $test->confidence_level )
		);

		$significance = Opti_Behavior_AB_Test_Engine::check_significance(
			$variant_stats,
			floatval( $test->confidence_level ),
			(int) $test->min_sample_size,
			(int) $test->min_duration_hours,
			$test->started_at
		);

		$progress = Opti_Behavior_AB_Test_Engine::get_significance_progress(
			$variant_stats,
			floatval( $test->confidence_level ),
			(int) $test->min_sample_size
		);

		return array(
			'test'         => $test,
			'variants'     => $results,
			'significance' => $significance,
			'progress'     => $progress,
			'daily_stats'  => Opti_Behavior_AB_Test_Database::get_daily_stats( $test_id, $goal_id, null, null, $exclude_spam ),
		);
	}

	// =========================================================================
	// Internal Helpers
	// =========================================================================

	/**
	 * Execute a status transition with side effects.
	 *
	 * @param int    $test_id    Test ID.
	 * @param object $test       Test object.
	 * @param string $old_status Current status.
	 * @param string $new_status Target status.
	 * @return bool|WP_Error
	 */
	private function execute_transition( $test_id, $test, $old_status, $new_status ) {
		$update_data = array( 'status' => $new_status );

		switch ( $new_status ) {
			case 'running':
				if ( empty( $test->started_at ) ) {
					$update_data['started_at'] = current_time( 'mysql' );
				}
				break;

			case 'completed':
				$update_data['ended_at'] = current_time( 'mysql' );
				break;
		}

		$success = Opti_Behavior_AB_Test_Database::update_test( $test_id, $update_data );

		if ( $success ) {
			Opti_Behavior_AB_Test_Database::invalidate_active_tests_cache();
			$this->purge_page_cache_for_test( $test_id );
			do_action( 'opti_behavior_ab_test_status_changed', $test_id, $old_status, $new_status );

			if ( 'running' === $new_status ) {
				do_action( 'opti_behavior_ab_test_started', $test_id );
			} elseif ( 'completed' === $new_status ) {
				do_action( 'opti_behavior_ab_test_completed', $test_id, $test->winner_variant_id );
			}
		}

		return $success;
	}

	/**
	 * Purge full-page caches for a test's target URL(s).
	 *
	 * Cached copies of the target page embed the variant assignment and the
	 * rendered variant HTML, so every test status change (start, pause,
	 * resume, complete, archive, apply/revert winner) must invalidate the
	 * page cache. Supports WP Rocket, LiteSpeed, W3TC and WP Super Cache;
	 * falls back to a domain-wide WP Rocket purge when no URL is known.
	 *
	 * @since 1.7.1
	 * @param int $test_id Test ID.
	 */
	public function purge_page_cache_for_test( $test_id ) {
		$urls = array();
		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );

		if ( $test ) {
			if ( ! empty( $test->target_url ) ) {
				$urls[] = $test->target_url;
			}
			if ( ! empty( $test->target_post_id ) ) {
				$permalink = get_permalink( (int) $test->target_post_id );
				if ( $permalink ) {
					$urls[] = $permalink;
				}
			}

			// page_split destination URLs.
			$variants = Opti_Behavior_AB_Test_Database::get_variants( (int) $test_id );
			foreach ( (array) $variants as $variant ) {
				if ( empty( $variant->variant_data ) ) {
					continue;
				}
				$vdata = json_decode( $variant->variant_data, true );
				if ( is_array( $vdata ) && ! empty( $vdata['redirect_url'] ) ) {
					$urls[] = $vdata['redirect_url'];
				}
			}
		}

		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );

		// WP Rocket.
		if ( function_exists( 'rocket_clean_files' ) && ! empty( $urls ) ) {
			rocket_clean_files( $urls );
		} elseif ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// WP Rocket: the home page needs its own dedicated purge call.
		if ( function_exists( 'rocket_clean_home' ) ) {
			$home_path = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
			foreach ( $urls as $url ) {
				$url_path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				if ( $url_path === $home_path ) {
					rocket_clean_home();
					break;
				}
			}
		}

		// LiteSpeed Cache.
		foreach ( $urls as $url ) {
			do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by LiteSpeed Cache (its documented purge API); not a hook defined by this plugin.
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_url' ) ) {
			foreach ( $urls as $url ) {
				w3tc_flush_url( $url );
			}
		}

		// WP Super Cache.
		if ( function_exists( 'wpsc_delete_url_cache' ) ) {
			foreach ( $urls as $url ) {
				wpsc_delete_url_cache( $url );
			}
		}
	}

	/**
	 * Check if Pro plugin is active.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	private function is_pro_active() {
		return function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();
	}
}
