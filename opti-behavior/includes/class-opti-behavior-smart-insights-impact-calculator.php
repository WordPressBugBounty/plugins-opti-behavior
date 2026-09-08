<?php
/**
 * Smart Insights Impact Calculator Class
 *
 * Converts a detected signal (or a correlated story) into an absolute business
 * impact: how many visitors the leak costs per analysis window, and - when a
 * WooCommerce order history is readable - the revenue exposure behind them.
 *
 * The engine ranks "largest leak first": a 50% drop on a 100-session page loses
 * 50 visitors, while a 10% drop on a 10,000-session page loses 1,000. Relative
 * percentages alone would invert that order, so `users_lost` is the primary
 * ranking key and the classic 0-100 priority score stays untouched for backward
 * compatibility.
 *
 * Everything here is deterministic and explainable: every number is a documented
 * arithmetic step over stored aggregate metrics, never an estimate produced by a
 * model. When the inputs for a basis are missing the calculator returns null and
 * the insight keeps rendering through the classic card path.
 *
 * @package opti-behavior
 * @since   1.3.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Impact Calculator Class.
 *
 * @since 1.3.8
 */
class Opti_Behavior_Smart_Insights_Impact_Calculator {

	/**
	 * Impact payload schema version.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Maximum cause attributions persisted per impact payload.
	 */
	const MAX_CAUSE_ATTRIBUTIONS = 6;

	/**
	 * Option holding the manually configured value of one conversion.
	 *
	 * Shape: `array( 'amount' => float, 'currency' => string )`.
	 */
	const CONVERSION_VALUE_OPTION = 'opti_behavior_smart_insights_conversion_value';

	/**
	 * Revenue source identifier for the manually configured conversion value.
	 */
	const REVENUE_SOURCE_MANUAL = 'manual_conversion_value';

	/**
	 * Basis identifiers, ordered from most to least specific.
	 */
	const BASIS_FUNNEL_DROPOFF   = 'funnel_dropoff';
	const BASIS_FORM_ABANDONMENT = 'form_abandonment';
	const BASIS_ERROR_IMPACT     = 'error_impact';
	const BASIS_CONVERSION_GAP   = 'conversion_gap';
	const BASIS_ENGAGEMENT_GAP   = 'engagement_gap';

	/**
	 * Memoized revenue context per date-range key.
	 *
	 * @var array
	 */
	private $revenue_context_cache = array();

	/**
	 * Attach impact payloads to a correlated candidate batch.
	 *
	 * The candidate order is preserved on purpose: the correlator already sorted
	 * story primaries before their children so the generator can resolve
	 * `parent_insight_id` after persistence. Ranking is expressed through the
	 * stored `impact_rank` / `impact_score` fields, not through array order.
	 *
	 * @param array $candidates Candidate records (`insight`, `metrics`, `baselines`).
	 * @param array $date_range Generation date range.
	 * @param array $args       Generation args.
	 * @return array
	 */
	public function apply_to_candidates( $candidates, $date_range = array(), $args = array() ) {
		if ( empty( $candidates ) || ! is_array( $candidates ) ) {
			return is_array( $candidates ) ? $candidates : array();
		}

		$candidates = array_values( $candidates );
		$date_range = is_array( $date_range ) ? $date_range : array();
		$args       = is_array( $args ) ? $args : array();

		$impacts        = array();
		$max_users_lost = 0.0;

		foreach ( $candidates as $index => $candidate ) {
			if ( empty( $candidate['insight'] ) || ! is_array( $candidate['insight'] ) ) {
				continue;
			}

			$impact = $this->calculate_impact(
				$candidate['insight'],
				array(
					'metrics'    => isset( $candidate['metrics'] ) && is_array( $candidate['metrics'] ) ? $candidate['metrics'] : array(),
					'baselines'  => isset( $candidate['baselines'] ) && is_array( $candidate['baselines'] ) ? $candidate['baselines'] : array(),
					'date_range' => $date_range,
					'args'       => $args,
				)
			);

			if ( empty( $impact ) ) {
				continue;
			}

			$impacts[ $index ] = $impact;
			$max_users_lost    = max( $max_users_lost, (float) $impact['users_lost'] );
		}

		if ( empty( $impacts ) ) {
			return $candidates;
		}

		$ranking = $impacts;
		uasort(
			$ranking,
			function ( $left, $right ) {
				if ( (float) $left['users_lost'] === (float) $right['users_lost'] ) {
					return (float) $right['revenue']['amount'] <=> (float) $left['revenue']['amount'];
				}

				return (float) $right['users_lost'] <=> (float) $left['users_lost'];
			}
		);

		$rank = 0;
		foreach ( array_keys( $ranking ) as $index ) {
			++$rank;
			$impacts[ $index ]['impact_rank']           = $rank;
			$impacts[ $index ]['impact_score']          = $max_users_lost > 0
				? (int) round( min( 100, ( (float) $impacts[ $index ]['users_lost'] / $max_users_lost ) * 100 ) )
				: 0;
			$impacts[ $index ]['batch_peak_users_lost'] = (int) round( $max_users_lost );
		}

		foreach ( $impacts as $index => $impact ) {
			$insight = $candidates[ $index ]['insight'];

			$insight['impact']           = $impact;
			$insight['scores']           = isset( $insight['scores'] ) && is_array( $insight['scores'] ) ? $insight['scores'] : array();
			$insight['scores']['impact'] = array(
				'users_lost'        => (int) $impact['users_lost'],
				'impact_score'      => (int) $impact['impact_score'],
				'impact_rank'       => (int) $impact['impact_rank'],
				'basis'             => $impact['basis'],
				'revenue_available' => ! empty( $impact['revenue']['available'] ),
			);

			$insight = $this->apply_evidence_confidence( $insight );

			$candidates[ $index ]['insight'] = $insight;
		}

		return $candidates;
	}

