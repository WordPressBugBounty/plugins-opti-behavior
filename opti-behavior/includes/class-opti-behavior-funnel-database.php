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
	 * Schema version of the two funnel tables. Bump it whenever
	 * create_tables() changes a column, a key or a table option.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option holding the installed funnel schema version (not autoloaded:
	 * only read by the database setup).
	 */
	const DB_VERSION_OPTION = 'opti_behavior_funnel_db_version';

	/**
	 * Create or update the funnel tables only when needed: the schema version
	 * changed or one of the two tables is missing.
	 *
	 * Called from Opti_Behavior_Heatmap_Database::setup_locked() (activation,
	 * plugin update, admin self-heal). Never called on a front-end or AJAX
	 * request: dbDelta() used to run DESCRIBE + SHOW INDEX on both tables on
	 * every request of the site.
	 *
	 * @return bool True when dbDelta() ran.
	 */
	public static function maybe_create_tables() {
		global $wpdb;

		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			$missing = false;
			foreach ( array( 'opti_behavior_funnels', 'opti_behavior_funnel_tracking' ) as $suffix ) {
				$table = $wpdb->prefix . $suffix;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check during setup only.
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
					$missing = true;
					break;
				}
			}
			if ( ! $missing ) {
				return false;
			}
		}

		self::create_tables();
		return true;
	}

	/**
	 * Create funnel database tables
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$engine          = Opti_Behavior_Heatmap_Database::engine_clause();

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
		) {$engine} {$charset_collate};";

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
		) {$engine} {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_funnels );
		dbDelta( $sql_tracking );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

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
