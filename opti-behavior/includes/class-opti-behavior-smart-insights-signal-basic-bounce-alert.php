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

// Shared page rules (also loaded when the signal file is required on its own).
if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Page_Rules', false ) ) {
	require_once __DIR__ . '/class-opti-behavior-smart-insights-page-rules.php';
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
	// Shared with Page X-Ray (Page_Rules): one verdict per page and period.
	const MIN_SESSIONS = Opti_Behavior_Smart_Insights_Page_Rules::MIN_VISITS;

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

		$verdict = Opti_Behavior_Smart_Insights_Page_Rules::bounce(
			(int) $metrics['sessions'],
			$this->is_metric_available( $metrics['bounce_rate'] ) ? $metrics['bounce_rate'] : null,
			$this->is_metric_available( $baselines['site_avg_bounce_rate'] ) ? $baselines['site_avg_bounce_rate'] : null,
			$threshold
		);
		$detection['triggered'] = $verdict['triggered'];
		$detection['reason']    = $verdict['reason'];
		if ( $verdict['fallback'] ) {
			$detection['fallback_used'] = 'absolute_bounce_threshold';
			$detection['severity_gap']  = (float) $metrics['bounce_rate'] - Opti_Behavior_Smart_Insights_Page_Rules::BOUNCE_ABSOLUTE;
		}

		return $detection;
	}
}
