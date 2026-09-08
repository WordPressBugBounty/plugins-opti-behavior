/**
 * Auto-funnel suggestions panel (spec.md §2.3, §4.2).
 *
 * Renders the "Suggested Funnels" cards on the Funnels page — the ONLY
 * suggestion surface in v1 (locked decision 7). The panel is filled after paint
 * from `optibehavior_funnel_suggestions`, so site detection never runs during
 * the admin page render.
 *
 * Locked decision 8 — dedupe FLAGS, never hides: a card whose journey already
 * exists (`already_created`, or an equivalent `similar_funnel`) still renders,
 * with a badge, a deep link to the existing funnel and a non-primary, disabled
 * Create action. The only thing that removes a card is an explicit Dismiss,
 * and "Re-scan site" (force=1) brings every dismissed card back.
 *
 * A locked Pro recipe can arrive with an EMPTY `steps` array (the Pro plugin
 * supplies the builder through `opti_behavior_funnel_recipe_steps`), so the
 * step-chip row and both build actions degrade gracefully when there is no
 * step preview.
 *
 * 1.8.4.1 UX pass — the panel scales with the page:
 *
 * - EMPTY state (`data-surface="empty"`): unchanged. The full onboarding panel,
 *   every card in one grid, no summary bar, never collapsed.
 * - Above a funnel LIST (`data-surface="list"`): the panel defaults to a single
 *   compact summary bar ("N new · M created" + Show + Re-scan), because once
 *   funnels exist the grid is mostly cards flagged "already created". Expanding
 *   puts the not-yet-created cards first and the flagged ones behind a
 *   "Show created (N)" sub-toggle.
 *
 * That sub-toggle is USER-controlled visibility, not the dedupe hiding a card:
 * every flagged card is still in the payload, still rendered, one click away —
 * locked decision 8 is untouched.
 *
 * Both toggles persist per user through `optibehavior_funnel_suggestions_prefs`
 * (same nonce + capability guard as every other funnel endpoint). The request is
 * fire-and-forget: a failed save costs one click on the next page load, so it
 * must never surface an error over the panel's real status messages.
 *
 * @package opti-behavior
 * @version 1.0.0
 */

