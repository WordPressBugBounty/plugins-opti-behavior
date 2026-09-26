<?php
/**
 * Smart Insights Evidence Builder Class
 *
 * Turns the aggregate correlation output into a typed evidence bundle: a small,
 * ordered list of references (`session`, `recording`, `error_group`, `form`,
 * `field`, `heatmap_zone`, `heatmap`, `funnel`, `journey`, `segment`,
 * `comparison`) that the insight detail modal renders as proof, each carrying
 * the exact destination-report context (page, dates, segment, spam policy) the
 * target report needs to display the affected subset.
 *
 * The builder never reads a data source itself: scope references come from the
 * insight's own metrics and probe references come from the correlation probe
 * `sample_refs` already produced by the providers. Anything that cannot be
 * addressed (no page, no URL, no funnel/form context) yields an empty bundle so
 * legacy insights keep rendering through the classic evidence path.
 *
 * @package opti-behavior
 * @since   1.3.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Evidence Builder Class.
 *
 * @since 1.3.8
 */
class Opti_Behavior_Smart_Insights_Evidence_Builder {

	/**
	 * Evidence bundle schema version.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Maximum references persisted per insight.
	 */
	const MAX_REFS = 32;

	/**
	 * Maximum references persisted per reference type.
	 */
	const MAX_REFS_PER_TYPE = 6;

	/**
	 * Signals whose cohort is single-pageview by definition.
	 *
	 * A journey flow needs at least two pageviews in the same session, so a
	 * journey destination can never describe a bounce/quick-exit cohort no
	 * matter how much data the site collects. These references stay visible
	 * (the destination is still the right one to reason about) but are marked
	 * unavailable so the modal renders them disabled with the reason instead of
	 * linking to a screen that is guaranteed to be empty.
	 *
	 * @since 1.4.1
	 */
	const SINGLE_PAGEVIEW_SIGNALS = array(
		'quick_exit_pattern',
		'basic_bounce_alert',
		'basic_mobile_bounce_warning',
		'high_exit_rate_page',
		'high_traffic_low_engagement',
	);

	/**
	 * Attach an evidence bundle to every candidate record.
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

		foreach ( $candidates as $index => $candidate ) {
			if ( empty( $candidate['insight'] ) || ! is_array( $candidate['insight'] ) ) {
				continue;
			}

			$bundle = $this->build( $candidate['insight'], $date_range, $args );
			if ( empty( $bundle['refs'] ) ) {
				continue;
			}

			$candidates[ $index ]['insight']['evidence_refs'] = $bundle;
		}

		return $candidates;
	}

	/**
	 * Build the typed evidence bundle for one insight.
	 *
	 * @param array $insight    Insight payload.
	 * @param array $date_range Generation date range.
	 * @param array $args       Generation args.
	 * @return array Bundle, or an empty array when nothing is addressable.
	 */
	public function build( $insight, $date_range = array(), $args = array() ) {
		$insight = is_array( $insight ) ? $insight : array();

		$scope = $this->build_scope( $insight, is_array( $date_range ) ? $date_range : array(), is_array( $args ) ? $args : array() );
		if ( ! $this->scope_is_addressable( $scope ) ) {
			return array();
		}

		$refs = array_merge(
			$this->build_scope_refs( $scope, $insight ),
			$this->build_probe_refs( $scope, $insight )
		);

		/**
		 * Filter the typed evidence references of one insight.
		 *
		 * @since 1.3.8
		 *
		 * @param array $refs    Typed references.
		 * @param array $insight Insight payload.
		 * @param array $scope   Evidence scope.
		 */
		$refs = apply_filters( 'opti_behavior_smart_insights_evidence_refs', $refs, $insight, $scope );

		$refs = $this->limit_refs( $refs );
		if ( empty( $refs ) ) {
			return array();
		}

		return array(
			'version'      => self::SCHEMA_VERSION,
			'scope'        => $scope,
			'refs'         => $refs,
			'counts'       => $this->count_refs( $refs ),
			'generated_at' => $this->now(),
		);
	}

