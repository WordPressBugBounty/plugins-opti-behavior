<?php
/**
 * Dashboard View Helpers
 *
 * Procedural view helpers for rendering dashboard page.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'opti_behavior_render_widget_loading' ) ) {
	/**
	 * Render loading overlay for a widget.
	 *
	 * @since 1.0.3
	 */
	function opti_behavior_render_widget_loading() {
		?>
		<div class="widget-loading-overlay">
			<div class="widget-spinner"></div>
		</div>
		<?php
	}
}

if ( ! function_exists( 'opti_behavior_views_render_dashboard' ) ) {
	/**
	 * Render dashboard page wrapper.
	 *
	 * @since 1.0.0
	 * @param object $self Dashboard instance.
	 */
	function opti_behavior_views_render_dashboard( $self ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameters for filtering display data
		$period = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : 'last7days';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameters for filtering display data
		$start_q = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameters for filtering display data
		$end_q = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : null;

		// For async loading: only fetch stats and date range on initial load
		// Widgets will load their data via AJAX for better performance with large datasets
		$dashboard_data = $self->get_dashboard_stats_only( $period, $start_q, $end_q );
		?>
		<div class="wrap opti-behavior-dashboard-page">
			<?php opti_behavior_views_render_dashboard_header( $self, $dashboard_data['date_range'], $period ); ?>
			<?php opti_behavior_views_render_dashboard_stats( $self, $dashboard_data['stats'], $dashboard_data['changes'] ); ?>
			<?php opti_behavior_views_render_dashboard_content( $self, $dashboard_data ); ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'opti_behavior_views_render_dashboard_header' ) ) {
	/**
	 * Render dashboard page header with controls.
	 *
	 * @since 1.0.0
	 * @param object      $self       Dashboard instance.
	 * @param array|null  $date_range Date range array.
	 * @param string      $period     Selected period.
	 */
	function opti_behavior_views_render_dashboard_header( $self, $date_range = null, $period = 'last30days' ) {
		$start_val = $date_range && isset( $date_range['start'] ) ? substr( $date_range['start'], 0, 10 ) : '';
		$end_val   = $date_range && isset( $date_range['end'] ) ? substr( $date_range['end'], 0, 10 ) : '';
		?>
		<div class="dashboard-header">
			<div class="dashboard-title-section">
				<div class="dashboard-icon"><i data-lucide="bar-chart-3"></i></div>
				<div class="dashboard-title-text">
					<h1 class="dashboard-title"><?php echo esc_html__( 'Analytics Dashboard', 'opti-behavior' ); ?></h1>
					<div class="dashboard-subtitle">
						<span class="subtitle-text"><?php echo esc_html__( 'Real-time insights and user behavior analytics', 'opti-behavior' ); ?></span>
					</div>
				</div>
			</div>
			<div class="dashboard-controls">
				<select class="period-selector" id="dashboard-period">
					<option value="today" <?php selected( $period, 'today' ); ?>><?php echo esc_html__( 'Today', 'opti-behavior' ); ?></option>
					<option value="yesterday" <?php selected( $period, 'yesterday' ); ?>><?php echo esc_html__( 'Yesterday', 'opti-behavior' ); ?></option>
					<option value="last7days" <?php selected( $period, 'last7days' ); ?>><?php echo esc_html__( 'Last 7 Days', 'opti-behavior' ); ?></option>
					<option value="last30days" <?php selected( $period, 'last30days' ); ?>><?php echo esc_html__( 'Last 30 Days', 'opti-behavior' ); ?></option>
					<option value="thismonth" <?php selected( $period, 'thismonth' ); ?>><?php echo esc_html__( 'This Month', 'opti-behavior' ); ?></option>
					<option value="custom" <?php selected( $period, 'custom' ); ?>><?php echo esc_html__( 'Custom Range', 'opti-behavior' ); ?></option>
				</select>
				<input type="date" id="start-date" value="<?php echo esc_attr( $start_val ); ?>" />
				<input type="date" id="end-date" value="<?php echo esc_attr( $end_val ); ?>" />
				<button class="refresh-btn" id="apply-range">
					<i data-lucide="calendar"></i>
					<?php echo esc_html__( 'Apply', 'opti-behavior' ); ?>
				</button>
				<button class="refresh-btn" id="refresh-dashboard">
					<i data-lucide="refresh-cw"></i>
					<?php echo esc_html__( 'Refresh', 'opti-behavior' ); ?>
				</button>
			</div>
		</div>
		<?php
	}
}

