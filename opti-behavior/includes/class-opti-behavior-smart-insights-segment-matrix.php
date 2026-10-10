<?php
/**
 * Smart Insights segment matrix ("Where is the problem?").
 *
 * Answers the second story question for one insight: which slice of the
 * affected population carries the problem, measured with the insight's own
 * primary metric instead of a share of sessions.
 *
 * The matrix is computed on demand from the Free behaviour tables
 * (`pageviews INNER JOIN sessions LEFT JOIN visitors`) for the insight's own
 * scope (page + period) and is always spam-scoped with the same policy the rest
 * of Smart Insights uses.
 *
 * Only aggregates leave this class: per-segment distinct session counts, the
 * metric value of the segment, the metric value of everyone else, and the
 * significance of the gap. No visitor identifiers, IPs, URLs beyond the scope
 * the insight already carries, or raw rows are returned.
 *
 * Free edition (OPT-403, WordPress.org guideline 5): this class computes the
 * scope totals, the traffic-mix comparison and the daily curve. The
 * per-audience breakdown (outliers, pulses, combinations, sparklines, the
 * one-audience curve) lives in Opti-Behavior Pro, which subclasses this
 * builder and fills build_segment_breakdown() / resolve_segment_filter().
 *
 * @package opti-behavior
 * @since   1.3.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders live inside the pre-built scope WHERE fragment; values are bound through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are hardcoded with $wpdb->prefix.

if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Segment_Matrix' ) ) {

	/**
	 * Segment matrix builder for a single insight scope.
	 *
	 * @since 1.3.9
	 */
	class Opti_Behavior_Smart_Insights_Segment_Matrix {

		/**
		 * Payload schema version.
		 *
		 * Bumped to 2 when the matrix moved off the session-recordings table onto
		 * pageviews/sessions/visitors and started returning per-bucket metrics.
		 * Bumped to 3 for combination dimensions, per-bucket split-half trends and
		 * the explicit insufficient-data fields.
		 * Bumped to 4 when the per-audience breakdown moved to Pro (OPT-403):
		 * a v3 transient holds the full breakdown and must never reach Free.
		 */
		const SCHEMA_VERSION = 4;

		/**
		 * Maximum buckets returned per dimension (hard SQL LIMIT).
		 */
		const MAX_SEGMENTS = 8;

		/**
		 * Minimum sessions in the whole scope before any split is attempted.
		 */
		const MIN_SCOPE_SESSIONS = 30;

		/**
		 * Minimum sessions in one bucket before it can be an outlier.
		 */
		const MIN_BUCKET_SESSIONS = 30;

		/**
		 * Minimum share of the scope a bucket must hold to be an outlier.
		 */
		const MIN_BUCKET_SHARE = 0.10;

		/**
		 * Minimum relative deviation from the complement (15%).
		 */
		const MIN_RELATIVE_DELTA = 0.15;

		/**
		 * Two-sided 95% significance threshold for z / Welch t.
		 */
		const SIGNIFICANCE_Z = 1.96;

		/**
		 * Minimum sessions in one half of the period before a trend is stated.
		 */
		const MIN_TREND_HALF_SESSIONS = 10;

		/**
		 * Minimum absolute share movement (0-1) before a traffic-mix shift is flagged.
		 *
		 * Ten points of share is the smallest move an agency can act on; below that
		 * the "your audience changed" sentence is noise dressed as a finding.
		 */
		const MIN_MIX_SHARE_DELTA = 0.10;

		/**
		 * Minimum relative growth of a bucket's share before it counts as a shift.
		 */
		const MIN_MIX_RELATIVE_GROWTH = 0.50;

		/**
		 * Hard cap on traffic-mix comparison queries per matrix build.
		 */
		const MAX_MIX_DIMENSIONS = 3;

		/**
		 * Transient key prefix.
		 */
		const CACHE_PREFIX = 'opti_behavior_si_seg_';

		/**
		 * Option holding the cache salt bumped by "Refresh insights".
		 */
		const CACHE_SALT_OPTION = 'opti_behavior_smart_insights_segment_cache_salt';

		/**
		 * Transient lifetime.
		 */
		const CACHE_TTL = 21600; // 6 * HOUR_IN_SECONDS.

		/**
		 * Cached column existence lookups.
		 *
		 * @var array
		 */
		protected $column_cache = array();

		/**
		 * Cached table existence lookups.
		 *
		 * @var array
		 */
		protected $table_cache = array();

		/**
		 * Whether the last get_matrix() call was answered from cache.
		 *
		 * @var bool
		 */
		protected $served_from_cache = false;

		/**
		 * Build the segment matrix for one insight.
		 *
		 * Never throws: any failure degrades to an `unavailable()` payload so the
		 * modal renders a reason instead of an error.
		 *
		 * @since 1.3.9
		 *
		 * @param array $insight Decoded insight row.
		 * @param array $args    Optional args: exclude_spam, skip_cache.
		 * @return array Matrix payload (always an array, `available` tells the caller).
		 */
		public function get_matrix( $insight, $args = array() ) {
			$insight = is_array( $insight ) ? $insight : array();
			$args    = is_array( $args ) ? $args : array();

			$exclude_spam            = $this->resolve_exclude_spam( $args );
			$scope                   = $this->resolve_scope( $insight );
			$this->served_from_cache = false;

			try {
				$matrix = $this->build_matrix( $insight, $args, $scope, $exclude_spam );
			} catch ( Exception $e ) {
				$matrix = $this->unavailable( 'matrix_error', $scope, $exclude_spam );
			} catch ( Error $e ) {
				$matrix = $this->unavailable( 'matrix_error', $scope, $exclude_spam );
			}

			/**
			 * Filter the computed segment matrix before it is shaped for the viewer.
			 *
			 * @since 1.3.9
			 *
			 * @param array $matrix  Matrix payload.
			 * @param array $insight Insight the matrix was built for.
			 * @param array $args    Request args.
			 */
			return apply_filters( 'opti_behavior_smart_insights_segment_matrix', $matrix, $insight, $args );
		}

		/**
		 * Whether the last get_matrix() call was answered from a transient.
		 *
		 * Exposed so acceptance checks (and Query Monitor comparisons) can prove
		 * the second open of a modal costs no queries.
		 *
		 * @since 1.4.0
		 * @return bool
		 */
		public function was_served_from_cache() {
			return $this->served_from_cache;
		}

		/**
		 * Invalidate every cached matrix and time series.
		 *
		 * Called by the "Refresh insights" handler. Bumps a salt instead of
		 * deleting keys so no prefix scan over the options table is needed.
		 *
		 * @since 1.4.0
		 * @return int The new salt.
		 */
		public static function flush_cache() {
			$salt = (int) get_option( self::CACHE_SALT_OPTION, 0 ) + 1;
			update_option( self::CACHE_SALT_OPTION, $salt, false );

			return $salt;
		}

		/**
		 * Current cache salt.
		 *
		 * @since 1.4.0
		 * @return int
		 */
		public static function get_cache_salt() {
			return (int) get_option( self::CACHE_SALT_OPTION, 0 );
		}

		/**
		 * Build the matrix (uncaught failures are handled by get_matrix()).
		 *
		 * @param array $insight      Insight payload.
		 * @param array $args         Request args.
		 * @param array $scope        Resolved scope.
		 * @param bool  $exclude_spam Spam policy.
		 * @return array
		 */
		protected function build_matrix( $insight, $args, $scope, $exclude_spam ) {
			global $wpdb;

			// Funnel and form insights are step-scoped, not page-scoped: splitting
			// their population by device would answer a question about the page the
			// step happens to live on, not about the step.
			if ( in_array( $scope['entity_type'], array( 'funnel', 'form' ), true ) ) {
				return $this->unavailable( 'scope_not_page', $scope, $exclude_spam );
			}

			if ( empty( $scope['page_id'] ) && '' === $scope['page_url'] ) {
				return $this->unavailable( 'scope_not_addressable', $scope, $exclude_spam );
			}

			$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';
			$sessions_table  = $wpdb->prefix . 'optibehavior_sessions';

			if ( ! $this->table_exists( $pageviews_table ) || ! $this->table_exists( $sessions_table ) ) {
				return $this->unavailable( 'metrics_tables_missing', $scope, $exclude_spam );
			}

			$cache_key = $this->get_cache_key( $insight, $scope, $exclude_spam );
			if ( empty( $args['skip_cache'] ) && '' !== $cache_key ) {
				$cached = get_transient( $cache_key );
				if ( is_array( $cached ) && isset( $cached['version'] ) && self::SCHEMA_VERSION === (int) $cached['version'] ) {
					$this->served_from_cache = true;
					return $cached;
				}
			}

			$where = $this->build_scope_where( $scope, $exclude_spam );
			if ( '' === $where['sql'] ) {
				return $this->unavailable( 'scope_not_addressable', $scope, $exclude_spam );
			}

			$primary_metric = $this->resolve_primary_metric( $insight );
			$metric_def     = $this->get_metric_definition( $primary_metric );

			$cta = $this->build_cta_join( $scope, 'cta_click_rate' === $primary_metric );
			if ( 'cta_click_rate' === $primary_metric && '' === $cta['sql'] ) {
				// No CTA source for this scope: answering with bounce rate is still a
				// true statement about where the problem sits, so degrade instead of
				// returning nothing.
				$primary_metric = 'bounce_rate';
				$metric_def     = $this->get_metric_definition( $primary_metric );
			}

			$totals = $this->query_scope_totals( $where, $cta );
			if ( (int) $totals['sessions'] < 1 ) {
				return $this->unavailable( 'no_scope_sessions', $scope, $exclude_spam );
			}

			if ( (int) $totals['sessions'] < self::MIN_SCOPE_SESSIONS ) {
				return $this->unavailable( 'too_few_sessions', $scope, $exclude_spam, (int) $totals['sessions'] );
			}

			$scope_sessions = (int) $totals['sessions'];
			$midpoint       = $this->get_period_midpoint( $scope );
			$single_defs    = $this->get_dimensions();
			$single_buckets = array();

			// Free measures only the dimensions its traffic-mix table compares
			// (country, source, device): the per-audience breakdown that answers
			// "where is the problem" is a Pro feature since OPT-403 and is added by
			// the Pro subclass through build_segment_breakdown().
			foreach ( $this->get_mix_dimensions() as $key ) {
				if ( ! isset( $single_defs[ $key ] ) ) {
					continue;
				}

				$buckets = $this->query_dimension( $key, $single_defs[ $key ], $where, $cta, $totals, $primary_metric, $metric_def, array( 'midpoint' => $midpoint ) );
				if ( ! empty( $buckets ) ) {
					$single_buckets[ $key ] = $buckets;
				}
			}

			$breakdown = $this->build_segment_breakdown(
				array(
					'scope'          => $scope,
					'where'          => $where,
					'cta'            => $cta,
					'totals'         => $totals,
					'primary_metric' => $primary_metric,
					'metric_def'     => $metric_def,
					'scope_sessions' => $scope_sessions,
					'midpoint'       => $midpoint,
					'definitions'    => $single_defs,
					'single_buckets' => $single_buckets,
				)
			);

			if ( is_array( $breakdown ) && ! empty( $breakdown['unavailable_reason'] ) ) {
				return $this->unavailable( (string) $breakdown['unavailable_reason'], $scope, $exclude_spam, $scope_sessions );
			}

			// The breakdown measured every dimension: the mix reuses its buckets
			// instead of querying the same three dimensions twice.
			if ( is_array( $breakdown ) && ! empty( $breakdown['single_buckets'] ) && is_array( $breakdown['single_buckets'] ) ) {
				$single_buckets = array_merge( $single_buckets, $breakdown['single_buckets'] );
			}

			$traffic_mix = $this->build_traffic_mix(
				$scope,
				$exclude_spam,
				$cta,
				$totals,
				$single_defs,
				$single_buckets
			);

			$matrix = array(
				'version'               => self::SCHEMA_VERSION,
				'available'             => true,
				'reason'                => '',
				'scope'                 => $scope,
				'primary_metric'        => $primary_metric,
				'metric_label'          => $metric_def['label'],
				'metric_unit'           => $metric_def['unit'],
				'metric_type'           => $metric_def['type'],
				'scope_totals'          => $this->format_metrics( $totals, $totals['sessions'] ),
				'scope_sessions'        => $scope_sessions,
				'total_sessions'        => $scope_sessions,
				// Always echoed so the UI can say "12 of 30 sessions needed"
				// instead of the generic "not enough data" sentence.
				'min_scope_sessions'    => self::MIN_SCOPE_SESSIONS,
				'exclude_spam'          => $exclude_spam,
				// False = no audience breakdown in this payload (Free alone, closed
				// licence): an empty outlier list then means "not measured", never
				// "the problem is everywhere", so `uniform` stays false.
				'segments_included'     => false,
				'dimension_count'       => 0,
				'combo_dimension_count' => 0,
				'dimensions'            => array(),
				'outliers'              => array(),
				'uniform'               => false,
				'top_segment'           => null,
				// Null when nothing moved: the card is quiet by default and only
				// appears when the audience itself actually changed.
				'traffic_mix'           => $traffic_mix,
			);

			if ( is_array( $breakdown ) ) {
				$matrix['segments_included']     = true;
				$matrix['dimension_count']       = isset( $breakdown['dimensions'] ) ? count( (array) $breakdown['dimensions'] ) : 0;
				$matrix['combo_dimension_count'] = isset( $breakdown['combo_dimension_count'] ) ? (int) $breakdown['combo_dimension_count'] : 0;
				$matrix['dimensions']            = isset( $breakdown['dimensions'] ) ? (array) $breakdown['dimensions'] : array();
				$matrix['outliers']              = isset( $breakdown['outliers'] ) ? (array) $breakdown['outliers'] : array();
				$matrix['uniform']               = empty( $matrix['outliers'] );
				$matrix['top_segment']           = isset( $breakdown['top_segment'] ) && is_array( $breakdown['top_segment'] ) ? $breakdown['top_segment'] : null;
			}

			if ( '' !== $cache_key ) {
				set_transient( $cache_key, $matrix, self::CACHE_TTL );
			}

			return $matrix;
		}

		/**
		 * Dimensions the traffic-mix comparison runs on.
		 *
		 * @since 1.4.1
		 * @return array
		 */
		public function get_mix_dimensions() {
			$keys = array( 'country', 'source', 'device' );

			/**
			 * Filter the dimensions compared against the previous period.
			 *
			 * The list is truncated to MAX_MIX_DIMENSIONS entries whatever it
			 * returns: one extra aggregate query each, inside a modal.
			 *
			 * @since 1.4.1
			 *
			 * @param array $keys Dimension keys.
			 */
			$keys = apply_filters( 'opti_behavior_smart_insights_segment_mix_dimensions', $keys );
			if ( ! is_array( $keys ) ) {
				return array();
			}

			$clean = array();
			foreach ( $keys as $key ) {
				$key = sanitize_key( $key );
				if ( '' === $key || in_array( $key, $clean, true ) ) {
					continue;
				}

				$clean[] = $key;
				if ( count( $clean ) >= self::MAX_MIX_DIMENSIONS ) {
					break;
				}
			}

			return $clean;
		}

		/**
		 * Compare this period's traffic mix against the previous one.
		 *
		 * Answers the question that invalidates every other answer in the panel:
		 * did the page get worse, or did the audience change? A metric drop carried
		 * by a bucket that just tripled its share of the traffic is a mix effect,
		 * not a page regression — and the payload says which of the two it is
		 * (`degrades_metric`) instead of leaving the reader to guess.
		 *
		 * Returns null when nothing moved, so the card is quiet by default.
		 *
		 * @since 1.4.1
		 *
		 * @param array $scope          Resolved scope.
		 * @param bool  $exclude_spam   Spam policy.
		 * @param array $cta            CTA join fragment.
		 * @param array $totals         Current-period scope aggregates.
		 * @param array $definitions    Single dimension registry.
		 * @param array $single_buckets Computed single buckets keyed by dimension.
		 * @return array|null
		 */
		protected function build_traffic_mix( $scope, $exclude_spam, $cta, $totals, $definitions, $single_buckets ) {
			if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Trend_Calculator' ) ) {
				return null;
			}

			$calculator     = new Opti_Behavior_Smart_Insights_Trend_Calculator();
			$previous_range = $calculator->get_previous_period_range( $scope['date_from'], $scope['date_to'] );
			if ( empty( $previous_range['from'] ) || empty( $previous_range['to'] ) ) {
				return null;
			}

			$previous_where = $this->build_scope_where( $scope, $exclude_spam, $previous_range );
			if ( '' === $previous_where['sql'] ) {
				return null;
			}

			$previous_totals   = $this->query_scope_totals( $previous_where, $cta );
			$previous_sessions = (int) $previous_totals['sessions'];

			// Comparing against a period that was itself too small would turn every
			// normal week-to-week wobble into a "traffic anomaly".
			if ( $previous_sessions < self::MIN_SCOPE_SESSIONS ) {
				return null;
			}

			$current_sessions = max( 1, (int) $totals['sessions'] );
			$entries          = array();
			$shift_count      = 0;
			$grouped_any      = false;

			foreach ( $this->get_mix_dimensions() as $dimension_key ) {
				if ( ! isset( $definitions[ $dimension_key ], $single_buckets[ $dimension_key ] ) ) {
					continue;
				}

				$previous_shares = $this->query_mix_shares( $definitions[ $dimension_key ], $previous_where, $cta );
				if ( empty( $previous_shares ) ) {
					continue;
				}

				$entry = $this->build_mix_dimension_entry(
					$dimension_key,
					$definitions[ $dimension_key ],
					$single_buckets[ $dimension_key ],
					$previous_shares,
					$current_sessions,
					$previous_sessions
				);

				if ( null === $entry ) {
					continue;
				}

				$shift_count += (int) $entry['shift_count'];
				$grouped_any  = $grouped_any || ! empty( $entry['grouped_shift'] );
				$entries[]    = $entry;
			}

			if ( empty( $entries ) || ( 0 === $shift_count && ! $grouped_any ) ) {
				return null;
			}

			return array(
				'available'          => true,
				'previous_range'     => array(
					'from' => (string) $previous_range['from'],
					'to'   => (string) $previous_range['to'],
				),
				'current_sessions'   => (int) $totals['sessions'],
				'previous_sessions'  => $previous_sessions,
				'min_scope_sessions' => self::MIN_SCOPE_SESSIONS,
				'min_share_delta'    => self::MIN_MIX_SHARE_DELTA,
				'shift_count'        => $shift_count,
				'dimensions'         => $entries,
			);
		}

		/**
		 * Previous-period session counts per bucket for one dimension.
		 *
		 * @since 1.4.1
		 *
		 * @param array $dimension Dimension definition.
		 * @param array $where     Previous-period WHERE fragment.
		 * @param array $cta       CTA join fragment.
		 * @return array Bucket key => sessions.
		 */
		protected function query_mix_shares( $dimension, $where, $cta ) {
			global $wpdb;

			$expression = isset( $dimension['expression'] ) ? (string) $dimension['expression'] : '';
			if ( '' === $expression ) {
				return array();
			}

			$needs_visitors = isset( $dimension['needs'] ) && 'visitors' === $dimension['needs'];

			$sql = "SELECT {$expression} AS segment_key, COUNT(DISTINCT pv.session_id) AS sessions "
				. $this->get_from_clause( $needs_visitors, $cta )
				. " WHERE {$where['sql']}"
				. ' GROUP BY segment_key ORDER BY sessions DESC, segment_key ASC LIMIT %d';

			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, array_merge( $cta['values'], $where['values'], array( self::MAX_SEGMENTS ) ) ),
				ARRAY_A
			);

			$shares = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$key = sanitize_text_field( (string) ( isset( $row['segment_key'] ) ? $row['segment_key'] : '' ) );
				if ( '' === $key ) {
					continue;
				}
				$shares[ $key ] = isset( $row['sessions'] ) ? (int) $row['sessions'] : 0;
			}

			return $shares;
		}

		/**
		 * Shape one dimension's share comparison, with the grouped-spike check.
		 *
		 * The grouped check exists because a bot wave rarely arrives from one
		 * country: five countries each adding 4 points of share is invisible to a
		 * per-bucket test and obvious once they are summed.
		 *
		 * @since 1.4.1
		 *
		 * @param string $dimension_key     Dimension key.
		 * @param array  $definition        Dimension definition.
		 * @param array  $buckets           Current-period buckets.
		 * @param array  $previous_shares   Previous-period sessions per key.
		 * @param int    $current_sessions  Current scope denominator.
		 * @param int    $previous_sessions Previous scope denominator.
		 * @return array|null
		 */
		protected function build_mix_dimension_entry( $dimension_key, $definition, $buckets, $previous_shares, $current_sessions, $previous_sessions ) {
			$shaped        = array();
			$shift_count   = 0;
			$degrades_any  = false;
			$grown_current = 0;
			$grown_previous = 0;
			$grown_count   = 0;

			foreach ( $buckets as $bucket ) {
				$key = isset( $bucket['key'] ) ? (string) $bucket['key'] : '';

				// A tracking gap growing is a tracking story, not a traffic story.
				if ( '' === $key || 'unknown' === $key ) {
					continue;
				}

				$sessions          = (int) $bucket['sessions'];
				$previous_bucket   = isset( $previous_shares[ $key ] ) ? (int) $previous_shares[ $key ] : 0;
				$share             = round( $sessions / $current_sessions, 4 );
				$previous_share    = round( $previous_bucket / max( 1, $previous_sessions ), 4 );
				$share_delta       = round( $share - $previous_share, 4 );
				$relative_growth   = $previous_share > 0 ? round( ( $share / $previous_share ) - 1, 4 ) : null;
				$z                 = $this->two_proportion_z( $sessions, $current_sessions, $previous_bucket, $previous_sessions );

				$mix_shift = $sessions >= self::MIN_BUCKET_SESSIONS
					&& $share_delta >= self::MIN_MIX_SHARE_DELTA
					&& ( null === $relative_growth || $relative_growth >= self::MIN_MIX_RELATIVE_GROWTH )
					&& null !== $z && $z >= self::SIGNIFICANCE_Z;

				// Causality hint, reusing the test the bucket already carries: a
				// spike only explains the metric when the spiking audience also
				// behaves measurably worse than everyone else.
				$degrades_metric = null;
				if ( empty( $bucket['insufficient'] ) ) {
					$degrades_metric = ! empty( $bucket['is_outlier'] ) && ! empty( $bucket['is_worse_side'] );
				}

				if ( $mix_shift ) {
					$shift_count++;
					$degrades_any = $degrades_any || true === $degrades_metric;
				}

				if ( $share_delta > 0 && ( null === $relative_growth || $relative_growth >= self::MIN_MIX_RELATIVE_GROWTH ) ) {
					$grown_current  += $sessions;
					$grown_previous += $previous_bucket;
					$grown_count++;
				}

				$shaped_bucket = array(
					'key'               => $key,
					'label'             => isset( $bucket['label'] ) ? (string) $bucket['label'] : $key,
					'sessions'          => $sessions,
					'previous_sessions' => $previous_bucket,
					'share'             => $share,
					'previous_share'    => $previous_share,
					'share_delta'       => $share_delta,
					'relative_growth'   => $relative_growth,
					'z'                 => $z,
					'mix_shift'         => $mix_shift,
					'degrades_metric'   => $degrades_metric,
					'value'             => isset( $bucket['value'] ) ? $bucket['value'] : null,
					'complement_value'  => isset( $bucket['complement_value'] ) ? $bucket['complement_value'] : null,
				);

				if ( ! empty( $bucket['country_code'] ) ) {
					$shaped_bucket['country_code'] = (string) $bucket['country_code'];
				}

				$shaped[] = $shaped_bucket;
			}

			if ( empty( $shaped ) ) {
				return null;
			}

			$grouped       = null;
			$grouped_shift = false;

			// One bucket growing is already reported per-bucket; the grouped check
			// only earns its own flag when the movement is spread over several.
			if ( $grown_count >= 2 ) {
				$grouped_share    = round( $grown_current / $current_sessions, 4 );
				$grouped_previous = round( $grown_previous / max( 1, $previous_sessions ), 4 );
				$grouped_delta    = round( $grouped_share - $grouped_previous, 4 );
				$grouped_z        = $this->two_proportion_z( $grown_current, $current_sessions, $grown_previous, $previous_sessions );
				$grouped_shift    = $grouped_delta >= self::MIN_MIX_SHARE_DELTA && null !== $grouped_z && $grouped_z >= self::SIGNIFICANCE_Z;

				$grouped = array(
					'bucket_count'      => $grown_count,
					'sessions'          => $grown_current,
					'previous_sessions' => $grown_previous,
					'share'             => $grouped_share,
					'previous_share'    => $grouped_previous,
					'share_delta'       => $grouped_delta,
					'z'                 => $grouped_z,
				);
			}

			$pulse = 'healthy';
			if ( $shift_count > 0 || $grouped_shift ) {
				$pulse = $degrades_any ? 'broken' : 'watch';
			}

			return array(
				'dimension'       => $dimension_key,
				'dimension_label' => isset( $definition['label'] ) ? (string) $definition['label'] : $dimension_key,
				'report_key'      => isset( $definition['report_key'] ) ? sanitize_key( $definition['report_key'] ) : '',
				'pulse'           => $pulse,
				'shift_count'     => $shift_count,
				'grouped_shift'   => $grouped_shift,
				'grouped'         => $grouped,
				'buckets'         => $shaped,
			);
		}

		/**
		 * Midpoint timestamp splitting the scope period in two halves.
		 *
		 * @since 1.4.1
		 *
		 * @param array $scope Resolved scope.
		 * @return string Empty when the period is degenerate.
		 */
		protected function get_period_midpoint( $scope ) {
			$from = strtotime( $scope['date_from'] . ' 00:00:00' );
			$to   = strtotime( $scope['date_to'] . ' 23:59:59' );

			if ( ! $from || ! $to || $to <= $from ) {
				return '';
			}

			return gmdate( 'Y-m-d H:i:s', $from + (int) floor( ( $to - $from ) / 2 ) );
		}

		/**
		 * Per-audience breakdown ("where is the problem?") — Pro extension point.
		 *
		 * Free computes no breakdown (WordPress.org guideline 5, OPT-403): it
		 * returns null and the payload carries the traffic mix only. The Pro
		 * subclass returns {dimensions, outliers, combo_dimension_count,
		 * top_segment, single_buckets} or {unavailable_reason}.
		 *
		 * @since 1.10.0
		 *
		 * @param array $context Scope, WHERE / CTA fragments, totals, metric, definitions, mix buckets.
		 * @return array|null
		 */
		protected function build_segment_breakdown( $context ) {
			unset( $context );

			return null;
		}

		/**
		 * Identify one combination bucket — Pro extension point.
		 *
		 * Free never queries a combination dimension, so it identifies none.
		 *
		 * @since 1.10.0
		 *
		 * @param array  $dimension   Combination dimension definition.
		 * @param string $segment_key Composite bucket key.
		 * @param array  $row         Raw row.
		 * @return array|null {parts, label}
		 */
		protected function resolve_combo_bucket( $dimension, $segment_key, $row ) {
			unset( $dimension, $segment_key, $row );

			return null;
		}

		/**
		 * Optional one-audience restriction of the daily series — Pro extension point.
		 *
		 * The curve of one audience belongs to the Pro breakdown, so Free always
		 * answers with the whole scope.
		 *
		 * @since 1.10.0
		 *
		 * @param array $args Request args.
		 * @return array|null
		 */
		protected function resolve_segment_filter( $args ) {
			unset( $args );

			return null;
		}

		/**
		 * Build the daily time series for one insight scope.
		 *
		 * Same scope, spam policy, primary metric and cache policy as the matrix,
		 * so the verdict sentence and the curve underneath it always describe the
		 * same population. Never throws.
		 *
		 * @since 1.4.0
		 *
		 * @param array $insight Decoded insight row.
		 * @param array $args    Optional args: exclude_spam, segment_dimension, segment_key, skip_cache.
		 * @return array
		 */
		public function get_timeseries( $insight, $args = array() ) {
			$insight = is_array( $insight ) ? $insight : array();
			$args    = is_array( $args ) ? $args : array();

			$exclude_spam            = $this->resolve_exclude_spam( $args );
			$scope                   = $this->resolve_scope( $insight );
			$this->served_from_cache = false;

			try {
				return $this->build_timeseries( $insight, $args, $scope, $exclude_spam );
			} catch ( Exception $e ) {
				return $this->unavailable_series( 'timeseries_error', $scope, $exclude_spam );
			} catch ( Error $e ) {
				return $this->unavailable_series( 'timeseries_error', $scope, $exclude_spam );
			}
		}

		/**
		 * Build the time series payload.
		 *
		 * @param array $insight      Insight payload.
		 * @param array $args         Request args.
		 * @param array $scope        Resolved scope.
		 * @param bool  $exclude_spam Spam policy.
		 * @return array
		 */
		protected function build_timeseries( $insight, $args, $scope, $exclude_spam ) {
			global $wpdb;

			if ( in_array( $scope['entity_type'], array( 'funnel', 'form' ), true ) ) {
				return $this->unavailable_series( 'scope_not_page', $scope, $exclude_spam );
			}

			if ( empty( $scope['page_id'] ) && '' === $scope['page_url'] ) {
				return $this->unavailable_series( 'scope_not_addressable', $scope, $exclude_spam );
			}

			$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';
			$sessions_table  = $wpdb->prefix . 'optibehavior_sessions';
			if ( ! $this->table_exists( $pageviews_table ) || ! $this->table_exists( $sessions_table ) ) {
				return $this->unavailable_series( 'metrics_tables_missing', $scope, $exclude_spam );
			}

			$segment = $this->resolve_segment_filter( $args );
			$cache_key = $this->get_series_cache_key( $insight, $scope, $exclude_spam, $segment );
			if ( empty( $args['skip_cache'] ) && '' !== $cache_key ) {
				$cached = get_transient( $cache_key );
				if ( is_array( $cached ) && isset( $cached['version'] ) && self::SCHEMA_VERSION === (int) $cached['version'] ) {
					$this->served_from_cache = true;
					return $cached;
				}
			}

			$primary_metric = $this->resolve_primary_metric( $insight );
			$cta            = $this->build_cta_join( $scope, 'cta_click_rate' === $primary_metric );
			if ( 'cta_click_rate' === $primary_metric && '' === $cta['sql'] ) {
				$primary_metric = 'bounce_rate';
			}
			$metric_def = $this->get_metric_definition( $primary_metric );

			$previous_range = array(
				'from' => '',
				'to'   => '',
			);
			if ( class_exists( 'Opti_Behavior_Smart_Insights_Trend_Calculator' ) ) {
				$calculator     = new Opti_Behavior_Smart_Insights_Trend_Calculator();
				$previous_range = $calculator->get_previous_period_range( $scope['date_from'], $scope['date_to'] );
			}

			$current = $this->query_daily_series(
				$scope,
				$exclude_spam,
				array(
					'from' => $scope['date_from'],
					'to'   => $scope['date_to'],
				),
				$cta,
				$primary_metric,
				null
			);

			$previous = array();
			if ( ! empty( $previous_range['from'] ) && ! empty( $previous_range['to'] ) ) {
				$previous = $this->query_daily_series( $scope, $exclude_spam, $previous_range, $cta, $primary_metric, null );
			}

			$segment_payload = null;
			if ( null !== $segment ) {
				$segment_series = $this->query_daily_series(
					$scope,
					$exclude_spam,
					array(
						'from' => $scope['date_from'],
						'to'   => $scope['date_to'],
					),
					$cta,
					$primary_metric,
					$segment
				);

				$segment_payload = array(
					'dimension' => $segment['dimension'],
					'key'       => $segment['key'],
					'label'     => isset( $segment['label'] ) ? (string) $segment['label'] : $segment['key'],
					'series'    => $segment_series,
				);
			}

			$payload = array(
				'version'           => self::SCHEMA_VERSION,
				'available'         => true,
				'reason'            => '',
				'scope'             => $scope,
				'metric'            => $primary_metric,
				'metric_label'      => $metric_def['label'],
				'metric_unit'       => $metric_def['unit'],
				'metric_type'       => $metric_def['type'],
				'exclude_spam'      => (bool) $exclude_spam,
				'range'             => array(
					'from' => $scope['date_from'],
					'to'   => $scope['date_to'],
				),
				'previous_range'    => array(
					'from' => isset( $previous_range['from'] ) ? (string) $previous_range['from'] : '',
					'to'   => isset( $previous_range['to'] ) ? (string) $previous_range['to'] : '',
				),
				'current'           => $current,
				'previous'          => $previous,
				'first_detected_at' => $this->get_first_detected_at( $insight ),
				'segment'           => $segment_payload,
			);

			if ( '' !== $cache_key ) {
				set_transient( $cache_key, $payload, self::CACHE_TTL );
			}

			/**
			 * Filter the computed daily time series before it reaches the viewer.
			 *
			 * @since 1.4.0
			 *
			 * @param array $payload Time series payload.
			 * @param array $insight Insight the series was built for.
			 * @param array $args    Request args.
			 */
			return apply_filters( 'opti_behavior_smart_insights_timeseries', $payload, $insight, $args );
		}

		/**
		 * Run one zero-filled daily aggregation.
		 *
		 * @param array       $scope          Resolved scope.
		 * @param bool        $exclude_spam   Spam policy.
		 * @param array       $range          {from,to} range.
		 * @param array       $cta            CTA join fragment.
		 * @param string      $primary_metric Metric key.
		 * @param array|null  $segment        Optional segment restriction.
		 * @return array
		 */
		protected function query_daily_series( $scope, $exclude_spam, $range, $cta, $primary_metric, $segment ) {
			global $wpdb;

			$where = $this->build_scope_where( $scope, $exclude_spam, $range );
			if ( '' === $where['sql'] ) {
				return array();
			}

			$values         = array_merge( $cta['values'], $where['values'] );
			$needs_visitors = false;

			if ( is_array( $segment ) ) {
				$needs_visitors = ! empty( $segment['needs_visitors'] );
				foreach ( (array) $segment['restrictions'] as $restriction ) {
					$where['sql'] .= ' AND ' . $restriction['expression'] . ' = %s';
					$values[]      = $restriction['value'];
				}
			}

			$days = $this->build_day_index( $range );
			if ( empty( $days ) ) {
				return array();
			}

			$sql = 'SELECT DATE(pv.view_time) AS series_day, ' . $this->get_aggregate_select( $cta ) . ' '
				. $this->get_from_clause( $needs_visitors, $cta )
				. " WHERE {$where['sql']} GROUP BY series_day ORDER BY series_day ASC LIMIT %d";

			$values[] = count( $days );

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();

			$by_day = array();
			foreach ( $rows as $row ) {
				$day = isset( $row['series_day'] ) ? substr( (string) $row['series_day'], 0, 10 ) : '';
				if ( '' === $day ) {
					continue;
				}
				$by_day[ $day ] = $this->normalize_aggregate_row( $row );
			}

			$series = array();
			foreach ( $days as $day ) {
				if ( ! isset( $by_day[ $day ] ) ) {
					// A day with no traffic has no rate: null keeps the line broken
					// instead of drawing a fake 0 %.
					$series[] = array(
						'date'     => $day,
						'sessions' => 0,
						'value'    => null,
					);
					continue;
				}

				$aggregate = $by_day[ $day ];
				$metrics   = $this->format_metrics( $aggregate, $aggregate['sessions'] );
				$series[]  = array(
					'date'     => $day,
					'sessions' => (int) $aggregate['sessions'],
					'value'    => isset( $metrics[ $primary_metric ] ) ? $metrics[ $primary_metric ] : null,
				);
			}

			return $series;
		}

		/**
		 * Enumerate the days of a range (bounded to one year).
		 *
		 * @param array $range {from,to} range.
		 * @return array
		 */
		protected function build_day_index( $range ) {
			$from = strtotime( $this->normalize_date( isset( $range['from'] ) ? $range['from'] : '' ) . ' 00:00:00' );
			$to   = strtotime( $this->normalize_date( isset( $range['to'] ) ? $range['to'] : '' ) . ' 00:00:00' );

			if ( ! $from || ! $to || $to < $from ) {
				return array();
			}

			$days = array();
			for ( $ts = $from; $ts <= $to && count( $days ) < 366; $ts += DAY_IN_SECONDS ) {
				$days[] = gmdate( 'Y-m-d', $ts );
			}

			return $days;
		}

		/**
		 * First time this insight's group key was ever stored.
		 *
		 * @param array $insight Insight payload.
		 * @return string
		 */
		protected function get_first_detected_at( $insight ) {
			$group_key = isset( $insight['group_key'] ) && is_scalar( $insight['group_key'] ) ? (string) $insight['group_key'] : '';
			if ( '' === $group_key || ! class_exists( 'Opti_Behavior_Smart_Insights_Repository' ) ) {
				return isset( $insight['created_at'] ) ? (string) $insight['created_at'] : '';
			}

			$repository = new Opti_Behavior_Smart_Insights_Repository();
			if ( ! method_exists( $repository, 'get_first_detected_at' ) ) {
				return isset( $insight['created_at'] ) ? (string) $insight['created_at'] : '';
			}

			$first = $repository->get_first_detected_at( $group_key );

			return '' !== $first ? $first : ( isset( $insight['created_at'] ) ? (string) $insight['created_at'] : '' );
		}

		/**
		 * Transient key for one time series.
		 *
		 * @param array      $insight      Insight payload.
		 * @param array      $scope        Resolved scope.
		 * @param bool       $exclude_spam Spam policy.
		 * @param array|null $segment      Segment restriction.
		 * @return string
		 */
		protected function get_series_cache_key( $insight, $scope, $exclude_spam, $segment ) {
			$insight_id = isset( $insight['id'] ) ? absint( $insight['id'] ) : 0;
			if ( $insight_id < 1 ) {
				return '';
			}

			return 'opti_behavior_si_ts_' . md5(
				implode(
					'|',
					array(
						self::SCHEMA_VERSION,
						self::get_cache_salt(),
						$insight_id,
						$exclude_spam ? '1' : '0',
						$scope['date_from'],
						$scope['date_to'],
						null === $segment ? '' : $segment['dimension'] . ':' . $segment['key'],
					)
				)
			);
		}

		/**
		 * Build an unavailable time series payload.
		 *
		 * @param string $reason       Reason key.
		 * @param array  $scope        Resolved scope.
		 * @param bool   $exclude_spam Spam policy.
		 * @return array
		 */
		protected function unavailable_series( $reason, $scope, $exclude_spam ) {
			return array(
				'version'           => self::SCHEMA_VERSION,
				'available'         => false,
				'reason'            => sanitize_key( $reason ),
				'scope'             => $scope,
				'metric'            => '',
				'metric_label'      => '',
				'metric_unit'       => '',
				'metric_type'       => '',
				'exclude_spam'      => (bool) $exclude_spam,
				'range'             => array(
					'from' => $scope['date_from'],
					'to'   => $scope['date_to'],
				),
				'previous_range'    => array(
					'from' => '',
					'to'   => '',
				),
				'current'           => array(),
				'previous'          => array(),
				'first_detected_at' => '',
				'segment'           => null,
			);
		}

		/**
		 * Registry of segment dimensions.
		 *
		 * Every entry declares a SQL expression evaluated against the scope join
		 * plus the columns it needs. Dimensions whose backing column is missing on
		 * this install are skipped silently.
		 *
		 * @since 1.3.9
		 *
		 * @return array
		 */
		public function get_dimensions() {
			global $wpdb;

			$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
			$visitors_table = $wpdb->prefix . 'optibehavior_visitors';
			$dimensions     = array();

			if ( $this->column_exists( $visitors_table, 'device_type' ) ) {
				$dimensions['device'] = array(
					'expression' => "COALESCE(NULLIF(v.device_type, ''), 'unknown')",
					'needs'      => 'visitors',
					'label'      => __( 'Device', 'opti-behavior' ),
					'report_key' => 'device',
				);
			}

			if ( $this->column_exists( $visitors_table, 'browser' ) ) {
				$dimensions['browser'] = array(
					'expression' => "COALESCE(NULLIF(v.browser, ''), 'unknown')",
					'needs'      => 'visitors',
					'label'      => __( 'Browser', 'opti-behavior' ),
					'report_key' => '',
				);
			}

			if ( $this->column_exists( $visitors_table, 'country_name' ) ) {
				$country = array(
					'expression' => "COALESCE(NULLIF(v.country_name, ''), 'unknown')",
					'needs'      => 'visitors',
					'label'      => __( 'Country', 'opti-behavior' ),
					'report_key' => '',
				);

				// The bucket keeps its country NAME key (that is what the timeseries
				// overlay and every stored payload already reference); the ISO-2 code
				// travels beside it so the UI can draw a flag. Keying on the code
				// instead would drop every visitor row that only carries a name.
				if ( $this->column_exists( $visitors_table, 'country' ) ) {
					$country['extra_select'] = array(
						'bucket_country_code' => "MAX(NULLIF(UPPER(TRIM(v.country)), ''))",
					);
				}

				$dimensions['country'] = $country;
			}

			$source_expr = $this->get_source_expression();
			if ( '' !== $source_expr ) {
				$dimensions['source'] = array(
					'expression' => $source_expr,
					'needs'      => '',
					'label'      => __( 'Traffic source', 'opti-behavior' ),
					'report_key' => 'source',
				);
			}

			if ( $this->column_exists( $sessions_table, 'utm_campaign' ) ) {
				$dimensions['campaign'] = array(
					'expression' => "COALESCE(NULLIF(s.utm_campaign, ''), 'none')",
					'needs'      => '',
					'label'      => __( 'Campaign', 'opti-behavior' ),
					'report_key' => 'campaign',
					'labels'     => array(
						'none' => __( 'No campaign', 'opti-behavior' ),
					),
				);
			}

			if ( $this->column_exists( $visitors_table, 'visit_count' ) ) {
				$dimensions['visitor_type'] = array(
					'expression' => "CASE WHEN COALESCE(v.visit_count, 1) <= 1 THEN 'new' ELSE 'returning' END",
					'needs'      => 'visitors',
					'label'      => __( 'New vs returning', 'opti-behavior' ),
					'report_key' => '',
					'labels'     => array(
						'new'       => __( 'New visitors', 'opti-behavior' ),
						'returning' => __( 'Returning visitors', 'opti-behavior' ),
					),
				);
			}

			$dimensions['daypart'] = array(
				'expression' => "CASE WHEN HOUR(pv.view_time) < 6 THEN 'night' WHEN HOUR(pv.view_time) < 12 THEN 'morning' WHEN HOUR(pv.view_time) < 18 THEN 'afternoon' ELSE 'evening' END",
				'needs'      => '',
				'label'      => __( 'Time of day', 'opti-behavior' ),
				'report_key' => '',
				'labels'     => array(
					'night'     => __( 'Night (00-06)', 'opti-behavior' ),
					'morning'   => __( 'Morning (06-12)', 'opti-behavior' ),
					'afternoon' => __( 'Afternoon (12-18)', 'opti-behavior' ),
					'evening'   => __( 'Evening (18-24)', 'opti-behavior' ),
				),
			);

			$dimensions['weekday'] = array(
				'expression' => "CASE WHEN DAYOFWEEK(pv.view_time) IN (1, 7) THEN 'weekend' ELSE 'weekday' END",
				'needs'      => '',
				'label'      => __( 'Day type', 'opti-behavior' ),
				'report_key' => '',
				'labels'     => array(
					'weekend' => __( 'Weekend', 'opti-behavior' ),
					'weekday' => __( 'Weekday', 'opti-behavior' ),
				),
			);

			/**
			 * Filter the segment dimensions computed for the "Where is the problem?" section.
			 *
			 * @since 1.3.9
			 *
			 * @param array $dimensions Dimension registry.
			 */
			$dimensions = apply_filters( 'opti_behavior_smart_insights_segment_dimensions', $dimensions );

			return is_array( $dimensions ) ? $dimensions : array();
		}

		/**
		 * Metric definitions the matrix can measure per bucket.
		 *
		 * `abs_floor` is the absolute deviation (in the metric's own unit) a bucket
		 * must clear on top of the relative test. Percentage metrics use 10 points;
		 * seconds have no meaningful universal floor.
		 *
		 * @since 1.4.0
		 * @return array
		 */
		public function get_metric_definitions() {
			return array(
				'bounce_rate'    => array(
					'label'           => __( 'Bounce rate', 'opti-behavior' ),
					'type'            => 'rate',
					'unit'            => 'percent',
					'abs_floor'       => 10.0,
					'higher_is_worse' => true,
				),
				'exit_rate'      => array(
					'label'           => __( 'Exit rate', 'opti-behavior' ),
					'type'            => 'rate',
					'unit'            => 'percent',
					'abs_floor'       => 10.0,
					'higher_is_worse' => true,
				),
				'cta_click_rate' => array(
					'label'           => __( 'CTA click rate', 'opti-behavior' ),
					'type'            => 'rate',
					'unit'            => 'percent',
					'abs_floor'       => 10.0,
					'higher_is_worse' => false,
				),
				'avg_scroll'     => array(
					'label'           => __( 'Average scroll depth', 'opti-behavior' ),
					'type'            => 'average',
					'unit'            => 'percent',
					'abs_floor'       => 10.0,
					'higher_is_worse' => false,
				),
				'avg_time'       => array(
					'label'           => __( 'Average time on page', 'opti-behavior' ),
					'type'            => 'average',
					'unit'            => 'seconds',
					'abs_floor'       => 0.0,
					'higher_is_worse' => false,
				),
			);
		}

		/**
		 * Resolve one metric definition, falling back to bounce rate.
		 *
		 * @param string $metric Metric key.
		 * @return array
		 */
		protected function get_metric_definition( $metric ) {
			$definitions = $this->get_metric_definitions();

			return isset( $definitions[ $metric ] ) ? $definitions[ $metric ] : $definitions['bounce_rate'];
		}

		/**
		 * Resolve the primary metric the matrix compares segments on.
		 *
		 * Prefers the signal object's own declaration (`get_primary_segment_metric()`)
		 * so Free and Pro signals stay authoritative about their own metric, and
		 * falls back to a signal_id map for stored insights whose signal class is no
		 * longer registered (deactivated Pro, renamed signal).
		 *
		 * @since 1.4.0
		 *
		 * @param array $insight Insight payload.
		 * @return string
		 */
		public function resolve_primary_metric( $insight ) {
			$insight   = is_array( $insight ) ? $insight : array();
			$signal_id = isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '';
			$metric    = '';

			$signal = $this->find_signal( $signal_id, isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '' );
			if ( is_object( $signal ) && method_exists( $signal, 'get_primary_segment_metric' ) ) {
				$metric = (string) $signal->get_primary_segment_metric();
			}

			if ( '' === $metric ) {
				$metric = $this->get_fallback_primary_metric( $signal_id );
			}

			/**
			 * Filter the metric the segment matrix compares buckets on.
			 *
			 * @since 1.4.0
			 *
			 * @param string $metric    Metric key.
			 * @param array  $insight   Insight payload.
			 * @param string $signal_id Signal id.
			 */
			$metric = (string) apply_filters( 'opti_behavior_smart_insights_segment_primary_metric', $metric, $insight, $signal_id );

			$definitions = $this->get_metric_definitions();

			return isset( $definitions[ $metric ] ) ? $metric : 'bounce_rate';
		}

		/**
		 * Signal_id → metric map used when the signal object is unavailable.
		 *
		 * @param string $signal_id Signal id.
		 * @return string
		 */
		protected function get_fallback_primary_metric( $signal_id ) {
			$map = array(
				'high_exit_rate_page'                      => 'exit_rate',
				'quick_exit_pattern'                       => 'exit_rate',
				'low_scroll_depth_important_page'          => 'avg_scroll',
				'engagement_decay'                         => 'avg_time',
				'product_page_engagement_issue'            => 'avg_time',
				'high_traffic_low_engagement'              => 'avg_time',
				'cta_low_performance'                      => 'cta_click_rate',
				'poor_conversion_rate'                     => 'cta_click_rate',
				'conversion_drop_alert'                    => 'cta_click_rate',
				'mobile_cta_click_rate_lower_than_desktop' => 'cta_click_rate',
			);

			/**
			 * Filter the signal_id → segment metric fallback map.
			 *
			 * @since 1.4.0
			 *
			 * @param array $map Signal id to metric key.
			 */
			$map = apply_filters( 'opti_behavior_smart_insights_segment_metric_map', $map );
			$map = is_array( $map ) ? $map : array();

			if ( isset( $map[ $signal_id ] ) ) {
				return (string) $map[ $signal_id ];
			}

			// Keyword fallback for signals nobody mapped (Pro add-ons, custom rules).
			if ( false !== strpos( $signal_id, 'exit' ) ) {
				return 'exit_rate';
			}
			if ( false !== strpos( $signal_id, 'scroll' ) ) {
				return 'avg_scroll';
			}
			if ( false !== strpos( $signal_id, 'cta' ) || false !== strpos( $signal_id, 'click' ) || false !== strpos( $signal_id, 'conversion' ) ) {
				return 'cta_click_rate';
			}
			if ( false !== strpos( $signal_id, 'engagement' ) || false !== strpos( $signal_id, 'time' ) ) {
				return 'avg_time';
			}

			return 'bounce_rate';
		}

		/**
		 * Look up a registered signal object by id.
		 *
		 * @param string $signal_id   Signal id.
		 * @param string $entity_type Entity type.
		 * @return object|null
		 */
		protected function find_signal( $signal_id, $entity_type ) {
			if ( '' === $signal_id || ! class_exists( 'Opti_Behavior_Smart_Insights_Signal_Registry' ) ) {
				return null;
			}

			try {
				$registry = new Opti_Behavior_Smart_Insights_Signal_Registry();
				if ( ! method_exists( $registry, 'for_entity_type' ) ) {
					return null;
				}

				$signals = $registry->for_entity_type( '' !== $entity_type ? $entity_type : 'page', array() );
				foreach ( (array) $signals as $signal ) {
					if ( ! is_object( $signal ) || ! method_exists( $signal, 'get_definition' ) ) {
						continue;
					}
					$definition = $signal->get_definition();
					if ( isset( $definition['signal_id'] ) && $signal_id === sanitize_key( $definition['signal_id'] ) ) {
						return $signal;
					}
				}
			} catch ( Exception $e ) {
				return null;
			} catch ( Error $e ) {
				return null;
			}

			return null;
		}

		/**
		 * Query the aggregate numerators/denominators for the whole scope.
		 *
		 * @param array $where Scope WHERE fragment.
		 * @param array $cta   CTA join fragment.
		 * @return array
		 */
		protected function query_scope_totals( $where, $cta ) {
			global $wpdb;

			$sql = 'SELECT ' . $this->get_aggregate_select( $cta ) . ' ' . $this->get_from_clause( true, $cta ) . " WHERE {$where['sql']}";
			$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( $cta['values'], $where['values'] ) ), ARRAY_A );

			return $this->normalize_aggregate_row( is_array( $row ) ? $row : array() );
		}

		/**
		 * Query one dimension's buckets and run the deviation test on each.
		 *
		 * @param string $key            Dimension key.
		 * @param array  $dimension      Dimension definition.
		 * @param array  $where          Scope WHERE fragment.
		 * @param array  $cta            CTA join fragment.
		 * @param array  $totals         Scope aggregates.
		 * @param string $primary_metric Primary metric key.
		 * @param array  $metric_def     Primary metric definition.
		 * @param array  $options        Optional: midpoint, significance_z.
		 * @return array
		 */
		protected function query_dimension( $key, $dimension, $where, $cta, $totals, $primary_metric, $metric_def, $options = array() ) {
			global $wpdb;

			$expression = isset( $dimension['expression'] ) ? (string) $dimension['expression'] : '';
			if ( '' === $expression ) {
				return array();
			}

			$options        = is_array( $options ) ? $options : array();
			$is_combo       = ! empty( $dimension['is_combo'] );
			$midpoint       = isset( $options['midpoint'] ) ? (string) $options['midpoint'] : '';
			$significance_z = isset( $options['significance_z'] ) ? (float) $options['significance_z'] : self::SIGNIFICANCE_Z;

			$needs_visitors = isset( $dimension['needs'] ) && 'visitors' === $dimension['needs'];
			$trend          = $this->get_trend_select( $primary_metric, $metric_def, $cta, $midpoint );

			$sql = "SELECT {$expression} AS segment_key, " . $this->get_aggregate_select( $cta )
				. $trend['sql'] . $this->get_extra_select( $dimension ) . ' '
				. $this->get_from_clause( $needs_visitors, $cta )
				. " WHERE {$where['sql']}"
				. ' GROUP BY segment_key ORDER BY sessions DESC, segment_key ASC LIMIT %d';

			// The trend placeholders live in the SELECT list, so their values come
			// before the CTA join and the WHERE fragment.
			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, array_merge( $trend['values'], $cta['values'], $where['values'], array( self::MAX_SEGMENTS ) ) ),
				ARRAY_A
			);

			if ( empty( $rows ) || ! is_array( $rows ) ) {
				return array();
			}

			$scope_sessions = max( 1, (int) $totals['sessions'] );
			$buckets        = array();

			foreach ( $rows as $row ) {
				$segment_key = sanitize_text_field( (string) ( isset( $row['segment_key'] ) ? $row['segment_key'] : '' ) );
				$aggregate   = $this->normalize_aggregate_row( $row );
				$sessions    = (int) $aggregate['sessions'];

				if ( '' === $segment_key || $sessions < 1 ) {
					continue;
				}

				$parts = null;
				$label = $this->segment_label( $dimension, $segment_key );

				if ( $is_combo ) {
					$combo = $this->resolve_combo_bucket( $dimension, $segment_key, $row );
					if ( null === $combo ) {
						continue;
					}

					$parts = $combo['parts'];
					$label = $combo['label'];
				}

				$complement = $this->subtract_aggregates( $totals, $aggregate );
				$metrics    = $this->format_metrics( $aggregate, $sessions );
				$comp_stats = $this->format_metrics( $complement, $complement['sessions'] );

				$value      = isset( $metrics[ $primary_metric ] ) ? $metrics[ $primary_metric ] : null;
				$comp_value = isset( $comp_stats[ $primary_metric ] ) ? $comp_stats[ $primary_metric ] : null;
				$delta      = ( null !== $value && null !== $comp_value ) ? round( $value - $comp_value, 2 ) : null;

				$test = $this->test_bucket( $primary_metric, $metric_def, $aggregate, $complement, $sessions, $scope_sessions, $value, $comp_value, $significance_z );

				// Which side of the comparison is the bad side depends on the
				// metric: a high bounce rate is a problem, a high CTA click rate is
				// not. Detection stays symmetric; only the verdict picks a side.
				$worse_sign    = ! empty( $metric_def['higher_is_worse'] ) ? 1 : -1;
				$is_worse_side = null !== $delta && ( $worse_sign * $delta ) > 0;

				$bucket = array(
					'key'              => $segment_key,
					'label'            => $label,
					'sessions'         => $sessions,
					'share'            => round( min( 1, $sessions / $scope_sessions ), 4 ),
					'metrics'          => $metrics,
					'complement'       => $comp_stats,
					'complement_sessions' => (int) $complement['sessions'],
					'value'            => $value,
					'complement_value' => $comp_value,
					'delta'            => $delta,
					'z'                => $test['z'],
					'is_outlier'       => $test['is_outlier'],
					'is_worse_side'    => $is_worse_side,
					'rank_score'       => $test['rank_score'],
					// A bucket under the data floor was never tested: saying so is
					// an answer, saying "no problem here" would be a lie.
					'insufficient'     => $test['insufficient'],
					'trend'            => $this->compute_trend( $row, $metric_def ),
				);

				$country_code = $this->read_country_code( $row );
				if ( '' !== $country_code && ( 'country' === $key || $is_combo ) ) {
					$bucket['country_code'] = $country_code;
				}

				if ( null !== $parts ) {
					$bucket['parts'] = $parts;
				}

				$buckets[] = $bucket;
			}

			return $buckets;
		}

		/**
		 * Extra per-bucket SELECT columns declared by a dimension.
		 *
		 * @since 1.4.1
		 *
		 * @param array $dimension Dimension definition.
		 * @return string
		 */
		protected function get_extra_select( $dimension ) {
			if ( empty( $dimension['extra_select'] ) || ! is_array( $dimension['extra_select'] ) ) {
				return '';
			}

			$parts = array();
			foreach ( $dimension['extra_select'] as $alias => $expression ) {
				$alias = sanitize_key( $alias );
				if ( '' === $alias || ! is_string( $expression ) || '' === $expression ) {
					continue;
				}
				$parts[] = $expression . ' AS ' . $alias;
			}

			return empty( $parts ) ? '' : ', ' . implode( ', ', $parts );
		}

		/**
		 * ISO-2 country code companion value of one bucket row.
		 *
		 * @since 1.4.1
		 *
		 * @param array $row Raw row.
		 * @return string
		 */
		protected function read_country_code( $row ) {
			if ( ! isset( $row['bucket_country_code'] ) ) {
				return '';
			}

			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $row['bucket_country_code'] ) );

			return 2 === strlen( $code ) ? $code : '';
		}

		/**
		 * Split-half conditional aggregates for the per-bucket trend verdict.
		 *
		 * Only the primary metric's own components are split, so the trend costs no
		 * extra query and only a handful of extra columns.
		 *
		 * @since 1.4.1
		 *
		 * @param string $primary_metric Metric key.
		 * @param array  $metric_def     Metric definition.
		 * @param array  $cta            CTA join fragment.
		 * @param string $midpoint       Period midpoint (Y-m-d H:i:s), '' to skip.
		 * @return array {sql, values}
		 */
		protected function get_trend_select( $primary_metric, $metric_def, $cta, $midpoint ) {
			global $wpdb;

			$empty = array(
				'sql'    => '',
				'values' => array(),
			);

			if ( '' === $midpoint ) {
				return $empty;
			}

			$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';
			$sessions_table  = $wpdb->prefix . 'optibehavior_sessions';
			$halves          = array(
				'early' => '<',
				'late'  => '>=',
			);

			$parts  = array();
			$values = array();

			foreach ( $halves as $half => $operator ) {
				$parts[]  = "COUNT(DISTINCT CASE WHEN pv.view_time {$operator} %s THEN pv.session_id END) AS trend_{$half}_sessions";
				$values[] = $midpoint;
			}

			if ( 'rate' === $metric_def['type'] ) {
				$target    = 'pv.session_id';
				$condition = '';

				if ( 'exit_rate' === $primary_metric ) {
					if ( ! $this->column_exists( $pageviews_table, 'exit_page' ) ) {
						return $empty;
					}
					$condition = 'pv.exit_page = 1';
				} elseif ( 'cta_click_rate' === $primary_metric ) {
					if ( '' === $cta['sql'] ) {
						return $empty;
					}
					$target = 'ob_cta.session_id';
				} else {
					if ( ! $this->column_exists( $sessions_table, 'is_bounce' ) ) {
						return $empty;
					}
					$condition = 's.is_bounce = 1';
				}

				foreach ( $halves as $half => $operator ) {
					$when     = ( '' !== $condition ? $condition . ' AND ' : '' ) . "pv.view_time {$operator} %s";
					$parts[]  = "COUNT(DISTINCT CASE WHEN {$when} THEN {$target} END) AS trend_{$half}_numerator";
					$values[] = $midpoint;
				}
			} else {
				$column = 'avg_scroll' === $primary_metric ? 'scroll_depth' : 'time_on_page';
				if ( ! $this->column_exists( $pageviews_table, $column ) ) {
					return $empty;
				}

				foreach ( $halves as $half => $operator ) {
					$parts[]  = "COUNT(CASE WHEN pv.view_time {$operator} %s THEN NULLIF(pv.{$column}, 0) END) AS trend_{$half}_n";
					$values[] = $midpoint;
					$parts[]  = "SUM(CASE WHEN pv.view_time {$operator} %s THEN NULLIF(pv.{$column}, 0) END) AS trend_{$half}_sum";
					$values[] = $midpoint;
				}
			}

			return array(
				'sql'    => ', ' . implode( ', ', $parts ),
				'values' => $values,
			);
		}

		/**
		 * Turn the split-half aggregates of one bucket into a trend verdict.
		 *
		 * Direction is metric-aware: a rising bounce rate degrades, a rising scroll
		 * depth improves. Below MIN_TREND_HALF_SESSIONS in either half the answer is
		 * `insufficient`, never `stable`.
		 *
		 * @since 1.4.1
		 *
		 * @param array $row        Raw row.
		 * @param array $metric_def Metric definition.
		 * @return array
		 */
		protected function compute_trend( $row, $metric_def ) {
			$trend = array(
				'direction'   => 'insufficient',
				'early_value' => null,
				'late_value'  => null,
				'delta'       => null,
			);

			if ( ! isset( $row['trend_early_sessions'], $row['trend_late_sessions'] ) ) {
				return $trend;
			}

			$early_sessions = (int) $row['trend_early_sessions'];
			$late_sessions  = (int) $row['trend_late_sessions'];

			if ( $early_sessions < self::MIN_TREND_HALF_SESSIONS || $late_sessions < self::MIN_TREND_HALF_SESSIONS ) {
				return $trend;
			}

			if ( 'rate' === $metric_def['type'] ) {
				if ( ! isset( $row['trend_early_numerator'], $row['trend_late_numerator'] ) ) {
					return $trend;
				}
				$early = round( ( (float) $row['trend_early_numerator'] / $early_sessions ) * 100, 2 );
				$late  = round( ( (float) $row['trend_late_numerator'] / $late_sessions ) * 100, 2 );
			} else {
				$early_n = isset( $row['trend_early_n'] ) ? (float) $row['trend_early_n'] : 0.0;
				$late_n  = isset( $row['trend_late_n'] ) ? (float) $row['trend_late_n'] : 0.0;
				if ( $early_n < 1 || $late_n < 1 ) {
					return $trend;
				}
				$early = round( (float) $row['trend_early_sum'] / $early_n, 2 );
				$late  = round( (float) $row['trend_late_sum'] / $late_n, 2 );
			}

			$delta = round( $late - $early, 2 );

			$trend['early_value'] = $early;
			$trend['late_value']  = $late;
			$trend['delta']       = $delta;
			$trend['direction']   = 'stable';

			// Half the outlier floor: this is a watch signal, not a verdict, so it
			// may fire before the bucket is significant on the full period.
			$threshold = max( self::MIN_RELATIVE_DELTA * abs( $early ), (float) $metric_def['abs_floor'] / 2 );
			if ( abs( $delta ) >= $threshold ) {
				$worse_sign         = ! empty( $metric_def['higher_is_worse'] ) ? 1 : -1;
				$trend['direction'] = ( $worse_sign * $delta ) > 0 ? 'degrading' : 'improving';
			}

			return $trend;
		}

		/**
		 * Run the deviation test for one bucket against its complement.
		 *
		 * Rates use a two-proportion z test, averages a Welch t statistic compared
		 * against the same 1.96 threshold (large-sample normal approximation).
		 *
		 * @param string     $metric         Metric key.
		 * @param array      $metric_def     Metric definition.
		 * @param array      $bucket         Bucket aggregates.
		 * @param array      $complement     Complement aggregates.
		 * @param int        $sessions       Bucket sessions.
		 * @param int        $scope_sessions Scope sessions.
		 * @param float|null $value          Bucket metric value.
		 * @param float|null $comp_value     Complement metric value.
		 * @param float|null $significance_z Significance threshold (combos are stricter).
		 * @return array
		 */
		protected function test_bucket( $metric, $metric_def, $bucket, $complement, $sessions, $scope_sessions, $value, $comp_value, $significance_z = null ) {
			$threshold_z = ( null === $significance_z ) ? self::SIGNIFICANCE_Z : (float) $significance_z;

			// A bucket under the session or share floor was never tested at all: the
			// payload says so instead of letting the UI read "not an outlier" as
			// "this segment is fine".
			$result = array(
				'z'            => null,
				'is_outlier'   => false,
				'rank_score'   => 0.0,
				'insufficient' => $sessions < self::MIN_BUCKET_SESSIONS
					|| $sessions < ( self::MIN_BUCKET_SHARE * $scope_sessions )
					|| (int) $complement['sessions'] < 1,
			);

			if ( null === $value || null === $comp_value ) {
				return $result;
			}

			$delta = $value - $comp_value;

			if ( 'rate' === $metric_def['type'] ) {
				$numerator_key = $this->get_rate_numerator_key( $metric );
				$result['z']   = $this->two_proportion_z(
					(float) $bucket[ $numerator_key ],
					(float) $sessions,
					(float) $complement[ $numerator_key ],
					(float) $complement['sessions']
				);
			} else {
				$stat_key    = 'avg_scroll' === $metric ? 'scroll' : 'time';
				$result['z'] = $this->welch_t(
					(float) $bucket[ $stat_key . '_sum' ],
					(float) $bucket[ $stat_key . '_sq' ],
					(float) $bucket[ $stat_key . '_n' ],
					(float) $complement[ $stat_key . '_sum' ],
					(float) $complement[ $stat_key . '_sq' ],
					(float) $complement[ $stat_key . '_n' ]
				);
			}

			$result['rank_score'] = round( abs( $delta ) * sqrt( max( 1, $sessions ) ), 4 );

			// "unknown" is a tracking gap, not an audience an agency can act on.
			if ( 'unknown' === $bucket['segment_key'] ) {
				return $result;
			}

			if ( $result['insufficient'] ) {
				return $result;
			}

			$threshold = max( self::MIN_RELATIVE_DELTA * abs( $comp_value ), (float) $metric_def['abs_floor'] );
			if ( abs( $delta ) < $threshold ) {
				return $result;
			}

			if ( null === $result['z'] || abs( $result['z'] ) < $threshold_z ) {
				return $result;
			}

			$result['is_outlier'] = true;

			return $result;
		}

		/**
		 * Numerator column backing a rate metric.
		 *
		 * @param string $metric Metric key.
		 * @return string
		 */
		protected function get_rate_numerator_key( $metric ) {
			switch ( $metric ) {
				case 'exit_rate':
					return 'exit_sessions';
				case 'cta_click_rate':
					return 'cta_sessions';
				default:
					return 'bounce_sessions';
			}
		}

		/**
		 * Two-proportion z statistic.
		 *
		 * @param float $x1 Successes in group 1.
		 * @param float $n1 Trials in group 1.
		 * @param float $x2 Successes in group 2.
		 * @param float $n2 Trials in group 2.
		 * @return float|null
		 */
		protected function two_proportion_z( $x1, $n1, $x2, $n2 ) {
			if ( $n1 <= 0 || $n2 <= 0 ) {
				return null;
			}

			$pooled = ( $x1 + $x2 ) / ( $n1 + $n2 );
			$se     = sqrt( $pooled * ( 1 - $pooled ) * ( ( 1 / $n1 ) + ( 1 / $n2 ) ) );
			if ( $se <= 0 ) {
				return null;
			}

			return round( ( ( $x1 / $n1 ) - ( $x2 / $n2 ) ) / $se, 4 );
		}

		/**
		 * Welch t statistic from sums, sums of squares, and counts.
		 *
		 * @param float $sum1 Sum group 1.
		 * @param float $sq1  Sum of squares group 1.
		 * @param float $n1   Count group 1.
		 * @param float $sum2 Sum group 2.
		 * @param float $sq2  Sum of squares group 2.
		 * @param float $n2   Count group 2.
		 * @return float|null
		 */
		protected function welch_t( $sum1, $sq1, $n1, $sum2, $sq2, $n2 ) {
			if ( $n1 < 2 || $n2 < 2 ) {
				return null;
			}

			$mean1 = $sum1 / $n1;
			$mean2 = $sum2 / $n2;
			$var1  = max( 0, ( $sq1 - ( ( $sum1 * $sum1 ) / $n1 ) ) / ( $n1 - 1 ) );
			$var2  = max( 0, ( $sq2 - ( ( $sum2 * $sum2 ) / $n2 ) ) / ( $n2 - 1 ) );
			$se    = sqrt( ( $var1 / $n1 ) + ( $var2 / $n2 ) );

			if ( $se <= 0 ) {
				return null;
			}

			return round( ( $mean1 - $mean2 ) / $se, 4 );
		}

		/**
		 * The aggregate SELECT list shared by the scope and dimension queries.
		 *
		 * @param array $cta CTA join fragment.
		 * @return string
		 */
		protected function get_aggregate_select( $cta ) {
			global $wpdb;

			$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';
			$sessions_table  = $wpdb->prefix . 'optibehavior_sessions';

			$bounce_expr = $this->column_exists( $sessions_table, 'is_bounce' )
				? 'COUNT(DISTINCT CASE WHEN s.is_bounce = 1 THEN pv.session_id END)'
				: '0';
			$exit_expr   = $this->column_exists( $pageviews_table, 'exit_page' )
				? 'COUNT(DISTINCT CASE WHEN pv.exit_page = 1 THEN pv.session_id END)'
				: '0';
			$cta_expr    = '' !== $cta['sql'] ? 'COUNT(DISTINCT ob_cta.session_id)' : '0';

			$scroll = $this->column_exists( $pageviews_table, 'scroll_depth' )
				? array( 'COUNT(NULLIF(pv.scroll_depth, 0))', 'SUM(NULLIF(pv.scroll_depth, 0))', 'SUM(POW(NULLIF(pv.scroll_depth, 0), 2))' )
				: array( '0', '0', '0' );
			$time   = $this->column_exists( $pageviews_table, 'time_on_page' )
				? array( 'COUNT(NULLIF(pv.time_on_page, 0))', 'SUM(NULLIF(pv.time_on_page, 0))', 'SUM(POW(NULLIF(pv.time_on_page, 0), 2))' )
				: array( '0', '0', '0' );

			return 'COUNT(DISTINCT pv.session_id) AS sessions, '
				. "{$bounce_expr} AS bounce_sessions, "
				. "{$exit_expr} AS exit_sessions, "
				. "{$cta_expr} AS cta_sessions, "
				. "{$scroll[0]} AS scroll_n, {$scroll[1]} AS scroll_sum, {$scroll[2]} AS scroll_sq, "
				. "{$time[0]} AS time_n, {$time[1]} AS time_sum, {$time[2]} AS time_sq";
		}

		/**
		 * The FROM/JOIN clause shared by the scope and dimension queries.
		 *
		 * @param bool  $with_visitors Whether the visitors table is needed.
		 * @param array $cta           CTA join fragment.
		 * @return string
		 */
		protected function get_from_clause( $with_visitors, $cta ) {
			global $wpdb;

			$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';
			$sessions_table  = $wpdb->prefix . 'optibehavior_sessions';
			$visitors_table  = $wpdb->prefix . 'optibehavior_visitors';

			$sql = "FROM {$pageviews_table} pv INNER JOIN {$sessions_table} s ON pv.session_id = s.id";

			if ( $with_visitors && $this->table_exists( $visitors_table ) && $this->column_exists( $pageviews_table, 'visitor_id' ) ) {
				$sql .= " LEFT JOIN {$visitors_table} v ON pv.visitor_id = v.id";
			}

			return $sql . $cta['sql'];
		}

		/**
		 * Build the optional CTA-session join.
		 *
		 * `cta_click_rate` is measured as "sessions that clicked a CTA on this page
		 * / sessions", which keeps it a proportion on the same denominator as the
		 * other rates so the same z test applies.
		 *
		 * @param array $scope    Resolved scope.
		 * @param bool  $required Whether the caller needs CTA data.
		 * @return array
		 */
		protected function build_cta_join( $scope, $required ) {
			global $wpdb;

			$empty = array(
				'sql'    => '',
				'values' => array(),
			);

			if ( ! $required || $scope['page_id'] < 1 ) {
				return $empty;
			}

			$events_table = $wpdb->prefix . 'optibehavior_events';
			if ( ! $this->table_exists( $events_table ) || ! $this->column_exists( $events_table, 'session_id' ) ) {
				return $empty;
			}

			$page_column = $this->column_exists( $events_table, 'page_id2' ) ? 'page_id2' : 'page_id';
			if ( ! $this->column_exists( $events_table, $page_column ) ) {
				return $empty;
			}

			$cta_case = $this->get_cta_case_expression( 'e' );
			if ( '' === $cta_case ) {
				return $empty;
			}

			$click_events = class_exists( 'Opti_Behavior_Heatmap_Core' )
				? array( Opti_Behavior_Heatmap_Core::CLICK_PC, Opti_Behavior_Heatmap_Core::CLICK_MOBILE )
				: array( 16, 17 );
			$placeholders = implode( ', ', array_fill( 0, count( $click_events ), '%d' ) );

			return array(
				'sql'    => " LEFT JOIN ( SELECT DISTINCT e.session_id FROM {$events_table} e"
					. " WHERE e.event IN ({$placeholders}) AND e.{$page_column} = %d AND ( {$cta_case} ) = 1"
					. ' ) ob_cta ON ob_cta.session_id = pv.session_id',
				'values' => array_merge( array_map( 'intval', $click_events ), array( (int) $scope['page_id'] ) ),
			);
		}

		/**
		 * CTA-safe CASE expression over the events table (no selectors returned).
		 *
		 * @param string $alias Table alias.
		 * @return string
		 */
		protected function get_cta_case_expression( $alias ) {
			global $wpdb;

			$events_table = $wpdb->prefix . 'optibehavior_events';
			$pre          = $alias ? $alias . '.' : '';
			$conditions   = array();

			foreach ( array( 'element_tag', 'element_id', 'element_class' ) as $column ) {
				if ( ! $this->column_exists( $events_table, $column ) ) {
					continue;
				}

				if ( 'element_tag' === $column ) {
					$conditions[] = "LOWER({$pre}{$column}) IN ('a', 'button', 'input')";
				} else {
					// Used inside $wpdb->prepare(): percent signs must be doubled.
					$conditions[] = "LOWER({$pre}{$column}) LIKE '%%cta%%'";
					$conditions[] = "LOWER({$pre}{$column}) LIKE '%%button%%'";
					$conditions[] = "LOWER({$pre}{$column}) LIKE '%%submit%%'";
				}
			}

			if ( empty( $conditions ) ) {
				return '';
			}

			return 'CASE WHEN ' . implode( ' OR ', $conditions ) . ' THEN 1 ELSE 0 END';
		}

		/**
		 * Traffic-source expression matching the source aggregator's entity ids.
		 *
		 * @return string
		 */
		protected function get_source_expression() {
			global $wpdb;

			$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
			$has_utm        = $this->column_exists( $sessions_table, 'utm_source' );
			$has_referrer   = $this->column_exists( $sessions_table, 'referrer' );

			if ( ! $has_utm && ! $has_referrer ) {
				return '';
			}

			$host = "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(s.referrer, 'https://', ''), 'http://', ''), '/', 1), ':', 1))";

			if ( $has_utm && $has_referrer ) {
				return "CASE WHEN s.utm_source IS NOT NULL AND s.utm_source != '' THEN CONCAT('utm:', LOWER(s.utm_source))"
					. " WHEN s.referrer IS NOT NULL AND s.referrer != '' THEN CONCAT('referrer:', {$host})"
					. " ELSE 'direct' END";
			}

			if ( $has_referrer ) {
				return "CASE WHEN s.referrer IS NOT NULL AND s.referrer != '' THEN CONCAT('referrer:', {$host}) ELSE 'direct' END";
			}

			return "COALESCE(NULLIF(CONCAT('utm:', LOWER(s.utm_source)), 'utm:'), 'direct')";
		}

		/**
		 * Cast one aggregate row into the numeric shape the math expects.
		 *
		 * @param array $row Raw row.
		 * @return array
		 */
		protected function normalize_aggregate_row( $row ) {
			return array(
				'segment_key'     => isset( $row['segment_key'] ) ? (string) $row['segment_key'] : '',
				'sessions'        => isset( $row['sessions'] ) ? (int) $row['sessions'] : 0,
				'bounce_sessions' => isset( $row['bounce_sessions'] ) ? (int) $row['bounce_sessions'] : 0,
				'exit_sessions'   => isset( $row['exit_sessions'] ) ? (int) $row['exit_sessions'] : 0,
				'cta_sessions'    => isset( $row['cta_sessions'] ) ? (int) $row['cta_sessions'] : 0,
				'scroll_n'        => isset( $row['scroll_n'] ) ? (float) $row['scroll_n'] : 0.0,
				'scroll_sum'      => isset( $row['scroll_sum'] ) ? (float) $row['scroll_sum'] : 0.0,
				'scroll_sq'       => isset( $row['scroll_sq'] ) ? (float) $row['scroll_sq'] : 0.0,
				'time_n'          => isset( $row['time_n'] ) ? (float) $row['time_n'] : 0.0,
				'time_sum'        => isset( $row['time_sum'] ) ? (float) $row['time_sum'] : 0.0,
				'time_sq'         => isset( $row['time_sq'] ) ? (float) $row['time_sq'] : 0.0,
			);
		}

		/**
		 * Complement aggregates: the whole scope minus one bucket.
		 *
		 * Every field is an additive count or sum, so the complement needs no extra
		 * query. Distinct session counts can overlap across buckets on time-based
		 * dimensions (one session spanning two dayparts), so results are clamped at
		 * zero rather than trusted to be exact there.
		 *
		 * @param array $totals Scope aggregates.
		 * @param array $bucket Bucket aggregates.
		 * @return array
		 */
		protected function subtract_aggregates( $totals, $bucket ) {
			$complement = array( 'segment_key' => '' );

			foreach ( array( 'sessions', 'bounce_sessions', 'exit_sessions', 'cta_sessions', 'scroll_n', 'scroll_sum', 'scroll_sq', 'time_n', 'time_sum', 'time_sq' ) as $field ) {
				$complement[ $field ] = max( 0, $totals[ $field ] - $bucket[ $field ] );
			}

			return $complement;
		}

		/**
		 * Turn aggregates into displayable metric values (rates on a 0-100 scale).
		 *
		 * @param array $aggregate Aggregates.
		 * @param int   $sessions  Denominator.
		 * @return array
		 */
		protected function format_metrics( $aggregate, $sessions ) {
			$sessions = (int) $sessions;

			return array(
				'sessions'       => $sessions,
				'bounce_rate'    => $sessions > 0 ? round( ( $aggregate['bounce_sessions'] / $sessions ) * 100, 2 ) : null,
				'exit_rate'      => $sessions > 0 ? round( ( $aggregate['exit_sessions'] / $sessions ) * 100, 2 ) : null,
				'cta_click_rate' => $sessions > 0 ? round( ( $aggregate['cta_sessions'] / $sessions ) * 100, 2 ) : null,
				'avg_scroll'     => $aggregate['scroll_n'] > 0 ? round( $aggregate['scroll_sum'] / $aggregate['scroll_n'], 2 ) : null,
				'avg_time'       => $aggregate['time_n'] > 0 ? round( $aggregate['time_sum'] / $aggregate['time_n'], 2 ) : null,
			);
		}

		/**
		 * Resolve a display label for one segment bucket.
		 *
		 * @param array  $dimension Dimension definition.
		 * @param string $key       Segment key.
		 * @return string
		 */
		protected function segment_label( $dimension, $key ) {
			$labels = isset( $dimension['labels'] ) && is_array( $dimension['labels'] ) ? $dimension['labels'] : array();
			if ( isset( $labels[ $key ] ) ) {
				return (string) $labels[ $key ];
			}

			if ( 'unknown' === $key ) {
				return __( 'Unknown', 'opti-behavior' );
			}

			if ( 'direct' === $key ) {
				return __( 'Direct', 'opti-behavior' );
			}

			if ( 0 === strpos( $key, 'utm:' ) ) {
				return substr( $key, 4 );
			}

			if ( 0 === strpos( $key, 'referrer:' ) ) {
				return substr( $key, 9 );
			}

			return $key;
		}

		/**
		 * Build the shared scope WHERE clause.
		 *
		 * @param array $scope        Resolved scope.
		 * @param bool  $exclude_spam Spam policy.
		 * @param array $range        Optional {from,to} override (time series).
		 * @return array
		 */
		protected function build_scope_where( $scope, $exclude_spam, $range = null ) {
			global $wpdb;

			$empty = array(
				'sql'    => '',
				'values' => array(),
			);

			$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';
			if ( ! $this->column_exists( $pageviews_table, 'view_time' ) ) {
				return $empty;
			}

			$from = is_array( $range ) && ! empty( $range['from'] ) ? $this->normalize_date( $range['from'] ) : $scope['date_from'];
			$to   = is_array( $range ) && ! empty( $range['to'] ) ? $this->normalize_date( $range['to'] ) : $scope['date_to'];

			$sql    = 'pv.view_time BETWEEN %s AND %s';
			$values = array( $from . ' 00:00:00', $to . ' 23:59:59' );

			if ( $scope['page_id'] > 0 && $this->column_exists( $pageviews_table, 'page_id' ) ) {
				$sql     .= ' AND pv.page_id = %d';
				$values[] = $scope['page_id'];
			} elseif ( '' !== $scope['page_url'] && $this->column_exists( $pageviews_table, 'url' ) ) {
				$sql     .= ' AND pv.url = %s';
				$values[] = $scope['page_url'];
			} else {
				return $empty;
			}

			if ( $exclude_spam && class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
				$spam_where = Opti_Behavior_Stats_Spam_Filter::session_sql( 's', 'AND', true );
				if ( '' !== $spam_where ) {
					$sql .= $spam_where;
				}
			}

			return array(
				'sql'    => $sql,
				'values' => $values,
			);
		}

		/**
		 * Build the transient key for one scope.
		 *
		 * @param array $insight      Insight payload.
		 * @param array $scope        Resolved scope.
		 * @param bool  $exclude_spam Spam policy.
		 * @return string
		 */
		protected function get_cache_key( $insight, $scope, $exclude_spam ) {
			$insight_id = isset( $insight['id'] ) ? absint( $insight['id'] ) : 0;
			if ( $insight_id < 1 ) {
				return '';
			}

			return self::CACHE_PREFIX . md5(
				implode(
					'|',
					array(
						self::SCHEMA_VERSION,
						self::get_cache_salt(),
						// The builder class is part of the key: a Free payload and a
						// Pro payload of the same insight never share a transient, so a
						// licence change is never answered from the other tier's cache.
						get_class( $this ),
						$insight_id,
						$exclude_spam ? '1' : '0',
						$scope['date_from'],
						$scope['date_to'],
					)
				)
			);
		}

		/**
		 * Resolve the scope (page + period) an insight was detected on.
		 *
		 * Prefers the persisted correlation scope so a story and its children
		 * always answer "where" for the same population, then falls back to the
		 * insight's own entity/metric context.
		 *
		 * @param array $insight Insight payload.
		 * @return array
		 */
		public function resolve_scope( $insight ) {
			$insight     = is_array( $insight ) ? $insight : array();
			$metrics     = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
			$context     = isset( $metrics['entity_context'] ) && is_array( $metrics['entity_context'] ) ? $metrics['entity_context'] : array();
			$correlation = isset( $insight['correlation'] ) && is_array( $insight['correlation'] ) ? $insight['correlation'] : array();
			$story_scope = isset( $correlation['scope'] ) && is_array( $correlation['scope'] ) ? $correlation['scope'] : array();

			$entity_type = isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '';
			$entity_id   = isset( $insight['entity_id'] ) && is_scalar( $insight['entity_id'] ) ? (string) $insight['entity_id'] : '';

			$page_id = 0;
			foreach ( array( $story_scope, $metrics, $context ) as $bag ) {
				if ( isset( $bag['page_id'] ) && is_numeric( $bag['page_id'] ) && absint( $bag['page_id'] ) > 0 ) {
					$page_id = absint( $bag['page_id'] );
					break;
				}
			}
			if ( 0 === $page_id && 'page' === $entity_type && is_numeric( $entity_id ) ) {
				$page_id = absint( $entity_id );
			}

			$page_url   = '';
			$candidates = array(
				isset( $story_scope['page_url_full'] ) ? $story_scope['page_url_full'] : '',
				isset( $metrics['page_url'] ) ? $metrics['page_url'] : '',
				isset( $context['view_url'] ) ? $context['view_url'] : '',
				$entity_id,
			);
			foreach ( $candidates as $candidate ) {
				if ( ! is_scalar( $candidate ) ) {
					continue;
				}
				$candidate = trim( (string) $candidate );
				if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
					$page_url = esc_url_raw( $candidate );
					break;
				}
			}

			return array(
				'entity_type'  => $entity_type,
				'entity_id'    => $entity_id,
				'entity_label' => isset( $insight['entity_label'] ) ? sanitize_text_field( (string) $insight['entity_label'] ) : '',
				'page_id'      => $page_id,
				'page_url'     => $page_url,
				'date_from'    => $this->normalize_date( isset( $insight['date_from'] ) ? $insight['date_from'] : '' ),
				'date_to'      => $this->normalize_date( isset( $insight['date_to'] ) ? $insight['date_to'] : '' ),
			);
		}

		/**
		 * Resolve the spam policy for this request.
		 *
		 * @param array $args Request args.
		 * @return bool
		 */
		protected function resolve_exclude_spam( $args ) {
			if ( array_key_exists( 'exclude_spam', $args ) && null !== $args['exclude_spam'] ) {
				return (bool) $args['exclude_spam'];
			}

			if ( array_key_exists( 'opti_behavior_exclude_spam', $GLOBALS ) ) {
				return (bool) $GLOBALS['opti_behavior_exclude_spam'];
			}

			if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
				return Opti_Behavior_Stats_Spam_Filter::is_enabled();
			}

			return true;
		}

		/**
		 * Normalize a date to Y-m-d.
		 *
		 * @param string $date Date value.
		 * @return string
		 */
		protected function normalize_date( $date ) {
			$timestamp = is_scalar( $date ) ? strtotime( (string) $date ) : false;

			return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : gmdate( 'Y-m-d' );
		}

		/**
		 * Build an unavailable matrix payload.
		 *
		 * @param string $reason       Reason key.
		 * @param array  $scope        Resolved scope.
		 * @param bool   $exclude_spam Spam policy.
		 * @param int    $total        Known denominator.
		 * @return array
		 */
		protected function unavailable( $reason, $scope, $exclude_spam, $total = 0 ) {
			return array(
				'version'               => self::SCHEMA_VERSION,
				'available'             => false,
				'reason'                => sanitize_key( $reason ),
				'scope'                 => $scope,
				'primary_metric'        => '',
				'metric_label'          => '',
				'metric_unit'           => '',
				'metric_type'           => '',
				'scope_totals'          => array(),
				'scope_sessions'        => max( 0, (int) $total ),
				'total_sessions'        => max( 0, (int) $total ),
				// Carried even here — especially here: "12 of the 30 sessions this
				// analysis needs" is an answer, "not enough data" is not.
				'min_scope_sessions'    => self::MIN_SCOPE_SESSIONS,
				'exclude_spam'          => (bool) $exclude_spam,
				'dimension_count'       => 0,
				'combo_dimension_count' => 0,
				'dimensions'            => array(),
				'outliers'              => array(),
				'uniform'               => false,
				'top_segment'           => null,
				'traffic_mix'           => null,
			);
		}

		/**
		 * Check whether a table exists.
		 *
		 * @param string $table Table name.
		 * @return bool
		 */
		protected function table_exists( $table ) {
			global $wpdb;

			if ( ! array_key_exists( $table, $this->table_cache ) ) {
				$this->table_cache[ $table ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			}

			return $this->table_cache[ $table ];
		}

		/**
		 * Check whether a table column exists.
		 *
		 * @param string $table  Table name.
		 * @param string $column Column name.
		 * @return bool
		 */
		protected function column_exists( $table, $column ) {
			global $wpdb;

			$key = $table . '.' . $column;
			if ( ! array_key_exists( $key, $this->column_cache ) ) {
				$this->column_cache[ $key ] = (bool) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
						DB_NAME,
						$table,
						$column
					)
				);
			}

			return $this->column_cache[ $key ];
		}
	}
}