	/**
	 * Resolve the destination context shared by every reference.
	 *
	 * @param array $insight    Insight payload.
	 * @param array $date_range Date range.
	 * @param array $args       Generation args.
	 * @return array
	 */
	private function build_scope( $insight, $date_range, $args ) {
		$metrics     = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
		$context     = isset( $metrics['entity_context'] ) && is_array( $metrics['entity_context'] ) ? $metrics['entity_context'] : array();
		$correlation = isset( $insight['correlation'] ) && is_array( $insight['correlation'] ) ? $insight['correlation'] : array();
		$corr_scope  = isset( $correlation['scope'] ) && is_array( $correlation['scope'] ) ? $correlation['scope'] : array();

		$entity_type = isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '';
		$entity_id   = isset( $insight['entity_id'] ) && is_scalar( $insight['entity_id'] ) ? (string) $insight['entity_id'] : '';

		$page_id = 0;
		foreach ( array( $corr_scope, $metrics, $context ) as $bag ) {
			if ( isset( $bag['page_id'] ) && is_numeric( $bag['page_id'] ) && absint( $bag['page_id'] ) > 0 ) {
				$page_id = absint( $bag['page_id'] );
				break;
			}
		}
		if ( 0 === $page_id && 'page' === $entity_type && is_numeric( $entity_id ) ) {
			$page_id = absint( $entity_id );
		}

		$page_url       = '';
		$url_candidates = array(
			isset( $corr_scope['page_url_full'] ) ? $corr_scope['page_url_full'] : '',
			isset( $metrics['page_url'] ) ? $metrics['page_url'] : '',
			isset( $context['view_url'] ) ? $context['view_url'] : '',
			$entity_id,
		);
		foreach ( $url_candidates as $candidate_url ) {
			if ( is_scalar( $candidate_url ) && preg_match( '#^https?://#i', trim( (string) $candidate_url ) ) ) {
				$page_url = esc_url_raw( trim( (string) $candidate_url ) );
				break;
			}
		}

		$date_from = $this->first_date(
			array(
				isset( $corr_scope['date_from'] ) ? $corr_scope['date_from'] : '',
				isset( $date_range['from'] ) ? $date_range['from'] : '',
				isset( $metrics['date_from'] ) ? $metrics['date_from'] : '',
			)
		);
		$date_to   = $this->first_date(
			array(
				isset( $corr_scope['date_to'] ) ? $corr_scope['date_to'] : '',
				isset( $date_range['to'] ) ? $date_range['to'] : '',
				isset( $metrics['date_to'] ) ? $metrics['date_to'] : '',
			)
		);

		$compare = $this->build_compare_window( $date_from, $date_to );

		$exclude_spam = null;
		if ( array_key_exists( 'exclude_spam', $args ) && null !== $args['exclude_spam'] ) {
			$exclude_spam = (bool) $args['exclude_spam'] ? '1' : '0';
		} elseif ( isset( $corr_scope['exclude_spam'] ) && null !== $corr_scope['exclude_spam'] ) {
			$exclude_spam = (bool) $corr_scope['exclude_spam'] ? '1' : '0';
		}

		return array(
			'entity_type'  => $entity_type,
			'entity_id'    => $entity_id,
			'page_id'      => $page_id,
			'page_url'     => $page_url,
			'form_id'      => $this->first_scalar( array( isset( $metrics['form_id'] ) ? $metrics['form_id'] : '', 'form' === $entity_type ? $entity_id : '' ) ),
			'funnel_id'    => $this->first_scalar( array( isset( $metrics['funnel_id'] ) ? $metrics['funnel_id'] : '', 'funnel' === $entity_type && is_numeric( $entity_id ) ? $entity_id : '' ) ),
			'funnel_step'  => $this->first_scalar( array( isset( $metrics['step_index'] ) ? $metrics['step_index'] : '', isset( $metrics['funnel_step'] ) ? $metrics['funnel_step'] : '' ) ),
			'error_type'   => $this->first_scalar( array( isset( $metrics['error_type'] ) ? $metrics['error_type'] : '', 'error' === $entity_type ? $entity_id : '' ) ),
			'cta_selector' => $this->first_scalar( array( isset( $metrics['cta_selector'] ) ? $metrics['cta_selector'] : '', isset( $metrics['selector'] ) ? $metrics['selector'] : '' ) ),
			'sessions'     => $this->resolve_population( $insight, $corr_scope ),
			'date_from'    => $date_from,
			'date_to'      => $date_to,
			'compare_from' => $compare['from'],
			'compare_to'   => $compare['to'],
			'exclude_spam' => $exclude_spam,
		);
	}

