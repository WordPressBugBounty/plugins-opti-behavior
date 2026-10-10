<?php
/**
 * Smart Insights Recommendations Class
 *
 * Holds deterministic recommendation templates for Smart Insights.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Recommendations Class
 *
 * The library intentionally returns structured arrays instead of pre-rendered
 * HTML so dashboard cards, details panels, AJAX responses, and future Pro
 * extensions can reuse the same safe payload.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Recommendations {

	/**
	 * Short form of a next action, for the card and the weekly summary (the
	 * modal keeps the full sentence): the part before the first ": " (the
	 * numbers after it are already on the card), else the first sentence,
	 * without a parenthesised figure, at most 90 characters. Pure.
	 *
	 * @param string $action Full action.
	 * @return string
	 */
	public static function short_action( $action ) {
		$action = trim( preg_replace( '/\s+/u', ' ', (string) $action ) );
		if ( '' === $action ) {
			return '';
		}
		$colon = mb_strpos( $action, ': ' );
		if ( false !== $colon && $colon >= 12 ) {
			$action = mb_substr( $action, 0, $colon );
		} elseif ( preg_match( '/^(.{12,}?[.!?])\s/u', $action, $m ) ) {
			$action = $m[1];
		}
		$action = trim( preg_replace( '/\s*\([^)]*\d[^)]*\)/u', '', $action ) );
		$action = rtrim( $action, ' .,;' );
		if ( mb_strlen( $action ) > 90 ) {
			$action = rtrim( mb_substr( $action, 0, 89 ) ) . '…';
		}

		return $action;
	}

	/**
	 * A next action up to this length is shown whole on the card (OPT-362: the
	 * part after ": " names the step or the entity, cutting it lost both).
	 */
	const SHORT_ACTION_KEEP_WHOLE = 140;

	/**
	 * Card families shown as ONE card per entity and period (OPT-378 A):
	 * family => signal ids. The signal with the most visits lost carries the
	 * card, the others become "Also seen here" lines of its detail. Pro
	 * signal ids are plain strings here (no Pro code in Free).
	 */
	const MERGE_FAMILIES = array(
		'bounce'   => array( 'basic_bounce_alert', 'basic_mobile_bounce_warning' ),
		'scroll'   => array( 'low_scroll_depth_important_page', 'low_scroll_depth_page', 'high_traffic_low_engagement' ),
		'checkout' => array( 'checkout_to_purchase_dropoff', 'checkout_friction_detected' ),
		'form'     => array( 'form_abandonment_detected', 'field_level_friction', 'form_error_friction' ),
	);

	/**
	 * Merge family of a signal ('' = the card stays on its own). Pure.
	 * Filter `opti_behavior_smart_insights_merge_families` (family => ids).
	 *
	 * @param string $signal_id Signal id.
	 * @return string
	 */
	public static function merge_family( $signal_id ) {
		static $map = null;
		if ( null === $map ) {
			$families = function_exists( 'apply_filters' ) ? apply_filters( 'opti_behavior_smart_insights_merge_families', self::MERGE_FAMILIES ) : self::MERGE_FAMILIES;
			$map      = array();
			foreach ( is_array( $families ) ? $families : array() as $family => $ids ) {
				foreach ( is_array( $ids ) ? $ids : array() as $id ) {
					$map[ sanitize_key( (string) $id ) ] = sanitize_key( (string) $family );
				}
			}
		}
		$signal_id = sanitize_key( (string) $signal_id );

		return isset( $map[ $signal_id ] ) ? $map[ $signal_id ] : '';
	}

	/**
	 * Signals that are actions, not problems (OPT-378 B): never a card of
	 * their own. They ride on the card of the same page as its proof button
	 * (recordings) or its "Start an A/B test" action (repository
	 * attach_action_signals()); without such a card they are not shown.
	 */
	const ACTION_SIGNALS = array(
		'session_recording_opportunity' => 'recording',
		'ab_test_opportunity'           => 'ab_test',
	);

	/**
	 * Action kind of a signal: 'recording', 'ab_test' or '' (a problem). Pure.
	 *
	 * @param string $signal_id Signal id.
	 * @return string
	 */
	public static function action_signal_kind( $signal_id ) {
		$signal_id = sanitize_key( (string) $signal_id );

		return isset( self::ACTION_SIGNALS[ $signal_id ] ) ? self::ACTION_SIGNALS[ $signal_id ] : '';
	}

	/**
	 * Page title without the " – Site name" suffix themes add (OPT-378 D1):
	 * "Contact – My Site" → "Contact". Separators: - – — | · :. The label is
	 * kept whole when nothing would be left. Pure apart from the site name.
	 *
	 * @param string      $label     Page title.
	 * @param string|null $site_name Site name (null = the blog name).
	 * @return string
	 */
	public static function strip_site_suffix( $label, $site_name = null ) {
		$label = trim( html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' ) );
		if ( null === $site_name ) {
			$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		}
		$site_name = trim( html_entity_decode( (string) $site_name, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $label || '' === $site_name ) {
			return $label;
		}

		$stripped = trim( (string) preg_replace( '/\s*[\-\x{2013}\x{2014}|\x{00B7}:]\s*' . preg_quote( $site_name, '/' ) . '\s*$/iu', '', $label ) );

		return '' !== $stripped ? $stripped : $label;
	}

	/**
	 * The one chart of a card (OPT-378 C), from the stored figures only:
	 * G2 = previous period vs this period, G3 = this group vs the others.
	 * Empty array = no chart (a figure is missing: never an invented value).
	 * `summary` is the card's key comparison, so the card, the chart and the
	 * detail print the same numbers. Locked previews get none.
	 *
	 * @param array $insight Insight payload.
	 * @return array { type, unit, metric, bars: [ { label, value, text, focus } x2 ], summary }
	 */
	public static function chart_data( $insight ) {
		if ( ! is_array( $insight ) || ! empty( $insight['is_locked_preview'] ) ) {
			return array();
		}

		$signal    = isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '';
		$metrics   = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
		$detection = isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array();
		$reason    = isset( $detection['reason'] ) ? (string) $detection['reason'] : '';
		$before    = __( 'Previous period', 'opti-behavior' );
		$now       = __( 'This period', 'opti-behavior' );
		$bounce    = __( 'Bounce rate', 'opti-behavior' );
		$mobile    = __( 'Mobile', 'opti-behavior' );

		switch ( $signal ) {
			case 'traffic_spike_observation':
				return self::chart_pair( 'before_after', 'count', __( 'Visits', 'opti-behavior' ), array( $before, $detection['previous_sessions'] ?? null ), array( $now, $metrics['sessions'] ?? null ) );
			case 'traffic_spike_without_conversion':
				$rate = self::chart_trend( $metrics, 'conversion_rate' );
				return self::chart_pair( 'before_after', 'percent', __( 'Conversion rate', 'opti-behavior' ), array( $before, $rate['previous'] ), array( $now, $rate['current'] ) );
			case 'conversion_drop_alert':
				return self::chart_pair( 'before_after', 'percent', __( 'Conversion rate', 'opti-behavior' ), array( $before, $detection['previous_conversion_rate'] ?? null ), array( $now, $detection['current_conversion_rate'] ?? null ) );
			case 'engagement_decay':
				$rate = self::chart_trend( $metrics, 'bounce_rate' );
				return self::chart_pair( 'before_after', 'percent', $bounce, array( $before, $rate['previous'] ), array( $now, $rate['current'] ) );
			case 'basic_mobile_bounce_warning':
			case 'mobile_friction_detected':
				// The conversion-gap rule of mobile friction stores no desktop rate.
				if ( 'mobile_conversion_gap' === $reason ) {
					return array();
				}
				$other = isset( $detection['comparison_device'] ) && 'desktop' === $detection['comparison_device'] ? __( 'Desktop', 'opti-behavior' ) : __( 'Site average', 'opti-behavior' );
				return self::chart_pair( 'compare', 'percent', $bounce, array( $mobile, $metrics['bounce_rate'] ?? null ), array( $other, $detection['comparison_bounce_rate'] ?? null ) );
			case 'mobile_cta_click_rate_lower_than_desktop':
				return self::chart_pair( 'compare', 'percent', __( 'Button click rate', 'opti-behavior' ), array( $mobile, $detection['mobile_cta_click_rate'] ?? null ), array( __( 'Desktop', 'opti-behavior' ), $detection['desktop_cta_click_rate'] ?? null ) );
			case 'low_quality_traffic_source':
				$rate = isset( $metrics['bounce_rate'] ) && is_numeric( $metrics['bounce_rate'] ) ? (float) $metrics['bounce_rate'] : null;
				$gap  = isset( $detection['bounce_gap'] ) && is_numeric( $detection['bounce_gap'] ) ? (float) $detection['bounce_gap'] : null;
				$site = null !== $rate && null !== $gap ? $rate - $gap : null;
				return self::chart_pair( 'compare', 'percent', $bounce, array( __( 'This source', 'opti-behavior' ), $rate ), array( __( 'Site average', 'opti-behavior' ), $site ) );
			case 'segment_anomaly_detection':
			case 'returning_visitor_opportunity':
				// OPT-516 D7: returning visitors = one segment too (stored segment_* /
				// overall_* rates), so the card gets the same "this segment vs all" chart.
				if ( 'segment_bounce_anomaly' === $reason || 'returning_bounce_above_new' === $reason ) {
					$keys = array( $bounce, 'segment_bounce_rate', 'overall_bounce_rate' );
				} elseif ( 'segment_scroll_anomaly' === $reason ) {
					$keys = array( __( 'Scroll depth', 'opti-behavior' ), 'segment_avg_scroll_depth', 'overall_avg_scroll_depth' );
				} else {
					$keys = array( __( 'Conversion rate', 'opti-behavior' ), 'segment_conversion_rate', 'overall_conversion_rate' );
				}
				return self::chart_pair( 'compare', 'percent', $keys[0], array( __( 'This segment', 'opti-behavior' ), $metrics[ $keys[1] ] ?? null ), array( __( 'All visitors', 'opti-behavior' ), $metrics[ $keys[2] ] ?? null ) );
		}

		return array();
	}

	/**
	 * Current / previous value of a metric (same lookup as the Pro signals:
	 * metrics.trend.<key>, else metrics.<key> + previous_period_metrics.<key>).
	 *
	 * @param array  $metrics Metrics.
	 * @param string $key     Metric key.
	 * @return array { current, previous } floats or null.
	 */
	private static function chart_trend( $metrics, $key ) {
		$num = function( $value ) {
			return is_numeric( $value ) ? (float) $value : null;
		};
		if ( isset( $metrics['trend'][ $key ] ) && is_array( $metrics['trend'][ $key ] ) ) {
			return array(
				'current'  => $num( $metrics['trend'][ $key ]['current'] ?? null ),
				'previous' => $num( $metrics['trend'][ $key ]['previous'] ?? null ),
			);
		}

		return array(
			'current'  => $num( $metrics[ $key ] ?? null ),
			'previous' => $num( $metrics['previous_period_metrics'][ $key ] ?? null ),
		);
	}

	/**
	 * Two-bar chart payload, or array() when a value is missing, negative,
	 * both are 0, or a percentage is above 100.
	 *
	 * @param string $type   before_after | compare.
	 * @param string $unit   percent | count.
	 * @param string $metric Metric label.
	 * @param array  $first  { label, value }.
	 * @param array  $second { label, value }.
	 * @return array
	 */
	private static function chart_pair( $type, $unit, $metric, $first, $second ) {
		$bars = array();
		foreach ( array( $first, $second ) as $pair ) {
			$value = isset( $pair[1] ) && is_numeric( $pair[1] ) ? (float) $pair[1] : null;
			if ( null === $value || $value < 0 || ( 'percent' === $unit && $value > 100 ) ) {
				return array();
			}
			$value  = 'count' === $unit ? (float) round( $value ) : round( $value, 1 );
			$bars[] = array(
				'label' => (string) $pair[0],
				'value' => $value,
				'text'  => self::chart_value_text( $value, $unit ),
				'focus' => false,
			);
		}
		if ( $bars[0]['value'] <= 0 && $bars[1]['value'] <= 0 ) {
			return array();
		}
		// The bar the card is about: this period, or this group.
		$bars[ 'before_after' === $type ? 1 : 0 ]['focus'] = true;

		return array(
			'type'    => $type,
			'unit'    => $unit,
			'metric'  => (string) $metric,
			'bars'    => $bars,
			/* translators: 1: metric, e.g. Bounce rate, 2: first group, 3: its value, 4: second group, 5: its value. */
			'summary' => sprintf( __( '%1$s: %2$s %3$s · %4$s %5$s', 'opti-behavior' ), $metric, $bars[0]['label'], $bars[0]['text'], $bars[1]['label'], $bars[1]['text'] ),
		);
	}

	/**
	 * Chart value in the site language: "1,234" or "57%" / "57.5%".
	 *
	 * @param float  $value Value.
	 * @param string $unit  percent | count.
	 * @return string
	 */
	private static function chart_value_text( $value, $unit ) {
		$format = function( $number, $decimals ) {
			return function_exists( 'number_format_i18n' ) ? number_format_i18n( $number, $decimals ) : number_format( $number, $decimals );
		};
		if ( 'count' === $unit ) {
			return $format( $value, 0 );
		}

		return $format( $value, floor( $value ) === $value ? 0 : 1 ) . '%';
	}

	/**
	 * The card form of a next action: the whole sentence up to
	 * SHORT_ACTION_KEEP_WHOLE characters, else short_action(). Pure.
	 *
	 * @param string $action Full action.
	 * @return string
	 */
	public static function card_action( $action ) {
		$whole = trim( preg_replace( '/\s+/u', ' ', (string) $action ) );

		return mb_strlen( $whole ) <= self::SHORT_ACTION_KEEP_WHOLE ? $whole : self::short_action( $whole );
	}

	/**
	 * Precise card title + the one complete action of a Free card (OPT-362
	 * lot 2), computed when the payload is shaped, so stored rows get them
	 * too. Pure: reads the payload only. Pro adds its families through the
	 * `opti_behavior_smart_insights_card_copy` filter (capabilities).
	 *
	 * @param array $insight Insight payload.
	 * @return array { card_title, action_short } — '' when the family is not a Free one.
	 */
	public static function card_copy( $insight ) {
		$copy = array(
			'card_title'   => '',
			'action_short' => '',
		);
		if ( ! is_array( $insight ) ) {
			return $copy;
		}

		$signal    = isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '';
		$metrics   = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
		$detection = isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array();
		$page      = self::copy_page_label( $insight );

		switch ( $signal ) {
			case 'basic_bounce_alert':
				/* translators: %s: page title. */
				$copy['card_title'] = sprintf( __( 'Visitors leave %s without a second click', 'opti-behavior' ), $page );
				/* translators: %s: page title. */
				$copy['action_short'] = sprintf( __( 'Open the heatmap of %s and check the first screen: offer, next step, nothing covering it.', 'opti-behavior' ), $page );
				break;
			case 'basic_mobile_bounce_warning':
				if ( self::copy_is_page( $insight ) ) {
					/* translators: %s: page title. */
					$copy['card_title'] = sprintf( __( 'Mobile visitors leave %s right away', 'opti-behavior' ), $page );
					/* translators: %s: page title. */
					$copy['action_short'] = sprintf( __( 'Open the mobile heatmap of %s and check the first screen: offer, next step, nothing covering it.', 'opti-behavior' ), $page );
				} else {
					$copy['card_title']   = __( 'Mobile visitors leave your site right away', 'opti-behavior' );
					$copy['action_short'] = __( 'Open the mobile heatmap of your busiest page and check the first screen: offer, next step, nothing covering it.', 'opti-behavior' );
				}
				break;
			case 'high_exit_rate_page':
				/* translators: %s: page title. */
				$copy['card_title'] = sprintf( __( 'Visits often end on %s', 'opti-behavior' ), $page );
				/* translators: %s: page title. */
				$copy['action_short'] = sprintf( __( 'Add a clear link to the next step on %s where visitors stop reading.', 'opti-behavior' ), $page );
				break;
			case 'high_traffic_low_engagement':
			case 'low_scroll_depth_important_page':
				/* translators: %s: page title. */
				$copy['card_title'] = sprintf( __( 'Most visitors never reach the middle of %s', 'opti-behavior' ), $page );
				$scroll             = isset( $metrics['avg_scroll_depth'] ) && is_numeric( $metrics['avg_scroll_depth'] ) && $metrics['avg_scroll_depth'] > 0 ? self::copy_percent( $metrics['avg_scroll_depth'] ) : '';
				$copy['action_short'] = '' !== $scroll
					/* translators: 1: page title, 2: average scroll depth, e.g. 34%. */
					? sprintf( __( 'Move the main button or key message of %1$s above %2$s of the page, where most visitors stop.', 'opti-behavior' ), $page, $scroll )
					/* translators: %s: page title. */
					: sprintf( __( 'Move the main button or key message of %s higher, where most visitors still read.', 'opti-behavior' ), $page );
				break;
			case 'traffic_spike_observation':
				$delta = isset( $detection['sessions_relative_delta_pct'] ) && is_numeric( $detection['sessions_relative_delta_pct'] ) && $detection['sessions_relative_delta_pct'] > 0 ? self::copy_percent( $detection['sessions_relative_delta_pct'] ) : '';
				if ( self::copy_is_page( $insight ) ) {
					$copy['card_title'] = '' !== $delta
						/* translators: 1: page title, 2: visit increase, e.g. 85%. */
						? sprintf( __( 'Traffic spike on %1$s: +%2$s visits', 'opti-behavior' ), $page, $delta )
						/* translators: %s: page title. */
						: sprintf( __( 'Traffic spike on %s', 'opti-behavior' ), $page );
					/* translators: %s: page title. */
					$copy['action_short'] = sprintf( __( 'Check where the extra visits to %s come from before changing anything.', 'opti-behavior' ), $page );
				} else {
					$copy['card_title'] = '' !== $delta
						/* translators: %s: visit increase, e.g. 85%. */
						? sprintf( __( 'Traffic spike on your site: +%s visits', 'opti-behavior' ), $delta )
						: __( 'Traffic spike on your site', 'opti-behavior' );
					$copy['action_short'] = __( 'Check where the extra visits to your site come from before changing anything.', 'opti-behavior' );
				}
				break;
		}

		// OPT-378 D3: the card action is one complete sentence of at most
		// SHORT_ACTION_KEEP_WHOLE characters; a long page title gives way to
		// "this page" rather than a cut sentence.
		if ( mb_strlen( $copy['action_short'] ) > self::SHORT_ACTION_KEEP_WHOLE && '' !== trim( (string) ( $insight['entity_label'] ?? '' ) ) ) {
			$generic              = self::card_copy( array_merge( $insight, array( 'entity_label' => '' ) ) );
			$copy['action_short'] = $generic['action_short'];
		}

		return $copy;
	}

	/**
	 * Whether the card is about one page.
	 *
	 * @param array $insight Insight payload.
	 * @return bool
	 */
	private static function copy_is_page( $insight ) {
		return isset( $insight['entity_type'] ) && 'page' === $insight['entity_type'];
	}

	/**
	 * Page label for card copy: the stored entity label (the page title,
	 * without the " – Site name" suffix), else "this page".
	 *
	 * @param array $insight Insight payload.
	 * @return string
	 */
	private static function copy_page_label( $insight ) {
		$label = isset( $insight['entity_label'] ) ? self::strip_site_suffix( wp_strip_all_tags( (string) $insight['entity_label'] ) ) : '';

		return '' !== $label ? $label : __( 'this page', 'opti-behavior' );
	}

	/**
	 * Whole percent in the site language, e.g. "34%".
	 *
	 * @param float $value Percent value.
	 * @return string
	 */
	private static function copy_percent( $value ) {
		return number_format_i18n( round( (float) $value ) ) . '%';
	}

	const TEMPLATE_PAGE_LOW_ENGAGEMENT = 'page_low_engagement_v1';
	const TEMPLATE_PAGE_HIGH_EXIT_RATE = 'page_high_exit_rate_v1';
	const TEMPLATE_PAGE_LOW_SCROLL_DEPTH = 'page_low_scroll_depth_v1';
	const TEMPLATE_PAGE_BASIC_BOUNCE_ALERT = 'page_basic_bounce_alert_v1';
	const TEMPLATE_PAGE_TRAFFIC_SPIKE = 'page_traffic_spike_observation_v1';
	const TEMPLATE_DEVICE_BASIC_MOBILE_BOUNCE = 'device_basic_mobile_bounce_warning_v1';

	/**
	 * Get a recommendation payload by template ID.
	 *
	 * @param string $template_id Template identifier.
	 * @param array  $context     Metrics, baselines, and detection context.
	 * @return array
	 */
	public function get_template( $template_id, $context = array() ) {
		switch ( $template_id ) {
			case self::TEMPLATE_PAGE_LOW_ENGAGEMENT:
				return $this->build_page_low_engagement_template( $context );
			case self::TEMPLATE_PAGE_HIGH_EXIT_RATE:
				return $this->build_page_high_exit_rate_template( $context );
			case self::TEMPLATE_PAGE_LOW_SCROLL_DEPTH:
				return $this->build_page_low_scroll_depth_template( $context );
			case self::TEMPLATE_PAGE_BASIC_BOUNCE_ALERT:
				return $this->build_page_basic_bounce_template( $context );
			case self::TEMPLATE_PAGE_TRAFFIC_SPIKE:
				return $this->build_page_traffic_spike_template( $context );
			case self::TEMPLATE_DEVICE_BASIC_MOBILE_BOUNCE:
				return $this->build_device_basic_mobile_bounce_template( $context );
		}

		return array(
			'interpretation'       => '',
			'why_it_matters'       => '',
			'likely_causes'        => array(),
			'recommended_actions'  => array(),
			'related_reports'      => array(),
			'template_id'          => sanitize_key( $template_id ),
			'template_version'     => '1.0.0',
		);
	}

	/**
	 * Normalize stored recommended action arrays to the current Free signal copy.
	 *
	 * Smart Insights rows persist their recommendation payloads at generation
	 * time. When the product copy changes, existing rows can otherwise keep
	 * showing stale "Next action" text until the issue is regenerated. This
	 * helper keeps default Free signal payloads current while preserving the
	 * secondary checklist items already stored with the insight.
	 *
	 * @since 1.3.7
	 *
	 * @param string $signal_id           Smart Insights signal ID.
	 * @param array  $recommended_actions Stored recommended actions.
	 * @return array
	 */
	public function normalize_recommended_actions_for_signal( $signal_id, $recommended_actions ) {
		$actions = is_array( $recommended_actions ) ? array_values( $recommended_actions ) : array();
		$actions = array_values(
			array_filter(
				array_map(
					function ( $action ) {
						return is_scalar( $action ) ? (string) $action : '';
					},
					$actions
				),
				function ( $action ) {
					return '' !== trim( $action );
				}
			)
		);
		$primary = $this->get_primary_action_copy_for_signal( $signal_id );

		if ( '' === $primary ) {
			return $actions;
		}

		if ( empty( $actions ) ) {
			return array( $primary );
		}

		$actions[0] = $primary;

		return array_values( array_unique( $actions ) );
	}

	/**
	 * Get the current primary card action copy for a Free signal.
	 *
	 * The first action is the compact "Next action" shown on the card, so this
	 * method is intentionally limited to supported Free signal IDs.
	 *
	 * @since 1.3.7
	 *
	 * @param string $signal_id Smart Insights signal ID.
	 * @return string
	 */
	private function get_primary_action_copy_for_signal( $signal_id ) {
		switch ( sanitize_key( $signal_id ) ) {
			case 'high_traffic_low_engagement':
				return __( 'Rewrite the hero headline to state the visitor\'s main benefit, then keep the primary CTA visible above the fold.', 'opti-behavior' );
			case 'high_exit_rate_page':
				return __( 'Add one clear end-of-page CTA or related link so visitors know where to go next.', 'opti-behavior' );
			case 'low_scroll_depth_important_page':
				return __( 'Tighten the first screen: state the benefit, reduce clutter, and move one proof point above the fold.', 'opti-behavior' );
			case 'basic_bounce_alert':
				return __( 'Match the headline to the visitor\'s intent and show a specific CTA in the first screen.', 'opti-behavior' );
			case 'traffic_spike_observation':
				return __( 'Find the source of the spike, then check bounce and scroll before changing the page.', 'opti-behavior' );
			case 'basic_mobile_bounce_warning':
				return __( 'On mobile, shorten the first screen and make the primary CTA visible without scrolling.', 'opti-behavior' );
		}

		return '';
	}

	/**
	 * Build the page low-engagement recommendation template.
	 *
	 * @param array $context Context array.
	 * @return array
	 */
	public function build_page_low_engagement_template( $context = array() ) {
		$metrics   = isset( $context['metrics'] ) && is_array( $context['metrics'] ) ? $context['metrics'] : array();
		$baselines = isset( $context['baselines'] ) && is_array( $context['baselines'] ) ? $context['baselines'] : array();
		$detection = isset( $context['detection'] ) && is_array( $context['detection'] ) ? $context['detection'] : array();

		$page_label        = $this->get_page_label( $metrics );
		$sessions          = isset( $metrics['sessions'] ) ? (int) $metrics['sessions'] : 0;
		$page_bounce       = $this->format_percent( $metrics['bounce_rate'] ?? null );
		$site_bounce       = $this->format_percent( $baselines['site_avg_bounce_rate'] ?? null );
		$page_scroll       = $this->format_percent( $metrics['avg_scroll_depth'] ?? null );
		$site_scroll       = $this->format_percent( $baselines['site_avg_scroll_depth'] ?? null );
		$page_time         = $this->format_seconds( $metrics['avg_time_on_page'] ?? null );
		$site_time         = $this->format_seconds( $baselines['site_avg_time_on_page'] ?? null );
		$page_exit         = $this->format_percent( $metrics['exit_rate'] ?? null );
		$site_exit         = $this->format_percent( $baselines['site_avg_exit_rate'] ?? null );
		$fallback_used     = ! empty( $detection['fallback_used'] ) ? (string) $detection['fallback_used'] : '';
		$has_scroll_output = null !== ( $metrics['avg_scroll_depth'] ?? null ) && null !== ( $baselines['site_avg_scroll_depth'] ?? null );

		if ( $has_scroll_output ) {
			$interpretation = sprintf(
				/* translators: 1: Page label, 2: Sessions, 3: Page bounce rate, 4: Site bounce rate, 5: Page scroll depth, 6: Site scroll depth. */
				__( '%1$s received %2$d sessions during the selected period, but engagement is weak. Its bounce rate is %3$s compared with the site average of %4$s, and average scroll depth is %5$s compared with %6$s site average.', 'opti-behavior' ),
				$page_label,
				$sessions,
				$page_bounce,
				$site_bounce,
				$page_scroll,
				$site_scroll
			);
		} elseif ( 'time_on_page' === $fallback_used ) {
			$interpretation = sprintf(
				/* translators: 1: Page label, 2: Sessions, 3: Page bounce rate, 4: Site bounce rate, 5: Page time, 6: Site time. */
				__( '%1$s received %2$d sessions during the selected period, but engagement is weak. Its bounce rate is %3$s compared with the site average of %4$s, and average time on page is %5$s compared with %6$s site average.', 'opti-behavior' ),
				$page_label,
				$sessions,
				$page_bounce,
				$site_bounce,
				$page_time,
				$site_time
			);
		} elseif ( 'exit_rate' === $fallback_used ) {
			$interpretation = sprintf(
				/* translators: 1: Page label, 2: Sessions, 3: Page bounce rate, 4: Site bounce rate, 5: Page exit rate, 6: Site exit rate. */
				__( '%1$s received %2$d sessions during the selected period, but engagement is weak. Its bounce rate is %3$s compared with the site average of %4$s, and exit rate is %5$s compared with %6$s site average.', 'opti-behavior' ),
				$page_label,
				$sessions,
				$page_bounce,
				$site_bounce,
				$page_exit,
				$site_exit
			);
		} else {
			$interpretation = sprintf(
				/* translators: 1: Page label, 2: Sessions, 3: Page bounce rate, 4: Page scroll depth. */
				__( '%1$s received %2$d sessions during the selected period, but visitors are not engaging deeply. Bounce rate is %3$s and average scroll depth is %4$s.', 'opti-behavior' ),
				$page_label,
				$sessions,
				$page_bounce,
				$page_scroll
			);
		}

		$template = array(
			'template_id'         => self::TEMPLATE_PAGE_LOW_ENGAGEMENT,
			'template_version'    => '1.0.0',
			'interpretation'      => $interpretation,
			'why_it_matters'      => __( 'This page attracts meaningful traffic, so weak engagement can waste acquisition effort and reduce conversion opportunities before visitors see the offer or next step.', 'opti-behavior' ),
			'likely_causes'       => $this->get_page_low_engagement_causes( $metrics, $detection ),
			'recommended_actions' => $this->get_page_low_engagement_actions( $metrics, $detection ),
			'related_reports'     => $this->get_page_low_engagement_reports( $metrics ),
			'upgrade_preview'     => array(
				'title'       => __( 'Pro diagnosis available', 'opti-behavior' ),
				'description' => __( 'Unlock device, source, journey, and recording context to see which segment is causing the engagement gap.', 'opti-behavior' ),
			),
		);

		/**
		 * Filter the deterministic low-engagement template.
		 *
		 * Pro can append gated reports or segmentation-specific language later
		 * without replacing the Free signal implementation.
		 *
		 * @param array $template Template payload.
		 * @param array $context  Metrics, baselines, and detection context.
		 */
		return apply_filters( 'opti_behavior_smart_insights_recommendation_template', $template, $context );
	}

	/**
	 * Build the high exit-rate recommendation template.
	 *
	 * @param array $context Context array.
	 * @return array
	 */
	public function build_page_high_exit_rate_template( $context = array() ) {
		$metrics   = isset( $context['metrics'] ) && is_array( $context['metrics'] ) ? $context['metrics'] : array();
		$baselines = isset( $context['baselines'] ) && is_array( $context['baselines'] ) ? $context['baselines'] : array();

		return $this->build_generic_template(
			self::TEMPLATE_PAGE_HIGH_EXIT_RATE,
			sprintf(
				/* translators: 1: Page label, 2: Pageviews, 3: Page exit rate, 4: Site exit rate. */
				__( '%1$s had %2$d pageviews, and visitors exited from this page at %3$s compared with the site average of %4$s.', 'opti-behavior' ),
				$this->get_page_label( $metrics ),
				isset( $metrics['pageviews'] ) ? (int) $metrics['pageviews'] : 0,
				$this->format_percent( $metrics['exit_rate'] ?? null ),
				$this->format_percent( $baselines['site_avg_exit_rate'] ?? null )
			),
			__( 'A high exit rate can indicate that the page is a dead end, lacks a clear next step, or does not connect well to the visitor journey.', 'opti-behavior' ),
			array(
				__( 'The page may not offer a clear next step after visitors consume the content.', 'opti-behavior' ),
				__( 'Internal links, related content, or contextual calls to action may be missing.', 'opti-behavior' ),
				__( 'Visitors may be reaching the page with expectations that the page does not satisfy.', 'opti-behavior' ),
			),
			array(
				$this->get_primary_action_copy_for_signal( 'high_exit_rate_page' ),
				__( 'Add contextual internal links to related content, products, or service pages.', 'opti-behavior' ),
				__( 'Place a relevant call to action after the main content.', 'opti-behavior' ),
				__( 'Review whether the page purpose matches the visitor journey.', 'opti-behavior' ),
			),
			$this->get_page_basic_reports( $metrics ),
			array(
				'title'       => __( 'Pro journey diagnosis available', 'opti-behavior' ),
				'description' => __( 'Unlock session recordings and journey paths to see where visitors go before they exit.', 'opti-behavior' ),
			)
		);
	}

	/**
	 * Build the low scroll-depth recommendation template.
	 *
	 * @param array $context Context array.
	 * @return array
	 */
	public function build_page_low_scroll_depth_template( $context = array() ) {
		$metrics   = isset( $context['metrics'] ) && is_array( $context['metrics'] ) ? $context['metrics'] : array();
		$baselines = isset( $context['baselines'] ) && is_array( $context['baselines'] ) ? $context['baselines'] : array();

		return $this->build_generic_template(
			self::TEMPLATE_PAGE_LOW_SCROLL_DEPTH,
			sprintf(
				/* translators: 1: Page label, 2: Sessions, 3: Page scroll, 4: Site scroll. */
				__( '%1$s received %2$d sessions, but average scroll depth is only %3$s compared with the site average of %4$s.', 'opti-behavior' ),
				$this->get_page_label( $metrics ),
				isset( $metrics['sessions'] ) ? (int) $metrics['sessions'] : 0,
				$this->format_percent( $metrics['avg_scroll_depth'] ?? null ),
				$this->format_percent( $baselines['site_avg_scroll_depth'] ?? null )
			),
			__( 'Low scroll depth suggests visitors are not finding enough reason to continue beyond the first screen.', 'opti-behavior' ),
			array(
				__( 'The hero section may not communicate a strong reason to continue.', 'opti-behavior' ),
				__( 'High-value content may be too low on the page.', 'opti-behavior' ),
				__( 'Visual clutter, slow loading, or weak content hierarchy may stop exploration.', 'opti-behavior' ),
			),
			array(
				$this->get_primary_action_copy_for_signal( 'low_scroll_depth_important_page' ),
				__( 'Move high-value content, proof points, and key benefits above the fold.', 'opti-behavior' ),
				__( 'Reduce first-screen clutter and improve content hierarchy.', 'opti-behavior' ),
				__( 'Review the heatmap to confirm where visitors stop scrolling.', 'opti-behavior' ),
			),
			$this->get_page_basic_reports( $metrics ),
			array(
				'title'       => __( 'Pro scroll diagnosis available', 'opti-behavior' ),
				'description' => __( 'Unlock device and source breakdowns to identify whether the scroll issue is concentrated in one segment.', 'opti-behavior' ),
			)
		);
	}

	/**
	 * Build the basic bounce-alert recommendation template.
	 *
	 * @param array $context Context array.
	 * @return array
	 */
	public function build_page_basic_bounce_template( $context = array() ) {
		$metrics   = isset( $context['metrics'] ) && is_array( $context['metrics'] ) ? $context['metrics'] : array();
		$baselines = isset( $context['baselines'] ) && is_array( $context['baselines'] ) ? $context['baselines'] : array();

		return $this->build_generic_template(
			self::TEMPLATE_PAGE_BASIC_BOUNCE_ALERT,
			sprintf(
				/* translators: 1: Page label, 2: Sessions, 3: Page bounce, 4: Site bounce. */
				__( '%1$s received %2$d sessions and has a bounce rate of %3$s compared with the site average of %4$s.', 'opti-behavior' ),
				$this->get_page_label( $metrics ),
				isset( $metrics['sessions'] ) ? (int) $metrics['sessions'] : 0,
				$this->format_percent( $metrics['bounce_rate'] ?? null ),
				$this->format_percent( $baselines['site_avg_bounce_rate'] ?? null )
			),
			__( 'A high bounce rate means many visitors leave before viewing another page, which can signal a weak first impression or mismatch.', 'opti-behavior' ),
			array(
				__( 'The headline or first-screen promise may not match visitor intent.', 'opti-behavior' ),
				__( 'Visitors may not see a compelling next step quickly enough.', 'opti-behavior' ),
				__( 'Page speed, intrusive elements, or missing trust signals may discourage exploration.', 'opti-behavior' ),
			),
			array(
				$this->get_primary_action_copy_for_signal( 'basic_bounce_alert' ),
				__( 'Add a visible, specific call to action near the first screen.', 'opti-behavior' ),
				__( 'Add trust signals such as reviews, guarantees, logos, or security reassurance.', 'opti-behavior' ),
				__( 'Check page speed and remove intrusive first-screen distractions.', 'opti-behavior' ),
			),
			$this->get_page_basic_reports( $metrics ),
			array(
				'title'       => __( 'Pro bounce diagnosis available', 'opti-behavior' ),
				'description' => __( 'Unlock source, device, and recording context to explain why visitors bounce.', 'opti-behavior' ),
			)
		);
	}

	/**
	 * Build the traffic-spike recommendation template.
	 *
	 * @param array $context Context array.
	 * @return array
	 */
	public function build_page_traffic_spike_template( $context = array() ) {
		$metrics   = isset( $context['metrics'] ) && is_array( $context['metrics'] ) ? $context['metrics'] : array();
		$detection = isset( $context['detection'] ) && is_array( $context['detection'] ) ? $context['detection'] : array();

		return $this->build_generic_template(
			self::TEMPLATE_PAGE_TRAFFIC_SPIKE,
			sprintf(
				/* translators: 1: Page label, 2: Current sessions, 3: Previous sessions, 4: Relative delta. */
				__( '%1$s received %2$d sessions versus %3$d in the previous comparable period, a change of %4$s.', 'opti-behavior' ),
				$this->get_page_label( $metrics ),
				isset( $metrics['sessions'] ) ? (int) $metrics['sessions'] : 0,
				isset( $detection['previous_sessions'] ) ? (int) $detection['previous_sessions'] : 0,
				$this->format_percent( $detection['sessions_relative_delta_pct'] ?? null )
			),
			__( 'A traffic spike can be an opportunity, but it should be checked to make sure the new traffic is relevant and the page is ready to convert it.', 'opti-behavior' ),
			array(
				__( 'A campaign, referral, search ranking change, or social mention may have increased traffic.', 'opti-behavior' ),
				__( 'The page may need stronger calls to action while attention is high.', 'opti-behavior' ),
				__( 'If bounce or exit rates are also high, the spike may include low-intent visitors.', 'opti-behavior' ),
			),
			array(
				$this->get_primary_action_copy_for_signal( 'traffic_spike_observation' ),
				__( 'Check whether bounce rate, scroll depth, and exit rate remain healthy during the spike.', 'opti-behavior' ),
				__( 'Add or strengthen the primary call to action while traffic is elevated.', 'opti-behavior' ),
				__( 'Monitor the same page over the next period to confirm whether the spike persists.', 'opti-behavior' ),
			),
			$this->get_page_basic_reports( $metrics ),
			array(
				'title'       => __( 'Pro traffic diagnosis available', 'opti-behavior' ),
				'description' => __( 'Unlock source and campaign breakdowns to see which audience caused the spike and how it performed.', 'opti-behavior' ),
			)
		);
	}

	/**
	 * Build the basic mobile bounce warning template.
	 *
	 * @param array $context Context array.
	 * @return array
	 */
	public function build_device_basic_mobile_bounce_template( $context = array() ) {
		$metrics   = isset( $context['metrics'] ) && is_array( $context['metrics'] ) ? $context['metrics'] : array();
		$detection = isset( $context['detection'] ) && is_array( $context['detection'] ) ? $context['detection'] : array();

		return $this->build_generic_template(
			self::TEMPLATE_DEVICE_BASIC_MOBILE_BOUNCE,
			sprintf(
				/* translators: 1: Sessions, 2: Mobile bounce, 3: Comparison label, 4: Comparison bounce. */
				__( 'Mobile visitors generated %1$d sessions with a bounce rate of %2$s, which is higher than the %3$s comparison of %4$s.', 'opti-behavior' ),
				isset( $metrics['sessions'] ) ? (int) $metrics['sessions'] : 0,
				$this->format_percent( $metrics['bounce_rate'] ?? null ),
				isset( $detection['comparison_device'] ) ? sanitize_text_field( $detection['comparison_device'] ) : __( 'site average', 'opti-behavior' ),
				$this->format_percent( $detection['comparison_bounce_rate'] ?? null )
			),
			__( 'Mobile bounce issues often point to responsive layout, speed, CTA visibility, readability, or tap-target friction.', 'opti-behavior' ),
			array(
				__( 'Mobile users may not see the main value proposition or call to action quickly enough.', 'opti-behavior' ),
				__( 'Text size, spacing, tap targets, or page speed may be weaker on mobile.', 'opti-behavior' ),
				__( 'A full diagnosis requires segment and journey context, which is available in Pro.', 'opti-behavior' ),
			),
			array(
				$this->get_primary_action_copy_for_signal( 'basic_mobile_bounce_warning' ),
				__( 'Reduce hero height, improve spacing, and keep copy concise on small screens.', 'opti-behavior' ),
				__( 'Check mobile speed and remove intrusive popups or layout shifts.', 'opti-behavior' ),
			),
			array(
				array(
					'label'  => __( 'Compare devices', 'opti-behavior' ),
					'type'   => 'device_report',
					'target' => 'mobile',
				),
			),
			array(
				'title'       => __( 'Pro mobile friction diagnosis available', 'opti-behavior' ),
				'description' => __( 'Unlock mobile vs desktop breakdowns by page, source, recordings, and UX recommendations.', 'opti-behavior' ),
			)
		);
	}

	/**
	 * Build a generic structured recommendation template.
	 *
	 * @param string $template_id         Template ID.
	 * @param string $interpretation      Interpretation.
	 * @param string $why_it_matters      Why it matters.
	 * @param array  $likely_causes       Likely causes.
	 * @param array  $recommended_actions Recommended actions.
	 * @param array  $related_reports     Related reports.
	 * @param array  $upgrade_preview     Upgrade preview.
	 * @return array
	 */
	private function build_generic_template( $template_id, $interpretation, $why_it_matters, $likely_causes, $recommended_actions, $related_reports, $upgrade_preview = array() ) {
		$template = array(
			'template_id'         => sanitize_key( $template_id ),
			'template_version'    => '1.0.0',
			'interpretation'      => $interpretation,
			'why_it_matters'      => $why_it_matters,
			'likely_causes'       => array_values( array_unique( $likely_causes ) ),
			'recommended_actions' => array_values( array_unique( $recommended_actions ) ),
			'related_reports'     => $related_reports,
		);

		if ( ! empty( $upgrade_preview ) ) {
			$template['upgrade_preview'] = $upgrade_preview;
		}

		return apply_filters( 'opti_behavior_smart_insights_recommendation_template', $template, array( 'template_id' => $template_id ) );
	}

	/**
	 * Get basic related reports for a page signal.
	 *
	 * @param array $metrics Metrics.
	 * @return array
	 */
	private function get_page_basic_reports( $metrics ) {
		$reports      = $this->get_page_low_engagement_reports( $metrics );
		$page_context = $this->get_page_report_context( $metrics );
		$analytics    = array(
			'label'  => __( 'Open analytics for this page', 'opti-behavior' ),
			'type'   => 'analytics',
			'target' => isset( $page_context['page_url'] ) ? $page_context['page_url'] : '',
		);

		$analytics_url = $this->build_admin_report_url(
			'opti-behavior-analytics',
			array_merge(
				$page_context,
				array(
					'si_context' => '1',
					'context'    => 'page',
				)
			)
		);
		if ( '' !== $analytics_url ) {
			$analytics['url'] = $analytics_url;
		}

		$reports[] = $analytics;

		return $reports;
	}

	/**
	 * Select likely causes from available evidence.
	 *
	 * @param array $metrics   Page metrics.
	 * @param array $detection Detection output.
	 * @return array
	 */
	private function get_page_low_engagement_causes( $metrics, $detection ) {
		$causes           = array();
		$avg_time_on_page = $metrics['avg_time_on_page'] ?? null;
		$scroll_gap       = $detection['scroll_gap'] ?? null;
		$page_scroll      = $metrics['avg_scroll_depth'] ?? null;
		$page_bounce      = $metrics['bounce_rate'] ?? null;

		if ( null !== $page_bounce && (float) $page_bounce >= 65 && ( null === $page_scroll || (float) $page_scroll <= 35 ) ) {
			$causes[] = __( 'Visitors may not immediately understand the page value or what to do next.', 'opti-behavior' );
		}

		if ( null !== $scroll_gap && (float) $scroll_gap <= -20 ) {
			$causes[] = __( 'Important benefits or the primary call to action may be too low on the page.', 'opti-behavior' );
		}

		if ( null !== $avg_time_on_page && (float) $avg_time_on_page > 0 && (float) $avg_time_on_page < 30 ) {
			$causes[] = __( 'The first-screen message may not match visitor expectations from the traffic source.', 'opti-behavior' );
		}

		$causes[] = __( 'Mobile layout, readability, page speed, or trust signals may be creating friction.', 'opti-behavior' );
		$causes[] = __( 'Visitors may need more reassurance before they take the next step.', 'opti-behavior' );

		return array_values( array_unique( $causes ) );
	}

	/**
	 * Get recommended actions for the low-engagement template.
	 *
	 * @param array $metrics   Page metrics.
	 * @param array $detection Detection output.
	 * @return array
	 */
	private function get_page_low_engagement_actions( $metrics, $detection ) {
		$actions = array(
			$this->get_primary_action_copy_for_signal( 'high_traffic_low_engagement' ),
			__( 'Place the primary call to action above the fold and make its copy more specific.', 'opti-behavior' ),
			__( 'Move key benefits, differentiators, and proof points higher on the page.', 'opti-behavior' ),
			__( 'Add trust signals near the first call to action, such as testimonials, guarantees, reviews, or logos.', 'opti-behavior' ),
			__( 'Review the heatmap for this page to confirm where visitors stop scrolling or hesitate.', 'opti-behavior' ),
		);

		if ( ! empty( $detection['adaptive_low_traffic'] ) ) {
			$actions[] = __( 'Collect more data before making major changes; treat this as a medium-confidence early warning.', 'opti-behavior' );
		} else {
			$actions[] = __( 'Compare behavior by device and traffic source before prioritizing design changes.', 'opti-behavior' );
		}

		return array_values( array_unique( $actions ) );
	}

	/**
	 * Get related report targets.
	 *
	 * @param array $metrics Page metrics.
	 * @return array
	 */
	private function get_page_low_engagement_reports( $metrics ) {
		$page_context = $this->get_page_report_context( $metrics );
		$page_target  = isset( $page_context['page_url'] ) && '' !== $page_context['page_url'] ? $page_context['page_url'] : ( isset( $page_context['page_id'] ) ? (string) $page_context['page_id'] : '' );
		$reports      = array(
			array(
				'label'  => __( 'Open heatmap for this page', 'opti-behavior' ),
				'type'   => 'heatmap',
				'target' => $page_target,
			),
			array(
				'label'  => __( 'Compare devices for this page', 'opti-behavior' ),
				'type'   => 'device_report',
				'target' => $page_target,
			),
		);

		$heatmap_url = '';
		if ( ! empty( $page_context['page_id'] ) ) {
			$heatmap_url = $this->build_admin_report_url(
				'opti-behavior-heatmap-detail',
				array_merge(
					$page_context,
					array(
						'type' => 'click',
					)
				)
			);
		} elseif ( ! empty( $page_context['page_url'] ) ) {
			$reports[0]['label'] = __( 'Find page in heatmaps', 'opti-behavior' );
			$heatmap_url         = $this->build_admin_report_url(
				'opti-behavior-heatmaps',
				array_merge(
					$page_context,
					array(
						'type'   => 'click',
						'search' => $page_context['page_url'],
					)
				)
			);
		}
		if ( '' !== $heatmap_url ) {
			$reports[0]['url'] = $heatmap_url;
		}

		$device_url = $this->build_admin_report_url(
			'opti-behavior-analytics',
			array_merge(
				$page_context,
				array(
					'si_context' => '1',
					'context'    => 'page',
				)
			)
		);
		if ( '' !== $device_url ) {
			$reports[1]['url'] = $device_url;
		}

		return apply_filters( 'opti_behavior_smart_insights_related_reports', $reports, array( 'metrics' => $metrics ) );
	}

	/**
	 * Build a page-context query for related report deep links.
	 *
	 * @param array $metrics Page metrics.
	 * @return array
	 */
	private function get_page_report_context( $metrics ) {
		$args = array();

		if ( ! empty( $metrics['page_id'] ) && is_numeric( $metrics['page_id'] ) ) {
			$args['page_id'] = absint( $metrics['page_id'] );
		} elseif ( ! empty( $metrics['entity_id'] ) && is_numeric( $metrics['entity_id'] ) ) {
			$args['page_id'] = absint( $metrics['entity_id'] );
		}

		if ( ! empty( $metrics['page_url'] ) ) {
			$args['page_url'] = esc_url_raw( (string) $metrics['page_url'] );
		} elseif ( empty( $args['page_id'] ) && ! empty( $metrics['entity_id'] ) && filter_var( $metrics['entity_id'], FILTER_VALIDATE_URL ) ) {
			$args['page_url'] = esc_url_raw( (string) $metrics['entity_id'] );
		}

		return array_filter(
			$args,
			function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
	}

	/**
	 * Build an admin report URL when report context is available.
	 *
	 * @param string $page_slug Admin page slug.
	 * @param array  $args      Query args excluding page.
	 * @return string
	 */
	private function build_admin_report_url( $page_slug, $args = array() ) {
		if ( ! function_exists( 'admin_url' ) || ! function_exists( 'add_query_arg' ) ) {
			return '';
		}

		$args         = is_array( $args ) ? $args : array();
		$has_context  = false;
		$clean_args   = array( 'page' => sanitize_key( $page_slug ) );

		foreach ( $args as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			$clean_key = sanitize_key( $key );
			if ( '' === $clean_key || 'page' === $clean_key ) {
				continue;
			}

			$clean_args[ $clean_key ] = is_numeric( $value ) ? (string) $value : sanitize_text_field( (string) $value );
			$has_context              = true;
		}

		if ( ! $has_context ) {
			return '';
		}

		return admin_url( add_query_arg( $clean_args, 'admin.php' ) );
	}

	/**
	 * Get a readable page label.
	 *
	 * @param array $metrics Page metrics.
	 * @return string
	 */
	private function get_page_label( $metrics ) {
		if ( ! empty( $metrics['entity_label'] ) ) {
			return (string) $metrics['entity_label'];
		}

		if ( ! empty( $metrics['page_title'] ) ) {
			return (string) $metrics['page_title'];
		}

		if ( ! empty( $metrics['page_url'] ) ) {
			return (string) $metrics['page_url'];
		}

		if ( ! empty( $metrics['entity_id'] ) ) {
			return (string) $metrics['entity_id'];
		}

		return __( 'This page', 'opti-behavior' );
	}

	/**
	 * Format a 0-100 percentage value.
	 *
	 * @param mixed $value Percentage value.
	 * @return string
	 */
	private function format_percent( $value ) {
		if ( null === $value || '' === $value ) {
			return __( 'not available', 'opti-behavior' );
		}

		return sprintf(
			/* translators: %s: Percentage value. */
			__( '%s%%', 'opti-behavior' ),
			number_format_i18n( round( (float) $value, 1 ), 1 )
		);
	}

	/**
	 * Format seconds as a readable value.
	 *
	 * @param mixed $value Seconds.
	 * @return string
	 */
	private function format_seconds( $value ) {
		if ( null === $value || '' === $value ) {
			return __( 'not available', 'opti-behavior' );
		}

		return sprintf(
			/* translators: %s: Seconds. */
			__( '%s seconds', 'opti-behavior' ),
			number_format_i18n( round( (float) $value, 1 ), 1 )
		);
	}
}
