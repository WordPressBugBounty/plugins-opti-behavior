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
	 * Relative tolerance below which a loss change is reported as unchanged.
	 *
	 * @since 1.4.0
	 * @var float
	 */
	const STATE_TOLERANCE = 0.10;

	/**
	 * Insight repository.
	 *
	 * @var Opti_Behavior_Smart_Insights_Repository
	 */
	private $repository;

	/**
	 * Previous-run lookups already resolved in this request.
	 *
	 * @since 1.4.0
	 * @var array
	 */
	private $previous_run_cache = array();

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
		// The group collapse keys on signal + entity, and a story child carries a
		// different signal from its primary, so it survives that pass. Without this
		// second collapse the briefing repeats one story once per child, each line
		// showing the identical `users_lost`.
		if ( method_exists( $this->repository, 'collapse_story_children' ) ) {
			$active = $this->repository->collapse_story_children( $active );
		}

		$wins              = $this->build_wins( $range, $args );
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
				'high_priority' => $this->count_priority_label_at_least( $active, 'high' ),
				'critical'      => $this->count_priority_label_at_least( $active, 'critical' ),
				'recurring'     => count( $recurring_issues ),
				'wins'          => count( $wins ),
			),
			'wins'                  => $wins,
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

		foreach ( array( 'top_critical_insights', 'recurring_issues', 'wins' ) as $section ) {
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
					isset( $item['status'] ) ? $item['status'] : '',
					isset( $item['outcome'] ) ? $item['outcome'] : array()
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
				isset( $summary[ $section ]['status'] ) ? $summary[ $section ]['status'] : '',
				isset( $summary[ $section ]['outcome'] ) ? $summary[ $section ]['outcome'] : array()
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
	 * @param array  $outcome          Optional measured outcome block.
	 * @return array
	 */
	private function export_row( $section, $label, $summary, $priority_score, $confidence_label, $status, $outcome = array() ) {
		return array(
			'section'          => sanitize_key( $section ),
			'label'            => sanitize_text_field( (string) $label ),
			'summary'          => wp_strip_all_tags( (string) $summary ),
			'priority_score'   => is_numeric( $priority_score ) ? (int) $priority_score : '',
			'confidence_label' => sanitize_text_field( (string) $confidence_label ),
			'status'           => sanitize_key( (string) $status ),
			'outcome_verdict'  => is_array( $outcome ) && ! empty( $outcome['verdict'] ) ? sanitize_key( $outcome['verdict'] ) : '',
			'outcome_change'   => $this->format_outcome_change( $outcome ),
		);
	}

	/**
	 * Render the before/after movement of one outcome as export text.
	 *
	 * @since 1.4.0
	 *
	 * @param array $outcome Outcome block.
	 * @return string
	 */
	private function format_outcome_change( $outcome ) {
		if ( ! is_array( $outcome ) || ! isset( $outcome['before_value'], $outcome['after_value'] ) ) {
			return '';
		}

		if ( null === $outcome['before_value'] || null === $outcome['after_value'] ) {
			return '';
		}

		$unit   = isset( $outcome['unit'] ) && 'seconds' === $outcome['unit'] ? 's' : '%';
		$metric = isset( $outcome['metric_label'] ) && '' !== $outcome['metric_label'] ? $outcome['metric_label'] . ': ' : '';

		return sprintf(
			'%1$s%2$s%4$s -> %3$s%4$s',
			$metric,
			number_format_i18n( (float) $outcome['before_value'], 1 ),
			number_format_i18n( (float) $outcome['after_value'], 1 ),
			$unit
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
	 * Count insights whose rank-based priority label is at or above a level.
	 *
	 * Rank-based labels replace the saturating absolute thresholds, so severity
	 * counts follow the label. Rows generated before rank scoring (no
	 * `priority_method`) keep the historical score thresholds.
	 *
	 * @since 1.3.9
	 *
	 * @param array  $insights Insights.
	 * @param string $minimum  Minimum canonical label key (`high` or `critical`).
	 * @return int
	 */
	private function count_priority_label_at_least( $insights, $minimum ) {
		$minimum = 'critical' === $minimum ? 'critical' : 'high';
		$count   = 0;
		$legacy  = array();

		foreach ( is_array( $insights ) ? $insights : array() as $insight ) {
			$label_key = $this->priority_label_key( $insight );
			if ( '' === $label_key ) {
				$legacy[] = $insight;
				continue;
			}

			if ( 'critical' === $label_key || ( 'high' === $minimum && 'high' === $label_key ) ) {
				$count++;
			}
		}

		if ( ! empty( $legacy ) ) {
			$count += $this->count_priority_at_least( $legacy, 'critical' === $minimum ? 80 : 60 );
		}

		return $count;
	}

	/**
	 * Resolve the canonical priority label key of a rank-scored insight.
	 *
	 * @since 1.3.9
	 *
	 * @param array $insight Insight.
	 * @return string Canonical key, or an empty string for legacy rows.
	 */
	private function priority_label_key( $insight ) {
		$scores = isset( $insight['scores'] ) && is_array( $insight['scores'] ) ? $insight['scores'] : array();
		if ( ! isset( $scores['priority_method'] ) || 'rank_v2' !== $scores['priority_method'] ) {
			return '';
		}

		if ( ! empty( $scores['priority_label_key'] ) ) {
			return sanitize_key( $scores['priority_label_key'] );
		}

		if ( class_exists( 'Opti_Behavior_Smart_Insights_Scorer' ) ) {
			return Opti_Behavior_Smart_Insights_Scorer::normalize_label_key( isset( $scores['priority_label'] ) ? $scores['priority_label'] : '' );
		}

		return '';
	}

	/**
	 * Build recurring issue groups.
	 *
	 * "Recurring" has exactly one definition: the issue was detected in at least
	 * two non-overlapping analysis windows, which the repository records on the row
	 * as `detection.recurrence_count`. Counting distinct rows per group key instead
	 * always returned zero, because the upsert merges re-detections into one row.
	 *
	 * @param array $current  Current active insights (already collapsed).
	 * @param array $previous Previous-range insights, kept for signature parity.
	 * @return array
	 */
	private function build_recurring_issues( $current, $previous ) {
		unset( $previous );

		$recurring = array();
		foreach ( is_array( $current ) ? $current : array() as $insight ) {
			$count = $this->recurrence_count( $insight );
			if ( $count < 2 ) {
				continue;
			}
			$item                     = $this->summarize_insight( $insight );
			$item['recurrence_count'] = $count;
			$recurring[]              = $item;
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
	 * Read the stored, window-based recurrence count of an insight.
	 *
	 * @param array $insight Insight row.
	 * @return int At least 1.
	 */
	private function recurrence_count( $insight ) {
		$detection = isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array();
		if ( isset( $detection['recurrence_count'] ) && is_numeric( $detection['recurrence_count'] ) ) {
			return max( 1, (int) $detection['recurrence_count'] );
		}
		if ( isset( $insight['recurrence_count'] ) && is_numeric( $insight['recurrence_count'] ) ) {
			return max( 1, (int) $insight['recurrence_count'] );
		}

		return 1;
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
		$bad_when_up = array( 'bounce_rate', 'exit_rate', 'dropoff_rate', 'abandonment_rate', 'error_rate', 'form_error_rate', 'worst_field_error_rate' );
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

		// The brief prints this sentence on its own, away from the modal that shows
		// which reports can actually be opened. Shaping the row here (and reading
		// the same text key the capabilities layer rewrites) means the brief can
		// never promise a report the insight marks non-applicable, whoever handed
		// the row in.
		$top_opportunity = $this->capabilities->filter_insight_for_viewer( $top_opportunity, 'list' );

		$actions = isset( $top_opportunity['recommended_actions'] ) && is_array( $top_opportunity['recommended_actions'] ) ? $top_opportunity['recommended_actions'] : array();
		$action = '';
		if ( ! empty( $actions ) ) {
			$first = reset( $actions );
			if ( is_array( $first ) ) {
				foreach ( array( 'title', 'label', 'action', 'description' ) as $key ) {
					if ( isset( $first[ $key ] ) && is_string( $first[ $key ] ) && '' !== trim( $first[ $key ] ) ) {
						$action = $first[ $key ];
						break;
					}
				}
			} else {
				$action = (string) $first;
			}
		}

		if ( '' !== $action && method_exists( $this->capabilities, 'reconcile_action_text' ) ) {
			$action = $this->capabilities->reconcile_action_text( $action, $top_opportunity );
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
		$high = $this->count_priority_label_at_least( $active, 'high' );
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
	 * Resolve the state of an issue against the previous stored run.
	 *
	 * The brief must say what changed, not only what exists. Rows are keyed by
	 * `group_key`, so the previous run of the same problem is one lookup; the
	 * loss it carried is compared with the current one under a 10% relative
	 * tolerance, below which the issue is reported as unchanged.
	 *
	 * @since 1.4.0
	 *
	 * @param array $insight Insight.
	 * @return string One of `new`, `worse`, `better`, `steady`, `resolved`.
	 */
	private function resolve_change_state( $insight ) {
		$status = isset( $insight['status'] ) ? sanitize_key( $insight['status'] ) : '';
		if ( in_array( $status, array( 'resolved', 'auto_resolved' ), true ) ) {
			return 'resolved';
		}

		$detection = isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array();
		$previous  = $this->get_previous_run( $insight );

		if ( ! $previous ) {
			// No earlier row: the generator may have updated this one in place, in
			// which case the detection block is the only memory of the last run.
			if ( ! empty( $detection['worsened'] ) ) {
				return 'worse';
			}

			return empty( $detection['same_issue_previous_period'] ) ? 'new' : 'steady';
		}

		$before = $this->users_lost( $previous );
		$after  = $this->users_lost( $insight );
		if ( null === $before || null === $after ) {
			return ! empty( $detection['worsened'] ) ? 'worse' : 'steady';
		}

		if ( $before <= 0 ) {
			return $after > 0 ? 'worse' : 'steady';
		}

		$relative = ( $after - $before ) / $before;
		if ( abs( $relative ) < self::STATE_TOLERANCE ) {
			return 'steady';
		}

		return $relative > 0 ? 'worse' : 'better';
	}

	/**
	 * Previous stored run of the same issue group, memoized per request.
	 *
	 * @since 1.4.0
	 *
	 * @param array $insight Insight.
	 * @return array|null
	 */
	private function get_previous_run( $insight ) {
		if ( ! $this->repository || ! method_exists( $this->repository, 'get_previous_run_by_group_key' ) ) {
			return null;
		}

		$group_key = $this->group_key( $insight );
		if ( '' === $group_key ) {
			return null;
		}

		$id         = isset( $insight['id'] ) ? (int) $insight['id'] : 0;
		$date_from  = isset( $insight['date_from'] ) ? (string) $insight['date_from'] : '';
		$cache_key  = $group_key . '|' . $id . '|' . $date_from;
		if ( array_key_exists( $cache_key, $this->previous_run_cache ) ) {
			return $this->previous_run_cache[ $cache_key ];
		}

		$previous = $this->repository->get_previous_run_by_group_key( $group_key, $id, $date_from );
		$this->previous_run_cache[ $cache_key ] = is_array( $previous ) ? $previous : null;

		return $this->previous_run_cache[ $cache_key ];
	}

	/**
	 * Translated label for a change state.
	 *
	 * @since 1.4.0
	 *
	 * @param string $state State key.
	 * @return string
	 */
	private function get_state_label( $state ) {
		switch ( $state ) {
			case 'worse':
				return __( 'Worse', 'opti-behavior' );
			case 'better':
				return __( 'Better', 'opti-behavior' );
			case 'resolved':
				return __( 'Resolved', 'opti-behavior' );
			case 'steady':
				return __( 'Unchanged', 'opti-behavior' );
			default:
				return __( 'New', 'opti-behavior' );
		}
	}

	/**
	 * Visitors lost recorded on an insight.
	 *
	 * @since 1.4.0
	 *
	 * @param array $insight Insight.
	 * @return int|null Null when the insight carries no impact block.
	 */
	private function users_lost( $insight ) {
		if ( isset( $insight['impact']['users_lost'] ) && is_numeric( $insight['impact']['users_lost'] ) ) {
			return (int) $insight['impact']['users_lost'];
		}

		if ( isset( $insight['scores']['impact']['users_lost'] ) && is_numeric( $insight['scores']['impact']['users_lost'] ) ) {
			return (int) $insight['scores']['impact']['users_lost'];
		}

		return null;
	}

	/**
	 * Money exposure recorded on an insight, when it could be measured.
	 *
	 * @since 1.4.0
	 *
	 * @param array $insight Insight.
	 * @return array Empty array when no amount is available.
	 */
	private function revenue_exposure( $insight ) {
		$revenue = isset( $insight['impact']['revenue'] ) && is_array( $insight['impact']['revenue'] ) ? $insight['impact']['revenue'] : array();
		if ( empty( $revenue['available'] ) || ! isset( $revenue['amount'] ) || ! is_numeric( $revenue['amount'] ) ) {
			return array();
		}

		return array(
			'available' => true,
			'amount'    => (float) $revenue['amount'],
			'currency'  => isset( $revenue['currency'] ) ? sanitize_text_field( (string) $revenue['currency'] ) : '',
		);
	}

	/**
	 * Sessions the insight was measured on.
	 *
	 * @since 1.4.0
	 *
	 * @param array $insight Insight.
	 * @return int
	 */
	private function measured_sessions( $insight ) {
		$metrics = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
		foreach ( array( 'sessions', 'starts', 'entries', 'pageviews' ) as $key ) {
			if ( isset( $metrics[ $key ] ) && is_numeric( $metrics[ $key ] ) && (int) $metrics[ $key ] > 0 ) {
				return (int) $metrics[ $key ];
			}
		}

		return 0;
	}

	/**
	 * Summarize one insight for reports.
	 *
	 * @param array $insight Insight.
	 * @return array
	 */
	private function summarize_insight( $insight ) {
		$state = $this->resolve_change_state( $insight );

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
			'state'            => $state,
			'state_label'      => $this->get_state_label( $state ),
			'users_lost'       => $this->users_lost( $insight ),
			'revenue'          => $this->revenue_exposure( $insight ),
			'observation_only' => ! empty( $insight['detection']['observation_only'] ),
			'sessions'         => $this->measured_sessions( $insight ),
			'outcome'          => $this->summarize_outcome( $insight ),
		);
	}

	/**
	 * Reduce a stored outcome block to the fields the report needs.
	 *
	 * @since 1.4.0
	 *
	 * @param array $insight Insight.
	 * @return array Empty array when the insight was never measured.
	 */
	private function summarize_outcome( $insight ) {
		$outcome = isset( $insight['outcome'] ) && is_array( $insight['outcome'] ) ? $insight['outcome'] : array();
		if ( empty( $outcome ) || empty( $outcome['metric_key'] ) ) {
			return array();
		}

		return array(
			'metric_key'    => sanitize_key( $outcome['metric_key'] ),
			'metric_label'  => isset( $outcome['metric_label'] ) ? sanitize_text_field( $outcome['metric_label'] ) : '',
			'unit'          => isset( $outcome['unit'] ) ? sanitize_key( $outcome['unit'] ) : 'percent',
			'verdict'       => isset( $outcome['verdict'] ) ? sanitize_key( $outcome['verdict'] ) : '',
			'verdict_label' => isset( $outcome['verdict_label'] ) ? sanitize_text_field( $outcome['verdict_label'] ) : '',
			'reason'        => isset( $outcome['reason'] ) ? sanitize_key( $outcome['reason'] ) : '',
			'before_value'  => isset( $outcome['before_value'] ) && is_numeric( $outcome['before_value'] ) ? (float) $outcome['before_value'] : null,
			'after_value'   => isset( $outcome['after_value'] ) && is_numeric( $outcome['after_value'] ) ? (float) $outcome['after_value'] : null,
			'delta_abs'     => isset( $outcome['delta_abs'] ) && is_numeric( $outcome['delta_abs'] ) ? (float) $outcome['delta_abs'] : null,
			'delta_rel'     => isset( $outcome['delta_rel'] ) && is_numeric( $outcome['delta_rel'] ) ? (float) $outcome['delta_rel'] : null,
			'resolved_at'   => isset( $outcome['resolved_at'] ) ? sanitize_text_field( $outcome['resolved_at'] ) : ( isset( $insight['resolved_at'] ) ? sanitize_text_field( (string) $insight['resolved_at'] ) : '' ),
		);
	}

	/**
	 * Build the "Wins this month" list: fixes measured as improvements.
	 *
	 * The outcome cron only measures an insight two weeks after it was resolved,
	 * so the window is anchored on the end of the reported range and reaches a
	 * month back; anything older belongs to a previous report.
	 *
	 * @since 1.4.0
	 *
	 * @param array $range Summary range.
	 * @param array $args  Summary args.
	 * @return array
	 */
	private function build_wins( $range, $args ) {
		if ( ! $this->repository || ! method_exists( $this->repository, 'get_outcomes' ) ) {
			return array();
		}

		$anchor = isset( $range['to'] ) ? strtotime( $range['to'] . ' 23:59:59' ) : false;
		if ( ! $anchor ) {
			return array();
		}

		try {
			$rows = $this->repository->get_outcomes(
				array(
					'verdict'        => 'improved',
					'resolved_since' => gmdate( 'Y-m-d H:i:s', $anchor - ( 30 * DAY_IN_SECONDS ) ),
					'limit'          => 5,
				)
			);
		} catch ( Exception $e ) {
			return array();
		} catch ( Error $e ) {
			return array();
		}

		$rows = $this->capabilities->filter_insights_for_viewer( (array) $rows, 'list' );
		$wins = array();

		foreach ( $rows as $row ) {
			$outcome = $this->summarize_outcome( $row );
			if ( empty( $outcome ) || 'improved' !== $outcome['verdict'] ) {
				continue;
			}

			$wins[] = array(
				'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'signal_id'    => isset( $row['signal_id'] ) ? sanitize_key( $row['signal_id'] ) : '',
				'signal_name'  => isset( $row['signal_name'] ) ? sanitize_text_field( $row['signal_name'] ) : '',
				'entity_label' => isset( $row['entity_label'] ) ? sanitize_text_field( $row['entity_label'] ) : '',
				'label'        => isset( $row['entity_label'] ) ? sanitize_text_field( $row['entity_label'] ) : '',
				'summary'      => isset( $row['signal_name'] ) ? sanitize_text_field( $row['signal_name'] ) : '',
				'status'       => isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '',
				'outcome'      => $outcome,
			);
		}

		/**
		 * Filter the measured wins listed in the weekly brief.
		 *
		 * @since 1.4.0
		 *
		 * @param array $wins  Win entries.
		 * @param array $range Summary range.
		 * @param array $args  Summary args.
		 */
		return apply_filters( 'opti_behavior_smart_insights_weekly_wins', $wins, $range, $args );
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
