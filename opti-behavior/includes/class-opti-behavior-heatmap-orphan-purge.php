<?php
/**
 * `_orphaned/` archive retention PURGE service (unbounded-growth fix,
 * 2026-08-15 remediation follow-up).
 *
 * Background: every maintenance pass that would otherwise destroy heatmap
 * data (dedupe, QA purge, the cleanup cascade guardrail, the orphan-file
 * archival migration) ARCHIVES url_hash directories under
 * `uploads/opti-behavior-data/_orphaned/` instead of deleting them. That
 * archive has no expiry: on bot-heavy live sites it grows by thousands of
 * dirs per month forever — an inode/disk exhaustion risk on shared hosting.
 *
 * This service is the retention counterpart: a DAILY recurring, batched,
 * cursor-resumable cron pass that PERMANENTLY deletes archived dirs older
 * than N days (default {@see self::DEFAULT_RETENTION_DAYS}, configurable via
 * option + filter; 0 disables the purge entirely). Dirs younger than N days
 * are never touched — they remain restorable for the deferred `_orphaned/`
 * restore feature (Option C phase 2).
 *
 * DESTRUCTIVE, therefore heavily guarded:
 *  - only dir names matching the two known archive forms are ever considered:
 *    `{32-hex}` and `{32-hex}-YmdHis` (re-archive suffix); anything else in
 *    `_orphaned/` (index.php, .htaccess, stray dirs) is skipped;
 *  - age is resolved from the `-YmdHis` suffix when present (authoritative
 *    archive time, {@see Opti_Behavior_Heatmap_Storage::archive_orphaned_hash_dir()}
 *    writes it with gmdate) and from the dir mtime otherwise — ONE cheap
 *    stat per dir before any deeper IO; unresolvable age = never deleted;
 *  - the recursive delete is bounded (depth cap) and strictly confined to
 *    the realpath-verified `_orphaned/{valid-name}` tree; symlinks are never
 *    traversed (the link itself is removed, its target untouched).
 *
 * HARD PERFORMANCE CONSTRAINTS (sites with MILLIONS of JSON files; live
 * `_orphaned/` observed at 7,547 dirs):
 *  - at most {@see self::BATCH_SIZE} dirs per tick under a ~20 s budget
 *    (both filterable); cursor persisted in an option; the daily event's
 *    tick self-schedules 1-minute continuations while work remains;
 *  - archive listing is ONE readdir() stream of dir NAMES — never a
 *    recursive glob; the admin page renders from option reads only;
 *  - runs under the shared HEATMAP_SYNC_LOCK_NAME advisory lock so it never
 *    overlaps the backfill/reconcile/repair/rebuild/report passes.
 *
 * @since 2026-08-15 (`_orphaned/` retention purge)
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batched, cursor-resumable, daily-recurring purge of expired `_orphaned/`
 * archive directories.
 *
 * @since 2026-08-15
 */
class Opti_Behavior_Heatmap_Orphan_Purge {

	/**
	 * Cursor option: last fully-processed archive dir name ('' = start).
	 */
	const CURSOR_OPTION = 'opti_behavior_heatmap_orphan_purge_cursor';

	/**
	 * Progress option: pass counters + last-run info for the admin UI.
	 */
	const PROGRESS_OPTION = 'opti_behavior_heatmap_orphan_purge_progress';

	/**
	 * Retention setting option: days to keep archived dirs. Absent option =
	 * {@see self::DEFAULT_RETENTION_DAYS}; explicit 0 disables the purge.
	 */
	const RETENTION_OPTION = 'opti_behavior_heatmap_orphan_retention_days';

	/**
	 * Default retention window in days.
	 */
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Upper clamp for the retention setting (10 years).
	 */
	const MAX_RETENTION_DAYS = 3650;

	/**
	 * Default dirs per tick (filterable, clamped 1..200).
	 */
	const BATCH_SIZE = 100;

	/**
	 * Default wall-time budget per tick in seconds (filterable).
	 */
	const TIME_BUDGET = 20.0;

