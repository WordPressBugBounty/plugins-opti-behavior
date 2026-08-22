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

// Header
echo esc_html( strtoupper( $schedule['name'] ) ) . "\n";
echo esc_html( str_repeat( '=', strlen( $schedule['name'] ) ) ) . "\n\n";
echo esc_html( $report['period_label'] ) . ' | ' . esc_html( $report['site_name'] ) . "\n\n";

// KPIs Section
if ( ! empty( $kpis ) ) {
	echo esc_html( strtoupper( __( 'Key Performance Indicators', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 28 ) ) . "\n\n";

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
		if ( ! empty( $kpi['change']['value'] ) ) {
			$arrow      = ( $kpi['change']['direction'] ?? '' ) === 'up' ? '^' : 'v';
			$change_str = ' (' . $arrow . ' ' . $kpi['change']['value'] . '%)';
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		}

		echo sprintf( "  %-20s %s%s\n", esc_html( $label ) . ':', esc_html( $value ), esc_html( $change_str ) );
	}
	echo "\n";
}

// Smart Insights Section
if ( ! empty( $smart_insights ) ) {
	echo esc_html( strtoupper( __( 'Smart Insights', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 14 ) ) . "\n\n";
	echo sprintf(
		"  %s\n\n",
		esc_html(
			sprintf(
				/* translators: %d: insight count */
				__( '%d active insight(s) detected for this report period.', 'opti-behavior' ),
				(int) ( $smart_insights['count'] ?? 0 )
			)
		)
	);

	if ( ! empty( $smart_insights['insights'] ) ) {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $smart_insights['insights'], 0, 3 ) as $i => $insight ) {
			$title      = $insight['title'] ?? __( 'Smart Insight', 'opti-behavior' );
			$entity     = ! empty( $insight['entity_label'] ) ? ' — ' . $insight['entity_label'] : '';
			$priority   = $insight['priority'] ?? __( 'Normal', 'opti-behavior' );
			$confidence = ! empty( $insight['confidence'] ) ? ' | Confidence: ' . $insight['confidence'] . '%' : '';
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

			echo sprintf( "  %d. %s%s\n", intval( $i + 1 ), esc_html( $title ), esc_html( $entity ) );
			echo sprintf( "     Priority: %s%s\n", esc_html( $priority ), esc_html( $confidence ) );
			if ( ! empty( $insight['interpretation'] ) ) {
				echo sprintf( "     %s\n", esc_html( $insight['interpretation'] ) );
			}
			if ( ! empty( $insight['evidence'] ) ) {
				echo sprintf( "     Evidence: %s\n", esc_html( implode( ' | ', array_slice( $insight['evidence'], 0, 3 ) ) ) );
			}
			if ( ! empty( $insight['recommended_action'] ) ) {
				echo sprintf( "     Next action: %s\n", esc_html( $insight['recommended_action'] ) );
			}
			echo "\n";
		}
	} else {
		echo '  ' . esc_html( $smart_insights['empty_message'] ?? __( 'No active Smart Insights detected for this report period.', 'opti-behavior' ) ) . "\n\n";
	}
}

