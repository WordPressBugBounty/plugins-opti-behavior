<?php
/**
 * Self-heal for the plugin main-file basename case flip.
 *
 * The canonical Free main file is `opti-behavior/Opti-Behavior.php` (capitalized
 * `O`/`B`). A build once shipped it lowercase (`opti-behavior/opti-behavior.php`).
 * On a case-sensitive host (Linux) the stored `active_plugins` entry then stopped
 * resolving and WordPress deactivated the plugin with
 * "Plugin file does not exist." (see .zencoder investigation, Problem #1).
 *
 * Sites that were (re)activated while the lowercase file was live carry a stale
 * lowercase basename in `active_plugins` / `active_sitewide_plugins`. When such a
 * site loads this (now-capitalized) plugin, this migration rewrites the stale
 * option value back to the canonical case so the entry keeps resolving after the
 * next update. It also clears an orphaned scheduled-reports cron event whose
 * custom schedule no longer resolves (Problem #3).
 *
 * Idempotent and cheap: the guarded option write only runs when a differently
 * cased entry is actually present.
 *
 * @package opti-behavior
 * @since   1.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Opti_Behavior_Basename_Migration' ) ) {

	/**
	 * Rewrites stale-cased plugin basename entries to the canonical filename.
	 */
	final class Opti_Behavior_Basename_Migration {

		/**
		 * Canonical Free plugin basename (exact case).
		 *
		 * @var string
		 */
		const CANONICAL_BASENAME = 'opti-behavior/Opti-Behavior.php';

		/**
		 * Scheduled-reports cron hook that can be orphaned after a bad update.
		 *
		 * @var string
		 */
		const REPORT_CRON_HOOK = 'opti_behavior_send_scheduled_reports';

		/**
		 * Register the migration on plugin load.
		 *
		 * The basename rewrites run on `plugins_loaded` priority 1. The cron
		 * heal step is deferred to `init` priority 1 (@since 1.8.1.6): it calls
		 * `wp_get_schedules()`, which fires the `cron_schedules` filter, and
		 * both plugins' closures on that filter call `__()` — translations
		 * cannot load before `init` (WP 6.7+ `_doing_it_wrong`).
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'plugins_loaded', array( __CLASS__, 'run' ), 1 );
			add_action( 'init', array( __CLASS__, 'heal_orphaned_report_cron' ), 1 );
		}

		/**
		 * Execute every heal step that is safe at `plugins_loaded`.
		 *
		 * The cron heal step is registered separately on `init` — see
		 * self::init().
		 *
		 * @return void
		 */
		public static function run() {
			self::migrate_active_plugins();
			self::migrate_active_sitewide_plugins();
		}

		/**
		 * Rewrite a stale-cased single-site `active_plugins` entry.
		 *
		 * @return bool True when the option was rewritten.
		 */
		public static function migrate_active_plugins() {
			$active = get_option( 'active_plugins' );

			if ( ! is_array( $active ) ) {
				return false;
			}

			$changed = false;

			foreach ( $active as $index => $entry ) {
				if ( is_string( $entry )
					&& $entry !== self::CANONICAL_BASENAME
					&& 0 === strcasecmp( $entry, self::CANONICAL_BASENAME )
				) {
					$active[ $index ] = self::CANONICAL_BASENAME;
					$changed          = true;
				}
			}

			if ( ! $changed ) {
				return false;
			}

			// Collapse any duplicate that a prior capitalized entry may have left.
			$active = array_values( array_unique( $active ) );

			update_option( 'active_plugins', $active );

			return true;
		}

		/**
		 * Rewrite a stale-cased multisite `active_sitewide_plugins` key.
		 *
		 * The sitewide option is an associative array keyed by basename with the
		 * activation timestamp as value, so the key itself must be renamed.
		 *
		 * @return bool True when the option was rewritten.
		 */
		public static function migrate_active_sitewide_plugins() {
			if ( ! is_multisite() ) {
				return false;
			}

			$active = get_site_option( 'active_sitewide_plugins' );

			if ( ! is_array( $active ) ) {
				return false;
			}

			$changed = false;

			foreach ( $active as $key => $timestamp ) {
				if ( is_string( $key )
					&& $key !== self::CANONICAL_BASENAME
					&& 0 === strcasecmp( $key, self::CANONICAL_BASENAME )
				) {
					if ( ! isset( $active[ self::CANONICAL_BASENAME ] ) ) {
						$active[ self::CANONICAL_BASENAME ] = $timestamp;
					}
					unset( $active[ $key ] );
					$changed = true;
				}
			}

			if ( ! $changed ) {
				return false;
			}

			update_site_option( 'active_sitewide_plugins', $active );

			return true;
		}

		/**
		 * Clear an orphaned scheduled-reports event with an unresolvable schedule.
		 *
		 * After an update that deactivated the plugin, the recurring event can
		 * persist while its custom `every_fifteen_minutes` schedule is gone,
		 * making WP-Cron log `invalid_schedule` on every spawn. Here the plugin is
		 * active again (so the schedule resolves) — only heal the truly broken
		 * case, then re-arm with the canonical interval.
		 *
		 * @return void
		 */
		public static function heal_orphaned_report_cron() {
			$next = wp_next_scheduled( self::REPORT_CRON_HOOK );

			if ( ! $next ) {
				return;
			}

			$schedule  = wp_get_schedule( self::REPORT_CRON_HOOK );
			$schedules = wp_get_schedules();

			// Schedule name still resolves — nothing to heal.
			if ( $schedule && isset( $schedules[ $schedule ] ) ) {
				return;
			}

			wp_clear_scheduled_hook( self::REPORT_CRON_HOOK );

			if ( isset( $schedules['every_fifteen_minutes'] ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'every_fifteen_minutes', self::REPORT_CRON_HOOK );
			}
		}
	}
}
