<?php
/**
 * Dashboard Views Trait
 *
 * Moves Dashboard header and stats render methods out of the monolithic dashboard class.
 *
 * @package opti-behavior
 * @version 1.0.3
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard Views Trait
 *
 * Provides dashboard rendering methods.
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
if ( ! trait_exists( 'opti_behavior_Dashboard_Views_Trait' ) ) {
	trait Opti_Behavior_Dashboard_Views_Trait {
		/**
		 * Render dashboard header.
		 *
		 * @since 1.0.0
		 * @param array  $date_range Date range.
		 * @param string $period Period.
		 */
		private function render_dashboard_header( $date_range = null, $period = 'last30days' ) {
            $start_val = $date_range && isset($date_range['start']) ? substr($date_range['start'],0,10) : '';
            $end_val = $date_range && isset($date_range['end']) ? substr($date_range['end'],0,10) : '';
            $exclude_spam = method_exists( $this, 'is_spam_excluded' ) && $this->is_spam_excluded() ? '1' : '0';
            $export_args = array(
                'action'       => 'opti_behavior_dashboard_export',
                '_wpnonce'     => wp_create_nonce( 'opti_behavior_dashboard_export' ),
                'period'       => sanitize_key( $period ),
                'start_date'   => $start_val,
                'end_date'     => $end_val,
                'exclude_spam' => $exclude_spam,
                'scope'        => 'dashboard',
            );
            $export_csv_url                = add_query_arg( array_merge( $export_args, array( 'format' => 'csv' ) ), admin_url( 'admin-post.php' ) );
            $export_json_url               = add_query_arg( array_merge( $export_args, array( 'format' => 'json' ) ), admin_url( 'admin-post.php' ) );
            $can_export_full_raw_traffic   = false;
            $export_raw_traffic_csv_url    = '';
            if ( current_user_can( 'manage_options' ) && class_exists( 'Opti_Behavior_Dashboard_Exporter' ) ) {
                $dashboard_exporter = new Opti_Behavior_Dashboard_Exporter();
                if ( is_callable( array( $dashboard_exporter, 'can_export_full_raw_traffic' ) ) ) {
                    $can_export_full_raw_traffic = (bool) $dashboard_exporter->can_export_full_raw_traffic();
                }
            }
            if ( $can_export_full_raw_traffic ) {
                $export_raw_traffic_csv_url = add_query_arg(
                    array_merge(
                        $export_args,
                        array(
                            'format' => 'csv',
                            'scope'  => 'raw_traffic',
                        )
                    ),
                    admin_url( 'admin-post.php' )
                );
            }
            $tooltips             = opti_behavior_get_dashboard_tooltips();
			$exclude_spam_active  = $this->is_spam_excluded();
			$exclude_spam_icon    = $exclude_spam_active ? 'shield-check' : 'shield-off';
			$exclude_spam_classes = 'filter-btn' . ( $exclude_spam_active ? ' active' : '' );
			$exclude_spam_label   = $exclude_spam_active ? __( 'Excluding Spam', 'opti-behavior' ) : __( 'Including Spam', 'opti-behavior' );
			$exclude_spam_title   = $exclude_spam_active ? __( 'Spam traffic is excluded from all statistics', 'opti-behavior' ) : __( 'Spam traffic is included in all statistics', 'opti-behavior' );
            ?>
            <div class="dashboard-header">
                <div class="dashboard-title-section">
                    <div class="dashboard-icon"><i data-lucide="bar-chart-3"></i></div>
                    <div class="dashboard-title-text">
                        <h1 class="dashboard-title">
                            <?php esc_html_e( 'Traffic Overview', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['dashboard_overview']['title'], $tooltips['dashboard_overview']['content'], $tooltips['dashboard_overview']['simple'], '', array( 'position' => 'bottom' ) ); ?>
                        </h1>
                        <div class="dashboard-subtitle">
                            <span class="subtitle-text"><?php esc_html_e( 'Real-time insights and user behavior analytics', 'opti-behavior' ); ?></span>
                        </div>
                    </div>
                </div>
                <div class="dashboard-controls">
                    <select class="period-selector" id="dashboard-period">
                        <option value="today" <?php selected($period,'today'); ?>><?php esc_html_e( 'Today', 'opti-behavior' ); ?></option>
                        <option value="yesterday" <?php selected($period,'yesterday'); ?>><?php esc_html_e( 'Yesterday', 'opti-behavior' ); ?></option>
                        <option value="last7days" <?php selected($period,'last7days'); ?>><?php esc_html_e( 'Last 7 Days', 'opti-behavior' ); ?></option>
                        <option value="last14days" <?php selected($period,'last14days'); ?>><?php esc_html_e( 'Last 14 Days', 'opti-behavior' ); ?></option>
                        <option value="last30days" <?php selected($period,'last30days'); ?>><?php esc_html_e( 'Last 30 Days', 'opti-behavior' ); ?></option>
                        <option value="thismonth" <?php selected($period,'thismonth'); ?>><?php esc_html_e( 'This Month', 'opti-behavior' ); ?></option>
                        <option value="custom" <?php selected($period,'custom'); ?>><?php esc_html_e( 'Custom Range', 'opti-behavior' ); ?></option>
                    </select>
                    <input type="date" id="start-date" value="<?php echo esc_attr($start_val); ?>" />
                    <input type="date" id="end-date" value="<?php echo esc_attr($end_val); ?>" />
                    <button class="refresh-btn" id="apply-range">
                        <i data-lucide="calendar"></i>
                        <?php esc_html_e( 'Apply', 'opti-behavior' ); ?>
                    </button>
                    <button class="refresh-btn" id="refresh-dashboard">
                        <i data-lucide="refresh-cw"></i>
                        <?php esc_html_e( 'Refresh', 'opti-behavior' ); ?>
                    </button>
                    <button class="refresh-btn" id="toggle-advanced-filters" type="button" aria-expanded="false" aria-controls="advanced-filters-panel">
                        <i data-lucide="sliders-horizontal"></i>
                        <span class="advanced-filters-toggle-label"><?php esc_html_e( 'Filters', 'opti-behavior' ); ?></span>
                    </button>
					<div class="dashboard-export-menu" data-export-scope="dashboard">
						<button type="button" class="dashboard-export-button" aria-label="<?php esc_attr_e( 'Download dashboard report', 'opti-behavior' ); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="dashboard-export-dropdown">
							<i data-lucide="download"></i>
							<span><?php esc_html_e( 'Download', 'opti-behavior' ); ?></span>
							<i data-lucide="chevron-down" class="dashboard-export-chevron" aria-hidden="true"></i>
						</button>
						<div class="dashboard-export-dropdown" id="dashboard-export-dropdown" role="menu" aria-label="<?php esc_attr_e( 'Dashboard export formats', 'opti-behavior' ); ?>" hidden>
							<a class="dashboard-export-link" data-export-format="csv" role="menuitem" href="<?php echo esc_url( $export_csv_url ); ?>">
								<i data-lucide="file-spreadsheet"></i>
								<span><?php esc_html_e( 'Download CSV', 'opti-behavior' ); ?></span>
							</a>
							<a class="dashboard-export-link" data-export-format="json" role="menuitem" href="<?php echo esc_url( $export_json_url ); ?>">
								<i data-lucide="braces"></i>
								<span><?php esc_html_e( 'Download JSON', 'opti-behavior' ); ?></span>
							</a>
							<?php if ( $can_export_full_raw_traffic && $export_raw_traffic_csv_url ) : ?>
								<div class="dashboard-export-separator" role="separator" aria-hidden="true"></div>
								<a class="dashboard-export-link dashboard-export-link--raw" data-export-format="csv" data-export-scope="raw_traffic" role="menuitem" href="<?php echo esc_url( $export_raw_traffic_csv_url ); ?>">
									<i data-lucide="database"></i>
									<span><?php esc_html_e( 'Full Raw Traffic CSV', 'opti-behavior' ); ?></span>
									<span class="dashboard-export-badge"><?php esc_html_e( 'Pro', 'opti-behavior' ); ?></span>
								</a>
							<?php endif; ?>
						</div>
					</div>
                    <button class="<?php echo esc_attr( $exclude_spam_classes ); ?>" id="exclude-spam-toggle" aria-pressed="<?php echo esc_attr( $exclude_spam_active ? 'true' : 'false' ); ?>" aria-label="<?php echo esc_attr( $exclude_spam_label ); ?>" title="<?php echo esc_attr( $exclude_spam_title ); ?>">
                        <span class="filter-icon"><i data-lucide="<?php echo esc_attr( $exclude_spam_icon ); ?>"></i></span>
                        <span class="filter-label"><?php echo esc_html( $exclude_spam_label ); ?></span>
                    </button>
                </div>
            </div>
            <?php
        }

        /**
         * Render the FREE dashboard "Filters" panel (hidden by default, toggled
         * by #toggle-advanced-filters in render_dashboard_header()).
         *
         * Mirrors the PRO Session Recordings advanced-filters panel's 4-column
         * layout, adapted to the field set the FREE schema/queries support
         * (see spec.md section 2.2 / section 7 "User Decisions"): no Recording
         * ID / Watched Status / Contains Page / Does Not Contain Page.
         *
         * Field values posted by this panel must match the allow-listed keys
         * consumed by sanitize_advanced_filters_from_request() /
         * build_advanced_filters_sql() in trait-opti-behavior-ajax-handlers.php
         * and class-opti-behavior-heatmap-dashboard.php.
         *
         * @since 1.0.4
         */
        private function render_advanced_filters_panel() {
            // Delegates to the shared renderer (Opti_Behavior_Advanced_Filters_Trait)
            // so the dashboard + Funnels detail page emit one identical panel. The
            // dashboard keeps its full field set (Exit Page included).
            $this->render_shared_advanced_filters_panel( array( 'include_exit_page' => true ) );
        }

        /**
         * Render dashboard stats
         */
        private function render_dashboard_stats($stats, $changes) {
            /* translators: %s: percentage change value */
            $fmt = function($delta){ $sign = ($delta > 0 ? '+' : ($delta < 0 ? '' : '')); return $sign . (int)round($delta) . esc_html__( '% vs last period', 'opti-behavior' ); };
            $cls = function($delta){ return $delta > 0 ? 'positive' : ($delta < 0 ? 'negative' : 'neutral'); };
            $tooltips = opti_behavior_get_dashboard_tooltips();
            ?>
            <div class="dashboard-stats">
                <div class="stat-card" data-stat="visitors">
                    <div class="stat-icon"><i data-lucide="users"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><span class="stat-value-spinner"></span></div>
                        <div class="stat-label">
                            <?php esc_html_e( 'Visitors', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['visitors']['title'], $tooltips['visitors']['content'], $tooltips['visitors']['simple'], $tooltips['visitors']['example'], array( 'position' => 'bottom', 'size' => 'sm' ) ); ?>
                        </div>
                        <div class="stat-change neutral"><?php esc_html_e( '0% vs last period', 'opti-behavior' ); ?></div>
                        <div class="stat-history">
                            <canvas id="history-visitors" width="280" height="50"></canvas>
                        </div>
                    </div>
                </div>

                <div class="stat-card" data-stat="sessions">
                    <div class="stat-icon"><i data-lucide="activity"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><span class="stat-value-spinner"></span></div>
                        <div class="stat-label">
                            <?php esc_html_e( 'Sessions', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['sessions']['title'], $tooltips['sessions']['content'], $tooltips['sessions']['simple'], $tooltips['sessions']['example'], array( 'position' => 'bottom', 'size' => 'sm' ) ); ?>
                        </div>
                        <div class="stat-change neutral"><?php esc_html_e( '0% vs last period', 'opti-behavior' ); ?></div>
                        <div class="stat-history">
                            <canvas id="history-sessions" width="280" height="50"></canvas>
                        </div>
                    </div>
                </div>

                <div class="stat-card" data-stat="pageviews">
                    <div class="stat-icon"><i data-lucide="file-text"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><span class="stat-value-spinner"></span></div>
                        <div class="stat-label">
                            <?php esc_html_e( 'Page Views', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['pageviews']['title'], $tooltips['pageviews']['content'], $tooltips['pageviews']['simple'], $tooltips['pageviews']['example'], array( 'position' => 'bottom', 'size' => 'sm' ) ); ?>
                        </div>
                        <div class="stat-change neutral"><?php esc_html_e( '0% vs last period', 'opti-behavior' ); ?></div>
                        <div class="stat-history">
                            <canvas id="history-pageviews" width="280" height="50"></canvas>
                        </div>
                    </div>
                </div>

                <div class="stat-card" data-stat="avg_session_time">
                    <div class="stat-icon"><i data-lucide="clock"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><span class="stat-value-spinner"></span></div>
                        <div class="stat-label">
                            <?php esc_html_e( 'Avg. Session Time', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['avg_session_time']['title'], $tooltips['avg_session_time']['content'], $tooltips['avg_session_time']['simple'], $tooltips['avg_session_time']['example'], array( 'position' => 'bottom', 'size' => 'sm' ) ); ?>
                        </div>
                        <div class="stat-change neutral"><?php esc_html_e( '0% vs last period', 'opti-behavior' ); ?></div>
                        <div class="stat-history">
                            <canvas id="history-avg-session-time" width="280" height="50"></canvas>
                        </div>
                    </div>
                </div>

                <div class="stat-card" data-stat="avg_scroll_depth">
                    <div class="stat-icon"><i data-lucide="scroll-text"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><span class="stat-value-spinner"></span></div>
                        <div class="stat-label">
                            <?php esc_html_e( 'Avg. Scroll Depth', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['avg_scroll_depth']['title'], $tooltips['avg_scroll_depth']['content'], $tooltips['avg_scroll_depth']['simple'], $tooltips['avg_scroll_depth']['example'], array( 'position' => 'bottom', 'size' => 'sm' ) ); ?>
                        </div>
                        <div class="stat-change neutral"><?php esc_html_e( '0% vs last period', 'opti-behavior' ); ?></div>
                        <div class="stat-history">
                            <canvas id="history-avg-scroll-depth" width="280" height="50"></canvas>
                        </div>
                    </div>
                </div>

                <div class="stat-card" data-stat="bounce_rate">
                    <div class="stat-icon"><i data-lucide="trending-down"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><span class="stat-value-spinner"></span></div>
                        <div class="stat-label">
                            <?php esc_html_e( 'Bounce Rate', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['bounce_rate']['title'], $tooltips['bounce_rate']['content'], $tooltips['bounce_rate']['simple'], $tooltips['bounce_rate']['example'], array( 'position' => 'bottom', 'size' => 'sm' ) ); ?>
                        </div>
                        <div class="stat-change neutral"><?php esc_html_e( '0% vs last period', 'opti-behavior' ); ?></div>
                        <div class="stat-history">
                            <canvas id="history-bounce-rate" width="280" height="50"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }

		/**
		 * Render the dashboard Smart Insights summary section.
		 *
		 * Data is loaded asynchronously so the dashboard remains safe and fast on
		 * upgrades where the insights table may not be available yet.
		 *
		 * @since 1.3.3
		 * @param array $dashboard_data Initial dashboard data.
		 * @return void
		 */
		private function render_smart_insights_dashboard_section( $dashboard_data ) {
			$date_range = isset( $dashboard_data['date_range'] ) && is_array( $dashboard_data['date_range'] ) ? $dashboard_data['date_range'] : array();
			$start_date = isset( $date_range['start'] ) ? substr( $date_range['start'], 0, 10 ) : '';
			$end_date   = isset( $date_range['end'] ) ? substr( $date_range['end'], 0, 10 ) : '';
			$period     = isset( $dashboard_data['period'] ) ? sanitize_key( $dashboard_data['period'] ) : 'last30days';
			$center_url = admin_url( 'admin.php?page=opti-behavior-smart-insights' );
			$has_pro    = $this->smart_insights_viewer_has_pro_access();
			?>
			<div class="dashboard-content ob-smart-insights-dashboard-wrap">
			<section
				class="ob-smart-insights ob-smart-insights-dashboard dashboard-widget"
				data-ob-smart-insights-context="dashboard"
				data-period="<?php echo esc_attr( $period ); ?>"
				data-start-date="<?php echo esc_attr( $start_date ); ?>"
				data-end-date="<?php echo esc_attr( $end_date ); ?>"
			>
				<div class="widget-header ob-smart-insights-header">
					<div class="ob-smart-insights-heading ob-smart-insights-title-group">
						<h3 class="widget-title ob-smart-insights-widget-title">
							<span class="widget-icon ob-smart-insights-icon" aria-hidden="true"><i data-lucide="lightbulb"></i></span>
							<span><?php esc_html_e( 'Smart Insights', 'opti-behavior' ); ?></span>
						</h3>
						<p class="ob-smart-insights-subtitle"><?php esc_html_e( 'Priority recommendations detected from local behavior analytics.', 'opti-behavior' ); ?></p>
					</div>
					<div class="ob-smart-insights-header-actions">
						<button type="button" class="button button-small ob-smart-insights-refresh">
							<i data-lucide="refresh-cw" aria-hidden="true"></i>
							<?php esc_html_e( 'Refresh', 'opti-behavior' ); ?>
						</button>
						<a class="button button-small" href="<?php echo esc_url( $center_url ); ?>">
							<?php esc_html_e( 'Open center', 'opti-behavior' ); ?>
						</a>
					</div>
				</div>

				<div class="widget-content ob-smart-insights-content">
					<div class="ob-smart-insights-alert" role="status" hidden></div>
					<div class="ob-smart-insights-list" aria-live="polite">
						<div class="ob-smart-insights-loading">
							<span class="ob-smart-insights-spinner" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Loading Smart Insights…', 'opti-behavior' ); ?></span>
						</div>
					</div>
					<?php if ( ! $has_pro ) : ?>
						<div class="ob-smart-insights-weekly-mini" aria-label="<?php esc_attr_e( 'Weekly CRO Summary Pro preview', 'opti-behavior' ); ?>">
							<span class="ob-smart-insights-badge is-locked"><?php esc_html_e( 'Pro', 'opti-behavior' ); ?></span>
							<strong><?php esc_html_e( 'Weekly CRO Summary', 'opti-behavior' ); ?></strong>
							<span><?php esc_html_e( 'Unlock recurring issues, segment changes, and prioritized next actions.', 'opti-behavior' ); ?></span>
						</div>
					<?php endif; ?>
				</div>
			</section>
			</div>
			<?php
		}

		/**
		 * Detect whether the dashboard viewer has Pro Smart Insights access.
		 *
		 * @since 1.3.3
		 * @return bool
		 */
		private function smart_insights_viewer_has_pro_access() {
			if ( class_exists( 'Opti_Behavior_Smart_Insights_Capabilities' ) ) {
				$capabilities = new Opti_Behavior_Smart_Insights_Capabilities();
				if ( is_callable( array( $capabilities, 'has_pro_access' ) ) ) {
					return (bool) $capabilities->has_pro_access();
				}
			}

			return (bool) apply_filters( 'opti_behavior_smart_insights_has_pro_access', false, 'smart_insights' );
		}

        /**
         * Render dashboard content
         */
        private function render_dashboard_content_view($dashboard_data) {
            // Get tooltips for all widgets
            $tooltips = opti_behavior_get_dashboard_tooltips();
            ?>
            <div class="dashboard-content">
                <div class="dashboard-sections">
                <?php $this->render_dashboard_section_open( 1, 'key-metrics', __( 'Traffic Overview', 'opti-behavior' ), true ); ?>
                    <?php $this->render_dashboard_stats( $dashboard_data['stats'], $dashboard_data['changes'] ); ?>
                    <!--
                    ╔══════════════════════════════════════════════════════════════════════════════╗
                    ║                        DASHBOARD WIDGET DISPLAY ORDER                        ║
                    ╠══════════════════════════════════════════════════════════════════════════════╣
                    ║  ⚠️ SINGLE SOURCE OF TRUTH - Widget order controlled in PHP (this file)     ║
                    ║  CSS order property is NOT used - widgets appear in natural HTML order      ║
                    ║                                                                              ║
                    ║  DISPLAY ORDER (matching https://optiuser.com EXACTLY):                     ║
                    ║  ┌────────────────────────────────────────────────────────────────────────┐ ║
                    ║  │ LEVEL 1: Stats cards (6 cards)                                        │ ║
                    ║  │ LEVEL 2: Real-time Visitors (2-col) + Real-time Map (1-col)          │ ║
                    ║  │ LEVEL 3: Traffic Overview (3-col span / full-width)                   │ ║
                    ║  │ LEVEL 4: Top Engaged Users (2-col) + Top Pages (1-col)                │ ║
                    ║  │ LEVEL 5: Visitor Activity Heatmap (3-col span / full-width)           │ ║
                    ║  │ LEVEL 6: Traffic Classification + Bot Traffic + User Intent           │ ║
                    ║  │ LEVEL 7: Top Referrers + Top Countries + Browsers                     │ ║
                    ║  │ LEVEL 8: Device Types + Operating Systems + Screen Resolution         │ ║
                    ║  └────────────────────────────────────────────────────────────────────────┘ ║
                    ║                                                                              ║
                    ║  HTML ORDER (below):                                                         ║
                    ║  Real-time Visitors → Real-time Map → Traffic Overview → Top Engaged Users  ║
                    ║  → Top Pages → Visitor Activity Heatmap → Rest in natural order             ║
                    ╚══════════════════════════════════════════════════════════════════════════════╝
                    -->

                    <!-- Real-time Visitors [DISPLAY ORDER: 2] -->
                    <div id="realtime-widget" class="dashboard-widget realtime-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title">
                                <span class="live-badge" aria-label="<?php esc_attr_e( 'Live', 'opti-behavior' ); ?>"><span class="live-dot" aria-hidden="true"></span><?php esc_html_e( 'Live', 'opti-behavior' ); ?></span>
                                <?php esc_html_e( 'Real-time Visitors', 'opti-behavior' ); ?>
                                <span class="visitor-count"><?php echo count($dashboard_data['realtime']['active_visitors']); ?></span>
                                <?php opti_behavior_tooltip_e( $tooltips['realtime_visitors']['title'], $tooltips['realtime_visitors']['content'], $tooltips['realtime_visitors']['simple'], $tooltips['realtime_visitors']['example'] ?? '', array( 'position' => 'bottom' ) ); ?>
                            </h3>
                        </div>
                        <div class="widget-content">
                            <div class="realtime-visitors" id="realtime-visitors">
                                <?php foreach ($dashboard_data['realtime']['active_visitors'] as $visitor): ?>
                                <div class="visitor-item grid">
                                    <span class="visitor-datetime"><?php echo esc_html($visitor['visited_at'] ?? ''); ?><?php if(!empty($visitor['time_ago'])): ?> - <span class="ago"><?php echo esc_html($visitor['time_ago']); ?></span><?php endif; ?></span>
                                    <span class="visitor-flag-country"><span class="visitor-flag"><?php echo $visitor['flag']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Flag emoji is safe, generated from validated ISO country code ?></span> <span class="visitor-location"><?php echo esc_html($visitor['country']); ?></span></span>
                                    <span class="visitor-pageblock"><span class="visitor-title"><?php echo esc_html($visitor['page_title'] ?? ''); ?></span><a class="visitor-url" href="<?php echo esc_url($visitor['current_url'] ?? ''); ?>" target="_blank" rel="noopener"><?php echo esc_html($visitor['current_url'] ?? ''); ?></a></span>
                                    <span class="visitor-ip-col"><?php
									$ip_raw = isset( $visitor['ip'] ) ? trim( (string) $visitor['ip'] ) : '';
									if ( '' === $ip_raw ) {
										echo '<span class="visitor-ip-pill">-</span>';
									} elseif ( 'Anonymous' === $ip_raw ) {
										echo '<span class="visitor-ip-pill visitor-ip-anon"><i data-lucide="shield-check" style="width:13px;height:13px;display:inline-block;vertical-align:-2px;margin-right:3px;"></i>' . esc_html__( 'Anonymous', 'opti-behavior' ) . '</span>';
									} elseif ( false !== strpos( $ip_raw, ':' ) ) {
										echo '<span class="visitor-ip-pill">' . esc_html( substr( $ip_raw, 0, 7 ) . "\xE2\x80\xA6" . substr( $ip_raw, -7 ) ) . '</span>';
									} else {
										echo '<span class="visitor-ip-pill">' . esc_html( $ip_raw ) . '</span>';
									}
								?></span>
                                </div>
                                <?php endforeach; ?>

                                <?php if (empty($dashboard_data['realtime']['active_visitors'])): ?>
                                <div class="optibehavior-empty-state is-visible">
                                    <i data-lucide="users" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                                    <div class="optibehavior-empty-title"><?php esc_html_e( 'No active visitors right now', 'opti-behavior' ); ?></div>
                                    <div class="optibehavior-empty-sub"><?php esc_html_e( 'Traffic updates in real-time.', 'opti-behavior' ); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Real-time Visitor Map -->
                    <div id="realtime-map-widget" class="dashboard-widget realtime-map-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title"><span class="widget-icon"><i data-lucide="map"></i></span> <?php esc_html_e( 'Real-time Visitor Map', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['realtime_map']['title'], $tooltips['realtime_map']['content'], $tooltips['realtime_map']['simple'], '', array( 'position' => 'bottom' ) ); ?></h3>
                        </div>
                        <div class="widget-content">
                            <div id="realtime-map" class="realtime-map" aria-label="<?php esc_attr_e( 'Interactive world map of live visitors', 'opti-behavior' ); ?>"></div>
                        </div>
                    </div>
                    <!-- Note: Realtime map tooltip styles are enqueued via wp_add_inline_style() in trait-opti-behavior-assets.php -->
                    <!-- Note: Realtime map initialization script is enqueued via wp_add_inline_script() in get_dashboard_scripts() method -->

                    <!-- LEVEL 3: Traffic chart - full width -->
                    <!-- Traffic Overview Chart -->
                    <div id="sessions-chart-widget" class="dashboard-widget chart-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title">
                                <span class="widget-icon"><i data-lucide="chart-spline"></i></span>
                                <?php esc_html_e( 'Traffic Overview', 'opti-behavior' ); ?>
                                <?php opti_behavior_tooltip_e( $tooltips['traffic_overview']['title'], $tooltips['traffic_overview']['content'], $tooltips['traffic_overview']['simple'], $tooltips['traffic_overview']['example'], array( 'position' => 'bottom' ) ); ?>
                            </h3>
                        </div>
                        <div class="widget-content">
                            <div class="chart-container">
                                <canvas id="sessions-chart"></canvas>
                            </div>
                        </div>
                    </div>

                <?php $this->render_dashboard_section_close(); ?>
                <?php $this->render_dashboard_section_open( 2, 'engagement', __( 'Engagement & Content', 'opti-behavior' ), false ); ?>
                    <!-- LEVEL 4: Engagement metrics - 2 widgets side by side -->
                    <!-- Top Engaged Users -->
                    <?php $optibehavior_tu_nonce = wp_create_nonce('optibehavior_top_users'); $optibehavior_tu_ajax = admin_url('admin-ajax.php'); ?>
                    <div id="top-users-widget" class="dashboard-widget top-users-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title"><span class="widget-icon"><i data-lucide="user-check"></i></span> <?php echo esc_html__('Top Engaged Users','opti-behavior'); ?><?php opti_behavior_tooltip_e( $tooltips['top_engaged_users']['title'], $tooltips['top_engaged_users']['content'], $tooltips['top_engaged_users']['simple'], $tooltips['top_engaged_users']['example'], array( 'position' => 'bottom' ) ); ?></h3>
                        </div>
                        <div class="widget-content">
                            <div class="optibehavior-table-wrap" style="display: none;">
                                <table class="widefat striped">
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th><?php echo esc_html__('Visitor','opti-behavior'); ?></th>
                                        <th title="<?php echo esc_attr__('Average sessions per active day', 'opti-behavior'); ?>"><?php echo esc_html__('Daily Freq', 'opti-behavior'); ?></th>
                                        <th title="<?php echo esc_attr__('Average time per session', 'opti-behavior'); ?>"><?php echo esc_html__('Avg Session', 'opti-behavior'); ?></th>
                                        <th><?php echo esc_html__('Total Time','opti-behavior'); ?></th>
                                        <th><?php echo esc_html__('Sessions','opti-behavior'); ?></th>
                                        <th title="<?php echo esc_attr__('Pages per session', 'opti-behavior'); ?>"><?php echo esc_html__('Pages/Sess', 'opti-behavior'); ?></th>
                                        <th><?php echo esc_html__('Country','opti-behavior'); ?></th>
                                        <th><?php echo esc_html__('Last Seen','opti-behavior'); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody id="optibehavior-tu2-body">
                                    </tbody>
                                </table>
                            </div>
                            <div class="optibehavior-empty-state is-visible">
                                <i data-lucide="user-check" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                                <div class="optibehavior-empty-title"><?php esc_html_e( 'No engaged users for this period', 'opti-behavior' ); ?></div>
                                <div class="optibehavior-empty-sub"><?php esc_html_e( 'As visitors engage more, you\'ll see them ranked here.', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- Note: Top users loading script is now properly enqueued via wp_add_inline_script() in get_dashboard_scripts() method -->

                    <!-- Top Pages -->
                    <div id="top-pages-widget" class="dashboard-widget pages-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title">
                                <i data-lucide="file-text"></i>
                                <?php esc_html_e( 'Top Pages', 'opti-behavior' ); ?>
                                <?php opti_behavior_tooltip_e( $tooltips['top_pages']['title'], $tooltips['top_pages']['content'], $tooltips['top_pages']['simple'], '', array( 'position' => 'bottom' ) ); ?>
                            </h3>
                        </div>
                        <div class="widget-content">
                            <div class="top-pages">
                                <?php if ( empty( $dashboard_data['charts']['top_pages'] ) ) : ?>
                                    <div class="optibehavior-empty-state is-visible">
                                        <svg viewBox='0 0 24 24' fill='none' stroke='#9ca3af' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'><path d='M4 3h14a2 2 0 0 1 2 2v14l-4-4H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z'/></svg>
                                        <div class="optibehavior-empty-title"><?php esc_html_e( 'No page views yet', 'opti-behavior' ); ?></div>
                                        <div class="optibehavior-empty-sub"><?php esc_html_e( 'Once visitors view pages, you will see them here.', 'opti-behavior' ); ?></div>
                                    </div>
                                <?php else : ?>
                                    <?php foreach ( $dashboard_data['charts']['top_pages'] as $page_index => $page ) : ?>
                                        <?php
                                        $rank               = $page_index + 1;
                                        $title              = isset( $page['title'] ) ? (string) $page['title'] : '';
                                        $url                = isset( $page['url'] ) ? (string) $page['url'] : '';
                                        $views              = isset( $page['views'] ) ? (int) $page['views'] : 0;
                                        $sessions           = isset( $page['sessions'] ) ? (int) $page['sessions'] : 0;
                                        $clicks             = isset( $page['clicks'] ) ? (int) $page['clicks'] : 0;
                                        $views_change       = isset( $page['views_change'] ) ? (int) $page['views_change'] : 0;
                                        $views_trend        = isset( $page['views_trend'] ) ? (string) $page['views_trend'] : 'neutral';
                                        $clicks_change      = isset( $page['clicks_change'] ) ? (int) $page['clicks_change'] : 0;
                                        $clicks_trend       = isset( $page['clicks_trend'] ) ? (string) $page['clicks_trend'] : 'neutral';
                                        $avg_time_formatted = isset( $page['avg_time_formatted'] ) ? (string) $page['avg_time_formatted'] : '0s';
                                        $avg_time_change    = isset( $page['avg_time_change'] ) ? (int) $page['avg_time_change'] : 0;
                                        $avg_time_trend     = isset( $page['avg_time_trend'] ) ? (string) $page['avg_time_trend'] : 'neutral';
                                        $pc_heatmap_url     = isset( $page['pc_heatmap'] ) ? $page['pc_heatmap'] : '';
                                        $mobile_heatmap_url = isset( $page['mobile_heatmap'] ) ? $page['mobile_heatmap'] : '';
                                        $edit_url           = isset( $page['edit_url'] ) ? (string) $page['edit_url'] : '';

                                        $views_trend_class = 'neutral';
                                        $views_arrow       = '–';
                                        if ( 'up' === $views_trend ) {
                                            $views_trend_class = 'positive';
                                            $views_arrow       = '↑';
                                        } elseif ( 'down' === $views_trend ) {
                                            $views_trend_class = 'negative';
                                            $views_arrow       = '↓';
                                        }

                                        $clicks_trend_class = 'neutral';
                                        $clicks_arrow       = '–';
                                        if ( 'up' === $clicks_trend ) {
                                            $clicks_trend_class = 'positive';
                                            $clicks_arrow       = '↑';
                                        } elseif ( 'down' === $clicks_trend ) {
                                            $clicks_trend_class = 'negative';
                                            $clicks_arrow       = '↓';
                                        }

                                        $avg_time_trend_class = 'neutral';
                                        $avg_time_arrow       = '–';
                                        if ( 'up' === $avg_time_trend ) {
                                            $avg_time_trend_class = 'positive';
                                            $avg_time_arrow       = '↑';
                                        } elseif ( 'down' === $avg_time_trend ) {
                                            $avg_time_trend_class = 'negative';
                                            $avg_time_arrow       = '↓';
                                        }

                                        $views_change_display    = abs( $views_change );
                                        $clicks_change_display   = abs( $clicks_change );
                                        $avg_time_change_display = abs( $avg_time_change );

                                        // "Hot" badge heuristic: no dedicated data field exists, so approximate it
                                        // from the existing views trend/change (presentation-only, no data-layer change).
                                        $is_hot = ( 'up' === $views_trend && $views_change_display >= 50 );
                                        ?>
                                        <div class="page-item">
                                            <div class="page-title-row">
                                                <span class="page-rank"><?php echo esc_html( $rank ); ?></span>
                                                <div class="page-title" title="<?php echo esc_attr( $title ); ?>">
                                                    <?php echo esc_html( $title ); ?>
                                                </div>
                                                <?php if ( '' !== $edit_url ) : ?>
                                                    <a class="page-edit-link" href="<?php echo esc_url( $edit_url ); ?>" title="<?php echo esc_attr__( 'Edit this page', 'opti-behavior' ); ?>">
                                                        <i data-lucide="pencil"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ( $is_hot ) : ?>
                                                    <span class="page-hot-badge"><?php echo esc_html__( '🔥 Hot', 'opti-behavior' ); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <a class="page-url" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $url ); ?>">
                                                <?php echo esc_html( $url ); ?>
                                            </a>
                                            <div class="page-metrics">
                                                <div class="metric metric-views" title="<?php echo esc_attr__( 'Heatmap sessions', 'opti-behavior' ); ?>">
                                                    <i class="metric-icon" data-lucide="flame"></i>
                                                    <span class="metric-value"><?php echo esc_html( number_format_i18n( $sessions ) ); ?></span>
                                                    <span class="metric-change <?php echo esc_attr( $views_trend_class ); ?>">
                                                        <span class="metric-arrow"><?php echo esc_html( $views_arrow ); ?></span>
                                                        <span class="metric-percent"><?php echo esc_html( $views_change_display ); ?>%</span>
                                                    </span>
                                                </div>
                                                <div class="metric metric-clicks" title="<?php echo esc_attr__( 'Clicks', 'opti-behavior' ); ?>">
                                                    <i class="metric-icon" data-lucide="mouse-pointer-click"></i>
                                                    <span class="metric-value"><?php echo esc_html( number_format_i18n( $clicks ) ); ?></span>
                                                    <span class="metric-change <?php echo esc_attr( $clicks_trend_class ); ?>">
                                                        <span class="metric-arrow"><?php echo esc_html( $clicks_arrow ); ?></span>
                                                        <span class="metric-percent"><?php echo esc_html( $clicks_change_display ); ?>%</span>
                                                    </span>
                                                </div>
                                                <div class="metric metric-avg-time" title="<?php echo esc_attr__( 'Average time spent', 'opti-behavior' ); ?>">
                                                    <i class="metric-icon" data-lucide="clock"></i>
                                                    <span class="metric-value"><?php echo esc_html( $avg_time_formatted ); ?></span>
                                                    <span class="metric-change <?php echo esc_attr( $avg_time_trend_class ); ?>">
                                                        <span class="metric-arrow"><?php echo esc_html( $avg_time_arrow ); ?></span>
                                                        <span class="metric-percent"><?php echo esc_html( $avg_time_change_display ); ?>%</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Visitor Heatmap Widget (LEVEL 5) -->
                    <div id="visitor-heatmap-widget" class="dashboard-widget visitor-heatmap-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title">
                                <span class="widget-icon"><i data-lucide="flame"></i></span>
                                <?php echo esc_html__( 'Visitor Activity Heatmap', 'opti-behavior' ); ?>
                                <?php opti_behavior_tooltip_e( $tooltips['visitor_heatmap']['title'], $tooltips['visitor_heatmap']['content'], $tooltips['visitor_heatmap']['simple'], $tooltips['visitor_heatmap']['example'], array( 'position' => 'bottom' ) ); ?>
                            </h3>
                        </div>
                        <div class="widget-content">
                            <div id="visitor-heatmap-container" class="visitor-heatmap-container"></div>
                        </div>
                    </div>

                <?php $this->render_dashboard_section_close(); ?>
                <?php $this->render_dashboard_section_open( 3, 'audience', __( 'Audience Insights', 'opti-behavior' ), false ); ?>
                    <!-- LEVEL 5.5: New vs Returning Visitors + Visited Directories + New Registered Users -->
                    <!-- New vs Returning Visitors Widget -->
                    <?php
                    if ( isset( $dashboard_data['new_vs_returning'] ) ) {
                        $this->render_new_vs_returning_widget( $dashboard_data['new_vs_returning'] );
                    }
                    ?>

                    <!-- Visited Directories Widget -->
                    <?php
                    if ( isset( $dashboard_data['visited_directories'] ) ) {
                        $this->render_visited_directories_widget( $dashboard_data['visited_directories'] );
                    }
                    ?>

                    <!-- New Registered Users Widget -->
                    <?php
                    if ( isset( $dashboard_data['new_registered_users'] ) ) {
                        $this->render_new_registered_users_widget( $dashboard_data['new_registered_users'] );
                    }
                    ?>

                    <!-- LEVEL 6: Traffic Classification + Bot Traffic + User Intent -->
                    <!-- Traffic Classification Widget -->
                    <?php
                    if ( isset( $dashboard_data['traffic_classification'] ) ) {
                        $this->render_traffic_classification_widget( $dashboard_data['traffic_classification'] );
                    }
                    ?>

                    <!-- Bot Traffic Widget -->
                    <?php
                    if ( isset( $dashboard_data['bot_traffic'] ) ) {
                        $this->render_bot_traffic_widget( $dashboard_data['bot_traffic'] );
                    }
                    ?>

                    <!-- User Intent Widget -->
                    <div id="user-intent-widget" class="dashboard-widget user-intent-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title">
                                <span class="widget-icon"><i data-lucide="target"></i></span>
                                <?php esc_html_e( 'User Intent', 'opti-behavior' ); ?>
                                <?php opti_behavior_tooltip_e( $tooltips['user_intent']['title'], $tooltips['user_intent']['content'], $tooltips['user_intent']['simple'], $tooltips['user_intent']['example'], array( 'position' => 'bottom' ) ); ?>
                            </h3>
                        </div>
                        <div class="widget-content">
                            <?php $this->render_user_intent_chart(); ?>
                        </div>
                    </div>

                <?php $this->render_dashboard_section_close(); ?>
                <?php $this->render_dashboard_section_open( 4, 'tech-acquisition', __( 'Visitor Environment', 'opti-behavior' ), false ); ?>
                    <!-- LEVEL 7: Top Referrers + Top Countries + Browsers -->
                    <!-- Top Referrers -->
                    <div id="referrers-widget" class="dashboard-widget referrers-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title"><span class="widget-icon"><i data-lucide="link"></i></span> <?php esc_html_e( 'Referrers', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['referrers']['title'], $tooltips['referrers']['content'], $tooltips['referrers']['simple'], $tooltips['referrers']['example'] ?? '', array( 'position' => 'bottom' ) ); ?></h3>
                        </div>
                        <div class="widget-content">
                            <div class="referrers-table-container" style="display: none;">
                                <table class="referrers-table">
                                    <tbody id="referrers-table-body">
                                    </tbody>
                                </table>
                            </div>
                            <div class="optibehavior-empty-state is-visible">
                                <i data-lucide="link" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                                <div class="optibehavior-empty-title"><?php esc_html_e( 'No referrer data available', 'opti-behavior' ); ?></div>
                                <div class="optibehavior-empty-sub"><?php esc_html_e( 'Try broadening the date range or check back later.', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Countries Widget -->
                    <div id="countries-widget" class="dashboard-widget countries-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title"><span class="widget-icon"><i data-lucide="globe"></i></span> <?php esc_html_e( 'Countries', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['countries']['title'], $tooltips['countries']['content'], $tooltips['countries']['simple'], '', array( 'position' => 'bottom' ) ); ?></h3>
                        </div>
                        <div class="widget-content">
                            <div class="countries-table-container">
                                <table class="countries-table">
                                    <tbody id="countries-table-body">
                                        <tr class="loading-row">
                                            <td colspan="2" class="loading-cell">
                                                <div class="optibehavior-empty-state">
                                                    <i data-lucide="globe" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                                                    <div class="optibehavior-empty-title"><?php esc_html_e( 'Loading countries...', 'opti-behavior' ); ?></div>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Browsers Widget -->
                    <div id="browsers-widget" class="dashboard-widget browsers-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title"><span class="widget-icon"><i data-lucide="compass"></i></span> <?php esc_html_e( 'Browsers', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['browsers']['title'], $tooltips['browsers']['content'], $tooltips['browsers']['simple'], $tooltips['browsers']['example'], array( 'position' => 'bottom' ) ); ?></h3>
                        </div>
                        <div class="widget-content">
                            <div class="browsers-table-container" style="display: none;">
                                <table class="browsers-table">
                                    <tbody id="browsers-table-body">
                                    </tbody>
                                </table>
                            </div>
                            <div class="optibehavior-empty-state is-visible">
                                <i data-lucide="compass" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                                <div class="optibehavior-empty-title"><?php esc_html_e( 'No browser data available', 'opti-behavior' ); ?></div>
                                <div class="optibehavior-empty-sub"><?php esc_html_e( 'Try broadening the date range or check back later.', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- LEVEL 8: Device Types + Operating Systems + Screen Resolution -->

                    <!-- Device Types Widget -->
                    <div id="device-types-widget" class="dashboard-widget device-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title"><span class="widget-icon"><i data-lucide="smartphone"></i></span> <?php esc_html_e( 'Device Types', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['device_types']['title'], $tooltips['device_types']['content'], $tooltips['device_types']['simple'], '', array( 'position' => 'bottom' ) ); ?></h3>
                        </div>
                        <div class="widget-content compact">
                            <?php $this->render_device_types_chart(); ?>
                        </div>
                    </div>

                    <!-- Operating Systems Widget -->
                    <div id="operating-systems-widget" class="dashboard-widget os-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title"><span class="widget-icon"><i data-lucide="monitor"></i></span> <?php esc_html_e( 'Operating Systems', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['operating_systems']['title'], $tooltips['operating_systems']['content'], $tooltips['operating_systems']['simple'], '', array( 'position' => 'bottom' ) ); ?></h3>
                        </div>
                        <div class="widget-content compact">
                            <?php $this->render_operating_systems_chart(); ?>
                        </div>
                    </div>

                    <!-- Screen Resolution Widget (LEVEL 15) -->
                    <div id="screen-resolution-widget" class="dashboard-widget resolution-widget">
                        <?php opti_behavior_render_widget_loading(); ?>
                        <div class="widget-header">
                            <h3 class="widget-title"><span class="widget-icon"><i data-lucide="monitor-dot"></i></span> <?php echo esc_html__( 'Screen Resolution', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['screen_resolution']['title'], $tooltips['screen_resolution']['content'], $tooltips['screen_resolution']['simple'], $tooltips['screen_resolution']['example'], array( 'position' => 'bottom' ) ); ?></h3>
                        </div>
                        <div class="widget-content compact">
                            <?php $this->render_screen_resolution_chart(); ?>
                        </div>
                    </div>
                    <!-- This widget will be added in the next task -->

                <?php $this->render_dashboard_section_close(); ?>
                </div>
            </div>
            <?php
        }

        /**
         * Render the opening markup for a collapsible dashboard section.
         *
         * Each section wraps its own .dashboard-grid so the existing per-widget
         * grid spanning rules keep working. Collapsed sections are hidden via the
         * `is-collapsed` class; their widget data is still preloaded in the
         * background by dashboard.js (fetch-ahead, never cached server-side).
         *
         * @since 1.6.9
         * @param int    $index    Section index (1-4).
         * @param string $key      Stable section key used by the JS preload queue.
         * @param string $label    Human-readable, translated section label.
         * @param bool   $expanded Whether the section is expanded on initial render.
         */
        private function render_dashboard_section_open( $index, $key, $label, $expanded = false ) {
            $index = (int) $index;
            ?>
            <section class="ob-dash-section<?php echo $expanded ? '' : ' is-collapsed'; ?>" data-section-key="<?php echo esc_attr( $key ); ?>" data-section-index="<?php echo esc_attr( (string) $index ); ?>">
                <button type="button" class="ob-dash-section-header" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="ob-dash-section-body-<?php echo esc_attr( (string) $index ); ?>">
                    <span class="ob-dash-section-heading">
                        <span class="ob-dash-section-marker" aria-hidden="true"></span>
                        <span class="ob-dash-section-title"><?php echo esc_html( $label ); ?></span>
                    </span>
                    <span class="ob-dash-section-summary" data-section-summary="<?php echo esc_attr( $key ); ?>" aria-hidden="true">
                        <?php for ( $chip_i = 0; $chip_i < 3; $chip_i++ ) : ?>
                            <span class="ob-dash-chip is-loading">
                                <span class="ob-dash-chip-icon"></span>
                                <span class="ob-dash-chip-body">
                                    <span class="ob-dash-chip-label"></span>
                                    <span class="ob-dash-chip-value"></span>
                                </span>
                            </span>
                        <?php endfor; ?>
                    </span>
                    <span class="ob-dash-section-toggle" aria-hidden="true"><i data-lucide="chevron-down"></i></span>
                </button>
                <div class="ob-dash-section-body" id="ob-dash-section-body-<?php echo esc_attr( (string) $index ); ?>">
                    <div class="dashboard-grid">
            <?php
        }

        /**
         * Render the closing markup for a collapsible dashboard section.
         *
         * @since 1.6.9
         */
        private function render_dashboard_section_close() {
            ?>
                    </div>
                </div>
            </section>
            <?php
        }

        /**
         * Render user intent pie chart
         */
        private function render_user_intent_chart() {
            $intent_data = $this->get_user_intent_data();
            $has_data = $intent_data['total_sessions'] > 0;

            // Always emit the canvas + container so the JS widget can upgrade it once
            // the async widget AJAX delivers fresh data. The PHP render and the AJAX
            // render use different paths (cached vs force-live), so the initial
            // synchronous render can report zero while the AJAX reports data. If we
            // omitted the canvas here, initUserIntentWidget() would hit its
            // `if (!intentEl) return;` guard and never draw the chart, leaving the
            // widget stuck on "No data available" despite a valid payload.
            // The empty state below is a sibling the JS toggles (show/hide), not a
            // replacement for the canvas.
            ?>
            <div class="user-intent-chart-container">
                <canvas id="user-intent-chart" width="300" height="300"<?php echo $has_data ? '' : ' style="display:none;"'; ?>></canvas>
                <table class="user-intent-legend">
                    <tbody id="user-intent-legend-body">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
                <div class="optibehavior-empty-state<?php echo $has_data ? '' : ' is-visible'; ?>"<?php echo $has_data ? ' style="display:none;"' : ''; ?>>
                    <i data-lucide="target" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                    <div class="optibehavior-empty-title"><?php esc_html_e( 'No data available', 'opti-behavior' ); ?></div>
                    <div class="optibehavior-empty-sub"><?php esc_html_e( 'Try broadening the date range or check back later.', 'opti-behavior' ); ?></div>
                </div>
            </div>
            <!-- Note: User intent chart script is now properly enqueued via wp_add_inline_script() in get_dashboard_scripts() method -->
            <?php
        }

        /**
         * Get user intent data for the chart
         */
        private function get_user_intent_data($start_date = null, $end_date = null, array $filters = array()) {
            global $wpdb;

            // Use provided dates or default to last 30 days.
            // SCALE FIX (C2-4): the default end date used to be gmdate('Y-m-d H:i:s') —
            // a per-SECOND value baked into the cache key, so the transient never hit
            // and the (expensive at scale) per-session metric battery re-ran on every
            // dashboard load. Floor the default range to a stable 15-minute bucket so
            // consecutive loads share one cache entry; explicit caller dates unchanged.
            // (Bucket must not be shorter than the large-install TTL below, otherwise
            // the key rolls over before the transient expires and caching is moot.)
            if (!$start_date || !$end_date) {
                $bucket     = (int) floor( time() / 900 ) * 900;
                $end_date   = gmdate( 'Y-m-d H:i:s', $bucket );
                $start_date = gmdate( 'Y-m-d H:i:s', $bucket - 30 * DAY_IN_SECONDS );
            }

            $has_filters = ! empty( $filters );

            // Honour the dashboard force-live contract: a force-refresh request must
            // bypass the cache even when the record-count threshold would allow it,
            // so this widget never serves a stale/empty value behind the live KPIs.
            // Advanced filters always force the live path (cache keys don't encode them).
            $use_cache = ! $has_filters
                && $this->should_use_dashboard_cache( $start_date, $end_date, 'user_intent' )
                && ! ( function_exists( 'opti_behavior_is_force_refresh_requested' ) && opti_behavior_is_force_refresh_requested() );
            if ( $has_filters ) {
                // Filtered baseline: the same live filtered sessions count used by the KPIs,
                // so this widget's total reconciles with the filtered dashboard.
                $baseline = $this->get_sessions_count( $start_date, $end_date, $filters );
                $context  = array(
                    'start' => $start_date,
                    'end'   => $end_date,
                );
            } else {
                $context   = method_exists( $this, 'get_dashboard_stats_context_for_range' )
                    ? $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'session' )
                    : array(
                        'start' => $start_date,
                        'end'   => $end_date,
                    );
                $baseline  = method_exists( $this, 'get_dashboard_dimension_session_baseline' )
                    ? $this->get_dashboard_dimension_session_baseline( $context )
                    : $this->get_sessions_count( $start_date, $end_date );
            }

            // Check cache only when enough records exist to justify caching.
            $exclude_spam = $this->is_spam_excluded();
            $cache_key = 'opti_behavior_user_intent_' . md5( 'frozen-baseline-v2|' . $start_date . $end_date . '|' . $baseline . '|' . ( $exclude_spam ? '1' : '0' ) );
            if ( $use_cache ) {
                $cached = get_transient( $cache_key );
                if ( false !== $cached ) {
                    return $cached;
                }
            } elseif ( ! $has_filters ) {
                delete_transient( $cache_key );
                delete_transient( 'opti_behavior_user_intent_' . md5( $start_date . $end_date . '0' ) );
            }

            // Get spam exclusion clause
            $spam_clause = $this->get_spam_exclusion_clause( 's' );

            // Get user-defined intent rules from settings
            $intent_rules = get_option( 'opti_behavior_intent_rules', array(
                'low_intent' => array(
                    'time_spent' => 10,
                    'clicks' => 1,
                    'scroll_depth' => 25,
                ),
                'medium_intent' => array(
                    'time_spent' => 30,
                    'clicks' => 3,
                    'scroll_depth' => 50,
                ),
                'high_intent' => array(
                    'time_spent' => 60,
                    'clicks' => 5,
                    'scroll_depth' => 75,
                ),
            ) );

            $low_rules = $intent_rules['low_intent'];
            $medium_rules = $intent_rules['medium_intent'];
            $high_rules = $intent_rules['high_intent'];

            // Calculate previous period
            $duration = max(1, strtotime($end_date) - strtotime($start_date) + 1);
            $prev_end = gmdate('Y-m-d H:i:s', strtotime($start_date) - 1);
            $prev_start = gmdate('Y-m-d H:i:s', strtotime($start_date) - $duration);

            // PERF + CORRECTNESS: the previous implementation joined sessions to BOTH
            // events and pageviews in one query and GROUPed BY session. That is a 3-way
            // fan-out: a session with N events and M pageviews produced N*M rows, so
            // (a) the temp table exploded (this widget measured ~12 s on live), and
            // (b) COUNT(CASE WHEN e.event IN (16,17)) — the click_count used for intent
            // classification — was multiplied by the pageview count, silently corrupting
            // the result. We now aggregate each child table separately against the
            // sampled session set (no fan-out): correct counts, far less work.
            $sessions      = $this->get_user_intent_session_metrics( $start_date, $end_date, $spam_clause, $filters );
            $prev_sessions = $this->get_user_intent_session_metrics( $prev_start, $prev_end, $spam_clause, $filters );

            // Current period classification using user-defined rules
            $low_intent = 0;
            $medium_intent = 0;
            $high_intent = 0;
            $total_sessions = count($sessions);

            foreach ($sessions as $session) {
                $duration = (int) $session->duration;
                $clicks = (int) $session->click_count;
                $scroll_depth = (int) ($session->max_scroll_depth ?? 0);

                // Classify based on user-defined rules using scoring system
                // Count how many high intent criteria are met (need at least 2 out of 3)
                $high_score = 0;
                if ($duration >= $high_rules['time_spent']) $high_score++;
                if ($clicks >= $high_rules['clicks']) $high_score++;
                if ($scroll_depth >= $high_rules['scroll_depth']) $high_score++;

                // High intent: At least 2 out of 3 criteria meet high thresholds
                if ($high_score >= 2) {
                    $high_intent++;
                }
                else {
                    // Count how many medium intent criteria are met (need at least 2 out of 3)
                    $medium_score = 0;
                    if ($duration >= $medium_rules['time_spent']) $medium_score++;
                    if ($clicks >= $medium_rules['clicks']) $medium_score++;
                    if ($scroll_depth >= $medium_rules['scroll_depth']) $medium_score++;

                    // Medium intent: At least 2 out of 3 criteria meet medium thresholds
                    if ($medium_score >= 2) {
                        $medium_intent++;
                    }
                    // Low intent: Does not meet at least 2 medium thresholds
                    else {
                        $low_intent++;
                    }
                }
            }

            // Previous period classification
            $prev_low_intent = 0;
            $prev_medium_intent = 0;
            $prev_high_intent = 0;

            foreach ($prev_sessions as $session) {
                $duration = (int) $session->duration;
                $clicks = (int) $session->click_count;
                $scroll_depth = (int) ($session->max_scroll_depth ?? 0);

                // Use same classification logic for previous period
                // Count how many high intent criteria are met (need at least 2 out of 3)
                $high_score = 0;
                if ($duration >= $high_rules['time_spent']) $high_score++;
                if ($clicks >= $high_rules['clicks']) $high_score++;
                if ($scroll_depth >= $high_rules['scroll_depth']) $high_score++;

                // High intent: At least 2 out of 3 criteria meet high thresholds
                if ($high_score >= 2) {
                    $prev_high_intent++;
                }
                else {
                    // Count how many medium intent criteria are met (need at least 2 out of 3)
                    $medium_score = 0;
                    if ($duration >= $medium_rules['time_spent']) $medium_score++;
                    if ($clicks >= $medium_rules['clicks']) $medium_score++;
                    if ($scroll_depth >= $medium_rules['scroll_depth']) $medium_score++;

                    // Medium intent: At least 2 out of 3 criteria meet medium thresholds
                    if ($medium_score >= 2) {
                        $prev_medium_intent++;
                    }
                    // Low intent: Does not meet at least 2 medium thresholds
                    else {
                        $prev_low_intent++;
                    }
                }
            }

            if ( $baseline !== $total_sessions ) {
                if ( $baseline > $total_sessions ) {
                    $low_intent += $baseline - $total_sessions;
                } else {
                    $overage = $total_sessions - $baseline;
                    $low_reduction = min( $low_intent, $overage );
                    $low_intent -= $low_reduction;
                    $overage -= $low_reduction;

                    if ( $overage > 0 ) {
                        $medium_reduction = min( $medium_intent, $overage );
                        $medium_intent -= $medium_reduction;
                        $overage -= $medium_reduction;
                    }

                    if ( $overage > 0 ) {
                        $high_intent = max( 0, $high_intent - $overage );
                    }
                }

                $total_sessions = absint( $baseline );
            }

            // Calculate percentages
            if ($total_sessions > 0) {
                $low_percentage = round(($low_intent / $total_sessions) * 100, 1);
                $medium_percentage = round(($medium_intent / $total_sessions) * 100, 1);
                $high_percentage = round(($high_intent / $total_sessions) * 100, 1);
            } else {
                $low_percentage = $medium_percentage = $high_percentage = 0;
            }

            $result = array(
                'low_sessions' => $low_intent,
                'medium_sessions' => $medium_intent,
                'high_sessions' => $high_intent,
                'low_percentage' => $low_percentage,
                'medium_percentage' => $medium_percentage,
                'high_percentage' => $high_percentage,
                'total_sessions' => $total_sessions,
                'prev_low_sessions' => $prev_low_intent,
                'prev_medium_sessions' => $prev_medium_intent,
                'prev_high_sessions' => $prev_high_intent
            );

            if ( $use_cache ) {
                $ttl = $this->get_dashboard_cache_ttl( 'user_intent' );
                // SCALE FIX (C2-4): at large scale this widget costs seconds even after
                // sampling; a 60s TTL made every-other admin load pay it again. Hold the
                // result for at least 16 minutes on large installs (outlives the
                // 15-minute default-date bucket so the key, not the TTL, expires first).
                $core_ttl = class_exists( 'Opti_Behavior_Heatmap_Core' ) ? Opti_Behavior_Heatmap_Core::get_instance() : null;
                $db_ttl   = $core_ttl ? $core_ttl->get_database() : null;
                if ( $db_ttl && method_exists( $db_ttl, 'is_large_table' )
                    && ( $db_ttl->is_large_table( $wpdb->prefix . 'optibehavior_events' )
                        || $db_ttl->is_large_table( $wpdb->prefix . 'optibehavior_pageviews' ) ) ) {
                    $ttl = max( $ttl, 960 );
                }
                set_transient( $cache_key, $result, $ttl );
            }

            return $result;
        }

        /**
         * Collect per-session intent metrics (duration, click count, max scroll depth)
         * for a date range WITHOUT a fan-out join.
         *
         * Sampling keeps the LIMIT 10000 behaviour of the original query: we first take
         * up to 10k sessions in range, then aggregate clicks (events 16/17) and scroll
         * depth for exactly that session set in two separate, index-friendly queries.
         * Returns an array of objects with ->duration, ->click_count, ->max_scroll_depth
         * so the existing classification loop is unchanged.
         *
         * @since 1.x
         * @param string $start_date  Range start (Y-m-d H:i:s).
         * @param string $end_date    Range end (Y-m-d H:i:s).
         * @param string $spam_clause Spam-exclusion SQL fragment for the `s` alias.
         * @param array  $filters     Optional advanced filters (allow-listed, see build_advanced_filters_sql()).
         * @return array<int,object>
         */
        private function get_user_intent_session_metrics( $start_date, $end_date, $spam_clause, array $filters = array() ) {
            global $wpdb;

            // Advanced filters: append the shared WHERE fragment; join visitors as `v`
            // because visitor-attribute filters (browser/country/device/os/...) target it.
            $filter_sql  = $this->build_advanced_filters_sql( $filters );
            $filter_join = '' !== $filter_sql['where']
                ? " LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id"
                : '';

            // SCALE GUARD: on large installs a 10k-id IN() list against a multi-million-row
            // events table blows past the range-optimizer memory cap, so MySQL abandons the
            // session_id index and full-scans (measured 160s+ on 5M rows). Two defences:
            // (a) shrink the sample on large installs — this widget reports percentages, so
            //     a 2k statistical sample is equivalent; (b) chunk the IN() lists (see below)
            //     so every child query stays comfortably on the index.
            $sample_limit = 10000;
            $core         = class_exists( 'Opti_Behavior_Heatmap_Core' ) ? Opti_Behavior_Heatmap_Core::get_instance() : null;
            $database     = $core ? $core->get_database() : null;
            if ( $database
                && method_exists( $database, 'is_large_table' )
                && ( $database->is_large_table( $wpdb->prefix . 'optibehavior_events' )
                    || $database->is_large_table( $wpdb->prefix . 'optibehavior_pageviews' ) )
            ) {
                /**
                 * Filters the user-intent session sample size on large installs.
                 *
                 * @param int $sample_limit Maximum sessions sampled for intent classification.
                 */
                // 250 (one IN() chunk): at 5M events each 500-id chunk costs ~1.5-2s of
                // random IO on a cold buffer pool; the old 2000 sample meant 4 chunks
                // x 2 tables x 2 periods = 16 queries (~25s measured, C2-4). One small
                // chunk per table/period keeps the whole widget under ~4s cold while a
                // 250-session sample still gives ~+/-6% accuracy on a percentage pie.
                $sample_limit = (int) apply_filters( 'opti_behavior_user_intent_large_sample', 250 );
            }

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            // Step 1: sample sessions in range (duration only).
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $spam_clause is an internal hard-coded SQL fragment; date values are bound via prepare().
            $session_rows = $wpdb->get_results(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    "SELECT s.id, s.duration
                    FROM {$wpdb->prefix}optibehavior_sessions s{$filter_join}
                    WHERE s.start_time >= %s AND s.start_time <= %s{$spam_clause}" . $filter_sql['where'] . "
                    LIMIT %d",
                    array_merge( array( $start_date, $end_date ), $filter_sql['params'], array( $sample_limit ) )
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( empty( $session_rows ) ) {
                return array();
            }

            // Session ids are opaque strings (e.g. "session_1782...") — NOT integers — so
            // they must be SQL-escaped and quoted for the IN() list, never cast to int.
            // Chunked to 500 ids per query: keeps the IN() list inside the range-optimizer
            // memory cap so the session_id index is always used (no full-scan fallback).
            $quoted = array();
            foreach ( $session_rows as $row ) {
                $quoted[] = "'" . esc_sql( (string) $row->id ) . "'";
            }
            $id_chunks = array_chunk( $quoted, 500 );

            // Step 2: click counts per session (events 16/17), no pageview fan-out.
            $click_map = array();
            foreach ( $id_chunks as $chunk ) {
                $id_list = implode( ',', $chunk );
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $id_list is a comma-joined list of esc_sql()'d, quoted session ids; analytics aggregate.
                $click_rows = $wpdb->get_results(
                    "SELECT e.session_id AS sid, COUNT(*) AS click_count
                    FROM {$wpdb->prefix}optibehavior_events e
                    WHERE e.session_id IN ({$id_list}) AND e.event IN (16, 17)
                    GROUP BY e.session_id"
                );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                foreach ( (array) $click_rows as $row ) {
                    $click_map[ (string) $row->sid ] = (int) $row->click_count;
                }
            }

            // Step 3: max scroll depth per session, no event fan-out.
            $scroll_map = array();
            foreach ( $id_chunks as $chunk ) {
                $id_list = implode( ',', $chunk );
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $id_list is a comma-joined list of esc_sql()'d, quoted session ids; analytics aggregate.
                $scroll_rows = $wpdb->get_results(
                    "SELECT pv.session_id AS sid, MAX(pv.scroll_depth) AS max_scroll_depth
                    FROM {$wpdb->prefix}optibehavior_pageviews pv
                    WHERE pv.session_id IN ({$id_list})
                    GROUP BY pv.session_id"
                );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                foreach ( (array) $scroll_rows as $row ) {
                    $scroll_map[ (string) $row->sid ] = (int) $row->max_scroll_depth;
                }
            }

            // Merge into the shape the classification loop expects.
            foreach ( $session_rows as $row ) {
                $sid                   = (string) $row->id;
                $row->click_count      = isset( $click_map[ $sid ] ) ? $click_map[ $sid ] : 0;
                $row->max_scroll_depth = isset( $scroll_map[ $sid ] ) ? $scroll_map[ $sid ] : 0;
            }

            return $session_rows;
        }

        /**
         * Render device types pie chart
         */
        private function render_device_types_chart() {
            $device_data = $this->get_device_types_data_for_chart();
            $has_data = $device_data['total_sessions'] > 0;
            ?>
            <div class="user-intent-chart-container">
                <canvas id="device-types-pie" width="300" height="300"></canvas>
                <table class="device-types-legend">
                    <tbody id="device-types-legend-body">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
            </div>
            <!-- Note: Device types chart script is now properly enqueued via wp_add_inline_script() in get_dashboard_scripts() method -->
            <?php
        }

        /**
         * Get device types data formatted for the chart
         */
        private function get_device_types_data_for_chart() {
            // Get the current date range from dashboard data
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters used for filtering dashboard view (read-only operation)
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash not needed for sanitize_text_field
            $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : 'last7days';
            $start_q = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
            $end_q = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

            // Get date range
            $date_range = $this->get_date_range($period, $start_q, $end_q);
            $start_date = $date_range['start'];
            $end_date = $date_range['end'];

            // Get device types data
            $device_types = $this->get_device_types_data($start_date, $end_date);

            $desktop_sessions = 0;
            $mobile_sessions = 0;
            $tablet_sessions = 0;
            $total_sessions = 0;

            foreach ($device_types as $device) {
                $count = (int) $device['count'];
                $total_sessions += $count;

                switch ($device['name']) {
                    case 'Desktop':
                        $desktop_sessions = $count;
                        break;
                    case 'Mobile':
                        $mobile_sessions = $count;
                        break;
                    case 'Tablet':
                        $tablet_sessions = $count;
                        break;
                }
            }

            // Calculate percentages
            $desktop_percentage = $total_sessions > 0 ? round(($desktop_sessions / $total_sessions) * 100, 1) : 0;
            $mobile_percentage = $total_sessions > 0 ? round(($mobile_sessions / $total_sessions) * 100, 1) : 0;
            $tablet_percentage = $total_sessions > 0 ? round(($tablet_sessions / $total_sessions) * 100, 1) : 0;

            return array(
                'desktop_sessions' => $desktop_sessions,
                'mobile_sessions' => $mobile_sessions,
                'tablet_sessions' => $tablet_sessions,
                'desktop_percentage' => $desktop_percentage,
                'mobile_percentage' => $mobile_percentage,
                'tablet_percentage' => $tablet_percentage,
                'total_sessions' => $total_sessions
            );
        }

        /**
         * Render operating systems pie chart
         */
        private function render_operating_systems_chart() {
            $os_data = $this->get_operating_systems_data_for_chart();
            $has_data = $os_data['total_sessions'] > 0;
            ?>
            <div class="user-intent-chart-container">
                <canvas id="operating-systems-chart" width="300" height="300"></canvas>
                <div class="os-table-container">
                    <table class="os-legend">
                        <tbody id="os-legend-body">
                            <!-- Populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Note: Operating systems chart script is now properly enqueued via wp_add_inline_script() in get_dashboard_scripts() method -->
            <?php
        }

        /**
         * Get operating systems data formatted for the chart
         */
        private function get_operating_systems_data_for_chart() {
            // Nonce verification not required for GET parameters used for read-only data filtering
            // This method is called from admin pages protected by 'manage_options' capability
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters used for filtering dashboard view (read-only operation)
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash not needed for sanitize_text_field
            $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : 'last7days';
            $start_q = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
            $end_q = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

            // Get date range
            $date_range = $this->get_date_range($period, $start_q, $end_q);
            $start_date = $date_range['start'];
            $end_date = $date_range['end'];

            // Get operating systems data
            $operating_systems = $this->get_operating_systems_data($start_date, $end_date);

            $total_sessions = 0;
            $os_counts = array();

            // Count sessions for each OS
            foreach ($operating_systems as $os) {
                $count = (int) $os['count'];
                $total_sessions += $count;
                $os_counts[$os['os']] = $count;
            }

            // Define OS colors and CSS classes
            $os_config = array(
                'Windows' => array('color' => '#0078d4', 'css_class' => 'windows-os'),
                'macOS' => array('color' => '#000000', 'css_class' => 'macos-os'),
                'iOS' => array('color' => '#007aff', 'css_class' => 'ios-os'),
                'Android' => array('color' => '#3ddc84', 'css_class' => 'android-os'),
                'Linux' => array('color' => '#fcc624', 'css_class' => 'linux-os'),
                'ChromeOS' => array('color' => '#4285f4', 'css_class' => 'chromeos-os'),
            );

            // Default colors for unknown OS
            $default_colors = array('#6b7280', '#9ca3af', '#d1d5db', '#e5e7eb');
            $color_index = 0;

            $os_list = array();
            foreach ($os_counts as $os_name => $sessions) {
                if (isset($os_config[$os_name])) {
                    $color = $os_config[$os_name]['color'];
                    $css_class = $os_config[$os_name]['css_class'];
                } else {
                    $color = $default_colors[$color_index % count($default_colors)];
                    $css_class = 'unknown-os';
                    $color_index++;
                }

                $percentage = $total_sessions > 0 ? round(($sessions / $total_sessions) * 100, 1) : 0;

                $os_list[] = array(
                    'name' => $os_name,
                    'sessions' => $sessions,
                    'percentage' => $percentage,
                    'color' => $color,
                    'css_class' => $css_class
                );
            }

            // Sort by session count (descending)
            usort($os_list, function($a, $b) {
                return $b['sessions'] - $a['sessions'];
            });

            return array(
                'os_list' => $os_list,
                'total_sessions' => $total_sessions
            );
        }

        /**
         * Render traffic classification widget
         *
         * @param array $data Traffic classification data.
         */
        private function render_traffic_classification_widget( $data ) {
            $spam_count = 0;
            if ( ! empty( $data['spam']['detected_count'] ) ) {
                $spam_count = absint( $data['spam']['detected_count'] );
            } elseif ( ! empty( $data['spam_detected_count'] ) ) {
                $spam_count = absint( $data['spam_detected_count'] );
            } elseif ( ! empty( $data['spam']['count'] ) ) {
                $spam_count = absint( $data['spam']['count'] );
            }

            $has_data = ! empty( $data ) && ( ( isset( $data['total'] ) && $data['total'] > 0 ) || $spam_count > 0 );

            if ( isset( $data['spam']['detected_percentage'] ) ) {
                $spam_percentage = (float) $data['spam']['detected_percentage'];
            } elseif ( isset( $data['spam_detected_percentage'] ) ) {
                $spam_percentage = (float) $data['spam_detected_percentage'];
            } else {
                $spam_percentage = ( $has_data && isset( $data['spam']['percentage'] ) ) ? (float) $data['spam']['percentage'] : 0;
            }
            $spam_percentage_decimals = floor( $spam_percentage ) === $spam_percentage ? 0 : 1;
            $spam_percentage_label = number_format_i18n( $spam_percentage, $spam_percentage_decimals );

            ?>
            <div id="traffic-classification-widget" class="dashboard-widget traffic-classification-widget">
                <?php opti_behavior_render_widget_loading(); ?>
                <?php $tooltips = opti_behavior_get_dashboard_tooltips(); ?>
                <div class="widget-header">
                    <h3 class="widget-title">
                        <span class="widget-icon"><i data-lucide="traffic-cone"></i></span>
                        <?php esc_html_e( 'Traffic Classification', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['traffic_classification']['title'], $tooltips['traffic_classification']['content'], $tooltips['traffic_classification']['simple'], $tooltips['traffic_classification']['example'], array( 'position' => 'bottom' ) ); ?>
                    </h3>
                </div>
                <div class="widget-content">
                    <div class="optibehavior-empty-state <?php echo $has_data ? '' : 'is-visible'; ?>">
                        <i data-lucide="traffic-cone" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                        <div class="optibehavior-empty-title"><?php esc_html_e( 'No data available', 'opti-behavior' ); ?></div>
                        <div class="optibehavior-empty-sub"><?php esc_html_e( 'Try broadening the date range or check back later.', 'opti-behavior' ); ?></div>
                    </div>
                    <div class="user-intent-chart-container" style="<?php echo $has_data ? '' : 'display: none;'; ?>">
                        <canvas id="traffic-classification-chart" width="300" height="300"></canvas>
                        <table class="traffic-classification-legend">
                            <tbody id="traffic-classification-legend-body">
                                <!-- Populated by JavaScript -->
                            </tbody>
                        </table>
                        <div id="traffic-spam-summary-card" class="traffic-spam-summary-card <?php echo $spam_count > 0 ? 'has-spam' : 'is-zero'; ?>" aria-live="polite">
                            <div class="traffic-spam-card-copy">
                                <span class="traffic-spam-card-icon" aria-hidden="true"><i data-lucide="shield-alert"></i></span>
                                <span class="traffic-spam-card-text">
                                    <span class="traffic-spam-card-label"><?php esc_html_e( 'Spam Traffic', 'opti-behavior' ); ?></span>
                                    <span class="traffic-spam-card-subtitle"><?php esc_html_e( 'Flagged sessions', 'opti-behavior' ); ?></span>
                                </span>
                            </div>
                            <div class="traffic-spam-card-metric">
                                <span id="traffic-spam-count" class="traffic-spam-card-count"><?php echo esc_html( number_format_i18n( $spam_count ) ); ?></span>
                                <span class="traffic-spam-card-percent">
                                    <span id="traffic-spam-percentage"><?php echo esc_html( $spam_percentage_label ); ?>%</span>
                                    <?php esc_html_e( 'of traffic', 'opti-behavior' ); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Note: Traffic classification chart script is now properly enqueued via wp_add_inline_script() in get_dashboard_scripts() method -->
            <?php
        }

        /**
         * Render bot traffic widget
         *
         * @param array $data Bot traffic data.
         */
        private function render_bot_traffic_widget( $data ) {
            $has_data = ! empty( $data ) && isset( $data['total'] ) && $data['total'] > 0;

            $total = $has_data ? intval( $data['total'] ) : 0;
            $bots = $has_data && isset( $data['bots'] ) ? $data['bots'] : array();
            $change_pct = $has_data && isset( $data['change_percentage'] ) ? intval( $data['change_percentage'] ) : 0;
            ?>
            <div id="bot-traffic-widget" class="dashboard-widget bot-traffic-widget">
                <?php opti_behavior_render_widget_loading(); ?>
                <?php $tooltips = opti_behavior_get_dashboard_tooltips(); ?>
                <div class="widget-header">
                    <h3 class="widget-title">
                        <span class="widget-icon"><i data-lucide="bot"></i></span>
                        <?php esc_html_e( 'Bot Traffic', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['bot_traffic']['title'], $tooltips['bot_traffic']['content'], $tooltips['bot_traffic']['simple'], $tooltips['bot_traffic']['example'], array( 'position' => 'bottom' ) ); ?>
                    </h3>
                    <div class="widget-meta" id="bot-traffic-meta" style="<?php echo $has_data ? '' : 'display: none;'; ?>">
                        <span class="total-count"><span id="bot-traffic-total"><?php echo esc_html( number_format( $total ) ); ?></span> <?php esc_html_e( 'visits', 'opti-behavior' ); ?></span>
                        <span class="change-indicator <?php echo $change_pct > 0 ? 'positive' : 'negative'; ?>" id="bot-traffic-change" style="<?php echo $change_pct !== 0 ? '' : 'display: none;'; ?>">
                            <span id="bot-traffic-change-value"><?php echo $change_pct > 0 ? '↑' : '↓'; ?> <?php echo esc_html( abs( $change_pct ) ); ?>%</span>
                        </span>
                    </div>
                </div>
                <div class="widget-content">
                    <div class="optibehavior-empty-state <?php echo $has_data ? '' : 'is-visible'; ?>">
                        <i data-lucide="bot" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                        <div class="optibehavior-empty-title"><?php esc_html_e( 'No bot visits detected', 'opti-behavior' ); ?></div>
                        <div class="optibehavior-empty-sub"><?php esc_html_e( 'Bot visits will appear here when detected.', 'opti-behavior' ); ?></div>
                    </div>
                    <div class="bot-traffic-list" id="bot-traffic-list" style="<?php echo $has_data ? '' : 'display: none;'; ?>">
                        <?php if ( $has_data ) : ?>
                            <?php foreach ( $bots as $bot_type => $bot_data ) : ?>
                                <?php
                                $count      = intval( $bot_data['count'] );
                                $percentage = floatval( $bot_data['percentage'] );
                                $bot_change = isset( $bot_data['change'] ) ? intval( $bot_data['change'] ) : 0;
                                $bot_label  = $this->get_bot_label( $bot_type );
                                $bot_icon   = $this->get_bot_icon( $bot_type );
                                if ( $bot_change > 0 ) {
                                    $trend_class = 'trend-up';
                                    $trend_points = '18 15 12 9 6 15';
                                } elseif ( $bot_change < 0 ) {
                                    $trend_class = 'trend-down';
                                    $trend_points = '6 9 12 15 18 9';
                                } else {
                                    $trend_class = 'trend-neutral';
                                    $trend_points = '';
                                }
                                ?>
                                <div class="bot-item ob-metric-row">
                                    <div class="bot-info ob-metric-main">
                                        <span class="bot-icon"><?php echo wp_kses( $bot_icon, array( 'i' => array( 'data-lucide' => true, 'class' => true ) ) ); ?></span>
                                        <span class="bot-label ob-metric-label"><?php echo esc_html( $bot_label ); ?></span>
                                    </div>
                                    <div class="bot-stats ob-metric-values">
                                        <span class="visitor-count"><?php echo esc_html( number_format( $count ) ); ?></span>
                                        <span class="visitor-percentage"><?php echo esc_html( $percentage ); ?>%</span>
                                        <span class="visitor-trend <?php echo esc_attr( $trend_class ); ?>">
                                            <?php if ( '' !== $trend_points ) : ?>
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="<?php echo esc_attr( $trend_points ); ?>"></polyline>
                                            </svg>
                                            <?php else : ?>
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                            </svg>
                                            <?php endif; ?>
                                            <?php echo esc_html( abs( $bot_change ) ); ?>%
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <!-- Will be populated by JavaScript when data loads asynchronously -->
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Get bot label from bot type
         *
         * @param string $bot_type Bot type.
         * @return string Bot label.
         */
        private function get_bot_label( $bot_type ) {
            $labels = array(
                'googlebot'       => 'Googlebot',
                'googlebot-image' => 'Googlebot Image',
                'googlebot-news'  => 'Googlebot News',
                'bingbot'         => 'Bingbot',
                'bingpreview'     => 'Bing Preview',
                'yahoo'           => 'Yahoo Slurp',
                'duckduckbot'     => 'DuckDuckBot',
                'baiduspider'     => 'Baidu Spider',
                'yandexbot'       => 'Yandex Bot',
                'facebookbot'     => 'Facebook Bot',
                'twitterbot'      => 'Twitter Bot',
                'linkedinbot'     => 'LinkedIn Bot',
                'pinterestbot'    => 'Pinterest Bot',
                'whatsapp'        => 'WhatsApp',
                'ahrefsbot'       => 'Ahrefs Bot',
                'semrushbot'      => 'SEMrush Bot',
                'mj12bot'         => 'Majestic Bot',
                'dotbot'          => 'Moz DotBot',
                'screaming-frog'  => 'Screaming Frog',
                'uptimerobot'     => 'UptimeRobot',
                'pingdom'         => 'Pingdom',
                'statuspage'      => 'StatusPage',
                'custom'          => 'Custom Bot',
            );

            return isset( $labels[ $bot_type ] ) ? $labels[ $bot_type ] : ucfirst( str_replace( array( '-', '_' ), ' ', $bot_type ) );
        }

        /**
         * Get bot icon from bot type
         *
         * @param string $bot_type Bot type.
         * @return string Bot icon (Lucide icon HTML).
         */
        private function get_bot_icon( $bot_type ) {
            $icons = array(
                'googlebot'       => '<i data-lucide="search"></i>',
                'googlebot-image' => '<i data-lucide="image"></i>',
                'googlebot-news'  => '<i data-lucide="newspaper"></i>',
                'bingbot'         => '<i data-lucide="search"></i>',
                'bingpreview'     => '<i data-lucide="eye"></i>',
                'yahoo'           => '<i data-lucide="search"></i>',
                'duckduckbot'     => '<i data-lucide="search"></i>',
                'baiduspider'     => '<i data-lucide="bug"></i>',
                'yandexbot'       => '<i data-lucide="search"></i>',
                'facebookbot'     => '<i data-lucide="users"></i>',
                'twitterbot'      => '<i data-lucide="twitter"></i>',
                'linkedinbot'     => '<i data-lucide="linkedin"></i>',
                'pinterestbot'    => '<i data-lucide="pin"></i>',
                'whatsapp'        => '<i data-lucide="message-circle"></i>',
                'ahrefsbot'       => '<i data-lucide="bar-chart"></i>',
                'semrushbot'      => '<i data-lucide="trending-up"></i>',
                'mj12bot'         => '<i data-lucide="link"></i>',
                'dotbot'          => '<i data-lucide="link"></i>',
                'screaming-frog'  => '<i data-lucide="bug"></i>',
                'uptimerobot'     => '<i data-lucide="clock"></i>',
                'pingdom'         => '<i data-lucide="radio"></i>',
                'statuspage'      => '<i data-lucide="activity"></i>',
                'custom'          => '<i data-lucide="bot"></i>',
            );

            return isset( $icons[ $bot_type ] ) ? $icons[ $bot_type ] : '<i data-lucide="bot"></i>';
        }

        /**
         * Get screen resolution data for chart
         *
         * @return array
         */
        private function get_screen_resolution_data_for_chart() {
            // Nonce verification not required for GET parameters used for read-only data filtering
            // This method is called from admin pages protected by 'manage_options' capability
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters used for filtering dashboard view (read-only operation)
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash not needed for sanitize_text_field
            $period = isset( $_GET['period'] ) ? sanitize_text_field( $_GET['period'] ) : 'last7days';
            $start_q = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : null;
            $end_q = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : null;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

            // Get date range
            $date_range = $this->get_date_range( $period, $start_q, $end_q );
            $start_date = $date_range['start'];
            $end_date = $date_range['end'];

            // Get screen resolution data
            $screen_resolutions = $this->get_screen_resolution_data( $start_date, $end_date );

            $total_sessions = 0;
            $resolution_counts = array();

            // Count sessions for each resolution
            foreach ( $screen_resolutions as $resolution ) {
                $count = (int) $resolution['count'];
                $total_sessions += $count;
                $resolution_counts[ $resolution['resolution'] ] = $count;
            }

            // Define color palette for resolutions
            $colors = array( '#6366F1', '#10B981', '#F59E0B', '#EF4444', '#3B82F6', '#8B5CF6', '#14B8A6', '#F472B6', '#84CC16', '#06B6D4' );
            $color_index = 0;

            $resolution_list = array();
            foreach ( $resolution_counts as $resolution_name => $sessions ) {
                $color = $colors[ $color_index % count( $colors ) ];
                $color_index++;

                $percentage = $total_sessions > 0 ? round( ( $sessions / $total_sessions ) * 100, 1 ) : 0;

                $resolution_list[] = array(
                    'name' => $resolution_name,
                    'sessions' => $sessions,
                    'percentage' => $percentage,
                    'color' => $color,
                    'css_class' => 'resolution-' . sanitize_html_class( $resolution_name ),
                );
            }

            // Sort by session count (descending)
            usort(
                $resolution_list,
                function ( $a, $b ) {
                    return $b['sessions'] - $a['sessions'];
                }
            );

            return array(
                'resolution_list' => $resolution_list,
                'total_sessions' => $total_sessions,
            );
        }

        /**
         * Render screen resolution pie chart
         *
         * @return void
         */
        private function render_screen_resolution_chart() {
            ?>
            <div class="user-intent-chart-container">
                <canvas id="screen-resolution-chart" width="300" height="300"></canvas>
                <div class="resolution-table-container">
                    <table class="resolution-legend">
                        <tbody id="resolution-legend-body">
                            <!-- Populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Note: Screen resolution chart script is now properly enqueued via wp_add_inline_script() in get_dashboard_scripts() method -->
            <?php
        }

        /**
         * Render new vs returning visitors widget
         *
         * @param array $data New vs returning visitors data.
         */
        private function render_new_vs_returning_widget( $data ) {
            $has_data = ! empty( $data ) && isset( $data['total'] ) && $data['total'] > 0;

            ?>
            <div id="new-vs-returning-widget" class="dashboard-widget new-vs-returning-widget">
                <?php opti_behavior_render_widget_loading(); ?>
                <?php $tooltips = opti_behavior_get_dashboard_tooltips(); ?>
                <div class="widget-header">
                    <h3 class="widget-title">
                        <span class="widget-icon"><i data-lucide="users-round"></i></span>
                        <?php esc_html_e( 'New vs Returning Visitors', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['new_vs_returning']['title'], $tooltips['new_vs_returning']['content'], $tooltips['new_vs_returning']['simple'], $tooltips['new_vs_returning']['example'], array( 'position' => 'bottom' ) ); ?>
                    </h3>
                </div>
                <div class="widget-content">
                    <div class="optibehavior-empty-state <?php echo $has_data ? '' : 'is-visible'; ?>">
                        <i data-lucide="users-round" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                        <div class="optibehavior-empty-title"><?php esc_html_e( 'No visitor data available', 'opti-behavior' ); ?></div>
                        <div class="optibehavior-empty-sub"><?php esc_html_e( 'Try broadening the date range or check back later.', 'opti-behavior' ); ?></div>
                    </div>
                    <div class="user-intent-chart-container" style="<?php echo $has_data ? '' : 'display: none;'; ?>">
                        <canvas id="new-vs-returning-chart" width="300" height="300"></canvas>
                        <table class="traffic-classification-legend">
                            <tbody id="new-vs-returning-legend-body">
                                <!-- Populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Render visited directories widget
         *
         * @param array $data Visited directories data.
         */
        private function render_visited_directories_widget( $data ) {
            $has_data = ! empty( $data ) && isset( $data['directories'] ) && ! empty( $data['directories'] );
            ?>
            <div id="visited-directories-widget" class="dashboard-widget visited-directories-widget">
                <?php opti_behavior_render_widget_loading(); ?>
                <?php $tooltips = opti_behavior_get_dashboard_tooltips(); ?>
                <div class="widget-header">
                    <h3 class="widget-title">
                        <span class="widget-icon"><i data-lucide="folder-tree"></i></span>
                        <?php esc_html_e( 'Visited Directories', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['visited_directories']['title'], $tooltips['visited_directories']['content'], $tooltips['visited_directories']['simple'], $tooltips['visited_directories']['example'], array( 'position' => 'bottom' ) ); ?>
                    </h3>
                </div>
                <div class="widget-content">
                    <div class="optibehavior-empty-state <?php echo $has_data ? '' : 'is-visible'; ?>">
                        <i data-lucide="folder-tree" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                        <div class="optibehavior-empty-title"><?php esc_html_e( 'No directory data available', 'opti-behavior' ); ?></div>
                        <div class="optibehavior-empty-sub"><?php esc_html_e( 'Try broadening the date range or check back later.', 'opti-behavior' ); ?></div>
                    </div>
                    <div class="visited-directories-list" style="<?php echo $has_data ? '' : 'display: none;'; ?>">
                        <table class="traffic-classification-legend">
                            <tbody id="visited-directories-legend-body">
                                <!-- Populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Render new registered users widget
         *
         * @param array $data New registered users data.
         */
        private function render_new_registered_users_widget( $data ) {
            $has_data = ! empty( $data ) && isset( $data['total'] ) && $data['total'] > 0;
            ?>
            <div id="new-registered-users-widget" class="dashboard-widget new-registered-users-widget">
                <?php opti_behavior_render_widget_loading(); ?>
                <?php $tooltips = opti_behavior_get_dashboard_tooltips(); ?>
                <div class="widget-header">
                    <h3 class="widget-title">
                        <span class="widget-icon"><i data-lucide="user-plus"></i></span>
                        <?php esc_html_e( 'Visitor Authentication', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['visitor_auth']['title'], $tooltips['visitor_auth']['content'], $tooltips['visitor_auth']['simple'], '', array( 'position' => 'bottom' ) ); ?>
                    </h3>
                </div>
                <div class="widget-content">
                    <div class="optibehavior-empty-state <?php echo $has_data ? '' : 'is-visible'; ?>">
                        <i data-lucide="user-plus" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
                        <div class="optibehavior-empty-title"><?php esc_html_e( 'No visitors data', 'opti-behavior' ); ?></div>
                        <div class="optibehavior-empty-sub"><?php esc_html_e( 'Visitor and registration data will appear here.', 'opti-behavior' ); ?></div>
                    </div>
                    <div class="user-intent-chart-container" id="new-registered-users-chart-container" style="<?php echo $has_data ? '' : 'display: none;'; ?>">
                        <canvas id="new-registered-users-chart"></canvas>
                        <table class="traffic-classification-legend">
                            <tbody id="new-registered-users-legend-body">
                                <!-- Populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php
        }

		/**
		 * Render the Pro Trial Banner.
		 *
		 * Displays one of three banner variants depending on trial state:
		 * - invite:  Encourages user to start 6-month free trial
		 * - active:  Shows countdown and download link
		 * - expired: Shows trial ended message with upgrade link
		 *
		 * Banner is hidden when Pro is active with valid manifest,
		 * or when the user has recently dismissed it.
		 *
		 * @since 1.1.3
		 */
		private function render_pro_trial_banner() {
			$state = $this->get_trial_banner_state();

			if ( 'hidden' === $state['display'] ) {
				return;
			}

			// Build CSS classes
			$classes = 'opti-behavior-trial-banner';
			if ( 'active' === $state['display'] ) {
				$classes .= ' opti-behavior-trial-active';
			} elseif ( 'expired' === $state['display'] ) {
				$classes .= ' opti-behavior-trial-expired';
			}

			?>
			<div class="<?php echo esc_attr( $classes ); ?>">
				<?php if ( 'invite' === $state['display'] ) : ?>
					<?php // --- INVITE STATE --- ?>
					<div class="opti-behavior-trial-banner-content">
						<div class="opti-behavior-trial-banner-icon">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/></svg>
						</div>
						<div class="opti-behavior-trial-banner-text">
							<p class="opti-behavior-trial-banner-title">
								<?php
								printf(
									/* translators: %s: highlighted "6 months" text */
									esc_html__( 'Try Pro FREE for %s — No Credit Card Needed!', 'opti-behavior' ),
									'<span class="opti-behavior-trial-highlight">' . esc_html__( '6 Months', 'opti-behavior' ) . '</span>'
								);
								?>
							</p>
							<p class="opti-behavior-trial-banner-description">
								<?php esc_html_e( 'Unlock session recordings, error tracking, user journeys, form analytics & advanced heatmaps. One click to activate.', 'opti-behavior' ); ?>
							</p>
							<div class="opti-behavior-trial-features">
								<span class="opti-behavior-trial-feature-pill">
									<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>
									<?php esc_html_e( 'Recordings', 'opti-behavior' ); ?>
								</span>
								<span class="opti-behavior-trial-feature-pill">
									<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
									<?php esc_html_e( 'Error Tracking', 'opti-behavior' ); ?>
								</span>
								<span class="opti-behavior-trial-feature-pill">
									<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>
									<?php esc_html_e( 'User Journeys', 'opti-behavior' ); ?>
								</span>
								<span class="opti-behavior-trial-feature-pill">
									<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
									<?php esc_html_e( 'Form Analytics', 'opti-behavior' ); ?>
								</span>
							</div>
						</div>
					</div>
					<div class="opti-behavior-trial-banner-actions">
						<button type="button" class="opti-behavior-trial-btn-start">
							<span class="opti-behavior-trial-spinner"></span>
							<span class="opti-behavior-trial-btn-icon">
								<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg>
							</span>
							<?php esc_html_e( 'Start Free Trial', 'opti-behavior' ); ?>
						</button>
						<button type="button" class="opti-behavior-trial-btn-dismiss" title="<?php esc_attr_e( 'Dismiss', 'opti-behavior' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</button>
					</div>

				<?php elseif ( 'active' === $state['display'] ) : ?>
					<?php // --- ACTIVE STATE --- ?>
					<div class="opti-behavior-trial-banner-content">
						<div class="opti-behavior-trial-success-check">
							<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
						</div>
						<div class="opti-behavior-trial-banner-text">
							<p class="opti-behavior-trial-banner-title">
								<?php esc_html_e( 'Pro Trial Active', 'opti-behavior' ); ?>
							</p>
							<p class="opti-behavior-trial-banner-description">
								<?php
								if ( function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active() ) {
									esc_html_e( 'All Pro features are unlocked. Enjoy session recordings, error tracking, and more!', 'opti-behavior' );
								} else {
									esc_html_e( 'Your trial is active! Download and install the Pro plugin to unlock all premium features.', 'opti-behavior' );
								}
								?>
							</p>
						</div>
					</div>
					<div class="opti-behavior-trial-banner-actions">
						<span class="opti-behavior-trial-banner-countdown">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
							<?php
							printf(
								/* translators: %d: number of days remaining */
								esc_html__( '%d days left', 'opti-behavior' ),
								(int) $state['days_left']
							);
							?>
						</span>
						<?php if ( ! ( function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active() ) ) : ?>
							<a href="<?php echo esc_url( $state['download_url'] ); ?>" class="opti-behavior-trial-btn-download" target="_blank" rel="noopener noreferrer">
								<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
								<?php esc_html_e( 'Download Pro Plugin', 'opti-behavior' ); ?>
							</a>
						<?php endif; ?>
						<button type="button" class="opti-behavior-trial-btn-dismiss" title="<?php esc_attr_e( 'Dismiss', 'opti-behavior' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</button>
					</div>

				<?php elseif ( 'expired' === $state['display'] ) : ?>
					<?php // --- EXPIRED STATE --- ?>
					<div class="opti-behavior-trial-banner-content">
						<div class="opti-behavior-trial-banner-icon">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
						</div>
						<div class="opti-behavior-trial-banner-text">
							<p class="opti-behavior-trial-banner-title">
								<?php esc_html_e( 'Your Pro Trial Has Ended', 'opti-behavior' ); ?>
							</p>
							<p class="opti-behavior-trial-banner-description">
								<?php esc_html_e( 'Your 6-month free trial has expired. Upgrade to continue using session recordings, error tracking, and all premium features.', 'opti-behavior' ); ?>
							</p>
						</div>
					</div>
					<div class="opti-behavior-trial-banner-actions">
						<a href="<?php echo esc_url( $state['download_url'] ); ?>" class="opti-behavior-trial-btn-upgrade" target="_blank" rel="noopener noreferrer">
							<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
							<?php esc_html_e( 'Upgrade to Pro', 'opti-behavior' ); ?>
						</a>
						<button type="button" class="opti-behavior-trial-btn-dismiss" title="<?php esc_attr_e( 'Dismiss', 'opti-behavior' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</button>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}

    }
}

