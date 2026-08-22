<?php
/**
 * Smart Insights High Traffic Low Engagement Signal Class
 *
 * Deterministic Signal 1 implementation.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights High Traffic Low Engagement Signal Class
 *
 * Evaluates page-level metrics against site baselines and returns a complete
 * insight object when the rule triggers. It performs no database writes, which
 * keeps it easy for generators, CLI tests, and future Pro extensions to reuse.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Signal_High_Traffic_Low_Engagement {

	const SIGNAL_ID       = 'high_traffic_low_engagement';
	const SIGNAL_NAME     = 'High Traffic, Low Engagement Page';
	const CATEGORY        = 'UX/CRO';
	const RULE_VERSION    = '1.0.0';
	const TEMPLATE_ID     = 'page_low_engagement_v1';
	const MIN_SESSIONS    = 100;
	const ADAPTIVE_MIN_SESSIONS = 50;
	const LOW_SITE_SESSIONS_THRESHOLD = 500;

	/**
	 * Scoring utilities.
	 *
	 * @var Opti_Behavior_Smart_Insights_Scorer
	 */
	private $scorer;

	/**
	 * Recommendation library.
	 *
	 * @var Opti_Behavior_Smart_Insights_Recommendations
	 */
	private $recommendations;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Smart_Insights_Scorer|null          $scorer          Scorer dependency.
	 * @param Opti_Behavior_Smart_Insights_Recommendations|null $recommendations Recommendation dependency.
	 */
	public function __construct( $scorer = null, $recommendations = null ) {
		$this->scorer          = $scorer instanceof Opti_Behavior_Smart_Insights_Scorer ? $scorer : new Opti_Behavior_Smart_Insights_Scorer();
		$this->recommendations = $recommendations instanceof Opti_Behavior_Smart_Insights_Recommendations ? $recommendations : new Opti_Behavior_Smart_Insights_Recommendations();
	}

	/**
	 * Get rule metadata.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'signal_id'                  => self::SIGNAL_ID,
			'signal_name'                => __( 'High Traffic, Low Engagement Page', 'opti-behavior' ),
			'category'                   => self::CATEGORY,
			'entity_type'                => 'page',
			'availability'               => 'free',
			'visibility_tier'            => 'free',
			'rule_version'               => self::RULE_VERSION,
			'recommendation_template_id' => self::TEMPLATE_ID,
			'thresholds'                 => array(
				'min_sessions'                => self::MIN_SESSIONS,
				'adaptive_min_sessions'       => self::ADAPTIVE_MIN_SESSIONS,
				'low_site_sessions_threshold' => self::LOW_SITE_SESSIONS_THRESHOLD,
				'bounce_gap_points'           => 15,
				'scroll_gap_points'           => -20,
				'time_on_page_ratio'          => 0.70,
				'exit_gap_points'             => 20,
				'absolute_bounce_rate'        => 65,
				'absolute_scroll_depth'       => 35,
			),
		);
	}

	/**
	 * Evaluate the signal and return a complete insight object when triggered.
	 *
	 * @param array $metrics   Page-level metrics.
	 * @param array $baselines Site baselines.
	 * @param array $date_range Date range: from, to, optional label.
	 * @param array $args      Optional flags for scoring and generation.
	 * @return array|null
	 */
	public function evaluate( $metrics, $baselines, $date_range, $args = array() ) {
		$detection = $this->detect( $metrics, $baselines, $args );
		if ( empty( $detection['triggered'] ) ) {
			return null;
		}

		$metrics     = $this->normalize_metrics( $metrics );
		$baselines   = $this->normalize_baselines( $baselines );
		$date_range  = $this->normalize_date_range( $date_range );
		$score_args  = array(
			'adaptive_low_traffic'       => ! empty( $detection['adaptive_low_traffic'] ),
			'same_issue_previous_period' => ! empty( $args['same_issue_previous_period'] ),
			'tracking_data_complete'     => ! empty( $metrics['tracking_data_complete'] ) || ! empty( $baselines['tracking_data_complete'] ),
		);
		$scores      = $this->scorer->calculate_scores( $metrics, $baselines, $detection, $score_args );
		$evidence    = $this->build_metrics_evidence( $metrics, $baselines );
		$template    = $this->recommendations->get_template(
			self::TEMPLATE_ID,
			array(
				'metrics'   => $metrics,
				'baselines' => $baselines,
				'detection' => $detection,
				'scores'    => $scores,
			)
		);

		$availability = apply_filters(
			'opti_behavior_smart_insights_availability',
			'free',
			self::SIGNAL_ID,
			array(
				'metrics'   => $metrics,
				'baselines' => $baselines,
			)
		);

		$insight = array(
			'signal_id'           => self::SIGNAL_ID,
			'signal_name'         => __( 'High Traffic, Low Engagement Page', 'opti-behavior' ),
			'category'            => self::CATEGORY,
			'entity_type'         => 'page',
			'entity_id'           => $this->get_entity_id( $metrics ),
			'entity_label'        => $this->get_entity_label( $metrics ),
			'date_from'           => $date_range['from'],
			'date_to'             => $date_range['to'],
			'date_range'          => $date_range,
			'metrics'             => $evidence,
			'detection'           => $detection,
			'scores'              => $scores,
			'trend'               => isset( $metrics['trend'] ) && is_array( $metrics['trend'] ) ? $metrics['trend'] : array(),
			'interpretation'      => $template['interpretation'] ?? '',
			'explanation'         => $template['interpretation'] ?? '',
			'why_it_matters'      => $template['why_it_matters'] ?? '',
			'likely_causes'       => $template['likely_causes'] ?? array(),
			'recommended_actions' => $template['recommended_actions'] ?? array(),
			'related_reports'     => $template['related_reports'] ?? array(),
			'availability'        => sanitize_key( $availability ),
			'source_plugin'       => 'free',
			'visibility_tier'     => 'free',
			'group_key'           => self::SIGNAL_ID . ':page:' . md5( $this->get_entity_id( $metrics ) ),
			'status'              => 'new',
			'rule_version'        => self::RULE_VERSION,
			'last_seen_at'        => $this->get_last_seen_at( $metrics ),
		);

		if ( ! empty( $template['upgrade_preview'] ) ) {
			$insight['upgrade_preview'] = $template['upgrade_preview'];
		}

		/**
		 * Filter the generated high-traffic low-engagement insight.
		 *
		 * @param array $insight   Complete insight object.
		 * @param array $metrics   Page metrics.
		 * @param array $baselines Site baselines.
		 */
		$insight = apply_filters( 'opti_behavior_smart_insights_high_traffic_low_engagement_insight', $insight, $metrics, $baselines );

		do_action( 'opti_behavior_smart_insight_generated', $insight );

		return $insight;
	}

	/**
	 * Alias for generator readability.
	 *
	 * @param array $metrics    Page metrics.
	 * @param array $baselines  Site baselines.
	 * @param array $date_range Date range.
	 * @param array $args       Optional args.
	 * @return array|null
	 */
	public function evaluate_page( $metrics, $baselines, $date_range, $args = array() ) {
		return $this->evaluate( $metrics, $baselines, $date_range, $args );
	}

	/**
	 * Return rule detection output without building an insight.
	 *
	 * @param array $metrics   Page metrics.
	 * @param array $baselines Site baselines.
	 * @param array $args      Optional args.
	 * @return array
	 */
	public function detect( $metrics, $baselines, $args = array() ) {
		$metrics   = $this->normalize_metrics( $metrics );
		$baselines = $this->normalize_baselines( $baselines );
		$threshold = $this->get_session_threshold( $baselines, $args );
		$sessions  = (int) $metrics['sessions'];

		$detection = array(
			'rule_version'         => self::RULE_VERSION,
			'triggered'            => false,
			'fallback_used'        => false,
			'adaptive_low_traffic' => $threshold < self::MIN_SESSIONS,
			'threshold_sessions'   => $threshold,
			'bounce_gap'           => $this->calculate_gap( $metrics['bounce_rate'], $baselines['site_avg_bounce_rate'] ),
			'scroll_gap'           => $this->calculate_gap( $metrics['avg_scroll_depth'], $baselines['site_avg_scroll_depth'] ),
			'time_on_page_gap'     => $this->calculate_gap( $metrics['avg_time_on_page'], $baselines['site_avg_time_on_page'] ),
			'exit_gap'             => $this->calculate_gap( $metrics['exit_rate'], $baselines['site_avg_exit_rate'] ),
			'supporting_metrics'   => array(),
			'reason'               => '',
		);

		if ( $sessions < $threshold ) {
			$detection['reason'] = 'insufficient_page_sessions';
			return $this->with_supporting_metrics( $detection, $metrics, $baselines );
		}

		$has_bounce_baseline = $this->is_metric_available( $metrics['bounce_rate'] ) && $this->is_metric_available( $baselines['site_avg_bounce_rate'] );
		$has_scroll_baseline = $this->is_metric_available( $metrics['avg_scroll_depth'] ) && $this->is_metric_available( $baselines['site_avg_scroll_depth'] );
		$has_time_baseline   = $this->is_metric_available( $metrics['avg_time_on_page'] ) && $this->is_metric_available( $baselines['site_avg_time_on_page'] );
		$has_exit_baseline   = $this->is_metric_available( $metrics['exit_rate'] ) && $this->is_metric_available( $baselines['site_avg_exit_rate'] );

		if ( $has_bounce_baseline && $has_scroll_baseline ) {
			if ( (float) $detection['bounce_gap'] >= 15 && (float) $detection['scroll_gap'] <= -20 ) {
				$detection['triggered'] = true;
				$detection['reason']    = 'primary_rule';
				return $this->with_supporting_metrics( $detection, $metrics, $baselines );
			}

			$detection['reason'] = 'engagement_not_low_enough';
			return $this->with_supporting_metrics( $detection, $metrics, $baselines );
		}

		if ( $has_bounce_baseline && $has_time_baseline && ! $this->is_metric_available( $metrics['avg_scroll_depth'] ) ) {
			$time_threshold = (float) $baselines['site_avg_time_on_page'] * 0.70;
			if ( (float) $detection['bounce_gap'] >= 15 && (float) $metrics['avg_time_on_page'] <= $time_threshold ) {
				$detection['triggered']                = true;
				$detection['fallback_used']            = 'time_on_page';
				$detection['time_on_page_threshold']   = round( $time_threshold, 2 );
				$detection['time_on_page_ratio']       = $baselines['site_avg_time_on_page'] > 0 ? round( (float) $metrics['avg_time_on_page'] / (float) $baselines['site_avg_time_on_page'], 4 ) : null;
				$detection['reason']                   = 'time_on_page_fallback';
				return $this->with_supporting_metrics( $detection, $metrics, $baselines );
			}

			$detection['reason'] = 'time_on_page_fallback_not_met';
			return $this->with_supporting_metrics( $detection, $metrics, $baselines );
		}

		if ( $has_bounce_baseline && $has_exit_baseline && ! $this->is_metric_available( $metrics['avg_scroll_depth'] ) && ! $this->is_metric_available( $metrics['avg_time_on_page'] ) ) {
			if ( (float) $detection['bounce_gap'] >= 15 && (float) $detection['exit_gap'] >= 20 ) {
				$detection['triggered']     = true;
				$detection['fallback_used'] = 'exit_rate';
				$detection['reason']        = 'exit_rate_fallback';
				return $this->with_supporting_metrics( $detection, $metrics, $baselines );
			}

			$detection['reason'] = 'exit_rate_fallback_not_met';
			return $this->with_supporting_metrics( $detection, $metrics, $baselines );
		}

		if ( $this->is_metric_available( $metrics['bounce_rate'] ) && $this->is_metric_available( $metrics['avg_scroll_depth'] ) ) {
			$absolute_bounce_gap = (float) $metrics['bounce_rate'] - 65;
			$absolute_scroll_gap = (float) $metrics['avg_scroll_depth'] - 35;
			if ( (float) $metrics['bounce_rate'] >= 65 && (float) $metrics['avg_scroll_depth'] <= 35 ) {
				$detection['triggered']     = true;
				$detection['fallback_used'] = 'absolute_thresholds';
				$detection['bounce_gap']    = round( $absolute_bounce_gap, 2 );
				$detection['scroll_gap']    = round( $absolute_scroll_gap, 2 );
				$detection['reason']        = 'baseline_unavailable_fallback';
				return $this->with_supporting_metrics( $detection, $metrics, $baselines );
			}
		}

		$detection['reason'] = 'required_metrics_unavailable_or_rule_not_met';
		return $this->with_supporting_metrics( $detection, $metrics, $baselines );
	}

	/**
	 * Normalize metric keys used by the rule.
	 *
	 * @param array $metrics Raw metrics.
	 * @return array
	 */
	private function normalize_metrics( $metrics ) {
		$defaults = array(
			'page_key'               => '',
			'page_id'                => 0,
			'page_url'               => '',
			'page_title'             => '',
			'entity_type'            => 'page',
			'entity_id'              => '',
			'entity_label'           => '',
			'sessions'               => 0,
			'users'                  => 0,
			'pageviews'              => 0,
			'bounce_rate'            => null,
			'avg_scroll_depth'       => null,
			'avg_time_on_page'       => null,
			'exit_rate'              => null,
			'conversion_rate'        => null,
			'cta_click_rate'         => null,
			'top_page_sessions'      => 0,
			'tracking_data_complete' => false,
			'trend'                  => array(),
			'previous_period_metrics' => array(),
			'last_seen_at'           => '',
		);

		$metrics = wp_parse_args( is_array( $metrics ) ? $metrics : array(), $defaults );

		foreach ( array( 'sessions', 'users', 'pageviews', 'top_page_sessions' ) as $key ) {
			$metrics[ $key ] = max( 0, (int) $metrics[ $key ] );
		}

		foreach ( array( 'bounce_rate', 'avg_scroll_depth', 'avg_time_on_page', 'exit_rate', 'conversion_rate', 'cta_click_rate' ) as $key ) {
			$metrics[ $key ] = $this->normalize_nullable_number( $metrics[ $key ] );
		}

		if ( 0 === $metrics['top_page_sessions'] ) {
			$metrics['top_page_sessions'] = $metrics['sessions'];
		}

		$metrics['tracking_data_complete'] = (bool) $metrics['tracking_data_complete'];
		$metrics['trend']                  = is_array( $metrics['trend'] ) ? $metrics['trend'] : array();
		$metrics['previous_period_metrics'] = is_array( $metrics['previous_period_metrics'] ) ? $metrics['previous_period_metrics'] : array();

		return $metrics;
	}

	/**
	 * Normalize baseline keys used by the rule.
	 *
	 * @param array $baselines Raw baselines.
	 * @return array
	 */
	private function normalize_baselines( $baselines ) {
		$defaults = array(
			'site_sessions'            => 0,
			'site_avg_bounce_rate'     => null,
			'site_avg_scroll_depth'    => null,
			'site_avg_time_on_page'    => null,
			'site_avg_exit_rate'       => null,
			'site_avg_conversion_rate' => null,
			'site_avg_cta_click_rate'  => null,
			'tracking_data_complete'   => false,
		);

		$baselines                  = wp_parse_args( is_array( $baselines ) ? $baselines : array(), $defaults );
		$baselines['site_sessions'] = max( 0, (int) $baselines['site_sessions'] );

		foreach ( array( 'site_avg_bounce_rate', 'site_avg_scroll_depth', 'site_avg_time_on_page', 'site_avg_exit_rate', 'site_avg_conversion_rate', 'site_avg_cta_click_rate' ) as $key ) {
			$baselines[ $key ] = $this->normalize_nullable_number( $baselines[ $key ] );
		}

		$baselines['tracking_data_complete'] = (bool) $baselines['tracking_data_complete'];

		return $baselines;
	}

	/**
	 * Build a flat metrics evidence object.
	 *
	 * @param array $metrics   Page metrics.
	 * @param array $baselines Site baselines.
	 * @return array
	 */
	private function build_metrics_evidence( $metrics, $baselines ) {
		$evidence = array_merge( $metrics, $baselines );

		$evidence['signal_metrics'] = array(
			'page_sessions'             => $metrics['sessions'],
			'page_bounce_rate'          => $metrics['bounce_rate'],
			'site_avg_bounce_rate'      => $baselines['site_avg_bounce_rate'],
			'page_avg_scroll_depth'     => $metrics['avg_scroll_depth'],
			'site_avg_scroll_depth'     => $baselines['site_avg_scroll_depth'],
			'page_avg_time_on_page'     => $metrics['avg_time_on_page'],
			'site_avg_time_on_page'     => $baselines['site_avg_time_on_page'],
			'page_exit_rate'            => $metrics['exit_rate'],
			'site_avg_exit_rate'        => $baselines['site_avg_exit_rate'],
			'page_top_sessions'         => $metrics['top_page_sessions'],
			'page_conversion_rate'      => $metrics['conversion_rate'],
			'site_avg_conversion_rate'  => $baselines['site_avg_conversion_rate'],
			'page_cta_click_rate'       => $metrics['cta_click_rate'],
			'site_avg_cta_click_rate'   => $baselines['site_avg_cta_click_rate'],
		);
		$evidence['baseline'] = array(
			'site_avg_bounce_rate'     => $baselines['site_avg_bounce_rate'],
			'site_avg_scroll_depth'    => $baselines['site_avg_scroll_depth'],
			'site_avg_time_on_page'    => $baselines['site_avg_time_on_page'],
			'site_avg_exit_rate'       => $baselines['site_avg_exit_rate'],
			'site_avg_conversion_rate' => $baselines['site_avg_conversion_rate'],
			'site_avg_cta_click_rate'  => $baselines['site_avg_cta_click_rate'],
		);

		return $evidence;
	}

	/**
	 * Normalize a date range.
	 *
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function normalize_date_range( $date_range ) {
		$date_range = is_array( $date_range ) ? $date_range : array();
		$from       = isset( $date_range['from'] ) ? $this->normalize_date( $date_range['from'] ) : gmdate( 'Y-m-d', strtotime( '-6 days' ) );
		$to         = isset( $date_range['to'] ) ? $this->normalize_date( $date_range['to'] ) : gmdate( 'Y-m-d' );

		return array(
			'from'  => $from,
			'to'    => $to,
			'label' => isset( $date_range['label'] ) ? sanitize_text_field( $date_range['label'] ) : '',
		);
	}

	/**
	 * Add supporting metric names to detection output.
	 *
	 * @param array $detection Detection output.
	 * @param array $metrics   Page metrics.
	 * @param array $baselines Site baselines.
	 * @return array
	 */
	private function with_supporting_metrics( $detection, $metrics, $baselines ) {
		$supporting = array( 'sessions' );

		if ( $this->is_metric_available( $metrics['bounce_rate'] ) ) {
			$supporting[] = 'bounce_rate';
		}

		if ( $this->is_metric_available( $metrics['avg_scroll_depth'] ) ) {
			$supporting[] = 'scroll_depth';
		}

		if ( $this->is_metric_available( $metrics['avg_time_on_page'] ) && ! $this->is_metric_available( $metrics['avg_scroll_depth'] ) ) {
			$supporting[] = 'time_on_page';
		}

		if ( $this->is_metric_available( $metrics['exit_rate'] ) && ! $this->is_metric_available( $metrics['avg_scroll_depth'] ) && ! $this->is_metric_available( $metrics['avg_time_on_page'] ) ) {
			$supporting[] = 'exit_rate';
		}

		if ( $this->is_metric_available( $baselines['site_avg_bounce_rate'] ) ) {
			$supporting[] = 'site_bounce_baseline';
		}

		if ( $this->is_metric_available( $baselines['site_avg_scroll_depth'] ) ) {
			$supporting[] = 'site_scroll_baseline';
		}

		if ( $this->is_metric_available( $baselines['site_avg_time_on_page'] ) && in_array( 'time_on_page', $supporting, true ) ) {
			$supporting[] = 'site_time_on_page_baseline';
		}

		if ( $this->is_metric_available( $baselines['site_avg_exit_rate'] ) && in_array( 'exit_rate', $supporting, true ) ) {
			$supporting[] = 'site_exit_rate_baseline';
		}

		$detection['supporting_metrics'] = array_values( array_unique( $supporting ) );

		return $detection;
	}

	/**
	 * Get min-session threshold, including adaptive low-traffic behavior.
	 *
	 * @param array $baselines Site baselines.
	 * @param array $args      Optional args.
	 * @return int
	 */
	private function get_session_threshold( $baselines, $args ) {
		$site_sessions = isset( $baselines['site_sessions'] ) ? (int) $baselines['site_sessions'] : 0;
		$threshold     = self::MIN_SESSIONS;
		if ( $site_sessions > 0 && $site_sessions < self::LOW_SITE_SESSIONS_THRESHOLD ) {
			$threshold = self::ADAPTIVE_MIN_SESSIONS;
		}

		if ( isset( $args['min_sessions'] ) && '' !== $args['min_sessions'] ) {
			$override = absint( $args['min_sessions'] );
			if ( $override > $threshold ) {
				return $override;
			}
		}

		return $threshold;
	}

	/**
	 * Calculate a numeric gap if both values are available.
	 *
	 * @param mixed $left  Left value.
	 * @param mixed $right Right value.
	 * @return float|null
	 */
	private function calculate_gap( $left, $right ) {
		if ( ! $this->is_metric_available( $left ) || ! $this->is_metric_available( $right ) ) {
			return null;
		}

		return round( (float) $left - (float) $right, 2 );
	}

	/**
	 * Normalize a number or null-like value.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private function normalize_nullable_number( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return round( (float) $value, 2 );
	}

	/**
	 * Whether a metric value is usable.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_metric_available( $value ) {
		return null !== $value && '' !== $value && is_numeric( $value );
	}

	/**
	 * Get entity ID for the insight.
	 *
	 * @param array $metrics Page metrics.
	 * @return string
	 */
	private function get_entity_id( $metrics ) {
		if ( ! empty( $metrics['entity_id'] ) ) {
			return (string) $metrics['entity_id'];
		}

		if ( ! empty( $metrics['page_url'] ) ) {
			return (string) $metrics['page_url'];
		}

		if ( ! empty( $metrics['page_key'] ) ) {
			return (string) $metrics['page_key'];
		}

		return 'page:' . (int) $metrics['page_id'];
	}

	/**
	 * Get entity label for the insight.
	 *
	 * @param array $metrics Page metrics.
	 * @return string
	 */
	private function get_entity_label( $metrics ) {
		if ( ! empty( $metrics['entity_label'] ) ) {
			return (string) $metrics['entity_label'];
		}

		if ( ! empty( $metrics['page_title'] ) ) {
			return (string) $metrics['page_title'];
		}

		return $this->get_entity_id( $metrics );
	}

	/**
	 * Get last-seen datetime for storage.
	 *
	 * @param array $metrics Page metrics.
	 * @return string
	 */
	private function get_last_seen_at( $metrics ) {
		if ( ! empty( $metrics['last_seen_at'] ) ) {
			$timestamp = strtotime( (string) $metrics['last_seen_at'] );
			if ( $timestamp ) {
				return gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Normalize a date string.
	 *
	 * @param string $date Date.
	 * @return string
	 */
	private function normalize_date( $date ) {
		$timestamp = strtotime( (string) $date );

		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : gmdate( 'Y-m-d' );
	}
}
