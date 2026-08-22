<?php
/**
 * Smart Insights Trend Calculator Class
 *
 * Calculates current-vs-previous-period deltas for aggregated metrics.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Trend Calculator Class.
 *
 * Keeps trend math deterministic and reusable across Free and Pro providers.
 * Percent metrics are treated as point deltas; count metrics also include ratio
 * changes. No raw visitor-level data is stored in returned payloads.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Trend_Calculator {

	/**
	 * Build the previous equivalent period for a current range.
	 *
	 * @param string $start_date Current start date/datetime.
	 * @param string $end_date   Current end date/datetime.
	 * @return array
	 */
	public function get_previous_period_range( $start_date, $end_date ) {
		$start = $this->normalize_date( $start_date );
		$end   = $this->normalize_date( $end_date );

		if ( ! $start || ! $end ) {
			return array(
				'from' => '',
				'to'   => '',
			);
		}

		$start_ts = strtotime( $start . ' 00:00:00' );
		$end_ts   = strtotime( $end . ' 00:00:00' );
		if ( $start_ts > $end_ts ) {
			$tmp      = $start_ts;
			$start_ts = $end_ts;
			$end_ts   = $tmp;
		}

		$days        = max( 1, (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1 );
		$previous_to = strtotime( '-1 day', $start_ts );

		return array(
			'from' => gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', $previous_to ) ),
			'to'   => gmdate( 'Y-m-d', $previous_to ),
			'days' => $days,
		);
	}

	/**
	 * Calculate a trend payload between two metric arrays.
	 *
	 * @param array $current       Current metrics.
	 * @param array $previous      Previous metrics.
	 * @param array $metric_keys   Metric keys to compare.
	 * @param array $percent_keys  Metrics whose absolute delta is a percentage-point delta.
	 * @return array
	 */
	public function compare_metrics( $current, $previous, $metric_keys = array(), $percent_keys = array() ) {
		$current      = is_array( $current ) ? $current : array();
		$previous     = is_array( $previous ) ? $previous : array();
		$metric_keys  = $metric_keys ? $metric_keys : array_keys( array_merge( $current, $previous ) );
		$percent_keys = array_fill_keys( $percent_keys, true );
		$comparisons  = array();

		foreach ( $metric_keys as $key ) {
			$current_value  = $this->numeric_or_null( isset( $current[ $key ] ) ? $current[ $key ] : null );
			$previous_value = $this->numeric_or_null( isset( $previous[ $key ] ) ? $previous[ $key ] : null );

			if ( null === $current_value && null === $previous_value ) {
				continue;
			}

			$absolute_delta = ( null !== $current_value && null !== $previous_value ) ? round( $current_value - $previous_value, 2 ) : null;
			$relative_delta = null;
			if ( null !== $current_value && null !== $previous_value && 0.0 !== (float) $previous_value ) {
				$relative_delta = round( ( ( $current_value - $previous_value ) / abs( $previous_value ) ) * 100, 2 );
			}

			$comparisons[ $key ] = array(
				'current'             => $current_value,
				'previous'            => $previous_value,
				'absolute_delta'      => $absolute_delta,
				'relative_delta_pct'  => $relative_delta,
				'is_percentage_metric'=> isset( $percent_keys[ $key ] ),
				'direction'           => $this->get_direction( $absolute_delta ),
			);
		}

		return $comparisons;
	}

	/**
	 * Attach trend payloads to current entity rows by matching previous rows.
	 *
	 * @param array  $current_rows Current rows.
	 * @param array  $previous_rows Previous rows.
	 * @param string $entity_key Entity key used to match rows.
	 * @param array  $metric_keys Metric keys to compare.
	 * @param array  $percent_keys Percentage metric keys.
	 * @return array
	 */
	public function attach_entity_trends( $current_rows, $previous_rows, $entity_key, $metric_keys = array(), $percent_keys = array() ) {
		$previous_by_key = array();
		foreach ( (array) $previous_rows as $row ) {
			if ( is_array( $row ) && isset( $row[ $entity_key ] ) ) {
				$previous_by_key[ (string) $row[ $entity_key ] ] = $row;
			}
		}

		foreach ( $current_rows as $index => $row ) {
			$key      = isset( $row[ $entity_key ] ) ? (string) $row[ $entity_key ] : '';
			$previous = isset( $previous_by_key[ $key ] ) ? $previous_by_key[ $key ] : array();

			$current_rows[ $index ]['previous_period_metrics'] = $previous;
			$current_rows[ $index ]['trend']                   = $this->compare_metrics( $row, $previous, $metric_keys, $percent_keys );
		}

		return $current_rows;
	}

	/**
	 * Normalize a date string to Y-m-d.
	 *
	 * @param string $date Date string.
	 * @return string
	 */
	private function normalize_date( $date ) {
		$date = is_string( $date ) ? trim( $date ) : '';
		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		$timestamp = strtotime( $date );
		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}

	/**
	 * Convert numeric values and leave missing values as null.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private function numeric_or_null( $value ) {
		return is_numeric( $value ) ? round( (float) $value, 2 ) : null;
	}

	/**
	 * Get a stable direction label.
	 *
	 * @param float|null $delta Absolute delta.
	 * @return string
	 */
	private function get_direction( $delta ) {
		if ( null === $delta || 0.0 === (float) $delta ) {
			return 'flat';
		}

		return $delta > 0 ? 'up' : 'down';
	}
}
