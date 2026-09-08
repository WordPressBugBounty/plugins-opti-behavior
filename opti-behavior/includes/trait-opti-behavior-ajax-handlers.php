<?php
/**
 * AJAX Handlers Trait
 *
 * Groups AJAX handlers for dashboard and analytics functionality.
 *
 * @package Opti_Behavior
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Handlers Trait
 *
 * Provides AJAX handler methods for dashboard operations.
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cleanup SQL uses the hard-coded danger-zone table manifest and verified column names.
if ( ! trait_exists( 'opti_behavior_Ajax_Handlers_Trait' ) ) {
	trait Opti_Behavior_Ajax_Handlers_Trait {
		/**
		 * Acquire a MySQL advisory lock serializing an expensive cold computation
		 * (Perf RC4 — parallel stats/table AJAX must not duplicate the work).
		 *
		 * GET_LOCK is connection-scoped: it is auto-released when PHP dies or the
		 * DB connection closes, so a killed request can never wedge the lock.
		 *
		 * @since 1.7.0
		 * @param string $lock_name Lock name (already hashed/short).
		 * @param int    $timeout   Max seconds to wait for the current holder.
		 * @return bool True when the lock was acquired (must be released).
		 */
		private function acquire_heatmap_compute_lock( $lock_name, $timeout = 30 ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock; no table access.
			$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, max( 0, (int) $timeout ) ) );
			return '1' === (string) $got;
		}

		/**
		 * Release an advisory lock acquired via acquire_heatmap_compute_lock().
		 *
		 * @since 1.7.0
		 * @param string $lock_name Lock name.
		 * @param bool   $got_lock  Whether the lock was actually acquired.
		 * @return void
		 */
		private function release_heatmap_compute_lock( $lock_name, $got_lock ) {
			if ( ! $got_lock ) {
				return;
			}
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock; no table access.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		/**
		 * AJAX handler for heatmaps table.
		 *
		 * @since 1.0.0
		 * @global wpdb $wpdb WordPress database abstraction object.
		 */
		private function ajax_heatmaps_table_impl() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'opti-behavior' ) );
            }
            $orderby = isset($_POST['orderby']) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'last_updated';
            $order   = isset($_POST['order']) ? strtolower( sanitize_text_field( wp_unslash( $_POST['order'] ) ) ) : 'desc';
            $page    = isset($_POST['paged']) ? max(1, intval( wp_unslash( $_POST['paged'] ) )) : 1;
            $per_page = isset($_POST['per_page']) ? max(1, intval( wp_unslash( $_POST['per_page'] ) )) : 5;
            $period  = isset($_POST['period']) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'last30days';
            $start   = isset($_POST['start_date']) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
            $end     = isset($_POST['end_date']) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
            $search  = isset($_POST['search']) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

            // Keep the heatmaps table aligned with the product-wide spam filter / temporary page override.
            $exclude_spam = $this->resolve_spam_exclusion_from_request( $_POST );
            $GLOBALS['opti_behavior_exclude_spam'] = $exclude_spam;

            // Debug logging
            $debug_manager = $this->heatmap->get_debug_manager();
            $debug_manager->log( 'ajax_heatmaps_table params - orderby=' . $orderby . ' order=' . $order . ' page=' . $page . ' per_page=' . $per_page . ' period=' . $period, 'debug', 'ajax' );

            // Cache the rendered table HTML. Sorting / paginating only re-renders
            // the same rows, so caching the output by the full request signature
            // turns repeat sorts into an O(1) transient read. The cache is
            // invalidated on every heatmap ingest/delete (version bump) and the
            // spam flag is part of the key, so the HTML can never go stale or mix
            // spam-on / spam-off values.
            $table_cache_key = function_exists( 'opti_behavior_heatmap_cache_key' )
                ? opti_behavior_heatmap_cache_key( 'table', compact( 'orderby', 'order', 'page', 'per_page', 'period', 'start', 'end', 'search' ) + array( 'spam' => $exclude_spam ? '1' : '0' ) )
                : '';

            $html = false;
            $force_refresh = function_exists( 'opti_behavior_is_force_refresh_requested' ) && opti_behavior_is_force_refresh_requested();
            if ( $table_cache_key && ! $force_refresh ) {
                $cached_html = get_transient( $table_cache_key );
                if ( is_string( $cached_html ) ) {
                    $html = $cached_html;
                    $debug_manager->log( 'Heatmaps table served from cache', 'debug', 'ajax' );
                }
            }

            if ( false === $html ) {
                // Concurrency guard (Perf RC4): the stats + table AJAX fire in
                // parallel and several tabs/retries can stack up. Serialize the
                // expensive cold computation behind a MySQL advisory lock —
                // waiters re-check the transient after the winner finishes, so
                // duplicate cold work is eliminated. Lock is connection-scoped
                // (auto-released if PHP dies) and never blocks longer than 30 s.
                $lock_name = 'obhm_' . md5( 'table|' . $table_cache_key );
                $got_lock  = $this->acquire_heatmap_compute_lock( $lock_name, 30 );

                if ( $got_lock && $table_cache_key && ! $force_refresh ) {
                    $cached_html = get_transient( $table_cache_key );
                    if ( is_string( $cached_html ) ) {
                        $html = $cached_html;
                        $debug_manager->log( 'Heatmaps table served from cache (populated while waiting for lock)', 'debug', 'ajax' );
                    }
                }

                if ( false === $html ) {
                    ob_start();
                    $this->render_heatmap_table_inner( compact('orderby','order','page','per_page','period','start','end','search') );
                    $html = ob_get_clean();

                    if ( $table_cache_key ) {
                        set_transient( $table_cache_key, $html, opti_behavior_heatmap_cache_ttl() );
                    }
                }

                $this->release_heatmap_compute_lock( $lock_name, $got_lock );
            }

            // Debug: log HTML length and check for data
            $debug_manager->log( 'Generated HTML length: ' . strlen( $html ), 'debug', 'ajax' );
            $debug_manager->log( 'HTML contains "No heatmaps available": ' . ( strpos( $html, 'No heatmaps available' ) !== false ? 'YES' : 'NO' ), 'debug', 'ajax' );

            wp_send_json_success( array( 'html' => $html ) );
        }

        /**
         * AJAX: heatmaps KPI stat cards HTML.
         *
         * Mirrors the table handler: the page renders a 6-card skeleton, then
         * this endpoint streams the populated grid in after first paint so a
         * cold stat aggregation never blocks the whole page render.
         */
        private function ajax_heatmaps_stats_impl() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'opti-behavior' ) );
            }

            // Keep KPI numbers aligned with the product-wide spam filter, same as the table.
            $exclude_spam = $this->resolve_spam_exclusion_from_request( $_POST );
            $GLOBALS['opti_behavior_exclude_spam'] = $exclude_spam;

            // Concurrency guard (Perf RC4): serialize the cold stats aggregation
            // behind an advisory lock. get_heatmap_statistics() reads its own
            // transient first, so a waiter that acquires the lock after the
            // winner simply gets the warm cache hit.
            $lock_name = 'obhm_' . md5( 'stats|' . ( $exclude_spam ? '1' : '0' ) . '|' . gmdate( 'Y-m-d' ) );
            $got_lock  = $this->acquire_heatmap_compute_lock( $lock_name, 30 );

            $stats = $this->get_heatmap_statistics();

            $this->release_heatmap_compute_lock( $lock_name, $got_lock );

            $tooltips = opti_behavior_get_heatmaps_tooltips();

            ob_start();
            $this->render_heatmap_stats_grid( $stats, $tooltips );
            $html = ob_get_clean();

            wp_send_json_success( array( 'html' => $html ) );
        }

        /**
         * AJAX handler for heatmaps sessions.
         *
         * @since 1.0.0
         * @global wpdb $wpdb WordPress database abstraction object.
         */
        private function ajax_heatmaps_sessions_impl() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'opti-behavior' ) );
            }
            // Unslash first, then sanitize with intval
            $pageIds = isset($_POST['page_ids']) && is_array($_POST['page_ids']) ? array_map('intval', wp_unslash( $_POST['page_ids'] ) ) : array();
            $period  = isset($_POST['period']) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'last30days';
            $start   = isset($_POST['start_date']) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
            $end     = isset($_POST['end_date']) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
            if ($period === 'custom' && $start && $end) { $start_date = $start.' 00:00:00'; $end_date = $end.' 23:59:59'; }
            else { $range = $this->get_date_range($period); $start_date = $range['start']; $end_date = $range['end']; }
            $debug_manager = $this->heatmap->get_debug_manager();
            $debug_manager->log( 'ajax_heatmaps_sessions ids=' . wp_json_encode( $pageIds ) . ' period=' . $period . ' start=' . $start_date . ' end=' . $end_date, 'debug', 'ajax' );
            $map = $this->batch_sessions_counts_by_page($pageIds, $start_date, $end_date);
            $debug_manager->log( 'ajax_heatmaps_sessions result=' . wp_json_encode( $map ), 'debug', 'ajax' );
            wp_send_json_success(array('sessions'=>$map));
        }

        /*
         * sanitize_advanced_filters_from_request() moved to the shared
         * Opti_Behavior_Advanced_Filters_Trait
         * (includes/trait-opti-behavior-advanced-filters.php) so the Dashboard
         * and the Funnels page share one implementation. This class `use`s that
         * trait, so $this->sanitize_advanced_filters_from_request() still
         * resolves unchanged.
         */

        /**
         * AJAX handler for dashboard data.
         *
         * Supports async widget loading via 'widget' parameter.
         * When widget is specified, returns only that widget's data.
         * When widget is not specified, returns minimal data (stats only).
         *
         * @since 1.0.0
         */
        private function ajax_dashboard_data_impl() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'opti-behavior' ) );
            }

            $period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'last30days';
            $start = isset($_POST['start_date']) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : null;
            $end   = isset($_POST['end_date']) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : null;
            $widget = isset($_POST['widget']) ? sanitize_text_field( wp_unslash( $_POST['widget'] ) ) : '';

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified above via check_ajax_referer().
            $filters = $this->sanitize_advanced_filters_from_request( $_POST );

            // Get exclude_spam parameter - store globally for use in queries.
            $exclude_spam = $this->resolve_spam_exclusion_from_request( $_POST );
            $GLOBALS['opti_behavior_exclude_spam'] = $exclude_spam;

            // Cache policy (perf): the short-lived dashboard transient caches use a 30 s TTL
            // AND are flushed on every new session insert/update (see
            // opti_behavior_invalidate_dashboard_caches()), so cached values are already
            // near-live and self-correcting. Forcing a live query on EVERY widget load was
            // therefore pure overhead — it disabled a self-invalidating cache and made each
            // dashboard visit re-run heavy aggregates from cold (e.g. summary_stats ~35 s).
            //
            // Bypass the cache only when the user EXPLICITLY asks for it (Refresh button /
            // date-filter reload send force_refresh=1), and always keep the realtime widget
            // live so the active-visitor count is never served stale.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified above via check_ajax_referer().
            $explicit_force = isset( $_POST['force_refresh'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force_refresh'] ) );
            $GLOBALS['opti_behavior_force_live_query'] = ( $explicit_force || 'realtime' === $widget );

            // If widget is specified, return only that widget's data (lazy parallel load).
            if ( ! empty( $widget ) ) {
                $data = $this->get_widget_data( $widget, $period, $start, $end, $filters );
                wp_send_json_success( $data );
                return;
            }

            // No widget specified: return the fast stats-only payload (KPI cards + daily
            // history) instead of the 28-aggregation monolith. The heavy widgets are loaded
            // in parallel via the per-widget '&widget=' path above, so the no-widget call
            // must never trigger get_dashboard_data_impl() (the source of the ~30s load).
            $data = $this->get_dashboard_stats_only( $period, $start, $end, $filters );

            wp_send_json_success( $data );
        }

        /**
         * Get data for a specific widget.
         *
         * @since 1.0.0
         * @param string      $widget  Widget identifier.
         * @param string      $period  Period identifier.
         * @param string|null $start   Start date override.
         * @param string|null $end     End date override.
         * @param array       $filters Optional sanitized advanced-filters array (allow-listed keys only). Empty = unfiltered (default, backward compatible). Only applied to widgets whose backing query method supports it (Task 3's ~13 methods); other widgets silently ignore it (realtime stays exempt by design).
         * @return array Widget data.
         */
        private function get_widget_data( $widget, $period, $start = null, $end = null, array $filters = array() ) {
            $date_range = $this->get_dashboard_effective_date_range( $period, $start, $end );
            $start_date = $date_range['start'];
            $end_date   = $date_range['end'];

            switch ( $widget ) {
                case 'sessions_chart':
                    return array( 'sessions_chart' => $this->get_sessions_chart_data( $start_date, $end_date, $filters ) );

                case 'browsers':
                    return array( 'browsers' => $this->get_browsers_data( $start_date, $end_date, $filters ) );

                case 'device_types':
                    return array( 'device_types' => $this->get_device_types_data( $start_date, $end_date, $filters ) );

                case 'operating_systems':
                    return array( 'operating_systems' => $this->get_operating_systems_data( $start_date, $end_date, $filters ) );

                case 'user_intent':
                    return array( 'user_intent' => method_exists( $this, 'get_user_intent_data' ) ? $this->get_user_intent_data( $start_date, $end_date, $filters ) : array() );

                case 'countries':
                    return array( 'countries' => $this->get_countries_data( $start_date, $end_date, $filters ) );

                case 'realtime':
                    // Realtime stays exempt from advanced filters (inherently "right now" data).
                    return array(
                        'active_visitors' => $this->get_active_visitors(),
                        'recent_sessions' => $this->get_recent_sessions( 10 )
                    );

                case 'top_pages':
                    return array( 'top_pages' => $this->get_top_pages_data( $start_date, $end_date, $filters ) );

                case 'referrers':
                    return array( 'referrers' => $this->get_referrers_data( $start_date, $end_date, $filters ) );

                case 'screen_resolutions':
                    return array( 'screen_resolutions' => $this->get_screen_resolution_data( $start_date, $end_date, $filters ) );

                case 'traffic_classification':
                    return array( 'traffic_classification' => method_exists( $this, 'get_traffic_classification_data_impl' ) ? $this->get_traffic_classification_data_impl( $start_date, $end_date, $filters ) : array() );

                case 'bot_traffic':
                    return array( 'bot_traffic' => method_exists( $this, 'get_bot_traffic_data_impl' ) ? $this->get_bot_traffic_data_impl( $start_date, $end_date ) : array() );

                case 'new_vs_returning':
                    return array( 'new_vs_returning' => method_exists( $this, 'get_new_vs_returning_visitors_data_impl' ) ? $this->get_new_vs_returning_visitors_data_impl( $start_date, $end_date, $filters ) : array() );

                case 'visited_directories':
                    return array( 'visited_directories' => method_exists( $this, 'get_visited_directories_data_impl' ) ? $this->get_visited_directories_data_impl( $start_date, $end_date, $filters ) : array() );

                case 'new_registered_users':
                    return array( 'new_registered_users' => method_exists( $this, 'get_new_registered_users_data_impl' ) ? $this->get_new_registered_users_data_impl( $start_date, $end_date, $filters ) : array() );

                case 'summary_stats':
                    // Get summary stats for the stats cards (visitors, sessions, pageviews, etc.)
                    return $this->get_summary_stats_data( $start_date, $end_date, $filters );

                case 'section_summaries':
                    // Lightweight teaser chips for the collapsible section headers.
                    // Single cheap top-1 / COUNT aggregates on indexed columns; never
                    // re-runs the heavy widget queries, never cached (live/exact).
                    return array( 'section_summaries' => $this->get_section_summaries_data( $start_date, $end_date, $filters ) );

                case 'top_users':
                    return array( 'top_users' => $this->get_top_engaged_users_data( $start_date, $end_date, $filters ) );

                default:
                    return array();
            }
        }

        /**
         * AJAX handler for the FREE dashboard advanced-filters panel's dropdown
         * data. Inspired by PRO User Journey's `ajax_get_filter_options()`
         * (opti-behavior-pro/admin/class-opti-behavior-user-journey-page.php):
         * every option now carries its SESSION COUNT so the panel can render
         * "Chrome (123)" style labels, plus new datasets for the previously
         * static/empty fields (visitor types, traffic channels, entry/exit
         * pages, referrer domains).
         *
         * Small-host performance contract:
         *  - every aggregate is scoped to the dashboard's selected date range
         *    (rides the sessions.start_time / idx_spam_filter indexes),
         *  - every list is LIMIT-capped so the payload stays a few KB,
         *  - the whole response is transient-cached (default 300 s, filterable
         *    via `opti_behavior_filter_options_cache_ttl`) so repeat panel
         *    opens within the TTL run ZERO SQL,
         *  - fetched once, lazily, on first panel open (see dashboard.js).
         *
         * Option `value`s are the RAW stored values (no count suffix) so the
         * Apply payload keeps matching build_advanced_filters_sql()'s
         * equality/LIKE contract exactly.
         *
         * @since 1.0.4 Bare DISTINCT lists.
         * @since 1.8.2 Count-aggregated, date-scoped, cached; new datasets.
         */
        private function ajax_get_dashboard_filter_options_impl() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'opti-behavior' ) );
            }

            global $wpdb;

            // Scope: same period/range/spam parameters the widget requests use,
            // so option counts match what the dashboard is currently showing.
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified above via check_ajax_referer().
            $period = isset( $_REQUEST['period'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['period'] ) ) : 'last30days';
            $start  = isset( $_REQUEST['start_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['start_date'] ) ) : null;
            $end    = isset( $_REQUEST['end_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['end_date'] ) ) : null;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            $exclude_spam = $this->resolve_spam_exclusion_from_request( $_REQUEST );
            $GLOBALS['opti_behavior_exclude_spam'] = $exclude_spam;

            $date_range = $this->get_dashboard_effective_date_range( $period, $start, $end );
            $start_date = $date_range['start'];
            $end_date   = $date_range['end'];

            /**
             * Filters the filter-options transient TTL (seconds).
             *
             * Options tolerate more staleness than the widgets themselves, so
             * the default is 300 s (vs the 30 s widget TTL): a cache hit means
             * the panel open costs zero SQL - important on small shared hosts.
             *
             * @since 1.8.2
             * @param int $ttl Cache TTL in seconds.
             */
            $cache_ttl = (int) apply_filters( 'opti_behavior_filter_options_cache_ttl', 300 );
            $cache_key = 'optibehavior_filter_opts_' . md5( 'v2|' . $start_date . '|' . $end_date . '|' . ( $exclude_spam ? '1' : '0' ) );

            if ( $cache_ttl > 0 ) {
                $cached = get_transient( $cache_key );
                if ( is_array( $cached ) && isset( $cached['browsers'] ) ) {
                    wp_send_json_success( $cached );
                }
            }

            $visitors_table = $wpdb->prefix . 'optibehavior_visitors';
            $sessions_table = $wpdb->prefix . 'optibehavior_sessions';
            $spam_clause    = $this->get_spam_exclusion_clause( 's' );

            // Shared scopes. Session-count basis everywhere (PRO parity): one
            // visitor can contribute several sessions.
            $session_scope = "FROM {$sessions_table} s WHERE s.start_time BETWEEN %s AND %s" . $spam_clause;
            $join_scope    = "FROM {$sessions_table} s INNER JOIN {$visitors_table} v ON s.visitor_id = v.id WHERE s.start_time BETWEEN %s AND %s" . $spam_clause;
            $range_params  = array( $start_date, $end_date );

            // Visitor-attribute dimension aggregate (browser / device / os).
            // Raw stored values only (empty/null skipped) so the option value
            // always round-trips through build_advanced_filters_sql()'s `= %s`.
            $visitor_dim = function ( $column, $limit ) use ( $wpdb, $join_scope, $range_params ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded column/table names from $wpdb->prefix; result cached in the surrounding transient.
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT v.{$column} AS value, COUNT(*) AS count {$join_scope} AND v.{$column} IS NOT NULL AND v.{$column} != '' GROUP BY v.{$column} ORDER BY count DESC LIMIT {$limit}",
                    $range_params
                ) );
            };

            // Session-column dimension aggregate (utm_* / entry_page / exit_page).
            $session_dim = function ( $column, $limit ) use ( $wpdb, $session_scope, $range_params ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded column/table names from $wpdb->prefix; result cached in the surrounding transient.
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT s.{$column} AS value, COUNT(*) AS count {$session_scope} AND s.{$column} IS NOT NULL AND s.{$column} != '' GROUP BY s.{$column} ORDER BY count DESC LIMIT {$limit}",
                    $range_params
                ) );
            };

            $browsers = $visitor_dim( 'browser', 50 );
            $devices  = $visitor_dim( 'device_type', 10 );
            $os_list  = $visitor_dim( 'os', 30 );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded table names from $wpdb->prefix; result cached in the surrounding transient.
            $countries = $wpdb->get_results( $wpdb->prepare(
                "SELECT v.country, v.country_name, COUNT(*) AS count {$join_scope} AND v.country IS NOT NULL AND v.country != '' GROUP BY v.country, v.country_name ORDER BY count DESC LIMIT 100",
                $range_params
            ) );

            // Visitor type: same new/returning predicate as the
            // `visitor_type` case in build_advanced_filters_sql().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded table names from $wpdb->prefix; result cached in the surrounding transient.
            $visitor_type_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT SUM(CASE WHEN COALESCE(v.visit_count, 1) <= 1 THEN 1 ELSE 0 END) AS new_count,
                        SUM(CASE WHEN COALESCE(v.visit_count, 1) > 1 THEN 1 ELSE 0 END) AS returning_count
                 {$join_scope}",
                $range_params
            ) );
            $visitor_types = array(
                array( 'value' => 'new', 'count' => $visitor_type_row ? (int) $visitor_type_row->new_count : 0 ),
                array( 'value' => 'returning', 'count' => $visitor_type_row ? (int) $visitor_type_row->returning_count : 0 ),
            );

            // Traffic channels: reuse the exact SQL classifier the
            // `traffic_channel` filter matches against, so counts and filter
            // results can never disagree. (The CASE fragment escapes literal
            // "%" as "%%" specifically for wpdb::prepare() - see
            // build_traffic_channel_case_sql().)
            $channel_case = method_exists( $this, 'build_traffic_channel_case_sql' ) ? $this->build_traffic_channel_case_sql() : '';
            $traffic_channels = array();
            if ( '' !== $channel_case ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static CASE fragment + tables from $wpdb->prefix; result cached in the surrounding transient.
                $traffic_channels = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ({$channel_case}) AS value, COUNT(*) AS count {$session_scope} GROUP BY value ORDER BY count DESC",
                    $range_params
                ) );
            }

            $utm_campaigns = $session_dim( 'utm_campaign', 50 );
            $utm_sources   = $session_dim( 'utm_source', 50 );
            $utm_mediums   = $session_dim( 'utm_medium', 50 );
            $entry_pages   = $session_dim( 'entry_page', 50 );
            $exit_pages    = $session_dim( 'exit_page', 50 );

            // Referrer suggestions: domain-normalized (scheme/path/query/www.
            // stripped) like PRO User Journey; own-host + empty referrers are
            // excluded (they are "Direct", not a typeable referrer value). The
            // suggested domain feeds the LIKE-based `referrer` filter, so a
            // domain always matches its own full URLs.
            $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
            $site_host = $site_host ? preg_replace( '/^www\./i', '', $site_host ) : '';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables from $wpdb->prefix, host esc_sql()-escaped; result cached in the surrounding transient.
            $referrers = $wpdb->get_results( $wpdb->prepare(
                "SELECT REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(s.referrer, 'https://', ''), 'http://', ''), '/', 1), '?', 1), 'www.', '') AS value,
                        COUNT(*) AS count
                 {$session_scope}
                 AND s.referrer IS NOT NULL AND s.referrer != ''
                 GROUP BY value
                 HAVING value != '' AND value != '" . esc_sql( $site_host ) . "'
                 ORDER BY count DESC
                 LIMIT 50",
                $range_params
            ) );

            $to_value_count = function ( $rows ) {
                $out = array();
                foreach ( (array) $rows as $row ) {
                    $out[] = array(
                        'value' => (string) $row->value,
                        'count' => (int) $row->count,
                    );
                }
                return $out;
            };

            $country_list = array();
            foreach ( (array) $countries as $row ) {
                $country_list[] = array(
                    'country'      => (string) $row->country,
                    'country_name' => (string) $row->country_name,
                    'count'        => (int) $row->count,
                );
            }

            $payload = array(
                'browsers'         => $to_value_count( $browsers ),
                'countries'        => $country_list,
                'devices'          => $to_value_count( $devices ),
                'os'               => $to_value_count( $os_list ),
                'visitor_types'    => $visitor_types,
                'traffic_channels' => $to_value_count( $traffic_channels ),
                'entry_pages'      => $to_value_count( $entry_pages ),
                'exit_pages'       => $to_value_count( $exit_pages ),
                'referrers'        => $to_value_count( $referrers ),
                'utm_campaigns'    => $to_value_count( $utm_campaigns ),
                'utm_sources'      => $to_value_count( $utm_sources ),
                'utm_mediums'      => $to_value_count( $utm_mediums ),
            );

            if ( $cache_ttl > 0 ) {
                set_transient( $cache_key, $payload, $cache_ttl );
            }

            wp_send_json_success( $payload );
        }

        /**
         * AJAX handler for session data.
         *
         * @since 1.0.0
         */
        private function ajax_session_data_impl() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'opti-behavior' ) );
            }

            $page = isset( $_POST['page'] ) ? intval( wp_unslash( $_POST['page'] ) ) : 1;
            $per_page = isset( $_POST['per_page'] ) ? intval( wp_unslash( $_POST['per_page'] ) ) : 10;
            $filters = isset( $_POST['filters'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['filters'] ) ) : array();

            $data = $this->get_session_data( $page, $per_page, $filters );

            wp_send_json_success( $data );
        }

        /**
         * AJAX handler for analytics data.
         *
         * @since 1.0.0
         */
        private function ajax_analytics_data_impl() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'opti-behavior' ) );
            }

            $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'overview';
            $period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'last30days';

            $data = $this->get_analytics_data( $type, $period );

            wp_send_json_success( $data );
        }
        /**
         * AJAX handler for top engaged users.
         *
         * Returns Top Engaged Users for the selected period.
         * Version: 2025-09-30-v3 - Always resolve country name from code.
         *
         * @since 1.0.0
         * @global wpdb $wpdb WordPress database abstraction object.
         */
        private function ajax_top_users_impl() {
            check_ajax_referer('optibehavior_top_users','nonce');
            if ( ! current_user_can('manage_options') ) { wp_die( esc_html__( 'Unauthorized', 'opti-behavior' ) ); }

            $period = isset($_POST['period']) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'last30days';
            $start_q = isset($_POST['start_date']) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
            $end_q   = isset($_POST['end_date']) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

            // Get exclude_spam parameter.
            $exclude_spam = $this->resolve_spam_exclusion_from_request( $_POST );
            $GLOBALS['opti_behavior_exclude_spam'] = $exclude_spam;

            // Optional advanced filters (dashboard filter panel). When present the
            // widget must honour them: skip the pre-aggregated summary fast path
            // (it cannot express per-session/visitor predicates) and never touch
            // the shared unfiltered transient cache.
            $filters     = $this->sanitize_advanced_filters_from_request( $_POST );
            $has_filters = ! empty( $filters );
            $filter_sql  = $has_filters ? $this->build_advanced_filters_sql( $filters ) : array( 'where' => '', 'params' => array() );
            $filter_join = ( '' !== $filter_sql['where'] )
                ? " LEFT JOIN {$GLOBALS['wpdb']->prefix}optibehavior_visitors v ON s.visitor_id = v.id"
                : '';

            if ($period === 'custom' && $start_q && $end_q) {
                $start_date = $start_q . ' 00:00:00';
                $end_date   = $end_q   . ' 23:59:59';
            } else {
                $range = $this->get_date_range($period);
                $start_date = $range['start'];
                $end_date   = $range['end'];
            }

            if ( $has_filters ) {
                global $wpdb;
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Filtered aggregate; table name from $wpdb->prefix.
                $visitor_total = max(
                    0,
                    (int) $wpdb->get_var(
                        $wpdb->prepare(
                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                            "SELECT COUNT(DISTINCT NULLIF(s.visitor_id, ''))
                            FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
                            WHERE s.start_time BETWEEN %s AND %s" . $this->get_spam_exclusion_clause( 's' ) . $filter_sql['where'],
                            array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
                        )
                    )
                );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
            } else {
                $visitor_total = max( 0, (int) $this->get_visitors_count( $start_date, $end_date ) );
            }
            $row_limit = min( 10, $visitor_total );

            // Transient cache for Top Users per date-range (include spam filter and visitor cap basis in key).
            // Default TTL: 30s (filterable) - was 15 min before 1.2.9, which hid new visitor activity.
            $cache_key = 'optibehavior_top_users_' . md5( 'v4|' . $start_date . '|' . $end_date . '|' . ( $exclude_spam ? '1' : '0' ) . '|' . $visitor_total . '|' . $row_limit );
            $default_ttl = function_exists( 'opti_behavior_dashboard_cache_ttl' ) ? opti_behavior_dashboard_cache_ttl() : 30;
            $cache_ttl = apply_filters('opti_behavior_top_users_cache_ttl', $default_ttl);

            // Honour force-refresh: skip reading the cache on explicit refresh.
            // Filtered requests never read or write the shared unfiltered cache.
            $force_refresh = function_exists( 'opti_behavior_is_force_refresh_requested' ) && opti_behavior_is_force_refresh_requested();
            if ( $has_filters ) {
                // No cache interaction when advanced filters are active.
            } elseif ( $force_refresh ) {
                delete_transient( $cache_key );
            } else {
                $cached = get_transient( $cache_key );
                if ( false !== $cached ) {
                    if ( is_array( $cached ) && array_key_exists( 'items', $cached ) ) {
                        wp_send_json_success( $cached );
                    }
                    wp_send_json_success(
                        array(
                            'items'         => $cached,
                            'visitor_total' => $visitor_total,
                            'limit'         => $row_limit,
                            'count_basis'   => 'unique_visitors_with_sessions',
                        )
                    );
                }
            }

            if ( 0 === $row_limit ) {
                $payload = array(
                    'items'         => array(),
                    'visitor_total' => $visitor_total,
                    'limit'         => $row_limit,
                    'count_basis'   => 'unique_visitors_with_sessions',
                );
                if ( ! $has_filters ) {
                    set_transient( $cache_key, $payload, $cache_ttl );
                }
                wp_send_json_success( $payload );
            }

            global $wpdb;
            // Escape table names for WordPress Plugin Check compliance
            $sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
            $summary_table = esc_sql( $wpdb->prefix . 'optibehavior_visitor_daily_stats' );
            $pageviews_table = esc_sql( $wpdb->prefix . 'optibehavior_pageviews' );
            $visitors_table = esc_sql( $wpdb->prefix . 'optibehavior_visitors' );
            $users_table = esc_sql( $wpdb->users );

            /*
             * Keep Top Engaged Users aligned with the dashboard summary cards.
             *
             * The daily summary table is pre-aggregated and cannot apply the current
             * spam duration threshold per session. When spam exclusion is active we
             * must read the sessions table directly and reuse get_spam_exclusion_clause().
             */

            // Check if summary table exists and has data for this date range
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            $summary_exists = $wpdb->get_var( "SHOW TABLES LIKE '" . $summary_table . "'" );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $use_summary = false;
            if ( $summary_exists && ! $exclude_spam && ! $has_filters ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $summary_count = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM " . $summary_table . " WHERE stat_date BETWEEN %s AND %s",
                    substr($start_date, 0, 10), substr($end_date, 0, 10)
                ) );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $use_summary = ( $summary_count > 0 );
            }

            if ( $use_summary ) {
                // FAST PATH: Use pre-aggregated summary table (4x faster)
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT
                        agg.visitor_id,
                        agg.user_id,
                        agg.sessions,
                        agg.active_days,
                        COALESCE(
                            ROUND(NULLIF(pv.tracked_pageviews, 0) / NULLIF(agg.sessions, 0), 2),
                            agg.pages_per_session,
                            0
                        ) AS pages_per_session,
                        agg.total_time,
                        agg.avg_session_time,
                        agg.last_seen,
                        UPPER(COALESCE(NULLIF(TRIM(v.country),''), '')) AS country_code,
                        v.country_name,
                        u.user_login,
                        u.display_name
                     FROM (
                        SELECT
                            ds.visitor_id,
                            MAX(ds.user_id) AS user_id,
                            SUM(ds.sessions) AS sessions,
                            COUNT(DISTINCT ds.stat_date) AS active_days,
                            ROUND(SUM(ds.total_page_views) / NULLIF(SUM(ds.sessions), 0), 2) AS pages_per_session,
                            SUM(ds.total_duration) AS total_time,
                            ROUND(SUM(ds.total_duration) / NULLIF(SUM(ds.sessions), 0)) AS avg_session_time,
                            MAX(ds.last_seen) AS last_seen
                        FROM " . $summary_table . " ds
                        WHERE ds.stat_date BETWEEN %s AND %s
                          AND ds.visitor_id IS NOT NULL
                          AND ds.visitor_id != ''
                        GROUP BY ds.visitor_id
                        ORDER BY total_time DESC
                        LIMIT %d
                     ) agg
                     LEFT JOIN " . $visitors_table . " v ON v.id = agg.visitor_id
                     LEFT JOIN (
                        SELECT
                            visitor_id,
                            COUNT(*) AS tracked_pageviews
                        FROM " . $pageviews_table . "
                        WHERE view_time BETWEEN %s AND %s
                          AND visitor_id IS NOT NULL
                          AND visitor_id != ''
                        GROUP BY visitor_id
                     ) pv ON pv.visitor_id = agg.visitor_id
                     LEFT JOIN " . $users_table . " u ON u.ID = agg.user_id",
                     substr($start_date, 0, 10), substr($end_date, 0, 10), $row_limit, $start_date, $end_date
                ) );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
            } else {
                // FALLBACK: Use sessions table directly (slower but always works)
                $spam_filter_sessions = $this->get_spam_exclusion_clause( 's' );
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT
                        agg.visitor_id,
                        agg.user_id,
                        agg.sessions,
                        agg.active_days,
                        agg.pages_per_session,
                        agg.total_time,
                        agg.avg_session_time,
                        agg.last_seen,
                        UPPER(COALESCE(NULLIF(TRIM(v.country),''), '')) AS country_code,
                        v.country_name,
                        u.user_login,
                        u.display_name
                     FROM (
                        SELECT
                            s.visitor_id,
                            MAX(s.user_id) AS user_id,
                            COUNT(*) AS sessions,
                            COUNT(DISTINCT DATE(s.start_time)) AS active_days,
                            COALESCE(
                                ROUND(
                                    SUM(
                                        CASE
                                            WHEN COALESCE(s.page_views, 0) > 0 THEN COALESCE(s.page_views, 0)
                                            ELSE COALESCE(pvc.pageview_count, 0)
                                        END
                                    ) / NULLIF(COUNT(*), 0),
                                    2
                                ),
                                0
                            ) AS pages_per_session,
                            SUM(GREATEST(s.duration, 0)) AS total_time,
                            AVG(GREATEST(s.duration, 0)) AS avg_session_time,
                            MAX(COALESCE(s.end_time, s.start_time)) AS last_seen
                        FROM " . $sessions_table . " s
                        LEFT JOIN (
                            SELECT
                                session_id,
                                COUNT(*) AS pageview_count
                            FROM " . $pageviews_table . "
                            WHERE view_time BETWEEN %s AND %s
                            GROUP BY session_id
                        ) pvc ON pvc.session_id = s.id" . $filter_join . "
                        WHERE s.start_time BETWEEN %s AND %s" . $spam_filter_sessions . "
                          AND s.visitor_id IS NOT NULL
                          AND s.visitor_id != ''" . $filter_sql['where'] . "
                        GROUP BY s.visitor_id
                        ORDER BY total_time DESC
                        LIMIT %d
                     ) agg
                     LEFT JOIN " . $visitors_table . " v ON v.id = agg.visitor_id
                     LEFT JOIN " . $users_table . " u ON u.ID = agg.user_id",
                     array_merge(
                         array( $start_date, $end_date, $start_date, $end_date ),
                         $filter_sql['params'],
                         array( $row_limit )
                     )
                ) );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
            }

            $now = current_time('timestamp');
            $items = array();
            foreach ((array)$rows as $r){
                $vid = (string)$r->visitor_id;
                $short = $vid;
                if (strlen($vid) > 12) {
                    $short = substr($vid,0,8) . '…' . substr($vid,-4);
                }
                $active_days = max(1, (int)$r->active_days);
                $daily_freq = (float)$r->sessions / $active_days;
                $last_ts = strtotime((string)$r->last_seen);

                // Apply country name fallback logic - ALWAYS resolve from country code
                $country_code = strtoupper(trim((string)$r->country_code));

                // Always resolve country name from country code if we have a valid code
                if (!empty($country_code) && $country_code !== 'UN' && $country_code !== 'NULL') {
                    $country_name = $this->get_country_name($country_code);
                    // If get_country_name returns 'Unknown', keep the original country_name from DB if available
                    if ($country_name === 'Unknown' && !empty($r->country_name)) {
                        $country_name = $r->country_name;
                    }
                } else {
                    // Use country_name from database if available, otherwise use 'Unknown'
                    $country_name = !empty($r->country_name) ? $r->country_name : 'Unknown';
                    // If we have no valid country code, set to 'UN' for display consistency
                    if (empty($country_code) || $country_code === 'NULL') {
                        $country_code = 'UN';
                    }
                }

                // Check if this visitor is a WordPress user
                $user_id = !empty($r->user_id) ? (int)$r->user_id : 0;
                $user_login = !empty($r->user_login) ? (string)$r->user_login : '';
                $display_name = !empty($r->display_name) ? (string)$r->display_name : '';
                $profile_url = $user_id > 0 ? admin_url('user-edit.php?user_id=' . $user_id) : '';

                $items[] = array(
                    'visitor_id'       => $vid,
                    'visitor_short'    => $short,
                    'user_id'          => $user_id,
                    'user_login'       => $user_login,
                    'display_name'     => $display_name,
                    'profile_url'      => $profile_url,
                    'sessions'         => (int)$r->sessions,
                    'pages_per_session'=> (float)$r->pages_per_session,
                    'total_time'       => (int)$r->total_time,
                    'avg_session_time' => (int)round((float)$r->avg_session_time),
                    'daily_freq'       => round($daily_freq, 2),
                    'country_code'     => $country_code,
                    'country_name'     => $country_name,
                    'last_seen'        => (string)$r->last_seen,
                    'last_seen_human'  => ($last_ts? human_time_diff($last_ts, $now) . ' ago' : ''),
                );
            }

            $items = array_slice( $items, 0, $row_limit );
            $payload = array(
                'items'         => $items,
                'visitor_total' => $visitor_total,
                'limit'         => $row_limit,
                'count_basis'   => 'unique_visitors_with_sessions',
            );

            if ( ! $has_filters ) {
                set_transient( $cache_key, $payload, $cache_ttl );
            }
            wp_send_json_success( $payload );
        }

        /**
         * Get country name from country code.
         *
         * @since 1.0.0
         * @param string $country_code Country code.
         * @return string Country name.
         */
        private function get_country_name( $country_code ) {
            $country_code = strtoupper(trim((string)$country_code));
            $countries = array(
                'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa',
                'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica',
                'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba',
                'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas',
                'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus',
                'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda',
                'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana',
                'BR' => 'Brazil', 'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso',
                'BI' => 'Burundi', 'CV' => 'Cabo Verde', 'KH' => 'Cambodia', 'CM' => 'Cameroon',
                'CA' => 'Canada', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad',
                'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros',
                'CG' => 'Congo', 'CD' => 'Congo, Democratic Republic', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica',
                'CI' => 'Cote d\'Ivoire', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus',
                'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica',
                'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador',
                'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini',
                'ET' => 'Ethiopia', 'FK' => 'Falkland Islands', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji',
                'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana', 'PF' => 'French Polynesia',
                'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany',
                'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland',
                'GD' => 'Grenada', 'GP' => 'Guadeloupe', 'GU' => 'Guam', 'GT' => 'Guatemala',
                'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
                'HT' => 'Haiti', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary',
                'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran',
                'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle of Man', 'IL' => 'Israel',
                'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey',
                'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati',
                'KP' => 'North Korea', 'KR' => 'South Korea', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan',
                'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho',
                'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania',
                'LU' => 'Luxembourg', 'MO' => 'Macao', 'MG' => 'Madagascar', 'MW' => 'Malawi',
                'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta',
                'MH' => 'Marshall Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MU' => 'Mauritius',
                'YT' => 'Mayotte', 'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova',
                'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MS' => 'Montserrat',
                'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia',
                'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NC' => 'New Caledonia',
                'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria',
                'NU' => 'Niue', 'NF' => 'Norfolk Island', 'MK' => 'North Macedonia', 'MP' => 'Northern Mariana Islands',
                'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau',
                'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay',
                'PE' => 'Peru', 'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland',
                'PT' => 'Portugal', 'PR' => 'Puerto Rico', 'QA' => 'Qatar', 'RE' => 'Reunion',
                'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda', 'BL' => 'Saint Barthelemy',
                'SH' => 'Saint Helena', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia',
                'MF' => 'Saint Martin', 'PM' => 'Saint Pierre and Miquelon', 'VC' => 'Saint Vincent and the Grenadines',
                'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia',
                'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone',
                'SG' => 'Singapore', 'SX' => 'Sint Maarten', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
                'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'SS' => 'South Sudan',
                'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname',
                'SJ' => 'Svalbard and Jan Mayen', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria',
                'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand',
                'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TK' => 'Tokelau', 'TO' => 'Tonga',
                'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan',
                'TC' => 'Turks and Caicos Islands', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine',
                'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States',
                'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela',
                'VN' => 'Vietnam', 'VG' => 'British Virgin Islands', 'VI' => 'U.S. Virgin Islands',
                'WF' => 'Wallis and Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen',
                'ZM' => 'Zambia', 'ZW' => 'Zimbabwe'
            );

            // Fallback: if code not in map, show the code itself (never "Unknown")
            return $countries[$country_code] ?? ($country_code ?: 'Other');
        }

        /**
         * AJAX handler for cleanup recordings by date.
         *
         * @since 1.0.0
         * @global wpdb $wpdb WordPress database abstraction object.
         */
        private function ajax_cleanup_by_date_impl() {
            check_ajax_referer('opti_behavior_cleanup', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => esc_html__( 'Permission denied', 'opti-behavior' )));
            }

            $date = isset($_POST['date']) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
            if (empty($date)) {
                wp_send_json_error(array('message' => esc_html__( 'Invalid date', 'opti-behavior' )));
            }

            global $wpdb;
            $table = $wpdb->prefix . 'optibehavior_recordings';

            // Convert date to timestamp
            $timestamp = strtotime($date . ' 23:59:59');
            if (!$timestamp) {
                wp_send_json_error(array('message' => esc_html__( 'Invalid date format', 'opti-behavior' )));
            }

            // Get file storage instance (only if Pro is active)
            $file_storage = null;
            if (opti_behavior_pro_active() && class_exists('Opti_Behavior_Heatmap_File_Storage')) {
                $file_storage = new Opti_Behavior_Heatmap_File_Storage( $this->heatmap->get_debug_manager() );
            }

            // Escape table name for WordPress Plugin Check compliance
            $safe_table = esc_sql( $table );

            // Get recordings before date (use start_time instead of created_at)
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            $recordings = $wpdb->get_results($wpdb->prepare(
                "SELECT id, session_id, file_path, storage_type, start_time, duration
                FROM " . $safe_table . "
                WHERE start_time < %s",
                gmdate('Y-m-d H:i:s', $timestamp)
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

            $deleted_count = 0;
            $deleted_size = 0;

            foreach ($recordings as $recording) {
                // Delete file if it exists (only for file storage)
                if ($recording->storage_type === 'file' && !empty($recording->file_path)) {
                    // Get proper file path using WordPress upload directory
                    $upload_dir = wp_upload_dir();
                    $base_dir = trailingslashit($upload_dir['basedir']) . 'opti-behavior-data/recordings/';

                    // If Pro version is active and has file storage, use its base directory
                    if ($file_storage && method_exists($file_storage, 'get_base_dir')) {
                        $base_dir = trailingslashit($file_storage->get_base_dir());
                    }

                    $full_path = $base_dir . ltrim($recording->file_path, '/\\');
                    if (file_exists($full_path)) {
                        $deleted_size += filesize($full_path);
                        wp_delete_file($full_path);
                    }
                }

                // Delete database record
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->delete($table, array('id' => $recording->id), array('%d'));
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $deleted_count++;
            }

            wp_send_json_success(array(
                'message' => sprintf(
                    'Deleted %d recording(s) (%.2f MB) created before %s',
                    $deleted_count,
                    $deleted_size / 1024 / 1024,
                    gmdate('Y-m-d', $timestamp)
                )
            ));
        }

        /**
         * AJAX handler for cleanup recordings by duration.
         *
         * @since 1.0.0
         * @global wpdb $wpdb WordPress database abstraction object.
         */
        private function ajax_cleanup_by_duration_impl() {
            check_ajax_referer('opti_behavior_cleanup', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => esc_html__( 'Permission denied', 'opti-behavior' )));
            }

            $min_duration = isset($_POST['duration']) ? intval( wp_unslash( $_POST['duration'] ) ) : 0;
            if ($min_duration < 1) {
                wp_send_json_error(array('message' => esc_html__( 'Invalid duration', 'opti-behavior' )));
            }

            global $wpdb;
            $table = $wpdb->prefix . 'optibehavior_recordings';

            // Get file storage instance (only if Pro is active)
            $file_storage = null;
            if (opti_behavior_pro_active() && class_exists('Opti_Behavior_Heatmap_File_Storage')) {
                $file_storage = new Opti_Behavior_Heatmap_File_Storage( $this->heatmap->get_debug_manager() );
            }

            // Escape table name for WordPress Plugin Check compliance
            $safe_table = esc_sql( $table );

            // Bug 10: Filter by duration threshold in SQL and cap batch size to avoid memory exhaustion.
            // recording_data (large blob) and start_time are excluded as they are not needed here.
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            $recordings = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, session_id, file_path, storage_type, duration
                FROM " . $safe_table . "
                WHERE duration > 0 AND duration < %d
                LIMIT 500",
                $min_duration
            ) );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

            $deleted_count = 0;
            $deleted_size = 0;

            foreach ($recordings as $recording) {
                // Use duration from database if available
                $duration = isset($recording->duration) ? intval($recording->duration) : 0;

                // Delete if duration is below threshold
                if ($duration > 0 && $duration < $min_duration) {
                    // Delete file if it exists (only for file storage)
                    if ($recording->storage_type === 'file' && !empty($recording->file_path)) {
                        // Get proper file path using WordPress upload directory
                        $upload_dir = wp_upload_dir();
                        $base_dir = trailingslashit($upload_dir['basedir']) . 'opti-behavior-data/recordings/';

                        // If Pro version is active and has file storage, use its base directory
                        if ($file_storage && method_exists($file_storage, 'get_base_dir')) {
                            $base_dir = trailingslashit($file_storage->get_base_dir());
                        }

                        $full_path = $base_dir . ltrim($recording->file_path, '/\\');
                        if (file_exists($full_path)) {
                            $deleted_size += filesize($full_path);
                            wp_delete_file($full_path);
                        }
                    }

                    // Delete database record
                    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                    $wpdb->delete($table, array('id' => $recording->id), array('%d'));
                    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $deleted_count++;
                }
            }

            wp_send_json_success(array(
                'message' => sprintf(
                    'Deleted %d recording(s) (%.2f MB) shorter than %d seconds',
                    $deleted_count,
                    $deleted_size / 1024 / 1024,
                    $min_duration
                )
            ));
        }

        /**
         * AJAX handler for cleanup orphaned recording files.
         *
         * @since 1.0.0
         * @global wpdb $wpdb WordPress database abstraction object.
         */
        private function ajax_cleanup_orphaned_files_impl() {
            check_ajax_referer('opti_behavior_cleanup', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => esc_html__( 'Permission denied', 'opti-behavior' )));
            }

            global $wpdb;
            $table = $wpdb->prefix . 'optibehavior_recordings';

            // Escape table name for WordPress Plugin Check compliance
            $safe_table = esc_sql( $table );

            // Get all file paths from database
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            $db_file_paths = $wpdb->get_col(
                "SELECT file_path FROM " . $safe_table . " WHERE file_path IS NOT NULL AND file_path != ''"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

            // Get proper file path using WordPress upload directory
            $upload_dir = wp_upload_dir();
            $base_dir = trailingslashit($upload_dir['basedir']) . 'opti-behavior-data/recordings/';

            // Get file storage instance (only if Pro is active)
            $file_storage = null;
            if (opti_behavior_pro_active() && class_exists('Opti_Behavior_Heatmap_File_Storage')) {
                $file_storage = new Opti_Behavior_Heatmap_File_Storage( $this->heatmap->get_debug_manager() );
                // If Pro version has file storage, use its base directory
                if (method_exists($file_storage, 'get_base_dir')) {
                    $base_dir = trailingslashit($file_storage->get_base_dir());
                }
            }

            if (!is_dir($base_dir)) {
                wp_send_json_error(array('message' => esc_html__( 'Recordings directory not found', 'opti-behavior' )));
            }

            // Scan recordings directory for all .json.gz files
            $deleted_count = 0;
            $deleted_size = 0;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && pathinfo($file->getFilename(), PATHINFO_EXTENSION) === 'gz') {
                    // Get relative path from base directory
                    $relative_path = str_replace($base_dir, '', $file->getPathname());
                    $relative_path = str_replace('\\', '/', $relative_path);

                    // Check if this file has a database record
                    if (!in_array($relative_path, $db_file_paths, true)) {
                        // This is an orphaned file - delete it
                        $deleted_size += $file->getSize();
                        wp_delete_file($file->getPathname());
                        $deleted_count++;
                    }
                }
            }

            wp_send_json_success(array(
                'message' => sprintf(
                    'Deleted %d orphaned file(s) (%.2f MB)',
                    $deleted_count,
                    $deleted_size / 1024 / 1024
                )
            ));
        }

        /**
         * AJAX handler for Visitor Heatmap widget
         *
         * @since 1.0.4
         * @global wpdb $wpdb WordPress database abstraction object.
         */
        private function ajax_visitor_heatmap_impl() {
            // Verify nonce
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            // Verify user capabilities
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions', 'opti-behavior' ) ) );
            }

            // Get and sanitize parameters
            $period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'last30days';
            $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : null;
            $end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : null;

            // Get exclude_spam parameter.
            $exclude_spam = $this->resolve_spam_exclusion_from_request( $_POST );
            $GLOBALS['opti_behavior_exclude_spam'] = $exclude_spam;

            // Get date range
            $date_range = $this->get_date_range_impl( $period, $start_date, $end_date );
            $start_date = $date_range['start'];
            $end_date = $date_range['end'];

            global $wpdb;
            $sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
            $spam_clause = $this->get_spam_exclusion_clause( 's' );

            // Apply advanced filters (if any) so the heatmap reflects the filtered dataset.
            $filters     = $this->sanitize_advanced_filters_from_request( $_POST );
            $filter_sql  = ! empty( $filters ) ? $this->build_advanced_filters_sql( $filters ) : array( 'where' => '', 'params' => array() );
            $filter_join = ( '' !== $filter_sql['where'] )
                ? " LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id"
                : '';

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            // Session-based activity grid (same basis as dashboard Sessions KPI scope).
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT
                    DAYOFWEEK(s.start_time) AS day_of_week,
                    HOUR(s.start_time) AS hour,
                    COUNT(*) AS activity_count
                 FROM " . $sessions_table . " s" . $filter_join . "
                 WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
                 GROUP BY DAYOFWEEK(s.start_time), HOUR(s.start_time)
                 ORDER BY day_of_week, hour",
                 array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
            ) );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

            $heatmap_data = array();
            foreach ( (array) $results as $row ) {
                $day = isset( $row->day_of_week ) ? (int) $row->day_of_week : 0;
                $hour = isset( $row->hour ) ? (int) $row->hour : 0;
                $count = isset( $row->activity_count ) ? (int) $row->activity_count : 0;

                if ( $day < 1 || $day > 7 || $hour < 0 || $hour > 23 ) {
                    continue;
                }

                if ( ! isset( $heatmap_data[ $day ] ) ) {
                    $heatmap_data[ $day ] = array();
                }
                $heatmap_data[ $day ][ $hour ] = $count;
            }

            wp_send_json_success( array(
                'heatmap_data' => $heatmap_data,
                'period' => $period,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'metric_basis' => 'sessions'
            ) );
        }

        /**
         * AJAX handler for batch spam recalculation.
         * Processes sessions in batches to avoid timeout and provides progress updates.
         *
         * @since 1.0.8.29
         */
        public function ajax_recalculate_spam() {
            check_ajax_referer( 'opti_behavior_settings_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            global $wpdb;
            $sessions_table = $wpdb->prefix . 'optibehavior_sessions';
            $events_table = $wpdb->prefix . 'optibehavior_events';

            // Get parameters
            $batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 500;
            $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
            $action_type = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : 'recalculate';

            // Get spam settings
            $settings = get_option( 'opti_behavior_traffic_settings', array() );
            $spam_duration_threshold = isset( $settings['spam_duration_threshold'] ) ? max( 0, intval( $settings['spam_duration_threshold'] ) ) : 3;
            $spam_min_scrolls_threshold = isset( $settings['spam_min_scrolls_threshold'] ) ? max( 0, intval( $settings['spam_min_scrolls_threshold'] ) ) : 0;
            $spam_min_clicks_threshold = isset( $settings['spam_min_clicks_threshold'] ) ? max( 0, intval( $settings['spam_min_clicks_threshold'] ) ) : 1;

            // First call - get total count and reset spam flags
            if ( $offset === 0 && $action_type === 'start' ) {
                // Get total sessions count (excluding bots)
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $total_sessions = $wpdb->get_var(
                    "SELECT COUNT(*) FROM " . $sessions_table . " WHERE traffic_type != 'bot' OR traffic_type IS NULL"
                );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

                // Reset all spam sessions to human (keep bots as bots)
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query(
                    "UPDATE " . $sessions_table . " SET traffic_type = 'human', spam_reason = NULL
                     WHERE traffic_type = 'spam'"
                );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

                // Store total in transient for progress tracking
                set_transient( 'opti_behavior_spam_recalc_total', $total_sessions, 3600 );
                set_transient( 'opti_behavior_spam_recalc_processed', 0, 3600 );

                wp_send_json_success( array(
                    'status' => 'started',
                    'total' => (int) $total_sessions,
                    'processed' => 0,
                    'progress' => 0,
                    'next_offset' => 0
                ) );
            }

            // Get batch of session IDs to process
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            $sessions = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM " . $sessions_table . "
                 WHERE (traffic_type = 'human' OR traffic_type IS NULL)
                 ORDER BY id ASC
                 LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ) );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( empty( $sessions ) ) {
                // All done - clear transients and caches
                delete_transient( 'opti_behavior_spam_recalc_total' );
                delete_transient( 'opti_behavior_spam_recalc_processed' );

                // Clear related caches
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_optibehavior_top_users_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_timeout_optibehavior_top_users_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_opti_behavior_traffic_class_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_timeout_opti_behavior_traffic_class_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

                wp_send_json_success( array(
                    'status' => 'completed',
                    'message' => __( 'Spam recalculation completed successfully.', 'opti-behavior' )
                ) );
            }

            // Process this batch - mark sessions as spam based on criteria
            $session_ids_placeholder = implode( ',', array_fill( 0, count( $sessions ), '%s' ) );

            // Update sessions that fail ANY of the spam criteria:
            // A legitimate session must meet ALL: duration >= threshold AND scrolls >= min AND clicks >= min
            // So spam = fails at least one criterion (OR logic)
            // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            $query = $wpdb->prepare(
                "UPDATE " . $sessions_table . " s
                 LEFT JOIN (
                     SELECT session_id, COUNT(*) as scroll_count
                     FROM " . $events_table . "
                     WHERE session_id IN (" . $session_ids_placeholder . ")
                     AND event IN (32, 33)
                     GROUP BY session_id
                 ) sc ON s.id = sc.session_id
                 LEFT JOIN (
                     SELECT session_id, COUNT(*) as click_count
                     FROM " . $events_table . "
                     WHERE session_id IN (" . $session_ids_placeholder . ")
                     AND event IN (16, 17)
                     GROUP BY session_id
                 ) cc ON s.id = cc.session_id
                 SET s.traffic_type = 'spam',
                     s.spam_reason = CONCAT_WS(',',
                         CASE WHEN (
                             CASE WHEN s.duration > 0 THEN s.duration
                                  ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time))
                             END
                         ) < %d THEN 'short_duration' ELSE NULL END,
                         CASE WHEN COALESCE(sc.scroll_count, 0) < %d THEN 'few_scrolls' ELSE NULL END,
                         CASE WHEN COALESCE(cc.click_count, 0) < %d THEN 'few_clicks' ELSE NULL END
                     )
                 WHERE s.id IN (" . $session_ids_placeholder . ")
                 AND (
                     (
                         CASE
                             WHEN s.duration > 0 THEN s.duration
                             ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time))
                         END
                     ) < %d
                     OR COALESCE(sc.scroll_count, 0) < %d
                     OR COALESCE(cc.click_count, 0) < %d
                 )",
                array_merge( $sessions, $sessions, array( $spam_duration_threshold, $spam_min_scrolls_threshold, $spam_min_clicks_threshold ), $sessions, array( $spam_duration_threshold, $spam_min_scrolls_threshold, $spam_min_clicks_threshold ) )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            $wpdb->query( $query );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

            // Update progress
            $total = get_transient( 'opti_behavior_spam_recalc_total' );
            $processed = $offset + count( $sessions );
            set_transient( 'opti_behavior_spam_recalc_processed', $processed, 3600 );

            $progress = $total > 0 ? round( ( $processed / $total ) * 100 ) : 100;

            wp_send_json_success( array(
                'status' => 'processing',
                'total' => (int) $total,
                'processed' => (int) $processed,
                'progress' => (int) $progress,
                'next_offset' => $offset + $batch_size
            ) );
        }

        /**
         * AJAX handler to get current spam recalculation status.
         *
         * @since 1.0.8.29
         */
        public function ajax_recalculate_spam_status() {
            check_ajax_referer( 'opti_behavior_settings_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            $total = get_transient( 'opti_behavior_spam_recalc_total' );
            $processed = get_transient( 'opti_behavior_spam_recalc_processed' );

            if ( false === $total ) {
                wp_send_json_success( array(
                    'status' => 'idle',
                    'message' => __( 'No recalculation in progress.', 'opti-behavior' )
                ) );
            }

            $progress = $total > 0 ? round( ( $processed / $total ) * 100 ) : 0;

            wp_send_json_success( array(
                'status' => 'processing',
                'total' => (int) $total,
                'processed' => (int) $processed,
                'progress' => (int) $progress
            ) );
        }

        /**
         * Return the canonical Danger Zone cleanup table manifest.
         *
         * The Full Reset and Date Range workflows intentionally share this map
         * so new Opti-Behavior data stores are added in exactly one place.
         *
         * `date_column` / `date_columns` describe the safest available range
         * predicate for Date Range deletion. When multiple candidate columns are
         * supplied, the runtime chooses the first column that exists on the
         * current site's schema, which keeps older installs and Pro schema
         * migrations compatible.
         *
         * @since 1.0.8.31
         * @return array
         */
        private function get_danger_zone_cleanup_manifest() {
            return array(
                array( 'name' => 'optibehavior_sessions',            'category' => 'sessions',       'label' => __( 'Sessions', 'opti-behavior' ),              'date_column' => 'start_time', 'range_delete' => true ),
                array( 'name' => 'optibehavior_pageviews',           'category' => 'sessions',       'label' => __( 'Pageviews', 'opti-behavior' ),             'date_column' => 'view_time', 'range_delete' => true ),
                array( 'name' => 'optibehavior_session_pages',       'category' => 'sessions',       'label' => __( 'Session Pages', 'opti-behavior' ),         'date_column' => 'entry_time', 'range_delete' => true ),
                array( 'name' => 'optibehavior_pages',               'category' => 'sessions',       'label' => __( 'Pages', 'opti-behavior' ),                 'date_column' => 'insert_at' ),
                array( 'name' => 'optibehavior_daily_stats',         'category' => 'sessions',       'label' => __( 'Daily Stats', 'opti-behavior' ),           'date_column' => 'stat_date', 'range_delete' => true ),
                array( 'name' => 'optibehavior_visitor_daily_stats', 'category' => 'sessions',       'label' => __( 'Visitor Daily Stats', 'opti-behavior' ),   'date_column' => 'stat_date', 'range_delete' => true ),
                array( 'name' => 'optibehavior_daily_dimension_stats', 'category' => 'sessions',    'label' => __( 'Daily Dimension Stats', 'opti-behavior' ), 'date_column' => 'stat_date', 'range_delete' => true ),
                array( 'name' => 'optibehavior_visitors',            'category' => 'visitors',       'label' => __( 'Visitors', 'opti-behavior' ),              'date_column' => 'first_visit' ),
                array( 'name' => 'optibehavior_bot_visits',          'category' => 'visitors',       'label' => __( 'Bot Visits', 'opti-behavior' ),            'date_column' => 'visit_time', 'range_delete' => true ),
                array( 'name' => 'optibehavior_events',              'category' => 'events',         'label' => __( 'Events & Interactions', 'opti-behavior' ), 'date_column' => 'insert_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_heatmap_pages',       'category' => 'heatmaps',       'label' => __( 'Heatmap Pages', 'opti-behavior' ),         'date_columns' => array( 'last_data_at', 'updated_at', 'created_at' ) ),
                array( 'name' => 'optibehavior_heatmap_daily',       'category' => 'heatmaps',       'label' => __( 'Heatmap Daily Index', 'opti-behavior' ),   'date_column' => 'day', 'range_delete' => true ),
                array( 'name' => 'optibehavior_recordings',          'category' => 'recordings',     'label' => __( 'Recordings', 'opti-behavior' ),            'date_column' => 'start_time', 'range_delete' => true ),
                array( 'name' => 'optibehavior_referrers',           'category' => 'traffic',        'label' => __( 'Referrers', 'opti-behavior' ),             'date_column' => 'created_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_outbound_clicks',     'category' => 'traffic',        'label' => __( 'Outbound Clicks', 'opti-behavior' ),       'date_column' => 'created_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_errors',              'category' => 'errors',         'label' => __( 'Errors', 'opti-behavior' ),                'date_column' => 'occurred_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_error_types',         'category' => 'errors',         'label' => __( 'Error Types', 'opti-behavior' ),           'date_columns' => array( 'from' => 'first_seen', 'to' => 'last_seen' ), 'date_condition' => 'overlap' ),
                array( 'name' => 'optibehavior_broken_links',        'category' => 'errors',         'label' => __( 'Broken Links', 'opti-behavior' ),          'date_columns' => array( 'from' => 'first_detected', 'to' => 'last_detected' ), 'date_condition' => 'overlap' ),
                array( 'name' => 'optibehavior_friction',            'category' => 'errors',         'label' => __( 'Friction Data', 'opti-behavior' ),         'date_columns' => array( 'occurred_at', 'created_at' ), 'range_delete' => true ),
                array( 'name' => 'optibehavior_performance',         'category' => 'errors',         'label' => __( 'Performance Data', 'opti-behavior' ),      'date_columns' => array( 'measured_at', 'created_at' ), 'range_delete' => true ),
                array( 'name' => 'optibehavior_insights',            'category' => 'smart_insights', 'label' => __( 'Smart Insights', 'opti-behavior' ),        'date_columns' => array( 'from' => 'date_from', 'to' => 'date_to' ), 'date_condition' => 'overlap' ),
                array( 'name' => 'opti_behavior_funnels',            'category' => 'funnels',        'label' => __( 'Funnels', 'opti-behavior' ),               'date_column' => 'created_at' ),
                array( 'name' => 'opti_behavior_funnel_tracking',    'category' => 'funnels',        'label' => __( 'Funnel Tracking', 'opti-behavior' ),       'date_column' => 'entry_time', 'range_delete' => true ),
                array( 'name' => 'optibehavior_form_interactions',   'category' => 'forms',          'label' => __( 'Form Interactions', 'opti-behavior' ),     'date_column' => 'created_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_form_submissions',    'category' => 'forms',          'label' => __( 'Form Submissions', 'opti-behavior' ),      'date_column' => 'created_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_journey_groups',      'category' => 'forms',          'label' => __( 'Journey Groups', 'opti-behavior' ),        'date_column' => 'created_at' ),
                array( 'name' => 'optibehavior_report_schedules',    'category' => 'forms',          'label' => __( 'Report Schedules', 'opti-behavior' ),      'date_column' => 'created_at' ),
                array( 'name' => 'optibehavior_report_logs',         'category' => 'forms',          'label' => __( 'Report Logs', 'opti-behavior' ),           'date_column' => 'sent_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_ab_daily_stats',      'category' => 'ab_testing',     'label' => __( 'A/B Daily Stats', 'opti-behavior' ),       'date_column' => 'stat_date', 'range_delete' => true ),
                array( 'name' => 'optibehavior_ab_conversions',      'category' => 'ab_testing',     'label' => __( 'A/B Conversions', 'opti-behavior' ),       'date_column' => 'created_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_ab_impressions',      'category' => 'ab_testing',     'label' => __( 'A/B Impressions', 'opti-behavior' ),       'date_column' => 'created_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_ab_goals',            'category' => 'ab_testing',     'label' => __( 'A/B Goals', 'opti-behavior' ),             'date_column' => 'created_at' ),
                array( 'name' => 'optibehavior_ab_variants',         'category' => 'ab_testing',     'label' => __( 'A/B Variants', 'opti-behavior' ),          'date_column' => 'created_at' ),
                array( 'name' => 'optibehavior_ab_tests',            'category' => 'ab_testing',     'label' => __( 'A/B Tests', 'opti-behavior' ),             'date_column' => 'created_at' ),
                array( 'name' => 'optibehavior_ab_decision_log',     'category' => 'ab_testing',     'label' => __( 'A/B Decision Log', 'opti-behavior' ),      'date_column' => 'created_at', 'range_delete' => true ),
                array( 'name' => 'optibehavior_ab_targeting_rules',  'category' => 'ab_testing',     'label' => __( 'A/B Targeting Rules', 'opti-behavior' ),   'date_column' => 'created_at' ),
                array( 'name' => 'optibehavior_ab_schedule',         'category' => 'ab_testing',     'label' => __( 'A/B Schedule', 'opti-behavior' ),          'date_column' => 'created_at' ),
            );
        }

        /**
         * Return the Date Range-safe cleanup manifest.
         *
         * Parent/configuration rows are intentionally excluded because deleting
         * them by created_at can orphan analytics rows outside the selected range.
         *
         * @since 1.0.8.31
         * @return array
         */
        private function get_danger_zone_range_cleanup_manifest() {
            return array_values(
                array_filter(
                    $this->get_danger_zone_cleanup_manifest(),
                    function ( $table ) {
                        return ! empty( $table['range_delete'] ) && ( ! empty( $table['date_column'] ) || ! empty( $table['date_columns'] ) );
                    }
                )
            );
        }

        /**
         * Return the ordered category keys used by the Danger Zone selector.
         *
         * @since 1.0.8.31
         * @return array
         */
        private function get_danger_zone_cleanup_category_keys() {
            return array( 'sessions', 'visitors', 'events', 'heatmaps', 'recordings', 'traffic', 'errors', 'smart_insights', 'funnels', 'forms', 'ab_testing' );
        }

        /**
         * Group the canonical manifest by category.
         *
         * @since 1.0.8.31
         * @return array
         */
        private function get_danger_zone_cleanup_categories_map() {
            $categories = array_fill_keys( $this->get_danger_zone_cleanup_category_keys(), array() );

            foreach ( $this->get_danger_zone_cleanup_manifest() as $table ) {
                if ( isset( $categories[ $table['category'] ] ) ) {
                    $categories[ $table['category'] ][] = $table;
                }
            }

            return $categories;
        }

        /**
         * Map Danger Zone categories to the file directories they own.
         *
         * Mirrors the selective Full Reset file cleanup:
         *   events     → opti-behavior-data/events/
         *   recordings → opti-behavior-data/recordings/
         *   heatmaps   → opti-behavior-data/heatmaps/ plus root-level heatmap
         *                hash folders (32-char hex name or clicks/moves/scrolls
         *                subdirectories).
         *
         * @since 1.0.8.32
         * @return array Category key => array of absolute directory paths.
         */
        private function get_danger_zone_file_dir_map() {
            $upload_dir = wp_upload_dir();
            $data_base  = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data';

            $map = array(
                'events'     => array( $data_base . '/events' ),
                'recordings' => array( $data_base . '/recordings' ),
                'heatmaps'   => array( $data_base . '/heatmaps' ),
            );

            if ( is_dir( $data_base ) ) {
                $root_items = glob( trailingslashit( $data_base ) . '*', GLOB_ONLYDIR );
                if ( is_array( $root_items ) ) {
                    foreach ( $root_items as $root_dir ) {
                        $folder_name = basename( $root_dir );
                        if ( in_array( $folder_name, array( 'events', 'recordings', 'heatmaps' ), true ) ) {
                            continue;
                        }

                        $is_hash_folder      = ( 32 === strlen( $folder_name ) && ctype_xdigit( $folder_name ) );
                        $has_heatmap_subdirs = is_dir( $root_dir . '/clicks' ) || is_dir( $root_dir . '/moves' ) || is_dir( $root_dir . '/scrolls' );

                        if ( $is_hash_folder || $has_heatmap_subdirs ) {
                            $map['heatmaps'][] = $root_dir;
                        }
                    }
                }
            }

            return $map;
        }

        /**
         * Recursively sum the file sizes inside a directory.
         *
         * Every file is counted — including guard files (index.php / .htaccess) —
         * to stay consistent with the Storage Stats tab, whose
         * calculate_folder_size() counts all files. Unreadable entries are
         * skipped silently.
         *
         * @since 1.0.8.32
         * @param string $dir Absolute directory path.
         * @return int Total bytes.
         */
        private function calculate_danger_zone_dir_size( $dir ) {
            if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
                return 0;
            }

            $bytes = 0;

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ( $iterator as $item ) {
                    if ( $item->isFile() ) {
                        $size = $item->getSize();
                        if ( false !== $size ) {
                            $bytes += (int) $size;
                        }
                    }
                }
            } catch ( \Exception $e ) {
                // Directory disappeared or is unreadable mid-scan; report what we have.
                unset( $e );
            }

            return $bytes;
        }

        /**
         * Format a byte count for display.
         *
         * Replicates format_bytes() from the Settings Views trait (used by the
         * Storage Stats tab) so both tabs render identical strings. Kept as a
         * local copy so this trait stays self-contained for standalone tests.
         *
         * @since 1.0.8.32
         * @param int $bytes Byte count.
         * @return string
         */
        private function format_danger_zone_size( $bytes ) {
            $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

            $bytes = max( (int) $bytes, 0 );
            $pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
            $pow   = min( $pow, count( $units ) - 1 );

            $bytes /= pow( 1024, $pow );

            return round( $bytes, 2 ) . ' ' . $units[ (int) $pow ];
        }

        /**
         * Compute per-category database and file storage sizes for the Danger Zone.
         *
         * Results are cached in a transient so the AJAX endpoint answers instantly
         * on repeat loads. The transient name intentionally starts with
         * `optibehavior_` so the existing post-deletion transient wipe in
         * ajax_delete_all_data() invalidates it automatically after a Full Reset.
         *
         * Database sizes come from a single information_schema.TABLES query
         * (data_length + index_length), then tables verified empty via exact
         * COUNT(*) are zeroed — the same rule the Storage Stats tab applies —
         * so both tabs report consistent numbers.
         *
         * @since 1.0.8.32
         * @param bool $force Skip the transient cache and recompute.
         * @return array
         */
        private function get_danger_zone_category_sizes( $force = false ) {
            $cache_key = 'optibehavior_danger_zone_sizes';

            if ( ! $force ) {
                $cached = get_transient( $cache_key );
                if ( is_array( $cached ) && isset( $cached['categories'] ) ) {
                    $cached['cached'] = true;
                    return $cached;
                }
            }

            global $wpdb;

            $categories_map = $this->get_danger_zone_cleanup_categories_map();
            $file_dir_map   = $this->get_danger_zone_file_dir_map();

            // Collect all prefixed table names across the manifest.
            $table_names = array();
            foreach ( $categories_map as $tables ) {
                foreach ( $tables as $table ) {
                    $table_names[] = $wpdb->prefix . $table['name'];
                }
            }
            $table_names = array_values( array_unique( $table_names ) );

            // Single information_schema query for every manifest table.
            $table_sizes = array();
            if ( ! empty( $table_names ) ) {
                $placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list built from a fixed manifest; values passed via prepare().
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT table_name AS tbl, ( COALESCE( data_length, 0 ) + COALESCE( index_length, 0 ) ) AS total_bytes
                     FROM information_schema.TABLES
                     WHERE table_schema = %s AND table_name IN ( {$placeholders} )",
                    array_merge( array( DB_NAME ), $table_names )
                ), ARRAY_A );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

                if ( is_array( $rows ) ) {
                    foreach ( $rows as $row ) {
                        $table_sizes[ $row['tbl'] ] = (int) $row['total_bytes'];
                    }
                }

                // Consistency with the Storage Stats tab: when a table has 0 rows
                // (exact COUNT, not the InnoDB estimate), report 0 bytes because
                // InnoDB tablespace overhead is not user data. Only tables that
                // exist (returned by information_schema) are counted.
                foreach ( array_keys( $table_sizes ) as $existing_table ) {
                    if ( 0 === $table_sizes[ $existing_table ] ) {
                        continue;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from the fixed manifest + $wpdb->prefix.
                    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                    $row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$existing_table}`" );
                    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    if ( 0 === $row_count ) {
                        $table_sizes[ $existing_table ] = 0;
                    }
                }
            }

            $payload = array(
                'categories'   => array(),
                'totals'       => array(),
                'generated_at' => time(),
                'cached'       => false,
            );

            $total_db_bytes    = 0;
            $total_files_bytes = 0;

            foreach ( $categories_map as $category_key => $tables ) {
                $db_bytes = 0;
                foreach ( $tables as $table ) {
                    $full_name = $wpdb->prefix . $table['name'];
                    if ( isset( $table_sizes[ $full_name ] ) ) {
                        $db_bytes += $table_sizes[ $full_name ];
                    }
                }

                $files_bytes = 0;
                $has_files   = isset( $file_dir_map[ $category_key ] );
                if ( $has_files ) {
                    foreach ( $file_dir_map[ $category_key ] as $dir ) {
                        $files_bytes += $this->calculate_danger_zone_dir_size( $dir );
                    }
                }

                $total_db_bytes    += $db_bytes;
                $total_files_bytes += $files_bytes;

                $payload['categories'][ $category_key ] = array(
                    'db_bytes'    => $db_bytes,
                    'files_bytes' => $files_bytes,
                    'total_bytes' => $db_bytes + $files_bytes,
                    'db_human'    => $this->format_danger_zone_size( $db_bytes ),
                    'files_human' => $this->format_danger_zone_size( $files_bytes ),
                    'total_human' => $this->format_danger_zone_size( $db_bytes + $files_bytes ),
                    'has_files'   => $has_files,
                );
            }

            $payload['totals'] = array(
                'db_bytes'    => $total_db_bytes,
                'files_bytes' => $total_files_bytes,
                'total_bytes' => $total_db_bytes + $total_files_bytes,
                'db_human'    => $this->format_danger_zone_size( $total_db_bytes ),
                'files_human' => $this->format_danger_zone_size( $total_files_bytes ),
                'total_human' => $this->format_danger_zone_size( $total_db_bytes + $total_files_bytes ),
            );

            /**
             * Filter the Danger Zone storage sizes cache TTL in seconds.
             *
             * @since 1.0.8.32
             * @param int $ttl Cache lifetime. Default 900 (15 minutes).
             */
            $ttl = (int) apply_filters( 'opti_behavior_danger_zone_sizes_ttl', 900 );
            set_transient( $cache_key, $payload, max( 60, $ttl ) );

            return $payload;
        }

        /**
         * AJAX handler: return per-category storage sizes for the Danger Zone UI.
         *
         * Called asynchronously after the settings page paints so size
         * computation never blocks page load.
         *
         * @since 1.0.8.32
         */
        public function ajax_get_danger_zone_sizes() {
            check_ajax_referer( 'opti_behavior_danger_sizes', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            $force = isset( $_POST['refresh'] ) && '1' === sanitize_key( wp_unslash( $_POST['refresh'] ) );

            wp_send_json_success( $this->get_danger_zone_category_sizes( $force ) );
        }

        /**
         * AJAX handler: Storage Stats tab — sizes + estimated rows for all
         * plugin tables (single information_schema query).
         *
         * Called asynchronously after the storage-stats shell paints so table
         * enumeration never blocks page load. Row counts here are InnoDB
         * estimates; exact counts arrive via ajax_storage_stats_counts().
         *
         * @since 1.8.x
         */
        public function ajax_storage_stats_tables() {
            check_ajax_referer( 'opti_behavior_storage_stats', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            $overview = $this->get_storage_tables_overview();

            $tables           = array();
            $total_db_size    = 0;
            $records_estimate = 0;

            foreach ( $overview as $table ) {
                $total_db_size    += $table['total_size'];
                $records_estimate += $table['rows_estimate'];

                $table['rows_estimate_fmt'] = number_format_i18n( $table['rows_estimate'] );
                $table['data_size_fmt']     = $this->format_bytes( $table['data_size'] );
                $table['index_size_fmt']    = $this->format_bytes( $table['index_size'] );
                $table['total_size_fmt']    = $this->format_bytes( $table['total_size'] );

                $tables[] = $table;
            }

            wp_send_json_success( array(
                'tables' => $tables,
                'totals' => array(
                    'db_size'              => $total_db_size,
                    'db_size_fmt'          => $this->format_bytes( $total_db_size ),
                    'table_count'          => count( $tables ),
                    'records_estimate'     => $records_estimate,
                    'records_estimate_fmt' => number_format_i18n( $records_estimate ),
                ),
            ) );
        }

        /**
         * AJAX handler: Storage Stats tab — exact COUNT(*) for a batch of
         * plugin tables.
         *
         * Accepts `tables[]` (table suffixes, allowlist-validated against
         * get_storage_table_definitions()); batch size is capped at 10
         * server-side so one request can never queue dozens of full scans.
         *
         * @since 1.8.x
         */
        public function ajax_storage_stats_counts() {
            check_ajax_referer( 'opti_behavior_storage_stats', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry sanitized below, then allowlist-validated in get_storage_tables_exact_counts().
            $raw = isset( $_POST['tables'] ) ? (array) wp_unslash( $_POST['tables'] ) : array();

            $suffixes = array();
            foreach ( $raw as $suffix ) {
                if ( is_string( $suffix ) && '' !== $suffix ) {
                    $suffixes[] = sanitize_key( $suffix );
                }
            }

            // Server-side batch cap (helper also enforces it).
            $suffixes = array_slice( $suffixes, 0, 10 );

            $exact_counts = $this->get_storage_tables_exact_counts( $suffixes );

            $counts = array();
            foreach ( $exact_counts as $suffix => $row_count ) {
                $counts[ $suffix ] = array(
                    'rows'     => $row_count,
                    'rows_fmt' => number_format_i18n( $row_count ),
                );
            }

            wp_send_json_success( array( 'counts' => $counts ) );
        }

        /**
         * AJAX handler: Storage Stats tab — file storage statistics
         * (recursive scan of uploads/opti-behavior-data).
         *
         * @since 1.8.x
         */
        public function ajax_storage_stats_files() {
            check_ajax_referer( 'opti_behavior_storage_stats', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            $stats = $this->get_file_storage_stats();

            $stats['total_size_fmt']   = $this->format_bytes( $stats['total_size'] );
            $stats['file_count_fmt']   = number_format_i18n( $stats['file_count'] );
            $stats['folder_count_fmt'] = number_format_i18n( $stats['folder_count'] );

            wp_send_json_success( $stats );
        }

        /**
         * AJAX: manually repair the heatmap DB/file sync-status cache (Option A —
         * recompute-only, spec §3c, per the confirmed decision). Scoped to one
         * page (list row "Repair" badge, UI added in a later step) or global
         * (Danger Zone "Repair Heatmap Sync" button, same). Never writes or
         * restores rows in `optibehavior_recordings` / `optibehavior_session_pages` /
         * `optibehavior_sessions` — this only force-refreshes the
         * Opti_Behavior_Heatmap_Dashboard::HEATMAP_SYNC_TRANSIENT cache read by
         * Opti_Behavior_Heatmap_Ajax::get_session_counts_with_sync_status(),
         * bypassing its normal TTL. The compute/merge helpers and the advisory
         * lock live on Opti_Behavior_Heatmap_Dashboard (this trait is `use`d
         * there), so they are reused unchanged here.
         *
         * @since 1.8.x
         * @return void
         */
        public function ajax_repair_heatmap_sync() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            $requested_page_id = isset( $_POST['page_id'] ) ? absint( wp_unslash( $_POST['page_id'] ) ) : 0;

            $got_lock = $this->acquire_heatmap_compute_lock( Opti_Behavior_Heatmap_Dashboard::HEATMAP_SYNC_LOCK_NAME, 30 );
            if ( ! $got_lock ) {
                wp_send_json_error( array( 'message' => __( 'A heatmap sync repair is already running, please try again shortly.', 'opti-behavior' ) ) );
            }

            $entries  = array();
            $has_more = false;

            try {
                if ( $requested_page_id > 0 ) {
                    $page_ids = array( $requested_page_id );
                } else {
                    // Global repair: bounded per request so one AJAX call cannot
                    // run away on installs with a large page count; a later UI
                    // step can re-invoke while has_more is true to drain the rest.
                    $limit    = max( 1, (int) apply_filters( 'opti_behavior_heatmap_repair_max_pages', 100 ) );
                    $page_ids = $this->get_all_heatmap_page_ids_for_repair( $limit + 1 );
                    $has_more = count( $page_ids ) > $limit;
                    if ( $has_more ) {
                        $page_ids = array_slice( $page_ids, 0, $limit );
                    }
                }

                if ( ! empty( $page_ids ) ) {
                    // Shared implementation (also used by the daily scheduled
                    // auto-repair cron): recompute + merge the sync-status
                    // cache, then invalidate the pages' stale agg_* columns so
                    // a page reload cannot revert the just-repaired numbers
                    // (repair durability). Cheap: one UPDATE per 500 ids, no
                    // extra file IO beyond the recompute in this request.
                    $entries = $this->repair_heatmap_sync_for_pages( $page_ids );
                }
            } finally {
                $this->release_heatmap_compute_lock( Opti_Behavior_Heatmap_Dashboard::HEATMAP_SYNC_LOCK_NAME, $got_lock );
            }

            // Manual accelerator (Task 1): this button repairs one bounded batch
            // inline; arm the self-serve background drain so the REST of the
            // backlog converges without the UI having to loop. Idempotent and
            // overlap-guarded — no-ops when nothing is stale / the chain is live.
            if ( method_exists( $this, 'ensure_heatmap_backfill_drain' ) ) {
                $this->ensure_heatmap_backfill_drain();
            }

            wp_send_json_success(
                array(
                    'counts'   => $entries,
                    'has_more' => $has_more,
                )
            );
        }

        /**
         * AJAX: start (or restart) the file-first heatmap registry rebuild
         * (Option A, Danger Zone "Rebuild Heatmap Registry From Files").
         *
         * Never scans anything inline — resets the service cursor/progress and
         * schedules the first one-off cron tick, then nudges WP-Cron with a
         * non-blocking spawn so the pass begins within seconds even on
         * low-traffic sites. All heavy work happens in the bounded cron ticks
         * ({@see Opti_Behavior_Heatmap_Dashboard::run_heatmap_registry_rebuild_tick()}).
         *
         * @since 2026-08-15 (registry rebuild from files, Option A)
         * @return void
         */
        public function ajax_heatmap_rebuild_start() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' ) ) {
                wp_send_json_error( array( 'message' => __( 'Rebuild service unavailable.', 'opti-behavior' ) ) );
            }

            $service = new Opti_Behavior_Heatmap_Registry_Rebuild();
            $service->reset();

            // Kick the first tick now; continuations self-reschedule from cron.
            $this->schedule_heatmap_rebuild_continuation( 1 );
            if ( function_exists( 'spawn_cron' ) ) {
                spawn_cron(); // Non-blocking loopback; returns immediately.
            }

            wp_send_json_success( array( 'progress' => $service->get_progress() ) );
        }

        /**
         * AJAX: poll the file-first rebuild progress. Reads the progress OPTION
         * only (one autoload-off option row) — never touches the filesystem, so
         * the admin progress UI can poll every few seconds at zero disk cost.
         *
         * @since 2026-08-15 (registry rebuild from files, Option A)
         * @return void
         */
        public function ajax_heatmap_rebuild_progress() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' ) ) {
                wp_send_json_error( array( 'message' => __( 'Rebuild service unavailable.', 'opti-behavior' ) ) );
            }

            $service = new Opti_Behavior_Heatmap_Registry_Rebuild();

            wp_send_json_success(
                array(
                    'progress'       => $service->get_progress(),
                    'tick_scheduled' => (bool) wp_next_scheduled( Opti_Behavior_Heatmap_Dashboard::HEATMAP_REBUILD_CRON_HOOK ),
                )
            );
        }

        /**
         * AJAX: pause / resume / abort the file-first rebuild pass.
         *
         * Cheap option writes only — the running cron tick honors the control
         * flag on its next batch boundary:
         *  - pause:  control='pause'; the pending tick exits without work and
         *            schedules nothing (cursor preserved).
         *  - resume: control cleared; a continuation tick is re-armed.
         *  - abort:  control='abort' + cursor reset here so the state is final
         *            even while paused (no tick pending); a stale tick hitting
         *            the abort flag is a harmless no-op, and a later Start
         *            clears the flag via reset().
         *
         * @since 2026-08-15 (registry rebuild from files, Option A)
         * @return void
         */
        public function ajax_heatmap_rebuild_control() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' ) ) {
                wp_send_json_error( array( 'message' => __( 'Rebuild service unavailable.', 'opti-behavior' ) ) );
            }

            $op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
            if ( ! in_array( $op, array( 'pause', 'resume', 'abort' ), true ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid operation.', 'opti-behavior' ) ) );
            }

            $service  = new Opti_Behavior_Heatmap_Registry_Rebuild();
            $progress = $service->get_progress();

            if ( 'pause' === $op ) {
                update_option( Opti_Behavior_Heatmap_Registry_Rebuild::CONTROL_OPTION, 'pause', false );
                $progress['state'] = 'paused';
            } elseif ( 'resume' === $op ) {
                update_option( Opti_Behavior_Heatmap_Registry_Rebuild::CONTROL_OPTION, '', false );
                $progress['state'] = 'running';
                $this->schedule_heatmap_rebuild_continuation( 1 );
                if ( function_exists( 'spawn_cron' ) ) {
                    spawn_cron();
                }
            } else { // abort.
                update_option( Opti_Behavior_Heatmap_Registry_Rebuild::CONTROL_OPTION, 'abort', false );
                update_option( Opti_Behavior_Heatmap_Registry_Rebuild::CURSOR_OPTION, '', false );
                $progress['state'] = 'aborted';
            }

            $progress['updated_at'] = current_time( 'mysql' );
            update_option( Opti_Behavior_Heatmap_Registry_Rebuild::PROGRESS_OPTION, $progress, false );

            wp_send_json_success( array( 'progress' => $progress ) );
        }

        /**
         * AJAX: start (or restart) the `_orphaned/` archive dry-run report
         * (Option C phase 1, Danger Zone "Scan Archived Heatmap Data").
         *
         * REPORT-ONLY: the pass inventories the archive without writing to any
         * table or moving/restoring any file. Never scans anything inline —
         * resets the service cursor/progress and schedules the first one-off
         * cron tick; all heavy work happens in the bounded cron ticks
         * ({@see Opti_Behavior_Heatmap_Dashboard::run_heatmap_orphan_report_tick()}).
         *
         * @since 2026-08-15 (`_orphaned/` dry-run report, Option C phase 1)
         * @return void
         */
        public function ajax_heatmap_orphan_report_start() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Report' ) ) {
                wp_send_json_error( array( 'message' => __( 'Archive report service unavailable.', 'opti-behavior' ) ) );
            }

            $service = new Opti_Behavior_Heatmap_Orphan_Report();
            $service->reset();

            // Kick the first tick now; continuations self-reschedule from cron.
            $this->schedule_heatmap_orphan_report_continuation( 1 );
            if ( function_exists( 'spawn_cron' ) ) {
                spawn_cron(); // Non-blocking loopback; returns immediately.
            }

            wp_send_json_success( array( 'progress' => $service->get_progress() ) );
        }

        /**
         * AJAX: poll the `_orphaned/` dry-run report progress. Reads the
         * progress OPTION only (one autoload-off option row) — never touches
         * the filesystem, so the admin UI can poll at zero disk cost.
         *
         * @since 2026-08-15 (`_orphaned/` dry-run report, Option C phase 1)
         * @return void
         */
        public function ajax_heatmap_orphan_report_progress() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Report' ) ) {
                wp_send_json_error( array( 'message' => __( 'Archive report service unavailable.', 'opti-behavior' ) ) );
            }

            $service = new Opti_Behavior_Heatmap_Orphan_Report();

            wp_send_json_success(
                array(
                    'progress'       => $service->get_progress(),
                    'tick_scheduled' => (bool) wp_next_scheduled( Opti_Behavior_Heatmap_Dashboard::HEATMAP_ORPHAN_REPORT_CRON_HOOK ),
                )
            );
        }

        /**
         * AJAX: pause / resume / abort the `_orphaned/` dry-run report pass.
         *
         * Cheap option writes only — the running cron tick honors the control
         * flag on its next batch boundary (same semantics as the rebuild
         * control handler above).
         *
         * @since 2026-08-15 (`_orphaned/` dry-run report, Option C phase 1)
         * @return void
         */
        public function ajax_heatmap_orphan_report_control() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Report' ) ) {
                wp_send_json_error( array( 'message' => __( 'Archive report service unavailable.', 'opti-behavior' ) ) );
            }

            $op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
            if ( ! in_array( $op, array( 'pause', 'resume', 'abort' ), true ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid operation.', 'opti-behavior' ) ) );
            }

            $service  = new Opti_Behavior_Heatmap_Orphan_Report();
            $progress = $service->get_progress();

            if ( 'pause' === $op ) {
                update_option( Opti_Behavior_Heatmap_Orphan_Report::CONTROL_OPTION, 'pause', false );
                $progress['state'] = 'paused';
            } elseif ( 'resume' === $op ) {
                update_option( Opti_Behavior_Heatmap_Orphan_Report::CONTROL_OPTION, '', false );
                $progress['state'] = 'running';
                $this->schedule_heatmap_orphan_report_continuation( 1 );
                if ( function_exists( 'spawn_cron' ) ) {
                    spawn_cron();
                }
            } else { // abort.
                update_option( Opti_Behavior_Heatmap_Orphan_Report::CONTROL_OPTION, 'abort', false );
                update_option( Opti_Behavior_Heatmap_Orphan_Report::CURSOR_OPTION, '', false );
                $progress['state'] = 'aborted';
            }

            $progress['updated_at'] = current_time( 'mysql' );
            update_option( Opti_Behavior_Heatmap_Orphan_Report::PROGRESS_OPTION, $progress, false );

            wp_send_json_success( array( 'progress' => $progress ) );
        }

        /**
         * AJAX: save the `_orphaned/` archive retention window (Danger Zone
         * "Archived Heatmap Data Retention" widget).
         *
         * Cheap writes only: one option update + the daily purge cron
         * self-heal ({@see Opti_Behavior_Heatmap_Dashboard::ensure_heatmap_orphan_purge_cron()}
         * schedules the event for a non-zero window, clears it for 0 =
         * disabled). Never scans or deletes anything inline.
         *
         * @since 2026-08-15 (`_orphaned/` retention purge)
         * @return void
         */
        public function ajax_heatmap_orphan_purge_save() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Purge' ) ) {
                wp_send_json_error( array( 'message' => __( 'Archive retention service unavailable.', 'opti-behavior' ) ) );
            }

            if ( ! isset( $_POST['days'] ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid retention value.', 'opti-behavior' ) ) );
            }

            $days = min( Opti_Behavior_Heatmap_Orphan_Purge::MAX_RETENTION_DAYS, absint( wp_unslash( $_POST['days'] ) ) );
            update_option( Opti_Behavior_Heatmap_Orphan_Purge::RETENTION_OPTION, (string) $days, false );

            if ( method_exists( $this, 'ensure_heatmap_orphan_purge_cron' ) ) {
                // Schedules the daily event when enabled, clears it for 0.
                $this->ensure_heatmap_orphan_purge_cron();
            }

            $service = new Opti_Behavior_Heatmap_Orphan_Purge();

            wp_send_json_success(
                array(
                    'days'     => $days,
                    'progress' => $service->get_progress(),
                )
            );
        }

        /**
         * AJAX: run ONE batch of the Danger-Zone "Re-evaluate spam flags"
         * recovery pass (2026-08-16 mass-deletion incident, Phase C).
         *
         * Re-runs the canonical classifier over sessions currently flagged
         * `spam` whose `spam_reason` contains `few_clicks` — the population
         * the broken click-count derivation could have mis-flagged. Sessions
         * that now pass every threshold are downgraded to human; genuinely
         * click-less sessions keep their spam verdict (the
         * `spam_min_clicks_threshold = 1` default is intentionally unchanged).
         *
         * Batched + cursor-resumable: the client loops this action, passing
         * back the (start_time, id) compound cursor from the previous
         * response, until `done` is true — same chained-request shape as the
         * spam recalculation loop. `op=start` additionally returns the total
         * matching row count for the progress bar.
         *
         * @since 2026-08-16 (mass-deletion incident recovery tooling)
         * @return void
         */
        public function ajax_reevaluate_spam_flags() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
                wp_send_json_error( array( 'message' => __( 'Spam filter service unavailable.', 'opti-behavior' ) ) );
            }

            global $wpdb;

            $op          = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'batch';
            $window_days = isset( $_POST['window_days'] ) ? absint( wp_unslash( $_POST['window_days'] ) ) : 0;
            $limit       = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 200;
            $limit       = max( 1, min( 500, $limit ) );
            $reason_like = 'few_clicks';

            $cursor = null;
            if ( isset( $_POST['cursor_time'], $_POST['cursor_id'] )
                && '' !== $_POST['cursor_time'] && '' !== $_POST['cursor_id'] ) {
                $cursor = array(
                    'time' => sanitize_text_field( wp_unslash( $_POST['cursor_time'] ) ),
                    'id'   => sanitize_text_field( wp_unslash( $_POST['cursor_id'] ) ),
                );
            }

            $total = null;
            if ( 'start' === $op ) {
                $sessions_table = $wpdb->prefix . 'optibehavior_sessions';
                $where          = "traffic_type = 'spam' AND spam_reason LIKE %s";
                $params         = array( '%' . $wpdb->esc_like( $reason_like ) . '%' );
                if ( $window_days > 0 ) {
                    $where   .= ' AND start_time >= %s';
                    $params[] = gmdate( 'Y-m-d H:i:s', time() - $window_days * DAY_IN_SECONDS );
                }
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table from $wpdb->prefix; WHERE composed of literal SQL + placeholders bound via prepare.
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sessions_table} WHERE {$where}", $params ) );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $cursor = null; // A start always scans from the top.
            }

            $batch = Opti_Behavior_Stats_Spam_Filter::reclassify_flagged_sessions_batch(
                array(
                    'limit'       => $limit,
                    'window_days' => $window_days,
                    'cursor'      => $cursor,
                    'reason_like' => $reason_like,
                )
            );

            $response = array(
                'processed' => (int) $batch['processed'],
                'changed'   => (int) $batch['changed'],
                'done'      => (bool) $batch['done'],
                'cursor'    => is_array( $batch['cursor'] ) ? $batch['cursor'] : null,
            );
            if ( null !== $total ) {
                $response['total'] = $total;
            }

            wp_send_json_success( $response );
        }

        /**
         * AJAX: run ONE batch of the Danger-Zone "Restore Archived Heatmap
         * Data" recovery pass (2026-08-16 mass-deletion incident, Phase C).
         *
         * Each request moves a bounded number of `_orphaned/{hash}` archive
         * dirs back into the live `opti-behavior-data/` tree
         * ({@see Opti_Behavior_Heatmap_Orphan_Restore::run_batch()}); the
         * client loops until `complete` is true. The service's persisted
         * cursor makes the pass resumable across page reloads, and its
         * recovery hold pauses the retention purge for the duration.
         *
         * On completion this handler triggers the DB-side follow-up: restored
         * pages are marked aggregate-stale, the heatmap caches are flushed,
         * and the Rebuild Heatmap Registry From Files pass is started so
         * restored dirs without a registry row become visible again.
         *
         * @since 2026-08-16 (mass-deletion incident recovery tooling)
         * @return void
         */
        public function ajax_heatmap_orphan_restore_batch() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Restore' ) ) {
                wp_send_json_error( array( 'message' => __( 'Archive restore service unavailable.', 'opti-behavior' ) ) );
            }

            $op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'batch';
            $service = new Opti_Behavior_Heatmap_Orphan_Restore();

            if ( 'start' === $op ) {
                $service->reset();
            }

            $result = $service->run_batch();

            if ( ! empty( $result['complete'] ) ) {
                $this->finalize_heatmap_orphan_restore( $service->get_progress() );
            }

            wp_send_json_success(
                array(
                    'result'   => $result,
                    'progress' => $service->get_progress(),
                )
            );
        }

        /**
         * Post-restore follow-up: mark the restored pages aggregate-stale,
         * flush the heatmap caches, and kick off the registry rebuild so the
         * Heatmaps list picks the recovered data up.
         *
         * Deliberately NOT a blanket invalidation (the 2026-08-16 auto-repair
         * fix removed those): only pages whose url_hash was actually restored
         * are marked stale; dirs without a registry row are handled by the
         * rebuild pass.
         *
         * @since 2026-08-16 (mass-deletion incident recovery tooling)
         * @param array $progress Restore progress snapshot (restored_hashes).
         * @return void
         */
        private function finalize_heatmap_orphan_restore( $progress ) {
            global $wpdb;

            $hashes = ( isset( $progress['restored_hashes'] ) && is_array( $progress['restored_hashes'] ) )
                ? array_values( array_filter( array_map( 'strval', $progress['restored_hashes'] ) ) )
                : array();

            if ( ! empty( $hashes ) && class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
                $storage  = Opti_Behavior_Heatmap_Storage::get_instance();
                $table    = $wpdb->prefix . 'optibehavior_heatmap_pages';
                $page_ids = array();

                foreach ( array_chunk( $hashes, 200 ) as $chunk ) {
                    $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table from $wpdb->prefix; IN-list bound via prepare placeholders.
                    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                    $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT page_id FROM {$table} WHERE url_hash IN ({$placeholders})", $chunk ) );
                    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    foreach ( (array) $ids as $id ) {
                        $page_ids[ (int) $id ] = true;
                    }
                }

                if ( ! empty( $page_ids ) && method_exists( $storage, 'mark_pages_stale' ) ) {
                    $storage->mark_pages_stale( array_keys( $page_ids ) );
                }
            }

            if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
                opti_behavior_flush_heatmap_caches();
            }

            // Kick the file-first registry rebuild so restored dirs whose
            // registry rows are gone get re-created in the background.
            if ( class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' )
                && method_exists( $this, 'schedule_heatmap_rebuild_continuation' ) ) {
                $rebuild = new Opti_Behavior_Heatmap_Registry_Rebuild();
                $rebuild->reset();
                $this->schedule_heatmap_rebuild_continuation( 1 );
                if ( function_exists( 'spawn_cron' ) ) {
                    spawn_cron(); // Non-blocking loopback; returns immediately.
                }
            }
        }

        /**
         * Save the Unified Retention Protocol settings (master raw-data
         * retention window + Smart Insights prune window). Cheap option
         * write only — nothing is deleted from this request; the daily
         * retention cron applies the new window on its next tick.
         *
         * @since 1.9.0
         */
        public function ajax_data_retention_save() {
            check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            if ( ! class_exists( 'Opti_Behavior_Retention_Policy' ) ) {
                wp_send_json_error( array( 'message' => __( 'Retention policy service unavailable.', 'opti-behavior' ) ) );
            }

            if ( ! isset( $_POST['days'] ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid retention value.', 'opti-behavior' ) ) );
            }

            // Tiered Retention (round 2): save every submitted tier field in
            // ONE validated write so the files ≤ raw invariant is enforced
            // atomically (Opti_Behavior_Retention_Policy::validate_tiers()).
            $fields = array(
                'raw_retention_days' => absint( wp_unslash( $_POST['days'] ) ),
            );
            if ( isset( $_POST['insights_months'] ) ) {
                $fields['insights_prune_months'] = absint( wp_unslash( $_POST['insights_months'] ) );
            }
            if ( isset( $_POST['spam_daily'] ) ) {
                $fields['spam_daily_enabled'] = (bool) absint( wp_unslash( $_POST['spam_daily'] ) );
            }
            if ( isset( $_POST['files_days'] ) ) {
                $fields['files_retention_days'] = absint( wp_unslash( $_POST['files_days'] ) );
            }
            if ( isset( $_POST['aggregates_months'] ) ) {
                $fields['aggregates_retention_months'] = absint( wp_unslash( $_POST['aggregates_months'] ) );
            }

            $saved = Opti_Behavior_Retention_Policy::update_settings( $fields );

            wp_send_json_success(
                array(
                    'days'              => (int) $saved['raw_retention_days'],
                    'insights_months'   => (int) $saved['insights_prune_months'],
                    'spam_daily'        => ! empty( $saved['spam_daily_enabled'] ) ? 1 : 0,
                    'files_days'        => (int) $saved['files_retention_days'],
                    'aggregates_months' => (int) $saved['aggregates_retention_months'],
                )
            );
        }

        /**
         * Check whether a column exists on a prefixed table.
         *
         * @since 1.0.8.31
         * @param string $table_name  Full table name including prefix.
         * @param string $column_name Column name.
         * @return bool
         */
        private function danger_zone_column_exists( $table_name, $column_name ) {
            global $wpdb;

            if ( '' === (string) $column_name ) {
                return false;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup safety check.
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            return (bool) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = %s AND table_name = %s AND column_name = %s',
                    DB_NAME,
                    $table_name,
                    $column_name
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        /**
         * Resolve the first existing simple date column for a manifest entry.
         *
         * @since 1.0.8.31
         * @param array  $table      Manifest entry.
         * @param string $table_name Full table name.
         * @return string
         */
        private function resolve_danger_zone_date_column( $table, $table_name ) {
            $candidates = array();

            if ( ! empty( $table['date_column'] ) ) {
                $candidates[] = $table['date_column'];
            }

            if ( ! empty( $table['date_columns'] ) && is_array( $table['date_columns'] ) && empty( $table['date_condition'] ) ) {
                $candidates = array_merge( $candidates, $table['date_columns'] );
            }

            foreach ( $candidates as $candidate ) {
                if ( $this->danger_zone_column_exists( $table_name, $candidate ) ) {
                    return $candidate;
                }
            }

            return '';
        }

        /**
         * Delete files in a directory by modification time.
         *
         * @since 1.0.8.31
         * @param string $dir             Directory to scan.
         * @param int    $start_timestamp Start timestamp.
         * @param int    $end_timestamp   End timestamp.
         * @return int Deleted file count.
         */
        private function delete_danger_zone_files_by_date_range( $dir, $start_timestamp, $end_timestamp ) {
            if ( ! is_dir( $dir ) ) {
                return 0;
            }

            $files_deleted = 0;
            $protected_files = array( '.htaccess', 'index.php' );
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ( $iterator as $item ) {
                if ( $item->isFile() ) {
                    if ( in_array( $item->getBasename(), $protected_files, true ) ) {
                        continue;
                    }

                    $file_time = $item->getMTime();
                    if ( $file_time >= $start_timestamp && $file_time <= $end_timestamp ) {
                        $file_path = $item->getRealPath();
                        wp_delete_file( $file_path );
                        if ( ! is_file( $file_path ) ) {
                            $files_deleted++;
                        }
                    }
                }
            }

            return $files_deleted;
        }

        /**
         * Return Date Range data directories that are safe to recursively scan.
         *
         * @since 1.0.8.31
         * @param string $data_base Base opti-behavior-data directory.
         * @return array
         */
        private function get_danger_zone_date_range_file_dirs( $data_base ) {
            if ( ! is_dir( $data_base ) ) {
                return array();
            }

            $data_dirs = array(
                trailingslashit( $data_base ) . 'heatmaps',
                trailingslashit( $data_base ) . 'events',
            );

            $root_items = glob( trailingslashit( $data_base ) . '*', GLOB_ONLYDIR );
            if ( is_array( $root_items ) ) {
                foreach ( $root_items as $root_dir ) {
                    $folder_name = basename( $root_dir );

                    $is_hash_folder      = ( 32 === strlen( $folder_name ) && ctype_xdigit( $folder_name ) );
                    $has_heatmap_subdirs = is_dir( $root_dir . '/clicks' ) || is_dir( $root_dir . '/moves' ) || is_dir( $root_dir . '/scrolls' );

                    if ( $is_hash_folder || $has_heatmap_subdirs ) {
                        $data_dirs[] = $root_dir;
                    }
                }
            }

            return array_values( array_unique( array_filter( $data_dirs, 'is_dir' ) ) );
        }

        /**
         * Delete recording files from stored relative paths, constrained to the plugin data directory.
         *
         * @since 1.0.8.31
         * @param array $recording_file_paths Stored recording file paths.
         * @return int Deleted file count.
         */
        private function delete_danger_zone_recording_files( $recording_file_paths ) {
            if ( empty( $recording_file_paths ) || ! is_array( $recording_file_paths ) ) {
                return 0;
            }

            $recording_upload_dir = wp_upload_dir();
            $recording_base_dir   = trailingslashit( $recording_upload_dir['basedir'] ) . 'opti-behavior-data/';
            $recording_base_real  = realpath( $recording_base_dir );

            if ( false === $recording_base_real ) {
                return 0;
            }

            $recording_base_real = trailingslashit( wp_normalize_path( $recording_base_real ) );
            $files_deleted       = 0;

            foreach ( $recording_file_paths as $recording_file_path ) {
                $relative_path = ltrim( str_replace( '\\', '/', (string) $recording_file_path ), '/' );
                $candidate     = $recording_base_dir . $relative_path;
                $real_path     = realpath( $candidate );

                if ( false === $real_path || ! is_file( $real_path ) ) {
                    continue;
                }

                $normalized_real_path = wp_normalize_path( $real_path );
                if ( 0 !== strpos( $normalized_real_path, $recording_base_real ) ) {
                    continue;
                }

                wp_delete_file( $real_path );
                if ( ! is_file( $real_path ) ) {
                    $files_deleted++;
                }
            }

            return $files_deleted;
        }

        /**
         * Delete root-level heatmap hash folders during selective Full Reset.
         *
         * @since 1.0.8.31
         * @param string $data_dir Base opti-behavior-data directory.
         * @return int Deleted file count.
         */
        private function delete_danger_zone_heatmap_hash_folders( $data_dir ) {
            if ( ! is_dir( $data_dir ) ) {
                return 0;
            }

            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                require_once ABSPATH . '/wp-admin/includes/file.php';
                WP_Filesystem();
            }

            $files_deleted = 0;
            $items = scandir( $data_dir );
            foreach ( $items as $item ) {
                if ( in_array( $item, array( '.', '..', 'index.php', '.htaccess', 'events', 'recordings', 'heatmaps' ), true ) ) {
                    continue;
                }

                $item_path = trailingslashit( $data_dir ) . $item;
                if ( ! is_dir( $item_path ) ) {
                    continue;
                }

                $is_hash_folder      = ( 32 === strlen( $item ) && ctype_xdigit( $item ) );
                $has_heatmap_subdirs = is_dir( $item_path . '/clicks' ) || is_dir( $item_path . '/moves' ) || is_dir( $item_path . '/scrolls' );
                if ( ! $is_hash_folder && ! $has_heatmap_subdirs ) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $item_path, RecursiveDirectoryIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ( $iterator as $child ) {
                    if ( $child->isFile() ) {
                        $child_path = $child->getPathname();
                        wp_delete_file( $child_path );
                        if ( ! is_file( $child_path ) ) {
                            $files_deleted++;
                        }
                    } elseif ( $child->isDir() ) {
                        $wp_filesystem->rmdir( $child->getPathname() );
                    }
                }
                $wp_filesystem->rmdir( $item_path );
            }

            return $files_deleted;
        }

        /**
         * AJAX handler for batched deletion of all analytics data.
         * Deletes tables one by one with progress updates.
         *
         * @since 1.0.8.30
         */
        public function ajax_delete_all_data() {
            check_ajax_referer( 'opti_behavior_delete_all_ajax', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            global $wpdb;

            // Get current step
            $step = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 0;

            // Define category-to-tables mapping for the data reset selector.
            // Category keys are the canonical identifiers sent from JS via the `categories` POST param.
            $categories_map = $this->get_danger_zone_cleanup_categories_map();

            $all_category_keys = array_keys( $categories_map );

            // Resolve selected categories from POST.
            // If the `categories` param is absent or empty, default to ALL categories
            // so the handler remains fully backward-compatible.
            if ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked above
                $raw_cats = array_map( 'sanitize_key', (array) $_POST['categories'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $selected_categories = array_values(
                    array_filter( $raw_cats, function ( $cat ) use ( $all_category_keys ) {
                        return in_array( $cat, $all_category_keys, true );
                    } )
                );
            } else {
                $selected_categories = array();
            }

            // Empty / missing categories list → delete everything (backward compatibility)
            if ( empty( $selected_categories ) ) {
                $selected_categories = $all_category_keys;
            }

            // Build the filtered table list from only the selected categories
            $tables = array();
            foreach ( $selected_categories as $cat_key ) {
                if ( isset( $categories_map[ $cat_key ] ) ) {
                    foreach ( $categories_map[ $cat_key ] as $tbl ) {
                        $tables[] = $tbl;
                    }
                }
            }

            $total_tables     = count( $tables );
            $is_full_deletion = ( count( $selected_categories ) === count( $all_category_keys ) );

            // ---------------------------------------------------------------
            // Final step: all table truncations are done — clean up files and
            // finish. This block is reached when step == total_tables.
            // ---------------------------------------------------------------
            if ( $step >= $total_tables ) {
                $upload_dir    = wp_upload_dir();
                $data_dir      = $upload_dir['basedir'] . '/opti-behavior-data';
                $files_deleted = 0;

                global $wp_filesystem;
                if ( empty( $wp_filesystem ) ) {
                    require_once ABSPATH . '/wp-admin/includes/file.php';
                    WP_Filesystem();
                }

                if ( $is_full_deletion ) {
                    // Backward-compatible path: wipe everything inside opti-behavior-data/
                    if ( is_dir( $data_dir ) ) {
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator( $data_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                            \RecursiveIteratorIterator::CHILD_FIRST
                        );
                        foreach ( $iterator as $item ) {
                            if ( $item->isFile() ) {
                                wp_delete_file( $item->getPathname() );
                                $files_deleted++;
                            } elseif ( $item->isDir() ) {
                                $wp_filesystem->rmdir( $item->getPathname() );
                            }
                        }
                    }
                } else {
                    // Selective path: only remove the file directories that belong
                    // to the categories the user chose to delete.
                    //   events     → opti-behavior-data/events/
                    //   recordings → opti-behavior-data/recordings/
                    //   heatmaps   → opti-behavior-data/heatmaps/
                    $file_dirs = array();
                    if ( in_array( 'events', $selected_categories, true ) ) {
                        $file_dirs[] = $data_dir . '/events';
                    }
                    if ( in_array( 'recordings', $selected_categories, true ) ) {
                        $file_dirs[] = $data_dir . '/recordings';
                    }
                    if ( in_array( 'heatmaps', $selected_categories, true ) ) {
                        $file_dirs[] = $data_dir . '/heatmaps';
                    }

                    foreach ( $file_dirs as $dir ) {
                        if ( ! is_dir( $dir ) ) {
                            continue;
                        }
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                            \RecursiveIteratorIterator::CHILD_FIRST
                        );
                        foreach ( $iterator as $item ) {
                            if ( $item->isFile() ) {
                                wp_delete_file( $item->getPathname() );
                                $files_deleted++;
                            } elseif ( $item->isDir() ) {
                                $wp_filesystem->rmdir( $item->getPathname() );
                            }
                        }
                    }

                    if ( in_array( 'heatmaps', $selected_categories, true ) ) {
                        $files_deleted += $this->delete_danger_zone_heatmap_hash_folders( $data_dir );
                    }
                }

                // Optimize only the tables that were actually truncated in this run
                foreach ( $tables as $tbl ) {
                    $tbl_name = $wpdb->prefix . $tbl['name'];
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                    $tbl_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    if ( $tbl_exists ) {
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                        $wpdb->query( "OPTIMIZE TABLE `" . $tbl_name . "`" );
                        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    }
                }

                // Clear transient caches (safe to wipe all — they will be regenerated)
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_optibehavior_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_timeout_optibehavior_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_opti_behavior_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_timeout_opti_behavior_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

                // Heatmap dashboard caches use their own prefixes (ob_hm_stats_*,
                // ob_hm_metrics_*, opti_hm_list_*, opti_heatmap_*) that the patterns
                // above never match. Without this the Available Heatmaps table keeps
                // serving pre-deletion Interactions/Sessions numbers from these
                // transients even though every plugin table is already empty.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_ob_hm_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_timeout_ob_hm_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_opti_hm_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_timeout_opti_hm_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_opti_heatmap_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "options WHERE option_name LIKE '_transient_timeout_opti_heatmap_%'" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

                // Rotate the version-namespaced heatmap caches as well, so any key
                // written between the deletes above and this response is orphaned.
                if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
                    opti_behavior_flush_heatmap_caches();
                }

                // Deletion hook (spec P4.1): the wipe above removed heatmap session
                // files wholesale - reset the adaptive-mode total-files estimate and
                // stale-mark any surviving mapping rows so the cron indexer re-derives
                // the daily index from the (now empty) disk state.
                if ( class_exists( 'Opti_Behavior_Heatmap_Storage' )
                    && ( $is_full_deletion || in_array( 'heatmaps', $selected_categories, true ) ) ) {
                    $ob_dz_storage = Opti_Behavior_Heatmap_Storage::get_instance();
                    $ob_dz_storage->set_total_files_estimate( 0 );
                    $ob_dz_storage->mark_all_pages_stale();
                }

                // A full reset empties optibehavior_pages, so the per-page heatmap
                // reset ledger only references page ids that no longer exist.
                if ( $is_full_deletion ) {
                    delete_option( 'opti_behavior_heatmap_page_resets' );
                }

                if ( in_array( 'smart_insights', $selected_categories, true ) ) {
                    $this->reset_smart_insights_deletion_state();
                }

                // Build a completion message that accurately reflects what was deleted
                if ( $is_full_deletion ) {
                    $completion_message = sprintf(
                        /* translators: 1: number of tables, 2: number of files */
                        __( 'Successfully deleted data from %1$d tables and %2$d recording files.', 'opti-behavior' ),
                        $total_tables,
                        $files_deleted
                    );
                } else {
                    $completion_message = sprintf(
                        /* translators: 1: number of tables, 2: number of files */
                        __( 'Successfully deleted selected data from %1$d tables and %2$d files.', 'opti-behavior' ),
                        $total_tables,
                        $files_deleted
                    );
                }

                wp_send_json_success( array(
                    'status'  => 'completed',
                    'message' => $completion_message,
                ) );
            }

            // ---------------------------------------------------------------
            // Regular step: truncate a small batch of tables for this cursor.
            // This keeps progress incremental while avoiding one full
            // WordPress/admin-ajax bootstrap per table.
            // ---------------------------------------------------------------
            $batch_size = (int) apply_filters( 'opti_behavior_delete_all_data_batch_size', 5 );
            $batch_size = max( 1, min( 10, $batch_size ) );

            $start_step       = min( $step, $total_tables );
            $next_step        = min( $start_step + $batch_size, $total_tables );
            $processed_labels = array();

            for ( $table_index = $start_step; $table_index < $next_step; $table_index++ ) {
                $current_table = $tables[ $table_index ];
                $table_name    = $wpdb->prefix . $current_table['name'];

                // Check if table exists before truncating.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

                if ( $table_exists ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                    $wpdb->query( "TRUNCATE TABLE `" . $table_name . "`" );
                    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                }

                $processed_labels[] = $current_table['label'];
            }

            $progress = round( ( $next_step / ( $total_tables + 1 ) ) * 100 );

            wp_send_json_success( array(
                'status'           => 'processing',
                'step'             => $next_step,
                'total'            => $total_tables + 1, // +1 for the file-cleanup step.
                'progress'         => (int) $progress,
                'current_table'    => end( $processed_labels ),
                'processed_tables' => $processed_labels,
                'message'          => sprintf(
                    /* translators: 1: number of processed table steps, 2: total number of table steps */
                    __( 'Deleted %1$d of %2$d table groups...', 'opti-behavior' ),
                    $next_step,
                    $total_tables
                ),
            ) );
        }

        /**
         * Reset Smart Insights operational state after stored insight data is deleted.
         *
         * Notification and scheduler settings are intentionally preserved; only
         * queue/progress state, locks, and the pending batch hook are cleared.
         *
         * @since 1.3.7
         */
        private function reset_smart_insights_deletion_state() {
            $state_option   = 'opti_behavior_smart_insights_scheduler_state';
            $lock_transient = 'opti_behavior_smart_insights_scheduler_lock';
            $batch_hook     = 'opti_behavior_smart_insights_scheduler_batch';

            if ( class_exists( 'Opti_Behavior_Smart_Insights_Scheduler' ) ) {
                $state_option   = Opti_Behavior_Smart_Insights_Scheduler::STATE_OPTION;
                $lock_transient = Opti_Behavior_Smart_Insights_Scheduler::LOCK_TRANSIENT;
                $batch_hook     = Opti_Behavior_Smart_Insights_Scheduler::BATCH_HOOK;
            }

            delete_option( $state_option );
            delete_transient( $lock_transient );

            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( $batch_hook );
            }
        }

        /**
         * AJAX handler for batched deletion of analytics data by date range.
         * Deletes data from tables one by one with date filtering.
         *
         * @since 1.0.8.30
         */
        public function ajax_delete_data_by_range() {
            check_ajax_referer( 'opti_behavior_delete_range_ajax', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            global $wpdb;

            // Get parameters
            $step = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 0;
            $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
            $end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

            // Validate dates
            if ( empty( $start_date ) || empty( $end_date ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid date range', 'opti-behavior' ) ) );
            }

            // Ensure end date includes the full day
            $end_date_full = $end_date . ' 23:59:59';
            $start_date_full = $start_date . ' 00:00:00';
            if ( 0 === $step ) {
                delete_transient( 'opti_behavior_range_delete_count' );
                delete_transient( 'opti_behavior_range_delete_file_count' );
            }

            // Use the same canonical cleanup manifest as Full Reset. Entries with
            // safe date predicates are handled below; missing tables/columns are
            // skipped per-site to support Free-only and mixed Free+Pro installs.
            $tables = $this->get_danger_zone_range_cleanup_manifest();

            $total_tables = count( $tables );
            $total_deleted = 0;

            // Check if we're done
            if ( $step >= $total_tables ) {
                // Get total deleted count from transient
                $total_deleted = get_transient( 'opti_behavior_range_delete_count' );
                delete_transient( 'opti_behavior_range_delete_count' );

                // Delete files within date range from all data directories
                $files_deleted = (int) get_transient( 'opti_behavior_range_delete_file_count' );
                delete_transient( 'opti_behavior_range_delete_file_count' );
                $start_ts = strtotime( $start_date_full );
                $end_ts = strtotime( $end_date_full );

                // Main data directory. Scan only known data directories and
                // root-level heatmap hash folders to preserve guard files such
                // as index.php and .htaccess.
                $upload_dir = wp_upload_dir();
                $data_base = $upload_dir['basedir'] . '/opti-behavior-data';
                foreach ( $this->get_danger_zone_date_range_file_dirs( $data_base ) as $data_dir ) {
                    $files_deleted += $this->delete_danger_zone_files_by_date_range( $data_dir, $start_ts, $end_ts );
                }

                // Legacy recordings directory
                $legacy_dir = WP_CONTENT_DIR . '/opti-behavior-recordings';
                if ( is_dir( $legacy_dir ) ) {
                    $files = glob( $legacy_dir . '/*.json' );
                    if ( $files ) {
                        foreach ( $files as $file ) {
                            if ( is_file( $file ) ) {
                                $file_time = filemtime( $file );
                                if ( $file_time >= $start_ts && $file_time <= $end_ts ) {
                                    wp_delete_file( $file );
                                    if ( ! is_file( $file ) ) {
                                        $files_deleted++;
                                    }
                                }
                            }
                        }
                    }
                }

                // Deletion hook (spec P4.1): the date-range wipe removed heatmap
                // session files from arbitrary hash dirs - page ids unknown, so
                // stale-mark every page; the indexer re-derive corrects the
                // adaptive-mode estimate exactly on its next pass.
                if ( $files_deleted > 0 && class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
                    Opti_Behavior_Heatmap_Storage::get_instance()->note_heatmap_files_deleted( (int) $files_deleted );
                }

                // Stored sizes are now stale — recompute on next Danger Zone load.
                delete_transient( 'optibehavior_danger_zone_sizes' );

                wp_send_json_success( array(
                    'status' => 'completed',
                    'message' => sprintf(
                        /* translators: 1: number of records, 2: number of files, 3: start date, 4: end date */
                        __( 'Successfully deleted %1$d records and %2$d data files between %3$s and %4$s.', 'opti-behavior' ),
                        $total_deleted ? $total_deleted : 0,
                        $files_deleted,
                        $start_date,
                        $end_date
                    )
                ) );
            }

            // Delete from current table
            $current_table = $tables[ $step ];
            $table_name = $wpdb->prefix . $current_table['name'];

            // Check if table and column exist
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
            $table_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            ) );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

            $deleted_rows = 0;
            if ( $table_exists ) {
                if ( isset( $current_table['date_condition'], $current_table['date_columns'] ) && 'overlap' === $current_table['date_condition'] ) {
                    $from_column = $current_table['date_columns']['from'];
                    $to_column   = $current_table['date_columns']['to'];

                    if ( $this->danger_zone_column_exists( $table_name, $from_column ) && $this->danger_zone_column_exists( $table_name, $to_column ) ) {
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are hard-coded in the canonical cleanup manifest and verified above.
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                        $deleted_rows = $wpdb->query( $wpdb->prepare(
                            "DELETE FROM " . $table_name . " WHERE " . $from_column . " <= %s AND " . $to_column . " >= %s",
                            $end_date_full,
                            $start_date_full
                        ) );
                        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    }
                } else {
                    $date_column = $this->resolve_danger_zone_date_column( $current_table, $table_name );

                    if ( '' !== $date_column ) {
                        if ( 'optibehavior_recordings' === $current_table['name'] && $this->danger_zone_column_exists( $table_name, 'file_path' ) ) {
                            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                            $recording_file_paths = $wpdb->get_col( $wpdb->prepare(
                                "SELECT file_path FROM " . $table_name . " WHERE " . $date_column . " BETWEEN %s AND %s AND file_path IS NOT NULL AND file_path != ''",
                                $start_date_full,
                                $end_date_full
                            ) );
                            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                            $recording_files_deleted = $this->delete_danger_zone_recording_files( $recording_file_paths );
                            if ( $recording_files_deleted > 0 ) {
                                $running_file_total = get_transient( 'opti_behavior_range_delete_file_count' );
                                $running_file_total = $running_file_total ? (int) $running_file_total : 0;
                                set_transient( 'opti_behavior_range_delete_file_count', $running_file_total + $recording_files_deleted, 3600 );
                            }
                        }

                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are hard-coded in the canonical cleanup manifest and verified above.
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
                        $deleted_rows = $wpdb->query( $wpdb->prepare(
                            "DELETE FROM " . $table_name . " WHERE " . $date_column . " BETWEEN %s AND %s",
                            $start_date_full,
                            $end_date_full
                        ) );
                        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                    }
                }

                if ( $deleted_rows === false ) {
                    $deleted_rows = 0;
                }
            }

            // Update running total in transient
            $running_total = get_transient( 'opti_behavior_range_delete_count' );
            $running_total = $running_total ? $running_total : 0;
            $running_total += $deleted_rows;
            set_transient( 'opti_behavior_range_delete_count', $running_total, 3600 );

            $progress = round( ( ( $step + 1 ) / ( $total_tables + 1 ) ) * 100 );

            wp_send_json_success( array(
                'status' => 'processing',
                'step' => $step + 1,
                'total' => $total_tables + 1,
                'progress' => (int) $progress,
                'deleted_rows' => $deleted_rows,
                'current_table' => $current_table['label'],
                'message' => sprintf(
                    /* translators: %s: table label */
                    __( 'Deleting %s...', 'opti-behavior' ),
                    $current_table['label']
                )
            ) );
        }

        // =====================================================================
        // Smart Data Cleanup AJAX Handlers
        // =====================================================================

        /**
         * AJAX handler: Preview count of sessions matching conditions.
         *
         * @since 1.0.9
         */
        public function ajax_smart_cleanup_preview() {
            check_ajax_referer( 'opti_behavior_smart_cleanup', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via map_deep and sanitize_cleanup_conditions below.
            $conditions = isset( $_POST['conditions'] ) ? $this->sanitize_cleanup_conditions( map_deep( wp_unslash( $_POST['conditions'] ), 'sanitize_text_field' ) ) : array();

            if ( empty( $conditions ) ) {
                wp_send_json_error( array( 'message' => __( 'No conditions specified.', 'opti-behavior' ) ) );
            }

            $impact = $this->get_smart_cleanup_impact_summary( $conditions );
            $count  = isset( $impact['sessions'] ) ? absint( $impact['sessions'] ) : $this->count_sessions_by_conditions( $conditions );

            wp_send_json_success( array_merge( $impact, array(
                'count'   => $count,
                'message' => sprintf(
                    /* translators: %d: number of sessions */
                    _n( '%d session matches your conditions.', '%d sessions match your conditions.', $count, 'opti-behavior' ),
                    $count
                ),
            ) ) );
        }

        /**
         * AJAX handler: Execute batched deletion of sessions matching conditions.
         *
         * @since 1.0.9
         */
        public function ajax_smart_cleanup_execute() {
            check_ajax_referer( 'opti_behavior_smart_cleanup', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via map_deep and sanitize_cleanup_conditions below.
            $conditions = isset( $_POST['conditions'] ) ? $this->sanitize_cleanup_conditions( map_deep( wp_unslash( $_POST['conditions'] ), 'sanitize_text_field' ) ) : array();
            $step = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 0;
            $options = array(
                'optimize_after_cleanup' => isset( $_POST['optimize_after_cleanup'] ) ? filter_var( wp_unslash( $_POST['optimize_after_cleanup'] ), FILTER_VALIDATE_BOOLEAN ) : true,
            );

            if ( empty( $conditions ) ) {
                wp_send_json_error( array( 'message' => __( 'No conditions specified.', 'opti-behavior' ) ) );
            }

            $result = $this->delete_sessions_by_conditions_impl( $conditions, $step, $options );

            // Stored sizes are now stale — recompute on next Danger Zone load.
            delete_transient( 'optibehavior_danger_zone_sizes' );

            wp_send_json_success( $result );
        }

        /**
         * AJAX handler: Execute batched bot/spam session deletion.
         *
         * @since 1.0.9
         */
        public function ajax_bot_cleanup() {
            check_ajax_referer( 'opti_behavior_smart_cleanup', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            $step = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 0;

            $result = $this->delete_bot_sessions_impl( $step );

            // Stored sizes are now stale — recompute on next Danger Zone load.
            delete_transient( 'optibehavior_danger_zone_sizes' );

            wp_send_json_success( $result );
        }

        /**
         * AJAX handler: Save auto-cleanup schedule settings.
         *
         * @since 1.0.9
         */
        public function ajax_save_auto_cleanup_settings() {
            check_ajax_referer( 'opti_behavior_smart_cleanup', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array values are sanitized individually below.
            $raw_settings = isset( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array();

            $settings = array(
                'enabled'                  => isset( $raw_settings['enabled'] ) ? filter_var( $raw_settings['enabled'], FILTER_VALIDATE_BOOLEAN ) : false,
                'frequency'                => isset( $raw_settings['frequency'] ) ? sanitize_text_field( $raw_settings['frequency'] ) : 'daily',
                'conditions'               => isset( $raw_settings['conditions'] ) ? $this->sanitize_cleanup_conditions( $raw_settings['conditions'] ) : array(),
                'delete_orphaned_visitors' => isset( $raw_settings['delete_orphaned_visitors'] ) ? filter_var( $raw_settings['delete_orphaned_visitors'], FILTER_VALIDATE_BOOLEAN ) : true,
                'max_rows_per_run'         => isset( $raw_settings['max_rows_per_run'] ) ? min( max( 1, absint( $raw_settings['max_rows_per_run'] ) ), 50000 ) : 50000,
                'optimize_after_cleanup'   => isset( $raw_settings['optimize_after_cleanup'] ) ? filter_var( $raw_settings['optimize_after_cleanup'], FILTER_VALIDATE_BOOLEAN ) : false,
                'recalculate_spam_before_cleanup' => isset( $raw_settings['recalculate_spam_before_cleanup'] ) ? filter_var( $raw_settings['recalculate_spam_before_cleanup'], FILTER_VALIDATE_BOOLEAN ) : false,
                'last_run'                 => null,
                'last_result'              => array(),
            );

            // Validate frequency
            if ( ! in_array( $settings['frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ) {
                $settings['frequency'] = 'daily';
            }

            // Preserve last_run from existing settings
            $existing = get_option( 'opti_behavior_auto_cleanup_settings', array() );
            if ( ! empty( $existing['last_run'] ) ) {
                $settings['last_run'] = $existing['last_run'];
            }
            if ( ! empty( $existing['last_result'] ) && is_array( $existing['last_result'] ) ) {
                $settings['last_result'] = $existing['last_result'];
            }

            if ( method_exists( $this, 'get_smart_cleanup_service' ) ) {
                $settings = $this->get_smart_cleanup_service()->normalize_auto_cleanup_settings( $settings );
            }

            update_option( 'opti_behavior_auto_cleanup_settings', $settings );

            // Manage cron schedule
            $hook = 'opti_behavior_scheduled_smart_cleanup';
            wp_clear_scheduled_hook( $hook );

            if ( $settings['enabled'] ) {
                $recurrence = $settings['frequency'];
                if ( $recurrence === 'monthly' ) {
                    // WordPress doesn't have monthly by default, use a custom interval
                    $recurrence = 'daily'; // We'll check inside the callback
                }
                wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $hook );
            }

            // Heatmap sync auto-repair toggle (Danger Zone → Smart Cleanup →
            // Repair & Archive card since round 3; saved immediately on change
            // through this same handler). Stored as its own option — NOT inside the
            // cleanup settings array — so an absent option keeps the opt-out
            // default (enabled) and the repair cron stays independent of the
            // cleanup enabled flag. Only touched when the payload carries the
            // key, so legacy save calls cannot silently flip it off.
            if ( isset( $raw_settings['auto_repair_heatmap_sync'] ) ) {
                $auto_repair_enabled = filter_var( $raw_settings['auto_repair_heatmap_sync'], FILTER_VALIDATE_BOOLEAN );
                update_option( Opti_Behavior_Heatmap_Dashboard::HEATMAP_AUTO_REPAIR_OPTION, $auto_repair_enabled ? '1' : '0', false );

                if ( method_exists( $this, 'ensure_heatmap_auto_repair_cron' ) ) {
                    // Schedules the daily event when enabled, clears it when disabled.
                    $this->ensure_heatmap_auto_repair_cron();
                }
            }

            wp_send_json_success( array(
                'message' => __( 'Auto-cleanup settings saved successfully.', 'opti-behavior' ),
                'settings' => $settings,
            ) );
        }

        /**
         * Sanitize cleanup conditions array.
         *
         * @since 1.0.9
         * @param array|string $raw Raw conditions data.
         * @return array Sanitized conditions.
         */
        private function sanitize_cleanup_conditions( $raw ) {
            if ( is_string( $raw ) ) {
                $raw = json_decode( $raw, true );
            }
            if ( ! is_array( $raw ) ) {
                return array();
            }

            $sanitized = array();

            $int_keys = array(
                'older_than_days', 'inactive_visitor_days',
                'min_duration', 'min_duration_age_days',
                'max_duration', 'max_duration_age_days',
                'min_events', 'min_events_age_days',
                'max_events',
                'min_page_views',
                'bounce_age_days',
                'single_visit_age_days',
            );

            $bool_keys = array(
                'bounce_only', 'single_visit_only', 'delete_orphaned_visitors',
            );

            foreach ( $int_keys as $key ) {
                if ( isset( $raw[ $key ] ) ) {
                    $sanitized[ $key ] = absint( $raw[ $key ] );
                }
            }

            foreach ( $bool_keys as $key ) {
                if ( isset( $raw[ $key ] ) ) {
                    $sanitized[ $key ] = filter_var( $raw[ $key ], FILTER_VALIDATE_BOOLEAN );
                }
            }

            $has_spam_traffic = array_key_exists( 'include_spam_traffic', $raw );
            $has_legacy_bots  = array_key_exists( 'include_bots', $raw );
            if ( $has_spam_traffic || $has_legacy_bots ) {
                $include_spam_traffic = $has_spam_traffic ? filter_var( $raw['include_spam_traffic'], FILTER_VALIDATE_BOOLEAN ) : filter_var( $raw['include_bots'], FILTER_VALIDATE_BOOLEAN );
                $sanitized['include_spam_traffic'] = $include_spam_traffic;
                $sanitized['include_bots']         = $include_spam_traffic;
            }

            return $sanitized;
        }

		/**
		 * AJAX handler: Start Pro free trial.
		 *
		 * Saves trial start timestamp to WP options and attempts to notify the API.
		 * Trial activates locally even if API is unreachable (graceful degradation).
		 *
		 * @since 1.1.3
		 */
		private function ajax_start_pro_trial_impl() {
			// Verify nonce
			check_ajax_referer( 'opti_behavior_start_trial', 'nonce' );

			// Verify capabilities
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'opti-behavior' ) ) );
			}

			// Check if trial already started
			$trial_started = get_option( 'opti_behavior_pro_trial_started', 0 );
			if ( $trial_started > 0 ) {
				$expires   = $trial_started + ( 180 * DAY_IN_SECONDS );
				$days_left = max( 0, (int) ceil( ( $expires - time() ) / DAY_IN_SECONDS ) );
				wp_send_json_success( array(
					'message'      => __( 'Your Pro trial is already active!', 'opti-behavior' ),
					'days_left'    => $days_left,
					'download_url' => $this->get_pro_download_url(),
				) );
			}

			// Activate trial locally
			$now = time();
			update_option( 'opti_behavior_pro_trial_started', $now );
			delete_option( 'opti_behavior_pro_trial_banner_dismissed' );

			// Calculate expiry
			$expires   = $now + ( 180 * DAY_IN_SECONDS );
			$days_left = 180;

			// Attempt to notify the API (non-blocking, informational)
			$this->notify_api_trial_started();

			wp_send_json_success( array(
				'message'      => __( 'Pro trial activated! You have 6 months of free access to all Pro features.', 'opti-behavior' ),
				'days_left'    => $days_left,
				'download_url' => $this->get_pro_download_url(),
			) );
		}

		/**
		 * AJAX handler: Dismiss trial banner.
		 *
		 * Stores a dismiss timestamp. Banner will re-appear after 30 days.
		 *
		 * @since 1.1.3
		 */
		private function ajax_dismiss_trial_banner_impl() {
			check_ajax_referer( 'opti_behavior_dismiss_trial', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'opti-behavior' ) ) );
			}

			update_option( 'opti_behavior_pro_trial_banner_dismissed', time() );
			wp_send_json_success( array( 'message' => 'Banner dismissed' ) );
		}

		/**
		 * Get Pro download URL with access code.
		 *
		 * Mirrors the logic in recordings-upgrade-page.php — requests a one-time
		 * access code from the API so the download page can verify the visitor.
		 *
		 * @since 1.1.3
		 * @return string Download URL.
		 */
		private function get_pro_download_url() {
			$current_user = wp_get_current_user();
			$cache_key    = 'ob_dl_access_' . md5( $current_user->user_login . site_url() );
			$access_code  = get_transient( $cache_key );

			if ( ! $access_code ) {
				$api_response = wp_remote_post(
					'https://api.optiuser.com/request-download-access',
					array(
						'body'    => wp_json_encode( array(
							'site_url' => site_url(),
							'username' => $current_user->user_login,
							'email'    => $current_user->user_email,
						) ),
						'headers' => array( 'Content-Type' => 'application/json' ),
						'timeout' => 10,
					)
				);
				if ( ! is_wp_error( $api_response ) ) {
					$api_body = json_decode( wp_remote_retrieve_body( $api_response ), true );
					if ( ! empty( $api_body['data']['access_code'] ) ) {
						$access_code = $api_body['data']['access_code'];
						set_transient( $cache_key, $access_code, 10 * MINUTE_IN_SECONDS );
					}
				}
			}

			return add_query_arg(
				array(
					'site_url'    => rawurlencode( site_url() ),
					'username'    => rawurlencode( $current_user->user_login ),
					'email'       => rawurlencode( $current_user->user_email ),
					'access_code' => rawurlencode( $access_code ? $access_code : '' ),
				),
				'https://optiuser.com/opti-behavior/ob-download-pro/'
			);
		}


		/**
		 * Get Smart Insights notification settings with safe defaults.
		 *
		 * @since 1.3.5
		 * @return array
		 */
		private function get_smart_insights_notification_settings_impl() {
			$defaults = array(
				'enabled'                 => true,
				'launcher_behavior'       => 'show_badge',
				'priority_threshold'      => 'high',
				'period'                  => 'last7days',
				'include_locked_previews' => false,
			);

			$settings = get_option( 'opti_behavior_smart_insights_notifications', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			$settings = wp_parse_args( $settings, $defaults );

			$settings['enabled']                 = ! empty( $settings['enabled'] );
			$settings['include_locked_previews'] = ! empty( $settings['include_locked_previews'] );

			if ( ! in_array( $settings['launcher_behavior'], array( 'show_badge', 'minimized', 'compact_side', 'opti_pages_only' ), true ) ) {
				$settings['launcher_behavior'] = $defaults['launcher_behavior'];
			}
			if ( ! in_array( $settings['priority_threshold'], array( 'all', 'medium', 'high' ), true ) ) {
				$settings['priority_threshold'] = $defaults['priority_threshold'];
			}
			if ( ! in_array( $settings['period'], array( 'last7days', 'last14days', 'last30days' ), true ) ) {
				$settings['period'] = $defaults['period'];
			}

			return $settings;
		}

		/**
		 * Count newly detected insights the current user has not seen yet.
		 *
		 * Powers the red pulse dot on the "Insights" admin menu item. Uses the
		 * same per-user watermark as the floating launcher
		 * (`opti_behavior_si_notifications_last_seen`), so opening the Smart
		 * Insights center clears both indicators together.
		 *
		 * @since 1.8.6
		 * @return int
		 */
		private function get_smart_insights_menu_unseen_count_impl() {
			static $count = null;

			if ( null !== $count ) {
				return $count;
			}

			$count = 0;

			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				return $count;
			}

			$generator = $this->get_smart_insights_generator_impl();
			if ( ! $generator || ! method_exists( $generator, 'get_repository' ) ) {
				return $count;
			}

			$repository = $generator->get_repository();
			if ( ! $repository || ! method_exists( $repository, 'count_unseen_new_insights' ) ) {
				return $count;
			}

			$last_seen = (int) get_user_meta( get_current_user_id(), 'opti_behavior_si_notifications_last_seen', true );
			$count     = (int) $repository->count_unseen_new_insights( $last_seen );

			/**
			 * Filter the unseen-insight count behind the admin menu pulse dot.
			 *
			 * @since 1.8.6
			 * @param int $count Unseen new insight count for the current user.
			 */
			$count = max( 0, (int) apply_filters( 'opti_behavior_smart_insights_menu_unseen_count', $count ) );

			return $count;
		}

		/**
		 * Mark Smart Insights as seen for the current user.
		 *
		 * Runs on the Smart Insights screen `load-` hook (before the admin menu
		 * renders) so the pulse dot disappears as soon as the user opens the page.
		 *
		 * @since 1.8.6
		 */
		private function mark_smart_insights_menu_seen_impl() {
			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			update_user_meta( get_current_user_id(), 'opti_behavior_si_notifications_last_seen', time() );
		}

		/**
		 * Get current user's Smart Insights notification state.
		 *
		 * @since 1.3.5
		 * @return array
		 */
		private function get_smart_insights_notification_user_state_impl() {
			$user_id             = get_current_user_id();
			$settings            = $this->get_smart_insights_notification_settings_impl();
			$hidden_meta         = (bool) get_user_meta( $user_id, 'opti_behavior_si_notification_hidden', true );
			$minimized_meta      = (bool) get_user_meta( $user_id, 'opti_behavior_si_notification_minimized', true );
			$is_compact_behavior = in_array( $settings['launcher_behavior'], array( 'minimized', 'compact_side' ), true );
			$legacy_state        = 'visible';
			$compact_reason      = '';

			if ( ! $settings['enabled'] ) {
				$state        = 'disabled';
				$legacy_state = 'disabled';
			} elseif ( $hidden_meta ) {
				$state          = 'compact';
				$legacy_state   = 'hidden';
				$compact_reason = 'user_hidden';
			} elseif ( $minimized_meta ) {
				$state          = 'compact';
				$legacy_state   = 'minimized';
				$compact_reason = 'user_minimized';
			} elseif ( $is_compact_behavior ) {
				$state          = 'compact';
				$legacy_state   = 'minimized' === $settings['launcher_behavior'] ? 'minimized' : 'compact';
				$compact_reason = 'site_default';
			} else {
				$state = 'visible';
			}

			return array(
				'state'             => $state,
				'display_state'     => $state,
				'legacy_state'      => $legacy_state,
				'last_seen'         => (int) get_user_meta( $user_id, 'opti_behavior_si_notifications_last_seen', true ),
				'is_compact'        => 'compact' === $state,
				'is_hidden'         => false,
				'is_enabled'        => 'disabled' !== $state,
				'can_restore'       => 'compact' === $state,
				'compact_reason'    => $compact_reason,
				'launcher_behavior' => $settings['launcher_behavior'],
				'meta'              => array(
					'hidden'    => $hidden_meta,
					'minimized' => $minimized_meta,
				),
			);
		}

		/**
		 * Get human state label for settings.
		 *
		 * @since 1.3.5
		 * @param string $state State key.
		 * @return string
		 */
		private function get_smart_insights_notification_state_label_impl( $state ) {
			$labels = array(
				'visible'   => __( 'Visible', 'opti-behavior' ),
				'minimized' => __( 'Minimized', 'opti-behavior' ),
				'compact'   => __( 'Compact side mode', 'opti-behavior' ),
				'hidden'    => __( 'Compact side mode', 'opti-behavior' ),
				'disabled'  => __( 'Disabled by site settings', 'opti-behavior' ),
			);

			return isset( $labels[ $state ] ) ? $labels[ $state ] : $labels['visible'];
		}

		/**
		 * Resolve the Smart Insights launcher query scope.
		 *
		 * On the Smart Insights center, the browser posts the currently selected
		 * period/custom dates and explicit spam include/exclude toggle. Other admin
		 * pages keep using the notification settings period and global spam default.
		 *
		 * @since 1.3.7
		 * @param array $settings Notification settings.
		 * @param array $request  Request source.
		 * @return array
		 */
		private function resolve_smart_insights_notification_scope_impl( $settings, $request = array() ) {
			$request = is_array( $request ) ? $request : array();
			$context = isset( $request['context'] ) ? sanitize_key( wp_unslash( $request['context'] ) ) : 'notification';
			$period  = isset( $request['period'] ) ? sanitize_key( wp_unslash( $request['period'] ) ) : '';

			if ( '' === $period ) {
				$period = isset( $settings['period'] ) ? sanitize_key( $settings['period'] ) : 'last7days';
			}

			if ( ! in_array( $period, array( 'today', 'yesterday', 'last7days', 'last14days', 'last30days', 'custom' ), true ) ) {
				$period = isset( $settings['period'] ) ? sanitize_key( $settings['period'] ) : 'last7days';
			}

			$start_date   = isset( $request['start_date'] ) ? sanitize_text_field( wp_unslash( $request['start_date'] ) ) : '';
			$end_date     = isset( $request['end_date'] ) ? sanitize_text_field( wp_unslash( $request['end_date'] ) ) : '';
			$exclude_spam = array_key_exists( 'exclude_spam', $request )
				? ( '1' === (string) sanitize_text_field( wp_unslash( $request['exclude_spam'] ) ) )
				: $this->get_smart_insights_default_spam_exclusion_impl();

			return array(
				'context'      => $context,
				'period'       => $period,
				'start_date'   => $start_date,
				'end_date'     => $end_date,
				'exclude_spam' => $exclude_spam,
				'spam_scope'   => $this->get_smart_insights_spam_scope_key_impl( $exclude_spam ),
				'status'       => 'active',
				'status__in'   => array(
					Opti_Behavior_Smart_Insights_Repository::STATUS_NEW,
					Opti_Behavior_Smart_Insights_Repository::STATUS_VIEWED,
					Opti_Behavior_Smart_Insights_Repository::STATUS_IN_PROGRESS,
				),
			);
		}

		/**
		 * Build a Smart Insights center URL that opens the same launcher scope.
		 *
		 * @since 1.3.7
		 * @param array $scope Launcher query scope.
		 * @return string
		 */
		private function get_smart_insights_center_url_for_scope_impl( $scope ) {
			$args = array(
				'page'         => 'opti-behavior-smart-insights',
				'period'       => isset( $scope['period'] ) ? sanitize_key( $scope['period'] ) : 'last7days',
				'exclude_spam' => ! empty( $scope['exclude_spam'] ) ? '1' : '0',
			);

			if ( ! empty( $scope['start_date'] ) ) {
				$args['start_date'] = sanitize_text_field( $scope['start_date'] );
			}
			if ( ! empty( $scope['end_date'] ) ) {
				$args['end_date'] = sanitize_text_field( $scope['end_date'] );
			}

			return admin_url( 'admin.php?' . http_build_query( $args, '', '&' ) );
		}

		/**
		 * Check whether the lightweight global launcher should load on this admin screen.
		 *
		 * @since 1.3.5
		 * @param string $hook_suffix Admin hook suffix.
		 * @return bool
		 */
		private function should_load_smart_insights_notifications_impl( $hook_suffix = '' ) {
			if ( ! is_admin() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			$settings = $this->get_smart_insights_notification_settings_impl();
			if ( empty( $settings['enabled'] ) ) {
				return false;
			}

			$user_state = $this->get_smart_insights_notification_user_state_impl();
			if ( 'disabled' === $user_state['state'] ) {
				return false;
			}

			$is_opti_behavior_page = false !== strpos( (string) $hook_suffix, 'opti-behavior-' );
			if ( ! $is_opti_behavior_page && function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( $screen && ! empty( $screen->id ) ) {
					$is_opti_behavior_page = false !== strpos( $screen->id, 'opti-behavior' ) || false !== strpos( $screen->id, 'opti_behavior' );
				}
			}

			if ( 'opti_pages_only' === $settings['launcher_behavior'] && ! $is_opti_behavior_page ) {
				return false;
			}

			return true;
		}

		/**
		 * Build the current admin-page Smart Insights context for the floating launcher.
		 *
		 * The launcher is global, but when it is rendered on the Smart Insights
		 * center it must query the same reporting scope as the center widgets so
		 * the badge/cards do not disagree with the visible Active/High Priority
		 * counts.
		 *
		 * @since 1.3.7
		 * @param string $hook_suffix Current admin hook suffix.
		 * @return array
		 */
		private function get_smart_insights_notification_request_context_impl( $hook_suffix = '' ) {
			$hook_suffix = (string) $hook_suffix;
			if ( false === strpos( $hook_suffix, 'opti-behavior-smart-insights' ) ) {
				return array();
			}

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only launcher scope.
			$period     = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : ( class_exists( 'Opti_Behavior_Smart_Insights_Generator' ) ? Opti_Behavior_Smart_Insights_Generator::DEFAULT_PERIOD : 'last30days' );
			$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : '';
			$end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : '';
			$exclude_spam = $this->resolve_smart_insights_spam_exclusion_from_request_impl( $_GET );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			return array(
				'context'      => 'center',
				'period'       => $period,
				'start_date'   => $start_date,
				'end_date'     => $end_date,
				'exclude_spam' => $exclude_spam ? '1' : '0',
			);
		}

		/**
		 * AJAX: compact Smart Insights notification payload.
		 *
		 * @since 1.3.5
		 */
		private function ajax_smart_insights_notifications_payload_impl() {
			$this->smart_insights_notifications_ajax_check();

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_notifications_ajax_check() above.
			wp_send_json_success( $this->get_smart_insights_notifications_payload_impl( $_POST ) );
		}

		/**
		 * AJAX: update current user's Smart Insights notification state.
		 *
		 * @since 1.3.5
		 */
		private function ajax_smart_insights_notification_state_impl() {
			$this->smart_insights_notifications_ajax_check();

			$user_id = get_current_user_id();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_notifications_ajax_check() above.
			$action  = isset( $_POST['state_action'] ) ? sanitize_key( wp_unslash( $_POST['state_action'] ) ) : '';

			switch ( $action ) {
				case 'compact':
				case 'minimize':
					update_user_meta( $user_id, 'opti_behavior_si_notification_minimized', 1 );
					delete_user_meta( $user_id, 'opti_behavior_si_notification_hidden' );
					break;
				case 'hide':
				case 'hide_to_side':
					update_user_meta( $user_id, 'opti_behavior_si_notification_hidden', 1 );
					delete_user_meta( $user_id, 'opti_behavior_si_notification_minimized' );
					break;
				case 'show':
				case 'restore':
					delete_user_meta( $user_id, 'opti_behavior_si_notification_hidden' );
					delete_user_meta( $user_id, 'opti_behavior_si_notification_minimized' );
					break;
				case 'mark_seen':
					update_user_meta( $user_id, 'opti_behavior_si_notifications_last_seen', time() );
					break;
				default:
					wp_send_json_error( array( 'message' => __( 'Unknown notification state action.', 'opti-behavior' ) ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_notifications_ajax_check() above.
			wp_send_json_success( $this->get_smart_insights_notifications_payload_impl( $_POST ) );
		}

		/**
		 * Build compact notification payload.
		 *
		 * @since 1.3.5
		 * @return array
		 */
		private function get_smart_insights_notifications_payload_impl( $request = array() ) {
			$settings   = $this->get_smart_insights_notification_settings_impl();
			$user_state = $this->get_smart_insights_notification_user_state_impl();
			$scope      = $this->resolve_smart_insights_notification_scope_impl( $settings, $request );

			$payload = array(
				'enabled'          => ! empty( $settings['enabled'] ),
				'state'            => $user_state['state'],
				'display_state'    => $user_state['display_state'],
				'legacy_state'     => $user_state['legacy_state'],
				'is_compact'       => ! empty( $user_state['is_compact'] ),
				'is_hidden'        => ! empty( $user_state['is_hidden'] ),
				'is_enabled'       => ! empty( $user_state['is_enabled'] ),
				'can_restore'      => ! empty( $user_state['can_restore'] ),
				'compact_reason'   => $user_state['compact_reason'],
				'launcher_behavior' => $settings['launcher_behavior'],
				'unread_count'     => 0,
				'highest_severity' => '',
				'items'            => array(),
				'center_url'       => $this->get_smart_insights_center_url_for_scope_impl( $scope ),
				'settings_url'     => admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=smart-insights' ),
				'include_locked'   => ! empty( $settings['include_locked_previews'] ),
				'scope'            => $scope,
				'actions'          => array(
					'compact'   => 'compact',
					'restore'   => 'restore',
					'mark_seen' => 'mark_seen',
				),
				'compact'          => array(
					'enabled'        => ! empty( $user_state['is_compact'] ),
					'restore_action' => 'restore',
					'compact_action' => 'compact',
					'label'          => __( 'Restore Smart Insights launcher', 'opti-behavior' ),
					'aria_label'     => __( 'Restore Smart Insights notifications from compact side mode', 'opti-behavior' ),
				),
				'scope_note'       => __( 'Notification badge counts use the same active status, period, and spam scope as the visible Smart Insights center when that page is open.', 'opti-behavior' ),
			);

			if ( empty( $settings['enabled'] ) || ! current_user_can( 'manage_options' ) ) {
				$payload['enabled']       = false;
				$payload['state']         = 'disabled';
				$payload['display_state'] = 'disabled';
				$payload['is_enabled']    = false;
				$payload['is_compact']    = false;
				$payload['can_restore']   = false;
				$payload['compact']['enabled'] = false;
				return $payload;
			}

			$generator = $this->get_smart_insights_generator_impl();
			if ( ! $generator ) {
				return $payload;
			}

			$period = $scope['period'];
			$range  = $generator->get_date_range_for_period( $period, $scope['start_date'], $scope['end_date'] );
			if ( is_wp_error( $range ) ) {
				return $payload;
			}
			$scope['date_range'] = $range;
			$payload['scope']    = $scope;

			$repository = $generator->get_repository();
			$date_query = 'center' === $scope['context']
				? array(
					'date_from' => $range['from'],
					'date_to'   => $range['to'],
				)
				: array(
					'date_overlap_from' => $range['from'],
					'date_overlap_to'   => $range['to'],
				);
			$items      = $repository->get_insights(
				array_merge(
					$date_query,
					array(
						'limit'      => 50,
						'spam_scope' => $scope['spam_scope'],
						'orderby'    => 'last_seen_at',
						'order'      => 'DESC',
						'status__in' => array(
							Opti_Behavior_Smart_Insights_Repository::STATUS_NEW,
							Opti_Behavior_Smart_Insights_Repository::STATUS_VIEWED,
							Opti_Behavior_Smart_Insights_Repository::STATUS_IN_PROGRESS,
						),
					)
				)
			);

			if ( method_exists( $repository, 'collapse_open_insights_by_group' ) ) {
				$items = $repository->collapse_open_insights_by_group( $items );
			}

			$items = $this->get_smart_insights_capabilities_impl()->filter_insights_for_viewer( $items, 'notification' );
			$items = array_map( array( $this, 'sanitize_smart_insights_entity_links_impl' ), is_array( $items ) ? $items : array() );
			$items = array_map( array( $this, 'localize_smart_insights_insight_impl' ), $items );
			$items = $this->filter_smart_insights_notifications_by_settings_impl( $items, $settings );
			$items = $this->sort_smart_insights_notifications_impl( $items );

			$last_seen       = (int) $user_state['last_seen'];
			$unread_count    = 0;
			$highest_weight  = 0;
			$highest_label   = '';

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || ! empty( $item['is_locked_preview'] ) ) {
					continue;
				}

				$item_timestamp = $this->get_smart_insights_notification_timestamp_impl( $item );
				if ( 0 === $last_seen || $item_timestamp > $last_seen ) {
					$unread_count++;
					$severity_label = $this->get_smart_insights_notification_severity_impl( $item );
					$weight         = $this->get_smart_insights_notification_severity_weight_impl( $severity_label );
					if ( $weight > $highest_weight ) {
						$highest_weight = $weight;
						$highest_label  = strtolower( $severity_label );
					}
				}
			}

			$payload['unread_count']     = $unread_count;
			$payload['highest_severity'] = $highest_label;
			$center_url                  = $payload['center_url'];
			$payload['items']            = array_map(
				function( $item ) use ( $center_url ) {
					return $this->format_smart_insights_notification_item_impl( $item, $center_url );
				},
				array_slice( $items, 0, 5 )
			);

			if ( ! empty( $settings['include_locked_previews'] ) ) {
				$payload['locked_preview'] = array(
					'title'      => __( 'Pro can monitor recordings, forms, errors, and journeys.', 'opti-behavior' ),
					'detail_url' => $payload['center_url'],
				);
			}

			return $payload;
		}

		/**
		 * Filter notification items by threshold and locked preview policy.
		 *
		 * @since 1.3.5
		 * @param array $items    Insights.
		 * @param array $settings Settings.
		 * @return array
		 */
		private function filter_smart_insights_notifications_by_settings_impl( $items, $settings ) {
			$threshold = isset( $settings['priority_threshold'] ) ? sanitize_key( $settings['priority_threshold'] ) : 'high';
			$minimum   = 'all' === $threshold ? 1 : ( 'medium' === $threshold ? 2 : 3 );

			return array_values(
				array_filter(
					is_array( $items ) ? $items : array(),
					function( $item ) use ( $settings, $minimum ) {
						if ( ! is_array( $item ) ) {
							return false;
						}

						if ( ! empty( $item['is_locked_preview'] ) && empty( $settings['include_locked_previews'] ) ) {
							return false;
						}

						return $this->get_smart_insights_notification_severity_weight_impl( $this->get_smart_insights_notification_severity_impl( $item ) ) >= $minimum;
					}
				)
			);
		}

		/**
		 * Sort notifications by severity, priority, then recency.
		 *
		 * @since 1.3.5
		 * @param array $items Insights.
		 * @return array
		 */
		private function sort_smart_insights_notifications_impl( $items ) {
			usort(
				$items,
				function( $a, $b ) {
					$severity_delta = $this->get_smart_insights_notification_severity_weight_impl( $this->get_smart_insights_notification_severity_impl( $b ) ) - $this->get_smart_insights_notification_severity_weight_impl( $this->get_smart_insights_notification_severity_impl( $a ) );
					if ( 0 !== $severity_delta ) {
						return $severity_delta;
					}

					$score_a = isset( $a['scores']['priority_score'] ) ? (int) $a['scores']['priority_score'] : 0;
					$score_b = isset( $b['scores']['priority_score'] ) ? (int) $b['scores']['priority_score'] : 0;
					if ( $score_a !== $score_b ) {
						return $score_b - $score_a;
					}

					return $this->get_smart_insights_notification_timestamp_impl( $b ) - $this->get_smart_insights_notification_timestamp_impl( $a );
				}
			);

			return $items;
		}

		/**
		 * Format a compact notification item.
		 *
		 * @since 1.3.5
		 * @param array $item Insight row.
		 * @return array
		 */
		private function format_smart_insights_notification_item_impl( $item, $center_url = '' ) {
			$id       = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$metric   = $this->get_smart_insights_notification_metric_impl( $item );
			$severity = $this->get_smart_insights_notification_severity_impl( $item );
			$status   = sanitize_key( $item['status'] ?? '' );
			$detail_url = $center_url ? add_query_arg( 'insight_id', $id, $center_url ) : admin_url( 'admin.php?page=opti-behavior-smart-insights&insight_id=' . $id );
			$detected  = $this->get_smart_insights_notification_detected_labels_impl( $item );
			$category  = sanitize_text_field( $item['category'] ?? '' );
			$priority  = isset( $item['scores']['priority_score'] ) ? (int) $item['scores']['priority_score'] : 0;
			$priority  = max( 0, min( 100, $priority ) );

			// A locked Pro preview arrives here with an emptied metrics array, so
			// the enriched comparison block resolves to nothing on its own. The
			// confidence chip is suppressed explicitly because `scores` survives
			// the lock and must not print a Pro measurement to a Free viewer.
			$is_locked = ! empty( $item['is_locked_preview'] );
			$primary   = $is_locked ? null : $this->get_smart_insights_notification_primary_metric_impl( $item );
			$secondary = $is_locked ? null : $this->get_smart_insights_notification_secondary_metric_impl( $item, is_array( $primary ) ? $primary['key'] : '' );
			$confidence = $is_locked ? array(
				'label' => '',
				'score' => 0,
			) : $this->get_smart_insights_notification_confidence_impl( $item );

			return array(
				'id'                    => $id,
				'title'                 => sanitize_text_field( $item['signal_name'] ?? __( 'Smart Insight', 'opti-behavior' ) ),
				'severity'              => sanitize_key( $severity ),
				'severity_label'        => $this->get_smart_insights_notification_severity_label_impl( $severity ),
				'priority_score'        => $priority,
				'priority_score_label'  => $priority > 0 ? sprintf( '%s/100', number_format_i18n( $priority ) ) : '',
				'priority_score_aria'   => $priority > 0
					? sprintf(
						/* translators: %s: priority score between 0 and 100. */
						__( 'Priority score: %s out of 100', 'opti-behavior' ),
						number_format_i18n( $priority )
					)
					: '',
				'status'                => $status,
				'status_label'          => $this->get_smart_insights_status_label_impl( $status ),
				'entity_label'          => sanitize_text_field( $item['entity_label'] ?? $item['entity_id'] ?? __( 'Site-wide', 'opti-behavior' ) ),
				'category'              => $category,
				'category_label'        => '' !== $category ? sanitize_text_field( $this->get_smart_insights_category_label_impl( $category ) ) : '',
				'confidence_label'      => $confidence['label'],
				'confidence_score'      => $confidence['score'],
				'primary_metric'        => $primary,
				'secondary_metric'      => $secondary,
				'metric_label'          => $metric['label'],
				'metric_value'          => $metric['value'],
				'detected_at'           => sanitize_text_field( $item['last_seen_at'] ?? $item['updated_at'] ?? $item['created_at'] ?? $item['date_to'] ?? '' ),
				'detected_at_human'     => $detected['human'],
				'detected_at_formatted' => $detected['formatted'],
				'detail_url'            => $detail_url,
				'is_locked_preview'     => $is_locked,
			);
		}

		/**
		 * Build the compared primary metric block for one notification card.
		 *
		 * The card headline must answer "how bad, compared to what" instead of
		 * printing a raw volume number. The metric is picked from the signal's own
		 * vocabulary (a bounce signal is judged on bounce rate), then compared to
		 * the site average when the insight stored one, and to the previous period
		 * when it did not. Volume-only signals (traffic spikes) legitimately end up
		 * on `sessions` with a previous-period baseline.
		 *
		 * @since 1.4.3
		 * @param array $item Insight row.
		 * @return array|null
		 */
		private function get_smart_insights_notification_primary_metric_impl( $item ) {
			$metrics = isset( $item['metrics'] ) && is_array( $item['metrics'] ) ? $item['metrics'] : array();
			if ( empty( $metrics ) ) {
				return null;
			}

			$candidates = $this->get_smart_insights_notification_metric_candidates_impl(
				isset( $item['signal_id'] ) ? sanitize_key( $item['signal_id'] ) : ''
			);

			$fallback = null;

			foreach ( $candidates as $key ) {
				$value = $this->get_smart_insights_notification_metric_value_impl( $metrics, $key );
				if ( ! is_numeric( $value ) ) {
					continue;
				}

				$value    = (float) $value;
				$baseline = $this->resolve_smart_insights_notification_baseline_impl( $metrics, $key );

				if ( null === $baseline ) {
					if ( null === $fallback ) {
						$fallback = $this->shape_smart_insights_notification_metric_block_impl( $key, $value, null );
					}
					continue;
				}

				return $this->shape_smart_insights_notification_metric_block_impl( $key, $value, $baseline );
			}

			return $fallback;
		}

		/**
		 * Assemble one formatted primary-metric block.
		 *
		 * @since 1.4.3
		 * @param string     $key      Metric key.
		 * @param float      $value    Current value.
		 * @param array|null $baseline Baseline descriptor: value + source label.
		 * @return array
		 */
		private function shape_smart_insights_notification_metric_block_impl( $key, $value, $baseline ) {
			$meta  = $this->get_smart_insights_notification_metric_meta_impl( $key );
			$block = array(
				'key'            => $key,
				'label'          => $this->get_smart_insights_notification_metric_label_impl( $key ),
				'value'          => $this->format_smart_insights_notification_metric_unit_impl( $value, $meta['unit'] ),
				'baseline'       => '',
				'baseline_label' => '',
				'comparison'     => '',
				'delta'          => '',
				'direction'      => 'flat',
				'sentiment'      => 'neutral',
				'hint'           => '',
			);

			if ( ! is_array( $baseline ) || ! is_numeric( $baseline['value'] ) ) {
				return $block;
			}

			$baseline_value          = (float) $baseline['value'];
			$block['baseline']       = $this->format_smart_insights_notification_metric_unit_impl( $baseline_value, $meta['unit'] );
			$block['baseline_label'] = $baseline['label'];
			$block['comparison']     = sprintf(
				/* translators: 1: baseline metric value, 2: baseline source, e.g. "site average". */
				__( 'vs %1$s %2$s', 'opti-behavior' ),
				$block['baseline'],
				$baseline['label']
			);

			$difference = $value - $baseline_value;
			$relative   = 0.0 !== $baseline_value ? ( $difference / abs( $baseline_value ) ) * 100 : null;

			// "Flat" is a real answer: a 0.2-point drift is noise and colouring it
			// red would make every card look like an emergency.
			$is_flat = 'percent' === $meta['unit']
				? abs( $difference ) < 0.5
				: ( null === $relative || abs( $relative ) < 1 );

			if ( $is_flat ) {
				return $block;
			}

			$block['direction'] = $difference > 0 ? 'up' : 'down';

			if ( 'percent' === $meta['unit'] ) {
				$block['delta'] = sprintf(
					/* translators: %s: signed difference in percentage points. */
					__( '%s pts', 'opti-behavior' ),
					$this->format_smart_insights_notification_signed_impl( $difference, 1 )
				);
			} elseif ( null !== $relative ) {
				$block['delta'] = $this->format_smart_insights_notification_signed_impl( $relative, 0 ) . '%';
			} else {
				$block['delta'] = $this->format_smart_insights_notification_signed_impl( $difference, 0 );
			}

			if ( null !== $meta['higher_is_worse'] ) {
				$is_worse           = $meta['higher_is_worse'] ? $difference > 0 : $difference < 0;
				$block['sentiment'] = $is_worse ? 'worse' : 'better';
				$block['hint']      = $is_worse
					? __( 'Worse than the comparison baseline', 'opti-behavior' )
					: __( 'Better than the comparison baseline', 'opti-behavior' );
			}

			return $block;
		}

		/**
		 * Ordered metric keys to try for one signal, most specific first.
		 *
		 * @since 1.4.3
		 * @param string $signal_id Signal id.
		 * @return array
		 */
		private function get_smart_insights_notification_metric_candidates_impl( $signal_id ) {
			$keywords = array(
				'bounce'     => array( 'bounce_rate' ),
				'scroll'     => array( 'avg_scroll_depth' ),
				'exit'       => array( 'exit_rate' ),
				'conversion' => array( 'conversion_rate', 'cta_click_rate' ),
				'cta'        => array( 'cta_click_rate' ),
				'click'      => array( 'cta_click_rate', 'dead_click_rate' ),
				'dropoff'    => array( 'dropoff_rate', 'completion_rate' ),
				'funnel'     => array( 'dropoff_rate', 'completion_rate' ),
				'checkout'   => array( 'dropoff_rate', 'completion_rate' ),
				'cart'       => array( 'dropoff_rate', 'completion_rate' ),
				'abandon'    => array( 'abandonment_rate', 'form_error_rate' ),
				'form'       => array( 'form_error_rate', 'abandonment_rate' ),
				'field'      => array( 'field_error_rate', 'form_error_rate' ),
				'error'      => array( 'error_rate', 'form_error_rate' ),
				'engagement' => array( 'avg_time_on_page', 'avg_scroll_depth' ),
				'decay'      => array( 'avg_time_on_page' ),
				'confusion'  => array( 'avg_time_on_page' ),
				'traffic'    => array( 'sessions' ),
				'spike'      => array( 'sessions' ),
				'recording'  => array( 'sessions' ),
			);

			$candidates = array();
			foreach ( $keywords as $needle => $keys ) {
				if ( '' !== $signal_id && false !== strpos( $signal_id, $needle ) ) {
					$candidates = array_merge( $candidates, $keys );
				}
			}

			// Universal tail so a signal whose own metric was not tracked still gets
			// an honest comparison instead of an empty card.
			$candidates = array_merge(
				$candidates,
				array( 'bounce_rate', 'exit_rate', 'conversion_rate', 'cta_click_rate', 'avg_scroll_depth', 'avg_time_on_page', 'sessions' )
			);

			return array_values( array_unique( $candidates ) );
		}

		/**
		 * Resolve the comparison baseline for one metric key.
		 *
		 * @since 1.4.3
		 * @param array  $metrics Metrics.
		 * @param string $key     Metric key.
		 * @return array|null { value: float, label: string }
		 */
		private function resolve_smart_insights_notification_baseline_impl( $metrics, $key ) {
			$site_key  = 'site_avg_' . preg_replace( '/^avg_/', '', $key );
			$baselines = isset( $metrics['baseline'] ) && is_array( $metrics['baseline'] ) ? $metrics['baseline'] : array();

			foreach ( array( 'comparison_' . $key, $site_key ) as $lookup ) {
				$value = $this->get_smart_insights_notification_metric_value_impl( $metrics, $lookup );
				if ( null === $value && isset( $baselines[ $lookup ] ) ) {
					$value = $baselines[ $lookup ];
				}

				if ( is_numeric( $value ) ) {
					return array(
						'value' => (float) $value,
						'label' => __( 'site average', 'opti-behavior' ),
					);
				}
			}

			$previous = null;
			if ( isset( $metrics['trend'][ $key ]['previous'] ) && is_numeric( $metrics['trend'][ $key ]['previous'] ) ) {
				$previous = $metrics['trend'][ $key ]['previous'];
			} elseif ( isset( $metrics['previous_period_metrics'][ $key ] ) && is_numeric( $metrics['previous_period_metrics'][ $key ] ) ) {
				$previous = $metrics['previous_period_metrics'][ $key ];
			}

			if ( null !== $previous ) {
				return array(
					'value' => (float) $previous,
					'label' => __( 'previous period', 'opti-behavior' ),
				);
			}

			return null;
		}

		/**
		 * Unit and polarity metadata for a notification metric.
		 *
		 * @since 1.4.3
		 * @param string $key Metric key.
		 * @return array { unit: string, higher_is_worse: bool|null }
		 */
		private function get_smart_insights_notification_metric_meta_impl( $key ) {
			$worse_when_higher = array( 'bounce_rate', 'exit_rate', 'dropoff_rate', 'abandonment_rate', 'error_rate', 'form_error_rate', 'field_error_rate', 'worst_field_error_rate', 'dead_click_rate' );
			$worse_when_lower  = array( 'conversion_rate', 'cta_click_rate', 'avg_scroll_depth', 'avg_time_on_page', 'avg_session_duration', 'completion_rate', 'recording_watch_rate' );

			if ( in_array( $key, $worse_when_higher, true ) ) {
				$polarity = true;
			} elseif ( in_array( $key, $worse_when_lower, true ) ) {
				$polarity = false;
			} else {
				$polarity = null;
			}

			if ( false !== strpos( $key, 'time' ) || false !== strpos( $key, 'duration' ) ) {
				$unit = 'seconds';
			} elseif ( false !== strpos( $key, 'rate' ) || false !== strpos( $key, 'depth' ) || false !== strpos( $key, 'percent' ) ) {
				$unit = 'percent';
			} else {
				$unit = 'count';
			}

			return array(
				'unit'            => $unit,
				'higher_is_worse' => $polarity,
			);
		}

		/**
		 * Format a metric value for its unit.
		 *
		 * @since 1.4.3
		 * @param float  $value Value.
		 * @param string $unit  percent|seconds|count.
		 * @return string
		 */
		private function format_smart_insights_notification_metric_unit_impl( $value, $unit ) {
			$value = (float) $value;

			if ( 'percent' === $unit ) {
				return number_format_i18n( round( $value, 1 ), 1 ) . '%';
			}

			if ( 'seconds' === $unit ) {
				return sprintf(
					/* translators: %s: number of seconds. */
					__( '%ss', 'opti-behavior' ),
					number_format_i18n( round( $value, 1 ), 1 )
				);
			}

			return number_format_i18n( $value, $value === floor( $value ) ? 0 : 1 );
		}

		/**
		 * Format a signed number with a localized magnitude.
		 *
		 * @since 1.4.3
		 * @param float $value    Value.
		 * @param int   $decimals Decimals.
		 * @return string
		 */
		private function format_smart_insights_notification_signed_impl( $value, $decimals ) {
			$rounded = round( (float) $value, $decimals );
			$sign    = $rounded > 0 ? '+' : ( $rounded < 0 ? "\xE2\x88\x92" : '' );

			return $sign . number_format_i18n( abs( $rounded ), $decimals );
		}

		/**
		 * Secondary volume metric shown next to the compared headline.
		 *
		 * @since 1.4.3
		 * @param array  $item        Insight row.
		 * @param string $primary_key Key already used by the primary block.
		 * @return array|null
		 */
		private function get_smart_insights_notification_secondary_metric_impl( $item, $primary_key ) {
			$metrics = isset( $item['metrics'] ) && is_array( $item['metrics'] ) ? $item['metrics'] : array();
			if ( empty( $metrics ) ) {
				return null;
			}

			foreach ( array( 'sessions', 'page_sessions', 'entries', 'pageviews' ) as $key ) {
				if ( $key === $primary_key ) {
					continue;
				}

				$value = $this->get_smart_insights_notification_metric_value_impl( $metrics, $key );
				if ( ! is_numeric( $value ) ) {
					continue;
				}

				return array(
					'label' => $this->get_smart_insights_notification_metric_label_impl( $key ),
					'value' => $this->format_smart_insights_notification_metric_unit_impl( (float) $value, 'count' ),
				);
			}

			return null;
		}

		/**
		 * Localized confidence chip for one notification card.
		 *
		 * Stored labels are inconsistently cased across rule versions, so the label
		 * is re-derived from the score whenever a score exists.
		 *
		 * @since 1.4.3
		 * @param array $item Insight row.
		 * @return array { label: string, score: int }
		 */
		private function get_smart_insights_notification_confidence_impl( $item ) {
			$score = isset( $item['scores']['confidence_score'] ) ? (int) round( (float) $item['scores']['confidence_score'] ) : 0;
			$score = max( 0, min( 100, $score ) );
			$label = isset( $item['scores']['confidence_label'] ) ? strtolower( sanitize_key( $item['scores']['confidence_label'] ) ) : '';

			if ( $score > 0 ) {
				$label = $score >= 70 ? 'high' : ( $score >= 40 ? 'medium' : 'low' );
			}

			$labels = array(
				'high'   => __( 'High', 'opti-behavior' ),
				'medium' => __( 'Medium', 'opti-behavior' ),
				'low'    => __( 'Low', 'opti-behavior' ),
			);

			return array(
				'label' => isset( $labels[ $label ] ) ? $labels[ $label ] : '',
				'score' => $score,
			);
		}

		/**
		 * Build human-readable detection time labels for a notification item.
		 *
		 * Stored datetimes are site-local strings, so the relative diff is
		 * computed against the site-local "now" to avoid timezone drift.
		 *
		 * @since 1.4.2
		 * @param array $item Insight row.
		 * @return array { human: string, formatted: string }
		 */
		private function get_smart_insights_notification_detected_labels_impl( $item ) {
			$timestamp = $this->get_smart_insights_notification_timestamp_impl( $item );
			if ( ! $timestamp ) {
				return array(
					'human'     => '',
					'formatted' => '',
				);
			}

			$now = (int) current_time( 'timestamp' );

			if ( $timestamp > $now - MINUTE_IN_SECONDS ) {
				$human = __( 'Just now', 'opti-behavior' );
			} else {
				/* translators: %s: human-readable time difference, e.g. "2 hours". */
				$human = sprintf( __( '%s ago', 'opti-behavior' ), human_time_diff( $timestamp, $now ) );
			}

			return array(
				'human'     => sanitize_text_field( $human ),
				'formatted' => sanitize_text_field( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ),
			);
		}

		/**
		 * Get primary metric summary for a notification item.
		 *
		 * @since 1.3.5
		 * @param array $item Insight row.
		 * @return array
		 */
		private function get_smart_insights_notification_metric_impl( $item ) {
			$metrics = isset( $item['metrics'] ) && is_array( $item['metrics'] ) ? $item['metrics'] : array();
			$primary = isset( $metrics['primary_evidence'] ) && is_array( $metrics['primary_evidence'] ) ? $metrics['primary_evidence'] : array();
			$keys    = isset( $primary['metric_keys'] ) && is_array( $primary['metric_keys'] ) ? $primary['metric_keys'] : array();

			foreach ( $keys as $key ) {
				$value = $this->get_smart_insights_notification_metric_value_impl( $metrics, $key );
				if ( null !== $value && '' !== $value ) {
					return array(
						'label' => $this->get_smart_insights_notification_metric_label_impl( $key, $primary['label'] ?? '' ),
						'value' => sanitize_text_field( $this->format_smart_insights_notification_metric_value_impl( $value, $key ) ),
					);
				}
			}

			$fallback_keys = array( 'page_sessions', 'sessions', 'page_bounce_rate', 'bounce_rate', 'page_exit_rate', 'exit_rate', 'page_avg_scroll_depth', 'avg_scroll_depth' );
			foreach ( $fallback_keys as $key ) {
				$value = $this->get_smart_insights_notification_metric_value_impl( $metrics, $key );
				if ( null !== $value && '' !== $value ) {
					return array(
						'label' => $this->get_smart_insights_notification_metric_label_impl( $key ),
						'value' => sanitize_text_field( $this->format_smart_insights_notification_metric_value_impl( $value, $key ) ),
					);
				}
			}

			return array(
				'label' => __( 'Signal', 'opti-behavior' ),
				'value' => __( 'Review recommended', 'opti-behavior' ),
			);
		}

		/**
		 * Read metric value from nested Smart Insights metrics.
		 *
		 * @since 1.3.5
		 * @param array  $metrics Metrics.
		 * @param string $key     Metric key.
		 * @return mixed|null
		 */
		private function get_smart_insights_notification_metric_value_impl( $metrics, $key ) {
			if ( isset( $metrics[ $key ] ) ) {
				return $metrics[ $key ];
			}

			if ( isset( $metrics['signal_metrics'][ $key ] ) ) {
				return $metrics['signal_metrics'][ $key ];
			}

			return null;
		}

		/**
		 * Format compact metric values.
		 *
		 * @since 1.3.5
		 * @param mixed  $value Value.
		 * @param string $key   Metric key.
		 * @return string
		 */
		private function format_smart_insights_notification_metric_value_impl( $value, $key ) {
			if ( is_numeric( $value ) ) {
				$number = (float) $value;
				if ( false !== strpos( $key, 'rate' ) || false !== strpos( $key, 'depth' ) || false !== strpos( $key, 'percent' ) ) {
					return round( $number, 1 ) . '%';
				}
				return number_format_i18n( $number, $number === floor( $number ) ? 0 : 1 );
			}

			return wp_strip_all_tags( (string) $value );
		}

		/**
		 * Get a localized notification metric label.
		 *
		 * @since 1.4.1
		 * @param string $key      Metric key.
		 * @param string $fallback Optional stored label.
		 * @return string
		 */
		private function get_smart_insights_notification_metric_label_impl( $key, $fallback = '' ) {
			$labels = array(
				'page_sessions'          => __( 'Sessions', 'opti-behavior' ),
				'sessions'               => __( 'Sessions', 'opti-behavior' ),
				'pageviews'              => __( 'Pageviews', 'opti-behavior' ),
				'page_bounce_rate'       => __( 'Bounce rate', 'opti-behavior' ),
				'bounce_rate'            => __( 'Bounce rate', 'opti-behavior' ),
				'page_exit_rate'         => __( 'Exit rate', 'opti-behavior' ),
				'exit_rate'              => __( 'Exit rate', 'opti-behavior' ),
				'page_avg_scroll_depth'  => __( 'Avg. scroll depth', 'opti-behavior' ),
				'avg_scroll_depth'       => __( 'Avg. scroll depth', 'opti-behavior' ),
				'avg_time_on_page'       => __( 'Avg. time on page', 'opti-behavior' ),
				'page_avg_time_on_page'  => __( 'Avg. time on page', 'opti-behavior' ),
				'mobile_sessions'        => __( 'Mobile sessions', 'opti-behavior' ),
				'mobile_bounce_rate'     => __( 'Mobile bounce rate', 'opti-behavior' ),
			);

			if ( isset( $labels[ $key ] ) ) {
				return $labels[ $key ];
			}

			return '' !== $fallback ? sanitize_text_field( $fallback ) : sanitize_text_field( ucwords( str_replace( '_', ' ', $key ) ) );
		}

		/**
		 * Get notification severity label.
		 *
		 * @since 1.3.5
		 * @param array $item Insight row.
		 * @return string
		 */
		private function get_smart_insights_notification_severity_impl( $item ) {
			return sanitize_text_field( $item['scores']['priority_label'] ?? 'Low' );
		}

		/**
		 * Localize notification severity labels while keeping the internal key stable.
		 *
		 * @since 1.4.1
		 * @param string $severity Severity key or stored label.
		 * @return string
		 */
		private function get_smart_insights_notification_severity_label_impl( $severity ) {
			$key = strtolower( sanitize_key( $severity ) );
			$labels = array(
				'critical' => __( 'Critical', 'opti-behavior' ),
				'high'     => __( 'High', 'opti-behavior' ),
				'medium'   => __( 'Medium', 'opti-behavior' ),
				'low'      => __( 'Low', 'opti-behavior' ),
			);

			return isset( $labels[ $key ] ) ? $labels[ $key ] : sanitize_text_field( $severity );
		}

		/**
		 * Localize Smart Insights status keys for compact notification cards.
		 *
		 * @since 1.4.1
		 * @param string $status Status key.
		 * @return string
		 */
		private function get_smart_insights_status_label_impl( $status ) {
			$labels = array(
				'new'           => __( 'New', 'opti-behavior' ),
				'viewed'        => __( 'Reviewed', 'opti-behavior' ),
				'in_progress'   => __( 'In progress', 'opti-behavior' ),
				'resolved'      => __( 'Resolved', 'opti-behavior' ),
				'ignored'       => __( 'Ignored', 'opti-behavior' ),
				'auto_resolved' => __( 'Auto-resolved', 'opti-behavior' ),
			);

			return isset( $labels[ $status ] ) ? $labels[ $status ] : sanitize_text_field( $status );
		}

		/**
		 * Get severity weight.
		 *
		 * @since 1.3.5
		 * @param string $severity Severity.
		 * @return int
		 */
		private function get_smart_insights_notification_severity_weight_impl( $severity ) {
			$severity = strtolower( sanitize_key( $severity ) );
			if ( 'critical' === $severity ) {
				return 4;
			}
			if ( 'high' === $severity ) {
				return 3;
			}
			if ( 'medium' === $severity ) {
				return 2;
			}
			return 1;
		}

		/**
		 * Get timestamp for unread comparisons.
		 *
		 * @since 1.3.5
		 * @param array $item Insight row.
		 * @return int
		 */
		private function get_smart_insights_notification_timestamp_impl( $item ) {
			$value = $item['last_seen_at'] ?? $item['updated_at'] ?? $item['created_at'] ?? $item['date_to'] ?? '';
			$time  = $value ? strtotime( (string) $value ) : 0;

			return $time ? $time : 0;
		}

		/**
		 * Verify notification AJAX nonce and capability.
		 *
		 * @since 1.3.5
		 */
		private function smart_insights_notifications_ajax_check() {
			check_ajax_referer( 'opti_behavior_smart_insights_notifications', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
			}
		}

		/**
		 * AJAX: list Smart Insights.
		 *
		 * @since 1.3.3
		 */
		private function ajax_smart_insights_list_impl() {
			$this->smart_insights_ajax_check();

			$generator = $this->get_smart_insights_generator_impl();
			if ( ! $generator ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insights are not available yet.', 'opti-behavior' ) ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$period                = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : Opti_Behavior_Smart_Insights_Generator::DEFAULT_PERIOD;
			$start_date            = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
			$end_date              = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
			$context               = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'center';
			$auto_refresh_if_stale = ! empty( $_POST['auto_refresh_if_stale'] ) && 'center' === $context;
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$exclude_spam = $this->set_smart_insights_spam_exclusion_context_impl( $_POST );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$spam_scope   = $this->get_smart_insights_spam_scope_key_impl( $exclude_spam );
			$range      = $generator->get_date_range_for_period( $period, $start_date, $end_date );
			if ( is_wp_error( $range ) ) {
				wp_send_json_error( array( 'message' => $range->get_error_message() ) );
			}

			$generation = $auto_refresh_if_stale
				? $this->maybe_generate_smart_insights_for_request_impl( $generator, $period, $range, 'ajax_stale_refresh', $exclude_spam )
				: $this->get_smart_insights_read_only_generation_state_impl( $generator, $period, $range, 'ajax_list', $exclude_spam );

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$limit       = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 20;
			$offset      = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
			$status      = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
			$category    = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
			$confidence  = isset( $_POST['confidence'] ) ? sanitize_key( wp_unslash( $_POST['confidence'] ) ) : '';
			$search      = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
			$signal_id   = isset( $_POST['signal_id'] ) ? sanitize_key( wp_unslash( $_POST['signal_id'] ) ) : '';
			$entity_type = isset( $_POST['entity_type'] ) ? sanitize_key( wp_unslash( $_POST['entity_type'] ) ) : '';
			$top_only    = ! empty( $_POST['top_only'] ) || 'dashboard' === $context;
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$repository = $generator->get_repository();
			$query_args = array(
				'limit'     => 500,
				'offset'    => $offset,
				'date_from' => $range['from'],
				'date_to'   => $range['to'],
				'spam_scope' => $spam_scope,
				'orderby'   => 'last_seen_at',
				'order'     => 'DESC',
			);
			$requested_limit  = $limit ? min( 100, $limit ) : 20;
			$requested_offset = $query_args['offset'];

			if ( $status ) {
				if ( 'active' === $status ) {
					$query_args['status__in'] = array(
						Opti_Behavior_Smart_Insights_Repository::STATUS_NEW,
						Opti_Behavior_Smart_Insights_Repository::STATUS_VIEWED,
						Opti_Behavior_Smart_Insights_Repository::STATUS_IN_PROGRESS,
					);
				} else {
					$query_args['status'] = $status;
				}
			}
			if ( $category ) {
				$query_args['category'] = $category;
			}
			if ( $signal_id ) {
				$query_args['signal_id'] = $signal_id;
			}
			if ( $entity_type ) {
				$query_args['entity_type'] = $entity_type;
			}
			if ( $top_only ) {
				$query_args['limit'] = $requested_limit;
			}

			$capabilities     = $this->get_smart_insights_capabilities_impl();
			$matching_args    = $query_args;
			$matching_args['offset'] = 0;
			$matching_items   = $top_only ? $repository->get_top_insights( $matching_args ) : $repository->get_insights( $matching_args );
			if ( method_exists( $repository, 'collapse_open_insights_by_group' ) ) {
				$matching_items = $repository->collapse_open_insights_by_group( $matching_items );
			}
			$matching_items = $capabilities->filter_insights_for_viewer( $matching_items, $top_only ? 'dashboard' : 'list' );
			$matching_items = array_map( array( $this, 'sanitize_smart_insights_entity_links_impl' ), $matching_items );
			$matching_items = array_map( array( $this, 'localize_smart_insights_insight_impl' ), $matching_items );

			if ( ! $top_only ) {
				$matching_items = $this->filter_smart_insights_list_items_impl(
					$matching_items,
					array(
						'confidence' => $confidence,
						'search'     => $search,
					)
				);
			}

			$items = $top_only ? $matching_items : array_slice( $matching_items, $requested_offset, $requested_limit );

			$filter_items = $items;
			if ( ! $top_only ) {
				$filter_query_args            = $query_args;
				$filter_query_args['limit']   = 500;
				$filter_query_args['offset']  = 0;
				$filter_query_args['category'] = '';
				$filter_query_args['entity_type'] = '';
				$filter_items                 = $repository->get_insights( $filter_query_args );
				if ( method_exists( $repository, 'collapse_open_insights_by_group' ) ) {
					$filter_items = $repository->collapse_open_insights_by_group( $filter_items );
				}
				$filter_items                 = $capabilities->filter_insights_for_viewer( $filter_items, 'list' );
				$filter_items                 = array_map( array( $this, 'sanitize_smart_insights_entity_links_impl' ), $filter_items );
				$filter_items                 = array_map( array( $this, 'localize_smart_insights_insight_impl' ), $filter_items );
			}

			$total_query_args = array(
				'limit'      => 500,
				'offset'     => 0,
				'date_from'  => $range['from'],
				'date_to'    => $range['to'],
				'spam_scope' => $spam_scope,
				'orderby'    => 'last_seen_at',
				'order'      => 'DESC',
			);
			$total_count      = $this->get_smart_insights_scoped_count_impl( $repository, $total_query_args, false );
			$matching_count   = $this->get_smart_insights_scoped_count_impl(
				$repository,
				$query_args,
				! $top_only && ( '' !== $confidence || '' !== $search ),
				$matching_items
			);
			if ( $top_only ) {
				$matching_count = count( $matching_items );
			}
			$matching_counts = $this->get_smart_insights_counts_impl( $matching_items );

			wp_send_json_success(
				array(
					'items'          => $items,
					'insights'       => $items,
					'counts'         => $matching_counts,
					'visible_count'  => count( $items ),
					'matching_count' => $matching_count,
					'total_count'    => $total_count,
					'filters'        => $this->get_smart_insights_filter_options_impl( $filter_items ),
					'date_range'     => $range,
					'exclude_spam'   => $exclude_spam,
					'generation'     => is_wp_error( $generation ) ? array( 'error' => $generation->get_error_message() ) : $generation,
					'state'          => $this->get_smart_insights_response_state_impl( $items, $generation ),
				)
			);
		}

		/**
		 * AJAX: get Smart Insight detail.
		 *
		 * @since 1.3.3
		 */
		private function ajax_smart_insights_detail_impl() {
			$this->smart_insights_ajax_check();

			$generator = $this->get_smart_insights_generator_impl();
			if ( ! $generator ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insights are not available yet.', 'opti-behavior' ) ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$insight_id = isset( $_POST['insight_id'] ) ? absint( wp_unslash( $_POST['insight_id'] ) ) : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$insight    = $generator->get_repository()->get_insight( $insight_id );
			if ( ! $insight ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insight not found.', 'opti-behavior' ) ) );
			}

			$insight = $this->get_smart_insights_capabilities_impl()->filter_insight_for_viewer( $insight, 'detail' );
			$insight = $this->sanitize_smart_insights_entity_links_impl( $insight );
			$insight = $this->localize_smart_insights_insight_impl( $insight );

			wp_send_json_success( array( 'insight' => $insight ) );
		}

		/**
		 * AJAX: compute the "Who is affected?" segment matrix for one insight.
		 *
		 * Answers the story's third question on demand instead of persisting it:
		 * the affected population is only meaningful for the insight's own scope
		 * and the caller's current spam policy, both of which are request state.
		 *
		 * @since 1.3.9
		 */
		private function ajax_smart_insights_segments_impl() {
			$this->smart_insights_ajax_check();

			$generator = $this->get_smart_insights_generator_impl();
			if ( ! $generator || ! class_exists( 'Opti_Behavior_Smart_Insights_Segment_Matrix' ) ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insights are not available yet.', 'opti-behavior' ) ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$insight_id = isset( $_POST['insight_id'] ) ? absint( wp_unslash( $_POST['insight_id'] ) ) : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$insight    = $insight_id ? $generator->get_repository()->get_insight( $insight_id ) : null;
			if ( ! $insight ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insight not found.', 'opti-behavior' ) ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$exclude_spam = $this->set_smart_insights_spam_exclusion_context_impl( $_POST );
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$builder = new Opti_Behavior_Smart_Insights_Segment_Matrix();
			$matrix  = $builder->get_matrix( $insight, array( 'exclude_spam' => $exclude_spam ) );
			$cached  = $builder->was_served_from_cache();
			$matrix  = $this->get_smart_insights_segment_matrix_capabilities_impl()->filter_segment_matrix_for_viewer( $matrix );

			wp_send_json_success(
				array(
					'insight_id'   => $insight_id,
					'segments'     => $matrix,
					'exclude_spam' => $exclude_spam,
					'cached'       => $cached,
				)
			);
		}

		/**
		 * AJAX: compute the daily time series for one insight.
		 *
		 * Answers "since when" for the same scope the segment matrix answers
		 * "where" for: current period, previous period of equal length, and the
		 * first time this problem was ever detected. Optionally restricted to one
		 * segment bucket so clicking an outlier overlays its own curve.
		 *
		 * @since 1.4.0
		 */
		private function ajax_smart_insights_timeseries_impl() {
			$this->smart_insights_ajax_check();

			$generator = $this->get_smart_insights_generator_impl();
			if ( ! $generator || ! class_exists( 'Opti_Behavior_Smart_Insights_Segment_Matrix' ) ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insights are not available yet.', 'opti-behavior' ) ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$insight_id        = isset( $_POST['insight_id'] ) ? absint( wp_unslash( $_POST['insight_id'] ) ) : 0;
			$segment_dimension = isset( $_POST['segment_dimension'] ) ? sanitize_key( wp_unslash( $_POST['segment_dimension'] ) ) : '';
			// A combination overlay arrives as a pipe-joined pair ("Safari|France").
			// sanitize_text_field() keeps the separator, and the builder is what
			// validates the pair and expands it into two equality restrictions —
			// do not narrow this to sanitize_key() or combos stop overlaying.
			$segment_key       = isset( $_POST['segment_key'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_key'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$insight = $insight_id ? $generator->get_repository()->get_insight( $insight_id ) : null;
			if ( ! $insight ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insight not found.', 'opti-behavior' ) ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$exclude_spam = $this->set_smart_insights_spam_exclusion_context_impl( $_POST );
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$builder = new Opti_Behavior_Smart_Insights_Segment_Matrix();
			$series  = $builder->get_timeseries(
				$insight,
				array(
					'exclude_spam'      => $exclude_spam,
					'segment_dimension' => $segment_dimension,
					'segment_key'       => $segment_key,
				)
			);

			wp_send_json_success(
				array(
					'insight_id'   => $insight_id,
					'timeseries'   => $series,
					'exclude_spam' => $exclude_spam,
					'cached'       => $builder->was_served_from_cache(),
				)
			);
		}

		/**
		 * Resolve a capability shaper that can shape segment matrices.
		 *
		 * Falls back to the anonymous stub used by the other Smart Insights
		 * handlers when the capabilities class is not loadable, so the endpoint
		 * degrades to a locked payload instead of fataling.
		 *
		 * @since 1.3.9
		 * @return object
		 */
		private function get_smart_insights_segment_matrix_capabilities_impl() {
			$capabilities = $this->get_smart_insights_capabilities_impl();
			if ( is_object( $capabilities ) && method_exists( $capabilities, 'filter_segment_matrix_for_viewer' ) ) {
				return $capabilities;
			}

			return new class() {
				/**
				 * Locked fallback shaper.
				 *
				 * @param array $matrix Segment matrix.
				 * @return array
				 */
				public function filter_segment_matrix_for_viewer( $matrix ) {
					$matrix               = is_array( $matrix ) ? $matrix : array();
					$matrix['dimensions'] = array();
					$matrix['locked']     = true;
					$matrix['tier']       = 'pro_locked';

					return $matrix;
				}
			};
		}

		/**
		 * AJAX: force Smart Insights refresh.
		 *
		 * @since 1.3.3
		 */
		private function ajax_smart_insights_refresh_impl() {
			$this->smart_insights_ajax_check();

			$generator = $this->get_smart_insights_generator_impl();
			if ( ! $generator ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insights are not available yet.', 'opti-behavior' ) ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$period     = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : Opti_Behavior_Smart_Insights_Generator::DEFAULT_PERIOD;
			$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
			$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$exclude_spam = $this->set_smart_insights_spam_exclusion_context_impl( $_POST );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$spam_scope   = $this->get_smart_insights_spam_scope_key_impl( $exclude_spam );

			// "Refresh insights" is the only user action that can change what a
			// segment split or a daily curve should say, so it is the only place
			// that invalidates their 6h transients.
			if ( class_exists( 'Opti_Behavior_Smart_Insights_Segment_Matrix' ) ) {
				Opti_Behavior_Smart_Insights_Segment_Matrix::flush_cache();
			}

			$range      = $generator->get_date_range_for_period( $period, $start_date, $end_date );
			if ( is_wp_error( $range ) ) {
				wp_send_json_error( array( 'message' => $range->get_error_message() ) );
			}

			$result = $generator->generate_for_period(
				$range['from'],
				$range['to'],
				array(
					'force'           => true,
					'source'          => 'ajax_refresh',
					'exclude_spam'    => $exclude_spam,
					// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
					'candidate_limit' => isset( $_POST['candidate_limit'] ) ? absint( wp_unslash( $_POST['candidate_limit'] ) ) : 50,
					// phpcs:enable WordPress.Security.NonceVerification.Missing
				)
			);

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			$items = $generator->get_repository()->get_top_insights(
				array(
					// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
					'limit'     => isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 5,
					// phpcs:enable WordPress.Security.NonceVerification.Missing
					'date_from' => $range['from'],
					'date_to'   => $range['to'],
					'spam_scope' => $spam_scope,
				)
			);
			$items = $this->get_smart_insights_capabilities_impl()->filter_insights_for_viewer( $items, 'dashboard' );
			$items = array_map( array( $this, 'sanitize_smart_insights_entity_links_impl' ), $items );
			$items = array_map( array( $this, 'localize_smart_insights_insight_impl' ), $items );
			$total_count = $this->get_smart_insights_scoped_count_impl(
				$generator->get_repository(),
				array(
					'date_from' => $range['from'],
					'date_to'   => $range['to'],
					'spam_scope' => $spam_scope,
				),
				false
			);

			wp_send_json_success(
				array(
					'generation'     => $result,
					'items'          => $items,
					'insights'       => $items,
					'counts'         => $this->get_smart_insights_counts_impl( $items ),
					'visible_count'  => count( $items ),
					'matching_count' => count( $items ),
					'total_count'    => $total_count,
					'date_range'     => $range,
					'exclude_spam'   => $exclude_spam,
				)
			);
		}

		/**
		 * AJAX: update Smart Insight status.
		 *
		 * @since 1.3.3
		 */
		private function ajax_smart_insights_update_status_impl() {
			$this->smart_insights_ajax_check();

			$generator = $this->get_smart_insights_generator_impl();
			if ( ! $generator ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insights are not available yet.', 'opti-behavior' ) ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$insight_id = isset( $_POST['insight_id'] ) ? absint( wp_unslash( $_POST['insight_id'] ) ) : 0;
			$status     = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			if ( in_array( $status, array( 'reviewed', 'mark_reviewed' ), true ) ) {
				$status = Opti_Behavior_Smart_Insights_Repository::STATUS_VIEWED;
			}
			$result     = $generator->get_repository()->update_status( $insight_id, $status );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			$insight = $generator->get_repository()->get_insight( $insight_id );
			$insight = $this->get_smart_insights_capabilities_impl()->filter_insight_for_viewer( $insight, 'list' );
			$insight = $this->sanitize_smart_insights_entity_links_impl( $insight );
			$insight = $this->localize_smart_insights_insight_impl( $insight );

			wp_send_json_success(
				array(
					'updated' => true,
					'insight' => $insight,
				)
			);
		}

		/**
		 * AJAX: build Weekly CRO Summary from stored Smart Insights.
		 *
		 * @since 1.3.3
		 */
		private function ajax_smart_insights_summary_impl() {
			$this->smart_insights_ajax_check();

			$generator = $this->get_smart_insights_generator_impl();
			if ( ! $generator || ! class_exists( 'Opti_Behavior_Smart_Insights_Weekly_Summary' ) ) {
				wp_send_json_error( array( 'message' => __( 'Smart Insights summary is not available yet.', 'opti-behavior' ) ) );
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$period     = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : Opti_Behavior_Smart_Insights_Generator::DEFAULT_PERIOD;
			$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
			$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
			$exclude_spam = $this->set_smart_insights_spam_exclusion_context_impl( $_POST );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$range      = $generator->get_date_range_for_period( $period, $start_date, $end_date );
			if ( is_wp_error( $range ) ) {
				wp_send_json_error( array( 'message' => $range->get_error_message() ) );
			}

			$generation      = $this->get_smart_insights_read_only_generation_state_impl( $generator, $period, $range, 'ajax_summary', $exclude_spam );
			$summary_builder = new Opti_Behavior_Smart_Insights_Weekly_Summary( $generator->get_repository(), $this->get_smart_insights_capabilities_impl() );
			$summary         = $summary_builder->get_summary(
				$range['from'],
				$range['to'],
				array(
					// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by smart_insights_ajax_check() above.
					'context' => isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'center',
					// phpcs:enable WordPress.Security.NonceVerification.Missing
					'exclude_spam' => $exclude_spam,
				)
			);

			wp_send_json_success(
				array(
					'summary'    => $summary,
					'date_range' => $range,
					'exclude_spam' => $exclude_spam,
					'generation' => $generation,
				)
			);
		}

		/**
		 * Apply UI-only Smart Insights filters after payload shaping.
		 *
		 * Confidence is stored inside scores JSON, and search spans multiple
		 * decoded fields, so these filters run after the repository returns safe
		 * bounded results.
		 *
		 * @since 1.3.3
		 * @param array $items   Insight rows.
		 * @param array $filters UI filters.
		 * @return array Filtered rows.
		 */
		private function filter_smart_insights_list_items_impl( $items, $filters ) {
			if ( ! is_array( $items ) ) {
				return array();
			}

			$confidence   = isset( $filters['confidence'] ) ? sanitize_key( $filters['confidence'] ) : '';
			$search       = isset( $filters['search'] ) ? strtolower( trim( sanitize_text_field( $filters['search'] ) ) ) : '';
			$allowed_confidence = array( 'low', 'medium', 'high' );

			return array_values(
				array_filter(
					$items,
					function( $item ) use ( $confidence, $search, $allowed_confidence ) {
						if ( ! is_array( $item ) ) {
							return false;
						}

						if ( $confidence && in_array( $confidence, $allowed_confidence, true ) ) {
							$item_confidence = isset( $item['scores']['confidence_label'] ) ? strtolower( sanitize_text_field( $item['scores']['confidence_label'] ) ) : '';
							if ( $item_confidence !== $confidence ) {
								return false;
							}
						}

						if ( $search ) {
							$haystack = strtolower(
								wp_strip_all_tags(
									implode(
										' ',
										array(
											$item['signal_name'] ?? '',
											$item['category'] ?? '',
											$item['entity_type'] ?? '',
											$item['entity_id'] ?? '',
											$item['entity_label'] ?? '',
											$item['interpretation'] ?? '',
											$item['status'] ?? '',
										)
									)
								)
							);

							if ( false === strpos( $haystack, $search ) ) {
								return false;
							}
						}

						return true;
					}
				)
			);
		}

		/**
		 * Count Smart Insights for an explicit response-count scope.
		 *
		 * When confidence/search post-filters are active, the repository cannot
		 * count them directly because those fields live in decoded/shaped payloads.
		 * In that case, use the already filtered bounded result set.
		 *
		 * @since 1.3.5
		 * @param Opti_Behavior_Smart_Insights_Repository $repository      Repository instance.
		 * @param array                                   $query_args      Repository query args.
		 * @param bool                                    $use_items_count Whether to count supplied items.
		 * @param array                                   $items           Optional filtered items.
		 * @return int
		 */
		private function get_smart_insights_scoped_count_impl( $repository, $query_args, $use_items_count = false, $items = array() ) {
			if ( $use_items_count ) {
				return is_array( $items ) ? count( $items ) : 0;
			}

			if ( is_object( $repository ) && method_exists( $repository, 'count_insights' ) ) {
				$count_args                    = is_array( $query_args ) ? $query_args : array();
				$count_args['collapse_groups'] = true;
				unset( $count_args['limit'], $count_args['offset'], $count_args['orderby'], $count_args['order'] );

				return (int) $repository->count_insights( $count_args );
			}

			return is_array( $items ) ? count( $items ) : 0;
		}

		/**
		 * Build counts from the same deduped and capability-filtered rows shown in the UI.
		 *
		 * Counts are matching-scoped for the current filters, not limited to the
		 * visible paginated response slice.
		 *
		 * @since 1.3.4
		 * @param array $items Insight rows.
		 * @return array
		 */
		private function get_smart_insights_counts_impl( $items ) {
			$counts = array(
				'active'        => 0,
				'high_priority' => 0,
				'recurring'     => 0,
			);

			foreach ( is_array( $items ) ? $items : array() as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$status = isset( $item['status'] ) ? sanitize_key( $item['status'] ) : '';
				if ( in_array( $status, array( 'new', 'viewed', 'in_progress' ), true ) ) {
					$counts['active']++;
				}

				if ( $this->is_smart_insights_high_priority_impl( $item ) ) {
					$counts['high_priority']++;
				}

				$recurrence = isset( $item['recurrence_count'] ) ? (int) $item['recurrence_count'] : ( isset( $item['detection']['recurrence_count'] ) ? (int) $item['detection']['recurrence_count'] : 0 );
				if ( $recurrence > 1 || ! empty( $item['detection']['same_issue_previous_period'] ) ) {
					$counts['recurring']++;
				}
			}

			return $counts;
		}

		/**
		 * Whether an insight row counts as high priority.
		 *
		 * Rank-scored rows are counted by their label (Critical or High); rows
		 * generated before rank scoring keep the historical `>= 60` threshold.
		 *
		 * @since 1.3.9
		 * @param array $item Insight row.
		 * @return bool
		 */
		private function is_smart_insights_high_priority_impl( $item ) {
			$scores = isset( $item['scores'] ) && is_array( $item['scores'] ) ? $item['scores'] : array();

			if ( isset( $scores['priority_method'] ) && 'rank_v2' === $scores['priority_method'] ) {
				$label_key = ! empty( $scores['priority_label_key'] ) ? sanitize_key( $scores['priority_label_key'] ) : '';
				if ( '' === $label_key && class_exists( 'Opti_Behavior_Smart_Insights_Scorer' ) ) {
					$label_key = Opti_Behavior_Smart_Insights_Scorer::normalize_label_key( isset( $scores['priority_label'] ) ? $scores['priority_label'] : '' );
				}

				if ( '' !== $label_key ) {
					return in_array( $label_key, array( 'critical', 'high' ), true );
				}
			}

			$priority = isset( $scores['priority_score'] ) ? (int) $scores['priority_score'] : 0;

			return $priority >= 60;
		}

		/**
		 * Remove stale entity admin links before sending insight payloads to the browser.
		 *
		 * @since 1.3.4
		 * @param array $insight Insight row.
		 * @return array
		 */
		private function sanitize_smart_insights_entity_links_impl( $insight ) {
			if ( ! is_array( $insight ) || empty( $insight['metrics']['entity_context'] ) || ! is_array( $insight['metrics']['entity_context'] ) ) {
				return $insight;
			}

			$context   = $insight['metrics']['entity_context'];
			$admin_url = isset( $context['admin_url'] ) ? esc_url_raw( (string) $context['admin_url'] ) : '';
			if ( '' === $admin_url ) {
				return $insight;
			}

			$post_id = 0;
			$parts   = wp_parse_url( $admin_url );
			if ( ! empty( $parts['query'] ) ) {
				$query = array();
				wp_parse_str( $parts['query'], $query );
				if ( ! empty( $query['post'] ) ) {
					$post_id = absint( $query['post'] );
				}
			}

			if ( $post_id > 0 && ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
				$insight['metrics']['entity_context']['admin_url'] = '';
				$insight['metrics']['entity_context']['admin_link_disabled_reason'] = __( 'The original WordPress item no longer exists or cannot be edited by this user.', 'opti-behavior' );
			}

			return $insight;
		}

		/**
		 * Localize stored Smart Insights payloads before sending them to the browser.
		 *
		 * Insight rows persist copy at generation time. Without response-time
		 * localization, rows generated under one locale keep showing those strings
		 * after the admin switches language. Known Free signal payloads are rebuilt
		 * from their stored aggregate metrics so titles, explanations, causes, and
		 * recommendations use the current viewer locale without mutating stored rows.
		 *
		 * @since 1.4.1
		 * @param array $insight Insight row.
		 * @return array
		 */
		private function localize_smart_insights_insight_impl( $insight ) {
			if ( ! is_array( $insight ) ) {
				return $insight;
			}

			$signal_id = isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '';
			$signal_names = array(
				'high_traffic_low_engagement'      => __( 'High Traffic, Low Engagement Page', 'opti-behavior' ),
				'high_exit_rate_page'              => __( 'High Exit Rate Page', 'opti-behavior' ),
				'low_scroll_depth_important_page'  => __( 'Low Scroll Depth on Important Page', 'opti-behavior' ),
				'basic_bounce_alert'               => __( 'Basic Bounce Alert', 'opti-behavior' ),
				'traffic_spike_observation'        => __( 'Traffic Spike Observation', 'opti-behavior' ),
				'basic_mobile_bounce_warning'      => __( 'Basic Mobile Bounce Warning', 'opti-behavior' ),
			);
			if ( isset( $signal_names[ $signal_id ] ) ) {
				$insight['signal_name'] = $signal_names[ $signal_id ];
			}

			if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Recommendations' ) ) {
				return $insight;
			}

			$template_ids = array(
				'high_traffic_low_engagement'      => Opti_Behavior_Smart_Insights_Recommendations::TEMPLATE_PAGE_LOW_ENGAGEMENT,
				'high_exit_rate_page'              => Opti_Behavior_Smart_Insights_Recommendations::TEMPLATE_PAGE_HIGH_EXIT_RATE,
				'low_scroll_depth_important_page'  => Opti_Behavior_Smart_Insights_Recommendations::TEMPLATE_PAGE_LOW_SCROLL_DEPTH,
				'basic_bounce_alert'               => Opti_Behavior_Smart_Insights_Recommendations::TEMPLATE_PAGE_BASIC_BOUNCE_ALERT,
				'traffic_spike_observation'        => Opti_Behavior_Smart_Insights_Recommendations::TEMPLATE_PAGE_TRAFFIC_SPIKE,
				'basic_mobile_bounce_warning'      => Opti_Behavior_Smart_Insights_Recommendations::TEMPLATE_DEVICE_BASIC_MOBILE_BOUNCE,
			);
			if ( empty( $template_ids[ $signal_id ] ) ) {
				return $insight;
			}

			$metrics   = isset( $insight['metrics'] ) && is_array( $insight['metrics'] ) ? $insight['metrics'] : array();
			$baselines = isset( $metrics['baseline'] ) && is_array( $metrics['baseline'] ) ? $metrics['baseline'] : array();
			foreach ( array( 'site_sessions', 'site_avg_bounce_rate', 'site_avg_scroll_depth', 'site_avg_time_on_page', 'site_avg_exit_rate', 'site_avg_conversion_rate', 'site_avg_cta_click_rate', 'tracking_data_complete' ) as $baseline_key ) {
				if ( isset( $metrics[ $baseline_key ] ) && ! isset( $baselines[ $baseline_key ] ) ) {
					$baselines[ $baseline_key ] = $metrics[ $baseline_key ];
				}
			}

			$recommendations = new Opti_Behavior_Smart_Insights_Recommendations();
			$template = $recommendations->get_template(
				$template_ids[ $signal_id ],
				array(
					'metrics'   => $metrics,
					'baselines' => $baselines,
					'detection' => isset( $insight['detection'] ) && is_array( $insight['detection'] ) ? $insight['detection'] : array(),
					'scores'    => isset( $insight['scores'] ) && is_array( $insight['scores'] ) ? $insight['scores'] : array(),
				)
			);

			// This refresh runs AFTER filter_insight_for_viewer(), so re-copying
			// the template's upgrade teaser would resurrect it for Pro viewers
			// the capabilities layer just stripped it from.
			$viewer_has_pro = $this->get_smart_insights_capabilities_impl()->has_pro_access();
			foreach ( array( 'interpretation', 'why_it_matters', 'likely_causes', 'recommended_actions', 'related_reports', 'upgrade_preview' ) as $copy_key ) {
				if ( 'upgrade_preview' === $copy_key && $viewer_has_pro ) {
					continue;
				}
				if ( array_key_exists( $copy_key, $template ) ) {
					$insight[ $copy_key ] = $template[ $copy_key ];
				}
			}
			if ( isset( $template['interpretation'] ) ) {
				$insight['explanation'] = $template['interpretation'];
			}

			return $insight;
		}

		/**
		 * Build capability-aware filter options from shaped insights.
		 *
		 * @since 1.3.4
		 * @param array $items Shaped insight rows.
		 * @return array
		 */
		private function get_smart_insights_filter_options_impl( $items ) {
			$options = array(
				'categories'   => array(
					array(
						'value' => '',
						'label' => __( 'All categories', 'opti-behavior' ),
					),
				),
				'entity_types' => array(
					array(
						'value' => '',
						'label' => __( 'All entities', 'opti-behavior' ),
					),
				),
			);

			if ( ! is_array( $items ) ) {
				return $options;
			}

			$categories   = array();
			$entity_types = array();

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$category = isset( $item['category'] ) ? sanitize_text_field( $item['category'] ) : '';
				if ( '' !== $category ) {
					$categories[ $category ] = $this->get_smart_insights_category_label_impl( $category );
				}

				$entity_type = isset( $item['entity_type'] ) ? sanitize_key( $item['entity_type'] ) : '';
				if ( '' !== $entity_type ) {
					$entity_types[ $entity_type ] = $this->get_smart_insights_entity_type_label_impl( $entity_type );
				}
			}

			if ( ! empty( $categories ) ) {
				ksort( $categories, SORT_NATURAL | SORT_FLAG_CASE );
				foreach ( $categories as $value => $label ) {
					$options['categories'][] = array(
						'value' => $value,
						'label' => $label,
					);
				}
			}

			if ( ! empty( $entity_types ) ) {
				asort( $entity_types, SORT_NATURAL | SORT_FLAG_CASE );
				foreach ( $entity_types as $value => $label ) {
					$options['entity_types'][] = array(
						'value' => $value,
						'label' => $label,
					);
				}
			}

			return $options;
		}

		/**
		 * Get Smart Insights category labels for filter controls.
		 *
		 * @since 1.3.4
		 * @param string $category Stored category value.
		 * @return string
		 */
		private function get_smart_insights_category_label_impl( $category ) {
			$labels = array(
				'UX/CRO'               => __( 'UX / CRO', 'opti-behavior' ),
				'Page Engagement'      => __( 'Engagement Issue', 'opti-behavior' ),
				'Exit and Abandonment' => __( 'Exit / Abandonment', 'opti-behavior' ),
				'Device Friction'      => __( 'Mobile Issue', 'opti-behavior' ),
				'Trend and Anomaly'    => __( 'Traffic Quality', 'opti-behavior' ),
				'Funnel Drop-off'      => __( 'Funnel Drop-off', 'opti-behavior' ),
				'Form Friction'        => __( 'Form Friction', 'opti-behavior' ),
				'Technical Issue'      => __( 'Technical Issue', 'opti-behavior' ),
				'Campaign Issue'       => __( 'Campaign Issue', 'opti-behavior' ),
				'Revenue Opportunity'  => __( 'Revenue Opportunity', 'opti-behavior' ),
			);

			return isset( $labels[ $category ] ) ? $labels[ $category ] : $category;
		}

		/**
		 * Get Smart Insights entity labels for filter controls.
		 *
		 * @since 1.3.4
		 * @param string $entity_type Stored entity type value.
		 * @return string
		 */
		private function get_smart_insights_entity_type_label_impl( $entity_type ) {
			$labels = array(
				'page'   => __( 'Pages', 'opti-behavior' ),
				'source' => __( 'Sources', 'opti-behavior' ),
				'device' => __( 'Devices', 'opti-behavior' ),
				'funnel' => __( 'Funnels', 'opti-behavior' ),
				'form'   => __( 'Forms', 'opti-behavior' ),
				'cta'    => __( 'CTAs', 'opti-behavior' ),
				'site'   => __( 'Site', 'opti-behavior' ),
			);

			return isset( $labels[ $entity_type ] ) ? $labels[ $entity_type ] : ucfirst( str_replace( '_', ' ', $entity_type ) );
		}

		/**
		 * Build a read-only generation state for passive Smart Insights AJAX reads.
		 *
		 * List, summary, dashboard, and notification polling endpoints must never
		 * mutate global insight lifecycle state. This payload preserves the old
		 * response contract for the UI while making the scope explicit and stable.
		 *
		 * @since 1.3.5
		 * @param Opti_Behavior_Smart_Insights_Generator $generator Generator instance.
		 * @param string                                 $period    Period slug.
		 * @param array                                  $range     Normalized date range.
		 * @param string                                 $source    Read source identifier.
		 * @return array
		 */
		private function get_smart_insights_read_only_generation_state_impl( $generator, $period, $range, $source = 'ajax_list', $exclude_spam = null ) {
			$last_generated = 0;

			if ( is_object( $generator ) && method_exists( $generator, 'get_generated_transient_key' ) && is_array( $range ) && ! empty( $range['from'] ) && ! empty( $range['to'] ) ) {
				$spam_scope = null === $exclude_spam ? $this->get_smart_insights_default_spam_exclusion_impl() : (bool) $exclude_spam;
				$last_run   = get_transient( $generator->get_generated_transient_key( $range['from'], $range['to'], $spam_scope ) );
				if ( $last_run ) {
					$last_generated = (int) $last_run;
				}
			}

			return array(
				'skipped'        => true,
				'reason'         => 'read_only_passive',
				'source'         => sanitize_key( $source ),
				'period'         => sanitize_key( $period ),
				'date_range'     => $range,
				'exclude_spam'   => null === $exclude_spam ? $this->get_smart_insights_default_spam_exclusion_impl() : (bool) $exclude_spam,
				'last_generated' => $last_generated,
				'scope_note'     => __( 'Passive Smart Insights reads are read-only. Counts come from stored, deduplicated visible insight groups for the selected range; generation runs only from explicit refresh or scheduled cron.', 'opti-behavior' ),
			);
		}

		/**
		 * Maybe regenerate Smart Insights for an opted-in admin list request.
		 *
		 * The center opts in only on initial loads and range changes. The generator
		 * still performs a range-aware freshness check and an attempt throttle so a
		 * browser refresh cannot run expensive aggregate queries repeatedly.
		 *
		 * @since 1.3.7
		 * @param Opti_Behavior_Smart_Insights_Generator $generator Generator instance.
		 * @param string                                 $period    Period slug.
		 * @param array                                  $range     Normalized date range.
		 * @param string                                 $source    Generation source identifier.
		 * @return array|WP_Error
		 */
		private function maybe_generate_smart_insights_for_request_impl( $generator, $period, $range, $source = 'ajax_stale_refresh', $exclude_spam = null ) {
			if ( ! is_object( $generator ) || ! method_exists( $generator, 'maybe_generate_if_stale' ) ) {
				return $this->get_smart_insights_read_only_generation_state_impl( $generator, $period, $range, $source, $exclude_spam );
			}

			$stale_seconds = $this->get_smart_insights_auto_refresh_stale_seconds_impl( $range );

			return $generator->maybe_generate_if_stale(
				$period,
				array(
					'start_date'               => isset( $range['from'] ) ? $range['from'] : '',
					'end_date'                 => isset( $range['to'] ) ? $range['to'] : '',
					'source'                   => sanitize_key( $source ),
					'stale_seconds'            => $stale_seconds,
					'attempt_throttle_seconds' => $stale_seconds,
					'candidate_limit'          => (int) apply_filters( 'opti_behavior_smart_insights_auto_refresh_candidate_limit', 25, $period, $range ),
					'auto_resolve'             => false,
					'exclude_spam'             => null === $exclude_spam ? $this->get_smart_insights_default_spam_exclusion_impl() : (bool) $exclude_spam,
				)
			);
		}

		/**
		 * Resolve the global Smart Insights spam exclusion default.
		 *
		 * @return bool
		 */
		private function get_smart_insights_default_spam_exclusion_impl() {
			if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
				return Opti_Behavior_Stats_Spam_Filter::is_enabled();
			}

			$traffic_settings = get_option(
				'opti_behavior_traffic_settings',
				array(
					'spam_detection_enabled' => true,
				)
			);

			if ( ! is_array( $traffic_settings ) ) {
				return true;
			}

			return ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] );
		}

		/**
		 * Get the persisted Smart Insights spam-scope key.
		 *
		 * @param bool|null $exclude_spam Explicit spam exclusion state.
		 * @return string
		 */
		private function get_smart_insights_spam_scope_key_impl( $exclude_spam = null ) {
			if ( null === $exclude_spam ) {
				$exclude_spam = $this->get_smart_insights_default_spam_exclusion_impl();
			}

			return $exclude_spam ? 'exclude_spam_1' : 'exclude_spam_0';
		}

		/**
		 * Resolve the current Smart Insights request's spam exclusion state.
		 *
		 * @param array|null $source Request source.
		 * @return bool
		 */
		private function resolve_smart_insights_spam_exclusion_from_request_impl( $source = null ) {
			$source = is_array( $source ) ? $source : array();

			if ( array_key_exists( 'exclude_spam', $source ) ) {
				return '1' === (string) sanitize_text_field( wp_unslash( $source['exclude_spam'] ) );
			}

			return $this->get_smart_insights_default_spam_exclusion_impl();
		}

		/**
		 * Set the shared aggregator context for Smart Insights spam-aware metrics.
		 *
		 * @param array|null $source Request source.
		 * @return bool
		 */
		private function set_smart_insights_spam_exclusion_context_impl( $source = null ) {
			$exclude_spam = $this->resolve_smart_insights_spam_exclusion_from_request_impl( $source );
			$GLOBALS['opti_behavior_exclude_spam'] = $exclude_spam;

			return $exclude_spam;
		}

		/**
		 * Get a conservative auto-refresh freshness window for a Smart Insights range.
		 *
		 * Ranges that include today can refresh every 15 minutes. Historical ranges
		 * change less often, so they use a one-hour window to avoid unnecessary DB
		 * work while admins browse or reload the center.
		 *
		 * @since 1.3.7
		 * @param array $range Normalized date range.
		 * @return int
		 */
		private function get_smart_insights_auto_refresh_stale_seconds_impl( $range ) {
			$today         = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
			$range_to      = is_array( $range ) && ! empty( $range['to'] ) ? sanitize_text_field( $range['to'] ) : $today;
			$stale_seconds = ( $range_to >= $today ) ? 15 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS;

			return max(
				5 * MINUTE_IN_SECONDS,
				(int) apply_filters( 'opti_behavior_smart_insights_auto_refresh_stale_seconds', $stale_seconds, $range )
			);
		}

		/**
		 * Build front-end state hints for empty and low-data UI states.
		 *
		 * @since 1.3.3
		 * @param array          $items      Shaped response items.
		 * @param array|WP_Error $generation Generation result.
		 * @return array Response state.
		 */
		private function get_smart_insights_response_state_impl( $items, $generation ) {
			$state = array(
				'has_items'     => ! empty( $items ),
				'is_low_data'   => false,
				'minimum_pages' => 0,
				'minimum_page_sessions' => 0,
			);

			if ( is_wp_error( $generation ) || ! is_array( $generation ) ) {
				return $state;
			}

			$state['minimum_page_sessions'] = isset( $generation['thresholds']['min_page_sessions'] ) ? (int) $generation['thresholds']['min_page_sessions'] : 0;
			$state['minimum_pages']         = isset( $generation['evaluated_pages'] ) ? (int) $generation['evaluated_pages'] : 0;
			$state['is_low_data']           = empty( $items ) && empty( $generation['skipped'] ) && 0 === $state['minimum_pages'];

			return $state;
		}

		/**
		 * Verify Smart Insights AJAX nonce and capability.
		 *
		 * @since 1.3.3
		 */
		private function smart_insights_ajax_check() {
			check_ajax_referer( 'opti_behavior_dashboard_nonce', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized', 'opti-behavior' ) ) );
			}
		}

		/**
		 * Get Smart Insights generator for AJAX handlers.
		 *
		 * @since 1.3.3
		 * @return Opti_Behavior_Smart_Insights_Generator|null
		 */
		private function get_smart_insights_generator_impl() {
			if ( isset( $this->heatmap ) && is_object( $this->heatmap ) && method_exists( $this->heatmap, 'get_smart_insights_generator' ) ) {
				return $this->heatmap->get_smart_insights_generator();
			}

			if ( class_exists( 'Opti_Behavior_Smart_Insights_Generator' ) ) {
				return new Opti_Behavior_Smart_Insights_Generator();
			}

			return null;
		}

		/**
		 * Get Smart Insights payload capability resolver.
		 *
		 * @since 1.3.3
		 * @return Opti_Behavior_Smart_Insights_Capabilities
		 */
		private function get_smart_insights_capabilities_impl() {
			if ( class_exists( 'Opti_Behavior_Smart_Insights_Capabilities' ) ) {
				return new Opti_Behavior_Smart_Insights_Capabilities();
			}

			return new class() {
				public function filter_insights_for_viewer( $insights, $context = 'list' ) {
					return is_array( $insights ) ? $insights : array();
				}

				public function filter_insight_for_viewer( $insight, $context = 'detail' ) {
					return is_array( $insight ) ? $insight : array();
				}
			};
		}

		/**
		 * Notify the API that a trial was started (non-blocking, informational).
		 *
		 * @since 1.1.3
		 */
		private function notify_api_trial_started() {
			$current_user = wp_get_current_user();

			wp_remote_post(
				'https://api.optiuser.com/trial-started',
				array(
					'body'     => wp_json_encode( array(
						'site_url' => site_url(),
						'domain'   => wp_parse_url( home_url(), PHP_URL_HOST ),
						'email'    => get_option( 'admin_email' ),
						'username' => $current_user->user_login,
						'plugin'   => 'opti-behavior',
						'version'  => OPTI_BEHAVIOR_HEATMAP_VERSION,
					) ),
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'timeout'  => 5,
					'blocking' => false,
				)
			);
		}

    }
}
