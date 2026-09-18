<?php
/**
 * Welcome & Consent Page
 *
 * Displays a full-screen professional welcome/onboarding page on first activation.
 * All plugin admin pages are blocked until the admin clicks "Accept & Continue".
 * After acceptance, the consent flag is stored and the tracker may send data.
 *
 * @package opti-behavior
 * @since 1.2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Welcome
 *
 * Manages the first-run consent flow for Opti-Behavior.
 *
 * @since 1.2.4
 */
class Opti_Behavior_Welcome {

	/** WordPress option that stores whether consent was accepted. */
	const OPTION_CONSENT  = 'opti_behavior_consent_accepted';

	/** Transient/option used to trigger the post-activation redirect. */
	const OPTION_REDIRECT = 'opti_behavior_activation_redirect';

	/** Slug for the hidden welcome admin page. */
	const WELCOME_SLUG = 'opti-behavior-welcome';

	/** Option storing the trial license key obtained from API. */
	const OPTION_TRIAL_LICENSE_KEY = 'opti_behavior_trial_license_key';

	/** Option storing trial expiry datetime from API. */
	const OPTION_TRIAL_EXPIRES_AT = 'opti_behavior_trial_expires_at';

	/** Transient key for the short-lived Pro download URL (TTL: 280s). */
	const TRANSIENT_PRO_DOWNLOAD_URL = 'opti_behavior_pro_download_url';

	/**
	 * Plugin page slugs that require consent before access.
	 *
	 * @var string[]
	 */
	private static $protected_pages = array(
		'opti-behavior-analytics',
		'opti-behavior-heatmaps',
		'opti-behavior-recordings',
		'opti-behavior-settings',
		'opti-behavior-funnels',
		'opti-behavior-ab-testing',
		'opti-behavior-ab-visual-editor',
		'opti-behavior-ai-insights',
		'opti-behavior-smart-insights',
		'opti-behavior-heatmap-detail',
		'opti-behavior-user-journey',
		'opti-behavior-errors',
		'opti-behavior-form-analytics',
	);

	/** Singleton instance. @var self|null */
	private static $instance = null;

