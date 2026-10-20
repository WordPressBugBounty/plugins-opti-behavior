<?php
/**
 * Country of a visitor from a local database
 *
 * The country comes from the free DB-IP Lite country database (MMDB file).
 * The file of the month ships with the plugin (assets/geo/, read in place,
 * never written): countries work right after activation, without a click
 * and without a network call. The visitor IP address is read in memory only:
 * it is never sent to an external service and never stored by this class.
 * A newer file is downloaded from download.db-ip.com into the uploads folder
 * only after the administrator asked for it (Settings > Traffic & Behavior), then once
 * a month when the monthly update is on.
 *
 * @package opti-behavior
 * @since   1.10.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Geo_Country
 *
 * Public API (also called by the Pro plugin, keep it stable):
 * lookup(), is_ready(), status(), download_now(), maybe_monthly_update().
 *
 * @since 1.10.0.1
 */
class Opti_Behavior_Geo_Country {

	/** Option: '1' once the administrator asked for the download (opt-in). */
	const OPTION_OPTIN = 'opti_behavior_geo_db_optin';

	/** Option: '1' = monthly update on, '0' = off. */
	const OPTION_MONTHLY = 'opti_behavior_geo_db_monthly';

	/** Option: array( month, last_check, last_error ). */
	const OPTION_STATUS = 'opti_behavior_geo_db_status';

	/** Option: '1' once the "Countries show Unknown" notice was closed. */
	const OPTION_NOTICE_DISMISSED = 'opti_behavior_geo_db_notice_dismissed';

	/** Daily cron hook (light check, download when the month changed). */
	const CRON_HOOK = 'opti_behavior_geo_db_check';

	/** Admin-post action and nonce name. */
	const ACTION_NAME = 'opti_behavior_geo_db';

	/** Query argument carrying the result of an action back to Settings. */
	const RESULT_ARG = 'ob_geo_db';

	/** Folder of a downloaded file, under uploads/opti-behavior-data/. Never inside the plugin. */
	const SUBDIR = 'geo';

	/** Folder of the file shipped with the plugin (read only, see tools/update-geo-db.php). */
	const BUNDLED_DIR = 'assets/geo/';

	/** Database file name. */
	const FILE_NAME = 'dbip-country-lite.mmdb';

	/** Monthly file of DB-IP, %s = YYYY-MM. */
	const DOWNLOAD_URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz';

	/** Attribution required by the CC BY 4.0 licence of the database. */
	const ATTRIBUTION_URL = 'https://db-ip.com';

	/** Download timeout in seconds. */
	const DOWNLOAD_TIMEOUT = 60;

	/** The monthly file is asked for from this day of the month. */
	const MONTHLY_FROM_DAY = 3;

	/** Largest accepted unpacked file, in bytes. */
	const MAX_FILE_BYTES = 209715200; // 200 MB.

	/** Address used to check a downloaded file, and its country. */
	const CHECK_IP      = '8.8.8.8';
	const CHECK_COUNTRY = 'US';

	/** @var Opti_Behavior_Mmdb_Reader|false|null Reader of this request (false = file missing or damaged). */
	private static $reader = null;

	/** @var string Why the reader of this request could not open the file. */
	private static $reader_error = '';

	/** @var string File behind the reader of this request: 'uploads', 'bundled' or ''. */
	private static $source = '';

	/** @var string Path of the file behind the reader of this request. */
	private static $reader_path = '';

	/** @var bool The downloaded file failed during this request: the shipped file answers. */
	private static $uploads_failed = false;

	/** @var string Why the downloaded file failed during this request. */
	private static $uploads_error = '';

