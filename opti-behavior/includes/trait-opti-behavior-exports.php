<?php
/**
 * Export Implementations Trait
 *
 * Provides CSV, JSON and SQL export functionality for analytics data.
 *
 * @package Opti_Behavior
 * @copyright 2025 OptiUser
 * @version 1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Export queries use allowlisted identifiers and prepared values; WP 5.8 support prevents %i identifiers.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy export tables/date columns are selected from internal allowlists before interpolation.

/**
 * Export Implementations Trait
 *
 * Provides methods for exporting analytics data in CSV, JSON and SQL formats.
 *
 * @since 1.0.0
 */
trait Opti_Behavior_Exports_Trait {

	/**
	 * Get the list of tables to export based on available features.
	 *
	 * @return array
	 */
	private function get_export_tables() {
		global $wpdb;
		$tables = array(
			'optibehavior_pages',
			'optibehavior_visitors',
			'optibehavior_sessions',
			'optibehavior_pageviews',
			'optibehavior_events',
			'optibehavior_recordings',
			'optibehavior_referrers',
			'optibehavior_outbound_clicks',
		);

		// Pro / Feature tables
		$pro_tables = array(
			'optibehavior_errors',
			'optibehavior_error_types',
			'optibehavior_friction',
			'optibehavior_performance',
			'optibehavior_broken_links',
			'optibehavior_journey_groups',
			'opti_behavior_funnels',
			'opti_behavior_funnel_tracking',
			'optibehavior_ab_tests',
			'optibehavior_ab_variants',
			'optibehavior_ab_goals',
			'optibehavior_ab_impressions',
			'optibehavior_ab_conversions',
		);

		foreach ( $pro_tables as $t ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) ) ) {
				$tables[] = $t;
			}
		}

		return $tables;
	}

	/**
	 * Get the date column for a given table.
	 *
	 * @param string $table Table name without prefix.
	 * @return string|null
	 */
	private function get_table_date_column( $table ) {
		$map = array(
			'optibehavior_pages'           => 'insert_at',
			'optibehavior_visitors'        => 'last_visit',
			'optibehavior_sessions'        => 'start_time',
			'optibehavior_pageviews'       => 'view_time',
			'optibehavior_events'          => 'insert_at',
			'optibehavior_recordings'      => 'start_time',
			'optibehavior_referrers'       => 'created_at',
			'optibehavior_outbound_clicks' => 'created_at',
			'optibehavior_errors'          => 'occurred_at',
			'optibehavior_error_types'     => 'last_seen',
			'optibehavior_friction'        => 'occurred_at',
			'optibehavior_performance'     => 'measured_at',
			'optibehavior_broken_links'    => 'last_detected',
			'opti_behavior_funnels'        => 'created_at',
			'opti_behavior_funnel_tracking' => 'entry_time',
			'optibehavior_ab_tests'        => 'created_at',
			'optibehavior_ab_impressions'  => 'created_at',
			'optibehavior_ab_conversions'  => 'created_at',
		);
		return isset( $map[ $table ] ) ? $map[ $table ] : null;
	}

	/**
	 * Stream CSV export of analytics tables.
	 *
	 * @since 1.0.0
	 */
	private function export_analytics_csv_impl( $start_date = null, $end_date = null, $exclude_spam = false ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="opti-behavior-analytics-export-' . current_time( 'Y-m-d' ) . '.csv"' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
		}

		global $wpdb;

		$tables = $this->get_export_tables();

		foreach ( $tables as $t ) {
			$query = $this->build_export_select_query( $t, $start_date, $end_date, $exclude_spam );
			if ( '' === $query ) {
				continue;
			}

			$rows = $wpdb->get_results( $query, ARRAY_A );

			if ( ! empty( $rows ) ) {
				$this->stream_export_csv_line( array( strtoupper( $t ) ) );
				$this->stream_export_csv_line( array_keys( $rows[0] ) );
				foreach ( $rows as $r ) {
					$this->stream_export_csv_line( array_values( $r ) );
				}
				$this->stream_export_csv_line( array() ); // Empty line between tables.
			}
		}
	}

	/**
	 * Stream JSON export of analytics tables.
	 *
	 * @since 1.0.4
	 */
	private function export_analytics_json_impl( $start_date = null, $end_date = null, $exclude_spam = false ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json' );
			header( 'Content-Disposition: attachment; filename="opti-behavior-analytics-export-' . current_time( 'Y-m-d' ) . '.json"' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
		}

		global $wpdb;
		$tables = $this->get_export_tables();
		$data   = array(
			'export_date' => current_time( 'mysql' ),
			'filters'     => array(
				'start_date'   => $start_date,
				'end_date'     => $end_date,
				'exclude_spam' => $exclude_spam,
			),
			'tables'      => array(),
		);

		foreach ( $tables as $t ) {
			$query = $this->build_export_select_query( $t, $start_date, $end_date, $exclude_spam );
			if ( '' === $query ) {
				continue;
			}

			$data['tables'][ $t ] = $wpdb->get_results( $query, ARRAY_A );
		}

		echo wp_json_encode( $data );
	}

	/**
	 * Stream SQL export of analytics tables.
	 *
	 * @since 1.0.0
	 */
	private function export_analytics_sql_impl( $start_date = null, $end_date = null, $exclude_spam = false ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="opti-behavior-analytics-export-' . current_time( 'Y-m-d' ) . '.sql"' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
		}

		global $wpdb;
		$prefix = $wpdb->prefix;
		$tables = $this->get_export_tables();

		$this->stream_export_sql_line( "-- Opti-Behavior Analytics SQL Export\n" );
		$this->stream_export_sql_line( '-- Date: ' . current_time( 'mysql' ) . "\n\n" );
		$this->stream_export_sql_line( "BEGIN;\n" );

		foreach ( $tables as $t ) {
			$table_identifier = $this->get_export_table_identifier( $t );
			$query            = $this->build_export_select_query( $t, $start_date, $end_date, $exclude_spam );
			if ( '' === $table_identifier || '' === $query ) {
				continue;
			}

			$rows = $wpdb->get_results( $query, ARRAY_A );

			if ( ! empty( $rows ) ) {
				$this->stream_export_sql_line( "\n-- Table: {$prefix}{$t}\n" );
				foreach ( $rows as $r ) {
					$cols = array_map(
						function ( $c ) {
							return $this->get_export_column_identifier( $c );
						},
						array_keys( $r )
					);
					$vals = array_map(
						function ( $v ) use ( $wpdb ) {
							return is_null( $v ) ? 'NULL' : ( "'" . esc_sql( $v ) . "'" );
						},
						array_values( $r )
					);
					$this->stream_export_sql_line( 'INSERT INTO ' . $table_identifier . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ");\n" );
				}
			}
		}

		$this->stream_export_sql_line( "\nCOMMIT;\n" );
	}

	/**
	 * Build an export SELECT query from allowlisted identifiers and prepared values.
	 *
	 * @param string      $table Table suffix.
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date End date.
	 * @param bool        $exclude_spam Exclude spam sessions.
	 * @return string
	 */
	private function build_export_select_query( $table, $start_date = null, $end_date = null, $exclude_spam = false ) {
		global $wpdb;

		$table_identifier = $this->get_export_table_identifier( $table );
		if ( '' === $table_identifier ) {
			return '';
		}

		$query = "SELECT * FROM {$table_identifier}";
		$where = array();

		$date_col = $this->get_table_date_column( $table );
		if ( $date_col ) {
			$date_identifier = $this->get_export_column_identifier( $date_col );
			if ( '' !== $date_identifier ) {
				if ( $start_date ) {
					$where[] = $wpdb->prepare( "{$date_identifier} >= %s", $start_date );
				}
				if ( $end_date ) {
					$where[] = $wpdb->prepare( "{$date_identifier} <= %s", $end_date );
				}
			}
		}

		if ( $exclude_spam && 'optibehavior_sessions' === $table ) {
			$where[] = $wpdb->prepare( '`traffic_type` = %s', 'human' );
		}

		if ( ! empty( $where ) ) {
			$query .= ' WHERE ' . implode( ' AND ', $where );
		}

		return $query;
	}

	/**
	 * Return a backticked full table identifier after validating the suffix.
	 *
	 * @param string $table Table suffix.
	 * @return string
	 */
	private function get_export_table_identifier( $table ) {
		global $wpdb;

		$tables = array_merge(
			array(
				'optibehavior_pages',
				'optibehavior_visitors',
				'optibehavior_sessions',
				'optibehavior_pageviews',
				'optibehavior_events',
				'optibehavior_recordings',
				'optibehavior_referrers',
				'optibehavior_outbound_clicks',
			),
			array(
				'optibehavior_errors',
				'optibehavior_error_types',
				'optibehavior_friction',
				'optibehavior_performance',
				'optibehavior_broken_links',
				'optibehavior_journey_groups',
				'opti_behavior_funnels',
				'opti_behavior_funnel_tracking',
				'optibehavior_ab_tests',
				'optibehavior_ab_variants',
				'optibehavior_ab_goals',
				'optibehavior_ab_impressions',
				'optibehavior_ab_conversions',
			)
		);

		if ( ! in_array( $table, $tables, true ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return '';
		}

		$full_table = $wpdb->prefix . $table;
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $full_table ) ) {
			return '';
		}

		return '`' . $full_table . '`';
	}

	/**
	 * Return a backticked column identifier after validation.
	 *
	 * @param string $column Column name.
	 * @return string
	 */
	private function get_export_column_identifier( $column ) {
		if ( ! is_string( $column ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $column ) ) {
			return '';
		}

		return '`' . $column . '`';
	}

	/**
	 * Stream one CSV row without PHP filesystem streams.
	 *
	 * @param array $fields Row fields.
	 * @return void
	 */
	private function stream_export_csv_line( $fields ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw CSV attachment; HTML escaping would corrupt the download.
		echo $this->build_export_csv_line( $fields );
	}

	/**
	 * Build one CSV row.
	 *
	 * @param array $fields Row fields.
	 * @return string
	 */
	private function build_export_csv_line( $fields ) {
		$escaped = array();
		foreach ( (array) $fields as $field ) {
			if ( is_bool( $field ) ) {
				$field = $field ? '1' : '0';
			}
			if ( is_array( $field ) || is_object( $field ) ) {
				$field = wp_json_encode( $field );
			}
			$field = (string) $field;
			if ( preg_match( '/[,"\\\\\r\n\t ]/', $field ) ) {
				$field = '"' . str_replace( '"', '""', $field ) . '"';
			}
			$escaped[] = $field;
		}

		return implode( ',', $escaped ) . "\n";
	}

	/**
	 * Stream generated SQL download content.
	 *
	 * @param string $line SQL line.
	 * @return void
	 */
	private function stream_export_sql_line( $line ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw SQL attachment; HTML escaping would corrupt importable SQL.
		echo $line;
	}
}
