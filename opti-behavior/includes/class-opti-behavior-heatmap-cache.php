<?php
/**
 * Heatmap Cache Manager
 *
 * Manages caching of aggregated heatmap data using WordPress transients only.
 * Raw heatmap data is stored directly in opti-behavior-data/{url_hash}/clicks|moves|scrolls/
 *
 * @package OptiBehaviorPro
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Opti_Behavior_Heatmap_Cache', false ) ) {
	return;
}

/**
 * Class Opti_Behavior_Heatmap_Cache
 *
 * Handles transient caching for processed heatmap data (no file cache).
 */
class Opti_Behavior_Heatmap_Cache {

	/**
	 * Transient cache expiration time.
	 */
	const TRANSIENT_EXPIRATION = 3600; // 1 hour.

	/**
	 * Get cached heatmap data from transient.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type (desktop, mobile, tablet).
	 * @param string $type Heatmap type (click, move, scroll).
	 * @param string $date_range Date range identifier.
	 * @param string $cache_key Optional custom cache key.
	 * @return array|false Cached data or false if not found.
	 */
	public function get( $page_id, $device, $type, $date_range, $cache_key = '' ) {
		if ( empty( $cache_key ) ) {
			$cache_key = $this->generate_cache_key( $page_id, $device, $type, $date_range );
		}

		return get_transient( $cache_key );
	}

	/**
	 * Save heatmap data to transient cache.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type.
	 * @param string $type Heatmap type.
	 * @param string $date_range Date range identifier.
	 * @param array  $data Heatmap data to cache.
	 * @param string $cache_key Optional custom cache key.
	 * @return bool True on success, false on failure.
	 */
	public function set( $page_id, $device, $type, $date_range, $data, $cache_key = '' ) {
		if ( empty( $cache_key ) ) {
			$cache_key = $this->generate_cache_key( $page_id, $device, $type, $date_range );
		}

		return set_transient( $cache_key, $data, self::TRANSIENT_EXPIRATION );
	}

	/**
	 * Clear cache for specific page and parameters.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type (optional, clears all if null).
	 * @param string $type Heatmap type (optional, clears all if null).
	 * @return bool True on success.
	 */
	public function clear( $page_id, $device = null, $type = null ) {
		if ( null === $device || null === $type ) {
			return $this->clear_all_for_page( $page_id );
		}

		// Clear specific transients.
		$date_ranges = array( 'all', 'today', 'yesterday', 'last7days', 'last30days' );
		foreach ( $date_ranges as $range ) {
			$cache_key = $this->generate_cache_key( $page_id, $device, $type, $range );
			delete_transient( $cache_key );
		}

		return true;
	}

	/**
	 * Clear all caches for a specific page.
	 *
	 * @param int $page_id Page ID.
	 * @return bool True on success.
	 */
	private function clear_all_for_page( $page_id ) {
		$devices = array( 'desktop', 'mobile', 'tablet' );
		$types   = array( 'click', 'move', 'scroll' );

		foreach ( $devices as $device ) {
			foreach ( $types as $type ) {
				$this->clear( $page_id, $device, $type );
			}
		}

		return true;
	}

	/**
	 * Generate cache key.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type.
	 * @param string $type Heatmap type.
	 * @param string $date_range Date range.
	 * @return string Cache key.
	 */
	private function generate_cache_key( $page_id, $device, $type, $date_range ) {
		return sprintf(
			'opti_heatmap_%d_%s_%s_%s',
			$page_id,
			sanitize_key( $device ),
			sanitize_key( $type ),
			sanitize_key( $date_range )
		);
	}

}
