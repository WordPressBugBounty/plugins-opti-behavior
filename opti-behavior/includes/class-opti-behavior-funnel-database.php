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
			source varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'manual | auto | discovered',
			recipe_id varchar(64) DEFAULT NULL COMMENT 'Auto-builder recipe provenance',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY source (source),
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

	/**
	 * Compute the canonical step signature of a funnel.
	 *
	 * The signature is the dedupe key used by the auto-builder: two funnels
	 * whose ordered ( match_type, url_pattern ) pairs are identical describe the
	 * same journey, regardless of their name, description or step labels.
	 *
	 * Formula (spec.md §3.5 — must stay byte-for-byte stable, it is persisted
	 * indirectly through dedupe decisions):
	 *
	 *     sha1( implode( '|', array_map( fn( $s ) => $s['match_type'] . ':' . $s['url_pattern'], $steps ) ) )
	 *
	 * No normalization (no trim, no case folding, no trailing-slash fixing) is
	 * applied on purpose: the generated patterns come from a single generator
	 * and normalizing here would silently collapse two genuinely different
	 * user-authored funnels.
	 *
	 * @since 1.8.4
	 *
	 * @param array|string $steps Steps array (step shape: `match_type`,
	 *                            `url_pattern`) or the raw JSON string as
	 *                            stored in `opti_behavior_funnels.steps`.
	 * @return string 40-char sha1 hash, or '' when no usable step was supplied.
	 */
	public static function get_steps_signature( $steps ) {
		if ( is_string( $steps ) ) {
			$steps = json_decode( $steps, true );
		}

		if ( ! is_array( $steps ) || empty( $steps ) ) {
			return '';
		}

		$parts = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$match_type  = isset( $step['match_type'] ) ? (string) $step['match_type'] : '';
			$url_pattern = isset( $step['url_pattern'] ) ? (string) $step['url_pattern'] : '';
			$parts[]     = $match_type . ':' . $url_pattern;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return sha1( implode( '|', $parts ) );
	}
}