// Top Pages Section
if ( ! empty( $top_pages ) ) {
	echo esc_html( strtoupper( __( 'Top Pages', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 10 ) ) . "\n\n";

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	foreach ( array_slice( $top_pages, 0, 5 ) as $i => $page ) {
		$title = mb_strimwidth( $page['title'], 0, 50, '...' );
		$views = number_format( $page['views'] );
		$time  = $page['avg_time_formatted'] ?? '-';
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		echo sprintf( "  %d. %s\n", intval( $i + 1 ), esc_html( $title ) );
		echo sprintf(
			/* translators: 1: view count, 2: average time on page */
			'     ' . __( 'Views: %1$s | Avg. Time: %2$s', 'opti-behavior' ) . "\n\n",
			esc_html( $views ),
			esc_html( $time )
		);
	}
}

// Top Referrers Section
if ( ! empty( $top_referrers ) ) {
	echo esc_html( strtoupper( __( 'Top Referrers', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 13 ) ) . "\n\n";

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	foreach ( array_slice( $top_referrers, 0, 5 ) as $i => $ref ) {
		$source = $ref['source'];
		$visits = number_format( $ref['visits'] );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		echo sprintf(
			/* translators: 1: rank number, 2: referrer source, 3: visit count */
			__( '  %1$d. %2$-30s %3$s visits', 'opti-behavior' ) . "\n",
			intval( $i + 1 ),
			esc_html( $source ),
			esc_html( $visits )
		);
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

		echo sprintf( "  %-20s %s (%s%%)\n", esc_html( $type ) . ':', esc_html( $count ), esc_html( $pct ) );
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

		echo sprintf(
			/* translators: 1: rank number, 2: country name, 3: visitor count, 4: session count */
			__( '  %1$d. %2$-25s %3$s visitors, %4$s sessions', 'opti-behavior' ) . "\n",
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

	echo sprintf( '  ' . __( 'Total Clicks: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $heatmap['total_clicks'] ?? 0 ) ) );
	echo sprintf( '  ' . __( 'Pages Tracked: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $heatmap['pages_tracked'] ?? 0 ) ) );

	if ( ! empty( $heatmap['top_clicked'] ) ) {
		echo "\n  " . esc_html__( 'Most Clicked Pages:', 'opti-behavior' ) . "\n";
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $heatmap['top_clicked'], 0, 3 ) as $i => $clicked ) {
			$title  = mb_strimwidth( $clicked['title'], 0, 40, '...' );
			$clicks = number_format( $clicked['clicks'] );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			echo sprintf(
				/* translators: 1: rank number, 2: page title, 3: click count */
				__( '    %1$d. %2$s (%3$s clicks)', 'opti-behavior' ) . "\n",
				intval( $i + 1 ),
				esc_html( $title ),
				esc_html( $clicks )
			);
		}
	}
	echo "\n";
}

// Funnel Performance Section
if ( ! empty( $funnels ) && ! empty( $funnels['total_funnels'] ) ) {
	echo esc_html( strtoupper( __( 'Funnel Performance', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 18 ) ) . "\n\n";

	echo sprintf( '  ' . __( 'Active Funnels: %s', 'opti-behavior' ) . "\n\n", esc_html( $funnels['total_funnels'] ) );

	if ( ! empty( $funnels['funnels'] ) ) {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( $funnels['funnels'] as $i => $funnel ) {
			$name    = mb_strimwidth( $funnel['name'], 0, 35, '...' );
			$entries = number_format( $funnel['total_entries'] );
			$compl   = number_format( $funnel['completions'] );
			$rate    = number_format( $funnel['conversion_rate'] ?? 0, 1 ) . '%';
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

			echo sprintf( "  %d. %-35s\n", intval( $i + 1 ), esc_html( $name ) );
			echo sprintf(
				/* translators: 1: funnel entries, 2: funnel completions, 3: conversion rate */
				'     ' . __( 'Entries: %1$s | Completions: %2$s | Conv: %3$s', 'opti-behavior' ) . "\n\n",
				esc_html( $entries ),
				esc_html( $compl ),
				esc_html( $rate )
			);
		}
	}
}

// Session Recordings Stats (Pro)
if ( $report['is_pro'] && ! empty( $recordings ) ) {
	echo esc_html( strtoupper( __( 'Session Recordings (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 24 ) ) . "\n\n";

	echo sprintf( '  ' . __( 'Total Recordings: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $recordings['total'] ) ) );
	echo sprintf( '  ' . __( 'Watched: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $recordings['watched'] ) ) );
	echo sprintf( '  ' . __( 'Unwatched: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $recordings['unwatched'] ) ) );
	echo sprintf( '  ' . __( 'Avg. Duration: %s', 'opti-behavior' ) . "\n", esc_html( $recordings['avg_duration_formatted'] ) );
	echo "\n";
}

// Errors Section (Pro)
if ( $report['is_pro'] && ! empty( $errors ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'JavaScript Errors (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 23 ) ) . "\n\n";

	echo sprintf( '  ' . __( 'Total Errors: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $errors['total'] ) ) );
	echo sprintf( '  ' . __( 'Unresolved: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $errors['unresolved'] ) ) );
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
			echo sprintf( "  %-35s %s\n", esc_html( $config['label'] ), esc_html( $value ) );
		}
	}
	echo "\n";
}

// Friction Events Section (Pro)
if ( $report['is_pro'] && ! empty( $friction ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'Friction Events (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 21 ) ) . "\n\n";

	echo sprintf( '  ' . __( 'Total Events: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $friction['total'] ) ) );
	echo sprintf( '  ' . __( 'Rage Clicks: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $friction['rage_clicks'] ) ) );
	echo sprintf( '  ' . __( 'Dead Clicks: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $friction['dead_clicks'] ) ) );
	echo "\n";
}

// Broken Links Section (Pro)
if ( $report['is_pro'] && ! empty( $broken_links ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'Broken Links Report (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 25 ) ) . "\n\n";

	echo sprintf( '  ' . __( 'Total Found: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $broken_links['total'] ) ) );
	echo sprintf( '  ' . __( 'Open: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $broken_links['open'] ) ) );
	echo sprintf( '  ' . __( 'Fixed: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $broken_links['fixed'] ) ) );

	if ( ! empty( $broken_links['top_links'] ) ) {
		echo "\n  " . esc_html__( 'Top Broken Links:', 'opti-behavior' ) . "\n";
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $broken_links['top_links'], 0, 3 ) as $i => $link ) {
			$url   = mb_strimwidth( $link['url'], 0, 50, '...' );
			$status = $link['http_status'];
			$hits  = number_format( $link['occurrence_count'] );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			echo sprintf(
				/* translators: 1: rank number, 2: broken URL, 3: HTTP status code, 4: occurrence count */
				__( '    %1$d. %2$s (HTTP %3$s, %4$s hits)', 'opti-behavior' ) . "\n",
				intval( $i + 1 ),
				esc_html( $url ),
				esc_html( $status ),
				esc_html( $hits )
			);
		}
	}
	echo "\n";
}

// User Journeys Section (Pro)
if ( $report['is_pro'] && ! empty( $user_journeys ) ) {
	/* translators: (Pro) marks a Pro-plugin-only report section */
	echo esc_html( strtoupper( __( 'User Journeys (Pro)', 'opti-behavior' ) ) ) . "\n";
	echo esc_html( str_repeat( '-', 19 ) ) . "\n\n";

	echo sprintf( '  ' . __( 'Sessions Analyzed: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $user_journeys['total_sessions'] ?? 0 ) ) );
	echo sprintf( '  ' . __( 'Avg. Pages/Session: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $user_journeys['avg_path_length'] ?? 0, 1 ) ) );

	// Depth distribution
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$depth = $user_journeys['depth'] ?? array();
	if ( ! empty( $depth ) ) {
		echo "\n  " . esc_html__( 'Session Depth:', 'opti-behavior' ) . "\n";
		echo sprintf( '    ' . __( '1 Page: %s sessions', 'opti-behavior' ) . "\n", esc_html( number_format( $depth['1_page'] ?? 0 ) ) );
		echo sprintf( '    ' . __( '2-3 Pages: %s sessions', 'opti-behavior' ) . "\n", esc_html( number_format( $depth['2_3_pages'] ?? 0 ) ) );
		echo sprintf( '    ' . __( '4+ Pages: %s sessions', 'opti-behavior' ) . "\n", esc_html( number_format( $depth['4_plus_pages'] ?? 0 ) ) );
	}

	// Top entry pages
	if ( ! empty( $user_journeys['entry_pages'] ) ) {
		echo "\n  " . esc_html__( 'Top Entry Pages:', 'opti-behavior' ) . "\n";
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $user_journeys['entry_pages'], 0, 5 ) as $i => $page ) {
			$url      = mb_strimwidth( $page['url'] ?? '', 0, 40, '...' );
			$sessions = number_format( $page['sessions'] ?? 0 );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			echo sprintf(
				/* translators: 1: rank number, 2: page URL, 3: session count */
				__( '    %1$d. %2$-40s %3$s sessions', 'opti-behavior' ) . "\n",
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
			echo sprintf(
				/* translators: 1: rank number, 2: page URL, 3: session count, 4: exit rate percentage */
				__( '    %1$d. %2$-35s %3$s sessions (%4$s exit)', 'opti-behavior' ) . "\n",
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

	echo sprintf( '  ' . __( 'Form Views: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $form_analytics['form_views'] ?? 0 ) ) );
	echo sprintf( '  ' . __( 'Submissions: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $form_analytics['submissions'] ?? 0 ) ) );
	echo sprintf( '  ' . __( 'Abandonments: %s', 'opti-behavior' ) . "\n", esc_html( number_format( $form_analytics['abandonments'] ?? 0 ) ) );
	echo sprintf( '  ' . __( 'Conversion Rate: %s%%', 'opti-behavior' ) . "\n", esc_html( number_format( $form_analytics['conversion_rate'] ?? 0, 1 ) ) );
	echo sprintf( '  ' . __( 'Avg. Comp. Time: %s', 'opti-behavior' ) . "\n", esc_html( $form_analytics['avg_completion_time_formatted'] ?? '—' ) );

	if ( ! empty( $form_analytics['top_forms'] ) ) {
		echo "\n  " . esc_html__( 'Top Forms by Conversion:', 'opti-behavior' ) . "\n";
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		foreach ( array_slice( $form_analytics['top_forms'], 0, 5 ) as $i => $form ) {
			$name       = mb_strimwidth( $form['form_name'] ?? __( 'Unknown', 'opti-behavior' ), 0, 30, '...' );
			$views      = number_format( $form['form_views'] ?? 0 );
			$conv_rate  = number_format( $form['conversion_rate'] ?? 0, 1 ) . '%';
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			echo sprintf(
				/* translators: 1: rank number, 2: form name, 3: view count, 4: conversion rate */
				__( '    %1$d. %2$-30s %3$s views, %4$s conv.', 'opti-behavior' ) . "\n",
				intval( $i + 1 ),
				esc_html( $name ),
				esc_html( $views ),
				esc_html( $conv_rate )
			);
		}
	}
	echo "\n";
}

// CTA
echo esc_html( str_repeat( '=', 50 ) ) . "\n\n";
echo esc_html( __( 'View Full Dashboard', 'opti-behavior' ) ) . ":\n";
echo esc_url( admin_url( 'admin.php?page=opti-behavior-analytics' ) ) . "\n\n";

// Footer
echo esc_html( str_repeat( '-', 50 ) ) . "\n";
echo esc_html( __( 'This report was automatically generated by Opti-Behavior', 'opti-behavior' ) ) . "\n";
echo esc_html( __( 'Manage report settings', 'opti-behavior' ) ) . ': ' . esc_url( admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=scheduled-reports' ) ) . "\n";
echo esc_html( $report['site_name'] ) . ' | ' . esc_url( home_url() ) . "\n";
