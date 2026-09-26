<?php
/**
 * Cleanup Task Registry
 *
 * Single source of truth for the unified "Cleanup Tasks" panel (Danger Zone →
 * Smart Cleanup). Describes every periodic cleanup task the plugin suite
 * schedules — what it deletes, which cron hook drives it, when it runs next —
 * and implements the safe "Run now" mechanism: an immediate one-off cron event
 * of the task's OWN hook (never a new deletion code path, never inline
 * deletion in the admin request).
 *
 * Pro rows are appended through the `opti_behavior_cleanup_tasks` filter
 * (same injection pattern as `opti_behavior_cron_hooks` and the settings-tab
 * render actions) — Free-only installs never see them.
 *
 * Duplicate-guard rule (lesson from the duplicate backfill-cron bug, Free
 * commit 83371a5): every scheduling decision scans the FULL cron array for
 * the hook — recurring vs one-off events counted separately — never the
 * earliest-event-only `wp_next_scheduled()` shortcut.
 *
 * @package opti-behavior
 * @since   1.9.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Cleanup_Task_Registry
 *
 * Static registry + run-now queueing for periodic cleanup tasks.
 *
 * @since 1.9.x
 */
class Opti_Behavior_Cleanup_Task_Registry {

	/** Filter through which Pro (or extensions) append task descriptors. */
	const TASKS_FILTER = 'opti_behavior_cleanup_tasks';

	/** Valid `run_now` modes. `cron-spawn` = queue one-off event of the task's own hook. */
	const RUN_NOW_MODES = array( 'cron-spawn', 'link', 'none' );

	/** Window (seconds) within which an upcoming recurring run makes run-now redundant. */
	const DUE_SOON_WINDOW = 600;

	/** Option: last recorded run per task (task id => timestamp/status/trigger). */
	const RUNS_OPTION = 'opti_behavior_cleanup_task_runs';

	/** Option: pending manual run-now requests (task id => unix time queued). */
	const MANUAL_OPTION = 'opti_behavior_cleanup_task_manual';

	/** Seconds a run-now request stays attributable to the next run of its hook. */
	const MANUAL_TTL = 3600;

	/** Run statuses, lowest to highest precedence when several reports merge. */
	const RUN_STATUSES = array( 'skipped', 'completed', 'partial', 'aborted', 'deferred' );

	/**
	 * Active run records (stack: a task hook may, in theory, fire another).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $run_stack = array();

	/** Whether the begin/end run wrappers are attached to the task hooks. */
	private static $tracking_registered = false;

	/** True while end_run() writes its own history entry (never captured). */
	private static $writing_history = false;

	/**
	 * Canonical Free task descriptors (unfiltered).
	 *
	 * Each descriptor:
	 * - id              string  Stable key (sanitize_key), whitelist for run-now.
	 * - group           string  Section grouping key.
	 * - title           string  Human title.
	 * - description     string  Plain-language "what it deletes".
	 * - hook            string  The task's own cron hook (run-now schedules THIS hook).
	 * - kind            string  recurring | one-off.
	 * - enabled_cb      callable(): bool  Whether current settings let the task delete anything.
	 * - last_run_cb     callable(): string|int|null  Site-local datetime OR site-local
	 *                   timestamp of the last completed run (both shapes accepted).
	 * - status_cb       callable(): string|null  Optional status label (one-off tasks).
	 * - frequency_cb    callable(): string|null  Optional override for the Frequency
	 *                   column, for tasks whose WP recurrence does not equal the
	 *                   cadence the admin configured (e.g. "monthly" armed as a
	 *                   daily event and throttled inside the callback).
	 * - next_run_note_cb callable(bool $has_event, bool $enabled): string|null
	 *                   Optional honest explanation of the current schedule state,
	 *                   shown under the Next run cell. MUST describe what the code
	 *                   actually does — never a promised self-heal that cannot fire.
	 * - settings_anchor string  Fragment of the owning settings card.
	 * - run_now         string  cron-spawn | link | none.
	 * - link_label      string  Label when run_now = link.
	 * - details         string[] Optional bullet list rendered under the description.
	 * - log_idle_runs   bool    Whether a SCHEDULED run that deleted nothing still
	 *                   writes a Cleanup History entry (default true). Hourly
	 *                   checks set false so they cannot flood the history.
	 *                   Manual runs are always logged.
	 *
	 * @since 1.9.x
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_free_tasks() {
		$tasks = array();

		// 1. Master daily retention pass (detailed data + spam tier + heavy files).
		//    The "what it removes" list reflects the saved retention settings.
		$policy_ready = class_exists( 'Opti_Behavior_Retention_Policy' );
		$raw_days     = $policy_ready ? Opti_Behavior_Retention_Policy::get_raw_retention_days() : 365;
		$files_days   = $policy_ready ? Opti_Behavior_Retention_Policy::get_effective_files_retention_days() : 0;
		$spam_daily   = $policy_ready ? Opti_Behavior_Retention_Policy::is_spam_daily_enabled() : true;
		$agg_months   = $policy_ready ? Opti_Behavior_Retention_Policy::get_aggregates_retention_months() : 0;
		/** This filter is documented in includes/class-opti-behavior-smart-cleanup-service.php */
		$bot_log_days = absint( apply_filters( 'opti_behavior_bot_visits_retention_days', 30 ) );

		$retention_details = array();

		$retention_details[] = $raw_days > 0
			/* translators: %d: detailed data retention window in days */
			? sprintf( __( 'Visitor sessions older than %1$d days, with everything linked to them: events, clicks, pageviews, visited pages, referrers, recordings, form, funnel and A/B test entries, errors and performance data — plus error summaries, broken-link (404) reports and per-visitor daily stats not seen for %1$d days.', 'opti-behavior' ), $raw_days )
			: __( 'Visitor sessions: never deleted by age (the detailed data window is set to 0).', 'opti-behavior' );

		if ( $spam_daily ) {
			$retention_details[] = $bot_log_days > 0
				/* translators: %d: days crawler visit log rows are kept */
				? sprintf( __( 'Spam, bot and automated sessions of any age, plus crawler visit logs older than %d days.', 'opti-behavior' ), $bot_log_days )
				: __( 'Spam, bot and automated sessions of any age.', 'opti-behavior' );
		} else {
			$retention_details[] = __( 'Spam, bot and automated sessions: daily purge is turned off.', 'opti-behavior' );
		}

		if ( $files_days > 0 && ( $raw_days < 1 || $files_days < $raw_days ) ) {
			/* translators: %d: heavy files retention window in days */
			$retention_details[] = sprintf( __( 'Heatmap files older than %d days are moved to the archive — restorable until the archived heatmap data purge removes them.', 'opti-behavior' ), $files_days );
		} elseif ( $raw_days > 0 ) {
			$retention_details[] = __( 'Heatmap files of deleted sessions are moved to the archive — restorable until the archived heatmap data purge removes them.', 'opti-behavior' );
		}

