<?php
/**
 * Smart Insights Weekly Summary Class
 *
 * Free half of the Weekly CRO Summary since OPT-404 (WordPress.org guideline
 * 5): Free shows the locked invitation only. The brief itself (grouped issues,
 * wins, worsening issues, next action, export rows) is built by the Pro
 * subclass OptiBehavior_Pro_Smart_Insights_Weekly_Summary, handed over through
 * the `opti_behavior_smart_insights_weekly_summary_class` filter (see create()).
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Weekly Summary Class.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Weekly_Summary {

	/**
	 * Insight repository.
	 *
	 * @var Opti_Behavior_Smart_Insights_Repository
	 */
	protected $repository;

	/**
	 * Capability resolver.
	 *
	 * @var Opti_Behavior_Smart_Insights_Capabilities
	 */
	protected $capabilities;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Smart_Insights_Repository|null   $repository   Repository override.
	 * @param Opti_Behavior_Smart_Insights_Capabilities|null $capabilities Capability override.
	 */
	public function __construct( $repository = null, $capabilities = null ) {
		$this->repository   = $repository instanceof Opti_Behavior_Smart_Insights_Repository ? $repository : new Opti_Behavior_Smart_Insights_Repository();
		$this->capabilities = $capabilities instanceof Opti_Behavior_Smart_Insights_Capabilities ? $capabilities : new Opti_Behavior_Smart_Insights_Capabilities();
	}

	/**
	 * Summary builder for the current viewer.
	 *
	 * The brief is Pro since OPT-404: Pro hands its subclass through the filter
	 * below, applied only for a viewer with Pro access (or $force_pro, used by
	 * exports and tests). The class must extend this one.
	 *
	 * @since 1.10.0
	 * @param Opti_Behavior_Smart_Insights_Repository|null   $repository   Repository override.
	 * @param Opti_Behavior_Smart_Insights_Capabilities|null $capabilities Capability override.
	 * @param bool                                           $force_pro    Ask for the Pro builder whatever the viewer.
	 * @return Opti_Behavior_Smart_Insights_Weekly_Summary
	 */
	public static function create( $repository = null, $capabilities = null, $force_pro = false ) {
		$class = __CLASS__;
		$caps  = $capabilities instanceof Opti_Behavior_Smart_Insights_Capabilities ? $capabilities : new Opti_Behavior_Smart_Insights_Capabilities();

		if ( $force_pro || $caps->has_pro_access() ) {
			/**
			 * Filter the weekly summary builder class (Pro access only).
			 *
			 * @since 1.10.0
			 *
			 * @param string $class Builder class; must extend this one.
			 */
			$candidate = apply_filters( 'opti_behavior_smart_insights_weekly_summary_class', $class );
			if ( is_string( $candidate ) && class_exists( $candidate ) && is_subclass_of( $candidate, $class ) ) {
				$class = $candidate;
			}
		}

		return new $class( $repository, $caps );
	}

	/**
	 * Build a weekly CRO summary for a date range.
	 *
	 * Free returns the locked invitation. A Pro builder fills
	 * build_full_summary() for a viewer with Pro access.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $args       Optional args.
	 * @return array
	 */
	public function get_summary( $start_date = '', $end_date = '', $args = array() ) {
		$defaults = array(
			'context'      => 'center',
			'force_full'   => false,
			'limit'        => 500,
			'exclude_spam' => null,
		);
		$args     = wp_parse_args( $args, $defaults );
		$range    = $this->normalize_range( $start_date, $end_date );
		$can_view = ! empty( $args['force_full'] ) || $this->capabilities->has_pro_access();

		$summary = $can_view ? $this->build_full_summary( $range, $args ) : null;
		if ( ! is_array( $summary ) ) {
			return $this->locked_preview( $range, $args );
		}

		/**
		 * Filter the deterministic weekly summary for Pro reporting.
		 *
		 * @param array $summary Summary payload.
		 * @param array $args    Summary args.
		 * @param self  $builder Builder instance.
		 */
		return apply_filters( 'opti_behavior_smart_insights_weekly_summary', $summary, $args, $this );
	}

	/**
	 * Full brief. Empty in Free; filled by the Pro subclass.
	 *
	 * @since 1.10.0
	 * @param array $range Normalized date range.
	 * @param array $args  Summary args.
	 * @return array|null Null = show the locked invitation.
	 */
	protected function build_full_summary( $range, $args ) {
		return null;
	}

	/**
	 * Convert a summary into safe export rows. Free has none (Pro overrides).
	 *
	 * @param array $summary Summary payload.
	 * @return array
	 */
	public function export_rows( $summary ) {
		return array();
	}

	/**
	 * Build a locked Free preview.
	 *
	 * @param array $range Date range.
	 * @param array $args  Args.
	 * @return array
	 */
	private function locked_preview( $range, $args ) {
		return array(
			'title'             => __( 'Weekly CRO Summary', 'opti-behavior' ),
			'visibility_tier'   => 'pro_locked',
			'is_locked_preview' => true,
			'has_pro_access'    => false,
			'date_range'        => $range,
			'headline'          => __( 'Unlock the weekly CRO summary in Pro.', 'opti-behavior' ),
			'summary_text'      => __( 'Pro groups recurring issues, highlights wins and worsening patterns, and recommends the highest-impact next action from your stored Smart Insights.', 'opti-behavior' ),
			'locked_preview'    => array(
				'title'       => __( 'Advanced weekly diagnosis available in Pro', 'opti-behavior' ),
				'description' => __( 'Upgrade to see recurring issues, biggest improvement, biggest decline, export-ready report rows, and historical monitoring.', 'opti-behavior' ),
				'context'     => isset( $args['context'] ) ? sanitize_key( $args['context'] ) : 'center',
			),
		);
	}

	/**
	 * Normalize a date range, defaulting to the last 7 days.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	protected function normalize_range( $start_date, $end_date ) {
		$today = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
		$from  = $this->normalize_date( $start_date );
		$to    = $this->normalize_date( $end_date );

		if ( ! $from || ! $to ) {
			$to   = $today;
			$from = gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );
		}

		if ( strtotime( $from ) > strtotime( $to ) ) {
			$tmp  = $from;
			$from = $to;
			$to   = $tmp;
		}

		return array(
			'from'  => $from,
			'to'    => $to,
			'label' => 'weekly',
		);
	}

	/**
	 * Previous equivalent range.
	 *
	 * @param array $range Current range.
	 * @return array
	 */
	protected function previous_range( $range ) {
		$from_ts = strtotime( $range['from'] );
		$to_ts   = strtotime( $range['to'] );
		$days    = max( 1, (int) floor( ( $to_ts - $from_ts ) / DAY_IN_SECONDS ) + 1 );

		return array(
			'from'  => gmdate( 'Y-m-d', strtotime( $range['from'] . ' -' . $days . ' days' ) ),
			'to'    => gmdate( 'Y-m-d', strtotime( $range['to'] . ' -' . $days . ' days' ) ),
			'label' => 'previous_weekly',
		);
	}

	/**
	 * Normalize a date.
	 *
	 * @param string $date Date.
	 * @return string
	 */
	protected function normalize_date( $date ) {
		$date = is_string( $date ) ? trim( $date ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		$timestamp = strtotime( $date );
		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}
}
