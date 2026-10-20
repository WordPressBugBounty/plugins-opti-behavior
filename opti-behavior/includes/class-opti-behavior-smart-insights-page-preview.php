<?php
/**
 * Smart Insights: real preview of the locked Page X-Ray tab.
 *
 * Without Pro the Page X-Ray tab shows the three most visited pages of the
 * period with a health score and the headline of their first problem, built
 * from the data the free plugin collects (visits, bounce, scroll depth,
 * clicks, funnels). The full report (why, what to do, recordings, the other
 * problems, the other pages) stays in Pro.
 *
 * The score uses the SAME formula as the Pro dossier, restricted to the
 * problem types Free can measure: on a page whose only problems are of these
 * types, the preview and the dossier show the same score.
 *
 * @package OptiBehavior
 * @since   1.10.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opti_Behavior_Smart_Insights_Page_Preview {

	/**
	 * Cache lifetime, same as the Pro tab (CACHE_TTL).
	 */
	const CACHE_TTL    = 600;
	const CACHE_PREFIX = 'opti_behavior_si_preview_';
	// Part of the cache key: bump when the stored shape or a rule changes.
	const DATA_VERSION = 1;

	/**
	 * Pages shown, and the visits a page needs to be shown.
	 */
	const MAX_PAGES  = 3;
	const MIN_VISITS = 20;
	// Top rows read before URL variants are folded and archives dropped.
	const CANDIDATES = 40;

	// Must match Pro Page_Xray: CLUSTER_OTHERS_SHARE, MIN_SESSIONS_BEHAVIOUR, CLICK_EVENTS, ROW_MIN_CLICKS.
	const CLUSTER_OTHERS_SHARE   = 0.25;
	const MIN_SESSIONS_BEHAVIOUR = 20;
	const CLICK_EVENTS           = '16, 17';
	const ROW_MIN_CLICKS         = 10;

	/**
	 * Problem types Free can measure alone, with their score weight.
	 * Must match Pro get_types() (same weights) for these types.
	 *
	 * @return array type => weight.
	 */
	public static function get_types() {
		return array(
			'funnel_drop'      => 25,
			'high_bounce'      => 20,
			'clicks_on_static' => 15,
			'low_scroll'       => 10,
		);
	}

	/**
	 * Root-cause cluster of a type. Must match Pro get_clusters() for the
	 * types of get_types(); a type outside a cluster is its own cluster.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function cluster_of( $type ) {
		$clusters = array(
			'low_scroll'       => 'goal',
			'clicks_on_static' => 'clicks',
		);

		return isset( $clusters[ $type ] ) ? $clusters[ $type ] : (string) $type;
	}

	/**
	 * Drop the cached previews when the Smart Insights rules change.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'opti_behavior_smart_insights_rules_upgraded', array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Delete every cached preview.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		if ( function_exists( 'opti_behavior_delete_transients_by_prefix' ) ) {
			opti_behavior_delete_transients_by_prefix( array( self::CACHE_PREFIX ) );
		}
	}

	/**
	 * The preview: at most MAX_PAGES pages with at least MIN_VISITS visits,
	 * most visited first.
	 *
	 * @param array $args `period`, `start_date`, `end_date`, `exclude_spam`.
	 * @return array { pages: [ { page_id, title, path, sessions, score, health, provisional, top_title } ], range: { from, to } }
	 */
	public static function get_preview( $args = array() ) {
		$context = self::build_context( $args );
		$key     = self::cache_key( $args );
		$cached  = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['pages'] ) ) {
			return $cached;
		}

		$result = array(
			'pages' => array(),
			'range' => array(
				'from' => $context['date_from'],
				'to'   => $context['date_to'],
			),
		);
		try {
			$result['pages'] = self::build_pages( $context );
		} catch ( Throwable $e ) {
			$result['pages'] = array();
		}
		set_transient( $key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Transient name of a preview (period + spam scope + language).
	 *
	 * @param array $args See get_preview().
	 * @return string
	 */
	public static function cache_key( $args = array() ) {
		$context = self::build_context( $args );

		return self::CACHE_PREFIX . md5( wp_json_encode( array( self::DATA_VERSION, $context['sql_start'], $context['sql_end'], $context['exclude_spam'], determine_locale() ) ) );
	}

	/**
	 * Resolve the period (same resolver as the Pro tab).
	 *
	 * @param array $args Args.
	 * @return array
	 */
	private static function build_context( $args ) {
		$args   = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'period'       => 'last30days',
				'start_date'   => '',
				'end_date'     => '',
				'exclude_spam' => true,
			)
		);
		$period = sanitize_key( (string) $args['period'] );
		$today  = current_time( 'Y-m-d' );
		$range  = array(
			'start_date' => gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) ),
			'end_date'   => $today,
			'sql_start'  => gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) ) . ' 00:00:00',
			'sql_end'    => gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) ) . ' 00:00:00',
		);
		if ( class_exists( 'Opti_Behavior_Stats_Date_Range' ) ) {
			$range = Opti_Behavior_Stats_Date_Range::resolve(
				'' !== $period ? $period : 'last30days',
				sanitize_text_field( (string) $args['start_date'] ),
				sanitize_text_field( (string) $args['end_date'] )
			);
		}

		return array(
			'date_from'    => (string) $range['start_date'],
			'date_to'      => (string) $range['end_date'],
			'sql_start'    => (string) $range['sql_start'],
			'sql_end'      => (string) $range['sql_end'],
			'exclude_spam' => ! empty( $args['exclude_spam'] ),
		);
	}

	/**
	 * Build the page cards.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private static function build_pages( $context ) {
		$rows = self::query_page_rows( $context );
		if ( empty( $rows ) ) {
			return array();
		}
		$meta = self::query_page_meta( array_keys( $rows ) );

		// One row per page: the variants of one URL are folded into the member
		// with a clean URL (same rule as the Pro list and Traffic Top Pages).
		$groups = array();
		if ( function_exists( 'opti_behavior_page_identity_key' ) && function_exists( 'opti_behavior_page_identity_members' ) ) {
			$reps = array();
			foreach ( $rows as $page_id => $row ) {
				$url = isset( $meta[ $page_id ] ) ? (string) $meta[ $page_id ]['url'] : '';
				$key = '' === $url ? 'id:' . $page_id : opti_behavior_page_identity_key( $url );
				if ( ! isset( $reps[ $key ] ) ) {
					$reps[ $key ] = $page_id;
					continue;
				}
				$current = isset( $meta[ $reps[ $key ] ] ) ? (string) $meta[ $reps[ $key ] ]['url'] : '';
				if ( false !== strpos( $current, '?' ) && false === strpos( $url, '?' ) ) {
					$reps[ $key ] = $page_id;
				}
			}
			$found = (array) opti_behavior_page_identity_members( array_values( $reps ) );
			foreach ( $reps as $rep ) {
				$members        = isset( $found[ $rep ] ) ? array_values( array_filter( array_map( 'absint', (array) $found[ $rep ] ) ) ) : array();
				$groups[ $rep ] = empty( $members ) ? array( $rep ) : $members;
			}
			$rows = self::query_page_rows( $context, $groups );
		} else {
			foreach ( array_keys( $rows ) as $page_id ) {
				$groups[ $page_id ] = array( $page_id );
			}
		}
		uasort(
			$rows,
			function ( $a, $b ) {
				return $b['sessions'] <=> $a['sessions'];
			}
		);

		$picked = array();
		foreach ( $rows as $page_id => $row ) {
			if ( $row['sessions'] < self::MIN_VISITS ) {
				break;
			}
			if ( ! isset( $meta[ $page_id ] ) || ! self::is_listable_url( $meta[ $page_id ]['url'] ) ) {
				continue;
			}
			$picked[ $page_id ] = $row;
			if ( count( $picked ) >= self::MAX_PAGES ) {
				break;
			}
		}
		if ( empty( $picked ) ) {
			return array();
		}

		$site    = self::query_site_row( $context );
		$heat    = self::query_static_clicks( array_intersect_key( $groups, $picked ), $context );
		$funnels = self::funnel_facts( $picked, $meta, $context );
		$pages   = array();
		foreach ( $picked as $page_id => $row ) {
			$facts = self::behaviour_facts( $row, $site );
			if ( isset( $heat[ $page_id ] ) && $row['sessions'] >= self::MIN_SESSIONS_BEHAVIOUR ) {
				$facts[] = array(
					'type'     => 'clicks_on_static',
					'count'    => $heat[ $page_id ]['clicks'],
					'sessions' => min( $row['sessions'], $heat[ $page_id ]['clicks'] ),
					'sample'   => $heat[ $page_id ]['label'],
				);
			}
			if ( isset( $funnels[ $page_id ] ) ) {
				$facts = array_merge( $facts, $funnels[ $page_id ] );
			}
			$problems = self::rank( $facts, $row['sessions'] );
			$score    = self::score( $problems, $row['sessions'] );
			$url      = function_exists( 'opti_behavior_strip_sensitive_query_args' ) ? (string) opti_behavior_strip_sensitive_query_args( $meta[ $page_id ]['url'] ) : (string) $meta[ $page_id ]['url'];
			$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
			$query    = (string) wp_parse_url( $url, PHP_URL_QUERY );
			$path     = ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' );
			$pages[]  = array(
				'page_id'     => (int) $page_id,
				'title'       => '' !== $meta[ $page_id ]['title'] ? $meta[ $page_id ]['title'] : $path,
				'path'        => $path,
				'sessions'    => (int) $row['sessions'],
				'score'       => $score['score'],
				'health'      => $score['health'],
				'provisional' => $score['provisional'],
				'top_title'   => empty( $problems ) ? '' : self::title( $problems[0] ),
			);
		}

		return $pages;
	}

	/**
	 * Bounce and scroll facts of a page: the shared Page_Rules verdicts, as in
	 * the Pro dossier (collect_facts()).
	 *
	 * @param array $row  Page row.
	 * @param array $site Site row.
	 * @return array
	 */
	public static function behaviour_facts( $row, $site ) {
		$facts = array();
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Page_Rules' ) ) {
			return $facts;
		}
		$sessions   = (int) $row['sessions'];
		$bounce_pct = $sessions > 0 ? $row['bounce_sessions'] / $sessions * 100 : null;
		$bounce     = Opti_Behavior_Smart_Insights_Page_Rules::bounce( $sessions, $bounce_pct, isset( $site['bounce_rate'] ) ? $site['bounce_rate'] : null );
		if ( $bounce['triggered'] ) {
			$facts[] = array(
				'type'     => 'high_bounce',
				'count'    => (int) $row['bounce_sessions'],
				'sessions' => (int) $row['bounce_sessions'],
				'sample'   => '',
			);
		}
		$scroll = Opti_Behavior_Smart_Insights_Page_Rules::scroll( $sessions, $row['scroll_depth'] > 0 ? $row['scroll_depth'] : null, ! empty( $site['scroll_depth'] ) ? $site['scroll_depth'] : null );
		if ( $scroll['triggered'] ) {
			$facts[] = array(
				'type'     => 'low_scroll',
				'count'    => $sessions,
				'sessions' => (int) round( $sessions * 0.5 ),
				'sample'   => '',
			);
		}

		return $facts;
	}

	/**
	 * Rank facts: most visits touched first, then type weight (Pro rank_problems()).
	 *
	 * @param array $facts    Facts.
	 * @param int   $sessions Page visits.
	 * @return array
	 */
	public static function rank( $facts, $sessions ) {
		$types = self::get_types();
		$cap   = max( 1, (int) $sessions );
		$out   = array();
		foreach ( (array) $facts as $fact ) {
			if ( ! is_array( $fact ) || empty( $fact['type'] ) || ! isset( $types[ $fact['type'] ] ) ) {
				continue;
			}
			$touched         = max( isset( $fact['sessions'] ) ? (int) $fact['sessions'] : 0, 0 );
			$fact['touched'] = min( $cap, $touched > 0 ? $touched : ( isset( $fact['count'] ) ? (int) $fact['count'] : 0 ) );
			$fact['weight']  = $types[ $fact['type'] ];
			$out[]           = $fact;
		}
		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['touched'] !== $b['touched'] ) {
					return $b['touched'] <=> $a['touched'];
				}
				return $b['weight'] <=> $a['weight'];
			}
		);

		return $out;
	}

	/**
	 * Health score (0-100). Must match Pro Page_Xray::score(): each problem
	 * removes its weight scaled by the share of visits it touched (full weight
	 * from half of the visits, a quarter at least), halved under the
	 * provisional threshold; a cluster costs its heaviest problem plus
	 * CLUSTER_OTHERS_SHARE of the others.
	 *
	 * @param array $problems Ranked problems.
	 * @param int   $sessions Page visits.
	 * @return array { score, health, provisional }
	 */
	public static function score( $problems, $sessions ) {
		$visits      = max( 0, (int) $sessions );
		$sessions    = max( 1, $visits );
		$provisional = class_exists( 'Opti_Behavior_Smart_Insights_Scorer' ) ? Opti_Behavior_Smart_Insights_Scorer::is_provisional( $visits ) : $visits < 30;
		$clusters    = array();
		foreach ( (array) $problems as $problem ) {
			$share  = min( 1, $problem['touched'] / $sessions );
			$factor = max( 0.25, min( 1, $share * 2 ) );
			$p      = $problem['weight'] * $factor;
			if ( $provisional ) {
				$p *= 0.5;
			}
			$clusters[ self::cluster_of( (string) $problem['type'] ) ][] = $p;
		}
		$penalty = 0;
		foreach ( $clusters as $items ) {
			rsort( $items );
			foreach ( $items as $k => $p ) {
				$penalty += 0 === $k ? $p : $p * self::CLUSTER_OTHERS_SHARE;
			}
		}
		$score = (int) max( 0, round( 100 - $penalty ) );
		if ( $score >= 80 ) {
			$health = 'good';
		} elseif ( $score >= 50 ) {
			$health = 'watch';
		} else {
			$health = 'fix';
		}

		return array(
			'score'       => $score,
			'health'      => $health,
			'provisional' => $provisional,
		);
	}

	/**
	 * Health label (same words as the Pro dossier).
	 *
	 * @param string $health good|watch|fix.
	 * @return string
	 */
	public static function health_label( $health ) {
		switch ( $health ) {
			case 'good':
				return __( 'Healthy', 'opti-behavior' );
			case 'watch':
				return __( 'Watch', 'opti-behavior' );
		}

		return __( 'Fix', 'opti-behavior' );
	}

	/**
	 * Headline of a problem (same words as the Pro dossier).
	 *
	 * @param array $problem Problem.
	 * @return string
	 */
	public static function title( $problem ) {
		$sample = isset( $problem['sample'] ) ? (string) $problem['sample'] : '';
		switch ( isset( $problem['type'] ) ? $problem['type'] : '' ) {
			case 'funnel_drop':
				/* translators: %s: funnel name. */
				return sprintf( __( 'Visitors drop out of the funnel “%s” here', 'opti-behavior' ), $sample );
			case 'clicks_on_static':
				/* translators: %s: element text. */
				return sprintf( __( 'Visitors click on “%s”, which does nothing', 'opti-behavior' ), $sample );
			case 'high_bounce':
				return __( 'Visitors leave right away', 'opti-behavior' );
			case 'low_scroll':
				return __( 'Visitors stop scrolling early', 'opti-behavior' );
		}

		return '';
	}

	/**
	 * Whether a URL is a page (not an archive, a search or a paginated URL),
	 * as in the Pro list.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_listable_url( $url ) {
		if ( class_exists( 'Opti_Behavior_Heatmap_Page_Type_Prune' ) && method_exists( 'Opti_Behavior_Heatmap_Page_Type_Prune', 'classify_url' ) ) {
			try {
				return 'keep' === Opti_Behavior_Heatmap_Page_Type_Prune::classify_url( (string) $url, true );
			} catch ( Throwable $e ) {
				return true;
			}
		}

		return true;
	}

	/**
	 * Traffic-scope SQL of the canonical repository (Traffic's universe).
	 *
	 * @param array $context Context.
	 * @return array { sql, params }
	 */
	private static function scope_sql( $context ) {
		if ( class_exists( 'Opti_Behavior_Page_Analytics_Repository' ) ) {
			try {
				$repository = new Opti_Behavior_Page_Analytics_Repository();
				$scope      = $repository->get_traffic_scope_sql( 's', $context['exclude_spam'] ? 'human_sessions' : 'all' );
				if ( is_array( $scope ) && isset( $scope['sql'] ) ) {
					return array(
						'sql'    => (string) $scope['sql'],
						'params' => isset( $scope['params'] ) ? (array) $scope['params'] : array(),
					);
				}
			} catch ( Throwable $e ) {
				// Fall through.
			}
		}

		return array(
			'sql'    => '',
			'params' => array(),
		);
	}

	/**
	 * Visits, bounces and average scroll depth per page over the period.
	 *
	 * @param array      $context Context.
	 * @param array|null $groups  page_id => member ids: one row per page id,
	 *                            counted over its members; null = top CANDIDATES by visits.
	 * @return array page_id => row (visits desc).
	 */
	private static function query_page_rows( $context, $groups = null ) {
		global $wpdb;

		$pageviews = $wpdb->prefix . 'optibehavior_pageviews';
		$sessions  = $wpdb->prefix . 'optibehavior_sessions';
		$scope     = self::scope_sql( $context );
		// A page view counts only with its session row (Traffic's universe).
		$where  = 'pv.view_time >= %s AND pv.view_time < %s AND s.id IS NOT NULL' . $scope['sql'];
		$params = array_merge( array( $context['sql_start'], $context['sql_end'] ), $scope['params'] );
		$case   = 'pv.page_id';
		$limit  = ' LIMIT ' . (int) self::CANDIDATES;
		$first  = array();
		if ( is_array( $groups ) ) {
			$whens = array();
			$ids   = array();
			foreach ( $groups as $rep => $members ) {
				foreach ( (array) $members as $member ) {
					$member = absint( $member );
					if ( $member > 0 && ! isset( $ids[ $member ] ) ) {
						$ids[ $member ] = true;
						$whens[]        = 'WHEN %d THEN %d';
						$first[]        = $member;
						$first[]        = (int) $rep;
					}
				}
			}
			if ( empty( $whens ) ) {
				return array();
			}
			$ids    = array_keys( $ids );
			$case   = 'CASE pv.page_id ' . implode( ' ', $whens ) . ' END';
			$where .= ' AND pv.page_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $ids );
			$limit  = '';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregates on plugin tables (names from $wpdb->prefix); the placeholders of the page-id IN() list live in the WHERE fragment and those of the group CASE in the SELECT, all bound through $wpdb->prepare(); scope SQL comes from the canonical repository builder; result cached in a transient by get_preview().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$case} AS gid,
					COUNT(DISTINCT pv.session_id) AS sessions,
					COUNT(DISTINCT CASE WHEN s.is_bounce = 1 AND (s.page_views IS NULL OR s.page_views <= 1) THEN pv.session_id END) AS bounce_sessions,
					COALESCE(AVG(NULLIF(pv.scroll_depth, 0)), 0) AS scroll_depth
				FROM {$pageviews} pv
				LEFT JOIN {$sessions} s ON s.id = pv.session_id
				WHERE {$where}
				GROUP BY gid
				ORDER BY sessions DESC{$limit}",
				array_merge( $first, $params )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( (int) $r['gid'] <= 0 ) {
				continue;
			}
			$out[ (int) $r['gid'] ] = array(
				'sessions'        => (int) $r['sessions'],
				'bounce_sessions' => (int) $r['bounce_sessions'],
				'scroll_depth'    => (float) $r['scroll_depth'],
			);
		}

		return $out;
	}

	/**
	 * Site averages over the period (same universe as the page rows).
	 *
	 * @param array $context Context.
	 * @return array { sessions, scroll_depth, bounce_rate } or empty.
	 */
	private static function query_site_row( $context ) {
		global $wpdb;

		$pageviews = $wpdb->prefix . 'optibehavior_pageviews';
		$sessions  = $wpdb->prefix . 'optibehavior_sessions';
		$scope     = self::scope_sql( $context );
		$where     = 'pv.view_time >= %s AND pv.view_time < %s AND s.id IS NOT NULL' . $scope['sql'];
		$params    = array_merge( array( $context['sql_start'], $context['sql_end'] ), $scope['params'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- See query_page_rows().
		$r = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pv.session_id) AS sessions,
					COUNT(DISTINCT CASE WHEN s.is_bounce = 1 AND (s.page_views IS NULL OR s.page_views <= 1) THEN pv.session_id END) AS bounce_sessions,
					COALESCE(AVG(NULLIF(pv.scroll_depth, 0)), 0) AS scroll_depth
				FROM {$pageviews} pv
				LEFT JOIN {$sessions} s ON s.id = pv.session_id
				WHERE {$where}",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $r ) || (int) $r['sessions'] <= 0 ) {
			return array();
		}

		return array(
			'sessions'     => (int) $r['sessions'],
			'scroll_depth' => (float) $r['scroll_depth'],
			'bounce_rate'  => round( (int) $r['bounce_sessions'] / (int) $r['sessions'] * 100, 1 ),
		);
	}

	/**
	 * URL, title and post id of pages.
	 *
	 * @param array $ids Page ids.
	 * @return array page_id => { url, title }
	 */
	private static function query_page_meta( $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'optibehavior_pages';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Lookup on a plugin table; cached by get_preview().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, title FROM {$table} WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['id'] ] = array(
				'url'   => (string) $r['url'],
				'title' => trim( wp_strip_all_tags( (string) $r['title'] ) ),
			);
		}

		return $out;
	}

	/**
	 * Per page: the most clicked element when it is not a link or a control
	 * and takes at least 20 % of the clicks (the Pro "clicks on static" rule,
	 * read from the click events Free stores; no page content is read).
	 * Two grouped queries for all pages.
	 *
	 * @param array $groups  page_id => member ids.
	 * @param array $context Context.
	 * @return array page_id => { clicks, label }
	 */
	private static function query_static_clicks( $groups, $context ) {
		global $wpdb;

		$rep_of = array();
		foreach ( (array) $groups as $rep => $members ) {
			foreach ( (array) $members as $member ) {
				$rep_of[ absint( $member ) ] = (int) $rep;
			}
		}
		unset( $rep_of[0] );
		if ( empty( $rep_of ) ) {
			return array();
		}
		$ids      = array_keys( $rep_of );
		$events   = $wpdb->prefix . 'optibehavior_events';
		$sessions = $wpdb->prefix . 'optibehavior_sessions';
		$scope    = self::scope_sql( $context );
		$where    = 'e.page_id2 IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND e.event IN (' . self::CLICK_EVENTS . ') AND e.insert_at >= %s AND e.insert_at < %s' . $scope['sql'];
		$params   = array_merge( $ids, array( $context['sql_start'], $context['sql_end'] ), $scope['params'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Click events (ids 16 / 17) of plugin tables; the page-id IN() placeholders live in the WHERE fragment and are bound through $wpdb->prepare(); scope SQL from the canonical builder; cached by get_preview().
		$totals = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.page_id2 AS pid, COUNT(*) AS total
				FROM {$events} e LEFT JOIN {$sessions} s ON s.id = e.session_id
				WHERE {$where} GROUP BY e.page_id2",
				$params
			),
			ARRAY_A
		);
		if ( empty( $totals ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.page_id2 AS pid, COALESCE(NULLIF(e.element_selector, ''), NULLIF(e.element_text, ''), e.element_tag) AS el,
					MAX(e.element_text) AS txt, MAX(LOWER(e.element_tag)) AS tag, MAX(e.element_href) AS href, MAX(LOWER(e.element_type)) AS etype,
					MAX(e.element_class) AS cls, MAX(e.element_id) AS eid, COUNT(*) AS c
				FROM {$events} e LEFT JOIN {$sessions} s ON s.id = e.session_id
				WHERE {$where} AND LOWER(e.element_tag) NOT IN ('html', 'body')
				GROUP BY e.page_id2, el HAVING c >= " . (int) self::ROW_MIN_CLICKS . ' ORDER BY c DESC LIMIT 600',
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// The URL variants of a page answer for the page.
		$total = array();
		foreach ( (array) $totals as $t ) {
			$pid = isset( $rep_of[ (int) $t['pid'] ] ) ? $rep_of[ (int) $t['pid'] ] : 0;
			if ( $pid > 0 ) {
				$total[ $pid ] = ( isset( $total[ $pid ] ) ? $total[ $pid ] : 0 ) + (int) $t['total'];
			}
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $rows as $r ) {
			$pid = isset( $rep_of[ (int) $r['pid'] ] ) ? $rep_of[ (int) $r['pid'] ] : 0;
			if ( $pid <= 0 || isset( $seen[ $pid ] ) || empty( $total[ $pid ] ) ) {
				continue;
			}
			// Cookie / consent banners collect clicks on every page: not a page finding.
			if ( preg_match( '/consent|cookie|gdpr/i', $r['el'] . ' ' . $r['cls'] . ' ' . $r['eid'] ) ) {
				continue;
			}
			// Only the most clicked element of the page is judged.
			$seen[ $pid ] = true;
			$tag          = (string) $r['tag'];
			$etype        = (string) $r['etype'];
			$interactive  = in_array( $tag, array( 'a', 'button', 'input', 'select', 'textarea', 'label', 'summary', 'option' ), true ) || in_array( $etype, array( 'button', 'submit', 'link' ), true ) || '' !== trim( (string) $r['href'] );
			$share        = (int) round( (int) $r['c'] / $total[ $pid ] * 100 );
			if ( $interactive || $share < 20 ) {
				continue;
			}
			$text        = trim( (string) $r['txt'] );
			$out[ $pid ] = array(
				'clicks' => (int) $r['c'],
				'label'  => mb_substr( sanitize_text_field( '' !== $text ? $text : (string) $r['el'] ), 0, 60 ),
			);
		}

		return $out;
	}

	/**
	 * Funnel drop facts: the page is a step of an active funnel, at least 10
	 * visitors reached it and fewer than half went on (the Pro rule).
	 *
	 * @param array $picked  page_id => row.
	 * @param array $meta    page_id => meta.
	 * @param array $context Context.
	 * @return array page_id => facts.
	 */
	private static function funnel_facts( $picked, $meta, $context ) {
		global $wpdb;

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Funnel_Aggregator' ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'opti_behavior_funnels';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe; result cached by get_preview().
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return array();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Funnel definitions (plugin table, name from $wpdb->prefix); cached by get_preview().
		// OPT-1619: suggested template funnels (not confirmed) are left out.
		$confirmed = class_exists( 'Opti_Behavior_Funnel_Database' ) ? Opti_Behavior_Funnel_Database::confirmed_sql() : '';
		$funnels   = (array) $wpdb->get_results( "SELECT id, name, steps FROM {$table} WHERE COALESCE(status, 'active') IN ('active', ''){$confirmed} LIMIT 200", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( empty( $funnels ) ) {
			return array();
		}

		$matches = array();
		foreach ( $picked as $page_id => $row ) {
			$url = isset( $meta[ $page_id ] ) ? (string) $meta[ $page_id ]['url'] : '';
			foreach ( $funnels as $f ) {
				$steps = json_decode( (string) $f['steps'], true );
				if ( ! is_array( $steps ) || empty( $steps ) ) {
					continue;
				}
				$steps = array_values( $steps );
				foreach ( $steps as $i => $step ) {
					if ( ! is_array( $step ) ) {
						continue;
					}
					$pattern = isset( $step['url_pattern'] ) ? (string) $step['url_pattern'] : ( isset( $step['url'] ) ? (string) $step['url'] : '' );
					$type    = isset( $step['match_type'] ) ? (string) $step['match_type'] : 'contains';
					if ( '' !== $pattern && '*' !== $pattern && self::url_matches( $url, $pattern, $type ) ) {
						$matches[ $page_id ][] = array(
							'id'    => (int) $f['id'],
							'name'  => sanitize_text_field( (string) $f['name'] ),
							'step'  => $i + 1,
							'steps' => count( $steps ),
							'defs'  => $steps,
						);
						break;
					}
				}
			}
		}
		if ( empty( $matches ) ) {
			return array();
		}

		try {
			$aggregator = new Opti_Behavior_Smart_Insights_Funnel_Aggregator();
			$metrics    = $aggregator->get_funnel_metrics(
				$context['sql_start'],
				$context['date_to'] . ' 23:59:59',
				array(
					'limit'        => 200,
					'exclude_spam' => $context['exclude_spam'],
				)
			);
		} catch ( Throwable $e ) {
			return array();
		}
		$by_id = array();
		foreach ( (array) $metrics as $m ) {
			if ( is_array( $m ) && isset( $m['funnel_id'] ) ) {
				$by_id[ (int) $m['funnel_id'] ] = $m;
			}
		}

		$out = array();
		foreach ( $matches as $page_id => $list ) {
			// Funnels that track the same steps are one finding: the one with
			// the most entries answers (tie: the oldest id).
			$kept = array();
			foreach ( $list as $match ) {
				$m       = isset( $by_id[ $match['id'] ] ) ? $by_id[ $match['id'] ] : null;
				$reached = 0;
				$next    = null;
				foreach ( ( $m && ! empty( $m['steps'] ) ) ? (array) $m['steps'] : array() as $s ) {
					if ( (int) $s['step_number'] === $match['step'] ) {
						$reached = (int) $s['reached'];
					}
					if ( (int) $s['step_number'] === $match['step'] + 1 ) {
						$next = (int) $s['reached'];
					}
				}
				$match['entries'] = $m ? (int) $m['entries'] : 0;
				$match['reached'] = $reached;
				$match['next']    = $next;
				$key              = md5( wp_json_encode( array( $match['step'], Opti_Behavior_Smart_Insights_Funnel_Aggregator::normalize_step_definitions( $match['defs'] ) ) ) );
				if ( isset( $kept[ $key ] ) && ! ( $match['entries'] > $kept[ $key ]['entries'] || ( $match['entries'] === $kept[ $key ]['entries'] && $match['id'] < $kept[ $key ]['id'] ) ) ) {
					continue;
				}
				$kept[ $key ] = $match;
			}
			foreach ( $kept as $match ) {
				$continue = ( null !== $match['next'] && $match['reached'] > 0 ) ? (int) round( $match['next'] / $match['reached'] * 100 ) : null;
				if ( null !== $continue && $match['step'] < $match['steps'] && $match['reached'] >= 10 && $continue < 50 ) {
					$out[ $page_id ][] = array(
						'type'     => 'funnel_drop',
						'count'    => $match['reached'] - (int) $match['next'],
						'sessions' => $match['reached'] - (int) $match['next'],
						'sample'   => $match['name'],
					);
				}
			}
		}

		return $out;
	}

	/**
	 * PHP twin of the funnel tracker's urlMatchesPattern() (as in the Pro dossier).
	 *
	 * @param string $url     Page URL.
	 * @param string $pattern Step pattern.
	 * @param string $type    Match type.
	 * @return bool
	 */
	public static function url_matches( $url, $pattern, $type ) {
		$url     = (string) $url;
		$pattern = (string) $pattern;
		$type    = strtolower( trim( (string) $type ) );
		if ( in_array( $type, array( 'any', 'pageview' ), true ) && '' !== $pattern && '*' !== $pattern ) {
			$type = 'contains';
		}
		switch ( $type ) {
			case 'any':
				return true;
			case 'exact':
				$u = self::exact_key( $url, false !== strpos( $pattern, '?' ) );
				$p = self::exact_key( $pattern, false !== strpos( $pattern, '?' ) );
				if ( '' !== $p['host'] && $u['host'] !== $p['host'] ) {
					return false;
				}
				return $u['path'] === $p['path'];
			case 'contains':
				return false !== stripos( $url, $pattern );
			case 'starts_with':
				return 0 === stripos( $url, $pattern );
			case 'ends_with':
				return '' !== $pattern && strtolower( substr( $url, -strlen( $pattern ) ) ) === strtolower( $pattern );
			case 'regex':
				$re = '~' . str_replace( '~', '\~', $pattern ) . '~';
				return 1 === @preg_match( $re, $url ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- User-supplied pattern; an invalid one simply does not match.
		}

		return false;
	}

	/**
	 * Normalised host + path of a URL or path pattern (exact matching).
	 *
	 * @param string $value      URL or path.
	 * @param bool   $keep_query Whether the query string is part of the key.
	 * @return array { host, path }
	 */
	private static function exact_key( $value, $keep_query ) {
		$value = trim( (string) $value );
		$parts = wp_parse_url( 0 === strpos( $value, '/' ) ? 'http://x' . $value : $value );
		$host  = isset( $parts['host'] ) && 'x' !== $parts['host'] ? strtolower( $parts['host'] ) : '';
		$path  = isset( $parts['path'] ) ? strtolower( rtrim( $parts['path'], '/' ) ) : '';
		if ( '' === $path ) {
			$path = '/';
		}
		if ( $keep_query && ! empty( $parts['query'] ) ) {
			$path .= '?' . strtolower( $parts['query'] );
		}

		return array(
			'host' => $host,
			'path' => $path,
		);
	}
}