	/**
	 * Whether the scope can be translated into a destination filter.
	 *
	 * @param array $scope Evidence scope.
	 * @return bool
	 */
	private function scope_is_addressable( $scope ) {
		return ! empty( $scope['page_id'] ) || '' !== $scope['page_url'] || '' !== $scope['funnel_id'] || '' !== $scope['form_id'];
	}

	/**
	 * Build the references derived from the insight scope itself.
	 *
	 * @param array $scope   Evidence scope.
	 * @param array $insight Insight payload.
	 * @return array
	 */
	private function build_scope_refs( $scope, $insight ) {
		$refs      = array();
		$has_page  = ! empty( $scope['page_id'] ) || '' !== $scope['page_url'];
		$entity    = isset( $insight['entity_label'] ) ? sanitize_text_field( (string) $insight['entity_label'] ) : '';
		$page_name = '' !== $entity ? $entity : ( '' !== $scope['page_url'] ? $scope['page_url'] : (string) $scope['page_id'] );

		if ( $has_page ) {
			$refs[] = $this->make_ref(
				array(
					'type'   => 'session',
					'id'     => '',
					'label'  => $page_name,
					'count'  => (int) $scope['sessions'],
					'action' => 'view_sessions',
					'source' => 'scope',
					'report' => $this->report_context( $scope, 'session_recordings' ),
				)
			);

			$refs[] = $this->make_ref(
				array(
					'type'   => 'heatmap',
					'id'     => '' !== $scope['cta_selector'] ? $scope['cta_selector'] : '',
					'label'  => $page_name,
					'action' => 'open_heatmap',
					'source' => 'scope',
					'report' => $this->report_context(
						$scope,
						'heatmap',
						array(
							'heatmap_type' => 'click',
							'cta_selector' => $scope['cta_selector'],
						)
					),
				)
			);

			$journey_report = $this->report_context( $scope, 'user_journey', array( 'tab' => 'flow' ) );
			$journey_block  = $this->get_single_pageview_block_reason( $insight );
			if ( '' !== $journey_block ) {
				$journey_report = $this->mark_report_unavailable( $journey_report, $journey_block );
			}

			$refs[] = $this->make_ref(
				array(
					'type'   => 'journey',
					'id'     => '',
					'label'  => $page_name,
					'action' => 'view_journey',
					'source' => 'scope',
					'report' => $journey_report,
				)
			);
		}

		if ( '' !== $scope['funnel_id'] ) {
			$refs[] = $this->make_ref(
				array(
					'type'     => 'funnel',
					'id'       => $scope['funnel_id'],
					'label'    => '' !== $entity ? $entity : $scope['funnel_id'],
					'action'   => 'view_funnel',
					'source'   => 'scope',
					'report'   => $this->report_context( $scope, 'funnel' ),
					'url_args' => '' !== $scope['funnel_step'] ? array( 'funnel_step' => $scope['funnel_step'] ) : array(),
				)
			);
		}

		if ( '' !== $scope['form_id'] ) {
			$refs[] = $this->make_ref(
				array(
					'type'   => 'form',
					'id'     => $scope['form_id'],
					'label'  => '' !== $entity ? $entity : $scope['form_id'],
					'action' => 'view_form_analytics',
					'source' => 'scope',
					'report' => $this->report_context( $scope, 'form_analytics', array( 'tab' => 'field-analysis' ) ),
				)
			);
		}

		if ( '' !== $scope['error_type'] && 'site' !== $scope['error_type'] ) {
			$refs[] = $this->make_ref(
				array(
					'type'   => 'error_group',
					'id'     => $scope['error_type'],
					'label'  => $scope['error_type'],
					'action' => 'view_errors',
					'source' => 'scope',
					'report' => $this->report_context( $scope, 'error_tracking', array( 'error_type' => $scope['error_type'] ) ),
				)
			);
		}

		if ( $has_page && '' !== $scope['compare_from'] && '' !== $scope['compare_to'] ) {
			$refs[] = $this->make_ref(
				array(
					'type'           => 'comparison',
					'id'             => $scope['compare_from'] . '_' . $scope['compare_to'],
					'label'          => $scope['compare_from'] . ' - ' . $scope['compare_to'],
					'action'         => 'compare_before_after',
					'source'         => 'scope',
					// The analytics dashboard has no per-page data scope: the
					// page context travels with the link as a chip, but the
					// numbers on the destination are site-wide. Disclose it on
					// the reference so the modal can label the button instead of
					// implying a page-scoped before/after.
					'scope_note'     => __( 'Opens the analytics dashboard, which reports site-wide totals for the period - it cannot scope its numbers to a single page.', 'opti-behavior' ),
					'report'         => $this->report_context(
						$scope,
						'analytics',
						array(
							'compare_start_date' => $scope['compare_from'],
							'compare_end_date'   => $scope['compare_to'],
						)
					),
					'compare_report' => $this->report_context(
						$scope,
						'analytics',
						array(
							'start_date'         => $scope['compare_from'],
							'end_date'           => $scope['compare_to'],
							'date_range'         => 'custom',
							'compare_start_date' => $scope['date_from'],
							'compare_end_date'   => $scope['date_to'],
							// The destination derives its cohort window from the
							// insight when the report carries none; the previous
							// period must override it or the "before" link would
							// re-scope to the detection window.
							'cohort_start_at'    => $scope['compare_from'] . ' 00:00:00',
							'cohort_end_at'      => $scope['compare_to'] . ' 23:59:59',
						)
					),
				)
			);
		}

		return $refs;
	}

