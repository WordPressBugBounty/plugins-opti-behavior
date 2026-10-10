<?php
/**
 * Storage Health
 *
 * Tells the site owner when Opti-Behavior cannot write its data files
 * (heatmap clicks / scrolls, session recordings) under
 * wp-content/uploads/opti-behavior-data/.
 *
 * - On activation the folder is probed (create + write a test file).
 * - Every storage write path calls report_failure() when a folder cannot be
 *   created or a file cannot be written. The failure is stored once per hour
 *   at most in a non-autoloaded option, so a failing site costs one option
 *   read per failed write and no write per request.
 * - Admins see one error notice (plugin screens + Plugins screen) that says
 *   what is lost, which folder is blocked, how to fix it, and links to
 *   support. "Check again" re-probes; the notice clears itself as soon as the
 *   folder is writable again.
 * - The "Setup check" card on How it works gets a red row while it lasts.
 *
 * Replaces the English-only `opti_behavior_file_storage_error` transient that
 * only Pro displayed (and only for recordings).
 *
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Storage_Health
 */
class Opti_Behavior_Storage_Health {

	/** Option: last failure { time, path, what }. Non-autoloaded. */
	const OPTION = 'opti_behavior_storage_error';

	/** Transient: cached probe result used by the admin notice. */
	const PROBE_TRANSIENT = 'opti_behavior_storage_probe';

	/** A same-kind failure is stored at most once per hour. */
	const REPORT_THROTTLE = HOUR_IN_SECONDS;

	/** How long the admin notice trusts its last probe. */
	const PROBE_TTL = 10 * MINUTE_IN_SECONDS;

	/** admin-post action + nonce action of the "Check again" button. */
	const ACTION_NAME = 'opti_behavior_storage_check';

	/** Query arg carrying the "Check again" result. */
	const DONE_ARG = 'ob_storage';

	/** Support page. */
	const SUPPORT_URL = 'https://optiuser.com/contact-support/';

	/** @var bool Hooks registered. */
	private static $initialized = false;

