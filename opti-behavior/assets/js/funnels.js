/**
 * Funnel Analytics JavaScript - A/B-style list view
 *
 * Funnels render as a paginated list of collapsed rows with per-funnel KPI
 * badges. The GLOBAL top bar holds only Status + Search. Period, Device AND
 * Country are PER-FUNNEL and fully independent (changing funnel A never touches
 * funnel B), keyed by funnelId in funnelStateById / selectedCountriesByFunnel.
 * Each row's badges + expanded detail reflect THAT funnel's own selection; the
 * top summary aggregates the sum of every funnel's current selection.
 *
 * @package opti-behavior
 * @version 4.0.0
 */

(function($) {
	'use strict';

	// i18n helper – pull localized strings from PHP with English fallbacks
	const _s = (window.optiBehaviorFunnels && window.optiBehaviorFunnels.strings) || {};
	function funnelMatchOptions(selected) {
		const opts = [
			{ v: 'any',        l: _s.anyPage       || 'Any Page' },
			{ v: 'exact',      l: _s.exactUrl       || 'Exact URL' },
			{ v: 'contains',   l: _s.urlContains    || 'URL Contains' },
			{ v: 'starts_with',l: _s.urlStartsWith  || 'URL Starts With' },
			{ v: 'ends_with',  l: _s.urlEndsWith    || 'URL Ends With' },
			{ v: 'regex',      l: _s.regex           || 'Regex' },
			{ v: 'pageview',   l: _s.anyPageView     || 'Any Page View' },
		];
		return opts.map(o => `<option value="${o.v}"${selected === o.v ? ' selected' : ''}>${o.l}</option>`).join('');
	}

	// =========================================================================
	// State
	// =========================================================================

	// GLOBAL filter state — only Status + Search live in the top bar now.
	let globalStatus = 'active';
	let globalSearch = '';

	// PER-FUNNEL filter state, keyed by funnelId and fully independent. Period +
	// Device + Country each belong to a single funnel; changing funnel A never
	// touches funnel B. Defaults: 7 Days / All Devices / All Countries.
	let funnelStateById = {};
	let selectedCountriesByFunnel = {};

	// Detail-page URL for a funnel (spec.md §4.1): the index list links each row
	// to its own `&funnel=<id>` detail view instead of expanding inline.
	function funnelDetailUrl(funnelId) {
		const base = (window.optiBehaviorFunnels && optiBehaviorFunnels.pageUrl) || 'admin.php?page=opti-behavior-funnels';
		const sep = base.indexOf('?') === -1 ? '?' : '&';
		return base + sep + 'funnel=' + encodeURIComponent(funnelId);
	}

	function getFunnelState(funnelId) {
		const key = String(funnelId);
		if (!funnelStateById[key]) {
			funnelStateById[key] = {
				period: '7days',
				device: 'all',
				customStart: '',
				customEnd: '',
				customStartDateTime: '',
				customEndDateTime: ''
			};
		}
		return funnelStateById[key];
	}

	// =========================================================================
	// Funnel step → Session Recording deep-link
	// =========================================================================

	function formatLocalDate(d) {
		const y = d.getFullYear();
		const m = String(d.getMonth() + 1).padStart(2, '0');
		const day = String(d.getDate()).padStart(2, '0');
		return y + '-' + m + '-' + day;
	}

	// Resolve the funnel card's current period to explicit start/end dates so the
	// recordings cohort matches exactly what the card shows (LOCKED #3).
	function getReplayDateParams(funnelId) {
		const state = getFunnelState(funnelId);
		const periodDays = { '7days': 6, '30days': 29, '90days': 89 };
		if (state.period === 'custom') {
			return { startDate: state.customStart || '', endDate: state.customEnd || '' };
		}
		if (Object.prototype.hasOwnProperty.call(periodDays, state.period)) {
			const end = new Date();
			const start = new Date();
			start.setDate(end.getDate() - periodDays[state.period]);
			return { startDate: formatLocalDate(start), endDate: formatLocalDate(end) };
		}
		return { startDate: '', endDate: '' };
	}

	// Build the recordings deep-link for a step's drop-off cohort. Scope is always
	// "abandoned" (LOCKED #1). Returns '' when no recordings base URL is available.
	function buildStepReplayUrl(funnelId, stepNumber) {
		const base = (window.optiBehaviorFunnels && optiBehaviorFunnels.recordingsBaseUrl) || '';
		if (!base) {
			return '';
		}
		const dates = getReplayDateParams(funnelId);
		let url = base
			+ '&funnel_id=' + encodeURIComponent(funnelId)
			+ '&funnel_step=' + encodeURIComponent(stepNumber)
			+ '&funnel_scope=abandoned';
		if (dates.startDate && dates.endDate) {
			url += '&date_range=custom'
				+ '&start_date=' + encodeURIComponent(dates.startDate)
				+ '&end_date=' + encodeURIComponent(dates.endDate);
		}
		return url;
	}

	// Build the per-step replay icon markup. The cohort is the step's "Abandoned"
	// count (visitors who never reached this step), so the icon is only rendered
	// when abandoned > 0 — nobody to show otherwise (e.g. step 1 / entry).
	// When abandoned > 0: Pro recordings active → deep-link to the cohort;
	// otherwise → upsell page (LOCKED #4).
	// topPercent positions the icon at the vertical centre of the step bar's
	// dropout band (the light/abandoned region above the completed fill), so it
	// sits inside .funnel-bar-container as an absolutely-positioned overlay.
	function buildStepReplayIcon(funnelId, stepNumber, abandoned, topPercent) {
		if (!(Number(abandoned) > 0)) {
			return '';
		}
		const topStyle = ' style="top:' + topPercent + '%;"';
		const replayUrl = buildStepReplayUrl(funnelId, stepNumber);
		if (replayUrl) {
			const label = escapeHtml(_s.replayWatch || 'Watch replays of visitors who did not reach this step');
			// aria-label carries the accessible text; the visible bubble is decorative
			// (aria-hidden) so SR users don't hear it twice. label already escaped.
			return '<a class="funnel-step-replay"' + topStyle + ' href="' + escapeHtml(replayUrl) + '" aria-label="' + label + '"><i data-lucide="play-circle"></i><span class="funnel-step-replay__tip" aria-hidden="true">' + label + '</span></a>';
		}
		const upsellUrl = (window.optiBehaviorFunnels && optiBehaviorFunnels.recordingsUpsellUrl) || '#';
		const upsellLabel = escapeHtml(_s.replayUpsell || 'Upgrade to watch session replays of visitors who dropped here');
		return '<a class="funnel-step-replay funnel-step-replay--upsell"' + topStyle + ' href="' + escapeHtml(upsellUrl) + '" aria-label="' + upsellLabel + '"><i data-lucide="play-circle"></i><span class="funnel-step-replay__tip" aria-hidden="true">' + upsellLabel + '</span></a>';
	}

	// Ids of funnels whose detail view is currently expanded. Tracked so a list
	// re-render (search / pagination / status / global period+device change)
	// re-opens them and reloads their data against the new global filters.
	const expandedFunnelIds = new Set();

	// List pagination + data cache.
	let currentPage = 1;
	const PER_PAGE = 8;
	let summaryRows = []; // [{ id, name, description, step_count, entries, completions, conversion_rate, dropoff_rate }]

	// Custom-range modal pending callbacks (single shared modal).
	let pendingCustomApply = null;
	let pendingCustomCancel = null;

	const smartInsightsFunnelContext = getSmartInsightsFunnelContext();
	let smartInsightsHandled = false;

	// =========================================================================
	// Smart Insights deep-link context
	// =========================================================================

	function normalizeSmartInsightsFunnelPeriod(value) {
		const key = String(value || '').toLowerCase().replace(/[^a-z0-9_]/g, '');
		const map = {
			last_7_days: '7days', last7days: '7days', '7days': '7days',
			last_30_days: '30days', last30days: '30days', '30days': '30days',
			last_90_days: '90days', last90days: '90days', '90days': '90days',
			custom: 'custom'
		};
		return map[key] || '';
	}

	function normalizeSmartInsightsDate(value) {
		const match = String(value || '').trim().match(/^(\d{4}-\d{2}-\d{2})/);
		return match ? match[1] : '';
	}

	function normalizeSmartInsightsDateTime(value) {
		const normalized = String(value || '').trim().replace('T', ' ');
		const match = normalized.match(/^(\d{4}-\d{2}-\d{2})(?:\s+(\d{2}:\d{2}(?::\d{2})?))?/);
		if (!match || !match[2]) {
			return '';
		}
		return `${match[1]} ${match[2].length === 5 ? match[2] + ':00' : match[2]}`;
	}

	function getSmartInsightsFunnelContext() {
		const params = new URLSearchParams(window.location.search);
		const cohortStartAt = normalizeSmartInsightsDateTime(params.get('cohort_start_at'));
		const cohortEndAt = normalizeSmartInsightsDateTime(params.get('cohort_end_at'));
		const startDate = normalizeSmartInsightsDate(cohortStartAt || params.get('start_date') || params.get('date_from'));
		const endDate = normalizeSmartInsightsDate(cohortEndAt || params.get('end_date') || params.get('date_to'));
		let period = normalizeSmartInsightsFunnelPeriod(params.get('date_range') || params.get('period'));
		if (cohortStartAt && cohortEndAt) {
			period = 'custom';
		} else if ((!period || period === 'custom') && startDate && endDate) {
			period = 'custom';
		}
		return {
			funnelId: params.get('funnel_id') || '',
			funnelStep: params.get('funnel_step') || '',
			device: params.get('device') || params.get('device_type') || '',
			source: params.get('source') || '',
			campaign: params.get('campaign') || '',
			period: period,
			startDate: startDate,
			endDate: endDate,
			cohortStartAt: cohortStartAt,
			cohortEndAt: cohortEndAt,
			excludeSpam: params.has('exclude_spam') ? (params.get('exclude_spam') === '1' ? '1' : '0') : ''
		};
	}

	function applySmartInsightsFunnelDefaults() {
		const ctx = smartInsightsFunnelContext;
		if (!ctx.funnelId) {
			return;
		}
		// Seed ONLY the deep-linked funnel's own per-funnel state so its row +
		// detail open against the Smart Insights cohort without touching others.
		const state = getFunnelState(ctx.funnelId);
		if (ctx.period) {
			state.period = ctx.period;
		}
		if (ctx.period === 'custom') {
			state.customStart = ctx.startDate;
			state.customEnd = ctx.endDate;
			state.customStartDateTime = ctx.cohortStartAt;
			state.customEndDateTime = ctx.cohortEndAt;
		}
		const validDevices = ['all', 'desktop', 'mobile', 'tablet'];
		// Deep links carry the stored column casing ("Mobile"); the funnel state
		// enum is lower-case, so match on the normalized form.
		const device = String(ctx.device || '').trim().toLowerCase();
		if (device && validDevices.indexOf(device) !== -1) {
			state.device = device;
		}
	}

	// URL param => funnel advanced-filters field, for links that arrive already
	// scoped to a segment. Only fields the funnel query really filters by are
	// read; everything else stays context-only.
	const FUNNEL_INCOMING_FILTER_PARAMS = [
		['device_type', 'device_type'],
		['browser', 'browser'],
		['country', 'country'],
		['os', 'os'],
		['utm_source', 'utm_source'],
		['utm_campaign', 'utm_campaign'],
		['utm_medium', 'utm_medium'],
		['traffic_channel', 'traffic_channel'],
		['visitor_type', 'visitor_type'],
		['referrer', 'referrer'],
		['entry_page', 'entry_page']
	];

	function readFunnelIncomingFilterScope() {
		const scope = {};
		let params;
		try {
			params = new URLSearchParams(window.location.search);
		} catch (e) {
			return scope;
		}
		FUNNEL_INCOMING_FILTER_PARAMS.forEach(function(pair) {
			const value = ('' + (params.get(pair[0]) || '')).trim();
			// Internal segment keys ("utm:google") are never column values.
			if (value === '' || /^(utm|referrer|campaign):/i.test(value)) return;
			scope[pair[1]] = value;
		});
		return scope;
	}

	// =========================================================================
	// Label helpers (operate on a state object)
	// =========================================================================

	function periodLabel(period) {
		if (period === 'custom') return _s.customRange || 'Custom Range';
		if (period === '30days') return _s.thirtyDays || '30 Days';
		if (period === '90days') return _s.ninetyDays || '90 Days';
		return _s.sevenDays || '7 Days';
	}

	function deviceLabel(device) {
		if (device === 'desktop') return _s.desktop || 'Desktop';
		if (device === 'mobile') return _s.mobile || 'Mobile';
		if (device === 'tablet') return _s.tablet || 'Tablet';
		return _s.allDevices || 'All Devices';
	}

	// Lucide icon name per device — mirrors the User Journey device filter
	// (desktop=monitor, mobile=smartphone, tablet=tablet, all=layers).
	function deviceIcon(device) {
		if (device === 'desktop') return 'monitor';
		if (device === 'mobile') return 'smartphone';
		if (device === 'tablet') return 'tablet';
		return 'layers';
	}

	// Sync a funnel card's Period trigger label to its own state.
	function updatePeriodLabelForFunnel(funnelId) {
		const state = getFunnelState(funnelId);
		$('.funnel-card[data-funnel-id="' + funnelId + '"] .funnel-period-label').text(periodLabel(state.period));
	}

	// Sync a funnel card's Device trigger icon + label to its own state.
	function updateDeviceDisplayForFunnel(funnelId) {
		const state = getFunnelState(funnelId);
		const $card = $('.funnel-card[data-funnel-id="' + funnelId + '"]');
		$card.find('.funnel-device-label').text(deviceLabel(state.device));
		$card.find('.funnel-device-icon').attr('data-lucide', deviceIcon(state.device));
	}

	// =========================================================================
	// Country selection (per-funnel — unchanged isolation pattern)
	// =========================================================================

	function getSelectedCountries(funnelId) {
		return normalizeCountryList(selectedCountriesByFunnel[funnelId] || []);
	}

	function setSelectedCountries(funnelId, countries) {
		selectedCountriesByFunnel[funnelId] = normalizeCountryList(countries);
	}

	function normalizeCountryCode(country) {
		const code = String(country || '').trim().toUpperCase();
		return /^[A-Z]{2}$/.test(code) ? code : '';
	}

	function normalizeCountryList(countries) {
		const values = Array.isArray(countries) ? countries : [countries];
		const uniqueCountries = [];
		values.forEach(function(value) {
			String(value || '').split(',').forEach(function(token) {
				const code = normalizeCountryCode(token);
				if (code && !uniqueCountries.includes(code)) {
					uniqueCountries.push(code);
				}
			});
		});
		return uniqueCountries;
	}

	// =========================================================================
	// Exclude-spam flag
	// =========================================================================

	// Per-user persistence for the exclude-spam toggle. The flag used to live in
	// the URL only (history.replaceState), so any navigation back to the bare
	// ?page=opti-behavior-funnels URL silently reverted it to the site default
	// (QA-B-FUNNEL-056). The stored preference is a display filter, never a
	// security boundary, so localStorage is the right home for it.
	const EXCLUDE_SPAM_STORAGE_KEY = 'optiBehaviorFunnelsExcludeSpam';

	function readStoredExcludeSpamFlag() {
		try {
			const stored = window.localStorage.getItem(EXCLUDE_SPAM_STORAGE_KEY);
			return (stored === '1' || stored === '0') ? stored : null;
		} catch (e) {
			return null;
		}
	}

	function writeStoredExcludeSpamFlag(flag) {
		try {
			window.localStorage.setItem(EXCLUDE_SPAM_STORAGE_KEY, flag);
		} catch (e) {
			// Private mode / storage disabled: the URL param still carries the
			// flag for the rest of this page's life.
		}
	}

	function getExcludeSpamFlag() {
		const params = new URLSearchParams(window.location.search);
		if (params.has('exclude_spam')) {
			return params.get('exclude_spam') === '1' ? '1' : '0';
		}
		const stored = readStoredExcludeSpamFlag();
		if (stored !== null) {
			return stored;
		}
		const root = document.querySelector('.opti-behavior-funnels-page');
		if (root && root.getAttribute('data-exclude-spam') !== null) {
			return root.getAttribute('data-exclude-spam') === '1' ? '1' : '0';
		}
		if (window.optiBehaviorCurrentExcludeSpamFlag) {
			return window.optiBehaviorCurrentExcludeSpamFlag();
		}
		return '1';
	}

	function setExcludeSpamFlag(value) {
		const flag = value === '1' ? '1' : '0';
		writeStoredExcludeSpamFlag(flag);
		const root = document.querySelector('.opti-behavior-funnels-page');
		const button = document.getElementById('funnels-exclude-spam-toggle');
		if (root) {
			root.setAttribute('data-exclude-spam', flag);
		}
		if (button) {
			button.classList.toggle('active', flag === '1');
			button.setAttribute('aria-pressed', flag === '1' ? 'true' : 'false');
			const icon = button.querySelector('i[data-lucide]');
			if (icon) {
				icon.setAttribute('data-lucide', flag === '1' ? 'shield-check' : 'shield-off');
			}
		}
		const url = new URL(window.location.href);
		url.searchParams.set('exclude_spam', flag);
		window.history.replaceState({}, '', url.toString());
		if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
			lucide.createIcons();
		}
	}

	// Reconcile the server-rendered toggle state with the stored preference.
	function applyStoredExcludeSpamFlag() {
		const params = new URLSearchParams(window.location.search);
		if (params.has('exclude_spam')) {
			// The URL is explicit: treat it as the user's latest choice.
			writeStoredExcludeSpamFlag(params.get('exclude_spam') === '1' ? '1' : '0');
			return;
		}

		const stored = readStoredExcludeSpamFlag();
		if (stored === null) {
			return;
		}

		const root = document.querySelector('.opti-behavior-funnels-page');
		const rendered = (root && root.getAttribute('data-exclude-spam') === '1') ? '1' : '0';
		if (stored !== rendered) {
			setExcludeSpamFlag(stored);
		}
	}

	// =========================================================================
	// Formatters / utils
	// =========================================================================

	function formatNumber(num) {
		if (!num) return '0';
		return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function formatNumberShort(num) {
		if (!num) return '0';
		if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
		if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
		return num.toString();
	}

	function escapeHtml(text) {
		const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}

	function debounce(fn, wait) {
		let t;
		return function() {
			const ctx = this, args = arguments;
			clearTimeout(t);
			t = setTimeout(function() { fn.apply(ctx, args); }, wait);
		};
	}

	function refreshIcons() {
		if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
			lucide.createIcons();
		}
	}

	// =========================================================================
	// Initialization
	// =========================================================================

	function initFunnelAnalytics() {
		applySmartInsightsFunnelDefaults();
		setExcludeSpamFlag(getExcludeSpamFlag());
		setupEventListeners();
	}

	function setupEventListeners() {
		// ---- GLOBAL filter bar (status + search only) ----

		// ---- Per-funnel Period dropdown (independent per funnel) ----
		$(document).on('click', '.funnel-period-btn', function(e) {
			e.stopPropagation();
			const $dropdown = $(this).siblings('.funnel-period-dropdown');
			$('.period-dropdown').not($dropdown).hide();
			$dropdown.toggle();
		});
		$(document).on('click', '.funnel-period-dropdown .period-option', function() {
			const funnelId = $(this).closest('.funnel-card').data('funnel-id');
			const period = $(this).data('period');
			const state = getFunnelState(funnelId);
			$(this).closest('.period-dropdown').hide();
			if (period === 'custom') {
				openCustomRangeModal(state.customStart, state.customEnd, function(start, end) {
					state.period = 'custom';
					state.customStart = start;
					state.customEnd = end;
					state.customStartDateTime = '';
					state.customEndDateTime = '';
					updatePeriodLabelForFunnel(funnelId);
					applyFunnelFilterChange(funnelId);
				}, function() {});
				return;
			}
			state.period = period;
			updatePeriodLabelForFunnel(funnelId);
			applyFunnelFilterChange(funnelId);
		});

		// ---- Per-funnel Device dropdown (independent per funnel) ----
		$(document).on('click', '.funnel-device-btn', function(e) {
			e.stopPropagation();
			const $dropdown = $(this).siblings('.device-dropdown');
			$('.period-dropdown').not($dropdown).hide();
			$dropdown.toggle();
		});
		$(document).on('click', '.device-dropdown .period-option', function() {
			const funnelId = $(this).closest('.funnel-card').data('funnel-id');
			const device = $(this).data('device');
			const state = getFunnelState(funnelId);
			state.device = device;
			$(this).closest('.period-dropdown').find('.period-option').removeClass('active');
			$(this).addClass('active');
			$(this).closest('.period-dropdown').hide();
			updateDeviceDisplayForFunnel(funnelId);
			refreshIcons();
			applyFunnelFilterChange(funnelId);
		});

		$('#opti-funnel-filter-status').on('change', function() {
			globalStatus = $(this).val();
			currentPage = 1;
			applyFilterAndRender();
		});

		$('#opti-funnel-search').on('input', debounce(function() {
			globalSearch = $(this).val();
			currentPage = 1;
			applyFilterAndRender();
		}, 250));

		// List pagination.
		$(document).on('click', '.opti-funnel-pagination__btn', function() {
			if ($(this).is(':disabled') || $(this).hasClass('is-active')) return;
			const page = parseInt($(this).data('page'), 10);
			if (page) {
				currentPage = page;
				applyFilterAndRender();
			}
		});

		// Exclude spam toggle — reload summary (period/device unchanged).
		$(document).on('click', '#funnels-exclude-spam-toggle', function(e) {
			e.preventDefault();
			setExcludeSpamFlag(getExcludeSpamFlag() === '1' ? '0' : '1');
			currentPage = 1;
			loadFunnelsSummary();
		});

		// ---- Row → detail navigation (spec.md §4.1) ----
		// The whole summary row navigates to the funnel's own detail URL; the
		// actions cluster (kebab menu, edit/delete) is excluded so those still
		// work in place. The "View details" anchor navigates natively.
		$(document).on('click', '.opti-funnel-row__summary', function(e) {
			if ($(e.target).closest('.opti-funnel-row__actions, .funnel-more-container').length) {
				return;
			}
			const funnelId = $(this).closest('.funnel-card').data('funnel-id');
			if (funnelId) {
				window.location.href = funnelDetailUrl(funnelId);
			}
		});

		// ---- Per-funnel Country dropdown (Period + Device handled above) ----
		$(document).on('click', '.funnel-country-btn', function(e) {
			e.stopPropagation();
			const $dropdown = $(this).siblings('.country-dropdown');
			$('.period-dropdown').not($dropdown).hide();
			$dropdown.toggle();
		});
		$(document).on('click', '.country-dropdown', function(e) {
			e.stopPropagation();
		});

		$(document).on('input', '.country-search-input', function() {
			const searchTerm = $(this).val().toLowerCase();
			const $container = $(this).closest('.country-dropdown').find('.country-options-container');
			$container.find('.country-option-item').each(function() {
				const countryName = $(this).find('.country-name').text().toLowerCase();
				$(this).toggle(countryName.includes(searchTerm));
			});
		});

		$(document).on('change', '.country-checkbox', function() {
			const countryCode = normalizeCountryCode($(this).data('country'));
			const funnelId = $(this).closest('.funnel-card').data('funnel-id');
			let countries = getSelectedCountries(funnelId);
			if (!countryCode) return;
			if ($(this).is(':checked')) {
				if (!countries.includes(countryCode)) countries.push(countryCode);
			} else {
				countries = countries.filter(c => c !== countryCode);
			}
			setSelectedCountries(funnelId, countries);
		});

		$(document).on('click', '.country-clear-btn', function() {
			const $dropdown = $(this).closest('.country-dropdown');
			const funnelId = $(this).closest('.funnel-card').data('funnel-id');
			$dropdown.find('.country-checkbox').prop('checked', false);
			setSelectedCountries(funnelId, []);
		});

		$(document).on('click', '.country-apply-btn', function() {
			const $dropdown = $(this).closest('.country-dropdown');
			const funnelId = $(this).closest('.funnel-card').data('funnel-id');
			updateCountryLabelForFunnel(funnelId);
			$dropdown.hide();
			loadFunnelAnalytics(funnelId);
		});

		// Close any dropdown when clicking outside.
		$(document).on('click', function(e) {
			if (!$(e.target).closest('.funnel-control-btn, .period-dropdown, .funnel-control-wrapper').length) {
				$('.period-dropdown').hide();
			}
		});

		// ---- Shared custom-range modal handlers ----
		$(document).on('click', '#close-custom-range, #cancel-custom-range, .custom-range-overlay', function() {
			$('#custom-range-modal').remove();
			const cancel = pendingCustomCancel;
			pendingCustomApply = null;
			pendingCustomCancel = null;
			if (typeof cancel === 'function') cancel();
		});

		$(document).on('click', '#apply-custom-range', function() {
			const startDate = $('#custom-start-date').val();
			const endDate = $('#custom-end-date').val();
			if (!startDate || !endDate) {
				alert(_s.customRangeBothRequired || 'Please select both start and end dates');
				return;
			}
			if (new Date(startDate) > new Date(endDate)) {
				alert(_s.customRangeStartBeforeEnd || 'Start date must be before end date');
				return;
			}
			$('#custom-range-modal').remove();
			const apply = pendingCustomApply;
			pendingCustomApply = null;
			pendingCustomCancel = null;
			if (typeof apply === 'function') apply(startDate, endDate);
		});

		// Builder: update URL pattern placeholder when match type changes.
		$(document).on('change', '.step-match-type', function() {
			const matchType = $(this).val();
			const $urlPattern = $(this).closest('.step-fields').find('.step-url-pattern');
			const placeholders = {
				'any': _s.placeholderAny || 'No pattern needed - matches all URLs',
				'exact': _s.placeholderExact || 'Example: https://example.com/checkout',
				'contains': _s.placeholderContains || 'Example: /product (matches any URL containing /product)',
				'starts_with': _s.placeholderStartsWith || 'Example: https://example.com (matches URLs starting with this)',
				'ends_with': _s.placeholderEndsWith || 'Example: .pdf (matches URLs ending with .pdf)',
				'regex': _s.placeholderRegex || 'Example: product/[0-9]+ (matches product/123, product/456)',
				'pageview': _s.placeholderPageview || 'No pattern needed - matches all page views'
			};
			$urlPattern.attr('placeholder', placeholders[matchType] || _s.placeholderDefault || 'Enter URL pattern');
			if (matchType === 'any' || matchType === 'pageview') {
				$urlPattern.prop('disabled', true).val('');
			} else {
				$urlPattern.prop('disabled', false);
			}
		});
	}

	// =========================================================================
	// Custom range modal (shared)
	// =========================================================================

	function openCustomRangeModal(startVal, endVal, onApply, onCancel) {
		$('#custom-range-modal').remove();
		pendingCustomApply = onApply || null;
		pendingCustomCancel = onCancel || null;

		const today = new Date().toISOString().split('T')[0];
		const modalHtml = `
			<div id="custom-range-modal" class="funnel-builder-modal">
				<div class="custom-range-overlay funnel-builder-overlay"></div>
				<div class="funnel-builder-content" style="max-width: 500px;">
					<div class="funnel-builder-header">
						<h2>${_s.customRangeTitle || 'Select Custom Date Range'}</h2>
						<button class="funnel-builder-close" id="close-custom-range">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>
					<div class="funnel-builder-body">
						<div class="form-field">
							<label for="custom-start-date">${_s.startDate || 'Start Date'}</label>
							<input type="date" id="custom-start-date" max="${today}" required />
						</div>
						<div class="form-field">
							<label for="custom-end-date">${_s.endDate || 'End Date'}</label>
							<input type="date" id="custom-end-date" max="${today}" required />
						</div>
					</div>
					<div class="funnel-builder-footer">
						<button type="button" class="btn-cancel" id="cancel-custom-range">${_s.cancel || 'Cancel'}</button>
						<button type="button" class="btn-save-funnel" id="apply-custom-range">${_s.apply || 'Apply'}</button>
					</div>
				</div>
			</div>
		`;
		$('body').append(modalHtml);
		$('#custom-range-modal').show();
		if (startVal && endVal) {
			$('#custom-start-date').val(startVal);
			$('#custom-end-date').val(endVal);
		}
		$('.period-dropdown').hide();
	}

	// =========================================================================
	// Summary load + list render
	// =========================================================================

	function loadFunnelsSummary() {
		const $container = $('#opti-funnel-list-container');
		if ($container.length === 0) {
			return; // Empty state showing.
		}
		$container.html('<div class="funnel-loading"><span class="loading-spinner"></span><span>' + (_s.loading || 'Loading...') + '</span></div>');
		$('#opti-funnel-no-results').hide();
		$('#opti-funnel-pagination').empty();

		// The summary endpoint seeds every row's KPI under the DEFAULT selection
		// (7 Days / All Devices) — matching each funnel's default state on load.
		// Non-default funnels (e.g. a Smart Insights deep-link) are reconciled
		// afterwards via reconcileNonDefaultFunnels().
		const requestData = {
			action: 'optibehavior_get_funnels_summary',
			nonce: optiBehaviorFunnels.nonce,
			period: '7days',
			filter: 'all',
			exclude_spam: getExcludeSpamFlag()
		};

		$.ajax({
			url: optiBehaviorFunnels.ajaxUrl,
			type: 'POST',
			data: requestData,
			success: function(response) {
				if (response.success && response.data) {
					summaryRows = response.data.funnels || [];
					updateSummaryCards(response.data.totals || {});
					applyFilterAndRender();
					reconcileNonDefaultFunnels();
				} else {
					$container.html('<div class="funnel-loading"><span style="color:#dc2626;">' + (_s.error || 'Error loading data') + '</span></div>');
				}
			},
			error: function() {
				$container.html('<div class="funnel-loading"><span style="color:#dc2626;">' + (_s.error || 'Error loading data') + '</span></div>');
			}
		});
	}

	function updateSummaryCards(totals) {
		$('#opti-funnel-stat-total').text(formatNumber(totals.funnels || 0));
		$('#opti-funnel-stat-entries').text(formatNumberShort(totals.entries || 0));
		$('#opti-funnel-stat-completions').text(formatNumberShort(totals.completions || 0));
		const avg = (typeof totals.avg_conversion_rate !== 'undefined') ? totals.avg_conversion_rate : 0;
		$('#opti-funnel-stat-rate').text(avg + '%')
			.removeClass(KPI_COLOR_CLASSES).addClass(conversionColorClass(avg));
	}

	function getFilteredRows() {
		const term = globalSearch.trim().toLowerCase();
		return summaryRows.filter(function(row) {
			if (term && String(row.name || '').toLowerCase().indexOf(term) === -1) {
				return false;
			}
			// Status filter: exactly 'active' or 'suspended'. Missing status on a
			// row is treated as 'active' (backward-compat).
			const status = (row.status === 'suspended') ? 'suspended' : 'active';
			if (globalStatus && status !== globalStatus) {
				return false;
			}
			return true;
		});
	}

	// Weighted global KPI summary over an arbitrary row set:
	// avg conversion = (sum completions / sum entries) * 100, NOT the mean of
	// per-funnel rates. Divide-by-zero guarded (0 entries → 0%).
	function computeFilteredTotals(rows) {
		let entries = 0;
		let completions = 0;
		rows.forEach(function(row) {
			entries += parseInt(row.entries, 10) || 0;
			completions += parseInt(row.completions, 10) || 0;
		});
		const avg = entries > 0 ? Math.round((completions / entries) * 1000) / 10 : 0;
		return {
			funnels: rows.length,
			entries: entries,
			completions: completions,
			avg_conversion_rate: avg
		};
	}

	// =========================================================================
	// Per-funnel KPI refresh (collapsed-row badges + summary aggregation)
	// =========================================================================

	// A funnel's Period/Device changed → re-fetch ITS OWN KPI, refresh that row's
	// badges, re-aggregate the top summary from every funnel's current selection,
	// and reload the detail (steps + countries) when the card is open.
	function applyFunnelFilterChange(funnelId) {
		refreshFunnelKpi(funnelId);
		const $card = $('.funnel-card[data-funnel-id="' + funnelId + '"]');
		if ($card.hasClass('is-expanded')) {
			loadFunnelAnalytics(funnelId);
			loadAvailableCountries(funnelId);
		}
	}

	// Fetch a single funnel's collapsed-row KPI under its own period/device, then
	// update the cached row, its badges, and the recomputed top summary.
	function refreshFunnelKpi(funnelId) {
		const state = getFunnelState(funnelId);
		const requestData = {
			action: 'optibehavior_get_funnel_kpi',
			nonce: optiBehaviorFunnels.nonce,
			funnel_id: funnelId,
			period: state.period,
			filter: state.device,
			exclude_spam: getExcludeSpamFlag()
		};
		if (state.period === 'custom') {
			requestData.start_date = state.customStart;
			requestData.end_date = state.customEnd;
			if (state.customStartDateTime && state.customEndDateTime) {
				requestData.cohort_start_at = state.customStartDateTime;
				requestData.cohort_end_at = state.customEndDateTime;
			}
		}

		$.ajax({
			url: optiBehaviorFunnels.ajaxUrl,
			type: 'POST',
			data: requestData,
			success: function(response) {
				if (!response.success || !response.data) {
					return;
				}
				const kpi = response.data;
				// Keep the cached row in sync so the top summary aggregates the
				// CURRENT per-funnel selection (sum over all funnels).
				summaryRows.forEach(function(r) {
					if (String(r.id) === String(funnelId)) {
						r.entries = kpi.entries;
						r.completions = kpi.completions;
						r.conversion_rate = kpi.conversion_rate;
						r.dropoff_rate = kpi.dropoff_rate;
					}
				});
				updateRowBadges(funnelId, kpi);
				updateSummaryCards(computeFilteredTotals(getFilteredRows()));
			}
		});
	}

	// Update one row's 4 collapsed badges in place (no full re-render).
	function updateRowBadges(funnelId, kpi) {
		const $badges = $('.funnel-card[data-funnel-id="' + funnelId + '"] .opti-funnel-row__badges');
		$badges.find('.opti-funnel-badge--entries .opti-funnel-badge__value').text(formatNumberShort(kpi.entries));
		$badges.find('.opti-funnel-badge--completions .opti-funnel-badge__value').text(formatNumberShort(kpi.completions));
		$badges.find('.opti-funnel-badge--rate .opti-funnel-badge__value')
			.text((kpi.conversion_rate || 0) + '%')
			.removeClass(KPI_COLOR_CLASSES).addClass(conversionColorClass(kpi.conversion_rate || 0));
		$badges.find('.opti-funnel-badge--dropoff .opti-funnel-badge__value')
			.text((kpi.dropoff_rate || 0) + '%')
			.removeClass(KPI_COLOR_CLASSES).addClass(dropoffColorClass(kpi.dropoff_rate || 0));
	}

	// Refresh any funnel whose per-funnel selection differs from the default
	// (7 Days / All Devices) — e.g. a Smart Insights deep-link seeded one funnel.
	function reconcileNonDefaultFunnels() {
		summaryRows.forEach(function(row) {
			const state = funnelStateById[String(row.id)];
			if (state && (state.period !== '7days' || state.device !== 'all')) {
				refreshFunnelKpi(row.id);
			}
		});
	}

	function applyFilterAndRender() {
		const $container = $('#opti-funnel-list-container');
		if ($container.length === 0) return;

		const rows = getFilteredRows();
		// Recompute the KPI summary from the currently filtered set so search /
		// filter changes update the stat-bar live (weighted avg conversion).
		updateSummaryCards(computeFilteredTotals(rows));
		const total = rows.length;
		const totalPages = Math.ceil(total / PER_PAGE) || 1;
		if (currentPage > totalPages) currentPage = totalPages;
		if (currentPage < 1) currentPage = 1;

		if (total === 0) {
			$container.empty();
			$('#opti-funnel-pagination').empty();
			$('#opti-funnel-no-results').show();
			return;
		}
		$('#opti-funnel-no-results').hide();

		const startIdx = (currentPage - 1) * PER_PAGE;
		const pageRows = rows.slice(startIdx, startIdx + PER_PAGE);
		renderFunnelRows(pageRows, $container);
		renderFunnelPagination(total, currentPage, PER_PAGE);
		refreshIcons();
		restoreExpandedDetails();
		maybeFocusSmartInsightsFunnel();
	}

	// Dynamic KPI color classes (4-band quality scale). Swapped at render AND on
	// every live per-row refresh so the badge color tracks the current value.
	const KPI_COLOR_CLASSES = 'opti-kpi-green opti-kpi-yellow opti-kpi-orange opti-kpi-red';

	// Conversion rate — higher is better.
	function conversionColorClass(rate) {
		const r = parseFloat(rate) || 0;
		if (r >= 50) return 'opti-kpi-green';
		if (r >= 25) return 'opti-kpi-yellow';
		if (r >= 10) return 'opti-kpi-orange';
		return 'opti-kpi-red';
	}

	// Drop-off rate — higher is worse (mirror of conversion bands).
	function dropoffColorClass(rate) {
		const r = parseFloat(rate) || 0;
		if (r <= 50) return 'opti-kpi-green';
		if (r <= 75) return 'opti-kpi-yellow';
		if (r <= 90) return 'opti-kpi-orange';
		return 'opti-kpi-red';
	}

	function renderRowBadge(value, label, modifier, valueClass) {
		const cls = valueClass ? ' ' + valueClass : '';
		return '' +
			'<div class="opti-funnel-badge opti-funnel-badge--' + modifier + '">' +
			'  <span class="opti-funnel-badge__value' + cls + '">' + value + '</span>' +
			'  <span class="opti-funnel-badge__label">' + label + '</span>' +
			'</div>';
	}

	function renderFunnelRows(rows, $container) {
		let html = '';
		rows.forEach(function(funnel) {
			const conv = (typeof funnel.conversion_rate !== 'undefined') ? funnel.conversion_rate : 0;
			const drop = (typeof funnel.dropoff_rate !== 'undefined') ? funnel.dropoff_rate : 0;
			const stepCount = parseInt(funnel.step_count, 10) || 0;
			// Backward-compat: a missing status is treated as 'active'.
			const status = (funnel.status === 'suspended') ? 'suspended' : 'active';
			const isSuspended = status === 'suspended';

			html += '<div class="funnel-card opti-funnel-row' + (isSuspended ? ' is-suspended' : '') + '" data-funnel-id="' + funnel.id + '" data-status="' + status + '">';
			html += '  <div class="opti-funnel-row__summary">';
			html += '    <div class="opti-funnel-row__title">';
			html += '      <span class="opti-funnel-row__chevron"><i data-lucide="chevron-right"></i></span>';
			html += '      <div class="opti-funnel-row__title-text">';
			html += '        <h3 class="funnel-title">' + escapeHtml(funnel.name || '');
			if (isSuspended) {
				html += ' <span class="opti-funnel-status-pill opti-funnel-status-pill--suspended"><i data-lucide="pause-circle"></i>' + (_s.suspended || 'Suspended') + '</span>';
			}
			html += '</h3>';
			if (funnel.description) {
				html += '      <p class="funnel-description">' + escapeHtml(funnel.description) + '</p>';
			}
			html += '        <span class="opti-funnel-row__steps">' + stepCount + ' ' + (_s.stepCount || 'Steps') + '</span>';
			html += '      </div>';
			html += '    </div>';

			html += '    <div class="opti-funnel-row__badges">';
			html += renderRowBadge(formatNumberShort(funnel.entries), _s.entries || 'Entries', 'entries');
			html += renderRowBadge(formatNumberShort(funnel.completions), _s.completions || 'Completions', 'completions');
			html += renderRowBadge(conv + '%', _s.conversionRate || 'Conversion Rate', 'rate', conversionColorClass(conv));
			html += renderRowBadge(drop + '%', _s.dropOff || 'Drop-off', 'dropoff', dropoffColorClass(drop));
			html += '    </div>';

			html += '    <div class="opti-funnel-row__actions">';
			html += '      <a class="opti-funnel-row__toggle" href="' + escapeHtml(funnelDetailUrl(funnel.id)) + '">' + (_s.viewDetails || 'View details') + '</a>';
			html += '      <div class="funnel-more-container">';
			html += '        <button class="funnel-control-btn funnel-more-btn" data-funnel-id="' + funnel.id + '"><span class="dashicons dashicons-ellipsis"></span></button>';
			html += '        <div class="funnel-more-dropdown" data-funnel-id="' + funnel.id + '">';
			html += '          <button class="edit-funnel" data-funnel-id="' + funnel.id + '"><span class="dashicons dashicons-edit"></span>' + (_s.editFunnel || 'Edit Funnel') + '</button>';
			html += '          <button class="duplicate-funnel" data-funnel-id="' + funnel.id + '"><span class="dashicons dashicons-admin-page"></span>' + (_s.duplicate || 'Duplicate') + '</button>';
			html += '          <button class="reset-funnel-data" data-funnel-id="' + funnel.id + '"><span class="dashicons dashicons-update"></span>' + (_s.resetData || 'Reset Data') + '</button>';
			if (isSuspended) {
				html += '          <button class="toggle-funnel-status" data-funnel-id="' + funnel.id + '" data-status="suspended"><i data-lucide="play-circle"></i>' + (_s.activate || 'Activate') + '</button>';
			} else {
				html += '          <button class="toggle-funnel-status" data-funnel-id="' + funnel.id + '" data-status="active"><i data-lucide="pause-circle"></i>' + (_s.suspend || 'Suspend') + '</button>';
			}
			html += '          <button class="delete-funnel" data-funnel-id="' + funnel.id + '"><span class="dashicons dashicons-trash"></span>' + (_s.delete || 'Delete') + '</button>';
			html += '        </div>';
			html += '      </div>';
			html += '    </div>';
			html += '  </div>'; // .opti-funnel-row__summary
			html += '</div>'; // .funnel-card
		});
		$container.html(html);
	}

	function renderFunnelPagination(total, page, perPage) {
		const $pag = $('#opti-funnel-pagination');
		if (total <= perPage) {
			$pag.empty();
			return;
		}
		const totalPages = Math.ceil(total / perPage);
		let winStart = Math.max(1, page - 2);
		let winEnd = Math.min(totalPages, winStart + 4);
		if (winEnd - winStart < 4) {
			winStart = Math.max(1, winEnd - 4);
		}

		let html = '<div class="opti-funnel-pagination__controls">';
		html += '<button class="opti-funnel-pagination__btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>&larr;</button>';
		for (let i = winStart; i <= winEnd; i++) {
			html += '<button class="opti-funnel-pagination__btn' + (i === page ? ' is-active' : '') + '" data-page="' + i + '">' + i + '</button>';
		}
		html += '<button class="opti-funnel-pagination__btn" data-page="' + (page + 1) + '"' + (page >= totalPages ? ' disabled' : '') + '>&rarr;</button>';
		html += '</div>';

		const from = (page - 1) * perPage + 1;
		const to = Math.min(page * perPage, total);
		const tpl = _s.showingRange || 'Showing %1$d–%2$d of %3$d funnels';
		const info = tpl.replace('%1$d', from).replace('%2$d', to).replace('%3$d', total);
		html += '<div class="opti-funnel-pagination__info">' + info + '</div>';

		$pag.html(html);
	}

	// =========================================================================
	// Row detail (expand / collapse)
	// =========================================================================

	function buildDetailControlsHtml(funnelId) {
		// Period + Device + Country are ALL per-funnel and independent. Each
		// control reads/writes this funnel's own state (funnelStateById).
		const state = getFunnelState(funnelId);
		return '' +
			'<div class="funnel-controls">' +
			// Period (per-funnel) — calendar icon.
			'  <div class="funnel-control-wrapper">' +
			'    <button class="funnel-control-btn funnel-period-btn" data-funnel-id="' + funnelId + '">' +
			'      <i data-lucide="calendar"></i>' +
			'      <span class="funnel-period-label">' + periodLabel(state.period) + '</span>' +
			'      <span class="dashicons dashicons-arrow-down-alt2"></span>' +
			'    </button>' +
			'    <div class="period-dropdown funnel-period-dropdown" style="display:none;">' +
			'      <button class="period-option' + (state.period === '7days' ? ' active' : '') + '" data-period="7days">' + (_s.sevenDays || '7 Days') + '</button>' +
			'      <button class="period-option' + (state.period === '30days' ? ' active' : '') + '" data-period="30days">' + (_s.thirtyDays || '30 Days') + '</button>' +
			'      <button class="period-option' + (state.period === '90days' ? ' active' : '') + '" data-period="90days">' + (_s.ninetyDays || '90 Days') + '</button>' +
			'      <button class="period-option' + (state.period === 'custom' ? ' active' : '') + '" data-period="custom">' + (_s.customRange || 'Custom Range') + '</button>' +
			'    </div>' +
			'  </div>' +
			// Device (per-funnel) — Lucide icon dropdown (layers/monitor/smartphone/tablet).
			'  <div class="funnel-control-wrapper">' +
			'    <button class="funnel-control-btn funnel-device-btn" data-funnel-id="' + funnelId + '">' +
			'      <i data-lucide="' + deviceIcon(state.device) + '" class="funnel-device-icon"></i>' +
			'      <span class="funnel-device-label">' + deviceLabel(state.device) + '</span>' +
			'      <span class="dashicons dashicons-arrow-down-alt2"></span>' +
			'    </button>' +
			'    <div class="period-dropdown device-dropdown" style="display:none;">' +
			'      <button class="period-option' + (state.device === 'all' ? ' active' : '') + '" data-device="all"><i data-lucide="layers"></i><span>' + (_s.allDevices || 'All Devices') + '</span></button>' +
			'      <button class="period-option' + (state.device === 'desktop' ? ' active' : '') + '" data-device="desktop"><i data-lucide="monitor"></i><span>' + (_s.desktop || 'Desktop') + '</span></button>' +
			'      <button class="period-option' + (state.device === 'mobile' ? ' active' : '') + '" data-device="mobile"><i data-lucide="smartphone"></i><span>' + (_s.mobile || 'Mobile') + '</span></button>' +
			'      <button class="period-option' + (state.device === 'tablet' ? ' active' : '') + '" data-device="tablet"><i data-lucide="tablet"></i><span>' + (_s.tablet || 'Tablet') + '</span></button>' +
			'    </div>' +
			'  </div>' +
			// Country (per-funnel) — globe icon.
			'  <div class="funnel-control-wrapper">' +
			'    <button class="funnel-control-btn funnel-country-btn" data-funnel-id="' + funnelId + '">' +
			'      <i data-lucide="globe"></i>' +
			'      <span class="funnel-country-label">' + (_s.allCountries || 'All Countries') + '</span>' +
			'      <span class="dashicons dashicons-arrow-down-alt2"></span>' +
			'    </button>' +
			'    <div class="period-dropdown country-dropdown" style="display:none;">' +
			'      <div class="country-search-wrapper">' +
			'        <input type="text" class="country-search-input" placeholder="' + (_s.searchCountries || 'Search countries...') + '">' +
			'      </div>' +
			'      <div class="country-options-container"></div>' +
			'      <div class="country-actions">' +
			'        <button type="button" class="country-clear-btn">' + (_s.clear || 'Clear') + '</button>' +
			'        <button type="button" class="country-apply-btn">' + (_s.apply || 'Apply') + '</button>' +
			'      </div>' +
			'    </div>' +
			'  </div>' +
			'</div>' +
			'<div class="funnel-steps-wrapper">' +
			'  <div class="funnel-steps-label">' + (_s.steps || 'Steps') + '</div>' +
			'  <div class="funnel-steps-container" data-funnel-id="' + funnelId + '">' +
			'    <div class="funnel-loading"><span class="loading-spinner"></span><span>' + (_s.loading || 'Loading...') + '</span></div>' +
			'  </div>' +
			'</div>';
	}

	// Open a card's detail view, building + loading its data on first open. Detail
	// data uses THIS funnel's own per-funnel state, so a re-render (which rebuilds
	// the DOM from funnelStateById) automatically reflects its current selection.
	function openFunnelDetail($card, funnelId, animate) {
		const $detail = $card.find('.opti-funnel-row__detail');
		$card.addClass('is-expanded');
		$card.find('.opti-funnel-row__toggle').text(_s.hideDetails || 'Hide details');

		if (!$detail.data('built')) {
			$detail.html(buildDetailControlsHtml(funnelId));
			$detail.data('built', true);
			updateCountryLabelForFunnel(funnelId);
			refreshIcons();
			loadFunnelAnalytics(funnelId);
			loadAvailableCountries(funnelId);
		}
		if (animate) {
			$detail.slideDown(150);
		} else {
			$detail.show();
		}
	}

	function closeFunnelDetail($card) {
		$card.removeClass('is-expanded');
		$card.find('.opti-funnel-row__toggle').text(_s.viewDetails || 'View details');
		$card.find('.opti-funnel-row__detail').slideUp(150);
	}

	function toggleFunnelDetail(funnelId) {
		const $card = $('.funnel-card[data-funnel-id="' + funnelId + '"]');
		if ($card.length === 0) return;

		if ($card.hasClass('is-expanded')) {
			expandedFunnelIds.delete(String(funnelId));
			closeFunnelDetail($card);
			return;
		}

		expandedFunnelIds.add(String(funnelId));
		openFunnelDetail($card, funnelId, true);
	}

	// Re-open detail views that were expanded before the last list re-render so
	// the global period/device/status change (or search/pagination) reflects in
	// any open card without the user having to re-expand it.
	function restoreExpandedDetails() {
		expandedFunnelIds.forEach(function(id) {
			const $card = $('.funnel-card[data-funnel-id="' + id + '"]');
			if ($card.length && !$card.hasClass('is-expanded')) {
				openFunnelDetail($card, id, false);
			}
		});
	}

	// =========================================================================
	// Per-funnel analytics (detail steps visualization)
	// =========================================================================

	function loadFunnelAnalytics(funnelId) {
		// Detail data uses THIS funnel's own period/device/country selection.
		const state = getFunnelState(funnelId);
		const selectedCountries = getSelectedCountries(funnelId);

		const ajaxData = {
			action: 'optibehavior_funnel_data',
			nonce: optiBehaviorFunnels.nonce,
			funnel_id: funnelId,
			period: state.period,
			filter: state.device,
			country: selectedCountries.length > 0 ? selectedCountries.join(',') : 'all',
			exclude_spam: getExcludeSpamFlag()
		};
		if (state.period === 'custom') {
			ajaxData.start_date = state.customStart;
			ajaxData.end_date = state.customEnd;
			if (state.customStartDateTime && state.customEndDateTime) {
				ajaxData.cohort_start_at = state.customStartDateTime;
				ajaxData.cohort_end_at = state.customEndDateTime;
			}
		}

		$.ajax({
			url: optiBehaviorFunnels.ajaxUrl,
			type: 'POST',
			data: ajaxData,
			success: function(response) {
				if (response.success && response.data) {
					renderFunnelAnalytics(funnelId, response.data);
				} else {
					showFunnelError(funnelId);
				}
			},
			error: function() {
				showFunnelError(funnelId);
			}
		});
	}

	function renderFunnelAnalytics(funnelId, data) {
		const stepsContainer = $('.funnel-steps-container[data-funnel-id="' + funnelId + '"]');
		if (!data.funnel_steps || data.funnel_steps.length === 0) {
			stepsContainer.html('<div class="funnel-loading"><span>' + (_s.noData || 'No funnel data available') + '</span></div>');
			return;
		}

		const steps = data.funnel_steps;
		const maxValue = steps[0].completed; // First step has highest value.
		stepsContainer.empty();

		// Axis card.
		const stepsCard = `
			<div class="funnel-step-card funnel-steps-axis-card">
				<div class="funnel-step-header">
					<div class="step-title">${_s.steps || 'Steps'}</div>
				</div>
				<div class="funnel-bar-container">
					<div class="funnel-y-axis-inline">
						<div class="funnel-y-axis-label">${formatNumberShort(maxValue)}</div>
						<div class="funnel-y-axis-label">${formatNumberShort(Math.round(maxValue * 0.67))}</div>
						<div class="funnel-y-axis-label">${formatNumberShort(Math.round(maxValue * 0.33))}</div>
						<div class="funnel-y-axis-label">0</div>
					</div>
				</div>
				<div class="funnel-step-metrics">
					<div class="funnel-y-axis-users">${_s.users || 'Users'}</div>
				</div>
			</div>
		`;
		stepsContainer.append(stepsCard);

		steps.forEach(function(step) {
			const heightPercent = Math.max((step.completed / maxValue) * 100, 5);
			const completionRate = step.completion_rate || 0;
			const abandonmentRate = step.abandonment_rate || 0;

			let bgColor = '#fee2e2';
			let barColor = '#10b981';
			if (completionRate >= 80) { bgColor = '#d1fae5'; barColor = '#10b981'; }
			else if (completionRate >= 60) { bgColor = '#fef3c7'; barColor = '#10b981'; }
			else if (completionRate >= 40) { bgColor = '#fed7aa'; barColor = '#fb923c'; }
			else if (completionRate >= 20) { bgColor = '#fecaca'; barColor = '#f87171'; }
			else { bgColor = '#fee2e2'; barColor = '#ef4444'; }

			// Position the replay icon vertically within the dropout band (the
			// abandoned region above the completed fill). Reason in PIXELS, not %,
			// because the icon has a fixed pixel size and the bar fill has a pixel
			// min-height — so a tiny dropout band (e.g. 1%) is only a few px tall,
			// far shorter than the icon, and naive %-centring clips the top of the
			// bar and overlaps the solid fill.
			//   barHeightPx   .funnel-bar-container height (CSS)
			//   iconHalfPx    half of .funnel-step-replay (30px) => 15px
			//   minFillPx     .funnel-bar min-height (CSS) — true fill floor
			//   minInsetPx    keep the icon core fully visible below the top edge
			// fillPx is the REAL rendered fill height (honours min-height), so
			// bandBottomPx is where the dropout band actually ends (fill top edge).
			const barHeightPx = 180;
			const iconHalfPx = 15;
			const minFillPx = 36;
			const minInsetPx = iconHalfPx + 1;
			const fillPx = Math.max((heightPercent / 100) * barHeightPx, minFillPx);
			const bandBottomPx = barHeightPx - fillPx;
			// Ideal: centre of the dropout band. Clamp so the icon's bottom edge
			// never crosses into the fill (cap at bandBottom - iconHalf), then
			// never let it clip the top edge (floor at minInset). When the band is
			// shorter than the icon, the floor wins → icon pinned just inside the
			// top edge rather than mathematically centred.
			let iconCenterPx = bandBottomPx / 2;
			iconCenterPx = Math.min(iconCenterPx, bandBottomPx - iconHalfPx);
			iconCenterPx = Math.max(iconCenterPx, minInsetPx);
			const dropoutCenter = (iconCenterPx / barHeightPx) * 100;
			const replayIcon = buildStepReplayIcon(funnelId, step.step_number, step.abandoned, dropoutCenter);

			// Smart Insights evidence links carry the leaking step so the card
			// that triggered the insight is visually anchored on arrival.
			const isAnchoredStep = smartInsightsFunnelContext
				&& String(smartInsightsFunnelContext.funnelId) === String(funnelId)
				&& String(smartInsightsFunnelContext.funnelStep) !== ''
				&& String(smartInsightsFunnelContext.funnelStep) === String(step.step_number);
			const anchorClass = isAnchoredStep ? ' is-smart-insights-step' : '';

			const stepHtml = `
				<div class="funnel-step-card${anchorClass}">
					<div class="funnel-step-header">
						<div class="step-circle">${step.step_number}</div>
						<div class="step-title" title="${escapeHtml(step.step_name)}">${escapeHtml(step.step_name)}</div>
					</div>
					<div class="funnel-bar-container">
						<div class="funnel-bar-background" style="background-color: ${bgColor};"></div>
						<div class="funnel-bar" style="height: ${heightPercent}%; background-color: ${barColor};">
							<div class="funnel-bar-percentage">${completionRate}%</div>
						</div>
						${replayIcon}
					</div>
					<div class="funnel-step-metrics">
						<div class="funnel-metric-row">
							<div class="funnel-metric-section">
								<div class="funnel-metric-label">${_s.completed || 'Completed'}</div>
								<div class="funnel-metric-value">
									<span class="value-number">${formatNumber(step.completed)}</span>
									<span class="value-percent positive">${completionRate}%</span>
								</div>
							</div>
						</div>
						<div class="funnel-metric-row">
							<div class="funnel-metric-section">
								<div class="funnel-metric-label">${_s.abandoned || 'Abandoned'}</div>
								<div class="funnel-metric-value">
									<span class="value-number">${formatNumber(step.abandoned)}</span>
									<span class="value-percent negative">${abandonmentRate}%</span>
								</div>
							</div>
						</div>
					</div>
				</div>
			`;
			stepsContainer.append(stepHtml);
		});

		// Render the newly injected lucide icons (step replay icons).
		if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
			lucide.createIcons();
		}
	}

	function showFunnelError(funnelId) {
		$('.funnel-steps-container[data-funnel-id="' + funnelId + '"]').html('<div class="funnel-loading"><span style="color:#dc2626;">' + (_s.error || 'Error loading data') + '</span></div>');
	}

	// =========================================================================
	// Country options loading (per-funnel)
	// =========================================================================

	function loadAvailableCountries(funnelId, reloadAfterPrune) {
		// Country options follow THIS funnel's own period (matches its detail scope).
		const state = getFunnelState(funnelId);
		const requestData = {
			action: 'optibehavior_get_funnel_countries',
			nonce: optiBehaviorFunnels.nonce,
			funnel_id: funnelId || 0,
			period: state.period,
			exclude_spam: getExcludeSpamFlag()
		};
		if (state.period === 'custom') {
			requestData.start_date = state.customStart;
			requestData.end_date = state.customEnd;
			if (state.customStartDateTime && state.customEndDateTime) {
				requestData.cohort_start_at = state.customStartDateTime;
				requestData.cohort_end_at = state.customEndDateTime;
			}
		}

		$.ajax({
			url: optiBehaviorFunnels.ajaxUrl,
			type: 'POST',
			data: requestData,
			success: function(response) {
				if (!response.success || !response.data.countries) {
					return;
				}
				const countries = response.data.countries;
				const availableCodes = countries
					.map(function(country) { return normalizeCountryCode(country.code); })
					.filter(function(code) { return !!code; });
				let prunedSelection = false;

				const $containers = funnelId
					? $('.funnel-card[data-funnel-id="' + funnelId + '"] .country-options-container')
					: $('.country-options-container');

				$containers.each(function() {
					const $container = $(this);
					const containerFunnelId = $container.closest('.funnel-card').data('funnel-id') || funnelId;
					$container.empty();

					if (countries.length === 0) {
						$container.html('<div class="no-countries-message">' + (_s.noCountryData || 'No country data available for this period') + '</div>');
						if (containerFunnelId) {
							const existingSelection = getSelectedCountries(containerFunnelId);
							if (existingSelection.length > 0) {
								prunedSelection = true;
								setSelectedCountries(containerFunnelId, []);
								updateCountryLabelForFunnel(containerFunnelId);
							}
						}
						return;
					}

					const selectedCountries = getSelectedCountries(containerFunnelId);
					const prunedCountries = selectedCountries.filter(function(code) {
						return availableCodes.includes(code);
					});
					if (prunedCountries.length !== selectedCountries.length) {
						prunedSelection = true;
						setSelectedCountries(containerFunnelId, prunedCountries);
						updateCountryLabelForFunnel(containerFunnelId);
					}

					countries.forEach(function(country) {
						const normalizedCountryCode = normalizeCountryCode(country.code);
						if (!normalizedCountryCode) return;
						const countryCode = normalizedCountryCode.toLowerCase();
						const isChecked = prunedCountries.includes(normalizedCountryCode);
						const $option = $('<label>')
							.addClass('country-option-item')
							.html(`
								<input type="checkbox" class="country-checkbox" data-country="${normalizedCountryCode}" ${isChecked ? 'checked' : ''}>
								<span class="fi fi-${countryCode}"></span>
								<span class="country-name">${country.name.toUpperCase()}</span>
							`);
						$container.append($option);
					});
				});

				if (reloadAfterPrune && prunedSelection && funnelId) {
					loadFunnelAnalytics(funnelId);
				}
			},
			error: function() {
				console.error('Failed to load countries');
			}
		});
	}

	function updateCountryLabelForFunnel(funnelId) {
		const selectedCountries = getSelectedCountries(funnelId);
		const $label = $('.funnel-card[data-funnel-id="' + funnelId + '"] .funnel-country-label');
		if (selectedCountries.length === 0) {
			$label.text(_s.allCountries || 'All Countries');
		} else if (selectedCountries.length === 1) {
			$label.text(selectedCountries[0].toUpperCase());
		} else {
			$label.text((_s.nCountries || '%d Countries').replace('%d', selectedCountries.length));
		}
	}

	// =========================================================================
	// Smart Insights deep-link auto-focus
	// =========================================================================

	function maybeFocusSmartInsightsFunnel() {
		if (smartInsightsHandled || !smartInsightsFunnelContext.funnelId) {
			return;
		}
		const $card = $('.funnel-card[data-funnel-id="' + smartInsightsFunnelContext.funnelId + '"]');
		if ($card.length === 0) {
			// Target funnel may be on another page; ensure it's on the current
			// filtered page by jumping to page 1 search-free is out of scope —
			// only auto-focus when it is visible.
			return;
		}
		smartInsightsHandled = true;
		$card.addClass('smart-insights-target-funnel');
		$card.css({ boxShadow: '0 0 0 3px rgba(79,70,229,.22)', borderColor: '#4f46e5' });
		if ($card.find('.smart-insights-context-chip').length === 0) {
			$card.find('.opti-funnel-row__summary').before('<div class="smart-insights-context-chip" style="margin:10px;padding:8px 10px;border-radius:10px;background:#eef2ff;color:#3730a3;font-weight:600;">' + (_s.smartInsightsContext || 'Smart Insights context: this funnel is the related issue.') + '</div>');
		}
		toggleFunnelDetail(smartInsightsFunnelContext.funnelId);
		setTimeout(function() {
			if ($card[0]) {
				$card[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		}, 300);
	}

	// =========================================================================
	// Funnel builder modal
	// =========================================================================

	function initFunnelBuilder() {
		let stepCounter = 1;
		let editingFunnelId = null;

		$('.btn-build-funnel, .btn-build-funnel-primary').on('click', function() {
			editingFunnelId = null;
			resetFunnelBuilder();
			$('#funnel-builder-title').text(_s.buildNewFunnel || 'Build New Funnel');
			$('#save-funnel-builder').text(_s.saveFunnel || 'Save Funnel');
			$('#funnel-builder-modal').css('display', 'flex').addClass('show');
		});

		$('#close-funnel-builder, #cancel-funnel-builder, .funnel-builder-overlay').on('click', function() {
			$('#funnel-builder-modal').removeClass('show');
			setTimeout(function() {
				$('#funnel-builder-modal').css('display', 'none');
				resetFunnelBuilder();
			}, 300);
		});

		$('#add-funnel-step').on('click', function() {
			stepCounter++;
			const stepHtml = `
				<div class="funnel-step-item" data-step="${stepCounter}">
					<div class="step-number">${stepCounter}</div>
					<div class="step-fields">
						<input type="text" class="step-name" placeholder="${_s.stepNamePlaceholder || 'Step name (e.g., Landing Page)'}" required />
						<select class="step-match-type">
							${funnelMatchOptions()}
						</select>
						<input type="text" class="step-url-pattern" placeholder="${_s.urlPatternPlaceholder || 'URL pattern (e.g., /cart)'}" />
					</div>
					<button type="button" class="remove-step">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
			`;
			$('#funnel-steps-container').append(stepHtml);
			updateRemoveButtons();
		});

		$(document).on('click', '.remove-step', function() {
			$(this).closest('.funnel-step-item').remove();
			updateStepNumbers();
			updateRemoveButtons();
		});

		$('#save-funnel-builder').on('click', function() {
			saveFunnel();
		});

		function updateRemoveButtons() {
			const stepCount = $('.funnel-step-item').length;
			if (stepCount <= 1) {
				$('.remove-step').hide();
			} else {
				$('.remove-step').show();
			}
		}

		function updateStepNumbers() {
			$('.funnel-step-item').each(function(index) {
				$(this).attr('data-step', index + 1);
				$(this).find('.step-number').text(index + 1);
			});
		}

		function resetFunnelBuilder() {
			$('#funnel-name').val('');
			$('#funnel-description').val('');
			$('#funnel-steps-container').empty();
			stepCounter = 1;
			const firstStepHtml = `
				<div class="funnel-step-item" data-step="1">
					<div class="step-number">1</div>
					<div class="step-fields">
						<input type="text" class="step-name" placeholder="${_s.stepNamePlaceholder || 'Step name (e.g., Landing Page)'}" required />
						<select class="step-match-type">
							${funnelMatchOptions()}
						</select>
						<input type="text" class="step-url-pattern" placeholder="${_s.urlPatternPlaceholder || 'URL pattern (e.g., /cart)'}" />
					</div>
					<button type="button" class="remove-step" style="display: none;">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
			`;
			$('#funnel-steps-container').append(firstStepHtml);
		}

		function saveFunnel() {
			const funnelName = $('#funnel-name').val().trim();
			if (!funnelName) {
				alert(_s.funnelNameRequired || 'Please enter a funnel name');
				return;
			}

			const steps = [];
			let valid = true;
			$('.funnel-step-item').each(function() {
				const stepName = $(this).find('.step-name').val().trim();
				const matchType = $(this).find('.step-match-type').val();
				const urlPattern = $(this).find('.step-url-pattern').val().trim();
				if (!stepName) {
					valid = false;
					alert(_s.stepNamesRequired || 'Please fill in all step names');
					return false;
				}
				steps.push({ name: stepName, match_type: matchType, url_pattern: urlPattern || '*' });
			});
			if (!valid) return;

			const funnelDescription = $('#funnel-description').val().trim();
			$('#save-funnel-builder').prop('disabled', true).text(_s.saving || 'Saving...');

			const ajaxData = {
				action: 'optibehavior_save_funnel',
				nonce: optiBehaviorFunnels.nonce,
				name: funnelName,
				description: funnelDescription,
				steps: JSON.stringify(steps)
			};
			if (editingFunnelId) {
				ajaxData.funnel_id = editingFunnelId;
			}

			$.ajax({
				url: optiBehaviorFunnels.ajaxUrl,
				type: 'POST',
				data: ajaxData,
				success: function(response) {
					if (response.success) {
						$('#funnel-builder-modal').removeClass('show');
						setTimeout(function() {
							$('#funnel-builder-modal').css('display', 'none');
							resetFunnelBuilder();
						}, 300);

						// First funnel created from the empty state: the list UI
						// (stats bar, #opti-funnel-list-container, filters) is not
						// rendered yet — the server only prints it once at least one
						// funnel exists. loadFunnelsSummary() early-returns when the
						// container is missing, so the new funnel would not appear
						// until a manual refresh. Reload to let the server render the
						// full list markup.
						if ($('#opti-funnel-list-container').length === 0) {
							window.location.reload();
							return;
						}

						// Live refresh: re-render the list + recompute the global KPI
						// summary so a freshly CREATED funnel appears without a manual
						// page reload (mirrors the edit/update flow). New funnels sort
						// to the top (created_at DESC) so jump to page 1.
						editingFunnelId = null;
						currentPage = 1;
						loadFunnelsSummary();

						var msg = _s.funnelSavedSuccess || 'Funnel saved successfully!';
						var $toast = $('<div class="opti-toast opti-toast-success"><span class="opti-toast-icon">&#10003;</span> ' + $('<span>').text(msg).html() + '</div>');
						$toast.css({
							position: 'fixed', top: '60px', right: '30px', zIndex: 999999,
							background: 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
							color: '#fff', padding: '14px 28px', borderRadius: '10px',
							fontSize: '14px', fontWeight: 600,
							boxShadow: '0 8px 24px rgba(16,185,129,0.35)',
							display: 'flex', alignItems: 'center', gap: '10px',
							opacity: 0, transform: 'translateX(40px)',
							transition: 'all 0.4s cubic-bezier(.4,0,.2,1)'
						});
						$toast.find('.opti-toast-icon').css({
							background: 'rgba(255,255,255,0.25)', borderRadius: '50%',
							width: '26px', height: '26px', display: 'inline-flex',
							alignItems: 'center', justifyContent: 'center', fontSize: '14px'
						});
						$('body').append($toast);
						setTimeout(function(){ $toast.css({ opacity: 1, transform: 'translateX(0)' }); }, 50);
						setTimeout(function(){ $toast.css({ opacity: 0, transform: 'translateX(40px)' }); }, 2000);
						setTimeout(function(){ $toast.remove(); }, 2500);
					} else {
						alert((_s.errorSavingFunnel || 'Error saving funnel') + ': ' + (response.data.message || _s.unknownError || 'Unknown error'));
						$('#save-funnel-builder').prop('disabled', false).text(_s.saveFunnel || 'Save Funnel');
					}
				},
				error: function() {
					alert(_s.errorSavingFunnelRetry || 'Error saving funnel. Please try again.');
					$('#save-funnel-builder').prop('disabled', false).text(_s.saveFunnel || 'Save Funnel');
				}
			});
		}

		function populateBuilderSteps(funnel) {
			$('#funnel-steps-container').empty();
			stepCounter = 0;
			funnel.steps.forEach(function(step) {
				stepCounter++;
				const stepHtml = `
					<div class="funnel-step-item" data-step="${stepCounter}">
						<div class="step-number">${stepCounter}</div>
						<div class="step-fields">
							<input type="text" class="step-name" placeholder="${_s.stepNamePlaceholder || 'Step name (e.g., Landing Page)'}" value="${escapeHtml(step.name)}" required />
							<select class="step-match-type">
								${funnelMatchOptions(step.match_type)}
							</select>
							<input type="text" class="step-url-pattern" placeholder="${_s.urlPatternPlaceholder || 'URL pattern (e.g., /cart)'}" value="${escapeHtml(step.url_pattern || '')}" />
						</div>
						<button type="button" class="remove-step">
							<span class="dashicons dashicons-trash"></span>
						</button>
					</div>
				`;
				$('#funnel-steps-container').append(stepHtml);
			});
			updateRemoveButtons();
		}

		window.loadFunnelForEdit = function(funnelId) {
			$('#funnel-builder-modal').css('display', 'flex').addClass('show');
			$('#funnel-name').val(_s.loading || 'Loading...').prop('disabled', true);
			$('#funnel-description').val('').prop('disabled', true);
			$('#save-funnel-builder').prop('disabled', true).text(_s.loading || 'Loading...');

			$.ajax({
				url: optiBehaviorFunnels.ajaxUrl,
				type: 'POST',
				data: { action: 'optibehavior_get_funnel', nonce: optiBehaviorFunnels.nonce, funnel_id: funnelId },
				success: function(response) {
					if (response.success && response.data) {
						const funnel = response.data;
						editingFunnelId = funnel.id;
						$('#funnel-builder-title').text(_s.editFunnel || 'Edit Funnel');
						$('#funnel-name').val(funnel.name).prop('disabled', false);
						$('#funnel-description').val(funnel.description || '').prop('disabled', false);
						populateBuilderSteps(funnel);
						$('#save-funnel-builder').prop('disabled', false).text(_s.updateFunnel || 'Update Funnel');
					} else {
						alert((_s.errorLoadingFunnel || 'Error loading funnel') + ': ' + (response.data.message || _s.unknownError || 'Unknown error'));
						$('#funnel-builder-modal').removeClass('show');
						setTimeout(function() { $('#funnel-builder-modal').css('display', 'none'); }, 300);
					}
				},
				error: function() {
					alert(_s.errorLoadingFunnelRetry || 'Error loading funnel. Please try again.');
					$('#funnel-builder-modal').removeClass('show');
					setTimeout(function() { $('#funnel-builder-modal').css('display', 'none'); }, 300);
				}
			});
		};

		window.loadFunnelForDuplicate = function(funnelId) {
			$('#funnel-builder-modal').css('display', 'flex').addClass('show');
			$('#funnel-name').val(_s.loading || 'Loading...').prop('disabled', true);
			$('#funnel-description').val('').prop('disabled', true);
			$('#save-funnel-builder').prop('disabled', true).text(_s.loading || 'Loading...');

			$.ajax({
				url: optiBehaviorFunnels.ajaxUrl,
				type: 'POST',
				data: { action: 'optibehavior_get_funnel', nonce: optiBehaviorFunnels.nonce, funnel_id: funnelId },
				success: function(response) {
					if (response.success && response.data) {
						const funnel = response.data;
						editingFunnelId = null;
						$('#funnel-builder-title').text(_s.buildNewFunnel || 'Build New Funnel');
						$('#funnel-name').val(funnel.name + (_s.copySuffix || ' (Copy)')).prop('disabled', false);
						$('#funnel-description').val(funnel.description || '').prop('disabled', false);
						populateBuilderSteps(funnel);
						$('#save-funnel-builder').prop('disabled', false).text(_s.saveFunnel || 'Save Funnel');
					} else {
						alert((_s.errorLoadingFunnel || 'Error loading funnel') + ': ' + (response.data.message || _s.unknownError || 'Unknown error'));
						$('#funnel-builder-modal').removeClass('show');
						setTimeout(function() { $('#funnel-builder-modal').css('display', 'none'); }, 300);
					}
				},
				error: function() {
					alert(_s.errorLoadingFunnelRetry || 'Error loading funnel. Please try again.');
					$('#funnel-builder-modal').removeClass('show');
					setTimeout(function() { $('#funnel-builder-modal').css('display', 'none'); }, 300);
				}
			});
		};

		/**
		 * Open the builder pre-filled with a recipe (spec.md §2.3 "Customize").
		 *
		 * The suggestions panel (assets/js/funnel-suggestions.js) hands over a
		 * name, a description and the recipe's generated steps; everything else is
		 * the normal "Build New Funnel" flow, so saving goes through the SAME
		 * validation and save path as a hand-built funnel (the created row is then
		 * `source = manual`, which is correct: the user edited it).
		 *
		 * @param {string} name        Pre-filled funnel name.
		 * @param {string} description Pre-filled description.
		 * @param {Array}  steps       Step objects { name, match_type, url_pattern }.
		 */
		window.optiFunnelOpenBuilderWithSteps = function(name, description, steps) {
			editingFunnelId = null;
			resetFunnelBuilder();
			$('#funnel-builder-title').text(_s.buildNewFunnel || 'Build New Funnel');
			$('#funnel-name').val(name || '').prop('disabled', false);
			$('#funnel-description').val(description || '').prop('disabled', false);
			if (Array.isArray(steps) && steps.length > 0) {
				populateBuilderSteps({ steps: steps });
			}
			$('#save-funnel-builder').prop('disabled', false).text(_s.saveFunnel || 'Save Funnel');
			$('#funnel-builder-modal').css('display', 'flex').addClass('show');
		};
	}

	/**
	 * Re-render the funnel list after an external mutation (a funnel created
	 * from the suggestions panel). Returns false when the list markup is not on
	 * the page (empty state) — the caller then has to reload so the server can
	 * print it.
	 *
	 * @return {boolean} Whether the list was refreshed in place.
	 */
	window.optiFunnelRefreshList = function() {
		if ($('#opti-funnel-list-container').length === 0) {
			return false;
		}
		currentPage = 1;
		loadFunnelsSummary();
		return true;
	};

	// =========================================================================
	// Funnel actions (edit / duplicate / delete / reset)
	// =========================================================================

	function initFunnelActions() {
		$(document).on('click', '.funnel-more-btn', function(e) {
			e.stopPropagation();
			const dropdown = $(this).siblings('.funnel-more-dropdown');
			$('.funnel-more-dropdown').not(dropdown).removeClass('show');
			dropdown.toggleClass('show');
		});

		$(document).on('click', function(e) {
			if (!$(e.target).closest('.funnel-more-container').length) {
				$('.funnel-more-dropdown').removeClass('show');
			}
		});

		$(document).on('click', '.funnel-more-dropdown', function(e) {
			e.stopPropagation();
		});

		$(document).on('click', '.delete-funnel', function(e) {
			e.preventDefault();
			e.stopPropagation();
			const funnelId = $(this).data('funnel-id');
			const funnelCard = $('.funnel-card[data-funnel-id="' + funnelId + '"]');
			const funnelName = funnelCard.find('.funnel-title').text();
			if (confirm((_s.confirmDeleteFunnel || 'Are you sure you want to delete "%s"? This action cannot be undone.').replace('%s', funnelName))) {
				deleteFunnel(funnelId);
			}
			$('.funnel-more-dropdown').removeClass('show');
		});

		$(document).on('click', '.edit-funnel', function(e) {
			e.preventDefault();
			e.stopPropagation();
			const funnelId = $(this).data('funnel-id');
			$('.funnel-more-dropdown').removeClass('show');
			if (typeof window.loadFunnelForEdit === 'function') {
				window.loadFunnelForEdit(funnelId);
			}
		});

		$(document).on('click', '.duplicate-funnel', function(e) {
			e.preventDefault();
			e.stopPropagation();
			const funnelId = $(this).data('funnel-id');
			$('.funnel-more-dropdown').removeClass('show');
			if (typeof window.loadFunnelForDuplicate === 'function') {
				window.loadFunnelForDuplicate(funnelId);
			}
		});

		$(document).on('click', '.reset-funnel-data', function(e) {
			e.preventDefault();
			e.stopPropagation();
			const funnelId = $(this).data('funnel-id');
			const funnelCard = $('.funnel-card[data-funnel-id="' + funnelId + '"]');
			const funnelName = funnelCard.find('.funnel-title').text();
			$('.funnel-more-dropdown').removeClass('show');
			if (confirm((_s.confirmResetFunnelData || 'Are you sure you want to reset all data for "%s"?\n\nThis will delete all tracking data but keep the funnel configuration (steps and URLs).\n\nThis action cannot be undone.').replace('%s', funnelName))) {
				resetFunnelData(funnelId);
			}
		});

		// Suspend / Activate toggle — flips funnel status, then live-refreshes the
		// list (mirrors the delete/reset live-refresh path).
		$(document).on('click', '.toggle-funnel-status', function(e) {
			e.preventDefault();
			e.stopPropagation();
			const funnelId = $(this).data('funnel-id');
			const current = String($(this).data('status') || 'active');
			const newStatus = current === 'suspended' ? 'active' : 'suspended';
			$('.funnel-more-dropdown').removeClass('show');
			setFunnelStatus(funnelId, newStatus);
		});
	}

	function setFunnelStatus(funnelId, newStatus) {
		const funnelCard = $('.funnel-card[data-funnel-id="' + funnelId + '"]');
		funnelCard.css('opacity', '0.5');
		funnelCard.find('.funnel-more-btn').prop('disabled', true);

		$.ajax({
			url: optiBehaviorFunnels.ajaxUrl,
			type: 'POST',
			data: {
				action: 'optibehavior_set_funnel_status',
				nonce: optiBehaviorFunnels.nonce,
				funnel_id: funnelId,
				status: newStatus
			},
			success: function(response) {
				if (response.success) {
					// Keep local cache in sync, then live-refresh the list so the
					// row moves between the Active/Suspended status filters.
					summaryRows.forEach(function(r) {
						if (String(r.id) === String(funnelId)) {
							r.status = newStatus;
						}
					});
					loadFunnelsSummary();
					showFunnelToast(_s.statusUpdateSuccess || 'Funnel status updated successfully.', 'success');
				} else {
					funnelCard.css('opacity', '1');
					funnelCard.find('.funnel-more-btn').prop('disabled', false);
					showFunnelToast(
						(response.data && response.data.message) || _s.statusUpdateError || 'Error updating funnel status. Please try again.',
						'error'
					);
				}
			},
			error: function() {
				funnelCard.css('opacity', '1');
				funnelCard.find('.funnel-more-btn').prop('disabled', false);
				showFunnelToast(_s.statusUpdateError || 'Error updating funnel status. Please try again.', 'error');
			}
		});
	}

	function deleteFunnel(funnelId) {
		const funnelCard = $('.funnel-card[data-funnel-id="' + funnelId + '"]');
		funnelCard.css('opacity', '0.5');
		funnelCard.find('.funnel-more-btn').prop('disabled', true);

		$.ajax({
			url: optiBehaviorFunnels.ajaxUrl,
			type: 'POST',
			data: { action: 'optibehavior_delete_funnel', nonce: optiBehaviorFunnels.nonce, funnel_id: funnelId },
			success: function(response) {
				if (response.success) {
					// Drop from cache + re-render. Reload page to empty state if none left.
					summaryRows = summaryRows.filter(function(r) { return String(r.id) !== String(funnelId); });
					funnelCard.fadeOut(300, function() {
						$(this).remove();
						if (summaryRows.length === 0) {
							location.reload();
						} else {
							applyFilterAndRender();
							loadFunnelsSummary();
						}
					});
				} else {
					alert((_s.errorDeletingFunnel || 'Error deleting funnel') + ': ' + (response.data.message || _s.unknownError || 'Unknown error'));
					funnelCard.css('opacity', '1');
					funnelCard.find('.funnel-more-btn').prop('disabled', false);
				}
			},
			error: function() {
				alert(_s.errorDeletingFunnelRetry || 'Error deleting funnel. Please try again.');
				funnelCard.css('opacity', '1');
				funnelCard.find('.funnel-more-btn').prop('disabled', false);
			}
		});
	}

	function resetFunnelData(funnelId) {
		const funnelCard = $('.funnel-card[data-funnel-id="' + funnelId + '"]');
		funnelCard.css('opacity', '0.5');
		funnelCard.find('.funnel-more-btn').prop('disabled', true);

		$.ajax({
			url: optiBehaviorFunnels.ajaxUrl,
			type: 'POST',
			data: { action: 'optibehavior_reset_funnel_data', nonce: optiBehaviorFunnels.nonce, funnel_id: funnelId },
			success: function(response) {
				if (response.success) {
					if (funnelCard.hasClass('is-expanded')) {
						loadFunnelAnalytics(funnelId);
					}
					funnelCard.css('opacity', '1');
					funnelCard.find('.funnel-more-btn').prop('disabled', false);
					loadFunnelsSummary();
					showFunnelToast(
						optiBehaviorFunnels.strings.resetDataSuccess ||
						'Funnel data has been reset successfully. The funnel configuration (steps and URLs) has been preserved.',
						'success'
					);
				} else {
					showFunnelToast(
						(optiBehaviorFunnels.strings.resetDataError || 'Error resetting funnel data') +
						': ' + (response.data.message || optiBehaviorFunnels.strings.unknownError || 'Unknown error'),
						'error'
					);
					funnelCard.css('opacity', '1');
					funnelCard.find('.funnel-more-btn').prop('disabled', false);
				}
			},
			error: function() {
				showFunnelToast(
					optiBehaviorFunnels.strings.resetDataNetworkError ||
					'Error resetting funnel data. Please try again.',
					'error'
				);
				funnelCard.css('opacity', '1');
				funnelCard.find('.funnel-more-btn').prop('disabled', false);
			}
		});
	}

	// =========================================================================
	// Toast
	// =========================================================================

	function showFunnelToast(message, type) {
		type = type || 'success';
		var $toast = $('<div class="opti-funnel-toast opti-funnel-toast--' + type + '"></div>').text(message);
		$('body').append($toast);
		setTimeout(function() { $toast.addClass('show'); }, 10);
		setTimeout(function() {
			$toast.removeClass('show');
			setTimeout(function() { $toast.remove(); }, 300);
		}, 4000);
	}

	// =========================================================================
	// Funnel detail page (spec.md §4.1 / §4.2)
	// =========================================================================
	//
	// The `&funnel=<id>` URL renders a dedicated detail page (PHP:
	// render_funnel_detail()). Here we wire its kept period control and the
	// PRO advanced-filter panel, then load + draw the single funnel's KPIs and
	// step visualization. The advanced-filters payload is applied SERVER-SIDE
	// only when the PRO gate passes (ajax_funnel_data re-checks); the
	// data-advanced-pro flag is cosmetic (live panel vs locked upsell).

	// Advanced-filters payload for the detail page. Field => panel element id,
	// MUST mirror the funnel allow-list (dashboard's 16 minus exit_page).
	const FUNNEL_FILTER_FIELD_IDS = {
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
		referrer: 'filter-referrer',
		traffic_channel: 'filter-traffic-channel',
		utm_campaign: 'filter-utm-campaign',
		utm_source: 'filter-utm-source',
		utm_medium: 'filter-utm-medium'
	};

	function initFunnelDetailPage() {
		const $root = $('#opti-funnel-detail');
		if ($root.length === 0) return false;

		const funnelId = $root.data('funnel-id');
		const advancedPro = String($root.data('advanced-pro')) === '1';
		window.optiBehaviorFunnelAdvancedFilters = {};

		// Seed this funnel's period state (detail default = Last 30 Days, matches dashboard).
		const state = getFunnelState(funnelId);
		state.period = '30days';
		// ...unless the URL arrived from a Smart Insights deep link carrying the
		// cohort the insight was measured on. Overwriting it here would show a
		// different window than the link promised.
		applySmartInsightsFunnelDefaults();

		setupFunnelDetailPeriod(funnelId);
		if (advancedPro) {
			setupFunnelDetailAdvancedFilters(funnelId);
		} else {
			// Free: the toggle still reveals the (locked) panel for discoverability.
			$(document).on('click', '#toggle-advanced-filters', function() {
				const panel = document.getElementById('advanced-filters-panel');
				if (!panel) return;
				const open = panel.style.display === 'none' || panel.style.display === '';
				panel.style.display = open ? 'block' : 'none';
				this.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
		}

		refreshIcons();
		loadFunnelDetail(funnelId);
		return true;
	}

	// Compute explicit start/end dates for a period token and write them into the
	// detail date inputs (native YYYY-MM-DD value; browser renders locale DD/MM/YYYY).
	// Mirrors the dashboard's pre-filled #start-date/#end-date. periodDays matches
	// getReplayDateParams so cohorts stay consistent.
	function prefillFunnelDetailDates(state) {
		const periodDays = { '7days': 6, '30days': 29, '90days': 89 };
		let startStr = '';
		let endStr = '';
		if (state.period === 'custom') {
			startStr = state.customStart || '';
			endStr = state.customEnd || '';
		} else if (Object.prototype.hasOwnProperty.call(periodDays, state.period)) {
			const end = new Date();
			const start = new Date();
			start.setDate(end.getDate() - periodDays[state.period]);
			startStr = formatLocalDate(start);
			endStr = formatLocalDate(end);
		}
		$('#funnel-detail-start').val(startStr);
		$('#funnel-detail-end').val(endStr);
	}

	function setupFunnelDetailPeriod(funnelId) {
		const state = getFunnelState(funnelId);
		const $period = $('#funnel-detail-period');
		const $start = $('#funnel-detail-start');
		const $end = $('#funnel-detail-end');
		const $apply = $('#funnel-detail-apply-range');

		// Pre-fill the date pickers for the seeded period on first render (like dashboard).
		prefillFunnelDetailDates(state);
		// Keep the period select on the seeded state. Without this a deep link
		// carrying a custom cohort renders its dates while the control still
		// reads "Last 30 Days". Default state is 30days, i.e. the markup's own
		// selected option, so an ordinary load is unchanged.
		$period.val(state.period);

		// Date pickers + Apply stay ALWAYS visible (matches dashboard control bar).
		// Non-custom period → reload immediately; custom → wait for Apply.
		$period.on('change', function() {
			const val = $(this).val();
			if (val === 'custom') {
				return;
			}
			state.period = val;
			state.customStart = '';
			state.customEnd = '';
			prefillFunnelDetailDates(state);
			loadFunnelDetail(funnelId);
		});

		// Refresh re-runs the current funnel/period/filter state (mirrors dashboard Refresh).
		$('#funnel-detail-refresh').on('click', function() {
			loadFunnelDetail(funnelId);
		});

		$apply.on('click', function() {
			const s = $start.val();
			const e = $end.val();
			if (!s || !e) {
				alert(_s.customRangeBothRequired || 'Please select both start and end dates');
				return;
			}
			if (new Date(s) > new Date(e)) {
				alert(_s.customRangeStartBeforeEnd || 'Start date must be before end date');
				return;
			}
			state.period = 'custom';
			state.customStart = s;
			state.customEnd = e;
			state.customStartDateTime = '';
			state.customEndDateTime = '';
			loadFunnelDetail(funnelId);
		});

		refreshIcons();
	}

	// Wire the PRO advanced-filters panel. Mirrors the dashboard wiring
	// (dashboard.js) but reloads THIS funnel instead of the dashboard widgets.
	function setupFunnelDetailAdvancedFilters(funnelId) {
		const panel = document.getElementById('advanced-filters-panel');
		const toggleBtn = document.getElementById('toggle-advanced-filters');
		if (!panel || !toggleBtn) return;

		const FUI = window.OptiBehaviorFilterUI;
		if (!FUI) {
			if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('OptiBehaviorFilterUI module missing - funnel advanced filters disabled', 'funnels');
			return;
		}

		// Icon multi-selects + free-text suggestion inputs (exit_page dropped).
		FUI.enhanceIconSelect('filter-browser', 'browser');
		FUI.enhanceIconSelect('filter-country', 'country');
		FUI.enhanceIconSelect('filter-device', 'device');
		FUI.enhanceIconSelect('filter-os', 'os');
		FUI.enhanceIconSelect('filter-utm-campaign', 'utm');
		FUI.enhanceIconSelect('filter-utm-source', 'utm');
		FUI.enhanceIconSelect('filter-utm-medium', 'utm');
		const suggestInputs = {
			entry_pages: FUI.enhanceSuggestInput('filter-entry-page'),
			referrers: FUI.enhanceSuggestInput('filter-referrer')
		};

		let optionsSig = '';
		function loadFilterOptions() {
			const state = getFunnelState(funnelId);
			const sig = state.period + '|' + (state.customStart || '') + '|' + (state.customEnd || '') + '|' + getExcludeSpamFlag();
			if (optionsSig === sig) return;
			optionsSig = sig;
			const ajaxUrl = (window.optiBehaviorFunnels && optiBehaviorFunnels.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php';
			// Funnel-scoped options: counts reflect only sessions that entered THIS
			// funnel (max = Total Entries), not site-wide sessions.
			const nonce = (window.optiBehaviorFunnels && optiBehaviorFunnels.nonce) || '';
			const qs = new URLSearchParams({
				action: 'optibehavior_funnel_filter_options',
				nonce: nonce,
				funnel_id: funnelId,
				period: state.period,
				exclude_spam: getExcludeSpamFlag()
			});
			if (state.period === 'custom' && state.customStart) qs.set('start_date', state.customStart);
			if (state.period === 'custom' && state.customEnd) qs.set('end_date', state.customEnd);
			fetch(ajaxUrl + '?' + qs.toString(), { method: 'GET', credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(result) {
					if (optionsSig !== sig) return;
					if (!result || !result.success || !result.data) return;
					const data = result.data;
					const withCount = FUI.withCount;
					const vc = function(item) {
						if (item && typeof item === 'object') {
							return { value: item.value, label: withCount(item.value, item.count) };
						}
						return { value: item, label: item };
					};
					FUI.populateSelect('filter-browser', data.browsers, vc);
					FUI.populateSelect('filter-country', data.countries, function(c) {
						if (!c) return null;
						return { value: c.country, label: withCount(c.country_name || c.country, c.count) };
					});
					FUI.populateSelect('filter-device', data.devices, vc);
					FUI.populateSelect('filter-os', data.os, vc);
					FUI.populateSelect('filter-utm-campaign', data.utm_campaigns, vc);
					FUI.populateSelect('filter-utm-source', data.utm_sources, vc);
					FUI.populateSelect('filter-utm-medium', data.utm_mediums, vc);
					FUI.annotateStaticSelect('filter-visitor-type', data.visitor_types, 'value');
					FUI.annotateStaticSelect('filter-traffic-channel', data.traffic_channels, 'value');
					if (suggestInputs.entry_pages) suggestInputs.entry_pages.setItems(data.entry_pages);
					if (suggestInputs.referrers) suggestInputs.referrers.setItems(data.referrers);
					// populateSelect() rebuilds the options and only preserves
					// picks by exact value, so a deep-link value that differs in
					// case would be lost. Re-apply against the canonical options.
					syncPanelToIncomingScope();
					// The re-applied picks can change the active count.
					refreshToggleBadge();
				})
				.catch(function() {
					if (optionsSig === sig) optionsSig = '';
				});
		}

		function isPanelOpen() { return panel.style.display !== 'none' && panel.style.display !== ''; }
		function setPanelOpen(open) {
			panel.style.display = open ? 'block' : 'none';
			toggleBtn.classList.toggle('active', open);
			toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) loadFilterOptions();
		}
		toggleBtn.addEventListener('click', function() { setPanelOpen(!isPanelOpen()); });

		// Active-filter counter on the Filters toggle, delegated to the shared
		// badge module so every report screen shows the same "Filters (N)". The
		// count is derived from the controls (a filter is active when it differs
		// from its "All ..." default) plus the one scope that lives outside the
		// panel: a custom analysis window, including the one a Smart Insights
		// deep link carries.
		const FB = window.OptiBehaviorFilterBadge || null;
		const badgeFields = Object.keys(FUNNEL_FILTER_FIELD_IDS).map(function(field) {
			return FUNNEL_FILTER_FIELD_IDS[field];
		});

		function customRangeActive() {
			const periodEl = document.getElementById('funnel-detail-period');
			return periodEl && periodEl.value === 'custom' ? 1 : 0;
		}

		function refreshToggleBadge() {
			if (!FB) return 0;
			return FB.render(toggleBtn, FB.countFields(badgeFields) + customRangeActive());
		}

		// Legacy signature kept for the existing call sites; the count argument is
		// ignored now that the badge is recomputed from the controls.
		function updateToggleBadge() {
			return refreshToggleBadge();
		}

		badgeFields.forEach(function(id) {
			const el = document.getElementById(id);
			if (!el) return;
			el.addEventListener('change', refreshToggleBadge);
			el.addEventListener('input', refreshToggleBadge);
		});
		const periodSelect = document.getElementById('funnel-detail-period');
		if (periodSelect) periodSelect.addEventListener('change', refreshToggleBadge);

		// ---- Incoming deep-link scope ------------------------------------
		// A Smart Insights funnel link can arrive already scoped to a segment.
		// Show that scope in the panel AND apply it to the funnel query, so the
		// report matches what the link promised. Values are matched
		// case-insensitively (the link carries the internal key casing);
		// MySQL's collation already matches either way, so this is only about
		// the visible selection.
		function selectFieldValue(el, value) {
			if (!el || value === undefined || value === null || value === '') return;
			const wanted = ('' + value).trim();
			if (el.tagName !== 'SELECT') {
				el.value = wanted;
				return;
			}
			let match = null;
			Array.prototype.forEach.call(el.options, function(o) {
				if (!match && o.value !== '' && ('' + o.value).toLowerCase() === wanted.toLowerCase()) match = o;
			});
			if (!match) {
				match = document.createElement('option');
				match.value = wanted;
				match.textContent = wanted;
				el.appendChild(match);
			}
			if (el.multiple) {
				match.selected = true;
			} else {
				el.value = match.value;
			}
			if (typeof el._obIconSync === 'function') el._obIconSync();
		}

		function syncPanelToIncomingScope() {
			const scope = window.optiBehaviorFunnelIncomingFilterScope || {};
			const fields = Object.keys(scope);
			if (!fields.length) return false;
			fields.forEach(function(field) {
				if (!FUNNEL_FILTER_FIELD_IDS[field]) return;
				selectFieldValue(document.getElementById(FUNNEL_FILTER_FIELD_IDS[field]), scope[field]);
			});
			return true;
		}

		window.optiBehaviorFunnelIncomingFilterScope = readFunnelIncomingFilterScope();
		if (syncPanelToIncomingScope()) {
			window.optiBehaviorFunnelAdvancedFilters = Object.assign({}, window.optiBehaviorFunnelIncomingFilterScope);
			setPanelOpen(true);
		}
		// Runs unconditionally: a deep link can scope the funnel by analysis
		// window alone (no segment), which still deserves a badge.
		refreshToggleBadge();

		const applyBtn = document.getElementById('apply-advanced-filters');
		if (applyBtn) {
			applyBtn.addEventListener('click', function() {
				const filters = {};
				Object.keys(FUNNEL_FILTER_FIELD_IDS).forEach(function(field) {
					const el = document.getElementById(FUNNEL_FILTER_FIELD_IDS[field]);
					if (!el) return;
					if (el.tagName === 'SELECT' && el.multiple) {
						const vals = Array.prototype.filter.call(el.options, function(o) {
							return o.selected && ('' + o.value).trim() !== '';
						}).map(function(o) { return ('' + o.value).trim(); });
						if (vals.length === 1) { filters[field] = vals[0]; }
						else if (vals.length > 1) { filters[field] = vals; }
						return;
					}
					const val = ('' + (el.value || '')).trim();
					if (val !== '') filters[field] = val;
				});
				window.optiBehaviorFunnelAdvancedFilters = filters;
				updateToggleBadge(Object.keys(filters).length);
				loadFunnelDetail(funnelId);
				setPanelOpen(false);
			});
		}

		const resetBtn = document.getElementById('reset-advanced-filters');
		if (resetBtn) {
			resetBtn.addEventListener('click', function() {
				Object.keys(FUNNEL_FILTER_FIELD_IDS).forEach(function(field) {
					const el = document.getElementById(FUNNEL_FILTER_FIELD_IDS[field]);
					if (!el) return;
					if (el.tagName === 'SELECT' && el.multiple) {
						Array.prototype.forEach.call(el.options, function(o) { o.selected = false; });
					} else {
						el.value = '';
					}
					if (typeof el._obIconSync === 'function') el._obIconSync();
				});
				window.optiBehaviorFunnelAdvancedFilters = {};
				updateToggleBadge(0);
				loadFunnelDetail(funnelId);
			});
		}

		// ---- Filter Profiles cluster wiring -----------------------------
		// This function only runs on the non-locked (PRO) detail panel, so the
		// profile controls are wired ONLY where the cluster markup renders. On
		// the free/locked upsell panel setupFunnelDetailAdvancedFilters() is
		// never called and the shared trait omits the cluster, so nothing to
		// wire - the controls stay hidden.
		function collectFunnelAdvancedFiltersPayload() {
			const filters = {};
			Object.keys(FUNNEL_FILTER_FIELD_IDS).forEach(function(field) {
				const el = document.getElementById(FUNNEL_FILTER_FIELD_IDS[field]);
				if (!el) return;
				if (el.tagName === 'SELECT' && el.multiple) {
					const vals = Array.prototype.filter.call(el.options, function(o) {
						return o.selected && ('' + o.value).trim() !== '';
					}).map(function(o) { return ('' + o.value).trim(); });
					if (vals.length === 1) { filters[field] = vals[0]; }
					else if (vals.length > 1) { filters[field] = vals; }
					return;
				}
				const val = ('' + (el.value || '')).trim();
				if (val !== '') filters[field] = val;
			});
			return filters;
		}

		if (window.OptiBehaviorFilterProfiles && typeof window.OptiBehaviorFilterProfiles.init === 'function') {
			// Profile-key => { id, type } map for the module's default populate.
			// exit_page is intentionally absent from the funnel allow-list.
			const PROFILE_FIELD_TYPES = {
				browser: 'multi', country: 'multi', device_type: 'multi', os: 'multi',
				utm_campaign: 'multi', utm_source: 'multi', utm_medium: 'multi',
				visitor_type: 'static', traffic_channel: 'static',
				duration_min: 'numeric', duration_max: 'numeric',
				page_count_min: 'numeric', page_count_max: 'numeric',
				entry_page: 'text', referrer: 'text'
			};
			const profileFieldIds = {};
			Object.keys(FUNNEL_FILTER_FIELD_IDS).forEach(function(field) {
				profileFieldIds[field] = { id: FUNNEL_FILTER_FIELD_IDS[field], type: PROFILE_FIELD_TYPES[field] || 'text' };
			});
			// Options load lazily on first panel-open; prime the fetch so a profile
			// load's injected picks backfill their counts on the next refetch.
			loadFilterOptions();
			window.OptiBehaviorFilterProfiles.init({
				fieldIds: profileFieldIds,
				collect: collectFunnelAdvancedFiltersPayload,
				onLoaded: function(payload) {
					// Store as the applied filter set + reuse the existing reload path
					// (mirrors the Apply button behavior on the detail page).
					window.optiBehaviorFunnelAdvancedFilters = payload || {};
					updateToggleBadge(Object.keys(window.optiBehaviorFunnelAdvancedFilters).length);
					loadFunnelDetail(funnelId);
					setPanelOpen(false);
				}
			});
		}
	}

	function loadFunnelDetail(funnelId) {
		const state = getFunnelState(funnelId);
		const ajaxData = {
			action: 'optibehavior_funnel_data',
			nonce: optiBehaviorFunnels.nonce,
			funnel_id: funnelId,
			period: state.period,
			filter: 'all',
			country: 'all',
			exclude_spam: getExcludeSpamFlag()
		};
		if (state.period === 'custom') {
			ajaxData.start_date = state.customStart;
			ajaxData.end_date = state.customEnd;
		}
		const adv = window.optiBehaviorFunnelAdvancedFilters || {};
		if (Object.keys(adv).length > 0) {
			ajaxData.advanced_filters = JSON.stringify(adv);
		}

		$('.funnel-steps-container[data-funnel-id="' + funnelId + '"]').html('<div class="funnel-loading"><span class="loading-spinner"></span><span>' + (_s.loading || 'Loading...') + '</span></div>');

		$.ajax({
			url: optiBehaviorFunnels.ajaxUrl,
			type: 'POST',
			data: ajaxData,
			success: function(response) {
				if (response.success && response.data) {
					updateFunnelDetailKpis(response.data);
					renderFunnelAnalytics(funnelId, response.data);
				} else {
					showFunnelError(funnelId);
				}
			},
			error: function() {
				showFunnelError(funnelId);
			}
		});
	}

	function updateFunnelDetailKpis(data) {
		const conv = parseFloat(data.conversion_rate) || 0;
		const drop = parseFloat(data.dropoff_rate) || 0;
		$('#funnel-detail-entries').text(formatNumberShort(data.total_entries || 0));
		$('#funnel-detail-completions').text(formatNumberShort(data.completed || 0));
		$('#funnel-detail-rate').text(conv + '%')
			.removeClass(KPI_COLOR_CLASSES).addClass(conversionColorClass(conv));
		$('#funnel-detail-dropoff').text(drop + '%')
			.removeClass(KPI_COLOR_CLASSES).addClass(dropoffColorClass(drop));
	}

	// =========================================================================
	// Bootstrap
	// =========================================================================

	$(document).ready(function() {
		if ($('#opti-funnel-detail').length > 0) {
			// Single-funnel detail page (spec.md §4.1).
			applyStoredExcludeSpamFlag();
			initFunnelDetailPage();
			return;
		}
		if ($('.opti-behavior-funnels-page').length > 0) {
			// The page was rendered from $_GET, which carries no exclude_spam arg
			// on a plain menu click. Re-apply the stored preference (and mirror it
			// back into the URL + button state) BEFORE the first summary request,
			// so the toggle survives a reload (QA-B-FUNNEL-056).
			applyStoredExcludeSpamFlag();
			initFunnelAnalytics();
			initFunnelBuilder();
			initFunnelActions();
			loadFunnelsSummary();
		}
	});

})(jQuery);
