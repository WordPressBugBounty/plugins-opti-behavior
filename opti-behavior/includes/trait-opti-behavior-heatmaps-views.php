<?php
/**
 * Heatmaps Views Trait
 *
 * Provides rendering methods for heatmaps page.
 *
 * @package Opti_Behavior
 * @copyright 2025 OptiUser
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! trait_exists( 'Opti_Behavior_Heatmaps_Views_Trait' ) ) {
	/**
	 * Heatmaps Views Trait
	 *
	 * Provides methods for rendering heatmaps page components.
	 *
	 * @since 1.0.0
	 */
	trait Opti_Behavior_Heatmaps_Views_Trait {

		/**
		 * Render heatmaps page header.
		 *
		 * @since 1.0.0
		 */
		private function render_heatmaps_header() {
			$tooltips = opti_behavior_get_heatmaps_tooltips();
			?>
			<div class="heatmaps-header">
				<div class="heatmaps-header-content">
					<div class="heatmaps-title-section">
						<div class="heatmaps-icon"><i data-lucide="flame"></i></div>
						<div class="heatmaps-title-text">
							<h1 class="heatmaps-title"><?php esc_html_e( 'Heatmaps', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['heatmap_overview']['title'], $tooltips['heatmap_overview']['content'], $tooltips['heatmap_overview']['simple'], '', array( 'position' => 'bottom' ) ); ?></h1>
							<div class="heatmaps-subtitle"><?php esc_html_e( 'View and analyze user interaction heatmaps', 'opti-behavior' ); ?></div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Format a Heatmaps duration statistic as minutes and seconds.
		 *
		 * @since 1.2.7
		 *
		 * @param mixed $seconds Duration in seconds.
		 * @return string Duration formatted as M:SS.
		 */
		private function format_heatmap_stat_duration( $seconds ) {
			$seconds = is_numeric( $seconds ) ? (int) round( (float) $seconds ) : 0;
			$seconds = max( 0, $seconds );
			$minutes = (int) floor( $seconds / 60 );
			$remaining_seconds = $seconds % 60;

			return sprintf( '%d:%02d', $minutes, $remaining_seconds );
		}

		/**
		 * Render heatmaps statistics section.
		 *
		 * @since 1.0.0
		 */
		private function render_heatmaps_stats() {
			// Performance: render the KPI shell only and populate it via AJAX
			// (optibehavior_heatmaps_stats), exactly like the heatmap table below.
			// The stat aggregation (get_heatmap_statistics) scans heatmap files and
			// is cached, but on a busy site the cache is flushed on every ingest, so
			// computing it inline blocked the WHOLE page render on a cold pass and
			// produced the long blank-page wait. The data is unchanged — it is just
			// streamed in after first paint instead of before it.
			$tooltips = opti_behavior_get_heatmaps_tooltips();
			?>
			<div class="heatmaps-stats" data-optibehavior-stats>
				<?php $this->render_heatmap_stats_grid( null, $tooltips ); ?>
			</div>
			<?php
		}

		/**
		 * Render the 6 KPI stat cards.
		 *
		 * Shared by the initial skeleton render (when $stats is null → spinner
		 * placeholders) and the AJAX refresh (when $stats holds real values).
		 *
		 * @param array|null $stats    Stat values, or null to render the loading skeleton.
		 * @param array      $tooltips Tooltip copy keyed by card.
		 */
		private function render_heatmap_stats_grid( $stats, $tooltips ) {
			$loading = ! is_array( $stats );
			// Placeholder shown for every number while the data is loading.
			$ph = '<span class="opti-stat-skeleton" aria-hidden="true"></span>';

			$value = function ( $rendered ) use ( $loading, $ph ) {
				return $loading ? $ph : $rendered; // $rendered is already escaped by the caller.
			};
			$change = function ( $growth, $suffix_this_week = true ) use ( $loading ) {
				if ( $loading ) {
					return array( 'class' => 'neutral', 'text' => '' );
				}
				$cls  = $growth >= 0 ? 'positive' : 'negative';
				$text = ( $growth > 0 ? '+' . $growth : $growth ) . '%';
				if ( $suffix_this_week ) {
					$text .= ' ' . __( 'this week', 'opti-behavior' );
				}
				return array( 'class' => $cls, 'text' => $text );
			};

			$c_heatmaps = $change( $loading ? 0 : $stats['heatmaps_growth'] );
			$c_clicks   = $change( $loading ? 0 : $stats['clicks_growth'] );
			// isset() guard: a stale cached payload from before the card swap may
			// still carry the old mobile_* keys instead of sessions_growth.
			$c_sessions = $change( ( $loading || ! isset( $stats['sessions_growth'] ) ) ? 0 : $stats['sessions_growth'] );
			$c_time     = $change( $loading ? 0 : $stats['time_improvement'] );
			$c_ctr      = $change( $loading ? 0 : $stats['ctr_improvement'] );
			?>
			<div class="stats-grid<?php echo $loading ? ' is-loading' : ''; ?>">
				<div class="stat-card">
					<div class="stat-icon"><i data-lucide="flame"></i></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo $value( esc_html( $loading ? '' : $stats['total_heatmaps'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- placeholder is static HTML, value pre-escaped ?></div>
						<div class="stat-label"><?php esc_html_e( 'Total Heatmaps', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['total_heatmaps']['title'], $tooltips['total_heatmaps']['content'], $tooltips['total_heatmaps']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
						<div class="stat-change <?php echo esc_attr( $c_heatmaps['class'] ); ?>"><?php echo esc_html( $c_heatmaps['text'] ); ?></div>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon"><i data-lucide="mouse-pointer-click"></i></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo $value( esc_html( $loading ? '' : number_format( $stats['total_clicks'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- placeholder is static HTML, value pre-escaped ?></div>
						<div class="stat-label"><?php esc_html_e( 'Total Clicks', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['total_clicks']['title'], $tooltips['total_clicks']['content'], $tooltips['total_clicks']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
						<div class="stat-change <?php echo esc_attr( $c_clicks['class'] ); ?>"><?php echo esc_html( $c_clicks['text'] ); ?></div>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon"><i data-lucide="users"></i></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo $value( esc_html( $loading ? '' : number_format( isset( $stats['total_sessions'] ) ? (int) $stats['total_sessions'] : 0 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- placeholder is static HTML, value pre-escaped ?></div>
						<div class="stat-label"><?php esc_html_e( 'Total Sessions', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['total_sessions']['title'], $tooltips['total_sessions']['content'], $tooltips['total_sessions']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
						<div class="stat-change <?php echo esc_attr( $c_sessions['class'] ); ?>"><?php echo esc_html( $c_sessions['text'] ); ?></div>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon"><i data-lucide="clock"></i></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo $value( esc_html( $loading ? '' : $this->format_heatmap_stat_duration( $stats['avg_time'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- placeholder is static HTML, value pre-escaped ?></div>
						<div class="stat-label"><?php esc_html_e( 'Avg. Time on Page', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['avg_time_on_page']['title'], $tooltips['avg_time_on_page']['content'], $tooltips['avg_time_on_page']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
						<div class="stat-change <?php echo esc_attr( $c_time['class'] ); ?>"><?php echo esc_html( $c_time['text'] ); ?></div>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon"><i data-lucide="target"></i></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo $value( esc_html( $loading ? '' : $stats['hottest_page_clicks'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- placeholder is static HTML, value pre-escaped ?></div>
						<div class="stat-label"><?php esc_html_e( 'Hottest Page', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['hottest_page']['title'], $tooltips['hottest_page']['content'], $tooltips['hottest_page']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
						<div class="stat-change neutral"><?php echo $loading ? '' : esc_html( $stats['hottest_page_name'] ); ?></div>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon"><i data-lucide="bar-chart-3"></i></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo $value( $loading ? '' : esc_html( $stats['conversion_rate'] ) . '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- placeholder is static HTML, value pre-escaped ?></div>
						<div class="stat-label"><?php esc_html_e( 'Click-through Rate', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['click_through_rate']['title'], $tooltips['click_through_rate']['content'], $tooltips['click_through_rate']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
						<div class="stat-change <?php echo esc_attr( $c_ctr['class'] ); ?>"><?php echo esc_html( $c_ctr['text'] ); ?></div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render heatmaps content section.
		 *
		 * @since 1.0.0
		 */
		private function render_heatmaps_content() {
			$tooltips = opti_behavior_get_heatmaps_tooltips();
			?>
			<div class="heatmaps-content">
				<div class="heatmaps-panel">
					<div class="panel-header">
						<h3 class="panel-title">
							<i data-lucide="folder-open"></i>
							<?php esc_html_e( 'Available Heatmaps', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['available_heatmaps']['title'], $tooltips['available_heatmaps']['content'], $tooltips['available_heatmaps']['simple'], '', array( 'position' => 'bottom' ) ); ?>
						</h3>
						<div class="heatmaps-search-wrapper">
							<i data-lucide="search" class="heatmaps-search-icon" style="color: #6366f1;"></i>
							<input type="text"
								   id="heatmaps-search"
								   class="heatmaps-search-input"
								   placeholder="<?php esc_attr_e( 'Search heatmaps...', 'opti-behavior' ); ?>"
								   autocomplete="off" />
						</div>
					</div>

					<?php $this->render_simplified_heatmap_table(); ?>
				</div>
			</div>
			<?php
		}
	}
}
