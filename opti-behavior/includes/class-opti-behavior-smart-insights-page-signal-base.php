<?php
/**
 * Smart Insights Page Signal Base Class
 *
 * Shared deterministic helpers for Free page-level Smart Insights signals.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Page Signal Base Class.
 *
 * Child classes keep their detection rules isolated while this base class
 * normalizes payload shape, scoring, evidence, and Free/Pro boundaries.
 *
 * @since 1.3.3
 */
abstract class Opti_Behavior_Smart_Insights_Page_Signal_Base {

	/**
	 * Scoring utilities.
	 *
	 * @var Opti_Behavior_Smart_Insights_Scorer
	 */
	protected $scorer;

	/**
	 * Recommendation library.
	 *
	 * @var Opti_Behavior_Smart_Insights_Recommendations
	 */
	protected $recommendations;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Smart_Insights_Scorer|null           $scorer          Scorer dependency.
	 * @param Opti_Behavior_Smart_Insights_Recommendations|null $recommendations Recommendation dependency.
	 */
	public function __construct( $scorer = null, $recommendations = null ) {
		$this->scorer          = $scorer instanceof Opti_Behavior_Smart_Insights_Scorer ? $scorer : new Opti_Behavior_Smart_Insights_Scorer();
		$this->recommendations = $recommendations instanceof Opti_Behavior_Smart_Insights_Recommendations ? $recommendations : new Opti_Behavior_Smart_Insights_Recommendations();
	}

	/**
	 * Return signal configuration.
	 *
	 * @return array
	 */
	abstract protected function get_signal_config();

	/**
	 * Detect whether the signal should trigger.
	 *
	 * @param array $metrics   Entity metrics.
	 * @param array $baselines Site baselines.
	 * @param array $args      Optional args.
	 * @return array
	 */
	abstract public function detect( $metrics, $baselines, $args = array() );

	/**
	 * Get rule metadata.
	 *
	 * @return array
	 */
	public function get_definition() {
		$config = wp_parse_args(
			$this->get_signal_config(),
			array(
				'signal_id'                  => '',
				'signal_name'                => '',
				'category'                   => 'UX/CRO',
				'entity_type'                => 'page',
				'availability'               => 'free',
				'visibility_tier'            => 'free',
				'rule_version'               => '1.0.0',
				'recommendation_template_id' => '',
				'thresholds'                 => array(),
			)
		);

		return array(
			'signal_id'                  => sanitize_key( $config['signal_id'] ),
			'signal_name'                => $config['signal_name'],
			'category'                   => $config['category'],
			'entity_type'                => sanitize_key( $config['entity_type'] ),
			'availability'               => sanitize_key( $config['availability'] ),
			'visibility_tier'            => sanitize_key( $config['visibility_tier'] ),
			'rule_version'               => $config['rule_version'],
			'recommendation_template_id' => sanitize_key( $config['recommendation_template_id'] ),
			'thresholds'                 => $config['thresholds'],
		);
	}

	/**
	 * The metric the "Where is the problem?" section compares segments on.
	 *
	 * A signal is the only thing that knows which number it fired on, so it -
	 * not the segment matrix - decides what a segment split should measure.
	 * Signals may declare `primary_segment_metric` in their config; otherwise
	 * the shared map below answers, and an empty return lets the segment matrix
	 * fall back to its own signal_id map.
	 *
	 * @since 1.4.0
	 *
	 * @return string One of bounce_rate|exit_rate|avg_scroll|avg_time|cta_click_rate, or ''.
	 */
	public function get_primary_segment_metric() {
		$config = $this->get_signal_config();
		if ( ! empty( $config['primary_segment_metric'] ) ) {
			return sanitize_key( $config['primary_segment_metric'] );
		}

		$signal_id = isset( $config['signal_id'] ) ? sanitize_key( $config['signal_id'] ) : '';
		$map       = array(
			'basic_bounce_alert'              => 'bounce_rate',
			'visitor_confusion_pattern'       => 'bounce_rate',
			'ab_test_opportunity'             => 'bounce_rate',
			'session_recording_opportunity'   => 'bounce_rate',
			'traffic_spike_observation'       => 'bounce_rate',
			'high_exit_rate_page'             => 'exit_rate',
			'quick_exit_pattern'              => 'exit_rate',
			'low_scroll_depth_important_page' => 'avg_scroll',
			'engagement_decay'                => 'avg_time',
			'high_traffic_low_engagement'     => 'avg_time',
			'product_page_engagement_issue'   => 'avg_time',
			'poor_conversion_rate'            => 'cta_click_rate',
			'conversion_drop_alert'           => 'cta_click_rate',
			'cta_low_performance'             => 'cta_click_rate',
		);

		return isset( $map[ $signal_id ] ) ? $map[ $signal_id ] : '';
	}

