<?php
/**
 * Errors Tracking Upgrade Page
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
	'placement' => 'pro-page-errors',
	'icon'     => 'shield-alert',
	'title'    => __( 'Errors Tracking', 'opti-behavior' ),
	'promise'  => __( 'Find the JavaScript errors, broken links and slow pages your visitors run into.', 'opti-behavior' ),
	'image'    => 'preview-errors.svg',
	'caption'  => __( 'Errors, friction and page speed, page by page.', 'opti-behavior' ),
	'cases'    => array(
		__( 'A JavaScript error that breaks a button on one browser, with its stack trace and the pages it hits.', 'opti-behavior' ),
		__( 'Rage clicks and dead clicks: places where visitors click again and again and nothing happens.', 'opti-behavior' ),
		__( 'Broken links and 404s found on real visits; doubtful reports are rechecked by your server before they are listed.', 'opti-behavior' ),
	),
	'compare'  => array(
		array( __( 'JavaScript and network errors', 'opti-behavior' ), false, true ),
		array( __( 'Rage clicks and dead clicks', 'opti-behavior' ), false, true ),
		array( __( 'Page speed by page and device (LCP, CLS, INP)', 'opti-behavior' ), false, true ),
		array( __( 'Broken links and 404s', 'opti-behavior' ), false, true ),
		array( __( 'These signals in Smart Insights', 'opti-behavior' ), false, true ),
	),
	'faq'      => array(
		array(
			__( 'Does Free show any of this?', 'opti-behavior' ),
			__( 'No. Free Smart Insights works on traffic, funnels and click data. Pro adds error, friction, speed and broken-link signals to it.', 'opti-behavior' ),
		),
		array(
			__( 'Which speed metrics?', 'opti-behavior' ),
			__( 'LCP, CLS and INP, measured on real visits, by page and by device.', 'opti-behavior' ),
		),
		array(
			__( 'How long is the data kept?', 'opti-behavior' ),
			__( 'Error and performance data is kept 30 days by default, on your WordPress site.', 'opti-behavior' ),
		),
	),
	'cta_text' => __( 'Download the Pro version now and get access to Errors Tracking and all premium features for 6 months — completely free. No credit card, no commitment.', 'opti-behavior' ),
);

include __DIR__ . '/partials/pro-feature-page.php';
