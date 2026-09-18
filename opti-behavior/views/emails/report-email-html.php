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

/**
 * Get arrow indicator based on direction (using text arrows for email compatibility)
 *
 * Guarded: this template is included once per rendered report, so a worker that
 * sends several due schedules in one PHP process would otherwise hit a fatal
 * "Cannot redeclare opti_get_change_html()" on the second report.
 */
if ( ! function_exists( 'opti_get_change_html' ) ) {
	/**
	 * Get arrow indicator based on direction.
	 *
	 * @param array $change Change descriptor (value, direction, positive).
	 * @return string HTML markup, or an empty string when there is nothing to show.
	 */
	function opti_get_change_html( $change ) {
		if ( empty( $change['value'] ) ) {
			return '';
		}

		$direction   = $change['direction'] ?? 'neutral';
		$is_positive = $change['positive'] ?? null;

		if ( 'up' === $direction ) {
			$arrow = '&#9650;'; // ▲
			$color = true === $is_positive ? '#16a34a' : '#dc2626';
		} elseif ( 'down' === $direction ) {
			$arrow = '&#9660;'; // ▼
			$color = false === $is_positive ? '#16a34a' : '#dc2626';
		} else {
			return '';
		}

		return sprintf(
			'<div style="font-size: 12px; color: %s; margin-top: 4px;">%s %s%%</div>',
			esc_attr( $color ),
			$arrow,
			esc_html( $change['value'] )
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
	<title><?php echo esc_html( $schedule['name'] ); ?></title>
	<!--[if mso]>
	<style type="text/css">
		table { border-collapse: collapse; }
		.button { padding: 12px 24px !important; }
	</style>
	<![endif]-->
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f4f4f5; -webkit-font-smoothing: antialiased;">
	<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f5;">
		<tr>
			<td align="center" style="padding: 40px 20px;">
				<table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">
					<!-- Header -->
					<tr>
						<td bgcolor="#4f46e5" style="background-color: #4f46e5; padding: 40px; text-align: center;">
							<h1 style="color: #ffffff; margin: 0 0 8px 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php echo esc_html( $schedule['name'] ); ?>
							</h1>
							<p style="color: #e0e7ff; margin: 0; font-size: 15px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php echo esc_html( $report['period_label'] ); ?> &#8226; <?php echo esc_html( $report['site_name'] ); ?>
							</p>
						</td>
					</tr>

					<?php if ( ! empty( $kpis ) ) : ?>
					<!-- KPIs Section -->
					<tr>
						<td style="padding: 32px 40px;">
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
									</td>
								</tr>
							</table>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<?php
									// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
									$kpi_items = array(
										'visitors'  => array(
											'label' => __( 'Visitors', 'opti-behavior' ),
											'icon'  => '&#128100;', // 👤
											'color' => '#4f46e5',
										),
										'sessions'  => array(
											'label' => __( 'Sessions', 'opti-behavior' ),
											'icon'  => '&#128202;', // 📊
											'color' => '#0891b2',
										),
										'pageviews' => array(
											'label' => __( 'Page Views', 'opti-behavior' ),
											'icon'  => '&#128196;', // 📄
											'color' => '#059669',
										),
									);
									// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
									foreach ( $kpi_items as $key => $config ) :
										if ( ! isset( $kpis[ $key ] ) ) {
											continue;
										}
										// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
										$kpi = $kpis[ $key ];
									?>
									<td width="33%" style="padding: 6px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;">
											<tr>
												<td style="padding: 20px; text-align: center;">
													<div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
														<span style="color: <?php echo esc_attr( $config['color'] ); ?>;"><?php echo wp_kses_post( $config['icon'] ); ?></span>
														<?php echo esc_html( $config['label'] ); ?>
													</div>
													<div style="font-size: 32px; font-weight: 700; color: <?php echo esc_attr( $config['color'] ); ?>; line-height: 1.2; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
														<?php echo esc_html( number_format( $kpi['value'] ) ); ?>
													</div>
													<?php echo wp_kses_post( opti_get_change_html( $kpi['change'] ?? array() ) ); ?>
												</td>
											</tr>
										</table>
									</td>
									<?php endforeach; ?>
								</tr>
								<tr>
									<?php
									// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
									$kpi_items2 = array(
										'bounce_rate'      => array(
											'label'  => __( 'Bounce Rate', 'opti-behavior' ),
											'icon'   => '&#8617;', // ↩
											'suffix' => '%',
											'color'  => '#dc2626',
										),
										'avg_session_time' => array(
											'label'     => __( 'Avg. Time', 'opti-behavior' ),
											'icon'      => '&#9201;', // ⏱
											'formatted' => true,
											'color'     => '#7c3aed',
										),
										'avg_scroll_depth' => array(
											'label'  => __( 'Scroll Depth', 'opti-behavior' ),
											'icon'   => '&#8595;', // ↓
											'suffix' => '%',
											'color'  => '#0891b2',
										),
									);
									// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
									foreach ( $kpi_items2 as $key => $config ) :
										if ( ! isset( $kpis[ $key ] ) ) {
											continue;
										}
										$kpi   = $kpis[ $key ];
										$value = isset( $config['formatted'] ) && $config['formatted'] && isset( $kpi['formatted'] )
										// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
											? $kpi['formatted']
											: $kpi['value'] . ( $config['suffix'] ?? '' );
									?>
									<td width="33%" style="padding: 6px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;">
											<tr>
												<td style="padding: 20px; text-align: center;">
													<div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
														<span style="color: <?php echo esc_attr( $config['color'] ); ?>;"><?php echo wp_kses_post( $config['icon'] ); ?></span>
														<?php echo esc_html( $config['label'] ); ?>
													</div>
													<div style="font-size: 32px; font-weight: 700; color: <?php echo esc_attr( $config['color'] ); ?>; line-height: 1.2; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
														<?php echo esc_html( $value ); ?>
													</div>
													<?php echo wp_kses_post( opti_get_change_html( $kpi['change'] ?? array() ) ); ?>
												</td>
											</tr>
										</table>
									</td>
									<?php endforeach; ?>
								</tr>
							</table>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $smart_insights ) ) : ?>
					<!-- Smart Insights Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #7c3aed;">&#10024;</span>
								<?php esc_html_e( 'Smart Insights', 'opti-behavior' ); ?>
							</h2>
							<p style="margin: 0 0 14px 0; font-size: 13px; color: #52525b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php
								printf(
									/* translators: %d: insight count */
									esc_html__( '%d active insight(s) detected for this report period.', 'opti-behavior' ),
									(int) ( $smart_insights['count'] ?? 0 )
								);
								?>
							</p>
							<?php if ( ! empty( $smart_insights['insights'] ) ) : ?>
								<?php foreach ( array_slice( $smart_insights['insights'], 0, 3 ) as $opti_behavior_si_item ) : ?>
									<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #faf5ff; border: 1px solid #e9d5ff; border-radius: 8px; margin-bottom: 10px;">
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
								<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
									<tr><td style="padding: 16px; font-size: 13px; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $smart_insights['empty_message'] ?? __( 'No active Smart Insights detected for this report period.', 'opti-behavior' ) ); ?></td></tr>
								</table>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $top_pages ) ) : ?>
					<!-- Top Pages Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #4f46e5;">&#128196;</span>
								<?php esc_html_e( 'Top Pages', 'opti-behavior' ); ?>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Page', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Views', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Time', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( array_slice( $top_pages, 0, 5 ) as $page ) : ?>
								<tr>
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( mb_strimwidth( $page['title'], 0, 45, '...' ) ); ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( number_format( $page['views'] ) ); ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( $page['avg_time_formatted'] ?? '—' ); ?>
									</td>
								</tr>
								<?php endforeach; ?>
							</table>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $top_referrers ) ) : ?>
					<!-- Top Referrers Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #0891b2;">&#128279;</span>
								<?php esc_html_e( 'Top Referrers', 'opti-behavior' ); ?>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Source', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 100px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Visits', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( array_slice( $top_referrers, 0, 5 ) as $opti_behavior_ref ) : ?>
								<tr>
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( $opti_behavior_ref['source'] ); ?>
									</td>
									<td style="padding: 14px 16px; text-align: right; font-size: 14px; color: #18181b; font-weight: 600; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( number_format( $opti_behavior_ref['visits'] ) ); ?>
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
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #7c3aed;">&#128202;</span>
								<?php esc_html_e( 'Traffic Breakdown', 'opti-behavior' ); ?>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
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
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
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
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $geographic ) ) : ?>
					<!-- Geographic Distribution Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #059669;">&#127760;</span>
								<?php esc_html_e( 'Geographic Distribution', 'opti-behavior' ); ?>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 14px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Country', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Visitors', 'opti-behavior' ); ?></th>
									<th style="padding: 14px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Sessions', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( array_slice( $geographic, 0, 5 ) as $opti_behavior_country ) : ?>
								<tr>
									<td style="padding: 14px 16px; font-size: 14px; color: #18181b; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
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
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #f59e0b;">&#128293;</span>
								<?php esc_html_e( 'Click Heatmap Summary', 'opti-behavior' ); ?>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td width="50%" style="padding-right: 8px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7" style="background-color: #fef3c7; border-radius: 8px;">
											<tr>
												<td style="padding: 24px; text-align: center;">
													<div style="font-size: 36px; font-weight: 700; color: #92400e; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $heatmap['total_clicks'] ?? 0 ) ); ?></div>
													<div style="font-size: 12px; color: #78350f; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Total Clicks', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="50%" style="padding-left: 8px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#e0e7ff" style="background-color: #e0e7ff; border-radius: 8px;">
											<tr>
												<td style="padding: 24px; text-align: center;">
													<div style="font-size: 36px; font-weight: 700; color: #3730a3; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $heatmap['pages_tracked'] ?? 0 ) ); ?></div>
													<div style="font-size: 12px; color: #312e81; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Pages Tracked', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $funnels ) && ( $funnels['total_funnels'] ?? 0 ) > 0 ) : ?>
					<!-- Funnel Performance Section -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #6366f1;">&#9660;</span>
								<?php esc_html_e( 'Funnel Performance', 'opti-behavior' ); ?>
								<span style="font-size: 12px; font-weight: 400; color: #6b7280; margin-left: 8px;"><?php /* translators: %d: number of active funnels */ echo esc_html( sprintf( __( '%d active', 'opti-behavior' ), $funnels['total_funnels'] ) ); ?></span>
							</h2>
							<?php if ( ! empty( $funnels['funnels'] ) ) : ?>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
								<tr style="background-color: #f9fafb;">
									<td style="padding: 10px 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Funnel', 'opti-behavior' ); ?></td>
									<td style="padding: 10px 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Entries', 'opti-behavior' ); ?></td>
									<td style="padding: 10px 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Completions', 'opti-behavior' ); ?></td>
									<td style="padding: 10px 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Conv. Rate', 'opti-behavior' ); ?></td>
								</tr>
								<?php foreach ( $funnels['funnels'] as $opti_behavior_funnel ) : ?>
								<tr>
									<td style="padding: 10px 14px; font-size: 13px; color: #18181b; border-top: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( mb_strimwidth( $opti_behavior_funnel['name'], 0, 40, '...' ) ); ?></td>
									<td style="padding: 10px 14px; font-size: 13px; color: #18181b; border-top: 1px solid #f3f4f6; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_funnel['total_entries'] ) ); ?></td>
									<td style="padding: 10px 14px; font-size: 13px; color: #18181b; border-top: 1px solid #f3f4f6; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_funnel['completions'] ) ); ?></td>
									<td style="padding: 10px 14px; font-size: 13px; color: #18181b; border-top: 1px solid #f3f4f6; text-align: right; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php
										// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
										$conv_color = ( $opti_behavior_funnel['conversion_rate'] ?? 0 ) >= 50 ? '#16a34a' : ( ( $opti_behavior_funnel['conversion_rate'] ?? 0 ) >= 20 ? '#ca8a04' : '#dc2626' );
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
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $recordings ) && ( $recordings['total'] ?? 0 ) > 0 ) : ?>
					<!-- Session Recordings Stats (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #ec4899;">&#127909;</span>
								<?php esc_html_e( 'Session Recordings', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td width="25%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 24px; font-weight: 700; color: #4f46e5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $recordings['total'] ) ); ?></div>
													<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Total', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="25%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 24px; font-weight: 700; color: #16a34a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $recordings['watched'] ) ); ?></div>
													<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Watched', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="25%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 24px; font-weight: 700; color: #f59e0b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $recordings['unwatched'] ) ); ?></div>
													<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Unwatched', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="25%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 24px; font-weight: 700; color: #7c3aed; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $recordings['avg_duration_formatted'] ); ?></div>
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
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #dc2626;">&#9888;</span>
								<?php esc_html_e( 'JavaScript Errors', 'opti-behavior' ); ?>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td width="50%" style="padding-right: 8px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef2f2" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
											<tr>
												<td style="padding: 24px; text-align: center;">
													<div style="font-size: 36px; font-weight: 700; color: #dc2626; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $errors['total'] ) ); ?></div>
													<div style="font-size: 12px; color: #991b1b; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Total Errors', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="50%" style="padding-left: 8px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7" style="background-color: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;">
											<tr>
												<td style="padding: 24px; text-align: center;">
													<div style="font-size: 36px; font-weight: 700; color: #d97706; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $errors['unresolved'] ) ); ?></div>
													<div style="font-size: 12px; color: #92400e; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Unresolved', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $performance ) ) : ?>
					<!-- Performance Section (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #f59e0b;">&#9889;</span>
								<?php esc_html_e( 'Web Vitals', 'opti-behavior' ); ?>
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
										$status_color = $value <= $config['good'] ? '#16a34a' : ( $value <= $config['good'] * 2 ? '#d97706' : '#dc2626' );
										// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
									?>
									<td width="25%" style="padding: 4px;">
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
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $friction ) && ( $friction['total'] ?? 0 ) > 0 ) : ?>
					<!-- Friction Events Section (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #dc2626;">&#128544;</span>
								<?php esc_html_e( 'Friction Events', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef2f2" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #dc2626; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $friction['total'] ) ); ?></div>
													<div style="font-size: 11px; color: #991b1b; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Total Events', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7" style="background-color: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #d97706; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $friction['rage_clicks'] ) ); ?></div>
													<div style="font-size: 11px; color: #92400e; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Rage Clicks', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="33%" style="padding: 4px;">
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
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<span style="color: #dc2626;">&#128279;</span>
								<?php esc_html_e( 'Broken Links Report', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef2f2" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #dc2626; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $broken_links['total'] ) ); ?></div>
													<div style="font-size: 11px; color: #991b1b; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Total Found', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7" style="background-color: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #d97706; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $broken_links['open'] ) ); ?></div>
													<div style="font-size: 11px; color: #92400e; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Open', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#dcfce7" style="background-color: #dcfce7; border: 1px solid #86efac; border-radius: 8px;">
											<tr>
												<td style="padding: 16px; text-align: center;">
													<div style="font-size: 28px; font-weight: 700; color: #16a34a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $broken_links['fixed'] ) ); ?></div>
													<div style="font-size: 11px; color: #166534; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Fixed', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( $report['is_pro'] && ! empty( $user_journeys ) && ( $user_journeys['total_sessions'] ?? 0 ) > 0 ) : ?>
					<!-- User Journeys Section (Pro) -->
					<tr>
						<td style="padding: 0 40px 32px;">
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								&#128736;
								<?php esc_html_e( 'User Journeys', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td width="50%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px;">
											<tr>
												<td style="padding: 20px; text-align: center;">
													<div style="font-size: 32px; font-weight: 700; color: #4338ca; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $user_journeys['total_sessions'] ) ); ?></div>
													<div style="font-size: 12px; color: #4338ca; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Sessions Analyzed', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
									<td width="50%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;">
											<tr>
												<td style="padding: 20px; text-align: center;">
													<div style="font-size: 32px; font-weight: 700; color: #166534; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $user_journeys['avg_path_length'] ); ?></div>
													<div style="font-size: 12px; color: #166534; margin-top: 6px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Avg. Pages/Session', 'opti-behavior' ); ?></div>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
							<?php $opti_behavior_uj_depth = $user_journeys['depth'] ?? array(); ?>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 12px;">
								<tr>
									<td width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_uj_depth['1_page'] ?? 0 ) ); ?></div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( '1 Page', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #4f46e5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_uj_depth['2_3_pages'] ?? 0 ) ); ?></div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( '2-3 Pages', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td width="33%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #16a34a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_uj_depth['4_plus_pages'] ?? 0 ) ); ?></div>
												<div style="font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( '4+ Pages', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
								</tr>
							</table>
							<?php if ( ! empty( $user_journeys['entry_pages'] ) ) : ?>
							<h3 style="margin: 16px 0 10px; font-size: 13px; font-weight: 600; color: #52525b; text-transform: uppercase; letter-spacing: 0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Top Entry Pages', 'opti-behavior' ); ?></h3>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Page', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Sessions', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( $user_journeys['entry_pages'] as $opti_behavior_uj_page ) : ?>
								<tr>
									<td style="padding: 10px 16px; font-size: 13px; color: #18181b; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( mb_strimwidth( $opti_behavior_uj_page['name'] ?? $opti_behavior_uj_page['url'], 0, 45, '...' ) ); ?></td>
									<td style="padding: 10px 16px; text-align: right; font-size: 13px; color: #18181b; font-weight: 600; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_uj_page['sessions'] ) ); ?></td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php endif; ?>
							<?php if ( ! empty( $user_journeys['exit_pages'] ) ) : ?>
							<h3 style="margin: 16px 0 10px; font-size: 13px; font-weight: 600; color: #52525b; text-transform: uppercase; letter-spacing: 0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Top Exit Pages', 'opti-behavior' ); ?></h3>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Page', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Sessions', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 80px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Exit Rate', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( $user_journeys['exit_pages'] as $opti_behavior_uj_page ) : ?>
								<tr>
									<td style="padding: 10px 16px; font-size: 13px; color: #18181b; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( mb_strimwidth( $opti_behavior_uj_page['name'] ?? $opti_behavior_uj_page['url'], 0, 45, '...' ) ); ?></td>
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
							<h2 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #18181b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								&#128221;
								<?php esc_html_e( 'Form Analytics', 'opti-behavior' ); ?>
								<span style="display: inline-block; background-color: #4f46e5; color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; vertical-align: middle; font-weight: 600;">PRO</span>
							</h2>
							<table width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #4f46e5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $form_analytics['form_views'] ) ); ?></div>
												<div style="font-size: 10px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Views', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #16a34a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $form_analytics['submissions'] ) ); ?></div>
												<div style="font-size: 10px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Submitted', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #059669; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $form_analytics['conversion_rate'] ); ?>%</div>
												<div style="font-size: 10px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Conv. Rate', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<?php $opti_behavior_fa_ct_fmt = $form_analytics['avg_completion_time_formatted'] ?? '—'; ?>
												<div style="font-size: 22px; font-weight: 700; color: #7c3aed; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_fa_ct_fmt ); ?></div>
												<div style="font-size: 10px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Avg. Time', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
									<td width="20%" style="padding: 4px;">
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
											<tr><td style="padding: 14px; text-align: center;">
												<div style="font-size: 22px; font-weight: 700; color: #dc2626; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $form_analytics['abandonments'] ) ); ?></div>
												<div style="font-size: 10px; color: #6b7280; margin-top: 4px; font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Abandoned', 'opti-behavior' ); ?></div>
											</td></tr>
										</table>
									</td>
								</tr>
							</table>
							<?php if ( ! empty( $form_analytics['top_forms'] ) ) : ?>
							<h3 style="margin: 16px 0 10px; font-size: 13px; font-weight: 600; color: #52525b; text-transform: uppercase; letter-spacing: 0.5px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Top Forms by Conversion', 'opti-behavior' ); ?></h3>
							<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">
								<tr style="background-color: #f9fafb;">
									<th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Form', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 70px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Views', 'opti-behavior' ); ?></th>
									<th style="padding: 10px 16px; text-align: right; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; width: 70px; border-bottom: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php esc_html_e( 'Conv.', 'opti-behavior' ); ?></th>
								</tr>
								<?php foreach ( $form_analytics['top_forms'] as $opti_behavior_fa_form ) : ?>
								<tr>
									<td style="padding: 10px 16px; font-size: 13px; color: #18181b; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
										<?php echo esc_html( mb_strimwidth( $opti_behavior_fa_form['form_name'] ?: $opti_behavior_fa_form['form_id'], 0, 35, '...' ) ); ?>
										<?php if ( ! empty( $opti_behavior_fa_form['form_plugin'] ) ) : ?>
											<span style="font-size: 10px; color: #a1a1aa;">(<?php echo esc_html( $opti_behavior_fa_form['form_plugin'] ); ?>)</span>
										<?php endif; ?>
									</td>
									<td style="padding: 10px 16px; text-align: right; font-size: 13px; color: #18181b; font-weight: 600; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( number_format( $opti_behavior_fa_form['form_views'] ) ); ?></td>
									<td style="padding: 10px 16px; text-align: right; font-size: 13px; color: #059669; font-weight: 600; border-bottom: 1px solid #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;"><?php echo esc_html( $opti_behavior_fa_form['conversion_rate'] ); ?>%</td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>

					<!-- CTA Button -->
					<tr>
						<td style="padding: 0 40px 32px; text-align: center;">
							<table cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;">
								<tr>
									<td bgcolor="#4f46e5" style="background-color: #4f46e5; border-radius: 8px;">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-analytics' ) ); ?>"
										   style="display: inline-block; padding: 16px 32px; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 600; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
											&#128202; <?php esc_html_e( 'View Full Dashboard', 'opti-behavior' ); ?>
										</a>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<!-- Footer -->
					<tr>
						<td bgcolor="#f9fafb" style="background-color: #f9fafb; padding: 28px 40px; text-align: center; border-top: 1px solid #e5e7eb;">
							<p style="margin: 0 0 8px 0; font-size: 13px; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
								<?php esc_html_e( 'This report was automatically generated by', 'opti-behavior' ); ?> <strong style="color: #18181b;">Opti-Behavior</strong>
							</p>
							<p style="margin: 0; font-size: 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
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
			</td>
		</tr>
	</table>
</body>
</html>
