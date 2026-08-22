<?php
/**
 * Maintenance Trait
 *
 * Provides database maintenance and optimization methods.
 *
 * @package Opti_Behavior
 * @copyright 2025 OptiUser
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * Maintenance Trait
 *
 * Provides methods for database maintenance, cleanup, and optimization.
 *
 * @since 1.0.0
 */
trait Opti_Behavior_Maintenance_Trait {

	/**
	 * Smart Cleanup service instance.
	 *
	 * @since 1.2.7
	 * @var Opti_Behavior_Smart_Cleanup_Service|null
	 */
	private $smart_cleanup_service = null;

	/**
	 * Delete all analytics data across plugin tables.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function delete_all_analytics_data_impl() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '" . $prefix . "optibehavior_%'" );
		if ( ! is_array( $tables ) ) {
			$tables = array();
		}

		// Also get tables with opti_behavior_ pattern (for funnels and other features)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$tables_underscore = $wpdb->get_col( "SHOW TABLES LIKE '" . $prefix . "opti\_behavior\_%'" );
		if ( is_array( $tables_underscore ) ) {
			$tables = array_merge( $tables, $tables_underscore );
		}

		foreach ( array( 'optibehavior_norm', 'optibehavior_unread' ) as $legacy ) {
			$full = $prefix . $legacy;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB access.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			if ( $exists ) {
				$tables[] = $full;
			}
		}

		$tables = array_values(
			array_unique(
				array_filter(
					$tables,
					function ( $t ) use ( $prefix ) {
						return is_string( $t ) && (
							strpos( $t, $prefix . 'optibehavior_' ) === 0 ||
							strpos( $t, $prefix . 'opti_behavior_' ) === 0
						);
					}
				)
			)
		);

		foreach ( $tables as $table ) {
			$safe_table = esc_sql( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$wpdb->query( "TRUNCATE TABLE `" . $safe_table . "`" );
		}

		$like_key     = $wpdb->esc_like( '_transient_optibehavior_top_users_' ) . '%';
		$like_timeout = $wpdb->esc_like( '_transient_timeout_optibehavior_top_users_' ) . '%';
		$options_table = esc_sql( $wpdb->prefix . 'options' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . $options_table . " WHERE option_name LIKE %s OR option_name LIKE %s",
				$like_key,
				$like_timeout
			)
		);

		// Delete file storage data as well
		$core = isset( $this->heatmap ) ? $this->heatmap : Opti_Behavior_Heatmap_Core::get_instance();
		$file_storage = $core->get_file_storage();
		if ( $file_storage ) {
			$debug_manager = $core->get_debug_manager();
			$debug_manager->log( 'Deleting file storage data', 'info', 'maintenance' );

			// Get storage directory
			$upload_dir = wp_upload_dir();
			$storage_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';

			if ( file_exists( $storage_dir ) ) {
				// Delete all files in events and recordings directories
				$this->delete_directory_contents( $storage_dir . 'events/' );
				$this->delete_directory_contents( $storage_dir . 'recordings/' );

				// Delete ALL heatmap hash folders (url_hash directories containing clicks/moves/scrolls)
				// These folders have MD5 hash names like 'a1b2c3d4e5f6...'
				$this->delete_heatmap_hash_folders( $storage_dir );

				// Also delete heatmaps subfolder and its hash folders if it exists
				if ( file_exists( $storage_dir . 'heatmaps/' ) ) {
					$this->delete_heatmap_hash_folders( $storage_dir . 'heatmaps/' );
					$this->delete_directory_contents( $storage_dir . 'heatmaps/' );
				}

				$debug_manager->log( 'File storage data deleted (including heatmap hash folders)', 'info', 'maintenance' );

				// Deletion hook (spec P4.1): every heatmap session file is gone
				// (tables were truncated above too) - zero the adaptive-mode
				// total-files estimate so the dashboard starts from live mode.
				if ( class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
					Opti_Behavior_Heatmap_Storage::get_instance()->set_total_files_estimate( 0 );
				}
			}
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All opti-behavior Analytics tables and files were emptied successfully.', 'opti-behavior' ) . '</p></div>';
			}
		);
	}

	/**
	 * Delete logs within a date range.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @param string $start Start date.
	 * @param string $end   End date.
	 */
	private function delete_logs_in_date_range_impl( $start, $end ) {
		global $wpdb;
		if ( ! $start || ! $end ) {
			return;
		}
		$start = gmdate( 'Y-m-d 00:00:00', strtotime( $start ) );
		$end   = gmdate( 'Y-m-d 23:59:59', strtotime( $end ) );

		$events_table     = esc_sql( $wpdb->prefix . 'optibehavior_events' );
		$pageviews_table  = esc_sql( $wpdb->prefix . 'optibehavior_pageviews' );
		$sessions_table   = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$recordings_table = esc_sql( $wpdb->prefix . 'optibehavior_recordings' );
		$referrers_table  = esc_sql( $wpdb->prefix . 'optibehavior_referrers' );
		$outbound_table   = esc_sql( $wpdb->prefix . 'optibehavior_outbound_clicks' );

		$wpdb->query( $wpdb->prepare( "DELETE FROM " . $events_table . " WHERE insert_at BETWEEN %s AND %s", $start, $end ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . $pageviews_table . " WHERE view_time BETWEEN %s AND %s", $start, $end ) );

		// File/DB synchro (2026-08-13): this date-range purge deletes sessions
		// directly (not via cascade_delete_sessions), so the heatmap interaction
		// files (clicks/moves/scrolls JSON) of the purged sessions would be left
		// behind as orphans and keep inflating the file-derived heatmap surfaces
		// above the canonical pageview-session count. Resolve + delete those files
		// (and mark the affected heatmap pages stale + flush caches) BEFORE the
		// session_pages rows are removed, since file resolution needs page_id.
		$purge_session_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM " . $sessions_table . " WHERE start_time BETWEEN %s AND %s",
			$start,
			$end
		) );
		if ( ! empty( $purge_session_ids )
			&& method_exists( $this, 'get_smart_cleanup_service' ) ) {
			$_cleanup_svc = $this->get_smart_cleanup_service();
			if ( $_cleanup_svc && method_exists( $_cleanup_svc, 'sync_heatmap_files_for_deleted_sessions' ) ) {
				$_cleanup_svc->sync_heatmap_files_for_deleted_sessions( $purge_session_ids );
			}
		}

		// Bug 4: Delete session-linked tables that lack a direct date column via JOIN.
		// Must run BEFORE sessions are deleted so the JOIN resolves correctly.
		$session_pages_tbl = esc_sql( $wpdb->prefix . 'optibehavior_session_pages' );
		$friction_tbl      = esc_sql( $wpdb->prefix . 'optibehavior_friction' );
		$errors_tbl        = esc_sql( $wpdb->prefix . 'optibehavior_errors' );
		$performance_tbl   = esc_sql( $wpdb->prefix . 'optibehavior_performance' );
		foreach ( array( $session_pages_tbl, $friction_tbl, $errors_tbl, $performance_tbl ) as $_dr_tbl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$_dr_tbl_name    = str_replace( esc_sql( $wpdb->prefix ), '', $_dr_tbl );
			$_dr_tbl_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $_dr_tbl_name ) );
			if ( $_dr_tbl_exists ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE t FROM " . $_dr_tbl . " t
					INNER JOIN " . $sessions_table . " s ON s.id = t.session_id
					WHERE s.start_time BETWEEN %s AND %s",
					$start,
					$end
				) );
			}
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM " . $sessions_table . " WHERE start_time BETWEEN %s AND %s", $start, $end ) );

		// Bug 6: Collect recording file paths from DB before deleting DB rows,
		// so files are deleted by session start_time (not filesystem mtime).
		$recording_file_paths = $wpdb->get_col( $wpdb->prepare(
			"SELECT file_path FROM " . $recordings_table . " WHERE start_time BETWEEN %s AND %s AND file_path IS NOT NULL AND file_path != ''",
			$start,
			$end
		) );
		if ( ! empty( $recording_file_paths ) ) {
			$rec_upload_dir = wp_upload_dir();
			$rec_base_dir   = trailingslashit( $rec_upload_dir['basedir'] ) . 'opti-behavior-data/';
			foreach ( $recording_file_paths as $_rec_path ) {
				if ( ! empty( $_rec_path ) ) {
					$_rec_full = $rec_base_dir . ltrim( $_rec_path, '/\\' );
					if ( file_exists( $_rec_full ) ) {
						wp_delete_file( $_rec_full );
					}
				}
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . $recordings_table . " WHERE start_time BETWEEN %s AND %s", $start, $end ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM " . $referrers_table . " WHERE created_at BETWEEN %s AND %s", $start, $end ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . $outbound_table . " WHERE created_at BETWEEN %s AND %s", $start, $end ) );

		$form_interactions_table = esc_sql( $wpdb->prefix . 'optibehavior_form_interactions' );
		$form_submissions_table  = esc_sql( $wpdb->prefix . 'optibehavior_form_submissions' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . $form_interactions_table . " WHERE created_at BETWEEN %s AND %s", $start, $end ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . $form_submissions_table . " WHERE created_at BETWEEN %s AND %s", $start, $end ) );

		$like_key      = $wpdb->esc_like( '_transient_optibehavior_top_users_' ) . '%';
		$like_timeout  = $wpdb->esc_like( '_transient_timeout_optibehavior_top_users_' ) . '%';
		$options_table = esc_sql( $wpdb->prefix . 'options' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . $options_table . " WHERE option_name LIKE %s OR option_name LIKE %s",
				$like_key,
				$like_timeout
			)
		);

		// Bug 6: Only delete events files by filemtime; recordings are handled above via DB file_path.
		$core = isset( $this->heatmap ) ? $this->heatmap : Opti_Behavior_Heatmap_Core::get_instance();
		$file_storage = $core->get_file_storage();
		if ( $file_storage ) {
			$this->delete_file_storage_in_date_range( $start, $end );
		}

		// Bug 5: Remove orphaned visitor records after session deletion.
		$this->delete_orphaned_visitors();

		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Logs within the selected date range were deleted from database and files.', 'opti-behavior' ) . '</p></div>';
			}
		);
	}

	/**
	 * Delete file storage data within a date range.
	 *
	 * @since 1.0.0
	 * @param string $start Start date in MySQL format.
	 * @param string $end   End date in MySQL format.
	 */
	private function delete_file_storage_in_date_range( $start, $end ) {
		$upload_dir = wp_upload_dir();
		$storage_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';

		if ( ! file_exists( $storage_dir ) ) {
			return;
		}

		$start_timestamp = strtotime( $start );
		$end_timestamp   = strtotime( $end );

		// Bug 6: Only delete events files here; recordings are deleted via DB file_path records
		// in delete_logs_in_date_range_impl() to ensure date accuracy.
		foreach ( array( 'events' ) as $type ) {
			$type_dir = $storage_dir . $type . '/';
			if ( ! file_exists( $type_dir ) ) {
				continue;
			}

			$this->delete_files_by_date_range( $type_dir, $start_timestamp, $end_timestamp );
		}
	}

	/**
	 * Delete files within a date range by checking file modification time.
	 *
	 * @since 1.0.0
	 * @param string $dir            Directory to scan.
	 * @param int    $start_timestamp Start timestamp.
	 * @param int    $end_timestamp   End timestamp.
	 */
	private function delete_files_by_date_range( $dir, $start_timestamp, $end_timestamp ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		$deleted_count = 0;
		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				$file_time = $item->getMTime();
				if ( $file_time >= $start_timestamp && $file_time <= $end_timestamp ) {
					wp_delete_file( $item->getRealPath() );
					$deleted_count++;
				}
			}
		}

		// Log using core debug manager
		$core = isset( $this->heatmap ) ? $this->heatmap : Opti_Behavior_Heatmap_Core::get_instance();
		$debug_manager = $core->get_debug_manager();
		$debug_manager->log( "Deleted $deleted_count files from $dir within date range", 'debug', 'maintenance' );
	}

	/**
	 * Recursively delete all contents of a directory.
	 *
	 * @since 1.0.0
	 * @param string $dir Directory path to clear.
	 */
	private function delete_directory_contents( $dir ) {
		if ( ! file_exists( $dir ) || ! is_dir( $dir ) ) {
			return;
		}

		// Initialize WP_Filesystem
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				$wp_filesystem->rmdir( $item->getRealPath() );
			} else {
				wp_delete_file( $item->getRealPath() );
			}
		}

		// Log using core debug manager
		$core = isset( $this->heatmap ) ? $this->heatmap : Opti_Behavior_Heatmap_Core::get_instance();
		$debug_manager = $core->get_debug_manager();
		$debug_manager->log( 'Deleted directory contents: ' . $dir, 'debug', 'maintenance' );
	}

	/**
	 * Delete all heatmap hash folders in the storage directory.
	 *
	 * Heatmap data is stored in folders named with MD5 hashes of URLs.
	 * Structure: opti-behavior-data/{url_hash}/clicks/
	 *            opti-behavior-data/{url_hash}/moves/
	 *            opti-behavior-data/{url_hash}/scrolls/
	 *
	 * @since 1.0.0
	 * @param string $storage_dir Base storage directory path.
	 */
	private function delete_heatmap_hash_folders( $storage_dir ) {
		if ( ! file_exists( $storage_dir ) || ! is_dir( $storage_dir ) ) {
			return;
		}

		// Initialize WP_Filesystem
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$deleted_count = 0;
		$items = scandir( $storage_dir );

		foreach ( $items as $item ) {
			// Skip special entries and known non-hash directories
			if ( $item === '.' || $item === '..' || $item === 'index.php' || $item === '.htaccess' ) {
				continue;
			}

			// Skip known named directories (events, recordings)
			if ( in_array( $item, array( 'events', 'recordings' ), true ) ) {
				continue;
			}

			$item_path = $storage_dir . $item;

			// Only process directories (hash folders are directories)
			if ( is_dir( $item_path ) ) {
				// Check if this looks like a hash folder (32 character hex string = MD5 hash)
				// Or any other folder that contains heatmap data (clicks, moves, scrolls subdirs)
				$is_hash_folder = ( strlen( $item ) === 32 && ctype_xdigit( $item ) );
				$has_heatmap_subdirs = is_dir( $item_path . '/clicks' ) ||
				                       is_dir( $item_path . '/moves' ) ||
				                       is_dir( $item_path . '/scrolls' );

				if ( $is_hash_folder || $has_heatmap_subdirs ) {
					// Recursively delete the entire hash folder and its contents
					$this->delete_directory_recursive( $item_path );
					$deleted_count++;
				}
			}
		}

		// Log the deletion
		$core = isset( $this->heatmap ) ? $this->heatmap : Opti_Behavior_Heatmap_Core::get_instance();
		$debug_manager = $core->get_debug_manager();
		$debug_manager->log( "Deleted $deleted_count heatmap hash folders from storage", 'info', 'maintenance' );
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @since 1.0.0
	 * @param string $dir Directory path to delete.
	 * @return bool True on success, false on failure.
	 */
	private function delete_directory_recursive( $dir ) {
		if ( ! file_exists( $dir ) || ! is_dir( $dir ) ) {
			return true;
		}

		// Initialize WP_Filesystem
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				$wp_filesystem->rmdir( $item->getRealPath() );
			} else {
				wp_delete_file( $item->getRealPath() );
			}
		}

		// Finally, remove the directory itself
		return $wp_filesystem->rmdir( $dir );
	}

	/**
	 * Conditionally ensure database indexes for performance.
	 *
	 * Runs at most once per day.
	 *
	 * @since 1.0.0
	 */
	public function maybe_ensure_db_indexes_impl() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$flag = get_option( 'optibehavior_indexes_last_checked' );
		if ( $flag && time() - intval( $flag ) < DAY_IN_SECONDS ) {
			return;
		}
		update_option( 'optibehavior_indexes_last_checked', time(), false );
		$this->ensure_db_indexes_impl();
	}

	/**
	 * Create missing database indexes.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function ensure_db_indexes_impl() {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$defs   = array(
			$prefix . "optibehavior_events"    => array(
				'idx_events_page_event_time' => 'CREATE INDEX idx_events_page_event_time ON %s (page_id2, event, insert_at)',
				'idx_events_event_time'      => 'CREATE INDEX idx_events_event_time ON %s (event, insert_at)',
			),
			$prefix . "optibehavior_pages"     => array(
				'idx_pages_url' => 'CREATE INDEX idx_pages_url ON %s (url(191))',
			),
			$prefix . "optibehavior_pageviews" => array(
				'idx_pv_url'        => 'CREATE INDEX idx_pv_url ON %s (url(191))',
				'idx_pv_session_id' => 'CREATE INDEX idx_pv_session_id ON %s (session_id)',
				'idx_pv_view_time'  => 'CREATE INDEX idx_pv_view_time ON %s (view_time)',
			),
			$prefix . "optibehavior_sessions"  => array(
				'idx_sessions_start_time' => 'CREATE INDEX idx_sessions_start_time ON %s (start_time)',
				'idx_sessions_visitor'    => 'CREATE INDEX idx_sessions_visitor ON %s (visitor_id)',
			),
		);
		// PRODUCTION SAFETY (C2-3): CREATE INDEX on a multi-million-row table
		// measured 7-12+ minutes at customer scale — never run it inline in an
		// admin request. Large-table builds are deferred to the shared
		// heavy-migrations background worker (all defs below are part of its
		// managed index list); only small (fast) tables build inline here.
		$db = null;
		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			$core_instance = Opti_Behavior_Heatmap_Core::get_instance();
			if ( $core_instance && method_exists( $core_instance, 'get_database' ) ) {
				$db = $core_instance->get_database();
			}
		}

		foreach ( $defs as $table => $idxs ) {
			foreach ( $idxs as $name => $tpl ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB access.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
						$table,
						$name
					)
				);
				if ( (int) $exists === 0 ) {
					// Missing index on a LARGE table: defer to the background
					// worker instead of blocking this admin request with DDL.
					if ( $db && method_exists( $db, 'is_large_table' ) && $db->is_large_table( $table ) ) {
						if ( method_exists( $db, 'schedule_heavy_migrations' ) ) {
							$db->schedule_heavy_migrations();
						}
						continue;
					}
					$safe_table = esc_sql( $table );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query built from prepared parts.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( sprintf( $tpl, $safe_table ) );
				}
			}
		}
	}

	/**
	 * Conditionally backfill referrers from entry page.
	 *
	 * Runs at most once per day.
	 *
	 * @since 1.0.0
	 */
	public function maybe_backfill_referrers_impl() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$flag = get_option( 'optibehavior_referrers_last_backfill' );
		if ( $flag && time() - intval( $flag ) < ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ) ) {
			return;
		}
		update_option( 'optibehavior_referrers_last_backfill', time(), false );
		$this->backfill_referrers_from_entry_page_impl( 30, 2000 );
	}

	/**
	 * Backfill utm_source from entry_page query string.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @param int $days  Number of days to look back.
	 * @param int $limit Maximum number of rows to process.
	 */
	private function backfill_referrers_from_entry_page_impl( $days = 30, $limit = 2000 ) {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * 86400 );
		$table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- Intentional literal LIKE wildcards for utm_source matching.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, entry_page FROM " . $table . "
				 WHERE start_time >= %s AND (utm_source IS NULL OR utm_source='')
				   AND entry_page LIKE '%%utm_source=%%'
				 LIMIT %d",
				$since,
				(int) $limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		if ( ! $rows ) {
			return;
		}
		foreach ( $rows as $r ) {
			$entry = is_string( $r->entry_page ) ? $r->entry_page : '';
			if ( $entry === '' ) {
				continue;
			}
			$qs = wp_parse_url( $entry, PHP_URL_QUERY );
			if ( ! $qs ) {
				continue;
			}
			parse_str( $qs, $params );
			$src = isset( $params['utm_source'] ) ? trim( $params['utm_source'] ) : '';
			if ( $src === '' ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, array( 'utm_source' => $src ), array( 'id' => $r->id ) );
		}
	}

	/**
	 * Fix Android OS detection - update visitors where OS is 'Linux' but device is mobile.
	 * This corrects the issue where Android devices were incorrectly categorized as Linux.
	 *
	 * @since 1.0.4
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @return array Results with count of updated records.
	 */
	private function fix_android_os_detection_impl() {
		global $wpdb;

		// Find all visitors where os='Linux' and device_type='mobile' or 'tablet'
		// These are very likely Android devices that were misclassified
		$table = esc_sql( $wpdb->prefix . 'optibehavior_visitors' );

		$updated = $wpdb->query(
			"UPDATE " . $table . "
			 SET os = 'Android'
			 WHERE LOWER(os) = 'linux'
			   AND device_type IN ('mobile', 'tablet')"
		);

		return array(
			'success' => true,
			'updated' => $updated,
			'message' => sprintf(
				'Updated %d visitor record(s) from Linux to Android based on device type',
				$updated
			),
		);
	}

	/**
	 * AJAX handler for fixing Android OS detection.
	 *
	 * @since 1.0.4
	 */
	public function ajax_fix_android_os() {
		check_ajax_referer( 'opti_behavior_maintenance', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied', 'opti-behavior' ) ) );
		}

		$result = $this->fix_android_os_detection_impl();
		wp_send_json_success( $result );
	}

	/**
	 * Update the visitor daily stats summary table.
	 *
	 * This function aggregates session data into a summary table for faster
	 * Top Engaged Users queries. Should be called via cron or after bulk operations.
	 *
	 * @since 1.0.5
	 * @param string|null $date Optional specific date to update (Y-m-d format). Defaults to yesterday and today.
	 * @return array Results with counts.
	 */
	public function update_visitor_daily_stats( $date = null ) {
		global $wpdb;

		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$summary_table = esc_sql( $wpdb->prefix . 'optibehavior_visitor_daily_stats' );

		// Check if summary table exists, create if not
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '" . $summary_table . "'" );
		if ( ! $table_exists ) {
			$this->create_visitor_daily_stats_table();
		}

		// Determine which dates to update
		if ( $date ) {
			$dates = array( $date );
		} else {
			// Update yesterday and today by default
			$dates = array(
				gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
				gmdate( 'Y-m-d' ),
			);
		}

		$updated = 0;
		$inserted = 0;

		foreach ( $dates as $stat_date ) {
			$result = $wpdb->query( $wpdb->prepare(
				"INSERT INTO " . $summary_table . "
					(visitor_id, stat_date, sessions, total_duration, total_page_views, user_id, last_seen, traffic_type, country_code)
				SELECT
					s.visitor_id,
					DATE(s.start_time) as stat_date,
					COUNT(*) as sessions,
					SUM(GREATEST(s.duration, 0)) as total_duration,
					SUM(COALESCE(s.page_views, 0)) as total_page_views,
					MAX(s.user_id) as user_id,
					MAX(COALESCE(s.end_time, s.start_time)) as last_seen,
					MAX(s.traffic_type) as traffic_type,
					'' as country_code
				FROM " . $sessions_table . " s
				WHERE DATE(s.start_time) = %s
				GROUP BY s.visitor_id, DATE(s.start_time)
				ON DUPLICATE KEY UPDATE
					sessions = VALUES(sessions),
					total_duration = VALUES(total_duration),
					total_page_views = VALUES(total_page_views),
					user_id = VALUES(user_id),
					last_seen = VALUES(last_seen),
					traffic_type = VALUES(traffic_type)",
				$stat_date
			) );

			if ( $result !== false ) {
				$updated += $result;
			}
		}

		return array(
			'dates_processed' => count( $dates ),
			'rows_affected'   => $updated,
		);
	}

	/**
	 * Create the visitor daily stats summary table.
	 *
	 * @since 1.0.5
	 */
	private function create_visitor_daily_stats_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'optibehavior_visitor_daily_stats';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE " . $table_name . " (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			visitor_id VARCHAR(64) NOT NULL,
			stat_date DATE NOT NULL,
			sessions INT UNSIGNED NOT NULL DEFAULT 0,
			total_duration INT UNSIGNED NOT NULL DEFAULT 0,
			total_page_views INT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			last_seen DATETIME DEFAULT NULL,
			traffic_type VARCHAR(20) DEFAULT 'human',
			country_code CHAR(2) DEFAULT '',
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY idx_visitor_date (visitor_id, stat_date),
			KEY idx_stat_date (stat_date),
			KEY idx_total_duration (stat_date, traffic_type, total_duration DESC),
			KEY idx_traffic_date (traffic_type, stat_date)
		) " . $charset_collate . ";";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Rebuild the entire visitor daily stats summary table.
	 * Use sparingly as it processes all sessions.
	 *
	 * @since 1.0.5
	 * @return array Results with counts.
	 */
	public function rebuild_visitor_daily_stats() {
		global $wpdb;

		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$summary_table = esc_sql( $wpdb->prefix . 'optibehavior_visitor_daily_stats' );

		// Create table if needed
		$this->create_visitor_daily_stats_table();

		// Truncate existing data
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$wpdb->query( "TRUNCATE TABLE " . $summary_table );

		// Rebuild from sessions
		$result = $wpdb->query(
			"INSERT INTO " . $summary_table . "
				(visitor_id, stat_date, sessions, total_duration, total_page_views, user_id, last_seen, traffic_type, country_code)
			SELECT
				s.visitor_id,
				DATE(s.start_time) as stat_date,
				COUNT(*) as sessions,
				SUM(GREATEST(s.duration, 0)) as total_duration,
				SUM(COALESCE(s.page_views, 0)) as total_page_views,
				MAX(s.user_id) as user_id,
				MAX(COALESCE(s.end_time, s.start_time)) as last_seen,
				MAX(s.traffic_type) as traffic_type,
				'' as country_code
			FROM " . $sessions_table . " s
			GROUP BY s.visitor_id, DATE(s.start_time)"
		);

		return array(
			'rows_inserted' => $result !== false ? $result : 0,
		);
	}

	// =========================================================================
	// Smart Data Cleanup Methods
	// =========================================================================

	/**
	 * Lazily instantiate the Smart Cleanup service.
	 *
	 * @since 1.2.7
	 * @return Opti_Behavior_Smart_Cleanup_Service
	 */
	private function get_smart_cleanup_service() {
		if ( null === $this->smart_cleanup_service ) {
			if ( ! class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
				require_once __DIR__ . '/class-opti-behavior-smart-cleanup-service.php';
			}

			$this->smart_cleanup_service = new Opti_Behavior_Smart_Cleanup_Service();
		}

		return $this->smart_cleanup_service;
	}

	/**
	 * Count sessions matching the given conditions (for preview).
	 *
	 * @since 1.0.9
	 * @param array $conditions Cleanup conditions array.
	 * @return int Number of matching sessions.
	 */
	public function count_sessions_by_conditions( $conditions ) {
		return $this->get_smart_cleanup_service()->count_sessions_by_conditions( $conditions );
	}

	/**
	 * Count bot/spam sessions.
	 *
	 * @since 1.0.9
	 * @return int Number of bot sessions.
	 */
	public function count_bot_sessions() {
		return $this->get_smart_cleanup_service()->count_bot_sessions();
	}

	/**
	 * Get session IDs matching conditions in batches.
	 *
	 * @since 1.0.9
	 * @param array $conditions Cleanup conditions.
	 * @param int   $limit      Batch size.
	 * @param int   $offset     Offset for pagination.
	 * @return array Array of session IDs.
	 */
	private function get_matching_session_ids( $conditions, $limit = 500, $offset = 0 ) {
		return $this->get_smart_cleanup_service()->get_matching_session_ids( $conditions, $limit, $offset );
	}

	/**
	 * Build WHERE clauses from conditions array.
	 * Conditions use OR logic between groups but AND within each condition.
	 *
	 * @since 1.0.9
	 * @param array $conditions Conditions array.
	 * @return array Array of SQL WHERE clause strings.
	 */
	private function build_session_condition_clauses( $conditions ) {
		return $this->get_smart_cleanup_service()->build_session_condition_clauses( $conditions );
	}

	/**
	 * Build the Smart Cleanup impact summary.
	 *
	 * @since 1.2.7
	 * @param array $conditions Cleanup conditions.
	 * @return array Impact summary.
	 */
	public function get_smart_cleanup_impact_summary( $conditions ) {
		return $this->get_smart_cleanup_service()->build_impact_summary( $conditions );
	}

	/**
	 * Delete bot/spam sessions with cascading deletion.
	 * Returns batch info for step-by-step AJAX processing.
	 *
	 * @since 1.0.9
	 * @param int $step Current step (0-based).
	 * @return array Result with status, progress, and counts.
	 */
	public function delete_bot_sessions_impl( $step = 0 ) {
		return $this->get_smart_cleanup_service()->delete_bot_sessions( $step );
	}

	/**
	 * Delete sessions matching conditions with cascading deletion.
	 * Returns batch info for step-by-step AJAX processing.
	 *
	 * @since 1.0.9
	 * @param array $conditions Cleanup conditions.
	 * @param int   $step       Current step (0-based).
	 * @return array Result with status, progress, and counts.
	 */
	public function delete_sessions_by_conditions_impl( $conditions, $step = 0, $options = array() ) {
		return $this->get_smart_cleanup_service()->delete_sessions_by_conditions( $conditions, $step, $options );
	}

	/**
	 * Cascade delete sessions and all related data.
	 *
	 * @since 1.0.9
	 * @param array $session_ids Array of session IDs to delete.
	 * @return int Number of sessions deleted.
	 */
	private function cascade_delete_sessions( $session_ids ) {
		return $this->get_smart_cleanup_service()->cascade_delete_sessions( $session_ids );
	}

	/**
	 * Delete orphaned visitors (visitors with no remaining sessions).
	 *
	 * @since 1.0.9
	 * @return int Number of visitors deleted.
	 */
	private function delete_orphaned_visitors() {
		return $this->get_smart_cleanup_service()->delete_orphaned_visitors();
	}

	/**
	 * Clear analytics-related transient caches.
	 *
	 * @since 1.0.9
	 */
	private function clear_analytics_caches() {
		$this->get_smart_cleanup_service()->clear_analytics_caches();
	}

	/**
	 * Run scheduled cleanup using saved conditions.
	 *
	 * @since 1.0.9
	 */
	public function run_scheduled_cleanup() {
		$this->get_smart_cleanup_service()->run_scheduled_cleanup();
	}

	/**
	 * Add a cleanup log entry.
	 *
	 * @since 1.0.9
	 * @param string $type             Cleanup type (manual|scheduled).
	 * @param int    $sessions_deleted  Number of sessions deleted.
	 * @param int    $events_deleted    Number of events deleted.
	 * @param int    $files_deleted     Number of files deleted.
	 */
	public function add_cleanup_log( $type, $sessions_deleted, $events_deleted = 0, $files_deleted = 0, $details = array() ) {
		$this->get_smart_cleanup_service()->add_cleanup_log( $type, $sessions_deleted, $events_deleted, $files_deleted, $details );
	}

	/**
	 * Get cleanup log entries.
	 *
	 * @since 1.0.9
	 * @param int $limit Maximum entries to return.
	 * @return array Array of log entries.
	 */
	public function get_cleanup_logs( $limit = 10 ) {
		return $this->get_smart_cleanup_service()->get_cleanup_logs( $limit );
	}

}