		$retention_details[] = __( 'Heatmap page entries left without any data, and old debug log files.', 'opti-behavior' );

		$retention_details[] = $agg_months > 0
			/* translators: %d: months dashboard daily summaries are kept */
			? sprintf( __( 'Kept: dashboard daily summaries (charts and totals) for %d months.', 'opti-behavior' ), $agg_months )
			: __( 'Kept: dashboard daily summaries (charts and totals), forever.', 'opti-behavior' );

		$tasks[] = array(
			'id'              => 'db-retention',
			'group'           => 'database',
			'title'           => __( 'Daily data retention', 'opti-behavior' ),
			'description'     => __( 'Removes expired visitor data, spam/bot sessions and old heatmap files every day, in small background batches.', 'opti-behavior' ),
			'details'         => $retention_details,
			'hook'            => 'opti_behavior_heatmap_cron_daily',
			'kind'            => 'recurring',
			'enabled_cb'      => function () {
				if ( ! class_exists( 'Opti_Behavior_Retention_Policy' ) ) {
					return true;
				}
				return Opti_Behavior_Retention_Policy::get_raw_retention_days() > 0
					|| Opti_Behavior_Retention_Policy::is_spam_daily_enabled();
			},
			'last_run_cb'     => function () {
				return self::latest_cleanup_log_timestamp( array( 'spam_daily_tier' ), array( 'auto' ) );
			},
			'settings_anchor' => '#opti-behavior-data-retention-widget',
			'run_now'         => 'cron-spawn',
		);

		// 1b. Spam/bot tier — the automatic twin of the Manual Cleanup card's
		// "Clean Bot/Spam Traffic" button (same Traffic Behavior selection,
		// same cascade). It rides the daily retention hook for its schedule
		// and owns a one-off hook for run-now + capped continuations, so the
		// admin can see it, run it, and read its last run without guessing
		// whether the button's work ever happens on its own.
		$tasks[] = array(
			'id'               => 'spam-tier',
			'group'            => 'database',
			'title'            => __( 'Bot/spam traffic cleanup', 'opti-behavior' ),
			'description'      => __( 'Removes every session classified as spam, bot or automated by the shared Traffic Behavior policy — the same rule as the "Clean Bot/Spam Traffic" button — together with their events, recordings and heatmap files. Runs every day inside Daily data retention; the bot-visit log is kept 30 days.', 'opti-behavior' ),
			'hook'             => 'opti_behavior_spam_tier_run',
			'kind'             => 'recurring',
			'enabled_cb'       => function () {
				return ! class_exists( 'Opti_Behavior_Retention_Policy' ) || Opti_Behavior_Retention_Policy::is_spam_daily_enabled();
			},
			'last_run_cb'      => function () {
				return self::latest_cleanup_log_timestamp( array( 'spam_daily_tier' ), array( 'auto' ) );
			},
			'frequency_cb'     => function () {
				return __( 'Daily (with Daily data retention)', 'opti-behavior' );
			},
			'next_run_note_cb' => function ( $has_event, $enabled ) {
				if ( ! $enabled ) {
					return __( 'Turned off in Data Retention (spam/bot tier).', 'opti-behavior' );
				}
				$daily = wp_next_scheduled( 'opti_behavior_heatmap_cron_daily' );
				if ( $daily ) {
					return sprintf(
						/* translators: %s: site-local date/time of the next Daily data retention run. */
						__( 'Next automatic pass: with Daily data retention on %s.', 'opti-behavior' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $daily )
					);
				}
				return __( 'Runs with Daily data retention (that event is not scheduled right now).', 'opti-behavior' );
			},
			'settings_anchor'  => '#opti-behavior-data-retention-widget',
			'run_now'          => 'cron-spawn',
		);

		// 2. Conditional Cleanup rules cron. NO run-now button by design:
		//    (a) the handler's internal last-run throttle silently no-ops an
		//    early fire (a lying button); (b) the Manual Cleanup card already
		//    offers the superior preview → confirm → execute flow.
		$tasks[] = array(
			'id'              => 'conditional-rules',
			'group'           => 'database',
			'title'           => __( 'Conditional Cleanup rules', 'opti-behavior' ),
			'description'     => __( 'Deletes sessions matching your saved expert rules (OR logic), capped per run. Use the Manual Cleanup card to preview and run the same rules on demand.', 'opti-behavior' ),
			'hook'            => 'opti_behavior_scheduled_smart_cleanup',
			'kind'            => 'recurring',
			'enabled_cb'      => function () {
				$settings = get_option( 'opti_behavior_auto_cleanup_settings', array() );
				return ! empty( $settings['enabled'] );
			},
			'last_run_cb'     => function () {
				$settings = get_option( 'opti_behavior_auto_cleanup_settings', array() );
				return ( is_array( $settings ) && ! empty( $settings['last_run'] ) ) ? $settings['last_run'] : null;
			},
			// The WP recurrence is NOT the admin's cadence: 'monthly' is armed as
			// a daily event and throttled inside run_scheduled_cleanup(). Report
			// the saved setting so the column stops claiming "Daily" for a
			// monthly configuration.
			'frequency_cb'    => function () {
				$settings  = get_option( 'opti_behavior_auto_cleanup_settings', array() );
				$frequency = ( is_array( $settings ) && isset( $settings['frequency'] ) ) ? (string) $settings['frequency'] : 'daily';
				$labels    = array(
					'daily'   => __( 'Daily', 'opti-behavior' ),
					'weekly'  => __( 'Weekly', 'opti-behavior' ),
					'monthly' => __( 'Monthly', 'opti-behavior' ),
				);
				return isset( $labels[ $frequency ] ) ? $labels[ $frequency ] : $labels['daily'];
			},
			// Honest schedule states. The checkbox and this row read the same
			// option + the same cron hook, so they can never contradict each
			// other silently again.
			'next_run_note_cb' => function ( $has_event, $enabled ) {
				if ( ! $enabled ) {
					return __( 'Turned off — "Enable automatic cleanup" is unchecked below.', 'opti-behavior' );
				}

				$settings   = get_option( 'opti_behavior_auto_cleanup_settings', array() );
				$conditions = ( is_array( $settings ) && isset( $settings['conditions'] ) && is_array( $settings['conditions'] ) )
					? array_filter(
						$settings['conditions'],
						function ( $value ) {
							return ! ( '' === $value || null === $value || false === $value );
						}
					)
					: array();

				if ( empty( $conditions ) ) {
					return __( 'Enabled, but no cleanup conditions are saved — each run deletes nothing.', 'opti-behavior' );
				}

				if ( ! $has_event ) {
					return __( 'The background event is missing. It is re-armed automatically on the next wp-admin page load; save the schedule below to force it now.', 'opti-behavior' );
				}

				return null;
			},
			// Anchor the collapsed expert <details> itself, not the enclosing
			// Manual Cleanup card: landing on the card would show only the
			// unrelated bot/spam button. The panel JS expands ancestor/target
			// <details> before scrolling, so the rules UI is visible on arrival.
			'settings_anchor' => '#opti-behavior-conditional-cleanup-details',
			'run_now'         => 'link',
			'link_label'      => __( 'Preview & run', 'opti-behavior' ),
		);

