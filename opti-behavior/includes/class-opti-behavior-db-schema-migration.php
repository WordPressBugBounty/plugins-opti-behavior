<?php
/**
 * DB schema migration tick (1.9.3 space audit).
 *
 * Three idempotent, background-only jobs that ride the daily maintenance
 * hook (and a one-off hook for "Run now" + continuation):
 *
 *  1. Redundant index drop — `Opti_Behavior_Heatmap_Database::drop_redundant_indexes()`.
 *     Each index is dropped only when its covering index exists, so a
 *     partially upgraded install (older Pro still active, an older Free
 *     activation that has not yet built the covering index) never loses a
 *     lookup path. Re-running is a no-op.
 *  2. Storage-engine conversion — `ALTER TABLE … ENGINE=InnoDB` for plugin
 *     tables still on MyISAM (server default on WAMP and some hosts). One
 *     table per tick, smallest first, only under a size ceiling (filter
 *     `opti_behavior_engine_convert_max_mb`, default 512) and only on a
 *     server whose InnoDB supports 3072-byte index prefixes (MySQL ≥ 5.7.7,
 *     MariaDB ≥ 10.2.2) — older servers keep MyISAM untouched rather than
 *     failing on the varchar(500) indexes. Every ALTER is plain SQL that an
 *     older Free or Pro reads and writes identically: the conversion never
 *     changes columns or indexes.
 *  3. Space reclaim — `OPTIMIZE TABLE` for one table per tick whose
 *     DATA_FREE exceeds 20 % of its footprint (and ≥ 8 MB), at most once a
 *     week per table. DELETE frees nothing on disk without it.
 *
 * Version-skew safety: the tick touches only tables that already exist and
 * never renames, drops or retypes a column, so an older Pro plugin writing
 * to these tables keeps working before, during and after the migration.
 *
 * @package OptiBehavior
 * @since   1.9.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_DB_Schema_Migration
 */
class Opti_Behavior_DB_Schema_Migration {

	/** One-off hook: Cleanup Tasks "Run now" + capped continuation. */
	const HOOK = 'opti_behavior_db_schema_migration_run';

	/** State option (not autoloaded). */
	const STATE_OPTION = 'opti_behavior_db_schema_migration_state';

	/** Seconds a tick may spend before deferring the rest to a continuation. */
	const DEFAULT_TIME_BUDGET = 25;

	/** Re-check a failed conversion / re-optimize a table after this many seconds. */
	const RETRY_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Default size ceiling (MB) for the heavy operations of this tick
	 * (`ALTER TABLE … ENGINE=InnoDB`, `OPTIMIZE TABLE`).
	 *
	 * 1.9.0.6: was 512 MB. Both statements rebuild the whole table (double
	 * disk space, minutes of I/O, metadata lock at the end). On hosts where
	 * WP-Cron is request-driven (no `DISABLE_WP_CRON` + system cron) the
	 * rebuild runs inside a visitor's PHP request on shared hardware, which is
	 * exactly the kind of stall customers report as "the plugin crashed my
	 * server". So: 128 MB with a real cron, 64 MB when WP-Cron piggybacks on
	 * page loads. Both filters (`opti_behavior_engine_convert_max_mb`,
	 * `opti_behavior_optimize_max_mb`) still override this.
	 *
	 * @since 1.9.0.6
	 * @return float
	 */
	private static function default_heavy_op_max_mb() {
		$real_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		return $real_cron ? 128.0 : 64.0;
	}

