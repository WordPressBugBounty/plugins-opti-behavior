<?php
/**
 * Batched size scan of the plugin data folder (uploads/opti-behavior-data).
 *
 * One recursive walk of a large folder (50 000+ files) does not fit in one
 * PHP request on a shared host: Settings > Storage Stats showed "Could not
 * load storage data." and Retry failed the same way (OPT-1944). The walk is
 * now cut into steps: each AJAX call reads at most BATCH entries or
 * max_seconds(), then saves where it stopped (folders still to read + the
 * position inside the current folder). The finished result is cached
 * RESULT_TTL (12 h) and also feeds the Danger Zone file sizes (by_top).
 *
 * A step that dies (time limit, memory, killed by the server) leaves its
 * cause in the saved state: the next call answers it, and Retry resumes from
 * the last saved position with a smaller batch.
 *
 * @package Opti_Behavior
 * @since   1.10.0 (OPT-1944)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batched file scan of the plugin data folder.
 */
class Opti_Behavior_Stats_File_Scan {

	/** Scan in progress (where it stopped + counts so far). */
	const STATE_TRANSIENT = 'opti_behavior_file_scan_state';

	/** Last finished scan. */
	const RESULT_TRANSIENT = 'opti_behavior_file_scan_result';

	/** Lifetime of a finished scan (12 h). */
	const RESULT_TTL = 43200;

	/** Lifetime of an unfinished scan (1 h). */
	const STATE_TTL = 3600;

	/** Entries (files + folders) read per step. */
	const BATCH = 2000;

	/** Smallest batch after failed steps. */
	const MIN_BATCH = 100;

	/** MySQL lock name (one step at a time on the site). */
	const LOCK = 'ob_file_scan';

	/**
	 * State of the step running in this request (read by on_shutdown()).
	 *
	 * @var array|null
	 */
	private static $running = null;

	/**
	 * Whether the shutdown handler is registered.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Absolute path of the folder to scan.
	 *
	 * @return string
	 */
	public static function root() {
		$upload_dir = wp_upload_dir();
		$root       = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data';

		/**
		 * Folder scanned by the Storage Stats file scan.
		 *
		 * @since 1.10.0
		 * @param string $root Absolute path.
		 */
		return untrailingslashit( (string) apply_filters( 'opti_behavior_file_scan_root', $root ) );
	}

	/**
	 * Entries read per step.
	 *
	 * @return int
	 */
	public static function batch_size() {
		/**
		 * Entries (files + folders) read per scan step.
		 *
		 * @since 1.10.0
		 * @param int $batch Default 2000.
		 */
		return max( self::MIN_BATCH, (int) apply_filters( 'opti_behavior_file_scan_batch', self::BATCH ) );
	}

	/**
	 * Time budget of one step: a third of max_execution_time, 8 s at most.
	 *
	 * @return float Seconds.
	 */
	public static function max_seconds() {
		$limit  = (int) ini_get( 'max_execution_time' );
		$budget = 8.0;
		if ( $limit > 0 ) {
			$budget = min( $budget, max( 1.0, floor( $limit / 3 ) ) );
		}

		/**
		 * Seconds one scan step may run.
		 *
		 * @since 1.10.0
		 * @param float $budget Seconds.
		 */
		return max( 0.2, (float) apply_filters( 'opti_behavior_file_scan_max_seconds', $budget ) );
	}

	/**
	 * Last finished scan of the current root, or null.
	 *
	 * @return array|null
	 */
	public static function cached_result() {
		$result = get_transient( self::RESULT_TRANSIENT );
		if ( ! is_array( $result ) || ! isset( $result['root'] ) || self::root() !== $result['root'] ) {
			return null;
		}
		return $result;
	}

	/**
	 * Forget the cached result and any scan in progress.
	 */
	public static function clear() {
		delete_transient( self::RESULT_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );
	}