	/** @var bool Failure already handled in this request. */
	private static $reported = false;

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$initialized || ! is_admin() ) {
			return;
		}
		self::$initialized = true;
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION_NAME, array( __CLASS__, 'handle_check' ) );
	}

	/**
	 * Absolute path of the data folder, with a trailing slash.
	 *
	 * @return string
	 */
	public static function base_dir() {
		$upload_dir = wp_upload_dir( null, false );
		return trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';
	}

	/**
	 * Check that WordPress can create and write files in a folder.
	 *
	 * A missing folder is judged on its nearest existing parent (that is where
	 * it would be created). Writes and deletes one hidden test file.
	 *
	 * @param string $dir Folder to check. Default: the data folder.
	 * @return array { ok: bool, path: string } path = the folder that failed.
	 */
	public static function probe( $dir = '' ) {
		$dir = '' === $dir ? self::base_dir() : trailingslashit( $dir );

		$existing = $dir;
		while ( ! is_dir( $existing ) ) {
			$parent = trailingslashit( dirname( $existing ) );
			// A file standing where a folder must be created blocks it.
			if ( $parent === $existing || is_file( untrailingslashit( $existing ) ) ) {
				return array(
					'ok'   => false,
					'path' => $dir,
				);
			}
			$existing = $parent;
		}

		if ( ! wp_is_writable( $existing ) ) {
			return array(
				'ok'   => false,
				'path' => $dir,
			);
		}

		$test = $existing . '.ob-write-test-' . wp_generate_password( 8, false, false );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Write probe; failure is the answer.
		$written = @file_put_contents( $test, '1' );
		if ( false === $written ) {
			return array(
				'ok'   => false,
				'path' => $dir,
			);
		}
		wp_delete_file( $test );

		return array(
			'ok'   => true,
			'path' => $dir,
		);
	}

	/**
	 * Record a failed folder creation or file write.
	 *
	 * @param string $path File or folder that could not be written.
	 * @param string $what 'heatmaps', 'recordings' or 'folder'.
	 * @return void
	 */
	public static function report_failure( $path, $what ) {
		if ( self::$reported ) {
			return;
		}
		self::$reported = true;

		$what    = in_array( $what, array( 'heatmaps', 'recordings', 'folder' ), true ) ? $what : 'folder';
		$current = get_option( self::OPTION, null );
		if ( is_array( $current ) && isset( $current['time'], $current['what'] )
			&& $what === $current['what'] && time() - (int) $current['time'] < self::REPORT_THROTTLE ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'time' => time(),
				'path' => (string) $path,
				'what' => $what,
			),
			false
		);
		delete_transient( self::PROBE_TRANSIENT );
	}

	/**
	 * Activation: probe the data folder, remember a failure.
	 *
	 * @return void
	 */
	public static function on_activation() {
		$result = self::probe();
		if ( $result['ok'] ) {
			delete_option( self::OPTION );
		} else {
			self::report_failure( $result['path'], 'folder' );
		}
	}

	/**
	 * Folder that must be writable to clear a stored failure.
	 *
	 * @param array $error Stored failure.
	 * @return string
	 */
	private static function failed_dir( $error ) {
		$path = isset( $error['path'] ) ? (string) $error['path'] : '';
		$base = self::base_dir();
		if ( '' === $path || 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $base ) ) ) {
			return $base;
		}
		// A file path is judged on its folder.
		return '/' === substr( $path, -1 ) ? $path : trailingslashit( dirname( $path ) );
	}

	/**
	 * The stored failure, or null when there is none or the folder works again.
	 *
	 * @param bool $fresh Ignore the cached probe.
	 * @return array|null { time, path, what }
	 */
	public static function current_error( $fresh = false ) {
		$error = get_option( self::OPTION, null );
		if ( ! is_array( $error ) || empty( $error['time'] ) ) {
			return null;
		}

		$state = $fresh ? false : get_transient( self::PROBE_TRANSIENT );
		if ( 'ok' !== $state && 'bad' !== $state ) {
			$base  = self::probe();
			$state = ( $base['ok'] && self::probe( self::failed_dir( $error ) )['ok'] ) ? 'ok' : 'bad';
			set_transient( self::PROBE_TRANSIENT, $state, self::PROBE_TTL );
		}

		if ( 'ok' === $state ) {
			delete_option( self::OPTION );
			return null;
		}
		return $error;
	}

	/**
	 * What the site loses, in one sentence.
	 *
	 * @param string $what Stored failure kind.
	 * @return string
	 */
	public static function impact_text( $what ) {
		if ( 'heatmaps' === $what ) {
			return __( 'Heatmap clicks and scrolls are not saved.', 'opti-behavior' );
		}
		if ( 'recordings' === $what ) {
			return __( 'Session recordings are saved in the database instead, which makes it grow faster.', 'opti-behavior' );
		}
		return __( 'Heatmaps and session recordings cannot be saved.', 'opti-behavior' );
	}

	/**
	 * Support link with the same UTM tags as the How it works page.
	 *
	 * @return string
	 */
	public static function support_url() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return add_query_arg(
			array(
				'utm_source'   => $host ? $host : 'wordpress',
				'utm_medium'   => 'opti-behavior-plugin',
				'utm_campaign' => 'storage-error',
			),
			self::SUPPORT_URL
		);
	}

	/**
	 * Whether the current admin screen shows the notice.
	 *
	 * @return bool
	 */
	private static function notice_screen() {
		global $pagenow;
		if ( 'plugins.php' === $pagenow || 'index.php' === $pagenow ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return 0 === strpos( $page, 'opti-behavior' ) && 'opti-behavior-welcome' !== $page;
	}

	/**
	 * Print the error notice, or the "Check again" result.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::notice_screen() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only result after a nonce-checked action.
		$done = isset( $_GET[ self::DONE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::DONE_ARG ] ) ) : '';
		if ( 'ok' === $done ) {
			printf( '<div class="notice notice-success opti-behavior-notice"><p style="margin:0">%s</p></div>', esc_html__( 'Fixed: Opti-Behavior can save its files again.', 'opti-behavior' ) );
			return;
		}

		$error = self::current_error();
		if ( null === $error ) {
			return;
		}

		$folder = self::failed_dir( $error );
		$check  = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_NAME ), self::ACTION_NAME );
		?>
		<?php // opti-behavior-notice: the plugin screens hide every other notice (admin-notices.css + notice-cleanup.js). ?>
		<div class="notice notice-error opti-behavior-notice ob-storage-notice" role="alert">
			<p><strong><?php esc_html_e( 'Opti-Behavior cannot save its data files.', 'opti-behavior' ); ?></strong>
				<?php echo esc_html( self::impact_text( $error['what'] ) ); ?></p>
			<p>
				<?php esc_html_e( 'WordPress is not allowed to write in this folder:', 'opti-behavior' ); ?>
				<code><?php echo esc_html( wp_normalize_path( $folder ) ); ?></code>
			</p>
			<p><?php esc_html_e( 'To fix it, ask your hosting provider to make this folder writable by WordPress (permission 755). Still blocked? Contact us, we will help you.', 'opti-behavior' ); ?></p>
			<?php if ( 'bad' === $done ) : ?>
				<p><em><?php esc_html_e( 'Checked again just now: the folder is still blocked.', 'opti-behavior' ); ?></em></p>
			<?php endif; ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $check ); ?>"><?php esc_html_e( 'Check again', 'opti-behavior' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::support_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Contact support', 'opti-behavior' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'opti-behavior' ); ?></span></a>
			</p>
		</div>
		<?php
	}

	/**
	 * "Check again": re-probe now, go back with the result.
	 *
	 * @return void
	 */
	public static function handle_check() {
		check_admin_referer( self::ACTION_NAME );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'opti-behavior' ),
				esc_html__( 'Unauthorized', 'opti-behavior' ),
				array( 'response' => 403 )
			);
		}

		delete_transient( self::PROBE_TRANSIENT );
		$error = get_option( self::OPTION, null );
		if ( ! is_array( $error ) ) {
			$probe = self::probe();
			if ( ! $probe['ok'] ) {
				self::report_failure( $probe['path'], 'folder' );
			}
		}
		$result = null === self::current_error( true ) ? 'ok' : 'bad';

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'plugins.php' );
		}
		wp_safe_redirect( add_query_arg( self::DONE_ARG, $result, remove_query_arg( self::DONE_ARG, $redirect ) ) );
		exit;
	}

	/**
	 * Row for the "Setup check" card, or null while the folder works.
	 *
	 * Same shape as Opti_Behavior_Setup_Guide::get_checks() rows.
	 *
	 * @return array|null
	 */
	public static function setup_check_row() {
		$error = self::current_error();
		if ( null === $error ) {
			return null;
		}
		return array(
			'bad',
			__( 'Opti-Behavior cannot save its data files', 'opti-behavior' ),
			/* translators: 1: what is lost, e.g. "Heatmap clicks and scrolls are not saved.", 2: folder path. */
			sprintf( __( '%1$s WordPress is not allowed to write in %2$s.', 'opti-behavior' ), self::impact_text( $error['what'] ), wp_normalize_path( self::failed_dir( $error ) ) ),
			self::support_url(),
			__( 'Contact support', 'opti-behavior' ),
		);
	}
}