	/**
	 * Initialize the singleton.
	 *
	 * @return self
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private constructor — use init(). */
	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_welcome_page' ), 5 );
		add_action( 'admin_init',            array( $this, 'maybe_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_welcome_styles' ) );
		add_action( 'admin_post_opti_behavior_accept_consent', array( $this, 'handle_accept' ) );
		add_action( 'admin_notices',         array( $this, 'render_trial_failed_notice' ) );
		// Fix PHP 8.2 deprecation: pages registered with parent_slug=null are hidden pages that
		// WordPress's get_admin_page_title() cannot find, leaving $title as null.
		// admin-header.php then calls strip_tags(null) → deprecation warning.
		// We hook into 'current_screen' (fires before admin-header.php) to set $title manually.
		add_action( 'current_screen', array( $this, 'maybe_set_hidden_page_title' ) );
	}

	/**
	 * Set the global $title for hidden admin pages (those registered with parent_slug = null).
	 *
	 * WordPress's get_admin_page_title() only scans $menu and $submenu[parent] — it cannot
	 * resolve hidden pages, leaving the global $title as null. On PHP 8.2 the subsequent
	 * strip_tags($title) in admin-header.php produces a deprecation warning. We set $title
	 * here so WordPress finds it correctly.
	 *
	 * @return void
	 */
	public function maybe_set_hidden_page_title() {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional: setting $title for hidden admin pages that WP cannot resolve automatically.
		global $title;

		if ( ! empty( $title ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: GET param used for page identification only.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( self::WELCOME_SLUG === $current_page ) {
			$title = __( 'Welcome to Opti-Behavior', 'opti-behavior' );
		} elseif ( 'opti-behavior-install-trial' === $current_page ) {
			$title = __( 'Installing Opti-Behavior Pro', 'opti-behavior' );
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Return true if the admin has already accepted the terms.
	 *
	 * @return bool
	 */
	public static function has_consent() {
		return (bool) get_option( self::OPTION_CONSENT, false );
	}

	/**
	 * Called on plugin activation via register_activation_hook().
	 * Sets a flag that triggers a redirect to the welcome page.
	 *
	 * @return void
	 */
	public static function on_activation() {
		if ( ! get_option( self::OPTION_CONSENT ) ) {
			update_option( self::OPTION_REDIRECT, '1' );
		}
	}

	/**
	 * Register the hidden welcome page and the trial installer page.
	 *
	 * @return void
	 */
	public function register_welcome_page() {
		add_submenu_page(
			'', // hidden — not attached to any parent menu (empty string avoids PHP 8.2 null deprecation)
			__( 'Welcome to Opti-Behavior', 'opti-behavior' ),
			__( 'Welcome', 'opti-behavior' ),
			'manage_options',
			self::WELCOME_SLUG,
			array( $this, 'render_welcome_page' )
		);

		// Hidden installer page — only reachable after trial activation flow.
		add_submenu_page(
			'', // hidden — empty string avoids PHP 8.2 null deprecation
			__( 'Installing Opti-Behavior Pro', 'opti-behavior' ),
			__( 'Install Pro', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-install-trial',
			array( $this, 'render_trial_installer_page' )
		);
	}

	/**
	 * On every admin_init, redirect to the welcome page when:
	 * (a) plugin was just activated and no consent exists, OR
	 * (b) user navigates to a protected plugin page without consent.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: GET param used for page identification only.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Never redirect when already on the welcome page.
		if ( self::WELCOME_SLUG === $current_page ) {
			return;
		}

		// Once consent is given, enforcement is done.
		if ( self::has_consent() ) {
			return;
		}

		// Handle post-activation redirect flag.
		if ( get_option( self::OPTION_REDIRECT ) ) {
			delete_option( self::OPTION_REDIRECT );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::WELCOME_SLUG ) );
			exit;
		}

		// Block any protected plugin page if consent was never given.
		if ( in_array( $current_page, self::$protected_pages, true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::WELCOME_SLUG ) );
			exit;
		}
	}

	/**
	 * Process the "Accept & Continue" form submission.
	 * Handles both the standard consent flow and the optional trial activation.
	 *
	 * @return void
	 */
	public function handle_accept() {
		check_admin_referer( 'opti_behavior_accept_consent' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
		}

		// Always set consent flag first — regardless of trial choice.
		update_option( self::OPTION_CONSENT, '1' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer
		$start_trial = isset( $_POST['start_trial'] ) && '1' === $_POST['start_trial'];

		if ( $start_trial ) {
			$trial_result = $this->request_trial_from_api();

			if ( $trial_result && ! empty( $trial_result['trial_license_key'] ) ) {
				// Store trial info — NOTE: master_secret is intentionally NOT stored here.
				// The Pro plugin will receive and encrypt it via its own /register call.
				update_option( self::OPTION_TRIAL_LICENSE_KEY, sanitize_text_field( $trial_result['trial_license_key'] ) );
				update_option( self::OPTION_TRIAL_EXPIRES_AT, sanitize_text_field( $trial_result['trial_expires_at'] ) );
				update_option( 'opti_behavior_installation_id', sanitize_text_field( $trial_result['installation_id'] ) );

				// Store download URL in short-lived transient (single-use, 280s < 5-min token TTL).
				set_transient( self::TRANSIENT_PRO_DOWNLOAD_URL, esc_url_raw( $trial_result['download_url'] ), 280 );

				wp_safe_redirect( admin_url( 'admin.php?page=opti-behavior-install-trial' ) );
				exit;
			}

			// Trial API call failed — set notice transient and fall through to normal redirect.
			set_transient( 'opti_behavior_trial_failed', '1', 60 );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[Opti-Behavior] Trial activation failed, proceeding without trial.' );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=opti-behavior-analytics' ) );
		exit;
	}

	/**
	 * Call the API /trial-install endpoint to generate a trial license and download URL.
	 * Accepts HTTP 200 (new trial) and HTTP 409 trial_already_active (existing trial) as success.
	 *
	 * @return array|false Array with keys: installation_id, trial_license_key,
	 *                     trial_expires_at, download_url. Returns false on failure.
	 */
	private function request_trial_from_api() {
		// Sodium is required to generate a client keypair for the API handshake.
		if ( ! function_exists( 'sodium_crypto_box_keypair' ) ) {
			set_transient( 'opti_behavior_trial_debug', 'FAIL:sodium_not_available', 300 );
			return false;
		}

		try {
			$keypair    = sodium_crypto_box_keypair();
			$public_key = base64_encode( sodium_crypto_box_publickey( $keypair ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			// Never call sodium_memzero() — WordPress's sodium_compat polyfill throws
			// because secure memory wiping is impossible in pure PHP. Use unset() instead.
			unset( $keypair );
		} catch ( \Throwable $e ) {
			set_transient( 'opti_behavior_trial_debug', 'FAIL:sodium_throw:' . $e->getMessage(), 300 );
			return false;
		}

		// Select API URL: local dev when OPTI_BEHAVIOR_ENVIRONMENT = 'local' AND WP_DEBUG,
		// otherwise production. Matches the pattern in class-opti-behavior-license-manager.php.
		$api_url = 'https://api.optiuser.com/';
		if ( defined( 'OPTI_BEHAVIOR_ENVIRONMENT' ) && 'local' === OPTI_BEHAVIOR_ENVIRONMENT
			&& defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$api_url = 'http://localhost/API/'; // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- dev-only URL, only active when OPTI_BEHAVIOR_ENVIRONMENT=local and WP_DEBUG are set; never active in production.
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $domain ) ) {
			$domain = 'localhost';
		}

		$free_version = defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1.0.0';
		$contact_email = $this->resolve_trial_contact_email();

		if ( empty( $contact_email ) ) {
			set_transient( 'opti_behavior_trial_debug', 'FAIL:contact_email_unavailable', 300 );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is on.
				error_log( '[Opti-Behavior] Trial activation skipped: no valid administrator contact email available.' );
			}

			return false;
		}

		$response = wp_remote_post(
			$api_url . 'trial-install',
			array(
				'body'      => wp_json_encode(
					array(
						'domain'              => $domain,
						'email'               => $contact_email,
						'site_url'            => get_site_url(),
						'site_name'           => get_bloginfo( 'name' ) ?: get_site_url(),
						'client_public_key'   => $public_key,
						'free_plugin_version' => $free_version,
					)
				),
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( 'opti_behavior_trial_debug', 'FAIL:wp_error:' . $response->get_error_message(), 300 );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// HTTP 200 = new trial created successfully.
		$is_new = ( 200 === $code && ! empty( $body['success'] ) && ! empty( $body['data'] ) );

		// HTTP 409 trial_already_active = existing trial — treat as success (Issue 3 fix).
		$is_existing = (
			409 === $code
			&& isset( $body['error'] )
			&& 'trial_already_active' === $body['error']
			&& ! empty( $body['data'] )
		);

		if ( ! $is_new && ! $is_existing ) {
			set_transient( 'opti_behavior_trial_debug', 'FAIL:api_http' . $code . ':' . substr( wp_remote_retrieve_body( $response ), 0, 200 ), 300 );
			return false;
		}

		$data = $body['data'];

		// Validate all required keys are present.
		foreach ( array( 'installation_id', 'trial_license_key', 'trial_expires_at', 'download_url' ) as $key ) {
			if ( empty( $data[ $key ] ) ) {
				return false;
			}
		}

		// master_secret is intentionally not validated or returned (Issue 1 fix).
		set_transient( 'opti_behavior_trial_debug', 'OK:trial_data_received', 300 );
		return $data;
	}

	/**
	 * Resolve the contact email used by the interactive trial-install request.
	 *
	 * The trial endpoint requires a valid email. Prefer the current managing
	 * administrator because accepting the welcome screen is an explicit admin
	 * action, then fall back through the shared resolver's deterministic site
	 * contact candidates.
	 *
	 * @return string|null Valid email address, or null when no contact is available.
	 */
	private function resolve_trial_contact_email() {
		if ( class_exists( 'Opti_Behavior_Heatmap_Admin_Email_Resolver' ) ) {
			return Opti_Behavior_Heatmap_Admin_Email_Resolver::get_email(
				array(
					'prefer_current_user'  => true,
					'include_current_user' => true,
				)
			);
		}

		$admin_email = sanitize_email( get_option( 'admin_email' ) );

		return is_email( $admin_email ) ? $admin_email : null;
	}

	/**
	 * Render the Pro plugin trial installer page.
	 * Uses WordPress's native Plugin_Upgrader to download and install the Pro ZIP.
	 *
	 * @return void
	 */
	public function render_trial_installer_page() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'opti-behavior' ) );
		}

		// Retrieve and immediately delete the single-use download URL transient.
		$download_url = get_transient( self::TRANSIENT_PRO_DOWNLOAD_URL );
		delete_transient( self::TRANSIENT_PRO_DOWNLOAD_URL );

		if ( empty( $download_url ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Install Opti-Behavior Pro', 'opti-behavior' ) . '</h1>';
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: support URL */
				esc_html__( 'The Pro plugin download link has expired. Please try again from the welcome page, or %s.', 'opti-behavior' ),
				'<a href="https://optiuser.com/pro" target="_blank" rel="noopener noreferrer">' . esc_html__( 'visit optiuser.com/pro', 'opti-behavior' ) . '</a>'
			);
			echo '</p></div>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=opti-behavior-analytics' ) ) . '" class="button">'
				. esc_html__( 'Continue without Pro', 'opti-behavior' ) . '</a></p></div>';
			return;
		}

		// Check if Pro plugin is already installed.
		$pro_plugin_file = 'opti-behavior-pro/opti-behavior-pro.php';
		if ( file_exists( WP_PLUGIN_DIR . '/opti-behavior-pro/opti-behavior-pro.php' ) ) {
			// Already installed — activate if not active, then redirect.
			if ( ! is_plugin_active( $pro_plugin_file ) ) {
				$activated = activate_plugin( $pro_plugin_file );
				if ( is_wp_error( $activated ) ) {
					echo '<div class="wrap"><h1>' . esc_html__( 'Install Opti-Behavior Pro', 'opti-behavior' ) . '</h1>';
					echo '<div class="notice notice-warning"><p>'
						. esc_html__( 'Pro plugin is installed but could not be activated automatically.', 'opti-behavior' )
						. ' ' . esc_html( $activated->get_error_message() ) . '</p></div>';
					echo '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '" class="button button-primary">'
						. esc_html__( 'Go to Plugins page', 'opti-behavior' ) . '</a></p></div>';
					return;
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=opti-behavior-analytics&trial=activated' ) );
			exit;
		}

		// Load WordPress upgrader classes.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		echo '<div class="wrap"><h1>' . esc_html__( 'Installing Opti-Behavior Pro…', 'opti-behavior' ) . '</h1>';

		// Use WP_Upgrader_Skin to display progress inline.
		$skin     = new WP_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		$result = $upgrader->install( esc_url_raw( $download_url ) );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Installation failed:', 'opti-behavior' ) . ' '
				. esc_html( $result->get_error_message() ) . '</p></div>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=opti-behavior-analytics' ) ) . '" class="button">'
				. esc_html__( 'Continue without Pro', 'opti-behavior' ) . '</a></p></div>';
			return;
		}

		if ( false === $result ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Installation could not be completed. Please try installing the Pro plugin manually.', 'opti-behavior' )
				. '</p></div>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=opti-behavior-analytics' ) ) . '" class="button">'
				. esc_html__( 'Continue without Pro', 'opti-behavior' ) . '</a></p></div>';
			return;
		}

		// Activate the Pro plugin.
		$activated = activate_plugin( $pro_plugin_file );

		if ( is_wp_error( $activated ) ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Pro plugin installed but could not be activated automatically.', 'opti-behavior' )
				. ' ' . esc_html( $activated->get_error_message() ) . '</p></div>';
			echo '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '" class="button button-primary">'
				. esc_html__( 'Go to Plugins page', 'opti-behavior' ) . '</a></p></div>';
			return;
		}

		// Headers are already sent (installer progress output), so use JS redirect.
		$redirect_url = admin_url( 'admin.php?page=opti-behavior-analytics&trial=activated' );
		echo '<div class="notice notice-success"><p>'
			. esc_html__( 'Pro plugin activated! Redirecting to Analytics…', 'opti-behavior' )
			. '</p></div></div>';
		echo '<script>window.location.href=' . wp_json_encode( $redirect_url ) . ';</script>';
		exit;
	}

	/**
	 * Show a dismissible admin notice on the Analytics page when trial activation failed.
	 *
	 * @return void
	 */
	public function render_trial_failed_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET param for page detection.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'opti-behavior-analytics' !== $current_page ) {
			return;
		}

		if ( ! get_transient( 'opti_behavior_trial_failed' ) ) {
			return;
		}

		delete_transient( 'opti_behavior_trial_failed' );

		// The class name MUST carry "opti-behavior": assets/js/notice-cleanup.js
		// strips every `.notice-warning` that is not ours from our own screens, and
		// this notice is one-shot (the transient is consumed above), so a stripped
		// render is a notice the user never sees again (QA-B-GATE-021).
		echo '<div id="opti-behavior-trial-failed-notice" class="notice notice-warning is-dismissible opti-behavior-notice"><p>';
		printf(
			/* translators: %s: link to Pro page */
			esc_html__( 'The Pro Trial could not be activated automatically. You can %s to start your trial manually.', 'opti-behavior' ),
			'<a href="https://optiuser.com/pro" target="_blank" rel="noopener noreferrer">' . esc_html__( 'visit optiuser.com/pro', 'opti-behavior' ) . '</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Enqueue inline CSS for the welcome and trial installer pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_welcome_styles( $hook ) {
		if ( 'admin_page_opti-behavior-welcome' !== $hook
			&& 'admin_page_opti-behavior-install-trial' !== $hook ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', $this->get_welcome_css() );
	}

	/**
	 * Render the full welcome/onboarding page.
	 *
	 * @return void
	 */
	public function render_welcome_page() {
		$logo_url          = OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'images/256x256.png';
		$action_url        = esc_url( admin_url( 'admin-post.php' ) );
		$trial_already_set = (bool) get_option( self::OPTION_TRIAL_LICENSE_KEY );
		$version           = defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1.0.0';
		?>
		<div class="ob-consent-wrap">

			<!-- Accent bar -->
			<div class="ob-consent-accent-bar"></div>

			<!-- Header -->
			<div class="ob-consent-header">
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Opti-Behavior Logo', 'opti-behavior' ); ?>" class="ob-consent-logo" />
				<div class="ob-consent-brand-sub"><?php esc_html_e( 'by OptiUser', 'opti-behavior' ); ?></div>
				<h1><?php esc_html_e( 'Welcome to Opti-Behavior', 'opti-behavior' ); ?></h1>
				<p class="ob-consent-tagline">
					<?php esc_html_e( 'See where visitors click, scroll, hesitate, abandon forms, hit errors, and leave funnels — then improve your pages with A/B testing. All visitor behavior data stays on your own WordPress server.', 'opti-behavior' ); ?>
				</p>
			</div>

			<div class="ob-consent-divider"></div>

			<?php if ( ! $trial_already_set ) : ?>
			<!-- Pro Trial Opt-in Card (shown prominently before features) -->
			<div class="ob-consent-trial-card" id="ob-trial-card">
				<div class="ob-consent-trial-badge"><?php esc_html_e( '✨ Free Offer', 'opti-behavior' ); ?></div>
				<h3><?php esc_html_e( 'Start Your Free 6-Month Pro Trial', 'opti-behavior' ); ?></h3>
				<p>
					<?php esc_html_e( 'Get full access to Session Recordings, Form Analytics, Error Tracking, User Journey Maps, and AI Insights — completely free for 6 months. No credit card required.', 'opti-behavior' ); ?>
				</p>
				<label class="ob-consent-trial-label" for="ob_start_trial">
					<input type="checkbox" id="ob_start_trial" name="start_trial" value="1" form="ob-consent-form" checked="checked" />
					<span><?php esc_html_e( 'Yes, activate my free 6-month Pro Trial', 'opti-behavior' ); ?></span>
				</label>
				<p class="ob-consent-trial-note">
					<?php esc_html_e( "The Pro plugin will be installed automatically using WordPress's standard installer. You can remove it at any time.", 'opti-behavior' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<h2 class="ob-consent-section-title"><?php esc_html_e( "What's Included", 'opti-behavior' ); ?></h2>

			<!-- Feature grid -->
			<div class="ob-consent-features">

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-heatmap">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12c0-3 2.5-6 2.5-6s2.5 3 2.5 6a2.5 2.5 0 1 1-5 0Z"/><path d="M12 2v1"/><path d="M12 21v1"/><path d="m4.6 4.6.7.7"/><path d="m18.7 18.7.7.7"/><path d="M2 12h1"/><path d="M21 12h1"/><path d="m4.6 19.4.7-.7"/><path d="m18.7 5.3.7-.7"/></svg>
					</div>
					<h3><?php esc_html_e( 'Visual Heatmaps', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Click, scroll and attention heatmaps with color-coded intensity overlays on every page.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-analytics">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
					</div>
					<h3><?php esc_html_e( 'Advanced Analytics', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Real-time dashboards, traffic sources, conversion funnels and visitor behavior reports.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-recordings">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
					</div>
					<h3><?php esc_html_e( 'Session Recordings', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Replay full visitor sessions to see precisely how users interact with every page.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-funnels">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
					</div>
					<h3><?php esc_html_e( 'Conversion Funnels', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Track multi-step user flows, identify drop-off points and optimize conversion paths.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-forms">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="2"/><path d="M7 8h10"/><path d="M7 12h7"/><path d="M7 16h4"/></svg>
					</div>
					<h3><?php esc_html_e( 'Form Analytics', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Measure form field interactions, abandonment rates and completion times.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-errors">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
					</div>
					<h3><?php esc_html_e( 'Errors & Performance', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Capture JavaScript errors and performance bottlenecks in real time.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-journey">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="6" r="3"/><path d="M5 9v6"/><circle cx="5" cy="18" r="3"/><path d="M12 3h7a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-7"/><path d="M12 13h7a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-7"/></svg>
					</div>
					<h3><?php esc_html_e( 'User Journey Analytics', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Visualize complete visitor navigation paths from entry to exit across your site.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-abtesting">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h5"/><path d="M4 17h5"/><path d="M15 5h5"/><path d="M15 19h5"/><path d="M9 7c4 0 3 10 6 10"/><path d="M9 17c4 0 3-10 6-10"/></svg>
					</div>
					<h3><?php esc_html_e( 'A/B Testing', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'Run page split and element tests to compare variants and identify winning experiences.', 'opti-behavior' ); ?></p>
				</div>

				<div class="ob-consent-feature">
					<div class="ob-consent-feature-icon icon-privacy-shield">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 5 6v5c0 4.5 2.9 8.5 7 10 4.1-1.5 7-5.5 7-10V6l-7-3Z"/><path d="m9.5 12 1.8 1.8 3.7-4.1"/></svg>
					</div>
					<h3><?php esc_html_e( 'GDPR Compliant by Design', 'opti-behavior' ); ?></h3>
					<p><?php esc_html_e( 'IP anonymization, no cross-border transfers, configurable consent and full data controls.', 'opti-behavior' ); ?></p>
				</div>

			</div><!-- /.ob-consent-features -->

			<!-- Privacy & Compliance -->
			<div class="ob-consent-privacy">
				<div class="ob-consent-privacy-header">
					<div class="ob-consent-privacy-badge">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
					</div>
					<strong><?php esc_html_e( 'Privacy & Data Protection', 'opti-behavior' ); ?></strong>
				</div>
				<div class="ob-consent-privacy-body">
					<?php
					printf(
						/* translators: %s: Privacy Policy hyperlink */
						esc_html__( 'All visitor analytics data is processed and stored exclusively on your own server — no external services, no cross-border data transfers. IP anonymization, consent-mode support, and full data deletion controls help you comply with GDPR, CCPA, and other privacy regulations. By clicking "Accept & Continue" you agree to our %s.', 'opti-behavior' ),
						'<a href="https://optiuser.com/privacy-policy/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'opti-behavior' ) . '</a>'
					);
					?>
				</div>
				<div class="ob-consent-badges">
					<span class="ob-consent-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e( 'GDPR Ready', 'opti-behavior' ); ?></span>
					<span class="ob-consent-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e( 'CCPA Compliant', 'opti-behavior' ); ?></span>
					<span class="ob-consent-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e( 'Self-Hosted Data', 'opti-behavior' ); ?></span>
					<span class="ob-consent-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e( 'No Third-Party Sharing', 'opti-behavior' ); ?></span>
					<span class="ob-consent-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e( 'IP Anonymization', 'opti-behavior' ); ?></span>
				</div>
			</div>

			<!-- Accept form -->
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="ob-consent-cta-section" id="ob-consent-form">
				<?php wp_nonce_field( 'opti_behavior_accept_consent' ); ?>
				<input type="hidden" name="action" value="opti_behavior_accept_consent" />
				<button type="submit" class="ob-consent-cta">
					<?php esc_html_e( 'Accept & Continue', 'opti-behavior' ); ?>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
				</button>
				<p class="ob-consent-footer-note">
					<?php esc_html_e( 'You can review and adjust all privacy and tracking settings at any time from the Settings page.', 'opti-behavior' ); ?>
				</p>
			</form>

			<!-- Bottom brand -->
			<div class="ob-consent-bottom-brand">
				<a href="https://optiuser.com" target="_blank" rel="noopener noreferrer">OptiUser</a> &middot;
				<?php
				/* translators: %s: plugin version number */
				printf( esc_html__( 'Version %s', 'opti-behavior' ), esc_html( $version ) );
				?>
			</div>

		</div><!-- /.ob-consent-wrap -->
		<?php
	}

	/**
	 * Return the inline CSS used for the welcome page.
	 *
	 * @return string
	 */
	private function get_welcome_css() {
		return '
/* ── Opti-Behavior First-Run Consent Page ── */
.ob-consent-wrap {
	max-width: 880px;
	margin: 16px auto;
	padding: 0 20px 36px;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, sans-serif;
	color: #1a1a2e;
}
.ob-consent-accent-bar {
	height: 4px;
	background: linear-gradient(90deg, #6366f1, #8b5cf6, #a78bfa, #6366f1);
	background-size: 200% 100%;
	border-radius: 2px;
	margin-bottom: 22px;
	animation: ob-shimmer 3s ease infinite;
}
@keyframes ob-shimmer {
	0%   { background-position: 0% 50%; }
	50%  { background-position: 100% 50%; }
	100% { background-position: 0% 50%; }
}
.ob-consent-header {
	text-align: center;
	margin-bottom: 18px;
}
.ob-consent-logo {
	width: 60px;
	height: 60px;
	border-radius: 13px;
	box-shadow: 0 4px 16px rgba(99,102,241,.20);
	margin-bottom: 10px;
	display: block;
	margin-inline: auto;
}
.ob-consent-brand-sub {
	font-size: 10px;
	color: #6366f1;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 1.8px;
	margin-bottom: 6px;
}
.ob-consent-header h1 {
	font-size: 22px;
	font-weight: 700;
	color: #1a1a2e;
	margin: 0 0 6px;
	line-height: 1.2;
}
.ob-consent-tagline {
	font-size: 13px;
	color: #64748b;
	margin: 0 auto;
	max-width: 500px;
	line-height: 1.6;
}
.ob-consent-divider {
	width: 36px;
	height: 3px;
	background: linear-gradient(90deg, #6366f1, #8b5cf6);
	border-radius: 2px;
	margin: 14px auto;
}
.ob-consent-section-title {
	text-align: center;
	font-size: 14px;
	font-weight: 600;
	color: #1a1a2e;
	margin-bottom: 14px;
}
/* Feature grid */
.ob-consent-features {
	display: grid;
	grid-template-columns: repeat(3,1fr);
	gap: 10px;
	margin-bottom: 18px;
}
@media (max-width: 782px) {
	.ob-consent-features { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 520px) {
	.ob-consent-features { grid-template-columns: 1fr; }
}
.ob-consent-feature {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 9px;
	padding: 14px 13px;
	transition: border-color .18s, box-shadow .18s;
}
.ob-consent-feature:hover {
	border-color: #c7d2fe;
	box-shadow: 0 3px 10px rgba(99,102,241,.09);
}
.ob-consent-feature-icon {
	width: 32px;
	height: 32px;
	border-radius: 7px;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-bottom: 8px;
}
.ob-consent-feature-icon svg { width: 16px; height: 16px; }
.ob-consent-feature-icon.icon-heatmap    { background: #fef2f2; color: #ef4444; }
.ob-consent-feature-icon.icon-analytics  { background: #eff6ff; color: #3b82f6; }
.ob-consent-feature-icon.icon-recordings { background: #f5f3ff; color: #7c3aed; }
.ob-consent-feature-icon.icon-funnels    { background: #fdf4ff; color: #a855f7; }
.ob-consent-feature-icon.icon-forms      { background: #fff7ed; color: #ea580c; }
.ob-consent-feature-icon.icon-errors     { background: #fef2f2; color: #dc2626; }
.ob-consent-feature-icon.icon-journey    { background: #f0fdfa; color: #0d9488; }
.ob-consent-feature-icon.icon-selfhosted { background: #ecfdf5; color: #10b981; }
.ob-consent-feature-icon.icon-abtesting  { background: #eef2ff; color: #4f46e5; }
.ob-consent-feature-icon.icon-privacy-shield { background: #eff6ff; color: #6366f1; }
.ob-consent-feature h3 {
	font-size: 12.5px;
	font-weight: 600;
	color: #1a1a2e;
	margin: 0 0 3px;
}
.ob-consent-feature p {
	font-size: 11.5px;
	color: #64748b;
	margin: 0;
	line-height: 1.5;
}
/* Privacy section */
.ob-consent-privacy {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 9px;
	padding: 14px 18px;
	margin-bottom: 16px;
}
.ob-consent-privacy-header {
	display: flex;
	align-items: center;
	gap: 9px;
	margin-bottom: 8px;
}
.ob-consent-privacy-badge {
	width: 28px;
	height: 28px;
	border-radius: 6px;
	background: #eff6ff;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.ob-consent-privacy-badge svg { width: 14px; height: 14px; color: #6366f1; }
.ob-consent-privacy-header strong { font-size: 13px; color: #1a1a2e; }
.ob-consent-privacy-body {
	font-size: 12px;
	color: #475569;
	line-height: 1.6;
	margin-bottom: 10px;
}
.ob-consent-privacy-body a { color: #6366f1; text-decoration: none; font-weight: 500; }
.ob-consent-privacy-body a:hover { text-decoration: underline; }
.ob-consent-badges { display: flex; flex-wrap: wrap; gap: 5px; }
.ob-consent-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 10.5px;
	font-weight: 500;
	color: #475569;
	background: #f8fafc;
	border: 1px solid #e5e7eb;
	border-radius: 5px;
	padding: 3px 7px;
}
.ob-consent-badge svg { width: 10px; height: 10px; color: #10b981; flex-shrink: 0; }
/* Trial opt-in card */
.ob-consent-trial-card {
	background: linear-gradient(135deg,#fef9c3 0%,#fef3c7 100%);
	border: 2px solid #fcd34d;
	border-radius: 11px;
	padding: 18px 22px;
	margin-bottom: 16px;
}
.ob-consent-trial-badge {
	display: inline-block;
	background: linear-gradient(135deg,#f59e0b,#d97706);
	color: #fff;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: .05em;
	text-transform: uppercase;
	padding: 3px 9px;
	border-radius: 20px;
	margin-bottom: 8px;
}
.ob-consent-trial-card h3 {
	font-size: 15px;
	font-weight: 700;
	color: #92400e;
	margin: 0 0 7px;
	line-height: 1.3;
}
.ob-consent-trial-card > p {
	font-size: 12.5px;
	color: #78350f;
	margin: 0 0 12px;
	line-height: 1.55;
}
.ob-consent-trial-label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	margin-bottom: 8px;
}
.ob-consent-trial-label input[type="checkbox"] {
	width: 16px;
	height: 16px;
	flex-shrink: 0;
	accent-color: #d97706;
	cursor: pointer;
}
.ob-consent-trial-label span {
	font-size: 13px;
	font-weight: 600;
	color: #78350f;
	line-height: 1.4;
}
.ob-consent-trial-note {
	font-size: 11px;
	color: #92400e;
	margin: 0;
	opacity: .75;
	line-height: 1.5;
}
/* CTA form */
.ob-consent-cta-section { text-align: center; padding: 4px 0 0; }
.ob-consent-cta {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	background: linear-gradient(135deg,#6366f1,#7c3aed);
	color: #fff !important;
	font-size: 14px;
	font-weight: 600;
	padding: 12px 32px;
	border: none;
	border-radius: 8px;
	cursor: pointer;
	transition: background .2s, box-shadow .2s, transform .15s;
	box-shadow: 0 2px 12px rgba(99,102,241,.30);
	text-decoration: none !important;
	line-height: 1;
	letter-spacing: .2px;
}
.ob-consent-cta:hover {
	background: linear-gradient(135deg,#4f46e5,#6d28d9);
	box-shadow: 0 4px 18px rgba(99,102,241,.42);
	transform: translateY(-1px);
}
.ob-consent-cta:active { transform: translateY(0); }
.ob-consent-cta svg { width: 15px; height: 15px; flex-shrink: 0; }
.ob-consent-footer-note {
	margin-top: 10px;
	font-size: 11px;
	color: #94a3b8;
	line-height: 1.5;
}
/* Bottom brand */
.ob-consent-bottom-brand {
	text-align: center;
	margin-top: 20px;
	padding-top: 14px;
	border-top: 1px solid #e5e7eb;
	font-size: 11px;
	color: #94a3b8;
}
.ob-consent-bottom-brand a { color: #6366f1; text-decoration: none; font-weight: 500; }
.ob-consent-bottom-brand a:hover { text-decoration: underline; }
		';
	}
}