	/**
	 * Build references from the correlation probe sample refs.
	 *
	 * @param array $scope   Evidence scope.
	 * @param array $insight Insight payload.
	 * @return array
	 */
	private function build_probe_refs( $scope, $insight ) {
		$correlation = isset( $insight['correlation'] ) && is_array( $insight['correlation'] ) ? $insight['correlation'] : array();
		$probes      = isset( $correlation['probes'] ) && is_array( $correlation['probes'] ) ? $correlation['probes'] : array();
		if ( empty( $probes ) ) {
			return array();
		}

		$refs = array();
		foreach ( $probes as $probe_id => $probe ) {
			if ( ! is_array( $probe ) || empty( $probe['available'] ) || empty( $probe['sample_refs'] ) || ! is_array( $probe['sample_refs'] ) ) {
				continue;
			}

			$probe_id    = sanitize_key( $probe_id );
			$probe_label = isset( $probe['label'] ) ? sanitize_text_field( (string) $probe['label'] ) : '';

			foreach ( $probe['sample_refs'] as $sample ) {
				$ref = $this->build_probe_ref( $sample, $scope, $probe_id, $probe_label );
				if ( ! empty( $ref ) ) {
					$refs[] = $ref;
				}

				$field_ref = $this->build_field_ref( $sample, $scope, $probe_id, $probe_label );
				if ( ! empty( $field_ref ) ) {
					$refs[] = $field_ref;
				}
			}
		}

		return $refs;
	}

