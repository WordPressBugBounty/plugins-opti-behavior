<?php
/**
 * Report signals: one short block per signal of the admin menu (OPT-1592 / OPT-1596).
 *
 * Read by the scheduled report e-mail (short version). Nothing is computed
 * here: Heatmaps and Funnels = the report generator's get_heatmap_summary() /
 * get_funnel_data() (same rows as the Heatmaps and Funnels screens), Smart
 * Insights = issues_total of the site report summary (same as the PDF).
 *
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report signals.
 */
class Opti_Behavior_Report_Signals {

	/**
	 * Signals in the order of the admin menu (Traffic is printed before).
	 */
	const ORDER = array( 'heatmaps', 'recordings', 'funnels', 'shop', 'user_journeys', 'errors', 'forms', 'smart_insights', 'ab_testing' );

	/**
	 * Pro signals shown locked (one line, no figure) in Free.
	 */
	const LOCKED_IN_FREE = array( 'recordings', 'user_journeys', 'errors', 'forms' );

	/**
	 * Block states.
	 */
	const STATES = array( 'data', 'locked', 'empty_setup' );

	/**
	 * Figures per block, at most.
	 */
	const MAX_FIGURES = 3;

	/**
	 * Blocks of the period, in menu order. A signal without data = no block.
	 *
	 * @param string $from         First day (Y-m-d).
	 * @param string $to           Last day (Y-m-d).
	 * @param bool   $exclude_spam Spam excluded (screen links carry it).
	 * @param bool   $is_pro       Pro features available.
	 * @param array  $sources      Optional data already read: { heatmap, funnels, site, ab_tests }.
	 * @return array[] { id, title, tier free|pro, figures [ { label, value } ], sentence, url, state data|locked|empty_setup }
	 */
	public static function blocks( $from, $to, $exclude_spam = true, $is_pro = false, $sources = array() ) {
		$from    = self::clean_day( $from );
		$to      = self::clean_day( $to );
		$sources = is_array( $sources ) ? $sources : array();
		$args    = array(
			'from'         => $from,
			'to'           => $to,
			'exclude_spam' => (bool) $exclude_spam,
			'is_pro'       => (bool) $is_pro,
		);
		if ( '' === $from || '' === $to ) {
			return array();
		}

		if ( ! isset( $sources['heatmap'], $sources['funnels'] ) && class_exists( 'Opti_Behavior_Report_Generator' ) ) {
			try {
				$generator = new Opti_Behavior_Report_Generator( null );
				$sources  += $generator->signal_sources( $from, $to );
			} catch ( Throwable $e ) {
				unset( $generator );
			}
		}

		$blocks = array();
		foreach ( array( 'heatmaps', 'funnels', 'smart_insights', 'ab_testing' ) as $id ) {
			try {
				$block = call_user_func( array( __CLASS__, 'block_' . $id ), $sources, $args );
			} catch ( Throwable $e ) {
				$block = null;
			}
			if ( $block ) {
				$blocks[ $id ] = $block;
			}
		}
		if ( ! $args['is_pro'] ) {
			foreach ( self::LOCKED_IN_FREE as $id ) {
				$blocks[ $id ] = self::locked_block( $id );
			}
		}

		/**
		 * Blocks of the report signals (OPT-1596). Pro fills its own signals.
		 *
		 * @param array $blocks Blocks keyed by signal id (see blocks()).
		 * @param array $args   { from, to, exclude_spam, is_pro }.
		 */
		try {
			$filtered = apply_filters( 'opti_behavior_report_signals', $blocks, $args );
		} catch ( Throwable $e ) {
			$filtered = $blocks;
		}

		return self::validate( is_array( $filtered ) ? $filtered : $blocks, $args );
	}

