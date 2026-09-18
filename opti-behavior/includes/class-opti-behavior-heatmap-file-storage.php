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
	 * The base recording file (`{session_id}_{timestamp}.json[.gz]` — note there
	 * is no `session_` prefix; see save_recording()) is written ONCE by
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
	 * SINGLE SOURCE OF TRUTH (Ticket B). This free-plugin file is the only
	 * declaration of Opti_Behavior_Heatmap_File_Storage. Pro used to ship a
	 * second, divergent copy of the whole class; because the free plugin
	 * require_once's this file before the Pro autoloader can fire, Pro's copy
	 * always lost the class_exists() race and every Pro-only behaviour in it was
	 * dead code (the 3-argument update_recording(), find_session_file(), the
	 * host-permission directory fallback, the post-write verification and the
	 * whole of its logging). Those behaviours now live here, and Pro's file is a
	 * shim that require_once's this one — so there is nothing left to mirror and
	 * nothing depends on load order.
	 *
	 * Pro may still be paired with an OLDER free build that lacks methods added
	 * here, so Pro guards its calls into the newer API with method_exists().
	 */
	const APPEND_SUFFIX = '.oblog';
	const INDEX_SUFFIX  = '.obidx';
	// Scratch file used by compact_recording() while it streams the folded
	// recording. Six characters like the other suffixes, so the same
	// `substr( $name, -6 )` sidecar filters catch it.
	const COMPACT_SUFFIX = '.obtmp';

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
	 * The debug manager is normally injected by the constructor. Callers that
	 * cannot inject one (Pro instantiates the class with no arguments — see the
	 * single-source-of-truth note on APPEND_SUFFIX) fall back to the core
	 * singleton, so file-storage logging is never silently dropped.
	 *
	 * @param string $message Message to log.
	 * @param string $level   Log level (error, warning, info, debug).
	 */
	private function log( $message, $level = 'info' ) {
		$manager = $this->debug_manager;

		if ( ! $manager && class_exists( 'Opti_Behavior_Heatmap_Core', false ) ) {
			$core = Opti_Behavior_Heatmap_Core::get_instance();
			if ( $core && method_exists( $core, 'get_debug_manager' ) ) {
				$manager = $core->get_debug_manager();
			}
		}

		if ( $manager ) {
			$manager->log( $message, $level, 'file-storage' );
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
	 * @return string|false Directory path, or false when it cannot be created/written.
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
			$created = wp_mkdir_p( $dir );

			if ( ! $created || ! is_dir( $dir ) ) {
				// wp_mkdir_p failed — try creating parent directories one by one.
				// Some hosts (Hostinger, shared cPanel) reject deep mkdir but allow step-by-step.
				$parts = array( $type, $year, $month, $day, $hour );
				$path  = $this->base_dir;
				foreach ( $parts as $part ) {
					$path .= $part . '/';
					if ( ! is_dir( $path ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged
						@mkdir( $path, 0755 );
					}
				}
			}

			// Final check — if still not writable, surface it instead of writing
			// into a path that does not exist (which silently lands the file in
			// the process working directory).
			if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
				$this->log( 'CRITICAL: cannot create/write data directory: ' . $dir, 'error' );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[Opti-Behavior] CRITICAL: Cannot create data directory: ' . $dir . ' — Check file permissions (755) on wp-content/uploads/opti-behavior-data/' );
				set_transient(
					'opti_behavior_file_storage_error',
					'Opti-Behavior cannot write session recordings. The directory <code>' . esc_html( $this->base_dir . $type . '/' ) . '</code> is not writable. Please set permissions to 755 or contact your hosting provider.',
					DAY_IN_SECONDS
				);
				return false;
			}

			// Add index.php to prevent directory listing.
			$index = $dir . 'index.php';
			if ( ! file_exists( $index ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
			}
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

		// If directory creation failed, return an error so the caller can fall
		// back to database storage instead of writing to an unusable path.
		if ( false === $dir ) {
			$this->log( 'Cannot save recording: directory creation failed', 'error' );
			return array( 'error' => 'Directory creation failed - check file permissions on wp-content/uploads/opti-behavior-data/' );
		}

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
			$this->log( 'ERROR: file_put_contents returned false for: ' . $filepath, 'error' );
			return array( 'error' => 'Failed to write file - file_put_contents returned false' );
		}

		// Verify the file really landed. A truncated/refused write can still
		// return a byte count on some hosts, and an empty base file would make
		// every later read of this recording fail.
		if ( ! file_exists( $filepath ) ) {
			$this->log( 'ERROR: file does not exist after write: ' . $filepath, 'error' );
			return array( 'error' => 'Failed to write file - file does not exist after write' );
		}

		$actual_size = filesize( $filepath );
		if ( 0 === (int) $actual_size ) {
			$this->log( 'ERROR: file is empty after write: ' . $filepath, 'error' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $filepath );
			return array( 'error' => 'Failed to write file - file is empty after write' );
		}

		$this->log( 'File saved successfully: ' . $filepath . ' (' . $actual_size . ' bytes)', 'debug' );

		// Seed the running-counter sidecar straight away (bug #2 / D2) so the
		// event-timestamp span of the very first batch is available before any
		// append happens. Without it the first recordings row would fall back to
		// the client wall clock.
		$seed_events = isset( $data['events'] ) && is_array( $data['events'] ) ? $data['events'] : array();
		$bounds      = $this->scan_events_timestamp_bounds( $seed_events );
		$this->write_recording_index(
			$filepath . self::INDEX_SUFFIX,
			array(
				'events'   => count( $seed_events ),
				'duration' => isset( $data['duration'] ) ? (int) $data['duration'] : 0,
				'first_ts' => null === $bounds ? 0 : $bounds['min'],
				'last_ts'  => null === $bounds ? 0 : $bounds['max'],
				// OOM fix (F1): running click tally, so the save path never has to
				// re-read the whole recording just to recompute `click_count`.
				'clicks'   => self::count_click_events( $seed_events ),
			)
		);

		return array(
			'success'         => true,
			'file_path'       => str_replace( $this->base_dir, '', $filepath ),
			'file_size'       => $actual_size,
			'timestamp'       => $timestamp,
			'replay_duration' => self::replay_duration_from_bounds( null === $bounds ? 0 : $bounds['min'], null === $bounds ? 0 : $bounds['max'] ),
		);
	}

	/**
	 * Min/max `timestamp` across a batch of events.
	 *
	 * Batches are NOT guaranteed to be element-ordered (rrweb can flush an
	 * out-of-order tail, and merged `.oblog` batches are concatenated in save
	 * order), so the span must come from min/max, never from first/last.
	 *
	 * @param array $events Event array.
	 * @return array|null array( 'min' => int, 'max' => int ) or null when the
	 *                    batch carries no usable timestamp.
	 */
	private function scan_events_timestamp_bounds( $events ) {
		if ( ! is_array( $events ) || empty( $events ) ) {
			return null;
		}

		$min = null;
		$max = null;
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['timestamp'] ) || ! is_numeric( $event['timestamp'] ) ) {
				continue;
			}
			$ts = (int) $event['timestamp'];
			if ( $ts <= 0 ) {
				continue;
			}
			if ( null === $min || $ts < $min ) {
				$min = $ts;
			}
			if ( null === $max || $ts > $max ) {
				$max = $ts;
			}
		}

		if ( null === $min ) {
			return null;
		}

		return array( 'min' => $min, 'max' => $max );
	}

	/**
	 * Count the click events inside a batch (OOM fix F1).
	 *
	 * Single source of truth for the recordings-row `click_count`, shared by the
	 * running `.obidx` tally and by the Pro save path. Both historical rules are
	 * applied so the number cannot shift when the tally moved off the read path:
	 *   - rrweb MouseInteraction click: type 3, data.source 2, data.type 2
	 *   - enhanced click metadata:      type 5, data.tag === 'click_metadata'
	 *
	 * @param array $events Event batch.
	 * @return int Number of click events in the batch.
	 */
	public static function count_click_events( $events ) {
		if ( ! is_array( $events ) ) {
			return 0;
		}

		$clicks = 0;
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['type'] ) ) {
				continue;
			}
			$type = (int) $event['type'];
			if ( 3 === $type
				&& isset( $event['data']['source'] ) && 2 === (int) $event['data']['source']
				&& isset( $event['data']['type'] ) && 2 === (int) $event['data']['type'] ) {
				++$clicks;
				continue;
			}
			if ( 5 === $type
				&& isset( $event['data']['tag'] ) && 'click_metadata' === $event['data']['tag'] ) {
				++$clicks;
			}
		}

		return $clicks;
	}

	/**
	 * Replay duration (seconds) from a first/last event timestamp pair.
	 *
	 * This is the length a user can actually WATCH, as opposed to the wall-clock
	 * session duration. It is deliberately NOT capped at the recording
	 * collection caps (`max_duration` 600 / server 1800): those bound what is
	 * collected, not how much of it exists.
	 *
	 * Rounding convention: durations round UP (ceil), matching the player's
	 * `#total-time`, so the recordings list, the payload meta and the control
	 * bar agree to the second (a 14.4 s span reads 0:15 everywhere).
	 *
	 * @param int $first_ts Epoch milliseconds of the first event.
	 * @param int $last_ts  Epoch milliseconds of the last event.
	 * @return int Seconds, never negative.
	 */
	public static function replay_duration_from_bounds( $first_ts, $last_ts ) {
		$first_ts = (int) $first_ts;
		$last_ts  = (int) $last_ts;
		if ( $first_ts <= 0 || $last_ts <= 0 || $last_ts <= $first_ts ) {
			return 0;
		}
		return (int) ceil( ( $last_ts - $first_ts ) / 1000 );
	}

	/**
	 * Update an existing recording file
	 *
	 * @param string      $file_path  Relative file path (from database)
	 * @param array       $data       Recording data to save
	 * @param string|null $session_id Optional session ID. When the base file has
	 *                                gone missing (manual deletion, a failed
	 *                                first save that still stored a file_path, an
	 *                                over-eager cleanup) the recording is
	 *                                recreated under this session instead of the
	 *                                update being lost.
	 * @return array Result with file_path and file_size or error
	 */
	public function update_recording( $file_path, $data, $session_id = null ) {
		// Check if file storage is enabled
		if ( 'database' === $this->settings['storage_mode'] ) {
			return array( 'error' => 'File storage is disabled' );
		}

		// Get full file path
		$full_path = $this->base_dir . $file_path;

		// Check if file exists — if not, recreate it rather than dropping the batch.
		if ( ! file_exists( $full_path ) ) {
			$this->log( 'File not found during update: ' . $file_path . ' - creating new file', 'warning' );

			if ( ! empty( $session_id ) ) {
				return $this->save_recording( $session_id, $data );
			}

			// Recover the session ID from the filename. save_recording() writes
			// `{session_id}_{timestamp}.json[.gz]`, and a session ID itself
			// contains underscores (`sess_anon_<hex>_<n>`), so anchor on the
			// trailing timestamp and take everything before it. Greedy `.+`
			// keeps the longest prefix, i.e. the whole session ID.
			if ( preg_match( '/^(.+)_\d+\.json(?:\.gz)?$/', basename( $file_path ), $matches ) ) {
				$this->log( 'Extracted session_id from path: ' . $matches[1], 'debug' );
				return $this->save_recording( $matches[1], $data );
			}

			return array(
				'error'        => 'File does not exist and cannot create new: ' . $file_path,
				'file_missing' => true,
			);
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
		} elseif ( $idx['first_ts'] <= 0 ) {
			// Sidecar written before the replay-duration tracking existed
			// (bug #2 / D2). Seed the timestamp bounds once from the base file
			// so the span starts at the real first event, then let later
			// appends extend `last_ts`.
			$seed             = $this->init_recording_index( $full_path, $file_path );
			$idx['first_ts']  = $seed['first_ts'];
			$idx['last_ts']   = max( (int) $idx['last_ts'], (int) $seed['last_ts'] );
		}

		$new_events   = isset( $data['events'] ) && is_array( $data['events'] ) ? array_values( $data['events'] ) : array();
		$new_duration = isset( $data['duration'] ) ? (int) $data['duration'] : 0;

		// Size cap (spec §G.4): combined on-disk bytes of base + append sidecar.
		$max_bytes     = (int) apply_filters( 'opti_behavior_recording_max_file_bytes', 50 * MB_IN_BYTES );
		$combined_size = (int) @filesize( $full_path ) + ( is_file( $log_path ) ? (int) @filesize( $log_path ) : 0 );
		// Encode once: the cap is checked AGAINST the size after this append
		// (base + log + incoming line), so a single large batch can no longer
		// push a recording past the cap before the check trips.
		$encoded_events = wp_json_encode( $new_events );
		$incoming_bytes = strlen( (string) $encoded_events ) + 1; // + "\n".
		if ( $max_bytes > 0 && ( $combined_size + $incoming_bytes ) > $max_bytes ) {
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
				'success'         => true,
				'file_path'       => $file_path,
				'file_size'       => $combined_size,
				'timestamp'       => current_time( 'timestamp' ),
				'duration'        => (int) $idx['duration'],
				'replay_duration' => self::replay_duration_from_bounds( $idx['first_ts'], $idx['last_ts'] ),
			);
		}

		// Append the batch as one NDJSON line (a JSON array of events).
		$written = @file_put_contents( $log_path, $encoded_events . "\n", FILE_APPEND | LOCK_EX );
		if ( false === $written ) {
			$this->log( 'Append write failed for: ' . $log_path, 'warning' );
			return array( 'error' => 'Failed to write file - append failed' );
		}

		$idx['events']  += count( $new_events );
		$idx['duration'] = max( (int) $idx['duration'], $new_duration );

		// OOM fix (F1): keep the click tally running on the write path, where the
		// batch is already in memory. `-1` means "unknown" (a legacy sidecar with
		// no `clicks` key) and stays unknown until recount_recording_counters()
		// rebuilds it once — adding to an unknown base would invent a number.
		if ( isset( $idx['clicks'] ) && (int) $idx['clicks'] >= 0 ) {
			$idx['clicks'] = (int) $idx['clicks'] + self::count_click_events( $new_events );
		}

		// Extend the replay span with this batch (bug #2 / D2).
		$bounds = $this->scan_events_timestamp_bounds( $new_events );
		if ( null !== $bounds ) {
			$idx['first_ts'] = ( (int) $idx['first_ts'] > 0 ) ? min( (int) $idx['first_ts'], $bounds['min'] ) : $bounds['min'];
			$idx['last_ts']  = max( (int) $idx['last_ts'], $bounds['max'] );
		}

		$this->write_recording_index( $idx_path, $idx );

		$combined_size = (int) @filesize( $full_path ) + (int) @filesize( $log_path );

		return array(
			'success'         => true,
			'file_path'       => $file_path,
			'file_size'       => $combined_size,
			'timestamp'       => current_time( 'timestamp' ),
			'duration'        => (int) $idx['duration'],
			'replay_duration' => self::replay_duration_from_bounds( $idx['first_ts'], $idx['last_ts'] ),
		);
	}

	/**
	 * Read the running-counter sidecar (`.obidx`) for a recording.
	 *
	 * @param string $idx_path Absolute path to the `.obidx` file.
	 * @return array|null array( 'events', 'duration', 'first_ts', 'last_ts' ) or null if absent/unreadable.
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
			'events'    => (int) $decoded['events'],
			'duration'  => isset( $decoded['duration'] ) ? (int) $decoded['duration'] : 0,
			// Sidecars written before bug #2 carry no timestamp bounds; 0 means
			// "unknown" and callers fall back to the wall-clock duration.
			'first_ts'  => isset( $decoded['first_ts'] ) ? (int) $decoded['first_ts'] : 0,
			'last_ts'   => isset( $decoded['last_ts'] ) ? (int) $decoded['last_ts'] : 0,
			// OOM fix (F1): sidecars written before the click tally carry no
			// `clicks` key. `-1` is UNKNOWN and must stay distinct from a real 0,
			// or every legacy recording would be zeroed on its next save.
			'clicks'    => isset( $decoded['clicks'] ) ? (int) $decoded['clicks'] : -1,
			// Ticket A: number of times compact_recording() has folded the
			// `.oblog` sidecar into the base file. Non-zero means the base holds
			// concatenated (save-order) batches, so read_recording() must sort.
			'compacted' => isset( $decoded['compacted'] ) ? (int) $decoded['compacted'] : 0,
		);
	}

	/**
	 * Public accessor for a recording's running counters (`.obidx` sidecar).
	 *
	 * Kept in sync with the Pro storage class: the Pro playback layer reads
	 * these to report honest `event_count` / `duration` values when streaming a
	 * large recording, instead of estimating them from the base file size.
	 *
	 * `replay_duration` (bug #2 / D2) is the event-timestamp span — the length a
	 * user can actually watch — as opposed to `duration`, which is the client
	 * wall clock kept for spam scoring and analytics.
	 *
	 * `clicks` (OOM fix F1) is the running click tally that lets the save path
	 * report `click_count` without ever loading the recording. `-1` means the
	 * sidecar predates the tally — call recount_recording_counters() once.
	 *
	 * @param string $file_path Relative file path (from the database).
	 * @return array|null array( 'events', 'duration', 'first_ts', 'last_ts', 'clicks', 'replay_duration' ) or null when absent.
	 */
	public function get_recording_counters( $file_path ) {
		if ( empty( $file_path ) ) {
			return null;
		}
		$idx = $this->read_recording_index( $this->base_dir . $file_path . self::INDEX_SUFFIX );
		if ( null === $idx ) {
			return null;
		}
		$idx['replay_duration'] = self::replay_duration_from_bounds( $idx['first_ts'], $idx['last_ts'] );
		return $idx;
	}

	/**
	 * Write the running-counter sidecar (`.obidx`). Tiny, O(1) overwrite.
	 *
	 * @param string $idx_path Absolute path to the `.obidx` file.
	 * @param array  $idx      array( 'events', 'duration', 'first_ts', 'last_ts' ).
	 * @return void
	 */
	private function write_recording_index( $idx_path, $idx ) {
		@file_put_contents(
			$idx_path,
			wp_json_encode( array(
				'events'    => (int) $idx['events'],
				'duration'  => (int) $idx['duration'],
				'first_ts'  => isset( $idx['first_ts'] ) ? (int) $idx['first_ts'] : 0,
				'last_ts'   => isset( $idx['last_ts'] ) ? (int) $idx['last_ts'] : 0,
				'clicks'    => isset( $idx['clicks'] ) ? (int) $idx['clicks'] : -1,
				'compacted' => isset( $idx['compacted'] ) ? (int) $idx['compacted'] : 0,
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
	 * @return array array( 'events', 'duration', 'first_ts', 'last_ts' ).
	 */
	private function init_recording_index( $full_path, $file_path ) {
		// `clicks` starts UNKNOWN (-1): if the base file cannot be decoded below,
		// a 0 would be indistinguishable from a genuine click-free recording.
		$idx = array( 'events' => 0, 'duration' => 0, 'first_ts' => 0, 'last_ts' => 0, 'clicks' => -1 );

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
		unset( $file_contents );
		if ( is_array( $decoded ) ) {
			$idx['clicks'] = 0;
			if ( isset( $decoded['events'] ) && is_array( $decoded['events'] ) ) {
				$idx['events'] = count( $decoded['events'] );
				$idx['clicks'] = self::count_click_events( $decoded['events'] );
				$bounds        = $this->scan_events_timestamp_bounds( $decoded['events'] );
				if ( null !== $bounds ) {
					$idx['first_ts'] = $bounds['min'];
					$idx['last_ts']  = $bounds['max'];
				}
			}
			if ( isset( $decoded['duration'] ) ) {
				$idx['duration'] = (int) $decoded['duration'];
			}
		}

		return $idx;
	}

	/**
	 * Rebuild the `.obidx` event/click counters for a recording whose sidecar
	 * predates the click tally (OOM fix F2).
	 *
	 * Memory-bounded by construction: the base file is decoded once (it holds a
	 * single save batch in the append-only era) and the `.oblog` sidecar is walked
	 * one NDJSON line at a time, each batch released before the next is read — the
	 * whole recording is NEVER materialised at once. Recordings whose combined
	 * on-disk size exceeds `opti_behavior_recording_merge_max_bytes` are refused
	 * outright (null), so the caller freezes the previous counts instead of
	 * risking the fatal this fix exists to remove.
	 *
	 * The recovered counters are persisted, so the walk happens at most once per
	 * legacy recording.
	 *
	 * @param string $file_path Relative file path (from the database).
	 * @return array|null Same shape as get_recording_counters(), or null when the
	 *                    recount was refused / impossible.
	 */
	public function recount_recording_counters( $file_path ) {
		if ( empty( $file_path ) ) {
			return null;
		}

		$full_path = $this->base_dir . $file_path;
		if ( ! is_file( $full_path ) ) {
			return null;
		}

		$log_path  = $full_path . self::APPEND_SUFFIX;
		$log_size  = is_file( $log_path ) ? (int) @filesize( $log_path ) : 0;
		$combined  = (int) @filesize( $full_path ) + $log_size;
		$threshold = (int) apply_filters( 'opti_behavior_recording_merge_max_bytes', 24 * MB_IN_BYTES );
		if ( $threshold > 0 && $combined > $threshold ) {
			static $recount_refused_logged = array();
			if ( ! isset( $recount_refused_logged[ $file_path ] ) ) {
				$recount_refused_logged[ $file_path ] = true;
				$this->log( 'Counter recount refused (' . $combined . ' > ' . $threshold . ' bytes): ' . $file_path, 'warning' );
			}
			return null;
		}

		// Ticket A: same shared lock read_recording() takes — a recount that
		// straddled a compaction swap would count the folded batches twice.
		$read_lock = $this->lock_append_log_shared( $log_path );

		// Base file: one decode, released immediately.
		$content = @file_get_contents( $full_path );
		if ( false === $content ) {
			$this->release_append_log_lock( $read_lock );
			return null;
		}
		if ( substr( $file_path, -3 ) === '.gz' ) {
			$content = @gzuncompress( $content );
			if ( false === $content ) {
				$this->release_append_log_lock( $read_lock );
				return null;
			}
		}
		$decoded = json_decode( $content, true );
		unset( $content );
		if ( ! is_array( $decoded ) ) {
			$this->release_append_log_lock( $read_lock );
			return null;
		}

		$events = 0;
		$clicks = 0;
		if ( isset( $decoded['events'] ) && is_array( $decoded['events'] ) ) {
			$events = count( $decoded['events'] );
			$clicks = self::count_click_events( $decoded['events'] );
		}
		unset( $decoded );

		// Sidecar: one batch in memory at a time, never the whole log.
		if ( $log_size > 0 ) {
			// Streaming line-by-line read of the append-only sidecar; loading it whole is exactly the fatal this method avoids.
			$handle = @fopen( $log_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false !== $handle ) {
				while ( ( $line = fgets( $handle ) ) !== false ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$batch = json_decode( $line, true );
					unset( $line );
					// A crash mid-append can leave a torn line; skip it exactly the
					// way merge_append_log() and the playback streamer do, so the
					// counters cannot diverge from what playback shows.
					if ( ! is_array( $batch ) ) {
						continue;
					}
					$events += count( $batch );
					$clicks += self::count_click_events( $batch );
					unset( $batch );
				}
				// Closing the streaming read handle opened above.
				@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}

		$this->release_append_log_lock( $read_lock );

		$idx_path = $full_path . self::INDEX_SUFFIX;
		$idx      = $this->read_recording_index( $idx_path );
		if ( null === $idx ) {
			$idx = array( 'events' => $events, 'duration' => 0, 'first_ts' => 0, 'last_ts' => 0, 'clicks' => $clicks, 'compacted' => 0 );
		}
		// A concurrent append can bump the sidecar between our walk and this
		// write; max() makes the counter monotone so no batch is ever lost.
		$idx['events'] = max( (int) $idx['events'], $events );
		$idx['clicks'] = $clicks;
		$this->write_recording_index( $idx_path, $idx );

		$idx['replay_duration'] = self::replay_duration_from_bounds( $idx['first_ts'], $idx['last_ts'] );

		return $idx;
	}

	/**
	 * Merge the append-only sidecar events into the base recording data.
	 *
	 * Each sidecar line is a JSON array of events appended by update_recording().
	 * Events are concatenated onto the base events and sorted once by timestamp,
	 * reproducing the chronological order the old rewrite-every-save code kept.
	 *
	 * @param array  $data       Base recording data (decoded).
	 * @param string $log_path   Absolute path to the `.oblog` sidecar.
	 * @param bool   $force_sort Sort even when the sidecar contributed nothing
	 *                           (used for compacted bases, whose events are
	 *                           concatenated in save order inside the file).
	 * @return array Recording data with merged, time-ordered events.
	 */
	private function merge_append_log( &$data, $log_path, $force_sort = false ) {
		if ( ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
			$data['events'] = array();
		}

		// Streaming line-by-line read of the append-only sidecar log; WP_Filesystem has no streaming reader and loading the whole file at once defeats the memory-bounded parse. Sidecar may be absent/locked under concurrent writes.
		$handle = @fopen( $log_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			if ( $force_sort ) {
				$this->sort_events_by_timestamp( $data['events'] );
			}
			return $data;
		}

		// OOM fix (F3): append straight into the (by-reference) target array and
		// release each decoded batch immediately. The old code accumulated the
		// whole sidecar in `$appended` and then array_merge()d it, holding three
		// copies of the event set at once — that peak is what exhausted memory.
		$appended = 0;
		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$batch = json_decode( $line, true );
			unset( $line );
			if ( is_array( $batch ) ) {
				foreach ( $batch as $evt ) {
					$data['events'][] = $evt;
					++$appended;
				}
				unset( $batch );
			}
		}
		// Closing the streaming read handle opened above; handle guaranteed valid here.
		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $appended > 0 || $force_sort ) {
			$this->sort_events_by_timestamp( $data['events'] );
		}

		return $data;
	}

	/**
	 * Stable-enough chronological ordering for a merged event array.
	 *
	 * Extracted so the compacted-base path (Ticket A) sorts through exactly the
	 * same comparator the sidecar merge has always used — the read-back order of
	 * a recording must not depend on whether it has been compacted yet.
	 *
	 * @param array $events Events array, sorted in place.
	 * @return void
	 */
	private function sort_events_by_timestamp( &$events ) {
		if ( ! is_array( $events ) || count( $events ) < 2 ) {
			return;
		}
		usort( $events, function( $a, $b ) {
			$ts_a = isset( $a['timestamp'] ) ? $a['timestamp'] : 0;
			$ts_b = isset( $b['timestamp'] ) ? $b['timestamp'] : 0;
			return $ts_a <=> $ts_b;
		} );
	}

	/**
	 * Take a shared (reader) lock on a recording's `.oblog` sidecar.
	 *
	 * Ticket A. Compaction swaps the base file and truncates the sidecar under an
	 * exclusive lock; a reader that straddles those two operations would see the
	 * folded batches twice. Holding LOCK_SH across the base read + sidecar merge
	 * makes the pair atomic from the reader's side. Shared locks do not block
	 * each other, so concurrent playback is unaffected.
	 *
	 * @param string $log_path Absolute path to the `.oblog` sidecar.
	 * @return resource|null Locked handle, or null when there is nothing to lock.
	 */
	private function lock_append_log_shared( $log_path ) {
		if ( ! is_file( $log_path ) ) {
			return null;
		}
		// Shared read lock on the append sidecar; WP_Filesystem exposes no locking primitive.
		$handle = @fopen( $log_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return null;
		}
		if ( ! @flock( $handle, LOCK_SH ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// Closing the lock handle acquired above.
			@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return null;
		}
		return $handle;
	}

	/**
	 * Release a lock taken by lock_append_log_shared().
	 *
	 * @param resource|null $handle Locked handle (or null).
	 * @return void
	 */
	private function release_append_log_lock( $handle ) {
		if ( ! is_resource( $handle ) ) {
			return;
		}
		@flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// Closing the lock handle taken by lock_append_log_shared().
		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Cheap stat-only test for "is this recording worth compacting right now?"
	 * (Ticket A).
	 *
	 * Called from the save path, so it must never read or decode anything — two
	 * filesize() calls and nothing else.
	 *
	 * The trigger is a GROWTH RATIO, not just an absolute size. Compacting every
	 * time the sidecar crosses a fixed byte threshold would rewrite the whole base
	 * file once per threshold-worth of new events, i.e. O(N^2) bytes over a long
	 * session — the exact cost profile the append-only refactor existed to remove.
	 * Waiting until the sidecar is at least as large as the base it would be folded
	 * into (the standard log-structured-merge rule) makes the total rewrite work
	 * amortised O(N log N) instead, because each compaction at least doubles the
	 * distance to the next one.
	 *
	 * @param string $file_path Relative file path (from the database).
	 * @return bool True when compaction should be scheduled.
	 */
	public function should_compact_recording( $file_path ) {
		if ( empty( $file_path ) ) {
			return false;
		}

		$full_path = $this->base_dir . $file_path;
		$log_path  = $full_path . self::APPEND_SUFFIX;

		if ( ! is_file( $log_path ) || ! is_file( $full_path ) ) {
			return false;
		}

		$log_size  = (int) @filesize( $log_path );
		$base_size = (int) @filesize( $full_path );

		$min_bytes = (int) apply_filters( 'opti_behavior_recording_compaction_min_bytes', 512 * KB_IN_BYTES );
		if ( $log_size < $min_bytes ) {
			return false;
		}

		$max_bytes = (int) apply_filters( 'opti_behavior_recording_compaction_max_bytes', (int) apply_filters( 'opti_behavior_recording_max_file_bytes', 50 * MB_IN_BYTES ) );
		if ( $max_bytes > 0 && ( $base_size + $log_size ) > $max_bytes ) {
			return false;
		}

		$ratio = (float) apply_filters( 'opti_behavior_recording_compaction_growth_ratio', 1.0 );
		if ( $ratio > 0 && $base_size > 0 && $log_size < ( $base_size * $ratio ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Fold the `.oblog` append sidecar back into the base recording file
	 * (Ticket A — sidecar compaction).
	 *
	 * WHY: update_recording() only ever appends, and nothing ever folded the
	 * sidecar back. `.oblog` is uncompressed NDJSON, so a long session grows it
	 * monotonically towards the 50 MB combined write cap; every read (playback,
	 * export, legacy recount) pays for the whole of it, and once the combined
	 * size passes `opti_behavior_recording_merge_max_bytes` the array reader can
	 * only return base events flagged `truncated`. Compaction moves those bytes
	 * into the base file — which is gzip-compressed for the vast majority of
	 * installs — so combined size typically drops by an order of magnitude and
	 * the recording comes back under the read guard instead of drifting past it.
	 *
	 * BOUNDED MEMORY BY CONSTRUCTION: nothing is decoded. The base file is
	 * inflated in 64 KB chunks and re-deflated incrementally into a scratch file,
	 * and the sidecar is copied one NDJSON line at a time, each line validated
	 * structurally (no json_decode) and released before the next. Peak memory is
	 * O(chunk + longest sidecar line), independent of the recording size — the
	 * whole point, given this exists to remove an OOM.
	 *
	 * ATOMICITY: the scratch file is renamed over the base file and the sidecar
	 * is truncated in the same exclusive-lock window, and readers hold LOCK_SH
	 * across their base+sidecar read, so no reader can observe the folded batches
	 * twice (or not at all). A crash mid-write leaves the scratch file behind and
	 * touches nothing else — the recording stays exactly as it was.
	 *
	 * @param string $file_path Relative file path (from the database).
	 * @return array array( 'compacted' => bool, 'reason' => string, 'bytes_before' => int,
	 *               'bytes_after' => int, 'events' => int ).
	 */
	public function compact_recording( $file_path ) {
		$result = array(
			'compacted'    => false,
			'reason'       => '',
			'file_path'    => $file_path,
			'bytes_before' => 0,
			'bytes_after'  => 0,
			'events'       => 0,
		);

		if ( empty( $file_path ) ) {
			$result['reason'] = 'no_path';
			return $result;
		}

		$full_path = $this->base_dir . $file_path;
		if ( ! is_file( $full_path ) ) {
			$result['reason'] = 'missing_base';
			return $result;
		}

		$log_path = $full_path . self::APPEND_SUFFIX;
		if ( ! is_file( $log_path ) ) {
			$result['reason'] = 'no_sidecar';
			return $result;
		}

		$base_size = (int) @filesize( $full_path );
		$log_size  = (int) @filesize( $log_path );

		$result['bytes_before'] = $base_size + $log_size;
		$result['bytes_after']  = $result['bytes_before'];

		// Not worth the rewrite below this — a small sidecar costs nothing to
		// merge and compacting on every tick would just churn the disk.
		$min_bytes = (int) apply_filters( 'opti_behavior_recording_compaction_min_bytes', 512 * KB_IN_BYTES );
		if ( $log_size < $min_bytes ) {
			$result['reason'] = 'below_threshold';
			return $result;
		}

		// Upper bound. NOTE: this is deliberately NOT the 24 MB
		// `opti_behavior_recording_merge_max_bytes` read guard. Compaction never
		// performs an in-memory merge, and refusing at the read guard would leave
		// exactly the recordings that already exceed it stuck there forever —
		// compaction is the mechanism that brings them back under it. The cap
		// here just stops a runaway file from monopolising a cron tick.
		$max_bytes = (int) apply_filters( 'opti_behavior_recording_compaction_max_bytes', (int) apply_filters( 'opti_behavior_recording_max_file_bytes', 50 * MB_IN_BYTES ) );
		if ( $max_bytes > 0 && $result['bytes_before'] > $max_bytes ) {
			$this->log( 'Compaction skipped, recording over the compaction cap (' . $result['bytes_before'] . ' > ' . $max_bytes . ' bytes): ' . $file_path, 'warning' );
			$result['reason'] = 'too_large';
			return $result;
		}

		// Exclusive, NON-BLOCKING: a save beacon appending right now holds LOCK_EX
		// on the sidecar. Compaction is opportunistic housekeeping, so it yields
		// to the write path and retries on the next tick rather than stalling it.
		// Exclusive lock on the append sidecar for the swap; WP_Filesystem has no locking primitive.
		$lock = @fopen( $log_path, 'rb+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $lock ) {
			$result['reason'] = 'sidecar_unreadable';
			return $result;
		}
		if ( ! @flock( $lock, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// Closing the lock handle opened above.
			@fclose( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$result['reason'] = 'busy';
			return $result;
		}

		$tmp_path = $full_path . self::COMPACT_SUFFIX;
		if ( file_exists( $tmp_path ) ) {
			// Leftover from a crashed run: it was never swapped in, so it is safe
			// (and necessary) to drop it before reusing the path.
			wp_delete_file( $tmp_path );
		}

		$encoding = $this->detect_recording_encoding( $full_path );
		if ( false === $encoding ) {
			$this->finish_compaction( $lock, $tmp_path );
			$result['reason'] = 'unreadable_base';
			return $result;
		}

		// Scratch output for the folded recording; renamed over the base below.
		$out = @fopen( $tmp_path, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			$this->finish_compaction( $lock, $tmp_path );
			$result['reason'] = 'tmp_unwritable';
			return $result;
		}

		$deflate = null;
		if ( 'plain' !== $encoding ) {
			$deflate = deflate_init( 'gzip' === $encoding ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE, array( 'level' => 9 ) );
			if ( ! $deflate ) {
				// Closing the scratch handle before bailing out.
				@fclose( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$this->finish_compaction( $lock, $tmp_path );
				$result['reason'] = 'deflate_init_failed';
				return $result;
			}
		}

		$write_failed = false;
		$write        = function ( $chunk ) use ( $out, $deflate, &$write_failed ) {
			if ( $write_failed || ! is_string( $chunk ) || '' === $chunk ) {
				return;
			}
			if ( $deflate ) {
				$chunk = deflate_add( $deflate, $chunk, ZLIB_NO_FLUSH );
				if ( false === $chunk ) {
					$write_failed = true;
					return;
				}
				if ( '' === $chunk ) {
					return;
				}
			}
			if ( false === fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				$write_failed = true;
			}
		};

		// Copies the sidecar into the events array, one NDJSON line at a time.
		$emitted_any = false;
		$injected    = 0;
		$inject      = function () use ( $lock, $log_path, $write, &$emitted_any, &$injected ) {
			rewind( $lock );
			while ( ( $line = fgets( $lock ) ) !== false ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				// Structural validation only — never json_decode, so a fat batch
				// costs its own bytes and not 10-20x of them as PHP arrays. A
				// torn line left by a crash mid-append is skipped exactly the way
				// merge_append_log() and the playback streamer skip it, so
				// compaction cannot change what a reader sees.
				$line_events = $this->scan_json_array_line_events( $line );
				if ( $line_events < 0 ) {
					$this->log( 'Compaction skipped a malformed .oblog line (' . strlen( $line ) . ' bytes) in ' . $log_path, 'warning' );
					continue;
				}
				$inner = trim( (string) substr( $line, 1, -1 ) );
				unset( $line );
				if ( '' === $inner ) {
					continue;
				}
				if ( $emitted_any ) {
					$write( ',' );
				}
				$write( $inner );
				unset( $inner );
				$emitted_any = true;
				$injected   += $line_events;
			}
		};

		$base_events = $this->stream_recording_events( $full_path, $encoding, $write, $inject, $emitted_any );

		if ( null !== $deflate && ! $write_failed ) {
			$tail = deflate_add( $deflate, '', ZLIB_FINISH );
			if ( false === $tail ) {
				$write_failed = true;
			} elseif ( '' !== $tail && false === fwrite( $out, $tail ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				$write_failed = true;
			}
		}
		// Closing the scratch handle; everything is flushed at this point.
		@fclose( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $base_events < 0 || $write_failed ) {
			$this->log( 'Compaction aborted before the swap (base_events=' . $base_events . ', write_failed=' . ( $write_failed ? 'yes' : 'no' ) . '): ' . $file_path, 'warning' );
			$this->finish_compaction( $lock, $tmp_path );
			$result['reason'] = $write_failed ? 'write_failed' : 'base_unparsable';
			return $result;
		}

		$expected = $base_events + $injected;

		// Verify the scratch file before it replaces anything: re-stream it and
		// count. Catches a truncated write or a corrupt deflate stream while the
		// original files are still untouched — and costs one more bounded pass.
		$verify_seen     = false;
		$verify_encoding = $this->detect_recording_encoding( $tmp_path );
		$verified        = ( false === $verify_encoding ) ? -1 : $this->stream_recording_events( $tmp_path, $verify_encoding, null, null, $verify_seen );
		if ( $verified !== $expected ) {
			$this->log( 'Compaction verification failed (' . $verified . ' events in the rewritten file, expected ' . $expected . ') - original left intact: ' . $file_path, 'error' );
			$this->finish_compaction( $lock, $tmp_path );
			$result['reason'] = 'verify_failed';
			return $result;
		}

		// Swap + truncate inside the same exclusive-lock window, so a reader
		// holding LOCK_SH never observes a half-applied compaction.
		if ( ! @rename( $tmp_path, $full_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory swap under an exclusive flock; WP_Filesystem::move() is copy+delete (non-atomic) and unavailable on cron.
			$this->log( 'Compaction could not swap the rewritten file in: ' . $file_path, 'error' );
			$this->finish_compaction( $lock, $tmp_path );
			$result['reason'] = 'swap_failed';
			return $result;
		}
		ftruncate( $lock, 0 );
		fflush( $lock );

		clearstatcache( true, $full_path );
		clearstatcache( true, $log_path );

		// Counters do not change (nothing was added or dropped) — only the
		// `compacted` generation, which tells read_recording() the base now holds
		// save-ordered batches and must be sorted.
		$idx_path = $full_path . self::INDEX_SUFFIX;
		$idx      = $this->read_recording_index( $idx_path );
		if ( null === $idx ) {
			$idx = array(
				'events'    => $expected,
				'duration'  => 0,
				'first_ts'  => 0,
				'last_ts'   => 0,
				'clicks'    => -1,
				'compacted' => 0,
			);
		}
		$idx['compacted'] = (int) $idx['compacted'] + 1;
		$this->write_recording_index( $idx_path, $idx );

		$this->finish_compaction( $lock, $tmp_path );

		$result['compacted']   = true;
		$result['reason']      = 'ok';
		$result['events']      = $expected;
		$result['bytes_after'] = (int) @filesize( $full_path );

		$this->log(
			'Compacted recording ' . $file_path . ': ' . $result['bytes_before'] . ' -> ' . $result['bytes_after'] .
			' bytes (' . $expected . ' events, sidecar reclaimed ' . $log_size . ' bytes)',
			'info'
		);

		return $result;
	}

	/**
	 * Release the compaction lock and drop the scratch file if it survived.
	 *
	 * @param resource $lock     Exclusive lock handle on the `.oblog` sidecar.
	 * @param string   $tmp_path Scratch file path.
	 * @return void
	 */
	private function finish_compaction( $lock, $tmp_path ) {
		if ( file_exists( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}
		if ( is_resource( $lock ) ) {
			@flock( $lock, LOCK_UN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// Closing the exclusive lock handle taken by compact_recording().
			@fclose( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Detect how a recording file is stored on disk, from its magic bytes.
	 *
	 * The `.gz` filename suffix is not authoritative (compression is a setting
	 * that can change between saves), so compaction re-encodes with whatever the
	 * file itself actually uses — a base written by gzcompress() must stay
	 * gzuncompress()-readable afterwards.
	 *
	 * @param string $path Absolute file path.
	 * @return string|false 'gzip', 'zlib', 'plain', or false when unreadable.
	 */
	private function detect_recording_encoding( $path ) {
		// Magic-byte probe; WP_Filesystem cannot read a fixed byte prefix.
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return false;
		}
		$magic = fread( $fh, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- 2-byte magic probe; WP_Filesystem can only read whole files.
		// Closing the probe handle.
		@fclose( $fh ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! is_string( $magic ) || strlen( $magic ) < 2 ) {
			return false;
		}
		if ( "\x1f\x8b" === $magic ) {
			return 'gzip';
		}
		if ( "\x78" === $magic[0] ) {
			return 'zlib';
		}
		if ( '{' === $magic[0] ) {
			return 'plain';
		}
		return false;
	}

	/**
	 * Stream a base recording file, optionally rewriting it, at constant memory.
	 *
	 * Walks the (possibly compressed) JSON envelope byte by byte in 64 KB
	 * inflate chunks, counting the top-level entries of the `events` array. When
	 * `$write` is supplied every byte is passed through to it, and `$inject` is
	 * invoked exactly once — immediately before the events array closes — which
	 * is how compact_recording() splices the sidecar batches in without ever
	 * materialising the event set.
	 *
	 * @param string        $full_path   Absolute path to the recording file.
	 * @param string        $encoding    'gzip', 'zlib' or 'plain' (from detect_recording_encoding()).
	 * @param callable|null $write       Receives raw uncompressed output chunks, or null to only count.
	 * @param callable|null $inject      Called once before the events array closes, or null.
	 * @param bool          $emitted_any Set to true when the events array already holds content.
	 * @return int Number of top-level events in the base file, or -1 on failure.
	 */
	private function stream_recording_events( $full_path, $encoding, $write, $inject, &$emitted_any ) {
		// Chunked streaming read; WP_Filesystem has no streaming reader and loading the file whole is the OOM this avoids.
		$fh = @fopen( $full_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return -1;
		}

		$inflate = null;
		if ( 'plain' !== $encoding ) {
			$inflate = inflate_init( 'gzip' === $encoding ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE );
			if ( ! $inflate ) {
				// Closing the streaming read handle.
				@fclose( $fh ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return -1;
			}
		}

		// 1) Header: everything up to and including the `[` of the events array.
		// The key is located with a strict `"events"\s*:\s*[` match, never a bare
		// `"events"` substring search: a recording envelope may legitimately carry
		// the word as a VALUE (`{"tag":"events","list":[1],"events":[…]}`), and a
		// loose match there would splice the sidecar into the wrong array.
		$header       = '';
		$header_found = false;
		$bracket_pos  = false;
		// `events` is not guaranteed to be the first key in the envelope, so allow
		// a generous header before giving up — it is a one-off bounded buffer, not
		// proportional to the event set.
		$header_max = (int) apply_filters( 'opti_behavior_recording_compaction_header_max_bytes', MB_IN_BYTES );

		while ( ! feof( $fh ) && ! $header_found ) {
			$raw = fread( $fh, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded streaming read of a large recording file; WP_Filesystem can only load whole files into memory.
			if ( false === $raw || '' === $raw ) {
				break;
			}
			if ( $inflate ) {
				$raw = inflate_add( $inflate, $raw, ZLIB_SYNC_FLUSH );
				if ( false === $raw ) {
					// Closing the streaming read handle.
					@fclose( $fh ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return -1;
				}
			}
			$header .= $raw;

			if ( preg_match( '/"events"\s*:\s*\[/', $header, $m, PREG_OFFSET_CAPTURE ) ) {
				$bracket_pos  = $m[0][1] + strlen( $m[0][0] ) - 1;
				$header_found = true;
			}
			if ( ! $header_found && strlen( $header ) > $header_max ) {
				break;
			}
		}

		if ( ! $header_found ) {
			// Closing the streaming read handle.
			@fclose( $fh ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return -1;
		}

		$depth       = 0;
		$in_string   = false;
		$escaped     = false;
		$closed      = false;
		$event_count = 0;

		// Balance of the envelope up to (not including) the events `[` — normally 1,
		// the single enclosing object. The bytes AFTER the events array must bring it
		// back to 0, which the tail check at the end of this method asserts.
		//
		// WHY the tail is checked at all: compaction replaces the original file, so
		// its pre-swap verification pass has to be trustworthy. Verification re-runs
		// THIS scanner, so a systematic scanner error would be reproduced identically
		// in both passes, the event counts would agree, and corrupt output would be
		// swapped in as "verified". The tail balance is an independent structural
		// assertion: spliced-in-the-wrong-place output leaves the envelope unbalanced
		// even when the count happens to match.
		$tail_in_string = false;
		$tail_escaped   = false;
		$tail_depth     = $this->scan_json_depth( (string) substr( $header, 0, $bracket_pos ), $tail_in_string, $tail_escaped );
		$tail_scan      = function ( $data ) use ( &$tail_depth, &$tail_in_string, &$tail_escaped ) {
			$tail_depth = $this->scan_json_depth( $data, $tail_in_string, $tail_escaped, $tail_depth );
		};

		// 2) Events array: pass through, count top-level entries, and splice the
		// sidecar in just before the closing `]`.
		//
		// `$escaped` is deliberately carried ACROSS chunks: a 64 KB boundary can
		// fall between a backslash and the character it escapes, and consuming the
		// escape with a local `$i++` would then treat that character as live JSON.
		// A `\"` split that way would flip the in-string state, the scanner would
		// mistake a `]` inside a string for the end of the events array, and the
		// sidecar would be spliced into the middle of a string value.
		$scan = function ( $data ) use ( &$depth, &$in_string, &$escaped, &$closed, &$event_count, &$emitted_any, $write, $inject, $tail_scan ) {
			$len = strlen( $data );
			for ( $i = 0; $i < $len; $i++ ) {
				$c = $data[ $i ];
				if ( $escaped ) {
					$escaped = false;
					continue;
				}
				if ( $in_string ) {
					if ( '\\' === $c ) {
						$escaped = true;
					} elseif ( '"' === $c ) {
						$in_string = false;
					}
					continue;
				}
				if ( '"' === $c ) {
					$in_string = true;
					continue;
				}
				if ( '[' === $c || '{' === $c ) {
					if ( 0 === $depth ) {
						++$event_count;
					}
					++$depth;
					continue;
				}
				if ( ']' === $c || '}' === $c ) {
					if ( 0 === $depth && ']' === $c ) {
						$head = substr( $data, 0, $i );
						if ( $write ) {
							$write( $head );
						}
						if ( '' !== trim( $head ) ) {
							$emitted_any = true;
						}
						if ( $inject ) {
							$inject();
						}
						$trailing = (string) substr( $data, $i + 1 );
						if ( $write ) {
							$write( ']' );
							$write( $trailing );
						}
						$closed = true;
						$tail_scan( $trailing );
						return;
					}
					--$depth;
					continue;
				}
			}
			if ( $write ) {
				$write( $data );
			}
			if ( '' !== trim( $data ) ) {
				$emitted_any = true;
			}
		};

		if ( $write ) {
			$write( substr( $header, 0, $bracket_pos + 1 ) );
		}
		$rest = (string) substr( $header, $bracket_pos + 1 );
		unset( $header );
		$scan( $rest );
		unset( $rest );

		// 3) Remainder of the envelope (the keys after `events`) is copied verbatim.
		while ( ! feof( $fh ) ) {
			$raw = fread( $fh, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded streaming read of a large recording file; WP_Filesystem can only load whole files into memory.
			if ( false === $raw || '' === $raw ) {
				break;
			}
			if ( $inflate ) {
				$raw = inflate_add( $inflate, $raw, ZLIB_SYNC_FLUSH );
				if ( false === $raw ) {
					// Closing the streaming read handle.
					@fclose( $fh ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return -1;
				}
			}
			if ( $closed ) {
				if ( $write ) {
					$write( $raw );
				}
				$tail_scan( $raw );
			} else {
				$scan( $raw );
			}
			unset( $raw );
		}

		// Closing the streaming read handle.
		@fclose( $fh ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// An events array that never closed means a truncated/corrupt base file.
		if ( ! $closed ) {
			return -1;
		}

		// The envelope must close exactly once after the events array. Anything else
		// means the `]` we treated as the end of `events` was not it.
		if ( 0 !== $tail_depth || $tail_in_string ) {
			return -1;
		}

		return $event_count;
	}

	/**
	 * Resumable, string-aware bracket-depth scan over a slice of JSON.
	 *
	 * The string and escape states are carried by reference so a caller can feed
	 * the document in arbitrary chunks — a 64 KB read boundary may land anywhere,
	 * including between a backslash and the character it escapes.
	 *
	 * @param string $data      Slice of JSON text.
	 * @param bool   $in_string Inside-a-string state, in and out.
	 * @param bool   $escaped   Pending-escape state, in and out.
	 * @param int    $depth     Depth to resume from.
	 * @return int Depth after the slice.
	 */
	private function scan_json_depth( $data, &$in_string, &$escaped, $depth = 0 ) {
		$len = strlen( $data );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $data[ $i ];
			if ( $escaped ) {
				$escaped = false;
				continue;
			}
			if ( $in_string ) {
				if ( '\\' === $c ) {
					$escaped = true;
				} elseif ( '"' === $c ) {
					$in_string = false;
				}
				continue;
			}
			if ( '"' === $c ) {
				$in_string = true;
			} elseif ( '[' === $c || '{' === $c ) {
				++$depth;
			} elseif ( ']' === $c || '}' === $c ) {
				--$depth;
			}
		}
		return $depth;
	}

	/**
	 * Allocation-free structural check of one `.oblog` NDJSON line.
	 *
	 * Verifies the line is a complete JSON array whose brackets balance exactly
	 * at its last character, and counts its top-level elements. Used instead of
	 * json_decode() so compaction never pays the 10-20x array cost of a batch,
	 * and so a torn line left by a crash mid-append is rejected rather than
	 * silently corrupting the rewritten file.
	 *
	 * @param string $line Trimmed sidecar line.
	 * @return int Top-level element count, or -1 when the line is malformed.
	 */
	private function scan_json_array_line_events( $line ) {
		$len = strlen( $line );
		if ( $len < 2 || '[' !== $line[0] || ']' !== $line[ $len - 1 ] ) {
			return -1;
		}

		$depth     = 0;
		$in_string = false;
		$count     = 0;

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $line[ $i ];
			if ( $in_string ) {
				if ( '\\' === $c ) {
					$i++;
				} elseif ( '"' === $c ) {
					$in_string = false;
				}
				continue;
			}
			if ( '"' === $c ) {
				$in_string = true;
				continue;
			}
			if ( '[' === $c || '{' === $c ) {
				++$depth;
				if ( 2 === $depth ) {
					++$count;
				}
				continue;
			}
			if ( ']' === $c || '}' === $c ) {
				--$depth;
				if ( $depth < 0 ) {
					return -1;
				}
				if ( 0 === $depth && $i !== $len - 1 ) {
					return -1;
				}
			}
		}

		if ( 0 !== $depth || $in_string ) {
			return -1;
		}

		return $count;
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

		// An unwritable directory must not fall through: `false . $filename`
		// stringifies to the bare filename and would write into the process
		// working directory.
		if ( false === $dir ) {
			$this->log( 'Cannot save events: directory creation failed', 'error' );
			return array( 'error' => 'Directory creation failed - check file permissions on wp-content/uploads/opti-behavior-data/' );
		}

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

		$log_path = $full_path . self::APPEND_SUFFIX;

		// OOM guard (live fatal at json_decode below, optiuser.com 2026-09-14):
		// refuse a read whose decoded arrays cannot fit in memory_limit. A false
		// return is handled by every caller; a fatal takes the whole request down.
		if ( ! $this->recording_decode_fits_in_memory( $full_path, $log_path ) ) {
			$this->log( 'Recording too large to decode in memory - read skipped: ' . $file_path, 'warning' );
			return false;
		}

		// Ticket A: take a SHARED lock on the sidecar for the whole base+sidecar
		// read. compact_recording() folds the sidecar into the base under an
		// EXCLUSIVE lock, so without this a reader could slip between the base
		// swap and the sidecar truncation and see every folded event twice.
		// Readers never block each other; only compaction waits (and it uses
		// LOCK_NB, so it simply retries on the next tick).
		$read_lock = $this->lock_append_log_shared( $log_path );

		$content = file_get_contents( $full_path );

		if ( false === $content ) {
			$this->release_append_log_lock( $read_lock );
			return false;
		}

		// Decompress if needed
		if ( substr( $file_path, -3 ) === '.gz' ) {
			$content = gzuncompress( $content );
			if ( false === $content ) {
				$this->release_append_log_lock( $read_lock );
				return false;
			}
		}

		$data = json_decode( $content, true );
		// OOM fix (F3): the raw JSON string is dead weight from here on — freeing
		// it before the merge removes a full copy of the recording from the peak.
		unset( $content );
		if ( ! is_array( $data ) ) {
			$this->release_append_log_lock( $read_lock );
			return $data;
		}

		// Prefer the running duration from the counter sidecar when higher.
		$idx = $this->read_recording_index( $full_path . self::INDEX_SUFFIX );

		// Ticket A: a compacted base holds base events followed by the folded
		// sidecar batches in SAVE order. That matches what the streaming player
		// emits, but the array reader has always returned time-ordered events, so
		// force the sort for compacted recordings even when the (now empty)
		// sidecar contributes nothing.
		$needs_sort = ( null !== $idx && (int) $idx['compacted'] > 0 );

		// Merge the append-only sidecar (perf Fix A). Legacy recordings have no
		// `.oblog` sidecar and read back byte-for-byte unchanged. New recordings
		// carry their incremental events here; sort once, at read time.
		if ( is_file( $log_path ) ) {
			// OOM fix (F3): read-side size guard. The write caps (50 MB / 50 000
			// events) sanction files that cannot be decoded at all — 50 MB of
			// rrweb JSON is several hundred MB of PHP arrays. Reuse the playback
			// threshold so there is one number to reason about; over it, return
			// the base data flagged `truncated` instead of attempting a decode
			// that cannot finish. Callers must never write truncated data back.
			$merge_max = (int) apply_filters( 'opti_behavior_recording_merge_max_bytes', 24 * MB_IN_BYTES );
			$combined  = (int) @filesize( $full_path ) + (int) @filesize( $log_path );
			if ( $merge_max > 0 && $combined > $merge_max ) {
				static $read_cap_logged = array();
				if ( ! isset( $read_cap_logged[ $file_path ] ) ) {
					$read_cap_logged[ $file_path ] = true;
					$this->log( 'Recording too large for an in-memory merge (' . $combined . ' > ' . $merge_max . ' bytes) - returning base events only: ' . $file_path, 'warning' );
				}
				$data['truncated'] = true;
			} else {
				$data       = $this->merge_append_log( $data, $log_path, $needs_sort );
				$needs_sort = false;
			}
		}

		$this->release_append_log_lock( $read_lock );

		if ( $needs_sort && isset( $data['events'] ) && is_array( $data['events'] ) ) {
			$this->sort_events_by_timestamp( $data['events'] );
		}

		if ( null !== $idx && $idx['duration'] > ( isset( $data['duration'] ) ? (int) $data['duration'] : 0 ) ) {
			$data['duration'] = $idx['duration'];
		}

		// Bug #2 / D2: expose the REPLAY duration (event-timestamp span) next to
		// the wall-clock `duration`, so callers can pick the right contract
		// instead of conflating the two. Falls back to a scan of the merged
		// events when the sidecar predates the tracking.
		$replay_duration = ( null !== $idx ) ? self::replay_duration_from_bounds( $idx['first_ts'], $idx['last_ts'] ) : 0;
		if ( $replay_duration <= 0 && isset( $data['events'] ) && is_array( $data['events'] ) ) {
			$bounds = $this->scan_events_timestamp_bounds( $data['events'] );
			if ( null !== $bounds ) {
				$replay_duration = self::replay_duration_from_bounds( $bounds['min'], $bounds['max'] );
			}
		}
		$data['replay_duration'] = $replay_duration;

		return $data;
	}

	/**
	 * Whether a recording (base file + optional `.oblog` sidecar) can be
	 * json_decoded into PHP arrays within the remaining memory_limit.
	 *
	 * Compressed bases expand ~12x to JSON; decoded rrweb arrays cost ~16x the
	 * JSON bytes. Only the sidecar bytes the merge will actually read count
	 * (over the merge threshold the sidecar is skipped, see read_recording()).
	 *
	 * @param string $full_path Absolute base file path.
	 * @param string $log_path  Absolute `.oblog` sidecar path.
	 * @return bool True when the decode is safe.
	 */
	private function recording_decode_fits_in_memory( $full_path, $log_path ) {
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		if ( $limit <= 0 ) {
			return true; // Unlimited.
		}

		$base_size    = (int) @filesize( $full_path );
		$sidecar_size = is_file( $log_path ) ? (int) @filesize( $log_path ) : 0;
		$merge_max    = (int) apply_filters( 'opti_behavior_recording_merge_max_bytes', 24 * MB_IN_BYTES );
		if ( $merge_max > 0 && ( $base_size + $sidecar_size ) > $merge_max ) {
			$sidecar_size = 0; // Sidecar merge is skipped for oversized recordings.
		}

		$ratio          = (int) apply_filters( 'opti_behavior_recording_compression_ratio_estimate', 12 );
		$is_compressed  = ( '.gz' === substr( $full_path, -3 ) );
		$estimated_json = ( $is_compressed ? $base_size * max( 1, $ratio ) : $base_size ) + $sidecar_size;
		$factor         = (int) apply_filters( 'opti_behavior_recording_decode_memory_factor', 16 );
		$headroom       = $limit - memory_get_usage( true );

		return ( $estimated_json * max( 1, $factor ) ) < $headroom;
	}

	/**
	 * Locate a session's base recording file on disk.
	 *
	 * Recovery helper for rows whose `file_path` column is empty or stale (a
	 * failed first save, a manual move, a partial restore). Recording files are
	 * named `{session_id}_{timestamp}.json[.gz]` by save_recording(), so the
	 * session ID is the filename prefix.
	 *
	 * Ticket B: this method used to live only in the Pro copy of the class,
	 * which never loaded, so all three Pro call sites permanently took their
	 * `method_exists()` fallback. It matched on a `'session_' . $session_id`
	 * prefix that no recording has ever carried; the prefix here is the one the
	 * writer actually produces.
	 *
	 * @param string $session_id Session ID.
	 * @return string|false Path relative to the storage base dir, or false.
	 */
	public function find_session_file( $session_id ) {
		if ( '' === (string) $session_id ) {
			return false;
		}

		$recordings_dir = $this->base_dir . 'recordings/';
		if ( ! is_dir( $recordings_dir ) ) {
			return false;
		}

		// Anchored on the trailing timestamp and the extension rather than a
		// bare `{session_id}_` prefix. A loose prefix also matches a *different*,
		// longer session ID that happens to start with this one — `sess_x_1`
		// would resolve to `sess_x_1_2`'s recording — and it needs a separate
		// skip-list to reject the .oblog/.obidx/.obtmp sidecars, which this
		// pattern rejects outright.
		$pattern = '/^' . preg_quote( $session_id, '/' ) . '_\d+\.json(?:\.gz)?$/';

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $recordings_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$filename = $file->getFilename();
				if ( ! preg_match( $pattern, $filename ) ) {
					continue;
				}

				$relative_path = str_replace( $this->base_dir, '', $file->getPathname() );
				return str_replace( '\\', '/', $relative_path );
			}
		} catch ( Exception $e ) {
			$this->log( 'Error finding session file: ' . $e->getMessage(), 'error' );
			return false;
		}

		return false;
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
	 * loads — guarantees the `.oblog`/`.obidx`/`.obtmp` sidecars are removed
	 * alongside the base file (they are the base filename plus a suffix, so they
	 * share its `{session_id}_` prefix). Fix A.
	 *
	 * Prefix contract: save_recording() writes `{session_id}_{timestamp}.json[.gz]`
	 * (see line ~285). This scan used to match `'session_' . $session_id . '_'`,
	 * a prefix no recording has ever carried, so deleting a file-based recording
	 * silently removed nothing and left the files orphaned on disk.
	 *
	 * @param string $session_id Session ID to delete files for.
	 * @return bool True if any files were deleted, false otherwise.
	 */
	public function delete_session_files( $session_id ) {
		$recordings_dir = $this->base_dir . 'recordings/';

		if ( ! file_exists( $recordings_dir ) ) {
			return false;
		}

		if ( '' === (string) $session_id ) {
			return false;
		}

		$files_deleted = false;
		$touched_dirs  = array();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $recordings_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			$this->log( 'Error creating directory iterator for deletion: ' . $e->getMessage(), 'warning' );
			return false;
		}

		// Matches the base recording ({session_id}_{ts}.json[.gz]) AND its
		// append-only sidecars (…json[.gz].oblog / .obidx / .obtmp), which are
		// the base filename plus a suffix. Anchored on the timestamp and the
		// extension rather than a bare prefix: deletion is destructive, and a
		// loose `{session_id}_` prefix would also match a longer session ID that
		// happens to start with this one.
		$pattern = '/^' . preg_quote( $session_id, '/' ) . '_\d+\.json(?:\.gz)?(?:'
			. preg_quote( self::APPEND_SUFFIX, '/' ) . '|'
			. preg_quote( self::INDEX_SUFFIX, '/' ) . '|'
			. preg_quote( self::COMPACT_SUFFIX, '/' ) . ')?$/';

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getFilename() === 'index.php' || $file->getFilename() === '.htaccess' ) {
				continue;
			}

			$filename = $file->getFilename();
			if ( preg_match( $pattern, $filename ) ) {
				$path = $file->getPathname();
				wp_delete_file( $path );
				if ( ! file_exists( $path ) ) {
					$files_deleted                 = true;
					$touched_dirs[ $file->getPath() ] = true;
				}
			}
		}

		if ( $files_deleted ) {
			$this->prune_empty_recording_dirs( array_keys( $touched_dirs ) );
		}

		return $files_deleted;
	}

	/**
	 * Remove recording hour directories that a delete just emptied.
	 *
	 * The date shards are `recordings/{YYYY}/{MM}/{DD}/{HH}/` and every level
	 * carries an `index.php` (and sometimes `.htaccess`) hardening stub, so a
	 * directory whose recordings are all gone is never literally empty — it used
	 * to be left behind forever, one stub per hour of traffic (QA-B-REC-022).
	 * A shard holding nothing but those stubs is removed stub and all, and the
	 * walk continues up day → month → year. The `recordings/` root itself and
	 * its stub are never touched.
	 *
	 * @since 1.9.0.7
	 * @param string[] $dirs Absolute directories a deletion emptied.
	 * @return int Number of directories removed.
	 */
	private function prune_empty_recording_dirs( $dirs ) {
		$root    = rtrim( str_replace( '\\', '/', $this->base_dir . 'recordings/' ), '/' );
		$removed = 0;
		$stubs   = array( 'index.php', '.htaccess' );

		foreach ( (array) $dirs as $dir ) {
			$current = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );

			// Only shards strictly below recordings/ may be pruned.
			while ( '' !== $current && $current !== $root && 0 === strpos( $current, $root . '/' ) ) {
				if ( ! is_dir( $current ) ) {
					$current = dirname( $current );
					continue;
				}

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with a concurrent sweep is expected.
				$entries = @scandir( $current );
				if ( ! is_array( $entries ) ) {
					break;
				}

				$left = array_diff( $entries, array( '.', '..' ) );
				if ( array_diff( $left, $stubs ) ) {
					break; // Real content is still there.
				}

				foreach ( $left as $stub ) {
					wp_delete_file( $current . '/' . $stub );
				}

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty-dir prune; failure is non-fatal.
				if ( ! @rmdir( $current ) ) {
					break;
				}

				++$removed;
				$current = dirname( $current );
			}
		}

		return $removed;
	}

	/**
	 * Enumerate the date-sharded hour directories under `recordings/` that sort
	 * strictly after a cursor (Ticket C — orphaned-file sweep).
	 *
	 * The layout is `recordings/{YYYY}/{MM}/{DD}/{HH}/` (see
	 * get_data_directory()), which gives a natural, stable, lexicographically
	 * sorted cursor: the relative hour path `YYYY/MM/DD/HH`. The walk touches
	 * four SHALLOW levels only (years, months, days, hours — each a handful of
	 * entries), never a recursive full-tree listing, so memory stays flat no
	 * matter how large the backlog is.
	 *
	 * @param string $after Cursor — last fully processed hour path ('' = start).
	 * @param int    $limit Maximum number of hour paths to return.
	 * @return array Sorted relative hour paths ('YYYY/MM/DD/HH') after $after.
	 */
	private function list_recording_hour_dirs( $after = '', $limit = 50 ) {
		$root = $this->base_dir . 'recordings/';
		if ( ! is_dir( $root ) || $limit < 1 ) {
			return array();
		}

		$scan_level = function ( $dir, $len ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$entries = @scandir( $dir );
			if ( ! is_array( $entries ) ) {
				return array();
			}
			$out = array();
			foreach ( $entries as $name ) {
				if ( strlen( $name ) === $len && ctype_digit( $name ) && is_dir( $dir . $name ) ) {
					$out[] = $name;
				}
			}
			sort( $out, SORT_STRING );
			return $out;
		};

		$found = array();
		foreach ( $scan_level( $root, 4 ) as $year ) {
			if ( '' !== $after && strcmp( $year, substr( $after, 0, 4 ) ) < 0 ) {
				continue; // Whole year already behind the cursor.
			}
			foreach ( $scan_level( $root . $year . '/', 2 ) as $month ) {
				$ym = $year . '/' . $month;
				if ( '' !== $after && strcmp( $ym, substr( $after, 0, 7 ) ) < 0 ) {
					continue;
				}
				foreach ( $scan_level( $root . $ym . '/', 2 ) as $day ) {
					$ymd = $ym . '/' . $day;
					if ( '' !== $after && strcmp( $ymd, substr( $after, 0, 10 ) ) < 0 ) {
						continue;
					}
					foreach ( $scan_level( $root . $ymd . '/', 2 ) as $hour ) {
						$rel = $ymd . '/' . $hour;
						if ( '' !== $after && strcmp( $rel, $after ) <= 0 ) {
							continue;
						}
						$found[] = $rel;
						if ( count( $found ) >= $limit ) {
							return $found;
						}
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Delete every recording file (base + sidecars) in hour directories that
	 * ended before `$cutoff_ts`, oldest first, then prune the emptied
	 * Y/m/d/H tree.
	 *
	 * Free-side fallback for the Pro archiver: recordings are only ever
	 * created by Pro, but they must not outlive the files-retention window
	 * just because Pro is deactivated, license-gated or memory-guarded. The
	 * partition is `gmdate( 'Y/m/d/H', current_time( 'timestamp' ) )`
	 * (see get_data_directory()), so the caller passes a cutoff on the same
	 * `current_time( 'timestamp' )` basis and the comparison is a plain
	 * string compare on the relative hour path — no file is opened or
	 * stat'ed to decide expiry.
	 *
	 * Bounded: at most `max_dirs` hour directories and `time_budget`
	 * seconds per call. `capped` = true means more expired directories
	 * remain; the caller re-runs (next day or a continuation event).
	 *
	 * @since 1.9.x (Cleanup audit 2026-09-13)
	 * @param int   $cutoff_ts Expiry cutoff, seconds, current_time('timestamp') basis.
	 * @param array $args      Optional: max_dirs (int, default 200), time_budget (float seconds, default 20).
	 * @return array{deleted:int,bytes:int,dirs:int,failed:int,capped:bool}
	 */
	public function delete_recording_files_older_than( $cutoff_ts, $args = array() ) {
		$result = array(
			'deleted' => 0,
			'bytes'   => 0,
			'dirs'    => 0,
			'failed'  => 0,
			'capped'  => false,
		);
		$cutoff_ts = (int) $cutoff_ts;
		if ( $cutoff_ts <= 0 ) {
			return $result;
		}
		$root = $this->base_dir . 'recordings/';
		if ( ! is_dir( $root ) ) {
			return $result;
		}
		$max_dirs    = isset( $args['max_dirs'] ) ? max( 1, (int) $args['max_dirs'] ) : 200;
		$time_budget = isset( $args['time_budget'] ) ? max( 1.0, (float) $args['time_budget'] ) : 20.0;
		$deadline    = microtime( true ) + $time_budget;
		$cutoff_rel  = gmdate( 'Y/m/d/H', $cutoff_ts );

		// Oldest hour dirs first. Deleting a dir removes it from the next
		// listing, so a fresh unbounded-cursor listing per call is correct.
		$hour_dirs = $this->list_recording_hour_dirs( '', $max_dirs );
		$processed = 0;
		foreach ( $hour_dirs as $rel ) {
			if ( strcmp( $rel, $cutoff_rel ) >= 0 ) {
				break; // Sorted ascending: everything from here on is inside the window.
			}
			if ( microtime( true ) >= $deadline ) {
				$result['capped'] = true;
				break;
			}
			$dir     = $root . $rel . '/';
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with concurrent deletion is expected.
			if ( is_array( $entries ) ) {
				foreach ( $entries as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					$path = $dir . $name;
					if ( ! is_file( $path ) ) {
						continue;
					}
					$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with concurrent deletion is expected.
					wp_delete_file( $path );
					if ( file_exists( $path ) ) {
						++$result['failed'];
						continue;
					}
					++$result['deleted'];
					$result['bytes'] += $size;
				}
			}
			++$processed;
			++$result['dirs'];
			// Prune hour → day → month → year while empty.
			$parts = explode( '/', $rel );
			while ( count( $parts ) > 0 ) {
				$candidate = $root . implode( '/', $parts );
				$left      = @scandir( $candidate ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with concurrent deletion is expected.
				if ( ! is_array( $left ) || count( $left ) > 2 ) {
					break;
				}
				if ( ! @rmdir( $candidate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty-dir prune; failure is non-fatal.
					break;
				}
				array_pop( $parts );
			}
		}
		if ( ! $result['capped'] && $processed >= $max_dirs ) {
			// Listing was full and every entry was expired: more may remain.
			$result['capped'] = true;
		}
		if ( $result['deleted'] > 0 || $result['failed'] > 0 ) {
			$this->log( sprintf( 'Recording files fallback sweep: %d file(s) deleted (%d bytes) in %d hour dir(s), %d failed, capped=%s', $result['deleted'], $result['bytes'], $result['dirs'], $result['failed'], $result['capped'] ? 'yes' : 'no' ), 'info' );
		}
		return $result;
	}

	/**
	 * Which of these session IDs have at least one row (ANY storage_type, ANY
	 * file_path — including NULL/'' stubs) in the recordings table?
	 *
	 * The sweep's no-row rule is deliberately the strictly safer SUPERSET test:
	 * a stub row (file_path NULL) is a legitimate auto-recovery target, so a
	 * file for a session with ANY surviving row is untouchable. Chunked IN
	 * queries — nothing ever preloads the whole table.
	 *
	 * @param array $session_ids Session IDs to test.
	 * @return array Set (id => true) of session IDs that HAVE a row.
	 */
	private function recording_sessions_with_rows( $session_ids ) {
		global $wpdb;

		$found = array();
		foreach ( array_chunk( array_values( array_unique( $session_ids ) ), 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows         = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT session_id FROM {$wpdb->prefix}optibehavior_recordings WHERE session_id IN ($placeholders)",
				$chunk
			) );
			foreach ( (array) $rows as $sid ) {
				$found[ $sid ] = true;
			}
		}

		return $found;
	}

	/**
	 * Which of these relative file paths are referenced by a recordings row?
	 *
	 * Belt-and-braces companion to the session-superset rule: even if a row's
	 * session_id were corrupted, a file whose exact path is still referenced in
	 * the file_path column is never a deletion candidate.
	 *
	 * @param array $rel_paths Relative forward-slash paths (DB storage form).
	 * @return array Set (path => true) of paths that ARE referenced.
	 */
	private function recording_paths_with_rows( $rel_paths ) {
		global $wpdb;

		$found = array();
		foreach ( array_chunk( array_values( array_unique( $rel_paths ) ), 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows         = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT file_path FROM {$wpdb->prefix}optibehavior_recordings WHERE file_path IN ($placeholders)",
				$chunk
			) );
			foreach ( (array) $rows as $p ) {
				$found[ $p ] = true;
			}
		}

		return $found;
	}

	/**
	 * Find orphaned recording files in one bounded batch (Ticket C).
	 *
	 * Pure enumeration — deletes NOTHING. This is both the dry-run engine and
	 * the candidate source for sweep_orphaned_recording_files_step().
	 *
	 * Orphan definition (SAFETY FIRST, all conditions must hold):
	 *   Base file `{session_id}_{ts}.json[.gz]`:
	 *     1. anchored filename contract match, inside `recordings/` only;
	 *     2. NO row exists for the parsed session_id at all (superset rule —
	 *        stub rows with file_path NULL protect the file), AND no row
	 *        references the exact relative path;
	 *     3. older than the grace period on BOTH the filename timestamp and the
	 *        file mtime — and every sidecar of the base must be aged too (a
	 *        recent sidecar means something may still be writing).
	 *   Sidecar `…json[.gz].oblog|.obidx|.obtmp`:
	 *     only when its exact base file is ABSENT from the same directory and
	 *     the base's session clears the same no-row + grace tests. A sidecar
	 *     whose base exists is never touched on its own (it dies with its base,
	 *     here or via delete_session_files()); a stale `.obtmp` whose base
	 *     exists belongs to compaction and is left alone.
	 *
	 * Bounded memory: one scandir() per hour dir (filenames only — no file is
	 *   ever opened or decoded), chunked IN queries per dir, cursor'd batches.
	 * Bounded time: stops after max_dirs hour dirs or time_budget seconds,
	 *   whichever first; a dir is processed atomically so the cursor never
	 *   points mid-directory.
	 *
	 * @param string $cursor Last fully processed hour path ('' = start).
	 * @param array  $args   Optional overrides: max_dirs, time_budget, min_age.
	 * @return array {
	 *     groups          array  Deletable file groups; each group is a list of
	 *                            entries ['rel','full','size','kind','session_id'],
	 *                            sidecars ordered BEFORE their base.
	 *     next_cursor     string|null Cursor for the next batch, null = tree done.
	 *     dirs_scanned    int
	 *     files_scanned   int
	 *     skipped_recent  int    Files inside the grace period.
	 *     skipped_has_row int    Files protected by a surviving DB row.
	 *     nonconforming   int    Files not matching any contract (never touched).
	 * }
	 */
	public function find_orphaned_recording_files( $cursor = '', $args = array() ) {
		global $wpdb;

		$result = array(
			'groups'          => array(),
			'next_cursor'     => null,
			'dirs_scanned'    => 0,
			'files_scanned'   => 0,
			'skipped_recent'  => 0,
			'skipped_has_row' => 0,
			'nonconforming'   => 0,
		);

		$root = $this->base_dir . 'recordings/';
		if ( ! is_dir( $root ) ) {
			return $result;
		}

		// If the recordings table is missing we cannot prove ANY file is
		// row-less — refuse to nominate candidates rather than over-delete.
		$table = $wpdb->prefix . 'optibehavior_recordings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			$this->log( 'Orphan sweep: recordings table missing — no candidates nominated.', 'warning' );
			return $result;
		}

		$max_dirs    = isset( $args['max_dirs'] ) ? max( 1, (int) $args['max_dirs'] )
			: max( 1, (int) apply_filters( 'opti_behavior_recording_orphan_sweep_max_dirs', 50 ) );
		$time_budget = isset( $args['time_budget'] ) ? (float) $args['time_budget']
			: (float) apply_filters( 'opti_behavior_recording_orphan_sweep_time_budget', 10 );
		$min_age     = isset( $args['min_age'] ) ? max( 0, (int) $args['min_age'] )
			: max( 0, (int) apply_filters( 'opti_behavior_recording_orphan_min_age', 2 * DAY_IN_SECONDS ) );

		$started = microtime( true );
		$now     = time();

		// +1 so we know whether anything remains beyond this batch.
		$dirs = $this->list_recording_hour_dirs( (string) $cursor, $max_dirs + 1 );
		if ( empty( $dirs ) ) {
			return $result; // Tree exhausted — next_cursor stays null.
		}
		$more_beyond = count( $dirs ) > $max_dirs;
		if ( $more_beyond ) {
			array_pop( $dirs );
		}

		$last_done = null;
		foreach ( $dirs as $i => $rel_dir ) {
			// Time box BETWEEN dirs, never inside one (atomic per-dir).
			if ( $i > 0 && ( microtime( true ) - $started ) > $time_budget ) {
				$result['next_cursor'] = $last_done;
				return $result;
			}

			$abs_dir = $root . $rel_dir . '/';
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$entries = @scandir( $abs_dir );
			if ( ! is_array( $entries ) ) {
				++$result['dirs_scanned'];
				$last_done = $rel_dir;
				continue;
			}

			// One flat pass over filenames — names, sizes, mtimes only. No file
			// is ever opened or decoded (the memory guards from bugs 1–2 stay
			// untouched by design).
			$groups = array(); // base filename => { base:entry|null, sidecars:[entry], aged:bool }
			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name || 'index.php' === $name || '.htaccess' === $name ) {
					continue;
				}
				$full = $abs_dir . $name;
				if ( ! is_file( $full ) ) {
					continue;
				}
				++$result['files_scanned'];

				if ( preg_match( '/^((.+)_(\d+)\.json(?:\.gz)?)(' . preg_quote( self::APPEND_SUFFIX, '/' )
					. '|' . preg_quote( self::INDEX_SUFFIX, '/' )
					. '|' . preg_quote( self::COMPACT_SUFFIX, '/' ) . ')$/', $name, $m ) ) {
					$base_name = $m[1];
					$entry     = array(
						// DB storage form: relative to base_dir, forward slashes,
						// INCLUDING the `recordings/` segment (see save_recording()).
						'rel'        => 'recordings/' . $rel_dir . '/' . $name,
						'full'       => $full,
						'size'       => (int) filesize( $full ),
						'kind'       => 'sidecar',
						'session_id' => $m[2],
						'aged'       => ( $now - (int) $m[3] ) >= $min_age && ( $now - (int) filemtime( $full ) ) >= $min_age,
					);
					if ( ! isset( $groups[ $base_name ] ) ) {
						$groups[ $base_name ] = array( 'base' => null, 'sidecars' => array() );
					}
					$groups[ $base_name ]['sidecars'][] = $entry;
				} elseif ( preg_match( '/^(.+)_(\d+)\.json(?:\.gz)?$/', $name, $m ) ) {
					$entry = array(
						'rel'        => 'recordings/' . $rel_dir . '/' . $name,
						'full'       => $full,
						'size'       => (int) filesize( $full ),
						'kind'       => 'base',
						'session_id' => $m[1],
						'aged'       => ( $now - (int) $m[2] ) >= $min_age && ( $now - (int) filemtime( $full ) ) >= $min_age,
					);
					if ( ! isset( $groups[ $name ] ) ) {
						$groups[ $name ] = array( 'base' => null, 'sidecars' => array() );
					}
					$groups[ $name ]['base'] = $entry;
				} else {
					++$result['nonconforming'];
				}
			}

			// Grace filter first (cheap), then one chunked DB pass per dir.
			$candidates = array(); // session_id => [group,...]
			foreach ( $groups as $base_name => $group ) {
				$files = $group['sidecars'];
				if ( null !== $group['base'] ) {
					$files[] = $group['base']; // base LAST — deletion order.
				}
				// Every file in the group must be aged; one recent member means
				// the session may still be alive, so the whole group waits.
				$all_aged = true;
				foreach ( $files as $f ) {
					if ( ! $f['aged'] ) {
						$all_aged = false;
						break;
					}
				}
				if ( ! $all_aged ) {
					$result['skipped_recent'] += count( $files );
					continue;
				}
				$sid                  = $files[0]['session_id'];
				$candidates[ $sid ][] = $files;
			}

			if ( $candidates ) {
				$has_row   = $this->recording_sessions_with_rows( array_keys( $candidates ) );
				$rel_paths = array();
				foreach ( $candidates as $sid => $groups_for_sid ) {
					foreach ( $groups_for_sid as $files ) {
						foreach ( $files as $f ) {
							if ( 'base' === $f['kind'] ) {
								$rel_paths[] = $f['rel'];
							}
						}
					}
				}
				$path_has_row = $rel_paths ? $this->recording_paths_with_rows( $rel_paths ) : array();

				foreach ( $candidates as $sid => $groups_for_sid ) {
					foreach ( $groups_for_sid as $files ) {
						$protected = isset( $has_row[ $sid ] );
						if ( ! $protected ) {
							foreach ( $files as $f ) {
								if ( 'base' === $f['kind'] && isset( $path_has_row[ $f['rel'] ] ) ) {
									$protected = true;
									break;
								}
							}
						}
						if ( $protected ) {
							$result['skipped_has_row'] += count( $files );
							continue;
						}
						$result['groups'][] = $files;
					}
				}
			}

			++$result['dirs_scanned'];
			$last_done = $rel_dir;
		}

		$result['next_cursor'] = $more_beyond ? $last_done : null;

		return $result;
	}

	/**
	 * Run ONE bounded sweep batch: evaluate a cursor'd slice of the recordings
	 * tree and delete (or, in dry-run, only count) the confirmed orphans
	 * (Ticket C — one-off cleanup for the Bug-4 backlog).
	 *
	 * Deletion mechanics:
	 *   - realpath containment check against `recordings/` before every unlink
	 *     (symlinked or otherwise escaped paths are refused);
	 *   - wp_delete_file(), sidecars first and base LAST within a group, so a
	 *     tick dying mid-group leaves a state the next run still recognizes as
	 *     orphaned;
	 *   - idempotent vs. the Pro archiver: a file already gone is a no-op.
	 *
	 * One summary log line per tick — never per-file spam.
	 *
	 * @param string $cursor Last fully processed hour path ('' = start).
	 * @param array  $args   Optional overrides: dry_run, max_dirs, time_budget,
	 *                       min_age (all otherwise filterable — see
	 *                       find_orphaned_recording_files()).
	 * @return array Tick stats: next_cursor (string|null), dry_run, files_deleted,
	 *               bytes_freed, delete_failed, dirs_scanned, files_scanned,
	 *               skipped_recent, skipped_has_row, nonconforming.
	 */
	public function sweep_orphaned_recording_files_step( $cursor = '', $args = array() ) {
		$dry_run = isset( $args['dry_run'] ) ? (bool) $args['dry_run']
			: (bool) apply_filters( 'opti_behavior_recording_orphan_sweep_dry_run', false );

		$found = $this->find_orphaned_recording_files( $cursor, $args );

		$stats = array(
			'next_cursor'     => $found['next_cursor'],
			'dry_run'         => $dry_run,
			'files_deleted'   => 0,
			'bytes_freed'     => 0,
			'delete_failed'   => 0,
			'dirs_scanned'    => $found['dirs_scanned'],
			'files_scanned'   => $found['files_scanned'],
			'skipped_recent'  => $found['skipped_recent'],
			'skipped_has_row' => $found['skipped_has_row'],
			'nonconforming'   => $found['nonconforming'],
		);

		$root_real = realpath( $this->base_dir . 'recordings' );
		if ( false === $root_real ) {
			return $stats;
		}
		$root_prefix = rtrim( $root_real, '/\\' ) . DIRECTORY_SEPARATOR;

		foreach ( $found['groups'] as $files ) {
			foreach ( $files as $f ) {
				// Containment: never delete anything that does not resolve to a
				// real path inside recordings/ (symlinks, traversal, races).
				$real = realpath( $f['full'] );
				if ( false === $real || 0 !== strncmp( $real, $root_prefix, strlen( $root_prefix ) ) ) {
					continue;
				}

				if ( $dry_run ) {
					++$stats['files_deleted']; // "would delete" in dry-run.
					$stats['bytes_freed'] += $f['size'];
					continue;
				}

				wp_delete_file( $f['full'] );
				clearstatcache( true, $f['full'] );
				if ( file_exists( $f['full'] ) ) {
					++$stats['delete_failed'];
				} else {
					++$stats['files_deleted'];
					$stats['bytes_freed'] += $f['size'];
				}
			}
		}

		$this->log(
			'Orphan sweep tick' . ( $dry_run ? ' [DRY-RUN]' : '' ) . ': '
			. $stats['dirs_scanned'] . ' dirs, ' . $stats['files_scanned'] . ' files scanned, '
			. $stats['files_deleted'] . ( $dry_run ? ' would be deleted' : ' deleted' )
			. ' (' . $stats['bytes_freed'] . ' bytes), '
			. $stats['skipped_recent'] . ' in grace, ' . $stats['skipped_has_row'] . ' row-protected, '
			. $stats['nonconforming'] . ' nonconforming; next_cursor='
			. ( null === $stats['next_cursor'] ? 'DONE' : $stats['next_cursor'] ),
			'info'
		);

		return $stats;
	}

	/**
	 * Count recordings rows whose file_path points at a missing file
	 * (Ticket C, direction 2 — REPORT ONLY, never deletes or modifies rows).
	 *
	 * Row removal stays with the existing authorities (Pro's
	 * bulk_cleanup_orphaned_recordings() and the archiver's expiry stubbing);
	 * adding a third destructive path here would be the inverse of the Bug-4
	 * mistake. Keyset-paginated so nothing preloads the table.
	 *
	 * @param int $max_rows Scan cap (matches the bulk-cleanup 25k precedent).
	 * @return int Number of file-storage rows pointing at a missing file.
	 */
	public function count_recordings_missing_files( $max_rows = 25000 ) {
		global $wpdb;

		$missing = 0;
		$scanned = 0;
		$last_id = 0;

		while ( $scanned < $max_rows ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, file_path FROM {$wpdb->prefix}optibehavior_recordings
				 WHERE storage_type = 'file' AND file_path IS NOT NULL AND file_path != '' AND id > %d
				 ORDER BY id ASC LIMIT 500",
				$last_id
			) );
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$last_id = (int) $row->id;
				++$scanned;
				if ( ! file_exists( $this->base_dir . ltrim( $row->file_path, '/\\' ) ) ) {
					++$missing;
				}
			}
		}

		return $missing;
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

			// Parse filename: {session_id}_{timestamp}.json[.gz] — the exact
			// shape save_recording() writes. This used to require a leading
			// `session_`, which no recording has ever carried, so the listing
			// always came back empty. The `(.+)` is greedy, so a session ID
			// containing underscores (sess_anon_{hex}_{n}) survives intact and
			// only the trailing all-digit run is read as the timestamp.
			// Sidecars (.oblog/.obidx/.obtmp) never match: the extension anchor
			// rejects them.
			$filename = $file->getFilename();
			if ( ! preg_match( '/^(.+)_(\d+)\.json(\.gz)?$/', $filename, $matches ) ) {
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
						// Bug #2 / D1: sort on the DISPLAY (replay) duration, so
						// the order matches the rendered column. Fall back to the
						// wall clock for legacy files with no timestamp span.
						$recording['duration'] = ( isset( $file_data['replay_duration'] ) && (int) $file_data['replay_duration'] > 0 )
							? (int) $file_data['replay_duration']
							: intval( $file_data['duration'] );
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

