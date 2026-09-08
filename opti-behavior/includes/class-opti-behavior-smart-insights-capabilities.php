<?php
/**
 * Smart Insights Capabilities Class
 *
 * Shapes insight payloads according to Free/Pro visibility boundaries.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Capabilities Class.
 *
 * Free always keeps useful basic insight data visible. Pro-only and locked
 * preview payloads are shaped so future adapters can persist richer aggregate
 * context without leaking it to Free screens.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Capabilities {

	const TIER_FREE       = 'free';
	const TIER_PRO_LOCKED = 'pro_locked';
	const TIER_PRO        = 'pro';

	/**
	 * Maximum number of recommended actions a viewer is shown.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const MAX_RECOMMENDED_ACTIONS = 3;

	/**
	 * Whether the current viewer has Pro access.
	 *
	 * @var bool
	 */
	private $has_pro_access;

	/**
	 * Constructor.
	 *
	 * @param bool|null $has_pro_access Optional explicit capability.
	 */
	public function __construct( $has_pro_access = null ) {
		$this->has_pro_access = null === $has_pro_access ? $this->detect_pro_access() : (bool) $has_pro_access;
	}

	/**
	 * Shape multiple insight payloads.
	 *
	 * @param array  $insights Insights.
	 * @param string $context  View context: list, detail, dashboard.
	 * @return array
	 */
	public function filter_insights_for_viewer( $insights, $context = 'list' ) {
		if ( ! is_array( $insights ) ) {
			return array();
		}

		$filtered = array();
		foreach ( $insights as $insight ) {
			if ( is_array( $insight ) ) {
				$filtered[] = $this->filter_insight_for_viewer( $insight, $context );
			}
		}

		return $filtered;
	}

	/**
	 * Shape one insight payload for the current viewer.
	 *
	 * @param array  $insight Insight payload.
	 * @param string $context View context: list, detail, dashboard.
	 * @return array
	 */
	public function filter_insight_for_viewer( $insight, $context = 'list' ) {
		if ( ! is_array( $insight ) ) {
			return array();
		}

		$tier = $this->normalize_visibility_tier( $insight );
		if ( self::TIER_PRO === $tier && ! $this->has_pro_access ) {
			$tier = self::TIER_PRO_LOCKED;
		}

		$insight['visibility_tier'] = $tier;
		$insight['viewer_can_unlock_full_detail'] = $this->has_pro_access;
		$insight['is_locked_preview'] = self::TIER_PRO_LOCKED === $tier;

		if ( self::TIER_PRO_LOCKED === $tier ) {
			$insight = $this->shape_locked_preview( $insight, $context );
		} elseif ( self::TIER_FREE === $tier && ! $this->has_pro_access ) {
			$insight = $this->shape_free_basic_payload( $insight );
		}

		// Upgrade teasers are baked into stored insights at generation time so
		// Free viewers always have honest copy. A Pro viewer must never see
		// them: strip the leftovers instead of trusting the renderer.
		if ( $this->has_pro_access && self::TIER_PRO_LOCKED !== $tier ) {
			unset( $insight['upgrade_preview'], $insight['locked_preview'] );
		}

		if ( ! $this->has_pro_access ) {
			$insight = $this->shape_story_blocks_for_free( $insight );
		}

		$insight = $this->shape_recommended_actions( $insight );

		return apply_filters( 'opti_behavior_smart_insights_shape_payload', $insight, $context, $this->has_pro_access );
	}

	/**
	 * Reconcile a stand-alone action sentence against one insight's reports.
	 *
	 * `filter_insight_for_viewer()` already does this for every bullet inside an
	 * insight payload. Surfaces that lift a single sentence back out of a payload
	 * and print it on their own — the weekly brief's "next action" line — must
	 * hold the same promise, so they route the sentence through here instead of
	 * re-implementing the lexicon.
	 *
	 * @since 1.4.1
	 *
	 * @param string $text    Action sentence.
	 * @param array  $insight Insight payload the sentence belongs to.
	 * @return string Reconciled sentence, or '' when no report it names can be opened.
	 */
	public function reconcile_action_text( $text, $insight ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $text || ! is_array( $insight ) ) {
			return $text;
		}

		$reconciled = $this->reconcile_action_with_reports( $text, $this->get_report_applicability( $insight ) );

		return is_string( $reconciled ) ? $reconciled : '';
	}

	/**
	 * Check whether viewer has Pro access.
	 *
	 * @return bool
	 */
	public function has_pro_access() {
		return $this->has_pro_access;
	}

	/**
	 * Shape a computed "Who is affected?" segment matrix for the current viewer.
	 *
	 * Pro receives the full matrix and can re-scope the insight's evidence links
	 * by segment. Free receives a teaser: the single most concentrated segment
	 * (so the value of the panel is visible and comparable) plus the number of
	 * dimensions that stay locked. Per-dimension distributions, the remaining
	 * segments, and the per-segment report context never reach a Free viewer.
	 *
	 * @since 1.3.9
	 *
	 * @param array $matrix Segment matrix payload.
	 * @return array
	 */
	public function filter_segment_matrix_for_viewer( $matrix ) {
		if ( ! is_array( $matrix ) ) {
			$matrix = array();
		}

		$matrix['viewer_can_unlock_full_detail'] = $this->has_pro_access;

		if ( $this->has_pro_access ) {
			$matrix['tier']   = self::TIER_PRO;
			$matrix['locked'] = false;
		} else {
			$dimensions = isset( $matrix['dimensions'] ) && is_array( $matrix['dimensions'] ) ? $matrix['dimensions'] : array();
			$top        = isset( $matrix['top_segment'] ) && is_array( $matrix['top_segment'] ) ? $matrix['top_segment'] : null;
			$outliers   = isset( $matrix['outliers'] ) && is_array( $matrix['outliers'] ) ? $matrix['outliers'] : array();

			$combo_outliers = $this->filter_combo_outliers( $outliers, true );

			$matrix = array(
				'version'         => isset( $matrix['version'] ) ? (int) $matrix['version'] : 1,
				'available'       => ! empty( $matrix['available'] ),
				'reason'          => isset( $matrix['reason'] ) ? sanitize_key( $matrix['reason'] ) : '',
				'total_sessions'  => isset( $matrix['total_sessions'] ) ? (int) $matrix['total_sessions'] : 0,
				'scope_sessions'  => isset( $matrix['scope_sessions'] ) ? (int) $matrix['scope_sessions'] : 0,
				// Free keeps the honest arithmetic behind an unavailable panel:
				// "12 of the 30 sessions needed" is not a paid answer.
				'min_scope_sessions' => isset( $matrix['min_scope_sessions'] ) ? (int) $matrix['min_scope_sessions'] : 0,
				'exclude_spam'    => ! empty( $matrix['exclude_spam'] ),
				// The verdict sentence is the honest part of the answer and stays
				// free: Free viewers learn WHICH audience carries the problem, Pro
				// viewers additionally get every bucket and the segment-scoped
				// evidence links.
				'primary_metric'  => isset( $matrix['primary_metric'] ) ? sanitize_key( $matrix['primary_metric'] ) : '',
				'metric_label'    => isset( $matrix['metric_label'] ) ? (string) $matrix['metric_label'] : '',
				'metric_unit'     => isset( $matrix['metric_unit'] ) ? sanitize_key( $matrix['metric_unit'] ) : '',
				'metric_type'     => isset( $matrix['metric_type'] ) ? sanitize_key( $matrix['metric_type'] ) : '',
				'uniform'         => ! empty( $matrix['uniform'] ),
				'outliers'        => $this->shape_locked_outliers( $outliers ),
				'locked_outlier_count' => count( $outliers ),
				'dimension_count' => count( $dimensions ),
				// The pulse strip is the free half of the answer: WHICH dimensions
				// carry a problem, how many segments were measurable, and which ones
				// fell under the data floor. WHERE exactly inside them stays Pro.
				'dimension_pulses' => $this->shape_dimension_pulses( $dimensions ),
				// Combination findings are Pro: Free learns one exists and half of
				// its identity, which is the upgrade reason, not the answer.
				'combo_dimension_count' => 0,
				'locked_combo_count'    => count( $combo_outliers ),
				'locked_combo_teaser'   => $this->shape_combo_teaser( $combo_outliers ),
				'traffic_mix'     => $this->shape_locked_traffic_mix( isset( $matrix['traffic_mix'] ) ? $matrix['traffic_mix'] : null ),
				'dimensions'      => array(),
				'top_segment'     => null === $top ? null : array(
					'dimension'       => isset( $top['dimension'] ) ? sanitize_key( $top['dimension'] ) : '',
					'dimension_label' => isset( $top['dimension_label'] ) ? (string) $top['dimension_label'] : '',
					'label'           => isset( $top['label'] ) ? (string) $top['label'] : '',
					'sessions'        => isset( $top['sessions'] ) ? (int) $top['sessions'] : 0,
					'share'           => isset( $top['share'] ) ? (float) $top['share'] : 0,
				),
				'tier'            => self::TIER_PRO_LOCKED,
				'locked'          => true,
				'locked_hint'     => __( 'Upgrade to Pro to break this insight down by device, source, campaign, and visitor type — and to re-scope the evidence links to one segment.', 'opti-behavior' ),
				'viewer_can_unlock_full_detail' => false,
			);
		}

		/**
		 * Filter the tier-shaped segment matrix payload.
		 *
		 * @since 1.3.9
		 *
		 * @param array $matrix         Shaped matrix.
		 * @param bool  $has_pro_access Viewer access.
		 */
		return apply_filters( 'opti_behavior_smart_insights_shape_segment_matrix', $matrix, $this->has_pro_access );
	}

	/**
	 * Reduce outliers to the single verdict-bearing entry a Free viewer sees.
	 *
	 * Only the fields the verdict sentence needs survive: no per-bucket metric
	 * table, no complement breakdown, no report context, and no sparkline — the
	 * day-by-day shape of one segment is the drill-down Pro pays for.
	 *
	 * Combination outliers are excluded on purpose: with pairs in the ranking
	 * pool the top entry can be a combined pattern, which decision D1 keeps Pro.
	 * Free gets the best SINGLE-dimension finding plus a combo teaser beside it.
	 *
	 * @since 1.4.0
	 *
	 * @param array $outliers Full outlier list.
	 * @return array
	 */
	private function shape_locked_outliers( $outliers ) {
		$singles = $this->filter_combo_outliers( $outliers, false );
		$first   = isset( $singles[0] ) && is_array( $singles[0] ) ? $singles[0] : null;
		if ( null === $first ) {
			return array();
		}

		return array(
			array(
				'dimension'        => isset( $first['dimension'] ) ? sanitize_key( $first['dimension'] ) : '',
				'dimension_label'  => isset( $first['dimension_label'] ) ? (string) $first['dimension_label'] : '',
				'key'              => isset( $first['key'] ) ? (string) $first['key'] : '',
				'label'            => isset( $first['label'] ) ? (string) $first['label'] : '',
				'sessions'         => isset( $first['sessions'] ) ? (int) $first['sessions'] : 0,
				'share'            => isset( $first['share'] ) ? (float) $first['share'] : 0,
				'value'            => isset( $first['value'] ) ? $first['value'] : null,
				'complement_value' => isset( $first['complement_value'] ) ? $first['complement_value'] : null,
				'delta'            => isset( $first['delta'] ) ? $first['delta'] : null,
				'is_outlier'       => ! empty( $first['is_outlier'] ),
				'is_worse_side'    => ! empty( $first['is_worse_side'] ),
				// Pulse inputs are Free (D1): the direction word only, never the
				// early/late values behind it.
				'insufficient'     => ! empty( $first['insufficient'] ),
				'trend'            => array(
					'direction' => isset( $first['trend']['direction'] ) ? sanitize_key( $first['trend']['direction'] ) : 'insufficient',
				),
			),
		);
	}

	/**
	 * Split an outlier list into combination and single-dimension findings.
	 *
	 * A combination bucket is the only one that carries `parts`, so the split
	 * needs no dimension-registry lookup and cannot drift from the builder.
	 *
	 * @since 1.4.1
	 *
	 * @param array $outliers Full outlier list.
	 * @param bool  $combos   True to keep combinations, false to keep singles.
	 * @return array
	 */
	private function filter_combo_outliers( $outliers, $combos ) {
		$kept = array();

		foreach ( $outliers as $outlier ) {
			if ( ! is_array( $outlier ) ) {
				continue;
			}

			$is_combo = ! empty( $outlier['parts'] ) && is_array( $outlier['parts'] );
			if ( $is_combo === (bool) $combos ) {
				$kept[] = $outlier;
			}
		}

		return array_values( $kept );
	}

	/**
	 * Free-tier teaser for the best combination finding.
	 *
	 * Names the dimension pair and the FIRST half of the audience, then stops:
	 * enough to prove the analysis found something a single dimension missed,
	 * not enough to act on it without Pro.
	 *
	 * @since 1.4.1
	 *
	 * @param array $combo_outliers Combination outliers.
	 * @return array|null
	 */
	private function shape_combo_teaser( $combo_outliers ) {
		$first = isset( $combo_outliers[0] ) && is_array( $combo_outliers[0] ) ? $combo_outliers[0] : null;
		if ( null === $first ) {
			return null;
		}

		$parts       = isset( $first['parts'] ) && is_array( $first['parts'] ) ? array_values( $first['parts'] ) : array();
		$first_label = isset( $parts[0]['label'] ) ? (string) $parts[0]['label'] : '';

		return array(
			'dimension_label' => isset( $first['dimension_label'] ) ? (string) $first['dimension_label'] : '',
			'label_hint'      => '' === $first_label
				? ''
				/* translators: %s: the visible half of a locked combined segment, e.g. "Mobile". */
				: sprintf( __( '%s × …', 'opti-behavior' ), $first_label ),
			'is_worse_side'   => ! empty( $first['is_worse_side'] ),
		);
	}

	/**
	 * Per-dimension pulse verdicts a Free viewer is allowed to see.
	 *
	 * The strip answers "which dimensions carry a problem and which ones could
	 * not even be measured" without naming a single bucket, so it is the free
	 * half of the panel. Combination dimensions are dropped entirely (D1).
	 *
	 * @since 1.4.1
	 *
	 * @param array $dimensions Full dimension list.
	 * @return array
	 */
	private function shape_dimension_pulses( $dimensions ) {
		$pulses = array();

		foreach ( $dimensions as $entry ) {
			if ( ! is_array( $entry ) || ! empty( $entry['is_combo'] ) ) {
				continue;
			}

			$pulses[] = array(
				'dimension'           => isset( $entry['dimension'] ) ? sanitize_key( $entry['dimension'] ) : '',
				'dimension_label'     => isset( $entry['dimension_label'] ) ? (string) $entry['dimension_label'] : '',
				'pulse'               => isset( $entry['pulse'] ) ? sanitize_key( $entry['pulse'] ) : 'insufficient',
				'bucket_count'        => isset( $entry['bucket_count'] ) ? (int) $entry['bucket_count'] : 0,
				'tested_bucket_count' => isset( $entry['tested_bucket_count'] ) ? (int) $entry['tested_bucket_count'] : 0,
			);
		}

		return $pulses;
	}

	/**
	 * Free-tier shape of the traffic-mix block: the verdict, not the table.
	 *
	 * "Your audience changed and the change explains the metric" is the finding
	 * and stays free, because a Free viewer acting on a page regression that is
	 * really a traffic-mix effect would be acting on a false premise. Which
	 * buckets moved, by how much, and against which previous shares is the
	 * drill-down Pro pays for.
	 *
	 * @since 1.4.1
	 *
	 * @param array|null $traffic_mix Computed traffic-mix block.
	 * @return array|null
	 */
	private function shape_locked_traffic_mix( $traffic_mix ) {
		if ( ! is_array( $traffic_mix ) || empty( $traffic_mix['available'] ) ) {
			return null;
		}

		$dimensions = isset( $traffic_mix['dimensions'] ) && is_array( $traffic_mix['dimensions'] ) ? $traffic_mix['dimensions'] : array();
		$headline   = null;

		foreach ( $dimensions as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			foreach ( ( isset( $entry['buckets'] ) && is_array( $entry['buckets'] ) ? $entry['buckets'] : array() ) as $bucket ) {
				if ( ! is_array( $bucket ) || empty( $bucket['mix_shift'] ) ) {
					continue;
				}

				// The worst-explaining shift wins the sentence: a spike that also
				// degrades the metric is the one that changes what to do next.
				if ( null !== $headline && ! ( ! empty( $bucket['degrades_metric'] ) && empty( $headline['degrades_metric'] ) ) ) {
					continue;
				}

				$headline = array(
					'dimension'       => isset( $entry['dimension'] ) ? sanitize_key( $entry['dimension'] ) : '',
					'dimension_label' => isset( $entry['dimension_label'] ) ? (string) $entry['dimension_label'] : '',
					'label'           => isset( $bucket['label'] ) ? (string) $bucket['label'] : '',
					'share'           => isset( $bucket['share'] ) ? (float) $bucket['share'] : 0,
					'previous_share'  => isset( $bucket['previous_share'] ) ? (float) $bucket['previous_share'] : 0,
					'degrades_metric' => isset( $bucket['degrades_metric'] ) ? $bucket['degrades_metric'] : null,
				);
			}
		}

		return array(
			'available'      => true,
			'locked'         => true,
			'shift_count'    => isset( $traffic_mix['shift_count'] ) ? (int) $traffic_mix['shift_count'] : 0,
			'previous_range' => isset( $traffic_mix['previous_range'] ) && is_array( $traffic_mix['previous_range'] )
				? array(
					'from' => isset( $traffic_mix['previous_range']['from'] ) ? (string) $traffic_mix['previous_range']['from'] : '',
					'to'   => isset( $traffic_mix['previous_range']['to'] ) ? (string) $traffic_mix['previous_range']['to'] : '',
				)
				: array(
					'from' => '',
					'to'   => '',
				),
			'headline'       => $headline,
			'dimensions'     => array(),
		);
	}

	/**
	 * Detect Pro access through Pro hooks/functions when available.
	 *
	 * @return bool
	 */
	private function detect_pro_access() {
		$has_access = false;

		if ( class_exists( 'Opti_Behavior_Manifest_Manager' ) && is_callable( array( 'Opti_Behavior_Manifest_Manager', 'get_instance' ) ) ) {
			$manifest_manager = Opti_Behavior_Manifest_Manager::get_instance();
			if ( is_object( $manifest_manager ) && is_callable( array( $manifest_manager, 'has_pro_access' ) ) ) {
				$has_access = (bool) $manifest_manager->has_pro_access( 'smart_insights' );
			}
		}

		/**
		 * Let Pro validate access through its manifest manager.
		 *
		 * @param bool   $has_access Default Free access.
		 * @param string $feature    Feature slug.
		 */
		return (bool) apply_filters( 'opti_behavior_smart_insights_has_pro_access', $has_access, 'smart_insights' );
	}

	/**
	 * Normalize a stored/derived visibility tier.
	 *
	 * @param array $insight Insight payload.
	 * @return string
	 */
	private function normalize_visibility_tier( $insight ) {
		$tier = isset( $insight['visibility_tier'] ) ? sanitize_key( $insight['visibility_tier'] ) : '';
		if ( in_array( $tier, array( self::TIER_FREE, self::TIER_PRO_LOCKED, self::TIER_PRO ), true ) ) {
			return $tier;
		}

		$availability = isset( $insight['availability'] ) ? sanitize_key( $insight['availability'] ) : self::TIER_FREE;
		if ( in_array( $availability, array( self::TIER_PRO_LOCKED, 'locked', 'free_basic_pro_advanced' ), true ) ) {
			return 'free_basic_pro_advanced' === $availability ? self::TIER_FREE : self::TIER_PRO_LOCKED;
		}

		if ( self::TIER_PRO === $availability ) {
			return self::TIER_PRO;
		}

		return self::TIER_FREE;
	}

	/**
	 * Keep Free payload useful but hide future deep segment/trend objects.
	 *
	 * @param array $insight Insight payload.
	 * @return array
	 */
	private function shape_free_basic_payload( $insight ) {
		if ( isset( $insight['segment'] ) ) {
			unset( $insight['segment'] );
		}

		if ( isset( $insight['segment_json'] ) ) {
			unset( $insight['segment_json'] );
		}

		$insight['upgrade_preview'] = isset( $insight['upgrade_preview'] ) ? $insight['upgrade_preview'] : array(
			'title'       => __( 'Pro diagnosis available', 'opti-behavior' ),
			'description' => __( 'Unlock segment breakdowns, related recordings, and deeper CRO recommendations for this signal.', 'opti-behavior' ),
		);

		return $insight;
	}

	/**
	 * Shape the correlation/impact story blocks for a Free viewer.
	 *
	 * Free keeps the parts that make the problem understandable and comparable:
	 * the absolute impact headline (how many visitors this leak costs) and how
	 * many corroborating causes were measured. The measured shares, probe
	 * internals, evidence sample references, and the WooCommerce revenue exposure
	 * stay Pro-only; revenue is replaced by a locked hint so the value is visible
	 * as an upgrade reason without leaking the number.
	 *
	 * @since 1.3.8
	 *
	 * @param array $insight Insight payload.
	 * @return array
	 */
	private function shape_story_blocks_for_free( $insight ) {
		if ( ! empty( $insight['correlation'] ) && is_array( $insight['correlation'] ) ) {
			$correlation = $insight['correlation'];
			$causes      = isset( $correlation['causes'] ) && is_array( $correlation['causes'] ) ? $correlation['causes'] : array();

			$free_causes = array();
			foreach ( $causes as $cause ) {
				if ( ! is_array( $cause ) ) {
					continue;
				}

				$free_causes[] = array(
					'probe' => isset( $cause['probe'] ) ? sanitize_key( $cause['probe'] ) : '',
					'label' => isset( $cause['label'] ) ? (string) $cause['label'] : '',
					'rank'  => isset( $cause['rank'] ) ? (int) $cause['rank'] : count( $free_causes ) + 1,
					'tier'  => self::TIER_PRO_LOCKED,
				);
			}

			$insight['correlation'] = array(
				'version'        => isset( $correlation['version'] ) ? (int) $correlation['version'] : 1,
				'correlated'     => ! empty( $correlation['correlated'] ),
				'playbook_label' => isset( $correlation['playbook_label'] ) ? (string) $correlation['playbook_label'] : '',
				'signal_count'   => isset( $correlation['signal_count'] ) ? (int) $correlation['signal_count'] : 0,
				'cause_count'    => isset( $correlation['cause_count'] ) ? (int) $correlation['cause_count'] : count( $free_causes ),
				'causes'         => $free_causes,
				'shares_locked'  => true,
				'locked_hint'    => __( 'Upgrade to Pro to see how much of this problem each cause explains.', 'opti-behavior' ),
			);
		}

		if ( ! empty( $insight['impact'] ) && is_array( $insight['impact'] ) ) {
			$impact = $insight['impact'];

			$insight['impact'] = array(
				'version'      => isset( $impact['version'] ) ? (int) $impact['version'] : 1,
				'users_lost'   => isset( $impact['users_lost'] ) ? (int) $impact['users_lost'] : 0,
				'headline'     => isset( $impact['headline'] ) ? (string) $impact['headline'] : '',
				'basis'        => isset( $impact['basis'] ) ? sanitize_key( $impact['basis'] ) : '',
				'basis_label'  => isset( $impact['basis_label'] ) ? (string) $impact['basis_label'] : '',
				'impact_score' => isset( $impact['impact_score'] ) ? (int) $impact['impact_score'] : 0,
				'impact_rank'  => isset( $impact['impact_rank'] ) ? (int) $impact['impact_rank'] : 0,
				'cause_count'  => isset( $impact['causes'] ) && is_array( $impact['causes'] ) ? count( $impact['causes'] ) : 0,
				'revenue'      => $this->shape_revenue_for_free( isset( $impact['revenue'] ) ? $impact['revenue'] : array() ),
			);
		}

		// The hypothesis and its linked experiment are the Pro half of the loop.
		// Free keeps a locked preview card: it says a testable hypothesis exists
		// and which metric it targets, but never the statement, the expected
		// range, or the sample-size estimate.
		if ( ! empty( $insight['hypothesis'] ) && is_array( $insight['hypothesis'] ) ) {
			$hypothesis = $insight['hypothesis'];

			$insight['hypothesis'] = array(
				'version'      => isset( $hypothesis['version'] ) ? (int) $hypothesis['version'] : 1,
				'available'    => true,
				'locked'       => true,
				'tier'         => self::TIER_PRO_LOCKED,
				'family'       => isset( $hypothesis['family'] ) ? sanitize_key( $hypothesis['family'] ) : '',
				'metric_label' => isset( $hypothesis['metric_label'] ) ? (string) $hypothesis['metric_label'] : '',
				'locked_hint'  => __( 'Upgrade to Pro to see the suggested hypothesis, the typical industry range, and the sample size this test would need.', 'opti-behavior' ),
			);
		}

		if ( isset( $insight['experiment'] ) ) {
			unset( $insight['experiment'] );
		}

		// Typed evidence stays Pro-only, but the *counts* are safe to show: they
		// tell a Free user how much proof exists without exposing the refs.
		if ( isset( $insight['evidence_refs'] ) ) {
			$bundle = is_array( $insight['evidence_refs'] ) ? $insight['evidence_refs'] : array();
			$counts = isset( $bundle['counts'] ) && is_array( $bundle['counts'] ) ? $bundle['counts'] : array();

			unset( $insight['evidence_refs'] );

			$total = isset( $counts['total'] ) ? (int) $counts['total'] : 0;
			if ( $total > 0 ) {
				$by_type = array();
				foreach ( isset( $counts['by_type'] ) && is_array( $counts['by_type'] ) ? $counts['by_type'] : array() as $type => $count ) {
					$by_type[ sanitize_key( $type ) ] = (int) $count;
				}

				$insight['evidence_summary'] = array(
					'version'     => isset( $bundle['version'] ) ? (int) $bundle['version'] : 1,
					'total'       => $total,
					'by_type'     => $by_type,
					'locked'      => true,
					'tier'        => self::TIER_PRO_LOCKED,
					'locked_hint' => __( 'Upgrade to Pro to open the affected sessions, error groups, and form fields behind this insight.', 'opti-behavior' ),
				);
			}
		}

		return $insight;
	}

	/**
	 * Shape the revenue exposure block for a Free viewer.
	 *
	 * A WooCommerce-derived average order value is a Pro disclosure and stays
	 * behind the locked hint. A value the site owner typed into the Smart
	 * Insights settings is their own number, so it is handed straight back: an
	 * agency on Free must be able to put a currency figure on the leak.
	 *
	 * @since 1.3.9
	 *
	 * @param array $revenue Stored revenue block.
	 * @return array
	 */
	private function shape_revenue_for_free( $revenue ) {
		$locked = array(
			'available' => false,
			'locked'    => true,
			'tier'      => self::TIER_PRO,
			'hint'      => __( 'Revenue exposure for this leak is available in Pro.', 'opti-behavior' ),
		);

		if ( ! is_array( $revenue ) || empty( $revenue['available'] ) ) {
			return $locked;
		}

		$manual_source = class_exists( 'Opti_Behavior_Smart_Insights_Impact_Calculator' )
			? Opti_Behavior_Smart_Insights_Impact_Calculator::REVENUE_SOURCE_MANUAL
			: 'manual_conversion_value';

		if ( ! isset( $revenue['source'] ) || $manual_source !== $revenue['source'] ) {
			return $locked;
		}

		return array(
			'available'           => true,
			'locked'              => false,
			'amount'              => isset( $revenue['amount'] ) ? (float) $revenue['amount'] : 0.0,
			'currency'            => isset( $revenue['currency'] ) ? (string) $revenue['currency'] : '',
			'average_order_value' => isset( $revenue['average_order_value'] ) ? (float) $revenue['average_order_value'] : 0.0,
			'conversion_factor'   => isset( $revenue['conversion_factor'] ) ? (float) $revenue['conversion_factor'] : 0.0,
			'orders_sampled'      => 0,
			'source'              => $manual_source,
			'tier'                => self::TIER_FREE,
		);
	}

	/**
	 * Cap the recommended actions and place the Pro-context bullet honestly.
	 *
	 * A Pro viewer never sees a "use Pro context" bullet: they already have it,
	 * so it is pure noise in a list that is capped at three lines. A Free viewer
	 * sees it exactly once, as the last bullet, because for them it is the one
	 * useful upgrade reason attached to a concrete finding. Doing this here means
	 * the JS renderer never has to branch on the viewer's tier.
	 *
	 * @since 1.4.0
	 *
	 * @param array $insight Insight payload.
	 * @return array
	 */
	private function shape_recommended_actions( $insight ) {
		if ( ! isset( $insight['recommended_actions'] ) || ! is_array( $insight['recommended_actions'] ) ) {
			return $insight;
		}

		$limit = (int) apply_filters( 'opti_behavior_smart_insights_max_recommended_actions', self::MAX_RECOMMENDED_ACTIONS, $insight, $this->has_pro_access );
		if ( $limit < 1 ) {
			$limit = self::MAX_RECOMMENDED_ACTIONS;
		}

		$applicability = $this->get_report_applicability( $insight );

		$actions = array();
		foreach ( array_values( $insight['recommended_actions'] ) as $action ) {
			if ( $this->is_pro_context_action( $action ) ) {
				continue;
			}

			$action = $this->reconcile_action_with_reports( $action, $applicability );
			if ( null === $action ) {
				continue;
			}

			$actions[] = $action;
		}

		if ( empty( $actions ) && ! empty( $insight['recommended_actions'] ) ) {
			$fallback = $this->build_report_scoped_action( array_keys( $applicability['applicable'] ) );
			if ( '' !== $fallback ) {
				$actions[] = $fallback;
			}
		}

		// Locked previews already are one upgrade call to action; never stack a
		// second upsell bullet on top of it.
		$is_locked_preview = ! empty( $insight['is_locked_preview'] );

		if ( ! $this->has_pro_access && ! $is_locked_preview && ! empty( $actions ) ) {
			$actions   = array_slice( $actions, 0, max( 1, $limit - 1 ) );
			$actions[] = $this->get_pro_context_action();
		} else {
			$actions = array_slice( $actions, 0, $limit );
		}

		$insight['recommended_actions'] = array_values( $actions );

		return $insight;
	}

	/**
	 * The single Pro-context bullet Free viewers may see.
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	private function get_pro_context_action() {
		return __( 'Pro adds segment, journey, recording, form, and error context to confirm which visitor group is driving this before you change the page.', 'opti-behavior' );
	}

	/**
	 * Detect a stored "use Pro context" bullet, whoever wrote it.
	 *
	 * Rows generated by earlier versions (and by the Pro adapter) carry the
	 * upsell inline. Pro plugins register their own translated wording through
	 * the filter so the match survives translation.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $action Recommended action entry.
	 * @return bool
	 */
	private function is_pro_context_action( $action ) {
		if ( is_array( $action ) ) {
			$text = '';
			foreach ( array( 'title', 'label', 'action', 'description' ) as $key ) {
				if ( isset( $action[ $key ] ) && is_string( $action[ $key ] ) && '' !== trim( $action[ $key ] ) ) {
					$text = $action[ $key ];
					break;
				}
			}
		} else {
			$text = is_string( $action ) ? $action : '';
		}

		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $text ) {
			return false;
		}

		$needles = apply_filters(
			'opti_behavior_smart_insights_pro_upsell_actions',
			array(
				'Use Pro segment, journey, recording',
				$this->get_pro_context_action(),
			)
		);

		foreach ( (array) $needles as $needle ) {
			$needle = trim( (string) $needle );
			if ( '' !== $needle && false !== stripos( $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduce a related-report type to the canonical key used for reconciliation.
	 *
	 * The payload carries several spellings of the same destination (`recordings`
	 * and `session_recordings`, `heatmap` and `heatmap_detail`, ...). They all
	 * render as one card, so they must reconcile as one report.
	 *
	 * @since 1.4.1
	 *
	 * @param string $type Raw report type.
	 * @return string Canonical type key.
	 */
	private function normalize_report_type( $type ) {
		$type = strtolower( trim( (string) $type ) );

		$aliases = array(
			'recordings'        => 'session_recordings',
			'session_recording' => 'session_recordings',
			'heatmap_detail'    => 'heatmap',
			'heatmaps'          => 'heatmap',
			'errors_friction'   => 'error_tracking',
			'errors'            => 'error_tracking',
			'funnel_analytics'  => 'funnel',
			'funnels'           => 'funnel',
			'journey'           => 'user_journey',
			'device_report'     => 'analytics',
			'ab_test'           => 'ab_testing',
			'ab_testing_pro'    => 'ab_testing',
		);

		return isset( $aliases[ $type ] ) ? $aliases[ $type ] : $type;
	}

	/**
	 * Report destinations that only exist for a Pro viewer.
	 *
	 * A Free viewer sees them in the modal's "does not apply" bucket, so an action
	 * line must not name them either.
	 *
	 * @since 1.4.1
	 *
	 * @param string $type Canonical report type.
	 * @return bool
	 */
	private function is_pro_only_report_type( $type ) {
		return in_array( $type, array( 'session_recordings', 'user_journey', 'form_analytics', 'error_tracking' ), true );
	}

	/**
	 * Measure which report destinations this insight actually offers.
	 *
	 * `present` is every destination the modal renders a card for; `applicable` is
	 * the subset the viewer can really open. Everything present but not applicable
	 * is exactly what the modal collapses under "N other reports do not apply".
	 *
	 * @since 1.4.1
	 *
	 * @param array $insight Insight payload.
	 * @return array{present: array<string, bool>, applicable: array<string, bool>}
	 */
	private function get_report_applicability( $insight ) {
		$present    = array();
		$applicable = array();
		$reports    = isset( $insight['related_reports'] ) && is_array( $insight['related_reports'] ) ? $insight['related_reports'] : array();

		foreach ( $reports as $report ) {
			if ( ! is_array( $report ) ) {
				continue;
			}

			$raw_type = isset( $report['type'] ) ? $report['type'] : ( isset( $report['report_type'] ) ? $report['report_type'] : '' );
			$type     = $this->normalize_report_type( $raw_type );
			if ( '' === $type || 'pro_locked' === $type ) {
				continue;
			}

			$present[ $type ] = true;

			$url = '';
			foreach ( array( 'url', 'href' ) as $url_key ) {
				if ( ! empty( $report[ $url_key ] ) && is_string( $report[ $url_key ] ) ) {
					$url = $report[ $url_key ];
					break;
				}
			}

			$blocked = ( isset( $report['available'] ) && false === $report['available'] )
				|| ( isset( $report['enabled'] ) && false === $report['enabled'] )
				|| ( isset( $report['is_available'] ) && false === $report['is_available'] );

			if ( $blocked || '' === $url ) {
				continue;
			}

			if ( ! $this->has_pro_access && $this->is_pro_only_report_type( $type ) ) {
				continue;
			}

			$applicable[ $type ] = true;
		}

		return array(
			'present'    => $present,
			'applicable' => $applicable,
		);
	}

	/**
	 * Wording that sends the viewer to a report destination.
	 *
	 * Deliberately narrow: only phrasing that names a report is listed. A topic
	 * word ("check JavaScript errors", "reduce checkout fields") is advice about
	 * the page, not a promise that a report can be opened, and must not make an
	 * action line disappear.
	 *
	 * Pro registers its own translated wording through the filter so the match
	 * survives translation of the Pro action templates.
	 *
	 * @since 1.4.1
	 *
	 * @return array<string, string[]>
	 */
	private function get_report_reference_tokens() {
		$tokens = array(
			'session_recordings' => array(
				__( 'recordings', 'opti-behavior' ),
				__( 'session recordings', 'opti-behavior' ),
				__( 'session replays', 'opti-behavior' ),
			),
			'error_tracking'     => array(
				__( 'errors report', 'opti-behavior' ),
				__( 'error report', 'opti-behavior' ),
				__( 'error tracking', 'opti-behavior' ),
				__( 'errors and friction', 'opti-behavior' ),
				__( 'friction report', 'opti-behavior' ),
			),
			'form_analytics'     => array(
				__( 'form analytics', 'opti-behavior' ),
				__( 'form friction', 'opti-behavior' ),
				__( 'form report', 'opti-behavior' ),
			),
			'heatmap'            => array(
				__( 'heatmap', 'opti-behavior' ),
				__( 'heatmaps', 'opti-behavior' ),
			),
			'user_journey'       => array(
				__( 'journeys', 'opti-behavior' ),
				__( 'user journey', 'opti-behavior' ),
				__( 'user journeys', 'opti-behavior' ),
			),
			'funnel'             => array(
				__( 'funnel report', 'opti-behavior' ),
				__( 'funnel analytics', 'opti-behavior' ),
			),
			'ab_testing'         => array(
				__( 'A/B test', 'opti-behavior' ),
				__( 'A/B tests', 'opti-behavior' ),
			),
		);

		return apply_filters( 'opti_behavior_smart_insights_report_reference_tokens', $tokens );
	}

	/**
	 * Short, sentence-ready name of a report destination.
	 *
	 * @since 1.4.1
	 *
	 * @param string $type Canonical report type.
	 * @return string
	 */
	private function get_report_display_name( $type ) {
		$names = apply_filters(
			'opti_behavior_smart_insights_report_display_names',
			array(
				'session_recordings' => __( 'session recordings', 'opti-behavior' ),
				'error_tracking'     => __( 'the errors report', 'opti-behavior' ),
				'form_analytics'     => __( 'form analytics', 'opti-behavior' ),
				'heatmap'            => __( 'the heatmap', 'opti-behavior' ),
				'user_journey'       => __( 'user journeys', 'opti-behavior' ),
				'funnel'             => __( 'the funnel report', 'opti-behavior' ),
				'ab_testing'         => __( 'A/B testing', 'opti-behavior' ),
				'analytics'          => __( 'the analytics report', 'opti-behavior' ),
			)
		);

		return isset( $names[ $type ] ) ? (string) $names[ $type ] : '';
	}

	/**
	 * Find the report destinations an action line names, in reading order.
	 *
	 * @since 1.4.1
	 *
	 * @param string $text Action text.
	 * @return string[] Canonical report types.
	 */
	private function find_reports_named_in_action( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return array();
		}

		$found = array();
		foreach ( $this->get_report_reference_tokens() as $type => $tokens ) {
			$type = $this->normalize_report_type( $type );
			foreach ( (array) $tokens as $token ) {
				$token = trim( (string) $token );
				if ( '' === $token ) {
					continue;
				}

				// Unicode-aware word boundary: "form" must not match "platform",
				// and "error" must not match "errors" (both spellings are listed).
				$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $token, '/' ) . '(?![\p{L}\p{N}])/ui';
				if ( preg_match( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
					$offset = isset( $matches[0][1] ) ? (int) $matches[0][1] : 0;
					if ( ! isset( $found[ $type ] ) || $offset < $found[ $type ] ) {
						$found[ $type ] = $offset;
					}
				}
			}
		}

		asort( $found );

		return array_keys( $found );
	}

	/**
	 * Compose an action line that names only the given report destinations.
	 *
	 * @since 1.4.1
	 *
	 * @param string[] $types Canonical report types.
	 * @return string Empty when nothing can be named.
	 */
	private function build_report_scoped_action( $types ) {
		$names = array();
		foreach ( (array) $types as $type ) {
			$name = $this->get_report_display_name( $type );
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		$names = array_slice( $names, 0, self::MAX_RECOMMENDED_ACTIONS );

		if ( empty( $names ) ) {
			return __( 'Review the measured evidence for this insight before changing the page.', 'opti-behavior' );
		}

		if ( 1 === count( $names ) ) {
			/* translators: %s: name of a report, for example "session recordings". */
			return sprintf( __( 'Review %s for this insight.', 'opti-behavior' ), $names[0] );
		}

		/* translators: %l: list of report names, for example "session recordings and the errors report". */
		return wp_sprintf( __( 'Review %l together.', 'opti-behavior' ), $names );
	}

	/**
	 * Keep an action line consistent with the reports the modal marks applicable.
	 *
	 * An action must never send the viewer to a report the same modal collapses
	 * under "does not apply". When some of the named reports are applicable the
	 * line is rebuilt from the measured subset; when none are, it is dropped.
	 *
	 * @since 1.4.1
	 *
	 * @param mixed $action        Recommended action entry.
	 * @param array $applicability Output of get_report_applicability().
	 * @return mixed|null Reconciled action, or null when it must be dropped.
	 */
	private function reconcile_action_with_reports( $action, $applicability ) {
		$present    = isset( $applicability['present'] ) ? (array) $applicability['present'] : array();
		$applicable = isset( $applicability['applicable'] ) ? (array) $applicability['applicable'] : array();

		if ( empty( $present ) ) {
			return $action;
		}

		$text_key = '';
		if ( is_array( $action ) ) {
			foreach ( array( 'title', 'label', 'action', 'description' ) as $key ) {
				if ( isset( $action[ $key ] ) && is_string( $action[ $key ] ) && '' !== trim( $action[ $key ] ) ) {
					$text_key = $key;
					break;
				}
			}
			$text = '' !== $text_key ? $action[ $text_key ] : '';
		} else {
			$text = is_string( $action ) ? $action : '';
		}

		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $text ) {
			return $action;
		}

		$named = $this->find_reports_named_in_action( $text );
		if ( empty( $named ) ) {
			return $action;
		}

		$contradicted = array();
		$kept         = array();
		foreach ( $named as $type ) {
			if ( isset( $present[ $type ] ) && ! isset( $applicable[ $type ] ) ) {
				$contradicted[] = $type;
				continue;
			}

			$kept[] = $type;
		}

		if ( empty( $contradicted ) ) {
			return $action;
		}

		if ( empty( $kept ) ) {
			return null;
		}

		$rebuilt = $this->build_report_scoped_action( $kept );
		if ( '' === $rebuilt ) {
			return null;
		}

		if ( is_array( $action ) && '' !== $text_key ) {
			$action[ $text_key ] = $rebuilt;
			return $action;
		}

		return $rebuilt;
	}

	/**
	 * Shape Pro-only records into locked previews for Free viewers.
	 *
	 * @param array  $insight Insight payload.
	 * @param string $context View context.
	 * @return array
	 */
	private function shape_locked_preview( $insight, $context ) {
		$insight['metrics']             = array();
		$insight['detection']           = array();
		$insight['segment']             = array();
		$insight['trend']               = array();
		// The outcome chip prints the Pro-only metric this insight was measured
		// on, so it stays behind the same lock as the metrics themselves.
		$insight['outcome']             = array();
		$insight['related_reports']     = array();
		$insight['recommended_actions'] = array(
			__( 'Upgrade to Pro to see the affected segment, related evidence, and recommended fixes.', 'opti-behavior' ),
		);
		$insight['likely_causes']       = array();
		$insight['why_it_matters']      = __( 'A Pro-only behavioral pattern may need attention, but detailed evidence is locked in the Free version.', 'opti-behavior' );
		$locked_preview_text            = '';
		if ( isset( $insight['locked_preview'] ) ) {
			if ( is_array( $insight['locked_preview'] ) ) {
				$locked_preview_text = isset( $insight['locked_preview']['description'] ) ? (string) $insight['locked_preview']['description'] : '';
			} else {
				$locked_preview_text = (string) $insight['locked_preview'];
			}
		}
		$insight['interpretation']      = '' !== $locked_preview_text
			? wp_strip_all_tags( $locked_preview_text )
			: __( 'Pro insight available. Upgrade to unlock the full diagnosis and next-step recommendations.', 'opti-behavior' );
		$insight['locked_preview']      = array(
			'title'       => isset( $insight['signal_name'] ) ? $insight['signal_name'] : __( 'Pro insight available', 'opti-behavior' ),
			'description' => $insight['interpretation'],
			'context'     => sanitize_key( $context ),
		);

		return $insight;
	}
}
