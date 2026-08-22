<?php
/**
 * Session Recordings Upgrade Page
 * Shown when PRO plugin is not active
 *
 * @package OptiBehavior
 * @version 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build download page URL pointing to the live optiuser.com download page.
// Requests a one-time access code from the API so the download page can verify
// the visitor came from a real WordPress admin upgrade page (cross-domain protection).
$opti_behavior_current_user = wp_get_current_user();
$opti_behavior_access_code  = '';
$opti_behavior_cache_key    = 'ob_dl_access_' . md5( $opti_behavior_current_user->user_login . site_url() );
$opti_behavior_access_code  = get_transient( $opti_behavior_cache_key );

if ( ! $opti_behavior_access_code ) {
	$opti_behavior_api_response = wp_remote_post(
		'https://api.optiuser.com/request-download-access',
		array(
			'body'    => wp_json_encode(
				array(
					'site_url' => site_url(),
					'username' => $opti_behavior_current_user->user_login,
					'email'    => $opti_behavior_current_user->user_email,
				)
			),
			'headers' => array( 'Content-Type' => 'application/json' ),
			'timeout' => 10,
		)
	);
	if ( ! is_wp_error( $opti_behavior_api_response ) ) {
		$opti_behavior_api_body = json_decode( wp_remote_retrieve_body( $opti_behavior_api_response ), true );
		if ( ! empty( $opti_behavior_api_body['data']['access_code'] ) ) {
			$opti_behavior_access_code = $opti_behavior_api_body['data']['access_code'];
			set_transient( $opti_behavior_cache_key, $opti_behavior_access_code, 10 * MINUTE_IN_SECONDS );
		}
	}
}

$opti_behavior_download_url = add_query_arg(
	array(
		'site_url'    => rawurlencode( site_url() ),
		'username'    => rawurlencode( $opti_behavior_current_user->user_login ),
		'email'       => rawurlencode( $opti_behavior_current_user->user_email ),
		'access_code' => rawurlencode( $opti_behavior_access_code ),
	),
	'https://optiuser.com/opti-behavior/ob-download-pro/'
);
?>

<div class="wrap opti-behavior-recordings-upgrade-page">
	<!-- Header -->
	<div class="recordings-header">
		<div class="recordings-header-content">
			<div class="recordings-title-section">
				<div class="recordings-icon"><i data-lucide="video"></i></div>
				<div class="recordings-title-text">
					<h1 class="recordings-title"><?php esc_html_e( 'Session Recordings', 'opti-behavior' ); ?></h1>
					<div class="recordings-subtitle"><?php esc_html_e( 'Watch real user sessions to understand behavior patterns and identify UX issues.', 'opti-behavior' ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- Upgrade Content -->
	<div class="recordings-upgrade-content">
		<div class="upgrade-card">
			<div class="upgrade-icon">
				<i data-lucide="sparkles" style="width: 64px; height: 64px; color: #2271b1;"></i>
			</div>

			<h2 class="upgrade-title"><?php esc_html_e( 'Try Pro FREE for 6 Months!', 'opti-behavior' ); ?></h2>

			<p class="upgrade-description">
				<?php esc_html_e( 'Session Recordings is a premium module that allows you to watch real user sessions, understand behavior patterns, and identify UX issues.', 'opti-behavior' ); ?>
				<br />
				<?php esc_html_e( 'Get full access to all Pro features for 6 months — completely free, no credit card required. After the trial, you will continue to receive a free monthly quota.', 'opti-behavior' ); ?>
			</p>

			<div class="upgrade-features">
				<h3><?php esc_html_e( 'What you get with Session Recordings:', 'opti-behavior' ); ?></h3>
				<ul class="features-list">
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Watch real user sessions in replay', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Track clicks, scrolls, and form interactions', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Identify UX issues and friction points', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Multi-page session tracking', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Advanced filtering and search', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Privacy-focused with data masking', 'opti-behavior' ); ?></li>
				</ul>
			</div>

			<div class="upgrade-cta">
				<div class="free-badge">
					<i data-lucide="gift"></i>
					<span><?php esc_html_e( '6-Month Free Trial!', 'opti-behavior' ); ?></span>
				</div>

				<p class="cta-text">
					<?php esc_html_e( 'Download the Pro version now and get access to Session Recordings and all premium features for 6 months — completely free. No credit card, no commitment.', 'opti-behavior' ); ?>
				</p>

				<a href="#" class="button button-primary button-hero opti-behavior-download-cta" data-ob-download="<?php echo esc_attr( $opti_behavior_download_url ); ?>" onclick="event.preventDefault();window.open(this.getAttribute('data-ob-download'),'_blank','noopener,noreferrer');">
					<i data-lucide="download"></i>
					<?php esc_html_e( 'Download Pro — Free for 6 Months', 'opti-behavior' ); ?>
				</a>

				<p class="help-text">
					<?php esc_html_e( 'Need help installing? Visit our documentation or contact support.', 'opti-behavior' ); ?>
				</p>
			</div>
		</div>

		<!-- Preview Screenshot -->
		<div class="upgrade-preview">
			<h3><?php esc_html_e( 'Preview: Session Recordings Dashboard', 'opti-behavior' ); ?></h3>
			<div class="preview-placeholder">
				<i data-lucide="play-circle" style="width: 128px; height: 128px; color: #ccc;"></i>
				<p><?php esc_html_e( 'Session recording player with timeline, events, and visitor data', 'opti-behavior' ); ?></p>
			</div>
		</div>
	</div>
</div>
