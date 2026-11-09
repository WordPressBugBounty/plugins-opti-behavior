<?php
/**
 * "Calculating statistics…" indicator of the screens with filters, Free and Pro.
 *
 * Assets only: assets/js/opti-behavior-loading.js decides when it shows. Nothing
 * here reads or computes a figure.
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the indicator on the screens that have filters.
 */
class Opti_Behavior_Loading_Indicator {

	/** Script and style handle. */
	const HANDLE = 'opti-behavior-loading';

	/** Screens with filters (Pro screens exist only with Pro). */
	const PAGES = array(
		'opti-behavior-analytics',
		'opti-behavior-heatmaps',
		'opti-behavior-heatmap-detail',
		'opti-behavior-funnels',
		'opti-behavior-ab-testing',
		'opti-behavior-smart-insights',
		'opti-behavior-shop',
		'opti-behavior-recordings',
		'opti-behavior-errors',
		'opti-behavior-user-journey',
		'opti-behavior-form-analytics',
	);

	/**
	 * Hook the assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Is this screen one with filters?
	 *
	 * @param string $page Value of the `page` request field.
	 * @return bool
	 */
	public static function is_filter_screen( $page ) {
		return in_array( (string) $page, self::PAGES, true );
	}

	/**
	 * Enqueue on the screens with filters only.
	 *
	 * @return void
	 */
	public static function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen id only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! self::is_filter_screen( $page ) ) {
			return;
		}
		$js  = 'assets/js/opti-behavior-loading.js';
		$css = 'assets/css/opti-behavior-loading.css';
		wp_enqueue_style(
			self::HANDLE,
			OPTI_BEHAVIOR_HEATMAP_PLUGIN_URL . $css,
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . $css )
		);
		wp_enqueue_script(
			self::HANDLE,
			OPTI_BEHAVIOR_HEATMAP_PLUGIN_URL . $js,
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . $js ),
			false
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.optiBehaviorLoadingConfig = ' . wp_json_encode(
				array(
					'noStatsBox'  => 'opti-behavior-heatmap-detail' === $page, // This screen has its own single message.
					'text'        => __( 'Calculating statistics… This can take a moment.', 'opti-behavior' ),
					'optionsText' => __( 'Calculating the figures of each filter… This can take a moment.', 'opti-behavior' ),
				)
			) . ';',
			'before'
		);
	}
}
