<?php
/**
 * Smart Insights Scheduler Class
 *
 * Coordinates configurable, low-resource automatic Smart Insights generation.
 *
 * @package opti-behavior
 * @since   1.3.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Scheduler Class.
 *
 * The scheduler owns only cron orchestration and option/state persistence. Signal
 * availability, metrics, Pro gates, and storage remain inside the existing
 * generator/registry/repository contracts.
 *
 * @since 1.3.6
 */
class Opti_Behavior_Smart_Insights_Scheduler {

	const SETTINGS_OPTION = 'opti_behavior_smart_insights_scheduler';
	const STATE_OPTION    = 'opti_behavior_smart_insights_scheduler_state';
	const BATCH_HOOK      = 'opti_behavior_smart_insights_scheduler_batch';
	const LOCK_TRANSIENT  = 'opti_behavior_smart_insights_scheduler_lock';

	/**
	 * Daily outcome-verification hook ("did the fix work?").
	 *
	 * Independent of the generation settings: an insight a human resolved still
	 * deserves its before/after measurement even when automatic generation is
	 * switched off, and the job is bounded to a small batch per run.
	 *
	 * @since 1.4.0
	 */
	const OUTCOME_HOOK = 'opti_behavior_smart_insights_outcome_check';

	/**
	 * Get default scheduler settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'enabled'             => true,
			'frequency'           => 'daily',
			'period'              => class_exists( 'Opti_Behavior_Smart_Insights_Generator' ) ? Opti_Behavior_Smart_Insights_Generator::DEFAULT_PERIOD : 'last30days',
			'processing_profile'  => 'gentle',
			'candidate_limit'     => 25,
			'work_items_per_tick' => 1,
			'min_spacing_seconds' => 300,
			'auto_resolve'        => true,
			'last_saved_at'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Get normalized scheduler settings from the database.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$raw = get_option( self::SETTINGS_OPTION, null );
		if ( ! is_array( $raw ) ) {
			return self::get_default_settings();
		}

		return self::normalize_settings( $raw );
	}

	/**
	 * Normalize a settings payload.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public static function normalize_settings( $input ) {
		$defaults = self::get_default_settings();
		$input    = is_array( $input ) ? $input : array();

		$allowed_frequencies = array( 'daily', 'twicedaily', 'hourly', 'every_minute' );
		$allowed_periods     = array( 'last7days', 'last14days', 'last30days' );
		$allowed_profiles    = array( 'gentle', 'balanced', 'testing' );

		$frequency = isset( $input['frequency'] ) ? sanitize_key( $input['frequency'] ) : $defaults['frequency'];
		if ( ! in_array( $frequency, $allowed_frequencies, true ) ) {
			$frequency = $defaults['frequency'];
		}

		$period = isset( $input['period'] ) ? sanitize_key( $input['period'] ) : $defaults['period'];
		if ( ! in_array( $period, $allowed_periods, true ) ) {
			$period = 'last7days';
		}

		$profile = isset( $input['processing_profile'] ) ? sanitize_key( $input['processing_profile'] ) : $defaults['processing_profile'];
		if ( ! in_array( $profile, $allowed_profiles, true ) ) {
			$profile = $defaults['processing_profile'];
		}

		$profile_defaults = self::get_profile_defaults( $profile );

		return array(
			'enabled'             => array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : $defaults['enabled'],
			'frequency'           => $frequency,
			'period'              => $period,
			'processing_profile'  => $profile,
			'candidate_limit'     => self::clamp_int( isset( $input['candidate_limit'] ) ? $input['candidate_limit'] : $profile_defaults['candidate_limit'], 10, 100 ),
			'work_items_per_tick' => self::clamp_int( isset( $input['work_items_per_tick'] ) ? $input['work_items_per_tick'] : $profile_defaults['work_items_per_tick'], 1, 3 ),
			'min_spacing_seconds' => self::clamp_int( isset( $input['min_spacing_seconds'] ) ? $input['min_spacing_seconds'] : $profile_defaults['min_spacing_seconds'], 60, 900 ),
			'auto_resolve'        => array_key_exists( 'auto_resolve', $input ) ? (bool) $input['auto_resolve'] : $defaults['auto_resolve'],
			'last_saved_at'       => ! empty( $input['last_saved_at'] ) ? sanitize_text_field( (string) $input['last_saved_at'] ) : current_time( 'mysql' ),
		);
	}

	/**
	 * Persist settings and reschedule cron.
	 *
	 * @param array $input Raw settings.
	 * @return array Saved settings.
	 */
	public static function save_settings( $input ) {
		$settings                  = self::normalize_settings( $input );
		$settings['last_saved_at'] = current_time( 'mysql' );

		update_option( self::SETTINGS_OPTION, $settings, false );
		self::reschedule( $settings );

		return $settings;
	}

