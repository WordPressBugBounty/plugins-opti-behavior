<?php
/**
 * DB size cap (1.9.3).
 *
 * Age-based retention alone lets a busy site fill the raw tables for a
 * whole year. This tick adds two ceilings, both OFF by default:
 *
 *  - Global cap (`db_max_mb`): every plugin table together, data + indexes.
 *  - Per-table cap (`table_max_mb`): applied to each raw (sweepable) table;
 *    override one table with `opti_behavior_table_max_mb` ($mb, $table).
 *
 * Enforcement always removes the OLDEST raw rows first, through the same
 * batched sweep the daily retention uses, and never touches dashboard
 * summaries (daily_stats, dimension stats, heatmap_daily, A/B daily stats)
 * or configuration tables. Guard rails:
 *
 *  - Rows newer than `opti_behavior_size_cap_min_keep_days` (default 7)
 *    are never deleted, whatever the cap.
 *  - At most `opti_behavior_size_cap_max_fraction` (default 25 %) of a
 *    table's rows go per run, so a stale size estimate can never wipe a
 *    table in one pass; the next daily run re-measures after OPTIMIZE.
 *  - After a delete the affected tables are queued for OPTIMIZE TABLE
 *    (Opti_Behavior_DB_Schema_Migration) because DELETE alone frees no disk
 *    space — the size only really drops after that rebuild.
 *
 * Version-skew safety: only DELETE statements on existing tables; nothing
 * here depends on the Pro plugin or changes any schema.
 *
 * @package OptiBehavior
 * @since   1.9.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_DB_Size_Cap
 */
class Opti_Behavior_DB_Size_Cap {

	/** One-off hook: Cleanup Tasks "Run now" + capped continuation. */
	const HOOK = 'opti_behavior_db_size_cap_run';

	/** State option (not autoloaded). */
	const STATE_OPTION = 'opti_behavior_db_size_cap_state';

	/** Seconds a tick may spend deleting before deferring. */
	const DEFAULT_TIME_BUDGET = 25;

	/** DELETE batch size (same as the daily sweep). */
	const BATCH = 10000;

