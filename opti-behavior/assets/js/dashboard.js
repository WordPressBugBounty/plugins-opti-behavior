window.optiBehaviorCurrentExcludeSpamFlag = window.optiBehaviorCurrentExcludeSpamFlag || function() {
	const params = new URLSearchParams(window.location.search);
	if (params.has('exclude_spam')) {
		return params.get('exclude_spam') === '1' ? '1' : '0';
	}
	return (window.opti_behaviorData && window.opti_behaviorData.excludeSpam === '1') ? '1' : '0';
};
window.optiBehaviorCurrentForceRefreshFlag = window.optiBehaviorCurrentForceRefreshFlag || function() {
	const params = new URLSearchParams(window.location.search);
	return params.get('force_refresh') === '1' ? '1' : '0';
};


// Browser icon SVG helper (window.optiBehaviorBrowserIconSVG) moved to the
// shared filter-UI module opti-behavior-filter-ui.js (enqueued as a
// dependency of this file). Also exposed as OptiBehaviorFilterUI.browserIconSVG.

// Advanced Filters panel state (FREE Analytics Dashboard "Filters" toggle).
// Flat object of allow-listed field => scalar value, e.g. { browser: 'Chrome' }.
// Empty object = unfiltered. Read by the widget loader below to append
// `advanced_filters` (JSON) to every widget AJAX request and to fold a hash
// of the current filters into each widget's cache/in-flight signature so a
// filter change is always treated as a new request instead of reusing a
// stale cached/in-flight response.
window.optiBehaviorAdvancedFilters = window.optiBehaviorAdvancedFilters || {};
window.optiBehaviorAdvancedFiltersHash = window.optiBehaviorAdvancedFiltersHash || function() {
	const filters = window.optiBehaviorAdvancedFilters || {};
	const keys = Object.keys(filters).sort();
	if (!keys.length) return '';
	return keys.map(function(k) { return k + '=' + filters[k]; }).join('&');
};

		// ========================================
		// ASYNC WIDGET LOADER - Performance Optimization
		// ========================================
		// Loads dashboard widgets asynchronously for faster page load
		// Each widget loads independently via AJAX
		(function() {
			'use strict';

			function optiBehaviorCurrentExcludeSpamFlag() {
				const params = new URLSearchParams(window.location.search);
				if (params.has('exclude_spam')) {
					return params.get('exclude_spam') === '1' ? '1' : '0';
				}
				return (window.opti_behaviorData && window.opti_behaviorData.excludeSpam === '1') ? '1' : '0';
			}

			function optiBehaviorCurrentForceRefreshFlag() {
				const params = new URLSearchParams(window.location.search);
				return params.get('force_refresh') === '1' ? '1' : '0';
			}

			function initSmartInsightsDashboardContext() {
				const params = new URLSearchParams(window.location.search);
				if (params.get('si_context') !== '1') {
					return;
				}
				const contextLabel = params.get('page_url') || params.get('page_id') || params.get('device') || params.get('source') || params.get('campaign') || '';
				if (!contextLabel) {
					return;
				}
				const banner = document.createElement('div');
				banner.className = 'smart-insights-context-chip';
				banner.style.cssText = 'margin:12px 0;padding:10px 12px;border-radius:10px;background:#eef2ff;color:#3730a3;font-weight:600;';
				banner.textContent = 'Smart Insights context: analytics opened for ' + contextLabel;
				const target = document.querySelector('.wrap, .opti-behavior-dashboard, #wpbody-content') || document.body;
				target.insertBefore(banner, target.firstChild);
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initSmartInsightsDashboardContext);
			} else {
				initSmartInsightsDashboardContext();
			}

			// Widget loader queue
			const widgetLoaders = [];
			let loadingInProgress = false;

			// SINGLE-FLIGHT de-dupe: map of widget-request signature -> in-flight Promise.
			// Collapses identical concurrent widget requests (same widget+period+dates+spam)
			// into one network call so a period switch + bfcache restore + initial load
			// cannot fire the same query 2-3x in parallel and saturate MySQL/PHP-FPM.
			const widgetInFlight = {};

			// MODULE-LEVEL per-(slug+signature) LOAD STATE.
			// widgetInFlight only de-dupes CONCURRENT requests — it is deleted in
			// the request .finally, so once a widget RESOLVES its entry is gone and a
			// later re-entry re-fires it. The closure-local w._loaded/_loading flags
			// (built fresh inside every initAsyncWidgets() invocation) also cannot
			// block a re-entry, because the chart-render guard's re-call of
			// initAsyncWidgets() builds a brand-new widgets[] array with fresh flags.
			// This map survives across initAsyncWidgets() invocations and is keyed by
			// "slug|period|startDate|endDate|excludeSpam" (identical to the request sig
			// at loadWidgetWithTimeout), so a date-range / period / spam change yields a
			// NEW key and is allowed to reload, while a same-params re-entry (the
			// spurious chart-guard tick) is blocked from re-firing already-loaded
			// widgets. Values: { status: 'loading'|'loaded', promise }.
			const widgetLoadState = {};

			// Force-reload hook for the manual Refresh button (same date-range, so the
			// sig is unchanged and would otherwise be treated as already-loaded). Clears
			// both the per-signature load state and any lingering in-flight entries so a
			// subsequent loader run re-fetches every widget live.
			window.optiBehaviorResetWidgetLoadState = function() {
				Object.keys(widgetLoadState).forEach(function(k){ delete widgetLoadState[k]; });
				Object.keys(widgetInFlight).forEach(function(k){ delete widgetInFlight[k]; });
			};

			/**
			 * Show loading indicator for a widget
			 */
			function showWidgetLoading(widgetId) {
				const widget = document.getElementById(widgetId);
				if (!widget) return;

				const overlay = widget.querySelector('.widget-loading-overlay');
				if (overlay) {
					overlay.classList.remove('hidden');
				}
			}

			/**
			 * Hide loading indicator for a widget
			 */
			function hideWidgetLoading(widgetId) {
				const widget = document.getElementById(widgetId);
				if (!widget) return;

				const overlay = widget.querySelector('.widget-loading-overlay');
				if (overlay) {
					overlay.classList.add('hidden');
				}
			}

		// Expose to window object for use by other widgets
		window.showWidgetLoading = showWidgetLoading;
		window.hideWidgetLoading = hideWidgetLoading;

			/**
			 * Initialize async widget loading on page load
			 */
			function initAsyncWidgets() {

				// SINGLE-FLIGHT: never run two full dashboard batches concurrently.
				// Overlapping triggers (DOMContentLoaded, pageshow, the chart-guard
				// fallback) collapse into the batch that is already running.
				if ( loadingInProgress ) { return; }
				loadingInProgress = true;

				// Function to update stats cards with loaded data
				function updateStatsCards(data) {
					if (!data || !data.stats) return;
					const stats = data.stats;
					const changes = data.changes || {};

					// Update each stat card
					const statMappings = [
						{ id: 'visitors', value: stats.visitors, change: changes.visitors },
						{ id: 'sessions', value: stats.sessions, change: changes.sessions },
						{ id: 'pageviews', value: stats.pageviews, change: changes.pageviews },
						{ id: 'avg_session_time', value: stats.avg_session_time, change: changes.avg_session_time, format: 'time' },
						{ id: 'avg_scroll_depth', value: stats.avg_scroll_depth, change: changes.avg_scroll_depth, format: 'percent' },
						{ id: 'bounce_rate', value: stats.bounce_rate, change: changes.bounce_rate, format: 'percent' }
					];

					statMappings.forEach(function(mapping) {
						const valueEl = document.querySelector('[data-stat="' + mapping.id + '"] .stat-value, .stat-card[data-stat="' + mapping.id + '"] .stat-value');
						const changeEl = document.querySelector('[data-stat="' + mapping.id + '"] .stat-change, .stat-card[data-stat="' + mapping.id + '"] .stat-change');

						// Also try by stat name class
						const altValueEl = document.querySelector('.stat-' + mapping.id.replace('stat-', '') + ' .stat-value');
						const altChangeEl = document.querySelector('.stat-' + mapping.id.replace('stat-', '') + ' .stat-change');

						const targetValue = valueEl || altValueEl;
						const targetChange = changeEl || altChangeEl;

						if (targetValue) {
							let displayValue = mapping.value;
							if (mapping.format === 'time') {
								const mins = Math.floor(displayValue / 60);
								const secs = Math.floor(displayValue % 60);
								displayValue = mins + ':' + String(secs).padStart(2, '0');
							} else if (mapping.format === 'percent') {
								displayValue = displayValue.toFixed(1) + '%';
							} else {
								displayValue = Number(displayValue).toLocaleString();
							}
							targetValue.textContent = displayValue;
						}

						if (targetChange && mapping.change !== undefined) {
							const sign = mapping.change > 0 ? '+' : '';
							const _i18n = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
							targetChange.textContent = sign + Math.round(mapping.change) + (_i18n.vsLastPeriod || '% vs last period');
							// Bounce rate is an inverted metric: an increase is bad (red), a decrease is good (green).
							const isGood = mapping.id === 'bounce_rate' ? mapping.change <= 0 : mapping.change >= 0;
							targetChange.className = 'stat-change ' + (isGood ? 'positive' : 'negative');
						}
					});

					// Also update the main stat number elements by their container class
					document.querySelectorAll('.summary-card').forEach(function(card) {
						const title = card.querySelector('.card-title, .summary-title')?.textContent?.toLowerCase() || '';
						const valueEl = card.querySelector('.summary-value, .card-value');
						const changeEl = card.querySelector('.summary-change, .card-change');

						let statKey = null;
						if (title.includes('visitor')) statKey = 'visitors';
						else if (title.includes('session') && !title.includes('time')) statKey = 'sessions';
						else if (title.includes('page')) statKey = 'pageviews';
						else if (title.includes('time')) statKey = 'avg_session_time';
						else if (title.includes('scroll')) statKey = 'avg_scroll_depth';
						else if (title.includes('bounce')) statKey = 'bounce_rate';

						if (statKey && valueEl && stats[statKey] !== undefined) {
							let displayValue = stats[statKey];
							if (statKey === 'avg_session_time') {
								const mins = Math.floor(displayValue / 60);
								const secs = Math.floor(displayValue % 60);
								displayValue = mins + ':' + String(secs).padStart(2, '0');
							} else if (statKey === 'avg_scroll_depth' || statKey === 'bounce_rate') {
								displayValue = displayValue.toFixed(1) + '%';
							} else {
								displayValue = Number(displayValue).toLocaleString();
							}
							valueEl.textContent = displayValue;
						}

						if (statKey && changeEl && changes[statKey] !== undefined) {
							const change = changes[statKey];
							const sign = change > 0 ? '+' : '';
							const _i18n2 = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
							changeEl.textContent = sign + Math.round(change) + (_i18n2.vsLastPeriod || '% vs last period');
						}
					});

				}

				// Load all widgets in parallel for maximum performance
				// summary_stats is loaded FIRST for instant stats card display
				// NOTE: Some widgets (device_types, operating_systems, etc.) use init functions that read from window.opti_behaviorData
				// So we need to store the AJAX data there before calling the init function
				const widgets = [
					{ name: 'summary_stats', widgetId: null, callback: function(data) {
						updateStatsCards(data);
						// Store daily_history and initialize stat history charts
						if (data.daily_history) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.daily_history = data.daily_history;
							if (typeof window.initStatHistoryCharts === 'function') {
								window.initStatHistoryCharts();
							}
							// Render the Traffic Overview chart straight from the per-day
							// series already in this payload, instead of issuing a second
							// (heavy) sessions_chart query. The series is byte-identical to
							// the old sessions_chart response (same canonical traffic source,
							// range, and spam policy), so the chart is unchanged - it just
							// paints with zero extra round-trips and no transient dependency.
							// Paint the Traffic Overview chart from this payload. The chart
							// object is created by a separate init loop; if it is not ready
							// yet (Chart.js still loading), retry briefly so we never leave
							// the spinner up over an unpainted chart.
							if (typeof optiBehaviorDailyHistoryToTraffic === 'function') {
								const trafficSeries = optiBehaviorDailyHistoryToTraffic(data.daily_history);
								let chartTries = 0;
								const paintTrafficChart = function() {
									if (window.sessionsChart && typeof updateSessionsChart === 'function') {
										try { updateSessionsChart(trafficSeries); } catch (e) {}
										// The chart no longer has its own AJAX widget, so nothing
										// else clears its loading overlay - hide it once painted.
										if (typeof hideWidgetLoading === 'function') {
											try { hideWidgetLoading('sessions-chart-widget'); } catch (e) {}
										}
										return;
									}
									if (chartTries++ < 50) {
										setTimeout(paintTrafficChart, 150);
									} else if (typeof hideWidgetLoading === 'function') {
										// Chart.js never produced sessionsChart; don't trap the
										// user under a permanent spinner.
										try { hideWidgetLoading('sessions-chart-widget'); } catch (e) {}
									}
								};
								paintTrafficChart();
							}
						}
					} },
					// sessions_chart is intentionally NOT an AJAX widget. The Traffic
					// Overview chart is rendered from the per-day series already returned
					// inside the summary_stats payload (daily_history), so it needs no
					// second query. See externalWidgets below for the no-fetch loader.
					{ name: 'browsers', widgetId: 'browsers-widget', callback: function(data) { if (typeof updateBrowsers === 'function') updateBrowsers(data.browsers || []); } },
					{ name: 'device_types', widgetId: 'device-types-widget', callback: function(data) {
						if (data.device_types) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.charts = window.opti_behaviorData.dashboard.charts || {};
							window.opti_behaviorData.dashboard.charts.device_types = data.device_types;
							if (typeof initDeviceTypesWidget === 'function') initDeviceTypesWidget();
						}
					} },
					{ name: 'operating_systems', widgetId: 'operating-systems-widget', callback: function(data) {
						if (data.operating_systems) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.charts = window.opti_behaviorData.dashboard.charts || {};
							window.opti_behaviorData.dashboard.charts.operating_systems = data.operating_systems;
							if (typeof initOperatingSystemsWidget === 'function') initOperatingSystemsWidget();
						}
					} },
					{ name: 'user_intent', widgetId: 'user-intent-widget', callback: function(data) {
						if (data.user_intent) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.user_intent = data.user_intent;
							if (typeof initUserIntentWidget === 'function') initUserIntentWidget();
						}
					} },
					{ name: 'countries', widgetId: 'countries-widget', callback: function(data) { if (typeof updateCountries === 'function') updateCountries(data.countries || []); } },
					{ name: 'realtime', widgetId: 'realtime-widget', callback: function(data) { if (data.active_visitors && typeof updateRealtimeVisitors === 'function') updateRealtimeVisitors(data.active_visitors); } },
					{ name: 'top_pages', widgetId: 'top-pages-widget', callback: function(data) { if (typeof updateTopPagesEnhanced === 'function') updateTopPagesEnhanced(data.top_pages || []); } },
					{ name: 'referrers', widgetId: 'referrers-widget', callback: function(data) { if (typeof updateReferrers === 'function') updateReferrers(data.referrers || []); } },
					{ name: 'screen_resolutions', widgetId: 'screen-resolution-widget', callback: function(data) {
						if (data.screen_resolutions) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.charts = window.opti_behaviorData.dashboard.charts || {};
							window.opti_behaviorData.dashboard.charts.screen_resolutions = data.screen_resolutions;
							if (typeof initScreenResolutionChart === 'function') initScreenResolutionChart();
						}
					} },
					{ name: 'traffic_classification', widgetId: 'traffic-classification-widget', callback: function(data) {
						if (data.traffic_classification) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.traffic_classification = data.traffic_classification;
							if (typeof initTrafficClassificationWidget === 'function') initTrafficClassificationWidget();
						}
					} },
					{ name: 'bot_traffic', widgetId: 'bot-traffic-widget', callback: function(data) {
						if (data.bot_traffic) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.bot_traffic = data.bot_traffic;
							if (typeof initBotTrafficWidget === 'function') initBotTrafficWidget();
						}
					} },
					{ name: 'new_vs_returning', widgetId: 'new-vs-returning-widget', callback: function(data) {
						if (data.new_vs_returning) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.new_vs_returning = data.new_vs_returning;
							if (typeof initNewVsReturningWidget === 'function') initNewVsReturningWidget();
						}
					} },
					{ name: 'visited_directories', widgetId: 'visited-directories-widget', callback: function(data) {
						if (data.visited_directories) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.visited_directories = data.visited_directories;
							if (typeof initVisitedDirectoriesWidget === 'function') initVisitedDirectoriesWidget();
						}
					} },
					{ name: 'new_registered_users', widgetId: 'new-registered-users-widget', callback: function(data) {
						if (data.new_registered_users) {
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || {};
							window.opti_behaviorData.dashboard.new_registered_users = data.new_registered_users;
							if (typeof initNewRegisteredUsersWidget === 'function') initNewRegisteredUsersWidget();
						}
					} }
					// Note: top_users is handled by its own dedicated IIFE at the bottom of this file
				];


				// PERFORMANCE OPTIMIZATION: TRUE PARALLEL loading
				// All widgets load simultaneously for fastest possible dashboard
				// Each widget has its own timeout to prevent blocking

				const ajaxUrl = (window.opti_behaviorData && window.opti_behaviorData.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php';
				const nonce = (window.opti_behaviorData && window.opti_behaviorData.nonce) || '';
				const WIDGET_TIMEOUT = 30000; // 30 second timeout per widget

				// Load a single widget with timeout
				function loadWidgetWithTimeout(widget) {
					const widgetStartTime = performance.now();

					const period = window.opti_behaviorData?.period || 'last30days';
					const startDate = window.opti_behaviorData?.startDate || '';
					const endDate = window.opti_behaviorData?.endDate || '';
					const excludeSpam = window.optiBehaviorCurrentExcludeSpamFlag();
					const advancedFilters = window.optiBehaviorAdvancedFilters || {};
					const filtersHash = window.optiBehaviorAdvancedFiltersHash();

					// SINGLE-FLIGHT: if an identical request is already running, reuse it
					// instead of issuing a duplicate (same widget + period + range + spam
					// + advanced-filters selection).
					const sig = widget.name + '|' + period + '|' + startDate + '|' + endDate + '|' + excludeSpam + '|' + filtersHash;
					if (widgetInFlight[sig]) {
						return widgetInFlight[sig];
					}

					showWidgetLoading(widget.widgetId);

					const controller = new AbortController();
					const timeoutId = setTimeout(() => controller.abort(), WIDGET_TIMEOUT);

					const formData = new FormData();
					formData.append('action', 'optibehavior_dashboard_data');
					formData.append('widget', widget.name);
					formData.append('nonce', nonce);
					formData.append('period', period);
					if (startDate) formData.append('start_date', startDate);
					if (endDate) formData.append('end_date', endDate);
					formData.append('exclude_spam', excludeSpam);
					formData.append('force_refresh', optiBehaviorCurrentForceRefreshFlag());
					if (filtersHash) {
						formData.append('advanced_filters', JSON.stringify(advancedFilters));
					}

					// FIX 5 (Section-1 speed): if the inline early-prefetch already fired
					// the identical summary_stats request near TTFB, ADOPT its in-flight
					// response instead of issuing a second identical query (single-flight).
					let jsonPromise;
					if (widget.name === 'summary_stats'
						&& window.optiBehaviorEarlyKpi
						&& window.optiBehaviorEarlyKpi.sig === sig
						&& window.optiBehaviorEarlyKpi.promise) {
						jsonPromise = window.optiBehaviorEarlyKpi.promise;
						// Consume once so a later refresh (same sig) does not reuse a stale payload.
						window.optiBehaviorEarlyKpi = null;
					} else {
						jsonPromise = fetch(ajaxUrl, {
							method: 'POST',
							body: formData,
							credentials: 'same-origin',
							signal: controller.signal
						}).then(response => response.json());
					}

					const request = jsonPromise
					.then(result => {
						clearTimeout(timeoutId);
						hideWidgetLoading(widget.widgetId);
						const widgetEndTime = performance.now();

						if (result.success && result.data) {
							// Store data in global opti_behaviorData for widgets that read from it
							window.opti_behaviorData = window.opti_behaviorData || {};
							window.opti_behaviorData.dashboard = window.opti_behaviorData.dashboard || { charts: {} };

							// Store based on widget type
							if (widget.name === 'realtime') {
								window.opti_behaviorData.dashboard.realtime = result.data;
							} else if (widget.name === 'user_intent') {
								window.opti_behaviorData.dashboard.user_intent = result.data.user_intent;
							} else if (widget.name === 'traffic_classification') {
								window.opti_behaviorData.dashboard.traffic_classification = result.data.traffic_classification;
							} else if (widget.name === 'bot_traffic') {
								window.opti_behaviorData.dashboard.bot_traffic = result.data.bot_traffic;
							} else {
								// Chart data (device_types, operating_systems, browsers, countries, etc.)
								Object.assign(window.opti_behaviorData.dashboard.charts, result.data);
							}

							widget.callback(result.data);
						}
						return { widget: widget.name, success: true };
					})
					.catch(error => {
						clearTimeout(timeoutId);
						hideWidgetLoading(widget.widgetId);
						const widgetEndTime = performance.now();
						const isTimeout = error.name === 'AbortError';

						if (isTimeout) {
							if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.warning('[Widget TIMEOUT] ' + widget.name + ' exceeded ' + (WIDGET_TIMEOUT/1000) + 's', 'dashboard');
						} else {
							if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Widget load error: ' + widget.name, 'dashboard', error);
						}
						return { widget: widget.name, success: false, timeout: isTimeout };
					})
					.finally(() => { delete widgetInFlight[sig]; });

					widgetInFlight[sig] = request;
					return request;
				}

				// PARALLEL LOADING: All widgets load simultaneously for fastest total time
				// Total time = slowest widget (not sum of all widgets)

				// ============================================================
				// SECTION-AWARE SEQUENTIAL PRELOAD
				// ============================================================
				// Section 1 (Key Metrics) loads first, in parallel, so the page is
				// interactive in a few seconds. Sections 2-4 are then preloaded ONE
				// BY ONE in the background (sequential = no MySQL/PHP-FPM saturation),
				// even while their accordion stays visually collapsed. Every widget
				// is still fetched fresh on each page load (force-live, never cached
				// server-side) - "preload" only means fetch-ahead within this page.
				const SECTION_BY_WIDGET = {
					summary_stats: 1, realtime: 1, sessions_chart: 1,
					top_users: 2, top_pages: 2, visitor_heatmap: 2,
					new_vs_returning: 3, visited_directories: 3, new_registered_users: 3,
					traffic_classification: 3, bot_traffic: 3, user_intent: 3,
					referrers: 4, countries: 4, browsers: 4,
					device_types: 4, operating_systems: 4, screen_resolutions: 4
				};

				// External loaders own their AJAX endpoint (Top Engaged Users and the
				// Visitor Activity Heatmap). They are joined into the same queue as
				// pseudo-widgets so they obey the identical sequential ordering and no
				// longer fire on their own at page load.
				const externalWidgets = [
					{ name: 'top_users', widgetId: 'top-users-widget', external: true,
						loader: function() { return window.optiBehaviorLoadTopUsers ? window.optiBehaviorLoadTopUsers() : Promise.resolve(); } },
					{ name: 'visitor_heatmap', widgetId: 'visitor-heatmap-widget', external: true,
						loader: function() { return window.optiBehaviorLoadVisitorHeatmap ? window.optiBehaviorLoadVisitorHeatmap() : Promise.resolve(); } }
				];

				const allWidgets = widgets.concat(externalWidgets);
				allWidgets.forEach(function(w) { w.section = SECTION_BY_WIDGET[w.name] || 4; });

				// Current request signature for a widget slug. MUST match the sig used
				// inside loadWidgetWithTimeout (~name|period|startDate|endDate|excludeSpam
				// |advancedFiltersHash) so the module-level guard and the in-flight
				// de-dupe share one key space.
				function widgetSignature(name) {
					const period = window.opti_behaviorData?.period || 'last30days';
					const startDate = window.opti_behaviorData?.startDate || '';
					const endDate = window.opti_behaviorData?.endDate || '';
					const excludeSpam = window.optiBehaviorCurrentExcludeSpamFlag();
					const filtersHash = window.optiBehaviorAdvancedFiltersHash();
					return name + '|' + period + '|' + startDate + '|' + endDate + '|' + excludeSpam + '|' + filtersHash;
				}

				// Widget cache-affinity dependencies: a dependent widget is held until
				// its dependency resolves so it can reuse server-side state the
				// dependency primed, instead of recomputing an identical heavy query in
				// parallel. Currently empty - the Traffic Overview chart no longer makes
				// its own request at all (it renders from the summary_stats payload), so
				// there is nothing to gate. The mechanism is kept for future use.
				const WIDGET_DEPS = {};

				// Run a single widget (internal per-widget AJAX or external loader).
				// Idempotent across initAsyncWidgets() invocations: the MODULE-LEVEL
				// widgetLoadState (keyed by slug+sig) is the authority, so a chart-guard
				// re-entry never re-fires a widget already loaded/loading for the CURRENT
				// date-range signature. The closure-local w._loaded/_loading flags are
				// kept too (harmless) but only guard within a single invocation.
				function runWidget(w) {
					const sig = widgetSignature(w.name);
					const st = widgetLoadState[sig];
					if (st && (st.status === 'loaded' || st.status === 'loading')) {
						return st.promise || Promise.resolve();
					}
					if (w._loaded || w._loading) { return w._promise || Promise.resolve(); }
					w._loading = true;
					// Resolve cache-affinity dependencies first (e.g. prime the shared
					// traffic-series transient via summary_stats before sessions_chart).
					const depNames = WIDGET_DEPS[w.name] || [];
					let depGate = Promise.resolve();
					if (depNames.length) {
						depGate = Promise.all(depNames.map(function(dn) {
							if (dn === w.name) { return Promise.resolve(); }
							const dw = allWidgets.find(function(x) { return x.name === dn; });
							return dw ? runWidget(dw) : Promise.resolve();
						})).catch(function() {});
					}
					const runSelf = w.external
						? function() { return Promise.resolve().then(w.loader); }
						: function() { return loadWidgetWithTimeout(w); };
					const p = depNames.length ? depGate.then(runSelf) : runSelf();
					w._promise = Promise.resolve(p)
						.catch(function() {})
						.finally(function() { w._loading = false; w._loaded = true; });
					// Record module-level state so a later invocation's runWidget(sig)
					// short-circuits instead of issuing a duplicate fetch. The early-KPI
					// adopt path (summary_stats) flows through here too, so summary_stats
					// is marked loaded and a re-entry will not re-fire it.
					widgetLoadState[sig] = { status: 'loading', promise: w._promise };
					w._promise.finally(function() {
						if (widgetLoadState[sig]) { widgetLoadState[sig].status = 'loaded'; }
					});
					return w._promise;
				}

				// ============================================================
				// PROGRESSIVE / LAZY BLOCK-LIST LOADING
				// ============================================================
				// Replaces the old unconditional background preloader. On init we load
				// ONLY list 1 (Section 1 / Traffic Overview). Lists 2-4 stay completely
				// un-fetched (ZERO AJAX) until the user clicks a section chevron. That
				// first click ARMS an auto-cascade: the requested list loads (parallel
				// within), and on completion the next list is enqueued automatically,
				// one list at a time, ascending, until every list is loaded.
				//
				// Ordering guarantees:
				//   - PARALLEL within a list   -> Promise.all(list.map(runWidget))
				//   - SEQUENTIAL between lists  -> single-pump FIFO + ascending sort
				// Markup coupling: data-section-index is 1-based; listIndex = index - 1.

				// Ordered list registry derived from SECTION_BY_WIDGET (the source of
				// truth). listsInOrder[0] = Section 1 widgets, [1] = Section 2, ...
				// External blocks (top_users / visitor_heatmap) ride in their own list
				// via their .section tag, so they obey the identical gating.
				const listsInOrder = [];
				(function buildListRegistry() {
					let maxSection = 1;
					allWidgets.forEach(function(w) { if (w.section > maxSection) { maxSection = w.section; } });
					for (let s = 1; s <= maxSection; s++) {
						listsInOrder.push(allWidgets.filter(function(w) { return w.section === s; }));
					}
				})();

				const listState = [];    // per index: undefined | 'loading' | 'loaded'
				const listPromise = [];  // per index: Promise for that list's load
				const listQueue = [];    // pending list indices (ascending FIFO)
				let listPumpRunning = false;
				let cascadeArmed = false; // armed when list 1 finishes (or earlier chevron)
				let chipsKicked = false;  // section_summaries fires once, never at init

				// Bounded-concurrency runner. Instead of firing every widget in a list
				// at once (Promise.all over up to 6 widgets), run at most N in flight at
				// a time. On shared hosting a cold dashboard load is disk-I/O bound: 6
				// heavy GROUP BY scans launched simultaneously thrash a cold InnoDB
				// buffer pool and each finishes SLOWER than if drip-fed. A small pool
				// keeps the DB warm-ish and steady. Pure scheduling change: same widgets,
				// same queries, same (always-live/just-cached) results - only WHEN they
				// fire changes, so stats stay exactly as fresh as before. Tunable via
				// window.optiBehaviorListConcurrency (default 2).
				function runWithPool(items, worker, limit) {
					limit = Math.max(1, parseInt(limit, 10) || 1);
					return new Promise(function(resolve) {
						const queue = items.slice();
						let active = 0;
						let settled = 0;
						const total = items.length;
						if (total === 0) { resolve(); return; }
						function next() {
							while (active < limit && queue.length) {
								const item = queue.shift();
								active++;
								Promise.resolve()
									.then(function() { return worker(item); })
									.catch(function() {})
									.finally(function() {
										active--;
										settled++;
										if (settled >= total) { resolve(); }
										else { next(); }
									});
							}
						}
						next();
					});
				}

				// Parallel-within-list loader (Req 5), now bounded. Idempotent per index.
				function loadList(i) {
					if (i < 0 || i >= listsInOrder.length) { return Promise.resolve(); }
					if (listState[i] === 'loaded' || listState[i] === 'loading') {
						return listPromise[i] || Promise.resolve();
					}
					listState[i] = 'loading';
					const poolLimit = parseInt(window.optiBehaviorListConcurrency, 10);
					listPromise[i] = runWithPool(listsInOrder[i], runWidget, poolLimit > 0 ? poolLimit : 2)
						.catch(function() {})
						.finally(function() {
							listState[i] = 'loaded';
							// CASCADE-COMPLETE SIGNAL: when the final list finishes, mark the
							// whole cascade done. The realtime poller waits on this (not on
							// section1ready) so it never competes with lists 2-4 for DB/PHP-FPM
							// during the first-load cascade (the source of 20-25s contention
							// spikes on shared hosting).
							if (i >= listsInOrder.length - 1) {
								window.optiBehaviorCascadeComplete = true;
								try { window.dispatchEvent(new Event('optibehavior:cascadecomplete')); } catch (e) {}
							}
							// AUTO-CASCADE (user-confirmed): once armed, chain the next list so
							// list N+1 starts only AFTER list N fully loads. Cascade is armed in
							// list 1's init .finally, so 1->2->3->4 flows automatically with no
							// chevron click required.
							if (cascadeArmed) { requestList(i + 1); }
						});
					return listPromise[i];
				}

				// Sequential-between-lists gate (Req 4): FIFO, ascending, one at a time.
				function requestList(i) {
					if (i < 0 || i >= listsInOrder.length) { return; }
					if (listState[i] === 'loaded' || listState[i] === 'loading') { return; }
					if (listQueue.indexOf(i) !== -1) { return; }
					listQueue.push(i);
					listQueue.sort(function(a, b) { return a - b; });
					pumpLists();
				}

				function pumpLists() {
					if (listPumpRunning) { return; }
					const i = listQueue.shift();
					if (i === undefined) { return; }
					listPumpRunning = true;
					loadList(i).finally(function() { listPumpRunning = false; pumpLists(); });
				}

				// Arm the cascade and lazily load a user-requested list. This is the
				// ONLY entry point for lists 2+ full block data. The section_summaries
				// teaser chips already fire at init (see below), so chipsKicked is normally
				// true here; the guard remains only as a defensive fallback.
				function expandList(sectionIndex) {
					sectionIndex = parseInt(sectionIndex, 10);
					if (isNaN(sectionIndex)) { return; }
					cascadeArmed = true;
					if (!chipsKicked) {
						chipsKicked = true;
						if (window.OptiBehaviorSectionSummaries && typeof window.OptiBehaviorSectionSummaries.load === 'function') {
							try { window.OptiBehaviorSectionSummaries.load(); } catch (e) {}
						}
					}
					requestList(sectionIndex - 1);
				}

				// Public hook (back-compat). Old callers used bumpSection(sectionIndex)
				// to reprioritize the preloader; it now arms the cascade and lazily
				// loads that section's list. isWidgetReady kept for chart-guard/export.
				window.OptiBehaviorPreloadQueue = {
					bumpSection: expandList,
					expandSection: expandList,
					requestList: requestList,
					isWidgetReady: function(name) {
						const w = allWidgets.find(function(x) { return x.name === name; });
						return !!(w && w._loaded);
					}
				};

				// STEP 1: load ONLY list 1 (Section 1) on init - parallel within the
				// list. Lists 2-4 full block data is NOT touched here; it stays un-fetched
				// (zero block AJAX) until a chevron click arms the auto-cascade (see
				// expandList).
				//
				// Teaser chips: the lightweight section_summaries AJAX is fired here in
				// PARALLEL with list 1 (NOT deferred to chevron click) so the collapsed
				// section headers (Engagement / Audience / Visitor Environment) fill in
				// immediately instead of showing skeletons forever. It is one small summary
				// query and runs concurrently, so it does not delay list-1 KPI paint.
				chipsKicked = true;
				if (window.OptiBehaviorSectionSummaries && typeof window.OptiBehaviorSectionSummaries.load === 'function') {
					try { window.OptiBehaviorSectionSummaries.load(); } catch (e) {}
				}
				loadList(0)
					.finally(function() {
						loadingInProgress = false;
						// Release secondary live calls (e.g. smart-insights notifications)
						// only AFTER the gating Section-1 KPI requests resolve, so they
						// never compete with the gating KPI request near first paint.
						if (!window.optiBehaviorSection1Ready) {
							window.optiBehaviorSection1Ready = true;
							try { window.dispatchEvent(new Event('optibehavior:section1ready')); } catch (e) {}
						}
						// AUTO-CASCADE (user-confirmed): list 1 (Traffic Overview) is fully
						// loaded -> automatically begin loading the remaining lists in the
						// background WITHOUT requiring any chevron click. Arm the cascade and
						// request list 2; each list's own .finally then chains the next
						// (list 3 after 2, list 4 after 3) one at a time, sequentially.
						cascadeArmed = true;
						requestList(1);
					});
			}

			// Expose the parallel loader so the chart-render guard (and any other code)
			// can re-trigger a live widget load without re-firing the slow no-widget path.
			window.optiBehaviorLoadAllWidgets = initAsyncWidgets;

			// Initialize on DOM ready
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initAsyncWidgets);
			} else {
				initAsyncWidgets();
			}

		})();

		// Global helper function to get trend HTML with chevron icons
		function getTrendHTML(currentCount, previousCount) {
		  if (!previousCount || previousCount === 0) {
		    if (currentCount > 0) {
		      return '<span class="visitor-trend trend-up"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>100%</span>';
		    }
		    return '<span class="visitor-trend trend-neutral">0%</span>';
		  }

		  const change = ((currentCount - previousCount) / previousCount) * 100;
		  const changeAbs = Math.abs(change).toFixed(0);

		  if (change > 0) {
		    return `<span class="visitor-trend trend-up"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>${changeAbs}%</span>`;
		  } else if (change < 0) {
		    return `<span class="visitor-trend trend-down"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>${changeAbs}%</span>`;
		  } else {
		    return '<span class="visitor-trend trend-neutral">0%</span>';
		  }
		}

		// Device Types Widget with Donut Chart + Styled Legend Table
		(function(){
		  // Device type colors: Desktop (blue), Mobile (green), Tablet (orange)
		  const DEVICE_COLORS = {
		    'Desktop': '#3B82F6',
		    'Mobile': '#10B981',
		    'Tablet': '#F59E0B'
		  };

		  // Update device types legend table
		  function updateDeviceTypesLegend(data) {
		    const legendBody = document.getElementById('device-types-legend-body');
		    if (!legendBody) return;

		    legendBody.innerHTML = '';

		    // Prepare device types array with all three types
		    const i18n = window.opti_behaviorData?.strings || {};
		    const deviceLabels = {
		      'Desktop': i18n.desktop || 'Desktop',
		      'Mobile': i18n.mobile || 'Mobile',
		      'Tablet': i18n.tablet || 'Tablet'
		    };
		    const deviceTypes = ['Desktop', 'Mobile', 'Tablet'].map(name => {
		      const item = data.find(d => d.name === name);
		      return {
		        name: deviceLabels[name],
		        rawName: name,
		        count: item ? (item.count || 0) : 0,
		        prevCount: item ? (item.prevCount || 0) : 0,
		        color: DEVICE_COLORS[name]
		      };
		    });

		    // Calculate total and percentages
		    const total = deviceTypes.reduce((sum, type) => sum + type.count, 0);

		    deviceTypes.forEach(type => {
		      type.percentage = total > 0 ? ((type.count / total) * 100).toFixed(1) : 0;
		    });

		    // Create table rows
		    deviceTypes.forEach(type => {
		      const row = document.createElement('tr');
		      row.innerHTML = `
		        <td class="device-type-cell">
		          <span>
		            <span class="device-color-dot" style="background-color: ${type.color};"></span>
		            <span class="device-type-name">${type.name}</span>
		          </span>
		        </td>
		        <td class="device-stats-cell">
		          <span class="visitor-count">${type.count}</span>
		          <span class="visitor-percentage">${type.percentage}%</span>
		          ${getTrendHTML(type.count, type.prevCount)}
		        </td>
		      `;
		      legendBody.appendChild(row);
		    });
		  }

		  // Master Device Types Widget Initialization
		  function initDeviceTypesWidget() {
		    if (typeof Chart === 'undefined') {
		      setTimeout(initDeviceTypesWidget, 300);
		      return;
		    }

		    const ctx = document.getElementById('device-types-pie');
		    if (!ctx) return;

		    const data = window.opti_behaviorData;
		    if (!data || !data.dashboard || !data.dashboard.charts || !data.dashboard.charts.device_types) return;

		    const deviceData = data.dashboard.charts.device_types;

		    // Update legend table
		    updateDeviceTypesLegend(deviceData);

		    // Prepare chart data
		    const i18n = window.opti_behaviorData?.strings || {};
		    const deviceTypeKeys = ['Desktop', 'Mobile', 'Tablet'];
		    const deviceLabels = {
		      'Desktop': i18n.desktop || 'Desktop',
		      'Mobile': i18n.mobile || 'Mobile',
		      'Tablet': i18n.tablet || 'Tablet'
		    };
		    const chartData = deviceTypeKeys.map(name => {
		      const item = deviceData.find(d => d.name === name);
		      return item ? (item.count || 0) : 0;
		    });
		    const chartLabels = deviceTypeKeys.map(name => deviceLabels[name]);

		    // Check if we have any data
		    const totalCount = chartData.reduce((sum, count) => sum + count, 0);
		    if (totalCount === 0) {
		      // Hide the table
		      const table = document.querySelector('.device-types-legend');
		      if (table) {
		        table.style.display = 'none';
		      }
		      // Show empty state (force: destroy any stale chart first)
		      if (window.optibehavior_showEmptyStateForCanvas) {
		        const emptyMsg = i18n.noDeviceData || 'No device data available';
		        window.optibehavior_showEmptyStateForCanvas('device-types-pie', 'device', emptyMsg, true);
		      }
		      return;
		    }

		    // We have data - show the table and hide empty state
		    ctx.style.display = ''; // restore canvas if a previous empty state hid it
		    const table = document.querySelector('.device-types-legend');
		    if (table) {
		      table.style.display = '';
		    }

		    // Hide empty state if it exists
		    const container = ctx.parentElement;
		    if (container) {
		      const emptyState = container.querySelector('.optibehavior-empty-state');
		      if (emptyState) {
		        emptyState.style.display = 'none';
		      }
		    }

		    const chartColors = deviceTypeKeys.map(name => DEVICE_COLORS[name]);

		    // Destroy existing chart if it exists
		    const existingChart = Chart.getChart(ctx);
		    if (existingChart) existingChart.destroy();

		    // Create new donut chart
		    new Chart(ctx, {
		      type: 'doughnut',
		      data: {
		        labels: chartLabels,
		        datasets: [{
		          data: chartData,
		          backgroundColor: chartColors,
		          borderWidth: 0
		        }]
		      },
		      options: {
		        responsive: true,
		        maintainAspectRatio: false,
		        cutout: '70%',
		        plugins: {
		          legend: { display: false },
		          tooltip: {
		            callbacks: {
		              label: function(context) {
		                try {
		                  const label = context.label || '';
		                  const value = context.parsed || 0;
		                  const total = (context.dataset && context.dataset.data || []).reduce((a, b) => a + (+b || 0), 0);
		                  const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
		                  return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
		                } catch(e) {
		                  return (context.label || '') + ': ' + (context.parsed || 0);
		                }
		              }
		            }
		          }
		        }
		      }
		    });
		  }

		  // Operating Systems Widget Initialization
		  function initOperatingSystemsWidget() {
		    if (typeof Chart === 'undefined') {
		      setTimeout(initOperatingSystemsWidget, 300);
		      return;
		    }

		    const ctx = document.getElementById('operating-systems-chart');
		    if (!ctx) return;

		    const data = window.opti_behaviorData;
		    if (!data || !data.dashboard || !data.dashboard.charts) return;

		    let osData = data.dashboard.charts.operating_systems || [];

		    // Fallback: derive from visitors if no operating_systems data.
		    // NEVER when advanced filters are active - the visitors list is the
		    // unfiltered page-load payload, so deriving from it would resurrect
		    // unfiltered numbers on an empty filtered result.
		    const osFiltersActive = Object.keys(window.optiBehaviorAdvancedFilters || {}).length > 0;
		    if (!osData.length && !osFiltersActive && data.dashboard.visitors) {
		      const map = {};
		      (data.dashboard.visitors || []).forEach(v => {
		        const os = (v.os || '').trim();
		        const osLower = os.toLowerCase();
		        if (!os || osLower === 'unknown' || osLower === 'undefined' || osLower === 'other') return;
		        map[os] = (map[os] || 0) + 1;
		      });
		      osData = Object.keys(map).map(os => ({ os: os, name: os, count: map[os], prevCount: 0 }));
		    }

		    // Filter out unknown/invalid OS
		    osData = osData.filter(os => {
		      const name = (os.os || os.name || '').trim().toLowerCase();
		      return name && name !== 'unknown' && name !== 'undefined' && name !== 'other';
		    });

		    if (!osData.length) {
		      // Clear stale legend rows so a previous (unfiltered) render cannot survive.
		      const staleLegend = document.getElementById('os-legend-body');
		      if (staleLegend) staleLegend.innerHTML = '';
		      // Show empty state (force: destroy any stale chart first)
		      if (window.optibehavior_showEmptyStateForCanvas) {
		        window.optibehavior_showEmptyStateForCanvas('operating-systems-chart', 'os', ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.noOperatingSystemData)||'No operating system data available'), true);
		      }
		      return;
		    }

		    // Operating system colors
		    const OS_COLORS = {
		      'Windows': '#0078d4',
		      'macOS': '#000000',
		      'iOS': '#007aff',
		      'Android': '#3ddc84',
		      'Linux': '#fcc624',
		      'ChromeOS': '#4285f4'
		    };

		    // Hide empty state if it exists (data is available)
		    ctx.style.display = ''; // restore canvas if a previous empty state hid it
		    const container = ctx.parentElement;
		    if (container) {
		      const emptyState = container.querySelector('.optibehavior-empty-state');
		      if (emptyState) {
		        emptyState.style.display = 'none';
		      }
		    }

		    // Restore the OS legend table container if a previous empty state hid it
		    if (container) {
		      container.querySelectorAll('.os-table-container').forEach(function(tc){ tc.style.display = ''; });
		    }
		    const osWidgetEl = ctx.closest('.dashboard-widget');
		    if (osWidgetEl) {
		      osWidgetEl.querySelectorAll('.os-table-container').forEach(function(tc){ tc.style.display = ''; });
		    }

		    // Update legend table
		    const legendBody = document.getElementById('os-legend-body');
		    if (legendBody) {
		      legendBody.innerHTML = '';

		      // Calculate total and percentages
		      const total = osData.reduce((sum, os) => sum + (os.count || 0), 0);

		      osData.forEach(os => {
		        const count = os.count || 0;
		        const prevCount = os.prevCount || 0;
		        const percentage = total > 0 ? ((count / total) * 100).toFixed(1) : 0;
		        const color = OS_COLORS[os.os] || OS_COLORS[os.name] || '#6b7280';

		        // Get trend HTML using the global getTrendHTML function
		        const trendHTML = getTrendHTML(count, prevCount);

		        const row = document.createElement('tr');
		        row.innerHTML = `
		          <td class="os-type-cell">
		            <span>
		              <span class="os-color-dot" style="background-color: ${color};"></span>
		              <span class="os-type-name">${os.os || os.name}</span>
		            </span>
		          </td>
		          <td class="os-stats-cell">
		            <span class="visitor-count">${count}</span>
		            <span class="visitor-percentage">${percentage}%</span>
		            ${trendHTML}
		          </td>
		        `;
		        legendBody.appendChild(row);
		      });
		    }

		    // Prepare chart data
		    const labels = osData.map(os => os.os || os.name);
		    const chartData = osData.map(os => os.count || 0);
		    const chartColors = labels.map(label => OS_COLORS[label] || '#6b7280');

		    // Destroy existing chart if it exists
		    const existingChart = Chart.getChart(ctx);
		    if (existingChart) existingChart.destroy();

		    // Create new donut chart
		    new Chart(ctx, {
		      type: 'doughnut',
		      data: {
		        labels: labels,
		        datasets: [{
		          data: chartData,
		          backgroundColor: chartColors,
		          borderWidth: 0
		        }]
		      },
		      options: {
		        responsive: true,
		        maintainAspectRatio: false,
		        cutout: '70%',
		        plugins: {
		          legend: { display: false },
		          tooltip: {
		            callbacks: {
		              label: function(context) {
		                try {
		                  const label = context.label || '';
		                  const value = context.parsed || 0;
		                  const total = (context.dataset && context.dataset.data || []).reduce((a, b) => a + (+b || 0), 0);
		                  const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
		                  return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
		                } catch(e) {
		                  return (context.label || '') + ': ' + (context.parsed || 0);
		                }
		              }
		            }
		          }
		        }
		      }
		    });
		  }

		  function tryInit(){
		    if (typeof Chart==='undefined') { setTimeout(tryInit,300); return; }
    // Apply global Chart.js defaults for consistent, readable charts
    try {
      Chart.defaults.color = '#334155';
      Chart.defaults.font.family = ['Inter','system-ui','-apple-system','Segoe UI','Roboto','Helvetica Neue','Arial','Noto Sans','Apple Color Emoji','Segoe UI Emoji'].join(',');
      Chart.defaults.font.size = 12;
      Chart.defaults.responsive = true;
      Chart.defaults.maintainAspectRatio = false;
      Chart.defaults.animation = false; // prevent deformation on hover/resize
      Chart.defaults.interaction = { mode: 'index', intersect: false };
      Chart.defaults.resizeDelay = 200;
      // Number formatting for numeric axes
      Chart.defaults.scales = Chart.defaults.scales || {};
      Chart.defaults.scales.linear = Chart.defaults.scales.linear || {};
      Chart.defaults.scales.linear.ticks = Chart.defaults.scales.linear.ticks || {};
      Chart.defaults.scales.linear.ticks.callback = function(value){ try{ return new Intl.NumberFormat().format(value); }catch(e){ return value; } };
      // Legibility for legends and tooltips
      Chart.defaults.plugins = Chart.defaults.plugins || {};
      Chart.defaults.plugins.legend = Chart.defaults.plugins.legend || {};
      Chart.defaults.plugins.legend.labels = { color: '#334155', font: { size: 12 } };
      Chart.defaults.plugins.tooltip = Chart.defaults.plugins.tooltip || {};
      Chart.defaults.plugins.tooltip.callbacks = Chart.defaults.plugins.tooltip.callbacks || {};

				// Helper: toggle a modern empty-state for a chart canvas when there is no data
				function ensureEmptyStateForCanvas(id, message){
					try{
						var i18n = window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n ? window.opti_behaviorDashboard.i18n : {};
						var cv = document.getElementById(id); if(!cv) return;
						var wrap = cv.parentElement || cv; if(!wrap) return;
						var empty = wrap.querySelector('.optibehavior-empty-state');
						if(!empty){
							empty = document.createElement('div');
							empty.className = 'optibehavior-empty-state';
							empty.innerHTML = '<svg viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'#9ca3af\' stroke-width=\'1.5\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><rect x=\'3\' y=\'3\' width=\'18\' height=\'14\' rx=\'2\'/><path d=\'M3 14l4-4 4 4 5-5 2 2\'/></svg>'+
								'<div class=\'optibehavior-empty-title\'>'+ (message || (window.opti_behaviorData&&opti_behaviorData.strings&&opti_behaviorData.strings.noData) || 'No data yet') +'</div>'+
								'<div class=\'optibehavior-empty-sub\'>'+ (i18n.tryBroadeningDateRange || 'Try broadening the date range or check back later.') +'</div>';
							wrap.appendChild(empty);
						}
						var inst = (typeof Chart!=='undefined') ? Chart.getChart(cv) : null;
						var has = false;
						if(inst && inst.data && inst.data.datasets && inst.data.datasets[0]){
							var ds = inst.data.datasets[0].data || [];
							has = ds.some(function(v){ return Number(v) > 0; });
						}
						cv.style.display = has ? '' : 'none';
						empty.style.display = has ? 'none' : 'flex';
					}catch(e){}
				}

      Chart.defaults.plugins.tooltip.callbacks.label = function(ctx){ var v = ctx.raw || 0; var name = (ctx.dataset && ctx.dataset.label) ? ctx.dataset.label+': ' : ''; try{ return name + new Intl.NumberFormat().format(v); }catch(e){ return name + v; } };
      // Line elements: no point radius to avoid wobbles
      Chart.defaults.elements = Chart.defaults.elements || {};
      Chart.defaults.elements.point = Object.assign({}, Chart.defaults.elements.point || {}, { radius: 0, hoverRadius: 3, hitRadius: 6 });
      Chart.defaults.elements.line  = Object.assign({}, Chart.defaults.elements.line  || {}, { tension: 0.3, borderWidth: 2 });
    } catch(e) {}


			// Plugin: draw value labels to the right of each horizontal bar (Top Countries)
			(function(){
				var plugin = {
					id: 'optibehaviorValueLabels',
					afterDatasetsDraw: function(chart, args, opts){
						try{
							var ctx = chart.ctx;
							var area = chart.chartArea || {};
							var ds = (chart.data && chart.data.datasets && chart.data.datasets[0]) ? chart.data.datasets[0] : null;
							var meta = chart.getDatasetMeta ? chart.getDatasetMeta(0) : null;
							if(!ds || !meta || !meta.data) return;
							ctx.save();
							ctx.fillStyle = (opts && opts.color) || '#111827';
							ctx.font = (opts && opts.font) || '700 13px system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif';
							ctx.textBaseline = 'middle';
							var gap = (opts && typeof opts.gap==='number') ? opts.gap : 8; // distance from bar end
							for (var i=0; i<meta.data.length; i++){
								var el = meta.data[i];
								var v = (ds.data && ds.data[i] != null) ? ds.data[i] : null;
								if (v == null) continue;
								var p = el.getProps ? el.getProps(['x','y','base','width','height'], true) : {x:el.x, y:el.y, base:el.base, width:el.width, height:el.height};
								var rightEdge = Math.max(p.x || 0, p.base || 0);
								var x = rightEdge + gap + 2; // small fudge to ensure clear separation from rounded bar edge
								// Keep labels within canvas, not clipped by chartArea
var padR = (chart.options && chart.options.layout && chart.options.layout.padding && chart.options.layout.padding.right) || 0;
var maxX = (chart.width || (area && area.right) || 0) - 4;
// we purposely do NOT subtract padR because padding is already part of canvas width
x = Math.min(x, maxX);
								ctx.textAlign = 'left';
								ctx.fillText(String(v), x, p.y);
							}
							ctx.restore();
						}catch(_){ }
					}
				};
				// expose globally and locally
				window.optibehaviorValueLabelsPlugin = plugin;
				var optibehaviorValueLabelsPlugin = plugin; // local alias for current scope
			})();

		    // Pull latest data from opti_behaviorData if available
		    var dash = window.opti_behaviorData && window.opti_behaviorData.dashboard ? window.opti_behaviorData.dashboard : null;
		    var charts = dash && dash.charts ? dash.charts : ({});

		    // Device Types Widget - initialize immediately
		    initDeviceTypesWidget();

		    // Operating Systems Widget - initialize immediately
		    if (typeof initOperatingSystemsWidget === 'function') {
		      initOperatingSystemsWidget();
		    }

		    // Browsers
		    var brEl = document.getElementById('browsers-chart');
		    if (brEl && charts.browsers) {
		      var items = charts.browsers.filter(i=> i && i.browser && String(i.browser).trim());
		      var bl = items.map(i=>i.browser);
		      var bd = items.map(i=>i.count||0);
		      (function(){ try{ var inst = Chart.getChart(brEl); if(inst){ inst.data.labels = bl; inst.data.datasets[0].data = bd; inst.update('none'); } else { new Chart(brEl, { type:'bar', data:{ labels: bl, datasets: [{ label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartBrowsers)||'Browsers'), data: bd, backgroundColor: colors(bd.length) }] }, plugins: (window.optibehaviorValueLabelsPlugin ? [window.optibehaviorValueLabelsPlugin] : []), options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, layout: { padding: { top: 8, right: 64, bottom: 8, left: 12 } }, plugins: { legend: { display: false } }, elements: { bar: { borderRadius: 6, borderSkipped: false } }, scales: { x: { display: false, grid: { display: false, drawBorder: false } }, y: {  ticks: { color: '#6b7280', font: { size: 12 } }, grid: { display: false } } } } }); } } catch(e){ try{ new Chart(brEl, { type:'bar', data:{ labels: bl, datasets: [{ label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartBrowsers)||'Browsers'), data: bd, backgroundColor: colors(bd.length) }] }, options: { responsive: true, maintainAspectRatio: false, layout: { padding: { top: 8, right: 8, bottom: 24, left: 8 } }, plugins: { legend: { display: false } }, elements: { bar: { borderRadius: 6, borderSkipped: false } }, scales: { x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 45, minRotation: 45, align: 'end', color: '#6b7280', font: { size: 12 } } }, y: { beginAtZero: true, ticks: { color: '#6b7280', font: { size: 12 } }, grid: { color: '#E5E7EB' } } } } }); }catch(_e){} } })();
		    }
		    // Operating Systems (prefer server data; fallback to visitors table)
		    var osEl = document.getElementById('os-chart');
		    if (osEl) {
		      var raw = (charts.operating_systems||[]);
      var items = raw.filter(function(i){ var s=(i&&i.os?String(i.os).trim():''); s=s.toLowerCase(); return s && s!=='unknown' && s!=='undefined' && s!=='other'; })
                     .map(function(i){ return { name: String(i.os).trim(), count: (i.count||0) }; });
		      if (!items.length && dash && dash.visitors) {
		        var map = {};
		        (dash.visitors||[]).forEach(function(v){ var k=(v.os?String(v.os).trim():''); var lk=k.toLowerCase(); if(!k||lk==='unknown'||lk==='undefined'||lk==='other') return; map[k]=(map[k]||0)+1; });
		        items = Object.keys(map).map(function(k){ return { name:k, count: map[k] }; });
		      }
		      var ol = items.map(function(i){ return i.name; });
		      var od = items.map(function(i){ return i.count; });
		      if (ol.length) {
		        new Chart(osEl, {
		          type: 'pie',
		          data: {
		            labels: ol,
		            datasets: [{
		              label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartOperatingSystems)||'Operating Systems'),
		              data: od,
		              backgroundColor: colors(od.length)
		            }]
		          },
		          options: {
		            responsive: true,
		            maintainAspectRatio: false,
		            plugins: {
		              legend: { position: 'right' },
		              tooltip: {
		                callbacks: {
		                  label: function (ctx) {
		                    try {
		                      var total = (ctx.dataset && ctx.dataset.data || []).reduce(function (a, b) { return a + (+b || 0); }, 0);
		                      var v = ctx.parsed || 0;
		                      var pct = total ? ((v / total) * 100).toFixed(1) : 0;
		                      return (ctx.label || '') + ': ' + v + ' (' + pct + '%)';
		                    } catch (e) {
		                      return (ctx.label || '') + ': ' + (ctx.parsed || 0);
		                    }
		                  }
		                }
		              }
		            }
		          }
		        });
		      }
		    }
		    // Countries
		    var cEl = document.getElementById('countries-chart');
		    if (cEl && charts.countries) {
		      var _unk = (window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.unknown:'Unknown';
		      var cl = charts.countries.map(i=>i.country_name||i.country||_unk);
		      var cd = charts.countries.map(i=>i.count||0);
		      new Chart(cEl, {
		        type: 'bar',
		        data: { labels: cl, datasets: [{ label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartCountries)||'Countries'), data: cd, backgroundColor: colors(cd.length) }] },
		        plugins: [optibehaviorValueLabelsPlugin],
		        options: {
		          indexAxis: 'y',
		          responsive: true,
		          maintainAspectRatio: false,
		          layout: { padding: { top: 8, right: 64, bottom: 8, left: 12 } },
		          plugins: { legend: { display: false } },
		          elements: { bar: { borderRadius: 6, borderSkipped: false } },
		          scales: {
		            x: { display: false, grid: { display: false, drawBorder: false } },
		            y: { ticks: { autoSkip: false, color: '#6b7280', font: { size: 12 } }, grid: { display: false } }
		          }
		        }
		      });

				// Referrers (new)
				var rEl = document.getElementById('referrers-chart');
				if (rEl && charts.referrers) {
				  var rl = charts.referrers.map(function(i){return i.referrer||((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.directNone:'Direct / None')});
				  var rd = charts.referrers.map(function(i){return i.count||0});

				  // Store favicon URLs for later use
				  window.referrersFaviconData = charts.referrers.map(function(i) {
				    return {
				      referrer: i.referrer || ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.directNone:'Direct / None'),
				      domain: i.domain || '',
				      favicon_url: i.favicon_url || ''
				    };
				  });

				  new Chart(rEl, {
				    type: 'bar',
				    data: { labels: rl, datasets: [{ label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartReferrers)||'Referrers'), data: rd, backgroundColor: colors(rd.length) }] },
            plugins: (window.optibehaviorValueLabelsPlugin ? [window.optibehaviorValueLabelsPlugin] : []),
				    options: {
				      indexAxis: 'y',
				      responsive: true,
				      maintainAspectRatio: false,
				      layout: { padding: { top: 8, right: 64, bottom: 8, left: 12 } },
				      plugins: {
				        legend: { display: false },
				        tooltip: {
				          callbacks: {
				            label: function(context) {
				              return context.dataset.label + ': ' + context.parsed.x;
				            }
				          }
				        }
				      },
				      elements: { bar: { borderRadius: 6, borderSkipped: false } },
				      scales: {
				        x: {
				          grid: { display: false, drawBorder: false },
				          display: false
				        },
				        y: {

				          ticks: {
				            color: '#6b7280',
				            font: { size: 12 },
				            padding: 8,
				            callback: function(value, index, ticks) {
				              // Return label with extra padding for favicon
				              return '     ' + this.getLabelForValue(value);
				            }
				          },
				          grid: { display: false }
				        }
				      }
				    }
				  });

				  // Add favicons to the chart after rendering
				  setTimeout(function() {
				    var canvas = rEl;
				    var chartContainer = canvas.parentElement;

				    // Remove any existing favicon overlay
				    var existingOverlay = chartContainer.querySelector('.referrer-favicon-overlay');
				    if (existingOverlay) existingOverlay.parentNode.removeChild(existingOverlay);

				    // Create overlay container
				    var overlay = document.createElement('div');
				    overlay.className = 'referrer-favicon-overlay';
				    overlay.style.cssText = 'position: absolute; top: 0; left: 0; pointer-events: none;';

				    // Get chart instance to calculate positions
				    var chart = Chart.getChart(rEl);
				    if (chart && window.referrersFaviconData) {
				      var scale = chart.scales.y;
				      var faviconData = window.referrersFaviconData;

				      faviconData.forEach(function(item, index) {
				        if (item.favicon_url) {
				          var y = scale.getPixelForValue(index);

				          // Create favicon image with fallback
				          var img = document.createElement('img');
				          img.src = item.favicon_url;
				          img.style.cssText = 'position: absolute; left: 12px; top: ' + (y - 8) + 'px; width: 16px; height: 16px; border-radius: 2px;';
				          img.onerror = function() {
				            // Fallback to default icon
				            this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIHZpZXdCb3g9IjAgMCAxNiAxNiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8cmVjdCB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIHJ4PSIyIiBmaWxsPSIjRTVFN0VCIi8+CiAgPHBhdGggZD0iTTggM0M1LjIzODU4IDMgMyA1LjIzODU4IDMgOEMzIDEwLjc2MTQgNS4yMzg1OCAxMyA4IDEzQzEwLjc2MTQgMTMgMTMgMTAuNzYxNCAxMyA4QzEzIDUuMjM4NTggMTAuNzYxNCAzIDggM1pNOCA0LjVDOC41NTIyOCA0LjUgOSA0Ljk0NzcyIDkgNS41QzkgNi4wNTIyOCA4LjU1MjI4IDYuNSA4IDYuNUM3LjQ0NzcyIDYuNSA3IDYuMDUyMjggNyA1LjVDNyA0Ljk0NzcyIDcuNDQ3NzIgNC41IDggNC41Wk05LjUgMTAuNUg2LjVWOUg3LjVWOEg4LjVWMTBIOC41VjkuNUg5LjVWMTAuNVoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+';
				          };

				          overlay.appendChild(img);
				        }
				      });

				      chartContainer.style.position = 'relative';
				      chartContainer.appendChild(overlay);
				    }
				  }, 100);
				}

		    }
		  }


  window.optibehavior_initCharts = tryInit;
  window.initDeviceTypesWidget = initDeviceTypesWidget;
  window.initOperatingSystemsWidget = initOperatingSystemsWidget;

		  tryInit();
		})();


			// Global helper: show a modern empty-state inside a widget canvas container based on type
			window.optibehavior_showEmptyStateForCanvas = function(id, type, message, force){
				try{
					var i18n = window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n ? window.opti_behaviorDashboard.i18n : {};
					var cv = document.getElementById(id); if(!cv) return;
					var wrap = cv.parentElement || cv; if(!wrap) return;
					var empty = wrap.querySelector('.optibehavior-empty-state');
					// Map widget types to Lucide icon names (matching widget header icons)
					var lucideIcons = {
						'sessions': 'chart-spline',
						'browsers': 'compass',
						'os': 'monitor',
						'countries': 'globe',
						'referrers': 'link',
						'device': 'smartphone',
						'resolution': 'monitor-dot'
					};
					if(!empty){
						empty = document.createElement('div');
						empty.className = 'optibehavior-empty-state';
						var title = (message || i18n.noDataYet || 'No data yet');
						var iconName = lucideIcons[type] || lucideIcons['sessions'];
						var iconElement = document.createElement('i');
						iconElement.setAttribute('data-lucide', iconName);
						iconElement.style.width = '48px';
						iconElement.style.height = '48px';
						iconElement.style.color = '#9ca3af';
						iconElement.style.strokeWidth = '1.5';
						empty.appendChild(iconElement);
						var titleDiv = document.createElement('div');
						titleDiv.className = 'optibehavior-empty-title';
						titleDiv.textContent = title;
						empty.appendChild(titleDiv);
						var subDiv = document.createElement('div');
						subDiv.className = 'optibehavior-empty-sub';
						subDiv.textContent = i18n.tryBroadeningDateRange || 'Try broadening the date range or check back later.';
						empty.appendChild(subDiv);
						wrap.appendChild(empty);
						// Initialize Lucide icons
						if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
							lucide.createIcons();
						}
					}
					function getInst(el){
						var C = window.Chart; var inst = null;
						if (C && typeof C.getChart === 'function'){
							try { inst = C.getChart(el) || C.getChart(el.id); } catch(_) {}
						}
						if (!inst && C && C.instances){
							try{
								var insts = C.instances;
								if (Array.isArray(insts)){
									for (var i=0;i<insts.length;i++){ var c = insts[i]; if (!c) continue; var cand=c.chart||c; if (cand && (cand.canvas===el || cand.id===el.id)) { inst=cand; break; } }
								} else {
									var keys = Object.keys(insts);
									for (var k=0;k<keys.length;k++){ var c = insts[keys[k]]; if (!c) continue; var cand=c.chart||c; if (cand && (cand.canvas===el || cand.id===el.id)) { inst=cand; break; } }
								}
							}catch(_){ }
						}
						if (!inst){
							var map = { 'sessions-chart': (window.sessionsChart||null), 'browsers-chart': (window.browsersBar||null), 'os-chart': (window.osPie||null), 'countries-chart': (window.countriesBar||null), 'referrers-chart': (window.referrersBar||null) };
							inst = map[id] || null;
						}
						return inst;
					}
					var inst = getInst(cv);
					// force=true: caller KNOWS the current dataset is empty (e.g. a
					// filtered reload returned no rows). Destroy any stale chart so the
					// old unfiltered drawing cannot survive and suppress the empty state.
					if (force && inst) {
						try { if (typeof inst.destroy === 'function') { inst.destroy(); } } catch(_) {}
						inst = null;
					}
					var has = false;
					try{
						if(inst && inst.data){
							var dsets = Array.isArray(inst.data.datasets) ? inst.data.datasets : [];
							for (var d=0; d<dsets.length && !has; d++){
								var ds = (dsets[d] && dsets[d].data) ? dsets[d].data : [];
								for (var j=0; j<ds.length; j++){ var v = Number(ds[j]) || 0; if (v > 0){ has = true; break; } }
							}
							if (!has){
								var labels = inst.data.labels || [];
								if (labels.length && dsets.some(function(x){ return x && x.data && x.data.length; })){
									has = true; // avoid flicker when zero-only but chart is rendered
								}
							}
						}
					}catch(e){}
					cv.style.display = has ? '' : 'none';
					empty.style.display = has ? 'none' : 'flex';
					// Hide any sibling table containers (for OS widget, etc.) when showing empty state
					if (!has) {
						var tableContainers = wrap.querySelectorAll('.os-table-container, .resolution-table-container');
						for (var i = 0; i < tableContainers.length; i++) {
							tableContainers[i].style.display = 'none';
						}
					}
				}catch(e){}
			};

