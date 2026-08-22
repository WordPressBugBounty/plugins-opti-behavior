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

		// Big-file guard (spec §G.4): never read-merge-usort-rewrite an
		// oversized recording — the read/decompress alone can exhaust PHP
		// memory. Above the cap the append is refused and the existing file is
		// left intact; callers keep the recording pointed at it (the
		// `file_too_large` marker tells them NOT to fall back to database
		// storage). NOTE: this free-plugin copy of the class is the one that
		// actually loads when both plugins are active (the free plugin
		// require_once's it before the pro autoloader can fire), so the guard
		// must live here as well as in the pro copy.
		$max_bytes_cap = (int) apply_filters( 'opti_behavior_recording_max_file_bytes', 50 * MB_IN_BYTES );
		$current_size  = (int) @filesize( $full_path );
		if ( $max_bytes_cap > 0 && $current_size > $max_bytes_cap ) {
			static $size_cap_logged = array();
			if ( ! isset( $size_cap_logged[ $file_path ] ) ) {
				$size_cap_logged[ $file_path ] = true;
				$this->log( 'Recording file exceeds the size cap (' . $current_size . ' > ' . $max_bytes_cap . ' bytes) - append refused: ' . $file_path, 'warning' );
			}
			return array(
				'error'          => 'Recording file exceeds the size cap - append refused',
				'file_too_large' => true,
				'file_path'      => $file_path,
				'file_size'      => $current_size,
			);
		}

		// Read existing file data
		$existing_data = $this->read_recording( $file_path );

		// Growth cap (spec §G.4): once a recording holds this many events,
		// refuse further merges so the file can never grow unbounded via the
		// merge-usort-rewrite cycle.
		if ( $existing_data && isset( $existing_data['events'] ) && is_array( $existing_data['events'] ) ) {
			$max_events_cap = (int) apply_filters( 'opti_behavior_recording_max_events_per_file', 50000 );
			if ( $max_events_cap > 0 && count( $existing_data['events'] ) >= $max_events_cap ) {
				static $event_cap_logged = array();
				if ( ! isset( $event_cap_logged[ $file_path ] ) ) {
					$event_cap_logged[ $file_path ] = true;
					$this->log( 'Recording already holds ' . count( $existing_data['events'] ) . ' events (cap ' . $max_events_cap . ') - append refused: ' . $file_path, 'warning' );
				}
				return array(
					'error'          => 'Recording event count exceeds the cap - append refused',
					'file_too_large' => true,
					'file_path'      => $file_path,
					'file_size'      => $current_size,
				);
			}
		}

		if ( ! $existing_data || ! isset( $existing_data['events'] ) ) {
			$this->log( 'Failed to read existing recording, will overwrite: ' . $file_path, 'warning' );
			$merged_data = $data;
		} else {
			// Merge events from existing file with new events
			$existing_events = $existing_data['events'];
			$new_events = isset( $data['events'] ) ? $data['events'] : array();

			// Combine events and sort by timestamp
			$merged_events = array_merge( $existing_events, $new_events );

			// Sort events by timestamp (rrweb events have a 'timestamp' property)
			usort( $merged_events, function( $a, $b ) {
				$time_a = isset( $a['timestamp'] ) ? $a['timestamp'] : 0;
				$time_b = isset( $b['timestamp'] ) ? $b['timestamp'] : 0;
				return $time_a - $time_b;
			});

			// Merge other data (use new data, but keep events merged)
			$merged_data = array_merge( $existing_data, $data );
			$merged_data['events'] = $merged_events;

			// Use the duration from the new data (sent from frontend)
			// The frontend calculates duration from actual elapsed time (Date.now() - pageStartTime)
			// This is more accurate than calculating from event timestamps, which only shows
			// when interactions happened, not the total time user spent on page
			// For multi-page sessions, the frontend sends cumulative duration
			if ( isset( $data['duration'] ) && $data['duration'] > 0 ) {
				$merged_data['duration'] = (int) $data['duration'];
				$this->log( 'Using duration from frontend: ' . $merged_data['duration'] . 's', 'debug' );
			} else {
				// Fallback: calculate from event timestamps if no duration provided
				if ( count( $merged_events ) > 0 ) {
					$first_event = $merged_events[0];
					$last_event = $merged_events[ count( $merged_events ) - 1 ];
					$first_timestamp = isset( $first_event['timestamp'] ) ? $first_event['timestamp'] : 0;
					$last_timestamp = isset( $last_event['timestamp'] ) ? $last_event['timestamp'] : 0;
					$merged_data['duration'] = (int) floor( ( $last_timestamp - $first_timestamp ) / 1000 );
					$this->log( 'Fallback: Calculated duration from event timestamps: ' . $merged_data['duration'] . 's', 'debug' );
				}
			}

			$this->log( 'Merged events: ' . count( $existing_events ) . ' existing + ' . count( $new_events ) . ' new = ' . count( $merged_events ) . ' total', 'info' );
		}

		// Payload-size guard. A stuck/looping client (e.g. Pro trackers on a
		// cached page repeatedly replaying a growing pending payload) can merge an
		// unbounded event array, and gzcompress()/wp_json_encode() on a multi-MB
		// string is what exhausts PHP memory (Fatal: Allowed memory size, 02-Jul).
		// Cap the retained events to the most recent window before encoding, then
		// hard-cap the encoded byte size so one giant recording can never OOM.
		if ( isset( $merged_data['events'] ) && is_array( $merged_data['events'] ) ) {
			$max_events = 20000; // generous: a normal recording is well under this
			$event_count = count( $merged_data['events'] );
			if ( $event_count > $max_events ) {
				// Keep the most recent events (array is sorted oldest-first).
				$merged_data['events'] = array_slice( $merged_data['events'], -$max_events );
				$this->log( 'Payload guard: trimmed events ' . $event_count . ' -> ' . $max_events, 'warning' );
			}
		}

		// Prepare merged data
		$json_data = wp_json_encode( $merged_data );

		// Byte-size guard: refuse to compress/write a payload large enough to
		// risk OOM. Drop the oldest events until it fits; bail cleanly if a single
		// snapshot is still too large (better a skipped update than a fatal error).
		$max_bytes = 8 * 1024 * 1024; // 8 MB encoded
		if ( false !== $json_data && strlen( $json_data ) > $max_bytes
			&& isset( $merged_data['events'] ) && is_array( $merged_data['events'] ) ) {
			while ( strlen( $json_data ) > $max_bytes && count( $merged_data['events'] ) > 1 ) {
				// Drop the oldest ~10% of events per pass to converge quickly.
				$drop = max( 1, (int) floor( count( $merged_data['events'] ) * 0.1 ) );
				$merged_data['events'] = array_slice( $merged_data['events'], $drop );
				$json_data = wp_json_encode( $merged_data );
			}
			$this->log( 'Payload guard: byte-capped recording to ' . strlen( (string) $json_data ) . ' bytes', 'warning' );
			if ( false === $json_data || strlen( $json_data ) > $max_bytes ) {
				return array( 'error' => 'Recording payload exceeds size limit' );
			}
		}

		// Compress if enabled (check if original file was compressed)
		if ( substr( $file_path, -3 ) === '.gz' ) {
			$compressed = gzcompress( $json_data, 9 );
			$written = file_put_contents( $full_path, $compressed );
		} else {
			$written = file_put_contents( $full_path, $json_data );
		}

		if ( false === $written ) {
			return array( 'error' => 'Failed to write file' );
		}

		return array(
			'success'   => true,
			'file_path' => $file_path,
			'file_size' => filesize( $full_path ),
			'timestamp' => current_time( 'timestamp' ),
			'duration'  => isset( $merged_data['duration'] ) ? $merged_data['duration'] : 0,
		);
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
		}

		return json_decode( $content, true );
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

