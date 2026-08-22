<?php
/**
 * Heatmap registry rebuild service (Option A core, 2026-08-15 remediation).
 *
 * Background: a historical retention bug silently deleted DB sessions/events
 * (and, via cleanup_pages(), the optibehavior_pages rows) while the heatmap
 * JSON files survived on disk. The events-based registry backfill can never
 * resurrect those pages (no surviving DB evidence), so the
 * optibehavior_heatmap_pages registry — the ONLY data source for the Heatmaps
 * list/KPIs — stays frozen at the handful of pages with surviving sessions.
 *
 * This service rebuilds the registry FILE-FIRST: it scans the root url_hash
 * directories under uploads/opti-behavior-data/ (skipping `recordings/` and
 * `_orphaned/`) in batched, cursor-resumable ticks and upserts one registry
 * row per directory that still holds legitimate heatmap data.
 *
 * HARD PERFORMANCE CONSTRAINTS (sites with MILLIONS of JSON files):
 *  - Strictly batched: at most {@see self::BATCH_SIZE} dirs per tick under a
 *    ~20 s wall-time budget (both filterable); cursor persisted in an option.
 *  - Root-dir listing is a single readdir() stream of hash-dir NAMES only
 *    (~10k short strings even on huge sites) — never a recursive glob.
 *  - Registry-existence pre-check is ONE batched query per tick, executed
 *    BEFORE any file IO, so already-registered dirs cost zero disk reads.
 *  - Per-dir costs: 3 folder globs, filename parsing only (counts/devices/
 *    session tokens are encoded in the filenames, mirroring
 *    batch_heatmap_file_metrics_by_page()), and AT MOST ONE json_decode (the
 *    newest file, for page_id/url metadata) guarded by the shared
 *    max-read-bytes size limit.
 *  - Session spam verdicts are resolved with chunked batched queries against
 *    optibehavior_sessions and memoized per instance — never per-file.
 *  - Runs under the shared HEATMAP_SYNC_LOCK_NAME advisory lock so it never
 *    overlaps the backfill/reconcile/auto-repair passes. Admin page loads and
 *    beacon writes are untouched.
 *
 * SPAM GATE (matches the RC-C display gate,
 * Opti_Behavior_Heatmap_Dashboard::is_heatmap_session_allowed_or_orphan()):
 *  - token KNOWN in the DB with an excluded traffic_type (spam/bot/automated)
 *    -> genuine spam, excluded;
 *  - token KNOWN with a non-excluded traffic_type -> legitimate;
 *  - token UNKNOWN (no DB trace — exactly the retention-bug case) ->
 *    legitimate. The DB cannot evidence it as spam.
 * Engagement thresholds (duration/scroll/click HAVING gates) are deliberately
 * NOT applied: heatmaps keep every legitimate human interaction visible (see
 * the policy docblock on get_allowed_heatmap_session_lookup_for_page()).
 *
 * BOT-ONLY-DIR FILTER (user decision 2026-08-15, ON by default): a dir whose
 * counted (allowed + unknown) files contain NO click and NO move signal —
 * i.e. only scroll files, the classic single-scroll bot signature — is
 * skipped and reported in the progress counters. Disable via the
 * `opti_behavior_heatmap_rebuild_bot_only_filter` filter to include them.
 *
 * GUARDRAILS: upsert-only DB writes (never deletes/moves files or rows);
 * url_hash race-guard before every insert; AUTO_INCREMENT id-reuse guard when
 * re-creating optibehavior_pages rows; pause/abort kill switch; heatmap
 * transient caches flushed after ticks that inserted rows.
 *
 * @since 2026-08-15 (registry rebuild from files, Option A)
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batched, cursor-resumable file-first heatmap registry rebuild.
 *
 * @since 2026-08-15
 */
class Opti_Behavior_Heatmap_Registry_Rebuild {

	/**
	 * Cursor option: last fully-processed root hash-dir name ('' = start).
	 */
	const CURSOR_OPTION = 'opti_behavior_heatmap_rebuild_cursor';

