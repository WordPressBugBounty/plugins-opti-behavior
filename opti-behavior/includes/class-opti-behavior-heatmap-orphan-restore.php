<?php
/**
 * `_orphaned/` archive RESTORE service (2026-08-16 mass-deletion incident,
 * Phase C recovery tooling).
 *
 * Counterpart of {@see Opti_Behavior_Heatmap_Orphan_Purge}: where the purge
 * permanently deletes expired archive dirs, this service moves archived
 * url_hash directories BACK into the live `opti-behavior-data/` tree so the
 * Heatmaps list can recover data that past cleanup passes moved aside.
 *
 * Batched and cursor-resumable exactly like the purge:
 *  - at most {@see self::BATCH_SIZE} dirs per tick under a ~20 s budget
 *    (both filterable); cursor persisted in an option so an interrupted pass
 *    resumes where it stopped;
 *  - only dir names matching the two known archive forms are ever touched:
 *    `{32-hex}` and `{32-hex}-YmdHis` (re-archive suffix);
 *  - the whole dir is renamed back when no live dir exists (atomic, cheap);
 *    otherwise the contents are MERGED file-by-file into the live dir —
 *    existing live files always win (never overwritten), `YmdHis-` collision
 *    prefixes written by the archiver are stripped on the way back so the
 *    parsers see canonical `{ts}_..._{token}.json` names again;
 *  - runs under the shared HEATMAP_SYNC_LOCK_NAME advisory lock so it never
 *    overlaps the purge/backfill/reconcile/repair/rebuild/report passes.
 *
 * Recovery hold: while a restore pass is active (and for
 * {@see self::HOLD_SECONDS} after its last tick) the retention purge is
 * paused ({@see Opti_Behavior_Heatmap_Orphan_Purge::run_batch()} checks
 * {@see self::is_recovery_active()}), so archives cannot be permanently
 * deleted out from under an in-flight recovery.
 *
 * After a pass completes the caller (AJAX handler) is responsible for the
 * DB-side follow-up: mark restored pages aggregate-stale, flush the heatmap
 * caches, and start the Rebuild Heatmap Registry From Files pass so dirs
 * without a registry row become visible again.
 *
 * @since 2026-08-16 (mass-deletion incident recovery tooling)
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batched, cursor-resumable restore of `_orphaned/` archive directories back
 * into the live heatmap data tree.
 *
 * @since 2026-08-16
 */
class Opti_Behavior_Heatmap_Orphan_Restore {

	/**
	 * Cursor option: last fully-processed archive dir name ('' = start).
	 */
	const CURSOR_OPTION = 'opti_behavior_heatmap_orphan_restore_cursor';

	/**
	 * Progress option: pass counters + last-run info for the admin UI.
	 */
	const PROGRESS_OPTION = 'opti_behavior_heatmap_orphan_restore_progress';

	/**
	 * Recovery-hold option: unix timestamp until which the retention purge
	 * must stay paused. Refreshed on every restore tick.
	 */
	const HOLD_OPTION = 'opti_behavior_heatmap_orphan_restore_hold_until';

	/**
	 * How long (seconds) the purge stays paused after the last restore tick.
	 */
	const HOLD_SECONDS = DAY_IN_SECONDS;

	/**
	 * Default dirs per tick (filterable, clamped 1..100). Lower than the
	 * purge because merge restores do per-file renames.
	 */
	const BATCH_SIZE = 25;

	/**
	 * Default wall-time budget per tick in seconds (filterable).
	 */
	const TIME_BUDGET = 20.0;

	/**
	 * Max distinct restored url_hashes remembered in the progress option for
	 * the post-restore stale-marking step (bounded memory).
	 */
	const MAX_TRACKED_HASHES = 2000;

	/**
	 * The ONLY dir name forms the restore will ever touch:
	 * `{32-hex}` and `{32-hex}-YmdHis` (re-archive collision suffix).
	 */
	const NAME_PATTERN = '/^[a-f0-9]{32}(-\d{14})?$/';

