<?php
/**
 * Unified Data Retention Policy
 *
 * Single source of truth for the master raw-data retention window
 * (Unified Retention Protocol, 2026-08-15 user decision):
 *
 * - ONE master raw-data retention setting (days, default 365) drives ALL
 *   raw/per-session data: sessions, pageviews, events JSON files, recordings
 *   (DB rows + files), heatmap raw clicks/files, raw errors, A/B
 *   impressions/conversions, funnel tracking, form interactions, journeys.
 * - Files never age out on their own separate clock — they are removed in the
 *   SAME session cascade as their DB rows (Smart Cleanup service).
 * - Aggregates (`optibehavior_daily_stats`, `optibehavior_daily_dimension_stats`,
 *   `optibehavior_heatmap_daily`, `optibehavior_ab_daily_stats`, funnel stats)
 *   are exempt and kept indefinitely so dashboard history survives.
 * - The legacy independent clocks are retired/absorbed:
 *   - `opti_behavior_heatmap_option['period']` (months) — migrated once into
 *     the master day-based setting.
 *   - `opti_behavior_ab_settings['data_retention_days']` — A/B raw cleanup now
 *     reads the master setting.
 *   - `opti_behavior_file_storage_settings['retention_days']` — file cleanup
 *     now reads the master setting (storage-cap eviction is unchanged).
 *
 * Tiered Retention (2026-08-15, second round user decisions) layers a 3-tier
 * model on top of the unified master clock:
 *
 * 1. Spam/bot tier — raw spam/bot/automated sessions purged DAILY (default ON,
 *    toggleable) via the existing session cascade (bounded batches).
 * 2. Heavy-files tier — JSON files only (heatmap raw files, recording/event
 *    files), default 90 days. Heatmap files are ARCHIVED to `_orphaned/`
 *    (Option B guardrail — never immediate hard-delete); recording/event files
 *    are removed by the Pro archiver, whose DB rows are stubbed gracefully.
 *    Safety rule enforced here: files tier ≤ detailed-DB tier (validated on
 *    save; 0 = follow the detailed-DB tier, never earlier expiry).
 * 3. Detailed-DB tier — the master raw retention (default 365 days): the full
 *    session cascade including any remaining files.
 * 4. Dashboard aggregates — kept FOREVER by default (optional advanced cap in
 *    months, 0 = forever).
 *
 * @package opti-behavior
 * @since   1.9.0 (Unified Retention Protocol)
 * @since   1.9.1 (Tiered Retention)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Master retention policy accessor.
 *
 * @since 1.9.0
 */
class Opti_Behavior_Retention_Policy {

	/**
	 * Option storing the unified retention settings array.
	 */
	const OPTION = 'opti_behavior_data_retention';

	/**
	 * Master raw-data retention default: 365 days (1 year).
	 */
	const DEFAULT_RAW_RETENTION_DAYS = 365;

	/**
	 * Resolved/dismissed Smart Insights are pruned after this many months.
	 */
	const DEFAULT_INSIGHTS_PRUNE_MONTHS = 12;

	/**
	 * Upper bound accepted for the raw retention setting (10 years).
	 */
	const MAX_RAW_RETENTION_DAYS = 3650;

	/**
	 * Heavy-files tier default: 90 days (JSON files only).
	 */
	const DEFAULT_FILES_RETENTION_DAYS = 90;

	/**
	 * Dashboard aggregates default: 0 = kept forever (user decision).
	 */
	const DEFAULT_AGGREGATES_RETENTION_MONTHS = 0;

	/**
	 * Upper bound for the optional aggregates cap (10 years).
	 */
	const MAX_AGGREGATES_RETENTION_MONTHS = 120;

	/**
	 * DB size caps (1.9.3): 0 = off. Enforced by Opti_Behavior_DB_Size_Cap by
	 * aging out the OLDEST raw data first, never dashboard summaries.
	 */
	const DEFAULT_DB_MAX_MB    = 0;
	const DEFAULT_TABLE_MAX_MB = 0;
	const MAX_CAP_MB           = 1000000;

