<?php
/**
 * Engagement counters ("lean events" mode).
 *
 * Historically every heatmap pointer event (click 16/17, scroll/breakaway
 * 32/33, mouse-move/attention 48/49) was inserted as a raw row into
 * `optibehavior_events` in addition to the per-session heatmap JSON file.
 * Scroll + move rows were ~90 % of the table but were only ever read as
 * COUNT(*) per session or per page. This class replaces them with three
 * integer counters on `optibehavior_sessions` (click_count, scroll_count,
 * move_count); per-page totals already live in `optibehavior_heatmap_daily`,
 * fed from the JSON write path. Click rows are still stored because the
 * Top Clicked Elements / CTA insight features need `element_*` columns.
 *
 * Mixed-version safety (Free and Pro can be updated at different times):
 *  - Free owns the schema. Columns are added by dbDelta + a guarded ALTER.
 *  - Lean mode (stop writing scroll/move rows, read counters) only switches
 *    on when: columns exist, the one-off backfill of legacy rows into the
 *    counters has finished, file storage mode is active, AND either Pro is
 *    inactive or Pro >= {@see MIN_PRO_VERSION}. An older Pro still reads
 *    scroll counts from the events table, so Free keeps writing the legacy
 *    rows until Pro is updated. No data is lost in either direction.
 *  - A newer Pro on an older Free calls the SQL builders here only when the
 *    class exists (class_exists guard) and otherwise keeps its legacy SQL.
 *  - Counters are incremented from the first request after the columns are
 *    ready; the backfill only touches rows with id <= the snapshot taken at
 *    that moment, so nothing is counted twice.
 *  - Once lean mode is on, legacy scroll/move rows are purged in id-range
 *    chunks by a time-boxed WP-Cron tick.
 *
 * @package OptiBehavior
 * @since   1.9.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Heatmap_Engagement_Counters
 */
class Opti_Behavior_Heatmap_Engagement_Counters {

	const STATE_OPTION      = 'opti_behavior_engagement_counters';
	const COLUMNS_TRANSIENT = 'opti_behavior_engagement_cols';
	const TICK_HOOK         = 'opti_behavior_engagement_counters_tick';

	/** Pro must be at least this version before Free stops writing scroll/move rows. */
	const MIN_PRO_VERSION = '1.9.2';

	const CLICK_EVENTS  = array( 16, 17 );
	const SCROLL_EVENTS = array( 32, 33 );
	const MOVE_EVENTS   = array( 48, 49 );

	/** Rows per id-range chunk for backfill / purge. */
	const CHUNK_SIZE = 20000;
	/** Seconds of work per cron tick. */
	const TIME_BUDGET = 10;
	/** Sessions per batched UPDATE during backfill. */
	const UPDATE_BATCH = 500;

	/** @var array|null */
	private static $state = null;
	/** @var bool|null */
	private static $columns_ready = null;
	/** @var bool|null */
	private static $lean = null;
	/** @var array<string,array<string,int>> */
	private static $buffer = array();
	/** @var bool */
	private static $shutdown_hooked = false;

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Persistent migration state (autoload off).
	 *
	 * @return array
	 */
	public static function get_state() {
		if ( null === self::$state ) {
			$defaults = array(
				'schema'          => 0,
				'snapshot_id'     => 0,
				'backfill_cursor' => 0,
				'backfill_done'   => 0,
				'purge_cursor'    => 0,
				'purge_max'       => 0,
				'purge_done'      => 0,
				'purged_rows'     => 0,
				'optimized'       => 0,
				'optimized_at'    => '',
				'lean_since'      => '',
				'last_tick'       => '',
				'last_error'      => '',
			);
			$stored      = get_option( self::STATE_OPTION, array() );
			self::$state = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
		}
		return self::$state;
	}

	/**
	 * @param array $state State to persist.
	 */
	private static function save_state( array $state ) {
		self::$state = $state;
		update_option( self::STATE_OPTION, $state, false );
		self::$lean = null;
	}

	/**
	 * Reset per-request caches (tests).
	 */
	public static function reset_runtime_cache() {
		self::$state         = null;
		self::$columns_ready = null;
		self::$lean          = null;
	}

