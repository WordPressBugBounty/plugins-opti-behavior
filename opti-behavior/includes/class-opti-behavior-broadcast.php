<?php
/**
 * Broadcast Banner Manager
 *
 * Fetches broadcast messages from the Opti-Behavior API and displays them
 * as banners at the top of all plugin admin pages. Supports dismiss tracking,
 * style customization, and license-type targeting.
 *
 * @package Opti-Behavior
 * @since   1.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Broadcast
 */
class Opti_Behavior_Broadcast {

	/**
	 * Transient key for cached broadcasts.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'opti_behavior_broadcasts';

	/**
	 * Cache TTL in seconds (6 hours).
	 *
	 * @var int
	 */
	const CACHE_TTL = 21600;

	/**
	 * User meta key prefix for dismissed broadcasts.
	 *
	 * @var string
	 */
	const DISMISS_META_KEY = 'opti_behavior_dismissed_broadcasts';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Only run in admin
		if ( ! is_admin() ) {
			return;
		}

		// Display broadcast banners on plugin pages
		add_action( 'admin_notices', array( $this, 'display_broadcasts' ), 1 );

		// AJAX handler for dismissing broadcasts
		add_action( 'wp_ajax_opti_behavior_dismiss_broadcast', array( $this, 'ajax_dismiss_broadcast' ) );

		// Enqueue broadcast banner styles on plugin pages
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Initialize the broadcast manager.
	 *
	 * @return self
	 */
	public static function init() {
		return self::get_instance();
	}

