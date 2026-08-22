<?php
/**
 * Smart Insights Basic Mobile Bounce Warning Signal Class
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
 * Free-safe mobile bounce warning with a Pro diagnosis preview.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Signal_Basic_Mobile_Bounce_Warning extends Opti_Behavior_Smart_Insights_Page_Signal_Base {

	const SIGNAL_ID    = 'basic_mobile_bounce_warning';
	const SIGNAL_NAME  = 'Basic Mobile Bounce Warning';
	const CATEGORY     = 'Device Friction';
	const RULE_VERSION = '1.0.0';
	const TEMPLATE_ID  = 'device_basic_mobile_bounce_warning_v1';
	const MIN_SESSIONS = 100;

	/**
	 * Return signal configuration.
	 *
	 * @return array
	 */
	protected function get_signal_config() {
		return array(
			'signal_id'                  => self::SIGNAL_ID,
			'signal_name'                => __( 'Basic Mobile Bounce Warning', 'opti-behavior' ),
			'category'                   => self::CATEGORY,
			'entity_type'                => 'device',
			'availability'               => 'free',
			'visibility_tier'            => 'free',
			'rule_version'               => self::RULE_VERSION,
			'recommendation_template_id' => self::TEMPLATE_ID,
			'thresholds'                 => array(
				'min_mobile_sessions' => self::MIN_SESSIONS,
				'bounce_gap_points'   => 15,
			),
		);
	}

	/**
	 * Alias for generator device metrics.
	 *
	 * @param array $metrics    Device metrics.
	 * @param array $baselines  Device/site baselines.
	 * @param array $date_range Date range.
	 * @param array $args       Optional args.
	 * @return array|null
	 */
	public function evaluate_device( $metrics, $baselines, $date_range, $args = array() ) {
		return $this->evaluate( $metrics, $baselines, $date_range, $args );
	}

	/**
	 * Evaluate mobile bounce warning.
	 *
	 * @param array $metrics   Device metrics.
	 * @param array $baselines Baselines.
	 * @param array $args      Optional args.
	 * @return array
	 */
	public function detect( $metrics, $baselines, $args = array() ) {
		$metrics     = $this->normalize_metrics( $metrics );
		$baselines   = $this->normalize_baselines( $baselines );
		$device_key  = isset( $metrics['device_key'] ) ? sanitize_key( $metrics['device_key'] ) : sanitize_key( $metrics['entity_id'] );
		$desktop_row = $this->find_desktop_metrics( isset( $args['all_device_metrics'] ) ? $args['all_device_metrics'] : array() );
		$desktop_bounce = isset( $desktop_row['bounce_rate'] ) && is_numeric( $desktop_row['bounce_rate'] ) ? (float) $desktop_row['bounce_rate'] : null;
		$comparison_bounce = null !== $desktop_bounce ? $desktop_bounce : $baselines['site_avg_bounce_rate'];
		$bounce_gap = $this->calculate_gap( $metrics['bounce_rate'], $comparison_bounce );

		$detection = array(
			'rule_version'       => self::RULE_VERSION,
			'triggered'          => false,
			'threshold_sessions' => self::MIN_SESSIONS,
			'comparison_device'  => null !== $desktop_bounce ? 'desktop' : 'site_average',
			'desktop_bounce_rate'=> $desktop_bounce,
			'comparison_bounce_rate'=> $comparison_bounce,
			'bounce_gap'         => $bounce_gap,
			'scroll_gap'         => $this->calculate_gap( $metrics['avg_scroll_depth'], isset( $desktop_row['avg_scroll_depth'] ) ? $desktop_row['avg_scroll_depth'] : $baselines['site_avg_scroll_depth'] ),
			'severity_gap'       => null !== $bounce_gap ? $bounce_gap : 0,
			'opportunity_gap'    => null !== $bounce_gap ? $bounce_gap : 0,
			'supporting_metrics' => array( 'mobile_sessions', 'mobile_bounce_rate', 'desktop_or_site_bounce_rate' ),
			'reason'             => '',
		);

		if ( ! in_array( $device_key, array( 'mobile', 'phone', 'smartphone' ), true ) ) {
			$detection['reason'] = 'not_mobile_device';
			return $detection;
		}

		if ( $metrics['sessions'] < self::MIN_SESSIONS ) {
			$detection['reason'] = 'insufficient_mobile_sessions';
			return $detection;
		}

		if ( ! $this->is_metric_available( $metrics['bounce_rate'] ) || null === $bounce_gap ) {
			$detection['reason'] = 'mobile_bounce_or_comparison_unavailable';
			return $detection;
		}

		if ( $bounce_gap > 15 ) {
			$detection['triggered'] = true;
			$detection['reason']    = 'mobile_bounce_gap_rule';
			return $detection;
		}

		$detection['reason'] = 'mobile_bounce_gap_not_high_enough';
		return $detection;
	}

	/**
	 * Find desktop aggregate in the same period.
	 *
	 * @param array $rows Device metric rows.
	 * @return array
	 */
	private function find_desktop_metrics( $rows ) {
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = isset( $row['device_key'] ) ? sanitize_key( $row['device_key'] ) : sanitize_key( $row['entity_id'] ?? '' );
			if ( in_array( $key, array( 'desktop', 'computer' ), true ) ) {
				return $row;
			}
		}

		return array();
	}
}
