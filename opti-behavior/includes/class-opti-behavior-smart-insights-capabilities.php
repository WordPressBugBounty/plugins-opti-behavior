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

		return apply_filters( 'opti_behavior_smart_insights_shape_payload', $insight, $context, $this->has_pro_access );
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