	/**
	 * Whether any cap is configured.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! class_exists( 'Opti_Behavior_Retention_Policy' ) || ! method_exists( 'Opti_Behavior_Retention_Policy', 'get_db_max_mb' ) ) {
			return false;
		}
		return Opti_Behavior_Retention_Policy::get_db_max_mb() > 0 || Opti_Behavior_Retention_Policy::get_table_max_mb() > 0;
	}

	/**
	 * Daily entry point (also the one-off hook handler).
	 *
	 * @return array Summary of this tick.
	 */
	public static function run_tick() {
		global $wpdb;

		$summary = array(
			'enabled'       => self::is_enabled(),
			'total_mb'      => 0.0,
			'db_cap_mb'     => 0,
			'table_cap_mb'  => 0,
			'rows_deleted'  => 0,
			'global_cutoff' => null,
			'tables'        => array(),
			'capped'        => false,
			'note'          => '',
		);

		$measure             = self::measure();
		$summary['total_mb'] = $measure['total_mb'];

		if ( ! $summary['enabled'] ) {
			// No "nothing was deleted" here: the same run can still delete rows
			// through the retention period, which writes its own note.
			$summary['note'] = __( 'Database size cap: not configured.', 'opti-behavior' );
			self::save_state( $summary );
			return $summary;
		}

		$summary['db_cap_mb']    = Opti_Behavior_Retention_Policy::get_db_max_mb();
		$summary['table_cap_mb'] = Opti_Behavior_Retention_Policy::get_table_max_mb();

		$deadline      = time() + max( 5, (int) apply_filters( 'opti_behavior_db_size_cap_time_budget', self::DEFAULT_TIME_BUDGET ) );
		$min_keep_days = max( 1, absint( apply_filters( 'opti_behavior_size_cap_min_keep_days', 7 ) ) );
		$floor_cutoff  = gmdate( 'Y-m-d H:i:s', time() - $min_keep_days * DAY_IN_SECONDS );
		$max_fraction  = min( 1.0, max( 0.01, (float) apply_filters( 'opti_behavior_size_cap_max_fraction', 0.25 ) ) );
		$sweep         = class_exists( 'Opti_Behavior_Heatmap_Database' ) ? Opti_Behavior_Heatmap_Database::get_retention_sweep_table_map() : array();
		$touched       = array();

		// ── Per-table caps ────────────────────────────────────────────────
		foreach ( $sweep as $suffix => $date_col ) {
			$table = $wpdb->prefix . $suffix;
			$cap   = (float) apply_filters( 'opti_behavior_table_max_mb', $summary['table_cap_mb'], $table );
			if ( $cap <= 0 || empty( $measure['tables'][ $table ] ) ) {
				continue;
			}
			$info = $measure['tables'][ $table ];
			if ( $info['mb'] <= $cap || $info['rows'] < 1 ) {
				continue;
			}
			if ( time() >= $deadline ) {
				$summary['capped'] = true;
				break;
			}
			$excess_rows = (int) floor( $info['rows'] * ( 1 - $cap / $info['mb'] ) );
			$excess_rows = min( $excess_rows, (int) floor( $info['rows'] * $max_fraction ) );
			if ( $excess_rows < 1 ) {
				continue;
			}
			$cutoff = self::cutoff_at_offset( $table, $date_col, $excess_rows );
			if ( null === $cutoff ) {
				continue;
			}
			if ( $cutoff > $floor_cutoff ) {
				$cutoff = $floor_cutoff;
			}
			$deleted = self::delete_before( $table, $date_col, $cutoff, $deadline );
			if ( $deleted > 0 ) {
				$touched[]                  = $table;
				$summary['rows_deleted']   += $deleted;
				$summary['tables'][ $table ] = array(
					'mb'      => $info['mb'],
					'cap_mb'  => $cap,
					'cutoff'  => $cutoff,
					'deleted' => $deleted,
				);
			}
		}

		// ── Global cap ───────────────────────────────────────────────────
		$db_cap = (float) $summary['db_cap_mb'];
		if ( $db_cap > 0 && $measure['total_mb'] > $db_cap && time() < $deadline ) {
			$excess_bytes = ( $measure['total_mb'] - $db_cap ) * MB_IN_BYTES;
			$cutoff       = self::find_global_cutoff( $sweep, $measure, $excess_bytes, $floor_cutoff, $max_fraction );
			if ( null !== $cutoff ) {
				$core     = class_exists( 'Opti_Behavior_Heatmap_Core' ) ? Opti_Behavior_Heatmap_Core::get_instance() : null;
				$database = ( $core && method_exists( $core, 'get_database' ) ) ? $core->get_database() : null;
				if ( $database && method_exists( $database, 'delete_raw_data_before' ) ) {
					$deleted                   = (int) $database->delete_raw_data_before( $cutoff, 'db size cap' );
					$summary['rows_deleted']  += $deleted;
					$summary['global_cutoff']  = $cutoff;
					if ( $deleted > 0 ) {
						foreach ( array_keys( $sweep ) as $suffix ) {
							$touched[] = $wpdb->prefix . $suffix;
						}
					}
				}
			}
		}

		$touched = array_values( array_unique( $touched ) );
		if ( $touched && class_exists( 'Opti_Behavior_DB_Schema_Migration' ) ) {
			Opti_Behavior_DB_Schema_Migration::request_optimize( $touched );
		}

		if ( $summary['rows_deleted'] > 0 ) {
			$summary['note'] = sprintf(
				/* translators: 1: rows deleted, 2: size before in MB */
				__( '%1$s oldest raw row(s) deleted to get back under the cap (was %2$s MB); disk space is released by the next OPTIMIZE pass.', 'opti-behavior' ),
				number_format_i18n( $summary['rows_deleted'] ),
				number_format_i18n( $measure['total_mb'], 1 )
			);
		} elseif ( $summary['capped'] ) {
			$summary['note'] = __( 'Time budget reached before every table was checked — the run continues shortly.', 'opti-behavior' );
		} else {
			$summary['note'] = sprintf(
				/* translators: 1: size in MB, 2: cap in MB */
				__( 'Plugin tables use %1$s MB — under the configured cap (%2$s MB), nothing was deleted.', 'opti-behavior' ),
				number_format_i18n( $measure['total_mb'], 1 ),
				number_format_i18n( $db_cap > 0 ? $db_cap : (float) $summary['table_cap_mb'], 0 )
			);
		}

		self::save_state( $summary );

		if ( $summary['capped'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK );
		}

		return $summary;
	}

