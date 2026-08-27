<?php
/**
 * File Storage Manager for Opti-Behavior Heatmap
 *
 * Handles file-based storage for recordings and events to prevent database bloat
 * on high-traffic sites. Organizes data by date/hour with compression.
 *
 * @package opti-behavior-heatmap
 * @copyright 2025 Opti-User
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Opti_Behavior_Heatmap_File_Storage', false ) ) {
	return;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Opti_Behavior_Heatmap_File_Storage
 */
class Opti_Behavior_Heatmap_File_Storage {

	/**
	 * Append-only sidecar suffixes (perf Fix A, customer report 2026-08).
	 *
	 * The base recording file (`session_..._TS.json[.gz]`) is written ONCE by
	 * save_recording(). Every subsequent update appends its new events as one
	 * NDJSON line to the `.oblog` sidecar instead of the old
	 * read-whole-file -> gzuncompress -> array_merge -> usort-all ->
	 * gzcompress(9) -> rewrite-whole-file cycle (which was O(N^2) per session).
	 * A tiny `.obidx` sidecar tracks running counters (event count + duration)
	 * so the size/event caps stay enforceable in O(1) without re-reading the
	 * base file on every save. read_recording() merges base + sidecar and sorts
	 * once at read (playback/list) time. Old recordings have no sidecar and read
	 * back unchanged.
	 *
	 * NOTE: this free-plugin copy of the class is the one that actually loads
	 * when both plugins are active (the free plugin require_once's it before the
	 * pro autoloader fires and the class_exists guard makes the pro copy a
	 * no-op), so Fix A must live here as well as in the pro copy.
	 */
	const APPEND_SUFFIX = '.oblog';
	const INDEX_SUFFIX  = '.obidx';

	/**
	 * Storage base directory
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * Storage base URL
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Storage settings
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Debug manager instance
	 *
	 * @var Opti_Behavior_Heatmap_Debug_Manager|null
	 */
	private $debug_manager = null;

	/**
	 * Constructor
	 *
	 * @param Opti_Behavior_Heatmap_Debug_Manager|null $debug_manager Optional debug manager instance.
	 */
	public function __construct( $debug_manager = null ) {
		$this->debug_manager = $debug_manager;
		$this->load_settings();
		$this->init_storage_directory();
	}

	/**
	 * Log a debug message
	 *
	 * @param string $message Message to log.
	 * @param string $level   Log level (error, warning, info, debug).
	 */
	private function log( $message, $level = 'info' ) {
		if ( $this->debug_manager ) {
			$this->debug_manager->log( $message, $level, 'file-storage' );
		}
	}

	/**
	 * Load storage settings
	 */
	private function load_settings() {
		$defaults = array(
			'enabled'           => true,
			'storage_mode'      => 'file', // 'file' or 'database'
			'compression'       => true,
			'retention_days'    => 30,
			'max_storage_mb'    => 5000, // 5GB default
			'auto_cleanup'      => true,
		);

		$this->settings = wp_parse_args( 
			get_option( 'opti_behavior_file_storage_settings', array() ), 
			$defaults 
		);
	}

	/**
	 * Initialize storage directory
	 */
	private function init_storage_directory() {
		$upload_dir = wp_upload_dir();
		$this->base_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';
		$this->base_url = trailingslashit( $upload_dir['baseurl'] ) . 'opti-behavior-data/';

		// Create base directory if it doesn't exist
		if ( ! file_exists( $this->base_dir ) ) {
			wp_mkdir_p( $this->base_dir );
			
			// Add .htaccess to protect data files
			$this->create_htaccess();
			
			// Add index.php to prevent directory listing
			$this->create_index_file();
		}
	}