	/**
	 * Return the stored retention settings, seeding + migrating on first read.
	 *
	 * Migration: installs that had the legacy month-based
	 * `opti_behavior_heatmap_option['period']` retention configured are
	 * converted once (months × 30 days) so their effective retention window
	 * does not silently change when the unified setting takes over.
	 *
	 * @return array { raw_retention_days: int, insights_prune_months: int }
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION, false );

		if ( ! is_array( $settings ) || ! isset( $settings['raw_retention_days'] ) ) {
			$seed_days = self::DEFAULT_RAW_RETENTION_DAYS;

			// One-shot legacy migration: absorb the month-based clock.
			$legacy = maybe_unserialize( get_option( 'opti_behavior_heatmap_option' ) );
			if ( is_array( $legacy ) && ! empty( $legacy['period'] ) && absint( $legacy['period'] ) > 0 ) {
				$seed_days = min( self::MAX_RAW_RETENTION_DAYS, absint( $legacy['period'] ) * 30 );
			}

			$settings = array(
				'raw_retention_days'          => $seed_days,
				'insights_prune_months'       => self::DEFAULT_INSIGHTS_PRUNE_MONTHS,
				'spam_daily_enabled'          => true,
				'files_retention_days'        => self::DEFAULT_FILES_RETENTION_DAYS,
				'aggregates_retention_months' => self::DEFAULT_AGGREGATES_RETENTION_MONTHS,
				'db_max_mb'                   => self::DEFAULT_DB_MAX_MB,
				'table_max_mb'                => self::DEFAULT_TABLE_MAX_MB,
			);
			$settings = self::validate_tiers( $settings );
			update_option( self::OPTION, $settings, false );

			return $settings;
		}

		return self::validate_tiers(
			wp_parse_args(
				$settings,
				array(
					'raw_retention_days'          => self::DEFAULT_RAW_RETENTION_DAYS,
					'insights_prune_months'       => self::DEFAULT_INSIGHTS_PRUNE_MONTHS,
					'spam_daily_enabled'          => true,
					'files_retention_days'        => self::DEFAULT_FILES_RETENTION_DAYS,
					'aggregates_retention_months' => self::DEFAULT_AGGREGATES_RETENTION_MONTHS,
				)
			)
		);
	}

	/**
	 * Sanitize + enforce the tier safety rules on a settings array.
	 *
	 * Safety rule (user decision, enforced in code): the heavy-files tier can
	 * never OUTLIVE the detailed-DB tier — files die with (or before) their DB
	 * rows. When both tiers are active (> 0) and files > raw, files is clamped
	 * down to raw. files = 0 means "no earlier expiry" (files follow the DB
	 * cascade), which is always safe.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @param array $settings Raw settings array.
	 * @return array Sanitized settings.
	 */
	public static function validate_tiers( $settings ) {
		$settings = (array) $settings;

		$raw   = min( self::MAX_RAW_RETENTION_DAYS, absint( isset( $settings['raw_retention_days'] ) ? $settings['raw_retention_days'] : self::DEFAULT_RAW_RETENTION_DAYS ) );
		$files = min( self::MAX_RAW_RETENTION_DAYS, absint( isset( $settings['files_retention_days'] ) ? $settings['files_retention_days'] : self::DEFAULT_FILES_RETENTION_DAYS ) );

		// files ≤ raw when both tiers are active. raw = 0 (keep DB forever)
		// leaves the files tier free-standing.
		if ( $raw > 0 && $files > $raw ) {
			$files = $raw;
		}

		$settings['raw_retention_days']          = $raw;
		$settings['files_retention_days']        = $files;
		$settings['spam_daily_enabled']          = ! empty( $settings['spam_daily_enabled'] );
		$settings['insights_prune_months']       = min( 120, absint( isset( $settings['insights_prune_months'] ) ? $settings['insights_prune_months'] : self::DEFAULT_INSIGHTS_PRUNE_MONTHS ) );
		$settings['aggregates_retention_months'] = min( self::MAX_AGGREGATES_RETENTION_MONTHS, absint( isset( $settings['aggregates_retention_months'] ) ? $settings['aggregates_retention_months'] : self::DEFAULT_AGGREGATES_RETENTION_MONTHS ) );
		$settings['db_max_mb']                   = min( self::MAX_CAP_MB, absint( isset( $settings['db_max_mb'] ) ? $settings['db_max_mb'] : self::DEFAULT_DB_MAX_MB ) );
		$settings['table_max_mb']                = min( self::MAX_CAP_MB, absint( isset( $settings['table_max_mb'] ) ? $settings['table_max_mb'] : self::DEFAULT_TABLE_MAX_MB ) );

		return $settings;
	}

	/**
	 * Persist several tier fields at once, running tier validation.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @param array $fields Any of: raw_retention_days, files_retention_days,
	 *                      spam_daily_enabled, insights_prune_months,
	 *                      aggregates_retention_months.
	 * @return array The stored (sanitized) settings.
	 */
	public static function update_settings( $fields ) {
		$settings = array_merge( self::get_settings(), (array) $fields );
		$settings = self::validate_tiers( $settings );
		update_option( self::OPTION, $settings, false );

		return $settings;
	}

	/**
	 * Master raw-data retention window in days. 0 = retention disabled.
	 *
	 * @return int
	 */
	public static function get_raw_retention_days() {
		$settings = self::get_settings();
		$days     = absint( $settings['raw_retention_days'] );

		return min( self::MAX_RAW_RETENTION_DAYS, $days );
	}

	/**
	 * Persist a new master raw-retention value.
	 *
	 * @param int $days Days to keep raw data; 0 disables age-based deletion.
	 * @return int Sanitized stored value.
	 */
	public static function set_raw_retention_days( $days ) {
		// Tier validation: lowering the detailed-DB tier re-clamps the
		// heavy-files tier so files ≤ raw always holds after this save.
		$settings = self::update_settings( array( 'raw_retention_days' => absint( $days ) ) );

		return $settings['raw_retention_days'];
	}

