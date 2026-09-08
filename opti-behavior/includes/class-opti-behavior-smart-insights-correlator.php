<?php
/**
 * Smart Insights Correlator Class
 *
 * Groups triggered signals that describe the same problem into one correlated
 * "story", runs deterministic diagnostic playbook probes against the available
 * data sources, and ranks the likely causes by measured share.
 *
 * The correlator lives in Free core so storage, scoring, and the privacy
 * boundary stay in one place. Deep probes (errors, forms, recordings, segments)
 * are supplied by Pro through the probe filter, exactly like the existing
 * provider/evidence extension points. When no probe is available the correlator
 * degrades to plain scope grouping, and when a scope holds a single signal with
 * no probe result the candidate is returned untouched so the classic insight
 * card path renders unchanged.
 *
 * @package opti-behavior
 * @since   1.3.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Correlator Class.
 *
 * @since 1.3.8
 */
class Opti_Behavior_Smart_Insights_Correlator {

	/**
	 * Correlation payload schema version.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Maximum ranked causes persisted per story.
	 */
	const MAX_CAUSES = 6;

	/**
	 * Maximum evidence sample references kept per probe.
	 */
	const MAX_SAMPLE_REFS = 25;

	/**
	 * Correlate a candidate insight list into stories.
	 *
	 * Returns the same candidate records, reordered so every story primary is
	 * emitted before its children, with the primary carrying a `correlation`
	 * block and each record carrying `story_role` / `story_scope_key` so the
	 * generator can resolve `parent_insight_id` after persistence.
	 *
	 * @param array $candidates Candidate records (`insight`, `metrics`, `baselines`).
	 * @param array $date_range Generation date range.
	 * @param array $args       Generation args.
	 * @return array
	 */
	public function correlate( $candidates, $date_range, $args = array() ) {
		if ( empty( $candidates ) || ! is_array( $candidates ) ) {
			return is_array( $candidates ) ? $candidates : array();
		}

		$candidates = array_values( $candidates );
		$date_range = is_array( $date_range ) ? $date_range : array();
		$args       = is_array( $args ) ? $args : array();

		$scopes = $this->group_candidates_by_scope( $candidates, $date_range );
		if ( empty( $scopes ) ) {
			return $candidates;
		}

		$playbooks = $this->get_playbooks(
			array(
				'date_range' => $date_range,
				'args'       => $args,
			)
		);

		foreach ( $scopes as $scope_key => $scope ) {
			$members = $scope['members'];
			if ( empty( $members ) ) {
				continue;
			}

			$primary_index = $this->select_primary_index( $candidates, $members );
			if ( null === $primary_index ) {
				continue;
			}

			$primary_insight = $candidates[ $primary_index ]['insight'];
			$playbook_key    = $this->match_playbook( $primary_insight, $playbooks );
			$playbook        = isset( $playbooks[ $playbook_key ] ) ? $playbooks[ $playbook_key ] : array();

			$scope_context = $this->build_scope_context( $scope_key, $scope, $candidates, $primary_index, $date_range, $args );

			$probe_results = $this->run_playbook_probes(
				$playbook,
				$playbook_key,
				$scope_context,
				$candidates,
				$members,
				$primary_index,
				$date_range,
				$args
			);

			$causes = $this->rank_causes( $probe_results );

			// Fallback: a lone signal with no measured cause stays a classic card.
			if ( count( $members ) < 2 && empty( $causes ) ) {
				continue;
			}

			$signal_summaries = $this->build_signal_summaries( $candidates, $members, $primary_index );

			$correlation = array(
				'version'          => self::SCHEMA_VERSION,
				'scope_key'        => $scope_key,
				'scope'            => $scope_context,
				'playbook'         => $playbook_key,
				'playbook_label'   => isset( $playbook['label'] ) ? (string) $playbook['label'] : '',
				'primary_signal'   => isset( $primary_insight['signal_id'] ) ? sanitize_key( $primary_insight['signal_id'] ) : '',
				'signal_count'     => count( $members ),
				'signals'          => $signal_summaries,
				'child_signal_ids' => $this->collect_child_signal_ids( $signal_summaries ),
				'causes'           => $causes,
				'cause_count'      => count( $causes ),
				'probes'           => $probe_results,
				'probes_run'       => count( $probe_results ),
				'probes_available' => count( $causes ),
				'correlated'       => count( $members ) > 1 || ! empty( $causes ),
				'generated_at'     => $this->now(),
			);

			/**
			 * Filter a completed correlation story before it is attached.
			 *
			 * @since 1.3.8
			 *
			 * @param array $correlation Correlation payload.
			 * @param array $context     Scope context.
			 * @param array $args        Generation args.
			 */
			$correlation = apply_filters( 'opti_behavior_smart_insights_correlation_story', $correlation, $scope_context, $args );
			if ( ! is_array( $correlation ) || empty( $correlation ) ) {
				continue;
			}

			$candidates = $this->attach_correlation_to_candidates( $candidates, $members, $primary_index, $scope_key, $correlation );
		}

		return $this->order_primaries_before_children( $candidates );
	}

