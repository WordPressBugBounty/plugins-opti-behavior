<?php
/**
 * Deactivation Survey Relay Controller
 *
 * Receives deactivation survey submissions from wp-admin/plugins.php and
 * relays them to the OptiUser API. No survey data is persisted locally.
 *
 * @package opti-behavior
 * @since   1.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Heatmap_Deactivation_Survey
 *
 * @since 1.3.2
 */
class Opti_Behavior_Heatmap_Deactivation_Survey {

	/**
	 * AJAX action name.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'opti_behavior_submit_deactivation_survey';

	/**
	 * Nonce action name.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'opti_behavior_deactivation_survey';

	/**
	 * API endpoint path for survey relay.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'deactivation-survey';

	/**
	 * Max feedback length to keep payload bounded.
	 *
	 * @var int
	 */
	const MAX_FEEDBACK_LENGTH = 2000;

	/**
	 * Target plugin basename for plugins.php row interception.
	 *
	 * @var string
	 */
	const TARGET_PLUGIN_BASENAME = 'opti-behavior/Opti-Behavior.php';

	/**
	 * AJAX timeout for survey submission (milliseconds).
	 *
	 * @var int
	 */
	const AJAX_TIMEOUT_MS = 6000;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Initialize singleton.
	 *
	 * @return self
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_submit_survey' ) );
	}

	/**
	 * Enqueue survey assets on plugins.php only.
	 *
	 * @param string $hook_suffix Current admin page suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'plugins.php' !== $hook_suffix ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$css_handle = 'opti-behavior-deactivation-survey';
		$js_handle  = 'opti-behavior-deactivation-survey';
		$css_path   = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/deactivation-survey.css';
		$js_path    = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/deactivation-survey.js';

		wp_enqueue_style(
			$css_handle,
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/deactivation-survey.css',
			array(),
			$this->resolve_asset_version( $css_path )
		);

		wp_enqueue_script(
			$js_handle,
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/deactivation-survey.js',
			array( 'jquery' ),
			$this->resolve_asset_version( $js_path ),
			true
		);

		wp_localize_script(
			$js_handle,
			'optiBehaviorDeactivationSurvey',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'ajaxAction'     => self::AJAX_ACTION,
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'pluginFile'     => self::TARGET_PLUGIN_BASENAME,
				'requestTimeout' => self::AJAX_TIMEOUT_MS,
				'reasons'        => $this->get_survey_reasons(),
				'i18n'           => $this->get_modal_i18n(),
			)
		);
	}

	/**
	 * Handle survey submission and relay to remote API.
	 *
	 * Validation failures return wp_send_json_error().
	 * Relay failures are fail-open: wp_send_json_success() with relayed=false.
	 *
	 * @return void
	 */
	public function ajax_submit_survey() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed.', 'opti-behavior' ),
				),
				403
			);
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to deactivate plugins.', 'opti-behavior' ),
				),
				403
			);
		}

		$survey_payload = $this->build_payload_from_request();
		if ( empty( $survey_payload['reason_key'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'A deactivation reason is required.', 'opti-behavior' ),
				),
				400
			);
		}

		$relay_result = $this->relay_payload_to_api( $survey_payload );

		wp_send_json_success(
			array(
				'accepted' => true,
				'relayed'  => $relay_result['success'],
			)
		);
	}

	/**
	 * Build sanitized payload from POST request.
	 *
	 * @return array
	 */
	private function build_payload_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in ajax_submit_survey().
		$reason_key_raw   = isset( $_POST['reason_key'] ) ? sanitize_key( wp_unslash( $_POST['reason_key'] ) ) : '';
		$reason_label_raw = isset( $_POST['reason_label'] ) ? sanitize_text_field( wp_unslash( $_POST['reason_label'] ) ) : '';
		$feedback_raw     = isset( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';
		$follow_up_raw    = isset( $_POST['follow_up_email'] ) ? sanitize_email( wp_unslash( $_POST['follow_up_email'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$reason_key = sanitize_key( $reason_key_raw );
		$reason_key = in_array(
			$reason_key,
			$this->get_allowed_reason_keys(),
			true
		) ? $reason_key : '';

		$reason_label = sanitize_text_field( $reason_label_raw );
		if ( '' === $reason_label && '' !== $reason_key ) {
			$reason_label = ucwords( str_replace( '_', ' ', $reason_key ) );
		}

		$feedback = sanitize_textarea_field( $feedback_raw );
		$feedback = substr( $feedback, 0, self::MAX_FEEDBACK_LENGTH );

		$follow_up_email = sanitize_email( $follow_up_raw );
		if ( ! is_email( $follow_up_email ) ) {
			$follow_up_email = '';
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $domain ) ) {
			$domain = '';
		}

		return array(
			'site_url'        => esc_url_raw( get_site_url() ),
			'domain'          => sanitize_text_field( strtolower( $domain ) ),
			'plugin_slug'     => plugin_basename( OPTI_BEHAVIOR_HEATMAP ),
			'plugin_version'  => defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '',
			'wp_version'      => get_bloginfo( 'version' ),
			'php_version'     => PHP_VERSION,
			'admin_language'  => $this->get_selected_admin_language(),
			'entry_point'     => 'plugin_row_deactivate_link',
			'reason_key'      => $reason_key,
			'reason_label'    => $reason_label,
			'feedback'        => $feedback,
			'follow_up_email' => $follow_up_email,
			'submitted_at_utc' => gmdate( 'c' ),
		);
	}

	/**
	 * Get deactivation reason keys/labels used by the survey UI.
	 *
	 * @return array
	 */
	private function get_survey_reasons() {
		return array(
			array(
				'key'   => 'missing_feature',
				'label' => __( 'I need a feature that is missing.', 'opti-behavior' ),
			),
			array(
				'key'   => 'not_user_friendly',
				'label' => __( 'The plugin is not easy to use.', 'opti-behavior' ),
			),
			array(
				'key'   => 'found_better_plugin',
				'label' => __( 'I found another plugin.', 'opti-behavior' ),
			),
			array(
				'key'   => 'temporary_deactivation',
				'label' => __( 'I am deactivating temporarily.', 'opti-behavior' ),
			),
			array(
				'key'   => 'too_many_bugs',
				'label' => __( 'I hit bugs or technical issues.', 'opti-behavior' ),
			),
			array(
				'key'   => 'too_expensive',
				'label' => __( 'Pricing is not a fit for me.', 'opti-behavior' ),
			),
			array(
				'key'   => 'other',
				'label' => __( 'Other reason.', 'opti-behavior' ),
			),
		);
	}

	/**
	 * Get allowed reason keys accepted by the AJAX contract.
	 *
	 * @return array
	 */
	private function get_allowed_reason_keys() {
		return array(
			'missing_feature',
			'not_user_friendly',
			'found_better_plugin',
			'temporary_deactivation',
			'too_many_bugs',
			'too_expensive',
			'other',
		);
	}

	/**
	 * Modal strings localized in PHP and consumed by JavaScript.
	 *
	 * @return array
	 */
	private function get_modal_i18n() {
		return array(
			'title'            => __( 'Quick feedback before you deactivate Opti-Behavior', 'opti-behavior' ),
			'intro'            => __( 'Could you tell us why you are deactivating? This helps us improve.', 'opti-behavior' ),
			'reasonLabel'      => __( 'Reason (required)', 'opti-behavior' ),
			'feedbackLabel'    => __( 'Additional details (optional)', 'opti-behavior' ),
			'feedbackHelp'     => __( 'Share anything that would help us improve your experience.', 'opti-behavior' ),
			'emailLabel'       => __( 'Email for follow-up (optional)', 'opti-behavior' ),
			'emailHelp'        => __( 'Leave your email only if you want a reply from our team.', 'opti-behavior' ),
			'cancel'           => __( 'Cancel', 'opti-behavior' ),
			'submit'           => __( 'Submit & Deactivate', 'opti-behavior' ),
			'submitting'       => __( 'Submitting...', 'opti-behavior' ),
			'close'            => __( 'Close', 'opti-behavior' ),
			'reasonRequired'   => __( 'Please choose a reason before deactivating.', 'opti-behavior' ),
			'invalidEmail'     => __( 'Please enter a valid email address or leave the field empty.', 'opti-behavior' ),
			'genericError'     => __( 'Submission failed. The plugin will still be deactivated.', 'opti-behavior' ),
		);
	}

	/**
	 * Relay payload to the OptiUser API.
	 *
	 * @param array $payload Survey payload.
	 * @return array
	 */
	private function relay_payload_to_api( $payload ) {
		$api_base = trailingslashit( $this->get_api_url() );
		$api_url  = $api_base . self::API_ENDPOINT;

		$response = wp_remote_post(
			$api_url,
			array(
				'body'      => wp_json_encode( $payload ),
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
				'timeout'   => 10,
				'sslverify' => $this->should_verify_ssl( $api_url ),
				'blocking'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'Survey relay failed: ' . $response->get_error_message() );
			return array(
				'success' => false,
			);
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			$this->debug_log( 'Survey relay failed with HTTP code: ' . $response_code );
			return array(
				'success' => false,
			);
		}

		return array(
			'success' => true,
		);
	}

	/**
	 * Resolve API base URL by environment.
	 *
	 * @return string
	 */
	private function get_api_url() {
		if ( $this->is_local_debug() ) {
			return 'http://localhost/API/'; // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- Dev-only URL gated by local debug mode.
		}

		return 'https://api.optiuser.com/';
	}

	/**
	 * Check if local debug mode is active.
	 *
	 * @return bool
	 */
	private function is_local_debug() {
		return ( defined( 'OPTI_BEHAVIOR_ENVIRONMENT' ) && 'local' === OPTI_BEHAVIOR_ENVIRONMENT
			&& defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Decide if SSL verification should remain enabled.
	 *
	 * @param string $url Target URL.
	 * @return bool
	 */
	private function should_verify_ssl( $url ) {
		return ( strpos( $url, 'http://localhost' ) !== 0 );
	}

	/**
	 * Resolve selected admin language with allow-list fallback.
	 *
	 * @return string
	 */
	private function get_selected_admin_language() {
		$selected_language = get_option( 'opti_behavior_admin_language', '' );

		if ( '' === $selected_language ) {
			$selected_language = determine_locale();
		}

		$allowed_languages = array( 'en_US', 'fr_FR', 'de_DE', 'es_ES', 'pt_BR', 'it_IT' );

		if ( ! in_array( $selected_language, $allowed_languages, true ) ) {
			return 'en_US';
		}

		return $selected_language;
	}

	/**
	 * Debug-only logger.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private function debug_log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
			error_log( '[Opti-Behavior Deactivation Survey] ' . $message );
		}
	}

	/**
	 * Resolve a stable asset version from filemtime or plugin version fallback.
	 *
	 * @param string $file_path Asset path.
	 * @return string
	 */
	private function resolve_asset_version( $file_path ) {
		if ( file_exists( $file_path ) ) {
			return (string) filemtime( $file_path );
		}

		return defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1.0.0';
	}
}
