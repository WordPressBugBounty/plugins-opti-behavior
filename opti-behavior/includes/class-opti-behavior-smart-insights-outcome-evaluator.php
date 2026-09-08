<?php
/**
 * Smart Insights outcome evaluator ("did the fix work?").
 *
 * Answers the third story question for one insight: after the client marked a
 * problem resolved, did the metric that defined the problem actually move?
 *
 * Two halves, written at two different moments:
 *
 * 1. the "before" snapshot, frozen by the repository the moment the status
 *    becomes `resolved` (the insight row itself keeps being refreshed by
 *    generation runs, so the value must be captured, not looked up later);
 * 2. the "after" measurement, computed by a daily cron once a full post-fix
 *    window exists, by re-running the very same signal on the very same entity
 *    through the generator's read-only re-evaluation path.
 *
 * Only aggregates are stored: two metric values, two session counts, two date
 * ranges and a verdict. No visitor-level data ever reaches this class.
 *
 * @package opti-behavior
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Outcome_Evaluator' ) ) {

	/**
	 * Before/after outcome evaluator for resolved Smart Insights.
	 *
	 * @since 1.4.0
	 */
	class Opti_Behavior_Smart_Insights_Outcome_Evaluator {

		/**
		 * Payload schema version.
		 */
		const SCHEMA_VERSION = 1;

		/**
		 * Length in days of the post-fix measurement window.
		 */
		const WINDOW_DAYS = 14;

		/**
		 * Minimum age in days of `resolved_at` before a row is measured.
		 */
		const MIN_AGE_DAYS = 14;

		/**
		 * Maximum age in days of `resolved_at` still picked up by the cron.
		 */
		const MAX_AGE_DAYS = 20;

		/**
		 * Rows measured per cron run.
		 */
		const BATCH_LIMIT = 25;

		/**
		 * Minimum sessions in the post-fix window before a verdict is possible.
		 */
		const MIN_AFTER_SESSIONS = 30;

		/**
		 * Minimum relative movement before the change is called a change.
		 */
		const MIN_RELATIVE_CHANGE = 0.10;

		/**
		 * Two-sided significance threshold (large-sample normal approximation).
		 */
		const SIGNIFICANCE_Z = 1.96;

		/**
		 * Insight repository.
		 *
		 * @var Opti_Behavior_Smart_Insights_Repository
		 */
		private $repository;

		/**
		 * Smart Insights generator (read-only re-evaluation).
		 *
		 * @var object|null
		 */
		private $generator;

		/**
		 * Constructor.
		 *
		 * @param Opti_Behavior_Smart_Insights_Repository|null $repository Repository override.
		 * @param object|null                                  $generator  Generator override.
		 */
		public function __construct( $repository = null, $generator = null ) {
			$this->repository = $repository instanceof Opti_Behavior_Smart_Insights_Repository
				? $repository
				: new Opti_Behavior_Smart_Insights_Repository();
			$this->generator  = is_object( $generator ) ? $generator : null;
		}

		/**
		 * Metric definitions the outcome loop can measure.
		 *
		 * Mirrors the segment matrix definitions (same keys, same direction) and
		 * adds the insight-row fields each metric is read from, because the stored
		 * page metrics use longer names than the matrix aggregates.
		 *
		 * Rate metrics carry a successes/trials pair (two-proportion z). Average
		 * metrics carry a samples/sum/sum-of-squares triplet (Welch t).
		 *
		 * @return array
		 */
		public static function get_metric_definitions() {
			return array(
				'bounce_rate'    => array(
					'label'           => __( 'Bounce rate', 'opti-behavior' ),
					'type'            => 'rate',
					'unit'            => 'percent',
					'higher_is_worse' => true,
					'value_key'       => 'bounce_rate',
					'numerator_key'   => 'bounce_sessions',
					'denominator_key' => 'sessions',
					'samples_key'     => '',
					'sum_key'         => '',
					'sum_squares_key' => '',
				),
				'exit_rate'      => array(
					'label'           => __( 'Exit rate', 'opti-behavior' ),
					'type'            => 'rate',
					'unit'            => 'percent',
					'higher_is_worse' => true,
					'value_key'       => 'exit_rate',
					'numerator_key'   => 'exit_sessions',
					'denominator_key' => 'pageviews',
					'samples_key'     => '',
					'sum_key'         => '',
					'sum_squares_key' => '',
				),
				'cta_click_rate' => array(
					'label'           => __( 'CTA click rate', 'opti-behavior' ),
					'type'            => 'rate',
					'unit'            => 'percent',
					'higher_is_worse' => false,
					'value_key'       => 'cta_click_rate',
					'numerator_key'   => '',
					'denominator_key' => 'sessions',
					'samples_key'     => '',
					'sum_key'         => '',
					'sum_squares_key' => '',
				),
				'avg_scroll'     => array(
					'label'           => __( 'Average scroll depth', 'opti-behavior' ),
					'type'            => 'average',
					'unit'            => 'percent',
					'higher_is_worse' => false,
					'value_key'       => 'avg_scroll_depth',
					'numerator_key'   => '',
					'denominator_key' => 'scroll_depth_samples',
					'samples_key'     => 'scroll_depth_samples',
					'sum_key'         => 'scroll_depth_sum',
					'sum_squares_key' => 'scroll_depth_sum_squares',
				),
				'avg_time'       => array(
					'label'           => __( 'Average time on page', 'opti-behavior' ),
					'type'            => 'average',
					'unit'            => 'seconds',
					'higher_is_worse' => false,
					'value_key'       => 'avg_time_on_page',
					'numerator_key'   => '',
					'denominator_key' => 'time_on_page_samples',
					'samples_key'     => 'time_on_page_samples',
					'sum_key'         => 'time_on_page_sum',
					'sum_squares_key' => 'time_on_page_sum_squares',
				),
			);
		}

		/**
		 * Resolve one metric definition, falling back to bounce rate.
		 *
		 * @param string $metric Metric key.
		 * @return array
		 */
		public static function get_metric_definition( $metric ) {
			$definitions = self::get_metric_definitions();
			$metric      = sanitize_key( (string) $metric );

			return isset( $definitions[ $metric ] ) ? $definitions[ $metric ] : $definitions['bounce_rate'];
		}

		/**
		 * Metric key that defines the problem for one insight.
		 *
		 * Reuses the segment matrix resolver so "where is the problem" and "did
		 * the fix work" always talk about the same number. Falls back to the
		 * bounce rate when the matrix class is unavailable.
		 *
		 * @param array $insight Insight payload.
		 * @return string
		 */
		public static function resolve_metric_key( $insight ) {
			$metric = '';

			if ( class_exists( 'Opti_Behavior_Smart_Insights_Segment_Matrix' ) ) {
				try {
					$matrix = new Opti_Behavior_Smart_Insights_Segment_Matrix();
					if ( method_exists( $matrix, 'resolve_primary_metric' ) ) {
						$metric = (string) $matrix->resolve_primary_metric( $insight );
					}
				} catch ( Exception $e ) {
					$metric = '';
				} catch ( Error $e ) {
					$metric = '';
				}
			}

			$definitions = self::get_metric_definitions();
			if ( '' === $metric || ! isset( $definitions[ $metric ] ) ) {
				$metric = 'bounce_rate';
			}

			/**
			 * Filter the metric the outcome loop measures before and after a fix.
			 *
			 * @since 1.4.0
			 *
			 * @param string $metric  Metric key.
			 * @param array  $insight Insight payload.
			 */
			$metric = (string) apply_filters( 'opti_behavior_smart_insights_outcome_metric', $metric, $insight );

			return isset( $definitions[ $metric ] ) ? $metric : 'bounce_rate';
		}

		/**
		 * Read one metric value out of a stored metrics map.
		 *
		 * @param array  $metrics Metrics map.
		 * @param string $metric  Metric key.
		 * @return float|null
		 */
		public static function get_metric_value( $metrics, $metric ) {
			$definition = self::get_metric_definition( $metric );
			$metrics    = is_array( $metrics ) ? $metrics : array();
			$key        = $definition['value_key'];

			if ( ! isset( $metrics[ $key ] ) || null === $metrics[ $key ] || '' === $metrics[ $key ] || ! is_numeric( $metrics[ $key ] ) ) {
				return null;
			}

			return round( (float) $metrics[ $key ], 2 );
		}

		/**
		 * Sessions the metric was measured on.
		 *
		 * @param array $metrics Metrics map.
		 * @return int
		 */
		public static function get_sessions( $metrics ) {
			$metrics = is_array( $metrics ) ? $metrics : array();

			return isset( $metrics['sessions'] ) ? max( 0, (int) $metrics['sessions'] ) : 0;
		}

		/**
		 * Successes/trials pair backing a rate metric, for the z statistic.
		 *
		 * Rates read their real numerator and denominator when the aggregator
		 * stored them, and fall back to value x trials otherwise. Average metrics
		 * are not proportions and return null here; they are tested with a Welch t
		 * over the dispersion triplet instead (see get_metric_dispersion()).
		 *
		 * @param array  $metrics Metrics map.
		 * @param string $metric  Metric key.
		 * @return array|null `array( successes, trials )` or null.
		 */
		public static function get_metric_sample( $metrics, $metric ) {
			$definition = self::get_metric_definition( $metric );
			$metrics    = is_array( $metrics ) ? $metrics : array();

			if ( 'rate' !== $definition['type'] ) {
				return null;
			}

			$value = self::get_metric_value( $metrics, $metric );
			if ( null === $value ) {
				return null;
			}

			$denominator_key = $definition['denominator_key'];
			$trials          = isset( $metrics[ $denominator_key ] ) ? (float) $metrics[ $denominator_key ] : 0.0;
			if ( $trials <= 0 ) {
				$trials = (float) self::get_sessions( $metrics );
			}
			if ( $trials <= 0 ) {
				return null;
			}

			$numerator_key = $definition['numerator_key'];
			if ( '' !== $numerator_key && isset( $metrics[ $numerator_key ] ) && is_numeric( $metrics[ $numerator_key ] ) ) {
				$successes = (float) $metrics[ $numerator_key ];
			} else {
				$successes = ( $value / 100 ) * $trials;
			}

			$successes = max( 0.0, min( $trials, $successes ) );

			return array( $successes, $trials );
		}

		/**
		 * Samples/sum/sum-of-squares triplet backing an average metric.
		 *
		 * The page metric aggregator emits the sum and the sum of squares over the
		 * exact samples the average was computed on, which is everything a Welch t
		 * needs. Rows aggregated before that existed (and every non-page entity
		 * type, whose aggregators carry no dispersion) return null, and the verdict
		 * falls back to the relative-change rule flagged as `relative_only`.
		 *
		 * @since 1.4.0
		 *
		 * @param array  $metrics Metrics map.
		 * @param string $metric  Metric key.
		 * @return array|null `array( n, sum, sum_of_squares )` or null.
		 */
		public static function get_metric_dispersion( $metrics, $metric ) {
			$definition = self::get_metric_definition( $metric );
			$metrics    = is_array( $metrics ) ? $metrics : array();

			if ( 'average' !== $definition['type'] || '' === $definition['sum_key'] ) {
				return null;
			}

			foreach ( array( 'samples_key', 'sum_key', 'sum_squares_key' ) as $slot ) {
				$key = $definition[ $slot ];
				if ( '' === $key || ! isset( $metrics[ $key ] ) || null === $metrics[ $key ] || ! is_numeric( $metrics[ $key ] ) ) {
					return null;
				}
			}

			$n = (float) $metrics[ $definition['samples_key'] ];
			if ( $n < 2 ) {
				// A Welch t needs at least two samples per side to have a variance.
				return null;
			}

			return array(
				$n,
				(float) $metrics[ $definition['sum_key'] ],
				(float) $metrics[ $definition['sum_squares_key'] ],
			);
		}

		/**
		 * Welch t statistic from sums, sums of squares, and counts.
		 *
		 * Unequal-variance two-sample t. Compared against the same 1.96 threshold
		 * as the z statistic (large-sample normal approximation), exactly like the
		 * segment matrix does, because both post-fix windows are gated at 30
		 * sessions before a verdict is even attempted.
		 *
		 * @since 1.4.0
		 *
		 * @param float $sum1 Sum group 1.
		 * @param float $sq1  Sum of squares group 1.
		 * @param float $n1   Count group 1.
		 * @param float $sum2 Sum group 2.
		 * @param float $sq2  Sum of squares group 2.
		 * @param float $n2   Count group 2.
		 * @return float|null
		 */
		public static function welch_t( $sum1, $sq1, $n1, $sum2, $sq2, $n2 ) {
			if ( $n1 < 2 || $n2 < 2 ) {
				return null;
			}

			$mean1 = $sum1 / $n1;
			$mean2 = $sum2 / $n2;
			$var1  = max( 0, ( $sq1 - ( ( $sum1 * $sum1 ) / $n1 ) ) / ( $n1 - 1 ) );
			$var2  = max( 0, ( $sq2 - ( ( $sum2 * $sum2 ) / $n2 ) ) / ( $n2 - 1 ) );
			$se    = sqrt( ( $var1 / $n1 ) + ( $var2 / $n2 ) );

			if ( $se <= 0 ) {
				return null;
			}

			return round( ( $mean1 - $mean2 ) / $se, 4 );
		}

		/**
		 * Build the "before" snapshot for a freshly resolved insight.
		 *
		 * @param array $insight Decoded insight row.
		 * @return array Empty array when nothing measurable exists.
		 */
		public static function build_snapshot( $insight ) {
			if ( ! is_array( $insight ) || empty( $insight['signal_id'] ) ) {
				return array();
			}

			$metric     = self::resolve_metric_key( $insight );
			$definition = self::get_metric_definition( $metric );
			$metrics    = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();

			$snapshot = array(
				'schema_version'  => self::SCHEMA_VERSION,
				'metric_key'      => $metric,
				'unit'            => $definition['unit'],
				'higher_is_worse' => (bool) $definition['higher_is_worse'],
				'before_value'    => self::get_metric_value( $metrics, $metric ),
				'before_sessions' => self::get_sessions( $metrics ),
				'before_range'    => array(
					'from' => isset( $insight['date_from'] ) ? (string) $insight['date_from'] : '',
					'to'   => isset( $insight['date_to'] ) ? (string) $insight['date_to'] : '',
				),
				'resolved_at'     => isset( $insight['resolved_at'] ) ? (string) $insight['resolved_at'] : '',
				'snapshot_at'     => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			);

			$sample = self::get_metric_sample( $metrics, $metric );
			if ( is_array( $sample ) ) {
				$snapshot['before_successes'] = round( $sample[0], 4 );
				$snapshot['before_trials']    = round( $sample[1], 4 );
			}

			// Average metrics freeze their dispersion here: the insight row keeps
			// being refreshed by generation runs, so the sums that made the "before"
			// mean must be captured at resolution time, exactly like the mean is.
			$dispersion = self::get_metric_dispersion( $metrics, $metric );
			if ( is_array( $dispersion ) ) {
				$snapshot['before_n']           = round( $dispersion[0], 4 );
				$snapshot['before_sum']         = round( $dispersion[1], 4 );
				$snapshot['before_sum_squares'] = round( $dispersion[2], 4 );
			}

			return $snapshot;
		}

		/**
		 * Add read-time presentation fields to a stored outcome payload.
		 *
		 * The metric label is resolved here and never stored, so a row written by
		 * a cron run in one locale still renders in the reader's locale.
		 *
		 * @param array $outcome Stored outcome payload.
		 * @return array
		 */
		public static function decorate( $outcome ) {
			if ( ! is_array( $outcome ) || empty( $outcome ) ) {
				return array();
			}

			$definition              = self::get_metric_definition( isset( $outcome['metric_key'] ) ? $outcome['metric_key'] : '' );
			$outcome['metric_label'] = $definition['label'];
			$outcome['unit']         = isset( $outcome['unit'] ) ? $outcome['unit'] : $definition['unit'];

			// A snapshot has no verdict yet, and decoration must not invent one:
			// an empty `verdict` key would make the row look already measured.
			if ( ! empty( $outcome['verdict'] ) ) {
				$outcome['verdict']       = sanitize_key( $outcome['verdict'] );
				$outcome['verdict_label'] = self::get_verdict_label( $outcome['verdict'] );
			}

			return $outcome;
		}

		/**
		 * Human label for one verdict.
		 *
		 * @param string $verdict Verdict key.
		 * @return string
		 */
		public static function get_verdict_label( $verdict ) {
			switch ( sanitize_key( (string) $verdict ) ) {
				case 'improved':
					return __( 'Improved', 'opti-behavior' );
				case 'worse':
					return __( 'Worse after the fix', 'opti-behavior' );
				case 'no_change':
					return __( 'No measurable change yet', 'opti-behavior' );
				case 'inconclusive':
					return __( 'Not measurable', 'opti-behavior' );
				default:
					return '';
			}
		}

		/**
		 * Post-fix measurement window for one resolved insight.
		 *
		 * @param string $resolved_at Resolution datetime.
		 * @return array|null `array( from, to )` or null when unusable.
		 */
		public static function get_after_range( $resolved_at ) {
			$resolved_at = is_scalar( $resolved_at ) ? trim( (string) $resolved_at ) : '';
			$timestamp   = '' !== $resolved_at ? strtotime( $resolved_at ) : false;
			if ( ! $timestamp ) {
				return null;
			}

			return array(
				'from' => gmdate( 'Y-m-d', $timestamp + DAY_IN_SECONDS ),
				'to'   => gmdate( 'Y-m-d', $timestamp + ( self::WINDOW_DAYS * DAY_IN_SECONDS ) ),
			);
		}

		/**
		 * Spam-exclusion policy the insight was generated under.
		 *
		 * Group keys carry the scope suffix, so the post-fix re-evaluation runs
		 * under the exact policy that produced the "before" number.
		 *
		 * @param array $insight Insight payload.
		 * @return bool|null
		 */
		public static function resolve_exclude_spam( $insight ) {
			$group_key = isset( $insight['group_key'] ) ? (string) $insight['group_key'] : '';
			if ( false !== strpos( $group_key, 'si_spam_scope:exclude_spam_1' ) ) {
				return true;
			}
			if ( false !== strpos( $group_key, 'si_spam_scope:exclude_spam_0' ) ) {
				return false;
			}

			return class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? (bool) Opti_Behavior_Stats_Spam_Filter::is_enabled() : null;
		}

		/**
		 * Two-proportion z statistic.
		 *
		 * @param float $x1 Successes in group 1.
		 * @param float $n1 Trials in group 1.
		 * @param float $x2 Successes in group 2.
		 * @param float $n2 Trials in group 2.
		 * @return float|null
		 */
		public static function two_proportion_z( $x1, $n1, $x2, $n2 ) {
			if ( $n1 <= 0 || $n2 <= 0 ) {
				return null;
			}

			$pooled = ( $x1 + $x2 ) / ( $n1 + $n2 );
			$se     = sqrt( $pooled * ( 1 - $pooled ) * ( ( 1 / $n1 ) + ( 1 / $n2 ) ) );
			if ( $se <= 0 ) {
				return null;
			}

			return round( ( ( $x1 / $n1 ) - ( $x2 / $n2 ) ) / $se, 4 );
		}

		/**
		 * Turn a before/after pair into a stored outcome payload.
		 *
		 * @param array      $outcome          Existing outcome (carries the snapshot).
		 * @param float|null $after_value      Post-fix metric value.
		 * @param int        $after_sessions   Post-fix sessions.
		 * @param array      $after_range      Post-fix date range.
		 * @param array|null $after_sample     Post-fix successes/trials pair (rates).
		 * @param array|null $after_dispersion Post-fix n/sum/sum-of-squares (averages).
		 * @return array
		 */
		public static function build_verdict( $outcome, $after_value, $after_sessions, $after_range, $after_sample = null, $after_dispersion = null ) {
			$outcome                = is_array( $outcome ) ? $outcome : array();
			$outcome['after_range'] = array(
				'from' => isset( $after_range['from'] ) ? (string) $after_range['from'] : '',
				'to'   => isset( $after_range['to'] ) ? (string) $after_range['to'] : '',
			);
			$outcome['checked_at']  = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

			$before = isset( $outcome['before_value'] ) && is_numeric( $outcome['before_value'] ) ? (float) $outcome['before_value'] : null;
			if ( null === $before ) {
				return self::mark_inconclusive( $outcome, 'no_before_value' );
			}

			if ( null === $after_value ) {
				return self::mark_inconclusive( $outcome, 'no_after_value' );
			}

			$outcome['after_value']    = round( (float) $after_value, 2 );
			$outcome['after_sessions'] = max( 0, (int) $after_sessions );

			if ( $outcome['after_sessions'] < self::MIN_AFTER_SESSIONS ) {
				return self::mark_inconclusive( $outcome, 'low_after_traffic' );
			}

			$delta_abs = round( $outcome['after_value'] - $before, 2 );
			$delta_rel = 0.0 !== (float) $before ? round( $delta_abs / abs( $before ), 4 ) : null;

			$outcome['delta_abs'] = $delta_abs;
			$outcome['delta_rel'] = $delta_rel;

			// Rates get a two-proportion z, averages a Welch t on the frozen
			// dispersion. Whichever ran is reported through `significance` so the
			// report never implies a test that was not performed.
			$z    = null;
			$test = 'relative_only';

			if ( is_array( $after_sample ) && isset( $outcome['before_successes'], $outcome['before_trials'] ) ) {
				$z = self::two_proportion_z(
					(float) $after_sample[0],
					(float) $after_sample[1],
					(float) $outcome['before_successes'],
					(float) $outcome['before_trials']
				);
				if ( null !== $z ) {
					$test = 'z';
				}
			} elseif ( is_array( $after_dispersion ) && isset( $outcome['before_n'], $outcome['before_sum'], $outcome['before_sum_squares'] ) ) {
				$z = self::welch_t(
					(float) $after_dispersion[1],
					(float) $after_dispersion[2],
					(float) $after_dispersion[0],
					(float) $outcome['before_sum'],
					(float) $outcome['before_sum_squares'],
					(float) $outcome['before_n']
				);
				if ( null !== $z ) {
					$test = 'welch_t';
				}
			}

			$outcome['z']            = $z;
			$outcome['significance'] = $test;

			$magnitude_ok = null !== $delta_rel && abs( $delta_rel ) >= self::MIN_RELATIVE_CHANGE;
			// Legacy rows snapshotted before dispersion was stored (and entity types
			// whose aggregators carry none) have no statistic at all; the relative
			// rule then decides alone and `significance` says `relative_only`.
			$significant  = null === $z ? true : abs( $z ) >= self::SIGNIFICANCE_Z;

			if ( ! $magnitude_ok || ! $significant ) {
				$outcome['verdict'] = 'no_change';
				$outcome['reason']  = '';

				return $outcome;
			}

			$higher_is_worse = ! empty( $outcome['higher_is_worse'] );
			$moved_well      = $higher_is_worse ? ( $delta_abs < 0 ) : ( $delta_abs > 0 );

			$outcome['verdict'] = $moved_well ? 'improved' : 'worse';
			$outcome['reason']  = '';

			return $outcome;
		}

		/**
		 * Mark an outcome as not measurable.
		 *
		 * @param array  $outcome Outcome payload.
		 * @param string $reason  Machine reason.
		 * @return array
		 */
		public static function mark_inconclusive( $outcome, $reason ) {
			$outcome            = is_array( $outcome ) ? $outcome : array();
			$outcome['verdict'] = 'inconclusive';
			$outcome['reason']  = sanitize_key( (string) $reason );

			return $outcome;
		}

		/**
		 * Measure one resolved insight and persist its verdict.
		 *
		 * @param array $insight Decoded insight row.
		 * @return array|null Stored outcome payload, or null when nothing was written.
		 */
		public function evaluate_insight( $insight ) {
			if ( ! is_array( $insight ) || empty( $insight['id'] ) ) {
				return null;
			}

			$outcome = isset( $insight['outcome'] ) && is_array( $insight['outcome'] ) ? $insight['outcome'] : array();
			unset( $outcome['metric_label'], $outcome['verdict_label'] );

			if ( empty( $outcome['metric_key'] ) ) {
				// Resolved before the outcome loop existed: rebuild what can still
				// be rebuilt from the stored row, then measure.
				$outcome = array_merge( self::build_snapshot( $insight ), $outcome );
			}

			$after_range = self::get_after_range( isset( $outcome['resolved_at'] ) && '' !== $outcome['resolved_at'] ? $outcome['resolved_at'] : ( isset( $insight['resolved_at'] ) ? $insight['resolved_at'] : '' ) );
			if ( null === $after_range ) {
				return $this->persist( $insight['id'], self::mark_inconclusive( $outcome, 'no_resolved_at' ) );
			}

			$generator = $this->get_generator();
			if ( ! is_object( $generator ) || ! method_exists( $generator, 'evaluate_signal_for_entity' ) ) {
				return $this->persist( $insight['id'], self::mark_inconclusive( $outcome, 'generator_unavailable' ) );
			}

			$metric = isset( $outcome['metric_key'] ) ? (string) $outcome['metric_key'] : self::resolve_metric_key( $insight );

			try {
				$evaluation = $generator->evaluate_signal_for_entity(
					isset( $insight['signal_id'] ) ? $insight['signal_id'] : '',
					isset( $insight['entity_type'] ) ? $insight['entity_type'] : '',
					isset( $insight['entity_id'] ) ? $insight['entity_id'] : '',
					$after_range['from'],
					$after_range['to'],
					array(
						'exclude_spam' => self::resolve_exclude_spam( $insight ),
						'source'       => 'outcome_check',
					)
				);
			} catch ( Exception $e ) {
				return $this->persist( $insight['id'], self::mark_inconclusive( $outcome, 'evaluation_failed' ) );
			} catch ( Error $e ) {
				return $this->persist( $insight['id'], self::mark_inconclusive( $outcome, 'evaluation_failed' ) );
			}

			if ( ! is_array( $evaluation ) || empty( $evaluation['available'] ) ) {
				$reason = is_array( $evaluation ) && ! empty( $evaluation['reason'] ) ? $evaluation['reason'] : 'entity_unavailable';

				return $this->persist( $insight['id'], self::mark_inconclusive( $outcome, $reason ) );
			}

			$after_metrics = isset( $evaluation['metrics'] ) && is_array( $evaluation['metrics'] ) ? $evaluation['metrics'] : array();
			$outcome       = self::build_verdict(
				$outcome,
				self::get_metric_value( $after_metrics, $metric ),
				self::get_sessions( $after_metrics ),
				$after_range,
				self::get_metric_sample( $after_metrics, $metric ),
				self::get_metric_dispersion( $after_metrics, $metric )
			);

			// The signal firing again on the post-fix window is itself evidence the
			// problem is back; keep it next to the numbers for the report.
			$outcome['still_triggered'] = ! empty( $evaluation['triggered'] );

			return $this->persist( $insight['id'], $outcome );
		}

		/**
		 * Run one bounded outcome-check batch.
		 *
		 * @param array $args Optional overrides (`limit`, `min_days`, `max_days`).
		 * @return array Run summary.
		 */
		public function run_check( $args = array() ) {
			$defaults = array(
				'limit'    => self::BATCH_LIMIT,
				'min_days' => self::MIN_AGE_DAYS,
				'max_days' => self::MAX_AGE_DAYS,
			);
			$args     = wp_parse_args( $args, $defaults );

			$summary = array(
				'checked'      => 0,
				'improved'     => 0,
				'worse'        => 0,
				'no_change'    => 0,
				'inconclusive' => 0,
				'skipped'      => 0,
			);

			if ( ! method_exists( $this->repository, 'get_outcome_check_candidates' ) ) {
				$summary['skipped'] = 1;

				return $summary;
			}

			try {
				$candidates = $this->repository->get_outcome_check_candidates(
					absint( $args['min_days'] ),
					absint( $args['max_days'] ),
					max( 1, min( 100, absint( $args['limit'] ) ) )
				);
			} catch ( Exception $e ) {
				$summary['skipped'] = 1;

				return $summary;
			} catch ( Error $e ) {
				$summary['skipped'] = 1;

				return $summary;
			}

			foreach ( (array) $candidates as $insight ) {
				$outcome = $this->evaluate_insight( $insight );
				if ( ! is_array( $outcome ) || empty( $outcome['verdict'] ) ) {
					++$summary['skipped'];
					continue;
				}

				++$summary['checked'];
				if ( isset( $summary[ $outcome['verdict'] ] ) ) {
					++$summary[ $outcome['verdict'] ];
				}
			}

			return $summary;
		}

		/**
		 * Persist one outcome payload.
		 *
		 * @param int   $id      Insight ID.
		 * @param array $outcome Outcome payload.
		 * @return array|null
		 */
		private function persist( $id, $outcome ) {
			if ( ! is_array( $outcome ) || empty( $outcome ) || ! method_exists( $this->repository, 'update_outcome' ) ) {
				return null;
			}

			$result = $this->repository->update_outcome( $id, $outcome );

			return is_wp_error( $result ) ? null : $outcome;
		}

		/**
		 * Lazily resolve the generator used for the post-fix re-evaluation.
		 *
		 * @return object|null
		 */
		private function get_generator() {
			if ( is_object( $this->generator ) ) {
				return $this->generator;
			}

			/**
			 * Filter the generator instance used by the outcome loop.
			 *
			 * @since 1.4.0
			 *
			 * @param object|null $generator Generator instance.
			 */
			$generator = apply_filters( 'opti_behavior_smart_insights_outcome_generator', null );
			if ( is_object( $generator ) ) {
				$this->generator = $generator;

				return $this->generator;
			}

			if ( class_exists( 'Opti_Behavior_Smart_Insights_Generator' ) ) {
				try {
					$this->generator = new Opti_Behavior_Smart_Insights_Generator();
				} catch ( Exception $e ) {
					$this->generator = null;
				} catch ( Error $e ) {
					$this->generator = null;
				}
			}

			return $this->generator;
		}
	}
}