	/**
	 * Evaluate the signal and return a complete insight object when triggered.
	 *
	 * @param array $metrics    Entity metrics.
	 * @param array $baselines  Site baselines.
	 * @param array $date_range Date range.
	 * @param array $args       Optional args.
	 * @return array|null
	 */
	public function evaluate( $metrics, $baselines, $date_range, $args = array() ) {
		$detection = $this->detect( $metrics, $baselines, $args );
		if ( empty( $detection['triggered'] ) ) {
			return null;
		}

		$config     = $this->get_definition();
		$metrics    = $this->normalize_metrics( $metrics );
		$baselines  = $this->normalize_baselines( $baselines );
		$date_range = $this->normalize_date_range( $date_range );
		$scores     = $this->calculate_scores( $metrics, $baselines, $detection, $args );
		$template   = $this->recommendations->get_template(
			$config['recommendation_template_id'],
			array(
				'metrics'   => $metrics,
				'baselines' => $baselines,
				'detection' => $detection,
				'scores'    => $scores,
				'signal'    => $config,
			)
		);

		$availability = apply_filters(
			'opti_behavior_smart_insights_availability',
			$config['availability'],
			$config['signal_id'],
			array(
				'metrics'   => $metrics,
				'baselines' => $baselines,
			)
		);

		$interpretation = isset( $template['interpretation'] ) ? (string) $template['interpretation'] : '';

		$insight = array(
			'signal_id'           => $config['signal_id'],
			'signal_name'         => $config['signal_name'],
			'category'            => $config['category'],
			'entity_type'         => $config['entity_type'],
			'entity_id'           => $this->get_entity_id( $metrics ),
			'entity_label'        => $this->get_entity_label( $metrics ),
			'date_from'           => $date_range['from'],
			'date_to'             => $date_range['to'],
			'date_range'          => $date_range,
			'metrics'             => $this->build_metrics_evidence( $metrics, $baselines ),
			'detection'           => $detection,
			'scores'              => $scores,
			'trend'               => isset( $metrics['trend'] ) && is_array( $metrics['trend'] ) ? $metrics['trend'] : array(),
			'interpretation'      => $interpretation,
			'explanation'         => $interpretation,
			'why_it_matters'      => isset( $template['why_it_matters'] ) ? $template['why_it_matters'] : '',
			'likely_causes'       => isset( $template['likely_causes'] ) ? $template['likely_causes'] : array(),
			'recommended_actions' => isset( $template['recommended_actions'] ) ? $template['recommended_actions'] : array(),
			'related_reports'     => isset( $template['related_reports'] ) ? $template['related_reports'] : array(),
			'availability'        => sanitize_key( $availability ),
			'source_plugin'       => 'free',
			'visibility_tier'     => $config['visibility_tier'],
			'group_key'           => $config['signal_id'] . ':' . $config['entity_type'] . ':' . md5( $this->get_entity_id( $metrics ) ),
			'status'              => 'new',
			'rule_version'        => $config['rule_version'],
			'last_seen_at'        => $this->get_last_seen_at( $metrics ),
		);

		if ( ! empty( $template['upgrade_preview'] ) ) {
			$insight['upgrade_preview'] = $template['upgrade_preview'];
		}

		return apply_filters( 'opti_behavior_smart_insights_signal_insight', $insight, $metrics, $baselines, $config );
	}

	/**
	 * Alias for generator readability.
	 *
	 * @param array $metrics    Entity metrics.
	 * @param array $baselines  Site baselines.
	 * @param array $date_range Date range.
	 * @param array $args       Optional args.
	 * @return array|null
	 */
	public function evaluate_page( $metrics, $baselines, $date_range, $args = array() ) {
		return $this->evaluate( $metrics, $baselines, $date_range, $args );
	}