	/**
	 * Run one step of the scan.
	 *
	 * @param bool $restart Drop the cached result and the scan in progress.
	 * @return array {
	 *     status: done | running | busy | error,
	 *     result (done), progress + counts (running), cause + resumable (error)
	 * }
	 */
	public static function step( $restart = false ) {
		if ( $restart ) {
			self::clear();
		} else {
			$cached = self::cached_result();
			if ( null !== $cached ) {
				return array(
					'status' => 'done',
					'cached' => true,
					'result' => $cached,
				);
			}
		}

		if ( ! self::lock() ) {
			return array( 'status' => 'busy' );
		}

		try {
			$state = get_transient( self::STATE_TRANSIENT );
			if ( ! is_array( $state ) || ! isset( $state['root'] ) || self::root() !== $state['root'] ) {
				$state = self::new_state();
			}

			// The step before this one never finished: say why, once, and
			// resume from the last saved position with a smaller batch.
			if ( ! empty( $state['in_step'] ) ) {
				$cause = ! empty( $state['failed'] ) ? (string) $state['failed'] : 'interrupted';

				$state['in_step'] = 0;
				$state['failed']  = '';
				$state['batch']   = max( self::MIN_BATCH, (int) floor( (int) $state['batch'] / 2 ) );
				$state['errors']  = (int) $state['errors'] + 1;
				set_transient( self::STATE_TRANSIENT, $state, self::STATE_TTL );

				return array(
					'status'    => 'error',
					'cause'     => $cause,
					'resumable' => true,
					'progress'  => self::progress( $state ),
				);
			}

			if ( ! is_dir( $state['root'] ) ) {
				return self::finish( $state, false );
			}
			if ( ! is_readable( $state['root'] ) ) {
				delete_transient( self::STATE_TRANSIENT );
				return array(
					'status'    => 'error',
					'cause'     => 'permissions',
					'resumable' => false,
				);
			}

			// Saved before reading: a step that dies keeps this position.
			$state['in_step'] = time();
			set_transient( self::STATE_TRANSIENT, $state, self::STATE_TTL );
			self::watch( $state );

			$state = self::walk( $state, self::max_seconds() );

			self::$running = null;
			$state['in_step'] = 0;
			$state['steps']   = (int) $state['steps'] + 1;

			if ( empty( $state['stack'] ) && null === $state['dir'] ) {
				return self::finish( $state, true );
			}

			set_transient( self::STATE_TRANSIENT, $state, self::STATE_TTL );

			return array(
				'status'   => 'running',
				'progress' => self::progress( $state ),
				'counts'   => self::counts( $state ),
			);
		} finally {
			self::$running = null;
			self::unlock();
		}
	}

