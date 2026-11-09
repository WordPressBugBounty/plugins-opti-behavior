<?php
/**
 * Plain Text Email Template for Scheduled Reports
 *
 * Available variables:
 * - $report: array - Report data from generator
 * - $schedule: array - Schedule configuration
 *
 * @package opti-behavior
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$kpis          = $report['sections']['kpis'] ?? array();
$top_pages     = $report['sections']['top_pages'] ?? array();
$top_referrers = $report['sections']['top_referrers'] ?? array();
$traffic       = $report['sections']['traffic_breakdown'] ?? array();
$geographic    = $report['sections']['geographic'] ?? array();
$heatmap       = $report['sections']['heatmap_summary'] ?? array();
$funnels       = $report['sections']['funnels'] ?? array();
$smart_insights = $report['sections']['smart_insights'] ?? array();
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Pro sections
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$recordings     = $report['sections']['recordings'] ?? array();
$errors         = $report['sections']['errors'] ?? array();
$friction       = $report['sections']['friction'] ?? array();
$performance    = $report['sections']['performance'] ?? array();
$broken_links   = $report['sections']['broken_links'] ?? array();
$user_journeys  = $report['sections']['user_journeys'] ?? array();
$form_analytics = $report['sections']['form_analytics'] ?? array();
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// One "What it means / What to do" line per block, from the figures of that block only (OPT-1648).
$opti_behavior_hint_ctx = array(
	'days'      => Opti_Behavior_Report_Mailer::period_days( $report ),
	'pageviews' => (int) ( $kpis['pageviews']['value'] ?? 0 ),
);

// Header
echo esc_html( strtoupper( $schedule['name'] ) ) . "\n";
echo esc_html( str_repeat( '=', strlen( $schedule['name'] ) ) ) . "\n\n";
echo esc_html( $report['period_label'] ) . ' | ' . esc_html( $report['site_name'] ) . "\n\n";

// Full e-mail (OPT-1647, design OPT-1646): the 3 issues to look at first of the PDF site report first, then every block.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$opti_behavior_s = ( ! empty( $report['site'] ) && is_array( $report['site'] ) ) ? $report['site'] : null;
if ( $opti_behavior_s && $opti_behavior_s['visits'] <= 0 ) {
	/* translators: 1: first day, 2: last day of the period. */
	echo esc_html( sprintf( __( 'No visit recorded between %1$s and %2$s.', 'opti-behavior' ), Opti_Behavior_Report_Site::day_long( $opti_behavior_s['from'] ), Opti_Behavior_Report_Site::day_long( $opti_behavior_s['to'] ) ) ) . "\n\n";
} elseif ( $opti_behavior_s ) {
	echo esc_html( strtoupper( __( 'The 3 issues to look at first', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 30 ) ) . "\n";
	if ( ! empty( $opti_behavior_s['issues_period'] ) ) {
		echo esc_html( $opti_behavior_s['issues_period'] ) . "\n";
	}
	if ( empty( $opti_behavior_s['issues'] ) ) {
		echo esc_html__( 'No costly issue detected for this period.', 'opti-behavior' ) . "\n";
	}
	foreach ( $opti_behavior_s['issues'] as $opti_behavior_it ) {
		echo esc_html( $opti_behavior_it['rank'] . '. [' . $opti_behavior_it['word'] . '] ' . $opti_behavior_it['title'] ) . "\n";
		if ( '' !== $opti_behavior_it['headline'] ) {
			echo '   ' . esc_html( $opti_behavior_it['headline'] ) . "\n";
		}
		$opti_behavior_parts = array();
		// OB-07: what the figure counts (Report_Site::issue_unit()), never "lost".
		$opti_behavior_unit = isset( $opti_behavior_it['unit'] ) ? (string) $opti_behavior_it['unit'] : '';
		if ( '' === $opti_behavior_unit && null !== $opti_behavior_it['lost'] ) {
			$opti_behavior_unit = _n( 'visit concerned', 'visits concerned', $opti_behavior_it['lost'], 'opti-behavior' );
		}
		if ( '' !== $opti_behavior_it['money'] ) {
			$opti_behavior_parts[] = $opti_behavior_it['money'] . ' ' . __( 'estimated loss', 'opti-behavior' );
		}
		if ( null !== $opti_behavior_it['lost'] && null !== $opti_behavior_it['share'] ) {
			$opti_behavior_parts[] = number_format_i18n( $opti_behavior_it['lost'] ) . ' ' . $opti_behavior_unit;
			$opti_behavior_parts[] = number_format_i18n( $opti_behavior_it['share'] ) . ' % ' . ( empty( $opti_behavior_it['own'] ) ? __( 'of all visits', 'opti-behavior' ) : __( 'of the visits of those days', 'opti-behavior' ) );
		} elseif ( null !== $opti_behavior_it['lost'] ) {
			// OPT-1940: estimated from an average gap, never a share of the visits.
			$opti_behavior_parts[] = '~' . number_format_i18n( $opti_behavior_it['lost'] ) . ' ' . $opti_behavior_unit;
		}
		if ( $opti_behavior_parts ) {
			echo '   ' . esc_html( implode( ' · ', $opti_behavior_parts ) ) . "\n";
		}
		echo '   ' . esc_html( __( 'Where', 'opti-behavior' ) . ': ' . $opti_behavior_it['where'] ) . "\n";
		if ( '' !== $opti_behavior_it['action'] ) {
			echo '   ' . esc_html( __( 'What to do', 'opti-behavior' ) . ': ' . $opti_behavior_it['action'] ) . "\n";
		}
	}
	$opti_behavior_n = count( $opti_behavior_s['issues'] );
	// Same rule as the PDF: "Only N" only when nothing else is open (Free alone leaves locked Pro cards out).
	if ( $opti_behavior_n > 0 && $opti_behavior_n < Opti_Behavior_Report_Site::TOP_ISSUES && $opti_behavior_s['issues_total'] <= $opti_behavior_n ) {
		/* translators: %s: number of issues. */
		echo esc_html( sprintf( _n( 'Only %s open issue over this period.', 'Only %s open issues over this period.', $opti_behavior_n, 'opti-behavior' ), number_format_i18n( $opti_behavior_n ) ) ) . "\n";
	} elseif ( $opti_behavior_n > 0 && $opti_behavior_s['issues_total'] > $opti_behavior_n ) {
		$opti_behavior_left = $opti_behavior_s['issues_total'] - $opti_behavior_n;
		/* translators: %s: number of issues. */
		echo esc_html( sprintf( _n( '%s other open issue in Smart Insights, with a smaller cost.', '%s other open issues in Smart Insights, with a smaller cost.', $opti_behavior_left, 'opti-behavior' ), number_format_i18n( $opti_behavior_left ) ) ) . "\n";
	}
	echo "\n";
}
// OPT-2860: one AI line (which AI companies read the site, pages read, visitors they sent).
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if ( ! empty( $opti_behavior_s['ai_line'] ) ) {
	echo esc_html__( 'AI', 'opti-behavior' ) . ': ' . esc_html( $opti_behavior_s['ai_line'] ) . "\n\n";
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Key figures first, then Smart Insights (design OPT-1646; the costly issues above already say what to fix).
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$opti_behavior_si_first = false;
ob_start();

// KPIs Section
if ( ! empty( $kpis ) ) {
	echo esc_html( strtoupper( __( 'Key Performance Indicators', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 28 ) ) . "\n";
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$opti_behavior_compare_days = Opti_Behavior_Report_Mailer::period_days( $report );
	if ( $opti_behavior_compare_days > 0 ) {
		echo esc_html(
			sprintf(
				/* translators: %s: number of days of the report period */
				_n( 'vs previous %s day', 'vs previous %s days', $opti_behavior_compare_days, 'opti-behavior' ),
				number_format_i18n( $opti_behavior_compare_days )
			)
		) . "\n";
	}
	echo "\n";

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$kpi_labels = array(
		'visitors'         => __( 'Visitors', 'opti-behavior' ),
		'sessions'         => __( 'Sessions', 'opti-behavior' ),
		'pageviews'        => __( 'Page Views', 'opti-behavior' ),
		'bounce_rate'      => __( 'Bounce Rate', 'opti-behavior' ),
		'avg_session_time' => __( 'Avg. Session Time', 'opti-behavior' ),
		'avg_scroll_depth' => __( 'Avg. Scroll Depth', 'opti-behavior' ),
	);

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	foreach ( $kpi_labels as $key => $label ) {
		if ( ! isset( $kpis[ $key ] ) ) {
			continue;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$kpi = $kpis[ $key ];

		// Format value
		if ( 'avg_session_time' === $key && isset( $kpi['formatted'] ) ) {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			$value = $kpi['formatted'];
		} elseif ( in_array( $key, array( 'bounce_rate', 'avg_scroll_depth' ), true ) ) {
			$value = $kpi['value'] . '%';
		} else {
			$value = number_format( $kpi['value'] );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		}

		// Change indicator
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$change_str = '';
		if ( 'new' === ( $kpi['change']['direction'] ?? '' ) ) {
			$change_str = ' (' . __( 'new', 'opti-behavior' ) . ')';
		} elseif ( ! empty( $kpi['change']['value'] ) ) {
			$arrow      = ( $kpi['change']['direction'] ?? '' ) === 'up' ? '^' : 'v';
			$change_str = ' (' . $arrow . ' ' . Opti_Behavior_Report_Generator::change_amount_text( $kpi['change'] ) . ')';
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		}

		printf( "  %-20s %s%s\n", esc_html( $label ) . ':', esc_html( $value ), esc_html( $change_str ) );
	}
	if ( 'anonymous' === ( $report['privacy_mode'] ?? '' ) ) {
		echo "\n  " . esc_html__( 'Cookieless mode: a returning visitor is counted once per day.', 'opti-behavior' ) . "\n";
	}
	echo esc_html( Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'kpis', $kpis, $opti_behavior_hint_ctx ) ) );
	echo "\n";
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$opti_behavior_kpi_text = ob_get_clean();
ob_start();

// Smart Insights Section ("What to fix this week")
if ( ! empty( $smart_insights ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$opti_behavior_si_title = __( 'What to fix this week', 'opti-behavior' );
	echo esc_html( mb_strtoupper( $opti_behavior_si_title ) ) . "\n";
	echo esc_html( str_repeat( '-', max( 3, mb_strlen( $opti_behavior_si_title ) ) ) ) . "\n\n";
	if ( $opti_behavior_si_first ) {
		printf(
			"  %s\n\n",
			esc_html(
				sprintf(
					/* translators: %d: insight count */
					__( '%d active insight(s) detected for this report period.', 'opti-behavior' ),
					(int) ( $smart_insights['count'] ?? 0 )
				)
			)
		);
	}

	if ( ! empty( $smart_insights['insights'] ) ) {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $smart_insights['insights'], 0, 3 ) as $i => $insight ) {
			$title      = $insight['title'] ?? __( 'Smart Insight', 'opti-behavior' );
			$entity     = ! empty( $insight['entity_label'] ) ? ' — ' . $insight['entity_label'] : '';
			$priority   = $insight['priority'] ?? __( 'Normal', 'opti-behavior' );
			$confidence = ! empty( $insight['confidence'] ) ? ' | Confidence: ' . $insight['confidence'] . '%' : '';
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

			printf( "  %d. %s%s\n", intval( $i + 1 ), esc_html( $title ), esc_html( $entity ) );
			printf( "     Priority: %s%s\n", esc_html( $priority ), esc_html( $confidence ) );
			if ( ! empty( $insight['interpretation'] ) ) {
				printf( "     %s\n", esc_html( $insight['interpretation'] ) );
			}
			if ( ! empty( $insight['evidence'] ) ) {
				printf( "     Evidence: %s\n", esc_html( implode( ' | ', array_slice( $insight['evidence'], 0, 3 ) ) ) );
			}
			if ( ! empty( $insight['recommended_action'] ) ) {
				printf( "     Next action: %s\n", esc_html( $insight['recommended_action'] ) );
			}
			echo "\n";
		}
	} else {
		echo '  ' . esc_html( $smart_insights['empty_message'] ?? __( 'No active Smart Insights detected for this report period.', 'opti-behavior' ) ) . "\n\n";
	}
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$opti_behavior_si_text = ob_get_clean();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both blocks were escaped while buffered.
echo $opti_behavior_si_first ? $opti_behavior_si_text . $opti_behavior_kpi_text : $opti_behavior_kpi_text . $opti_behavior_si_text;

// Top Pages Section
if ( ! empty( $top_pages ) ) {
	echo esc_html( strtoupper( __( 'Top Pages', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 10 ) ) . "\n\n";

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	foreach ( array_slice( $top_pages, 0, 5 ) as $i => $page ) {
		$title = mb_strimwidth( $page['title'], 0, 50, '...' );
		$views = number_format( $page['views'] );
		$time  = $page['avg_time_formatted'] ?? '-';
		// Visits of the page, same figure as the HTML table (OPT-2488).
		$visits = isset( $page['visits'] ) && null !== $page['visits'] ? number_format( (int) $page['visits'] ) : '-';
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		printf( "  %d. %s\n", intval( $i + 1 ), esc_html( $title ) );
		printf(
			/* translators: 1: view count, 2: average time on page */
			'     ' . esc_html( __( 'Visits', 'opti-behavior' ) . ': ' . $visits . ' | ' ) . esc_html__( 'Views: %1$s | Avg. Time: %2$s', 'opti-behavior' ) . "\n\n",
			esc_html( $views ),
			esc_html( $time )
		);
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$opti_behavior_hint = Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'top_pages', $top_pages, $opti_behavior_hint_ctx ) );
	if ( '' !== $opti_behavior_hint ) {
		echo esc_html( $opti_behavior_hint ) . "\n";
	}
}

// Sources right after the top pages, then countries (design OPT-1646).
// Top Referrers Section
if ( ! empty( $top_referrers ) ) {
	echo esc_html( strtoupper( __( 'Top Referrers', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 13 ) ) . "\n\n";

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	foreach ( array_slice( $top_referrers, 0, 5 ) as $i => $ref ) {
		$source = $ref['source'];
		$visits = number_format( $ref['visits'] );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		printf(
			/* translators: 1: rank number, 2: referrer source, 3: visit count */
			esc_html__( '  %1$d. %2$-30s %3$s visits', 'opti-behavior' ),
			intval( $i + 1 ),
			esc_html( $source ),
			esc_html( $visits )
		);
		// Share of all visits of the period (OPT-1650).
		echo isset( $ref['share'] ) ? ' (' . esc_html( number_format_i18n( (int) $ref['share'] ) ) . "%)\n" : "\n";
	}
	echo "\n";
}

// Traffic Breakdown Section
if ( ! empty( $traffic ) && ! empty( $traffic['breakdown'] ) ) {
	echo esc_html( strtoupper( __( 'Traffic Breakdown', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 17 ) ) . "\n\n";

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	foreach ( array_slice( $traffic['breakdown'], 0, 6 ) as $item ) {
		$type  = ucfirst( $item['type'] ?? __( 'Unknown', 'opti-behavior' ) );
		$count = number_format( $item['count'] );
		$pct   = $item['percentage'] ?? 0;
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		printf( "  %-20s %s (%s%%)\n", esc_html( $type ) . ':', esc_html( $count ), esc_html( $pct ) );
	}
	// Devices in % (OPT-1650): the Traffic screen device rows, as the PDF prints them.
	$opti_behavior_devices = isset( $report['site']['devices'] ) && is_array( $report['site']['devices'] ) ? $report['site']['devices'] : array();
	if ( ! empty( $opti_behavior_devices ) ) {
		echo "\n  " . esc_html__( 'Device', 'opti-behavior' ) . ":\n";
		foreach ( array_slice( $opti_behavior_devices, 0, 4 ) as $opti_behavior_device ) {
			printf( "  %-20s %s (%s%%)\n", esc_html( (string) ( $opti_behavior_device['label'] ?? '' ) ) . ':', esc_html( number_format_i18n( (int) ( $opti_behavior_device['count'] ?? 0 ) ) ), esc_html( number_format_i18n( (int) ( $opti_behavior_device['share'] ?? 0 ) ) ) );
		}
	}
	echo "\n";
}
// Geographic Distribution Section
if ( ! empty( $geographic ) ) {
	echo esc_html( strtoupper( __( 'Geographic Distribution', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 23 ) ) . "\n\n";

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	foreach ( array_slice( $geographic, 0, 5 ) as $i => $country ) {
		$name     = $country['country_name'] ?? $country['country'] ?? __( 'Unknown', 'opti-behavior' );
		$visitors = number_format( $country['visitors'] );
		$sessions = number_format( $country['sessions'] );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		printf(
			/* translators: 1: rank number, 2: country name, 3: visitor count, 4: session count */
			esc_html__( '  %1$d. %2$-25s %3$s visitors, %4$s sessions', 'opti-behavior' ) . "\n",
			intval( $i + 1 ),
			esc_html( $name ),
			esc_html( $visitors ),
			esc_html( $sessions )
		);
	}
	echo "\n";
}

// Heatmap Summary Section
if ( ! empty( $heatmap ) ) {
	echo esc_html( strtoupper( __( 'Click Heatmap Summary', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 21 ) ) . "\n\n";

	// Most clicked pages, their most clicked element and their heatmap; total on one line under them (OPT-1515).
	if ( ! empty( $heatmap['top_clicked'] ) ) {
		echo '  ' . esc_html__( 'Most Clicked Pages:', 'opti-behavior' ) . "\n";
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $heatmap['top_clicked'], 0, 3 ) as $i => $clicked ) {
			$title  = mb_strimwidth( (string) $clicked['title'], 0, 40, '...' );
			$clicks = number_format( $clicked['clicks'] );
			printf(
				/* translators: 1: rank number, 2: page title, 3: click count */
				esc_html__( '    %1$d. %2$s (%3$s clicks)', 'opti-behavior' ) . "\n",
				intval( $i + 1 ),
				esc_html( $title ),
				esc_html( $clicks )
			);
			if ( ! empty( $clicked['element'] ) ) {
				/* translators: %s: short label of the most clicked element of the page, e.g. "Add to cart" */
				echo '       ' . esc_html( sprintf( __( 'Most clicked: %s', 'opti-behavior' ), $clicked['element'] ) ) . "\n";
			}
			if ( ! empty( $clicked['page_id'] ) ) {
				echo '       ' . esc_url( admin_url( 'admin.php?page=opti-behavior-heatmap-detail&page_id=' . (int) $clicked['page_id'] . '&type=click&device=desktop' ) ) . "\n";
			}
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		echo "\n";
	}

	/* translators: %s: total click count */
	printf( '  ' . esc_html__( 'Total Clicks: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $heatmap['total_clicks'] ?? 0 ) ) );
	echo esc_html( Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'heatmap_summary', $heatmap, $opti_behavior_hint_ctx ) ) );
	echo "\n";
}

// Funnel Performance Section
if ( ! empty( $funnels ) && ! empty( $funnels['total_funnels'] ) ) {
	echo esc_html( strtoupper( __( 'Funnel Performance', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 18 ) ) . "\n\n";

	/* translators: %s: number of active funnels */
	printf( '  ' . esc_html__( 'Active Funnels: %s', 'opti-behavior' ) . "\n\n", esc_html( $funnels['total_funnels'] ) );

	if ( ! empty( $funnels['funnels'] ) ) {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( $funnels['funnels'] as $i => $funnel ) {
			$name    = mb_strimwidth( $funnel['name'], 0, 35, '...' );
			$entries = number_format( $funnel['total_entries'] );
			$compl   = number_format( $funnel['completions'] );
			$rate    = number_format( $funnel['conversion_rate'] ?? 0, 1 ) . '%';
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

			printf( "  %d. %-35s\n", intval( $i + 1 ), esc_html( $name ) );
			printf(
				/* translators: 1: funnel entries, 2: funnel completions, 3: conversion rate */
				'     ' . esc_html__( 'Entries: %1$s | Completions: %2$s | Conv: %3$s', 'opti-behavior' ) . "\n\n",
				esc_html( $entries ),
				esc_html( $compl ),
				esc_html( $rate )
			);
		}
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$opti_behavior_hint = Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'funnels', $funnels, $opti_behavior_hint_ctx ) );
	if ( '' !== $opti_behavior_hint ) {
		echo esc_html( $opti_behavior_hint ) . "\n";
	}
}

// A/B Testing (Free, OPT-1650): the block of the report signals engine; no test = no block.
$opti_behavior_ab = Opti_Behavior_Report_Mailer::signal_block( $report, 'ab_testing' );
if ( $opti_behavior_ab && 'data' === $opti_behavior_ab['state'] && ! empty( $opti_behavior_ab['figures'] ) ) {
	echo esc_html( strtoupper( $opti_behavior_ab['title'] ) ) . "\n";
	echo esc_html( str_repeat( '-', 11 ) ) . "\n\n";
	foreach ( $opti_behavior_ab['figures'] as $opti_behavior_fig ) {
		echo '  ' . esc_html( $opti_behavior_fig['label'] ) . ': ' . esc_html( $opti_behavior_fig['value'] ) . "\n";
	}
	echo esc_html( Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'ab_testing', $opti_behavior_ab, $opti_behavior_hint_ctx ) ) );
	if ( ! empty( $opti_behavior_ab['url'] ) ) {
		echo '  ' . esc_url( $opti_behavior_ab['url'] ) . "\n";
	}
	echo "\n";
}

// Shop block (OPT-1735), after A/B Testing (design OPT-1645, QA OPT-1651): numbers of this period, Pro "Money at stake" or the Pro line.
if ( ! empty( $report['sections']['shop']['totals'] ) && class_exists( 'Opti_Behavior_Shop_Report' ) ) {
	$opti_behavior_shop = $report['sections']['shop'];
	echo esc_html( Opti_Behavior_Shop_Report::render_block_text( $opti_behavior_shop, $opti_behavior_shop['pro'] ?? null, ! empty( $opti_behavior_shop['pro_line'] ) ) );
}

// Free: each Pro block = its title + one line + the trial page, never a figure (OPT-1647, design OPT-1646).
if ( empty( $report['is_pro'] ) ) {
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$opti_behavior_locked = array(
		'recordings'    => __( 'Session Recordings', 'opti-behavior' ),
		'errors'        => __( 'JavaScript Errors', 'opti-behavior' ),
		'performance'   => __( 'Web Vitals', 'opti-behavior' ),
		'friction'      => __( 'Friction Events', 'opti-behavior' ),
		'broken_links'  => __( 'Broken Links', 'opti-behavior' ),
		'user_journeys' => __( 'User Journeys', 'opti-behavior' ),
		'forms'         => __( 'Form Analytics', 'opti-behavior' ),
	);
	foreach ( $opti_behavior_locked as $opti_behavior_lk => $opti_behavior_lt ) {
		echo esc_html( strtoupper( $opti_behavior_lt ) . ' (PRO)' ) . "\n";
		echo '  ' . esc_html__( 'Available with Opti-Behavior Pro', 'opti-behavior' ) . ' · ' . esc_html__( 'See the Pro trial', 'opti-behavior' ) . ': ' . esc_url( Opti_Behavior_Report_Signals::trial_url( $opti_behavior_lk ) ) . "\n\n";
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
}

// Session Recordings Stats (Pro)
if ( $report['is_pro'] && ! empty( $recordings ) ) {
	echo esc_html( strtoupper( __( 'Session Recordings (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 24 ) ) . "\n\n";

	/* translators: %s: total number of recordings */
	printf( '  ' . esc_html__( 'Total Recordings: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $recordings['total'] ) ) );
	/* translators: %s: number of watched recordings */
	printf( '  ' . esc_html__( 'Watched: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $recordings['watched'] ) ) );
	/* translators: %s: number of unwatched recordings */
	printf( '  ' . esc_html__( 'Unwatched: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $recordings['unwatched'] ) ) );
	/* translators: %s: average recording duration */
	printf( '  ' . esc_html__( 'Avg. Duration: %s', 'opti-behavior' ) . "\n", esc_html( $recordings['avg_duration_formatted'] ) );
	echo "\n";
}

// Errors Section (Pro)
if ( $report['is_pro'] && ! empty( $errors ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'JavaScript Errors (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 23 ) ) . "\n\n";

	/* translators: %s: total number of JavaScript errors */
	printf( '  ' . esc_html__( 'Total Errors: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $errors['total'] ) ) );
	/* translators: %s: number of unresolved errors */
	printf( '  ' . esc_html__( 'Unresolved: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $errors['unresolved'] ) ) );
	echo esc_html( Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'errors', $errors, $opti_behavior_hint_ctx ) ) );
	echo "\n";
}

// Performance Section (Pro)
if ( $report['is_pro'] && ! empty( $performance ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'Web Vitals (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 16 ) ) . "\n\n";

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$vitals = array(
		'lcp' => array( 'label' => __( 'LCP (Largest Contentful Paint):', 'opti-behavior' ), 'suffix' => 's' ),
		'fid' => array( 'label' => __( 'FID (First Input Delay):', 'opti-behavior' ), 'suffix' => 'ms' ),
		'cls' => array( 'label' => __( 'CLS (Cumulative Layout Shift):', 'opti-behavior' ), 'suffix' => '' ),
		'inp' => array( 'label' => __( 'INP (Interaction to Next Paint):', 'opti-behavior' ), 'suffix' => 'ms' ),
	);

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	foreach ( $vitals as $key => $config ) {
		if ( isset( $performance[ $key ] ) ) {
			$value = $performance[ $key ] . $config['suffix'];
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			printf( "  %-35s %s\n", esc_html( $config['label'] ), esc_html( $value ) );
		}
	}
	echo esc_html( Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'performance', $performance, $opti_behavior_hint_ctx ) ) );
	echo "\n";
}

// Friction Events Section (Pro)
if ( $report['is_pro'] && ! empty( $friction ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'Friction Events (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 21 ) ) . "\n\n";

	/* translators: %s: total number of friction events */
	printf( '  ' . esc_html__( 'Total Events: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $friction['total'] ) ) );
	/* translators: %s: number of rage clicks */
	printf( '  ' . esc_html__( 'Rage Clicks: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $friction['rage_clicks'] ) ) );
	/* translators: %s: number of dead clicks */
	printf( '  ' . esc_html__( 'Dead Clicks: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $friction['dead_clicks'] ) ) );
	echo "\n";
}

// Broken Links Section (Pro)
if ( $report['is_pro'] && ! empty( $broken_links ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'Broken Links Report (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 25 ) ) . "\n\n";

	/* translators: %s: total number of broken links found */
	printf( '  ' . esc_html__( 'Total Found: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $broken_links['total'] ) ) );
	/* translators: %s: number of open broken links */
	printf( '  ' . esc_html__( 'Open: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $broken_links['open'] ) ) );
	/* translators: %s: number of fixed broken links */
	printf( '  ' . esc_html__( 'Fixed: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $broken_links['fixed'] ) ) );

	if ( ! empty( $broken_links['top_links'] ) ) {
		echo "\n  " . esc_html__( 'Top Broken Links:', 'opti-behavior' ) . "\n";
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $broken_links['top_links'], 0, 3 ) as $i => $link ) {
			$url   = mb_strimwidth( $link['url'], 0, 50, '...' );
			$status = $link['http_status'];
			$hits  = number_format( $link['occurrence_count'] );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			printf(
				/* translators: 1: rank number, 2: broken URL, 3: HTTP status code, 4: occurrence count */
				esc_html__( '    %1$d. %2$s (HTTP %3$s, %4$s hits)', 'opti-behavior' ) . "\n",
				intval( $i + 1 ),
				esc_html( $url ),
				esc_html( $status ),
				esc_html( $hits )
			);
		}
	}
	echo esc_html( Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'broken_links', $broken_links, $opti_behavior_hint_ctx ) ) );
	echo "\n";
}

// User Journeys Section (Pro)
if ( $report['is_pro'] && ! empty( $user_journeys ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'User Journeys (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 19 ) ) . "\n\n";

	/* translators: %s: number of sessions analyzed */
	printf( '  ' . esc_html__( 'Sessions Analyzed: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $user_journeys['total_sessions'] ?? 0 ) ) );
	/* translators: %s: average number of pages per session */
	printf( '  ' . esc_html__( 'Avg. Pages/Session: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $user_journeys['avg_path_length'] ?? 0, 1 ) ) );

	// Depth distribution
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$depth = $user_journeys['depth'] ?? array();
	if ( ! empty( $depth ) ) {
		echo "\n  " . esc_html__( 'Session Depth:', 'opti-behavior' ) . "\n";
		/* translators: %s: number of single-page sessions */
		printf( '    ' . esc_html__( '1 Page: %s sessions', 'opti-behavior' ) . "\n", esc_html( number_format( $depth['1_page'] ?? 0 ) ) );
		/* translators: %s: number of 2-3 page sessions */
		printf( '    ' . esc_html__( '2-3 Pages: %s sessions', 'opti-behavior' ) . "\n", esc_html( number_format( $depth['2_3_pages'] ?? 0 ) ) );
		/* translators: %s: number of 4+ page sessions */
		printf( '    ' . esc_html__( '4+ Pages: %s sessions', 'opti-behavior' ) . "\n", esc_html( number_format( $depth['4_plus_pages'] ?? 0 ) ) );
	}

	// Top entry pages
	if ( ! empty( $user_journeys['entry_pages'] ) ) {
		echo "\n  " . esc_html__( 'Top Entry Pages:', 'opti-behavior' ) . "\n";
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $user_journeys['entry_pages'], 0, 5 ) as $i => $page ) {
			$url      = mb_strimwidth( $page['url'] ?? '', 0, 40, '...' );
			$sessions = number_format( $page['sessions'] ?? 0 );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			printf(
				/* translators: 1: rank number, 2: page URL, 3: session count */
				esc_html__( '    %1$d. %2$-40s %3$s sessions', 'opti-behavior' ) . "\n",
				intval( $i + 1 ),
				esc_html( $url ),
				esc_html( $sessions )
			);
		}
	}

	// Top exit pages
	if ( ! empty( $user_journeys['exit_pages'] ) ) {
		echo "\n  " . esc_html__( 'Top Exit Pages:', 'opti-behavior' ) . "\n";
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $user_journeys['exit_pages'], 0, 5 ) as $i => $page ) {
			$url       = mb_strimwidth( $page['url'] ?? '', 0, 35, '...' );
			$sessions  = number_format( $page['sessions'] ?? 0 );
			$exit_rate = number_format( $page['exit_rate'] ?? 0, 1 ) . '%';
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			printf(
				/* translators: 1: rank number, 2: page URL, 3: session count, 4: exit rate percentage */
				esc_html__( '    %1$d. %2$-35s %3$s sessions (%4$s exit)', 'opti-behavior' ) . "\n",
				intval( $i + 1 ),
				esc_html( $url ),
				esc_html( $sessions ),
				esc_html( $exit_rate )
			);
		}
	}
	echo "\n";
}

// Form Analytics Section (Pro)
if ( $report['is_pro'] && ! empty( $form_analytics ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'Form Analytics (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 20 ) ) . "\n\n";

	/* translators: %s: number of form views */
	printf( '  ' . esc_html__( 'Form Views: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $form_analytics['form_views'] ?? 0 ) ) );
	/* translators: %s: number of form submissions */
	printf( '  ' . esc_html__( 'Submissions: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $form_analytics['submissions'] ?? 0 ) ) );
	/* translators: %s: number of form abandonments */
	printf( '  ' . esc_html__( 'Abandonments: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $form_analytics['abandonments'] ?? 0 ) ) );
	/* translators: %s: form conversion rate percentage */
	printf( '  ' . esc_html__( 'Conversion Rate: %s%%', 'opti-behavior' ) . "\n", esc_html( number_format( $form_analytics['conversion_rate'] ?? 0, 1 ) ) );
	/* translators: %s: average form completion time */
	printf( '  ' . esc_html__( 'Avg. Comp. Time: %s', 'opti-behavior' ) . "\n", esc_html( $form_analytics['avg_completion_time_formatted'] ?? '—' ) );

	if ( ! empty( $form_analytics['top_forms'] ) ) {
		echo "\n  " . esc_html__( 'Top Forms by Conversion:', 'opti-behavior' ) . "\n";
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $form_analytics['top_forms'], 0, 5 ) as $i => $form ) {
			$name       = mb_strimwidth( $form['form_name'] ?? __( 'Unknown', 'opti-behavior' ), 0, 30, '...' );
			$views      = number_format( $form['form_views'] ?? 0 );
			$conv_rate  = number_format( $form['conversion_rate'] ?? 0, 1 ) . '%';
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			printf(
				/* translators: 1: rank number, 2: form name, 3: view count, 4: conversion rate */
				esc_html__( '    %1$d. %2$-30s %3$s views, %4$s conv.', 'opti-behavior' ) . "\n",
				intval( $i + 1 ),
				esc_html( $name ),
				esc_html( $views ),
				esc_html( $conv_rate )
			);
		}
	}
	echo esc_html( Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'form_analytics', $form_analytics, $opti_behavior_hint_ctx ) ) );
	echo "\n";
}

// CTA: the PDF report of these days (when the generator read it) + the dashboard.
echo esc_html( str_repeat( '=', 50 ) ) . "\n\n";
if ( $opti_behavior_s && '' !== (string) ( $opti_behavior_s['report_url'] ?? '' ) ) {
	echo esc_html( __( 'View the full report (PDF)', 'opti-behavior' ) ) . ":\n";
	echo esc_url( $opti_behavior_s['report_url'] ) . "\n\n";
}
echo esc_html( __( 'View Full Dashboard', 'opti-behavior' ) ) . ":\n";
echo esc_url( admin_url( 'admin.php?page=opti-behavior-analytics' ) ) . "\n\n";

// Pro line, Free only (OPT-1517).
$opti_behavior_pro_line = Opti_Behavior_Report_Mailer::pro_line( $report );
if ( '' !== $opti_behavior_pro_line ) {
	echo esc_html( $opti_behavior_pro_line ) . "\n";
	echo esc_html( __( 'See what Pro adds', 'opti-behavior' ) ) . ': ' . esc_url( Opti_Behavior_Report_Mailer::PRO_LINE_URL ) . "\n\n";
}

// Footer
echo esc_html( str_repeat( '-', 50 ) ) . "\n";
echo esc_html( __( 'This report was automatically generated by Opti-Behavior', 'opti-behavior' ) ) . "\n";
// Why this address receives the report: this recipient only (OPT-1517).
$opti_behavior_recipient_line = Opti_Behavior_Report_Mailer::recipient_line( $report, $schedule );
if ( '' !== $opti_behavior_recipient_line ) {
	echo esc_html( $opti_behavior_recipient_line ) . "\n";
}
echo esc_html( __( 'Manage report settings', 'opti-behavior' ) ) . ': ' . esc_url( admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=scheduled-reports' ) ) . "\n";
echo esc_html( $report['site_name'] ) . ' | ' . esc_url( home_url() ) . "\n";
