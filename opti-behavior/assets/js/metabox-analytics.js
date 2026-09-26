/**
 * Opti Behavior - Post Metabox Analytics
 * Handles analytics display in the WordPress post editor metabox
 */
(function() {
	'use strict';

	// Initialize immediately when script loads (don't wait for DOMContentLoaded)
	function initMetaboxAnalytics() {
		var box = document.querySelector('.optibehavior-analytics-container');
		if (!box) return;

		// Get data from localized script
		var data = window.optiBehaviorMetaboxData || {};
		var pid = data.postId || box.getAttribute('data-post-id');
		var nonce = data.nonce || box.getAttribute('data-nonce');
		var ajax = data.ajaxUrl || (typeof window.ajaxurl === 'string' ? window.ajaxurl : null);

		if (!ajax || !pid || !nonce) return;

		/* --------------------------------------------------------------
		   Period selector — shared standard options, default "All time".
		   Persisted per user in the same localStorage key the frontend
		   stats bar uses, so one selector value follows the user across
		   surfaces. One selection drives every block in this widget.
		   -------------------------------------------------------------- */
		var STATS_PERIOD_KEY = 'opti_behavior_stats_period';
		var periods = (data.periods && typeof data.periods === 'object') ? data.periods : null;

		function isValidPeriod(p) {
			if (periods) {
				return Object.prototype.hasOwnProperty.call(periods, p);
			}
			return ['today', 'last7days', 'last30days', 'all'].indexOf(p) !== -1;
		}

		function defaultPeriod() {
			if (data.defaultPeriod && isValidPeriod(data.defaultPeriod)) {
				return data.defaultPeriod;
			}
			return (data.period && isValidPeriod(data.period)) ? data.period : 'all';
		}

		function readPersistedPeriod() {
			try {
				var stored = localStorage.getItem(STATS_PERIOD_KEY);
				if (stored && isValidPeriod(stored)) {
					return stored;
				}
			} catch (e) {}
			return defaultPeriod();
		}

		function persistPeriod(p) {
			try { localStorage.setItem(STATS_PERIOD_KEY, p); } catch (e) {}
		}

		function rangeForPeriod(p) {
			return (p === 'today') ? '24h' : (p === 'last7days' ? '7d' : (p === 'last30days' ? '30d' : 'all'));
		}

		var currentPeriod = readPersistedPeriod();

		var fmt = function(n) {
			try {
				return new Intl.NumberFormat().format(n);
			} catch (e) {
				return n;
			}
		};

		var fmtDur = function(s) {
			s = parseInt(s || 0, 10);
			var m = Math.floor(s / 60);
			var r = s % 60;
			return m > 0 ? (m + 'm ' + r + 's') : (s + 's');
		};

		var qs = function(sel) {
			return document.querySelector(sel);
		};

		function applyScrollable(listEl) {
			try {
				var items = listEl.querySelectorAll('.optibehavior-list-item');
				if (!items || items.length <= 5) {
					listEl.style.maxHeight = '';
					listEl.style.overflow = 'visible';
					listEl.style.overflowX = 'hidden';
					return;
				}
				var first = items[0];
				var h = first.getBoundingClientRect().height || 40; // fallback height
				var gap = 8; // grid gap matches .optibehavior-list
				var maxH = (h * 5) + (gap * 4);
				listEl.style.maxHeight = Math.round(maxH) + 'px';
				listEl.style.overflowY = 'auto';
				listEl.style.overflowX = 'hidden';
				listEl.classList.add('optibehavior-scroll-area');
			} catch (e) {}
		}

		var S = (window.optiBehaviorMetaboxData && window.optiBehaviorMetaboxData.i18n) || {};

		// Every value below comes from visitor-supplied tracking data: escape it
		// before it is concatenated into an innerHTML string (text or attribute).
		function escHtml(value) {
			return String(value === null || value === undefined ? '' : value)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}

		// Only http(s) / mailto / tel URLs become links; anything else
		// (javascript:, data:, garbage) is shown as plain text.
		function isHttpUrl(value) {
			return typeof value === 'string' && /^(https?:\/\/|mailto:|tel:)[^\s"'<>`]+$/i.test(value);
		}

		// Load the top-line + Session Type block for the active period. Chart
		// default range follows the selected period.
		function loadPrimary() {
			var defaultRange = rangeForPeriod(currentPeriod);
			var loadingEl = qs('#optibehavior-chart-loading');
			if (loadingEl) { loadingEl.style.display = 'flex'; }
			fetch(ajax, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: 'action=optibehavior_post_analytics&post_id=' + encodeURIComponent(pid) + '&nonce=' + encodeURIComponent(nonce) + '&period=' + encodeURIComponent(currentPeriod)
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
			if (!resp || !resp.success || !resp.data) {
				// Check if it's because the post is not published
				if (resp && !resp.success && resp.data && resp.data.not_published) {
					// Show a message indicating the post needs to be published first
					if (qs('#optibehavior-chart-loading')) qs('#optibehavior-chart-loading').style.display = 'none';
					var emptyEl = qs('#optibehavior-empty');
					emptyEl.textContent = S.analytics_not_published || 'Analytics will be available after this post is published.';
					emptyEl.style.display = 'block';
					qs('#optibehavior-chart').style.display = 'none';
					return;
				}
				// Hide loading, show empty message
				if (qs('#optibehavior-chart-loading')) qs('#optibehavior-chart-loading').style.display = 'none';
				qs('#optibehavior-empty').style.display = 'block';
				qs('#optibehavior-chart').style.display = 'none';
				return;
			}
			// Hide loading and empty message, show chart when data is available
			if (qs('#optibehavior-chart-loading')) qs('#optibehavior-chart-loading').style.display = 'none';
			qs('#optibehavior-empty').style.display = 'none';
			qs('#optibehavior-chart').style.display = 'block';

			qs('#optibehavior-int').textContent = fmt(resp.data.total_interactions || 0);
			qs('#optibehavior-avg').textContent = fmtDur(resp.data.avg_time);
			qs('#optibehavior-scroll').textContent = (resp.data.avg_scroll_depth || 0) + '%';
			qs('#optibehavior-bounce').textContent = (resp.data.bounce_rate || 0) + '%';
			// Desktop and Mobile Events
			if (qs('#optibehavior-pc-events')) {
				qs('#optibehavior-pc-events').textContent = fmt(resp.data.pc_clicks || 0);
			}
			if (qs('#optibehavior-mobile-events')) {
				qs('#optibehavior-mobile-events').textContent = fmt(resp.data.mobile_clicks || 0);
			}
			// Session type: New vs Returning sessions. This is session-based so
			// the two counts match the page session total instead of unique visitors.
			var sessionTypeEl = qs('#optibehavior-session-type') || qs('#optibehavior-visitor-type');
			var newSessionCount = resp.data.new_sessions || resp.data.new_visitors || 0;
			var returningSessionCount = resp.data.returning_sessions || resp.data.returning_visitors || 0;
			if (sessionTypeEl) {
				var newTitle = (S.new_sessions_tooltip || 'New Sessions: %s sessions (first session for that visitor)').replace('%s', fmt(newSessionCount));
			var retTitle = (S.returning_sessions_tooltip || 'Returning Sessions: %s sessions (visitor had an earlier session)').replace('%s', fmt(returningSessionCount));
			sessionTypeEl.innerHTML = '<span title="' + newTitle + '" style="cursor:help;">' + fmt(newSessionCount) + ' <i data-lucide="user-plus" style="width:14px;height:14px;vertical-align:middle;"></i></span> / <span title="' + retTitle + '" style="cursor:help;">' + fmt(returningSessionCount) + ' <i data-lucide="rotate-ccw" style="width:14px;height:14px;vertical-align:middle;"></i></span>';
			}
			// Re-initialize Lucide icons for the new elements
			if (typeof lucide !== 'undefined') {
				lucide.createIcons();
			}
			qs('#optibehavior-upd').textContent = resp.data.last_updated || '—';
			var periodEl = document.querySelector('.optibehavior-active-period');
			if (periodEl && resp.data.period_label) {
				periodEl.textContent = '';
				periodEl.setAttribute('aria-label', 'Analytics period: ' + resp.data.period_label);
				periodEl.setAttribute('data-period', resp.data.period || period);
				periodEl.setAttribute('data-traffic-scope', resp.data.traffic_scope || 'human');
			}
			var pc = qs('#optibehavior-view-pc');
			var mb = qs('#optibehavior-view-mob');
			if (resp.data.view_pc_url) {
				pc.href = resp.data.view_pc_url;
				pc.querySelector('span').textContent = 'PC (' + fmt(resp.data.pc_clicks || 0) + ')';
			}
			if (resp.data.view_mobile_url) {
				mb.href = resp.data.view_mobile_url;
				mb.querySelector('span').textContent = 'Mobile (' + fmt(resp.data.mobile_clicks || 0) + ')';
			}
			// Chart.js is now enqueued via wp_enqueue_script, wait for it to load
			var chartLoadAttempts = 0;
			var waitForChart = setInterval(function() {
				if (typeof Chart !== 'undefined' || chartLoadAttempts++ > 50) {
					clearInterval(waitForChart);
					if (typeof Chart !== 'undefined') {
						buildChart(defaultRange);
						// Ensure only the default range button is active (remove from all first)
						document.querySelectorAll('.optibehavior-time-filter').forEach(function(b) {
							b.classList.remove('active');
						});
						qs('[data-range="' + defaultRange + '"]').classList.add('active');
					}
				}
			}, 100);
		})
		.catch(function() {
			// Hide loading, show empty message on error
			if (qs('#optibehavior-chart-loading')) qs('#optibehavior-chart-loading').style.display = 'none';
			qs('#optibehavior-empty').style.display = 'block';
			qs('#optibehavior-chart').style.display = 'none';
		});
		}

		// Reload every period-scoped block in the widget for the active period.
		function reloadAll() {
			loadPrimary();
			loadReferrerData();
			loadOutboundClickData();
			loadCountriesData();
			loadBrowsersData();
			loadDevicesData();
		}

		setTimeout(loadPrimary, 50); // 50ms delay to allow page to render first

		function buildChart(range) {
			var body = 'action=optibehavior_post_analytics_chart&post_id=' + encodeURIComponent(pid) + '&nonce=' + encodeURIComponent(nonce) + '&range=' + encodeURIComponent(range);
			fetch(ajax, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: body
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				if (!resp || !resp.success || !resp.data) { return; }
				var data = resp.data;
				var canvas = document.getElementById('optibehavior-chart');
				if (!canvas) return;
				// Fix canvas size to avoid responsive resize loops within WP editor meta boxes
				var container = canvas.parentElement;
				var width = (container && container.clientWidth) ? container.clientWidth : (canvas.clientWidth || 600);
				canvas.width = Math.max(300, width);
				canvas.height = 280; // increased height for better visibility
				var ctx = canvas.getContext('2d');
				if (window.optibehaviorChart) {
					window.optibehaviorChart.destroy();
				}
				var desktopLabel = S.desktop || 'Desktop';
				var mobileLabel = S.mobile || 'Mobile';

				window.optibehaviorChart = new Chart(ctx, {
					type: 'line',
					data: {
						labels: data.labels,
						datasets: [
							// Total Sessions
							{
								label: S.sessions || 'Sessions',
								data: data.total_visitors,
								backgroundColor: 'rgba(249, 115, 22, 0.1)',
								borderColor: 'rgb(249, 115, 22)',
								borderWidth: 3,
								tension: 0.4,
								fill: true,
								pointRadius: 4,
								pointHoverRadius: 6,
								pointBackgroundColor: 'rgb(249, 115, 22)',
								pointBorderColor: '#fff',
								pointBorderWidth: 2,
								order: 1
							},
							// Desktop metrics
							{
								label: desktopLabel + ' Sessions',
								data: data.desktop_visitors,
								backgroundColor: 'rgba(59, 130, 246, 0.1)',
								borderColor: 'rgb(59, 130, 246)',
								borderWidth: 3,
								tension: 0.4,
								fill: true,
								pointRadius: 4,
								pointHoverRadius: 6,
								pointBackgroundColor: 'rgb(59, 130, 246)',
								pointBorderColor: '#fff',
								pointBorderWidth: 2,
								order: 2
							},
							{
								label: desktopLabel + ' Events',
								data: data.desktop_events,
								backgroundColor: 'rgba(239, 68, 68, 0.1)',
								borderColor: 'rgb(239, 68, 68)',
								borderWidth: 3,
								tension: 0.4,
								fill: true,
								pointRadius: 4,
								pointHoverRadius: 6,
								pointBackgroundColor: 'rgb(239, 68, 68)',
								pointBorderColor: '#fff',
								pointBorderWidth: 2,
								order: 3
							},
							// Mobile metrics
							{
								label: mobileLabel + ' Sessions',
								data: data.mobile_visitors,
								backgroundColor: 'rgba(34, 197, 94, 0.1)',
								borderColor: 'rgb(34, 197, 94)',
								borderWidth: 3,
								tension: 0.4,
								fill: true,
								pointRadius: 4,
								pointHoverRadius: 6,
								pointBackgroundColor: 'rgb(34, 197, 94)',
								pointBorderColor: '#fff',
								pointBorderWidth: 2,
								order: 4
							},
							{
								label: mobileLabel + ' Events',
								data: data.mobile_events,
								backgroundColor: 'rgba(168, 85, 247, 0.1)',
								borderColor: 'rgb(168, 85, 247)',
								borderWidth: 3,
								tension: 0.4,
								fill: true,
								pointRadius: 4,
								pointHoverRadius: 6,
								pointBackgroundColor: 'rgb(168, 85, 247)',
								pointBorderColor: '#fff',
								pointBorderWidth: 2,
								order: 5
							}
						]
					},
					options: {
						responsive: false,
						animation: {
							duration: 750,
							easing: 'easeInOutQuart'
						},
						plugins: {
							legend: {
								display: true,
								position: 'top',
								labels: {
									boxWidth: 12,
									boxHeight: 12,
									padding: 10,
									font: {
										size: 11,
										weight: '600'
									},
									usePointStyle: true,
									pointStyle: 'rect'
								}
							},
							tooltip: {
								enabled: true,
								mode: 'index',
								intersect: false,
								backgroundColor: 'rgba(0, 0, 0, 0.9)',
								titleColor: '#fff',
								bodyColor: '#fff',
								borderColor: 'rgba(255, 255, 255, 0.2)',
								borderWidth: 1,
								padding: 12,
								displayColors: true,
								callbacks: {
									title: function(context) {
										return context[0].label || '';
									},
									label: function(context) {
										var label = context.dataset.label || '';
										var value = context.parsed.y || 0;
										return label + ': ' + fmt(value);
									}
								}
							}
						},
						interaction: {
							mode: 'index',
							intersect: false
						},
						scales: {
							x: {
								grid: {
									display: false
								},
								ticks: {
									font: {
										size: 10
									},
									maxRotation: 45,
									minRotation: 0
								}
							},
							y: {
								beginAtZero: true,
								grid: {
									color: 'rgba(0, 0, 0, 0.05)',
									drawBorder: false
								},
								ticks: {
									precision: 0,
									font: {
										size: 10
									},
									callback: function(value) {
										return fmt(value);
									}
								}
							}
						}
					}
				});
			});
		}

		// Time filter buttons (chart granularity — independent of the period).
		Array.prototype.forEach.call(document.querySelectorAll('.optibehavior-time-filter'), function(btn) {
			btn.addEventListener('click', function(e) {
				e.preventDefault();
				var r = this.getAttribute('data-range');
				if (!r) return;
				// Reset all buttons
				document.querySelectorAll('.optibehavior-time-filter').forEach(function(b) {
					b.classList.remove('active');
				});
				// Set clicked button as active
				this.classList.add('active');
				buildChart(r);
			});
		});

		// Period selector — one control drives every block in this widget.
		var periodSelect = document.querySelector('.optibehavior-period-select');
		if (periodSelect) {
			periodSelect.value = currentPeriod;
			periodSelect.addEventListener('change', function() {
				if (!isValidPeriod(this.value)) {
					this.value = currentPeriod;
					return;
				}
				currentPeriod = this.value;
				persistPeriod(currentPeriod);
				reloadAll();
			});
		}

		// Load secondary data with staggered delays for better performance
		// This prevents all AJAX requests from firing simultaneously
		setTimeout(loadReferrerData, 100);
		setTimeout(loadOutboundClickData, 150);
		setTimeout(loadCountriesData, 200);
		setTimeout(loadBrowsersData, 250);
		setTimeout(loadDevicesData, 300);

		function loadReferrerData() {
			fetch(ajax, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: 'action=optibehavior_post_referrers&post_id=' + encodeURIComponent(pid) + '&nonce=' + encodeURIComponent(nonce) + '&period=' + encodeURIComponent(currentPeriod)
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var loading = document.getElementById('optibehavior-referrers-loading');
				var list = document.getElementById('optibehavior-referrers-list');
				var empty = document.getElementById('optibehavior-referrers-empty');

				loading.style.display = 'none';

				// Check if we have valid data
				if (resp && resp.success) {
					// If data exists and has items, display them
					if (resp.data && resp.data.length > 0) {
						var html = '<div class="optibehavior-list">';
						resp.data.forEach(function(item) {
							// Use Lucide icons instead of emojis
							var typeIcon = item.referrer_type === 'direct' ? '<i data-lucide="link" style="width:16px;height:16px;"></i>' : (item.referrer_type === 'internal' ? '<i data-lucide="home" style="width:16px;height:16px;"></i>' : '<i data-lucide="globe" style="width:16px;height:16px;"></i>');
							var fullUrl = item.referrer_url || '';
							var isDirect = item.referrer_type === 'direct' || !fullUrl;
							var displayUrl = isDirect ? (S.direct_visit || 'Direct Visit') : fullUrl;

							html += '<div class="optibehavior-list-item optibehavior-entry-item" style="display:grid;grid-template-columns:1fr auto;align-items:center;column-gap:2px;padding:8px 2px 8px 2px;background:#f8fafc;border-radius:6px;border-left:3px solid #3b82f6;">';
							html += '<div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1;"><span style="margin-right:6px;">' + typeIcon + '</span>';
							if (isDirect || !isHttpUrl(fullUrl)) {
								html += '<strong style="font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0;display:block;box-sizing:border-box;max-width:calc(100% - 12px);padding-right:12px;">' + escHtml(displayUrl) + '</strong>';
							} else {
								html += '<a href="' + escHtml(fullUrl) + '" target="_blank" rel="noopener" class="optibehavior-url" title="' + escHtml(fullUrl) + '" style="font-weight:600;font-size:10px;color:#0f172a;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0;display:block;box-sizing:border-box;max-width:calc(100% - 12px);padding-right:12px;">' + escHtml(displayUrl) + '</a>';
							}
							html += '</div>';
							html += '<div style="text-align:right;white-space:nowrap;flex:0 0 48px;min-width:48px;width:48px;margin-left:8px;"><span style="font-weight:600;color:#1e40af;">' + escHtml(item.count) + '</span><br><small style="color:#64748b;">' + (S.visits || 'visits') + '</small></div>';
							html += '</div>';
						});
						html += '</div>';
						list.innerHTML = html;
						list.style.display = 'block';
						empty.style.display = 'none';
						applyScrollable(list);
						// Re-initialize Lucide icons for the new elements
						if (typeof lucide !== 'undefined') {
							lucide.createIcons();
						}
					} else {
						// Empty data array means no visitors yet - show friendly message
						empty.innerHTML = '<i data-lucide="link" style="width:48px;height:48px;color:#9ca3af;stroke-width:1.5;"></i><div class="optibehavior-empty-title">' + (S.no_referrer_data || 'No referrer data available') + '</div><div class="optibehavior-empty-sub">' + (S.data_appears_after_visit || 'Data will appear once visitors access this page.') + '</div>';
						empty.className = 'optibehavior-empty-state is-visible';
						list.style.display = 'none';
						if (typeof lucide !== 'undefined') { lucide.createIcons(); }
					}
				} else {
					// Error response - show generic error message
					empty.innerHTML = '<i data-lucide="alert-circle" style="width:48px;height:48px;color:#9ca3af;stroke-width:1.5;"></i><div class="optibehavior-empty-title">' + (S.unable_load_referrer || 'Unable to load referrer data') + '</div><div class="optibehavior-empty-sub">' + (S.try_refreshing || 'Please try refreshing the page.') + '</div>';
					empty.className = 'optibehavior-empty-state is-visible';
					list.style.display = 'none';
					if (typeof lucide !== 'undefined') { lucide.createIcons(); }
				}
			})
			.catch(function() {
				document.getElementById('optibehavior-referrers-loading').style.display = 'none';
				var empty = document.getElementById('optibehavior-referrers-empty');
				empty.innerHTML = '<i data-lucide="alert-circle" style="width:48px;height:48px;color:#9ca3af;stroke-width:1.5;"></i><div class="optibehavior-empty-title">' + (S.unable_load_referrer || 'Unable to load referrer data') + '</div><div class="optibehavior-empty-sub">' + (S.try_refreshing || 'Please try refreshing the page.') + '</div>';
				empty.className = 'optibehavior-empty-state is-visible';
				if (typeof lucide !== 'undefined') { lucide.createIcons(); }
			});
		}

		function loadOutboundClickData() {
			fetch(ajax, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: 'action=optibehavior_post_outbound_clicks&post_id=' + encodeURIComponent(pid) + '&nonce=' + encodeURIComponent(nonce) + '&period=' + encodeURIComponent(currentPeriod)
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var loading = document.getElementById('optibehavior-outbound-loading');
				var list = document.getElementById('optibehavior-outbound-list');
				var empty = document.getElementById('optibehavior-outbound-empty');

				loading.style.display = 'none';

				// Check if we have valid data
				if (resp && resp.success) {
					// If data exists and has items, display them
					if (resp.data && resp.data.length > 0) {
						var html = '<div class="optibehavior-list">';
						resp.data.forEach(function(item) {
							// Use Lucide icons instead of emojis
							var typeIcon = (item.click_type === 'external') ? '<i data-lucide="external-link" style="width:16px;height:16px;"></i>' : (item.click_type === 'left' ? '<i data-lucide="log-out" style="width:16px;height:16px;"></i>' : '<i data-lucide="link" style="width:16px;height:16px;"></i>');
							var fullUrl = item.target_url || '';
							var isLeft = item.click_type === 'left' || !fullUrl;
							var displayUrl = isLeft ? (S.left_page || 'Left page') : fullUrl;

							html += '<div class="optibehavior-list-item optibehavior-exit-item" style="display:grid;grid-template-columns:1fr auto;align-items:center;column-gap:2px;padding:8px 2px 8px 2px;background:#f8fafc;border-radius:6px;border-left:3px solid #10b981;">';
							html += '<div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1;"><span style="margin-right:6px;">' + typeIcon + '</span>';
							if (isLeft || !isHttpUrl(fullUrl)) {
								html += '<strong style="font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0;display:block;box-sizing:border-box;max-width:calc(100% - 12px);padding-right:12px;">' + escHtml(displayUrl) + '</strong>';
							} else {
								html += '<a href="' + escHtml(fullUrl) + '" target="_blank" rel="noopener" class="optibehavior-url" title="' + escHtml(fullUrl) + '" style="font-weight:600;font-size:10px;color:#0f172a;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0;display:block;box-sizing:border-box;max-width:calc(100% - 12px);padding-right:12px;">' + escHtml(displayUrl) + '</a>';
							}
							html += '</div>';
							html += '<div style="text-align:right;white-space:nowrap;flex:0 0 64px;min-width:64px;width:64px;margin-left:8px;"><span style="font-weight:600;color:#059669;">' + escHtml(item.count) + '</span><br><small style="color:#64748b;">' + (S.clicks || 'clicks') + '</small></div>';
							html += '</div>';
						});
						html += '</div>';
						list.innerHTML = html;
						list.style.display = 'block';
						empty.style.display = 'none';
						applyScrollable(list);
						// Re-initialize Lucide icons for the new elements
						if (typeof lucide !== 'undefined') {
							lucide.createIcons();
						}
					} else {
						// Empty data array means no outbound clicks yet - show friendly message
						empty.innerHTML = '<i data-lucide="external-link" style="width:48px;height:48px;color:#9ca3af;stroke-width:1.5;"></i><div class="optibehavior-empty-title">' + (S.no_outbound_clicks || 'No outbound clicks recorded') + '</div><div class="optibehavior-empty-sub">' + (S.data_appears_after_click || 'Data will appear once visitors click links on this page.') + '</div>';
						empty.className = 'optibehavior-empty-state is-visible';
						list.style.display = 'none';
						if (typeof lucide !== 'undefined') { lucide.createIcons(); }
					}
				} else {
					// Error response - show generic error message
					empty.innerHTML = '<i data-lucide="alert-circle" style="width:48px;height:48px;color:#9ca3af;stroke-width:1.5;"></i><div class="optibehavior-empty-title">' + (S.unable_load_outbound || 'Unable to load outbound click data') + '</div><div class="optibehavior-empty-sub">' + (S.try_refreshing || 'Please try refreshing the page.') + '</div>';
					empty.className = 'optibehavior-empty-state is-visible';
					list.style.display = 'none';
					if (typeof lucide !== 'undefined') { lucide.createIcons(); }
				}
			})
			.catch(function() {
				document.getElementById('optibehavior-outbound-loading').style.display = 'none';
				var empty = document.getElementById('optibehavior-outbound-empty');
				empty.innerHTML = '<i data-lucide="alert-circle" style="width:48px;height:48px;color:#9ca3af;stroke-width:1.5;"></i><div class="optibehavior-empty-title">' + (S.unable_load_outbound || 'Unable to load outbound click data') + '</div><div class="optibehavior-empty-sub">' + (S.try_refreshing || 'Please try refreshing the page.') + '</div>';
				empty.className = 'optibehavior-empty-state is-visible';
				if (typeof lucide !== 'undefined') { lucide.createIcons(); }
			});
		}

		// Helper function to get country ISO code from country name
		function getCountryISO(countryName, countryCode) {
			var countryToISO = {
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

			// If country code is already a 2-letter ISO code, use it
			if (countryCode && countryCode.length === 2 && /^[A-Z]{2}$/i.test(countryCode)) {
				return countryCode.toLowerCase();
			}

			// Otherwise, look up the country name
			return countryToISO[countryName] || countryToISO[countryCode] || 'xx';
		}

		function loadCountriesData() {
			fetch(ajax, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: 'action=optibehavior_post_countries&post_id=' + encodeURIComponent(pid) + '&nonce=' + encodeURIComponent(nonce) + '&period=' + encodeURIComponent(currentPeriod)
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var table = document.getElementById('optibehavior-countries-table');
				var empty = document.getElementById('optibehavior-countries-empty');

				if (resp && resp.success && resp.data && resp.data.length > 0) {
					// Calculate total for percentage
					var total = 0;
					resp.data.forEach(function(item) {
						total += item.count || 0;
					});

					var html = '<table class="countries-table" style="width:100%;border-collapse:collapse;"><tbody>';
					resp.data.forEach(function(item) {
						var countryName = item.country_name || (S.unknown || 'Unknown');
						var countryCode = item.country || '';
						var count = item.count || 0;
						var percentage = total > 0 ? Math.round((count / total) * 100) : 0;
						var isoCode = getCountryISO(countryName, countryCode);
						var flagUrl = 'https://flagcdn.com/w40/' + isoCode + '.png';

						html += '<tr style="border-bottom:1px solid #e5e7eb;">';
						html += '<td style="padding:8px 4px;font-size:11px;font-weight:600;color:#1e293b;">';
						html += '<img src="' + flagUrl + '" alt="' + escHtml(countryName) + '" style="width:20px;height:15px;margin-right:8px;vertical-align:middle;border-radius:2px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
						html += escHtml(countryName);
						html += '</td>';
						html += '<td style="padding:8px 4px;text-align:right;font-size:11px;font-weight:600;">';
						html += '<span style="display:inline-flex;align-items:center;gap:6px;">';
						html += '<span style="background:#10b981;color:#fff;padding:4px 8px;border-radius:12px;font-size:11px;min-width:32px;text-align:center;">' + escHtml(count) + '</span>';
						html += '<span style="color:#64748b;font-size:11px;">' + percentage + '%</span>';
						html += '</span>';
						html += '</td>';
						html += '</tr>';
					});
					html += '</tbody></table>';
					table.innerHTML = html;
					table.style.display = 'block';
					empty.classList.remove('is-visible');
				} else {
					empty.classList.add('is-visible');
					table.style.display = 'none';
					// Re-initialize Lucide icons for the empty state
					if (typeof lucide !== 'undefined') {
						lucide.createIcons();
					}
				}
			})
			.catch(function() {
				var empty = document.getElementById('optibehavior-countries-empty');
				empty.classList.add('is-visible');
				// Re-initialize Lucide icons for the empty state
				if (typeof lucide !== 'undefined') {
					lucide.createIcons();
				}
			});
		}

		// Helper function to get browser icon SVG
		function getBrowserIconSVG(browserName) {
			var name = browserName.toLowerCase();
			var browserIcons = {
				'chrome': '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><circle cx="12" cy="12" r="11" fill="#fff"/><path d="M12 1a11 11 0 1 0 11 11A11 11 0 0 0 12 1zm0 2a9 9 0 0 1 7.87 4.5H12a4.5 4.5 0 0 0-3.9 2.25L4.65 5.4A9 9 0 0 1 12 3z" fill="#DB4437"/><path d="M4.65 5.4l3.45 6.35A4.5 4.5 0 0 0 12 16.5a4.47 4.47 0 0 0 2.25-.6l-3.45 6A9 9 0 0 1 4.65 5.4z" fill="#0F9D58"/><path d="M19.87 7.5A9 9 0 0 1 10.8 21.9l3.45-6A4.5 4.5 0 0 0 16.5 12a4.47 4.47 0 0 0-.6-2.25z" fill="#F4B400"/><circle cx="12" cy="12" r="4.5" fill="#4285F4"/><circle cx="12" cy="12" r="3" fill="#fff"/></svg>',
				'firefox': '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><radialGradient id="ffGrad1"><stop offset="0%" stop-color="#FFBD4F"/><stop offset="100%" stop-color="#FF9640"/></radialGradient><radialGradient id="ffGrad2"><stop offset="0%" stop-color="#FF9640"/><stop offset="100%" stop-color="#E63950"/></radialGradient></defs><circle cx="12" cy="12" r="11" fill="url(#ffGrad1)"/><path d="M12 2C8 2 5 4 4 7c0 0 2-1 4-1 0-2 2-3 4-3 3 0 5 2 5 5 0 2-1 3-2 4-2 1-3 2-3 4v1h3v-1c0-1 1-2 2-3 2-1 3-3 3-5 0-4-3-7-8-7z" fill="url(#ffGrad2)"/><ellipse cx="12" cy="19" rx="2" ry="2.5" fill="#fff"/></svg>',
				'safari': '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="safGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#1AC8FC"/><stop offset="100%" stop-color="#0D66D0"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#safGrad)"/><circle cx="12" cy="12" r="9" fill="none" stroke="#fff" stroke-width="0.5"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2" stroke="#fff" stroke-width="0.8"/><path d="M12 12L8 16" fill="#fff"/><path d="M12 12L16 8" fill="#E63950"/><polygon points="12,12 8,16 10,14 12,12" fill="#fff"/><polygon points="12,12 16,8 14,10 12,12" fill="#E63950"/></svg>',
				'edge': '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="edgeGrad1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0078D4"/><stop offset="100%" stop-color="#1490DF"/></linearGradient></defs><path d="M3 12c0-2 1-4 2-5 2-2 5-4 9-4 3 0 5 1 7 3-1-2-3-3-6-3-5 0-9 4-9 9 0 3 1 5 3 7 1 1 3 2 5 2h1c-3 0-6-1-8-3-2-2-4-4-4-6z" fill="url(#edgeGrad1)"/><path d="M21 9c1 1 2 3 2 5 0 5-4 9-9 9-2 0-4-1-6-2 2 1 4 2 6 2 5 0 9-4 9-9 0-2-1-4-2-5z" fill="#2DCCFF"/><path d="M12 7c-3 0-5 2-5 5h10c0-3-2-5-5-5z" fill="#0078D4"/></svg>',
				'opera': '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><radialGradient id="opGrad"><stop offset="0%" stop-color="#FF1B2D"/><stop offset="100%" stop-color="#A02020"/></radialGradient></defs><circle cx="12" cy="12" r="11" fill="url(#opGrad)"/><ellipse cx="12" cy="12" rx="5" ry="8" fill="none" stroke="#fff" stroke-width="1.5"/></svg>',
				'brave': '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="braveGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FB542B"/><stop offset="100%" stop-color="#CD3A1F"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#braveGrad)"/><path d="M12 4l-1 3-2-1-1 3-3 1 1 2-2 2 3 1v3l2-1 2 1v-3l3-1-2-2 1-2-3-1-1-3z" fill="#fff"/></svg>',
				'samsung': '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="samsungGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#7B4FFF"/><stop offset="100%" stop-color="#5A2FD6"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#samsungGrad)"/><path d="M8 8h8v8H8z" fill="none" stroke="#fff" stroke-width="1.5" rx="1"/><circle cx="12" cy="12" r="3" fill="#fff"/></svg>',
				'android': '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="androidGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#A4C639"/><stop offset="100%" stop-color="#7FA82E"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#androidGrad)"/><path d="M8 9h8v6c0 1-1 2-2 2h-4c-1 0-2-1-2-2V9z" fill="#fff"/><circle cx="10" cy="11" r="0.8" fill="url(#androidGrad)"/><circle cx="14" cy="11" r="0.8" fill="url(#androidGrad)"/><path d="M9 7l-1-2M15 7l1-2" stroke="#fff" stroke-width="1" stroke-linecap="round"/></svg>'
			};

			// Check if we have a predefined icon for this browser
			var iconSvg = null;
			for (var key in browserIcons) {
				if (name.indexOf(key) !== -1) {
					iconSvg = browserIcons[key];
					break;
				}
			}

			// Default browser icon
			if (!iconSvg) {
				iconSvg = '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="defGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#9CA3AF"/><stop offset="100%" stop-color="#6B7280"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#defGrad)"/><circle cx="12" cy="12" r="8" fill="none" stroke="#fff" stroke-width="0.5"/><path d="M12 4v3M12 17v3M4 12h3M17 12h3" stroke="#fff" stroke-width="1"/><circle cx="12" cy="12" r="2" fill="#fff"/></svg>';
			}

			// Uniquify gradient/defs IDs per rendered copy: inline SVG IDs are
			// document-global, and duplicate IDs (especially inside hidden
			// containers) make url(#grad) fills render blank.
			window.optiBehaviorSvgUid = (window.optiBehaviorSvgUid || 0) + 1;
			var obSvgSuffix = '-ob' + window.optiBehaviorSvgUid;
			return iconSvg
				.replace(/id="([^"]+)"/g, 'id="$1' + obSvgSuffix + '"')
				.replace(/url\(#([^)]+)\)/g, 'url(#$1' + obSvgSuffix + ')');
		}

		function loadBrowsersData() {
			fetch(ajax, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: 'action=optibehavior_post_browsers&post_id=' + encodeURIComponent(pid) + '&nonce=' + encodeURIComponent(nonce) + '&period=' + encodeURIComponent(currentPeriod)
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var table = document.getElementById('optibehavior-browsers-table');
				var empty = document.getElementById('optibehavior-browsers-empty');

				if (resp && resp.success && resp.data && resp.data.length > 0) {
					// Calculate total for percentage
					var total = 0;
					resp.data.forEach(function(item) {
						total += item.count || 0;
					});

					var html = '<table class="browsers-table" style="width:100%;border-collapse:collapse;"><tbody>';
					resp.data.forEach(function(item) {
						var browserName = item.name || (S.unknown || 'Unknown');
						var count = item.count || 0;
						var percentage = total > 0 ? Math.round((count / total) * 100) : 0;
						var browserIconSVG = getBrowserIconSVG(browserName);

						html += '<tr style="border-bottom:1px solid #e5e7eb;">';
						html += '<td style="padding:8px 4px;font-size:11px;font-weight:600;color:#1e293b;">';
						html += '<span style="display:inline-flex;align-items:center;gap:8px;">';
						html += '<span style="display:inline-block;vertical-align:middle;">' + browserIconSVG + '</span>';
						html += '<span>' + escHtml(browserName) + '</span>';
						html += '</span>';
						html += '</td>';
						html += '<td style="padding:8px 4px;text-align:right;font-size:11px;font-weight:600;">';
						html += '<span style="display:inline-flex;align-items:center;gap:6px;">';
						html += '<span style="background:#10b981;color:#fff;padding:4px 8px;border-radius:12px;font-size:11px;min-width:32px;text-align:center;">' + escHtml(count) + '</span>';
						html += '<span style="color:#64748b;font-size:11px;">' + percentage + '%</span>';
						html += '</span>';
						html += '</td>';
						html += '</tr>';
					});
					html += '</tbody></table>';
					table.innerHTML = html;
					table.style.display = 'block';
					empty.classList.remove('is-visible');
				} else {
					empty.classList.add('is-visible');
					table.style.display = 'none';
					// Re-initialize Lucide icons for the empty state
					if (typeof lucide !== 'undefined') {
						lucide.createIcons();
					}
				}
			})
			.catch(function() {
				var empty = document.getElementById('optibehavior-browsers-empty');
				empty.classList.add('is-visible');
				// Re-initialize Lucide icons for the empty state
				if (typeof lucide !== 'undefined') {
					lucide.createIcons();
				}
			});
		}

		// Helper function to get device icon SVG
		function getDeviceIconSVG(deviceName) {
			var name = deviceName.toLowerCase();

			if (name.indexOf('desktop') !== -1) {
				return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="desktopGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3B82F6"/><stop offset="100%" stop-color="#2563EB"/></linearGradient></defs><rect x="2" y="4" width="20" height="12" rx="1" fill="url(#desktopGrad)"/><rect x="4" y="6" width="16" height="8" fill="#EFF6FF"/><rect x="9" y="16" width="6" height="1" fill="url(#desktopGrad)"/><rect x="7" y="17" width="10" height="2" rx="0.5" fill="url(#desktopGrad)"/></svg>';
			} else if (name.indexOf('mobile') !== -1 || name.indexOf('phone') !== -1) {
				return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="mobileGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#10B981"/><stop offset="100%" stop-color="#059669"/></linearGradient></defs><rect x="6" y="2" width="12" height="20" rx="2" fill="url(#mobileGrad)"/><rect x="7" y="4" width="10" height="14" fill="#ECFDF5"/><circle cx="12" cy="20" r="1" fill="#ECFDF5"/></svg>';
			} else if (name.indexOf('tablet') !== -1) {
				return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="tabletGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#F59E0B"/><stop offset="100%" stop-color="#D97706"/></linearGradient></defs><rect x="4" y="2" width="16" height="20" rx="2" fill="url(#tabletGrad)"/><rect x="5" y="4" width="14" height="15" fill="#FEF3C7"/><circle cx="12" cy="20.5" r="1" fill="#FEF3C7"/></svg>';
			}

			// Default device icon
			return '<svg viewBox="0 0 24 24" style="width:18px;height:18px;"><defs><linearGradient id="devDefGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#9CA3AF"/><stop offset="100%" stop-color="#6B7280"/></linearGradient></defs><rect x="2" y="4" width="20" height="12" rx="1" fill="url(#devDefGrad)"/><rect x="4" y="6" width="16" height="8" fill="#F3F4F6"/></svg>';
		}

		function loadDevicesData() {
			fetch(ajax, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: 'action=optibehavior_post_devices&post_id=' + encodeURIComponent(pid) + '&nonce=' + encodeURIComponent(nonce) + '&period=' + encodeURIComponent(currentPeriod)
			})
			.then(function(r) { return r.json(); })
			.then(function(resp) {
				var table = document.getElementById('optibehavior-devices-table');
				var empty = document.getElementById('optibehavior-devices-empty');

				if (resp && resp.success && resp.data && resp.data.length > 0) {
					// Calculate total for percentage
					var total = 0;
					resp.data.forEach(function(item) {
						total += item.count || 0;
					});

					var html = '<table class="devices-table" style="width:100%;border-collapse:collapse;"><tbody>';
					resp.data.forEach(function(item) {
						var deviceName = item.name || (S.unknown || 'Unknown');
						var count = item.count || 0;
						var percentage = total > 0 ? Math.round((count / total) * 100) : 0;
						var deviceIconSVG = getDeviceIconSVG(deviceName);

						html += '<tr style="border-bottom:1px solid #e5e7eb;">';
						html += '<td style="padding:8px 4px;font-size:11px;font-weight:600;color:#1e293b;">';
						html += '<span style="display:inline-flex;align-items:center;gap:8px;">';
						html += '<span style="display:inline-block;vertical-align:middle;">' + deviceIconSVG + '</span>';
						html += '<span>' + escHtml(deviceName) + '</span>';
						html += '</span>';
						html += '</td>';
						html += '<td style="padding:8px 4px;text-align:right;font-size:11px;font-weight:600;">';
						html += '<span style="display:inline-flex;align-items:center;gap:6px;">';
						html += '<span style="background:#10b981;color:#fff;padding:4px 8px;border-radius:12px;font-size:11px;min-width:32px;text-align:center;">' + escHtml(count) + '</span>';
						html += '<span style="color:#64748b;font-size:11px;">' + percentage + '%</span>';
						html += '</span>';
						html += '</td>';
						html += '</tr>';
					});
					html += '</tbody></table>';
					table.innerHTML = html;
					table.style.display = 'block';
					empty.classList.remove('is-visible');
				} else {
					empty.classList.add('is-visible');
					table.style.display = 'none';
					// Re-initialize Lucide icons for the empty state
					if (typeof lucide !== 'undefined') {
						lucide.createIcons();
					}
				}
			})
			.catch(function() {
				var empty = document.getElementById('optibehavior-devices-empty');
				empty.classList.add('is-visible');
				// Re-initialize Lucide icons for the empty state
				if (typeof lucide !== 'undefined') {
					lucide.createIcons();
				}
			});
		}
	}

	// Initialize as soon as possible - try immediately, then on DOMContentLoaded, then on load
	if (document.readyState === 'loading') {
		// DOM is still loading, wait for DOMContentLoaded
		document.addEventListener('DOMContentLoaded', initMetaboxAnalytics);
	} else {
		// DOM is already loaded, initialize immediately
		initMetaboxAnalytics();
	}
})();

