<?php
/**
 * Canonical stats date range resolver.
 *
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides one date-range contract for all Opti-Behavior reporting surfaces.
 */
class Opti_Behavior_Stats_Date_Range {

	/**
	 * Resolve a reporting period into display and SQL boundaries.
	 *
	 * UI dates are inclusive calendar days. SQL dates use a half-open interval:
	 * sql_start <= timestamp < sql_end.
	 *
	 * @param string $period     Period key.
	 * @param string $start_date Optional custom start date (Y-m-d).
	 * @param string $end_date   Optional custom end date (Y-m-d).
	 * @param array  $args       Optional resolver args.
	 * @return array
	 */
	public static function resolve( $period = 'last7days', $start_date = '', $end_date = '', $args = array() ) {
		$period = self::normalize_period( $period );
		$tz     = wp_timezone();
		$today  = new DateTimeImmutable( current_time( 'Y-m-d' ), $tz );

		$start = null;
		$end   = $today;

		switch ( $period ) {
			case 'today':
				$start = $today;
				break;

			case 'yesterday':
				$start = $today->modify( '-1 day' );
				$end   = $start;
				break;

			case 'last14days':
				$start = $today->modify( '-13 days' );
				break;

			case 'last30days':
				$start = $today->modify( '-29 days' );
				break;

			case 'last90days':
				$start = $today->modify( '-89 days' );
				break;

			case 'thismonth':
				$start = $today->modify( 'first day of this month' );
				break;

			case 'lastmonth':
				$start = $today->modify( 'first day of last month' );
				$end   = $today->modify( 'last day of last month' );
				break;

			case 'custom':
				$start = self::date_from_string( $start_date, $tz );
				$end   = self::date_from_string( $end_date, $tz );
				if ( ! $start || ! $end ) {
					$start = $today->modify( '-6 days' );
					$end   = $today;
				}
				break;

			case 'last7days':
			default:
				$start = $today->modify( '-6 days' );
				break;
		}

		if ( $start > $end ) {
			$tmp   = $start;
			$start = $end;
			$end   = $tmp;
		}

		$clamped           = false;
		$first_data_date   = isset( $args['first_data_date'] ) ? (string) $args['first_data_date'] : '';
		$allow_clamp       = ! empty( $args['clamp_to_first_data'] );
		$clampable_periods = array( 'last7days', 'last14days', 'last30days', 'last90days', 'today', 'thismonth' );

		if ( $allow_clamp && $first_data_date && in_array( $period, $clampable_periods, true ) ) {
			$first = self::date_from_string( substr( $first_data_date, 0, 10 ), $tz );
			if ( $first && $first > $start && $first <= $end ) {
				$start   = $first;
				$clamped = true;
			}
		}

		$sql_start = $start->setTime( 0, 0, 0 );
		$sql_end   = $end->modify( '+1 day' )->setTime( 0, 0, 0 );

		return array(
			'period'           => $period,
			'start_date'       => $start->format( 'Y-m-d' ),
			'end_date'         => $end->format( 'Y-m-d' ),
			'start'            => $sql_start->format( 'Y-m-d H:i:s' ),
			'end'              => $sql_end->modify( '-1 second' )->format( 'Y-m-d H:i:s' ),
			'sql_start'        => $sql_start->format( 'Y-m-d H:i:s' ),
			'sql_end'          => $sql_end->format( 'Y-m-d H:i:s' ),
			'end_mode'         => 'exclusive',
			'timezone'         => $tz->getName(),
			'clamped'          => $clamped,
			'first_data_date'  => $first_data_date,
			'start_date_obj'   => $start,
			'end_date_obj'     => $end,
		);
	}

	/**
	 * Normalize period aliases used by different pages.
	 *
	 * @param string $period Period key.
	 * @return string
	 */
	public static function normalize_period( $period ) {
		$period = sanitize_key( (string) $period );

		$map = array(
			'7days'       => 'last7days',
			'last7days'   => 'last7days',
			'last7day'    => 'last7days',
			'last7'       => 'last7days',
			'last_7_days' => 'last7days',
			'lastweek'    => 'last7days',
			'14days'      => 'last14days',
			'last14days'  => 'last14days',
			'last_14_days' => 'last14days',
			'30days'      => 'last30days',
			'last30days'  => 'last30days',
			'last30'      => 'last30days',
			'last_30_days' => 'last30days',
			'90days'      => 'last90days',
			'last90days'  => 'last90days',
			'last_90_days' => 'last90days',
			'thismonth'   => 'thismonth',
			'this_month'  => 'thismonth',
			'currentmonth' => 'thismonth',
			'lastmonth'   => 'lastmonth',
			'last_month'  => 'lastmonth',
			'yesterday'   => 'yesterday',
			'today'       => 'today',
			'custom'      => 'custom',
		);

		return isset( $map[ $period ] ) ? $map[ $period ] : 'last7days';
	}

	/**
	 * Build a DateTimeImmutable from a Y-m-d-ish string.
	 *
	 * @param string       $value Date string.
	 * @param DateTimeZone $tz    Timezone.
	 * @return DateTimeImmutable|null
	 */
	private static function date_from_string( $value, $tz ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( substr( $value, 0, 10 ), $tz );
		} catch ( Exception $e ) {
			return null;
		}
	}
}