	/**
	 * Read entries until the batch or the time budget is used.
	 *
	 * Pure enough for tests: works on the given state only.
	 *
	 * @param array $state   Scan state.
	 * @param float $seconds Time budget.
	 * @return array Updated state.
	 */
	public static function walk( array $state, $seconds ) {
		$started = microtime( true );
		$left    = max( 1, (int) $state['batch'] );

		while ( $left > 0 && ( microtime( true ) - $started ) < $seconds ) {
			if ( null === $state['dir'] ) {
				if ( empty( $state['stack'] ) ) {
					break;
				}
				$state['dir']    = array_pop( $state['stack'] );
				$state['offset'] = 0;
			}

			$relative = (string) $state['dir'];
			$absolute = '' === $relative ? $state['root'] : $state['root'] . '/' . $relative;
			$handle   = @opendir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable folder is counted and reported, never a PHP warning.

			if ( false === $handle ) {
				$state['unreadable']++;
				if ( '' === $state['unreadable_example'] ) {
					$state['unreadable_example'] = $relative;
				}
				$state['dir'] = null;
				$state['dirs_done']++;
				continue;
			}

			// Skip what an earlier step already read in this folder.
			$position = 0;
			while ( $position < (int) $state['offset'] && false !== readdir( $handle ) ) {
				$position++;
			}

			$finished = true;
			while ( true ) {
				if ( $left <= 0 || ( microtime( true ) - $started ) >= $seconds ) {
					$finished = false;
					break;
				}
				$name = readdir( $handle );
				if ( false === $name ) {
					break;
				}
				$position++;
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$left--;

				$child_relative = '' === $relative ? $name : $relative . '/' . $name;
				$child          = $absolute . '/' . $name;
				$top            = '' === $relative ? ( is_dir( $child ) && ! is_link( $child ) ? $name : '' ) : strtok( $relative, '/' );

				if ( is_link( $child ) && is_dir( $child ) ) {
					continue; // Never follow a linked folder (loops, other disks).
				}
				if ( is_dir( $child ) ) {
					$state['folders']++;
					$state['stack'][] = $child_relative;
					if ( ! isset( $state['by_top'][ $top ] ) ) {
						$state['by_top'][ $top ] = 0;
					}
					continue;
				}

				$size = @filesize( $child ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- File removed between readdir and stat: skipped.
				if ( false === $size ) {
					continue;
				}
				$state['files']++;
				$state['size'] += (int) $size;
				if ( ! isset( $state['by_top'][ $top ] ) ) {
					$state['by_top'][ $top ] = 0;
				}
				$state['by_top'][ $top ] += (int) $size;
			}
			closedir( $handle );

			if ( $finished ) {
				$state['dir'] = null;
				$state['dirs_done']++;
			} else {
				$state['offset'] = $position;
			}
		}

		return $state;
	}

	/**
	 * Fresh state for the current root.
	 *
	 * @return array
	 */
	public static function new_state() {
		return array(
			'root'               => self::root(),
			'stack'              => array( '' ),
			'dir'                => null,
			'offset'             => 0,
			'files'              => 0,
			'folders'            => 0,
			'size'               => 0,
			'by_top'             => array(),
			'dirs_done'          => 0,
			'unreadable'         => 0,
			'unreadable_example' => '',
			'batch'              => self::batch_size(),
			'steps'              => 0,
			'errors'             => 0,
			'started'            => time(),
			'in_step'            => 0,
			'failed'             => '',
		);
	}

	/**
	 * Share of the folders already read, 0-99 while running.
	 *
	 * The total is not known in advance: folders found but not read yet
	 * count as left. Grows by jumps, never reaches 100 before the end.
	 *
	 * @param array $state Scan state.
	 * @return int
	 */
	public static function progress( array $state ) {
		$left = count( $state['stack'] ) + ( null === $state['dir'] ? 0 : 1 );
		$done = (int) $state['dirs_done'];
		if ( $done + $left <= 0 ) {
			return 0;
		}
		return (int) min( 99, floor( 100 * $done / ( $done + $left ) ) );
	}

	/**
	 * Counts read so far.
	 *
	 * @param array $state Scan state.
	 * @return array
	 */
	public static function counts( array $state ) {
		return array(
			'total_size'   => (int) $state['size'],
			'file_count'   => (int) $state['files'],
			'folder_count' => (int) $state['folders'],
		);
	}

	/**
	 * Explanation of a failed step, for the screen.
	 *
	 * @param string $cause timeout | memory | permissions | interrupted | fatal.
	 * @return string
	 */
	public static function cause_message( $cause ) {
		switch ( $cause ) {
			case 'timeout':
				return __( 'The server stopped the calculation: it took longer than the PHP time limit (max_execution_time). Retry continues where it stopped, in smaller steps.', 'opti-behavior' );
			case 'memory':
				return __( 'The server stopped the calculation: not enough PHP memory (memory_limit). Retry continues where it stopped, in smaller steps.', 'opti-behavior' );
			case 'permissions':
				return __( 'The data folder cannot be read: check its permissions (the web server needs read access).', 'opti-behavior' );
			case 'interrupted':
				return __( 'The server ended the request before the step finished (time limit of the server or of a proxy). Retry continues where it stopped, in smaller steps.', 'opti-behavior' );
			default:
				return __( 'A PHP error stopped the calculation. Retry continues where it stopped; if it fails again, look at the PHP error log.', 'opti-behavior' );
		}
	}

	/**
	 * Store the finished scan and drop the state.
	 *
	 * @param array $state  Scan state.
	 * @param bool  $exists Whether the folder exists.
	 * @return array
	 */
	private static function finish( array $state, $exists ) {
		$result = array_merge(
			self::counts( $state ),
			array(
				'root'               => $state['root'],
				'exists'             => (bool) $exists,
				'by_top'             => $state['by_top'],
				'unreadable'         => (int) $state['unreadable'],
				'unreadable_example' => (string) $state['unreadable_example'],
				'steps'              => (int) $state['steps'],
				'seconds'            => max( 0, time() - (int) $state['started'] ),
				'finished_at'        => time(),
			)
		);

		set_transient( self::RESULT_TRANSIENT, $result, self::RESULT_TTL );
		delete_transient( self::STATE_TRANSIENT );

		return array(
			'status' => 'done',
			'cached' => false,
			'result' => $result,
		);
	}

	/**
	 * Remember the running step so a fatal error can save its cause.
	 *
	 * @param array $state State saved before the step.
	 */
	private static function watch( array $state ) {
		self::$running = $state;
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			// WordPress's own fatal error handler runs first and ends the
			// request (wp_die): its filter is the only place we are called.
			add_filter( 'wp_should_handle_php_error', array( __CLASS__, 'on_wp_fatal' ), 10, 2 );
			// Fatal error handler turned off (WP_DISABLE_FATAL_ERROR_HANDLER).
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}
	}

