<?php
/**
 * Smart Insights Weekly Summary Class
 *
 * Builds a deterministic, aggregate-only Weekly CRO Summary from stored
 * Smart Insight rows. Free viewers receive a locked teaser; Pro viewers get
 * grouped issues, wins, worsening issues, and the next recommended action.
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
	private $repository;

	/**
	 * Capability resolver.
	 *
	 * @var Opti_Behavior_Smart_Insights_Capabilities
	 */
	private $capabilities;

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
	 * Build a weekly CRO summary for a date range.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $args       Optional args.
	 * @return array
	 */
	public function get_summary( $start_date = '', $end_date = '', $args = array() ) {
		$defaults = array(
			'context'    => 'center',
			'force_full' => false,
			'limit'      => 500,
			'exclude_spam' => null,
		);
		$args     = wp_parse_args( $args, $defaults );
		$range    = $this->normalize_range( $start_date, $end_date );
		$can_view = ! empty( $args['force_full'] ) || $this->capabilities->has_pro_access();

		if ( ! $can_view ) {
			return $this->locked_preview( $range, $args );
		}

		$current = $this->get_insights_for_range( $range, $args );
		$previous_range = $this->previous_range( $range );
		$previous = $this->get_insights_for_range( $previous_range, $args );
		$current = $this->capabilities->filter_insights_for_viewer( $current, 'list' );
		$previous = $this->capabilities->filter_insights_for_viewer( $previous, 'list' );

		$active = array_values(
			array_filter(
				$current,
				function( $insight ) {
					$status = isset( $insight['status'] ) ? sanitize_key( $insight['status'] ) : '';
					return in_array( $status, array( 'new', 'viewed', 'in_progress' ), true );
				}
			)
		);
		if ( method_exists( $this->repository, 'collapse_open_insights_by_group' ) ) {
			$active = $this->repository->collapse_open_insights_by_group( $active );
		}

		$top_critical      = $this->top_insights( $active, 3 );
		$biggest_improvement = $this->find_biggest_improvement( $current, $previous );
		$biggest_decline     = $this->find_biggest_decline( $current, $previous );
		$recurring_issues    = $this->build_recurring_issues( $active, $previous );
		$top_opportunity     = ! empty( $top_critical ) ? $top_critical[0] : null;
		$next_action         = $this->build_next_action( $top_opportunity );

		$summary = array(
			'title'             => __( 'Weekly CRO Summary', 'opti-behavior' ),
			'visibility_tier'   => 'pro',
			'is_locked_preview' => false,
			'has_pro_access'    => true,
			'date_range'        => $range,
			'previous_range'    => $previous_range,
			'generated_at'      => current_time( 'mysql' ),
			'headline'          => $this->build_headline( $active, $top_opportunity ),
			'scope_note'        => __( 'Counts use the same deduplicated active insight groups shown in the Smart Insights list for this selected range.', 'opti-behavior' ),
			'counts'            => array(
				'total'         => count( $active ),
				'active'        => count( $active ),
				'high_priority' => $this->count_priority_at_least( $active, 60 ),
				'critical'      => $this->count_priority_at_least( $active, 80 ),
				'recurring'     => count( $recurring_issues ),
			),
			'top_critical_insights' => $this->summarize_insights( $top_critical ),
			'biggest_improvement'   => $biggest_improvement,
			'biggest_decline'       => $biggest_decline,
			'recurring_issues'      => $recurring_issues,
			'top_cro_opportunity'   => $top_opportunity ? $this->summarize_insight( $top_opportunity ) : array(),
			'recommended_next_action' => $next_action,
			'monitoring'            => array(
				'uses_stored_insights' => true,
				'uses_trend_deltas'    => true,
				'privacy'              => __( 'Summary uses stored aggregate Smart Insight objects only; no visitor-level replay data is embedded.', 'opti-behavior' ),
			),
		);

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
	 * Convert a summary into safe export rows.
	 *
	 * @param array $summary Summary payload.
	 * @return array
	 */
	public function export_rows( $summary ) {
		if ( ! is_array( $summary ) || ! empty( $summary['is_locked_preview'] ) ) {
			return array();
		}

		$rows = array();
		$rows[] = $this->export_row( 'headline', '', isset( $summary['headline'] ) ? $summary['headline'] : '', '', '', '' );
		if ( ! empty( $summary['counts'] ) && is_array( $summary['counts'] ) ) {
			foreach ( $summary['counts'] as $key => $value ) {
				$rows[] = $this->export_row( 'count', $key, $value, '', '', '' );
			}
		}

		foreach ( array( 'top_critical_insights', 'recurring_issues' ) as $section ) {
			if ( empty( $summary[ $section ] ) || ! is_array( $summary[ $section ] ) ) {
				continue;
			}
			foreach ( $summary[ $section ] as $item ) {
				$rows[] = $this->export_row(
					$section,
					isset( $item['label'] ) ? $item['label'] : ( isset( $item['entity_label'] ) ? $item['entity_label'] : '' ),
					isset( $item['summary'] ) ? $item['summary'] : ( isset( $item['signal_name'] ) ? $item['signal_name'] : '' ),
					isset( $item['priority_score'] ) ? $item['priority_score'] : '',
					isset( $item['confidence_label'] ) ? $item['confidence_label'] : '',
					isset( $item['status'] ) ? $item['status'] : ''
				);
			}
		}

		foreach ( array( 'biggest_improvement', 'biggest_decline', 'top_cro_opportunity', 'recommended_next_action' ) as $section ) {
			if ( empty( $summary[ $section ] ) || ! is_array( $summary[ $section ] ) ) {
				continue;
			}
			$rows[] = $this->export_row(
				$section,
				isset( $summary[ $section ]['label'] ) ? $summary[ $section ]['label'] : ( isset( $summary[ $section ]['entity_label'] ) ? $summary[ $section ]['entity_label'] : '' ),
				isset( $summary[ $section ]['summary'] ) ? $summary[ $section ]['summary'] : ( isset( $summary[ $section ]['action'] ) ? $summary[ $section ]['action'] : '' ),
				isset( $summary[ $section ]['priority_score'] ) ? $summary[ $section ]['priority_score'] : '',
				isset( $summary[ $section ]['confidence_label'] ) ? $summary[ $section ]['confidence_label'] : '',
				isset( $summary[ $section ]['status'] ) ? $summary[ $section ]['status'] : ''
			);
		}

		return $rows;
	}

	/**
	 * Create one export row.
	 *
	 * @param string $section          Section.
	 * @param string $label            Label.
	 * @param string $summary          Summary.
	 * @param string $priority_score   Priority.
	 * @param string $confidence_label Confidence.
	 * @param string $status           Status.
	 * @return array
	 */
	private function export_row( $section, $label, $summary, $priority_score, $confidence_label, $status ) {
		return array(
			'section'          => sanitize_key( $section ),
			'label'            => sanitize_text_field( (string) $label ),
			'summary'          => wp_strip_all_tags( (string) $summary ),
			'priority_score'   => is_numeric( $priority_score ) ? (int) $priority_score : '',
			'confidence_label' => sanitize_text_field( (string) $confidence_label ),
			'status'           => sanitize_key( (string) $status ),
		);
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
	 * Get stored insights for a date range.
	 *
	 * @param array $range Range.
	 * @param array $args  Args.
	 * @return array
	 */
	private function get_insights_for_range( $range, $args ) {
		if ( ! $this->repository || ! method_exists( $this->repository, 'get_insights' ) ) {
			return array();
		}

		return $this->repository->get_insights(
			array(
				'date_from'          => $range['from'],
				'date_to'            => $range['to'],
				'include_suppressed' => false,
				'limit'              => isset( $args['limit'] ) ? absint( $args['limit'] ) : 500,
				'spam_scope'         => $this->get_spam_scope_key( $args['exclude_spam'] ?? null ),
				'orderby'            => 'updated_at',
				'order'              => 'DESC',
			)
		);
	}

	/**
	 * Convert an explicit spam exclusion state into the persisted insight scope key.
	 *
	 * @param bool|null $exclude_spam Explicit spam exclusion state.
	 * @return string|null
	 */
	private function get_spam_scope_key( $exclude_spam = null ) {
		if ( null === $exclude_spam ) {
			return null;
		}

		return $exclude_spam ? 'exclude_spam_1' : 'exclude_spam_0';
	}

	/**
	 * Return top insights by priority.
	 *
	 * @param array $insights Insights.
	 * @param int   $limit    Limit.
	 * @return array
	 */
	private function top_insights( $insights, $limit ) {
		usort(
			$insights,
			function( $a, $b ) {
				return $this->priority_score( $b ) <=> $this->priority_score( $a );
			}
		);

		return array_slice( $insights, 0, max( 1, absint( $limit ) ) );
	}

	/**
	 * Count priority at or above a threshold.
	 *
	 * @param array $insights Insights.
	 * @param int   $minimum  Minimum score.
	 * @return int
	 */
	private function count_priority_at_least( $insights, $minimum ) {
		$count = 0;
		foreach ( $insights as $insight ) {
			if ( $this->priority_score( $insight ) >= (int) $minimum ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Build recurring issue groups.
	 *
	 * @param array $current  Current insights.
	 * @param array $previous Previous insights.
	 * @return array
	 */
	private function build_recurring_issues( $current, $previous ) {
		$groups = array();
		foreach ( array_merge( $previous, $current ) as $insight ) {
			$key = $this->group_key( $insight );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'count'     => 0,
					'max_score' => 0,
					'current'   => null,
					'label'     => isset( $insight['entity_label'] ) ? $insight['entity_label'] : '',
					'summary'   => isset( $insight['signal_name'] ) ? $insight['signal_name'] : '',
				);
			}
			$groups[ $key ]['count']++;
			$score = $this->priority_score( $insight );
			if ( $score >= $groups[ $key ]['max_score'] ) {
				$groups[ $key ]['max_score'] = $score;
				$groups[ $key ]['current']   = $insight;
			}
		}

		$recurring = array();
		foreach ( $groups as $group ) {
			if ( $group['count'] < 2 || empty( $group['current'] ) ) {
				continue;
			}
			$item = $this->summarize_insight( $group['current'] );
			$item['recurrence_count'] = (int) $group['count'];
			$recurring[] = $item;
		}

		usort(
			$recurring,
			function( $a, $b ) {
				return (int) $b['priority_score'] <=> (int) $a['priority_score'];
			}
		);

		return array_slice( $recurring, 0, 5 );
	}

	/**
	 * Find biggest improvement.
	 *
	 * @param array $current  Current insights.
	 * @param array $previous Previous insights.
	 * @return array
	 */
	private function find_biggest_improvement( $current, $previous ) {
		$closed = array_values(
			array_filter(
				$current,
				function( $insight ) {
					$status = isset( $insight['status'] ) ? sanitize_key( $insight['status'] ) : '';
					return in_array( $status, array( 'resolved', 'auto_resolved' ), true );
				}
			)
		);
		if ( ! empty( $closed ) ) {
			$top = $this->top_insights( $closed, 1 );
			$item = $this->summarize_insight( $top[0] );
			$item['summary'] = sprintf( /* translators: %s: Insight label. */ __( '%s is now marked resolved or auto-resolved.', 'opti-behavior' ), $item['entity_label'] );
			return $item;
		}

		return $this->trend_extreme( $current, $previous, 'improvement' );
	}

	/**
	 * Find biggest decline.
	 *
	 * @param array $current  Current insights.
	 * @param array $previous Previous insights.
	 * @return array
	 */
	private function find_biggest_decline( $current, $previous ) {
		$worsened = array_values(
			array_filter(
				$current,
				function( $insight ) {
					return ! empty( $insight['detection']['worsened'] );
				}
			)
		);
		if ( ! empty( $worsened ) ) {
			$top = $this->top_insights( $worsened, 1 );
			$item = $this->summarize_insight( $top[0] );
			$item['summary'] = sprintf( /* translators: %s: Insight label. */ __( '%s worsened compared with the last stored detection.', 'opti-behavior' ), $item['entity_label'] );
			return $item;
		}

		return $this->trend_extreme( $current, $previous, 'decline' );
	}

	/**
	 * Find strongest trend extreme.
	 *
	 * @param array  $current  Current insights.
	 * @param array  $previous Previous insights.
	 * @param string $mode     improvement|decline.
	 * @return array
	 */
	private function trend_extreme( $current, $previous, $mode ) {
		$best = null;
		$best_score = 0;
		foreach ( $current as $insight ) {
			$trend_score = $this->trend_health_score( isset( $insight['trend'] ) ? $insight['trend'] : array() );
			if ( 'improvement' === $mode ) {
				$trend_score *= -1;
			}
			if ( $trend_score > $best_score ) {
				$best = $insight;
				$best_score = $trend_score;
			}
		}

		if ( $best ) {
			$item = $this->summarize_insight( $best );
			$item['summary'] = 'improvement' === $mode
				? __( 'Stored trend deltas point to the strongest improvement this week.', 'opti-behavior' )
				: __( 'Stored trend deltas point to the strongest worsening pattern this week.', 'opti-behavior' );
			return $item;
		}

		return array(
			'label'   => __( 'No clear change detected', 'opti-behavior' ),
			'summary' => 'improvement' === $mode ? __( 'No resolved or improving issue stands out yet.', 'opti-behavior' ) : __( 'No worsening issue stands out yet.', 'opti-behavior' ),
		);
	}

	/**
	 * Score trend health; positive means worsening, negative means improving.
	 *
	 * @param array $trend Trend payload.
	 * @return float
	 */
	private function trend_health_score( $trend ) {
		$score = 0.0;
		$bad_when_up = array( 'bounce_rate', 'exit_rate', 'dropoff_rate', 'abandonment_rate', 'error_rate' );
		$bad_when_down = array( 'avg_scroll_depth', 'avg_time_on_page', 'conversion_rate', 'completion_rate', 'cta_click_rate' );

		foreach ( is_array( $trend ) ? $trend : array() as $key => $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$delta = isset( $payload['relative_delta_pct'] ) ? (float) $payload['relative_delta_pct'] : ( isset( $payload['delta_pct'] ) ? (float) $payload['delta_pct'] : 0 );
			if ( in_array( $key, $bad_when_up, true ) ) {
				$score += $delta;
			}
			if ( in_array( $key, $bad_when_down, true ) ) {
				$score -= $delta;
			}
		}

		return $score;
	}

	/**
	 * Build the next action object.
	 *
	 * @param array|null $top_opportunity Top insight.
	 * @return array
	 */
	private function build_next_action( $top_opportunity ) {
		if ( ! is_array( $top_opportunity ) ) {
			return array(
				'label'   => __( 'Keep monitoring', 'opti-behavior' ),
				'action'  => __( 'No high-priority action stands out yet. Keep collecting data and review heatmaps, sources, and funnels.', 'opti-behavior' ),
				'summary' => __( 'No active critical insights were found for this period.', 'opti-behavior' ),
			);
		}

		$actions = isset( $top_opportunity['recommended_actions'] ) && is_array( $top_opportunity['recommended_actions'] ) ? $top_opportunity['recommended_actions'] : array();
		$action = '';
		if ( ! empty( $actions ) ) {
			$first = reset( $actions );
			$action = is_array( $first ) ? ( isset( $first['title'] ) ? $first['title'] : ( isset( $first['description'] ) ? $first['description'] : '' ) ) : (string) $first;
		}

		return array(
			'id'               => isset( $top_opportunity['id'] ) ? (int) $top_opportunity['id'] : 0,
			'signal_id'        => isset( $top_opportunity['signal_id'] ) ? sanitize_key( $top_opportunity['signal_id'] ) : '',
			'signal_name'      => isset( $top_opportunity['signal_name'] ) ? sanitize_text_field( $top_opportunity['signal_name'] ) : '',
			'entity_type'      => isset( $top_opportunity['entity_type'] ) ? sanitize_key( $top_opportunity['entity_type'] ) : '',
			'entity_id'        => isset( $top_opportunity['entity_id'] ) ? sanitize_text_field( $top_opportunity['entity_id'] ) : '',
			'entity_label'     => isset( $top_opportunity['entity_label'] ) ? sanitize_text_field( $top_opportunity['entity_label'] ) : '',
			'label'            => isset( $top_opportunity['entity_label'] ) ? sanitize_text_field( $top_opportunity['entity_label'] ) : '',
			'action'           => $action ? $action : __( 'Start with the highest-priority insight and verify it in the related Opti-Behavior report.', 'opti-behavior' ),
			'summary'          => sprintf( /* translators: %s: Entity label. */ __( 'Start with %s because it has the strongest priority score and CRO opportunity this week.', 'opti-behavior' ), isset( $top_opportunity['entity_label'] ) ? $top_opportunity['entity_label'] : __( 'the top insight', 'opti-behavior' ) ),
			'priority_score'   => $this->priority_score( $top_opportunity ),
			'priority_label'   => isset( $top_opportunity['scores']['priority_label'] ) ? sanitize_text_field( $top_opportunity['scores']['priority_label'] ) : '',
			'confidence_label' => isset( $top_opportunity['scores']['confidence_label'] ) ? sanitize_text_field( $top_opportunity['scores']['confidence_label'] ) : '',
			'status'           => isset( $top_opportunity['status'] ) ? sanitize_key( $top_opportunity['status'] ) : '',
		);
	}

	/**
	 * Build a headline.
	 *
	 * @param array      $active          Active insights.
	 * @param array|null $top_opportunity Top opportunity.
	 * @return string
	 */
	private function build_headline( $active, $top_opportunity ) {
		$high = $this->count_priority_at_least( $active, 60 );
		if ( $high > 0 ) {
			return sprintf( /* translators: %d: Number of high-priority issues. */ _n( '%d high-priority issue detected this week.', '%d high-priority issues detected this week.', $high, 'opti-behavior' ), $high );
		}

		return __( 'No high-priority CRO issue stands out this week.', 'opti-behavior' );
	}

	/**
	 * Summarize insight list.
	 *
	 * @param array $insights Insights.
	 * @return array
	 */
	private function summarize_insights( $insights ) {
		$items = array();
		foreach ( $insights as $insight ) {
			$items[] = $this->summarize_insight( $insight );
		}

		return $items;
	}

	/**
	 * Summarize one insight for reports.
	 *
	 * @param array $insight Insight.
	 * @return array
	 */
	private function summarize_insight( $insight ) {
		return array(
			'id'               => isset( $insight['id'] ) ? (int) $insight['id'] : 0,
			'signal_id'        => isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '',
			'signal_name'      => isset( $insight['signal_name'] ) ? sanitize_text_field( $insight['signal_name'] ) : '',
			'entity_type'      => isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '',
			'entity_id'        => isset( $insight['entity_id'] ) ? sanitize_text_field( $insight['entity_id'] ) : '',
			'entity_label'     => isset( $insight['entity_label'] ) ? sanitize_text_field( $insight['entity_label'] ) : '',
			'label'            => isset( $insight['entity_label'] ) ? sanitize_text_field( $insight['entity_label'] ) : '',
			'summary'          => isset( $insight['interpretation'] ) && '' !== trim( (string) $insight['interpretation'] ) ? wp_strip_all_tags( $insight['interpretation'] ) : ( isset( $insight['signal_name'] ) ? sanitize_text_field( $insight['signal_name'] ) : '' ),
			'priority_score'   => $this->priority_score( $insight ),
			'priority_label'   => isset( $insight['scores']['priority_label'] ) ? sanitize_text_field( $insight['scores']['priority_label'] ) : '',
			'confidence_label' => isset( $insight['scores']['confidence_label'] ) ? sanitize_text_field( $insight['scores']['confidence_label'] ) : '',
			'status'           => isset( $insight['status'] ) ? sanitize_key( $insight['status'] ) : '',
		);
	}

	/**
	 * Resolve priority score.
	 *
	 * @param array $insight Insight.
	 * @return int
	 */
	private function priority_score( $insight ) {
		return isset( $insight['scores']['priority_score'] ) ? (int) $insight['scores']['priority_score'] : 0;
	}

	/**
	 * Build a recurring group key.
	 *
	 * @param array $insight Insight.
	 * @return string
	 */
	private function group_key( $insight ) {
		if ( ! empty( $insight['group_key'] ) ) {
			return (string) $insight['group_key'];
		}

		return implode(
			':',
			array(
				isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '',
				isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '',
				isset( $insight['entity_id'] ) ? sanitize_text_field( $insight['entity_id'] ) : '',
			)
		);
	}

	/**
	 * Normalize a date range, defaulting to the last 7 days.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	private function normalize_range( $start_date, $end_date ) {
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
	private function previous_range( $range ) {
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
	private function normalize_date( $date ) {
		$date = is_string( $date ) ? trim( $date ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		$timestamp = strtotime( $date );
		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}
}