	/**
	 * Recursion cap for the bounded delete (hash/{clicks,moves,scrolls}/*.json
	 * is 2 levels; anything deeper than this is structurally foreign).
	 */
	const MAX_DELETE_DEPTH = 6;

	/**
	 * The ONLY dir name forms the purge will ever touch:
	 * `{32-hex}` and `{32-hex}-YmdHis` (re-archive collision suffix).
	 */
	const NAME_PATTERN = '/^[a-f0-9]{8,64}(-\d{14})?$/';

	/**
	 * Storage instance (base dir helper).
	 *
	 * @var Opti_Behavior_Heatmap_Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Heatmap_Storage|null $storage Optional storage (tests).
	 */
	public function __construct( $storage = null ) {
		$this->storage = $storage instanceof Opti_Behavior_Heatmap_Storage
			? $storage
			: Opti_Behavior_Heatmap_Storage::get_instance();
	}

	/**
	 * Raw retention setting for the admin UI (option/default, no filter):
	 * what the number input should display.
	 *
	 * @return int Days (0 = disabled).
	 */
	public function get_retention_days_setting() {
		$days = get_option( self::RETENTION_OPTION, null );
		if ( null === $days || '' === $days || false === $days ) {
			return self::DEFAULT_RETENTION_DAYS;
		}
		return min( self::MAX_RETENTION_DAYS, absint( $days ) );
	}

	/**
	 * Effective retention window in days (option -> filter -> clamp).
	 *
	 * @return int Days; 0 disables the purge entirely.
	 */
	public function get_retention_days() {
		$days = $this->get_retention_days_setting();

		/**
		 * Filter the `_orphaned/` archive retention window in days.
		 *
		 * @since 2026-08-15
		 * @param int $days Retention days (0 disables the purge).
		 */
		$days = (int) apply_filters( 'opti_behavior_heatmap_orphan_retention_days', $days );

		return max( 0, min( self::MAX_RETENTION_DAYS, $days ) );
	}

	/**
	 * Current progress snapshot (admin page + tests read this).
	 *
	 * @return array
	 */
	public function get_progress() {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		return array_merge( $this->default_progress(), is_array( $progress ) ? $progress : array() );
	}

