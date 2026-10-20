<?php
/**
 * Brand mark of the Opti-Behavior screen headers (OPT-1504, design OPT-1501).
 *
 * Every Free and Pro screen keeps its own purple band; the screen icon tile is
 * replaced by the plugin logo on a white tile (`render_mark()`) and the product
 * name is printed right above the <h1> (`render_name( $edition )`). The name
 * follows the owner of the screen: Free screens "Opti-Behavior", Pro screens
 * "Opti-Behavior" + a "Pro" pill. No licence is read here.
 *
 * Pro calls it behind `class_exists( 'Opti_Behavior_Admin_Brand' )`: with an
 * older Free the Pro header stays as it was. CSS = assets/css/brand-header.css,
 * enqueued on every Opti-Behavior screen next to admin-notices.css.
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand mark + product name of the screen headers.
 */
class Opti_Behavior_Admin_Brand {

	/**
	 * Logo file under assets/ (the 256x256 plugin icon reduced to 128 px).
	 */
	const LOGO = 'images/report-icon.png';

	/**
	 * Logo size in CSS pixels (40 / 24 px tile / logo on phones: CSS).
	 */
	const LOGO_SIZE = 28;

	/**
	 * Markup of the logo tile that replaces the screen icon.
	 *
	 * @return string
	 */
	public static function mark_html() {
		return '<span class="ob-brand-mark"><img src="' . esc_url( OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . self::LOGO ) . '" width="' . (int) self::LOGO_SIZE . '" height="' . (int) self::LOGO_SIZE . '" alt=""></span>';
	}

	/**
	 * Markup of the product name printed above the screen title.
	 *
	 * @param string $edition 'free' (default) or 'pro' = owner of the screen.
	 * @return string
	 */
	public static function name_html( $edition = 'free' ) {
		// Product name, not translated.
		$pro = ( 'pro' === $edition ) ? ' <span class="ob-brand-name__pro">Pro</span>' : '';
		return '<span class="ob-brand-name">Opti-Behavior' . $pro . '</span>';
	}

	/**
	 * Print the logo tile.
	 */
	public static function render_mark() {
		echo self::mark_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}

	/**
	 * Print the product name.
	 *
	 * @param string $edition 'free' or 'pro'.
	 */
	public static function render_name( $edition = 'free' ) {
		echo self::name_html( $edition ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
	}
}
