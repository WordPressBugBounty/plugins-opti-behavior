<?php
/**
 * Database size alert
 *
 * Orange admin notice when the Opti-Behavior tables take more than 1.5 GB of
 * the site database (filter `opti_behavior_db_size_alert_mb`). It asks the
 * owner to contact support, with a link to the support page.
 *
 * - The size is the sum of DATA_LENGTH + INDEX_LENGTH of every
 *   `{prefix}optibehavior_*` / `{prefix}opti_behavior_*` table, read from
 *   INFORMATION_SCHEMA at most every 6 hours (transient), admin screens only.
 * - Shown on plugin screens, Plugins and Dashboard, manage_options only.
 * - Not dismissible: it goes away by itself once the tables are back under
 *   the limit (next measure).
 *
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_DB_Size_Alert
 */
class Opti_Behavior_DB_Size_Alert {

	/** Default alert threshold in MB (1.5 GB). */
	const DEFAULT_LIMIT_MB = 1536;

	/** Transient: last measured size of the plugin tables, in bytes. */
	const SIZE_TRANSIENT = 'opti_behavior_db_size_bytes';

	/** How long a measure is trusted. */
	const SIZE_TTL = 6 * HOUR_IN_SECONDS;

	/** Support page. */
	const SUPPORT_URL = 'https://optiuser.com/contact-support/';

	/** @var bool Hooks registered. */
	private static $initialized = false;

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
	}

	/**
	 * Alert threshold in bytes.
	 *
	 * @return float
	 */
	public static function limit_bytes() {
		/**
		 * Size in MB of the Opti-Behavior tables above which the orange
		 * "database is getting large" notice is shown (0 = never).
		 *
		 * @since 2026-09-30
		 * @param int $mb Default 1536 (1.5 GB).
		 */
		$mb = absint( apply_filters( 'opti_behavior_db_size_alert_mb', self::DEFAULT_LIMIT_MB ) );
		return (float) $mb * MB_IN_BYTES;
	}

	/**
	 * Size of the plugin tables in bytes (cached).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return float
	 */
	public static function size_bytes( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::SIZE_TRANSIENT );
			if ( false !== $cached ) {
				return (float) $cached;
			}
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in a transient right below.
		$bytes = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE( SUM( DATA_LENGTH + INDEX_LENGTH ), 0 )
				 FROM INFORMATION_SCHEMA.TABLES
				 WHERE TABLE_SCHEMA = %s AND ( TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s )',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix . 'optibehavior_' ) . '%',
				$wpdb->esc_like( $wpdb->prefix . 'opti_behavior_' ) . '%'
			)
		);

		set_transient( self::SIZE_TRANSIENT, $bytes, self::SIZE_TTL );
		return $bytes;
	}

	/**
	 * Support link with UTM tags (same scheme as the storage notice).
	 *
	 * @return string
	 */
	public static function support_url() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return add_query_arg(
			array(
				'utm_source'   => $host ? $host : 'wordpress',
				'utm_medium'   => 'opti-behavior-plugin',
				'utm_campaign' => 'db-size',
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
	 * Print the orange notice when the plugin tables are over the limit.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::notice_screen() ) {
			return;
		}

		$limit = self::limit_bytes();
		if ( $limit <= 0 ) {
			return;
		}

		$size = self::size_bytes();
		if ( $size <= $limit ) {
			return;
		}

		// Plugin icon + name so the site owner knows which plugin speaks (same icon as the review banner).
		$icon_url = OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'images/256x256.png';
		?>
		<?php // opti-behavior-notice: the plugin screens hide every other notice (admin-notices.css + notice-cleanup.js). ?>
		<?php // Darker orange than the stock notice-warning (user request 2026-09-30); inline so no stylesheet has to load. ?>
		<?php // admin-notices.css forces display:block !important on .opti-behavior-notice, so the flex row is an inner wrapper. ?>
		<div class="notice notice-warning opti-behavior-notice ob-db-size-notice" style="padding:12px 14px;background:#fdba74;border-left:4px solid #c2410c;color:#431407;">
			<div class="ob-db-size-row" style="display:flex;gap:14px;align-items:flex-start;">
			<img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="44" height="44" style="flex:0 0 44px;border-radius:8px;" />
			<div>
				<p style="margin:0 0 2px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Opti-Behavior</p>
				<p style="margin:0 0 4px;"><strong><?php
					/* translators: 1: current size of the Opti-Behavior tables (e.g. "1.7 GB"), 2: alert limit (e.g. "1.5 GB") */
					echo esc_html( sprintf( __( 'Opti-Behavior data now takes %1$s in your database (more than %2$s).', 'opti-behavior' ), size_format( $size, 1 ), size_format( $limit, 1 ) ) );
				?></strong></p>
				<p style="margin:0 0 8px;"><?php esc_html_e( 'A database this large can slow down your site and your backups. Contact us: we will help you choose what to keep and reduce it.', 'opti-behavior' ); ?></p>
				<p style="margin:0;">
					<a class="button button-primary" style="background:#c2410c;border-color:#9a3412;color:#fff;" href="<?php echo esc_url( self::support_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Contact support', 'opti-behavior' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'opti-behavior' ); ?></span></a>
				</p>
			</div>
			</div>
		</div>
		<?php
	}
}
