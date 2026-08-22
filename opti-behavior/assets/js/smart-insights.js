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
	var pendingDeepOpenInsightId = parseInt(config.deepOpenInsightId || 0, 10) || 0;

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
			sort: 'timeline'
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
			data.sort = sortEl.value || 'timeline';
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
				query.utm_source = context.source;
			}
		}
		if (context.campaign) {
			query.campaign = context.campaign;
			query.utm_campaign = context.campaign;
		}
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
				query.referrer = context.source;
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
				if (hasAnyReportContext(context)) {
					query.si_context = '1';
					query.context = context.pageId || context.pageUrl ? 'page' : (context.device ? 'device' : (context.source ? 'source' : (context.campaign ? 'campaign' : 'insight')));
				}
				return { url: buildAdminReportUrl('opti-behavior-analytics', query), disabledReason: '' };
			case 'funnel':
			case 'funnel_analytics':
				if (context.funnelId) {
					query.funnel_id = context.funnelId;
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
				addRecordingSegmentAliases(query, context);
				if (context.formId && !query.page_id && !query.page_url && !query.contains_page && !query.device && !query.device_type && !query.source && !query.utm_source && !query.traffic_channel && !query.campaign && !query.utm_campaign) {
					return { url: '', disabledReason: i18n.recordingsFormFilterUnsupported || 'Recordings cannot be filtered by this form yet. Inspect the form analytics report for field-level evidence.' };
				}
				if (!query.page_id && !query.page_url && !query.contains_page && !query.device && !query.device_type && !query.source && !query.utm_source && !query.traffic_channel && !query.campaign && !query.utm_campaign) {
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
				if (context.formId && !query.page_id && !query.page_url && !query.url && !query.error_type) {
					return { url: '', disabledReason: i18n.errorsFormFilterUnsupported || 'Errors cannot be filtered by this form yet. Inspect form analytics for field errors tied to this form.' };
				}
				if (!query.page_id && !query.page_url && !query.url && !query.error_type) {
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
			'Revenue Opportunity': i18n.categoryRevenue || 'Revenue Opportunity'
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

	function sortInsightsForCenter(items) {
		var mode = arguments.length > 1 && arguments[1] ? arguments[1] : 'timeline';
		return items.slice().sort(function(a, b) {
			var dateDelta = getInsightTimestamp(b) - getInsightTimestamp(a);
			if (mode !== 'priority' && dateDelta !== 0) {
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
			if (mode === 'priority' && dateDelta !== 0) {
				return dateDelta;
			}
			return (parseInt(b.id, 10) || 0) - (parseInt(a.id, 10) || 0);
		});
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

	function renderCenterHeaderChips(insight, priorityLabel, priorityClass) {
		var title = insight.signal_name || '';
		var categoryLabel = getCategoryLabel(insight.category);
		var chips = [];

		if (categoryLabel && !textContainsMeaning(title, categoryLabel)) {
			chips.push('<span class="ob-smart-insights-category-chip">' + escapeHtml(categoryLabel) + '</span>');
		}

		chips.push('<span class="ob-smart-insights-status-chip is-' + escapeHtml(getSeverityClass(insight.status || 'new')) + '">' + escapeHtml(getStatusLabel(insight.status)) + '</span>');

		var recurrence = renderRecurrenceChip(insight);
		if (recurrence) {
			chips.push(recurrence);
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
		return '<section class="ob-smart-insights-detail-section is-next"><h3>' + escapeHtml(i18n.nextAction || 'Next action') + '</h3><div class="ob-smart-insights-action-callout"><span class="ob-smart-insights-action-icon" aria-hidden="true"><i data-lucide="arrow-up-right"></i><span class="ob-smart-insights-action-icon-fallback">↗</span></span><strong>' + escapeHtml(action) + '</strong></div></section>';
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

		return '<article class="ob-smart-insights-card" data-insight-id="' + escapeHtml(insight.id) + '">' +
			'<div class="ob-smart-insights-card-top">' +
				'<span class="ob-smart-insights-badge is-' + escapeHtml(priorityClass) + '">' + escapeHtml(priorityLabel) + '</span>' +
				'<span class="ob-smart-insights-score">' + escapeHtml(i18n.priority || 'Priority') + ': <strong>' + getPriorityScore(insight) + '/100</strong></span>' +
				renderRecurrenceChip(insight) +
				(insight.is_locked_preview ? '<span class="ob-smart-insights-badge is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>' : '') +
			'</div>' +
			'<h3>' + escapeHtml(insight.signal_name || (i18n.smartInsight || 'Smart Insight')) + '</h3>' +
			'<p class="ob-smart-insights-page-label"><span>' + escapeHtml(getCategoryLabel(insight.category)) + '</span> &middot; ' + renderEntityReference(insight) + '</p>' +
			'<div class="ob-smart-insights-meta">' +
				'<span>' + escapeHtml(i18n.confidence || 'Confidence') + ': <strong>' + escapeHtml(getConfidenceLabel(insight)) + '</strong></span>' +
				'<span>' + escapeHtml(getStatusLabel(insight.status)) + '</span>' +
				renderDetectedTime(insight) +
			'</div>' +
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

		if (!items.length) {
			list.innerHTML = renderEmptyState(data, getContextData(section));
			refreshIcons();
			maybeOpenDeepLinkedInsight(section);
			return;
		}

		list.innerHTML = '<div class="ob-smart-insights-center-list">' + items.map(renderCenterRow).join('') + '</div>';
		refreshIcons();
		maybeOpenDeepLinkedInsight(section);
	}

	function maybeOpenDeepLinkedInsight(section) {
		if (!pendingDeepOpenInsightId || !section || (section.getAttribute('data-ob-smart-insights-context') || '') !== 'center') {
			return;
		}

		var insightId = pendingDeepOpenInsightId;
		pendingDeepOpenInsightId = 0;

		var card = section.querySelector('[data-insight-id="' + insightId + '"]');
		if (card) {
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
			'.ob-smart-insights-sort': 'timeline'
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

	function renderCenterRow(insight) {
		var priorityLabel = getPriorityLabel(insight);
		var priorityClass = getSeverityClass(priorityLabel);
		var action = getRecommendedAction(insight);
		var title = insight.signal_name || (i18n.smartInsight || 'Smart Insight');
		var interpretation = getCompactCenterExplanation(insight, title);

		return '<article class="ob-smart-insights-center-card is-' + escapeHtml(priorityClass) + (insight.is_locked_preview ? ' is-locked-preview' : '') + '" data-insight-id="' + escapeHtml(insight.id) + '">' +
			'<span class="ob-smart-insights-severity-rail" aria-hidden="true"></span>' +
			'<div class="ob-smart-insights-center-main ob-smart-insights-primary-zone">' +
				'<div class="ob-smart-insights-title-row">' +
					'<h3 title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</h3>' +
				'</div>' +
				renderCenterHeaderChips(insight, priorityLabel, priorityClass) +
				renderCompactContextLine(insight) +
				(interpretation ? '<p class="ob-smart-insights-explanation">' + escapeHtml(interpretation) + '</p>' : '') +
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
		var nonDefaultSort = contextData.sort && contextData.sort !== 'timeline';
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
		modal.classList.remove('has-detail');
		content.innerHTML = '<div class="ob-smart-insights-loading"><span class="ob-smart-insights-spinner" aria-hidden="true"></span><span>' + escapeHtml(i18n.loading || 'Loading Smart Insights...') + '</span></div>';
		modal.hidden = false;
		document.body.classList.add('ob-smart-insights-modal-open');

		postAjax('optibehavior_smart_insights_detail', { insight_id: insightId })
			.then(function(data) {
				content.innerHTML = renderDetail(data.insight || {});
				modal.classList.add('has-detail');
				refreshIcons();
			})
			.catch(function(error) {
				modal.classList.remove('has-detail');
				content.innerHTML = '<div class="ob-smart-insights-empty"><h3>' + escapeHtml(error.message || (i18n.error || 'Unable to load Smart Insights.')) + '</h3></div>';
			});
	}

	function renderList(values) {
		if (!Array.isArray(values) || !values.length) {
			return '<p class="description">-</p>';
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

	function renderRelatedReportCardContent(item, state, insight) {
		var destination = getRelatedReportDestinationLabel(item.report, item.meta);
		var scope = getRelatedReportScopeLabel(item.report, insight);
		var action = item.meta.label && item.meta.label !== destination ? item.meta.label : state.label;
		var routeLabel = i18n.reportDestination || 'Report destination';

		return '<i data-lucide="' + escapeHtml(item.meta.icon) + '"></i>' +
			'<span class="ob-smart-insights-report-card-body">' +
				'<span class="ob-smart-insights-report-card-topline">' +
					'<strong>' + escapeHtml(destination) + '</strong>' +
					'<span class="ob-smart-insights-report-state ' + escapeHtml(state.badgeClass) + '">' + escapeHtml(state.label) + '</span>' +
				'</span>' +
				'<small class="ob-smart-insights-report-card-copy">' + escapeHtml(action) + '</small>' +
				'<small class="ob-smart-insights-report-card-status">' + escapeHtml(state.description) + '</small>' +
				'<span class="ob-smart-insights-report-card-meta"><span>' + escapeHtml(routeLabel) + '</span><span>' + escapeHtml(scope) + '</span></span>' +
				(item.resolved.url ? '<span class="ob-smart-insights-report-card-action">' + escapeHtml(i18n.openReport || 'Open report') + ' <span aria-hidden="true">&rarr;</span></span>' : '') +
			'</span>';
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

		var cards = Object.keys(byLabel).map(function(signature) {
			return byLabel[signature];
		}).sort(function(a, b) {
			if (a.isPrimary !== b.isPrimary) {
				return a.isPrimary ? -1 : 1;
			}
			return (b.score - a.score) || (a.index - b.index);
		}).map(function(item) {
			var state = getRelatedReportState(item);
			var content = renderRelatedReportCardContent(item, state, insight || {});
			var ariaLabel = getRelatedReportDestinationLabel(item.report, item.meta) + ': ' + item.meta.label + '. ' + state.label + '. ' + state.description;
			if (item.resolved.url) {
				return '<a class="ob-smart-insights-report-card ' + escapeHtml(state.className) + '" href="' + escapeHtml(item.resolved.url) + '" target="_blank" rel="noopener noreferrer"' + (item.isPrimary ? ' aria-current="page"' : '') + ' aria-label="' + escapeHtml(ariaLabel) + '">' + content + '</a>';
			}
			return '<div class="ob-smart-insights-report-card ' + escapeHtml(state.className) + '" role="group" aria-disabled="true" aria-label="' + escapeHtml(ariaLabel) + '">' + content + '</div>';
		});

		return cards.length ? '<div class="ob-smart-insights-report-card-list">' + cards.join('') + '</div>' : '<p class="description">' + escapeHtml(i18n.noRelatedReports || 'No related reports are available for this insight yet.') + '</p>';
	}

	function renderSummaryPriority(item) {
		if (!item || item.priority_score === undefined || item.priority_score === '') {
			return '';
		}

		var score = parseInt(item.priority_score, 10) || 0;
		var priorityClass = score >= 80 ? 'critical' : (score >= 60 ? 'high' : (score >= 40 ? 'medium' : 'low'));
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

	function renderTrendWatch(summary) {
		var movement = [];
		if (summary.biggest_decline) {
			movement.push(summary.biggest_decline);
		}
		if (summary.biggest_improvement) {
			movement.push(summary.biggest_improvement);
		}
		var recurring = Array.isArray(summary.recurring_issues) ? summary.recurring_issues : [];
		return '<section class="ob-smart-insights-briefing-section is-watch"><h3>' + escapeHtml(i18n.movement || 'Trend watch') + '</h3>' + renderSummaryList(movement, 1, { compact: true, hideLowValue: true, emptyText: i18n.noClearMover || 'No clear movement detected.' }) + '</section>' +
			'<section class="ob-smart-insights-briefing-section is-recurring"><h3>' + escapeHtml(i18n.recurringPatterns || 'Recurring patterns') + '</h3>' + renderSummaryList(recurring, 1, { compact: true }) + '</section>';
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
		return '<div class="ob-smart-insights-summary-report is-briefing">' +
			'<div class="ob-smart-insights-briefing-lead"><span>' + escapeHtml(i18n.analystBriefing || 'Analyst briefing') + '</span><strong>' + escapeHtml(headline) + '</strong></div>' +
			'<div class="ob-smart-insights-summary-metrics">' +
				'<span><small>' + escapeHtml(i18n.activeInsights || 'Active') + '</small><strong>' + escapeHtml(counts.active || 0) + '</strong></span>' +
				'<span><small>' + escapeHtml(i18n.highPriority || 'High priority') + '</small><strong>' + escapeHtml(counts.high_priority || 0) + '</strong></span>' +
				'<span><small>' + escapeHtml(i18n.recurring || 'Recurring') + '</small><strong>' + escapeHtml(counts.recurring || 0) + '</strong></span>' +
				'<span class="is-mover"><small>' + escapeHtml(i18n.biggestMover || 'Biggest mover') + '</small><strong title="' + escapeHtml(moverLabel) + '">' + escapeHtml(moverLabel) + '</strong></span>' +
			'</div>' +
			'<p class="description ob-smart-insights-summary-scope">' + escapeHtml(scopeNote) + '</p>' +
			'<div class="ob-smart-insights-recommendation ob-smart-insights-summary-next-action"><span>' + escapeHtml(i18n.recommendedAction || 'Recommended action') + '</span><strong>' + escapeHtml(next.action || next.summary || next.title || (i18n.defaultRecommendedAction || 'Review the highest-priority issue and open the supporting report.')) + '</strong>' + renderSummaryInsightLink(next) + '</div>' +
			'<div class="ob-smart-insights-briefing-grid">' +
				'<section class="ob-smart-insights-briefing-section is-priorities"><h3>' + escapeHtml(i18n.topPriorities || 'Top priorities') + '</h3>' + renderSummaryList(summary.top_critical_insights, 1, { compact: true }) + '</section>' +
				renderTrendWatch(summary) +
			'</div>' +
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


	function renderTrendComparison(trend, insight) {
		if (!trend || typeof trend !== 'object' || (Array.isArray(trend) && !trend.length) || (!Array.isArray(trend) && !Object.keys(trend).length)) {
			return renderBaselineContext(insight) || '<p class="description">' + escapeHtml(i18n.noTrendData || 'No previous-period trend data is available for this insight yet. Baseline context appears on the evidence tiles when available.') + '</p>';
		}

		var rows = Object.keys(trend).map(function(key) {
			var value = trend[key];
			if (!value || typeof value !== 'object') {
				var scalar = formatSegmentScalar(value);
				return scalar && scalar !== (i18n.unavailable || 'Unavailable') ? '<div class="ob-smart-insights-trend-item"><span>' + escapeHtml(prettifyKey(key)) + '</span><strong>' + escapeHtml(scalar) + '</strong></div>' : '';
			}
			var current = value.current !== undefined ? value.current : (value.current_value !== undefined ? value.current_value : null);
			var previous = value.previous !== undefined ? value.previous : (value.previous_value !== undefined ? value.previous_value : null);
			var delta = value.relative_delta_pct !== undefined ? value.relative_delta_pct : (value.delta_pct !== undefined ? value.delta_pct : null);
			var hasDelta = delta !== null && delta !== undefined && delta !== '' && !isNaN(parseFloat(delta));
			var hasPrevious = previous !== null && previous !== undefined && previous !== '' && !isNaN(parseFloat(previous));
			var hasCurrent = current !== null && current !== undefined && current !== '' && !isNaN(parseFloat(current));
			if (!hasDelta && !hasPrevious) {
				return '';
			}
			var headline = hasDelta ? formatSignedPercent(delta) : (i18n.baselineUnavailable || 'Baseline unavailable');
			var comparison = hasCurrent ? (i18n.current || 'Current') + ': ' + formatNumber(current) : '';
			comparison += hasPrevious ? (comparison ? ' ' + (i18n.vsBaseline || 'vs') + ' ' : '') + (i18n.previous || 'Previous') + ': ' + formatNumber(previous) : (comparison ? ' · ' : '') + (i18n.noPreviousData || 'No previous data');
			return '<div class="ob-smart-insights-trend-item' + (!hasDelta ? ' is-muted' : '') + '"><span>' + escapeHtml(prettifyKey(key)) + '</span><strong>' + escapeHtml(headline) + '</strong><small>' + escapeHtml(comparison) + '</small></div>';
		}).filter(Boolean).join('');

		if (!rows) {
			return renderBaselineContext(insight) || '<p class="description">' + escapeHtml(i18n.noTrendBaseline || 'No previous-period trend is available for this insight yet. Use the site baseline comparison in Evidence until enough previous-period data exists.') + '</p>';
		}

		return '<div class="ob-smart-insights-trend-grid">' + rows + '</div>';
	}

	function renderBaselineContext(insight) {
		if (!insight || insight.is_locked_preview) {
			return '';
		}
		var rows = collectEvidenceRows(insight, 7).filter(function(row) {
			return row.baseline && !row.isUnavailable;
		});
		if (!rows.length) {
			return '';
		}
		return '<div class="ob-smart-insights-trend-grid is-baseline-context">' + rows.slice(0, 4).map(function(row) {
			return '<div class="ob-smart-insights-trend-item"><span>' + escapeHtml(row.label) + '</span><strong>' + escapeHtml(row.value) + '</strong><small>' + escapeHtml((i18n.siteBaseline || 'Site baseline') + ': ' + row.baseline) + '</small></div>';
		}).join('') + '</div><p class="description">' + escapeHtml(i18n.previousTrendUnavailable || 'Previous-period trend is not available yet; these comparisons use the current site baseline.') + '</p>';
	}

	function formatSegmentScalar(value) {
		if (value === null || value === undefined || value === '') {
			return i18n.unavailable || 'Unavailable';
		}

		if (typeof value === 'number') {
			if (!isFinite(value)) {
				return i18n.unavailable || 'Unavailable';
			}
			if (Math.abs(value) <= 100 && String(value).indexOf('.') !== -1) {
				return value.toFixed(2);
			}
			return value.toLocaleString();
		}

		if (typeof value === 'boolean') {
			return value ? (i18n.yes || 'Yes') : (i18n.no || 'No');
		}

		if (typeof value === 'object') {
			if (Array.isArray(value)) {
				return value.map(formatSegmentScalar).filter(function(item) {
					return item && item !== (i18n.unavailable || 'Unavailable');
				}).join(', ') || (i18n.unavailable || 'Unavailable');
			}

			var parts = Object.keys(value).slice(0, 4).map(function(key) {
				return prettifyKey(key) + ': ' + formatSegmentScalar(value[key]);
			}).filter(function(item) {
				return item && item.indexOf(i18n.unavailable || 'Unavailable') === -1;
			});

			return parts.length ? parts.join(', ') : (i18n.unavailable || 'Unavailable');
		}

		return String(value);
	}

	function flattenSegmentRows(value, labelPrefix, rows) {
		if (value === null || value === undefined) {
			return;
		}

		if (Array.isArray(value)) {
			if (!value.length) {
				return;
			}

			value.forEach(function(item, index) {
				if (item && typeof item === 'object') {
					var itemLabel = item.label || item.name || item.key || item.segment || ('Item ' + (index + 1));
					if (item.value !== undefined && (typeof item.value !== 'object' || item.value === null)) {
						rows.push({ label: labelPrefix ? labelPrefix + ' / ' + itemLabel : itemLabel, value: formatSegmentScalar(item.value) });
					}
					Object.keys(item).forEach(function(key) {
						if (key === 'label' || key === 'name' || key === 'key' || key === 'segment' || key === 'value') {
							return;
						}
						flattenSegmentRows(item[key], (labelPrefix ? labelPrefix + ' / ' : '') + itemLabel + ' / ' + prettifyKey(key), rows);
					});
					return;
				}

				rows.push({
					label: labelPrefix ? labelPrefix + ' / ' + (index + 1) : 'Item ' + (index + 1),
					value: formatSegmentScalar(item)
				});
			});
			return;
		}

		if (typeof value === 'object') {
			Object.keys(value).forEach(function(key) {
				var nextLabel = labelPrefix ? labelPrefix + ' / ' + prettifyKey(key) : prettifyKey(key);
				flattenSegmentRows(value[key], nextLabel, rows);
			});
			return;
		}

		rows.push({ label: labelPrefix || (i18n.segmentBreakdown || 'Segment breakdown'), value: formatSegmentScalar(value) });
	}

	function isInternalSegmentRow(row) {
		var text = String((row && row.label) || '').toLowerCase();
		var value = String((row && row.value) || '').toLowerCase();
		var blocked = [
			'access',
			'features',
			'feature flags',
			'limits',
			'tier',
			'plan',
			'entitlement',
			'entitlements',
			'upgrade url',
			'upgrade_url',
			'upgrade',
			'download',
			'url',
			'has pro access',
			'has_pro_access',
			'pro context',
			'pro_context',
			'manifest',
			'manifest manager',
			'manifest_manager',
			'nonce',
			'license',
			'sodium',
			'capabilities',
			'raw',
			'internal',
			'implementation',
			'max recordings',
			'max_recordings',
			'recording limit',
			'recordings limit'
		];
		for (var i = 0; i < blocked.length; i++) {
			if (text.indexOf(blocked[i]) !== -1 || value.indexOf(blocked[i]) !== -1) {
				return true;
			}
		}
		return false;
	}

	function isAllowedSegmentLabel(label) {
		return /device|source|campaign|visitor|returning|new visitor|page|form|funnel|step|error|friction|performance|speed|load|path|journey|referrer|browser|country|region|conversion|checkout|cart/i.test(String(label || ''));
	}

	function formatSegmentLabel(label) {
		return String(label || (i18n.segment || 'Segment'))
			.replace(/\s*\/\s*/g, ' / ')
			.replace(/\bUrl\b/g, 'URL')
			.replace(/\bCta\b/g, 'CTA')
			.replace(/\bAb\b/g, 'A/B');
	}

	function collectHumanSegmentRows(value, labelPrefix, rows) {
		if (value === null || value === undefined) {
			return;
		}

		if (Array.isArray(value)) {
			value.forEach(function(item, index) {
				var itemLabel = item && typeof item === 'object'
					? (item.label || item.name || item.key || item.segment || ((i18n.item || 'Item') + ' ' + (index + 1)))
					: ((i18n.item || 'Item') + ' ' + (index + 1));
				collectHumanSegmentRows(item, labelPrefix ? labelPrefix + ' / ' + itemLabel : itemLabel, rows);
			});
			return;
		}

		if (typeof value === 'object') {
			Object.keys(value).forEach(function(key) {
				var keyLabel = prettifyKey(key);
				var nextLabel = labelPrefix ? labelPrefix + ' / ' + keyLabel : keyLabel;
				if (isInternalSegmentRow({ label: nextLabel, value: '' })) {
					return;
				}
				collectHumanSegmentRows(value[key], nextLabel, rows);
			});
			return;
		}

		var row = { label: labelPrefix || (i18n.segmentBreakdown || 'Segment breakdown'), value: formatSegmentScalar(value) };
		if (isAllowedSegmentLabel(row.label) && !isInternalSegmentRow(row) && row.value !== (i18n.unavailable || 'Unavailable') && row.value !== '') {
			rows.push(row);
		}
	}

	function renderSegmentBreakdown(insight) {
		var segment = insight.segment;
		if (insight.is_locked_preview) {
			return '<div class="ob-smart-insights-upgrade-preview"><span class="ob-smart-insights-badge is-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span><strong>' + escapeHtml(i18n.segmentLockedTitle || 'Advanced segment insight available in Pro') + '</strong><p>' + escapeHtml(i18n.segmentLockedBody || 'Upgrade to unlock device/source breakdowns, related session recordings, and advanced recommendations.') + '</p></div>';
		}
		if (!segment || typeof segment !== 'object' || (Array.isArray(segment) && !segment.length) || (!Array.isArray(segment) && !Object.keys(segment).length)) {
			return '<p class="description">' + escapeHtml(i18n.noSegmentData || 'No segment breakdown is available for this insight yet.') + '</p>';
		}

		var rows = [];
		collectHumanSegmentRows(segment, '', rows);
		rows = rows.filter(function(row, index, allRows) {
			var signature = String(row.label || '') + '::' + String(row.value || '');
			return allRows.findIndex(function(compareRow) {
				return String(compareRow.label || '') + '::' + String(compareRow.value || '') === signature;
			}) === index;
		});
		if (!rows.length) {
			return '<p class="description">' + escapeHtml(i18n.noSegmentData || 'No segment breakdown is available for this insight yet.') + '</p>';
		}

		return '<div class="ob-smart-insights-segment-grid">' + rows.slice(0, 12).map(function(row) {
			return '<div class="ob-smart-insights-segment-item"><span>' + escapeHtml(formatSegmentLabel(row.label)) + '</span><strong>' + escapeHtml(row.value || (i18n.unavailable || 'Unavailable')) + '</strong></div>';
		}).join('') + '</div>';
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
					'<section class="ob-smart-insights-detail-section is-diagnosis"><h3>' + escapeHtml(i18n.diagnosis || 'Diagnosis') + '</h3><div class="ob-smart-insights-detail-diagnosis-copy"><p>' + escapeHtml(insight.interpretation || '') + '</p>' + (insight.why_it_matters ? '<p class="ob-smart-insights-detail-why">' + escapeHtml(insight.why_it_matters) + '</p>' : '') + '</div></section>' +
					'<section class="ob-smart-insights-detail-section is-evidence"><h3>' + escapeHtml(i18n.evidence || 'Evidence') + '</h3><div class="ob-smart-insights-evidence is-detail-evidence">' + renderDetailEvidence(insight, 7) + '</div></section>' +
					'<section class="ob-smart-insights-detail-section is-trend"><h3>' + escapeHtml(i18n.trendComparison || 'Trend comparison') + '</h3>' + renderTrendComparison(insight.trend, insight) + '</section>' +
					'<section class="ob-smart-insights-detail-section is-segments"><h3>' + escapeHtml(i18n.segmentBreakdown || 'Segments') + '</h3>' + renderSegmentBreakdown(insight) + '</section>' +
				'</main>' +
				'<aside class="ob-smart-insights-detail-side">' +
					'<section class="ob-smart-insights-detail-section is-context"><h3>' + escapeHtml(i18n.context || 'Context') + '</h3>' + renderDetailContextFacts(insight, dateRange) + '</section>' +
					renderDetailNextAction(insight, action) +
					'<section class="ob-smart-insights-detail-section is-related"><h3>' + escapeHtml(i18n.relatedReports || 'Where to investigate') + '</h3>' + renderRelatedReports(insight.related_reports, insight, { primaryReportUrl: primaryReportUrl }) + '</section>' +
					'<section class="ob-smart-insights-detail-section is-recommendations"><h3>' + escapeHtml(i18n.recommendedActions || i18n.recommendedAction || 'Recommended actions') + '</h3><div class="ob-smart-insights-action-checklist">' + renderList(insight.recommended_actions) + '</div></section>' +
					'<section class="ob-smart-insights-detail-section is-causes"><h3>' + escapeHtml(i18n.likelyCauses || 'Likely causes') + '</h3>' + renderList(insight.likely_causes) + '</section>' +
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
