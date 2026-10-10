<?php
/**
 * Form Analytics Upgrade Page
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
	'placement' => 'pro-page-forms',
	'icon'     => 'file-input',
	'title'    => __( 'Form Analytics', 'opti-behavior' ),
	'promise'  => __( 'See which form field makes visitors give up.', 'opti-behavior' ),
	'image'    => 'preview-forms.svg',
	'caption'  => __( 'Field-by-field drop-off of a contact form.', 'opti-behavior' ),
	'cases'    => array(
		__( 'The exact field where visitors give up.', 'opti-behavior' ),
		__( 'Fields that make people retype (refills) or show errors.', 'opti-behavior' ),
		__( 'Fields almost everyone leaves empty: candidates to remove.', 'opti-behavior' ),
	),
	'compare'  => array(
		array( __( 'Form submission as a Funnel goal', 'opti-behavior' ), true, true ),
		array( __( 'Field-by-field drop-off funnel', 'opti-behavior' ), false, true ),
		array( __( 'Time per field, errors, refills, empty fields', 'opti-behavior' ), false, true ),
		array( __( 'Form abandonment and conversion rate', 'opti-behavior' ), false, true ),
		array( __( 'Detects Contact Form 7, WPForms, Gravity Forms, WooCommerce cart / checkout', 'opti-behavior' ), false, true ),
	),
	'faq'      => array(
		array(
			__( 'Does it record what visitors type?', 'opti-behavior' ),
			__( 'No. It sends the field name, the field type and timings, never the value typed. Password and hidden fields are ignored.', 'opti-behavior' ),
		),
		array(
			__( 'Which forms are detected?', 'opti-behavior' ),
			__( 'Contact Form 7, WPForms, Gravity Forms, and the WooCommerce cart and checkout.', 'opti-behavior' ),
		),
		array(
			__( 'Can Free track my forms at all?', 'opti-behavior' ),
			__( 'Partly. A Funnel can count a form submission as its goal. The field-by-field analysis needs Pro.', 'opti-behavior' ),
		),
	),
	'cta_text' => __( 'Download the Pro version now and get access to Form Analytics and all premium features for 6 months — completely free. No credit card, no commitment.', 'opti-behavior' ),
);

include __DIR__ . '/partials/pro-feature-page.php';