	/**
	 * Create .htaccess file to protect data
	 */
	private function create_htaccess() {
		$htaccess_file = $this->base_dir . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$content = "# Opti-Behavior Data Protection\n";
			$content .= "Order deny,allow\n";
			$content .= "Deny from all\n";
			$content .= "<FilesMatch '\.(json|gz)$'>\n";
			$content .= "  Deny from all\n";
			$content .= "</FilesMatch>\n";
			
			file_put_contents( $htaccess_file, $content );
		}
	}

	/**
	 * Create index.php to prevent directory listing
	 */
	private function create_index_file() {
		$index_file = $this->base_dir . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Get directory path for specific date/hour
	 *
	 * @param string $type Type of data (recordings, events, sessions).
	 * @param int    $timestamp Timestamp for organization.
	 * @return string Directory path.
	 */
	private function get_data_directory( $type, $timestamp = null ) {
		if ( null === $timestamp ) {
			$timestamp = current_time( 'timestamp' );
		}

		$year  = gmdate( 'Y', $timestamp );
		$month = gmdate( 'm', $timestamp );
		$day   = gmdate( 'd', $timestamp );
		$hour  = gmdate( 'H', $timestamp );

		$dir = $this->base_dir . "{$type}/{$year}/{$month}/{$day}/{$hour}/";

		// Create directory if it doesn't exist
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			
			// Add index.php to each directory
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Save recording to file
	 *
	 * @param string $session_id Session ID.
	 * @param array  $data Recording data.
	 * @return array Result with file_path and file_size.
	 */
	public function save_recording( $session_id, $data ) {
		// Check if file storage is enabled
		if ( 'database' === $this->settings['storage_mode'] ) {
			return array( 'error' => 'File storage is disabled' );
		}

		$timestamp = current_time( 'timestamp' );
		$dir = $this->get_data_directory( 'recordings', $timestamp );
		
		// Generate unique filename
		$filename = $session_id . '_' . $timestamp . '.json';
		$filepath = $dir . $filename;

		// Prepare data
		$json_data = wp_json_encode( $data );

		// Compress if enabled
		if ( $this->settings['compression'] ) {
			$compressed = gzcompress( $json_data, 9 );
			$filepath .= '.gz';
			$written = file_put_contents( $filepath, $compressed );
		} else {
			$written = file_put_contents( $filepath, $json_data );
		}

		if ( false === $written ) {
			return array( 'error' => 'Failed to write file' );
		}

		return array(
			'success'   => true,
			'file_path' => str_replace( $this->base_dir, '', $filepath ),
			'file_size' => filesize( $filepath ),
			'timestamp' => $timestamp,
		);
	}

	/**
	 * Update an existing recording file
	 *
	 * @param string $file_path Relative file path (from database)
	 * @param array  $data Recording data to save
	 * @return array Result with file_path and file_size or error
	 */
	public function update_recording( $file_path, $data ) {
		// Check if file storage is enabled
		if ( 'database' === $this->settings['storage_mode'] ) {
			return array( 'error' => 'File storage is disabled' );
		}

		// Get full file path
		$full_path = $this->base_dir . $file_path;

		// Check if file exists
		if ( ! file_exists( $full_path ) ) {
			return array( 'error' => 'File does not exist: ' . $file_path );
		}

		// Append-only update (perf Fix A). Instead of the old
		// read-whole-file -> merge -> usort-all -> gzcompress(9) -> rewrite
		// cycle (O(N^2) per session), append the new events to the `.oblog`
		// sidecar and bump the `.obidx` counters. The base file is never
		// rewritten. Caps are enforced up front so an oversized recording is
		// left intact and the append is refused (never falls back to DB).
		$log_path = $full_path . self::APPEND_SUFFIX;
		$idx_path = $full_path . self::INDEX_SUFFIX;

		// Running counters (event count + duration). Cheap read; lazily
		// initialised from the base file once for legacy files with no sidecar.
		$idx = $this->read_recording_index( $idx_path );
		if ( null === $idx ) {
			$idx = $this->init_recording_index( $full_path, $file_path );
			$this->write_recording_index( $idx_path, $idx );
		}

		$new_events   = isset( $data['events'] ) && is_array( $data['events'] ) ? array_values( $data['events'] ) : array();
		$new_duration = isset( $data['duration'] ) ? (int) $data['duration'] : 0;

		// Size cap (spec §G.4): combined on-disk bytes of base + append sidecar.
		$max_bytes     = (int) apply_filters( 'opti_behavior_recording_max_file_bytes', 50 * MB_IN_BYTES );
		$combined_size = (int) @filesize( $full_path ) + ( is_file( $log_path ) ? (int) @filesize( $log_path ) : 0 );
		if ( $max_bytes > 0 && $combined_size > $max_bytes ) {
			static $size_cap_logged = array();
			if ( ! isset( $size_cap_logged[ $file_path ] ) ) {
				$size_cap_logged[ $file_path ] = true;
				$this->log( 'Recording exceeds the size cap (' . $combined_size . ' > ' . $max_bytes . ' bytes) - append refused: ' . $file_path, 'warning' );
			}
			return array(
				'error'          => 'Recording file exceeds the size cap - append refused',
				'file_too_large' => true,
				'file_path'      => $file_path,
				'file_size'      => $combined_size,
			);
		}

		// Event cap (spec §G.4): running total across base + sidecar.
		$max_events = (int) apply_filters( 'opti_behavior_recording_max_events_per_file', 50000 );
		if ( $max_events > 0 && (int) $idx['events'] >= $max_events ) {
			static $event_cap_logged = array();
			if ( ! isset( $event_cap_logged[ $file_path ] ) ) {
				$event_cap_logged[ $file_path ] = true;
				$this->log( 'Recording already holds ' . $idx['events'] . ' events (cap ' . $max_events . ') - append refused: ' . $file_path, 'warning' );
			}
			return array(
				'error'          => 'Recording event count exceeds the cap - append refused',
				'file_too_large' => true,
				'file_path'      => $file_path,
				'file_size'      => $combined_size,
			);
		}

		// No new events: nothing to append. Bump the duration in the index if it
		// grew, but never write an empty append line or rewrite the base file.
		if ( empty( $new_events ) ) {
			if ( $new_duration > (int) $idx['duration'] ) {
				$idx['duration'] = $new_duration;
				$this->write_recording_index( $idx_path, $idx );
			}
			return array(
				'success'   => true,
				'file_path' => $file_path,
				'file_size' => $combined_size,
				'timestamp' => current_time( 'timestamp' ),
				'duration'  => (int) $idx['duration'],
			);
		}

		// Append the batch as one NDJSON line (a JSON array of events).
		$written = @file_put_contents( $log_path, wp_json_encode( $new_events ) . "\n", FILE_APPEND | LOCK_EX );
		if ( false === $written ) {
			$this->log( 'Append write failed for: ' . $log_path, 'warning' );
			return array( 'error' => 'Failed to write file - append failed' );
		}

		$idx['events']  += count( $new_events );
		$idx['duration'] = max( (int) $idx['duration'], $new_duration );
		$this->write_recording_index( $idx_path, $idx );

		$combined_size = (int) @filesize( $full_path ) + (int) @filesize( $log_path );

		return array(
			'success'   => true,
			'file_path' => $file_path,
			'file_size' => $combined_size,
			'timestamp' => current_time( 'timestamp' ),
			'duration'  => (int) $idx['duration'],
		);
	}

	/**
	 * Read the running-counter sidecar (`.obidx`) for a recording.
	 *
	 * @param string $idx_path Absolute path to the `.obidx` file.
	 * @return array|null array( 'events' => int, 'duration' => int ) or null if absent/unreadable.
	 */
	private function read_recording_index( $idx_path ) {
		if ( ! is_file( $idx_path ) ) {
			return null;
		}
		$raw = @file_get_contents( $idx_path );
		if ( false === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['events'] ) ) {
			return null;
		}
		return array(
			'events'   => (int) $decoded['events'],
			'duration' => isset( $decoded['duration'] ) ? (int) $decoded['duration'] : 0,
		);
	}

	/**
	 * Write the running-counter sidecar (`.obidx`). Tiny, O(1) overwrite.
	 *
	 * @param string $idx_path Absolute path to the `.obidx` file.
	 * @param array  $idx      array( 'events' => int, 'duration' => int ).
	 * @return void
	 */
	private function write_recording_index( $idx_path, $idx ) {
		@file_put_contents(
			$idx_path,
			wp_json_encode( array(
				'events'   => (int) $idx['events'],
				'duration' => (int) $idx['duration'],
			) ),
			LOCK_EX
		);
	}

	/**
	 * Initialise the running counters from the base recording file.
	 *
	 * Runs at most once per recording (the first append after the base file was
	 * created, or the first append to a legacy merged file). After that the
	 * `.obidx` sidecar is authoritative and the base file is never re-read on
	 * the write path.
	 *
	 * @param string $full_path Absolute path to the base recording file.
	 * @param string $file_path Relative file path (used to detect .gz).
	 * @return array array( 'events' => int, 'duration' => int ).
	 */
	private function init_recording_index( $full_path, $file_path ) {
		$idx = array( 'events' => 0, 'duration' => 0 );

		$file_contents = @file_get_contents( $full_path );
		if ( false === $file_contents ) {
			return $idx;
		}

		if ( substr( $file_path, -3 ) === '.gz' ) {
			$decompressed = @gzuncompress( $file_contents );
			if ( false === $decompressed ) {
				return $idx;
			}
			$file_contents = $decompressed;
		}

		$decoded = json_decode( $file_contents, true );
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['events'] ) && is_array( $decoded['events'] ) ) {
				$idx['events'] = count( $decoded['events'] );
			}
			if ( isset( $decoded['duration'] ) ) {
				$idx['duration'] = (int) $decoded['duration'];
			}
		}

		return $idx;
	}

	/**
	 * Merge the append-only sidecar events into the base recording data.
	 *
	 * Each sidecar line is a JSON array of events appended by update_recording().
	 * Events are concatenated onto the base events and sorted once by timestamp,
	 * reproducing the chronological order the old rewrite-every-save code kept.
	 *
	 * @param array  $data     Base recording data (decoded).
	 * @param string $log_path Absolute path to the `.oblog` sidecar.
	 * @return array Recording data with merged, time-ordered events.
	 */
	private function merge_append_log( $data, $log_path ) {
		if ( ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
			$data['events'] = array();
		}

		// Streaming line-by-line read of the append-only sidecar log; WP_Filesystem has no streaming reader and loading the whole file at once defeats the memory-bounded parse. Sidecar may be absent/locked under concurrent writes.
		$handle = @fopen( $log_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return $data;
		}

		$appended = array();
		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$batch = json_decode( $line, true );
			if ( is_array( $batch ) ) {
				foreach ( $batch as $evt ) {
					$appended[] = $evt;
				}
			}
		}
		// Closing the streaming read handle opened above; handle guaranteed valid here.
		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! empty( $appended ) ) {
			$data['events'] = array_merge( $data['events'], $appended );
			usort( $data['events'], function( $a, $b ) {
				$ts_a = isset( $a['timestamp'] ) ? $a['timestamp'] : 0;
				$ts_b = isset( $b['timestamp'] ) ? $b['timestamp'] : 0;
				return $ts_a <=> $ts_b;
			} );
		}

		return $data;
	}

	/**
	 * Save events batch to file
	 *
	 * @param array $events Array of events.
	 * @return array Result with file_path and file_size.
	 */
	public function save_events_batch( $events ) {
		$this->log( 'save_events_batch called with ' . count( $events ) . ' events', 'debug' );

		if ( empty( $events ) ) {
			$this->log( 'No events to save', 'warning' );
			return array( 'error' => 'No events to save' );
		}

		// Check if file storage is enabled
		if ( 'database' === $this->settings['storage_mode'] ) {
			$this->log( 'File storage is disabled (mode: ' . $this->settings['storage_mode'] . ')', 'warning' );
			return array( 'error' => 'File storage is disabled' );
		}

		$timestamp = current_time( 'timestamp' );
		$dir = $this->get_data_directory( 'events', $timestamp );

		$this->log( 'Directory: ' . $dir, 'debug' );

		// Generate unique filename with microtime for uniqueness
		$filename = 'events_' . $timestamp . '_' . uniqid() . '.json';
		$filepath = $dir . $filename;

		// Prepare data
		$json_data = wp_json_encode( $events );

		// Compress if enabled
		if ( $this->settings['compression'] ) {
			$compressed = gzcompress( $json_data, 9 );
			$filepath .= '.gz';
			$written = file_put_contents( $filepath, $compressed );
		} else {
			$written = file_put_contents( $filepath, $json_data );
		}

		if ( false === $written ) {
			$this->log( 'Failed to write file: ' . $filepath, 'error' );
			return array( 'error' => 'Failed to write file' );
		}

		$this->log( 'Successfully saved ' . count( $events ) . ' events to: ' . $filepath, 'info' );

		return array(
			'success'   => true,
			'file_path' => str_replace( $this->base_dir, '', $filepath ),
			'file_size' => filesize( $filepath ),
			'count'     => count( $events ),
		);
	}

	/**
	 * Read recording from file
	 *
	 * @param string $file_path Relative file path.
	 * @return array|false Recording data or false on failure.
	 */
	public function read_recording( $file_path ) {
		$full_path = $this->base_dir . $file_path;

		if ( ! file_exists( $full_path ) ) {
			return false;
		}

		$content = file_get_contents( $full_path );

		if ( false === $content ) {
			return false;
		}

		// Decompress if needed
		if ( substr( $file_path, -3 ) === '.gz' ) {
			$content = gzuncompress( $content );
			if ( false === $content ) {
				return false;
			}
		}

		$data = json_decode( $content, true );
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// Merge the append-only sidecar (perf Fix A). Legacy recordings have no
		// `.oblog` sidecar and read back byte-for-byte unchanged. New recordings
		// carry their incremental events here; sort once, at read time.
		$log_path = $full_path . self::APPEND_SUFFIX;
		if ( is_file( $log_path ) ) {
			$data = $this->merge_append_log( $data, $log_path );
		}

		// Prefer the running duration from the counter sidecar when higher.
		$idx = $this->read_recording_index( $full_path . self::INDEX_SUFFIX );
		if ( null !== $idx && $idx['duration'] > ( isset( $data['duration'] ) ? (int) $data['duration'] : 0 ) ) {
			$data['duration'] = $idx['duration'];
		}

		return $data;
	}

	/**
	 * Get storage statistics
	 *
	 * @return array Storage stats.
	 */
	public function get_storage_stats() {
		$stats = array(
			'total_size'       => 0,
			'file_count'       => 0,
			'recordings_size'  => 0,
			'recordings_count' => 0,
			'events_size'      => 0,
			'events_count'     => 0,
		);

		// Calculate recordings size
		$recordings_dir = $this->base_dir . 'recordings/';
		if ( file_exists( $recordings_dir ) ) {
			$stats['recordings_size'] = $this->get_directory_size( $recordings_dir );
			$stats['recordings_count'] = $this->count_files( $recordings_dir );
		}

		// Calculate events size
		$events_dir = $this->base_dir . 'events/';
		if ( file_exists( $events_dir ) ) {
			$stats['events_size'] = $this->get_directory_size( $events_dir );
			$stats['events_count'] = $this->count_files( $events_dir );
		}

		$stats['total_size'] = $stats['recordings_size'] + $stats['events_size'];
		$stats['file_count'] = $stats['recordings_count'] + $stats['events_count'];

		return $stats;
	}

	/**
	 * Get directory size recursively
	 *
	 * @param string $directory Directory path.
	 * @return int Size in bytes.
	 */
	private function get_directory_size( $directory ) {
		$size = 0;
		
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			$size += $file->getSize();
		}

		return $size;
	}

	/**
	 * Count files in directory recursively
	 *
	 * @param string $directory Directory path.
	 * @return int File count.
	 */
	private function count_files( $directory ) {
		$count = 0;
		
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && $file->getFilename() !== 'index.php' && $file->getFilename() !== '.htaccess' ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Delete all files for a specific session (base recording + append sidecars).
	 *
	 * The Pro deletion path prefers this method (via method_exists) over its
	 * manual unlink fallback, so having it here — in the free copy that actually
	 * loads — guarantees the `.oblog`/`.obidx` sidecars are removed alongside the
	 * base file (they share the `session_{id}_` filename prefix). Fix A.
	 *
	 * @param string $session_id Session ID to delete files for.
	 * @return bool True if any files were deleted, false otherwise.
	 */
	public function delete_session_files( $session_id ) {
		$recordings_dir = $this->base_dir . 'recordings/';

		if ( ! file_exists( $recordings_dir ) ) {
			return false;
		}

		$files_deleted = false;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $recordings_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			$this->log( 'Error creating directory iterator for deletion: ' . $e->getMessage(), 'warning' );
			return false;
		}

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getFilename() === 'index.php' || $file->getFilename() === '.htaccess' ) {
				continue;
			}

			// Matches base recording (session_{id}_{ts}.json[.gz]) AND its
			// append-only sidecars (…json.gz.oblog / .obidx), which share the
			// same session_{id}_ prefix.
			$filename = $file->getFilename();
			$pattern  = 'session_' . $session_id . '_';
			if ( strpos( $filename, $pattern ) === 0 ) {
				$path = $file->getPathname();
				wp_delete_file( $path );
				if ( ! file_exists( $path ) ) {
					$files_deleted = true;
				}
			}
		}

		return $files_deleted;
	}

	/**
	 * Get settings
	 *
	 * @return array Settings.
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Update settings
	 *
	 * @param array $new_settings New settings.
	 * @return bool Success.
	 */
	public function update_settings( $new_settings ) {
		$this->settings = wp_parse_args( $new_settings, $this->settings );
		return update_option( 'opti_behavior_file_storage_settings', $this->settings );
	}

	/**
	 * Get base directory path
	 *
	 * @return string
	 */
	public function get_base_dir() {
		return $this->base_dir;
	}

	/**
	 * Read events from file storage for a specific page and event type
	 *
	 * @param int    $page_id Page ID.
	 * @param int    $event_id Event ID (16=click_pc, 17=click_mobile, etc.).
	 * @param int    $limit Maximum number of events to return (0 = no limit).
	 * @param string $start_date Optional start date filter (Y-m-d format).
	 * @param string $end_date Optional end date filter (Y-m-d format).
	 * @return array Array of event objects matching the criteria.
	 */
	public function read_events( $page_id, $event_id, $limit = 0, $start_date = null, $end_date = null ) {
		$events_dir = $this->base_dir . 'events/';

		if ( ! file_exists( $events_dir ) ) {
			return array();
		}

		$all_events = array();

		// Determine date range to scan
		if ( $start_date && $end_date ) {
			$start_timestamp = strtotime( $start_date );
			$end_timestamp = strtotime( $end_date . ' 23:59:59' );
		} else {
			// Scan all files if no date range specified
			$start_timestamp = 0;
			$end_timestamp = PHP_INT_MAX;
		}

		// Recursively scan events directory
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $events_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getFilename() === 'index.php' || $file->getFilename() === '.htaccess' ) {
				continue;
			}

			// Check if file is within date range (based on file modification time)
			$file_time = $file->getMTime();
			if ( $file_time < $start_timestamp || $file_time > $end_timestamp ) {
				continue;
			}

			// Read and decompress file
			$content = file_get_contents( $file->getPathname() );
			if ( false === $content ) {
				continue;
			}

			// Decompress if needed
			if ( substr( $file->getFilename(), -3 ) === '.gz' ) {
				$content = gzuncompress( $content );
				if ( false === $content ) {
					continue;
				}
			}

			// Decode JSON
			$file_events = json_decode( $content, true );
			if ( ! is_array( $file_events ) ) {
				continue;
			}

			// Filter events by page_id and event_id
			foreach ( $file_events as $event ) {
				if ( isset( $event['page_id'] ) && isset( $event['event'] ) ) {
					if ( intval( $event['page_id'] ) === intval( $page_id ) && intval( $event['event'] ) === intval( $event_id ) ) {
						$all_events[] = $event;
					}
				}
			}

			// Stop if we've reached the limit
			if ( $limit > 0 && count( $all_events ) >= $limit ) {
				break;
			}
		}

		// Sort by insert_at descending (most recent first)
		usort( $all_events, function( $a, $b ) {
			$time_a = isset( $a['insert_at'] ) ? strtotime( $a['insert_at'] ) : 0;
			$time_b = isset( $b['insert_at'] ) ? strtotime( $b['insert_at'] ) : 0;
			return $time_b - $time_a;
		});

		// Apply limit
		if ( $limit > 0 && count( $all_events ) > $limit ) {
			$all_events = array_slice( $all_events, 0, $limit );
		}

		return $all_events;
	}

	/**
	 * Count events from file storage for a specific page and event type
	 *
	 * @param int    $page_id Page ID.
	 * @param int    $event_id Event ID (16=click_pc, 17=click_mobile, etc.).
	 * @param string $start_date Optional start date filter (Y-m-d format).
	 * @param string $end_date Optional end date filter (Y-m-d format).
	 * @return int Count of events matching the criteria.
	 */
	public function count_events( $page_id, $event_id, $start_date = null, $end_date = null ) {
		$events = $this->read_events( $page_id, $event_id, 0, $start_date, $end_date );
		return count( $events );
	}

	/**
	 * Get total count of all click events (event 16 and 17) from file storage
	 *
	 * @param string $start_date Optional start date filter (Y-m-d format).
	 * @param string $end_date Optional end date filter (Y-m-d format).
	 * @return int Total count of click events.
	 */
	public function get_total_clicks( $start_date = null, $end_date = null ) {
		$events_dir = $this->base_dir . 'events/';

		if ( ! file_exists( $events_dir ) ) {
			return 0;
		}

		$total_count = 0;

		// Determine date range to scan
		if ( $start_date && $end_date ) {
			$start_timestamp = strtotime( $start_date );
			$end_timestamp = strtotime( $end_date . ' 23:59:59' );
		} else {
			// Scan all files if no date range specified
			$start_timestamp = 0;
			$end_timestamp = PHP_INT_MAX;
		}

		// Recursively scan events directory
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $events_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getFilename() === 'index.php' || $file->getFilename() === '.htaccess' ) {
				continue;
			}

			// Check if file is within date range (based on file modification time)
			$file_time = $file->getMTime();
			if ( $file_time < $start_timestamp || $file_time > $end_timestamp ) {
				continue;
			}

			// Read and decompress file
			$content = file_get_contents( $file->getPathname() );
			if ( false === $content ) {
				continue;
			}

			// Decompress if needed
			if ( substr( $file->getFilename(), -3 ) === '.gz' ) {
				$content = gzuncompress( $content );
				if ( false === $content ) {
					continue;
				}
			}

			// Decode JSON
			$file_events = json_decode( $content, true );
			if ( ! is_array( $file_events ) ) {
				continue;
			}

			// Count click events (16 = click_pc, 17 = click_mobile)
			foreach ( $file_events as $event ) {
				if ( isset( $event['event'] ) && ( intval( $event['event'] ) === 16 || intval( $event['event'] ) === 17 ) ) {
					$total_count++;
				}
			}
		}

		return $total_count;
	}

	/**
	 * List all recordings from file storage with pagination and filtering
	 *
	 * @param int    $limit Number of recordings to return (0 = no limit).
	 * @param int    $offset Offset for pagination.
	 * @param string $start_date Optional start date filter (Y-m-d format).
	 * @param string $end_date Optional end date filter (Y-m-d format).
	 * @param string $sort_order Sort order ('asc' or 'desc').
	 * @return array Array with 'recordings' and 'total' count.
	 */
	public function list_recordings( $limit = 0, $offset = 0, $start_date = null, $end_date = null, $sort_order = 'desc', $sort_by = 'date' ) {
		$this->log( '[Opti-FREE] list_recordings START - sort_by=' . $sort_by . ', sort_order=' . $sort_order . ', limit=' . $limit, 'debug' );
		$recordings_dir = $this->base_dir . 'recordings/';

		if ( ! file_exists( $recordings_dir ) ) {
			return array(
				'recordings' => array(),
				'total' => 0,
			);
		}

		$all_recordings = array();

		// Determine date range to scan
		if ( $start_date && $end_date ) {
			$start_timestamp = strtotime( $start_date );
			$end_timestamp = strtotime( $end_date . ' 23:59:59' );
		} else {
			// Scan all files if no date range specified
			$start_timestamp = 0;
			$end_timestamp = PHP_INT_MAX;
		}

		// Recursively scan recordings directory
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $recordings_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getFilename() === 'index.php' || $file->getFilename() === '.htaccess' ) {
				continue;
			}

			// Parse filename: session_[id]_[timestamp].json.gz
			$filename = $file->getFilename();
			if ( ! preg_match( '/^session_(.+)_(\d+)\.json(\.gz)?$/', $filename, $matches ) ) {
				continue;
			}

			$session_id = $matches[1];
			$file_timestamp = intval( $matches[2] );

			// Check if file is within date range
			if ( $file_timestamp < $start_timestamp || $file_timestamp > $end_timestamp ) {
				continue;
			}

			// Get file path relative to base dir
			$relative_path = str_replace( $this->base_dir, '', $file->getPathname() );
			$relative_path = str_replace( '\\', '/', $relative_path ); // Normalize path separators

			// Read file metadata (we'll load full data only when viewing specific recording)
			$all_recordings[] = array(
				'session_id' => $session_id,
				'file_path' => $relative_path,
				'file_size' => $file->getSize(),
				'start_time' => gmdate( 'Y-m-d H:i:s', $file_timestamp ),
				'timestamp' => $file_timestamp,
			);
		}

		// Sort by timestamp
		usort( $all_recordings, function( $a, $b ) use ( $sort_order ) {
			if ( $sort_order === 'asc' ) {
				return $a['timestamp'] - $b['timestamp'];
			} else {
				return $b['timestamp'] - $a['timestamp'];
			}
		});

		$total = count( $all_recordings );

		// Apply pagination
		if ( $limit > 0 ) {
			$all_recordings = array_slice( $all_recordings, $offset, $limit );
		}

		// If sorting by duration, read duration from files AFTER pagination (only for displayed items)
		if ( $sort_by === 'duration' ) {
			$this->log( '[Opti-FREE] DURATION SORTING ACTIVE - Reading durations...', 'debug' );
			foreach ( $all_recordings as &$recording ) {
				try {
					$file_data = $this->read_recording( $recording['file_path'] );
					if ( $file_data && isset( $file_data['duration'] ) ) {
						$recording['duration'] = intval( $file_data['duration'] );
						$this->log( '[Opti-FREE] Session ' . $recording['session_id'] . ' duration: ' . $recording['duration'] . 's', 'debug' );
					}
				} catch ( Exception $e ) {
					$this->log( '[Opti-FREE] Error reading duration: ' . $recording['file_path'], 'error' );
				}
			}
			unset( $recording ); // Break reference

			$this->log( '[Opti-FREE] Sorting by duration (' . $sort_order . ')...', 'debug' );
			// Now sort by duration
			usort( $all_recordings, function( $a, $b ) use ( $sort_order ) {
				if ( $sort_order === 'asc' ) {
					return $a['duration'] - $b['duration'];
				} else {
					return $b['duration'] - $a['duration'];
				}
			});

			// Log final order after sorting
			$this->log( '[Opti-FREE] After duration sort - Final order:', 'debug' );
			foreach ( $all_recordings as $rec ) {
				$this->log( '[Opti-FREE]   => ' . $rec['session_id'] . ': ' . $rec['duration'] . 's', 'debug' );
			}
		}

		return array(
			'recordings' => $all_recordings,
			'total' => $total,
		);
	}

	/**
	 * Get pages with heatmap data from database mapping table
	 *
	 * Reads aggregated counts from optibehavior_heatmap_pages table instead of scanning files.
	 * This is MUCH faster - O(1) database query vs O(n) file scanning for millions of files.
	 *
	 * @return array Array of page data with event counts.
	 */
	public function get_pages_with_heatmap_data() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( ! $table_exists ) {
			return array();
		}

		// Get all pages with heatmap data from database
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results(
			"SELECT page_id, url, click_count, move_count, scroll_count, last_data_at
			FROM {$table_name}
			WHERE click_count > 0 OR move_count > 0 OR scroll_count > 0
			ORDER BY last_data_at DESC"
		);

		if ( empty( $results ) ) {
			return array();
		}

		$pages_data = array();

		foreach ( $results as $row ) {
			// Note: The database stores TOTAL counts (pc + mobile combined)
			// We need to split them to match the expected format
			// For now, we'll attribute all to desktop since we don't track device split in the mapping table
			// TODO: Add device_type tracking to the mapping table for accurate split
			$pages_data[] = array(
				'page_id'          => (int) $row->page_id,
				'url'              => $row->url,
				'click_pc'         => (int) $row->click_count,  // All clicks attributed to desktop
				'click_mobile'     => 0,
				'breakaway_pc'     => (int) $row->scroll_count, // All scrolls attributed to desktop
				'breakaway_mobile' => 0,
				'attention_pc'     => (int) $row->move_count,   // All moves attributed to desktop
				'attention_mobile' => 0,
				'last_event_time'  => $row->last_data_at,
			);
		}

		return $pages_data;
	}
}

