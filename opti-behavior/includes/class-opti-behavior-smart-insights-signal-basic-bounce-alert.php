<?php
/**
 * Smart Insights Basic Bounce Alert Signal Class
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Page_Signal_Base' ) ) {
	require_once __DIR__ . '/class-opti-behavior-smart-insights-page-signal-base.php';
}

/**
 * Detects high bounce-rate pages without requiring scroll or conversion data.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Signal_Basic_Bounce_Alert extends Opti_Behavior_Smart_Insights_Page_Signal_Base {

	const SIGNAL_ID    = 'basic_bounce_alert';
	const SIGNAL_NAME  = 'Basic Bounce Alert';
	const CATEGORY     = 'Page Engagement';
	const RULE_VERSION = '1.0.0';
	const TEMPLATE_ID  = 'page_basic_bounce_alert_v1';
	const MIN_SESSIONS = 100;

	/**
	 * Return signal configuration.
	 *
	 * @return array
	 */
	protected function get_signal_config() {
		return array(
			'signal_id'                  => self::SIGNAL_ID,
			'signal_name'                => __( 'Basic Bounce Alert', 'opti-behavior' ),
			'category'                   => self::CATEGORY,
			'entity_type'                => 'page',
			'availability'               => 'free',
			'visibility_tier'            => 'free',
			'rule_version'               => self::RULE_VERSION,
			'recommendation_template_id' => self::TEMPLATE_ID,
			'thresholds'                 => array(
				'min_sessions'           => self::MIN_SESSIONS,
				'bounce_gap_points'      => 20,
				'absolute_bounce_rate'   => 70,
			),
		);
	}

	/**
	 * Evaluate basic bounce alert.
	 *
	 * @param array $metrics   Page metrics.
	 * @param array $baselines Site baselines.
	 * @param array $args      Optional args.
	 * @return array
	 */
	public function detect( $metrics, $baselines, $args = array() ) {
		$metrics    = $this->normalize_metrics( $metrics );
		$baselines  = $this->normalize_baselines( $baselines );
		$threshold  = isset( $args['min_sessions'] ) ? max( self::MIN_SESSIONS, absint( $args['min_sessions'] ) ) : self::MIN_SESSIONS;
		$bounce_gap = $this->calculate_gap( $metrics['bounce_rate'], $baselines['site_avg_bounce_rate'] );

		$detection = array(
			'rule_version'       => self::RULE_VERSION,
			'triggered'          => false,
			'threshold_sessions' => $threshold,
			'bounce_gap'         => $bounce_gap,
			'scroll_gap'         => $this->calculate_gap( $metrics['avg_scroll_depth'], $baselines['site_avg_scroll_depth'] ),
			'severity_gap'       => null !== $bounce_gap ? $bounce_gap : 0,
			'opportunity_gap'    => null !== $bounce_gap ? $bounce_gap : 0,
			'supporting_metrics' => array( 'sessions', 'bounce_rate', 'site_bounce_baseline' ),
			'reason'             => '',
		);

		if ( $metrics['sessions'] < $threshold ) {
			$detection['reason'] = 'insufficient_page_sessions';
			return $detection;
		}

		if ( ! $this->is_metric_available( $metrics['bounce_rate'] ) ) {
			$detection['reason'] = 'bounce_rate_unavailable';
			return $detection;
		}

		if ( $this->is_metric_available( $baselines['site_avg_bounce_rate'] ) && $bounce_gap > 20 ) {
			$detection['triggered'] = true;
			$detection['reason']    = 'site_baseline_rule';
			return $detection;
		}

		if ( (float) $metrics['bounce_rate'] >= 70 ) {
			$detection['triggered']     = true;
			$detection['fallback_used'] = 'absolute_bounce_threshold';
			$detection['severity_gap']  = (float) $metrics['bounce_rate'] - 70;
			$detection['reason']        = 'baseline_unavailable_or_absolute_rule';
			return $detection;
		}

		$detection['reason'] = 'bounce_rate_not_high_enough';
		return $detection;
	}
}
