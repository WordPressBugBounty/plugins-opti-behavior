<?php
/**
 * `_orphaned/` archive dry-run REPORT service (Option C phase 1, 2026-08-15
 * remediation).
 *
 * Background: maintenance passes (dedupe, QA purge, the 2026-08-13 orphan-file
 * archival migration and the archive-not-delete cleanup guardrail) move
 * heatmap url_hash directories/files under `uploads/opti-behavior-data/
 * _orphaned/` instead of deleting them. On sites hit by the historical
 * retention bug many of those archives are LEGITIMATE sessions whose DB rows
 * were wiped — potentially recoverable. No manifest records WHY a dir was
 * archived, so before any restore is even offered the admin needs a
 * REPORT-ONLY inventory of what the archive holds.
 *
 * This service scans `_orphaned/` in batched, cursor-resumable ticks and
 * produces a report:
 *  - total archived dirs / scanned so far;
 *  - dirs ELIGIBLE FOR RESTORE: their url_hash has NO surviving root data dir
 *    (restoring cannot collide with live data) AND their files parse to a
 *    valid page URL;
 *  - per-eligible-dir URL, distinct session count and unknown-token share
 *    (sessions the DB has no trace of — the retention-bug signature);
 *  - aggregate session/unknown counts and a bounded sample of eligible URLs
 *    for the admin UI.
 *
 * STRICTLY REPORT-ONLY: the pass NEVER writes to the registry, pages or
 * sessions tables and NEVER moves, deletes or restores any file. Its only
 * writes are its own cursor/progress options. The restore action is a
 * deliberately separate, deferred, opt-in follow-up.
 *
 * HARD PERFORMANCE CONSTRAINTS (mirrors the registry rebuild — sites with
 * MILLIONS of JSON files; live `_orphaned/` observed at 7,547 dirs):
 *  - at most {@see self::BATCH_SIZE} dirs per tick under a ~20 s budget (both
 *    filterable); cursor persisted in an option;
 *  - archive listing is ONE readdir() stream of dir NAMES (never a recursive
 *    glob); per-dir costs are 3 folder globs + filename parsing + AT MOST ONE
 *    json_decode (newest file, for the URL), size-guarded;
 *  - session verdicts reuse the rebuild service's chunked, memoized resolver
 *    ({@see Opti_Behavior_Heatmap_Registry_Rebuild::resolve_session_verdicts()})
 *    — never per-file queries;
 *  - runs under the shared HEATMAP_SYNC_LOCK_NAME advisory lock so it never
 *    overlaps the backfill/reconcile/repair/rebuild passes.
 *
 * @since 2026-08-15 (`_orphaned/` dry-run report, Option C phase 1)
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batched, cursor-resumable, report-only scanner over the `_orphaned/` archive.
 *
 * @since 2026-08-15
 */
class Opti_Behavior_Heatmap_Orphan_Report {

	/**
	 * Cursor option: last fully-processed archive dir name ('' = start).
	 */
	const CURSOR_OPTION = 'opti_behavior_heatmap_orphan_report_cursor';

	/**
	 * Progress option: cumulative counters + report data for the admin UI.
	 */
	const PROGRESS_OPTION = 'opti_behavior_heatmap_orphan_report_progress';

	/**
	 * Control option: '' (run) | 'pause' | 'abort' — kill switch.
	 */
	const CONTROL_OPTION = 'opti_behavior_heatmap_orphan_report_control';

	/**
	 * Default dirs per tick (filterable, clamped 1..200).
	 */
	const BATCH_SIZE = 100;

	/**
	 * Default wall-time budget per tick in seconds (filterable).
	 */
	const TIME_BUDGET = 20.0;

	/**
	 * Default cap on the eligible-URL sample kept for the UI (filterable).
	 */
	const SAMPLE_LIMIT = 20;

	/**
	 * Storage instance (base dir / filename token helpers).
	 *
	 * @var Opti_Behavior_Heatmap_Storage
	 */
	private $storage;

	/**
	 * Rebuild service reused ONLY for its memoized session-verdict resolver
	 * (token => allowed | spam | unknown). Never asked to insert anything.
	 *
	 * @var Opti_Behavior_Heatmap_Registry_Rebuild|null
	 */
	private $verdict_resolver;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Heatmap_Storage|null $storage Optional storage (tests).
	 */
	public function __construct( $storage = null ) {
		$this->storage = $storage instanceof Opti_Behavior_Heatmap_Storage
			? $storage
			: Opti_Behavior_Heatmap_Storage::get_instance();

		$this->verdict_resolver = class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' )
			? new Opti_Behavior_Heatmap_Registry_Rebuild( $this->storage )
			: null;
	}

