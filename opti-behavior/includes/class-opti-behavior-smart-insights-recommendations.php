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