	/**
	 * Calculate the impact payload for a single insight.
	 *
	 * @param array $insight Insight payload.
	 * @param array $context Optional context (`metrics`, `baselines`, `date_range`, `args`).
	 * @return array|null Impact payload, or null when no basis is measurable.
	 */
	public function calculate_impact( $insight, $context = array() ) {
		if ( ! is_array( $insight ) ) {
			return null;
		}

		$context = is_array( $context ) ? $context : array();
		$basis   = $this->resolve_basis( $insight, $context );
		if ( empty( $basis ) ) {
			return null;
		}

		$users_lost = (int) round( $basis['users_lost'] );
		if ( $users_lost < 1 ) {
			return null;
		}

		$correlation = isset( $insight['correlation'] ) && is_array( $insight['correlation'] ) ? $insight['correlation'] : array();
		$revenue     = $this->calculate_revenue_exposure( $users_lost, $basis, $insight, $context );

		$impact = array(
			'version'               => self::SCHEMA_VERSION,
			'users_lost'            => $users_lost,
			'population'            => (int) round( $basis['population'] ),
			'loss_rate'             => round( (float) $basis['loss_rate'], 4 ),
			'basis'                 => $basis['basis'],
			'basis_label'           => $basis['basis_label'],
			'population_label'      => $basis['population_label'],
			'headline'              => $this->build_headline( $users_lost, $basis['basis'] ),
			'impact_score'          => 0,
			'impact_rank'           => 0,
			'batch_peak_users_lost' => $users_lost,
			'causes'                => $this->attribute_causes( $users_lost, $correlation ),
			'revenue'               => $revenue,
			'calculated_at'         => $this->now(),
		);

		/**
		 * Filter a calculated absolute-impact payload.
		 *
		 * @since 1.3.8
		 *
		 * @param array $impact  Impact payload.
		 * @param array $insight Insight payload.
		 * @param array $context Calculation context.
		 */
		$impact = apply_filters( 'opti_behavior_smart_insights_impact', $impact, $insight, $context );

		return is_array( $impact ) && ! empty( $impact['users_lost'] ) ? $impact : null;
	}

	/**
	 * Read the absolute users-lost value from a stored or in-flight insight.
	 *
	 * @param array $insight Insight payload.
	 * @return int|null Users lost, or null when the insight carries no impact block.
	 */
	public static function get_users_lost( $insight ) {
		if ( ! is_array( $insight ) ) {
			return null;
		}

		foreach ( array( 'impact', 'scores' ) as $bag_key ) {
			$bag = isset( $insight[ $bag_key ] ) && is_array( $insight[ $bag_key ] ) ? $insight[ $bag_key ] : array();
			if ( 'scores' === $bag_key ) {
				$bag = isset( $bag['impact'] ) && is_array( $bag['impact'] ) ? $bag['impact'] : array();
			}

			if ( isset( $bag['users_lost'] ) && is_numeric( $bag['users_lost'] ) ) {
				return (int) $bag['users_lost'];
			}
		}

		return null;
	}