	/**
	 * Register custom cron recurrences.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['every_minute'] ) ) {
			$schedules['every_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every Minute (Smart Insights testing only)', 'opti-behavior' ),
			);
		}

		return $schedules;
	}

	/**
	 * Get the WordPress recurrence slug for a scheduler frequency.
	 *
	 * @param string $frequency Frequency slug.
	 * @return string
	 */
	public static function get_recurrence_for_frequency( $frequency ) {
		$frequency = sanitize_key( $frequency );
		if ( in_array( $frequency, array( 'daily', 'twicedaily', 'hourly', 'every_minute' ), true ) ) {
			return $frequency;
		}

		return 'daily';
	}

	/**
	 * Reschedule the kickoff event based on current settings.
	 *
	 * @param array|null $settings Optional normalized settings.
	 * @return bool
	 */
	public static function reschedule( $settings = null ) {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );

		$settings = is_array( $settings ) ? self::normalize_settings( $settings ) : self::get_settings();

		self::ensure_outcome_check_scheduled();

		if ( empty( $settings['enabled'] ) ) {
			wp_clear_scheduled_hook( Opti_Behavior_Smart_Insights_Generator::CRON_HOOK );
			wp_clear_scheduled_hook( self::BATCH_HOOK );
			self::update_state(
				array(
					'status'       => 'disabled',
					'last_error'   => '',
					'queued_items' => array(),
				)
			);
			return true;
		}

		$recurrence      = self::get_recurrence_for_frequency( $settings['frequency'] );
		$next_scheduled  = wp_next_scheduled( Opti_Behavior_Smart_Insights_Generator::CRON_HOOK );
		$current_schedule = function_exists( 'wp_get_schedule' ) ? wp_get_schedule( Opti_Behavior_Smart_Insights_Generator::CRON_HOOK ) : '';

		if ( $next_scheduled && $current_schedule === $recurrence ) {
			return true;
		}

		wp_clear_scheduled_hook( Opti_Behavior_Smart_Insights_Generator::CRON_HOOK );