	/**
	 * WordPress fatal error handler hook: save the cause, change nothing.
	 *
	 * @param bool  $handle Whether WordPress handles the error.
	 * @param array $error  error_get_last() array.
	 * @return bool
	 */
	public static function on_wp_fatal( $handle, $error ) {
		self::save_failure( $error );
		return $handle;
	}

	/**
	 * Shutdown handler: a step stopped by a fatal error saves its cause.
	 */
	public static function on_shutdown() {
		self::save_failure( error_get_last() );
	}

	/**
	 * Save the cause of the fatal error that stopped the running step.
	 *
	 * @param mixed $error error_get_last() array.
	 */
	private static function save_failure( $error ) {
		if ( null === self::$running ) {
			return;
		}
		if ( ! is_array( $error ) || ! in_array( (int) $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}

		$state           = self::$running;
		$state['failed'] = self::cause_of( (string) $error['message'] );
		self::$running   = null;
		set_transient( self::STATE_TRANSIENT, $state, self::STATE_TTL );
	}

	/**
	 * Cause key of a PHP fatal error message.
	 *
	 * @param string $message error_get_last() message.
	 * @return string
	 */
	public static function cause_of( $message ) {
		if ( false !== stripos( $message, 'Maximum execution time' ) ) {
			return 'timeout';
		}
		if ( false !== stripos( $message, 'Allowed memory size' ) || false !== stripos( $message, 'Out of memory' ) ) {
			return 'memory';
		}
		return 'fatal';
	}

	/**
	 * Take the site-wide scan lock (no wait).
	 *
	 * @return bool
	 */
	private static function lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::lock_name() ) );
	}

	/**
	 * Release the scan lock.
	 */
	private static function unlock() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::lock_name() ) );
	}

	/**
	 * Lock name of this site (several sites may share one MySQL server).
	 *
	 * @return string
	 */
	private static function lock_name() {
		global $wpdb;
		return self::LOCK . '_' . substr( md5( $wpdb->prefix . self::root() ), 0, 12 );
	}
}