	/**
	 * Read the measurable revenue exposure from a stored or in-flight insight.
	 *
	 * Only an `available` revenue block returns a number: a locked Free hint or a
	 * missing commerce source is not an amount and must never rank an insight.
	 *
	 * @since 1.3.9
	 *
	 * @param array $insight Insight payload.
	 * @return float|null Amount, or null when the insight carries no readable revenue.
	 */
	public static function get_revenue_amount( $insight ) {
		if ( ! is_array( $insight ) || empty( $insight['impact']['revenue'] ) || ! is_array( $insight['impact']['revenue'] ) ) {
			return null;
		}

		$revenue = $insight['impact']['revenue'];
		if ( empty( $revenue['available'] ) || ! isset( $revenue['amount'] ) || ! is_numeric( $revenue['amount'] ) ) {
			return null;
		}

		return (float) $revenue['amount'];
	}

	/**
	 * Largest-leak-first comparator for two decoded insight rows.
	 *
	 * Money wins when both sides carry a measured amount: a euro figure is the
	 * number the site owner acts on. Otherwise the comparison falls back to the
	 * absolute visitor loss.
	 *
	 * The comparison is only decisive when BOTH rows carry an impact block. A
	 * mixed pair (one legacy row, one measured row) returns 0 on purpose so the
	 * caller keeps its existing priority ordering and no install regresses just
	 * because part of its history predates impact scoring.
	 *
	 * @param array $left  Left insight.
	 * @param array $right Right insight.
	 * @return int Negative when left ranks first, positive when right ranks first, 0 when undecided.
	 */
	public static function compare_by_impact( $left, $right ) {
		$left_revenue  = self::get_revenue_amount( $left );
		$right_revenue = self::get_revenue_amount( $right );

		if ( null !== $left_revenue && null !== $right_revenue && $left_revenue !== $right_revenue ) {
			return $right_revenue <=> $left_revenue;
		}

		$left_users  = self::get_users_lost( $left );
		$right_users = self::get_users_lost( $right );

		if ( null === $left_users || null === $right_users || $left_users === $right_users ) {
			return 0;
		}

		return $right_users <=> $left_users;
	}

	/**
	 * Build the translated absolute-impact headline.
	 *
	 * @param int    $users_lost Users lost.
	 * @param string $basis      Basis identifier.
	 * @return string
	 */
	public function build_headline( $users_lost, $basis = '' ) {
		$users_lost = max( 0, (int) $users_lost );
		$formatted  = function_exists( 'number_format_i18n' ) ? number_format_i18n( $users_lost ) : (string) $users_lost;

		switch ( $basis ) {
			case self::BASIS_FUNNEL_DROPOFF:
				/* translators: %s: number of visitors. */
				return sprintf( _n( '%s visitor dropped out of this funnel', '%s visitors dropped out of this funnel', $users_lost, 'opti-behavior' ), $formatted );
			case self::BASIS_FORM_ABANDONMENT:
				/* translators: %s: number of visitors. */
				return sprintf( _n( '%s visitor abandoned this form', '%s visitors abandoned this form', $users_lost, 'opti-behavior' ), $formatted );
			case self::BASIS_ERROR_IMPACT:
				/* translators: %s: number of visitors. */
				return sprintf( _n( '%s visitor hit an error here', '%s visitors hit an error here', $users_lost, 'opti-behavior' ), $formatted );
			case self::BASIS_CONVERSION_GAP:
				/* translators: %s: number of visitors. */
				return sprintf( _n( '%s conversion lost versus your site average', '%s conversions lost versus your site average', $users_lost, 'opti-behavior' ), $formatted );
			default:
				/* translators: %s: number of visitors. */
				return sprintf( _n( '%s visitor lost versus your site average', '%s visitors lost versus your site average', $users_lost, 'opti-behavior' ), $formatted );
		}
	}

	/**
	 * Add evidence-anchored confidence points to a scored insight.
	 *
	 * Confidence rises with the number of independent corroborating probes, the
	 * sample sizes behind them, and the number of distinct signals that fired on
	 * the same scope. Uncorrelated insights are returned untouched.
	 *
	 * @param array $insight Insight payload.
	 * @return array
	 */
	public function apply_evidence_confidence( $insight ) {
		if ( ! is_array( $insight ) || empty( $insight['correlation'] ) || ! is_array( $insight['correlation'] ) ) {
			return $insight;
		}

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Scorer' ) ) {
			return $insight;
		}

		$scorer  = new Opti_Behavior_Smart_Insights_Scorer();
		$scores  = isset( $insight['scores'] ) && is_array( $insight['scores'] ) ? $insight['scores'] : array();
		$updated = $scorer->apply_evidence_confidence( $scores, $insight['correlation'] );