	/**
	 * Reset cursor + progress and clear the control flag so a fresh dry-run
	 * pass starts from the first archived directory (admin "start" action).
	 *
	 * @return void
	 */
	public function reset() {
		update_option( self::CURSOR_OPTION, '', false );
		update_option( self::CONTROL_OPTION, '', false );
		update_option(
			self::PROGRESS_OPTION,
			array_merge(
				$this->default_progress(),
				array(
					'state'      => 'pending',
					'started_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				)
			),
			false
		);
	}

	/**
	 * Current progress/report snapshot (admin poll endpoint reads this).
	 *
	 * @return array
	 */
	public function get_progress() {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		return array_merge( $this->default_progress(), is_array( $progress ) ? $progress : array() );
	}

	/**
	 * Run ONE batched, report-only tick of the `_orphaned/` scan.
	 *
	 * ZERO data writes: the only persistence is this service's own
	 * cursor/progress options. Files are never moved; no registry/pages/
	 * sessions row is ever touched.
	 *
	 * @return array{
	 *   processed:int, eligible:int, complete:bool,
	 *   locked:bool, paused:bool, aborted:bool
	 * } Tick result. `complete` is true when the whole scan finished (or the
	 *   pass was aborted); cron wiring schedules a continuation while it is
	 *   false and neither locked nor paused blocked the tick.
	 */
	public function run_batch() {
		$result = array(
			'processed' => 0,
			'eligible'  => 0,
			'complete'  => false,
			'locked'    => false,
			'paused'    => false,
			'aborted'   => false,
		);

		// Kill switch: pause blocks work but keeps the cursor; abort ends the
		// pass and resets the cursor so a later start begins fresh.
		$control = (string) get_option( self::CONTROL_OPTION, '' );
		if ( 'pause' === $control ) {
			$result['paused'] = true;
			return $result;
		}
		if ( 'abort' === $control ) {
			update_option( self::CURSOR_OPTION, '', false );
			$this->update_progress( array( 'state' => 'aborted' ) );
			$result['aborted']  = true;
			$result['complete'] = true;
			return $result;
		}

		$got_lock = $this->acquire_lock( 5 );
		if ( ! $got_lock ) {
			// Another heatmap maintenance pass is scanning; caller retries.
			$result['locked'] = true;
			return $result;
		}

		try {
			$root_dir   = $this->get_root_base_dir();
			$orphan_dir = $root_dir . '_orphaned/';
			$dirs       = $this->list_archive_dirs( $orphan_dir );
			$total      = count( $dirs );
			$cursor     = (string) get_option( self::CURSOR_OPTION, '' );

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
						'state'      => 'complete',
						'total_dirs' => $total,
					)
				);
				$result['complete'] = true;
				return $result;
			}

			$batch_size = (int) apply_filters( 'opti_behavior_heatmap_orphan_report_batch_size', self::BATCH_SIZE );
			$batch_size = max( 1, min( 200, $batch_size ) );
			$budget     = (float) apply_filters( 'opti_behavior_heatmap_orphan_report_time_budget', self::TIME_BUDGET );
			$start      = microtime( true );
			$batch      = array_slice( $pending, 0, $batch_size );

			$tick = array(
				'scanned'           => 0,
				'eligible'          => 0,
				'skipped_surviving' => 0,
				'skipped_invalid'   => 0,
				'sessions_total'    => 0,
				'sessions_unknown'  => 0,
				'sample'            => array(),
			);

			$last_processed = $cursor;

			foreach ( $batch as $name ) {
				if ( $tick['scanned'] > 0 && ( microtime( true ) - $start ) >= $budget ) {
					break; // Budget spent — continuation resumes from the cursor.
				}

				$last_processed = $name;
				++$tick['scanned'];
				++$result['processed'];

				$entry = $this->inspect_archive_dir( $root_dir, $orphan_dir, $name );

				if ( 'surviving' === $entry['status'] ) {
					++$tick['skipped_surviving'];
					continue;
				}
				if ( 'invalid' === $entry['status'] ) {
					++$tick['skipped_invalid'];
					continue;
				}

				// Eligible for restore (report only — nothing is restored).
				++$tick['eligible'];
				++$result['eligible'];
				$tick['sessions_total']   += $entry['sessions'];
				$tick['sessions_unknown'] += $entry['unknown'];
				$tick['sample'][]          = array(
					'dir'      => $name,
					'url'      => $entry['url'],
					'sessions' => $entry['sessions'],
					'unknown'  => $entry['unknown'],
				);
			}