	/**
	 * MySQL datetime cutoff for the master retention window.
	 *
	 * @return string|null Cutoff (UTC, Y-m-d H:i:s), or null when disabled.
	 */
	public static function get_raw_cutoff() {
		$days = self::get_raw_retention_days();
		if ( $days < 1 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Whether the spam/bot tier purges raw spam sessions daily.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @return bool
	 */
	public static function is_spam_daily_enabled() {
		$settings = self::get_settings();

		return ! empty( $settings['spam_daily_enabled'] );
	}

	/**
	 * Heavy-files tier window in days as STORED. 0 = no earlier expiry
	 * (files follow the detailed-DB cascade).
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @return int
	 */
	public static function get_files_retention_days() {
		$settings = self::get_settings();

		return absint( $settings['files_retention_days'] );
	}

	/**
	 * EFFECTIVE heavy-files window in days: the stored files tier when set,
	 * otherwise the detailed-DB tier (files never outlive their DB rows).
	 * 0 = files are never age-expired (both tiers disabled).
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @return int
	 */
	public static function get_effective_files_retention_days() {
		$files = self::get_files_retention_days();
		if ( $files > 0 ) {
			return $files;
		}

		return self::get_raw_retention_days();
	}

	/**
	 * MySQL datetime cutoff for the heavy-files tier (effective window).
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @return string|null Cutoff (UTC, Y-m-d H:i:s), or null when disabled.
	 */
	public static function get_files_cutoff() {
		$days = self::get_effective_files_retention_days();
		if ( $days < 1 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Persist the heavy-files tier window (days). Validated: files ≤ raw.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @param int $days Days; 0 = follow the detailed-DB tier.
	 * @return int Stored (possibly clamped) value.
	 */
	public static function set_files_retention_days( $days ) {
		$settings = self::update_settings( array( 'files_retention_days' => absint( $days ) ) );

		return $settings['files_retention_days'];
	}

	/**
	 * Persist the spam/bot daily purge toggle.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @param bool $enabled Whether daily spam purge is on.
	 * @return bool Stored value.
	 */
	public static function set_spam_daily_enabled( $enabled ) {
		$settings = self::update_settings( array( 'spam_daily_enabled' => (bool) $enabled ) );

		return ! empty( $settings['spam_daily_enabled'] );
	}

	/**
	 * Optional dashboard-aggregates cap in months. 0 = kept forever (default,
	 * per explicit user decision).
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @return int
	 */
	/**
	 * Whole-plugin DB cap in MB (all optibehavior tables, data + indexes). 0 = off.
	 *
	 * @return int
	 */
	public static function get_db_max_mb() {
		$settings = self::get_settings();
		return isset( $settings['db_max_mb'] ) ? absint( $settings['db_max_mb'] ) : 0;
	}

	/**
	 * Per-table cap in MB applied to every raw (sweepable) table. 0 = off.
	 * Override a single table with the `opti_behavior_table_max_mb` filter.
	 *
	 * @return int
	 */
	public static function get_table_max_mb() {
		$settings = self::get_settings();
		return isset( $settings['table_max_mb'] ) ? absint( $settings['table_max_mb'] ) : 0;
	}

	public static function get_aggregates_retention_months() {
		$settings = self::get_settings();

		return absint( $settings['aggregates_retention_months'] );
	}

	/**
	 * Persist the optional aggregates cap (months, 0 = forever).
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @param int $months Months; 0 keeps aggregates forever.
	 * @return int Stored value.
	 */
	public static function set_aggregates_retention_months( $months ) {
		$settings = self::update_settings( array( 'aggregates_retention_months' => absint( $months ) ) );

		return $settings['aggregates_retention_months'];
	}

	/**
	 * Months after which resolved/dismissed Smart Insights are pruned.
	 *
	 * @return int 0 = pruning disabled.
	 */
	public static function get_insights_prune_months() {
		$settings = self::get_settings();

		return absint( $settings['insights_prune_months'] );
	}

	/**
	 * Persist the insights prune window (months).
	 *
	 * @param int $months Months; 0 disables pruning.
	 * @return int Stored value.
	 */
	public static function set_insights_prune_months( $months ) {
		$months   = min( 120, absint( $months ) );
		$settings = self::get_settings();

		$settings['insights_prune_months'] = $months;
		update_option( self::OPTION, $settings, false );

		return $months;
	}
}

if ( ! function_exists( 'opti_behavior_get_raw_retention_days' ) ) {
	/**
	 * Global helper so the Pro plugin (file archiver) can read the master
	 * retention window without hard-depending on the class name.
	 *
	 * @return int Days; 0 = retention disabled.
	 */
	function opti_behavior_get_raw_retention_days() {
		return Opti_Behavior_Retention_Policy::get_raw_retention_days();
	}
}

if ( ! function_exists( 'opti_behavior_get_files_retention_days' ) ) {
	/**
	 * Global helper so the Pro plugin (file archiver) can read the EFFECTIVE
	 * heavy-files tier window without hard-depending on the class name.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @return int Days; 0 = files never age-expired.
	 */
	function opti_behavior_get_files_retention_days() {
		return Opti_Behavior_Retention_Policy::get_effective_files_retention_days();
	}
}
