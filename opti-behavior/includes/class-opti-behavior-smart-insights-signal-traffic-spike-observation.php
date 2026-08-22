<?php
/**
 * Smart Insights Traffic Spike Observation Signal Class
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
 * Detects pages receiving a material traffic increase versus the previous period.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Signal_Traffic_Spike_Observation extends Opti_Behavior_Smart_Insights_Page_Signal_Base {

	const SIGNAL_ID    = 'traffic_spike_observation';
	const SIGNAL_NAME  = 'Traffic Spike Observation';
	const CATEGORY     = 'Trend and Anomaly';
	const RULE_VERSION = '1.0.0';
	const TEMPLATE_ID  = 'page_traffic_spike_observation_v1';
	const MIN_SESSIONS = 100;
	const MIN_PREVIOUS_SESSIONS = 50;

	/**
	 * Return signal configuration.
	 *
	 * @return array
	 */
	protected function get_signal_config() {
		return array(
			'signal_id'                  => self::SIGNAL_ID,
			'signal_name'                => __( 'Traffic Spike Observation', 'opti-behavior' ),
			'category'                   => self::CATEGORY,
			'entity_type'                => 'page',
			'availability'               => 'free',
			'visibility_tier'            => 'free',
			'rule_version'               => self::RULE_VERSION,
			'recommendation_template_id' => self::TEMPLATE_ID,
			'thresholds'                 => array(
				'min_sessions'          => self::MIN_SESSIONS,
				'min_previous_sessions' => self::MIN_PREVIOUS_SESSIONS,
				'traffic_growth_ratio'  => 1.5,
			),
		);
	}

	/**
	 * Evaluate traffic spike observation.
	 *
	 * @param array $metrics   Page metrics.
	 * @param array $baselines Site baselines.
	 * @param array $args      Optional args.
	 * @return array
	 */
	public function detect( $metrics, $baselines, $args = array() ) {
		$metrics           = $this->normalize_metrics( $metrics );
		$previous_metrics  = isset( $metrics['previous_period_metrics'] ) && is_array( $metrics['previous_period_metrics'] ) ? $metrics['previous_period_metrics'] : array();
		$previous_sessions = isset( $previous_metrics['sessions'] ) ? (int) $previous_metrics['sessions'] : null;
		$relative_delta    = null;

		if ( isset( $metrics['trend']['sessions']['relative_delta_pct'] ) && is_numeric( $metrics['trend']['sessions']['relative_delta_pct'] ) ) {
			$relative_delta = (float) $metrics['trend']['sessions']['relative_delta_pct'];
		} elseif ( null !== $previous_sessions && $previous_sessions > 0 ) {
			$relative_delta = round( ( ( $metrics['sessions'] - $previous_sessions ) / $previous_sessions ) * 100, 2 );
		}

		$detection = array(
			'rule_version'              => self::RULE_VERSION,
			'triggered'                 => false,
			'threshold_sessions'        => self::MIN_SESSIONS,
			'threshold_previous_sessions'=> self::MIN_PREVIOUS_SESSIONS,
			'previous_sessions'         => $previous_sessions,
			'sessions_delta'            => null !== $previous_sessions ? $metrics['sessions'] - $previous_sessions : null,
			'sessions_relative_delta_pct'=> $relative_delta,
			'bounce_gap'                => $this->calculate_gap( $metrics['bounce_rate'], $baselines['site_avg_bounce_rate'] ),
			'severity_gap'              => null !== $relative_delta ? min( 40, $relative_delta / 2 ) : 0,
			'opportunity_gap'           => null !== $relative_delta ? min( 30, $relative_delta / 3 ) : 0,
			'supporting_metrics'        => array( 'sessions', 'previous_period_sessions', 'trend' ),
			'priority_ceiling'          => 79,
			'reason'                    => '',
		);

		if ( $metrics['sessions'] < self::MIN_SESSIONS ) {
			$detection['reason'] = 'insufficient_current_sessions';
			return $detection;
		}

		if ( null === $previous_sessions || $previous_sessions < self::MIN_PREVIOUS_SESSIONS ) {
			$detection['reason'] = 'insufficient_previous_sessions';
			return $detection;
		}

		if ( $metrics['sessions'] > $previous_sessions * 1.5 ) {
			$detection['triggered'] = true;
			$detection['reason']    = 'traffic_spike_rule';
			return $detection;
		}

		$detection['reason'] = 'traffic_growth_not_high_enough';
		return $detection;
	}
}
