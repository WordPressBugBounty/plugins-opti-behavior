<?php
/**
 * Report Scheduler Class
 *
 * Handles scheduled report management including CRUD operations,
 * schedule calculations, and cron integration.
 *
 * @package opti-behavior
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Report Scheduler Class
 *
 * Manages report schedules and their execution timing.
 *
 * @since 1.1.0
 */
class Opti_Behavior_Report_Scheduler {

	/**
	 * Core instance.
	 *
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Table name for schedules.
	 *
	 * @var string
	 */
	private $schedules_table;

	/**
	 * Table name for logs.
	 *
	 * @var string
	 */
	private $logs_table;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		global $wpdb;
		$this->core            = $core;
		$this->schedules_table = $wpdb->prefix . 'optibehavior_report_schedules';
		$this->logs_table      = $wpdb->prefix . 'optibehavior_report_logs';
	}

	/**
	 * Create database tables for report scheduling.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	public function create_tables( $charset_collate ) {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Report schedules table
		dbDelta(
			"CREATE TABLE {$this->schedules_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(255) NOT NULL DEFAULT 'My Report',
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				frequency VARCHAR(20) NOT NULL DEFAULT 'weekly',
				day_of_week TINYINT UNSIGNED DEFAULT 1,
				day_of_month TINYINT UNSIGNED DEFAULT 1,
				send_time TIME NOT NULL DEFAULT '09:00:00',
				timezone VARCHAR(50) DEFAULT '',
				report_period VARCHAR(30) NOT NULL DEFAULT 'last7days',
				include_kpis TINYINT(1) DEFAULT 1,
				include_top_pages TINYINT(1) DEFAULT 1,
				include_top_referrers TINYINT(1) DEFAULT 1,
				include_traffic_breakdown TINYINT(1) DEFAULT 1,
				include_smart_insights TINYINT(1) DEFAULT 1,
				include_funnels TINYINT(1) DEFAULT 1,
				include_heatmap_summary TINYINT(1) DEFAULT 1,
				include_geographic TINYINT(1) DEFAULT 1,
				include_recordings_stats TINYINT(1) DEFAULT 0,
				include_errors TINYINT(1) DEFAULT 0,
				include_friction TINYINT(1) DEFAULT 0,
				include_performance TINYINT(1) DEFAULT 0,
				include_broken_links TINYINT(1) DEFAULT 0,
				include_user_journeys TINYINT(1) DEFAULT 0,
				include_form_analytics TINYINT(1) DEFAULT 0,
				recipients TEXT NOT NULL,
				created_by BIGINT UNSIGNED DEFAULT NULL,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				last_sent_at DATETIME DEFAULT NULL,
				next_send_at DATETIME DEFAULT NULL,
				PRIMARY KEY (id),
				KEY enabled (enabled),
				KEY next_send_at (next_send_at),
				KEY frequency (frequency)
			) {$engine} {$charset_collate}"
		);

		// Report logs table
		dbDelta(
			"CREATE TABLE {$this->logs_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				schedule_id BIGINT UNSIGNED NOT NULL,
				sent_at DATETIME NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				recipients_count INT UNSIGNED DEFAULT 0,
				recipients_success INT UNSIGNED DEFAULT 0,
				report_period_start DATE DEFAULT NULL,
				report_period_end DATE DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				execution_time_ms INT UNSIGNED DEFAULT 0,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY schedule_id (schedule_id),
				KEY sent_at (sent_at),
				KEY status (status)
			) {$engine} {$charset_collate}"
		);
	}

	/**
	 * Get all schedules.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_schedules( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'enabled' => null,
			'limit'   => 100,
			'offset'  => 0,
			'orderby' => 'created_at',
			'order'   => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where = '1=1';
		$values = array();

		if ( null !== $args['enabled'] ) {
			$where .= ' AND enabled = %d';
			$values[] = $args['enabled'];
		}

		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		if ( ! $orderby ) {
			$orderby = 'created_at DESC';
		}

		$sql = "SELECT * FROM {$this->schedules_table} WHERE {$where} ORDER BY {$orderby} LIMIT %d OFFSET %d";
		$values[] = $args['limit'];
		$values[] = $args['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		// Parse recipients JSON
		foreach ( $results as &$schedule ) {
			$schedule['recipients'] = json_decode( $schedule['recipients'], true ) ?: array();
			$schedule = $this->normalize_schedule_row( $schedule );
		}

		return $results;
	}

	/**
	 * Get a single schedule by ID.
	 *
	 * @param int $id Schedule ID.
	 * @return array|null
	 */
	public function get_schedule( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$schedule = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->schedules_table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( $schedule ) {
			$schedule['recipients'] = json_decode( $schedule['recipients'], true ) ?: array();
			$schedule = $this->normalize_schedule_row( $schedule );
		}

		return $schedule;
	}

	/**
	 * Create a new schedule.
	 *
	 * @param array $data Schedule data.
	 * @return int|false Schedule ID or false on failure.
	 */
	public function create_schedule( $data ) {
		global $wpdb;

		$this->ensure_smart_insights_column();

		$pro_default = ( function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active() ) ? 1 : 0;

		$defaults = array(
			'name'                     => __( 'My Report', 'opti-behavior' ),
			'enabled'                  => 1,
			'frequency'                => 'weekly',
			'day_of_week'              => 1,
			'day_of_month'             => 1,
			'send_time'                => '09:00:00',
			'timezone'                 => '',
			'report_period'            => 'last7days',
			'include_kpis'             => 1,
			'include_top_pages'        => 1,
			'include_top_referrers'    => 1,
			'include_traffic_breakdown'=> 1,
			'include_smart_insights'   => 1,
			'include_funnels'          => 1,
			'include_heatmap_summary'  => 1,
			'include_geographic'       => 1,
			'include_recordings_stats' => $pro_default,
			'include_errors'           => $pro_default,
			'include_friction'         => $pro_default,
			'include_performance'      => $pro_default,
			'include_broken_links'     => $pro_default,
			'include_user_journeys'    => $pro_default,
			'include_form_analytics'   => $pro_default,
			'recipients'               => array(),
			'created_by'               => get_current_user_id(),
		);

		$data = wp_parse_args( $data, $defaults );

		$data['send_time'] = $this->sanitize_send_time( $data['send_time'] );
		$data['timezone']  = $this->sanitize_timezone( $data['timezone'] );

		// Validate recipients
		$data['recipients'] = $this->validate_recipients( $data['recipients'] );
		if ( empty( $data['recipients'] ) ) {
			return false;
		}

		// Calculate next send time
		$next_send = $this->calculate_next_send_time( $data );

		$insert_data = array(
			'name'                      => sanitize_text_field( $data['name'] ),
			'enabled'                   => (int) $data['enabled'],
			'frequency'                 => sanitize_key( $data['frequency'] ),
			'day_of_week'               => (int) $data['day_of_week'],
			'day_of_month'              => (int) $data['day_of_month'],
			'send_time'                 => sanitize_text_field( $data['send_time'] ),
			'timezone'                  => sanitize_text_field( $data['timezone'] ),
			'report_period'             => sanitize_key( $data['report_period'] ),
			'include_kpis'              => (int) $data['include_kpis'],
			'include_top_pages'         => (int) $data['include_top_pages'],
			'include_top_referrers'     => (int) $data['include_top_referrers'],
			'include_traffic_breakdown' => (int) $data['include_traffic_breakdown'],
			'include_smart_insights'    => (int) $data['include_smart_insights'],
			'include_funnels'           => (int) $data['include_funnels'],
			'include_heatmap_summary'   => (int) $data['include_heatmap_summary'],
			'include_geographic'        => (int) $data['include_geographic'],
			'include_recordings_stats'  => (int) $data['include_recordings_stats'],
			'include_errors'            => (int) $data['include_errors'],
			'include_friction'          => (int) $data['include_friction'],
			'include_performance'       => (int) $data['include_performance'],
			'include_broken_links'      => (int) $data['include_broken_links'],
			'include_user_journeys'     => (int) $data['include_user_journeys'],
			'include_form_analytics'    => (int) $data['include_form_analytics'],
			'recipients'                => wp_json_encode( $data['recipients'] ),
			'created_by'                => (int) $data['created_by'],
			'created_at'                => current_time( 'mysql' ),
			'updated_at'                => current_time( 'mysql' ),
			'next_send_at'              => $next_send,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->schedules_table, $insert_data );

		if ( $result ) {
			if ( ! empty( $insert_data['enabled'] ) ) {
				$this->ensure_worker_cron();
			}

			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Update a schedule.
	 *
	 * @param int   $id   Schedule ID.
	 * @param array $data Schedule data.
	 * @return bool
	 */
	public function update_schedule( $id, $data ) {
		global $wpdb;

		$this->ensure_smart_insights_column();

		$existing = $this->get_schedule( $id );
		if ( ! $existing ) {
			return false;
		}

		$update_data = array(
			'updated_at' => current_time( 'mysql' ),
		);

		// Fields that can be updated
		$allowed_fields = array(
			'name', 'enabled', 'frequency', 'day_of_week', 'day_of_month',
			'send_time', 'timezone', 'report_period', 'include_kpis',
			'include_top_pages', 'include_top_referrers', 'include_traffic_breakdown',
			'include_smart_insights', 'include_funnels', 'include_heatmap_summary', 'include_geographic',
			'include_recordings_stats', 'include_errors', 'include_friction',
			'include_performance', 'include_broken_links',
			'include_user_journeys', 'include_form_analytics',
		);

		foreach ( $allowed_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				if ( 'name' === $field ) {
					$update_data[ $field ] = sanitize_text_field( $data[ $field ] );
				} elseif ( in_array( $field, array( 'frequency', 'report_period' ), true ) ) {
					$update_data[ $field ] = sanitize_key( $data[ $field ] );
				} elseif ( 'send_time' === $field ) {
					$update_data[ $field ] = $this->sanitize_send_time( $data[ $field ], $existing['send_time'] );
				} elseif ( 'timezone' === $field ) {
					$update_data[ $field ] = $this->sanitize_timezone( $data[ $field ] );
				} else {
					$update_data[ $field ] = (int) $data[ $field ];
				}
			}
		}

		// Handle recipients
		if ( isset( $data['recipients'] ) ) {
			$recipients = $this->validate_recipients( $data['recipients'] );
			if ( empty( $recipients ) ) {
				return false;
			}
			$update_data['recipients'] = wp_json_encode( $recipients );
		}

		// Recalculate next send time if schedule changed
		$merged_data = array_merge( $existing, $update_data );
		if ( isset( $merged_data['recipients'] ) && is_string( $merged_data['recipients'] ) ) {
			$merged_data['recipients'] = json_decode( $merged_data['recipients'], true );
		}
		$update_data['next_send_at'] = $this->calculate_next_send_time( $merged_data );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$result = $wpdb->update(
			$this->schedules_table,
			$update_data,
			array( 'id' => $id ),
			null,
			array( '%d' )
		);

		if ( false !== $result && ! empty( $merged_data['enabled'] ) ) {
			$this->ensure_worker_cron();
		}

		return false !== $result;
	}

	/**
	 * Delete a schedule.
	 *
	 * @param int $id Schedule ID.
	 * @return bool
	 */
	public function delete_schedule( $id ) {
		global $wpdb;

		// Delete logs first
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$wpdb->delete( $this->logs_table, array( 'schedule_id' => $id ), array( '%d' ) );

		// Delete schedule
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$result = $wpdb->delete( $this->schedules_table, array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Toggle schedule enabled status.
	 *
	 * @param int  $id      Schedule ID.
	 * @param bool $enabled Enabled status.
	 * @return bool
	 */
	public function toggle_schedule( $id, $enabled ) {
		return $this->update_schedule( $id, array( 'enabled' => $enabled ? 1 : 0 ) );
	}

	/**
	 * Get schedules that are due to be sent.
	 *
	 * @return array
	 */
	public function get_due_schedules() {
		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->schedules_table}
				WHERE enabled = 1
				AND next_send_at IS NOT NULL
				AND next_send_at <= %s
				ORDER BY next_send_at ASC",
				$now
			),
			ARRAY_A
		);

		foreach ( $results as &$schedule ) {
			$schedule['recipients'] = json_decode( $schedule['recipients'], true ) ?: array();
			$schedule = $this->normalize_schedule_row( $schedule );
		}

		return $results;
	}

	/**
	 * Normalize schedule rows from legacy installs.
	 *
	 * @param array $schedule Schedule row.
	 * @return array
	 */
	private function normalize_schedule_row( $schedule ) {
		if ( is_array( $schedule ) && ! array_key_exists( 'include_smart_insights', $schedule ) ) {
			$schedule['include_smart_insights'] = 1;
		}

		return $schedule;
	}

	/**
	 * Ensure existing report-schedule tables have the Smart Insights flag.
	 *
	 * @return void
	 */
	private function ensure_smart_insights_column() {
		global $wpdb;

		$schedules_table = preg_replace( '/[^A-Za-z0-9_]/', '', $this->schedules_table );
		if ( $schedules_table !== $this->schedules_table ) {
			return;
		}

		$escaped_schedules_table = esc_sql( $schedules_table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Lightweight schema compatibility check; table name is sanitized before interpolation.
		$column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM `{$escaped_schedules_table}` LIKE %s",
				'include_smart_insights'
			)
		);
		if ( $column ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is sanitized before interpolation; legacy compatibility migration.
		$wpdb->query( "ALTER TABLE `{$escaped_schedules_table}` ADD COLUMN include_smart_insights TINYINT(1) DEFAULT 1 AFTER include_traffic_breakdown" );
	}

	/**
	 * Mark schedule as sent and calculate next send time.
	 *
	 * @param int $id Schedule ID.
	 * @return bool
	 */
	public function mark_as_sent( $id ) {
		global $wpdb;

		$schedule = $this->get_schedule( $id );
		if ( ! $schedule ) {
			return false;
		}

		$next_send = $this->calculate_next_send_time( $schedule );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$result = $wpdb->update(
			$this->schedules_table,
			array(
				'last_sent_at' => current_time( 'mysql' ),
				'next_send_at' => $next_send,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Calculate the next send time for a schedule.
	 *
	 * @param array $schedule Schedule data.
	 * @return string MySQL datetime string.
	 */
	public function calculate_next_send_time( $schedule ) {
		$tz = $this->get_schedule_timezone( $schedule );
		$now = new DateTime( 'now', $tz );

		// Parse send time
		$time_parts = explode( ':', $schedule['send_time'] );
		$hour = isset( $time_parts[0] ) ? (int) $time_parts[0] : 9;
		$minute = isset( $time_parts[1] ) ? (int) $time_parts[1] : 0;

		$next = clone $now;
		$next->setTime( $hour, $minute, 0 );

		switch ( $schedule['frequency'] ) {
			case 'daily':
				// If today's time has passed, schedule for tomorrow
				if ( $next <= $now ) {
					$next->add( new DateInterval( 'P1D' ) );
				}
				break;

			case 'weekly':
				$target_day = (int) $schedule['day_of_week'];
				$current_day = (int) $next->format( 'w' );

				// Calculate days until target day
				$days_diff = $target_day - $current_day;
				if ( $days_diff < 0 || ( 0 === $days_diff && $next <= $now ) ) {
					$days_diff += 7;
				}

				if ( $days_diff > 0 ) {
					$next->add( new DateInterval( "P{$days_diff}D" ) );
				} elseif ( $next <= $now ) {
					$next->add( new DateInterval( 'P7D' ) );
				}
				break;

			case 'monthly':
				$target_day = min( (int) $schedule['day_of_month'], 28 );
				$next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'm' ), $target_day );

				// If this month's date has passed, go to next month
				if ( $next <= $now ) {
					$next->add( new DateInterval( 'P1M' ) );
					$next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'm' ), $target_day );
				}
				break;
		}

		// Convert to site timezone for storage
		$next->setTimezone( wp_timezone() );

		return $next->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Ensure the global scheduled report worker exists.
	 *
	 * @since 1.2.7
	 * @return bool True when the worker is scheduled or already healthy.
	 */
	private function ensure_worker_cron() {
		if ( $this->core && method_exists( $this->core, 'ensure_scheduled_reports_cron' ) ) {
			return $this->core->ensure_scheduled_reports_cron();
		}

		return false;
	}

	/**
	 * Sanitize a schedule send time.
	 *
	 * @since 1.2.7
	 * @param string $send_time Submitted send time.
	 * @param string $fallback  Fallback time.
	 * @return string Normalized HH:MM:SS time.
	 */
	private function sanitize_send_time( $send_time, $fallback = '09:00:00' ) {
		$send_time = sanitize_text_field( (string) $send_time );

		if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $send_time, $matches ) ) {
			$seconds = isset( $matches[3] ) ? (int) $matches[3] : 0;

			return sprintf( '%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], $seconds );
		}

		if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', (string) $fallback, $matches ) ) {
			$seconds = isset( $matches[3] ) ? (int) $matches[3] : 0;

			return sprintf( '%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], $seconds );
		}

		return '09:00:00';
	}

	/**
	 * Sanitize a schedule timezone.
	 *
	 * @since 1.2.7
	 * @param string $timezone Submitted timezone.
	 * @return string Valid timezone identifier or empty string for site default.
	 */
	private function sanitize_timezone( $timezone ) {
		$timezone = sanitize_text_field( (string) $timezone );

		if ( '' === $timezone ) {
			return '';
		}

		try {
			new DateTimeZone( $timezone );
			return $timezone;
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Get the timezone for a schedule.
	 *
	 * @param array $schedule Schedule data.
	 * @return DateTimeZone
	 */
	private function get_schedule_timezone( $schedule ) {
		if ( ! empty( $schedule['timezone'] ) ) {
			try {
				return new DateTimeZone( $schedule['timezone'] );
			} catch ( Exception $e ) {
				// Fall through to default
			}
		}

		return wp_timezone();
	}

	/**
	 * Validate and sanitize recipients list.
	 *
	 * @param array|string $recipients Recipients list.
	 * @return array Valid email addresses.
	 */
	private function validate_recipients( $recipients ) {
		if ( is_string( $recipients ) ) {
			$recipients = array_filter( array_map( 'trim', explode( "\n", $recipients ) ) );
		}

		if ( ! is_array( $recipients ) ) {
			$recipients = array();
		}

		$valid = array();
		foreach ( $recipients as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) ) {
				$valid[] = $email;
			}
		}

		return array_unique( $valid );
	}

	/**
	 * Log a report send attempt.
	 *
	 * @param int    $schedule_id     Schedule ID.
	 * @param string $status          Status (success, failed, partial).
	 * @param array  $data            Additional log data.
	 * @return int|false Log ID or false on failure.
	 */
	public function log_send( $schedule_id, $status, $data = array() ) {
		global $wpdb;

		$log_data = array(
			'schedule_id'        => (int) $schedule_id,
			'sent_at'            => current_time( 'mysql' ),
			'status'             => sanitize_key( $status ),
			'recipients_count'   => isset( $data['recipients_count'] ) ? (int) $data['recipients_count'] : 0,
			'recipients_success' => isset( $data['recipients_success'] ) ? (int) $data['recipients_success'] : 0,
			'report_period_start'=> isset( $data['period_start'] ) ? $data['period_start'] : null,
			'report_period_end'  => isset( $data['period_end'] ) ? $data['period_end'] : null,
			'error_message'      => isset( $data['error'] ) ? sanitize_text_field( $data['error'] ) : null,
			'execution_time_ms'  => isset( $data['execution_time'] ) ? (int) $data['execution_time'] : 0,
			'created_at'         => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->logs_table, $log_data );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get logs for a schedule.
	 *
	 * @param int   $schedule_id Schedule ID (optional, 0 for all).
	 * @param array $args        Query arguments.
	 * @return array
	 */
	public function get_logs( $schedule_id = 0, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'  => 50,
			'offset' => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where = '1=1';
		$values = array();

		if ( $schedule_id > 0 ) {
			$where .= ' AND l.schedule_id = %d';
			$values[] = $schedule_id;
		}

		$sql = "SELECT l.*, s.name as schedule_name
				FROM {$this->logs_table} l
				LEFT JOIN {$this->schedules_table} s ON l.schedule_id = s.id
				WHERE {$where}
				ORDER BY l.sent_at DESC
				LIMIT %d OFFSET %d";
		$values[] = $args['limit'];
		$values[] = $args['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
	}

	/**
	 * Clear old logs.
	 *
	 * @param int $days_to_keep Number of days to keep.
	 * @return int Number of deleted rows.
	 */
	public function clear_old_logs( $days_to_keep = 90 ) {
		global $wpdb;

		$cutoff = new DateTime();
		$cutoff->sub( new DateInterval( "P{$days_to_keep}D" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->logs_table} WHERE sent_at < %s",
				$cutoff->format( 'Y-m-d H:i:s' )
			)
		);

		return $result ? $result : 0;
	}

	/**
	 * Get schedule statistics.
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->schedules_table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$enabled = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->schedules_table} WHERE enabled = %d", 1 )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$last_sent = $wpdb->get_var(
			"SELECT MAX(sent_at) FROM {$this->logs_table} WHERE status = 'success'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$success_rate = $wpdb->get_var(
			"SELECT ROUND(
				(SELECT COUNT(*) FROM {$this->logs_table} WHERE status = 'success') * 100.0 /
				NULLIF((SELECT COUNT(*) FROM {$this->logs_table}), 0),
				1
			)"
		);

		return array(
			'total'        => (int) $total,
			'enabled'      => (int) $enabled,
			'disabled'     => (int) $total - (int) $enabled,
			'last_sent'    => $last_sent,
			'success_rate' => $success_rate ? (float) $success_rate : 100.0,
		);
	}

	/**
	 * Get available frequency options.
	 *
	 * @return array
	 */
	public static function get_frequency_options() {
		return array(
			'daily'   => __( 'Daily', 'opti-behavior' ),
			'weekly'  => __( 'Weekly', 'opti-behavior' ),
			'monthly' => __( 'Monthly', 'opti-behavior' ),
		);
	}

	/**
	 * Get available period options.
	 *
	 * @return array
	 */
	public static function get_period_options() {
		return array(
			'yesterday'  => __( 'Yesterday', 'opti-behavior' ),
			'last7days'  => __( 'Last 7 Days', 'opti-behavior' ),
			'last30days' => __( 'Last 30 Days', 'opti-behavior' ),
			'thismonth'  => __( 'This Month', 'opti-behavior' ),
			'lastmonth'  => __( 'Last Month', 'opti-behavior' ),
		);
	}

	/**
	 * Get day of week options.
	 *
	 * @return array
	 */
	public static function get_day_of_week_options() {
		return array(
			0 => __( 'Sunday', 'opti-behavior' ),
			1 => __( 'Monday', 'opti-behavior' ),
			2 => __( 'Tuesday', 'opti-behavior' ),
			3 => __( 'Wednesday', 'opti-behavior' ),
			4 => __( 'Thursday', 'opti-behavior' ),
			5 => __( 'Friday', 'opti-behavior' ),
			6 => __( 'Saturday', 'opti-behavior' ),
		);
	}
}