	/**
	 * Daily entry point (also the one-off hook handler).
	 *
	 * @return array Summary of this tick.
	 */
	public static function run_tick() {
		global $wpdb;

		$state    = self::get_state();
		$deadline = time() + max( 5, (int) apply_filters( 'opti_behavior_db_schema_migration_time_budget', self::DEFAULT_TIME_BUDGET ) );
		$summary  = array(
			'indexes_dropped'  => 0,
			'converted'        => array(),
			'optimized'        => array(),
			'skipped_large'    => array(),
			'pending'          => 0,
			'notes'            => array(),
		);

		// 1. Redundant indexes (idempotent).
		if ( class_exists( 'Opti_Behavior_Heatmap_Database' ) && method_exists( 'Opti_Behavior_Heatmap_Database', 'drop_redundant_indexes' ) ) {
			$drop                       = Opti_Behavior_Heatmap_Database::drop_redundant_indexes( false );
			$summary['indexes_dropped'] = (int) $drop['dropped'];
		}

		$tables = self::list_plugin_tables();

		// 2. Engine conversion.
		$engine_note = self::engine_conversion_blocker();
		if ( '' !== $engine_note ) {
			$summary['notes'][] = $engine_note;
		} else {
			$max_mb = (float) apply_filters( 'opti_behavior_engine_convert_max_mb', self::default_heavy_op_max_mb() );
			foreach ( $tables as $row ) {
				if ( 'innodb' === strtolower( (string) $row['engine'] ) ) {
					continue;
				}
				$name = $row['name'];
				if ( isset( $state['convert_failed'][ $name ] ) && ( time() - (int) $state['convert_failed'][ $name ]['at'] ) < self::RETRY_AFTER ) {
					++$summary['pending'];
					continue;
				}
				if ( $row['mb'] > $max_mb ) {
					$summary['skipped_large'][] = $name;
					continue;
				}
				if ( time() >= $deadline ) {
					++$summary['pending'];
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $name is one of this plugin's own tables read back from INFORMATION_SCHEMA (list_plugin_tables, backtick-free); identifiers cannot be placeholders on WP < 6.2.
				$ok = $wpdb->query( "ALTER TABLE `{$name}` ENGINE=InnoDB" );
				if ( false === $ok ) {
					$state['convert_failed'][ $name ] = array(
						'at'    => time(),
						'error' => (string) $wpdb->last_error,
					);
					$summary['notes'][] = sprintf( '%s: %s', $name, (string) $wpdb->last_error );
				} else {
					$summary['converted'][] = $name;
					unset( $state['convert_failed'][ $name ] );
					// Never convert more than one table per tick unless it was tiny.
					if ( $row['mb'] > 32 ) {
						break;
					}
				}
			}
		}

		// 3. OPTIMIZE TABLE — reclaim space after the retention / size-cap deletes.
		$tables    = self::list_plugin_tables(); // Fresh sizes after any conversion.
		$min_free  = (float) apply_filters( 'opti_behavior_optimize_min_free_mb', 8 );
		$min_ratio = (float) apply_filters( 'opti_behavior_optimize_min_free_ratio', 0.20 );
		$max_mb    = (float) apply_filters( 'opti_behavior_optimize_max_mb', self::default_heavy_op_max_mb() );
		$forced    = isset( $state['optimize_pending'] ) && is_array( $state['optimize_pending'] ) ? $state['optimize_pending'] : array();
		foreach ( $tables as $row ) {
			$name = $row['name'];
			$want = in_array( $name, $forced, true )
				|| ( $row['free_mb'] >= $min_free && $row['mb'] > 0 && ( $row['free_mb'] / max( 0.001, $row['mb'] ) ) >= $min_ratio );
			if ( ! $want ) {
				continue;
			}
			$last = isset( $state['optimized'][ $name ] ) ? (int) $state['optimized'][ $name ] : 0;
			if ( ! in_array( $name, $forced, true ) && ( time() - $last ) < self::RETRY_AFTER ) {
				continue;
			}
			if ( $row['mb'] > $max_mb ) {
				$summary['skipped_large'][] = $name;
				continue;
			}
			if ( time() >= $deadline ) {
				++$summary['pending'];
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $name is one of this plugin's own tables read back from INFORMATION_SCHEMA (list_plugin_tables, backtick-free); identifiers cannot be placeholders on WP < 6.2.
			$wpdb->query( "OPTIMIZE TABLE `{$name}`" );
			$summary['optimized'][]     = $name;
			$state['optimized'][ $name ] = time();
			$forced                      = array_values( array_diff( $forced, array( $name ) ) );
			if ( $row['mb'] > 32 ) {
				break; // One heavy rebuild per tick.
			}
		}
		$state['optimize_pending'] = $forced;
		if ( $forced ) {
			$summary['pending'] += count( $forced );
		}

		$state['last_run']     = current_time( 'mysql' );
		$state['last_summary'] = $summary;
		update_option( self::STATE_OPTION, $state, false );

		self::report_to_registry( $summary );

		if ( $summary['pending'] > 0 && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK );
		}

		return $summary;
	}

	/**
	 * Feed the Cleanup Tasks history (Run now / cron run tracker) with what
	 * this tick actually did, instead of the generic "Nothing deleted".
	 *
	 * @param array $summary run_tick() summary.
	 * @return void
	 */
	private static function report_to_registry( array $summary ) {
		if ( ! class_exists( 'Opti_Behavior_Cleanup_Task_Registry' ) || ! method_exists( 'Opti_Behavior_Cleanup_Task_Registry', 'report_run' ) ) {
			return;
		}
		$parts = array();
		if ( $summary['indexes_dropped'] > 0 ) {
			/* translators: %d: number of duplicate indexes dropped */
			$parts[] = sprintf( _n( '%d duplicate index dropped', '%d duplicate indexes dropped', $summary['indexes_dropped'], 'opti-behavior' ), $summary['indexes_dropped'] );
		}
		if ( $summary['converted'] ) {
			/* translators: %s: comma-separated table names */
			$parts[] = sprintf( __( 'converted to InnoDB: %s', 'opti-behavior' ), implode( ', ', $summary['converted'] ) );
		}
		if ( $summary['optimized'] ) {
			/* translators: %s: comma-separated table names */
			$parts[] = sprintf( __( 'OPTIMIZE TABLE: %s', 'opti-behavior' ), implode( ', ', $summary['optimized'] ) );
		}
		if ( $summary['skipped_large'] ) {
			/* translators: %s: comma-separated table names */
			$parts[] = sprintf( __( 'left as is (above the size ceiling): %s', 'opti-behavior' ), implode( ', ', array_unique( $summary['skipped_large'] ) ) );
		}
		if ( $summary['pending'] > 0 ) {
			/* translators: %d: number of tables still waiting */
			$parts[] = sprintf( __( '%d table(s) still pending — continues automatically in a few minutes', 'opti-behavior' ), $summary['pending'] );
		}
		$did_work = $summary['indexes_dropped'] > 0 || $summary['converted'] || $summary['optimized'];
		$note     = $parts
			? ucfirst( implode( '; ', $parts ) ) . '.'
			: __( 'Schema already up to date: no duplicate index left, every table on InnoDB, nothing to optimize.', 'opti-behavior' );
		Opti_Behavior_Cleanup_Task_Registry::report_run(
			array(
				'status'           => $summary['pending'] > 0 ? 'partial' : ( $did_work ? 'completed' : 'skipped' ),
				'note'             => $note,
				'notes'            => $summary['notes'],
				'optimized_tables' => $summary['optimized'],
			)
		);
	}

	/**
	 * Ask the next tick to OPTIMIZE these tables regardless of DATA_FREE
	 * (used by the size cap right after a large delete).
	 *
	 * @param string[] $table_names Full table names.
	 * @return void
	 */
	public static function request_optimize( array $table_names ) {
		$state   = self::get_state();
		$pending = isset( $state['optimize_pending'] ) && is_array( $state['optimize_pending'] ) ? $state['optimize_pending'] : array();
		$state['optimize_pending'] = array_values( array_unique( array_merge( $pending, array_map( 'strval', $table_names ) ) ) );
		update_option( self::STATE_OPTION, $state, false );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK );
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
	 * Plugin tables with engine and sizes (MB), smallest first.
	 *
	 * @return array[] { name, engine, mb, free_mb, rows }
	 */
	public static function list_plugin_tables() {
		global $wpdb;
		$like1 = $wpdb->esc_like( $wpdb->prefix . 'optibehavior_' ) . '%';
		$like2 = $wpdb->esc_like( $wpdb->prefix . 'opti_behavior_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_count,
					(DATA_LENGTH + INDEX_LENGTH) AS bytes, DATA_FREE AS free_bytes
				 FROM INFORMATION_SCHEMA.TABLES
				 WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s AND ( TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s )
				 ORDER BY (DATA_LENGTH + INDEX_LENGTH) ASC',
				DB_NAME,
				'BASE TABLE',
				$like1,
				$like2
			),
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				// Names are quoted with backticks by the callers (ALTER / OPTIMIZE);
				// an identifier cannot be passed through a placeholder, so refuse
				// the one character that could break out of the quoting.
				if ( false !== strpos( (string) $r['name'], '`' ) ) {
					continue;
				}
				$out[] = array(
					'name'    => (string) $r['name'],
					'engine'  => (string) $r['engine'],
					'mb'      => round( (float) $r['bytes'] / MB_IN_BYTES, 2 ),
					'free_mb' => round( (float) $r['free_bytes'] / MB_IN_BYTES, 2 ),
					'rows'    => (int) $r['row_count'],
				);
			}
		}
		return $out;
	}

	/**
	 * Why engine conversion must not run on this server ('' = it may).
	 *
	 * @return string
	 */
	public static function engine_conversion_blocker() {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Database' ) || ! Opti_Behavior_Heatmap_Database::innodb_available() ) {
			return __( 'InnoDB is not available on this database server; tables keep their current engine.', 'opti-behavior' );
		}
		if ( ! self::server_supports_large_index_prefix() ) {
			return __( 'This database server is too old for InnoDB long index prefixes (needs MySQL 5.7.7+ or MariaDB 10.2.2+); tables keep their current engine.', 'opti-behavior' );
		}
		if ( ! (bool) apply_filters( 'opti_behavior_engine_convert_enabled', true ) ) {
			return __( 'Engine conversion disabled by filter.', 'opti-behavior' );
		}
		return '';
	}

	/**
	 * InnoDB DYNAMIC row format + large prefix are the default on MySQL ≥ 5.7.7
	 * and MariaDB ≥ 10.2.2; on older servers a 3072-byte secondary index fails.
	 *
	 * @return bool
	 */
	public static function server_supports_large_index_prefix() {
		global $wpdb;
		// Full server string ("10.4.28-MariaDB", "8.0.36"); db_version() strips the
		// MariaDB marker. Same value as SELECT VERSION(), without a query.
		$version = (string) $wpdb->db_server_info();
		if ( '' === $version ) {
			return false;
		}
		if ( false !== stripos( $version, 'mariadb' ) ) {
			// e.g. "10.4.28-MariaDB" or "5.5.5-10.4.28-MariaDB".
			if ( preg_match( '/(\d+\.\d+\.\d+)-MariaDB/i', $version, $m ) ) {
				return version_compare( $m[1], '10.2.2', '>=' );
			}
			return false;
		}
		if ( preg_match( '/^(\d+\.\d+\.\d+)/', $version, $m ) ) {
			return version_compare( $m[1], '5.7.7', '>=' );
		}
		return false;
	}

	/**
	 * Human-readable lines for the Cleanup Tasks panel.
	 *
	 * @return string[]
	 */
	public static function describe() {
		$lines   = array();
		$tables  = self::list_plugin_tables();
		$non_inn = 0;
		$total   = 0.0;
		$free    = 0.0;
		foreach ( $tables as $row ) {
			$total += $row['mb'];
			$free  += $row['free_mb'];
			if ( 'innodb' !== strtolower( $row['engine'] ) ) {
				++$non_inn;
			}
		}
		$lines[] = sprintf(
			/* translators: 1: table count, 2: total MB, 3: reclaimable MB */
			__( '%1$d plugin tables, %2$s MB on disk, %3$s MB reclaimable by OPTIMIZE.', 'opti-behavior' ),
			count( $tables ),
			number_format_i18n( $total, 1 ),
			number_format_i18n( $free, 1 )
		);
		$blocker = self::engine_conversion_blocker();
		if ( '' !== $blocker ) {
			$lines[] = $blocker;
		} elseif ( $non_inn > 0 ) {
			/* translators: %d: number of tables not yet on InnoDB */
			$lines[] = sprintf( __( '%d table(s) still on MyISAM — converted to InnoDB one per run, smallest first.', 'opti-behavior' ), $non_inn );
		} else {
			$lines[] = __( 'All plugin tables are on InnoDB.', 'opti-behavior' );
		}
		$lines[] = __( 'Redundant duplicate indexes are dropped only once their covering index exists; safe with an older Pro plugin still active.', 'opti-behavior' );
		return $lines;
	}
}
