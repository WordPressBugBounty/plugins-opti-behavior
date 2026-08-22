/**
 * Frontend Stats Bar — JavaScript
 *
 * Loads stats via AJAX, populates the skeleton bar, and manages
 * hide/unhide toggle with localStorage persistence.
 *
 * No jQuery dependency — vanilla JS only.
 *
 * @package opti-behavior
 * @since   1.2.3
 */

(function () {
	'use strict';

	var STORAGE_KEY = 'opti_behavior_stats_bar_collapsed';
	var PERIOD_KEY  = 'opti_behavior_stats_period';
	var config      = window.optiBehaviorStatsBar || {};
	var bar         = document.getElementById('opti-stats-bar');
	var pill        = document.getElementById('opti-stats-bar-pill');
	var layoutFrame = null;

	if (!bar || !pill || !config.ajax_url) {
		return;
	}

	/* ------------------------------------------------------------------
	   Page builder guard — hide the bar inside JS-activated editors.
	   Server-side detection (URL params / iframes) covers most builders;
	   this handles editors that activate without a page reload, e.g.
	   Page Builder Sandwich and Themify Builder inline mode.
	   ------------------------------------------------------------------ */
	var hideInBuilders = !config.settings ||
		config.settings.hide_in_builders === undefined ||
		!!config.settings.hide_in_builders;

	function isJsBuilderActive() {
		if (window.PBSEditor) { // Page Builder Sandwich editor booted.
			return true;
		}
		var cls = ' ' + document.body.className + ' ';
		return (
			cls.indexOf(' pbs-editing ') !== -1 ||            // Page Builder Sandwich.
			cls.indexOf(' themify_builder_active ') !== -1 || // Themify Builder inline.
			cls.indexOf(' fl-builder-edit ') !== -1 ||        // Beaver Builder (belt & suspenders).
			cls.indexOf(' et-fb ') !== -1                     // Divi FB (belt & suspenders).
		);
	}

	function hideForBuilder() {
		bar.style.display  = 'none';
		pill.style.display = 'none';
		document.body.classList.remove('opti-stats-bar-active');
		document.body.classList.add('opti-stats-bar-collapsed');
	}

	if (hideInBuilders) {
		if (isJsBuilderActive()) {
			hideForBuilder();
			return;
		}
		if (window.MutationObserver) {
			var builderObserver = new MutationObserver(function () {
				if (isJsBuilderActive()) {
					hideForBuilder();
					builderObserver.disconnect();
				}
			});
			builderObserver.observe(document.body, {
				attributes: true,
				attributeFilter: ['class'],
				childList: true
			});
		}
	}

	/* ------------------------------------------------------------------
	   Body offset — push page content below the fixed bar
	   ------------------------------------------------------------------ */
	function updateBodyOffset() {
		if (bar.style.display === 'none' || bar.offsetHeight === 0) {
			document.body.classList.remove('opti-stats-bar-active');
			document.body.classList.add('opti-stats-bar-collapsed');
		} else {
			var barHeight = bar.offsetHeight;
			document.body.style.setProperty('--osb-bar-height', barHeight + 'px');
			document.body.classList.add('opti-stats-bar-active');
			document.body.classList.remove('opti-stats-bar-collapsed');
		}
	}

	/* ------------------------------------------------------------------
	   Lucide icons — create them if the global lucide object exists
	   ------------------------------------------------------------------ */
	function initIcons() {
		if (window.lucide && typeof window.lucide.createIcons === 'function') {
			window.lucide.createIcons();
		}
	}

	/* ------------------------------------------------------------------
	   Period selector — shared standard options, default "All time".
	   Persisted per user in localStorage so the choice sticks across pages.
	   ------------------------------------------------------------------ */
	var periodSelect = bar.querySelector('.opti-stats-bar__period-select');

	/* ------------------------------------------------------------------
	   Third-party select-enhancer immunity (Select2, selectWoo, niceSelect,
	   Chosen, Selectric, SlimSelect, …).
	   Themes that run $('select').select2() hijack the period select: they
	   hide the native control and inject a large widget, inflating the bar.
	   Strategy: destroy known enhancers cleanly, sweep leftover injected
	   DOM, restore the native select, and keep watching for late re-inits.
	   ------------------------------------------------------------------ */
	var ENHANCER_SELECTOR = '.select2-container, .select2, .nice-select, ' +
		'.chosen-container, .selectric-wrapper, .selectric, .ss-main';
	var neutralizing = false;

	function neutralizeSelectEnhancers() {
		if (!periodSelect || neutralizing) {
			return;
		}
		neutralizing = true;

		try {
			var $ = window.jQuery;

			// Select2 / selectWoo — destroy removes bindings + ARIA changes.
			if ($ && $.fn && typeof $.fn.select2 === 'function') {
				try {
					var $sel = $(periodSelect);
					if ($sel.data('select2') || periodSelect.classList.contains('select2-hidden-accessible')) {
						$sel.select2('destroy');
					}
				} catch (e) { /* Already destroyed or foreign version — sweep below. */ }
			}

			// Chosen.
			if ($ && $.fn && typeof $.fn.chosen === 'function') {
				try {
					$(periodSelect).chosen('destroy');
				} catch (e) { /* Not chosen-ified — ignore. */ }
			}

			// DOM sweep — covers niceSelect, Selectric, SlimSelect and any
			// enhancer without a reachable destroy handle.
			var label = periodSelect.closest('.opti-stats-bar__period') || periodSelect.parentNode;
			var scopes = [label, bar];
			for (var s = 0; s < scopes.length; s++) {
				if (!scopes[s]) {
					continue;
				}
				var injected = scopes[s].querySelectorAll(ENHANCER_SELECTOR);
				for (var i = 0; i < injected.length; i++) {
					if (!injected[i].contains(periodSelect) && injected[i].parentNode) {
						injected[i].parentNode.removeChild(injected[i]);
					}
				}
			}

			// Restore the native select: strip hijack classes/attributes.
			periodSelect.classList.remove('select2-hidden-accessible', 'chosen-single', 'selectric-hide-select');
			periodSelect.removeAttribute('aria-hidden');
			if (periodSelect.getAttribute('tabindex') === '-1') {
				periodSelect.removeAttribute('tabindex');
			}
			periodSelect.style.removeProperty('display');
		} finally {
			neutralizing = false;
		}
	}

	function watchSelectEnhancers() {
		if (!periodSelect || !window.MutationObserver) {
			return;
		}
		var label = periodSelect.closest('.opti-stats-bar__period') || periodSelect.parentNode;
		if (!label) {
			return;
		}
		var enhancerObserver = new MutationObserver(function (mutations) {
			if (neutralizing) {
				return;
			}
			for (var m = 0; m < mutations.length; m++) {
				var added = mutations[m].addedNodes;
				for (var n = 0; n < added.length; n++) {
					var node = added[n];
					if (node.nodeType === 1 && (node.matches(ENHANCER_SELECTOR) || node.querySelector(ENHANCER_SELECTOR))) {
						neutralizeSelectEnhancers();
						scheduleResponsiveLayout();
						return;
					}
				}
			}
		});
		enhancerObserver.observe(label, { childList: true, subtree: true });
		// Bar-level guard: some libs append the widget as a sibling of the label.
		enhancerObserver.observe(bar, { childList: true });
	}

	function isValidPeriod(p) {
		if (config.periods && typeof config.periods === 'object') {
			return Object.prototype.hasOwnProperty.call(config.periods, p);
		}
		return ['today', 'last7days', 'last30days', 'all'].indexOf(p) !== -1;
	}

	function defaultPeriod() {
		return config.default_period && isValidPeriod(config.default_period) ? config.default_period : 'all';
	}

	function getPersistedPeriod() {
		try {
			var stored = localStorage.getItem(PERIOD_KEY);
			if (stored && isValidPeriod(stored)) {
				return stored;
			}
		} catch (e) {
			// localStorage not available — fall through to default.
		}
		return defaultPeriod();
	}

	var currentPeriod = getPersistedPeriod();

	function getStatsPeriod() {
		return currentPeriod;
	}

	function setStatsPeriod(p) {
		currentPeriod = isValidPeriod(p) ? p : defaultPeriod();
		try {
			localStorage.setItem(PERIOD_KEY, currentPeriod);
		} catch (e) {
			// Persistence unavailable — keep in-memory selection only.
		}
	}

	function buildCurrentPageAdminUrl(baseUrl) {
		if (!baseUrl) {
			return '';
		}

		var separator = baseUrl.indexOf('?') === -1 ? '?' : '&';

		return baseUrl +
			separator +
			'page_url=' + encodeURIComponent(window.location.href) +
			'&date_range=' + encodeURIComponent(getStatsPeriod());
	}

	function buildPageIdsParam(pageIds) {
		if (!Array.isArray(pageIds)) {
			return '';
		}

		return pageIds
			.map(function (pageId) {
				return parseInt(pageId, 10) || 0;
			})
			.filter(function (pageId) {
				return pageId > 0;
			})
			.join(',');
	}

	function appendCanonicalPageIds(url, pageIds) {
		var pageIdsParam = buildPageIdsParam(pageIds);
		if (!url || !pageIdsParam) {
			return url;
		}

		var separator = url.indexOf('?') === -1 ? '?' : '&';
		return url + separator + 'page_ids=' + encodeURIComponent(pageIdsParam);
	}

	function appendQueryParam(url, key, value) {
		if (!url || value === undefined || value === null || value === '') {
			return url;
		}

		var separator = url.indexOf('?') === -1 ? '?' : '&';
		return url + separator + encodeURIComponent(key) + '=' + encodeURIComponent(value);
	}

	/* ------------------------------------------------------------------
	   Sessions link — jump to heatmaps filtered to current page
	   ------------------------------------------------------------------ */
	function getHeatmapDetailUrl(pageId, pageIds) {
		if (!config.heatmap_detail_url || !pageId || pageId <= 0) {
			return '';
		}

		var separator = config.heatmap_detail_url.indexOf('?') === -1 ? '?' : '&';
		var pageIdsParam = buildPageIdsParam(pageIds);

		return config.heatmap_detail_url +
			separator +
			'page_id=' + encodeURIComponent(pageId) +
			(pageIdsParam ? '&page_ids=' + encodeURIComponent(pageIdsParam) : '') +
			'&type=click&device=desktop' +
			'&date_range=' + encodeURIComponent(getStatsPeriod());
	}

	function getHeatmapsUrl(pageId, pageIds) {
		var detailUrl = getHeatmapDetailUrl(pageId, pageIds);
		if (detailUrl) {
			return detailUrl;
		}

		return appendCanonicalPageIds(buildCurrentPageAdminUrl(config.heatmaps_url), pageIds);
	}

	function updateHeatmapsLinks(pageId, pageIds) {
		var heatmapsUrl = getHeatmapsUrl(pageId, pageIds);
		var links = bar.querySelectorAll('.opti-stats-bar__item--sessions-link');

		if (!heatmapsUrl || !links.length) {
			return;
		}

		for (var i = 0; i < links.length; i++) {
			links[i].setAttribute('href', heatmapsUrl);
		}
	}

	/* ------------------------------------------------------------------
	   Recordings link — jump to Pro recordings filtered to current page
	   ------------------------------------------------------------------ */
	function getRecordingsUrl(pageIds, data) {
		var url = appendCanonicalPageIds(buildCurrentPageAdminUrl(config.recordings_url), pageIds);
		var excludeSpam = data && data.pro && data.pro.recordings_exclude_spam !== undefined ? data.pro.recordings_exclude_spam : null;

		if (excludeSpam !== null) {
			url = appendQueryParam(url, 'exclude_spam', excludeSpam ? '1' : '0');
		}

		return url;
	}

	function updateRecordingsLinks(pageIds, data) {
		var recordingsUrl = getRecordingsUrl(pageIds, data);
		var links = bar.querySelectorAll('.opti-stats-bar__item--recordings-link');

		if (!recordingsUrl || !links.length) {
			return;
		}

		for (var i = 0; i < links.length; i++) {
			links[i].setAttribute('href', recordingsUrl);
		}
	}

	/* ------------------------------------------------------------------
	   Responsive layout — fit enabled Free/Pro stats to the admin viewport
	   ------------------------------------------------------------------ */
	function layoutOverflows(inner, stats) {
		return (
			stats.scrollWidth - stats.clientWidth > 1 ||
			inner.scrollWidth - inner.clientWidth > 1
		);
	}

	function applyResponsiveLayout() {
		var inner = bar.querySelector('.opti-stats-bar__inner');
		var stats = bar.querySelector('.opti-stats-bar__stats');

		if (!inner || !stats) {
			return;
		}

		bar.setAttribute('data-stat-count', stats.querySelectorAll('.opti-stats-bar__item').length);
		bar.classList.remove('opti-stats-bar--compact', 'opti-stats-bar--wrap');

		if (bar.style.display === 'none' || bar.offsetHeight === 0) {
			updateBodyOffset();
			return;
		}

		// First try the full-label single-row layout, then progressively compact.
		if (layoutOverflows(inner, stats)) {
			bar.classList.add('opti-stats-bar--compact');
		}

		// If compact chips still do not fit, wrap the stat viewport instead of
		// hiding the Forms/last Pro chip behind the fixed hide button.
		if (layoutOverflows(inner, stats)) {
			bar.classList.add('opti-stats-bar--wrap');
		}

		updateBodyOffset();
	}

	function scheduleResponsiveLayout() {
		if (layoutFrame) {
			window.cancelAnimationFrame(layoutFrame);
		}

		layoutFrame = window.requestAnimationFrame(function () {
			layoutFrame = null;
			applyResponsiveLayout();
		});
	}

	/* ------------------------------------------------------------------
	   Collapse / Expand
	   ------------------------------------------------------------------ */
	function isCollapsed() {
		try {
			return localStorage.getItem(STORAGE_KEY) === '1';
		} catch (e) {
			return false;
		}
	}

	function setCollapsed(val) {
		try {
			localStorage.setItem(STORAGE_KEY, val ? '1' : '0');
		} catch (e) {
			// localStorage not available — fail silently.
		}
	}

	function collapse() {
		bar.style.transform = 'translateY(-100%)';
		bar.style.opacity   = '0';
		document.body.classList.remove('opti-stats-bar-active');
		document.body.classList.add('opti-stats-bar-collapsed');
		setTimeout(function () {
			bar.style.display  = 'none';
			pill.style.display = 'flex';
			initIcons();
		}, 300);
		setCollapsed(true);
	}

	function expand() {
		pill.style.display = 'none';
		bar.style.display  = 'block';
		// Force reflow before animating.
		void bar.offsetHeight;
		bar.style.transform = 'translateY(0)';
		bar.style.opacity   = '1';
		setCollapsed(false);
		initIcons();
		scheduleResponsiveLayout();
	}

	// Apply initial state.
	if (isCollapsed()) {
		bar.style.display  = 'none';
		bar.style.transform = 'translateY(-100%)';
		bar.style.opacity   = '0';
		pill.style.display = 'flex';
		document.body.classList.add('opti-stats-bar-collapsed');
	} else {
		scheduleResponsiveLayout();
	}

	// Bind toggle button.
	var toggleBtn = bar.querySelector('.opti-stats-bar__toggle');
	if (toggleBtn) {
		toggleBtn.addEventListener('click', function (e) {
			e.preventDefault();
			collapse();
		});
	}

	pill.addEventListener('click', function (e) {
		e.preventDefault();
		expand();
	});

	/* ------------------------------------------------------------------
	   AJAX: Load stats asynchronously
	   ------------------------------------------------------------------ */
	function setLoading(on) {
		var values = bar.querySelectorAll('.opti-stats-bar__value');
		for (var i = 0; i < values.length; i++) {
			if (on) {
				values[i].setAttribute('data-loading', 'true');
				values[i].textContent = '';
			} else {
				values[i].removeAttribute('data-loading');
			}
		}
		scheduleResponsiveLayout();
	}

	/**
	 * Resolve a dotted key like "pro.recordings" against a data object.
	 */
	function resolveKey(obj, key) {
		var parts = key.split('.');
		var val = obj;
		for (var i = 0; i < parts.length; i++) {
			if (val === undefined || val === null) return '—';
			val = val[parts[i]];
		}
		return (val !== undefined && val !== null) ? val : '—';
	}

	function populateStats(data) {
		var values = bar.querySelectorAll('.opti-stats-bar__value');
		for (var i = 0; i < values.length; i++) {
			var key = values[i].getAttribute('data-key');
			if (key) {
				var val = resolveKey(data, key);
				values[i].textContent = (typeof val === 'number') ? val.toLocaleString() : val;
			}
		}
		updateHeatmapsLinks(data && data.page_id ? parseInt(data.page_id, 10) : 0, data && data.page_ids ? data.page_ids : []);
		updateRecordingsLinks(data && data.page_ids ? data.page_ids : [], data);
		scheduleResponsiveLayout();
	}

	function loadStats() {
		setLoading(true);

		var xhr = new XMLHttpRequest();
		xhr.open('POST', config.ajax_url, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) return;

			setLoading(false);

			if (xhr.status === 200) {
				try {
					var resp = JSON.parse(xhr.responseText);
					if (resp.success && resp.data) {
						populateStats(resp.data);
					}
				} catch (e) {
					// JSON parse failed — leave placeholders.
				}
			}
		};

		xhr.send(
			'action=opti_behavior_frontend_stats&nonce=' + encodeURIComponent(config.nonce) +
			'&period=' + encodeURIComponent(getStatsPeriod()) +
			'&page_url=' + encodeURIComponent(window.location.href)
		);
	}

	/* ------------------------------------------------------------------
	   Boot
	   ------------------------------------------------------------------ */
	if (periodSelect) {
		// Undo any select-enhancement hijack, then keep watching for late inits.
		neutralizeSelectEnhancers();
		watchSelectEnhancers();
		// Reflect the persisted/default selection, then refetch on change.
		periodSelect.value = getStatsPeriod();
		periodSelect.addEventListener('change', function () {
			setStatsPeriod(this.value);
			loadStats();
			scheduleResponsiveLayout();
		});
	}
	updateHeatmapsLinks();
	updateRecordingsLinks();
	initIcons();
	scheduleResponsiveLayout();
	window.addEventListener('resize', scheduleResponsiveLayout);
	window.addEventListener('orientationchange', scheduleResponsiveLayout);

	if (document.fonts && typeof document.fonts.ready === 'object') {
		document.fonts.ready.then(scheduleResponsiveLayout).catch(function () {});
	}

	loadStats();

})();