	/**
	 * Progress option: cumulative counters + state for the admin progress UI.
	 */
	const PROGRESS_OPTION = 'opti_behavior_heatmap_rebuild_progress';

	/**
	 * Control option: '' (run) | 'pause' | 'abort' — kill switch.
	 */
	const CONTROL_OPTION = 'opti_behavior_heatmap_rebuild_control';

	/**
	 * Divergence-probe baseline option (bot-site fix, 2026-08-15): number of
	 * root url_hash dirs observed by the last COMPLETED rebuild pass
	 * (autoload off; written only on the 'complete' transitions of
	 * {@see self::run_batch()}, never on abort).
	 *
	 * Why: on heavily bot-trafficked sites, bot-only hash dirs never earn
	 * registry rows, so root-dir count permanently exceeds registry COUNT(*)
	 * by the divergence ratio and the weekly probe
	 * ({@see Opti_Behavior_Heatmap_Dashboard::maybe_run_heatmap_rebuild_divergence_probe()})
	 * would re-schedule a full (idempotent but ~2 h of background IO) rebuild
	 * every single week forever. With this baseline stored, the probe compares
	 * CURRENT dir count against the dir count at the last completed scan and
	 * only re-triggers on real GROWTH since then — a steady bot-dir surplus no
	 * longer looks like divergence. No baseline yet (never completed a pass)
	 * keeps the registry-rows comparison so first-run self-heal still works.
	 */
	const PROBE_BASELINE_OPTION = 'opti_behavior_heatmap_rebuild_probe_baseline';

	/**
	 * Default dirs per tick (filterable, clamped 1..200 — spec: 50-100).
	 */
	const BATCH_SIZE = 100;

	/**
	 * Default wall-time budget per tick in seconds (filterable).
	 */
	const TIME_BUDGET = 20.0;

	/**
	 * Storage instance (url hash / base dir / filename parsing helpers).
	 *
	 * @var Opti_Behavior_Heatmap_Storage
	 */
	private $storage;

	/**
	 * Per-instance memo of resolved session-token verdicts:
	 * token => 'allowed' | 'spam' | 'unknown'.
	 *
	 * @var array<string,string>
	 */
	private $verdict_memo = array();

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
	 * Reset cursor + progress and clear the control flag so a fresh rebuild
	 * pass starts from the first directory (admin "start" action).
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
	 * Current progress snapshot (admin poll endpoint reads this).
	 *
	 * @return array
	 */
	public function get_progress() {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		return array_merge( $this->default_progress(), is_array( $progress ) ? $progress : array() );
	}

	/**
	 * Whether a rebuild pass is currently mid-flight (pending or running).
	 *
	 * Read by the cron-conflict guardrail in
	 * {@see Opti_Behavior_Smart_Cleanup_Service::archive_orphaned_page_hash_dirs()}
	 * (defer archiving hash dirs to `_orphaned/` so the rebuild can still scan
	 * them) and by the auto-trigger paths (never clobber an in-flight pass).
	 *
	 * Cost: ONE autoload-off option read — safe for any caller.
	 *
	 * STALENESS GUARD: a pass that died mid-flight (server restart, fatal)
	 * leaves state 'running' forever; without an age cap that would block
	 * cleanup archiving indefinitely. The pass touches the progress option on
	 * every tick (~1/min while working), so an `updated_at` older than the max
	 * age means the pass is dead — treated as inactive. The default (12 h) is
	 * deliberately larger than the divergence-probe scheduling offset (6 h),
	 * because the probe resets progress to 'pending' when it SCHEDULES the
	 * delayed pass and the guardrail must keep deferring archival until the
	 * pass actually starts ticking.
	 *
	 * @since 2026-08-15 (product auto-rebuild)
	 * @param int $max_age Seconds after the last progress update at which a
	 *                     pending/running pass is considered dead. Filterable
	 *                     via `opti_behavior_heatmap_rebuild_active_max_age`.
	 * @return bool
	 */
	public static function is_rebuild_active( $max_age = 43200 ) {
		$progress = get_option( self::PROGRESS_OPTION, array() );
		if ( ! is_array( $progress ) || empty( $progress['state'] ) ) {
			return false;
		}
		if ( ! in_array( (string) $progress['state'], array( 'pending', 'running' ), true ) ) {
			return false;
		}

		$max_age = (int) apply_filters( 'opti_behavior_heatmap_rebuild_active_max_age', (int) $max_age );
		$updated = isset( $progress['updated_at'] ) ? strtotime( (string) $progress['updated_at'] ) : false;
		if ( false === $updated ) {
			return false; // Corrupt/legacy progress payload — never wedge callers.
		}

		return ( current_time( 'timestamp' ) - $updated ) <= max( 0, $max_age );
	}

