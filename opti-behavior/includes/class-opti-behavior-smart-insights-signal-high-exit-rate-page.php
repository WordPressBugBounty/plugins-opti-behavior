<?php
/**
 * Smart Insights High Exit Rate Page Signal Class
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
 * Detects pages that frequently end visits.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Signal_High_Exit_Rate_Page extends Opti_Behavior_Smart_Insights_Page_Signal_Base {

	const SIGNAL_ID    = 'high_exit_rate_page';
	const SIGNAL_NAME  = 'High Exit Rate Page';
	const CATEGORY     = 'Exit and Abandonment';
	const RULE_VERSION = '1.0.0';
	const TEMPLATE_ID  = 'page_high_exit_rate_v1';
	const MIN_PAGEVIEWS = 100;

	/**
	 * Return signal configuration.
	 *
	 * @return array
	 */
	protected function get_signal_config() {
		return array(
			'signal_id'                  => self::SIGNAL_ID,
			'signal_name'                => __( 'High Exit Rate Page', 'opti-behavior' ),
			'category'                   => self::CATEGORY,
			'entity_type'                => 'page',
			'availability'               => 'free',
			'visibility_tier'            => 'free',
			'rule_version'               => self::RULE_VERSION,
			'recommendation_template_id' => self::TEMPLATE_ID,
			'thresholds'                 => array(
				'min_pageviews'   => self::MIN_PAGEVIEWS,
				'exit_gap_points' => 20,
			),
		);
	}

	/**
	 * Evaluate high exit rate.
	 *
	 * @param array $metrics   Page metrics.
	 * @param array $baselines Site baselines.
	 * @param array $args      Optional args.
	 * @return array
	 */
	public function detect( $metrics, $baselines, $args = array() ) {
		$metrics   = $this->normalize_metrics( $metrics );
		$baselines = $this->normalize_baselines( $baselines );
		$threshold = isset( $args['min_pageviews'] ) ? max( self::MIN_PAGEVIEWS, absint( $args['min_pageviews'] ) ) : self::MIN_PAGEVIEWS;
		$exit_gap  = $this->calculate_gap( $metrics['exit_rate'], $baselines['site_avg_exit_rate'] );

		$detection = array(
			'rule_version'       => self::RULE_VERSION,
			'triggered'          => false,
			'threshold_pageviews'=> $threshold,
			'exit_gap'           => $exit_gap,
			'bounce_gap'         => $this->calculate_gap( $metrics['bounce_rate'], $baselines['site_avg_bounce_rate'] ),
			'scroll_gap'         => $this->calculate_gap( $metrics['avg_scroll_depth'], $baselines['site_avg_scroll_depth'] ),
			'severity_gap'       => null !== $exit_gap ? $exit_gap : 0,
			'opportunity_gap'    => null !== $exit_gap ? $exit_gap : 0,
			'supporting_metrics' => array( 'pageviews', 'exit_rate', 'site_exit_rate_baseline' ),
			'reason'             => '',
		);

		if ( $metrics['pageviews'] < $threshold ) {
			$detection['reason'] = 'insufficient_pageviews';
			return $detection;
		}

		if ( ! $this->is_metric_available( $metrics['exit_rate'] ) || ! $this->is_metric_available( $baselines['site_avg_exit_rate'] ) ) {
			$detection['reason'] = 'exit_rate_or_baseline_unavailable';
			return $detection;
		}

		if ( $exit_gap > 20 ) {
			$detection['triggered'] = true;
			$detection['reason']    = 'primary_rule';
			return $detection;
		}

		$detection['reason'] = 'exit_gap_not_high_enough';
		return $detection;
	}
}
