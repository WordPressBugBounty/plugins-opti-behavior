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
			// Teaser chips above the Heatmaps / Funnels / A-B sections. Written
			// with the same 30 s TTL by get_section_summaries()
			// (trait-opti-behavior-data-helpers.php), so they have to be swept
			// by the invalidator and the dirty sweep like every other widget
			// (QA-B-DASH-027).
			'section_summaries'         => array( 'opti_behavior_section_summaries_' ),
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

if ( ! function_exists( 'opti_behavior_delete_transients_by_prefix' ) ) {
	/**
	 * Delete every transient whose key starts with one of the given prefixes.
	 *
	 * Lock-safe replacement for `DELETE … WHERE option_name LIKE '_transient_x%'`.
	 * That pattern starts with an unescaped `_` (a single-character SQL wildcard),
	 * so MySQL cannot use the option_name index: every call full-scanned
	 * wp_options while InnoDB held next-key locks on each row it walked. With
	 * several visitors tracking at once the sweeps serialised on those locks
	 * ("Lock wait timeout exceeded; try restarting transaction"), PHP workers
	 * piled up behind them and whole sites went down (customer report, 1.9.5).
	 *
	 * This helper (a) escapes the full pattern so the LIKE becomes an index
	 * range scan, (b) SELECTs the matching names first and returns early when
	 * there is nothing to delete — the common case on the frontend tracking
	 * path, where dashboard caches only exist after an admin opened the
	 * dashboard — and (c) deletes by exact option_name so the DELETE locks only
	 * the rows it removes.
	 *
	 * @since 1.9.0.6
	 * @param string[] $prefixes Transient key prefixes (without `_transient_`).
	 * @return int Number of option rows deleted.
	 */
	function opti_behavior_delete_transients_by_prefix( array $prefixes ) {
		global $wpdb;

		$where_parts = array();
		$params      = array();
		foreach ( $prefixes as $prefix ) {
			$prefix = (string) $prefix;
			if ( '' === $prefix ) {
				continue;
			}
			$like          = $wpdb->esc_like( $prefix ) . '%';
			$where_parts[] = 'option_name LIKE %s';
			$params[]      = $wpdb->esc_like( '_transient_' ) . $like;
			$where_parts[] = 'option_name LIKE %s';
			$params[]      = $wpdb->esc_like( '_transient_timeout_' ) . $like;
		}

		if ( empty( $where_parts ) ) {
			return 0;
		}

		$where = implode( ' OR ', $where_parts );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prefix list is internal (hardcoded registry), every fragment goes through $wpdb->prepare().
		$option_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE {$where}", $params ) );

		if ( empty( $option_names ) ) {
			return 0;
		}

		$rows = 0;
		foreach ( array_chunk( $option_names, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact option names read back from the DB above, all bound through $wpdb->prepare().
			$rows += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", $chunk ) );
		}

		// A direct SQL DELETE bypasses delete_option()/delete_transient(), so on
		// installs with a persistent object cache (Redis/Memcached) the stale
		// value would otherwise keep being served. Transients are autoload=no,
		// so they live under their own option_name key in the 'options' group;
		// alloptions is busted too for safety.
		wp_cache_delete( 'alloptions', 'options' );
		foreach ( $option_names as $option_name ) {
			wp_cache_delete( $option_name, 'options' );
		}

		return $rows;
	}
}

if ( ! function_exists( 'opti_behavior_cache_sweep_due' ) ) {
	/**
	 * Rate-limit a cache sweep triggered from the frontend tracking path.
	 *
	 * Returns true at most once per $window seconds per $key (site-wide), so a
	 * burst of concurrent heartbeats / event batches / recording saves runs one
	 * sweep instead of one per request. The check reads an autoloaded option
	 * (free, served from alloptions); the single write per window is an UPDATE
	 * by primary key, which cannot block other requests the way the old
	 * per-request LIKE sweeps did.
	 *
	 * When the sweep is skipped the key is flagged dirty so the next admin
	 * request (see {@see opti_behavior_flush_dirty_dashboard_caches()}) can run
	 * it before the dashboard reads a widget. Worst case a widget is stale for
	 * $window seconds — well under the 900 s transient TTL.
	 *
	 * @since 1.9.0.6
	 * @param string $key    Sweep family key, e.g. 'dashboard' or 'recordings'.
	 * @param int    $window Minimum seconds between two sweeps of the same key.
	 * @return bool True when the caller should run the sweep now.
	 */
	function opti_behavior_cache_sweep_due( $key, $window = 60 ) {
		$stamp_key = 'opti_behavior_sweep_' . sanitize_key( $key );
		$window    = max( 1, (int) $window );
		$last      = (int) get_option( $stamp_key, 0 );

		if ( $last > 0 && ( time() - $last ) < $window ) {
			// update_option() is a no-op (no query) when the value is already 1.
			update_option( $stamp_key . '_dirty', 1, true );
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'opti_behavior_cache_sweep_is_dirty' ) ) {
	/**
	 * Whether a throttled sweep was skipped since the last real sweep of $key.
	 *
	 * @since 1.9.0.6
	 * @param string $key Sweep family key.
	 * @return bool
	 */
	function opti_behavior_cache_sweep_is_dirty( $key ) {
		return (bool) get_option( 'opti_behavior_sweep_' . sanitize_key( $key ) . '_dirty', 0 );
	}
}

if ( ! function_exists( 'opti_behavior_cache_sweep_mark_clean' ) ) {
	/**
	 * Record that a full sweep of $key just ran (stamps the throttle window and
	 * clears the dirty flag). Both writes are no-ops when nothing changed.
	 *
	 * @since 1.9.0.6
	 * @param string $key Sweep family key.
	 * @return void
	 */
	function opti_behavior_cache_sweep_mark_clean( $key ) {
		$stamp_key = 'opti_behavior_sweep_' . sanitize_key( $key );
		update_option( $stamp_key, time(), true );
		update_option( $stamp_key . '_dirty', 0, true );
	}
}

if ( ! function_exists( 'opti_behavior_flush_dirty_dashboard_caches' ) ) {
	/**
	 * admin_init: run a dashboard sweep that the tracking path skipped
	 * (throttled) so an admin never reads a widget the throttle left stale.
	 * Only for users who can see the dashboard; frontend nopriv AJAX (which
	 * also fires admin_init) is excluded by the capability check.
	 *
	 * @since 1.9.0.6
	 * @return void
	 */
	function opti_behavior_flush_dirty_dashboard_caches() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! opti_behavior_cache_sweep_is_dirty( 'dashboard' ) ) {
			return;
		}
		opti_behavior_invalidate_dashboard_caches();
	}
	add_action( 'admin_init', 'opti_behavior_flush_dirty_dashboard_caches', 5 );
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

		// 1.9.0.6: index-friendly, SELECT-then-DELETE-by-name sweep (see
		// opti_behavior_delete_transients_by_prefix() for why the old
		// `LIKE '_transient_…'` DELETE locked up wp_options under load).
		$rows = opti_behavior_delete_transients_by_prefix( $prefixes );

		opti_behavior_cache_sweep_mark_clean( 'dashboard' );

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