	/**
	 * Map one probe sample reference to a typed evidence reference.
	 *
	 * @param mixed  $sample      Raw probe sample ref.
	 * @param array  $scope       Evidence scope.
	 * @param string $probe_id    Probe identifier.
	 * @param string $probe_label Probe label.
	 * @return array
	 */
	private function build_probe_ref( $sample, $scope, $probe_id, $probe_label ) {
		if ( ! is_array( $sample ) || empty( $sample['type'] ) ) {
			return array();
		}

		$type     = sanitize_key( $sample['type'] );
		$id       = isset( $sample['id'] ) && is_scalar( $sample['id'] ) ? sanitize_text_field( (string) $sample['id'] ) : '';
		$label    = isset( $sample['label'] ) && '' !== $sample['label'] ? sanitize_text_field( (string) $sample['label'] ) : $id;
		$share    = isset( $sample['share'] ) && is_numeric( $sample['share'] ) ? round( (float) $sample['share'], 4 ) : null;
		$url_args = isset( $sample['url_args'] ) && is_array( $sample['url_args'] ) ? $sample['url_args'] : array();

		$base = array(
			'type'   => $type,
			'id'     => $id,
			'label'  => $label,
			'share'  => $share,
			'probe'  => $probe_id,
			'source' => 'probe:' . $probe_id,
			'group'  => $probe_label,
		);

		switch ( $type ) {
			case 'error_group':
				$base['action'] = 'view_errors';
				$base['report'] = $this->report_context( $scope, 'error_tracking', array( 'error_type' => $id ) );
				break;
			case 'form':
				$field          = isset( $url_args['field'] ) ? sanitize_text_field( (string) $url_args['field'] ) : '';
				$base['action'] = 'view_form_analytics';
				$base['report'] = $this->report_context(
					$scope,
					'form_analytics',
					array(
						'form_id' => $id,
						'tab'     => 'field-analysis',
					)
				);
				if ( '' !== $field ) {
					$base['url_args'] = array( 'field' => $field );
				}
				break;
			case 'heatmap_zone':
				$friction       = isset( $url_args['friction_type'] ) ? sanitize_key( $url_args['friction_type'] ) : '';
				$base['action'] = 'open_heatmap';
				$base['report'] = $this->report_context(
					$scope,
					'heatmap',
					array(
						'heatmap_type' => 'click',
						'cta_selector' => $id,
					)
				);
				if ( '' !== $friction ) {
					$base['url_args'] = array(
						'friction_type' => $friction,
						'selector'      => $id,
					);
				}
				break;
			case 'recording':
				$session        = isset( $url_args['session_id'] ) ? sanitize_text_field( (string) $url_args['session_id'] ) : '';
				$base['action'] = 'view_sessions';
				$base['label']  = '' !== $label ? $label : $id;
				$base['report'] = $this->report_context( $scope, 'session_recordings' );
				// The recordings list resolves `recording` (player) and
				// `session_id` (exact-match filter); both are carried so the
				// destination opens on the sampled visit, not the whole page.
				$base['url_args'] = array(
					'recording'    => $id,
					'recording_id' => $id,
				);
				if ( '' !== $session ) {
					$base['url_args']['session_id'] = $session;
				}
				break;
			case 'session':
				$session          = isset( $url_args['session_id'] ) ? sanitize_text_field( (string) $url_args['session_id'] ) : $id;
				$base['action']   = 'view_sessions';
				$base['report']   = $this->report_context( $scope, 'session_recordings' );
				$base['url_args'] = '' !== $session ? array( 'session_id' => $session ) : array();
				break;
			case 'segment':
				$dimension      = isset( $url_args['segment_dimension'] ) ? sanitize_key( $url_args['segment_dimension'] ) : '';
				$key            = isset( $url_args['segment_key'] ) ? sanitize_text_field( (string) $url_args['segment_key'] ) : $id;
				$base['action'] = 'view_segment';
				$base['report'] = $this->report_context(
					$scope,
					'analytics',
					array_merge(
						array(
							'segment_dimension' => $dimension,
							'segment_key'       => $key,
						),
						$this->segment_report_fields( $dimension, $key )
					)
				);
				break;
			default:
				return array();
		}

		return $this->make_ref( $base );
	}

	/**
	 * Emit a dedicated field-anchor reference for form probe samples.
	 *
	 * @param mixed  $sample      Raw probe sample ref.
	 * @param array  $scope       Evidence scope.
	 * @param string $probe_id    Probe identifier.
	 * @param string $probe_label Probe label.
	 * @return array
	 */
	private function build_field_ref( $sample, $scope, $probe_id, $probe_label ) {
		if ( ! is_array( $sample ) || empty( $sample['type'] ) || 'form' !== sanitize_key( $sample['type'] ) ) {
			return array();
		}

		$url_args = isset( $sample['url_args'] ) && is_array( $sample['url_args'] ) ? $sample['url_args'] : array();
		$field    = isset( $url_args['field'] ) ? sanitize_text_field( (string) $url_args['field'] ) : '';
		if ( '' === $field ) {
			return array();
		}

		$form_id = isset( $sample['id'] ) && is_scalar( $sample['id'] ) ? sanitize_text_field( (string) $sample['id'] ) : '';

		return $this->make_ref(
			array(
				'type'     => 'field',
				'id'       => $field,
				'label'    => $field,
				'share'    => isset( $sample['share'] ) && is_numeric( $sample['share'] ) ? round( (float) $sample['share'], 4 ) : null,
				'probe'    => $probe_id,
				'source'   => 'probe:' . $probe_id,
				'group'    => $probe_label,
				'action'   => 'view_form_analytics',
				'report'   => $this->report_context(
					$scope,
					'form_analytics',
					array(
						'form_id' => $form_id,
						'tab'     => 'field-analysis',
					)
				),
				'url_args' => array( 'field' => $field ),
			)
		);
	}

