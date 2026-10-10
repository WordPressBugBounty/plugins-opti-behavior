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
	 * @param array  $insight       Insight payload.
	 * @param string $context       View context: list, detail, dashboard.
	 * @param bool   $apply_filters False = skip `opti_behavior_smart_insights_shape_payload`
	 *                              (Pro related-report links, one query each): the list
	 *                              matches search / confidence on the rest (OPT-268).
	 * @return array
	 */
	public function filter_insight_for_viewer( $insight, $context = 'list', $apply_filters = true ) {
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

		// The card shows the short form of the next action (the modal the full one).
		$first = isset( $insight['recommended_actions'] ) && is_array( $insight['recommended_actions'] ) ? reset( $insight['recommended_actions'] ) : '';
		if ( is_array( $first ) ) {
			$first = isset( $first['title'] ) ? $first['title'] : ( isset( $first['label'] ) ? $first['label'] : ( isset( $first['action'] ) ? $first['action'] : '' ) );
		}
		$insight['next_action_short'] = class_exists( 'Opti_Behavior_Smart_Insights_Recommendations' ) && is_string( $first ) ? Opti_Behavior_Smart_Insights_Recommendations::card_action( $first ) : '';

		$insight = $this->apply_card_copy( $insight );
		$insight = $this->shape_also_seen_here( $insight );
		$insight = $this->shape_attached_actions( $insight );
		$insight = $this->shape_chart( $insight );

		if ( ! $apply_filters ) {
			return $insight;
		}

		return apply_filters( 'opti_behavior_smart_insights_shape_payload', $insight, $context, $this->has_pro_access );
	}

	/**
	 * Card title + the card's one complete action (OPT-362 lot 2): Free
	 * families from Recommendations::card_copy(), Pro families through the
	 * `opti_behavior_smart_insights_card_copy` filter. Both fields are optional:
	 * empty = the screen keeps signal_name / next_action_short. Locked previews
	 * keep their teaser copy. A filter may also replace `recommended_actions`
	 * (the Next steps 2 and 3), never with an empty list.
	 *
	 * @param array $insight Shaped insight payload.
	 * @return array
	 */
	private function apply_card_copy( $insight ) {
		if ( ! empty( $insight['is_locked_preview'] ) || ! class_exists( 'Opti_Behavior_Smart_Insights_Recommendations' ) ) {
			return $insight;
		}

		$copy = Opti_Behavior_Smart_Insights_Recommendations::card_copy( $insight );
		try {
			$copy = apply_filters( 'opti_behavior_smart_insights_card_copy', $copy, $insight, $this->has_pro_access );
		} catch ( Throwable $e ) {
			// A faulty provider never costs the card its Free copy.
			$copy = Opti_Behavior_Smart_Insights_Recommendations::card_copy( $insight );
		}
		if ( ! is_array( $copy ) ) {
			return $insight;
		}

		foreach ( array( 'card_title', 'action_short' ) as $key ) {
			$value = isset( $copy[ $key ] ) && is_scalar( $copy[ $key ] ) ? trim( sanitize_text_field( (string) $copy[ $key ] ) ) : '';
			if ( '' !== $value ) {
				$insight[ $key ] = $value;
			}
		}
		if ( ! empty( $insight['action_short'] ) ) {
			$insight['next_action_short'] = $insight['action_short'];
		}
		if ( ! empty( $copy['recommended_actions'] ) && is_array( $copy['recommended_actions'] ) ) {
			$actions = array_values( array_filter( array_map( 'sanitize_text_field', array_filter( $copy['recommended_actions'], 'is_scalar' ) ) ) );
			if ( ! empty( $actions ) ) {
				$insight['recommended_actions'] = $actions;
			}
		}

		return $insight;
	}

	/**
	 * "Also seen here" lines of a merged card (OPT-378 A, repository
	 * merge_entity_families()): each merged signal = its card title (same copy
	 * as its own card would get) + one figure, visits lost. Raw metrics and
	 * detection never leave the server; a locked preview shows none.
	 *
	 * @param array $insight Shaped insight payload.
	 * @return array
	 */
	private function shape_also_seen_here( $insight ) {
		if ( empty( $insight['also_seen_here'] ) || ! is_array( $insight['also_seen_here'] ) ) {
			unset( $insight['also_seen_here'] );
			return $insight;
		}
		if ( ! empty( $insight['is_locked_preview'] ) ) {
			unset( $insight['also_seen_here'], $insight['merged_ids'] );
			return $insight;
		}

		$lines = array();
		foreach ( $insight['also_seen_here'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$copy  = $this->apply_card_copy( $item + array( 'is_locked_preview' => false ) );
			$title = ! empty( $copy['card_title'] ) ? $copy['card_title'] : sanitize_text_field( (string) ( $item['signal_name'] ?? '' ) );
			if ( '' === $title ) {
				$title = ucfirst( str_replace( '_', ' ', sanitize_key( (string) ( $item['signal_id'] ?? '' ) ) ) );
			}
			$lines[] = array(
				'id'         => isset( $item['id'] ) ? absint( $item['id'] ) : 0,
				'signal_id'  => sanitize_key( (string) ( $item['signal_id'] ?? '' ) ),
				'title'      => $title,
				'users_lost' => isset( $item['users_lost'] ) ? absint( $item['users_lost'] ) : 0,
			);
		}

		$insight['also_seen_here'] = $lines;
		if ( $lines ) {
			$insight['also_seen_label'] = __( 'Also seen here:', 'opti-behavior' );
		}

		return $insight;
	}

	/**
	 * Actions carried by the card of the same page (OPT-378 B, repository
	 * attach_action_signals()): `recording_action` = the card's proof button
	 * "Watch N recordings of this page" (locked for a viewer without Pro, like
	 * the other Pro previews), `ab_action` = "Start an A/B test" from the
	 * stored A/B opportunity (Pro viewers only: the experiment it suggests is
	 * Pro data). Nothing on a locked preview card.
	 *
	 * @param array $insight Shaped insight payload.
	 * @return array
	 */
	private function shape_attached_actions( $insight ) {
		$recording = isset( $insight['recording_action'] ) && is_array( $insight['recording_action'] ) ? $insight['recording_action'] : array();
		$ab        = isset( $insight['ab_action'] ) && is_array( $insight['ab_action'] ) ? $insight['ab_action'] : array();
		unset( $insight['recording_action'], $insight['ab_action'] );
		if ( ! empty( $insight['is_locked_preview'] ) ) {
			return $insight;
		}

		// No count in the label: the stored `recordings` metric of the
		// opportunity is not scoped to the page (Pro provider, recordings table
		// without a url column), the recordings list it opens is. A Pro viewer
		// gets the button only when Pro finds recordings of THIS page in the
		// card's period (else the list it opens is empty); the locked preview
		// opens no list.
		$count = isset( $recording['recordings'] ) ? absint( $recording['recordings'] ) : 0;
		if ( $count > 0 && $this->has_pro_access ) {
			$count = self::page_recordings_count( $insight );
		}
		if ( $count > 0 ) {
			$insight['recording_action'] = array(
				'locked' => ! $this->has_pro_access,
				'label'  => __( 'Watch the recordings of this page', 'opti-behavior' ),
			);
		}

		$ab_id = isset( $ab['id'] ) ? absint( $ab['id'] ) : 0;
		if ( $ab_id > 0 && $this->has_pro_access ) {
			$insight['ab_action'] = array(
				'insight_id' => $ab_id,
				'days'       => isset( $ab['days'] ) ? absint( $ab['days'] ) : 0,
				'label'      => __( 'Start an A/B test', 'opti-behavior' ),
			);
		}

		return $insight;
	}

	/**
	 * Recorded visits of the card's page, period and spam scope, answered by
	 * Pro through `opti_behavior_smart_insights_page_recordings` (OPT-378 B).
	 * No answer (older Pro, feature off, page not addressable) = 0.
	 *
	 * @param array $insight Shaped insight payload.
	 * @return int
	 */
	private static function page_recordings_count( $insight ) {
		try {
			$count = apply_filters( 'opti_behavior_smart_insights_page_recordings', null, $insight );
		} catch ( Throwable $e ) {
			$count = null;
		}

		return is_numeric( $count ) ? max( 0, (int) $count ) : 0;
	}

	/**
	 * The card chart (OPT-378 C): Recommendations::chart_data() from the
	 * stored figures, none when a figure is missing or the card is locked.
	 *
	 * @param array $insight Shaped insight payload.
	 * @return array
	 */
	private function shape_chart( $insight ) {
		unset( $insight['chart'] );
		if ( ! empty( $insight['is_locked_preview'] ) || ! class_exists( 'Opti_Behavior_Smart_Insights_Recommendations' ) ) {
			return $insight;
		}
		$chart = Opti_Behavior_Smart_Insights_Recommendations::chart_data( $insight );
		if ( ! empty( $chart ) ) {
			$insight['chart'] = $chart;
		}

		return $insight;
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
	 * Whether filter_insight_for_viewer() would turn this row into a locked preview.
	 *
	 * Lets the list count rows it does not shape (OPT-268): a locked preview
	 * loses its `detection`, which the "Recurring" count reads.
	 *
	 * @since 1.9.2
	 * @param array $insight Insight payload.
	 * @return bool
	 */
	public function is_locked_for_viewer( $insight ) {
		if ( ! is_array( $insight ) ) {
			return false;
		}

		$tier = $this->normalize_visibility_tier( $insight );

		return self::TIER_PRO_LOCKED === $tier || ( self::TIER_PRO === $tier && ! $this->has_pro_access );
	}

	/**
	 * Shape a computed "Who is affected?" segment matrix for the current viewer.
	 *
	 * Pro receives the full matrix (built by the Pro builder) and can re-scope
	 * the insight's evidence links by segment. Since OPT-403 (WordPress.org
	 * guideline 5) the per-audience breakdown is not computed for a Free viewer
	 * at all: Free gets everything the Free builder computed (scope numbers,
	 * metric, the whole traffic mix) plus the upgrade line, and no teaser built
	 * from Pro-only results.
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
			// Breakdown fields are always emptied, also when a Pro builder ran for a
			// viewer whose access closed in between: the Free payload only carries
			// what the Free builder computes.
			$matrix = array(
				'version'         => isset( $matrix['version'] ) ? (int) $matrix['version'] : 1,
				'available'       => ! empty( $matrix['available'] ),
				'reason'          => isset( $matrix['reason'] ) ? sanitize_key( $matrix['reason'] ) : '',
				'total_sessions'  => isset( $matrix['total_sessions'] ) ? (int) $matrix['total_sessions'] : 0,
				'scope_sessions'  => isset( $matrix['scope_sessions'] ) ? (int) $matrix['scope_sessions'] : 0,
				// "12 of the 30 sessions needed" is not a paid answer.
				'min_scope_sessions' => isset( $matrix['min_scope_sessions'] ) ? (int) $matrix['min_scope_sessions'] : 0,
				'exclude_spam'    => ! empty( $matrix['exclude_spam'] ),
				'primary_metric'  => isset( $matrix['primary_metric'] ) ? sanitize_key( $matrix['primary_metric'] ) : '',
				'metric_label'    => isset( $matrix['metric_label'] ) ? (string) $matrix['metric_label'] : '',
				'metric_unit'     => isset( $matrix['metric_unit'] ) ? sanitize_key( $matrix['metric_unit'] ) : '',
				'metric_type'     => isset( $matrix['metric_type'] ) ? sanitize_key( $matrix['metric_type'] ) : '',
				'segments_included' => false,
				// Not measured is not "the same everywhere".
				'uniform'         => false,
				'outliers'        => array(),
				'locked_outlier_count' => 0,
				'dimension_count' => 0,
				'dimension_pulses' => array(),
				'combo_dimension_count' => 0,
				'locked_combo_count'    => 0,
				'locked_combo_teaser'   => null,
				'traffic_mix'     => $this->shape_free_traffic_mix( isset( $matrix['traffic_mix'] ) ? $matrix['traffic_mix'] : null ),
				'dimensions'      => array(),
				'top_segment'     => null,
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
	 * Free-tier shape of the traffic-mix block: the whole block.
	 *
	 * The Free code computes the table, so a Free viewer sees all of it
	 * (WordPress.org guideline 5: no computed result is held back until the
	 * user pays). A quiet or unavailable mix stays null, like before.
	 *
	 * @since 1.4.1
	 *
	 * @param array|null $traffic_mix Computed traffic-mix block.
	 * @return array|null
	 */
	private function shape_free_traffic_mix( $traffic_mix ) {
		if ( ! is_array( $traffic_mix ) || empty( $traffic_mix['available'] ) ) {
			return null;
		}

		$traffic_mix['locked'] = false;

		return $traffic_mix;
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
	 * The correlation (each cause with its measured share) and the impact block
	 * (lost visitors and revenue exposure, WooCommerce or owner value) are
	 * computed by the Free code, so a Free viewer sees them in full
	 * (WordPress.org guideline 5). Only the raw probe payloads are dropped: no
	 * screen reads them. The hypothesis, the experiment, and the evidence refs
	 * below come from Pro modules and stay a preview.
	 *
	 * @since 1.3.8
	 *
	 * @param array $insight Insight payload.
	 * @return array
	 */
	private function shape_story_blocks_for_free( $insight ) {
		if ( ! empty( $insight['correlation'] ) && is_array( $insight['correlation'] ) ) {
			unset( $insight['correlation']['probes'] );
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
	 * Cap the recommended actions and place the Pro-context bullet honestly.
	 *
	 * A Pro viewer never sees a "use Pro context" bullet: they already have it,
	 * so it is pure noise in a list that is capped at three lines. A Free viewer
	 * gets the same real actions as a Pro viewer (WordPress.org guideline 5: a
	 * computed action is never swapped for an upsell) and the bullet only in a
	 * free slot, once, last. Doing this here means the JS renderer never has to
	 * branch on the viewer's tier.
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

		$actions = array_slice( $actions, 0, $limit );

		if ( ! $this->has_pro_access && ! $is_locked_preview && ! empty( $actions ) && count( $actions ) < $limit ) {
			$actions[] = $this->get_pro_context_action();
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
		return in_array( $type, array( 'session_recordings', 'user_journey', 'form_analytics', 'error_tracking', 'page_xray' ), true );
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