(function ($) {
	'use strict';

	var CFG = window.optiBehaviorFunnelSuggestions || {};
	var S = CFG.strings || {};
	var TYPES = CFG.siteTypes || {};
	// '' = the user never chose, so the per-surface default applies.
	var PREFS = CFG.prefs || {};

	// Detection badge per card. The payload carries no family key, so the badge
	// is derived from the recipe id prefix — cosmetic only: an unknown prefix
	// simply renders no family badge.
	var FAMILY_BY_PREFIX = [
		['woo_', 'woocommerce'],
		['edd_', 'edd'],
		['membership_', 'membership'],
		['lms_', 'lms'],
		['booking', 'booking'],
		['lead_', 'lead'],
		['signup', 'signup'],
		['blog_', 'blog'],
		['discovered_', 'traffic'],
		['dropoff_', 'traffic']
	];

	// Creating from the EMPTY state reloads the page (see refreshAfterCreate), so
	// the confirmation has to survive a navigation. sessionStorage, not a query
	// arg: the message is a one-shot UI artefact, must not be bookmarkable and
	// must not reappear on a manual refresh.
	var FLASH_KEY = 'optiBehaviorFunnelSuggestionFlash';

	var loaded = null; // Last payload, so Customize can reuse the card's steps.
	var busy = false;

	// Panel display state, resolved once on ready() and then owned by the user.
	var collapsed = false;
	var showCreated = false;
	var createdCount = 0; // Cards behind the "Show created (N)" sub-toggle.

	function t(key, fallback) {
		return S[key] || fallback;
	}

	function sprintf1(template, value) {
		return String(template).replace('%s', value);
	}

	// Non-negative integer, or 0 for anything unusable — the backfill count comes
	// from a server that may not report it at all (Free, or an older Pro build).
	function toCount(value) {
		var n = parseInt(value, 10);
		return (isNaN(n) || n < 0) ? 0 : n;
	}

	// =========================================================================
	// One-shot status flash across a full page reload
	// =========================================================================

	// Storage can throw (Safari private mode, disabled cookies). A lost
	// confirmation is cosmetic, so every access degrades silently.
	function storeFlash(message, kind) {
		if (!message) {
			return;
		}
		try {
			window.sessionStorage.setItem(FLASH_KEY, JSON.stringify({
				message: String(message),
				kind: kind || 'success'
			}));
		} catch (e) {
			// No storage: the message is simply not carried across the reload.
		}
	}

	// Read AND clear in one go: the flash must never survive a second reload.
	function takeFlash() {
		var raw = null;
		try {
			raw = window.sessionStorage.getItem(FLASH_KEY);
			window.sessionStorage.removeItem(FLASH_KEY);
		} catch (e) {
			return null;
		}
		if (!raw) {
			return null;
		}
		try {
			var parsed = JSON.parse(raw);
			return (parsed && parsed.message) ? parsed : null;
		} catch (e) {
			return null;
		}
	}

	function escapeHtml(text) {
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text === null || typeof text === 'undefined' ? '' : text)
			.replace(/[&<>"']/g, function (m) { return map[m]; });
	}

	function refreshIcons() {
		if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
			lucide.createIcons();
		}
	}

	function familyOf(recipeId) {
		var id = String(recipeId || '');
		for (var i = 0; i < FAMILY_BY_PREFIX.length; i++) {
			if (id.indexOf(FAMILY_BY_PREFIX[i][0]) === 0) {
				return FAMILY_BY_PREFIX[i][1];
			}
		}
		return '';
	}

	function typeLabel(slug) {
		return TYPES[slug] || slug;
	}

	function funnelUrl(funnelId) {
		var base = CFG.pageUrl || 'admin.php?page=opti-behavior-funnels';
		var sep = base.indexOf('?') === -1 ? '?' : '&';
		return base + sep + 'funnel=' + encodeURIComponent(funnelId);
	}

	function $panel() {
		return $('#opti-funnel-suggestions');
	}

	// 'empty' = the onboarding surface (no funnels yet): never collapsed, never
	// split into groups. Anything else is the above-the-list surface.
	function isListSurface() {
		var $wrap = $panel();
		return $wrap.length > 0 && 'empty' !== String($wrap.data('surface') || 'list');
	}

	// Both status nodes get the message; CSS shows exactly one of them (the bar
	// mirror only while the panel is collapsed), so the user always sees it and
	// a hidden aria-live region is never announced.
	function setStatus(message, kind) {
		var $status = $('#opti-funnel-suggestions-status, #opti-funnel-suggestions-bar-status');
		if ($status.length === 0) {
			return;
		}
		if (!message) {
			$status.empty().removeClass('is-error is-success is-info').hide();
			return;
		}
		$status
			.removeClass('is-error is-success is-info')
			.addClass('is-' + (kind || 'info'))
			.text(message)
			.show();
	}

	// =========================================================================
	// Rendering
	// =========================================================================

	function stepChips(steps) {
		if (!Array.isArray(steps) || steps.length === 0) {
			return '<span class="opti-funnel-suggestion-card__nosteps">'
				+ escapeHtml(t('stepsUnavailable', 'Step preview unavailable.'))
				+ '</span>';
		}
		return steps.map(function (step, index) {
			var pattern = step && step.url_pattern ? step.url_pattern : '';
			var title = (step && step.match_type ? step.match_type : '') + (pattern ? ' · ' + pattern : '');
			return '<span class="opti-funnel-step-chip" title="' + escapeHtml(title) + '">'
				+ '<span class="opti-funnel-step-chip__index">' + (index + 1) + '</span>'
				+ '<span class="opti-funnel-step-chip__name">' + escapeHtml(step && step.name ? step.name : '') + '</span>'
				+ '</span>';
		}).join('<span class="opti-funnel-step-chip__sep" aria-hidden="true">›</span>');
	}

	function cardBadges(card) {
		var badges = [];
		var family = familyOf(card.id);

		if (family) {
			badges.push('<span class="opti-funnel-badge opti-funnel-badge--detected">'
				+ '<i data-lucide="scan-search" aria-hidden="true"></i>'
				+ escapeHtml(sprintf1(t('detectedBadge', '%s detected'), typeLabel(family)))
				+ '</span>');
		}

		if (card.locked) {
			badges.push('<span class="opti-funnel-badge opti-funnel-badge--pro">'
				+ '<i data-lucide="lock" aria-hidden="true"></i>'
				+ escapeHtml(t('proBadge', 'Pro'))
				+ '</span>');
		}

		// Dedupe flags NEVER hide the card (locked decision 8).
		if (card.already_created) {
			badges.push('<span class="opti-funnel-badge opti-funnel-badge--exists">'
				+ '<i data-lucide="check-circle" aria-hidden="true"></i>'
				+ escapeHtml(t('alreadyCreated', 'Already created'))
				+ '</span>');
		} else if (card.similar_funnel) {
			badges.push('<span class="opti-funnel-badge opti-funnel-badge--exists">'
				+ '<i data-lucide="copy-check" aria-hidden="true"></i>'
				+ escapeHtml(t('similarExists', 'Similar funnel exists'))
				+ '</span>');
		}

		return badges.join('');
	}

	function cardActions(card) {
		var hasSteps = Array.isArray(card.steps) && card.steps.length > 0;
		var existing = card.similar_funnel && card.similar_funnel.id ? card.similar_funnel : null;
		var html = '';

		if (card.locked) {
			html += '<a class="opti-funnel-suggestion-btn opti-funnel-suggestion-btn--upgrade" href="'
				+ escapeHtml(CFG.upgradeUrl || '#') + '">'
				+ '<i data-lucide="lock" aria-hidden="true"></i>'
				+ escapeHtml(t('upgrade', 'Upgrade to unlock'))
				+ '</a>';
		} else if (existing) {
			// Secondary + disabled Create: the journey already exists, and the
			// create endpoint would answer `duplicate` anyway.
			html += '<button type="button" class="opti-funnel-suggestion-btn opti-funnel-suggestion-btn--secondary opti-funnel-suggestion-create" disabled'
				+ ' title="' + escapeHtml(t('createDisabledExists', 'A funnel with these steps already exists.')) + '">'
				+ '<i data-lucide="plus" aria-hidden="true"></i>'
				+ escapeHtml(t('createFunnel', 'Create funnel'))
				+ '</button>';
		} else {
			html += '<button type="button" class="opti-funnel-suggestion-btn opti-funnel-suggestion-btn--primary opti-funnel-suggestion-create"'
				+ (hasSteps ? '' : ' disabled')
				+ '>'
				+ '<i data-lucide="plus" aria-hidden="true"></i>'
				+ escapeHtml(t('createFunnel', 'Create funnel'))
				+ '</button>';
		}

		html += '<button type="button" class="opti-funnel-suggestion-btn opti-funnel-suggestion-btn--secondary opti-funnel-suggestion-customize"'
			+ (hasSteps ? '' : ' disabled')
			+ '>'
			+ '<i data-lucide="sliders-horizontal" aria-hidden="true"></i>'
			+ escapeHtml(t('customize', 'Customize'))
			+ '</button>';

		if (existing) {
			html += '<a class="opti-funnel-suggestion-link" href="' + escapeHtml(funnelUrl(existing.id)) + '">'
				+ escapeHtml(sprintf1(t('viewExisting', 'View "%s"'), existing.name || ''))
				+ '</a>';
		}

		return html;
	}

	function cardHtml(card) {
		return '<div class="opti-funnel-suggestion-card'
			+ (card.locked ? ' is-locked' : '')
			+ ((card.already_created || card.similar_funnel) ? ' is-existing' : '')
			+ '" data-recipe-id="' + escapeHtml(card.id) + '">'
			+ '<div class="opti-funnel-suggestion-card__head">'
			+ '<span class="opti-funnel-suggestion-card__icon"><i data-lucide="' + escapeHtml(card.icon || 'filter') + '" aria-hidden="true"></i></span>'
			+ '<div class="opti-funnel-suggestion-card__titles">'
			+ '<h3 class="opti-funnel-suggestion-card__title">' + escapeHtml(card.label || '') + '</h3>'
			+ '<p class="opti-funnel-suggestion-card__desc">' + escapeHtml(card.description || '') + '</p>'
			+ '</div>'
			+ '<button type="button" class="opti-funnel-suggestion-dismiss" title="'
			+ escapeHtml(t('dismissTitle', 'Dismiss this suggestion')) + '" aria-label="'
			+ escapeHtml(t('dismissTitle', 'Dismiss this suggestion')) + '">'
			+ '<i data-lucide="x" aria-hidden="true"></i>'
			+ '</button>'
			+ '</div>'
			+ '<div class="opti-funnel-suggestion-card__badges">' + cardBadges(card) + '</div>'
			+ '<div class="opti-funnel-suggestion-card__steps">' + stepChips(card.steps) + '</div>'
			+ '<div class="opti-funnel-suggestion-card__actions">' + cardActions(card) + '</div>'
			+ '</div>';
	}

	function detectionHtml(payload) {
		var html = (payload.site_types || []).map(function (slug) {
			return '<span class="opti-funnel-detection-pill">'
				+ '<i data-lucide="badge-check" aria-hidden="true"></i>'
				+ escapeHtml(sprintf1(t('detectedBadge', '%s detected'), typeLabel(slug)))
				+ '</span>';
		}).join('');

		var unresolved = (payload.unresolved || []).map(typeLabel);
		if (unresolved.length > 0) {
			html += '<span class="opti-funnel-detection-pill opti-funnel-detection-pill--unresolved" title="'
				+ escapeHtml(t('unresolvedHint', 'Detected, but no usable URL could be resolved - no funnel is guessed for it.')) + '">'
				+ '<i data-lucide="help-circle" aria-hidden="true"></i>'
				+ escapeHtml(sprintf1(t('unresolvedBadge', 'Unresolved: %s'), unresolved.join(', ')))
				+ '</span>';
		}

		return html;
	}

	function creatableCards(payload) {
		return (payload.suggestions || []).filter(function (card) {
			return !card.locked
				&& !card.already_created
				&& !card.similar_funnel
				&& Array.isArray(card.steps)
				&& card.steps.length > 0;
		});
	}

	// A card the user has not acted on yet. The complement — already_created or
	// similar_funnel — is what the "Show created (N)" group holds.
	function isNewCard(card) {
		return !card.already_created && !card.similar_funnel;
	}

	// =========================================================================
	// Collapsed summary bar + "show created" sub-group (1.8.4.1)
	// =========================================================================

	function applyCollapsed() {
		var effective = collapsed && isListSurface();

		$panel().toggleClass('is-collapsed', effective);
		$('#opti-funnel-suggestions-body').toggle(!effective);
		$('#opti-funnel-suggestions-toggle')
			.attr('aria-expanded', effective ? 'false' : 'true')
			.find('.opti-funnel-suggestions__bar-btn-label')
			.text(effective ? t('showPanel', 'Show') : t('hidePanel', 'Hide'));
	}

	function applyShowCreated() {
		var template = showCreated
			? t('hideCreated', 'Hide created (%d)')
			: t('showCreated', 'Show created (%d)');

		$('#opti-funnel-suggestions-created-grid').toggle(showCreated);
		$('#opti-funnel-suggestions-created-toggle')
			.attr('aria-expanded', showCreated ? 'true' : 'false')
			.text(String(template).replace('%d', createdCount));
	}

	function renderBar(newCount) {
		var $bar = $('#opti-funnel-suggestions-bar');

		if (!isListSurface()) {
			$bar.hide();
			return;
		}

		$('#opti-funnel-suggestions-bar-counts').text(
			String(t('barCounts', '%1$d new · %2$d created'))
				.replace('%1$d', newCount)
				.replace('%2$d', createdCount)
		);
		$bar.show();
	}

	// Display-only preference: fired and forgotten, because a failed save costs
	// one click on the next load and must not shout over a real status message.
	function savePrefs(data) {
		if (!isListSurface()) {
			return;
		}
		post('optibehavior_funnel_suggestions_prefs', data, function () {}, function () {});
	}

	function render(payload) {
		var $wrap = $panel();
		if ($wrap.length === 0) {
			return;
		}

		loaded = payload;
		var cards = payload.suggestions || [];

		// The empty state keeps ONE undivided grid: there is nothing created yet,
		// so grouping would only add a control that toggles an empty box.
		var grouped = isListSurface();
		var fresh = grouped ? cards.filter(isNewCard) : cards;
		var existing = grouped ? cards.filter(function (card) { return !isNewCard(card); }) : [];

		createdCount = existing.length;

		$('#opti-funnel-suggestions-detection').html(detectionHtml(payload));
		$('#opti-funnel-suggestions-created-grid').html(existing.map(cardHtml).join(''));
		$('#opti-funnel-suggestions-created').toggle(existing.length > 0);

		if (cards.length === 0) {
			$('#opti-funnel-suggestions-grid').empty();
			$('#opti-funnel-create-recommended').hide();
			$('#opti-funnel-suggestions-subtitle').text(
				t('noSuggestions', 'No funnel suggestions for this site right now. Build one manually, or re-scan after installing a store, form or membership plugin.')
			);
		} else {
			$('#opti-funnel-suggestions-subtitle').text(
				(fresh.length === 0 && existing.length > 0)
					? t('allCreated', 'Every funnel we suggest for this site already exists. Re-scan after installing or configuring a plugin.')
					: t('subtitle', 'Ready-made funnels built from your own URLs. Nothing is created until you say so.')
			);
			$('#opti-funnel-suggestions-grid').html(fresh.map(cardHtml).join(''));
			$('#opti-funnel-create-recommended').toggle(creatableCards(payload).length > 1);
		}

		renderBar(fresh.length);
		applyShowCreated();
		applyCollapsed();
		$wrap.show();
		refreshIcons();
	}

	// =========================================================================
	// Endpoints
	// =========================================================================

	// `fail` receives a second argument: TRUE when the request never produced a
	// usable JSON envelope (network drop, 500, timeout) as opposed to the server
	// answering with `success: false`. Only the former is worth retrying — a
	// refused action would refuse again.
	function post(action, data, done, fail) {
		$.ajax({
			url: CFG.ajaxUrl,
			type: 'POST',
			data: $.extend({ action: action, nonce: CFG.nonce }, data || {}),
			success: function (response) {
				if (response && response.success) {
					done(response.data || {});
					return;
				}
				fail((response && response.data && response.data.message) || t('unknownError', 'Unknown error'), false);
			},
			error: function () {
				fail(t('networkError', 'Request failed. Please try again.'), true);
			}
		});
	}

	// The Funnels page fires three admin-ajax requests at once on load. On a
	// memory- or worker-starved host one of them can lose that race and come back
	// as a transport error, which used to paint "Could not load funnel
	// suggestions." over a panel that would have loaded fine a moment later.
	// The FIRST load therefore gets one silent retry; the status bar stays on
	// "Loading suggestions…" across it, so a recovered load looks like a normal
	// (slightly slow) load and a genuinely broken one still ends on the error.
	var LOAD_RETRY_DELAY_MS = 1500;

	// `retriesLeft` is only armed by the initial page-load call. User-initiated
	// reloads (create, re-scan) pass nothing: they are explicit actions whose
	// failure the user is watching for and can repeat themselves.
	function load(force, after, retriesLeft) {
		var $wrap = $panel();
		if ($wrap.length === 0) {
			return;
		}
		setStatus(t('loading', 'Loading suggestions…'), 'info');
		post('optibehavior_funnel_suggestions', { force: force ? 1 : 0 }, function (payload) {
			setStatus('');
			render(payload);
			if (typeof after === 'function') {
				after(payload);
			}
		}, function (message, isTransportError) {
			if (isTransportError && retriesLeft > 0) {
				window.setTimeout(function () {
					load(force, after, retriesLeft - 1);
				}, LOAD_RETRY_DELAY_MS);
				return;
			}
			setStatus(t('loadError', 'Could not load funnel suggestions.') + ' ' + message, 'error');
			$wrap.show();
		});
	}

	// The funnel list markup only exists once the site has at least one funnel,
	// so creating from the EMPTY state has to reload the page to get the server
	// to print it (same rule as the builder's save path).
	//
	// `message` is re-applied AFTER the suggestion reload: load() clears the
	// status bar on success, so a confirmation set before the round-trip would
	// be wiped before the user could read it.
	function refreshAfterCreate(message, kind) {
		if ($('#opti-funnel-list-container').length === 0) {
			// Empty state: the reload throws away the whole status bar, so the
			// confirmation is parked in sessionStorage and re-displayed by the
			// ready handler once the suggestions have re-rendered.
			storeFlash(message, kind || 'success');
			window.location.reload();
			return;
		}
		if (typeof window.optiFunnelRefreshList === 'function') {
			window.optiFunnelRefreshList();
		}
		reloadKeepingStatus(message, kind || 'success');
	}

	// Re-render the cards, then restore the caller's message.
	function reloadKeepingStatus(message, kind) {
		load(false, function () {
			if (message) {
				setStatus(message, kind || 'info');
			}
		});
	}

	// "Funnel X created." — upgraded to "…and populated with N sessions of
	// history." when Pro's autopilot reported a backfill through the
	// `opti_behavior_funnel_created_result` seam. Free (and any server that does
	// not report) falls back to the plain wording.
	function createdMessage(data) {
		var name = (data && data.name) || '';
		var sessions = toCount(data && data.backfilled_sessions);

		if (data && data.backfilled && sessions > 0) {
			return String(t('createdBackfilled', 'Funnel "%1$s" created and populated with %2$d sessions of history.'))
				.replace('%1$s', name)
				.replace('%2$d', sessions);
		}

		return sprintf1(t('created', 'Funnel "%s" created.'), name);
	}

	// Same fallback rule for the bulk endpoint, over the SUM of every created
	// item's backfill (each entry carries its own count).
	function bulkCreatedMessage(data) {
		var created = (data.created || []);
		var skipped = (data.skipped || []).length;
		var sessions = created.reduce(function (sum, item) {
			return sum + (item && item.backfilled ? toCount(item.backfilled_sessions) : 0);
		}, 0);

		if (sessions > 0) {
			return String(t('bulkCreatedBackfilled', 'Created %1$d funnel(s), skipped %2$d - populated with %3$d sessions of history.'))
				.replace('%1$d', created.length)
				.replace('%2$d', skipped)
				.replace('%3$d', sessions);
		}

		return String(t('bulkCreated', 'Created %1$d funnel(s), skipped %2$d.'))
			.replace('%1$d', created.length)
			.replace('%2$d', skipped);
	}

	function createOne($button, recipeId) {
		if (busy) {
			return;
		}
		busy = true;
		var label = $button.html();
		$button.prop('disabled', true).text(t('creating', 'Creating…'));

		post('optibehavior_create_funnel_from_recipe', { recipe_id: recipeId }, function (data) {
			busy = false;
			if (data.duplicate) {
				// Not an error: the server refused to insert a second identical
				// funnel (spec.md §3.5). Surface it as information.
				reloadKeepingStatus(t('duplicate', 'A matching funnel already exists - nothing was created.'), 'info');
				return;
			}
			refreshAfterCreate(createdMessage(data), 'success');
		}, function (message) {
			busy = false;
			$button.prop('disabled', false).html(label);
			refreshIcons();
			setStatus(t('createError', 'Could not create the funnel.') + ' ' + message, 'error');
		});
	}

	function createRecommended($button) {
		if (busy) {
			return;
		}
		busy = true;
		var label = $button.html();
		$button.prop('disabled', true).text(t('creating', 'Creating…'));

		post('optibehavior_create_recommended_funnels', {}, function (data) {
			busy = false;
			var created = (data.created || []).length;
			var message = bulkCreatedMessage(data);

			// Restore the button before re-rendering: when some recipes are left
			// over, render() shows it again and it must not stay stuck on
			// "Creating…".
			$button.prop('disabled', false).html(label);
			refreshIcons();

			if (created > 0) {
				refreshAfterCreate(message, 'success');
				return;
			}
			reloadKeepingStatus(message, 'info');
		}, function (message) {
			busy = false;
			$button.prop('disabled', false).html(label);
			refreshIcons();
			setStatus(t('createError', 'Could not create the funnel.') + ' ' + message, 'error');
		});
	}

	// Drop the card from the cached payload and re-render rather than yanking the
	// node out: the summary-bar counts and the "Show created (N)" label are
	// derived from that payload, so a raw .remove() would leave both stale.
	// render() never touches the status bar, so the confirmation below survives.
	function dismiss($card, recipeId) {
		post('optibehavior_dismiss_funnel_suggestion', { recipe_id: recipeId }, function () {
			if (loaded && Array.isArray(loaded.suggestions)) {
				loaded.suggestions = loaded.suggestions.filter(function (item) {
					return item.id !== recipeId;
				});
				render(loaded);
			} else {
				$card.remove();
			}
			setStatus(t('dismissed', 'Suggestion dismissed. "Re-scan site" brings it back.'), 'info');
		}, function (message) {
			setStatus(message, 'error');
		});
	}

	function customize(recipeId) {
		var card = (loaded && loaded.suggestions || []).filter(function (item) {
			return item.id === recipeId;
		})[0];

		if (!card || !Array.isArray(card.steps) || card.steps.length === 0) {
			setStatus(t('stepsUnavailable', 'Step preview unavailable.'), 'error');
			return;
		}
		if (typeof window.optiFunnelOpenBuilderWithSteps !== 'function') {
			setStatus(t('builderUnavailable', 'The funnel builder is not available on this screen.'), 'error');
			return;
		}
		window.optiFunnelOpenBuilderWithSteps(card.label, card.description, card.steps);
	}

	// =========================================================================
	// Wiring
	// =========================================================================

	// "Re-scan site" = force=1: bypasses the 12 h detection cache AND clears every
	// dismissal, which is what makes Dismiss reversible. Reachable from the page
	// header button and from the collapsed summary bar; both run this.
	function rescan($button) {
		$button.prop('disabled', true);
		setStatus(t('rescanning', 'Scanning your site…'), 'info');
		load(true, function () {
			$button.prop('disabled', false);
			setStatus(t('rescanned', 'Site re-scanned. Dismissed suggestions are back.'), 'success');
		});
	}

	$(document).ready(function () {
		if ($panel().length === 0) {
			return;
		}

		// Resolve the display state ONCE: the stored per-user preference wins;
		// with none stored the panel starts collapsed above a funnel list (the
		// list is what the user came for) and fully expanded in the empty state.
		collapsed = isListSurface() && '0' !== String(PREFS.collapsed);
		showCreated = '1' === String(PREFS.showCreated);

		$(document).on('click', '.opti-funnel-suggestion-create', function () {
			var $button = $(this);
			createOne($button, $button.closest('.opti-funnel-suggestion-card').data('recipe-id'));
		});

		$(document).on('click', '.opti-funnel-suggestion-customize', function () {
			customize($(this).closest('.opti-funnel-suggestion-card').data('recipe-id'));
		});

		$(document).on('click', '.opti-funnel-suggestion-dismiss', function () {
			var $card = $(this).closest('.opti-funnel-suggestion-card');
			dismiss($card, $card.data('recipe-id'));
		});

		$('#opti-funnel-create-recommended').on('click', function () {
			createRecommended($(this));
		});

		$(document).on('click', '#opti-funnel-rescan, #opti-funnel-suggestions-bar-rescan', function () {
			rescan($(this));
		});

		$(document).on('click', '#opti-funnel-suggestions-toggle', function () {
			collapsed = !collapsed;
			applyCollapsed();
			savePrefs({ collapsed: collapsed ? 1 : 0 });
		});

		$(document).on('click', '#opti-funnel-suggestions-created-toggle', function () {
			showCreated = !showCreated;
			applyShowCreated();
			savePrefs({ show_created: showCreated ? 1 : 0 });
		});

		// Consume the flash BEFORE the round-trip (one-shot: a manual refresh must
		// not show it again) and re-display it AFTER, because load() clears the
		// status bar on success.
		var flash = takeFlash();

		// 1 = one silent retry if this first load hits a transport error.
		load(false, function () {
			if (flash) {
				setStatus(flash.message, flash.kind);
			}
		}, 1);
	});

})(jQuery);
