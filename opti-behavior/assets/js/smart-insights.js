/**
 * Smart Insights admin UI.
 *
 * Renders dashboard cards and the Smart Insights center through existing
 * nonce-protected AJAX endpoints.
 *
 * @package opti-behavior
 */
(function() {
	'use strict';

	var config = window.optiBehaviorSmartInsights || {};
	var i18n = config.i18n || {};
	var ajaxUrl = config.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
	var nonce = config.nonce || (window.opti_behaviorData && window.opti_behaviorData.nonce) || '';
	var upgradeUrl = config.upgradeUrl || config.upgrade_url || 'https://optiuser.com/opti-behavior/ob-download-pro/';
	var hasProAccess = !!config.hasProAccess;
	var excludeSpamDefault = config.excludeSpamDefault === '1';
	var activeModal = null;
	var activeModalSection = null;
	var activeSegmentScope = null;
	// Incremented per rendered collapsed block so each toggle owns a unique id
	// for aria-controls without leaking ids across modal re-renders.
	var disclosureSeq = 0;
	// Client-side safety net; the authoritative cap and the Pro-upsell rule live
	// in the capabilities layer so JS never branches on the viewer's tier.
	var MAX_RECOMMENDED_ACTIONS = 3;
	var pendingDeepOpenInsightId = parseInt(config.deepOpenInsightId || 0, 10) || 0;
	// Segment dimensions the destination reports can actually be filtered by,
	// per destination. A shortcut is only offered when the destination really
	// accepts that filter, so a "mobile" link never lands on an unfiltered
	// report pretending to be scoped.
	var WHERE_SCOPED_REPORT_KEYS = ['device', 'source', 'campaign'];
	var WHERE_SCOPED_REPORT_FILTERS = {
		session_recordings: ['device', 'source', 'campaign'],
		analytics: ['device', 'source', 'campaign'],
		device_report: ['device', 'source', 'campaign'],
		user_journey: ['device', 'source'],
		heatmap: ['device']
	};

	function ready(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}
		callback();
	}

	function escapeHtml(value) {
		var div = document.createElement('div');
		div.textContent = value === null || value === undefined ? '' : String(value);
		return div.innerHTML;
	}

	// --- Help tooltips -------------------------------------------------------
	// The list cards and the detail modal are rendered here, not in PHP, so the
	// purple "?" helper used across the settings screens cannot come from
	// opti_behavior_tooltip_e(). The copy is localized into config.tooltips and
	// re-emitted below with byte-equivalent markup, so assets/css/tooltips.css
	// styles it and assets/js/tooltips.js binds it: that script runs a
	// MutationObserver on document.body and calls bindTooltip() on every
	// .ob-tooltip node injected after load, which covers every re-render here.

	var tooltipCopy = config.tooltips || {};
	var signalTooltips = tooltipCopy.signals || {};
	var sectionTooltips = tooltipCopy.sections || {};

	function renderTooltipMarkup(entry, options) {
		if (!entry || !entry.title || !entry.content) {
			return '';
		}

		var opts = options || {};
		var classes = ['ob-tooltip'];
		if (opts.position && opts.position !== 'top') {
			classes.push('ob-tooltip-' + opts.position);
		}
		if (opts.align && opts.align !== 'center') {
			classes.push('ob-tooltip-align-' + opts.align);
		}
		if (opts.extraClass) {
			classes.push(opts.extraClass);
		}

		return '<span class="' + escapeHtml(classes.join(' ')) + '" tabindex="0" role="button" aria-label="' + escapeHtml(entry.title) + '">' +
			'<span class="ob-tooltip-icon" aria-hidden="true">?</span>' +
			'<span class="ob-tooltip-content" role="tooltip">' +
				'<span class="ob-tooltip-title">' + escapeHtml(entry.title) + '</span>' +
				'<span class="ob-tooltip-text">' + escapeHtml(entry.content) + '</span>' +
				(entry.simple ? '<span class="ob-tooltip-simple">' + escapeHtml(entry.simple) + '</span>' : '') +
				(entry.example ? '<span class="ob-tooltip-example">' + escapeHtml(entry.example) + '</span>' : '') +
			'</span>' +
		'</span>';
	}

	// Signal tooltip for a card. Unknown signal ids (a newer Pro build writing a
	// signal this Free release has no copy for) fall back to the generic entry
	// rather than rendering a card with no explanation at all.
	function renderSignalTooltip(insight, options) {
		var signalId = getSignalId(insight);
		var entry = (signalId && signalTooltips[signalId]) || signalTooltips._default;
		return renderTooltipMarkup(entry, options);
	}

	function renderSectionTooltip(key, options) {
		return renderTooltipMarkup(sectionTooltips[key], options);
	}

	// A section heading plus its "?" helper, used for every detail-modal section.
	// `headingClass` lets a caller keep an existing layout class (the "Where"
	// sub-blocks all use .ob-smart-insights-where-subtitle) while still opting in
	// to the flex alignment the helper icon needs.
	function renderSectionHeading(text, tooltipKey, options) {
		var opts = options || {};
		var tag = opts.tag || 'h3';
		var classes = 'ob-smart-insights-section-heading' + (opts.headingClass ? ' ' + opts.headingClass : '');
		return '<' + tag + ' class="' + escapeHtml(classes) + '">' + escapeHtml(text) +
			renderSectionTooltip(tooltipKey, opts) + '</' + tag + '>';
	}

	// tooltips.js portals the open popup into document.body. Closing the modal
	// removes the trigger but not the portaled popup, so it would stay pinned on
	// screen forever; drop any popup that belongs to a trigger inside the node
	// being torn down.
	function releasePortaledTooltips(root) {
		if (!root || !root.querySelectorAll) {
			return;
		}

		root.querySelectorAll('.ob-tooltip').forEach(function(tooltip) {
			var content = tooltip._obTooltipContent;
			if (content && content.parentNode === document.body) {
				content.parentNode.removeChild(content);
			}
			tooltip.classList.remove('is-active', 'is-pinned');
		});
	}

	function postAjax(action, data) {
		var formData = new FormData();
		formData.append('action', action);
		formData.append('nonce', nonce);

		Object.keys(data || {}).forEach(function(key) {
			if (data[key] !== null && data[key] !== undefined) {
				formData.append(key, data[key]);
			}
		});

		return fetch(ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
			.then(function(response) {
				return response.json();
			})
			.then(function(result) {
				if (!result || !result.success) {
					var message = result && result.data && result.data.message ? result.data.message : (i18n.error || 'Unable to load Smart Insights.');
					throw new Error(message);
				}
				return result.data || {};
			});
	}

	function getContextData(section) {
		var context = section.getAttribute('data-ob-smart-insights-context') || 'dashboard';
		var data = {
			context: context,
			period: section.getAttribute('data-period') || 'last30days',
			startDate: section.getAttribute('data-start-date') || '',
			endDate: section.getAttribute('data-end-date') || '',
			excludeSpam: getExcludeSpamFlag(section),
			status: '',
			severity: '',
			category: '',
			entityType: '',
			confidence: '',
			search: '',
			sort: 'priority'
		};

		if (context === 'dashboard' && window.opti_behaviorData) {
			data.period = window.opti_behaviorData.period || data.period;
			data.startDate = window.opti_behaviorData.startDate || data.startDate;
			data.endDate = window.opti_behaviorData.endDate || data.endDate;
		}

		var periodEl = section.querySelector('.ob-smart-insights-period');
		var startEl = section.querySelector('.ob-smart-insights-start-date');
		var endEl = section.querySelector('.ob-smart-insights-end-date');
		var statusEl = section.querySelector('.ob-smart-insights-status');
		var severityEl = section.querySelector('.ob-smart-insights-severity');
		var categoryEl = section.querySelector('.ob-smart-insights-category');
		var entityTypeEl = section.querySelector('.ob-smart-insights-entity-type');
		var confidenceEl = section.querySelector('.ob-smart-insights-confidence');
		var searchEl = section.querySelector('.ob-smart-insights-search');
		var sortEl = section.querySelector('.ob-smart-insights-sort');

		if (periodEl) {
			data.period = periodEl.value || data.period;
		}
		if (startEl && data.period === 'custom') {
			data.startDate = startEl.value || '';
		}
		if (endEl && data.period === 'custom') {
			data.endDate = endEl.value || '';
		}
		if (statusEl) {
			data.status = statusEl.value || '';
		}
		if (severityEl) {
			data.severity = severityEl.value || '';
		}
		if (categoryEl) {
			data.category = categoryEl.value || '';
		}
		if (entityTypeEl) {
			data.entityType = entityTypeEl.value || '';
		}
		if (confidenceEl) {
			data.confidence = confidenceEl.value || '';
		}
		if (searchEl) {
			data.search = searchEl.value || '';
		}
		if (sortEl) {
			data.sort = sortEl.value || 'priority';
		}

		section.setAttribute('data-period', data.period);
		section.setAttribute('data-start-date', data.startDate);
		section.setAttribute('data-end-date', data.endDate);
		section.setAttribute('data-exclude-spam', data.excludeSpam);

		return data;
	}

	function getExcludeSpamFlag(section) {
		var params = new URLSearchParams(window.location.search);
		if (params.has('exclude_spam')) {
			return params.get('exclude_spam') === '1' ? '1' : '0';
		}

		if (section && section.getAttribute('data-exclude-spam') !== null) {
			return section.getAttribute('data-exclude-spam') === '1' ? '1' : '0';
		}

		if (window.opti_behaviorData && window.opti_behaviorData.excludeSpam !== undefined) {
			return window.opti_behaviorData.excludeSpam === '1' ? '1' : '0';
		}

		return excludeSpamDefault ? '1' : '0';
	}

	function updateExcludeSpamButton(section) {
		var button = section.querySelector('.ob-smart-insights-exclude-spam');
		if (!button) {
			return;
		}

		var isActive = getExcludeSpamFlag(section) === '1';
		button.classList.toggle('active', isActive);
		button.setAttribute('aria-pressed', isActive ? 'true' : 'false');

		var iconWrapper = button.querySelector('.filter-icon');
		if (iconWrapper) {
			iconWrapper.innerHTML = '<i data-lucide="' + (isActive ? 'shield-check' : 'shield-off') + '" aria-hidden="true"></i>';
		}
		var label = button.querySelector('.filter-label');
		if (label) {
			label.textContent = isActive ? (i18n.excludingSpam || 'Excluding Spam') : (i18n.includingSpam || 'Including Spam');
		}
		button.setAttribute('aria-label', isActive ? (i18n.excludingSpamTraffic || 'Excluding spam traffic') : (i18n.includingSpamTraffic || 'Including spam traffic'));
		button.setAttribute('title', isActive ? (i18n.excludingSpamTrafficTitle || 'Spam traffic is excluded from Smart Insights') : (i18n.includingSpamTrafficTitle || 'Spam traffic is included in Smart Insights'));

		refreshIcons();
	}

	function setExcludeSpamFlag(section, isActive) {
		var value = isActive ? '1' : '0';
		section.setAttribute('data-exclude-spam', value);
		if (window.opti_behaviorData) {
			window.opti_behaviorData.excludeSpam = value;
		}

		var params = new URLSearchParams(window.location.search);
		params.set('exclude_spam', value);
		var newUrl = window.location.pathname + '?' + params.toString();
		if (window.history && window.history.replaceState) {
			window.history.replaceState({}, '', newUrl);
		}
		updateExcludeSpamButton(section);
	}

	function toggleCustomDateInputs(section) {
		var periodEl = section.querySelector('.ob-smart-insights-period');
		if (!periodEl) {
			return;
		}

		var isCustom = periodEl.value === 'custom';
		var labels = section.querySelectorAll('.ob-smart-insights-custom-date');
		Array.prototype.forEach.call(labels, function(label) {
			label.hidden = !isCustom;
			label.style.display = isCustom ? '' : 'none';
		});

		var startEl = section.querySelector('.ob-smart-insights-start-date');
		var endEl = section.querySelector('.ob-smart-insights-end-date');
		if (startEl) {
			startEl.disabled = !isCustom;
			if (!isCustom) {
				startEl.value = '';
			}
		}
		if (endEl) {
			endEl.disabled = !isCustom;
			if (!isCustom) {
				endEl.value = '';
			}
		}
	}

	function syncFilterSelectOptions(section, data) {
		if (!data || !data.filters) {
			return;
		}

		var categoryEl = section.querySelector('.ob-smart-insights-category');
		var entityTypeEl = section.querySelector('.ob-smart-insights-entity-type');

		populateSelectOptions(categoryEl, data.filters.categories);
		populateSelectOptions(entityTypeEl, data.filters.entity_types);
	}

	function populateSelectOptions(selectEl, options) {
		if (!selectEl || !Array.isArray(options)) {
			return;
		}

		var selectedValue = selectEl.value || '';
		var hasBlank = false;
		selectEl.innerHTML = '';

		options.forEach(function(option) {
			if (!option || option.value === undefined || option.value === null) {
				return;
			}

			var value = String(option.value);
			var label = option.label !== undefined && option.label !== null ? String(option.label) : value;

			if (value === '') {
				hasBlank = true;
			}

			var optionEl = document.createElement('option');
			optionEl.value = value;
			optionEl.textContent = label;
			selectEl.appendChild(optionEl);
		});

		if (!hasBlank) {
			var allOption = document.createElement('option');
			allOption.value = '';
			allOption.textContent = 'All';
			selectEl.insertBefore(allOption, selectEl.firstChild);
		}

		var hasSelectedValue = Array.prototype.some.call(selectEl.options, function(optionEl) {
			return optionEl.value === selectedValue;
		});
		if (selectedValue && !hasSelectedValue) {
			var selectedOption = document.createElement('option');
			selectedOption.value = selectedValue;
			selectedOption.textContent = selectedValue;
			selectEl.appendChild(selectedOption);
		}

		selectEl.value = selectedValue;
	}

	function setAlert(section, message, type) {
		var alert = section.querySelector('.ob-smart-insights-alert');
		if (!alert) {
			return;
		}

		if (!message) {
			alert.hidden = true;
			alert.textContent = '';
			alert.className = 'ob-smart-insights-alert';
			return;
		}

		alert.hidden = false;
		alert.textContent = message;
		alert.className = 'ob-smart-insights-alert is-' + (type || 'info');
	}

	function setLoading(section, message) {
		var list = section.querySelector('.ob-smart-insights-list');
		if (!list) {
			return;
		}
		list.innerHTML = '<div class="ob-smart-insights-loading"><span class="ob-smart-insights-spinner" aria-hidden="true"></span><span>' + escapeHtml(message || i18n.loading || 'Loading Smart Insights...') + '</span></div>';
	}

	function hasCompleteCustomRange(contextData) {
		return contextData.period !== 'custom' || (!!contextData.startDate && !!contextData.endDate);
	}

	function syncNotificationsScope(section) {
		if (typeof window.optiBehaviorSmartInsightNotificationsRefresh !== 'function') {
			return;
		}

		var contextData = getContextData(section);
		if (contextData.context !== 'center' || !hasCompleteCustomRange(contextData)) {
			return;
		}

		window.optiBehaviorSmartInsightNotificationsRefresh({
			context: 'center',
			period: contextData.period,
			start_date: contextData.startDate,
			end_date: contextData.endDate,
			exclude_spam: contextData.excludeSpam
		});
	}

	function normalizeItems(data) {
		if (data && Array.isArray(data.items)) {
			return data.items;
		}
		if (data && Array.isArray(data.insights)) {
			return data.insights;
		}
		return [];
	}

	function getPriorityLabel(insight) {
		return localizeLevelLabel((insight.scores && insight.scores.priority_label) || 'Low');
	}

	function getPriorityScore(insight) {
		return insight.scores && insight.scores.priority_score !== undefined ? parseInt(insight.scores.priority_score, 10) || 0 : 0;
	}

	function getConfidenceLabel(insight) {
		return localizeLevelLabel((insight.scores && insight.scores.confidence_label) || 'Low');
	}

	function getSeverityClass(label) {
		var normalized = String(label || 'low').toLowerCase();
		if (normalized === 'critical' || normalized.indexOf('criti') !== -1) {
			return 'critical';
		}
		if (normalized === 'high' || normalized.indexOf('high') !== -1 || normalized.indexOf('élev') !== -1 || normalized.indexOf('eleve') !== -1) {
			return 'high';
		}
		if (normalized === 'medium' || normalized.indexOf('moy') !== -1 || normalized.indexOf('medio') !== -1 || normalized.indexOf('mittel') !== -1) {
			return 'medium';
		}
		return String(label || 'low').toLowerCase().replace(/[^a-z0-9_-]/g, '-');
	}

	function localizeLevelLabel(label) {
		var key = String(label || '').toLowerCase();
		if (key === 'critical') {
			return i18n.critical || 'Critical';
		}
		if (key === 'high') {
			return i18n.high || 'High';
		}
		if (key === 'medium') {
			return i18n.medium || 'Medium';
		}
		if (key === 'low') {
			return i18n.low || 'Low';
		}
		return label || (i18n.low || 'Low');
	}

	function getMetric(insight, key) {
		if (!insight || !insight.metrics) {
			return null;
		}

		if (insight.metrics[key] !== undefined && insight.metrics[key] !== null) {
			return insight.metrics[key];
		}

		if (insight.metrics.signal_metrics && insight.metrics.signal_metrics[key] !== undefined && insight.metrics.signal_metrics[key] !== null) {
			return insight.metrics.signal_metrics[key];
		}

		return null;
	}

	function formatNumber(value) {
		var number = parseFloat(value);
		return isNaN(number) ? '-' : number.toLocaleString();
	}

	function formatPercent(value) {
		var number = parseFloat(value);
		return isNaN(number) ? '-' : number.toFixed(1) + '%';
	}

	function formatSeconds(value) {
		var number = parseFloat(value);
		if (isNaN(number)) {
			return '-';
		}
		var minutes = Math.floor(number / 60);
		var seconds = Math.round(number % 60);
		return minutes + ':' + String(seconds).padStart(2, '0');
	}

	function refreshIcons() {
		if (window.lucide && window.lucide.createIcons) {
			window.lucide.createIcons();
		}
	}

	function getRecommendedAction(insight) {
		if (insight && insight.is_locked_preview && insight.locked_preview && insight.locked_preview.description) {
			return String(insight.locked_preview.description).trim();
		}

		var actions = Array.isArray(insight.recommended_actions) ? insight.recommended_actions : [];
		for (var i = 0; i < actions.length; i++) {
			var action = actions[i];
			var text = typeof action === 'string' ? action : (action.title || action.label || action.action || action.description || '');
			text = String(text || '').trim();
			if (text) {
				return text;
			}
		}
		return i18n.defaultNextAction || 'Review the evidence, open the most relevant report, and decide whether this should move to In progress.';
	}

	function getPageLabel(insight) {
		return insight.entity_label || insight.entity_id || (i18n.unknownEntity || 'Unknown entity');
	}

	function getSignalId(insight) {
		return String((insight && insight.signal_id) || '').toLowerCase();
	}

	function isHttpUrl(value) {
		return /^https?:\/\//i.test(String(value || '').trim());
	}

	function getAdminBaseUrl() {
		if (!ajaxUrl) {
			return 'admin.php';
		}

		return String(ajaxUrl).replace(/admin-ajax\.php(?:\?.*)?$/i, 'admin.php');
	}

	function buildAdminReportUrl(pageSlug, args) {
		var slug = String(pageSlug || '').trim();
		if (!slug) {
			return '';
		}

		var params = new URLSearchParams();
		params.set('page', slug);

		Object.keys(args || {}).forEach(function(key) {
			var value = args[key];
			if (value === undefined || value === null || value === '') {
				return;
			}
			params.set(key, String(value));
		});

		return getAdminBaseUrl() + '?' + params.toString();
	}

	function startsWithAdminUrl(url) {
		return typeof url === 'string' && /^admin\.php(?:\?|$)/i.test(url.trim());
	}

	function firstContextValue(values) {
		for (var i = 0; i < values.length; i++) {
			var value = values[i];
			if (value === undefined || value === null || value === '') {
				continue;
			}
			if (typeof value === 'number' && isFinite(value)) {
				return String(value);
			}
			if (typeof value === 'string' && value.trim() !== '') {
				return value.trim();
			}
		}
		return '';
	}

	function getRelatedReportContext(report, insight) {
		report = report && typeof report === 'object' ? report : {};
		insight = insight && typeof insight === 'object' ? insight : {};

		var metrics = insight.metrics && typeof insight.metrics === 'object' ? insight.metrics : {};
		var entityContext = metrics.entity_context && typeof metrics.entity_context === 'object' ? metrics.entity_context : {};
		var entityType = firstContextValue([entityContext.entity_type, insight.entity_type, metrics.entity_type]);
		var entityId = firstContextValue([entityContext.entity_id, insight.entity_id, metrics.entity_id, report.target]);
		var pageId = firstContextValue([report.page_id, entityContext.page_id, metrics.page_id, entityType === 'page' && /^\d+$/.test(entityId) ? entityId : '']);
		var pageUrl = firstContextValue([report.page_url, entityContext.view_url, metrics.page_url, isHttpUrl(entityId) ? entityId : '']);
		var formId = firstContextValue([report.form_id, metrics.form_id, entityType === 'form' ? entityId : '']);
		var funnelId = firstContextValue([report.funnel_id, metrics.funnel_id, entityType === 'funnel' && /^\d+$/.test(entityId) ? entityId : '']);
		// Funnel-cohort scope for the recordings list: the sessions that entered
		// the funnel and never reached this step. Supplied by the Pro adapter.
		var funnelStep = firstContextValue([report.funnel_step, metrics.funnel_step]);
		var funnelScope = firstContextValue([report.funnel_scope, metrics.funnel_scope]);
		var testId = firstContextValue([report.test_id, metrics.test_id, entityType === 'test' && /^\d+$/.test(entityId) ? entityId : '']);
		var ctaSelector = firstContextValue([report.cta_selector, metrics.cta_selector, metrics.selector, entityType === 'cta' ? entityId : '']);
		var errorType = firstContextValue([report.error_type, metrics.error_type, entityType === 'error' ? entityId : '']);
		var device = firstContextValue([report.device, metrics.device_key, metrics.device_type, entityType === 'device' ? entityId : '']);
		var source = firstContextValue([report.source, metrics.source_key, metrics.source, entityType === 'source' ? entityId : '']);
		var campaign = firstContextValue([report.campaign, metrics.campaign_key, metrics.campaign_name, entityType === 'campaign' ? entityId : '']);
		var segmentDimension = firstContextValue([report.segment_dimension, metrics.segment_dimension]);
		var segmentKey = firstContextValue([report.segment_key, metrics.segment_key]);
		if (entityType === 'segment' && entityId.indexOf(':') !== -1) {
			var parts = entityId.split(':');
			segmentDimension = segmentDimension || parts.shift();
			segmentKey = segmentKey || parts.join(':');
		}
		if (entityType === 'segment' && segmentKey) {
			var normalizedDimension = String(segmentDimension || '').toLowerCase();
			if (!device && /^(device|device_type)$/.test(normalizedDimension)) {
				device = segmentKey;
			}
			if (!source && /^(source|traffic_source|utm_source|referrer|referrer_url)$/.test(normalizedDimension)) {
				source = segmentKey;
			}
			if (!campaign && /^(campaign|utm_campaign)$/.test(normalizedDimension)) {
				campaign = segmentKey;
			}
		}
		var dateRange = firstContextValue([report.date_range, insight.date_range_key, insight.period]);
		var startDate = firstContextValue([report.start_date, insight.date_from, insight.start_date, insight.date_range && (insight.date_range.from || insight.date_range.start)]);
		var endDate = firstContextValue([report.end_date, report.cohort_end_at, insight.cohort_end_at, insight.last_seen_at, metrics.last_seen_at, insight.date_to, insight.end_date, insight.date_range && (insight.date_range.to || insight.date_range.end)]);
		var excludeSpam = normalizeExcludeSpam(firstContextValue([report.exclude_spam, insight.exclude_spam, metrics.exclude_spam, metrics.spam_scope, insight.detection && insight.detection.spam_scope]));
		var cohortStartAt = normalizeDateTime(firstContextValue([report.cohort_start_at, insight.cohort_start_at, metrics.cohort_start_at, startDate]), 'start');
		var cohortEndAt = normalizeDateTime(firstContextValue([report.cohort_end_at, insight.cohort_end_at, metrics.cohort_end_at, insight.last_seen_at, metrics.last_seen_at, endDate]), 'end');
		var insightId = firstContextValue([report.insight_id, insight.id]);
		if (pageId === '0') {
			pageId = '';
		}
		if (formId === '0') {
			formId = '';
		}
		if (funnelId === '0') {
			funnelId = '';
		}
		if (!funnelId || !/^\d+$/.test(String(funnelStep))) {
			funnelStep = '';
			funnelScope = '';
		} else if (['abandoned', 'reached', 'completed'].indexOf(String(funnelScope)) === -1) {
			funnelScope = 'abandoned';
		}
		if (testId === '0') {
			testId = '';
		}

		return {
			entityType: entityType,
			entityId: entityId,
			pageId: pageId,
			pageUrl: pageUrl,
			formId: formId,
			funnelId: funnelId,
			funnelStep: funnelStep,
			funnelScope: funnelScope,
			testId: testId,
			ctaSelector: ctaSelector,
			errorType: errorType,
			device: device,
			source: source,
			campaign: campaign,
			segmentDimension: segmentDimension,
			segmentKey: segmentKey,
			dateRange: dateRange,
			startDate: normalizeDateOnly(startDate),
			endDate: normalizeDateOnly(endDate),
			insightId: insightId,
			cohortStartAt: cohortStartAt,
			cohortEndAt: cohortEndAt,
			excludeSpam: excludeSpam
		};
	}


	function normalizeExcludeSpam(value) {
		if (value === null || value === undefined || value === '') {
			return '';
		}
		var text = String(value).toLowerCase();
		if (text === '0' || text === 'false' || text === 'exclude_spam_0') {
			return '0';
		}
		if (text === '1' || text === 'true' || text === 'exclude_spam_1') {
			return '1';
		}
		return '';
	}

	function normalizeDateOnly(value) {
		var text = String(value || '').trim();
		var match = text.match(/^(\d{4}-\d{2}-\d{2})/);
		return match ? match[1] : '';
	}

	function normalizeDateTime(value, bound) {
		var text = String(value || '').trim();
		var match = text.match(/^(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2}:\d{2})/);
		if (match) {
			return match[1] + ' ' + match[2];
		}
		match = text.match(/^(\d{4}-\d{2}-\d{2})$/);
		if (match) {
			return match[1] + (bound === 'end' ? ' 23:59:59' : ' 00:00:00');
		}
		return '';
	}

	function addPageContext(query, context) {
		if (context.pageId) {
			query.page_id = context.pageId;
		}
		if (context.pageUrl) {
			query.page_url = context.pageUrl;
		}
	}

	// Segment and entity keys are stored in the analytics-internal form
	// ("utm:adnetwork/cpc", "referrer:google.com", "campaign:spring|google|cpc"),
	// which is never a column value. The destination reports filter on the raw
	// columns, so the key is decoded here and the decoded parts travel under the
	// destination's own filter names. The internal key still travels as
	// `source`/`campaign` so whereUrlCarriesSegment() and the labels keep working.
	function decodeSourceKey(value) {
		var text = String(value || '').trim();
		var out = { utmSource: '', utmMedium: '', referrer: '', channel: '' };
		if (!text) {
			return out;
		}
		if (/^utm:/i.test(text)) {
			var rest = text.slice(4);
			var slash = rest.indexOf('/');
			if (slash !== -1) {
				out.utmSource = rest.slice(0, slash);
				out.utmMedium = rest.slice(slash + 1);
			} else {
				out.utmSource = rest;
			}
			return out;
		}
		if (/^referrer:/i.test(text)) {
			out.referrer = text.slice(9);
			return out;
		}
		if (isDirectSource(text)) {
			out.channel = 'direct';
			return out;
		}
		out.utmSource = text;
		return out;
	}

	function decodeCampaignKey(value) {
		var text = String(value || '').trim();
		var out = { campaign: '', utmSource: '', utmMedium: '' };
		if (!text) {
			return out;
		}
		if (/^campaign:/i.test(text)) {
			var parts = text.slice(9).split('|');
			out.campaign = parts[0] || '';
			out.utmSource = parts[1] || '';
			out.utmMedium = parts[2] || '';
			return out;
		}
		out.campaign = text;
		return out;
	}

	// Destination-native filter params. The analytics dashboard, the funnel
	// report and the recordings list all expose the SAME advanced-filter field
	// names, so one decoder feeds every one of them.
	function addSegmentFilterAliases(query, context) {
		if (context.device && !query.device_type) {
			query.device_type = context.device;
		}
		if (context.source) {
			var source = decodeSourceKey(context.source);
			if (source.channel === 'direct') {
				// "direct" is only the no-referrer channel when the segment is a
				// traffic-source one; on a utm_source/campaign dimension it is a
				// literal value and must not be turned into a channel filter.
				if (isDirectTrafficContext(context)) {
					query.traffic_channel = 'direct';
				}
			} else if (source.referrer) {
				query.referrer = source.referrer;
			} else if (source.utmSource) {
				query.utm_source = source.utmSource;
				if (source.utmMedium) {
					query.utm_medium = source.utmMedium;
				}
			}
		}
		if (context.campaign) {
			var campaign = decodeCampaignKey(context.campaign);
			if (campaign.campaign) {
				query.utm_campaign = campaign.campaign;
			}
			if (campaign.utmSource && !query.utm_source) {
				query.utm_source = campaign.utmSource;
			}
			if (campaign.utmMedium && !query.utm_medium) {
				query.utm_medium = campaign.utmMedium;
			}
		}
	}

	function addSegmentContext(query, context) {
		if (context.device) {
			query.device = context.device;
		}
		if (context.source) {
			query.source = context.source;
		}
		if (context.campaign) {
			query.campaign = context.campaign;
		}
		addSegmentFilterAliases(query, context);
	}

	function normalizeDestinationDateRange(value) {
		var key = String(value || '').toLowerCase().replace(/[^a-z0-9_]/g, '');
		var map = {
			last_7_days: 'last7days',
			last7days: 'last7days',
			'7days': 'last7days',
			last_30_days: 'last30days',
			last30days: 'last30days',
			'30days': 'last30days',
			last_90_days: 'last90days',
			last90days: 'last90days',
			'90days': 'last90days',
			this_month: 'thismonth',
			thismonth: 'thismonth',
			today: 'today',
			yesterday: 'yesterday',
			custom: 'custom'
		};
		return map[key] || key;
	}

	function addDateContext(query, context, destination) {
		var range = normalizeDestinationDateRange(context.dateRange || '');
		if (!range && context.startDate && context.endDate) {
			range = 'custom';
		}
		if (range) {
			query.date_range = range;
		}
		if (context.startDate && context.endDate) {
			query.start_date = context.startDate;
			query.end_date = context.endDate;
			if (destination === 'journey') {
				query.date_from = context.startDate;
				query.date_to = context.endDate;
			}
		}
		if (context.cohortStartAt && context.cohortEndAt) {
			query.si_context = '1';
			query.cohort_start_at = context.cohortStartAt;
			query.cohort_end_at = context.cohortEndAt;
			if (context.insightId) {
				query.insight_id = context.insightId;
			}
		}
	}

	function addSpamContext(query, context) {
		if (context.excludeSpam === '0' || context.excludeSpam === '1') {
			query.exclude_spam = context.excludeSpam;
		}
	}

	function isDirectSource(value) {
		var key = String(value || '').toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/^_+|_+$/g, '');
		return ['direct', 'direct_visit', 'directvisits', 'none', 'no_referrer', 'noreferrer'].indexOf(key) !== -1;
	}

	function isDirectTrafficContext(context) {
		var dimension = String(context.segmentDimension || '').toLowerCase();
		if (!isDirectSource(context.source)) {
			return false;
		}
		if (/^(utm_source|utm_campaign|campaign)$/.test(dimension)) {
			return false;
		}
		return context.entityType === 'source' || !dimension || /^(source|traffic_source|referrer|referrer_url)$/.test(dimension);
	}

	function addFormContext(query, context) {
		if (context.formId) {
			query.form_id = context.formId;
			query.selected_form = context.formId;
		}
	}

	function addRecordingSegmentAliases(query, context) {
		if (context.device) {
			query.device = context.device;
			query.device_type = context.device;
		}
		if (context.source) {
			if (isDirectTrafficContext(context)) {
				query.traffic_channel = 'direct';
			} else {
				query.source = context.source;
			}
		}
		if (context.campaign) {
			query.campaign = context.campaign;
		}
		// utm_source / utm_medium / utm_campaign / referrer carry the DECODED
		// column values; the raw keys above stay for the segment-scope contract.
		addSegmentFilterAliases(query, context);
	}

	function addJourneySegmentAliases(query, context) {
		if (context.device) {
			query.device = context.device;
			query.device_type = context.device;
		}
		if (context.source) {
			if (isDirectTrafficContext(context)) {
				query.referrer = 'Direct';
			} else if (!/^(utm_source|utm_campaign|campaign)$/i.test(context.segmentDimension || '')) {
				// The journey report matches the referrer host, so the internal
				// "referrer:host" / "utm:name" key has to be decoded first.
				var journeySource = decodeSourceKey(context.source);
				query.referrer = journeySource.referrer || journeySource.utmSource || context.source;
			}
		}
	}

	function hasAnyReportContext(context) {
		return !!(context.pageId || context.pageUrl || context.formId || context.funnelId || context.testId || context.ctaSelector || context.errorType || context.device || context.source || context.campaign);
	}

	function isProOnlyReportType(reportType) {
		return /session_recordings|recordings|user_journey|form_analytics|error_tracking|errors_friction/.test(String(reportType || '').toLowerCase());
	}

	function resolveRelatedReportLink(report, insight) {
		if (!report || typeof report !== 'object') {
			return { url: '', disabledReason: '' };
		}

		var existingUrl = report.url || report.href || '';
		var reportType = String(report.type || report.report_type || '').toLowerCase();
		if (report.available === false || report.enabled === false || report.is_available === false) {
			return { url: '', disabledReason: report.reason || report.disabled_reason || report.message || (i18n.relatedUnavailable || 'This report is not available for this insight yet.') };
		}
		if (isProOnlyReportType(reportType) && !hasProAccess) {
			return { url: '', disabledReason: i18n.proRelatedLocked || 'Requires Pro data.' };
		}
		if (!reportType && (isHttpUrl(existingUrl) || startsWithAdminUrl(existingUrl))) {
			return { url: String(existingUrl), disabledReason: '' };
		}

		var context = getRelatedReportContext(report, insight);
		var query = {};
		var needsPageContext = i18n.relatedUnavailable || 'This report requires page or entity context.';

		switch (reportType) {
			case 'heatmap':
			case 'heatmap_detail':
				addPageContext(query, context);
				if (context.ctaSelector) {
					query.cta_selector = context.ctaSelector;
				}
				if (!query.page_id && !query.page_url && !query.cta_selector) {
					return { url: '', disabledReason: i18n.relatedUnavailable || 'This heatmap link requires page or CTA context.' };
				}
				query.type = report.heatmap_type || 'click';
				addDateContext(query, context, 'heatmap');
				addSpamContext(query, context);
				if (query.page_id) {
					if (report.device || context.device) {
						query.device = report.device || context.device;
					}
					return { url: buildAdminReportUrl('opti-behavior-heatmap-detail', query), disabledReason: '' };
				}
				query.search = query.page_url || query.cta_selector || '';
				return { url: buildAdminReportUrl('opti-behavior-heatmaps', query), disabledReason: '' };
			case 'device_report':
			case 'analytics':
				addPageContext(query, context);
				addSegmentContext(query, context);
				// The analytics dashboard reads start_date/end_date/exclude_spam
				// from the URL; without them a segment or before/after evidence
				// link would land on the default period instead of the window the
				// insight was detected in.
				addDateContext(query, context, 'analytics');
				addSpamContext(query, context);
				if (hasAnyReportContext(context)) {
					query.si_context = '1';
					query.context = context.pageId || context.pageUrl ? 'page' : (context.device ? 'device' : (context.source ? 'source' : (context.campaign ? 'campaign' : 'insight')));
				}
				return { url: buildAdminReportUrl('opti-behavior-analytics', query), disabledReason: '' };
			case 'funnel':
			case 'funnel_analytics':
				if (context.funnelId) {
					query.funnel_id = context.funnelId;
					// The funnels screen switches from the index list to the single
					// funnel view on `funnel`; `funnel_id` alone lands on the list
					// (and on a site without index rows, on an empty page). Both
					// travel so the detail route opens and the list-side step
					// anchoring keeps working.
					query.funnel = context.funnelId;
				}
				addSegmentContext(query, context);
				addDateContext(query, context, 'funnel');
				addSpamContext(query, context);
				if (!query.funnel_id) {
					return { url: '', disabledReason: i18n.relatedUnavailable || 'This funnel link requires funnel context.' };
				}
				return { url: buildAdminReportUrl('opti-behavior-funnels', query), disabledReason: '' };
			case 'session_recordings':
				addPageContext(query, context);
				if (context.pageUrl) {
					query.filter_contains_page = context.pageUrl;
					query.contains_page = context.pageUrl;
				}
				addDateContext(query, context, 'recordings');
				// The recordings list honors exclude_spam; without it the
				// "affected sessions" evidence link would show a different
				// population than the insight measured.
				addSpamContext(query, context);
				addRecordingSegmentAliases(query, context);
				// The recordings list filters on the funnel cohort directly
				// (funnel_id + funnel_step + funnel_scope), so a funnel insight
				// does not need a page context to scope its replays.
				if (context.funnelId && context.funnelStep) {
					query.funnel_id = context.funnelId;
					query.funnel_step = context.funnelStep;
					query.funnel_scope = context.funnelScope || 'abandoned';
				}
				if (context.formId && !query.funnel_step && !query.page_id && !query.page_url && !query.contains_page && !query.device && !query.device_type && !query.source && !query.utm_source && !query.traffic_channel && !query.campaign && !query.utm_campaign) {
					return { url: '', disabledReason: i18n.recordingsFormFilterUnsupported || 'Recordings cannot be filtered by this form yet. Inspect the form analytics report for field-level evidence.' };
				}
				if (!query.funnel_step && !query.page_id && !query.page_url && !query.contains_page && !query.device && !query.device_type && !query.source && !query.utm_source && !query.traffic_channel && !query.campaign && !query.utm_campaign) {
					return { url: '', disabledReason: needsPageContext };
				}
				return { url: buildAdminReportUrl('opti-behavior-recordings', query), disabledReason: '' };
			case 'user_journey':
				addPageContext(query, context);
				if (context.pageUrl) {
					query.target_url = context.pageUrl;
					query.start_page = context.pageUrl;
				}
				query.tab = report.tab || 'flow';
				addDateContext(query, context, 'journey');
				// The journey report reads exclude_spam from the URL too.
				addSpamContext(query, context);
				addJourneySegmentAliases(query, context);
				if (context.formId && !query.page_id && !query.page_url && !query.target_url && !query.device && !query.device_type && !query.referrer) {
					return { url: '', disabledReason: i18n.journeyFormFilterUnsupported || 'Journeys cannot be filtered by this form yet. Use form analytics to inspect the abandonment path.' };
				}
				if (!query.page_id && !query.page_url && !query.target_url && !query.device && !query.device_type && !query.referrer) {
					return { url: '', disabledReason: needsPageContext };
				}
				return { url: buildAdminReportUrl('opti-behavior-user-journey', query), disabledReason: '' };
			case 'form_analytics':
				if (context.formId) {
					addFormContext(query, context);
					query.tab = 'field-analysis';
				} else {
					return { url: '', disabledReason: i18n.formAnalyticsNeedsFormContext || 'Form analytics needs a specific form or field context for this insight.' };
				}
				// Form analytics applies an incoming window to its date inputs and
				// honors the spam toggle, so both travel with the link.
				addDateContext(query, context, 'form_analytics');
				addSpamContext(query, context);
				if (!query.form_id && !query.page_id && !query.page_url) {
					return { url: '', disabledReason: i18n.relatedUnavailable || 'This form analytics link requires form or page context.' };
				}
				return { url: buildAdminReportUrl('opti-behavior-form-analytics', query), disabledReason: '' };
			case 'error_tracking':
			case 'errors_friction':
				query.tab = getSignalId(insight) === 'dead_or_rage_click_signal' || reportType === 'errors_friction' ? 'friction' : 'errors';
				addPageContext(query, context);
				if (context.pageUrl) {
					query.url = context.pageUrl;
				}
				if (context.errorType && context.errorType !== 'site') {
					query.error_type = context.errorType;
					if (reportType === 'errors_friction' && /^(dead_click|rage_click)$/i.test(context.errorType)) {
						query.friction_type = context.errorType;
					}
				}
				// The errors report applies an incoming analysis window. It has no
				// spam scope of its own (error rows are not session-joined), so no
				// exclude_spam flag is carried here rather than sending one the
				// destination would silently ignore.
				addDateContext(query, context, 'errors');
				if (context.formId && !query.page_id && !query.page_url && !query.url && !query.error_type) {
					return { url: '', disabledReason: i18n.errorsFormFilterUnsupported || 'Errors cannot be filtered by this form yet. Inspect form analytics for field errors tied to this form.' };
				}
				// A site-wide error/friction insight is measured on the whole site:
				// the unfiltered tab is its exact scope, so no filter is promised
				// and the report still opens on what the insight counted.
				var isSiteWideError = context.entityType === 'error' && /^(site|site_wide|sitewide)$/i.test(String(context.errorType || ''));
				if (!isSiteWideError && !query.page_id && !query.page_url && !query.url && !query.error_type) {
					return { url: '', disabledReason: i18n.relatedUnavailable || 'This errors link requires page or error context.' };
				}
				return { url: buildAdminReportUrl('opti-behavior-errors', query), disabledReason: '' };
			case 'ab_testing':
				query.view = 'builder';
				if (context.testId) {
					query.test_id = context.testId;
				} else if (context.pageUrl) {
					query.target_url = context.pageUrl;
				} else if (context.pageId) {
					query.target_post_id = context.pageId;
				}
				if (!query.test_id && !query.target_url && !query.target_post_id) {
					return { url: '', disabledReason: i18n.relatedUnavailable || 'This A/B link requires page or test context.' };
				}
				return { url: buildAdminReportUrl('opti-behavior-ab-testing', query), disabledReason: '' };
			case 'pro_locked':
				return { url: '', disabledReason: i18n.proRelatedLocked || 'Related recordings, journeys, and segment reports unlock in Pro.' };
			default:
				if (existingUrl) {
					return { url: String(existingUrl), disabledReason: '' };
				}
				return { url: '', disabledReason: i18n.relatedUnavailable || 'No deep link is available for this report.' };
		}
	}

	function getEntityContext(insight) {
		var metrics = insight && insight.metrics ? insight.metrics : {};
		var context = metrics && metrics.entity_context && typeof metrics.entity_context === 'object' ? metrics.entity_context : {};
		var entityId = (context.entity_id || insight.entity_id || '').toString();
		var label = context.label || insight.entity_label || entityId || (i18n.unknownEntity || 'Unknown entity');
		var links = [];

		var viewUrl = context.view_url || metrics.page_url || (isHttpUrl(entityId) ? entityId : '');
		if (isHttpUrl(viewUrl)) {
			links.push({ label: i18n.viewPage || 'View page', url: viewUrl, kind: 'page' });
		}

		var adminUrl = context.admin_url || '';
		if (isHttpUrl(adminUrl) && String(adminUrl).indexOf('postType=wp_navigation') === -1) {
			links.push({ label: i18n.adminEdit || 'Admin edit', url: adminUrl, kind: 'admin' });
		}

		var reportUrl = '';
		var reportLabel = '';
		var bestReport = getBestRelatedReport(insight);
		if (bestReport && bestReport.resolved && bestReport.resolved.url) {
			reportUrl = bestReport.resolved.url;
			reportLabel = bestReport.meta.label;
		}
		if (!reportUrl) {
			reportUrl = context.report_url || '';
			reportLabel = context.report_label || context.report_title || '';
			if (reportUrl && isUncontextualReportUrl(reportUrl)) {
				reportUrl = '';
				reportLabel = '';
			}
		}
		if (reportUrl) {
			reportLabel = getDestinationReportLabel(reportLabel, reportUrl);
			links.push({ label: reportLabel, url: reportUrl, kind: 'report' });
		}

		return {
			label: label,
			identifier: context.identifier || entityId || '',
			links: links
		};
	}

	function renderEntityReference(insight, options) {
		options = options || {};
		var context = getEntityContext(insight);
		var links = context.links;
		if (options.includeReportLinks === false) {
			links = links.filter(function(link) {
				return link.kind !== 'report';
			});
		}
		var identifierHtml = context.identifier ? '<small class="ob-smart-insights-entity-url" title="' + escapeHtml(context.identifier) + '">' + escapeHtml(context.identifier) + '</small>' : '';
		var linksHtml = links.length
			? '<span class="ob-smart-insights-entity-links">' + links.slice(0, 3).map(function(link) {
				return '<a class="ob-smart-insights-entity-link-pill" href="' + escapeHtml(link.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(link.label) + '</a>';
			}).join('') + '</span>'
			: '';
		var label = context.label || getPageLabel(insight);

		return '<span class="ob-smart-insights-entity-ref" title="' + escapeHtml(label) + '"><span class="ob-smart-insights-entity-copy"><strong>' + escapeHtml(label) + '</strong>' + identifierHtml + '</span>' + linksHtml + '</span>';
	}

	function isGenericReportLabel(label) {
		return /^(related\s+reports?|related\s+report|report|open\s+report)$/i.test(String(label || '').trim());
	}

	function inferReportLabelFromUrl(url) {
		var value = String(url || '').toLowerCase();
		if (value.indexOf('opti-behavior-heatmaps') !== -1 || value.indexOf('opti-behavior-heatmap-detail') !== -1) {
			return i18n.openHeatmap || 'Open heatmap';
		}
		if (value.indexOf('opti-behavior-recordings') !== -1 || value.indexOf('session') !== -1) {
			return i18n.viewRecordings || 'View recordings';
		}
		if (value.indexOf('opti-behavior-user-journeys') !== -1 || value.indexOf('journey') !== -1) {
			return i18n.reviewJourneys || 'Review journeys';
		}
		if (value.indexOf('opti-behavior-forms') !== -1 || value.indexOf('form') !== -1) {
			return i18n.inspectForm || 'Inspect form';
		}
		if (value.indexOf('opti-behavior-errors') !== -1 || value.indexOf('friction') !== -1 || value.indexOf('error') !== -1) {
			return i18n.reviewErrors || 'Review errors';
		}
		if (value.indexOf('opti-behavior-funnels') !== -1 || value.indexOf('funnel') !== -1) {
			return i18n.openFunnel || 'Open funnel';
		}
		if (value.indexOf('opti-behavior-ab-testing') !== -1 || value.indexOf('ab_testing') !== -1) {
			return i18n.createAbTest || 'Create A/B test';
		}
		if (value.indexOf('opti-behavior-analytics') !== -1 || value.indexOf('dashboard') !== -1) {
			return i18n.openAnalytics || 'Open analytics';
		}
		return i18n.openSupportingReport || 'Open supporting report';
	}

	function getDestinationReportLabel(label, url) {
		label = String(label || '').trim();
		return label && !isGenericReportLabel(label) ? label : inferReportLabelFromUrl(url);
	}

	function normalizeReportUrlForComparison(url) {
		url = String(url || '').trim();
		if (!url) {
			return '';
		}
		try {
			var parsed = new URL(url, window.location.origin);
			var params = new URLSearchParams(parsed.search);
			params.sort();
			parsed.search = params.toString();
			parsed.hash = '';
			return parsed.toString();
		} catch (e) {
			return url;
		}
	}

	function isUncontextualReportUrl(url) {
		var value = String(url || '');
		var parsed;
		try {
			parsed = new URL(value, window.location.origin);
		} catch (e) {
			return false;
		}
		var page = parsed.searchParams.get('page') || '';
		if (!page) {
			return false;
		}
		function hasAny(keys) {
			return keys.some(function(key) {
				var paramValue = parsed.searchParams.get(key);
				return paramValue !== null && String(paramValue).trim() !== '';
			});
		}
		if (page === 'opti-behavior-heatmaps') {
			return !hasAny(['page_id', 'page_url', 'search', 'target_url']);
		}
		if (page === 'opti-behavior-heatmap-detail') {
			return !hasAny(['page_id', 'page_url']);
		}
		if (page === 'opti-behavior-recordings') {
			return !hasAny(['page_id', 'page_url', 'filter_contains_page', 'contains_page', 'campaign', 'utm_campaign', 'source', 'utm_source', 'device', 'session_id', 'recording']);
		}
		if (page === 'opti-behavior-user-journey') {
			return !hasAny(['page_id', 'page_url', 'target_url', 'start_page', 'campaign', 'utm_campaign', 'source', 'utm_source', 'device']);
		}
		if (page === 'opti-behavior-form-analytics') {
			return !hasAny(['form_id', 'selected_form']);
		}
		if (page === 'opti-behavior-errors') {
			return !hasAny(['page_id', 'page_url', 'url', 'error_type', 'friction_type']);
		}
		if (page === 'opti-behavior-funnels') {
			return !hasAny(['funnel_id']);
		}
		if (page === 'opti-behavior-ab-testing') {
			return !hasAny(['test_id', 'target_url', 'target_post_id']);
		}
		if (page === 'opti-behavior-analytics') {
			return !hasAny(['si_context', 'context', 'page_id', 'page_url', 'device', 'source', 'utm_source', 'campaign', 'utm_campaign']);
		}
		return false;
	}

	function getStatusLabel(status) {
		var labels = {
			new: i18n.statusNew || 'New',
			viewed: i18n.statusViewed || 'Reviewed',
			in_progress: i18n.statusInProgress || 'In progress',
			resolved: i18n.statusResolved || 'Resolved',
			ignored: i18n.statusIgnored || 'Ignored',
			auto_resolved: i18n.statusAutoResolved || 'Auto-resolved'
		};
		return labels[status] || status || 'new';
	}

	function getCategoryLabel(category) {
		var labels = {
			'UX/CRO': i18n.categoryUxCro || 'UX / CRO',
			'Page Engagement': i18n.categoryEngagement || 'Engagement Issue',
			'Exit and Abandonment': i18n.categoryExit || 'Exit / Abandonment',
			'Device Friction': i18n.categoryDevice || 'Mobile Issue',
			'Trend and Anomaly': i18n.categoryTrend || 'Traffic Quality',
			'Funnel Drop-off': i18n.categoryFunnel || 'Funnel Drop-off',
			'Form Friction': i18n.categoryForm || 'Form Friction',
			'Technical Issue': i18n.categoryTechnical || 'Technical Issue',
			'Campaign Issue': i18n.categoryCampaign || 'Campaign Issue',
			'Revenue Opportunity': i18n.categoryRevenue || 'Revenue Opportunity',
			'Experiment Learning': i18n.categoryLearning || 'Experiment learning'
		};
		return labels[category] || category || (i18n.categoryGeneral || 'General');
	}

	function formatSignedPercent(value) {
		var number = parseFloat(value);
		if (isNaN(number)) {
			return i18n.baselineUnavailable || 'Baseline unavailable';
		}
		return (number > 0 ? '+' : '') + number.toFixed(1) + '%';
	}

	function prettifyKey(key) {
		return String(key || '').replace(/_/g, ' ').replace(/\b\w/g, function(letter) {
			return letter.toUpperCase();
		});
	}

	function filterClientItems(items, contextData) {
		var severity = String(contextData.severity || '').toLowerCase();
		var search = String(contextData.search || '').toLowerCase().trim();

		return items.filter(function(insight) {
			var priorityLabel = String(getPriorityLabel(insight)).toLowerCase();
			if (severity && priorityLabel !== severity) {
				return false;
			}

			if (search) {
				var haystack = [
					insight.signal_name,
					insight.entity_label,
					insight.entity_id,
					insight.category,
					insight.interpretation
				].join(' ').toLowerCase();

				if (haystack.indexOf(search) === -1) {
					return false;
				}
			}

			return true;
		});
	}

	function getSeverityWeight(insight) {
		var label = String(getPriorityLabel(insight) || '').toLowerCase();
		if (label === 'critical') {
			return 4;
		}
		if (label === 'high') {
			return 3;
		}
		if (label === 'medium') {
			return 2;
		}
		return 1;
	}

	function hasActionableReport(insight) {
		var reports = Array.isArray(insight && insight.related_reports) ? insight.related_reports : [];
		for (var i = 0; i < reports.length; i++) {
			var resolved = typeof reports[i] === 'object' ? resolveRelatedReportLink(reports[i], insight) : { url: '' };
			if (resolved.url) {
				return true;
			}
		}
		return false;
	}

	// --- Absolute impact ("largest leak first") -----------------------------
	// The engine stores how many visitors a problem actually costs. Ranking on
	// that number instead of on a relative percentage keeps a 10% drop over
	// 10,000 sessions above a 50% drop over 100 sessions.

	function getImpact(insight) {
		return insight && typeof insight.impact === 'object' && insight.impact ? insight.impact : null;
	}

	function getUsersLost(insight) {
		var impact = getImpact(insight);
		if (impact && impact.users_lost !== undefined && impact.users_lost !== null && impact.users_lost !== '') {
			return parseInt(impact.users_lost, 10) || 0;
		}
		if (insight && insight.scores && insight.scores.impact && insight.scores.impact.users_lost !== undefined) {
			return parseInt(insight.scores.impact.users_lost, 10) || 0;
		}
		return null;
	}

	// The money behind the leak. Only an `available` block is a number: a locked
	// Free hint or a site without a readable order value is not an amount and
	// must never rank or headline an insight.
	function getRevenue(insight) {
		var impact = getImpact(insight);
		return impact && typeof impact.revenue === 'object' && impact.revenue ? impact.revenue : null;
	}

	function getRevenueAmount(insight) {
		var revenue = getRevenue(insight);
		if (!revenue || !revenue.available) {
			return null;
		}
		var amount = parseFloat(revenue.amount);
		return isNaN(amount) ? null : amount;
	}

	// The observation gate (backend) marks insights measured on too little
	// traffic to be sized honestly. The UI must then say so instead of printing
	// a loss figure the sample cannot support.
	function isObservationOnly(insight) {
		return !!(insight && insight.detection && insight.detection.observation_only);
	}

	function getObservationSample(insight) {
		var detection = insight && typeof insight.detection === 'object' && insight.detection ? insight.detection : null;
		if (detection && detection.observation_sample !== undefined && detection.observation_sample !== null && detection.observation_sample !== '') {
			var stored = parseInt(detection.observation_sample, 10);
			if (!isNaN(stored) && stored > 0) {
				return stored;
			}
		}
		var metrics = insight && typeof insight.metrics === 'object' && insight.metrics ? insight.metrics : {};
		var keys = ['sessions', 'starts', 'entries', 'pageviews'];
		for (var i = 0; i < keys.length; i++) {
			var value = parseInt(metrics[keys[i]], 10);
			if (!isNaN(value) && value > 0) {
				return value;
			}
		}
		return null;
	}

	function getCorrelation(insight) {
		return insight && typeof insight.correlation === 'object' && insight.correlation ? insight.correlation : null;
	}

	function getStoryCauses(insight) {
		var correlation = getCorrelation(insight);
		return correlation && Array.isArray(correlation.causes) ? correlation.causes : [];
	}

	function isStoryInsight(insight) {
		var correlation = getCorrelation(insight);
		if (!correlation) {
			return false;
		}
		var signalCount = parseInt(correlation.signal_count, 10) || 0;
		return !!correlation.correlated && (signalCount > 1 || getStoryCauses(insight).length > 0);
	}

	// Used only by the explicit "Biggest impact" sort. There the viewer asked for
	// measured leaks, so insights that carry an impact block rank above the ones
	// that do not. Money decides first when both sides carry a measured amount;
	// otherwise the absolute visitor loss does. The default orderings never call
	// this, and the PHP-side comparator stays neutral on mixed pairs so legacy
	// rows keep their place in the dashboard's priority ranking.
	function compareByImpact(a, b) {
		var leftRevenue = getRevenueAmount(a);
		var rightRevenue = getRevenueAmount(b);
		if (leftRevenue !== null && rightRevenue !== null && leftRevenue !== rightRevenue) {
			return rightRevenue - leftRevenue;
		}
		var left = getUsersLost(a);
		var right = getUsersLost(b);
		if (left === null && right === null) {
			return 0;
		}
		if (left === null) {
			return 1;
		}
		if (right === null) {
			return -1;
		}
		return right - left;
	}

	function sortInsightsForCenter(items) {
		var mode = arguments.length > 1 && arguments[1] ? arguments[1] : 'priority';
		return items.slice().sort(function(a, b) {
			var dateDelta = getInsightTimestamp(b) - getInsightTimestamp(a);
			if (mode === 'impact') {
				var impactDelta = compareByImpact(a, b);
				if (impactDelta !== 0) {
					return impactDelta;
				}
			} else if (mode !== 'priority' && dateDelta !== 0) {
				return dateDelta;
			}
			var severityDelta = getSeverityWeight(b) - getSeverityWeight(a);
			if (severityDelta !== 0) {
				return severityDelta;
			}
			var scoreDelta = getPriorityScore(b) - getPriorityScore(a);
			if (scoreDelta !== 0) {
				return scoreDelta;
			}
			var actionDelta = (hasActionableReport(b) ? 1 : 0) - (hasActionableReport(a) ? 1 : 0);
			if (actionDelta !== 0) {
				return actionDelta;
			}
			if ((mode === 'priority' || mode === 'impact') && dateDelta !== 0) {
				return dateDelta;
			}
			return (parseInt(b.id, 10) || 0) - (parseInt(a.id, 10) || 0);
		});
	}

	// Story grouping for the center list. Runs AFTER filtering and sorting, so
	// the top-level order stays exactly what sortInsightsForCenter() produced.
	// A child whose parent is missing from the filtered set (resolved, ignored,
	// other status...) stays a top-level card: no insight is ever dropped.
	function groupCenterItems(items) {
		var present = {};
		var childrenByParent = {};
		var topLevel = [];

		items.forEach(function(insight) {
			present[String(insight.id)] = true;
		});

		items.forEach(function(insight) {
			var parentId = parseInt(insight.parent_insight_id, 10) || 0;
			if (parentId && present[String(parentId)]) {
				if (!childrenByParent[String(parentId)]) {
					childrenByParent[String(parentId)] = [];
				}
				childrenByParent[String(parentId)].push(insight);
				return;
			}
			topLevel.push(insight);
		});

		var groups = topLevel.map(function(insight) {
			return {
				insight: insight,
				children: childrenByParent[String(insight.id)] || []
			};
		});

		return groupSourceCards(groups);
	}

	// --- Source-level grouping ---------------------------------------------
	// Traffic-source signals fire once per source, so one bad ad network can
	// push five near-identical cards into the list. Cards of the same signal on
	// `source` entities collapse into one synthetic card that lists its members.
	// Every member keeps its own detail modal and its own data-insight-id, so
	// deep links (maybeOpenDeepLinkedInsight) still resolve to a member.
	var SOURCE_GROUP_MIN_MEMBERS = 2;

	function isSourceGroupCandidate(group) {
		if (!group || !group.insight || (group.children && group.children.length)) {
			return false;
		}
		var insight = group.insight;
		return String(insight.entity_type || '') === 'source' && !!getSignalId(insight) && !isStoryInsight(insight);
	}

	// Story grouping runs first and is never overridden: only ungrouped,
	// uncorrelated source cards are collapsed, and a signal with a single source
	// keeps the exact card it had before.
	function groupSourceCards(groups) {
		var buckets = {};
		groups.forEach(function(group) {
			if (!isSourceGroupCandidate(group)) {
				return;
			}
			var key = getSignalId(group.insight);
			if (!buckets[key]) {
				buckets[key] = [];
			}
			buckets[key].push(group.insight);
		});

		var rendered = {};
		var result = [];
		groups.forEach(function(group) {
			var key = isSourceGroupCandidate(group) ? getSignalId(group.insight) : '';
			if (!key || !buckets[key] || buckets[key].length < SOURCE_GROUP_MIN_MEMBERS) {
				result.push(group);
				return;
			}
			if (rendered[key]) {
				return;
			}
			rendered[key] = true;
			// The synthetic card keeps the position of the best-ranked member, so
			// the sort order the viewer asked for is preserved.
			result.push({ insight: group.insight, children: [], sourceMembers: buckets[key] });
		});

		return result;
	}

	// The synthetic card speaks with the score of its worst member: collapsing
	// must never bury a problem lower in the list than it was.
	function getSourceGroupLeader(members) {
		var leader = members[0];
		members.forEach(function(member) {
			if (getSeverityWeight(member) > getSeverityWeight(leader) ||
				(getSeverityWeight(member) === getSeverityWeight(leader) && getPriorityScore(member) > getPriorityScore(leader))) {
				leader = member;
			}
		});
		return leader;
	}

	function renderSourceGroupMember(insight) {
		var id = String(insight.id);
		var label = insight.entity_label || insight.entity_id || (i18n.smartInsight || 'Smart Insight');
		var parts = [label];

		var sessions = parseFloat(getMetric(insight, 'sessions'));
		if (!isNaN(sessions) && sessions > 0) {
			parts.push(fillToken(i18n.sourceGroupSessions || '%s sessions', '%s', formatNumber(sessions)));
		}

		var conversion = parseFloat(getMetric(insight, 'conversion_rate'));
		if (!isNaN(conversion)) {
			parts.push(fillToken(i18n.sourceGroupConversion || '%s conversion rate', '%s', formatPercent(conversion)));
		}

		return '<li>' +
			'<button type="button" class="button-link ob-smart-insights-source-open" data-open-insight="' + escapeHtml(id) + '" data-insight-id="' + escapeHtml(id) + '">' +
				escapeHtml(parts.join(' — ')) +
			'</button>' +
		'</li>';
	}

	function renderSourceGroupCard(members) {
		var leader = getSourceGroupLeader(members);
		var priorityLabel = getPriorityLabel(leader);
		var priorityClass = getSeverityClass(priorityLabel);
		var signalName = leader.signal_name || (i18n.smartInsight || 'Smart Insight');
		var title = fillToken(
			fillToken(i18n.sourceGroupTitle || '%1$s across %2$s traffic sources', '%1$s', signalName),
			'%2$s',
			formatNumber(members.length)
		);
		var lines = members.map(renderSourceGroupMember).join('');
		var detectedTime = renderDetectedTime(leader);

		return '<article class="ob-smart-insights-center-card is-' + escapeHtml(priorityClass) + ' is-source-group" data-source-group="' + escapeHtml(getSignalId(leader)) + '">' +
			'<span class="ob-smart-insights-severity-rail" aria-hidden="true"></span>' +
			'<div class="ob-smart-insights-center-main ob-smart-insights-primary-zone">' +
				'<div class="ob-smart-insights-title-row">' +
					'<h3 title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</h3>' +
					renderSignalTooltip(leader, { position: 'bottom' }) +
				'</div>' +
				renderCenterHeaderChips(leader, priorityLabel, priorityClass) +
				'<p class="ob-smart-insights-explanation">' + escapeHtml(i18n.sourceGroupExplanation || 'The same signal fired on several traffic sources. Open a source to see its own diagnosis.') + '</p>' +
				'<div class="ob-smart-insights-source-group">' +
					'<span class="ob-smart-insights-source-group-label">' + escapeHtml(fillToken(i18n.sourceGroupCount || '%s traffic sources', '%s', formatNumber(members.length))) + '</span>' +
					'<ul>' + lines + '</ul>' +
				'</div>' +
			'</div>' +
			'<div class="ob-smart-insights-metric-strip ob-smart-insights-evidence-zone" aria-label="' + escapeHtml(i18n.evidence || 'Evidence') + '">' + renderCenterEvidenceMicroCards(leader, 3) + '</div>' +
			'<div class="ob-smart-insights-action-zone">' + renderCenterActionPanel(leader, getRecommendedAction(leader)) + '</div>' +
			'<div class="ob-smart-insights-center-footer">' +
				'<div class="ob-smart-insights-card-footer-meta">' +
					(detectedTime ? '<span>' + detectedTime + '</span>' : '') +
					'<span>' + escapeHtml(i18n.confidence || 'Confidence') + ': <strong>' + escapeHtml(getConfidenceLabel(leader)) + '</strong></span>' +
					renderCenterPriorityMeter(leader, priorityLabel) +
				'</div>' +
			'</div>' +
		'</article>';
	}

	function getInsightTimestamp(insight) {
		var fields = ['updated_at', 'last_seen_at', 'created_at', 'date_to'];
		for (var i = 0; i < fields.length; i++) {
			var value = insight && insight[fields[i]] ? Date.parse(String(insight[fields[i]]).replace(' ', 'T')) : NaN;
			if (!isNaN(value)) {
				return value;
			}
		}
		return 0;
	}

	function getInsightTimelineLabel(insight) {
		if (!insight || typeof insight !== 'object') {
			return '';
		}
		return insight.updated_at || insight.last_seen_at || insight.created_at || insight.date_to || '';
	}

	function sprintfCount(pattern, count) {
		return String(pattern).replace('%s', String(count)).replace('%d', String(count));
	}

	// Site-local "now" anchor: insight datetimes are site-local strings, so the
	// relative diff must use the site clock (not the browser clock) to avoid
	// timezone drift. Both sides are parsed with the same local-time assumption,
	// so the timezone cancels out.
	var siteNowAnchor = config.siteNow ? Date.parse(String(config.siteNow).replace(' ', 'T')) : NaN;
	var siteNowLoadedAt = Date.now();

	function getSiteNow() {
		if (isNaN(siteNowAnchor)) {
			return Date.now();
		}
		return siteNowAnchor + (Date.now() - siteNowLoadedAt);
	}

	function formatRelativeTime(insight) {
		var timestamp = getInsightTimestamp(insight);
		if (!timestamp) {
			return '';
		}
		var diff = getSiteNow() - timestamp;
		if (diff < 0) {
			diff = 0;
		}
		var minute = 60000;
		var hour = 3600000;
		var day = 86400000;
		if (diff < minute) {
			return i18n.justNow || 'Just now';
		}
		if (diff < hour) {
			return sprintfCount(i18n.minutesAgo || '%s min ago', Math.floor(diff / minute));
		}
		if (diff < day) {
			return sprintfCount(i18n.hoursAgo || '%sh ago', Math.floor(diff / hour));
		}
		return sprintfCount(i18n.daysAgo || '%sd ago', Math.floor(diff / day));
	}

	function renderDetectedTime(insight, extraClass) {
		var relative = formatRelativeTime(insight);
		var raw = getInsightTimelineLabel(insight);
		if (!relative && !raw) {
			return '';
		}
		var visible = relative || raw;
		var title = raw ? ' title="' + escapeHtml((i18n.detected || 'Detected') + ': ' + raw) + '"' : '';
		return '<time class="ob-smart-insights-detected-time' + (extraClass ? ' ' + extraClass : '') + '"' + title + '>' + escapeHtml(visible) + '</time>';
	}


	function formatMetricByType(value, type) {
		if (type === 'percent') {
			return formatPercent(value);
		}
		if (type === 'seconds') {
			return formatSeconds(value);
		}
		return formatNumber(value);
	}

	function getEvidenceDefinitions(insight) {
		var signalId = getSignalId(insight);
		var primary = insight && insight.metrics && insight.metrics.primary_evidence && typeof insight.metrics.primary_evidence === 'object' ? insight.metrics.primary_evidence : null;
		var defaultDefinitions = [
			{ label: i18n.sessions || 'Sessions', key: 'page_sessions', fallback: 'sessions', type: 'number', hideZero: true, group: 'traffic' },
			{ label: i18n.bounceRate || 'Bounce rate', key: 'page_bounce_rate', fallback: 'bounce_rate', baseline: 'site_avg_bounce_rate', type: 'percent', group: 'engagement' },
			{ label: i18n.exitRate || 'Exit rate', key: 'page_exit_rate', fallback: 'exit_rate', baseline: 'site_avg_exit_rate', type: 'percent', group: 'engagement' },
			{ label: i18n.scrollDepth || 'Avg. scroll depth', key: 'page_avg_scroll_depth', fallback: 'avg_scroll_depth', baseline: 'site_avg_scroll_depth', type: 'percent', group: 'engagement' },
			{ label: i18n.timeOnPage || 'Avg. time on page', key: 'page_avg_time_on_page', fallback: 'avg_time_on_page', baseline: 'site_avg_time_on_page', type: 'seconds', group: 'engagement' },
			{ label: i18n.conversionRate || 'Conversion rate', key: 'page_conversion_rate', fallback: 'conversion_rate', baseline: 'site_avg_conversion_rate', type: 'percent', group: 'conversion' },
			{ label: i18n.ctaClickRate || 'CTA click rate', key: 'page_cta_click_rate', fallback: 'cta_click_rate', baseline: 'site_avg_cta_click_rate', type: 'percent', group: 'conversion' }
		];

		if (primary && Array.isArray(primary.metric_keys) && primary.metric_keys.length) {
			var primaryKey = primary.metric_keys[0];
			var type = /rate|percent|depth/i.test(primaryKey) ? 'percent' : 'number';
			defaultDefinitions.unshift({
				label: primary.label || prettifyKey(primaryKey),
				key: primaryKey,
				type: type,
				hideZero: !!primary.require_positive,
				group: /error|rage|dead|friction|abandon/i.test(primaryKey) ? 'friction' : 'traffic'
			});
		}


		if (['product_page_to_cart_dropoff', 'cart_to_checkout_dropoff', 'checkout_to_purchase_dropoff'].indexOf(signalId) !== -1) {
			var primaryDefinition = null;
			if (primary && Array.isArray(primary.metric_keys) && primary.metric_keys.length) {
				primaryDefinition = {
					label: primary.label || prettifyKey(primary.metric_keys[0]),
					key: primary.metric_keys[0],
					fallback: primary.metric_keys[1] || '',
					type: 'number',
					hideZero: !!primary.require_positive,
					group: 'traffic'
				};
			}

			var rateMetric = signalId === 'product_page_to_cart_dropoff'
				? { label: i18n.addToCartRate || 'Add-to-cart rate', key: 'add_to_cart_rate', type: 'percent', group: 'conversion' }
				: (signalId === 'cart_to_checkout_dropoff'
					? { label: i18n.cartToCheckoutRate || 'Cart-to-checkout rate', key: 'cart_to_checkout_rate', type: 'percent', group: 'conversion' }
					: { label: i18n.checkoutToPurchaseRate || 'Checkout-to-purchase rate', key: 'checkout_to_purchase_rate', type: 'percent', group: 'conversion' });

			var funnelDefinitions = [];
			if (primaryDefinition) {
				funnelDefinitions.push(primaryDefinition);
			} else if (signalId === 'product_page_to_cart_dropoff') {
				funnelDefinitions.push({ label: i18n.productViews || 'Product views', key: 'entries', fallback: 'product_views', type: 'number', hideZero: true, group: 'traffic' });
			} else if (signalId === 'cart_to_checkout_dropoff') {
				funnelDefinitions.push({ label: i18n.cartSessions || 'Cart sessions', key: 'entries', fallback: 'cart_sessions', type: 'number', hideZero: true, group: 'traffic' });
			} else {
				funnelDefinitions.push({ label: i18n.checkoutSessions || 'Checkout sessions', key: 'entries', fallback: 'checkout_sessions', type: 'number', hideZero: true, group: 'traffic' });
			}

			funnelDefinitions.push(rateMetric);
			funnelDefinitions.push({ label: i18n.funnelDropoffRate || 'Drop-off rate', key: 'dropoff_rate', type: 'percent', group: 'friction' });
			funnelDefinitions.push({ label: i18n.funnelCompletionRate || 'Completion rate', key: 'completion_rate', type: 'percent', group: 'conversion' });
			return funnelDefinitions;
		}

		if (signalId === 'low_scroll_depth_important_page') {
			return [
				{ label: i18n.scrollDepth || 'Avg. scroll depth', key: 'page_avg_scroll_depth', fallback: 'avg_scroll_depth', baseline: 'site_avg_scroll_depth', type: 'percent', group: 'engagement' },
				{ label: i18n.sessions || 'Sessions', key: 'page_sessions', fallback: 'sessions', type: 'number', hideZero: true, group: 'traffic' },
				{ label: i18n.bounceRate || 'Bounce rate', key: 'page_bounce_rate', fallback: 'bounce_rate', baseline: 'site_avg_bounce_rate', type: 'percent', group: 'engagement' },
				{ label: i18n.exitRate || 'Exit rate', key: 'page_exit_rate', fallback: 'exit_rate', baseline: 'site_avg_exit_rate', type: 'percent', group: 'engagement' }
			];
		}

		if (signalId === 'form_abandonment_detected' || signalId === 'form_error_friction') {
			return [
				{ label: i18n.formStarts || 'Form starts', key: 'starts', type: 'number', hideZero: true, group: 'traffic' },
				{ label: i18n.formSubmits || 'Form submits', key: 'submits', type: 'number', group: 'conversion' },
				{ label: i18n.abandonmentRate || 'Abandonment rate', key: 'abandonment_rate', type: 'percent', group: 'friction' },
				{ label: i18n.errorRate || 'Error rate', key: 'error_rate', type: 'percent', group: 'friction' }
			];
		}

		if (signalId === 'cta_low_performance') {
			return [
				{ label: i18n.exposedVisitors || 'Exposed visitors', key: 'click_sessions', fallback: 'sessions', type: 'number', hideZero: true, group: 'traffic' },
				{ label: i18n.ctaClicks || 'CTA clicks', key: 'cta_click_count', type: 'number', group: 'conversion' },
				{ label: i18n.ctaClickRate || 'CTA click rate', key: 'cta_click_rate', baseline: 'site_avg_cta_click_rate', type: 'percent', group: 'conversion' },
				{ label: i18n.ctaExposures || 'CTA exposures', key: 'click_count', type: 'number', hideZero: true, group: 'traffic' }
			];
		}

		if (signalId === 'dead_or_rage_click_signal') {
			return [
				{ label: i18n.rageClicks || 'Rage clicks', key: 'rage_click_events', type: 'number', hideZero: true, group: 'friction' },
				{ label: i18n.deadClicks || 'Dead clicks', key: 'dead_click_events', type: 'number', hideZero: true, group: 'friction' },
				{ label: i18n.frictionEvents || 'Friction events', key: 'friction_events', type: 'number', hideZero: true, group: 'friction' },
				{ label: i18n.deadClickRate || 'Dead click rate', key: 'dead_click_rate', type: 'percent', group: 'friction' }
			];
		}

		if (signalId === 'error_impact_on_conversion') {
			return [
				{ label: i18n.errorSessions || 'Error sessions', key: 'error_sessions', type: 'number', hideZero: true, group: 'traffic' },
				{ label: i18n.errorCount || 'Errors', key: 'error_count', type: 'number', group: 'friction' },
				{ label: i18n.errorSessionConversionRate || 'Error session conversion', key: 'error_session_conversion_rate', type: 'percent', group: 'conversion' },
				{ label: i18n.cleanSessionConversionRate || 'Clean session conversion', key: 'non_error_session_conversion_rate', type: 'percent', group: 'conversion' }
			];
		}

		if (signalId === 'funnel_dropoff_detected' || signalId === 'checkout_friction_detected') {
			return [
				{ label: i18n.funnelEntrants || 'Funnel entrants', key: 'entries', type: 'number', hideZero: true, group: 'traffic' },
				{ label: i18n.funnelCompletionRate || 'Completion rate', key: 'completion_rate', type: 'percent', group: 'conversion' },
				{ label: i18n.funnelDropoffRate || 'Drop-off rate', key: 'dropoff_rate', type: 'percent', group: 'friction' }
			];
		}

		return defaultDefinitions;
	}

	function collectEvidenceRows(insight, maxItems) {
		if (insight.is_locked_preview) {
			return [];
		}

		var definitions = getEvidenceDefinitions(insight);
		var rows = [];

		definitions.forEach(function(metric) {
			var value = getMetric(insight, metric.key);
			if ((value === null || value === undefined || value === '') && metric.fallback) {
				value = getMetric(insight, metric.fallback);
			}
			if (value === null || value === undefined || value === '' || isNaN(parseFloat(value))) {
				return;
			}

			if (metric.hideZero && parseFloat(value) <= 0) {
				return;
			}

			var baseline = metric.baseline ? getMetric(insight, metric.baseline) : null;
			rows.push({
				group: metric.group || 'engagement',
				label: metric.label,
				value: formatMetricByType(value, metric.type),
				baseline: baseline !== null && baseline !== undefined && baseline !== '' && !isNaN(parseFloat(baseline)) ? formatMetricByType(baseline, metric.type) : '',
				isZero: parseFloat(value) === 0,
				isUnavailable: false
			});
		});

		if (!rows.length) {
			rows.push({
				group: 'engagement',
				label: i18n.evidence || 'Evidence',
				value: i18n.evidenceUnavailable || 'Evidence unavailable',
				baseline: '',
				isZero: false,
				isUnavailable: true
			});
		}

		return rows.slice(0, maxItems || 4);
	}

	function renderEvidenceRows(rows) {
		return rows.map(function(metric, index) {
			var baselineHtml = metric.baseline ? '<small>' + escapeHtml((i18n.vsBaseline || 'vs') + ' ' + metric.baseline) + '</small>' : '';
			var primaryClass = index === 0 && !metric.isUnavailable ? ' is-primary' : '';
			var mutedClass = metric.isZero || metric.isUnavailable ? ' is-muted' : '';
			return '<div class="ob-smart-insights-evidence-item' + primaryClass + mutedClass + '"><span>' + escapeHtml(metric.label) + '</span><strong>' + escapeHtml(metric.value) + '</strong>' + baselineHtml + '</div>';
		}).join('');
	}

	function renderEvidence(insight, maxItems) {
		if (insight.is_locked_preview) {
			return '<div class="ob-smart-insights-locked-proof"><i data-lucide="lock"></i><span>' + escapeHtml(i18n.proEvidenceLocked || 'Detailed evidence is available in Pro.') + '</span></div>';
		}

		return renderEvidenceRows(collectEvidenceRows(insight, maxItems));
	}

	function renderInlineEvidence(insight, maxItems) {
		if (insight.is_locked_preview) {
			return '<div class="ob-smart-insights-inline-lock"><i data-lucide="lock"></i><span>' + escapeHtml(i18n.proEvidenceLocked || 'Detailed evidence is available in Pro.') + '</span></div>';
		}

		return collectEvidenceRows(insight, maxItems).map(function(metric, index) {
			if (metric.isUnavailable) {
				return '<span class="ob-smart-insights-inline-evidence-note is-muted">' + escapeHtml(metric.value) + '</span>';
			}

			var baselineHtml = metric.baseline ? '<small>' + escapeHtml((i18n.vsBaseline || 'vs') + ' ' + metric.baseline) + '</small>' : '';
			var primaryClass = index === 0 ? ' is-primary' : '';
			var mutedClass = metric.isZero ? ' is-muted' : '';

			return '<span class="ob-smart-insights-inline-stat' + primaryClass + mutedClass + '">' +
				'<span>' + escapeHtml(metric.label) + '</span>' +
				'<strong>' + escapeHtml(metric.value) + '</strong>' +
				baselineHtml +
			'</span>';
		}).join('');
	}

	function getMicroCardEvidenceLabel(label) {
		var text = String(label || '').trim();
		var normalized = text.toLowerCase();
		var compactLabels = {
			'avg. scroll depth': i18n.compactScrollDepth || 'Scroll depth',
			'average scroll depth': i18n.compactScrollDepth || 'Scroll depth',
			'bounce rate': i18n.bounceRate || 'Bounce rate',
			'exit rate': i18n.exitRate || 'Exit rate',
			'sessions': i18n.sessions || 'Sessions',
			'pageviews': i18n.pageviews || 'Pageviews'
		};

		return compactLabels[normalized] || text;
	}

	function renderCenterEvidenceMicroCards(insight, maxItems) {
		if (insight.is_locked_preview) {
			var lockedText = i18n.proEvidenceLocked || 'Detailed evidence is available in Pro.';
			return '<span class="ob-smart-insights-evidence-microcard is-muted is-locked" aria-label="' + escapeHtml(lockedText) + '" title="' + escapeHtml(lockedText) + '">' +
				'<span class="ob-smart-insights-evidence-microcard-label">' + escapeHtml(i18n.evidence || 'Evidence') + '</span>' +
				'<span class="ob-smart-insights-evidence-microcard-value-row"><i data-lucide="lock" aria-hidden="true"></i><strong>' + escapeHtml(lockedText) + '</strong></span>' +
			'</span>';
		}

		return collectEvidenceRows(insight, maxItems || 3).map(function(metric, index) {
			var baselineText = metric.baseline ? (i18n.vsBaseline || 'vs') + ' ' + metric.baseline : '';
			var baselineHtml = baselineText ? '<small>' + escapeHtml(baselineText) + '</small>' : '';
			var primaryClass = index === 0 && !metric.isUnavailable ? ' is-primary' : '';
			var mutedClass = metric.isZero || metric.isUnavailable ? ' is-muted' : '';
			var unavailableClass = metric.isUnavailable ? ' is-unavailable' : '';
			var ariaLabel = metric.isUnavailable ? metric.value : metric.label + ': ' + metric.value + (baselineText ? ', ' + baselineText : '');
			var displayLabel = getMicroCardEvidenceLabel(metric.label);

			return '<span class="ob-smart-insights-evidence-microcard' + primaryClass + mutedClass + unavailableClass + '" aria-label="' + escapeHtml(ariaLabel) + '" title="' + escapeHtml(ariaLabel) + '">' +
				'<span class="ob-smart-insights-evidence-microcard-label">' + escapeHtml(displayLabel) + '</span>' +
				'<span class="ob-smart-insights-evidence-microcard-value-row">' +
					'<strong>' + escapeHtml(metric.value) + '</strong>' +
					baselineHtml +
				'</span>' +
			'</span>';
		}).join('');
	}

	function renderDetailEvidence(insight, maxItems) {
		if (insight.is_locked_preview) {
			return renderEvidence(insight, maxItems);
		}

		var rows = collectEvidenceRows(insight, maxItems || 7);
		var primary = rows[0];
		var primaryBaseline = primary && primary.baseline ? '<small>' + escapeHtml((i18n.vsBaseline || 'vs') + ' ' + primary.baseline) + '</small>' : '';
		var primaryHtml = primary ? '<div class="ob-smart-insights-evidence-item is-primary' + (primary.isUnavailable ? ' is-muted' : '') + '"><span>' + escapeHtml(primary.label) + '</span><strong>' + escapeHtml(primary.value) + '</strong>' + primaryBaseline + '</div>' : '';
		var secondaryRows = rows.slice(1);
		var rowsHtml = secondaryRows.map(function(metric) {
			var baselineHtml = metric.baseline ? '<small>' + escapeHtml((i18n.vsBaseline || 'vs') + ' ' + metric.baseline) + '</small>' : '';
			var mutedClass = metric.isZero || metric.isUnavailable ? ' is-muted' : '';
			return '<div class="ob-smart-insights-evidence-item' + mutedClass + '"><span>' + escapeHtml(metric.label) + '</span><strong>' + escapeHtml(metric.value) + '</strong>' + baselineHtml + '</div>';
		}).join('');

		return primaryHtml + (rowsHtml ? '<div class="ob-smart-insights-evidence-table is-flat">' + rowsHtml + '</div>' : '');
	}

	function getReportMeta(report) {
		var type = String(report && typeof report === 'object' ? report.type || '' : '').toLowerCase();
		var reportUrl = report && typeof report === 'object' ? (report.url || report.href || '') : '';
		var fallback = typeof report === 'string' ? report : (report.label || report.title || type || '');
		fallback = getDestinationReportLabel(fallback, reportUrl);
		var metas = {
			heatmap: { label: i18n.openHeatmap || 'Open heatmap', icon: 'mouse-pointer-click', description: i18n.reportDescHeatmap || 'Review page-level click, movement, and scroll evidence.' },
			heatmap_detail: { label: i18n.openHeatmap || 'Open heatmap', icon: 'mouse-pointer-click', description: i18n.reportDescHeatmap || 'Review page-level click, movement, and scroll evidence.' },
			session_recordings: { label: i18n.viewRecordings || 'View recordings', icon: 'video', description: i18n.reportDescRecordings || 'Watch sessions that match this behavior pattern.' },
			user_journey: { label: i18n.reviewJourneys || 'Review journeys', icon: 'route', description: i18n.reportDescJourney || 'Trace the path visitors took before and after this signal.' },
			form_analytics: { label: i18n.inspectForm || 'Inspect form', icon: 'list-checks', description: i18n.reportDescForm || 'Find fields, errors, and abandonments driving friction.' },
			error_tracking: { label: i18n.reviewErrors || 'Review errors', icon: 'bug', description: i18n.reportDescErrors || 'Check technical errors associated with this opportunity.' },
			errors_friction: { label: i18n.checkFriction || 'Check friction', icon: 'alert-triangle', description: i18n.reportDescFriction || 'Inspect rage clicks, dead clicks, and UI friction.' },
			funnel: { label: i18n.openFunnel || 'Open funnel', icon: 'split', description: i18n.reportDescFunnel || 'See where visitors drop out of the conversion path.' },
			funnel_analytics: { label: i18n.openFunnel || 'Open funnel', icon: 'split', description: i18n.reportDescFunnel || 'See where visitors drop out of the conversion path.' },
			ab_testing: { label: i18n.createAbTest || 'Create A/B test', icon: 'flask-conical', description: i18n.reportDescAbTesting || 'Turn this finding into an experiment.' },
			analytics: { label: i18n.openAnalytics || 'Open analytics', icon: 'bar-chart-3', description: i18n.reportDescAnalytics || 'Review supporting traffic and engagement context.' },
			device_report: { label: i18n.openAnalytics || 'Open analytics', icon: 'monitor-smartphone', description: i18n.reportDescDevice || 'Compare behavior by device and segment.' },
			pro_locked: { label: i18n.proLocked || 'Pro preview', icon: 'lock', description: i18n.proRelatedLocked || 'Related recordings, journeys, and segment reports unlock in Pro.' }
		};
		return metas[type] || { label: fallback, icon: 'file-search', description: i18n.reportDescDefault || 'Open the most relevant supporting report.' };
	}

	function getContextualReportMeta(report, insight) {
		var meta = getReportMeta(report);
		var type = String(report && typeof report === 'object' ? report.type || '' : '').toLowerCase();
		var context = getRelatedReportContext(report, insight || {});
		var contextual = {
			label: meta.label,
			icon: meta.icon,
			description: meta.description
		};

		if (context.formId) {
			if (type === 'form_analytics') {
				contextual.label = i18n.inspectFieldDropoff || 'Inspect field drop-off';
				contextual.description = i18n.inspectFieldDropoffDescription || 'Open this form with field-level abandonment and error context.';
			} else if (type === 'session_recordings') {
				contextual.label = i18n.viewRecordingsForForm || 'View recordings for this form';
				contextual.description = i18n.recordingsFormContextDescription || 'Recordings need page or visitor context before this form can be isolated.';
			} else if (type === 'user_journey') {
				contextual.label = i18n.reviewJourneysForForm || 'Review journeys for this form';
				contextual.description = i18n.journeyFormContextDescription || 'Journey filters need page or visitor context before this form can be isolated.';
			} else if (type === 'error_tracking' || type === 'errors_friction') {
				contextual.label = i18n.reviewErrorsForForm || 'Review errors for this form';
				contextual.description = i18n.errorsFormContextDescription || 'Error reports need page or error-type context before this form can be isolated.';
			}
		} else if (context.pageId || context.pageUrl) {
			if (type === 'heatmap' || type === 'heatmap_detail') {
				contextual.label = i18n.openHeatmapForPage || 'Open heatmap for this page';
				if (!context.pageId && context.pageUrl) {
					contextual.label = i18n.findPageInHeatmaps || 'Find page in heatmaps';
					contextual.description = i18n.findPageInHeatmapsDescription || 'Open the heatmap list filtered to this page URL.';
				}
			} else if (type === 'session_recordings') {
				contextual.label = i18n.viewRecordingsForPage || 'View recordings filtered to this page';
			} else if (type === 'user_journey') {
				contextual.label = i18n.reviewJourneysForPage || 'Review journeys from this page';
			} else if (type === 'error_tracking' || type === 'errors_friction') {
				contextual.label = i18n.reviewErrorsForPage || 'Review errors for this page';
			} else if (type === 'analytics' || type === 'device_report') {
				contextual.label = i18n.openAnalyticsForPage || 'Open analytics for this page';
			}
		} else if (context.device || context.source || context.campaign) {
			if (type === 'session_recordings') {
				contextual.label = i18n.viewRecordingsForSegment || 'View recordings for this segment';
			} else if (type === 'user_journey') {
				contextual.label = i18n.reviewJourneysForSegment || 'Review journeys for this segment';
			} else if (type === 'analytics' || type === 'device_report') {
				contextual.label = i18n.openAnalyticsForSegment || 'Open analytics for this segment';
			}
		} else if (context.funnelId && (type === 'funnel' || type === 'funnel_analytics')) {
			contextual.label = i18n.openFunnelContext || 'Open this funnel report';
		}

		return contextual;
	}

	function normalizeReportType(report) {
		var type = String(report && typeof report === 'object' ? report.type || report.report_type || '' : '').toLowerCase();
		var aliases = {
			recordings: 'session_recordings',
			recording: 'session_recordings',
			journeys: 'user_journey',
			user_journeys: 'user_journey',
			forms: 'form_analytics',
			form: 'form_analytics',
			errors: 'error_tracking',
			friction: 'errors_friction',
			funnel_analytics: 'funnel',
			heatmap_detail: 'heatmap',
			ab_testing_pro: 'ab_testing'
		};

		return aliases[type] || type;
	}

	function getPreferredReportTypes(insight) {
		var signalId = getSignalId(insight);
		var entityType = String((insight && insight.entity_type) || '').toLowerCase();
		var signalPreferences = {
			high_traffic_low_engagement: ['heatmap', 'analytics', 'session_recordings', 'user_journey', 'ab_testing'],
			low_scroll_depth_important_page: ['heatmap', 'analytics', 'session_recordings', 'user_journey'],
			high_exit_rate_page: ['user_journey', 'session_recordings', 'analytics', 'heatmap'],
			basic_bounce_alert: ['session_recordings', 'user_journey', 'analytics', 'heatmap'],
			traffic_spike_observation: ['analytics', 'user_journey', 'session_recordings'],
			basic_mobile_bounce_warning: ['session_recordings', 'heatmap', 'user_journey', 'analytics'],
			mobile_friction_detected: ['session_recordings', 'heatmap', 'user_journey', 'analytics'],
			low_quality_traffic_source: ['user_journey', 'session_recordings', 'analytics'],
			campaign_page_intent_mismatch: ['user_journey', 'session_recordings', 'analytics'],
			traffic_spike_without_conversion: ['analytics', 'user_journey', 'session_recordings'],
			funnel_dropoff_detected: ['funnel', 'user_journey', 'session_recordings', 'error_tracking'],
			checkout_friction_detected: ['funnel', 'error_tracking', 'form_analytics', 'session_recordings', 'user_journey'],
			form_abandonment_detected: ['form_analytics', 'session_recordings', 'user_journey', 'error_tracking'],
			form_error_friction: ['form_analytics', 'error_tracking', 'session_recordings', 'user_journey'],
			field_level_friction: ['form_analytics', 'error_tracking', 'session_recordings'],
			cta_low_performance: ['heatmap', 'session_recordings', 'ab_testing', 'analytics'],
			dead_or_rage_click_signal: ['errors_friction', 'error_tracking', 'session_recordings', 'heatmap'],
			error_impact_on_conversion: ['error_tracking', 'session_recordings', 'user_journey'],
			visitor_confusion_pattern: ['session_recordings', 'user_journey', 'heatmap', 'ab_testing'],
			quick_exit_pattern: ['session_recordings', 'user_journey', 'analytics', 'heatmap'],
			ab_test_opportunity: ['ab_testing', 'session_recordings', 'heatmap', 'analytics'],
			conversion_drop_alert: ['error_tracking', 'form_analytics', 'user_journey', 'session_recordings', 'analytics'],
			engagement_decay: ['analytics', 'heatmap', 'session_recordings', 'user_journey'],
			session_recording_opportunity: ['session_recordings', 'user_journey', 'heatmap'],
			returning_visitor_opportunity: ['user_journey', 'session_recordings', 'analytics'],
			segment_anomaly_detection: ['user_journey', 'session_recordings', 'analytics'],
			product_page_engagement_issue: ['heatmap', 'session_recordings', 'user_journey', 'ab_testing'],
			poor_conversion_rate: ['ab_testing', 'user_journey', 'session_recordings', 'analytics'],
			mobile_cta_click_rate_lower_than_desktop: ['heatmap', 'session_recordings', 'ab_testing'],
			product_page_to_cart_dropoff: ['funnel', 'heatmap', 'session_recordings', 'user_journey'],
			cart_to_checkout_dropoff: ['funnel', 'session_recordings', 'user_journey'],
			checkout_to_purchase_dropoff: ['funnel', 'error_tracking', 'form_analytics', 'session_recordings']
		};
		var entityPreferences = {
			form: ['form_analytics', 'error_tracking', 'session_recordings', 'user_journey'],
			funnel: ['funnel', 'user_journey', 'session_recordings', 'error_tracking'],
			error: ['errors_friction', 'error_tracking', 'session_recordings', 'heatmap'],
			cta: ['heatmap', 'session_recordings', 'ab_testing', 'analytics'],
			device: ['session_recordings', 'heatmap', 'user_journey', 'analytics'],
			source: ['user_journey', 'session_recordings', 'analytics'],
			campaign: ['user_journey', 'session_recordings', 'analytics'],
			segment: ['user_journey', 'session_recordings', 'analytics'],
			page: ['heatmap', 'analytics', 'session_recordings', 'user_journey', 'ab_testing']
		};
		var preferred = (signalPreferences[signalId] || entityPreferences[entityType] || []).slice();
		var fallbacks = ['heatmap', 'analytics', 'session_recordings', 'user_journey', 'funnel', 'form_analytics', 'error_tracking', 'errors_friction', 'ab_testing'];

		fallbacks.forEach(function(type) {
			if (preferred.indexOf(type) === -1) {
				preferred.push(type);
			}
		});

		return preferred;
	}

	function scoreRelatedReportCandidate(item, index, insight) {
		var type = normalizeReportType(item.report);
		var preferred = getPreferredReportTypes(insight);
		var rank = preferred.indexOf(type);
		var score = rank === -1 ? 0 : (1000 - (rank * 50));

		if (item.resolved && item.resolved.url) {
			score += 10000;
		}
		if (type && (item.resolved && item.resolved.url) && !isUncontextualReportUrl(item.resolved.url)) {
			score += 100;
		}
		if (isProOnlyReportType(type) && hasProAccess) {
			score += 25;
		}
		if (type === 'heatmap' && preferred[0] !== 'heatmap') {
			score -= 75;
		}

		return score - index;
	}

	function getBestRelatedReport(insight) {
		var reports = Array.isArray(insight.related_reports) ? insight.related_reports : [];
		var fallback = null;
		var best = null;
		for (var i = 0; i < reports.length; i++) {
			var report = reports[i];
			var resolved = typeof report === 'object' ? resolveRelatedReportLink(report, insight) : { url: '', disabledReason: '' };
			var item = { report: report, resolved: resolved, meta: getContextualReportMeta(report, insight) };
			if (resolved.url) {
				item.score = scoreRelatedReportCandidate(item, i, insight);
				if (!best || item.score > best.score) {
					best = item;
				}
			}
			if (!fallback) {
				fallback = item;
			}
		}
		return best || fallback;
	}

	function renderRelatedReportPreview(insight) {
		var best = getBestRelatedReport(insight);
		if (!best) {
			if (insight.is_locked_preview) {
				return '<div class="ob-smart-insights-report-callout is-locked"><i data-lucide="lock"></i><span>' + escapeHtml(i18n.proRelatedLocked || 'Related recordings, journeys, and segment reports unlock in Pro.') + '</span></div>';
			}
			return '';
		}

		if (best.resolved.url) {
			return '<div class="ob-smart-insights-report-callout is-actionable"><span>' + escapeHtml(i18n.whereToInvestigate || 'Where to investigate') + '</span><strong>' + escapeHtml(best.meta.label) + '</strong><small>' + escapeHtml(best.meta.description) + '</small><a class="button button-primary ob-smart-insights-report-cta" href="' + escapeHtml(best.resolved.url) + '" target="_blank" rel="noopener noreferrer"><i data-lucide="' + escapeHtml(best.meta.icon) + '"></i>' + escapeHtml(best.meta.label) + '</a></div>';
		}
		var reason = best.resolved.disabledReason || (i18n.relatedUnavailable || 'No deep link available');
		return '<div class="ob-smart-insights-report-callout is-disabled"><span>' + escapeHtml(i18n.whereToInvestigate || 'Where to investigate') + '</span><strong>' + escapeHtml(best.meta.label) + '</strong><small>' + escapeHtml(reason) + '</small></div>';
	}

	function getSafePriorityScore(insight) {
		var score = getPriorityScore(insight);
		if (score < 0) {
			return 0;
		}
		if (score > 100) {
			return 100;
		}
		return score;
	}

	function renderCenterPriorityMeter(insight, priorityLabel) {
		var score = getSafePriorityScore(insight);
		var priorityClass = getSeverityClass(priorityLabel);
		var label = (i18n.priority || 'Priority') + ' ' + score + '/100';
		return '<div class="ob-smart-insights-priority-meter is-' + escapeHtml(priorityClass) + '" aria-label="' + escapeHtml(label) + '">' +
			'<span><small>' + escapeHtml(i18n.priority || 'Priority') + '</small><strong>' + score + '</strong></span>' +
			'<b aria-hidden="true"><i style="width:' + score + '%"></i></b>' +
		'</div>';
	}

	function normalizeComparableText(value) {
		return String(value || '')
			.toLowerCase()
			.replace(/https?:\/\/\S+/g, ' ')
			.replace(/[_\-\/|:]+/g, ' ')
			.replace(/[^\p{L}\p{N}\s]/gu, '')
			.replace(/\b(entity|segment|type|page|form|funnel|source|campaign|device|report|the|a|an)\b/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function textContainsMeaning(parent, child) {
		var haystack = normalizeComparableText(parent);
		var needle = normalizeComparableText(child);
		if (!haystack || !needle || needle.length < 3) {
			return false;
		}
		return haystack === needle || haystack.indexOf(needle) !== -1;
	}

	function isDuplicateToken(value, renderedText) {
		return renderedText.some(function(existing) {
			return textContainsMeaning(existing, value) || textContainsMeaning(value, existing);
		});
	}

	function getEntityTypeLabel(insight) {
		var type = String((insight && insight.entity_type) || '').trim();
		var labels = {
			page: i18n.page || 'Page',
			form: i18n.form || 'Form',
			funnel: i18n.funnel || 'Funnel',
			source: i18n.source || 'Source',
			campaign: i18n.campaign || 'Campaign',
			device: i18n.segment || 'Segment',
			cta: i18n.cta || 'CTA',
			error: i18n.errorType || 'Error'
		};
		return labels[type] || (type ? prettifyKey(type) : '');
	}

	function shouldRenderEntityTypeChip(insight, context) {
		var typeLabel = getEntityTypeLabel(insight);
		var title = insight && insight.signal_name ? insight.signal_name : '';
		var label = context && context.label ? context.label : '';
		if (!typeLabel || textContainsMeaning(title, typeLabel) || textContainsMeaning(label, typeLabel)) {
			return false;
		}
		if (String((insight && insight.entity_type) || '').toLowerCase() === 'device' && label) {
			return false;
		}
		return true;
	}

	function getCompactIdentifierLabel(identifier) {
		var value = String(identifier || '').trim();
		if (!value) {
			return '';
		}
		if (!isHttpUrl(value)) {
			return value;
		}
		try {
			var parsed = new URL(value, window.location.origin);
			var path = parsed.pathname || '/';
			path = path.replace(/\/+/g, '/').replace(/\/$/, '') || '/';
			return path.length > 34 ? '…' + path.slice(-33) : path;
		} catch (e) {
			return '';
		}
	}

	function renderCompactContextLine(insight) {
		var context = getEntityContext(insight);
		var label = context.label || getPageLabel(insight);
		var identifier = context.identifier || '';
		var title = insight.signal_name || '';
		var renderedText = [title];
		var tokens = [];
		var typeLabel = getEntityTypeLabel(insight);
		var renderedLabel = false;
		var entityType = String((insight && insight.entity_type) || '').toLowerCase();
		var labelIsDuplicate = label && isDuplicateToken(label, renderedText);

		if (label && !labelIsDuplicate) {
			var prefix = shouldRenderEntityTypeChip(insight, context) ? '<small>' + escapeHtml(typeLabel) + '</small>' : '';
			var labelTitle = identifier ? label + ' — ' + identifier : label;
			tokens.push('<span class="ob-smart-insights-entity-summary">' + prefix + '<strong title="' + escapeHtml(labelTitle) + '">' + escapeHtml(label) + '</strong></span>');
			renderedText.push(label);
			renderedLabel = true;
		}

		if (identifier && !(entityType === 'page' && labelIsDuplicate && isHttpUrl(identifier)) && !isDuplicateToken(identifier, renderedText) && (renderedLabel || !/^\d+$/.test(String(identifier).trim()))) {
			var compactIdentifier = renderedLabel && isHttpUrl(identifier) ? '' : getCompactIdentifierLabel(identifier);
			if (compactIdentifier) {
				tokens.push('<em class="ob-smart-insights-context-token" title="' + escapeHtml(identifier) + '">' + escapeHtml(compactIdentifier) + '</em>');
				renderedText.push(compactIdentifier);
			}
		}

		if (!tokens.length && typeLabel && shouldRenderEntityTypeChip(insight, context)) {
			tokens.push('<span class="ob-smart-insights-context-token">' + escapeHtml(typeLabel) + '</span>');
		}

		if (!tokens.length) {
			return '';
		}

		return '<div class="ob-smart-insights-entity-context">' +
			tokens.join('') +
		'</div>';
	}

	function getCompactCenterExplanation(insight, title) {
		var text = String((insight && (insight.interpretation || insight.why_it_matters)) || '').trim();
		if (!text) {
			return '';
		}

		var entityType = String((insight && insight.entity_type) || '').toLowerCase();
		if (entityType === 'page') {
			var context = getEntityContext(insight);
			var label = context.label || getPageLabel(insight);
			if ((label && textContainsMeaning(text, label)) || (title && textContainsMeaning(text, title))) {
				return '';
			}
		}

		return text;
	}

	// --- Outcome loop: did the fix work? ------------------------------------
	// The backend measures the insight's own metric over the two weeks that
	// followed the resolution and stores the verdict; the UI only formats it.
	function getOutcome(insight) {
		var outcome = insight && insight.outcome && typeof insight.outcome === 'object' ? insight.outcome : null;
		return outcome && outcome.metric_key ? outcome : null;
	}

	function formatShortDate(value) {
		var raw = String(value || '').trim();
		if (!raw) {
			return '';
		}
		var parsed = Date.parse(raw.replace(' ', 'T'));
		if (isNaN(parsed)) {
			return raw.slice(0, 10);
		}
		try {
			return new Date(parsed).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
		} catch (error) {
			return raw.slice(0, 10);
		}
	}

	function formatOutcomeValue(value, unit) {
		return unit === 'seconds' ? formatSeconds(value) : formatPercent(value);
	}

	function formatOutcomeDelta(delta, unit) {
		var number = parseFloat(delta);
		if (isNaN(number)) {
			return '';
		}
		// U+2212 keeps the minus sign readable next to the digits.
		var sign = number > 0 ? '+' : (number < 0 ? '−' : '');
		var magnitude = Math.abs(number);
		if (unit === 'seconds') {
			return sign + fillToken(i18n.outcomeSeconds || '%ss', '%s', magnitude.toFixed(1));
		}
		return sign + fillToken(i18n.outcomePoints || '%s pts', '%s', magnitude.toFixed(1));
	}

	// "bounce rate 65.0% -> 48.0% (-17.0 pts)" when both halves were measured,
	// the honest short sentence when they were not.
	function getOutcomeChangeLabel(outcome) {
		if (!outcome) {
			return '';
		}

		var verdict = String(outcome.verdict || '');
		var before = parseFloat(outcome.before_value);
		var after = parseFloat(outcome.after_value);

		if (isNaN(before) || isNaN(after)) {
			return verdict === 'inconclusive'
				? (i18n.outcomeNotMeasurable || 'not measurable yet')
				: (i18n.outcomePending || 'measurement in progress');
		}

		if (verdict === 'no_change') {
			return i18n.outcomeNoChange || 'no measurable change yet';
		}

		var template = i18n.outcomeChange || '%1$s %2$s → %3$s (%4$s)';
		template = fillToken(template, '%1$s', outcome.metric_label || outcome.metric_key || '');
		template = fillToken(template, '%2$s', formatOutcomeValue(before, outcome.unit));
		template = fillToken(template, '%3$s', formatOutcomeValue(after, outcome.unit));
		return fillToken(template, '%4$s', formatOutcomeDelta(outcome.delta_abs, outcome.unit));
	}

	function getOutcomeVerdictClass(outcome) {
		var verdict = String((outcome && outcome.verdict) || '');
		if (verdict === 'improved') {
			return 'is-improved';
		}
		if (verdict === 'worse') {
			return 'is-worse';
		}
		return 'is-flat';
	}

	function getOutcomeChipLabel(insight, outcome) {
		var resolvedAt = (outcome && outcome.resolved_at) || (insight && insight.resolved_at) || '';
		var fixed = fillToken(i18n.outcomeFixed || 'Fixed %s', '%s', formatShortDate(resolvedAt));
		var change = getOutcomeChangeLabel(outcome);
		return change ? fixed + ' · ' + change : fixed;
	}

	function renderOutcomeChip(insight) {
		var status = String((insight && insight.status) || '').toLowerCase();
		if (status !== 'resolved' && status !== 'auto_resolved') {
			return '';
		}

		var outcome = getOutcome(insight);
		if (!outcome) {
			return '';
		}

		var label = getOutcomeChipLabel(insight, outcome);
		var title = outcome.verdict_label ? outcome.verdict_label + ' — ' + label : label;

		return '<span class="ob-smart-insights-outcome-chip ' + escapeHtml(getOutcomeVerdictClass(outcome)) + '" title="' + escapeHtml(title) + '">' +
			'<i data-lucide="check-circle-2" aria-hidden="true"></i>' + escapeHtml(label) +
		'</span>';
	}

	function renderCenterHeaderChips(insight, priorityLabel, priorityClass) {
		var title = insight.signal_name || '';
		var categoryLabel = getCategoryLabel(insight.category);
		var chips = [];

		if (categoryLabel && !textContainsMeaning(title, categoryLabel)) {
			chips.push('<span class="ob-smart-insights-category-chip">' + escapeHtml(categoryLabel) + '</span>');
		}

		chips.push('<span class="ob-smart-insights-status-chip is-' + escapeHtml(getSeverityClass(insight.status || 'new')) + '">' + escapeHtml(getStatusLabel(insight.status)) + '</span>');

		var story = renderStoryChip(insight);
		if (story) {
			chips.push(story);
		}

		var recurrence = renderRecurrenceChip(insight);
		if (recurrence) {
			chips.push(recurrence);
		}

		var outcome = renderOutcomeChip(insight);
		if (outcome) {
			chips.push(outcome);
		}

		if (insight.is_locked_preview) {
			chips.push('<span class="ob-smart-insights-badge is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>');
		}

		return chips.length ? '<div class="ob-smart-insights-chip-row">' + chips.join('') + '</div>' : '';
	}

	function renderCenterActionPanel(insight, action) {
		var best = getBestRelatedReport(insight);
		var panelClass = 'is-plain';
		var reportHtml = '';

		if (best && best.resolved && best.resolved.url) {
			panelClass = 'is-actionable';
			reportHtml = '<a class="button button-primary ob-smart-insights-report-cta" href="' + escapeHtml(best.resolved.url) + '" target="_blank" rel="noopener noreferrer"><i data-lucide="' + escapeHtml(best.meta.icon) + '"></i>' + escapeHtml(best.meta.label) + '</a>';
		} else if (best) {
			panelClass = 'is-disabled';
			reportHtml = '<small class="ob-smart-insights-next-report"><span>' + escapeHtml(best.meta.label) + '</span>' + escapeHtml(best.resolved.disabledReason || (i18n.relatedUnavailable || 'No deep link available')) + '</small>';
		} else if (insight.is_locked_preview) {
			panelClass = 'is-locked';
			reportHtml = '<small class="ob-smart-insights-next-report"><span>' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>' + escapeHtml(i18n.proRelatedLocked || 'Related recordings, journeys, and segment reports unlock in Pro.') + '</small>';
		}

		return '<div class="ob-smart-insights-next-panel ' + panelClass + '">' +
			'<div class="ob-smart-insights-next-copy"><span>' + escapeHtml(i18n.nextAction || 'Next action') + '</span><strong>' + escapeHtml(action || getRecommendedAction(insight)) + '</strong></div>' +
			reportHtml +
		'</div>';
	}

	function renderCenterFooter(insight, priorityLabel) {
		var detectedTime = renderDetectedTime(insight);
		return '<div class="ob-smart-insights-center-footer">' +
			'<div class="ob-smart-insights-card-footer-meta">' +
				(detectedTime ? '<span>' + detectedTime + '</span>' : '') +
				'<span>' + escapeHtml(i18n.confidence || 'Confidence') + ': <strong>' + escapeHtml(getConfidenceLabel(insight)) + '</strong></span>' +
				renderCenterPriorityMeter(insight, priorityLabel) +
			'</div>' +
			renderActions(insight) +
		'</div>';
	}

	function renderDetailNextAction(insight, action) {
		action = String(action || '').trim();
		if (!action) {
			return '';
		}
		return '<section class="ob-smart-insights-detail-section is-next">' + renderSectionHeading(i18n.nextAction || 'Next action', 'next_action', { position: 'left' }) + '<div class="ob-smart-insights-action-callout"><span class="ob-smart-insights-action-icon" aria-hidden="true"><i data-lucide="arrow-up-right"></i><span class="ob-smart-insights-action-icon-fallback">↗</span></span><strong>' + escapeHtml(action) + '</strong></div></section>';
	}

	function renderUpgradePreview(insight) {
		var preview = insight.locked_preview || insight.upgrade_preview || null;
		if (!preview && !insight.is_locked_preview) {
			return '';
		}
		var title = preview && preview.title ? preview.title : (i18n.proDiagnosis || 'Pro diagnosis available');
		var description = preview && preview.description ? preview.description : (i18n.proDiagnosisDescription || 'Unlock segment breakdowns, related recordings, and advanced CRO recommendations.');
		return '<div class="ob-smart-insights-upgrade-preview"><div><span class="ob-smart-insights-badge is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span><strong>' + escapeHtml(title) + '</strong></div><p>' + escapeHtml(description) + '</p></div>';
	}

	var IMPACT_CURRENCY_SYMBOLS = { EUR: '€', USD: '$', GBP: '£', JPY: '¥' };

	// Currency values can contain "$", which String.replace() would read as a
	// capture-group reference. A function replacement inserts them verbatim.
	function fillToken(template, token, value) {
		return String(template).replace(token, function() {
			return value;
		});
	}

	function formatImpactCurrency(amount, currency, decimals) {
		var number = parseFloat(amount);
		var digits = decimals === undefined ? 0 : decimals;
		var value;
		if (isNaN(number)) {
			value = '-';
		} else {
			value = number.toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });
		}
		var code = String(currency || '').trim().toUpperCase();
		if (IMPACT_CURRENCY_SYMBOLS[code]) {
			return IMPACT_CURRENCY_SYMBOLS[code] + value;
		}
		return code ? code + ' ' + value : value;
	}

	// The honest half of the impact line: when the backend gate flagged the
	// insight as measured on too little traffic, no loss figure is printed at
	// all - only what the sample can support.
	function renderObservationImpact(insight) {
		var sample = getObservationSample(insight);
		var sentence = sample === null
			? (i18n.impactObservationNoSample || 'Too little traffic to size the impact reliably. Treat this as an observation to confirm, not a loss to act on.')
			: (i18n.impactObservation || 'Too little traffic to size the impact reliably (%s sessions). Treat this as an observation to confirm, not a loss to act on.').replace('%s', formatNumber(sample));

		return '<div class="ob-smart-insights-impact-line is-observation">' +
			'<span class="ob-smart-insights-impact-label">' + escapeHtml(i18n.impactObservationLabel || 'Observation') + '</span>' +
			'<span class="ob-smart-insights-impact-observation">' + escapeHtml(sentence) + '</span>' +
		'</div>';
	}

	// One line, always the same shape: what this problem costs in the analysed
	// window - in money when a value per conversion is known, in visitors
	// otherwise - and on which population it was measured.
	function renderImpactLine(insight) {
		var impact = getImpact(insight);
		var observationOnly = isObservationOnly(insight);

		if (!impact) {
			return observationOnly ? renderObservationImpact(insight) : '';
		}

		var usersLost = getUsersLost(insight);
		if (usersLost === null || usersLost <= 0) {
			return observationOnly ? renderObservationImpact(insight) : '';
		}

		if (observationOnly) {
			return renderObservationImpact(insight);
		}

		var basis = String(impact.basis_label || '').trim();
		var revenue = getRevenue(insight);
		var revenueAmount = getRevenueAmount(insight);
		var headline = String(impact.headline || '').trim();
		var detailHtml = '';

		if (revenueAmount !== null) {
			headline = fillToken(i18n.impactRevenueHeadline || '≈ %s at risk this period', '%s', formatImpactCurrency(revenueAmount, revenue.currency));

			var unitValue = parseFloat(revenue.average_order_value);
			if (!isNaN(unitValue) && unitValue > 0) {
				// The amount is users_lost x conversion factor x unit value. When the
				// factor discounts the loss (visitors who were not yet at a checkout
				// step), the factor must be printed too, or the line reads as a
				// multiplication that does not produce the headline.
				var isManual = 'manual_conversion_value' === revenue.source;
				var factor = parseFloat(revenue.conversion_factor);
				var factored = !isNaN(factor) && factor > 0 && factor < 0.995;
				var template;
				if (factored) {
					template = isManual
						? (i18n.impactRevenueManualFactored || '%1$s drop-offs × %2$s conversion rate × %3$s per conversion')
						: (i18n.impactRevenueOrdersFactored || '%1$s drop-offs × %2$s conversion rate × %3$s avg. order');
					template = fillToken(template, '%3$s', formatImpactCurrency(unitValue, revenue.currency, 2));
					template = fillToken(template, '%2$s', formatPercent(factor * 100));
				} else {
					template = isManual
						? (i18n.impactRevenueManual || '%1$s drop-offs × %2$s per conversion')
						: (i18n.impactRevenueOrders || '%1$s drop-offs × %2$s avg. order');
					template = fillToken(template, '%2$s', formatImpactCurrency(unitValue, revenue.currency, 2));
				}
				template = fillToken(template, '%1$s', formatNumber(usersLost));
				detailHtml = '<small class="ob-smart-insights-impact-revenue">' + escapeHtml(template) + '</small>';
			}
		} else if (revenue && revenue.locked) {
			detailHtml = '<small class="ob-smart-insights-impact-revenue is-locked">' +
				escapeHtml(revenue.hint || i18n.revenueLocked || 'Revenue exposure for this leak is available in Pro.') + '</small>';
		}

		if (!headline) {
			headline = formatNumber(usersLost);
		}

		return '<div class="ob-smart-insights-impact-line" data-users-lost="' + escapeHtml(usersLost) + '">' +
			'<span class="ob-smart-insights-impact-label">' + escapeHtml(i18n.businessImpact || 'Business impact') + '</span>' +
			'<strong class="ob-smart-insights-impact-headline">' + escapeHtml(headline) + '</strong>' +
			detailHtml +
			(basis ? '<small class="ob-smart-insights-impact-basis">' + escapeHtml((i18n.impactBasis || 'Measured on') + ': ' + basis) + '</small>' : '') +
		'</div>';
	}

	// Cause chips carry the measured share when the viewer may see it; Free gets
	// the ranked cause labels plus one locked hint instead of the percentages.
	function renderCauseChips(insight) {
		var causes = getStoryCauses(insight);
		if (!causes.length) {
			return '';
		}

		var correlation = getCorrelation(insight);
		var sharesLocked = !!(correlation && correlation.shares_locked);
		var chips = causes.slice(0, 4).map(function(cause) {
			var label = String((cause && cause.label) || '').trim();
			if (!label) {
				return '';
			}
			var share = '';
			if (!sharesLocked && cause && cause.share_pct !== undefined && cause.share_pct !== null && cause.share_pct !== '') {
				share = '<span class="ob-smart-insights-cause-share">' + escapeHtml(formatPercent(cause.share_pct)) + '</span>';
			}
			return '<span class="ob-smart-insights-cause-chip' + (sharesLocked ? ' is-locked' : '') + '">' +
				'<span class="ob-smart-insights-cause-label">' + escapeHtml(label) + '</span>' + share +
			'</span>';
		}).filter(Boolean);

		if (!chips.length) {
			return '';
		}

		var lockedHint = sharesLocked
			? '<small class="ob-smart-insights-cause-locked-hint">' + escapeHtml((correlation && correlation.locked_hint) || i18n.causeSharesLocked || 'Upgrade to Pro to see how much of this problem each cause explains.') + '</small>'
			: '';

		return '<div class="ob-smart-insights-cause-row" aria-label="' + escapeHtml(i18n.measuredCauses || 'Measured causes') + '">' +
			chips.join('') + lockedHint +
		'</div>';
	}

	function renderStoryChip(insight) {
		if (!isStoryInsight(insight)) {
			return '';
		}

		var correlation = getCorrelation(insight);
		var signalCount = parseInt(correlation.signal_count, 10) || 0;
		var causeCount = getStoryCauses(insight).length || (parseInt(correlation.cause_count, 10) || 0);
		var title = signalCount > 1
			? sprintfCount(i18n.storySignalCount || '%s correlated signals', signalCount)
			: sprintfCount(i18n.storyCauseCount || '%s measured causes', causeCount);

		return '<span class="ob-smart-insights-story-chip" title="' + escapeHtml(title) + '">' +
			escapeHtml(i18n.correlatedStory || 'Correlated story') +
			(signalCount > 1 ? '<span class="ob-smart-insights-story-count">' + escapeHtml(signalCount) + '</span>' : '') +
		'</span>';
	}

	function renderRecurrenceChip(insight) {
		var count = parseInt((insight && (insight.recurrence_count || (insight.detection && insight.detection.recurrence_count))) || 0, 10) || 0;
		if (count <= 1) {
			return '';
		}
		return '<span class="ob-smart-insights-recurrence-chip" title="' + escapeHtml(i18n.recurrenceHistory || 'Older overlapping detections are grouped into this card.') + '">' + escapeHtml((i18n.recurring || 'Recurring') + ': ' + count) + '</span>';
	}


	function renderActions(insight) {
		var id = parseInt(insight.id, 10) || 0;
		return '<div class="ob-smart-insights-card-actions" aria-label="' + escapeHtml(i18n.status || 'Status') + '">' +
			'<button type="button" class="button button-small ob-smart-insights-detail-btn is-detail" data-insight-id="' + id + '">' + escapeHtml(i18n.viewDetails || 'View details') + '</button>' +
			'<label class="ob-smart-insights-status-control"><span>' + escapeHtml(i18n.updateStatus || 'Update status') + '</span><select class="ob-smart-insights-status-select" data-insight-id="' + id + '">' +
				'<option value="">' + escapeHtml(i18n.chooseStatus || 'Choose status') + '</option>' +
				'<option value="viewed">' + escapeHtml(i18n.markReviewed || 'Mark as reviewed') + '</option>' +
				'<option value="in_progress">' + escapeHtml(i18n.markInProgress || 'In progress') + '</option>' +
				'<option value="resolved">' + escapeHtml(i18n.resolve || 'Resolve') + '</option>' +
				'<option value="ignored">' + escapeHtml(i18n.ignore || 'Ignore') + '</option>' +
			'</select></label>' +
		'</div>';
	}

	function renderDashboard(section, items, data) {
		var list = section.querySelector('.ob-smart-insights-list');
		if (!list) {
			return;
		}

		releasePortaledTooltips(list);

		if (!items.length) {
			list.innerHTML = renderEmptyState(data, getContextData(section));
			refreshIcons();
			maybeOpenDeepLinkedInsight(section);
			return;
		}

		list.innerHTML = '<div class="ob-smart-insights-card-grid">' + items.map(renderInsightCard).join('') + '</div>';
		refreshIcons();
	}

	function renderInsightCard(insight) {
		var priorityLabel = getPriorityLabel(insight);
		var priorityClass = getSeverityClass(priorityLabel);
		var action = getRecommendedAction(insight);

		return '<article class="ob-smart-insights-card' + (isStoryInsight(insight) ? ' is-story' : '') + '" data-insight-id="' + escapeHtml(insight.id) + '">' +
			'<div class="ob-smart-insights-card-top">' +
				'<span class="ob-smart-insights-badge is-' + escapeHtml(priorityClass) + '">' + escapeHtml(priorityLabel) + '</span>' +
				'<span class="ob-smart-insights-score">' + escapeHtml(i18n.priority || 'Priority') + ': <strong>' + getPriorityScore(insight) + '/100</strong></span>' +
				renderStoryChip(insight) +
				renderRecurrenceChip(insight) +
				(insight.is_locked_preview ? '<span class="ob-smart-insights-badge is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>' : '') +
			'</div>' +
			'<h3 class="ob-smart-insights-section-heading">' + escapeHtml(insight.signal_name || (i18n.smartInsight || 'Smart Insight')) + renderSignalTooltip(insight, { position: 'bottom' }) + '</h3>' +
			'<p class="ob-smart-insights-page-label"><span>' + escapeHtml(getCategoryLabel(insight.category)) + '</span> &middot; ' + renderEntityReference(insight) + '</p>' +
			'<div class="ob-smart-insights-meta">' +
				'<span>' + escapeHtml(i18n.confidence || 'Confidence') + ': <strong>' + escapeHtml(getConfidenceLabel(insight)) + '</strong></span>' +
				'<span>' + escapeHtml(getStatusLabel(insight.status)) + '</span>' +
				renderDetectedTime(insight) +
			'</div>' +
			renderImpactLine(insight) +
			renderCauseChips(insight) +
			renderStageTracker(insight) +
			'<div class="ob-smart-insights-evidence">' + renderEvidence(insight, 4) + '</div>' +
			'<p class="ob-smart-insights-explanation">' + escapeHtml(insight.interpretation || '') + '</p>' +
			(action ? '<div class="ob-smart-insights-recommendation"><span>' + escapeHtml(i18n.recommendedAction || 'Recommended action') + '</span><strong>' + escapeHtml(action) + '</strong></div>' : '') +
			renderRelatedReportPreview(insight) +
			renderUpgradePreview(insight) +
			renderActions(insight) +
		'</article>';
	}

	function renderCenter(section, items, data) {
		var list = section.querySelector('.ob-smart-insights-list');
		if (!list) {
			return;
		}

		releasePortaledTooltips(list);

		if (!items.length) {
			list.innerHTML = renderEmptyState(data, getContextData(section));
			refreshIcons();
			maybeOpenDeepLinkedInsight(section);
			return;
		}

		// Explicit invocation (not `map(renderCenterRow)`): map passes the index
		// as the second argument, which would collide with the children param.
		var groups = groupCenterItems(items);
		var cards = groups.map(function(group) {
			if (group.sourceMembers && group.sourceMembers.length) {
				return renderSourceGroupCard(group.sourceMembers);
			}
			return renderCenterRow(group.insight, group.children);
		});

		// Visible cap: the overflow cards stay in the DOM (search, deep links and
		// [data-insight-id] lookups keep working); only their visibility changes.
		var visibleLimit = parseInt(config.visibleLimit, 10);
		if (!(visibleLimit > 0)) {
			visibleLimit = 8;
		}

		var folded = cards.slice(visibleLimit);
		var markup = cards.slice(0, visibleLimit).join('');
		if (folded.length) {
			var moreLabel = (i18n.showMoreObservations || 'Show %s more observations').replace('%s', formatNumber(folded.length));
			markup += '<div class="ob-smart-insights-center-more" hidden>' + folded.join('') + '</div>' +
				'<button type="button" class="button-link ob-smart-insights-center-more-toggle" data-more-count="' + escapeHtml(String(folded.length)) + '" aria-expanded="false">' +
					escapeHtml(moreLabel) +
				'</button>';
		}

		list.innerHTML = '<div class="ob-smart-insights-center-list">' + markup + '</div>';
		refreshIcons();
		maybeOpenDeepLinkedInsight(section);
	}

	// Folds/unfolds the capped overflow cards and keeps the toggle label and
	// aria-expanded in sync (also used when a deep link targets a folded card).
	function setCenterMoreExpanded(section, expanded) {
		var more = section.querySelector('.ob-smart-insights-center-more');
		if (!more) {
			return;
		}

		if (expanded) {
			more.removeAttribute('hidden');
		} else {
			more.setAttribute('hidden', 'hidden');
		}

		var toggle = section.querySelector('.ob-smart-insights-center-more-toggle');
		if (!toggle) {
			return;
		}

		var count = parseInt(toggle.getAttribute('data-more-count'), 10) || 0;
		toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		toggle.textContent = expanded
			? (i18n.showFewerObservations || 'Show fewer')
			: (i18n.showMoreObservations || 'Show %s more observations').replace('%s', formatNumber(count));
	}

	function maybeOpenDeepLinkedInsight(section) {
		if (!pendingDeepOpenInsightId || !section || (section.getAttribute('data-ob-smart-insights-context') || '') !== 'center') {
			return;
		}

		var insightId = pendingDeepOpenInsightId;
		pendingDeepOpenInsightId = 0;

		var card = section.querySelector('[data-insight-id="' + insightId + '"]');
		if (card) {
			var folded = card.closest ? card.closest('.ob-smart-insights-center-more') : null;
			if (folded && folded.hasAttribute('hidden')) {
				setCenterMoreExpanded(section, true);
			}
			card.classList.add('is-deep-linked');
			card.scrollIntoView({ behavior: 'smooth', block: 'center' });
		} else {
			setAlert(section, i18n.openingLinkedInsight || 'Opening the linked Smart Insight. It may be outside the current filters.', 'info');
		}

		openDetail(section, insightId);
	}

	function getPeriodLabel(value) {
		var labels = {
			last7days: 'Last 7 Days',
			last14days: 'Last 14 Days',
			last30days: 'Last 30 Days',
			last90days: 'Last 3 Months',
			today: 'Today',
			yesterday: 'Yesterday',
			custom: 'Custom Range'
		};
		return labels[value] || value || 'Last 30 Days';
	}

	function syncActiveFilterState(section) {
		var contextData = getContextData(section);
		var reset = section.querySelector('.ob-smart-insights-reset');
		var filterWrap = section.querySelector('.ob-smart-insights-header-filters');
		var active = hasActiveFilters(contextData);
		if (reset) {
			reset.hidden = !active;
		}
		if (filterWrap) {
			filterWrap.classList.toggle('has-active-filters', active);
		}
	}

	function resetFilters(section) {
		var defaults = {
			'.ob-smart-insights-status': 'active',
			'.ob-smart-insights-severity': '',
			'.ob-smart-insights-category': '',
			'.ob-smart-insights-entity-type': '',
			'.ob-smart-insights-confidence': '',
			'.ob-smart-insights-search': '',
			'.ob-smart-insights-sort': 'priority'
		};
		Object.keys(defaults).forEach(function(selector) {
			var element = section.querySelector(selector);
			if (element) {
				element.value = defaults[selector];
			}
		});
		syncActiveFilterState(section);
		loadWeeklySummary(section);
		loadSection(section, { autoRefreshIfStale: true });
	}

	// Compact "Also detected" list for the signals grouped under a primary.
	// Each line opens the child's own detail modal and keeps data-insight-id so
	// maybeOpenDeepLinkedInsight() still resolves deep links to children.
	function renderCenterChildren(children) {
		if (!Array.isArray(children) || !children.length) {
			return '';
		}

		var label = (i18n.alsoDetectedHere || 'Also detected here (%s)').replace('%s', String(children.length));
		var lines = children.map(function(child) {
			var childId = String(child.id);
			var parts = [child.signal_name || (i18n.smartInsight || 'Smart Insight')];
			parts.push((i18n.priorityShort || 'priority %s').replace('%s', String(getPriorityScore(child))));
			var usersLost = getUsersLost(child);
			if (usersLost) {
				parts.push((i18n.sessionsLostShort || '%s sessions lost').replace('%s', formatNumber(usersLost)));
			}

			return '<li>' +
				'<button type="button" class="button-link ob-smart-insights-child-open" data-open-insight="' + escapeHtml(childId) + '" data-insight-id="' + escapeHtml(childId) + '">' +
					escapeHtml(parts.join(' — ')) +
				'</button>' +
			'</li>';
		}).join('');

		return '<div class="ob-smart-insights-center-children">' +
			'<span class="ob-smart-insights-center-children-label">' + escapeHtml(label) + '</span>' +
			'<ul>' + lines + '</ul>' +
		'</div>';
	}

	function renderCenterRow(insight, children) {
		var priorityLabel = getPriorityLabel(insight);
		var priorityClass = getSeverityClass(priorityLabel);
		var action = getRecommendedAction(insight);
		var title = insight.signal_name || (i18n.smartInsight || 'Smart Insight');
		var interpretation = getCompactCenterExplanation(insight, title);
		// Story card = merged, correlated problem. Uncorrelated single signals
		// keep the exact markup they had before, so nothing regresses.
		var isStory = isStoryInsight(insight);

		return '<article class="ob-smart-insights-center-card is-' + escapeHtml(priorityClass) + (insight.is_locked_preview ? ' is-locked-preview' : '') + (isStory ? ' is-story' : '') + '" data-insight-id="' + escapeHtml(insight.id) + '">' +
			'<span class="ob-smart-insights-severity-rail" aria-hidden="true"></span>' +
			'<div class="ob-smart-insights-center-main ob-smart-insights-primary-zone">' +
				'<div class="ob-smart-insights-title-row">' +
					'<h3 title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</h3>' +
					renderSignalTooltip(insight, { position: 'bottom' }) +
				'</div>' +
				renderCenterHeaderChips(insight, priorityLabel, priorityClass) +
				renderCompactContextLine(insight) +
				renderImpactLine(insight) +
				renderCauseChips(insight) +
				renderStageTracker(insight) +
				(interpretation ? '<p class="ob-smart-insights-explanation">' + escapeHtml(interpretation) + '</p>' : '') +
				renderCenterChildren(children) +
				renderUpgradePreview(insight) +
			'</div>' +
			'<div class="ob-smart-insights-metric-strip ob-smart-insights-evidence-zone" aria-label="' + escapeHtml(i18n.evidence || 'Evidence') + '">' + renderCenterEvidenceMicroCards(insight, 3) + '</div>' +
			'<div class="ob-smart-insights-action-zone">' + renderCenterActionPanel(insight, action) + '</div>' +
			renderCenterFooter(insight, priorityLabel) +
		'</article>';
	}


	function hasActiveFilters(contextData) {
		if (!contextData) {
			return false;
		}
		var nonDefaultStatus = contextData.status && contextData.status !== 'active';
		var nonDefaultSort = contextData.sort && contextData.sort !== 'priority';
		return !!(nonDefaultStatus || nonDefaultSort || contextData.severity || contextData.category || contextData.entityType || contextData.confidence || contextData.search);
	}

	function renderEmptyState(data, contextData) {
		var state = data && data.state ? data.state : {};
		var lowData = !!state.is_low_data;
		var filtered = hasActiveFilters(contextData);
		var title = filtered ? (i18n.noMatchingInsights || 'No insights match these filters.') : (i18n.noCriticalSignals || 'No critical behavior signals detected for this period.');
		var body = filtered ? (i18n.noMatchingInsightsBody || 'Try broadening the filters or selecting a longer date range.') : (i18n.noCriticalSignalsBody || 'Your key engagement metrics are within expected ranges. You can still review heatmaps, funnels, and source reports to look for optimization opportunities.');
		var icon = 'sparkles';

		if (lowData) {
			title = i18n.lowDataTitle || 'Not enough data yet to generate reliable Smart Insights.';
			body = i18n.lowDataBody || 'OptiUser needs more visitor activity before it can detect meaningful behavior patterns. Insights become more reliable after at least 100 sessions per page or segment.';
			icon = 'bar-chart-2';
		}

		return '<div class="ob-smart-insights-empty ' + (lowData ? 'is-low-data' : '') + '">' +
			'<div class="ob-smart-insights-empty-icon"><i data-lucide="' + escapeHtml(icon) + '"></i></div>' +
			'<h3>' + escapeHtml(title) + '</h3>' +
			'<p>' + escapeHtml(body) + '</p>' +
		'</div>';
	}


	function loadSection(section, options) {
		var contextData = getContextData(section);
		if (!hasCompleteCustomRange(contextData)) {
			setAlert(section, i18n.selectCustomRange || 'Select a start and end date to use a custom range.', 'info');
			var emptyList = section.querySelector('.ob-smart-insights-list');
			if (emptyList) {
				emptyList.innerHTML = '<div class="ob-smart-insights-empty"><h3>' + escapeHtml(i18n.selectCustomRangeTitle || 'Custom range requires dates') + '</h3><p>' + escapeHtml(i18n.selectCustomRange || 'Select a start and end date to use a custom range.') + '</p></div>';
			}
			return Promise.resolve();
		}

		var isDashboard = contextData.context === 'dashboard';
		var limit = isDashboard ? 5 : 100;
		var payload = {
			context: contextData.context,
			period: contextData.period,
			start_date: contextData.startDate,
			end_date: contextData.endDate,
			exclude_spam: contextData.excludeSpam,
			limit: limit,
			status: isDashboard ? 'active' : contextData.status,
			category: contextData.category,
			entity_type: contextData.entityType,
			confidence: contextData.confidence,
			search: contextData.search,
			top_only: isDashboard ? '1' : '',
			auto_refresh_if_stale: (!isDashboard && options && options.autoRefreshIfStale) ? '1' : ''
		};

		setAlert(section, '', 'info');
		setLoading(section, options && options.refreshing ? (i18n.refreshing || 'Refreshing Smart Insights...') : (i18n.loading || 'Loading Smart Insights...'));

		return postAjax('optibehavior_smart_insights_list', payload)
			.then(function(data) {
				var items = normalizeItems(data);
				syncFilterSelectOptions(section, data);
				items = isDashboard ? items : filterClientItems(items, contextData);
				if (!isDashboard) {
					items = sortInsightsForCenter(items, contextData.sort);
				}
				syncActiveFilterState(section);

				if (isDashboard) {
					renderDashboard(section, items, data);
				} else {
					renderCenter(section, items, data);
					syncNotificationsScope(section);
				}
			})
			.catch(function(error) {
				setAlert(section, error.message || (i18n.error || 'Unable to load Smart Insights.'), 'error');
				var list = section.querySelector('.ob-smart-insights-list');
				if (list) {
					list.innerHTML = renderEmptyState({}, contextData);
				}
			});
	}

	function refreshSection(section) {
		var contextData = getContextData(section);
		if (!hasCompleteCustomRange(contextData)) {
			setAlert(section, i18n.selectCustomRange || 'Select a start and end date to use a custom range.', 'info');
			return;
		}

		var payload = {
			period: contextData.period,
			start_date: contextData.startDate,
			end_date: contextData.endDate,
			exclude_spam: contextData.excludeSpam,
			limit: contextData.context === 'dashboard' ? 5 : 100
		};

		setLoading(section, i18n.refreshing || 'Refreshing Smart Insights...');

		postAjax('optibehavior_smart_insights_refresh', payload)
			.then(function() {
				setAlert(section, i18n.refreshComplete || 'Smart Insights refreshed.', 'success');
				loadWeeklySummary(section);
				return loadSection(section, { refreshing: true });
			})
			.catch(function(error) {
				setAlert(section, error.message || (i18n.error || 'Unable to load Smart Insights.'), 'error');
				return loadSection(section);
			});
	}

	function handleStatus(section, control) {
		var insightId = control.getAttribute('data-insight-id');
		var status = control.getAttribute('data-status') || control.value;
		if (!insightId || !status) {
			return;
		}

		control.disabled = true;
		postAjax('optibehavior_smart_insights_update_status', {
			insight_id: insightId,
			status: status
		})
			.then(function() {
				setAlert(section, i18n.statusUpdated || 'Smart Insight status updated.', 'success');
				loadSection(section).then(function() {
					syncNotificationsScope(section);
				});
			})
			.catch(function(error) {
				setAlert(section, error.message || (i18n.error || 'Unable to load Smart Insights.'), 'error');
			})
			.finally(function() {
				control.disabled = false;
				if (control.tagName && control.tagName.toLowerCase() === 'select') {
					control.value = '';
				}
			});
	}

	function ensureModal() {
		if (activeModal) {
			return activeModal;
		}

		var modal = document.createElement('div');
		modal.className = 'ob-smart-insights-modal';
		modal.hidden = true;
		modal.innerHTML = '<div class="ob-smart-insights-modal-backdrop" data-ob-smart-insights-close></div>' +
			'<div class="ob-smart-insights-modal-panel" role="dialog" aria-modal="true" aria-labelledby="ob-smart-insights-modal-title">' +
				'<button type="button" class="ob-smart-insights-modal-close" data-ob-smart-insights-close aria-label="' + escapeHtml(i18n.close || 'Close') + '">&times;</button>' +
				'<div class="ob-smart-insights-modal-content"></div>' +
			'</div>';
		document.body.appendChild(modal);
		activeModal = modal;

		modal.addEventListener('click', function(event) {
			if (event.target && event.target.hasAttribute('data-ob-smart-insights-close')) {
				closeModal();
				return;
			}

			var disclosureToggle = event.target.closest ? event.target.closest('[data-ob-si-disclosure]') : null;
			if (disclosureToggle) {
				event.preventDefault();
				var expanded = disclosureToggle.getAttribute('aria-expanded') === 'true';
				var body = modal.querySelector('#' + disclosureToggle.getAttribute('data-ob-si-disclosure'));
				disclosureToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
				if (body) {
					body.hidden = expanded;
				}
				refreshIcons();
			}
		});

		modal.addEventListener('change', function(event) {
			var statusSelect = event.target.closest('.ob-smart-insights-status-select');
			if (statusSelect && activeModalSection) {
				handleStatus(activeModalSection, statusSelect);
			}
		});

		document.addEventListener('keydown', function(event) {
			if (event.key === 'Escape' && activeModal && !activeModal.hidden) {
				closeModal();
			}
		});

		return modal;
	}

	function closeModal() {
		if (activeModal) {
			// A pinned help popup lives on document.body, not inside the modal, so
			// hiding the modal alone would strand it on screen.
			releasePortaledTooltips(activeModal);
			activeModal.hidden = true;
			activeModal.classList.remove('has-detail');
			document.body.classList.remove('ob-smart-insights-modal-open');
		}
		activeModalSection = null;
	}

	function openDetail(section, insightId) {
		var modal = ensureModal();
		var content = modal.querySelector('.ob-smart-insights-modal-content');
		activeModalSection = section;
		activeSegmentScope = null;
		modal.classList.remove('has-detail');
		releasePortaledTooltips(content);
		content.innerHTML ='<div class="ob-smart-insights-loading"><span class="ob-smart-insights-spinner" aria-hidden="true"></span><span>' + escapeHtml(i18n.loading || 'Loading Smart Insights...') + '</span></div>';
		modal.hidden = false;
		document.body.classList.add('ob-smart-insights-modal-open');

		postAjax('optibehavior_smart_insights_detail', { insight_id: insightId })
			.then(function(data) {
				var insight = data.insight || {};
				content.innerHTML = renderDetail(insight);
				modal.classList.add('has-detail');
				refreshIcons();
				loadWhereSection(content, insight, section);
			})
			.catch(function(error) {
				modal.classList.remove('has-detail');
				content.innerHTML = '<div class="ob-smart-insights-empty"><h3>' + escapeHtml(error.message || (i18n.error || 'Unable to load Smart Insights.')) + '</h3></div>';
			});
	}

	// One collapsed "show the rest" affordance shared by the modal sections that
	// keep secondary content out of the way (non-applicable reports, generic
	// causes). The toggle is handled by one delegated listener on the modal.
	function renderDisclosure(label, body, options) {
		options = options || {};
		disclosureSeq += 1;
		var id = 'ob-si-disclosure-' + disclosureSeq;
		var open = !!options.open;

		return '<button type="button" class="button-link ob-smart-insights-disclosure-toggle' + (options.className ? ' ' + escapeHtml(options.className) : '') + '" aria-expanded="' + (open ? 'true' : 'false') + '" aria-controls="' + escapeHtml(id) + '" data-ob-si-disclosure="' + escapeHtml(id) + '">' +
				'<span class="ob-smart-insights-disclosure-label">' + escapeHtml(label) + '</span>' +
				'<span class="ob-smart-insights-disclosure-caret" aria-hidden="true">&#9662;</span>' +
			'</button>' +
			'<div class="ob-smart-insights-disclosure-body" id="' + escapeHtml(id) + '"' + (open ? '' : ' hidden') + '>' + body + '</div>';
	}

	function renderList(values, limit) {
		if (!Array.isArray(values) || !values.length) {
			return '<p class="description">-</p>';
		}

		var max = parseInt(limit, 10) || 0;
		if (max > 0 && values.length > max) {
			values = values.slice(0, max);
		}

		return '<ul>' + values.map(function(value) {
			if (typeof value === 'string') {
				return '<li>' + escapeHtml(value) + '</li>';
			}

			var title = value.title || value.label || value.action || value.description || '';
			var description = value.description && value.description !== title ? ' <span>' + escapeHtml(value.description) + '</span>' : '';
			return '<li><strong>' + escapeHtml(title) + '</strong>' + description + '</li>';
		}).join('') + '</ul>';
	}

	function isRelatedReportLocked(report, resolved) {
		if (hasProAccess) {
			return false;
		}

		var type = normalizeReportType(report);
		var reason = String((resolved && resolved.disabledReason) || '').toLowerCase();
		if (isProOnlyReportType(type) || reason.indexOf('pro') !== -1 || reason.indexOf('upgrade') !== -1 || reason.indexOf('locked') !== -1) {
			return true;
		}

		return !!(report && typeof report === 'object' && (report.pro_only === true || report.requires_pro === true || report.is_pro === true || report.locked === true || report.is_locked === true || report.is_locked_preview === true));
	}

	function isRelatedReportNotRelated(report) {
		return !!(report && typeof report === 'object' && (report.related === false || report.is_related === false || report.relevant === false || report.is_relevant === false));
	}

	function getRelatedReportDestinationLabel(report, meta) {
		var type = normalizeReportType(report);
		var destinations = {
			heatmap: i18n.heatmap || 'Heatmap',
			analytics: i18n.analytics || 'Analytics',
			device_report: i18n.analytics || 'Analytics',
			session_recordings: i18n.sessionRecordings || 'Session recordings',
			user_journey: i18n.userJourneys || 'User journeys',
			form_analytics: i18n.formAnalytics || 'Form analytics',
			error_tracking: i18n.errorTracking || 'Error tracking',
			errors_friction: i18n.frictionReport || 'Friction report',
			funnel: i18n.funnel || 'Funnel',
			ab_testing: i18n.abTesting || 'A/B testing',
			pro_locked: i18n.proLocked || 'Pro preview'
		};

		return destinations[type] || (meta && meta.destination) || (meta && meta.label) || (i18n.relatedReport || 'Related report');
	}

	function getRelatedReportScopeLabel(report, insight) {
		var context = getRelatedReportContext(report, insight || {});
		if (context.formId) {
			return i18n.form || 'Form';
		}
		if (context.funnelId) {
			return i18n.funnel || 'Funnel';
		}
		if (context.ctaSelector) {
			return i18n.cta || 'CTA';
		}
		if (context.pageId || context.pageUrl) {
			return i18n.page || 'Page';
		}
		if (context.device || context.source || context.campaign) {
			return i18n.segment || 'Segment';
		}
		if (context.errorType) {
			return i18n.errorType || 'Error';
		}
		return i18n.smartInsight || 'Insight';
	}

	function getRelatedReportState(item) {
		var reason = item.resolved.disabledReason || (i18n.relatedUnavailable || 'No deep link available');

		if (item.resolved.url) {
			if (item.isPrimary) {
				return {
					className: 'is-current',
					badgeClass: 'is-current',
					label: i18n.current || 'Current',
					description: (i18n.current || 'Current') + '. ' + (i18n.openReport || 'Open report')
				};
			}

			return {
				className: 'is-enabled',
				badgeClass: 'is-open',
				label: i18n.openReport || 'Open report',
				description: item.meta.description || (i18n.openReport || 'Open report')
			};
		}

		if (isRelatedReportLocked(item.report, item.resolved)) {
			return {
				className: 'is-disabled is-locked',
				badgeClass: 'is-locked',
				label: i18n.proLocked || 'Pro locked',
				description: (i18n.proLocked || 'Pro locked') + ': ' + (reason || (i18n.proRelatedLocked || 'Requires Pro data.'))
			};
		}

		if (isRelatedReportNotRelated(item.report)) {
			return {
				className: 'is-disabled is-not-related',
				badgeClass: 'is-not-related',
				label: i18n.notRelated || 'Not related',
				description: (i18n.notRelated || 'Not related') + ': ' + (reason || (i18n.relatedUnavailable || 'Not available for this insight.'))
			};
		}

		return {
			className: 'is-disabled is-unavailable',
			badgeClass: 'is-unavailable',
			label: i18n.unavailable || 'Unavailable',
			description: (i18n.unavailable || 'Unavailable') + ': ' + (reason || (i18n.relatedUnavailable || 'No deep link available'))
		};
	}

	// The A/B destination is a test-creation wizard, not a report: promising
	// "Open report" there sets the expectation of data that a builder cannot
	// have. Name the action it actually performs instead.
	function getRelatedReportCtaLabel(item) {
		var reportType = String((item && item.report && (item.report.type || item.report.report_type)) || '').toLowerCase();
		if (reportType === 'ab_testing') {
			return i18n.createAbTest || 'Create A/B test';
		}

		return i18n.openReport || 'Open report';
	}

	// `options.compact` is used for the reports that do not apply to this insight:
	// they stay reachable behind one collapsed line, but without the explanatory
	// sentence and the route/scope meta that made the sidebar unreadable in v1.
	function renderRelatedReportCardContent(item, state, insight, options) {
		options = options || {};
		var destination = getRelatedReportDestinationLabel(item.report, item.meta);
		var scope = getRelatedReportScopeLabel(item.report, insight);
		var action = item.meta.label && item.meta.label !== destination ? item.meta.label : state.label;
		var routeLabel = i18n.reportDestination || 'Report destination';

		if (options.compact) {
			return '<i data-lucide="' + escapeHtml(item.meta.icon) + '"></i>' +
				'<span class="ob-smart-insights-report-card-body">' +
					'<span class="ob-smart-insights-report-card-topline">' +
						'<strong>' + escapeHtml(destination) + '</strong>' +
						'<span class="ob-smart-insights-report-state ' + escapeHtml(state.badgeClass) + '">' + escapeHtml(state.label) + '</span>' +
					'</span>' +
				'</span>';
		}

		return '<i data-lucide="' + escapeHtml(item.meta.icon) + '"></i>' +
			'<span class="ob-smart-insights-report-card-body">' +
				'<span class="ob-smart-insights-report-card-topline">' +
					'<strong>' + escapeHtml(destination) + '</strong>' +
					'<span class="ob-smart-insights-report-state ' + escapeHtml(state.badgeClass) + '">' + escapeHtml(state.label) + '</span>' +
				'</span>' +
				'<small class="ob-smart-insights-report-card-copy">' + escapeHtml(action) + '</small>' +
				'<small class="ob-smart-insights-report-card-status">' + escapeHtml(state.description) + '</small>' +
				'<span class="ob-smart-insights-report-card-meta"><span>' + escapeHtml(routeLabel) + '</span><span>' + escapeHtml(scope) + '</span></span>' +
				(item.resolved.url ? '<span class="ob-smart-insights-report-card-action">' + escapeHtml(getRelatedReportCtaLabel(item)) + ' <span aria-hidden="true">&rarr;</span></span>' : '') +
			'</span>';
	}

	function renderRelatedReportCard(item, insight, options) {
		options = options || {};
		var state = getRelatedReportState(item);
		var content = renderRelatedReportCardContent(item, state, insight || {}, options);
		var ariaLabel = getRelatedReportDestinationLabel(item.report, item.meta) + ': ' + item.meta.label + '. ' + state.label +
			(options.compact ? '' : '. ' + state.description);
		if (item.resolved.url) {
			return '<a class="ob-smart-insights-report-card ' + escapeHtml(state.className) + '" href="' + escapeHtml(item.resolved.url) + '" target="_blank" rel="noopener noreferrer"' + (item.isPrimary ? ' aria-current="page"' : '') + ' aria-label="' + escapeHtml(ariaLabel) + '">' + content + '</a>';
		}
		return '<div class="ob-smart-insights-report-card ' + escapeHtml(state.className) + '" role="group" aria-disabled="true" aria-label="' + escapeHtml(ariaLabel) + '">' + content + '</div>';
	}

	function renderRelatedReports(reports, insight, options) {
		if (!Array.isArray(reports) || !reports.length) {
			return '<p class="description">' + escapeHtml(i18n.noRelatedReports || 'No related reports are available for this insight yet.') + '</p>';
		}

		options = options || {};
		var primaryReportUrl = normalizeReportUrlForComparison(options.primaryReportUrl || '');
		var byLabel = {};
		reports.forEach(function(report, index) {
			var resolved = typeof report === 'object' ? resolveRelatedReportLink(report, insight || {}) : { url: '', disabledReason: '' };
			var meta = getContextualReportMeta(report, insight || {});
			var signature = String(meta.label || '').toLowerCase();
			var item = {
				resolved: resolved,
				meta: meta,
				report: report,
				isPrimary: !!(primaryReportUrl && resolved.url && normalizeReportUrlForComparison(resolved.url) === primaryReportUrl),
				index: index
			};
			item.score = scoreRelatedReportCandidate(item, index, insight || {});
			if (!byLabel[signature] || (item.isPrimary && !byLabel[signature].isPrimary) || (!byLabel[signature].resolved.url && resolved.url)) {
				byLabel[signature] = item;
			}
		});

		var sorted = Object.keys(byLabel).map(function(signature) {
			return byLabel[signature];
		}).sort(function(a, b) {
			if (a.isPrimary !== b.isPrimary) {
				return a.isPrimary ? -1 : 1;
			}
			return (b.score - a.score) || (a.index - b.index);
		});

		// Only the reports the viewer can actually open (current / open) earn a
		// full card. Everything that does not apply stays reachable behind one
		// collapsed line instead of six cards explaining why they are irrelevant.
		var applicable = [];
		var other = [];
		sorted.forEach(function(item) {
			if (item.resolved.url) {
				applicable.push(item);
			} else {
				other.push(item);
			}
		});

		if (!applicable.length && !other.length) {
			return '<p class="description">' + escapeHtml(i18n.noRelatedReports || 'No related reports are available for this insight yet.') + '</p>';
		}

		var html = applicable.length
			? '<div class="ob-smart-insights-report-card-list">' + applicable.map(function(item) {
				return renderRelatedReportCard(item, insight);
			}).join('') + '</div>'
			: '<p class="description">' + escapeHtml(i18n.noApplicableReports || 'No report applies directly to this insight.') + '</p>';

		if (other.length) {
			var template = other.length === 1
				? (i18n.relatedOtherReport || '%s other report does not apply to this insight')
				: (i18n.relatedOtherReports || '%s other reports do not apply to this insight');
			html += renderDisclosure(sprintfCount(template, other.length), '<div class="ob-smart-insights-report-card-list is-muted-reports">' + other.map(function(item) {
				return renderRelatedReportCard(item, insight, { compact: true });
			}).join('') + '</div>', { className: 'ob-smart-insights-report-more-toggle' });
		}

		return html;
	}

	function renderSummaryPriority(item) {
		if (!item || item.priority_score === undefined || item.priority_score === '') {
			return '';
		}

		var score = parseInt(item.priority_score, 10) || 0;
		// Rank-based labels no longer map to absolute score bands, so the chip
		// colour follows the label when there is one and only falls back to the
		// legacy score bands for rows stored before rank scoring.
		var priorityClass = item.priority_label
			? getSeverityClass(item.priority_label)
			: (score >= 80 ? 'critical' : (score >= 60 ? 'high' : (score >= 40 ? 'medium' : 'low')));
		var label = item.priority_label || (i18n.priority || 'Priority');
		return '<span class="ob-smart-insights-summary-priority is-' + escapeHtml(priorityClass) + '">' + escapeHtml(label) + '<strong>' + escapeHtml(score + '/100') + '</strong></span>';
	}

	function renderSummaryInsightLink(item) {
		var id = item && parseInt(item.id, 10) ? parseInt(item.id, 10) : 0;
		if (!id) {
			return '';
		}

		return '<button type="button" class="button-link ob-smart-insights-summary-link ob-smart-insights-detail-btn" data-insight-id="' + id + '">' + escapeHtml(i18n.openInsight || 'Open insight') + '</button>';
	}

	function isLowValueSummaryItem(item) {
		var title = getSummaryItemTitle(item);
		var body = item && typeof item === 'object' ? (item.summary || item.body || item.description || '') : '';
		var text = String([title, body].join(' ')).toLowerCase();
		return !text || text.indexOf('no clear') !== -1 || text.indexOf('not enough') !== -1 || text.indexOf('no notable') !== -1;
	}

	function renderSummaryList(items, limit, options) {
		options = options || {};
		if (!Array.isArray(items) || !items.length) {
			return '<p class="description is-muted-summary">' + escapeHtml(i18n.noSummaryItems || 'No notable items for this section yet.') + '</p>';
		}

		var max = limit || 3;
		var candidates = options.hideLowValue ? items.filter(function(item) {
			return !isLowValueSummaryItem(item);
		}) : items;
		if (!candidates.length) {
			return '<p class="description is-muted-summary">' + escapeHtml(options.emptyText || i18n.noClearMover || 'No clear movement detected.') + '</p>';
		}

		var visible = candidates.slice(0, max);
		var more = candidates.length > visible.length ? '<p class="ob-smart-insights-summary-more">+' + escapeHtml(candidates.length - visible.length) + ' ' + escapeHtml(i18n.moreIssues || 'more issues') + '</p>' : '';
		var listClass = options.compact ? ' is-compact' : '';
		return '<div class="ob-smart-insights-summary-list' + listClass + '">' + visible.map(function(item) {
			var label = item.label || item.entity_label || item.signal_name || '';
			var summary = item.summary || item.body || item.description || '';
			var recurrence = item.recurrence_count ? '<span class="ob-smart-insights-summary-chip">' + escapeHtml((i18n.recurring || 'Recurring') + ': ' + item.recurrence_count) + '</span>' : '';
			return '<article class="ob-smart-insights-summary-item">' +
				'<div class="ob-smart-insights-summary-item-main">' +
					'<strong title="' + escapeHtml(label || summary || (i18n.smartInsight || 'Smart Insight')) + '">' + escapeHtml(label || summary || (i18n.smartInsight || 'Smart Insight')) + '</strong>' +
					(summary && summary !== label ? '<p>' + escapeHtml(summary) + '</p>' : '') +
				'</div>' +
				'<div class="ob-smart-insights-summary-item-meta">' +
					renderSummaryPriority(item) +
					recurrence +
					renderSummaryInsightLink(item) +
				'</div>' +
			'</article>';
		}).join('') + more + '</div>';
	}

	function getSummaryItemTitle(item) {
		if (!item || typeof item !== 'object') {
			return '';
		}
		return item.label || item.entity_label || item.signal_name || item.title || '';
	}

	function getSummaryMoverLabel(summary) {
		var decline = summary.biggest_decline || null;
		var improvement = summary.biggest_improvement || null;
		var item = decline || improvement || null;
		if (!item || !getSummaryItemTitle(item) || getSummaryItemTitle(item).toLowerCase().indexOf('no clear') !== -1) {
			return i18n.noClearMover || 'No clear mover';
		}
		var direction = decline ? (i18n.worsening || 'Worsening') : (i18n.improving || 'Improving');
		var title = getSummaryItemTitle(item);
		return direction + ': ' + title;
	}

	// A card that has nothing to say is removed, not filled with "No notable
	// items": the brief must be short enough to be read in full.
	// "Wins this month": fixes the outcome cron measured as real improvements.
	// This is the block a white-label report leads with, so it only ever lists
	// verdicts the backend already confirmed as `improved`.
	function renderWinsList(wins) {
		return '<ul class="ob-smart-insights-wins-list">' + wins.map(function(win) {
			var outcome = win && win.outcome ? win.outcome : null;
			var title = getBriefTitle(win);
			return '<li>' +
				'<span class="ob-smart-insights-brief-title" title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</span>' +
				'<span class="ob-smart-insights-win-change">' + escapeHtml(getOutcomeChangeLabel(outcome)) + '</span>' +
				'<span class="ob-smart-insights-win-date">' + escapeHtml(fillToken(i18n.outcomeFixed || 'Fixed %s', '%s', formatShortDate(outcome && outcome.resolved_at))) + '</span>' +
				renderSummaryInsightLink(win) +
			'</li>';
		}).join('') + '</ul>';
	}

	function renderBriefingSections(summary) {
		var sections = [];

		var wins = (Array.isArray(summary.wins) ? summary.wins : []).filter(function(win) {
			return win && win.outcome && win.outcome.verdict === 'improved';
		});
		if (wins.length) {
			sections.push('<section class="ob-smart-insights-briefing-section is-wins"><h3>' + escapeHtml(i18n.winsThisMonth || 'Wins this month') + '</h3>' + renderWinsList(wins.slice(0, 3)) + '</section>');
		}

		var movement = [];
		if (summary.biggest_decline) {
			movement.push(summary.biggest_decline);
		}
		if (summary.biggest_improvement) {
			movement.push(summary.biggest_improvement);
		}
		movement = movement.filter(function(item) {
			return !isLowValueSummaryItem(item);
		});
		if (movement.length) {
			sections.push('<section class="ob-smart-insights-briefing-section is-watch"><h3>' + escapeHtml(i18n.movement || 'Trend watch') + '</h3>' + renderSummaryList(movement, 1, { compact: true }) + '</section>');
		}

		var recurring = (Array.isArray(summary.recurring_issues) ? summary.recurring_issues : []).filter(function(item) {
			return !isLowValueSummaryItem(item);
		});
		if (recurring.length) {
			sections.push('<section class="ob-smart-insights-briefing-section is-recurring"><h3>' + escapeHtml(i18n.recurringPatterns || 'Recurring patterns') + '</h3>' + renderSummaryList(recurring, 1, { compact: true }) + '</section>');
		}

		return sections;
	}

	// --- Weekly brief: top three issues with a number and a state -----------
	// The state compares the stored loss with the previous run of the same
	// issue group (server side, 10% tolerance); the key is localized here.
	var BRIEF_STATES = { 'new': 1, worse: 1, better: 1, steady: 1, resolved: 1 };

	function getBriefStateKey(item) {
		var key = String((item && item.state) || 'new').toLowerCase();
		return BRIEF_STATES[key] ? key : 'new';
	}

	function getBriefStateLabel(state) {
		if (state === 'worse') {
			return i18n.stateWorse || 'Worse';
		}
		if (state === 'better') {
			return i18n.stateBetter || 'Better';
		}
		if (state === 'resolved') {
			return i18n.stateResolved || 'Resolved';
		}
		if (state === 'steady') {
			return i18n.stateSteady || 'Unchanged';
		}
		return i18n.stateNew || 'New';
	}

	// Money when the site can price a conversion, visitors when it cannot, and
	// never a loss figure for an insight the backend flagged as observation.
	function getBriefAmountLabel(item) {
		var revenue = item && item.revenue && item.revenue.available ? item.revenue : null;
		if (revenue) {
			var amount = parseFloat(revenue.amount);
			if (!isNaN(amount)) {
				return fillToken(i18n.briefAmountAtRisk || '%s at risk', '%s', formatImpactCurrency(amount, revenue.currency));
			}
		}

		var sessions = parseInt(item && item.sessions, 10) || 0;
		if (item && item.observation_only) {
			return fillToken(i18n.briefObservation || 'Observation on %s sessions', '%s', formatNumber(sessions));
		}

		var usersLost = item && item.users_lost !== undefined && item.users_lost !== null ? parseInt(item.users_lost, 10) : NaN;
		if (!isNaN(usersLost) && usersLost > 0) {
			return fillToken(i18n.briefVisitorsAtRisk || '%s visitors at risk', '%s', formatNumber(usersLost));
		}

		return fillToken(i18n.briefSessionsMeasured || 'Measured on %s sessions', '%s', formatNumber(sessions));
	}

	// Three entities can carry three different problems and still share a label
	// ("Mobile"), so the brief names the signal as well when it adds something.
	function getBriefTitle(item) {
		var label = getSummaryItemTitle(item);
		var signalName = item && item.signal_name ? String(item.signal_name).trim() : '';
		if (signalName && label && label !== signalName) {
			return signalName + ' — ' + label;
		}
		return label || signalName || (item && item.summary) || (i18n.smartInsight || 'Smart Insight');
	}

	// Summary items carry the same outcome block as cards do, so a brief line for
	// an already-resolved issue shows the measured before/after too.
	function renderBriefOutcomeChip(item) {
		var outcome = item && item.outcome && item.outcome.metric_key ? item.outcome : null;
		if (!outcome || !outcome.verdict) {
			return '';
		}

		var label = getOutcomeChangeLabel(outcome);
		if (!label) {
			return '';
		}

		return '<span class="ob-smart-insights-outcome-chip ' + escapeHtml(getOutcomeVerdictClass(outcome)) + '" title="' + escapeHtml(label) + '">' + escapeHtml(label) + '</span>';
	}

	function renderBriefList(items) {
		var list = Array.isArray(items) ? items.slice(0, 3) : [];
		if (!list.length) {
			return '';
		}

		return '<ol class="ob-smart-insights-brief-list">' + list.map(function(item) {
			var title = getBriefTitle(item);
			var state = getBriefStateKey(item);
			return '<li>' +
				'<span class="ob-smart-insights-brief-title" title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</span>' +
				'<span class="ob-smart-insights-brief-amount">' + escapeHtml(getBriefAmountLabel(item)) + '</span>' +
				'<span class="ob-smart-insights-brief-state is-' + escapeHtml(state) + '">' + escapeHtml(getBriefStateLabel(state)) + '</span>' +
				renderBriefOutcomeChip(item) +
				renderSummaryInsightLink(item) +
			'</li>';
		}).join('') + '</ol>';
	}

	function getLockedWeeklyPreviewTiles() {
		return [
			{ icon: 'dashicons-update', title: i18n.weeklyRecurringIssues || 'Recurring issues', body: i18n.weeklyRecurringIssuesBody || 'Patterns that keep returning across the week.' },
			{ icon: 'dashicons-chart-line', title: i18n.weeklyBiggestImprovement || 'Biggest improvement', body: i18n.weeklyBiggestImprovementBody || 'The strongest positive movement to learn from.' },
			{ icon: 'dashicons-warning', title: i18n.weeklyBiggestDecline || 'Biggest decline', body: i18n.weeklyBiggestDeclineBody || 'The highest-risk change needing review.' },
			{ icon: 'dashicons-yes-alt', title: i18n.weeklyRecommendedNextAction || 'Recommended next action', body: i18n.weeklyRecommendedNextActionBody || 'One prioritized action for the next optimization step.' }
		];
	}

	function renderLockedWeeklySummary(summary) {
		var preview = summary && summary.locked_preview && typeof summary.locked_preview === 'object' ? summary.locked_preview : {};
		var headline = i18n.weeklyLockedHeadline || 'Unlock your weekly CRO briefing.';
		var body = i18n.weeklyLockedBody || preview.description || (summary && summary.summary_text) || 'Pro summarizes priority patterns and recommended actions across your Smart Insights without exposing protected report data in Free.';
		var tiles = getLockedWeeklyPreviewTiles().map(function(tile) {
			return '<article class="ob-smart-insights-weekly-preview-tile">' +
				'<span class="dashicons ' + escapeHtml(tile.icon) + '" aria-hidden="true"></span>' +
				'<strong>' + escapeHtml(tile.title) + '</strong>' +
				'<p>' + escapeHtml(tile.body) + '</p>' +
			'</article>';
		}).join('');

		return '<div class="ob-smart-insights-weekly-lock-card" aria-label="' + escapeHtml(i18n.weeklyLockedAria || 'Weekly CRO Summary is locked in Free mode') + '">' +
			'<div class="ob-smart-insights-weekly-lock-hero">' +
				'<span class="ob-smart-insights-weekly-lock-icon dashicons dashicons-lock" aria-hidden="true"></span>' +
				'<div>' +
					'<strong>' + escapeHtml(headline) + '</strong>' +
					'<p>' + escapeHtml(body) + '</p>' +
				'</div>' +
			'</div>' +
			'<div class="ob-smart-insights-weekly-preview-grid" aria-label="' + escapeHtml(i18n.weeklyPreviewAria || 'Protected Weekly CRO Summary preview') + '">' + tiles + '</div>' +
			'<div class="ob-smart-insights-weekly-lock-actions">' +
				'<a class="button button-primary ob-smart-insights-weekly-upgrade" href="' + escapeHtml(upgradeUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(i18n.weeklyUpgradeCta || 'Upgrade to unlock summary') + '</a>' +
				'<span class="ob-smart-insights-weekly-lock-note"><span class="dashicons dashicons-shield" aria-hidden="true"></span>' + escapeHtml(i18n.weeklyProtectedNote || 'Pro-only metrics stay protected until Pro is active.') + '</span>' +
			'</div>' +
		'</div>';
	}

	function renderWeeklySummary(summary) {
		if (!summary || typeof summary !== 'object') {
			return '<p class="description">' + escapeHtml(i18n.noSummaryItems || 'No weekly summary is available yet.') + '</p>';
		}

		if (summary.is_locked_preview) {
			return renderLockedWeeklySummary(summary);
		}

		var counts = summary.counts || {};
		var next = summary.recommended_next_action || {};
		var highPriorityCount = counts.high_priority || 0;
		var headline = summary.headline || (i18n.weeklyHeadline ? String(i18n.weeklyHeadline).replace('%d', highPriorityCount) : (highPriorityCount + ' high-priority issues need review this week'));
		var moverLabel = getSummaryMoverLabel(summary);
		var scopeNote = summary.scope_note || i18n.summaryScopeNote || 'Counts use deduplicated active insight groups for the selected range.';
		// The lead is the brief: the three issues that cost the most, each with
		// its number and what changed since the previous run. The counting
		// headline is only the fallback when nothing could be listed.
		var briefList = renderBriefList(summary.top_critical_insights);
		var sections = renderBriefingSections(summary);
		return '<div class="ob-smart-insights-summary-report is-briefing">' +
			'<div class="ob-smart-insights-briefing-lead"><span>' + escapeHtml(i18n.analystBriefing || 'Analyst briefing') + '</span>' + (briefList || ('<strong>' + escapeHtml(headline) + '</strong>')) + '</div>' +
			'<div class="ob-smart-insights-summary-metrics">' +
				'<span><small>' + escapeHtml(i18n.activeInsights || 'Active') + '</small><strong>' + escapeHtml(counts.active || 0) + '</strong></span>' +
				'<span><small>' + escapeHtml(i18n.highPriority || 'High priority') + '</small><strong>' + escapeHtml(counts.high_priority || 0) + '</strong></span>' +
				'<span><small>' + escapeHtml(i18n.recurring || 'Recurring') + '</small><strong>' + escapeHtml(counts.recurring || 0) + '</strong></span>' +
				'<span class="is-mover"><small>' + escapeHtml(i18n.biggestMover || 'Biggest mover') + '</small><strong title="' + escapeHtml(moverLabel) + '">' + escapeHtml(moverLabel) + '</strong></span>' +
			'</div>' +
			'<p class="description ob-smart-insights-summary-scope">' + escapeHtml(scopeNote) + '</p>' +
			'<div class="ob-smart-insights-recommendation ob-smart-insights-summary-next-action"><span>' + escapeHtml(i18n.recommendedAction || 'Recommended action') + '</span><strong>' + escapeHtml(next.action || next.summary || next.title || (i18n.defaultRecommendedAction || 'Review the highest-priority issue and open the supporting report.')) + '</strong>' + renderSummaryInsightLink(next) + '</div>' +
			(sections.length ? '<div class="ob-smart-insights-briefing-grid has-' + sections.length + '-sections">' + sections.join('') + '</div>' : '') +
		'</div>';
	}

	function loadWeeklySummary(section) {
		var summaryWidget = section.querySelector('.ob-smart-insights-weekly-summary');
		if (!summaryWidget) {
			return;
		}

		var content = summaryWidget.querySelector('.widget-content');
		if (content) {
			content.innerHTML = '<div class="ob-smart-insights-loading"><span class="ob-smart-insights-spinner" aria-hidden="true"></span><span>' + escapeHtml(i18n.loadingSummary || 'Loading Weekly CRO Summary...') + '</span></div>';
		}

		if (!hasProAccess) {
			if (content) {
				content.innerHTML = renderLockedWeeklySummary({
					is_locked_preview: true,
					locked_preview: {
						description: i18n.weeklyLockedBody || 'Pro summarizes priority patterns and recommended actions across your Smart Insights without exposing protected report data in Free.'
					}
				});
			}
			return;
		}

		var contextData = getContextData(section);
		if (!hasCompleteCustomRange(contextData)) {
			if (content) {
				content.innerHTML = '<p class="description">' + escapeHtml(i18n.selectCustomRange || 'Select a start and end date to use a custom range.') + '</p>';
			}
			return;
		}

		postAjax('optibehavior_smart_insights_summary', {
			context: contextData.context,
			period: contextData.period,
			start_date: contextData.startDate,
			end_date: contextData.endDate,
			exclude_spam: contextData.excludeSpam
		})
			.then(function(data) {
				if (content) {
					content.innerHTML = renderWeeklySummary(data.summary || {});
					refreshIcons();
				}
			})
			.catch(function(error) {
				if (content) {
					content.innerHTML = '<p class="description">' + escapeHtml(error.message || (i18n.error || 'Unable to load Smart Insights.')) + '</p>';
				}
			});
	}



	function getDetailDateRange(insight) {
		var from = insight && insight.date_from ? String(insight.date_from).trim() : '';
		var to = insight && insight.date_to ? String(insight.date_to).trim() : '';
		if (from && to) {
			return from + ' ' + (i18n.dateRangeTo || 'to') + ' ' + to;
		}
		return from || to || '';
	}

	function getDetailRecurrenceLabel(insight) {
		var count = parseInt((insight && (insight.recurrence_count || (insight.detection && insight.detection.recurrence_count))) || 0, 10) || 0;
		if (count <= 1) {
			return '';
		}
		return (i18n.recurring || 'Recurring') + ': ' + count;
	}

	function renderDetailHeaderChips(insight, priorityLabel, priorityClass) {
		var categoryLabel = getCategoryLabel(insight.category);
		var entityTypeLabel = getEntityTypeLabel(insight);
		var recurrenceLabel = getDetailRecurrenceLabel(insight);
		var chips = [
			'<span class="ob-smart-insights-badge ob-smart-insights-detail-chip is-priority is-' + escapeHtml(priorityClass) + '">' + escapeHtml(priorityLabel) + '</span>'
		];

		if (categoryLabel) {
			chips.push('<span class="ob-smart-insights-detail-chip is-category">' + escapeHtml(categoryLabel) + '</span>');
		}
		if (entityTypeLabel) {
			chips.push('<span class="ob-smart-insights-detail-chip is-entity">' + escapeHtml(entityTypeLabel) + '</span>');
		}
		if (recurrenceLabel) {
			chips.push('<span class="ob-smart-insights-detail-chip ob-smart-insights-recurrence-chip is-recurring" title="' + escapeHtml(i18n.recurrenceHistory || 'Older overlapping detections are grouped into this card.') + '">' + escapeHtml(recurrenceLabel) + '</span>');
		} else if (insight.status) {
			chips.push('<span class="ob-smart-insights-detail-chip ob-smart-insights-status-chip is-' + escapeHtml(getSeverityClass(insight.status)) + '">' + escapeHtml(getStatusLabel(insight.status)) + '</span>');
		}
		if (insight.is_locked_preview) {
			chips.push('<span class="ob-smart-insights-badge ob-smart-insights-detail-chip is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>');
		}

		// The signal helper rides in the chip row rather than in the <h2>: that
		// heading is a -webkit-line-clamp box, and turning it into a flex row to
		// seat the icon would drop the two-line clamp on long signal names.
		chips.push(renderSignalTooltip(insight, { position: 'bottom' }));

		return '<div class="ob-smart-insights-card-top ob-smart-insights-detail-chip-row">' + chips.join('') + '</div>';
	}

	function renderDetailPriorityMeter(insight, priorityLabel) {
		var score = getSafePriorityScore(insight);
		var priorityClass = getSeverityClass(priorityLabel);
		var activeSegments = score > 0 ? Math.max(1, Math.ceil(score / 20)) : 0;
		var segments = [];
		for (var i = 1; i <= 5; i++) {
			segments.push('<span class="ob-smart-insights-priority-segment' + (i <= activeSegments ? ' is-active' : '') + '" aria-hidden="true"></span>');
		}
		return '<div class="ob-smart-insights-priority-meter ob-smart-insights-detail-priority is-' + escapeHtml(priorityClass) + '" aria-label="' + escapeHtml((i18n.priority || 'Priority') + ' ' + score + '/100') + '">' +
			'<span class="ob-smart-insights-priority-label">' + escapeHtml(i18n.priority || 'Priority') + '</span>' +
			'<span class="ob-smart-insights-priority-segments">' + segments.join('') + '</span>' +
			'<span class="ob-smart-insights-score ob-smart-insights-priority-value"><strong>' + escapeHtml(score) + '/100</strong></span>' +
		'</div>';
	}

	function renderDetailSubtitle(insight, dateRange) {
		var context = getEntityContext(insight);
		var parts = [];
		if (dateRange) {
			parts.push('<span class="ob-smart-insights-detail-date-range">' + escapeHtml(dateRange) + '</span>');
		}
		if (context.label) {
			parts.push('<span class="ob-smart-insights-detail-entity-summary" title="' + escapeHtml(context.identifier || context.label) + '">' + escapeHtml(context.label) + '</span>');
		}
		return parts.length ? '<p class="ob-smart-insights-page-label ob-smart-insights-detail-subtitle">' + parts.join('<span class="ob-smart-insights-detail-subtitle-separator" aria-hidden="true">|</span>') + '</p>' : '';
	}

	function renderDetailContextFacts(insight, dateRange) {
		var categoryLabel = getCategoryLabel(insight.category);
		var recurrenceLabel = getDetailRecurrenceLabel(insight);
		var rows = [];

		function addFact(label, value, className) {
			if (value === undefined || value === null || value === '') {
				return;
			}
			rows.push('<div class="ob-smart-insights-context-fact' + (className ? ' ' + className : '') + '"><dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(value) + '</dd></div>');
		}

		function addFactHtml(label, valueHtml, className) {
			if (!valueHtml) {
				return;
			}
			rows.push('<div class="ob-smart-insights-context-fact' + (className ? ' ' + className : '') + '"><dt>' + escapeHtml(label) + '</dt><dd>' + valueHtml + '</dd></div>');
		}

		addFact(i18n.confidence || 'Confidence', getConfidenceLabel(insight), 'is-confidence');
		addFact(i18n.status || 'Status', getStatusLabel(insight.status), 'is-status');
		addFact(i18n.category || 'Category', categoryLabel, 'is-category');
		addFactHtml(i18n.entity || 'Entity', renderEntityReference(insight, { includeReportLinks: false }), 'is-entity');
		addFact(i18n.recurrence || 'Recurrence', recurrenceLabel, 'is-recurrence');
		addFact(i18n.dateRange || 'Date range', dateRange, 'is-date-range');

		return rows.length ? '<dl class="ob-smart-insights-context-facts">' + rows.join('') + '</dl>' : '<p class="description">-</p>';
	}

	function renderDetailFooter(insight, dateRange, primaryReport) {
		var detectedTime = renderDetectedTime(insight);
		var id = parseInt(insight.id, 10) || 0;
		var meta = [];
		if (detectedTime) {
			meta.push('<span><strong>' + escapeHtml(i18n.lastSeen || 'Last seen') + '</strong> ' + detectedTime + '</span>');
		}
		if (dateRange) {
			meta.push('<span><strong>' + escapeHtml(i18n.analysisWindow || 'Analysis window') + '</strong> ' + escapeHtml(dateRange) + '</span>');
		}
		if (!meta.length) {
			meta.push('<span>' + escapeHtml(i18n.smartInsight || 'Smart Insight') + '</span>');
		}

		var reportCta = primaryReport && primaryReport.resolved && primaryReport.resolved.url
			? '<a class="button button-primary ob-smart-insights-report-cta" href="' + escapeHtml(primaryReport.resolved.url) + '" target="_blank" rel="noopener noreferrer"><i data-lucide="' + escapeHtml(primaryReport.meta.icon) + '"></i>' + escapeHtml(primaryReport.meta.label) + '</a>'
			: '';
		var statusControl = id ? '<label class="ob-smart-insights-status-control ob-smart-insights-detail-status-control"><span>' + escapeHtml(i18n.updateStatus || 'Update status') + '</span><select class="ob-smart-insights-status-select" data-insight-id="' + id + '">' +
			'<option value="">' + escapeHtml(i18n.chooseStatus || 'Choose status') + '</option>' +
			'<option value="viewed">' + escapeHtml(i18n.markReviewed || 'Mark as reviewed') + '</option>' +
			'<option value="in_progress">' + escapeHtml(i18n.markInProgress || 'In progress') + '</option>' +
			'<option value="resolved">' + escapeHtml(i18n.resolve || 'Resolve') + '</option>' +
			'<option value="ignored">' + escapeHtml(i18n.ignore || 'Ignore') + '</option>' +
		'</select></label>' : '';

		return '<footer class="ob-smart-insights-detail-footer"><div class="ob-smart-insights-detail-footer-meta">' + meta.join('<span class="ob-smart-insights-detail-footer-separator" aria-hidden="true">|</span>') + '</div><div class="ob-smart-insights-detail-footer-actions">' + statusControl + reportCta + '</div></footer>';
	}

	// ---------------------------------------------------------------------
	// Typed evidence references (Milestone 5)
	//
	// The insight row carries a bundle of typed refs, each with the destination
	// report descriptor that the existing related-report resolver understands.
	// Rendering therefore reuses resolveRelatedReportLink() so an evidence link
	// carries exactly the same page/date/segment/spam context as any other deep
	// link, plus the ref-specific args (recording, session, field, step).
	// ---------------------------------------------------------------------

	var EVIDENCE_TYPE_ORDER = ['session', 'recording', 'error_group', 'form', 'field', 'heatmap_zone', 'heatmap', 'funnel', 'journey', 'segment', 'comparison'];

	function getEvidenceBundle(insight) {
		var bundle = insight && insight.evidence_refs && typeof insight.evidence_refs === 'object' ? insight.evidence_refs : null;
		if (!bundle || !Array.isArray(bundle.refs) || !bundle.refs.length) {
			return null;
		}
		return bundle;
	}

	function appendUrlArgs(url, args) {
		if (!url || !args || typeof args !== 'object') {
			return url;
		}

		var pairs = [];
		Object.keys(args).forEach(function(key) {
			var value = args[key];
			if (value === undefined || value === null || value === '') {
				return;
			}
			pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
		});

		if (!pairs.length) {
			return url;
		}

		return url + (url.indexOf('?') === -1 ? '?' : '&') + pairs.join('&');
	}

	function resolveEvidenceRefLink(ref, insight, scope, reportKey) {
		var report = ref && ref[reportKey || 'report'];
		if (!report || typeof report !== 'object') {
			return { url: '', disabledReason: i18n.relatedUnavailable || 'No deep link is available for this report.' };
		}

		var scoped = applySegmentScopeToReports([report], scope);
		var resolved = resolveRelatedReportLink(scoped[0], insight);
		if (resolved && resolved.url) {
			resolved.url = appendUrlArgs(resolved.url, ref.url_args);
		}
		return resolved;
	}

	// An evidence action that resolves to the exact same URL as the modal's
	// primary report CTA (footer button + "Current" related-report card) adds a
	// third identical button with no extra value — form insights were showing
	// "Inspect field drop-off", "Open report" and "View form analytics" all
	// pointing at the same screen. Suppress the duplicate; keep any evidence
	// action whose destination or scope differs (recordings, journeys,
	// previous-period comparisons, differently-dated heatmaps).
	function isEvidenceActionDuplicateOfPrimary(url, insight) {
		if (!url) {
			return false;
		}
		var primary = getBestRelatedReport(insight);
		var primaryUrl = primary && primary.resolved ? primary.resolved.url : '';
		if (!primaryUrl) {
			return false;
		}
		return normalizeReportUrlForComparison(url) === normalizeReportUrlForComparison(primaryUrl);
	}

	function renderEvidenceRefAction(ref, insight, scope, reportKey, label) {
		var resolved = resolveEvidenceRefLink(ref, insight, scope, reportKey);
		if (resolved && resolved.url) {
			if (isEvidenceActionDuplicateOfPrimary(resolved.url, insight)) {
				return '';
			}
			// A destination whose scope is wider than the insight (the analytics
			// dashboard reports site-wide totals) discloses it on the control
			// itself, so the button never implies a page-scoped result set.
			var scopeNote = ref && ref.scope_note ? ' title="' + escapeHtml(ref.scope_note) + '"' : '';
			return '<a class="button button-secondary ob-smart-insights-evidence-ref-action" href="' + escapeHtml(resolved.url) + '"' + scopeNote + ' target="_blank" rel="noopener noreferrer" data-evidence-action="' + escapeHtml(ref.action || '') + '">' + escapeHtml(label) + '</a>';
		}

		var reason = (resolved && resolved.disabledReason) || (i18n.relatedUnavailable || 'This report is not available for this insight yet.');
		return '<span class="ob-smart-insights-evidence-ref-action is-disabled" title="' + escapeHtml(reason) + '">' + escapeHtml(label) + '</span>';
	}

	function renderEvidenceRef(ref, insight, scope) {
		if (!ref || typeof ref !== 'object') {
			return '';
		}

		var label = ref.label || ref.id || ref.type_label || '';
		var meta = [];
		if (ref.share_pct !== undefined && ref.share_pct !== null && ref.share_pct !== '') {
			meta.push('<span class="ob-smart-insights-evidence-ref-share">' + escapeHtml(formatPercent(parseFloat(ref.share_pct) || 0)) + '</span>');
		}
		if (ref.count) {
			meta.push('<span class="ob-smart-insights-evidence-ref-count">' + escapeHtml(formatNumber(ref.count) + ' ' + (i18n.segmentSessions || 'sessions')) + '</span>');
		}

		var actions = renderEvidenceRefAction(ref, insight, scope, 'report', ref.action_label || (i18n.openReport || 'Open report'));
		if (ref.compare_report) {
			actions = renderEvidenceRefAction(ref, insight, scope, 'compare_report', i18n.evidencePreviousPeriod || 'Previous period') + actions;
		}

		return '<li class="ob-smart-insights-evidence-ref" data-evidence-type="' + escapeHtml(ref.type || '') + '">' +
			'<span class="ob-smart-insights-evidence-ref-label" title="' + escapeHtml(label) + '">' + escapeHtml(label) + '</span>' +
			(meta.length ? '<span class="ob-smart-insights-evidence-ref-meta">' + meta.join('') + '</span>' : '') +
			(actions ? '<span class="ob-smart-insights-evidence-ref-actions">' + actions + '</span>' : '') +
		'</li>';
	}

	function renderEvidenceRefsBody(insight, scope) {
		var bundle = getEvidenceBundle(insight);
		if (!bundle) {
			return '';
		}

		var groups = {};
		bundle.refs.forEach(function(ref) {
			if (!ref || !ref.type) {
				return;
			}
			if (!groups[ref.type]) {
				groups[ref.type] = { label: ref.type_label || ref.type, refs: [] };
			}
			groups[ref.type].refs.push(ref);
		});

		var types = Object.keys(groups).sort(function(left, right) {
			var leftIndex = EVIDENCE_TYPE_ORDER.indexOf(left);
			var rightIndex = EVIDENCE_TYPE_ORDER.indexOf(right);
			return (leftIndex === -1 ? 99 : leftIndex) - (rightIndex === -1 ? 99 : rightIndex);
		});

		var html = types.map(function(type) {
			var group = groups[type];
			return '<div class="ob-smart-insights-evidence-ref-group" data-evidence-group="' + escapeHtml(type) + '">' +
				'<h4>' + escapeHtml(group.label) + '<span class="ob-smart-insights-evidence-ref-group-count">' + escapeHtml(formatNumber(group.refs.length)) + '</span></h4>' +
				'<ul class="ob-smart-insights-evidence-ref-list">' + group.refs.map(function(ref) {
					return renderEvidenceRef(ref, insight, scope);
				}).join('') + '</ul>' +
			'</div>';
		}).join('');

		if (!html) {
			return '';
		}

		// No explanatory placeholder here: the refs themselves are the proof, and
		// the section renders nothing at all when there is none.
		return '<div class="ob-smart-insights-evidence-refs">' + html + '</div>';
	}

	function renderEvidenceRefsLocked(insight) {
		var summary = insight && insight.evidence_summary && typeof insight.evidence_summary === 'object' ? insight.evidence_summary : null;
		if (!summary || !summary.total) {
			return '';
		}

		var template = i18n.evidenceRefsLockedCount || '%s evidence references collected for this insight';
		return '<div class="ob-smart-insights-upgrade-preview">' +
			'<span class="ob-smart-insights-badge is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>' +
			'<strong>' + escapeHtml(template.replace('%s', formatNumber(summary.total))) + '</strong>' +
			'<p>' + escapeHtml(summary.locked_hint || i18n.proEvidenceLocked || 'Detailed evidence is available in Pro.') + '</p>' +
			'<a class="button button-secondary" href="' + escapeHtml(upgradeUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(i18n.segmentsUpgradeCta || 'Upgrade to Pro') + '</a>' +
		'</div>';
	}

	function renderEvidenceRefsSection(insight, scope) {
		var body = renderEvidenceRefsBody(insight, scope) || renderEvidenceRefsLocked(insight);
		if (!body) {
			return '';
		}

		return '<section class="ob-smart-insights-detail-section is-evidence-refs" data-ob-si-evidence-refs>' +
			renderSectionHeading(i18n.evidenceRefsTitle || 'Evidence and proof', 'evidence_refs') +
			body +
		'</section>';
	}

	// Detail counterpart of the story card: the absolute impact plus the ranked
	// causes that were actually measured for this scope. Rendered only when the
	// insight carries one of those blocks, so legacy rows are untouched.
	function renderDetailStorySection(insight) {
		var impactHtml = renderImpactLine(insight);
		var causeHtml = renderCauseChips(insight);
		if (!impactHtml && !causeHtml) {
			return '';
		}

		var impact = getImpact(insight);
		var facts = [];
		if (impact && impact.population) {
			facts.push('<li><span>' + escapeHtml(impact.population_label || i18n.sessions || 'Sessions') + '</span><strong>' + escapeHtml(formatNumber(impact.population)) + '</strong></li>');
		}
		if (impact && !isObservationOnly(insight) && impact.loss_rate !== undefined && impact.loss_rate !== null && impact.loss_rate !== '') {
			facts.push('<li><span>' + escapeHtml(i18n.impactBasis || 'Measured on') + '</span><strong>' + escapeHtml(formatPercent((parseFloat(impact.loss_rate) || 0) * 100)) + '</strong></li>');
		}

		return '<section class="ob-smart-insights-detail-section is-impact">' +
			renderSectionHeading(i18n.businessImpact || 'Business impact', 'impact') +
			impactHtml +
			(facts.length ? '<ul class="ob-smart-insights-impact-facts">' + facts.join('') + '</ul>' : '') +
			(causeHtml ? '<h4 class="ob-smart-insights-impact-causes-title">' + escapeHtml(i18n.measuredCauses || 'Measured causes') + '</h4>' + causeHtml : '') +
		'</section>';
	}

	// --- Hypothesis and experiment ------------------------------------------
	// The story's "what next / how to verify" half. Both blocks are produced by
	// Pro; Free receives a locked shape from the capabilities layer, so the same
	// renderer covers both tiers and legacy rows render nothing at all.

	function getHypothesis(insight) {
		return (insight && typeof insight.hypothesis === 'object' && insight.hypothesis) ? insight.hypothesis : null;
	}

	function getExperiment(insight) {
		return (insight && typeof insight.experiment === 'object' && insight.experiment) ? insight.experiment : null;
	}

	function buildInsightAbBuilderUrl(insight) {
		var id = (insight && parseInt(insight.id, 10)) ? parseInt(insight.id, 10) : 0;
		if (!id) {
			return '';
		}

		return buildAdminReportUrl('opti-behavior-ab-testing', {
			view: 'builder',
			origin_insight_id: id
		});
	}

	// The server caps duration_days at 56 and flags duration_capped. Recompute
	// the honest estimate here so the modal never prints a capped value as if it
	// were exact, and so a test nobody could ever finish is called out as such.
	function getHypothesisFeasibility(hypothesis) {
		var sample = (hypothesis && hypothesis.sample_size && typeof hypothesis.sample_size === 'object') ? hypothesis.sample_size : null;
		var perVariant = sample ? (parseInt(sample.per_variant, 10) || 0) : 0;
		var daily = sample ? (parseFloat(sample.daily_sessions) || 0) : 0;
		var realDays = (perVariant && daily > 0) ? Math.ceil((perVariant * 2) / daily) : 0;

		return {
			sample: sample,
			capped: !!(sample && sample.duration_capped),
			dailySessions: daily,
			realDays: realDays,
			// The server could not resolve a baseline rate for the goal metric, so
			// there is no honest per-variant number to print at all.
			noBaseline: !!(sample && sample.available === false && sample.reason === 'baseline_unavailable'),
			notFeasible: realDays > 90
		};
	}

	function renderHypothesisFacts(hypothesis) {
		var facts = [];

		if (hypothesis.metric_label) {
			facts.push('<li><span>' + escapeHtml(i18n.hypothesisMetric || 'Goal metric') + '</span><strong>' + escapeHtml(hypothesis.metric_label) + '</strong></li>');
		}
		if (hypothesis.segment_label) {
			facts.push('<li><span>' + escapeHtml(i18n.hypothesisSegment || 'Suggested audience') + '</span><strong>' + escapeHtml(hypothesis.segment_label) + '</strong></li>');
		}
		if (hypothesis.expected_range_label) {
			facts.push('<li><span>' + escapeHtml(i18n.hypothesisTypicalRange || 'Typical industry range') + '</span><strong>' + escapeHtml(hypothesis.expected_range_label) + '</strong></li>');
		}

		var feasibility = getHypothesisFeasibility(hypothesis);
		var sample = feasibility.sample;

		if (feasibility.noBaseline) {
			// No baseline means no sample size and no duration: printing either
			// would be inventing a number, so the whole block becomes advice.
			facts.push('<li class="is-warning"><span>' + escapeHtml(i18n.hypothesisSampleSize || 'Sessions needed per variant') + '</span><strong>' +
				escapeHtml(i18n.hypothesisNoBaseline || 'No measurable baseline for this goal metric yet — ship the change and compare before/after.') +
				'</strong></li>');
		} else if (feasibility.notFeasible) {
			// Both sample facts are replaced: the numbers are technically correct
			// but practically meaningless at this traffic level.
			facts.push('<li class="is-warning"><span>' + escapeHtml(i18n.hypothesisSampleSize || 'Sessions needed per variant') + '</span><strong>' +
				escapeHtml((i18n.hypothesisNotFeasible || 'An A/B test is not feasible at current traffic (~%s sessions/day). Ship the change and compare before/after instead.').replace('%s', formatNumber(Math.round(feasibility.dailySessions)))) +
				'</strong></li>');
		} else {
			if (sample && sample.per_variant) {
				facts.push('<li><span>' + escapeHtml(i18n.hypothesisSampleSize || 'Sessions needed per variant') + '</span><strong>' + escapeHtml(formatNumber(sample.per_variant)) + '</strong></li>');
			}
			if (feasibility.realDays && feasibility.realDays <= 90) {
				// The server caps duration_days at 56, so "More than 56 days" was
				// printed for tests that would really take, say, 82. Print the
				// honest recomputed number whenever it is still a runnable test.
				facts.push('<li><span>' + escapeHtml(i18n.hypothesisDuration || 'Estimated duration') + '</span><strong>' + escapeHtml((i18n.hypothesisDurationReal || '~%s days at current traffic').replace('%s', formatNumber(feasibility.realDays))) + '</strong></li>');
			} else if (sample && sample.duration_days) {
				if (feasibility.capped) {
					facts.push('<li class="is-warning"><span>' + escapeHtml(i18n.hypothesisDuration || 'Estimated duration') + '</span><strong>' + escapeHtml((i18n.hypothesisDurationCapped || 'More than %s days at current traffic').replace('%s', formatNumber(sample.duration_days))) + '</strong></li>');
				} else {
					facts.push('<li><span>' + escapeHtml(i18n.hypothesisDuration || 'Estimated duration') + '</span><strong>' + escapeHtml((i18n.hypothesisDurationDays || '%s days').replace('%s', formatNumber(sample.duration_days))) + '</strong></li>');
				}
			}
		}

		return facts.length ? '<ul class="ob-smart-insights-hypothesis-facts">' + facts.join('') + '</ul>' : '';
	}

	function renderExperimentLink(insight) {
		var experiment = getExperiment(insight);
		var testId = (experiment && parseInt(experiment.test_id, 10)) ? parseInt(experiment.test_id, 10) : 0;
		if (!testId) {
			return '';
		}

		var url = buildAdminReportUrl('opti-behavior-ab-testing', { view: 'results', test_id: testId });
		var name = String(experiment.test_name || '').trim();
		var statusLabel = String(experiment.status_label || '').trim();

		return '<div class="ob-smart-insights-experiment-link" data-test-id="' + escapeHtml(testId) + '">' +
			'<span class="ob-smart-insights-experiment-link-label">' + escapeHtml(i18n.hypothesisLinkedTest || 'Linked A/B test') + '</span>' +
			'<a class="button button-secondary" href="' + escapeHtml(url) + '">' + escapeHtml(name || ((i18n.hypothesisTestNumber || 'Test #%s').replace('%s', testId))) + '</a>' +
			(statusLabel ? '<span class="ob-smart-insights-experiment-status">' + escapeHtml(statusLabel) + '</span>' : '') +
		'</div>';
	}

	function renderHypothesisLocked(hypothesis) {
		var lines = [];
		if (hypothesis.metric_label) {
			lines.push((i18n.hypothesisMetric || 'Goal metric') + ': ' + hypothesis.metric_label);
		}

		return '<div class="ob-smart-insights-upgrade-preview" data-ob-si-hypothesis-locked>' +
			'<span class="ob-smart-insights-badge is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>' +
			'<strong>' + escapeHtml(i18n.hypothesisLockedTitle || 'A testable hypothesis is ready for this insight') + '</strong>' +
			(lines.length ? '<p class="ob-smart-insights-hypothesis-locked-meta">' + escapeHtml(lines.join(' — ')) + '</p>' : '') +
			'<p>' + escapeHtml(hypothesis.locked_hint || i18n.hypothesisLockedHint || 'Upgrade to Pro to see the suggested hypothesis, the typical industry range, and the sample size this test would need.') + '</p>' +
			'<a class="button button-secondary" href="' + escapeHtml(upgradeUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(i18n.segmentsUpgradeCta || 'Upgrade to Pro') + '</a>' +
		'</div>';
	}

	function renderHypothesisSection(insight) {
		var hypothesis = getHypothesis(insight);
		if (!hypothesis) {
			return '';
		}

		var body;
		if (hypothesis.locked) {
			body = renderHypothesisLocked(hypothesis);
		} else {
			var statement = String(hypothesis.statement || '').trim();
			if (!statement) {
				return '';
			}

			// No "create test" CTA when the test could never finish at this traffic.
			var builderUrl = getHypothesisFeasibility(hypothesis).notFeasible ? '' : buildInsightAbBuilderUrl(insight);
			var experimentHtml = renderExperimentLink(insight);

			body = '<p class="ob-smart-insights-hypothesis-statement">' + escapeHtml(statement) + '</p>' +
				renderHypothesisFacts(hypothesis) +
				'<p class="description ob-smart-insights-hypothesis-disclaimer">' + escapeHtml(i18n.hypothesisDisclaimer || 'Expected ranges are typical published results for this kind of change, not a prediction for your site.') + '</p>' +
				(experimentHtml ? experimentHtml : (builderUrl ? '<a class="button button-primary ob-smart-insights-create-ab-test" href="' + escapeHtml(builderUrl) + '" data-ob-si-create-ab-test="' + escapeHtml(insight.id) + '">' + escapeHtml(i18n.hypothesisCreateTest || 'Create A/B test from this insight') + '</a>' : ''));
		}

		if (!body) {
			return '';
		}

		return '<section class="ob-smart-insights-detail-section is-hypothesis" data-ob-si-hypothesis>' +
			renderSectionHeading(i18n.hypothesisTitle || 'Hypothesis and next experiment', 'hypothesis') +
			body +
		'</section>';
	}

	// --- Experiment verdict and stage tracker -------------------------------
	// The closing half of the loop. Only insights that carry an analyzed
	// experiment render any of this; a Free viewer never receives the block at
	// all, and legacy rows render nothing.

	function getVerdict(insight) {
		var experiment = getExperiment(insight);
		return (experiment && typeof experiment.verdict === 'object' && experiment.verdict) ? experiment.verdict : null;
	}

	// Detected -> Diagnosed -> Hypothesis -> Testing -> Verified, derived from
	// what is actually stored: no stage column, no guessing forward.
	function getStoryStages(insight) {
		var experiment = getExperiment(insight);
		var hypothesis = getHypothesis(insight);
		var verdict = getVerdict(insight);
		var reached = 0;

		if (getStoryCauses(insight).length || isStoryInsight(insight)) {
			reached = 1;
		}
		if (hypothesis) {
			reached = Math.max(reached, 2);
		}
		if (experiment && parseInt(experiment.test_id, 10)) {
			reached = Math.max(reached, 3);
		}
		if (verdict || (experiment && experiment.stage === 'verified')) {
			reached = Math.max(reached, 4);
		}

		return {
			reached: reached,
			labels: [
				i18n.stageDetected || 'Detected',
				i18n.stageDiagnosed || 'Diagnosed',
				i18n.stageHypothesis || 'Hypothesis',
				i18n.stageTesting || 'Testing',
				i18n.stageVerified || 'Verified'
			]
		};
	}

	function renderStageTracker(insight) {
		// The tracker only earns its space once the story moved past detection.
		if (!getHypothesis(insight) && !getExperiment(insight)) {
			return '';
		}

		var stage = getStoryStages(insight);
		var steps = stage.labels.map(function(label, index) {
			var state = index < stage.reached ? ' is-done' : (index === stage.reached ? ' is-current' : '');
			return '<li class="ob-smart-insights-stage-step' + state + '" data-stage-index="' + index + '">' +
				'<span class="ob-smart-insights-stage-dot" aria-hidden="true"></span>' +
				'<span class="ob-smart-insights-stage-label">' + escapeHtml(label) + '</span>' +
			'</li>';
		}).join('');

		return '<ol class="ob-smart-insights-stage-tracker" data-ob-si-stage="' + escapeHtml(stage.reached) + '"' +
			' aria-label="' + escapeHtml(i18n.stageTrackerLabel || 'Experiment progress') + '">' + steps + '</ol>';
	}

	function renderVerdictFacts(verdict) {
		var facts = [];

		if (verdict.lift_pct !== undefined && verdict.lift_pct !== null && verdict.lift_pct !== '') {
			facts.push('<li><span>' + escapeHtml(i18n.verdictLift || 'Measured lift') + '</span><strong>' + escapeHtml(formatSignedPercent(verdict.lift_pct)) + '</strong></li>');
		}
		if (verdict.pbc_pct !== undefined && verdict.pbc_pct !== null && verdict.pbc_pct !== '') {
			facts.push('<li><span>' + escapeHtml(i18n.verdictProbability || 'Probability to beat control') + '</span><strong>' + escapeHtml(formatPercent(verdict.pbc_pct)) + '</strong></li>');
		}
		if (verdict.total_impressions) {
			facts.push('<li><span>' + escapeHtml(i18n.verdictSample || 'Sessions measured') + '</span><strong>' + escapeHtml(formatNumber(verdict.total_impressions)) + '</strong></li>');
		}

		return facts.length ? '<ul class="ob-smart-insights-verdict-facts">' + facts.join('') + '</ul>' : '';
	}

	function renderSegmentOutcomeList(entries, className, title) {
		if (!Array.isArray(entries) || !entries.length) {
			return '';
		}

		var items = entries.slice(0, 3).map(function(entry) {
			return '<li class="ob-smart-insights-verdict-segment ' + className + '">' +
				'<span class="ob-smart-insights-verdict-segment-label">' + escapeHtml(entry.label || '') + '</span>' +
				'<span class="ob-smart-insights-verdict-segment-dimension">' + escapeHtml(entry.dimension_label || '') + '</span>' +
				'<span class="ob-smart-insights-verdict-segment-lift">' + escapeHtml(formatSignedPercent(entry.lift_pct)) + '</span>' +
			'</li>';
		}).join('');

		return '<div class="ob-smart-insights-verdict-segments">' +
			'<h4>' + escapeHtml(title) + '</h4>' +
			'<ul>' + items + '</ul>' +
		'</div>';
	}

	function renderExperimentSection(insight) {
		var experiment = getExperiment(insight);
		var verdict = getVerdict(insight);
		if (!experiment || !verdict) {
			return '';
		}

		var outcome = String(verdict.outcome || 'inconclusive');
		var segments = (experiment.segments && typeof experiment.segments === 'object') ? experiment.segments : {};
		var recheck = (experiment.signal_recheck && typeof experiment.signal_recheck === 'object') ? experiment.signal_recheck : null;
		var nextStep = (experiment.next_step && typeof experiment.next_step === 'object') ? experiment.next_step : null;

		return '<section class="ob-smart-insights-detail-section is-experiment" data-ob-si-experiment="' + escapeHtml(outcome) + '">' +
			renderSectionHeading(i18n.experimentResultTitle || 'Experiment result', 'experiment') +
			renderStageTracker(insight) +
			'<p class="ob-smart-insights-verdict-outcome is-' + escapeHtml(outcome.replace(/_/g, '-')) + '">' +
				'<span class="ob-smart-insights-verdict-badge">' + escapeHtml(verdict.outcome_label || '') + '</span>' +
				escapeHtml(verdict.summary || '') +
			'</p>' +
			renderVerdictFacts(verdict) +
			renderSegmentOutcomeList(segments.winners, 'is-won', i18n.verdictWinners || 'Segments that won') +
			renderSegmentOutcomeList(segments.losers, 'is-lost', i18n.verdictLosers || 'Segments that lost') +
			(recheck ? '<p class="ob-smart-insights-verdict-recheck is-' + escapeHtml(String(recheck.status || 'not_measured').replace(/_/g, '-')) + '">' + escapeHtml(recheck.label || '') + '</p>' : '') +
			(nextStep && nextStep.statement ? '<p class="ob-smart-insights-verdict-next"><span>' + escapeHtml(i18n.verdictNextStep || 'Next experiment') + '</span>' + escapeHtml(nextStep.statement) + '</p>' : '') +
			renderExperimentLink(insight) +
		'</section>';
	}

	// "Where is the problem?" — the single section that answers "which audience
	// carries this" (segment matrix) and "since when" (daily series). Neither
	// half is stored on the insight row: both only make sense for the current
	// spam policy, so they are fetched on demand once the modal is open and
	// rendered into these two placeholders. The two calls run in parallel and
	// render independently, so one failing half never blanks the other.
	function renderWhereSection(insight) {
		var id = insight && parseInt(insight.id, 10) ? parseInt(insight.id, 10) : 0;
		if (!id) {
			return '';
		}

		return '<section class="ob-smart-insights-detail-section is-where is-affected" data-ob-si-segments="' + id + '">' +
			renderSectionHeading(i18n.whereTitle || 'Where is the problem?', 'where') +
			'<div class="ob-smart-insights-where" data-ob-si-segments-body>' +
				'<div class="ob-smart-insights-where-segments" data-ob-si-where-segments>' + renderWhereSkeleton(i18n.whereLoadingSegments || 'Measuring which audience carries this...') + '</div>' +
				'<div class="ob-smart-insights-where-series" data-ob-si-where-series>' + renderWhereSkeleton(i18n.whereLoadingSeries || 'Measuring the daily trend...') + '</div>' +
			'</div>' +
		'</section>';
	}

	function renderWhereSkeleton(label) {
		return '<div class="ob-smart-insights-where-skeleton">' +
			'<span class="ob-smart-insights-where-skeleton-bar" aria-hidden="true"></span>' +
			'<span class="ob-smart-insights-where-skeleton-bar is-short" aria-hidden="true"></span>' +
			'<p class="description">' + escapeHtml(label) + '</p>' +
		'</div>';
	}

	// Values only ever carry the unit the backend declared for the primary
	// metric, so formatting stays a lookup instead of a guess.
	function formatWhereValue(value, unit) {
		var number = parseFloat(value);
		if (value === null || value === undefined || value === '' || isNaN(number)) {
			return i18n.whereNoValue || 'no data';
		}

		return unit === 'seconds' ? formatSeconds(number) : formatPercent(number);
	}

	function getWhereUnavailableText(matrix) {
		var reason = matrix && matrix.reason ? String(matrix.reason) : '';
		var sessions = matrix && matrix.scope_sessions ? parseInt(matrix.scope_sessions, 10) || 0 : 0;
		var floor = matrix && matrix.min_scope_sessions ? parseInt(matrix.min_scope_sessions, 10) || 0 : 0;

		if (reason === 'scope_not_page') {
			return i18n.whereNeedsPageContext || 'Segment split needs page-level context; open the funnel or form report for step-level detail.';
		}
		// A device/source/campaign/segment insight IS one audience already:
		// explaining that beats the generic "could not measure" shrug.
		var scopeEntityType = matrix && matrix.scope && matrix.scope.entity_type ? String(matrix.scope.entity_type) : '';
		if (reason === 'scope_not_addressable' && ['device', 'source', 'campaign', 'segment'].indexOf(scopeEntityType) !== -1) {
			var scopeEntityLabel = (matrix.scope.entity_label || matrix.scope.entity_id || scopeEntityType);
			return fillToken(
				i18n.whereAlreadySegment || 'This insight already isolates one audience segment (%s). The per-audience split applies to page-scoped insights - check the page-level insights correlated with this issue.',
				'%s',
				scopeEntityLabel
			);
		}
		if (reason === 'too_few_sessions' || reason === 'no_scope_sessions') {
			// "12 sessions" alone reads like an opinion; "12 of the 30 needed"
			// tells the reader exactly how much more traffic ends the wait, so the
			// floor is spelled out whenever the backend sent it.
			if (floor > 0) {
				return fillToken(
					fillToken(i18n.whereTooFewSessionsFloor || 'Not enough sessions in this period to point at an audience: %1$s of the %2$s needed. Collect more traffic before blaming a segment.', '%1$s', formatNumber(sessions)),
					'%2$s',
					formatNumber(floor)
				);
			}
			return fillToken(i18n.whereTooFewSessions || 'Not enough sessions in this period to point at an audience (%s measured). Collect more traffic before blaming a segment.', '%s', formatNumber(sessions));
		}

		return i18n.whereUnavailable || 'No audience split could be measured for this insight scope.';
	}

	// One sentence, generated from the strongest outlier. A segment that sits on
	// the GOOD side of the metric is still worth naming, but never with problem
	// wording: "better" is a finding, not a fault.
	function renderWhereVerdict(matrix) {
		if (!matrix || typeof matrix !== 'object' || !matrix.available) {
			return '<p class="ob-smart-insights-where-verdict is-muted">' + escapeHtml(getWhereUnavailableText(matrix)) + '</p>';
		}

		var outliers = Array.isArray(matrix.outliers) ? matrix.outliers : [];
		if (matrix.uniform || !outliers.length) {
			return '<p class="ob-smart-insights-where-verdict is-uniform">' + escapeHtml(i18n.whereUniform || 'Spread evenly across device, source, country and time - this is the page, not an audience.') + '</p>';
		}

		var outlier = outliers[0];
		var unit = matrix.metric_unit || 'percent';
		var isWorse = !!outlier.is_worse_side;
		var template = isWorse
			? (i18n.whereVerdictWorse || '%1$s carries this: %2$s %3$s vs %4$s for everyone else (%5$s of %6$s sessions).')
			: (i18n.whereVerdictBetter || '%1$s behaves differently - better: %2$s %3$s vs %4$s for everyone else (%5$s of %6$s sessions).');

		template = fillToken(template, '%1$s', outlier.label || outlier.key || '');
		template = fillToken(template, '%2$s', matrix.metric_label || matrix.primary_metric || '');
		template = fillToken(template, '%3$s', formatWhereValue(outlier.value, unit));
		template = fillToken(template, '%4$s', formatWhereValue(outlier.complement_value, unit));
		template = fillToken(template, '%5$s', formatNumber(outlier.sessions));
		template = fillToken(template, '%6$s', formatNumber(matrix.scope_sessions));

		// The sentence itself is identical in both tiers, but only Free reads its
		// verdict from here — the outlier cards below it are Pro. The pulse and the
		// data-floor note therefore ride on the sentence too, so a Free viewer is
		// told "measured, but not conclusive" instead of reading a bare comparison
		// as if it were significant.
		var pulse = getWhereOutlierPulse(outlier);
		var note = outlier.insufficient
			? '<span class="ob-smart-insights-where-verdict-note">' + escapeHtml(i18n.whereBucketInsufficient || 'Below the data floor — measured, but not conclusive.') + '</span>'
			: '';

		return '<p class="ob-smart-insights-where-verdict' + (isWorse ? ' is-worse' : ' is-better') + ' has-pulse-' + pulse + '">' +
			renderWherePulse(pulse) +
			'<span>' + escapeHtml(template) + '</span>' +
			note +
		'</p>';
	}

	function truncateWhereLabel(label, maxLength) {
		var text = String(label === null || label === undefined ? '' : label);
		return text.length > maxLength ? text.slice(0, maxLength - 1) + '…' : text;
	}

	// ---------------------------------------------------------------------
	// Segment identity layer: pulses, flags, icons, trend arrows, sparklines.
	//
	// Every verdict drawn here is READ from the payload, never recomputed. The
	// significance maths (z-tests, session floors, trend direction) lives in the
	// segment matrix so a Free viewer, a Pro viewer and a future export can never
	// disagree about what "broken" means. This file only decides what red looks
	// like.
	// ---------------------------------------------------------------------

	var WHERE_PULSE_STATES = ['broken', 'watch', 'healthy', 'insufficient'];

	var WHERE_DEVICE_ICONS = {
		mobile: 'smartphone',
		phone: 'smartphone',
		tablet: 'tablet',
		desktop: 'monitor',
		laptop: 'laptop'
	};

	// Browser keys arrive as free-form user-agent labels ("Mobile Safari",
	// "Chrome 121"), so the match is a substring probe, not a table lookup.
	var WHERE_BROWSER_ICONS = [
		['chrome', 'chrome'],
		['firefox', 'flame'],
		['safari', 'compass'],
		['edge', 'globe'],
		['opera', 'circle-dot'],
		['samsung', 'smartphone']
	];

	var WHERE_DIMENSION_ICONS = {
		device: 'monitor',
		browser: 'globe',
		country: 'map-pin',
		source: 'share-2',
		campaign: 'megaphone',
		visitor_type: 'user-round',
		daypart: 'clock',
		weekday: 'calendar-days'
	};

	function normalizeWherePulse(pulse) {
		var value = String(pulse === null || pulse === undefined ? '' : pulse);
		return WHERE_PULSE_STATES.indexOf(value) === -1 ? 'insufficient' : value;
	}

	function getWherePulseLabel(pulse) {
		switch (normalizeWherePulse(pulse)) {
			case 'broken':
				return i18n.wherePulseBroken || 'Carries the problem';
			case 'watch':
				return i18n.wherePulseWatch || 'Worth watching';
			case 'healthy':
				return i18n.wherePulseHealthy || 'Behaves normally';
			default:
				return i18n.wherePulseInsufficient || 'Not enough data';
		}
	}

	function renderWherePulse(pulse) {
		var state = normalizeWherePulse(pulse);
		var label = getWherePulseLabel(state);
		return '<span class="ob-smart-insights-where-pulse is-' + state + '" role="img" aria-label="' + escapeHtml(label) + '" title="' + escapeHtml(label) + '"></span>';
	}

	// The per-outlier pulse is a projection of server flags, in priority order:
	// an unmeasurable bucket is grey before it is anything else, a significant
	// bucket is red or green by which side of the metric it sits on, and only a
	// non-significant bucket can fall back to its trend for amber.
	function getWhereOutlierPulse(outlier) {
		if (!outlier || typeof outlier !== 'object') {
			return 'insufficient';
		}
		if (outlier.insufficient) {
			return 'insufficient';
		}
		if (outlier.is_outlier) {
			return outlier.is_worse_side ? 'broken' : 'healthy';
		}

		var direction = outlier.trend && outlier.trend.direction ? String(outlier.trend.direction) : '';
		return direction === 'degrading' ? 'watch' : 'healthy';
	}

	// Country flags with no network dependency. The bundled flag-icons stylesheet
	// resolves every single flag against a public CDN, which an admin screen must
	// never depend on (offline installs, air-gapped staging, privacy), so the
	// ISO-2 code is mapped to its Unicode regional-indicator pair instead: zero
	// requests, zero bundled assets, and a readable "FR" letter pair on the
	// platforms that ship no flag glyphs.
	function renderWhereFlag(countryCode) {
		var code = String(countryCode === null || countryCode === undefined ? '' : countryCode).toUpperCase().replace(/[^A-Z]/g, '');
		if (code.length !== 2 || !String.fromCodePoint) {
			return '';
		}

		var emoji = String.fromCodePoint(0x1F1E6 + code.charCodeAt(0) - 65, 0x1F1E6 + code.charCodeAt(1) - 65);
		return '<span class="ob-smart-insights-where-flag" data-country="' + escapeHtml(code) + '" aria-hidden="true">' + emoji + '</span>';
	}

	function getWhereSegmentIcon(dimension, key) {
		var dim = String(dimension === null || dimension === undefined ? '' : dimension);
		var normalized = String(key === null || key === undefined ? '' : key).toLowerCase();

		if (dim === 'device') {
			for (var deviceKey in WHERE_DEVICE_ICONS) {
				if (Object.prototype.hasOwnProperty.call(WHERE_DEVICE_ICONS, deviceKey) && normalized.indexOf(deviceKey) !== -1) {
					return WHERE_DEVICE_ICONS[deviceKey];
				}
			}
			return 'monitor';
		}

		if (dim === 'browser') {
			for (var index = 0; index < WHERE_BROWSER_ICONS.length; index++) {
				if (normalized.indexOf(WHERE_BROWSER_ICONS[index][0]) !== -1) {
					return WHERE_BROWSER_ICONS[index][1];
				}
			}
			return 'globe';
		}

		return WHERE_DIMENSION_ICONS[dim] || 'circle-dot';
	}

	// A country renders as its flag when the backend could resolve an ISO-2 code
	// and as the generic pin when it could not, so a NULL country column degrades
	// to a still-labelled row instead of a hole.
	function renderWhereSegmentMark(dimension, key, countryCode) {
		if (String(dimension || '') === 'country') {
			var flag = renderWhereFlag(countryCode);
			if (flag) {
				return flag;
			}
		}

		return '<i class="ob-smart-insights-where-mark" data-lucide="' + escapeHtml(getWhereSegmentIcon(dimension, key)) + '" aria-hidden="true"></i>';
	}

	function getWhereTrendMeta(trend) {
		var direction = trend && trend.direction ? String(trend.direction) : 'insufficient';

		switch (direction) {
			case 'degrading':
				return { direction: 'degrading', glyph: '↗', label: i18n.whereTrendDegrading || 'Getting worse across the period' };
			case 'improving':
				return { direction: 'improving', glyph: '↘', label: i18n.whereTrendImproving || 'Getting better across the period' };
			case 'stable':
				return { direction: 'stable', glyph: '→', label: i18n.whereTrendStable || 'Flat across the period' };
			default:
				return { direction: 'insufficient', glyph: '·', label: i18n.whereTrendInsufficient || 'Too few sessions per half to read a trend' };
		}
	}

	// The arrow is the headline; the early → late pair behind it is the proof and
	// only exists for Pro, so the tooltip degrades to the direction word alone.
	function renderWhereTrend(trend, unit) {
		var meta = getWhereTrendMeta(trend);
		var detail = meta.label;
		var hasValues = trend &&
			trend.early_value !== undefined && trend.early_value !== null &&
			trend.late_value !== undefined && trend.late_value !== null;

		if (hasValues) {
			detail = fillToken(
				fillToken(i18n.whereTrendDetail || '%1$s (%2$s)', '%1$s', meta.label),
				'%2$s',
				formatWhereValue(trend.early_value, unit) + ' → ' + formatWhereValue(trend.late_value, unit)
			);
		}

		return '<span class="ob-smart-insights-where-trend is-' + meta.direction + '" title="' + escapeHtml(detail) + '">' +
			'<span aria-hidden="true">' + meta.glyph + '</span>' +
			'<span class="screen-reader-text">' + escapeHtml(detail) + '</span>' +
		'</span>';
	}

	// One outlier, one line, drawn with the same null-gapped path builder as the
	// big chart: days the segment had no sessions carry value:null and BREAK the
	// line rather than being drawn as a zero the segment never actually scored.
	// A day the segment had no sessions at all carries value:null, so the finite
	// days can sit anywhere in the window — including alone. A lone point is not
	// a line and gets no `L` command, which would silently draw an empty SVG, so
	// the days that have no drawable neighbour are emitted as dots instead.
	function collectWhereSparkPoints(series) {
		var points = [];
		series.forEach(function(point, index) {
			var number = point ? parseFloat(point.value) : NaN;
			if (!isNaN(number) && isFinite(number)) {
				points.push({ index: index, value: number });
			}
		});
		return points;
	}

	function renderWhereSparkline(outlier, matrix) {
		var series = outlier && Array.isArray(outlier.sparkline) ? outlier.sparkline : [];
		if (!series.length) {
			return '';
		}

		var points = collectWhereSparkPoints(series);
		if (!points.length) {
			return '';
		}

		var values = points.map(function(point) {
			return point.value;
		});
		var min = Math.min.apply(null, values);
		var max = Math.max.apply(null, values);

		var width = 104;
		var height = 26;
		var padY = 3;
		var plot = height - padY * 2;
		var lastIndex = Math.max(1, series.length - 1);
		var xFor = function(index) {
			return (index * width) / lastIndex;
		};
		// A segment whose value never moved has no range to scale against, and
		// drawing it against a synthetic one pins the line to the floor — which
		// reads as "it dropped to zero". A flat series belongs on the mid-line.
		var yFor = max > min
			? function(value) {
				return padY + plot - ((value - min) / (max - min)) * plot;
			}
			: function() {
				return padY + plot / 2;
			};

		var path = buildWhereChartPath(series, xFor, yFor);
		// Only the days with no drawable neighbour become dots: a run of two or
		// more consecutive days is already a visible line segment.
		var dots = points.filter(function(point, position) {
			var previous = points[position - 1];
			var next = points[position + 1];
			return (!previous || previous.index !== point.index - 1) && (!next || next.index !== point.index + 1);
		}).map(function(point) {
			return '<circle class="ob-smart-insights-where-sparkline-dot" cx="' + xFor(point.index).toFixed(1) + '" cy="' + yFor(point.value).toFixed(1) + '" r="1.6"></circle>';
		}).join('');

		if (!path && !dots) {
			return '';
		}

		var unit = matrix.metric_unit || 'percent';
		var label = fillToken(
			fillToken(i18n.whereSparklineLabel || 'Daily %1$s for this segment over %2$s days', '%1$s', matrix.metric_label || matrix.primary_metric || ''),
			'%2$s',
			formatNumber(series.length)
		);
		var summary = values.length > 1
			? label + ': ' + formatWhereValue(values[0], unit) + ' → ' + formatWhereValue(values[values.length - 1], unit)
			: label + ': ' + formatWhereValue(values[0], unit);

		return '<svg class="ob-smart-insights-where-sparkline" viewBox="0 0 ' + width + ' ' + height + '" width="' + width + '" height="' + height + '" role="img" aria-label="' + escapeHtml(summary) + '" preserveAspectRatio="none" focusable="false">' +
			'<title>' + escapeHtml(summary) + '</title>' +
			(path ? '<path class="ob-smart-insights-where-sparkline-line" d="' + path + '"></path>' : '') +
			dots +
		'</svg>';
	}

	// A combined finding only earns its extra row if the reader can see BOTH
	// halves, so it renders as two separately-marked parts instead of one
	// pre-joined string.
	function renderWhereCardLabel(outlier) {
		var parts = Array.isArray(outlier.parts) ? outlier.parts : [];

		if (parts.length) {
			return '<span class="ob-smart-insights-where-card-label is-combo">' + parts.map(function(part, index) {
				if (!part || typeof part !== 'object') {
					return '';
				}
				return (index ? '<span class="ob-smart-insights-where-card-join" aria-hidden="true">×</span>' : '') +
					'<span class="ob-smart-insights-where-card-part">' +
						renderWhereSegmentMark(part.dimension, part.key, part.country_code) +
						'<span>' + escapeHtml(part.label || part.key || '') + '</span>' +
					'</span>';
			}).join('') + '</span>';
		}

		return '<span class="ob-smart-insights-where-card-label">' +
			renderWhereSegmentMark(outlier.dimension, outlier.key, outlier.country_code) +
			'<span>' + escapeHtml(outlier.label || outlier.key || '') + '</span>' +
		'</span>';
	}

	// The first thing the eye should hit: which dimensions carry a problem, and
	// which ones could not be measured at all. Free and Pro read different fields
	// (`dimension_pulses` vs the full `dimensions` list, because Free never
	// receives buckets) but the rendered strip is deliberately identical.
	function collectWherePulseEntries(matrix) {
		if (Array.isArray(matrix.dimension_pulses) && matrix.dimension_pulses.length) {
			return matrix.dimension_pulses;
		}

		return (Array.isArray(matrix.dimensions) ? matrix.dimensions : []).filter(function(entry) {
			// Combination dimensions are already represented by their two parents;
			// listing them again would double-count the same audience in the strip.
			return entry && typeof entry === 'object' && !entry.parts;
		});
	}

	function renderWherePulseStrip(matrix) {
		if (!matrix || !matrix.available) {
			return '';
		}

		var entries = collectWherePulseEntries(matrix);
		if (!entries.length) {
			return '';
		}

		var items = entries.map(function(entry) {
			var pulse = normalizeWherePulse(entry.pulse);
			var tested = parseInt(entry.tested_bucket_count, 10) || 0;
			var total = parseInt(entry.bucket_count, 10) || 0;
			// "3 of 8 segments measured" is the honest version of a grey dot: it
			// names the missing data instead of implying the dimension is fine.
			var meta = (total > tested)
				? fillToken(fillToken(i18n.wherePulseMeasured || '%1$s of %2$s segments measured', '%1$s', formatNumber(tested)), '%2$s', formatNumber(total))
				: getWherePulseLabel(pulse);

			return '<li class="ob-smart-insights-where-pulse-item is-' + pulse + '" title="' + escapeHtml(getWherePulseLabel(pulse) + ' — ' + meta) + '">' +
				renderWherePulse(pulse) +
				'<span class="ob-smart-insights-where-pulse-name">' + escapeHtml(entry.dimension_label || entry.dimension || '') + '</span>' +
				'<span class="ob-smart-insights-where-pulse-meta">' + escapeHtml(meta) + '</span>' +
			'</li>';
		}).filter(Boolean).join('');

		if (!items) {
			return '';
		}

		return '<div class="ob-smart-insights-where-pulses">' +
			renderSectionHeading(i18n.wherePulseTitle || 'Segment health', 'where_pulse', { tag: 'h4', headingClass: 'ob-smart-insights-where-subtitle' }) +
			'<ul class="ob-smart-insights-where-pulse-list">' + items + '</ul>' +
		'</div>';
	}

	// Free teaser for combination findings (decision D1): the count and half the
	// identity prove the analysis found something a single dimension missed, and
	// stop exactly short of being actionable without Pro.
	function renderWhereComboTeaser(matrix) {
		var count = matrix ? parseInt(matrix.locked_combo_count, 10) || 0 : 0;
		if (!count) {
			return '';
		}

		var teaser = matrix.locked_combo_teaser && typeof matrix.locked_combo_teaser === 'object' ? matrix.locked_combo_teaser : null;
		var headline = count === 1
			? (i18n.whereComboLockedOne || '1 combined-segment pattern found')
			: fillToken(i18n.whereComboLockedMany || '%s combined-segment patterns found', '%s', formatNumber(count));

		return '<div class="ob-smart-insights-where-combo-teaser' + (teaser && teaser.is_worse_side ? ' is-worse' : '') + '">' +
			'<span class="ob-smart-insights-badge is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>' +
			'<strong>' + escapeHtml(headline) + '</strong>' +
			(teaser
				? '<p class="ob-smart-insights-where-combo-hint">' +
					'<span class="ob-smart-insights-where-combo-dimension">' + escapeHtml(teaser.dimension_label || '') + '</span>' +
					'<span class="ob-smart-insights-where-combo-label">' + escapeHtml(teaser.label_hint || '') + '</span>' +
				'</p>'
				: '') +
			'<p class="description">' + escapeHtml(i18n.whereComboLockedBody || 'A pattern that only appears when two audience traits are combined — neither trait on its own was significant. Unlock the full pair in Pro.') + '</p>' +
		'</div>';
	}

	function formatWhereShare(share) {
		var number = parseFloat(share);
		return isNaN(number) ? (i18n.whereNoValue || 'no data') : formatPercent(number * 100);
	}

	function renderWhereMixBucket(bucket, entry) {
		var shares = fillToken(
			fillToken(i18n.whereMixShare || '%1$s → %2$s of sessions', '%1$s', formatWhereShare(bucket.previous_share)),
			'%2$s',
			formatWhereShare(bucket.share)
		);
		// The causality hint is the whole point of the block: a spike that also
		// degrades the metric changes what to do next, a spike that does not is
		// noise the reader should be told to ignore.
		var causality = '';
		if (bucket.degrades_metric === true) {
			causality = i18n.whereMixDegrades || 'and it performs worse than everyone else — the metric drop is a traffic-mix effect, not a page regression.';
		} else if (bucket.degrades_metric === false) {
			causality = i18n.whereMixNeutral || 'but it behaves like everyone else — the spike does not explain the metric change.';
		}

		return '<li class="ob-smart-insights-where-mix-item' + (bucket.degrades_metric === true ? ' is-degrading' : '') + '">' +
			'<span class="ob-smart-insights-where-mix-label">' +
				renderWhereSegmentMark(entry.dimension, bucket.key, bucket.country_code) +
				'<span>' + escapeHtml(bucket.label || bucket.key || '') + '</span>' +
			'</span>' +
			'<span class="ob-smart-insights-where-mix-share">' + escapeHtml(shares) + '</span>' +
			(causality ? '<span class="ob-smart-insights-where-mix-cause">' + escapeHtml(causality) + '</span>' : '') +
		'</li>';
	}

	// "Did the page get worse, or did the audience get replaced?" — the one
	// question the metric alone cannot answer. Absent block = quiet mix, and a
	// quiet mix renders nothing at all.
	function renderWhereMixShift(matrix) {
		var mix = matrix && matrix.traffic_mix && typeof matrix.traffic_mix === 'object' ? matrix.traffic_mix : null;
		if (!mix || !mix.available) {
			return '';
		}

		var range = mix.previous_range && typeof mix.previous_range === 'object' ? mix.previous_range : null;
		var rangeText = range && range.from && range.to
			? fillToken(fillToken(i18n.whereMixPrevious || 'compared with %1$s to %2$s', '%1$s', String(range.from)), '%2$s', String(range.to))
			: '';
		var title = '<h4 class="ob-smart-insights-where-subtitle ob-smart-insights-section-heading">' + escapeHtml(i18n.whereMixTitle || 'Did the audience change?') +
			renderSectionTooltip('where_mix') +
			(rangeText ? '<span class="ob-smart-insights-where-mix-range">' + escapeHtml(rangeText) + '</span>' : '') +
		'</h4>';

		if (mix.locked) {
			var headline = mix.headline && typeof mix.headline === 'object' ? mix.headline : null;
			var sentence = headline
				? fillToken(
					fillToken(
						fillToken(i18n.whereMixLockedHeadline || 'Traffic from %1$s moved from %2$s to %3$s of sessions.', '%1$s', headline.label || ''),
						'%2$s',
						formatWhereShare(headline.previous_share)
					),
					'%3$s',
					formatWhereShare(headline.share)
				)
				: (i18n.whereMixLockedGeneric || 'Your traffic mix changed measurably over this period.');

			return '<div class="ob-smart-insights-where-mix is-locked">' +
				title +
				'<p class="ob-smart-insights-where-mix-verdict">' + renderWherePulse(headline && headline.degrades_metric === true ? 'broken' : 'watch') + escapeHtml(sentence) + '</p>' +
				(headline && headline.degrades_metric === true
					? '<p class="ob-smart-insights-where-mix-cause is-degrading">' + escapeHtml(i18n.whereMixDegrades || 'and it performs worse than everyone else — the metric drop is a traffic-mix effect, not a page regression.') + '</p>'
					: '') +
				'<p class="description">' + escapeHtml(i18n.whereMixLockedBody || 'The full breakdown — every segment that grew, by how much, and whether it explains the metric — is available in Pro.') + '</p>' +
			'</div>';
		}

		var blocks = (Array.isArray(mix.dimensions) ? mix.dimensions : []).map(function(entry) {
			if (!entry || typeof entry !== 'object') {
				return '';
			}

			var buckets = (Array.isArray(entry.buckets) ? entry.buckets : []).filter(function(bucket) {
				return bucket && bucket.mix_shift;
			});
			var grouped = entry.grouped_shift && entry.grouped && typeof entry.grouped === 'object' ? entry.grouped : null;
			if (!buckets.length && !grouped) {
				return '';
			}

			// A single bucket growing is already reported per row; the grouped line
			// exists for the bot-wave shape where no single country moves enough on
			// its own but several together replace the audience.
			var groupedText = grouped
				? fillToken(
					fillToken(
						fillToken(i18n.whereMixGrouped || '%1$s segments grew together: %2$s → %3$s of sessions.', '%1$s', formatNumber(grouped.bucket_count)),
						'%2$s',
						formatWhereShare(grouped.previous_share)
					),
					'%3$s',
					formatWhereShare(grouped.share)
				)
				: '';

			return '<div class="ob-smart-insights-where-mix-dimension is-' + normalizeWherePulse(entry.pulse) + '">' +
				'<h5>' + renderWherePulse(entry.pulse) + escapeHtml(entry.dimension_label || entry.dimension || '') + '</h5>' +
				(buckets.length
					? '<ul class="ob-smart-insights-where-mix-list">' + buckets.map(function(bucket) {
						return renderWhereMixBucket(bucket, entry);
					}).join('') + '</ul>'
					: '') +
				(groupedText ? '<p class="ob-smart-insights-where-mix-grouped">' + escapeHtml(groupedText) + '</p>' : '') +
			'</div>';
		}).filter(Boolean).join('');

		if (!blocks) {
			return '';
		}

		return '<div class="ob-smart-insights-where-mix">' + title + blocks + '</div>';
	}

	// Two bars, one segment, one complement, drawn to the same scale so the gap
	// the verdict claims is the gap the reader sees.
	function renderWhereBar(outlier, matrix, scope) {
		var unit = matrix.metric_unit || 'percent';
		var value = parseFloat(outlier.value);
		var complement = parseFloat(outlier.complement_value);
		if (isNaN(value) && isNaN(complement)) {
			return '';
		}

		var top = Math.max(isNaN(value) ? 0 : value, isNaN(complement) ? 0 : complement);
		if (unit === 'percent') {
			top = Math.max(top, 100);
		}
		if (top <= 0) {
			top = 1;
		}

		var trackStart = 104;
		var trackWidth = 168;
		var segmentWidth = isNaN(value) ? 0 : Math.max(2, (value / top) * trackWidth);
		var complementWidth = isNaN(complement) ? 0 : Math.max(2, (complement / top) * trackWidth);
		var isActive = !!(scope && scope.dimension === outlier.dimension && scope.key === outlier.key);
		var everyoneElse = i18n.whereEveryoneElse || 'Everyone else';
		var ariaLabel = (outlier.dimension_label || outlier.dimension || '') + ': ' +
			(outlier.label || outlier.key || '') + ' ' + formatWhereValue(outlier.value, unit) + ', ' +
			everyoneElse + ' ' + formatWhereValue(outlier.complement_value, unit);
		var isCombo = Array.isArray(outlier.parts) && outlier.parts.length > 1;
		var pulse = getWhereOutlierPulse(outlier);
		var sparkline = renderWhereSparkline(outlier, matrix);
		// Free ships `trend` as a bare direction word and no sparkline, so both
		// halves of the card degrade independently instead of the row vanishing.
		var trend = outlier.trend ? renderWhereTrend(outlier.trend, unit) : '';

		return '<button type="button" class="ob-smart-insights-where-bar ob-smart-insights-where-card' + (isActive ? ' is-active' : '') + (outlier.is_worse_side ? ' is-worse' : ' is-better') + (isCombo ? ' is-combo' : '') + ' has-pulse-' + pulse + '"' +
			' aria-pressed="' + (isActive ? 'true' : 'false') + '"' +
			' aria-label="' + escapeHtml(ariaLabel) + '"' +
			' title="' + escapeHtml(i18n.whereBarHint || 'Show this segment on the chart and re-scope the evidence links.') + '"' +
			' data-segment-dimension="' + escapeHtml(outlier.dimension || '') + '"' +
			' data-segment-key="' + escapeHtml(outlier.key || '') + '"' +
			' data-segment-label="' + escapeHtml(outlier.label || outlier.key || '') + '"' +
			' data-segment-pulse="' + escapeHtml(pulse) + '"' +
			' data-report-key="' + escapeHtml(outlier.report_key || '') + '">' +
			'<span class="ob-smart-insights-where-card-head">' +
				renderWherePulse(pulse) +
				'<span class="ob-smart-insights-where-bar-dimension">' + escapeHtml(outlier.dimension_label || outlier.dimension || '') + '</span>' +
				(isCombo ? '<span class="ob-smart-insights-where-card-badge">' + escapeHtml(i18n.whereComboBadge || 'Combined') + '</span>' : '') +
				trend +
			'</span>' +
			renderWhereCardLabel(outlier) +
			(outlier.insufficient ? '<span class="ob-smart-insights-where-card-note">' + escapeHtml(i18n.whereBucketInsufficient || 'Below the data floor — measured, but not conclusive.') + '</span>' : '') +
			(sparkline ? '<span class="ob-smart-insights-where-card-spark">' + sparkline + '</span>' : '') +
			'<svg class="ob-smart-insights-where-bar-svg" viewBox="0 0 292 56" width="100%" height="56" role="img" aria-hidden="true" focusable="false">' +
				// The card header already names the segment (with its flag or icon,
				// and both halves when it is a pair), so the bar row says which SIDE
				// it is instead of repeating a label that combos would overflow.
				'<text class="ob-smart-insights-where-bar-name" x="0" y="16">' + escapeHtml(truncateWhereLabel(i18n.whereThisSegment || 'This segment', 16)) + '</text>' +
				'<rect class="ob-smart-insights-where-bar-track" x="' + trackStart + '" y="4" width="' + trackWidth + '" height="16" rx="3"></rect>' +
				'<rect class="ob-smart-insights-where-bar-fill is-segment" x="' + trackStart + '" y="4" width="' + segmentWidth.toFixed(1) + '" height="16" rx="3"></rect>' +
				'<text class="ob-smart-insights-where-bar-value" x="' + (trackStart + segmentWidth + 6).toFixed(1) + '" y="16">' + escapeHtml(formatWhereValue(outlier.value, unit)) + '</text>' +
				'<text class="ob-smart-insights-where-bar-name" x="0" y="44">' + escapeHtml(truncateWhereLabel(everyoneElse, 16)) + '</text>' +
				'<rect class="ob-smart-insights-where-bar-track" x="' + trackStart + '" y="32" width="' + trackWidth + '" height="16" rx="3"></rect>' +
				'<rect class="ob-smart-insights-where-bar-fill is-complement" x="' + trackStart + '" y="32" width="' + complementWidth.toFixed(1) + '" height="16" rx="3"></rect>' +
				'<text class="ob-smart-insights-where-bar-value" x="' + (trackStart + complementWidth + 6).toFixed(1) + '" y="44">' + escapeHtml(formatWhereValue(outlier.complement_value, unit)) + '</text>' +
			'</svg>' +
			'<span class="ob-smart-insights-where-bar-meta">' + escapeHtml(fillToken(fillToken(i18n.whereBarSessions || '%1$s of %2$s sessions', '%1$s', formatNumber(outlier.sessions)), '%2$s', formatNumber(matrix.scope_sessions))) + '</span>' +
		'</button>';
	}

	// Free viewers get the verdict and the daily chart; the per-segment bars and
	// the segment-scoped shortcuts are the Pro half, gated server-side by
	// filter_segment_matrix_for_viewer() (`matrix.locked`), never by a tier check
	// in this file.
	function renderWhereBarsLocked(matrix) {
		return '<div class="ob-smart-insights-upgrade-preview ob-smart-insights-where-locked">' +
			'<span class="ob-smart-insights-badge is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>' +
			'<strong>' + escapeHtml(i18n.segmentLockedTitle || 'Advanced segment insight available in Pro') + '</strong>' +
			'<p>' + escapeHtml(matrix.locked_hint || i18n.segmentsLockedMore || 'Upgrade to Pro to break this insight down by device, source, campaign, and visitor type.') + '</p>' +
			'<a class="button button-secondary" href="' + escapeHtml(upgradeUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(i18n.segmentsUpgradeCta || 'Upgrade to Pro') + '</a>' +
		'</div>';
	}

	function renderWhereBars(matrix, scope) {
		if (!matrix || !matrix.available) {
			return '';
		}

		// MAX_OUTLIERS is 4 since schema v3 (decision D2) so a combined pattern can
		// surface beside the singles instead of being ranked out by them.
		var outliers = Array.isArray(matrix.outliers) ? matrix.outliers.slice(0, 4) : [];
		if (matrix.locked) {
			return outliers.length ? renderWhereBarsLocked(matrix) : '';
		}

		var bars = outliers.map(function(outlier) {
			return renderWhereBar(outlier, matrix, scope);
		}).filter(Boolean).join('');

		if (!bars) {
			return '';
		}

		// Same subtitle element the pulse strip and the mix-shift block already use,
		// so the outlier cards can carry their own "?" without a new layout idiom.
		return renderSectionHeading(
			i18n.whereOutliersTitle || 'Segments that differ most',
			'where_outliers',
			{ tag: 'h4', headingClass: 'ob-smart-insights-where-subtitle' }
		) + '<div class="ob-smart-insights-where-bars">' + bars + '</div>';
	}

	// A destination "accepts" the segment only if the built URL actually carries
	// it. The whitelist alone is not enough: some report types accept a filter on
	// one route but not on another (heatmaps take `device` on the page_id detail
	// route, not on the search-by-url list route), so a whitelisted report can
	// still resolve to a link that silently drops the segment. Promising
	// "Heatmap (Mobile)" and landing on unfiltered data is worse than not
	// offering the shortcut at all, so the emitted URL is the source of truth.
	function whereUrlCarriesSegment(url, scope) {
		var expected = String(scope.reportKey) + '=' + encodeURIComponent(scope.key);
		return String(url || '').toLowerCase().indexOf(expected.toLowerCase()) !== -1;
	}

	// Only the destinations that actually accept the segment as a filter get a
	// shortcut: the report whitelist is the same one applySegmentScopeToReports()
	// injects the segment into.
	function renderWhereShortcuts(insight, scope) {
		if (!scope || WHERE_SCOPED_REPORT_KEYS.indexOf(scope.reportKey) === -1) {
			return '';
		}

		var reports = Array.isArray(insight.related_reports) ? insight.related_reports : [];
		var scoped = applySegmentScopeToReports(reports, scope);
		var seen = {};
		var links = [];

		scoped.forEach(function(report) {
			if (links.length >= 4 || !report || typeof report !== 'object') {
				return;
			}
			var accepted = WHERE_SCOPED_REPORT_FILTERS[normalizeReportType(report)];
			if (!accepted || accepted.indexOf(scope.reportKey) === -1) {
				return;
			}
			var resolved = resolveRelatedReportLink(report, insight);
			if (!resolved || !resolved.url || !whereUrlCarriesSegment(resolved.url, scope)) {
				return;
			}
			var label = fillToken(fillToken(i18n.whereShortcut || '%1$s (%2$s)', '%1$s', getRelatedReportDestinationLabel(report, getContextualReportMeta(report, insight))), '%2$s', scope.label);
			// Two related reports can differ only by insight-tracking params and
			// still be the same destination to the reader, so the visible label is
			// what gets de-duplicated, not the raw URL.
			var dedupeKey = label.toLowerCase();
			if (seen[resolved.url] || seen[dedupeKey]) {
				return;
			}
			seen[resolved.url] = true;
			seen[dedupeKey] = true;
			links.push('<a class="button button-secondary ob-smart-insights-where-shortcut" href="' + escapeHtml(resolved.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(label) + '</a>');
		});

		if (!links.length) {
			return '';
		}

		return '<div class="ob-smart-insights-where-shortcuts">' +
			'<h4>' + escapeHtml(i18n.whereShortcutsTitle || 'Open filtered to this segment') + '</h4>' +
			links.join('') +
		'</div>';
	}

	// Reading order is the diagnostic order: the verdict sentence, then the pulse
	// strip that shows WHICH dimensions were even measurable, then the outlier
	// cards, then the "was it the audience, not the page?" mix block. Free and Pro
	// run the exact same sequence — the server already decided what each field
	// contains, so no tier check appears here.
	function renderWhereSegments(insight, matrix, scope) {
		return renderWhereVerdict(matrix) +
			renderWherePulseStrip(matrix) +
			renderWhereBars(matrix, scope) +
			renderWhereComboTeaser(matrix) +
			renderWhereMixShift(matrix) +
			(matrix && matrix.available && !matrix.locked && Array.isArray(matrix.outliers) && matrix.outliers.length ? renderSegmentScopeStatus(scope) : '') +
			renderWhereShortcuts(insight, scope);
	}

	// ---------------------------------------------------------------------
	// Daily chart. Inline SVG only: no chart library, no new dependency. The
	// current period is solid, the previous period dashed behind it, and the
	// day the problem was first detected is marked so "since when" is readable
	// without a legend lookup.
	// ---------------------------------------------------------------------

	function collectWhereSeriesValues(series, values) {
		if (!Array.isArray(series)) {
			return;
		}
		series.forEach(function(point) {
			var number = point ? parseFloat(point.value) : NaN;
			if (!isNaN(number) && isFinite(number)) {
				values.push(number);
			}
		});
	}

	function buildWhereChartPath(series, xFor, yFor) {
		if (!Array.isArray(series)) {
			return '';
		}

		var path = '';
		var drawing = false;
		series.forEach(function(point, index) {
			var number = point ? parseFloat(point.value) : NaN;
			if (isNaN(number) || !isFinite(number)) {
				drawing = false;
				return;
			}
			path += (drawing ? 'L' : 'M') + xFor(index).toFixed(1) + ' ' + yFor(number).toFixed(1) + ' ';
			drawing = true;
		});

		return path.trim();
	}

	function formatWhereChartDay(date) {
		var text = String(date || '');
		return text.length >= 10 ? text.slice(5) : text;
	}

	function renderWhereChart(series, scope) {
		if (!series || typeof series !== 'object' || !series.available) {
			return '<p class="description ob-smart-insights-where-chart-empty">' + escapeHtml(getWhereUnavailableText(series)) + '</p>';
		}

		var current = Array.isArray(series.current) ? series.current : [];
		var previous = Array.isArray(series.previous) ? series.previous : [];
		var segmentSeries = series.segment && Array.isArray(series.segment.series) ? series.segment.series : [];
		if (!current.length) {
			return '<p class="description ob-smart-insights-where-chart-empty">' + escapeHtml(i18n.whereChartEmpty || 'No daily data is available for this period yet.') + '</p>';
		}

		var values = [];
		collectWhereSeriesValues(current, values);
		collectWhereSeriesValues(previous, values);
		collectWhereSeriesValues(segmentSeries, values);
		if (!values.length) {
			return '<p class="description ob-smart-insights-where-chart-empty">' + escapeHtml(i18n.whereChartEmpty || 'No daily data is available for this period yet.') + '</p>';
		}

		var unit = series.metric_unit || 'percent';
		var min = Math.min.apply(null, values);
		var max = Math.max.apply(null, values);
		var pad = max > min ? (max - min) * 0.15 : Math.max(1, Math.abs(max) * 0.1);
		min = Math.max(0, min - pad);
		max = max + pad;
		if (max <= min) {
			max = min + 1;
		}
		if (unit === 'percent' && max > 100) {
			max = 100;
		}

		var width = 640;
		var height = 196;
		var padLeft = 48;
		var padRight = 14;
		var padTop = 14;
		var padBottom = 30;
		var plotWidth = width - padLeft - padRight;
		var plotHeight = height - padTop - padBottom;
		var lastIndex = Math.max(1, current.length - 1);

		var xFor = function(index) {
			return current.length === 1 ? padLeft + plotWidth / 2 : padLeft + (index * plotWidth) / lastIndex;
		};
		var yFor = function(value) {
			return padTop + plotHeight - ((value - min) / (max - min)) * plotHeight;
		};

		var grid = '';
		for (var tick = 0; tick <= 2; tick++) {
			var tickValue = min + ((max - min) * tick) / 2;
			var tickY = yFor(tickValue);
			grid += '<line class="ob-smart-insights-where-chart-grid" x1="' + padLeft + '" y1="' + tickY.toFixed(1) + '" x2="' + (width - padRight) + '" y2="' + tickY.toFixed(1) + '"></line>' +
				'<text class="ob-smart-insights-where-chart-axis" x="' + (padLeft - 6) + '" y="' + (tickY + 3.5).toFixed(1) + '" text-anchor="end">' + escapeHtml(formatWhereValue(tickValue, unit)) + '</text>';
		}

		var marker = '';
		var firstDetected = series.first_detected_at ? String(series.first_detected_at).slice(0, 10) : '';
		if (firstDetected) {
			for (var day = 0; day < current.length; day++) {
				if (String(current[day].date || '').slice(0, 10) === firstDetected) {
					var markerX = xFor(day);
					marker = '<line class="ob-smart-insights-where-chart-marker" x1="' + markerX.toFixed(1) + '" y1="' + padTop + '" x2="' + markerX.toFixed(1) + '" y2="' + (padTop + plotHeight) + '"></line>' +
						'<text class="ob-smart-insights-where-chart-marker-label" x="' + Math.min(markerX + 4, width - padRight - 60).toFixed(1) + '" y="' + (padTop + 10) + '">' + escapeHtml(i18n.whereFirstDetected || 'First detected') + '</text>';
					break;
				}
			}
		}

		var tooltipTemplate = i18n.whereChartTooltip || '%1$s: %2$s (%3$s sessions)';
		var hitWidth = plotWidth / Math.max(1, current.length);
		var hits = current.map(function(point, index) {
			var tooltip = fillToken(tooltipTemplate, '%1$s', String(point.date || ''));
			tooltip = fillToken(tooltip, '%2$s', formatWhereValue(point.value, unit));
			tooltip = fillToken(tooltip, '%3$s', formatNumber(point.sessions));
			return '<rect class="ob-smart-insights-where-chart-hit" x="' + Math.max(padLeft, xFor(index) - hitWidth / 2).toFixed(1) + '" y="' + padTop + '" width="' + hitWidth.toFixed(1) + '" height="' + plotHeight + '"><title>' + escapeHtml(tooltip) + '</title></rect>';
		}).join('');

		var previousPath = buildWhereChartPath(previous.slice(0, current.length), xFor, yFor);
		var currentPath = buildWhereChartPath(current, xFor, yFor);
		var segmentPath = buildWhereChartPath(segmentSeries.slice(0, current.length), xFor, yFor);

		var legend = '<p class="ob-smart-insights-where-chart-legend">' +
			'<span class="is-current">' + escapeHtml(i18n.whereChartCurrent || 'This period') + '</span>' +
			(previousPath ? '<span class="is-previous">' + escapeHtml(i18n.whereChartPrevious || 'Previous period') + '</span>' : '') +
			(segmentPath && scope ? '<span class="is-segment">' + escapeHtml(scope.label) + '</span>' : '') +
		'</p>';

		var chartTitle = fillToken(i18n.whereChartTitle || 'Daily %s', '%s', series.metric_label || series.metric || '');

		return '<h4 class="ob-smart-insights-where-chart-title">' + escapeHtml(chartTitle) + '</h4>' +
			'<svg class="ob-smart-insights-where-chart" viewBox="0 0 ' + width + ' ' + height + '" width="100%" height="' + height + '" role="img" aria-label="' + escapeHtml(chartTitle) + '" preserveAspectRatio="xMidYMid meet">' +
				grid +
				marker +
				(previousPath ? '<path class="ob-smart-insights-where-chart-line is-previous" d="' + previousPath + '"></path>' : '') +
				(segmentPath ? '<path class="ob-smart-insights-where-chart-line is-segment" d="' + segmentPath + '"></path>' : '') +
				'<path class="ob-smart-insights-where-chart-line is-current" d="' + currentPath + '"></path>' +
				'<text class="ob-smart-insights-where-chart-axis" x="' + padLeft + '" y="' + (height - 10) + '">' + escapeHtml(formatWhereChartDay(current[0].date)) + '</text>' +
				'<text class="ob-smart-insights-where-chart-axis" x="' + (width - padRight) + '" y="' + (height - 10) + '" text-anchor="end">' + escapeHtml(formatWhereChartDay(current[current.length - 1].date)) + '</text>' +
				hits +
			'</svg>' +
			legend;
	}

	// ---------------------------------------------------------------------
	// Loading and interaction.
	// ---------------------------------------------------------------------

	function getWhereState(content) {
		var panel = content ? content.querySelector('[data-ob-si-segments]') : null;
		return panel && panel.__obWhereState ? panel.__obWhereState : null;
	}

	function renderWhereSegmentsInto(content, insight) {
		var state = getWhereState(content);
		var target = content.querySelector('[data-ob-si-where-segments]');
		if (!state || !target) {
			return;
		}

		releasePortaledTooltips(target);
		target.innerHTML = renderWhereSegments(insight, state.matrix, activeSegmentScope);
		refreshIcons();
	}

	function renderWhereSeriesInto(content) {
		var state = getWhereState(content);
		var target = content.querySelector('[data-ob-si-where-series]');
		if (!state || !target) {
			return;
		}

		target.innerHTML = renderWhereChart(state.series, activeSegmentScope);
	}

	function loadWhereSeries(content, insight, section, scope) {
		var panel = content.querySelector('[data-ob-si-segments]');
		if (!panel) {
			return;
		}

		var payload = { insight_id: panel.getAttribute('data-ob-si-segments') };
		if (section) {
			payload.exclude_spam = getExcludeSpamFlag(section);
		}
		if (scope && scope.dimension && scope.key) {
			payload.segment_dimension = scope.dimension;
			payload.segment_key = scope.key;
		}

		return postAjax('optibehavior_smart_insights_timeseries', payload)
			.then(function(data) {
				var state = getWhereState(content);
				if (!state) {
					return;
				}
				state.series = data.timeseries || null;
				renderWhereSeriesInto(content);
			})
			.catch(function(error) {
				var target = content.querySelector('[data-ob-si-where-series]');
				if (target) {
					target.innerHTML = '<p class="description ob-smart-insights-where-chart-empty">' + escapeHtml(error.message || (i18n.whereChartError || 'Unable to load the daily trend.')) + '</p>';
				}
			});
	}

	function bindWhereSection(content, insight, section) {
		var panel = content.querySelector('[data-ob-si-segments]');
		if (!panel || panel.__obWhereBound) {
			return;
		}

		panel.__obWhereBound = true;
		panel.addEventListener('click', function(event) {
			var clearButton = event.target.closest('.ob-smart-insights-segment-clear');
			if (clearButton) {
				event.preventDefault();
				activeSegmentScope = null;
				applySegmentScopeToDetail(content, insight, null);
				renderWhereSegmentsInto(content, insight);
				loadWhereSeries(content, insight, section, null);
				return;
			}

			var bar = event.target.closest('.ob-smart-insights-where-bar');
			if (!bar) {
				return;
			}

			event.preventDefault();
			var dimension = bar.getAttribute('data-segment-dimension') || '';
			var key = bar.getAttribute('data-segment-key') || '';
			var isSame = !!(activeSegmentScope && activeSegmentScope.dimension === dimension && activeSegmentScope.key === key);
			activeSegmentScope = isSame ? null : {
				dimension: dimension,
				key: key,
				label: bar.getAttribute('data-segment-label') || key,
				reportKey: bar.getAttribute('data-report-key') || ''
			};

			applySegmentScopeToDetail(content, insight, activeSegmentScope);
			renderWhereSegmentsInto(content, insight);
			loadWhereSeries(content, insight, section, activeSegmentScope);
		});
	}

	function loadWhereSection(content, insight, section) {
		var panel = content.querySelector('[data-ob-si-segments]');
		if (!panel) {
			return;
		}

		panel.__obWhereState = { matrix: null, series: null };
		bindWhereSection(content, insight, section);

		var payload = { insight_id: panel.getAttribute('data-ob-si-segments') };
		if (section) {
			payload.exclude_spam = getExcludeSpamFlag(section);
		}

		postAjax('optibehavior_smart_insights_segments', payload)
			.then(function(data) {
				var state = getWhereState(content);
				if (!state) {
					return;
				}
				state.matrix = data.segments || null;
				renderWhereSegmentsInto(content, insight);
			})
			.catch(function(error) {
				var target = content.querySelector('[data-ob-si-where-segments]');
				if (target) {
					target.innerHTML = '<p class="description">' + escapeHtml(error.message || (i18n.segmentsError || 'Unable to load the affected segments.')) + '</p>';
				}
			});

		loadWhereSeries(content, insight, section, null);
	}

	function renderSegmentScopeStatus(scope) {
		if (!scope) {
			return '<p class="description ob-smart-insights-segment-scope-status">' + escapeHtml(i18n.segmentScopeHint || 'Select a segment to re-scope the evidence links below.') + '</p>';
		}

		var template = i18n.segmentScopeActive || 'Evidence links scoped to %s';
		return '<p class="description ob-smart-insights-segment-scope-status is-active">' +
			escapeHtml(template.replace('%s', scope.label)) +
			' <button type="button" class="button-link ob-smart-insights-segment-clear">' + escapeHtml(i18n.segmentScopeClear || 'Clear segment scope') + '</button>' +
		'</p>';
	}


	// Re-scoping is a presentation-time overlay: the segment is injected into a
	// copy of each related report so the existing link resolver carries it into
	// the destination report exactly like any other report-level context.
	function applySegmentScopeToReports(reports, scope) {
		if (!Array.isArray(reports) || !scope) {
			return Array.isArray(reports) ? reports : [];
		}

		return reports.map(function(report) {
			if (!report || typeof report !== 'object') {
				return report;
			}

			var clone = {};
			Object.keys(report).forEach(function(key) {
				clone[key] = report[key];
			});
			clone.segment_dimension = scope.dimension;
			clone.segment_key = scope.key;
			if (scope.reportKey === 'device') {
				clone.device = scope.key;
			} else if (scope.reportKey === 'source') {
				clone.source = scope.key;
			} else if (scope.reportKey === 'campaign') {
				clone.campaign = scope.key;
			}
			return clone;
		});
	}

	function applySegmentScopeToDetail(content, insight, scope) {
		var relatedSection = content.querySelector('.ob-smart-insights-detail-section.is-related');
		if (relatedSection) {
			releasePortaledTooltips(relatedSection);
			var reports = applySegmentScopeToReports(insight.related_reports, scope);
			var primaryReport = getBestRelatedReport(insight);
			var primaryReportUrl = primaryReport && primaryReport.resolved ? primaryReport.resolved.url : '';
			relatedSection.innerHTML = renderSectionHeading(i18n.relatedReports || 'Where to investigate', 'related_reports', { position: 'left' }) +
				renderRelatedReports(reports, insight, { primaryReportUrl: primaryReportUrl });
		}

		var evidenceSection = content.querySelector('[data-ob-si-evidence-refs]');
		if (evidenceSection) {
			releasePortaledTooltips(evidenceSection);
			var evidenceBody = renderEvidenceRefsBody(insight, scope) || renderEvidenceRefsLocked(insight);
			evidenceSection.innerHTML = renderSectionHeading(i18n.evidenceRefsTitle || 'Evidence and proof', 'evidence_refs') + evidenceBody;
		}

		var status = content.querySelector('.ob-smart-insights-segment-scope-status');
		if (status) {
			status.outerHTML = renderSegmentScopeStatus(scope);
		}

		var chips = content.querySelectorAll('[data-segment-dimension]');
		Array.prototype.forEach.call(chips, function(chip) {
			var isActive = !!(scope &&
				chip.getAttribute('data-segment-dimension') === scope.dimension &&
				chip.getAttribute('data-segment-key') === scope.key);
			chip.classList.toggle('is-active', isActive);
			chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
		});

		refreshIcons();
	}


	// The sidebar is capped at three actions. Which bullets survive (and whether
	// the Pro-context bullet is present at all) is decided server-side by the
	// capabilities layer, so this renderer never inspects the viewer's tier.
	function renderRecommendationsSection(insight) {
		var actions = Array.isArray(insight.recommended_actions) ? insight.recommended_actions : [];
		if (!actions.length) {
			return '';
		}

		return '<section class="ob-smart-insights-detail-section is-recommendations">' +
			renderSectionHeading(i18n.recommendedActions || i18n.recommendedAction || 'Recommended actions', 'recommendations', { position: 'left' }) +
			'<div class="ob-smart-insights-action-checklist">' + renderList(actions, MAX_RECOMMENDED_ACTIONS) + '</div>' +
		'</section>';
	}

	// Measured causes come from the correlation probes and carry a value the
	// viewer can check ("Rage clicks on this page: 41 sessions"). Only when there
	// is none do we fall back to the generic template list, collapsed so it never
	// competes with measured evidence.
	function renderMeasuredCauseList(insight) {
		var causes = getStoryCauses(insight);
		if (!causes.length) {
			return '';
		}

		var items = causes.map(function(cause) {
			var label = String((cause && cause.label) || '').trim();
			if (!label) {
				return '';
			}

			var parts = [];
			if (cause.value !== undefined && cause.value !== null && cause.value !== '') {
				var metricLabel = String(cause.metric_label || '').trim();
				var value = formatNumber(cause.value);
				parts.push(metricLabel ? value + ' ' + metricLabel : value);
			} else if (cause.sample_size) {
				parts.push(sprintfCount(i18n.causeSessions || '%s sessions', formatNumber(cause.sample_size)));
			}
			if (cause.share_pct !== undefined && cause.share_pct !== null && cause.share_pct !== '') {
				parts.push(sprintfCount(i18n.causeShareOfSessions || '%s of affected sessions', formatPercent(cause.share_pct)));
			}

			return '<li><strong>' + escapeHtml(label) + '</strong>' +
				(parts.length ? '<span>' + escapeHtml(parts.join(' · ')) + '</span>' : '') +
			'</li>';
		}).filter(Boolean);

		return items.length ? '<ul class="ob-smart-insights-measured-causes">' + items.join('') + '</ul>' : '';
	}

	function renderCausesSection(insight) {
		var title = renderSectionHeading(i18n.likelyCauses || 'Likely causes', 'causes', { position: 'left' });
		var measured = renderMeasuredCauseList(insight);
		if (measured) {
			return '<section class="ob-smart-insights-detail-section is-causes">' + title + measured + '</section>';
		}

		var generic = Array.isArray(insight.likely_causes) ? insight.likely_causes : [];
		if (!generic.length) {
			return '';
		}

		return '<section class="ob-smart-insights-detail-section is-causes">' + title +
			renderDisclosure(i18n.genericCausesToggle || 'Possible causes (generic)', renderList(generic), { className: 'ob-smart-insights-generic-causes-toggle', open: true }) +
		'</section>';
	}

	function renderDetail(insight) {
		var priorityLabel = getPriorityLabel(insight);
		var priorityClass = getSeverityClass(priorityLabel);
		var action = getRecommendedAction(insight);
		var title = insight.signal_name || (i18n.smartInsight || 'Smart Insight');
		var dateRange = getDetailDateRange(insight);
		var primaryReport = getBestRelatedReport(insight);
		var primaryReportUrl = primaryReport && primaryReport.resolved ? primaryReport.resolved.url : '';

		return '<div class="ob-smart-insights-detail">' +
			'<div class="ob-smart-insights-detail-topbar is-' + escapeHtml(priorityClass) + '" aria-hidden="true"></div>' +
			'<header class="ob-smart-insights-detail-header is-' + escapeHtml(priorityClass) + '">' +
				'<div class="ob-smart-insights-detail-header-copy">' +
					renderDetailHeaderChips(insight, priorityLabel, priorityClass) +
					'<h2 id="ob-smart-insights-modal-title" title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</h2>' +
					renderDetailSubtitle(insight, dateRange) +
				'</div>' +
				'<div class="ob-smart-insights-detail-header-actions">' +
					renderDetailPriorityMeter(insight, priorityLabel) +
					'<button type="button" class="button-link ob-smart-insights-modal-header-close" data-ob-smart-insights-close aria-label="' + escapeHtml(i18n.close || 'Close') + '">&times;</button>' +
				'</div>' +
			'</header>' +
			'<div class="ob-smart-insights-detail-grid ob-smart-insights-detail-body">' +
				'<main class="ob-smart-insights-detail-main">' +
					renderUpgradePreview(insight) +
					renderDetailStorySection(insight) +
					'<section class="ob-smart-insights-detail-section is-diagnosis">' + renderSectionHeading(i18n.diagnosis || 'Diagnosis', 'diagnosis') + '<div class="ob-smart-insights-detail-diagnosis-copy"><p>' + escapeHtml(insight.interpretation || '') + '</p>' + (insight.why_it_matters ? '<p class="ob-smart-insights-detail-why">' + escapeHtml(insight.why_it_matters) + '</p>' : '') + '</div></section>' +
					'<section class="ob-smart-insights-detail-section is-evidence">' + renderSectionHeading(i18n.evidence || 'Evidence', 'evidence') + '<div class="ob-smart-insights-evidence is-detail-evidence">' + renderDetailEvidence(insight, 7) + '</div></section>' +
					renderEvidenceRefsSection(insight, activeSegmentScope) +
					renderHypothesisSection(insight) +
					renderExperimentSection(insight) +
					renderWhereSection(insight) +
				'</main>' +
				'<aside class="ob-smart-insights-detail-side">' +
					'<section class="ob-smart-insights-detail-section is-context">' + renderSectionHeading(i18n.context || 'Context', 'context', { position: 'left' }) + renderDetailContextFacts(insight, dateRange) + '</section>' +
					renderDetailNextAction(insight, action) +
					'<section class="ob-smart-insights-detail-section is-related">' + renderSectionHeading(i18n.relatedReports || 'Where to investigate', 'related_reports', { position: 'left' }) + renderRelatedReports(insight.related_reports, insight, { primaryReportUrl: primaryReportUrl }) + '</section>' +
					renderRecommendationsSection(insight) +
					renderCausesSection(insight) +
				'</aside>' +
			'</div>' +
			renderDetailFooter(insight, dateRange, primaryReport) +
		'</div>';
	}


	function bindSection(section) {
		section.addEventListener('click', function(event) {
			var refreshButton = event.target.closest('.ob-smart-insights-refresh');
			if (refreshButton) {
				event.preventDefault();
				refreshSection(section);
				return;
			}

			var applyButton = event.target.closest('.ob-smart-insights-apply');
			if (applyButton) {
				event.preventDefault();
				loadSection(section, { autoRefreshIfStale: true });
				return;
			}

			var excludeSpamButton = event.target.closest('.ob-smart-insights-exclude-spam');
			if (excludeSpamButton) {
				event.preventDefault();
				var isActive = !(excludeSpamButton.classList.contains('active') || excludeSpamButton.getAttribute('aria-pressed') === 'true');
				setExcludeSpamFlag(section, isActive);
				syncActiveFilterState(section);
				loadWeeklySummary(section);
				refreshSection(section);
				return;
			}

			var resetButton = event.target.closest('.ob-smart-insights-reset');
			if (resetButton) {
				event.preventDefault();
				resetFilters(section);
				return;
			}

			var moreToggle = event.target.closest('.ob-smart-insights-center-more-toggle');
			if (moreToggle) {
				event.preventDefault();
				setCenterMoreExpanded(section, moreToggle.getAttribute('aria-expanded') !== 'true');
				return;
			}

			var childOpenButton = event.target.closest('[data-open-insight]');
			if (childOpenButton) {
				event.preventDefault();
				openDetail(section, childOpenButton.getAttribute('data-open-insight'));
				return;
			}

			var detailButton = event.target.closest('.ob-smart-insights-detail-btn');
			if (detailButton) {
				event.preventDefault();
				openDetail(section, detailButton.getAttribute('data-insight-id'));
				return;
			}

			var statusButton = event.target.closest('.ob-smart-insights-status-btn');
			if (statusButton) {
				event.preventDefault();
				handleStatus(section, statusButton);
			}
		});

		section.addEventListener('change', function(event) {
			var statusSelect = event.target.closest('.ob-smart-insights-status-select');
			if (statusSelect) {
				handleStatus(section, statusSelect);
			}
		});

		var search = section.querySelector('.ob-smart-insights-search');
		if (search) {
			var searchTimer = null;
			search.addEventListener('input', function() {
				window.clearTimeout(searchTimer);
				searchTimer = window.setTimeout(function() {
					syncActiveFilterState(section);
					loadSection(section);
				}, 250);
			});
		}

		var period = section.querySelector('.ob-smart-insights-period');
		if (period) {
			period.addEventListener('change', function() {
				toggleCustomDateInputs(section);
				syncActiveFilterState(section);
				loadWeeklySummary(section);
				loadSection(section, { autoRefreshIfStale: true });
			});
		}

		var status = section.querySelector('.ob-smart-insights-status');
		if (status) {
			status.addEventListener('change', function() {
				syncActiveFilterState(section);
				loadSection(section);
			});
		}

		var severity = section.querySelector('.ob-smart-insights-severity');
		if (severity) {
			severity.addEventListener('change', function() {
				syncActiveFilterState(section);
				loadSection(section);
			});
		}

		[
			'.ob-smart-insights-category',
			'.ob-smart-insights-entity-type',
			'.ob-smart-insights-confidence',
			'.ob-smart-insights-sort',
			'.ob-smart-insights-start-date',
			'.ob-smart-insights-end-date'
		].forEach(function(selector) {
			var element = section.querySelector(selector);
			if (element) {
				element.addEventListener('change', function() {
					syncActiveFilterState(section);
					loadWeeklySummary(section);
					loadSection(section, { autoRefreshIfStale: selector === '.ob-smart-insights-start-date' || selector === '.ob-smart-insights-end-date' });
				});
			}
		});

		toggleCustomDateInputs(section);
		updateExcludeSpamButton(section);
		syncActiveFilterState(section);
		if (pendingDeepOpenInsightId && (section.getAttribute('data-ob-smart-insights-context') || '') === 'center') {
			var statusSelect = section.querySelector('.ob-smart-insights-status');
			if (statusSelect) {
				statusSelect.value = '';
			}
		}
		syncNotificationsScope(section);
		loadWeeklySummary(section);
		loadSection(section, { autoRefreshIfStale: true });
	}

	ready(function() {
		var sections = document.querySelectorAll('[data-ob-smart-insights-context]');
		Array.prototype.forEach.call(sections, bindSection);
	});
})();