	/**
	 * Run ONE batched tick of the rebuild.
	 *
	 * @return array{
	 *   processed:int, inserted:int, complete:bool,
	 *   locked:bool, paused:bool, aborted:bool
	 * } Tick result. `complete` is true when the whole scan finished (or the
	 *   pass was aborted); callers (cron wiring) schedule a continuation while
	 *   it is false and neither locked nor paused blocked the tick.
	 */
	public function run_batch() {
		global $wpdb;

		$result = array(
			'processed' => 0,
			'inserted'  => 0,
			'complete'  => false,
			'locked'    => false,
			'paused'    => false,
			'aborted'   => false,
		);

		$registry_table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$pages_table    = $wpdb->prefix . 'optibehavior_pages';
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';

		foreach ( array( $registry_table, $pages_table, $sessions_table ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence guard; cron/CLI only.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$result['complete'] = true;
				return $result;
			}
		}

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
			// A backfill/reconcile/repair pass is scanning; caller retries.
			$result['locked'] = true;
			return $result;
		}

		try {
			$base_dir = $this->get_scan_base_dir();
			$dirs     = $this->list_root_hash_dirs( $base_dir );
			$total    = count( $dirs );
			$cursor   = (string) get_option( self::CURSOR_OPTION, '' );

			// Resume after the cursor (dirs are sorted; strcmp is the resume order).
			$pending = array();
			foreach ( $dirs as $hash ) {
				if ( '' === $cursor || strcmp( $hash, $cursor ) > 0 ) {
					$pending[] = $hash;
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
				// Pass completed: persist the dir count as the divergence-probe
				// baseline (growth-based re-trigger; see PROBE_BASELINE_OPTION).
				update_option( self::PROBE_BASELINE_OPTION, (int) $total, false );
				$result['complete'] = true;
				return $result;
			}

			$batch_size = (int) apply_filters( 'opti_behavior_heatmap_rebuild_batch_size', self::BATCH_SIZE );
			$batch_size = max( 1, min( 200, $batch_size ) );
			$budget     = (float) apply_filters( 'opti_behavior_heatmap_rebuild_time_budget', self::TIME_BUDGET );
			$start      = microtime( true );
			$batch      = array_slice( $pending, 0, $batch_size );

			// Perf: ONE batched registry-existence query BEFORE any file IO —
			// already-registered dirs are skipped with zero disk reads.
			$existing = $this->get_existing_registry_hashes( $batch );

			$tick = array(
				'scanned'          => 0,
				'inserted'         => 0,
				'skipped_existing' => 0,
				'skipped_spam'     => 0,
				'skipped_bot'      => 0,
				'skipped_invalid'  => 0,
			);

			$last_processed = $cursor;

			foreach ( $batch as $hash ) {
				if ( $tick['scanned'] > 0 && ( microtime( true ) - $start ) >= $budget ) {
					break; // Budget spent — continuation resumes from the cursor.
				}

				$last_processed = $hash;
				++$tick['scanned'];
				++$result['processed'];

				if ( isset( $existing[ $hash ] ) ) {
					++$tick['skipped_existing'];
					continue;
				}

				$outcome = $this->process_hash_dir( $base_dir, $hash );
				if ( 'inserted' === $outcome ) {
					++$tick['inserted'];
					++$result['inserted'];
				} elseif ( 'spam_only' === $outcome ) {
					++$tick['skipped_spam'];
				} elseif ( 'bot_only' === $outcome ) {
					++$tick['skipped_bot'];
				} elseif ( 'existing' === $outcome ) {
					++$tick['skipped_existing'];
				} else {
					++$tick['skipped_invalid'];
				}
			}

			$drained = ( $tick['scanned'] >= count( $pending ) );
			update_option( self::CURSOR_OPTION, $drained ? '' : $last_processed, false );

			$progress = $this->get_progress();
			$this->update_progress(
				array(
					'state'            => $drained ? 'complete' : 'running',
					'total_dirs'       => $total,
					'scanned'          => $progress['scanned'] + $tick['scanned'],
					'inserted'         => $progress['inserted'] + $tick['inserted'],
					'skipped_existing' => $progress['skipped_existing'] + $tick['skipped_existing'],
					'skipped_spam'     => $progress['skipped_spam'] + $tick['skipped_spam'],
					'skipped_bot'      => $progress['skipped_bot'] + $tick['skipped_bot'],
					'skipped_invalid'  => $progress['skipped_invalid'] + $tick['skipped_invalid'],
				)
			);

			if ( $drained ) {
				// Pass completed: persist the dir count as the divergence-probe
				// baseline (growth-based re-trigger; see PROBE_BASELINE_OPTION).
				update_option( self::PROBE_BASELINE_OPTION, (int) $total, false );
			}

			$result['complete'] = $drained;
		} finally {
			$this->release_lock( $got_lock );
		}

		if ( $result['inserted'] > 0 && function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
			opti_behavior_flush_heatmap_caches();
		}

		return $result;
	}