if ( ! function_exists( 'opti_behavior_views_render_dashboard_stats' ) ) {
	/**
	 * Render dashboard statistics cards.
	 *
	 * @since 1.0.0
	 * @param object $self    Dashboard instance.
	 * @param array  $stats   Statistics data.
	 * @param array  $changes Change percentages.
	 */
	function opti_behavior_views_render_dashboard_stats( $self, $stats, $changes ) {
		$fmt = function ( $delta ) {
			$sign = ( $delta > 0 ? '+' : ( $delta < 0 ? '' : '' ) );
			/* translators: %s: percentage change value with sign */
			return sprintf( esc_html__( '%s%% vs last period', 'opti-behavior' ), $sign . (int) round( $delta ) );
		};
		$cls = function ( $delta ) {
			return $delta > 0 ? 'positive' : ( $delta < 0 ? 'negative' : 'neutral' );
		};
		?>
		<div class="dashboard-stats">
			<div class="stat-card" data-stat="visitors">
				<div class="stat-icon">👥</div>
				<div class="stat-content">
					<div class="stat-value"><span class="stat-value-spinner"></span></div>
					<div class="stat-label"><?php echo esc_html__( 'Visitors', 'opti-behavior' ); ?></div>
					<div class="stat-change neutral"><?php echo esc_html__( '0% vs last period', 'opti-behavior' ); ?></div>
					<div class="stat-history">
						<canvas id="history-visitors" width="280" height="50"></canvas>
					</div>
				</div>
			</div>
			<div class="stat-card" data-stat="sessions">
				<div class="stat-icon">🔗</div>
				<div class="stat-content">
					<div class="stat-value"><span class="stat-value-spinner"></span></div>
					<div class="stat-label"><?php echo esc_html__( 'Sessions', 'opti-behavior' ); ?></div>
					<div class="stat-change neutral"><?php echo esc_html__( '0% vs last period', 'opti-behavior' ); ?></div>
					<div class="stat-history">
						<canvas id="history-sessions" width="280" height="50"></canvas>
					</div>
				</div>
			</div>
			<div class="stat-card" data-stat="pageviews">
				<div class="stat-icon">📄</div>
				<div class="stat-content">
					<div class="stat-value"><span class="stat-value-spinner"></span></div>
					<div class="stat-label"><?php echo esc_html__( 'Page Views', 'opti-behavior' ); ?></div>
					<div class="stat-change neutral"><?php echo esc_html__( '0% vs last period', 'opti-behavior' ); ?></div>
					<div class="stat-history">
						<canvas id="history-pageviews" width="280" height="50"></canvas>
					</div>
				</div>
			</div>
			<div class="stat-card" data-stat="avg_session_time">
				<div class="stat-icon">⏱️</div>
				<div class="stat-content">
					<div class="stat-value"><span class="stat-value-spinner"></span></div>
					<div class="stat-label"><?php echo esc_html__( 'Avg. Session Time', 'opti-behavior' ); ?></div>
					<div class="stat-change neutral"><?php echo esc_html__( '0% vs last period', 'opti-behavior' ); ?></div>
					<div class="stat-history">
						<canvas id="history-avg-session-time" width="280" height="50"></canvas>
					</div>
				</div>
			</div>
			<div class="stat-card" data-stat="avg_scroll_depth">
				<div class="stat-icon">📜</div>
				<div class="stat-content">
					<div class="stat-value"><span class="stat-value-spinner"></span></div>
					<div class="stat-label"><?php echo esc_html__( 'Avg. Scroll Depth', 'opti-behavior' ); ?></div>
					<div class="stat-change neutral"><?php echo esc_html__( '0% vs last period', 'opti-behavior' ); ?></div>
					<div class="stat-history">
						<canvas id="history-avg-scroll-depth" width="280" height="50"></canvas>
					</div>
				</div>
			</div>
			<div class="stat-card" data-stat="bounce_rate">
				<div class="stat-icon">📊</div>
				<div class="stat-content">
					<div class="stat-value"><span class="stat-value-spinner"></span></div>
					<div class="stat-label"><?php echo esc_html__( 'Bounce Rate', 'opti-behavior' ); ?></div>
					<div class="stat-change neutral"><?php echo esc_html__( '0% vs last period', 'opti-behavior' ); ?></div>
					<div class="stat-history">
						<canvas id="history-bounce-rate" width="280" height="50"></canvas>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

if ( ! function_exists( 'opti_behavior_views_render_dashboard_content' ) ) {
	/**
	 * Render dashboard main content area with widgets.
	 *
	 * @since 1.0.0
	 * @param object $self           Dashboard instance.
	 * @param array  $dashboard_data Dashboard data array.
	 */
	function opti_behavior_views_render_dashboard_content( $self, $dashboard_data ) {
		?>
		<div class="dashboard-content">
			<div class="dashboard-grid">
				<div id="sessions-chart-widget" class="dashboard-widget chart-widget">
					<?php opti_behavior_render_widget_loading(); ?>
					<div class="widget-header">
						<h3 class="widget-title">
							<span class="widget-icon">📈</span>
							<?php echo esc_html__( 'Traffic Overview', 'opti-behavior' ); ?>
						</h3>
					</div>
					<div class="widget-content">
						<div class="chart-container">
							<canvas id="sessions-chart"></canvas>
						</div>
					</div>
				</div>

				<div id="browsers-widget" class="dashboard-widget browsers-widget">
					<?php opti_behavior_render_widget_loading(); ?>
					<div class="widget-header">
						<h3 class="widget-title"><span class="widget-icon">🧭</span> <?php echo esc_html__( 'Browsers', 'opti-behavior' ); ?></h3>
					</div>
					<div class="widget-content compact">
						<canvas id="browsers-chart"></canvas>
					</div>
				</div>

				<div id="device-types-widget" class="dashboard-widget device-widget">
					<?php opti_behavior_render_widget_loading(); ?>
					<div class="widget-header">
						<h3 class="widget-title"><span class="widget-icon">📱</span> <?php echo esc_html__( 'Device Types', 'opti-behavior' ); ?></h3>
					</div>
					<div class="widget-content compact">
						<?php $self->render_device_types_chart(); ?>
					</div>
				</div>

				<div id="operating-systems-widget" class="dashboard-widget os-widget">
					<?php opti_behavior_render_widget_loading(); ?>
					<div class="widget-header">
						<h3 class="widget-title"><span class="widget-icon">💻</span> <?php echo esc_html__( 'Operating Systems', 'opti-behavior' ); ?></h3>
					</div>
					<div class="widget-content compact">
						<?php $self->render_operating_systems_chart(); ?>
					</div>
				</div>

				<div id="user-intent-widget" class="dashboard-widget user-intent-widget">
					<?php opti_behavior_render_widget_loading(); ?>
					<div class="widget-header">
						<h3 class="widget-title">
							<span class="widget-icon">🎯</span>
							<?php echo esc_html__( 'User Intent', 'opti-behavior' ); ?>
						</h3>
					</div>
					<div class="widget-content compact">
						<?php $self->render_user_intent_chart(); ?>
					</div>
				</div>

				<?php
				$optibehavior_tu_nonce = wp_create_nonce( 'optibehavior_top_users' );
				$optibehavior_tu_ajax  = admin_url( 'admin-ajax.php' );
				?>
				<div class="dashboard-widget top-users-widget">
					<div class="widget-header">
						<h3 class="widget-title"><span class="widget-icon">👤</span> <?php echo esc_html__( 'Top Engaged Users', 'opti-behavior' ); ?></h3>
					</div>
					<div class="widget-content">
						<div class="optibehavior-table-wrap">
							<table class="widefat striped">
								<thead>
								<tr>
									<th>#</th>
									<th><?php echo esc_html__( 'Visitor', 'opti-behavior' ); ?></th>
									<th title="Average sessions per active day"><?php echo esc_html__( 'Daily Freq', 'opti-behavior' ); ?></th>
									<th title="Average time per session"><?php echo esc_html__( 'Avg Session', 'opti-behavior' ); ?></th>
									<th><?php echo esc_html__( 'Total Time', 'opti-behavior' ); ?></th>
									<th><?php echo esc_html__( 'Sessions', 'opti-behavior' ); ?></th>
									<th title="Pages per session"><?php echo esc_html__( 'Pages/Sess', 'opti-behavior' ); ?></th>
									<th><?php echo esc_html__( 'Country', 'opti-behavior' ); ?></th>
									<th><?php echo esc_html__( 'Last Seen', 'opti-behavior' ); ?></th>
								</tr>
								</thead>
								<tbody id="optibehavior-tu2-body">
									<tr><td colspan="9">
										<div class="optibehavior-loading-state">
											<span class="spinner is-active"></span>
											<span><?php echo esc_html__( 'Loading top users…', 'opti-behavior' ); ?></span>
										</div>
									</td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div id="countries-widget" class="dashboard-widget countries-widget">
					<?php opti_behavior_render_widget_loading(); ?>
					<div class="widget-header">
						<h3 class="widget-title"><span class="widget-icon">🌍</span> <?php echo esc_html__( 'Top Countries', 'opti-behavior' ); ?></h3>
					</div>
					<div class="widget-content compact">
						<canvas id="countries-chart"></canvas>
					</div>
				</div>

				<div id="realtime-widget" class="dashboard-widget realtime-widget">
					<?php opti_behavior_render_widget_loading(); ?>
					<div class="widget-header">
						<h3 class="widget-title">
							<span class="live-badge" aria-label="Live"><span class="live-dot" aria-hidden="true"></span><?php echo esc_html__( 'Live', 'opti-behavior' ); ?></span>
							<?php echo esc_html__( 'Real-time Visitors', 'opti-behavior' ); ?>
							<span class="visitor-count"><?php echo count( $dashboard_data['realtime']['active_visitors'] ); ?></span>
						</h3>
					</div>
					<div class="widget-content">
						<div class="realtime-visitors" id="realtime-visitors">
							<?php foreach ( $dashboard_data['realtime']['active_visitors'] as $visitor ) : ?>
							<div class="visitor-item grid">
								<span class="visitor-datetime"><?php echo esc_html( $visitor['visited_at'] ?? '' ); ?><?php if ( ! empty( $visitor['time_ago'] ) ) : ?> - <span class="ago"><?php echo esc_html( $visitor['time_ago'] ); ?></span><?php endif; ?></span>
								<span class="visitor-flag-country"><span class="visitor-flag"><?php echo $visitor['flag']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <span class="visitor-location"><?php echo esc_html( $visitor['country'] ); ?></span></span>
								<span class="visitor-pageblock"><span class="visitor-title"><?php echo esc_html( $visitor['page_title'] ?? '' ); ?></span><a class="visitor-url" href="<?php echo esc_url( $visitor['current_url'] ?? '' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $visitor['current_url'] ?? '' ); ?></a></span>
								<span class="visitor-ip-col"><?php
									$ip_raw = isset( $visitor['ip'] ) ? (string) $visitor['ip'] : '';
									$ip_raw = trim( $ip_raw );
									if ( '' === $ip_raw ) {
										echo '<span class="visitor-ip-pill visitor-ip-anon"><i data-lucide="shield-check" style="width:13px;height:13px;display:inline-block;vertical-align:-2px;margin-right:3px;"></i>' . esc_html__( 'Anonymous', 'opti-behavior' ) . '</span>';
									} elseif ( 'Anonymous' === $ip_raw ) {
										echo '<span class="visitor-ip-pill visitor-ip-anon"><i data-lucide="shield-check" style="width:13px;height:13px;display:inline-block;vertical-align:-2px;margin-right:3px;"></i>' . esc_html__( 'Anonymous', 'opti-behavior' ) . '</span>';
									} elseif ( false !== strpos( $ip_raw, ':' ) ) {
										$start = substr( $ip_raw, 0, 7 );
										$end   = substr( $ip_raw, -7 );
										echo '<span class="visitor-ip-pill">' . esc_html( $start . '…' . $end ) . '</span>';
									} else {
										echo '<span class="visitor-ip-pill">' . esc_html( $ip_raw ) . '</span>';
									}
									?></span>
							</div>
							<?php endforeach; ?>

							<?php if ( empty( $dashboard_data['realtime']['active_visitors'] ) ) : ?>
							<div class="optibehavior-empty-state is-visible">
								<svg viewBox='0 0 24 24' fill='none' stroke='#9ca3af' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'><path d='M16 11a4 4 0 1 0-8 0'/><path d='M3 21a7 7 0 0 1 18 0'/></svg>
								<div class="optibehavior-empty-title"><?php echo esc_html__( 'No active visitors right now', 'opti-behavior' ); ?></div>
								<div class="optibehavior-empty-sub"><?php echo esc_html__( 'Traffic updates in real-time.', 'opti-behavior' ); ?></div>
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div class="dashboard-widget realtime-map-widget">
					<div class="widget-header">
						<h3 class="widget-title"><span class="widget-icon">🗺️</span> <?php echo esc_html__( 'Real-time Visitor Map', 'opti-behavior' ); ?></h3>
					</div>
					<div class="widget-content">
						<div id="realtime-map" class="realtime-map" aria-label="<?php esc_attr_e( 'Interactive world map of live visitors', 'opti-behavior' ); ?>"></div>
					</div>
				</div>

                <!-- Top Pages -->
                <div id="top-pages-widget" class="dashboard-widget pages-widget">
                    <?php opti_behavior_render_widget_loading(); ?>
                    <div class="widget-header">
                        <h3 class="widget-title">
                            <span class="widget-icon" aria-hidden="true">
                                <svg class="widget-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path></svg>
                            </span>
                            <?php echo esc_html__( 'Top Pages', 'opti-behavior' ); ?>
                        </h3>
                    </div>
                    <div class="widget-content">
                        <div class="top-pages">
                            <?php if ( empty( $dashboard_data['charts']['top_pages'] ) ) : ?>
                                <div class="optibehavior-empty-state is-visible">
                                    <svg viewBox='0 0 24 24' fill='none' stroke='#9ca3af' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'><path d='M4 3h14a2 2 0 0 1 2 2v14l-4-4H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z'/></svg>
                                    <div class="optibehavior-empty-title"><?php echo esc_html__( 'No page views yet', 'opti-behavior' ); ?></div>
                                    <div class="optibehavior-empty-sub"><?php echo esc_html__( 'Once visitors view pages, you\'ll see them here.', 'opti-behavior' ); ?></div>
                                </div>
                            <?php else : ?>
                                <?php foreach ( $dashboard_data['charts']['top_pages'] as $page ) : ?>
                                    <?php
                                    $page_title        = isset( $page['title'] ) ? $page['title'] : '';
                                    $page_url          = isset( $page['url'] ) ? $page['url'] : '';
                                    $views             = isset( $page['views'] ) ? intval( $page['views'] ) : 0;
                                    $clicks            = isset( $page['clicks'] ) ? intval( $page['clicks'] ) : 0;
                                    $clicks_percentage = isset( $page['clicks_percentage'] ) ? intval( $page['clicks_percentage'] ) : 0;
                                    $clicks_change     = isset( $page['clicks_change'] ) ? intval( $page['clicks_change'] ) : 0;
                                    $clicks_trend      = isset( $page['clicks_trend'] ) ? $page['clicks_trend'] : 'neutral';

                                    $trend_class = 'neutral';
                                    $trend_arrow = '→';
                                    if ( 'up' === $clicks_trend ) {
                                        $trend_class = 'positive';
                                        $trend_arrow = '↑';
                                    } elseif ( 'down' === $clicks_trend ) {
                                        $trend_class = 'negative';
                                        $trend_arrow = '↓';
                                    }

                                    $display_url = $page_url;
                                    if ( strlen( $display_url ) > 80 ) {
                                        $keep        = 79;
                                        $left        = (int) ceil( $keep / 2 );
                                        $right       = (int) floor( $keep / 2 );
                                        $display_url = substr( $display_url, 0, $left ) . '…' . substr( $display_url, - $right );
                                    }
                                    ?>
                                    <div class="page-item">
                                        <div class="page-info">
                                            <div class="page-title-row">
                                                <div class="page-title"><?php echo esc_html( $page_title ); ?></div>
                                                <div class="page-actions">
                                                    <a class="optibehavior-heatmap-btn" target="_blank" href="<?php echo esc_url( $page['pc_heatmap'] ); ?>" aria-label="Open PC heatmap for <?php echo esc_attr( $page_title ); ?>">
                                                        <span class="optibehavior-heatmap-icon" aria-hidden="true">🖥️</span>
                                                        <span class="optibehavior-heatmap-label"><?php echo esc_html__( 'PC', 'opti-behavior' ); ?></span>
                                                    </a>
                                                    <a class="optibehavior-heatmap-btn alt" target="_blank" href="<?php echo esc_url( $page['mobile_heatmap'] ); ?>" aria-label="Open Mobile heatmap for <?php echo esc_attr( $page_title ); ?>">
                                                        <span class="optibehavior-heatmap-icon" aria-hidden="true">📱</span>
                                                        <span class="optibehavior-heatmap-label"><?php echo esc_html__( 'Mobile', 'opti-behavior' ); ?></span>
                                                    </a>
                                                </div>
                                            </div>
                                            <a class="page-url" href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $page_url ); ?>"><?php echo esc_html( $display_url ); ?></a>
                                        </div>
                                        <div class="page-stats">
                                            <div class="page-views-wrapper">
                                                <span class="page-views">
                                                    <span class="page-views-icon" aria-hidden="true">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                                    </span>
                                                    <span class="page-views-value"><?php echo esc_html( number_format_i18n( $views ) ); ?></span>
                                                </span>
                                            </div>
                                            <div class="page-clicks-indicator">
                                                <span class="click-pill">
                                                    <span class="click-pill-icon" aria-hidden="true">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L12 22"></path><path d="M5 5L12 2L19 5"></path><path d="M5 19L12 22L19 19"></path></svg>
                                                    </span>
                                                    <span class="click-pill-value"><?php echo esc_html( number_format_i18n( $clicks ) ); ?></span>
                                                </span>
                                                <span class="click-percentage-pill"><?php echo esc_html( $clicks_percentage ); ?>%</span>
                                                <span class="click-change <?php echo esc_attr( $trend_class ); ?>"><?php echo esc_html( $trend_arrow ); ?> <?php echo esc_html( $clicks_change ); ?>%</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Referrers -->
                <div id="referrers-widget" class="dashboard-widget referrers-widget">
                    <?php opti_behavior_render_widget_loading(); ?>
                    <div class="widget-header">
                        <h3 class="widget-title"><span class="widget-icon">🔗</span> <?php echo esc_html__( 'Top Referrers', 'opti-behavior' ); ?></h3>
                    </div>
                    <div class="widget-content compact">
                        <canvas id="referrers-chart"></canvas>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }
}

