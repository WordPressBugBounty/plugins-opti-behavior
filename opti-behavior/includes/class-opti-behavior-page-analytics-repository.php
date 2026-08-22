<?php
/**
 * Canonical Page Analytics Repository.
 *
 * Resolves WordPress/page-table identity and provides one Free-plugin metric
 * contract for displays that need page-level analytics.
 *
 * @package opti-behavior
 * @since   1.2.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are built from $wpdb->prefix and fixed plugin table suffixes.

/**
 * Canonical page analytics repository.
 *
 * This class is intentionally Free-owned. Pro can override authoritative
 * session/page metrics by filtering `opti_behavior_page_analytics_metrics`.
 *
 * @since 1.2.8
 */
final class Opti_Behavior_Page_Analytics_Repository {

	const CONTRACT_VERSION    = 'free-page-analytics-v1';
	const TRAFFIC_SCOPE_HUMAN = 'human';
	const TRAFFIC_SCOPE_ALL   = 'all';
	const TRAFFIC_SCOPE_BOT   = 'bot';

	/**
	 * WordPress database adapter.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Plugin table names.
	 *
	 * @var array
	 */
	private $tables = array();

	/**
	 * Cached table-existence checks.
	 *
	 * @var array
	 */
	private $table_exists_cache = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb = $wpdb;
		$this->tables = array(
			'pages'     => $wpdb->prefix . 'optibehavior_pages',
			'pageviews' => $wpdb->prefix . 'optibehavior_pageviews',
			'sessions'  => $wpdb->prefix . 'optibehavior_sessions',
			'visitors'  => $wpdb->prefix . 'optibehavior_visitors',
			'events'    => $wpdb->prefix . 'optibehavior_events',
		);
	}

	/**
	 * Return canonical metrics for one page identity.
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type int    $post_id       Optional WordPress post or revision ID.
	 *     @type string $page_url      Optional page URL.
	 *     @type string $period        today|last7days|last30days|all|custom.
	 *     @type string $start_date    Custom start date.
	 *     @type string $end_date      Custom end date.
	 *     @type string $traffic_scope human|all|bot.
	 *     @type bool   $prefer_pro    Whether Pro may replace metrics through the filter.
	 * }
	 * @return array
	 */
	public function get_page_metrics( array $args ) {
		$identity      = $this->resolve_page_identity( $args );
		$period        = isset( $args['period'] ) ? sanitize_key( $args['period'] ) : $this->get_default_period();
		$date_range    = $this->normalize_date_range(
			$period,
			isset( $args['start_date'] ) ? $args['start_date'] : '',
			isset( $args['end_date'] ) ? $args['end_date'] : ''
		);
		$traffic_scope = $this->normalize_traffic_scope( isset( $args['traffic_scope'] ) ? $args['traffic_scope'] : self::TRAFFIC_SCOPE_HUMAN );

		// Optional precise lower bound on pv.view_time (e.g. a heatmap page's
		// "Delete Heatmap Data" reset floor). Threaded into $date_range so every
		// sub-query in query_free_metrics() (sessions/devices/visitor types/…)
		// honours it via build_date_sql()/get_date_params(). Empty => no-op.
		if ( ! empty( $args['min_view_time'] ) ) {
			$date_range['min_view_time'] = (string) $args['min_view_time'];
		}

		$metrics       = $this->get_empty_metrics( $identity, $date_range, $traffic_scope );

		if ( ! empty( $identity['page_ids'] ) && $this->has_required_metric_tables() ) {
			$free_metrics = $this->query_free_metrics( $identity['page_ids'], $date_range, $traffic_scope );
			$metrics      = array_merge( $metrics, $free_metrics );
			$metrics['source'] = 'free_pageviews';
		}

		$metrics = $this->with_formatted_metrics( $metrics );

		$context = array(
			'args'           => $args,
			'identity'       => $identity,
			'date_range'     => $date_range,
			'traffic_scope'  => $traffic_scope,
			'contract'       => self::CONTRACT_VERSION,
			'free_source'    => 'free_pageviews',
			'repository'     => $this,
		);

		if ( ! isset( $args['prefer_pro'] ) || $args['prefer_pro'] ) {
			$metrics = apply_filters( 'opti_behavior_page_analytics_metrics', $metrics, $context );
			$metrics = $this->with_formatted_metrics( $metrics );
		}

		return $metrics;
	}

	/**
	 * Canonical human pageview-session metrics for one Opti page identity, keyed
	 * by an internal optibehavior_pages.id (the same id space heatmap surfaces
	 * use). Resolves the page's full content group (WordPress post_id when the
	 * page is post-backed, else its tracked URL) and reuses the exact same
	 * universe the post-edit metabox and frontend stats bar read — distinct
	 * human, spam-excluded `optibehavior_pageviews.session_id` across the group.
	 *
	 * Heatmap surfaces (detail pill, device chips) call this so their headline
	 * session count matches bar/metabox/dashboard for the same page (master
	 * decision 2026-08-13: one canonical session universe everywhere).
	 *
	 * @param int   $page_id Internal optibehavior_pages.id.
	 * @param array $args    Optional overrides: period, start_date, end_date,
	 *                       traffic_scope. Defaults to human scope, Free source
	 *                       (prefer_pro=false — Pro's session override already
	 *                       resolves to the same human universe post-BUG-2).
	 * @return array|null Metric block (see get_page_metrics()), or null when the
	 *                    page id resolves to no known content page.
	 */
	public function get_canonical_metrics_for_page_id( $page_id, array $args = array() ) {
		$page_id = absint( $page_id );
		if ( ! $page_id || ! $this->table_exists( 'pages' ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT post_id, url FROM {$this->tables['pages']} WHERE id = %d",
				$page_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$resolver = array();
		if ( ! empty( $row['post_id'] ) ) {
			$resolver['post_id'] = absint( $row['post_id'] );
		} elseif ( ! empty( $row['url'] ) ) {
			$resolver['page_url'] = (string) $row['url'];
		} else {
			return null;
		}

		$args = array_merge(
			array(
				'traffic_scope' => self::TRAFFIC_SCOPE_HUMAN,
				'prefer_pro'    => false,
			),
			$args,
			$resolver
		);

		return $this->get_page_metrics( $args );
	}

	/**
	 * Batched, group-aware canonical session counts for a set of Opti page ids —
	 * the efficient list-surface variant of get_canonical_metrics_for_page_id().
	 *
	 * The Heatmaps list renders one row per heatmap page id but the canonical
	 * session count is a CONTENT-page metric (distinct human sessions across all
	 * a post's URL variants). Summing per-page-id distinct counts over-counts
	 * sessions that touch more than one variant (verified: homepage variants sum
	 * to 51 while the true group distinct is 45), so this groups the DISTINCT
	 * session count by WordPress post_id in a single query, then maps every input
	 * page id to its group total. URL-only pages (post_id = 0, no WP post) are
	 * grouped by their canonical base URL so AB / tracked-query variants of the
	 * same content page (e.g. the homepage's opti_ab_visual_editor variants)
	 * aggregate into one DISTINCT session count — matching the detail pill, which
	 * resolves such pages by URL across the whole variant group.
	 *
	 * Returns the SAME number the detail pill / bar / metabox show for the same
	 * page, so list == pill == bar == metabox and the Sessions column can sort on
	 * the displayed value.
	 *
	 * @param int[]  $page_ids     Internal optibehavior_pages.id values (list rows).
	 * @param string $period       Standard period slug (default 'all').
	 * @param string $start_date   Custom start (period = custom).
	 * @param string $end_date     Custom end (period = custom).
	 * @param array  $reset_floors Optional "Delete Heatmap Data" reset floors, map of
	 *                             input page id => raw mysql datetime. For each floored
	 *                             page the entry is recomputed with pv.view_time >= floor
	 *                             (via get_canonical_metrics_for_page_id(), so the floor
	 *                             applies across the page's whole content group exactly
	 *                             like the detail pill) and the pre-floor all-time count
	 *                             is preserved in 'sessions_all_time'. Defaults to no
	 *                             floors (backwards compatible — unchanged behaviour).
	 * @return array<int,array{sessions:int,desktop:int,mobile:int,tablet:int,sessions_all_time?:int}> Map keyed by page id.
	 */
	public function get_group_session_counts_for_pages( array $page_ids, $period = 'all', $start_date = '', $end_date = '', array $reset_floors = array() ) {
		$result   = array();
		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
		if ( empty( $page_ids ) || ! $this->table_exists( 'pages' ) || ! $this->has_required_metric_tables() ) {
			return $result;
		}

		$date_range = $this->normalize_date_range( $period, $start_date, $end_date );
		$scope      = $this->get_traffic_scope_sql( 's', self::TRAFFIC_SCOPE_HUMAN );
		$date_sql   = $this->build_date_sql( 'pv.view_time', $date_range );
		$date_prm   = $this->get_date_params( $date_range );
		$has_v      = $this->table_exists( 'visitors' );

		// Resolve each page id to its content-group key: post_id when post-backed,
		// else the page id itself (a standalone url-only group).
		$id_ph      = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		$id_rows    = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, post_id FROM {$this->tables['pages']} WHERE id IN ({$id_ph})",
				...$page_ids
			),
			ARRAY_A
		);
		$id_to_post = array();
		$post_ids   = array();
		$url_only   = array();
		foreach ( $id_rows as $r ) {
			$id  = absint( $r['id'] );
			$pid = absint( $r['post_id'] );
			if ( $pid > 0 ) {
				$id_to_post[ $id ] = $pid;
				$post_ids[ $pid ]  = $pid;
			} else {
				$url_only[] = $id;
			}
		}

		$post_totals  = array();
		$post_devices = array();
		if ( ! empty( $post_ids ) ) {
			$post_ids = array_values( $post_ids );
			$pph      = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			$params   = array_merge( $post_ids, $date_prm );

			$tot = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT dp.gkey, COUNT(DISTINCT dp.session_id) AS c
				FROM (
					SELECT DISTINCT pg.post_id AS gkey, pv.session_id
					FROM {$this->tables['pageviews']} pv
					JOIN {$this->tables['pages']} pg ON pg.id = pv.page_id
					WHERE pg.post_id IN ({$pph}){$date_sql}
				) dp
				LEFT JOIN {$this->tables['sessions']} s ON s.id = dp.session_id
				WHERE 1=1{$scope['sql']}
				GROUP BY dp.gkey",
					...$params
				),
				ARRAY_A
			);
			foreach ( $tot as $t ) {
				$post_totals[ absint( $t['gkey'] ) ] = absint( $t['c'] );
			}

			if ( $has_v ) {
				$dev = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT dp.gkey, COALESCE(NULLIF(LOWER(v.device_type), ''), 'unknown') AS dt, COUNT(DISTINCT dp.session_id) AS c
					FROM (
						SELECT DISTINCT pg.post_id AS gkey, pv.session_id, pv.visitor_id
						FROM {$this->tables['pageviews']} pv
						JOIN {$this->tables['pages']} pg ON pg.id = pv.page_id
						WHERE pg.post_id IN ({$pph}){$date_sql}
					) dp
					LEFT JOIN {$this->tables['sessions']} s ON s.id = dp.session_id
					LEFT JOIN {$this->tables['visitors']} v ON v.id = dp.visitor_id
					WHERE 1=1{$scope['sql']}
					GROUP BY dp.gkey, dt",
						...$params
					),
					ARRAY_A
				);
				foreach ( $dev as $d ) {
					$g      = absint( $d['gkey'] );
					$bucket = $this->fold_device_bucket( $d['dt'] );
					if ( ! isset( $post_devices[ $g ] ) ) {
						$post_devices[ $g ] = array( 'desktop' => 0, 'mobile' => 0, 'tablet' => 0 );
					}
					$post_devices[ $g ][ $bucket ] += absint( $d['c'] );
				}
			}
		}

		// URL-only pages (post_id = 0) are AB / tracked-query URL variants that
		// share a canonical base URL (e.g. the homepage's opti_ab_visual_editor
		// variants). The detail pill resolves such a page by URL and counts the
		// DISTINCT human session universe across every sibling variant, so summing
		// per-page-id here would UNDER report (a single page id) or, once siblings
		// are added, OVER count shared sessions. Group by canonical base URL and
		// count DISTINCT sessions once across the whole variant group so the list
		// row equals the pill for the same content page.
		$url_totals  = array(); // base_key => session count.
		$url_devices = array(); // base_key => device bucket map.
		$id_to_base  = array(); // input url-only page id => base_key.
		if ( ! empty( $url_only ) ) {
			// Resolve each input url-only id to its canonical base URL key.
			$uph   = implode( ',', array_fill( 0, count( $url_only ), '%d' ) );
			$urows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT id, url FROM {$this->tables['pages']} WHERE id IN ({$uph})",
					...$url_only
				),
				ARRAY_A
			);

			$base_members  = array(); // base_key => array of member page ids.
			$like_bases    = array(); // base_key => untrailingslashed base for sibling LIKE fetch.
			foreach ( $urows as $ur ) {
				$id   = absint( $ur['id'] );
				$base = $this->untrailingslash_url( (string) strtok( (string) $ur['url'], '?#' ) );
				if ( strlen( $base ) < 8 ) {
					// No usable base URL: keep the page as its own standalone group.
					$key = 'id:' . $id;
				} else {
					$key                = $base;
					$like_bases[ $key ] = $base;
				}
				$id_to_base[ $id ] = $key;
				if ( ! isset( $base_members[ $key ] ) ) {
					$base_members[ $key ] = array();
				}
				$base_members[ $key ][ $id ] = $id; // input id is always a member.
			}

			// Fetch sibling page ids that share any of these base URLs (exact base,
			// trailing-slash base, or a tracked-query/AB variant "base?..."), then
			// assign each sibling to the base key its own URL normalizes to.
			if ( ! empty( $like_bases ) ) {
				$conds  = array();
				$params = array();
				foreach ( $like_bases as $b ) {
					$bslash = $this->trailingslash_url( $b );
					foreach ( array( 'url', 'url2' ) as $col ) {
						$conds[]  = "{$col} = %s";
						$params[] = $b;
						$conds[]  = "{$col} = %s";
						$params[] = $bslash;
						$conds[]  = "{$col} LIKE %s";
						$params[] = $this->wpdb->esc_like( $b ) . '?%';
						$conds[]  = "{$col} LIKE %s";
						$params[] = $this->wpdb->esc_like( $bslash ) . '?%';
					}
				}
				$sibs = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT id, url, url2 FROM {$this->tables['pages']}
						WHERE " . implode( ' OR ', $conds ) . '
						LIMIT 2000',
						...$params
					),
					ARRAY_A
				);
				foreach ( $sibs as $sr ) {
					$sid   = absint( $sr['id'] );
					$sbase = $this->untrailingslash_url( (string) strtok( (string) $sr['url'], '?#' ) );
					if ( ! isset( $base_members[ $sbase ] ) && ! empty( $sr['url2'] ) ) {
						$sbase = $this->untrailingslash_url( (string) strtok( (string) $sr['url2'], '?#' ) );
					}
					if ( isset( $base_members[ $sbase ] ) ) {
						$base_members[ $sbase ][ $sid ] = $sid;
					}
				}
			}

			// Assign a dense group index per base key. Single-member groups (the
			// overwhelming majority — pages without AB/tracked-query variants)
			// need no cross-page session dedupe, so they are served by plain
			// per-page GROUP BY queries that ride the covering
			// idx_pv_page_time_sess index (see the optimizer hint below). Only
			// multi-member variant groups go through the CASE-map query, which
			// stays tiny. At 100k pageviews x 500 pages the all-CASE form
			// measured ~5 s per grouped COUNT(DISTINCT); the split form is ~1 s
			// total + ~2.5 s device.
			$gidx_of_key  = array();
			$single_gidx  = array(); // page_id => gidx (single-member groups).
			$case_when    = array();
			$case_prm     = array();
			$multi_member = array(); // member page ids of multi-member groups.
			$i            = 0;
			foreach ( $base_members as $key => $members ) {
				$gidx_of_key[ $key ] = $i;
				if ( 1 === count( $members ) ) {
					$single_gidx[ (int) reset( $members ) ] = $i;
				} else {
					foreach ( $members as $mid ) {
						$case_when[]    = 'WHEN %d THEN %d';
						$case_prm[]     = $mid;
						$case_prm[]     = $i;
						$multi_member[] = $mid;
					}
				}
				++$i;
			}
			$multi_member = array_values( array_unique( $multi_member ) );

			// Optimizer hint: MySQL 8 misestimates the pageviews range and picks
			// a non-covering index for the dated scan (row lookup per entry —
			// ~3x slower at 100k rows). Unknown-index hints degrade to a warning
			// (MySQL) or a plain comment (MariaDB), so this is safe on installs
			// where the background index worker has not created it yet.
			$pv_hint = '/*+ INDEX(pv idx_pv_page_time_sess) */ ';

			$gidx_total = array();
			$gidx_dev   = array();

			if ( ! empty( $single_gidx ) ) {
				$single_ids = array_keys( $single_gidx );
				$sph        = implode( ',', array_fill( 0, count( $single_ids ), '%d' ) );

				$tot = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT {$pv_hint}pv.page_id, COUNT(DISTINCT pv.session_id) AS c
					FROM {$this->tables['pageviews']} pv
					LEFT JOIN {$this->tables['sessions']} s ON s.id = pv.session_id
					WHERE pv.page_id IN ({$sph}){$date_sql}{$scope['sql']}
					GROUP BY pv.page_id",
						...array_merge( $single_ids, $date_prm )
					),
					ARRAY_A
				);
				foreach ( $tot as $t ) {
					$pid = (int) $t['page_id'];
					if ( isset( $single_gidx[ $pid ] ) ) {
						$gidx_total[ $single_gidx[ $pid ] ] = absint( $t['c'] );
					}
				}

				if ( $has_v ) {
					// Dedupe to one row per (page, session) first — COUNT(*) on
					// the deduped set replaces the expensive outer
					// COUNT(DISTINCT) sort.
					$dev = $this->wpdb->get_results(
						$this->wpdb->prepare(
							"SELECT dp.page_id, COALESCE(NULLIF(LOWER(v.device_type), ''), 'unknown') AS dt, COUNT(*) AS c
						FROM (
							SELECT {$pv_hint}pv.page_id, pv.session_id, MIN(pv.visitor_id) AS visitor_id
							FROM {$this->tables['pageviews']} pv
							WHERE pv.page_id IN ({$sph}){$date_sql}
							GROUP BY pv.page_id, pv.session_id
						) dp
						LEFT JOIN {$this->tables['sessions']} s ON s.id = dp.session_id
						LEFT JOIN {$this->tables['visitors']} v ON v.id = dp.visitor_id
						WHERE 1=1{$scope['sql']}
						GROUP BY dp.page_id, dt",
							...array_merge( $single_ids, $date_prm )
						),
						ARRAY_A
					);
					foreach ( $dev as $d ) {
						$pid = (int) $d['page_id'];
						if ( ! isset( $single_gidx[ $pid ] ) ) {
							continue;
						}
						$g      = $single_gidx[ $pid ];
						$bucket = $this->fold_device_bucket( $d['dt'] );
						if ( ! isset( $gidx_dev[ $g ] ) ) {
							$gidx_dev[ $g ] = array( 'desktop' => 0, 'mobile' => 0, 'tablet' => 0 );
						}
						$gidx_dev[ $g ][ $bucket ] += absint( $d['c'] );
					}
				}
			}

			if ( ! empty( $multi_member ) ) {
				$mph = implode( ',', array_fill( 0, count( $multi_member ), '%d' ) );
				// Cross-page dedupe via the CASE map, evaluated on the DISTINCT
				// (page_id, session_id) pairs of the (few) variant-group members.
				$case_sql = 'CASE dp.page_id ' . implode( ' ', $case_when ) . ' END';

				$tot = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT {$case_sql} AS gidx, COUNT(DISTINCT dp.session_id) AS c
					FROM (
						SELECT {$pv_hint}DISTINCT pv.page_id, pv.session_id
						FROM {$this->tables['pageviews']} pv
						WHERE pv.page_id IN ({$mph}){$date_sql}
					) dp
					LEFT JOIN {$this->tables['sessions']} s ON s.id = dp.session_id
					WHERE 1=1{$scope['sql']}
					GROUP BY gidx",
						...array_merge( $case_prm, $multi_member, $date_prm )
					),
					ARRAY_A
				);
				foreach ( $tot as $t ) {
					$gidx_total[ (int) $t['gidx'] ] = absint( $t['c'] );
				}

				if ( $has_v ) {
					$dev = $this->wpdb->get_results(
						$this->wpdb->prepare(
							"SELECT {$case_sql} AS gidx, COALESCE(NULLIF(LOWER(v.device_type), ''), 'unknown') AS dt, COUNT(DISTINCT dp.session_id) AS c
						FROM (
							SELECT {$pv_hint}DISTINCT pv.page_id, pv.session_id, pv.visitor_id
							FROM {$this->tables['pageviews']} pv
							WHERE pv.page_id IN ({$mph}){$date_sql}
						) dp
						LEFT JOIN {$this->tables['sessions']} s ON s.id = dp.session_id
						LEFT JOIN {$this->tables['visitors']} v ON v.id = dp.visitor_id
						WHERE 1=1{$scope['sql']}
						GROUP BY gidx, dt",
							...array_merge( $case_prm, $multi_member, $date_prm )
						),
						ARRAY_A
					);
					foreach ( $dev as $d ) {
						$g      = (int) $d['gidx'];
						$bucket = $this->fold_device_bucket( $d['dt'] );
						if ( ! isset( $gidx_dev[ $g ] ) ) {
							$gidx_dev[ $g ] = array( 'desktop' => 0, 'mobile' => 0, 'tablet' => 0 );
						}
						$gidx_dev[ $g ][ $bucket ] += absint( $d['c'] );
					}
				}
			}

			foreach ( $gidx_of_key as $key => $gidx ) {
				$url_totals[ $key ]  = isset( $gidx_total[ $gidx ] ) ? $gidx_total[ $gidx ] : 0;
				$url_devices[ $key ] = isset( $gidx_dev[ $gidx ] ) ? $gidx_dev[ $gidx ] : array( 'desktop' => 0, 'mobile' => 0, 'tablet' => 0 );
			}
		}

		$empty_dev = array( 'desktop' => 0, 'mobile' => 0, 'tablet' => 0 );
		foreach ( $page_ids as $id ) {
			if ( isset( $id_to_post[ $id ] ) ) {
				$g        = $id_to_post[ $id ];
				$sessions = isset( $post_totals[ $g ] ) ? $post_totals[ $g ] : 0;
				$devices  = isset( $post_devices[ $g ] ) ? $post_devices[ $g ] : $empty_dev;
			} else {
				$key      = isset( $id_to_base[ $id ] ) ? $id_to_base[ $id ] : ( 'id:' . $id );
				$sessions = isset( $url_totals[ $key ] ) ? $url_totals[ $key ] : 0;
				$devices  = isset( $url_devices[ $key ] ) ? $url_devices[ $key ] : $empty_dev;
			}

			$result[ $id ] = array(
				'sessions' => (int) $sessions,
				'desktop'  => (int) $devices['desktop'],
				'mobile'   => (int) $devices['mobile'],
				'tablet'   => (int) $devices['tablet'],
			);
		}

		// "Delete Heatmap Data" reset floors: recompute the (rare) floored pages
		// individually with the pv.view_time >= floor bound — the per-page resolver
		// applies the floor across the page's full content group, exactly like the
		// detail pill — and keep the batched all-time count alongside so list
		// surfaces can render the "valid / total" pair.
		foreach ( $reset_floors as $floor_pid => $floor ) {
			$floor_pid = absint( $floor_pid );
			$floor     = (string) $floor;
			if ( ! $floor_pid || '' === $floor || ! isset( $result[ $floor_pid ] ) ) {
				continue;
			}

			$metrics = $this->get_canonical_metrics_for_page_id(
				$floor_pid,
				array(
					'period'        => $period,
					'start_date'    => $start_date,
					'end_date'      => $end_date,
					'min_view_time' => $floor,
				)
			);
			if ( ! is_array( $metrics ) || ! isset( $metrics['sessions'] ) ) {
				continue;
			}

			$all_time = (int) $result[ $floor_pid ]['sessions'];
			$total    = (int) $metrics['sessions'];
			$devices  = isset( $metrics['devices'] ) && is_array( $metrics['devices'] ) ? $metrics['devices'] : array();
			$mobile   = isset( $devices['mobile'] ) ? (int) $devices['mobile'] : 0;
			$tablet   = isset( $devices['tablet'] ) ? (int) $devices['tablet'] : 0;

			$result[ $floor_pid ] = array(
				'sessions'          => $total,
				// Desktop absorbs unknown-device sessions and is reconciled so the
				// buckets sum to the floored total (same fold as the device chips).
				'desktop'           => max( 0, $total - $mobile - $tablet ),
				'mobile'            => $mobile,
				'tablet'            => $tablet,
				'sessions_all_time' => $all_time,
			);
		}

		return $result;
	}

	/**
	 * Fold a raw device_type token into the three heatmap device buckets. Unknown
	 * / unrecognized device types collapse into 'desktop' (same rule the heatmap
	 * file bucketer uses) so device chips sum toward the session total instead of
	 * silently dropping unknown-device sessions.
	 *
	 * @param string $device_type Raw device type.
	 * @return string 'desktop'|'mobile'|'tablet'.
	 */
	private function fold_device_bucket( $device_type ) {
		$device_type = strtolower( (string) $device_type );
		if ( 'mobile' === $device_type || 'phone' === $device_type ) {
			return 'mobile';
		}
		if ( 'tablet' === $device_type ) {
			return 'tablet';
		}
		return 'desktop';
	}

	/**
	 * Resolve post/revision/URL to canonical Opti page IDs.
	 *
	 * Display reads never create page rows.
	 *
	 * @param array $args Resolver arguments.
	 * @return array
	 */
	public function resolve_page_identity( array $args ) {
		$request_post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		$wp_post_id      = $request_post_id;
		$is_revision     = false;

		if ( $request_post_id ) {
			$revision_parent = wp_is_post_revision( $request_post_id );
			if ( $revision_parent ) {
				$wp_post_id  = absint( $revision_parent );
				$is_revision = true;
			} else {
				$post = get_post( $request_post_id );
				if ( $post && 'revision' === $post->post_type && $post->post_parent ) {
					$wp_post_id  = absint( $post->post_parent );
					$is_revision = true;
				}
			}
		}

		$page_url = '';
		if ( ! empty( $args['page_url'] ) ) {
			$page_url = esc_url_raw( wp_unslash( $args['page_url'] ) );
		} elseif ( ! empty( $args['url'] ) ) {
			$page_url = esc_url_raw( wp_unslash( $args['url'] ) );
		}

		$canonical_url = $page_url;
		if ( $wp_post_id ) {
			$permalink = get_permalink( $wp_post_id );
			if ( $permalink ) {
				$canonical_url = $permalink;
			}
		}

		$url_variants = $this->build_url_variants( $canonical_url, $page_url );
		$rows         = array();
		$match_order  = array();

		if ( $this->table_exists( 'pages' ) ) {
			if ( $wp_post_id ) {
				$post_rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT id, url, url2, post_id FROM {$this->tables['pages']} WHERE post_id = %d ORDER BY id ASC LIMIT 100",
						$wp_post_id
					),
					ARRAY_A
				);
				$this->merge_page_rows( $rows, $match_order, $post_rows, 'post_id' );
			}

			if ( ! empty( $url_variants ) ) {
				$this->merge_page_rows(
					$rows,
					$match_order,
					$this->query_pages_by_url_variants( $url_variants ),
					'url_variant'
				);
				$this->merge_page_rows(
					$rows,
					$match_order,
					$this->query_pages_by_url2_variants( $url_variants ),
					'url2_variant'
				);
				$this->merge_page_rows(
					$rows,
					$match_order,
					$this->query_pages_by_tracked_query_variants( $url_variants ),
					'tracked_query'
				);
			}
		}

		$page_ids     = array_map( 'absint', array_keys( $rows ) );
		$primary_id   = ! empty( $page_ids ) ? absint( $page_ids[0] ) : 0;
		$matched_urls = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['url'] ) ) {
				$matched_urls[] = (string) $row['url'];
			}
			if ( ! empty( $row['url2'] ) ) {
				$matched_urls[] = (string) $row['url2'];
			}
		}

		return array(
			'wp_post_id'      => $wp_post_id,
			'request_post_id' => $request_post_id,
			'is_revision'     => $is_revision,
			'page_id'         => $primary_id,
			'page_ids'        => $page_ids,
			'canonical_url'   => $canonical_url,
			'matched_urls'    => array_values( array_unique( array_filter( $matched_urls ) ) ),
			'url_variants'    => $url_variants,
			'match_order'     => $match_order,
		);
	}

	/**
	 * Normalize a repository period to a SQL-ready site-local date range.
	 *
	 * @param string $period     Period slug.
	 * @param string $start_date Optional custom start.
	 * @param string $end_date   Optional custom end.
	 * @return array
	 */
	public function normalize_date_range( $period, $start_date = '', $end_date = '' ) {
		$period = sanitize_key( $period );
		if ( ! in_array( $period, array( 'today', 'last7days', 'last30days', 'all', 'custom' ), true ) ) {
			$period = 'last30days';
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now      = new DateTimeImmutable( 'now', $timezone );
		$start    = null;
		$end      = null;
		$label    = __( 'All time', 'opti-behavior' );

		switch ( $period ) {
			case 'today':
				$start = $now->setTime( 0, 0, 0 );
				$end   = $now->setTime( 23, 59, 59 );
				$label = __( 'Today', 'opti-behavior' );
				break;
			case 'last7days':
				$start = $now->modify( '-6 days' )->setTime( 0, 0, 0 );
				$end   = $now->setTime( 23, 59, 59 );
				$label = __( 'Last 7 Days', 'opti-behavior' );
				break;
			case 'last30days':
				$start = $now->modify( '-29 days' )->setTime( 0, 0, 0 );
				$end   = $now->setTime( 23, 59, 59 );
				$label = __( 'Last 30 Days', 'opti-behavior' );
				break;
			case 'custom':
				$start = $this->parse_custom_date( $start_date, $timezone, false );
				$end   = $this->parse_custom_date( $end_date, $timezone, true );
				if ( ! $start || ! $end ) {
					return $this->normalize_date_range( 'last30days' );
				}
				if ( $start > $end ) {
					$tmp   = $start;
					$start = $end->setTime( 0, 0, 0 );
					$end   = $tmp->setTime( 23, 59, 59 );
				}
				$label = sprintf(
					/* translators: 1: start date, 2: end date */
					__( '%1$s to %2$s', 'opti-behavior' ),
					$start->format( 'Y-m-d' ),
					$end->format( 'Y-m-d' )
				);
				break;
			case 'all':
			default:
				break;
		}

		return array(
			'period'     => $period,
			'label'      => $label,
			'start'      => $start ? $start->format( 'Y-m-d H:i:s' ) : null,
			'end'        => $end ? $end->format( 'Y-m-d H:i:s' ) : null,
			'has_bounds' => (bool) ( $start && $end ),
			'timezone'   => $timezone->getName(),
		);
	}

	/**
	 * Canonical, ordered list of standard analytics periods shared by the
	 * frontend stats bar and the post-edit metabox so every surface offers the
	 * same options + labels. Default (first entry) is "all" (All time), matching
	 * the always-all-time heatmap surfaces for a consistent baseline.
	 *
	 * Labels are reused from normalize_date_range() so a single source of truth
	 * drives both the option list and the active-window label.
	 *
	 * @return array<string,string> Period slug => translated label.
	 */
	public static function get_standard_periods() {
		return array(
			'all'        => __( 'All time', 'opti-behavior' ),
			'today'      => __( 'Today', 'opti-behavior' ),
			'last7days'  => __( 'Last 7 Days', 'opti-behavior' ),
			'last30days' => __( 'Last 30 Days', 'opti-behavior' ),
		);
	}

	/**
	 * Default standard analytics period shared by the stats bar and metabox.
	 *
	 * @return string
	 */
	public static function get_default_standard_period() {
		return 'all';
	}

	/**
	 * Validate an incoming period against the standard option list, falling back
	 * to the shared default ("all") when the slug is empty or unrecognized.
	 *
	 * @param string $period Raw period slug.
	 * @return string
	 */
	public static function sanitize_standard_period( $period ) {
		$period = sanitize_key( (string) $period );

		return array_key_exists( $period, self::get_standard_periods() ) ? $period : self::get_default_standard_period();
	}

	/**
	 * Return a SQL fragment and params for a session traffic scope.
	 *
	 * @param string $session_alias Session table alias.
	 * @param string $traffic_scope Scope slug.
	 * @return array
	 */
	public function get_traffic_scope_sql( $session_alias = 's', $traffic_scope = self::TRAFFIC_SCOPE_HUMAN ) {
		$traffic_scope = $this->normalize_traffic_scope( $traffic_scope );
		$prefix        = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $session_alias );
		$column        = $prefix ? $prefix . '.traffic_type' : 'traffic_type';
		$duration_col  = $prefix ? $prefix . '.duration' : 'duration';

		if ( self::TRAFFIC_SCOPE_ALL === $traffic_scope ) {
			return array(
				'sql'    => '',
				'params' => array(),
			);
		}

		if ( self::TRAFFIC_SCOPE_BOT === $traffic_scope ) {
			return array(
				'sql'    => " AND COALESCE({$column}, '') IN ('spam', 'bot', 'automated')",
				'params' => array(),
			);
		}

		$sql = " AND ({$column} IS NULL OR {$column} = '' OR {$column} NOT IN ('spam', 'bot', 'automated'))";

		if ( $this->is_spam_detection_enabled() ) {
			$sql .= ' AND COALESCE(' . $duration_col . ', 0) >= ' . absint( $this->get_spam_duration_threshold() );
			return array(
				'sql'    => $sql,
				'params' => array(),
			);
		}

		return array(
			'sql'    => $sql,
			'params' => array(),
		);
	}

	/**
	 * Build Free metric calculations.
	 *
	 * @param array  $page_ids      Canonical page IDs.
	 * @param array  $date_range    Normalized date range.
	 * @param string $traffic_scope Traffic scope.
	 * @return array
	 */
	private function query_free_metrics( array $page_ids, array $date_range, $traffic_scope ) {
		$where = $this->build_page_id_sql( 'pv.page_id', $page_ids );
		$where .= $this->build_date_sql( 'pv.view_time', $date_range );
		$traffic_scope_sql = $this->get_traffic_scope_sql( 's', $traffic_scope );
		$where            .= $traffic_scope_sql['sql'];

		$params = array_merge( $page_ids, $this->get_date_params( $date_range ), $traffic_scope_sql['params'] );
		$sql    = $this->wpdb->prepare(
			"SELECT
				COUNT(DISTINCT pv.visitor_id) AS visitors,
				COUNT(DISTINCT pv.session_id) AS sessions,
				COUNT(pv.id) AS pageviews,
				COALESCE(AVG(NULLIF(pv.time_on_page, 0)), 0) AS avg_time_seconds,
				COALESCE(AVG(NULLIF(pv.scroll_depth, 0)), 0) AS scroll_depth_percent
			FROM {$this->tables['pageviews']} pv
			LEFT JOIN {$this->tables['sessions']} s ON s.id = pv.session_id
			WHERE {$where}",
			...$params
		);
		$row    = $this->wpdb->get_row( $sql, ARRAY_A );

		$sessions        = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;
		$bounce_sessions = $this->query_bounce_sessions( $page_ids, $date_range, $traffic_scope );
		$interactions    = $this->query_click_interactions( $page_ids, $date_range, $traffic_scope );
		$devices         = $this->query_device_counts( $page_ids, $date_range, $traffic_scope );
		$visitor_types   = $this->query_visitor_type_counts( $page_ids, $date_range, $traffic_scope );

		return array(
			'visitors'             => isset( $row['visitors'] ) ? absint( $row['visitors'] ) : 0,
			'sessions'             => $sessions,
			'pageviews'            => isset( $row['pageviews'] ) ? absint( $row['pageviews'] ) : 0,
			'avg_time_seconds'     => isset( $row['avg_time_seconds'] ) ? round( (float) $row['avg_time_seconds'], 2 ) : 0,
			'scroll_depth_percent' => isset( $row['scroll_depth_percent'] ) ? round( (float) $row['scroll_depth_percent'], 2 ) : 0,
			'bounce_sessions'      => $bounce_sessions,
			'bounce_rate_percent'  => $sessions > 0 ? round( ( $bounce_sessions / $sessions ) * 100, 2 ) : 0,
			'interactions'         => $interactions,
			'clicks'               => $interactions,
			'devices'              => $devices,
			'visitor_types'        => $visitor_types,
		);
	}

	/**
	 * Canonical human pageview-session split by visitor type (guest vs logged-in).
	 *
	 * Partitions the SAME distinct-session universe query_free_metrics() counts
	 * (distinct optibehavior_pageviews.session_id, human, spam-excluded, same
	 * page group + date range) by whether the session belongs to a logged-in
	 * user (sessions.user_id > 0) or a guest. Guest + logged_in therefore always
	 * sum to the canonical `sessions` total — the number the heatmap detail
	 * header pill, device chips, frontend bar and metabox all show — so the
	 * heatmap Visitor Type dropdown counts can no longer contradict the pill.
	 *
	 * @param array  $page_ids      Page IDs.
	 * @param array  $date_range    Date range.
	 * @param string $traffic_scope Traffic scope.
	 * @return array{guest:int,logged_in:int}
	 */
	private function query_visitor_type_counts( array $page_ids, array $date_range, $traffic_scope ) {
		$where  = $this->build_page_id_sql( 'pv.page_id', $page_ids );
		$where .= $this->build_date_sql( 'pv.view_time', $date_range );
		$where .= $this->get_traffic_scope_sql( 's', $traffic_scope )['sql'];

		$params = array_merge( $page_ids, $this->get_date_params( $date_range ) );
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT CASE WHEN s.user_id IS NOT NULL AND s.user_id > 0 THEN 'logged_in' ELSE 'guest' END AS vtype,
					COUNT(DISTINCT pv.session_id) AS sessions
				FROM {$this->tables['pageviews']} pv
				LEFT JOIN {$this->tables['sessions']} s ON s.id = pv.session_id
				WHERE {$where}
				GROUP BY vtype",
				...$params
			),
			ARRAY_A
		);

		$counts = array(
			'guest'     => 0,
			'logged_in' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$key = ( isset( $row['vtype'] ) && 'logged_in' === $row['vtype'] ) ? 'logged_in' : 'guest';
			$counts[ $key ] += absint( $row['sessions'] );
		}

		return $counts;
	}

	/**
	 * Query bounce sessions for the canonical page set.
	 *
	 * @param array  $page_ids      Page IDs.
	 * @param array  $date_range    Date range.
	 * @param string $traffic_scope Traffic scope.
	 * @return int
	 */
	private function query_bounce_sessions( array $page_ids, array $date_range, $traffic_scope ) {
		$where = $this->build_page_id_sql( 'pv.page_id', $page_ids );
		$where .= $this->build_date_sql( 'pv.view_time', $date_range );
		$where .= $this->get_traffic_scope_sql( 's', $traffic_scope )['sql'];

		$params = array_merge( $page_ids, $this->get_date_params( $date_range ) );

		return absint(
			$this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(DISTINCT pv.session_id)
					FROM {$this->tables['pageviews']} pv
					INNER JOIN {$this->tables['sessions']} s ON s.id = pv.session_id
					WHERE {$where}
						AND s.is_bounce = 1
						AND (s.page_views IS NULL OR s.page_views <= 1)",
					...$params
				)
			)
		);
	}

	/**
	 * Query click interaction count.
	 *
	 * @param array  $page_ids      Page IDs.
	 * @param array  $date_range    Date range.
	 * @param string $traffic_scope Traffic scope.
	 * @return int
	 */
	private function query_click_interactions( array $page_ids, array $date_range, $traffic_scope ) {
		if ( ! $this->table_exists( 'events' ) ) {
			return 0;
		}

		$where = $this->build_page_id_sql( 'e.page_id2', $page_ids );
		$where .= $this->build_date_sql( 'e.insert_at', $date_range );
		$where .= $this->get_traffic_scope_sql( 's', $traffic_scope )['sql'];

		$params = array_merge( $page_ids, $this->get_date_params( $date_range ) );
		$params[] = 16;
		$params[] = 17;

		return absint(
			$this->wpdb->get_var(
				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic page/date filters supply their placeholders via variadic arrays.
				$this->wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$this->tables['events']} e
					LEFT JOIN {$this->tables['sessions']} s ON s.id = e.session_id
					WHERE {$where}
						AND e.event IN (%d, %d)",
					...$params
				)
				// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			)
		);
	}

	/**
	 * Query device counts grouped by normalized visitor device type.
	 *
	 * @param array  $page_ids      Page IDs.
	 * @param array  $date_range    Date range.
	 * @param string $traffic_scope Traffic scope.
	 * @return array
	 */
	private function query_device_counts( array $page_ids, array $date_range, $traffic_scope ) {
		if ( ! $this->table_exists( 'visitors' ) ) {
			return $this->get_empty_device_counts();
		}

		$where = $this->build_page_id_sql( 'pv.page_id', $page_ids );
		$where .= $this->build_date_sql( 'pv.view_time', $date_range );
		$where .= $this->get_traffic_scope_sql( 's', $traffic_scope )['sql'];

		$params = array_merge( $page_ids, $this->get_date_params( $date_range ) );
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT COALESCE(NULLIF(LOWER(v.device_type), ''), 'unknown') AS device_type,
					COUNT(DISTINCT pv.session_id) AS sessions
				FROM {$this->tables['pageviews']} pv
				LEFT JOIN {$this->tables['sessions']} s ON s.id = pv.session_id
				LEFT JOIN {$this->tables['visitors']} v ON v.id = pv.visitor_id
				WHERE {$where}
				GROUP BY COALESCE(NULLIF(LOWER(v.device_type), ''), 'unknown')",
				...$params
			),
			ARRAY_A
		);

		$devices = $this->get_empty_device_counts();
		foreach ( $rows as $row ) {
			$type = $this->normalize_device_type( $row['device_type'] );
			if ( ! isset( $devices[ $type ] ) ) {
				$devices[ $type ] = 0;
			}
			$devices[ $type ] += absint( $row['sessions'] );
		}

		return $devices;
	}

	/**
	 * Build an empty metric block with stable keys.
	 *
	 * @param array  $identity      Page identity.
	 * @param array  $date_range    Date range.
	 * @param string $traffic_scope Traffic scope.
	 * @return array
	 */
	private function get_empty_metrics( array $identity, array $date_range, $traffic_scope ) {
		return array(
			'visitors'             => 0,
			'sessions'             => 0,
			'pageviews'            => 0,
			'avg_time_seconds'     => 0,
			'avg_time'             => $this->format_duration( 0 ),
			'scroll_depth_percent' => 0,
			'scroll_depth'         => '0%',
			'bounce_sessions'      => 0,
			'bounce_rate_percent'  => 0,
			'bounce_rate'          => '0%',
			'interactions'         => 0,
			'clicks'               => 0,
			'devices'              => $this->get_empty_device_counts(),
			'visitor_types'        => array(
				'guest'     => 0,
				'logged_in' => 0,
			),
			'pro'                  => array(
				'available'  => false,
				'source'     => 'unavailable',
				'recordings' => 0,
				'events'     => 0,
				'clicks'     => 0,
			),
			'page_id'              => isset( $identity['page_id'] ) ? absint( $identity['page_id'] ) : 0,
			'page_ids'             => isset( $identity['page_ids'] ) ? array_map( 'absint', $identity['page_ids'] ) : array(),
			'period'               => $date_range['period'],
			'period_label'         => $date_range['label'],
			'date_range'           => $date_range,
			'traffic_scope'        => $traffic_scope,
			'source'               => 'free_pageviews_empty',
			'contract_version'     => self::CONTRACT_VERSION,
			'identity'             => $identity,
		);
	}

	/**
	 * Add display fields derived from raw numbers.
	 *
	 * @param array $metrics Metrics.
	 * @return array
	 */
	private function with_formatted_metrics( array $metrics ) {
		$metrics['visitors']             = isset( $metrics['visitors'] ) ? absint( $metrics['visitors'] ) : 0;
		$metrics['sessions']             = isset( $metrics['sessions'] ) ? absint( $metrics['sessions'] ) : 0;
		$metrics['pageviews']            = isset( $metrics['pageviews'] ) ? absint( $metrics['pageviews'] ) : 0;
		$metrics['avg_time_seconds']     = isset( $metrics['avg_time_seconds'] ) ? round( max( 0, (float) $metrics['avg_time_seconds'] ), 2 ) : 0;
		$metrics['scroll_depth_percent'] = isset( $metrics['scroll_depth_percent'] ) ? round( max( 0, (float) $metrics['scroll_depth_percent'] ), 2 ) : 0;
		$metrics['bounce_rate_percent']  = isset( $metrics['bounce_rate_percent'] ) ? round( max( 0, (float) $metrics['bounce_rate_percent'] ), 2 ) : 0;
		$metrics['avg_time']             = $this->format_duration( $metrics['avg_time_seconds'] );
		$metrics['scroll_depth']         = $this->format_percent( $metrics['scroll_depth_percent'] );
		$metrics['bounce_rate']          = $this->format_percent( $metrics['bounce_rate_percent'] );

		if ( empty( $metrics['devices'] ) || ! is_array( $metrics['devices'] ) ) {
			$metrics['devices'] = $this->get_empty_device_counts();
		} else {
			$metrics['devices'] = array_merge( $this->get_empty_device_counts(), array_map( 'absint', $metrics['devices'] ) );
		}

		if ( empty( $metrics['pro'] ) || ! is_array( $metrics['pro'] ) ) {
			$metrics['pro'] = array(
				'available'  => false,
				'source'     => 'unavailable',
				'recordings' => 0,
				'events'     => 0,
				'clicks'     => 0,
			);
		}

		return $metrics;
	}

	/**
	 * Build exact URL variants.
	 *
	 * @param string $canonical_url Canonical URL.
	 * @param string $page_url      Requested page URL.
	 * @return array
	 */
	private function build_url_variants( $canonical_url, $page_url = '' ) {
		$candidates = array_filter(
			array(
				$canonical_url,
				$page_url,
				$this->trailingslash_url( $canonical_url ),
				$this->untrailingslash_url( $canonical_url ),
				$this->trailingslash_url( $page_url ),
				$this->untrailingslash_url( $page_url ),
				$this->rebuild_tracking_url( $canonical_url ),
				$this->rebuild_tracking_url( $page_url ),
			)
		);

		return array_values( array_unique( array_map( 'esc_url_raw', $candidates ) ) );
	}

	/**
	 * Query pages by url exact variants.
	 *
	 * @param array $url_variants URL variants.
	 * @return array
	 */
	private function query_pages_by_url_variants( array $url_variants ) {
		return $this->query_pages_by_column_variants( 'url', $url_variants );
	}

	/**
	 * Query pages by url2 exact/rebuilt variants.
	 *
	 * @param array $url_variants URL variants.
	 * @return array
	 */
	private function query_pages_by_url2_variants( array $url_variants ) {
		$url2_variants = array();
		foreach ( $url_variants as $url ) {
			$url2_variants[] = $this->rebuild_tracking_url( $url );
		}

		return $this->query_pages_by_column_variants( 'url2', array_values( array_unique( array_filter( $url2_variants ) ) ) );
	}

	/**
	 * Query pages by a bounded permalink-prefix search for tracked query URLs.
	 *
	 * @param array $url_variants URL variants.
	 * @return array
	 */
	private function query_pages_by_tracked_query_variants( array $url_variants ) {
		$bases = array();
		foreach ( $url_variants as $url ) {
			$base = strtok( $url, '?#' );
			if ( $base ) {
				$bases[] = $this->trailingslash_url( $base );
				$bases[] = $this->untrailingslash_url( $base );
			}
		}

		$bases = array_values( array_unique( array_filter( $bases ) ) );
		if ( empty( $bases ) ) {
			return array();
		}

		$likes  = array();
		$params = array();
		foreach ( array_slice( $bases, 0, 8 ) as $base ) {
			if ( strlen( $base ) < 8 ) {
				continue;
			}
			$likes[]  = 'url LIKE %s';
			$params[] = $this->wpdb->esc_like( $base ) . '?%';
			$likes[]  = 'url2 LIKE %s';
			$params[] = $this->wpdb->esc_like( $base ) . '?%';
		}

		if ( empty( $likes ) ) {
			return array();
		}

		$params[] = 100;

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, url, url2, post_id
				FROM {$this->tables['pages']}
				WHERE " . implode( ' OR ', $likes ) . '
				ORDER BY id ASC
				LIMIT %d',
				...$params
			),
			ARRAY_A
		);
	}

	/**
	 * Query pages by exact variants against a safe hardcoded column.
	 *
	 * @param string $column       url|url2.
	 * @param array  $url_variants URL variants.
	 * @return array
	 */
	private function query_pages_by_column_variants( $column, array $url_variants ) {
		$column       = 'url2' === $column ? 'url2' : 'url';
		$url_variants = array_values( array_unique( array_filter( $url_variants ) ) );

		if ( empty( $url_variants ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $url_variants ), '%s' ) );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, url, url2, post_id
				FROM {$this->tables['pages']}
				WHERE {$column} IN ({$placeholders})
				ORDER BY id ASC
				LIMIT 100",
				$url_variants
			),
			ARRAY_A
		);
	}

	/**
	 * Merge page rows while preserving first-match priority.
	 *
	 * @param array  $rows        Existing rows keyed by page ID.
	 * @param array  $match_order Match reasons.
	 * @param array  $new_rows    Rows to merge.
	 * @param string $reason      Match reason.
	 */
	private function merge_page_rows( array &$rows, array &$match_order, $new_rows, $reason ) {
		if ( empty( $new_rows ) || ! is_array( $new_rows ) ) {
			return;
		}

		foreach ( $new_rows as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( ! $id || isset( $rows[ $id ] ) ) {
				continue;
			}

			$rows[ $id ]        = $row;
			$match_order[ $id ] = $reason;
		}
	}

	/**
	 * Rebuild URL as url2 using the existing database helper when available.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function rebuild_tracking_url( $url ) {
		if ( ! $url ) {
			return '';
		}

		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) && class_exists( 'Opti_Behavior_Heatmap_Database' ) ) {
			try {
				$core = Opti_Behavior_Heatmap_Core::get_instance();
				$db   = new Opti_Behavior_Heatmap_Database( $core );
				if ( method_exists( $db, 'make_url_filter' ) && method_exists( $db, 'rebuild_url' ) ) {
					$options         = method_exists( $core, 'get_options' ) ? $core->get_options() : array();
					$query_filter    = $db->make_url_filter( true );
					$fragment_filter = ! empty( $options['keep_url_hash'] );
					return $db->rebuild_url( $url, $query_filter, $fragment_filter );
				}
			} catch ( Exception $e ) {
				// Fall through to the lightweight implementation.
			}
		}

		return $this->lightweight_rebuild_url( $url );
	}

	/**
	 * Lightweight url2-compatible fallback.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function lightweight_rebuild_url( $url ) {
		$strip_keys = array(
			'opti_behavior_debug',
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'gclid',
			'fbclid',
			'msclkid',
			'mc_cid',
			'mc_eid',
			'ref',
			'elementor-preview',
			'ver',
			'preview',
			'preview_id',
			'preview_nonce',
		);

		return remove_query_arg( $strip_keys, strtok( $url, '#' ) );
	}

	/**
	 * Build a page-id IN clause.
	 *
	 * @param string $column   Column expression.
	 * @param array  $page_ids Page IDs.
	 * @return string
	 */
	private function build_page_id_sql( $column, array $page_ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		return $column . ' IN (' . $placeholders . ')';
	}

	/**
	 * Build date SQL for bounded ranges.
	 *
	 * @param string $column     Date column.
	 * @param array  $date_range Date range.
	 * @return string
	 */
	private function build_date_sql( $column, array $date_range ) {
		$sql = '';
		if ( ! empty( $date_range['has_bounds'] ) ) {
			$sql .= " AND {$column} BETWEEN %s AND %s";
		}

		// Optional precise lower bound (e.g. "Delete Heatmap Data" reset floor):
		// drop rows older than the given datetime, same datetime precision the
		// session_pages reset clause uses. Opt-in via $date_range['min_view_time']
		// so every other caller is unaffected (empty => no clause).
		if ( ! empty( $date_range['min_view_time'] ) ) {
			$sql .= " AND {$column} >= %s";
		}

		return $sql;
	}

	/**
	 * Return date params for bounded ranges.
	 *
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function get_date_params( array $date_range ) {
		$params = array();
		if ( ! empty( $date_range['has_bounds'] ) ) {
			$params[] = $date_range['start'];
			$params[] = $date_range['end'];
		}

		// Matches the optional lower-bound placeholder appended by build_date_sql().
		if ( ! empty( $date_range['min_view_time'] ) ) {
			$params[] = $date_range['min_view_time'];
		}

		return $params;
	}

	/**
	 * Check whether base metric tables exist.
	 *
	 * @return bool
	 */
	private function has_required_metric_tables() {
		return $this->table_exists( 'pageviews' ) && $this->table_exists( 'sessions' );
	}

	/**
	 * Check a known plugin table exists.
	 *
	 * @param string $key Table key.
	 * @return bool
	 */
	private function table_exists( $key ) {
		if ( empty( $this->tables[ $key ] ) ) {
			return false;
		}

		if ( isset( $this->table_exists_cache[ $key ] ) ) {
			return $this->table_exists_cache[ $key ];
		}

		$this->table_exists_cache[ $key ] = (bool) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tables[ $key ] ) );
		return $this->table_exists_cache[ $key ];
	}

	/**
	 * Normalize traffic scope.
	 *
	 * @param string $traffic_scope Scope slug.
	 * @return string
	 */
	private function normalize_traffic_scope( $traffic_scope ) {
		$traffic_scope = sanitize_key( $traffic_scope );
		if ( in_array( $traffic_scope, array( self::TRAFFIC_SCOPE_HUMAN, self::TRAFFIC_SCOPE_ALL, self::TRAFFIC_SCOPE_BOT ), true ) ) {
			return $traffic_scope;
		}

		return self::TRAFFIC_SCOPE_HUMAN;
	}

	/**
	 * Get default page-analytics period.
	 *
	 * @return string
	 */
	private function get_default_period() {
		$settings = get_option( 'opti_behavior_frontend_stats_bar', array() );
		$period   = is_array( $settings ) && ! empty( $settings['period'] ) ? sanitize_key( $settings['period'] ) : 'last30days';

		return in_array( $period, array( 'today', 'last7days', 'last30days' ), true ) ? $period : 'last30days';
	}

	/**
	 * Whether Traffic Behavior spam detection is enabled.
	 *
	 * Missing legacy option rows are treated as enabled because the settings UI
	 * renders the checkbox enabled by default.
	 *
	 * @return bool
	 */
	private function is_spam_detection_enabled() {
		$settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled' => true,
			)
		);

		if ( ! is_array( $settings ) ) {
			return true;
		}

		return ! array_key_exists( 'spam_detection_enabled', $settings ) || ! empty( $settings['spam_detection_enabled'] );
	}

	/**
	 * Get configured spam duration threshold.
	 *
	 * @return int
	 */
	private function get_spam_duration_threshold() {
		$settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_duration_threshold' => 3,
			)
		);

		return isset( $settings['spam_duration_threshold'] ) ? max( 0, absint( $settings['spam_duration_threshold'] ) ) : 3;
	}

	/**
	 * Parse a custom date.
	 *
	 * @param string       $date       Date string.
	 * @param DateTimeZone $timezone   Site timezone.
	 * @param bool         $end_of_day Whether to set to 23:59:59.
	 * @return DateTimeImmutable|null
	 */
	private function parse_custom_date( $date, DateTimeZone $timezone, $end_of_day = false ) {
		$date = is_string( $date ) ? trim( $date ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
		if ( ! $parsed ) {
			return null;
		}

		return $end_of_day ? $parsed->setTime( 23, 59, 59 ) : $parsed->setTime( 0, 0, 0 );
	}

	/**
	 * Return a URL with one trailing slash before query/hash.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function trailingslash_url( $url ) {
		if ( ! $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
			return trailingslashit( $url );
		}

		$base = strtok( $url, '?#' );
		$tail = substr( $url, strlen( $base ) );

		return trailingslashit( $base ) . $tail;
	}

	/**
	 * Return a URL without a trailing slash before query/hash.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function untrailingslash_url( $url ) {
		if ( ! $url ) {
			return '';
		}

		$base = strtok( $url, '?#' );
		$tail = substr( $url, strlen( $base ) );

		return untrailingslashit( $base ) . $tail;
	}

	/**
	 * Normalize visitor device type.
	 *
	 * @param string $device_type Device type.
	 * @return string
	 */
	private function normalize_device_type( $device_type ) {
		$device_type = sanitize_key( $device_type );
		if ( in_array( $device_type, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
			return $device_type;
		}

		return $device_type ? $device_type : 'unknown';
	}

	/**
	 * Empty device counts.
	 *
	 * @return array
	 */
	private function get_empty_device_counts() {
		return array(
			'desktop' => 0,
			'mobile'  => 0,
			'tablet'  => 0,
			'unknown' => 0,
		);
	}

	/**
	 * Format seconds.
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	private function format_duration( $seconds ) {
		$seconds = max( 0, (int) round( $seconds ) );
		$minutes = (int) floor( $seconds / 60 );
		$remain  = $seconds % 60;

		if ( $minutes > 0 ) {
			return sprintf( '%dm %02ds', $minutes, $remain );
		}

		return sprintf( '%ds', $remain );
	}

	/**
	 * Format percentage.
	 *
	 * @param float $percent Percent.
	 * @return string
	 */
	private function format_percent( $percent ) {
		$percent = round( max( 0, (float) $percent ), 1 );
		if ( 0.0 === $percent ) {
			return '0%';
		}

		return rtrim( rtrim( number_format_i18n( $percent, 1 ), '0' ), '.' ) . '%';
	}
}