	/**
	 * Run ONE batched tick of the retention purge.
	 *
	 * A fresh pass (empty cursor) resets the pass counters; continuations
	 * accumulate into them. Only its own cursor/progress options are written
	 * besides the file deletions themselves.
	 *
	 * @return array{
	 *   processed:int, deleted:int, kept:int, complete:bool,
	 *   locked:bool, disabled:bool
	 * } Tick result. `complete` is true when the whole pass finished (or the
	 *   purge is disabled); cron wiring schedules a continuation while it is
	 *   false and the lock did not block the tick.
	 */
	public function run_batch() {
		$result = array(
			'processed' => 0,
			'deleted'   => 0,
			'kept'      => 0,
			'complete'  => false,
			'locked'    => false,
			'disabled'  => false,
		);

		$retention_days = $this->get_retention_days();
		if ( $retention_days <= 0 ) {
			// Kill switch: retention 0 disables the purge entirely.
			update_option( self::CURSOR_OPTION, '', false );
			$this->update_progress(
				array(
					'state'          => 'disabled',
					'retention_days' => 0,
				)
			);
			$result['disabled'] = true;
			$result['complete'] = true;
			return $result;
		}

		// Recovery hold (2026-08-16 Phase C): while a restore-from-archive
		// pass is active (or ran within its hold window) the purge must not
		// permanently delete archives out from under the recovery. Treated
		// like a lock miss: nothing is written, the next daily tick retries.
		if ( class_exists( 'Opti_Behavior_Heatmap_Orphan_Restore' )
			&& Opti_Behavior_Heatmap_Orphan_Restore::is_recovery_active() ) {
			$result['locked'] = true;
			$result['reason'] = 'recovery_hold';
			return $result;
		}

		$got_lock = $this->acquire_lock( 5 );
		if ( ! $got_lock ) {
			// Another heatmap maintenance pass is scanning; caller retries.
			$result['locked'] = true;
			$result['reason'] = 'lock_miss';
			return $result;
		}

		try {
			$orphan_dir = $this->get_root_base_dir() . '_orphaned/';
			$dirs       = $this->list_archive_dirs( $orphan_dir );
			$total      = count( $dirs );
			$cursor     = (string) get_option( self::CURSOR_OPTION, '' );

			if ( '' === $cursor ) {
				// Fresh pass: zero the pass counters (daily recurrence).
				$this->update_progress(
					array_merge(
						$this->default_progress(),
						array(
							'state'          => 'running',
							'total_dirs'     => $total,
							'retention_days' => $retention_days,
							'started_at'     => current_time( 'mysql' ),
						)
					)
				);
			}

			// Resume after the cursor (dirs sorted; strcmp is the resume order).
			$pending = array();
			foreach ( $dirs as $name ) {
				if ( '' === $cursor || strcmp( $name, $cursor ) > 0 ) {
					$pending[] = $name;
				}
			}

			if ( empty( $pending ) ) {
				update_option( self::CURSOR_OPTION, '', false );
				$this->update_progress(
					array(
						'state'       => 'complete',
						'total_dirs'  => $total,
						'finished_at' => current_time( 'mysql' ),
					)
				);
				$result['complete'] = true;
				return $result;
			}

			$batch_size = (int) apply_filters( 'opti_behavior_heatmap_orphan_purge_batch_size', self::BATCH_SIZE );
			$batch_size = max( 1, min( 200, $batch_size ) );
			$budget     = (float) apply_filters( 'opti_behavior_heatmap_orphan_purge_time_budget', self::TIME_BUDGET );
			$start      = microtime( true );
			$batch      = array_slice( $pending, 0, $batch_size );
			$cutoff     = time() - ( $retention_days * DAY_IN_SECONDS );

			$tick = array(
				'scanned' => 0,
				'deleted' => 0,
				'kept'    => 0,
				'failed'  => 0,
			);

			$last_processed = $cursor;

			foreach ( $batch as $name ) {
				if ( $tick['scanned'] > 0 && ( microtime( true ) - $start ) >= $budget ) {
					break; // Budget spent — continuation resumes from the cursor.
				}

				$last_processed = $name;
				++$tick['scanned'];
				++$result['processed'];

				// ONE cheap age resolution (suffix parse or a single stat)
				// before any deeper IO inside the dir.
				$archived_ts = $this->resolve_archived_time( $orphan_dir, $name );

				if ( $archived_ts <= 0 ) {
					// Unresolvable age: NEVER delete (fail-safe guardrail).
					++$tick['failed'];
					++$tick['kept'];
					++$result['kept'];
					continue;
				}

				if ( $archived_ts > $cutoff ) {
					// Younger than the retention window: restore window kept.
					++$tick['kept'];
					++$result['kept'];
					continue;
				}

				if ( $this->delete_archive_dir( $orphan_dir, $name ) ) {
					++$tick['deleted'];
					++$result['deleted'];
				} else {
					++$tick['failed'];
					++$tick['kept'];
					++$result['kept'];
				}
			}

			$drained = ( $tick['scanned'] >= count( $pending ) );
			update_option( self::CURSOR_OPTION, $drained ? '' : $last_processed, false );

			$progress = $this->get_progress();
			$fields   = array(
				'state'          => $drained ? 'complete' : 'running',
				'total_dirs'     => $total,
				'retention_days' => $retention_days,
				'scanned'        => $progress['scanned'] + $tick['scanned'],
				'deleted'        => $progress['deleted'] + $tick['deleted'],
				'kept'           => $progress['kept'] + $tick['kept'],
				'failed'         => $progress['failed'] + $tick['failed'],
			);
			if ( $drained ) {
				$fields['finished_at'] = current_time( 'mysql' );
			}
			$this->update_progress( $fields );

			$result['complete'] = $drained;
		} finally {
			$this->release_lock( $got_lock );
		}

		return $result;
	}