	/**
	 * Translate a segment dimension into destination report fields.
	 *
	 * @param string $dimension Segment dimension.
	 * @param string $key       Segment value.
	 * @return array
	 */
	private function segment_report_fields( $dimension, $key ) {
		if ( '' === $key ) {
			return array();
		}

		if ( preg_match( '/^(device|device_type)$/', $dimension ) ) {
			return array( 'device' => $key );
		}
		if ( preg_match( '/^(source|traffic_source|utm_source|referrer)$/', $dimension ) ) {
			return array( 'source' => $key );
		}
		if ( preg_match( '/^(campaign|utm_campaign)$/', $dimension ) ) {
			return array( 'campaign' => $key );
		}

		return array();
	}

	/**
	 * Build the destination report descriptor for one reference.
	 *
	 * The keys mirror the related-report contract already consumed by the admin
	 * link resolver, so a typed reference navigates exactly like a related
	 * report and carries the same page/date/segment/spam context.
	 *
	 * @param array  $scope       Evidence scope.
	 * @param string $report_type Destination report type.
	 * @param array  $extra       Extra descriptor fields.
	 * @return array
	 */
	private function report_context( $scope, $report_type, $extra = array() ) {
		$report = array(
			'type' => sanitize_key( $report_type ),
		);

		if ( ! empty( $scope['page_id'] ) ) {
			$report['page_id'] = (int) $scope['page_id'];
		}
		if ( '' !== $scope['page_url'] ) {
			$report['page_url'] = $scope['page_url'];
		}
		if ( '' !== $scope['funnel_id'] ) {
			$report['funnel_id'] = $scope['funnel_id'];
		}
		if ( '' !== $scope['form_id'] ) {
			$report['form_id'] = $scope['form_id'];
		}
		if ( '' !== $scope['date_from'] && '' !== $scope['date_to'] ) {
			$report['start_date'] = $scope['date_from'];
			$report['end_date']   = $scope['date_to'];
			$report['date_range'] = 'custom';
		}
		if ( null !== $scope['exclude_spam'] ) {
			$report['exclude_spam'] = $scope['exclude_spam'];
		}

		foreach ( (array) $extra as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! is_scalar( $value ) || '' === $value ) {
				continue;
			}

			$report[ $key ] = is_numeric( $value ) ? $value + 0 : sanitize_text_field( (string) $value );
		}

