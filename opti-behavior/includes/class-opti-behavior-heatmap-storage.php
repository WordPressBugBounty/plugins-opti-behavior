<?php
/**
 * Optimized Heatmap Storage Class
 *
 * Provides fast file-based storage for heatmap data (clicks, moves, scrolls).
 * Uses filename metadata for fast filtering without reading file contents.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.0
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
 * Opti_Behavior_Heatmap_Storage
 *
 * Handles optimized storage and retrieval of heatmap data.
 * Files are organized by URL hash and contain pre-extracted coordinates.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Heatmap_Storage {

	/**
	 * Singleton instance
	 *
	 * @var Opti_Behavior_Heatmap_Storage
	 */
	private static $instance = null;

	/**
	 * Storage base directory
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * Debug manager instance
	 *
	 * @var Opti_Behavior_Heatmap_Debug_Manager|null
	 */
	private $debug_manager = null;

	/**
	 * Event buffer for batch saving
	 *
	 * @var array
	 */
	private $event_buffer = array();

	/**
	 * Buffer flush threshold
	 *
	 * @var int
	 */
	private $buffer_threshold = 10;

	/**
	 * Get singleton instance
	 *
	 * @return Opti_Behavior_Heatmap_Storage
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @param Opti_Behavior_Heatmap_Debug_Manager|null $debug_manager Optional debug manager instance.
	 */
	public function __construct( $debug_manager = null ) {
		$this->debug_manager = $debug_manager;
		$this->init_storage_directory();

		// Flush buffer on shutdown
		add_action( 'shutdown', array( $this, 'flush_all_buffers' ) );
	}

	/**
	 * Set debug manager
	 *
	 * @param Opti_Behavior_Heatmap_Debug_Manager $debug_manager Debug manager instance.
	 */
	public function set_debug_manager( $debug_manager ) {
		$this->debug_manager = $debug_manager;
	}

	/**
	 * Log a debug message
	 *
	 * @param string $message Message to log.
	 * @param string $level   Log level (error, warning, info, debug).
	 */
	private function log( $message, $level = 'info' ) {
		if ( $this->debug_manager ) {
			$this->debug_manager->log( $message, $level, 'heatmap-storage' );
		}
	}

	/**
	 * Initialize storage directory
	 */
	private function init_storage_directory() {
		$upload_dir = wp_upload_dir();
		$this->base_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';

		// Create base directory if it doesn't exist
		if ( ! file_exists( $this->base_dir ) ) {
			wp_mkdir_p( $this->base_dir );

			// Add .htaccess to protect data files
			$this->create_htaccess( $this->base_dir );

			// Add index.php to prevent directory listing
			$this->create_index_file( $this->base_dir );
		}
	}

	/**
	 * Create .htaccess file to protect data
	 *
	 * @param string $dir Directory path.
	 */
	private function create_htaccess( $dir ) {
		$htaccess_file = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$content = "# Opti-Behavior Heatmap Data Protection\n";
			$content .= "Order deny,allow\n";
			$content .= "Deny from all\n";
			$content .= "<FilesMatch '\.(json)$'>\n";
			$content .= "  Deny from all\n";
			$content .= "</FilesMatch>\n";

			file_put_contents( $htaccess_file, $content );
		}
	}

	/**
	 * Create index.php to prevent directory listing
	 *
	 * @param string $dir Directory path.
	 */
	private function create_index_file( $dir ) {
		$index_file = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Get URL hash for organizing files
	 *
	 * @param string $url The URL to hash.
	 * @return string MD5 hash of the normalized URL.
	 */
	public function get_url_hash( $url ) {
		// Normalize URL: remove query params and fragment
		$parsed = wp_parse_url( $url );

		$normalized = '';
		if ( isset( $parsed['scheme'] ) ) {
			$normalized .= $parsed['scheme'] . '://';
		}
		if ( isset( $parsed['host'] ) ) {
			$normalized .= strtolower( $parsed['host'] );
		}
		if ( isset( $parsed['path'] ) ) {
			$normalized .= rtrim( $parsed['path'], '/' );
		}

		return md5( $normalized );
	}

	/**
	 * Get directory path for heatmap data
	 *
	 * Structure: opti-behavior-data/{url_hash}/clicks/
	 *            opti-behavior-data/{url_hash}/moves/
	 *            opti-behavior-data/{url_hash}/scrolls/
	 *
	 * @param string $url_hash URL hash (unique identifier for each visited URL).
	 * @param string $type     Data type (clicks, moves, scrolls).
	 * @return string Directory path.
	 */
	public function get_heatmap_dir( $url_hash, $type = 'clicks' ) {
		return $this->base_dir . $url_hash . '/' . $type . '/';
	}

	/**
	 * Ensure directory exists
	 *
	 * @param string $path Directory path.
	 * @return bool Success.
	 */
	public function ensure_directory_exists( $path ) {
		if ( ! file_exists( $path ) ) {
			$result = wp_mkdir_p( $path );
			if ( $result ) {
				$this->create_index_file( $path );
			}
			return $result;
		}
		return true;
	}

	/**
	 * Normalize browser name
	 *
	 * @param string $browser Raw browser string.
	 * @return string Normalized browser name.
	 */
	private function normalize_browser( $browser ) {
		$browser = strtolower( $browser );

		if ( strpos( $browser, 'chrome' ) !== false && strpos( $browser, 'edg' ) === false ) {
			return 'Chrome';
		}
		if ( strpos( $browser, 'firefox' ) !== false ) {
			return 'Firefox';
		}
		if ( strpos( $browser, 'safari' ) !== false && strpos( $browser, 'chrome' ) === false ) {
			return 'Safari';
		}
		if ( strpos( $browser, 'edg' ) !== false ) {
			return 'Edge';
		}
		if ( strpos( $browser, 'opera' ) !== false || strpos( $browser, 'opr' ) !== false ) {
			return 'Opera';
		}

		return 'Other';
	}

	/**
	 * Normalize device type
	 *
	 * @param string $device Raw device type.
	 * @return string Normalized device type.
	 */
	private function normalize_device( $device ) {
		$device = strtolower( $device );

		if ( in_array( $device, array( 'desktop', 'pc' ), true ) ) {
			return 'desktop';
		}
		if ( in_array( $device, array( 'mobile', 'phone' ), true ) ) {
			return 'mobile';
		}
		if ( $device === 'tablet' ) {
			return 'tablet';
		}

		return 'desktop';
	}

	/**
	 * Sanitize an arbitrary metadata value into a safe filename token.
	 *
	 * Underscore is the filename delimiter, so it (and any other unsafe char)
	 * is replaced with a dash. Empty values become the 'na' placeholder so
	 * positional parsing stays stable.
	 *
	 * @param string $value  Raw value (OS name, UTM value, ...).
	 * @param int    $maxlen Maximum token length.
	 * @return string Sanitized token ('na' when empty).
	 */
	public function sanitize_filename_token( $value, $maxlen = 24 ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 'na';
		}
		$token = preg_replace( '/[^a-zA-Z0-9\-]+/', '-', $value );
		$token = trim( $token, '-' );
		$token = substr( $token, 0, $maxlen );
		return '' === $token ? 'na' : $token;
	}

	/**
	 * Check whether a filename token matches any of the selected filter values.
	 *
	 * Both sides are run through sanitize_filename_token() and compared
	 * case-insensitively so "Windows 10" matches token "Windows-10".
	 *
	 * @param string $token  Token parsed from a filename ('' or 'na' = unknown).
	 * @param array  $wanted Selected raw filter values.
	 * @return bool True when the token matches one of the wanted values.
	 */
	private function token_matches_filter( $token, $wanted ) {
		$token = strtolower( (string) $token );
		if ( '' === $token || 'na' === $token ) {
			return false; // Old files without the token are excluded when the filter is active.
		}
		foreach ( $wanted as $value ) {
			if ( strtolower( $this->sanitize_filename_token( $value ) ) === $token ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether a file passes an OS/UTM dimension filter: filename token
	 * match OR the file's session suffix is in the DB-derived fallback set the
	 * AJAX layer attaches as $filters['adv_dim_suffixes'][dim]. Legacy files
	 * carry no os/utm tokens, so the DB set is their only match source.
	 *
	 * @param array  $filters      Full filters array (may carry 'adv_dim_suffixes').
	 * @param string $dim          Dimension key ('os', 'utm_campaign', ...).
	 * @param array  $wanted       Selected raw filter values (empty = filter inactive).
	 * @param string $token        Token parsed from the filename.
	 * @param string $file_session Session suffix parsed from the filename.
	 * @return bool True when the file passes the dimension filter.
	 */
	private function advanced_dimension_passes( $filters, $dim, $wanted, $token, $file_session ) {
		if ( empty( $wanted ) ) {
			return true;
		}
		if ( $this->token_matches_filter( $token, $wanted ) ) {
			return true;
		}
		return '' !== (string) $file_session
			&& isset( $filters['adv_dim_suffixes'][ $dim ] )
			&& is_array( $filters['adv_dim_suffixes'][ $dim ] )
			&& isset( $filters['adv_dim_suffixes'][ $dim ][ $file_session ] );
	}

	/**
	 * Build filename with metadata
	 *
	 * @param int    $timestamp    Unix timestamp.
	 * @param string $country      Country code.
	 * @param string $browser      Browser name.
	 * @param int    $count        Event count.
	 * @param string $device       Device type.
	 * @param string $session_id   Session ID.
	 * @param bool   $is_logged_in Whether visitor is logged in.
	 * @param int    $duration     Session duration in seconds (default 0).
	 * @param int    $ab_test_id   A/B test ID (0 = no test).
	 * @param int    $variant_id   A/B variant ID (0 = no variant).
	 * @param string $os           Operating system name ('' = unknown).
	 * @param string $utm_campaign UTM campaign ('' = none).
	 * @param string $utm_source   UTM source ('' = none).
	 * @param string $utm_medium   UTM medium ('' = none).
	 * @return string Filename.
	 */
	private function build_filename( $timestamp, $country, $browser, $count, $device, $session_id, $is_logged_in = false, $duration = 0, $ab_test_id = 0, $variant_id = 0, $os = '', $utm_campaign = '', $utm_source = '', $utm_medium = '' ) {
		$country = strtoupper( substr( preg_replace( '/[^a-zA-Z]/', '', $country ?: 'XX' ), 0, 2 ) );
		$browser = $this->normalize_browser( $browser );
		$device = $this->normalize_device( $device );
		// Use LAST 8 chars of session ID to get unique random suffix
		// session_{timestamp}_{random} -> last 8 chars include the random part
		$session_clean = preg_replace( '/[^a-zA-Z0-9]/', '', $session_id );
		$session_short = substr( $session_clean, -8 ); // Last 8 chars instead of first 8
		$logged_in_marker = $is_logged_in ? 'L' : 'G'; // L=Logged in, G=Guest
		$duration = max( 0, (int) $duration ); // Ensure non-negative integer
		$ab_test_id = max( 0, (int) $ab_test_id );
		$variant_id = max( 0, (int) $variant_id );
		$os_token   = $this->sanitize_filename_token( $os );
		$utm_c      = $this->sanitize_filename_token( $utm_campaign );
		$utm_s      = $this->sanitize_filename_token( $utm_source );
		$utm_m      = $this->sanitize_filename_token( $utm_medium );

		return sprintf(
			'%d_%s_%s_%d_%s_%s_%d_%d_%d_%s_%s_%s_%s_%s.json',
			$timestamp,
			$country,
			$browser,
			$count,
			$device,
			$logged_in_marker,
			$duration,
			$ab_test_id,
			$variant_id,
			$os_token,
			$utm_c,
			$utm_s,
			$utm_m,
			$session_short
		);
	}

	/**
	 * Parse metadata from filename
	 *
	 * Supports multiple formats for backward compatibility:
	 * - Extended format (14 parts): {timestamp}_{country}_{browser}_{count}_{device}_{L|G}_{duration}_{ab_test_id}_{variant_id}_{os}_{utm_campaign}_{utm_source}_{utm_medium}_{session}.json
	 * - AB format (10 parts): {timestamp}_{country}_{browser}_{count}_{device}_{L|G}_{duration}_{ab_test_id}_{variant_id}_{session}.json
	 * - Newest format (8 parts): {timestamp}_{country}_{browser}_{count}_{device}_{L|G}_{duration}_{session}.json
	 * - New format (7 parts): {timestamp}_{country}_{browser}_{count}_{device}_{L|G}_{session}.json
	 * - Old format (6 parts): {timestamp}_{country}_{browser}_{count}_{device}_{session}.json
	 *
	 * @param string $filename Filename to parse.
	 * @return array|false Parsed metadata or false on failure.
	 */
	public function parse_filename_metadata( $filename ) {
		// Remove .json extension
		$name = str_replace( '.json', '', $filename );
		$parts = explode( '_', $name );

		if ( count( $parts ) < 6 ) {
			return false;
		}

		// Check if this is the extended format (14 parts with os + utm tokens).
		// MUST be checked before the 10-part AB branch: a 14-part name also
		// satisfies count >= 10 and would be mis-assigned positionally.
		if ( count( $parts ) >= 14 && in_array( $parts[5], array( 'L', 'G' ), true ) && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
			return array(
				'timestamp'    => (int) $parts[0],
				'country'      => $parts[1],
				'browser'      => $parts[2],
				'count'        => (int) $parts[3],
				'device'       => $parts[4],
				'is_logged_in' => $parts[5] === 'L',
				'duration'     => (int) $parts[6],
				'ab_test_id'   => (int) $parts[7],
				'variant_id'   => (int) $parts[8],
				'os'           => 'na' === $parts[9] ? '' : $parts[9],
				'utm_campaign' => 'na' === $parts[10] ? '' : $parts[10],
				'utm_source'   => 'na' === $parts[11] ? '' : $parts[11],
				'utm_medium'   => 'na' === $parts[12] ? '' : $parts[12],
				'session_id'   => $parts[13],
			);
		}

		// Check if this is AB format (10 parts with ab_test_id and variant_id)
		// Format: {ts}_{country}_{browser}_{count}_{device}_{L|G}_{duration}_{ab_test_id}_{variant_id}_{session}
		if ( count( $parts ) >= 10 && in_array( $parts[5], array( 'L', 'G' ), true ) && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
			return array(
				'timestamp'    => (int) $parts[0],
				'country'      => $parts[1],
				'browser'      => $parts[2],
				'count'        => (int) $parts[3],
				'device'       => $parts[4],
				'is_logged_in' => $parts[5] === 'L',
				'duration'     => (int) $parts[6],
				'ab_test_id'   => (int) $parts[7],
				'variant_id'   => (int) $parts[8],
				'os'           => '',
				'utm_campaign' => '',
				'utm_source'   => '',
				'utm_medium'   => '',
				'session_id'   => $parts[9],
			);
		}

		// Check if this is newest format (8 parts with duration)
		if ( count( $parts ) >= 8 && in_array( $parts[5], array( 'L', 'G' ), true ) ) {
			// Newest format with logged_in marker and duration
			return array(
				'timestamp'    => (int) $parts[0],
				'country'      => $parts[1],
				'browser'      => $parts[2],
				'count'        => (int) $parts[3],
				'device'       => $parts[4],
				'is_logged_in' => $parts[5] === 'L',
				'duration'     => (int) $parts[6],
				'ab_test_id'   => 0,
				'variant_id'   => 0,
				'os'           => '',
				'utm_campaign' => '',
				'utm_source'   => '',
				'utm_medium'   => '',
				'session_id'   => $parts[7],
			);
		}

		// Check if this is new format (7 parts with logged_in marker, no duration)
		if ( count( $parts ) >= 7 && in_array( $parts[5], array( 'L', 'G' ), true ) ) {
			// New format with logged_in marker but no duration
			return array(
				'timestamp'    => (int) $parts[0],
				'country'      => $parts[1],
				'browser'      => $parts[2],
				'count'        => (int) $parts[3],
				'device'       => $parts[4],
				'is_logged_in' => $parts[5] === 'L',
				'duration'     => 0, // No duration in old format
				'ab_test_id'   => 0,
				'variant_id'   => 0,
				'os'           => '',
				'utm_campaign' => '',
				'utm_source'   => '',
				'utm_medium'   => '',
				'session_id'   => $parts[6],
			);
		}

		// Old format without logged_in marker (assume guest for backward compatibility)
		return array(
			'timestamp'    => (int) $parts[0],
			'country'      => $parts[1],
			'browser'      => $parts[2],
			'count'        => (int) $parts[3],
			'device'       => $parts[4],
			'is_logged_in' => false, // Default to guest for old files
			'duration'     => 0, // No duration in old format
			'ab_test_id'   => 0,
			'variant_id'   => 0,
			'os'           => '',
			'utm_campaign' => '',
			'utm_source'   => '',
			'utm_medium'   => '',
			'session_id'   => $parts[5],
		);
	}

	/**
	 * Extract the visitor-type marker (L=logged in, G=guest) from heatmap file
	 * parts across all historical filename formats.
	 *
	 * Shared by the dashboard's file-scan fallback, Pro's device-count file
	 * scan, and the cascade-delete file matcher, so all call sites classify
	 * filenames identically. Older files without a marker default to guest (G).
	 *
	 * @param array $parts Underscore-split filename parts (without extension).
	 * @return string 'L' or 'G'.
	 */
	public static function parse_visitor_marker( $parts ) {
		if ( count( $parts ) >= 8 && isset( $parts[5] ) && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) ) {
			return strtoupper( $parts[5] );
		}
		if ( count( $parts ) >= 7 && isset( $parts[5] ) && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) ) {
			return strtoupper( $parts[5] );
		}

		return 'G';
	}

	/**
	 * Extract the session token from heatmap file parts across all historical
	 * filename formats.
	 *
	 * Shared by the dashboard's file-scan fallback, Pro's device-count file
	 * scan, and the cascade-delete file matcher.
	 *
	 * @param array $parts Underscore-split filename parts (without extension).
	 * @return string Session token.
	 */
	public static function parse_session_token( $parts ) {
		$has_marker = isset( $parts[5] ) && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true );

		// Extended format (14 parts): session at index 13 (last).
		if ( count( $parts ) >= 14 && $has_marker && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
			return $parts[13];
		}
		// AB format (10 parts): session at index 9 (last), NOT index 7 (ab_test_id).
		if ( count( $parts ) >= 10 && $has_marker && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
			return $parts[9];
		}
		if ( count( $parts ) >= 8 && $has_marker ) {
			return $parts[7];
		}
		if ( count( $parts ) >= 7 && $has_marker ) {
			return $parts[6];
		}

		return end( $parts );
	}

	/**
	 * Calculate session duration from event timestamps
	 *
	 * @param array $data Array of events with 't' (timestamp) field.
	 * @return int Duration in seconds.
	 */
	private function calculate_session_duration( $data ) {
		if ( empty( $data ) ) {
			return 0;
		}

		$timestamps = array();
		foreach ( $data as $event ) {
			if ( isset( $event['t'] ) ) {
				$timestamps[] = $event['t'];
			}
		}

		if ( count( $timestamps ) < 2 ) {
			return 0;
		}

		$min_ts = min( $timestamps );
		$max_ts = max( $timestamps );

		// Convert milliseconds to seconds and round
		return (int) round( ( $max_ts - $min_ts ) / 1000 );
	}

	/**
	 * Emit a monitorable error when a heatmap JSON file write fails.
	 *
	 * Part of the write-path hardening (registry visibility is decoupled from
	 * file-write success): logs to the plugin debug log always, and to the PHP
	 * error log once per request when WP_DEBUG is on, so a recurring disk
	 * full/permission failure is observable instead of silently hiding pages.
	 *
	 * @param string $filepath Target file path that failed to write.
	 * @param string $type     Heatmap data type (clicks/moves/scrolls).
	 * @return void
	 */
	private function log_heatmap_file_write_failure( $filepath, $type ) {
		$this->log( sprintf( 'Heatmap %s file write failed (%s) — registry row updated from DB so the page stays visible on the Heatmaps list.', $type, $filepath ), 'error' );

		static $logged = false;
		if ( ! $logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$logged = true;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Monitorable error for a genuine write failure, logged once per request under WP_DEBUG.
			error_log( sprintf( '[Opti-Behavior Heatmap] File write failed for %s (%s). Registry row still updated so the page remains listed. Check disk space/permissions on the uploads directory. (further write failures this request are logged to the plugin debug log only)', $type, $filepath ) );
		}
	}

	/**
	 * Save heatmap data to file
	 *
	 * If a file already exists for this session, it will be updated by merging the new data.
	 * This ensures we have only ONE file per session per type (clicks/moves/scrolls).
	 *
	 * @param string $url_hash   URL hash.
	 * @param string $type       Data type (clicks, moves, scrolls).
	 * @param string $session_id Session ID.
	 * @param array  $data       Heatmap coordinates data.
	 * @param array  $metadata   Session metadata (country, browser, device, viewport, etc.).
	 * @return array|false Result with file_path or false on failure.
	 */
	public function save_heatmap_data( $url_hash, $type, $session_id, $data, $metadata ) {
		if ( empty( $data ) ) {
			return false;
		}

		$dir = $this->get_heatmap_dir( $url_hash, $type );
		if ( ! $this->ensure_directory_exists( $dir ) ) {
			$this->log( 'Failed to create directory: ' . $dir, 'error' );
			return false;
		}

		$timestamp = isset( $metadata['timestamp'] ) ? $metadata['timestamp'] : time();
		$country = isset( $metadata['country'] ) ? $metadata['country'] : 'XX';
		$browser = isset( $metadata['browser'] ) ? $metadata['browser'] : 'Other';
		$device = isset( $metadata['device_type'] ) ? $metadata['device_type'] : 'desktop';
		$is_logged_in = ! empty( $metadata['is_logged_in'] );
		$user_id = isset( $metadata['user_id'] ) ? (int) $metadata['user_id'] : 0;
		$ab_test_id = isset( $metadata['ab_test_id'] ) ? (int) $metadata['ab_test_id'] : 0;
		$variant_id = isset( $metadata['variant_id'] ) ? (int) $metadata['variant_id'] : 0;
		$os = isset( $metadata['os'] ) ? (string) $metadata['os'] : '';
		$utm_campaign = isset( $metadata['utm_campaign'] ) ? (string) $metadata['utm_campaign'] : '';
		$utm_source = isset( $metadata['utm_source'] ) ? (string) $metadata['utm_source'] : '';
		$utm_medium = isset( $metadata['utm_medium'] ) ? (string) $metadata['utm_medium'] : '';

		// Check if a file already exists for this session
		$existing_file = $this->find_existing_session_file( $dir, $session_id );

		if ( $existing_file ) {
			// File exists - UPDATE it by merging new data
			$this->log( sprintf( 'Found existing file for session %s: %s - merging data', substr( $session_id, 0, 16 ), $existing_file ), 'info' );

			// Race-safety: a concurrent merge writer may have deleted the file
			// between find_existing_session_file() (scandir) and this read.
			// Guard with file_exists + clearstatcache to dodge the PHP warning
			// emitted by file_get_contents on a missing path. See investigation
			// 2026-04-26 (Bug B).
			$existing_path = $dir . $existing_file;
			clearstatcache( true, $existing_path );
			if ( ! file_exists( $existing_path ) ) {
				$this->log( 'Existing file disappeared (likely concurrent merge): ' . $existing_file, 'info' );
				return false;
			}

			// Write-time growth cap (spec §G.2): refuse the merge when it would
			// push the per-session file over the point cap, or when the existing
			// file is already beyond the max-read-bytes guard (reading it here
			// would risk the same memory fatal the guard exists to prevent).
			// The existing file is kept intact; the new batch is dropped. This
			// is the mechanism that stops per-session files from ever growing
			// to hundreds of MB.
			$point_cap      = self::max_points_per_session_file();
			$existing_meta  = $this->parse_filename_metadata( $existing_file );
			$existing_count = ( is_array( $existing_meta ) && isset( $existing_meta['count'] ) ) ? (int) $existing_meta['count'] : 0;
			if ( $existing_count + count( $data ) > $point_cap || self::file_exceeds_read_limit( $existing_path ) ) {
				static $merge_cap_logged = false;
				if ( ! $merge_cap_logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$merge_cap_logged = true;
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is on, once per request.
					error_log(
						sprintf(
							'[Opti-Behavior Heatmap] Merge refused: session file at/over growth cap (%d existing + %d new points, cap %d): %s (further cap refusals this request are logged silently)',
							$existing_count,
							count( $data ),
							$point_cap,
							$existing_file
						)
					);
				}
				$this->log( sprintf( 'Merge refused by growth cap (%d + %d > %d points): %s', $existing_count, count( $data ), $point_cap, $existing_file ), 'warning' );
				return false;
			}

			// Read existing file
			$existing_content = file_get_contents( $existing_path );
			if ( false === $existing_content ) {
				$this->log( 'Failed to read existing file: ' . $existing_file, 'error' );
				return false;
			}

			$existing_data = json_decode( $existing_content, true );
			if ( ! $existing_data || ! isset( $existing_data['data'] ) ) {
				$this->log( 'Invalid existing file format: ' . $existing_file, 'error' );
				return false;
			}

			// Merge data (append new events to existing)
			$merged_data = array_merge( $existing_data['data'], $data );
			$total_count = count( $merged_data );

			// Calculate duration from merged data
			$duration = $this->calculate_session_duration( $merged_data );

			// Delete old file
			wp_delete_file( $dir . $existing_file );

			// Preserve A/B info from existing file if current metadata lacks it
			$opti_ab_merge_test_id = $ab_test_id > 0 ? $ab_test_id : ( isset( $existing_data['ab_test_id'] ) ? (int) $existing_data['ab_test_id'] : 0 );
			$opti_ab_merge_variant = $variant_id > 0 ? $variant_id : ( isset( $existing_data['variant_id'] ) ? (int) $existing_data['variant_id'] : 0 );

			// Preserve OS / UTM info from existing file if current metadata lacks it
			$os           = '' !== $os ? $os : ( isset( $existing_data['os'] ) ? (string) $existing_data['os'] : '' );
			$utm_campaign = '' !== $utm_campaign ? $utm_campaign : ( isset( $existing_data['utm_campaign'] ) ? (string) $existing_data['utm_campaign'] : '' );
			$utm_source   = '' !== $utm_source ? $utm_source : ( isset( $existing_data['utm_source'] ) ? (string) $existing_data['utm_source'] : '' );
			$utm_medium   = '' !== $utm_medium ? $utm_medium : ( isset( $existing_data['utm_medium'] ) ? (string) $existing_data['utm_medium'] : '' );

			// Prepare merged file content (keep metadata from first file, update data, count, and duration)
			$file_content = array(
				'page_id'      => $existing_data['page_id'],
				'url'          => isset( $metadata['url'] ) ? $metadata['url'] : ( isset( $existing_data['url'] ) ? $existing_data['url'] : '' ),
				'session_id'   => $session_id,
				'visitor_id'   => $existing_data['visitor_id'],
				'device_type'  => $device,
				'browser'      => $browser,
				'country'      => $country,
				'is_logged_in' => $is_logged_in,
				'user_id'      => $user_id,
				'viewport'     => $existing_data['viewport'],
				'timestamp'    => $existing_data['timestamp'], // Keep original timestamp
				'duration'     => $duration,
				'ab_test_id'   => $opti_ab_merge_test_id,
				'variant_id'   => $opti_ab_merge_variant,
				'os'           => $os,
				'utm_campaign' => $utm_campaign,
				'utm_source'   => $utm_source,
				'utm_medium'   => $utm_medium,
				'data'         => $merged_data,
			);

			// Build new filename with updated count and duration
			$filename = $this->build_filename( $existing_data['timestamp'], $country, $browser, $total_count, $device, $session_id, $is_logged_in, $duration, $opti_ab_merge_test_id, $opti_ab_merge_variant, $os, $utm_campaign, $utm_source, $utm_medium );
			$filepath = $dir . $filename;

			$json = wp_json_encode( $file_content );
			$written = file_put_contents( $filepath, $json );

			if ( false === $written ) {
				$this->log( 'Failed to write merged heatmap file: ' . $filepath, 'error' );
				// Write-path hardening: the optibehavior_heatmap_pages registry row
				// is the ONLY data source for admin.php?page=opti-behavior-heatmaps,
				// so a failed file write must NOT hide a page that really received
				// interactions. Update the registry counters from the DB regardless
				// of file-write success and log a monitorable error.
				$this->log_heatmap_file_write_failure( $filepath, $type );
				$this->update_url_hash_mapping( $url_hash, $metadata, $type, count( $data ) );
				return false;
			}

			$this->log( sprintf( 'Merged %d new events into existing file (total: %d %s) - %s', count( $data ), $total_count, $type, $filename ), 'info' );

			// Update URL hash mapping in database
			$this->update_url_hash_mapping( $url_hash, $metadata, $type, count( $data ) );

			// Spec §P1 write-time upsert: add the merged point delta to the day
			// of the file's ORIGINAL timestamp (a merged file keeps its creation
			// timestamp in the filename, so the live scan buckets all its points
			// on that day). No file/session delta: the file already exists and
			// its session was counted when it was created.
			$this->upsert_heatmap_daily_delta(
				isset( $existing_data['page_id'] ) ? (int) $existing_data['page_id'] : ( isset( $metadata['page_id'] ) ? (int) $metadata['page_id'] : 0 ),
				(int) $existing_data['timestamp'],
				$type,
				$device,
				count( $data ),
				0,
				false,
				$session_id
			);

			return array(
				'success'   => true,
				'file_path' => str_replace( $this->base_dir, '', $filepath ),
				'file_size' => filesize( $filepath ),
				'count'     => $total_count,
				'merged'    => true,
			);
		} else {
			// No existing file - CREATE new one
			$count = count( $data );

			// Calculate duration from data
			$duration = $this->calculate_session_duration( $data );

			$filename = $this->build_filename( $timestamp, $country, $browser, $count, $device, $session_id, $is_logged_in, $duration, $ab_test_id, $variant_id, $os, $utm_campaign, $utm_source, $utm_medium );
			$filepath = $dir . $filename;

			// Prepare file content
			$file_content = array(
				'page_id'      => isset( $metadata['page_id'] ) ? $metadata['page_id'] : 0,
				'url'          => isset( $metadata['url'] ) ? $metadata['url'] : '',
				'session_id'   => $session_id,
				'visitor_id'   => isset( $metadata['visitor_id'] ) ? $metadata['visitor_id'] : '',
				'device_type'  => $device,
				'browser'      => $browser,
				'country'      => $country,
				'is_logged_in' => $is_logged_in,
				'user_id'      => $user_id,
				'viewport'     => isset( $metadata['viewport'] ) ? $metadata['viewport'] : array( 'width' => 1920, 'height' => 1080 ),
				'timestamp'    => $timestamp,
				'duration'     => $duration,
				'ab_test_id'   => $ab_test_id,
				'variant_id'   => $variant_id,
				'os'           => $os,
				'utm_campaign' => $utm_campaign,
				'utm_source'   => $utm_source,
				'utm_medium'   => $utm_medium,
				'data'         => $data,
			);

			$json = wp_json_encode( $file_content );
			$written = file_put_contents( $filepath, $json );

			if ( false === $written ) {
				$this->log( 'Failed to write heatmap file: ' . $filepath, 'error' );
				// Write-path hardening: create/update the registry row even when
				// the JSON file write fails so the page still appears on the
				// Heatmaps list (its only data source). Without this, a disk
				// full/permission error on the very first interaction for a URL
				// left the page permanently invisible despite durable
				// optibehavior_events rows. A monitorable error is logged.
				$this->log_heatmap_file_write_failure( $filepath, $type );
				$this->update_url_hash_mapping( $url_hash, $metadata, $type, $count );
				return false;
			}

			$this->log( sprintf( 'Created new file with %d %s events: %s', $count, $type, $filename ), 'info' );

			// Update URL hash mapping in database
			$this->update_url_hash_mapping( $url_hash, $metadata, $type, $count );

			// Spec §P1 write-time upsert: one O(1) daily-index delta for the new
			// file (points + file count + possibly a new unique guest session).
			$this->upsert_heatmap_daily_delta(
				isset( $metadata['page_id'] ) ? (int) $metadata['page_id'] : 0,
				(int) $timestamp,
				$type,
				$device,
				$count,
				1,
				! $is_logged_in,
				$session_id
			);

			// Spec §P0: keep the adaptive-mode total-files estimate current.
			$this->adjust_total_files_estimate( 1 );

			// Race-safety: filesize() emits a PHP Warning if the file vanished
			// between fwrite and stat (concurrent merge writer can wp_delete_file
			// the freshly-created file). Guard via file_exists. See investigation
			// 2026-04-26 (Bug B).
			clearstatcache( true, $filepath );
			$file_size = file_exists( $filepath ) ? (int) filesize( $filepath ) : 0;

			return array(
				'success'   => true,
				'file_path' => str_replace( $this->base_dir, '', $filepath ),
				'file_size' => $file_size,
				'count'     => $count,
				'merged'    => false,
			);
		}
	}

	/**
	 * Find existing heatmap file for a session
	 *
	 * Scans the directory for any file matching this session ID.
	 *
	 * @param string $dir        Directory to search.
	 * @param string $session_id Session ID to find.
	 * @return string|false Filename if found, false otherwise.
	 */
	private function find_existing_session_file( $dir, $session_id ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$files = scandir( $dir );
		if ( ! $files ) {
			return false;
		}

		// Extract session ID short form - use LAST 8 chars to get unique random part
		// session_{timestamp}_{random} -> take last 8 chars which include the random suffix
		$session_clean = preg_replace( '/[^a-zA-Z0-9]/', '', $session_id );
		$session_short = substr( $session_clean, -8 ); // Last 8 chars instead of first 8

		foreach ( $files as $file ) {
			if ( $file === '.' || $file === '..' || $file === 'index.php' ) {
				continue;
			}

			// Check if filename contains this session ID
			// Format: {timestamp}_{country}_{browser}_{count}_{device}_{L|G}_{session}.json
			if ( strpos( $file, '_' . $session_short . '.json' ) !== false ) {
				return $file;
			}
		}

		return false;
	}

	/**
	 * Update URL hash mapping in database
	 *
	 * @param string $url_hash URL hash.
	 * @param array  $metadata Session metadata.
	 * @param string $type     Event type.
	 * @param int    $count    Event count.
	 */
	private function update_url_hash_mapping( $url_hash, $metadata, $type, $count ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( ! $table_exists ) {
			return;
		}

		$page_id = isset( $metadata['page_id'] ) ? (int) $metadata['page_id'] : 0;
		$url = isset( $metadata['url'] ) ? $metadata['url'] : '';

		// Check if record exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, page_id, url, click_count, move_count, scroll_count FROM {$table_name} WHERE url_hash = %s",
				$url_hash
			)
		);

		$now = current_time( 'mysql' );

		if ( $existing ) {
			// Update existing record
			$update_data = array(
				'updated_at'   => $now,
				'last_data_at' => $now,
			);

			$update_format = array( '%s', '%s' );

			// Self-heal drifted mappings: url_hash is derived from the URL, so a
			// row whose stored page_id/url no longer matches the current request's
			// metadata is stale — typically after optibehavior_pages was truncated
			// (Danger Zone partial cleanup / delete_all) without this table, which
			// resets AUTO_INCREMENT and lets brand-new pages reuse old IDs. Without
			// this re-sync the stale FK persists forever, because this UPDATE
			// branch otherwise only touches counters/timestamps.
			if ( $page_id > 0 && (int) $existing->page_id !== $page_id ) {
				$update_data['page_id'] = $page_id;
				$update_format[]        = '%d';
			}
			if ( '' !== $url && $existing->url !== $url ) {
				$update_data['url'] = $url;
				$update_format[]    = '%s';
			}

			if ( $type === 'clicks' ) {
				$update_data['click_count'] = $existing->click_count + $count;
				$update_format[]            = '%d';
			} elseif ( $type === 'moves' ) {
				$update_data['move_count'] = $existing->move_count + $count;
				$update_format[]           = '%d';
			} elseif ( $type === 'scrolls' ) {
				$update_data['scroll_count'] = $existing->scroll_count + $count;
				$update_format[]             = '%d';
			}

			// Phase 3: mark the precomputed aggregate columns stale so the
			// dashboard recomputes exact values for this page on its next load.
			if ( $this->heatmap_agg_columns_exist() ) {
				$update_data['agg_synced_at'] = null;
				$update_format[]              = '%s';
			}

			// Spec §P1: mark the per-day index rows stale too, so the cron
			// indexer re-derives them exactly (heals any write-time upsert
			// drift). Folded into this existing UPDATE — zero extra queries on
			// the beacon path.
			if ( $this->heatmap_daily_columns_exist() ) {
				$update_data['daily_synced_at'] = null;
				$update_format[]                = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$wpdb->update(
				$table_name,
				$update_data,
				array( 'id' => $existing->id ),
				$update_format,
				array( '%d' )
			);
		} else {
			// Insert new record
			$insert_data = array(
				'page_id'      => $page_id,
				'url_hash'     => $url_hash,
				'url'          => $url,
				'created_at'   => $now,
				'updated_at'   => $now,
				'click_count'  => $type === 'clicks' ? $count : 0,
				'move_count'   => $type === 'moves' ? $count : 0,
				'scroll_count' => $type === 'scrolls' ? $count : 0,
				'last_data_at' => $now,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$wpdb->insert(
				$table_name,
				$insert_data,
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
			);

			// BUG-1 recurrence guard: a fresh mapping row for a page_id that
			// already has one (e.g. a stale QA/alias URL colliding on the same
			// id) would silently double-count every heatmap number. The insert
			// branch is rare (first data for a url_hash), so collapsing the page
			// to its single canonical row here is cheap and prevents duplicates
			// from ever accumulating again. Guarded to only act when a genuine
			// duplicate exists.
			if ( $page_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
				$row_count = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE page_id = %d", $page_id )
				);
				if ( $row_count > 1 ) {
					$this->dedupe_page_mappings( $page_id );
				}
			}
		}

		// New heatmap data landed — drop cached aggregates so the Heatmaps page
		// recomputes exact counts on its next load (covers Free + Pro ingest,
		// since Pro writes its event files through this same storage class).
		if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
			opti_behavior_flush_heatmap_caches();
		}
	}

	/**
	 * Mark the precomputed aggregate columns stale for the given pages so the
	 * Heatmaps dashboard recomputes exact click/session/interaction counts
	 * from the files actually remaining on disk on its next load.
	 *
	 * Shares the same `agg_synced_at = NULL` invalidation semantics as the
	 * ingest write path above (self::save/update methods); callers that
	 * mutate heatmap data outside of ingest (e.g. cascade session deletion)
	 * should call this instead of duplicating the raw SQL.
	 *
	 * @since 1.6.8
	 * @param array<int,int> $page_ids Page IDs whose aggregate cache should be invalidated.
	 * @return int Number of rows marked stale.
	 */
	public function mark_pages_stale( array $page_ids ) {
		global $wpdb;

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
		if ( empty( $page_ids ) || ! $this->heatmap_agg_columns_exist() ) {
			return 0;
		}

		$table_name   = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );

		// Also invalidate the per-day index rows (spec §P1/§P4): data mutations
		// outside ingest (cascade deletion, purge, spam reclassification) must
		// make the cron indexer re-derive the daily table from disk.
		$set_clause = $this->heatmap_daily_columns_exist()
			? 'agg_synced_at = NULL, daily_synced_at = NULL'
			: 'agg_synced_at = NULL';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; analytics queries.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name} SET {$set_clause} WHERE page_id IN ({$placeholders})",
				...$page_ids
			)
		);

		return false !== $updated ? absint( $updated ) : 0;
	}

	/**
	 * Deletion-path hook (spec P4.1): record that heatmap session JSON files
	 * were removed from disk outside the ingest path.
	 *
	 * Cheap and synchronous by design — NULLs the affected pages' agg_/daily_
	 * sync markers so the cron indexer re-derives them from the files that
	 * actually remain. No inline recomputation ever happens here.
	 *
	 * The adaptive-mode total-files estimate is deliberately NOT touched:
	 * the deleted files are still present in the pages' daily.file_count sums
	 * (write-time upserts keep those in lockstep with the estimate), so the
	 * indexer's re-derive correction (disk total − stored sum) already
	 * subtracts them exactly once. Decrementing here as well double-counted
	 * the deletion and permanently understated the estimate — an understated
	 * estimate can wrongly flip the dashboard into the expensive live-scan
	 * mode, which is the failure the estimate exists to prevent. The brief
	 * overcount until the scheduled re-derive is the safe direction.
	 *
	 * @since 1.9.x
	 * @param int   $file_count Number of session files deleted (0 allowed).
	 * @param int[] $page_ids   Affected page ids; empty = unknown, mark ALL pages stale.
	 * @return int Mapping rows marked stale.
	 */
	public function note_heatmap_files_deleted( $file_count, array $page_ids = array() ) {
		$file_count = max( 0, (int) $file_count );
		if ( $file_count <= 0 ) {
			return 0;
		}

		if ( ! empty( $page_ids ) ) {
			return $this->mark_pages_stale( $page_ids );
		}

		return $this->mark_all_pages_stale();
	}

	/**
	 * Mark EVERY mapping row aggregate- and daily-stale (spec P4.1).
	 *
	 * Used by deletion paths that cannot resolve which pages lost files
	 * (retention sweeps over all hash dirs, danger-zone date-range wipes):
	 * the cron indexer then re-derives each page from disk a batch at a time.
	 *
	 * @since 1.9.x
	 * @return int Mapping rows marked stale.
	 */
	public function mark_all_pages_stale() {
		global $wpdb;

		if ( ! $this->heatmap_agg_columns_exist() ) {
			return 0;
		}

		$table_name = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$set_clause = $this->heatmap_daily_columns_exist()
			? 'agg_synced_at = NULL, daily_synced_at = NULL'
			: 'agg_synced_at = NULL';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; no user input.
		$updated = $wpdb->query( "UPDATE {$table_name} SET {$set_clause}" );

		return false !== $updated ? absint( $updated ) : 0;
	}

	/**
	 * Collapse duplicate `optibehavior_heatmap_pages` rows for a single page_id
	 * down to one canonical mapping row (BUG-1 fix).
	 *
	 * A page_id may accumulate more than one mapping row when it is tracked
	 * under several URLs (e.g. a live canonical URL plus a stale QA URL whose
	 * WP post was later deleted, or after an AUTO_INCREMENT id reuse). Those
	 * siblings make every heatmap-derived number double-count (the file
	 * aggregators union all hash dirs of a page) and let
	 * sync_heatmap_aggregate_columns() stamp identical agg_* onto each row.
	 *
	 * Keeper selection (first match wins):
	 *   1. Row whose url_hash equals the hash of the page's current canonical
	 *      URL (`optibehavior_pages.url`).
	 *   2. Row whose stored `url` equals that canonical URL (slash-insensitive).
	 *   3. Most-recent valid: greatest data volume (click+move+scroll), then
	 *      newest `last_data_at`, then lowest `id`.
	 *
	 * Non-keeper rows are deleted and their on-disk hash dir is MOVED (never
	 * hard-deleted) to `opti-behavior-data/_orphaned/{hash}/` so the raw data
	 * remains recoverable. The keeper is marked aggregate-stale so the next
	 * dashboard load recomputes exact counts over the remaining single dir.
	 *
	 * @since 1.8.4
	 * @param int $page_id Page ID whose mapping rows should be collapsed.
	 * @return array{removed:int,kept_id:int,orphaned_hashes:string[]} Result summary.
	 */
	public function dedupe_page_mappings( $page_id ) {
		global $wpdb;

		$page_id = (int) $page_id;
		$result  = array( 'removed' => 0, 'kept_id' => 0, 'orphaned_hashes' => array() );
		if ( $page_id <= 0 ) {
			return $result;
		}

		$table_name = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; analytics query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url_hash, url, click_count, move_count, scroll_count, last_data_at
				FROM {$table_name} WHERE page_id = %d ORDER BY id ASC",
				$page_id
			)
		);

		if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
			return $result;
		}

		// Resolve the page's current canonical URL + its hash.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; analytics query.
		$canonical_url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT url FROM {$wpdb->prefix}optibehavior_pages WHERE id = %d",
				$page_id
			)
		);
		$canonical_hash = $canonical_url ? $this->get_url_hash( $canonical_url ) : '';
		$canonical_norm = $canonical_url ? rtrim( (string) $canonical_url, '/' ) : '';

		// Pick the keeper.
		$keeper = null;
		if ( '' !== $canonical_hash ) {
			foreach ( $rows as $r ) {
				if ( (string) $r->url_hash === $canonical_hash ) {
					$keeper = $r;
					break;
				}
			}
		}
		if ( null === $keeper && '' !== $canonical_norm ) {
			foreach ( $rows as $r ) {
				if ( rtrim( (string) $r->url, '/' ) === $canonical_norm ) {
					$keeper = $r;
					break;
				}
			}
		}
		if ( null === $keeper ) {
			// Most-recent valid fallback.
			$best = null;
			foreach ( $rows as $r ) {
				$vol = (int) $r->click_count + (int) $r->move_count + (int) $r->scroll_count;
				if ( null === $best ) {
					$best = array( 'row' => $r, 'vol' => $vol );
					continue;
				}
				$ts_r    = $r->last_data_at ? strtotime( $r->last_data_at ) : 0;
				$ts_best = $best['row']->last_data_at ? strtotime( $best['row']->last_data_at ) : 0;
				if ( $vol > $best['vol']
					|| ( $vol === $best['vol'] && $ts_r > $ts_best )
					|| ( $vol === $best['vol'] && $ts_r === $ts_best && (int) $r->id < (int) $best['row']->id )
				) {
					$best = array( 'row' => $r, 'vol' => $vol );
				}
			}
			$keeper = $best ? $best['row'] : $rows[0];
		}

		$keeper_id = (int) $keeper->id;
		$result['kept_id'] = $keeper_id;

		foreach ( $rows as $r ) {
			if ( (int) $r->id === $keeper_id ) {
				continue;
			}

			// Archive the on-disk hash dir (never hard-delete).
			$this->archive_orphaned_hash_dir( (string) $r->url_hash );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing a de-duplicated mapping row.
			$deleted = $wpdb->delete( $table_name, array( 'id' => (int) $r->id ), array( '%d' ) );
			if ( false !== $deleted && $deleted > 0 ) {
				++$result['removed'];
				$result['orphaned_hashes'][] = (string) $r->url_hash;
			}
		}

		if ( $result['removed'] > 0 ) {
			// The keeper now covers a smaller file universe — recompute its
			// aggregates on the next dashboard load.
			if ( $this->heatmap_agg_columns_exist() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate staleness marker.
				$wpdb->update( $table_name, array( 'agg_synced_at' => null ), array( 'id' => $keeper_id ), array( '%s' ), array( '%d' ) );
			}

			// Drop the per-page url-hash caches so readers see the collapsed set.
			wp_cache_delete( 'opti_url_hash_' . $page_id, 'opti-behavior' );
			wp_cache_delete( 'opti_url_hashes_' . $page_id, 'opti-behavior' );

			$this->log(
				sprintf(
					'Deduped heatmap mapping rows for page_id %d: kept id=%d, archived %d stale row(s)/dir(s)',
					$page_id,
					$keeper_id,
					$result['removed']
				),
				'info'
			);
		}

		return $result;
	}

	/**
	 * Move a stale url_hash data directory under the `_orphaned` archive so the
	 * raw heatmap files survive a mapping-row dedupe (never hard-deleted).
	 *
	 * @since 1.8.4
	 * @param string $url_hash Hash whose directory should be archived.
	 * @return bool True when a directory was moved (or already archived), false otherwise.
	 */
	private function archive_orphaned_hash_dir( $url_hash ) {
		$url_hash = preg_replace( '/[^a-f0-9]/i', '', (string) $url_hash );
		if ( '' === $url_hash ) {
			return false;
		}

		$src = $this->base_dir . $url_hash . '/';
		if ( ! is_dir( $src ) ) {
			return false;
		}

		$archive_root = $this->base_dir . '_orphaned/';
		if ( ! is_dir( $archive_root ) ) {
			wp_mkdir_p( $archive_root );
			$this->create_htaccess( $archive_root );
			$this->create_index_file( $archive_root );
		}

		$dest = $archive_root . $url_hash . '/';
		if ( is_dir( $dest ) ) {
			// Preserve any earlier archive under a timestamped suffix.
			$dest = $archive_root . $url_hash . '-' . gmdate( 'YmdHis' ) . '/';
		}

		// @rename is atomic on the same filesystem; suppress the warning and
		// fall back to a copy+delete only if it fails.
		if ( @rename( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}

		return false;
	}

	/**
	 * Archive (never delete) every heatmap event FILE of a page whose session
	 * token has no matching DB session row — the file/DB "orphan" reconciliation
	 * for the canonical-session-universe unification (2026-08-13).
	 *
	 * A file session is an ORPHAN when its filename session token matches no row
	 * in wp_optibehavior_sessions under the same 3-form suffix bridging the
	 * heatmap allow-list uses (raw / cleaned / last-8-chars). Those files inflate
	 * the file-derived surfaces (Visitor Type dropdown, Views strip) above the
	 * canonical pageview-session count, so they are moved to `_orphaned/{hash}/
	 * {folder}/` — the raw JSON survives (recoverable), the aggregators stop
	 * counting them. Callers must recompute aggregates (mark_pages_stale) and
	 * flush caches afterwards.
	 *
	 * The known-session lookup is resolved by the Free dashboard's orphan gate
	 * ({@see Opti_Behavior_Heatmap_Dashboard::get_heatmap_known_db_session_lookup_for_page_bridge()})
	 * and passed in. When that resolver could not restrict safely it returns
	 * boolean `true` (fail-safe) — this method then archives NOTHING for the page
	 * (every token treated as known), matching the live orphan-admission gate.
	 *
	 * @since 2026-08-13 (file/DB orphan reconciliation)
	 * @param int         $page_id      Page ID.
	 * @param array|true  $known_lookup 3-form lookup map (token/clean/last8 => true)
	 *                                  of DB-known sessions, or boolean true (fail-safe).
	 * @return array{archived:int,sessions:string[]}
	 */
	public function archive_orphan_file_sessions_for_page( $page_id, $known_lookup ) {
		$result = array( 'archived' => 0, 'sessions' => array() );

		// Fail-safe: resolver could not restrict safely — no orphan admission.
		if ( true === $known_lookup || ! is_array( $known_lookup ) ) {
			return $result;
		}

		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			return $result;
		}

		$sessions = array();
		foreach ( $this->get_url_hashes_for_page( $page_id ) as $url_hash ) {
			$clean_hash = preg_replace( '/[^a-f0-9]/i', '', (string) $url_hash );
			if ( '' === $clean_hash ) {
				continue;
			}
			foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder ) {
				$dir = $this->base_dir . $clean_hash . '/' . $folder;
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
					$parts = explode( '_', str_replace( '.json', '', basename( $file ) ) );
					if ( count( $parts ) < 6 ) {
						continue;
					}
					$token = (string) end( $parts );
					if ( '' === $token ) {
						continue;
					}
					$clean = preg_replace( '/[^a-zA-Z0-9]/', '', $token );
					if ( isset( $known_lookup[ $token ] )
						|| isset( $known_lookup[ $clean ] )
						|| isset( $known_lookup[ substr( $clean, -8 ) ] ) ) {
						continue; // Known DB session — keep the file.
					}
					if ( $this->archive_orphan_heatmap_file( $clean_hash, $folder, $file ) ) {
						++$result['archived'];
						$sessions[ $token ] = true;
					}
				}
			}
		}

		$result['sessions'] = array_keys( $sessions );
		return $result;
	}

	/**
	 * Move a single orphan heatmap event file under `_orphaned/{hash}/{folder}/`
	 * (never hard-deleted). Companion to {@see archive_orphan_file_sessions_for_page()}.
	 *
	 * @since 2026-08-13 (file/DB orphan reconciliation)
	 * @param string $url_hash  Cleaned (hex) url_hash the file lives under.
	 * @param string $folder    Event folder (clicks|moves|scrolls).
	 * @param string $file_path Absolute path of the file to archive.
	 * @return bool True when the file was moved.
	 */
	private function archive_orphan_heatmap_file( $url_hash, $folder, $file_path ) {
		if ( ! is_file( $file_path ) ) {
			return false;
		}

		$folder = preg_replace( '/[^a-z]/', '', (string) $folder );
		if ( '' === $folder ) {
			return false;
		}

		$archive_root = $this->base_dir . '_orphaned/';
		if ( ! is_dir( $archive_root ) ) {
			wp_mkdir_p( $archive_root );
			$this->create_htaccess( $archive_root );
			$this->create_index_file( $archive_root );
		}

		$dest_dir = $archive_root . $url_hash . '/' . $folder . '/';
		if ( ! is_dir( $dest_dir ) ) {
			wp_mkdir_p( $dest_dir );
		}

		$dest = $dest_dir . basename( $file_path );
		if ( file_exists( $dest ) ) {
			// Preserve an earlier archive of the same name.
			$dest = $dest_dir . gmdate( 'YmdHis' ) . '-' . basename( $file_path );
		}

		// @rename is atomic on the same filesystem; suppress the warning.
		if ( @rename( $file_path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}

		return false;
	}

	/**
	 * Public archive entry point for cleanup passes: move a single heatmap
	 * event JSON file under `_orphaned/{hash}/{folder}/` instead of deleting
	 * it (Option B guardrail — cleanup passes never hard-delete raw heatmap
	 * files; permanent deletion happens only via the Archived Heatmap Data
	 * Retention purge of `_orphaned/`).
	 *
	 * The file must live inside this store's base directory
	 * (`uploads/opti-behavior-data/`) — paths outside it are refused.
	 *
	 * @since 2026-08-16 (mass-deletion incident guardrails)
	 * @param string $url_hash  url_hash directory the file belongs to.
	 * @param string $folder    Event folder (clicks|moves|scrolls).
	 * @param string $file_path Absolute path of the live file to archive.
	 * @return bool True when the file was moved into the archive.
	 */
	public function archive_heatmap_session_file( $url_hash, $folder, $file_path ) {
		$clean_hash = preg_replace( '/[^a-f0-9]/i', '', (string) $url_hash );
		if ( '' === $clean_hash ) {
			return false;
		}

		$file_path = (string) $file_path;
		$real      = realpath( $file_path );
		if ( false === $real || ! is_file( $real ) ) {
			return false;
		}

		// Containment guard: only files inside the opti-behavior-data store.
		$base_real = realpath( $this->base_dir );
		$base_norm = wp_normalize_path( false !== $base_real ? $base_real : $this->base_dir );
		$base_norm = rtrim( $base_norm, '/\\' ) . '/';
		$real_norm = wp_normalize_path( $real );
		if ( 0 !== strpos( $real_norm, $base_norm ) ) {
			return false;
		}

		return $this->archive_orphan_heatmap_file( $clean_hash, $folder, $real );
	}

	/**
	 * Option holding the map of heatmap pages whose raw files were age-expired
	 * by the heavy-files retention tier: page_id => gmdate('Y-m-d H:i:s').
	 * The Heatmaps UI reads this to show a "files archived" badge instead of a
	 * broken/empty render explanation.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 */
	const FILES_EXPIRED_PAGES_OPTION = 'opti_behavior_heatmap_files_expired_pages';

	/**
	 * Heavy-files retention tier (Tiered Retention, 2026-08-15 second round):
	 * ARCHIVE (never hard-delete — Option B guardrail) every heatmap event
	 * JSON file older than the cutoff to `_orphaned/{hash}/{folder}/`.
	 *
	 * The file age comes from the leading unix-timestamp token in the
	 * filename (capture time); files without a parsable timestamp fall back
	 * to filemtime. Bounded: at most $max_files moves per call — leftovers
	 * are picked up by the next daily tick. Affected mapping rows are marked
	 * stale (counts re-derive from the files that remain) and flagged in
	 * {@see self::FILES_EXPIRED_PAGES_OPTION} so the Heatmaps list degrades
	 * gracefully ("files archived" badge) instead of silently losing data.
	 * Permanent deletion happens ONLY later via the existing Archived Heatmap
	 * Data Retention purge of `_orphaned/`.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @param int $cutoff_ts Unix timestamp; files strictly older are archived.
	 * @param int $max_files Per-run bound on file moves.
	 * @return array{archived:int,page_ids:int[],hashes:string[],capped:bool}
	 */
	public function archive_heatmap_files_older_than( $cutoff_ts, $max_files = 2000 ) {
		global $wpdb;

		$result = array(
			'archived' => 0,
			'page_ids' => array(),
			'hashes'   => array(),
			'capped'   => false,
		);

		$cutoff_ts = (int) $cutoff_ts;
		$max_files = max( 1, (int) $max_files );

		if ( $cutoff_ts <= 0 || ! is_dir( $this->base_dir ) ) {
			return $result;
		}

		$affected_hashes = array();

		foreach ( (array) scandir( $this->base_dir ) as $entry ) {
			if ( $result['archived'] >= $max_files ) {
				$result['capped'] = true;
				break;
			}

			// Only hex url_hash data dirs — skips '.', '..', '_orphaned',
			// 'recordings', protection files and strays.
			if ( ! preg_match( '/^[a-f0-9]{8,64}$/i', (string) $entry ) ) {
				continue;
			}

			$hash_dir = $this->base_dir . $entry . '/';
			if ( ! is_dir( $hash_dir ) ) {
				continue;
			}

			foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder ) {
				if ( $result['archived'] >= $max_files ) {
					$result['capped'] = true;
					break;
				}

				$dir = $hash_dir . $folder;
				if ( ! is_dir( $dir ) ) {
					continue;
				}

				foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
					if ( $result['archived'] >= $max_files ) {
						$result['capped'] = true;
						break;
					}

					$parts   = explode( '_', str_replace( '.json', '', basename( $file ) ) );
					$file_ts = isset( $parts[0] ) && ctype_digit( (string) $parts[0] ) ? (int) $parts[0] : 0;
					if ( $file_ts <= 0 ) {
						$file_ts = (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}

					if ( $file_ts <= 0 || $file_ts >= $cutoff_ts ) {
						continue; // Unknown age or still inside the window — keep.
					}

					if ( $this->archive_orphan_heatmap_file( $entry, $folder, $file ) ) {
						++$result['archived'];
						$affected_hashes[ strtolower( $entry ) ] = true;
					}
				}
			}
		}

		if ( empty( $affected_hashes ) ) {
			return $result;
		}

		$result['hashes'] = array_keys( $affected_hashes );

		// Resolve affected page ids from the mapping table.
		$pages_table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence probe for fixed plugin table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pages_table ) ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $result['hashes'] ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; values prepared.
			$page_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT page_id FROM {$pages_table} WHERE LOWER(url_hash) IN ({$placeholders})",
					...$result['hashes']
				)
			);
			$result['page_ids'] = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		}

		// Flag the pages for the graceful "files archived" badge.
		if ( ! empty( $result['page_ids'] ) ) {
			$flags = get_option( self::FILES_EXPIRED_PAGES_OPTION, array() );
			$flags = is_array( $flags ) ? $flags : array();
			$now   = gmdate( 'Y-m-d H:i:s' );
			foreach ( $result['page_ids'] as $page_id ) {
				$flags[ $page_id ] = $now;
			}
			update_option( self::FILES_EXPIRED_PAGES_OPTION, $flags, false );
		}

		// Mark affected pages stale (counts re-derive from remaining files)
		// and flush the dashboard caches.
		$this->note_heatmap_files_deleted( $result['archived'], $result['page_ids'] );
		if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
			opti_behavior_flush_heatmap_caches();
		}

		return $result;
	}

	/**
	 * Read the "files archived by retention" page flag map.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @return array<int,string> page_id => gmdate flagged at.
	 */
	public function get_files_expired_page_flags() {
		$flags = get_option( self::FILES_EXPIRED_PAGES_OPTION, array() );

		return is_array( $flags ) ? $flags : array();
	}

	/**
	 * Purge STALE single-row QA heatmap mappings across the whole table.
	 *
	 * The dedupe pass ({@see dedupe_page_mappings()}) only touches page_ids with
	 * MORE than one mapping row, so a page whose ONLY mapping row is a stale QA
	 * fixture URL (e.g. `.../ob-pro-qa-YYYYmmddHHMM-entry/`,
	 * `.../ob-dash-qa-.../#top`) that no longer resolves to the page's current
	 * canonical/variant identity is never cleaned up — its stale sessions keep
	 * inflating the multi-variant detail pill (homepage group summed the QA rows
	 * of the A/B-variant page_ids). This sweeps those single-row stragglers.
	 *
	 * Conservative detection — a row is purged ONLY when BOTH hold:
	 *  1. Its `url` matches a QA fixture slug (`ob-pro-qa-` / `ob-dash-qa-`), and
	 *  2. Its `url_hash` does NOT equal the hash of the owning page's CURRENT
	 *     canonical URL (so a live A/B-variant mapping — whose url_hash equals the
	 *     page's canonical hash, query params are stripped by get_url_hash() — is
	 *     never touched).
	 * Non-QA url-change cases are left alone (avoid archiving legitimate renamed
	 * pages whose only copy of the data lives under the old hash).
	 *
	 * Non-destructive, same as the dedupe pass: the on-disk hash dir is MOVED to
	 * `_orphaned/` (not deleted), and only when no OTHER surviving mapping row
	 * (any page_id) still references that url_hash, so a shared dir is never
	 * pulled out from under a live row.
	 *
	 * @since 2026-08-13 (stale-QA single-row purge)
	 * @return array{removed:int,affected:int[],orphaned_hashes:string[]}
	 */
	public function purge_stale_qa_page_mappings() {
		global $wpdb;

		$result = array( 'removed' => 0, 'affected' => array(), 'orphaned_hashes' => array() );

		$table_name = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$pages_table = $wpdb->prefix . 'optibehavior_pages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers from $wpdb->prefix; analytics maintenance query.
		$rows = $wpdb->get_results(
			"SELECT h.id, h.page_id, h.url_hash, h.url, p.url AS canonical_url
			FROM {$table_name} h
			LEFT JOIN {$pages_table} p ON p.id = h.page_id"
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return $result;
		}

		// url_hash usage count across the WHOLE table (for the shared-dir guard).
		$hash_refs = array();
		foreach ( $rows as $r ) {
			$h = (string) $r->url_hash;
			$hash_refs[ $h ] = isset( $hash_refs[ $h ] ) ? $hash_refs[ $h ] + 1 : 1;
		}

		$qa_pattern = '/ob-(?:pro|dash)-qa-/i';

		foreach ( $rows as $r ) {
			// (1) QA fixture slug only.
			if ( ! preg_match( $qa_pattern, (string) $r->url ) ) {
				continue;
			}
			// Owning page must resolve to a canonical URL we can compare against.
			if ( empty( $r->canonical_url ) ) {
				continue;
			}
			// (2) Live mapping (matches the page's current canonical hash) is safe.
			$canonical_hash = $this->get_url_hash( (string) $r->canonical_url );
			if ( '' === $canonical_hash || (string) $r->url_hash === $canonical_hash ) {
				continue;
			}

			// Archive the on-disk dir only when this is the last row referencing
			// the hash (never orphan a dir a surviving row still points at).
			if ( isset( $hash_refs[ (string) $r->url_hash ] ) && (int) $hash_refs[ (string) $r->url_hash ] <= 1 ) {
				$this->archive_orphaned_hash_dir( (string) $r->url_hash );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing a stale QA mapping row.
			$deleted = $wpdb->delete( $table_name, array( 'id' => (int) $r->id ), array( '%d' ) );
			if ( false !== $deleted && $deleted > 0 ) {
				++$result['removed'];
				$result['affected'][]        = (int) $r->page_id;
				$result['orphaned_hashes'][] = (string) $r->url_hash;
				--$hash_refs[ (string) $r->url_hash ];

				// Drop the per-page url-hash caches so readers see the collapse.
				wp_cache_delete( 'opti_url_hash_' . (int) $r->page_id, 'opti-behavior' );
				wp_cache_delete( 'opti_url_hashes_' . (int) $r->page_id, 'opti-behavior' );
			}
		}

		$result['affected'] = array_values( array_unique( $result['affected'] ) );

		if ( $result['removed'] > 0 ) {
			$this->log(
				sprintf(
					'Purged %d stale QA heatmap mapping row(s)/dir(s) across %d page(s)',
					$result['removed'],
					count( $result['affected'] )
				),
				'info'
			);
		}

		return $result;
	}

	/**
	 * Whether the Phase 3 aggregate columns exist on the mapping table.
	 *
	 * Cached per request. When the migration has not run, ingest skips the
	 * stale-marking (no column to write) and the dashboard fast path stays off,
	 * so behaviour is unchanged on an un-migrated install.
	 *
	 * @since 1.6.7
	 * @return bool
	 */
	private function heatmap_agg_columns_exist() {
		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema capability probe; cached per request.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				DB_NAME,
				$table,
				'agg_synced_at'
			)
		);

		$exists = ( $count > 0 );
		return $exists;
	}

	/**
	 * Add event to buffer for batch saving
	 *
	 * @param string $url_hash   URL hash.
	 * @param string $type       Data type.
	 * @param string $session_id Session ID.
	 * @param array  $event      Single event data.
	 * @param array  $metadata   Session metadata.
	 */
	public function buffer_event( $url_hash, $type, $session_id, $event, $metadata ) {
		$buffer_key = $url_hash . '_' . $type . '_' . $session_id;

		if ( ! isset( $this->event_buffer[ $buffer_key ] ) ) {
			$this->event_buffer[ $buffer_key ] = array(
				'url_hash'   => $url_hash,
				'type'       => $type,
				'session_id' => $session_id,
				'metadata'   => $metadata,
				'events'     => array(),
			);
		}

		$this->event_buffer[ $buffer_key ]['events'][] = $event;

		// Flush if threshold reached
		if ( count( $this->event_buffer[ $buffer_key ]['events'] ) >= $this->buffer_threshold ) {
			$this->flush_buffer( $buffer_key );
		}
	}

	/**
	 * Flush a specific buffer
	 *
	 * @param string $buffer_key Buffer key.
	 */
	public function flush_buffer( $buffer_key ) {
		if ( ! isset( $this->event_buffer[ $buffer_key ] ) ) {
			return;
		}

		$buffer = $this->event_buffer[ $buffer_key ];

		if ( ! empty( $buffer['events'] ) ) {
			$this->save_heatmap_data(
				$buffer['url_hash'],
				$buffer['type'],
				$buffer['session_id'],
				$buffer['events'],
				$buffer['metadata']
			);
		}

		unset( $this->event_buffer[ $buffer_key ] );
	}

	/**
	 * Flush all buffers (called on shutdown)
	 */
	public function flush_all_buffers() {
		foreach ( array_keys( $this->event_buffer ) as $buffer_key ) {
			$this->flush_buffer( $buffer_key );
		}
	}

	/**
	 * Check if timestamp is within date range
	 *
	 * @param int    $timestamp  Unix timestamp.
	 * @param string $date_range Date range string (today, 7days, 30days, 90days, custom).
	 * @param string $start_date Optional start date for custom range.
	 * @param string $end_date   Optional end date for custom range.
	 * @return bool True if in range.
	 */
	private function is_in_date_range( $timestamp, $date_range, $start_date = '', $end_date = '' ) {
		$now = time();

		switch ( $date_range ) {
			case 'today':
				$start = strtotime( 'today midnight' );
				$end = $now;
				break;
			case 'yesterday':
				$start = strtotime( 'yesterday midnight' );
				$end = strtotime( 'today midnight' ) - 1;
				break;
			case '7days':
			case 'last7days':
				$start = strtotime( '-7 days midnight' );
				$end = $now;
				break;
			case '30days':
			case 'last30days':
				$start = strtotime( '-30 days midnight' );
				$end = $now;
				break;
			case '90days':
				$start = strtotime( '-90 days midnight' );
				$end = $now;
				break;
			case 'custom':
				$start = $start_date ? strtotime( $start_date ) : 0;
				$end = $end_date ? strtotime( $end_date . ' 23:59:59' ) : $now;
				break;
			default:
				// No filter - include all
				return true;
		}

		return $timestamp >= $start && $timestamp <= $end;
	}

	/**
	 * Read heatmap files with filtering
	 *
	 * Supports both single values and arrays for country and browser filters.
	 * When arrays are provided, files matching ANY of the values are included.
	 *
	 * @param string $url_hash URL hash.
	 * @param string $type     Data type (clicks, moves, scrolls).
	 * @param array  $filters  Optional filters (device, country, browser, date_range, visitor_type, batch, batch_size).
	 *                         - country: string or array of country codes (e.g., 'US' or ['US', 'FR', 'DE'])
	 *                         - browser: string or array of browser names (e.g., 'Chrome' or ['Chrome', 'Firefox'])
	 *                         - visitor_type: 'logged_in' or 'guest' to filter by login status
	 * @return array Associative array with 'files' (array of file data) and 'total_files' (int total matching count).
	 */
	public function read_heatmap_files( $url_hash, $type, $filters = array() ) {
		$dir = $this->get_heatmap_dir( $url_hash, $type );

		$empty_result = array(
			'files'       => array(),
			'total_files' => 0,
		);

		if ( ! is_dir( $dir ) ) {
			return $empty_result;
		}

		// Build a cache key from filters excluding batch/batch_size (those just slice the same list)
		$filter_for_cache = $filters;
		unset( $filter_for_cache['batch'], $filter_for_cache['batch_size'] );
		$list_cache_key = 'opti_hm_list_' . md5( $url_hash . $type . wp_json_encode( $filter_for_cache ) );

		// Try to get the sorted file path list from transient cache (avoids re-scanning directory for every batch)
		$sorted_paths = get_transient( $list_cache_key );

		if ( false === $sorted_paths ) {
			// Cache miss — scan directory, filter, and sort
			$files = scandir( $dir );
			$matching_files = array();

			// Normalize multi-select filters to arrays
			$country_filter = $this->normalize_filter_to_array( isset( $filters['country'] ) ? $filters['country'] : '' );
			$browser_filter = $this->normalize_filter_to_array( isset( $filters['browser'] ) ? $filters['browser'] : '' );
			$os_filter      = $this->normalize_filter_to_array( isset( $filters['os'] ) ? $filters['os'] : '' );
			$utm_campaign_filter = $this->normalize_filter_to_array( isset( $filters['utm_campaign'] ) ? $filters['utm_campaign'] : '' );
			$utm_source_filter   = $this->normalize_filter_to_array( isset( $filters['utm_source'] ) ? $filters['utm_source'] : '' );
			$utm_medium_filter   = $this->normalize_filter_to_array( isset( $filters['utm_medium'] ) ? $filters['utm_medium'] : '' );
			$duration_min = isset( $filters['duration_min'] ) && '' !== $filters['duration_min'] ? max( 0, (int) $filters['duration_min'] ) : null;
			$duration_max = isset( $filters['duration_max'] ) && '' !== $filters['duration_max'] ? max( 0, (int) $filters['duration_max'] ) : null;

			// A/B variant filtering: direct match on ab_test_id and variant_id from filename metadata.
			$opti_ab_filter_test_id    = isset( $filters['ab_test_id'] ) ? (int) $filters['ab_test_id'] : 0;
			$opti_ab_filter_variant_id = isset( $filters['variant_id'] ) ? (int) $filters['variant_id'] : 0;

			// Pre-compute short session ID suffixes when a caller supplies an allow-list.
			// This is used for legacy A/B filtering and for global spam exclusion.
			$short_session_ids = null;
			if ( ! empty( $filters['session_ids'] ) && is_array( $filters['session_ids'] ) ) {
				$short_session_ids = array();
				foreach ( $filters['session_ids'] as $opti_behavior_sid ) {
					$opti_behavior_sid_clean = preg_replace( '/[^a-zA-Z0-9]/', '', $opti_behavior_sid );
					$short_session_ids[]     = substr( $opti_behavior_sid_clean, -8 );
				}
			}

			foreach ( $files as $file ) {
				if ( $file === '.' || $file === '..' || $file === 'index.php' ) {
					continue;
				}

				// Parse metadata from filename (NO file read needed!)
				$meta = $this->parse_filename_metadata( $file );
				if ( ! $meta ) {
					continue;
				}

				// Apply filters based on filename metadata
				// Normalize device type before comparison (unknown devices count as desktop)
				if ( ! empty( $filters['device'] ) && $this->normalize_device( $meta['device'] ) !== strtolower( $filters['device'] ) ) {
					continue;
				}

				// Country filter: match if ANY selected country matches (multi-select support)
				if ( ! empty( $country_filter ) ) {
					$file_country = strtoupper( $meta['country'] );
					$country_match = false;
					foreach ( $country_filter as $selected_country ) {
						if ( strtoupper( $selected_country ) === $file_country ) {
							$country_match = true;
							break;
						}
					}
					if ( ! $country_match ) {
						continue;
					}
				}

				// Browser filter: match if ANY selected browser matches (multi-select support)
				if ( ! empty( $browser_filter ) ) {
					$file_browser = $meta['browser'];
					$browser_match = false;
					foreach ( $browser_filter as $selected_browser ) {
						if ( $this->normalize_browser( $selected_browser ) === $file_browser ) {
							$browser_match = true;
							break;
						}
					}
					if ( ! $browser_match ) {
						continue;
					}
				}

				// Visitor type filter: 'logged_in' or 'guest'
				if ( ! empty( $filters['visitor_type'] ) ) {
					$want_logged_in = ( $filters['visitor_type'] === 'logged_in' );
					$file_logged_in = isset( $meta['is_logged_in'] ) ? $meta['is_logged_in'] : false;
					if ( $want_logged_in !== $file_logged_in ) {
						continue;
					}
				}

				// OS filter: token match OR DB session-suffix fallback (legacy files).
				$opti_file_session = isset( $meta['session_id'] ) ? $meta['session_id'] : '';
				if ( ! $this->advanced_dimension_passes( $filters, 'os', $os_filter, isset( $meta['os'] ) ? $meta['os'] : '', $opti_file_session ) ) {
					continue;
				}

				// UTM filters: token match OR DB session-suffix fallback (legacy files).
				if ( ! $this->advanced_dimension_passes( $filters, 'utm_campaign', $utm_campaign_filter, isset( $meta['utm_campaign'] ) ? $meta['utm_campaign'] : '', $opti_file_session ) ) {
					continue;
				}
				if ( ! $this->advanced_dimension_passes( $filters, 'utm_source', $utm_source_filter, isset( $meta['utm_source'] ) ? $meta['utm_source'] : '', $opti_file_session ) ) {
					continue;
				}
				if ( ! $this->advanced_dimension_passes( $filters, 'utm_medium', $utm_medium_filter, isset( $meta['utm_medium'] ) ? $meta['utm_medium'] : '', $opti_file_session ) ) {
					continue;
				}

				// Session duration bounds (seconds), parsed from filename metadata
				if ( null !== $duration_min || null !== $duration_max ) {
					$file_duration = isset( $meta['duration'] ) ? (int) $meta['duration'] : 0;
					if ( null !== $duration_min && $file_duration < $duration_min ) {
						continue;
					}
					if ( null !== $duration_max && $file_duration > $duration_max ) {
						continue;
					}
				}

				// A/B variant filter: direct match on ab_test_id and variant_id from filename.
				// Files tagged with variant info (new format) are matched directly.
				// Old files without variant tags (ab_test_id=0) are excluded when filtering by variant.
				if ( $opti_ab_filter_test_id > 0 && $opti_ab_filter_variant_id > 0 ) {
					$opti_ab_file_test_id    = isset( $meta['ab_test_id'] ) ? (int) $meta['ab_test_id'] : 0;
					$opti_ab_file_variant_id = isset( $meta['variant_id'] ) ? (int) $meta['variant_id'] : 0;
					if ( $opti_ab_file_test_id !== $opti_ab_filter_test_id || $opti_ab_file_variant_id !== $opti_ab_filter_variant_id ) {
						continue;
					}
				}

				// Session allow-list filter: include only files whose session_id suffix
				// matches one of the allowed session IDs (pre-computed as short suffixes).
				if ( null !== $short_session_ids ) {
					$file_session_id = isset( $meta['session_id'] ) ? $meta['session_id'] : '';
					if ( ! in_array( $file_session_id, $short_session_ids, true ) ) {
						continue;
					}
				}

				if ( ! empty( $filters['date_range'] ) ) {
					$start_date = isset( $filters['start_date'] ) ? $filters['start_date'] : '';
					$end_date = isset( $filters['end_date'] ) ? $filters['end_date'] : '';
					if ( ! $this->is_in_date_range( $meta['timestamp'], $filters['date_range'], $start_date, $end_date ) ) {
						continue;
					}
				}

				// Store file path with timestamp for sorting
				$matching_files[] = array(
					'path'      => $dir . $file,
					'timestamp' => $meta['timestamp'],
				);
			}

			// Sort by timestamp descending (newest files first)
			// This ensures most recent data is loaded in the first batches
			usort( $matching_files, function( $a, $b ) {
				return $b['timestamp'] - $a['timestamp']; // Descending order
			});

			// Extract just the file paths after sorting
			$sorted_paths = array_column( $matching_files, 'path' );

			// Cache the sorted list for 60 seconds — all subsequent batch requests reuse it
			set_transient( $list_cache_key, $sorted_paths, 60 );
		}

		$total_files = count( $sorted_paths );

		// Apply batching if requested
		$batch = isset( $filters['batch'] ) ? (int) $filters['batch'] : 0;
		$batch_size = isset( $filters['batch_size'] ) ? (int) $filters['batch_size'] : 100;

		// Read matching files (with optional batching)
		$files_data = $this->read_files_batch( $sorted_paths, $batch, $batch_size );

		return array(
			'files'       => $files_data,
			'total_files' => $total_files,
		);
	}

	/**
	 * Normalize a filter value to an array
	 *
	 * Handles string, array, or comma-separated values.
	 *
	 * @param mixed $value Filter value (string, array, or comma-separated string).
	 * @return array Normalized array of filter values (empty array if no filter).
	 */
	private function normalize_filter_to_array( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		// Already an array
		if ( is_array( $value ) ) {
			return array_filter( array_map( 'trim', $value ) );
		}

		// Comma-separated string
		if ( strpos( $value, ',' ) !== false ) {
			return array_filter( array_map( 'trim', explode( ',', $value ) ) );
		}

		// Single value
		return array( trim( $value ) );
	}

	/**
	 * Get total count of matching files (for pagination)
	 *
	 * Supports both single values and arrays for country and browser filters.
	 *
	 * @param string $url_hash URL hash.
	 * @param string $type     Data type (clicks, moves, scrolls).
	 * @param array  $filters  Optional filters (device, country, browser, date_range).
	 *                         - country: string or array of country codes
	 *                         - browser: string or array of browser names
	 * @return int Total number of matching files.
	 */
	public function get_heatmap_file_count( $url_hash, $type, $filters = array() ) {
		$dir = $this->get_heatmap_dir( $url_hash, $type );

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$files = scandir( $dir );
		$count = 0;

		// Normalize multi-select filters to arrays
		$country_filter = $this->normalize_filter_to_array( isset( $filters['country'] ) ? $filters['country'] : '' );
		$browser_filter = $this->normalize_filter_to_array( isset( $filters['browser'] ) ? $filters['browser'] : '' );
		$os_filter      = $this->normalize_filter_to_array( isset( $filters['os'] ) ? $filters['os'] : '' );
		$utm_campaign_filter = $this->normalize_filter_to_array( isset( $filters['utm_campaign'] ) ? $filters['utm_campaign'] : '' );
		$utm_source_filter   = $this->normalize_filter_to_array( isset( $filters['utm_source'] ) ? $filters['utm_source'] : '' );
		$utm_medium_filter   = $this->normalize_filter_to_array( isset( $filters['utm_medium'] ) ? $filters['utm_medium'] : '' );
		$duration_min = isset( $filters['duration_min'] ) && '' !== $filters['duration_min'] ? max( 0, (int) $filters['duration_min'] ) : null;
		$duration_max = isset( $filters['duration_max'] ) && '' !== $filters['duration_max'] ? max( 0, (int) $filters['duration_max'] ) : null;

		foreach ( $files as $file ) {
			if ( $file === '.' || $file === '..' || $file === 'index.php' ) {
				continue;
			}

			// Parse metadata from filename (NO file read needed!)
			$meta = $this->parse_filename_metadata( $file );
			if ( ! $meta ) {
				continue;
			}

			// Apply filters based on filename metadata
			// Normalize device type before comparison (unknown devices count as desktop)
			if ( ! empty( $filters['device'] ) && $this->normalize_device( $meta['device'] ) !== strtolower( $filters['device'] ) ) {
				continue;
			}

			// Country filter: match if ANY selected country matches (multi-select support)
			if ( ! empty( $country_filter ) ) {
				$file_country = strtoupper( $meta['country'] );
				$country_match = false;
				foreach ( $country_filter as $selected_country ) {
					if ( strtoupper( $selected_country ) === $file_country ) {
						$country_match = true;
						break;
					}
				}
				if ( ! $country_match ) {
					continue;
				}
			}

			// Browser filter: match if ANY selected browser matches (multi-select support)
			if ( ! empty( $browser_filter ) ) {
				$file_browser = $meta['browser'];
				$browser_match = false;
				foreach ( $browser_filter as $selected_browser ) {
					if ( $this->normalize_browser( $selected_browser ) === $file_browser ) {
						$browser_match = true;
						break;
					}
				}
				if ( ! $browser_match ) {
					continue;
				}
			}

			// Visitor type filter: 'logged_in' or 'guest'
			if ( ! empty( $filters['visitor_type'] ) ) {
				$want_logged_in = ( $filters['visitor_type'] === 'logged_in' );
				$file_logged_in = isset( $meta['is_logged_in'] ) ? $meta['is_logged_in'] : false;
				if ( $want_logged_in !== $file_logged_in ) {
					continue;
				}
			}

			// OS filter: token match OR DB session-suffix fallback (legacy files).
			$opti_file_session = isset( $meta['session_id'] ) ? $meta['session_id'] : '';
			if ( ! $this->advanced_dimension_passes( $filters, 'os', $os_filter, isset( $meta['os'] ) ? $meta['os'] : '', $opti_file_session ) ) {
				continue;
			}

			// UTM filters: token match OR DB session-suffix fallback (legacy files).
			if ( ! $this->advanced_dimension_passes( $filters, 'utm_campaign', $utm_campaign_filter, isset( $meta['utm_campaign'] ) ? $meta['utm_campaign'] : '', $opti_file_session ) ) {
				continue;
			}
			if ( ! $this->advanced_dimension_passes( $filters, 'utm_source', $utm_source_filter, isset( $meta['utm_source'] ) ? $meta['utm_source'] : '', $opti_file_session ) ) {
				continue;
			}
			if ( ! $this->advanced_dimension_passes( $filters, 'utm_medium', $utm_medium_filter, isset( $meta['utm_medium'] ) ? $meta['utm_medium'] : '', $opti_file_session ) ) {
				continue;
			}

			// Session duration bounds (seconds)
			if ( null !== $duration_min || null !== $duration_max ) {
				$file_duration = isset( $meta['duration'] ) ? (int) $meta['duration'] : 0;
				if ( null !== $duration_min && $file_duration < $duration_min ) {
					continue;
				}
				if ( null !== $duration_max && $file_duration > $duration_max ) {
					continue;
				}
			}

			if ( ! empty( $filters['date_range'] ) ) {
				$start_date = isset( $filters['start_date'] ) ? $filters['start_date'] : '';
				$end_date = isset( $filters['end_date'] ) ? $filters['end_date'] : '';
				if ( ! $this->is_in_date_range( $meta['timestamp'], $filters['date_range'], $start_date, $end_date ) ) {
					continue;
				}
			}

			$count++;
		}

		return $count;
	}

	/**
	 * Scan filenames to extract unique filter options
	 *
	 * This method is optimized for speed - it only reads filenames, not file contents.
	 * Used to quickly populate filter dropdowns while heatmap data loads in background.
	 * Supports interdependent filtering: when country_filter is set, only show browsers used by visitors
	 * from those countries. When browser_filter is set, only show countries where those browsers were used.
	 *
	 * @param string $url_hash URL hash.
	 * @param string $type     Data type (clicks, moves, scrolls).
	 * @param array  $filters  Optional filters: date_range, device, country_filter (for filtering browsers), browser_filter (for filtering countries).
	 * @return array Array with 'countries' and 'browsers' arrays.
	 */
	public function scan_filter_options( $url_hash, $type, $filters = array() ) {
		$dir = $this->get_heatmap_dir( $url_hash, $type );

		$result = array(
			'countries'     => array(),
			'browsers'      => array(),
			'devices'       => array(),
			'os'            => array(),
			'utm_campaigns' => array(),
			'utm_sources'   => array(),
			'utm_mediums'   => array(),
			'session_sets'  => array(
				'countries'     => array(),
				'browsers'      => array(),
				'devices'       => array(),
				'os'            => array(),
				'utm_campaigns' => array(),
				'utm_sources'   => array(),
				'utm_mediums'   => array(),
				'visitor_types' => array( 'guest' => array(), 'logged_in' => array() ),
			),
		);

		if ( ! is_dir( $dir ) ) {
			return $result;
		}

		$files = scandir( $dir );
		// Each dimension maps value => array( session_suffix => true ) so unique
		// sessions per option can be counted (and merged across URL hashes).
		$countries = array();
		$browsers = array();
		$devices = array();
		$os_list = array();
		$utm_campaigns = array();
		$utm_sources = array();
		$utm_mediums = array();
		$visitor_types = array(
			'guest'     => array(),
			'logged_in' => array(),
		);
		$file_index = 0;

		// Parse interdependent filter values (comma-separated strings to arrays)
		// country_filter: when set, only include browsers from files that match these countries
		// browser_filter: when set, only include countries from files that match these browsers
		// device: when set, only include countries/browsers from files that match this device
		$country_filter = array();
		$browser_filter = array();
		$device_filter = ! empty( $filters['device'] ) ? strtolower( $filters['device'] ) : '';
		if ( ! empty( $filters['country_filter'] ) ) {
			$country_filter = array_map( 'strtoupper', array_map( 'trim', explode( ',', $filters['country_filter'] ) ) );
			$country_filter = array_filter( $country_filter );
		}
		if ( ! empty( $filters['browser_filter'] ) ) {
			$browser_filter = array_map( 'strtolower', array_map( 'trim', explode( ',', $filters['browser_filter'] ) ) );
			$browser_filter = array_filter( $browser_filter );
		}

		foreach ( $files as $file ) {
			if ( $file === '.' || $file === '..' || $file === 'index.php' ) {
				continue;
			}

			// Parse metadata from filename (NO file read needed!)
			$meta = $this->parse_filename_metadata( $file );
			if ( ! $meta ) {
				continue;
			}

			// Apply date filter if specified
			if ( ! empty( $filters['date_range'] ) ) {
				$start_date = isset( $filters['start_date'] ) ? $filters['start_date'] : '';
				$end_date = isset( $filters['end_date'] ) ? $filters['end_date'] : '';
				if ( ! $this->is_in_date_range( $meta['timestamp'], $filters['date_range'], $start_date, $end_date ) ) {
					continue;
				}
			}

			// A/B variant scoping: keep only files recorded for the requested
			// variant so option counts never exceed the variant session total.
			// Legacy filenames carry no variant info (parsed as 0) and never match.
			if ( ! empty( $filters['ab_test_id'] ) && ! empty( $filters['variant_id'] ) ) {
				if ( (int) $meta['ab_test_id'] !== (int) $filters['ab_test_id'] || (int) $meta['variant_id'] !== (int) $filters['variant_id'] ) {
					continue;
				}
			}

			// Spam scoping: when the caller supplies the allow-list the hero
			// session count uses, drop files whose session suffix is not in it
			// (an empty list means every session is spam-flagged: drop all).
			if ( isset( $filters['allowed_session_lookup'] ) && is_array( $filters['allowed_session_lookup'] ) ) {
				$lookup_key = ! empty( $meta['session_id'] ) ? (string) $meta['session_id'] : '';
				if ( '' === $lookup_key || ! isset( $filters['allowed_session_lookup'][ $lookup_key ] ) ) {
					continue;
				}
			}

			$country_code = strtoupper( $meta['country'] );
			$browser_name = $meta['browser'];
			$browser_lower = strtolower( $browser_name );
			$device_type = strtolower( $meta['device'] );

			// Session suffix for unique-session counting. Fall back to a
			// per-file key when missing so the file still counts as one session.
			$file_index++;
			$session_key = ! empty( $meta['session_id'] ) ? $meta['session_id'] : 'file-' . $file_index;

			// Normalize device type (phone -> mobile)
			if ( $device_type === 'phone' ) {
				$device_type = 'mobile';
			}

			// Visitor-type gate (L|G filename marker): matches the hero session
			// scope. Visitor-type sets themselves are collected regardless so
			// the visitor select can always show both counts.
			$is_logged_in           = ! empty( $meta['is_logged_in'] );
			$matches_visitor_filter = true;
			if ( ! empty( $filters['visitor_type'] ) ) {
				if ( 'logged_in' === $filters['visitor_type'] ) {
					$matches_visitor_filter = $is_logged_in;
				} elseif ( in_array( $filters['visitor_type'], array( 'guest', 'visitor' ), true ) ) {
					$matches_visitor_filter = ! $is_logged_in;
				}
			}

			// Collect devices: no device filtering, but honor the visitor gate.
			if ( ! empty( $device_type ) && $matches_visitor_filter ) {
				$devices[ $device_type ][ $session_key ] = true;
			}

			// Apply device filter - only collect countries/browsers for the selected device
			if ( ! empty( $device_filter ) && $device_type !== $device_filter ) {
				continue;
			}

			// Interdependent filtering for dropdown options:
			// - Countries are filtered by browser_filter (show only countries where selected browsers were used)
			// - Browsers are filtered by country_filter (show only browsers used in selected countries)
			$matches_browser_filter = empty( $browser_filter ) || in_array( $browser_lower, $browser_filter, true );
			$matches_country_filter = empty( $country_filter ) || in_array( $country_code, $country_filter, true );

			// Collect countries: only from files that match the browser filter
			if ( $matches_visitor_filter && $matches_browser_filter && ! empty( $country_code ) && $country_code !== 'XX' ) {
				$countries[ $country_code ][ $session_key ] = true;
			}

			// Collect browsers: only from files that match the country filter
			if ( $matches_visitor_filter && $matches_country_filter && ! empty( $browser_name ) ) {
				$browsers[ $browser_name ][ $session_key ] = true;
			}

			// Collect OS and UTM tokens (extended-format files only; 'na'/'' skipped).
			// Applied after country/browser interdependence so lists stay consistent.
			if ( $matches_browser_filter && $matches_country_filter ) {
				$visitor_type_key = $is_logged_in ? 'logged_in' : 'guest';
				$visitor_types[ $visitor_type_key ][ $session_key ] = true;

				if ( ! $matches_visitor_filter ) {
					continue; // OS/UTM sets honor the visitor gate too.
				}

				if ( ! empty( $meta['os'] ) ) {
					$os_list[ $meta['os'] ][ $session_key ] = true;
				}
				if ( ! empty( $meta['utm_campaign'] ) ) {
					$utm_campaigns[ $meta['utm_campaign'] ][ $session_key ] = true;
				}
				if ( ! empty( $meta['utm_source'] ) ) {
					$utm_sources[ $meta['utm_source'] ][ $session_key ] = true;
				}
				if ( ! empty( $meta['utm_medium'] ) ) {
					$utm_mediums[ $meta['utm_medium'] ][ $session_key ] = true;
				}
			}
		}

		// Convert to sorted arrays
		$result['countries'] = array_keys( $countries );
		$result['browsers'] = array_keys( $browsers );
		$result['devices'] = array_keys( $devices );
		$result['os'] = array_keys( $os_list );
		$result['utm_campaigns'] = array_keys( $utm_campaigns );
		$result['utm_sources'] = array_keys( $utm_sources );
		$result['utm_mediums'] = array_keys( $utm_mediums );

		// Session sets per option value for unique-session counts (merged
		// across URL hashes by the caller before counting).
		$result['session_sets'] = array(
			'countries'     => $countries,
			'browsers'      => $browsers,
			'devices'       => $devices,
			'os'            => $os_list,
			'utm_campaigns' => $utm_campaigns,
			'utm_sources'   => $utm_sources,
			'utm_mediums'   => $utm_mediums,
			'visitor_types' => $visitor_types,
		);

		sort( $result['countries'] );
		sort( $result['browsers'] );
		sort( $result['devices'] );
		sort( $result['os'] );
		sort( $result['utm_campaigns'] );
		sort( $result['utm_sources'] );
		sort( $result['utm_mediums'] );

		return $result;
	}

	/**
	 * Maximum number of bytes a single heatmap session JSON file may have
	 * before the plugin refuses to read/decode it in full.
	 *
	 * Oversized files (formed before a write-time growth cap existed, or
	 * planted externally) would otherwise exhaust PHP memory the moment any
	 * code path calls file_get_contents() + json_decode() on them, taking the
	 * whole admin request down with a fatal.
	 *
	 * @return int Max readable size in bytes (always > 0).
	 */
	public static function max_file_read_bytes() {
		$default = 10 * MB_IN_BYTES;

		/**
		 * Filters the maximum heatmap session file size the plugin will read
		 * into memory. Files above the limit are skipped (never decoded).
		 *
		 * @param int $default Default limit in bytes (~10MB).
		 */
		$max = (int) apply_filters( 'opti_behavior_heatmap_max_file_read_bytes', $default );

		return $max > 0 ? $max : $default;
	}

	/**
	 * Shared size guard: check whether a heatmap file is too large to read.
	 *
	 * Used by every full-read path (dashboard legacy point counting, detail
	 * page batch reader) so no request-reachable code ever passes an unbounded
	 * file to file_get_contents(). Logs once per request (WP_DEBUG only) so an
	 * oversized dataset does not flood debug.log.
	 *
	 * @param string $filepath File path.
	 * @return bool True when the file exceeds the read limit and must be skipped.
	 */
	public static function file_exceeds_read_limit( $filepath ) {
		$size = @filesize( $filepath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with cleanup jobs deleting files; a warning here would flood debug.log.
		if ( false === $size || $size <= self::max_file_read_bytes() ) {
			return false;
		}

		static $logged = false;
		if ( ! $logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$logged = true;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is on, once per request.
			error_log(
				sprintf(
					'[Opti-Behavior Heatmap] Skipping oversized session file (%d bytes > %d limit): %s (further oversized files this request are skipped silently)',
					$size,
					self::max_file_read_bytes(),
					basename( $filepath )
				)
			);
		}

		return true;
	}

	/**
	 * Maximum number of interaction points a single per-session heatmap file
	 * may hold before the write path refuses to merge more data into it.
	 *
	 * Without this write-time growth cap a long-lived (or malicious/buggy)
	 * session keeps appending points on every tracker flush and the file can
	 * grow to hundreds of MB — which later fatals every reader. Capping at
	 * write time prevents oversized files from ever forming.
	 *
	 * @return int Max points per session file (always > 0).
	 */
	public static function max_points_per_session_file() {
		$default = 20000;

		/**
		 * Filters the maximum number of points a per-session heatmap file may
		 * accumulate. Merges that would exceed the cap are refused (the
		 * existing file is kept intact; the new batch is dropped).
		 *
		 * @param int $default Default cap (20,000 points ≈ well under the
		 *                     ~10MB max-read-bytes guard for typical points).
		 */
		$max = (int) apply_filters( 'opti_behavior_heatmap_max_points_per_session_file', $default );

		return $max > 0 ? $max : $default;
	}

	/**
	 * Autoloaded option holding the running estimate of the TOTAL number of
	 * heatmap session JSON files on disk (spec §P0 adaptive-mode heuristic).
	 *
	 * Incremented on new-file writes, decremented by deletion hooks, and
	 * corrected exactly by every daily-index cron pass. One O(1) option read
	 * per admin request decides live-scan vs indexed path.
	 */
	const TOTAL_FILES_ESTIMATE_OPTION = 'opti_behavior_heatmap_total_files_estimate';

	/**
	 * Atomically adjust the total-files estimate by a delta (spec §P0).
	 *
	 * Uses a single UPDATE on the options row (not read-modify-write via
	 * update_option()) so concurrent tracker flushes never lose increments.
	 * Failures are irrelevant to correctness: the cron indexer corrects the
	 * estimate exactly on every pass.
	 *
	 * @since 1.9.x
	 * @param int $delta Signed adjustment (clamped so the stored value never goes below 0).
	 * @return void
	 */
	public function adjust_total_files_estimate( $delta ) {
		global $wpdb;

		$delta = (int) $delta;
		if ( 0 === $delta ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter; option caches invalidated below.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = GREATEST(0, CAST(option_value AS SIGNED) + %d) WHERE option_name = %s",
				$delta,
				self::TOTAL_FILES_ESTIMATE_OPTION
			)
		);

		if ( ! $updated ) {
			// Option row missing (first write ever) — create it autoloaded.
			add_option( self::TOTAL_FILES_ESTIMATE_OPTION, (string) max( 0, $delta ), '', 'yes' );
		}

		// The raw UPDATE bypasses the option caches — drop them so the next
		// get_option() (adaptive-mode decision) sees the fresh value.
		wp_cache_delete( self::TOTAL_FILES_ESTIMATE_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Set the total-files estimate to an exact value (cron indexer correction).
	 *
	 * @since 1.9.x
	 * @param int $value Exact file count (>= 0).
	 * @return void
	 */
	public function set_total_files_estimate( $value ) {
		update_option( self::TOTAL_FILES_ESTIMATE_OPTION, (string) max( 0, (int) $value ), 'yes' );
	}

	/**
	 * Whether the per-day heatmap index table exists (spec §P1).
	 *
	 * Cached per request. When the migration has not run yet, the write-time
	 * upsert and the indexer no-op, so behaviour is unchanged on an
	 * un-migrated install.
	 *
	 * @since 1.9.x
	 * @return bool
	 */
	private function heatmap_daily_table_exists() {
		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema capability probe; cached per request.
		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'optibehavior_heatmap_daily' )
		);

		return $exists;
	}

	/**
	 * Whether the daily_* staleness columns exist on the mapping table.
	 *
	 * @since 1.9.x
	 * @return bool
	 */
	private function heatmap_daily_columns_exist() {
		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema capability probe; cached per request.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$wpdb->prefix . 'optibehavior_heatmap_pages',
				'daily_synced_at'
			)
		);

		$exists = ( $count > 0 );
		return $exists;
	}

	/**
	 * Beacon-path write-time upsert into the per-day heatmap index (spec §P1).
	 *
	 * Front-end guarantee (visitor experience is sacred): exactly ONE O(1)
	 * `INSERT … ON DUPLICATE KEY UPDATE`, with every delta computed from data
	 * already in hand (metadata / filename-derivable values). No glob or dir
	 * enumeration, no reads of other files, no table-wide queries. Failures are
	 * swallowed (debug-logged once per request): tracking must never fail or
	 * slow because of the index — any drift is healed by the cron indexer,
	 * which re-derives the page's rows because the same save marked it stale
	 * (daily_synced_at = NULL in update_url_hash_mapping()).
	 *
	 * Unique-session approximation: a guest session is counted at most once per
	 * (page, day, session) per request via a static memo. The typical tracker
	 * flush writes its clicks/moves/scrolls files in one request, so the memo
	 * makes the common case exact; a session whose additional event type first
	 * appears in a LATER request may be counted twice until the cron indexer
	 * re-derives the exact per-day uniques (minutes; accepted per spec §5b).
	 *
	 * @since 1.9.x
	 * @param int    $page_id     Page ID the file belongs to (0 = orphan; skipped — cron heals via staleness marker).
	 * @param int    $file_ts     Unix timestamp encoded in the filename (day bucket + last-event candidate).
	 * @param string $type        Event type: clicks|moves|scrolls.
	 * @param string $device      Raw device type from metadata.
	 * @param int    $point_delta Interaction points added by this save.
	 * @param int    $file_delta  1 when a new session file was created, 0 on merge.
	 * @param bool   $count_guest_session Whether this save may introduce a new unique guest session (new file + guest).
	 * @param string $session_id  Full session id (memo key source).
	 * @return void
	 */
	private function upsert_heatmap_daily_delta( $page_id, $file_ts, $type, $device, $point_delta, $file_delta, $count_guest_session, $session_id = '' ) {
		global $wpdb;

		$page_id = (int) $page_id;
		if ( $page_id <= 0 || ! $this->heatmap_daily_table_exists() ) {
			return;
		}

		$type_prefixes = array(
			'clicks'  => 'click',
			'moves'   => 'att',
			'scrolls' => 'break',
		);
		if ( ! isset( $type_prefixes[ $type ] ) ) {
			return;
		}

		$device_key = $this->normalize_device( (string) $device );
		// Point buckets fold tablet into mobile — identical to the dashboard's
		// live folder_map (batch_heatmap_file_metrics_by_page()).
		$point_col = $type_prefixes[ $type ] . ( 'desktop' === $device_key ? '_pc' : '_mobile' );
		$sess_col  = 'sessions_' . $device_key; // sessions_desktop|sessions_mobile|sessions_tablet.

		$file_ts = (int) $file_ts;
		if ( $file_ts <= 0 ) {
			$file_ts = time();
		}
		$day     = gmdate( 'Y-m-d', $file_ts );
		$last_ts = gmdate( 'Y-m-d H:i:s', $file_ts );

		$sess_delta = 0;
		if ( $count_guest_session && '' !== (string) $session_id ) {
			// Per-request dedupe: one session flush writes up to 3 event files.
			static $session_memo = array();
			$session_short = substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $session_id ), -8 );
			$memo_key      = $page_id . '|' . $day . '|' . $session_short;
			if ( ! isset( $session_memo[ $memo_key ] ) ) {
				$session_memo[ $memo_key ] = true;
				$sess_delta                = 1;
			}
		}

		$table = $wpdb->prefix . 'optibehavior_heatmap_daily';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers hard-coded/whitelisted above; values prepared. O(1) beacon upsert by design.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (page_id, day, {$point_col}, {$sess_col}, file_count, last_event_ts)
				VALUES (%d, %s, %d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE
					{$point_col} = {$point_col} + VALUES({$point_col}),
					{$sess_col} = {$sess_col} + VALUES({$sess_col}),
					file_count = file_count + VALUES(file_count),
					last_event_ts = IF(last_event_ts IS NULL OR VALUES(last_event_ts) > last_event_ts, VALUES(last_event_ts), last_event_ts)",
				$page_id,
				$day,
				max( 0, (int) $point_delta ),
				$sess_delta,
				max( 0, (int) $file_delta ),
				$last_ts
			)
		);

		if ( false === $result ) {
			static $upsert_fail_logged = false;
			if ( ! $upsert_fail_logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$upsert_fail_logged = true;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is on, once per request.
				error_log( '[Opti-Behavior Heatmap] Daily-index upsert failed (page ' . $page_id . ', day ' . $day . '): ' . $wpdb->last_error . ' — cron indexer will re-derive.' );
			}
		}
	}

	/**
	 * Read multiple files in batch
	 *
	 * @param array $file_paths Array of file paths.
	 * @param int   $batch      Batch number (0-indexed). 0 = all files (no batching).
	 * @param int   $batch_size Number of files per batch.
	 * @return array Array of file data with optional pagination metadata.
	 */
	private function read_files_batch( $file_paths, $batch = 0, $batch_size = 100 ) {
		$total_files = count( $file_paths );

		// If batch is specified (> 0), apply pagination
		if ( $batch > 0 && $batch_size > 0 ) {
			$offset = ( $batch - 1 ) * $batch_size;
			$file_paths = array_slice( $file_paths, $offset, $batch_size );
		} elseif ( $batch === 0 && $total_files > $batch_size ) {
			// First request with many files - return first batch
			$file_paths = array_slice( $file_paths, 0, $batch_size );
		}

		$results = array();

		foreach ( $file_paths as $filepath ) {
			// Skip files that no longer exist (e.g. cleaned up between the index
			// read and this batch read). Calling file_get_contents() on a missing
			// path emits a PHP warning ("No such file or directory") that floods
			// debug.log — guard with is_readable() first (also covers permission
			// races) instead of relying on the false return + '@' suppression.
			if ( ! is_readable( $filepath ) ) {
				continue;
			}

			// Size guard: skip oversized session files instead of fataling on
			// file_get_contents()/json_decode() memory exhaustion, so the
			// detail page still renders every remaining session.
			if ( self::file_exceeds_read_limit( $filepath ) ) {
				continue;
			}

			$content = file_get_contents( $filepath );
			if ( false === $content ) {
				continue;
			}

			$data = json_decode( $content, true );
			if ( $data && isset( $data['data'] ) ) {
				$results[] = $data;
			}
		}

		return $results;
	}

	/**
	 * Aggregate heatmap data from multiple files
	 *
	 * @param array  $files_data Array of file data.
	 * @param string $type       Data type (clicks, moves, scrolls).
	 * @return array Aggregated data.
	 */
	public function aggregate_heatmap_data( $files_data, $type = 'clicks' ) {
		if ( empty( $files_data ) ) {
			return array(
				'coordinates'     => array(),
				'viewport'        => array( 'width' => 1920, 'height' => 1080 ),
				'reference_width' => 1920,
				'reference_height' => 1080,
				'window_height'   => 0,
				'total_events'    => 0,
			);
		}

		$all_coordinates = array();
		$viewport_widths = array();
		$viewport_heights = array();
		$page_heights = array();
		$window_heights = array();
		$total_events = 0;
		$max_y_coord = 0;

		// Normal aggregation for click, move, scroll heatmaps
		foreach ( $files_data as $file_data ) {
			if ( ! isset( $file_data['data'] ) || ! is_array( $file_data['data'] ) ) {
				continue;
			}

			// Collect viewport dimensions (viewport.width/height from metadata)
			if ( isset( $file_data['viewport']['width'] ) ) {
				$viewport_widths[] = $file_data['viewport']['width'];
			}
			if ( isset( $file_data['viewport']['height'] ) ) {
				$viewport_heights[] = $file_data['viewport']['height'];
			}

			// Clicks: drop recording-derived duplicates of anchored tracker
			// clicks BEFORE normalization. Pro session recording stores an
			// anchor-free copy of every tracker click in the SAME session file
			// at the same raw x/y but without 'vw'; after width normalization
			// the two copies diverge (only the vw-carrying one is scaled), so
			// the renderer's strict-matching dedup can no longer pair them and
			// the rrweb copy resurfaces as an absolute point (ghost hotspot on
			// collapsed menus / closed popups).
			$file_coords = $file_data['data'];
			if ( 'clicks' === $type ) {
				$file_coords = $this->dedupe_recording_click_twins( $file_coords );
			}

			// Add coordinates and track max Y and page heights from individual events
			foreach ( $file_coords as $coord ) {
				$all_coordinates[] = $coord;
				$total_events++;

				// Track maximum Y coordinate to determine actual page height
				if ( isset( $coord['y'] ) && $coord['y'] > $max_y_coord ) {
					$max_y_coord = (int) $coord['y'];
				}

				// The 'vh' field in click data actually contains page height (scrollHeight),
				// not viewport height. Collect these for accurate reference_height calculation.
				if ( isset( $coord['vh'] ) && $coord['vh'] > 0 ) {
					$page_heights[] = (int) $coord['vh'];
				}

				// Collect actual browser window heights (wh field) for attention heatmap
				if ( isset( $coord['wh'] ) && $coord['wh'] > 0 ) {
					$window_heights[] = (int) $coord['wh'];
				}
			}
		}

		// Calculate reference width (average of viewport widths)
		$reference_width = ! empty( $viewport_widths ) ? round( array_sum( $viewport_widths ) / count( $viewport_widths ) ) : 1920;

		// Calculate reference height for coordinate scaling:
		// Priority 1: Use page heights from event data (vh field contains scrollHeight)
		// Priority 2: Use max Y coordinate + buffer (ensures all clicks are within bounds)
		// Priority 3: Fall back to viewport heights average
		// Priority 4: Default to 5000 (typical long page)
		if ( ! empty( $page_heights ) ) {
			// Use the maximum page height from collected data
			$reference_height = max( $page_heights );
		} elseif ( $max_y_coord > 0 ) {
			// Use max Y coordinate + 20% buffer to ensure full coverage
			$reference_height = (int) ( $max_y_coord * 1.2 );
		} elseif ( ! empty( $viewport_heights ) ) {
			// Fall back to max viewport height (legacy data)
			$reference_height = max( $viewport_heights );
		} else {
			// Default for typical long pages
			$reference_height = 5000;
		}

		// Ensure minimum height for proper scaling
		$reference_height = max( $reference_height, 1080 );

		// Normalize coordinates to reference viewport if needed
		$all_coordinates = $this->normalize_coordinates( $all_coordinates, $reference_width );

		// Grid sampling: merge nearby coordinates into grid cells to reduce data volume.
		// With thousands of sessions, raw coordinate count can exceed 100,000+ points.
		// Sampling reduces this to a few thousand while preserving heatmap accuracy.
		if ( count( $all_coordinates ) > 500 ) {
			$all_coordinates = $this->sample_coordinates( $all_coordinates, 5 );
		}

		// Calculate average browser window height for attention heatmap rendering
		$avg_window_height = ! empty( $window_heights )
			? (int) round( array_sum( $window_heights ) / count( $window_heights ) )
			: 0;

		return array(
			'coordinates'      => $all_coordinates,
			'viewport'         => array(
				'width'  => $reference_width,
				'height' => $reference_height,
			),
			'reference_width'  => $reference_width,
			'reference_height' => $reference_height,
			'window_height'    => $avg_window_height,
			'total_events'     => $total_events,
		);
	}

	/**
	 * Aggregate scroll-REACH data from multiple session files.
	 *
	 * Unlike aggregate_heatmap_data() (which dumps a coordinate cloud), this is
	 * session-aware: each $file_data is treated as one session, reduced to its
	 * deepest reached Y (max breakaway y, which is the viewport bottom edge).
	 * The result is a monotonic non-increasing curve where, for each depth band,
	 * value = % of sessions that scrolled at least that deep. band(0) = 100.
	 *
	 * A session with no scroll coords counts as "saw the top only" (max_y = 0),
	 * so it still contributes to the top band but nothing below it.
	 *
	 * coordinates[].value is the final percentage (0-100). No grid sampling and
	 * no coordinate normalization are applied (either would corrupt monotonicity).
	 *
	 * @param array $files_data Array of session file data (each = one session).
	 * @return array Aggregated reach envelope.
	 */
	public function aggregate_scroll_reach_data( $files_data ) {
		if ( empty( $files_data ) ) {
			return array(
				'coordinates'      => array(),
				'viewport'         => array(
					'width'  => 1920,
					'height' => 1080,
				),
				'reference_width'  => 1920,
				'reference_height' => 1080,
				'window_height'    => 0,
				'total_events'     => 0,
				'scroll_metric'    => 'reach',
				'total_sessions'   => 0,
			);
		}

		$session_max_y    = array();
		$viewport_widths  = array();
		$viewport_heights = array();
		$page_heights     = array();
		$max_y_coord      = 0;

		foreach ( $files_data as $file_data ) {
			// Collect viewport dimensions (viewport.width/height from metadata).
			if ( isset( $file_data['viewport']['width'] ) ) {
				$viewport_widths[] = $file_data['viewport']['width'];
			}
			if ( isset( $file_data['viewport']['height'] ) ) {
				$viewport_heights[] = $file_data['viewport']['height'];
			}

			// Reduce this session to its deepest reached Y. Default 0 = saw top only.
			$session_y = 0;
			if ( isset( $file_data['data'] ) && is_array( $file_data['data'] ) ) {
				foreach ( $file_data['data'] as $coord ) {
					if ( isset( $coord['y'] ) && (int) $coord['y'] > $session_y ) {
						$session_y = (int) $coord['y'];
					}

					// The 'vh' field carries page height (scrollHeight) for reference.
					if ( isset( $coord['vh'] ) && $coord['vh'] > 0 ) {
						$page_heights[] = (int) $coord['vh'];
					}
				}
			}

			if ( $session_y > $max_y_coord ) {
				$max_y_coord = $session_y;
			}
			$session_max_y[] = $session_y;
		}

		$total_sessions = count( $session_max_y );

		// Reference width (average of viewport widths).
		$reference_width = ! empty( $viewport_widths )
			? round( array_sum( $viewport_widths ) / count( $viewport_widths ) )
			: 1920;

		// Reference height ladder (mirrors aggregate_heatmap_data):
		// 1) page heights (vh = scrollHeight), 2) max Y + 20%, 3) viewport heights, 4) default.
		if ( ! empty( $page_heights ) ) {
			$reference_height = max( $page_heights );
		} elseif ( $max_y_coord > 0 ) {
			$reference_height = (int) ( $max_y_coord * 1.2 );
		} elseif ( ! empty( $viewport_heights ) ) {
			$reference_height = max( $viewport_heights );
		} else {
			$reference_height = 5000;
		}
		$reference_height = max( $reference_height, 1080 );

		$coordinates = $this->build_scroll_reach_bands( $session_max_y, $reference_height );

		return array(
			'coordinates'      => $coordinates,
			'viewport'         => array(
				'width'  => $reference_width,
				'height' => $reference_height,
			),
			'reference_width'  => $reference_width,
			'reference_height' => $reference_height,
			'window_height'    => 0,
			'total_events'     => $total_sessions,
			'scroll_metric'    => 'reach',
			'total_sessions'   => $total_sessions,
		);
	}

	/**
	 * Build full-width scroll-reach bands from a list of per-session max-reach Y.
	 *
	 * Shared band-builder so the slow rrweb/parser fallback path can reuse the
	 * exact same cumulative math. For each band at depth band_y:
	 *   value = round( count(sessions where max_y >= band_y) / total * 100 ).
	 * Because band_y is ascending, the reached count is non-increasing, so the
	 * curve is guaranteed monotonic with value(0) = 100.
	 *
	 * @param array $session_max_y    List of per-session deepest reached Y (px).
	 * @param int   $reference_height Page height used to lay out bands.
	 * @return array Ascending-y coordinates with value as reach % (0-100).
	 */
	private function build_scroll_reach_bands( $session_max_y, $reference_height ) {
		$total_sessions = count( $session_max_y );
		if ( 0 === $total_sessions || $reference_height <= 0 ) {
			return array();
		}

		// 1 band per ~20px, clamped to the same 50-300 range used by the parser.
		$pixels_per_band = 20;
		$bands           = max( 50, min( 300, (int) ( $reference_height / $pixels_per_band ) ) );

		$coordinates = array();
		for ( $band = 0; $band <= $bands; $band++ ) {
			$band_y  = (int) round( ( $band / $bands ) * $reference_height );
			$reached = 0;
			foreach ( $session_max_y as $session_y ) {
				if ( $session_y >= $band_y ) {
					++$reached;
				}
			}

			$coordinates[] = array(
				'x'     => 0,
				'y'     => $band_y,
				'value' => (int) round( $reached / $total_sessions * 100 ),
			);
		}

		return $coordinates;
	}

	/**
	 * Drop recording-derived (rrweb click_metadata) duplicates of anchored
	 * tracker clicks within ONE session file.
	 *
	 * The Free tracker and Pro session recording both persist every physical
	 * click into the same per-session click file: the tracker copy carries the
	 * element anchor (xp/rx/ry) plus 'vw', the recording copy carries neither.
	 * Both copies share the same RAW page x/y at this point (divergence only
	 * happens later, when normalize_coordinates() scales vw-carrying copies),
	 * so pairing here is exact.
	 *
	 * Budget-based pairing: each anchored click can absorb exactly its own
	 * value-weight of anchor-free clicks at the same (±1px) position, so
	 * genuine extra anchor-free clicks at that spot survive. Files without
	 * anchored clicks (legacy / pure-recording sessions) pass through
	 * untouched.
	 *
	 * @param array $coords Coordinates of a single session file.
	 * @return array Coordinates with recording twins of anchored clicks removed.
	 */
	private function dedupe_recording_click_twins( $coords ) {
		if ( ! is_array( $coords ) || count( $coords ) < 2 ) {
			return $coords;
		}

		// Pass 1: value budget of anchored clicks per raw "x_y" position.
		$anchored_budget = array();
		foreach ( $coords as $coord ) {
			if ( isset( $coord['xp'], $coord['rx'], $coord['ry'] ) && '' !== $coord['xp'] ) {
				$key                     = (int) ( isset( $coord['x'] ) ? $coord['x'] : 0 ) . '_' . (int) ( isset( $coord['y'] ) ? $coord['y'] : 0 );
				$value                   = isset( $coord['value'] ) ? max( 1, (int) $coord['value'] ) : 1;
				$anchored_budget[ $key ] = isset( $anchored_budget[ $key ] ) ? $anchored_budget[ $key ] + $value : $value;
			}
		}
		if ( empty( $anchored_budget ) ) {
			return $coords;
		}

		// Pass 2: skip anchor-free coords that pair with an anchored click at
		// the same position (±1px tolerance for float→int truncation drift).
		$out = array();
		foreach ( $coords as $coord ) {
			$is_anchored = isset( $coord['xp'], $coord['rx'], $coord['ry'] ) && '' !== $coord['xp'];
			if ( ! $is_anchored ) {
				$x       = (int) ( isset( $coord['x'] ) ? $coord['x'] : 0 );
				$y       = (int) ( isset( $coord['y'] ) ? $coord['y'] : 0 );
				$value   = isset( $coord['value'] ) ? max( 1, (int) $coord['value'] ) : 1;
				$matched = false;
				for ( $dx = -1; $dx <= 1 && ! $matched; $dx++ ) {
					for ( $dy = -1; $dy <= 1; $dy++ ) {
						$key = ( $x + $dx ) . '_' . ( $y + $dy );
						if ( isset( $anchored_budget[ $key ] ) && $anchored_budget[ $key ] > 0 ) {
							$anchored_budget[ $key ] -= $value;
							$matched                  = true;
							break;
						}
					}
				}
				if ( $matched ) {
					continue;
				}
			}
			$out[] = $coord;
		}

		return $out;
	}

	/**
	 * Normalize coordinates to reference width
	 *
	 * @param array $coordinates     Array of coordinates.
	 * @param int   $reference_width Reference viewport width.
	 * @return array Normalized coordinates.
	 */
	private function normalize_coordinates( $coordinates, $reference_width ) {
		$normalized = array();

		foreach ( $coordinates as $coord ) {
			// If coordinate has viewport info and differs from reference, normalize
			if ( isset( $coord['vw'] ) && $coord['vw'] > 0 && $coord['vw'] !== $reference_width ) {
				$scale = $reference_width / $coord['vw'];
				$coord['x'] = round( $coord['x'] * $scale );
			}

			$norm = array(
				'x'     => isset( $coord['x'] ) ? (int) $coord['x'] : 0,
				'y'     => isset( $coord['y'] ) ? (int) $coord['y'] : 0,
				'value' => isset( $coord['value'] ) ? (int) $coord['value'] : 1,
			);

			// Preserve browser window height for attention heatmap rendering
			if ( isset( $coord['wh'] ) && $coord['wh'] > 0 ) {
				$norm['wh'] = (int) $coord['wh'];
			}

			// Preserve the element anchor (element-anchored click reprojection).
			// The anchor is resolution-independent, so width normalization above
			// does not affect it; coords without an anchor keep the absolute path.
			if ( isset( $coord['xp'] ) && '' !== $coord['xp'] && isset( $coord['rx'], $coord['ry'] ) ) {
				$norm['xp'] = (string) $coord['xp'];
				$norm['rx'] = (float) $coord['rx'];
				$norm['ry'] = (float) $coord['ry'];
			}

			$normalized[] = $norm;
		}

		return $normalized;
	}

	/**
	 * Merge nearby coordinates into a grid to reduce data volume.
	 *
	 * Points within the same grid cell are combined into a single point
	 * at the cell center with accumulated values. This dramatically reduces
	 * coordinate count (e.g., 100k → 3k) while preserving heatmap accuracy,
	 * since heatmap.js uses a radius that easily covers a 5px grid.
	 *
	 * @param array $coordinates Array of coordinate arrays with x, y, value keys.
	 * @param int   $grid_size   Grid cell size in pixels (default 5).
	 * @return array Sampled coordinates with merged values.
	 */
	private function sample_coordinates( $coordinates, $grid_size = 5 ) {
		$grid = array();

		foreach ( $coordinates as $coord ) {
			$x = isset( $coord['x'] ) ? (int) $coord['x'] : 0;
			$y = isset( $coord['y'] ) ? (int) $coord['y'] : 0;

			$cell_x = (int) ( $x / $grid_size );
			$cell_y = (int) ( $y / $grid_size );
			$key    = $cell_x . '_' . $cell_y;

			if ( ! isset( $grid[ $key ] ) ) {
				$grid[ $key ] = array(
					'x'     => $cell_x * $grid_size + (int) ( $grid_size / 2 ),
					'y'     => $cell_y * $grid_size + (int) ( $grid_size / 2 ),
					'value' => 0,
				);
				// Preserve wh field from first coordinate in cell (for attention heatmap)
				if ( isset( $coord['wh'] ) && $coord['wh'] > 0 ) {
					$grid[ $key ]['wh'] = (int) $coord['wh'];
				}
				// Seed the cell's element anchor from its first coordinate
				// (element-anchored click reprojection).
				if ( isset( $coord['xp'] ) && '' !== $coord['xp'] && isset( $coord['rx'], $coord['ry'] ) ) {
					$grid[ $key ]['xp'] = (string) $coord['xp'];
					$grid[ $key ]['rx'] = (float) $coord['rx'];
					$grid[ $key ]['ry'] = (float) $coord['ry'];
				}
			} elseif ( isset( $grid[ $key ]['xp'] ) ) {
				// Merged cell: keep the anchor only while every ANCHORED
				// coordinate in the cell hits the SAME element. Cells mixing two
				// different elements drop the anchor so the merged point safely
				// renders via the absolute fallback instead of being reprojected
				// onto the wrong element. Anchor-FREE coordinates merging into an
				// anchored cell no longer strip the anchor: they are either
				// recording-derived twins of the anchored click (Pro rrweb copy)
				// or clicks at the same 5px spot, and stripping would silently
				// disable strict matching for the whole cell.
				if ( isset( $coord['xp'] ) && '' !== $coord['xp'] && $coord['xp'] !== $grid[ $key ]['xp'] ) {
					unset( $grid[ $key ]['xp'], $grid[ $key ]['rx'], $grid[ $key ]['ry'] );
				}
			}

			$grid[ $key ]['value'] += isset( $coord['value'] ) ? (int) $coord['value'] : 1;
		}

		return array_values( $grid );
	}

	/**
	 * Delete old heatmap files
	 *
	 * @param int $days Number of days to keep.
	 * @return int Number of files deleted.
	 */
	public function delete_old_heatmap_files( $days ) {
		$cutoff = time() - ( $days * 24 * 60 * 60 );
		$deleted = 0;

		// Iterate through all URL hash directories
		$url_dirs = glob( $this->base_dir . '*', GLOB_ONLYDIR );

		foreach ( $url_dirs as $url_dir ) {
			$type_dirs = glob( $url_dir . '/*', GLOB_ONLYDIR );

			foreach ( $type_dirs as $type_dir ) {
				$files = glob( $type_dir . '/*.json' );

				foreach ( $files as $file ) {
					$filename = basename( $file );
					$meta = $this->parse_filename_metadata( $filename );

					if ( $meta && $meta['timestamp'] < $cutoff ) {
						wp_delete_file( $file );
						if ( ! file_exists( $file ) ) {
							$deleted++;
						}
					}
				}

				// Remove empty directory
				if ( count( glob( $type_dir . '/*' ) ) === 1 ) { // Only index.php remains
					// Clean up
				}
			}
		}

		// Deletion hook (spec P4.1): keep the daily index honest about the files
		// this retention sweep removed. Page ids are unknown here (dir-based
		// sweep) => mark all pages stale; the indexer re-derive corrects the
		// adaptive-mode estimate exactly.
		if ( $deleted > 0 ) {
			$this->note_heatmap_files_deleted( $deleted );
		}

		$this->log( sprintf( 'Deleted %d old heatmap files (older than %d days)', $deleted, $days ), 'info' );

		return $deleted;
	}

	/**
	 * Delete all heatmap files for a session
	 *
	 * @param string $session_id Session ID.
	 * @return int Number of files deleted.
	 */
	public function delete_session_heatmaps( $session_id ) {
		// Filenames carry the LAST 8 alphanumeric chars of the session id (see
		// build_filename()); the first-8 form never matched anything.
		$session_short = substr( preg_replace( '/[^a-zA-Z0-9]/', '', $session_id ), -8 );
		$deleted = 0;

		// Search all directories for files matching this session; remember which
		// url_hash dirs actually lost files so the deletion hook can stale-mark
		// just those pages instead of the whole mapping table.
		$url_dirs      = glob( $this->base_dir . '*', GLOB_ONLYDIR );
		$hit_hashes    = array();

		foreach ( $url_dirs as $url_dir ) {
			$type_dirs = glob( $url_dir . '/*', GLOB_ONLYDIR );

			foreach ( $type_dirs as $type_dir ) {
				$pattern = $type_dir . '/*_' . $session_short . '.json';
				$files = glob( $pattern );

				foreach ( $files as $file ) {
					wp_delete_file( $file );
					if ( ! file_exists( $file ) ) {
						$deleted++;
						$hit_hashes[ basename( $url_dir ) ] = true;
					}
				}
			}
		}

		// Deletion hook (spec P4.1): resolve the affected page ids from the hash
		// dirs the sweep deleted from, so only those pages are re-derived.
		if ( $deleted > 0 ) {
			$page_ids = $this->get_page_ids_for_url_hashes( array_keys( $hit_hashes ) );
			$this->note_heatmap_files_deleted( $deleted, $page_ids );
		}

		return $deleted;
	}

	/**
	 * Map url_hash dir names back to their heatmap page ids (deletion-hook
	 * attribution, spec P4.1).
	 *
	 * @since 1.9.x
	 * @param string[] $url_hashes url_hash dir basenames.
	 * @return int[] Distinct page ids (may be empty when no mapping rows exist).
	 */
	private function get_page_ids_for_url_hashes( array $url_hashes ) {
		global $wpdb;

		$url_hashes = array_values( array_filter( array_map( 'strval', $url_hashes ) ) );
		if ( empty( $url_hashes ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $url_hashes ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifier from $wpdb->prefix.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT page_id FROM {$wpdb->prefix}optibehavior_heatmap_pages WHERE url_hash IN ({$placeholders})",
				$url_hashes
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Get URL hash for a page ID
	 *
	 * @param int $page_id Page ID from optibehavior_pages table.
	 * @return string|false URL hash or false if not found.
	 */
	public function get_url_hash_for_page( $page_id ) {
		global $wpdb;

		// Check in-process object cache first (avoids repeated DB queries within the same request
		// and across batch requests when a persistent object cache like Redis/Memcached is available)
		$cache_key = 'opti_url_hash_' . (int) $page_id;
		$cached    = wp_cache_get( $cache_key, 'opti-behavior' );
		if ( false !== $cached ) {
			return $cached;
		}

		// First try the heatmap_pages mapping table
		$table_name = $wpdb->prefix . 'optibehavior_heatmap_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$url_hash = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT url_hash FROM {$table_name} WHERE page_id = %d",
					$page_id
				)
			);

			if ( $url_hash ) {
				wp_cache_set( $cache_key, $url_hash, 'opti-behavior', 3600 );
				return $url_hash;
			}
		}

		// Fallback: get URL from pages table and calculate hash
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT url FROM {$wpdb->prefix}optibehavior_pages WHERE id = %d",
				$page_id
			)
		);

		if ( $url ) {
			$url_hash = $this->get_url_hash( $url );
			wp_cache_set( $cache_key, $url_hash, 'opti-behavior', 3600 );
			return $url_hash;
		}

		return false;
	}

	/**
	 * Get ALL URL hashes for a page ID (union of every mapping-table row plus
	 * the 3 md5 URL-variant hashes). A page can have more than one
	 * `optibehavior_heatmap_pages` row (e.g. a stale QA URL hash alongside the
	 * live one) - the file universe for the page is the union of all of them,
	 * never just the first row. See spec §3.1 (RC-A/RC-B).
	 *
	 * @param int $page_id Page ID from optibehavior_pages table.
	 * @return string[] Deduped list of url_hash strings (may be empty).
	 */
	public function get_url_hashes_for_page( $page_id ) {
		global $wpdb;

		$page_id = (int) $page_id;

		$cache_key = 'opti_url_hashes_' . $page_id;
		$cached    = wp_cache_get( $cache_key, 'opti-behavior' );
		if ( false !== $cached ) {
			return $cached;
		}

		$hashes = array();

		// 1. ALL mapping rows for this page (not just the first one).
		$table_name = $wpdb->prefix . 'optibehavior_heatmap_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT url_hash FROM {$table_name} WHERE page_id = %d ORDER BY id ASC",
					$page_id
				)
			);
			foreach ( (array) $rows as $row_hash ) {
				if ( $row_hash ) {
					$hashes[] = (string) $row_hash;
				}
			}
		}

		// 2. The 3 md5 URL-variant hashes derived from the page's current URL
		// (raw, trailing-slash-stripped, trailing-slash-appended) - covers
		// legacy data written before the mapping table existed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT url FROM {$wpdb->prefix}optibehavior_pages WHERE id = %d",
				$page_id
			)
		);
		if ( $url ) {
			$hashes[] = md5( $url );
			$hashes[] = md5( rtrim( $url, '/' ) );
			$hashes[] = md5( $url . '/' );
		}

		$hashes = array_values( array_unique( $hashes ) );

		wp_cache_set( $cache_key, $hashes, 'opti-behavior', 3600 );

		return $hashes;
	}

	/**
	 * Get storage statistics
	 *
	 * @return array Storage statistics.
	 */
	public function get_storage_stats() {
		$stats = array(
			'total_size'    => 0,
			'total_files'   => 0,
			'click_files'   => 0,
			'move_files'    => 0,
			'scroll_files'  => 0,
			'url_count'     => 0,
		);

		$url_dirs = glob( $this->base_dir . '*', GLOB_ONLYDIR );
		$stats['url_count'] = count( $url_dirs );

		foreach ( $url_dirs as $url_dir ) {
			foreach ( array( 'clicks', 'moves', 'scrolls' ) as $type ) {
				$type_dir = $url_dir . '/' . $type;
				if ( is_dir( $type_dir ) ) {
					$files = glob( $type_dir . '/*.json' );
					$count = count( $files );

					if ( $type === 'clicks' ) {
						$stats['click_files'] += $count;
					} elseif ( $type === 'moves' ) {
						$stats['move_files'] += $count;
					} elseif ( $type === 'scrolls' ) {
						$stats['scroll_files'] += $count;
					}

					$stats['total_files'] += $count;

					foreach ( $files as $file ) {
						$stats['total_size'] += filesize( $file );
					}
				}
			}
		}

		return $stats;
	}

	/**
	 * Get base directory path
	 *
	 * @return string Base directory path.
	 */
	public function get_base_dir() {
		return $this->base_dir;
	}

	/**
	 * Delete every on-disk hash directory that belongs to a page.
	 *
	 * Orphan-file leak closure (spec §conflict-safe design): when Smart Cleanup
	 * removes a page's LAST surviving session the page's heatmap JSON files
	 * become permanent orphans (files on disk, no DB trace, no registry row).
	 * Left behind, a future file-based tool could resurrect intentionally
	 * deleted / spam data. Removing the page's whole hash directory tree at that
	 * point keeps the DB authoritative — anything cleanup removed stays removed.
	 *
	 * Callers MUST have already confirmed the page has no remaining live
	 * sessions before invoking this (it deletes ALL of the page's files).
	 *
	 * @since 1.9.x
	 * @param int $page_id Page ID whose hash directories should be removed.
	 * @return int Number of hash directories deleted.
	 */
	public function delete_page_hash_directories( $page_id ) {
		$page_id = (int) $page_id;
		if ( $page_id <= 0 ) {
			return 0;
		}

		$hashes = $this->get_url_hashes_for_page( $page_id );
		if ( empty( $hashes ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $hashes as $hash ) {
			// url_hash is always a 32-char md5; hard-guard against traversal
			// before touching the filesystem.
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
				continue;
			}

			$dir = $this->base_dir . $hash;
			if ( is_dir( $dir ) && $this->recursive_rmdir( $dir ) ) {
				++$deleted;
				$this->log( sprintf( 'Deleted orphaned hash directory for page %d: %s', $page_id, $hash ), 'info' );
			}
		}

		return $deleted;
	}

	/**
	 * ARCHIVE (never hard-delete) every on-disk hash directory that belongs to a
	 * page — the non-destructive counterpart of
	 * {@see delete_page_hash_directories()}.
	 *
	 * Guardrail policy (2026-08-15, Option B): when Smart Cleanup removes a
	 * page's last surviving DB session, the page's heatmap JSON files are moved
	 * under `_orphaned/{hash}/` instead of being deleted, so a retention/cleanup
	 * mistake can never permanently destroy raw interaction data again. Each dir
	 * move is a single atomic rename() — O(1) per hash, no per-file traversal.
	 *
	 * @since 2026-08-15 (guardrail: archive instead of hard delete)
	 * @param int $page_id Page ID whose hash directories should be archived.
	 * @return int Number of hash directories archived.
	 */
	public function archive_page_hash_directories( $page_id ) {
		$page_id = (int) $page_id;
		if ( $page_id <= 0 ) {
			return 0;
		}

		$hashes = $this->get_url_hashes_for_page( $page_id );
		if ( empty( $hashes ) ) {
			return 0;
		}

		$archived = 0;
		foreach ( $hashes as $hash ) {
			// url_hash is always a 32-char md5; hard-guard against traversal
			// before touching the filesystem.
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
				continue;
			}

			if ( $this->archive_orphaned_hash_dir( $hash ) ) {
				++$archived;
				$this->log( sprintf( 'Archived orphaned hash directory for page %d to _orphaned/: %s', $page_id, $hash ), 'info' );
			}
		}

		return $archived;
	}

	/**
	 * Cheap probe: does a url_hash directory still hold at least one heatmap
	 * JSON file?
	 *
	 * Deliberately O(few): readdir() each of the 3 event folders and stop at the
	 * FIRST `.json` entry — never builds a full file list, never recurses, never
	 * globs. Safe to call in per-row loops on installs with millions of files
	 * (a non-empty folder answers within its first few directory entries).
	 *
	 * @since 2026-08-15 (guardrail: cleanup_pages() file-existence gate)
	 * @param string $url_hash 32-char md5 url_hash.
	 * @return bool True when at least one clicks/moves/scrolls JSON file exists.
	 */
	public function hash_dir_has_heatmap_files( $url_hash ) {
		if ( ! is_string( $url_hash ) || ! preg_match( '/^[a-f0-9]{32}$/', $url_hash ) ) {
			return false;
		}

		$dir = $this->base_dir . $url_hash . '/';
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder ) {
			$handle = @opendir( $dir . $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Folder may legitimately not exist.
			if ( false === $handle ) {
				continue;
			}
			while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( '.json' === substr( $entry, -5 ) ) {
					closedir( $handle );
					return true;
				}
			}
			closedir( $handle );
		}

		return false;
	}

	/**
	 * Recursively delete a directory and its contents (WP filesystem wrappers).
	 *
	 * @param string $dir Absolute directory path (already validated to live
	 *                    under the heatmap base directory).
	 * @return bool True on success.
	 */
	private function recursive_rmdir( $dir ) {
		$dir = rtrim( $dir, '/\\' );

		// Containment guard: never delete anything outside the heatmap base dir.
		$base = rtrim( $this->base_dir, '/\\' );
		if ( 0 !== strpos( $dir . '/', $base . '/' ) ) {
			return false;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing a now-empty plugin data directory; WP_Filesystem not guaranteed on cron.
		return @rmdir( $dir );
	}
}
