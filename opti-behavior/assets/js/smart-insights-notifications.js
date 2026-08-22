/**
 * Lightweight global Smart Insights notification launcher.
 *
 * @package opti-behavior
 */
(function() {
	'use strict';

	var config = window.optiBehaviorSmartInsightNotifications || {};
	var ajaxUrl = config.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
	var nonce = config.nonce || '';
	var i18n = config.i18n || {};
	var root = null;
	var payload = null;
	var isOpen = false;
	var isLoading = true;
	var loadingPayload = null;
	var pendingDisplayState = null;
	var stateMutationSequence = 0;
	var completedStateMutationSequence = 0;

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

	function getState(data) {
		return data && data.display_state ? String(data.display_state) : String(data && data.state ? data.state : 'visible');
	}

	function isCompactState(data) {
		var state = getState(data);

		return !!(
			data &&
			(
				data.is_compact ||
				state === 'compact' ||
				state === 'minimized' ||
				state === 'hidden' ||
				(data.compact && data.compact.enabled)
			)
		);
	}

	function getSeverityClass(data) {
		if (!data || !data.highest_severity) {
			return '';
		}

		return ' is-' + String(data.highest_severity).toLowerCase().replace(/[^a-z0-9_-]/g, '');
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
					throw new Error(result && result.data && result.data.message ? result.data.message : (i18n.error || 'Unable to load Smart Insights notifications.'));
				}
				return result.data || {};
			});
	}

	function getRequestContext(override) {
		var context = {};
		var configured = config.requestContext || {};
		Object.keys(configured).forEach(function(key) {
			context[key] = configured[key];
		});

		if (window.opti_behaviorData && window.opti_behaviorData.excludeSpam !== undefined && context.exclude_spam === undefined) {
			context.exclude_spam = window.opti_behaviorData.excludeSpam;
		}

		Object.keys(override || {}).forEach(function(key) {
			context[key] = override[key];
		});

		return context;
	}

	function getBadgeText(count) {
		count = parseInt(count, 10) || 0;
		if (count > 99) {
			return '99+';
		}
		return count > 0 ? String(count) : '';
	}

	function getUnreadText(count) {
		count = parseInt(count, 10) || 0;
		if (count > 99) {
			return i18n.badgeOverflow || '99 or more unread Smart Insights';
		}
		if (count > 0) {
			return count + ' ' + (i18n.unread || 'unread') + ' ' + (i18n.label || 'Smart Insights');
		}
		return i18n.allCaughtUp || 'All caught up';
	}

	function getShortLabel() {
		return i18n.shortLabel || i18n.label || 'Insights';
	}

	function getLauncherLabel(data) {
		var count = parseInt(data.unread_count, 10) || 0;
		if (count > 0) {
			return getShortLabel() + ': ' + getUnreadText(count);
		}
		return getShortLabel() + ': ' + (i18n.allCaughtUp || 'All caught up');
	}

	function getCompactLabel(data) {
		if (data && data.compact && data.compact.aria_label) {
			return data.compact.aria_label;
		}
		if (i18n.restoreAria) {
			return i18n.restoreAria;
		}
		return 'Restore Smart Insights notifications from compact side mode';
	}

	function getInlineCompactLabel(data) {
		if (data && data.compact && data.compact.inline_aria_label) {
			return data.compact.inline_aria_label;
		}
		if (i18n.compactLauncherAria) {
			return i18n.compactLauncherAria;
		}
		return i18n.compact || 'Hide to compact side point';
	}

	function getLoadingPayload() {
		if (loadingPayload) {
			return loadingPayload;
		}

		loadingPayload = {
			enabled: true,
			state: 'visible',
			display_state: 'visible',
			is_compact: false,
			unread_count: 0,
			highest_severity: '',
			items: [],
			center_url: '#',
			settings_url: '#',
			actions: {
				compact: 'compact',
				restore: 'restore',
				mark_seen: 'mark_seen'
			},
			compact: {
				enabled: false,
				restore_action: 'restore',
				compact_action: 'compact'
			},
			is_loading: true
		};

		return loadingPayload;
	}

	function clonePayload(data) {
		var source = data || payload || getLoadingPayload();

		return {
			enabled: source.enabled !== false,
			state: source.state || 'visible',
			display_state: source.display_state || source.state || 'visible',
			legacy_state: source.legacy_state || 'visible',
			is_compact: !!source.is_compact,
			is_hidden: !!source.is_hidden,
			is_enabled: source.is_enabled !== false,
			can_restore: !!source.can_restore,
			compact_reason: source.compact_reason || '',
			launcher_behavior: source.launcher_behavior || '',
			unread_count: parseInt(source.unread_count, 10) || 0,
			highest_severity: source.highest_severity || '',
			items: Array.isArray(source.items) ? source.items.slice(0) : [],
			center_url: source.center_url || '#',
			settings_url: source.settings_url || '#',
			include_locked: !!source.include_locked,
			actions: Object.assign({}, source.actions || {}),
			compact: Object.assign({}, source.compact || {}),
			locked_preview: source.locked_preview ? Object.assign({}, source.locked_preview) : null,
			is_loading: !!source.is_loading
		};
	}

	function applyLocalState(data, state) {
		var next = clonePayload(data);

		next.state = state;
		next.display_state = state;
		next.is_compact = state === 'compact';
		next.is_hidden = false;
		next.can_restore = next.is_compact;
		next.is_loading = isLoading && (!data || data.is_loading);

		if (next.compact) {
			next.compact.enabled = next.is_compact;
		}

		return next;
	}

	function renderBadge(count, extraClass) {
		var badge = getBadgeText(count);
		var label = getUnreadText(count);

		if (!badge) {
			return '<span class="screen-reader-text" aria-live="polite">' + escapeHtml(label) + '</span>';
		}

		return '<span class="ob-si-notification-badge ' + escapeHtml(extraClass || '') + '" aria-label="' + escapeHtml(label) + '" aria-live="polite">' + escapeHtml(badge) + '</span>';
	}

	function renderItemTime(item) {
		var human = item.detected_at_human || '';
		var exact = item.detected_at_formatted || item.detected_at || '';

		if (!human && !exact) {
			return '';
		}

		var visible = human || exact;
		var title = exact ? ' title="' + escapeHtml((i18n.detected || 'Detected') + ': ' + exact) + '"' : '';

		return '<time class="ob-si-notification-time"' + title + ' aria-label="' + escapeHtml((i18n.detected || 'Detected') + ': ' + (exact || visible)) + '">' + escapeHtml(visible) + '</time>';
	}

	function renderItem(item) {
		var severity = String(item.severity || 'Low').toLowerCase().replace(/[^a-z0-9_-]/g, '');
		var metric = item.metric_label && item.metric_value ? '<p class="ob-si-notification-metric"><span>' + escapeHtml(item.metric_label) + '</span><strong>' + escapeHtml(item.metric_value) + '</strong></p>' : '';
		var locked = item.is_locked_preview ? '<span class="ob-si-notification-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span>' : '';
		var severityLabel = item.severity_label || item.severity || i18n.low || 'Low';
		var statusLabel = item.status_label || item.status || i18n.statusNew || 'New';

		return '<article class="ob-si-notification-card is-' + escapeHtml(severity) + '">' +
			'<div class="ob-si-notification-card-meta">' +
				'<span class="ob-si-notification-severity">' + escapeHtml(severityLabel) + '</span>' +
				'<span>' + escapeHtml(i18n.status || 'Status') + ': ' + escapeHtml(statusLabel) + '</span>' +
				locked +
				renderItemTime(item) +
			'</div>' +
			'<h3>' + escapeHtml(item.title || 'Smart Insight') + '</h3>' +
			'<p class="ob-si-notification-entity">' + escapeHtml(item.entity_label || 'Site-wide') + '</p>' +
			metric +
			'<a class="ob-si-notification-open" href="' + escapeHtml(item.detail_url || '#') + '">' + escapeHtml(i18n.openInsight || 'Open insight') + '</a>' +
		'</article>';
	}

	function renderBody(data) {
		if (data && data.is_loading) {
			return '<div class="ob-si-notification-loading ob-si-notification-loading-card" role="status">' +
				'<strong>' + escapeHtml(i18n.loadingTitle || i18n.loading || 'Checking Smart Insights...') + '</strong>' +
				'<p>' + escapeHtml(i18n.loadingBody || 'Your notification list is loading. You can still collapse or restore the launcher now.') + '</p>' +
			'</div>';
		}

		var items = Array.isArray(data.items) ? data.items : [];
		if (!items.length) {
			return '<div class="ob-si-notification-empty">' +
				'<strong>' + escapeHtml(i18n.emptyTitle || 'No new Smart Insights signals right now.') + '</strong>' +
				'<p>' + escapeHtml(i18n.emptyBody || 'Open the center any time to review the full behavior workbench.') + '</p>' +
				'<a class="ob-si-notification-open" href="' + escapeHtml(data.center_url || '#') + '">' + escapeHtml(i18n.openCenter || 'Open Smart Insights center') + '</a>' +
			'</div>';
		}

		var html = items.map(renderItem).join('');
		if (data.locked_preview && data.locked_preview.title) {
			html += '<article class="ob-si-notification-card is-locked-summary">' +
				'<div class="ob-si-notification-card-meta"><span class="ob-si-notification-locked">' + escapeHtml(i18n.proLocked || 'Pro preview') + '</span></div>' +
				'<h3>' + escapeHtml(data.locked_preview.title) + '</h3>' +
				'<a class="ob-si-notification-open" href="' + escapeHtml(data.locked_preview.detail_url || data.center_url || '#') + '">' + escapeHtml(i18n.openCenter || 'Open Smart Insights center') + '</a>' +
			'</article>';
		}

		return html;
	}

	function renderCompact(data) {
		var unreadCount = parseInt(data.unread_count, 10) || 0;
		var restoreAction = data.actions && data.actions.restore ? data.actions.restore : 'restore';

		root.className = 'ob-si-notification-root is-compact' + getSeverityClass(data);
		root.innerHTML =
			'<button type="button" class="ob-si-notification-compact" data-ob-si-action="' + escapeHtml(restoreAction) + '" aria-label="' + escapeHtml(getCompactLabel(data)) + '">' +
				'<span class="ob-si-notification-compact-dot" aria-hidden="true"></span>' +
				'<span class="screen-reader-text">' + escapeHtml(i18n.restore || 'Restore Smart Insights') + '</span>' +
				renderBadge(unreadCount, 'is-compact-badge') +
			'</button>';
	}

	function renderLauncherAndPanel(data) {
		var unreadCount = parseInt(data.unread_count, 10) || 0;
		var compactAction = data.actions && data.actions.compact ? data.actions.compact : 'compact';
		var loadingText = data.is_loading ? escapeHtml(i18n.loading || 'Checking Smart Insights...') : '';

		root.className = 'ob-si-notification-root' + (isOpen ? ' is-open' : '') + (data.is_loading ? ' is-loading' : '') + getSeverityClass(data);
		root.innerHTML =
			'<div class="ob-si-notification-launcher" role="group" aria-label="' + escapeHtml(i18n.launcherGroup || 'Smart Insights notification launcher') + '">' +
				'<button type="button" class="ob-si-notification-launcher-main" aria-expanded="' + (isOpen ? 'true' : 'false') + '" aria-controls="ob-si-notification-panel" aria-label="' + escapeHtml(getLauncherLabel(data)) + '">' +
					'<span class="ob-si-notification-icon" aria-hidden="true">&#10022;</span>' +
					'<span class="ob-si-notification-label">' + escapeHtml(getShortLabel()) + '</span>' +
				'</button>' +
				renderBadge(unreadCount, '') +
				(loadingText ? '<span class="screen-reader-text" role="status">' + loadingText + '</span>' : '') +
				'<span class="ob-si-notification-launcher-divider" aria-hidden="true"></span>' +
				'<button type="button" class="ob-si-notification-inline-compact" data-ob-si-action="' + escapeHtml(compactAction) + '" aria-label="' + escapeHtml(getInlineCompactLabel(data)) + '" title="' + escapeHtml(i18n.compactLauncherTitle || i18n.compact || 'Hide to compact side point') + '">' +
					'<span aria-hidden="true">&lsaquo;</span>' +
					'<span class="screen-reader-text">' + escapeHtml(i18n.compact || 'Hide to compact side point') + '</span>' +
				'</button>' +
			'</div>' +
			'<section id="ob-si-notification-panel" class="ob-si-notification-panel" role="dialog" aria-modal="false" aria-labelledby="ob-si-notification-title" ' + (isOpen ? '' : 'hidden') + '>' +
				'<header class="ob-si-notification-header">' +
					'<div><h2 id="ob-si-notification-title">' + escapeHtml(i18n.label || 'Smart Insights') + '</h2><p>' + escapeHtml(i18n.subtitle || 'New behavior signals') + '</p></div>' +
					'<span class="ob-si-notification-count">' + escapeHtml(unreadCount > 0 ? getUnreadText(unreadCount) : (i18n.allCaughtUp || 'All caught up')) + '</span>' +
					'<div class="ob-si-notification-actions">' +
						'<button type="button" data-ob-si-action="' + escapeHtml(compactAction) + '" aria-label="' + escapeHtml(i18n.compact || 'Hide to compact side point') + '">&minus;</button>' +
						'<a href="' + escapeHtml(data.settings_url || '#') + '" aria-label="' + escapeHtml(i18n.settings || 'Settings') + '">&#9881;</a>' +
						'<button type="button" data-ob-si-action="close_panel" aria-label="' + escapeHtml(i18n.close || 'Close') + '">&times;</button>' +
					'</div>' +
				'</header>' +
				'<div class="ob-si-notification-body">' + renderBody(data) + '</div>' +
				'<footer class="ob-si-notification-footer">' +
					'<a href="' + escapeHtml(data.center_url || '#') + '">' + escapeHtml(i18n.openCenter || 'Open Smart Insights center') + '</a>' +
					'<button type="button" data-ob-si-action="mark_seen">' + escapeHtml(i18n.markSeen || 'Mark all as seen') + '</button>' +
				'</footer>' +
			'</section>';
	}

	function render(data) {
		if (!root || !data || data.enabled === false || data.state === 'disabled' || getState(data) === 'disabled') {
			if (root) {
				root.innerHTML = '';
			}
			return;
		}

		if (isCompactState(data)) {
			isOpen = false;
			renderCompact(data);
			return;
		}

		renderLauncherAndPanel(data);
	}

	function normalizeRestoredPayload(data) {
		data.state = 'visible';
		data.display_state = 'visible';
		data.is_compact = false;
		if (data.compact) {
			data.compact.enabled = false;
		}

		return data;
	}

	function preserveCurrentDisplayAfterInitialPayloadFailure(initialPayloadSequence) {
		var fallback;

		if (stateMutationSequence <= initialPayloadSequence) {
			return false;
		}

		fallback = payload ? clonePayload(payload) : clonePayload(getLoadingPayload());
		if (pendingDisplayState) {
			fallback = applyLocalState(fallback, pendingDisplayState);
		}

		fallback.is_loading = false;
		if (fallback.compact) {
			fallback.compact.enabled = fallback.is_compact;
		}

		payload = fallback;
		render(payload);

		return true;
	}

	function persistStateAction(action, previousPayload, mutationSequence) {
		var requestData = getRequestContext({ state_action: action });
		return postAjax(config.stateAction || 'optibehavior_smart_insights_notification_state', requestData)
			.then(function(data) {
				if (mutationSequence && mutationSequence !== stateMutationSequence) {
					return payload;
				}
				isLoading = false;
				completedStateMutationSequence = mutationSequence || stateMutationSequence;
				pendingDisplayState = null;
				if (action === 'restore' || action === 'show') {
					data = normalizeRestoredPayload(data);
					isOpen = true;
				}
				if (action === 'hide' || action === 'hide_to_side' || action === 'compact' || action === 'minimize') {
					isOpen = false;
				}
				payload = data;
				render(payload);
				return payload;
			})
			.catch(function(error) {
				if (mutationSequence && mutationSequence !== stateMutationSequence) {
					return payload;
				}
				pendingDisplayState = null;
				if (previousPayload && !previousPayload.is_loading) {
					payload = previousPayload;
					render(payload);
				}
				throw error;
			});
	}

	function setStateAction(action) {
		if (action === 'close_panel') {
			isOpen = false;
			render(payload || getLoadingPayload());
			return Promise.resolve(payload);
		}

		if (action === 'restore' || action === 'show') {
			var previousRestorePayload = payload ? clonePayload(payload) : null;
			stateMutationSequence++;
			var restoreMutationSequence = stateMutationSequence;
			pendingDisplayState = 'visible';
			isOpen = true;
			payload = applyLocalState(payload, 'visible');
			render(payload);
			return persistStateAction(action, previousRestorePayload, restoreMutationSequence);
		}

		if (action === 'hide' || action === 'hide_to_side' || action === 'compact' || action === 'minimize') {
			var previousCompactPayload = payload ? clonePayload(payload) : null;
			stateMutationSequence++;
			var compactMutationSequence = stateMutationSequence;
			pendingDisplayState = 'compact';
			isOpen = false;
			payload = applyLocalState(payload, 'compact');
			render(payload);
			return persistStateAction(action, previousCompactPayload, compactMutationSequence);
		}

		if (action === 'mark_seen') {
			var previousSeenPayload = payload ? clonePayload(payload) : null;
			if (payload) {
				payload.unread_count = 0;
				payload.highest_severity = '';
				render(payload);
			}
			return persistStateAction(action, previousSeenPayload);
		}

		return persistStateAction(action, payload ? clonePayload(payload) : null);
	}

	function refreshNotifications(context) {
		if (!root) {
			return Promise.resolve(payload);
		}

		config.requestContext = getRequestContext(context || {});

		return postAjax(config.payloadAction || 'optibehavior_smart_insights_notifications', config.requestContext)
			.then(function(data) {
				isLoading = false;
				payload = pendingDisplayState ? applyLocalState(data, pendingDisplayState) : data;
				render(payload);
				return payload;
			})
			.catch(function() {
				return payload;
			});
	}

	window.optiBehaviorSmartInsightNotificationsRefresh = refreshNotifications;

	function bindRoot() {
		root.addEventListener('click', function(event) {
			var action = event.target.closest('[data-ob-si-action]');
			if (action) {
				event.preventDefault();
				setStateAction(action.getAttribute('data-ob-si-action')).catch(function() {
					render(payload);
				});
				return;
			}

			var launcher = event.target.closest('.ob-si-notification-launcher-main');
			if (launcher) {
				event.preventDefault();
				isOpen = !isOpen;
				render(payload || getLoadingPayload());
			}
		});

		document.addEventListener('keydown', function(event) {
			if (event.key === 'Escape' && isOpen) {
				isOpen = false;
				render(payload || getLoadingPayload());
			}
		});
	}

	ready(function() {
		root = document.getElementById('opti-behavior-smart-insights-notifications-root');
		if (!root) {
			return;
		}

		payload = getLoadingPayload();
		render(payload);
		bindRoot();

		function fireInitial() {
			var initialPayloadSequence = stateMutationSequence;
			postAjax(config.payloadAction || 'optibehavior_smart_insights_notifications', getRequestContext())
				.then(function(data) {
					if (completedStateMutationSequence > initialPayloadSequence && !pendingDisplayState) {
						return;
					}
					isLoading = false;
					payload = pendingDisplayState ? applyLocalState(data, pendingDisplayState) : data;
					render(payload);
				})
				.catch(function() {
					isLoading = false;
					if (preserveCurrentDisplayAfterInitialPayloadFailure(initialPayloadSequence)) {
						return;
					}
					root.innerHTML = '';
				});
		}

		// FIX 4 (Section-1 speed): on the analytics page, defer the bell's initial
		// fetch until Section-1 KPIs resolve so it does not compete for the early
		// connection budget. Other admin pages keep firing immediately.
		if (window.optiBehaviorDeferSmartInsights) {
			if (window.optiBehaviorSection1Ready) {
				fireInitial();
			} else {
				window.addEventListener('optibehavior:section1ready', fireInitial, { once: true });
			}
		} else {
			fireInitial();
		}
	});
})();
