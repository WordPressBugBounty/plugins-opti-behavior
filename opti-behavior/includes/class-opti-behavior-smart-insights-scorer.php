<?php
/**
 * Smart Insights Scorer Class
 *
 * Provides deterministic traffic, severity, opportunity, priority, and
 * confidence scoring utilities.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Scorer Class
 *
 * Scores are on a 0-100 scale unless noted otherwise. Percentage-point metric
 * gaps are expected on the same 0-100 scale used by the aggregation layer.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Scorer {

	/**
	 * Calculate traffic score, max 40.
	 *
	 * @param int $page_sessions     Page sessions.
	 * @param int $top_page_sessions Highest page sessions in candidate set.
	 * @return float
	 */
	public function calculate_traffic_score( $page_sessions, $top_page_sessions ) {
		$page_sessions     = max( 0, (int) $page_sessions );
		$top_page_sessions = max( 0, (int) $top_page_sessions );

		if ( 0 === $page_sessions || 0 === $top_page_sessions ) {
			return 0.0;
		}

		return round( min( 40, ( $page_sessions / $top_page_sessions ) * 40 ), 2 );
	}

	/**
	 * Calculate severity score from a bounce-rate gap, max 40.
	 *
	 * @param float $bounce_gap Percentage-point gap on 0-100 scale.
	 * @return float
	 */
	public function calculate_severity_score( $bounce_gap ) {
		$bounce_gap = max( 0, (float) $bounce_gap );

		return round( min( 40, ( $bounce_gap / 30 ) * 40 ), 2 );
	}

	/**
	 * Calculate opportunity score, max 20.
	 *
	 * @param array $args Inputs: conversion_gap, site_avg_conversion_rate, scroll_gap.
	 * @return float
	 */
	public function calculate_opportunity_score( $args = array() ) {
		$defaults = array(
			'conversion_gap'           => null,
			'site_avg_conversion_rate' => null,
			'scroll_gap'               => null,
		);
		$args     = wp_parse_args( $args, $defaults );

		$conversion_gap  = null === $args['conversion_gap'] ? null : max( 0, (float) $args['conversion_gap'] );
		$conversion_base = null === $args['site_avg_conversion_rate'] ? null : max( 0, (float) $args['site_avg_conversion_rate'] );

		if ( null !== $conversion_gap && $conversion_base > 0 ) {
			return round( min( 20, ( $conversion_gap / $conversion_base ) * 20 ), 2 );
		}

		$scroll_gap = null === $args['scroll_gap'] ? 0 : (float) $args['scroll_gap'];

		return round( min( 20, ( abs( $scroll_gap ) / 30 ) * 20 ), 2 );
	}

	/**
	 * Calculate a final priority score and label.
	 *
	 * @param float $traffic_score     Traffic score.
	 * @param float $severity_score    Severity score.
	 * @param float $opportunity_score Opportunity score.
	 * @return array
	 */
	public function calculate_priority_score( $traffic_score, $severity_score, $opportunity_score ) {
		$priority_score = min( 100, max( 0, round( (float) $traffic_score + (float) $severity_score + (float) $opportunity_score ) ) );

		return array(
			'priority_score' => (int) $priority_score,
			'priority_label' => $this->get_priority_label( $priority_score ),
		);
	}

	/**
	 * Calculate a confidence score and label.
	 *
	 * @param array $metrics   Entity metrics.
	 * @param array $detection Detection output.
	 * @param array $args      Optional flags: same_issue_previous_period, adaptive_low_traffic.
	 * @return array
	 */
	public function calculate_confidence_score( $metrics, $detection = array(), $args = array() ) {
		$defaults = array(
			'same_issue_previous_period' => false,
			'adaptive_low_traffic'       => false,
			'tracking_data_complete'     => null,
		);
		$args     = wp_parse_args( $args, $defaults );

		$page_sessions = isset( $metrics['sessions'] ) ? (int) $metrics['sessions'] : 0;
		$points        = 0;

		if ( $page_sessions >= 100 ) {
			$points += 30;
		}

		if ( $page_sessions >= 300 ) {
			$points += 20;
		}

		if ( $this->gap_exists( $detection, 'bounce_gap' ) ) {
			$points += 15;
		}

		if ( $this->engagement_metric_confirms_issue( $detection ) ) {
			$points += 20;
		}

		if ( ! empty( $args['same_issue_previous_period'] ) || ! empty( $detection['same_issue_previous_period'] ) ) {
			$points += 15;
		}

		$tracking_complete = null === $args['tracking_data_complete']
			? ( ! empty( $metrics['tracking_data_complete'] ) )
			: (bool) $args['tracking_data_complete'];
		if ( $tracking_complete ) {
			$points += 10;
		}

		$points = min( 100, max( 0, $points ) );

		// Adaptive low-traffic periods may trigger at 50 sessions, but cannot be High confidence.
		if ( ! empty( $args['adaptive_low_traffic'] ) || ! empty( $detection['adaptive_low_traffic'] ) ) {
			$points = min( $points, 69 );
		}

		return array(
			'confidence_score' => (int) $points,
			'confidence_label' => $this->get_confidence_label( $points ),
		);
	}

	/**
	 * Calculate all common score fields for a page-level insight.
	 *
	 * @param array $metrics   Page metrics.
	 * @param array $baselines Site baselines.
	 * @param array $detection Detection output.
	 * @param array $args      Optional args.
	 * @return array
	 */
	public function calculate_scores( $metrics, $baselines, $detection = array(), $args = array() ) {
		$top_page_sessions = isset( $metrics['top_page_sessions'] ) ? (int) $metrics['top_page_sessions'] : (int) ( $metrics['sessions'] ?? 0 );
		$bounce_gap        = isset( $detection['bounce_gap'] ) ? (float) $detection['bounce_gap'] : $this->calculate_gap( $metrics, 'bounce_rate', $baselines, 'site_avg_bounce_rate' );
		$scroll_gap        = isset( $detection['scroll_gap'] ) ? (float) $detection['scroll_gap'] : $this->calculate_gap( $metrics, 'avg_scroll_depth', $baselines, 'site_avg_scroll_depth' );
		$conversion_gap    = null;

		if ( isset( $metrics['conversion_rate'], $baselines['site_avg_conversion_rate'] ) && null !== $metrics['conversion_rate'] && null !== $baselines['site_avg_conversion_rate'] ) {
			$conversion_gap = (float) $baselines['site_avg_conversion_rate'] - (float) $metrics['conversion_rate'];
		}

		$traffic_score     = $this->calculate_traffic_score( $metrics['sessions'] ?? 0, $top_page_sessions );
		$severity_score    = $this->calculate_severity_score( $bounce_gap );
		$opportunity_score = $this->calculate_opportunity_score(
			array(
				'conversion_gap'           => $conversion_gap,
				'site_avg_conversion_rate' => $baselines['site_avg_conversion_rate'] ?? null,
				'scroll_gap'               => $scroll_gap,
			)
		);
		$priority          = $this->calculate_priority_score( $traffic_score, $severity_score, $opportunity_score );
		$confidence        = $this->calculate_confidence_score( $metrics, $detection, $args );

		return array_merge(
			array(
				'traffic_score'     => $traffic_score,
				'severity_score'    => $severity_score,
				'opportunity_score' => $opportunity_score,
			),
			$priority,
			$confidence
		);
	}

	/**
	 * Get priority label for a score.
	 *
	 * @param float $score Score.
	 * @return string
	 */
	public function get_priority_label( $score ) {
		$score = (float) $score;

		if ( $score >= 80 ) {
			return $this->translate_label( 'Critical' );
		}

		if ( $score >= 60 ) {
			return $this->translate_label( 'High' );
		}

		if ( $score >= 40 ) {
			return $this->translate_label( 'Medium' );
		}

		return $this->translate_label( 'Low' );
	}

	/**
	 * Get confidence label for a score.
	 *
	 * @param float $score Score.
	 * @return string
	 */
	public function get_confidence_label( $score ) {
		$score = (float) $score;

		if ( $score >= 70 ) {
			return $this->translate_label( 'High' );
		}

		if ( $score >= 40 ) {
			return $this->translate_label( 'Medium' );
		}

		return $this->translate_label( 'Low' );
	}

	/**
	 * Calculate gap when both metric values exist.
	 *
	 * @param array  $left      Left metric set.
	 * @param string $left_key  Left key.
	 * @param array  $right     Right metric set.
	 * @param string $right_key Right key.
	 * @return float
	 */
	private function calculate_gap( $left, $left_key, $right, $right_key ) {
		if ( ! isset( $left[ $left_key ], $right[ $right_key ] ) || null === $left[ $left_key ] || null === $right[ $right_key ] ) {
			return 0.0;
		}

		return (float) $left[ $left_key ] - (float) $right[ $right_key ];
	}

	/**
	 * Whether a detection gap exists.
	 *
	 * @param array  $detection Detection output.
	 * @param string $key       Gap key.
	 * @return bool
	 */
	private function gap_exists( $detection, $key ) {
		return isset( $detection[ $key ] ) && null !== $detection[ $key ] && 0 !== (float) $detection[ $key ];
	}

	/**
	 * Whether scroll/time fallback confirms the issue.
	 *
	 * @param array $detection Detection output.
	 * @return bool
	 */
	private function engagement_metric_confirms_issue( $detection ) {
		if ( isset( $detection['scroll_gap'] ) && null !== $detection['scroll_gap'] && (float) $detection['scroll_gap'] <= -20 ) {
			return true;
		}

		if ( isset( $detection['time_on_page_gap'] ) && null !== $detection['time_on_page_gap'] && (float) $detection['time_on_page_gap'] <= 0 ) {
			return true;
		}

		if ( ! empty( $detection['fallback_used'] ) && ! empty( $detection['supporting_metrics'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Translate static labels when WordPress i18n is available.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private function translate_label( $label ) {
		if ( ! function_exists( '__' ) ) {
			return $label;
		}

		switch ( $label ) {
			case 'Critical':
				return __( 'Critical', 'opti-behavior' );
			case 'High':
				return __( 'High', 'opti-behavior' );
			case 'Medium':
				return __( 'Medium', 'opti-behavior' );
			case 'Low':
				return __( 'Low', 'opti-behavior' );
			default:
				return $label;
		}
	}
}
