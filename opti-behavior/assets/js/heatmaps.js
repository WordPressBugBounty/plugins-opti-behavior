/**
 * Heatmaps Page Scripts
 * Handles AJAX table rendering, sorting, pagination, and session counts
 * 
 * @package OptiBehavior
 */

(function(){
	// Perf debug: page load timing
	var optibehaviorPageStart = performance.now();
	window.addEventListener('load', function(){
		var secs = ((performance.now() - optibehaviorPageStart)/1000).toFixed(2);
		// console.log('[optibehavior] Heatmaps admin page load complete in ' + secs + 's');
	});
	function render(html){
		var panel = document.querySelector('.heatmaps-panel'); if(!panel) return;
		var container = panel.querySelector('[data-optibehavior-table-wrapper]');
		if(!container){ container = document.createElement('div'); container.setAttribute('data-optibehavior-table-wrapper',''); panel.appendChild(container); }
		container.innerHTML = html;
	// removed stray call; map is updated from updateRealtimeVisitors()
		var wrap = container.querySelector('[data-optibehavior-table]');
		var active = wrap && wrap.querySelector('thead a[data-sort][data-active="1"]');
		var orderby = active ? active.getAttribute('data-sort') : 'last_updated';
		var order = active ? (active.getAttribute('data-current') || 'desc') : 'desc';
		if(wrap){ wrap.dataset.orderby = orderby; wrap.dataset.order = order; }
		var oldShell = document.querySelector('.simplified-heatmap-table'); if(oldShell){ oldShell.style.display='none'; }
		// Initialize Lucide icons after rendering
		if(typeof lucide !== 'undefined'){ lucide.createIcons(); }
		// Bind smart positioning on tooltips inside the injected table HTML
		if(typeof window.reinitSmartTooltips === 'function'){ window.reinitSmartTooltips(); }
	}
	function loading(on){ let w = document.querySelector('.heatmaps-panel'); if(!w) return; w.classList.toggle('is-loading', !!on); }
	// Resilience (RC5): fetch with a hard timeout so a hung/killed backend
	// request (proxy 503 / connection reset) surfaces as an error instead of
	// an infinite skeleton.
	function fetchWithTimeout(url, opts, timeoutMs){
		if(typeof AbortController === 'undefined'){ return fetch(url, opts); }
		var ctrl = new AbortController();
		var timer = setTimeout(function(){ ctrl.abort(); }, timeoutMs);
		opts = opts || {};
		opts.signal = ctrl.signal;
		return fetch(url, opts).finally(function(){ clearTimeout(timer); });
	}
	function buildErrorNotice(message, retryAttr){
		return '<div class="notice notice-error" style="margin:12px 0;padding:12px;">' +
			'<p style="margin:0 0 8px;">' + message + '</p>' +
			'<button type="button" class="button" ' + retryAttr + '>Retry</button>' +
			'</div>';
	}
	var sortPendingRequestId = 0;
	function clearSortPending(){
		document.querySelectorAll('[data-optibehavior-table] thead a[data-sort].is-pending').forEach(function(link){
			var pendingText = link.querySelector('.opti-heatmap-sort-pending-text');
			link.classList.remove('is-pending');
			link.removeAttribute('aria-busy');
			if(pendingText){
				pendingText.setAttribute('aria-hidden', 'true');
			}
		});
	}
	function setSortPending(orderby){
		clearSortPending();
		if(!orderby){
			return;
		}
		var link = null;
		document.querySelectorAll('[data-optibehavior-table] thead a[data-sort]').forEach(function(sortLink){
			if(sortLink.getAttribute('data-sort') === orderby){
				link = sortLink;
			}
		});
		if(!link){
			return;
		}
		var pendingText = link.querySelector('.opti-heatmap-sort-pending-text');
		link.classList.add('is-pending');
		link.setAttribute('aria-busy', 'true');
		if(pendingText){
			pendingText.removeAttribute('aria-hidden');
		}
	}
	function fetchTable(params, context){
		context = context || {};
		var pendingSort = context.pendingSort || '';
		var pendingRequestId = 0;
		if(pendingSort){
			pendingRequestId = ++sortPendingRequestId;
			setSortPending(pendingSort);
		} else {
			sortPendingRequestId++;
			clearSortPending();
		}
		loading(true);
		var t0 = performance.now();
		// console.time('[optibehavior] Heatmap table AJAX');
		var form = new FormData();
		form.append('action','optibehavior_heatmaps_table');
		form.append('nonce', (opti_behaviorData && opti_behaviorData.nonce) || '');
		var periodEl = document.getElementById('dashboard-period');
		var sEl = document.getElementById('start-date');
		var eEl = document.getElementById('end-date');
		var defaultPeriod = 'all';
		if(periodEl){ form.append('period', periodEl.value || defaultPeriod); } else { form.append('period', defaultPeriod); }
		if(sEl && eEl){ if((periodEl && periodEl.value==='custom') || (sEl.value && eEl.value)){ form.append('start_date', sEl.value || ''); form.append('end_date', eEl.value || ''); } }
		var excludeSpam = null;
		if (typeof window.optiBehaviorCurrentExcludeSpamFlag === 'function') {
			excludeSpam = window.optiBehaviorCurrentExcludeSpamFlag();
		} else if (window.opti_behaviorData && typeof window.opti_behaviorData.excludeSpam !== 'undefined') {
			excludeSpam = window.opti_behaviorData.excludeSpam === '1' ? '1' : '0';
		}
		if (excludeSpam !== null) {
			form.append('exclude_spam', excludeSpam);
		}
		Object.keys(params).forEach(k=>form.append(k,params[k]));
		var attempt = context.attempt || 0;
		fetchWithTimeout(opti_behaviorData.ajaxUrl, {method:'POST', credentials:'same-origin', body:form}, 90000)
		.then(function(r){ var t1 = performance.now(); /* console.log('[optibehavior] admin-ajax responded in ' + ((t1 - t0)/1000).toFixed(2) + 's'); */ if(!r.ok){ throw new Error('HTTP ' + r.status); } return r.json(); })
		.then(function(resp){
			// console.log('[optibehavior] DEBUG: Full AJAX response:', resp);
			if(resp && resp.success){
				// console.log('[optibehavior] DEBUG: Response data:', resp.data);
				render(resp.data.html);
			} else {
				throw new Error('Unexpected response');
			}
		})
		.catch(function(err){
			if(attempt < 1){
				// One retry with backoff — the first (cold) request may have
				// warmed the server cache even if the client gave up on it.
				setTimeout(function(){ fetchTable(params, {pendingSort: pendingSort, attempt: attempt + 1}); }, 4000);
				return;
			}
			var panel = document.querySelector('.heatmaps-panel');
			if(panel){
				var container = panel.querySelector('[data-optibehavior-table-wrapper]');
				if(!container){ container = document.createElement('div'); container.setAttribute('data-optibehavior-table-wrapper',''); panel.appendChild(container); }
				container.innerHTML = buildErrorNotice('Heatmap table could not be loaded (server timeout or error).', 'data-optibehavior-table-retry data-params=\'' + JSON.stringify(params).replace(/'/g, '&#39;') + '\'');
				var oldShell = document.querySelector('.simplified-heatmap-table'); if(oldShell){ oldShell.style.display='none'; }
			}
		})
		.finally(function(){ /* console.timeEnd('[optibehavior] Heatmap table AJAX'); */ var secs = ((performance.now() - t0)/1000).toFixed(2); /* console.log('[optibehavior] Heatmaps table render completed in ' + secs + 's'); */ if(!pendingSort || pendingRequestId === sortPendingRequestId){ clearSortPending(); } loading(false); });
	}
	function fetchStats(attempt){
		attempt = attempt || 0;
		var container = document.querySelector('[data-optibehavior-stats]');
		if(!container) return;
		var form = new FormData();
		form.append('action','optibehavior_heatmaps_stats');
		form.append('nonce', (opti_behaviorData && opti_behaviorData.nonce) || '');
		var periodEl = document.getElementById('dashboard-period');
		var sEl = document.getElementById('start-date');
		var eEl = document.getElementById('end-date');
		form.append('period', (periodEl && periodEl.value) || 'all');
		if(sEl && eEl){ if((periodEl && periodEl.value==='custom') || (sEl.value && eEl.value)){ form.append('start_date', sEl.value || ''); form.append('end_date', eEl.value || ''); } }
		var excludeSpam = null;
		if (typeof window.optiBehaviorCurrentExcludeSpamFlag === 'function') {
			excludeSpam = window.optiBehaviorCurrentExcludeSpamFlag();
		} else if (window.opti_behaviorData && typeof window.opti_behaviorData.excludeSpam !== 'undefined') {
			excludeSpam = window.opti_behaviorData.excludeSpam === '1' ? '1' : '0';
		}
		if (excludeSpam !== null) { form.append('exclude_spam', excludeSpam); }
		fetchWithTimeout(opti_behaviorData.ajaxUrl, {method:'POST', credentials:'same-origin', body:form}, 60000)
		.then(function(r){ if(!r.ok){ throw new Error('HTTP ' + r.status); } return r.json(); })
		.then(function(resp){
			if(resp && resp.success && resp.data && typeof resp.data.html === 'string'){
				container.innerHTML = resp.data.html;
				if(typeof lucide !== 'undefined'){ lucide.createIcons(); }
				// Bind smart positioning on tooltips inside the injected stats HTML
				if(typeof window.reinitSmartTooltips === 'function'){ window.reinitSmartTooltips(); }
			} else {
				throw new Error('Unexpected response');
			}
		})
		.catch(function(){
			if(attempt < 1){
				// One retry with backoff — first (cold) request may have warmed
				// the server-side cache even if this client attempt failed.
				setTimeout(function(){ fetchStats(attempt + 1); }, 4000);
				return;
			}
			container.innerHTML = buildErrorNotice('Analytics stats could not be loaded (server timeout or error).', 'data-optibehavior-stats-retry');
		});
	}
	function getFrontendStatsDeepLink(){
		var params = new URLSearchParams(window.location.search);
		return {
			pageUrl: params.get('page_url') || params.get('search') || '',
			dateRange: params.get('date_range') || ''
		};
	}
	function applyFrontendStatsDeepLink(){
		var deepLink = getFrontendStatsDeepLink();
		var searchInput = document.getElementById('heatmaps-search');
		var periodEl = document.getElementById('dashboard-period');

		if(deepLink.pageUrl && searchInput){
			searchInput.value = deepLink.pageUrl;
		}

		if(deepLink.dateRange && periodEl){
			for(var i = 0; i < periodEl.options.length; i++){
				if(periodEl.options[i].value === deepLink.dateRange){
					periodEl.value = deepLink.dateRange;
					break;
				}
			}
		}

		return deepLink;
	}
	document.addEventListener('DOMContentLoaded', function(){
		var deepLink = applyFrontendStatsDeepLink();
		fetchStats();
		fetchTable({orderby:'last_updated', order:'desc', paged:1, per_page:10, search:deepLink.pageUrl || ''});
	});
	document.addEventListener('click', function(e){ var a=e.target.closest('[data-optibehavior-table] thead a[data-sort]'); if(a){ e.preventDefault(); var wrap = a.closest('[data-optibehavior-table]'); var orderby = a.getAttribute('data-sort'); var nextOrder = a.getAttribute('data-order') || 'desc'; if(wrap){ wrap.dataset.orderby = orderby; wrap.dataset.order = nextOrder; } var searchInput = document.getElementById('heatmaps-search'); var search = searchInput ? searchInput.value.trim() : ''; fetchTable({orderby:orderby, order:nextOrder, paged:1, per_page:10, search:search}, {pendingSort:orderby}); }});
	document.addEventListener('click', function(e){ var p=e.target.closest('[data-paging] .pagination-btn'); if(!p||p.hasAttribute('disabled')) return; e.preventDefault(); var pag = e.target.closest('.table-pagination'); var cur = pag && pag.querySelector('.pagination-current'); var page = parseInt(cur && cur.textContent || '1',10); var totalPages = parseInt((pag && pag.getAttribute('data-total-pages'))||'1',10); if(p.hasAttribute('data-first')) page=1; else if(p.hasAttribute('data-prev')) page=Math.max(1,page-1); else if(p.hasAttribute('data-next')) page=Math.min(totalPages,page+1); else if(p.hasAttribute('data-last')) page=totalPages; else if(p.hasAttribute('data-page')) page=parseInt(p.getAttribute('data-page'),10); var orderby = pag ? (pag.getAttribute('data-orderby') || 'last_updated') : 'last_updated'; var order = pag ? (pag.getAttribute('data-order') || 'desc') : 'desc'; var searchInput = document.getElementById('heatmaps-search'); var search = searchInput ? searchInput.value.trim() : ''; fetchTable({orderby:orderby, order:order, paged:page, per_page:10, search:search}); });

	// Search handler with debounce
	var searchTimeout;
	document.addEventListener('input', function(e){
		if(e.target && e.target.id === 'heatmaps-search'){
			clearTimeout(searchTimeout);
			searchTimeout = setTimeout(function(){
				var searchTerm = e.target.value.trim();
				var wrap = document.querySelector('[data-optibehavior-table]');
				var orderby = wrap ? (wrap.dataset.orderby || 'last_updated') : 'last_updated';
				var order = wrap ? (wrap.dataset.order || 'desc') : 'desc';
				fetchTable({orderby:orderby, order:order, paged:1, per_page:10, search:searchTerm});
			}, 300);
		}
	});

	// Resilience (RC5): retry buttons rendered by the error notices.
	document.addEventListener('click', function(e){
		var statsRetry = e.target.closest('[data-optibehavior-stats-retry]');
		if(statsRetry){
			e.preventDefault();
			var container = document.querySelector('[data-optibehavior-stats]');
			if(container){ container.innerHTML = '<div class="opti-heatmap-stats-skeleton" aria-hidden="true"></div>'; }
			fetchStats(0);
			return;
		}
		var tableRetry = e.target.closest('[data-optibehavior-table-retry]');
		if(tableRetry){
			e.preventDefault();
			var params = {orderby:'last_updated', order:'desc', paged:1, per_page:10, search:''};
			try{ params = JSON.parse(tableRetry.getAttribute('data-params')) || params; }catch(err){}
			fetchTable(params);
		}
	});

	// Scoped "Repair" badge link (spec §2/§3c): recompute the sync-status
	// cache for one page and repaint just that row's Sessions cell, no full
	// table reload.
	function repaintSessionsCell($td, sessionsTotal, entry){
		if(!$td) return;
		var strong = $td.querySelector('strong');
		// Unified all-visitors pair (same as the detail header pill):
		// first = sessions with heatmap event data (file_total_all),
		// second = HONEST detected sessions (detected_total_all = DB
		// sessions UNION orphan file sessions, spec §3.3/RC-D) when the
		// response carries it, else the legacy DB-only total (db_total_all).
		// Falls back further to the guest-scoped db_total/file_total pair
		// when the server response predates the unified fields (older cache
		// entries / Free-only).
		var fileAll = (entry && entry.file_total_all !== null && typeof entry.file_total_all !== 'undefined') ? parseInt(entry.file_total_all, 10) : null;
		var detectedAll = (entry && entry.detected_total_all !== null && typeof entry.detected_total_all !== 'undefined') ? parseInt(entry.detected_total_all, 10) : null;
		var dbAll = (entry && entry.db_total_all !== null && typeof entry.db_total_all !== 'undefined') ? parseInt(entry.db_total_all, 10) : null;
		var total, second, desynced;
		if(fileAll !== null && detectedAll !== null){
			total = fileAll;
			second = detectedAll;
			// False by construction against a fresh detected_total_all (it
			// is always >= file_total_all); can only fire against a stale pair.
			desynced = fileAll > detectedAll;
		} else if(fileAll !== null && dbAll !== null){
			total = fileAll;
			// Orphaned-files fallback, mirrors the server-rendered cell.
			second = (dbAll === 0 && fileAll > 0) ? fileAll : dbAll;
			desynced = fileAll > dbAll;
		} else {
			var dbTotal = entry && null !== entry.db_total ? parseInt(entry.db_total, 10) : null;
			var fileTotal = entry && null !== entry.file_total ? parseInt(entry.file_total, 10) : null;
			total = (dbTotal && dbTotal > 0) ? dbTotal : (fileTotal || 0);
			second = (null !== fileTotal && fileTotal !== total) ? fileTotal : null;
			desynced = !!(entry && entry.desynced);
		}
		if(strong){ strong.textContent = total; }
		$td.setAttribute('data-sessions', total);
		var existingInteractions = $td.querySelector('.sessions-interactions');
		if(existingInteractions){ existingInteractions.remove(); }
		var existingBadge = $td.querySelector('.sessions-desync-badge');
		if(existingBadge){ existingBadge.remove(); }
		if(null !== second && second !== total){
			var span = document.createElement('span');
			span.className = 'sessions-interactions';
			span.textContent = '/ ' + second;
			$td.appendChild(span);
		}
		if(desynced){
			var pageId = $td.getAttribute('data-page-id');
			var badge = document.createElement('span');
			badge.className = 'sessions-desync-badge';
			var l10n = (typeof optiBehaviorHeatmapsL10n !== 'undefined' && optiBehaviorHeatmapsL10n.strings) ? optiBehaviorHeatmapsL10n.strings : {};
			badge.title = l10n.desyncTooltip || 'Interaction files and detected sessions are out of sync for this page. Click Repair to refresh.';
			badge.innerHTML = '<i data-lucide="alert-triangle" style="width:12px;height:12px;vertical-align:middle;"></i> <a href="#" class="sessions-repair-link" data-page-id="' + pageId + '">' + (l10n.repair || 'Repair') + '</a>';
			$td.appendChild(badge);
			if(typeof lucide !== 'undefined'){ lucide.createIcons(); }
		}
	}
	// List device buttons (spec: "device buttons must share the row's
	// session-cell file universe"): repaint the row's Desktop/Mobile button
	// counts from the SAME repair response entry the Sessions cell above
	// just used (entry.file_devices_all — same all-visitors file universe as
	// file_total_all/detected_total_all), so a scoped Repair click keeps
	// both in sync without a full table reload.
	function repaintDeviceButtons($tr, devicesAll){
		if(!$tr || !devicesAll) return;
		var setCount = function($btn, n){
			if(!$btn) return;
			var count = Number(n || 0).toLocaleString();
			$btn.innerHTML = $btn.innerHTML.replace(/\([\d,]*\)/, '(' + count + ')');
		};
		setCount($tr.querySelector('.optibehavior-btn-desktop'), devicesAll.desktop);
		setCount($tr.querySelector('.optibehavior-btn-mobile'), devicesAll.mobile);
	}
	document.addEventListener('click', function(e){
		var link = e.target.closest('.sessions-repair-link');
		if(!link) return;
		e.preventDefault();
		var badge = link.closest('.sessions-desync-badge');
		var td = link.closest('td.sessions-count');
		var pageId = link.getAttribute('data-page-id');
		if(!pageId || (badge && badge.classList.contains('is-repairing'))) return;
		if(badge){ badge.classList.add('is-repairing'); }
		var form = new FormData();
		form.append('action', 'opti_behavior_repair_heatmap_sync');
		form.append('nonce', (opti_behaviorData && opti_behaviorData.nonce) || '');
		form.append('page_id', pageId);
		fetchWithTimeout(opti_behaviorData.ajaxUrl, {method:'POST', credentials:'same-origin', body:form}, 30000)
		.then(function(r){ if(!r.ok){ throw new Error('HTTP ' + r.status); } return r.json(); })
		.then(function(resp){
			if(resp && resp.success && resp.data && resp.data.counts && resp.data.counts[pageId]){
				var entry = resp.data.counts[pageId];
				repaintSessionsCell(td, null, entry);
				repaintDeviceButtons(td && td.closest('tr'), entry.file_devices_all);
			} else {
				throw new Error('Unexpected response');
			}
		})
		.catch(function(){
			if(badge){ badge.classList.remove('is-repairing'); }
		});
	});

})();

