<?php
/**
 * Smart Insights Generator Class
 *
 * Orchestrates deterministic Smart Insights generation for admin-safe paths.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Generator Class.
 *
 * Runs local, aggregated analytics rules and persists deduplicated insights.
 * This class intentionally exposes methods only; it does not hook itself into
 * frontend requests. Core/admin/AJAX code decides when generation is allowed.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Generator {

	const DEFAULT_PERIOD = 'last30days';
	const CRON_HOOK      = 'opti_behavior_smart_insights_generate_daily';

	/**
	 * Insight repository.
	 *
	 * @var Opti_Behavior_Smart_Insights_Repository
	 */
	private $repository;

	/**
	 * Page metric aggregator.
	 *
	 * @var Opti_Behavior_Smart_Insights_Metric_Aggregator
	 */
	private $metric_aggregator;

	/**
	 * Site baseline calculator.
	 *
	 * @var Opti_Behavior_Smart_Insights_Baseline_Calculator
	 */
	private $baseline_calculator;

	/**
	 * Signal registry.
	 *
	 * @var Opti_Behavior_Smart_Insights_Signal_Registry
	 */
	private $signal_registry;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Smart_Insights_Repository|null            $repository          Repository override.
	 * @param Opti_Behavior_Smart_Insights_Metric_Aggregator|null     $metric_aggregator   Metric aggregator override.
	 * @param Opti_Behavior_Smart_Insights_Baseline_Calculator|null   $baseline_calculator Baseline calculator override.
	 * @param array|Opti_Behavior_Smart_Insights_Signal_Registry|null $signals             Signal overrides or registry.
	 */
	public function __construct( $repository = null, $metric_aggregator = null, $baseline_calculator = null, $signals = null ) {
		$this->repository          = $repository instanceof Opti_Behavior_Smart_Insights_Repository ? $repository : new Opti_Behavior_Smart_Insights_Repository();
		$this->metric_aggregator   = $metric_aggregator instanceof Opti_Behavior_Smart_Insights_Metric_Aggregator ? $metric_aggregator : new Opti_Behavior_Smart_Insights_Metric_Aggregator();
		$this->baseline_calculator = $baseline_calculator instanceof Opti_Behavior_Smart_Insights_Baseline_Calculator ? $baseline_calculator : new Opti_Behavior_Smart_Insights_Baseline_Calculator();
		if ( $signals instanceof Opti_Behavior_Smart_Insights_Signal_Registry ) {
			$this->signal_registry = $signals;
		} elseif ( is_array( $signals ) ) {
			$this->signal_registry = new Opti_Behavior_Smart_Insights_Signal_Registry( $signals );
		} else {
			$this->signal_registry = Opti_Behavior_Smart_Insights_Signal_Registry::with_default_signals();
		}
	}

	/**
	 * Get the repository instance.
	 *
	 * @return Opti_Behavior_Smart_Insights_Repository
	 */
	public function get_repository() {
		return $this->repository;
	}

	/**
	 * Get the signal registry.
	 *
	 * @return Opti_Behavior_Smart_Insights_Signal_Registry
	 */
	public function get_signal_registry() {
		return $this->signal_registry;
	}

	/**
	 * Generate insights for a date range.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $args       Optional generation args.
	 * @return array|WP_Error Generation summary.
	 */
	public function generate_for_period( $start_date, $end_date, $args = array() ) {
		if ( ! $this->repository->table_exists( true ) ) {
			return new WP_Error( 'opti_behavior_smart_insights_table_missing', __( 'Smart Insights storage is not ready yet. Please reload the admin page to complete the database upgrade.', 'opti-behavior' ) );
		}

		$defaults = array(
			'candidate_limit'    => 50,
			'exclude_spam'       => null,
			'force'              => false,
			'auto_resolve'       => true,
			'source'             => 'manual',
			'entity_types'       => array(),
			'signal_ids'         => array(),
			'scheduler_cycle_id' => '',
			'allow_low_volume_entities' => false,
		);
		$args     = wp_parse_args( $args, $defaults );
		$args['entity_types'] = $this->normalize_generation_filter_list( $args['entity_types'] );
		$args['signal_ids']   = $this->normalize_generation_filter_list( $args['signal_ids'] );

		$date_range = $this->normalize_date_range( $start_date, $end_date );
		if ( is_wp_error( $date_range ) ) {
			return $date_range;
		}

		$lock_key = $this->get_lock_key( $date_range['from'], $date_range['to'] );
		if ( get_transient( $lock_key ) ) {
			return new WP_Error( 'opti_behavior_smart_insights_generation_locked', __( 'Smart Insights generation is already running for this period.', 'opti-behavior' ) );
		}

		set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );

		$stored_ids       = array();
		$error_messages   = array();
		$auto_resolved    = 0;
		$candidate_insights = array();
		$active_group_keys  = array();
		$evaluated_signal_ids = array();
		$evaluated_entity_types = array();
		$evaluated_signal_ids_by_entity = array();
		// Bug 5 hardening: the additional-entity loop used to `continue` silently on
		// every skip, so "a full run stored no funnel/form/error insights" was
		// indistinguishable from "no signals registered", "no metrics returned" and
		// "entity filtered out" without adding temporary logging. Each skip now
		// records an explicit machine-readable reason and the counts behind it,
		// returned in the generation result so any recurrence is self-diagnosing.
		$entity_diagnostics = array();

		try {
			$baseline_args = array();
			if ( null !== $args['exclude_spam'] ) {
				$baseline_args['exclude_spam'] = (bool) $args['exclude_spam'];
			}

			$baselines              = $this->baseline_calculator->calculate( $date_range['from'], $date_range['to'], $baseline_args );
			$safe_min_sessions      = $this->get_safe_min_sessions_threshold( $baselines, $args );
			$aggregation_args       = array(
				'limit'        => max( 1, min( 500, absint( $args['candidate_limit'] ) ) ),
				'min_sessions' => $safe_min_sessions,
				'include_previous' => true,
				'include_event_counts' => true,
			);
			$signal_evaluation_args = $args;
			$signal_evaluation_args['min_sessions'] = $safe_min_sessions;

			if ( null !== $args['exclude_spam'] ) {
				$aggregation_args['exclude_spam'] = (bool) $args['exclude_spam'];
			}

			$page_metrics = array();
			$device_metrics = array();

			if ( $this->should_process_entity_type( 'page', $args['entity_types'] ) ) {
				$signals = apply_filters(
					'opti_behavior_smart_insights_signals',
					$this->signal_registry->for_entity_type(
						'page',
						array(
							'date_range' => $date_range,
							'args'       => $args,
						)
					),
					$date_range,
					$args
				);
				$signals = $this->filter_signals_by_ids( $signals, $args['signal_ids'] );

				if ( ! empty( $signals ) ) {
					$page_signal_ids = $this->collect_signal_ids( $signals, 'page', $date_range, $args );
					$evaluated_entity_types[] = 'page';
					$evaluated_signal_ids     = array_merge( $evaluated_signal_ids, $page_signal_ids );
					$evaluated_signal_ids_by_entity['page'] = $page_signal_ids;
					$page_metrics = $this->metric_aggregator->aggregate_page_metrics( $date_range['from'], $date_range['to'], $aggregation_args );

					foreach ( $page_metrics as $metrics ) {
						foreach ( $signals as $signal ) {
							if ( ! is_object( $signal ) || ! method_exists( $signal, 'evaluate_page' ) ) {
								continue;
							}

							$insight = $signal->evaluate_page( $metrics, $baselines, $date_range, $signal_evaluation_args );
							if ( ! is_array( $insight ) ) {
								continue;
							}

							$candidate_insights[] = array(
								'insight'   => $insight,
								'metrics'   => $metrics,
								'baselines' => $baselines,
							);
						}
					}
				}
			}

			if ( $this->should_process_entity_type( 'device', $args['entity_types'] ) ) {
				$device_signals = apply_filters(
					'opti_behavior_smart_insights_device_signals',
					$this->signal_registry->for_entity_type(
						'device',
						array(
							'date_range' => $date_range,
							'args'       => $args,
						)
					),
					$date_range,
					$args
				);
				$device_signals = $this->filter_signals_by_ids( $device_signals, $args['signal_ids'] );

				$device_baselines = $baselines;
				if ( ! empty( $device_signals ) ) {
					$device_signal_ids = $this->collect_signal_ids( $device_signals, 'device', $date_range, $args );
					$evaluated_entity_types[] = 'device';
					$evaluated_signal_ids     = array_merge( $evaluated_signal_ids, $device_signal_ids );
					$evaluated_signal_ids_by_entity['device'] = $device_signal_ids;
					$device_metrics = $this->get_device_metrics_for_period( $date_range, $args, $safe_min_sessions );
					$device_context_args = $signal_evaluation_args;
					$device_context_args['all_device_metrics'] = $device_metrics;
					foreach ( $device_metrics as $metrics ) {
						foreach ( $device_signals as $signal ) {
							if ( ! is_object( $signal ) || ! method_exists( $signal, 'evaluate_device' ) ) {
								continue;
							}

							$insight = $signal->evaluate_device( $metrics, $device_baselines, $date_range, $device_context_args );
							if ( ! is_array( $insight ) ) {
								continue;
							}

							$candidate_insights[] = array(
								'insight'   => $insight,
								'metrics'   => $metrics,
								'baselines' => $device_baselines,
							);
						}
					}
				}
			}

			$additional_entity_types = apply_filters(
				'opti_behavior_smart_insights_additional_entity_types',
				array( 'source', 'campaign', 'funnel', 'cta', 'form', 'error' ),
				$date_range,
				$args
			);

			foreach ( (array) $additional_entity_types as $entity_type ) {
				$entity_type = sanitize_key( $entity_type );
				if ( '' === $entity_type ) {
					continue;
				}
				if ( in_array( $entity_type, array( 'page', 'device' ), true ) ) {
					$entity_diagnostics[ $entity_type ] = array( 'reason' => 'handled_by_dedicated_block' );
					continue;
				}
				if ( ! $this->should_process_entity_type( $entity_type, $args['entity_types'] ) ) {
					$entity_diagnostics[ $entity_type ] = array( 'reason' => 'excluded_by_entity_types_arg' );
					continue;
				}

				$entity_signals = $this->signal_registry->for_entity_type(
					$entity_type,
					array(
						'date_range' => $date_range,
						'args'       => $args,
					)
				);
				$entity_signals = $this->filter_signals_by_ids( $entity_signals, $args['signal_ids'] );
				if ( empty( $entity_signals ) ) {
					$entity_diagnostics[ $entity_type ] = array( 'reason' => 'no_registered_signals' );
					continue;
				}
				$entity_signal_ids = $this->collect_signal_ids( $entity_signals, $entity_type, $date_range, $args );
				$evaluated_entity_types[] = $entity_type;
				$evaluated_signal_ids     = array_merge( $evaluated_signal_ids, $entity_signal_ids );
				$evaluated_signal_ids_by_entity[ $entity_type ] = $entity_signal_ids;

				$entity_metrics = $this->get_metrics_for_entity_type( $entity_type, $date_range, $args, $safe_min_sessions, $page_metrics );
				if ( empty( $entity_metrics ) ) {
					$entity_diagnostics[ $entity_type ] = array(
						'reason'  => 'no_metrics_returned',
						'signals' => count( $entity_signals ),
					);
					continue;
				}

				$entity_diagnostics[ $entity_type ] = array(
					'reason'     => 'evaluated',
					'signals'    => count( $entity_signals ),
					'metrics'    => count( $entity_metrics ),
					'candidates' => 0,
				);

				$entity_baselines = method_exists( $this->baseline_calculator, 'get_baselines' ) ? $this->baseline_calculator->get_baselines( $entity_type, $date_range, $baseline_args ) : $baselines;
				$entity_baselines = wp_parse_args( is_array( $entity_baselines ) ? $entity_baselines : array(), $baselines );
				$entity_baselines = apply_filters( 'opti_behavior_smart_insights_baselines_for_entity_type', $entity_baselines, $entity_type, $date_range, $args );
				$entity_context_args = $signal_evaluation_args;
				$entity_context_args['all_' . $entity_type . '_metrics'] = $entity_metrics;

				foreach ( $entity_metrics as $metrics ) {
					foreach ( $entity_signals as $signal ) {
						$method = 'evaluate_' . $entity_type;
						if ( ! is_object( $signal ) || ! method_exists( $signal, $method ) ) {
							continue;
						}

						$insight = $signal->{$method}( $metrics, $entity_baselines, $date_range, $entity_context_args );
						if ( ! is_array( $insight ) ) {
							continue;
						}

						$candidate_insights[] = array(
							'insight'   => $insight,
							'metrics'   => $metrics,
							'baselines' => $entity_baselines,
						);
						++$entity_diagnostics[ $entity_type ]['candidates'];
					}
				}
			}

			$candidate_insights = $this->apply_noise_suppression( $candidate_insights );
			$candidate_insights = $this->apply_correlation( $candidate_insights, $date_range, $signal_evaluation_args );
			$candidate_insights = $this->apply_impact_scoring( $candidate_insights, $date_range, $signal_evaluation_args );
			$candidate_insights = $this->apply_priority_ranking( $candidate_insights );
			$candidate_insights = $this->apply_noise_suppression_marking( $candidate_insights );
			$candidate_insights = $this->apply_evidence_refs( $candidate_insights, $date_range, $signal_evaluation_args );

			$story_parent_ids = array();

			foreach ( $candidate_insights as $candidate ) {
				$insight = $candidate['insight'];
				$metrics = isset( $candidate['metrics'] ) ? $candidate['metrics'] : array();
				$current_baselines = isset( $candidate['baselines'] ) ? $candidate['baselines'] : $baselines;
				$story_role      = isset( $candidate['story_role'] ) ? sanitize_key( $candidate['story_role'] ) : '';
				$story_scope_key = isset( $candidate['story_scope_key'] ) ? (string) $candidate['story_scope_key'] : '';

				if ( 'child' === $story_role && '' !== $story_scope_key && ! empty( $story_parent_ids[ $story_scope_key ] ) ) {
					$insight['parent_insight_id'] = (int) $story_parent_ids[ $story_scope_key ];
				}

				$insight = $this->apply_spam_scope_to_insight( $insight, $args['exclude_spam'] );
				$insight = $this->apply_historical_persistence_scoring( $insight, $date_range );

				if ( isset( $insight['related_reports'] ) ) {
					$insight['related_reports'] = apply_filters( 'opti_behavior_smart_insights_related_reports', $insight['related_reports'], $insight );
				}
				$insight = $this->enrich_insight_entity_context( $insight, $metrics );

				$insight = apply_filters( 'opti_behavior_smart_insights_before_save', $insight, $metrics, $current_baselines, $date_range, $signal_evaluation_args );
				if ( ! is_array( $insight ) ) {
					continue;
				}

				$stored_id = $this->repository->upsert_insight( $insight );
				if ( is_wp_error( $stored_id ) ) {
					$error_messages[] = $stored_id->get_error_message();
					continue;
				}

				$stored_ids[] = (int) $stored_id;
				$active_group_keys[] = $this->build_insight_group_key( $insight );

				// Bug 5 hardening: per-entity stored counts, so a run that evaluates
				// candidates but persists none is distinguishable from one that never
				// produced candidates at all.
				$stored_entity_type = isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '';
				if ( '' !== $stored_entity_type && isset( $entity_diagnostics[ $stored_entity_type ] ) ) {
					$entity_diagnostics[ $stored_entity_type ]['stored'] = isset( $entity_diagnostics[ $stored_entity_type ]['stored'] )
						? $entity_diagnostics[ $stored_entity_type ]['stored'] + 1
						: 1;
				}

				if ( 'primary' === $story_role && '' !== $story_scope_key ) {
					$story_parent_ids[ $story_scope_key ] = (int) $stored_id;
				}

				do_action( 'opti_behavior_smart_insight_saved', $insight, (int) $stored_id );
			}

			$auto_resolve_spam_scope = null === $args['exclude_spam'] ? null : $this->normalize_spam_scope_key( $args['exclude_spam'] );

			if ( ! empty( $args['auto_resolve'] ) && ( ! empty( $page_metrics ) || ! empty( $baselines['site_sessions'] ) ) ) {
				if ( method_exists( $this->repository, 'auto_resolve_unseen_insights' ) ) {
					$resolved = $this->repository->auto_resolve_unseen_insights(
						$date_range['from'],
						$date_range['to'],
						array_values( array_unique( $active_group_keys ) ),
						array(
							'signal_ids'   => array_values( array_unique( array_filter( $evaluated_signal_ids ) ) ),
							'entity_types' => array_values( array_unique( array_filter( $evaluated_entity_types ) ) ),
							'spam_scope'   => $auto_resolve_spam_scope,
						)
					);
					if ( ! is_wp_error( $resolved ) ) {
						$auto_resolved += (int) $resolved;
					}
				}

				$cutoff = $this->get_auto_resolve_cutoff( $date_range['from'], $date_range['to'] );
				foreach ( $evaluated_signal_ids_by_entity as $entity_type => $signal_ids ) {
					$entity_type = sanitize_key( $entity_type );
					foreach ( array_values( array_unique( array_filter( (array) $signal_ids ) ) ) as $signal_id ) {
						$resolved = $this->repository->auto_resolve_stale_insights(
							$cutoff,
							array(
								'signal_id'         => $signal_id,
								'entity_type'       => $entity_type,
								'spam_scope'        => $auto_resolve_spam_scope,
								// Bug 5 hardening: never resolve what this very run
								// just wrote. last_seen_at is not always "now" (the
								// funnel aggregator sources it from real
								// funnel_tracking.last_activity), so a backdated
								// corpus could otherwise make a run resolve its own
								// fresh output. Mirrors the protection
								// auto_resolve_unseen_insights() already applies.
								'active_group_keys' => array_values( array_unique( $active_group_keys ) ),
							)
						);
						if ( ! is_wp_error( $resolved ) ) {
							$auto_resolved += (int) $resolved;
						}
					}
				}
			}

			$result = array(
				'success'            => true,
				'source'             => sanitize_key( $args['source'] ),
				'scheduler_cycle_id' => sanitize_text_field( (string) $args['scheduler_cycle_id'] ),
				'date_range'         => $date_range,
				'thresholds'      => array(
					'min_page_sessions' => $safe_min_sessions,
				),
				'evaluated_pages' => count( $page_metrics ),
				'evaluated_devices'=> count( $device_metrics ),
				'generated_count' => count( array_unique( $stored_ids ) ),
				'stored_ids'     => array_values( array_unique( $stored_ids ) ),
				'auto_resolved'  => $auto_resolved,
				// Bug 5 hardening: per-entity signals/metrics/candidates/stored counts
				// plus an explicit skip reason, so "the full run stored no
				// funnel/form/error insights" can be diagnosed from the run result
				// alone instead of by instrumenting the loop after the fact.
				'entity_diagnostics' => $entity_diagnostics,
				'errors'         => array_values( array_unique( $error_messages ) ),
				'generated_at'   => current_time( 'mysql' ),
				'exclude_spam'   => null === $args['exclude_spam'] ? null : (bool) $args['exclude_spam'],
			);

			set_transient( $this->get_generated_transient_key( $date_range['from'], $date_range['to'], $args['exclude_spam'] ), time(), DAY_IN_SECONDS );
			update_option( 'opti_behavior_smart_insights_last_generation', $result, false );

			return $result;
		} catch ( Exception $e ) {
			return new WP_Error( 'opti_behavior_smart_insights_generation_failed', $e->getMessage() );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Re-evaluate one signal against one entity for a period without persisting.
	 *
	 * This is the read-only half of `generate_for_period()`: the same registry,
	 * baselines, aggregators, and rule objects, but nothing is stored, no
	 * lifecycle transition happens, and no generation lock is taken. It exists so
	 * a caller can ask "does this exact problem still trigger?" for a later
	 * window — the post-experiment check of the learning loop — without creating
	 * or mutating insight rows as a side effect of asking.
	 *
	 * @since 1.3.10
	 *
	 * @param string $signal_id   Signal ID to evaluate.
	 * @param string $entity_type Entity type the signal belongs to.
	 * @param string $entity_id   Entity ID to look for in the aggregated metrics.
	 * @param string $start_date  Range start (Y-m-d).
	 * @param string $end_date    Range end (Y-m-d).
	 * @param array  $args        Optional args (`exclude_spam`, `min_sessions`).
	 * @return array `array( available, reason, triggered, insight, metrics, date_range )`.
	 */
	public function evaluate_signal_for_entity( $signal_id, $entity_type, $entity_id, $start_date, $end_date, $args = array() ) {
		$signal_id   = sanitize_key( $signal_id );
		$entity_type = sanitize_key( $entity_type );
		$entity_id   = is_scalar( $entity_id ) ? (string) $entity_id : '';

		$result = array(
			'available'  => false,
			'reason'     => '',
			'triggered'  => false,
			'insight'    => null,
			'metrics'    => array(),
			'date_range' => array(),
		);

		if ( '' === $signal_id || '' === $entity_type || '' === $entity_id ) {
			$result['reason'] = 'invalid_scope';
			return $result;
		}

		$date_range = $this->normalize_date_range( $start_date, $end_date );
		if ( is_wp_error( $date_range ) ) {
			$result['reason'] = 'invalid_range';
			return $result;
		}

		$result['date_range'] = $date_range;

		$defaults = array(
			'exclude_spam'              => null,
			'candidate_limit'           => 50,
			'entity_types'              => array(),
			'signal_ids'                => array( $signal_id ),
			'allow_low_volume_entities' => false,
			'source'                    => 'signal_recheck',
		);
		$args     = wp_parse_args( $args, $defaults );

		$signals = $this->signal_registry->for_entity_type(
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

		$signals = $this->filter_signals_by_ids( $signals, array( $signal_id ) );
		if ( empty( $signals ) ) {
			$result['reason'] = 'signal_not_registered';
			return $result;
		}

		$method = 'evaluate_' . $entity_type;
		$baseline_args = array();
		if ( null !== $args['exclude_spam'] ) {
			$baseline_args['exclude_spam'] = (bool) $args['exclude_spam'];
		}

		$baselines         = $this->baseline_calculator->calculate( $date_range['from'], $date_range['to'], $baseline_args );
		$safe_min_sessions = $this->get_safe_min_sessions_threshold( $baselines, $args );

		$aggregation_args = array(
			'limit'                => max( 1, min( 500, absint( $args['candidate_limit'] ) ) ),
			'min_sessions'         => $safe_min_sessions,
			'include_previous'     => true,
			'include_event_counts' => true,
		);
		if ( null !== $args['exclude_spam'] ) {
			$aggregation_args['exclude_spam'] = (bool) $args['exclude_spam'];
		}

		if ( 'page' === $entity_type ) {
			$rows = $this->metric_aggregator->aggregate_page_metrics( $date_range['from'], $date_range['to'], $aggregation_args );
		} elseif ( 'device' === $entity_type ) {
			$rows = $this->get_device_metrics_for_period( $date_range, $args, $safe_min_sessions );
		} else {
			$rows      = $this->get_metrics_for_entity_type( $entity_type, $date_range, $args, $safe_min_sessions );
			$baselines = method_exists( $this->baseline_calculator, 'get_baselines' )
				? wp_parse_args( (array) $this->baseline_calculator->get_baselines( $entity_type, $date_range, $baseline_args ), $baselines )
				: $baselines;
			$baselines = apply_filters( 'opti_behavior_smart_insights_baselines_for_entity_type', $baselines, $entity_type, $date_range, $args );
		}

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			$result['reason'] = 'no_metrics';
			return $result;
		}

		$evaluation_args                 = $args;
		$evaluation_args['min_sessions'] = $safe_min_sessions;
		$evaluation_args[ 'all_' . $entity_type . '_metrics' ] = $rows;

		$matched = null;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['entity_id'] ) && (string) $row['entity_id'] === $entity_id ) {
				$matched = $row;
				break;
			}
		}

		if ( null === $matched ) {
			$result['reason'] = 'entity_not_in_metrics';
			return $result;
		}

		$result['available'] = true;
		$result['metrics']   = $matched;

		foreach ( $signals as $signal ) {
			if ( ! is_object( $signal ) || ! method_exists( $signal, $method ) ) {
				continue;
			}

			$insight = $signal->{$method}( $matched, $baselines, $date_range, $evaluation_args );
			if ( is_array( $insight ) && ! empty( $insight ) ) {
				$result['triggered'] = true;
				$result['insight']   = $insight;
				break;
			}
		}

		return $result;
	}

	/**
	 * Generate when cached results for a period are stale.
	 *
	 * @param string $period Period slug.
	 * @param array  $args   Optional args.
	 * @return array|WP_Error
	 */
	public function maybe_generate_if_stale( $period = self::DEFAULT_PERIOD, $args = array() ) {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return array(
				'skipped' => true,
				'reason'  => 'not_admin_or_cron',
			);
		}

		$defaults = array(
			'start_date'               => '',
			'end_date'                 => '',
			'force'                    => false,
			'stale_seconds'            => DAY_IN_SECONDS,
			'attempt_throttle_seconds' => 0,
			'attempt_transient_key'    => '',
			'source'                   => 'admin_stale',
		);
		$args     = wp_parse_args( $args, $defaults );
		$range    = $this->get_date_range_for_period( $period, $args['start_date'], $args['end_date'] );
		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$stale_seconds = isset( $args['stale_seconds'] ) ? max( 300, absint( $args['stale_seconds'] ) ) : DAY_IN_SECONDS;
		$source               = sanitize_key( isset( $args['source'] ) ? $args['source'] : 'admin_stale' );
		$exclude_spam_context = array_key_exists( 'exclude_spam', $args ) && null !== $args['exclude_spam'] ? (bool) $args['exclude_spam'] : null;
		$generated_key        = $this->get_generated_transient_key( $range['from'], $range['to'], $exclude_spam_context );
		$last_run             = get_transient( $generated_key );

		if ( empty( $args['force'] ) && $last_run ) {
			return array(
				'skipped'        => true,
				'reason'         => 'fresh',
				'source'         => $source,
				'period'         => sanitize_key( $period ),
				'date_range'     => $range,
				'exclude_spam'   => $exclude_spam_context,
				'last_generated' => (int) $last_run,
			);
		}

		$attempt_throttle_seconds = isset( $args['attempt_throttle_seconds'] ) ? absint( $args['attempt_throttle_seconds'] ) : 0;
		if ( empty( $args['force'] ) && $attempt_throttle_seconds > 0 ) {
			$attempt_throttle_seconds = max( 300, $attempt_throttle_seconds );
			$attempt_key              = ! empty( $args['attempt_transient_key'] ) ? sanitize_key( $args['attempt_transient_key'] ) : $this->get_attempt_transient_key( $range['from'], $range['to'], $source, $exclude_spam_context );
			$last_attempt             = get_transient( $attempt_key );
			if ( $last_attempt ) {
				return array(
					'skipped'             => true,
					'reason'              => 'throttled',
					'source'              => $source,
					'period'              => sanitize_key( $period ),
					'date_range'          => $range,
					'exclude_spam'        => $exclude_spam_context,
					'last_generated'      => 0,
					'last_attempt'        => (int) $last_attempt,
					'throttle_seconds'    => $attempt_throttle_seconds,
					'next_attempt_after'  => (int) $last_attempt + $attempt_throttle_seconds,
				);
			}

			set_transient( $attempt_key, time(), $attempt_throttle_seconds );
		}

		$args['source'] = $source;
		$result         = $this->generate_for_period( $range['from'], $range['to'], $args );
		if ( ! is_wp_error( $result ) ) {
			set_transient( $generated_key, time(), $stale_seconds );
		}

		return $result;
	}

	/**
	 * Resolve a dashboard/admin period slug into dates.
	 *
	 * @param string $period     Period slug.
	 * @param string $start_date Optional custom start date.
	 * @param string $end_date   Optional custom end date.
	 * @return array|WP_Error
	 */
	public function get_date_range_for_period( $period = self::DEFAULT_PERIOD, $start_date = '', $end_date = '' ) {
		$period = sanitize_key( $period ? $period : self::DEFAULT_PERIOD );

		if ( class_exists( 'Opti_Behavior_Stats_Date_Range' ) ) {
			$range = Opti_Behavior_Stats_Date_Range::resolve( $period, $start_date, $end_date );
			return array(
				'from'         => $range['start_date'],
				'to'           => $range['end_date'],
				'label'        => $range['period'],
				'sql_start'    => $range['sql_start'],
				'sql_end'      => $range['sql_end'],
				'end_mode'     => $range['end_mode'],
				'metric_grain' => 'generated_snapshot',
				'source'       => 'smart_insights_generator',
			);
		}

		$today  = wp_date( 'Y-m-d', current_time( 'timestamp' ) );

		if ( 'custom' === $period ) {
			return $this->normalize_date_range( $start_date, $end_date, 'custom' );
		}

		switch ( $period ) {
			case 'today':
				$from = $today;
				$to   = $today;
				break;
			case 'yesterday':
				$from = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
				$to   = $from;
				break;
			case 'last30days':
			case 'last30':
				$from   = gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
				$to     = $today;
				$period = 'last30days';
				break;
			case 'last14days':
			case 'last14':
				$from   = gmdate( 'Y-m-d', strtotime( $today . ' -13 days' ) );
				$to     = $today;
				$period = 'last14days';
				break;
			case 'last7':
			case 'last7days':
				$from   = gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );
				$to     = $today;
				$period = 'last7days';
				break;
			default:
				$from   = gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
				$to     = $today;
				$period = self::DEFAULT_PERIOD;
				break;
		}

		return $this->normalize_date_range( $from, $to, $period );
	}

	/**
	 * Build a transient key for generation freshness.
	 *
	 * @param string $from Start date.
	 * @param string    $to           End date.
	 * @param bool|null $exclude_spam Spam exclusion scope.
	 * @return string
	 */
	public function get_generated_transient_key( $from, $to, $exclude_spam = null ) {
		return 'opti_behavior_si_generated_' . md5( $from . '|' . $to . '|' . $this->normalize_spam_scope_key( $exclude_spam ) );
	}

	/**
	 * Build a transient key for stale-generation attempts.
	 *
	 * This is separate from the generated freshness transient so failed or locked
	 * passive refreshes do not retry expensive aggregate queries on every browser
	 * reload.
	 *
	 * @since 1.3.7
	 * @param string $from   Start date.
	 * @param string $to     End date.
	 * @param string    $source       Generation source.
	 * @param bool|null $exclude_spam Spam exclusion scope.
	 * @return string
	 */
	public function get_attempt_transient_key( $from, $to, $source = 'admin_stale', $exclude_spam = null ) {
		return 'opti_behavior_si_attempt_' . md5( $from . '|' . $to . '|' . sanitize_key( $source ) . '|' . $this->normalize_spam_scope_key( $exclude_spam ) );
	}

	/**
	 * Normalize a spam exclusion scope to a stable cache key fragment.
	 *
	 * @param bool|null $exclude_spam Spam exclusion scope.
	 * @return string
	 */
	private function normalize_spam_scope_key( $exclude_spam = null ) {
		if ( null === $exclude_spam ) {
			return 'default';
		}

		return $exclude_spam ? 'exclude_spam_1' : 'exclude_spam_0';
	}

	/**
	 * Persist the reporting spam scope on an insight and isolate dedupe keys by scope.
	 *
	 * @param array     $insight      Insight payload.
	 * @param bool|null $exclude_spam Explicit spam exclusion state.
	 * @return array
	 */
	private function apply_spam_scope_to_insight( $insight, $exclude_spam = null ) {
		if ( ! is_array( $insight ) ) {
			return $insight;
		}

		$scope_key = $this->normalize_spam_scope_key( $exclude_spam );
		if ( 'default' === $scope_key ) {
			return $insight;
		}

		if ( empty( $insight['metrics'] ) || ! is_array( $insight['metrics'] ) ) {
			$insight['metrics'] = array();
		}
		if ( empty( $insight['detection'] ) || ! is_array( $insight['detection'] ) ) {
			$insight['detection'] = array();
		}

		$insight['metrics']['spam_scope']   = $scope_key;
		$insight['detection']['spam_scope'] = $scope_key;

		$base_group_key = ! empty( $insight['group_key'] ) ? sanitize_text_field( (string) $insight['group_key'] ) : $this->build_insight_group_key( $insight );
		$scope_suffix   = '|si_spam_scope:' . $scope_key;
		$base_group_key = preg_replace( '/\|si_spam_scope:(exclude_spam_0|exclude_spam_1|default)$/', '', $base_group_key );
		$max_base_len   = 191 - strlen( $scope_suffix );

		$insight['group_key'] = substr( $base_group_key, 0, max( 1, $max_base_len ) ) . $scope_suffix;

		return $insight;
	}

	/**
	 * Normalize a date range.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param string $label      Optional label.
	 * @return array|WP_Error
	 */
	private function normalize_date_range( $start_date, $end_date, $label = '' ) {
		$from = $this->normalize_date( $start_date );
		$to   = $this->normalize_date( $end_date );

		if ( ! $from || ! $to ) {
			return new WP_Error( 'opti_behavior_smart_insights_invalid_date_range', __( 'Invalid Smart Insights date range.', 'opti-behavior' ) );
		}

		if ( strtotime( $from ) > strtotime( $to ) ) {
			$tmp  = $from;
			$from = $to;
			$to   = $tmp;
		}

		return array(
			'from'  => $from,
			'to'    => $to,
			'label' => sanitize_key( $label ),
		);
	}

	/**
	 * Normalize a date string to Y-m-d.
	 *
	 * @param string $date Date string.
	 * @return string
	 */
	private function normalize_date( $date ) {
		$date = is_string( $date ) ? trim( $date ) : '';
		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		$timestamp = strtotime( $date );
		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}

	/**
	 * Get the safe page-session threshold for candidate selection and signals.
	 *
	 * Admin and cron generation should never lower the signal's product-defined
	 * threshold. A higher explicit override is allowed for stricter generation,
	 * but unsafe low overrides are ignored to prevent low-volume false positives.
	 *
	 * @param array $baselines Site baseline metrics.
	 * @param array $args      Generation args.
	 * @return int
	 */
	private function get_safe_min_sessions_threshold( $baselines, $args ) {
		$site_sessions = isset( $baselines['site_sessions'] ) ? (int) $baselines['site_sessions'] : 0;
		$threshold     = Opti_Behavior_Smart_Insights_Signal_High_Traffic_Low_Engagement::MIN_SESSIONS;

		if ( $site_sessions > 0 && $site_sessions < Opti_Behavior_Smart_Insights_Signal_High_Traffic_Low_Engagement::LOW_SITE_SESSIONS_THRESHOLD ) {
			$threshold = Opti_Behavior_Smart_Insights_Signal_High_Traffic_Low_Engagement::ADAPTIVE_MIN_SESSIONS;
		}

		if ( isset( $args['min_sessions'] ) && '' !== $args['min_sessions'] ) {
			$override = absint( $args['min_sessions'] );
			if ( ! empty( $args['allow_low_volume_entities'] ) && $override > 0 ) {
				$threshold = max( 1, $override );
			} elseif ( $override > $threshold ) {
				$threshold = $override;
			}
		}

		$filtered_threshold = absint( apply_filters( 'opti_behavior_smart_insights_min_sessions_threshold', $threshold, $baselines, $args ) );

		return max( $threshold, $filtered_threshold );
	}

	/**
	 * Normalize optional generation filters used by the scheduler.
	 *
	 * @param mixed $values Filter values.
	 * @return array
	 */
	private function normalize_generation_filter_list( $values ) {
		if ( is_string( $values ) ) {
			$values = preg_split( '/[,|]/', $values );
		}

		$list = array();
		foreach ( (array) $values as $value ) {
			$value = sanitize_key( $value );
			if ( '' !== $value ) {
				$list[] = $value;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Check if an entity type should be evaluated for this generation pass.
	 *
	 * @param string $entity_type           Entity type.
	 * @param array  $selected_entity_types Optional scheduler entity filter.
	 * @return bool
	 */
	private function should_process_entity_type( $entity_type, $selected_entity_types ) {
		$entity_type = sanitize_key( $entity_type );
		if ( '' === $entity_type ) {
			return false;
		}

		if ( empty( $selected_entity_types ) ) {
			return true;
		}

		return in_array( $entity_type, $selected_entity_types, true );
	}

	/**
	 * Limit a signal list to requested IDs when the scheduler passes a partial batch.
	 *
	 * @param array $signals             Signal instances.
	 * @param array $selected_signal_ids Optional signal ID filter.
	 * @return array
	 */
	private function filter_signals_by_ids( $signals, $selected_signal_ids ) {
		if ( empty( $selected_signal_ids ) ) {
			return array_values( (array) $signals );
		}

		$filtered = array();
		foreach ( (array) $signals as $signal ) {
			if ( $this->signal_matches_id( $signal, $selected_signal_ids ) ) {
				$filtered[] = $signal;
			}
		}

		return $filtered;
	}

	/**
	 * Determine whether a signal object matches one of the requested IDs.
	 *
	 * @param object $signal              Signal instance.
	 * @param array  $selected_signal_ids Requested signal IDs.
	 * @return bool
	 */
	private function signal_matches_id( $signal, $selected_signal_ids ) {
		if ( ! is_object( $signal ) ) {
			return false;
		}

		$signal_id = '';
		if ( method_exists( $signal, 'get_definition' ) ) {
			$definition = $signal->get_definition();
			if ( is_array( $definition ) && ! empty( $definition['signal_id'] ) ) {
				$signal_id = sanitize_key( $definition['signal_id'] );
			}
		}

		if ( '' === $signal_id && defined( get_class( $signal ) . '::SIGNAL_ID' ) ) {
			$signal_id = sanitize_key( constant( get_class( $signal ) . '::SIGNAL_ID' ) );
		}

		return '' !== $signal_id && in_array( $signal_id, $selected_signal_ids, true );
	}

	/**
	 * Get device metrics only when a registered signal can use them.
	 *
	 * @param array $date_range        Date range.
	 * @param array $args              Generation args.
	 * @param int   $safe_min_sessions Minimum sessions.
	 * @return array
	 */
	private function get_device_metrics_for_period( $date_range, $args, $safe_min_sessions ) {
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Device_Aggregator' ) ) {
			return array();
		}

		$device_signals = $this->signal_registry->for_entity_type(
			'device',
			array(
				'date_range' => $date_range,
				'args'       => $args,
			)
		);
		if ( empty( $device_signals ) ) {
			return array();
		}

		$aggregation_args = array(
			'limit'        => 20,
			'min_sessions' => min( $safe_min_sessions, 100 ),
		);

		if ( null !== $args['exclude_spam'] ) {
			$aggregation_args['exclude_spam'] = (bool) $args['exclude_spam'];
		}

		$aggregator = new Opti_Behavior_Smart_Insights_Device_Aggregator();
		$metrics    = $aggregator->aggregate_device_metrics( $date_range['from'], $date_range['to'], $aggregation_args );

		// Device rows used to be the only entity family with no previous-period
		// lookup at all, so every device insight stored an empty trend.
		if ( empty( $args['skip_trends'] ) ) {
			$metrics = $this->attach_entity_trends( $metrics, 'device', 'entity_id', $date_range, $args );
		}

		return $metrics;
	}

	/**
	 * Get aggregate metrics for non-page/non-device entity types.
	 *
	 * Pro signals rely on this generic path while Free continues to own the
	 * storage, scoring lifecycle, and privacy boundary. Metrics returned here
	 * must be aggregate-only and may be supplied by Pro through filters.
	 *
	 * @param string $entity_type       Entity type.
	 * @param array  $date_range        Date range.
	 * @param array  $args              Generation args.
	 * @param int    $safe_min_sessions Safe session threshold.
	 * @param array  $page_metrics      Current page metrics for CTA joining.
	 * @return array
	 */
	private function get_metrics_for_entity_type( $entity_type, $date_range, $args, $safe_min_sessions, $page_metrics = array() ) {
		$entity_type = sanitize_key( $entity_type );
		$metrics     = array();

		switch ( $entity_type ) {
			case 'source':
				if ( class_exists( 'Opti_Behavior_Smart_Insights_Source_Aggregator' ) ) {
					$metrics = ( new Opti_Behavior_Smart_Insights_Source_Aggregator() )->aggregate_source_metrics(
						$date_range['from'],
						$date_range['to'],
						array(
							'limit'        => 50,
							'min_sessions' => min( $safe_min_sessions, 100 ),
							'exclude_spam' => null !== $args['exclude_spam'] ? (bool) $args['exclude_spam'] : null,
						)
					);
					if ( empty( $args['skip_trends'] ) ) {
						$metrics = $this->attach_entity_trends( $metrics, 'source', 'entity_id', $date_range, $args );
					}
				}
				break;
			case 'campaign':
				if ( class_exists( 'Opti_Behavior_Smart_Insights_Source_Aggregator' ) ) {
					$metrics = ( new Opti_Behavior_Smart_Insights_Source_Aggregator() )->aggregate_campaign_metrics(
						$date_range['from'],
						$date_range['to'],
						array(
							'limit'        => 50,
							'min_sessions' => min( $safe_min_sessions, 100 ),
							'exclude_spam' => null !== $args['exclude_spam'] ? (bool) $args['exclude_spam'] : null,
						)
					);
					if ( empty( $args['skip_trends'] ) ) {
						$metrics = $this->attach_entity_trends( $metrics, 'campaign', 'entity_id', $date_range, $args );
					}
				}
				break;
			case 'funnel':
				if ( class_exists( 'Opti_Behavior_Smart_Insights_Funnel_Aggregator' ) ) {
					$metrics = ( new Opti_Behavior_Smart_Insights_Funnel_Aggregator() )->aggregate_funnel_metrics(
						$date_range['from'],
						$date_range['to'],
						array(
							'limit'        => 50,
							'min_entries'  => 1,
							'exclude_spam' => null !== $args['exclude_spam'] ? (bool) $args['exclude_spam'] : null,
						)
					);
					if ( empty( $args['skip_trends'] ) ) {
						$metrics = $this->attach_entity_trends( $metrics, 'funnel', 'entity_id', $date_range, $args );
					}
				}
				break;
			case 'cta':
				if ( class_exists( 'Opti_Behavior_Smart_Insights_Heatmap_Aggregator' ) ) {
					$metrics = ( new Opti_Behavior_Smart_Insights_Heatmap_Aggregator() )->aggregate_heatmap_metrics(
						$date_range['from'],
						$date_range['to'],
						array(
							'limit'        => 100,
							'min_clicks'   => 1,
							'exclude_spam' => null !== $args['exclude_spam'] ? (bool) $args['exclude_spam'] : null,
						)
					);
					$metrics = $this->merge_page_context_into_cta_metrics( $metrics, $page_metrics );
					if ( empty( $args['skip_trends'] ) ) {
						$metrics = $this->attach_entity_trends( $metrics, 'cta', 'entity_id', $date_range, $args );
					}
				}
				break;
		}

		$handled_natively = in_array( $entity_type, array( 'source', 'campaign', 'funnel', 'cta' ), true );

		$metrics = apply_filters( 'opti_behavior_smart_insights_metrics_for_entity_type', $metrics, $entity_type, $date_range, $args );

		// Entity families that only exist because a layer above answered the
		// filter (Pro's form/error/segment/test rows) never reached a trend
		// attachment, so every one of their insights stored an empty trend and the
		// modal fell back to "Previous-period trend is not available yet". The
		// recursive call below re-runs the same filter for the previous window with
		// skip_trends set, so it terminates after exactly one extra pass.
		if ( ! $handled_natively && ! empty( $metrics ) && empty( $args['skip_trends'] ) ) {
			$metrics = $this->attach_entity_trends( $metrics, $entity_type, 'entity_id', $date_range, $args );
		}

		return $metrics;
	}

	/**
	 * Attach previous-period trend payloads to entity metrics.
	 *
	 * @param array  $metrics     Current rows.
	 * @param string $entity_type Entity type.
	 * @param string $entity_key  Entity key.
	 * @param array  $date_range  Date range.
	 * @param array  $args        Generation args.
	 * @return array
	 */
	private function attach_entity_trends( $metrics, $entity_type, $entity_key, $date_range, $args ) {
		if ( empty( $metrics ) || ! class_exists( 'Opti_Behavior_Smart_Insights_Trend_Calculator' ) ) {
			return $metrics;
		}

		$trend_calculator = new Opti_Behavior_Smart_Insights_Trend_Calculator();
		$previous_range   = $trend_calculator->get_previous_period_range( $date_range['from'], $date_range['to'] );
		if ( empty( $previous_range['from'] ) || empty( $previous_range['to'] ) ) {
			return $metrics;
		}

		$previous_date_range = array(
			'from' => $previous_range['from'],
			'to'   => $previous_range['to'],
		);
		$previous_args       = wp_parse_args( array( 'skip_trends' => true ), $args );

		if ( 'device' === $entity_type ) {
			// Devices come from their own aggregator, not the generic entity path.
			// `0` as the session floor: the previous window only has to report what
			// a device measured, not re-qualify as a detection candidate.
			$previous = $this->get_device_metrics_for_period( $previous_date_range, $previous_args, 0 );
		} else {
			$previous = $this->get_metrics_for_entity_type(
				$entity_type,
				$previous_date_range,
				$previous_args,
				0,
				array()
			);
		}

		foreach ( $metrics as $index => $row ) {
			$metrics[ $index ]['previous_period_range'] = $previous_range;
		}

		return $trend_calculator->attach_entity_trends(
			$metrics,
			$previous,
			$entity_key,
			// Error, recording and segment entities carry their own metric keys.
			// Any key missing here is dropped by compare_metrics(), which is why
			// error insights used to store an empty trend even once the previous
			// window was fetched for them.
			array( 'sessions', 'users', 'pageviews', 'bounce_rate', 'exit_rate', 'avg_scroll_depth', 'avg_time_on_page', 'avg_session_duration', 'conversion_rate', 'conversions', 'entries', 'completion_rate', 'dropoff_rate', 'cta_click_rate', 'click_count', 'starts', 'submits', 'abandonment_rate', 'error_rate', 'form_error_rate', 'worst_field_error_rate', 'error_sessions', 'error_count', 'error_session_conversion_rate', 'non_error_session_conversion_rate', 'rage_click_events', 'dead_click_rate', 'recordings', 'recording_watch_rate', 'segment_sessions', 'segment_conversion_rate', 'returning_conversion_rate', 'new_conversion_rate', 'returning_bounce_rate', 'new_bounce_rate' ),
			array( 'bounce_rate', 'exit_rate', 'avg_scroll_depth', 'conversion_rate', 'completion_rate', 'dropoff_rate', 'cta_click_rate', 'abandonment_rate', 'error_rate', 'form_error_rate', 'worst_field_error_rate', 'error_session_conversion_rate', 'non_error_session_conversion_rate', 'dead_click_rate', 'recording_watch_rate', 'segment_conversion_rate', 'returning_conversion_rate', 'new_conversion_rate', 'returning_bounce_rate', 'new_bounce_rate' )
		);
	}

	/**
	 * Add page sessions/scroll context to CTA rows when a URL or page ID matches.
	 *
	 * @param array $cta_metrics  CTA rows.
	 * @param array $page_metrics Page rows.
	 * @return array
	 */
	private function merge_page_context_into_cta_metrics( $cta_metrics, $page_metrics ) {
		if ( empty( $cta_metrics ) || empty( $page_metrics ) ) {
			return $cta_metrics;
		}

		$by_id  = array();
		$by_url = array();
		foreach ( $page_metrics as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			if ( ! empty( $page['page_id'] ) ) {
				$by_id[ (int) $page['page_id'] ] = $page;
			}
			if ( ! empty( $page['page_url'] ) ) {
				$by_url[ (string) $page['page_url'] ] = $page;
			}
		}

		foreach ( $cta_metrics as $index => $row ) {
			$page = array();
			if ( ! empty( $row['page_id'] ) && isset( $by_id[ (int) $row['page_id'] ] ) ) {
				$page = $by_id[ (int) $row['page_id'] ];
			} elseif ( ! empty( $row['page_url'] ) && isset( $by_url[ (string) $row['page_url'] ] ) ) {
				$page = $by_url[ (string) $row['page_url'] ];
			}

			if ( empty( $page ) ) {
				continue;
			}

			foreach ( array( 'sessions', 'users', 'pageviews', 'bounce_rate', 'avg_scroll_depth', 'avg_time_on_page', 'conversion_rate' ) as $key ) {
				if ( isset( $page[ $key ] ) && ( ! isset( $cta_metrics[ $index ][ $key ] ) || null === $cta_metrics[ $index ][ $key ] || 0 === $cta_metrics[ $index ][ $key ] ) ) {
					$cta_metrics[ $index ][ $key ] = $page[ $key ];
				}
			}
		}

		return $cta_metrics;
	}

	/**
	 * Add stable entity identifiers and links into persisted metrics payloads.
	 *
	 * This keeps cards actionable in both Free and Pro contexts without adding
	 * new database columns. The UI can read metrics.entity_context safely.
	 *
	 * @param array $insight        Insight payload.
	 * @param array $candidate_data Raw candidate metric row.
	 * @return array
	 */
	private function enrich_insight_entity_context( $insight, $candidate_data ) {
		if ( ! is_array( $insight ) ) {
			return $insight;
		}

		$insight_metrics = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
		$candidate_data  = is_array( $candidate_data ) ? $candidate_data : array();
		$entity_type     = isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '';
		$entity_id       = isset( $insight['entity_id'] ) ? (string) $insight['entity_id'] : '';
		$entity_label    = isset( $insight['entity_label'] ) ? sanitize_text_field( (string) $insight['entity_label'] ) : '';

		$entity_context = array(
			'entity_type' => $entity_type,
			'entity_id'   => $entity_id,
			'label'       => $entity_label,
			'identifier'  => '' !== $entity_id ? $entity_id : $entity_label,
			'view_url'    => '',
			'admin_url'   => '',
			'report_url'  => '',
		);

		$page_id  = 0;
		$page_url = '';
		if ( isset( $candidate_data['page_id'] ) && is_numeric( $candidate_data['page_id'] ) ) {
			$page_id = absint( $candidate_data['page_id'] );
		} elseif ( isset( $insight_metrics['page_id'] ) && is_numeric( $insight_metrics['page_id'] ) ) {
			$page_id = absint( $insight_metrics['page_id'] );
		} elseif ( 'page' === $entity_type && is_numeric( $entity_id ) ) {
			$page_id = absint( $entity_id );
		}

		if ( isset( $candidate_data['page_url'] ) && $this->is_http_url( $candidate_data['page_url'] ) ) {
			$page_url = esc_url_raw( (string) $candidate_data['page_url'] );
		} elseif ( isset( $insight_metrics['page_url'] ) && $this->is_http_url( $insight_metrics['page_url'] ) ) {
			$page_url = esc_url_raw( (string) $insight_metrics['page_url'] );
		} elseif ( $this->is_http_url( $entity_id ) ) {
			$page_url = esc_url_raw( $entity_id );
		}

		if ( '' === $page_url && $page_id > 0 && function_exists( 'get_permalink' ) ) {
			$permalink = get_permalink( $page_id );
			if ( $this->is_http_url( $permalink ) ) {
				$page_url = esc_url_raw( $permalink );
			}
		}

		if ( '' !== $page_url ) {
			$entity_context['view_url'] = $page_url;
		}

		if ( $page_id > 0 ) {
			$post_exists  = function_exists( 'get_post' ) ? (bool) get_post( $page_id ) : true;
			$can_edit_post = ! function_exists( 'current_user_can' ) || current_user_can( 'edit_post', $page_id );
			if ( $post_exists && $can_edit_post && function_exists( 'get_edit_post_link' ) ) {
				$edit_url = get_edit_post_link( $page_id, '' );
				if ( $this->is_http_url( $edit_url ) ) {
					$entity_context['admin_url'] = esc_url_raw( $edit_url );
				}
			}

			if ( $post_exists && $can_edit_post && '' === $entity_context['admin_url'] && function_exists( 'admin_url' ) && function_exists( 'add_query_arg' ) ) {
				$entity_context['admin_url'] = esc_url_raw(
					admin_url(
						add_query_arg(
							array(
								'post'   => $page_id,
								'action' => 'edit',
							),
							'post.php'
						)
					)
				);
			}
		}

		if ( isset( $insight['related_reports'] ) && is_array( $insight['related_reports'] ) ) {
			foreach ( $insight['related_reports'] as $report ) {
				if ( ! is_array( $report ) ) {
					continue;
				}

				$report_url = '';
				if ( ! empty( $report['url'] ) && $this->is_http_url( $report['url'] ) ) {
					$report_url = esc_url_raw( (string) $report['url'] );
				} elseif ( ! empty( $report['href'] ) && $this->is_http_url( $report['href'] ) ) {
					$report_url = esc_url_raw( (string) $report['href'] );
				}

				if ( '' !== $report_url ) {
					$entity_context['report_url'] = $report_url;
					break;
				}
			}
		}

		$insight_metrics['entity_context'] = $entity_context;
		$insight['metrics']                = $insight_metrics;

		return $insight;
	}

	/**
	 * Collect stable signal IDs from a signal list.
	 *
	 * @param array  $signals     Signal instances.
	 * @param string $entity_type Entity type fallback.
	 * @param array  $date_range  Date range.
	 * @param array  $args        Generation args.
	 * @return array
	 */
	private function collect_signal_ids( $signals, $entity_type, $date_range, $args ) {
		$ids = array();
		foreach ( (array) $signals as $signal ) {
			if ( is_object( $signal ) && method_exists( $signal, 'get_definition' ) ) {
				$definition = $signal->get_definition();
				if ( is_array( $definition ) && ! empty( $definition['signal_id'] ) ) {
					$ids[] = sanitize_key( $definition['signal_id'] );
					continue;
				}
			}
		}

		if ( empty( $ids ) && method_exists( $this->signal_registry, 'get_signal_ids' ) ) {
			$ids = $this->signal_registry->get_signal_ids( $entity_type, array( 'date_range' => $date_range, 'args' => $args ) );
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $ids ) ) ) );
	}

	/**
	 * Build the same logical group key persisted by the repository.
	 *
	 * @param array $insight Insight payload.
	 * @return string
	 */
	private function build_insight_group_key( $insight ) {
		if ( ! is_array( $insight ) ) {
			return '';
		}

		if ( ! empty( $insight['group_key'] ) ) {
			return substr( sanitize_text_field( $insight['group_key'] ), 0, 191 );
		}

		$parts = array(
			isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '',
			isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '',
			isset( $insight['entity_id'] ) ? sanitize_text_field( (string) $insight['entity_id'] ) : '',
		);

		return substr( implode( ':', array_filter( $parts ) ), 0, 191 );
	}

	/**
	 * Check if a value looks like an absolute HTTP/HTTPS URL.
	 *
	 * @param mixed $url Candidate URL.
	 * @return bool
	 */
	private function is_http_url( $url ) {
		$url = is_scalar( $url ) ? trim( (string) $url ) : '';
		if ( '' === $url ) {
			return false;
		}

		if ( function_exists( 'wp_http_validate_url' ) && wp_http_validate_url( $url ) ) {
			return true;
		}

		return (bool) preg_match( '#^https?://#i', $url );
	}

	/**
	 * Assign the noise-suppression group key to every candidate.
	 *
	 * The suppression decision itself lives in
	 * {@see self::apply_noise_suppression_marking()} because it has to read the
	 * final, impact-aware priority produced by
	 * {@see self::apply_priority_ranking()}. This pass only normalizes the group
	 * key (other steps rely on it) and drops malformed candidates, keeping the
	 * historical priority ordering the correlator receives.
	 *
	 * @param array $candidate_insights Candidate records.
	 * @return array
	 */
	private function apply_noise_suppression( $candidate_insights ) {
		if ( empty( $candidate_insights ) || ! is_array( $candidate_insights ) ) {
			return array();
		}

		usort(
			$candidate_insights,
			function( $left, $right ) {
				$left_score  = isset( $left['insight']['scores']['priority_score'] ) ? (int) $left['insight']['scores']['priority_score'] : 0;
				$right_score = isset( $right['insight']['scores']['priority_score'] ) ? (int) $right['insight']['scores']['priority_score'] : 0;

				return $right_score <=> $left_score;
			}
		);

		foreach ( $candidate_insights as $index => $candidate ) {
			if ( empty( $candidate['insight'] ) || ! is_array( $candidate['insight'] ) ) {
				unset( $candidate_insights[ $index ] );
				continue;
			}

			if ( empty( $candidate_insights[ $index ]['insight']['group_key'] ) ) {
				$candidate_insights[ $index ]['insight']['group_key'] = substr( $this->build_noise_group( $candidate['insight'] ), 0, 191 );
			}
		}

		return array_values( $candidate_insights );
	}

	/**
	 * Suppress low-value repeated observations while preserving high-priority alerts.
	 *
	 * The repository already deduplicates exact signal/entity/date matches. This
	 * pass avoids overwhelming Free dashboards with many similar low-priority
	 * page observations by keeping the strongest examples visible and storing the
	 * rest with a short suppression window. It runs after priority ranking so the
	 * decision is taken on the final score, and it never reorders the candidate
	 * list (story primaries must stay ahead of their children).
	 *
	 * @since 1.3.9
	 *
	 * @param array $candidate_insights Candidate records.
	 * @return array
	 */
	private function apply_noise_suppression_marking( $candidate_insights ) {
		if ( empty( $candidate_insights ) || ! is_array( $candidate_insights ) ) {
			return is_array( $candidate_insights ) ? $candidate_insights : array();
		}

		$max_visible_low_value = (int) apply_filters( 'opti_behavior_smart_insights_low_value_group_limit', 3 );
		$max_visible_low_value = max( 1, min( 10, $max_visible_low_value ) );

		$order = array();
		foreach ( $candidate_insights as $index => $candidate ) {
			if ( empty( $candidate['insight'] ) || ! is_array( $candidate['insight'] ) ) {
				continue;
			}

			$scores          = isset( $candidate['insight']['scores'] ) && is_array( $candidate['insight']['scores'] ) ? $candidate['insight']['scores'] : array();
			$order[ $index ] = array(
				'rank'  => isset( $scores['priority_rank'] ) ? (int) $scores['priority_rank'] : PHP_INT_MAX,
				'score' => isset( $scores['priority_score'] ) ? (int) $scores['priority_score'] : 0,
			);
		}

		uasort(
			$order,
			function( $left, $right ) {
				if ( $left['rank'] !== $right['rank'] ) {
					return $left['rank'] <=> $right['rank'];
				}

				return $right['score'] <=> $left['score'];
			}
		);

		$visible_per_group = array();

		foreach ( array_keys( $order ) as $index ) {
			$insight = $candidate_insights[ $index ]['insight'];
			$group   = $this->build_noise_group( $insight );

			if ( ! isset( $visible_per_group[ $group ] ) ) {
				$visible_per_group[ $group ] = 0;
			}

			if ( $this->is_low_value_insight( $insight ) && $visible_per_group[ $group ] >= $max_visible_low_value ) {
				$candidate_insights[ $index ]['insight']['suppressed_until'] = gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) );
				$candidate_insights[ $index ]['insight']['detection'] = isset( $candidate_insights[ $index ]['insight']['detection'] ) && is_array( $candidate_insights[ $index ]['insight']['detection'] )
					? $candidate_insights[ $index ]['insight']['detection']
					: array();
				$candidate_insights[ $index ]['insight']['detection']['noise_suppressed'] = true;
				$candidate_insights[ $index ]['insight']['detection']['noise_suppression_reason'] = 'similar_low_value_observation';
			} else {
				$visible_per_group[ $group ]++;
			}
		}

		return $candidate_insights;
	}

	/**
	 * Build the signal/category/entity noise-suppression group key.
	 *
	 * @since 1.3.9
	 *
	 * @param array $insight Insight payload.
	 * @return string
	 */
	private function build_noise_group( $insight ) {
		$signal_id   = isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : 'unknown';
		$category    = isset( $insight['category'] ) ? sanitize_key( $insight['category'] ) : 'general';
		$entity_type = isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : 'entity';

		return $signal_id . ':' . $category . ':' . $entity_type;
	}

	/**
	 * Whether an insight counts as a low-value repeated observation.
	 *
	 * Under `rank_v2` the decision follows the rank-based label (Medium or Low);
	 * legacy scoring keeps the historical `priority_score < 60` rule so the
	 * `legacy` priority method reproduces the previous output exactly.
	 *
	 * @since 1.3.9
	 *
	 * @param array $insight Insight payload.
	 * @return bool
	 */
	private function is_low_value_insight( $insight ) {
		$scores = isset( $insight['scores'] ) && is_array( $insight['scores'] ) ? $insight['scores'] : array();

		if ( isset( $scores['priority_method'] ) && 'rank_v2' === $scores['priority_method'] ) {
			$label_key = isset( $scores['priority_label_key'] ) ? (string) $scores['priority_label_key'] : '';
			if ( '' === $label_key && class_exists( 'Opti_Behavior_Smart_Insights_Scorer' ) ) {
				$label_key = Opti_Behavior_Smart_Insights_Scorer::normalize_label_key( isset( $scores['priority_label'] ) ? $scores['priority_label'] : '' );
			}

			if ( '' !== $label_key ) {
				return in_array( $label_key, array( 'medium', 'low' ), true );
			}
		}

		$priority = isset( $scores['priority_score'] ) ? (int) $scores['priority_score'] : 0;

		return $priority < 60;
	}

	/**
	 * Re-rank the batch on blended impact-aware priority and label by rank share.
	 *
	 * The legacy score saturates: traffic + severity + opportunity all cap out on
	 * any busy page, so a 3-conversion leak and a 193-visitor leak both land on
	 * 100/100. This pass blends the measured absolute impact into the score and
	 * assigns labels by position in the batch instead of by absolute threshold,
	 * so "Critical" keeps meaning "the worst few items in this batch".
	 *
	 * Strictly additive and fail-safe: an unknown priority method, a thrown
	 * exception, or a count mismatch returns the untouched candidate list.
	 *
	 * @since 1.3.9
	 *
	 * @param array $candidate_insights Candidate records.
	 * @return array
	 */
	private function apply_priority_ranking( $candidate_insights ) {
		if ( empty( $candidate_insights ) || ! is_array( $candidate_insights ) ) {
			return is_array( $candidate_insights ) ? $candidate_insights : array();
		}

		/**
		 * Filter the Smart Insights priority scoring method.
		 *
		 * `rank_v2` blends absolute impact into the score and labels by rank
		 * share. `legacy` skips the pass entirely and keeps the historical
		 * absolute-threshold labels.
		 *
		 * @since 1.3.9
		 *
		 * @param string $method Priority method (`rank_v2` or `legacy`).
		 */
		$method = apply_filters( 'opti_behavior_smart_insights_priority_method', 'rank_v2' );
		if ( 'rank_v2' !== $method ) {
			return $candidate_insights;
		}

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Scorer' ) ) {
			return $candidate_insights;
		}

		try {
			$ranked = $this->rank_candidates_by_blended_priority( $candidate_insights );
		} catch ( Exception $e ) {
			return $candidate_insights;
		}

		if ( ! is_array( $ranked ) || count( $ranked ) !== count( $candidate_insights ) ) {
			return $candidate_insights;
		}

		return $ranked;
	}

	/**
	 * Compute blended scores, ranks, percentiles and rank-share labels.
	 *
	 * @since 1.3.9
	 *
	 * @param array $candidate_insights Candidate records.
	 * @return array
	 */
	private function rank_candidates_by_blended_priority( $candidate_insights ) {
		$sortable = array();

		foreach ( $candidate_insights as $index => $candidate ) {
			if ( empty( $candidate['insight'] ) || ! is_array( $candidate['insight'] ) ) {
				continue;
			}

			$insight    = $candidate['insight'];
			$scores     = isset( $insight['scores'] ) && is_array( $insight['scores'] ) ? $insight['scores'] : array();
			$raw        = isset( $scores['priority_score'] ) ? (float) $scores['priority_score'] : 0.0;
			$confidence = isset( $scores['confidence_score'] ) ? (float) $scores['confidence_score'] : 0.0;
			$has_impact = isset( $scores['impact']['impact_score'] ) && is_numeric( $scores['impact']['impact_score'] );

			if ( $has_impact ) {
				$blended = round( ( 0.60 * (float) $scores['impact']['impact_score'] ) + ( 0.30 * $raw ) + ( 0.10 * $confidence ) );
			} else {
				// Never let "no impact data" outrank a measured loss.
				$blended = min( 59, round( ( 0.70 * $raw ) + ( 0.30 * $confidence ) ) );
			}

			$revenue = isset( $insight['impact']['revenue']['amount'] ) && is_numeric( $insight['impact']['revenue']['amount'] )
				? (float) $insight['impact']['revenue']['amount']
				: 0.0;

			$sortable[ $index ] = array(
				'index'      => (int) $index,
				'raw'        => (int) round( $raw ),
				'blended'    => (int) min( 100, max( 0, $blended ) ),
				'users_lost' => isset( $scores['impact']['users_lost'] ) ? (int) $scores['impact']['users_lost'] : 0,
				'revenue'    => $revenue,
				'sessions'   => isset( $insight['metrics']['sessions'] ) ? (int) $insight['metrics']['sessions'] : 0,
				'sample'     => $this->resolve_observation_sample_size( $insight ),
				'signal_id'  => isset( $insight['signal_id'] ) ? (string) $insight['signal_id'] : '',
				'has_impact' => $has_impact,
			);
		}

		if ( empty( $sortable ) ) {
			return $candidate_insights;
		}

		uasort(
			$sortable,
			function( $left, $right ) {
				if ( $left['blended'] !== $right['blended'] ) {
					return $right['blended'] <=> $left['blended'];
				}
				if ( $left['users_lost'] !== $right['users_lost'] ) {
					return $right['users_lost'] <=> $left['users_lost'];
				}
				if ( $left['revenue'] !== $right['revenue'] ) {
					return $right['revenue'] <=> $left['revenue'];
				}
				if ( $left['sessions'] !== $right['sessions'] ) {
					return $right['sessions'] <=> $left['sessions'];
				}

				$signal_comparison = strcmp( $left['signal_id'], $right['signal_id'] );

				return 0 !== $signal_comparison ? $signal_comparison : ( $left['index'] <=> $right['index'] );
			}
		);

		$total          = count( $sortable );
		$critical_count = min( 3, max( 1, (int) ceil( 0.15 * $total ) ) );
		$critical_count = min( $critical_count, $total );
		$high_count     = min( $total - $critical_count, (int) round( 0.25 * $total ) );
		$medium_count   = min( $total - $critical_count - $high_count, (int) round( 0.30 * $total ) );

		$rank = 0;
		foreach ( $sortable as $index => $entry ) {
			++$rank;

			if ( $rank <= $critical_count ) {
				$label_key = 'critical';
			} elseif ( $rank <= $critical_count + $high_count ) {
				$label_key = 'high';
			} elseif ( $rank <= $critical_count + $high_count + $medium_count ) {
				$label_key = 'medium';
			} else {
				$label_key = 'low';
			}

			$insight           = $candidate_insights[ $index ]['insight'];
			$insight['scores'] = isset( $insight['scores'] ) && is_array( $insight['scores'] ) ? $insight['scores'] : array();

			// Observation gate: a leak measured on a handful of visitors is an
			// observation to confirm, never a Critical alert. It only lowers.
			$observation_only = ! $entry['has_impact'] || $entry['users_lost'] < 5 || $entry['sample'] < 30;
			if ( $observation_only && in_array( $label_key, array( 'critical', 'high' ), true ) ) {
				$label_key = 'medium';
			}

			$insight['scores']['priority_raw']        = $entry['raw'];
			$insight['scores']['priority_score']      = $entry['blended'];
			$insight['scores']['priority_label_key']  = $label_key;
			$insight['scores']['priority_label']      = Opti_Behavior_Smart_Insights_Scorer::get_label_for_key( $label_key );
			$insight['scores']['priority_rank']       = (int) $rank;
			$insight['scores']['priority_percentile'] = (int) round( ( ( $total - $rank + 1 ) / $total ) * 100 );
			$insight['scores']['priority_method']     = 'rank_v2';

			if ( $observation_only ) {
				$insight['detection'] = isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array();
				$insight['detection']['observation_only'] = true;
				// The sample the gate fired on, so the UI can name it honestly.
				$insight['detection']['observation_sample'] = (int) $entry['sample'];
			}

			$candidate_insights[ $index ]['insight'] = $insight;
		}

		return $candidate_insights;
	}

	/**
	 * Correlate candidate signals into stories between evaluation and persistence.
	 *
	 * The correlator groups signals that describe the same problem, runs the
	 * diagnostic playbook probes, and attaches the ranked-cause payload to the
	 * story primary. This step is strictly additive: any missing class, disabled
	 * filter, or thrown exception returns the untouched candidate list so
	 * generation keeps working exactly as before (same graceful-degradation
	 * contract as the data-availability guards).
	 *
	 * @since 1.3.8
	 *
	 * @param array $candidate_insights Candidate records.
	 * @param array $date_range         Date range.
	 * @param array $args               Generation args.
	 * @return array
	 */
	private function apply_correlation( $candidate_insights, $date_range, $args ) {
		if ( empty( $candidate_insights ) || ! is_array( $candidate_insights ) ) {
			return is_array( $candidate_insights ) ? $candidate_insights : array();
		}

		/**
		 * Filter whether Smart Insights correlation runs for this generation pass.
		 *
		 * @since 1.3.8
		 *
		 * @param bool  $enabled    Whether correlation runs.
		 * @param array $date_range Date range.
		 * @param array $args       Generation args.
		 */
		if ( ! apply_filters( 'opti_behavior_smart_insights_enable_correlation', true, $date_range, $args ) ) {
			return $candidate_insights;
		}

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Correlator' ) ) {
			return $candidate_insights;
		}

		try {
			$correlator = new Opti_Behavior_Smart_Insights_Correlator();
			$correlated = $correlator->correlate( $candidate_insights, $date_range, $args );
		} catch ( Exception $e ) {
			return $candidate_insights;
		}

		if ( ! is_array( $correlated ) || count( $correlated ) !== count( $candidate_insights ) ) {
			return $candidate_insights;
		}

		return $correlated;
	}

	/**
	 * Attach absolute business impact to every candidate before persistence.
	 *
	 * Impact scoring answers "how big is this leak in people, not percentages",
	 * so the insight center can rank largest-leak-first. Like correlation, the
	 * step is strictly additive: a missing class, a disabled filter, or a thrown
	 * exception returns the untouched candidate list and every existing card
	 * renders exactly as before.
	 *
	 * @since 1.3.8
	 *
	 * @param array $candidate_insights Candidate records.
	 * @param array $date_range         Date range.
	 * @param array $args               Generation args.
	 * @return array
	 */
	private function apply_impact_scoring( $candidate_insights, $date_range, $args ) {
		if ( empty( $candidate_insights ) || ! is_array( $candidate_insights ) ) {
			return is_array( $candidate_insights ) ? $candidate_insights : array();
		}

		/**
		 * Filter whether absolute-impact scoring runs for this generation pass.
		 *
		 * @since 1.3.8
		 *
		 * @param bool  $enabled    Whether impact scoring runs.
		 * @param array $date_range Date range.
		 * @param array $args       Generation args.
		 */
		if ( ! apply_filters( 'opti_behavior_smart_insights_enable_impact_scoring', true, $date_range, $args ) ) {
			return $candidate_insights;
		}

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Impact_Calculator' ) ) {
			return $candidate_insights;
		}

		try {
			$calculator = new Opti_Behavior_Smart_Insights_Impact_Calculator();
			$scored     = $calculator->apply_to_candidates( $candidate_insights, $date_range, $args );
		} catch ( Exception $e ) {
			return $candidate_insights;
		}

		if ( ! is_array( $scored ) || count( $scored ) !== count( $candidate_insights ) ) {
			return $candidate_insights;
		}

		return $scored;
	}

	/**
	 * Attach the typed evidence bundle to every candidate before persistence.
	 *
	 * Evidence refs turn the aggregate correlation result into clickable proof:
	 * every reference carries the destination-report context (page, dates,
	 * segment, spam policy) so the target report opens on the affected subset.
	 * Like correlation and impact scoring the step is strictly additive: a
	 * missing class, a disabled filter, an unaddressable scope, or a thrown
	 * exception leaves the candidate list untouched.
	 *
	 * @since 1.3.8
	 *
	 * @param array $candidate_insights Candidate records.
	 * @param array $date_range         Date range.
	 * @param array $args               Generation args.
	 * @return array
	 */
	private function apply_evidence_refs( $candidate_insights, $date_range, $args ) {
		if ( empty( $candidate_insights ) || ! is_array( $candidate_insights ) ) {
			return is_array( $candidate_insights ) ? $candidate_insights : array();
		}

		/**
		 * Filter whether typed evidence references are built for this pass.
		 *
		 * @since 1.3.8
		 *
		 * @param bool  $enabled    Whether the evidence builder runs.
		 * @param array $date_range Date range.
		 * @param array $args       Generation args.
		 */
		if ( ! apply_filters( 'opti_behavior_smart_insights_enable_evidence_refs', true, $date_range, $args ) ) {
			return $candidate_insights;
		}

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Evidence_Builder' ) ) {
			return $candidate_insights;
		}

		try {
			$builder  = new Opti_Behavior_Smart_Insights_Evidence_Builder();
			$enriched = $builder->apply_to_candidates( $candidate_insights, $date_range, $args );
		} catch ( Exception $e ) {
			return $candidate_insights;
		}

		if ( ! is_array( $enriched ) || count( $enriched ) !== count( $candidate_insights ) ) {
			return $candidate_insights;
		}

		return $enriched;
	}

	/**
	 * Add a small priority/confidence boost when the same issue persisted before.
	 *
	 * This implements historical monitoring using stored aggregate insights only.
	 * It never reads raw visitor/session payloads and keeps the boost bounded so
	 * fresh high-severity issues still rank naturally.
	 *
	 * @param array $insight    Insight payload.
	 * @param array $date_range Current date range.
	 * @return array
	 */
	private function apply_historical_persistence_scoring( $insight, $date_range ) {
		if ( ! is_array( $insight ) || ! method_exists( $this->repository, 'get_recent_related_insights' ) ) {
			return $insight;
		}

		$history = $this->repository->get_recent_related_insights(
			$insight,
			array(
				'before_date' => isset( $date_range['from'] ) ? $date_range['from'] : '',
				'limit'       => 6,
			)
		);

		if ( empty( $history ) ) {
			return $insight;
		}

		$count = count( $history );
		$max_previous_priority = 0;
		foreach ( $history as $previous ) {
			$previous_priority = isset( $previous['scores']['priority_score'] ) ? (int) $previous['scores']['priority_score'] : 0;
			$max_previous_priority = max( $max_previous_priority, $previous_priority );
		}

		$insight['scores'] = isset( $insight['scores'] ) && is_array( $insight['scores'] ) ? $insight['scores'] : array();
		$current_priority = isset( $insight['scores']['priority_score'] ) ? (int) $insight['scores']['priority_score'] : 0;
		$persistence_score = min( 10, 4 + ( $count * 2 ) );
		$new_priority = min( 100, $current_priority + $persistence_score );

		$scorer = class_exists( 'Opti_Behavior_Smart_Insights_Scorer' ) ? new Opti_Behavior_Smart_Insights_Scorer() : null;
		$insight['scores']['persistence_score'] = (int) $persistence_score;
		$insight['scores']['historical_detections'] = (int) $count;
		$insight['scores']['priority_score_before_persistence'] = $current_priority;
		$insight['scores']['priority_score'] = $new_priority;

		// Rank-based labels describe a position in the batch, not an absolute
		// score, so the persistence boost must never re-derive them from the
		// legacy thresholds (that would clobber the rank label and the
		// observation cap it already carries).
		$is_rank_v2 = isset( $insight['scores']['priority_method'] ) && 'rank_v2' === $insight['scores']['priority_method'];
		if ( $scorer && ! $is_rank_v2 && method_exists( $scorer, 'get_priority_label' ) ) {
			$insight['scores']['priority_label'] = $scorer->get_priority_label( $new_priority );
		}

		if ( isset( $insight['scores']['confidence_score'] ) ) {
			$new_confidence = min( 100, (int) $insight['scores']['confidence_score'] + min( 10, $count * 3 ) );
			$insight['scores']['confidence_score'] = $new_confidence;
			if ( $scorer && method_exists( $scorer, 'get_confidence_label' ) ) {
				$insight['scores']['confidence_label'] = $scorer->get_confidence_label( $new_confidence );
			}
		}

		$insight['detection'] = isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array();
		$insight['detection']['same_issue_previous_period'] = true;
		$insight['detection']['historical_detections'] = (int) $count;
		if ( $new_priority > $max_previous_priority && $max_previous_priority > 0 ) {
			$insight['detection']['worsened'] = true;
			$insight['detection']['previous_priority_score'] = $max_previous_priority;
		}

		$insight['trend'] = isset( $insight['trend'] ) && is_array( $insight['trend'] ) ? $insight['trend'] : array();
		$insight['trend']['historical_persistence'] = array(
			'previous_detections' => (int) $count,
			'priority_boost'      => (int) $persistence_score,
			'max_previous_priority_score' => (int) $max_previous_priority,
		);

		return $insight;
	}

	/**
	 * Resolve how many observations back an insight, for the observation gate.
	 *
	 * Page, source and segment entities are measured in sessions. Form and
	 * funnel entities are not: their metric denominators are form starts and
	 * funnel entries, and their `sessions` metric stays at zero. Falling back to
	 * those denominators keeps a measured 85-abandonment leak out of the
	 * "too little traffic to size it" bucket.
	 *
	 * @since 1.3.9
	 *
	 * @param array $insight Insight payload.
	 * @return int
	 */
	private function resolve_observation_sample_size( $insight ) {
		$metrics = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();

		foreach ( array( 'sessions', 'starts', 'entries', 'pageviews' ) as $key ) {
			if ( isset( $metrics[ $key ] ) && is_numeric( $metrics[ $key ] ) && (int) $metrics[ $key ] > 0 ) {
				return (int) $metrics[ $key ];
			}
		}

		return 0;
	}

	/**
	 * Build a generation lock transient key.
	 *
	 * @param string $from Start date.
	 * @param string $to   End date.
	 * @return string
	 */
	private function get_lock_key( $from, $to ) {
		return 'opti_behavior_si_lock_' . md5( $from . '|' . $to );
	}

	/**
	 * Calculate cutoff used for conservative auto-resolution.
	 *
	 * @param string $from Start date.
	 * @param string $to   End date.
	 * @return string MySQL datetime.
	 */
	private function get_auto_resolve_cutoff( $from, $to ) {
		$from_ts = strtotime( $from );
		$to_ts   = strtotime( $to );
		$days    = max( 1, (int) floor( ( $to_ts - $from_ts ) / DAY_IN_SECONDS ) + 1 );
		$cutoff  = strtotime( $from . ' -' . $days . ' days' );

		return gmdate( 'Y-m-d 00:00:00', $cutoff );
	}
}

