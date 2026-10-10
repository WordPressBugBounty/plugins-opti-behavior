<?php
/**
 * User Journeys Upgrade Page
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
	'placement' => 'pro-page-journeys',
	'icon'     => 'git-branch',
	'title'    => __( 'User Journeys', 'opti-behavior' ),
	'promise'  => __( 'See the real paths visitors take through your site, without defining them first.', 'opti-behavior' ),
	'image'    => 'preview-journeys.svg',
	'caption'  => __( 'Visitor paths from entry page to exit page, as a flow diagram.', 'opti-behavior' ),
	'cases'    => array(
		__( 'Which entry pages lead visitors deeper into the site, and which send them away.', 'opti-behavior' ),
		__( 'The page where most journeys stop.', 'opti-behavior' ),
		__( 'Paths you did not plan. Turn the best one into a Funnel to measure it.', 'opti-behavior' ),
	),
	'compare'  => array(
		array( __( 'Top pages (Traffic)', 'opti-behavior' ), true, true ),
		array( __( 'Funnels with the steps you choose', 'opti-behavior' ), true, true ),
		array( __( 'Flow diagram of real paths', 'opti-behavior' ), false, true ),
		array( __( 'Common paths and drop-off points', 'opti-behavior' ), false, true ),
		array( __( 'Date range filter on paths', 'opti-behavior' ), false, true ),
	),
	'faq'      => array(
		array(
			__( 'How is it different from Funnels?', 'opti-behavior' ),
			__( 'A Funnel checks the steps you choose. User Journeys shows the paths visitors actually take, including the ones you did not expect.', 'opti-behavior' ),
		),
		array(
			__( 'Which period can I look at?', 'opti-behavior' ),
			__( 'Any date range within the data you keep (raw data is kept 365 days by default).', 'opti-behavior' ),
		),
	),
	'cta_text' => __( 'Download the Pro version now and get access to User Journeys and all premium features for 6 months — completely free. No credit card, no commitment.', 'opti-behavior' ),
);

include __DIR__ . '/partials/pro-feature-page.php';