		return (bool) wp_schedule_event( self::get_first_run_timestamp( $settings ), $recurrence, Opti_Behavior_Smart_Insights_Generator::CRON_HOOK );
	}

	/**
	 * Make sure the daily outcome-verification event exists.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	public static function ensure_outcome_check_scheduled() {
		if ( wp_next_scheduled( self::OUTCOME_HOOK ) ) {
			return true;
		}

		// Runs well after generation (03:45) so a same-day refresh has finished.
		return (bool) wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::OUTCOME_HOOK );
	}

	/**
	 * Run one bounded outcome-verification batch.
	 *
	 * Every failure mode degrades to a summary with `skipped`, never to a fatal
	 * on a cron request.
	 *
	 * @since 1.4.0
	 *
	 * @param object|null $generator Optional generator override.
	 * @return array Run summary.
	 */
	public static function run_outcome_check( $generator = null ) {
		$summary = array(
			'checked'      => 0,
			'improved'     => 0,
			'worse'        => 0,
			'no_change'    => 0,
			'inconclusive' => 0,
			'skipped'      => 0,
		);

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Outcome_Evaluator' ) ) {
			$summary['skipped'] = 1;

			return $summary;
		}

		try {
			$evaluator = new Opti_Behavior_Smart_Insights_Outcome_Evaluator( null, self::get_generator( $generator ) );

			/**
			 * Filter the per-run outcome-check batch size.
			 *
			 * @since 1.4.0
			 *
			 * @param int $limit Rows measured per run.
			 */
			$limit = (int) apply_filters( 'opti_behavior_smart_insights_outcome_batch_limit', Opti_Behavior_Smart_Insights_Outcome_Evaluator::BATCH_LIMIT );

			return $evaluator->run_check( array( 'limit' => $limit ) );
		} catch ( Exception $e ) {
			$summary['skipped'] = 1;

			return $summary;
		} catch ( Error $e ) {
			$summary['skipped'] = 1;

			return $summary;
		}
	}

	/**
	 * Run the scheduler kickoff: build a queue and schedule the first batch tick.
	 *
	 * @param object|null $generator Optional generator.
	 * @return array|WP_Error
	 */
	public static function kickoff( $generator = null ) {
		if ( ! self::acquire_lock() ) {
			return new WP_Error( 'opti_behavior_smart_insights_scheduler_locked', __( 'Smart Insights scheduler is already updating its queue.', 'opti-behavior' ) );
		}

		try {
			$settings = self::get_settings();
			if ( empty( $settings['enabled'] ) ) {
				self::update_state( array( 'status' => 'disabled', 'queued_items' => array() ) );
				wp_clear_scheduled_hook( self::BATCH_HOOK );
				return array( 'skipped' => true, 'reason' => 'disabled' );
			}

			$generator = self::get_generator( $generator );
			if ( ! $generator || ! method_exists( $generator, 'get_date_range_for_period' ) ) {
				return new WP_Error( 'opti_behavior_smart_insights_scheduler_no_generator', __( 'Smart Insights generator is unavailable.', 'opti-behavior' ) );
			}

			$date_range = $generator->get_date_range_for_period( $settings['period'] );
			if ( is_wp_error( $date_range ) ) {
				return $date_range;
			}

			$cycle_id   = self::build_cycle_id( $settings, $date_range );
			$work_items = self::build_work_items( $generator, $settings, $date_range );

			$state = array(
				'cycle_id'         => $cycle_id,
				'status'           => empty( $work_items ) ? 'completed' : 'queued',
				'period'           => $settings['period'],
				'date_range'       => $date_range,
				'total_work_items' => count( $work_items ),
				'processed_items'  => 0,
				'failed_items'     => 0,
				'queued_items'     => $work_items,
				'started_at'       => current_time( 'mysql' ),
				'last_batch_at'    => '',
				'completed_at'     => empty( $work_items ) ? current_time( 'mysql' ) : '',
				'last_error'       => '',
				'last_result'      => array(),
			);
			self::update_state( $state );

			wp_clear_scheduled_hook( self::BATCH_HOOK );
			if ( ! empty( $work_items ) ) {
				self::schedule_next_batch( 0 );
			}

			return array(
				'success'          => true,
				'cycle_id'         => $cycle_id,
				'total_work_items' => count( $work_items ),
				'next_batch'       => wp_next_scheduled( self::BATCH_HOOK ),
			);
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Build deterministic work items from available registered signals.
	 *
	 * @param object $generator  Smart Insights generator.
	 * @param array  $settings   Scheduler settings.
	 * @param array  $date_range Date range.
	 * @return array
	 */
	public static function build_work_items( $generator, $settings, $date_range ) {
		if ( ! is_object( $generator ) || ! method_exists( $generator, 'get_signal_registry' ) ) {
			return array();
		}

		$registry = $generator->get_signal_registry();
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'for_entity_type' ) ) {
			return array();
		}

		$args = array(
			'source'              => 'cron_batch',
			'candidate_limit'     => $settings['candidate_limit'],
			'auto_resolve'        => $settings['auto_resolve'],
			'processing_profile'  => $settings['processing_profile'],
			'work_items_per_tick' => $settings['work_items_per_tick'],
		);

		$entity_types = array_merge(
			array( 'page', 'device' ),
			(array) apply_filters(
				'opti_behavior_smart_insights_additional_entity_types',
				array( 'source', 'campaign', 'funnel', 'cta', 'form', 'error' ),
				$date_range,
				$args
			)
		);
		$entity_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $entity_types ) ) ) );
		$group_size   = 'balanced' === $settings['processing_profile'] ? 2 : 1;
		$work_items   = array();

		foreach ( $entity_types as $entity_type ) {
			$signals = $registry->for_entity_type(
				$entity_type,
				array(
					'date_range' => $date_range,
					'args'       => $args,
				)
			);

			if ( 'page' === $entity_type ) {
				$signals = apply_filters( 'opti_behavior_smart_insights_signals', $signals, $date_range, $args );
			} elseif ( 'device' === $entity_type ) {
				$signals = apply_filters( 'opti_behavior_smart_insights_device_signals', $signals, $date_range, $args );
			}

			$signal_ids = self::collect_signal_ids( $signals );
			if ( empty( $signal_ids ) ) {
				continue;
			}

			foreach ( array_chunk( $signal_ids, $group_size ) as $signal_group ) {
				$work_items[] = array(
					'entity_type' => $entity_type,
					'signal_ids'  => array_values( $signal_group ),
				);
			}
		}

		return $work_items;
	}

	/**
	 * Process one scheduled batch tick.
	 *
	 * @param object|null $generator Optional generator.
	 * @return array|WP_Error
	 */
	public static function process_batch( $generator = null ) {
		if ( ! self::acquire_lock() ) {
			return new WP_Error( 'opti_behavior_smart_insights_scheduler_locked', __( 'Smart Insights scheduler is already running a batch.', 'opti-behavior' ) );
		}

		try {
			$settings = self::get_settings();
			$state    = self::get_state();

			if ( empty( $settings['enabled'] ) ) {
				wp_clear_scheduled_hook( self::BATCH_HOOK );
				$state['status']       = 'disabled';
				$state['queued_items'] = array();
				self::update_state( $state );
				return array( 'skipped' => true, 'reason' => 'disabled' );
			}

			if ( empty( $state['queued_items'] ) || ! is_array( $state['queued_items'] ) ) {
				$state['status']       = 'completed';
				$state['completed_at'] = current_time( 'mysql' );
				self::update_state( $state );
				return array( 'success' => true, 'processed' => 0, 'remaining' => 0 );
			}

			$generator = self::get_generator( $generator );
			if ( ! $generator || ! method_exists( $generator, 'generate_for_period' ) ) {
				return new WP_Error( 'opti_behavior_smart_insights_scheduler_no_generator', __( 'Smart Insights generator is unavailable.', 'opti-behavior' ) );
			}

			$date_range = isset( $state['date_range'] ) && is_array( $state['date_range'] ) ? $state['date_range'] : array();
			if ( empty( $date_range['from'] ) || empty( $date_range['to'] ) ) {
				$date_range = $generator->get_date_range_for_period( $settings['period'] );
				if ( is_wp_error( $date_range ) ) {
					return $date_range;
				}
				$state['date_range'] = $date_range;
			}

			$limit      = max( 1, (int) $settings['work_items_per_tick'] );
			$processed  = 0;
			$last_error = '';
			$last_result = array();

			for ( $i = 0; $i < $limit; $i++ ) {
				if ( empty( $state['queued_items'] ) ) {
					break;
				}

				$work_item = array_shift( $state['queued_items'] );
				$entity_type = isset( $work_item['entity_type'] ) ? sanitize_key( $work_item['entity_type'] ) : '';
				$signal_ids  = isset( $work_item['signal_ids'] ) ? array_values( array_filter( array_map( 'sanitize_key', (array) $work_item['signal_ids'] ) ) ) : array();

				$result = $generator->generate_for_period(
					$date_range['from'],
					$date_range['to'],
					array(
						'force'              => true,
						'source'             => 'cron_batch',
						'auto_resolve'       => $settings['auto_resolve'],
						'candidate_limit'    => $settings['candidate_limit'],
						'entity_types'       => array( $entity_type ),
						'signal_ids'         => $signal_ids,
						'scheduler_cycle_id' => isset( $state['cycle_id'] ) ? $state['cycle_id'] : '',
						'exclude_spam'       => class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::is_enabled() : true,
					)
				);

				if ( is_wp_error( $result ) ) {
					if ( 'opti_behavior_smart_insights_generation_locked' === $result->get_error_code() ) {
						array_unshift( $state['queued_items'], $work_item );
						$last_error = $result->get_error_message();
						break;
					}

					$state['failed_items'] = isset( $state['failed_items'] ) ? (int) $state['failed_items'] + 1 : 1;
					$last_error = $result->get_error_message();
					continue;
				}

				$processed++;
				$last_result = is_array( $result ) ? $result : array();
			}

			$state['processed_items'] = isset( $state['processed_items'] ) ? (int) $state['processed_items'] + $processed : $processed;
			$state['last_batch_at']   = current_time( 'mysql' );
			$state['last_error']      = $last_error;
			$state['last_result']     = $last_result;
			$state['status']          = empty( $state['queued_items'] ) ? 'completed' : 'running';
			if ( empty( $state['queued_items'] ) ) {
				$state['completed_at'] = current_time( 'mysql' );
			} else {
				self::schedule_next_batch( (int) $settings['min_spacing_seconds'] );
			}

			self::update_state( $state );

			return array(
				'success'   => true,
				'processed' => $processed,
				'remaining' => count( (array) $state['queued_items'] ),
				'failed'    => isset( $state['failed_items'] ) ? (int) $state['failed_items'] : 0,
			);
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Get scheduler runtime state.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return wp_parse_args(
			$state,
			array(
				'cycle_id'         => '',
				'status'           => '',
				'period'           => '',
				'date_range'       => array(),
				'total_work_items' => 0,
				'processed_items'  => 0,
				'failed_items'     => 0,
				'queued_items'     => array(),
				'started_at'       => '',
				'last_batch_at'    => '',
				'completed_at'     => '',
				'last_error'       => '',
				'last_result'      => array(),
			)
		);
	}

	/**
	 * Get the next kickoff timestamp.
	 *
	 * @return int|false
	 */
	public static function get_next_kickoff_timestamp() {
		return wp_next_scheduled( Opti_Behavior_Smart_Insights_Generator::CRON_HOOK );
	}

	/**
	 * Update scheduler state.
	 *
	 * @param array $state State fields.
	 * @return void
	 */
	private static function update_state( $state ) {
		$current = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( self::STATE_OPTION, wp_parse_args( $state, $current ), false );
	}

	/**
	 * Get profile defaults.
	 *
	 * @param string $profile Profile slug.
	 * @return array
	 */
	private static function get_profile_defaults( $profile ) {
		switch ( sanitize_key( $profile ) ) {
			case 'balanced':
				return array(
					'candidate_limit'     => 50,
					'work_items_per_tick' => 2,
					'min_spacing_seconds' => 120,
				);
			case 'testing':
				return array(
					'candidate_limit'     => 10,
					'work_items_per_tick' => 1,
					'min_spacing_seconds' => 60,
				);
			case 'gentle':
			default:
				return array(
					'candidate_limit'     => 25,
					'work_items_per_tick' => 1,
					'min_spacing_seconds' => 300,
				);
		}
	}

	/**
	 * Clamp an integer to a safe range.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max ) {
		$value = absint( $value );
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Calculate the first kickoff timestamp.
	 *
	 * @param array $settings Scheduler settings.
	 * @return int
	 */
	private static function get_first_run_timestamp( $settings ) {
		$offset = self::get_site_offset_seconds();
		$now    = time();

		if ( 'daily' === $settings['frequency'] ) {
			$tz     = wp_timezone();
			$run_at = new DateTime( 'T0345', $tz );
			if ( $run_at->getTimestamp() <= $now ) {
				$run_at->add( new DateInterval( 'P1D' ) );
			}
			return $run_at->getTimestamp() + $offset;
		}

		if ( 'every_minute' === $settings['frequency'] ) {
			return $now + MINUTE_IN_SECONDS;
		}

		return $now + max( MINUTE_IN_SECONDS, $offset );
	}

	/**
	 * Get deterministic site offset for kickoff staggering.
	 *
	 * @return int
	 */
	private static function get_site_offset_seconds() {
		$seed = function_exists( 'home_url' ) ? home_url() : ABSPATH;
		return abs( crc32( (string) $seed ) ) % ( 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Build a cycle ID.
	 *
	 * @param array $settings   Scheduler settings.
	 * @param array $date_range Date range.
	 * @return string
	 */
	private static function build_cycle_id( $settings, $date_range ) {
		$stamp = wp_date( 'Ymd-His', current_time( 'timestamp' ) );
		$from  = isset( $date_range['from'] ) ? $date_range['from'] : '';
		$to    = isset( $date_range['to'] ) ? $date_range['to'] : '';

		return sanitize_key( $stamp . '-' . $settings['period'] . '-' . substr( md5( $from . '|' . $to ), 0, 8 ) );
	}

	/**
	 * Schedule the next single batch event.
	 *
	 * @param int $delay Delay in seconds.
	 * @return void
	 */
	private static function schedule_next_batch( $delay ) {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		wp_schedule_single_event( time() + max( 0, (int) $delay ), self::BATCH_HOOK );
	}

	/**
	 * Get a generator instance.
	 *
	 * @param object|null $generator Optional generator.
	 * @return object|null
	 */
	private static function get_generator( $generator = null ) {
		if ( is_object( $generator ) ) {
			return $generator;
		}

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Generator' ) ) {
			return null;
		}

		return new Opti_Behavior_Smart_Insights_Generator();
	}

	/**
	 * Collect signal IDs from signal instances.
	 *
	 * @param array $signals Signal instances.
	 * @return array
	 */
	private static function collect_signal_ids( $signals ) {
		$ids = array();
		foreach ( (array) $signals as $signal ) {
			if ( ! is_object( $signal ) ) {
				continue;
			}

			if ( method_exists( $signal, 'get_definition' ) ) {
				$definition = $signal->get_definition();
				if ( is_array( $definition ) && ! empty( $definition['signal_id'] ) ) {
					$ids[] = sanitize_key( $definition['signal_id'] );
					continue;
				}
			}

			if ( defined( get_class( $signal ) . '::SIGNAL_ID' ) ) {
				$ids[] = sanitize_key( constant( get_class( $signal ) . '::SIGNAL_ID' ) );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Acquire scheduler lock.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, time(), 5 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Release scheduler lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}
}