		return $report;
	}

	/**
	 * Normalize one typed evidence reference.
	 *
	 * @param array $ref Raw reference.
	 * @return array
	 */
	private function make_ref( $ref ) {
		$type   = isset( $ref['type'] ) ? sanitize_key( $ref['type'] ) : '';
		$action = isset( $ref['action'] ) ? sanitize_key( $ref['action'] ) : '';
		if ( '' === $type || '' === $action ) {
			return array();
		}

		$url_args = array();
		foreach ( isset( $ref['url_args'] ) && is_array( $ref['url_args'] ) ? $ref['url_args'] : array() as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! is_scalar( $value ) || '' === $value ) {
				continue;
			}

			$url_args[ $key ] = is_numeric( $value ) ? $value + 0 : sanitize_text_field( (string) $value );
		}

		$normalized = array(
			'type'         => $type,
			'id'           => isset( $ref['id'] ) && is_scalar( $ref['id'] ) ? sanitize_text_field( (string) $ref['id'] ) : '',
			'label'        => isset( $ref['label'] ) ? sanitize_text_field( (string) $ref['label'] ) : '',
			'action'       => $action,
			'action_label' => $this->get_action_label( $action ),
			'type_label'   => $this->get_type_label( $type ),
			'source'       => isset( $ref['source'] ) ? sanitize_text_field( (string) $ref['source'] ) : 'scope',
			'report'       => isset( $ref['report'] ) && is_array( $ref['report'] ) ? $ref['report'] : array(),
			'url_args'     => $url_args,
		);

		if ( isset( $ref['probe'] ) && '' !== $ref['probe'] ) {
			$normalized['probe'] = sanitize_key( $ref['probe'] );
		}
		if ( isset( $ref['group'] ) && '' !== $ref['group'] ) {
			$normalized['group'] = sanitize_text_field( (string) $ref['group'] );
		}
		if ( isset( $ref['share'] ) && null !== $ref['share'] && is_numeric( $ref['share'] ) ) {
			$normalized['share']     = round( (float) $ref['share'], 4 );
			$normalized['share_pct'] = round( (float) $ref['share'] * 100, 1 );
		}
		if ( isset( $ref['count'] ) && (int) $ref['count'] > 0 ) {
			$normalized['count'] = (int) $ref['count'];
		}
		if ( isset( $ref['compare_report'] ) && is_array( $ref['compare_report'] ) ) {
			$normalized['compare_report'] = $ref['compare_report'];
		}
		if ( isset( $ref['scope_note'] ) && '' !== $ref['scope_note'] ) {
			$normalized['scope_note'] = sanitize_text_field( (string) $ref['scope_note'] );
		}

		return $normalized;
	}

	/**
	 * Mark a destination descriptor as unavailable with a truthful reason.
	 *
	 * Mirrors the related-report contract (`available`/`enabled`/`reason`) the
	 * admin link resolver already honors, so a reference marked here renders as
	 * a disabled control with a tooltip instead of a link to an empty screen.
	 *
	 * @since 1.4.1
	 *
	 * @param array  $report Destination descriptor.
	 * @param string $reason Human-readable reason.
	 * @return array
	 */
	private function mark_report_unavailable( $report, $reason ) {
		$report                    = is_array( $report ) ? $report : array();
		$reason                    = sanitize_text_field( (string) $reason );
		$report['available']       = false;
		$report['enabled']         = false;
		$report['reason']          = $reason;
		$report['disabled_reason'] = $reason;

		return $report;
	}

	/**
	 * Reason a journey destination cannot describe this signal, when applicable.
	 *
	 * @since 1.4.1
	 *
	 * @param array $insight Insight payload.
	 * @return string Empty string when the journey destination applies.
	 */
	private function get_single_pageview_block_reason( $insight ) {
		$signal_id = isset( $insight['signal_id'] ) ? sanitize_key( (string) $insight['signal_id'] ) : '';
		if ( '' === $signal_id || ! in_array( $signal_id, self::SINGLE_PAGEVIEW_SIGNALS, true ) ) {
			return '';
		}

		return __( 'Journey flows need sessions with at least two pageviews; this insight describes single-pageview exits.', 'opti-behavior' );
	}

	/**
	 * Cap the bundle size per type and overall, keeping the strongest evidence.
	 *
	 * @param array $refs Raw references.
	 * @return array
	 */
	private function limit_refs( $refs ) {
		$per_type = array();
		$kept     = array();
		$seen     = array();

		foreach ( (array) $refs as $ref ) {
			if ( ! is_array( $ref ) || empty( $ref['type'] ) || empty( $ref['action'] ) ) {
				continue;
			}

			$type      = sanitize_key( $ref['type'] );
			$signature = $type . '|' . ( isset( $ref['id'] ) ? (string) $ref['id'] : '' ) . '|' . ( isset( $ref['action'] ) ? (string) $ref['action'] : '' );
			if ( isset( $seen[ $signature ] ) ) {
				continue;
			}

			$per_type[ $type ] = isset( $per_type[ $type ] ) ? $per_type[ $type ] + 1 : 1;
			if ( $per_type[ $type ] > self::MAX_REFS_PER_TYPE ) {
				continue;
			}

			$seen[ $signature ] = true;
			$kept[]             = $ref;

			if ( count( $kept ) >= self::MAX_REFS ) {
				break;
			}
		}

		return $kept;
	}

	/**
	 * Summarize the bundle for tier shaping and UI counters.
	 *
	 * @param array $refs Kept references.
	 * @return array
	 */
	private function count_refs( $refs ) {
		$by_type   = array();
		$by_action = array();

		foreach ( (array) $refs as $ref ) {
			$type             = isset( $ref['type'] ) ? sanitize_key( $ref['type'] ) : '';
			$action           = isset( $ref['action'] ) ? sanitize_key( $ref['action'] ) : '';
			$by_type[ $type ] = isset( $by_type[ $type ] ) ? $by_type[ $type ] + 1 : 1;
			if ( '' !== $action ) {
				$by_action[ $action ] = isset( $by_action[ $action ] ) ? $by_action[ $action ] + 1 : 1;
			}
		}

		return array(
			'total'     => count( $refs ),
			'by_type'   => $by_type,
			'by_action' => $by_action,
		);
	}

	/**
	 * Human label per evidence action.
	 *
	 * @param string $action Action key.
	 * @return string
	 */
	private function get_action_label( $action ) {
		$labels = array(
			'view_sessions'        => __( 'View affected sessions', 'opti-behavior' ),
			'open_heatmap'         => __( 'Open heatmap', 'opti-behavior' ),
			'view_funnel'          => __( 'View funnel', 'opti-behavior' ),
			'view_journey'         => __( 'View journey', 'opti-behavior' ),
			'view_form_analytics'  => __( 'View form analytics', 'opti-behavior' ),
			'view_errors'          => __( 'View errors', 'opti-behavior' ),
			'view_segment'         => __( 'Open analytics for this segment', 'opti-behavior' ),
			'compare_before_after' => __( 'Compare before/after (site-wide)', 'opti-behavior' ),
		);

		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}

	/**
	 * Human label per evidence reference type.
	 *
	 * @param string $type Reference type.
	 * @return string
	 */
	private function get_type_label( $type ) {
		$labels = array(
			'session'      => __( 'Affected sessions', 'opti-behavior' ),
			'recording'    => __( 'Session recordings', 'opti-behavior' ),
			'error_group'  => __( 'Error groups', 'opti-behavior' ),
			'form'         => __( 'Forms', 'opti-behavior' ),
			'field'        => __( 'Form fields', 'opti-behavior' ),
			'heatmap_zone' => __( 'Interaction zones', 'opti-behavior' ),
			'heatmap'      => __( 'Heatmap', 'opti-behavior' ),
			'funnel'       => __( 'Funnel', 'opti-behavior' ),
			'journey'      => __( 'User journey', 'opti-behavior' ),
			'segment'      => __( 'Segments', 'opti-behavior' ),
			'comparison'   => __( 'Before/after comparison', 'opti-behavior' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Resolve the affected population used as the session-sample count.
	 *
	 * @param array $insight    Insight payload.
	 * @param array $corr_scope Correlation scope.
	 * @return int
	 */
	private function resolve_population( $insight, $corr_scope ) {
		if ( isset( $corr_scope['sessions'] ) && (int) $corr_scope['sessions'] > 0 ) {
			return (int) $corr_scope['sessions'];
		}

		if ( isset( $insight['impact']['population'] ) && (int) $insight['impact']['population'] > 0 ) {
			return (int) $insight['impact']['population'];
		}

		$metrics = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
		foreach ( array( 'sessions', 'entries', 'starts', 'affected_sessions', 'pageviews' ) as $key ) {
			if ( isset( $metrics[ $key ] ) && is_numeric( $metrics[ $key ] ) && (int) $metrics[ $key ] > 0 ) {
				return (int) $metrics[ $key ];
			}
		}

		return 0;
	}

	/**
	 * Compute the immediately preceding window of equal length.
	 *
	 * @param string $from Range start (Y-m-d).
	 * @param string $to   Range end (Y-m-d).
	 * @return array
	 */
	private function build_compare_window( $from, $to ) {
		$empty = array(
			'from' => '',
			'to'   => '',
		);

		if ( '' === $from || '' === $to ) {
			return $empty;
		}

		$start = strtotime( $from . ' 00:00:00' );
		$end   = strtotime( $to . ' 00:00:00' );
		if ( ! $start || ! $end || $end < $start ) {
			return $empty;
		}

		$day_seconds = 86400;
		$days        = (int) floor( ( $end - $start ) / $day_seconds ) + 1;
		$compare_to  = $start - $day_seconds;

		return array(
			'from' => gmdate( 'Y-m-d', $compare_to - ( ( $days - 1 ) * $day_seconds ) ),
			'to'   => gmdate( 'Y-m-d', $compare_to ),
		);
	}

	/**
	 * Pick the first usable Y-m-d date from a candidate list.
	 *
	 * @param array $values Candidate values.
	 * @return string
	 */
	private function first_date( $values ) {
		foreach ( (array) $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$text = trim( (string) $value );
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $text, $matches ) ) {
				return $matches[1];
			}
		}

		return '';
	}

	/**
	 * Pick the first non-empty scalar from a candidate list.
	 *
	 * @param array $values Candidate values.
	 * @return string
	 */
	private function first_scalar( $values ) {
		foreach ( (array) $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$text = trim( (string) $value );
			if ( '' !== $text && '0' !== $text ) {
				return sanitize_text_field( $text );
			}
		}

		return '';
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
