<?php
/**
 * Options Class
 *
 * Manages plugin options with ArrayAccess interface.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options Class
 *
 * Provides array-like access to plugin options with validation support.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Heatmap_Options implements ArrayAccess {

	/**
	 * Return default option values for all known plugin option keys.
	 *
	 * New keys added here are automatically back-filled for existing installs
	 * via wp_parse_args() inside check().
	 *
	 * @since 1.0.4
	 * @return array Default option values.
	 */
	public static function get_defaults() {
		return array(
			// Privacy mode.
			'privacy_mode'               => 'anonymous',

			// Consent banner preference: 'auto' = use third-party if detected, else built-in;
			// 'builtin' = always use built-in; 'thirdparty' = always defer to third-party.
			'consent_banner_prefer'      => 'auto',

			// Built-in consent banner: toggle and position.
			'consent_banner_enabled'     => false,
			'consent_banner_position'    => 'bottom-bar',

			// Built-in consent banner: colors.
			'consent_banner_accent_color' => '#2e7d32',
			'consent_banner_bg_color'    => '#ffffff',
			'consent_banner_text_color'  => '#333333',

			// Built-in consent banner: copy (empty = use translatable defaults).
			'consent_banner_title'       => '',
			'consent_banner_message'     => '',

			// Click/scroll/move event batching cadence for the frontend
			// HeatmapTracker (assets/js/opti-behavior-heatmap-simple.js): a
			// batch is flushed once it reaches `ajax_bulk` events, or once
			// `ajax_interval` ms have passed since the last flush — whichever
			// happens first. These two keys previously had NO default here, so
			// `intval( $options['ajax_bulk'] )` / `intval( $options['ajax_interval'] )`
			// both localized to JS as 0, making every single click fire its own
			// individual, non-batched AJAX request instead of being grouped —
			// needlessly multiplying the number of independent, abortable
			// in-flight requests race-competing against the sendBeacon
			// session-end call at unload. See Bug #3 (spam misclassification
			// investigation).
			// Perf fix (customer report 2026-07): 5000ms default made every
			// visitor POST to admin-ajax.php (= full WP bootstrap) every 5s,
			// saturating PHP-FPM on busy/plugin-heavy sites. 15000ms keeps
			// batching responsive while cutting request volume ~3x; the
			// sendBeacon unload flush still guarantees tail delivery.
			// Perf Fix B (customer report 2026-08): raised 15000ms -> 30000ms.
			// The Free heatmap flush was a secondary contributor to the observed
			// ~8-11s admin-ajax cadence alongside the Pro recorder; 30s halves its
			// request volume again. Flush is still size-triggered (`ajax_bulk`)
			// and the sendBeacon unload flush guarantees tail delivery.
			'ajax_bulk'                   => 5,
			'ajax_interval'               => 30000,

			// Legacy data-retention period in months for the daily cron's
			// delete_old_data() call. 0 = retention disabled (never delete).
			// This key historically had NO default and no settings UI, so
			// $options['period'] resolved to null → absint(null) = 0 → the
			// old "< 1 → clamp to 1" logic in delete_old_data() silently
			// wiped ALL data older than 1 month, every day, on every
			// install. Retention deletion is now solely the job of the
			// Smart Cleanup service unless a period is explicitly set.
			'period'                      => 0,
		);
	}

	/**
	 * Options array.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $options = array();

	/**
	 * Validation callback.
	 *
	 * @since 1.0.0
	 * @var callable|null
	 */
	private $checker = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param callable|null $checker Optional validation callback.
	 */
	public function __construct( $checker = null ) {
		$this->checker = $checker;
		$this->load();
	}

	/**
	 * Load options from database.
	 *
	 * @since 1.0.0
	 */
	public function load() {
		$options = maybe_unserialize( get_option( 'opti_behavior_heatmap_option' ) );
		if ( ! $options ) {
			$options = array();
		}
		$this->options = $this->check( $options, null );
	}

	/**
	 * Save options to database.
	 *
	 * @since 1.0.0
	 * @param array $options Options to save.
	 */
	public function save( $options = array() ) {
		foreach ( $options as $key => $value ) {
			if ( null === $value && isset( $this->options[ $key ] ) ) {
				$options[ $key ] = $this->options[ $key ];
			}
		}
		$options = array_merge( $this->options, $options );

		$this->update( $options, true );
	}

	/**
	 * Validate options using callback and fill in any missing defaults.
	 *
	 * wp_parse_args() ensures that every key returned by get_defaults() is
	 * present in the final array, so existing installs automatically receive
	 * default values for newly introduced option keys on first load.
	 *
	 * @since 1.0.0
	 * @param array      $new_options New options.
	 * @param array|null $old         Old options, null to skip change trigger.
	 * @return array Validated options with defaults applied.
	 */
	private function check( $new_options, $old = null ) {
		if ( is_callable( $this->checker ) ) {
			$checker     = $this->checker;
			$new_options = $checker( $new_options, $old );
		}

		// Back-fill defaults for any keys that are not yet present in the DB.
		return wp_parse_args( $new_options, self::get_defaults() );
	}

	/**
	 * Update options in memory and database.
	 *
	 * @since 1.0.0
	 * @param array $options Options to update.
	 * @param bool  $trigger Whether to trigger validation callback.
	 */
	private function update( $options, $trigger = true ) {
		$this->options = $this->check( $options, $trigger ? $this->options : null );
		update_option( 'opti_behavior_heatmap_option', maybe_serialize( $this->options ), 'yes' );
	}

	/**
	 * Check if option exists.
	 *
	 * @since 1.0.0
	 * @param string $offset Option key.
	 * @return bool True if option exists.
	 */
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return array_key_exists( $offset, $this->options ) && isset( $this->options[ $offset ] );
	}

	/**
	 * Get option value.
	 *
	 * @since 1.0.0
	 * @param string $offset Option key.
	 * @return mixed Option value or null.
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return array_key_exists( $offset, $this->options ) ? $this->options[ $offset ] : null;
	}

	/**
	 * Set option value.
	 *
	 * @since 1.0.0
	 * @param string $offset Option key.
	 * @param mixed  $value  Option value.
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		$options            = $this->options;
		$options[ $offset ] = $value;
		$this->update( $options, false );
	}

	/**
	 * Unset option.
	 *
	 * @since 1.0.0
	 * @param string $offset Option key.
	 */
	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		$options = $this->options;
		unset( $options[ $offset ] );
		$this->update( $options, false );
	}
}