	/**
	 * Check if we are on an Opti-Behavior admin page.
	 *
	 * @return bool
	 */
	private function is_plugin_page() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}
		return ( strpos( $screen->id, 'opti-behavior' ) !== false );
	}

	/**
	 * Get the API base URL.
	 *
	 * Honors OPTI_BEHAVIOR_ENVIRONMENT === 'local' + WP_DEBUG so local dev
	 * targets the localhost API (matches free-tracker / welcome / license-manager).
	 *
	 * @return string API base URL with trailing slash.
	 */
	private function get_api_url() {
		if ( defined( 'OPTI_BEHAVIOR_ENVIRONMENT' ) && 'local' === OPTI_BEHAVIOR_ENVIRONMENT
			&& defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return 'http://localhost/API/'; // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- dev-only URL, only active when OPTI_BEHAVIOR_ENVIRONMENT=local and WP_DEBUG are set; never active in production.
		}
		return 'https://api.optiuser.com/';
	}

	/**
	 * Get the current license type to send to the API.
	 *
	 * @return string One of: free, pro, trial, expired.
	 */
	private function get_license_type() {
		// Check if Pro is active
		if ( ! function_exists( 'opti_behavior_pro_active' ) || ! opti_behavior_pro_active() ) {
			return 'free';
		}

		// If Pro is active, check manifest for tier/trial status
		if ( class_exists( 'Opti_Behavior_Manifest_Manager' ) ) {
			$manifest_mgr = Opti_Behavior_Manifest_Manager::get_instance();

			// Check if trial has expired
			if ( method_exists( $manifest_mgr, 'is_trial_expired' ) && $manifest_mgr->is_trial_expired() ) {
				return 'expired';
			}

			// Check manifest tier
			if ( method_exists( $manifest_mgr, 'get_manifest' ) ) {
				$manifest = $manifest_mgr->get_manifest();
				if ( isset( $manifest['tier'] ) && 'trial' === $manifest['tier'] ) {
					return 'trial';
				}
			}
		}

		return 'pro';
	}

	/**
	 * Fetch broadcasts from the API (with caching).
	 *
	 * @return array Array of broadcast objects.
	 */
	private function get_broadcasts() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$license_type = $this->get_license_type();
		$api_base     = $this->get_api_url();
		$api_url      = $api_base . 'get-broadcasts?license_type=' . urlencode( $license_type ) . '&domain=' . urlencode( wp_parse_url( home_url(), PHP_URL_HOST ) );

		$response = wp_remote_get( $api_url, array(
			'timeout'   => 10,
			'sslverify' => true,
		) );

		if ( is_wp_error( $response ) ) {
			// Cache empty result for 30 minutes to avoid hammering API
			set_transient( self::CACHE_KEY, array(), 1800 );
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			set_transient( self::CACHE_KEY, array(), 1800 );
			return array();
		}

		$data = json_decode( $body, true );

		if ( ! isset( $data['success'] ) || ! $data['success'] || empty( $data['data']['broadcasts'] ) ) {
			set_transient( self::CACHE_KEY, array(), self::CACHE_TTL );
			return array();
		}

		$broadcasts = $data['data']['broadcasts'];
		set_transient( self::CACHE_KEY, $broadcasts, self::CACHE_TTL );
		return $broadcasts;
	}

	/**
	 * Get dismissed broadcasts map for the current user.
	 *
	 * Returns an associative array of { broadcast_id => dismiss_timestamp }.
	 * Handles migration from old format (plain array of IDs) to new format.
	 *
	 * @return array
	 */
	private function get_dismissed_map() {
		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, self::DISMISS_META_KEY, true );
		if ( ! is_array( $dismissed ) ) {
			return array();
		}

		// Migrate old format (plain array of IDs) to new format (ID => timestamp)
		$needs_migration = false;
		foreach ( $dismissed as $key => $value ) {
			if ( is_int( $key ) && is_int( $value ) && $value < 1000000 ) {
				// Old format: sequential array of broadcast IDs (small ints)
				$needs_migration = true;
				break;
			}
			break; // Only check first element
		}

		if ( $needs_migration ) {
			$migrated = array();
			foreach ( $dismissed as $bc_id ) {
				$migrated[ (int) $bc_id ] = time(); // Treat as just dismissed
			}
			update_user_meta( $user_id, self::DISMISS_META_KEY, $migrated );
			return $migrated;
		}

		return $dismissed;
	}

	/**
	 * Check if a broadcast is currently dismissed for this user.
	 *
	 * Takes dismiss_duration into account: if the broadcast has a non-zero
	 * dismiss_duration (in hours), and enough time has elapsed since the
	 * user dismissed it, the broadcast should be shown again.
	 *
	 * @param int $broadcast_id The broadcast ID.
	 * @param int $dismiss_duration Duration in hours (0 = permanent).
	 * @param array $dismissed_map Map of { broadcast_id => timestamp }.
	 * @return bool True if currently dismissed, false if should be shown.
	 */
	private function is_dismissed( $broadcast_id, $dismiss_duration, $dismissed_map ) {
		if ( ! isset( $dismissed_map[ $broadcast_id ] ) ) {
			return false; // Never dismissed
		}

		// Permanent dismiss
		if ( 0 === $dismiss_duration ) {
			return true;
		}

		// Check if enough time has elapsed
		$dismissed_at = (int) $dismissed_map[ $broadcast_id ];
		$elapsed      = time() - $dismissed_at;
		$duration_sec = $dismiss_duration * 3600; // Convert hours to seconds

		return ( $elapsed < $duration_sec );
	}

	/**
	 * Display broadcast banners on plugin admin pages.
	 *
	 * @return void
	 */
	public function display_broadcasts() {
		if ( ! $this->is_plugin_page() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$broadcasts    = $this->get_broadcasts();
		$dismissed_map = $this->get_dismissed_map();

		if ( empty( $broadcasts ) ) {
			return;
		}

		foreach ( $broadcasts as $bc ) {
			$bc_id            = (int) $bc['id'];
			$dismiss_duration = (int) ( $bc['dismiss_duration'] ?? 0 );

			// Skip broadcasts that are currently dismissed
			if ( $this->is_dismissed( $bc_id, $dismiss_duration, $dismissed_map ) ) {
				continue;
			}

			$this->render_banner( $bc );
		}
	}

	/**
	 * Render a single broadcast banner.
	 *
	 * @param array $bc Broadcast data from API.
	 * @return void
	 */
	private function render_banner( $bc ) {
		$bc_id         = (int) $bc['id'];
		$content       = wp_kses_post( $bc['content'] );
		$banner_style  = sanitize_text_field( $bc['banner_style'] ?? 'info' );
		$bg_color      = sanitize_hex_color( $bc['bg_color'] ?? '' );
		$text_color    = sanitize_hex_color( $bc['text_color'] ?? '' );
		$image_url     = esc_url( $bc['image_url'] ?? '' );
		$cta_text      = sanitize_text_field( $bc['cta_text'] ?? '' );
		$cta_url       = esc_url( $bc['cta_url'] ?? '' );
		$is_dismissible = (int) ( $bc['is_dismissible'] ?? 1 );
		$custom_css    = wp_strip_all_tags( $bc['custom_css'] ?? '' );

		// Default colors per style
		$style_defaults = array(
			'info'    => array( 'bg' => '#1e40af', 'text' => '#ffffff' ),
			'warning' => array( 'bg' => '#92400e', 'text' => '#ffffff' ),
			'success' => array( 'bg' => '#065f46', 'text' => '#ffffff' ),
			'promo'   => array( 'bg' => '#6d28d9', 'text' => '#ffffff' ),
			'custom'  => array( 'bg' => '#1e40af', 'text' => '#ffffff' ),
		);

		$defaults = $style_defaults[ $banner_style ] ?? $style_defaults['info'];
		if ( empty( $bg_color ) ) {
			$bg_color = $defaults['bg'];
		}
		if ( empty( $text_color ) ) {
			$text_color = $defaults['text'];
		}

		$inline_style = "background:{$bg_color};color:{$text_color};";
		if ( $custom_css ) {
			$inline_style .= $custom_css;
		}

		?>
		<div class="opti-behavior-broadcast-banner" data-broadcast-id="<?php echo esc_attr( $bc_id ); ?>" style="<?php echo esc_attr( $inline_style ); ?>">
			<div class="opti-behavior-broadcast-inner">
				<?php if ( $image_url ) : ?>
					<img src="<?php echo esc_url( $image_url ); ?>" alt="" class="opti-behavior-broadcast-image">
				<?php endif; ?>
				<div class="opti-behavior-broadcast-content">
					<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is sanitized via wp_kses_post above ?>
				</div>
				<?php if ( $cta_text && $cta_url ) : ?>
					<a href="<?php echo esc_url( $cta_url ); ?>" class="opti-behavior-broadcast-cta" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $cta_text ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $is_dismissible ) : ?>
					<button type="button" class="opti-behavior-broadcast-dismiss" data-broadcast-id="<?php echo esc_attr( $bc_id ); ?>" title="<?php esc_attr_e( 'Dismiss', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue styles and scripts for broadcast banners.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( ! $this->is_plugin_page() ) {
			return;
		}

		// Inline CSS for broadcast banners
		$css = '
			.opti-behavior-broadcast-banner {
				display: flex !important;
				visibility: visible !important;
				opacity: 1 !important;
				height: auto !important;
				margin: 10px 0 16px 0 !important;
				padding: 0 !important;
				border-radius: 10px;
				overflow: hidden;
				box-shadow: 0 2px 8px rgba(0,0,0,0.12);
				font-size: 14px;
				line-height: 1.5;
			}
			.opti-behavior-broadcast-inner {
				display: flex !important;
				visibility: visible !important;
				opacity: 1 !important;
				height: auto !important;
				align-items: center;
				gap: 14px;
				padding: 14px 20px;
				width: 100%;
				overflow: visible !important;
			}
			.opti-behavior-broadcast-image {
				max-height: 40px;
				border-radius: 4px;
				flex-shrink: 0;
			}
			.opti-behavior-broadcast-content {
				flex: 1;
				display: block !important;
				visibility: visible !important;
				opacity: 1 !important;
				height: auto !important;
				overflow: visible !important;
			}
			.opti-behavior-broadcast-content p {
				margin: 0;
				display: block !important;
				visibility: visible !important;
				opacity: 1 !important;
				height: auto !important;
			}
			.opti-behavior-broadcast-cta {
				display: inline-block;
				padding: 7px 18px;
				background: rgba(255,255,255,0.2);
				color: inherit !important;
				border: 1px solid rgba(255,255,255,0.4);
				border-radius: 6px;
				text-decoration: none;
				font-weight: 600;
				font-size: 13px;
				white-space: nowrap;
				flex-shrink: 0;
				transition: background 0.2s;
			}
			.opti-behavior-broadcast-cta:hover {
				background: rgba(255,255,255,0.35);
				color: inherit !important;
			}
			.opti-behavior-broadcast-dismiss {
				background: none;
				border: none;
				color: inherit;
				cursor: pointer;
				padding: 4px;
				opacity: 0.7;
				flex-shrink: 0;
				transition: opacity 0.2s;
				display: flex !important;
				visibility: visible !important;
			}
			.opti-behavior-broadcast-dismiss:hover {
				opacity: 1;
			}
		';

		wp_register_style( 'opti-behavior-broadcast', false, array(), OPTI_BEHAVIOR_HEATMAP_VERSION );
		wp_enqueue_style( 'opti-behavior-broadcast' );
		wp_add_inline_style( 'opti-behavior-broadcast', $css );

		// Inline JS for dismiss handling
		$js = '
			document.addEventListener("DOMContentLoaded", function() {
				document.querySelectorAll(".opti-behavior-broadcast-dismiss").forEach(function(btn) {
					btn.addEventListener("click", function() {
						var banner = this.closest(".opti-behavior-broadcast-banner");
						var bcId = this.getAttribute("data-broadcast-id");
						if (banner) {
							banner.style.transition = "opacity 0.3s, max-height 0.3s";
							banner.style.opacity = "0";
							banner.style.maxHeight = "0";
							banner.style.margin = "0";
							banner.style.padding = "0";
							banner.style.overflow = "hidden";
							setTimeout(function() { banner.remove(); }, 350);
						}
						// AJAX dismiss
						var formData = new FormData();
						formData.append("action", "opti_behavior_dismiss_broadcast");
						formData.append("broadcast_id", bcId);
						formData.append("nonce", "' . wp_create_nonce( 'opti_behavior_dismiss_broadcast' ) . '");
						fetch(ajaxurl, { method: "POST", body: formData });
					});
				});
			});
		';

		wp_register_script( 'opti-behavior-broadcast', false, array(), OPTI_BEHAVIOR_HEATMAP_VERSION, true );
		wp_enqueue_script( 'opti-behavior-broadcast' );
		wp_add_inline_script( 'opti-behavior-broadcast', $js );
	}

	/**
	 * AJAX handler for dismissing a broadcast.
	 *
	 * @return void
	 */
	public function ajax_dismiss_broadcast() {
		// Verify nonce
		if ( ! check_ajax_referer( 'opti_behavior_dismiss_broadcast', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$broadcast_id = isset( $_POST['broadcast_id'] ) ? (int) $_POST['broadcast_id'] : 0;
		if ( $broadcast_id <= 0 ) {
			wp_send_json_error( 'Invalid broadcast ID' );
		}

		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, self::DISMISS_META_KEY, true );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		// Store broadcast_id => current timestamp (for duration-based re-show)
		$dismissed[ $broadcast_id ] = time();
		update_user_meta( $user_id, self::DISMISS_META_KEY, $dismissed );

		// Notify API about dismissal (non-blocking, fire-and-forget)
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		wp_remote_post( $this->get_api_url() . 'dismiss-broadcast', array(
			'body'      => wp_json_encode( array(
				'broadcast_id' => $broadcast_id,
				'domain'       => $domain,
			) ),
			'headers'   => array( 'Content-Type' => 'application/json' ),
			'timeout'   => 5,
			'sslverify' => true,
			'blocking'  => false,
		) );

		wp_send_json_success( array( 'dismissed' => $broadcast_id ) );
	}
}
