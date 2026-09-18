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

		// Per-funnel detail routing (spec.md §4.1). A `funnel` query arg switches
		// this same page slug from the funnels LIST to a single funnel's detail
		// view (header + period control + advanced filter + KPIs + steps). No
		// `funnel` arg → the index list below, unchanged.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing; no state change.
		$funnel_arg = isset( $_GET['funnel'] ) ? sanitize_text_field( wp_unslash( $_GET['funnel'] ) ) : '';
		$requested_funnel = absint( $funnel_arg );
		if ( $requested_funnel > 0 ) {
			$this->render_funnel_detail( $requested_funnel );
			return;
		}

		// A `funnel` arg that is present but not a positive integer (?funnel=abc,
		// ?funnel=0, ?funnel=-1) is a request for a funnel that cannot exist. It
		// used to fall through to the index list, so the user got the whole
		// Funnels list back with no hint that their link was bad. Route it to the
		// detail view's graceful "Funnel not found" panel instead: id 0 matches no
		// row, so the panel renders with HTTP 200 and no fatal
		// (QA-B-FUNNEL-021).
		if ( '' !== trim( $funnel_arg ) ) {
			$this->render_funnel_detail( 0 );
			return;
		}

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
						<?php
						// The header button cannot host a tooltip bubble (it would nest an
						// interactive element), so the shared `rescan_site` help copy is
						// reused as its native title instead of a second hard-coded string.
						$rescan_tip = isset( $tooltips['rescan_site']['content'] )
							? $tooltips['rescan_site']['content']
							: __( 'Detect this site again and restore every dismissed suggestion', 'opti-behavior' );
						?>
						<button type="button" class="filter-btn opti-funnel-rescan-btn" id="opti-funnel-rescan" aria-label="<?php esc_attr_e( 'Re-scan site', 'opti-behavior' ); ?>" title="<?php echo esc_attr( $rescan_tip ); ?>">
							<span class="filter-icon"><i data-lucide="radar" aria-hidden="true"></i></span>
							<span class="filter-label"><?php esc_html_e( 'Re-scan site', 'opti-behavior' ); ?></span>
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
				<?php $this->render_funnel_suggestions_panel( 'empty', $tooltips ); ?>
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

				<?php
				// Order is dynamic: with zero funnels the suggestions lead the page;
				// once funnels exist the list leads and suggestions follow it.
				$this->render_funnel_suggestions_panel( 'list', $tooltips );
				?>
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

	/**
	 * Render the auto-funnel suggestions container (spec.md §2.3).
	 *
	 * Locked decision 7: the Funnels page is the ONLY suggestion surface — the
	 * empty state and the area directly above the funnel list. No dashboard
	 * admin notice is rendered anywhere.
	 *
	 * The container is an empty shell on purpose: site detection is not run
	 * during the page render (it would add a third-party plugin sweep to every
	 * page load). `assets/js/funnel-suggestions.js` calls
	 * `optibehavior_funnel_suggestions` after paint and renders the cards, so
	 * this markup only has to exist and stay hidden until there is something to
	 * show.
	 *
	 * Two shells, one markup (since 1.8.4.1):
	 *
	 * - `$surface === 'empty'` — the onboarding panel, rendered in full. The
	 *   summary bar stays hidden and the body is never collapsed, so the empty
	 *   state is byte-for-byte the experience it always was.
	 * - `$surface === 'list'` — the site already has funnels, so the panel
	 *   defaults to the collapsed summary bar ("N new · M created") and the body
	 *   is one click away. Which state is used is a per-user preference
	 *   (`opti_behavior_funnel_suggestions_ui` user meta) handed to the script
	 *   through `optiBehaviorFunnelSuggestions.prefs`.
	 *
	 * The already-created / similar-flagged cards live in their own sub-group
	 * behind "Show created (N)". That is user-controlled visibility, NOT the
	 * dedupe hiding a card (locked decision 8): every flagged card is still in
	 * the payload, still rendered, one click away.
	 *
	 * @since 1.8.4
	 * @since 1.8.4.1 Collapsible summary bar + "show created" sub-group.
	 * @param string $surface  Where the panel is rendered: 'empty' | 'list'.
	 * @param array  $tooltips Funnels tooltip definitions (already loaded by the caller).
	 */
	private function render_funnel_suggestions_panel( $surface, $tooltips ) {
		$tip = isset( $tooltips['suggested_funnels'] ) ? $tooltips['suggested_funnels'] : null;
		?>
		<div id="opti-funnel-suggestions"
			class="opti-funnel-suggestions opti-funnel-suggestions--<?php echo esc_attr( $surface ); ?>"
			data-surface="<?php echo esc_attr( $surface ); ?>"
			style="display:none;">
			<?php
			// Compact summary bar. Shown by the script on the 'list' surface only;
			// the counts are filled once the payload arrives.
			?>
			<div class="opti-funnel-suggestions__bar" id="opti-funnel-suggestions-bar" style="display:none;">
				<span class="opti-funnel-suggestions__bar-icon"><i data-lucide="lightbulb" aria-hidden="true"></i></span>
				<span class="opti-funnel-suggestions__bar-title"><?php esc_html_e( 'Suggested funnels', 'opti-behavior' ); ?></span>
				<span class="opti-funnel-suggestions__bar-counts" id="opti-funnel-suggestions-bar-counts"></span>
				<span class="opti-funnel-suggestions__bar-spacer"></span>
				<button type="button"
					class="opti-funnel-suggestions__bar-btn opti-funnel-suggestions__bar-btn--primary"
					id="opti-funnel-suggestions-toggle"
					aria-expanded="false"
					aria-controls="opti-funnel-suggestions-body">
					<span class="opti-funnel-suggestions__bar-btn-label"><?php esc_html_e( 'Show', 'opti-behavior' ); ?></span>
				</button>
				<button type="button" class="opti-funnel-suggestions__bar-btn" id="opti-funnel-suggestions-bar-rescan">
					<i data-lucide="radar" aria-hidden="true"></i>
					<span><?php esc_html_e( 'Re-scan site', 'opti-behavior' ); ?></span>
				</button>
			</div>
			<?php
			// Status mirror for the collapsed state: the real status bar lives
			// inside the body, which is hidden while the panel is collapsed, so a
			// re-scan started from the bar would otherwise report into the void.
			// CSS keeps exactly one of the two visible.
			?>
			<div class="opti-funnel-suggestions__status opti-funnel-suggestions__bar-status" id="opti-funnel-suggestions-bar-status" role="status" aria-live="polite"></div>

			<div class="opti-funnel-suggestions__body" id="opti-funnel-suggestions-body">
			<div class="opti-funnel-suggestions__header">
				<div class="opti-funnel-suggestions__heading">
					<h2 class="opti-funnel-suggestions__title">
						<span class="opti-funnel-suggestions__title-icon"><i data-lucide="sparkles" aria-hidden="true"></i></span>
						<?php esc_html_e( 'Suggested Funnels', 'opti-behavior' ); ?>
						<?php
						if ( $tip ) {
							opti_behavior_tooltip_e(
								$tip['title'],
								$tip['content'],
								isset( $tip['simple'] ) ? $tip['simple'] : '',
								isset( $tip['example'] ) ? $tip['example'] : '',
								array( 'position' => 'bottom' )
							);
						}
						?>
					</h2>
					<p class="opti-funnel-suggestions__subtitle" id="opti-funnel-suggestions-subtitle"></p>
					<div class="opti-funnel-suggestions__detection" id="opti-funnel-suggestions-detection"></div>
				</div>
				<div class="opti-funnel-suggestions__actions">
					<button type="button" class="btn-create-recommended" id="opti-funnel-create-recommended" style="display:none;">
						<i data-lucide="wand-2" aria-hidden="true"></i>
						<span class="opti-funnel-btn-label"><?php esc_html_e( 'Set up recommended funnels', 'opti-behavior' ); ?></span>
					</button>
				</div>
			</div>
			<div class="opti-funnel-suggestions__status" id="opti-funnel-suggestions-status" role="status" aria-live="polite"></div>
			<div class="opti-funnel-suggestions__grid" id="opti-funnel-suggestions-grid"></div>
			<?php
			// Already-created / similar-flagged cards. Populated and toggled by
			// the script on the 'list' surface; on the empty state every card
			// stays in the main grid above (there is nothing created yet).
			?>
			<div class="opti-funnel-suggestions__created" id="opti-funnel-suggestions-created" style="display:none;">
				<button type="button"
					class="opti-funnel-suggestions__created-toggle"
					id="opti-funnel-suggestions-created-toggle"
					aria-expanded="false"
					aria-controls="opti-funnel-suggestions-created-grid"></button>
				<div class="opti-funnel-suggestions__grid opti-funnel-suggestions__grid--created" id="opti-funnel-suggestions-created-grid" style="display:none;"></div>
			</div>
			</div><!-- .opti-funnel-suggestions__body -->
		</div>
		<?php
	}

	/**
	 * Render a single funnel's detail page (spec.md §4.1 / §4.2).
	 *
	 * Reached via `admin.php?page=opti-behavior-funnels&funnel=<id>`. Shows the
	 * funnel header with a kept period control and the PRO advanced-filter panel
	 * (live for entitled PRO users, locked + upsell otherwise), plus KPI cards
	 * and the step visualization (rendered client-side by funnels.js).
	 *
	 * @param int $funnel_id Requested funnel id.
	 */
	private function render_funnel_detail( $funnel_id ) {
		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';
		$index_url     = admin_url( 'admin.php?page=opti-behavior-funnels' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix.
		$funnel = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, description, steps, status FROM {$table_funnels} WHERE id = %d",
				$funnel_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Graceful not-found: unknown, deleted, or inactive funnel → message + back
		// link, never a fatal or an empty analytics shell (spec.md §4.1).
		if ( ! $funnel || 'active' !== $funnel->status ) {
			?>
			<div class="wrap opti-behavior-funnels-page opti-behavior-funnel-detail-page">
				<div class="opti-funnel-detail-back">
					<a href="<?php echo esc_url( $index_url ); ?>" class="opti-funnel-back-link">
						<i data-lucide="arrow-left"></i>
						<span><?php esc_html_e( 'Back to Funnels', 'opti-behavior' ); ?></span>
					</a>
				</div>
				<div class="funnels-empty-state">
					<h2 class="empty-state-title"><?php esc_html_e( 'Funnel not found', 'opti-behavior' ); ?></h2>
					<p class="empty-state-description">
						<?php esc_html_e( 'This funnel does not exist or is no longer active.', 'opti-behavior' ); ?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		$step_defs    = json_decode( $funnel->steps, true );
		$step_count   = is_array( $step_defs ) ? count( $step_defs ) : 0;
		$pro_active   = $this->funnel_advanced_filter_available();
		$exclude_spam = method_exists( $this, 'resolve_spam_exclusion_from_request' )
			? $this->resolve_spam_exclusion_from_request( $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
			: true;
		$upgrade_url  = admin_url( 'admin.php?page=opti-behavior-recordings' );
		?>
		<div class="wrap opti-behavior-funnels-page opti-behavior-funnel-detail-page" data-exclude-spam="<?php echo esc_attr( $exclude_spam ? '1' : '0' ); ?>">
			<div class="heatmaps-header">
				<a href="<?php echo esc_url( $index_url ); ?>" class="opti-funnel-back-btn">
					<i data-lucide="arrow-left"></i>
					<span><?php esc_html_e( 'Back to Funnels', 'opti-behavior' ); ?></span>
				</a>
				<div class="heatmaps-header-content">
					<div class="heatmaps-title-section">
						<div class="heatmaps-icon"><i data-lucide="filter"></i></div>
						<div class="heatmaps-title-text">
							<h1 class="heatmaps-title"><?php echo esc_html( $funnel->name ); ?></h1>
							<div class="heatmaps-subtitle">
								<?php
								/* translators: %d: number of funnel steps. */
								echo esc_html( sprintf( _n( '%d step', '%d steps', $step_count, 'opti-behavior' ), $step_count ) );
								?>
								<?php if ( $funnel->description ) : ?>
									&nbsp;&middot;&nbsp;<?php echo esc_html( $funnel->description ); ?>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<div class="heatmaps-header-actions dashboard-controls opti-funnel-detail-controls">
						<!-- Period control bar — reuses dashboard .dashboard-controls / .period-selector /
						     .refresh-btn classes so the funnel header matches the Analytics Dashboard
						     control row (period + always-visible date pickers + Apply + Refresh + Filters). -->
						<select id="funnel-detail-period" class="period-selector">
							<option value="7days"><?php esc_html_e( 'Last 7 Days', 'opti-behavior' ); ?></option>
							<option value="30days" selected><?php esc_html_e( 'Last 30 Days', 'opti-behavior' ); ?></option>
							<option value="90days"><?php esc_html_e( 'Last 90 Days', 'opti-behavior' ); ?></option>
							<option value="custom"><?php esc_html_e( 'Custom Range', 'opti-behavior' ); ?></option>
						</select>
						<input type="date" id="funnel-detail-start" class="opti-funnel-detail-date" />
						<input type="date" id="funnel-detail-end" class="opti-funnel-detail-date" />
						<button type="button" class="refresh-btn" id="funnel-detail-apply-range">
							<i data-lucide="calendar"></i>
							<?php esc_html_e( 'Apply', 'opti-behavior' ); ?>
						</button>
						<button type="button" class="refresh-btn" id="funnel-detail-refresh">
							<i data-lucide="refresh-cw"></i>
							<?php esc_html_e( 'Refresh', 'opti-behavior' ); ?>
						</button>
						<!-- Advanced filter toggle (mirrors dashboard #toggle-advanced-filters) -->
						<button class="refresh-btn" id="toggle-advanced-filters" type="button" aria-expanded="false" aria-controls="advanced-filters-panel">
							<i data-lucide="sliders-horizontal"></i>
							<span class="advanced-filters-toggle-label"><?php esc_html_e( 'Filters', 'opti-behavior' ); ?></span>
							<?php if ( ! $pro_active ) : ?>
								<span class="opti-funnel-pro-lock" aria-hidden="true"><i data-lucide="lock"></i></span>
							<?php endif; ?>
						</button>
					</div>
				</div>
			</div>

			<?php $this->render_funnel_advanced_filters_panel( ! $pro_active, $upgrade_url ); ?>

			<div id="opti-funnel-detail" class="opti-funnel-detail" data-funnel-id="<?php echo esc_attr( (int) $funnel->id ); ?>" data-advanced-pro="<?php echo esc_attr( $pro_active ? '1' : '0' ); ?>">
				<!-- KPI summary bar -->
				<div class="opti-funnel-stats-bar">
					<div class="opti-funnel-stat-card">
						<div class="opti-funnel-stat-card__icon opti-funnel-stat-card__icon--entries"><i data-lucide="log-in"></i></div>
						<div class="opti-funnel-stat-card__body">
							<span class="opti-funnel-stat-card__value" id="funnel-detail-entries">—</span>
							<span class="opti-funnel-stat-card__label"><?php esc_html_e( 'Total Entries', 'opti-behavior' ); ?></span>
						</div>
					</div>
					<div class="opti-funnel-stat-card">
						<div class="opti-funnel-stat-card__icon opti-funnel-stat-card__icon--completions"><i data-lucide="check-circle"></i></div>
						<div class="opti-funnel-stat-card__body">
							<span class="opti-funnel-stat-card__value" id="funnel-detail-completions">—</span>
							<span class="opti-funnel-stat-card__label"><?php esc_html_e( 'Total Completions', 'opti-behavior' ); ?></span>
						</div>
					</div>
					<div class="opti-funnel-stat-card">
						<div class="opti-funnel-stat-card__icon opti-funnel-stat-card__icon--rate"><i data-lucide="trending-up"></i></div>
						<div class="opti-funnel-stat-card__body">
							<span class="opti-funnel-stat-card__value" id="funnel-detail-rate">—</span>
							<span class="opti-funnel-stat-card__label"><?php esc_html_e( 'Conversion Rate', 'opti-behavior' ); ?></span>
						</div>
					</div>
					<div class="opti-funnel-stat-card">
						<div class="opti-funnel-stat-card__icon opti-funnel-stat-card__icon--funnels"><i data-lucide="trending-down"></i></div>
						<div class="opti-funnel-stat-card__body">
							<span class="opti-funnel-stat-card__value" id="funnel-detail-dropoff">—</span>
							<span class="opti-funnel-stat-card__label"><?php esc_html_e( 'Drop-off Rate', 'opti-behavior' ); ?></span>
						</div>
					</div>
				</div>

				<!-- Step visualization (rendered client-side by funnels.js) -->
				<div class="funnel-steps-wrapper">
					<div class="funnel-steps-label"><?php esc_html_e( 'Steps', 'opti-behavior' ); ?></div>
					<div class="funnel-steps-container" data-funnel-id="<?php echo esc_attr( (int) $funnel->id ); ?>">
						<div class="funnel-loading"><span class="loading-spinner"></span><span><?php esc_html_e( 'Loading...', 'opti-behavior' ); ?></span></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the funnel detail-page advanced-filters panel (spec.md §4.2).
	 *
	 * Mirrors the dashboard 4-column panel (render_advanced_filters_panel() in
	 * trait-opti-behavior-dashboard-views.php) but drops `filter-exit-page`
	 * (spec.md §3-6: funnel completion/abandonment already models the exit). The
	 * element ids are reused verbatim from the dashboard panel — safe here because
	 * the funnel detail page renders NO dashboard markup, so there is no id
	 * collision (spec.md §6). When $locked is true (free / non-entitled), fields
	 * are disabled and an upsell overlay replaces the Apply/Reset actions.
	 *
	 * @param bool   $locked      Whether to render the locked (free) variant.
	 * @param string $upgrade_url Upgrade CTA target for the locked variant.
	 */
	private function render_funnel_advanced_filters_panel( $locked, $upgrade_url = '' ) {
		// Reuse the dashboard's exact panel component (shared trait). Funnel drops
		// the Exit Page field (spec §3-6) and, for free users, renders the locked
		// PRO-upsell variant — everything else is byte-for-byte the dashboard panel.
		$this->render_shared_advanced_filters_panel(
			array(
				'include_exit_page' => false,
				'locked'            => (bool) $locked,
				'upgrade_url'       => $upgrade_url,
			)
		);
	}

}