	/* ------------------------------------------------------------------ */
	/* Schema                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Counter column names on the sessions table.
	 *
	 * @return string[]
	 */
	public static function columns() {
		return array( 'click_count', 'scroll_count', 'move_count' );
	}

	/**
	 * Which counter columns currently exist on the sessions table (live probe).
	 *
	 * @return string[]
	 */
	private static function probe_columns() {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME IN (%s, %s, %s)',
				DB_NAME,
				$table,
				'click_count',
				'scroll_count',
				'move_count'
			)
		);
		return is_array( $found ) ? array_map( 'strtolower', $found ) : array();
	}

	/**
	 * Make sure the counter columns exist and the migration state is seeded.
	 * Called from Opti_Behavior_Heatmap_Database::setup_locked() (activation /
	 * version upgrade). Idempotent.
	 *
	 * @return bool True when the columns are present.
	 */
	public static function ensure_schema() {
		global $wpdb;

		$sessions = $wpdb->prefix . 'optibehavior_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) ) {
			return false;
		}

		$missing = array_diff( self::columns(), self::probe_columns() );
		if ( $missing ) {
			$adds = array();
			foreach ( $missing as $col ) {
				$adds[] = "ADD COLUMN {$col} int(10) UNSIGNED NOT NULL DEFAULT 0";
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name from $wpdb->prefix; column names come from the hardcoded self::columns() list.
			$wpdb->query( "ALTER TABLE {$sessions} " . implode( ', ', $adds ) );
			$missing = array_diff( self::columns(), self::probe_columns() );
			if ( $missing ) {
				delete_transient( self::COLUMNS_TRANSIENT );
				self::$columns_ready = false;
				return false;
			}
		}

		set_transient( self::COLUMNS_TRANSIENT, 'ok', 12 * HOUR_IN_SECONDS );
		self::$columns_ready = null;

		$state = self::get_state();
		if ( empty( $state['schema'] ) ) {
			$events = $wpdb->prefix . 'optibehavior_events';
			$max_id = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix (identifiers cannot be placeholders on WP < 6.2); all values are bound via prepare() or cast to int.
				$max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$events}" );
			}
			$state['schema']          = 1;
			$state['snapshot_id']     = $max_id;
			$state['backfill_cursor'] = 0;
			$state['backfill_done']   = ( 0 === $max_id ) ? 1 : 0;
			self::save_state( $state );
		}

		self::maybe_schedule_tick();
		return true;
	}

	/**
	 * Whether the counter columns are usable (state seeded + columns verified).
	 *
	 * @return bool
	 */
	public static function columns_ready() {
		if ( null !== self::$columns_ready ) {
			return self::$columns_ready;
		}
		$state = self::get_state();
		if ( empty( $state['schema'] ) ) {
			self::$columns_ready = false;
			return false;
		}
		if ( 'ok' === get_transient( self::COLUMNS_TRANSIENT ) ) {
			self::$columns_ready = true;
			return true;
		}
		$ok = ! array_diff( self::columns(), self::probe_columns() );
		if ( $ok ) {
			set_transient( self::COLUMNS_TRANSIENT, 'ok', 12 * HOUR_IN_SECONDS );
		}
		self::$columns_ready = $ok;
		return $ok;
	}

	/* ------------------------------------------------------------------ */
	/* Gate                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Pro is either inactive or new enough to read the counters.
	 *
	 * @return bool
	 */
	public static function pro_compatible() {
		if ( ! defined( 'OPTI_BEHAVIOR_PRO_VERSION' ) ) {
			return true;
		}
		return self::is_pro_version_compatible( OPTI_BEHAVIOR_PRO_VERSION );
	}

	/**
	 * Version rule, isolated for tests: ANY Pro version >= MIN_PRO_VERSION
	 * qualifies (1.9.2, 1.9.5, 1.12.0, 2.0…). A site that skips 1.9.2 and
	 * jumps straight to a later release is fully covered — the migration is
	 * driven by the persisted state option, never by a specific version
	 * number, and Free's database setup / daily cron seed it on every
	 * upgrade regardless of the starting version.
	 *
	 * @param string $version Pro version string.
	 * @return bool
	 */
	public static function is_pro_version_compatible( $version ) {
		return version_compare( (string) $version, self::MIN_PRO_VERSION, '>=' );
	}

	/**
	 * Heatmap storage mode is "file" (the only mode where the JSON files, not
	 * the events table, render the heatmaps).
	 *
	 * @return bool
	 */
	public static function file_storage_mode() {
		$settings = get_option( 'opti_behavior_file_storage_settings', array() );
		$mode     = ( is_array( $settings ) && isset( $settings['storage_mode'] ) ) ? $settings['storage_mode'] : 'file';
		return 'file' === $mode;
	}

	/**
	 * Lean mode: scroll/move rows are no longer written and readers use the
	 * session counters / daily index instead of the events table.
	 *
	 * @return bool
	 */
	public static function is_lean() {
		if ( null !== self::$lean ) {
			return self::$lean;
		}
		$state = self::get_state();
		$lean  = self::columns_ready()
			&& ! empty( $state['backfill_done'] )
			&& self::pro_compatible()
			&& self::file_storage_mode();

		/**
		 * Allow forcing legacy (raw row) mode, e.g. for debugging.
		 *
		 * @param bool $lean Computed lean-mode flag.
		 */
		self::$lean = (bool) apply_filters( 'opti_behavior_events_lean_mode', $lean );
		return self::$lean;
	}

	/**
	 * Whether a raw row for this event code must still be inserted.
	 *
	 * @param int $event_code Event code (16/17/32/33/48/49).
	 * @return bool
	 */
	public static function should_store_event_row( $event_code ) {
		$event_code = (int) $event_code;
		if ( in_array( $event_code, self::SCROLL_EVENTS, true ) || in_array( $event_code, self::MOVE_EVENTS, true ) ) {
			return ! self::is_lean();
		}
		return true;
	}

	/**
	 * Diagnostic snapshot.
	 *
	 * @return array
	 */
	public static function status() {
		$state = self::get_state();
		return array(
			'columns_ready'  => self::columns_ready(),
			'pro_compatible' => self::pro_compatible(),
			'file_mode'      => self::file_storage_mode(),
			'lean'           => self::is_lean(),
			'backfill_done'  => ! empty( $state['backfill_done'] ),
			'purge_done'     => ! empty( $state['purge_done'] ),
			'state'          => $state,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Ingest                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Buffer one event for the session. Flushed by {@see flush()} (called at
	 * the end of the heatmap AJAX batch, and on shutdown as a safety net).
	 *
	 * @param string $session_id Session id.
	 * @param int    $event_code Event code.
	 */
	public static function record( $session_id, $event_code ) {
		$session_id = (string) $session_id;
		if ( '' === $session_id ) {
			return;
		}
		if ( ! isset( self::$buffer[ $session_id ] ) ) {
			self::$buffer[ $session_id ] = array(
				'events' => 0,
				'click'  => 0,
				'scroll' => 0,
				'move'   => 0,
			);
		}
		$event_code = (int) $event_code;
		++self::$buffer[ $session_id ]['events'];
		if ( in_array( $event_code, self::CLICK_EVENTS, true ) ) {
			++self::$buffer[ $session_id ]['click'];
		} elseif ( in_array( $event_code, self::SCROLL_EVENTS, true ) ) {
			++self::$buffer[ $session_id ]['scroll'];
		} elseif ( in_array( $event_code, self::MOVE_EVENTS, true ) ) {
			++self::$buffer[ $session_id ]['move'];
		}

		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 0 );
		}
	}

	/**
	 * Write buffered counters: one UPDATE per touched session.
	 */
	public static function flush() {
		global $wpdb;
		if ( empty( self::$buffer ) ) {
			return;
		}
		$buffer       = self::$buffer;
		self::$buffer = array();

		$sessions = $wpdb->prefix . 'optibehavior_sessions';
		$counters = self::columns_ready();

		foreach ( $buffer as $session_id => $counts ) {
			if ( $counters ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix (identifiers cannot be placeholders on WP < 6.2); all values are bound via prepare() or cast to int.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$sessions}
						 SET events_count = events_count + %d,
						     click_count  = click_count + %d,
						     scroll_count = scroll_count + %d,
						     move_count   = move_count + %d
						 WHERE id = %s",
						$counts['events'],
						$counts['click'],
						$counts['scroll'],
						$counts['move'],
						$session_id
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			} else {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix (identifiers cannot be placeholders on WP < 6.2); all values are bound via prepare() or cast to int.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$sessions} SET events_count = events_count + %d WHERE id = %s",
						$counts['events'],
						$session_id
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* SQL builders for readers                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Derived-table SQL yielding one row per session with columns:
	 * session_id, click_count, scroll_count, move_count, has_events.
	 *
	 * Use as `LEFT JOIN ( <sql> ) alias ON alias.session_id = ...`.
	 *
	 * @param bool $single_session When true the SQL carries ONE `%s`
	 *                             placeholder restricting it to one session id.
	 * @return string
	 */
	public static function session_counts_subquery_sql( $single_session = false ) {
		global $wpdb;

		if ( self::is_lean() ) {
			$sessions = $wpdb->prefix . 'optibehavior_sessions';
			$where    = $single_session ? ' WHERE id = %s' : '';
			return "SELECT id AS session_id,
					COALESCE(click_count, 0) AS click_count,
					COALESCE(scroll_count, 0) AS scroll_count,
					COALESCE(move_count, 0) AS move_count,
					(COALESCE(click_count, 0) + COALESCE(scroll_count, 0) + COALESCE(move_count, 0)) AS has_events
				FROM {$sessions}{$where}";
		}

		$events = $wpdb->prefix . 'optibehavior_events';
		$where  = $single_session ? ' AND session_id = %s' : '';
		return "SELECT session_id,
				SUM(CASE WHEN event IN (16,17) THEN 1 ELSE 0 END) AS click_count,
				SUM(CASE WHEN event IN (32,33) THEN 1 ELSE 0 END) AS scroll_count,
				SUM(CASE WHEN event IN (48,49) THEN 1 ELSE 0 END) AS move_count,
				COUNT(*) AS has_events
			FROM {$events}
			WHERE event IN (16,17,32,33,48,49){$where}
			GROUP BY session_id";
	}

	/**
	 * Derived-table SQL yielding one row per page with columns:
	 * page_id2, click_pc, click_mobile, breakaway_pc, breakaway_mobile,
	 * attention_pc, attention_mobile, last_event_time.
	 *
	 * @param string $where_sql Optional WHERE clause (without the keyword).
	 *                          Use the token `{page}` for the page-id column;
	 *                          it resolves to the right column for the source.
	 * @return string
	 */
	public static function page_counts_subquery_sql( $where_sql = '' ) {
		global $wpdb;
		$where_sql = trim( (string) $where_sql );

		if ( self::is_lean() ) {
			$daily = $wpdb->prefix . 'optibehavior_heatmap_daily';
			$where = $where_sql ? ' WHERE ' . str_replace( '{page}', 'page_id', $where_sql ) : '';
			return "SELECT page_id AS page_id2,
					SUM(click_pc) AS click_pc,
					SUM(click_mobile) AS click_mobile,
					SUM(break_pc) AS breakaway_pc,
					SUM(break_mobile) AS breakaway_mobile,
					SUM(att_pc) AS attention_pc,
					SUM(att_mobile) AS attention_mobile,
					MAX(last_event_ts) AS last_event_time
				FROM {$daily}{$where}
				GROUP BY page_id";
		}

		$events = $wpdb->prefix . 'optibehavior_events';
		$where  = $where_sql ? ' AND ' . str_replace( '{page}', 'page_id2', $where_sql ) : '';
		return "SELECT page_id2,
				COUNT( event = 16 OR NULL ) AS click_pc,
				COUNT( event = 17 OR NULL ) AS click_mobile,
				COUNT( event = 32 OR NULL ) AS breakaway_pc,
				COUNT( event = 33 OR NULL ) AS breakaway_mobile,
				COUNT( event = 48 OR NULL ) AS attention_pc,
				COUNT( event = 49 OR NULL ) AS attention_mobile,
				MAX(insert_at) AS last_event_time
			FROM {$events}
			WHERE event IN (16,17,32,33,48,49){$where}
			GROUP BY page_id2";
	}

	/* ------------------------------------------------------------------ */
	/* Maintenance (backfill + purge)                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Any deferred work left?
	 *
	 * @return bool
	 */
	public static function has_pending_work() {
		if ( ! self::columns_ready() ) {
			return false;
		}
		$state = self::get_state();
		if ( empty( $state['backfill_done'] ) ) {
			return true;
		}
		if ( ! self::is_lean() ) {
			return false;
		}
		return empty( $state['purge_done'] ) || empty( $state['optimized'] );
	}

	/**
	 * Human-readable migration progress for the Storage Stats panel.
	 *
	 * @return array{phase:string,label:string,percent:int,blocked_by:string,lean:bool,purged_rows:int,pending:bool,next_run:int}
	 */
	public static function progress() {
		$state   = self::get_state();
		$lean    = self::is_lean();
		$percent = 0;
		$phase   = 'pending';
		$blocked = '';

		if ( ! self::columns_ready() ) {
			$phase = 'schema';
		} elseif ( empty( $state['backfill_done'] ) ) {
			$phase   = 'backfill';
			$snap    = max( 1, (int) $state['snapshot_id'] );
			$percent = (int) floor( 100 * min( 1, (int) $state['backfill_cursor'] / $snap ) );
		} elseif ( ! $lean ) {
			$phase = 'blocked';
			if ( ! self::pro_compatible() ) {
				$blocked = 'pro_version';
			} elseif ( ! self::file_storage_mode() ) {
				$blocked = 'storage_mode';
			} else {
				$blocked = 'filter';
			}
		} elseif ( empty( $state['purge_done'] ) ) {
			$phase   = 'purge';
			$max     = max( 1, (int) $state['purge_max'] );
			$percent = (int) floor( 100 * min( 1, (int) $state['purge_cursor'] / $max ) );
		} elseif ( empty( $state['optimized'] ) ) {
			$phase   = 'optimize';
			$percent = 100;
		} else {
			$phase   = 'done';
			$percent = 100;
		}

		$labels = array(
			'schema'   => __( 'Waiting for the sessions table upgrade (opens on the next admin page load or daily cron).', 'opti-behavior' ),
			'backfill' => __( 'Folding existing scroll/move rows into the session counters…', 'opti-behavior' ),
			'blocked'  => __( 'Counters ready. Cleanup of scroll/move rows is on hold.', 'opti-behavior' ),
			'purge'    => __( 'Deleting legacy scroll/move rows in background batches…', 'opti-behavior' ),
			'optimize' => __( 'Rows deleted. Waiting for OPTIMIZE TABLE to release the disk space.', 'opti-behavior' ),
			'done'     => __( 'Done. Scroll/move points now live only in the heatmap files and session counters.', 'opti-behavior' ),
			'pending'  => '',
		);

		$next = (int) wp_next_scheduled( self::TICK_HOOK );

		return array(
			'phase'       => $phase,
			'label'       => $labels[ $phase ],
			'percent'     => $percent,
			'blocked_by'  => $blocked,
			'lean'        => $lean,
			'purged_rows' => (int) $state['purged_rows'],
			'pending'     => self::has_pending_work(),
			'next_run'    => $next,
			'last_tick'   => (string) $state['last_tick'],
			'last_error'  => (string) $state['last_error'],
		);
	}

	/**
	 * Schedule the maintenance tick if work is pending and none is queued.
	 *
	 * @param int $delay Seconds.
	 */
	public static function maybe_schedule_tick( $delay = 60 ) {
		if ( ! self::has_pending_work() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_single_event( time() + max( 10, (int) $delay ), self::TICK_HOOK );
		}
	}

	/**
	 * Cron handler: time-boxed backfill, then purge. Reschedules itself.
	 *
	 * @return array Progress summary.
	 */
	public static function run_tick( $time_budget = null ) {
		$summary = array(
			'backfilled_rows' => 0,
			'purged_rows'     => 0,
			'optimized'       => false,
			'done'            => false,
		);
		if ( ! self::columns_ready() ) {
			// Zero-touch: seed the schema if database setup has not run yet.
			if ( ! self::ensure_schema() ) {
				return $summary;
			}
		}

		$budget   = ( null === $time_budget ) ? self::TIME_BUDGET : max( 1, (int) $time_budget );
		$deadline = microtime( true ) + $budget;
		$state    = self::get_state();

		if ( empty( $state['backfill_done'] ) ) {
			$summary['backfilled_rows'] = self::backfill_step( $deadline );
			$state                      = self::get_state();
		}

		if ( ! empty( $state['backfill_done'] ) && self::is_lean() ) {
			// New legacy rows may exist if Pro was temporarily downgraded.
			self::maybe_reopen_purge();
			$state = self::get_state();
			if ( empty( $state['purge_done'] ) && microtime( true ) < $deadline ) {
				$summary['purged_rows'] = self::purge_step( $deadline );
				$state                  = self::get_state();
			}
			// Space is only released back to disk / INFORMATION_SCHEMA once the
			// InnoDB tablespace is rebuilt. Runs once, after the purge.
			if ( ! empty( $state['purge_done'] ) && empty( $state['optimized'] ) ) {
				$summary['optimized'] = self::optimize_step();
			}
		}

		if ( class_exists( 'Opti_Behavior_Heatmap_Database' ) && method_exists( 'Opti_Behavior_Heatmap_Database', 'drop_redundant_event_indexes' ) ) {
			Opti_Behavior_Heatmap_Database::drop_redundant_event_indexes();
		}

		$state              = self::get_state();
		$state['last_tick'] = current_time( 'mysql' );
		self::save_state( $state );

		$summary['done'] = ! self::has_pending_work();
		if ( ! $summary['done'] ) {
			self::maybe_schedule_tick( 60 );
		}
		return $summary;
	}

	/**
	 * Rebuild the events tablespace so the deleted rows' space is actually
	 * released (InnoDB keeps it allocated after DELETE, so the Storage Stats
	 * numbers do not move until this runs). Runs once per migration.
	 *
	 * @return bool True when OPTIMIZE completed.
	 */
	private static function optimize_step() {
		global $wpdb;

		$state  = self::get_state();
		$events = $wpdb->prefix . 'optibehavior_events';

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- One-off OPTIMIZE TABLE on a background cron tick; guarded by function_exists, failure is harmless.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix (identifiers cannot be placeholders on WP < 6.2); all values are bound via prepare() or cast to int.
		$result = $wpdb->query( "OPTIMIZE TABLE {$events}" );
		// OPTIMIZE returns a result set ("Table does not support optimize,
		// doing recreate + analyze instead" is the normal InnoDB note), so any
		// non-false return means the rebuild ran.
		if ( false === $result ) {
			$state['last_error'] = 'OPTIMIZE TABLE failed: ' . $wpdb->last_error;
			self::save_state( $state );
			return false;
		}

		$state['optimized']    = 1;
		$state['optimized_at'] = current_time( 'mysql' );
		$state['last_error']   = '';
		self::save_state( $state );
		return true;
	}

	/**
	 * Fold legacy rows (id <= snapshot) into the session counters.
	 *
	 * @param float $deadline microtime() deadline.
	 * @return int Event rows folded.
	 */
	private static function backfill_step( $deadline ) {
		global $wpdb;

		$state    = self::get_state();
		$events   = $wpdb->prefix . 'optibehavior_events';
		$sessions = $wpdb->prefix . 'optibehavior_sessions';
		$snapshot = (int) $state['snapshot_id'];
		$folded   = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) ) ) {
			$state['backfill_done'] = 1;
			self::save_state( $state );
			return 0;
		}

		while ( microtime( true ) < $deadline ) {
			$lo = (int) $state['backfill_cursor'];
			if ( $lo >= $snapshot ) {
				$state['backfill_done'] = 1;
				$state['lean_since']    = current_time( 'mysql' );
				self::save_state( $state );
				break;
			}
			$hi = min( $lo + self::CHUNK_SIZE, $snapshot );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix (identifiers cannot be placeholders on WP < 6.2); all values are bound via prepare() or cast to int.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT session_id,
						SUM(CASE WHEN event IN (16,17) THEN 1 ELSE 0 END) AS c,
						SUM(CASE WHEN event IN (32,33) THEN 1 ELSE 0 END) AS s,
						SUM(CASE WHEN event IN (48,49) THEN 1 ELSE 0 END) AS m
					FROM {$events}
					WHERE id > %d AND id <= %d
					  AND session_id IS NOT NULL AND session_id <> ''
					  AND event IN (16,17,32,33,48,49)
					GROUP BY session_id",
					$lo,
					$hi
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

			foreach ( array_chunk( (array) $rows, self::UPDATE_BATCH ) as $batch ) {
				$ids    = array();
				$case_c = '';
				$case_s = '';
				$case_m = '';
				foreach ( $batch as $row ) {
					$sid     = esc_sql( (string) $row->session_id );
					$ids[]   = "'{$sid}'";
					$case_c .= " WHEN '{$sid}' THEN " . (int) $row->c;
					$case_s .= " WHEN '{$sid}' THEN " . (int) $row->s;
					$case_m .= " WHEN '{$sid}' THEN " . (int) $row->m;
					$folded += (int) $row->c + (int) $row->s + (int) $row->m;
				}
				if ( ! $ids ) {
					continue;
				}
				$id_list = implode( ',', $ids );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name from $wpdb->prefix; session ids pass through esc_sql() and every count is cast to int (one batched CASE update instead of N queries).
				$wpdb->query(
					"UPDATE {$sessions}
					 SET click_count  = click_count  + (CASE id{$case_c} ELSE 0 END),
					     scroll_count = scroll_count + (CASE id{$case_s} ELSE 0 END),
					     move_count   = move_count   + (CASE id{$case_m} ELSE 0 END)
					 WHERE id IN ({$id_list})"
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}

			$state['backfill_cursor'] = $hi;
			self::save_state( $state );
		}

		return $folded;
	}

	/**
	 * If rows were written after the purge finished (Pro temporarily
	 * downgraded), reopen the purge from the previous high-water mark.
	 */
	private static function maybe_reopen_purge() {
		global $wpdb;
		$state = self::get_state();
		if ( empty( $state['purge_done'] ) ) {
			return;
		}
		$events = $wpdb->prefix . 'optibehavior_events';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix (identifiers cannot be placeholders on WP < 6.2); all values are bound via prepare() or cast to int.
		$max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$events}" );
		if ( $max_id <= (int) $state['purge_max'] ) {
			return;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix (identifiers cannot be placeholders on WP < 6.2); all values are bound via prepare() or cast to int.
		$stragglers = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events} WHERE id > %d AND event IN (32,33,48,49)",
				(int) $state['purge_max']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $stragglers > 0 ) {
			$state['purge_done']   = 0;
			$state['purge_cursor'] = (int) $state['purge_max'];
			$state['purge_max']    = $max_id;
			self::save_state( $state );
		} else {
			$state['purge_max'] = $max_id;
			self::save_state( $state );
		}
	}

	/**
	 * Delete legacy scroll/move rows in id-range chunks.
	 *
	 * @param float $deadline microtime() deadline.
	 * @return int Rows deleted.
	 */
	private static function purge_step( $deadline ) {
		global $wpdb;

		$state  = self::get_state();
		$events = $wpdb->prefix . 'optibehavior_events';
		$purged = 0;

		if ( empty( $state['purge_max'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix (identifiers cannot be placeholders on WP < 6.2); all values are bound via prepare() or cast to int.
			$state['purge_max'] = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$events}" );
			self::save_state( $state );
		}
		$max = (int) $state['purge_max'];

		while ( microtime( true ) < $deadline ) {
			$lo = (int) $state['purge_cursor'];
			if ( $lo >= $max ) {
				$state['purge_done'] = 1;
				self::save_state( $state );
				break;
			}
			$hi = min( $lo + self::CHUNK_SIZE, $max );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix (identifiers cannot be placeholders on WP < 6.2); all values are bound via prepare() or cast to int.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$events} WHERE id > %d AND id <= %d AND event IN (32,33,48,49)",
					$lo,
					$hi
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( false === $deleted ) {
				// SQL error: stop this tick, retry later.
				break;
			}
			$purged                += (int) $deleted;
			$state['purge_cursor']  = $hi;
			$state['purged_rows']   = (int) $state['purged_rows'] + (int) $deleted;
			self::save_state( $state );
		}

		return $purged;
	}
}
