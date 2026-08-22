<?php
/**
 * Dashboard Cache Helper
 *
 * Provides cache-invalidation + TTL helpers for the Analytics Dashboard
 * transient caches (summary stats, top engaged users).
 *
 * Before 1.2.9 the dashboard used a 15-minute TTL with no invalidation path,
 * so stat cards stayed stale for up to 15 minutes after new visitor activity.
 * These helpers reduce TTL to 30 s (filterable) and flush transients whenever
 * a session row is inserted / updated.
 *
 * @package opti-behavior
 * @since 1.2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'opti_behavior_dashboard_cache_ttl' ) ) {
	/**
	 * Short-lived dashboard cache TTL in seconds.
	 *
	 * Defaults to 30 s so stat cards catch up with new visitor activity quickly.
	 * Operators can raise it via the `opti_behavior_dashboard_cache_ttl` filter
	 * if their traffic warrants a longer cache.
	 *
	 * @since 1.2.9
	 * @return int Cache lifetime in seconds.
	 */
	function opti_behavior_dashboard_cache_ttl() {
		$ttl = (int) apply_filters( 'opti_behavior_dashboard_cache_ttl', 30 );
		return $ttl > 0 ? $ttl : 30;
	}
}

if ( ! function_exists( 'opti_behavior_dashboard_widget_cache_registry' ) ) {
	/**
	 * Central registry of every Analytics Dashboard widget's transient prefix(es).
	 *
	 * Single source of truth for which cache-key prefixes belong to the
	 * dashboard. {@see opti_behavior_invalidate_dashboard_caches()} sweeps
	 * exactly (and only) the prefixes listed here, and regression tests walk
	 * this same registry — so a widget added here is automatically covered by
	 * both the invalidation sweep and the coverage test with no other wiring.
	 * This closes the Bug #4 gap where 8 Traffic Overview widgets cached
	 * results but were never listed in the sweep, so a stale/empty transient
	 * could never be invalidated by new activity.
	 *
	 * @since 1.7.1
	 * @return array<string,array<int,string>> Map of widget_key => list of transient key prefixes.
	 */
	function opti_behavior_dashboard_widget_cache_registry() {
		$registry = array(
			'summary_stats'             => array( 'opti_behavior_summary_stats_' ),
			'top_users'                 => array( 'opti_behavior_top_users_', 'optibehavior_top_users_' ),
			'dashboard_traffic_series'  => array( 'opti_behavior_dashboard_traffic_series_' ),
			'traffic_classification'    => array( 'opti_behavior_traffic_class_' ),
			'user_intent'               => array( 'opti_behavior_user_intent_' ),
			'device_types'              => array( 'opti_behavior_device_types_' ),
			'countries'                 => array( 'opti_behavior_countries_' ),
			'browsers'                  => array( 'opti_behavior_browsers_' ),
			'operating_systems'         => array( 'opti_behavior_os_' ),
			'screen_resolution'         => array( 'opti_behavior_screen_res_' ),
			'referrers'                 => array( 'opti_behavior_referrers_' ),
			'top_pages'                 => array( 'opti_behavior_top_pages_' ),
			'new_vs_returning_visitors' => array( 'opti_behavior_new_returning_' ),
			'visited_directories'       => array( 'opti_behavior_visited_directories_' ),
			'bot_traffic'               => array( 'opti_behavior_bot_traffic_' ),
		);

		/**
		 * Filter the Analytics Dashboard widget cache-key prefix registry.
		 *
		 * @since 1.7.1
		 * @param array<string,array<int,string>> $registry Map of widget_key => transient key prefixes.
		 */
		return (array) apply_filters( 'opti_behavior_dashboard_widget_cache_registry', $registry );
	}
}

if ( ! function_exists( 'opti_behavior_invalidate_dashboard_caches' ) ) {
	/**
	 * Flush every registered Analytics Dashboard widget's transient cache.
	 *
	 * Safe to call frequently — only deletes transient rows; next dashboard hit
	 * re-aggregates. The `LIKE` sweep is built dynamically from
	 * {@see opti_behavior_dashboard_widget_cache_registry()} so every widget
	 * shares one invalidation path and adding a widget to the registry is the
	 * only step needed to wire it into this sweep (Bug #4 fix — previously
	 * only `summary_stats`/`top_users`/`dashboard_traffic_series` were swept;
	 * 8 other widgets cached results with no invalidation path at all).
	 * Callers that change a session's classification call this via
	 * {@see Opti_Behavior_Stats_Spam_Filter::clear_traffic_classification_caches()}
	 * and the ajax handler's `clear_behavior_classification_caches()`.
	 *
	 * @since 1.2.9
	 * @return int Number of option rows deleted.
	 */
	function opti_behavior_invalidate_dashboard_caches() {
		global $wpdb;

		$prefixes = array();
		foreach ( opti_behavior_dashboard_widget_cache_registry() as $widget_prefixes ) {
			foreach ( (array) $widget_prefixes as $prefix ) {
				$prefix = (string) $prefix;
				if ( '' !== $prefix ) {
					$prefixes[] = $prefix;
				}
			}
		}
		$prefixes = array_values( array_unique( $prefixes ) );

		if ( empty( $prefixes ) ) {
			return 0;
		}

		$where_parts = array();
		$params      = array();
		foreach ( $prefixes as $prefix ) {
			$like          = $wpdb->esc_like( $prefix ) . '%';
			$where_parts[] = 'option_name LIKE %s';
			$params[]      = '_transient_' . $like;
			$where_parts[] = 'option_name LIKE %s';
			$params[]      = '_transient_timeout_' . $like;
		}

		$where = implode( ' OR ', $where_parts );

		// Read the matching option names first so every individual per-option
		// object-cache entry can be busted below. A direct SQL DELETE bypasses
		// delete_option()/delete_transient(), so on installs with a persistent
		// object cache (Redis/Memcached) the stale value would otherwise keep
		// being served from cache even though the DB row is gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic LIKE sweep built from an internal, hardcoded prefix registry (not user input); every fragment is still passed through $wpdb->prepare().
		$option_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE {$where}", $params ) );

		$sql = "DELETE FROM {$wpdb->options} WHERE " . $where;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic LIKE sweep built from an internal, hardcoded prefix registry (not user input); every fragment is still passed through $wpdb->prepare().
		$rows = (int) $wpdb->query( $wpdb->prepare( $sql, $params ) );

		// Bust the cached alloptions blob so the next request doesn't serve the
		// deleted transient keys from memory.
		wp_cache_delete( 'alloptions', 'options' );

		// Bust each individual option's object-cache entry too. Transients are
		// stored with autoload=no, so WP caches them per-option-name under the
		// 'options' group rather than inside the 'alloptions' blob.
		foreach ( (array) $option_names as $option_name ) {
			wp_cache_delete( $option_name, 'options' );
		}

		/**
		 * Fires after dashboard caches are flushed.
		 *
		 * @since 1.2.9
		 * @param int $rows Number of option rows removed.
		 */
		do_action( 'opti_behavior_dashboard_caches_invalidated', $rows );

		return $rows;
	}
}