		if ( is_array( $updated ) ) {
			$insight['scores'] = $updated;
		}

		return $insight;
	}

	/**
	 * Resolve the measurable loss basis for an insight.
	 *
	 * Bases are evaluated most-specific first so a funnel story is measured on
	 * its entrants rather than on the page sessions behind it.
	 *
	 * @param array $insight Insight payload.
	 * @param array $context Calculation context.
	 * @return array|null
	 */
	private function resolve_basis( $insight, $context ) {
		$metrics   = $this->collect_metric_bag( $insight, $context );
		$detection = isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array();

		$override = $this->resolve_basis_override( $detection, $metrics );
		if ( null !== $override ) {
			return $override;
		}

		$entries      = $this->positive_int( $metrics, array( 'entries', 'step_entries', 'funnel_entries' ) );
		$dropoff_rate = $this->percentage( $metrics, array( 'dropoff_rate', 'drop_off_rate', 'step_dropoff_rate' ) );
		if ( $entries > 0 && null !== $dropoff_rate && $dropoff_rate > 0 ) {
			return $this->build_basis( self::BASIS_FUNNEL_DROPOFF, $entries, $dropoff_rate / 100 );
		}

		$starts           = $this->positive_int( $metrics, array( 'starts', 'form_starts' ) );
		$abandonment_rate = $this->percentage( $metrics, array( 'abandonment_rate', 'form_abandonment_rate' ) );
		if ( $starts > 0 && null !== $abandonment_rate && $abandonment_rate > 0 ) {
			return $this->build_basis( self::BASIS_FORM_ABANDONMENT, $starts, $abandonment_rate / 100 );
		}

		$sessions       = $this->positive_int( $metrics, array( 'sessions', 'pageviews', 'users' ) );
		$error_sessions = $this->positive_int( $metrics, array( 'error_sessions', 'affected_sessions' ) );
		if ( $error_sessions > 0 ) {
			$population = $sessions > 0 ? max( $sessions, $error_sessions ) : $error_sessions;

			return $this->build_basis( self::BASIS_ERROR_IMPACT, $population, $error_sessions / $population );
		}

		if ( $sessions > 0 ) {
			$conversion_gap = $this->positive_gap(
				$this->percentage( $metrics, array( 'site_avg_conversion_rate' ) ),
				$this->percentage( $metrics, array( 'conversion_rate' ) )
			);
			if ( null !== $conversion_gap ) {
				return $this->build_basis( self::BASIS_CONVERSION_GAP, $sessions, $conversion_gap / 100 );
			}

			$engagement_gap = $this->resolve_engagement_gap( $metrics );
			if ( null !== $engagement_gap ) {
				return $this->build_basis( self::BASIS_ENGAGEMENT_GAP, $sessions, $engagement_gap / 100, $metrics );
			}
		}

		return null;
	}

	/**
	 * Honor an explicit users-lost override published by a signal or provider.
	 *
	 * Pro signals that already know their affected population can publish
	 * `detection.impact_users_lost` (plus optional `impact_population` and
	 * `impact_basis`) instead of relying on generic metric-key sniffing.
	 *
	 * @param array $detection Detection payload.
	 * @param array $metrics   Flattened metric bag.
	 * @return array|null
	 */
	private function resolve_basis_override( $detection, $metrics ) {
		if ( ! isset( $detection['impact_users_lost'] ) || ! is_numeric( $detection['impact_users_lost'] ) ) {
			return null;
		}

		$users_lost = max( 0.0, (float) $detection['impact_users_lost'] );
		if ( $users_lost < 1 ) {
			return null;
		}

		$population = isset( $detection['impact_population'] ) && is_numeric( $detection['impact_population'] )
			? max( 0, (int) $detection['impact_population'] )
			: $this->positive_int( $metrics, array( 'entries', 'starts', 'sessions', 'pageviews' ) );
		$population = $population > 0 ? max( $population, (int) ceil( $users_lost ) ) : (int) ceil( $users_lost );

		$basis = isset( $detection['impact_basis'] ) ? sanitize_key( $detection['impact_basis'] ) : self::BASIS_ENGAGEMENT_GAP;
		if ( ! in_array( $basis, $this->get_basis_ids(), true ) ) {
			$basis = self::BASIS_ENGAGEMENT_GAP;
		}

		return $this->build_basis( $basis, $population, $users_lost / $population, $metrics );
	}

	/**
	 * Pick the widest engagement deficit available for a page-like entity.
	 *
	 * @param array $metrics Flattened metric bag.
	 * @return float|null Percentage-point gap on the 0-100 scale.
	 */
	private function resolve_engagement_gap( $metrics ) {
		// Key order matters: when a signal diagnosed against a peer segment, that
		// comparison wins over the site average so the priced gap is the gap the
		// card describes.
		$candidates = array(
			$this->positive_gap( $this->percentage( $metrics, array( 'bounce_rate' ) ), $this->percentage( $metrics, array( 'comparison_bounce_rate', 'site_avg_bounce_rate' ) ) ),
			$this->positive_gap( $this->percentage( $metrics, array( 'exit_rate' ) ), $this->percentage( $metrics, array( 'comparison_exit_rate', 'site_avg_exit_rate' ) ) ),
			$this->positive_gap( $this->percentage( $metrics, array( 'comparison_scroll_depth', 'site_avg_scroll_depth' ) ), $this->percentage( $metrics, array( 'avg_scroll_depth' ) ) ),
			$this->positive_gap( $this->percentage( $metrics, array( 'comparison_cta_click_rate', 'site_avg_cta_click_rate' ) ), $this->percentage( $metrics, array( 'cta_click_rate' ) ) ),
		);

		$gap = null;
		foreach ( $candidates as $candidate ) {
			if ( null !== $candidate && ( null === $gap || $candidate > $gap ) ) {
				$gap = $candidate;
			}
		}

		return $gap;
	}

	/**
	 * Compose a normalized basis record.
	 *
	 * @param string $basis      Basis identifier.
	 * @param int    $population Denominator.
	 * @param float  $loss_rate  Loss rate on a 0-1 scale.
	 * @param array  $metrics    Optional metric bag, used to name the comparison.
	 * @return array
	 */
	private function build_basis( $basis, $population, $loss_rate, $metrics = array() ) {
		$population = max( 0, (int) $population );
		$loss_rate  = max( 0.0, min( 1.0, (float) $loss_rate ) );
		$labels     = $this->get_basis_labels();
		$label      = isset( $labels[ $basis ] ) ? $labels[ $basis ] : $labels[ self::BASIS_ENGAGEMENT_GAP ];
		$basis_label = $label['basis'];

		// Name the peer the gap was measured against so the impact line and the
		// diagnosis line on the card cannot claim different baselines.
		if ( self::BASIS_ENGAGEMENT_GAP === $basis
			&& is_array( $metrics )
			&& isset( $metrics['comparison_device'] )
			&& 'desktop' === sanitize_key( (string) $metrics['comparison_device'] )
			&& isset( $metrics['comparison_bounce_rate'] ) ) {
			$basis_label = __( 'Engagement gap vs desktop', 'opti-behavior' );
		}

		return array(
			'basis'            => $basis,
			'basis_label'      => $basis_label,
			'population_label' => $label['population'],
			'population'       => $population,
			'loss_rate'        => $loss_rate,
			'users_lost'       => $population * $loss_rate,
		);
	}

	/**
	 * Known basis identifiers.
	 *
	 * @return array
	 */
	private function get_basis_ids() {
		return array(
			self::BASIS_FUNNEL_DROPOFF,
			self::BASIS_FORM_ABANDONMENT,
			self::BASIS_ERROR_IMPACT,
			self::BASIS_CONVERSION_GAP,
			self::BASIS_ENGAGEMENT_GAP,
		);
	}

	/**
	 * Translated basis labels.
	 *
	 * @return array
	 */
	private function get_basis_labels() {
		return array(
			self::BASIS_FUNNEL_DROPOFF   => array(
				'basis'      => __( 'Funnel drop-off', 'opti-behavior' ),
				'population' => __( 'Funnel entrants', 'opti-behavior' ),
			),
			self::BASIS_FORM_ABANDONMENT => array(
				'basis'      => __( 'Form abandonment', 'opti-behavior' ),
				'population' => __( 'Form starts', 'opti-behavior' ),
			),
			self::BASIS_ERROR_IMPACT     => array(
				'basis'      => __( 'Error exposure', 'opti-behavior' ),
				'population' => __( 'Sessions', 'opti-behavior' ),
			),
			self::BASIS_CONVERSION_GAP   => array(
				'basis'      => __( 'Conversion gap', 'opti-behavior' ),
				'population' => __( 'Sessions', 'opti-behavior' ),
			),
			self::BASIS_ENGAGEMENT_GAP   => array(
				'basis'      => __( 'Engagement gap', 'opti-behavior' ),
				'population' => __( 'Sessions', 'opti-behavior' ),
			),
		);
	}

	/**
	 * Split the absolute loss across the ranked correlation causes.
	 *
	 * Shares are measured overlaps, not a partition, so they can exceed 100% in
	 * total. Each attribution therefore reports its own measured slice and never
	 * pretends the causes are mutually exclusive.
	 *
	 * @param int   $users_lost  Users lost.
	 * @param array $correlation Correlation payload.
	 * @return array
	 */
	private function attribute_causes( $users_lost, $correlation ) {
		$causes = isset( $correlation['causes'] ) && is_array( $correlation['causes'] ) ? $correlation['causes'] : array();
		if ( empty( $causes ) ) {
			return array();
		}

		$attributions = array();
		foreach ( $causes as $cause ) {
			if ( ! is_array( $cause ) || ! isset( $cause['share'] ) || ! is_numeric( $cause['share'] ) ) {
				continue;
			}

			$share = max( 0.0, min( 1.0, (float) $cause['share'] ) );
			if ( $share <= 0 ) {
				continue;
			}

			$attributions[] = array(
				'probe'      => isset( $cause['probe'] ) ? sanitize_key( $cause['probe'] ) : '',
				'label'      => isset( $cause['label'] ) ? (string) $cause['label'] : '',
				'share'      => round( $share, 4 ),
				'share_pct'  => round( $share * 100, 1 ),
				'users_lost' => (int) round( $users_lost * $share ),
				'rank'       => isset( $cause['rank'] ) ? (int) $cause['rank'] : count( $attributions ) + 1,
			);

			if ( count( $attributions ) >= self::MAX_CAUSE_ATTRIBUTIONS ) {
				break;
			}
		}

		return $attributions;
	}

	/**
	 * Calculate the WooCommerce revenue exposure behind a leak.
	 *
	 * Revenue is a Pro-tier disclosure: the value is computed here so the story
	 * payload stays complete, and the capability layer replaces it with a locked
	 * hint for Free viewers.
	 *
	 * @param int   $users_lost Users lost.
	 * @param array $basis      Basis record.
	 * @param array $insight    Insight payload.
	 * @param array $context    Calculation context.
	 * @return array
	 */
	private function calculate_revenue_exposure( $users_lost, $basis, $insight, $context ) {
		$unavailable = array(
			'available' => false,
			'amount'    => 0.0,
			'currency'  => '',
			'tier'      => 'pro',
			'reason'    => 'revenue_source_unavailable',
		);

		$date_range = isset( $context['date_range'] ) && is_array( $context['date_range'] ) ? $context['date_range'] : array();
		$revenue    = $this->get_revenue_context( $date_range );
		if ( empty( $revenue['available'] ) || $revenue['average_order_value'] <= 0 ) {
			return $unavailable;
		}

		$conversion_factor = $this->resolve_conversion_factor( $basis, $insight, $context );
		if ( null === $conversion_factor || $conversion_factor <= 0 ) {
			$unavailable['reason'] = 'conversion_factor_unavailable';

			return $unavailable;
		}

		// A value the site owner typed in themselves is not a Pro disclosure: it
		// is their own number handed back to them, so it stays readable on Free.
		$is_manual = self::REVENUE_SOURCE_MANUAL === $revenue['source'];

		return array(
			'available'           => true,
			'amount'              => round( $users_lost * $conversion_factor * $revenue['average_order_value'], 2 ),
			'currency'            => $revenue['currency'],
			'average_order_value' => round( (float) $revenue['average_order_value'], 2 ),
			'conversion_factor'   => round( (float) $conversion_factor, 4 ),
			'orders_sampled'      => (int) $revenue['orders_sampled'],
			'source'              => $revenue['source'],
			'tier'                => $is_manual ? 'free' : 'pro',
		);
	}

	/**
	 * Resolve how many of the lost visitors would plausibly have purchased.
	 *
	 * Purchase-intent bases (funnel drop-off, form abandonment, conversion gap)
	 * already describe visitors at the conversion step, so the factor is 1. Other
	 * bases are discounted by the site conversion rate; without one, the revenue
	 * block stays unavailable rather than guessing.
	 *
	 * @param array $basis   Basis record.
	 * @param array $insight Insight payload.
	 * @param array $context Calculation context.
	 * @return float|null
	 */
	private function resolve_conversion_factor( $basis, $insight, $context ) {
		if ( in_array( $basis['basis'], array( self::BASIS_FUNNEL_DROPOFF, self::BASIS_FORM_ABANDONMENT, self::BASIS_CONVERSION_GAP ), true ) ) {
			return 1.0;
		}

		$metrics         = $this->collect_metric_bag( $insight, $context );
		$conversion_rate = $this->percentage( $metrics, array( 'site_avg_conversion_rate', 'conversion_rate' ) );
		if ( null === $conversion_rate || $conversion_rate <= 0 ) {
			return null;
		}

		return min( 1.0, $conversion_rate / 100 );
	}

	/**
	 * Resolve the WooCommerce revenue context for a date range.
	 *
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function get_revenue_context( $date_range ) {
		$from = isset( $date_range['from'] ) ? (string) $date_range['from'] : '';
		$to   = isset( $date_range['to'] ) ? (string) $date_range['to'] : '';
		$key  = $from . '|' . $to;

		if ( isset( $this->revenue_context_cache[ $key ] ) ) {
			return $this->revenue_context_cache[ $key ];
		}

		$context = array(
			'available'           => false,
			'average_order_value' => 0.0,
			'currency'            => '',
			'orders_sampled'      => 0,
			'source'              => '',
		);

		/**
		 * Short-circuit the WooCommerce average-order-value lookup.
		 *
		 * Return an array with `average_order_value`, `currency`, and optionally
		 * `orders_sampled` / `source` to publish revenue exposure from another
		 * commerce plugin, or `false` to disable revenue entirely.
		 *
		 * @since 1.3.8
		 *
		 * @param array|false|null $pre        Pre-computed revenue context.
		 * @param array            $date_range Date range.
		 */
		$pre = apply_filters( 'opti_behavior_smart_insights_impact_revenue_context', null, $date_range );
		if ( false === $pre ) {
			$this->revenue_context_cache[ $key ] = $context;

			return $context;
		}

		if ( is_array( $pre ) && isset( $pre['average_order_value'] ) && is_numeric( $pre['average_order_value'] ) && (float) $pre['average_order_value'] > 0 ) {
			$context = array(
				'available'           => true,
				'average_order_value' => (float) $pre['average_order_value'],
				'currency'            => isset( $pre['currency'] ) ? sanitize_text_field( (string) $pre['currency'] ) : '',
				'orders_sampled'      => isset( $pre['orders_sampled'] ) ? absint( $pre['orders_sampled'] ) : 0,
				'source'              => isset( $pre['source'] ) ? sanitize_key( $pre['source'] ) : 'filter',
			);

			$this->revenue_context_cache[ $key ] = $context;

			return $context;
		}

		$woocommerce = $this->get_woocommerce_average_order_value( $from, $to );
		if ( ! empty( $woocommerce ) ) {
			$context = $woocommerce;
		} else {
			$manual = self::get_manual_conversion_value();
			if ( ! empty( $manual ) ) {
				$context = array(
					'available'           => true,
					'average_order_value' => (float) $manual['amount'],
					'currency'            => (string) $manual['currency'],
					'orders_sampled'      => 0,
					'source'              => self::REVENUE_SOURCE_MANUAL,
				);
			}
		}

		$this->revenue_context_cache[ $key ] = $context;

		return $context;
	}

	/**
	 * Read the manually configured value of one conversion.
	 *
	 * Sites without a readable order history can declare what one conversion is
	 * worth to them. The value is used exactly like a WooCommerce average order
	 * value: the existing conversion factor still decides how many of the lost
	 * visitors would plausibly have converted, so nothing is invented here.
	 *
	 * @since 1.3.9
	 *
	 * @return array|null `array( 'amount' => float, 'currency' => string )`, or null when unset.
	 */
	public static function get_manual_conversion_value() {
		$stored = get_option( self::CONVERSION_VALUE_OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['amount'] ) || ! is_numeric( $stored['amount'] ) ) {
			return null;
		}

		$amount = (float) $stored['amount'];
		if ( $amount <= 0 ) {
			return null;
		}

		return array(
			'amount'   => $amount,
			'currency' => self::sanitize_currency_code( isset( $stored['currency'] ) ? $stored['currency'] : '' ),
		);
	}

	/**
	 * Normalize a free-text currency code to a 3-letter ISO-4217 style code.
	 *
	 * @since 1.3.9
	 *
	 * @param string $currency Raw currency input.
	 * @return string Sanitized code, or an empty string when unusable.
	 */
	public static function sanitize_currency_code( $currency ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );

		return 3 === strlen( $code ) ? $code : '';
	}

	/**
	 * Read the WooCommerce average order value for a date range.
	 *
	 * @param string $from Range start (Y-m-d).
	 * @param string $to   Range end (Y-m-d).
	 * @return array|null
	 */
	private function get_woocommerce_average_order_value( $from, $to ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WooCommerce' ) ) {
			return null;
		}

		$query_args = array(
			'limit'    => 500,
			'status'   => array( 'wc-completed', 'wc-processing' ),
			'type'     => 'shop_order',
			'return'   => 'objects',
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => false,
		);

		if ( '' !== $from && '' !== $to ) {
			$query_args['date_created'] = $from . '...' . $to;
		}

		try {
			$orders = wc_get_orders( $query_args );
		} catch ( Exception $e ) {
			return null;
		}

		if ( empty( $orders ) || ! is_array( $orders ) ) {
			return null;
		}

		$total = 0.0;
		$count = 0;
		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_total' ) ) ) {
				continue;
			}

			$order_total = (float) $order->get_total();
			if ( $order_total <= 0 ) {
				continue;
			}

			$total += $order_total;
			++$count;
		}

		if ( $count < 1 ) {
			return null;
		}

		return array(
			'available'           => true,
			'average_order_value' => $total / $count,
			'currency'            => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'orders_sampled'      => $count,
			'source'              => 'woocommerce',
		);
	}

	/**
	 * Flatten the metric sources an insight can expose.
	 *
	 * @param array $insight Insight payload.
	 * @param array $context Calculation context.
	 * @return array
	 */
	private function collect_metric_bag( $insight, $context ) {
		$bag = array();

		foreach ( array( 'baselines', 'metrics' ) as $context_key ) {
			if ( isset( $context[ $context_key ] ) && is_array( $context[ $context_key ] ) ) {
				$bag = array_merge( $bag, $context[ $context_key ] );
			}
		}

		$insight_metrics = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
		$bag             = array_merge( $bag, $insight_metrics );

		foreach ( array( 'signal_metrics', 'baseline' ) as $nested_key ) {
			if ( isset( $insight_metrics[ $nested_key ] ) && is_array( $insight_metrics[ $nested_key ] ) ) {
				$bag = array_merge( $bag, $insight_metrics[ $nested_key ] );
			}
		}

		// A signal that diagnosed against a peer segment (mobile vs desktop)
		// publishes what it compared against. Pricing the story against the site
		// average instead would put two different baselines on the same card.
		$detection = isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array();
		foreach ( array( 'comparison_bounce_rate', 'comparison_scroll_depth', 'comparison_exit_rate', 'comparison_cta_click_rate' ) as $comparison_key ) {
			if ( isset( $detection[ $comparison_key ] ) && is_numeric( $detection[ $comparison_key ] ) ) {
				$bag[ $comparison_key ] = $detection[ $comparison_key ];
			}
		}
		if ( ! empty( $detection['comparison_device'] ) && is_string( $detection['comparison_device'] ) ) {
			$bag['comparison_device'] = sanitize_key( $detection['comparison_device'] );
		}

		return $bag;
	}

	/**
	 * First positive integer found under any of the given keys.
	 *
	 * @param array $bag  Metric bag.
	 * @param array $keys Candidate keys.
	 * @return int
	 */
	private function positive_int( $bag, $keys ) {
		foreach ( (array) $keys as $key ) {
			if ( isset( $bag[ $key ] ) && is_numeric( $bag[ $key ] ) && (int) $bag[ $key ] > 0 ) {
				return (int) $bag[ $key ];
			}
		}

		return 0;
	}

	/**
	 * First usable percentage found under any of the given keys.
	 *
	 * @param array $bag  Metric bag.
	 * @param array $keys Candidate keys.
	 * @return float|null
	 */
	private function percentage( $bag, $keys ) {
		foreach ( (array) $keys as $key ) {
			if ( isset( $bag[ $key ] ) && null !== $bag[ $key ] && is_numeric( $bag[ $key ] ) ) {
				return (float) $bag[ $key ];
			}
		}

		return null;
	}

	/**
	 * Positive difference between two percentages, or null.
	 *
	 * @param float|null $left  Left value.
	 * @param float|null $right Right value.
	 * @return float|null
	 */
	private function positive_gap( $left, $right ) {
		if ( null === $left || null === $right ) {
			return null;
		}

		$gap = (float) $left - (float) $right;

		return $gap > 0 ? $gap : null;
	}

	/**
	 * Current site time, tolerating CLI bootstraps.
	 *
	 * @return string
	 */
	private function now() {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