	/**
	 * Get the diagnostic playbook registry.
	 *
	 * Playbooks are plain data so Pro (or a site) can register probes without
	 * Free needing to know about Pro tables.
	 *
	 * @param array $context Optional context (date range, args).
	 * @return array
	 */
	public function get_playbooks( $context = array() ) {
		$playbooks = array(
			'funnel_dropoff' => array(
				'label'    => __( 'Funnel drop-off diagnosis', 'opti-behavior' ),
				'priority' => 90,
				'match'    => array(
					'entity_types'    => array( 'funnel' ),
					'signal_patterns' => array( 'funnel_dropoff', 'dropoff', 'checkout_friction', 'checkout_to_purchase', 'cart_to_checkout', 'product_page_to_cart' ),
				),
				'probes'   => array( 'error_overlap', 'form_friction', 'interaction_friction', 'segment_skew', 'recording_sample' ),
			),
			'form_friction'  => array(
				'label'    => __( 'Form friction diagnosis', 'opti-behavior' ),
				'priority' => 80,
				'match'    => array(
					'entity_types'    => array( 'form' ),
					'signal_patterns' => array( 'form_abandonment', 'form_error_friction', 'field_level_friction' ),
				),
				'probes'   => array( 'form_friction', 'error_overlap', 'interaction_friction', 'segment_skew', 'recording_sample' ),
			),
			'error_impact'   => array(
				'label'    => __( 'Error impact diagnosis', 'opti-behavior' ),
				'priority' => 75,
				'match'    => array(
					'entity_types'    => array( 'error' ),
					'signal_patterns' => array( 'error_impact', 'error_friction' ),
				),
				'probes'   => array( 'error_overlap', 'interaction_friction', 'form_friction', 'segment_skew', 'recording_sample' ),
			),
			'conversion'     => array(
				'label'    => __( 'Conversion loss diagnosis', 'opti-behavior' ),
				'priority' => 70,
				'match'    => array(
					'categories'      => array( 'conversion' ),
					'signal_patterns' => array( 'conversion_drop', 'poor_conversion_rate', 'cta_low_performance', 'mobile_cta_click_rate', 'traffic_spike_without_conversion' ),
				),
				'probes'   => array( 'segment_skew', 'error_overlap', 'form_friction', 'interaction_friction', 'recording_sample' ),
			),
			'engagement'     => array(
				'label'    => __( 'Engagement loss diagnosis', 'opti-behavior' ),
				'priority' => 60,
				'match'    => array(
					'entity_types'    => array( 'page' ),
					'signal_patterns' => array( 'high_traffic_low_engagement', 'low_scroll_depth', 'high_exit_rate', 'bounce', 'quick_exit', 'visitor_confusion', 'engagement_decay', 'dead_or_rage_click', 'mobile_friction' ),
				),
				'probes'   => array( 'interaction_friction', 'error_overlap', 'form_friction', 'segment_skew', 'recording_sample' ),
			),
			'default'        => array(
				'label'    => __( 'General diagnosis', 'opti-behavior' ),
				'priority' => 0,
				'match'    => array(),
				'probes'   => array( 'error_overlap', 'interaction_friction', 'segment_skew' ),
			),
		);

		/**
		 * Filter the correlation playbook registry.
		 *
		 * Each playbook is `array( label, priority, match, probes )`. `match`
		 * supports `signal_ids`, `signal_patterns`, `entity_types`, and
		 * `categories`. Probes are executed in the declared order.
		 *
		 * @since 1.3.8
		 *
		 * @param array $playbooks Playbook registry.
		 * @param array $context   Optional context.
		 */
		$playbooks = apply_filters( 'opti_behavior_smart_insights_correlation_playbooks', $playbooks, is_array( $context ) ? $context : array() );

		return $this->normalize_playbooks( $playbooks );
	}