		// 3. `_orphaned/` archive retention purge (bounded, locked, cursor-resumable).
		$tasks[] = array(
			'id'              => 'orphan-archive-purge',
			'group'           => 'files',
			'title'           => __( 'Archived heatmap data purge', 'opti-behavior' ),
			'description'     => __( 'Permanently deletes _orphaned archive folders older than the archive retention window. Newer archives stay restorable. Runs daily in small background batches.', 'opti-behavior' ),
			'hook'            => 'opti_behavior_heatmap_orphan_purge_daily',
			'kind'            => 'recurring',
			'enabled_cb'      => function () {
				if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Purge' ) ) {
					return false;
				}
				return ( new Opti_Behavior_Heatmap_Orphan_Purge() )->get_retention_days_setting() > 0;
			},
			'last_run_cb'     => function () {
				if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Purge' ) ) {
					return null;
				}
				$progress = ( new Opti_Behavior_Heatmap_Orphan_Purge() )->get_progress();
				return ! empty( $progress['finished_at'] ) ? (string) $progress['finished_at'] : null;
			},
			'settings_anchor' => '#opti-behavior-heatmap-orphan-purge-widget',
			'run_now'         => 'cron-spawn',
		);

		// 4. A/B testing raw-data cleanup (rides the master retention window).
		$tasks[] = array(
			'id'              => 'ab-cleanup',
			'group'           => 'database',
			'title'           => __( 'A/B testing data cleanup', 'opti-behavior' ),
			'description'     => __( 'Deletes raw A/B test interaction data older than the master retention window. Aggregated results are kept. Runs every day.', 'opti-behavior' ),
			'hook'            => 'opti_behavior_ab_cleanup',
			'kind'            => 'recurring',
			'enabled_cb'      => function () {
				if ( ! class_exists( 'Opti_Behavior_Retention_Policy' ) ) {
					return true;
				}
				return Opti_Behavior_Retention_Policy::get_raw_retention_days() > 0;
			},
			'last_run_cb'     => '__return_null',
			'settings_anchor' => '#opti-behavior-data-retention-widget',
			'run_now'         => 'cron-spawn',
		);

		// 6. DB size cap (1.9.3) — global + per-table ceilings, oldest raw rows first.
		$tasks[] = array(
			'id'               => 'db-size-cap',
			'group'            => 'database',
			'title'            => __( 'Database size cap', 'opti-behavior' ),
			'description'      => __( 'Keeps the plugin tables under the configured size caps by removing the oldest raw data first — dashboard summaries are never touched, the last 7 days are always kept.', 'opti-behavior' ),
			'details'          => class_exists( 'Opti_Behavior_DB_Size_Cap' ) ? Opti_Behavior_DB_Size_Cap::describe() : array(),
			'hook'             => 'opti_behavior_db_size_cap_run',
			'kind'             => 'recurring',
			'enabled_cb'       => function () {
				return class_exists( 'Opti_Behavior_DB_Size_Cap' ) && Opti_Behavior_DB_Size_Cap::is_enabled();
			},
			'last_run_cb'      => function () {
				$state = class_exists( 'Opti_Behavior_DB_Size_Cap' ) ? Opti_Behavior_DB_Size_Cap::get_state() : array();
				return ! empty( $state['last_run'] ) ? (string) $state['last_run'] : null;
			},
			'frequency_cb'     => function () {
				return __( 'Daily (with Daily data retention)', 'opti-behavior' );
			},
			'next_run_note_cb' => function ( $has_event, $enabled ) {
				if ( ! $enabled ) {
					return __( 'No size cap configured in Data Retention → Advanced.', 'opti-behavior' );
				}
				$daily = wp_next_scheduled( 'opti_behavior_heatmap_cron_daily' );
				if ( $daily ) {
					return sprintf(
						/* translators: %s: site-local date/time of the next Daily data retention run. */
						__( 'Next automatic pass: with Daily data retention on %s.', 'opti-behavior' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $daily )
					);
				}
				return __( 'Runs with Daily data retention (that event is not scheduled right now).', 'opti-behavior' );
			},
			'settings_anchor'  => '#opti-behavior-data-retention-widget',
			'run_now'          => 'cron-spawn',
		);

		// 7. Schema upkeep (1.9.3) — redundant index drop, InnoDB conversion, OPTIMIZE reclaim.
		$tasks[] = array(
			'id'               => 'db-schema-upkeep',
			'group'            => 'database',
			'title'            => __( 'Database schema upkeep', 'opti-behavior' ),
			'description'      => __( 'Drops duplicate indexes, moves plugin tables to InnoDB one at a time and runs OPTIMIZE TABLE where deleted rows left reclaimable space. Safe to run with an older Pro plugin still active.', 'opti-behavior' ),
			'details'          => class_exists( 'Opti_Behavior_DB_Schema_Migration' ) ? Opti_Behavior_DB_Schema_Migration::describe() : array(),
			'hook'             => 'opti_behavior_db_schema_migration_run',
			'kind'             => 'recurring',
			'enabled_cb'       => '__return_true',
			'last_run_cb'      => function () {
				$state = class_exists( 'Opti_Behavior_DB_Schema_Migration' ) ? Opti_Behavior_DB_Schema_Migration::get_state() : array();
				return ! empty( $state['last_run'] ) ? (string) $state['last_run'] : null;
			},
			'frequency_cb'     => function () {
				return __( 'Daily (with Daily data retention)', 'opti-behavior' );
			},
			'next_run_note_cb' => function ( $has_event, $enabled ) {
				$daily = wp_next_scheduled( 'opti_behavior_heatmap_cron_daily' );
				if ( $daily ) {
					return sprintf(
						/* translators: %s: site-local date/time of the next Daily data retention run. */
						__( 'Next automatic pass: with Daily data retention on %s.', 'opti-behavior' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $daily )
					);
				}
				return __( 'Runs with Daily data retention (that event is not scheduled right now).', 'opti-behavior' );
			},
			'settings_anchor'  => '#opti-behavior-cleanup-tasks-panel',
			'run_now'          => 'cron-spawn',
		);

		// 8. Archive-page heatmap prune (1.9.5) — one-off after the page-type migration.
		$tasks[] = array(
			'id'              => 'heatmap-page-type-prune',
			'group'           => 'files',
			'title'           => __( 'Archive-page heatmap cleanup', 'opti-behavior' ),
			'description'     => __( 'Archives heatmaps of tag, category, author, date, search and paginated archive pages (pages with 100+ sessions are kept) to the restorable _orphaned archive. Runs in small background batches when "Non-Singular Pages" is "Posts & Pages Only".', 'opti-behavior' ),
			'details'         => class_exists( 'Opti_Behavior_Heatmap_Page_Type_Prune' ) ? Opti_Behavior_Heatmap_Page_Type_Prune::describe() : array(),
			'hook'            => 'opti_behavior_page_type_prune_run',
			'kind'            => 'one-off',
			'enabled_cb'      => function () {
				return class_exists( 'Opti_Behavior_Heatmap_Page_Type_Prune' ) && ! Opti_Behavior_Heatmap_Page_Type_Prune::tracks_all_pages();
			},
			'last_run_cb'     => function () {
				$state = class_exists( 'Opti_Behavior_Heatmap_Page_Type_Prune' ) ? Opti_Behavior_Heatmap_Page_Type_Prune::get_state() : array();
				return ! empty( $state['last_run'] ) ? (string) $state['last_run'] : null;
			},
			// The card lives on the Data Collection tab (another page load), so a
			// bare #fragment would find nothing on Danger Zone: link the full URL.
			'settings_anchor' => admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=data-collection' ) . '#opti-behavior-page-type-prune-card',
			'run_now'         => 'cron-spawn',
		);

		// NOTE: the Smart Insights stale-insight prune (hook
		// `opti_behavior_smart_insights_generate_daily`) is deliberately NOT
		// listed. The prune rides the daily insight GENERATION hook and is
		// configured through the Data Retention widget, so a panel row added
		// noise without an actionable control. The cron job itself is untouched
		// and keeps pruning on its own schedule.

		return $tasks;
	}

	/**
	 * Final task list: Free canonical rows + filter-appended (Pro) rows,
	 * normalized. Invalid rows (missing id/hook/title, bad run_now, duplicate
	 * id) are dropped — the run-now whitelist can never be widened by a
	 * malformed filter row.
	 *
	 * @since 1.9.x
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_tasks() {
		$tasks = apply_filters( self::TASKS_FILTER, self::get_free_tasks() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Class constant; value is the prefixed literal 'opti_behavior_cleanup_tasks'.

		$normalized = array();
		$seen_ids   = array();

		foreach ( (array) $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}

			$id   = isset( $task['id'] ) ? sanitize_key( (string) $task['id'] ) : '';
			$hook = isset( $task['hook'] ) ? (string) $task['hook'] : '';

			if ( '' === $id || '' === $hook || empty( $task['title'] ) || isset( $seen_ids[ $id ] ) ) {
				continue;
			}

			$run_now = isset( $task['run_now'] ) ? (string) $task['run_now'] : 'none';
			if ( ! in_array( $run_now, self::RUN_NOW_MODES, true ) ) {
				$run_now = 'none';
			}

			$normalized[]     = array(
				'id'              => $id,
				'group'           => isset( $task['group'] ) ? sanitize_key( (string) $task['group'] ) : 'other',
				'title'           => (string) $task['title'],
				'description'     => isset( $task['description'] ) ? (string) $task['description'] : '',
				'details'         => isset( $task['details'] ) ? array_values( array_filter( array_map( 'strval', (array) $task['details'] ), 'strlen' ) ) : array(),
				'hook'            => $hook,
				'kind'            => ( isset( $task['kind'] ) && 'one-off' === $task['kind'] ) ? 'one-off' : 'recurring',
				'enabled_cb'      => ( isset( $task['enabled_cb'] ) && is_callable( $task['enabled_cb'] ) ) ? $task['enabled_cb'] : '__return_true',
				'last_run_cb'     => ( isset( $task['last_run_cb'] ) && is_callable( $task['last_run_cb'] ) ) ? $task['last_run_cb'] : '__return_null',
				'status_cb'       => ( isset( $task['status_cb'] ) && is_callable( $task['status_cb'] ) ) ? $task['status_cb'] : null,
				'frequency_cb'    => ( isset( $task['frequency_cb'] ) && is_callable( $task['frequency_cb'] ) ) ? $task['frequency_cb'] : null,
				'next_run_note_cb' => ( isset( $task['next_run_note_cb'] ) && is_callable( $task['next_run_note_cb'] ) ) ? $task['next_run_note_cb'] : null,
				'settings_anchor' => isset( $task['settings_anchor'] ) ? (string) $task['settings_anchor'] : '',
				'run_now'         => $run_now,
				'link_label'      => isset( $task['link_label'] ) ? (string) $task['link_label'] : '',
				'log_idle_runs'   => ! isset( $task['log_idle_runs'] ) || (bool) $task['log_idle_runs'],
			);
			$seen_ids[ $id ]  = true;
		}

		return $normalized;
	}

	/**
	 * Look up one normalized task by id.
	 *
	 * @since 1.9.x
	 * @param string $task_id Task id.
	 * @return array<string,mixed>|null
	 */
	public static function get_task( $task_id ) {
		$task_id = sanitize_key( (string) $task_id );
		foreach ( self::get_tasks() as $task ) {
			if ( $task['id'] === $task_id ) {
				return $task;
			}
		}
		return null;
	}

	/**
	 * Serialized overview rows for rendering / the overview AJAX endpoint.
	 * Callables are resolved; next-run and frequency read live from the full
	 * cron-array scan. Cheap by construction: option reads + cron array only,
	 * never a filesystem scan or file read.
	 *
	 * @since 1.9.x
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_overview() {
		$rows        = array();
		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		foreach ( self::get_tasks() as $task ) {
			$schedule = self::describe_hook_schedule( $task['hook'] );
			$enabled  = (bool) call_user_func( $task['enabled_cb'] );
			$last_run = call_user_func( $task['last_run_cb'] );
			$status   = $task['status_cb'] ? call_user_func( $task['status_cb'] ) : null;

			$frequency = '';
			if ( $task['frequency_cb'] ) {
				// Descriptor-supplied truth wins: the WP recurrence can differ
				// from the cadence the admin actually configured.
				$frequency = (string) call_user_func( $task['frequency_cb'] );
			}
			if ( '' === $frequency ) {
				if ( 'one-off' === $task['kind'] ) {
					$frequency = __( 'One-off', 'opti-behavior' );
				} elseif ( ! empty( $schedule['recurrence'] ) ) {
					$schedules = wp_get_schedules();
					$frequency = isset( $schedules[ $schedule['recurrence'] ]['display'] )
						? (string) $schedules[ $schedule['recurrence'] ]['display']
						: (string) $schedule['recurrence'];
				}
			}

			$next_ts = null;
			if ( null !== $schedule['next_oneoff'] && ( null === $schedule['next_recurring'] || $schedule['next_oneoff'] < $schedule['next_recurring'] ) ) {
				$next_ts = $schedule['next_oneoff'];
			} elseif ( null !== $schedule['next_recurring'] ) {
				$next_ts = $schedule['next_recurring'];
			}

			// Honest schedule-state note. Never promises a self-heal that the
			// code cannot perform: the generic wording only states the fact.
			$has_event = ( $schedule['recurring_count'] > 0 || $schedule['oneoff_pending'] > 0 );
			$note      = null;
			if ( $task['next_run_note_cb'] ) {
				$note = call_user_func( $task['next_run_note_cb'], $has_event, $enabled );
			} elseif ( 'recurring' === $task['kind'] && ! $has_event ) {
				$note = $enabled
					? __( 'No background event is scheduled for this task right now.', 'opti-behavior' )
					: __( 'Turned off in settings — nothing is scheduled.', 'opti-behavior' );
			}

			// Every tracked run is recorded per task; the newest of that record
			// and the descriptor's own source wins (tasks without a source of
			// their own used to show "—" forever).
			$last_ts  = self::to_site_timestamp( $last_run );
			$recorded = self::get_recorded_run( $task['id'] );
			$rec_ts   = null !== $recorded ? self::to_site_timestamp( $recorded['timestamp'] ) : null;
			if ( null !== $rec_ts && ( null === $last_ts || $rec_ts > $last_ts ) ) {
				$last_ts = $rec_ts;
			}

			$rows[] = array(
				'id'               => $task['id'],
				'group'            => $task['group'],
				'title'            => $task['title'],
				'description'      => $task['description'],
				'details'          => $task['details'],
				'hook'             => $task['hook'],
				'kind'             => $task['kind'],
				'run_now'          => $task['run_now'],
				'link_label'       => $task['link_label'],
				'settings_anchor'  => $task['settings_anchor'],
				'enabled'          => $enabled,
				'frequency'        => $frequency,
				'next_run'         => $next_ts,
				'next_run_display' => null !== $next_ts ? wp_date( $date_format, $next_ts ) : '',
				'next_run_note'    => ( is_string( $note ) && '' !== $note ) ? $note : '',
				'queued'           => $schedule['oneoff_pending'] > 0,
				'last_run'         => null !== $last_ts ? (string) $last_ts : '',
				'last_run_display' => null !== $last_ts ? self::format_site_timestamp( $last_ts, $date_format ) : '',
				'status'           => null !== $status ? (string) $status : '',
			);
		}

		return $rows;
	}

	/**
	 * Coerce a last-run value to the plugin's site-local timestamp convention.
	 *
	 * Two shapes exist in the wild for the same field: an int produced by
	 * `current_time( 'timestamp' )` (site-local epoch) and a MySQL datetime
	 * string in site-local time. `strtotime()` runs under WordPress's UTC
	 * default PHP timezone, so a local datetime string parses to exactly the
	 * same site-local epoch — both shapes converge here. A wrong shape used to
	 * make the render silently print nothing (wp_date() returns false for a
	 * non-numeric timestamp).
	 *
	 * @since 1.9.x
	 * @param mixed $value Raw last-run value.
	 * @return int|null Site-local epoch, or null when there is no usable value.
	 */
	public static function to_site_timestamp( $value ) {
		if ( is_numeric( $value ) ) {
			$timestamp = (int) $value;
			return $timestamp > 0 ? $timestamp : null;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$timestamp = strtotime( trim( $value ) );

		return ( false === $timestamp || $timestamp <= 0 ) ? null : (int) $timestamp;
	}

	/**
	 * Format a site-local epoch (see to_site_timestamp()) for display.
	 *
	 * The value already carries the site's UTC offset, so it is formatted in
	 * UTC — formatting it in the site timezone would apply the offset twice.
	 *
	 * @since 1.9.x
	 * @param int         $timestamp Site-local epoch.
	 * @param string|null $format    Date format; site date+time format when null.
	 * @return string Formatted datetime ('' when unusable).
	 */
	public static function format_site_timestamp( $timestamp, $format = null ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return '';
		}

		if ( null === $format || '' === $format ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}

		$formatted = wp_date( $format, $timestamp, new DateTimeZone( 'UTC' ) );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Full cron-array scan for one hook (any args): recurring and one-off
	 * events counted separately. Never uses wp_next_scheduled() so a pending
	 * one-off can never masquerade as (or hide) the recurring event.
	 *
	 * @since 1.9.x
	 * @param string $hook Cron hook.
	 * @return array{next_recurring:int|null,recurrence:string|null,recurring_count:int,next_oneoff:int|null,oneoff_pending:int}
	 */
	public static function describe_hook_schedule( $hook ) {
		$result = array(
			'next_recurring'  => null,
			'recurrence'      => null,
			'recurring_count' => 0,
			'next_oneoff'     => null,
			'oneoff_pending'  => 0,
		);

		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();

		foreach ( (array) $crons as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) || ! isset( $hooks[ $hook ] ) ) {
				continue;
			}
			foreach ( (array) $hooks[ $hook ] as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				if ( ! empty( $event['schedule'] ) ) {
					++$result['recurring_count'];
					if ( null === $result['next_recurring'] || (int) $timestamp < $result['next_recurring'] ) {
						$result['next_recurring'] = (int) $timestamp;
						$result['recurrence']     = (string) $event['schedule'];
					}
				} else {
					++$result['oneoff_pending'];
					if ( null === $result['next_oneoff'] || (int) $timestamp < $result['next_oneoff'] ) {
						$result['next_oneoff'] = (int) $timestamp;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Queue a run-now for a whitelisted task: one immediate one-off cron event
	 * of the task's OWN hook. The handler runs with its own bounds/locks in a
	 * cron request immune to admin HTTP timeouts. NEVER executes deletion
	 * inline and NEVER introduces a new deletion path.
	 *
	 * Duplicate guard (full cron-array scan):
	 * - a pending one-off on the hook  → 'already_queued', nothing added;
	 * - recurring run < 10 min away    → 'due_soon', nothing added.
	 *
	 * @since 1.9.x
	 * @since 2026-09-13 $delay / $spawn for staggered "Run all now".
	 * @param string $task_id Task id (whitelisted against the registry).
	 * @param int    $delay   Seconds from now the one-off event is scheduled at.
	 * @param bool   $spawn   Kick wp-cron right away (the caller may batch it).
	 * @return array{success:bool,code:string,message:string,timestamp:int|null}
	 */
	public static function queue_run_now( $task_id, $delay = 0, $spawn = true ) {
		$delay = max( 0, (int) $delay );
		$task = self::get_task( $task_id );

		if ( null === $task ) {
			return array(
				'success'   => false,
				'code'      => 'invalid_task',
				'message'   => __( 'Unknown cleanup task.', 'opti-behavior' ),
				'timestamp' => null,
			);
		}

		if ( 'cron-spawn' !== $task['run_now'] ) {
			return array(
				'success'   => false,
				'code'      => 'not_runnable',
				'message'   => __( 'This task cannot be triggered manually from here.', 'opti-behavior' ),
				'timestamp' => null,
			);
		}

		// Server-side twin of the disabled "Run now" button: a task whose
		// settings make it a no-op must not be queued (the panel's "Run all"
		// already skips these rows).
		if ( ! (bool) call_user_func( $task['enabled_cb'] ) ) {
			return array(
				'success'   => false,
				'code'      => 'disabled',
				'message'   => __( 'This task is disabled by its settings — enable it (Configure) before running it.', 'opti-behavior' ),
				'timestamp' => null,
			);
		}

		$schedule = self::describe_hook_schedule( $task['hook'] );

		if ( $schedule['oneoff_pending'] > 0 ) {
			return array(
				'success'   => false,
				'code'      => 'already_queued',
				'message'   => __( 'Already queued — this task is scheduled to run shortly.', 'opti-behavior' ),
				'timestamp' => $schedule['next_oneoff'],
			);
		}

		if ( null !== $schedule['next_recurring'] && ( $schedule['next_recurring'] - time() ) < self::DUE_SOON_WINDOW ) {
			return array(
				'success'   => false,
				'code'      => 'due_soon',
				'message'   => __( 'The next scheduled run is less than 10 minutes away — no extra run was queued.', 'opti-behavior' ),
				'timestamp' => $schedule['next_recurring'],
			);
		}

		$now       = time();
		$scheduled = wp_schedule_single_event( $now + $delay, $task['hook'] );

		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			return array(
				'success'   => false,
				'code'      => 'schedule_failed',
				'message'   => __( 'Could not queue the task. Please try again.', 'opti-behavior' ),
				'timestamp' => null,
			);
		}

		// Attribute the next run of this hook to the admin's click, so the
		// history entry end_run() writes says "Manual" and replaces the marker.
		$manual = get_option( self::MANUAL_OPTION, array() );
		$manual = is_array( $manual ) ? $manual : array();
		$manual[ $task['id'] ] = $now;
		update_option( self::MANUAL_OPTION, $manual, false );

		// Cleanup History marker: manual trigger, queued. When the run
		// finishes, end_run() swaps this marker for the run's real result.
		if ( class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
			( new Opti_Behavior_Smart_Cleanup_Service() )->add_cleanup_log(
				$task['id'],
				0,
				0,
				0,
				array(
					'trigger' => 'manual',
					'status'  => 'queued',
				)
			);
		}

		// Kick the cron runner so "run now" means now, not next page view.
		// The filter exists so QA harnesses can queue without firing the
		// non-blocking wp-cron.php request (default true = spawn).
		if ( $spawn
			&& ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON )
			&& apply_filters( 'opti_behavior_cleanup_tasks_spawn_cron', true ) ) {
			spawn_cron();
		}

		return array(
			'success'   => true,
			'code'      => 'queued',
			'message'   => $delay > 0
				/* translators: %d: minutes until the queued task starts */
				? sprintf( __( 'Queued — starts in about %d min.', 'opti-behavior' ), (int) ceil( $delay / MINUTE_IN_SECONDS ) )
				: __( 'Queued — the task will run in the background within the next minute.', 'opti-behavior' ),
			'timestamp' => $now + $delay,
		);
	}

	/**
	 * Queue a run-now for every enabled, runnable task, staggered.
	 *
	 * Each task gets its own one-off event of its own hook, spaced apart so
	 * the passes (which share the heatmap sync lock and disk IO) run one after
	 * another instead of piling into one cron request. Tasks without run-now
	 * (Conditional Cleanup rules keep their preview → confirm flow) and
	 * disabled tasks are skipped; the single run-now duplicate guards apply.
	 *
	 * @since 2026-09-13
	 * @return array{success:bool,message:string,results:array<string,array>}
	 */
	public static function queue_run_all() {
		/**
		 * Seconds between tasks queued by "Run all now".
		 *
		 * @since 2026-09-13
		 * @param int $stagger Default 90 seconds.
		 */
		$stagger = max( 0, (int) apply_filters( 'opti_behavior_cleanup_tasks_run_all_stagger', 90 ) );
		$results = array();
		$queued  = 0;

		foreach ( self::get_overview() as $row ) {
			if ( 'cron-spawn' !== $row['run_now'] || empty( $row['enabled'] ) ) {
				continue;
			}

			$result                = self::queue_run_now( $row['id'], $queued * $stagger, false );
			$results[ $row['id'] ] = $result;
			if ( $result['success'] ) {
				++$queued;
			}
		}

		if ( $queued > 0
			&& ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON )
			&& apply_filters( 'opti_behavior_cleanup_tasks_spawn_cron', true ) ) {
			spawn_cron();
		}

		return array(
			'success' => $queued > 0,
			'message' => $queued > 0
				/* translators: %d: number of queued cleanup tasks */
				? sprintf( _n( '%d task queued — it runs in the background.', '%d tasks queued — they run one after another in the background over the next few minutes.', $queued, 'opti-behavior' ), $queued )
				: __( 'Nothing was queued — every task is already queued, due within 10 minutes, or turned off.', 'opti-behavior' ),
			'results' => $results,
		);
	}

	/**
	 * Attach the run tracker in contexts where task hooks actually execute
	 * (WP-Cron and WP-CLI). Admin and front-end requests never fire the
	 * cleanup hooks, so they skip building the task list.
	 *
	 * @since 1.9.x
	 * @return void
	 */
	public static function maybe_register_run_tracking() {
		$is_cron = function_exists( 'wp_doing_cron' ) ? wp_doing_cron() : ( defined( 'DOING_CRON' ) && DOING_CRON );
		if ( $is_cron || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::register_run_tracking();
		}
	}

	/**
	 * Wrap every task hook with begin/end run callbacks so EVERY execution —
	 * scheduled or "Run now" — ends in one Cleanup History entry carrying the
	 * run's stats, whatever the handler (Free or Pro) itself logs.
	 *
	 * @since 1.9.x
	 * @return void
	 */
	public static function register_run_tracking() {
		if ( self::$tracking_registered ) {
			return;
		}
		self::$tracking_registered = true;

		foreach ( self::get_tasks() as $task ) {
			$task_id = $task['id'];
			add_action(
				$task['hook'],
				function () use ( $task_id ) {
					Opti_Behavior_Cleanup_Task_Registry::begin_run( $task_id );
				},
				-99999,
				0
			);
			add_action(
				$task['hook'],
				function () use ( $task_id ) {
					Opti_Behavior_Cleanup_Task_Registry::end_run( $task_id );
				},
				99999,
				0
			);
		}
	}

	/**
	 * Open a run record for a task (runs before the task's own handlers).
	 *
	 * @since 1.9.x
	 * @param string $task_id Task id.
	 * @return void
	 */
	public static function begin_run( $task_id ) {
		self::$run_stack[] = array(
			'task_id'                   => sanitize_key( (string) $task_id ),
			'status'                    => '',
			'sessions_deleted'          => 0,
			'events_deleted'            => 0,
			'files_deleted'             => 0,
			'orphaned_visitors_deleted' => 0,
			'estimated_bytes_reclaimed' => 0,
			'rows_by_table'             => array(),
			'optimized_tables'          => array(),
			'warnings'                  => array(),
			'notes'                     => array(),
		);
	}

	/**
	 * Whether a task run is currently being tracked in this request.
	 *
	 * @since 1.9.x
	 * @return bool
	 */
	public static function is_tracking_run() {
		return ! empty( self::$run_stack ) && ! self::$writing_history;
	}

	/**
	 * Merge a handler's result into the active run. No-op (false) when no run
	 * is tracked, so handlers can report unconditionally.
	 *
	 * Accepted keys: status (skipped|completed|partial|aborted|deferred),
	 * sessions_deleted, events_deleted, files_deleted, orphaned_visitors_deleted,
	 * estimated_bytes_reclaimed, rows_by_table, optimized_tables, warnings,
	 * warning, notes, note. `deferred` means a continuation event will finish
	 * the work: nothing is logged until the final tick.
	 *
	 * @since 1.9.x
	 * @param array $result Handler result.
	 * @return bool Whether the result was captured.
	 */
	public static function report_run( $result ) {
		if ( ! self::is_tracking_run() || ! is_array( $result ) ) {
			return false;
		}

		$index = count( self::$run_stack ) - 1;
		$run   = self::$run_stack[ $index ];

		foreach ( array( 'sessions_deleted', 'events_deleted', 'files_deleted', 'orphaned_visitors_deleted', 'estimated_bytes_reclaimed' ) as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$run[ $key ] += absint( $result[ $key ] );
			}
		}

		if ( ! empty( $result['rows_by_table'] ) && is_array( $result['rows_by_table'] ) ) {
			foreach ( $result['rows_by_table'] as $table => $count ) {
				$table                         = (string) $table;
				$run['rows_by_table'][ $table ] = ( isset( $run['rows_by_table'][ $table ] ) ? $run['rows_by_table'][ $table ] : 0 ) + absint( $count );
			}
		}

		if ( ! empty( $result['optimized_tables'] ) && is_array( $result['optimized_tables'] ) ) {
			$run['optimized_tables'] = array_values( array_unique( array_merge( $run['optimized_tables'], array_map( 'strval', $result['optimized_tables'] ) ) ) );
		}

		foreach ( array( 'warnings' => 'warning', 'notes' => 'note' ) as $list_key => $single_key ) {
			if ( ! empty( $result[ $list_key ] ) && is_array( $result[ $list_key ] ) ) {
				$run[ $list_key ] = array_merge( $run[ $list_key ], array_map( 'strval', array_values( $result[ $list_key ] ) ) );
			}
			if ( ! empty( $result[ $single_key ] ) && is_string( $result[ $single_key ] ) ) {
				$run[ $list_key ][] = $result[ $single_key ];
			}
		}

		$status = isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : 'completed';
		$rank   = array_search( $status, self::RUN_STATUSES, true );
		$prev   = '' === $run['status'] ? -1 : array_search( $run['status'], self::RUN_STATUSES, true );
		if ( false !== $rank && $rank > $prev ) {
			$run['status'] = $status;
		}

		self::$run_stack[ $index ] = $run;

		return true;
	}

	/**
	 * Close the run record and write ONE Cleanup History entry for it.
	 *
	 * - deferred runs (a continuation tick follows) log nothing yet;
	 * - manual runs (queued via "Run now") are always logged and replace
	 *   their "queued" marker;
	 * - scheduled runs skip logging when skipped (not due / disabled), or
	 *   when idle and the task opted out via `log_idle_runs`.
	 *
	 * @since 1.9.x
	 * @param string $task_id Task id.
	 * @return void
	 */
	public static function end_run( $task_id ) {
		$task_id = sanitize_key( (string) $task_id );
		if ( empty( self::$run_stack ) ) {
			return;
		}

		$run = array_pop( self::$run_stack );
		if ( $run['task_id'] !== $task_id ) {
			return;
		}

		$status = '' === $run['status'] ? 'completed' : $run['status'];
		if ( 'deferred' === $status ) {
			// The task re-queued itself (lock miss, time budget). Keep the
			// manual attribution and the queued history marker for the
			// continuation, but leave a trace: the "Last run" cell shows the
			// deferral, and the queued entry carries the task's own reason
			// instead of an ageless "Waiting for the background job…".
			$pending = get_option( self::MANUAL_OPTION, array() );
			self::record_run( $task_id, 'deferred', ( is_array( $pending ) && isset( $pending[ $task_id ] ) ) ? 'manual' : 'scheduled' );
			self::annotate_queued_markers( $task_id, array_merge( $run['notes'], $run['warnings'] ) );
			return;
		}

		$manual    = get_option( self::MANUAL_OPTION, array() );
		$manual    = is_array( $manual ) ? $manual : array();
		$is_manual = isset( $manual[ $task_id ] ) && ( time() - absint( $manual[ $task_id ] ) ) <= self::MANUAL_TTL;
		$trigger   = $is_manual ? 'manual' : 'scheduled';

		if ( isset( $manual[ $task_id ] ) ) {
			unset( $manual[ $task_id ] );
			update_option( self::MANUAL_OPTION, $manual, false );
		}

		// A skip that carries a warning is a failure to surface (e.g. the
		// purge gave up on a lock held all day): record it and log it even
		// for scheduled runs. A quiet skip (settings say "nothing to do")
		// stays invisible for scheduled runs, as before.
		$loud_skip = ( 'skipped' === $status && ! empty( $run['warnings'] ) );

		if ( 'skipped' !== $status || $loud_skip ) {
			self::record_run( $task_id, $status, $trigger );
		}

		// Any finished run of the task supersedes its "queued" marker, even a
		// quiet scheduled one that logs nothing below (the manual attribution
		// may have expired): a marker left here would say "Waiting…" forever.
		self::remove_queued_markers( $task_id );

		if ( ! $is_manual ) {
			if ( 'skipped' === $status && ! $loud_skip ) {
				return;
			}
			$task = self::get_task( $task_id );
			if ( null !== $task && ! $task['log_idle_runs'] && ! self::run_did_work( $run ) ) {
				return;
			}
		}

		if ( ! class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
			return;
		}

		self::$writing_history = true;
		try {
			( new Opti_Behavior_Smart_Cleanup_Service() )->add_cleanup_log(
				$task_id,
				$run['sessions_deleted'],
				$run['events_deleted'],
				$run['files_deleted'],
				array(
					'files_deleted'             => $run['files_deleted'],
					'orphaned_visitors_deleted' => $run['orphaned_visitors_deleted'],
					'estimated_bytes_reclaimed' => $run['estimated_bytes_reclaimed'],
					'rows_by_table'             => $run['rows_by_table'],
					'optimized_tables'          => $run['optimized_tables'],
					'warnings'                  => array_values( array_unique( $run['warnings'] ) ),
					'notes'                     => array_values( array_unique( $run['notes'] ) ),
					'status'                    => $status,
					'trigger'                   => $trigger,
				)
			);
		} finally {
			self::$writing_history = false;
		}
	}

	/**
	 * Whether a run record deleted anything or raised a warning.
	 *
	 * @since 1.9.x
	 * @param array $run Run record.
	 * @return bool
	 */
	private static function run_did_work( $run ) {
		return $run['sessions_deleted'] > 0
			|| $run['events_deleted'] > 0
			|| $run['files_deleted'] > 0
			|| $run['orphaned_visitors_deleted'] > 0
			|| array_sum( $run['rows_by_table'] ) > 0
			|| ! empty( $run['warnings'] );
	}

	/**
	 * Drop the "queued" run-now markers of a task from the Cleanup History:
	 * the finished run's entry supersedes them.
	 *
	 * @since 1.9.x
	 * @param string $task_id Task id.
	 * @return void
	 */
	private static function remove_queued_markers( $task_id ) {
		$logs = get_option( 'opti_behavior_cleanup_logs', array() );
		if ( ! is_array( $logs ) || empty( $logs ) ) {
			return;
		}

		$kept = array();
		foreach ( $logs as $log ) {
			if ( is_array( $log ) && isset( $log['type'], $log['status'] ) && $task_id === $log['type'] && 'queued' === $log['status'] ) {
				continue;
			}
			$kept[] = $log;
		}

		if ( count( $kept ) !== count( $logs ) ) {
			update_option( 'opti_behavior_cleanup_logs', $kept, false );
		}
	}

	/**
	 * Persist the last run of a task (site-local mysql datetime).
	 *
	 * @since 1.9.x
	 * @param string $task_id Task id.
	 * @param string $status  Run status.
	 * @param string $trigger manual|scheduled.
	 * @return void
	 */
	/**
	 * Attach the task's own deferral reason to its queued history marker so
	 * the panel shows "Deferred: <why> — retrying…" under the entry instead
	 * of an ageless "Waiting for the background job…". The marker itself is
	 * kept (removed by the run that finally completes or skips).
	 *
	 * @since 1.9.x (Cleanup audit 2026-09-13)
	 * @param string   $task_id Task id.
	 * @param string[] $notes   Reasons reported by the task during the deferred run.
	 */
	private static function annotate_queued_markers( $task_id, $notes ) {
		$notes = array_values( array_filter( array_map( 'strval', (array) $notes ) ) );
		if ( empty( $notes ) ) {
			return;
		}
		$logs = get_option( 'opti_behavior_cleanup_logs', array() );
		if ( ! is_array( $logs ) || empty( $logs ) ) {
			return;
		}
		$changed = false;
		foreach ( $logs as $i => $log ) {
			if ( is_array( $log ) && isset( $log['type'], $log['status'] ) && $task_id === $log['type'] && 'queued' === $log['status'] ) {
				$logs[ $i ]['notes']     = $notes;
				$logs[ $i ]['timestamp'] = current_time( 'mysql' );
				$changed                 = true;
			}
		}
		if ( $changed ) {
			update_option( 'opti_behavior_cleanup_logs', $logs, false );
		}
	}

	private static function record_run( $task_id, $status, $trigger ) {
		$runs = get_option( self::RUNS_OPTION, array() );
		$runs = is_array( $runs ) ? $runs : array();

		$runs[ $task_id ] = array(
			'timestamp' => current_time( 'mysql' ),
			'status'    => $status,
			'trigger'   => $trigger,
		);

		update_option( self::RUNS_OPTION, $runs, false );
	}

	/**
	 * Last recorded run of a task.
	 *
	 * @since 1.9.x
	 * @param string $task_id Task id.
	 * @return array{timestamp:string,status:string,trigger:string}|null
	 */
	public static function get_recorded_run( $task_id ) {
		$runs = get_option( self::RUNS_OPTION, array() );
		if ( ! is_array( $runs ) || empty( $runs[ $task_id ] ) || ! is_array( $runs[ $task_id ] ) || empty( $runs[ $task_id ]['timestamp'] ) ) {
			return null;
		}

		return array(
			'timestamp' => (string) $runs[ $task_id ]['timestamp'],
			'status'    => isset( $runs[ $task_id ]['status'] ) ? (string) $runs[ $task_id ]['status'] : '',
			'trigger'   => isset( $runs[ $task_id ]['trigger'] ) ? (string) $runs[ $task_id ]['trigger'] : '',
		);
	}

	/**
	 * Human label for a Cleanup History entry type: registry task titles
	 * first, then the legacy entry types written outside the registry.
	 *
	 * @since 1.9.x
	 * @param string $type Entry type.
	 * @return string
	 */
	public static function get_history_type_label( $type ) {
		$type = (string) $type;

		foreach ( self::get_tasks() as $task ) {
			if ( $task['id'] === $type ) {
				return $task['title'];
			}
		}

		$legacy = array(
			'manual'    => __( 'Manual cleanup', 'opti-behavior' ),
			'scheduled' => __( 'Scheduled Auto-Cleanup', 'opti-behavior' ),
			'retention' => __( 'Data retention', 'opti-behavior' ),
			'auto'      => __( 'Spam/bot purge', 'opti-behavior' ),
			'danger-zone-reset' => __( 'Danger Zone data reset', 'opti-behavior' ),
		);

		return isset( $legacy[ $type ] ) ? $legacy[ $type ] : ucfirst( str_replace( array( '-', '_' ), ' ', $type ) );
	}

	/**
	 * Lightweight WP-Cron stall signal (same sentinel heuristic as
	 * Opti_Behavior_Cron_Health): the 15-minute reports worker overdue by more
	 * than twice its interval and 15 minutes absolute.
	 *
	 * @since 1.9.x
	 * @return bool
	 */
	public static function is_cron_stalled() {
		$next = wp_next_scheduled( 'opti_behavior_send_scheduled_reports' );
		if ( ! $next ) {
			return false;
		}

		$interval  = 900;
		$schedule  = wp_get_schedule( 'opti_behavior_send_scheduled_reports' );
		$schedules = wp_get_schedules();
		if ( $schedule && isset( $schedules[ $schedule ]['interval'] ) && (int) $schedules[ $schedule ]['interval'] > 0 ) {
			$interval = (int) $schedules[ $schedule ]['interval'];
		}

		$overdue_by = time() - (int) $next;

		return ( $overdue_by > ( 2 * $interval ) && $overdue_by > ( 15 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Latest cleanup-log timestamp matching a trigger or type (newest first in
	 * storage). Used as a last-run source for tasks that log through the
	 * shared Cleanup History.
	 *
	 * @since 1.9.x
	 * @param array<int,string> $triggers Trigger values to match.
	 * @param array<int,string> $types    Fallback type values to match.
	 * @return string|null Site-local mysql datetime or null.
	 */
	private static function latest_cleanup_log_timestamp( $triggers, $types ) {
		$logs = get_option( 'opti_behavior_cleanup_logs', array() );
		if ( ! is_array( $logs ) ) {
			return null;
		}

		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) || empty( $log['timestamp'] ) ) {
				continue;
			}
			$trigger = isset( $log['trigger'] ) ? (string) $log['trigger'] : '';
			$type    = isset( $log['type'] ) ? (string) $log['type'] : '';
			if ( in_array( $trigger, $triggers, true ) || in_array( $type, $types, true ) ) {
				return (string) $log['timestamp'];
			}
		}

		return null;
	}
}
