<?php
/**
 * Site report, short version (OPT-1340, Free): provider of `type=site` for the
 * report engine (`Opti_Behavior_Report`), design OPT-1338
 * (`qa/design-opt-1338/short.html`).
 *
 * Sections: head (site, period, compared period, visits), 1 Traffic (the
 * Traffic screen's own figures for the period), 2 The 3 most costly issues
 * (active Smart Insights cards ranked by
 * Opti_Behavior_Smart_Insights_Impact_Calculator::compare_by_impact(), cards
 * without an impact block after), then one sober line about the full report.
 * Nothing is computed here: every number comes from the screens' code through
 * Opti_Behavior_Heatmap_Dashboard::get_report_site_data(). No full address,
 * no IP, no visitor.
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short site report provider.
 */
class Opti_Behavior_Report_Site {

	/** Issues printed. */
	const TOP_ISSUES = 3;

	/** Sources printed before "Other sources". */
	const TOP_SOURCES = 5;

	/** Most viewed pages printed. */
	const TOP_PAGES = 5;

	/** Share (%) under which a device segment of the stacked bar carries no words. */
	const SPLIT_LABEL_MIN = 15;

	/** More words than this in a clicked text element = a sentence being read, not a click target (QA OPT-1730). */
	const READING_WORDS = 6;

	/** Funnels printed in "Journeys and funnels" (OPT-1682). */
	const TOP_FUNNELS = 5;

	/** A/B tests printed, most visitors in the period first (OPT-1682). */
	const TOP_AB_TESTS = 2;