	/**
	 * Normalize metric keys used by Free signals.
	 *
	 * @param array $metrics Raw metrics.
	 * @return array
	 */
	protected function normalize_metrics( $metrics ) {
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
	 * Normalize baseline keys.
	 *
	 * @param array $baselines Raw baselines.
	 * @return array
	 */
	protected function normalize_baselines( $baselines ) {
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
	 * Calculate scores using optional detection overrides.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $baselines Baselines.
	 * @param array $detection Detection.
	 * @param array $args      Args.
	 * @return array
	 */
	protected function calculate_scores( $metrics, $baselines, $detection, $args = array() ) {
		$scoring_detection = $detection;
		if ( isset( $detection['severity_gap'] ) ) {
			$scoring_detection['bounce_gap'] = (float) $detection['severity_gap'];
		}
		if ( isset( $detection['opportunity_gap'] ) ) {
			$scoring_detection['scroll_gap'] = -abs( (float) $detection['opportunity_gap'] );
		}

		$score_args = array(
			'adaptive_low_traffic'       => ! empty( $detection['adaptive_low_traffic'] ),
			'same_issue_previous_period' => ! empty( $args['same_issue_previous_period'] ) || ! empty( $detection['same_issue_previous_period'] ),
			'tracking_data_complete'     => ! empty( $metrics['tracking_data_complete'] ) || ! empty( $baselines['tracking_data_complete'] ),
		);

		$scores = $this->scorer->calculate_scores( $metrics, $baselines, $scoring_detection, $score_args );
		if ( isset( $detection['priority_ceiling'] ) ) {
			$scores['priority_score'] = min( (int) $scores['priority_score'], (int) $detection['priority_ceiling'] );
			$scores['priority_label'] = $this->scorer->get_priority_label( $scores['priority_score'] );
		}

		return $scores;
	}

	/**
	 * Build metrics evidence with baseline comparison.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $baselines Baselines.
	 * @return array
	 */
	protected function build_metrics_evidence( $metrics, $baselines ) {
		$evidence = array_merge( $metrics, $baselines );

		$evidence['signal_metrics'] = array(
			'entity_type'               => $metrics['entity_type'],
			'entity_id'                 => $this->get_entity_id( $metrics ),
			'sessions'                  => $metrics['sessions'],
			'users'                     => $metrics['users'],
			'pageviews'                 => $metrics['pageviews'],
			'bounce_rate'               => $metrics['bounce_rate'],
			'site_avg_bounce_rate'      => $baselines['site_avg_bounce_rate'],
			'avg_scroll_depth'          => $metrics['avg_scroll_depth'],
			'site_avg_scroll_depth'     => $baselines['site_avg_scroll_depth'],
			'avg_time_on_page'          => $metrics['avg_time_on_page'],
			'site_avg_time_on_page'     => $baselines['site_avg_time_on_page'],
			'exit_rate'                 => $metrics['exit_rate'],
			'site_avg_exit_rate'        => $baselines['site_avg_exit_rate'],
			'conversion_rate'           => $metrics['conversion_rate'],
			'site_avg_conversion_rate'  => $baselines['site_avg_conversion_rate'],
			'cta_click_rate'            => $metrics['cta_click_rate'],
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
	 * Calculate a numeric gap.
	 *
	 * @param mixed $left  Left value.
	 * @param mixed $right Right value.
	 * @return float|null
	 */
	protected function calculate_gap( $left, $right ) {
		if ( ! $this->is_metric_available( $left ) || ! $this->is_metric_available( $right ) ) {
			return null;
		}

		return round( (float) $left - (float) $right, 2 );
	}

	/**
	 * Whether a metric value is usable.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected function is_metric_available( $value ) {
		return null !== $value && '' !== $value && is_numeric( $value );
	}

	/**
	 * Normalize a number or null-like value.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	protected function normalize_nullable_number( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return round( (float) $value, 2 );
	}

	/**
	 * Normalize a date range.
	 *
	 * @param array $date_range Date range.
	 * @return array
	 */
	protected function normalize_date_range( $date_range ) {
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
	 * Get entity ID for the insight.
	 *
	 * @param array $metrics Metrics.
	 * @return string
	 */
	protected function get_entity_id( $metrics ) {
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
	 * @param array $metrics Metrics.
	 * @return string
	 */
	protected function get_entity_label( $metrics ) {
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
	 * @param array $metrics Metrics.
	 * @return string
	 */
	protected function get_last_seen_at( $metrics ) {
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
	protected function normalize_date( $date ) {
		$timestamp = strtotime( (string) $date );

		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : gmdate( 'Y-m-d' );
	}
}
