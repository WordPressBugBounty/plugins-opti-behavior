<?php
/**
 * Funnel Database Schema Manager
 *
 * Handles creation and management of funnel-related database tables.
 *
 * @package opti-behavior
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Funnel Database Class
 */
class Opti_Behavior_Funnel_Database {

	/**
	 * Create funnel database tables
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Table for funnel definitions
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// Table for funnel step tracking
		$table_funnel_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';

		$sql_funnels = "CREATE TABLE {$table_funnels} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text,
			steps longtext NOT NULL COMMENT 'JSON array of step definitions',
			status varchar(20) DEFAULT 'active',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_tracking = "CREATE TABLE {$table_funnel_tracking} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			session_id varchar(255) NOT NULL,
			funnel_id bigint(20) NOT NULL,
			current_step int(11) DEFAULT 0,
			max_step_reached int(11) DEFAULT 0,
			completed tinyint(1) DEFAULT 0,
			entry_time datetime DEFAULT CURRENT_TIMESTAMP,
			last_activity datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			completion_time datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY funnel_id (funnel_id),
			KEY completed (completed),
			KEY entry_time (entry_time)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_funnels );
		dbDelta( $sql_tracking );

		// Do NOT create default funnel - users should define their own funnels
	}
}
