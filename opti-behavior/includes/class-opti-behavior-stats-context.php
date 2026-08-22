<?php
/**
 * Canonical stats context value object.
 *
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates normalized metric context arrays used across stats pages.
 */
class Opti_Behavior_Stats_Context {

	/**
	 * Create a normalized context.
	 *
	 * @param array $args Context args.
	 * @return array
	 */
	public static function create( $args = array() ) {
		$defaults = array(
			'period'       => 'last7days',
			'start_date'   => '',
			'end_date'     => '',
			'source_scope' => 'all_sessions',
			'metric_grain' => 'session',
			'feature'      => 'dashboard',
		);

		$args  = wp_parse_args( $args, $defaults );
		$range = Opti_Behavior_Stats_Date_Range::resolve( $args['period'], $args['start_date'], $args['end_date'], $args );

		return array_merge(
			$range,
			array(
				'exclude_spam'           => array_key_exists( 'exclude_spam', $args ) ? (bool) $args['exclude_spam'] : Opti_Behavior_Stats_Spam_Filter::is_enabled(),
				'excluded_traffic_types' => Opti_Behavior_Stats_Spam_Filter::excluded_traffic_types(),
				'source_scope'           => sanitize_key( $args['source_scope'] ),
				'metric_grain'           => sanitize_key( $args['metric_grain'] ),
				'feature'                => sanitize_key( $args['feature'] ),
			)
		);
	}

	/**
	 * Build a stable cache key fragment for a context.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	public static function cache_key( $context ) {
		$parts = array(
			isset( $context['period'] ) ? $context['period'] : '',
			isset( $context['sql_start'] ) ? $context['sql_start'] : '',
			isset( $context['sql_end'] ) ? $context['sql_end'] : '',
			! empty( $context['exclude_spam'] ) ? 'exclude' : 'include',
			class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::cache_key( ! empty( $context['exclude_spam'] ) ) : '',
			isset( $context['source_scope'] ) ? $context['source_scope'] : '',
			isset( $context['metric_grain'] ) ? $context['metric_grain'] : '',
		);

		return md5( implode( '|', $parts ) );
	}
}