// Ensure OS pie empty-state syncs AFTER charts initialize and after late CSS/layout settles
(function(){
  try{
    var attempts = 0; var max = 16; // ~4.8s @300ms
    var iv = setInterval(function(){
      attempts++;
      try{ if (window.optibehavior_showEmptyStateForCanvas) { optibehavior_showEmptyStateForCanvas('os-chart','os'); } else if (typeof ensureEmptyStateForCanvas==='function'){ ensureEmptyStateForCanvas('os-chart'); } }catch(e){}
      if (attempts>=max){ clearInterval(iv); }
    }, 300);
    var el = document.getElementById('os-chart');
    if (el){ el.addEventListener('mouseenter', function(){ try{ if(window.optibehavior_showEmptyStateForCanvas){ optibehavior_showEmptyStateForCanvas('os-chart','os'); } }catch(e){} }); }
  }catch(e){}
})();

// Device Types widget initialization is now handled by the master initDeviceTypesWidget() function


		// Guard: ensure charts render after navigation/cached restores
		(function(){
		  var attempts = 0, maxAttempts = 20; // ~10s total
		  function hasData(el){
		    try { var inst = (typeof Chart!=='undefined') ? Chart.getChart(el) : null; return !!(inst && inst.data && inst.data.datasets && inst.data.datasets[0] && (inst.data.datasets[0].data||[]).length); } catch(e){ return false; }
		  }
		  function buildCharts(){
		    try {
		      var dash = window.opti_behaviorData && window.opti_behaviorData.dashboard ? window.opti_behaviorData.dashboard : null;
		      var charts = dash && dash.charts ? dash.charts : {};
		      // Device Types - retry initialization in case data is now available
		      if (typeof initDeviceTypesWidget === 'function') {
		        initDeviceTypesWidget();
		      }
		      // Operating Systems - retry initialization in case data is now available
		      if (typeof initOperatingSystemsWidget === 'function') {
		        initOperatingSystemsWidget();
		      }
		      // Browsers
		      var brEl = document.getElementById('browsers-chart');
		      if (brEl) {
		        (function(){ try{ var inst = Chart.getChart(brEl); var items = (charts.browsers||[]).filter(function(i){ var n=(i&&i.browser)?String(i.browser).trim():''; return n; }); var bl = items.map(function(i){ return i.browser; }); var bd = items.map(function(i){ return i.count||0; }); if (!bl.length) return; if(inst){ inst.data.labels = bl; inst.data.datasets[0].data = bd; inst.data.datasets[0].backgroundColor = colors(bd.length); inst.update('none'); } else { new Chart(brEl, { type:'bar', data:{ labels: bl, datasets:[{ label:((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartBrowsers)||'Browsers'), data: bd, backgroundColor: colors(bd.length) }] }, plugins: (window.optibehaviorValueLabelsPlugin ? [window.optibehaviorValueLabelsPlugin] : []), options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, layout:{ padding:{ top:8, right:64, bottom:8, left:12 } }, plugins:{ legend:{ display:false } }, elements:{ bar:{ borderRadius:6, borderSkipped:false } }, scales:{ x:{ display:false, grid:{ display:false, drawBorder:false } }, y:{  ticks:{ color:'#6b7280', font:{ size:12 } }, grid:{ display:false } } } } }); } } catch(e){} })();
		      }
			  function colors(n){ var base=['#6366F1','#10B981','#F59E0B','#EF4444','#3B82F6','#8B5CF6','#14B8A6','#F472B6','#84CC16','#06B6D4']; return Array.from({length:n}, function(_,i){ return base[i%base.length]; }); }

		      // OS
		      var osEl = document.getElementById('os-chart');
		      if (osEl) {
		        (function () {
          try {
            var inst = Chart.getChart(osEl);
            var items = (charts.operating_systems || []).filter(function (i) {
              var n = (i && i.os) ? String(i.os).trim() : '';
              var l = n.toLowerCase();
              return n && l !== 'unknown' && l !== 'undefined' && l !== 'other';
            });
            var ol = items.map(function (i) { return i.os; });
            var od = items.map(function (i) { return i.count || 0; });
            if (!ol.length) return;
            if (inst) {
              inst.data.labels = ol;
              inst.data.datasets[0].data = od;
              inst.data.datasets[0].backgroundColor = colors(od.length);
              inst.update('none');
            } else {
              new Chart(osEl, {
                type: 'pie',
                data: {
                  labels: ol,
                  datasets: [{
                    label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartOperatingSystems)||'Operating Systems'),
                    data: od,
                    backgroundColor: colors(od.length)
                  }]
                },
                options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: {
                    legend: { position: 'right' },
                    tooltip: {
                      callbacks: {
                        label: function (ctx) {
                          try {
                            var total = (ctx.dataset && ctx.dataset.data || []).reduce(function (a, b) { return a + (+b || 0); }, 0);
                            var v = ctx.parsed || 0;
                            var pct = total ? ((v / total) * 100).toFixed(1) : 0;
                            return (ctx.label || '') + ': ' + v + ' (' + pct + '%)';
                          } catch (e) {
                            return (ctx.label || '') + ': ' + (ctx.parsed || 0);
                          }
                        }
                      }
                    }
                  }
                }
              });
            }
          } catch (e) { }
        })();
		      }
		      // Countries
		      var cEl = document.getElementById('countries-chart');

		      if (cEl) {
		        (function(){ try{ var inst = Chart.getChart(cEl); var cl = (charts.countries||[]).map(function(i){ return i.country_name||i.country||((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.unknown:'Unknown'); }); var cd = (charts.countries||[]).map(function(i){ return i.count||0; }); if (!cl.length) return; if(inst){ inst.data.labels = cl; inst.data.datasets[0].data = cd; inst.data.datasets[0].backgroundColor = colors(cd.length); inst.update('none'); } else { new Chart(cEl, { type:'bar', data:{ labels: cl, datasets:[{ label:((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartCountries)||'Countries'), data: cd, backgroundColor: colors(cd.length) }] }, plugins: (window.optibehaviorValueLabelsPlugin ? [window.optibehaviorValueLabelsPlugin] : []), options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, layout:{ padding:{ top:8, right:64, bottom:8, left:12 } }, plugins:{ legend:{ display:false } }, elements:{ bar:{ borderRadius:6, borderSkipped:false } }, scales:{ x:{ display:false, grid:{ display:false, drawBorder:false } }, y:{ ticks:{ autoSkip:false, color:'#6b7280', font:{ size:12 } }, grid:{ display:false } } } } }); } } catch(e){} })();
		      }
			      // Referrers
			      var rEl = document.getElementById('referrers-chart');
			      if (rEl) {
			        (function(){ try{ var inst = Chart.getChart(rEl); var rItems = (charts.referrers||[]); var rl = rItems.map(function(i){ return i.referrer || ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.directNone:'Direct / None'); }); var rd = rItems.map(function(i){ return i.count || 0; }); if (!rl.length) return; if(inst){ inst.data.labels = rl; inst.data.datasets[0].data = rd; inst.data.datasets[0].backgroundColor = colors(rd.length); inst.update('none'); } else { new Chart(rEl, { type:'bar', data:{ labels: rl, datasets:[{ label:((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartReferrers)||'Referrers'), data: rd, backgroundColor: colors(rd.length) }] }, plugins: (window.optibehaviorValueLabelsPlugin ? [window.optibehaviorValueLabelsPlugin] : []), options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, layout:{ padding:{ top:8, right:64, bottom:8, left:12 } }, plugins:{ legend:{ display:false } }, elements:{ bar:{ borderRadius:6, borderSkipped:false } }, scales:{ x:{ display:false, grid:{ display:false, drawBorder:false }, display:false }, y:{  ticks:{ color:'#6b7280', font:{ size:12 } }, grid:{ display:false } } } } }); } } catch(e){} })();
			      // Show empty-states immediately while awaiting data (device-types-pie handled by master function)
			      try{
			        var typeMap = {'sessions-chart':'sessions','browsers-chart':'browsers','os-chart':'os','countries-chart':'countries','referrers-chart':'referrers','screen-resolution-chart':'resolution'};
			        Object.keys(typeMap).forEach(function(cid){ if(window.optibehavior_showEmptyStateForCanvas){ optibehavior_showEmptyStateForCanvas(cid, typeMap[cid]||'sessions'); } });
			      }catch(e){}

			      }

		      // Initialize stat history mini-charts (bar charts in stat cards)
		      if (typeof window.initStatHistoryCharts === 'function') {
		        window.initStatHistoryCharts();
		      }

		    } catch(err) { /* swallow to retry */ }
		  }
		  function ensure(){
		    if (!document.getElementById('opti-behavior-dashboard-root') && !document.querySelector('.dashboard-grid')) return; // wrong page
		    if (attempts++ > maxAttempts) return;
		    if (typeof Chart==='undefined'){ setTimeout(ensure, 500); return; }
		    // If we don't have dashboard data yet (e.g., returned from another page), fetch it then build
		    var dash = (window.opti_behaviorData && window.opti_behaviorData.dashboard) ? window.opti_behaviorData.dashboard : null;
		    var charts = dash && dash.charts ? dash.charts : null;
		    var hasAnyData = !!(charts && ((charts.device_types&&charts.device_types.length) || (charts.browsers&&charts.browsers.length) || (charts.operating_systems&&charts.operating_systems.length) || (charts.countries&&charts.countries.length) || (charts.referrers&&charts.referrers.length)));
		    if (!hasAnyData){
		      // Load every widget LIVE in parallel via the per-widget '&widget=' path instead
		      // of firing the slow no-widget monolith (get_dashboard_data_impl ~= 28 serial
		      // aggregations). The loader is single-flight guarded, so repeated ensure()
		      // ticks / bfcache restores cannot stack concurrent batches.
		      if (typeof window.optiBehaviorLoadAllWidgets === 'function') {
		        window.optiBehaviorLoadAllWidgets();
		      }
		      setTimeout(ensure, 800);
		      return;
		    }
		    var needsInit = false;
		    var ids = ['sessions-chart','browsers-chart','os-chart','countries-chart','referrers-chart'];
		    for (var i=0;i<ids.length;i++){
		      var el = document.getElementById(ids[i]);
		      if (el && !hasData(el)) { needsInit = true; break; }
		    }

			    // Reflect empty-states for all known chart widgets (device-types-pie handled by master function)
			    try{
			      var typeMap = {'sessions-chart':'sessions','browsers-chart':'browsers','os-chart':'os','countries-chart':'countries','referrers-chart':'referrers','screen-resolution-chart':'resolution'};
			      var all = ['sessions-chart','browsers-chart','os-chart','countries-chart','referrers-chart','screen-resolution-chart'];
			      all.forEach(function(cid){ if(window.optibehavior_showEmptyStateForCanvas){ optibehavior_showEmptyStateForCanvas(cid, typeMap[cid]||'sessions'); } });
			    }catch(e){}

		    if (needsInit) { buildCharts(); setTimeout(ensure, 500); }
		  }
		  window.addEventListener('pageshow', function(){ attempts=0; setTimeout(ensure, 300); });
		  document.addEventListener('visibilitychange', function(){ if (!document.hidden){ attempts=0; setTimeout(ensure, 300); } });
		  // Initial guard after load
		  setTimeout(ensure, 600);
		})();

		// Keep dashboard export links synchronized with the visible filters.
		(function() {
			function normalizeDashboardExportFlag(value) {
				return (value === true || value === '1' || value === 1) ? '1' : '0';
			}

			function getDashboardExportFilterState(overrides) {
				overrides = overrides || {};

				var params = new URLSearchParams(window.location.search);
				var periodEl = document.getElementById('dashboard-period');
				var startEl = document.getElementById('start-date');
				var endEl = document.getElementById('end-date');
				var excludeSpamBtn = document.getElementById('exclude-spam-toggle');
				var data = window.opti_behaviorData || {};

				var period = overrides.period || overrides.periodKey || (periodEl && periodEl.value) || data.period || params.get('period') || 'last30days';
				var startDate = overrides.startDate !== undefined ? overrides.startDate : (overrides.start_date !== undefined ? overrides.start_date : ((startEl && startEl.value) || data.startDate || params.get('start_date') || ''));
				var endDate = overrides.endDate !== undefined ? overrides.endDate : (overrides.end_date !== undefined ? overrides.end_date : ((endEl && endEl.value) || data.endDate || params.get('end_date') || ''));
				var excludeSpam = '0';

				if (overrides.excludeSpam !== undefined || overrides.exclude_spam !== undefined) {
					excludeSpam = normalizeDashboardExportFlag(overrides.excludeSpam !== undefined ? overrides.excludeSpam : overrides.exclude_spam);
				} else if (typeof window.optiBehaviorCurrentExcludeSpamFlag === 'function') {
					excludeSpam = window.optiBehaviorCurrentExcludeSpamFlag();
				} else if (excludeSpamBtn) {
					excludeSpam = (excludeSpamBtn.classList.contains('active') || excludeSpamBtn.getAttribute('aria-pressed') === 'true') ? '1' : '0';
				} else if (data.excludeSpam !== undefined) {
					excludeSpam = normalizeDashboardExportFlag(data.excludeSpam);
				} else {
					excludeSpam = window.optiBehaviorCurrentExcludeSpamFlag();
				}

				return {
					period: period,
					startDate: startDate,
					endDate: endDate,
					excludeSpam: excludeSpam
				};
			}

			function updateDashboardExportLinks(overrides) {
				var links = document.querySelectorAll('.dashboard-export-link[data-export-format]');
				if (!links.length) {
					return;
				}

				var state = getDashboardExportFilterState(overrides);

				window.opti_behaviorData = window.opti_behaviorData || {};
				window.opti_behaviorData.period = state.period;
				window.opti_behaviorData.startDate = state.startDate;
				window.opti_behaviorData.endDate = state.endDate;
				window.opti_behaviorData.excludeSpam = state.excludeSpam;

				links.forEach(function(link) {
					var format = link.getAttribute('data-export-format') || '';
					var menu = link.closest ? link.closest('.dashboard-export-menu') : null;
					var scope = link.getAttribute('data-export-scope') || (menu ? menu.getAttribute('data-export-scope') : '') || '';
					var href = link.getAttribute('href') || '';

					try {
						var url = new URL(href, window.location.origin);
						scope = scope || url.searchParams.get('scope') || 'dashboard';
						if (scope === 'full_raw_traffic') {
							scope = 'raw_traffic';
						}
						if (scope !== 'raw_traffic') {
							scope = 'dashboard';
						}

						url.searchParams.set('period', state.period);
						url.searchParams.set('start_date', state.startDate);
						url.searchParams.set('end_date', state.endDate);
						url.searchParams.set('exclude_spam', state.excludeSpam);
						url.searchParams.set('scope', scope);

						if (format) {
							url.searchParams.set('format', format);
						}

						link.setAttribute('href', url.toString());
					} catch (error) {
						if (window.OptiBehaviorDebug) {
							window.OptiBehaviorDebug.warning('Unable to synchronize dashboard export link', 'dashboard', error);
						}
					}
				});
			}

			function bindDashboardExportMenu() {
				var menus = document.querySelectorAll('.dashboard-export-menu[data-export-scope="dashboard"]');
				if (!menus.length) {
					return;
				}

				menus.forEach(function(menu) {
					if (menu.getAttribute('data-export-menu-bound') === '1') {
						return;
					}

					var button = menu.querySelector('.dashboard-export-button');
					var dropdown = menu.querySelector('.dashboard-export-dropdown');

					if (!button || !dropdown) {
						return;
					}

					menu.setAttribute('data-export-menu-bound', '1');

					function getItems() {
						return Array.prototype.slice.call(dropdown.querySelectorAll('.dashboard-export-link[href]'));
					}

					function closeMenu() {
						menu.classList.remove('is-open');
						button.setAttribute('aria-expanded', 'false');
						dropdown.hidden = true;
					}

					function openMenu() {
						menu.classList.add('is-open');
						button.setAttribute('aria-expanded', 'true');
						dropdown.hidden = false;
					}

					function toggleMenu() {
						if (menu.classList.contains('is-open')) {
							closeMenu();
						} else {
							openMenu();
						}
					}

					button.addEventListener('click', function(event) {
						event.preventDefault();
						event.stopPropagation();
						toggleMenu();
					});

					button.addEventListener('keydown', function(event) {
						var firstItem;

						if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
							event.preventDefault();
							openMenu();
							firstItem = getItems()[0];

							if (firstItem) {
								firstItem.focus();
							}
						}
					});

					dropdown.addEventListener('keydown', function(event) {
						var items = getItems();
						var currentIndex = items.indexOf(document.activeElement);
						var nextIndex;

						if (event.key === 'Escape') {
							event.preventDefault();
							closeMenu();
							button.focus();
							return;
						}

						if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
							return;
						}

						event.preventDefault();

						if (!items.length) {
							return;
						}

						nextIndex = event.key === 'ArrowDown' ? currentIndex + 1 : currentIndex - 1;

						if (nextIndex < 0) {
							nextIndex = items.length - 1;
						} else if (nextIndex >= items.length) {
							nextIndex = 0;
						}

						items[nextIndex].focus();
					});

					dropdown.addEventListener('click', function() {
						closeMenu();
					});

					document.addEventListener('click', function(event) {
						if (!menu.contains(event.target)) {
							closeMenu();
						}
					});

					document.addEventListener('keydown', function(event) {
						if (event.key === 'Escape') {
							closeMenu();
						}
					});
				});
			}

			function bindDashboardExportLinkSync() {
				bindDashboardExportMenu();
				updateDashboardExportLinks();

				var periodEl = document.getElementById('dashboard-period');
				var startEl = document.getElementById('start-date');
				var endEl = document.getElementById('end-date');
				var applyBtn = document.getElementById('apply-range');
				var refreshBtn = document.getElementById('refresh-dashboard');
				var excludeSpamBtn = document.getElementById('exclude-spam-toggle');

				if (periodEl) {
					periodEl.addEventListener('change', function() {
						var period = this.value || 'last30days';
						updateDashboardExportLinks({
							period: period,
							startDate: period === 'custom' && startEl ? startEl.value : '',
							endDate: period === 'custom' && endEl ? endEl.value : ''
						});
					});
				}

				[startEl, endEl].forEach(function(dateEl) {
					if (!dateEl) {
						return;
					}

					dateEl.addEventListener('input', function() {
						updateDashboardExportLinks();
					});
					dateEl.addEventListener('change', function() {
						updateDashboardExportLinks();
					});
				});

				if (applyBtn) {
					applyBtn.addEventListener('click', function() {
						updateDashboardExportLinks({
							period: 'custom',
							startDate: startEl ? startEl.value : '',
							endDate: endEl ? endEl.value : ''
						});
					});
				}

				if (refreshBtn) {
					refreshBtn.addEventListener('click', function() {
						updateDashboardExportLinks();
					});
				}

				if (excludeSpamBtn) {
					excludeSpamBtn.addEventListener('click', function() {
						setTimeout(updateDashboardExportLinks, 0);
					});
				}
			}

			window.optibehaviorUpdateDashboardExportLinks = updateDashboardExportLinks;

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', bindDashboardExportLinkSync);
			} else {
				bindDashboardExportLinkSync();
			}
		})();

		// Date range wiring
		document.getElementById('apply-range')?.addEventListener('click', function(){
			const s = document.getElementById('start-date')?.value;
			const e = document.getElementById('end-date')?.value;
			if(!s || !e){ alert(((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.selectStartEndDates)||'Please select start and end dates.')); return; }
			const ps = document.getElementById('dashboard-period');
			if(ps) ps.value = 'custom';
			const params = new URLSearchParams(window.location.search);
			params.set('period','custom'); params.set('start_date', s); params.set('end_date', e);
			// Preserve exclude_spam state
			if (window.optiBehaviorCurrentExcludeSpamFlag() === '1') {
				params.set('exclude_spam', '1');
			} else {
				params.set('exclude_spam', '0');
			}
			if (window.optibehaviorUpdateDashboardExportLinks) {
				window.optibehaviorUpdateDashboardExportLinks({
					period: 'custom',
					startDate: s,
					endDate: e,
					excludeSpam: window.optiBehaviorCurrentExcludeSpamFlag()
				});
			}
			const newUrl = window.location.pathname + '?' + params.toString();
			window.location.href = newUrl; // full page reload with selected range
		});

		document.getElementById('dashboard-period')?.addEventListener('change', function(){
			const period = this.value;
			if(period !== 'custom'){
				const params = new URLSearchParams(window.location.search);
				params.set('period', period); params.delete('start_date'); params.delete('end_date');
				params.set('exclude_spam', window.optiBehaviorCurrentExcludeSpamFlag());
				if (window.optibehaviorUpdateDashboardExportLinks) {
					window.optibehaviorUpdateDashboardExportLinks({
						period: period,
						startDate: '',
						endDate: '',
						excludeSpam: window.optiBehaviorCurrentExcludeSpamFlag()
					});
				}
				const newUrl = window.location.pathname + '?' + params.toString();
				window.location.href = newUrl; // full page reload for preset periods
			}
		});

		function optiBehaviorTrafficOverviewCount(value) {
			const numericValue = Number(value);
			return Number.isFinite(numericValue) && numericValue > 0 ? numericValue : 0;
		}

		function optiBehaviorNormalizeTrafficOverviewData(chartData) {
			if (!Array.isArray(chartData)) {
				return [];
			}

			return chartData.map(function(item) {
				const point = item && typeof item === 'object' ? item : {};

				return {
					date: point.date === undefined || point.date === null ? '' : String(point.date),
					visitors: optiBehaviorTrafficOverviewCount(point.visitors),
					sessions: optiBehaviorTrafficOverviewCount(point.sessions),
					pageviews: optiBehaviorTrafficOverviewCount(point.pageviews)
				};
			});
		}

		function optiBehaviorTrimTrafficOverviewLeadingEmptyDays(chartData) {
			if (!Array.isArray(chartData)) {
				return [];
			}

			const firstTrafficIndex = chartData.findIndex(function(point) {
				if (!point || typeof point !== 'object') {
					return false;
				}

				return point.visitors > 0 || point.sessions > 0 || point.pageviews > 0;
			});

			return firstTrafficIndex === -1 ? [] : chartData.slice(firstTrafficIndex);
		}

		// Custom ranges are explicit user choices - never shorten their X axis.
		function optiBehaviorIsCustomDashboardPeriod() {
			var period = (window.opti_behaviorData && window.opti_behaviorData.period) || '';
			return period === 'custom';
		}

		function optiBehaviorApplyTrafficOverviewZeroOriginOptions(chart) {
			if (!chart || !chart.options || !chart.options.scales) {
				return;
			}

			const xScaleOptions = chart.options.scales.x;
			const yScaleOptions = chart.options.scales.y;

			if (xScaleOptions) {
				xScaleOptions.offset = false;
				if (xScaleOptions.grid) {
					xScaleOptions.grid.offset = false;
				}
			}

			if (yScaleOptions) {
				yScaleOptions.beginAtZero = true;
				yScaleOptions.min = 0;
			}
		}

		// Initialize Traffic Overview Chart (Chart.js v4.5.0 compatible)
		(function initTrafficOverviewChart() {
			// Wait for both DOM and Chart.js to be ready
			function initChart() {
				if (typeof Chart === 'undefined') {
					setTimeout(initChart, 100);
					return;
				}

				const ctx = document.getElementById('sessions-chart');
				if (!ctx) {
					setTimeout(initChart, 100);
					return;
				}

				// Prevent duplicate initialization
				if (window.sessionsChart) {
					return;
				}

				const normalizedInitialChartData = optiBehaviorNormalizeTrafficOverviewData((window.opti_behaviorData && window.opti_behaviorData.dashboard && window.opti_behaviorData.dashboard.charts && window.opti_behaviorData.dashboard.charts.sessions_chart) ? window.opti_behaviorData.dashboard.charts.sessions_chart : []);
				const chartData = optiBehaviorIsCustomDashboardPeriod() ? normalizedInitialChartData : optiBehaviorTrimTrafficOverviewLeadingEmptyDays(normalizedInitialChartData);
				const labels = chartData.map(item => item.date);
				const sessions = chartData.map(item => item.sessions);
				const visitors = chartData.map(item => item.visitors);
				const pageviews = chartData.map(item => item.pageviews);

				// Create gradient fills for better visual appeal
				const createGradient = (ctx, color1, color2) => {
					const gradient = ctx.createLinearGradient(0, 0, 0, 400);
					gradient.addColorStop(0, color1);
					gradient.addColorStop(1, color2);
					return gradient;
				};

				// Create the chart with enhanced professional options
				window.sessionsChart = new Chart(ctx, {
					type: 'line',
					data: {
						labels: labels,
						datasets: [
							{
								label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.visitorsLabel:'Visitors'),
								data: visitors,
								borderColor: '#6366F1',
								backgroundColor: createGradient(ctx.getContext('2d'), 'rgba(99, 102, 241, 0.2)', 'rgba(99, 102, 241, 0.01)'),
								borderWidth: 3,
								fill: true,
								tension: 0.4,
								pointRadius: 4,
								pointHoverRadius: 8,
								pointBackgroundColor: '#6366F1',
								pointBorderColor: '#fff',
								pointBorderWidth: 2,
								pointHoverBackgroundColor: '#6366F1',
								pointHoverBorderColor: '#fff',
								pointHoverBorderWidth: 3,
								segment: {
									borderColor: ctx => ctx.p0.skip || ctx.p1.skip ? 'rgba(99, 102, 241, 0.3)' : '#6366F1',
								}
							},
							{
								label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.sessionsLabel:'Sessions'),
								data: sessions,
								borderColor: '#F59E0B',
								backgroundColor: createGradient(ctx.getContext('2d'), 'rgba(245, 158, 11, 0.2)', 'rgba(245, 158, 11, 0.01)'),
								borderWidth: 3,
								fill: true,
								tension: 0.4,
								pointRadius: 4,
								pointHoverRadius: 8,
								pointBackgroundColor: '#F59E0B',
								pointBorderColor: '#fff',
								pointBorderWidth: 2,
								pointHoverBackgroundColor: '#F59E0B',
								pointHoverBorderColor: '#fff',
								pointHoverBorderWidth: 3,
								segment: {
									borderColor: ctx => ctx.p0.skip || ctx.p1.skip ? 'rgba(245, 158, 11, 0.3)' : '#F59E0B',
								}
							},
							{
								label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.pageViewsLabel:'Page Views'),
								data: pageviews,
								borderColor: '#10B981',
								backgroundColor: createGradient(ctx.getContext('2d'), 'rgba(16, 185, 129, 0.2)', 'rgba(16, 185, 129, 0.01)'),
								borderWidth: 3,
								fill: true,
								tension: 0.4,
								pointRadius: 4,
								pointHoverRadius: 8,
								pointBackgroundColor: '#10B981',
								pointBorderColor: '#fff',
								pointBorderWidth: 2,
								pointHoverBackgroundColor: '#10B981',
								pointHoverBorderColor: '#fff',
								pointHoverBorderWidth: 3,
								segment: {
									borderColor: ctx => ctx.p0.skip || ctx.p1.skip ? 'rgba(16, 185, 129, 0.3)' : '#10B981',
								}
							}
						]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						interaction: {
							mode: 'index',
							intersect: false
						},
						layout: {
							padding: {
								top: 20,
								right: 30,
								bottom: 15,
								left: 30
							}
						},
						plugins: {
							legend: {
								display: true,
								position: 'top',
								align: 'end',
								labels: {
									usePointStyle: true,
									pointStyle: 'circle',
									padding: 15,
									font: {
										size: 12,
										weight: '600',
										family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
									},
									color: '#1f2937',
									boxWidth: 10,
									boxHeight: 10,
									generateLabels: function(chart) {
										const datasets = chart.data.datasets;
										return datasets.map((dataset, i) => ({
											text: dataset.label,
											fillStyle: dataset.borderColor,
											strokeStyle: dataset.borderColor,
											lineWidth: 2,
											hidden: !chart.isDatasetVisible(i),
											index: i,
											pointStyle: 'circle'
										}));
									}
								}
							},
							tooltip: {
								enabled: true,
								mode: 'index',
								intersect: false,
								backgroundColor: 'rgba(17, 24, 39, 0.96)',
								titleFont: {
									size: 14,
									weight: '700',
									family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
								},
								titleColor: '#ffffff',
								bodyFont: {
									size: 13,
									weight: '600',
									family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
								},
								bodyColor: '#e5e7eb',
								padding: 16,
								cornerRadius: 10,
								displayColors: true,
								boxWidth: 14,
								boxHeight: 14,
								boxPadding: 8,
								borderColor: 'rgba(255, 255, 255, 0.15)',
								borderWidth: 1,
								caretSize: 8,
								caretPadding: 12,
								callbacks: {
									title: function(context) {
										return '📅 ' + (context[0].label || '');
									},
									label: function(context) {
										let label = context.dataset.label || '';
										if (label) {
											label += ': ';
										}
										label += context.parsed.y.toLocaleString();
										return label;
									}
								}
							}
						},
						scales: {
							y: {
								beginAtZero: true,
								min: 0,
								grace: '5%',
								grid: {
									color: function(context) {
										if (context.tick.value === 0) {
											return 'rgba(107, 114, 128, 0.3)';
										}
										return 'rgba(229, 231, 235, 0.5)';
									},
									drawBorder: false,
									lineWidth: function(context) {
										if (context.tick.value === 0) {
											return 2;
										}
										return 1;
									}
								},
								border: {
									display: false,
									dash: [5, 5]
								},
								ticks: {
									font: {
										size: 12,
										weight: '600',
										family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
									},
									color: '#6b7280',
									padding: 12,
									callback: function(value) {
										if (value >= 1000) {
											return (value / 1000).toFixed(1) + 'k';
										}
										return value.toLocaleString();
									}
								}
							},
							x: {
								offset: false,
								grid: {
									display: true,
									color: 'rgba(229, 231, 235, 0.3)',
									drawBorder: false,
									lineWidth: 1,
									offset: false
								},
								border: {
									display: false
								},
								ticks: {
									font: {
										size: 11,
										weight: '600',
										family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
									},
									color: '#6b7280',
									padding: 10,
									maxRotation: 0,
									minRotation: 0,
									autoSkip: true,
									maxTicksLimit: 10
								}
							}
						},
						animation: {
							duration: 1200,
							easing: 'easeInOutCubic',
							onComplete: function() {
								// Animation complete callback
							}
						},
						hover: {
							mode: 'index',
							intersect: false,
							animationDuration: 200
						}
					}
				});
				optiBehaviorApplyTrafficOverviewZeroOriginOptions(window.sessionsChart);
			}

			// Start initialization
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initChart);
			} else {
				initChart();
			}
		})();

		// Device types pie + new widgets
		(function initCharts(){
			  if (typeof Chart === 'undefined') { setTimeout(initCharts, 300); return; }
			  // Helpers
			  function colors(n){ const base=['#6366F1','#10B981','#F59E0B','#EF4444','#3B82F6','#8B5CF6','#14B8A6','#F472B6','#84CC16','#06B6D4']; return Array.from({length:n}, (_,i)=>base[i%base.length]); }
			  // DEVICE TYPES PIE - handled by master initDeviceTypesWidget() function
			  // BROWSERS BAR
			  const brEl = document.getElementById('browsers-chart');
			  if (brEl && window.opti_behaviorData && window.opti_behaviorData.dashboard) {
			    const items = (window.opti_behaviorData.dashboard.charts?.browsers)||[];
			    // Aggregate duplicate labels (e.g., multiple 'Unknown' buckets)
			    const labelMap = new Map();
			    for (const it of items) {
			      let key = (it && it.browser) ? String(it.browser).trim() : '';
			      key = key ? key : 'Unknown';
			      const low = key.toLowerCase();
			      if (low === 'unknown' || low === 'undefined') key = 'Unknown';
			      labelMap.set(key, (labelMap.get(key) || 0) + Number(it?.count || 0));
			    }
			    const labels = Array.from(labelMap.keys());
			    const data = Array.from(labelMap.values());
			    // Destroy existing chart if it exists
			    const existingChart = Chart.getChart(brEl);
			    if (existingChart) existingChart.destroy();
			    new Chart(brEl, {
			      type: 'bar',
			      data: { labels, datasets: [{ label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartBrowsers)||'Browsers'), data, backgroundColor: colors(data.length) }] }, plugins: (window.optibehaviorValueLabelsPlugin ? [window.optibehaviorValueLabelsPlugin] : []),
			      options: { indexAxis: 'y',
			        responsive: true,
			        maintainAspectRatio: false,
			        layout: { padding: { top: 8, right: 64, bottom: 8, left: 12 } },
			        plugins: { legend: { display: false } },
			        elements: { bar: { borderRadius: 6, borderSkipped: false } },
			        scales: {
			          x: { display: false,
			            grid: { display: false, drawBorder: false },
			            ticks: { autoSkip: false, maxRotation: 45, minRotation: 45, align: 'end', color: '#6b7280', font: { size: 12 } }
			          },
			          y: {

			            ticks: { color: '#6b7280', font: { size: 12 } },
			            grid: { display: false }
			          }
			        }
			      }
			    });
			  }
			  // OS PIE (prefer server-provided operating_systems; fallback to deriving from visitors)
			  const osEl = document.getElementById('operating-systems-chart');
			  if (osEl && window.opti_behaviorData && window.opti_behaviorData.dashboard) {
			    const osItems = (window.opti_behaviorData.dashboard.charts?.operating_systems)||[];
			    const filtered = osItems.filter(i=>{ const s=(i?.os||'').trim().toLowerCase(); return s && s!=='unknown' && s!=='undefined' && s!=='other'; });
			    let labels = filtered.map(i=>i.os);
			    let data = filtered.map(i=>i.count||0);
			    if (!labels.length && window.opti_behaviorData.dashboard.visitors) {
			      const arr = window.opti_behaviorData.dashboard.visitors || [];
			      const map = {};
			      arr.forEach(v=>{ const raw=(v.os||''); const k=raw.trim(); const lk=k.toLowerCase(); if(!k||lk==='unknown'||lk==='undefined'||lk==='other') return; map[k]=(map[k]||0)+1; });
			      labels = Object.keys(map);
			      data = labels.map(l=>map[l]);
			    }
			    if (labels.length) {
      // Destroy existing chart if it exists
      const existingOsChart = Chart.getChart(osEl);
      if (existingOsChart) existingOsChart.destroy();
      new Chart(osEl, {
        type: 'doughnut',
        data: {
          labels,
          datasets: [{
            label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartOperatingSystems)||'Operating Systems'),
            data,
            backgroundColor: colors(data.length),
            borderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '70%',
          plugins: {
            legend: { display: false }, // Hide Chart.js legend - using custom HTML legend instead
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  try {
                    var total = (ctx.dataset && ctx.dataset.data || []).reduce(function (a, b) { return a + (+b || 0); }, 0);
                    var v = ctx.parsed || 0;
                    var pct = total ? ((v / total) * 100).toFixed(1) : 0;
                    return (ctx.label || '') + ': ' + v + ' (' + pct + '%)';
                  } catch (e) {
                    return (ctx.label || '') + ': ' + (ctx.parsed || 0);
                  }
                }
              }
            }
          }
        }
      });
    }
			  }
			  // COUNTRIES HORIZONTAL BAR
			  const cEl = document.getElementById('countries-chart');
			  if (cEl && window.opti_behaviorData && window.opti_behaviorData.dashboard) {
			    const items = (window.opti_behaviorData.dashboard.charts?.countries)||[];
			    const _unk2 = (window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.unknown:'Unknown';
			    const labels = items.map(i=>i.country_name||i.country||_unk2);
			    const data = items.map(i=>i.count||0);
			    // Destroy existing chart if it exists
			    const existingCountriesChart = Chart.getChart(cEl);
			    if (existingCountriesChart) existingCountriesChart.destroy();
			    new Chart(cEl, {
			      type: 'bar',
			      data: { labels, datasets: [{ label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartCountries)||'Countries'), data, backgroundColor: colors(data.length) }] },
			      plugins: (window.optibehaviorValueLabelsPlugin ? [window.optibehaviorValueLabelsPlugin] : []),
			      options: {
			        indexAxis: 'y',
			        responsive: true,
			        maintainAspectRatio: false,
			        layout: { padding: { top: 8, right: 64, bottom: 8, left: 12 } },
			        plugins: { legend: { display: false } },
			        elements: { bar: { borderRadius: 6, borderSkipped: false } },
			        scales: {
			          x: { display: false, grid: { display: false, drawBorder: false } },
			          y: { ticks: { autoSkip: false, color: '#6b7280', font: { size: 12 } }, grid: { display: false } }
			        }
			      }
			    });
			  }
			  // Referrers (fallback init)
			  const rEl = document.getElementById('referrers-chart');
			  if (rEl && window.opti_behaviorData && window.opti_behaviorData.dashboard) {
			    const items = (window.opti_behaviorData.dashboard.charts?.referrers)||[];
			    const labels = items.map(i=>i.referrer||((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.directNone:'Direct / None'));
			    const data = items.map(i=>i.count||0);

			    // Store favicon URLs for later use
			    window.referrersFaviconData = items.map(i => ({
			      referrer: i.referrer || ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.directNone:'Direct / None'),
			      domain: i.domain || '',
			      favicon_url: i.favicon_url || ''
			    }));

			    // Destroy existing chart if it exists
			    const existingReferrersChart = Chart.getChart(rEl);
			    if (existingReferrersChart) existingReferrersChart.destroy();

			    new Chart(rEl, {
      plugins: (window.optibehaviorValueLabelsPlugin ? [window.optibehaviorValueLabelsPlugin] : []),
			      type: 'bar',
			      data: { labels, datasets: [{ label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartReferrers)||'Referrers'), data, backgroundColor: colors(data.length) }] },
			      options: {
        indexAxis: 'y',
			        responsive: true,
			        maintainAspectRatio: false,
			        layout: { padding: { top: 8, right: 64, bottom: 8, left: 12 } },
			        plugins: {
			          legend: { display: false },
			          tooltip: {
			            callbacks: {
			              label: function(context) {
			                return context.dataset.label + ': ' + context.parsed.x;
			              }
			            }
			          }
			        },
			        elements: { bar: { borderRadius: 6, borderSkipped: false } },
			        scales: {
			          x: {
			            grid: { display: false, drawBorder: false },
			            display: false
			          },
			          y: {
			            ticks: {
			              color: '#6b7280',
			              font: { size: 12 },
			              padding: 8,
			              callback: function(value, index, ticks) {
			                // Return label with extra padding for favicon
			                return '     ' + this.getLabelForValue(value);
			              }
			            },
			            grid: { display: false }
			          }
			        }
			      }
			    });

			    // Add favicons to the chart after rendering
			    setTimeout(() => {
			      const canvas = rEl;
			      const chartContainer = canvas.parentElement;

			      // Remove any existing favicon overlay
			      const existingOverlay = chartContainer.querySelector('.referrer-favicon-overlay');
			      if (existingOverlay) existingOverlay.remove();

			      // Create overlay container
			      const overlay = document.createElement('div');
			      overlay.className = 'referrer-favicon-overlay';
			      overlay.style.cssText = 'position: absolute; top: 0; left: 0; pointer-events: none;';

			      // Get chart instance to calculate positions
			      const chart = Chart.getChart(rEl);
			      if (chart && window.referrersFaviconData) {
			        const scale = chart.scales.y;
			        const faviconData = window.referrersFaviconData;

			        faviconData.forEach((item, index) => {
			          if (item.favicon_url) {
			            const y = scale.getPixelForValue(index);

			            // Create favicon image with fallback
			            const img = document.createElement('img');
			            img.src = item.favicon_url;
			            img.style.cssText = `position: absolute; left: 12px; top: ${y - 8}px; width: 16px; height: 16px; border-radius: 2px;`;
			            img.onerror = function() {
			              // Fallback to default icon
			              this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIHZpZXdCb3g9IjAgMCAxNiAxNiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8cmVjdCB3aWR0aD0iMTYiIGhlaWdodD0iMTYiIHJ4PSIyIiBmaWxsPSIjRTVFN0VCIi8+CiAgPHBhdGggZD0iTTggM0M1LjIzODU4IDMgMyA1LjIzODU4IDMgOEMzIDEwLjc2MTQgNS4yMzg1OCAxMyA4IDEzQzEwLjc2MTQgMTMgMTMgMTAuNzYxNCAxMyA4QzEzIDUuMjM4NTggMTAuNzYxNCAzIDggM1pNOCA0LjVDOC41NTIyOCA0LjUgOSA0Ljk0NzcyIDkgNS41QzkgNi4wNTIyOCA4LjU1MjI4IDYuNSA4IDYuNUM3LjQ0NzcyIDYuNSA3IDYuMDUyMjggNyA1LjVDNyA0Ljk0NzcyIDcuNDQ3NzIgNC41IDggNC41Wk05LjUgMTAuNUg2LjVWOUg3LjVWOEg4LjVWMTBIOC41VjkuNUg5LjVWMTAuNVoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+';
			            };

			            overlay.appendChild(img);
			          }
			        });

			        chartContainer.style.position = 'relative';
			        chartContainer.appendChild(overlay);
			      }
			    }, 100);
			  }

			  // USER INTENT PIE - Update legend table
			  function updateUserIntentLegend() {
			    const tbody = document.getElementById('user-intent-legend-body');
			    if (!tbody) return;

			    // Get data from opti_behaviorData
			    const intentData = window.opti_behaviorData?.dashboard?.user_intent || {};

			    const lowSessions = intentData.low_sessions || 0;
			    const mediumSessions = intentData.medium_sessions || 0;
			    const highSessions = intentData.high_sessions || 0;
			    const lowPct = intentData.low_percentage || 0;
			    const mediumPct = intentData.medium_percentage || 0;
			    const highPct = intentData.high_percentage || 0;
			    const prevLowSessions = intentData.prev_low_sessions || 0;
			    const prevMediumSessions = intentData.prev_medium_sessions || 0;
			    const prevHighSessions = intentData.prev_high_sessions || 0;

			    // Helper function to create trend HTML
			    function getTrendHTML(currentCount, previousCount) {
			      let trendClass = 'trend-neutral';
			      let trendIcon = '';
			      let trendValue = 0;

			      if (previousCount > 0) {
			        trendValue = Math.round(((currentCount - previousCount) / previousCount) * 100);
			        if (trendValue > 0) {
			          trendClass = 'trend-up';
			          trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
			        } else if (trendValue < 0) {
			          trendClass = 'trend-down';
			          trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
			        } else {
			          trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
			        }
			      } else if (currentCount > 0) {
			        trendValue = 100;
			        trendClass = 'trend-up';
			        trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
			      } else {
			        trendValue = 0;
			        trendClass = 'trend-neutral';
			        trendIcon = '';
			      }

			      return `<span class="visitor-trend ${trendClass}">${trendIcon}${Math.abs(trendValue)}%</span>`;
			    }

			    // Create rows for each intent type
			    const i18n = window.opti_behaviorData?.strings || {};
			    const intentTypes = [
			      { name: i18n.lowIntent || 'Low intent', count: lowSessions, percentage: lowPct, prevCount: prevLowSessions, color: '#e91e63' },
			      { name: i18n.mediumIntent || 'Medium intent', count: mediumSessions, percentage: mediumPct, prevCount: prevMediumSessions, color: '#7c3aed' },
			      { name: i18n.highIntent || 'High intent', count: highSessions, percentage: highPct, prevCount: prevHighSessions, color: '#f59e0b' }
			    ];

			    tbody.innerHTML = '';
			    intentTypes.forEach(type => {
			      const row = document.createElement('tr');
			      row.innerHTML = `
			        <td class="intent-type-cell">
			          <span>
			            <span class="intent-color-dot" style="background-color: ${type.color};"></span>
			            <span class="intent-type-name">${type.name}</span>
			          </span>
			        </td>
			        <td class="intent-stats-cell">
			          <span class="visitor-count">${type.count}</span>
			          <span class="visitor-percentage">${type.percentage}%</span>
			          ${getTrendHTML(type.count, type.prevCount)}
			        </td>
			      `;
			      tbody.appendChild(row);
			    });
			  }

			  // USER INTENT PIE - Initialization function
			  function initUserIntentWidget() {
			    const intentEl = document.getElementById('user-intent-chart');
			    if (!intentEl) return;

			    // Update the legend table
			    updateUserIntentLegend();

			    // Get intent data from opti_behaviorData
			    const intentData = window.opti_behaviorData?.dashboard?.user_intent || {};
			    const lowSessions = intentData.low_sessions || 0;
			    const mediumSessions = intentData.medium_sessions || 0;
			    const highSessions = intentData.high_sessions || 0;

			    const labels = [];
			    const data = [];
			    const backgroundColors = ['#e91e63', '#7c3aed', '#f59e0b']; // low, medium, high
			    const i18n = window.opti_behaviorData?.strings || {};

			    if (lowSessions > 0) {
			      labels.push(i18n.lowIntent || 'Low intent');
			      data.push(lowSessions);
			    }
			    if (mediumSessions > 0) {
			      labels.push(i18n.mediumIntent || 'Medium intent');
			      data.push(mediumSessions);
			    }
			    if (highSessions > 0) {
			      labels.push(i18n.highIntent || 'High intent');
			      data.push(highSessions);
			    }

			    if (labels.length > 0) {
			      // Restore canvas if a previous empty state hid it
			      intentEl.style.display = '';
			      // Hide empty state if it exists (data is available)
			      const container = intentEl.parentElement;
			      if (container) {
			        const emptyState = container.querySelector('.optibehavior-empty-state');
			        if (emptyState) {
			          emptyState.style.display = 'none';
			        }
			      }

			      // Destroy existing chart if it exists
			      const existingIntentChart = Chart.getChart(intentEl);
			      if (existingIntentChart) existingIntentChart.destroy();

			      new Chart(intentEl, {
			        type: 'doughnut',
			        data: {
			          labels: labels,
			          datasets: [{
			              label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartUserIntent)||'User Intent'),
			              data: data,
			              backgroundColor: backgroundColors.slice(0, data.length),
			              borderWidth: 0
			            }]
			          },
			          options: {
			            responsive: true,
			            maintainAspectRatio: false,
			            cutout: '70%',
			            plugins: {
			              legend: { display: false }, // Hide Chart.js legend - using custom HTML legend instead
			              tooltip: {
			                callbacks: {
			                  label: function(ctx) {
			                    try {
			                      const total = (ctx.dataset && ctx.dataset.data || []).reduce((a, b) => a + (+b || 0), 0);
			                      const v = ctx.parsed || 0;
			                      const pct = total ? ((v / total) * 100).toFixed(1) : 0;
			                      return (ctx.label || '') + ': ' + v + ' (' + pct + '%)';
			                    } catch(e) {
			                      return (ctx.label || '') + ': ' + (ctx.parsed || 0);
			                    }
			                  }
			                }
			              }
			            }
			          }
			        });
			      } else {
			      // No sessions in current payload (e.g. filtered result is empty):
			      // destroy any stale donut and show the empty state instead of
			      // leaving the previous render visible.
			      const staleIntentChart = (typeof Chart !== 'undefined') ? Chart.getChart(intentEl) : null;
			      if (staleIntentChart) staleIntentChart.destroy();
			      if (window.optibehavior_showEmptyStateForCanvas) {
			        window.optibehavior_showEmptyStateForCanvas('user-intent-chart', 'sessions', ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.noDataYet)||'No data available'), true);
			      }
			      }
			  }

			  // Initialize on page load
			  initUserIntentWidget();

		// ========================================
		  // NEW VS RETURNING VISITORS CHART
		  // ========================================

		  // Update new vs returning visitors legend
		  function updateNewVsReturningLegend(data) {
		    const tbody = document.getElementById('new-vs-returning-legend-body');
		    if (!tbody || !data) return;

		    // Clear loading state
		    tbody.innerHTML = '';

		    // Get current period data
		    const newCount = (data.new_visitors) || 0;
		    const returningCount = (data.returning_visitors) || 0;
		    const total = data.total || 0;

		    // Get previous period data (if available)
		    const prevNewCount = (data.previous_new_visitors) || 0;
		    const prevReturningCount = (data.previous_returning_visitors) || 0;

		    // Calculate percentages
		    const newPct = total > 0 ? Math.round((newCount / total) * 100) : 0;
		    const returningPct = total > 0 ? Math.round((returningCount / total) * 100) : 0;

		    // Helper function to create trend HTML
		    function getTrendHTML(currentCount, previousCount) {
		      let trendClass = 'trend-neutral';
		      let trendIcon = '';
		      let trendValue = 0;

		      if (previousCount > 0) {
		        trendValue = Math.round(((currentCount - previousCount) / previousCount) * 100);
		        if (trendValue > 0) {
		          trendClass = 'trend-up';
		          trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
		        } else if (trendValue < 0) {
		          trendClass = 'trend-down';
		          trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
		        } else {
		          trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
		        }
		      } else if (currentCount > 0) {
		        // New data - show as positive trend
		        trendValue = 100;
		        trendClass = 'trend-up';
		        trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
		      } else {
		        // Both current and previous are 0 - show neutral
		        trendValue = 0;
		        trendClass = 'trend-neutral';
		        trendIcon = '';
		      }

		      return `<span class="visitor-trend ${trendClass}">${trendIcon}${Math.abs(trendValue)}%</span>`;
		    }

		    // Create rows for each visitor type
		    const i18n = window.opti_behaviorData?.strings || {};
		    const visitorTypes = [
		      { name: i18n.newVisitors || 'New Visitors', count: newCount, percentage: newPct, prevCount: prevNewCount, color: '#10B981' },
		      { name: i18n.returningVisitors || 'Returning Visitors', count: returningCount, percentage: returningPct, prevCount: prevReturningCount, color: '#6366F1' }
		    ];

		    visitorTypes.forEach(type => {
		      const row = document.createElement('tr');
		      row.innerHTML = `
		        <td class="traffic-type-cell">
		          <span>
		            <span class="traffic-color-dot" style="background-color: ${type.color};"></span>
		            <span class="traffic-type-name">${type.name}</span>
		          </span>
		        </td>
		        <td class="traffic-stats-cell">
		          <span class="visitor-count">${type.count}</span>
		          <span class="visitor-percentage">${type.percentage}%</span>
		          ${getTrendHTML(type.count, type.prevCount)}
		        </td>
		      `;
		      tbody.appendChild(row);
		    });
		  }

		  function initNewVsReturningWidget() {
		    if (typeof Chart === 'undefined') {
		      setTimeout(initNewVsReturningWidget, 300);
		      return;
		    }

		    const ctx = document.getElementById('new-vs-returning-chart');
		    if (!ctx) return;

		    const data = window.opti_behaviorData;
		    if (!data || !data.dashboard || !data.dashboard.new_vs_returning) return;

		    const visitorData = data.dashboard.new_vs_returning;

		    // Check if we have data
		    const newCount = visitorData.new_visitors || 0;
		    const returningCount = visitorData.returning_visitors || 0;
		    const total = visitorData.total || 0;

		    if (total === 0) {
		      // Hide chart container if no data
		      const widget = document.getElementById('new-vs-returning-widget');
		      if (widget) {
		        const emptyState = widget.querySelector('.optibehavior-empty-state');
		        if (emptyState) emptyState.classList.add('is-visible');

		        const chartContainer = widget.querySelector('.user-intent-chart-container');
		        if (chartContainer) chartContainer.style.display = 'none';
		      }
		      if (typeof window.hideWidgetLoading === 'function') {
		        window.hideWidgetLoading('new-vs-returning-widget');
		      }
		      return;
		    }

		    // Show chart container
		    const widget = document.getElementById('new-vs-returning-widget');
		    if (widget) {
		      const emptyState = widget.querySelector('.optibehavior-empty-state');
		      if (emptyState) emptyState.classList.remove('is-visible');

		      const chartContainer = widget.querySelector('.user-intent-chart-container');
		      if (chartContainer) chartContainer.style.display = '';
		    }

		    // Update legend table
		    updateNewVsReturningLegend(visitorData);

		    // Destroy existing chart if it exists
		    const existingChart = Chart.getChart(ctx);
		    if (existingChart) existingChart.destroy();

		    // Get i18n strings
		    const i18n = window.opti_behaviorData?.strings || {};

		    // Create Chart.js donut chart
		    new Chart(ctx, {
		      type: 'doughnut',
		      data: {
		        labels: [i18n.newVisitors || 'New Visitors', i18n.returningVisitors || 'Returning Visitors'],
		        datasets: [{
		          label: (i18n.visitorsLabel || 'Visitors'),
		          data: [newCount, returningCount],
		          backgroundColor: ['#10B981', '#6366F1'],
		          borderWidth: 0
		        }]
		      },
		      options: {
		        responsive: true,
		        maintainAspectRatio: false,
		        cutout: '70%',
		        plugins: {
		          legend: { display: false },
		          tooltip: {
		            callbacks: {
		              label: function(ctx) {
		                const total = (ctx.dataset.data || []).reduce((a, b) => a + (+b || 0), 0);
		                const v = ctx.parsed || 0;
		                const pct = total ? ((v / total) * 100).toFixed(1) : 0;
		                return (ctx.label || '') + ': ' + v.toLocaleString() + ' (' + pct + '%)';
		              }
		            }
		          }
		        }
		      }
		    });

		    // Hide loading overlay
		    if (typeof window.hideWidgetLoading === 'function') {
		      window.hideWidgetLoading('new-vs-returning-widget');
		    }
		  }

		  // Expose function globally for AJAX callback
		  window.initNewVsReturningWidget = initNewVsReturningWidget;

		  // Initialize new vs returning widget on page load
		  initNewVsReturningWidget();

			  // ========================================
			  // NEW REGISTERED USERS WIDGET
			  // ========================================
			  function updateNewRegisteredUsersLegend(data) {
			    const tbody = document.getElementById('new-registered-users-legend-body');
			    if (!tbody) return;

			    tbody.innerHTML = '';

			    const totalVisitors = parseInt(data.total_visitors) || 0;
			    const loggedInVisitors = parseInt(data.logged_in_visitors) || 0;
			    const newRegistrations = parseInt(data.new_registrations) || 0;
			    const total = totalVisitors;

			    const prevTotalVisitors = parseInt(data.previous_total_visitors) || 0;
			    const prevLoggedIn = parseInt(data.previous_logged_in_visitors) || 0;
			    const prevRegistrations = parseInt(data.previous_new_registrations) || 0;

			    // Calculate percentages
			    const totalPct = 100;
			    const loggedInPct = total > 0 ? Math.round((loggedInVisitors / total) * 100) : 0;
			    const newRegPct = total > 0 ? Math.round((newRegistrations / total) * 100) : 0;

			    const i18n = window.opti_behaviorData?.strings || {};
			    const visitorTypes = [
			      {
			        name: i18n.totalVisitors || 'Total Visitors',
			        count: totalVisitors,
			        percentage: totalPct,
			        prevCount: prevTotalVisitors,
			        color: '#3B82F6'
			      },
			      {
			        name: i18n.loggedInVisitors || 'Logged In Visitors',
			        count: loggedInVisitors,
			        percentage: loggedInPct,
			        prevCount: prevLoggedIn,
			        color: '#10B981'
			      },
			      {
			        name: i18n.newRegistrations || 'New Registrations',
			        count: newRegistrations,
			        percentage: newRegPct,
			        prevCount: prevRegistrations,
			        color: '#6366F1'
			      }
			    ];

			    visitorTypes.forEach(type => {
			      const row = document.createElement('tr');
			      row.innerHTML = `
			        <td class="traffic-type-cell">
			          <span style="display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;">
			            <span class="traffic-color-dot" style="background-color: ${type.color};"></span>
			            <span class="traffic-type-name">${type.name}</span>
			          </span>
			        </td>
			        <td class="traffic-stats-cell">
			          <span class="visitor-count">${type.count}</span>
			          <span class="visitor-percentage">${type.percentage}%</span>
			          ${getTrendHTML(type.count, type.prevCount)}
			        </td>
			      `;
			      tbody.appendChild(row);
			    });
			  }

			  function initNewRegisteredUsersWidget() {
			    // Hide loading overlay first
			    if (typeof window.hideWidgetLoading === 'function') {
			      window.hideWidgetLoading('new-registered-users-widget');
			    }

			    const chartEl = document.getElementById('new-registered-users-chart');
			    if (!chartEl) return;

			    // Get data from opti_behaviorData
			    const userData = window.opti_behaviorData?.dashboard?.new_registered_users || {};

			    const totalVisitors = parseInt(userData.total_visitors) || 0;
			    const loggedInVisitors = parseInt(userData.logged_in_visitors) || 0;
			    const newRegistrations = parseInt(userData.new_registrations) || 0;
			    const total = totalVisitors;

			    if (total === 0) {
			      document.getElementById('new-registered-users-chart-container')?.style.setProperty('display', 'none');
			      document.querySelector('#new-registered-users-widget .optibehavior-empty-state')?.classList.add('is-visible');
			      return;
			    }

			    // Show chart container and hide empty state
			    document.getElementById('new-registered-users-chart-container')?.style.removeProperty('display');
			    document.querySelector('#new-registered-users-widget .optibehavior-empty-state')?.classList.remove('is-visible');

			    // Update legend table
			    updateNewRegisteredUsersLegend(userData);

			    // Destroy existing chart if it exists
			    const existingChart = Chart.getChart(chartEl);
			    if (existingChart) existingChart.destroy();

			    // Create donut chart
			    new Chart(chartEl, {
			      type: 'doughnut',
			      data: {
			        labels: ['Total Visitors', 'Logged In Visitors', 'New Registrations'],
			        datasets: [{
			          data: [totalVisitors, loggedInVisitors, newRegistrations],
			          backgroundColor: ['#3B82F6', '#10B981', '#6366F1'],
			          borderWidth: 0,
			          hoverOffset: 4
			        }]
			      },
			      options: {
			        responsive: true,
			        maintainAspectRatio: true,
			        aspectRatio: 1,
			        cutout: '70%',
			        plugins: {
			          legend: {
			            display: false
			          },
			          tooltip: {
			            enabled: true,
			            backgroundColor: 'rgba(0, 0, 0, 0.8)',
			            padding: 12,
			            titleColor: '#fff',
			            bodyColor: '#fff',
			            callbacks: {
			              label: function(context) {
			                const label = context.label || '';
			                const value = context.parsed || 0;
			                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
			                return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
			              }
			            }
			          }
			        }
			      }
			    });
			  }

			  // Expose function globally for AJAX callback
			  window.initNewRegisteredUsersWidget = initNewRegisteredUsersWidget;

			  // Initialize new registered users widget on page load
			  initNewRegisteredUsersWidget();

		  // ========================================
		  // VISITED DIRECTORIES WIDGET
		  // ========================================


	  // Update visited directories legend
	  function updateVisitedDirectoriesLegend(data) {
	    const tbody = document.getElementById('visited-directories-legend-body');
	    if (!tbody || !data || !data.directories) return;

	    // Clear loading state
	    tbody.innerHTML = '';

	    const directories = data.directories || [];
	    if (directories.length === 0) return;

	    // Calculate total sessions for percentage calculation
	    const totalSessions = directories.reduce((sum, dir) => sum + (parseInt(dir.sessions) || 0), 0);

	    // Helper function to create trend HTML
	    function getTrendHTML(changePercent) {
	      let trendClass = 'trend-neutral';
	      let trendIcon = '';
	      const change = parseInt(changePercent) || 0;

	      if (change > 0) {
	        trendClass = 'trend-up';
	        trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
	      } else if (change < 0) {
	        trendClass = 'trend-down';
	        trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
	      } else {
	        trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
	      }

	      return `<span class="visitor-trend ${trendClass}">${trendIcon}${Math.abs(change)}%</span>`;
	    }

	    // Helper function to get color based on taxonomy
	    function getDirectoryColor(taxonomy) {
	      const taxonomyColors = {
	        'home': '#3B82F6',       // Blue
	        'category': '#10B981',   // Green
	        'tag': '#F59E0B',        // Amber
	        'search': '#8B5CF6',     // Purple
	        'page': '#14B8A6',       // Teal
	        'other': '#6B7280'       // Gray
	      };

	      return taxonomyColors[taxonomy] || taxonomyColors['other'];
	    }

	    // Helper function to safely render backend-provided text in HTML.
	    function escapeHTML(value) {
	      return String(value || '')
	        .replace(/&/g, '&amp;')
	        .replace(/</g, '&lt;')
	        .replace(/>/g, '&gt;')
	        .replace(/"/g, '&quot;')
	        .replace(/'/g, '&#039;');
	    }

	    // Helper function to keep the widget on generic dashboard categories even
	    // if stale cached data still contains a content-specific slug or label.
	    function normalizeDirectoryTaxonomy(value) {
	      const normalized = String(value || '')
	        .toLowerCase()
	        .replace(/[_\s]+/g, '-')
	        .trim();

	      const aliases = {
	        'home': 'home',
	        'category': 'category',
	        'categories': 'category',
	        'product-cat': 'category',
	        'product-category': 'category',
	        'tag': 'tag',
	        'tags': 'tag',
	        'post-tag': 'tag',
	        'product-tag': 'tag',
	        'search': 'search',
	        'page': 'page',
	        'post': 'page',
	        'post-type-archive': 'page',
	        'other': 'other',
	        'unknown': 'other'
	      };

	      return aliases[normalized] || 'other';
	    }

	    // Helper function to format generic directory labels only.
	    function formatDirectoryLabel(label, fallback) {
	      const genericLabels = {
	        home: 'Home',
	        category: 'Category',
	        tag: 'Tag',
	        search: 'Search',
	        page: 'Page',
	        other: 'Other'
	      };

	      let formattedLabel = String(label || fallback || 'Other');

	      if (/%[0-9A-Fa-f]{2}/.test(formattedLabel)) {
	        try {
	          formattedLabel = decodeURIComponent(formattedLabel);
	        } catch (error) {
	          // Leave malformed percent sequences unchanged and sanitize below.
	        }
	      }

	      formattedLabel = formattedLabel
	        .replace(/[\\/]/g, ' ')
	        .replace(/[-_+]+/g, ' ')
	        .replace(/\s+/g, ' ')
	        .trim();

	      const normalizedLabel = normalizeDirectoryTaxonomy(formattedLabel);
	      const normalizedFallback = normalizeDirectoryTaxonomy(fallback);
	      const genericKey = normalizedLabel !== 'other' ? normalizedLabel : normalizedFallback;

	      return genericLabels[genericKey] || genericLabels.other;
	    }

	    // Helper function to prevent unsafe URL schemes in rendered links.
	    function normalizeDirectoryUrl(url) {
	      if (!url) {
	        return '#';
	      }

	      try {
	        const parsedUrl = new URL(String(url), window.location.origin);
	        return /^https?:$/.test(parsedUrl.protocol) ? parsedUrl.href : '#';
	      } catch (error) {
	        return '#';
	      }
	    }

	    // Create rows for each directory
	    directories.forEach(dir => {
	      const taxonomy = normalizeDirectoryTaxonomy(dir.taxonomy || dir.type || 'other');
	      const type = formatDirectoryLabel(dir.type, taxonomy);
	      const sessions = parseInt(dir.sessions) || 0;
	      const change = dir.change || 0;
	      const color = getDirectoryColor(taxonomy);
	      // Use URL from backend (already properly formatted by WordPress)
	      const directoryUrl = normalizeDirectoryUrl(dir.url || '#');

	      // Calculate percentage
	      const percentage = totalSessions > 0 ? Math.round((sessions / totalSessions) * 100) : 0;

	      const row = document.createElement('tr');
	      row.innerHTML = `
	        <td class="traffic-type-cell">
	          <span>
	            <span class="traffic-color-dot" style="background-color: ${color};"></span>
	            <a href="${escapeHTML(directoryUrl)}" class="traffic-type-name" style="color: #1f2937; text-decoration: none; font-weight: 400; font-size: 13px; line-height: 1.4; white-space: nowrap;" target="_blank" rel="noopener noreferrer">${escapeHTML(type)}</a>
	          </span>
	        </td>
	        <td class="traffic-stats-cell">
	          <span class="visitor-count">${sessions}</span>
	          <span class="visitor-percentage">${percentage}%</span>
	          ${getTrendHTML(change)}
	        </td>
	      `;
	      tbody.appendChild(row);
	    });
	  }

		  // Initialize visited directories widget
		  function initVisitedDirectoriesWidget() {
		    const data = window.opti_behaviorData;
		    if (!data || !data.dashboard || !data.dashboard.visited_directories) {
		      if (typeof window.hideWidgetLoading === 'function') {
		        window.hideWidgetLoading('visited-directories-widget');
		      }
		      return;
		    }

		    const dirData = data.dashboard.visited_directories;

		    // Check if we have data
		    const directories = dirData.directories || [];

		    if (directories.length === 0) {
		      // Hide table container if no data
		      const widget = document.getElementById('visited-directories-widget');
		      if (widget) {
		        const emptyState = widget.querySelector('.optibehavior-empty-state');
		        if (emptyState) emptyState.classList.add('is-visible');

		        const tableContainer = widget.querySelector('.visited-directories-list');
		        if (tableContainer) tableContainer.style.display = 'none';
		      }
		      if (typeof window.hideWidgetLoading === 'function') {
		        window.hideWidgetLoading('visited-directories-widget');
		      }
		      return;
		    }

		    // Show table container
		    const widget = document.getElementById('visited-directories-widget');
		    if (widget) {
		      const emptyState = widget.querySelector('.optibehavior-empty-state');
		      if (emptyState) emptyState.classList.remove('is-visible');

		      const tableContainer = widget.querySelector('.visited-directories-list');
		      if (tableContainer) tableContainer.style.display = '';
		    }

		    // Update legend table
		    updateVisitedDirectoriesLegend(dirData);

		    // Hide loading overlay
		    if (typeof window.hideWidgetLoading === 'function') {
		      window.hideWidgetLoading('visited-directories-widget');
		    }
		  }

		  // Expose function globally for AJAX callback
		  window.initVisitedDirectoriesWidget = initVisitedDirectoriesWidget;

		  // Initialize visited directories widget on page load
		  initVisitedDirectoriesWidget();

		// Refresh button functionality
		document.getElementById('refresh-dashboard')?.addEventListener('click', function() {
			// Full page reload with force_refresh=1 so the server bypasses the
			// dashboard transient cache and re-aggregates fresh numbers.
			const params = new URLSearchParams(window.location.search);
			params.set('force_refresh', '1');
			if (window.optibehaviorUpdateDashboardExportLinks) {
				window.optibehaviorUpdateDashboardExportLinks();
			}
			const newUrl = window.location.pathname + '?' + params.toString();
			window.location.href = newUrl;
		});

		// Period selector change handler
		document.getElementById('dashboard-period')?.addEventListener('change', function() {
			const period = this.value;
			if (period !== 'custom') {
				const params = new URLSearchParams(window.location.search);
				params.set('period', period); params.delete('start_date'); params.delete('end_date');
				// Preserve explicit exclude_spam state, including "0" to override the global default.
				if (window.optiBehaviorCurrentExcludeSpamFlag() === '1') {
					params.set('exclude_spam', '1');
				} else {
					params.set('exclude_spam', '0');
				}
				if (window.optibehaviorUpdateDashboardExportLinks) {
					window.optibehaviorUpdateDashboardExportLinks({
						period: period,
						startDate: '',
						endDate: '',
						excludeSpam: window.optiBehaviorCurrentExcludeSpamFlag()
					});
				}
				const newUrl = window.location.pathname + '?' + params.toString();
				window.location.href = newUrl;
			}
		});

		// Exclude Spam toggle button handler
		(function() {
			const excludeSpamBtn = document.getElementById('exclude-spam-toggle');
			if (!excludeSpamBtn) return;
			const updateExcludeSpamButtonState = function(button, isActive) {
				button.classList.toggle('active', isActive);
				button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
				button.setAttribute('aria-label', isActive ? ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.excludingSpam)||'Excluding Spam') : ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.includingSpam)||'Including Spam'));
				button.setAttribute('title', isActive ? ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.spamExcludedTitle)||'Spam traffic is excluded from all statistics') : ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.spamIncludedTitle)||'Spam traffic is included in all statistics'));
				const iconEl = button.querySelector('.filter-icon i');
				if (iconEl) iconEl.setAttribute('data-lucide', isActive ? 'shield-check' : 'shield-off');
				const labelEl = button.querySelector('.filter-label');
				if (labelEl) labelEl.textContent = isActive ? ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.excludingSpam)||'Excluding Spam') : ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.includingSpam)||'Including Spam');
			};

			// Initialize from the effective URL/button state. Explicit URL overrides
			// such as exclude_spam=0 must win over the shared default setting.
			const initialExcludeSpam = window.optiBehaviorCurrentExcludeSpamFlag() === '1';
			updateExcludeSpamButtonState(excludeSpamBtn, initialExcludeSpam);

			// Toggle handler
			excludeSpamBtn.addEventListener('click', function() {
				const isActive = !(this.classList.contains('active') || this.getAttribute('aria-pressed') === 'true');
				updateExcludeSpamButtonState(this, isActive);
				const params = new URLSearchParams(window.location.search);

				if (isActive) {
					params.set('exclude_spam', '1');
				} else {
					params.set('exclude_spam', '0');
				}

				// Reload page with new parameter
				if (window.optibehaviorUpdateDashboardExportLinks) {
					window.optibehaviorUpdateDashboardExportLinks({
						excludeSpam: isActive ? '1' : '0'
					});
				}
				const newUrl = window.location.pathname + '?' + params.toString();
				window.location.href = newUrl;
			});

			// Re-initialize Lucide icons after changing data-lucide attribute
			if (typeof lucide !== 'undefined' && lucide.createIcons) {
				lucide.createIcons();
			}
		})();

		// ============================================================
		// ADVANCED FILTERS PANEL ("Filters" toggle button)
		// ============================================================
		// FREE-side counterpart of the PRO Session Recordings advanced-filters
		// panel (opti-behavior-pro/assets/js/recording-player.js). Field set is
		// adapted to what the FREE schema/queries support (see spec.md section
		// 2.2). Applying/resetting filters does not reload the page - it clears
		// the per-signature widget load state and re-runs the same in-page
		// widget loader (window.optiBehaviorLoadAllWidgets, exposed above) so
		// the whole cascade (all 4 sections) re-fetches with/without the
		// `advanced_filters` payload.
		(function() {
			const panel = document.getElementById('advanced-filters-panel');
			const toggleBtn = document.getElementById('toggle-advanced-filters');
			if (!panel || !toggleBtn) return;

			// Maps allow-listed filter field => panel input/select element id.
			// MUST mirror get_advanced_filters_allowed_fields() in
			// class-opti-behavior-heatmap-dashboard.php.
			const FIELD_IDS = {
				browser: 'filter-browser',
				country: 'filter-country',
				device_type: 'filter-device',
				os: 'filter-os',
				visitor_type: 'filter-visitor-type',
				duration_min: 'filter-duration-min',
				duration_max: 'filter-duration-max',
				page_count_min: 'filter-page-count-min',
				page_count_max: 'filter-page-count-max',
				entry_page: 'filter-entry-page',
				exit_page: 'filter-exit-page',
				referrer: 'filter-referrer',
				traffic_channel: 'filter-traffic-channel',
				utm_campaign: 'filter-utm-campaign',
				utm_source: 'filter-utm-source',
				utm_medium: 'filter-utm-medium'
			};

			// Scope signature of the last successful options fetch. Options are
			// count-annotated and scoped to the dashboard's date range + spam
			// toggle, so a change in any of those must trigger a refetch the
			// next time the panel opens (empty string = never loaded).
			let optionsSig = '';

			// Same period/range/spam sources the widget loader reads, so option
			// counts always describe the dataset the widgets are showing.
			function filterOptionsScope() {
				return {
					period: (window.opti_behaviorData && window.opti_behaviorData.period) || 'last30days',
					startDate: (window.opti_behaviorData && window.opti_behaviorData.startDate) || '',
					endDate: (window.opti_behaviorData && window.opti_behaviorData.endDate) || '',
					excludeSpam: (typeof window.optiBehaviorCurrentExcludeSpamFlag === 'function') ? window.optiBehaviorCurrentExcludeSpamFlag() : '0'
				};
			}

			function filterOptionsScopeSig(scope) {
				return scope.period + '|' + scope.startDate + '|' + scope.endDate + '|' + scope.excludeSpam;
			}

			// Shared filter-UI helpers (withCount / populateSelect /
			// annotateStaticSelect / enhanceSuggestInput / enhanceIconSelect and
			// the icon helpers they use) moved verbatim to the shared module
			// opti-behavior-filter-ui.js (window.OptiBehaviorFilterUI), enqueued
			// as a dependency of this file. Behaviour unchanged.
			const FUI = window.OptiBehaviorFilterUI;
			if (!FUI) {
				if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('OptiBehaviorFilterUI module missing - advanced filters disabled', 'dashboard');
				return;
			}
			const withCount = FUI.withCount;
			const populateSelect = FUI.populateSelect;
			const annotateStaticSelect = FUI.annotateStaticSelect;

			// ---- Custom suggestion dropdowns (Entry Page / Exit Page / Referrer) --
			const enhanceSuggestInput = FUI.enhanceSuggestInput;

			const suggestInputs = {
				entry_pages: enhanceSuggestInput('filter-entry-page'),
				exit_pages: enhanceSuggestInput('filter-exit-page'),
				referrers: enhanceSuggestInput('filter-referrer')
			};

			function populateSuggestions(key, items) {
				if (suggestInputs[key]) suggestInputs[key].setItems(items);
			}

			// ---- Icon dropdowns (Browser / Country / Device / OS) -------------
			const enhanceIconSelect = FUI.enhanceIconSelect;

			enhanceIconSelect('filter-browser', 'browser');
			enhanceIconSelect('filter-country', 'country');
			enhanceIconSelect('filter-device', 'device');
			enhanceIconSelect('filter-os', 'os');
			enhanceIconSelect('filter-utm-campaign', 'utm');
			enhanceIconSelect('filter-utm-source', 'utm');
			enhanceIconSelect('filter-utm-medium', 'utm');

			// Populate every dropdown/datalist from live, count-aggregated data
			// (option values chosen from real rows in the DB, labels annotated
			// with per-option session counts: "Chrome (123)"). Fetched lazily on
			// panel open; refetched only when the date range / spam scope
			// changed since the last successful load (server side is transient-
			// cached too, so refetches within the TTL cost zero SQL).
			function loadFilterOptions() {
				const scope = filterOptionsScope();
				const sig = filterOptionsScopeSig(scope);
				if (optionsSig === sig) return;
				optionsSig = sig;
				const ajaxUrl = (window.opti_behaviorData && window.opti_behaviorData.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php';
				const nonce = (window.opti_behaviorData && window.opti_behaviorData.nonce) || '';
				const qs = new URLSearchParams({
					action: 'opti_behavior_get_dashboard_filter_options',
					nonce: nonce,
					period: scope.period,
					exclude_spam: scope.excludeSpam
				});
				if (scope.startDate) qs.set('start_date', scope.startDate);
				if (scope.endDate) qs.set('end_date', scope.endDate);
				fetch(ajaxUrl + '?' + qs.toString(), { method: 'GET', credentials: 'same-origin' })
					.then(function(r) { return r.json(); })
					.then(function(result) {
						// Stale-response guard: if the scope changed while this
						// request was in flight, a newer fetch owns the panel -
						// discard this payload instead of overwriting it.
						if (optionsSig !== sig) return;
						if (!result || !result.success || !result.data) return;
						const data = result.data;
						// Backward-compatible mapper: new payload items are
						// {value, count} objects; a plain-string item (old
						// cached response shape) still renders without count.
						const vc = function(item) {
							if (item && typeof item === 'object') {
								return { value: item.value, label: withCount(item.value, item.count) };
							}
							return { value: item, label: item };
						};
						populateSelect('filter-browser', data.browsers, vc);
						populateSelect('filter-country', data.countries, function(c) {
							if (!c) return null;
							return { value: c.country, label: withCount(c.country_name || c.country, c.count) };
						});
						populateSelect('filter-device', data.devices, vc);
						populateSelect('filter-os', data.os, vc);
						populateSelect('filter-utm-campaign', data.utm_campaigns, vc);
						populateSelect('filter-utm-source', data.utm_sources, vc);
						populateSelect('filter-utm-medium', data.utm_mediums, vc);
						annotateStaticSelect('filter-visitor-type', data.visitor_types, 'value');
						annotateStaticSelect('filter-traffic-channel', data.traffic_channels, 'value');
						populateSuggestions('entry_pages', data.entry_pages);
						populateSuggestions('exit_pages', data.exit_pages);
						populateSuggestions('referrers', data.referrers);
					})
					.catch(function() {
						// Allow a retry on next panel open - but only if no newer
						// fetch has taken ownership of the signature meanwhile.
						if (optionsSig === sig) optionsSig = '';
						if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Failed to load dashboard filter options', 'dashboard');
					});
			}

			function isPanelOpen() {
				return panel.style.display !== 'none';
			}

			function setPanelOpen(open) {
				panel.style.display = open ? '' : 'none';
				toggleBtn.classList.toggle('active', open);
				toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
				if (open) loadFilterOptions();
			}

			toggleBtn.addEventListener('click', function() {
				setPanelOpen(!isPanelOpen());
			});

			function updateToggleBadge(count) {
				let badge = toggleBtn.querySelector('.filter-active-badge');
				if (count > 0) {
					if (!badge) {
						badge = document.createElement('span');
						badge.className = 'filter-active-badge';
						toggleBtn.appendChild(badge);
					}
					badge.textContent = String(count);
				} else if (badge) {
					badge.remove();
				}
			}

			// Since 1.8.1.2 the server computes ALL six daily_history series live
			// when a filter is active (traffic from the filtered timeseries,
			// time/scroll/bounce from get_dashboard_filtered_metric_history), so
			// the sparklines always show real filtered data and are never dimmed.
			// The class is still cleared here in case it lingers from a previous
			// build. See dashboard_styles.css
			// `.dashboard-stats.advanced-filters-active .stat-history`.
			function updateSparklineDimming(active) {
				document.querySelectorAll('.dashboard-stats').forEach(function(el) {
					el.classList.remove('advanced-filters-active');
				});
			}

			// Re-fetch every widget in-place (no page reload) with the current
			// window.optiBehaviorAdvancedFilters payload. Clears the per-signature
			// load-state guard first so the (now filter-tagged) sig is treated as
			// a fresh request, then re-runs the same parallel/sectioned loader
			// used at initial page load.
			function reloadFilteredWidgets() {
				if (typeof window.optiBehaviorResetWidgetLoadState === 'function') {
					window.optiBehaviorResetWidgetLoadState();
				}
				updateSparklineDimming(Object.keys(window.optiBehaviorAdvancedFilters || {}).length > 0);
				if (typeof window.optiBehaviorLoadAllWidgets === 'function') {
					window.optiBehaviorLoadAllWidgets();
				}
			}

			const applyBtn = document.getElementById('apply-advanced-filters');
			if (applyBtn) {
				applyBtn.addEventListener('click', function() {
					const filters = {};
					Object.keys(FIELD_IDS).forEach(function(field) {
						const el = document.getElementById(FIELD_IDS[field]);
						if (!el) return;
						// Multi-select fields (icon dropdowns) send an array of the
						// picked values; a single pick stays a plain string so the
						// payload matches the original scalar contract.
						if (el.tagName === 'SELECT' && el.multiple) {
							const vals = Array.prototype.filter.call(el.options, function(o) {
								return o.selected && ('' + o.value).trim() !== '';
							}).map(function(o) { return ('' + o.value).trim(); });
							if (vals.length === 1) {
								filters[field] = vals[0];
							} else if (vals.length > 1) {
								filters[field] = vals;
							}
							return;
						}
						const val = ('' + (el.value || '')).trim();
						if (val !== '') filters[field] = val;
					});
					window.optiBehaviorAdvancedFilters = filters;
					updateToggleBadge(Object.keys(filters).length);
					reloadFilteredWidgets();
					setPanelOpen(false);
				});
			}

			const resetBtn = document.getElementById('reset-advanced-filters');
			if (resetBtn) {
				resetBtn.addEventListener('click', function() {
					Object.keys(FIELD_IDS).forEach(function(field) {
						const el = document.getElementById(FIELD_IDS[field]);
						if (el) {
							if (el.tagName === 'SELECT' && el.multiple) {
								Array.prototype.forEach.call(el.options, function(o) { o.selected = false; });
							} else {
								el.value = '';
							}
							// Icon dropdowns mirror the hidden select; refresh their button.
							if (typeof el._obIconSync === 'function') el._obIconSync();
						}
					});
					window.optiBehaviorAdvancedFilters = {};
					updateToggleBadge(0);
					reloadFilteredWidgets();
				});
			}
		})();

		// Safe auto-refresh for real-time visitors every 5 seconds with guards.
		// PERF: poll the LIGHTWEIGHT widget=realtime endpoint (active_visitors +
		// recent_sessions) instead of the no-widget call, which ran the heavy
		// get_dashboard_stats_only() monolith (full KPI cards + daily history) every
		// 5s. On live that cost 3-26s per tick and competed with the cascade for
		// PHP/DB. Also hold off polling until list 1 is ready so the realtime tick
		// never steals resources from the first-paint widgets.
		(function(){
			let optibehaviorRefreshing = false;
			// Safety net: never strand the realtime tick if the cascade-complete signal is
			// missed (JS error in a widget, etc.). Releases the poller after 120s regardless.
			setTimeout(function(){ window.optiBehaviorCascadeComplete = true; }, 120000);
			setInterval(function(){
				if (document.hidden) return;
				// Hold the poller until the ENTIRE cascade (lists 1-4) has loaded, not just
				// list 1. Polling during lists 2-4 caused a feedback loop on shared hosting:
				// the 5s realtime tick stole DB/PHP-FPM from the cascade (and vice versa),
				// inflating both to 8-22s. Once the cascade is done the tick runs alone (~0.5s).
				if (!window.optiBehaviorCascadeComplete) return;
				if (optibehaviorRefreshing) return;
				optibehaviorRefreshing = true;
				const period = document.getElementById('dashboard-period')?.value || 'last30days';
				const excludeSpam = window.optiBehaviorCurrentExcludeSpamFlag();
				fetch(ajaxurl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'action=optibehavior_dashboard_data&widget=realtime&period=' + encodeURIComponent(period) + '&nonce=' + encodeURIComponent(opti_behaviorData?.nonce || '') + '&exclude_spam=' + excludeSpam
				})
				.then(r => r.json())
				.then(data => {
					if (data && data.success && data.data) {
						// widget=realtime returns { active_visitors, recent_sessions }.
						var av = data.data.active_visitors || (data.data.realtime && data.data.realtime.active_visitors) || [];
						updateRealtimeVisitors(av);
					}
				})
				.catch(() => {})
				.finally(() => { optibehaviorRefreshing = false; });
			}, 5000);
		})();

		function refreshDashboard(period) {
			// Manual Refresh = explicit force-reload at the SAME date-range, so the
			// per-signature widget load state would otherwise mark everything as
			// already-loaded and block a fresh fetch on any subsequent loader run.
			// Clear it (plus in-flight entries) so the dashboard re-fetches live.
			if (typeof window.optiBehaviorResetWidgetLoadState === 'function') {
				window.optiBehaviorResetWidgetLoadState();
			}
			// Show loading state
			const refreshBtn = document.getElementById('refresh-dashboard');
			if (refreshBtn) {
				refreshBtn.disabled = true;
				refreshBtn.innerHTML = '<span class="refresh-icon">⏳</span> Loading...';
			}

			const s = document.getElementById('start-date')?.value || '';
			const e = document.getElementById('end-date')?.value || '';
			const urlParams = new URLSearchParams(window.location.search);
			const excludeSpam = window.optiBehaviorCurrentExcludeSpamFlag();
			fetch(ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: 'action=optibehavior_dashboard_data&period=' + encodeURIComponent(period) + '&start_date=' + encodeURIComponent(s) + '&end_date=' + encodeURIComponent(e) + '&nonce=' + (opti_behaviorData?.nonce || '') + '&exclude_spam=' + excludeSpam + (Object.keys(window.optiBehaviorAdvancedFilters || {}).length > 0 ? '&advanced_filters=' + encodeURIComponent(JSON.stringify(window.optiBehaviorAdvancedFilters)) : '')
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					updateDashboardData(data.data);
				}
				// Refresh the collapsed-section teaser chips with live values too.
				if (window.OptiBehaviorSectionSummaries && typeof window.OptiBehaviorSectionSummaries.load === 'function') {
					window.OptiBehaviorSectionSummaries.load();
				}
			})
			.catch(error => {
				// console.error('Dashboard refresh failed:', error);
			})
			.finally(() => {
				// Reset button state
				if (refreshBtn) {
					refreshBtn.disabled = false;
					refreshBtn.innerHTML = '<span class="refresh-icon"><i data-lucide="refresh-cw"></i></span> Refresh';
					if (typeof lucide !== 'undefined') lucide.createIcons();
				}
			});
		}

		function fmtMMSS(sec){ sec = Math.max(0, parseInt(sec||0,10)); var m = Math.floor(sec/60), s = sec%60; return m+':'+(s<10?('0'+s):s); }
		function updateDashboardData(data) {
			// Update statistics values
			if (data.stats) {
				updateStatCard('visitors', data.stats.visitors);
				updateStatCard('sessions', data.stats.sessions);
				updateStatCard('pageviews', data.stats.pageviews);
				updateStatCard('avg_session_time', fmtMMSS(data.stats.avg_session_time));
				if (typeof data.stats.avg_scroll_depth !== 'undefined') {
					updateStatCard('avg_scroll_depth', (data.stats.avg_scroll_depth||0) + '%');
				}
				updateStatCard('bounce_rate', (data.stats.bounce_rate||0) + '%');
			}

			// Update change badges if provided
			if (data.changes) {
				const upd = (key, delta) => { try {
					const card = document.querySelector('.stat-card[data-stat="' + key + '"]') || Array.from(document.querySelectorAll('.stat-card')).find(c => (c.querySelector('.stat-label')?.textContent||'').toLowerCase().includes(key.replace(/_/g,'')) );
					if (!card) return;
					const el = card.querySelector('.stat-change'); if (!el) return;
					const d = Math.round(delta||0);
					const i18n = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
					const vsLastPeriod = i18n.vsLastPeriod || '% vs last period';
					el.textContent = (d>0?'+':'') + d + vsLastPeriod;
					el.classList.remove('positive','negative','neutral');
					// Bounce rate is an inverted metric: an increase is bad (red), a decrease is good (green).
					const goodDir = key === 'bounce_rate' ? -d : d;
					el.classList.add(goodDir>0?'positive':(goodDir<0?'negative':'neutral'));
				} catch(e){} };
				upd('visitors', data.changes.visitors);
				upd('sessions', data.changes.sessions);
				upd('pageviews', data.changes.pageviews);
				upd('avg_session_time', data.changes.avg_session_time);
				upd('avg_scroll_depth', data.changes.avg_scroll_depth);
				upd('bounce_rate', data.changes.bounce_rate);
			}

			// Update real-time visitors
			if (data.realtime && data.realtime.active_visitors) {
				updateRealtimeVisitors(data.realtime.active_visitors);
			}


			// Update browsers bar
			if (data.charts && data.charts.browsers) {
				updateBrowsers(data.charts.browsers);
			}

			// Update operating systems bar
			if (data.charts && (data.charts.operating_systems || data.visitors)) {
				updateOperatingSystems(data.charts.operating_systems || []);
			}

			// Update countries bar (horizontal)
			if (data.charts && data.charts.countries) {
				updateCountries(data.charts.countries);
			}

			// Update top pages
			if (data.charts && data.charts.top_pages) {
				updateTopPagesEnhanced(data.charts.top_pages);
			}
			// Update referrers
			if (data.charts && data.charts.referrers) {
				updateReferrers(data.charts.referrers);
			}

			// Update sessions chart
			if (data.charts && data.charts.sessions_chart && window.sessionsChart) {
				updateSessionsChart(data.charts.sessions_chart);
			}
		}

		function updateStatCard(type, value) {
			const statCards = document.querySelectorAll('.stat-card');
			const normalize = s => (s||'').toLowerCase().replace(/[^a-z]/g, '');
			const needle = normalize(type);
			statCards.forEach(card => {
				const raw = card.querySelector('.stat-label')?.textContent || '';
				const label = normalize(raw);
				if (label && label.includes(needle)) {
					const valueElement = card.querySelector('.stat-value');
					if (valueElement) {
						valueElement.textContent = value;
					}
				}
			});
		}

		function updateTopPages(topPages) {
			const container = document.querySelector('.top-pages');
			if (!container) return;

			const i18n = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
			const pcLabel = i18n.pc || 'PC';
			const mobileLabel = i18n.mobile || 'Mobile';

			let html = '';
			topPages.forEach(page => {
				const title = page.title || '';
				const url = page.url || '#';
				html += `
					<div class="page-item">
						<div class="page-info">
							<div class="page-title" title="${title}">${title}</div>
							<div class="page-url" title="${url}">${url}</div>
						</div>
						<div class="page-stats">
							<span class="page-views">${page.views}</span>
						</div>
					</div>
				`;
			});
			container.innerHTML = html;
			if (typeof lucide !== 'undefined') lucide.createIcons();
		}

			function updateTopPagesEnhanced(topPages) {
				const container = document.querySelector('.top-pages');
				if (!container) return;

				// Check if we have any data
				if (!topPages || !topPages.length) {
					// Show empty state
					const i18n = window.opti_behaviorData?.strings || {};
					container.innerHTML = `
						<div class="optibehavior-empty-state is-visible">
							<i data-lucide="file-text" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
							<div class="optibehavior-empty-title">${i18n.noPageData || 'No page data available'}</div>
							<div class="optibehavior-empty-sub">${i18n.tryBroadeningDateRange || 'Try broadening the date range or check back later.'}</div>
						</div>
					`;
					// Initialize Lucide icons
					if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
						lucide.createIcons();
					}
					return;
				}

				const i18n = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
				const interactionsLabel = i18n.interactions || 'Heatmap sessions';
				const clicksLabel = i18n.clicks || 'Clicks';

				let html = '';
				topPages.forEach((page, pageIndex) => {
					const rank = pageIndex + 1;
					const title = page.title || '';
					const url = page.url || '#';
					const viewsRaw = page.views || 0;
					const sessionsRaw = page.sessions || 0;
					const clicksRaw = page.clicks || 0;
					const views = typeof viewsRaw === 'number' ? viewsRaw : parseInt(viewsRaw, 10) || 0;
					const sessions = typeof sessionsRaw === 'number' ? sessionsRaw : parseInt(sessionsRaw, 10) || 0;
					const clicks = typeof clicksRaw === 'number' ? clicksRaw : parseInt(clicksRaw, 10) || 0;

					const viewsChangeRaw = page.views_change || 0;
					const clicksChangeRaw = page.clicks_change || 0;
					const viewsChange = typeof viewsChangeRaw === 'number' ? viewsChangeRaw : parseInt(viewsChangeRaw, 10) || 0;
					const clicksChange = typeof clicksChangeRaw === 'number' ? clicksChangeRaw : parseInt(clicksChangeRaw, 10) || 0;
					const viewsTrend = page.views_trend || 'neutral';
					const clicksTrend = page.clicks_trend || 'neutral';

					let viewsTrendClass = 'neutral';
					let viewsArrow = '–';
					if (viewsTrend === 'up') {
						viewsTrendClass = 'positive';
						viewsArrow = '↑';
					} else if (viewsTrend === 'down') {
						viewsTrendClass = 'negative';
						viewsArrow = '↓';
					}

					let clicksTrendClass = 'neutral';
					let clicksArrow = '–';
					if (clicksTrend === 'up') {
						clicksTrendClass = 'positive';
						clicksArrow = '↑';
					} else if (clicksTrend === 'down') {
						clicksTrendClass = 'negative';
						clicksArrow = '↓';
					}

					const viewsChangeDisplay = Math.abs(viewsChange);
					const clicksChangeDisplay = Math.abs(clicksChange);

					const editUrl = typeof page.edit_url === 'string' ? page.edit_url : '';
					const editLabel = i18n.editPage || 'Edit this page';
					const editLinkHtml = editUrl
						? `<a class="page-edit-link" href="${escapeHtml(editUrl)}" title="${escapeHtml(editLabel)}"><i data-lucide="pencil"></i></a>`
						: '';

					// "Hot" badge heuristic: no dedicated data field exists, so approximate it
					// from the existing views trend/change (presentation-only, mirrors PHP fallback).
					const isHot = viewsTrend === 'up' && viewsChangeDisplay >= 50;
					const hotBadgeHtml = isHot ? `<span class="page-hot-badge">🔥 Hot</span>` : '';

					html += `
						<div class="page-item">
							<div class="page-title-row">
								<span class="page-rank">${rank}</span>
								<div class="page-title" title="${title}">${title}</div>
								${editLinkHtml}
								${hotBadgeHtml}
							</div>
							<a class="page-url" href="${url}" target="_blank" rel="noopener noreferrer" title="${url}">${url}</a>
							<div class="page-metrics">
								<div class="metric metric-views" title="${interactionsLabel}">
									<i class="metric-icon" data-lucide="flame"></i>
									<span class="metric-value">${sessions.toLocaleString()}</span>
									<span class="metric-change ${viewsTrendClass}">
										<span class="metric-arrow">${viewsArrow}</span>
										<span class="metric-percent">${viewsChangeDisplay}%</span>
									</span>
								</div>
								<div class="metric metric-clicks" title="${clicksLabel}">
									<i class="metric-icon" data-lucide="mouse-pointer-click"></i>
									<span class="metric-value">${clicks.toLocaleString()}</span>
									<span class="metric-change ${clicksTrendClass}">
										<span class="metric-arrow">${clicksArrow}</span>
										<span class="metric-percent">${clicksChangeDisplay}%</span>
									</span>
								</div>
							</div>
						</div>
					`;
				});
				container.innerHTML = html;
				if (typeof lucide !== 'undefined') lucide.createIcons();
			}

			// Initialize Top Pages widget on initial page load using existing dashboard data
			(function initTopPagesOnLoad() {
				function run() {
					try {
						var data = window.opti_behaviorData || null;
						var pages = data && data.dashboard && data.dashboard.charts && data.dashboard.charts.top_pages ? data.dashboard.charts.top_pages : null;
						if (pages && pages.length) {
							updateTopPagesEnhanced(pages);
						}
					} catch (e) {
						// Fail silently; server-rendered Top Pages will still be visible.
					}
				}

				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', run);
				} else {
					run();
				}
			})();




		// Convert the summary_stats daily_history (parallel arrays keyed by metric)
		// into the array-of-points shape updateSessionsChart expects. The numbers are
		// the canonical Dashboard traffic series, identical to the old sessions_chart
		// AJAX response.
		function optiBehaviorDailyHistoryToTraffic(dh) {
			if (!dh || typeof dh !== 'object') { return []; }
			const dates = Array.isArray(dh.dates) ? dh.dates : [];
			const visitors = Array.isArray(dh.visitors) ? dh.visitors : [];
			const sessions = Array.isArray(dh.sessions) ? dh.sessions : [];
			const pageviews = Array.isArray(dh.pageviews) ? dh.pageviews : [];
			const out = [];
			for (let i = 0; i < dates.length; i++) {
				out.push({
					date: dates[i],
					visitors: parseInt(visitors[i], 10) || 0,
					sessions: parseInt(sessions[i], 10) || 0,
					pageviews: parseInt(pageviews[i], 10) || 0
				});
			}
			return out;
		}
		window.optiBehaviorDailyHistoryToTraffic = optiBehaviorDailyHistoryToTraffic;

		function updateSessionsChart(chartData) {
			if (!window.sessionsChart) return;

			const normalizedSeries = optiBehaviorNormalizeTrafficOverviewData(chartData);
			const normalizedChartData = optiBehaviorIsCustomDashboardPeriod() ? normalizedSeries : optiBehaviorTrimTrafficOverviewLeadingEmptyDays(normalizedSeries);

			// Check if we have any data
			const hasData = normalizedChartData.length > 0;

			if (!hasData) {
				// Clear stale series first so the empty-state helper does not read
				// leftover unfiltered data from the live chart instance.
				window.sessionsChart.data.labels = [];
				(window.sessionsChart.data.datasets || []).forEach(function(ds){ if (ds) { ds.data = []; } });
				try { window.sessionsChart.update('none'); } catch(_) {}
				// Show empty state
				if (window.optibehavior_showEmptyStateForCanvas) {
					const i18n = window.opti_behaviorData?.strings || {}; window.optibehavior_showEmptyStateForCanvas('sessions-chart', 'sessions', i18n.noVisitorData || 'No visitor data available');
				}
				return;
			}

			// Restore canvas in case a previous empty state hid it
			const sessionsCanvas = document.getElementById('sessions-chart');
			if (sessionsCanvas) {
				sessionsCanvas.style.display = '';
				const sessionsWrap = sessionsCanvas.parentElement;
				const sessionsEmpty = sessionsWrap ? sessionsWrap.querySelector('.optibehavior-empty-state') : null;
				if (sessionsEmpty) { sessionsEmpty.style.display = 'none'; }
			}

			const labels = normalizedChartData.map(item => item.date);
			const sessions = normalizedChartData.map(item => item.sessions);
			const visitors = normalizedChartData.map(item => item.visitors);
			const pageviews = normalizedChartData.map(item => item.pageviews);
			window.sessionsChart.data.labels = labels;
			window.sessionsChart.data.datasets = window.sessionsChart.data.datasets || [];
			window.sessionsChart.data.datasets[0] = window.sessionsChart.data.datasets[0] || {
				label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.visitorsLabel:'Visitors'),
				borderColor: '#6366F1',
				backgroundColor: 'rgba(99, 102, 241, 0.15)'
			};
			window.sessionsChart.data.datasets[1] = window.sessionsChart.data.datasets[1] || {
				label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.sessionsLabel:'Sessions'),
				borderColor: '#F59E0B',
				backgroundColor: 'rgba(245, 158, 11, 0.15)'
			};
			window.sessionsChart.data.datasets[2] = window.sessionsChart.data.datasets[2] || {
				label: ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.pageViewsLabel:'Page Views'),
				borderColor: '#10B981',
				backgroundColor: 'rgba(16, 185, 129, 0.15)'
			};
			window.sessionsChart.data.datasets[0].data = visitors;
			window.sessionsChart.data.datasets[1].data = sessions;
			window.sessionsChart.data.datasets[2].data = pageviews;
			optiBehaviorApplyTrafficOverviewZeroOriginOptions(window.sessionsChart);
			window.sessionsChart.update();
		}


			// Update browsers table
			function updateBrowsers(items) {
				const tbody = document.getElementById('browsers-table-body');
				if (!tbody) return;

				// Check if we have any data
				if (!items || !items.length) {
					// Hide the table container
					const tableContainer = document.querySelector('.browsers-table-container');
					if (tableContainer) {
						tableContainer.style.display = 'none';
					}
					// Show empty state outside the table
					const widgetContent = tbody.closest('.widget-content');
					if (widgetContent) {
						const i18n = window.opti_behaviorData?.strings || {};
						let emptyState = widgetContent.querySelector('.optibehavior-empty-state');
						if (!emptyState) {
							emptyState = document.createElement('div');
							emptyState.className = 'optibehavior-empty-state';
							emptyState.innerHTML = `
								<i data-lucide="compass" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
								<div class="optibehavior-empty-title">${i18n.noBrowserData || "No browser data available"}</div>
								<div class="optibehavior-empty-sub">${i18n.tryBroadeningDateRange || "Try broadening the date range or check back later."}</div>
							`;
							widgetContent.appendChild(emptyState);
							// Initialize Lucide icons
							if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
								lucide.createIcons();
							}
						}
						emptyState.classList.add('is-visible');
					}
					// Clear stale rows so a later un-hide cannot resurrect them
					tbody.innerHTML = '';
					return;
				}

				// We have data - show table and hide empty state
				const tableContainer = document.querySelector('.browsers-table-container');
				if (tableContainer) {
					tableContainer.style.display = '';
				}
				const widgetContent = tbody.closest('.widget-content');
				if (widgetContent) {
					const emptyState = widgetContent.querySelector('.optibehavior-empty-state');
					if (emptyState) {
						emptyState.classList.remove('is-visible');
					}
				}

				// Clear loading state
				tbody.innerHTML = '';

				// Aggregate duplicate labels; fold Unknown/undefined/empty into "Other"
				// so unidentified browsers show as a single catch-all row.
				const labelMap = new Map();
				(items || []).forEach(it => {
					let key = (it && it.browser) ? String(it.browser).trim() : '';
					const low = key.toLowerCase();
					if (!key || low === 'unknown' || low === 'undefined' || low === 'other') key = 'Other';
					labelMap.set(key, (labelMap.get(key) || 0) + Number(it?.count || 0));
				});

				// Get total count for percentage calculation
				const totalCount = Array.from(labelMap.values()).reduce((sum, count) => sum + count, 0);

				// Browser icon helper is shared at window scope (top of this file).
				const getBrowserIconSVG = window.optiBehaviorBrowserIconSVG;

				// Populate table rows: highest count first, catch-all "Other" always last
				const entries = Array.from(labelMap.entries()).sort((a, b) => {
					if (a[0] === 'Other') return 1;
					if (b[0] === 'Other') return -1;
					return b[1] - a[1];
				});
				entries.forEach(([browser, count]) => {
					const percentage = totalCount > 0 ? Math.round((count / totalCount) * 100) : 0;
					const browserIconSVG = getBrowserIconSVG(browser);

					// Calculate trend (for now, show as new since we don't have previous data)
					const trendValue = 100;
					const trendClass = 'trend-up';
					const trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';

					const row = document.createElement('tr');
					row.className = 'ob-metric-row';
					row.innerHTML = `
						<td class="browser-name-cell">
							<span class="ob-metric-main">
								<span class="browser-icon-svg">${browserIconSVG}</span>
								<span class="browser-name ob-metric-label" title="${browser}">${browser}</span>
							</span>
						</td>
						<td class="browser-visitors-cell">
							<span class="ob-metric-values">
								<span class="visitor-count">${count}</span>
								<span class="visitor-percentage">${percentage}%</span>
								<span class="visitor-trend ${trendClass}">
									${trendIcon}
									${Math.abs(trendValue)}%
								</span>
							</span>
						</td>
					`;
					tbody.appendChild(row);
				});
			}

			// Initialize browsers table on page load
			(function initBrowsersTable() {
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', function() {
						const data = window.opti_behaviorData;
						if (data && data.dashboard && data.dashboard.charts && data.dashboard.charts.browsers) {
							updateBrowsers(data.dashboard.charts.browsers);
						}
					});
				} else {
					const data = window.opti_behaviorData;
					if (data && data.dashboard && data.dashboard.charts && data.dashboard.charts.browsers) {
						updateBrowsers(data.dashboard.charts.browsers);
					}
				}
			})();

			// Update traffic classification legend
			function updateTrafficSpamSummaryCard(data) {
				const card = document.getElementById('traffic-spam-summary-card');
				if (!card || !data) return;

				const spamCount = (data.spam && typeof data.spam.detected_count !== 'undefined')
					? (Number(data.spam.detected_count) || 0)
					: (typeof data.spam_detected_count !== 'undefined')
						? (Number(data.spam_detected_count) || 0)
						: ((data.spam && Number(data.spam.count)) || 0);
				const total = Number(data.total) || 0;
				const detectedTotal = (data.spam && Number(data.spam.detected_total)) || Number(data.all_sessions_total) || total;
				const spamPercentage = data.spam && typeof data.spam.detected_percentage !== 'undefined'
					? Number(data.spam.detected_percentage) || 0
					: typeof data.spam_detected_percentage !== 'undefined'
						? Number(data.spam_detected_percentage) || 0
						: data.spam && typeof data.spam.percentage !== 'undefined'
							? Number(data.spam.percentage) || 0
							: (detectedTotal > 0 ? (spamCount / detectedTotal) * 100 : 0);
				const countEl = document.getElementById('traffic-spam-count');
				const percentageEl = document.getElementById('traffic-spam-percentage');

				card.style.display = (total > 0 || spamCount > 0) ? '' : 'none';
				card.classList.toggle('has-spam', spamCount > 0);
				card.classList.toggle('is-zero', spamCount <= 0);

				if (countEl) {
					countEl.textContent = spamCount.toLocaleString();
				}

				if (percentageEl) {
					const formattedPercentage = spamPercentage % 1 === 0 ? String(spamPercentage) : spamPercentage.toFixed(1);
					percentageEl.textContent = `${formattedPercentage}%`;
				}
			}

			function updateTrafficClassificationLegend(data) {
				const tbody = document.getElementById('traffic-classification-legend-body');
				if (!tbody || !data) return;

				// Clear loading state
				tbody.innerHTML = '';

				// Get current period data
				const humanCount = (data.human && data.human.count) || 0;
				const automatedCount = (data.automated && data.automated.count) || 0;
				const total = data.total || 0;

				// Get previous period data (if available)
				const prevHumanCount = (data.human && data.human.previous_count) || 0;
				const prevAutomatedCount = (data.automated && data.automated.previous_count) || 0;

				// Calculate percentages
				const humanPct = total > 0 ? Math.round((humanCount / total) * 100) : 0;
				const automatedPct = total > 0 ? Math.round((automatedCount / total) * 100) : 0;

				// Helper function to create trend HTML
				function getTrendHTML(currentCount, previousCount) {
					let trendClass = 'trend-neutral';
					let trendIcon = '';
					let trendValue = 0;

					if (previousCount > 0) {
						trendValue = Math.round(((currentCount - previousCount) / previousCount) * 100);
						if (trendValue > 0) {
							trendClass = 'trend-up';
							trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
						} else if (trendValue < 0) {
							trendClass = 'trend-down';
							trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
						} else {
							trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
						}
					} else if (currentCount > 0) {
						// New traffic type - show as positive trend
						trendValue = 100;
						trendClass = 'trend-up';
						trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
					} else {
						// Both current and previous are 0 - show neutral
						trendValue = 0;
						trendClass = 'trend-neutral';
						trendIcon = '';
					}

					return `<span class="visitor-trend ${trendClass}">${trendIcon}${Math.abs(trendValue)}%</span>`;
				}

				// Create rows for each traffic type
				const i18nTC = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
				const trafficTypes = [
					{ name: i18nTC.humanTraffic || 'Human Traffic', count: humanCount, percentage: humanPct, prevCount: prevHumanCount, color: '#10B981' },
					{ name: i18nTC.automated || 'Automated', count: automatedCount, percentage: automatedPct, prevCount: prevAutomatedCount, color: '#F59E0B' }
				];

				trafficTypes.forEach(type => {
					const row = document.createElement('tr');
					row.innerHTML = `
						<td class="traffic-type-cell">
							<span>
								<span class="traffic-color-dot" style="background-color: ${type.color};"></span>
								<span class="traffic-type-name">${type.name}</span>
							</span>
						</td>
						<td class="traffic-stats-cell">
							<span class="visitor-count">${type.count}</span>
							<span class="visitor-percentage">${type.percentage}%</span>
							${getTrendHTML(type.count, type.prevCount)}
						</td>
					`;
					tbody.appendChild(row);
				});
			}

			// Initialize traffic classification chart and legend
			function initTrafficClassificationWidget() {
				if (typeof Chart === 'undefined') {
					setTimeout(initTrafficClassificationWidget, 300);
					return;
				}

				const ctx = document.getElementById('traffic-classification-chart');
				if (!ctx) return;

				const data = window.opti_behaviorData;
				if (!data || !data.dashboard || !data.dashboard.traffic_classification) return;

				const trafficData = data.dashboard.traffic_classification;
				updateTrafficSpamSummaryCard(trafficData);

				// Check if we have data
				const total = trafficData.total || 0;
				const detectedSpamCount = (trafficData.spam && Number(trafficData.spam.detected_count)) || Number(trafficData.spam_detected_count) || 0;
				if (total === 0 && detectedSpamCount === 0) {
					// No data for the CURRENT payload (e.g. filtered result is empty).
					// A previous render may have left rows + a drawn donut behind, so
					// actively clear them instead of bailing out.
					updateTrafficClassificationLegend(trafficData); // renders zero rows
					const staleChart = Chart.getChart(ctx);
					if (staleChart) staleChart.destroy();
					const emptyWidget = ctx.closest('.dashboard-widget');
					if (emptyWidget) {
						const emptyState = emptyWidget.querySelector('.optibehavior-empty-state');
						if (emptyState) emptyState.classList.add('is-visible');
					}
					return;
				}

				// Hide empty state and show widget content
				const widget = ctx.closest('.dashboard-widget');
				if (widget) {
					const emptyState = widget.querySelector('.optibehavior-empty-state');
					const chartContainer = widget.querySelector('.user-intent-chart-container');
					if (emptyState) emptyState.classList.remove('is-visible');
					if (chartContainer) chartContainer.style.display = '';
				}

				// Update legend table
				updateTrafficClassificationLegend(trafficData);

				// Get counts
				const humanCount = (trafficData.human && trafficData.human.count) || 0;
				const automatedCount = (trafficData.automated && trafficData.automated.count) || 0;
				const spamCount = (trafficData.spam && trafficData.spam.count) || 0;

				// Destroy existing chart if it exists
				const existingChart = Chart.getChart(ctx);
				if (existingChart) existingChart.destroy();

				// Create new chart
				const i18nChart = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
				new Chart(ctx, {
					type: 'doughnut',
					data: {
						labels: [i18nChart.humanTraffic || 'Human Traffic', i18nChart.automated || 'Automated', i18nChart.spamTraffic || 'Spam Traffic'],
						datasets: [{
							data: [humanCount, automatedCount, spamCount],
							backgroundColor: ['#10B981', '#F59E0B', '#EF4444'],
							borderWidth: 0
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						cutout: '70%',
						plugins: {
							legend: { display: false },
							tooltip: {
								callbacks: {
									label: function(context) {
										try {
											const label = context.label || '';
											const value = context.parsed || 0;
											const total = (context.dataset && context.dataset.data || []).reduce((a, b) => a + (+b || 0), 0);
											const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
											return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
										} catch(e) {
											return (context.label || '') + ': ' + (context.parsed || 0);
										}
									}
								}
							}
						}
					}
				});
			}

			// Initialize on page load
			(function() {
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', initTrafficClassificationWidget);
				} else {
					initTrafficClassificationWidget();
				}
			})();

			function updateOperatingSystems(items){
				const el = document.getElementById('operating-systems-chart');
				if(!el || typeof Chart==='undefined') return;
				const cleaned = (items||[]).filter(it=>{ const s=((it?.os)||(it?.name)||'').trim().toLowerCase(); return s && s!=='unknown' && s!=='undefined' && s!=='other'; });
				let labels = cleaned.map(i=>i.os||i.name);
				let data = cleaned.map(i=>i.count||0);
				// Visitors fallback: never when advanced filters are active (visitors
				// list is the unfiltered page-load payload).
				const osUpdFiltersActive = Object.keys(window.optiBehaviorAdvancedFilters || {}).length > 0;
				if(!labels.length && !osUpdFiltersActive && window.opti_behaviorData?.dashboard?.visitors){
					const map={}; (window.opti_behaviorData.dashboard.visitors||[]).forEach(v=>{ const k=(v.os?String(v.os).trim():''); const lk=k.toLowerCase(); if(!k||lk==='unknown'||lk==='undefined'||lk==='other') return; map[k]=(map[k]||0)+1; });
					labels = Object.keys(map); data = labels.map(k=>map[k]);
				}
				if(!labels.length) {
					// Clear stale legend + destroy stale chart, then show empty state
					const staleOsLegend = document.getElementById('os-legend-body');
					if (staleOsLegend) staleOsLegend.innerHTML = '';
					if (window.osPie) { try { window.osPie.destroy(); } catch(e){} window.osPie = null; }
					if (window.optibehavior_showEmptyStateForCanvas) {
						window.optibehavior_showEmptyStateForCanvas('operating-systems-chart', 'os', ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.noOperatingSystemData)||'No operating system data available'), true);
					}
					return;
				}
				el.style.display = ''; // restore canvas if a previous empty state hid it
				if(!window.osPie){
					window.osPie = new Chart(el,{
						type:'pie',
						data:{
							labels,
							datasets:[{
								label:((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.chartOperatingSystems)||'Operating Systems'),
								data,
								backgroundColor:['#06B6D4','#84CC16','#8B5CF6','#F472B6','#F59E0B']
							}]
						},
						options:{
							responsive:true,
							maintainAspectRatio:false,
							plugins:{
								legend:{ display: false }, // Hide Chart.js legend - using custom HTML legend instead
								tooltip:{
									callbacks:{
										label:function(ctx){
											try{
												var total=(ctx.dataset&&ctx.dataset.data||[]).reduce(function(a,b){return a+(+b||0)},0);
												var v=ctx.parsed||0;
												var pct = total? ((v/total)*100).toFixed(1):0;
												return (ctx.label||'')+': '+v+' ('+pct+'%)';
											}catch(e){
												return (ctx.label||'')+': '+(ctx.parsed||0);
											}
										}
									}
								}
							}
						}
					});
				}
				else { window.osPie.data.labels = labels; window.osPie.data.datasets[0].data = data; window.osPie.update(); }
			}

			function updateCountries(items){
				const tbody = document.getElementById('countries-table-body');
				if(!tbody) return;

				// Clear loading state
				tbody.innerHTML = '';

				if(!items || !items.length) {
					// Hide the table container
					const tableContainer = document.querySelector('.countries-table-container');
					if (tableContainer) {
						tableContainer.style.display = 'none';
					}
					// Show empty state outside the table
					const widgetContent = tbody.closest('.widget-content');
					const i18n = window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n ? window.opti_behaviorDashboard.i18n : {};
					if (widgetContent) {
						let emptyState = widgetContent.querySelector('.optibehavior-empty-state');
						if (!emptyState) {
							emptyState = document.createElement('div');
							emptyState.className = 'optibehavior-empty-state is-visible';
							emptyState.innerHTML = `
								<i data-lucide="globe" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
								<div class="optibehavior-empty-title">${i18n.noCountryData || 'No country data available'}</div>
								<div class="optibehavior-empty-sub">${i18n.tryBroadeningDateRange || 'Try broadening the date range or check back later.'}</div>
							`;
							widgetContent.appendChild(emptyState);
							// Initialize Lucide icons
							if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
								lucide.createIcons();
							}
						} else {
							emptyState.classList.add('is-visible');
						}
					}
					return;
				}

				// We have data - show table and hide empty state
				const tableContainer = document.querySelector('.countries-table-container');
				if (tableContainer) {
					tableContainer.style.display = '';
				}
				const widgetContent = tbody.closest('.widget-content');
				if (widgetContent) {
					const emptyState = widgetContent.querySelector('.optibehavior-empty-state');
					if (emptyState) {
						emptyState.classList.remove('is-visible');
					}
				}

				// Calculate total for percentages
				const total = items.reduce((sum, item) => sum + (item.count || 0), 0);

				// Country code to ISO code mapping for professional flag icons
				const countryToISO = {
					'United States': 'us', 'US': 'us',
					'Germany': 'de', 'DE': 'de',
					'United Kingdom': 'gb', 'GB': 'gb', 'UK': 'gb',
					'France': 'fr', 'FR': 'fr',
					'India': 'in', 'IN': 'in',
					'Netherlands': 'nl', 'NL': 'nl',
					'China': 'cn', 'CN': 'cn',
					'Canada': 'ca', 'CA': 'ca',
					'Italy': 'it', 'IT': 'it',
					'Poland': 'pl', 'PL': 'pl',
					'Spain': 'es', 'ES': 'es',
					'Brazil': 'br', 'BR': 'br',
					'Australia': 'au', 'AU': 'au',
					'Japan': 'jp', 'JP': 'jp',
					'Russia': 'ru', 'RU': 'ru',
					'Mexico': 'mx', 'MX': 'mx',
					'South Korea': 'kr', 'KR': 'kr',
					'Indonesia': 'id', 'ID': 'id',
					'Turkey': 'tr', 'TR': 'tr',
					'Saudi Arabia': 'sa', 'SA': 'sa',
					'Switzerland': 'ch', 'CH': 'ch',
					'Belgium': 'be', 'BE': 'be',
					'Sweden': 'se', 'SE': 'se',
					'Norway': 'no', 'NO': 'no',
					'Denmark': 'dk', 'DK': 'dk',
					'Finland': 'fi', 'FI': 'fi',
					'Austria': 'at', 'AT': 'at',
					'Portugal': 'pt', 'PT': 'pt',
					'Greece': 'gr', 'GR': 'gr',
					'Ireland': 'ie', 'IE': 'ie',
					'Thailand': 'th', 'TH': 'th',
					'South Africa': 'za', 'ZA': 'za',
					'Unknown': 'xx'
				};

				// Build table rows with professional styling
				items.forEach((item, index) => {
					const countryName = item.country_name || item.country || (i18n.unknown || 'Unknown');
					const countryCode = item.country || '';
					const count = item.count || 0;
					const percentage = total > 0 ? Math.round((count / total) * 100) : 0;

					// Get ISO code for flag - use the country code directly from database (already 2-letter ISO code)
					// Fallback to mapping only if needed, then to 'xx' for unknown
					let isoCode = '';
					if (countryCode && countryCode.length === 2 && /^[A-Z]{2}$/.test(countryCode)) {
						// Database already has valid 2-letter ISO code (RU, US, CN, etc.)
						isoCode = countryCode.toLowerCase();
					} else {
						// Fallback to mapping for country names or invalid codes
						isoCode = countryToISO[countryName] || countryToISO[countryCode] || 'xx';
					}

					// Use flagcdn.com for professional flag icons
					const flagUrl = `https://flagcdn.com/w40/${isoCode}.png`;

					// Calculate trend (random for demo - replace with actual comparison logic)
					const previousCount = item.previous_count || 0;
					let trendClass = 'trend-neutral';
					let trendIcon = '';
					let trendValue = 0;

					if (previousCount > 0) {
						trendValue = Math.round(((count - previousCount) / previousCount) * 100);
						if (trendValue > 0) {
							trendClass = 'trend-up';
							trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
						} else if (trendValue < 0) {
							trendClass = 'trend-down';
							trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
						} else {
							trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
						}
					} else {
						// New country - show as positive trend
						trendValue = 100;
						trendClass = 'trend-up';
						trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
					}

					const row = document.createElement('tr');
					row.className = 'ob-metric-row';
					row.innerHTML = `
						<td class="country-name-cell">
							<div class="ob-metric-main">
								<span class="country-flag" style="background-image: url('${flagUrl}');"></span>
								<span class="country-name ob-metric-label">${countryName}</span>
							</div>
						</td>
						<td class="country-visitors-cell">
							<div class="ob-metric-values">
								<span class="visitor-count">${count.toLocaleString()}</span>
								<span class="visitor-percentage">${percentage}%</span>
								<span class="visitor-trend ${trendClass}">
									${trendIcon}
									${Math.abs(trendValue)}%
								</span>
							</div>
						</td>
					`;
					tbody.appendChild(row);
				});
			}

			// Initialize countries table on page load
			(function initCountriesTable() {
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', function() {
						const data = window.opti_behaviorData;
						if (data && data.dashboard && data.dashboard.charts && data.dashboard.charts.countries) {
							updateCountries(data.dashboard.charts.countries);
						}
					});
				} else {
					const data = window.opti_behaviorData;
					if (data && data.dashboard && data.dashboard.charts && data.dashboard.charts.countries) {
						updateCountries(data.dashboard.charts.countries);
					}
				}
			})();

			// Update referrers table
			function updateReferrers(items) {
				const tbody = document.getElementById('referrers-table-body');
				if (!tbody) return;

				// Check if we have any data
				if (!items || !items.length) {
					// Hide the table container
					const tableContainer = document.querySelector('.referrers-table-container');
					if (tableContainer) {
						tableContainer.style.display = 'none';
					}
					// Show empty state outside the table
					const widgetContent = tbody.closest('.widget-content');
					const i18n = window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n ? window.opti_behaviorDashboard.i18n : {};
					if (widgetContent) {
						let emptyState = widgetContent.querySelector('.optibehavior-empty-state');
						if (!emptyState) {
							emptyState = document.createElement('div');
							emptyState.className = 'optibehavior-empty-state';
							emptyState.innerHTML = `
								<i data-lucide="link" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
								<div class="optibehavior-empty-title">${i18n.noReferrerData || 'No referrer data available'}</div>
								<div class="optibehavior-empty-sub">${i18n.tryBroadeningDateRange || 'Try broadening the date range or check back later.'}</div>
							`;
							widgetContent.appendChild(emptyState);
							// Initialize Lucide icons
							if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
								lucide.createIcons();
							}
						}
						emptyState.classList.add('is-visible');
					}
					// Clear any stale rows from a previous render
					tbody.innerHTML = '';
					return;
				}

				// We have data - show table and hide empty state
				const tableContainer = document.querySelector('.referrers-table-container');
				if (tableContainer) {
					tableContainer.style.display = '';
				}
				const widgetContent = tbody.closest('.widget-content');
				if (widgetContent) {
					const emptyState = widgetContent.querySelector('.optibehavior-empty-state');
					if (emptyState) {
						emptyState.classList.remove('is-visible');
					}
				}

				// Clear loading state
				tbody.innerHTML = '';

				// Get total count for percentage calculation
				const totalCount = items.reduce((sum, item) => sum + (item.count || 0), 0);

				// Helper function to get referrer icon SVG with real multi-color branding
				function getReferrerIconSVG(referrer) {
					const name = (referrer || '').toLowerCase();

					// Direct / None - Blue gradient house icon
					if (name.includes('direct') || name.includes('none')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="directGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3B82F6"/><stop offset="100%" stop-color="#1D4ED8"/></linearGradient></defs><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="url(#directGrad)" stroke="#fff" stroke-width="1"/><path d="M9 22V12h6v10" fill="#1D4ED8"/></svg>';
					}

					// Google - Multi-color (Blue, Red, Yellow, Green)
					if (name.includes('google')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>';
					}

					// Facebook - Blue gradient
					if (name.includes('facebook')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="fbGrad" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#1877F2"/><stop offset="100%" stop-color="#0C63D4"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#fbGrad)"/><path d="M15.5 12.5h-2.5v8h-3v-8H8v-2.5h2V8.5c0-2 1-3.5 3.5-3.5h2.5v2.5h-2c-.5 0-.5.5-.5 1v2h2.5l-.5 2.5z" fill="#fff"/></svg>';
					}

					// Twitter/X - Black with blue accent
					if (name.includes('twitter') || name.includes('x.com')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><circle cx="12" cy="12" r="11" fill="#000"/><path d="M8 4l6 8-6 8h2l5-6.5L19 20h2l-6.5-8.5L20 4h-2l-4.5 6L10 4H8z" fill="#fff"/></svg>';
					}

					// LinkedIn - Blue gradient
					if (name.includes('linkedin')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="liGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0A66C2"/><stop offset="100%" stop-color="#004182"/></linearGradient></defs><rect x="2" y="2" width="20" height="20" rx="2" fill="url(#liGrad)"/><path d="M6 9h2v8H6V9zm1-3c.7 0 1.3.6 1.3 1.3S7.7 8.6 7 8.6 5.7 8 5.7 7.3 6.3 4 7 4zm4 5h2v1c.3-.5 1-1 2-1 2 0 2.5 1.3 2.5 3v5h-2v-4.5c0-.8 0-1.5-1-1.5s-1 .7-1 1.5V17h-2V9z" fill="#fff"/></svg>';
					}

					// Instagram - Gradient (Purple, Pink, Orange, Yellow)
					if (name.includes('instagram')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><radialGradient id="igGrad1" cx="30%" cy="110%"><stop offset="0%" stop-color="#FFDD55"/><stop offset="25%" stop-color="#FF543E"/><stop offset="50%" stop-color="#C837AB"/></radialGradient><radialGradient id="igGrad2" cx="70%" cy="0%"><stop offset="0%" stop-color="#4168C9"/><stop offset="50%" stop-color="#4168C9" stop-opacity="0"/></radialGradient></defs><rect x="2" y="2" width="20" height="20" rx="5" fill="url(#igGrad1)"/><rect x="2" y="2" width="20" height="20" rx="5" fill="url(#igGrad2)"/><circle cx="12" cy="12" r="4.5" fill="none" stroke="#fff" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.5" fill="#fff"/></svg>';
					}

					// YouTube - Red gradient
					if (name.includes('youtube')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="ytGrad" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#FF0000"/><stop offset="100%" stop-color="#CC0000"/></linearGradient></defs><rect x="2" y="6" width="20" height="12" rx="3" fill="url(#ytGrad)"/><path d="M10 9.5v5l5-2.5-5-2.5z" fill="#fff"/></svg>';
					}

					// Reddit - Orange gradient
					if (name.includes('reddit')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="rdGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF4500"/><stop offset="100%" stop-color="#CC3700"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#rdGrad)"/><circle cx="12" cy="13" r="7" fill="#fff"/><circle cx="9.5" cy="12" r="1.2" fill="#FF4500"/><circle cx="14.5" cy="12" r="1.2" fill="#FF4500"/><path d="M9 15c0 1.5 1.3 2.5 3 2.5s3-1 3-2.5" stroke="#FF4500" stroke-width="1.2" fill="none" stroke-linecap="round"/><circle cx="12" cy="8" r="1.5" fill="#fff"/></svg>';
					}

					// Pinterest - Red gradient
					if (name.includes('pinterest')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="pinGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#E60023"/><stop offset="100%" stop-color="#BD081C"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#pinGrad)"/><path d="M12 5c-3.9 0-7 3.1-7 7 0 3 1.9 5.5 4.5 6.5-.1-.5-.1-1.2 0-1.7l.9-3.8s-.2-.5-.2-1.2c0-1.1.6-2 1.4-2 .7 0 1 .5 1 1.1 0 .7-.4 1.7-.7 2.6-.2.8.4 1.5 1.2 1.5 1.4 0 2.5-1.5 2.5-3.7 0-1.9-1.4-3.3-3.3-3.3-2.3 0-3.6 1.7-3.6 3.5 0 .7.3 1.4.6 1.8.1.1.1.2.1.3l-.2.9c0 .1-.1.2-.3.1-1-.5-1.6-1.9-1.6-3.1 0-2.5 1.8-4.8 5.2-4.8 2.7 0 4.8 1.9 4.8 4.5 0 2.7-1.7 4.8-4 4.8-.8 0-1.5-.4-1.8-.9l-.5 1.9c-.2.7-.7 1.6-1 2.1.8.2 1.6.4 2.4.4 3.9 0 7-3.1 7-7s-3.1-7-7-7z" fill="#fff"/></svg>';
					}

					// TikTok - Black with cyan/pink
					if (name.includes('tiktok')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><rect x="2" y="2" width="20" height="20" rx="4" fill="#000"/><path d="M16 8c1 .5 2 .5 3 0V6c-1 0-2-.5-2-2h-2v10c0 1-.8 2-2 2s-2-.9-2-2 .8-2 2-2c.3 0 .5 0 .7.1V10c-.2 0-.5-.1-.7-.1-2.2 0-4 1.8-4 4s1.8 4 4 4 4-1.8 4-4V8z" fill="#fff"/><path d="M16 8c1 .5 2 .5 3 0V6c-1 0-2-.5-2-2h-2v10c0 1-.8 2-2 2s-2-.9-2-2 .8-2 2-2c.3 0 .5 0 .7.1V10c-.2 0-.5-.1-.7-.1-2.2 0-4 1.8-4 4s1.8 4 4 4 4-1.8 4-4V8z" fill="#00F2EA" opacity="0.3" transform="translate(-1, 1)"/><path d="M16 8c1 .5 2 .5 3 0V6c-1 0-2-.5-2-2h-2v10c0 1-.8 2-2 2s-2-.9-2-2 .8-2 2-2c.3 0 .5 0 .7.1V10c-.2 0-.5-.1-.7-.1-2.2 0-4 1.8-4 4s1.8 4 4 4 4-1.8 4-4V8z" fill="#FF0050" opacity="0.3" transform="translate(1, 1)"/></svg>';
					}

					// Email - Purple gradient envelope
					if (name.includes('email') || name.includes('mail')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="mailGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#8B5CF6"/><stop offset="100%" stop-color="#6D28D9"/></linearGradient></defs><rect x="2" y="5" width="20" height="14" rx="2" fill="url(#mailGrad)" stroke="#fff" stroke-width="1"/><path d="M2 7l10 7 10-7" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
					}

					// Paid Ads - Green gradient megaphone
					if (name.includes('ads') || name.includes('paid')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="adsGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#10B981"/><stop offset="100%" stop-color="#059669"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#adsGrad)"/><path d="M18 11c0-3-2-5-5-5H8v10h5c3 0 5-2 5-5zm-5-3c1.7 0 3 1.3 3 3s-1.3 3-3 3h-3V8h3zM7 17l-1 3h2l1-3H7z" fill="#fff"/></svg>';
					}

					// Social - Pink gradient share icon
					if (name.includes('social')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="socialGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EC4899"/><stop offset="100%" stop-color="#BE185D"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#socialGrad)"/><circle cx="7" cy="12" r="2.5" fill="#fff"/><circle cx="17" cy="8" r="2.5" fill="#fff"/><circle cx="17" cy="16" r="2.5" fill="#fff"/><path d="M9.5 11l5-2M9.5 13l5 2" stroke="#fff" stroke-width="2"/></svg>';
					}

					// Bing - Orange/Blue gradient
					if (name.includes('bing')) {
						return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="bingGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#008373"/><stop offset="100%" stop-color="#00BCF2"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#bingGrad)"/><path d="M7 4v12l3 2 6-3v-4l-4-2 2-1V4H7z" fill="#fff"/></svg>';
					}

					// Default - Gray gradient globe
					return '<svg viewBox="0 0 24 24" style="width:20px;height:20px;"><defs><linearGradient id="defRefGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#9CA3AF"/><stop offset="100%" stop-color="#6B7280"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#defRefGrad)"/><circle cx="12" cy="12" r="8" fill="none" stroke="#fff" stroke-width="1.5"/><path d="M12 4v16M4 12h16" stroke="#fff" stroke-width="1.5"/><path d="M8 7c1 2 1 6 0 10M16 7c-1 2-1 6 0 10" stroke="#fff" stroke-width="1.5" fill="none"/></svg>';
				}
					function getFaviconUrlFromReferrer(referrer) {
						if (!referrer) {
							return null;
						}

						let label = String(referrer).toLowerCase().trim();

						// Skip synthetic labels without a real domain
						if (label === 'direct / none' || label === 'direct' || label === 'none') {
							return null;
						}

						// Very generic classifications (Paid Ads, Email, Social, etc.) should keep their custom icons
						if (!label.includes('.') || label.includes(' ')) {
							return null;
						}

						let host = label;

						try {
							if (!/^https?:\/\//.test(host)) {
								host = 'https://' + host;
							}
							const url = new URL(host);
							host = url.hostname || host;
						} catch (e) {
							host = host.replace(/^https?:\/\//, '').split('/')[0];
						}

						// Basic hardening to avoid unexpected characters in the favicon URL
						host = host.replace(/[^a-z0-9\.\-]/gi, '');
						if (!host) {
							return null;
						}

						return 'https://icons.duckduckgo.com/ip3/' + host + '.ico';
					}

					function applyReferrerFavicons(tbody) {
						if (!tbody) {
							return;
						}

						const rows = tbody.querySelectorAll('tr');
						rows.forEach(function(row) {
							// Get favicon URL from data attribute (provided by PHP)
							const faviconUrl = row.getAttribute('data-favicon-url');
							const domain = row.getAttribute('data-domain');

							if (!faviconUrl || !domain) {
								// No favicon URL provided by PHP, skip this row
								return;
							}

							const iconContainer = row.querySelector('.referrer-icon-svg');
							if (!iconContainer) {
								return;
							}

							// Save the original SVG for fallback
							const originalSVG = iconContainer.innerHTML;

							// Create img element and add to DOM immediately
							const img = document.createElement('img');
							img.className = 'ob-referrer-favicon';
							img.alt = '';
							img.loading = 'lazy';
							img.style.cssText = 'width: 20px; height: 20px; border-radius: 3px; object-fit: contain;';

							// Fallback chain with multiple sources
							let fallbackIndex = 0;
							const fallbackUrls = [
								'https://www.google.com/s2/favicons?sz=32&domain=' + domain, // Google favicon service (most reliable)
								faviconUrl, // Primary: domain.com/favicon.ico
								'https://icons.duckduckgo.com/ip3/' + domain + '.ico' // DuckDuckGo favicon service
							];

							function tryNextFallback() {
								if (fallbackIndex < fallbackUrls.length) {
									img.src = fallbackUrls[fallbackIndex];
									fallbackIndex++;
								} else {
									// All fallbacks failed, restore the SVG icon
									iconContainer.innerHTML = originalSVG;
								}
							}

							img.addEventListener('error', function() {
								// Try next fallback on error
								tryNextFallback();
							});

							img.addEventListener('load', function() {
								// Successfully loaded, check if it's a valid image
								// Some services return 1x1 transparent pixel for missing favicons
								if (img.naturalWidth > 1 && img.naturalHeight > 1) {
									// Valid favicon loaded - image is already in DOM, just hide SVG
									const svg = iconContainer.querySelector('svg');
									if (svg) {
										svg.style.display = 'none';
									}
								} else {
									// Invalid image, try next fallback
									tryNextFallback();
								}
							});

							// Add image to DOM immediately, then start loading
							iconContainer.appendChild(img);
							img.src = fallbackUrls[0];
						});
					}


				// Populate table rows
				items.forEach((item, index) => {
					const referrer = item.referrer || ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)?window.opti_behaviorDashboard.i18n.directNone:'Direct / None');
					const count = item.count || 0;
					const percentage = totalCount > 0 ? Math.round((count / totalCount) * 100) : 0;
					const referrerIconSVG = getReferrerIconSVG(referrer);
					const faviconUrl = item.favicon_url || '';
					const domain = item.domain || '';

					// Calculate trend (for now, show as new since we don't have previous data)
					const previousCount = item.previous_count || 0;
					let trendClass = 'trend-neutral';
					let trendIcon = '';
					let trendValue = 0;

					if (previousCount > 0) {
						trendValue = Math.round(((count - previousCount) / previousCount) * 100);
						if (trendValue > 0) {
							trendClass = 'trend-up';



							trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
						} else if (trendValue < 0) {
							trendClass = 'trend-down';
							trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
						} else {
							trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
						}
					} else {
						// New referrer - show as positive trend
						trendValue = 100;
						trendClass = 'trend-up';
						trendIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
					}

					const row = document.createElement('tr');
					row.className = 'ob-metric-row';
					// Store favicon data as data attributes
					if (faviconUrl) {
						row.setAttribute('data-favicon-url', faviconUrl);
					}
					if (domain) {
						row.setAttribute('data-domain', domain);
					}
					row.innerHTML = `
						<td class="referrer-name-cell">
							<span class="ob-metric-main">
								<span class="referrer-icon-svg">${referrerIconSVG}</span>
								<span class="referrer-name ob-metric-label" title="${referrer}">${referrer}</span>
							</span>
						</td>
						<td class="referrer-visitors-cell">
							<span class="ob-metric-values">
								<span class="visitor-count">${count}</span>
								<span class="visitor-percentage">${percentage}%</span>
								<span class="visitor-trend ${trendClass}">
									${trendIcon}
									${Math.abs(trendValue)}%
								</span>
							</span>
						</td>
					`;
					tbody.appendChild(row);
				});
					applyReferrerFavicons(tbody);

			}

			// Initialize referrers table on page load
			(function initReferrersTable() {
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', function() {
						const data = window.opti_behaviorData;
						if (data && data.dashboard && data.dashboard.charts && data.dashboard.charts.referrers) {
							updateReferrers(data.dashboard.charts.referrers);
						}
					});
				} else {
					const data = window.opti_behaviorData;
					if (data && data.dashboard && data.dashboard.charts && data.dashboard.charts.referrers) {
						updateReferrers(data.dashboard.charts.referrers);
					}
				}
			})();

		function updateRealtimeVisitors(visitors) {
			function shortIP(ip){
				ip = (ip||'').toString().trim();
				// Treat empty/null IP as Anonymous — NULL ip means the ip was not stored (GDPR-safe fallback)
				if(!ip || ip === 'Anonymous') { return '<span class="visitor-ip-pill visitor-ip-anon"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:3px;"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>Anonymous</span>'; }
				if (ip.indexOf(':') !== -1) { return '<span class="visitor-ip-pill">' + ip.slice(0,7) + '\u2026' + ip.slice(-7) + '</span>'; }
				return '<span class="visitor-ip-pill">' + ip + '</span>';
			}

			const container = document.getElementById('realtime-visitors');
			if (!container) return;


				// Ensure the map initializes even when there are 0 visitors
				(function(){
					var el = document.getElementById('realtime-map'); if(!el) return;
					if (window.realtimeMap) return;
					function init(){ if(window.realtimeMap) return; window.realtimeMap = L.map(el, { worldCopyJump:true, attributionControl:false, minZoom:1 }); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 5, attribution: '' }).addTo(window.realtimeMap); window.rtMapMarkers = {}; try{ window.realtimeMap.fitWorld({ animate:false }); }catch(e){} }
					if (window.L) { init(); }
					else { /* Leaflet not yet available; will be retried by ensureRealtimeMap */ }
				})();

			if (visitors.length === 0) {
				var i18nRT = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
				container.innerHTML = '<div class="optibehavior-empty-state is-visible"><i data-lucide="users" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i><div class="optibehavior-empty-title">' + (i18nRT.noActiveVisitors || 'No active visitors right now') + '</div><div class="optibehavior-empty-sub">' + (i18nRT.trafficUpdates || 'Traffic updates in real-time.') + '</div></div>';
				// Initialize Lucide icons
				if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
					lucide.createIcons();
				}
				if (typeof window.updateRealtimeMap === 'function') { window.updateRealtimeMap([]); }
				return;
			}

			let html = '';
			visitors.forEach(visitor => {
				// Get country code for flag icon
				const countryCode = (visitor.country_code || '').toLowerCase();
				const flagUrl = countryCode && countryCode.length === 2 ? `https://flagcdn.com/w40/${countryCode}.png` : '';
				const flagHtml = flagUrl ? `<img src="${flagUrl}" alt="${visitor.country}" class="visitor-flag-icon" />` : '<span class="visitor-flag">${visitor.flag}</span>';

				html += `
					<div class="visitor-item grid">
						<span class="visitor-datetime">${(visitor.visited_at || '') + (visitor.time_ago ? ' - <span class="ago">' + visitor.time_ago + '</span>' : '')}</span>
						<span class="visitor-flag-country">${flagHtml} <span class="visitor-location">${visitor.country}</span></span>
						<span class="visitor-pageblock"><span class="visitor-title">${visitor.page_title || ''}</span><a class="visitor-url" href="${visitor.current_url || '#'}" target="_blank" rel="noopener">${visitor.current_url || ''}</a></span>
						<span class="visitor-ip-col">${shortIP(visitor.ip)}<\/span>
					</div>
				`;
			});
			container.innerHTML = html;

			// Update visitor count
			const countElement = document.querySelector('.visitor-count');
			if (countElement) {
				countElement.textContent = visitors.length;
			}

			// ---- Real-time Map (Leaflet) ----
			// Leaflet is now enqueued via wp_enqueue_script
			(function(){
				function loadLeaflet(cb){
					if(window.L){
						cb();
						return;
					}
					// Wait for Leaflet to load (it's enqueued in the page)
					var attempts = 0;
					var i18n = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
					var mapUnavailable = i18n.mapUnavailable || 'Map unavailable';
					var checkLeaflet = setInterval(function(){
						if(window.L || attempts++ > 50){
							clearInterval(checkLeaflet);
							if(window.L){
								cb();
							} else {
								var el=document.getElementById('realtime-map');
								if(el && !el.querySelector('.map-fallback')){
									el.innerHTML='<div class="map-fallback">' + mapUnavailable + '</div>';
								}
							}
						}
					}, 100);
				}

				const COUNTRY_CENTROIDS = {
					'US':[37.8,-96], 'CA':[56,-106], 'MX':[23,-102], 'BR':[-10,-55], 'AR':[-34,-64], 'CL':[-30,-71], 'CO':[4,-74], 'PE':[-9,-75], 'VE':[8,-66],
					'GB':[54,-2], 'IE':[53,-8], 'FR':[46,2], 'DE':[51,10], 'ES':[40,-4], 'PT':[39,-8], 'IT':[42,12], 'NL':[52,5], 'BE':[50.5,4.5], 'CH':[47,8], 'AT':[47.5,14],
					'PL':[52,19], 'CZ':[49.8,15.5], 'SK':[48.7,19.7], 'HU':[47,19], 'RO':[45.9,25], 'BG':[42.7,25.5], 'GR':[39,22], 'SE':[62,15], 'NO':[62,10], 'FI':[64,26], 'DK':[56,10], 'IS':[65,-19], 'EE':[59,26], 'LV':[57,25], 'LT':[55,24],
					'RU':[61,99], 'UA':[49,32], 'BY':[53,28], 'TR':[39,35], 'IL':[31.5,35], 'SA':[24,45], 'AE':[24.5,54.5], 'IR':[32,53], 'IQ':[33,44], 'JO':[31,36], 'LB':[33.9,35.8], 'SY':[35,38], 'QA':[25.3,51.2], 'KW':[29.3,47.5], 'OM':[21,57],
					'IN':[22,79], 'PK':[30,70], 'BD':[24,90], 'LK':[7,81], 'NP':[28,84],
					'CN':[35,103], 'HK':[22.3,114.2], 'TW':[23.7,121], 'JP':[36,138], 'KR':[36,128], 'VN':[16,106], 'TH':[15,101], 'MY':[4,102], 'SG':[1.35,103.8], 'ID':[-2,118], 'PH':[12.7,122],
					'AU':[-25,133], 'NZ':[-41,174],
					'EG':[27,30], 'MA':[31,-7], 'DZ':[28,2.6], 'TN':[34,9], 'KE':[-1,37], 'NG':[9,8], 'GH':[7.9,-1], 'ET':[8,39], 'TZ':[-6,35], 'UG':[1,32], 'ZA':[-30,25]
				};
						// Normalize a country to ISO alpha-2
						function flagEmojiToCode(flag){ try{ if(!flag) return ''; const cps=[...flag]; if(cps.length<2) return ''; const base=0x1F1E6; const a=cps[0].codePointAt(0)-base; const b=cps[1].codePointAt(0)-base; if(a<0||a>25||b<0||b>25) return ''; return String.fromCharCode(65+a,65+b); }catch(e){ return ''; } }
						const NAME_TO_CODE = { 'FRANCE':'FR','UNITED STATES':'US','USA':'US','UNITED KINGDOM':'GB','UK':'GB','GREAT BRITAIN':'GB','CANADA':'CA','MOROCCO':'MA','ALGERIA':'DZ','TUNISIA':'TN','SPAIN':'ES','PORTUGAL':'PT','GERMANY':'DE','ITALY':'IT','NETHERLANDS':'NL','BELGIUM':'BE','SWITZERLAND':'CH','AUSTRALIA':'AU','NEW ZEALAND':'NZ','INDIA':'IN','PAKISTAN':'PK','BANGLADESH':'BD','SRI LANKA':'LK','NEPAL':'NP','CHINA':'CN','HONG KONG':'HK','TAIWAN':'TW','JAPAN':'JP','SOUTH KOREA':'KR','VIETNAM':'VN','THAILAND':'TH','MALAYSIA':'MY','SINGAPORE':'SG','INDONESIA':'ID','PHILIPPINES':'PH','RUSSIA':'RU','UKRAINE':'UA','BELARUS':'BY','TURKEY':'TR','ISRAEL':'IL','SAUDI ARABIA':'SA','UAE':'AE','IRAN':'IR','IRAQ':'IQ','JORDAN':'JO','LEBANON':'LB','SYRIA':'SY','QATAR':'QA','KUWAIT':'KW','OMAN':'OM','EGYPT':'EG','KENYA':'KE','NIGERIA':'NG','GHANA':'GH','ETHIOPIA':'ET','TANZANIA':'TZ','UGANDA':'UG','SOUTH AFRICA':'ZA','POLAND':'PL','CZECHIA':'CZ','CZECH REPUBLIC':'CZ','SLOVAKIA':'SK','HUNGARY':'HU','ROMANIA':'RO','BULGARIA':'BG','GREECE':'GR','SWEDEN':'SE','NORWAY':'NO','FINLAND':'FI','DENMARK':'DK','ICELAND':'IS','ESTONIA':'EE','LATVIA':'LV','LITHUANIA':'LT','IRELAND':'IE','MEXICO':'MX','BRAZIL':'BR','ARGENTINA':'AR','CHILE':'CL','COLOMBIA':'CO','PERU':'PE','VENEZUELA':'VE' };
						function normalizeCountryCode(v){ let code=((v&& (v.country_code||v.countryCode||v.cc||''))+'').trim().toUpperCase(); if(/^[A-Z]{2}$/.test(code)) return code; const fromFlag=flagEmojiToCode(v&&v.flag); if(fromFlag) return fromFlag; const name=(((v&&v.country)||'')+'').trim().toUpperCase(); if(NAME_TO_CODE[name]) return NAME_TO_CODE[name]; return ''; }


				function countryToLatLng(code){ code=(code||'').toUpperCase(); return COUNTRY_CENTROIDS[code] || null; }

				function ensureRealtimeMap(){
					return new Promise(function(resolve){
						var el=document.getElementById('realtime-map'); if(!el){ resolve(null); return; }
						function init(){ if(!window.realtimeMap){ window.realtimeMap = L.map(el, { worldCopyJump:false, attributionControl:false, minZoom:1, zoomAnimation:false, markerZoomAnimation:false, zoomSnap:1, maxBounds:[[-85,-180],[85,180]], maxBoundsViscosity:0.0 }); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 5, attribution: '', noWrap:true, continuousWorld:false }).addTo(window.realtimeMap); window.rtMapMarkers = {}; try{ window.realtimeMap.setView([20,0], 2); setTimeout(function(){ try{ window.realtimeMap.invalidateSize(true); }catch(e){} }, 0); setTimeout(function(){ try{ window.realtimeMap.invalidateSize(true); }catch(e){} }, 300); }catch(e){} } resolve(window.realtimeMap); }
						if (window.L) { init(); }
						else { var n=0; (function wait(){ if(window.L){ init(); } else if(n++<50){ setTimeout(wait,100); } else { resolve(null); } })(); }
					});
				}

				window.updateRealtimeMap = function(visitors){
					var data = Array.isArray(visitors) ? visitors.slice() : [];
					ensureRealtimeMap().then(function(map){
						if(!map) return;
						try{ map.invalidateSize(true); }catch(e){}
						var markers = window.rtMapMarkers || (window.rtMapMarkers = {});
						var active = {};
						// Group visitors by normalized country code so we place ONE marker per country
						var groups = {};
						data.forEach(function(v){
							var code = normalizeCountryCode(v);
							if(!code) return;
							var latlng = countryToLatLng(code);
							if(!(Array.isArray(latlng) && latlng.length === 2)) return;
							var lat = parseFloat(latlng[0]), lng = parseFloat(latlng[1]);
							if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
							var key = 'country:' + code;
							if(!groups[key]) groups[key] = { code: code, latlng: [lat, lng], visitors: [] };
							groups[key].visitors.push(v);
						});
						Object.keys(groups).forEach(function(key){
							var g = groups[key];
							active[key] = true;
							var latlng = g.latlng;
							var count = g.visitors.length;
							var title = g.code + ' — ' + count + ' live';
							if(!markers[key]){
								try {
									markers[key] = L.marker(latlng)
										.addTo(map)
										.bindTooltip(title);
									markers[key].setIcon(L.divIcon({ className: 'realtime-pulse-icon', iconSize: [50,50], iconAnchor: [25,25] }));
								} catch(e){}
							} else {
								try { markers[key].setLatLng(latlng); markers[key].setTooltipContent(title); } catch(e){}
							}
						});
						// Remove markers for countries no longer active
						Object.keys(markers).forEach(function(id){
							if(!active[id]){ try { map.removeLayer(markers[id]); } catch(e){} delete markers[id]; }
						});
						var ids = Object.keys(markers);
						try { map.invalidateSize(false); } catch(e){}
						if(ids.length){
							try {
								var bounds = L.latLngBounds(ids.map(function(id){ return markers[id].getLatLng(); }));
								if(bounds.isValid()){
									if(!window.rtMapDidAutoFit){ map.fitBounds(bounds, { padding:[20,20], animate:false }); window.rtMapDidAutoFit = true; }
								}
							} catch(e){}
						}
					});
				};
					// Fallback message if Leaflet fails to load within 5s (e.g., offline)
					var i18nOffline = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
					var mapUnavailableOffline = i18nOffline.mapUnavailableOffline || 'Map unavailable (offline)';
					setTimeout(function(){ if(!window.L){ var el=document.getElementById('realtime-map'); if(el && !el.querySelector('.map-fallback')){ el.innerHTML='<div class="map-fallback">' + mapUnavailableOffline + '</div>'; } } }, 5000);

			})();
				if (typeof window.updateRealtimeMap === 'function') { window.updateRealtimeMap(visitors); }

		}

		// Initialize the realtime map immediately on page load (even with 0 visitors)
		(function initRealtimeMapOnLoad(){
			// Show loading indicator
			if (typeof window.showWidgetLoading === 'function') {
				window.showWidgetLoading('realtime-map-widget');
			}

			function tryInit(){
				var mapEl = document.getElementById('realtime-map');
				if(mapEl && window.L && typeof window.realtimeMap === 'undefined'){
					try {
						window.realtimeMap = L.map(mapEl, { worldCopyJump:false, attributionControl:false, minZoom:1, zoomAnimation:false, markerZoomAnimation:false, zoomSnap:1, maxBounds:[[-85,-180],[85,180]], maxBoundsViscosity:0.0 });
						L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 5, attribution: '', noWrap:true, continuousWorld:false }).addTo(window.realtimeMap);
						window.rtMapMarkers = {};
						window.realtimeMap.setView([20,0], 2);
						setTimeout(function(){ try{ window.realtimeMap.invalidateSize(true); }catch(e){} }, 0);
						setTimeout(function(){ try{ window.realtimeMap.invalidateSize(true); }catch(e){} }, 300);

						// Hide loading indicator after map is initialized
						if (typeof window.hideWidgetLoading === 'function') {
							window.hideWidgetLoading('realtime-map-widget');
						}
					} catch(e) {
						// console.error('Failed to initialize realtime map:', e);
						// Hide loading indicator on error
						if (typeof window.hideWidgetLoading === 'function') {
							window.hideWidgetLoading('realtime-map-widget');
						}
					}
				} else if(!window.L) {
					// Leaflet not loaded yet, try again
					setTimeout(tryInit, 100);
				}
			}
			if(document.readyState === 'loading'){
				document.addEventListener('DOMContentLoaded', tryInit);
			} else {
				tryInit();
			}
		})();

	// ---- Top Users Widget ----
	(function(){
		function fmt(t){ t=parseInt(t||0,10); if(t<60) return t+'s'; var h=Math.floor(t/3600), m=Math.floor((t%3600)/60), s=t%60; return (h?(h+':'):'')+('0'+m).slice(-2)+':'+('0'+s).slice(-2); }
		function num(n){ n=parseFloat(n); return isNaN(n) ? 0 : n; }
		function esc(s){ return (s||'').toString().replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
		function loadTU(){
			// Show loading indicator
			if (typeof window.showWidgetLoading === 'function') {
				window.showWidgetLoading('top-users-widget');
			}

			var fd = new FormData();
			fd.append('action','optibehavior_top_users');
			var nonce = '' + opti_behaviorDashboard.nonce + '';
			fd.append('nonce', nonce);
			var periodEl = document.getElementById('dashboard-period');
			var sEl = document.getElementById('start-date');
			var eEl = document.getElementById('end-date');
			var per = periodEl ? (periodEl.value || 'last30days') : 'last30days';
			fd.append('period', per);
			// Add exclude_spam parameter
			var urlParams = new URLSearchParams(window.location.search);
			fd.append('exclude_spam', window.optiBehaviorCurrentExcludeSpamFlag());
			if ((per === 'custom') || (sEl && eEl && sEl.value && eEl.value)) {
				fd.append('start_date', sEl && sEl.value ? sEl.value : '');
				fd.append('end_date', eEl && eEl.value ? eEl.value : '');
			}
			// Advanced filters: mirror the main widget loader so Top Engaged Users
			// obeys the same filter panel (see initAsyncWidgets / dashboard_data path).
			var tuAdvFilters = window.optiBehaviorAdvancedFilters || {};
			if (Object.keys(tuAdvFilters).length > 0) {
				fd.append('advanced_filters', JSON.stringify(tuAdvFilters));
			}
			// Function to get flag image HTML for a country code
			function getFlagHTML(cc, countryName) {
				try {
					var code = (cc||'').toString().trim().toUpperCase();
					if(/^[A-Z]{2}$/.test(code)) {
						// Use flagcdn.com for high-quality flag images
						return '<img src="https://flagcdn.com/w20/' + code.toLowerCase() + '.png" ' +
							'srcset="https://flagcdn.com/w40/' + code.toLowerCase() + '.png 2x" ' +
							'alt="' + (countryName || code) + '" ' +
							'class="tu-flag-img" ' +
							'style="width:20px;height:15px;margin-right:6px;vertical-align:middle;border-radius:2px;box-shadow:0 1px 2px rgba(0,0,0,0.1);" />';
					}
				} catch(e) {}
				// Fallback to globe emoji for unknown countries
				return '<span style="margin-right:6px;">🌐</span>';
			}

			return fetch('' + opti_behaviorDashboard.ajaxUrl + '', {method:'POST', credentials:'same-origin', body: fd})
			.then(r=>r.json()).then(function(resp){
				var body = document.getElementById('optibehavior-tu2-body'); if(!body) return;
				if(!resp || !resp.success || !resp.data || !resp.data.items || !resp.data.items.length){
					// No data - hide table and show empty state
					var wrap = body && body.closest ? body.closest('.optibehavior-table-wrap') : null;
					var content = wrap ? wrap.parentElement : null;
					if (wrap) wrap.style.display = 'none';
					if (content) {
						var empty = content.querySelector('.optibehavior-empty-state');
						if (empty) {
							empty.classList.add('is-visible');
							// Initialize Lucide icons
							if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
								lucide.createIcons();
							}
						}
					}
					// Hide loading indicator
					if (typeof window.hideWidgetLoading === 'function') {
						window.hideWidgetLoading('top-users-widget');
					}
					return;
				}

				// We have data - show table and hide empty state
				var wrap = body && body.closest ? body.closest('.optibehavior-table-wrap') : null;
				if (wrap) wrap.style.display = '';
				var content = wrap ? wrap.parentElement : null;
				if (content) {
					var empty = content.querySelector('.optibehavior-empty-state');
					if (empty) empty.classList.remove('is-visible');
				}

				var rows = resp.data.items.map(function(it,idx){
					// Display username with profile link if available, otherwise show visitor ID
					var isLoggedIn = it.user_id && it.user_id > 0 && it.display_name;
					var visitorDisplay = '';
					if (isLoggedIn) {
						// User is a logged-in WordPress user - show display name with profile link
						visitorDisplay = '<a href="' + esc(it.profile_url||'') + '" target="_blank" rel="noopener" title="' + ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.viewUserProfile)||'View user profile') + '">' +
							esc(it.display_name) + '</a>';
					} else {
						// Anonymous visitor - show visitor ID
						visitorDisplay = esc(it.visitor_id||'');
					}

					return '<tr'+ (isLoggedIn ? ' class="tu-logged-in-user"' : '') +'>'+
						'<td class="tu-rank">'+ (idx+1) +'</td>'+
						'<td class="tu-visitor">'+ visitorDisplay +'</td>'+
						'<td class="tu-num">'+ num(it.daily_freq).toFixed(2) +'</td>'+
						'<td class="tu-num"><span class="tu-chip">'+ fmt(it.avg_session_time||0) +'</span></td>'+
						'<td class="tu-num">'+ fmt(it.total_time||0) +'</td>'+
						'<td class="tu-num"><span class="tu-badge">'+ (it.sessions||0) +'</span></td>'+
						'<td class="tu-num">'+ num(it.pages_per_session).toFixed(2) +'</td>'+
						'<td>'+ getFlagHTML(it.country_code, it.country_name) +'<span class="tu-country-name">'+ esc(it.country_name||'') +'</span></td>'+
						'<td class="tu-last">'+ esc(it.last_seen_human||'') +'</td>'+
					'</tr>';
				}).join('');
				body.innerHTML = rows;

				// Hide loading indicator
				if (typeof window.hideWidgetLoading === 'function') {
					window.hideWidgetLoading('top-users-widget');
				}
			}).catch(function(){
				var body = document.getElementById('optibehavior-tu2-body');
				if(body) body.innerHTML = '<tr><td colspan="9">' + ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.errorLoadingData)||'Error loading data') + '</td></tr>';

				// Hide loading indicator on error
				if (typeof window.hideWidgetLoading === 'function') {
					window.hideWidgetLoading('top-users-widget');
				}
			});
		}
		// Expose the loader so the section-aware sequential preloader (Section 2) can
		// drive it. It no longer fires on its own at page load.
		window.optiBehaviorLoadTopUsers = loadTU;

		// Check if DOM is already loaded (inline scripts run after DOMContentLoaded)
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initTopUsers);
		} else {
			initTopUsers();
		}

		function initTopUsers() {
			var apply = document.getElementById('apply-range'); if(apply) apply.addEventListener('click', function(e){ e.preventDefault(); loadTU(); });
			var refresh = document.getElementById('refresh-dashboard'); if(refresh) refresh.addEventListener('click', function(e){ e.preventDefault(); loadTU(); });
			var periodEl = document.getElementById('dashboard-period'); if(periodEl) periodEl.addEventListener('change', loadTU);
			// Initial load is orchestrated by the Section 2 preload queue, not here.
		}
	})();

	// Initialize realtime visitors with server-provided data
	(function(){
		if (typeof updateRealtimeVisitors === 'function') {
			var initialVisitors = (window.opti_behaviorData && window.opti_behaviorData.dashboard && window.opti_behaviorData.dashboard.realtime && window.opti_behaviorData.dashboard.realtime.active_visitors ? window.opti_behaviorData.dashboard.realtime.active_visitors : []);
			if (Array.isArray(initialVisitors) && initialVisitors.length > 0) {
				updateRealtimeVisitors(initialVisitors);
			}
		}
	})();

	// Visitor Heatmap Widget Initialization
	(function(){
		function loadVisitorHeatmap() {
			var container = document.getElementById('visitor-heatmap-container');
			if (!container) return;

			// Show loading indicator
			showWidgetLoading('visitor-heatmap-widget');

			var periodEl = document.getElementById('dashboard-period');
			var startEl = document.getElementById('start-date');
			var endEl = document.getElementById('end-date');
			var period = periodEl ? (periodEl.value || 'last30days') : 'last30days';

			var ajaxUrl = (window.opti_behaviorData && window.opti_behaviorData.ajaxUrl) || (window.ajaxurl) || '/wp-admin/admin-ajax.php';
			var nonce = (window.opti_behaviorData && window.opti_behaviorData.nonce) || '';

			var formData = new FormData();
			formData.append('action', 'optibehavior_visitor_heatmap');
			formData.append('nonce', nonce);
			formData.append('period', period);
			// Add exclude_spam parameter
			var urlParams = new URLSearchParams(window.location.search);
			formData.append('exclude_spam', window.optiBehaviorCurrentExcludeSpamFlag());
			if (period === 'custom' && startEl && endEl && startEl.value && endEl.value) {
				formData.append('start_date', startEl.value);
				formData.append('end_date', endEl.value);
			}
			// Carry active advanced filters so the heatmap reflects the filtered dataset
			var hmAdvFilters = window.optiBehaviorAdvancedFilters || {};
			if (Object.keys(hmAdvFilters).length > 0) {
				formData.append('advanced_filters', JSON.stringify(hmAdvFilters));
			}

			return fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				// Hide loading indicator
				hideWidgetLoading('visitor-heatmap-widget');

				if (!resp || !resp.success || !resp.data) {
					container.innerHTML = '<div class="optibehavior-empty-state is-visible"><i data-lucide="flame" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i><div class="optibehavior-empty-title">' + ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.noVisitorData)||'No visitor data available') + '</div><div class="optibehavior-empty-sub">' + ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.dataWillAppear)||'Data will appear as visitors interact with your site.') + '</div></div>';
					// Initialize Lucide icons
					if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
						lucide.createIcons();
					}
					return;
				}

				renderHeatmap(container, resp.data.heatmap_data);
			})
			.catch(function(err) {
				// Hide loading indicator on error
				hideWidgetLoading('visitor-heatmap-widget');
				// console.error('Visitor heatmap load error:', err);
				container.innerHTML = '<div class="optibehavior-empty-state is-visible"><div class="optibehavior-empty-title">' + ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.errorLoadingHeatmap)||'Error loading heatmap') + '</div></div>';
			});
		}

		function renderHeatmap(container, data) {
			if (!data || Object.keys(data).length === 0) {
				container.innerHTML = '<div class="optibehavior-empty-state is-visible"><i data-lucide="flame" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i><div class="optibehavior-empty-title">' + ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.noVisitorData)||'No visitor data available') + '</div><div class="optibehavior-empty-sub">' + ((window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n&&window.opti_behaviorDashboard.i18n.dataWillAppear)||'Data will appear as visitors interact with your site.') + '</div></div>';
				// Initialize Lucide icons
				if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
					lucide.createIcons();
				}
				return;
			}

			// Day names (1=Sunday, 2=Monday, ..., 7=Saturday)
			// Use localized day names if available, otherwise fallback to English
			var dayNames = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n && window.opti_behaviorDashboard.i18n.dayNames)
				? window.opti_behaviorDashboard.i18n.dayNames
				: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

			// Find max value for color scaling
			var maxValue = 0;
			Object.keys(data).forEach(function(day) {
				Object.keys(data[day]).forEach(function(hour) {
					maxValue = Math.max(maxValue, data[day][hour]);
				});
			});

			// Build heatmap HTML with wrapper for side-by-side layout
			var html = '<div class="heatmap-wrapper">';

			// Heatmap grid
			html += '<div class="heatmap-grid">';
			html += '<div class="heatmap-header"><div class="heatmap-corner"></div>';

			// Hour labels (0-23)
			for (var h = 0; h < 24; h++) {
				html += '<div class="heatmap-hour-label">' + h + '</div>';
			}
			html += '</div>';

			// Rows for each day
			for (var d = 1; d <= 7; d++) {
				var dayIndex = d - 1; // Convert to 0-based for array access
				html += '<div class="heatmap-row">';
				html += '<div class="heatmap-day-label">' + dayNames[dayIndex] + '</div>';

				for (var h = 0; h < 24; h++) {
					var value = (data[d] && data[d][h]) ? data[d][h] : 0;
					var intensity = maxValue > 0 ? (value / maxValue) : 0;
					var bgColor = getHeatmapColor(intensity);
					// Use dark text for light backgrounds (0-50%), white text for darker backgrounds (50-100%)
					var textColor = intensity > 0.5 ? '#ffffff' : '#1e293b';

					// Use localized "visits" string if available
					var visitsText = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n && window.opti_behaviorDashboard.i18n.visits)
						? window.opti_behaviorDashboard.i18n.visits
						: 'visits';
					html += '<div class="heatmap-cell" style="background-color: ' + bgColor + '; color: ' + textColor + ';" title="' + dayNames[dayIndex] + ' ' + h + ':00 - ' + value + ' ' + visitsText + '">';
					if (value > 0) {
						html += '<span class="heatmap-value">' + value + '</span>';
					}
					html += '</div>';
				}
				html += '</div>';
			}
			html += '</div>'; // Close heatmap-grid

			// Add vertical legend beside the heatmap
			// Use localized "High" and "Low" strings if available
			var highText = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n && window.opti_behaviorDashboard.i18n.high)
				? window.opti_behaviorDashboard.i18n.high
				: 'High';
			var lowText = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n && window.opti_behaviorDashboard.i18n.low)
				? window.opti_behaviorDashboard.i18n.low
				: 'Low';
			html += '<div class="heatmap-legend">';
			html += '<span class="legend-label legend-label-top">' + highText + ' (' + maxValue + ')</span>';
			html += '<div class="legend-gradient"></div>';
			html += '<span class="legend-label legend-label-bottom">' + lowText + '</span>';
			html += '</div>';

			html += '</div>'; // Close heatmap-wrapper

			container.innerHTML = html;
		}

		function getHeatmapColor(intensity) {
			// Enhanced gradient with stronger contrast: Deep Blue -> Cyan -> Green -> Yellow -> Orange -> Deep Red
			// 0%: Very Light Blue (almost white)
			// 0-20%: Deep Blue to Cyan
			// 20-40%: Cyan to Green
			// 40-60%: Green to Yellow
			// 60-80%: Yellow to Orange
			// 80-100%: Orange to Deep Red

			var r, g, b;

			if (intensity === 0) {
				// Very light blue for zero values
				return 'rgb(240, 248, 255)';
			} else if (intensity <= 0.2) {
				// Deep Blue to Cyan (0-20%)
				var t = intensity / 0.2;
				r = Math.round(33 + (t * (0 - 33)));      // #2196F3 to #00CED1
				g = Math.round(150 + (t * (206 - 150)));
				b = Math.round(243 + (t * (209 - 243)));
			} else if (intensity <= 0.4) {
				// Cyan to Green (20-40%)
				var t = (intensity - 0.2) / 0.2;
				r = Math.round(0 + (t * (76 - 0)));       // #00CED1 to #4CAF50
				g = Math.round(206 + (t * (175 - 206)));
				b = Math.round(209 + (t * (80 - 209)));
			} else if (intensity <= 0.6) {
				// Green to Yellow (40-60%)
				var t = (intensity - 0.4) / 0.2;
				r = Math.round(76 + (t * (255 - 76)));    // #4CAF50 to #FFEB3B
				g = Math.round(175 + (t * (235 - 175)));
				b = Math.round(80 + (t * (59 - 80)));
			} else if (intensity <= 0.8) {
				// Yellow to Orange (60-80%)
				var t = (intensity - 0.6) / 0.2;
				r = Math.round(255 + (t * (255 - 255)));  // #FFEB3B to #FF9800
				g = Math.round(235 + (t * (152 - 235)));
				b = Math.round(59 + (t * (0 - 59)));
			} else {
				// Orange to Deep Red (80-100%)
				var t = (intensity - 0.8) / 0.2;
				r = Math.round(255 + (t * (220 - 255)));  // #FF9800 to #DC143C
				g = Math.round(152 + (t * (20 - 152)));
				b = Math.round(0 + (t * (60 - 0)));
			}

			return 'rgb(' + r + ',' + g + ',' + b + ')';
		}

		// Expose the loader so the section-aware sequential preloader (Section 2) can
		// drive it. It no longer fires on its own at page load.
		window.optiBehaviorLoadVisitorHeatmap = loadVisitorHeatmap;

		// Reload on period change
		var periodEl = document.getElementById('dashboard-period');
		if (periodEl) {
			periodEl.addEventListener('change', loadVisitorHeatmap);
		}
		var applyBtn = document.getElementById('apply-range');
		if (applyBtn) {
			applyBtn.addEventListener('click', function(e) {
				e.preventDefault();
				loadVisitorHeatmap();
			});
		}
		var refreshBtn = document.getElementById('refresh-dashboard');
		if (refreshBtn) {
			refreshBtn.addEventListener('click', function(e) {
				e.preventDefault();
				loadVisitorHeatmap();
			});
		}
	})();

	// Screen Resolution Chart Initialization
	(function(){
		function initScreenResolutionChart() {
			if (typeof Chart === 'undefined') {
				setTimeout(initScreenResolutionChart, 300);
				return;
			}

			var ctx = document.getElementById('screen-resolution-chart');
			if (!ctx) return;

			var data = window.opti_behaviorData;
			if (!data || !data.dashboard || !data.dashboard.charts) return;

			var resolutionData = data.dashboard.charts.screen_resolutions || [];

			// Resolution colors
			var RESOLUTION_COLORS = ['#6366F1', '#10B981', '#F59E0B', '#EF4444', '#3B82F6', '#8B5CF6', '#14B8A6', '#F472B6', '#84CC16', '#06B6D4'];

			// Filter out invalid entries
			var validData = resolutionData.filter(function(item) {
				var res = item.resolution || '';
				return res !== '' && res !== 'unknown' && res !== 'undefined' && res !== 'other';
			});

			if (validData.length === 0) {
				// Hide the table container
				var tableContainer = document.querySelector('.resolution-table-container');
				if (tableContainer) {
					tableContainer.style.display = 'none';
				}
				// Clear stale legend rows from a previous render
				var staleResLegend = document.getElementById('resolution-legend-body');
				if (staleResLegend) {
					staleResLegend.innerHTML = '';
				}
				// Show empty state - use localized string (force: destroy any stale chart)
				if (window.optibehavior_showEmptyStateForCanvas) {
					var i18nRes = (window.opti_behaviorDashboard && window.opti_behaviorDashboard.i18n) || {};
					var noDataMsg = i18nRes.noScreenResolutionData || 'No screen resolution data available';
					window.optibehavior_showEmptyStateForCanvas('screen-resolution-chart', 'resolution', noDataMsg, true);
				}
				return;
			}

			// We have data - show the table container and hide empty state
			var tableContainer = document.querySelector('.resolution-table-container');
			if (tableContainer) {
				tableContainer.style.display = '';
			}

			// Restore canvas in case a previous empty state hid it
			ctx.style.display = '';

			// Hide empty state if it exists (data is available)
			var container = ctx.parentElement;
			if (container) {
				var emptyState = container.querySelector('.optibehavior-empty-state');
				if (emptyState) {
					emptyState.style.display = 'none';
				}
			}

			// Calculate total
			var total = validData.reduce(function(sum, item) {
				return sum + (parseInt(item.count) || 0);
			}, 0);

			// Prepare chart data
			var labels = [];
			var chartData = [];
			var chartColors = [];

			validData.forEach(function(item, index) {
				var count = parseInt(item.count) || 0;
				labels.push(item.resolution);
				chartData.push(count);
				chartColors.push(RESOLUTION_COLORS[index % RESOLUTION_COLORS.length]);
			});

			// Populate legend table
			var legendBody = document.getElementById('resolution-legend-body');
			if (legendBody) {
				legendBody.innerHTML = '';

				validData.forEach(function(item, index) {
					var count = parseInt(item.count) || 0;
					var prevCount = parseInt(item.prevCount) || 0;
					var percentage = total > 0 ? ((count / total) * 100).toFixed(1) : 0;
					var color = RESOLUTION_COLORS[index % RESOLUTION_COLORS.length];

					var tr = document.createElement('tr');
					tr.innerHTML = `
						<td class="resolution-type-cell">
							<span>
								<span class="legend-color-dot" style="background-color: ${color};"></span>
								${item.resolution}
							</span>
						</td>
						<td class="resolution-stats-cell">
							<span class="visitor-count">${count}</span>
							<span class="visitor-percentage">${percentage}%</span>
							${getTrendHTML(count, prevCount)}
						</td>
					`;
					legendBody.appendChild(tr);
				});
			}

			// Destroy existing chart if it exists
			var existingChart = Chart.getChart(ctx);
			if (existingChart) existingChart.destroy();

			// Create new donut chart
			new Chart(ctx, {
				type: 'doughnut',
				data: {
					labels: labels,
					datasets: [{
						data: chartData,
						backgroundColor: chartColors,
						borderWidth: 0
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					cutout: '70%',
					plugins: {
						legend: { display: false },
						tooltip: {
							callbacks: {
								label: function(context) {
									var count = context.parsed || 0;
									var percentage = total > 0 ? ((count / total) * 100).toFixed(1) : 0;
									return context.label + ': ' + count + ' (' + percentage + '%)';
								}
							}
						}
					}
				}
			});

				// Ensure any previous empty state is hidden now that we have valid data
				if (window.optibehavior_showEmptyStateForCanvas) {
					window.optibehavior_showEmptyStateForCanvas('screen-resolution-chart', 'resolution');
				}

		}

		// Initialize on page load
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initScreenResolutionChart);
		} else {
			initScreenResolutionChart();
		}

		// Reinitialize on period change
		var periodEl = document.getElementById('dashboard-period');
		if (periodEl) {
			periodEl.addEventListener('change', function() {
				setTimeout(initScreenResolutionChart, 500);
			});
		}

		// Reinitialize on apply button click
		var applyBtn = document.getElementById('apply-custom-range');
		if (applyBtn) {
			applyBtn.addEventListener('click', function() {
				setTimeout(initScreenResolutionChart, 500);
			});
		}

		// Reinitialize on refresh button click
		var refreshBtn = document.querySelector('.refresh-dashboard');
		if (refreshBtn) {
			refreshBtn.addEventListener('click', function() {
				setTimeout(initScreenResolutionChart, 500);
			});
		}

		// Expose to window for async loader
		window.initScreenResolutionChart = initScreenResolutionChart;
	})();

	// Bot Traffic Widget - Render bot traffic data
	function initBotTrafficWidget() {
		const data = window.opti_behaviorData;
		if (!data || !data.dashboard || !data.dashboard.bot_traffic) return;

		const botData = data.dashboard.bot_traffic;
		const total = botData.total || 0;

		if (total === 0) return; // No data, keep empty state visible

		// Get widget elements
		const widget = document.querySelector('.bot-traffic-widget');
		if (!widget) return;

		const emptyState = widget.querySelector('.optibehavior-empty-state');
		const botList = widget.querySelector('#bot-traffic-list');
		const metaDiv = widget.querySelector('#bot-traffic-meta');
		const totalSpan = widget.querySelector('#bot-traffic-total');
		const changeSpan = widget.querySelector('#bot-traffic-change');
		const changeValueSpan = widget.querySelector('#bot-traffic-change-value');

		// Hide empty state and show widget content
		if (emptyState) emptyState.classList.remove('is-visible');
		if (botList) botList.style.display = '';
		if (metaDiv) metaDiv.style.display = '';

		// Update total count
		if (totalSpan) totalSpan.textContent = total.toLocaleString();

		// Update change percentage
		const changePct = botData.change_percentage || 0;
		if (changeSpan && changeValueSpan && changePct !== 0) {
			changeSpan.style.display = '';
			changeSpan.className = 'change-indicator ' + (changePct > 0 ? 'positive' : 'negative');
			changeValueSpan.textContent = (changePct > 0 ? '↑' : '↓') + ' ' + Math.abs(changePct) + '%';
		}

		// Render bot list
		if (botList && botData.bots) {
			const bots = botData.bots;
			const botEntries = Object.entries(bots).sort((a, b) => (b[1].count || 0) - (a[1].count || 0));

			let html = '';
			botEntries.forEach(([botType, botInfo]) => {
				const count = botInfo.count || 0;
				const percentage = botInfo.percentage || 0;
				const change = typeof botInfo.change === 'number' ? botInfo.change : parseInt(botInfo.change, 10) || 0;
				const botLabel = getBotLabel(botType);
				const botIcon = getBotIcon(botType);

				let trendClass, trendIcon;
				if (change > 0) {
					trendClass = 'trend-up';
					trendIcon = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
				} else if (change < 0) {
					trendClass = 'trend-down';
					trendIcon = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
				} else {
					trendClass = 'trend-neutral';
					trendIcon = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
				}

				html += '<div class="bot-item ob-metric-row">';
				html += '  <div class="bot-info ob-metric-main">';
				html += '    <span class="bot-icon">' + botIcon + '</span>';
				html += '    <span class="bot-label ob-metric-label">' + escapeHtml(botLabel) + '</span>';
				html += '  </div>';
				html += '  <div class="bot-stats ob-metric-values">';
				html += '    <span class="visitor-count">' + count.toLocaleString() + '</span>';
				html += '    <span class="visitor-percentage">' + percentage.toFixed(1) + '%</span>';
				html += '    <span class="visitor-trend ' + trendClass + '">' + trendIcon + Math.abs(change) + '%</span>';
				html += '  </div>';
				html += '</div>';
			});

			botList.innerHTML = html;

			// Reinitialize Lucide icons for the new bot icons
			if (typeof lucide !== 'undefined' && lucide.createIcons) {
				lucide.createIcons();
			}
		}
	}

	// Helper function to get bot label
	function getBotLabel(botType) {
		const labels = {
			'oai-searchbot': 'OAI SearchBot',
			'oai-adsbot': 'OAI AdsBot',
			'gptbot': 'GPTBot',
			'chatgpt-user': 'ChatGPT User',
			'claudebot': 'ClaudeBot',
			'claude-user': 'Claude User',
			'claude-searchbot': 'Claude SearchBot',
			'claude-web': 'Claude Web',
			'anthropic-ai': 'Anthropic AI',
			'perplexitybot': 'PerplexityBot',
			'perplexity-user': 'Perplexity User',
			'google-extended': 'Google-Extended',
			'googleother': 'GoogleOther',
			'googleother-image': 'GoogleOther Image',
			'googleother-video': 'GoogleOther Video',
			'gemini': 'Gemini',
			'bard': 'Bard',
			'ccbot': 'Common Crawl',
			'bytespider': 'Bytespider',
			'amazonbot': 'Amazonbot',
			'applebot-extended': 'Applebot Extended',
			'applebot': 'Applebot',
			'meta-externalagent': 'Meta External Agent',
			'meta-facebookbot': 'FacebookBot',
			'diffbot': 'Diffbot',
			'cohere-ai': 'Cohere AI',
			'cohere-training-data-crawler': 'Cohere Training Crawler',
			'youbot': 'YouBot',
			'omgilibot': 'Omgilibot',
			'omgili': 'Omgili',
			'ai2bot': 'AI2Bot',
			'allenai': 'AllenAI',
			'duckassistbot': 'DuckAssistBot',
			'grokbot': 'GrokBot',
			'xai-bot': 'xAI Bot',
			'xai': 'xAI',
			'grok': 'Grok',
			'mistralai-user': 'MistralAI User',
			'mistral-ai': 'Mistral AI',
			'deepseekbot': 'DeepSeekBot',
			'deepseek': 'DeepSeek',
			'googlebot': 'Googlebot',
			'googlebot-image': 'Googlebot Image',
			'googlebot-news': 'Googlebot News',
			'bingbot': 'Bingbot',
			'bingpreview': 'Bing Preview',
			'yahoo': 'Yahoo Slurp',
			'duckduckbot': 'DuckDuckBot',
			'baiduspider': 'Baiduspider',
			'yandexbot': 'YandexBot',
			'petalbot': 'PetalBot',
			'sogou': 'Sogou',
			'exabot': 'Exabot',
			'seznambot': 'SeznamBot',
			'facebookbot': 'Facebook Bot',
			'facebookexternalhit': 'Facebook Bot',
			'twitterbot': 'Twitter Bot',
			'linkedinbot': 'LinkedIn Bot',
			'pinterestbot': 'Pinterest Bot',
			'whatsapp': 'WhatsApp',
			'ahrefsbot': 'AhrefsBot',
			'semrushbot': 'SemrushBot',
			'mj12bot': 'MJ12bot',
			'dotbot': 'DotBot',
			'blexbot': 'BLEXBot',
			'dataforseobot': 'DataForSeoBot',
			'siteauditbot': 'SiteAuditBot',
			'screaming-frog': 'Screaming Frog',
			'uptimerobot': 'UptimeRobot',
			'pingdom': 'Pingdom',
			'statuspage': 'StatusPage',
			'slackbot': 'Slackbot',
			'telegrambot': 'Telegram Bot',
			'other': 'Other Bots'
		};
		return labels[botType] || botType.charAt(0).toUpperCase() + botType.slice(1);
	}

	// Helper function to get bot icon
	function getBotIcon(botType) {
		const aiBotTypes = new Set([
			'oai-searchbot', 'oai-adsbot', 'gptbot', 'chatgpt-user',
			'claudebot', 'claude-user', 'claude-searchbot', 'claude-web', 'anthropic-ai',
			'perplexitybot', 'perplexity-user', 'google-extended', 'gemini', 'bard',
			'ccbot', 'bytespider', 'amazonbot', 'applebot-extended', 'meta-externalagent',
			'meta-facebookbot', 'diffbot', 'cohere-ai', 'cohere-training-data-crawler',
			'youbot', 'omgilibot', 'omgili', 'ai2bot', 'allenai', 'duckassistbot',
			'grokbot', 'xai-bot', 'xai', 'grok', 'mistralai-user', 'mistral-ai',
			'deepseekbot', 'deepseek'
		]);

		if (aiBotTypes.has(botType)) {
			return '<i data-lucide="bot"></i>';
		}

		const icons = {
			'googlebot': '<i data-lucide="search"></i>',
			'googlebot-image': '<i data-lucide="image"></i>',
			'googlebot-news': '<i data-lucide="newspaper"></i>',
			'bingbot': '<i data-lucide="search"></i>',
			'bingpreview': '<i data-lucide="eye"></i>',
			'yahoo': '<i data-lucide="search"></i>',
			'duckduckbot': '<i data-lucide="search"></i>',
			'baiduspider': '<i data-lucide="search"></i>',
			'yandexbot': '<i data-lucide="search"></i>',
			'petalbot': '<i data-lucide="search"></i>',
			'sogou': '<i data-lucide="search"></i>',
			'exabot': '<i data-lucide="search"></i>',
			'seznambot': '<i data-lucide="search"></i>',
			'facebookbot': '<i data-lucide="share-2"></i>',
			'facebookexternalhit': '<i data-lucide="share-2"></i>',
			'twitterbot': '<i data-lucide="twitter"></i>',
			'linkedinbot': '<i data-lucide="linkedin"></i>',
			'pinterestbot': '<i data-lucide="share-2"></i>',
			'whatsapp': '<i data-lucide="message-circle"></i>',
			'ahrefsbot': '<i data-lucide="bar-chart-3"></i>',
			'semrushbot': '<i data-lucide="bar-chart-3"></i>',
			'mj12bot': '<i data-lucide="bar-chart-3"></i>',
			'dotbot': '<i data-lucide="bar-chart-3"></i>',
			'blexbot': '<i data-lucide="bar-chart-3"></i>',
			'dataforseobot': '<i data-lucide="bar-chart-3"></i>',
			'siteauditbot': '<i data-lucide="bar-chart-3"></i>',
			'screaming-frog': '<i data-lucide="activity"></i>',
			'uptimerobot': '<i data-lucide="activity"></i>',
			'pingdom': '<i data-lucide="activity"></i>',
			'statuspage': '<i data-lucide="activity"></i>',
			'slackbot': '<i data-lucide="message-square"></i>',
			'telegrambot': '<i data-lucide="send"></i>',
			'other': '<i data-lucide="bot"></i>'
		};
		return icons[botType] || '<i data-lucide="bot"></i>';
	}

	// Helper function to escape HTML
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Initialize mini bar charts for stat cards history
	 */
	function initStatHistoryCharts() {
		// Check if Chart.js is loaded
		if (typeof Chart === 'undefined') {
			window.OptiBehaviorDebug.warning('Chart.js not loaded, skipping stat history charts', 'dashboard');
			return;
		}

		// Get daily history data from window object
		const data = window.opti_behaviorData;
		if (!data || !data.dashboard || !data.dashboard.daily_history) {
			window.OptiBehaviorDebug.warning('Daily history data not available', 'dashboard');
			return;
		}

		const dailyHistory = data.dashboard.daily_history;
		const dates = dailyHistory.dates || [];

		// Format dates for display (e.g., "Dec 1", "Dec 2")
		const labels = dates.map(dateStr => {
			const safeDate = String(dateStr || '');
			const parts = safeDate.split('-');
			if (parts.length === 3) {
				const year = parseInt(parts[0], 10);
				const month = parseInt(parts[1], 10);
				const day = parseInt(parts[2], 10);
				if (!Number.isNaN(year) && !Number.isNaN(month) && !Number.isNaN(day)) {
					const localDate = new Date(year, month - 1, day);
					return localDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
				}
			}
			return safeDate;
		});

		// Define color scheme for each metric
		const colors = {
			visitors: '#3b82f6',         // Blue
			sessions: '#8b5cf6',         // Purple
			pageviews: '#f59e0b',        // Orange
			avg_session_time: '#14b8a6', // Teal
			avg_scroll_depth: '#6366f1', // Indigo
			bounce_rate: '#ef4444'       // Red
		};

		// Shared external HTML tooltip for the sparklines.
		// The default Chart.js tooltip is painted inside the 280x50 canvas bitmap,
		// so it gets clipped by the tiny canvas. An external tooltip appended to
		// <body> with position:fixed escapes the canvas and floats above the bar.
		const getOrCreateSparklineTooltip = () => {
			let el = document.getElementById('opti-behavior-sparkline-tooltip');
			if (!el) {
				el = document.createElement('div');
				el.id = 'opti-behavior-sparkline-tooltip';
				el.style.position = 'fixed';
				el.style.pointerEvents = 'none';
				el.style.zIndex = '999999';
				el.style.background = 'rgba(0, 0, 0, 0.85)';
				el.style.color = '#fff';
				el.style.padding = '6px 8px';
				el.style.borderRadius = '4px';
				el.style.fontSize = '11px';
				el.style.lineHeight = '1.3';
				el.style.whiteSpace = 'nowrap';
				el.style.textAlign = 'center';
				el.style.transform = 'translate(-50%, -100%)';
				el.style.opacity = '0';
				el.style.transition = 'opacity .12s ease';
				document.body.appendChild(el);
			}
			return el;
		};

		const externalTooltipHandler = (context) => {
			const chart = context.chart;
			const tooltip = context.tooltip;
			const el = getOrCreateSparklineTooltip();

			if (!tooltip || tooltip.opacity === 0) {
				el.style.opacity = '0';
				return;
			}

			const titleText = (tooltip.title || []).join(' ');
			const bodyText = (tooltip.body || []).map((b) => (b.lines || []).join(' ')).join(' ');

			el.textContent = '';
			const titleDiv = document.createElement('div');
			titleDiv.textContent = titleText;
			titleDiv.style.fontWeight = '600';
			const bodyDiv = document.createElement('div');
			bodyDiv.textContent = bodyText;
			el.appendChild(titleDiv);
			el.appendChild(bodyDiv);

			const rect = chart.canvas.getBoundingClientRect();
			// 8px gap above the hovered bar; translate(-50%,-100%) anchors bottom-center.
			el.style.left = (rect.left + tooltip.caretX) + 'px';
			el.style.top = (rect.top + tooltip.caretY - 8) + 'px';
			el.style.opacity = '1';
		};

		// Chart configuration template
		const getChartConfig = (metricKey, label, data, color) => ({
			type: 'bar',
			data: {
				labels: labels,
				datasets: [{
					label: label,
					data: data,
					backgroundColor: color,
					borderColor: color,
					borderWidth: 0,
					borderRadius: 2,
					barPercentage: 0.8,
					categoryPercentage: 0.9,
					maxBarThickness: 40
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: {
						enabled: false, // use external HTML tooltip so it is not clipped by the tiny canvas
						external: externalTooltipHandler,
						displayColors: false,
						callbacks: {
							title: (tooltipItems) => tooltipItems[0].label,
							label: (context) => {
								let value = context.parsed.y;
								// Format based on metric type
								if (metricKey === 'avg_session_time') {
									const minutes = Math.floor(value / 60);
									const seconds = value % 60;
									return `${minutes}:${seconds.toString().padStart(2, '0')}`;
								} else if (metricKey === 'avg_scroll_depth' || metricKey === 'bounce_rate') {
									return `${value}%`;
								}
								return value.toString();
							}
						}
					}
				},
				scales: {
					x: {
						display: false,
						grid: { display: false }
					},
					y: {
						display: false,
						grid: { display: false },
						beginAtZero: true
					}
				},
				animation: {
					duration: 500,
					easing: 'easeInOutQuart'
				}
			}
		});

		// Initialize each chart
		const _li = (window.opti_behaviorDashboard&&window.opti_behaviorDashboard.i18n)||{};
		const metrics = [
			{ key: 'visitors', label: _li.visitorsLabel||'Visitors', canvasId: 'history-visitors' },
			{ key: 'sessions', label: _li.sessionsLabel||'Sessions', canvasId: 'history-sessions' },
			{ key: 'pageviews', label: _li.pageViewsLabel||'Page Views', canvasId: 'history-pageviews' },
			{ key: 'avg_session_time', label: _li.avgSessionTimeLabel||'Avg. Session Time', canvasId: 'history-avg-session-time' },
			{ key: 'avg_scroll_depth', label: _li.avgScrollDepthLabel||'Avg. Scroll Depth', canvasId: 'history-avg-scroll-depth' },
			{ key: 'bounce_rate', label: _li.bounceRateLabel||'Bounce Rate', canvasId: 'history-bounce-rate' }
		];

		metrics.forEach(metric => {
			const canvas = document.getElementById(metric.canvasId);
			if (!canvas) {
			window.OptiBehaviorDebug.warning(`Canvas not found: ${metric.canvasId}`, 'dashboard');
				return;
			}

			const chartData = dailyHistory[metric.key] || [];
			const color = colors[metric.key];

			// Check if chart already exists - destroy it first or update it
			const existingChart = Chart.getChart(canvas);
			if (existingChart) {
				// Update existing chart with new data
				existingChart.data.labels = labels;
				existingChart.data.datasets[0].data = chartData;
				existingChart.update('none');
				return;
			}

			// Create new chart
			const ctx = canvas.getContext('2d');
			new Chart(ctx, getChartConfig(metric.key, metric.label, chartData, color));
		});
	}

	// Initialize stat history charts when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initStatHistoryCharts);
	} else {
		initStatHistoryCharts();
	}

	// Expose widget rendering functions to window for async loader callbacks
	window.updateSessionsChart = updateSessionsChart;
	window.updateTopPagesEnhanced = updateTopPagesEnhanced;
	window.updateReferrers = updateReferrers;
	window.updateCountries = updateCountries;
	window.updateBrowsers = updateBrowsers;
	window.updateOperatingSystems = updateOperatingSystems;
	window.updateRealtimeVisitors = updateRealtimeVisitors;
	window.initUserIntentWidget = initUserIntentWidget;
	window.initTrafficClassificationWidget = initTrafficClassificationWidget;
	window.initBotTrafficWidget = initBotTrafficWidget;
	window.initScreenResolutionChart = initScreenResolutionChart;
	window.initStatHistoryCharts = initStatHistoryCharts;
})();

	// ========================================
	// COLLAPSIBLE DASHBOARD SECTIONS (ACCORDION)
	// ========================================
	// Section 1 starts expanded; sections 2-4 start collapsed. Their widget data is
	// NOT fetched until the user expands a section (lazy block-list loading - see the
	// list registry / expandList above). Expanding a collapsed section arms the
	// auto-cascade and lazily loads that list (parallel within), reveals already-loaded
	// cards instantly, and nudges responsive charts that rendered while hidden to
	// re-measure.
	(function() {
		'use strict';

		function setSectionState(section, expanded) {
			if (!section) { return; }
			var header = section.querySelector('.ob-dash-section-header');
			section.classList.toggle('is-collapsed', !expanded);
			if (header) {
				header.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			}
			if (expanded) {
				var index = parseInt(section.getAttribute('data-section-index'), 10);
				if (window.OptiBehaviorPreloadQueue && !isNaN(index)) {
					window.OptiBehaviorPreloadQueue.bumpSection(index);
				}
				// Re-measure responsive charts that were rendered while display:none,
				// and (re)draw any lucide icons now in the visible subtree.
				try { window.dispatchEvent(new Event('resize')); } catch (e) {}
				if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
					lucide.createIcons();
				}
			}
		}

		function initSections() {
			var headers = document.querySelectorAll('.ob-dash-section .ob-dash-section-header');
			headers.forEach(function(header) {
				header.addEventListener('click', function() {
					var section = header.closest('.ob-dash-section');
					var expanded = section.classList.contains('is-collapsed');
					setSectionState(section, expanded);
				});
			});
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initSections);
		} else {
			initSections();
		}
	})();

	// ========================================
	// SECTION SUMMARY TEASER CHIPS
	// ========================================
	// Fetches one lightweight AJAX payload (widget=section_summaries) with the
	// top value for each collapsed section and fills the header chips. The call
	// is async and never blocks first paint: skeleton chips render server-side,
	// then this module swaps in the live values. Data is always LIVE/EXACT
	// (no caching) and degrades each chip to "—" when a value is empty.
	(function() {
		'use strict';

		var EMPTY = '\u2014'; // em dash

		function buildChip(chip) {
			var el = document.createElement('span');
			el.className = 'ob-dash-chip';
			var hasData = chip && chip.has_data && chip.value;
			if (!hasData) {
				el.classList.add('is-empty');
			}

			var iconWrap = document.createElement('span');
			iconWrap.className = 'ob-dash-chip-icon';
			var icon = document.createElement('i');
			icon.setAttribute('data-lucide', (chip && chip.icon) ? chip.icon : 'circle');
			iconWrap.appendChild(icon);

			var body = document.createElement('span');
			body.className = 'ob-dash-chip-body';

			var label = document.createElement('span');
			label.className = 'ob-dash-chip-label';
			label.textContent = (chip && chip.label) ? chip.label : '';

			var value = document.createElement('span');
			value.className = 'ob-dash-chip-value';
			value.textContent = hasData ? chip.value : EMPTY;
			if (hasData) {
				value.setAttribute('title', chip.value);
			}

			body.appendChild(label);
			body.appendChild(value);
			el.appendChild(iconWrap);
			el.appendChild(body);
			return el;
		}

		function renderSection(container, chips) {
			if (!container) { return; }
			container.innerHTML = '';
			(chips || []).forEach(function(chip) {
				container.appendChild(buildChip(chip));
			});
		}

		// Replace any still-shimmering skeletons with a graceful empty fallback.
		function fallbackEmpty() {
			document.querySelectorAll('.ob-dash-section-summary').forEach(function(container) {
				container.querySelectorAll('.ob-dash-chip.is-loading').forEach(function(skeleton) {
					skeleton.classList.remove('is-loading');
					skeleton.classList.add('is-empty');
					var value = skeleton.querySelector('.ob-dash-chip-value');
					if (value) { value.textContent = EMPTY; }
				});
			});
			refreshIcons();
		}

		function refreshIcons() {
			if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
				lucide.createIcons();
			}
		}

		var loadStarted = false;

		function load() {
			// FIX 4 (Section-1 speed): in-flight guard against concurrent double-load.
			// Reset on settle (.finally) so the manual Refresh button can reload.
			// FIX B (v1.6.17): always return a promise so the orchestrator can defer
			// the Section 2-4 background preload until the chips request settles.
			if (loadStarted) { return Promise.resolve(); }
			var containers = document.querySelectorAll('.ob-dash-section-summary');
			if (!containers.length) { return Promise.resolve(); }
			loadStarted = true;

			var ajaxUrl = (window.opti_behaviorData && window.opti_behaviorData.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php';
			var nonce = (window.opti_behaviorData && window.opti_behaviorData.nonce) || '';
			var period = (window.opti_behaviorData && window.opti_behaviorData.period) || 'last30days';
			var startDate = (window.opti_behaviorData && window.opti_behaviorData.startDate) || '';
			var endDate = (window.opti_behaviorData && window.opti_behaviorData.endDate) || '';
			var excludeSpam = (typeof window.optiBehaviorCurrentExcludeSpamFlag === 'function') ? window.optiBehaviorCurrentExcludeSpamFlag() : '0';
			var forceRefresh = (typeof window.optiBehaviorCurrentForceRefreshFlag === 'function') ? window.optiBehaviorCurrentForceRefreshFlag() : '0';

			var body = new FormData();
			body.append('action', 'optibehavior_dashboard_data');
			body.append('widget', 'section_summaries');
			body.append('nonce', nonce);
			body.append('period', period);
			if (startDate) { body.append('start_date', startDate); }
			if (endDate) { body.append('end_date', endDate); }
			body.append('exclude_spam', excludeSpam);
			body.append('force_refresh', forceRefresh);
			// Carry active advanced filters so the teaser chips match the filtered widgets
			var chipsAdvFilters = window.optiBehaviorAdvancedFilters || {};
			if (Object.keys(chipsAdvFilters).length > 0) {
				body.append('advanced_filters', JSON.stringify(chipsAdvFilters));
			}

			var controller = new AbortController();
			// FIX C (v1.6.17): raise the defensive abort from 15s to 60s. On large
			// datasets the chips request can legitimately take ~50s; the old 15s abort
			// killed it (NS_BINDING_ABORTED) and painted every chip "—". A+B remove the
			// saturation that made it slow; this is the defensive backstop.
			var timeoutId = setTimeout(function() { controller.abort(); }, 60000);

			return fetch(ajaxUrl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
				signal: controller.signal
			})
			.then(function(r) { return r.json(); })
			.then(function(result) {
				clearTimeout(timeoutId);
				var summaries = result && result.success && result.data && result.data.section_summaries;
				if (!summaries) { fallbackEmpty(); return; }
				containers.forEach(function(container) {
					var key = container.getAttribute('data-section-summary');
					if (key && summaries[key]) {
						renderSection(container, summaries[key]);
					} else {
						// No data for this section: degrade its skeletons.
						container.querySelectorAll('.ob-dash-chip.is-loading').forEach(function(skeleton) {
							skeleton.classList.remove('is-loading');
							skeleton.classList.add('is-empty');
							var value = skeleton.querySelector('.ob-dash-chip-value');
							if (value) { value.textContent = EMPTY; }
						});
					}
				});
				refreshIcons();
			})
			.catch(function() {
				clearTimeout(timeoutId);
				fallbackEmpty();
			})
			.finally(function() {
				// Release the in-flight guard so explicit refreshes can reload.
				loadStarted = false;
			});
		}

		window.OptiBehaviorSectionSummaries = { load: load };

		// FIX 4 (Section-1 speed): on the analytics page the orchestrator drives
		// section_summaries AFTER Section-1 KPIs resolve. Elsewhere, auto-fire as before.
		if (!window.optiBehaviorAnalyticsPage) {
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', load);
			} else {
				load();
			}
		}
	})();
