<?php
/**
 * Session Recordings Upgrade Page
 * Shown when PRO plugin is not active
 *
 * Content only: the page is rendered by admin/partials/pro-feature-page.php
 * (Download button, access code, demo button).
 *
 * @package OptiBehavior
 * @version 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$opti_behavior_pro_page = array(
	'placement' => 'pro-page-recordings',
	'icon'     => 'video',
	'title'    => __( 'Session Recordings', 'opti-behavior' ),
	'promise'  => __( 'Watch how visitors move through your pages. Recordings are stored on your own WordPress site.', 'opti-behavior' ),
	'image'    => 'preview-recordings.svg',
	'caption'  => __( 'A visitor session replayed in the WordPress admin.', 'opti-behavior' ),
	'cases'    => array(
		__( 'Where visitors hesitate: the scroll back up, the pause before they leave.', 'opti-behavior' ),
		__( 'Why a form or checkout step is abandoned, by watching the visits that stopped there.', 'opti-behavior' ),
		__( 'How a new layout behaves on real visits, across several pages of the same session.', 'opti-behavior' ),
	),
	'compare'  => array(
		array( __( 'Click heatmaps', 'opti-behavior' ), true, true ),
		array( __( 'Move, scroll and attention heatmaps', 'opti-behavior' ), false, true ),
		array( __( 'Session replay, several pages per session', 'opti-behavior' ), false, true ),
		array( __( 'Recording filters', 'opti-behavior' ), false, true ),
	),
	'faq'      => array(
		array(
			__( 'Where are recordings stored?', 'opti-behavior' ),
			__( 'On your WordPress site (database and uploads folder). To count your recording quota, Pro sends only an installation ID and a session ID to api.optiuser.com.', 'opti-behavior' ),
		),
		array(
			__( 'How long are recordings kept?', 'opti-behavior' ),
			__( 'Recording files are kept 90 days by default. You can change this in Settings.', 'opti-behavior' ),
		),
		array(
			__( 'Is there a limit?', 'opti-behavior' ),
			__( 'Yes, a recording quota applies. It is set by your Pro licence.', 'opti-behavior' ),
		),
	),
	'cta_text' => __( 'Download the Pro version now and get access to Session Recordings and all premium features for 6 months — completely free. No credit card, no commitment.', 'opti-behavior' ),
);

include __DIR__ . '/partials/pro-feature-page.php';