	/**
	 * Execute a single diagnostic probe.
	 *
	 * Free core never queries Pro-only evidence directly. It publishes a neutral
	 * unavailable result and lets registered probe providers fill it in.
	 *
	 * @param string $probe_id Probe identifier.
	 * @param array  $context  Probe context.
	 * @return array Normalized probe result.
	 */
	public function run_probe( $probe_id, $context ) {
		$probe_id = sanitize_key( $probe_id );
		if ( '' === $probe_id ) {
			return array();
		}

		$result = array(
			'probe'        => $probe_id,
			'available'    => false,
			'label'        => $this->get_probe_label( $probe_id ),
			'metric'       => $this->get_probe_metric( $probe_id ),
			'metric_label' => '',
			'value'        => null,
			'sample_size'  => 0,
			'share'        => null,
			'sample_refs'  => array(),
			'source'       => 'core',
			'reason'       => 'probe_provider_unavailable',
		);

		try {
			/**
			 * Filter one correlation probe result.
			 *
			 * Providers return `array( available, metric, share, sample_refs )`
			 * at minimum. Unavailable sources must return `available => false`
			 * so the correlator degrades instead of inventing a cause.
			 *
			 * @since 1.3.8
			 *
			 * @param array  $result   Default probe result.
			 * @param string $probe_id Probe identifier.
			 * @param array  $context  Probe context.
			 */
			$result = apply_filters( 'opti_behavior_smart_insights_correlation_probe_result', $result, $probe_id, is_array( $context ) ? $context : array() );
		} catch ( Exception $e ) {
			return array(
				'probe'     => $probe_id,
				'available' => false,
				'label'     => $this->get_probe_label( $probe_id ),
				'reason'    => 'probe_error',
			);
		}

		return $this->normalize_probe_result( $result, $probe_id );
	}

	/**
	 * Build the scope key for a single insight.
	 *
	 * @param array $insight    Insight payload.
	 * @param array $date_range Date range.
	 * @return string
	 */
	public function build_scope_key( $insight, $date_range = array() ) {
		$identity = $this->build_scope_identity( $insight );

		return $this->build_scope_key_from_alias( $identity['primary_alias'], $date_range );
	}

	/**
	 * Group candidates into correlation scopes.
	 *
	 * Page identity aliases (page id and normalized URL) are merged so a funnel
	 * step referencing a URL and a page signal referencing a post ID collapse
	 * into the same story.
	 *
	 * @param array $candidates Candidate records.
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function group_candidates_by_scope( $candidates, $date_range ) {
		$alias_to_scope = array();
		$scopes         = array();

		foreach ( $candidates as $index => $candidate ) {
			if ( empty( $candidate['insight'] ) || ! is_array( $candidate['insight'] ) ) {
				continue;
			}

			$identity = $this->build_scope_identity( $candidate['insight'] );
			$aliases  = array();
			foreach ( $identity['aliases'] as $alias ) {
				$aliases[] = $this->build_scope_key_from_alias( $alias, $date_range );
			}
			$aliases = array_values( array_unique( array_filter( $aliases ) ) );
			if ( empty( $aliases ) ) {
				continue;
			}

			$scope_key = '';
			foreach ( $aliases as $alias ) {
				if ( isset( $alias_to_scope[ $alias ] ) ) {
					$scope_key = $alias_to_scope[ $alias ];
					break;
				}
			}

			if ( '' === $scope_key ) {
				$scope_key            = $this->build_scope_key_from_alias( $identity['primary_alias'], $date_range );
				$scopes[ $scope_key ] = array(
					'entity_type'   => $identity['entity_type'],
					'entity_id'     => $identity['entity_id'],
					'page_id'       => $identity['page_id'],
					'page_url'      => $identity['page_url'],
					'page_url_full' => $identity['page_url_full'],
					'aliases'       => array(),
					'members'       => array(),
				);
			}

			foreach ( $aliases as $alias ) {
				$alias_to_scope[ $alias ]          = $scope_key;
				$scopes[ $scope_key ]['aliases'][] = $alias;
			}

			if ( empty( $scopes[ $scope_key ]['page_id'] ) && ! empty( $identity['page_id'] ) ) {
				$scopes[ $scope_key ]['page_id'] = $identity['page_id'];
			}
			if ( empty( $scopes[ $scope_key ]['page_url'] ) && ! empty( $identity['page_url'] ) ) {
				$scopes[ $scope_key ]['page_url'] = $identity['page_url'];
			}
			if ( empty( $scopes[ $scope_key ]['page_url_full'] ) && ! empty( $identity['page_url_full'] ) ) {
				$scopes[ $scope_key ]['page_url_full'] = $identity['page_url_full'];
			}

			$scopes[ $scope_key ]['members'][] = (int) $index;
		}

		foreach ( $scopes as $key => $scope ) {
			$scopes[ $key ]['aliases'] = array_values( array_unique( $scope['aliases'] ) );
			$scopes[ $key ]['members'] = array_values( array_unique( $scope['members'] ) );
		}

		return $scopes;
	}

	/**
	 * Derive scope identity aliases for one insight.
	 *
	 * @param array $insight Insight payload.
	 * @return array
	 */
	private function build_scope_identity( $insight ) {
		$insight     = is_array( $insight ) ? $insight : array();
		$metrics     = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
		$context     = isset( $metrics['entity_context'] ) && is_array( $metrics['entity_context'] ) ? $metrics['entity_context'] : array();
		$entity_type = isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '';
		$entity_id   = isset( $insight['entity_id'] ) && is_scalar( $insight['entity_id'] ) ? (string) $insight['entity_id'] : '';

		$page_id = 0;
		foreach ( array( $metrics, $context ) as $bag ) {
			if ( isset( $bag['page_id'] ) && is_numeric( $bag['page_id'] ) && absint( $bag['page_id'] ) > 0 ) {
				$page_id = absint( $bag['page_id'] );
				break;
			}
		}
		if ( 0 === $page_id && 'page' === $entity_type && is_numeric( $entity_id ) ) {
			$page_id = absint( $entity_id );
		}

		$page_url      = '';
		$page_url_full = '';
		foreach ( array( isset( $metrics['page_url'] ) ? $metrics['page_url'] : '', isset( $context['view_url'] ) ? $context['view_url'] : '', $entity_id ) as $candidate_url ) {
			$normalized = $this->normalize_url( $candidate_url );
			if ( '' !== $normalized ) {
				$page_url      = $normalized;
				$page_url_full = is_scalar( $candidate_url ) ? trim( (string) $candidate_url ) : '';
				break;
			}
		}

		$aliases = array();
		if ( $page_id > 0 ) {
			$aliases[] = 'page_id:' . $page_id;
		}
		if ( '' !== $page_url ) {
			$aliases[] = 'page_url:' . $page_url;
		}

		if ( empty( $aliases ) ) {
			$aliases[] = 'entity:' . $entity_type . ':' . strtolower( trim( $entity_id ) );
		}

		return array(
			'entity_type'   => $entity_type,
			'entity_id'     => $entity_id,
			'page_id'       => $page_id,
			'page_url'      => $page_url,
			'page_url_full' => $page_url_full,
			'aliases'       => $aliases,
			'primary_alias' => $aliases[0],
		);
	}