	/**
	 * Resolve WHEN an archive dir was archived.
	 *
	 * Preference order:
	 *  1. the `-YmdHis` name suffix (written with gmdate() by
	 *     archive_orphaned_hash_dir() on re-archive collisions) — parsed as
	 *     UTC, no filesystem IO at all;
	 *  2. the dir's mtime — ONE filemtime() stat.
	 *
	 * @param string $orphan_dir `_orphaned/` root (trailing slash).
	 * @param string $name       Archive dir name.
	 * @return int Unix timestamp, or 0 when unresolvable (caller must keep).
	 */
	private function resolve_archived_time( $orphan_dir, $name ) {
		if ( preg_match( '/-(\d{14})$/', $name, $m ) ) {
			$dt = DateTime::createFromFormat( 'YmdHis', $m[1], new DateTimeZone( 'UTC' ) );
			if ( $dt instanceof DateTime && $dt->format( 'YmdHis' ) === $m[1] ) {
				return (int) $dt->getTimestamp();
			}
			return 0; // Malformed suffix: fail safe, keep the dir.
		}

		$mtime = @filemtime( $orphan_dir . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with concurrent deletion is expected.
		$mtime = ( false === $mtime ) ? 0 : (int) $mtime;

		// Files archived into an existing `{hash}/` land in `{hash}/{folder}/`,
		// which bumps that folder's mtime but not the hash dir's own. Take the
		// newest of both levels so a recent archival keeps its full window.
		if ( $mtime > 0 ) {
			foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder ) {
				$sub_mtime = @filemtime( $orphan_dir . $name . '/' . $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Folder may not exist.
				if ( false !== $sub_mtime && (int) $sub_mtime > $mtime ) {
					$mtime = (int) $sub_mtime;
				}
			}
		}

		/**
		 * Filter the resolved archive-dir mtime (tests override this because
		 * touch() cannot set directory mtimes on every platform).
		 *
		 * @since 2026-08-15
		 * @param int    $mtime Resolved mtime (0 = unresolvable).
		 * @param string $name  Archive dir name.
		 * @param string $path  Full dir path.
		 */
		return (int) apply_filters( 'opti_behavior_heatmap_orphan_purge_dir_mtime', $mtime, $name, $orphan_dir . $name );
	}

	/**
	 * Permanently delete ONE archive dir — bounded and strictly confined.
	 *
	 * Guardrails (defense in depth, every one re-checked here):
	 *  - name must match the known archive forms ({@see self::NAME_PATTERN});
	 *  - the path must be a real directory, not a symlink;
	 *  - its realpath must live directly under the realpath of `_orphaned/`
	 *    (no traversal, no mount trickery);
	 *  - recursion is depth-capped; symlinked entries are unlinked, never
	 *    followed.
	 *
	 * @param string $orphan_dir `_orphaned/` root (trailing slash).
	 * @param string $name       Archive dir name.
	 * @return bool True when the dir is fully gone.
	 */
	private function delete_archive_dir( $orphan_dir, $name ) {
		if ( ! preg_match( self::NAME_PATTERN, $name ) ) {
			return false;
		}

		$path = $orphan_dir . $name;
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			return false;
		}

		$real_root = realpath( $orphan_dir );
		$real_path = realpath( $path );
		if ( false === $real_root || false === $real_path ) {
			return false;
		}

		// Containment: the resolved dir must be DIRECTLY inside the resolved
		// `_orphaned/` root and still carry the vetted name.
		$root_prefix = rtrim( $real_root, '/\\' ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $real_path, $root_prefix ) || basename( $real_path ) !== $name ) {
			return false;
		}