	/**
	 * Clean, order and cap the blocks (a provider can never break the e-mail).
	 *
	 * @param array $blocks Blocks.
	 * @param array $args   { is_pro }.
	 * @return array[]
	 */
	public static function validate( $blocks, $args ) {
		$by_id = array();
		foreach ( (array) $blocks as $key => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$id = isset( $block['id'] ) ? sanitize_key( (string) $block['id'] ) : sanitize_key( (string) $key );
			if ( ! in_array( $id, self::ORDER, true ) ) {
				continue;
			}
			$state = isset( $block['state'] ) ? (string) $block['state'] : 'data';
			if ( ! in_array( $state, self::STATES, true ) ) {
				continue;
			}
			$tier = isset( $block['tier'] ) && 'pro' === $block['tier'] ? 'pro' : 'free';
			// A Pro signal never shows figures without Pro.
			if ( 'pro' === $tier && 'data' === $state && empty( $args['is_pro'] ) ) {
				continue;
			}
			$figures = array();
			if ( 'data' === $state && ! empty( $block['figures'] ) && is_array( $block['figures'] ) ) {
				foreach ( $block['figures'] as $figure ) {
					if ( ! is_array( $figure ) || ! isset( $figure['label'], $figure['value'] ) || '' === (string) $figure['value'] ) {
						continue;
					}
					$figures[] = array(
						'label' => sanitize_text_field( (string) $figure['label'] ),
						'value' => sanitize_text_field( (string) $figure['value'] ),
					);
					if ( count( $figures ) >= self::MAX_FIGURES ) {
						break;
					}
				}
			}
			$url           = isset( $block['url'] ) ? esc_url_raw( (string) $block['url'] ) : '';
			$by_id[ $id ] = array(
				'id'       => $id,
				'title'    => isset( $block['title'] ) ? sanitize_text_field( (string) $block['title'] ) : '',
				'tier'     => $tier,
				'figures'  => $figures,
				'sentence' => isset( $block['sentence'] ) ? sanitize_text_field( (string) $block['sentence'] ) : '',
				'url'      => $url,
				'state'    => $state,
			);
		}

		$out = array();
		foreach ( self::ORDER as $id ) {
			if ( isset( $by_id[ $id ] ) && '' !== $by_id[ $id ]['title'] ) {
				$out[] = $by_id[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Heatmaps: clicks of the period + most clicked page and element (OPT-1515 rows).
	 *
	 * @param array $sources { heatmap }.
	 * @param array $args    Args.
	 * @return array|null
	 */
	private static function block_heatmaps( $sources, $args ) {
		$hm    = isset( $sources['heatmap'] ) && is_array( $sources['heatmap'] ) ? $sources['heatmap'] : array();
		$total = isset( $hm['total_clicks'] ) ? (int) $hm['total_clicks'] : 0;
		if ( $total <= 0 ) {
			return null;
		}
		$top     = ! empty( $hm['top_clicked'][0] ) && is_array( $hm['top_clicked'][0] ) ? $hm['top_clicked'][0] : array();
		$page    = $top ? self::page_label( $top['title'] ?? '', $top['url'] ?? '' ) : '';
		$element = $top ? self::safe_text( (string) ( $top['element'] ?? '' ) ) : '';
		$page_id = $top ? (int) ( $top['page_id'] ?? 0 ) : 0;

		$figures = array(
			array(
				'label' => __( 'Clicks', 'opti-behavior' ),
				'value' => number_format_i18n( $total ),
			),
		);
		if ( '' !== $page ) {
			$figures[] = array(
				'label' => __( 'Most clicked page', 'opti-behavior' ),
				'value' => $page,
			);
		}
		if ( '' !== $element ) {
			$figures[] = array(
				'label' => __( 'Most clicked element', 'opti-behavior' ),
				'value' => $element,
			);
		}

		if ( '' !== $element && '' !== $page ) {
			/* translators: 1: clicked element, 2: page title. */
			$sentence = sprintf( __( 'Most clicks landed on %1$s on %2$s: check it is the action you expect.', 'opti-behavior' ), $element, $page );
		} elseif ( '' !== $page ) {
			/* translators: %s: page title. */
			$sentence = sprintf( __( 'Most clicks landed on %s: open its heatmap to see what visitors click.', 'opti-behavior' ), $page );
		} else {
			$sentence = '';
		}

		$url = $page_id > 0
			? self::admin_link(
				array(
					'page'    => 'opti-behavior-heatmap-detail',
					'page_id' => $page_id,
					'type'    => 'click',
					'device'  => 'desktop',
				),
				$args,
				'date_range'
			)
			: self::admin_link( array( 'page' => 'opti-behavior-heatmaps' ), $args, '' );

		return array(
			'id'       => 'heatmaps',
			'title'    => __( 'Heatmaps', 'opti-behavior' ),
			'tier'     => 'free',
			'figures'  => $figures,
			'sentence' => $sentence,
			'url'      => $url,
			'state'    => 'data',
		);
	}

	/**
	 * Funnels: the most used active funnel of the period; no active funnel = setup line.
	 *
	 * @param array $sources { funnels }.
	 * @param array $args    Args.
	 * @return array|null
	 */
	private static function block_funnels( $sources, $args ) {
		$data = isset( $sources['funnels'] ) && is_array( $sources['funnels'] ) ? $sources['funnels'] : null;
		if ( null === $data ) {
			return null;
		}
		$title = __( 'Funnels', 'opti-behavior' );
		if ( (int) ( $data['total_funnels'] ?? 0 ) <= 0 ) {
			return array(
				'id'       => 'funnels',
				'title'    => $title,
				'tier'     => 'free',
				'figures'  => array(),
				'sentence' => __( 'Create your first funnel to see where visitors stop.', 'opti-behavior' ),
				'url'      => admin_url( 'admin.php?page=opti-behavior-funnels' ),
				'state'    => 'empty_setup',
			);
		}
		$top = ! empty( $data['funnels'][0] ) && is_array( $data['funnels'][0] ) ? $data['funnels'][0] : array();
		if ( ! $top || (int) ( $top['total_entries'] ?? 0 ) <= 0 ) {
			return null;
		}
		$entries = (int) $top['total_entries'];
		$done    = (int) ( $top['completions'] ?? 0 );
		$rate    = (float) ( $top['conversion_rate'] ?? 0 );
		$name    = self::safe_text( (string) ( $top['name'] ?? '' ) );
		$rate_tx = number_format_i18n( $rate, ( floor( $rate ) === $rate ) ? 0 : 1 );

		return array(
			'id'       => 'funnels',
			'title'    => $title,
			'tier'     => 'free',
			'figures'  => array(
				array(
					'label' => __( 'Entries', 'opti-behavior' ),
					'value' => number_format_i18n( $entries ),
				),
				array(
					'label' => __( 'Completions', 'opti-behavior' ),
					'value' => number_format_i18n( $done ),
				),
				array(
					'label' => __( 'Completion rate', 'opti-behavior' ),
					'value' => $rate_tx . ' %',
				),
			),
			'sentence' => '' !== $name
				/* translators: 1: funnel name, 2: completion rate (number). */
				? sprintf( __( '%1$s: %2$s %% complete it. Look at the step where most visitors stop.', 'opti-behavior' ), $name, $rate_tx )
				: '',
			'url'      => self::admin_link(
				array(
					'page'      => 'opti-behavior-funnels',
					'funnel_id' => (int) ( $top['id'] ?? 0 ),
				),
				$args,
				'date_range'
			),
			'state'    => 'data',
		);
	}

	/**
	 * Smart Insights: open issues of the period = issues_total of the PDF site report.
	 *
	 * @param array $sources { site }.
	 * @param array $args    Args.
	 * @return array|null
	 */
	private static function block_smart_insights( $sources, $args ) {
		$site = isset( $sources['site'] ) && is_array( $sources['site'] ) ? $sources['site'] : null;
		if ( null === $site && class_exists( 'Opti_Behavior_Report_Site' ) && method_exists( 'Opti_Behavior_Report_Site', 'email_summary' ) ) {
			$site = Opti_Behavior_Report_Site::email_summary( Opti_Behavior_Report_Site::email_context( $args['from'], $args['to'], $args['exclude_spam'] ) );
		}
		$n = is_array( $site ) ? (int) ( $site['issues_total'] ?? 0 ) : 0;
		if ( $n <= 0 ) {
			return null;
		}
		return array(
			'id'       => 'smart_insights',
			'title'    => __( 'Smart Insights', 'opti-behavior' ),
			'tier'     => 'free',
			'figures'  => array(
				array(
					'label' => __( 'Open insights', 'opti-behavior' ),
					'value' => number_format_i18n( $n ),
				),
			),
			/* translators: %s: number of insights. */
			'sentence' => sprintf( _n( '%s insight this period.', '%s insights this period.', $n, 'opti-behavior' ), number_format_i18n( $n ) ),
			'url'      => self::admin_link( array( 'page' => 'opti-behavior-smart-insights' ), $args, 'period' ),
			'state'    => 'data',
		);
	}

	/**
	 * A/B Testing (OPT-1650): tests running now (started by the last day) and
	 * tests ended during the period, stored winner of the latest ended one.
	 * Nothing computed: the report generator's get_ab_test_rows() (status,
	 * ended_at, winner of the A/B Testing screen rows). No test = no block
	 * (never "0").
	 *
	 * @param array $sources { ab_tests }.
	 * @param array $args    Args.
	 * @return array|null
	 */
	private static function block_ab_testing( $sources, $args ) {
		$rows = isset( $sources['ab_tests'] ) && is_array( $sources['ab_tests'] ) ? $sources['ab_tests'] : array();
		$running = 0;
		$ended   = 0;
		$won     = null;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row += array(
				'name'   => '',
				'status' => '',
				'winner' => '',
			);
			if ( 'running' === $row['status'] ) {
				++$running;
				continue;
			}
			++$ended;
			if ( null === $won && '' !== self::safe_text( (string) $row['winner'] ) && '' !== self::safe_text( (string) $row['name'] ) ) {
				$won = $row;
			}
		}
		if ( 0 === $running && 0 === $ended ) {
			return null;
		}

		$figures = array(
			array(
				'label' => __( 'Running tests', 'opti-behavior' ),
				'value' => number_format_i18n( $running ),
			),
			array(
				'label' => __( 'Ended this period', 'opti-behavior' ),
				'value' => number_format_i18n( $ended ),
			),
		);
		if ( $won ) {
			$test    = self::page_label( $won['name'], '' );
			$variant = self::page_label( $won['winner'], '' );
			$figures[] = array(
				'label' => __( 'Winner', 'opti-behavior' ),
				/* translators: 1: variant name, 2: A/B test name. */
				'value' => sprintf( __( '%1$s (%2$s)', 'opti-behavior' ), $variant, $test ),
			);
			$sentence = 'applied' === $won['status']
				/* translators: 1: variant name, 2: A/B test name. */
				? sprintf( __( '%1$s won %2$s and is applied: watch the page for a week.', 'opti-behavior' ), $variant, $test )
				/* translators: 1: variant name, 2: A/B test name. */
				: sprintf( __( '%1$s won %2$s: apply it from the A/B Testing screen.', 'opti-behavior' ), $variant, $test );
		} elseif ( $running > 0 ) {
			/* translators: %s: number of running A/B tests. */
			$sentence = sprintf( _n( '%s test is running: wait for enough visits before you pick a winner.', '%s tests are running: wait for enough visits before you pick a winner.', $running, 'opti-behavior' ), number_format_i18n( $running ) );
		} else {
			/* translators: %s: number of ended A/B tests. */
			$sentence = sprintf( _n( '%s test ended without a clear winner: keep the current page or test a bigger change.', '%s tests ended without a clear winner: keep the current page or test a bigger change.', $ended, 'opti-behavior' ), number_format_i18n( $ended ) );
		}

		return array(
			'id'       => 'ab_testing',
			'title'    => __( 'A/B Testing', 'opti-behavior' ),
			'tier'     => 'free',
			'figures'  => $figures,
			'sentence' => $sentence,
			'url'      => self::admin_link( array( 'page' => 'opti-behavior-ab-testing' ), $args, '' ),
			'state'    => 'data',
		);
	}

	/**
	 * One Pro signal in Free: one line, no figure, link to the trial page.
	 *
	 * @param string $id Signal id.
	 * @return array
	 */
	private static function locked_block( $id ) {
		$texts = array(
			'recordings'    => array( __( 'Recordings', 'opti-behavior' ), __( 'Pro adds session recordings: watch real visits on the pages that matter.', 'opti-behavior' ) ),
			'user_journeys' => array( __( 'User Journeys', 'opti-behavior' ), __( 'Pro adds user journeys: see the paths visitors take and where they leave.', 'opti-behavior' ) ),
			'errors'        => array( __( 'Errors Tracking', 'opti-behavior' ), __( 'Pro adds error tracking: JavaScript errors, broken links and slow pages.', 'opti-behavior' ) ),
			'forms'         => array( __( 'Forms', 'opti-behavior' ), __( 'Pro adds form analytics: see the field where visitors give up.', 'opti-behavior' ) ),
		);
		return array(
			'id'       => $id,
			'title'    => $texts[ $id ][0],
			'tier'     => 'pro',
			'figures'  => array(),
			'sentence' => $texts[ $id ][1],
			'url'      => self::trial_url( $id ),
			'state'    => 'locked',
		);
	}

	/**
	 * Trial page link of a locked block (UTM: source / medium / signal).
	 * OPT-2560: `report_pdf` = PDF site report (medium pdf); anything else = the e-mail.
	 *
	 * @param string $signal Signal id.
	 * @param string $source 'report_email' (default) or 'report_pdf'.
	 * @return string
	 */
	public static function trial_url( $signal, $source = 'report_email' ) {
		$base = class_exists( 'Opti_Behavior_Trial_Invite' ) ? Opti_Behavior_Trial_Invite::TRIAL_PAGE_URL : 'https://optiuser.com/opti-behavior/pro-trial/';
		$pdf  = 'report_pdf' === $source;
		return add_query_arg(
			array(
				'site'         => rawurlencode( home_url() ),
				'utm_source'   => $pdf ? 'report_pdf' : 'report_email',
				'utm_medium'   => $pdf ? 'pdf' : 'email',
				'utm_campaign' => sanitize_key( (string) $signal ),
			),
			$base
		);
	}

	/**
	 * Admin screen link for the report days (never a UTM).
	 *
	 * @param array  $query     Base query.
	 * @param array  $args      { from, to, exclude_spam }.
	 * @param string $range_key 'date_range' / 'period' = custom range key, '' = no dates.
	 * @return string
	 */
	private static function admin_link( $query, $args, $range_key ) {
		if ( '' !== $range_key ) {
			$query[ $range_key ] = 'custom';
			$query['start_date'] = $args['from'];
			$query['end_date']   = $args['to'];
		}
		// Always said: without it the screen falls back to the viewer's stored
		// spam toggle and may show other figures than the e-mail (QA OPT-1664).
		$query['exclude_spam'] = empty( $args['exclude_spam'] ) ? '0' : '1';
		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * Page title (site suffix kept off), else its path; never a full address.
	 *
	 * @param string $title Title.
	 * @param string $url   Stored URL.
	 * @return string
	 */
	private static function page_label( $title, $url ) {
		$title = self::safe_text( (string) $title );
		if ( '' !== $title && class_exists( 'Opti_Behavior_Smart_Insights_Recommendations' ) ) {
			$title = Opti_Behavior_Smart_Insights_Recommendations::strip_site_suffix( $title );
		}
		if ( '' !== $title && ! preg_match( '#^https?://#i', $title ) ) {
			return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $title, 0, 60, '…', 'UTF-8' ) : substr( $title, 0, 60 );
		}
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return self::safe_text( '' !== $path ? $path : '/' );
	}

	/**
	 * Plain text without e-mail address or long number (same rule as the click block).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function safe_text( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
		if ( false !== strpos( $text, '@' ) || preg_match( '/\d{6,}/', $text ) ) {
			return '';
		}
		return $text;
	}

	/**
	 * Y-m-d or ''.
	 *
	 * @param string $day Day.
	 * @return string
	 */
	private static function clean_day( $day ) {
		$day = (string) $day;
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? $day : '';
	}
}