	/**
	 * Compose a date-bounded scope key.
	 *
	 * @param string $alias      Scope alias.
	 * @param array  $date_range Date range.
	 * @return string
	 */
	private function build_scope_key_from_alias( $alias, $date_range ) {
		$alias = is_scalar( $alias ) ? trim( (string) $alias ) : '';
		if ( '' === $alias ) {
			return '';
		}

		$date_range = is_array( $date_range ) ? $date_range : array();
		$from       = isset( $date_range['from'] ) ? (string) $date_range['from'] : '';
		$to         = isset( $date_range['to'] ) ? (string) $date_range['to'] : '';

		return $alias . '|' . $from . '|' . $to;
	}

	/**
	 * Normalize a URL for cross-source page matching.
	 *
	 * @param mixed $url Candidate URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$url = is_scalar( $url ) ? trim( (string) $url ) : '';
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		$host = preg_replace( '/^www\./', '', $host );
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		$path = '' === $path ? '/' : $path;

		return $host . $path;
	}

	/**
	 * Select the story primary within a scope.
	 *
	 * @param array $candidates Candidate records.
	 * @param array $members    Member indexes.
	 * @return int|null
	 */
	private function select_primary_index( $candidates, $members ) {
		$primary_index = null;
		$best_priority = -1;

		foreach ( $members as $index ) {
			if ( ! isset( $candidates[ $index ]['insight'] ) ) {
				continue;
			}

			$priority = $this->get_priority_score( $candidates[ $index ]['insight'] );
			if ( $priority > $best_priority ) {
				$best_priority = $priority;
				$primary_index = (int) $index;
			}
		}

		return $primary_index;
	}

	/**
	 * Read a priority score from an insight payload.
	 *
	 * @param array $insight Insight payload.
	 * @return int
	 */
	private function get_priority_score( $insight ) {
		return isset( $insight['scores']['priority_score'] ) ? (int) $insight['scores']['priority_score'] : 0;
	}

	/**
	 * Normalize and sort the playbook registry.
	 *
	 * @param array $playbooks Raw playbooks.
	 * @return array
	 */
	private function normalize_playbooks( $playbooks ) {
		$normalized = array();

		foreach ( (array) $playbooks as $key => $playbook ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! is_array( $playbook ) ) {
				continue;
			}

			$probes = array();
			foreach ( isset( $playbook['probes'] ) ? (array) $playbook['probes'] : array() as $probe_id ) {
				$probe_id = sanitize_key( $probe_id );
				if ( '' !== $probe_id ) {
					$probes[] = $probe_id;
				}
			}

			$match = isset( $playbook['match'] ) && is_array( $playbook['match'] ) ? $playbook['match'] : array();

			$normalized[ $key ] = array(
				'label'    => isset( $playbook['label'] ) ? (string) $playbook['label'] : '',
				'priority' => isset( $playbook['priority'] ) ? (int) $playbook['priority'] : 0,
				'match'    => array(
					'signal_ids'      => $this->sanitize_key_list( isset( $match['signal_ids'] ) ? $match['signal_ids'] : array() ),
					'signal_patterns' => $this->sanitize_key_list( isset( $match['signal_patterns'] ) ? $match['signal_patterns'] : array() ),
					'entity_types'    => $this->sanitize_key_list( isset( $match['entity_types'] ) ? $match['entity_types'] : array() ),
					'categories'      => $this->sanitize_key_list( isset( $match['categories'] ) ? $match['categories'] : array() ),
				),
				'probes'   => array_values( array_unique( $probes ) ),
			);
		}