	/**
	 * Current footprint of every plugin table (data + indexes, MB).
	 *
	 * @return array { total_mb: float, tables: array<name, {mb, rows, avg_row_bytes}> }
	 */
	public static function measure() {
		global $wpdb;
		$out   = array(
			'total_mb' => 0.0,
			'tables'   => array(),
		);
		$like1 = $wpdb->esc_like( $wpdb->prefix . 'optibehavior_' ) . '%';
		$like2 = $wpdb->esc_like( $wpdb->prefix . 'opti_behavior_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, (DATA_LENGTH + INDEX_LENGTH) AS bytes
				 FROM INFORMATION_SCHEMA.TABLES
				 WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s AND ( TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s )',
				DB_NAME,
				'BASE TABLE',
				$like1,
				$like2
			),
			ARRAY_A
		);
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$bytes = (float) $r['bytes'];
				$n     = max( 0, (int) $r['row_count'] );
				$out['tables'][ (string) $r['name'] ] = array(
					'mb'            => round( $bytes / MB_IN_BYTES, 3 ),
					'rows'          => $n,
					'avg_row_bytes' => $n > 0 ? $bytes / $n : 0.0,
				);
				$out['total_mb'] += $bytes / MB_IN_BYTES;
			}
		}
		$out['total_mb'] = round( $out['total_mb'], 3 );
		return $out;
	}

	/**
	 * Date value of the N-th oldest row (rows strictly older than it = N).
	 *
	 * @param string $table    Full table name.
	 * @param string $date_col Date column.
	 * @param int    $offset   Row offset.
	 * @return string|null
	 */
	private static function cutoff_at_offset( $table, $date_col, $offset ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table / $date_col come from the hardcoded sweep map ($wpdb->prefix + fixed suffix => fixed column), never from input.
		$value =$wpdb->get_var( $wpdb->prepare( "SELECT `{$date_col}` FROM `{$table}` WHERE `{$date_col}` IS NOT NULL ORDER BY `{$date_col}` ASC LIMIT 1 OFFSET %d", max( 0, (int) $offset ) ) );
		return $value ? (string) $value : null;
	}

	/**
	 * Batched DELETE of rows older than $cutoff on one table.
	 *
	 * @param string $table    Full table name.
	 * @param string $date_col Date column.
	 * @param string $cutoff   Datetime.
	 * @param int    $deadline Unix time budget.
	 * @return int Rows deleted.
	 */
	private static function delete_before( $table, $date_col, $cutoff, $deadline ) {
		global $wpdb;
		$total = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table / $date_col come from the hardcoded sweep map ($wpdb->prefix + fixed suffix => fixed column), never from input.
			$deleted =$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE `{$date_col}` < %s LIMIT %d", $cutoff, self::BATCH ) );
			if ( false === $deleted ) {
				break;
			}
			$total += (int) $deleted;
			if ( (int) $deleted >= self::BATCH && time() < $deadline ) {
				usleep( 50000 );
			}
		} while ( (int) $deleted >= self::BATCH && time() < $deadline );
		return $total;
	}

	/**
	 * Binary-search the newest cutoff whose older rows, across the sweep
	 * tables, weigh at least $excess_bytes (estimated via avg row size).
	 *
	 * @param array  $sweep        suffix => date column.
	 * @param array  $measure      measure() output.
	 * @param float  $excess_bytes Bytes to shed.
	 * @param string $floor_cutoff Never newer than this.
	 * @param float  $max_fraction Max share of sessions rows per run.
	 * @return string|null Cutoff datetime, null when nothing can go.
	 */
	private static function find_global_cutoff( array $sweep, array $measure, $excess_bytes, $floor_cutoff, $max_fraction ) {
		global $wpdb;
		$sessions = $wpdb->prefix . 'optibehavior_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table name built from $wpdb->prefix; no user input.
		$oldest =$wpdb->get_var( "SELECT MIN(start_time) FROM `{$sessions}`" );
		if ( ! $oldest ) {
			return null;
		}
		$lo = strtotime( $oldest . ' UTC' );
		$hi = strtotime( $floor_cutoff . ' UTC' );
		if ( ! $lo || ! $hi || $lo >= $hi ) {
			return null;
		}
		// Fraction guard: the cutoff may not pass the max_fraction-th oldest session.
		$sessions_rows = isset( $measure['tables'][ $sessions ]['rows'] ) ? (int) $measure['tables'][ $sessions ]['rows'] : 0;
		if ( $sessions_rows > 0 ) {
			$limit = self::cutoff_at_offset( $sessions, 'start_time', (int) floor( $sessions_rows * $max_fraction ) );
			if ( $limit ) {
				$hi = min( $hi, (int) strtotime( $limit . ' UTC' ) );
			}
		}
		if ( $lo >= $hi ) {
			return null;
		}

		$best = null;
		for ( $i = 0; $i < 12 && $hi - $lo > HOUR_IN_SECONDS; $i++ ) {
			$mid    = (int) ( ( $lo + $hi ) / 2 );
			$cutoff = gmdate( 'Y-m-d H:i:s', $mid );
			if ( self::estimate_bytes_before( $sweep, $measure, $cutoff ) >= $excess_bytes ) {
				$best = $cutoff;
				$hi   = $mid;
			} else {
				$lo = $mid;
			}
		}
		// Nothing satisfied the target inside the guard rails: shed what the rails allow.
		return null !== $best ? $best : gmdate( 'Y-m-d H:i:s', $hi );
	}

	/**
	 * Estimated bytes held by rows older than $cutoff across sweep tables.
	 *
	 * @param array  $sweep   suffix => date column.
	 * @param array  $measure measure() output.
	 * @param string $cutoff  Datetime.
	 * @return float
	 */
	private static function estimate_bytes_before( array $sweep, array $measure, $cutoff ) {
		global $wpdb;
		$bytes = 0.0;
		foreach ( $sweep as $suffix => $date_col ) {
			$table = $wpdb->prefix . $suffix;
			if ( empty( $measure['tables'][ $table ] ) || $measure['tables'][ $table ]['rows'] < 1 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table / $date_col come from the hardcoded sweep map ($wpdb->prefix + fixed suffix => fixed column), never from input.
			$n =(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$date_col}` < %s", $cutoff ) );
			$bytes += $n * (float) $measure['tables'][ $table ]['avg_row_bytes'];
		}
		return $bytes;
	}

	/**
	 * Persist the last run.
	 *
	 * @param array $summary Tick summary.
	 * @return void
	 */
	private static function save_state( array $summary ) {
		update_option(
			self::STATE_OPTION,
			array(
				'last_run'     => current_time( 'mysql' ),
				'last_summary' => $summary,
			),
			false
		);

		// Cleanup Tasks history: show the real outcome instead of "Nothing deleted".
		if ( class_exists( 'Opti_Behavior_Cleanup_Task_Registry' ) && method_exists( 'Opti_Behavior_Cleanup_Task_Registry', 'report_run' ) ) {
			$rows_by_table = array();
			foreach ( $summary['tables'] as $table => $info ) {
				$rows_by_table[ $table ] = (int) $info['deleted'];
			}
			Opti_Behavior_Cleanup_Task_Registry::report_run(
				array(
					'status'        => ! $summary['enabled'] ? 'skipped' : ( $summary['capped'] ? 'partial' : ( $summary['rows_deleted'] > 0 ? 'completed' : 'skipped' ) ),
					'note'          => (string) $summary['note'],
					'rows_by_table' => $rows_by_table,
				)
			);
		}
	}

	/**
	 * Stored state.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Human-readable lines for the Cleanup Tasks panel.
	 *
	 * @return string[]
	 */
	public static function describe() {
		$lines   = array();
		$measure = self::measure();
		$db_cap  = class_exists( 'Opti_Behavior_Retention_Policy' ) && method_exists( 'Opti_Behavior_Retention_Policy', 'get_db_max_mb' ) ? Opti_Behavior_Retention_Policy::get_db_max_mb() : 0;
		$tb_cap  = class_exists( 'Opti_Behavior_Retention_Policy' ) && method_exists( 'Opti_Behavior_Retention_Policy', 'get_table_max_mb' ) ? Opti_Behavior_Retention_Policy::get_table_max_mb() : 0;

		$lines[] = $db_cap > 0
			/* translators: 1: current size in MB, 2: cap in MB */
			? sprintf( __( 'Plugin tables: %1$s MB of a %2$s MB cap.', 'opti-behavior' ), number_format_i18n( $measure['total_mb'], 1 ), number_format_i18n( $db_cap ) )
			/* translators: %s: current size in MB */
			: sprintf( __( 'Plugin tables: %s MB (no overall cap set).', 'opti-behavior' ), number_format_i18n( $measure['total_mb'], 1 ) );

		$lines[] = $tb_cap > 0
			/* translators: %s: per-table cap in MB */
			? sprintf( __( 'Each raw data table is capped at %s MB.', 'opti-behavior' ), number_format_i18n( $tb_cap ) )
			: __( 'No per-table cap set.', 'opti-behavior' );

		$lines[] = __( 'When a cap is exceeded the OLDEST raw rows go first (never dashboard summaries); rows from the last 7 days are always kept and at most a quarter of a table is removed per run.', 'opti-behavior' );
		return $lines;
	}
}
