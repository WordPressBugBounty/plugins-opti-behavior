<?php
/**
 * Errors Tracking Upgrade Page
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
				<div class="recordings-icon"><i data-lucide="shield-alert"></i></div>
				<div class="recordings-title-text">
					<h1 class="recordings-title"><?php esc_html_e( 'Errors Tracking', 'opti-behavior' ); ?></h1>
					<div class="recordings-subtitle"><?php esc_html_e( 'Monitor JavaScript errors, friction events, performance issues, and broken links across your site.', 'opti-behavior' ); ?></div>
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
				<?php esc_html_e( 'Errors Tracking is a powerful premium module that helps you detect, monitor, and resolve issues affecting your users in real time.', 'opti-behavior' ); ?>
				<br />
				<?php esc_html_e( 'Get full access to all Pro features for 6 months — completely free, no credit card required. Catch JavaScript errors before your users report them.', 'opti-behavior' ); ?>
			</p>

			<div class="upgrade-features">
				<h3><?php esc_html_e( 'What you get with Errors Tracking:', 'opti-behavior' ); ?></h3>
				<ul class="features-list">
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'JavaScript error monitoring with stack traces and severity levels', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Friction events detection (rage clicks, dead clicks, thrashed cursors)', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Core Web Vitals performance metrics (LCP, FID, CLS)', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Broken links and 404 error detection', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Real-time dashboard with trend charts and analytics', 'opti-behavior' ); ?></li>
					<li><i data-lucide="check-circle"></i> <?php esc_html_e( 'Error breakdown by type and top affected pages', 'opti-behavior' ); ?></li>
				</ul>
			</div>

			<div class="upgrade-cta">
				<div class="free-badge">
					<i data-lucide="gift"></i>
					<span><?php esc_html_e( '6-Month Free Trial!', 'opti-behavior' ); ?></span>
				</div>

				<p class="cta-text">
					<?php esc_html_e( 'Download the Pro version now and get access to Errors Tracking and all premium features for 6 months — completely free. No credit card, no commitment.', 'opti-behavior' ); ?>
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
	</div>
</div>