		if ( empty( $normalized['default'] ) ) {
			$normalized['default'] = array(
				'label'    => __( 'General diagnosis', 'opti-behavior' ),
				'priority' => 0,
				'match'    => array(
					'signal_ids'      => array(),
					'signal_patterns' => array(),
					'entity_types'    => array(),
					'categories'      => array(),
				),
				'probes'   => array( 'error_overlap', 'interaction_friction', 'segment_skew' ),
			);
		}

		uasort(
			$normalized,
			function ( $left, $right ) {
				return (int) $right['priority'] <=> (int) $left['priority'];
			}
		);

		return $normalized;
	}

	/**
	 * Sanitize a list of keys.
	 *
	 * @param mixed $values Raw values.
	 * @return array
	 */
	private function sanitize_key_list( $values ) {
		$list = array();
		foreach ( (array) $values as $value ) {
			$value = sanitize_key( $value );
			if ( '' !== $value ) {
				$list[] = $value;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Match an insight to a playbook key.
	 *
	 * @param array $insight   Primary insight.
	 * @param array $playbooks Normalized playbooks.
	 * @return string
	 */
	private function match_playbook( $insight, $playbooks ) {
		$signal_id   = isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '';
		$entity_type = isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '';
		$category    = isset( $insight['category'] ) ? sanitize_key( $insight['category'] ) : '';

		foreach ( $playbooks as $key => $playbook ) {
			if ( 'default' === $key ) {
				continue;
			}

			$match = $playbook['match'];

			if ( ! empty( $match['signal_ids'] ) && in_array( $signal_id, $match['signal_ids'], true ) ) {
				return $key;
			}

			if ( '' !== $signal_id && ! empty( $match['signal_patterns'] ) ) {
				foreach ( $match['signal_patterns'] as $pattern ) {
					if ( '' !== $pattern && false !== strpos( $signal_id, $pattern ) ) {
						return $key;
					}
				}
			}

			if ( ! empty( $match['entity_types'] ) && in_array( $entity_type, $match['entity_types'], true ) ) {
				return $key;
			}

			if ( ! empty( $match['categories'] ) && '' !== $category && in_array( $category, $match['categories'], true ) ) {
				return $key;
			}
		}

		return 'default';
	}

	/**
	 * Build the shared probe scope context.
	 *
	 * @param string $scope_key     Scope key.
	 * @param array  $scope         Scope record.
	 * @param array  $candidates    Candidate records.
	 * @param int    $primary_index Primary candidate index.
	 * @param array  $date_range    Date range.
	 * @param array  $args          Generation args.
	 * @return array
	 */
	private function build_scope_context( $scope_key, $scope, $candidates, $primary_index, $date_range, $args ) {
		$primary_insight = $candidates[ $primary_index ]['insight'];
		$primary_metrics = isset( $candidates[ $primary_index ]['metrics'] ) && is_array( $candidates[ $primary_index ]['metrics'] ) ? $candidates[ $primary_index ]['metrics'] : array();

		return array(
			'key'           => $scope_key,
			'entity_type'   => isset( $primary_insight['entity_type'] ) ? sanitize_key( $primary_insight['entity_type'] ) : '',
			'entity_id'     => isset( $primary_insight['entity_id'] ) && is_scalar( $primary_insight['entity_id'] ) ? (string) $primary_insight['entity_id'] : '',
			'entity_label'  => isset( $primary_insight['entity_label'] ) ? sanitize_text_field( (string) $primary_insight['entity_label'] ) : '',
			'page_id'       => isset( $scope['page_id'] ) ? absint( $scope['page_id'] ) : 0,
			'page_url'      => isset( $scope['page_url'] ) ? (string) $scope['page_url'] : '',
			'page_url_full' => isset( $scope['page_url_full'] ) ? (string) $scope['page_url_full'] : '',
			'sessions'      => $this->resolve_scope_sessions( $primary_insight, $primary_metrics ),
			'date_from'     => isset( $date_range['from'] ) ? (string) $date_range['from'] : '',
			'date_to'       => isset( $date_range['to'] ) ? (string) $date_range['to'] : '',
			'exclude_spam'  => array_key_exists( 'exclude_spam', $args ) ? $args['exclude_spam'] : null,
		);
	}

	/**
	 * Resolve the affected-population denominator for share calculations.
	 *
	 * @param array $insight Primary insight.
	 * @param array $metrics Raw candidate metric row.
	 * @return int
	 */
	private function resolve_scope_sessions( $insight, $metrics ) {
		$insight_metrics = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();

		foreach ( array( 'sessions', 'entries', 'starts', 'affected_sessions', 'pageviews' ) as $key ) {
			foreach ( array( $insight_metrics, $metrics ) as $bag ) {
				if ( isset( $bag[ $key ] ) && is_numeric( $bag[ $key ] ) && (int) $bag[ $key ] > 0 ) {
					return (int) $bag[ $key ];
				}
			}
		}

		return 0;
	}

	/**
	 * Run every probe declared by a playbook.
	 *
	 * @param array  $playbook      Playbook definition.
	 * @param string $playbook_key  Playbook key.
	 * @param array  $scope_context Scope context.
	 * @param array  $candidates    Candidate records.
	 * @param array  $members       Member indexes.
	 * @param int    $primary_index Primary index.
	 * @param array  $date_range    Date range.
	 * @param array  $args          Generation args.
	 * @return array
	 */
	private function run_playbook_probes( $playbook, $playbook_key, $scope_context, $candidates, $members, $primary_index, $date_range, $args ) {
		$probes = isset( $playbook['probes'] ) ? (array) $playbook['probes'] : array();
		if ( empty( $probes ) ) {
			return array();
		}

		$results = array();
		foreach ( $probes as $probe_id ) {
			$context = array(
				'probe_id'   => $probe_id,
				'playbook'   => $playbook_key,
				'scope'      => $scope_context,
				'primary'    => $candidates[ $primary_index ]['insight'],
				'signals'    => $this->build_signal_summaries( $candidates, $members, $primary_index ),
				'date_range' => $date_range,
				'args'       => $args,
			);

			$result = $this->run_probe( $probe_id, $context );
			if ( empty( $result ) ) {
				continue;
			}

			$results[ $probe_id ] = $result;
		}

		return $results;
	}

	/**
	 * Rank available probe results into likely causes by measured share.
	 *
	 * @param array $probe_results Probe results keyed by probe id.
	 * @return array
	 */
	private function rank_causes( $probe_results ) {
		$causes = array();

		foreach ( (array) $probe_results as $probe_id => $result ) {
			if ( empty( $result['available'] ) ) {
				continue;
			}

			$share = isset( $result['share'] ) && null !== $result['share'] ? (float) $result['share'] : null;
			if ( null === $share || $share <= 0 ) {
				continue;
			}

			$causes[] = array(
				'probe'        => sanitize_key( $probe_id ),
				'label'        => isset( $result['label'] ) ? (string) $result['label'] : $this->get_probe_label( $probe_id ),
				'metric'       => isset( $result['metric'] ) ? (string) $result['metric'] : '',
				'metric_label' => isset( $result['metric_label'] ) ? (string) $result['metric_label'] : '',
				'share'        => round( $share, 4 ),
				'share_pct'    => round( $share * 100, 1 ),
				'value'        => isset( $result['value'] ) ? $result['value'] : null,
				'sample_size'  => isset( $result['sample_size'] ) ? (int) $result['sample_size'] : 0,
				'ref_count'    => isset( $result['sample_refs'] ) ? count( (array) $result['sample_refs'] ) : 0,
				'source'       => isset( $result['source'] ) ? sanitize_key( $result['source'] ) : 'core',
			);
		}

		usort(
			$causes,
			function ( $left, $right ) {
				if ( $left['share'] === $right['share'] ) {
					return strcmp( $left['probe'], $right['probe'] );
				}

				return $right['share'] <=> $left['share'];
			}
		);

		$causes = array_slice( $causes, 0, self::MAX_CAUSES );

		foreach ( $causes as $index => $cause ) {
			$causes[ $index ]['rank'] = $index + 1;
		}

		return $causes;
	}

	/**
	 * Build compact signal summaries for the story payload.
	 *
	 * @param array $candidates    Candidate records.
	 * @param array $members       Member indexes.
	 * @param int   $primary_index Primary index.
	 * @return array
	 */
	private function build_signal_summaries( $candidates, $members, $primary_index ) {
		$summaries = array();

		foreach ( $members as $index ) {
			if ( empty( $candidates[ $index ]['insight'] ) || ! is_array( $candidates[ $index ]['insight'] ) ) {
				continue;
			}

			$insight     = $candidates[ $index ]['insight'];
			$summaries[] = array(
				'signal_id'      => isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '',
				'signal_name'    => isset( $insight['signal_name'] ) ? sanitize_text_field( (string) $insight['signal_name'] ) : '',
				'entity_type'    => isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '',
				'entity_id'      => isset( $insight['entity_id'] ) && is_scalar( $insight['entity_id'] ) ? (string) $insight['entity_id'] : '',
				'entity_label'   => isset( $insight['entity_label'] ) ? sanitize_text_field( (string) $insight['entity_label'] ) : '',
				'category'       => isset( $insight['category'] ) ? sanitize_key( $insight['category'] ) : '',
				'priority_score' => $this->get_priority_score( $insight ),
				'role'           => (int) $index === (int) $primary_index ? 'primary' : 'child',
			);
		}

		return $summaries;
	}

	/**
	 * Collect child signal IDs from signal summaries.
	 *
	 * @param array $summaries Signal summaries.
	 * @return array
	 */
	private function collect_child_signal_ids( $summaries ) {
		$ids = array();
		foreach ( (array) $summaries as $summary ) {
			if ( 'child' === $summary['role'] && '' !== $summary['signal_id'] ) {
				$ids[] = $summary['signal_id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Attach story markers to the candidate records of one scope.
	 *
	 * @param array  $candidates    Candidate records.
	 * @param array  $members       Member indexes.
	 * @param int    $primary_index Primary index.
	 * @param string $scope_key     Scope key.
	 * @param array  $correlation   Correlation payload.
	 * @return array
	 */
	private function attach_correlation_to_candidates( $candidates, $members, $primary_index, $scope_key, $correlation ) {
		foreach ( $members as $index ) {
			if ( empty( $candidates[ $index ]['insight'] ) || ! is_array( $candidates[ $index ]['insight'] ) ) {
				continue;
			}

			$is_primary = (int) $index === (int) $primary_index;

			$candidates[ $index ]['story_role']      = $is_primary ? 'primary' : 'child';
			$candidates[ $index ]['story_scope_key'] = $scope_key;

			$insight                                       = $candidates[ $index ]['insight'];
			$insight['detection']                          = isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array();
			$insight['detection']['correlation_scope_key'] = $scope_key;
			$insight['detection']['correlation_role']      = $is_primary ? 'primary' : 'child';
			$insight['detection']['correlation_playbook']  = isset( $correlation['playbook'] ) ? $correlation['playbook'] : '';

			if ( $is_primary ) {
				$insight['correlation']                          = $correlation;
				$insight['detection']['correlated_signal_count'] = isset( $correlation['signal_count'] ) ? (int) $correlation['signal_count'] : 1;
				$insight['detection']['correlated_cause_count']  = isset( $correlation['cause_count'] ) ? (int) $correlation['cause_count'] : 0;
			} else {
				$insight['detection']['correlation_primary_signal'] = isset( $correlation['primary_signal'] ) ? $correlation['primary_signal'] : '';
			}

			$candidates[ $index ]['insight'] = $insight;
		}

		return $candidates;
	}

	/**
	 * Reorder candidates so each story primary precedes its children.
	 *
	 * The generator resolves `parent_insight_id` from the stored primary ID, so
	 * the primary must be persisted first.
	 *
	 * @param array $candidates Candidate records.
	 * @return array
	 */
	private function order_primaries_before_children( $candidates ) {
		$primary_position = array();
		foreach ( $candidates as $index => $candidate ) {
			if ( isset( $candidate['story_role'], $candidate['story_scope_key'] ) && 'primary' === $candidate['story_role'] ) {
				$primary_position[ $candidate['story_scope_key'] ] = (int) $index;
			}
		}

		$ordered = array();
		foreach ( $candidates as $index => $candidate ) {
			$scope_key = isset( $candidate['story_scope_key'] ) ? $candidate['story_scope_key'] : '';
			$anchor    = isset( $primary_position[ $scope_key ] ) ? $primary_position[ $scope_key ] : (int) $index;
			$role_rank = isset( $candidate['story_role'] ) && 'child' === $candidate['story_role'] ? 1 : 0;

			$ordered[] = array(
				'anchor'    => $anchor,
				'role_rank' => $role_rank,
				'index'     => (int) $index,
				'candidate' => $candidate,
			);
		}

		usort(
			$ordered,
			function ( $left, $right ) {
				if ( $left['anchor'] !== $right['anchor'] ) {
					return $left['anchor'] <=> $right['anchor'];
				}
				if ( $left['role_rank'] !== $right['role_rank'] ) {
					return $left['role_rank'] <=> $right['role_rank'];
				}

				return $left['index'] <=> $right['index'];
			}
		);

		return array_values( wp_list_pluck( $ordered, 'candidate' ) );
	}

	/**
	 * Normalize a probe result returned by a provider.
	 *
	 * @param mixed  $result   Raw result.
	 * @param string $probe_id Probe identifier.
	 * @return array
	 */
	private function normalize_probe_result( $result, $probe_id ) {
		if ( ! is_array( $result ) ) {
			return array(
				'probe'     => $probe_id,
				'available' => false,
				'label'     => $this->get_probe_label( $probe_id ),
				'reason'    => 'probe_invalid_result',
			);
		}

		$available = ! empty( $result['available'] );
		$share     = null;
		if ( $available && isset( $result['share'] ) && is_numeric( $result['share'] ) ) {
			$share = (float) $result['share'];
			if ( $share > 1 && $share <= 100 ) {
				// Tolerate providers that return percentages.
				$share = $share / 100;
			}
			$share = max( 0, min( 1, $share ) );
		}

		$normalized = array(
			'probe'        => $probe_id,
			'available'    => $available,
			'label'        => isset( $result['label'] ) && '' !== $result['label'] ? sanitize_text_field( (string) $result['label'] ) : $this->get_probe_label( $probe_id ),
			'metric'       => isset( $result['metric'] ) ? sanitize_key( $result['metric'] ) : $this->get_probe_metric( $probe_id ),
			'metric_label' => isset( $result['metric_label'] ) ? sanitize_text_field( (string) $result['metric_label'] ) : '',
			'value'        => $this->normalize_probe_value( isset( $result['value'] ) ? $result['value'] : null ),
			'sample_size'  => isset( $result['sample_size'] ) && is_numeric( $result['sample_size'] ) ? absint( $result['sample_size'] ) : 0,
			'share'        => null === $share ? null : round( $share, 4 ),
			'sample_refs'  => $this->normalize_sample_refs( isset( $result['sample_refs'] ) ? $result['sample_refs'] : array() ),
			'source'       => isset( $result['source'] ) ? sanitize_key( $result['source'] ) : 'core',
		);

		if ( ! $available ) {
			$normalized['reason'] = isset( $result['reason'] ) ? sanitize_key( $result['reason'] ) : 'probe_provider_unavailable';
		}

		if ( isset( $result['details'] ) && is_array( $result['details'] ) ) {
			$normalized['details'] = $result['details'];
		}

		return $normalized;
	}

	/**
	 * Normalize a probe's absolute measured value.
	 *
	 * Counts stay integers; ratios and durations keep four decimals.
	 *
	 * @param mixed $value Raw value.
	 * @return int|float|null
	 */
	private function normalize_probe_value( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$float = (float) $value;

		return ( (float) (int) $float === $float ) ? (int) $float : round( $float, 4 );
	}

	/**
	 * Normalize typed evidence sample references.
	 *
	 * @param mixed $refs Raw refs.
	 * @return array
	 */
	private function normalize_sample_refs( $refs ) {
		if ( ! is_array( $refs ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) || empty( $ref['type'] ) ) {
				continue;
			}

			$url_args = array();
			foreach ( isset( $ref['url_args'] ) && is_array( $ref['url_args'] ) ? $ref['url_args'] : array() as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$url_args[ sanitize_key( $key ) ] = is_numeric( $value ) ? $value + 0 : sanitize_text_field( (string) $value );
				}
			}

			$entry = array(
				'type'     => sanitize_key( $ref['type'] ),
				'id'       => isset( $ref['id'] ) && is_scalar( $ref['id'] ) ? ( is_numeric( $ref['id'] ) ? (int) $ref['id'] : sanitize_text_field( (string) $ref['id'] ) ) : '',
				'label'    => isset( $ref['label'] ) ? sanitize_text_field( (string) $ref['label'] ) : '',
				'url_args' => $url_args,
			);

			if ( isset( $ref['share'] ) && is_numeric( $ref['share'] ) ) {
				$entry['share'] = round( (float) $ref['share'], 4 );
			}

			$normalized[] = $entry;

			if ( count( $normalized ) >= self::MAX_SAMPLE_REFS ) {
				break;
			}
		}

		return $normalized;
	}

	/**
	 * Default human label per probe.
	 *
	 * @param string $probe_id Probe identifier.
	 * @return string
	 */
	private function get_probe_label( $probe_id ) {
		$labels = array(
			'error_overlap'        => __( 'JavaScript or server errors', 'opti-behavior' ),
			'form_friction'        => __( 'Form friction', 'opti-behavior' ),
			'interaction_friction' => __( 'Rage or dead clicks', 'opti-behavior' ),
			'segment_skew'         => __( 'Concentrated in one visitor segment', 'opti-behavior' ),
			'recording_sample'     => __( 'Session recordings of affected visits', 'opti-behavior' ),
		);

		return isset( $labels[ $probe_id ] ) ? $labels[ $probe_id ] : $probe_id;
	}

	/**
	 * Default machine metric key per probe.
	 *
	 * @param string $probe_id Probe identifier.
	 * @return string
	 */
	private function get_probe_metric( $probe_id ) {
		$metrics = array(
			'error_overlap'        => 'affected_sessions_with_errors',
			'form_friction'        => 'affected_sessions_with_form_abandonment',
			'interaction_friction' => 'affected_sessions_with_click_friction',
			'segment_skew'         => 'top_segment_share_of_affected_sessions',
			'recording_sample'     => 'affected_sessions_with_recording',
		);

		return isset( $metrics[ $probe_id ] ) ? $metrics[ $probe_id ] : $probe_id;
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