	/** @var bool Hooks registered. */
	private static $hooked = false;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_monthly_update' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
			add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
			add_action( 'admin_post_' . self::ACTION_NAME, array( __CLASS__, 'handle_action' ) );
		}
	}

	/**
	 * Deactivation: the cron goes, the file and the choices stay.
	 *
	 * @return void
	 */
	public static function on_deactivation() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Public API
	 * ------------------------------------------------------------------- */

	/**
	 * Country of an IP address.
	 *
	 * Local file read, no HTTP call, nothing stored. Safe on every visit.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return string ISO 3166-1 alpha-2 code, or '' (private address, unknown address, no database).
	 */
	public static function lookup( $ip ) {
		if ( ! is_string( $ip ) || '' === $ip ) {
			return '';
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return '';
		}

		$reader = self::reader();
		if ( ! $reader ) {
			return '';
		}

		try {
			return $reader->country( $ip );
		} catch ( Throwable $e ) {
			// Damaged file: never a fatal error.
			$failed = self::$source;
			$error  = $e->getMessage();
			self::close_reader();

			// A damaged download never hides the file shipped with the plugin.
			if ( 'uploads' === $failed ) {
				self::$uploads_failed = true;
				self::$uploads_error  = $error;
				return self::lookup( $ip );
			}

			// Not retried in this request.
			self::$reader_error = $error;
			self::$reader       = false;
			return '';
		}
	}

	/**
	 * Whether a readable database is there (shipped with the plugin, or downloaded).
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return false !== self::reader();
	}

	/**
	 * State of the database for the settings screen.
	 *
	 * @return array {
	 *     @type string $state      'bundled' (file shipped with the plugin), 'installed' (newer downloaded file),
	 *                              'not_installed' or 'error'.
	 *     @type string $month      Month of the file in use, YYYY-MM ('' when unknown).
	 *     @type int    $last_check Unix time of the last download attempt or monthly check (0 = never).
	 *     @type string $last_error Last error ('' = none).
	 *     @type bool   $optin      The administrator asked for the download.
	 *     @type bool   $monthly    Monthly update on.
	 *     @type int    $size       Size of the file in bytes.
	 * }
	 */
	public static function status() {
		$stored = get_option( self::OPTION_STATUS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// The option holds an error CODE; the screen gets the translated sentence.
		$status = array(
			'state'      => 'not_installed',
			'month'      => isset( $stored['month'] ) ? (string) $stored['month'] : '',
			'last_check' => isset( $stored['last_check'] ) ? (int) $stored['last_check'] : 0,
			'last_error' => isset( $stored['last_error'] ) ? self::error_message( (string) $stored['last_error'] ) : '',
			'optin'      => self::opted_in(),
			'monthly'    => self::monthly_enabled(),
			'size'       => 0,
		);

		$reader = self::reader();
		if ( ! $reader ) {
			$status['month'] = '';

			// A file is there and cannot be read: an error, not "not installed".
			$uploads = self::uploads_path();
			$bundled = self::bundled_path();
			if ( ( '' !== $uploads && is_file( $uploads ) ) || ( '' !== $bundled && is_file( $bundled ) ) ) {
				$status['state'] = 'error';
				if ( '' === $status['last_error'] ) {
					$status['last_error'] = '' !== self::$reader_error ? self::$reader_error : self::error_message( 'unreadable' );
				}
			} elseif ( '' !== $status['last_error'] ) {
				$status['state'] = 'error';
			}
			return $status;
		}

		$status['size'] = (int) filesize( self::$reader_path );

		if ( 'bundled' === self::$source ) {
			// The stored month names a downloaded file: the shipped file says its own.
			$status['state'] = 'bundled';
			$status['month'] = self::reader_month( $reader );
			return $status;
		}

		$status['state'] = 'installed';
		if ( '' === $status['month'] ) {
			$status['month'] = self::reader_month( $reader );
		}

		return $status;
	}

	/**
	 * Download the database of the month and install it.
	 *
	 * Refused while the administrator has not opted in. Never call it during
	 * the request of a visitor: only the admin action and the cron do.
	 *
	 * @return true|WP_Error
	 */
	public static function download_now() {
		return self::run_download( true );
	}

	/**
	 * Cron: download the new monthly file when the month changed.
	 *
	 * @return bool True when a download ran and succeeded.
	 */
	public static function maybe_monthly_update() {
		if ( ! self::opted_in() || ! self::monthly_enabled() ) {
			return false;
		}

		// Month of the file in use: the shipped one, or a newer downloaded one.
		$status = self::status();
		$ready  = in_array( $status['state'], array( 'bundled', 'installed' ), true );

		if ( $ready ) {
			if ( gmdate( 'Y-m' ) === $status['month'] ) {
				return false;
			}
			if ( (int) gmdate( 'j' ) < self::MONTHLY_FROM_DAY ) {
				return false;
			}
		}

		$result = self::run_download( false );
		return true === $result;
	}

	/* ---------------------------------------------------------------------
	 * Choices
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the administrator asked for the download.
	 *
	 * @return bool
	 */
	public static function opted_in() {
		return '1' === (string) get_option( self::OPTION_OPTIN, '0' );
	}

	/**
	 * Whether the monthly update is on.
	 *
	 * @return bool
	 */
	public static function monthly_enabled() {
		return '1' === (string) get_option( self::OPTION_MONTHLY, '0' );
	}

	/**
	 * Keep the daily check scheduled while the monthly update is on.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$wanted    = self::opted_in() && self::monthly_enabled();
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $wanted && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		} elseif ( ! $wanted && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/* ---------------------------------------------------------------------
	 * Files
	 * ------------------------------------------------------------------- */

	/**
	 * Folder of the database, with a trailing slash ('' when uploads is unknown).
	 *
	 * @return string
	 */
	public static function dir_path() {
		$upload = wp_upload_dir( null, false );
		if ( empty( $upload['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'opti-behavior-data/' . self::SUBDIR . '/';

		/**
		 * Folder of the country database (tests point it to a sandbox).
		 *
		 * @since 1.10.0.1
		 * @param string $dir Absolute path with a trailing slash.
		 */
		$filtered = apply_filters( 'opti_behavior_geo_db_dir', $dir );

		return is_string( $filtered ) && '' !== $filtered ? trailingslashit( $filtered ) : $dir;
	}

	/**
	 * Forget the reader of this request (tests, after the folder changed).
	 *
	 * @return void
	 */
	public static function reset() {
		self::close_reader();
		self::$reader_error   = '';
		self::$uploads_failed = false;
		self::$uploads_error  = '';
	}

	/**
	 * Path of the file shipped with the plugin (assets/geo/, read only).
	 *
	 * @return string '' when a filter removed it.
	 */
	public static function bundled_path() {
		$path = dirname( __DIR__ ) . '/' . self::BUNDLED_DIR . self::FILE_NAME;

		/**
		 * File shipped with the plugin (tests point it to a sandbox, '' = no shipped file).
		 *
		 * @since 1.10.0.1
		 * @param string $path Absolute path.
		 */
		$filtered = apply_filters( 'opti_behavior_geo_bundled_path', $path );

		return is_string( $filtered ) ? $filtered : $path;
	}

	/**
	 * Path of a downloaded file (uploads/opti-behavior-data/geo/).
	 *
	 * @return string '' when uploads is unknown.
	 */
	public static function uploads_path() {
		$dir = self::dir_path();
		return '' === $dir ? '' : $dir . self::FILE_NAME;
	}

	/**
	 * Path of the database file in use: the downloaded file when it opens and
	 * is not older than the shipped one, else the shipped file. With no
	 * readable file: where a download would go.
	 *
	 * @return string
	 */
	public static function file_path() {
		if ( self::reader() && '' !== self::$reader_path ) {
			return self::$reader_path;
		}

		return self::uploads_path();
	}

	/**
	 * Reader of this request, opened once.
	 *
	 * The downloaded file wins only when it opens AND is not older than the
	 * file shipped with the plugin (a plugin update can bring a newer one).
	 * A damaged download falls back to the shipped file.
	 *
	 * @return Opti_Behavior_Mmdb_Reader|false
	 */
	private static function reader() {
		if ( null !== self::$reader ) {
			return self::$reader;
		}

		self::$reader       = false;
		self::$reader_error = '';
		self::$source       = '';
		self::$reader_path  = '';

		// With no shipped file to answer, the screen still gets the real reason.
		$errors  = '' !== self::$uploads_error ? array( self::$uploads_error ) : array();
		$bundled = false;
		try {
			$bundled_path = self::bundled_path();
			$bundled      = self::open_file( $bundled_path, $errors );
			$uploads_path = self::$uploads_failed ? '' : self::uploads_path();
			$uploads      = self::open_file( $uploads_path, $errors );
		} catch ( Throwable $e ) {
			// A path filter failed: nothing is known, so nothing is kept for the
			// next call (a kept "no database" would queue a download).
			if ( $bundled instanceof Opti_Behavior_Mmdb_Reader ) {
				$bundled->close();
			}
			self::$reader = null;
			throw $e;
		}

		// With a shipped file to fall back on, the download must be at least as
		// recent and still know the check address (a file can open and be damaged).
		if ( $uploads && $bundled && ( self::reader_epoch( $uploads ) < self::reader_epoch( $bundled ) || ! self::passes_check( $uploads ) ) ) {
			$uploads->close();
			$uploads = false;
		}

		if ( $uploads ) {
			if ( $bundled ) {
				$bundled->close();
			}
			self::$reader      = $uploads;
			self::$source      = 'uploads';
			self::$reader_path = $uploads_path;
		} elseif ( $bundled ) {
			self::$reader      = $bundled;
			self::$source      = 'bundled';
			self::$reader_path = $bundled_path;
		} elseif ( $errors ) {
			self::$reader_error = (string) end( $errors );
		}

		return self::$reader;
	}

	/**
	 * Open one database file.
	 *
	 * @param string   $path   File path ('' = none).
	 * @param string[] $errors Collects the reason when a file is there and cannot be read.
	 * @return Opti_Behavior_Mmdb_Reader|false
	 */
	private static function open_file( $path, &$errors ) {
		if ( '' === $path || ! is_file( $path ) ) {
			return false;
		}

		try {
			return new Opti_Behavior_Mmdb_Reader( $path );
		} catch ( Throwable $e ) {
			$errors[] = $e->getMessage();
			return false;
		}
	}

	/**
	 * Whether an open database gives the known country of the check address.
	 *
	 * @param Opti_Behavior_Mmdb_Reader $reader Open reader.
	 * @return bool
	 */
	private static function passes_check( $reader ) {
		try {
			return self::CHECK_COUNTRY === $reader->country( self::CHECK_IP );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Build time of an open database (0 when the file does not say).
	 *
	 * @param Opti_Behavior_Mmdb_Reader $reader Open reader.
	 * @return int Unix time.
	 */
	private static function reader_epoch( $reader ) {
		$metadata = $reader->metadata();
		return isset( $metadata['build_epoch'] ) ? max( 0, (int) $metadata['build_epoch'] ) : 0;
	}

	/**
	 * Month of an open database, from its own build time (the one source).
	 *
	 * @param Opti_Behavior_Mmdb_Reader $reader Open reader.
	 * @return string YYYY-MM, '' when the file does not say.
	 */
	private static function reader_month( $reader ) {
		$epoch = self::reader_epoch( $reader );
		return $epoch > 0 ? gmdate( 'Y-m', $epoch ) : '';
	}

	/**
	 * Close the reader of this request (before the file is replaced).
	 *
	 * @return void
	 */
	private static function close_reader() {
		if ( self::$reader instanceof Opti_Behavior_Mmdb_Reader ) {
			self::$reader->close();
		}
		self::$reader      = null;
		self::$source      = '';
		self::$reader_path = '';
	}

	/**
	 * Create the folder, closed to the web.
	 *
	 * @return bool
	 */
	private static function ensure_dir() {
		$dir = self::dir_path();
		if ( '' === $dir ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Two tiny guard files in the plugin's own uploads folder.
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', '' );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return wp_is_writable( $dir );
	}

	/**
	 * Delete a temporary file.
	 *
	 * @param string $path File path.
	 * @return void
	 */
	private static function remove_file( $path ) {
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/* ---------------------------------------------------------------------
	 * Download
	 * ------------------------------------------------------------------- */

	/**
	 * User agent of the download: the plugin and its version, never the site address.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'Opti-Behavior/' . ( defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '0' );
	}

	/**
	 * Months to ask for, newest first: this month, then the previous one
	 * (the file of a new month is not there on its first days).
	 *
	 * @param int|null $now Unix time (tests).
	 * @return string[]
	 */
	public static function candidate_months( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$first = gmmktime( 0, 0, 0, (int) gmdate( 'n', $now ), 1, (int) gmdate( 'Y', $now ) );

		return array( gmdate( 'Y-m', $first ), gmdate( 'Y-m', $first - DAY_IN_SECONDS ) );
	}

	/**
	 * Download, unpack, check, then replace the file.
	 *
	 * @param bool $force True = the admin clicked: always download. False = cron:
	 *                    stop when the newest file on the server is the installed one.
	 * @return true|WP_Error
	 */
	private static function run_download( $force ) {
		if ( ! self::opted_in() ) {
			return new WP_Error( 'opti_behavior_geo_optin', 'The country database is downloaded only after the administrator asks for it.' );
		}
		if ( ! function_exists( 'gzopen' ) ) {
			return self::record_failure( 'no_zlib' );
		}
		if ( ! self::ensure_dir() ) {
			return self::record_failure( 'dir_not_writable' );
		}

		$stored    = get_option( self::OPTION_STATUS, array() );
		$installed = is_array( $stored ) && isset( $stored['month'] ) ? (string) $stored['month'] : '';
		$dir       = self::dir_path();
		$token     = wp_generate_password( 12, false );
		$gz_tmp    = $dir . 'download-' . $token . '.gz.tmp';
		$db_tmp    = $dir . 'download-' . $token . '.mmdb.tmp';
		$error     = 'download_failed';

		foreach ( self::candidate_months() as $month ) {
			// Cron only: the newest file on the server is already installed.
			if ( ! $force && $month === $installed && is_file( self::uploads_path() ) ) {
				self::save_status( array( 'last_check' => time(), 'last_error' => '' ) );
				return true;
			}

			$response = wp_remote_get(
				sprintf( self::DOWNLOAD_URL, $month ),
				array(
					'timeout'     => self::DOWNLOAD_TIMEOUT,
					'redirection' => 3,
					'stream'      => true,
					'filename'    => $gz_tmp,
					'user-agent'  => self::user_agent(),
				)
			);

			if ( is_wp_error( $response ) ) {
				$error = 'http_error:' . $response->get_error_message();
				self::remove_file( $gz_tmp );
				break;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 404 === $code ) {
				// Not published yet (first days of the month): try the previous month.
				$error = 'not_published';
				self::remove_file( $gz_tmp );
				continue;
			}
			if ( 200 !== $code ) {
				$error = 'http_status:' . $code;
				self::remove_file( $gz_tmp );
				break;
			}

			$unpacked = self::gunzip( $gz_tmp, $db_tmp );
			self::remove_file( $gz_tmp );
			if ( true !== $unpacked ) {
				$error = $unpacked;
				self::remove_file( $db_tmp );
				break;
			}

			$checked = self::check_file( $db_tmp );
			if ( true !== $checked ) {
				$error = $checked;
				self::remove_file( $db_tmp );
				break;
			}

			if ( ! self::replace_file( $db_tmp ) ) {
				$error = 'replace_failed';
				self::remove_file( $db_tmp );
				break;
			}

			self::save_status(
				array(
					'month'      => $month,
					'last_check' => time(),
					'last_error' => '',
				)
			);
			return true;
		}

		return self::record_failure( $error );
	}

	/**
	 * Unpack a .gz file.
	 *
	 * @param string $source Packed file.
	 * @param string $target Unpacked file.
	 * @return true|string True, or the error code.
	 */
	private static function gunzip( $source, $target ) {
		if ( ! is_file( $source ) || filesize( $source ) < 1024 ) {
			return 'empty_file';
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streamed gunzip of a multi-megabyte file; WP_Filesystem would load it in memory.
		$in = gzopen( $source, 'rb' );
		if ( ! $in ) {
			return 'gz_open';
		}
		$out = fopen( $target, 'wb' );
		if ( ! $out ) {
			gzclose( $in );
			return 'write_failed';
		}

		$written = 0;
		$ok      = true;
		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, 524288 );
			if ( false === $chunk ) {
				$ok = false;
				break;
			}
			if ( '' === $chunk ) {
				break;
			}
			$bytes = fwrite( $out, $chunk );
			if ( false === $bytes || strlen( $chunk ) !== $bytes ) {
				$ok = false;
				break;
			}
			$written += $bytes;
			if ( $written > self::MAX_FILE_BYTES ) {
				$ok = false;
				break;
			}
		}

		gzclose( $in );
		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! $ok || $written < 1024 ) {
			return 'unpack_failed';
		}

		return true;
	}

	/**
	 * Check an unpacked file: the reader opens it and a known address gives its country.
	 *
	 * @param string $path Unpacked file.
	 * @return true|string True, or the error code.
	 */
	private static function check_file( $path ) {
		try {
			$reader  = new Opti_Behavior_Mmdb_Reader( $path );
			$country = $reader->country( self::CHECK_IP );
			$reader->close();
		} catch ( Throwable $e ) {
			return 'invalid_db';
		}

		if ( self::CHECK_COUNTRY !== $country ) {
			return 'country_check';
		}

		return true;
	}

	/**
	 * Put a checked file in place of the installed one.
	 *
	 * @param string $new_file Checked temporary file.
	 * @return bool
	 */
	private static function replace_file( $new_file ) {
		// Always the uploads folder: the plugin folder is never written.
		$final = self::uploads_path();
		if ( '' === $final ) {
			return false;
		}

		// Windows cannot replace a file that is open.
		self::close_reader();

		$moved = self::move_file( $new_file, $final );
		if ( ! $moved && is_file( $final ) ) {
			// Some file systems refuse to rename over an existing file. The old
			// file steps aside (never deleted first) and comes back when the new
			// one cannot take its place.
			$backup = $final . '.old';
			self::remove_file( $backup );
			if ( self::move_file( $final, $backup ) ) {
				$moved = self::move_file( $new_file, $final );
				if ( $moved ) {
					self::remove_file( $backup );
				} else {
					self::move_file( $backup, $final );
				}
			}
		}

		clearstatcache( true, $final );
		return $moved;
	}

	/**
	 * Rename a file inside the folder of the database.
	 *
	 * @param string $from Current path.
	 * @param string $to   New path.
	 * @return bool
	 */
	private static function move_file( $from, $to ) {
		/**
		 * Short-circuit a rename (tests: a file system that refuses it).
		 *
		 * @param bool|null $pre  Null = really rename, anything else = that result.
		 * @param string    $from Current path.
		 * @param string    $to   New path.
		 */
		$pre = apply_filters( 'opti_behavior_geo_db_pre_rename', null, $from, $to );
		if ( null !== $pre ) {
			return (bool) $pre;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic move inside one folder of uploads; a failure is reported to the caller.
		return @rename( $from, $to );
	}

	/**
	 * Merge values into the stored status.
	 *
	 * @param array $values Values to write.
	 * @return void
	 */
	private static function save_status( $values ) {
		$stored = get_option( self::OPTION_STATUS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		update_option( self::OPTION_STATUS, array_merge( $stored, $values ), false );
	}

	/**
	 * Keep the installed file, remember the error.
	 *
	 * @param string $code Error code, see error_message() ("code" or "code:detail").
	 * @return WP_Error
	 */
	private static function record_failure( $code ) {
		self::save_status(
			array(
				'last_check' => time(),
				'last_error' => (string) $code,
			)
		);

		return new WP_Error( 'opti_behavior_geo_download', self::error_message( (string) $code ) );
	}

	/**
	 * Sentence of a stored error, in the language of the screen.
	 *
	 * The option keeps a code ("http_status:503"), never a sentence. A sentence
	 * stored by an older version is returned as it is.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function error_message( $stored ) {
		$stored = (string) $stored;
		$parts  = explode( ':', $stored, 2 );
		$detail = isset( $parts[1] ) ? trim( $parts[1] ) : '';

		switch ( $parts[0] ) {
			case 'no_zlib':
				return __( 'The zlib extension of PHP is missing: the file cannot be unpacked.', 'opti-behavior' );
			case 'dir_not_writable':
				return __( 'The folder of the country database cannot be created or written.', 'opti-behavior' );
			case 'download_failed':
				return __( 'The country database could not be downloaded.', 'opti-behavior' );
			case 'http_error':
				if ( '' === $detail ) {
					return __( 'The country database could not be downloaded.', 'opti-behavior' );
				}
				/* translators: %s: technical error message of the HTTP request */
				return sprintf( __( 'Download failed: %s', 'opti-behavior' ), $detail );
			case 'http_status':
				/* translators: %s: HTTP status code, for example 503 */
				return sprintf( __( 'Download failed: the server answered %s.', 'opti-behavior' ), $detail );
			case 'not_published':
				return __( 'The file of the month is not available yet.', 'opti-behavior' );
			case 'empty_file':
				return __( 'The downloaded file is empty.', 'opti-behavior' );
			case 'gz_open':
				return __( 'The downloaded file cannot be opened.', 'opti-behavior' );
			case 'write_failed':
				return __( 'The unpacked file cannot be written.', 'opti-behavior' );
			case 'unpack_failed':
				return __( 'The downloaded file could not be unpacked.', 'opti-behavior' );
			case 'invalid_db':
				return __( 'The downloaded file is not a valid database.', 'opti-behavior' );
			case 'country_check':
				return __( 'The downloaded file failed the country check.', 'opti-behavior' );
			case 'replace_failed':
				return __( 'The new file could not replace the old one. The old file was kept.', 'opti-behavior' );
			case 'unreadable':
				return __( 'The database file cannot be read.', 'opti-behavior' );
		}

		return $stored;
	}

	/* ---------------------------------------------------------------------
	 * Admin: actions, settings block, notice, attribution
	 * ------------------------------------------------------------------- */

	/**
	 * Nonce-protected admin-post URL of an action.
	 *
	 * @param string $do download, monthly_on, monthly_off or dismiss.
	 * @return string
	 */
	public static function action_url( $do ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_NAME,
					'do'     => sanitize_key( $do ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_NAME
		);
	}

	/**
	 * URL of the "Country database" block of Settings > Traffic & Behavior.
	 *
	 * @param string $result Optional result flag.
	 * @return string
	 */
	public static function settings_url( $result = '' ) {
		$url = admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=traffic-behavior' );
		if ( '' !== $result ) {
			$url = add_query_arg( self::RESULT_ARG, sanitize_key( $result ), $url );
		}

		return $url . '#ob-geo-db-setting';
	}

	/**
	 * Handle the admin actions (nonce + manage_options).
	 *
	 * @return void
	 */
	public static function handle_action() {
		check_admin_referer( self::ACTION_NAME );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'opti-behavior' ),
				esc_html__( 'Unauthorized', 'opti-behavior' ),
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';

		switch ( $do ) {
			case 'download':
				// The click is the consent: from here the plugin may contact download.db-ip.com.
				// One download only: the monthly update has its own checkbox, off by default.
				update_option( self::OPTION_OPTIN, '1', false );
				self::maybe_schedule();

				ignore_user_abort( true );
				if ( function_exists( 'set_time_limit' ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- One download of a few megabytes started by the administrator.
					@set_time_limit( 180 );
				}

				$result = self::download_now();
				wp_safe_redirect( self::settings_url( true === $result ? 'ok' : 'error' ) );
				exit;

			case 'monthly_on':
				// Ticking the box is the consent to the monthly download.
				update_option( self::OPTION_OPTIN, '1', false );
				update_option( self::OPTION_MONTHLY, '1', false );
				self::maybe_schedule();
				wp_safe_redirect( self::settings_url( 'saved' ) );
				exit;

			case 'monthly_off':
				update_option( self::OPTION_MONTHLY, '0', false );
				self::maybe_schedule();
				wp_safe_redirect( self::settings_url( 'saved' ) );
				exit;

			case 'dismiss':
				update_option( self::OPTION_NOTICE_DISMISSED, '1', false );
				break;
		}

		$redirect = wp_get_referer();
		wp_safe_redirect( $redirect ? $redirect : admin_url() );
		exit;
	}

	/**
	 * "IP Geolocation by DB-IP" line (CC BY 4.0). Shown on screen only in the
	 * "Country database" block of the settings (OPT-1564: readme + NOTICE.txt
	 * carry the licence credit; no other screen, report or e-mail prints it).
	 *
	 * @param string $align 'right' or 'left'.
	 * @return string HTML, '' while no database is installed.
	 */
	public static function attribution_html( $align = 'right' ) {
		if ( ! self::is_ready() ) {
			return '';
		}

		return '<p class="ob-geo-attribution" style="margin:8px 0 0;font-size:11px;color:#6b7280;text-align:' . ( 'left' === $align ? 'left' : 'right' ) . ';">'
			. '<a href="' . esc_url( self::ATTRIBUTION_URL ) . '" target="_blank" rel="noopener noreferrer" style="color:inherit;">'
			. esc_html__( 'IP Geolocation by DB-IP', 'opti-behavior' )
			. '</a></p>';
	}

	/**
	 * "Country database" block, at the bottom of Settings > Traffic & Behavior.
	 *
	 * @return void
	 */
	public static function render_settings_block() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = self::status();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag of a nonce-checked action.
		$result = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : '';

		$has_database = in_array( $status['state'], array( 'bundled', 'installed' ), true );

		if ( 'bundled' === $status['state'] ) {
			$state_label = '' !== $status['month']
				/* translators: %s: month of the database file, for example 2026-10 */
				? sprintf( __( 'Included with the plugin (month %s)', 'opti-behavior' ), $status['month'] )
				: __( 'Included with the plugin', 'opti-behavior' );
		} elseif ( 'installed' === $status['state'] ) {
			$state_label = '' !== $status['month']
				/* translators: %s: month of the database file, for example 2026-10 */
				? sprintf( __( 'Installed, month %s', 'opti-behavior' ), $status['month'] )
				: __( 'Installed', 'opti-behavior' );
		} elseif ( 'error' === $status['state'] ) {
			$state_label = __( 'Error', 'opti-behavior' );
		} else {
			$state_label = __( 'Not installed', 'opti-behavior' );
		}
		// Card look (OPT-912): assets/css/settings.css "Country database card". The state is said by words, the colour only follows.
		$tone = $has_database ? 'ok' : ( 'error' === $status['state'] ? 'bad' : 'warn' );
		?>
		<div class="settings-section" id="ob-geo-db-setting">
			<div class="ob-geo-db-card is-<?php echo esc_attr( $tone ); ?>">
				<div class="ob-geo-db-head">
					<span class="ob-geo-db-icon" aria-hidden="true"><i data-lucide="globe"></i></span>
					<h3 class="ob-geo-db-title"><?php esc_html_e( 'Country database', 'opti-behavior' ); ?></h3>
					<p class="ob-geo-db-state">
						<?php
						/* translators: %s: state of the country database: "Included with the plugin (month 2026-10)", "Installed, month 2026-11", "Not installed" or "Error" */
						printf( esc_html__( 'Status: %s', 'opti-behavior' ), '<b>' . esc_html( $state_label ) . '</b>' );
						?>
					</p>
				</div>

				<?php if ( 'ok' === $result ) : ?>
					<p class="ob-geo-db-result is-ok" role="status"><b><?php esc_html_e( 'The country database is installed.', 'opti-behavior' ); ?></b></p>
				<?php elseif ( 'error' === $result ) : ?>
					<p class="ob-geo-db-result is-bad" role="alert"><b><?php esc_html_e( 'The download failed. The previous file, if any, was kept.', 'opti-behavior' ); ?></b></p>
				<?php elseif ( 'saved' === $result ) : ?>
					<p class="ob-geo-db-result is-ok" role="status"><b><?php esc_html_e( 'Saved.', 'opti-behavior' ); ?></b></p>
				<?php endif; ?>

				<?php if ( '' !== $status['last_error'] ) : ?>
					<p class="ob-geo-db-error">
						<?php
						/* translators: %s: technical error message of the last download */
						printf( esc_html__( 'Last error: %s', 'opti-behavior' ), esc_html( $status['last_error'] ) );
						?>
					</p>
				<?php endif; ?>

				<p class="description ob-geo-db-text">
					<?php if ( $has_database ) : ?>
						<?php esc_html_e( 'The free DB-IP Lite country database is included with the plugin and renewed with each plugin version: countries work without any download.', 'opti-behavior' ); ?>
						<?php esc_html_e( '"Update now" downloads the newest file (from download.db-ip.com, stored on your server; no visitor data is sent).', 'opti-behavior' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Download the free DB-IP Lite country database (from download.db-ip.com, stored on your server; no visitor data is sent).', 'opti-behavior' ); ?>
						<?php esc_html_e( 'Without it, and without Cloudflare, countries show "Unknown".', 'opti-behavior' ); ?>
					<?php endif; ?>
				</p>

				<div class="ob-geo-db-actions">
					<a class="button <?php echo $has_database ? 'button-secondary' : 'button-primary'; ?>" id="ob-geo-db-download" href="<?php echo esc_url( self::action_url( 'download' ) ); ?>">
						<?php echo $has_database ? esc_html__( 'Update now', 'opti-behavior' ) : esc_html__( 'Download now', 'opti-behavior' ); ?>
					</a>

					<?php // Off by default: the monthly download starts only when the administrator ticks the box (WordPress.org guideline 7). ?>
					<?php // A switch: assets/js/settings.js opens data-url (the nonce link of the action) when it changes. ?>
					<label class="ob-geo-db-monthly">
						<input type="checkbox" id="ob-geo-db-monthly" <?php checked( $status['optin'] && $status['monthly'] ); ?>
							data-url="<?php echo esc_url( self::action_url( $status['optin'] && $status['monthly'] ? 'monthly_off' : 'monthly_on' ) ); ?>"
							class="ob-geo-db-switch-input" role="switch" />
						<span class="ob-geo-db-switch" aria-hidden="true"></span>
						<span class="ob-geo-db-monthly-text"><?php esc_html_e( 'Check for a newer database every month (downloads from download.db-ip.com, no visitor data sent)', 'opti-behavior' ); ?></span>
					</label>
				</div>

				<p class="ob-geo-db-attribution">
					<a href="<?php echo esc_url( self::ATTRIBUTION_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'IP Geolocation by DB-IP', 'opti-behavior' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Whether the "Countries show Unknown" notice has something to say on this
	 * screen (every Opti-Behavior screen, the Pro ones included; never the
	 * welcome screen), before the "one banner at a time" rule.
	 *
	 * @return bool
	 */
	public static function notice_wanted() {
		if ( ! is_admin() || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( class_exists( 'Opti_Behavior_Review_Banner' ) ) {
			$on_plugin_screen = Opti_Behavior_Review_Banner::is_plugin_screen();
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
			$page             = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			$on_plugin_screen = 0 === strpos( $page, 'opti-behavior' ) && 'opti-behavior-welcome' !== $page;
		}
		if ( ! $on_plugin_screen ) {
			return false;
		}

		if ( self::is_ready() || '1' === (string) get_option( self::OPTION_NOTICE_DISMISSED, '' ) ) {
			return false;
		}

		// Behind Cloudflare the country comes from its header: nothing shows "Unknown".
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return false;
		}

		if ( class_exists( 'Opti_Behavior_Welcome' ) && ! Opti_Behavior_Welcome::has_made_choice() ) {
			return false;
		}

		return true;
	}

	/**
	 * Print the notice: one Opti-Behavior banner at a time.
	 *
	 * The first download waits for the administrator (WordPress.org guideline 7):
	 * the notice says what is downloaded and its button is that click.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! self::notice_wanted() ) {
			return;
		}
		if ( class_exists( 'Opti_Behavior_Review_Banner' ) && Opti_Behavior_Review_Banner::other_banner_visible() ) {
			return;
		}
		?>
		<?php // inline + opti-behavior-notice: the plugin screens hide every other notice (admin-notices.css). ?>
		<div class="notice notice-info inline opti-behavior-notice ob-geo-db-notice" role="status">
			<?php // admin-notices.css forces display:block !important on .opti-behavior-notice, so the flex row is the inner <p> (look: same file, "Country database notice"). ?>
			<p class="ob-geo-db-notice-row">
				<span class="ob-geo-db-notice-text"><?php esc_html_e( 'Countries show "Unknown" until you install the free country database (DB-IP Lite, about 4 MB, stored on your server, no visitor data sent).', 'opti-behavior' ); ?></span>
				<a class="button button-primary" id="ob-geo-db-notice-download" href="<?php echo esc_url( self::action_url( 'download' ) ); ?>"><?php esc_html_e( 'Download now', 'opti-behavior' ); ?></a>
				<a class="button-link ob-geo-db-notice-dismiss" href="<?php echo esc_url( self::action_url( 'dismiss' ) ); ?>"><?php esc_html_e( 'Dismiss', 'opti-behavior' ); ?></a>
			</p>
		</div>
		<?php
	}
}
