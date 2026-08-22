<?php
/**
 * A/B Test Frequentist Statistical Engine
 *
 * Implements two-proportion Z-test, confidence intervals, sample size calculator,
 * significance detection, and sequential testing with alpha-spending.
 *
 * @package opti-behavior
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opti_Behavior_AB_Test_Engine {

	/**
	 * Default confidence level (95%).
	 *
	 * @var float
	 */
	const DEFAULT_CONFIDENCE = 0.95;

	/**
	 * Calculate complete test results for all variants.
	 *
	 * @since 1.3.0
	 * @param array $variant_stats Array of objects with: variant_id, impressions/unique_visitors, conversions, revenue, is_control.
	 * @param float $confidence_level Desired confidence level (0-1). Default 0.95.
	 * @return array Enriched results with statistical calculations.
	 */
	public static function calculate_results( $variant_stats, $confidence_level = null ) {
		if ( null === $confidence_level ) {
			$confidence_level = self::DEFAULT_CONFIDENCE;
		}

		$confidence_level = max( 0.80, min( 0.99, floatval( $confidence_level ) ) );

		// Find the control variant.
		$control = null;
		foreach ( $variant_stats as $stat ) {
			if ( ! empty( $stat->is_control ) ) {
				$control = $stat;
				break;
			}
		}

		// If no control found, use first variant.
		if ( null === $control && ! empty( $variant_stats ) ) {
			$control = $variant_stats[0];
		}

		if ( null === $control ) {
			return array();
		}

		$control_visitors_raw = (int) $control->unique_visitors;
		$control_visitors     = max( 1, $control_visitors_raw ); // Avoid division by zero.
		// Defensive clamp — a conversion rate is conversions / unique_visitors, which
		// physically cannot exceed 1.0. Legacy rows inserted before DB_VERSION 1.2.1
		// (when UNIQUE (test_id, goal_id, visitor_id) was added) could violate that
		// invariant if the SELECT-then-INSERT race ever fired. Clamp here so the
		// displayed rate, lift, and improvement stay in [0, 100%]. The sister methods
		// two_proportion_z_test() and wilson_confidence_interval() already clamp.
		$control_conversions  = max( 0, min( (int) $control->conversions, $control_visitors_raw ) );
		$control_rate         = $control_conversions / $control_visitors;

		$results = array();

		foreach ( $variant_stats as $stat ) {
			$visitors_raw = (int) $stat->unique_visitors;
			$visitors     = max( 1, $visitors_raw ); // Avoid division by zero.
			// Same defensive clamp — see comment on $control_conversions above.
			$conversions  = max( 0, min( (int) $stat->conversions, $visitors_raw ) );
			$rate         = $conversions / $visitors;

			$result = new \stdClass();
			$result->variant_id     = (int) $stat->variant_id;
			$result->variant_name   = isset( $stat->variant_name ) ? $stat->variant_name : '';
			$result->is_control     = ! empty( $stat->is_control );
			$result->variant_data   = isset( $stat->variant_data ) ? $stat->variant_data : '{}';
			$result->visitors       = $visitors_raw; // Display the real count (not the max-1 guard).
			$result->conversions    = $conversions;
			$result->conversion_rate = $rate;
			$result->revenue        = isset( $stat->revenue ) ? floatval( $stat->revenue ) : 0;
			$result->revenue_per_visitor = $visitors > 0 ? $result->revenue / $visitors : 0;

			// Confidence interval for this variant's conversion rate.
			$ci = self::wilson_confidence_interval( $conversions, $visitors, $confidence_level );
			$result->ci_lower = $ci['lower'];
			$result->ci_upper = $ci['upper'];

			if ( $result->is_control ) {
				$result->improvement       = 0;
				$result->z_score           = 0;
				$result->p_value           = 1;
				$result->is_significant    = false;
				$result->confidence        = 0;
				$result->significance_text = __( 'Control', 'opti-behavior' );
			} else {
				// Improvement over control.
				$result->improvement = $control_rate > 0
					? ( ( $rate - $control_rate ) / $control_rate ) * 100
					: ( $rate > 0 ? 100 : 0 );

				// Z-test.
				$z_result = self::two_proportion_z_test(
					$control_conversions, $control_visitors,
					$conversions, $visitors
				);

				$result->z_score        = $z_result['z_score'];
				$result->p_value        = $z_result['p_value'];
				$result->is_significant = $z_result['p_value'] < ( 1 - $confidence_level );
				$result->confidence     = ( 1 - $z_result['p_value'] ) * 100;

				if ( $result->is_significant ) {
					if ( $rate > $control_rate ) {
						/* translators: %s: confidence percentage */
						$result->significance_text = sprintf( __( 'Winner at %.1f%% confidence', 'opti-behavior' ), $result->confidence );
					} else {
						/* translators: %s: confidence percentage */
						$result->significance_text = sprintf( __( 'Loser at %.1f%% confidence', 'opti-behavior' ), $result->confidence );
					}
				} else {
					$result->significance_text = __( 'Not yet significant', 'opti-behavior' );
				}
			}

			$results[] = $result;
		}

		return $results;
	}

	/**
	 * Two-proportion Z-test.
	 *
	 * Compares two conversion rates for statistical significance.
	 *
	 * @since 1.3.0
	 * @param int $conversions_a Conversions for variant A (control).
	 * @param int $visitors_a    Visitors for variant A.
	 * @param int $conversions_b Conversions for variant B (challenger).
	 * @param int $visitors_b    Visitors for variant B.
	 * @return array { z_score, p_value, significant_at_95 }
	 */
	public static function two_proportion_z_test( $conversions_a, $visitors_a, $conversions_b, $visitors_b ) {
		$visitors_a    = max( 1, (int) $visitors_a );
		$visitors_b    = max( 1, (int) $visitors_b );
		$conversions_a = max( 0, min( (int) $conversions_a, $visitors_a ) );
		$conversions_b = max( 0, min( (int) $conversions_b, $visitors_b ) );

		$p_a = $conversions_a / $visitors_a;
		$p_b = $conversions_b / $visitors_b;

		// Pooled proportion.
		$p_pool = ( $conversions_a + $conversions_b ) / ( $visitors_a + $visitors_b );

		// Standard error.
		$se = sqrt( $p_pool * ( 1 - $p_pool ) * ( ( 1 / $visitors_a ) + ( 1 / $visitors_b ) ) );

		// Prevent division by zero.
		if ( $se < 1e-10 ) {
			return array(
				'z_score'          => 0,
				'p_value'          => 1,
				'significant_at_95' => false,
			);
		}

		$z = ( $p_b - $p_a ) / $se;

		// Two-tailed p-value using normal CDF approximation.
		$p_value = 2 * self::normal_cdf( -abs( $z ) );

		return array(
			'z_score'          => round( $z, 4 ),
			'p_value'          => round( $p_value, 6 ),
			'significant_at_95' => $p_value < 0.05,
		);
	}

	/**
	 * Wilson score confidence interval for a proportion.
	 *
	 * More accurate than the normal approximation, especially for small samples
	 * or extreme proportions (near 0% or 100%).
	 *
	 * @since 1.3.0
	 * @param int   $successes  Number of successes (conversions).
	 * @param int   $trials     Number of trials (visitors).
	 * @param float $confidence Confidence level (0-1).
	 * @return array { lower, upper }
	 */
	public static function wilson_confidence_interval( $successes, $trials, $confidence = 0.95 ) {
		$trials = max( 1, (int) $trials );
		$successes = max( 0, min( (int) $successes, $trials ) );

		if ( 0 === $trials ) {
			return array( 'lower' => 0, 'upper' => 0 );
		}

		$p = $successes / $trials;
		$z = self::z_critical( $confidence );

		$denominator = 1 + ( $z * $z / $trials );
		$center      = $p + ( $z * $z / ( 2 * $trials ) );
		$spread      = $z * sqrt( ( $p * ( 1 - $p ) / $trials ) + ( $z * $z / ( 4 * $trials * $trials ) ) );

		$lower = ( $center - $spread ) / $denominator;
		$upper = ( $center + $spread ) / $denominator;

		return array(
			'lower' => max( 0, round( $lower, 6 ) ),
			'upper' => min( 1, round( $upper, 6 ) ),
		);
	}

	/**
	 * Sample size calculator.
	 *
	 * Calculates the minimum sample size per variant needed to detect a given
	 * effect size with the specified power and significance level.
	 *
	 * @since 1.3.0
	 * @param float $baseline_rate  Current conversion rate (0-1). e.g., 0.05 for 5%.
	 * @param float $min_effect     Minimum detectable effect (relative). e.g., 0.10 for 10% improvement.
	 * @param float $confidence     Confidence level (0-1). Default 0.95.
	 * @param float $power          Statistical power (0-1). Default 0.80.
	 * @return array { sample_size_per_variant, total_sample_size, estimated_days }
	 */
	public static function calculate_sample_size( $baseline_rate, $min_effect, $confidence = 0.95, $power = 0.80 ) {
		$baseline_rate = max( 0.001, min( 0.999, floatval( $baseline_rate ) ) );
		$min_effect    = max( 0.01, floatval( $min_effect ) );
		$confidence    = max( 0.80, min( 0.99, floatval( $confidence ) ) );
		$power         = max( 0.50, min( 0.99, floatval( $power ) ) );

		$p1 = $baseline_rate;
		$p2 = $baseline_rate * ( 1 + $min_effect );

		// Cap p2 at 0.999.
		$p2 = min( 0.999, $p2 );

		$z_alpha = self::z_critical( $confidence );    // e.g., 1.96 for 95%.
		$z_beta  = self::z_critical( 1 - ( 1 - $power ) * 2 ); // e.g., 0.84 for 80% power.

		// Sample size formula for two-proportion test.
		$p_avg = ( $p1 + $p2 ) / 2;
		$numerator   = pow( $z_alpha * sqrt( 2 * $p_avg * ( 1 - $p_avg ) ) + $z_beta * sqrt( $p1 * ( 1 - $p1 ) + $p2 * ( 1 - $p2 ) ), 2 );
		$denominator = pow( $p2 - $p1, 2 );

		if ( $denominator < 1e-10 ) {
			return array(
				'sample_size_per_variant' => PHP_INT_MAX,
				'total_sample_size'       => PHP_INT_MAX,
				'estimated_days'          => PHP_INT_MAX,
			);
		}

		$n = ceil( $numerator / $denominator );

		return array(
			'sample_size_per_variant' => (int) $n,
			'total_sample_size'       => (int) ( $n * 2 ), // For 2 variants.
			'estimated_days'          => null, // Caller must estimate based on traffic.
		);
	}

	/**
	 * Estimate test duration based on daily traffic.
	 *
	 * @since 1.3.0
	 * @param int   $sample_size_total Total sample size needed.
	 * @param int   $daily_visitors    Average daily visitors to the test page.
	 * @param float $traffic_percent   Percentage of traffic included in the test (0-100).
	 * @return int Estimated days to reach required sample size.
	 */
	public static function estimate_duration( $sample_size_total, $daily_visitors, $traffic_percent = 100 ) {
		$daily_visitors  = max( 1, (int) $daily_visitors );
		$traffic_percent = max( 1, min( 100, (int) $traffic_percent ) );

		$effective_daily = $daily_visitors * ( $traffic_percent / 100 );
		$effective_daily = max( 1, $effective_daily );

		return (int) ceil( $sample_size_total / $effective_daily );
	}

	/**
	 * Check if a test has reached statistical significance.
	 *
	 * Also checks minimum sample size and duration requirements.
	 *
	 * @since 1.3.0
	 * @param array  $variant_stats     Array of variant stat objects.
	 * @param float  $confidence_level  Required confidence level.
	 * @param int    $min_sample_size   Minimum visitors per variant.
	 * @param int    $min_duration_hours Minimum test duration in hours.
	 * @param string $started_at        Test start datetime.
	 * @return array { is_significant, winner_variant_id, reason }
	 */
	public static function check_significance( $variant_stats, $confidence_level, $min_sample_size, $min_duration_hours, $started_at ) {
		// Check minimum duration.
		if ( $started_at ) {
			$hours_running = ( time() - strtotime( $started_at ) ) / 3600;
			if ( $hours_running < $min_duration_hours ) {
				return array(
					'is_significant'    => false,
					'winner_variant_id' => null,
					'reason'            => sprintf(
						/* translators: 1: hours remaining, 2: minimum hours */
						__( 'Test needs at least %1$d more hours (minimum %2$d hours).', 'opti-behavior' ),
						ceil( $min_duration_hours - $hours_running ),
						$min_duration_hours
					),
				);
			}
		}

		// Check minimum sample size.
		foreach ( $variant_stats as $stat ) {
			if ( (int) $stat->unique_visitors < $min_sample_size ) {
				return array(
					'is_significant'    => false,
					'winner_variant_id' => null,
					'reason'            => sprintf(
						/* translators: 1: variant name, 2: current visitors, 3: minimum visitors */
						__( 'Variant "%1$s" has %2$d visitors (minimum %3$d required).', 'opti-behavior' ),
						$stat->variant_name,
						$stat->unique_visitors,
						$min_sample_size
					),
				);
			}
		}

		// Calculate results and check for significance.
		$results = self::calculate_results( $variant_stats, $confidence_level );

		$winner = null;
		$best_rate = -1;

		foreach ( $results as $result ) {
			if ( $result->is_control ) {
				continue;
			}

			if ( $result->is_significant && $result->conversion_rate > $best_rate && $result->improvement > 0 ) {
				$winner    = $result;
				$best_rate = $result->conversion_rate;
			}
		}

		if ( null !== $winner ) {
			return array(
				'is_significant'    => true,
				'winner_variant_id' => $winner->variant_id,
				'reason'            => sprintf(
					/* translators: 1: variant name, 2: improvement percentage, 3: confidence percentage */
					__( '"%1$s" beats control by %2$.1f%% with %3$.1f%% confidence.', 'opti-behavior' ),
					$winner->variant_name,
					$winner->improvement,
					$winner->confidence
				),
			);
		}

		// Check if control is significantly better than all variants.
		$control_result = null;
		foreach ( $results as $result ) {
			if ( $result->is_control ) {
				$control_result = $result;
				break;
			}
		}

		$all_losers = true;
		foreach ( $results as $result ) {
			if ( ! $result->is_control && ( ! $result->is_significant || $result->improvement >= 0 ) ) {
				$all_losers = false;
				break;
			}
		}

		if ( $all_losers && null !== $control_result ) {
			return array(
				'is_significant'    => true,
				'winner_variant_id' => $control_result->variant_id,
				'reason'            => __( 'Control is significantly better than all variants.', 'opti-behavior' ),
			);
		}

		return array(
			'is_significant'    => false,
			'winner_variant_id' => null,
			'reason'            => __( 'Not enough data to declare a winner yet.', 'opti-behavior' ),
		);
	}

	/**
	 * Progress towards statistical significance (0-100%).
	 *
	 * @since 1.3.0
	 * @param array $variant_stats Variant stat objects.
	 * @param float $confidence_level Required confidence level.
	 * @param int   $min_sample_size  Minimum visitors per variant.
	 * @return float Progress percentage (0-100).
	 */
	public static function get_significance_progress( $variant_stats, $confidence_level, $min_sample_size ) {
		$empty = array(
			'bar_pct'        => 0,
			'confidence'     => 0,
			'target'         => round( $confidence_level * 100, 1 ),
			'sample_pct'     => 0,
			'is_significant' => false,
		);

		if ( empty( $variant_stats ) ) {
			return $empty;
		}

		// Factor 1: Sample size progress.
		$min_visitors = PHP_INT_MAX;
		foreach ( $variant_stats as $stat ) {
			$min_visitors = min( $min_visitors, (int) $stat->unique_visitors );
		}
		$sample_progress = min( 100, ( $min_visitors / max( 1, $min_sample_size ) ) * 100 );

		// Factor 2: Confidence progress.
		$results         = self::calculate_results( $variant_stats, $confidence_level );
		$max_confidence  = 0;
		$is_significant  = false;
		foreach ( $results as $result ) {
			if ( ! $result->is_control ) {
				$max_confidence = max( $max_confidence, $result->confidence );
				if ( $result->is_significant ) {
					$is_significant = true;
				}
			}
		}
		$confidence_target   = $confidence_level * 100;
		$confidence_progress = min( 100, ( $max_confidence / max( 1, $confidence_target ) ) * 100 );

		// Weighted average: sample size 40%, confidence 60%.
		$bar_pct = round( ( $sample_progress * 0.4 ) + ( $confidence_progress * 0.6 ), 1 );

		return array(
			'bar_pct'        => $bar_pct,
			'confidence'     => round( $max_confidence, 1 ),
			'target'         => round( $confidence_target, 1 ),
			'sample_pct'     => round( $sample_progress, 1 ),
			'is_significant' => $is_significant,
		);
	}

	// =========================================================================
	// Mathematical Utility Functions
	// =========================================================================

	/**
	 * Cumulative Distribution Function for the standard normal distribution.
	 *
	 * Uses the Abramowitz and Stegun approximation (error < 7.5e-8).
	 *
	 * @since 1.3.0
	 * @param float $x Z-score.
	 * @return float Probability P(Z <= x).
	 */
	public static function normal_cdf( $x ) {
		// Constants for the approximation.
		$b1 =  0.319381530;
		$b2 = -0.356563782;
		$b3 =  1.781477937;
		$b4 = -1.821255978;
		$b5 =  1.330274429;
		$p  =  0.2316419;

		$sign = 1;
		if ( $x < 0 ) {
			$sign = -1;
			$x    = -$x;
		}

		$t   = 1.0 / ( 1.0 + $p * $x );
		$pdf = exp( -0.5 * $x * $x ) / sqrt( 2 * M_PI );
		$cdf = 1.0 - $pdf * ( $b1 * $t + $b2 * pow( $t, 2 ) + $b3 * pow( $t, 3 ) + $b4 * pow( $t, 4 ) + $b5 * pow( $t, 5 ) );

		if ( $sign < 0 ) {
			$cdf = 1.0 - $cdf;
		}

		return $cdf;
	}

	/**
	 * Inverse normal CDF — Z critical value for a given confidence level.
	 *
	 * Uses the Beasley-Springer-Moro algorithm.
	 *
	 * @since 1.3.0
	 * @param float $confidence Confidence level (0-1), e.g. 0.95.
	 * @return float Z critical value, e.g. ~1.96 for 95%.
	 */
	public static function z_critical( $confidence ) {
		// For two-tailed test, we need the (1 + confidence)/2 quantile.
		$p = ( 1 + $confidence ) / 2;

		return self::inverse_normal_cdf( $p );
	}

	/**
	 * Inverse normal CDF (quantile function / probit).
	 *
	 * Rational approximation by Peter J. Acklam.
	 *
	 * @since 1.3.0
	 * @param float $p Probability (0-1).
	 * @return float Z-score.
	 */
	public static function inverse_normal_cdf( $p ) {
		$p = max( 1e-10, min( 1 - 1e-10, $p ) );

		// Coefficients.
		$a1 = -3.969683028665376e+01;
		$a2 =  2.209460984245205e+02;
		$a3 = -2.759285104469687e+02;
		$a4 =  1.383577518672690e+02;
		$a5 = -3.066479806614716e+01;
		$a6 =  2.506628277459239e+00;

		$b1 = -5.447609879822406e+01;
		$b2 =  1.615858368580409e+02;
		$b3 = -1.556989798598866e+02;
		$b4 =  6.680131188771972e+01;
		$b5 = -1.328068155288572e+01;

		$c1 = -7.784894002430293e-03;
		$c2 = -3.223964580411365e-01;
		$c3 = -2.400758277161838e+00;
		$c4 = -2.549732539343734e+00;
		$c5 =  4.374664141464968e+00;
		$c6 =  2.938163982698783e+00;

		$d1 =  7.784695709041462e-03;
		$d2 =  3.224671290700398e-01;
		$d3 =  2.445134137142996e+00;
		$d4 =  3.754408661907416e+00;

		$p_low  = 0.02425;
		$p_high = 1 - $p_low;

		if ( $p < $p_low ) {
			// Lower region.
			$q = sqrt( -2 * log( $p ) );
			return ( ( ( ( ( $c1 * $q + $c2 ) * $q + $c3 ) * $q + $c4 ) * $q + $c5 ) * $q + $c6 ) /
				   ( ( ( ( $d1 * $q + $d2 ) * $q + $d3 ) * $q + $d4 ) * $q + 1 );
		}

		if ( $p <= $p_high ) {
			// Central region.
			$q = $p - 0.5;
			$r = $q * $q;
			return ( ( ( ( ( $a1 * $r + $a2 ) * $r + $a3 ) * $r + $a4 ) * $r + $a5 ) * $r + $a6 ) * $q /
				   ( ( ( ( ( $b1 * $r + $b2 ) * $r + $b3 ) * $r + $b4 ) * $r + $b5 ) * $r + 1 );
		}

		// Upper region.
		$q = sqrt( -2 * log( 1 - $p ) );
		return -( ( ( ( ( $c1 * $q + $c2 ) * $q + $c3 ) * $q + $c4 ) * $q + $c5 ) * $q + $c6 ) /
			   ( ( ( ( $d1 * $q + $d2 ) * $q + $d3 ) * $q + $d4 ) * $q + 1 );
	}

	/**
	 * Sequential testing: O'Brien-Fleming alpha-spending function.
	 *
	 * Calculates the adjusted significance level for interim analyses,
	 * preventing p-value inflation from repeated peeking.
	 *
	 * @since 1.3.0
	 * @param float $alpha          Overall significance level (e.g. 0.05).
	 * @param float $info_fraction  Information fraction (0-1): current_sample / max_sample.
	 * @return float Adjusted alpha for this interim look.
	 */
	public static function obrien_fleming_alpha( $alpha, $info_fraction ) {
		$info_fraction = max( 0.01, min( 1.0, floatval( $info_fraction ) ) );
		$alpha         = max( 0.001, min( 0.20, floatval( $alpha ) ) );

		// O'Brien-Fleming spending function:
		// alpha(t) = 2 * [1 - Phi(z_{alpha/2} / sqrt(t))]
		$z = self::z_critical( 1 - $alpha );
		$adjusted = 2 * ( 1 - self::normal_cdf( $z / sqrt( $info_fraction ) ) );

		return max( 0.0001, $adjusted );
	}

	/**
	 * Sequential test: check if result is significant at the current interim stage.
	 *
	 * @since 1.3.0
	 * @param float $p_value         Current test p-value.
	 * @param float $alpha           Overall significance level.
	 * @param int   $current_sample  Current total sample size.
	 * @param int   $max_sample      Planned maximum sample size.
	 * @return array { is_significant, adjusted_alpha, info_fraction }
	 */
	public static function sequential_check( $p_value, $alpha, $current_sample, $max_sample ) {
		$max_sample     = max( 1, (int) $max_sample );
		$current_sample = max( 1, min( (int) $current_sample, $max_sample ) );
		$info_fraction  = $current_sample / $max_sample;

		$adjusted_alpha = self::obrien_fleming_alpha( $alpha, $info_fraction );

		return array(
			'is_significant' => $p_value < $adjusted_alpha,
			'adjusted_alpha' => round( $adjusted_alpha, 6 ),
			'info_fraction'  => round( $info_fraction, 4 ),
		);
	}
}