	/**
	 * Process one root hash directory: parse filenames, apply the spam gate and
	 * the bot-only filter, decode ONE metadata file, upsert the registry row.
	 *
	 * @param string $base_dir Scan base dir (trailing slash).
	 * @param string $hash     32-hex url_hash directory name.
	 * @return string Outcome: inserted | existing | spam_only | bot_only | invalid.
	 */
	private function process_hash_dir( $base_dir, $hash ) {
		$folder_type_map = array(
			'clicks'  => 'click',
			'moves'   => 'move',
			'scrolls' => 'scroll',
		);

		// 1. List + parse filenames (no content reads: counts, tokens, device
		// and timestamp are all encoded in the filename).
		$files = array();
		foreach ( $folder_type_map as $folder => $type ) {
			$dir = $base_dir . $hash . '/' . $folder;
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
					'type'    => $type,
					'ts'      => (int) $parts[0],
					'count'   => ctype_digit( (string) $parts[3] ) ? (int) $parts[3] : 0,
					'session' => (string) Opti_Behavior_Heatmap_Storage::parse_session_token( $parts ),
					'name'    => basename( $file ),
				);
			}
		}

		if ( empty( $files ) ) {
			return 'invalid'; // No parseable heatmap files — nothing to register.
		}

		// 2. Spam gate: batched verdicts for the dir's tokens (memoized).
		$tokens = array();
		foreach ( $files as $f ) {
			if ( '' !== $f['session'] ) {
				$tokens[ $f['session'] ] = true;
			}
		}
		$verdicts = $this->resolve_session_verdicts( array_keys( $tokens ) );

		$counts      = array(
			'click'  => 0,
			'move'   => 0,
			'scroll' => 0,
		);
		$signal      = array(
			'click'  => false,
			'move'   => false,
			'scroll' => false,
		);
		$last_ts     = 0;
		$newest      = null;
		$newest_name = '';
		$had_allowed = false;

		foreach ( $files as $f ) {
			$verdict = isset( $verdicts[ $f['session'] ] ) ? $verdicts[ $f['session'] ] : 'unknown';
			if ( 'spam' === $verdict ) {
				continue; // Known DB session, excluded traffic type: genuine spam.
			}

			$had_allowed          = true;
			$counts[ $f['type'] ] += $f['count'];
			if ( $f['count'] > 0 ) {
				$signal[ $f['type'] ] = true;
			}
			if ( $f['ts'] > $last_ts ) {
				$last_ts = $f['ts'];
			}
			// Newest file candidate for the single metadata decode (filenames
			// are timestamp-prefixed; ts + lexical name break ties).
			if ( null === $newest || $f['ts'] > $newest['ts']
				|| ( $f['ts'] === $newest['ts'] && strcmp( $f['name'], $newest_name ) > 0 ) ) {
				$newest      = $f;
				$newest_name = $f['name'];
			}
		}

		if ( ! $had_allowed ) {
			return 'spam_only'; // Every session in the dir is known spam.
		}

		if ( $counts['click'] + $counts['move'] + $counts['scroll'] <= 0 ) {
			return 'invalid'; // No interaction points survive the gate.
		}

		// 3. Bot-only-dir filter (default ON): only scroll signals, no click and
		// no move evidence among the counted files — classic bot signature.
		$bot_filter = (bool) apply_filters( 'opti_behavior_heatmap_rebuild_bot_only_filter', true );
		if ( $bot_filter && ! $signal['click'] && ! $signal['move'] ) {
			return 'bot_only';
		}

		// 4. Metadata: decode AT MOST ONE file (the newest allowed one) for the
		// writer-recorded page_id + url. Size-guarded.
		$meta = $this->decode_file_metadata( $newest['path'] );
		if ( empty( $meta['url'] ) ) {
			return 'invalid'; // URL unrecoverable — cannot build a registry row.
		}

		// 5. Resolve/re-create the optibehavior_pages row (id-reuse guard).
		$page_id = $this->resolve_page_id( (int) $meta['page_id'], $meta['url'] );
		if ( $page_id <= 0 ) {
			return 'invalid';
		}

		// 6. Upsert the registry row keyed by the DIRECTORY name (the write-path
		// url_hash identity), with a pre-insert race-guard.
		return $this->insert_registry_row( $hash, $page_id, $meta['url'], $counts, $last_ts );
	}

	/**
	 * Decode a heatmap JSON file's metadata (page_id + url). ONE json_decode,
	 * guarded by the shared oversized-file read limit.
	 *
	 * @param string $file File path.
	 * @return array{page_id:int,url:string}
	 */
	private function decode_file_metadata( $file ) {
		$meta = array(
			'page_id' => 0,
			'url'     => '',
		);

		if ( method_exists( 'Opti_Behavior_Heatmap_Storage', 'file_exceeds_read_limit' )
			&& Opti_Behavior_Heatmap_Storage::file_exceeds_read_limit( $file ) ) {
			return $meta;
		}

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin-owned data file.
		if ( false === $content || '' === $content ) {
			return $meta;
		}

		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) ) {
			$meta['page_id'] = isset( $decoded['page_id'] ) ? (int) $decoded['page_id'] : 0;
			$meta['url']     = isset( $decoded['url'] ) ? (string) $decoded['url'] : '';
		}

		return $meta;
	}

	/**
	 * Resolve the optibehavior_pages id for a rebuilt page, re-creating the
	 * row when the retention bug erased it.
	 *
	 * AUTO_INCREMENT ID-REUSE GUARD: the file's writer-recorded page_id may
	 * have been reassigned to a DIFFERENT page after the original row was
	 * deleted (AUTO_INCREMENT does not reserve deleted ids across restarts /
	 * OPTIMIZE). When the existing row's normalized URL differs from the
	 * file's URL, the id belongs to someone else now — insert a FRESH row and
	 * use the new id instead of corrupting the foreign page.
	 *
	 * @param int    $file_page_id Writer-recorded page id (0 = unknown).
	 * @param string $url          Writer-recorded page URL.
	 * @return int Resolved page id (0 on failure).
	 */
	private function resolve_page_id( $file_page_id, $url ) {
		global $wpdb;

		$pages_table = $wpdb->prefix . 'optibehavior_pages';
		$now         = current_time( 'mysql' );
		$normalized  = $this->normalize_url( $url );

		if ( $file_page_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; id prepared.
			$existing_url = $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$pages_table} WHERE id = %d", $file_page_id ) );

			if ( null !== $existing_url ) {
				if ( $this->normalize_url( (string) $existing_url ) === $normalized ) {
					return $file_page_id; // Row survived (or was already re-created) — same page.
				}
				// Id-reuse collision: the id now belongs to a different URL.
				// Fall through to URL lookup / fresh insert below.
			} else {
				// Row gone: re-create it under its ORIGINAL id so surviving
				// events/session_pages references keep pointing at the page.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; wpdb->insert prepares values.
				$ok = $wpdb->insert(
					$pages_table,
					array(
						'id'        => $file_page_id,
						'url'       => $url,
						'url2'      => $url,
						'title'     => '',
						'insert_at' => $now,
						'update_at' => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( false !== $ok ) {
					return $file_page_id;
				}
				// Race: someone inserted the id between SELECT and INSERT —
				// re-check whose URL it is now.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; id prepared.
				$existing_url = $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$pages_table} WHERE id = %d", $file_page_id ) );
				if ( null !== $existing_url && $this->normalize_url( (string) $existing_url ) === $normalized ) {
					return $file_page_id;
				}
			}
		}

		// Unknown/collided id: reuse an existing row for the same URL when one
		// exists (avoid duplicate page rows), else insert a fresh row (new id).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; url prepared and indexed.
		$by_url = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pages_table} WHERE url = %s ORDER BY id ASC LIMIT 1", $url ) );
		if ( $by_url > 0 ) {
			return $by_url;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; wpdb->insert prepares values.
		$ok = $wpdb->insert(
			$pages_table,
			array(
				'url'       => $url,
				'url2'      => $url,
				'title'     => '',
				'insert_at' => $now,
				'update_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return ( false !== $ok ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Insert the registry row with a pre-insert race-guard on url_hash.
	 *
	 * @param string $url_hash Directory name (write-path url_hash identity).
	 * @param int    $page_id  Resolved page id.
	 * @param string $url      Page URL.
	 * @param array  $counts   click/move/scroll point sums.
	 * @param int    $last_ts  Newest counted file timestamp.
	 * @return string 'inserted' | 'existing' | 'invalid'.
	 */
	private function insert_registry_row( $url_hash, $page_id, $url, $counts, $last_ts ) {
		global $wpdb;

		$registry_table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$now            = current_time( 'mysql' );

		// Race guard: live traffic (write-path upsert) may have created the row
		// since the batched pre-check — url_hash is UNIQUE, so re-check first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; value prepared.
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$registry_table} WHERE url_hash = %s", $url_hash ) );
		if ( $exists > 0 ) {
			return 'existing';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; wpdb->insert prepares values.
		$ok = $wpdb->insert(
			$registry_table,
			array(
				'page_id'      => $page_id,
				'url_hash'     => $url_hash,
				'url'          => $url,
				'created_at'   => $now,
				'updated_at'   => $now,
				'click_count'  => (int) $counts['click'],
				'move_count'   => (int) $counts['move'],
				'scroll_count' => (int) $counts['scroll'],
				'last_data_at' => $last_ts > 0 ? gmdate( 'Y-m-d H:i:s', $last_ts ) : $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		if ( false === $ok ) {
			// Duplicate-key race on the UNIQUE url_hash counts as existing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; value prepared.
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$registry_table} WHERE url_hash = %s", $url_hash ) );
			return $exists > 0 ? 'existing' : 'invalid';
		}

		return 'inserted';
	}

	/**
	 * Resolve verdicts for file session tokens against optibehavior_sessions.
	 *
	 * 3-form matching mirror of
	 * Opti_Behavior_Heatmap_Dashboard::resolve_allowlist_session_ids_for_tokens():
	 * a DB session id d matches token t when {t, clean(t), last8(clean(t))}
	 * intersects {d, clean(d), last8(clean(d))}. Chunked (500 tokens/query) and
	 * memoized per instance, so a token's verdict is computed at most once per
	 * pass regardless of how many files carry it.
	 *
	 * Fallback: when REGEXP_REPLACE is unavailable (MySQL < 8 / MariaDB <
	 * 10.0.5) the clean(d) forms degrade to RIGHT(id, 8) — slightly narrower
	 * matching whose only failure direction is "known session treated as
	 * unknown", i.e. counted as legitimate (the mandated unknown-token policy).
	 *
	 * Public so the `_orphaned/` dry-run report scanner
	 * ({@see Opti_Behavior_Heatmap_Orphan_Report}) can reuse the exact same
	 * matching/memoization instead of duplicating it (read-only usage).
	 *
	 * @param string[] $tokens File session tokens.
	 * @return array<string,string> token => 'allowed' | 'spam' | 'unknown'.
	 */
	public function resolve_session_verdicts( $tokens ) {
		global $wpdb;

		$excluded = class_exists( 'Opti_Behavior_Stats_Spam_Filter' )
			? Opti_Behavior_Stats_Spam_Filter::excluded_traffic_types()
			: array( 'spam', 'bot', 'automated' );

		$pending = array();
		$out     = array();
		foreach ( (array) $tokens as $token ) {
			$token = (string) $token;
			if ( '' === $token ) {
				continue;
			}
			if ( isset( $this->verdict_memo[ $token ] ) ) {
				$out[ $token ] = $this->verdict_memo[ $token ];
				continue;
			}
			$pending[ $token ] = true;
		}
		$pending = array_keys( $pending );

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';

		foreach ( array_chunk( $pending, 500 ) as $chunk ) {
			// Build the 3-form set for the chunk.
			$forms = array();
			foreach ( $chunk as $token ) {
				$clean                         = preg_replace( '/[^a-zA-Z0-9]/', '', $token );
				$forms[ $token ]               = true;
				$forms[ $clean ]               = true;
				$forms[ substr( $clean, -8 ) ] = true;
			}
			$forms = array_map( 'strval', array_keys( $forms ) );
			$ph    = implode( ',', array_fill( 0, count( $forms ), '%s' ) );

			$prev_suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from $wpdb->prefix; all values parameterized.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, traffic_type FROM {$sessions_table}
					WHERE id IN ({$ph})
						OR REGEXP_REPLACE(id, '[^a-zA-Z0-9]', '') IN ({$ph})
						OR RIGHT(REGEXP_REPLACE(id, '[^a-zA-Z0-9]', ''), 8) IN ({$ph})",
					array_merge( $forms, $forms, $forms )
				)
			);
			if ( ! empty( $wpdb->last_error ) ) {
				// REGEXP_REPLACE unavailable: degrade to raw-id + RIGHT() matching.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from $wpdb->prefix; all values parameterized.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, traffic_type FROM {$sessions_table}
						WHERE id IN ({$ph}) OR RIGHT(id, 8) IN ({$ph})",
						array_merge( $forms, $forms )
					)
				);
			}
			$wpdb->suppress_errors( $prev_suppress );

			// Index the matched sessions by all 3 forms; a form maps to
			// "allowed" when ANY matching session is non-excluded.
			$form_verdict = array();
			foreach ( (array) $rows as $row ) {
				$id      = (string) $row->id;
				$clean   = preg_replace( '/[^a-zA-Z0-9]/', '', $id );
				$ttype   = strtolower( (string) $row->traffic_type );
				$is_spam = in_array( $ttype, $excluded, true );
				foreach ( array( $id, $clean, substr( $clean, -8 ) ) as $form ) {
					if ( '' === $form ) {
						continue;
					}
					if ( ! isset( $form_verdict[ $form ] ) || ! $is_spam ) {
						$form_verdict[ $form ] = $is_spam ? 'spam' : 'allowed';
					}
				}
			}

			foreach ( $chunk as $token ) {
				$clean   = preg_replace( '/[^a-zA-Z0-9]/', '', $token );
				$verdict = 'unknown';
				foreach ( array( $token, $clean, substr( $clean, -8 ) ) as $form ) {
					if ( isset( $form_verdict[ $form ] ) ) {
						// A single non-spam match legitimizes the token.
						if ( 'allowed' === $form_verdict[ $form ] ) {
							$verdict = 'allowed';
							break;
						}
						$verdict = 'spam';
					}
				}
				$this->verdict_memo[ $token ] = $verdict;
				$out[ $token ]                = $verdict;
			}
		}

		return $out;
	}

	/**
	 * Batched registry-existence pre-check for a tick's dir slice.
	 *
	 * @param string[] $hashes url_hash dir names.
	 * @return array<string,bool> Existing url_hash => true.
	 */
	private function get_existing_registry_hashes( $hashes ) {
		global $wpdb;

		if ( empty( $hashes ) ) {
			return array();
		}

		$registry_table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$ph             = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from $wpdb->prefix; values parameterized; url_hash is UNIQUE-indexed.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT url_hash FROM {$registry_table} WHERE url_hash IN ({$ph})", $hashes ) );

		$map = array();
		foreach ( (array) $found as $hash ) {
			$map[ (string) $hash ] = true;
		}
		return $map;
	}

	/**
	 * List the root url_hash directory NAMES of the heatmap data dir, sorted.
	 *
	 * Single readdir() stream over the base dir — hash-dir names only (~32
	 * bytes each), never a recursive walk. `recordings/`, `_orphaned/` and any
	 * non-32-hex entry are skipped.
	 *
	 * @param string $base_dir Base dir (trailing slash).
	 * @return string[] Sorted hash dir names.
	 */
	private function list_root_hash_dirs( $base_dir ) {
		$dirs = array();

		if ( ! is_dir( $base_dir ) ) {
			return $dirs;
		}

		$handle = opendir( $base_dir );
		if ( false === $handle ) {
			return $dirs;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $entry ) ) {
				continue; // Skips '.', '..', 'recordings', '_orphaned', strays.
			}
			if ( is_dir( $base_dir . $entry ) ) {
				$dirs[] = $entry;
			}
		}
		closedir( $handle );

		sort( $dirs, SORT_STRING );
		return $dirs;
	}

	/**
	 * Scan base dir (filterable so tests / the Option C `_orphaned/` scanner
	 * can point the pass at a fixture root).
	 *
	 * @return string Trailing-slashed base dir.
	 */
	private function get_scan_base_dir() {
		$base = trailingslashit( $this->storage->get_base_dir() );
		return trailingslashit( (string) apply_filters( 'opti_behavior_heatmap_rebuild_base_dir', $base ) );
	}

	/**
	 * Normalize a URL exactly like the write-path bucket identity
	 * (Opti_Behavior_Heatmap_Storage::get_url_hash() WITHOUT the md5):
	 * scheme:// + lowercased host + path with the trailing slash trimmed.
	 *
	 * @param string $url URL.
	 * @return string Normalized URL.
	 */
	private function normalize_url( $url ) {
		$parsed = wp_parse_url( (string) $url );

		$normalized = '';
		if ( isset( $parsed['scheme'] ) ) {
			$normalized .= $parsed['scheme'] . '://';
		}
		if ( isset( $parsed['host'] ) ) {
			$normalized .= strtolower( $parsed['host'] );
		}
		if ( isset( $parsed['path'] ) ) {
			$normalized .= rtrim( $parsed['path'], '/' );
		}

		return $normalized;
	}

	/**
	 * Default progress structure.
	 *
	 * @return array
	 */
	private function default_progress() {
		return array(
			'state'            => 'idle',
			'total_dirs'       => 0,
			'scanned'          => 0,
			'inserted'         => 0,
			'skipped_existing' => 0,
			'skipped_spam'     => 0,
			'skipped_bot'      => 0,
			'skipped_invalid'  => 0,
			'started_at'       => '',
			'updated_at'       => '',
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
	 * Shared sync lock name (never overlaps backfill/reconcile/repair).
	 *
	 * @return string
	 */
	private function lock_name() {
		return class_exists( 'Opti_Behavior_Heatmap_Dashboard' )
			? Opti_Behavior_Heatmap_Dashboard::HEATMAP_SYNC_LOCK_NAME
			: 'opti_behavior_heatmap_sync_reconcile';
	}
}
