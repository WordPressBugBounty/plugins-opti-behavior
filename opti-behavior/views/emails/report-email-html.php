<?php
/**
 * HTML Email Template for Scheduled Reports
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
$recordings   = $report['sections']['recordings'] ?? array();
$errors       = $report['sections']['errors'] ?? array();
$friction     = $report['sections']['friction'] ?? array();
$performance  = $report['sections']['performance'] ?? array();
$broken_links   = $report['sections']['broken_links'] ?? array();
$user_journeys  = $report['sections']['user_journeys'] ?? array();
$form_analytics = $report['sections']['form_analytics'] ?? array();
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// One "What it means / What to do" line per block, from the figures of that block only (OPT-1648).
$opti_behavior_hint_ctx = array(
	'days'      => Opti_Behavior_Report_Mailer::period_days( $report ),
	'pageviews' => (int) ( $kpis['pageviews']['value'] ?? 0 ),
);

/**
 * Get arrow indicator based on direction (using text arrows for email compatibility)
 *
 * Guarded: this template is included once per rendered report, so a worker that
 * sends several due schedules in one PHP process would otherwise hit a fatal
 * "Cannot redeclare opti_behavior_get_change_html()" on the second report.
 */
if ( ! function_exists( 'opti_behavior_get_change_html' ) ) {
	/**
	 * Get arrow indicator based on direction.
	 *
	 * @param array $change Change descriptor (value, direction, positive).
	 * @return string HTML markup, or an empty string when there is nothing to show.
	 */
	function opti_behavior_get_change_html( $change ) {
		$direction = $change['direction'] ?? 'neutral';

		// Previous period empty: nothing to compare with, no arrow, no percent (OPT-1510).
		if ( 'new' === $direction ) {
			return sprintf(
				'<div style="font-size: 12px; color: #71717a; margin-top: 4px;">%s</div>',
				esc_html__( 'new', 'opti-behavior' )
			);
		}

		if ( empty( $change['value'] ) ) {
			return '';
		}

		$is_positive = $change['positive'] ?? null;

		// Green = good way, red = bad way, grey = too small to judge (|change| <= 0.5 %).
		if ( 'up' === $direction ) {
			$arrow = '&#9650;'; // ▲
		} elseif ( 'down' === $direction ) {
			$arrow = '&#9660;'; // ▼
		} else {
			return '';
		}
		$color = true === $is_positive ? '#16a34a' : ( false === $is_positive ? '#dc2626' : '#71717a' );

		// Rates = gap in points ("18.4 pts"), counts = percent (OPT-1766).
		return sprintf(
			'<div style="font-size: 12px; color: %s; margin-top: 4px;">%s %s</div>',
			esc_attr( $color ),
			$arrow,
			esc_html( Opti_Behavior_Report_Generator::change_amount_text( $change ) )
		);
	}
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<meta name="color-scheme" content="light dark" />
	<meta name="supported-color-schemes" content="light dark" />
	<title><?php echo esc_html( $schedule['name'] ); ?></title>
	<?php // Phone layout (design OPT-1519): fluid card, 2 key figures per row, tighter tables. ?>
	<style type="text/css">
		:root { color-scheme: light dark; supported-color-schemes: light dark; }
		@media only screen and (max-width: 620px) {
			.ob-outer { padding: 12px 8px !important; }
			.ob-card > tbody > tr > td { padding-left: 16px !important; padding-right: 16px !important; }
			.ob-head { padding-top: 28px !important; padding-bottom: 28px !important; }
			.ob-h1 { font-size: 22px !important; }
			.ob-kpi { width: 50% !important; }
			.ob-kpi-in { padding: 14px 8px !important; }
			.ob-kpi-val { font-size: 24px !important; }
			.ob-col { display: block !important; float: left !important; width: 50% !important; box-sizing: border-box; }
			.ob-col-wide { width: 100% !important; }
			.ob-btn-cell { display: block !important; padding: 0 0 10px !important; }
			.ob-btn-cell table { width: 100% !important; }
			.ob-btn-cell a { display: block !important; text-align: center; }
			.ob-t th, .ob-t td { padding: 10px 8px !important; }
			<?php // Top pages: 3 figure columns narrower on a phone, so the Page column keeps room (OPT-2488). ?>
			.ob-t .ob-tp-n { width: 52px !important; padding: 10px 4px !important; letter-spacing: 0 !important; }
		}
	</style>
	<?php // Dark clients (Apple Mail, iOS Mail, Outlook mac): own block, so a client that rejects it keeps the phone layout. ?>
	<style type="text/css">
		@media (prefers-color-scheme: dark) {
			.ob-bg, body { background-color: #09090b !important; }
			.ob-card { background-color: #18181b !important; }
			.ob-logo { background-color: #ffffff !important; }
			.ob-card [style*="background-color: #f9fafb"], .ob-card [bgcolor="#f9fafb"], .ob-card [style*="background-color: #f3f4f6"] { background-color: #27272a !important; }
			.ob-card [style*="background-color: #faf5ff"], .ob-card [style*="background-color: #eef2ff"] { background-color: #1e1b4b !important; border-color: #3730a3 !important; }
			.ob-card [style*="background-color: #fef2f2"] { background-color: #3f1212 !important; border-color: #7f1d1d !important; }
			.ob-card [style*="background-color: #fef3c7"] { background-color: #3b2a0a !important; border-color: #78350f !important; }
			.ob-card [style*="background-color: #dcfce7"], .ob-card [style*="background-color: #f0fdf4"] { background-color: #0f2e1a !important; border-color: #166534 !important; }
			.ob-card [style*="border: 1px solid #e5e7eb"], .ob-card [style*="border-bottom: 1px solid #e5e7eb"], .ob-card [style*="border-top: 1px solid #e5e7eb"] { border-color: #3f3f46 !important; }
			.ob-card [style*="border-bottom: 1px solid #f3f4f6"], .ob-card [style*="border-top: 1px solid #f3f4f6"] { border-color: #3f3f46 !important; }
			.ob-card [style*="color: #18181b"], .ob-card [style*="color: #581c87"] { color: #fafafa !important; }
			.ob-card [style*="color: #3f3f46"], .ob-card [style*="color: #52525b"], .ob-card [style*="color: #6b21a8"] { color: #d4d4d8 !important; }
			.ob-card [style*="color: #6b7280"], .ob-card [style*="color: #71717a"], .ob-card [style*="color: #4b5563"], .ob-card [style*="color: #a1a1aa"] { color: #a1a1aa !important; }
			.ob-card [style*="color: #166534"] { color: #86efac !important; }
			.ob-card [style*="color: #991b1b"], .ob-card [style*="color: #dc2626"] { color: #fca5a5 !important; }
			.ob-card [style*="color: #92400e"], .ob-card [style*="color: #d97706"], .ob-card [style*="color: #b45309"], .ob-card [style*="color: #a16207"] { color: #fcd34d !important; }
			.ob-card [style*="color: #16a34a"], .ob-card [style*="color: #15803d"], .ob-card [style*="color: #047857"] { color: #4ade80 !important; }
			.ob-card [style*="color: #4338ca"] { color: #c7d2fe !important; }
			.ob-card a[style*="color: #4f46e5"] { color: #a5b4fc !important; }
			.ob-card .ob-btn, .ob-card .ob-btn a { color: #ffffff !important; }
			.ob-card .ob-btn2 { border-color: #4338ca !important; }
			.ob-card .ob-mean { background-color: #27272a !important; border-left-color: #818cf8 !important; }
			.ob-card .ob-mean strong { color: #c7d2fe !important; }
		}
	</style>
	<!--[if mso]>
	<style type="text/css">
		table { border-collapse: collapse; }
		.button { padding: 12px 24px !important; }
	</style>
	<![endif]-->
</head>
<body class="ob-bg" style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f4f4f5; -webkit-font-smoothing: antialiased;">
	<?php
	// Inbox preview line, hidden in the e-mail itself (OPT-1513).
	$opti_behavior_preheader = class_exists( 'Opti_Behavior_Report_Mailer' ) ? Opti_Behavior_Report_Mailer::preheader_text( $report ) : '';
	if ( '' !== $opti_behavior_preheader ) :
		?>
	<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all; font-size: 1px; line-height: 1px; color: #f4f4f5; opacity: 0;"><?php echo esc_html( $opti_behavior_preheader ); ?></div>
	<?php endif; ?>
	<table class="ob-bg" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f5;">
		<tr>
			<td class="ob-outer" align="center" style="padding: 40px 20px;">
				<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
				<table class="ob-card" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden;">
					<!-- Header: brand, site + period as the title, schedule name as a small line (design OPT-1519) -->
					<tr>
						<td class="ob-head" bgcolor="#4f46e5" style="background-color: #4f46e5; background-image: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); padding: 36px 40px; text-align: center;">
							<?php echo Opti_Behavior_Report_Mailer::brand_header_html( 48 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup escaped inside the helper. ?>
							<h1 class="ob-h1" style="color: #ffffff; margin: 0 0 6px 0; font-size: 26px; line-height: 1.25; font-weight: 700; letter-spacing: -0.3px; word-break: break-word; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php echo esc_html( $report['site_name'] ); ?>
							</h1>
							<p style="color: #e0e7ff; margin: 0; font-size: 16px; line-height: 1.4; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php echo esc_html( $report['period_label'] ); ?>
							</p>
							<p style="color: #e0e7ff; margin: 10px 0 0 0; font-size: 13px; line-height: 1.4; word-break: break-word; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php echo esc_html( $schedule['name'] ); ?>
							</p>
						</td>
					</tr>

					<?php
					// Full e-mail (OPT-1647, design OPT-1646): the 3 issues to look at first of the PDF site report first, then every block.
					$opti_behavior_s    = ( ! empty( $report['site'] ) && is_array( $report['site'] ) ) ? $report['site'] : null;
					$opti_behavior_font = "font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;";
					$opti_behavior_sev  = array(
						'crit'  => array( '#fef2f2', '#fecaca', '#991b1b' ),
						'warn'  => array( '#fef3c7', '#fde68a', '#92400e' ),
						'watch' => array( '#eef2ff', '#c7d2fe', '#4338ca' ),
					);
					if ( $opti_behavior_s && $opti_behavior_s['visits'] <= 0 ) :
						?>
					<tr>
						<td style="padding: 32px 40px 0;">
							<p style="margin: 0; padding: 12px 16px; font-size: 13px; color: #3f3f46; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; <?php echo esc_attr( $opti_behavior_font ); ?>">
								<?php
								echo esc_html(
									/* translators: 1: first day, 2: last day of the period. */
									sprintf( __( 'No visit recorded between %1$s and %2$s.', 'opti-behavior' ), Opti_Behavior_Report_Site::day_long( $opti_behavior_s['from'] ), Opti_Behavior_Report_Site::day_long( $opti_behavior_s['to'] ) )
								);
								?>
							</p>
						</td>
					</tr>
					<?php elseif ( $opti_behavior_s ) : ?>
					<!-- The 3 issues to look at first, as in the PDF (OPT-1553) -->
					<tr>
						<td style="padding: 32px 40px 4px;">
							<h2 style="margin: 0 0 14px 0; font-size: 16px; font-weight: 600; color: #18181b; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php esc_html_e( 'The 3 issues to look at first', 'opti-behavior' ); ?></h2>
								<?php if ( ! empty( $opti_behavior_s['issues_period'] ) ) : ?>
								<p style="margin: -8px 0 14px; font-size: 12px; color: #71717a; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php echo esc_html( $opti_behavior_s['issues_period'] ); ?></p>
								<?php endif; ?>
							<?php if ( empty( $opti_behavior_s['issues'] ) ) : ?>
							<p style="margin: 0 0 24px; font-size: 13px; color: #6b7280; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php esc_html_e( 'No costly issue detected for this period.', 'opti-behavior' ); ?></p>
							<?php else : ?>
								<?php
								foreach ( $opti_behavior_s['issues'] as $opti_behavior_it ) :
									$opti_behavior_c = $opti_behavior_sev[ $opti_behavior_it['severity'] ] ?? $opti_behavior_sev['watch'];
									?>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 10px;">
								<tr>
									<td style="padding: 14px 16px;">
										<div style="margin-bottom: 6px;">
											<span style="display: inline-block; padding: 2px 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: <?php echo esc_attr( $opti_behavior_c[2] ); ?>; background-color: <?php echo esc_attr( $opti_behavior_c[0] ); ?>; border: 1px solid <?php echo esc_attr( $opti_behavior_c[1] ); ?>; border-radius: 999px; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php echo esc_html( $opti_behavior_it['word'] ); ?></span>
											<span style="font-size: 12px; color: #71717a; <?php echo esc_attr( $opti_behavior_font ); ?>">&nbsp;#<?php echo esc_html( number_format_i18n( $opti_behavior_it['rank'] ) ); ?></span>
										</div>
										<div style="font-size: 15px; font-weight: 700; color: #18181b; line-height: 1.35; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php echo esc_html( $opti_behavior_it['title'] ); ?></div>
										<?php if ( '' !== $opti_behavior_it['headline'] ) : ?>
										<div style="margin-top: 4px; font-size: 13px; color: #3f3f46; line-height: 1.45; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php echo esc_html( $opti_behavior_it['headline'] ); ?></div>
										<?php endif; ?>
										<?php if ( null !== $opti_behavior_it['lost'] || '' !== $opti_behavior_it['money'] ) : ?>
										<div style="margin-top: 6px; font-size: 13px; color: #18181b; <?php echo esc_attr( $opti_behavior_font ); ?>">
											<?php
											$opti_behavior_parts = array();
											// OB-07: what the figure counts (Report_Site::issue_unit()), never "lost".
											$opti_behavior_unit = isset( $opti_behavior_it['unit'] ) ? (string) $opti_behavior_it['unit'] : '';
											if ( '' !== $opti_behavior_it['money'] ) {
												$opti_behavior_parts[] = '<strong>' . esc_html( $opti_behavior_it['money'] ) . '</strong> ' . esc_html__( 'estimated loss', 'opti-behavior' );
											}
											if ( null !== $opti_behavior_it['lost'] && null !== $opti_behavior_it['share'] ) {
												$opti_behavior_parts[] = '<strong>' . esc_html( number_format_i18n( $opti_behavior_it['lost'] ) ) . '</strong> ' . esc_html( '' !== $opti_behavior_unit ? $opti_behavior_unit : _n( 'visit concerned', 'visits concerned', $opti_behavior_it['lost'], 'opti-behavior' ) );
												$opti_behavior_parts[] = '<strong>' . esc_html( number_format_i18n( $opti_behavior_it['share'] ) . ' %' ) . '</strong> ' . ( empty( $opti_behavior_it['own'] ) ? esc_html__( 'of all visits', 'opti-behavior' ) : esc_html__( 'of the visits of those days', 'opti-behavior' ) );
											} elseif ( null !== $opti_behavior_it['lost'] ) {
												// OPT-1940: estimated from an average gap, never a share of the visits.
												$opti_behavior_parts[] = '<strong>&#8776; ' . esc_html( number_format_i18n( $opti_behavior_it['lost'] ) ) . '</strong> ' . esc_html( '' !== $opti_behavior_unit ? $opti_behavior_unit : _n( 'visit concerned', 'visits concerned', $opti_behavior_it['lost'], 'opti-behavior' ) );
											}
											echo wp_kses( implode( ' &#183; ', $opti_behavior_parts ), array( 'strong' => array() ) );
											?>
										</div>
										<?php endif; ?>
										<div style="margin-top: 8px; font-size: 13px; color: #3f3f46; line-height: 1.45; <?php echo esc_attr( $opti_behavior_font ); ?>"><strong><?php esc_html_e( 'Where', 'opti-behavior' ); ?>:</strong> <?php echo esc_html( $opti_behavior_it['where'] ); ?></div>
										<?php if ( '' !== $opti_behavior_it['action'] ) : ?>
										<div style="margin-top: 4px; font-size: 13px; color: #166534; line-height: 1.45; <?php echo esc_attr( $opti_behavior_font ); ?>"><strong><?php esc_html_e( 'What to do', 'opti-behavior' ); ?>:</strong> <?php echo esc_html( $opti_behavior_it['action'] ); ?></div>
										<?php endif; ?>
									</td>
								</tr>
							</table>
								<?php endforeach; ?>
								<?php
								$opti_behavior_n = count( $opti_behavior_s['issues'] );
								if ( $opti_behavior_n < Opti_Behavior_Report_Site::TOP_ISSUES && $opti_behavior_s['issues_total'] <= $opti_behavior_n ) {
										// Same rule as the PDF: "Only N" only when nothing else is open (Free alone leaves locked Pro cards out).
									/* translators: %s: number of issues. */
									$opti_behavior_more = sprintf( _n( 'Only %s open issue over this period.', 'Only %s open issues over this period.', $opti_behavior_n, 'opti-behavior' ), number_format_i18n( $opti_behavior_n ) );
								} elseif ( $opti_behavior_s['issues_total'] > $opti_behavior_n ) {
									$opti_behavior_left = $opti_behavior_s['issues_total'] - $opti_behavior_n;
									/* translators: %s: number of issues. */
									$opti_behavior_more = sprintf( _n( '%s other open issue in Smart Insights, with a smaller cost.', '%s other open issues in Smart Insights, with a smaller cost.', $opti_behavior_left, 'opti-behavior' ), number_format_i18n( $opti_behavior_left ) );
								} else {
									$opti_behavior_more = '';
								}
								if ( '' !== $opti_behavior_more ) :
									?>
							<p style="margin: 2px 0 0; font-size: 12px; color: #71717a; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php echo esc_html( $opti_behavior_more ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php // OPT-2860: one AI line (which AI companies read the site, pages read, visitors they sent). ?>
					<?php if ( ! empty( $opti_behavior_s['ai_line'] ) ) : ?>
					<tr>
						<td style="padding: 0 40px 24px;">
							<p style="margin: 0; font-size: 13px; color: #3f3f46; line-height: 1.45; <?php echo esc_attr( $opti_behavior_font ); ?>"><strong><?php esc_html_e( 'AI', 'opti-behavior' ); ?>:</strong> <?php echo esc_html( $opti_behavior_s['ai_line'] ); ?></p>
						</td>
					</tr>
					<?php endif; ?>

					<?php
					// Key figures first, then Smart Insights (design OPT-1646; the costly issues above already say what to fix).
					$opti_behavior_si_first = false;
					ob_start();
					?>
					<?php if ( ! empty( $kpis ) ) : ?>
					<!-- KPIs Section -->
					<tr>
						<td style="padding: <?php echo $opti_behavior_si_first ? '0' : '32px'; ?> 40px 32px;">
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td style="padding-bottom: 20px;">
										<table cellpadding="0" cellspacing="0" border="0">
											<tr>
												<td style="width: 8px; height: 8px; background-color: #4f46e5; border-radius: 50%;"></td>
												<td style="padding-left: 10px; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
													<?php esc_html_e( 'Key Performance Indicators', 'opti-behavior' ); ?>
												</td>
											</tr>
										</table>
										<?php
										$opti_behavior_compare_days = Opti_Behavior_Report_Mailer::period_days( $report );
										if ( $opti_behavior_compare_days > 0 ) :
											?>
										<div style="padding: 4px 0 0 18px; font-size: 12px; color: #71717a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: number of days of the report period */
													_n( 'vs previous %s day', 'vs previous %s days', $opti_behavior_compare_days, 'opti-behavior' ),
													number_format_i18n( $opti_behavior_compare_days )
												)
											);
											?>
										</div>
										<?php endif; ?>
									</td>
								</tr>
							</table>
							<?php
							// Key figures in one neutral colour; green / red only on the change line (design OPT-1519).
							// 3 tiles per row, 2 on a phone (inline blocks; Outlook gets the same grid through MSO tables).
							$opti_behavior_kpi_items = array(
								'visitors'         => array( 'label' => __( 'Visitors', 'opti-behavior' ) ),
								'sessions'         => array( 'label' => __( 'Sessions', 'opti-behavior' ) ),
								'pageviews'        => array( 'label' => __( 'Page Views', 'opti-behavior' ) ),
								'bounce_rate'      => array(
									'label'  => __( 'Bounce Rate', 'opti-behavior' ),
									'suffix' => '%',
								),
								'avg_session_time' => array(
									'label'     => __( 'Avg. Session Time', 'opti-behavior' ),
									'formatted' => true,
								),
								'avg_scroll_depth' => array(
									'label'  => __( 'Avg. Scroll Depth', 'opti-behavior' ),
									'suffix' => '%',
								),
							);
							$opti_behavior_kpi_n = 0;
							?>
							<div style="font-size: 0; line-height: 0;">
								<!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><![endif]-->
								<?php
								foreach ( $opti_behavior_kpi_items as $opti_behavior_kpi_key => $opti_behavior_kpi_cfg ) :
									if ( ! isset( $kpis[ $opti_behavior_kpi_key ] ) ) {
										continue;
									}
									$opti_behavior_kpi = $kpis[ $opti_behavior_kpi_key ];
									if ( ! empty( $opti_behavior_kpi_cfg['formatted'] ) ) {
										$opti_behavior_kpi_value = $opti_behavior_kpi['formatted'] ?? $opti_behavior_kpi['value'];
									} elseif ( isset( $opti_behavior_kpi_cfg['suffix'] ) ) {
										$opti_behavior_kpi_value = $opti_behavior_kpi['value'] . $opti_behavior_kpi_cfg['suffix'];
									} else {
										$opti_behavior_kpi_value = number_format( $opti_behavior_kpi['value'] );
									}
									if ( $opti_behavior_kpi_n > 0 && 0 === $opti_behavior_kpi_n % 3 ) {
										echo '<!--[if mso]></tr><tr><![endif]-->';
									}
									++$opti_behavior_kpi_n;
									?>
								<!--[if mso]><td width="33%" valign="top"><![endif]-->
								<div class="ob-kpi" style="display: inline-block; width: 33.33%; vertical-align: top; box-sizing: border-box; padding: 6px; font-size: 14px; line-height: 1.4;">
									<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 10px; border: 1px solid #e5e7eb;">
										<tr>
											<td class="ob-kpi-in" style="padding: 18px 12px; text-align: center;">
												<div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_kpi_cfg['label'] ); ?></div>
												<div class="ob-kpi-val" style="font-size: 28px; font-weight: 700; color: #18181b; line-height: 1.2; letter-spacing: -0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
													<?php echo esc_html( $opti_behavior_kpi_value ); ?>
												</div>
												<div style="min-height: 22px;"><?php echo wp_kses_post( opti_behavior_get_change_html( $opti_behavior_kpi['change'] ?? array() ) ); ?></div>
											</td>
										</tr>
									</table>
								</div>
								<!--[if mso]></td><![endif]-->
								<?php endforeach; ?>
								<!--[if mso]></tr></table><![endif]-->
							</div>
							<?php if ( 'anonymous' === ( $report['privacy_mode'] ?? '' ) ) : ?>
							<p style="margin: 12px 6px 0; font-size: 12px; color: #71717a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Cookieless mode: a returning visitor is counted once per day.', 'opti-behavior' ); ?></p>
							<?php endif; ?>
							<?php echo wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'kpis', $kpis, $opti_behavior_hint_ctx ) ) ); ?>
						</td>
					</tr>
					<?php endif; ?>
					<?php
					$opti_behavior_kpi_html = ob_get_clean();
					ob_start();
					?>

					<?php if ( ! empty( $smart_insights ) ) : ?>
					<!-- Smart Insights Section ("What to fix this week") -->
					<tr>
						<td style="padding: <?php echo ( $opti_behavior_si_first || empty( $kpis ) ) ? '32px' : '0'; ?> 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'What to fix this week', 'opti-behavior' ); ?>
							</h2>
							<?php if ( $opti_behavior_si_first ) : ?>
							<p style="margin: 0 0 14px 0; font-size: 13px; color: #52525b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php
								printf(
									/* translators: %d: insight count */
									esc_html__( '%d active insight(s) detected for this report period.', 'opti-behavior' ),
									(int) ( $smart_insights['count'] ?? 0 )
								);
								?>
							</p>
							<?php endif; ?>
							<?php if ( ! empty( $smart_insights['insights'] ) ) : ?>
								<?php foreach ( array_slice( $smart_insights['insights'], 0, 3 ) as $opti_behavior_si_item ) : ?>
									<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #faf5ff; border: 1px solid #e9d5ff; border-left: 4px solid #4f46e5; border-radius: 8px; margin-bottom: 10px;">
										<tr>
											<td style="padding: 14px 16px;">
												<div style="font-size: 14px; font-weight: 700; color: #581c87; margin-bottom: 4px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
													<?php echo esc_html( $opti_behavior_si_item['title'] ?? __( 'Smart Insight', 'opti-behavior' ) ); ?>
												</div>
												<?php if ( ! empty( $opti_behavior_si_item['entity_label'] ) ) : ?>
													<div style="font-size: 12px; color: #6b21a8; margin-bottom: 6px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_si_item['entity_label'] ); ?></div>
												<?php endif; ?>
												<div style="font-size: 12px; color: #52525b; margin-bottom: 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
													<strong><?php esc_html_e( 'Priority:', 'opti-behavior' ); ?></strong> <?php echo esc_html( $opti_behavior_si_item['priority'] ?? __( 'Normal', 'opti-behavior' ) ); ?>
													<?php if ( ! empty( $opti_behavior_si_item['confidence'] ) ) : ?>
														&#8226; <strong><?php esc_html_e( 'Confidence:', 'opti-behavior' ); ?></strong> <?php echo esc_html( $opti_behavior_si_item['confidence'] ); ?>%
													<?php endif; ?>
												</div>
												<?php if ( ! empty( $opti_behavior_si_item['interpretation'] ) ) : ?>
													<div style="font-size: 13px; color: #3f3f46; margin-bottom: 8px; line-height: 1.45; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_si_item['interpretation'] ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $opti_behavior_si_item['evidence'] ) ) : ?>
													<div style="font-size: 12px; color: #52525b; margin-bottom: 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
														<strong><?php esc_html_e( 'Evidence:', 'opti-behavior' ); ?></strong>
														<?php echo esc_html( implode( ' • ', array_slice( $opti_behavior_si_item['evidence'], 0, 3 ) ) ); ?>
													</div>
												<?php endif; ?>
												<?php if ( ! empty( $opti_behavior_si_item['recommended_action'] ) ) : ?>
													<div style="font-size: 12px; color: #166534; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
														<strong><?php esc_html_e( 'Next action:', 'opti-behavior' ); ?></strong> <?php echo esc_html( $opti_behavior_si_item['recommended_action'] ); ?>
													</div>
												<?php endif; ?>
											</td>
										</tr>
									</table>
								<?php endforeach; ?>
							<?php else : ?>
								<p style="margin: 0; font-size: 13px; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $smart_insights['empty_message'] ?? __( 'No active Smart Insights detected for this report period.', 'opti-behavior' ) ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>
					<?php
					$opti_behavior_si_html = ob_get_clean();
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both blocks were escaped while buffered.
					echo $opti_behavior_si_first ? $opti_behavior_si_html . $opti_behavior_kpi_html : $opti_behavior_kpi_html . $opti_behavior_si_html;
					?>

					<?php if ( ! empty( $top_pages ) ) : ?>
					<!-- Top Pages Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Top Pages', 'opti-behavior' ); ?>
							</h2>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Page', 'opti-behavior' ); ?></th>
									<?php // Visits of the page, same figure as the Heatmaps list and the site report (OPT-2484 / OPT-2488). ?>
									<th class="ob-tp-n" style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Visits', 'opti-behavior' ); ?></th>
									<th class="ob-tp-n" style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Views', 'opti-behavior' ); ?></th>
									<th class="ob-tp-n" style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Time', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( array_slice( $top_pages, 0, 5 ) as $page ) : ?>
								<tr>
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; word-break: break-word; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php if ( ! empty( $page['page_id'] ) ) : ?>
											<?php // Same address as the page title link of the Heatmaps list (admin/class-opti-behavior-heatmap-dashboard.php) (OPT-1514). ?>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-heatmap-detail&page_id=' . (int) $page['page_id'] . '&type=click&device=desktop' ) ); ?>" style="color: #4f46e5; text-decoration: none;"><?php echo esc_html( $page['title'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $page['title'] ); ?>
										<?php endif; ?>
									</td>
									<td class="ob-tp-n" style="padding: 14px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( isset( $page['visits'] ) && null !== $page['visits'] ? number_format( (int) $page['visits'] ) : '—' ); ?>
									</td>
									<td class="ob-tp-n" style="padding: 14px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( number_format( $page['views'] ) ); ?>
									</td>
									<td class="ob-tp-n" style="padding: 14px 16px; text-align: right; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( $page['avg_time_formatted'] ?? '—' ); ?>
									</td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php echo wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'top_pages', $top_pages, $opti_behavior_hint_ctx ) ) ); ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php // Sources right after the top pages, then countries (design OPT-1646). ?>
					<?php if ( ! empty( $top_referrers ) ) : ?>
					<!-- Top Referrers Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Top Referrers', 'opti-behavior' ); ?>
							</h2>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Source', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 100px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Visits', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 60px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( '%', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( array_slice( $top_referrers, 0, 5 ) as $opti_behavior_ref ) : ?>
								<tr>
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; word-break: break-word; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( $opti_behavior_ref['source'] ); ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( number_format( $opti_behavior_ref['visits'] ) ); ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo isset( $opti_behavior_ref['share'] ) ? esc_html( number_format_i18n( (int) $opti_behavior_ref['share'] ) . '%' ) : '&#8212;'; ?>
									</td>
								</tr>
								<?php endforeach; ?>
							</table>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $traffic ) && ! empty( $traffic['breakdown'] ) ) : ?>
					<!-- Traffic Breakdown Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Traffic Breakdown', 'opti-behavior' ); ?>
							</h2>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Source', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Visits', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 60px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( '%', 'opti-behavior' ); ?></th>
								</tr>
								<?php
								// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
								$traffic_colors = array(
									'direct'   => '#4f46e5',
									'organic'  => '#16a34a',
									'referral' => '#0891b2',
									'social'   => '#ec4899',
									'email'    => '#f59e0b',
									'paid'     => '#dc2626',
								);
								// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
								foreach ( array_slice( $traffic['breakdown'], 0, 6 ) as $item ) :
									$type_color = $traffic_colors[ strtolower( $item['type'] ?? '' ) ] ?? '#6b7280';
									// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
								?>
								<tr>
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; word-break: break-word; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<span style="display: inline-block; width: 10px; height: 10px; background-color: <?php echo esc_attr( $type_color ); ?>; border-radius: 50%; margin-right: 10px; vertical-align: middle;"></span>
										<?php echo esc_html( ucfirst( $item['type'] ?? __( 'Unknown', 'opti-behavior' ) ) ); ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( number_format( $item['count'] ) ); ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( $item['percentage'] ?? 0 ); ?>%
									</td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php
							// Devices in % (OPT-1650): the Traffic screen device rows, as the PDF prints them.
							$opti_behavior_devices = isset( $report['site']['devices'] ) && is_array( $report['site']['devices'] ) ? $report['site']['devices'] : array();
							if ( ! empty( $opti_behavior_devices ) ) :
								?>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 14px; border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Device', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Visits', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 60px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( '%', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( array_slice( $opti_behavior_devices, 0, 4 ) as $opti_behavior_device ) : ?>
								<tr>
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; word-break: break-word; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( (string) ( $opti_behavior_device['label'] ?? '' ) ); ?></td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format_i18n( (int) ( $opti_behavior_device['count'] ?? 0 ) ) ); ?></td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format_i18n( (int) ( $opti_behavior_device['share'] ?? 0 ) ) . '%' ); ?></td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>
					<?php if ( ! empty( $geographic ) ) : ?>
					<!-- Geographic Distribution Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Geographic Distribution', 'opti-behavior' ); ?>
							</h2>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Country', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Visitors', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Sessions', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( array_slice( $geographic, 0, 5 ) as $opti_behavior_country ) : ?>
								<tr>
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; word-break: break-word; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( $opti_behavior_country['country_name'] ?? $opti_behavior_country['country'] ?? __( 'Unknown', 'opti-behavior' ) ); ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( number_format( $opti_behavior_country['visitors'] ) ); ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( number_format( $opti_behavior_country['sessions'] ) ); ?>
									</td>
								</tr>
								<?php endforeach; ?>
							</table>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $heatmap ) && ( ( $heatmap['total_clicks'] ?? 0 ) > 0 || ( $heatmap['pages_tracked'] ?? 0 ) > 0 ) ) : ?>
					<!-- Heatmap Summary Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Click Heatmap Summary', 'opti-behavior' ); ?>
							</h2>
							<?php // Most clicked pages, their most clicked element and their heatmap (OPT-1515). ?>
							<?php if ( ! empty( $heatmap['top_clicked'] ) ) : ?>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Page', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Clicks', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( array_slice( $heatmap['top_clicked'], 0, 3 ) as $opti_behavior_clicked ) : ?>
								<tr>
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; word-break: break-word; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php if ( ! empty( $opti_behavior_clicked['page_id'] ) ) : ?>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-heatmap-detail&page_id=' . (int) $opti_behavior_clicked['page_id'] . '&type=click&device=desktop' ) ); ?>" style="color: #4f46e5; text-decoration: none;"><?php echo esc_html( (string) $opti_behavior_clicked['title'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( (string) $opti_behavior_clicked['title'] ); ?>
										<?php endif; ?>
										<?php if ( ! empty( $opti_behavior_clicked['element'] ) ) : ?>
										<div style="margin-top: 4px; font-size: 12px; color: #71717a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
											<?php
											/* translators: %s: short label of the most clicked element of the page, e.g. "Add to cart" */
											echo esc_html( sprintf( __( 'Most clicked: %s', 'opti-behavior' ), $opti_behavior_clicked['element'] ) );
											?>
										</div>
										<?php endif; ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; border-bottom: 1px solid #e5e7eb; vertical-align: top; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( number_format( $opti_behavior_clicked['clicks'] ) ); ?>
									</td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php endif; ?>
							<p style="margin: 12px 0 0 0; font-size: 13px; color: #71717a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php
								/* translators: %s: total click count */
								echo esc_html( sprintf( __( 'Total Clicks: %s', 'opti-behavior' ), number_format( $heatmap['total_clicks'] ?? 0 ) ) );
								?>
							</p>
							<?php echo wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'heatmap_summary', $heatmap, $opti_behavior_hint_ctx ) ) ); ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $funnels ) && ( $funnels['total_funnels'] ?? 0 ) > 0 ) : ?>
					<!-- Funnel Performance Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Funnel Performance', 'opti-behavior' ); ?>
								<span style="font-size: 12px; font-weight: 400; color: #6b7280; margin-left: 8px;"><?php /* translators: %d: number of active funnels */ echo esc_html( sprintf( __( '%d active', 'opti-behavior' ), $funnels['total_funnels'] ) ); ?></span>
							</h2>
							<?php if ( ! empty( $funnels['funnels'] ) ) : ?>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
								<tr style="background-color: #f9fafb;">
									<td style="padding: 10px 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Funnel', 'opti-behavior' ); ?></td>
									<td style="padding: 10px 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Entries', 'opti-behavior' ); ?></td>
									<td style="padding: 10px 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Completions', 'opti-behavior' ); ?></td>
									<td style="padding: 10px 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Conv. Rate', 'opti-behavior' ); ?></td>
								</tr>
								<?php foreach ( $funnels['funnels'] as $opti_behavior_funnel ) : ?>
								<tr>
									<td style="padding: 10px 14px; font-size: 13px; color: #18181b; word-break: break-word; border-top: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_funnel['name'] ); ?></td>
									<td style="padding: 10px 14px; font-size: 13px; color: #18181b; border-top: 1px solid #f3f4f6; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_funnel['total_entries'] ) ); ?></td>
									<td style="padding: 10px 14px; font-size: 13px; color: #18181b; border-top: 1px solid #f3f4f6; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_funnel['completions'] ) ); ?></td>
									<td style="padding: 10px 14px; font-size: 13px; color: #18181b; border-top: 1px solid #f3f4f6; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php
										// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
										$conv_color = ( $opti_behavior_funnel['conversion_rate'] ?? 0 ) >= 50 ? '#15803d' : ( ( $opti_behavior_funnel['conversion_rate'] ?? 0 ) >= 20 ? '#a16207' : '#dc2626' );
										?>
										<span style="color: <?php echo esc_attr( $conv_color ); ?>; font-weight: 600;"><?php echo esc_html( number_format( $opti_behavior_funnel['conversion_rate'] ?? 0, 1 ) ); ?>%</span>
									</td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php else : ?>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
								<tr>
									<td style="padding: 24px; text-align: center;">
										<div style="font-size: 13px; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'No funnel activity in this period.', 'opti-behavior' ); ?></div>
									</td>
								</tr>
							</table>
							<?php endif; ?>
							<?php echo wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'funnels', $funnels, $opti_behavior_hint_ctx ) ) ); ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php
					// A/B Testing (Free, OPT-1650): the block of the report signals engine; no test = no block.
					$opti_behavior_ab = Opti_Behavior_Report_Mailer::signal_block( $report, 'ab_testing' );
					if ( $opti_behavior_ab && 'data' === $opti_behavior_ab['state'] && ! empty( $opti_behavior_ab['figures'] ) ) :
						?>
					<!-- A/B Testing Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_ab['title'] ); ?></h2>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<?php foreach ( $opti_behavior_ab['figures'] as $opti_behavior_fig ) : ?>
								<tr>
									<td style="padding: 12px 16px; font-size: 14px; color: #52525b; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_fig['label'] ); ?></td>
									<td style="padding: 12px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; word-break: break-word; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_fig['value'] ); ?></td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php echo wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'ab_testing', $opti_behavior_ab, $opti_behavior_hint_ctx ) ) ); ?>
							<?php if ( ! empty( $opti_behavior_ab['url'] ) ) : ?>
							<p style="margin: 12px 0 0 0; font-size: 13px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><a href="<?php echo esc_url( $opti_behavior_ab['url'] ); ?>" style="color: #4f46e5; text-decoration: none; font-weight: 600;"><?php esc_html_e( 'Open A/B Testing', 'opti-behavior' ); ?></a></p>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php
					// Shop block (OPT-1735), after A/B Testing (design OPT-1645, QA OPT-1651): numbers of this period, Pro "Money at stake" or the grey Pro line.
					if ( ! empty( $report['sections']['shop']['totals'] ) && class_exists( 'Opti_Behavior_Shop_Report' ) ) {
						$opti_behavior_shop = $report['sections']['shop'];
						echo Opti_Behavior_Shop_Report::render_block_html( $opti_behavior_shop, $opti_behavior_shop['pro'] ?? null, ! empty( $opti_behavior_shop['pro_line'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_block_html() and by the Pro plugin (filter contract).
					}
					?>

					<?php
					// Free: each Pro block = its title + one line + the trial page, never a figure (OPT-1647, design OPT-1646).
					if ( empty( $report['is_pro'] ) ) :
						$opti_behavior_locked = array(
							'recordings'    => __( 'Session Recordings', 'opti-behavior' ),
							'errors'        => __( 'JavaScript Errors', 'opti-behavior' ),
							'performance'   => __( 'Web Vitals', 'opti-behavior' ),
							'friction'      => __( 'Friction Events', 'opti-behavior' ),
							'broken_links'  => __( 'Broken Links', 'opti-behavior' ),
							'user_journeys' => __( 'User Journeys', 'opti-behavior' ),
							'forms'         => __( 'Form Analytics', 'opti-behavior' ),
						);
						foreach ( $opti_behavior_locked as $opti_behavior_lk => $opti_behavior_lt ) :
							?>
					<!-- Locked Pro block: <?php echo esc_html( $opti_behavior_lk ); ?> -->
					<tr>
						<td style="padding: 0 40px 10px;">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px;">
								<tr>
									<td style="padding: 12px 16px; <?php echo esc_attr( $opti_behavior_font ); ?>">
										<span style="font-size: 14px; font-weight: 700; color: #18181b;"><?php echo esc_html( $opti_behavior_lt ); ?></span>
										<span style="display: inline-block; margin-left: 4px; padding: 1px 6px; font-size: 10px; font-weight: 700; letter-spacing: 0.4px; color: #4338ca; background-color: #eef2ff; border: 1px solid #c7d2fe; border-radius: 999px; vertical-align: 1px;">PRO</span>
										<div style="margin-top: 4px; font-size: 13px; color: #52525b;"><?php esc_html_e( 'Available with Opti-Behavior Pro', 'opti-behavior' ); ?> &#183; <a href="<?php echo esc_url( Opti_Behavior_Report_Signals::trial_url( $opti_behavior_lk ) ); ?>" style="color: #4f46e5; text-decoration: none; font-weight: 600;"><?php esc_html_e( 'See the Pro trial', 'opti-behavior' ); ?></a></div>
									</td>
								</tr>
							</table>
						</td>
					</tr>
							<?php
						endforeach;
						?>
					<tr><td style="padding: 0 0 22px; font-size: 0; line-height: 0;">&nbsp;</td></tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $recordings ) && ( $recordings['total'] ?? 0 ) > 0 ) : ?>
					<!-- Session Recordings Stats (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Session Recordings', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td class="ob-col" width="25%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 24px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $recordings['total'] ) ); ?></div>
													<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Total', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td class="ob-col" width="25%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 24px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $recordings['watched'] ) ); ?></div>
													<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Watched', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td class="ob-col" width="25%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 24px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $recordings['unwatched'] ) ); ?></div>
													<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Unwatched', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td class="ob-col" width="25%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 24px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $recordings['avg_duration_formatted'] ); ?></div>
													<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Avg. Duration', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $errors ) && ( $errors['total'] ?? 0 ) > 0 ) : ?>
					<!-- Errors Section (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'JavaScript Errors', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td width="50%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef2f2" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
											<tr>
												<td style="padding: 24px; text-align: center;">
													<div style="font-size: 36px; font-weight: 700; color: #dc2626; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $errors['total'] ) ); ?></div>
													<div style="font-size: 12px; color: #991b1b; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Total Errors', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="50%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7" style="background-color: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;">
											<tr>
												<td style="padding: 24px; text-align: center;">
													<div style="font-size: 36px; font-weight: 700; color: #b45309; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $errors['unresolved'] ) ); ?></div>
													<div style="font-size: 12px; color: #92400e; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Unresolved', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
							<?php echo wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'errors', $errors, $opti_behavior_hint_ctx ) ) ); ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $performance ) ) : ?>
					<!-- Performance Section (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Web Vitals', 'opti-behavior' ); ?>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<?php
									// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
									$vitals = array(
										'lcp' => array(
											'label'  => 'LCP',
											'suffix' => 's',
											'good'   => 2.5,
										),
										'fid' => array(
											'label'  => 'FID',
											'suffix' => 'ms',
											'good'   => 100,
										),
										'cls' => array(
											'label'  => 'CLS',
											'suffix' => '',
											'good'   => 0.1,
										),
										'inp' => array(
											'label'  => 'INP',
											'suffix' => 'ms',
											'good'   => 200,
										),
									);
									// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
									foreach ( $vitals as $key => $config ) :
										$value        = $performance[ $key ] ?? 0;
										$status_color = $value <= $config['good'] ? '#15803d' : ( $value <= $config['good'] * 2 ? '#b45309' : '#dc2626' );
										// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
									?>
									<td class="ob-col" width="25%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $config['label'] ); ?></div>
													<div style="font-size: 22px; font-weight: 700; color: <?php echo esc_attr( $status_color ); ?>; margin-top: 6px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
														<?php echo esc_html( $value . $config['suffix'] ); ?>
													</div>
												</td>
											</tr>
										</table>
									</td>
									<?php endforeach; ?>
								</tr>
							</table>
							<?php echo wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'performance', $performance, $opti_behavior_hint_ctx ) ) ); ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $friction ) && ( $friction['total'] ?? 0 ) > 0 ) : ?>
					<!-- Friction Events Section (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Friction Events', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td class="ob-col" width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef2f2" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #dc2626; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $friction['total'] ) ); ?></div>
													<div style="font-size: 11px; color: #991b1b; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Total Events', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td class="ob-col" width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7" style="background-color: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #b45309; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $friction['rage_clicks'] ) ); ?></div>
													<div style="font-size: 11px; color: #92400e; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Rage Clicks', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td class="ob-col ob-col-wide" width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f4f6" style="background-color: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $friction['dead_clicks'] ) ); ?></div>
													<div style="font-size: 11px; color: #4b5563; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Dead Clicks', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $broken_links ) && ( $broken_links['total'] ?? 0 ) > 0 ) : ?>
					<!-- Broken Links Section (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Broken Links Report', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td class="ob-col" width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef2f2" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #dc2626; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $broken_links['total'] ) ); ?></div>
													<div style="font-size: 11px; color: #991b1b; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Total Found', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td class="ob-col" width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7" style="background-color: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #b45309; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $broken_links['open'] ) ); ?></div>
													<div style="font-size: 11px; color: #92400e; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Open', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td class="ob-col ob-col-wide" width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#dcfce7" style="background-color: #dcfce7; border: 1px solid #86efac; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #15803d; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $broken_links['fixed'] ) ); ?></div>
													<div style="font-size: 11px; color: #166534; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Fixed', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
							<?php echo wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'broken_links', $broken_links, $opti_behavior_hint_ctx ) ) ); ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $user_journeys ) && ( $user_journeys['total_sessions'] ?? 0 ) > 0 ) : ?>
					<!-- User Journeys Section (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'User Journeys', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td width="50%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 20px; text-align: center;">
													<div style="font-size: 32px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $user_journeys['total_sessions'] ) ); ?></div>
													<div style="font-size: 12px; color: #6b7280; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Sessions Analyzed', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="50%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 20px; text-align: center;">
													<div style="font-size: 32px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $user_journeys['avg_path_length'] ); ?></div>
													<div style="font-size: 12px; color: #6b7280; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Avg. Pages/Session', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
							<?php $opti_behavior_uj_depth = $user_journeys['depth'] ?? array(); ?>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 12px;">
								<tr>
									<td class="ob-col" width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_uj_depth['1_page'] ?? 0 ) ); ?></div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( '1 Page', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td class="ob-col" width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_uj_depth['2_3_pages'] ?? 0 ) ); ?></div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( '2-3 Pages', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td class="ob-col ob-col-wide" width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_uj_depth['4_plus_pages'] ?? 0 ) ); ?></div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( '4+ Pages', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
								</tr>
							</table>
							<?php if ( ! empty( $user_journeys['entry_pages'] ) ) : ?>
							<h3 style="margin: 16px 0 10px; font-size: 13px; font-weight: 600; color: #52525b; text-transform: uppercase; letter-spacing: 0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Top Entry Pages', 'opti-behavior' ); ?></h3>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Page', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Sessions', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( $user_journeys['entry_pages'] as $opti_behavior_uj_page ) : ?>
								<tr>
									<td style="padding: 10px 16px; font-size: 13px; color: #18181b; word-break: break-word; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_uj_page['name'] ?? $opti_behavior_uj_page['url'] ); ?></td>
									<td style="padding: 10px 16px; text-align: right; font-size: 13px; color: #18181b; font-weight: 600; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_uj_page['sessions'] ) ); ?></td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php endif; ?>
							<?php if ( ! empty( $user_journeys['exit_pages'] ) ) : ?>
							<h3 style="margin: 16px 0 10px; font-size: 13px; font-weight: 600; color: #52525b; text-transform: uppercase; letter-spacing: 0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Top Exit Pages', 'opti-behavior' ); ?></h3>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Page', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Sessions', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Exit Rate', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( $user_journeys['exit_pages'] as $opti_behavior_uj_page ) : ?>
								<tr>
									<td style="padding: 10px 16px; font-size: 13px; color: #18181b; word-break: break-word; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_uj_page['name'] ?? $opti_behavior_uj_page['url'] ); ?></td>
									<td style="padding: 10px 16px; text-align: right; font-size: 13px; color: #18181b; font-weight: 600; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_uj_page['sessions'] ) ); ?></td>
									<td style="padding: 10px 16px; text-align: right; font-size: 13px; color: #6b7280; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_uj_page['exit_rate'] ); ?>%</td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $form_analytics ) && ( $form_analytics['form_views'] ?? 0 ) > 0 ) : ?>
					<!-- Form Analytics Section (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">								<?php esc_html_e( 'Form Analytics', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td class="ob-col" width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $form_analytics['form_views'] ) ); ?></div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Views', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td class="ob-col" width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $form_analytics['submissions'] ) ); ?></div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Submitted', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td class="ob-col" width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $form_analytics['conversion_rate'] ); ?>%</div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Conv. Rate', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td class="ob-col" width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<?php $opti_behavior_fa_ct_fmt = $form_analytics['avg_completion_time_formatted'] ?? '—'; ?>
												<div style="font-size: 22px; font-weight: 700; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_fa_ct_fmt ); ?></div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Avg. Time', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td class="ob-col ob-col-wide" width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #dc2626; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $form_analytics['abandonments'] ) ); ?></div>
												<div style="font-size: 11px; color: #52525b; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Abandoned', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
								</tr>
							</table>
							<?php if ( ! empty( $form_analytics['top_forms'] ) ) : ?>
							<h3 style="margin: 16px 0 10px; font-size: 13px; font-weight: 600; color: #52525b; text-transform: uppercase; letter-spacing: 0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Top Forms by Conversion', 'opti-behavior' ); ?></h3>
							<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Form', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 70px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Views', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 70px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Conv.', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( $form_analytics['top_forms'] as $opti_behavior_fa_form ) : ?>
								<tr>
									<td style="padding: 10px 16px; font-size: 13px; color: #18181b; word-break: break-word; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( $opti_behavior_fa_form['form_name'] ?: $opti_behavior_fa_form['form_id'] ); ?>
										<?php if ( ! empty( $opti_behavior_fa_form['form_plugin'] ) ) : ?>
											<span style="font-size: 10px; color: #71717a;">(<?php echo esc_html( $opti_behavior_fa_form['form_plugin'] ); ?>)</span>
										<?php endif; ?>
									</td>
									<td style="padding: 10px 16px; text-align: right; font-size: 13px; color: #18181b; font-weight: 600; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_fa_form['form_views'] ) ); ?></td>
									<td style="padding: 10px 16px; text-align: right; font-size: 13px; color: #047857; font-weight: 600; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_fa_form['conversion_rate'] ); ?>%</td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php endif; ?>
							<?php echo wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'form_analytics', $form_analytics, $opti_behavior_hint_ctx ) ) ); ?>
						</td>
					</tr>
					<?php endif; ?>

					<!-- CTA Buttons: PDF report (primary, when the generator read it) + dashboard (design OPT-1646) -->
					<tr>
						<td style="padding: 8px 40px 32px; text-align: center;">
							<div class="ob-btns" style="font-size: 0; line-height: 0;">
								<?php if ( $opti_behavior_s && '' !== (string) ( $opti_behavior_s['report_url'] ?? '' ) ) : ?>
								<div class="ob-btn-cell" style="display: inline-block; vertical-align: top; padding: 0 6px 10px; font-size: 14px; line-height: 1.4;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;"><tr>
										<td class="ob-btn" bgcolor="#4f46e5" style="background-color: #4f46e5; border-radius: 10px;">
											<a href="<?php echo esc_url( $opti_behavior_s['report_url'] ); ?>" style="display: inline-block; padding: 15px 28px; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 600; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php esc_html_e( 'View the full report (PDF)', 'opti-behavior' ); ?></a>
										</td>
									</tr></table>
								</div>
								<div class="ob-btn-cell" style="display: inline-block; vertical-align: top; padding: 0 6px 10px; font-size: 14px; line-height: 1.4;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;"><tr>
										<td class="ob-btn2" style="border: 1px solid #c7d2fe; border-radius: 10px;">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-analytics' ) ); ?>" style="display: inline-block; padding: 14px 26px; color: #4f46e5; text-decoration: none; font-size: 14px; font-weight: 600; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php esc_html_e( 'View Full Dashboard', 'opti-behavior' ); ?></a>
										</td>
									</tr></table>
								</div>
								<?php else : ?>
								<div class="ob-btn-cell" style="display: inline-block; vertical-align: top; padding: 0 6px 10px; font-size: 14px; line-height: 1.4;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;"><tr>
										<td class="ob-btn" bgcolor="#4f46e5" style="background-color: #4f46e5; border-radius: 10px;">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-analytics' ) ); ?>" style="display: inline-block; padding: 15px 28px; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 600; <?php echo esc_attr( $opti_behavior_font ); ?>"><?php esc_html_e( 'View Full Dashboard', 'opti-behavior' ); ?></a>
										</td>
									</tr></table>
								</div>
								<?php endif; ?>
							</div>
						</td>
					</tr>

					<?php
					// Pro line, Free only (OPT-1517).
					$opti_behavior_pro_line = Opti_Behavior_Report_Mailer::pro_line( $report );
					if ( '' !== $opti_behavior_pro_line ) :
						?>
					<tr>
						<td style="padding: 0 40px 28px; text-align: center; font-size: 13px; line-height: 1.5; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
							<?php echo esc_html( $opti_behavior_pro_line ); ?>
							<a href="<?php echo esc_url( Opti_Behavior_Report_Mailer::PRO_LINE_URL ); ?>" style="color: #4f46e5; text-decoration: none; font-weight: 500;"><?php esc_html_e( 'See what Pro adds', 'opti-behavior' ); ?></a>
						</td>
					</tr>
					<?php endif; ?>

					<!-- Footer -->
					<tr>
						<td bgcolor="#f9fafb" style="background-color: #f9fafb; padding: 28px 40px; text-align: center; border-top: 1px solid #e5e7eb;">
							<p style="margin: 0 0 8px 0; font-size: 13px; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php esc_html_e( 'This report was automatically generated by', 'opti-behavior' ); ?> <strong style="color: #18181b;">Opti-Behavior</strong>
							</p>
							<?php
							// Why this address receives the report: this recipient only (OPT-1517).
							$opti_behavior_recipient_line = Opti_Behavior_Report_Mailer::recipient_line( $report, $schedule );
							if ( '' !== $opti_behavior_recipient_line ) :
								?>
							<p style="margin: 0 0 8px 0; font-size: 12px; line-height: 1.5; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php echo esc_html( $opti_behavior_recipient_line ); ?>
							</p>
							<?php endif; ?>
							<p style="margin: 0; font-size: 12px; color: #71717a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=scheduled-reports' ) ); ?>"
								   style="color: #4f46e5; text-decoration: none; font-weight: 500;">
									<?php esc_html_e( 'Manage report settings', 'opti-behavior' ); ?>
								</a>
								&#8226;
								<a href="<?php echo esc_url( home_url() ); ?>" style="color: #6b7280; text-decoration: none;">
									<?php echo esc_html( $report['site_name'] ); ?>
								</a>
							</p>
						</td>
					</tr>
				</table>
				<!--[if mso]></td></tr></table><![endif]-->
			</td>
		</tr>
	</table>
</body>
</html>
