<?php
/**
 * Funnel Views Trait
 *
 * Handles rendering of funnel analytics page views and components.
 *
 * @package opti-behavior
 * @copyright 2025 OptiUser
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for funnel views rendering
 */
trait Opti_Behavior_Funnels_Views_Trait {

	/**
	 * Render the main funnels page
	 */
	public function render_funnels() {
		static $rendered = false;

		// Defensive guard: the page callback should only output once per request.
		// A re-entrant core initialization used to register the Funnels submenu
		// callback twice, duplicating the whole page in wp-admin.
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		global $wpdb;
		$table_sessions = $wpdb->prefix . 'opti_behavior_sessions';

		// Check if tables exist
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Checking table existence.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_sessions ) ) === $table_sessions;

		// Check if any funnels exist.
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Checking table existence.
		$funnel_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_funnels ) ) === $table_funnels;
		$funnel_count = 0;

		if ( $funnel_table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$funnel_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_funnels} WHERE status = 'active'" );
		}
		$tooltips     = opti_behavior_get_funnels_tooltips();
		$exclude_spam = method_exists( $this, 'resolve_spam_exclusion_from_request' )
			? $this->resolve_spam_exclusion_from_request( $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
			: true;
		?>
		<div class="wrap opti-behavior-funnels-page" data-exclude-spam="<?php echo esc_attr( $exclude_spam ? '1' : '0' ); ?>">
			<!-- Header with Funnels Title -->
			<div class="heatmaps-header">
				<div class="heatmaps-header-content">
					<div class="heatmaps-title-section">
						<div class="heatmaps-icon"><i data-lucide="filter"></i></div>
						<div class="heatmaps-title-text">
							<h1 class="heatmaps-title"><?php esc_html_e( 'Funnels', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['what_is_funnel']['title'], $tooltips['what_is_funnel']['content'], $tooltips['what_is_funnel']['simple'], $tooltips['what_is_funnel']['example'], array( 'position' => 'bottom' ) ); ?></h1>
							<div class="heatmaps-subtitle"><?php esc_html_e( 'Track user journeys and conversion paths', 'opti-behavior' ); ?></div>
						</div>
					</div>
					<div class="heatmaps-header-actions">
						<button type="button" class="filter-btn opti-funnels-exclude-spam <?php echo $exclude_spam ? 'active' : ''; ?>" id="funnels-exclude-spam-toggle" aria-pressed="<?php echo esc_attr( $exclude_spam ? 'true' : 'false' ); ?>" aria-label="<?php esc_attr_e( 'Exclude spam traffic', 'opti-behavior' ); ?>" title="<?php esc_attr_e( 'Exclude spam traffic from funnel analytics', 'opti-behavior' ); ?>">
							<span class="filter-icon"><i data-lucide="<?php echo esc_attr( $exclude_spam ? 'shield-check' : 'shield-off' ); ?>" aria-hidden="true"></i></span>
							<span class="filter-label"><?php esc_html_e( 'Exclude Spam', 'opti-behavior' ); ?></span>
						</button>
						<button type="button" class="btn-build-funnel">
							<span class="dashicons dashicons-plus-alt"></span>
							<?php esc_html_e( 'Build New Funnel', 'opti-behavior' ); ?>
						</button>
					</div>
				</div>
			</div>
			<?php if ( function_exists( 'opti_behavior_pro_sodium_banner' ) ) { opti_behavior_pro_sodium_banner(); } ?>

			<div class="funnels-content-wrapper">
				<?php if ( $funnel_count == 0 ) : ?>
				<!-- Empty State -->
				<div class="funnels-empty-state">
					<div class="empty-state-image">
						<img src="<?php echo esc_url( plugins_url( 'assets/images/Funnel-sales-lead.png', dirname( __FILE__ ) ) ); ?>" alt="<?php esc_attr_e( 'Funnel Illustration', 'opti-behavior' ); ?>" />
					</div>
					<h2 class="empty-state-title"><?php esc_html_e( 'No Funnels Yet', 'opti-behavior' ); ?></h2>
					<p class="empty-state-description">
						<?php esc_html_e( 'Create your first funnel to start tracking user behavior and conversion rates through your website.', 'opti-behavior' ); ?>
					</p>
				</div>
			<?php else : ?>
				<!-- Global KPI Summary Bar -->
				<div class="opti-funnel-stats-bar" id="opti-funnel-stats-bar">
					<div class="opti-funnel-stat-card">
						<div class="opti-funnel-stat-card__icon opti-funnel-stat-card__icon--funnels"><i data-lucide="filter"></i></div>
						<div class="opti-funnel-stat-card__body">
							<span class="opti-funnel-stat-card__value" id="opti-funnel-stat-total">—</span>
							<span class="opti-funnel-stat-card__label"><?php esc_html_e( 'Total Funnels', 'opti-behavior' ); ?></span>
						</div>
					</div>
					<div class="opti-funnel-stat-card">
						<div class="opti-funnel-stat-card__icon opti-funnel-stat-card__icon--entries"><i data-lucide="log-in"></i></div>
						<div class="opti-funnel-stat-card__body">
							<span class="opti-funnel-stat-card__value" id="opti-funnel-stat-entries">—</span>
							<span class="opti-funnel-stat-card__label"><?php esc_html_e( 'Total Entries', 'opti-behavior' ); ?></span>
						</div>
					</div>
					<div class="opti-funnel-stat-card">
						<div class="opti-funnel-stat-card__icon opti-funnel-stat-card__icon--completions"><i data-lucide="check-circle"></i></div>
						<div class="opti-funnel-stat-card__body">
							<span class="opti-funnel-stat-card__value" id="opti-funnel-stat-completions">—</span>
							<span class="opti-funnel-stat-card__label"><?php esc_html_e( 'Total Completions', 'opti-behavior' ); ?></span>
						</div>
					</div>
					<div class="opti-funnel-stat-card">
						<div class="opti-funnel-stat-card__icon opti-funnel-stat-card__icon--rate"><i data-lucide="trending-up"></i></div>
						<div class="opti-funnel-stat-card__body">
							<span class="opti-funnel-stat-card__value" id="opti-funnel-stat-rate">—</span>
							<span class="opti-funnel-stat-card__label"><?php esc_html_e( 'Avg. Conversion Rate', 'opti-behavior' ); ?></span>
						</div>
					</div>
				</div>

				<!-- Filters + Search (GLOBAL = Status + Search only; Period/Device are per-funnel) -->
				<div class="opti-funnel-filters">
					<div class="opti-funnel-filters__left">
						<select id="opti-funnel-filter-status" class="opti-funnel-select">
							<option value="active"><?php esc_html_e( 'Active', 'opti-behavior' ); ?></option>
							<option value="suspended"><?php esc_html_e( 'Suspended', 'opti-behavior' ); ?></option>
						</select>
					</div>
					<div class="opti-funnel-filters__right">
						<div class="opti-funnel-search-wrapper">
							<span class="opti-funnel-search-icon"><i data-lucide="search"></i></span>
							<input type="text" id="opti-funnel-search" class="opti-funnel-input" placeholder="<?php esc_attr_e( 'Search funnels…', 'opti-behavior' ); ?>" />
						</div>
					</div>
				</div>

				<!-- Funnel List Container -->
				<div id="opti-funnel-list-container" class="opti-funnel-list">
					<div class="funnel-loading">
						<span class="loading-spinner"></span>
						<span><?php esc_html_e( 'Loading...', 'opti-behavior' ); ?></span>
					</div>
				</div>

				<!-- No-results state (after search/filter) -->
				<div id="opti-funnel-no-results" class="opti-funnel-no-results" style="display:none;">
					<p><?php esc_html_e( 'No funnels match your search.', 'opti-behavior' ); ?></p>
				</div>

				<!-- Pagination -->
				<div id="opti-funnel-pagination" class="opti-funnel-pagination"></div>
			<?php endif; ?>

			<!-- Funnel Builder Modal -->
			<div class="funnel-builder-modal" id="funnel-builder-modal" style="display: none;">
				<div class="funnel-builder-overlay"></div>
				<div class="funnel-builder-content">
					<div class="funnel-builder-header">
						<h2 id="funnel-builder-title"><?php esc_html_e( 'Build New Funnel', 'opti-behavior' ); ?></h2>
						<button class="funnel-builder-close" id="close-funnel-builder">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>
					<div class="funnel-builder-body">
						<form id="funnel-builder-form">
							<div class="form-field">
								<label for="funnel-name"><?php esc_html_e( 'Funnel Name', 'opti-behavior' ); ?> <span class="required">*</span><?php opti_behavior_tooltip_e( $tooltips['funnel_name']['title'], $tooltips['funnel_name']['content'], $tooltips['funnel_name']['simple'], $tooltips['funnel_name']['example'], array( 'position' => 'right' ) ); ?></label>
								<input type="text" id="funnel-name" name="funnel_name" placeholder="<?php esc_attr_e( 'e.g., Purchase Journey', 'opti-behavior' ); ?>" required />
							</div>

							<div class="form-field">
								<label for="funnel-description"><?php esc_html_e( 'Description', 'opti-behavior' ); ?></label>
								<textarea id="funnel-description" name="funnel_description" placeholder="<?php esc_attr_e( 'Describe what this funnel tracks...', 'opti-behavior' ); ?>"></textarea>
							</div>

							<div class="form-field">
								<label><?php esc_html_e( 'Funnel Steps', 'opti-behavior' ); ?> <span class="required">*</span><?php opti_behavior_tooltip_e( $tooltips['funnel_steps']['title'], $tooltips['funnel_steps']['content'], $tooltips['funnel_steps']['simple'], $tooltips['funnel_steps']['example'], array( 'position' => 'right' ) ); ?></label>
								<div id="funnel-steps-container">
									<!-- Steps will be added here dynamically -->
									<div class="funnel-step-item" data-step="1">
										<div class="step-number">1</div>
										<div class="step-fields">
											<input type="text" class="step-name" placeholder="<?php esc_attr_e( 'Step name (e.g., Landing Page)', 'opti-behavior' ); ?>" required />
											<select class="step-match-type">
												<option value="any"><?php esc_html_e( 'Any Page', 'opti-behavior' ); ?></option>
												<option value="exact"><?php esc_html_e( 'Exact URL', 'opti-behavior' ); ?></option>
												<option value="contains"><?php esc_html_e( 'URL Contains', 'opti-behavior' ); ?></option>
												<option value="starts_with"><?php esc_html_e( 'URL Starts With', 'opti-behavior' ); ?></option>
												<option value="ends_with"><?php esc_html_e( 'URL Ends With', 'opti-behavior' ); ?></option>
												<option value="regex"><?php esc_html_e( 'Regex', 'opti-behavior' ); ?></option>
												<option value="pageview"><?php esc_html_e( 'Any Page View', 'opti-behavior' ); ?></option>
											</select>
											<input type="text" class="step-url-pattern" placeholder="<?php esc_attr_e( 'URL pattern (e.g., /cart)', 'opti-behavior' ); ?>" />
										</div>
										<button type="button" class="remove-step" style="display: none;">
											<span class="dashicons dashicons-trash"></span>
										</button>
									</div>
								</div>
								<button type="button" id="add-funnel-step" class="btn-add-step">
									<span class="dashicons dashicons-plus-alt"></span>
									<?php esc_html_e( 'Add Step', 'opti-behavior' ); ?>
								</button>
								<?php opti_behavior_tooltip_e( $tooltips['add_step']['title'], $tooltips['add_step']['content'], $tooltips['add_step']['simple'], $tooltips['add_step']['example'], array( 'position' => 'right' ) ); ?>
							</div>
						</form>
					</div>
					<div class="funnel-builder-footer">
						<button type="button" class="btn-cancel" id="cancel-funnel-builder"><?php esc_html_e( 'Cancel', 'opti-behavior' ); ?></button>
						<button type="button" class="btn-save-funnel" id="save-funnel-builder"><?php esc_html_e( 'Save Funnel', 'opti-behavior' ); ?></button>
					</div>
				</div>
			</div>
			</div><!-- .funnels-content-wrapper -->
		</div>
		<?php
	}

}