	/** Product pages printed in "Shop" (OPT-1683). */
	const TOP_SHOP = 3;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'opti_behavior_report_sections', array( __CLASS__, 'sections' ), 10, 3 );
	}

	/**
	 * The dashboard object that owns the screens' queries.
	 *
	 * @return object|null
	 */
	public static function dashboard() {
		$core = class_exists( 'Opti_Behavior_Heatmap_Core' ) && method_exists( 'Opti_Behavior_Heatmap_Core', 'get_instance' ) ? Opti_Behavior_Heatmap_Core::get_instance() : null;
		$dash = $core && method_exists( $core, 'get_dashboard' ) ? $core->get_dashboard() : null;
		if ( ! is_object( $dash ) && $core ) {
			// WP-CLI / front request: the core builds the dashboard in wp-admin only.
			if ( ! class_exists( 'Opti_Behavior_Heatmap_Dashboard' ) && defined( 'OPTI_BEHAVIOR_HEATMAP_ADMIN_DIR' ) && is_readable( OPTI_BEHAVIOR_HEATMAP_ADMIN_DIR . 'class-opti-behavior-heatmap-dashboard.php' ) ) {
				require_once OPTI_BEHAVIOR_HEATMAP_ADMIN_DIR . 'class-opti-behavior-heatmap-dashboard.php';
			}
			$dash = class_exists( 'Opti_Behavior_Heatmap_Dashboard' ) ? new Opti_Behavior_Heatmap_Dashboard( $core ) : null;
		}

		return is_object( $dash ) && method_exists( $dash, 'get_report_site_data' ) ? $dash : null;
	}

	/**
	 * Answer the engine for `type=site`.
	 *
	 * @param array  $sections Sections of earlier providers.
	 * @param string $type     Report type.
	 * @param array  $args     Cleaned report arguments.
	 * @return array
	 */
	public static function sections( $sections, $type, $args ) {
		$sections = is_array( $sections ) ? $sections : array();
		if ( 'site' !== $type ) {
			return $sections;
		}
		$ctx = null;
		try {
			$dash = self::dashboard();
			if ( $dash ) {
				$ctx = self::context( $dash->get_report_site_data( $args ), $args, $dash );
			}
		} catch ( Throwable $e ) {
			$ctx = null;
		}

		$list = array( array( 'site-head', 'render_head' ) );
		if ( is_array( $ctx ) && $ctx['visits'] > 0 ) {
			// OPT-1681: top clicked elements + day x hour grid, read here (not on the e-mail path).
			try {
				$ctx['clicks'] = self::click_rows( $ctx['from'], $ctx['to'], $ctx['spam'] );
				$ctx['hours']  = self::hour_grid( $ctx['from'], $ctx['to'], $ctx['spam'] );
			} catch ( Throwable $e ) {
				$ctx['clicks'] = array();
				$ctx['hours']  = array();
			}
			// OPT-1682: confirmed funnels + A/B tests with visits in the period.
			try {
				$ctx['funnels'] = self::funnel_rows( $ctx['from'], $ctx['to'], $ctx['spam'], $ctx['issues'] );
			} catch ( Throwable $e ) {
				$ctx['funnels'] = array();
			}
			try {
				$ctx['ab'] = self::ab_rows( $ctx['from'], $ctx['to'], $ctx['spam'] );
			} catch ( Throwable $e ) {
				$ctx['ab'] = array();
			}
			// OPT-1683: WooCommerce counters (no visitor, no order number).
			try {
				$ctx['shop'] = self::shop_rows( $ctx['from'], $ctx['to'] );
			} catch ( Throwable $e ) {
				$ctx['shop'] = null;
			}
			// Blocks of the full version (Pro, OPT-1684 / OPT-1685): escaped HTML,
			// '' = nothing. Filled only when Pro answers for these arguments.
			$ctx['forms_pro']    = self::pro_html( 'opti_behavior_report_site_forms', $ctx, $args );
			$ctx['clicks_pro']   = self::pro_html( 'opti_behavior_report_site_clicks', $ctx, $args );
			$ctx['journeys_pro'] = self::pro_html( 'opti_behavior_report_site_journeys', $ctx, $args );
			// OPT-1680..1683 (design OPT-1666): Summary, Issues, Shop, (Forms), Clicks,
			// Traffic, Journeys and funnels, Busiest time, A/B test; an empty block is
			// left out and the numbers follow.
			$list[] = array( 'site-summary', 'render_summary' );
			if ( $ctx['issues'] ) {
				$list[] = array( 'site-issues', 'render_issues' );
			}
			if ( $ctx['shop'] ) {
				$list[] = array( 'site-shop', 'render_shop' );
			}
			if ( '' !== $ctx['forms_pro'] ) {
				$list[] = array( 'site-forms', 'render_forms' );
			} elseif ( ! self::pro_on() ) {
				$list[] = array( 'site-forms-pro', 'render_forms_line' );
			}
			if ( $ctx['clicks'] || '' !== $ctx['clicks_pro'] ) {
				$list[] = array( 'site-clicks', 'render_clicks' );
			}
			$list[] = array( 'site-traffic', 'render_traffic' );
			if ( $ctx['funnels'] || '' !== $ctx['journeys_pro'] ) {
				$list[] = array( 'site-journeys', 'render_funnels' );
			}
			if ( self::hours_peak( $ctx['hours'] ) ) {
				$list[] = array( 'site-busiest', 'render_busiest' );
			}
			if ( $ctx['ab'] ) {
				$list[] = array( 'site-ab', 'render_ab' );
			}
		}
		$list[] = array( 'site-more', 'render_more' );
		$no     = 0;
		foreach ( $list as $item ) {
			$numbered   = ! in_array( $item[0], array( 'site-head', 'site-more', 'site-forms-pro' ), true );
			$no        += $numbered ? 1 : 0;
			$sections[] = array(
				'id'     => $item[0],
				'title'  => '',
				'tier'   => 'free',
				'render' => array( __CLASS__, $item[1] ),
				'data'   => array(
					'ctx'  => $ctx,
					'args' => $args,
					'no'   => $numbered ? $no : 0,
				),
			);
		}

		return $sections;
	}

	/**
	 * Everything the sections print, from the screens' data.
	 *
	 * @param array  $data Result of get_report_site_data().
	 * @param array  $args Report arguments.
	 * @param object $dash Dashboard (shapes the printed insights), or null.
	 * @return array
	 */
	public static function context( $data, $args, $dash = null ) {
		$traffic = isset( $data['traffic'] ) && is_array( $data['traffic'] ) ? $data['traffic'] : array();
		$stats   = isset( $traffic['stats'] ) && is_array( $traffic['stats'] ) ? $traffic['stats'] : array();
		$range   = isset( $traffic['date_range'] ) && is_array( $traffic['date_range'] ) ? $traffic['date_range'] : array();
		$from    = isset( $range['start'] ) ? substr( (string) $range['start'], 0, 10 ) : ( isset( $args['from'] ) ? (string) $args['from'] : '' );
		$to      = isset( $range['end'] ) ? substr( (string) $range['end'], 0, 10 ) : ( isset( $args['to'] ) ? (string) $args['to'] : '' );
		$days    = self::days_between( $from, $to );

		$rows   = isset( $data['insights']['rows'] ) && is_array( $data['insights']['rows'] ) ? $data['insights']['rows'] : array();
		$caps   = class_exists( 'Opti_Behavior_Smart_Insights_Capabilities' ) ? new Opti_Behavior_Smart_Insights_Capabilities() : null;
		$open   = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! ( $caps && $caps->is_locked_for_viewer( $row ) ) ) {
				$open[] = $row;
			}
		}
		// OPT-1618: one story = one issue (its children ride on the primary), as on the screen.
		$si_repo = class_exists( 'Opti_Behavior_Smart_Insights_Repository' ) && method_exists( 'Opti_Behavior_Smart_Insights_Repository', 'summarize_open_problems' );
		if ( $si_repo ) {
			$open = Opti_Behavior_Smart_Insights_Repository::drop_story_children( $open );
		}
		$ranked = self::rank_issues( $open );
		$top    = array_slice( $ranked, 0, self::TOP_ISSUES );
		if ( $top && $dash && method_exists( $dash, 'shape_report_insights' ) ) {
			$top = array_values( (array) $dash->shape_report_insights( $top ) );
		}

		return array(
			'from'         => $from,
			'to'           => $to,
			'days'         => $days,
			'prev_from'    => $days > 0 ? self::shift_day( $from, -$days ) : '',
			'prev_to'      => $days > 0 ? self::shift_day( $from, -1 ) : '',
			'visits'       => isset( $stats['sessions'] ) ? absint( $stats['sessions'] ) : 0,
			'stats'        => $stats,
			'changes'      => isset( $traffic['changes'] ) && is_array( $traffic['changes'] ) ? $traffic['changes'] : array(),
			'not_comparable' => ! empty( $traffic['changes_not_comparable'] ),
			'sources'    => isset( $traffic['referrers'] ) && is_array( $traffic['referrers'] ) ? $traffic['referrers'] : array(),
			'devices'      => isset( $traffic['device_types'] ) && is_array( $traffic['device_types'] ) ? $traffic['device_types'] : array(),
			// OPT-1680: the Traffic chart's days and the New vs returning widget.
			'daily'        => isset( $traffic['sessions_chart'] ) && is_array( $traffic['sessions_chart'] ) ? array_values( $traffic['sessions_chart'] ) : array(),
			'returning'    => isset( $traffic['new_vs_returning'] ) && is_array( $traffic['new_vs_returning'] ) ? $traffic['new_vs_returning'] : array(),
			'pages'        => isset( $traffic['top_pages'] ) && is_array( $traffic['top_pages'] ) ? array_slice( array_values( $traffic['top_pages'] ), 0, self::TOP_PAGES ) : array(),
			'issues'       => $top,
			// OPT-1618: every open problem, counted like the Smart Insights screen
			// and the menu (locked previews included; they are not printed).
			'issues_total' => $si_repo ? count( Opti_Behavior_Smart_Insights_Repository::drop_story_children( array_values( array_filter( $rows, 'is_array' ) ) ) ) : count( $ranked ),
			'spam'         => ! empty( $args['exclude_spam'] ),
		);
	}

	/**
	 * Order of the issues (pure): cards with an impact block first, in
	 * compare_by_impact() order (measured amount first, else visits lost);
	 * cards without one after. Ties keep the order received (the list order).
	 *
	 * @param array $rows Insight rows.
	 * @return array
	 */
	public static function rank_issues( $rows ) {
		$list = array();
		foreach ( array_values( is_array( $rows ) ? $rows : array() ) as $i => $row ) {
			if ( is_array( $row ) ) {
				$list[] = array( $i, $row, self::has_impact( $row ) );
			}
		}
		usort(
			$list,
			function ( $a, $b ) {
				if ( $a[2] !== $b[2] ) {
					return $a[2] ? -1 : 1;
				}
				if ( $a[2] ) {
					$c = Opti_Behavior_Smart_Insights_Impact_Calculator::compare_by_impact( $a[1], $b[1] );
					if ( 0 !== $c ) {
						return $c;
					}
				}

				return $a[0] <=> $b[0];
			}
		);

		return array_map(
			function ( $e ) {
				return $e[1];
			},
			$list
		);
	}

	/**
	 * Whether an insight carries an impact block (visits lost or a measured amount).
	 *
	 * @param array $insight Insight.
	 * @return bool
	 */
	public static function has_impact( $insight ) {
		return null !== Opti_Behavior_Smart_Insights_Impact_Calculator::get_users_lost( $insight )
			|| null !== Opti_Behavior_Smart_Insights_Impact_Calculator::get_revenue_amount( $insight );
	}

	/**
	 * Where an issue is, in words (pure): the path of its page (never the
	 * domain), the step page of a funnel, the scope of a device / segment card.
	 *
	 * @param array $insight Insight.
	 * @return string
	 */
	public static function where_of( $insight ) {
		$insight = is_array( $insight ) ? $insight : array();
		$type    = isset( $insight['entity_type'] ) ? sanitize_key( (string) $insight['entity_type'] ) : '';
		$id      = isset( $insight['entity_id'] ) ? (string) $insight['entity_id'] : '';
		$label   = isset( $insight['entity_label'] ) ? trim( (string) $insight['entity_label'] ) : '';
		$metrics = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();

		if ( 'funnel' === $type ) {
			$step = isset( $insight['detection']['worst_transition'] ) && is_array( $insight['detection']['worst_transition'] ) ? $insight['detection']['worst_transition'] : array();
			$url  = ! empty( $step['from_url'] ) ? (string) $step['from_url'] : ( ! empty( $step['to_url'] ) ? (string) $step['to_url'] : '' );
			/* translators: %s: path of a page (no domain). */
			return '' !== $url ? sprintf( __( '%s (a funnel step page)', 'opti-behavior' ), Opti_Behavior_Report::path_of( $url ) ) : __( 'A funnel step page', 'opti-behavior' );
		}
		if ( 'device' === $type ) {
			$scopes = array(
				'mobile'  => __( 'Every page, mobile visits', 'opti-behavior' ),
				'desktop' => __( 'Every page, desktop visits', 'opti-behavior' ),
				'tablet'  => __( 'Every page, tablet visits', 'opti-behavior' ),
			);
			$key    = strtolower( $id );
			$text   = isset( $scopes[ $key ] ) ? $scopes[ $key ] : __( 'Every page', 'opti-behavior' );
			if ( ! empty( $metrics['sessions'] ) ) {
				/* translators: %s: number of visits. */
				$text .= ' (' . sprintf( _n( '%s visit', '%s visits', (int) $metrics['sessions'], 'opti-behavior' ), number_format_i18n( (int) $metrics['sessions'] ) ) . ')';
			}

			return $text;
		}
		if ( 'form' === $type && '' !== $label ) {
			/* translators: %s: form name. */
			return sprintf( __( 'Form “%s”', 'opti-behavior' ), self::no_address( $label ) );
		}
		if ( 'error' === $type && 'site' === $id ) {
			return __( 'Every page', 'opti-behavior' );
		}
		if ( 'segment' === $type && '' !== $label ) {
			/* translators: %s: group of visits, e.g. "Device type: mobile". */
			return sprintf( __( 'Every page, %s', 'opti-behavior' ), self::no_address( $label ) );
		}
		foreach ( array( isset( $metrics['page_url'] ) ? $metrics['page_url'] : '', isset( $metrics['entity_context']['view_url'] ) ? $metrics['entity_context']['view_url'] : '', $id ) as $url ) {
			if ( is_string( $url ) && preg_match( '#^https?://#i', $url ) ) {
				return Opti_Behavior_Report::path_of( $url );
			}
		}

		return '' !== $label ? self::no_address( $label ) : __( 'Every page', 'opti-behavior' );
	}

	/**
	 * A text without any address in it: an address becomes its path.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function no_address( $text ) {
		return (string) preg_replace_callback(
			'#\bhttps?://[^\s"“”<>]+#i',
			function ( $m ) {
				return Opti_Behavior_Report::path_of( $m[0] );
			},
			(string) $text
		);
	}

	/* ----------------------------------------------------------------- *
	 * Scheduled report e-mail = short version of this report (OPT-1553).
	 * ----------------------------------------------------------------- */

	/**
	 * Context of this report for a period of the scheduled e-mail: the same
	 * call as the `type=site` sections, so the e-mail prints the figures of
	 * the PDF of the same days.
	 *
	 * @param string $from         First day (Y-m-d).
	 * @param string $to           Last day (Y-m-d).
	 * @param bool   $exclude_spam Spam visits left out.
	 * @return array|null Context of context(), null when the data cannot be read.
	 */
	public static function email_context( $from, $to, $exclude_spam ) {
		$args = array(
			'type'         => 'site',
			'period'       => '',
			'from'         => (string) $from,
			'to'           => (string) $to,
			'exclude_spam' => (bool) $exclude_spam,
		);
		try {
			$dash = self::dashboard();
			if ( ! $dash ) {
				return null;
			}

			return self::context( $dash->get_report_site_data( $args ), $args, $dash );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Rows of the latest Smart Insights run (pure, OPT-1727): the newest
	 * date_to, then the newest date_from; order kept. Read by the dashboard
	 * get_report_site_data() when no card lies inside the report period.
	 *
	 * @param array $rows Insight rows.
	 * @return array
	 */
	public static function latest_run_rows( $rows ) {
		$best = '';
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$key  = (string) ( $row['date_to'] ?? '' ) . '|' . (string) ( $row['date_from'] ?? '' );
				$best = strcmp( $key, $best ) > 0 ? $key : $best;
			}
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && (string) ( $row['date_to'] ?? '' ) . '|' . (string) ( $row['date_from'] ?? '' ) === $best ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * What the e-mail prints, from a context (pure): plain texts and numbers,
	 * escaped by the e-mail views. Same choices as the sections of the PDF:
	 * 4 tiles with their change, the 3 most costly issues, sources top 5 +
	 * "Other sources", devices, the 5 most viewed pages.
	 *
	 * @param array|null $ctx Result of context().
	 * @return array|null
	 */
	public static function email_summary( $ctx ) {
		if ( ! is_array( $ctx ) ) {
			return null;
		}
		$st    = isset( $ctx['stats'] ) && is_array( $ctx['stats'] ) ? $ctx['stats'] : array();
		$ch    = isset( $ctx['changes'] ) && is_array( $ctx['changes'] ) ? $ctx['changes'] : array();
		$total = max( 1, (int) $ctx['visits'] );
		$views = isset( $st['pageviews'] ) ? (int) $st['pageviews'] : 0;
		$defs  = array(
			array( 'sessions', __( 'Visits', 'opti-behavior' ), number_format_i18n( (int) $ctx['visits'] ), true, _x( 'visits', 'site report comparison', 'opti-behavior' ) ),
			array( 'pageviews', __( 'Page views', 'opti-behavior' ), number_format_i18n( $views ), true, _x( 'page views', 'site report comparison', 'opti-behavior' ) ),
			array( 'avg_session_time', __( 'Time per visit', 'opti-behavior' ), self::secs( isset( $st['avg_session_time'] ) ? (float) $st['avg_session_time'] : 0 ), true, _x( 'time per visit', 'site report comparison', 'opti-behavior' ) ),
			array( 'bounce_rate', __( 'Bounce', 'opti-behavior' ), self::pct( isset( $st['bounce_rate'] ) ? $st['bounce_rate'] : 0 ), false, _x( 'bounce', 'site report comparison', 'opti-behavior' ) ),
		);
		$tiles   = array();
		$missing = array();
		foreach ( $defs as $d ) {
			$change = array_key_exists( $d[0], $ch ) && is_numeric( $ch[ $d[0] ] ) ? (float) $ch[ $d[0] ] : null;
			$tile   = array(
				'key'    => $d[0],
				'label'  => $d[1],
				'value'  => $d[2],
				'change' => $change,
				'text'   => '',
				'good'   => null,
			);
			if ( null === $change ) {
				$missing[] = $d[4];
			} else {
				$tile['text'] = self::change_text( $change, self::change_unit( $d[0] ) );
				$tile['good'] = 0.0 === $change || ( $change > 0 ) === $d[3];
			}
			$tiles[] = $tile;
		}
		$note = '';
		if ( $missing && '' !== $ctx['prev_from'] && ! empty( $ctx['not_comparable'] ) ) {
			$note = self::not_comparable_note( $ctx );
		} elseif ( $missing && '' !== $ctx['prev_from'] ) {
			/* translators: 1: dates of the period before, 2: list of figures. */
			$note = sprintf( __( 'No comparison with %1$s for %2$s: too few visits in that period.', 'opti-behavior' ), self::range_text( $ctx['prev_from'], $ctx['prev_to'] ), wp_sprintf( '%l', $missing ) );
		}

		$sources = self::rows( $ctx['sources'], 'referrer' );
		$top     = array_slice( $sources, 0, self::TOP_SOURCES );
		$rest    = (int) $ctx['visits'] - array_sum( array_column( $top, 1 ) );
		if ( $rest > 0 && count( $sources ) > self::TOP_SOURCES ) {
			$top[] = array( __( 'Other sources', 'opti-behavior' ), $rest );
		}
		$shares = function ( $rows ) use ( $total ) {
			$out = array();
			foreach ( $rows as $r ) {
				$out[] = array(
					'label' => (string) $r[0],
					'count' => (int) $r[1],
					'share' => self::share( $r[1], $total ),
				);
			}
			return $out;
		};

		$pages = array();
		foreach ( $ctx['pages'] as $p ) {
			$p      = is_array( $p ) ? $p : array();
			$path   = Opti_Behavior_Report::path_of( isset( $p['url'] ) ? (string) $p['url'] : '' );
			$title  = self::strip_site( isset( $p['title'] ) ? wp_specialchars_decode( (string) $p['title'], ENT_QUOTES ) : '' );
			$pages[] = array(
				'title'  => '' !== trim( $title ) ? self::no_address( $title ) : $path,
				'path'   => $path,
				'visits' => isset( $p['sessions'] ) ? (int) $p['sessions'] : 0,
				'views'  => isset( $p['views'] ) ? (int) $p['views'] : 0,
			);
		}

		$issues = array();
		foreach ( $ctx['issues'] as $i => $it ) {
			$issues[] = self::email_issue( $i + 1, $it, $total );
		}

		return array(
			'from'         => $ctx['from'],
			'to'           => $ctx['to'],
			'days'         => (int) $ctx['days'],
			'prev_from'    => $ctx['prev_from'],
			'prev_to'      => $ctx['prev_to'],
			'period_text'  => '' !== $ctx['from'] ? self::range_text( $ctx['from'], $ctx['to'] ) : '',
			'prev_text'    => '' !== $ctx['prev_from'] ? self::range_text( $ctx['prev_from'], $ctx['prev_to'] ) : '',
			'visits'       => (int) $ctx['visits'],
			'tiles'        => $tiles,
			'note'         => $note,
			'sources'      => $shares( $top ),
			'devices'      => $shares( self::rows( $ctx['devices'], 'name' ) ),
			'pages'        => $pages,
			'issues'       => $issues,
			'issues_total' => (int) $ctx['issues_total'],
		);
	}

	/**
	 * One issue of the e-mail (pure): the fields of issue_card(), as text.
	 *
	 * @param int   $rank  Rank (1-3).
	 * @param array $it    Shaped insight.
	 * @param int   $total Visits of the period.
	 * @return array { rank, severity, word, title, headline, where, action, lost, share, money }
	 */
	public static function email_issue( $rank, $it, $total ) {
		$it    = is_array( $it ) ? $it : array();
		$sev   = self::severity( isset( $it['scores']['priority_label_key'] ) ? (string) $it['scores']['priority_label_key'] : '' );
		$title = '';
		foreach ( array( 'card_title', 'signal_name', 'entity_label' ) as $key ) {
			if ( '' === $title && ! empty( $it[ $key ] ) && is_string( $it[ $key ] ) ) {
				$title = $it[ $key ];
			}
		}
		$lost   = Opti_Behavior_Smart_Insights_Impact_Calculator::get_users_lost( $it );
		$amount = Opti_Behavior_Smart_Insights_Impact_Calculator::get_revenue_amount( $it );
		$action = isset( $it['next_action_short'] ) && is_string( $it['next_action_short'] ) ? $it['next_action_short'] : '';

		return array(
			'rank'     => (int) $rank,
			'severity' => $sev[0],
			'word'     => $sev[2],
			'title'    => self::no_address( $title ),
			'headline' => isset( $it['impact']['headline'] ) && is_string( $it['impact']['headline'] ) ? self::no_address( $it['impact']['headline'] ) : '',
			'where'    => self::where_of( $it ),
			'action'   => self::no_address( $action ),
			'lost'     => null === $lost ? null : (int) $lost,
			'share'    => null === $lost ? null : (int) min( 100, round( 100 * $lost / max( 1, $total ) ) ),
			'money'    => null === $amount ? '' : self::money( $amount, isset( $it['impact']['revenue']['currency'] ) ? (string) $it['impact']['revenue']['currency'] : '' ),
		);
	}

	/* ----------------------------------------------------------------- *
	 * Sections: each returns its HTML, every value escaped here.
	 * ----------------------------------------------------------------- */

	/**
	 * Head of page 1: kicker, site name, address, period, compared period,
	 * device, visits. A state line when the data cannot be read or there is no visit.
	 *
	 * @param array $data { ctx, args }.
	 * @return string
	 */
	public static function render_head( $data ) {
		$ctx  = isset( $data['ctx'] ) ? $data['ctx'] : null;
		$html = '<div class="xr-title"><div class="xr-kicker">' . esc_html__( 'Site report', 'opti-behavior' ) . '</div>';
		$html .= '<h1>' . esc_html( Opti_Behavior_Report::site_name() ) . '</h1>';
		$html .= '<p class="xr-address">' . esc_html( (string) preg_replace( '#^https?://#i', '', untrailingslashit( home_url() ) ) ) . '</p></div>';
		if ( ! is_array( $ctx ) ) {
			return $html . '<div class="xr-section">' . self::state( '<b>' . esc_html__( 'The figures of this report could not be read.', 'opti-behavior' ) . '</b> ' . esc_html__( 'Open the Traffic screen once, then generate the report again.', 'opti-behavior' ), true ) . '</div>';
		}
		$html .= '<dl class="xr-meta">';
		/* translators: %s: number of days. */
		$html .= '<div><dt>' . esc_html__( 'Period', 'opti-behavior' ) . '</dt><dd>' . esc_html( self::day_long( $ctx['from'] ) . ' – ' . self::day_long( $ctx['to'] ) ) . '<span>' . esc_html( sprintf( _n( '%s day', '%s days', $ctx['days'], 'opti-behavior' ), number_format_i18n( $ctx['days'] ) ) ) . '</span></dd></div>';
		if ( '' !== $ctx['prev_from'] ) {
			/* translators: %s: number of days. */
			$html .= '<div><dt>' . esc_html__( 'Compared with', 'opti-behavior' ) . '</dt><dd>' . esc_html( self::range_text( $ctx['prev_from'], $ctx['prev_to'] ) ) . '<span>' . esc_html( sprintf( _n( 'the %s day before', 'the %s days before', $ctx['days'], 'opti-behavior' ), number_format_i18n( $ctx['days'] ) ) ) . '</span></dd></div>';
		}
		$html .= '<div><dt>' . esc_html__( 'Device', 'opti-behavior' ) . '</dt><dd>' . esc_html__( 'All devices', 'opti-behavior' ) . '</dd></div>';
		$html .= '<div><dt>' . esc_html__( 'Visits', 'opti-behavior' ) . '</dt><dd>' . esc_html( number_format_i18n( $ctx['visits'] ) ) . '<span>' . esc_html( $ctx['spam'] ? __( 'spam visits left out', 'opti-behavior' ) : __( 'spam visits included', 'opti-behavior' ) ) . '</span></dd></div>';
		$html .= '</dl>';
		// Same days, time zone and spam rule as Traffic and Smart Insights
		// (Stats_Date_Range); a gap of a few visits = visits that arrived between
		// two reads (OPT-1626 A5).
		if ( $ctx['visits'] > 0 ) {
			$html .= '<p class="obr-live-note" style="margin:2mm 0 0;font-size:8pt;color:#71717a">' . esc_html__( 'Figures read live when this report was made. Another screen opened earlier or later can show a few more or fewer visits: the difference is visits that arrived between the two reads.', 'opti-behavior' ) . '</p>';
		}
		if ( $ctx['visits'] <= 0 ) {
			$html .= '<div class="xr-section">' . self::state(
				'<b>' . esc_html(
					/* translators: 1: first day, 2: last day of the period. */
					sprintf( __( 'No visit recorded between %1$s and %2$s.', 'opti-behavior' ), self::day_long( $ctx['from'] ), self::day_long( $ctx['to'] ) )
				) . '</b> ' . esc_html__( 'Traffic and issues need at least one day of visits. Pick a longer period or come back tomorrow.', 'opti-behavior' )
			) . '</div>';
		}

		return $html;
	}

	/**
	 * 1 Summary (OPT-1680, design OPT-1666): the 4 tiles of Traffic (moved, not
	 * copied), the comparison note, then "Start here" = a pointer to issue #1,
	 * without its figures (they are printed once, in section 2).
	 *
	 * @param array $data { ctx, args }.
	 * @return string
	 */
	public static function render_summary( $data ) {
		$ctx   = $data['ctx'];
		$html  = '<div class="xr-section obr-summary">' . self::sec_head( self::no_of( $data, 1 ), __( 'Summary', 'opti-behavior' ), esc_html( self::day_long( $ctx['from'] ) . ' – ' . self::day_long( $ctx['to'] ) ) );
		$html .= self::tiles_html( $ctx );

		$first = isset( $ctx['issues'][0] ) && is_array( $ctx['issues'][0] ) ? self::issue_title( $ctx['issues'][0] ) : '';
		$html .= '<div class="xr-first"><div class="xr-label">' . esc_html__( 'Start here', 'opti-behavior' ) . '</div>';
		if ( '' === $first ) {
			$html .= '<p>' . esc_html__( 'No costly issue detected for this period.', 'opti-behavior' ) . '</p>';
		} else {
			$html .= '<h3>' . esc_html( $first ) . self::ref( 1 ) . '</h3>';
			$more  = count( $ctx['issues'] );
			$html .= '<p>' . esc_html(
				$more > 1
					/* translators: %s: number of issues ranked in section 2. */
					? sprintf( _n( 'It costs the most visits of the period. The %s issue after it is ranked in section 2.', 'It costs the most visits of the period. The %s issues after it are ranked in section 2.', $more - 1, 'opti-behavior' ), number_format_i18n( $more - 1 ) )
					: __( 'It costs the most visits of the period. Its figures are in section 2.', 'opti-behavior' )
			) . '</p>';
		}

		return $html . '</div></div>';
	}

	/**
	 * The 4 tiles (Visits, Page views, Time per visit, Bounce) with their
	 * comparison line, or one note naming the figures without comparison.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	private static function tiles_html( $ctx ) {
		$st    = $ctx['stats'];
		$ch    = $ctx['changes'];
		$total = max( 1, $ctx['visits'] );
		$time  = isset( $st['avg_session_time'] ) ? (float) $st['avg_session_time'] : 0;
		$views = isset( $st['pageviews'] ) ? (int) $st['pageviews'] : 0;
		$tiles = array(
			/* translators: %s: number of visitors. */
			array( __( 'Visits', 'opti-behavior' ), number_format_i18n( $ctx['visits'] ), sprintf( _n( '%s visitor', '%s visitors', isset( $st['visitors'] ) ? (int) $st['visitors'] : 0, 'opti-behavior' ), number_format_i18n( isset( $st['visitors'] ) ? (int) $st['visitors'] : 0 ) ), 'sessions', true, _x( 'visits', 'site report comparison', 'opti-behavior' ) ),
			/* translators: %s: page views per visit. */
			array( __( 'Page views', 'opti-behavior' ), number_format_i18n( $views ), sprintf( __( '%s per visit', 'opti-behavior' ), number_format_i18n( $views / $total, 1 ) ), 'pageviews', true, _x( 'page views', 'site report comparison', 'opti-behavior' ) ),
			array( __( 'Time per visit', 'opti-behavior' ), self::secs( $time ), __( 'average', 'opti-behavior' ), 'avg_session_time', true, _x( 'time per visit', 'site report comparison', 'opti-behavior' ) ),
			/* translators: %s: average share of the page read, e.g. "47 %". */
			array( __( 'Bounce', 'opti-behavior' ), self::pct( isset( $st['bounce_rate'] ) ? $st['bounce_rate'] : 0 ), sprintf( __( 'page read %s', 'opti-behavior' ), self::pct( isset( $st['avg_scroll_depth'] ) ? $st['avg_scroll_depth'] : 0 ) ), 'bounce_rate', false, _x( 'bounce', 'site report comparison', 'opti-behavior' ) ),
		);
		$html    = '<div class="xr-tiles">';
		$missing = array();
		foreach ( $tiles as $t ) {
			$html  .= '<div class="xr-tile"><div class="xr-label">' . esc_html( $t[0] ) . '</div><b>' . esc_html( $t[1] ) . '</b><p class="xr-vs">' . esc_html( $t[2] ) . '</p>';
			$change = array_key_exists( $t[3], $ch ) && is_numeric( $ch[ $t[3] ] ) ? (float) $ch[ $t[3] ] : null;
			if ( null === $change ) {
				$missing[] = $t[5];
			} else {
				$html .= self::vs( $change, $t[4], self::change_unit( $t[3] ) );
			}
			$html .= '</div>';
		}
		$html .= '</div>';
		if ( $missing && '' !== $ctx['prev_from'] && ! empty( $ctx['not_comparable'] ) ) {
			$html .= '<p class="xr-note">' . esc_html( self::not_comparable_note( $ctx ) ) . '</p>';
		} elseif ( $missing && '' !== $ctx['prev_from'] ) {
			$html .= '<p class="xr-note">' . esc_html(
				/* translators: 1: dates of the period before, 2: list of figures. */
				sprintf( __( 'No comparison with %1$s for %2$s: too few visits in that period.', 'opti-behavior' ), self::range_text( $ctx['prev_from'], $ctx['prev_to'] ), wp_sprintf( '%l', $missing ) )
			) . '</p>';
		}

		return $html;
	}

	/**
	 * 3 Traffic (OPT-1680, design OPT-1666, no tiles any more): visits per day
	 * (one black line + grey area, readable in black and white), new /
	 * returning and devices on two lines, ONE source table with its kind,
	 * the 5 most viewed pages, one "What to do" line.
	 *
	 * @param array $data { ctx, args }.
	 * @return string
	 */
	public static function render_traffic( $data ) {
		$ctx   = $data['ctx'];
		$total = max( 1, $ctx['visits'] );
		$html  = '<div class="xr-section obr-traffic">' . self::sec_head( self::no_of( $data, 3 ), __( 'Traffic', 'opti-behavior' ), esc_html( self::day_long( $ctx['from'] ) . ' – ' . self::day_long( $ctx['to'] ) ) );
		$html .= self::curve_html( isset( $ctx['daily'] ) ? $ctx['daily'] : array() );

		// New / returning (the screen's widget) and devices (+ one stacked bar).
		$facts = '';
		$ret   = isset( $ctx['returning'] ) && is_array( $ctx['returning'] ) ? $ctx['returning'] : array();
		$new   = isset( $ret['new_visitors'] ) ? (int) $ret['new_visitors'] : 0;
		$back  = isset( $ret['returning_visitors'] ) ? (int) $ret['returning_visitors'] : 0;
		if ( $new + $back > 0 ) {
			$facts .= '<li><b>' . esc_html__( 'New / returning', 'opti-behavior' ) . '</b><span>' . sprintf(
				/* translators: 1: number of new visitors, 2: number of returning visitors, 3: share of returning visitors, e.g. "5 %". */
				esc_html__( '%1$s new visitors · %2$s returning (%3$s)', 'opti-behavior' ),
				'<strong>' . esc_html( number_format_i18n( $new ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $back ) ) . '</strong>',
				esc_html( number_format_i18n( self::share( $back, $new + $back ) ) . ' %' )
			) . '</span></li>';
		}
		$devices = self::rows( $ctx['devices'], 'name' );
		if ( $devices ) {
			$facts .= '<li><b>' . esc_html__( 'Devices', 'opti-behavior' ) . '</b>' . self::split_html( $devices, $total ) . '</li>';
		}
		if ( '' !== $facts ) {
			$html .= '<div class="obr-block"><ul class="obr-facts">' . $facts . '</ul></div>';
		}

		// One source table: the Referrers widget rows (sources grouped by name,
		// "Google" not google.fr, OPT-1605) + their kind; top 5 + the rest.
		$sources = self::source_rows( $ctx['sources'] );
		$top     = array_slice( $sources, 0, self::TOP_SOURCES );
		$rest    = $ctx['visits'] - array_sum( array_column( $top, 1 ) );
		if ( $rest > 0 && count( $sources ) > self::TOP_SOURCES ) {
			$top[] = array( __( 'Other sources', 'opti-behavior' ), $rest, '', true );
		}
		if ( $top ) {
			$html .= '<div class="obr-block obr-shares"><h3>' . esc_html__( 'Where visits come from', 'opti-behavior' ) . ' <span>' . esc_html( '· ' . __( 'top 5, with the kind of source', 'opti-behavior' ) ) . '</span></h3>';
			$html .= '<table class="xr-table"><thead><tr><th>' . esc_html__( 'Source', 'opti-behavior' ) . '</th><th>' . esc_html__( 'Kind', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Visits', 'opti-behavior' ) . '</th><th>' . esc_html__( 'Share', 'opti-behavior' ) . '</th></tr></thead><tbody>';
			foreach ( $top as $r ) {
				$name  = ! empty( $r[3] ) ? '<span class="obr-src-type">' . esc_html( $r[0] ) . '</span>' : esc_html( $r[0] );
				$html .= '<tr><td>' . $name . '</td><td><span class="obr-src-type">' . esc_html( self::kind_label( $r[2] ) ) . '</span></td><td class="xr-n">' . esc_html( number_format_i18n( $r[1] ) ) . '</td><td>' . self::bar( self::share( $r[1], $total ) ) . '</td></tr>';
			}
			$html .= '</tbody></table></div>';
		}

		// The 5 most viewed pages, in the order of the Traffic screen.
		if ( $ctx['pages'] ) {
			$html .= '<div class="obr-top-pages obr-block"><h3>' . esc_html__( 'The 5 most viewed pages', 'opti-behavior' ) . '</h3><table class="xr-table"><thead><tr><th>#</th><th>' . esc_html__( 'Page', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Visits', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Page views', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Time on page', 'opti-behavior' ) . '</th><th>' . esc_html__( 'Share of visits', 'opti-behavior' ) . '</th></tr></thead><tbody>';
			foreach ( $ctx['pages'] as $i => $p ) {
				$p      = is_array( $p ) ? $p : array();
				$url    = isset( $p['url'] ) ? (string) $p['url'] : '';
				$path   = Opti_Behavior_Report::path_of( $url );
				$title  = self::strip_site( isset( $p['title'] ) ? wp_specialchars_decode( (string) $p['title'], ENT_QUOTES ) : '' );
				$visits = isset( $p['sessions'] ) ? (int) $p['sessions'] : 0;
				// Archive pages (category, tag, search…) are marked: the annexes only
				// take pages and posts (OPT-1626 A6).
				$is_archive = '' !== $url && class_exists( 'Opti_Behavior_Heatmap_Page_Type_Prune' ) && 'keep' !== Opti_Behavior_Heatmap_Page_Type_Prune::classify_url( $url, false );
				$archive    = $is_archive ? ' <span class="xr-tag obr-archive-tag">' . esc_html__( 'Archive', 'opti-behavior' ) . '</span>' : '';
				$html  .= '<tr><td class="xr-rank">' . esc_html( number_format_i18n( $i + 1 ) ) . '</td><td><b>' . esc_html( '' !== trim( $title ) ? self::no_address( $title ) : $path ) . '</b>' . $archive . '<p>' . esc_html( $path ) . '</p></td>';
				$html  .= '<td class="xr-n">' . esc_html( number_format_i18n( $visits ) ) . '</td><td class="xr-n">' . esc_html( number_format_i18n( isset( $p['views'] ) ? (int) $p['views'] : 0 ) ) . '</td>';
				$html  .= '<td class="xr-n">' . esc_html( self::secs( isset( $p['avg_time'] ) ? (float) $p['avg_time'] : 0 ) ) . '</td><td>' . self::bar( self::share( $visits, $total ) ) . '</td></tr>';
			}
			$html .= '</tbody></table></div>';
		}

		$todo = self::traffic_todo( $sources, $ctx['pages'], $total );
		if ( '' !== $todo ) {
			$html .= self::todo( $todo );
		}

		return $html . '</div>';
	}

	/**
	 * Clicks and errors (OPT-1681, design OPT-1666 block 4): the 3 most
	 * clicked elements of the site, one "What to do" line, then ONE grey
	 * line for the Pro part (filled by a later lot).
	 *
	 * @param array $data { ctx, args, no }.
	 * @return string
	 */
	public static function render_clicks( $data ) {
		$ctx  = $data['ctx'];
		$rows = isset( $ctx['clicks'] ) && is_array( $ctx['clicks'] ) ? $ctx['clicks'] : array();
		$more = isset( $ctx['clicks_pro'] ) ? (string) $ctx['clicks_pro'] : '';
		if ( ! $rows && '' === $more ) {
			return '';
		}
		$html = '<div class="xr-section obr-new obr-clicks">' . self::sec_head( self::no_of( $data, 3 ), __( 'Clicks and errors', 'opti-behavior' ), esc_html( self::day_long( $ctx['from'] ) . ' – ' . self::day_long( $ctx['to'] ) ) );
		if ( $rows ) {
			$html .= '<div class="obr-block"><h3>' . esc_html__( 'The 3 most clicked elements', 'opti-behavior' ) . '</h3><table class="xr-table"><thead><tr><th>#</th><th>' . esc_html__( 'Element', 'opti-behavior' ) . '</th><th>' . esc_html__( 'Page', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Clicks', 'opti-behavior' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $i => $r ) {
				$html      .= '<tr><td><span class="xr-rank">' . esc_html( number_format_i18n( $i + 1 ) ) . '</span></td>';
				$kind_label = isset( $r['named'] ) && ! $r['named']
					? ( 'image' === $r['kind'] ? _x( 'not a link', 'kind of clicked element', 'opti-behavior' ) : '' )
					: self::element_kind_label( $r['kind'] );
				$html      .= '<td><b>' . esc_html( self::element_print_name( $r ) ) . '</b>' . ( '' !== $kind_label ? ' <span class="obr-src-type">' . esc_html( $kind_label ) . '</span>' : '' ) . '</td>';
				$html      .= '<td><b>' . esc_html( $r['page'] ) . '</b><span class="obr-path">' . esc_html( $r['path'] ) . '</span></td>';
				$html      .= '<td class="xr-n">' . esc_html( number_format_i18n( $r['clicks'] ) ) . '</td></tr>';
			}
			$html .= '</tbody></table>' . self::todo( self::clicks_todo( $rows[0] ) ) . '</div>';
		}
		// Pro blocks (OPT-1685): escaped by Pro.
		$html .= $more;
		if ( ! self::pro_on() ) {
			$html .= self::pro_line( __( 'rage and dead clicks, JavaScript errors, broken links and slow pages.', 'opti-behavior' ) );
		}

		return $html . '</div>';
	}

	/**
	 * Busiest time (OPT-1681, design OPT-1666 block 7): visits per day of the
	 * week x hour of the day, site time, grey grid + peak line + "What to do".
	 *
	 * @param array $data { ctx, args, no }.
	 * @return string
	 */
	public static function render_busiest( $data ) {
		$ctx  = $data['ctx'];
		$grid = isset( $ctx['hours'] ) && is_array( $ctx['hours'] ) ? $ctx['hours'] : array();
		$peak = self::hours_peak( $grid );
		if ( ! $peak ) {
			return '';
		}
		$html  = '<div class="xr-section obr-new obr-busiest">' . self::sec_head( self::no_of( $data, 5 ), __( 'Busiest time', 'opti-behavior' ), esc_html( self::tz_label() ) );
		$html .= '<figure class="xr-figure obr-hours obr-block">' . self::hours_svg( $grid, $peak ) . '</figure>';
		$html .= '<ul class="obr-facts"><li><b>' . esc_html__( 'Peak', 'opti-behavior' ) . '</b><span>' . sprintf(
			/* translators: 1: day and hour, e.g. "Tuesday at 10:00", 2: visits started in that hour over the period, 3: quietest day of the week. */
			esc_html__( '%1$s · %2$s visits in that hour over the period · quietest day: %3$s', 'opti-behavior' ),
			'<strong>' . esc_html( self::day_hour( $peak['day'], $peak['hour'] ) ) . '</strong>',
			esc_html( number_format_i18n( $peak['visits'] ) ),
			esc_html( self::weekday( $peak['quiet'] ) )
		) . '</span></li></ul>';
		/* translators: 1: day of the week, 2: hour, e.g. "10:00". */
		$html .= self::todo( sprintf( __( 'Publish news and send your e-mails on %1$s, before %2$s: it is when most visits start.', 'opti-behavior' ), self::weekday( $peak['day'] ), self::hour_label( $peak['hour'] ) ) );

		return $html . '</div>';
	}

	/**
	 * Journeys and funnels (OPT-1682, design OPT-1666 block 6): the funnels
	 * confirmed by the user, conversion + the step where most visitors leave,
	 * the same counts as the Funnels screen. A funnel that is an issue of
	 * section 2 points to it in place of repeating it. Journeys = Pro line.
	 *
	 * @param array $data { ctx, args, no }.
	 * @return string
	 */
	public static function render_funnels( $data ) {
		$ctx   = $data['ctx'];
		$rows  = isset( $ctx['funnels'] ) && is_array( $ctx['funnels'] ) ? $ctx['funnels'] : array();
		$paths = isset( $ctx['journeys_pro'] ) ? (string) $ctx['journeys_pro'] : '';
		if ( ! $rows && '' === $paths ) {
			return '';
		}
		// Pro: the 3 most frequent journeys first (OPT-1684), escaped by Pro.
		$html = '<div class="xr-section obr-new obr-journeys">' . self::sec_head( self::no_of( $data, 5 ), __( 'Journeys and funnels', 'opti-behavior' ), esc_html( self::day_long( $ctx['from'] ) . ' – ' . self::day_long( $ctx['to'] ) ) ) . $paths;
		if ( ! $rows ) {
			return $html . '</div>';
		}
		$html .= '<div class="obr-block"><h3>' . esc_html__( 'Funnels', 'opti-behavior' ) . ' <span>· ' . esc_html__( 'conversion and the step where most visitors leave', 'opti-behavior' ) . '</span></h3>';
		$html .= '<table class="xr-table"><thead><tr><th>' . esc_html__( 'Funnel', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Conversion', 'opti-behavior' ) . '</th><th>' . esc_html__( 'Step where most visitors leave', 'opti-behavior' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			/* translators: %s: number of steps of a funnel. */
			$steps = sprintf( _n( '%s step', '%s steps', $r['steps'], 'opti-behavior' ), number_format_i18n( $r['steps'] ) );
			$worst = $r['worst'] ? esc_html( $r['worst']['from'] . ' → ' . $r['worst']['to'] ) : '—';
			if ( $r['worst'] && $r['issue'] > 0 ) {
				$worst .= ' ' . self::ref( $r['issue'] );
			}
			$html .= '<tr><td><b>' . esc_html( $r['name'] ) . '</b><p>' . esc_html( $steps ) . '</p></td>';
			$html .= '<td class="xr-n">' . esc_html( self::pct_text( $r['rate'] ) ) . '</td><td>' . $worst . '</td></tr>';
		}
		$html .= '</tbody></table>';
		$todo  = self::funnels_todo( $rows );
		$html .= ( '' !== $todo ? self::todo( $todo ) : '' ) . '</div>';
		if ( ! self::pro_on() ) {
			$html .= self::pro_line( __( 'the 3 most frequent journeys of your visitors.', 'opti-behavior' ) );
		}

		return $html . '</div>';
	}

	/**
	 * A/B test (OPT-1682, design OPT-1666 block 8): printed only when a test
	 * had visits in the period. Result = the A/B Testing screen's own
	 * (frequentist engine on the whole test), winner in green.
	 *
	 * @param array $data { ctx, args, no }.
	 * @return string
	 */
	public static function render_ab( $data ) {
		$ctx   = $data['ctx'];
		$tests = isset( $ctx['ab'] ) && is_array( $ctx['ab'] ) ? $ctx['ab'] : array();
		if ( ! $tests ) {
			return '';
		}
		$html = '<div class="xr-section obr-new obr-ab">' . self::sec_head( self::no_of( $data, 7 ), __( 'A/B test', 'opti-behavior' ), esc_html( self::day_long( $ctx['from'] ) . ' – ' . self::day_long( $ctx['to'] ) ) );
		foreach ( $tests as $t ) {
			/**
			 * Pro column of an A/B test of the site report (OPT-1685): Bayesian
			 * probability to beat the original. Cells are escaped HTML.
			 *
			 * @param null|array $pro  { head, cells: [ variant index => html ], todo }.
			 * @param array      $t    ab_view() of the test.
			 * @param array      $args Report arguments.
			 */
			$pro   = apply_filters( 'opti_behavior_report_site_ab', null, $t, isset( $data['args'] ) ? $data['args'] : array() );
			$pro   = is_array( $pro ) && isset( $pro['head'], $pro['cells'] ) && is_array( $pro['cells'] ) ? $pro : null;
			$html .= '<div class="xr-keep obr-block"><h3>“' . esc_html( $t['name'] ) . '” <span class="xr-tag xr-tag--' . esc_attr( $t['tag'] ) . '">' . esc_html( $t['status_label'] ) . '</span></h3>';
			$html .= '<table class="xr-table"><thead><tr><th>' . esc_html__( 'Variant', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Visitors', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Conversions', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Rate', 'opti-behavior' ) . '</th><th>' . esc_html__( 'Result', 'opti-behavior' ) . '</th>' . ( $pro ? '<th>' . esc_html( (string) $pro['head'] ) . '</th>' : '' ) . '</tr></thead><tbody>';
			foreach ( $t['variants'] as $i => $v ) {
				$best  = $v['win'];
				$name  = esc_html( $v['label'] );
				$rate  = esc_html( number_format_i18n( $v['rate'], 1 ) . ' %' );
				$html .= '<tr><td>' . ( $best ? '<b>' . $name . '</b>' : $name ) . '</td>';
				$html .= '<td class="xr-n">' . esc_html( number_format_i18n( $v['visitors'] ) ) . '</td><td class="xr-n">' . esc_html( number_format_i18n( $v['conversions'] ) ) . '</td>';
				$html .= '<td class="xr-n">' . ( $best ? '<b>' . $rate . '</b>' : $rate ) . '</td><td>' . self::ab_result_html( $v, in_array( $t['status'], array( 'running', 'paused' ), true ) ) . '</td>';
				$html .= ( $pro ? '<td>' . ( isset( $pro['cells'][ $i ] ) ? (string) $pro['cells'][ $i ] : '—' ) . '</td>' : '' ) . '</tr>';
			}
			$todo  = $pro && ! empty( $pro['todo'] ) ? (string) $pro['todo'] : self::ab_todo( $t );
			$html .= '</tbody></table>' . self::todo( $todo ) . '</div>';
		}
		if ( ! self::pro_on() ) {
			$html .= self::pro_line( __( 'the chance that each variant is the best (Bayesian result).', 'opti-behavior' ) );
		}

		return $html . '</div>';
	}

	/**
	 * Shop (OPT-1683, design OPT-1666 block 3): only with WooCommerce and carts
	 * or orders in the period. Free counters of the Shop screen (product views,
	 * adds to cart, checkouts, paid orders), losses in visits, never an amount,
	 * an order number, a customer or a coupon code. Pro adds a money column.
	 *
	 * @param array $data { ctx, args, no }.
	 * @return string
	 */
	public static function render_shop( $data ) {
		$ctx  = $data['ctx'];
		$shop = isset( $ctx['shop'] ) && is_array( $ctx['shop'] ) ? $ctx['shop'] : null;
		if ( ! $shop ) {
			return '';
		}
		/**
		 * Pro money of the Shop block of the site report (OPT-1685).
		 *
		 * @param null|array $pro  { tile: { label, value, text }, head, cells: [ product id => escaped html ] }.
		 * @param array      $shop shop_view() + names.
		 * @param array      $args Report arguments.
		 */
		$pro   = apply_filters( 'opti_behavior_report_site_shop', null, $shop, isset( $data['args'] ) ? $data['args'] : array() );
		$pro   = is_array( $pro ) && isset( $pro['tile']['label'], $pro['tile']['value'], $pro['head'], $pro['cells'] ) && is_array( $pro['cells'] ) ? $pro : null;
		$tiles = array(
			/* translators: %s: number of product pages. */
			array( __( 'Product views', 'opti-behavior' ), number_format_i18n( $shop['views'] ), sprintf( _n( 'on %s product page', 'on %s product pages', $shop['pages'], 'opti-behavior' ), number_format_i18n( $shop['pages'] ) ) ),
			/* translators: %s: share of product views, e.g. "18 %". */
			array( __( 'Add to cart', 'opti-behavior' ), number_format_i18n( $shop['atc'] ), $shop['views'] > 0 ? sprintf( __( '%s of views', 'opti-behavior' ), self::pct_text( 100 * $shop['atc'] / $shop['views'] ) ) : '' ),
			/* translators: %s: number of checkouts started. */
			array( __( 'Orders', 'opti-behavior' ), number_format_i18n( $shop['orders'] ), sprintf( _n( 'from %s checkout started', 'from %s checkouts started', $shop['checkout'], 'opti-behavior' ), number_format_i18n( $shop['checkout'] ) ) ),
			array( __( 'Lost visits', 'opti-behavior' ), number_format_i18n( $shop['lost'] ), __( 'added, then no order', 'opti-behavior' ) ),
		);
		if ( $pro ) {
			$tiles[] = array( (string) $pro['tile']['label'], (string) $pro['tile']['value'], isset( $pro['tile']['text'] ) ? (string) $pro['tile']['text'] : '' );
		}
		$html = '<div class="xr-section obr-new obr-shop">' . self::sec_head( self::no_of( $data, 3 ), __( 'Shop', 'opti-behavior' ), esc_html( self::day_long( $ctx['from'] ) . ' – ' . self::day_long( $ctx['to'] ) ) );
		$html .= '<div class="xr-keep"><div class="xr-tiles' . ( count( $tiles ) > 4 ? ' obr-tiles-5' : '' ) . '">';
		foreach ( $tiles as $t ) {
			$html .= '<div class="xr-tile"><div class="xr-label">' . esc_html( $t[0] ) . '</div><b>' . esc_html( $t[1] ) . '</b>' . ( '' !== $t[2] ? '<p class="xr-vs">' . esc_html( $t[2] ) . '</p>' : '' ) . '</div>';
		}
		$html .= '</div></div>';
		if ( $shop['rows'] ) {
			$html .= '<div class="obr-block"><h3>' . esc_html__( 'The 3 product pages that lose the most', 'opti-behavior' ) . ' <span>· ' . esc_html__( 'added to cart, then no order', 'opti-behavior' ) . '</span></h3>';
			$html .= '<table class="xr-table"><thead><tr><th>' . esc_html__( 'Product page', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Views', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Add to cart', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Orders', 'opti-behavior' ) . '</th><th class="xr-n">' . esc_html__( 'Lost visits', 'opti-behavior' ) . '</th>' . ( $pro ? '<th class="xr-n">' . esc_html( (string) $pro['head'] ) . '</th>' : '' ) . '</tr></thead><tbody>';
			foreach ( $shop['rows'] as $r ) {
				$html .= '<tr><td><b>' . esc_html( $r['name'] ) . '</b>' . ( '' !== $r['path'] ? '<p>' . esc_html( $r['path'] ) . '</p>' : '' ) . '</td>';
				$html .= '<td class="xr-n">' . esc_html( number_format_i18n( $r['views'] ) ) . '</td><td class="xr-n">' . esc_html( number_format_i18n( $r['atc'] ) ) . '</td>';
				$html .= '<td class="xr-n">' . esc_html( number_format_i18n( $r['orders'] ) ) . '</td><td class="xr-n">' . esc_html( number_format_i18n( $r['lost'] ) ) . '</td>';
				$html .= ( $pro ? '<td class="xr-n">' . ( isset( $pro['cells'][ $r['product_id'] ] ) ? (string) $pro['cells'][ $r['product_id'] ] : '—' ) . '</td>' : '' ) . '</tr>';
			}
			$html .= '</tbody></table></div>';
		}
		$todo = self::shop_todo( $shop );
		if ( '' !== $todo ) {
			$html .= self::todo( $todo );
		}
		if ( ! self::pro_on() ) {
			$html .= self::pro_line( __( 'the sales lost per product page, in your shop currency.', 'opti-behavior' ) );
		}

		return $html . '</div>';
	}

	/**
	 * Forms with problems (OPT-1684): a Pro block, printed by Free so the
	 * numbers of every section stay in one place. Body = escaped Pro HTML.
	 *
	 * @param array $data { ctx, args, no }.
	 * @return string
	 */
	public static function render_forms( $data ) {
		$ctx  = $data['ctx'];
		$body = isset( $ctx['forms_pro'] ) ? (string) $ctx['forms_pro'] : '';
		if ( '' === $body ) {
			return '';
		}

		return '<div class="xr-section obr-new obr-forms">' . self::sec_head( self::no_of( $data, 4 ), __( 'Forms with problems', 'opti-behavior' ), esc_html__( 'the 3 most abandoned', 'opti-behavior' ) ) . $body . '</div>';
	}

	/**
	 * Free: the one grey line in place of the Forms section (no number, no
	 * figure, no link). Nothing when Pro is active.
	 *
	 * @param array $data { ctx, args, no }.
	 * @return string
	 */
	public static function render_forms_line( $data ) {
		if ( self::pro_on() ) {
			return '';
		}

		return '<p class="obr-pro-line obr-pro-line--sec"><span>PRO</span><span><b>' . esc_html__( 'Forms with problems', 'opti-behavior' ) . '</b> · ' . esc_html(
			/* translators: %s: what the Pro plugin adds to this block. */
			sprintf( __( 'Available with Pro: %s', 'opti-behavior' ), __( 'the 3 most abandoned forms and the field where visitors leave.', 'opti-behavior' ) )
		) . '</span></p>';
	}

	/**
	 * Shop counters of the period (OPT-1683), WooCommerce only. One grouped
	 * read of the Free daily counters (Shop_Report::query_counts()), product
	 * names and paths added for the printed rows.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array|null shop_view() + rows[] { name, path }, null = no block.
	 */
	public static function shop_rows( $from, $to ) {
		if ( ! self::is_day( $from ) || ! self::is_day( $to ) || ! class_exists( 'Opti_Behavior_Shop_Screen' ) || ! class_exists( 'Opti_Behavior_Shop_Report' ) || ! Opti_Behavior_Shop_Screen::available() ) {
			return null;
		}
		$shop = self::shop_view( Opti_Behavior_Shop_Report::query_counts( $from, $to, array( 'product_view', 'add_to_cart', 'checkout_start', 'order_paid' ) ) );
		if ( ! $shop ) {
			return null;
		}
		foreach ( $shop['rows'] as $i => $r ) {
			$title                      = wp_strip_all_tags( wp_specialchars_decode( (string) get_the_title( $r['product_id'] ), ENT_QUOTES ) );
			$link                       = get_permalink( $r['product_id'] );
			$shop['rows'][ $i ]['path'] = $link ? Opti_Behavior_Report::path_of( $link ) : '';
			/* translators: %d: product id. */
			$shop['rows'][ $i ]['name'] = '' !== trim( $title ) ? self::no_address( $title ) : sprintf( __( 'Product #%d', 'opti-behavior' ), $r['product_id'] );
		}
		$shop['from'] = $from;
		$shop['to']   = $to;

		return $shop;
	}

	/**
	 * Shop figures from the counter rows (pure). Totals = the Shop screen's
	 * build_report(); a product row loses the adds to cart that did not end in
	 * a paid order line ("added, then no order").
	 *
	 * @param array[] $counts Rows { product_id, event_type, count, qty, amount }.
	 * @return array|null { views, pages, atc, checkout, orders, amount, lost, rows[] { product_id, views, atc, orders, lost } }, null = no cart, no order.
	 */
	public static function shop_view( $counts ) {
		$report   = Opti_Behavior_Shop_Screen::build_report( $counts );
		$t        = $report['totals'];
		$checkout = 0;
		foreach ( (array) $counts as $row ) {
			if ( isset( $row['event_type'] ) && 'checkout_start' === $row['event_type'] ) {
				$checkout += max( 0, (int) $row['count'] );
			}
		}
		if ( (int) $t['orders'] < 1 && (int) $t['atc'] < 1 ) {
			return null;
		}
		$rows = array();
		foreach ( $report['rows'] as $r ) {
			if ( (int) $r['atc'] < 1 ) {
				continue;
			}
			$rows[] = array(
				'product_id' => (int) $r['product_id'],
				'views'      => (int) $r['views'],
				'atc'        => (int) $r['atc'],
				'orders'     => (int) $r['orders'],
				'lost'       => max( 0, (int) $r['atc'] - (int) $r['orders'] ),
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				foreach ( array( 'lost', 'atc', 'views' ) as $k ) {
					if ( $a[ $k ] !== $b[ $k ] ) {
						return $b[ $k ] - $a[ $k ];
					}
				}
				return $a['product_id'] - $b['product_id'];
			}
		);

		return array(
			'views'    => (int) $t['views'],
			'pages'    => (int) $report['count'],
			'atc'      => (int) $t['atc'],
			'checkout' => $checkout,
			'orders'   => (int) $t['orders'],
			'amount'   => (float) $t['amount'],
			// Sum of the product pages (a paid order can come from a cart not
			// counted in the period: whole orders minus adds would hide losses).
			'lost'     => array_sum( array_column( $rows, 'lost' ) ),
			'rows'     => array_slice( $rows, 0, self::TOP_SHOP ),
		);
	}

	/**
	 * "What to do" of the Shop block (pure).
	 *
	 * @param array $shop shop_rows().
	 * @return string
	 */
	public static function shop_todo( $shop ) {
		$top = isset( $shop['rows'][0] ) ? $shop['rows'][0] : null;
		if ( $top && $top['lost'] > 0 ) {
			return sprintf(
				/* translators: 1: number of visits, 2: product name. */
				_n( '%1$s visit added “%2$s” to the cart without ordering: go through the cart and the checkout yourself, on a phone, and check the delivery costs and the payment step.', '%1$s visits added “%2$s” to the cart without ordering: go through the cart and the checkout yourself, on a phone, and check the delivery costs and the payment step.', $top['lost'], 'opti-behavior' ),
				number_format_i18n( $top['lost'] ),
				isset( $top['name'] ) ? $top['name'] : ''
			);
		}
		if ( $shop['atc'] > 0 && $shop['lost'] < 1 ) {
			return __( 'Every cart of the period became an order: keep the cart and the checkout as they are.', 'opti-behavior' );
		}

		return '';
	}

	/**
	 * Pro is active (active, trial or grace): the grey Pro lines are left out.
	 *
	 * @return bool
	 */
	private static function pro_on() {
		return function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();
	}

	/**
	 * Escaped HTML of a Pro block of the full version, '' when Pro does not
	 * answer (Free alone, short version, gate closed) or fails.
	 *
	 * Filters: `opti_behavior_report_site_forms`, `opti_behavior_report_site_clicks`,
	 * `opti_behavior_report_site_journeys` ( '', $ctx, $args ).
	 *
	 * @param string $filter Filter name.
	 * @param array  $ctx    Context.
	 * @param array  $args   Report arguments.
	 * @return string
	 */
	private static function pro_html( $filter, $ctx, $args ) {
		try {
			$html = apply_filters( $filter, '', $ctx, $args );
		} catch ( Throwable $e ) {
			$html = '';
		}

		return is_string( $html ) ? trim( $html ) : '';
	}

	/**
	 * Funnels of the period (OPT-1682): active funnels confirmed by the user
	 * (suggested template funnels left out, OPT-1619), counted like the
	 * Funnels screen: a visit counts once, step n = visits whose furthest
	 * step is n or more, conversion = visits that completed, the same spam /
	 * real-visit rule (Funnel_Aggregator). Funnels without entries left out.
	 *
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 * @param bool   $spam   Exclude spam sessions.
	 * @param array  $issues Printed issues of section 2.
	 * @return array Rows of pick_funnel_rows().
	 */
	public static function funnel_rows( $from, $to, $spam, $issues = array() ) {
		global $wpdb;
		if ( ! self::is_day( $from ) || ! self::is_day( $to ) ) {
			return array();
		}
		$funnels_table  = $wpdb->prefix . 'opti_behavior_funnels';
		$tracking_table = $wpdb->prefix . 'opti_behavior_funnel_tracking';
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, clauses built by the plugin; values prepared.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tracking_table ) ) ) !== $tracking_table ) {
			return array();
		}
		$confirmed = class_exists( 'Opti_Behavior_Funnel_Database' ) && method_exists( 'Opti_Behavior_Funnel_Database', 'confirmed_sql' ) ? Opti_Behavior_Funnel_Database::confirmed_sql( 'f' ) : '';
		$funnels   = $wpdb->get_results( "SELECT f.id, f.name, f.steps FROM {$funnels_table} f WHERE f.status = 'active'{$confirmed} ORDER BY f.id ASC LIMIT 50", ARRAY_A );
		$scope     = class_exists( 'Opti_Behavior_Smart_Insights_Funnel_Aggregator' )
			? ( new Opti_Behavior_Smart_Insights_Funnel_Aggregator() )->get_tracking_spam_exclusion_clause( 't', $sessions_table, (bool) $spam )
			: '';
		$counts    = array();
		foreach ( is_array( $funnels ) ? $funnels : array() as $f ) {
			// One row per visit (its furthest step, completed or not), then one row per furthest step.
			$sql = $wpdb->prepare(
				"SELECT t.session_id, MAX(t.max_step_reached) AS m, MAX(t.completed) AS c
				FROM {$tracking_table} t
				WHERE t.funnel_id = %d AND t.entry_time >= %s AND t.entry_time < %s",
				(int) $f['id'],
				$from . ' 00:00:00',
				self::shift_day( $to, 1 ) . ' 00:00:00'
			);
			$rows = $wpdb->get_results( "SELECT x.m, COUNT(*) AS n, SUM(x.c) AS done FROM ( {$sql}{$scope} GROUP BY t.session_id ) x GROUP BY x.m", ARRAY_A );
			$counts[ (int) $f['id'] ] = is_array( $rows ) ? $rows : array();
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return self::pick_funnel_rows( is_array( $funnels ) ? $funnels : array(), $counts, $issues );
	}

	/**
	 * Funnel rows printed (pure): entries, conversion, the step transition
	 * with the most visits lost (Funnel_Page::build_step_row(), the screen's
	 * "Abandoned"), the issue number when section 2 prints this funnel. Most
	 * entries first, TOP_FUNNELS rows.
	 *
	 * @param array $funnels { id, name, steps (JSON) }.
	 * @param array $counts  funnel id => rows { m (furthest step), n (visits), done (completed visits) }.
	 * @param array $issues  Printed issues of section 2.
	 * @return array { id, name, steps, entries, completed, rate, worst { from, to, lost, share } | null, issue }.
	 */
	public static function pick_funnel_rows( $funnels, $counts, $issues = array() ) {
		$ranks = array();
		foreach ( array_values( is_array( $issues ) ? $issues : array() ) as $i => $it ) {
			if ( is_array( $it ) && isset( $it['entity_type'] ) && 'funnel' === $it['entity_type'] && isset( $it['entity_id'] ) && ! isset( $ranks[ (int) $it['entity_id'] ] ) ) {
				$ranks[ (int) $it['entity_id'] ] = $i + 1;
			}
		}
		$out = array();
		foreach ( $funnels as $f ) {
			$f     = (array) $f;
			$id    = isset( $f['id'] ) ? (int) $f['id'] : 0;
			$names = self::funnel_step_names( isset( $f['steps'] ) ? $f['steps'] : '' );
			$by    = array();
			$done  = 0;
			foreach ( isset( $counts[ $id ] ) ? (array) $counts[ $id ] : array() as $row ) {
				$row                 = (array) $row;
				$m                   = isset( $row['m'] ) ? max( 0, (int) $row['m'] ) : 0;
				$by[ $m ]            = ( isset( $by[ $m ] ) ? $by[ $m ] : 0 ) + ( isset( $row['n'] ) ? (int) $row['n'] : 0 );
				$done               += isset( $row['done'] ) ? (int) $row['done'] : 0;
			}
			$entries = array_sum( $by );
			if ( $id <= 0 || $entries <= 0 || ! $names ) {
				continue;
			}
			$worst    = null;
			$previous = $entries;
			foreach ( $names as $i => $name ) {
				$n       = $i + 1;
				$reached = 0;
				foreach ( $by as $m => $c ) {
					$reached += $m >= $n ? $c : 0;
				}
				$step = class_exists( 'Opti_Behavior_Funnel_Page' )
					? Opti_Behavior_Funnel_Page::build_step_row( $n, $name, $reached, $previous, $entries )
					: array(
						'completed'        => min( $reached, $previous ),
						'abandoned'        => $previous - min( $reached, $previous ),
						'abandonment_rate' => $previous > 0 ? round( ( $previous - min( $reached, $previous ) ) / $previous * 100, 1 ) : 0,
					);
				if ( $n > 1 && $step['abandoned'] > 0 && ( null === $worst || $step['abandoned'] > $worst['lost'] ) ) {
					$worst = array(
						'from'  => $names[ $i - 1 ],
						'to'    => $name,
						'lost'  => (int) $step['abandoned'],
						'share' => (float) $step['abandonment_rate'],
					);
				}
				$previous = (int) $step['completed'];
			}
			$done  = min( $done, $entries );
			$out[] = array(
				'id'        => $id,
				'name'      => self::no_address( trim( wp_specialchars_decode( isset( $f['name'] ) ? (string) $f['name'] : '', ENT_QUOTES ) ) ),
				'steps'     => count( $names ),
				'entries'   => $entries,
				'completed' => $done,
				'rate'      => round( $done / $entries * 100, 1 ),
				'worst'     => $worst,
				'issue'     => isset( $ranks[ $id ] ) ? $ranks[ $id ] : 0,
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['entries'] <=> $a['entries'] ?: $a['id'] <=> $b['id'];
			}
		);

		return array_slice( $out, 0, self::TOP_FUNNELS );
	}

	/**
	 * Step names of a funnel (pure), the steps the Funnels screen counts:
	 * a match type, and a pattern unless the type is any / pageview.
	 *
	 * @param string|array $steps Stored steps (JSON).
	 * @return string[]
	 */
	public static function funnel_step_names( $steps ) {
		$steps = is_string( $steps ) ? json_decode( $steps, true ) : $steps;
		$out   = array();
		foreach ( is_array( $steps ) ? $steps : array() as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$type    = isset( $step['match_type'] ) && is_scalar( $step['match_type'] ) ? (string) $step['match_type'] : '';
			$pattern = isset( $step['url_pattern'] ) && is_scalar( $step['url_pattern'] ) ? (string) $step['url_pattern'] : '';
			if ( '' === $type || ( '' === $pattern && ! in_array( $type, array( 'any', 'pageview' ), true ) ) ) {
				continue;
			}
			$name = isset( $step['name'] ) && is_scalar( $step['name'] ) ? trim( wp_strip_all_tags( (string) $step['name'] ) ) : '';
			// "2" (page 2 of an archive) = "<archive> (page 2)" (OPT-1627).
			if ( '' !== $name && ctype_digit( $name ) && class_exists( 'Opti_Behavior_Smart_Insights_Funnel_Aggregator' ) ) {
				$name = Opti_Behavior_Smart_Insights_Funnel_Aggregator::readable_step_label( $name, $pattern );
			}
			/* translators: %s: step number. */
			$out[] = '' !== $name ? self::no_address( $name ) : sprintf( __( 'Step %s', 'opti-behavior' ), number_format_i18n( count( $out ) + 1 ) );
		}

		return $out;
	}

	/**
	 * "What to do" of the funnel block (pure): the step with the most visits
	 * lost of all the funnels printed.
	 *
	 * @param array $rows pick_funnel_rows().
	 * @return string
	 */
	public static function funnels_todo( $rows ) {
		$top = null;
		foreach ( $rows as $r ) {
			if ( $r['worst'] && ( null === $top || $r['worst']['lost'] > $top['worst']['lost'] ) ) {
				$top = $r;
			}
		}
		if ( null === $top ) {
			return '';
		}
		$step = $top['worst']['from'] . ' → ' . $top['worst']['to'];
		if ( $top['issue'] > 0 ) {
			/* translators: 1: step of a funnel, e.g. "Cart → Checkout", 2: issue number. */
			return sprintf( __( 'Work on the step “%1$s” first: it is issue #%2$s of this report.', 'opti-behavior' ), $step, number_format_i18n( $top['issue'] ) );
		}

		/* translators: 1: step of a funnel, e.g. "Cart → Checkout", 2: funnel name, 3: share of visits, e.g. "62 %", 4: name of the step before. */
		return sprintf( __( 'Work on the step “%1$s” of “%2$s” first: %3$s of the visits that reached “%4$s” leave there.', 'opti-behavior' ), $step, $top['name'], self::pct_text( $top['worst']['share'] ), $top['worst']['from'] );
	}

	/**
	 * A/B tests with visits in the period (OPT-1682): tests with impressions
	 * (first visit of a visitor to the test) between the two days, most
	 * visitors first, TOP_AB_TESTS. Each result = the A/B Testing screen's:
	 * get_test_stats() + the frequentist engine at the test's confidence.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @param bool   $spam Exclude spam sessions.
	 * @return array Rows of ab_view().
	 */
	public static function ab_rows( $from, $to, $spam ) {
		global $wpdb;
		if ( ! self::is_day( $from ) || ! self::is_day( $to ) || ! class_exists( 'Opti_Behavior_AB_Test_Database' ) || ! class_exists( 'Opti_Behavior_AB_Test_Engine' ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'optibehavior_ab_impressions';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, spam clause built by the plugin; values prepared.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array();
		}
		$sql  = $wpdb->prepare( "SELECT i.test_id, COUNT(DISTINCT i.visitor_id) AS v FROM {$table} i WHERE i.created_at BETWEEN %s AND %s", $from . ' 00:00:00', $to . ' 23:59:59' );
		$ids  = $wpdb->get_results( $sql . Opti_Behavior_AB_Test_Database::get_ab_spam_session_filter_sql( 'i', (bool) $spam ) . ' GROUP BY i.test_id ORDER BY v DESC, i.test_id ASC LIMIT ' . (int) self::TOP_AB_TESTS, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $row ) {
			$test = Opti_Behavior_AB_Test_Database::get_test( (int) $row['test_id'] );
			if ( ! is_object( $test ) || ( isset( $test->status ) && 'deleted' === $test->status ) ) {
				continue;
			}
			$confidence = isset( $test->confidence_level ) ? (float) $test->confidence_level : null;
			$stats      = Opti_Behavior_AB_Test_Database::get_test_stats( (int) $row['test_id'], null, (bool) $spam );
			$view       = self::ab_view( $test, Opti_Behavior_AB_Test_Engine::calculate_results( is_array( $stats ) ? $stats : array(), $confidence > 0 ? $confidence : null ) );
			if ( $view ) {
				$out[] = $view;
			}
		}

		return $out;
	}

	/**
	 * One printed A/B test (pure).
	 *
	 * @param object $test    Test row { name, status }.
	 * @param array  $results Opti_Behavior_AB_Test_Engine::calculate_results().
	 * @return array|null { name, status, status_label, tag, verdict (winner / loser / none), best, variants[] { label, control, visitors, conversions, rate, improvement, p, significant, win } }.
	 */
	public static function ab_view( $test, $results ) {
		if ( ! is_array( $results ) || count( $results ) < 2 ) {
			return null;
		}
		$status  = isset( $test->status ) ? sanitize_key( (string) $test->status ) : '';
		$labels  = array(
			'running'   => array( _x( 'Running', 'A/B test status', 'opti-behavior' ), 'info' ),
			'paused'    => array( _x( 'Paused', 'A/B test status', 'opti-behavior' ), 'warn' ),
			'completed' => array( _x( 'Finished', 'A/B test status', 'opti-behavior' ), 'ok' ),
			'applied'   => array( _x( 'Winner applied', 'A/B test status', 'opti-behavior' ), 'ok' ),
		);
		$label   = isset( $labels[ $status ] ) ? $labels[ $status ] : array( _x( 'Stopped', 'A/B test status', 'opti-behavior' ), 'info' );
		$rows    = array();
		$verdict = 'none';
		$best    = null;
		foreach ( array_values( $results ) as $i => $r ) {
			$control     = ! empty( $r->is_control );
			$rate        = (float) $r->conversion_rate * 100;
			$significant = ! $control && ! empty( $r->is_significant );
			$up          = ! $control && (float) $r->improvement > 0;
			$name        = trim( wp_strip_all_tags( isset( $r->variant_name ) ? (string) $r->variant_name : '' ) );
			$letter      = $i < 26 ? chr( 65 + $i ) : (string) ( $i + 1 );
			$rows[]      = array(
				'label'       => $letter . ( '' !== $name ? ' · ' . self::no_address( $name ) : '' ),
				'control'     => $control,
				'visitors'    => (int) $r->visitors,
				'conversions' => (int) $r->conversions,
				'rate'        => $rate,
				'improvement' => $control ? 0.0 : (float) $r->improvement,
				'p'           => $control ? 1.0 : (float) $r->p_value,
				'significant' => $significant,
				'win'         => false,
			);
			if ( $significant && $up && ( null === $best || $rate > $rows[ $best ]['rate'] ) ) {
				$best = count( $rows ) - 1;
			}
			if ( $significant && ! $up && 'none' === $verdict ) {
				$verdict = 'loser';
			}
		}
		if ( null !== $best ) {
			$rows[ $best ]['win'] = true;
			$verdict              = 'winner';
		}

		return array(
			'name'         => self::no_address( trim( wp_specialchars_decode( isset( $test->name ) ? (string) $test->name : '', ENT_QUOTES ) ) ),
			'status'       => $status,
			'status_label' => $label[0],
			'tag'          => $label[1],
			'verdict'      => $verdict,
			'best'         => null !== $best ? $rows[ $best ]['label'] : '',
			'variants'     => $rows,
		);
	}

	/**
	 * Result cell of a variant: "—" for the original, else the change against
	 * it, whether it is significant, and the p-value.
	 *
	 * @param array $v    Variant of ab_view().
	 * @param bool  $open The test still runs (or is paused).
	 * @return string HTML.
	 */
	public static function ab_result_html( $v, $open = true ) {
		if ( $v['control'] ) {
			return '—';
		}
		$change = ( $v['improvement'] > 0 ? '+' : ( $v['improvement'] < 0 ? '−' : '' ) ) . number_format_i18n( abs( $v['improvement'] ), 0 ) . ' %';
		$p      = $v['p'] < 0.01 ? 'p < ' . number_format_i18n( 0.01, 2 ) : 'p = ' . number_format_i18n( $v['p'], 2 );
		if ( ! $v['significant'] ) {
			/* translators: %s: change against the original, e.g. "+5 %". */
			$text = $open ? __( '%s, not significant yet', 'opti-behavior' ) : __( '%s, not significant', 'opti-behavior' );

			return esc_html( sprintf( $text, $change ) . ' (' . $p . ')' );
		}
		/* translators: %s: change against the original, e.g. "+47 %". */
		$text = esc_html( sprintf( __( '%s, significant', 'opti-behavior' ), $change ) );

		return ( $v['win'] ? '<span class="obr-win">' . $text . '</span>' : $text ) . ' ' . esc_html( '(' . $p . ')' );
	}

	/**
	 * "What to do" of one A/B test (pure).
	 *
	 * @param array $t ab_view().
	 * @return string
	 */
	public static function ab_todo( $t ) {
		if ( 'winner' === $t['verdict'] ) {
			/* translators: %s: variant, e.g. "B · short form". */
			return sprintf( __( 'Variant %s converts better and the result is significant: apply it to the page.', 'opti-behavior' ), $t['best'] );
		}
		if ( 'loser' === $t['verdict'] ) {
			return __( 'No variant beats the original and the difference is significant: keep the original.', 'opti-behavior' );
		}
		if ( in_array( $t['status'], array( 'running', 'paused' ), true ) ) {
			return __( 'Let the test run: no variant is significantly better yet.', 'opti-behavior' );
		}

		return __( 'No variant made a significant difference: keep the original, or test a bigger change.', 'opti-behavior' );
	}

	/**
	 * A share as "9 %" or "9.5 %".
	 *
	 * @param float $pct Percent.
	 * @return string
	 */
	private static function pct_text( $pct ) {
		$pct = round( (float) $pct, 1 );

		return number_format_i18n( $pct, floor( $pct ) === $pct ? 0 : 1 ) . ' %';
	}

	/**
	 * The 3 most clicked elements of the period (click events 16 / 17, as the
	 * report e-mail and the Page X-Ray preview): ONE grouped query (page x
	 * element), html / body and consent elements left out, unreadable or
	 * personal labels skipped. Rows { label, kind, page, path, clicks }.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @param bool   $spam Exclude spam sessions.
	 * @return array
	 */
	public static function click_rows( $from, $to, $spam ) {
		global $wpdb;
		if ( ! self::is_day( $from ) || ! self::is_day( $to ) ) {
			return array();
		}
		$spam_sql = class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::session_sql( 's', 'AND', (bool) $spam ) : '';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, spam clause built by the plugin; values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.page_id2 AS pid, MAX(p.title) AS title, MAX(p.url) AS url,
					MAX(e.element_tag) AS tag, MAX(e.element_text) AS txt, MAX(e.element_class) AS cls, MAX(e.element_id) AS eid,
					COUNT(*) AS c
				FROM {$wpdb->prefix}optibehavior_events e
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				LEFT JOIN {$wpdb->prefix}optibehavior_pages p ON p.id = e.page_id2
				WHERE e.insert_at BETWEEN %s AND %s
				AND e.event IN (16, 17)
				AND LOWER(COALESCE(e.element_tag, '')) NOT IN ('', 'html', 'body')
				{$spam_sql}
				GROUP BY e.page_id2, COALESCE(NULLIF(e.element_selector, ''), NULLIF(e.element_text, ''), e.element_tag)
				ORDER BY c DESC
				LIMIT 30",
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return self::pick_click_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Rows printed by the click block (pure): first 3 rows with a printable,
	 * non-consent label.
	 *
	 * @param array $rows Query rows { pid, title, url, tag, txt, cls, eid, c }.
	 * @return array
	 */
	public static function pick_click_rows( $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$row = (array) $row;
			if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) && Opti_Behavior_Stats_Spam_Filter::is_consent_element( array( isset( $row['cls'] ) ? $row['cls'] : '', isset( $row['eid'] ) ? $row['eid'] : '' ) ) ) {
				continue;
			}
			$tag  = isset( $row['tag'] ) ? (string) $row['tag'] : '';
			$kind = self::element_kind( $tag );
			$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( isset( $row['txt'] ) ? (string) $row['txt'] : '' ) ) );
			if ( self::is_reading_click( $kind, $text, isset( $row['cls'] ) ? $row['cls'] : '', isset( $row['eid'] ) ? $row['eid'] : '' ) ) {
				continue;
			}
			// QA OPT-1730: never a CSS selector ("div.entry-content") in the
			// report: an element without text is named by what it is.
			$named = '' !== $text;
			$label = $named && class_exists( 'Opti_Behavior_Report_Generator' )
				? Opti_Behavior_Report_Generator::element_label( $tag, $text, '' )
				: self::element_kind_name( $kind );
			if ( '' === $label ) {
				continue;
			}
			$url   = isset( $row['url'] ) ? (string) $row['url'] : '';
			$path  = Opti_Behavior_Report::path_of( $url );
			$title = self::strip_site( isset( $row['title'] ) ? wp_specialchars_decode( (string) $row['title'], ENT_QUOTES ) : '' );
			$out[] = array(
				'label'  => $label,
				'named'  => $named,
				'kind'   => $kind,
				'page'   => '' !== trim( $title ) ? self::no_address( $title ) : $path,
				'path'   => $path,
				'clicks' => isset( $row['c'] ) ? (int) $row['c'] : 0,
			);
			if ( count( $out ) >= 3 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Kind of a clicked element by its tag (pure): link, button, field, image, text.
	 *
	 * @param string $tag element_tag.
	 * @return string
	 */
	public static function element_kind( $tag ) {
		$tag = strtolower( trim( (string) $tag ) );
		if ( 'a' === $tag ) {
			return 'link';
		}
		if ( 'button' === $tag ) {
			return 'button';
		}
		if ( in_array( $tag, array( 'input', 'select', 'textarea', 'label', 'option' ), true ) ) {
			return 'field';
		}
		if ( in_array( $tag, array( 'img', 'svg', 'picture', 'video', 'path' ), true ) ) {
			return 'image';
		}

		return 'text';
	}

	/**
	 * A click while reading, not on something to fix (pure, QA OPT-1730): a
	 * text element without its own short text (a block of the page, the
	 * article body), a sentence of more than READING_WORDS words, or a
	 * content container by its class / id ("entry-content", "post-content").
	 *
	 * @param string $kind element_kind() key.
	 * @param string $text Tracked text, spaces folded.
	 * @param string $cls  element_class.
	 * @param string $eid  element_id.
	 * @return bool
	 */
	public static function is_reading_click( $kind, $text, $cls, $eid ) {
		if ( 'text' !== $kind ) {
			return false;
		}
		if ( '' === $text || count( preg_split( '/\s+/u', $text ) ) > self::READING_WORDS ) {
			return true;
		}

		return (bool) preg_match( '/(^|[\s_-])(entry|post|page|article|the|wp-block-post|site|main)[-_]?(content|body|text)($|[\s_-])/i', (string) $cls . ' ' . (string) $eid );
	}

	/**
	 * Plain name of a clicked element that has no text (QA OPT-1730).
	 *
	 * @param string $kind element_kind() key.
	 * @return string
	 */
	public static function element_kind_name( $kind ) {
		$names = array(
			'link'   => __( 'A link without text', 'opti-behavior' ),
			'button' => __( 'A button without text', 'opti-behavior' ),
			'field'  => __( 'A form field', 'opti-behavior' ),
			'image'  => __( 'An image', 'opti-behavior' ),
		);

		return isset( $names[ $kind ] ) ? $names[ $kind ] : '';
	}

	/**
	 * Printed name of a click row: its text in quotes, else the plain name.
	 *
	 * @param array $row Row of pick_click_rows().
	 * @return string
	 */
	public static function element_print_name( $row ) {
		return ! isset( $row['named'] ) || $row['named'] ? '“' . $row['label'] . '”' : $row['label'];
	}

	/**
	 * Printed kind of a clicked element.
	 *
	 * @param string $kind element_kind() key.
	 * @return string
	 */
	public static function element_kind_label( $kind ) {
		$labels = array(
			'link'   => _x( 'link', 'kind of clicked element', 'opti-behavior' ),
			'button' => _x( 'button', 'kind of clicked element', 'opti-behavior' ),
			'field'  => _x( 'form field', 'kind of clicked element', 'opti-behavior' ),
			'image'  => _x( 'image, not a link', 'kind of clicked element', 'opti-behavior' ),
			'text'   => _x( 'text, not a link', 'kind of clicked element', 'opti-behavior' ),
		);

		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $labels['text'];
	}

	/**
	 * "What to do" of the click block (pure), from the most clicked element.
	 *
	 * @param array $row First row of pick_click_rows().
	 * @return string
	 */
	public static function clicks_todo( $row ) {
		if ( isset( $row['named'] ) && ! $row['named'] ) {
			if ( 'image' === $row['kind'] ) {
				/* translators: %s: page title. */
				return sprintf( __( 'Visitors click an image on %s, but it is not a link: link it to the page they expect, or make it look less clickable.', 'opti-behavior' ), $row['page'] );
			}
			/* translators: %s: page title. */
			return sprintf( __( 'The most clicked element of the site, on %s, has no visible text: give it a clear label, and keep it visible without scrolling.', 'opti-behavior' ), $row['page'] );
		}
		if ( in_array( $row['kind'], array( 'text', 'image' ), true ) ) {
			/* translators: 1: clicked element, 2: page title. */
			return sprintf( __( 'Visitors click “%1$s” on %2$s, but it is not a link: make it one, or make it look less clickable.', 'opti-behavior' ), $row['label'], $row['page'] );
		}

		/* translators: 1: clicked element, 2: page title. */
		return sprintf( __( 'Keep “%1$s” on %2$s visible without scrolling, on mobile too: it is the most clicked element of the site.', 'opti-behavior' ), $row['label'], $row['page'] );
	}

	/**
	 * Visits started per day of the week x hour of the day, site time (the
	 * plugin stores site time), ONE grouped query. [ day 0 = Sunday .. 6 ][ hour 0..23 ] => visits.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @param bool   $spam Exclude spam sessions.
	 * @return array
	 */
	public static function hour_grid( $from, $to, $spam ) {
		global $wpdb;
		if ( ! self::is_day( $from ) || ! self::is_day( $to ) ) {
			return array();
		}
		$spam_sql = class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::session_sql( 's', 'AND', (bool) $spam ) : '';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, spam clause built by the plugin; values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DAYOFWEEK(s.start_time) - 1 AS d, HOUR(s.start_time) AS h, COUNT(*) AS c
				FROM {$wpdb->prefix}optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s
				{$spam_sql}
				GROUP BY d, h",
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$grid = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$d = (int) $row['d'];
			$h = (int) $row['h'];
			if ( $d >= 0 && $d < 7 && $h >= 0 && $h < 24 ) {
				$grid[ $d ][ $h ] = (int) $row['c'];
			}
		}

		return $grid;
	}

	/**
	 * Peak of the grid (pure): busiest day + hour (first in week order on a
	 * tie), its visits, the max cell and the quietest day. null when empty.
	 *
	 * @param array $grid hour_grid().
	 * @return array|null { day, hour, visits, max, quiet }.
	 */
	public static function hours_peak( $grid ) {
		if ( ! is_array( $grid ) || 7 !== count( $grid ) ) {
			return null;
		}
		$best  = null;
		$quiet = null;
		$days  = array();
		foreach ( self::week_order() as $d ) {
			$row        = isset( $grid[ $d ] ) && is_array( $grid[ $d ] ) ? $grid[ $d ] : array();
			$days[ $d ] = array_sum( $row );
			for ( $h = 0; $h < 24; $h++ ) {
				$v = isset( $row[ $h ] ) ? (int) $row[ $h ] : 0;
				if ( $v > 0 && ( null === $best || $v > $best['visits'] ) ) {
					$best = array(
						'day'    => $d,
						'hour'   => $h,
						'visits' => $v,
					);
				}
			}
			if ( null === $quiet || $days[ $d ] < $days[ $quiet ] ) {
				$quiet = $d;
			}
		}
		if ( null === $best ) {
			return null;
		}
		$best['max']   = $best['visits'];
		$best['quiet'] = $quiet;

		return $best;
	}

	/**
	 * The grid as one inline SVG (mm units): 7 rows in the site's week order,
	 * 24 columns, grey from light to dark by visits (prints in black and white).
	 *
	 * @param array $grid hour_grid().
	 * @param array $peak hours_peak().
	 * @return string
	 */
	private static function hours_svg( $grid, $peak ) {
		$w     = 178;
		$left  = 9;
		$top   = 4;
		$cw    = ( $w - $left ) / 24;
		$ch    = 4.4;
		$h     = $top + 7 * $ch;
		$title = sprintf(
			/* translators: 1: day and hour, 2: visits. */
			__( 'Visits per day of the week and hour of the day; busiest: %1$s, %2$s visits.', 'opti-behavior' ),
			self::day_hour( $peak['day'], $peak['hour'] ),
			number_format_i18n( $peak['visits'] )
		);
		$svg   = '<svg viewBox="0 0 ' . $w . ' ' . self::num( $h ) . '" role="img" aria-label="' . esc_attr( $title ) . '"><title>' . esc_html( $title ) . '</title>';
		for ( $hr = 0; $hr < 24; $hr += 3 ) {
			$svg .= '<text x="' . self::num( $left + $hr * $cw + $cw / 2 ) . '" y="2.8" font-size="2.4" text-anchor="middle" fill="#64748b">' . esc_html( sprintf( '%02d', $hr ) ) . '</text>';
		}
		foreach ( self::week_order() as $i => $d ) {
			$y    = $top + $i * $ch;
			$svg .= '<text x="0" y="' . self::num( $y + $ch * 0.72 ) . '" font-size="2.4" fill="#64748b">' . esc_html( self::weekday( $d, true ) ) . '</text>';
			for ( $hr = 0; $hr < 24; $hr++ ) {
				$v    = isset( $grid[ $d ][ $hr ] ) ? (int) $grid[ $d ][ $hr ] : 0;
				$fill = $v > 0 ? '#0f172a" fill-opacity="' . self::num( 0.08 + 0.82 * $v / max( 1, $peak['max'] ) ) : '#f1f5f9';
				$svg .= '<rect x="' . self::num( $left + $hr * $cw + 0.2 ) . '" y="' . self::num( $y + 0.2 ) . '" width="' . self::num( $cw - 0.4 ) . '" height="' . self::num( $ch - 0.4 ) . '" rx="0.5" fill="' . $fill . '"/>';
			}
		}

		return $svg . '</svg>';
	}

	/**
	 * Days 0 (Sunday) .. 6 in the site's week order (Settings > General).
	 *
	 * @return int[]
	 */
	private static function week_order() {
		$start = (int) get_option( 'start_of_week', 1 );
		$out   = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$out[] = ( $start + $i ) % 7;
		}

		return $out;
	}

	/**
	 * Name of a day of the week in the site language.
	 *
	 * @param int  $day   0 = Sunday.
	 * @param bool $short Abbreviation.
	 * @return string
	 */
	private static function weekday( $day, $short = false ) {
		global $wp_locale;
		if ( ! is_object( $wp_locale ) ) {
			$names = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
			return $short ? substr( $names[ $day ], 0, 3 ) : $names[ $day ];
		}
		$name = $wp_locale->get_weekday( (int) $day );

		return $short ? $wp_locale->get_weekday_abbrev( $name ) : $name;
	}

	/**
	 * An hour of the day as "10:00".
	 *
	 * @param int $hour 0..23.
	 * @return string
	 */
	private static function hour_label( $hour ) {
		return sprintf( '%02d:00', (int) $hour );
	}

	/**
	 * "Tuesday at 10:00".
	 *
	 * @param int $day  0 = Sunday.
	 * @param int $hour 0..23.
	 * @return string
	 */
	private static function day_hour( $day, $hour ) {
		/* translators: 1: day of the week, 2: hour, e.g. "10:00". */
		return sprintf( __( '%1$s at %2$s', 'opti-behavior' ), self::weekday( $day ), self::hour_label( $hour ) );
	}

	/**
	 * Time zone of the site, as printed beside the section title.
	 *
	 * @return string
	 */
	private static function tz_label() {
		$tz = wp_timezone_string();
		$tz = preg_match( '/^[+-]/', $tz ) ? 'UTC' . $tz : $tz;

		/* translators: %s: time zone of the site, e.g. "Europe/Paris" or "UTC+01:00". */
		return sprintf( __( 'site time: %s', 'opti-behavior' ), $tz );
	}

	/**
	 * A number for SVG attributes (2 decimals, dot).
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private static function num( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Whether a text is a Y-m-d day.
	 *
	 * @param string $day Text.
	 * @return bool
	 */
	private static function is_day( $day ) {
		return is_string( $day ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day );
	}

	/**
	 * Section number given by sections(), else the default of the Free order.
	 *
	 * @param array $data Section data.
	 * @param int   $def  Default.
	 * @return int
	 */
	private static function no_of( $data, $def ) {
		return ! empty( $data['no'] ) ? (int) $data['no'] : (int) $def;
	}

	/**
	 * The grey "Available with Pro" line of a Free block: no figure, no link.
	 *
	 * @param string $what What Pro adds, plain text.
	 * @return string
	 */
	public static function pro_line( $what ) {
		return '<p class="obr-pro-line"><span>PRO</span><span>' . esc_html(
			/* translators: %s: what the Pro plugin adds to this block. */
			sprintf( __( 'Available with Pro: %s', 'opti-behavior' ), $what )
		) . '</span></p>';
	}

	/**
	 * Visits per day of the Traffic chart: one black line, a grey area, the
	 * busiest day named under it. Nothing when the period has no day.
	 *
	 * @param array $daily Rows { date, sessions } of the screen's chart.
	 * @return string
	 */
	private static function curve_html( $daily ) {
		$days   = array();
		$values = array();
		foreach ( is_array( $daily ) ? $daily : array() as $row ) {
			$row = (array) $row;
			if ( isset( $row['date'] ) ) {
				$days[]   = (string) $row['date'];
				$values[] = isset( $row['sessions'] ) ? (int) $row['sessions'] : 0;
			}
		}
		if ( count( $days ) < 2 || max( $values ) <= 0 ) {
			return '';
		}
		$peak  = array_search( max( $values ), $values, true );
		$title = sprintf(
			/* translators: 1: number of days, 2: visits of the busiest day, 3: that day. */
			__( 'Visits per day over %1$s days; busiest day: %2$s visits on %3$s.', 'opti-behavior' ),
			number_format_i18n( count( $days ) ),
			number_format_i18n( $values[ $peak ] ),
			self::day_long( $days[ $peak ] )
		);
		$svg   = Opti_Behavior_Report::svg_line(
			$days,
			$values,
			array(),
			178,
			46,
			array(
				'area'       => true,
				'area_color' => '#e2e8f0',
				'peak'       => true,
				'title'      => $title,
			)
		);
		// Inline SVG needs no namespace: the report prints no address.
		$svg   = str_replace( ' xmlns="http://www.w3.org/2000/svg"', '', $svg );
		$html  = '<figure class="xr-figure obr-curve xr-keep obr-block"><div class="xr-figure-top"><h3>' . esc_html__( 'Visits per day', 'opti-behavior' ) . '</h3>';
		$html .= '<span class="xr-legend"><span><svg viewBox="0 0 6 2" aria-hidden="true"><line x1="0" y1="1" x2="6" y2="1" stroke="#0f172a" stroke-width=".6"/></svg>' . esc_html( _x( 'visits', 'site report comparison', 'opti-behavior' ) ) . '</span></span></div>';
		$html .= $svg . '<figcaption>' . esc_html(
			/* translators: 1: busiest day, 2: its visits. */
			sprintf( __( 'Busiest day: %1$s, %2$s visits.', 'opti-behavior' ), self::day_long( $days[ $peak ] ), number_format_i18n( $values[ $peak ] ) )
		) . '</figcaption></figure>';

		return $html;
	}

	/**
	 * Devices as one stacked bar: every segment carries its name and share;
	 * a segment too narrow for its words prints them under the bar.
	 *
	 * @param array $devices [ label, count ], largest first.
	 * @param int   $total   Visits of the period.
	 * @return string
	 */
	private static function split_html( $devices, $total ) {
		$html = '<div class="obr-split' . ( self::has_narrow( $devices, $total ) ? ' obr-split--low' : '' ) . '" role="img" aria-label="' . esc_attr( implode( ', ', array_map(
			function ( $d ) use ( $total ) {
				return $d[0] . ' ' . number_format_i18n( self::share( $d[1], $total ) ) . ' %';
			},
			$devices
		) ) ) . '">';
		foreach ( $devices as $i => $d ) {
			$share = self::share( $d[1], $total );
			$words = '<b>' . esc_html( $d[0] ) . '</b> ' . esc_html( number_format_i18n( $share ) . ' %' );
			$html .= $share < self::SPLIT_LABEL_MIN
				? '<i class="obr-split-' . (int) min( 3, $i + 1 ) . ' is-narrow" style="width:' . (int) max( 1, $share ) . '%"><span>' . $words . '</span></i>'
				: '<i class="obr-split-' . (int) min( 3, $i + 1 ) . '" style="width:' . (int) $share . '%">' . $words . '</i>';
		}

		return $html . '</div>';
	}

	/**
	 * Whether a device segment is too narrow for its words.
	 *
	 * @param array $devices [ label, count ].
	 * @param int   $total   Visits.
	 * @return bool
	 */
	private static function has_narrow( $devices, $total ) {
		foreach ( $devices as $d ) {
			if ( self::share( $d[1], $total ) < self::SPLIT_LABEL_MIN ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Source rows [ label, count, kind ] of the Referrers widget, largest first.
	 *
	 * @param array $items Widget rows (with `kind`, OPT-1680).
	 * @return array
	 */
	public static function source_rows( $items ) {
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$item  = (array) $item;
			$count = isset( $item['count'] ) ? (int) $item['count'] : 0;
			if ( $count > 0 ) {
				$out[] = array( self::no_address( isset( $item['referrer'] ) ? (string) $item['referrer'] : '' ), $count, isset( $item['kind'] ) ? (string) $item['kind'] : '' );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);

		return $out;
	}

	/**
	 * Printed name of a source kind.
	 *
	 * @param string $kind Kind key.
	 * @return string
	 */
	public static function kind_label( $kind ) {
		$labels = array(
			'search'   => _x( 'Search', 'kind of traffic source', 'opti-behavior' ),
			'ai'       => _x( 'AI assistant', 'kind of traffic source', 'opti-behavior' ),
			'social'   => _x( 'Social', 'kind of traffic source', 'opti-behavior' ),
			'direct'   => _x( 'Direct', 'kind of traffic source', 'opti-behavior' ),
			'paid'     => _x( 'Paid', 'kind of traffic source', 'opti-behavior' ),
			'email'    => _x( 'E-mail', 'kind of traffic source', 'opti-behavior' ),
			'referral' => _x( 'Other site', 'kind of traffic source', 'opti-behavior' ),
			'campaign' => _x( 'Campaign', 'kind of traffic source', 'opti-behavior' ),
		);

		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : '—';
	}

	/**
	 * "What to do" of Traffic (pure): the first source that is not direct
	 * and the most viewed page. '' when neither is known.
	 *
	 * @param array $sources Source rows [ label, count, kind ].
	 * @param array $pages   Top pages of the screen.
	 * @param int   $total   Visits.
	 * @return string
	 */
	public static function traffic_todo( $sources, $pages, $total ) {
		$page = '';
		if ( ! empty( $pages[0] ) && is_array( $pages[0] ) ) {
			$title = self::strip_site( isset( $pages[0]['title'] ) ? wp_specialchars_decode( (string) $pages[0]['title'], ENT_QUOTES ) : '' );
			$page  = '' !== trim( $title ) ? self::no_address( $title ) : Opti_Behavior_Report::path_of( isset( $pages[0]['url'] ) ? (string) $pages[0]['url'] : '' );
		}
		$src = null;
		foreach ( is_array( $sources ) ? $sources : array() as $s ) {
			if ( isset( $s[2] ) && in_array( $s[2], array( 'search', 'ai', 'social', 'referral', 'paid', 'email', 'campaign' ), true ) ) {
				$src = $s;
				break;
			}
		}
		if ( $src && '' !== $page ) {
			/* translators: 1: source name, 2: its share of visits, e.g. "37 %", 3: page title. */
			return sprintf( __( '%1$s brings %2$s of visits: keep the title and description of “%3$s” up to date, it is the most visited page.', 'opti-behavior' ), $src[0], number_format_i18n( self::share( $src[1], $total ) ) . ' %', $page );
		}
		if ( '' !== $page ) {
			/* translators: %s: page title. */
			return sprintf( __( 'Keep the title and description of “%s” up to date: it is the most visited page.', 'opti-behavior' ), $page );
		}

		return '';
	}

	/**
	 * One "What to do" line.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function todo( $text ) {
		return '<p class="obr-do"><b>' . esc_html__( 'What to do', 'opti-behavior' ) . '</b><span>' . esc_html( $text ) . '</span></p>';
	}

	/**
	 * Pointer to an issue of section 2, in place of its figures.
	 *
	 * @param int $rank Issue number.
	 * @return string
	 */
	public static function ref( $rank ) {
		/* translators: %s: issue number. */
		return '<span class="obr-ref">' . esc_html( sprintf( __( 'issue #%s', 'opti-behavior' ), number_format_i18n( $rank ) ) ) . '</span>';
	}

	/**
	 * Printed title of an issue (card title, else signal, else entity).
	 *
	 * @param array $it Shaped insight.
	 * @return string
	 */
	public static function issue_title( $it ) {
		foreach ( array( 'card_title', 'signal_name', 'entity_label' ) as $key ) {
			if ( ! empty( $it[ $key ] ) && is_string( $it[ $key ] ) ) {
				return self::no_address( $it[ $key ] );
			}
		}

		return '';
	}

	/**
	 * 2 The 3 most costly issues.
	 *
	 * @param array $data { ctx, args }.
	 * @return string
	 */
	public static function render_issues( $data ) {
		$ctx    = $data['ctx'];
		$issues = $ctx['issues'];
		$money  = false;
		foreach ( $issues as $it ) {
			if ( null !== Opti_Behavior_Smart_Insights_Impact_Calculator::get_revenue_amount( $it ) ) {
				$money = true;
			}
		}
		$aside = $money ? __( 'ranked by amount lost, then visits lost', 'opti-behavior' ) : __( 'ranked by visits lost', 'opti-behavior' );
		$html  = '<div class="xr-section obr-issues">' . self::sec_head( self::no_of( $data, 2 ), __( 'The 3 most costly issues', 'opti-behavior' ), esc_html( $aside ) );
		if ( ! $issues ) {
			return $html . self::state( esc_html__( 'No costly issue detected for this period.', 'opti-behavior' ) ) . '</div>';
		}
		$html .= '<div class="xr-problems">';
		foreach ( $issues as $i => $it ) {
			$html .= self::issue_card( $i + 1, $it, max( 1, $ctx['visits'] ) );
		}
		$html .= '</div>';
		$count = count( $issues );
		if ( $count < self::TOP_ISSUES && $ctx['issues_total'] <= $count ) {
			/* translators: %s: number of issues. */
			$html .= '<p class="xr-note obr-issues-more">' . esc_html( sprintf( _n( 'Only %s open issue over this period.', 'Only %s open issues over this period.', $count, 'opti-behavior' ), number_format_i18n( $count ) ) ) . '</p>';
		} elseif ( $ctx['issues_total'] > $count ) {
			$more  = $ctx['issues_total'] - $count;
			/* translators: %s: number of issues. */
			$html .= '<p class="xr-small obr-issues-more">' . esc_html( sprintf( _n( '%s other open issue in Smart Insights, with a smaller cost.', '%s other open issues in Smart Insights, with a smaller cost.', $more, 'opti-behavior' ), number_format_i18n( $more ) ) ) . '</p>';
		}

		return $html . '</div>';
	}

	/**
	 * The one sober line about the full version: no link, no colour, no banner.
	 *
	 * @param array $data { ctx, args }.
	 * @return string
	 */
	public static function render_more( $data ) {
		// Pro active (active / trial / grace): the line only speaks to Free sites.
		// Same test as the report generator.
		if ( function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active() ) {
			return '';
		}

		/**
		 * Whether the short site report ends with the line about the full report.
		 *
		 * @param bool  $show Default true.
		 * @param array $args Report arguments.
		 */
		if ( ! apply_filters( 'opti_behavior_report_site_more_line', true, isset( $data['args'] ) ? $data['args'] : array() ) ) {
			return '';
		}

		/* translators: %s: product name. */
		return '<p class="obr-more">' . sprintf( esc_html__( 'Full site report with a card for every page: %s', 'opti-behavior' ), '<b>Opti-Behavior Pro</b>' ) . '</p>';
	}

	/* ----------------------------------------------------------------- *
	 * Helpers (pure).
	 * ----------------------------------------------------------------- */

	/**
	 * One issue card: severity, title, headline, figures, Where, What to do.
	 *
	 * @param int   $rank  Rank (1-3).
	 * @param array $it    Shaped insight.
	 * @param int   $total Visits of the period.
	 * @return string
	 */
	public static function issue_card( $rank, $it, $total ) {
		$it    = is_array( $it ) ? $it : array();
		$sev   = self::severity( isset( $it['scores']['priority_label_key'] ) ? (string) $it['scores']['priority_label_key'] : '' );
		$title = '';
		foreach ( array( 'card_title', 'signal_name', 'entity_label' ) as $key ) {
			if ( '' === $title && ! empty( $it[ $key ] ) && is_string( $it[ $key ] ) ) {
				$title = $it[ $key ];
			}
		}
		$head   = isset( $it['impact']['headline'] ) && is_string( $it['impact']['headline'] ) ? $it['impact']['headline'] : '';
		$lost   = Opti_Behavior_Smart_Insights_Impact_Calculator::get_users_lost( $it );
		$amount = Opti_Behavior_Smart_Insights_Impact_Calculator::get_revenue_amount( $it );
		$share  = null === $lost ? 0 : (int) min( 100, round( 100 * $lost / max( 1, $total ) ) );
		$action = isset( $it['next_action_short'] ) && is_string( $it['next_action_short'] ) ? $it['next_action_short'] : '';

		$html  = '<article class="xr-problem xr-problem--' . esc_attr( $sev[0] ) . '"><div class="xr-problem-hd"><span class="xr-problem-rank">' . esc_html( number_format_i18n( $rank ) ) . '</span>';
		$html .= '<div><span class="xr-tag xr-tag--' . esc_attr( $sev[1] ) . '">' . esc_html( $sev[2] ) . '</span><h3>' . esc_html( self::no_address( $title ) ) . '</h3>';
		if ( '' !== $head ) {
			$html .= '<p>' . esc_html( self::no_address( $head ) ) . '</p>';
		}
		$html .= '</div>';
		if ( null !== $lost || null !== $amount ) {
			$html .= '<div class="xr-problem-nums">';
			if ( null !== $amount ) {
				$currency = isset( $it['impact']['revenue']['currency'] ) ? (string) $it['impact']['revenue']['currency'] : '';
				$html    .= '<div><b class="obr-money">' . esc_html( self::money( $amount, $currency ) ) . '</b><span>' . esc_html__( 'lost, measured', 'opti-behavior' ) . '</span></div>';
			}
			if ( null !== $lost ) {
				$html .= '<div><b>' . esc_html( number_format_i18n( $lost ) ) . '</b><span>' . esc_html( _n( 'visit lost', 'visits lost', $lost, 'opti-behavior' ) ) . '</span></div>';
				$html .= '<div><b>' . esc_html( number_format_i18n( $share ) . ' %' ) . '</b><span>' . esc_html__( 'of all visits', 'opti-behavior' ) . '</span></div>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';
		if ( null !== $lost ) {
			$html .= '<div class="xr-share" aria-hidden="true"><i style="width:' . (int) $share . '%"></i></div>';
		}
		$html .= '<dl class="xr-problem-body"><div><dt>' . esc_html__( 'Where', 'opti-behavior' ) . '</dt><dd>' . esc_html( self::where_of( $it ) ) . '</dd></div>';
		if ( '' !== $action ) {
			$html .= '<div><dt>' . esc_html__( 'What to do', 'opti-behavior' ) . '</dt><dd>' . esc_html( self::no_address( $action ) ) . '</dd></div>';
		}

		return $html . '</dl></article>';
	}

	/**
	 * Severity of a card from its Smart Insights priority.
	 *
	 * @param string $key critical | high | medium | low.
	 * @return array { card class, tag class, word }.
	 */
	public static function severity( $key ) {
		switch ( $key ) {
			case 'critical':
				return array( 'crit', 'crit', __( 'Critical', 'opti-behavior' ) );
			case 'high':
				return array( 'warn', 'warn', __( 'High', 'opti-behavior' ) );
			case 'low':
				return array( 'watch', 'info', __( 'Low', 'opti-behavior' ) );
			default:
				return array( 'watch', 'info', __( 'Medium', 'opti-behavior' ) );
		}
	}

	/**
	 * Comparison line of a tile.
	 *
	 * @param float $change      Change in %.
	 * @param bool  $rise_is_good Whether a rise is good news (false for bounce).
	 * @return string
	 */
	public static function vs( $change, $rise_is_good, $unit = '%' ) {
		$change = (float) $change;
		$good   = 0.0 === $change || ( $change > 0 ) === (bool) $rise_is_good;
		$text   = self::change_text( $change, $unit );

		/* translators: %s: change, e.g. "+12 %" (already in bold). */
		return '<p class="xr-vs">' . sprintf( esc_html__( '%s vs the period before', 'opti-behavior' ), '<b class="' . ( $good ? 'obr-up' : 'obr-down' ) . '">' . esc_html( $text ) . '</b>' ) . '</p>';
	}

	/**
	 * "+12 %" for a count, "+18.4 pts" for a rate (OPT-1624: a gap of two
	 * rates is in points, see the data helper get_period_change()).
	 *
	 * @param float  $change Change.
	 * @param string $unit   '%' or 'pts'.
	 * @return string
	 */
	public static function change_text( $change, $unit = '%' ) {
		$change = (float) $change;
		$sign   = $change > 0 ? '+' : ( $change < 0 ? '−' : '' );
		$value  = abs( $change );
		$num    = number_format_i18n( $value, abs( $value - round( $value ) ) > 0.05 ? 1 : 0 );
		if ( 'pts' === $unit ) {
			/* translators: %s: gap between two rates in percentage points, e.g. "18.4". */
			return $sign . sprintf( _x( '%s pts', 'percentage points', 'opti-behavior' ), $num );
		}
		return $sign . $num . ' %';
	}

	/**
	 * Note when the period before is incomplete (OPT-1624): no change shown.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	private static function not_comparable_note( $ctx ) {
		/* translators: %s: dates of the period before. */
		return sprintf( __( 'No comparison with %s: that period is incomplete (too few days with data).', 'opti-behavior' ), self::range_text( $ctx['prev_from'], $ctx['prev_to'] ) );
	}

	/**
	 * Unit of a Traffic change: 'pts' for the rates, '%' else (OPT-1624).
	 *
	 * @param string $metric Metric key.
	 * @return string
	 */
	private static function change_unit( $metric ) {
		return in_array( (string) $metric, array( 'avg_scroll_depth', 'bounce_rate', 'conversion_rate' ), true ) ? 'pts' : '%';
	}

	/**
	 * Rows { label, count } of a screen widget, the largest first.
	 *
	 * @param array  $items Widget rows.
	 * @param string $key   Label key.
	 * @return array List of [ label, count ].
	 */
	public static function rows( $items, $key ) {
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$item  = (array) $item;
			$count = isset( $item['count'] ) ? (int) $item['count'] : 0;
			if ( $count > 0 ) {
				$out[] = array( self::no_address( isset( $item[ $key ] ) ? (string) $item[ $key ] : '' ), $count );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);

		return $out;
	}

	/**
	 * Table "label · visits · share".
	 *
	 * @param string $head  Head of the first column.
	 * @param array  $rows  [ label, count ].
	 * @param int    $total Visits of the period.
	 * @return string
	 */
	private static function share_table( $head, $rows, $total ) {
		$html = '<table class="xr-table"><thead><tr><th>' . esc_html( $head ) . '</th><th class="xr-n">' . esc_html__( 'Visits', 'opti-behavior' ) . '</th><th>' . esc_html__( 'Share', 'opti-behavior' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$html .= '<tr><td>' . esc_html( $r[0] ) . '</td><td class="xr-n">' . esc_html( number_format_i18n( $r[1] ) ) . '</td><td>' . self::bar( self::share( $r[1], $total ) ) . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * Share bar of a table cell, with the share written.
	 *
	 * @param int $pct 0-100.
	 * @return string
	 */
	private static function bar( $pct ) {
		$pct = max( 0, min( 100, (int) $pct ) );

		return '<div class="xr-bar"><span><i style="width:' . $pct . '%"></i></span><b>' . esc_html( number_format_i18n( $pct ) . ' %' ) . '</b></div>';
	}

	/**
	 * Share in whole percents, 0-100.
	 *
	 * @param int $part  Part.
	 * @param int $total Total.
	 * @return int
	 */
	public static function share( $part, $total ) {
		return $total > 0 ? (int) max( 0, min( 100, round( 100 * $part / $total ) ) ) : 0;
	}

	/**
	 * Numbered section head.
	 *
	 * @param int    $no    Number.
	 * @param string $title Title.
	 * @param string $aside Escaped HTML on the right.
	 * @return string
	 */
	private static function sec_head( $no, $title, $aside = '' ) {
		return '<div class="xr-sec-head"><span class="xr-sec-no">' . esc_html( number_format_i18n( $no ) ) . '</span><h2>' . esc_html( $title ) . '</h2>' . ( '' !== $aside ? '<span class="xr-aside">' . $aside . '</span>' : '' ) . '</div>';
	}

	/**
	 * An empty state or a warning: one framed line.
	 *
	 * @param string $html Escaped HTML.
	 * @param bool   $warn Warning.
	 * @return string
	 */
	private static function state( $html, $warn = false ) {
		return '<p class="xr-state' . ( $warn ? ' xr-state--warn' : '' ) . '"><span>' . $html . '</span></p>';
	}

	/**
	 * A percentage as printed ("52 %").
	 *
	 * @param float $value Value.
	 * @return string
	 */
	public static function pct( $value ) {
		return number_format_i18n( round( (float) $value ) ) . ' %';
	}

	/**
	 * A length in seconds: "45 s" under one minute, else "2 min 13 s".
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	public static function secs( $seconds ) {
		$seconds = max( 0, (int) round( (float) $seconds ) );
		if ( $seconds < 60 ) {
			/* translators: %s: seconds. */
			return sprintf( __( '%s s', 'opti-behavior' ), number_format_i18n( $seconds ) );
		}

		/* translators: 1: minutes, 2: seconds. */
		return sprintf( __( '%1$s min %2$s s', 'opti-behavior' ), number_format_i18n( intdiv( $seconds, 60 ) ), number_format_i18n( $seconds % 60 ) );
	}

	/**
	 * A measured amount with its currency ("€ 1,240", else "1,240 EUR").
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO code.
	 * @return string
	 */
	public static function money( $amount, $currency ) {
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		$number   = number_format_i18n( round( (float) $amount ) );
		if ( '' !== $currency && function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = html_entity_decode( (string) get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' );
			if ( '' !== $symbol ) {
				return $symbol . "\xC2\xA0" . $number;
			}
		}

		return trim( $number . ' ' . $currency );
	}

	/**
	 * A page title without the site name WordPress adds at its end.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function strip_site( $title ) {
		$title = (string) $title;
		$site  = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' === $site ) {
			return $title;
		}
		foreach ( array( ' – ', ' — ', ' - ', ' | ' ) as $sep ) {
			$tail = $sep . $site;
			if ( strlen( $title ) > strlen( $tail ) && substr( $title, -strlen( $tail ) ) === $tail ) {
				return substr( $title, 0, -strlen( $tail ) );
			}
		}

		return $title;
	}

	/**
	 * "16 Aug – 14 Sep 2026", or both years when they differ.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return string
	 */
	public static function range_text( $from, $to ) {
		$a = strtotime( (string) $from . ' 00:00:00' );
		$b = strtotime( (string) $to . ' 00:00:00' );
		if ( ! $a || ! $b ) {
			return '';
		}

		return date_i18n( gmdate( 'Y', $a ) === gmdate( 'Y', $b ) ? 'j M' : 'j M Y', $a ) . ' – ' . date_i18n( 'j M Y', $b );
	}

	/**
	 * A day as "14 Oct 2026".
	 *
	 * @param string $day Y-m-d.
	 * @return string
	 */
	public static function day_long( $day ) {
		$t = strtotime( (string) $day . ' 00:00:00' );

		return $t ? date_i18n( 'j M Y', $t ) : '';
	}

	/**
	 * Days of a period, both ends counted.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int
	 */
	public static function days_between( $from, $to ) {
		$a = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $from, new DateTimeZone( 'UTC' ) );
		$b = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $to, new DateTimeZone( 'UTC' ) );

		return ( $a && $b ) ? (int) $a->diff( $b )->days + 1 : 0;
	}

	/**
	 * Day + N days.
	 *
	 * @param string $day  Y-m-d.
	 * @param int    $days Days to add (negative = back).
	 * @return string
	 */
	public static function shift_day( $day, $days ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $day, new DateTimeZone( 'UTC' ) );

		return $date ? $date->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' ) : (string) $day;
	}
}