if ( ! function_exists( 'opti_behavior_heatmap_cache_version' ) ) {
	/**
	 * Current heatmap cache namespace version.
	 *
	 * Every heatmap transient key embeds this integer. Bumping it (via
	 * {@see opti_behavior_flush_heatmap_caches()}) instantly invalidates ALL
	 * heatmap caches in O(1) without a DELETE … LIKE sweep, because the next
	 * lookup builds a brand-new key namespace that has no stored rows.
	 *
	 * @since 1.6.6
	 * @return int Version >= 1.
	 */
	function opti_behavior_heatmap_cache_version() {
		$version = (int) get_option( 'opti_behavior_heatmap_cache_ver', 1 );
		return $version > 0 ? $version : 1;
	}
}

if ( ! function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
	/**
	 * Invalidate every heatmap aggregate / stats / table cache.
	 *
	 * Called whenever heatmap data is ingested (file write + mapping upsert) or
	 * deleted, so cached counts can never drift from the underlying files. This
	 * only bumps a version counter, so it is cheap and safe to call on every
	 * ingest hit.
	 *
	 * @since 1.6.6
	 * @return int New cache version.
	 */
	function opti_behavior_flush_heatmap_caches() {
		$next = opti_behavior_heatmap_cache_version() + 1;
		// Autoload so the version is available without an extra query on reads.
		update_option( 'opti_behavior_heatmap_cache_ver', $next, true );

		/**
		 * Fires after heatmap caches are invalidated.
		 *
		 * @since 1.6.6
		 * @param int $next New cache version.
		 */
		do_action( 'opti_behavior_heatmap_caches_invalidated', $next );

		return $next;
	}
}

if ( ! function_exists( 'opti_behavior_heatmap_cache_key' ) ) {
	/**
	 * Build a namespaced heatmap transient key.
	 *
	 * The key embeds the current cache version so a flush rotates every key at
	 * once. `$parts` is hashed, so callers can pass arbitrary identifying data
	 * (page-id sets, date ranges, spam flag, device, request params).
	 *
	 * @since 1.6.6
	 * @param string $kind  Short cache family identifier (e.g. 'stats', 'metrics').
	 * @param array  $parts Identifying parts to hash into the key.
	 * @return string Transient key (<= 45 chars, safe for the options table).
	 */
	function opti_behavior_heatmap_cache_key( $kind, $parts ) {
		$seed = wp_json_encode( $parts );
		if ( false === $seed ) {
			$seed = maybe_serialize( $parts );
		}
		return 'ob_hm_' . $kind . '_' . opti_behavior_heatmap_cache_version() . '_' . md5( (string) $seed );
	}
}

if ( ! function_exists( 'opti_behavior_heatmap_cache_ttl' ) ) {
	/**
	 * TTL (seconds) for heatmap aggregate/table caches.
	 *
	 * Caches are invalidated precisely on ingest/delete, so the TTL is only a
	 * safety net against a missed invalidation path. Defaults to 15 minutes and
	 * is filterable.
	 *
	 * @since 1.6.6
	 * @return int Cache lifetime in seconds.
	 */
	function opti_behavior_heatmap_cache_ttl() {
		$default = 15 * MINUTE_IN_SECONDS;
		$ttl     = (int) apply_filters( 'opti_behavior_heatmap_cache_ttl', $default );
		return $ttl > 0 ? $ttl : $default;
	}
}

if ( ! function_exists( 'opti_behavior_is_force_refresh_requested' ) ) {
	/**
	 * Whether the current request asks for a dashboard force-refresh.
	 *
	 * Accepts either `force_refresh=1` in GET or POST. Used by the dashboard
	 * query paths to bypass the transient cache on explicit user action.
	 *
	 * @since 1.2.9
	 * @return bool True when caller requested a cache bypass.
	 */
	function opti_behavior_is_force_refresh_requested() {
		// Analytics dashboard data requests opt out of every short-lived dashboard
		// transient cache so each rendered number is computed live/exact from the
		// current DB (product requirement: no stale analytics values). The AJAX
		// handler sets this request-scoped flag for the dashboard-data action.
		if ( ! empty( $GLOBALS['opti_behavior_force_live_query'] ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag; no state change performed by reading it.
		if ( isset( $_REQUEST['force_refresh'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '1' === sanitize_text_field( wp_unslash( $_REQUEST['force_refresh'] ) );
		}
		return false;
	}
}