	/**
	 * Event folders a url_hash data dir may contain.
	 *
	 * @var string[]
	 */
	const EVENT_FOLDERS = array( 'clicks', 'moves', 'scrolls' );

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
	 * Whether a recovery is active (restore running/paused recently): the
	 * retention purge must not permanently delete archives meanwhile.
	 *
	 * @return bool
	 */
	public static function is_recovery_active() {
		return (int) get_option( self::HOLD_OPTION, 0 ) > time();
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
	 * Reset the pass: clear the cursor, zero the counters, arm the recovery
	 * hold so the retention purge pauses immediately.
	 *
	 * @return void
	 */
	public function reset() {
		update_option( self::CURSOR_OPTION, '', false );
		update_option( self::HOLD_OPTION, time() + self::HOLD_SECONDS, false );
		$this->update_progress(
			array_merge(
				$this->default_progress(),
				array(
					'state'      => 'pending',
					'started_at' => current_time( 'mysql' ),
				)
			)
		);
	}

	/**
	 * Run ONE batched tick of the restore.
	 *
	 * @return array{
	 *   processed:int, dirs_restored:int, dirs_merged:int, files_restored:int,
	 *   files_skipped:int, failed:int, complete:bool, locked:bool
	 * } Tick result. `complete` is true when the whole pass finished; the AJAX
	 *   loop keeps calling while it is false and the lock did not block.
	 */
	public function run_batch() {
		$result = array(
			'processed'      => 0,
			'dirs_restored'  => 0,
			'dirs_merged'    => 0,
			'files_restored' => 0,
			'files_skipped'  => 0,
			'failed'         => 0,
			'complete'       => false,
			'locked'         => false,
		);

		// Every tick extends the recovery hold: the purge stays paused while
		// (and for HOLD_SECONDS after) the restore is making progress.
		update_option( self::HOLD_OPTION, time() + self::HOLD_SECONDS, false );

		$got_lock = $this->acquire_lock( 5 );
		if ( ! $got_lock ) {
			// Another heatmap maintenance pass is scanning; caller retries.
			$result['locked'] = true;
			return $result;
		}

		try {
			$base_dir   = $this->get_root_base_dir();
			$orphan_dir = $base_dir . '_orphaned/';
			$dirs       = $this->list_archive_dirs( $orphan_dir );
			$total      = count( $dirs );
			$cursor     = (string) get_option( self::CURSOR_OPTION, '' );

			if ( '' === $cursor ) {
				// Fresh pass: zero the pass counters, keep started_at from reset().
				$started = $this->get_progress();
				$this->update_progress(
					array_merge(
						$this->default_progress(),
						array(
							'state'      => 'running',
							'total_dirs' => $total,
							'started_at' => ! empty( $started['started_at'] ) ? $started['started_at'] : current_time( 'mysql' ),
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

			// Restored dirs disappear from the archive listing, so a resumed
			// tick's raw count($dirs) under-counts the pass. The stable pass
			// total is what earlier ticks already scanned plus what remains.
			$done_before = 0;
			if ( '' !== $cursor ) {
				$prior       = $this->get_progress();
				$done_before = max( 0, (int) $prior['scanned'] );
			}
			$total = $done_before + count( $pending );

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

			/**
			 * Filter the restore batch size (dirs per tick).
			 *
			 * @since 2026-08-16
			 * @param int $batch_size Dirs per tick.
			 */
			$batch_size = (int) apply_filters( 'opti_behavior_heatmap_orphan_restore_batch_size', self::BATCH_SIZE );
			$batch_size = max( 1, min( 100, $batch_size ) );

			/**
			 * Filter the restore tick wall-time budget in seconds.
			 *
			 * @since 2026-08-16
			 * @param float $budget Seconds.
			 */
			$budget = (float) apply_filters( 'opti_behavior_heatmap_orphan_restore_time_budget', self::TIME_BUDGET );
			$start  = microtime( true );
			$batch  = array_slice( $pending, 0, $batch_size );

			$tick = array(
				'scanned'        => 0,
				'dirs_restored'  => 0,
				'dirs_merged'    => 0,
				'files_restored' => 0,
				'files_skipped'  => 0,
				'failed'         => 0,
				'hashes'         => array(),
			);

			$last_processed = $cursor;

			foreach ( $batch as $name ) {
				if ( $tick['scanned'] > 0 && ( microtime( true ) - $start ) >= $budget ) {
					break; // Budget spent — next tick resumes from the cursor.
				}

				$last_processed = $name;
				++$tick['scanned'];
				++$result['processed'];

				$outcome = $this->restore_archive_dir( $orphan_dir, $base_dir, $name );

				if ( 'restored' === $outcome['status'] ) {
					++$tick['dirs_restored'];
					++$result['dirs_restored'];
					$tick['hashes'][ substr( $name, 0, 32 ) ] = true;
				} elseif ( 'merged' === $outcome['status'] ) {
					++$tick['dirs_merged'];
					++$result['dirs_merged'];
					$tick['files_restored'] += $outcome['files_restored'];
					$tick['files_skipped']  += $outcome['files_skipped'];
					$result['files_restored'] += $outcome['files_restored'];
					$result['files_skipped']  += $outcome['files_skipped'];
					$tick['hashes'][ substr( $name, 0, 32 ) ] = true;
				} else { // failed.
					++$tick['failed'];
					++$result['failed'];
				}
			}

			$drained = ( $tick['scanned'] >= count( $pending ) );
			update_option( self::CURSOR_OPTION, $drained ? '' : $last_processed, false );

			$progress = $this->get_progress();
			$hashes   = is_array( $progress['restored_hashes'] ) ? $progress['restored_hashes'] : array();
			foreach ( array_keys( $tick['hashes'] ) as $hash ) {
				if ( count( $hashes ) >= self::MAX_TRACKED_HASHES ) {
					break;
				}
				if ( ! in_array( $hash, $hashes, true ) ) {
					$hashes[] = $hash;
				}
			}

			$fields = array(
				'state'           => $drained ? 'complete' : 'running',
				'total_dirs'      => $total,
				'scanned'         => $progress['scanned'] + $tick['scanned'],
				'dirs_restored'   => $progress['dirs_restored'] + $tick['dirs_restored'],
				'dirs_merged'     => $progress['dirs_merged'] + $tick['dirs_merged'],
				'files_restored'  => $progress['files_restored'] + $tick['files_restored'],
				'files_skipped'   => $progress['files_skipped'] + $tick['files_skipped'],
				'failed'          => $progress['failed'] + $tick['failed'],
				'restored_hashes' => $hashes,
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
	 * Restore ONE archive dir back into the live tree.
	 *
	 * Guardrails (defense in depth):
	 *  - name must match the known archive forms ({@see self::NAME_PATTERN});
	 *  - the source must be a real directory, not a symlink, realpath-confined
	 *    directly under `_orphaned/`;
	 *  - live files are NEVER overwritten — on name collision the archived
	 *    file stays in the archive (counted as skipped);
	 *  - only the three known event folders are merged; foreign content is
	 *    left behind in the archive (never deleted).
	 *
	 * @param string $orphan_dir `_orphaned/` root (trailing slash).
	 * @param string $base_dir   Live data root (trailing slash).
	 * @param string $name       Archive dir name.
	 * @return array{status:string,files_restored:int,files_skipped:int}
	 */
	private function restore_archive_dir( $orphan_dir, $base_dir, $name ) {
		$outcome = array(
			'status'         => 'failed',
			'files_restored' => 0,
			'files_skipped'  => 0,
		);

		if ( ! preg_match( self::NAME_PATTERN, $name ) ) {
			return $outcome;
		}

		$src = $orphan_dir . $name;
		if ( is_link( $src ) || ! is_dir( $src ) ) {
			return $outcome;
		}

		$real_root = realpath( $orphan_dir );
		$real_src  = realpath( $src );
		if ( false === $real_root || false === $real_src ) {
			return $outcome;
		}
		$root_prefix = rtrim( $real_root, '/\\' ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $real_src, $root_prefix ) || basename( $real_src ) !== $name ) {
			return $outcome;
		}

		// Restore target: the canonical 32-hex hash (re-archive suffix dropped).
		$hash = substr( $name, 0, 32 );
		$dest = $base_dir . $hash;

		if ( ! is_dir( $dest ) && ! is_link( $dest ) ) {
			// Fast path: no live dir — atomic whole-dir move back.
			if ( @rename( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic same-filesystem move; failure falls through to 'failed'.
				$outcome['status'] = 'restored';
			}
			return $outcome;
		}

		// Merge path: live dir exists — move files one by one, live wins.
		foreach ( self::EVENT_FOLDERS as $folder ) {
			$src_folder = $src . '/' . $folder;
			if ( ! is_dir( $src_folder ) || is_link( $src_folder ) ) {
				continue;
			}

			$dest_folder = $dest . '/' . $folder;
			if ( ! is_dir( $dest_folder ) ) {
				wp_mkdir_p( $dest_folder );
			}

			foreach ( (array) glob( $src_folder . '/*.json' ) as $file ) {
				if ( is_link( $file ) || ! is_file( $file ) ) {
					continue;
				}

				// Strip the `YmdHis-` collision prefix the archiver may have
				// added, so the restored file carries its canonical parseable
				// name again.
				$target_name = preg_replace( '/^\d{14}-/', '', basename( $file ) );
				$target      = $dest_folder . '/' . $target_name;

				if ( file_exists( $target ) ) {
					++$outcome['files_skipped']; // Live wins; archived copy stays put.
					continue;
				}

				if ( @rename( $file, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic same-filesystem move; failure counted as skipped.
					++$outcome['files_restored'];
				} else {
					++$outcome['files_skipped'];
				}
			}

			// Drop the archive folder only when fully emptied.
			@rmdir( $src_folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fails harmlessly when leftovers remain.
		}

		// Drop the archive dir only when fully emptied (never deletes files).
		@rmdir( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fails harmlessly when leftovers remain.

		$outcome['status'] = 'merged';
		return $outcome;
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
			'state'           => 'idle',
			'total_dirs'      => 0,
			'scanned'         => 0,
			'dirs_restored'   => 0,
			'dirs_merged'     => 0,
			'files_restored'  => 0,
			'files_skipped'   => 0,
			'failed'          => 0,
			'restored_hashes' => array(),
			'started_at'      => '',
			'finished_at'     => '',
			'updated_at'      => '',
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
	 * Shared sync lock name (never overlaps purge/backfill/reconcile/repair/
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