		return $this->recursive_delete( $real_path, 0 );
	}

	/**
	 * Bounded recursive delete. Symlinks are unlinked (never traversed);
	 * recursion beyond {@see self::MAX_DELETE_DEPTH} aborts the deletion of
	 * that subtree (structurally foreign content — fail safe).
	 *
	 * @param string $dir   Directory (realpath-verified by the caller).
	 * @param int    $depth Current recursion depth.
	 * @return bool True when the directory is fully removed.
	 */
	private function recursive_delete( $dir, $depth ) {
		if ( $depth > self::MAX_DELETE_DEPTH ) {
			return false;
		}

		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with concurrent deletion is expected.
		if ( false === $handle ) {
			return false;
		}

		$ok = true;
		while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $path ) ) {
				// Remove the link itself; NEVER follow it.
				$ok = @unlink( $path ) && $ok; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned archive tree.
			} elseif ( is_dir( $path ) ) {
				$ok = $this->recursive_delete( $path, $depth + 1 ) && $ok;
			} else {
				$ok = @unlink( $path ) && $ok; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned archive tree.
			}
		}
		closedir( $handle );

		return $ok && @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Plugin-owned archive tree; boolean result drives recursive-delete success and WP_Filesystem is not guaranteed on cron.
	}

	/**
	 * List the archive directory NAMES under `_orphaned/`, sorted. Single
	 * readdir() stream — names only, never a recursive walk. Only the two
	 * known archive forms are accepted; symlinks are skipped outright.
	 *
	 * @param string $orphan_dir `_orphaned/` root (trailing slash).
	 * @return string[] Sorted archive dir names.
	 */
	private function list_archive_dirs( $orphan_dir ) {
		$dirs = array();

		if ( ! is_dir( $orphan_dir ) ) {
			return $dirs;
		}

		$handle = opendir( $orphan_dir );
		if ( false === $handle ) {
			return $dirs;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! preg_match( self::NAME_PATTERN, $entry ) ) {
				continue;
			}
			$path = $orphan_dir . $entry;
			if ( ! is_link( $path ) && is_dir( $path ) ) {
				$dirs[] = $entry;
			}
		}
		closedir( $handle );

		sort( $dirs, SORT_STRING );
		return $dirs;
	}

	/**
	 * Live data root (the parent of `_orphaned/`). Reuses the rebuild's
	 * base-dir filter so test fixtures point ALL the scanners at the same
	 * temp root with a single filter.
	 *
	 * @return string Trailing-slashed base dir.
	 */
	private function get_root_base_dir() {
		$base = trailingslashit( $this->storage->get_base_dir() );
		return trailingslashit( (string) apply_filters( 'opti_behavior_heatmap_rebuild_base_dir', $base ) );
	}

	/**
	 * Default progress structure.
	 *
	 * @return array
	 */
	private function default_progress() {
		return array(
			'state'          => 'idle',
			'total_dirs'     => 0,
			'retention_days' => 0,
			'scanned'        => 0,
			'deleted'        => 0,
			'kept'           => 0,
			'failed'         => 0,
			'started_at'     => '',
			'finished_at'    => '',
			'updated_at'     => '',
		);
	}

	/**
	 * Merge fields into the persisted progress option.
	 *
	 * @param array $fields Fields to merge.
	 * @return void
	 */
	private function update_progress( $fields ) {
		$progress               = $this->get_progress();
		$progress               = array_merge( $progress, $fields );
		$progress['updated_at'] = current_time( 'mysql' );
		update_option( self::PROGRESS_OPTION, $progress, false );
	}

	/**
	 * Acquire the shared heatmap sync advisory lock (GET_LOCK is
	 * connection-scoped: auto-released when PHP dies, can never wedge).
	 *
	 * @param int $timeout Seconds to wait for the current holder.
	 * @return bool
	 */
	private function acquire_lock( $timeout = 5 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock; no table access.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name(), max( 0, (int) $timeout ) ) );
		return '1' === (string) $got;
	}

	/**
	 * Release the advisory lock.
	 *
	 * @param bool $got_lock Whether the lock was acquired.
	 * @return void
	 */
	private function release_lock( $got_lock ) {
		if ( ! $got_lock ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock; no table access.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) );
	}

	/**
	 * Shared sync lock name (never overlaps backfill/reconcile/repair/
	 * rebuild/report passes).
	 *
	 * @return string
	 */
	private function lock_name() {
		return class_exists( 'Opti_Behavior_Heatmap_Dashboard' )
			? Opti_Behavior_Heatmap_Dashboard::HEATMAP_SYNC_LOCK_NAME
			: 'opti_behavior_heatmap_sync_reconcile';
	}
}