			$drained = ( $tick['scanned'] >= count( $pending ) );
			update_option( self::CURSOR_OPTION, $drained ? '' : $last_processed, false );

			$progress     = $this->get_progress();
			$sample_limit = max( 1, (int) apply_filters( 'opti_behavior_heatmap_orphan_report_sample_limit', self::SAMPLE_LIMIT ) );
			$sample       = array_slice(
				array_merge( is_array( $progress['sample'] ) ? $progress['sample'] : array(), $tick['sample'] ),
				0,
				$sample_limit
			);

			$this->update_progress(
				array(
					'state'             => $drained ? 'complete' : 'running',
					'total_dirs'        => $total,
					'scanned'           => $progress['scanned'] + $tick['scanned'],
					'eligible'          => $progress['eligible'] + $tick['eligible'],
					'skipped_surviving' => $progress['skipped_surviving'] + $tick['skipped_surviving'],
					'skipped_invalid'   => $progress['skipped_invalid'] + $tick['skipped_invalid'],
					'sessions_total'    => $progress['sessions_total'] + $tick['sessions_total'],
					'sessions_unknown'  => $progress['sessions_unknown'] + $tick['sessions_unknown'],
					'sample'            => $sample,
				)
			);

			$result['complete'] = $drained;
		} finally {
			$this->release_lock( $got_lock );
		}

		return $result;
	}

	/**
	 * Inspect ONE archived directory and classify it.
	 *
	 * Eligibility (restore could be offered safely, per spec Option C):
	 *  - its url_hash has NO surviving root data dir (a live dir means the
	 *    page kept/regained real data — restoring would collide), AND
	 *  - its files parse to a valid page URL (>=1 parseable heatmap filename
	 *    plus a decodable newest file carrying a non-empty `url`).
	 *
	 * Per-dir cost: one is_dir() on the root, 3 folder globs, filename
	 * parsing, ONE json_decode (newest file) — mirrors the rebuild scanner.
	 *
	 * @param string $root_dir   Live data root (trailing slash).
	 * @param string $orphan_dir `_orphaned/` root (trailing slash).
	 * @param string $name       Archive dir name: {32-hex}[ -YmdHis ].
	 * @return array{status:string,url:string,sessions:int,unknown:int}
	 *   status: eligible | surviving | invalid.
	 */
	private function inspect_archive_dir( $root_dir, $orphan_dir, $name ) {
		$entry = array(
			'status'   => 'invalid',
			'url'      => '',
			'sessions' => 0,
			'unknown'  => 0,
		);

		// Archive names are {url_hash} or {url_hash}-{YmdHis} (re-archive
		// collision suffix, see archive_orphaned_hash_dir()).
		$url_hash = substr( $name, 0, 32 );

		// 1. Surviving-root-dir exclusion: cheapest check first, no IO inside
		// the archive dir at all when the live dir still exists.
		if ( is_dir( $root_dir . $url_hash ) ) {
			$entry['status'] = 'surviving';
			return $entry;
		}

		// 2. Parse filenames (counts/tokens are filename-encoded; no reads).
		$files = array();
		foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder ) {
			$dir = $orphan_dir . $name . '/' . $folder;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
				$parts = explode( '_', str_replace( '.json', '', basename( $file ) ) );
				if ( count( $parts ) < 6 ) {
					continue;
				}
				$files[] = array(
					'path'    => $file,
					'ts'      => (int) $parts[0],
					'session' => (string) Opti_Behavior_Heatmap_Storage::parse_session_token( $parts ),
					'name'    => basename( $file ),
				);
			}
		}

		if ( empty( $files ) ) {
			return $entry; // No parseable heatmap files — invalid.
		}

		// 3. Distinct sessions + newest file (single metadata decode target).
		$tokens      = array();
		$newest      = null;
		$newest_name = '';
		foreach ( $files as $f ) {
			if ( '' !== $f['session'] ) {
				$tokens[ $f['session'] ] = true;
			}
			if ( null === $newest || $f['ts'] > $newest['ts']
				|| ( $f['ts'] === $newest['ts'] && strcmp( $f['name'], $newest_name ) > 0 ) ) {
				$newest      = $f;
				$newest_name = $f['name'];
			}
		}

		$meta = $this->decode_file_url( $newest['path'] );
		if ( '' === $meta ) {
			return $entry; // URL unrecoverable — invalid (restore pointless).
		}

		// 4. Unknown-token share via the rebuild's memoized batched resolver:
		// unknown = the DB has no trace of the session (retention-bug case).
		$verdicts = $this->resolve_verdicts( array_keys( $tokens ) );
		$unknown  = 0;
		foreach ( array_keys( $tokens ) as $token ) {
			if ( ! isset( $verdicts[ $token ] ) || 'unknown' === $verdicts[ $token ] ) {
				++$unknown;
			}
		}

		$entry['status']   = 'eligible';
		$entry['url']      = $meta;
		$entry['sessions'] = count( $tokens );
		$entry['unknown']  = $unknown;

		return $entry;
	}

	/**
	 * Decode a heatmap JSON file's `url` field. ONE json_decode, guarded by
	 * the shared oversized-file read limit.
	 *
	 * @param string $file File path.
	 * @return string URL ('' when unrecoverable).
	 */
	private function decode_file_url( $file ) {
		if ( method_exists( 'Opti_Behavior_Heatmap_Storage', 'file_exceeds_read_limit' )
			&& Opti_Behavior_Heatmap_Storage::file_exceeds_read_limit( $file ) ) {
			return '';
		}

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin-owned data file.
		if ( false === $content || '' === $content ) {
			return '';
		}

		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) && ! empty( $decoded['url'] ) && is_string( $decoded['url'] ) ) {
			return $decoded['url'];
		}

		return '';
	}

	/**
	 * Resolve token verdicts through the rebuild service's public resolver
	 * (chunked queries, per-instance memo). Fail-safe: when the resolver or
	 * the sessions table is unavailable every token counts as unknown — the
	 * report then simply shows a 100% unknown share, never wrong exclusions.
	 *
	 * @param string[] $tokens Distinct session tokens of one dir.
	 * @return array<string,string> token => allowed | spam | unknown.
	 */
	private function resolve_verdicts( $tokens ) {
		if ( empty( $tokens ) || null === $this->verdict_resolver ) {
			return array();
		}

		global $wpdb;
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence guard; cron/CLI only.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) ) {
			return array();
		}

		return $this->verdict_resolver->resolve_session_verdicts( $tokens );
	}

	/**
	 * List the archive directory NAMES under `_orphaned/`, sorted.
	 *
	 * Single readdir() stream — names only (~32-47 bytes each), never a
	 * recursive walk. Accepts {32-hex} and the {32-hex}-{YmdHis} re-archive
	 * suffix form; anything else (`.`, `..`, index/htaccess files) is skipped.
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
			if ( ! preg_match( '/^[a-f0-9]{32}(-\d{14})?$/', $entry ) ) {
				continue;
			}
			if ( is_dir( $orphan_dir . $entry ) ) {
				$dirs[] = $entry;
			}
		}
		closedir( $handle );

		sort( $dirs, SORT_STRING );
		return $dirs;
	}

	/**
	 * Live data root (the parent of `_orphaned/`). Reuses the rebuild's
	 * base-dir filter so test fixtures point BOTH scanners at the same
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
			'state'             => 'idle',
			'total_dirs'        => 0,
			'scanned'           => 0,
			'eligible'          => 0,
			'skipped_surviving' => 0,
			'skipped_invalid'   => 0,
			'sessions_total'    => 0,
			'sessions_unknown'  => 0,
			'sample'            => array(),
			'started_at'        => '',
			'updated_at'        => '',
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
	 * Shared sync lock name (never overlaps backfill/reconcile/repair/rebuild).
	 *
	 * @return string
	 */
	private function lock_name() {
		return class_exists( 'Opti_Behavior_Heatmap_Dashboard' )
			? Opti_Behavior_Heatmap_Dashboard::HEATMAP_SYNC_LOCK_NAME
			: 'opti_behavior_heatmap_sync_reconcile';
	}
}
