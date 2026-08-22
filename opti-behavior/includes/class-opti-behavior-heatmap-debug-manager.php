<?php
/**
 * Debug Manager
 *
 * Centralized debug logging system with configurable PHP and JavaScript debugging.
 * Supports custom log paths and granular debug control.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug Manager Class
 *
 * Handles debug logging for PHP and JavaScript.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Heatmap_Debug_Manager {

	/**
	 * Core instance.
	 *
	 * @since 1.0.0
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Debug settings option name.
	 *
	 * @since 1.0.0
	 */
	const DEBUG_OPTION = 'opti_behavior_heatmap_debug_settings';

	/**
	 * Default log file name.
	 *
	 * @since 1.0.0
	 */
	const DEFAULT_LOG_FILE = 'opti-behavior-heatmap-debug.log';

	/**
	 * Maximum time (seconds) a debug flag may stay enabled before it is
	 * automatically switched off. Customers enable debug mode and forget it,
	 * which slows the site — hard cap at 3 hours.
	 *
	 * Filterable via `opti_behavior_debug_max_lifetime` (QA / support only).
	 *
	 * @since 1.2.5
	 */
	const MAX_DEBUG_LIFETIME = 3 * HOUR_IN_SECONDS;

	/**
	 * Cron hook fired once as a backup to auto-disable expired debug flags on
	 * low-traffic sites. The lazy check in load_settings() remains authoritative.
	 *
	 * @since 1.2.5
	 */
	const AUTO_DISABLE_CRON_HOOK = 'opti_behavior_debug_auto_disable';

	/**
	 * Option holding the pending "debug was auto-disabled" admin notice.
	 * Set when a flag expires, cleared when an admin dismisses the notice.
	 *
	 * @since 1.2.5
	 */
	const NOTICE_OPTION = 'opti_behavior_debug_auto_disabled_notice';

	/**
	 * Admin-post action name used to dismiss the auto-disabled notice.
	 *
	 * @since 1.2.5
	 */
	const NOTICE_DISMISS_ACTION = 'opti_behavior_dismiss_debug_auto_disabled_notice';

	/**
	 * Debug settings.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		$this->load_settings();
	}

	/**
	 * Load debug settings.
	 *
	 * @since 1.0.0
	 */
	private function load_settings() {
		$defaults = array(
			'php_debug_enabled'    => false,
			'js_debug_enabled'     => false,
			// Unix timestamps of the last false→true transition per flag.
			// 0 = flag is off / never enabled. Used for the 3-hour auto-disable.
			'php_debug_enabled_at' => 0,
			'js_debug_enabled_at'  => 0,
			'log_to_file'          => true,
			'log_to_console'       => false,
			'custom_log_path'      => '',
			'log_folder'           => 'opti-behavior-logs',
			'log_level'            => 'info', // error, warning, info, debug
			'max_log_size'         => 10, // MB
			'auto_cleanup'         => true,
			'cleanup_days'         => 30,
		);

		$this->settings = wp_parse_args( get_option( self::DEBUG_OPTION, array() ), $defaults );

		// Auto-disable any debug flag enabled for longer than the max lifetime.
		// Runs on every request (front + admin + cron), so every later
		// is_*_debug_enabled() call already sees the expired state.
		$this->maybe_expire();
	}

	/**
	 * Save debug settings.
	 *
	 * Stamps `*_enabled_at` on every false→true transition of a debug flag,
	 * zeroes it when the flag is turned off, and (un)schedules the
	 * auto-disable backup cron event accordingly.
	 *
	 * @since 1.0.0
	 * @param array $settings Settings to save.
	 */
	public function save_settings( $settings ) {
		$old_php = ! empty( $this->settings['php_debug_enabled'] );
		$old_js  = ! empty( $this->settings['js_debug_enabled'] );

		$this->settings = wp_parse_args( $settings, $this->settings );

		$new_php = ! empty( $this->settings['php_debug_enabled'] );
		$new_js  = ! empty( $this->settings['js_debug_enabled'] );
		$now     = time();

		// User re-enabled debug (or saved the tab): any pending "auto-disabled"
		// notice is stale — they clearly know about the state now.
		if ( ( $new_php && ! $old_php ) || ( $new_js && ! $old_js ) ) {
			delete_option( self::NOTICE_OPTION );
		}

		// PHP flag transitions.
		if ( $new_php && ! $old_php ) {
			$this->settings['php_debug_enabled_at'] = $now;
		} elseif ( ! $new_php ) {
			$this->settings['php_debug_enabled_at'] = 0;
		} elseif ( empty( $this->settings['php_debug_enabled_at'] ) ) {
			// Defensive: enabled but timestamp missing (legacy/corrupted option).
			$this->settings['php_debug_enabled_at'] = $now;
		}

		// JS flag transitions.
		if ( $new_js && ! $old_js ) {
			$this->settings['js_debug_enabled_at'] = $now;
		} elseif ( ! $new_js ) {
			$this->settings['js_debug_enabled_at'] = 0;
		} elseif ( empty( $this->settings['js_debug_enabled_at'] ) ) {
			$this->settings['js_debug_enabled_at'] = $now;
		}

		update_option( self::DEBUG_OPTION, $this->settings );

		$this->reschedule_auto_disable_event();
	}

	/**
	 * Get the maximum debug lifetime in seconds.
	 *
	 * @since 1.2.5
	 * @return int Lifetime in seconds (>= 60, defensive against bad filter values).
	 */
	public function get_max_debug_lifetime() {
		/**
		 * Filter the maximum time a debug flag may stay enabled.
		 *
		 * @since 1.2.5
		 * @param int $lifetime Lifetime in seconds. Default 3 hours.
		 */
		$lifetime = (int) apply_filters( 'opti_behavior_debug_max_lifetime', self::MAX_DEBUG_LIFETIME );

		return ( $lifetime > 0 ) ? max( 60, $lifetime ) : self::MAX_DEBUG_LIFETIME;
	}

	/**
	 * Auto-disable debug flags that have been enabled longer than the max
	 * lifetime. Each flag expires independently. Persists with a single
	 * update_option() write, and only when something actually changed.
	 *
	 * Also lazily stamps legacy installs that had debug enabled before the
	 * timestamps existed — they get a full lifetime from first sighting and
	 * are never disabled instantly.
	 *
	 * @since 1.2.5
	 * @return bool True when at least one flag was auto-disabled.
	 */
	public function maybe_expire() {
		$now      = time();
		$lifetime = $this->get_max_debug_lifetime();
		$dirty    = false;
		$expired  = array();

		$flag_labels = array(
			'php' => 'PHP',
			'js'  => 'JavaScript',
		);

		foreach ( $flag_labels as $key => $label ) {
			$flag_key = $key . '_debug_enabled';
			$time_key = $key . '_debug_enabled_at';

			if ( empty( $this->settings[ $flag_key ] ) ) {
				// Flag off — make sure no stale timestamp lingers.
				if ( ! empty( $this->settings[ $time_key ] ) ) {
					$this->settings[ $time_key ] = 0;
					$dirty                       = true;
				}
				continue;
			}

			$enabled_at = (int) $this->settings[ $time_key ];

			if ( $enabled_at <= 0 || $enabled_at > $now ) {
				// Legacy install without a timestamp, or clock skew put the
				// stamp in the future: (re)stamp now — full lifetime from
				// this sighting, never insta-disable.
				$this->settings[ $time_key ] = $now;
				$dirty                       = true;
				continue;
			}

			if ( ( $now - $enabled_at ) > $lifetime ) {
				$this->settings[ $flag_key ] = false;
				$this->settings[ $time_key ] = 0;
				$dirty                       = true;
				$expired[ $key ]             = $label;
			}
		}

		if ( ! $dirty ) {
			return false;
		}

		// Log BEFORE persisting so the customer can see why debug turned off.
		// Written straight to the log file: the regular log() gate would
		// swallow the line when the PHP debug flag itself just expired.
		if ( ! empty( $expired ) && ! empty( $this->settings['log_to_file'] ) ) {
			$message = sprintf(
				'%s debug auto-disabled after %d hours to protect site performance.',
				implode( ' and ', $expired ),
				(int) round( $lifetime / HOUR_IN_SECONDS )
			);
			$this->log_to_file( $this->format_log_message( $message, 'info', 'debug-manager' ) );
		}

		update_option( self::DEBUG_OPTION, $this->settings );

		// Queue the dismissible wp-admin notice so the customer learns WHY
		// debug switched off (works from front-end, admin and cron context).
		if ( ! empty( $expired ) ) {
			$this->record_auto_disabled_notice( array_keys( $expired ) );
		}

		// Keep the backup cron event consistent with the new state.
		$this->reschedule_auto_disable_event();

		return ! empty( $expired );
	}

	/**
	 * Persist the pending "debug auto-disabled" notice, merging with any
	 * not-yet-dismissed earlier notice so no expiry gets lost.
	 *
	 * Stores flag keys ('php' / 'js'), never display strings — labels are
	 * translated at render time in the admin's locale.
	 *
	 * @since 1.2.5
	 * @param array $expired_keys Expired flag keys ('php', 'js').
	 */
	private function record_auto_disabled_notice( $expired_keys ) {
		$existing = get_option( self::NOTICE_OPTION, array() );
		$flags    = isset( $existing['flags'] ) && is_array( $existing['flags'] ) ? $existing['flags'] : array();
		$flags    = array_values( array_unique( array_merge( $flags, $expired_keys ) ) );

		update_option(
			self::NOTICE_OPTION,
			array(
				'flags' => $flags,
				'time'  => time(),
			),
			false
		);
	}

	/**
	 * Render the dismissible "debug auto-disabled" admin notice.
	 *
	 * Hooked to `admin_notices`. Shown only to users who can manage the
	 * plugin settings; persists across page loads until dismissed.
	 *
	 * @since 1.2.5
	 */
	public function render_auto_disabled_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = get_option( self::NOTICE_OPTION, array() );
		if ( empty( $notice['flags'] ) || ! is_array( $notice['flags'] ) ) {
			return;
		}

		$labels = array();
		if ( in_array( 'php', $notice['flags'], true ) ) {
			$labels[] = __( 'PHP debug logging', 'opti-behavior' );
		}
		if ( in_array( 'js', $notice['flags'], true ) ) {
			$labels[] = __( 'JavaScript debug logging', 'opti-behavior' );
		}
		if ( empty( $labels ) ) {
			return;
		}

		$hours = (int) round( $this->get_max_debug_lifetime() / HOUR_IN_SECONDS );
		$hours = max( 1, $hours );

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'action', self::NOTICE_DISMISS_ACTION, admin_url( 'admin-post.php' ) ),
			self::NOTICE_DISMISS_ACTION
		);
		?>
		<div class="notice notice-warning is-dismissible" id="opti-behavior-debug-auto-disabled-notice" data-dismiss-url="<?php echo esc_url( $dismiss_url ); ?>">
			<p>
				<strong><?php esc_html_e( 'Opti-Behavior:', 'opti-behavior' ); ?></strong>
				<?php
				printf(
					/* translators: 1: which debug modes were disabled (e.g. "PHP debug logging and JavaScript debug logging"), 2: number of hours. */
					esc_html( _n( '%1$s was automatically disabled after %2$d hour to protect site performance.', '%1$s was automatically disabled after %2$d hours to protect site performance.', $hours, 'opti-behavior' ) ),
					esc_html( implode( __( ' and ', 'opti-behavior' ), $labels ) ),
					(int) $hours
				);
				?>
				<?php esc_html_e( 'You can re-enable it any time from the Debug & Logging settings tab.', 'opti-behavior' ); ?>
			</p>
		</div>
		<script>
		( function () {
			var notice = document.getElementById( 'opti-behavior-debug-auto-disabled-notice' );
			if ( ! notice ) {
				return;
			}
			// WP core injects the X button; persist the dismissal server-side.
			notice.addEventListener( 'click', function ( event ) {
				if ( event.target && event.target.classList.contains( 'notice-dismiss' ) ) {
					window.fetch( notice.getAttribute( 'data-dismiss-url' ), { credentials: 'same-origin' } );
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Handle the admin-post dismissal of the auto-disabled notice.
	 *
	 * Nonce- and capability-checked. Responds to both the fetch() call from
	 * the notice's X button and a direct (non-JS) request.
	 *
	 * @since 1.2.5
	 */
	public function handle_dismiss_auto_disabled_notice() {
		check_admin_referer( self::NOTICE_DISMISS_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'opti-behavior' ),
				esc_html__( 'Unauthorized', 'opti-behavior' ),
				array( 'response' => 403 )
			);
		}

		delete_option( self::NOTICE_OPTION );

		$redirect_url = wp_get_referer();
		if ( ! $redirect_url ) {
			$redirect_url = admin_url();
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Cron callback for the auto-disable backup event.
	 *
	 * The lazy check in load_settings() already ran in the constructor for
	 * this request; this re-run covers the edge where the event fired
	 * slightly early — reschedule_auto_disable_event() then re-arms it.
	 *
	 * @since 1.2.5
	 */
	public function handle_auto_disable_event() {
		// Re-read the option: state may have changed (another request, manual
		// disable) since this instance was constructed — never act on stale
		// in-memory settings. load_settings() runs maybe_expire() itself.
		$this->load_settings();

		// If nothing expired (event fired early / flags re-enabled since),
		// make sure a follow-up event exists while any flag is still on;
		// clears the hook when everything is off. Idempotent.
		$this->reschedule_auto_disable_event();
	}

	/**
	 * Keep exactly zero or one pending auto-disable cron event.
	 *
	 * Clears the hook first (avoids duplicate events), then schedules a
	 * single event at the earliest upcoming expiry when at least one debug
	 * flag is enabled. Backup only — lazy expiry remains authoritative.
	 *
	 * @since 1.2.5
	 */
	private function reschedule_auto_disable_event() {
		wp_clear_scheduled_hook( self::AUTO_DISABLE_CRON_HOOK );

		$stamps = array();
		if ( ! empty( $this->settings['php_debug_enabled'] ) && (int) $this->settings['php_debug_enabled_at'] > 0 ) {
			$stamps[] = (int) $this->settings['php_debug_enabled_at'];
		}
		if ( ! empty( $this->settings['js_debug_enabled'] ) && (int) $this->settings['js_debug_enabled_at'] > 0 ) {
			$stamps[] = (int) $this->settings['js_debug_enabled_at'];
		}

		if ( empty( $stamps ) ) {
			return;
		}

		// +60s margin so the lazy check comparison (strictly greater than
		// lifetime) is guaranteed to pass when the event fires on time.
		$fire_at = min( $stamps ) + $this->get_max_debug_lifetime() + 60;
		wp_schedule_single_event( max( $fire_at, time() + 60 ), self::AUTO_DISABLE_CRON_HOOK );
	}

	/**
	 * Get debug settings.
	 *
	 * @since 1.0.0
	 * @return array Debug settings.
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Check if PHP debugging is enabled.
	 *
	 * @since 1.0.0
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_php_debug_enabled() {
		return (bool) $this->settings['php_debug_enabled'];
	}

	/**
	 * Check if JavaScript debugging is enabled.
	 *
	 * @since 1.0.0
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_js_debug_enabled() {
		return (bool) $this->settings['js_debug_enabled'];
	}

	/**
	 * Get log file path.
	 *
	 * @since 1.0.0
	 * @return string Log file path.
	 */
	public function get_log_file_path() {
		// Use custom path if specified
		if ( ! empty( $this->settings['custom_log_path'] ) ) {
			$base_path = trailingslashit( $this->settings['custom_log_path'] );
		} else {
			// Default to uploads directory (WordPress best practice)
			$upload_dir = wp_upload_dir();
			$base_path = trailingslashit( $upload_dir['basedir'] );
		}

		// Add log folder
		if ( ! empty( $this->settings['log_folder'] ) ) {
			$base_path .= trailingslashit( $this->settings['log_folder'] );
		}

		// Ensure directory exists
		if ( ! file_exists( $base_path ) ) {
			wp_mkdir_p( $base_path );
			
			// Add .htaccess to protect log files
			$htaccess_file = $base_path . '.htaccess';
			if ( ! file_exists( $htaccess_file ) ) {
				file_put_contents( $htaccess_file, "Deny from all\n" );
			}
		}

		return $base_path . self::DEFAULT_LOG_FILE;
	}

	/**
	 * Log a message.
	 *
	 * @since 1.0.0
	 * @param string $message Message to log.
	 * @param string $level   Log level (error, warning, info, debug).
	 * @param string $context Context/category.
	 */
	public function log( $message, $level = 'info', $context = 'general' ) {
		// Check if PHP debugging is enabled
		if ( ! $this->is_php_debug_enabled() ) {
			return;
		}

		// Check log level
		if ( ! $this->should_log_level( $level ) ) {
			return;
		}

		// Format message
		$formatted_message = $this->format_log_message( $message, $level, $context );

		// Log to file
		if ( $this->settings['log_to_file'] ) {
			$this->log_to_file( $formatted_message );
		}

		// Log to WordPress debug.log
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional logging to WordPress debug.log in debug manager
			error_log( $formatted_message );
		}

		// Log to browser console (for AJAX requests)
		if ( $this->settings['log_to_console'] && wp_doing_ajax() ) {
			$this->log_to_console( $message, $level, $context );
		}
	}

	/**
	 * Check if a log level should be logged.
	 *
	 * @since 1.0.0
	 * @param string $level Log level to check.
	 * @return bool True if should log, false otherwise.
	 */
	private function should_log_level( $level ) {
		$levels = array( 'error' => 1, 'warning' => 2, 'info' => 3, 'debug' => 4 );
		$current_level = isset( $levels[ $this->settings['log_level'] ] ) ? $levels[ $this->settings['log_level'] ] : 3;
		$message_level = isset( $levels[ $level ] ) ? $levels[ $level ] : 3;

		return $message_level <= $current_level;
	}

	/**
	 * Format log message.
	 *
	 * @since 1.0.0
	 * @param string $message Message.
	 * @param string $level   Level.
	 * @param string $context Context.
	 * @return string Formatted message.
	 */
	private function format_log_message( $message, $level, $context ) {
		$timestamp = current_time( 'Y-m-d H:i:s' );
		$level_upper = strtoupper( $level );
		return "[{$timestamp}] [{$level_upper}] [{$context}] {$message}";
	}

	/**
	 * Log to file.
	 *
	 * @since 1.0.0
	 * @param string $message Formatted message.
	 */
	private function log_to_file( $message ) {
		$log_file = $this->get_log_file_path();

		// Check file size and rotate if needed
		if ( file_exists( $log_file ) ) {
			$file_size_mb = filesize( $log_file ) / 1024 / 1024;
			if ( $file_size_mb > $this->settings['max_log_size'] ) {
				$this->rotate_log_file( $log_file );
			}
		}

		// Write to log file
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional file logging in debug manager (error_log with file parameter)
		error_log( $message . PHP_EOL, 3, $log_file );
	}

	/**
	 * Rotate log file.
	 *
	 * @since 1.0.0
	 * @param string $log_file Log file path.
	 */
	private function rotate_log_file( $log_file ) {
		$timestamp = current_time( 'Y-m-d-H-i-s' );
		$rotated_file = str_replace( '.log', "-{$timestamp}.log", $log_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- File system operation for log rotation
		rename( $log_file, $rotated_file );

		// Auto-cleanup old logs if enabled
		if ( $this->settings['auto_cleanup'] ) {
			$this->cleanup_old_logs();
		}
	}

	/**
	 * Cleanup old log files.
	 *
	 * @since 1.0.0
	 */
	public function cleanup_old_logs() {
		$log_dir = dirname( $this->get_log_file_path() );
		$cleanup_time = time() - ( $this->settings['cleanup_days'] * DAY_IN_SECONDS );

		$files = glob( $log_dir . '/opti-behavior-heatmap-debug-*.log' );
		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cleanup_time ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- File system operation for log cleanup
				unlink( $file );
			}
		}
	}

	/**
	 * Log to browser console.
	 *
	 * @since 1.0.0
	 * @param string $message Message.
	 * @param string $level   Level.
	 * @param string $context Context.
	 * @global array $GLOBALS['opti_behavior_console_logs'] Console logs array.
	 */
	private function log_to_console( $message, $level, $context ) {
		// Store console logs to be output in AJAX response
		if ( ! isset( $GLOBALS['opti_behavior_console_logs'] ) ) {
			$GLOBALS['opti_behavior_console_logs'] = array();
		}

		$GLOBALS['opti_behavior_console_logs'][] = array(
			'message' => $message,
			'level'   => $level,
			'context' => $context,
		);
	}

	/**
	 * Get JavaScript debug configuration.
	 *
	 * @since 1.0.0
	 * @return array Debug configuration.
	 */
	public function get_js_debug_config() {
		return array(
			'enabled'    => $this->is_js_debug_enabled(),
			'logLevel'   => $this->settings['log_level'],
			'prefix'     => '[optibehavior]',
			'timestamp'  => true,
			'stackTrace' => $this->settings['log_level'] === 'debug',
		);
	}

	/**
	 * Get log file contents.
	 *
	 * @since 1.0.0
	 * @param int $lines Number of lines to retrieve (default: 100).
	 * @return string Log contents.
	 */
	public function get_log_contents( $lines = 100 ) {
		$log_file = $this->get_log_file_path();

		if ( ! file_exists( $log_file ) ) {
			return 'No log file found.';
		}

		// Read last N lines
		$file = new SplFileObject( $log_file, 'r' );
		$file->seek( PHP_INT_MAX );
		$total_lines = $file->key();
		$start_line = max( 0, $total_lines - $lines );

		$file->seek( $start_line );
		$log_contents = '';
		while ( ! $file->eof() ) {
			$log_contents .= $file->fgets();
		}

		return $log_contents;
	}

	/**
	 * Clear log file.
	 *
	 * @since 1.0.0
	 */
	public function clear_log() {
		$log_file = $this->get_log_file_path();
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}
	}

	/**
	 * Get log file size.
	 *
	 * @since 1.0.0
	 * @return string Formatted file size.
	 */
	public function get_log_file_size() {
		$log_file = $this->get_log_file_path();
		if ( ! file_exists( $log_file ) ) {
			return '0 KB';
		}

		$size_bytes = filesize( $log_file );
		return size_format( $size_bytes, 2 );
	}
}

