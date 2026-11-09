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
	 * `tab` query value of the Page X-Ray view (Pro feature hosted here).
	 */
	const XRAY_TAB = 'pages';

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
		$upgrade_url          = $this->get_pro_trial_url( 'smart-insights-weekly' );
		$tooltips             = function_exists( 'opti_behavior_get_smart_insights_tooltips' ) ? opti_behavior_get_smart_insights_tooltips() : array();

		$view_args = array(
			'period'       => $period,
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'exclude_spam' => $exclude_spam,
		);
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$page_id   = isset( $_GET['page_id'] ) ? absint( wp_unslash( $_GET['page_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link state.
		// A page dossier keeps its own screen; the two tab views share this one
		// (OPT-2283: a tab click swaps the view under the bar, no page load).
		if ( self::XRAY_TAB === $tab && $page_id > 0 ) {
			$this->render_pages_tab( $view_args, $tooltips );
			return;
		}
		$view = self::XRAY_TAB === $tab ? self::XRAY_TAB : 'insights';
		?>
		<div class="wrap opti-behavior-dashboard-page ob-smart-insights-center-page">
			<section
				class="ob-smart-insights ob-smart-insights-center"
				data-ob-smart-insights-context="center"
				data-ob-si-view-current="<?php echo esc_attr( $view ); ?>"
				data-period="<?php echo esc_attr( $period ); ?>"
				data-start-date="<?php echo esc_attr( $start_date ); ?>"
				data-end-date="<?php echo esc_attr( $end_date ); ?>"
				data-deep-open-insight-id="<?php echo esc_attr( $insight_id ); ?>"
				data-exclude-spam="<?php echo esc_attr( $exclude_spam ? '1' : '0' ); ?>"
			>
				<div class="dashboard-header ob-smart-insights-dashboard-header">
					<div class="ob-smart-insights-header-main">
						<div class="dashboard-title-section">
							<?php Opti_Behavior_Admin_Brand::render_mark(); ?>
							<div class="dashboard-title-text">
								<?php Opti_Behavior_Admin_Brand::render_name(); ?>
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
							<?php
							if ( class_exists( 'Opti_Behavior_Reports_Screen' ) ) {
								Opti_Behavior_Reports_Screen::render_header_link( 'insights', $period, $start_date, $end_date, $exclude_spam );
							}
							?>
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
					<?php
					// OPT-2179 page order: How it works → Weekly CRO Summary → tab bar.
					if ( class_exists( 'Opti_Behavior_Smart_Insights_Sensors' ) ) {
						Opti_Behavior_Smart_Insights_Sensors::render(
							array(
								'period'       => $period,
								'start_date'   => $start_date,
								'end_date'     => $end_date,
								'exclude_spam' => $exclude_spam,
							)
						);
					}
					?>
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
						<?php // Design OPT-2209: summary (left) and "Pages losing the most visitors" (right) in one card. ?>
						<div class="ob-si-weekly-split">
						<div class="widget-content">
							<?php if ( $has_pro ) : ?>
								<?php // OPT-1168: a grey block the size of the summary while it loads; the sentence stays for screen readers. ?>
								<div class="ob-smart-insights-loading has-skeleton" role="status">
									<span class="ob-smart-insights-loading-text ob-smart-insights-summary-loading"><?php esc_html_e( 'Loading weekly CRO summary…', 'opti-behavior' ); ?></span>
									<span class="ob-si-skel" aria-hidden="true"><i></i></span>
								</div>
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
						<?php
						// OPT-2180 "Pages losing the most visitors": Free prints the 3 pages
						// of the locked Page X-Ray preview now; Pro fills it from the summary
						// AJAX answer (`top_pages_html`).
						$top_pages_html = '';
						if ( ! $has_pro && class_exists( 'Opti_Behavior_Smart_Insights_Page_Preview' ) ) {
							try {
								$preview        = Opti_Behavior_Smart_Insights_Page_Preview::get_preview( $view_args );
								$top_pages_html = self::render_top_pages(
									isset( $preview['pages'] ) ? $preview['pages'] : array(),
									array(
										'from'         => isset( $preview['range']['from'] ) ? $preview['range']['from'] : '',
										'to'           => isset( $preview['range']['to'] ) ? $preview['range']['to'] : '',
										'period'       => $period,
										'exclude_spam' => $exclude_spam,
										'locked'       => true,
									)
								);
							} catch ( Throwable $e ) {
								$top_pages_html = '';
							}
						}
						?>
						<div class="ob-si-top-pages" data-ob-si-top-pages<?php echo '' === $top_pages_html ? ' hidden' : ''; ?>><?php echo $top_pages_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_top_pages(). ?></div>
						</div>
						<?php // One foot for the card: the scripts move the scope note and the "Based on…" line here. ?>
						<div class="ob-si-weekly-foot" data-ob-si-weekly-foot></div>
					</div>

					<?php
					// The "?" of the insight cards sits in the Insights tab itself (OPT-2182):
					// the tab names the list, no second title under the bar.
					$tabs_help = '';
					if ( ! empty( $tooltips['insight_cards'] ) && function_exists( 'opti_behavior_tooltip' ) ) {
						$tabs_help = opti_behavior_tooltip( $tooltips['insight_cards']['title'], $tooltips['insight_cards']['content'], $tooltips['insight_cards']['simple'], '', array( 'position' => 'bottom' ) );
					}
					self::render_tabs( $view, array( 'exclude_spam' => $exclude_spam ? '1' : '0' ) + $view_args, $tabs_help );
					?>
					<div class="ob-si-tabpanel" id="ob-si-tabpanel" role="tabpanel" aria-labelledby="<?php echo esc_attr( 'ob-si-tab-' . $view ); ?>">
					<div class="ob-si-view" data-ob-si-view="insights"<?php echo 'insights' === $view ? '' : ' hidden'; ?>>
					<?php
					try {
						/**
						 * Print a block under the Insights tab bar (Opti-Behavior Pro:
						 * open problems it found before its licence ended, OPT-371).
						 *
						 * @param array $view_args `period`, `start_date`, `end_date`, `exclude_spam`.
						 * @param bool  $has_pro   Viewer has Pro Smart Insights access.
						 */
						do_action( 'opti_behavior_smart_insights_after_tabs', $view_args, $has_pro );
					} catch ( Throwable $e ) {
						// A faulty extension never costs the Insights view.
						unset( $e );
					}
					?>

					<div class="ob-smart-insights-alert" role="status" hidden></div>
					<div class="ob-smart-insights-list" aria-live="polite">
						<div class="ob-smart-insights-loading has-skeleton" role="status">
							<span class="ob-smart-insights-spinner" aria-hidden="true"></span>
							<span class="ob-smart-insights-loading-text"><?php esc_html_e( 'Loading Smart Insights...', 'opti-behavior' ); ?></span>
							<span class="ob-si-skel" aria-hidden="true"><i></i><i></i><i></i></span>
						</div>
					</div>
					</div>
					<?php // Page X-Ray view: printed when opened by its address, else fetched by smart-insights.js at the first tab click. ?>
					<div class="ob-si-view" data-ob-si-view="<?php echo esc_attr( self::XRAY_TAB ); ?>" data-ob-si-loaded="<?php echo self::XRAY_TAB === $view ? '1' : '0'; ?>"<?php echo self::XRAY_TAB === $view ? '' : ' hidden'; ?>>
						<?php if ( self::XRAY_TAB === $view ) : ?>
							<?php $this->render_xray_body( $view_args, 0 ); ?>
						<?php else : ?>
							<div class="ob-smart-insights-loading has-skeleton" role="status">
								<span class="ob-smart-insights-spinner" aria-hidden="true"></span>
								<span class="ob-smart-insights-loading-text"><?php esc_html_e( 'Loading Page X-Ray…', 'opti-behavior' ); ?></span>
								<span class="ob-si-skel" aria-hidden="true"><i></i><i></i><i></i></span>
							</div>
						<?php endif; ?>
					</div>
					</div>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * "Pages losing the most visitors" block under the Weekly CRO Summary
	 * (OPT-2180). One markup for both editions: Free prints the pages of the
	 * locked Page X-Ray preview (visits + first problem), Pro the pages of its
	 * brief (visits lost, capped at the page visits). Every page opens Page
	 * X-Ray on that page (locked view with the page chosen in Free).
	 *
	 * @param array $pages Rows: { page_id, title, path, sessions|visits, lost?, capped?, problems?, money?, currency?, problem|top_title }.
	 * @param array $args  `from`, `to` (Y-m-d), `period`, `exclude_spam`, `locked` (Free rows).
	 * @return string HTML (escaped); '' without a page (OPT-2503: the summary takes the full width).
	 */
	public static function render_top_pages( $pages, $args = array() ) {
		$args    = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'from'         => '',
				'to'           => '',
				'period'       => '',
				'exclude_spam' => true,
				'locked'       => false,
			)
		);
		$pages   = array_slice( array_values( array_filter( (array) $pages, 'is_array' ) ), 0, 3 );
		if ( empty( $pages ) ) {
			// OPT-2503: no page = no right column (an eyebrow alone read as an empty block).
			return '';
		}
		$periods = array( 'last7days', 'last14days', 'last30days', 'last90days' );
		$keep    = array( 'exclude_spam' => $args['exclude_spam'] ? '1' : '0' );
		if ( in_array( $args['period'], $periods, true ) ) {
			$keep['period'] = $args['period'];
		}
		ob_start();
		?>
		<section class="ob-si-top-pages__box" aria-labelledby="ob-si-top-pages-title">
			<h2 id="ob-si-top-pages-title" class="ob-si-top-pages__title"><i data-lucide="trending-down" class="ob-si-top-pages__icon" aria-hidden="true"></i><?php esc_html_e( 'Pages losing the most visitors', 'opti-behavior' ); ?><?php echo self::section_help( 'top_pages' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by opti_behavior_tooltip(). ?></h2>
			<?php if ( ! empty( $pages ) ) : ?>
				<ol class="ob-si-top-pages__list">
					<?php foreach ( $pages as $page ) : ?>
						<?php
						$page_id = isset( $page['page_id'] ) ? absint( $page['page_id'] ) : 0;
						$url     = add_query_arg( $keep, self::xray_url( $page_id ) );
						$title   = isset( $page['title'] ) ? (string) $page['title'] : '';
						if ( '' !== $title && class_exists( 'Opti_Behavior_Smart_Insights_Recommendations' ) && method_exists( 'Opti_Behavior_Smart_Insights_Recommendations', 'strip_site_suffix' ) ) {
							$title = Opti_Behavior_Smart_Insights_Recommendations::strip_site_suffix( $title );
						}
						$title = '' !== $title ? $title : ( isset( $page['path'] ) ? (string) $page['path'] : '' );
						$problem = isset( $page['problem'] ) ? (string) $page['problem'] : ( isset( $page['top_title'] ) ? (string) $page['top_title'] : '' );
						if ( $args['locked'] ) {
							$visits = isset( $page['sessions'] ) ? (int) $page['sessions'] : 0;
							/* translators: %s: number of visits. */
							$figure = sprintf( _n( '%s visit', '%s visits', $visits, 'opti-behavior' ), number_format_i18n( $visits ) );
						} elseif ( ! empty( $page['capped'] ) ) {
							// OB-07: at least the visits of the biggest problem; one visit with 3 problems counts once.
							/* translators: 1: visits with a problem (at least), 2: visits of the page. */
							$figure = sprintf( __( 'at least %1$s visits with a problem, of %2$s', 'opti-behavior' ), number_format_i18n( (int) $page['lost'] ), number_format_i18n( (int) $page['visits'] ) );
						} else {
							$count = isset( $page['problems'] ) ? (int) $page['problems'] : 0;
							/* translators: 1: number of problems, 2: visits affected. */
							$figure = sprintf( _n( '%1$s problem · %2$s visits affected', '%1$s problems · %2$s visits affected', $count, 'opti-behavior' ), number_format_i18n( $count ), number_format_i18n( isset( $page['lost'] ) ? (int) $page['lost'] : 0 ) );
						}
						$money = '';
						if ( ! $args['locked'] && isset( $page['money'] ) && is_numeric( $page['money'] ) && (float) $page['money'] > 0 ) {
							$amount = trim( number_format_i18n( (float) $page['money'], 0 ) . ' ' . ( isset( $page['currency'] ) ? sanitize_text_field( (string) $page['currency'] ) : '' ) );
							/* translators: %s: amount with its currency. */
							$money = sprintf( __( '%s in lost sales', 'opti-behavior' ), $amount );
						}
						?>
						<li class="ob-si-top-pages__row">
							<a class="ob-si-top-pages__page" href="<?php echo esc_url( $url ); ?>">
								<span class="ob-si-top-pages__name"><?php echo esc_html( $title ); ?></span>
								<?php if ( $args['locked'] ) : ?>
									<span class="opti-menu-pro-badge"><?php echo esc_html_x( 'PRO', 'badge', 'opti-behavior' ); ?></span>
								<?php endif; ?>
							</a>
							<span class="ob-si-top-pages__path"><?php echo esc_html( isset( $page['path'] ) ? (string) $page['path'] : '' ); ?></span>
							<span class="ob-si-top-pages__figure"><?php echo esc_html( $figure ); ?></span>
							<?php if ( '' !== $money ) : ?>
								<span class="ob-si-top-pages__money"><?php echo esc_html( $money ); ?></span>
							<?php endif; ?>
							<?php if ( ! $args['locked'] && isset( $page['goal_line'] ) && is_string( $page['goal_line'] ) && '' !== $page['goal_line'] ) : // OB-06: goal used by Page X-Ray + its state, sent translated by Pro. ?>
								<span class="ob-si-top-pages__goal"><?php echo esc_html( $page['goal_line'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $problem ) : ?>
								<span class="ob-si-top-pages__problem"><span class="ob-si-top-pages__problem-label"><?php esc_html_e( 'Problem #1', 'opti-behavior' ); ?></span> <?php echo esc_html( $problem ); ?></span>
							<?php elseif ( $args['locked'] ) : ?>
								<span class="ob-si-top-pages__problem is-none"><?php esc_html_e( 'No problem found on the data available in Free', 'opti-behavior' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<?php if ( count( $pages ) < 3 ) : ?>
				<p class="ob-si-top-pages__more"><?php esc_html_e( 'Not enough visits yet on other pages', 'opti-behavior' ); ?></p>
			<?php endif; ?>
			<?php
			$from = strtotime( (string) $args['from'] );
			$to   = strtotime( (string) $args['to'] );
			if ( $from && $to ) :
				$span = date_i18n( get_option( 'date_format' ), $from ) . ' – ' . date_i18n( get_option( 'date_format' ), $to );
				/* translators: %s: period, e.g. "1 October 2026 – 30 October 2026". */
				$foot = $args['exclude_spam'] ? sprintf( __( 'Based on %s, spam excluded', 'opti-behavior' ), $span ) : sprintf( __( 'Based on %s', 'opti-behavior' ), $span );
				?>
				<p class="ob-si-top-pages__foot"><?php echo esc_html( $foot ); ?></p>
			<?php endif; ?>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Admin URL of the Page X-Ray tab / of one page's dossier. Free owns the
	 * URL so its entry points (heatmap list "Dossier") never call Pro.
	 *
	 * @param int $page_id Optional page id.
	 * @return string
	 */
	public static function xray_url( $page_id = 0 ) {
		$args = array(
			'page' => 'opti-behavior-smart-insights',
			'tab'  => self::XRAY_TAB,
		);
		if ( $page_id > 0 ) {
			$args['page_id'] = absint( $page_id );
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Help mark of a section, from the section tooltips.
	 *
	 * @param string $key Key of opti_behavior_get_smart_insights_section_tooltips().
	 * @return string HTML, escaped by opti_behavior_tooltip().
	 */
	private static function section_help( $key ) {
		if ( ! function_exists( 'opti_behavior_get_smart_insights_section_tooltips' ) || ! function_exists( 'opti_behavior_tooltip' ) ) {
			return '';
		}
		$all = opti_behavior_get_smart_insights_section_tooltips();
		if ( empty( $all[ $key ] ) ) {
			return '';
		}
		return opti_behavior_tooltip( $all[ $key ]['title'], $all[ $key ]['content'], isset( $all[ $key ]['simple'] ) ? $all[ $key ]['simple'] : '' );
	}

	/**
	 * Help mark of a tab of the bar.
	 *
	 * @param string $id Tab id.
	 * @return string HTML, escaped by opti_behavior_tooltip().
	 */
	private static function tab_help( $id ) {
		if ( self::XRAY_TAB === $id ) {
			return self::section_help( 'page_xray_tab' );
		}
		$all = function_exists( 'opti_behavior_get_smart_insights_tooltips' ) && function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_get_smart_insights_tooltips() : array();
		if ( empty( $all['insight_cards'] ) ) {
			return '';
		}
		return opti_behavior_tooltip( $all['insight_cards']['title'], $all['insight_cards']['content'], $all['insight_cards']['simple'] );
	}

	/**
	 * Print the tab bar of the Smart Insights screen. The Page X-Ray tab is a
	 * Pro feature: without it, the tab carries a lock and a PRO badge and
	 * opens the locked view.
	 *
	 * @param string $current insights|pages.
	 * @param array  $keep    Query args to carry over (period, dates, spam).
	 * @param string $help    Optional tooltip HTML (escaped) shown inside the Insights tab.
	 * @return void
	 */
	public static function render_tabs( $current, $keep = array(), $help = '' ) {
		$base = admin_url( 'admin.php?page=opti-behavior-smart-insights' );
		$keep = array_filter(
			array_map( 'strval', array_intersect_key( (array) $keep, array_flip( array( 'period', 'start_date', 'end_date', 'exclude_spam' ) ) ) ),
			'strlen'
		);
		$open = opti_behavior_page_xray_available();
		$tabs = array(
			'insights'     => array( __( 'Behavior opportunities', 'opti-behavior' ), add_query_arg( $keep, $base ) ),
			self::XRAY_TAB => array( __( 'Page X-Ray', 'opti-behavior' ), add_query_arg( $keep + array( 'tab' => self::XRAY_TAB ), $base ) ),
		);
		?>
		<nav class="ob-si-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Smart Insights views', 'opti-behavior' ); ?>">
			<?php foreach ( $tabs as $id => $tab ) : ?>
				<?php
				// Tab pattern (OPT-2280): each tab is a link to its view; only the
				// current one is in the Tab order, arrows move between them
				// (smart-insights.js), the view under the bar is the tabpanel.
				$selected = $id === $current;
				$classes  = 'ob-si-tabs__tab' . ( $selected ? ' is-current' : '' );
				// The Page X-Ray tab always pulses while another view is open.
				if ( self::XRAY_TAB === $id && $id !== $current ) {
					$classes .= ' is-new';
				}
				if ( self::XRAY_TAB === $id && ! $open ) {
					$classes .= ' is-locked';
				}
				// The tooltip is a sibling of the link (never inside it), drawn inside the pill.
				$tab_help = 'insights' === $id ? $help : '';
				if ( '' === $tab_help ) {
					$tab_help = self::tab_help( $id );
				}
				$with_help = '' !== $tab_help;
				if ( $with_help ) {
					$classes .= ' has-help';
					echo '<span class="ob-si-tabs__with-help">';
				}
				?>
				<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $tab[1] ); ?>" id="<?php echo esc_attr( 'ob-si-tab-' . $id ); ?>" role="tab" aria-selected="<?php echo $selected ? 'true' : 'false'; ?>" tabindex="<?php echo $selected ? '0' : '-1'; ?>"<?php echo $selected ? ' aria-controls="ob-si-tabpanel" aria-current="page"' : ''; ?>>
					<?php if ( self::XRAY_TAB === $id && ! $open ) : ?>
						<i data-lucide="lock" class="ob-si-tabs__lock" aria-hidden="true"></i>
					<?php endif; ?>
					<?php echo esc_html( $tab[0] ); ?>
					<?php if ( self::XRAY_TAB === $id && ! $open ) : ?>
						<span class="opti-menu-pro-badge" aria-label="<?php esc_attr_e( 'Pro only', 'opti-behavior' ); ?>">PRO</span>
					<?php endif; ?>
				</a>
				<?php if ( $with_help ) : ?>
					<?php echo $tab_help; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by opti_behavior_tooltip(). ?>
					</span>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Locked view of the Page X-Ray tab: what the tab does, a real preview
	 * (score + first problem of the three most visited pages, from the data
	 * Free collects: `Opti_Behavior_Smart_Insights_Page_Preview`, cached) or,
	 * while no page has enough visits, an example report (sample data,
	 * labelled as such), and the one action that opens the tab (get Pro,
	 * activate the licence, or update Pro). No Pro call.
	 *
	 * @param array  $args         `period`, `exclude_spam`.
	 * @param string $period_label Label of the period.
	 * @param int    $page_id      Page opened from a link (OPT-2180): its card is marked chosen.
	 * @return void
	 */
	private function render_xray_locked( $args = array(), $period_label = '', $page_id = 0 ) {
		$preview = array();
		if ( class_exists( 'Opti_Behavior_Smart_Insights_Page_Preview' ) ) {
			try {
				$data    = Opti_Behavior_Smart_Insights_Page_Preview::get_preview( $args );
				$preview = isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : array();
			} catch ( Throwable $e ) {
				$preview = array();
			}
		}
		$chosen = null;
		foreach ( $preview as $card ) {
			if ( $page_id > 0 && isset( $card['page_id'] ) && (int) $card['page_id'] === (int) $page_id ) {
				$chosen = $card;
				break;
			}
		}
		$pro_active = function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();
		if ( ! $pro_active ) {
			$text   = __( 'Page X-Ray is part of Opti-Behavior Pro.', 'opti-behavior' );
			$button = __( 'Get Opti-Behavior Pro', 'opti-behavior' );
			$url    = $this->get_pro_trial_url( 'page-xray' );
			$target = true;
		} elseif ( ! $this->viewer_has_pro_access() ) {
			$text   = __( 'Activate your Opti-Behavior Pro licence to open Page X-Ray.', 'opti-behavior' );
			$button = __( 'Open the licence settings', 'opti-behavior' );
			$url    = admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=license' );
			$target = false;
		} else {
			$text   = __( 'Update Opti-Behavior Pro to open Page X-Ray.', 'opti-behavior' );
			$button = __( 'Go to Plugins', 'opti-behavior' );
			$url    = admin_url( 'plugins.php' );
			$target = false;
		}
		$features = array(
			'gauge'               => __( 'A health score for every page, built from every module', 'opti-behavior' ),
			'target'              => __( 'What the page is for, and whether visitors reach its main button', 'opti-behavior' ),
			'mouse-pointer-click' => __( 'Where visitors look, click and leave, band by band', 'opti-behavior' ),
			'clipboard-check'     => __( 'A page audit: clarity, trust, content, sharing', 'opti-behavior' ),
			'history'             => __( 'What changed in WordPress before a problem started', 'opti-behavior' ),
			'list-checks'         => __( 'An action plan: what to fix first and what to test', 'opti-behavior' ),
		);
		// Example report: fixed sample figures, the same blocks as a real dossier.
		$score    = 58;
		$ring     = 2 * M_PI * 34;
		$problems = array(
			array(
				'level' => 'critical',
				'label' => __( 'Critical', 'opti-behavior' ),
				/* translators: %s: button label, e.g. "Buy now". */
				'title' => sprintf( __( 'Script error on the “%s” button', 'opti-behavior' ), __( 'Buy now', 'opti-behavior' ) ),
				'meta'  => __( 'Started the day after a plugin update', 'opti-behavior' ),
			),
			array(
				'level' => 'high',
				'label' => __( 'High', 'opti-behavior' ),
				/* translators: %d: share of the visitors, without the % sign. */
				'title' => sprintf( __( 'Only %d%% of visitors reach the main button', 'opti-behavior' ), 25 ),
				/* translators: %s: number of visits. */
				'meta'  => sprintf( __( 'Touches %s visits', 'opti-behavior' ), number_format_i18n( 930 ) ),
			),
			array(
				'level' => 'medium',
				'label' => __( 'Medium', 'opti-behavior' ),
				/* translators: %d: share of the visitors, without the % sign. */
				'title' => sprintf( __( '%d%% of visitors leave before a tenth of the page', 'opti-behavior' ), 75 ),
				/* translators: %s: number of visits. */
				'meta'  => sprintf( __( 'Touches %s visits', 'opti-behavior' ), number_format_i18n( 740 ) ),
			),
		);
		$bands    = array( 28, 6, 4, 3, 16, 18, 14, 7, 3, 1 );
		$plan     = array(
			array( __( 'Fix the script error', 'opti-behavior' ), __( 'Fix', 'opti-behavior' ) ),
			array( __( 'Move the main button into the first screen', 'opti-behavior' ), __( 'Quick win', 'opti-behavior' ) ),
			array( __( 'Test a shorter headline', 'opti-behavior' ), __( 'A/B test', 'opti-behavior' ) ),
		);
		?>
		<section class="ob-si-xray-locked" aria-labelledby="ob-si-xray-locked-title">
			<div class="ob-si-xray-locked__pitch">
				<p class="ob-si-xray-locked__eyebrow"><i data-lucide="lock" aria-hidden="true"></i> <?php esc_html_e( 'Opti-Behavior Pro', 'opti-behavior' ); ?></p>
				<h2 id="ob-si-xray-locked-title" class="ob-si-xray-locked__title"><?php esc_html_e( 'Page X-Ray', 'opti-behavior' ); ?> <span class="opti-menu-pro-badge"><?php echo esc_html_x( 'PRO', 'badge', 'opti-behavior' ); ?></span></h2>
				<p class="ob-si-xray-locked__lead"><?php esc_html_e( 'One report per page: what is wrong, how many visits it costs, and what to fix first.', 'opti-behavior' ); ?></p>
				<?php if ( null !== $chosen ) : ?>
					<?php /* translators: %s: page title. */ ?>
					<p class="ob-si-xray-locked__chosen" data-ob-xray-chosen="<?php echo (int) $chosen['page_id']; ?>"><i data-lucide="file-search" aria-hidden="true"></i> <span><?php echo esc_html( sprintf( __( 'Chosen page: %s', 'opti-behavior' ), $chosen['title'] ) ); ?></span> <span class="opti-menu-pro-badge"><?php echo esc_html_x( 'PRO', 'badge', 'opti-behavior' ); ?></span></p>
				<?php endif; ?>
				<ul class="ob-si-xray-locked__features">
					<?php foreach ( $features as $icon => $feature ) : ?>
						<li><span class="ob-si-xray-locked__feature-icon" aria-hidden="true"><i data-lucide="<?php echo esc_attr( $icon ); ?>"></i></span> <span><?php echo esc_html( $feature ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<div class="ob-si-xray-locked__action">
					<p class="ob-si-xray-locked__text"><?php echo esc_html( $text ); ?></p>
					<a class="button button-primary ob-si-xray-locked__cta" href="<?php echo esc_url( $url ); ?>"<?php echo $target ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
						<?php echo esc_html( $button ); ?>
						<?php if ( $target ) : ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'opti-behavior' ); ?></span>
						<?php endif; ?>
					</a>
					<?php if ( ! $pro_active ) : ?>
						<p class="ob-si-xray-locked__note"><?php esc_html_e( 'Try Pro free for 6 months', 'opti-behavior' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $preview ) ) : ?>
			<figure class="ob-si-xray-demo ob-si-xray-preview" aria-labelledby="ob-si-xray-preview-caption">
				<?php /* translators: %s: period, e.g. "Last 30 Days". */ ?>
				<figcaption id="ob-si-xray-preview-caption" class="ob-si-xray-demo__caption"><i data-lucide="eye" aria-hidden="true"></i> <?php echo esc_html( sprintf( __( 'Your most visited pages · %s', 'opti-behavior' ), $period_label ) ); ?></figcaption>
				<ul class="ob-si-xray-preview__list">
					<?php foreach ( $preview as $card ) : ?>
						<?php
						$card_score  = max( 0, min( 100, (int) $card['score'] ) );
						$card_health = in_array( $card['health'], array( 'good', 'watch', 'fix' ), true ) ? $card['health'] : 'watch';
						$card_label  = Opti_Behavior_Smart_Insights_Page_Preview::health_label( $card_health );
						if ( ! empty( $card['provisional'] ) && class_exists( 'Opti_Behavior_Smart_Insights_Scorer' ) ) {
							$card_visits = Opti_Behavior_Smart_Insights_Scorer::provisional_text( (int) $card['sessions'] );
						} else {
							/* translators: %s: number of visits. */
							$card_visits = sprintf( __( '%s visits', 'opti-behavior' ), number_format_i18n( (int) $card['sessions'] ) );
						}
						?>
						<?php $is_chosen = null !== $chosen && (int) $card['page_id'] === (int) $chosen['page_id']; ?>
						<li class="ob-si-xray-demo__card ob-si-xray-preview__card is-<?php echo esc_attr( $card_health ); ?><?php echo $is_chosen ? ' is-chosen' : ''; ?>"<?php echo $is_chosen ? ' aria-current="true"' : ''; ?>>
							<div class="ob-si-xray-demo__head">
								<div class="ob-si-xray-preview__id">
									<p class="ob-si-xray-demo__page"><?php echo esc_html( $card['title'] ); ?></p>
									<p class="ob-si-xray-demo__meta"><span class="ob-si-xray-preview__path"><?php echo esc_html( $card['path'] ); ?></span> · <?php echo esc_html( $card_visits ); ?></p>
								</div>
								<?php /* translators: 1: health score out of 100, 2: health label, e.g. "Watch". */ ?>
								<div class="ob-si-xray-demo__ring" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Health score: %1$d out of 100, %2$s', 'opti-behavior' ), $card_score, $card_label ) ); ?>">
									<svg viewBox="0 0 80 80" aria-hidden="true" focusable="false">
										<circle cx="40" cy="40" r="34" class="ob-si-xray-demo__ring-track"></circle>
										<circle cx="40" cy="40" r="34" class="ob-si-xray-demo__ring-value" stroke-dasharray="<?php echo esc_attr( round( $ring * $card_score / 100, 1 ) . ' ' . round( $ring, 1 ) ); ?>"></circle>
									</svg>
									<span class="ob-si-xray-demo__ring-text" aria-hidden="true"><strong><?php echo (int) $card_score; ?></strong><small><?php echo esc_html( $card_label ); ?></small></span>
								</div>
							</div>
							<?php if ( '' !== (string) $card['top_title'] ) : ?>
								<p class="ob-si-xray-preview__problem"><span class="ob-si-xray-preview__problem-label"><?php esc_html_e( 'Problem #1', 'opti-behavior' ); ?></span> <?php echo esc_html( $card['top_title'] ); ?></p>
								<p class="ob-si-xray-preview__lock"><i data-lucide="lock" aria-hidden="true"></i> <span><?php esc_html_e( 'Why it happens, what to do, the recordings that show it, and the other problems:', 'opti-behavior' ); ?></span> <span class="opti-menu-pro-badge"><?php echo esc_html_x( 'PRO', 'badge', 'opti-behavior' ); ?></span></p>
							<?php else : ?>
								<p class="ob-si-xray-preview__problem is-none"><?php esc_html_e( 'No problem found on the data available in Free', 'opti-behavior' ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="ob-si-xray-preview__note"><?php esc_html_e( 'Preview based on the data the free plugin collects. Pro also checks errors, speed, forms and the page content, so its score can be lower.', 'opti-behavior' ); ?></p>
			</figure>
			<?php else : ?>
			<figure class="ob-si-xray-demo" aria-labelledby="ob-si-xray-demo-caption">
				<figcaption id="ob-si-xray-demo-caption" class="ob-si-xray-demo__caption"><i data-lucide="eye" aria-hidden="true"></i> <?php esc_html_e( 'Example report (sample data)', 'opti-behavior' ); ?></figcaption>
				<div class="ob-si-xray-demo__card">
					<div class="ob-si-xray-demo__head">
						<div>
							<p class="ob-si-xray-demo__page"><?php esc_html_e( 'Pricing', 'opti-behavior' ); ?> <span class="ob-si-xray-demo__chip"><?php esc_html_e( 'Sales page', 'opti-behavior' ); ?></span></p>
							<?php /* translators: %s: number of visits. */ ?>
							<p class="ob-si-xray-demo__meta"><?php echo esc_html( sprintf( __( '%s visits · last 30 days', 'opti-behavior' ), number_format_i18n( 1240 ) ) ); ?></p>
						</div>
						<?php /* translators: %d: health score out of 100. */ ?>
						<div class="ob-si-xray-demo__ring" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Health score: %d out of 100, needs work', 'opti-behavior' ), $score ) ); ?>">
							<svg viewBox="0 0 80 80" aria-hidden="true" focusable="false">
								<circle cx="40" cy="40" r="34" class="ob-si-xray-demo__ring-track"></circle>
								<circle cx="40" cy="40" r="34" class="ob-si-xray-demo__ring-value" stroke-dasharray="<?php echo esc_attr( round( $ring * $score / 100, 1 ) . ' ' . round( $ring, 1 ) ); ?>"></circle>
							</svg>
							<span class="ob-si-xray-demo__ring-text" aria-hidden="true"><strong><?php echo (int) $score; ?></strong><small><?php esc_html_e( 'Needs work', 'opti-behavior' ); ?></small></span>
						</div>
					</div>

					<h3 class="ob-si-xray-demo__title"><?php esc_html_e( 'Top problems', 'opti-behavior' ); ?></h3>
					<ol class="ob-si-xray-demo__problems">
						<?php foreach ( $problems as $problem ) : ?>
							<li class="is-<?php echo esc_attr( $problem['level'] ); ?>">
								<span class="ob-si-xray-demo__level"><?php echo esc_html( $problem['label'] ); ?></span>
								<span class="ob-si-xray-demo__problem"><?php echo esc_html( $problem['title'] ); ?><small><?php echo esc_html( $problem['meta'] ); ?></small></span>
							</li>
						<?php endforeach; ?>
					</ol>

					<h3 class="ob-si-xray-demo__title"><?php esc_html_e( 'Where visitors spend their time', 'opti-behavior' ); ?></h3>
					<div class="ob-si-xray-demo__bands" aria-hidden="true">
						<?php foreach ( $bands as $band ) : ?>
							<span style="height:<?php echo (int) max( 4, $band * 3 ); ?>px"></span>
						<?php endforeach; ?>
					</div>
					<p class="ob-si-xray-demo__axis" aria-hidden="true"><span><?php esc_html_e( 'Top of the page', 'opti-behavior' ); ?></span><span><?php esc_html_e( 'Bottom', 'opti-behavior' ); ?></span></p>
					<?php /* translators: %d: share of the viewing time, without the % sign. */ ?>
					<p class="ob-si-xray-demo__bands-note"><?php echo esc_html( sprintf( __( '%d%% of the viewing time is spent on the first screen.', 'opti-behavior' ), 28 ) ); ?></p>

					<h3 class="ob-si-xray-demo__title"><?php esc_html_e( 'Action plan', 'opti-behavior' ); ?></h3>
					<ol class="ob-si-xray-demo__plan">
						<?php foreach ( $plan as $step ) : ?>
							<li><span><?php echo esc_html( $step[0] ); ?></span> <span class="ob-si-xray-demo__tag"><?php echo esc_html( $step[1] ); ?></span></li>
						<?php endforeach; ?>
					</ol>
				</div>
				<?php if ( class_exists( 'Opti_Behavior_Smart_Insights_Page_Preview' ) ) : ?>
					<?php /* translators: %d: number of visits. */ ?>
					<p class="ob-si-xray-preview__note"><?php echo esc_html( sprintf( __( 'Your own pages appear here once a page has %d visits.', 'opti-behavior' ), Opti_Behavior_Smart_Insights_Page_Preview::MIN_VISITS ) ); ?></p>
				<?php endif; ?>
			</figure>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render the Page X-Ray tab: the Pro page list, or one page's dossier when
	 * `page_id` is set, or the locked view.
	 *
	 * Free keeps the header, the period form and the tab bar; Pro prints the
	 * body through `opti_behavior_smart_insights_render_page_xray`. The Smart
	 * Insights list script stays idle here because no
	 * `[data-ob-smart-insights-context]` section is printed.
	 *
	 * @param array $view_args `period`, `start_date`, `end_date`, `exclude_spam`.
	 * @param array $tooltips  Smart Insights tooltips.
	 * @return void
	 */
	private function render_pages_tab( $view_args, $tooltips ) {
		$page_id = isset( $_GET['page_id'] ) ? absint( wp_unslash( $_GET['page_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link state.
		$periods = self::xray_periods();
		$period  = isset( $periods[ $view_args['period'] ] ) ? $view_args['period'] : 'last30days';
		$open    = opti_behavior_page_xray_available();
		?>
		<div class="wrap opti-behavior-dashboard-page ob-smart-insights-center-page ob-si-xray-page">
			<div class="dashboard-header ob-smart-insights-dashboard-header">
				<div class="ob-smart-insights-header-main">
					<div class="dashboard-title-section">
						<?php Opti_Behavior_Admin_Brand::render_mark(); ?>
						<div class="dashboard-title-text">
							<?php Opti_Behavior_Admin_Brand::render_name(); ?>
							<h1 class="dashboard-title">
								<?php esc_html_e( 'Smart Insights', 'opti-behavior' ); ?>
								<?php if ( ! empty( $tooltips['center_heading'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
									<?php opti_behavior_tooltip_e( $tooltips['center_heading']['title'], $tooltips['center_heading']['content'], $tooltips['center_heading']['simple'], '', array( 'position' => 'right' ) ); ?>
								<?php endif; ?>
							</h1>
							<p class="dashboard-subtitle"><?php esc_html_e( 'One dossier per page: what is wrong, how many visits it costs, and the one button that opens the right module.', 'opti-behavior' ); ?></p>
						</div>
					</div>
					<?php if ( $open ) : ?>
					<form class="dashboard-controls ob-si-xray__controls" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="opti-behavior-smart-insights">
						<input type="hidden" name="tab" value="<?php echo esc_attr( self::XRAY_TAB ); ?>">
						<?php if ( $page_id > 0 ) : ?>
							<input type="hidden" name="page_id" value="<?php echo (int) $page_id; ?>">
						<?php endif; ?>
						<input type="hidden" name="exclude_spam" value="<?php echo $view_args['exclude_spam'] ? '1' : '0'; ?>">
						<label class="screen-reader-text" for="ob-si-xray-period"><?php esc_html_e( 'Period', 'opti-behavior' ); ?></label>
						<select id="ob-si-xray-period" name="period">
							<?php foreach ( $periods as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $period, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Apply', 'opti-behavior' ); ?></button>
					</form>
					<?php endif; ?>
				</div>
			</div>

			<div class="dashboard-content ob-smart-insights-center-content">
				<?php
				// "How it works" strip above the tab bar on the page list too (not
				// inside a dossier), same component, same fold state as the
				// Insights view (OPT-2179 page order).
				if ( 0 === $page_id && class_exists( 'Opti_Behavior_Smart_Insights_Sensors' ) ) {
					Opti_Behavior_Smart_Insights_Sensors::render(
						array(
							'period'       => $period,
							'exclude_spam' => ! empty( $view_args['exclude_spam'] ),
						)
					);
				}
				self::render_tabs( self::XRAY_TAB, array( 'period' => $period, 'exclude_spam' => $view_args['exclude_spam'] ? '1' : '0' ) );
				echo '<div class="ob-si-tabpanel" id="ob-si-tabpanel" role="tabpanel" aria-labelledby="' . esc_attr( 'ob-si-tab-' . self::XRAY_TAB ) . '">';
				$this->render_xray_body( $view_args, $page_id );
				echo '</div>';
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Periods the Page X-Ray tab knows (any other falls back to 30 days).
	 *
	 * @return array key => label.
	 */
	private static function xray_periods() {
		return array(
			'last7days'  => __( 'Last 7 Days', 'opti-behavior' ),
			'last14days' => __( 'Last 14 Days', 'opti-behavior' ),
			'last30days' => __( 'Last 30 Days', 'opti-behavior' ),
			'last90days' => __( 'Last 3 Months', 'opti-behavior' ),
		);
	}

	/**
	 * Body of the Page X-Ray tab: the Pro list / dossier, or the locked view.
	 * Printed inside the tabpanel of the dossier screen and in the Page X-Ray
	 * view of the shared Smart Insights screen (OPT-2283).
	 *
	 * @param array $view_args `period`, `exclude_spam`.
	 * @param int   $page_id   Dossier to open, 0 = page list.
	 * @return void
	 */
	private function render_xray_body( $view_args, $page_id ) {
		$periods = self::xray_periods();
		$period  = isset( $periods[ $view_args['period'] ] ) ? $view_args['period'] : 'last30days';
		if ( opti_behavior_page_xray_available() ) {
			try {
				/**
				 * Print the Page X-Ray tab body (Opti-Behavior Pro).
				 *
				 * @param array $args    `period`, `exclude_spam`.
				 * @param int   $page_id Dossier to open, 0 = page list.
				 */
				do_action(
					'opti_behavior_smart_insights_render_page_xray',
					array(
						'period'       => $period,
						'exclude_spam' => $view_args['exclude_spam'],
					),
					$page_id
				);
			} catch ( Throwable $e ) {
				echo '<p class="ob-si-xray-locked__text">' . esc_html__( 'Page X-Ray could not be displayed. Please reload the page.', 'opti-behavior' ) . '</p>';
			}
		} else {
			$this->render_xray_locked(
				array(
					'period'       => $period,
					'exclude_spam' => ! empty( $view_args['exclude_spam'] ),
				),
				$periods[ $period ],
				$page_id
			);
		}
	}

	/**
	 * Pro trial page URL for locked Smart Insights CTAs (OPT-764: the single
	 * trial page, `Opti_Behavior_Trial_Invite::link_url()`).
	 *
	 * @param string $placement utm_medium of the link.
	 * @return string
	 */
	private function get_pro_trial_url( $placement ) {
		return class_exists( 'Opti_Behavior_Trial_Invite' ) ? Opti_Behavior_Trial_Invite::link_url( $placement ) : '';
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
