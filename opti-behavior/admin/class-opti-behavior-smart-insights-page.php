<?php
/**
 * Smart Insights Admin Page
 *
 * Renders the Free plugin Smart Insights center. The list, filters, detail
 * panel, refresh, and status actions are powered by the existing secure AJAX
 * endpoints to keep page loads resilient during upgrades.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights admin page controller.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Page {

	/**
	 * Heatmap core instance.
	 *
	 * @var Opti_Behavior_Heatmap_Core|null
	 */
	private $heatmap;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Heatmap_Core|null $heatmap Heatmap core instance.
	 */
	public function __construct( $heatmap = null ) {
		$this->heatmap = $heatmap;
	}

	/**
	 * Render the Smart Insights center.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access Smart Insights.', 'opti-behavior' ) );
		}

		// QA-B-SI-072: the landing period has to be the one the generator and
		// the scheduler actually pre-generate, otherwise the default view can
		// never be served from a cron run and every client that omits `period`
		// silently gets a different window than the select shows.
		$default_period = class_exists( 'Opti_Behavior_Smart_Insights_Generator' )
			? Opti_Behavior_Smart_Insights_Generator::DEFAULT_PERIOD
			: 'last30days';
		$period     = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : $default_period; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		$period     = '' !== $period ? $period : $default_period;
		$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		$end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		$insight_id = isset( $_GET['insight_id'] ) ? absint( wp_unslash( $_GET['insight_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link state.
		$exclude_spam = $this->resolve_spam_exclusion_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		$GLOBALS['opti_behavior_exclude_spam'] = $exclude_spam;
		$exclude_spam_icon    = $exclude_spam ? 'shield-check' : 'shield-off';
		$exclude_spam_classes = 'filter-btn ob-smart-insights-exclude-spam' . ( $exclude_spam ? ' active' : '' );
		$exclude_spam_label   = $exclude_spam ? __( 'Excluding Spam', 'opti-behavior' ) : __( 'Including Spam', 'opti-behavior' );
		$exclude_spam_title   = $exclude_spam ? __( 'Spam traffic is excluded from Smart Insights', 'opti-behavior' ) : __( 'Spam traffic is included in Smart Insights', 'opti-behavior' );
		$has_pro              = $this->viewer_has_pro_access();
		$upgrade_url          = $this->get_pro_download_url();
		$tooltips             = function_exists( 'opti_behavior_get_smart_insights_tooltips' ) ? opti_behavior_get_smart_insights_tooltips() : array();
		?>
		<div class="wrap opti-behavior-dashboard-page ob-smart-insights-center-page">
			<section
				class="ob-smart-insights ob-smart-insights-center"
				data-ob-smart-insights-context="center"
				data-period="<?php echo esc_attr( $period ); ?>"
				data-start-date="<?php echo esc_attr( $start_date ); ?>"
				data-end-date="<?php echo esc_attr( $end_date ); ?>"
				data-deep-open-insight-id="<?php echo esc_attr( $insight_id ); ?>"
				data-exclude-spam="<?php echo esc_attr( $exclude_spam ? '1' : '0' ); ?>"
			>
				<div class="dashboard-header ob-smart-insights-dashboard-header">
					<div class="ob-smart-insights-header-main">
						<div class="dashboard-title-section">
							<div class="dashboard-icon" aria-hidden="true"><i data-lucide="lightbulb"></i></div>
							<div class="dashboard-title-text">
								<h1 class="dashboard-title">
									<?php esc_html_e( 'Smart Insights', 'opti-behavior' ); ?>
									<?php if ( ! empty( $tooltips['center_heading'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
										<?php opti_behavior_tooltip_e( $tooltips['center_heading']['title'], $tooltips['center_heading']['content'], $tooltips['center_heading']['simple'], '', array( 'position' => 'right' ) ); ?>
									<?php endif; ?>
								</h1>
								<p class="dashboard-subtitle"><?php esc_html_e( 'Prioritize the clearest behavioral opportunities, evidence, and next actions in one analyst briefing.', 'opti-behavior' ); ?></p>
							</div>
						</div>
						<div class="dashboard-controls ob-smart-insights-header-actions">
							<button type="button" class="refresh-btn ob-smart-insights-refresh">
								<i data-lucide="refresh-cw" aria-hidden="true"></i>
								<?php esc_html_e( 'Refresh insights', 'opti-behavior' ); ?>
								<?php if ( ! empty( $tooltips['refresh_insights'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
									<?php opti_behavior_tooltip_e( $tooltips['refresh_insights']['title'], $tooltips['refresh_insights']['content'], $tooltips['refresh_insights']['simple'], '', array( 'position' => 'bottom', 'align' => 'right' ) ); ?>
								<?php endif; ?>
							</button>
							<button type="button" class="<?php echo esc_attr( $exclude_spam_classes ); ?>" id="smart-insights-exclude-spam-toggle" aria-pressed="<?php echo esc_attr( $exclude_spam ? 'true' : 'false' ); ?>" aria-label="<?php echo esc_attr( $exclude_spam_label ); ?>" title="<?php echo esc_attr( $exclude_spam_title ); ?>">
								<span class="filter-icon"><i data-lucide="<?php echo esc_attr( $exclude_spam_icon ); ?>" aria-hidden="true"></i></span>
								<span class="filter-label"><?php echo esc_html( $exclude_spam_label ); ?></span>
							</button>
						</div>
					</div>

					<div class="ob-smart-insights-header-filters">
						<div class="ob-smart-insights-filters" aria-label="<?php esc_attr_e( 'Smart Insights filters', 'opti-behavior' ); ?>">
							<label>
								<span><?php esc_html_e( 'Period', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['period_filter'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['period_filter']['title'], $tooltips['period_filter']['content'], $tooltips['period_filter']['simple'], '', array( 'position' => 'bottom' ) ); } ?></span>
								<select class="ob-smart-insights-period">
									<option value="last7days" <?php selected( $period, 'last7days' ); ?>><?php esc_html_e( 'Last 7 Days', 'opti-behavior' ); ?></option>
									<option value="last14days" <?php selected( $period, 'last14days' ); ?>><?php esc_html_e( 'Last 14 Days', 'opti-behavior' ); ?></option>
									<option value="last30days" <?php selected( $period, 'last30days' ); ?>><?php esc_html_e( 'Last 30 Days', 'opti-behavior' ); ?></option>
									<option value="last90days" <?php selected( $period, 'last90days' ); ?>><?php esc_html_e( 'Last 3 Months', 'opti-behavior' ); ?></option>
									<option value="today" <?php selected( $period, 'today' ); ?>><?php esc_html_e( 'Today', 'opti-behavior' ); ?></option>
									<option value="yesterday" <?php selected( $period, 'yesterday' ); ?>><?php esc_html_e( 'Yesterday', 'opti-behavior' ); ?></option>
									<option value="custom" <?php selected( $period, 'custom' ); ?>><?php esc_html_e( 'Custom Range', 'opti-behavior' ); ?></option>
								</select>
							</label>
							<label class="ob-smart-insights-custom-date" <?php echo 'custom' === $period ? '' : 'hidden style="display:none;"'; ?>>
								<span><?php esc_html_e( 'Start date', 'opti-behavior' ); ?></span>
								<input type="date" class="ob-smart-insights-start-date" value="<?php echo esc_attr( $start_date ); ?>" />
							</label>
							<label class="ob-smart-insights-custom-date" <?php echo 'custom' === $period ? '' : 'hidden style="display:none;"'; ?>>
								<span><?php esc_html_e( 'End date', 'opti-behavior' ); ?></span>
								<input type="date" class="ob-smart-insights-end-date" value="<?php echo esc_attr( $end_date ); ?>" />
							</label>
							<label>
								<span><?php esc_html_e( 'Status', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['status_filter'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['status_filter']['title'], $tooltips['status_filter']['content'], $tooltips['status_filter']['simple'], '', array( 'position' => 'bottom' ) ); } ?></span>
								<select class="ob-smart-insights-status">
									<option value="active"><?php esc_html_e( 'Active', 'opti-behavior' ); ?></option>
									<option value="new"><?php esc_html_e( 'New', 'opti-behavior' ); ?></option>
									<option value="viewed"><?php esc_html_e( 'Reviewed', 'opti-behavior' ); ?></option>
									<option value="in_progress"><?php esc_html_e( 'In progress', 'opti-behavior' ); ?></option>
									<option value="resolved"><?php esc_html_e( 'Resolved', 'opti-behavior' ); ?></option>
									<option value="ignored"><?php esc_html_e( 'Ignored', 'opti-behavior' ); ?></option>
									<option value="auto_resolved"><?php esc_html_e( 'Auto-resolved', 'opti-behavior' ); ?></option>
									<option value=""><?php esc_html_e( 'All statuses', 'opti-behavior' ); ?></option>
								</select>
							</label>
							<label class="ob-smart-insights-search-label">
								<span><?php esc_html_e( 'Search', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['search_filter'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['search_filter']['title'], $tooltips['search_filter']['content'], $tooltips['search_filter']['simple'], '', array( 'position' => 'bottom' ) ); } ?></span>
								<input type="search" class="ob-smart-insights-search" placeholder="<?php esc_attr_e( 'Search signal, page, source, form...', 'opti-behavior' ); ?>" />
							</label>
							<label>
								<span><?php esc_html_e( 'Severity', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['severity_filter'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['severity_filter']['title'], $tooltips['severity_filter']['content'], $tooltips['severity_filter']['simple'], '', array( 'position' => 'bottom' ) ); } ?></span>
								<select class="ob-smart-insights-severity">
									<option value=""><?php esc_html_e( 'All severities', 'opti-behavior' ); ?></option>
									<option value="critical"><?php esc_html_e( 'Critical', 'opti-behavior' ); ?></option>
									<option value="high"><?php esc_html_e( 'High', 'opti-behavior' ); ?></option>
									<option value="medium"><?php esc_html_e( 'Medium', 'opti-behavior' ); ?></option>
									<option value="low"><?php esc_html_e( 'Low', 'opti-behavior' ); ?></option>
								</select>
							</label>
							<label>
								<span><?php esc_html_e( 'Sort', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['sort_filter'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['sort_filter']['title'], $tooltips['sort_filter']['content'], $tooltips['sort_filter']['simple'], '', array( 'position' => 'bottom', 'align' => 'right' ) ); } ?></span>
								<select class="ob-smart-insights-sort">
									<option value="priority" selected><?php esc_html_e( 'Priority first', 'opti-behavior' ); ?></option>
									<option value="timeline"><?php esc_html_e( 'Newest detected', 'opti-behavior' ); ?></option>
									<option value="impact"><?php esc_html_e( 'Biggest impact', 'opti-behavior' ); ?></option>
								</select>
							</label>
							<div class="ob-smart-insights-filter-actions">
								<button type="button" class="button ob-smart-insights-apply">
									<?php esc_html_e( 'Update dates', 'opti-behavior' ); ?>
								</button>
								<button type="button" class="button-link ob-smart-insights-reset" hidden>
									<?php esc_html_e( 'Reset filters', 'opti-behavior' ); ?>
								</button>
							</div>
							<details class="ob-smart-insights-advanced-filters">
								<summary><?php esc_html_e( 'Advanced filters', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['advanced_filters'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['advanced_filters']['title'], $tooltips['advanced_filters']['content'], $tooltips['advanced_filters']['simple'], '', array( 'position' => 'bottom' ) ); } ?></summary>
								<div class="ob-smart-insights-advanced-filter-grid">
									<label>
										<span><?php esc_html_e( 'Category', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['category_filter'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['category_filter']['title'], $tooltips['category_filter']['content'], $tooltips['category_filter']['simple'], '', array( 'position' => 'bottom' ) ); } ?></span>
										<select class="ob-smart-insights-category">
											<option value=""><?php esc_html_e( 'All categories', 'opti-behavior' ); ?></option>
										</select>
									</label>
									<label>
										<span><?php esc_html_e( 'Entity type', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['entity_type_filter'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['entity_type_filter']['title'], $tooltips['entity_type_filter']['content'], $tooltips['entity_type_filter']['simple'], '', array( 'position' => 'bottom' ) ); } ?></span>
										<select class="ob-smart-insights-entity-type">
											<option value=""><?php esc_html_e( 'All entities', 'opti-behavior' ); ?></option>
										</select>
									</label>
									<label>
										<span><?php esc_html_e( 'Confidence', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['confidence_filter'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['confidence_filter']['title'], $tooltips['confidence_filter']['content'], $tooltips['confidence_filter']['simple'], '', array( 'position' => 'bottom', 'align' => 'right' ) ); } ?></span>
										<select class="ob-smart-insights-confidence">
											<option value=""><?php esc_html_e( 'All confidence levels', 'opti-behavior' ); ?></option>
											<option value="high"><?php esc_html_e( 'High', 'opti-behavior' ); ?></option>
											<option value="medium"><?php esc_html_e( 'Medium', 'opti-behavior' ); ?></option>
											<option value="low"><?php esc_html_e( 'Low', 'opti-behavior' ); ?></option>
										</select>
									</label>
								</div>
							</details>
						</div>
					</div>
				</div>

				<div class="dashboard-content ob-smart-insights-center-content">
					<div class="dashboard-widget ob-smart-insights-weekly-summary <?php echo $has_pro ? 'is-pro-ready' : 'is-free-placeholder'; ?>">
						<div class="widget-header">
							<h2 class="widget-title">
								<span class="widget-icon" aria-hidden="true"><i data-lucide="calendar-check"></i></span>
								<?php esc_html_e( 'Weekly CRO Summary', 'opti-behavior' ); ?>
								<?php if ( ! empty( $tooltips['weekly_summary'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
									<?php opti_behavior_tooltip_e( $tooltips['weekly_summary']['title'], $tooltips['weekly_summary']['content'], $tooltips['weekly_summary']['simple'], '', array( 'position' => 'bottom' ) ); ?>
								<?php endif; ?>
								<?php if ( ! $has_pro ) : ?>
									<span class="ob-smart-insights-badge is-locked"><?php esc_html_e( 'Pro', 'opti-behavior' ); ?></span>
								<?php endif; ?>
							</h2>
						</div>
						<div class="widget-content">
							<?php if ( $has_pro ) : ?>
								<p class="ob-smart-insights-summary-loading"><?php esc_html_e( 'Loading weekly CRO summary…', 'opti-behavior' ); ?></p>
							<?php else : ?>
								<div class="ob-smart-insights-weekly-lock-card" aria-label="<?php esc_attr_e( 'Weekly CRO Summary is locked in Free mode', 'opti-behavior' ); ?>">
									<div class="ob-smart-insights-weekly-lock-hero">
										<span class="ob-smart-insights-weekly-lock-icon dashicons dashicons-lock" aria-hidden="true"></span>
										<div>
											<strong><?php esc_html_e( 'Unlock your weekly CRO briefing.', 'opti-behavior' ); ?></strong>
											<p><?php esc_html_e( 'Pro summarizes priority patterns and recommended actions across your Smart Insights without exposing protected report data in Free.', 'opti-behavior' ); ?></p>
										</div>
									</div>
									<div class="ob-smart-insights-weekly-preview-grid" aria-label="<?php esc_attr_e( 'Protected Weekly CRO Summary preview', 'opti-behavior' ); ?>">
										<article class="ob-smart-insights-weekly-preview-tile">
											<span class="dashicons dashicons-update" aria-hidden="true"></span>
											<strong><?php esc_html_e( 'Recurring issues', 'opti-behavior' ); ?></strong>
											<p><?php esc_html_e( 'Patterns that keep returning across the week.', 'opti-behavior' ); ?></p>
										</article>
										<article class="ob-smart-insights-weekly-preview-tile">
											<span class="dashicons dashicons-chart-line" aria-hidden="true"></span>
											<strong><?php esc_html_e( 'Biggest improvement', 'opti-behavior' ); ?></strong>
											<p><?php esc_html_e( 'The strongest positive movement to learn from.', 'opti-behavior' ); ?></p>
										</article>
										<article class="ob-smart-insights-weekly-preview-tile">
											<span class="dashicons dashicons-warning" aria-hidden="true"></span>
											<strong><?php esc_html_e( 'Biggest decline', 'opti-behavior' ); ?></strong>
											<p><?php esc_html_e( 'The highest-risk change needing review.', 'opti-behavior' ); ?></p>
										</article>
										<article class="ob-smart-insights-weekly-preview-tile">
											<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
											<strong><?php esc_html_e( 'Recommended next action', 'opti-behavior' ); ?></strong>
											<p><?php esc_html_e( 'One prioritized action for the next optimization step.', 'opti-behavior' ); ?></p>
										</article>
									</div>
									<div class="ob-smart-insights-weekly-lock-actions">
										<a class="button button-primary ob-smart-insights-weekly-upgrade" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Upgrade to unlock summary', 'opti-behavior' ); ?>
										</a>
										<span class="ob-smart-insights-weekly-lock-note">
											<span class="dashicons dashicons-shield" aria-hidden="true"></span>
											<?php esc_html_e( 'Pro-only metrics stay protected until Pro is active.', 'opti-behavior' ); ?>
										</span>
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<div class="ob-smart-insights-alert" role="status" hidden></div>
					<div class="ob-smart-insights-list-help">
						<strong><?php esc_html_e( 'Behavior opportunities', 'opti-behavior' ); ?></strong>
						<?php if ( ! empty( $tooltips['insight_cards'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
							<?php opti_behavior_tooltip_e( $tooltips['insight_cards']['title'], $tooltips['insight_cards']['content'], $tooltips['insight_cards']['simple'], '', array( 'position' => 'bottom' ) ); ?>
						<?php endif; ?>
					</div>
					<div class="ob-smart-insights-list" aria-live="polite">
						<div class="ob-smart-insights-loading">
							<span class="ob-smart-insights-spinner" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Loading Smart Insights...', 'opti-behavior' ); ?></span>
						</div>
					</div>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Build a Pro download URL for locked Smart Insights CTAs.
	 *
	 * @return string
	 */
	private function get_pro_download_url() {
		$current_user = wp_get_current_user();
		$cache_key    = 'ob_dl_access_' . md5( $current_user->user_login . site_url() );
		$access_code  = get_transient( $cache_key );

		return add_query_arg(
			array(
				'site_url'    => rawurlencode( site_url() ),
				'username'    => rawurlencode( $current_user->user_login ),
				'email'       => rawurlencode( $current_user->user_email ),
				'access_code' => rawurlencode( $access_code ? $access_code : '' ),
			),
			'https://optiuser.com/opti-behavior/ob-download-pro/'
		);
	}

	/**
	 * Detect whether the current viewer has Pro Smart Insights access.
	 *
	 * @return bool
	 */
	private function viewer_has_pro_access() {
		if ( class_exists( 'Opti_Behavior_Smart_Insights_Capabilities' ) ) {
			$capabilities = new Opti_Behavior_Smart_Insights_Capabilities();
			if ( is_callable( array( $capabilities, 'has_pro_access' ) ) ) {
				return (bool) $capabilities->has_pro_access();
			}
		}

		return (bool) apply_filters( 'opti_behavior_smart_insights_has_pro_access', false, 'smart_insights' );
	}

	/**
	 * Get the Smart Insights default spam exclusion state.
	 *
	 * @return bool
	 */
	private function get_default_spam_exclusion_enabled() {
		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			return Opti_Behavior_Stats_Spam_Filter::is_enabled();
		}

		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled' => true,
			)
		);

		if ( ! is_array( $traffic_settings ) ) {
			return true;
		}

		return ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] );
	}

	/**
	 * Resolve page-level Smart Insights spam exclusion.
	 *
	 * @param array|null $source Request source.
	 * @return bool
	 */
	private function resolve_spam_exclusion_from_request( $source = null ) {
		$source = is_array( $source ) ? $source : array();

		if ( array_key_exists( 'exclude_spam', $source ) ) {
			return '1' === (string) sanitize_text_field( wp_unslash( $source['exclude_spam'] ) );
		}

		return $this->get_default_spam_exclusion_enabled();
	}
}
