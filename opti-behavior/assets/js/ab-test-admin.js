/**
 * Opti-Behavior A/B Test Admin JS
 *
 * Handles the test list, builder wizard, and results page interactions.
 *
 * @package opti-behavior
 * @since   1.3.0
 */
(function($) {
	'use strict';

	var cfg     = window.optiBehaviorABAdmin || {};
	var strings = cfg.strings || {};
	var ajaxUrl = cfg.ajax_url;
	var nonce   = cfg.nonce;
	var isPro   = cfg.is_pro;
	var limits  = cfg.free_limits || {};

	// -------------------------------------------------------------------------
	// Preview thumbnails (variant/control cards + results cards) render the
	// target page inside an iframe at a wide "desktop" viewport, then CSS-scale
	// that render down to fit the small card. The render width is DYNAMIC: it
	// follows the current admin browser viewport, so a desktop admin sees a
	// desktop layout and a small/mobile viewport sees a mobile layout — instead
	// of the iframe collapsing to its ~280px container and always rendering the
	// theme's mobile breakpoint.
	//
	// Fully defensive: on any error / missing layout it leaves the element on
	// its static CSS default (width:1440px + fixed scale), so it can never break
	// rendering of the surrounding admin UI.
	//
	// @param {HTMLIFrameElement} iframe   The preview iframe element.
	// @param {boolean}           centered When true, keep the translateX(-50%)
	//                                      horizontal centering (results cards).
	function applyDynamicPreviewSize( iframe, centered ) {
		try {
			if ( ! iframe || ! iframe.parentElement ) { return; }
			var containerWidth = iframe.parentElement.clientWidth;
			if ( ! containerWidth ) { return; } // not laid out yet — keep CSS default
			var renderWidth = window.innerWidth || 0;
			// Small floor keeps the scale math finite; no upper clamp so the
			// render width tracks the real viewport and the preview switches
			// between desktop and mobile layouts naturally.
			if ( renderWidth < 320 ) { renderWidth = 320; }
			var scale = containerWidth / renderWidth;
			iframe.style.width = renderWidth + 'px';
			iframe.style.transform = ( centered ? 'translateX(-50%) ' : '' ) + 'scale(' + scale + ')';
		} catch ( e ) {}
	}

	// Re-apply dynamic sizing to every existing preview iframe. Used on window
	// resize so previews stay in sync with the current viewport. Scoped strictly
	// to the two preview iframe classes — touches nothing else in the admin UI.
	function refreshAllPreviewSizes() {
		$('.ob-vcard__left-iframe').each(function() { applyDynamicPreviewSize( this, false ); });
		$('.ob-res-vcard__iframe').each(function() { applyDynamicPreviewSize( this, true ); });
	}

	function getExcludeSpamFlag() {
		var params = new URLSearchParams(window.location.search);
		if (params.has('exclude_spam')) {
			return params.get('exclude_spam') === '1' ? '1' : '0';
		}
		if (cfg.exclude_spam !== undefined) {
			return String(cfg.exclude_spam) === '1' ? '1' : '0';
		}
		if (window.optiBehaviorCurrentExcludeSpamFlag) {
			return window.optiBehaviorCurrentExcludeSpamFlag();
		}
		return '1';
	}

	function setExcludeSpamFlag(value) {
		var flag = value === '1' ? '1' : '0';
		var $button = $('#opti-ab-exclude-spam-toggle');
		cfg.exclude_spam = flag;
		window.optiBehaviorCurrentExcludeSpamFlag = function() {
			return String(cfg.exclude_spam) === '1' ? '1' : '0';
		};
		if ($button.length) {
			$button.toggleClass('active', flag === '1');
			$button.attr('aria-pressed', flag === '1' ? 'true' : 'false');
			$button.find('i[data-lucide]').attr('data-lucide', flag === '1' ? 'shield-check' : 'shield-off');
		}
		var url = new URL(window.location.href);
		url.searchParams.set('exclude_spam', flag);
		window.history.replaceState({}, '', url.toString());
		if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
			lucide.createIcons();
		}
	}

	// Pagination state.
	var currentPage = 1;
	var PER_PAGE    = 10;

	// Custom confirm modal: stores the callback to run on confirmation.
	var confirmActionCallback = null;

	// Prevent multiple first-save requests from creating duplicate draft tests
	// while the user moves quickly through wizard steps.
	var autoSaveInFlight = false;

	// =========================================================================
	// Initialization
	// =========================================================================

	$(document).ready(function() {
		// Detect current view.
		if ( $('#opti-ab-test-list').length ) {
			initTestList();
		}
		if ( $('#opti-ab-wizard').length ) {
			initWizard();
		}
		if ( $('#opti-ab-results-summary').length ) {
			initResults();
		}

		// Global action buttons.
		$(document).on('click', '.opti-ab-action-btn', handleActionButton);
		initConfirmModal();

		// Keep preview thumbnails in sync with the viewport (desktop <-> mobile).
		$(window).on('resize.optiAbPreview', debounce(refreshAllPreviewSizes, 200));
	});

	// =========================================================================
	// Test List
	// =========================================================================

	function initTestList() {
		setExcludeSpamFlag(getExcludeSpamFlag());
		loadTests();

		$('#opti-ab-filter-status, #opti-ab-filter-type').on('change', function() {
			currentPage = 1;
			loadTests();
		});
		$('#opti-ab-search').on('input', debounce(function() {
			currentPage = 1;
			loadTests();
		}, 300));

		// Pagination button click delegation.
		$(document).on('click', '.opti-ab-pagination__btn', function() {
			if ( $(this).is(':disabled') || $(this).hasClass('is-active') ) return;
			var page = parseInt($(this).data('page'), 10);
			if ( page ) {
				currentPage = page;
				loadTests();
			}
		});

		$('#opti-ab-exclude-spam-toggle').on('click', function(e) {
			e.preventDefault();
			currentPage = 1;
			setExcludeSpamFlag(getExcludeSpamFlag() === '1' ? '0' : '1');
			loadTests();
		});
	}

	function loadTests() {
		var $list = $('#opti-ab-test-list');
		$list.html('<div class="opti-ab-loading"><div class="opti-ab-spinner"></div><p>' + strings.loading + '</p></div>');

		$.post(ajaxUrl, {
			action:   'opti_behavior_ab_get_tests',
			nonce:    nonce,
			status:   $('#opti-ab-filter-status').val(),
			type:     $('#opti-ab-filter-type').val(),
			search:   $('#opti-ab-search').val(),
			page_num: currentPage,
			per_page: PER_PAGE,
			exclude_spam: getExcludeSpamFlag()
		}, function(res) {
			if ( ! res.success ) {
				$list.html('<div class="notice notice-error"><p>' + (res.data && res.data.message ? res.data.message : strings.error) + '</p></div>');
				return;
			}

			var tests      = res.data.tests || [];
			var total      = res.data.total || 0;
			var totalPages = Math.ceil(total / PER_PAGE) || 1;
			updateQuickStats(res.data.stats || {});

			// If the current page is beyond the last page (e.g. after a delete), step back.
			if ( currentPage > 1 && currentPage > totalPages ) {
				currentPage = totalPages;
				loadTests();
				return;
			}

			if ( tests.length === 0 ) {
				$list.html('');
				$('#opti-ab-pagination').html('');
				$('#opti-ab-empty-state').show();
				return;
			}

			$('#opti-ab-empty-state').hide();
			renderTestList(tests, $list);
			renderPagination(total, currentPage, PER_PAGE);
		}).fail(function() {
			$list.html('<div class="notice notice-error"><p>' + strings.error + '</p></div>');
		});
	}

	function renderTestList(tests, $container) {
		var html = '';

		tests.forEach(function(test) {
			var resultsUrl = cfg.ajax_url.replace('admin-ajax.php', 'admin.php') + '?page=opti-behavior-ab-testing&view=results&test_id=' + test.id + '&exclude_spam=' + getExcludeSpamFlag();
			var editUrl    = cfg.ajax_url.replace('admin-ajax.php', 'admin.php') + '?page=opti-behavior-ab-testing&view=builder&test_id=' + test.id;

			var impressions = parseInt(test.total_impressions, 10) || 0;
			var conversions = parseInt(test.primary_conversions !== undefined ? test.primary_conversions : test.total_conversions, 10) || 0;
			var allGoalConversions = parseInt(test.all_goal_conversions !== undefined ? test.all_goal_conversions : conversions, 10) || 0;
			var rate        = impressions > 0 ? (conversions / impressions * 100) : 0;
			var rateText    = impressions > 0 ? rate.toFixed(rate >= 10 ? 0 : 1) + '%' : '—';
			var isConcluded = (test.status === 'completed' || test.status === 'archived');

			html += '<div class="opti-ab-test-card" data-test-id="' + test.id + '">';
			html += '  <div class="opti-ab-test-card__main">';
			html += '    <h3 class="opti-ab-test-card__name">';
			html += '      <a href="' + resultsUrl + '">' + escHtml(test.name || ( strings.untitled_test || 'Untitled Test' )) + '</a>';
			if ( isConcluded && test.winner_variant_name ) {
				html += '      <span class="opti-ab-test-card__winner" title="' + escAttr( strings.tooltip_winning_variant || 'Winning variant' ) + '">'
					+ '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>'
					+ ( strings.winner_prefix || 'Winner: ' ) + escHtml(test.winner_variant_name)
					+ '</span>';
			}
			html += '    </h3>';
			html += '    <div class="opti-ab-test-card__meta">';
			html += '      <span class="opti-ab-badge opti-ab-badge--' + test.status + '">' + ucfirst(test.status) + '</span>';
			html += '      <span>' + ucfirst((test.test_type || '').replace(/_/g, ' ')) + '</span>';
			if ( test.started_at ) {
				html += '      <span>' + escHtml( strings.started_prefix || 'Started: ' ) + formatDate(test.started_at) + '</span>';
			}
			if ( isConcluded && test.ended_at ) {
				html += '      <span>' + escHtml( strings.ended_prefix || 'Ended: ' ) + formatDate(test.ended_at) + '</span>';
				var durDays = diffDays(test.started_at, test.ended_at);
				if ( durDays !== null ) {
					html += '      <span>' + durDays + ' ' + (durDays === 1 ? ( strings.day_one || 'day' ) : ( strings.day_many || 'days' )) + '</span>';
				}
			}
			html += '    </div>';
			html += '  </div>';

			// Quick stats — three pills: Impressions / Conversions / Conversion Rate.
			html += '  <div class="opti-ab-test-card__stats">';
			html += '    <div class="opti-ab-test-card__stat opti-ab-test-card__stat--impressions">';
			html += '      <span class="opti-ab-test-card__stat-value">' + formatNumber(impressions) + '</span>';
			html += '      <span class="opti-ab-test-card__stat-label">' + escHtml( strings.stat_impressions || 'Impressions' ) + '</span>';
			html += '    </div>';
			html += '    <div class="opti-ab-test-card__stat opti-ab-test-card__stat--conversions">';
			html += '      <span class="opti-ab-test-card__stat-value">' + formatNumber(conversions) + '</span>';
			html += '      <span class="opti-ab-test-card__stat-label">' + escHtml( strings.stat_primary_conversions || 'Primary Conversions' ) + '</span>';
			html += '    </div>';
			html += '    <div class="opti-ab-test-card__stat opti-ab-test-card__stat--rate" title="' + escAttr( strings.tooltip_primary_rate || 'Primary-goal conversion rate (primary conversions / impressions)' ) + '">';
			html += '      <span class="opti-ab-test-card__stat-value">' + rateText + '</span>';
			html += '      <span class="opti-ab-test-card__stat-label">' + escHtml( strings.stat_primary_rate || 'Primary Rate' ) + '</span>';
			html += '    </div>';
			html += '    <div class="opti-ab-test-card__stat opti-ab-test-card__stat--all-conversions" title="' + escAttr( strings.tooltip_all_goal_conv || 'All goal conversions can exceed impressions when a visitor completes multiple goals.' ) + '">';
			html += '      <span class="opti-ab-test-card__stat-value">' + formatNumber(allGoalConversions) + '</span>';
			html += '      <span class="opti-ab-test-card__stat-label">' + escHtml( strings.stat_all_goal_conv || 'All-Goal Conv.' ) + '</span>';
			html += '    </div>';
			html += '  </div>';

			// Actions
			html += '  <div class="opti-ab-test-card__actions">';
			if ( test.status === 'draft' ) {
				html += '<a href="' + editUrl + '" class="button">' + escHtml( strings.card_edit || 'Edit' ) + '</a>';
				html += '<button class="button button-primary opti-ab-action-btn" data-action="start" data-test-id="' + test.id + '">' + escHtml( strings.card_start || 'Start' ) + '</button>';
			} else if ( test.status === 'running' ) {
				html += '<a href="' + resultsUrl + '" class="button button-primary">' + escHtml( strings.card_results || 'Results' ) + '</a>';
				html += '<button class="button opti-ab-action-btn" data-action="pause" data-test-id="' + test.id + '">' + escHtml( strings.card_pause || 'Pause' ) + '</button>';
			} else if ( test.status === 'paused' ) {
				html += '<a href="' + resultsUrl + '" class="button">' + escHtml( strings.card_results || 'Results' ) + '</a>';
				html += '<button class="button button-primary opti-ab-action-btn" data-action="start" data-test-id="' + test.id + '">' + escHtml( strings.card_resume || 'Resume' ) + '</button>';
			} else if ( test.status === 'completed' || test.status === 'archived' ) {
				html += '<a href="' + resultsUrl + '" class="button button-primary">' + escHtml( strings.card_results || 'Results' ) + '</a>';
			} else if ( test.status === 'applied' ) {
				// DEF-AB-005 fix: applied tests used to render ONLY the delete + duplicate icons,
				// leaving users with no way to open the Results page or roll back the applied winner.
				// Surface both Results (primary CTA) and the rollback entry-point so the applied
				// state isn't a dead-end.
				html += '<a href="' + resultsUrl + '" class="button button-primary">' + escHtml( strings.card_results || 'Results' ) + '</a>';
			}
			html += '<button class="button opti-ab-action-btn" data-action="duplicate" data-test-id="' + test.id + '" title="' + escAttr( strings.tooltip_duplicate || 'Duplicate' ) + '">⎘</button>';
			html += '<button class="button button-link-delete opti-ab-action-btn" data-action="delete" data-test-id="' + test.id + '" title="' + escAttr( strings.tooltip_delete || 'Delete' ) + '">✕</button>';
			html += '  </div>';
			html += '</div>';
		});

		$container.html(html);
	}

	// Returns whole-day difference between two date strings, or null if invalid.
	function diffDays(startStr, endStr) {
		if ( ! startStr || ! endStr ) return null;
		var start = new Date(startStr);
		var end   = new Date(endStr);
		if ( isNaN(start.getTime()) || isNaN(end.getTime()) ) return null;
		var diff = Math.max(0, Math.round((end - start) / 86400000));
		return diff;
	}

	function renderPagination(total, page, perPage) {
		var $pag = $('#opti-ab-pagination');

		// Single page — hide controls.
		if ( total <= perPage ) {
			$pag.html('');
			return;
		}

		var totalPages = Math.ceil(total / perPage);

		// Compute the visible window (max 5 page buttons).
		var winStart = Math.max(1, page - 2);
		var winEnd   = Math.min(totalPages, winStart + 4);
		if ( winEnd - winStart < 4 ) {
			winStart = Math.max(1, winEnd - 4);
		}

		var html = '<div class="opti-ab-pagination__controls">';

		// Previous button.
		html += '<button class="opti-ab-pagination__btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>&larr;</button>';

		// Page number buttons.
		for ( var i = winStart; i <= winEnd; i++ ) {
			html += '<button class="opti-ab-pagination__btn' + (i === page ? ' is-active' : '') + '" data-page="' + i + '">' + i + '</button>';
		}

		// Next button.
		html += '<button class="opti-ab-pagination__btn" data-page="' + (page + 1) + '"' + (page >= totalPages ? ' disabled' : '') + '>&rarr;</button>';

		html += '</div>';

		// Info text.
		var from = (page - 1) * perPage + 1;
		var to   = Math.min(page * perPage, total);
		html += '<div class="opti-ab-pagination__info">' + ( strings.pagination_info || 'Showing %1$s–%2$s of %3$s tests' ).replace( '%1$s', from ).replace( '%2$s', to ).replace( '%3$s', total ) + '</div>';

		$pag.html(html);
	}

	function updateQuickStats(stats) {
		$('#opti-ab-stat-running').text(stats.running || 0);
		$('#opti-ab-stat-draft').text(stats.draft || 0);
		$('#opti-ab-stat-completed').text(stats.completed || 0);
		$('#opti-ab-stat-impressions').text(formatNumber(stats.total_impressions || 0));
	}

	// =========================================================================
	// Action Buttons (Start, Pause, Stop, Delete, Duplicate)
	// =========================================================================

	function handleActionButton(e) {
		e.preventDefault();
		var $btn   = $(this);
		var action = $btn.data('action');
		var testId = $btn.data('test-id');

		if ( ! action || ! testId ) return;

		// Destructive actions use the plugin confirm modal instead of native confirm().
		if ( action === 'delete' ) {
			showConfirmModal({
				title:       strings.confirm_delete_title || 'Delete A/B Test',
				message:     strings.confirm_delete || 'Are you sure you want to delete this test? This action cannot be undone.',
				confirmText: strings.confirm_delete_button || 'Delete',
				buttonClass: 'opti-ab-confirm-modal__btn-danger'
			}, function() {
				performAction($btn, action, testId);
			});
			return;
		}

		if ( action === 'stop' ) {
			showConfirmModal({
				title:       strings.confirm_stop_title || 'Stop A/B Test',
				message:     strings.confirm_stop || 'Are you sure you want to stop this test? You will not be able to resume it.',
				confirmText: strings.confirm_stop_button || 'Stop Test',
				buttonClass: 'opti-ab-confirm-modal__btn-danger'
			}, function() {
				performAction($btn, action, testId);
			});
			return;
		}

		performAction($btn, action, testId);
	}

	function performAction($btn, action, testId) {
		var ajaxAction = 'opti_behavior_ab_' + action + '_test';

		$btn.prop('disabled', true).text('…');

		$.post(ajaxUrl, {
			action:  ajaxAction,
			nonce:   nonce,
			test_id: testId
		}, function(res) {
			if ( res.success ) {
				showToast(res.data.message || ( strings.action_done || 'Done!' ), 'success');
				// Refresh list if on list page.
				if ( $('#opti-ab-test-list').length ) {
					loadTests();
				} else {
					// On results page, reload.
					window.location.reload();
				}
			} else {
				showToast(res.data && res.data.message ? res.data.message : strings.error, 'error');
				$btn.prop('disabled', false).text(ucfirst(action));
			}
		}).fail(function() {
			showToast(strings.error, 'error');
			$btn.prop('disabled', false).text(ucfirst(action));
		});
	}

	// =========================================================================
	// Custom Confirm Modal
	// =========================================================================

	function initConfirmModal() {
		// Cancel button hides the modal without action.
		$('#opti-ab-modal-cancel').on('click', function() {
			hideConfirmModal();
		});

		// Confirm button runs the stored callback then hides.
		$('#opti-ab-modal-confirm').on('click', function() {
			var cb = confirmActionCallback; // Save reference before hideConfirmModal() clears it.
			hideConfirmModal();
			if ( typeof cb === 'function' ) {
				cb();
			}
		});

		// Clicking the backdrop (overlay) also cancels.
		$('#opti-ab-confirm-modal').on('click', function(e) {
			if ( e.target === this ) {
				hideConfirmModal();
			}
		});

		// DEF-AB-006 fix: ESC key dismisses the confirm modal (WCAG a11y covenant).
		// Listener is bound on document once; it no-ops when the modal is hidden so
		// it doesn't interfere with other ESC-handling UI elsewhere on the page.
		$(document).on('keydown.optiAbConfirmModal', function(e) {
			if ( e.key !== 'Escape' && e.keyCode !== 27 ) {
				return;
			}
			var $modal = $('#opti-ab-confirm-modal');
			if ( $modal.length && $modal.hasClass('is-visible') ) {
				hideConfirmModal();
				e.preventDefault();
				e.stopPropagation();
			}
		});
	}

	function showConfirmModal(options, callback) {
		var settings = $.extend({
			title:       strings.confirm_delete_title || 'Delete A/B Test',
			message:     strings.confirm_delete || 'Are you sure you want to delete this test? This action cannot be undone.',
			confirmText: strings.confirm_delete_button || 'Delete',
			cancelText:  strings.confirm_cancel || 'Cancel',
			buttonClass: 'opti-ab-confirm-modal__btn-danger'
		}, options || {});

		confirmActionCallback = callback;

		$('#opti-ab-modal-title').text(settings.title);
		$('#opti-ab-modal-message').text(settings.message);
		$('#opti-ab-modal-cancel').text(settings.cancelText);
		$('#opti-ab-modal-confirm')
			.text(settings.confirmText)
			.removeClass('opti-ab-confirm-modal__btn-delete opti-ab-confirm-modal__btn-danger opti-ab-confirm-modal__btn-primary')
			.addClass(settings.buttonClass);

		// Add the CSS-animated overlay class, removing the inline display:none.
		$('#opti-ab-confirm-modal')
			.removeAttr('style')
			.addClass('opti-ab-confirm-overlay is-visible');

		$('#opti-ab-modal-cancel').trigger('focus');
	}

	function hideConfirmModal() {
		$('#opti-ab-confirm-modal')
			.removeClass('is-visible opti-ab-confirm-overlay')
			.css('display', 'none');
		confirmActionCallback = null;
	}

	// =========================================================================
	// Builder Wizard
	// =========================================================================

	var wizardStep   = 1;
	var wizardData   = {};
	var wizardTestId = 0;

	function initWizard() {
		// Hides the floating Smart Insights launcher, which covered the wizard's
		// bottom-right Next / Save / Launch buttons (smart-insights-notifications.css).
		document.body.classList.add('opti-ab-wizard-open');
		var $wizard  = $('#opti-ab-wizard');
		var testId   = parseInt($wizard.data('test-id'), 10) || 0;
		wizardTestId = testId;
		var testJson = $wizard.data('test');
		var varsJson = $wizard.data('variants');
		var goalsJson = $wizard.data('goals');
		var urlParams = new URLSearchParams(window.location.search);
		var linkedTargetUrl = urlParams.get('target_url') || '';
		var linkedTargetPostId = parseInt(urlParams.get('target_post_id'), 10) || 0;
		// Smart Insights story prefill. Only ever present when the builder was
		// opened from an insight AND no existing test is being edited, so the
		// classic builder path is untouched.
		var insightPrefill = ( ! testId ) ? readInsightPrefill($wizard) : null;

		if ( insightPrefill && insightPrefill.target_url && ! linkedTargetUrl ) {
			linkedTargetUrl = String(insightPrefill.target_url);
		}
		if ( insightPrefill && insightPrefill.target_post_id && ! linkedTargetPostId ) {
			linkedTargetPostId = parseInt(insightPrefill.target_post_id, 10) || 0;
		}

		// Pre-populate from existing test.
		if ( testJson && typeof testJson === 'object' ) {
			wizardData.test_type = testJson.test_type;
			wizardData.name      = testJson.name;
			wizardData.target_url = testJson.target_url;
			wizardData.target_post_id = testJson.target_post_id;
			wizardData.confidence_level = testJson.confidence_level;
			wizardData.traffic_percentage = testJson.traffic_percentage;
			wizardData.min_sample_size = testJson.min_sample_size;
			wizardData.min_duration_days = testJson.min_duration_days;
		} else if ( linkedTargetUrl || linkedTargetPostId ) {
			wizardData.test_type = linkedTargetPostId ? 'element' : 'page_split';
			wizardData.target_url = linkedTargetUrl;
			wizardData.target_post_id = linkedTargetPostId;
		}

		if ( insightPrefill && insightPrefill.test_type ) {
			wizardData.test_type = String(insightPrefill.test_type);
		}

		// Init variant rows.
		var variants = ( varsJson && Array.isArray(varsJson) ) ? varsJson : [];
		if ( variants.length === 0 ) {
			// Default: Control + Variant B.
			addVariantRow(true, ( strings.variant_badge_control || 'Control' ), '');
			addVariantRow(false, ( strings.chart_variant_prefix || 'Variant ' ) + 'B', '');
		} else {
			variants.forEach(function(v) {
				addVariantRow(!!parseInt(v.is_control, 10), v.name, v.variant_data || '{}', parseInt(v.traffic_weight, 10) || 50, v.id || 0);
			});
		}

		// Pre-fill type-specific variant fields when editing an existing test.
		if ( wizardData.test_type ) {
			updateVariantTypeFields(wizardData.test_type);
		}

		// Init goal rows.
		var goals = ( goalsJson && Array.isArray(goalsJson) ) ? goalsJson : [];
		if ( goals.length === 0 ) {
			var prefillGoal = ( insightPrefill && insightPrefill.goal && typeof insightPrefill.goal === 'object' ) ? insightPrefill.goal : null;
			addGoalRow(
				( prefillGoal && prefillGoal.type ) ? String(prefillGoal.type) : 'page_visit',
				( prefillGoal && prefillGoal.config ) ? prefillGoal.config : ''
			);
		} else {
			goals.forEach(function(g) {
				addGoalRow(g.goal_type, g.goal_config || '{}');
			});
		}

		initElementTargetPicker();

		// Select pre-chosen type.
		if ( wizardData.test_type ) {
			$('.opti-ab-type-card[data-type="' + wizardData.test_type + '"] input').prop('checked', true);
			updateTargetFieldsForType(wizardData.test_type);
			updateGoalTypesForTestType(wizardData.test_type);
		}
		applySmartInsightsTargetPrefill(linkedTargetUrl, linkedTargetPostId);
		applyInsightStoryPrefill(insightPrefill);

		// Type selection.
		$('.opti-ab-type-card input[name="test_type"]').on('change', function() {
			wizardData.test_type = $(this).val();
			updateTargetFieldsForType(wizardData.test_type);
			updateVariantTypeFields(wizardData.test_type);
			updateGoalTypesForTestType(wizardData.test_type);
		});

		// Navigation.
		$('#opti-ab-wizard-next').on('click', function() {
			if ( validateStep(wizardStep) ) {
				autoSaveDraft();
				goToStep(wizardStep + 1);
			}
		});
		$('#opti-ab-wizard-prev').on('click', function() {
			autoSaveDraft();
			goToStep(wizardStep - 1);
		});

		// Auto-save when the user leaves the page (tab close, browser nav, admin menu click, etc.).
		$(window).on('beforeunload.optiABWizard', function() {
			autoSaveDraft();
		});

		// Save draft.
		$('#opti-ab-wizard-save-draft').on('click', function() {
			saveTest('draft');
		});

		// Launch.
		$('#opti-ab-wizard-launch').on('click', function() {
			saveTest('launch');
		});

		// Add variant/goal buttons.
		$('#opti-ab-add-variant').on('click', function() {
			var idx = $('#opti-ab-variants-list .opti-ab-variant-row').length;
			var maxVariants = isPro ? 10 : ( parseInt( limits.max_variants_per_test, 10 ) || 3 );
			if ( idx >= maxVariants ) {
				showToast( ( strings.limit_variants_reached || 'Free plan limit reached: maximum %d variants per test.' ).replace( '%d', maxVariants ), 'error' );
				return;
			}
			var letter = String.fromCharCode(65 + idx); // A, B, C, D...
			addVariantRow(false, ( strings.chart_variant_prefix || 'Variant ' ) + letter, '');
			redistributeWeights();
			// Only populate the newly added row — don't rebuild existing iframes.
			var $newRow = $('#opti-ab-variants-list .opti-ab-variant-row').last();
			updateVariantTypeFields(wizardData.test_type, $newRow);
		});

		$('#opti-ab-add-goal').on('click', function() {
			var currentGoals = $('#opti-ab-goals-list .opti-ab-goal-row').length;
			var maxGoals = isPro ? 20 : ( parseInt( limits.max_goals_per_test, 10 ) || 3 );
			if ( currentGoals >= maxGoals ) {
				showToast( ( strings.limit_goals_reached || 'Free plan limit reached: maximum %d goals per test.' ).replace( '%d', maxGoals ), 'error' );
				return;
			}
			addGoalRow('page_visit', '');
		});

		// Remove variant/goal.
		$('#opti-ab-variants-list').on('click', '.opti-ab-variant-row__remove', function() {
			$(this).closest('.opti-ab-variant-row').remove();
			redistributeWeights();
		});

		// Visual Editor button click handler (element type).
		// Saved variants render as native links and should not be intercepted.
		$('#opti-ab-variants-list').on('click', '.opti-ab-ve-open-btn', function(e) {
			if ( this.tagName && this.tagName.toLowerCase() === 'a' && $(this).attr('href') ) {
				return;
			}

			e.preventDefault();
			var $row         = $(this).closest('.opti-ab-variant-row');
			var variantIndex  = $('#opti-ab-variants-list .opti-ab-variant-row').index($row);
			var currentTestId = parseInt($('#opti-ab-wizard').data('test-id'), 10) || 0;
			var variantId     = parseInt($row.find('.opti-ab-variant-db-id').val(), 10) || 0;
			var veUrl         = ( window.optiBehaviorABAdmin && window.optiBehaviorABAdmin.visual_editor_url ) || '';

			if ( ! veUrl ) { return; }

			if ( currentTestId && variantId ) {
				window.location.href = buildVisualEditorHref( veUrl, currentTestId, variantId );
				return;
			}

			if ( typeof window.showToast === 'function' ) {
				window.showToast( ( strings.ve_saving_draft_retry || 'Saving draft. Click Open Visual Editor again after the direct link appears.' ), 'info' );
			} else {
				alert( ( strings.ve_saving_draft_retry || 'Saving draft. Click Open Visual Editor again after the direct link appears.' ) );
			}

			openVEAfterAutoSave( currentTestId, variantIndex, veUrl );
		});

		// Manual weight change — adjust other variants proportionally to keep total = 100.
		var _weightChanging = false;
		$('#opti-ab-variants-list').on('change', '.opti-ab-variant-weight', function() {
			if ( _weightChanging ) return;
			_weightChanging = true;
			var $changed   = $(this);
			var changedVal = parseInt($changed.val(), 10) || 1;
			var $others    = $('#opti-ab-variants-list .opti-ab-variant-weight').not($changed);
			var minOthers  = $others.length; // minimum 1 per other variant
			// Clamp changedVal so others can each have at least 1.
			if ( changedVal > 100 - minOthers ) {
				changedVal = 100 - minOthers;
				$changed.val(changedVal);
			}
			if ( changedVal < 1 ) {
				changedVal = 1;
				$changed.val(changedVal);
			}
			var othersTotal = 100 - changedVal;
			if ( $others.length > 0 ) {
				var base = Math.floor(othersTotal / $others.length);
				var rem  = othersTotal - ( base * $others.length );
				$others.each(function(i) {
					$(this).val( i === $others.length - 1 ? base + rem : base );
				});
			}
			_weightChanging = false;
		});
		$('#opti-ab-goals-list').on('click', '.opti-ab-goal-row__remove', function() {
			$(this).closest('.opti-ab-goal-row').remove();
		});

		// Goal type change — repopulate the config fields for the affected row.
		$('#opti-ab-goals-list').on('change', '.opti-ab-goal-type', function() {
			var $row = $(this).closest('.opti-ab-goal-row');
			updateGoalConfigFields($row, $(this).val(), {});
		});

		// Form mode toggle — show/hide custom selector input.
		$('#opti-ab-goals-list').on('change', '.opti-ab-goal-form-mode', function() {
			var $row = $(this).closest('.opti-ab-goal-row');
			$row.find('.opti-ab-goal-form-custom-wrap').toggle( $(this).val() === 'custom' );
		});

		// URL validation for page_visit goals.
		$('#opti-ab-goals-list').on('input blur', '.opti-ab-goal-url', function() {
			var $input = $(this);
			var $wrap  = $input.closest('.opti-ab-goal-url-wrap');
			var val    = $input.val().trim();
			$wrap.removeClass('is-valid is-invalid');
			if ( ! val ) { return; }
			try {
				// Will throw if invalid URL.
				new URL(val); // jshint ignore:line
				$wrap.addClass('is-valid');
			} catch (e) {
				$wrap.addClass('is-invalid');
			}
		});

		// Click goal: "+ Add CSS Selector" button (free mode: adds empty text input).
		$('#opti-ab-goals-list').on('click', '.opti-ab-goal-add-selector', function() {
			var $goalRow = $(this).closest('.opti-ab-goal-row');
			var $list    = $goalRow.find('.opti-ab-goal-selectors-list');
			var pholder  = strings.goal_selector_placeholder || 'e.g., .buy-button, #cta-link';
			appendSelectorEntry( $list, '', pholder );

			// Trigger Pro JS hook if available (so Pro can inject the "Select" button).
			$(document).trigger('opti_ab_selector_entry_added', [ $list.find('.opti-ab-goal-selector-entry').last(), $goalRow ]);
		});

		// Click goal: Remove selector entry.
		$('#opti-ab-goals-list').on('click', '.opti-ab-goal-selector-entry__remove', function() {
			var $entry = $(this).closest('.opti-ab-goal-selector-entry');
			var $list  = $entry.closest('.opti-ab-goal-selectors-list');

			// Keep at least one entry.
			if ( $list.find('.opti-ab-goal-selector-entry').length > 1 ) {
				$entry.remove();
			} else {
				// If it's the last one, just clear the input.
				$entry.find('.opti-ab-goal-selector-input').val('');
			}
		});

		// Step clicks on wizard bar.
		$('.opti-ab-wizard-step').on('click', function() {
			var target = parseInt($(this).data('step'), 10);
			if ( target < wizardStep ) {
				autoSaveDraft();
				goToStep(target);
			}
		});

		// Restore last visited step on page refresh (existing tests only).
		if ( wizardTestId > 0 ) {
			var requestedStep = parseInt( urlParams.get('builder_step') || urlParams.get('step'), 10 ) || 0;
			var restoredStep = requestedStep;
			try {
				restoredStep = restoredStep || parseInt( sessionStorage.getItem( 'opti_ab_wizard_step_' + wizardTestId ), 10 ) || 1;
			} catch (e) {}
			if ( restoredStep > 1 && restoredStep <= 6 ) {
				goToStep( restoredStep );
			}
		}
	}

	function goToStep(step) {
		if ( step < 1 || step > 6 ) return;
		wizardStep = step;

		// Persist current step so it can be restored on page refresh.
		if ( wizardTestId > 0 ) {
			try { sessionStorage.setItem( 'opti_ab_wizard_step_' + wizardTestId, step ); } catch (e) {}
		}

		// Panels.
		$('.opti-ab-wizard-panel').removeClass('active');
		$('.opti-ab-wizard-panel[data-step="' + step + '"]').addClass('active');

		// Step indicators.
		$('.opti-ab-wizard-step').removeClass('active').each(function() {
			var s = parseInt($(this).data('step'), 10);
			if ( s < step ) {
				$(this).addClass('completed');
			} else {
				$(this).removeClass('completed');
			}
		});
		$('.opti-ab-wizard-step[data-step="' + step + '"]').addClass('active');

		// Nav buttons.
		$('#opti-ab-wizard-prev').toggle(step > 1);
		$('#opti-ab-wizard-next').toggle(step < 6);
		$('#opti-ab-wizard-launch').toggle(step === 6);

		// Rebuild variant cards when entering step 3 so the target URL (set in step 2) is reflected.
		// Only clear containers that currently show the empty-state placeholder — containers that
		// already have a live iframe are left untouched to avoid an unnecessary reload.
		if ( step === 3 && wizardData.test_type ) {
			$('#opti-ab-variants-list .opti-ab-variant-row').each(function() {
				var $c = $(this).find('.opti-ab-variant-type-fields');
				if ( $c.find('.ob-vcard__left--empty').length > 0 ) {
					$c.empty();
				}
			});
			updateVariantTypeFields(wizardData.test_type);
		}

		// Build review summary on step 6.
		if ( step === 6 ) {
			buildReviewSummary();
		}
	}

	function validateStep(step) {
		switch (step) {
			case 1:
				if ( ! $('input[name="test_type"]:checked').val() ) {
					showToast(strings.validate_select_type || 'Please select a test type.', 'error');
					return false;
				}
				wizardData.test_type = $('input[name="test_type"]:checked').val();
				return true;

			case 2:
				var name = $('#opti-ab-test-name').val().trim();
				if ( ! name ) {
					showToast(strings.validate_enter_name || 'Please enter a test name.', 'error');
					$('#opti-ab-test-name').focus();
					return false;
				}
				wizardData.name = name;

				// WooCommerce type: validate product selection.
				if ( wizardData.test_type === 'woocommerce' ) {
					var wooProductId = $('#opti-ab-woo-selected-product-id').val();
					if ( ! wooProductId ) {
						showToast(strings.validate_select_product || 'Please select a WooCommerce product.', 'error');
						return false;
					}
					wizardData.target_post_id = parseInt( wooProductId, 10 );
					wizardData.target_url = '';
				} else if ( wizardData.test_type === 'element' ) {
					var elementTargetState = getElementTargetState();
					if ( ! elementTargetState.target_post_id && ! elementTargetState.target_url ) {
						showToast(strings.validate_select_target || 'Please select WordPress content or enter a same-site URL.', 'error');
						focusElementTargetPicker();
						return false;
					}
					if ( ! elementTargetState.target_post_id && ! isSameSiteUrl( elementTargetState.target_url ) ) {
						markElementTargetUrlInvalid(true);
						showToast( strings.target_picker_url_invalid || 'Please enter a same-site URL.', 'error' );
						$('#opti-ab-target-url-proxy').focus();
						return false;
					}
					markElementTargetUrlInvalid(false);
					wizardData.target_post_id = elementTargetState.target_post_id;
					wizardData.target_url = elementTargetState.target_url;
				} else {
					wizardData.target_url     = $('#opti-ab-target-url').val().trim();
					wizardData.target_post_id = $('#opti-ab-target-post').val() || 0;
				}
				return true;

			case 3:
				var rows = $('#opti-ab-variants-list .opti-ab-variant-row');
				if ( rows.length < 2 ) {
					showToast(strings.validate_min_variants || 'You need at least 2 variants.', 'error');
					return false;
				}
				var maxVariants = isPro ? 10 : ( parseInt( limits.max_variants_per_test, 10 ) || 3 );
				if ( rows.length > maxVariants ) {
					showToast( ( strings.limit_variants_reached || 'Free plan limit reached: maximum %d variants per test.' ).replace( '%d', maxVariants ), 'error' );
					return false;
				}
				var totalWeight = 0;
				rows.each(function() {
					totalWeight += parseInt( $(this).find('.opti-ab-variant-weight').val(), 10 ) || 0;
				});
				if ( totalWeight !== 100 ) {
					showToast( ( strings.validate_weights_total || 'Variant traffic weights must total 100%. Current total: %s%.' ).replace( '%s', totalWeight ), 'error' );
					return false;
				}
				return true;

			case 4:
				var goalRows = $('#opti-ab-goals-list .opti-ab-goal-row');
				if ( goalRows.length < 1 ) {
					showToast(strings.validate_min_goals || 'You need at least 1 goal.', 'error');
					return false;
				}
				var maxGoals = isPro ? 20 : ( parseInt( limits.max_goals_per_test, 10 ) || 3 );
				if ( goalRows.length > maxGoals ) {
					showToast( ( strings.limit_goals_reached || 'Free plan limit reached: maximum %d goals per test.' ).replace( '%d', maxGoals ), 'error' );
					return false;
				}
				return true;

			case 5:
				wizardData.confidence_level   = $('#opti-ab-confidence').val();
				wizardData.traffic_percentage  = parseInt($('#opti-ab-traffic').val(), 10) || 100;
				wizardData.min_sample_size     = parseInt($('#opti-ab-min-sample').val(), 10) || 100;
				wizardData.min_duration_days   = parseInt($('#opti-ab-min-duration').val(), 10) || 7;
				return true;
		}
		return true;
	}

	function updateTargetFieldsForType(type) {
		if ( type === 'page_split' ) {
			$('#opti-ab-target-url-group').show();
			$('#opti-ab-target-post-group').hide();
			$('#opti-ab-woo-product-picker-group').hide();
			$('#opti-ab-step2-title').text( strings.step_target || 'Select Target' );
			$('#opti-ab-step2-desc').text( strings.step2_desc_page || 'Choose which page or post this test applies to.' );
		} else if ( type === 'woocommerce' ) {
			$('#opti-ab-target-url-group').hide();
			$('#opti-ab-target-post-group').hide();
			$('#opti-ab-woo-product-picker-group').show();
			$('#opti-ab-step2-title').text( strings.step2_title_product || 'Select Product' );
			$('#opti-ab-step2-desc').text( strings.step2_desc_product || 'Choose the WooCommerce product you want to A/B test.' );
		} else if ( type === 'element' ) {
			$('#opti-ab-target-url-group').hide();
			$('#opti-ab-target-post-group').show();
			$('#opti-ab-woo-product-picker-group').hide();
			$('#opti-ab-step2-title').text( strings.step_target || 'Select Target' );
			$('#opti-ab-step2-desc').text( strings.step2_desc_element || 'Choose WordPress content or enter a same-site URL for this Element test.' );
			syncElementTargetPickerState();
		} else {
			$('#opti-ab-target-url-group').show();
			$('#opti-ab-target-post-group').hide();
			$('#opti-ab-woo-product-picker-group').hide();
			$('#opti-ab-step2-title').text( strings.step_target || 'Select Target' );
			$('#opti-ab-step2-desc').text( strings.step2_desc_page || 'Choose which page or post this test applies to.' );
		}
	}

	/**
	 * Read the Smart Insights story prefill emitted by the builder view.
	 *
	 * The payload is produced server-side by the Pro hypothesis builder; Free
	 * installs never receive one, so this returns null and the wizard behaves
	 * exactly as before.
	 *
	 * @param {jQuery} $wizard Wizard container.
	 * @return {Object|null} Prefill payload.
	 */
	function readInsightPrefill($wizard) {
		var raw = $wizard.attr('data-insight-prefill');
		if ( ! raw ) {
			return null;
		}

		var parsed = null;
		try {
			parsed = JSON.parse(raw);
		} catch (e) {
			return null;
		}

		return ( parsed && typeof parsed === 'object' && ! Array.isArray(parsed) ) ? parsed : null;
	}

	/**
	 * Apply the non-target parts of a Smart Insights story prefill.
	 *
	 * Target URL/post are handled by applySmartInsightsTargetPrefill(); this
	 * fills the test name, the statistical settings suggested by the sample-size
	 * estimate, and surfaces the hypothesis so the user sees what they are about
	 * to test. Pro listens to the emitted event to apply segment targeting.
	 *
	 * @param {Object|null} prefill Prefill payload.
	 */
	function applyInsightStoryPrefill(prefill) {
		if ( ! prefill ) {
			return;
		}

		if ( prefill.name && ! $('#opti-ab-test-name').val() ) {
			$('#opti-ab-test-name').val(String(prefill.name));
			wizardData.name = String(prefill.name);
		}

		var minSample = parseInt(prefill.min_sample_size, 10) || 0;
		if ( minSample > 0 ) {
			$('#opti-ab-min-sample').val(minSample);
			wizardData.min_sample_size = minSample;
		}

		var minDuration = parseInt(prefill.min_duration_days, 10) || 0;
		if ( minDuration > 0 ) {
			$('#opti-ab-min-duration').val(minDuration);
			wizardData.min_duration_days = minDuration;
		}

		renderInsightPrefillNotice(prefill);

		// Pro applies suggested segment targeting from here.
		$(document).trigger('opti-ab-insight-prefill', [prefill]);
	}

	/**
	 * Render the "started from insight" banner above the wizard.
	 *
	 * @param {Object} prefill Prefill payload.
	 */
	function renderInsightPrefillNotice(prefill) {
		var hypothesis = ( prefill.hypothesis && typeof prefill.hypothesis === 'object' ) ? prefill.hypothesis : {};
		var statement  = String(hypothesis.statement || '').trim();
		if ( ! statement ) {
			return;
		}

		var lines = [];
		if ( hypothesis.expected_range_label ) {
			lines.push(String(hypothesis.expected_range_label));
		}
		if ( hypothesis.sample_size_label ) {
			lines.push(String(hypothesis.sample_size_label));
		}

		// The class name is deliberately plain. notice-cleanup.js removes, and
		// admin-notices.css hides, any div whose class contains "notice",
		// "message", "alert", or "-banner" unless it is prefixed ob-/opti-behavior,
		// so a friendlier-sounding class would make this block vanish on render.
		var html = '<div class="opti-ab-insight-prefill">';
		html += '<p class="opti-ab-insight-prefill__title"><strong>' + escHtml( strings.insight_prefill_title || 'Started from a Smart Insight' ) + '</strong></p>';
		html += '<p class="opti-ab-insight-prefill__statement">' + escHtml(statement) + '</p>';
		if ( lines.length ) {
			html += '<p class="opti-ab-insight-prefill__meta description">' + escHtml(lines.join(' — ')) + '</p>';
		}
		html += '</div>';

		$('#opti-ab-wizard').before(html);
	}

	function applySmartInsightsTargetPrefill(targetUrl, targetPostId) {
		if (targetUrl) {
			$('#opti-ab-target-url').val(targetUrl);
			$('#opti-ab-target-url-proxy').val(targetUrl);
			wizardData.target_url = targetUrl;
		}
		if (targetPostId) {
			ensureTargetPostOption({
				id: targetPostId,
				title: ( strings.target_post_fallback || 'Post #' ) + targetPostId,
				post_type: '',
				type_label: ( strings.target_picker_content_mode || 'WordPress Content' ),
				url: targetUrl || '',
				status: ''
			});
			$('#opti-ab-target-post').val(String(targetPostId));
			wizardData.target_post_id = targetPostId;
		}
		if ( targetUrl || targetPostId ) {
			if ( targetPostId ) {
				selectElementTarget({
					id: targetPostId,
					title: $('#opti-ab-target-post option:selected').data('title') || ( ( strings.target_post_fallback || 'Post #' ) + targetPostId ),
					post_type: $('#opti-ab-target-post option:selected').data('post-type') || '',
					type_label: $('#opti-ab-target-post option:selected').data('type-label') || ( strings.target_picker_content_mode || 'WordPress Content' ),
					url: targetUrl || $('#opti-ab-target-post option:selected').data('url') || '',
					status: $('#opti-ab-target-post option:selected').data('status') || ''
				});
			} else {
				switchElementTargetMode('url');
				syncElementUrlMode(targetUrl);
			}
		}
	}

	var elementTargetPicker = {
		initialized: false,
		mode: 'content',
		page: 1,
		perPage: 20,
		total: 0,
		search: '',
		loading: false,
		hasLoadedInitial: false,
		xhr: null
	};

	function initElementTargetPicker() {
		var $picker = $('#opti-ab-element-target-picker');
		if ( ! $picker.length || elementTargetPicker.initialized ) {
			return;
		}

		var pickerCfg = cfg.target_picker || {};
		elementTargetPicker.initialized = true;
		elementTargetPicker.mode = $picker.data('initial-mode') || 'content';
		elementTargetPicker.perPage = parseInt( pickerCfg.per_page, 10 ) || 20;

		var initialTarget = parseJsonAttr( $picker.attr('data-selected-target') );
		if ( initialTarget && initialTarget.id ) {
			selectElementTarget( initialTarget, { silent: true, keepMode: true } );
		} else if ( $('#opti-ab-target-url-proxy').val() || $('#opti-ab-target-url').val() ) {
			elementTargetPicker.mode = 'url';
			syncElementUrlMode( $('#opti-ab-target-url-proxy').val() || $('#opti-ab-target-url').val(), { silent: true } );
		}

		switchElementTargetMode( elementTargetPicker.mode, { silent: true } );

		$('#opti-ab-target-search').on('input', debounce(function() {
			elementTargetPicker.search = $(this).val().trim();
			searchElementTargets(1, false);
		}, 300));

		$('#opti-ab-target-search').on('focus', function() {
			if ( elementTargetPicker.mode === 'content' && ! elementTargetPicker.hasLoadedInitial && ! $('#opti-ab-target-post').val() ) {
				searchElementTargets(1, false);
			}
		});

		$('#opti-ab-target-load-more').on('click', function() {
			if ( elementTargetPicker.loading ) {
				return;
			}
			searchElementTargets(elementTargetPicker.page + 1, true);
		});

		$('#opti-ab-target-results').on('click', '.opti-ab-target-result', function() {
			selectElementTarget({
				id: parseInt( $(this).data('target-id'), 10 ) || 0,
				title: $(this).data('target-title') || '',
				post_type: $(this).data('target-post-type') || '',
				type_label: $(this).data('target-type-label') || '',
				url: $(this).data('target-url') || '',
				status: $(this).data('target-status') || ''
			});
		});

		$('.opti-ab-target-picker__mode').on('click', function() {
			switchElementTargetMode( $(this).data('target-mode') || 'content' );
		});

		$('#opti-ab-target-change').on('click', function() {
			showElementTargetSearch(true);
			$('#opti-ab-target-search').focus();
			if ( ! elementTargetPicker.hasLoadedInitial ) {
				searchElementTargets(1, false);
			}
		});

		$('#opti-ab-target-clear').on('click', function() {
			clearElementTarget();
			$('#opti-ab-target-search').focus();
		});

		$('#opti-ab-target-url-proxy').on('input blur', function() {
			syncElementUrlMode( $(this).val() );
		});

		$('.opti-ab-target-quick').on('click', function() {
			var targetUrl = $(this).data('target-url') || '';
			switchElementTargetMode('url');
			$('#opti-ab-target-source').val( $(this).data('target-source') || 'url' );
			$('#opti-ab-target-label').val( $(this).data('target-label') || targetUrl );
			$('#opti-ab-target-url-proxy').val(targetUrl);
			syncElementUrlMode(targetUrl);
		});

		$('#opti-ab-target-post').on('change', function() {
			syncElementTargetPickerState();
		});
	}

	function searchElementTargets(page, append) {
		var $results = $('#opti-ab-target-results');
		var $status = $('#opti-ab-target-search-status');
		var $loadMore = $('#opti-ab-target-load-more');
		var pickerCfg = cfg.target_picker || {};

		if ( ! ajaxUrl || ! nonce ) {
			return;
		}

		if ( elementTargetPicker.xhr && elementTargetPicker.xhr.readyState !== 4 ) {
			elementTargetPicker.xhr.abort();
		}

		elementTargetPicker.loading = true;
		elementTargetPicker.page = page;
		elementTargetPicker.search = $('#opti-ab-target-search').val().trim();
		$loadMore.hide().prop('disabled', true);
		$status.text( strings.target_picker_searching || 'Searching targets...' );

		if ( ! append ) {
			$results.empty();
		}

		elementTargetPicker.xhr = $.post(ajaxUrl, {
			action: pickerCfg.search_action || 'opti_behavior_ab_search_targets',
			nonce: nonce,
			search: elementTargetPicker.search,
			page: page,
			per_page: elementTargetPicker.perPage
		}, function(res) {
			var data = res && res.data ? res.data : {};
			var targets = data.targets || [];
			elementTargetPicker.total = parseInt(data.total, 10) || 0;
			elementTargetPicker.page = parseInt(data.page, 10) || page;
			elementTargetPicker.hasLoadedInitial = true;

			if ( ! res.success ) {
				$status.text( data.message || strings.error || 'An error occurred.' );
				return;
			}

			renderElementTargetResults(targets, append);

			if ( targets.length === 0 && ! append ) {
				$status.text( strings.target_picker_no_results || 'No matching targets found.' );
			} else {
				var shown = $('#opti-ab-target-results .opti-ab-target-result').length;
				$status.text( shown + ' of ' + elementTargetPicker.total + ' targets shown.' );
				if ( shown < elementTargetPicker.total ) {
					$loadMore.text( strings.target_picker_load_more || 'Load more results' ).show().prop('disabled', false);
				}
			}
		}).fail(function(xhr, status) {
			if ( status !== 'abort' ) {
				$status.text( strings.error || 'An error occurred.' );
			}
		}).always(function() {
			elementTargetPicker.loading = false;
			$loadMore.prop('disabled', false);
		});
	}

	function renderElementTargetResults(targets, append) {
		var html = '';
		targets.forEach(function(target) {
			var title = target.title || ( ( strings.target_post_fallback || 'Post #' ) + target.id );
			var typeLabel = target.type_label || target.post_type || ( strings.target_type_content || 'Content' );
			var shortUrl = formatShortUrl( target.url || '' );
			html += '<button type="button" class="opti-ab-target-result" role="option"';
			html += ' data-target-id="' + escAttr(target.id || 0) + '"';
			html += ' data-target-title="' + escAttr(title) + '"';
			html += ' data-target-post-type="' + escAttr(target.post_type || '') + '"';
			html += ' data-target-type-label="' + escAttr(typeLabel) + '"';
			html += ' data-target-url="' + escAttr(target.url || '') + '"';
			html += ' data-target-status="' + escAttr(target.status || '') + '">';
			html += '<span class="opti-ab-target-result__title">' + escHtml(title) + '</span>';
			html += '<span class="opti-ab-target-result__meta">' + escHtml(typeLabel) + ( target.status ? ' · ' + escHtml(target.status) : '' ) + '</span>';
			if ( shortUrl ) {
				html += '<span class="opti-ab-target-result__url">' + escHtml(shortUrl) + '</span>';
			}
			html += '</button>';
		});

		if ( append ) {
			$('#opti-ab-target-results').append(html);
		} else {
			$('#opti-ab-target-results').html(html);
		}
	}

	function selectElementTarget(target, options) {
		options = options || {};
		if ( ! target || ! target.id ) {
			return;
		}

		var normalized = {
			id: parseInt(target.id, 10) || 0,
			title: target.title || ( ( strings.target_post_fallback || 'Post #' ) + target.id ),
			post_type: target.post_type || '',
			type_label: target.type_label || target.post_type || ( strings.target_type_content || 'Content' ),
			url: target.url || '',
			status: target.status || ''
		};

		ensureTargetPostOption(normalized);
		$('#opti-ab-target-post').val(String(normalized.id));
		$('#opti-ab-target-url').val(normalized.url);
		$('#opti-ab-target-url-proxy').val(normalized.url);
		$('#opti-ab-target-source').val('content');
		$('#opti-ab-target-label').val(normalized.title);

		$('#opti-ab-target-selected-title').text(normalized.title);
		$('#opti-ab-target-selected-type').text(normalized.type_label);
		$('#opti-ab-target-selected-status').text(normalized.status);
		$('#opti-ab-target-selected-url')
			.attr('href', normalized.url || '#')
			.text(formatShortUrl(normalized.url));
		$('#opti-ab-target-selected').show();
		showElementTargetSearch(false);

		wizardData.target_post_id = normalized.id;
		wizardData.target_url = normalized.url;
		markElementTargetUrlInvalid(false);

		if ( ! options.keepMode ) {
			switchElementTargetMode('content', { silent: true });
		}
		if ( ! options.silent ) {
			refreshElementVariantPreviews();
		}
	}

	function clearElementTarget() {
		$('#opti-ab-target-post').val('');
		$('#opti-ab-target-source').val('content');
		$('#opti-ab-target-label').val('');
		$('#opti-ab-target-selected').hide();
		$('#opti-ab-target-selected-title, #opti-ab-target-selected-type, #opti-ab-target-selected-status, #opti-ab-target-selected-url').text('');
		$('#opti-ab-target-selected-url').attr('href', '#');
		wizardData.target_post_id = 0;
		if ( elementTargetPicker.mode === 'content' ) {
			$('#opti-ab-target-url').val('');
			wizardData.target_url = '';
		}
		showElementTargetSearch(true);
		refreshElementVariantPreviews();
	}

	function switchElementTargetMode(mode, options) {
		options = options || {};
		mode = mode === 'url' ? 'url' : 'content';
		elementTargetPicker.mode = mode;
		$('#opti-ab-target-source').val(mode);
		$('.opti-ab-target-picker__mode').removeClass('is-active').attr('aria-selected', 'false');
		$('.opti-ab-target-picker__mode[data-target-mode="' + mode + '"]').addClass('is-active').attr('aria-selected', 'true');
		$('#opti-ab-target-content-panel').toggle(mode === 'content');
		$('#opti-ab-target-url-panel').toggle(mode === 'url');

		if ( mode === 'url' ) {
			var url = $('#opti-ab-target-url-proxy').val() || $('#opti-ab-target-url').val();
			syncElementUrlMode(url, { silent: true });
		} else {
			syncElementTargetPickerState();
			if ( ! $('#opti-ab-target-post').val() ) {
				showElementTargetSearch(true);
			}
		}

		if ( ! options.silent ) {
			refreshElementVariantPreviews();
		}
	}

	function syncElementUrlMode(url, options) {
		options = options || {};
		url = (url || '').trim();
		$('#opti-ab-target-url').val(url);
		$('#opti-ab-target-url-proxy').val(url);
		$('#opti-ab-target-post').val('');
		$('#opti-ab-target-label').val(url);
		$('#opti-ab-target-selected').hide();
		$('#opti-ab-target-url-summary').toggle(!!url);
		$('#opti-ab-target-url-summary-link')
			.attr('href', url || '#')
			.text(formatShortUrl(url));

		wizardData.target_post_id = 0;
		wizardData.target_url = url;
		markElementTargetUrlInvalid(!!url && ! isSameSiteUrl(url));

		if ( ! options.silent ) {
			refreshElementVariantPreviews();
		}
	}

	function syncElementTargetPickerState() {
		if ( elementTargetPicker.mode === 'url' ) {
			syncElementUrlMode( $('#opti-ab-target-url-proxy').val() || $('#opti-ab-target-url').val(), { silent: true } );
			return;
		}

		var $selected = $('#opti-ab-target-post option:selected');
		var selectedId = parseInt( $('#opti-ab-target-post').val(), 10 ) || 0;
		if ( selectedId ) {
			selectElementTarget({
				id: selectedId,
				title: $selected.data('title') || $selected.text() || ( ( strings.target_post_fallback || 'Post #' ) + selectedId ),
				post_type: $selected.data('post-type') || '',
				type_label: $selected.data('type-label') || ( strings.target_type_content || 'Content' ),
				url: $selected.data('url') || $('#opti-ab-target-url').val() || '',
				status: $selected.data('status') || ''
			}, { silent: true, keepMode: true });
		} else {
			wizardData.target_post_id = 0;
			$('#opti-ab-target-selected').hide();
			if ( wizardData.test_type === 'element' ) {
				showElementTargetSearch(true);
			}
		}
	}

	function getElementTargetState() {
		if ( elementTargetPicker.mode === 'url' || $('#opti-ab-target-source').val() === 'url' ) {
			syncElementUrlMode( $('#opti-ab-target-url-proxy').val() || $('#opti-ab-target-url').val(), { silent: true } );
			return {
				target_post_id: 0,
				target_url: ($('#opti-ab-target-url').val() || '').trim()
			};
		}

		var postId = parseInt( $('#opti-ab-target-post').val(), 10 ) || 0;
		var targetUrl = getElementTargetUrl();
		return {
			target_post_id: postId,
			target_url: postId ? targetUrl : ''
		};
	}

	function getElementTargetUrl() {
		var canonicalUrl = ($('#opti-ab-target-url').val() || '').trim();
		var selectedUrl = $('#opti-ab-target-post option:selected').data('url') || '';
		var postId = parseInt( wizardData.target_post_id || $('#opti-ab-target-post').val(), 10 ) || 0;
		var homeUrl = ( window.optiBehaviorABAdmin && window.optiBehaviorABAdmin.home_url ) || '';

		if ( canonicalUrl && ( elementTargetPicker.mode === 'url' || postId ) ) {
			return canonicalUrl;
		}
		if ( selectedUrl ) {
			return selectedUrl;
		}
		if ( postId && homeUrl ) {
			return homeUrl.replace(/\/+$/, '') + '/?p=' + postId;
		}
		return '';
	}

	function ensureTargetPostOption(target) {
		var $select = $('#opti-ab-target-post');
		var id = parseInt(target.id, 10) || 0;
		if ( ! id || ! $select.length ) {
			return;
		}

		var $option = $select.find('option[value="' + id + '"]');
		if ( ! $option.length ) {
			$option = $('<option></option>').attr('value', id).appendTo($select);
		}
		$option
			.text(( target.title || ( ( strings.target_post_fallback || 'Post #' ) + id ) ) + ( target.type_label ? ' (' + target.type_label + ')' : '' ))
			.attr('data-url', target.url || '')
			.attr('data-title', target.title || '')
			.attr('data-post-type', target.post_type || '')
			.attr('data-type-label', target.type_label || '')
			.attr('data-status', target.status || '')
			.data('url', target.url || '')
			.data('title', target.title || '')
			.data('post-type', target.post_type || '')
			.data('type-label', target.type_label || '')
			.data('status', target.status || '');
	}

	function showElementTargetSearch(show) {
		$('#opti-ab-target-search-wrap, #opti-ab-target-results, #opti-ab-target-search-status').toggle(!!show);
		if ( ! show ) {
			$('#opti-ab-target-load-more').hide();
		}
	}

	function focusElementTargetPicker() {
		if ( elementTargetPicker.mode === 'url' ) {
			$('#opti-ab-target-url-proxy').focus();
		} else if ( $('#opti-ab-target-post').val() ) {
			$('#opti-ab-target-change').focus();
		} else {
			$('#opti-ab-target-search').focus();
		}
	}

	function markElementTargetUrlInvalid(invalid) {
		$('#opti-ab-target-url-proxy').toggleClass('is-invalid', !!invalid);
		$('#opti-ab-element-target-picker').toggleClass('has-error', !!invalid);
	}

	function isSameSiteUrl(url) {
		if ( ! url ) {
			return false;
		}
		try {
			var siteUrl = new URL( cfg.home_url || window.location.origin );
			var targetUrl = new URL( url, siteUrl.href );
			return targetUrl.origin === siteUrl.origin;
		} catch (e) {
			return false;
		}
	}

	function refreshElementVariantPreviews() {
		if ( wizardData.test_type !== 'element' ) {
			return;
		}
		$('#opti-ab-variants-list .opti-ab-variant-row .opti-ab-variant-type-fields').empty();
		updateVariantTypeFields('element');
	}

	function parseJsonAttr(value) {
		if ( ! value || value === 'null' ) {
			return null;
		}
		try {
			return JSON.parse(value);
		} catch (e) {
			return null;
		}
	}

	function formatShortUrl(url) {
		return (url || '').replace(/^https?:\/\//, '').replace(/\/+$/, '');
	}

	/**
	 * Populate (or clear) the type-specific input container inside every variant row.
	 *
	 * Called whenever the test type changes, after rows are first rendered, and after
	 * a new row is added. Pro JS may overwrite the container for 'woocommerce' type.
	 *
	 * @param {string} type Current test type value.
	 */
	function updateVariantTypeFields(type, $onlyRow) {
		var $rows = $onlyRow ? $onlyRow : $('#opti-ab-variants-list .opti-ab-variant-row');
		$rows.each(function(variantIndex) {
			// When called for all rows, use the DOM index instead of each() index.
			if ( ! $onlyRow ) {
				variantIndex = $('#opti-ab-variants-list .opti-ab-variant-row').index(this);
			}
			var $row       = $(this);
			var $container = $row.find('.opti-ab-variant-type-fields');
			var isControl  = parseInt($row.find('.opti-ab-variant-control').val(), 10);
			var currentData = {};

			// Skip rows that already have content — avoids reloading iframes.
			if ( ! $onlyRow && $container.children().length > 0 ) {
				return;
			}

			try {
				currentData = JSON.parse($row.find('.opti-ab-variant-data').val() || '{}');
			} catch(e) {
				currentData = {};
			}

			$container.empty();

			if ( type === 'page_split' && ! isControl ) {
				// Redirect URL input for non-control page-split variants.
				var redirectUrl = currentData.redirect_url || '';
				$container.append(
					'<label class="opti-ab-field-label">' + escHtml( strings.variant_redirect_url_label || 'Redirect URL' ) + '</label>' +
					'<input type="url" class="opti-ab-input opti-ab-input--full opti-ab-variant-redirect-url"' +
					' value="' + escAttr(redirectUrl) + '" placeholder="' + escAttr( strings.variant_redirect_url_placeholder || 'https://example.com/variant-page' ) + '">'
				);
			} else if ( type === 'element' && ! isControl ) {
				// Element type: Open Visual Editor button for non-control variants.
				// The Control variant is the unmodified original — it needs no editor.
				var veUrl = ( window.optiBehaviorABAdmin && window.optiBehaviorABAdmin.visual_editor_url ) || '';
				if ( veUrl ) {
					// Build a human-readable changes summary from variant_data.
					var changes = ( currentData && currentData.changes ) || [];
					var summaryHtml = '';

					if ( changes.length > 0 ) {
						summaryHtml += '<div class="opti-ab-ve-changes-summary">';
						summaryHtml += '<div class="opti-ab-ve-changes-summary__header">';
						summaryHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.855z"/></svg>';
						summaryHtml += '<span>' + changes.length + ' ' + ( changes.length !== 1 ? ( strings.change_many || 'changes' ) : ( strings.change_one || 'change' ) ) + '</span>';
						summaryHtml += '</div>';
						summaryHtml += '<div class="opti-ab-ve-changes-summary__list">';

						for ( var ci = 0; ci < changes.length; ci++ ) {
							var ch = changes[ci];
							var icon = '✏️';
							var label = '';

							if ( ch.insert_after ) {
								icon = '➕';
								label = ( ch.block_name || ( strings.vc_block || 'Block' ) ) + ' ' + ( strings.vc_after || 'after' ) + ' ' + ( ch.selector || ( strings.vc_page || 'page' ) );
							} else if ( ch.duplicate ) {
								icon = '📋';
								label = ( strings.vc_duplicate || 'Duplicate' ) + ' ' + ch.selector;
							} else if ( ch.hide ) {
								icon = '🙈';
								label = ( strings.vc_hide || 'Hide' ) + ' ' + ch.selector;
							} else if ( typeof ch.move_delta !== 'undefined' ) {
								icon = '↕️';
								label = ( strings.vc_move || 'Move' ) + ' ' + ch.selector;
							} else if ( ch.text || ch.html ) {
								icon = '✏️';
								label = ( strings.vc_edit || 'Edit' ) + ' ' + ch.selector;
							} else if ( ch.css || ch.design ) {
								icon = '🎨';
								label = ( strings.vc_style || 'Style' ) + ' ' + ch.selector;
							} else {
								label = ch.selector || ( strings.vc_unknown || 'Unknown' );
							}

							// Truncate long selectors.
							if ( label.length > 50 ) {
								label = label.substring(0, 47) + '…';
							}

							summaryHtml += '<div class="opti-ab-ve-changes-summary__item">';
							summaryHtml += '<span class="opti-ab-ve-changes-summary__icon">' + icon + '</span>';
							summaryHtml += '<span class="opti-ab-ve-changes-summary__label">' + escAttr(label) + '</span>';
							summaryHtml += '</div>';
						}

						summaryHtml += '</div></div>';
					}

					// Build the preview thumbnail context.
					var targetUrl = getElementTargetUrl();
					var currentTestId = parseInt($('#opti-ab-wizard').data('test-id'), 10) || 0;
					var storedVariantId = parseInt($row.find('.opti-ab-variant-db-id').val(), 10) || 0;
					// Show iframe whenever a target URL exists; use variant-preview endpoint only when changes are stored.
					var showIframe = !!targetUrl;
					var hasChangesPreview = !!(changes.length > 0 && targetUrl && currentTestId && storedVariantId);

					// Side-by-side: LEFT = preview, RIGHT = controls + changes
					var cardHtml = '<div class="ob-vcard">';

					// LEFT: preview
					cardHtml += '<div class="ob-vcard__left' + ( showIframe ? '' : ' ob-vcard__left--empty' ) + '">';
					if ( showIframe ) {
						cardHtml += '<div class="ob-vcard__left-loading"><div class="ob-vcard__spinner"></div></div>';
					} else {
						cardHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>';
						cardHtml += '<span class="ob-vcard__empty-title">' + escHtml( strings.ve_no_changes_yet || 'No changes yet' ) + '</span>';
						cardHtml += '<span class="ob-vcard__empty-hint">' + escHtml( strings.ve_add_changes_hint || 'Add changes via Visual Editor' ) + '</span>';
					}
					cardHtml += '</div>';

					// RIGHT: controls + changes
					cardHtml += '<div class="ob-vcard__right">';

					// Toolbar
					cardHtml += '<div class="ob-vcard__toolbar">';
					if ( currentTestId && storedVariantId ) {
						cardHtml += '<a href="' + escAttr( buildVisualEditorHref( veUrl, currentTestId, storedVariantId ) ) + '" class="button opti-ab-ve-open-btn" target="_blank" rel="noopener noreferrer">';
						cardHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
						cardHtml += '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>';
						cardHtml += ' ' + escHtml( strings.ve_open_editor || 'Open Visual Editor' ) + '</a>';
					} else {
						cardHtml += '<button type="button" class="button opti-ab-ve-open-btn">';
						cardHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
						cardHtml += '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>';
						cardHtml += ' ' + escHtml( strings.ve_open_editor || 'Open Visual Editor' ) + '</button>';
					}
					if ( changes.length > 0 ) {
						var styleCount = 0, insertCount = 0, hideCount = 0, otherCount = 0;
						for ( var si = 0; si < changes.length; si++ ) {
							var sc = changes[si];
							if ( sc.insert_after ) insertCount++;
							else if ( sc.hide ) hideCount++;
							else if ( sc.css || sc.design ) styleCount++;
							else otherCount++;
						}
						cardHtml += '<div class="ob-vcard__pills">';
						if ( styleCount ) cardHtml += '<span class="ob-vcard__pill ob-vcard__pill--style">◆ ' + styleCount + ' ' + ( strings.vc_styled || 'styled' ) + '</span>';
						if ( insertCount ) cardHtml += '<span class="ob-vcard__pill ob-vcard__pill--insert">+ ' + insertCount + ' ' + ( strings.vc_added || 'added' ) + '</span>';
						if ( hideCount ) cardHtml += '<span class="ob-vcard__pill ob-vcard__pill--hide">− ' + hideCount + ' ' + ( strings.vc_hidden || 'hidden' ) + '</span>';
						if ( otherCount ) cardHtml += '<span class="ob-vcard__pill ob-vcard__pill--other">✎ ' + otherCount + ' ' + ( strings.vc_edited || 'edited' ) + '</span>';
						cardHtml += '</div>';
					}
					cardHtml += '</div>';

					// Changes list (always visible, scrollable)
					if ( changes.length > 0 ) {
						cardHtml += '<div class="ob-vcard__changelist">';
						for ( var ci = 0; ci < changes.length; ci++ ) {
							var ch = changes[ci], icon, iconClass, label;
							if ( ch.insert_after ) {
								icon = '+'; iconClass = 'insert';
								label = ( ch.block_name || ( strings.vc_block || 'Block' ) ) + ' <span class="ob-vcard__sel">' + ( strings.vc_after || 'after' ) + ' ' + escAttr(ch.selector || ( strings.vc_page || 'page' )) + '</span>';
							} else if ( ch.duplicate ) {
								icon = '⧉'; iconClass = 'other';
								label = ( strings.vc_duplicate || 'Duplicate' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
							} else if ( ch.hide ) {
								icon = '−'; iconClass = 'hide';
								label = ( strings.vc_hide || 'Hide' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
							} else if ( typeof ch.move_delta !== 'undefined' ) {
								icon = '↕'; iconClass = 'other';
								label = ( strings.vc_move || 'Move' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
							} else if ( ch.css || ch.design ) {
								icon = '◆'; iconClass = 'style';
								label = ( strings.vc_style || 'Style' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
							} else if ( ch.text || ch.html ) {
								icon = '✎'; iconClass = 'other';
								label = ( strings.vc_edit || 'Edit' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
							} else {
								icon = '•'; iconClass = 'other';
								label = '<span class="ob-vcard__sel">' + escAttr(ch.selector || ( strings.vc_unknown || 'Unknown' )) + '</span>';
							}
							cardHtml += '<div class="ob-vcard__ch ob-vcard__ch--' + iconClass + '"><span class="ob-vcard__ch-icon">' + icon + '</span><span class="ob-vcard__ch-text">' + label + '</span></div>';
						}
						cardHtml += '</div>';
					}

					cardHtml += '</div>'; // right
					cardHtml += '</div>'; // ob-vcard
					$container.append(cardHtml);

					// Load preview iframe: use variant-preview endpoint when changes exist, otherwise plain target URL.
					if ( showIframe ) {
						var pUrl;
						if ( hasChangesPreview ) {
							pUrl = targetUrl + ( targetUrl.indexOf('?') === -1 ? '?' : '&' ) +
								'opti_ab_preview_test=' + currentTestId +
								'&opti_ab_preview_variant=' + storedVariantId +
								'&opti_ab_admin_preview=1' +
								'&opti_ab_cache_bust=' + Date.now();
						} else {
							// opti_ab_admin_preview=1: this iframe renders the live
							// target page inside wp-admin. Without the marker it is
							// an ordinary tracked visit — the admin gets bucketed,
							// an impression is recorded, and the iframe left open on
							// screen fires the time-based goals.
							pUrl = targetUrl + ( targetUrl.indexOf('?') === -1 ? '?' : '&' ) +
								'opti_ab_admin_preview=1' +
								'&opti_ab_cache_bust=' + Date.now();
						}
						$container.find('.ob-vcard__left').html(
							'<div class="ob-vcard__left-loader"><div class="ob-vcard__spinner"></div></div>' +
							'<iframe class="ob-vcard__left-iframe" src="' + escAttr(pUrl) + '" ' +
							'sandbox="allow-same-origin allow-scripts" ' +
							'loading="lazy" tabindex="-1" title="' + escAttr( strings.preview_variant || 'Variant preview' ) + '" ' +
							'onload="if(this.previousElementSibling)this.previousElementSibling.style.display=\'none\'"></iframe>'
						);
						applyDynamicPreviewSize( $container.find('.ob-vcard__left-iframe').get(0), false );
					}
				}
			} else if ( type === 'element' && isControl ) {
				// Control variant — show preview of the original page + info label.
				var ctrlTargetUrl = getElementTargetUrl();
				var ctrlHasUrl = !!ctrlTargetUrl;

				var ctrlHtml = '<div class="ob-vcard ob-vcard--control">';
				// LEFT: preview
				ctrlHtml += '<div class="ob-vcard__left' + ( ctrlHasUrl ? '' : ' ob-vcard__left--empty' ) + '">';
				if ( ctrlHasUrl ) {
					// opti_ab_admin_preview=1 — see the note on the variant preview
					// URL above: an unmarked iframe is a fully tracked visit.
					var ctrlPreviewUrl = ctrlTargetUrl + ( ctrlTargetUrl.indexOf('?') === -1 ? '?' : '&' ) +
						'opti_ab_admin_preview=1&opti_ab_cache_bust=' + Date.now();
					ctrlHtml += '<div class="ob-vcard__left-loader"><div class="ob-vcard__spinner"></div></div>';
					ctrlHtml += '<iframe class="ob-vcard__left-iframe" src="' + escAttr(ctrlPreviewUrl) + '" ' +
						'sandbox="allow-same-origin allow-scripts" loading="lazy" tabindex="-1" title="' + escAttr( strings.preview_original_page || 'Original page' ) + '" ' +
						'onload="if(this.previousElementSibling)this.previousElementSibling.style.display=\'none\'"></iframe>';
				} else {
					ctrlHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
					ctrlHtml += '<span class="ob-vcard__empty-title">' + escHtml( strings.ve_no_target_page || 'No target page' ) + '</span>';
					ctrlHtml += '<span class="ob-vcard__empty-hint">' + escHtml( strings.ve_configure_target_hint || 'Configure a target in Step 2' ) + '</span>';
				}
				ctrlHtml += '</div>';
				// RIGHT: info
				ctrlHtml += '<div class="ob-vcard__right">';
				ctrlHtml += '<div class="ob-vcard__toolbar">';
				ctrlHtml += '<div class="opti-ab-ve-control-info">';
				ctrlHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>';
				ctrlHtml += '<span>' + escHtml( strings.ve_original_no_mods || 'Original page — no modifications' ) + '</span>';
				ctrlHtml += '</div></div>';
				ctrlHtml += '<div class="ob-vcard__control-desc">';
				ctrlHtml += '<p>' + escHtml( strings.ve_control_desc || 'Visitors in this group see the unmodified page. This is the baseline your variants are compared against.' ) + '</p>';
				ctrlHtml += '</div>';
				ctrlHtml += '</div></div>';
				$container.append(ctrlHtml);
				applyDynamicPreviewSize( $container.find('.ob-vcard__left-iframe').get(0), false );
			}
			// woocommerce     → WooCommerce fields injected by Pro JS (initWooCommerceVariantFields).
			// All other types → container stays empty (incl. legacy headline / css / shortcode).
		});
	}

	function addVariantRow(isControl, name, data, weight, variantId) {
		weight = weight || 50;
		variantId = variantId || 0;
		var $list = $('#opti-ab-variants-list');
		var controlClass = isControl ? ' opti-ab-variant-row--control' : '';
		var badgeText = isControl ? ( strings.variant_badge_control || 'Control' ) : ( strings.variant_badge_variant || 'Variant' );

		var html = '<div class="opti-ab-variant-row' + controlClass + '">';
		html += '  <span class="opti-ab-variant-row__badge">' + escHtml( badgeText ) + '</span>';
		html += '  <div class="opti-ab-variant-row__fields">';
		html += '    <input type="text" class="opti-ab-input opti-ab-input--full opti-ab-variant-name" value="' + escAttr(name) + '" placeholder="' + escAttr( strings.variant_name_placeholder || 'Variant name' ) + '">';
		html += '    <input type="hidden" class="opti-ab-variant-data" value="' + escAttr( typeof data === 'string' ? data : JSON.stringify(data) ) + '">';
		html += '    <input type="hidden" class="opti-ab-variant-control" value="' + ( isControl ? '1' : '0' ) + '">';
		html += '    <input type="hidden" class="opti-ab-variant-db-id" value="' + parseInt(variantId, 10) + '">';
		html += '    <input type="number" class="opti-ab-input opti-ab-variant-weight" value="' + weight + '" min="1" max="100" style="width:80px" title="' + escAttr( strings.variant_weight_title || 'Traffic weight' ) + '">';
		html += '    <div class="opti-ab-variant-type-fields"></div>';
		html += '  </div>';
		if ( ! isControl ) {
			html += '  <button type="button" class="opti-ab-variant-row__remove" title="' + escAttr( strings.remove || 'Remove' ) + '">';
			html += '    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
			html += '  </button>';
		}
		html += '</div>';

		$list.append(html);
	}

	/**
	 * Evenly redistribute traffic weights across all variant rows so they sum to 100%.
	 * Uses Math.floor for the base value and assigns the remainder to the last variant.
	 */
	function redistributeWeights() {
		var $inputs = $('#opti-ab-variants-list .opti-ab-variant-weight');
		var n = $inputs.length;
		if ( n === 0 ) { return; }
		var base = Math.floor( 100 / n );
		var remainder = 100 - ( base * n );
		$inputs.each(function(i) {
			$(this).val( i === n - 1 ? base + remainder : base );
		});
	}

	function addGoalRow(type, config) {
		var $list = $('#opti-ab-goals-list');
		var configObj = {};
		if ( typeof config === 'string' && config ) {
			try { configObj = JSON.parse(config); } catch(e) {}
		} else if ( typeof config === 'object' ) {
			configObj = config;
		}

		var hasWoo        = cfg.has_woo || false;
		var freeGoalTypes = ['page_visit', 'click', 'form_submit'];
		var allGoalTypes  = ['page_visit', 'click', 'form_submit', 'scroll_depth', 'time_on_page', 'revenue', 'bounce_rate'];
		var wooGoalTypes  = [];

		if ( isPro && hasWoo ) {
			wooGoalTypes = ['woo_add_to_cart', 'woo_purchase'];
			allGoalTypes = allGoalTypes.concat(wooGoalTypes);
		}

		var html = '<div class="opti-ab-goal-row">';
		html += '  <div class="opti-ab-goal-row__fields">';
		html += '    <select class="opti-ab-select opti-ab-goal-type">';
		allGoalTypes.forEach(function(gt) {
			var disabled  = ( ! isPro && freeGoalTypes.indexOf(gt) === -1 ) ? ' disabled' : '';
			var proLabel  = disabled ? ( strings.pro_suffix || ' (Pro)' ) : '';
			var selected  = ( gt === type ) ? ' selected' : '';
			var wooHidden = ( wooGoalTypes.indexOf(gt) !== -1 ) ? ' style="display:none"' : '';
			html += '<option value="' + gt + '"' + selected + disabled + wooHidden + '>' + ucfirst(gt.replace(/_/g, ' ')) + proLabel + '</option>';
		});
		html += '    </select>';
		html += '    <input type="hidden" class="opti-ab-goal-config-raw" value="">';
		html += '    <div class="opti-ab-goal-config-fields"></div>';
		html += '  </div>';
		html += '  <button type="button" class="opti-ab-goal-row__remove" title="' + escAttr( strings.remove || 'Remove' ) + '">';
		html += '    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
		html += '  </button>';
		html += '</div>';

		$list.append(html);

		// Populate type-specific config fields for the newly added row.
		var $newRow = $list.find('.opti-ab-goal-row').last();
		// Store the raw config so Pro JS can read it when populating existing rows on page load.
		$newRow.find('.opti-ab-goal-config-raw').val( JSON.stringify(configObj) );
		updateGoalConfigFields($newRow, type, configObj);

		// Show/hide woo goal options based on current test type.
		updateGoalTypesForTestType(wizardData.test_type);
	}

	/**
	 * Show or hide WooCommerce-specific goal options in all goal-type selects.
	 *
	 * Called when the test type changes and after each new goal row is added.
	 * Woo goal options are only visible when type === 'woocommerce'.
	 * If a woo goal is currently selected but the type is no longer woocommerce,
	 * the select is reset to 'page_visit'.
	 *
	 * @param {string} type Current test type value.
	 */
	function updateGoalTypesForTestType(type) {
		var wooTypes = ['woo_add_to_cart', 'woo_purchase'];

		$('#opti-ab-goals-list .opti-ab-goal-type').each(function() {
			var $select = $(this);

			wooTypes.forEach(function(wt) {
				var $opt = $select.find('option[value="' + wt + '"]');
				if ( ! $opt.length ) {
					return; // Woo options not present (Pro + WooCommerce not active).
				}

				if ( type === 'woocommerce' ) {
					$opt.show();
				} else {
					$opt.hide();
					// Reset to page_visit if a woo type is currently selected.
					if ( $select.val() === wt ) {
						$select.val('page_visit');
					}
				}
			});
		});
	}

	/**
	 * Populate the goal config fields container for the given goal type.
	 *
	 * Called when a goal row is first added and whenever the goal type dropdown
	 * changes. Free plugin handles page_visit, click, and form_submit. Pro JS
	 * overrides this function (via updateProGoalConfigFields) for Pro-only types.
	 *
	 * @param {jQuery} $row      The .opti-ab-goal-row element.
	 * @param {string} goalType  The goal type value (e.g. 'page_visit', 'click').
	 * @param {Object} configObj Existing goal_config object for pre-filling values.
	 */
	function updateGoalConfigFields($row, goalType, configObj) {
		var $container = $row.find('.opti-ab-goal-config-fields');
		$container.empty();

		switch ( goalType ) {

			case 'page_visit':
				var urlVal  = configObj.url || '';
				var urlPholder = strings.goal_url_placeholder || ( ( cfg.home_url || '' ) + '/thank-you/' );
				$container.append(
					'<label class="opti-ab-field-label">' + escHtml( strings.goal_url_label || 'Destination URL' ) + '</label>' +
					'<div class="opti-ab-goal-url-wrap">' +
						'<input type="url" class="opti-ab-input opti-ab-input--full opti-ab-goal-url"' +
						' value="' + escAttr( urlVal ) + '" placeholder="' + escAttr( urlPholder ) + '">' +
						'<span class="opti-ab-goal-url-status" aria-hidden="true"></span>' +
					'</div>' +
					'<p class="opti-ab-field-help">' + escHtml( strings.goal_url_help || 'Visitors who land on this URL will be counted as conversions.' ) + '</p>'
				);
				// Trigger initial validation if a URL is already set.
				if ( urlVal ) {
					$container.find('.opti-ab-goal-url').trigger('input');
				}
				break;

			case 'click':
				// Backward compatibility: accept both { selector: "..." } and { selectors: [...] }.
				var clickSelectors = [];
				if ( configObj.selectors && Array.isArray( configObj.selectors ) ) {
					clickSelectors = configObj.selectors.slice();
				} else if ( configObj.selector ) {
					clickSelectors = [ configObj.selector ];
				}

				var selPholder = strings.goal_selector_placeholder || 'e.g., .buy-button, #cta-link';

				$container.append(
					'<label class="opti-ab-field-label">' + escHtml( strings.goal_selector_label || 'CSS Selectors' ) + '</label>' +
					'<div class="opti-ab-goal-selectors-list"></div>' +
					'<div class="opti-ab-goal-click-actions">' +
						'<button type="button" class="button opti-ab-goal-add-selector">' +
							escHtml( strings.goal_click_add_element || '+ Add CSS Selector' ) +
						'</button>' +
						'<button type="button" class="button opti-ab-goal-pick-selector" disabled aria-disabled="true"' +
							' title="' + escAttr( strings.goal_click_pick_pro_title || 'Upgrade to Pro to pick elements visually from the live page.' ) + '">' +
							escHtml( strings.goal_click_pick_element || '✦ Pick Element Visually' ) +
							' <span class="opti-ab-pro-badge">' + escHtml( strings.pro_badge || 'Pro' ) + '</span>' +
						'</button>' +
					'</div>' +
					'<p class="opti-ab-field-help">' + escHtml( strings.goal_selector_help || 'Track clicks on one or more elements. Add multiple elements to track any of them.' ) + '</p>'
				);

				// Populate existing selectors (or add one empty row if none).
				var $selectorsList = $container.find('.opti-ab-goal-selectors-list');
				if ( clickSelectors.length > 0 ) {
					clickSelectors.forEach(function( sel ) {
						appendSelectorEntry( $selectorsList, sel, selPholder );
					});
				} else {
					appendSelectorEntry( $selectorsList, '', selPholder );
				}
				break;

			case 'form_submit':
				var existingSel  = configObj.selector || '';
				var formMode     = ( existingSel && existingSel !== 'form' ) ? 'custom' : 'any';
				var customSel    = ( formMode === 'custom' ) ? existingSel : '';
				var selPholder2  = strings.goal_form_selector_placeholder || 'e.g., #contact-form, .wpcf7-form';
				$container.append(
					'<label class="opti-ab-field-label">' + escHtml( strings.goal_form_label || 'Form' ) + '</label>' +
					'<select class="opti-ab-select opti-ab-goal-form-mode">' +
						'<option value="any"' + ( formMode === 'any' ? ' selected' : '' ) + '>' + escHtml( strings.goal_form_any || 'Any form on page' ) + '</option>' +
						'<option value="custom"' + ( formMode === 'custom' ? ' selected' : '' ) + '>' + escHtml( strings.goal_form_custom || 'Custom CSS selector' ) + '</option>' +
					'</select>' +
					'<div class="opti-ab-goal-form-custom-wrap"' + ( formMode !== 'custom' ? ' style="display:none"' : '' ) + '>' +
						'<input type="text" class="opti-ab-input opti-ab-input--full opti-ab-goal-form-selector"' +
						' value="' + escAttr( customSel ) + '" placeholder="' + escAttr( selPholder2 ) + '">' +
					'</div>' +
					'<p class="opti-ab-field-help">' + escHtml( strings.goal_form_help || 'Form submissions on the page will be counted as conversions.' ) + '</p>'
				);
				break;

			case 'scroll_depth':
				// When Pro is active, Pro JS will render an interactive slider instead.
				if ( ! isPro ) {
					var depthVal = parseInt( configObj.depth, 10 );
					if ( isNaN( depthVal ) || depthVal < 0 || depthVal > 100 ) {
						depthVal = 75;
					}
					$container.append(
						'<label class="opti-ab-field-label">' +
							escHtml( strings.goal_scroll_label || 'Scroll Depth' ) +
							' <span class="opti-ab-pro-badge">' + escHtml( strings.pro_badge || 'Pro' ) + '</span>' +
						'</label>' +
						'<div class="opti-ab-goal-scroll-wrap">' +
							'<input type="range" class="opti-ab-goal-scroll-slider" min="0" max="100" step="5"' +
							' value="' + depthVal + '" disabled aria-hidden="true">' +
							'<div class="opti-ab-goal-scroll-number-wrap">' +
								'<input type="number" class="opti-ab-input opti-ab-goal-scroll-number"' +
								' min="0" max="100" value="' + depthVal + '" disabled>' +
								'<span class="opti-ab-goal-unit-label">%</span>' +
							'</div>' +
						'</div>' +
						'<p class="opti-ab-field-help opti-ab-field-help--pro">' +
							escHtml( strings.goal_scroll_help_pro || 'Upgrade to Pro to track scroll depth conversions.' ) +
						'</p>'
					);
				}
				break;

			case 'time_on_page':
				// When Pro is active, Pro JS will render an interactive time input instead.
				if ( ! isPro ) {
					var rawSecs   = parseInt( configObj.seconds, 10 );
					if ( isNaN( rawSecs ) || rawSecs < 1 ) {
						rawSecs = 30;
					}
					var timeUnit = ( rawSecs >= 60 && rawSecs % 60 === 0 ) ? 'minutes' : 'seconds';
					var timeVal  = ( timeUnit === 'minutes' ) ? ( rawSecs / 60 ) : rawSecs;
					$container.append(
						'<label class="opti-ab-field-label">' +
							escHtml( strings.goal_time_label || 'Time on Page' ) +
							' <span class="opti-ab-pro-badge">' + escHtml( strings.pro_badge || 'Pro' ) + '</span>' +
						'</label>' +
						'<div class="opti-ab-goal-time-wrap">' +
							'<input type="number" class="opti-ab-input opti-ab-goal-time-value"' +
							' min="1" value="' + timeVal + '" disabled>' +
							'<select class="opti-ab-select opti-ab-goal-time-unit" disabled>' +
								'<option value="seconds"' + ( timeUnit === 'seconds' ? ' selected' : '' ) + '>' +
									escHtml( strings.goal_time_seconds || 'seconds' ) +
								'</option>' +
								'<option value="minutes"' + ( timeUnit === 'minutes' ? ' selected' : '' ) + '>' +
									escHtml( strings.goal_time_minutes || 'minutes' ) +
								'</option>' +
							'</select>' +
						'</div>' +
						'<p class="opti-ab-field-help opti-ab-field-help--pro">' +
							escHtml( strings.goal_time_help_pro || 'Upgrade to Pro to track time-on-page conversions.' ) +
						'</p>'
					);
				}
				break;

			case 'revenue':
			case 'woo_add_to_cart':
			case 'woo_purchase':
			case 'bounce_rate':
				// When Pro is active, Pro JS will render the full interactive config for these types.
				if ( ! isPro ) {
					$container.append(
						'<div class="opti-ab-goal-pro-required">' +
							'<span class="opti-ab-pro-badge">' + escHtml( strings.pro_badge || 'Pro' ) + '</span>' +
							'<p class="opti-ab-goal-pro-required__msg">' +
								escHtml( strings.goal_pro_required || 'Upgrade to Opti-Behavior Pro to use this goal type.' ) +
							'</p>' +
						'</div>'
					);
				}
				break;

			default:
				break;
		}
	}

	/**
	 * Append a single selector entry row to a .opti-ab-goal-selectors-list container.
	 *
	 * Each entry has a text input, a label area for element description, and a remove button.
	 * Pro JS can inject a "✦ Select" button into each entry.
	 *
	 * @param {jQuery} $list       The .opti-ab-goal-selectors-list container.
	 * @param {string} selectorVal Pre-filled CSS selector value.
	 * @param {string} placeholder Placeholder text for the input.
	 */
	function appendSelectorEntry( $list, selectorVal, placeholder ) {
		var html = '<div class="opti-ab-goal-selector-entry">';
		html += '<div class="opti-ab-goal-selector-entry__main">';
		html += '<input type="text" class="opti-ab-input opti-ab-input--full opti-ab-goal-selector-input"';
		html += ' value="' + escAttr( selectorVal ) + '" placeholder="' + escAttr( placeholder ) + '">';
		html += '</div>';
		html += '<button type="button" class="opti-ab-goal-selector-entry__remove" title="' + escAttr( strings.remove || 'Remove' ) + '">&times;</button>';
		html += '</div>';
		$list.append( html );
	}

	function collectWizardData() {
		// Collect variants, merging any type-specific visible inputs into variant_data.
		var variants = [];
		$('#opti-ab-variants-list .opti-ab-variant-row').each(function(i) {
			var $row = $(this);
			var currentData = {};

			try {
				currentData = JSON.parse($row.find('.opti-ab-variant-data').val() || '{}');
			} catch(e) {
				currentData = {};
			}

			// Free JS type-specific inputs.
			var $redirectUrl = $row.find('.opti-ab-variant-redirect-url');

			if ( $redirectUrl.length ) { currentData.redirect_url = $redirectUrl.val(); }

			// WooCommerce fields rendered by Pro JS — read them here so free JS
			// collectWizardData() captures their values without any Pro JS coupling.
			var $wooTitle      = $row.find('.opti-ab-variant-woo-title');
			var $wooPrice      = $row.find('.opti-ab-variant-woo-price');
			var $wooShortDesc  = $row.find('.opti-ab-variant-woo-short-desc');
			var $wooFullDesc   = $row.find('.opti-ab-variant-woo-full-desc');
			var $wooDesc       = $row.find('.opti-ab-variant-woo-description'); // backward compat hidden
			var $wooCart       = $row.find('.opti-ab-variant-woo-cart-text');
			var $wooImgId      = $row.find('.opti-ab-variant-woo-image-id');
			var $wooImgUrl     = $row.find('.opti-ab-variant-woo-image-url');
			var $wooRegPrice   = $row.find('.opti-ab-variant-woo-regular-price');
			var $wooSalePrice  = $row.find('.opti-ab-variant-woo-sale-price');
			var $wooGalleryIds = $row.find('.opti-ab-variant-woo-gallery-ids');

			if ( $wooTitle.length )     { currentData.title              = $wooTitle.val(); }
			if ( $wooPrice.length )     { currentData.price              = $wooPrice.val(); }
			if ( $wooShortDesc.length ) { currentData.short_description  = $wooShortDesc.val(); }
			if ( $wooFullDesc.length )  { currentData.full_description   = $wooFullDesc.val(); }
			// Backward compat: also write 'description' key for the WooCommerce filter.
			if ( $wooShortDesc.length ) { currentData.description        = $wooShortDesc.val(); }
			if ( $wooCart.length  )     { currentData.add_to_cart_text   = $wooCart.val(); }
			if ( $wooImgId.length && $wooImgId.val() ) { currentData.image_id = parseInt($wooImgId.val(), 10); }
			if ( $wooImgUrl.length )    { currentData.image_url          = $wooImgUrl.val(); }
			if ( $wooRegPrice.length )  { currentData.regular_price      = $wooRegPrice.val(); }
			if ( $wooSalePrice.length ) { currentData.sale_price         = $wooSalePrice.val(); }
			if ( $wooGalleryIds.length ) {
				try {
					var gIds = JSON.parse($wooGalleryIds.val() || '[]');
					if ( Array.isArray(gIds) && gIds.length > 0 ) { currentData.gallery_image_ids = gIds; }
				} catch(ex) {}
			}
			// Collect extension fields.
			$row.find('.opti-ab-variant-woo-ext-field').each(function() {
				var slug = $(this).data('ext-slug');
				var key  = $(this).data('ext-key');
				var val  = $(this).val();
				if ( slug && key && val ) {
					if ( ! currentData.ext ) { currentData.ext = {}; }
					if ( ! currentData.ext[slug] ) { currentData.ext[slug] = {}; }
					currentData.ext[slug][key] = val;
				}
			});

			variants.push({
				id:             parseInt($row.find('.opti-ab-variant-db-id').val(), 10) || 0,
				name:           $row.find('.opti-ab-variant-name').val(),
				is_control:     parseInt($row.find('.opti-ab-variant-control').val(), 10),
				traffic_weight: parseInt($row.find('.opti-ab-variant-weight').val(), 10) || 50,
				variant_data:   JSON.stringify(currentData),
				sort_order:     i
			});
		});

		// Collect goals.
		var goals = [];
		$('#opti-ab-goals-list .opti-ab-goal-row').each(function() {
			var $goalRow   = $(this);
			var goalType   = $goalRow.find('.opti-ab-goal-type').val();
			var goalConfig = {};

			switch ( goalType ) {
				case 'page_visit':
					goalConfig.url = $goalRow.find('.opti-ab-goal-url').val() || '';
					break;
				case 'click':
					var clickSels = [];
					$goalRow.find('.opti-ab-goal-selectors-list .opti-ab-goal-selector-input').each(function() {
						var v = $(this).val().trim();
						if ( v ) { clickSels.push( v ); }
					});
					goalConfig.selectors = clickSels;
					break;
				case 'form_submit':
					var formMode = $goalRow.find('.opti-ab-goal-form-mode').val();
					if ( formMode === 'detected' ) {
						// Pro form picker: hidden selector holds auto-generated CSS selector.
						goalConfig.selector = $goalRow.find('.opti-ab-goal-form-selector').val() || 'form';
						var fpId   = $goalRow.find('.opti-ab-goal-form-id').val();
						var fpName = $goalRow.find('.opti-ab-goal-form-name-field').val();
						if ( fpId )   { goalConfig.form_id   = fpId; }
						if ( fpName ) { goalConfig.form_name = fpName; }
					} else if ( formMode === 'custom' ) {
						// Free mode: .opti-ab-goal-form-selector; Pro custom mode: .opti-ab-goal-form-custom-selector.
						var $selInput = $goalRow.find('.opti-ab-goal-form-custom-selector');
						goalConfig.selector = ( $selInput.length ? $selInput : $goalRow.find('.opti-ab-goal-form-selector') ).val() || 'form';
					} else {
						goalConfig.selector = 'form';
					}
					break;
				// Pro types: Pro JS (collectWizardDataForSave) reads Pro-specific fields
				// and overwrites these defaults via its own goal-collection loop.
				case 'scroll_depth':
					goalConfig.depth = 75;
					break;
				case 'time_on_page':
					goalConfig.seconds = 30;
					break;
				case 'revenue':
					goalConfig.track_revenue = true;
					break;
				case 'woo_add_to_cart':
					goalConfig.track_add_to_cart = true;
					break;
				case 'woo_purchase':
					goalConfig.track_purchase = true;
					break;
				case 'bounce_rate':
					// Default values — Pro JS overwrites with actual field values when active.
					goalConfig.threshold_seconds = 10;
					goalConfig.inverse = true;
					break;
			}

			goals.push({
				goal_type:   goalType,
				goal_config: JSON.stringify(goalConfig),
				is_primary:  goals.length === 0 ? 1 : 0
			});
		});

		// Merge with wizard settings data.
		var currentType = wizardData.test_type || $('input[name="test_type"]:checked').val();
		var targetPostId = wizardData.target_post_id || $('#opti-ab-target-post').val() || 0;
		var targetUrl = wizardData.target_url || $('#opti-ab-target-url').val();

		// WooCommerce type: use the product picker hidden input.
		if ( currentType === 'woocommerce' ) {
			targetPostId = parseInt( $('#opti-ab-woo-selected-product-id').val(), 10 ) || targetPostId;
			targetUrl = '';
		} else if ( currentType === 'element' ) {
			var elementTargetState = getElementTargetState();
			targetPostId = elementTargetState.target_post_id;
			targetUrl = elementTargetState.target_url;
		} else {
			targetPostId = 0;
		}

		return {
			test_id:            parseInt($('#opti-ab-wizard').data('test-id'), 10) || 0,
			origin_insight_id:  parseInt($('#opti-ab-wizard').data('origin-insight-id'), 10) || 0,
			name:               wizardData.name || $('#opti-ab-test-name').val(),
			test_type:          currentType,
			target_url:         targetUrl,
			target_post_id:     targetPostId,
			confidence_level:   parseFloat($('#opti-ab-confidence').val()) || 0.95,
			traffic_percentage: parseInt($('#opti-ab-traffic').val(), 10) || 100,
			min_sample_size:    parseInt($('#opti-ab-min-sample').val(), 10) || 100,
			min_duration_days:  parseInt($('#opti-ab-min-duration').val(), 10) || 7,
			variants:           variants,
			goals:              goals
		};
	}

	function saveTest(mode) {
		// Validate up to current step.
		for ( var s = 1; s <= Math.min(wizardStep, 5); s++ ) {
			if ( ! validateStep(s) ) return;
		}

		if ( autoSaveInFlight ) {
			setTimeout(function() {
				saveTest(mode);
			}, 250);
			return;
		}

		// Use Pro collector if available, otherwise free collector — mirrors
		// autoSaveDraft()/openVEAfterAutoSave() so the final save always picks
		// up Pro-specific goal fields (e.g. woo_purchase/woo_add_to_cart/revenue scope).
		var data;
		if ( window.optiBehaviorABProModule && typeof window.optiBehaviorABProModule.collectWizardDataForSave === 'function' ) {
			data = window.optiBehaviorABProModule.collectWizardDataForSave();
		} else {
			data = collectWizardData();
		}
		var $btn = ( mode === 'launch' ) ? $('#opti-ab-wizard-launch') : $('#opti-ab-wizard-save-draft');
		$btn.prop('disabled', true).text(strings.saving);

		$.post(ajaxUrl, {
			action:    'opti_behavior_ab_save_test_full',
			nonce:     nonce,
			test_data: JSON.stringify(data),
			launch:    mode === 'launch' ? 1 : 0
		}, function(res) {
			$btn.prop('disabled', false);
			if ( res.success ) {
				syncVariantIdsFromResponse( res.data );
				try { sessionStorage.removeItem( 'opti_ab_wizard_step_' + wizardTestId ); } catch (e) {}
				showToast(strings.saved, 'success');

				// Add-ons (Pro targeting rules, schedule) persist their own
				// data on this event. They push their jqXHRs into `pending` so
				// the redirect below cannot abort a save in flight.
				var pending = [];
				$(document).trigger('opti_behavior_ab_test_saved', [res.data, pending]);

				// Redirect to list or results.
				var targetPage = mode === 'launch' ?
					'admin.php?page=opti-behavior-ab-testing&view=results&test_id=' + res.data.test_id :
					'admin.php?page=opti-behavior-ab-testing';
				var redirect = function() {
					window.location.href = ajaxUrl.replace('admin-ajax.php', targetPage);
				};
				if ( pending.length ) {
					$.when.apply($, pending).always(redirect);
				} else {
					redirect();
				}
			} else {
				showToast(res.data && res.data.message ? res.data.message : strings.error, 'error');
				$btn.text(mode === 'launch' ? ( strings.launch_test || 'Launch Test' ) : ( strings.save_draft || 'Save Draft' ));
			}
		}).fail(function() {
			showToast(strings.error, 'error');
			$btn.prop('disabled', false).text(mode === 'launch' ? ( strings.launch_test || 'Launch Test' ) : ( strings.save_draft || 'Save Draft' ));
		});
	}

	/**
	 * Silently auto-save the wizard as a draft in the background.
	 * Called when navigating between steps so that data is never lost.
	 */
	function autoSaveDraft() {
		if ( autoSaveInFlight ) {
			return;
		}

		// Use Pro collector if available, otherwise free collector.
		var data;
		if ( window.optiBehaviorABProModule && typeof window.optiBehaviorABProModule.collectWizardDataForSave === 'function' ) {
			data = window.optiBehaviorABProModule.collectWizardDataForSave();
		} else {
			data = collectWizardData();
		}

		// Need at minimum a test type and name to save.
		if ( ! data.test_type ) return;
		if ( ! data.name ) {
			// Generate a default name so auto-save can work for brand-new tests.
			data.name = data.test_type.replace(/_/g, ' ').replace(/\b\w/g, function(l){ return l.toUpperCase(); }) + ' Test';
		}

		autoSaveInFlight = true;
		$.post(ajaxUrl, {
			action:    'opti_behavior_ab_save_test_full',
			nonce:     nonce,
			test_data: JSON.stringify(data),
			launch:    0
		}, function(res) {
			if ( res.success && res.data.test_id ) {
				syncVariantIdsFromResponse( res.data );
				// Same contract as saveTest(): let add-ons persist their own
				// data for this test id (no redirect here, nothing to wait on).
				$(document).trigger('opti_behavior_ab_test_saved', [res.data, []]);
				// Store the new test_id if this was a brand-new test.
				var newId = parseInt(res.data.test_id, 10);
				if ( newId && ! wizardTestId ) {
					wizardTestId = newId;
					$('#opti-ab-wizard').data('test-id', newId);
					// Update browser URL so refresh loads the saved test.
					if ( window.history && window.history.replaceState ) {
						var newUrl = window.location.href.replace(
							/([?&])test_id=\d*/,
							'$1test_id=' + newId
						);
						if ( newUrl === window.location.href ) {
							newUrl += ( window.location.href.indexOf('?') > -1 ? '&' : '?' ) + 'test_id=' + newId;
						}
						window.history.replaceState(null, '', newUrl);
					}
				}
			}
		}).always(function() {
			autoSaveInFlight = false;
		});
		// Fire-and-forget: don't block the UI.
	}

	/**
	 * Build a Visual Editor URL for a saved test variant.
	 *
	 * @param {string} veUrl     Visual Editor base URL.
	 * @param {number} testId    Saved test ID.
	 * @param {number} variantId Saved variant ID.
	 * @return {string} Editor URL.
	 */
	function buildVisualEditorHref( veUrl, testId, variantId ) {
		return veUrl + '&test_id=' + encodeURIComponent( testId ) + '&variant_id=' + encodeURIComponent( variantId ) + '&builder_step=3';
	}

	/**
	 * Sync hidden variant IDs after full-save so direct Visual Editor links keep
	 * pointing at the same database rows across autosaves.
	 *
	 * @param {Object} data AJAX response data.
	 */
	function syncVariantIdsFromResponse( data ) {
		var variants = data && Array.isArray( data.variants ) ? data.variants : [];
		if ( ! variants.length ) { return; }

		var currentTestId = parseInt( ( data && data.test_id ) || $('#opti-ab-wizard').data('test-id'), 10 ) || 0;
		var veUrl = ( window.optiBehaviorABAdmin && window.optiBehaviorABAdmin.visual_editor_url ) || '';
		var $rows = $('#opti-ab-variants-list .opti-ab-variant-row');

		variants.forEach(function(variant) {
			var index = parseInt( variant.index, 10 );
			var id    = parseInt( variant.id, 10 ) || 0;
			if ( isNaN(index) || ! id ) { return; }

			var $row = $rows.eq(index);
			if ( ! $row.length ) { return; }

			$row.find('.opti-ab-variant-db-id').val(id);

			if ( veUrl && currentTestId ) {
				$row.find('.opti-ab-ve-open-btn[href]').attr('href', buildVisualEditorHref( veUrl, currentTestId, id ));
			}
		});
	}

	/**
	 * Auto-save the test as a draft, then convert the launcher to a direct
	 * Visual Editor link for the given variant.
	 * Called when the VE button is clicked for a variant not yet in the database.
	 *
	 * @param {number} testId       Current test ID (may be 0 for brand-new tests).
	 * @param {number} variantIndex Zero-based index of the variant row.
	 * @param {string} veUrl        Visual Editor base URL.
	 */
	function openVEAfterAutoSave( testId, variantIndex, veUrl ) {
		var data;
		if ( window.optiBehaviorABProModule && typeof window.optiBehaviorABProModule.collectWizardDataForSave === 'function' ) {
			data = window.optiBehaviorABProModule.collectWizardDataForSave();
		} else {
			data = collectWizardData();
		}

		$.post( ajaxUrl, {
			action:    'opti_behavior_ab_save_test_full',
			nonce:     nonce,
			test_data: JSON.stringify( data ),
			launch:    0
		}, function( saveRes ) {
			if ( ! saveRes.success ) {
				if ( typeof window.showToast === 'function' ) {
					window.showToast( ( strings.ve_draft_save_failed || 'Could not save draft. Please try again.' ), 'error' );
				}
				return;
			}

			syncVariantIdsFromResponse( saveRes.data );
			var savedTestId = parseInt( saveRes.data.test_id, 10 ) || testId;
			$('#opti-ab-wizard').data( 'test-id', savedTestId );
			if ( ! wizardTestId ) { wizardTestId = savedTestId; }

			$.post( ajaxUrl, {
				action:  'opti_behavior_ab_get_results',
				nonce:   nonce,
				test_id: savedTestId
			}, function( res ) {
				var variantId = 0;
				var $row = $('#opti-ab-variants-list .opti-ab-variant-row').eq( variantIndex );

				if ( res.success && res.data.results && variantIndex < res.data.results.length ) {
					variantId = parseInt( res.data.results[ variantIndex ].variant_id, 10 ) || 0;
				}

				if ( variantId && $row.length ) {
					$row.find('.opti-ab-variant-db-id').val( variantId );
					updateVariantTypeFields( wizardData.test_type, $row );
					if ( typeof window.showToast === 'function' ) {
						window.showToast( ( strings.ve_draft_saved_retry || 'Draft saved. Click Open Visual Editor again to open the direct link.' ), 'success' );
					}
				} else if ( typeof window.showToast === 'function' ) {
					window.showToast( ( strings.ve_draft_saved_no_variant || 'Draft saved but could not find variant. Please save the draft, then use the direct Visual Editor link.' ), 'error' );
				}
			} ).fail( function() {
				if ( typeof window.showToast === 'function' ) {
					window.showToast( ( strings.ve_draft_saved_no_data || 'Draft saved but variant data could not be loaded. Please save the draft, then use the direct Visual Editor link.' ), 'error' );
				}
			} );
		} ).fail( function() {
			if ( typeof window.showToast === 'function' ) {
				window.showToast( ( strings.ve_draft_save_failed || 'Could not save draft. Please try again.' ), 'error' );
			}
		} );
	}

	function buildReviewSummary() {
		// Use Pro collector if available, otherwise free collector — so the
		// review step reflects Pro-specific goal fields (scope, product_id, etc.)
		// rather than the free plugin's placeholder defaults.
		var data;
		if ( window.optiBehaviorABProModule && typeof window.optiBehaviorABProModule.collectWizardDataForSave === 'function' ) {
			data = window.optiBehaviorABProModule.collectWizardDataForSave();
		} else {
			data = collectWizardData();
		}
		var html = '';

		// Test info.
		html += '<div class="opti-ab-review-section"><h4>' + escHtml( strings.review_test_info || 'Test Info' ) + '</h4><table>';
		html += '<tr><td>' + escHtml( strings.review_name || 'Name' ) + '</td><td>' + escHtml(data.name) + '</td></tr>';
		html += '<tr><td>' + escHtml( strings.review_type || 'Type' ) + '</td><td>' + ucfirst((data.test_type || '').replace(/_/g, ' ')) + '</td></tr>';
		html += '<tr><td>' + escHtml( strings.review_target || 'Target' ) + '</td><td>' + escHtml(data.target_url || ( strings.target_post_fallback || 'Post #' ) + data.target_post_id) + '</td></tr>';
		html += '</table></div>';

		// Variants.
		html += '<div class="opti-ab-review-section"><h4>' + escHtml( strings.step_variants || 'Variants' ) + ' (' + data.variants.length + ')</h4><table>';
		data.variants.forEach(function(v) {
			var vd = {};
			try { vd = JSON.parse(v.variant_data || '{}'); } catch(e) {}

			// Build a type-specific summary snippet to append after the weight.
			var typeDetail = '';
			if ( data.test_type === 'page_split' && vd.redirect_url ) {
				typeDetail = ' &rarr; ' + escHtml(vd.redirect_url);
			} else if ( data.test_type === 'woocommerce' ) {
				var wooDetails = [];
				if ( vd.title ) { wooDetails.push(( strings.review_woo_title_prefix || 'title: ' ) + escHtml(vd.title)); }
				if ( vd.price ) { wooDetails.push(( strings.review_woo_price_prefix || 'price: ' ) + escHtml(String(vd.price))); }
				if ( wooDetails.length ) { typeDetail = ' &mdash; ' + wooDetails.join(', '); }
			} else if ( data.test_type === 'element' || data.test_type === 'css' ) {
				typeDetail = ' <em>' + escHtml( strings.review_visual_editor || '(Visual Editor)' ) + '</em>';
			}

			html += '<tr><td>' + escHtml(v.name) + '</td><td>' + (v.is_control ? '<strong>' + escHtml( strings.variant_badge_control || 'Control' ) + '</strong>' : escHtml( strings.variant_badge_variant || 'Variant' )) + ' &mdash; ' + escHtml( strings.review_weight || 'Weight: ' ) + v.traffic_weight + '%' + typeDetail + '</td></tr>';
		});
		html += '</table></div>';

		// Goals.
		html += '<div class="opti-ab-review-section"><h4>' + escHtml( strings.step_goals || 'Goals' ) + ' (' + data.goals.length + ')</h4><table>';
		data.goals.forEach(function(g) {
			var gcObj = {};
			try { gcObj = JSON.parse(g.goal_config || '{}'); } catch (e) {}

			var goalLabel  = ucfirst( (g.goal_type || '').replace(/_/g, ' ') );
			var goalDetail = '';

			switch ( g.goal_type ) {
				case 'page_visit':
					goalDetail = gcObj.url
						? '&rarr; ' + escHtml(gcObj.url)
						: '<em>' + escHtml(strings.review_goal_no_url || '(no URL set)') + '</em>';
					break;
				case 'click':
					// Support both { selectors: [...] } and legacy { selector: "..." }.
					var revClickSels = gcObj.selectors && gcObj.selectors.length
						? gcObj.selectors
						: ( gcObj.selector ? [ gcObj.selector ] : [] );
					if ( revClickSels.length > 0 ) {
						goalDetail = revClickSels.length + ' ' + ( revClickSels.length > 1 ? ( strings.review_element_many || 'elements' ) : ( strings.review_element_one || 'element' ) ) + ': ';
						goalDetail += revClickSels.map(function( s ) { return '<code>' + escHtml( s ) + '</code>'; }).join(', ');
					} else {
						goalDetail = '<em>' + escHtml(strings.review_goal_no_selector || '(no selector set)') + '</em>';
					}
					break;
				case 'form_submit':
					if ( gcObj.form_name ) {
						goalDetail = escHtml(strings.review_goal_detected_form || 'Detected form') + ': <strong>' + escHtml(gcObj.form_name) + '</strong>';
					} else if ( gcObj.selector_per_variant ) {
						goalDetail = escHtml(strings.review_goal_per_variant_form || 'Per-variant form selector');
					} else if ( gcObj.selector && gcObj.selector !== 'form' ) {
						goalDetail = escHtml(strings.review_goal_custom_form || 'Custom selector') + ': <code>' + escHtml(gcObj.selector) + '</code>';
					} else {
						goalDetail = escHtml(strings.review_goal_any_form || 'Any form on page');
					}
					break;
				case 'scroll_depth':
					goalDetail = (gcObj.depth || 75) + '%';
					break;
				case 'time_on_page':
					var secs = gcObj.seconds || 30;
					goalDetail = secs >= 60
						? Math.floor(secs / 60) + ' min' + (secs % 60 ? ' ' + (secs % 60) + 's' : '')
						: secs + 's';
					break;
				case 'revenue':
					var revScope = gcObj.scope || 'any';
					if ( revScope === 'category' && gcObj.category_id ) {
						goalDetail = escHtml( strings.review_goal_revenue_category || 'Category ID' ) + ': ' + escHtml( String( gcObj.category_id ) );
					} else if ( revScope === 'min_value' && gcObj.min_value ) {
						goalDetail = escHtml( strings.review_goal_revenue_min || 'Min value' ) + ': ' + escHtml( String( gcObj.min_value ) );
					} else if ( revScope === 'product' ) {
						goalDetail = escHtml( strings.review_goal_revenue_product || 'Current page product' );
					} else {
						goalDetail = escHtml( strings.review_goal_any_revenue || 'Any revenue' );
					}
					break;
				case 'woo_add_to_cart':
					var cartScope = gcObj.scope || 'any';
					if ( cartScope === 'specific' && gcObj.product_id ) {
						goalDetail = escHtml( strings.review_goal_cart_product || 'Product ID' ) + ': ' + escHtml( String( gcObj.product_id ) );
					} else if ( cartScope === 'current' ) {
						goalDetail = escHtml( strings.review_goal_cart_current || 'Current page product' );
					} else {
						goalDetail = escHtml( strings.review_goal_any_add_to_cart || 'Any product' );
					}
					break;
				case 'woo_purchase':
					var purchScope = gcObj.scope || 'any';
					if ( purchScope === 'min_value' && gcObj.min_value ) {
						goalDetail = escHtml( strings.review_goal_purchase_min || 'Min value' ) + ': ' + escHtml( String( gcObj.min_value ) );
					} else if ( purchScope === 'current' ) {
						goalDetail = escHtml( strings.review_goal_purchase_current || 'Current page product' );
					} else {
						goalDetail = escHtml( strings.review_goal_any_purchase || 'Any purchase' );
					}
					break;
				case 'bounce_rate':
					var bounceThreshold = gcObj.threshold_seconds || 10;
					var bounceInverse   = gcObj.inverse !== false; // default: true (stays = conversion)
					goalDetail = bounceThreshold + 's — ' + escHtml(
						bounceInverse
							? ( strings.review_goal_bounce_stays || 'Stays on page' )
							: ( strings.review_goal_bounce_bounces || 'Bounces off page' )
					);
					break;
				default:
					goalDetail = escHtml(g.goal_config);
					break;
			}

			html += '<tr><td>' + goalLabel + '</td><td>' + goalDetail + '</td></tr>';
		});
		html += '</table></div>';

		// Settings.
		html += '<div class="opti-ab-review-section"><h4>' + escHtml( strings.step_settings || 'Settings' ) + '</h4><table>';
		html += '<tr><td>' + escHtml( strings.review_confidence || 'Confidence' ) + '</td><td>' + (data.confidence_level * 100) + '%</td></tr>';
		html += '<tr><td>' + escHtml( strings.review_traffic || 'Traffic %' ) + '</td><td>' + data.traffic_percentage + '%</td></tr>';
		html += '<tr><td>' + escHtml( strings.review_min_sample || 'Min Sample Size' ) + '</td><td>' + data.min_sample_size + '</td></tr>';
		html += '<tr><td>' + escHtml( strings.review_min_duration || 'Min Duration' ) + '</td><td>' + data.min_duration_days + ' ' + escHtml( strings.day_many || 'days' ) + '</td></tr>';
		html += '</table></div>';

		$('#opti-ab-review-summary').html(html);
	}

	// =========================================================================
	// Results Page
	// =========================================================================

	/**
	 * Goal type human-readable labels.
	 */
	var goalTypeLabels = {
		page_visit:      ( strings.goal_label_page_visit || 'Page Visit' ),
		click:           ( strings.goal_label_click || 'Click' ),
		form_submit:     ( strings.goal_label_form_submit || 'Form Submit' ),
		scroll_depth:    ( strings.goal_label_scroll_depth || 'Scroll Depth' ),
		time_on_page:    ( strings.goal_label_time_on_page || 'Time on Page' ),
		revenue:         ( strings.goal_label_revenue || 'Revenue' ),
		bounce_rate:     ( strings.goal_label_bounce_rate || 'Bounce Rate' ),
		woo_add_to_cart: ( strings.goal_label_woo_add_to_cart || 'Woo Add to Cart' ),
		woo_purchase:    ( strings.goal_label_woo_purchase || 'Woo Purchase' )
	};

	/**
	 * Human-readable one-line explanation of each goal type, shown in the
	 * Goal Configuration card so users remember what they configured for
	 * the currently-selected tab.
	 */
	var goalTypeHints = {
		page_visit:      ( strings.goal_hint_page_visit || 'Counts a conversion each time a visitor loads the target URL.' ),
		click:           ( strings.goal_hint_click || 'Counts a conversion each time a visitor clicks an element matching any of the selectors below.' ),
		form_submit:     ( strings.goal_hint_form_submit || 'Counts a conversion each time a visitor submits a form matching the selector.' ),
		scroll_depth:    ( strings.goal_hint_scroll_depth || 'Counts a conversion when the visitor scrolls at least this far down the page.' ),
		time_on_page:    ( strings.goal_hint_time_on_page || 'Counts a conversion when the visitor stays on the page for at least this long.' ),
		revenue:         ( strings.goal_hint_revenue || 'Aggregates revenue from WooCommerce orders attributed to the test.' ),
		bounce_rate:     ( strings.goal_hint_bounce_rate || 'Session is a “non-bounce” when it lasts longer than this threshold.' ),
		woo_add_to_cart: ( strings.goal_hint_woo_add_to_cart || 'Counts a conversion when the visitor adds the test product to their cart.' ),
		woo_purchase:    ( strings.goal_hint_woo_purchase || 'Counts a conversion when the visitor completes a purchase of the test product.' )
	};

	/**
	 * Goal type → conversion label for variant cards.
	 */
	var goalConversionLabels = {
		page_visit:  ( strings.goal_conv_page_visit || 'Page Visits' ),
		click:       ( strings.goal_conv_click || 'Clicks' ),
		form_submit: ( strings.goal_conv_form_submit || 'Form Submissions' ),
		scroll_depth:( strings.goal_conv_scroll_depth || 'Scroll Conversions' ),
		time_on_page:( strings.goal_conv_time_on_page || 'Time Conversions' ),
		revenue:     ( strings.goal_conv_revenue || 'Revenue Conversions' ),
		bounce_rate: ( strings.goal_conv_bounce_rate || 'Bounce Conversions' ),
		// WooCommerce goals: without these entries both Woo tabs fell back to
		// the generic "Conversions" label, so an add-to-cart count and a
		// purchase count were indistinguishable on the results panel.
		woo_add_to_cart: ( strings.goal_conv_woo_add_to_cart || 'Add-to-Cart Conversions' ),
		woo_purchase:    ( strings.goal_conv_woo_purchase || 'Purchases' )
	};

	/**
	 * Currently active goal type for the results view.
	 */
	var activeGoalType = '';

	/**
	 * Cached goals array for the current test, used by the Goal Configuration
	 * card so tab-switch clicks can render instantly without waiting for AJAX.
	 * Keyed by numeric goal_id.
	 */
	var goalsById = {};

	/**
	 * Cached test metadata (target_post_id etc.) for the Goal Configuration card,
	 * captured on each ajax_get_results payload.
	 */
	var goalCardTestMeta = { target_post_id: 0, target_url: '', target_product_name: '' };

	/**
	 * Tracks whether the results tablist has been initialised for the first time.
	 * Used to decide if the DE tab (or primary goal tab) should be auto-activated.
	 */
	var resultsTabsInitialized = false;

	/**
	 * Client-side cache for per-goal results data.
	 * Keyed by goalId (int) or '_default' for the initial load.
	 * Eliminates redundant AJAX calls when switching between previously-viewed tabs.
	 */
	var resultsCache = {};

	/**
	 * Monotonic token for results requests.
	 *
	 * Every goal switch (AJAX or cache) increments this. An in-flight AJAX
	 * response is only rendered when its token is still the newest one, so a
	 * slow response for a goal the user has already navigated away from can
	 * never overwrite the panel — or the active tab — of the goal they are
	 * actually looking at.
	 */
	var resultsRequestToken = 0;

	function getResultsCacheKey(goalId) {
		return (goalId || '_default') + '_spam_' + getExcludeSpamFlag();
	}

	/**
	 * Resolve which goal a results payload belongs to.
	 *
	 * The initial load sends no goal_id, so the server echoes back
	 * active_goal_id = null. In that case the payload describes the primary
	 * goal, so resolve it from the goals list rather than leaving it null —
	 * otherwise the tablist cannot mark the right tab active.
	 *
	 * @param {Object} data AJAX payload.
	 * @return {number} Goal ID the payload's numbers belong to (0 if unknown).
	 */
	function resolveResultsGoalId(data) {
		var goalId = parseInt(data.active_goal_id, 10) || 0;
		if ( goalId ) {
			return goalId;
		}

		var goals = data.goals || [];
		for ( var i = 0; i < goals.length; i++ ) {
			if ( parseInt(goals[i].is_primary, 10) ) {
				return parseInt(goals[i].id, 10) || 0;
			}
		}

		return goals.length ? ( parseInt(goals[0].id, 10) || 0 ) : 0;
	}

	/**
	 * Blank every goal-specific figure currently rendered in the goal panel and
	 * relabel the conversion metric for the goal that is being loaded.
	 *
	 * Without this, the previously-selected goal's numbers stay in the DOM for
	 * the whole round-trip, so the panel reads as if the newly-activated tab
	 * had produced them (e.g. the Bounce Rate tab showing Time on Page counts
	 * labelled "Scroll Conversions").
	 *
	 * Visitor counts and test duration are goal-independent and are left alone.
	 *
	 * @param {string} goalType Goal type of the goal being loaded.
	 */
	function blankGoalMetrics(goalType) {
		var convLabel   = goalConversionLabels[goalType] || ( strings.conversions || 'Conversions' );
		var placeholder = '—';

		// Hero cards that depend on the selected goal (NOT #opti-ab-duration or
		// #opti-ab-total-impressions, which are the same for every goal).
		$('#opti-ab-best-rate, #opti-ab-sig-hero, #opti-ab-significance-pct').text(placeholder);
		$('#opti-ab-significance-hint').text( strings.loading || 'Loading…' );

		// Per-variant conversion rate / improvement / significance.
		$('#opti-ab-variant-cards')
			.find('.opti-ab-vs-card__rate, .opti-ab-variant-card__rate')
			.text(placeholder);
		$('#opti-ab-variant-cards')
			.find('.opti-ab-vs-card__baseline, .opti-ab-variant-card__improvement, .opti-ab-vs-card__sig, .opti-ab-variant-card__significance, .opti-ab-vs-divider__improvement')
			.text(placeholder);

		// Per-variant stat rows: blank every value, and relabel the conversion
		// metric (always the second stat item) for the incoming goal.
		$('#opti-ab-variant-cards')
			.find('.opti-ab-vs-card__stats, .opti-ab-variant-card__stats')
			.each(function() {
				var $items = $(this).children();
				$items.each(function(index) {
					// Index 0 is the goal-independent visitor count — keep it.
					if ( 0 === index ) {
						return;
					}
					$(this).find('strong').text(placeholder);
				});

				var $conv = $items.eq(1);
				if ( ! $conv.length ) {
					return;
				}
				$conv.contents().filter(function() {
					return 3 === this.nodeType;
				}).last().each(function() {
					this.nodeValue = convLabel;
				});
			});
	}

	function initResults() {
		var testId = parseInt($('#opti-ab-results-summary').data('test-id'), 10);
		if ( ! testId ) return;

		loadResults(testId);

		// Hidden <select> change: backward-compat for external automation scripts.
		$('#opti-ab-goal-select').on('change', function() {
			var goalId = parseInt($(this).val(), 10);
			loadResults(testId, goalId);
		});

		// Tab click handler: handles both goal tabs (built by JS) and any
		// Pro-injected tabs (e.g. Decision Engine) that are already in the DOM.
		$(document).on('click', '#opti-ab-results-tabs [role="tab"]', function() {
			var $tab   = $(this);
			var panel  = $tab.data('panel');
			var goalId = parseInt($tab.data('goal-id'), 10) || 0;

			// Update ARIA state immediately for instant visual feedback.
			activateTab($tab);

			if ( 'goal' === panel && goalId ) {
				showGoalPanel();
				// Update the Goal Configuration card instantly from cache.
				// If the goal isn't in goalsById yet (cold start), the card will
				// be populated after the pending loadResults() call resolves.
				if ( goalsById[ goalId ] ) {
					renderGoalConfigCard( goalsById[ goalId ] );
				}
				// Only update if this is a different goal than currently loaded.
				var currentGoalId = parseInt($('#opti-ab-goal-select').val(), 10) || 0;
				if ( goalId !== currentGoalId ) {
					// The clicked tab is the selection of record from now on:
					// sync it immediately so a slow response for the previous
					// goal cannot claim the panel, and so the conversion labels
					// already describe the goal being loaded.
					activeGoalType = ( goalsById[ goalId ] && goalsById[ goalId ].goal_type ) || activeGoalType;
					$('#opti-ab-goal-select').val(String(goalId));

					// Check cache first — instant switch if previously loaded.
					if ( resultsCache[getResultsCacheKey(goalId)] ) {
						renderFromCache(testId, goalId);
					} else {
						// Drop the outgoing goal's figures before the round-trip
						// so no stale number is ever attributed to this tab.
						blankGoalMetrics( activeGoalType );
						showGoalLoadingOverlay();
						loadResults(testId, goalId);
					}
				}
			} else if ( 'decision-engine' === panel ) {
				hideGoalPanel();
				// Decision Engine is not a single goal → hide the config card.
				renderGoalConfigCard( null );
			}
		});

		// Keyboard navigation — ARIA 1.1 roving tabindex pattern.
		$(document).on('keydown', '#opti-ab-results-tabs [role="tab"]', function(e) {
			handleTabKeyNav(e);
		});

		// Declare / apply winner.
		$('#opti-ab-declare-winner').on('click', function() {
			declareWinner(testId);
		});
		$('#opti-ab-apply-winner').on('click', function() {
			applyWinner(testId);
		});
		$('#opti-ab-revert-winner').on('click', function() {
			revertAppliedWinner(testId);
		});

		// WooCommerce variant info modal.
		$(document).on('click', '.opti-ab-woo-info-btn', function() {
			var $btn       = $(this);
			var vName      = $btn.data('variant-name');
			var vId        = parseInt($btn.data('variant-id'), 10);
			var tId        = parseInt($btn.data('test-id'), 10);
			var isCtrl     = $btn.data('is-control') === '1' || $btn.data('is-control') === 1;
			var vDataStr   = $btn.attr('data-variant-data') || '{}';
			var vd         = {};
			try { vd = JSON.parse(vDataStr); } catch(e) {}

			openWooVariantModal(tId, vId, vName, isCtrl, vd);
		});

		// Close modal.
		$(document).on('click', '.opti-ab-woo-modal__close, .opti-ab-woo-modal__overlay', function() {
			$('#opti-ab-woo-modal').remove();
		});
		$(document).on('keydown', function(e) {
			if ( e.key === 'Escape' ) { $('#opti-ab-woo-modal').remove(); }
		});

		// Save variant changes from modal.
		$(document).on('click', '#opti-ab-woo-modal-save', function() {
			saveWooVariantFromModal();
		});
	}

	/**
	 * Open the WooCommerce variant info / edit modal.
	 */
	function openWooVariantModal(testId, variantId, variantName, isControl, vd) {
		// Remove any existing modal.
		$('#opti-ab-woo-modal').remove();

		var readOnly = isControl;
		var title    = isControl ? escHtml( strings.woo_modal_control_title || 'Product Info — Control (Original)' ) : escHtml( strings.woo_modal_edit_title || 'Edit Variant — ' ) + escHtml(variantName);

		var html = '<div id="opti-ab-woo-modal" class="opti-ab-woo-modal">';
		html += '<div class="opti-ab-woo-modal__overlay"></div>';
		html += '<div class="opti-ab-woo-modal__dialog">';

		// Header
		html += '<div class="opti-ab-woo-modal__header">';
		html += '<h3>' + title + '</h3>';
		html += '<button type="button" class="opti-ab-woo-modal__close" aria-label="' + escAttr( strings.close || 'Close' ) + '">&times;</button>';
		html += '</div>';

		// Body
		html += '<div class="opti-ab-woo-modal__body">';

		// Image preview
		if ( vd.image_url ) {
			html += '<div class="opti-ab-woo-modal__image">';
			html += '<img src="' + escAttr(vd.image_url) + '" alt="' + escAttr( strings.woo_product_image_alt || 'Product image' ) + '">';
			html += '</div>';
		}

		// Title field
		html += '<div class="opti-ab-woo-modal__field">';
		html += '<label>' + escHtml( strings.woo_field_title || 'Title' ) + '</label>';
		html += '<input type="text" id="opti-woo-modal-title" value="' + escAttr(vd.title || '') + '"'
			+ (readOnly ? ' readonly' : '')
			+ ' placeholder="' + escAttr( strings.woo_ph_title || 'Original product title' ) + '">';
		html += '</div>';

		// Short description
		html += '<div class="opti-ab-woo-modal__field">';
		html += '<label>' + escHtml( strings.woo_field_short_desc || 'Short description' ) + '</label>';
		html += '<textarea id="opti-woo-modal-short-desc" rows="3"'
			+ (readOnly ? ' readonly' : '')
			+ ' placeholder="' + escAttr( strings.woo_ph_short_desc || 'Original short description' ) + '">'
			+ escHtml(vd.short_description || vd.description || '')
			+ '</textarea>';
		html += '</div>';

		// Pricing row
		html += '<div class="opti-ab-woo-modal__row">';
		html += '<div class="opti-ab-woo-modal__field opti-ab-woo-modal__field--half">';
		html += '<label>' + escHtml( strings.woo_field_regular_price || 'Regular price' ) + '</label>';
		html += '<input type="number" step="0.01" id="opti-woo-modal-regular-price" value="' + escAttr(vd.regular_price || '') + '"'
			+ (readOnly ? ' readonly' : '')
			+ ' placeholder="—">';
		html += '</div>';
		html += '<div class="opti-ab-woo-modal__field opti-ab-woo-modal__field--half">';
		html += '<label>' + escHtml( strings.woo_field_sale_price || 'Sale price' ) + '</label>';
		html += '<input type="number" step="0.01" id="opti-woo-modal-sale-price" value="' + escAttr(vd.sale_price || '') + '"'
			+ (readOnly ? ' readonly' : '')
			+ ' placeholder="—">';
		html += '</div>';
		html += '</div>';

		// CTA button text
		html += '<div class="opti-ab-woo-modal__field">';
		html += '<label>' + escHtml( strings.woo_field_cta || 'Button text (CTA)' ) + '</label>';
		html += '<input type="text" id="opti-woo-modal-cta" value="' + escAttr(vd.add_to_cart_text || '') + '"'
			+ (readOnly ? ' readonly' : '')
			+ ' placeholder="' + escAttr( strings.woo_ph_cta || 'Original button text' ) + '">';
		html += '</div>';

		html += '</div>'; // body

		// Footer
		html += '<div class="opti-ab-woo-modal__footer">';
		if ( ! readOnly ) {
			html += '<button type="button" id="opti-ab-woo-modal-save" class="button button-primary"'
				+ ' data-test-id="' + testId + '" data-variant-id="' + variantId + '">'
				+ escHtml( strings.woo_save_changes || 'Save Changes' ) + '</button>';
		}
		html += '<button type="button" class="button opti-ab-woo-modal__close">' + escHtml( strings.close || 'Close' ) + '</button>';
		html += '</div>';

		html += '</div>'; // dialog
		html += '</div>'; // modal

		$('body').append(html);
	}

	/**
	 * Save variant changes from the WooCommerce modal.
	 */
	function saveWooVariantFromModal() {
		var $btn      = $('#opti-ab-woo-modal-save');
		var testId    = parseInt($btn.data('test-id'), 10);
		var variantId = parseInt($btn.data('variant-id'), 10);

		var updatedData = {
			title:             $('#opti-woo-modal-title').val(),
			short_description: $('#opti-woo-modal-short-desc').val(),
			description:       $('#opti-woo-modal-short-desc').val(),
			regular_price:     $('#opti-woo-modal-regular-price').val(),
			sale_price:        $('#opti-woo-modal-sale-price').val(),
			add_to_cart_text:  $('#opti-woo-modal-cta').val()
		};

		$btn.prop('disabled', true).text(( strings.saving || 'Saving…' ));

		$.post(ajaxUrl, {
			action:       'opti_behavior_ab_update_variant_data',
			nonce:        nonce,
			test_id:      testId,
			variant_id:   variantId,
			variant_data: JSON.stringify(updatedData)
		}, function(res) {
			if ( res.success ) {
				showToast(( strings.woo_variant_updated || 'Variant updated successfully.' ), 'success');
				$('#opti-ab-woo-modal').remove();
				// Reload results to reflect changes.
				loadResults(testId);
			} else {
				showToast(res.data && res.data.message ? res.data.message : ( strings.woo_save_failed || 'Failed to save.' ), 'error');
				$btn.prop('disabled', false).text(( strings.woo_save_changes || 'Save Changes' ));
			}
		}).fail(function() {
			showToast(( strings.network_error || 'Network error.' ), 'error');
			$btn.prop('disabled', false).text(( strings.woo_save_changes || 'Save Changes' ));
		});
	}

	function loadResults(testId, goalId) {
		setExcludeSpamFlag(getExcludeSpamFlag());
		var postData = {
			action:  'opti_behavior_ab_get_results',
			nonce:   nonce,
			test_id: testId,
			exclude_spam: getExcludeSpamFlag()
		};
		if ( goalId ) {
			postData.goal_id = goalId;
		}
		$('#opti-ab-exclude-spam-toggle').off('click.optiAbExcludeSpam').on('click.optiAbExcludeSpam', function(e) {
			e.preventDefault();
			resultsCache = {};
			setExcludeSpamFlag(getExcludeSpamFlag() === '1' ? '0' : '1');
			loadResults(testId, goalId);
		});
		// Token for this request — only the newest one may render.
		var requestToken = ++resultsRequestToken;

		$.post(ajaxUrl, postData, function(res) {
			// The user switched goals while this was in flight: cache the
			// payload for later but never render it, otherwise it would
			// overwrite the panel — and the active tab — of the goal they are
			// now looking at.
			var isStale = ( requestToken !== resultsRequestToken );

			if ( ! res.success ) {
				if ( ! isStale ) {
					removeGoalLoadingOverlay();
					showToast(res.data && res.data.message ? res.data.message : strings.error, 'error');
				}
				return;
			}

			var data      = res.data;
			var goals     = data.goals || [];
			var activeGId = resolveResultsGoalId(data);

			// Store in cache for instant switching later.
			var cacheKey = getResultsCacheKey(goalId);
			resultsCache[cacheKey] = data;

			// QA-B-AB-012 / QA-B-AB-075: the first load carries every goal's
			// numbers (`goal_results`), so seed the cache for all of them. Goal
			// tabs then render from memory instead of paying a full admin-ajax
			// round trip each, which was ~8 s per tab on a 20-goal test.
			var prefetched = data.goal_results || [];
			for ( var pi = 0; pi < prefetched.length; pi++ ) {
				var prefetch = prefetched[pi];
				var prefetchGoalId = parseInt(prefetch.active_goal_id, 10) || 0;
				if ( ! prefetchGoalId ) {
					continue;
				}
				var prefetchKey = getResultsCacheKey(prefetchGoalId);
				if ( resultsCache[prefetchKey] ) {
					continue;
				}
				// `test` and `goals` are goal-independent: the server sends them
				// once, in this payload.
				prefetch.test  = data.test;
				prefetch.goals = goals;
				resultsCache[prefetchKey] = prefetch;
			}

			// On initial load (no explicit goalId), also cache under the
			// primary goal's ID so switching BACK to it is instant.
			if ( ! goalId && goals.length ) {
				for ( var ci = 0; ci < goals.length; ci++ ) {
					if ( parseInt(goals[ci].is_primary, 10) ) {
						resultsCache[ getResultsCacheKey(parseInt(goals[ci].id, 10)) ] = data;
						break;
					}
				}
				// Fallback: if no primary flag, cache under the first goal's ID.
				if ( ! resultsCache[ getResultsCacheKey(parseInt(goals[0].id, 10)) ] ) {
					resultsCache[ getResultsCacheKey(parseInt(goals[0].id, 10)) ] = data;
				}
			}

			if ( isStale ) {
				return;
			}

			populateGoalSelector(goals, activeGId);
			renderResults(data);
			removeGoalLoadingOverlay();

			// Expose shared state so the Pro plugin can read it without re-fetching.
			window.OptiABResultsState = {
				testId:       testId,
				goals:        goals,
				results:      data.results || [],
				activeGoalId: activeGId,
				excludeSpam:  getExcludeSpamFlag()
			};

			// Notify Pro plugins that a results render cycle completed.
			document.dispatchEvent(
				new CustomEvent( 'opti_ab_results_loaded', { detail: window.OptiABResultsState } )
			);
		}).fail(function() {
			if ( requestToken !== resultsRequestToken ) {
				return;
			}
			removeGoalLoadingOverlay();
			showToast(strings.error, 'error');
		});
	}

	/**
	 * Render results instantly from the client-side cache.
	 * Bypasses the AJAX call entirely for previously-loaded goals.
	 *
	 * @param {number} testId Test ID.
	 * @param {number} goalId Goal ID to render from cache.
	 */
	function renderFromCache(testId, goalId) {
		var data  = resultsCache[getResultsCacheKey(goalId)];
		var goals = data.goals || [];

		// Rendering from cache supersedes any in-flight request.
		resultsRequestToken++;
		removeGoalLoadingOverlay();

		// Prefer the goal the caller asked for: the payload cached on the
		// initial page load carries active_goal_id = null (no goal_id was
		// sent), which would otherwise leave the tablist without an active tab.
		var activeGId = parseInt(goalId, 10) || resolveResultsGoalId(data);

		populateGoalSelector(goals, activeGId);
		renderResults(data);

		// Update shared state — same contract as the fresh-AJAX path.
		window.OptiABResultsState = {
			testId:       testId,
			goals:        goals,
			results:      data.results || [],
			activeGoalId: activeGId,
			excludeSpam:  getExcludeSpamFlag()
		};

		document.dispatchEvent(
			new CustomEvent( 'opti_ab_results_loaded', { detail: window.OptiABResultsState } )
		);
	}

	/**
	 * Sync the hidden <select>, render goal tab buttons in the tablist, and
	 * set initial panel state (only on the first call per page load).
	 *
	 * The legacy <select>#opti-ab-goal-select remains in the DOM (display:none)
	 * as an accessibility / automation fallback.
	 *
	 * @param {Array}  goals        Goal objects from the AJAX response.
	 * @param {number} activeGoalId ID of the currently-active goal.
	 */
	function populateGoalSelector(goals, activeGoalId) {
		var $select = $('#opti-ab-goal-select');

		// ── 0. Refresh the module-level goalsById cache so tab clicks render ───
		//     the Goal Configuration card without waiting for another AJAX call.
		goalsById = {};
		for ( var gi = 0; gi < goals.length; gi++ ) {
			if ( goals[gi] && goals[gi].id ) {
				goalsById[ parseInt(goals[gi].id, 10) ] = goals[gi];
			}
		}

		// ── 1. Sync hidden <select> for backward-compat automation scripts ──────
		$select.empty();
		for ( var i = 0; i < goals.length; i++ ) {
			var g     = goals[i];
			var label = g.name || goalTypeLabels[g.goal_type] || g.goal_type.replace(/_/g, ' ');
			if ( parseInt(g.is_primary, 10) ) {
				label += ' (Primary)';
			}
			var optActive = activeGoalId
				? parseInt(g.id, 10) === parseInt(activeGoalId, 10)
				: !! parseInt(g.is_primary, 10);
			$select.append(
				'<option value="' + escHtml(g.id) + '"' + ( optActive ? ' selected' : '' ) + '>'
				+ escHtml(label) + '</option>'
			);
		}

		// ── 2. Rebuild goal tab buttons (keep any Pro-injected tabs untouched) ──
		// Pro-injected tabs have data-panel but no data-goal-id; only remove ours.
		// A non-goal tab (e.g. Decision Engine) that the user activated while
		// this payload was in flight must keep the selection — rebuilding the
		// goal tabs below would otherwise silently steal it back.
		var nonGoalTabActive = $('#opti-ab-results-tabs [role="tab"].opti-ab-tab--active').not('[data-goal-id]').length > 0;

		$('#opti-ab-results-tabs [data-goal-id]').remove();

		for ( var j = 0; j < goals.length; j++ ) {
			var goal      = goals[j];
			var tabLabel  = goal.name || goalTypeLabels[goal.goal_type] || goal.goal_type.replace(/_/g, ' ');
			var isPrimary = !! parseInt(goal.is_primary, 10);
			var tabActive = ! nonGoalTabActive && (
				activeGoalId
					? parseInt(goal.id, 10) === parseInt(activeGoalId, 10)
					: isPrimary
			);

			var $tab = $('<button></button>')
				.attr({
					'role':          'tab',
					'class':         'opti-ab-tab' + ( tabActive ? ' opti-ab-tab--active' : '' ),
					'data-panel':    'goal',
					'data-goal-id':  goal.id,
					'aria-selected': tabActive ? 'true' : 'false',
					'tabindex':      tabActive ? '0' : '-1'
				});

			$tab.append( $('<span></span>').text(tabLabel) );

			if ( isPrimary ) {
				$tab.append(
					$('<span></span>')
						.addClass('opti-ab-tab__primary-badge')
						.attr('aria-label', ( strings.goal_primary_suffix || ' (Primary)' ))
						.text(( strings.primary || 'Primary' ))
				);
			}

			$('#opti-ab-results-tabs').append($tab);
		}

		// ── 3. First load only: determine initial active tab & panel visibility ─
		if ( ! resultsTabsInitialized ) {
			resultsTabsInitialized = true;

			// Check if Pro has pre-rendered a default-active tab (Decision Engine).
			var $deDefaultTab = $( '#opti-ab-results-tabs [data-panel="decision-engine"][data-default-active="1"]' );
			if ( $deDefaultTab.length ) {
				// DE tab is default: deactivate all goal tabs, activate DE tab.
				$('#opti-ab-results-tabs [data-goal-id]')
					.removeClass('opti-ab-tab--active')
					.attr('aria-selected', 'false')
					.attr('tabindex', '-1');
				activateTab($deDefaultTab);
				hideGoalPanel();
			} else {
				// No Pro DE tab: goal panel is default, already visible from PHP.
				showGoalPanel();
			}
		}
		// On subsequent loads (user switched goal tabs) the click handler already
		// set the correct panel visibility — do not override it here.

		// ── 4. Update legacy goal-type badge inside the hidden selector ─────────
		var selectedId = parseInt($select.val(), 10);
		for ( var k = 0; k < goals.length; k++ ) {
			if ( parseInt(goals[k].id, 10) === selectedId ) {
				activeGoalType = goals[k].goal_type || 'page_visit';
				updateGoalTypeDisplay(goals[k]);
				// ── 5. Refresh the Goal Configuration card for the current tab ─
				// Hidden automatically when the Decision Engine tab is the default.
				var $activeTab = $('#opti-ab-results-tabs [role="tab"].opti-ab-tab--active');
				if ( $activeTab.data('panel') === 'decision-engine' ) {
					renderGoalConfigCard(null);
				} else {
					renderGoalConfigCard(goals[k]);
				}
				break;
			}
		}
	}

	// ── Tab management helpers ────────────────────────────────────────────────

	/**
	 * Activate a single tab in the tablist (updates ARIA attributes and CSS class).
	 * All other tabs in the same tablist are deactivated.
	 *
	 * @param {jQuery} $tab The tab button element to activate.
	 */
	function activateTab($tab) {
		$('#opti-ab-results-tabs [role="tab"]').each(function() {
			$(this).removeClass('opti-ab-tab--active')
			       .attr('aria-selected', 'false')
			       .attr('tabindex', '-1');
		});
		$tab.addClass('opti-ab-tab--active')
		    .attr('aria-selected', 'true')
		    .attr('tabindex', '0');
	}

	/**
	 * Show the goal results tabpanel and hide the decision-engine panel if present.
	 */
	function showGoalPanel() {
		$('#opti-ab-goal-panel').removeAttr('hidden');
		var $de = $('#opti-ab-decision-engine-panel');
		if ( $de.length ) {
			$de.attr('hidden', '');
		}
		// Re-equalize after the panel becomes visible (elements have real offsets).
		equalizeCardBodyHeights();
	}

	/**
	 * Hide the goal results tabpanel and show the decision-engine panel.
	 */
	function hideGoalPanel() {
		$('#opti-ab-goal-panel').attr('hidden', '');
		var $de = $('#opti-ab-decision-engine-panel');
		if ( $de.length ) {
			$de.removeAttr('hidden');
		}
	}

	/**
	 * Show a translucent loading overlay on the goal panel.
	 * Gives the user visual feedback that fresh data is being loaded.
	 */
	function showGoalLoadingOverlay() {
		var $panel = $('#opti-ab-goal-panel');
		if ( $panel.find('.opti-ab-goal-loading-overlay').length ) {
			return; // Already showing.
		}
		$panel.attr('aria-busy', 'true');
		$panel.append(
			'<div class="opti-ab-goal-loading-overlay">' +
				'<div class="opti-ab-goal-loading-overlay__inner">' +
					'<div class="opti-ab-spinner"></div>' +
					'<span>' + escHtml( strings.loading || 'Loading…' ) + '</span>' +
				'</div>' +
			'</div>'
		);
	}

	/**
	 * Remove the loading overlay from the goal panel.
	 */
	function removeGoalLoadingOverlay() {
		$('#opti-ab-goal-panel').removeAttr('aria-busy');
		$('#opti-ab-goal-panel .opti-ab-goal-loading-overlay').remove();
	}

	/**
	 * Handle Arrow-Left/Right, Home, End keyboard navigation on the results tablist.
	 * Implements ARIA 1.1 roving tabindex pattern.
	 *
	 * @param {jQuery.Event} e Keydown event originating from a [role="tab"] element.
	 */
	function handleTabKeyNav(e) {
		var key   = e.key || e.keyCode;
		var $tabs = $('#opti-ab-results-tabs [role="tab"]');
		var idx   = $tabs.index(e.currentTarget);
		var next  = -1;

		if ( 'ArrowRight' === key || 39 === key ) {
			next = ( idx + 1 ) % $tabs.length;
		} else if ( 'ArrowLeft' === key || 37 === key ) {
			next = ( idx - 1 + $tabs.length ) % $tabs.length;
		} else if ( 'Home' === key || 36 === key ) {
			next = 0;
		} else if ( 'End' === key || 35 === key ) {
			next = $tabs.length - 1;
		}

		if ( -1 !== next ) {
			e.preventDefault();
			$tabs.eq(next).trigger('click').focus();
		}
	}

	/**
	 * Update the goal type badge and configuration info display.
	 */
	function updateGoalTypeDisplay(goal) {
		var $badge  = $('#opti-ab-goal-type-badge');
		var $config = $('#opti-ab-goal-config-info');

		var typeLabel = goalTypeLabels[goal.goal_type] || goal.goal_type;
		$badge.text(typeLabel).show();

		// Show goal-specific configuration details.
		var configHtml = '';
		var config = {};
		if ( goal.config ) {
			try {
				config = typeof goal.config === 'string' ? JSON.parse(goal.config) : goal.config;
			} catch(e) {
				config = {};
			}
		}

		switch ( goal.goal_type ) {
			case 'page_visit':
				if ( config.url ) {
					configHtml = '<span class="opti-ab-goal-config-label">' + escHtml( strings.gc_url_label || 'URL:' ) + '</span> <span class="opti-ab-goal-config-value">' + escHtml(config.url) + '</span>';
				}
				break;
			case 'click':
				if ( config.selector ) {
					configHtml = '<span class="opti-ab-goal-config-label">' + escHtml( strings.gc_selector_label || 'Selector:' ) + '</span> <code class="opti-ab-goal-config-value">' + escHtml(config.selector) + '</code>';
				}
				break;
			case 'form_submit':
				if ( config.selector ) {
					configHtml = '<span class="opti-ab-goal-config-label">' + escHtml( strings.gc_form_label || 'Form:' ) + '</span> <code class="opti-ab-goal-config-value">' + escHtml(config.selector) + '</code>';
				}
				break;
			case 'scroll_depth':
				if ( config.depth ) {
					configHtml = '<span class="opti-ab-goal-config-label">' + escHtml( strings.gc_depth_label || 'Depth:' ) + '</span> <span class="opti-ab-goal-config-value">' + escHtml(config.depth) + '%</span>';
				}
				break;
			case 'time_on_page':
				if ( config.seconds ) {
					configHtml = '<span class="opti-ab-goal-config-label">' + escHtml( strings.gc_time_label || 'Time:' ) + '</span> <span class="opti-ab-goal-config-value">' + escHtml(config.seconds) + 's</span>';
				}
				break;
			case 'revenue':
				if ( config.threshold ) {
					configHtml = '<span class="opti-ab-goal-config-label">' + escHtml( strings.gc_threshold_label || 'Threshold:' ) + '</span> <span class="opti-ab-goal-config-value">$' + escHtml(config.threshold) + '</span>';
				}
				break;
			case 'bounce_rate':
				if ( config.threshold ) {
					configHtml = '<span class="opti-ab-goal-config-label">' + escHtml( strings.gc_threshold_label || 'Threshold:' ) + '</span> <span class="opti-ab-goal-config-value">' + escHtml(config.threshold) + 's</span>';
				}
				break;
		}

		$config.html(configHtml);
	}

	/**
	 * Safely parse a JSON string and return an object, or empty object on error.
	 *
	 * @param {string|object} raw JSON string or already-parsed object.
	 * @return {object} Parsed object.
	 */
	function parseGoalConfig(raw) {
		if ( ! raw ) return {};
		if ( typeof raw === 'object' ) return raw;
		try { return JSON.parse(raw) || {}; } catch(e) { return {}; }
	}

	/**
	 * Build a labelled row for the Goal Configuration card body.
	 *
	 * @param {string}  label     Short label (caps), e.g. "URL", "Selector".
	 * @param {string}  valueHtml Pre-escaped or safe HTML for the value.
	 * @param {boolean} stacked   If true, label is above value (for long values).
	 * @return {string} HTML string.
	 */
	function goalCardRow(label, valueHtml, stacked) {
		var cls = 'opti-ab-goal-config-card__row' + ( stacked ? ' opti-ab-goal-config-card__row--stacked' : '' );
		return '<div class="' + cls + '"><dt>' + escHtml(label) + '</dt><dd>' + valueHtml + '</dd></div>';
	}

	/**
	 * Render (or hide) the Per-Tab Goal Configuration card that fills the
	 * empty space inside the Statistical Significance widget.
	 *
	 * Called on: initial results load, tab click, goal <select> change, and
	 * when the Decision Engine tab is activated (to hide the card).
	 *
	 * @param {object|null} goal Goal object { id, name, goal_type, goal_config, is_primary }
	 *                           or null to hide the card.
	 */
	function renderGoalConfigCard(goal) {
		var $card = $('#opti-ab-goal-config-card');
		if ( ! $card.length ) return;

		if ( ! goal || ! goal.goal_type ) {
			$card.attr('hidden', 'hidden');
			return;
		}

		var $name  = $('#opti-ab-goal-config-card-name');
		var $badge = $('#opti-ab-goal-config-card-badge');
		var $body  = $('#opti-ab-goal-config-card-body');

		var typeLabel = goalTypeLabels[goal.goal_type] || goal.goal_type.replace(/_/g, ' ');
		var name      = goal.name || typeLabel;
		var config    = parseGoalConfig(goal.goal_config);
		var isPrimary = !! parseInt(goal.is_primary, 10);

		$name.text(name);

		if ( isPrimary ) {
			$badge.text(( strings.primary || 'Primary' )).removeAttr('hidden').addClass('opti-ab-goal-config-card__badge--primary');
		} else {
			$badge.attr('hidden', 'hidden').removeClass('opti-ab-goal-config-card__badge--primary');
		}

		// ── Type-specific rows ────────────────────────────────────────────
		var rows = '';
		rows += goalCardRow(( strings.gcc_type || 'Type' ), '<span class="opti-ab-goal-config-card__chip">' + escHtml(typeLabel) + '</span>');

		switch ( goal.goal_type ) {
			case 'page_visit':
				if ( config.url ) {
					var safeUrl = escAttr(config.url);
					var human   = escHtml( config.url.replace(/^https?:\/\//, '').replace(/\/$/, '') );
					rows += goalCardRow(( strings.gcc_url || 'URL' ), '<a href="' + safeUrl + '" target="_blank" rel="noopener">' + human + '</a>', true);
				} else {
					rows += goalCardRow(( strings.gcc_url || 'URL' ), '<em>' + escHtml( strings.gcc_any_page || 'Any page' ) + '</em>');
				}
				break;

			case 'click':
				// Supports both { selector: "foo" } and { selectors: [...] }.
				var selectors = [];
				if ( Array.isArray(config.selectors) ) {
					selectors = config.selectors.filter(function(s) { return !!s; });
				} else if ( config.selector ) {
					selectors = [ config.selector ];
				}
				if ( selectors.length ) {
					var chipsHtml = '<ul class="opti-ab-goal-config-card__list">';
					for ( var si = 0; si < selectors.length; si++ ) {
						chipsHtml += '<li><code class="opti-ab-goal-config-card__code">' + escHtml(selectors[si]) + '</code></li>';
					}
					chipsHtml += '</ul>';
					var rowLabel = selectors.length > 1 ? ( strings.gcc_selectors || 'Selectors' ) : ( strings.gcc_selector || 'Selector' );
					rows += goalCardRow(rowLabel, chipsHtml, true);
				} else {
					rows += goalCardRow(( strings.gcc_selector || 'Selector' ), '<em>' + escHtml( strings.gcc_any_click || 'Any click' ) + '</em>');
				}
				break;

			case 'form_submit':
				rows += goalCardRow(( strings.gcc_form || 'Form' ), '<code class="opti-ab-goal-config-card__code">' + escHtml(config.selector || 'form') + '</code>', true);
				break;

			case 'scroll_depth':
				rows += goalCardRow(( strings.gcc_depth || 'Depth' ), '<strong>' + escHtml(config.depth || 75) + '</strong>' + escHtml( strings.gcc_depth_value || '% of the page' ));
				break;

			case 'time_on_page':
				rows += goalCardRow(( strings.gcc_time || 'Time' ), '<strong>' + escHtml(config.seconds || 30) + '</strong> ' + escHtml( strings.gcc_time_value || 'seconds on page' ));
				break;

			case 'revenue':
				if ( config.threshold ) {
					rows += goalCardRow(( strings.gcc_threshold || 'Threshold' ), '<strong>$' + escHtml(config.threshold) + '</strong>');
				}
				if ( config.track_revenue ) {
					rows += goalCardRow(( strings.gcc_source || 'Source' ), escHtml( strings.gcc_source_value || 'All WooCommerce orders attributed to this test' ));
				}
				break;

			case 'bounce_rate':
				var secs = config.threshold_seconds || config.threshold || 10;
				rows += goalCardRow(( strings.gcc_threshold || 'Threshold' ), '<strong>' + escHtml(secs) + '</strong> ' + escHtml( strings.gcc_seconds || 'seconds' ));
				if ( config.inverse ) {
					rows += goalCardRow(( strings.gcc_mode || 'Mode' ), '<span class="opti-ab-goal-config-card__chip">' + escHtml( strings.gcc_inverse || 'Inverse' ) + '</span> ' + escHtml( strings.gcc_inverse_desc || '(session longer than threshold = conversion)' ));
				}
				break;

			case 'woo_add_to_cart':
			case 'woo_purchase':
				var productName = goalCardTestMeta.target_product_name || ( strings.gcc_test_product || 'Test product' );
				rows += goalCardRow(( strings.gcc_product || 'Product' ), escHtml(productName));
				if ( goalCardTestMeta.target_url ) {
					rows += goalCardRow(( strings.gcc_url || 'URL' ), '<a href="' + escAttr(goalCardTestMeta.target_url) + '" target="_blank" rel="noopener">' + escHtml( goalCardTestMeta.target_url.replace(/^https?:\/\//, '').replace(/\/$/, '') ) + '</a>', true);
				}
				break;
		}

		// Friendly hint so users recall the goal's intent.
		if ( goalTypeHints[goal.goal_type] ) {
			rows += '<div class="opti-ab-goal-config-card__hint">' + escHtml(goalTypeHints[goal.goal_type]) + '</div>';
		}

		$body.html(rows);
		$card.removeAttr('hidden');

		// Re-render Lucide icons that may have been inserted.
		if ( window.lucide && typeof window.lucide.createIcons === 'function' ) {
			window.lucide.createIcons();
		}
	}

	/**
	 * Build a compact "Variant Info" button for WooCommerce tests on the
	 * results page. Clicking opens a modal with the variant's product details.
	 *
	 * @param {object} v    Variant result object.
	 * @param {object} test Test object.
	 * @return {string} HTML string.
	 */
	function buildWooVariantInfoCard(v, test) {
		var vd = {};
		try { vd = JSON.parse(v.variant_data || '{}'); } catch(e) {}
		var isControl = !!v.is_control;
		var variantId = parseInt(v.variant_id, 10) || 0;
		var testId    = parseInt(test.id, 10) || 0;

		var html = '<div class="opti-ab-woo-info-card">';
		html += '<button type="button" class="button opti-ab-woo-info-btn" '
			+ 'data-test-id="' + testId + '" '
			+ 'data-variant-id="' + variantId + '" '
			+ 'data-variant-name="' + escAttr(v.variant_name) + '" '
			+ 'data-is-control="' + (isControl ? '1' : '0') + '" '
			+ 'data-variant-data="' + escAttr(v.variant_data || '{}') + '">'
			+ '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			+ '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg> '
			+ (isControl ? escHtml( strings.woo_view_product_info || 'View Product Info' ) : escHtml( strings.woo_view_edit_variant || 'View / Edit Variant' ))
			+ '</button>';

		// Show a quick summary of what's changed.
		if ( ! isControl ) {
			var changes = [];
			if ( vd.title )             changes.push(( strings.woo_change_title || 'Title' ));
			if ( vd.short_description ) changes.push(( strings.woo_change_description || 'Description' ));
			if ( vd.regular_price )     changes.push(( strings.woo_change_price || 'Price' ));
			if ( vd.sale_price )        changes.push(( strings.woo_change_sale_price || 'Sale price' ));
			if ( vd.add_to_cart_text )  changes.push(( strings.woo_change_cta || 'CTA' ));
			if ( vd.image_id || (vd.image_url && vd.image_url !== test.control_image_url) ) changes.push(( strings.woo_change_image || 'Image' ));
			if ( changes.length ) {
				html += '<span class="opti-ab-woo-info-changes">'
					+ '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838.838-2.872a2 2 0 0 1 .506-.855z"/></svg> '
					+ changes.join(', ')
					+ '</span>';
			}
		}

		html += '</div>';
		return html;
	}

	/**
	 * Build visual-changes card HTML for a variant on the results page.
	 * Shows a preview thumbnail + change list for element-type tests.
	 *
	 * @param {object} v          Variant result object (must have variant_data, is_control, variant_id).
	 * @param {object} test       Test object (must have test_type, target_url, id).
	 * @return {string} HTML string (empty if not an element-type test).
	 */
	function buildResultsVisualCard(v, test) {
		// WooCommerce tests: show variant info button (opens modal via Pro JS hook).
		if ( test.test_type === 'woocommerce' ) {
			return buildWooVariantInfoCard(v, test);
		}

		if ( test.test_type !== 'element' ) {
			return '';
		}

		var isControl = !!v.is_control;
		var variantData = {};
		try { variantData = JSON.parse(v.variant_data || '{}'); } catch(e) {}
		var changes = ( variantData && variantData.changes ) || [];

		var html = '<div class="ob-res-vcard">';

		// LEFT: preview thumbnail
		var targetUrl = test.target_url || '';
		if ( ! targetUrl && test.target_post_id ) {
			var homeUrl = ( window.optiBehaviorABAdmin && window.optiBehaviorABAdmin.home_url ) || '';
			targetUrl = homeUrl ? homeUrl + '/?p=' + test.target_post_id : '';
		}
		var variantId = parseInt(v.variant_id, 10) || 0;
		var testId = parseInt(test.id, 10) || 0;
		var hasPreview = !!targetUrl && testId && ( isControl || ( variantId && changes.length > 0 ) );

		html += '<div class="ob-res-vcard__left' + ( hasPreview ? '' : ' ob-res-vcard__left--empty' ) + '">';
		if ( hasPreview ) {
			var pUrl;
			// opti_ab_admin_preview=1 keeps these results-page thumbnails out of
			// the test's own data. The control thumbnail in particular is a plain
			// frontend load: unmarked, it bucketed the admin, recorded an
			// impression and — since the card stays on screen — fired the
			// time_on_page / bounce_rate goals, so merely opening the results page
			// changed the results being shown.
			if ( isControl ) {
				pUrl = targetUrl + ( targetUrl.indexOf('?') === -1 ? '?' : '&' ) +
					'opti_ab_admin_preview=1&opti_ab_cache_bust=' + Date.now();
			} else {
				pUrl = targetUrl + ( targetUrl.indexOf('?') === -1 ? '?' : '&' ) +
					'opti_ab_preview_test=' + testId +
					'&opti_ab_preview_variant=' + variantId +
					'&opti_ab_admin_preview=1' +
					'&opti_ab_cache_bust=' + Date.now();
			}
			html += '<div class="ob-res-vcard__loader"><div class="ob-vcard__spinner"></div></div>';
			html += '<iframe class="ob-res-vcard__iframe" src="' + escAttr(pUrl) + '" ' +
				'sandbox="allow-same-origin allow-scripts" loading="lazy" tabindex="-1" ' +
				'title="' + escAttr(isControl ? ( strings.preview_original_page || 'Original page' ) : v.variant_name) + '" ' +
				'onload="if(this.previousElementSibling)this.previousElementSibling.style.display=\'none\'"></iframe>';
		} else {
			html += '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>';
			html += '<span>' + escHtml( strings.ve_no_preview || 'No preview' ) + '</span>';
		}
		html += '</div>';

		// RIGHT: controls + changes
		html += '<div class="ob-res-vcard__right">';

		if ( isControl ) {
			// Control: simple info
			html += '<div class="ob-res-vcard__toolbar">';
			html += '<div class="ob-res-vcard__control-info">';
			html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>';
			html += '<span>' + escHtml( strings.ve_original_no_mods || 'Original page — no modifications' ) + '</span>';
			html += '</div></div>';
			html += '<div class="ob-res-vcard__control-desc"><p>' + escHtml( strings.ve_control_desc_short || 'Visitors in this group see the unmodified page.' ) + '</p></div>';
		} else {
			// Variant: Open Visual Editor button + changes list
			var veUrl = ( window.optiBehaviorABAdmin && window.optiBehaviorABAdmin.visual_editor_url ) || '';
			html += '<div class="ob-res-vcard__toolbar">';
			if ( veUrl && testId && variantId ) {
				var editorHref = buildVisualEditorHref( veUrl, testId, variantId );
				html += '<a href="' + escAttr(editorHref) + '" class="button ob-res-vcard__ve-btn" target="_blank" rel="noopener noreferrer">';
				html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
				html += '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>';
				html += ' ' + escHtml( strings.ve_open_editor || 'Open Visual Editor' ) + '</a>';
			}
			html += '</div>';

			// Changes list
			if ( changes.length > 0 ) {
				html += '<div class="ob-res-vcard__changelist">';
				for ( var ci = 0; ci < changes.length; ci++ ) {
					var ch = changes[ci], chIcon, chClass, chLabel;
					if ( ch.insert_after ) {
						chIcon = '+'; chClass = 'insert';
						chLabel = ( ch.block_name || ( strings.vc_block || 'Block' ) ) + ' <span class="ob-vcard__sel">' + ( strings.vc_after || 'after' ) + ' ' + escAttr(ch.selector || ( strings.vc_page || 'page' )) + '</span>';
					} else if ( ch.duplicate ) {
						chIcon = '⧉'; chClass = 'other';
						chLabel = ( strings.vc_duplicate || 'Duplicate' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
					} else if ( ch.hide ) {
						chIcon = '−'; chClass = 'hide';
						chLabel = ( strings.vc_hide || 'Hide' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
					} else if ( typeof ch.move_delta !== 'undefined' ) {
						chIcon = '↕'; chClass = 'other';
						chLabel = ( strings.vc_move || 'Move' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
					} else if ( ch.css || ch.design ) {
						chIcon = '◆'; chClass = 'style';
						chLabel = ( strings.vc_style || 'Style' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
					} else if ( ch.text || ch.html ) {
						chIcon = '✎'; chClass = 'other';
						chLabel = ( strings.vc_edit || 'Edit' ) + ' <span class="ob-vcard__sel">' + escAttr(ch.selector) + '</span>';
					} else {
						chIcon = '•'; chClass = 'other';
						chLabel = '<span class="ob-vcard__sel">' + escAttr(ch.selector || ( strings.vc_unknown || 'Unknown' )) + '</span>';
					}
					html += '<div class="ob-vcard__ch ob-vcard__ch--' + chClass + '"><span class="ob-vcard__ch-icon">' + chIcon + '</span><span class="ob-vcard__ch-text">' + chLabel + '</span></div>';
				}
				html += '</div>';
			} else {
				html += '<div class="ob-res-vcard__control-desc"><p>' + escHtml( strings.ve_no_visual_changes || 'No visual changes configured yet.' ) + '</p></div>';
			}
		}

		html += '</div>'; // right
		html += '</div>'; // ob-res-vcard
		return html;
	}

	function renderResults(data) {
		var results = data.results || [];
		var test = data.test || {};

		// Capture test metadata for the Goal Configuration card so woo_add_to_cart
		// and woo_purchase goals can show the product name + URL.
		goalCardTestMeta = {
			target_post_id:      parseInt(test.target_post_id, 10) || 0,
			target_url:          test.target_url || '',
			target_product_name: test.target_product_name || test.name || ''
		};

		// Duration — DB column is 'ended_at' (not 'completed_at').
		if ( test.started_at ) {
			var start = new Date(test.started_at);
			var now   = test.ended_at ? new Date(test.ended_at) : new Date();
			var days  = Math.ceil((now - start) / (1000 * 60 * 60 * 24));
			animateCountUp(document.getElementById('opti-ab-duration'), days, ' day' + (days !== 1 ? 's' : ''), 800);
		}

		// Total visitors — engine returns 'visitors' (not 'impressions').
		var totalImp = 0;
		results.forEach(function(v) { totalImp += parseInt(v.visitors, 10) || 0; });
		animateCountUp(document.getElementById('opti-ab-total-impressions'), totalImp, '', 800);

		// Best conversion rate — hero card.
		var bestRate = 0;
		results.forEach(function(v) {
			var r = parseFloat(v.conversion_rate) || 0;
			if ( r * 100 > bestRate ) { bestRate = r * 100; }
		});
		animateCountUp(document.getElementById('opti-ab-best-rate'), bestRate, '%', 800);

		// Inject target page URL into summary if not already rendered by PHP.
		if ( ! document.getElementById('opti-ab-target-page-meta') ) {
			var targetUrl = test.target_url || '';
			// Note: do NOT fall back to variant redirect_url — that is a variant
			// page, not the target page being optimized. The target_url should
			// always be saved on the test record itself.
			if ( targetUrl ) {
				var shortUrl = targetUrl.replace(/^https?:\/\//, '').replace(/\/+$/, '');
				var metaHtml = '<div class="opti-ab-target-pill" id="opti-ab-target-page-meta">';
				metaHtml += '<i data-lucide="link"></i>';
				metaHtml += '<a href="' + escHtml(targetUrl) + '" target="_blank" rel="noopener">' + escHtml(shortUrl) + '</a>';
				metaHtml += '</div>';
				$('#opti-ab-results-summary').after(metaHtml);
			}
		}

		// Significance progress — show actual confidence level, not composite.
		var sigData = data.significance_progress || {};
		var sigBarPct = 0;
		var sigConfidence = 0;
		var sigTarget = 95;
		var sigIsSignificant = false;

		if ( typeof sigData === 'object' ) {
			sigBarPct        = parseFloat(sigData.bar_pct) || 0;
			sigConfidence    = parseFloat(sigData.confidence) || 0;
			sigTarget        = parseFloat(sigData.target) || 95;
			sigIsSignificant = !!sigData.is_significant;
		} else {
			// Backward compat: old format was just a number.
			sigBarPct = parseInt(sigData, 10) || 0;
			sigConfidence = sigBarPct;
		}

		// Hero confidence card.
		$('#opti-ab-sig-hero').text(sigConfidence.toFixed(1) + '%');

		// Radial gauge: update pct label and SVG arc stroke-dashoffset.
		$('#opti-ab-significance-pct').text(sigConfidence.toFixed(1) + '%');
		var arc = document.getElementById('opti-ab-significance-fill');
		if ( arc ) {
			var circumference = 515;
			var offset = circumference * (1 - Math.min(sigBarPct, 100) / 100);
			arc.style.strokeDashoffset = offset;
			arc.style.stroke = sigBarPct >= 95 ? '#10b981'
			                 : sigBarPct >= 66 ? '#6366f1'
			                 : sigBarPct >= 33 ? '#f59e0b'
			                 : '#ef4444';
		}

		// Update hint text with context.
		if ( sigIsSignificant ) {
			$('#opti-ab-significance-hint').text(( strings.sig_reached || 'Statistical significance reached! The result is reliable.' )).show();
		} else if ( sigConfidence > 0 ) {
			var remaining = (sigTarget - sigConfidence).toFixed(1);
			$('#opti-ab-significance-hint').text(( strings.sig_progress || 'Current confidence: %1$s%. Need %2$s more percentage points to reach %3$s% significance threshold.' ).replace( '%1$s', sigConfidence.toFixed(1) ).replace( '%2$s', remaining ).replace( '%3$s', sigTarget.toFixed(0) )).show();
		} else {
			$('#opti-ab-significance-hint').text(( strings.sig_collecting || 'Collecting data — more visitors are needed to reach statistical significance.' )).show();
		}

		var sigPct = sigBarPct; // keep variable for winner-actions check below

		// Variant cards.
		var cardsHtml = '';
		var controlRate = null;

		// Determine the winner_variant_id from the test record.
		var winnerVariantId = test.winner_variant_id ? parseInt(test.winner_variant_id, 10) : 0;

		// Find control rate — engine returns boolean is_control.
		results.forEach(function(v) {
			if ( v.is_control ) {
				controlRate = parseFloat(v.conversion_rate) || 0;
			}
		});

		if ( results.length === 2 ) {
			// VS side-by-side layout for two-variant tests.
			var vsCards           = [];
			var vsImprovementHtml = '';

			// Sort so the control variant is always on the left.
			var sortedVsResults = results.slice().sort(function(a, b) {
				return (b.is_control ? 1 : 0) - (a.is_control ? 1 : 0);
			});

			sortedVsResults.forEach(function(v) {
				var isControl   = !!v.is_control;
				var isWinner    = winnerVariantId && parseInt(v.variant_id, 10) === winnerVariantId;
				var rate        = parseFloat(v.conversion_rate) || 0;
				var visitors    = parseInt(v.visitors, 10) || 0;
				var conv        = parseInt(v.conversions, 10) || 0;
				var improvement = parseFloat(v.improvement) || 0;
				var ci_lower    = (v.ci_lower !== null && v.ci_lower !== undefined) ? (parseFloat(v.ci_lower) * 100).toFixed(2) : '—';
				var ci_upper    = (v.ci_upper !== null && v.ci_upper !== undefined) ? (parseFloat(v.ci_upper) * 100).toFixed(2) : '—';
				var sigText     = v.significance_text || '';

				var cardClass = 'opti-ab-vs-card';
				if ( isControl ) { cardClass += ' opti-ab-vs-card--control'; }
				if ( isWinner )  { cardClass += ' opti-ab-vs-card--winner'; }

				var cardHtml = '<div class="' + cardClass + '">';

				// Crown badge for winner.
				if ( isWinner ) {
					cardHtml += '<div class="opti-ab-vs-card__crown">';
					cardHtml += '<i data-lucide="crown"></i>';
					cardHtml += '</div>';
				}

				cardHtml += '<div class="opti-ab-vs-card__label">' + escHtml(isControl ? ( strings.variant_badge_control || 'Control' ) : ( strings.variant_badge_variant || 'Variant' )) + '</div>';
				cardHtml += '<div class="opti-ab-vs-card__name">' + escHtml(v.variant_name) + '</div>';
				cardHtml += '<div class="opti-ab-vs-card__rate">' + (rate * 100).toFixed(2) + '%</div>';

				if ( isControl ) {
					cardHtml += '<div class="opti-ab-vs-card__baseline">' + escHtml( strings.baseline || 'Baseline' ) + '</div>';
				} else {
					// Improvement vs control — shown in card subtitle and VS divider.
					if ( controlRate === 0 ) {
						var absDiff = (rate * 100).toFixed(1);
						if ( rate > 0 ) {
							cardHtml += '<div class="opti-ab-vs-card__baseline" style="color:#059669;">+' + absDiff + ( strings.pp_vs_control_suffix || ' pp vs control' ) + '</div>';
							vsImprovementHtml = '<div class="opti-ab-vs-divider__improvement opti-ab-vs-divider__improvement--positive">&#9650; +' + absDiff + ( strings.pp_suffix || ' pp' ) + '</div>';
						} else {
							cardHtml += '<div class="opti-ab-vs-card__baseline">' + escHtml( strings.no_difference || 'No difference' ) + '</div>';
							vsImprovementHtml = '<div class="opti-ab-vs-divider__improvement opti-ab-vs-divider__improvement--neutral">= 0%</div>';
						}
					} else {
						var impClass = improvement > 0 ? 'positive' : (improvement < 0 ? 'negative' : 'neutral');
						var impSign  = improvement >= 0 ? '+' : '';
						var impArrow = improvement > 0 ? '&#9650; ' : (improvement < 0 ? '&#9660; ' : '');
						var impColor = improvement > 0 ? '#059669' : (improvement < 0 ? '#dc2626' : '#6b7280');
						cardHtml += '<div class="opti-ab-vs-card__baseline" style="color:' + impColor + ';">' + impSign + improvement.toFixed(1) + ( strings.vs_control_suffix || '% vs control' ) + '</div>';
						vsImprovementHtml = '<div class="opti-ab-vs-divider__improvement opti-ab-vs-divider__improvement--' + impClass + '">' + impArrow + impSign + improvement.toFixed(1) + '%</div>';
					}
				}

				// Redirect URL for page_split tests.
				if ( test.test_type === 'page_split' ) {
					var vd = {};
					try { vd = JSON.parse(v.variant_data || '{}'); } catch(e) {}
					var redirectUrl = vd.redirect_url || '';
					if ( redirectUrl ) {
						var shortUrl = redirectUrl.replace(/^https?:\/\//, '').replace(/\/+$/, '');
						cardHtml += '<div class="opti-ab-vs-card__url"><a href="' + escHtml(redirectUrl) + '" target="_blank" rel="noopener" title="' + escHtml(redirectUrl) + '">' + escHtml(shortUrl) + '</a></div>';
					} else if ( isControl && test.target_url ) {
						var ctrlUrl = test.target_url.replace(/^https?:\/\//, '').replace(/\/+$/, '');
						cardHtml += '<div class="opti-ab-vs-card__url"><a href="' + escHtml(test.target_url) + '" target="_blank" rel="noopener">' + escHtml(ctrlUrl) + ' <em>' + escHtml( strings.original_suffix || '(original)' ) + '</em></a></div>';
					}
				}

				// Significance badge.
				if ( sigText && ! isControl ) {
					var sigClass = v.is_significant ? 'significant' : 'pending';
					cardHtml += '<div class="opti-ab-vs-card__sig opti-ab-vs-card__sig--' + sigClass + '">' + escHtml(sigText) + '</div>';
				}

				var convLabel = goalConversionLabels[activeGoalType] || ( strings.conversions || 'Conversions' );
				cardHtml += '<div class="opti-ab-vs-card__stats">';
				cardHtml += '<div><strong>' + formatNumber(visitors) + '</strong>' + escHtml( strings.visitors || 'Visitors' ) + '</div>';
				cardHtml += '<div><strong>' + formatNumber(conv) + '</strong>' + escHtml(convLabel) + '</div>';
				cardHtml += '<div><strong>' + ci_lower + '% – ' + ci_upper + '%</strong>' + escHtml( strings.ci_95 || '95% CI' ) + '</div>';
				if ( v.p_value !== undefined && v.p_value !== null ) {
					var pVal     = parseFloat(v.p_value);
					var pDisplay = isControl ? '—' : pVal.toFixed(4);
					cardHtml += '<div><strong>' + pDisplay + '</strong>' + escHtml( strings.p_value || 'p-value' ) + '</div>';
				}
				cardHtml += '</div>'; // .opti-ab-vs-card__stats

				// Visual changes card for element-type tests.
				cardHtml += buildResultsVisualCard(v, test);

				cardHtml += '</div>'; // .opti-ab-vs-card

				vsCards.push(cardHtml);
			});

			var vsDivider = '<div class="opti-ab-vs-divider">';
			vsDivider += '<div class="opti-ab-vs-divider__line"></div>';
			vsDivider += '<div class="opti-ab-vs-divider__badge">' + escHtml( strings.vs_badge || 'VS' ) + '</div>';
			vsDivider += vsImprovementHtml;
			vsDivider += '<div class="opti-ab-vs-divider__line"></div>';
			vsDivider += '</div>';

			cardsHtml = '<div class="opti-ab-vs-layout">' + vsCards[0] + vsDivider + vsCards[1] + '</div>';

		} else {
			// 3+ variants: standard grid layout.
			results.forEach(function(v) {
				var isControl   = !!v.is_control;
				var isWinner    = winnerVariantId && parseInt(v.variant_id, 10) === winnerVariantId;
				var rate        = parseFloat(v.conversion_rate) || 0;
				var visitors    = parseInt(v.visitors, 10) || 0;
				var conv        = parseInt(v.conversions, 10) || 0;
				var improvement = parseFloat(v.improvement) || 0;
				// Fix: check !== null/undefined instead of truthy (0 is a valid CI bound).
				var ci_lower    = (v.ci_lower !== null && v.ci_lower !== undefined) ? (parseFloat(v.ci_lower) * 100).toFixed(2) : '—';
				var ci_upper    = (v.ci_upper !== null && v.ci_upper !== undefined) ? (parseFloat(v.ci_upper) * 100).toFixed(2) : '—';
				var sigText     = v.significance_text || '';

				var extraClass = isWinner ? ' opti-ab-variant-card--winner' : ( isControl ? ' opti-ab-variant-card--control' : '' );

				cardsHtml += '<div class="opti-ab-variant-card' + extraClass + '">';

				// ── Stats body (flex-grows to push visual card to same baseline) ──
				cardsHtml += '<div class="opti-ab-variant-card__body">';
				cardsHtml += '  <div class="opti-ab-variant-card__header">';
				cardsHtml += '    <span class="opti-ab-variant-card__name">' + escHtml(v.variant_name) + '</span>';
				if ( isControl ) cardsHtml += '    <span class="opti-ab-badge opti-ab-badge--completed">' + escHtml( strings.variant_badge_control || 'Control' ) + '</span>';
				if ( isWinner ) cardsHtml += '    <span class="opti-ab-badge opti-ab-badge--running">' + escHtml( strings.winner || 'Winner' ) + '</span>';
				cardsHtml += '  </div>';

				// Show redirect URL for page_split tests.
				if ( test.test_type === 'page_split' ) {
					var vd = {};
					try { vd = JSON.parse(v.variant_data || '{}'); } catch(e) {}
					var redirectUrl = vd.redirect_url || '';
					if ( redirectUrl ) {
						var shortUrl = redirectUrl.replace(/^https?:\/\//, '').replace(/\/+$/, '');
						cardsHtml += '  <div class="opti-ab-variant-card__url"><a href="' + escHtml(redirectUrl) + '" target="_blank" rel="noopener" title="' + escHtml(redirectUrl) + '">' + escHtml(shortUrl) + '</a></div>';
					} else if ( isControl && test.target_url ) {
						var ctrlUrl = test.target_url.replace(/^https?:\/\//, '').replace(/\/+$/, '');
						cardsHtml += '  <div class="opti-ab-variant-card__url"><a href="' + escHtml(test.target_url) + '" target="_blank" rel="noopener" title="' + escHtml(test.target_url) + '">' + escHtml(ctrlUrl) + ' <em>' + escHtml( strings.original_suffix || '(original)' ) + '</em></a></div>';
					}
				}

				cardsHtml += '  <div class="opti-ab-variant-card__rate">' + (rate * 100).toFixed(2) + '%</div>';

				if ( ! isControl && controlRate !== null ) {
					// When control rate is 0, relative % improvement is undefined (÷0).
					// Show absolute percentage-point difference instead.
					if ( controlRate === 0 ) {
						var absDiff = (rate * 100).toFixed(1);
						if ( rate > 0 ) {
							cardsHtml += '  <div class="opti-ab-variant-card__improvement opti-ab-variant-card__improvement--positive">&#9650; +' + absDiff + ( strings.pp_vs_control_suffix || ' pp vs control' ) + '</div>';
						} else {
							cardsHtml += '  <div class="opti-ab-variant-card__improvement opti-ab-variant-card__improvement--neutral">' + escHtml( strings.no_difference || 'No difference' ) + '</div>';
						}
					} else {
						var impClass = improvement > 0 ? 'positive' : ( improvement < 0 ? 'negative' : 'neutral' );
						var impSign  = improvement >= 0 ? '+' : '';
						var impArrow = improvement > 0 ? '&#9650; ' : (improvement < 0 ? '&#9660; ' : '');
						cardsHtml += '  <div class="opti-ab-variant-card__improvement opti-ab-variant-card__improvement--' + impClass + '">' + impArrow + impSign + improvement.toFixed(1) + ( strings.vs_control_suffix || '% vs control' ) + '</div>';
					}
				} else {
					cardsHtml += '  <div class="opti-ab-variant-card__improvement">' + escHtml( strings.baseline || 'Baseline' ) + '</div>';
				}

				// Significance status text.
				if ( sigText && ! isControl ) {
					var sigClass = v.is_significant ? 'significant' : 'pending';
					cardsHtml += '  <div class="opti-ab-variant-card__significance opti-ab-variant-card__significance--' + sigClass + '">' + escHtml(sigText) + '</div>';
				}

				var convLabel = goalConversionLabels[activeGoalType] || ( strings.conversions || 'Conversions' );

				cardsHtml += '  <div class="opti-ab-variant-card__stats">';
				cardsHtml += '    <div class="opti-ab-variant-card__stat-item"><strong>' + formatNumber(visitors) + '</strong>' + escHtml( strings.visitors || 'Visitors' ) + '</div>';
				cardsHtml += '    <div class="opti-ab-variant-card__stat-item"><strong>' + formatNumber(conv) + '</strong>' + escHtml(convLabel) + '</div>';
				cardsHtml += '    <div class="opti-ab-variant-card__stat-item"><strong>' + ci_lower + '% – ' + ci_upper + '%</strong>' + escHtml( strings.ci_95 || '95% CI' ) + '</div>';
				if ( v.p_value !== undefined && v.p_value !== null ) {
					var pVal = parseFloat(v.p_value);
					var pDisplay = isControl ? '—' : pVal.toFixed(4);
					cardsHtml += '    <div class="opti-ab-variant-card__stat-item"><strong>' + pDisplay + '</strong>' + escHtml( strings.p_value || 'p-value' ) + '</div>';
				}
				cardsHtml += '  </div>';
				cardsHtml += '</div>'; // /.opti-ab-variant-card__body

				// ── Visual changes card (aligned across all cards) ──
				cardsHtml += buildResultsVisualCard(v, test);

				cardsHtml += '</div>';
			});
		}

		$('#opti-ab-variant-cards').html(cardsHtml);

		// Size each results-preview iframe to the current viewport (dynamic
		// desktop/mobile render), matching the builder-card preview behavior.
		$('#opti-ab-variant-cards').find('.ob-res-vcard__iframe').each(function() {
			applyDynamicPreviewSize( this, true );
		});

		// Equalize variant-card body heights per row so visual cards align.
		equalizeCardBodyHeights();

		$('#opti-ab-declare-winner').show();
		$('#opti-ab-apply-winner').hide();
		$('#opti-ab-revert-winner').hide();

		// Applied tests should expose a safe undo/disable path instead of another apply.
		if ( test.status === 'applied' ) {
			$('#opti-ab-winner-actions').show();
			$('#opti-ab-declare-winner').hide();
			$('#opti-ab-apply-winner').hide();
			$('#opti-ab-revert-winner').show();
		// Show winner actions if test is completed or has significance.
		} else if ( test.status === 'completed' || sigPct >= 95 ) {
			$('#opti-ab-winner-actions').show();
			if ( test.winner_variant_id ) {
				$('#opti-ab-apply-winner').show();
			}
		} else {
			$('#opti-ab-winner-actions').hide();
		}

		// Chart.
		renderChart(data.daily_stats || []);

		// Initialize Lucide icons for dynamically inserted elements.
		if (typeof lucide !== 'undefined') { lucide.createIcons(); }
	}

	/**
	 * Equalize the height of .opti-ab-variant-card__body elements per visual
	 * row so that the visual-changes cards below them start at the same level.
	 *
	 * Groups cards by their CSS Grid row (offsetTop) and sets min-height on
	 * each group to the tallest body in that row.
	 */
	function equalizeCardBodyHeights() {
		var $panel = $('#opti-ab-goal-panel');
		// Skip if the goal panel is hidden — elements have no real dimensions.
		if ( $panel.length && $panel.is(':hidden') ) return;

		var $bodies = $('#opti-ab-variant-cards .opti-ab-variant-card__body');
		if ( $bodies.length < 2 ) return;

		// Reset any previous min-height so natural sizes are measured.
		$bodies.css('min-height', '');

		// Group bodies by their card's top offset (= same grid row).
		var rows = {};
		$bodies.each(function() {
			var $card = $(this).closest('.opti-ab-variant-card');
			var top   = Math.round($card.offset().top);
			if ( ! rows[top] ) rows[top] = [];
			rows[top].push(this);
		});

		// Per row, set min-height to the tallest body.
		Object.keys(rows).forEach(function(top) {
			var maxH = 0;
			rows[top].forEach(function(el) {
				var h = el.offsetHeight;
				if ( h > maxH ) maxH = h;
			});
			if ( maxH > 0 ) {
				rows[top].forEach(function(el) {
					el.style.minHeight = maxH + 'px';
				});
			}
		});
	}

	var abChartInstance = null;

	function renderChart(dailyStats) {
		var canvas = document.getElementById('opti-ab-chart');
		if ( ! canvas || ! window.Chart ) return;

		// Destroy previous chart instance before re-creating.
		if ( abChartInstance ) {
			abChartInstance.destroy();
			abChartInstance = null;
		}

		var $emptyMsg = $('#opti-ab-chart-empty');
		if ( ! dailyStats || dailyStats.length === 0 ) {
			$(canvas).hide();
			$emptyMsg.show();
			return;
		}
		$(canvas).show();
		$emptyMsg.hide();

		var ctx      = canvas.getContext('2d');
		var datasets = {};
		var labels   = [];
		var colors   = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#ec4899'];

		dailyStats.forEach(function(row) {
			if ( labels.indexOf(row.stat_date) === -1 ) {
				labels.push(row.stat_date);
			}
			var key = row.variant_id;
			if ( ! datasets[key] ) {
				var colorIdx = Object.keys(datasets).length % colors.length;
				var color    = colors[colorIdx];
				var grad     = ctx.createLinearGradient(0, 0, 0, 350);
				grad.addColorStop(0, hexToRgba(color, 0.3));
				grad.addColorStop(1, hexToRgba(color, 0));
				datasets[key] = {
					// variant_name is now JOINed from the variants table.
					label:           row.variant_name || ( strings.chart_variant_prefix || 'Variant ' ) + key,
					data:            [],
					borderColor:     color,
					backgroundColor: grad,
					fill:            true,
					tension:         0.4,
					pointRadius:     3
				};
			}
		});

		// Fill data points — DB columns are 'impressions' and 'conversions' (not daily_*).
		labels.sort();
		Object.keys(datasets).forEach(function(variantId) {
			labels.forEach(function(date) {
				var found = false;
				dailyStats.forEach(function(row) {
					if ( row.stat_date === date && String(row.variant_id) === String(variantId) ) {
						var imp  = parseInt(row.impressions, 10) || 0;
						var conv = parseInt(row.conversions, 10) || 0;
						var rate = imp > 0 ? (conv / imp * 100) : 0;
						datasets[variantId].data.push(rate.toFixed(2));
						found = true;
					}
				});
				if ( ! found ) {
					// Bug #4 fix: push an explicit 0 instead of null so Chart.js
					// draws a continuous line for every variant across the full
					// x-axis, even on days when that variant received no traffic.
					// Server-side zero-fill in get_daily_stats() should already
					// handle this — this client-side fallback is defence-in-depth.
					datasets[variantId].data.push(0);
				}
			});
		});

		abChartInstance = new Chart(ctx, {
			type: 'line',
			data: {
				labels: labels.map(function(d) { return formatDate(d); }),
				datasets: Object.values(datasets)
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				animation: { duration: 800 },
				scales: {
					y: {
						beginAtZero: true,
						ticks: { callback: function(v) { return v + '%'; } }
					}
				},
				plugins: {
					tooltip: {
						callbacks: {
							label: function(context) { return context.dataset.label + ': ' + context.parsed.y + '%'; }
						}
					}
				}
			}
		});
	}

	function declareWinner(testId) {
		// DEF-AB-014 fix: the original guard required `#opti-ab-variant-cards .opti-ab-variant-card`
		// which is the FREE plugin's results layout. The Pro plugin renders variants in a different
		// container (Frequentist vs Bayesian side-by-side cards), so the guard always returned 0
		// on Pro, silently aborting the declare-winner flow.
		//
		// Relaxed to: require testId to be valid — the server uses `auto_winner=1` to pick the
		// winning variant itself, so we don't need to see the cards in DOM.
		if ( ! testId || testId < 1 ) return;

		showConfirmModal({
			title:       strings.confirm_winner_title || 'Declare Best-Performing Variant',
			message:     strings.confirm_winner || 'Opti Behavior will declare the variant with the highest conversion rate as the winner based on the current test results.',
			confirmText: strings.confirm_winner_button || 'Declare Best Performer',
			buttonClass: 'opti-ab-confirm-modal__btn-primary'
		}, function() {
			// Send auto_winner flag; server-side will determine the best variant.
			$.post(ajaxUrl, {
				action:      'opti_behavior_ab_stop_test',
				nonce:       nonce,
				test_id:     testId,
				auto_winner: 1
			}, function(res) {
				if ( res.success ) {
					showToast(( strings.winner_declared || 'Winner declared!' ), 'success');
					window.location.reload();
				} else {
					showToast(res.data && res.data.message ? res.data.message : strings.error, 'error');
				}
			});
		});
	}


	function revertAppliedWinner(testId) {
		if ( ! testId || testId < 1 ) return;

		showConfirmModal({
			title:       strings.confirm_revert_title || 'Disable Applied Winner',
			message:     strings.confirm_revert || 'This will disable the permanent winner change and return the test to Completed. The declared winner and historical data will be preserved.',
			confirmText: strings.confirm_revert_button || 'Disable Applied Winner',
			buttonClass: 'opti-ab-confirm-modal__btn-danger'
		}, function() {
			$.post(ajaxUrl, {
				action:  'opti_behavior_ab_revert_winner',
				nonce:   nonce,
				test_id: testId
			}, function(res) {
				if ( res.success ) {
					showToast(( strings.applied_winner_disabled || 'Applied winner disabled.' ), 'success');
					window.location.reload();
				} else {
					showToast(res.data && res.data.message ? res.data.message : strings.error, 'error');
				}
			});
		});
	}

	/**
	 * Two-phase apply: preflight → diff dialog → commit.
	 *
	 * Phase A: fires opti_behavior_ab_preflight_apply (read-only) and renders the
	 *          diff preview dialog with field-by-field before/after table.
	 * Phase B: "Review & Commit" button fires opti_behavior_ab_apply_winner with
	 *          the idempotency_key from Phase A so the server skips recomputing.
	 *
	 * Exposes window.optiABDiff so the Pro plugin can:
	 *   - Call optiABDiff.open() / openWithButton() from its own action handlers.
	 *   - Enhance the rendered dialog via window.optiABDiffEnhance($dialog, payload).
	 */
	var optiABDiff = {

		/**
		 * Start the preflight for the standard results-page apply button.
		 *
		 * @param {number} testId
		 */
		open: function ( testId ) {
			var $btn = $( '#opti-ab-apply-winner' );
			optiABDiff._preflight( testId, $btn, null );
		},

		/**
		 * Start the preflight using a caller-supplied button and optional success callback.
		 * Used by the Pro plugin's header-button flow.
		 *
		 * @param {number}        testId
		 * @param {jQuery}        $btn          Button to disable/enable while loading.
		 * @param {Function|null} onSuccess     Called with (res.data) after a successful commit.
		 */
		openWithButton: function ( testId, $btn, onSuccess ) {
			optiABDiff._preflight( testId, $btn, onSuccess );
		},

		// -------------------------------------------------------------------------
		// Internal
		// -------------------------------------------------------------------------

		_preflight: function ( testId, $btn, onSuccess ) {
			var origText = $btn.length ? $btn.text() : '';
			if ( $btn.length ) {
				$btn.prop( 'disabled', true ).text( strings.loading || 'Loading\u2026' );
			}

			$.post( ajaxUrl, {
				action:  'opti_behavior_ab_preflight_apply',
				nonce:   nonce,
				test_id: testId
			}, function ( res ) {
				if ( $btn.length ) {
					$btn.prop( 'disabled', false ).text( origText );
				}
				if ( ! res.success ) {
					showToast( ( res.data && res.data.message ) ? res.data.message : strings.error, 'error' );
					return;
				}
				optiABDiff._show( res.data, testId, $btn, onSuccess );
			} ).fail( function () {
				if ( $btn.length ) {
					$btn.prop( 'disabled', false ).text( origText );
				}
				showToast( strings.error, 'error' );
			} );
		},

		_show: function ( payload, testId, $originBtn, onSuccess ) {
			optiABDiff._destroy();

			var changedFields    = payload.changed_fields   || {};
			var idempotencyKey   = payload.idempotency_key  || '';
			var testType         = payload.test_type        || '';
			var postTitle        = payload.target_post_title || '';
			var postId           = payload.target_post_id   || 0;
			var editUrl          = payload.target_edit_url  || '';
			var warnings         = payload.warnings         || [];
			var fieldKeys        = Object.keys( changedFields );

			// ----- Header -----
			var typeLabel = optiABDiff._typeLabel( testType );
			var typeAwareSentence = optiABDiff._typeAwareSentence( testType, postTitle );

			// ----- Target info row -----
			var targetHtml = '';
			if ( postTitle && postId ) {
				var editLinkHtml = editUrl
					? ' <a href="' + escAttr( editUrl ) + '" target="_blank" class="opti-ab-diff-edit-link">' + escHtml( strings.diff_edit_link || 'Edit \u2197' ) + '</a>'
					: '';
				targetHtml = '<div class="opti-ab-diff-target">'
					+ escHtml( strings.diff_changes_prefix || 'Changes will apply to ' ) + '<strong>' + escHtml( postTitle ) + '</strong> (#' + parseInt( postId, 10 ) + ').'
					+ editLinkHtml
					+ '</div>';
			}

			// ----- Warnings -----
			var warningsHtml = '';
			if ( warnings.length ) {
				warningsHtml = '<div class="opti-ab-diff-warnings">';
				for ( var wi = 0; wi < warnings.length; wi++ ) {
					warningsHtml += '<p class="opti-ab-diff-warning">'
						+ '<span class="opti-ab-diff-warning-icon" aria-hidden="true">\u26a0\ufe0f</span> '
						+ escHtml( warnings[ wi ] )
						+ '</p>';
				}
				warningsHtml += '</div>';
			}

			// ----- Table rows -----
			var tableRowsHtml = '';
			if ( fieldKeys.length === 0 ) {
				tableRowsHtml = '<tr><td colspan="3" class="opti-ab-diff-no-changes">' + escHtml( strings.diff_no_field_changes || 'No field changes detected.' ) + '</td></tr>';
			} else {
				for ( var fi = 0; fi < fieldKeys.length; fi++ ) {
					var field     = fieldKeys[ fi ];
					var entry     = changedFields[ field ];
					var fromVal   = ( entry && entry.from !== undefined && entry.from !== null ) ? entry.from : '';
					var toVal     = ( entry && entry.to   !== undefined && entry.to   !== null ) ? entry.to   : '';
					var noChange  = ( String( fromVal ) === String( toVal ) );
					var rowClass  = noChange ? ' opti-ab-diff-row--unchanged' : '';

					var fromHtml = optiABDiff._renderValue( field, fromVal, entry );
					var toHtml   = optiABDiff._renderValue( field, toVal,   entry );

					var badgeHtml = noChange
						? ' <span class="opti-ab-diff-badge" aria-label="' + escAttr( strings.diff_no_change_badge || 'no change' ) + '">' + escHtml( strings.diff_no_change_badge || 'no change' ) + '</span>'
						: '';

					tableRowsHtml += '<tr class="opti-ab-diff-row' + rowClass + '">'
						+ '<td class="opti-ab-diff-field" data-label="' + escAttr( strings.diff_th_field || 'Field' ) + '">'
						+ escHtml( optiABDiff._fieldLabel( field ) ) + badgeHtml
						+ '</td>'
						+ '<td class="opti-ab-diff-from" data-label="' + escAttr( strings.diff_th_before || 'Before' ) + '">' + fromHtml + '</td>'
						+ '<td class="opti-ab-diff-to"   data-label="' + escAttr( strings.diff_th_after || 'After' ) + '">'  + toHtml   + '</td>'
						+ '</tr>';
				}
			}

			// ----- Full dialog HTML -----
			var dialogHtml = '<div id="opti-ab-diff-dialog" class="opti-ab-modal opti-ab-diff-dialog-overlay"'
				+ ' role="dialog" aria-modal="true" aria-labelledby="opti-ab-diff-dlg-title">'
				+ '<div class="opti-ab-diff-dialog">'

				// Header
				+ '<div class="opti-ab-diff-dialog-hdr">'
				+ '<span class="opti-ab-diff-type-pill">' + escHtml( typeLabel ) + '</span>'
				+ '<h2 id="opti-ab-diff-dlg-title" class="opti-ab-diff-dialog-title">'
				+ escHtml( strings.diff_title || 'Apply Winner Permanently \u2014 Review Changes' )
				+ '</h2>'
				+ '<button class="opti-ab-diff-close" aria-label="' + escAttr( strings.close || 'Close' ) + '" type="button">&times;</button>'
				+ '</div>'

				// Body
				+ '<div class="opti-ab-diff-dialog-body">'
				+ ( typeAwareSentence ? '<p class="opti-ab-diff-type-sentence">' + escHtml( typeAwareSentence ) + '</p>' : '' )
				+ targetHtml
				+ warningsHtml
				+ '<table class="opti-ab-diff-table" role="table">'
				+ '<thead><tr>'
				+ '<th scope="col">' + escHtml( strings.diff_th_field || 'Field' ) + '</th>'
				+ '<th scope="col">' + escHtml( strings.diff_th_before || 'Before' ) + '</th>'
				+ '<th scope="col">' + escHtml( strings.diff_th_after || 'After' ) + '</th>'
				+ '</tr></thead>'
				+ '<tbody>' + tableRowsHtml + '</tbody>'
				+ '</table>'
				+ '<p class="opti-ab-diff-note">'
				+ escHtml( strings.diff_snapshot_note || 'A snapshot will be saved automatically \u2014 you\u2019ll be able to revert in the next update.' )
				+ '</p>'
				+ '</div>'

				// Footer
				+ '<div class="opti-ab-diff-dialog-footer">'
				+ '<button class="opti-ab-diff-btn opti-ab-diff-btn--cancel" type="button">' + escHtml( strings.confirm_cancel || 'Cancel' ) + '</button>'
				+ '<button id="opti-ab-apply-confirm" class="opti-ab-diff-btn opti-ab-diff-btn--commit" type="button">' + escHtml( strings.diff_commit || 'Review & Commit' ) + '</button>'
				+ '</div>'

				+ '</div>'
				+ '</div>';

			var $dialog = $( dialogHtml ).appendTo( 'body' );

			// ARIA: prevent background scroll
			$( 'body' ).addClass( 'opti-ab-diff-open' );

			// Focus the cancel button for safety (destructive action)
			setTimeout( function () {
				$dialog.find( '.opti-ab-diff-btn--cancel' ).focus();
			}, 60 );

			// Allow Pro to enhance the dialog with thumbnails, Pro pills etc.
			if ( typeof window.optiABDiffEnhance === 'function' ) {
				window.optiABDiffEnhance( $dialog, payload );
			}

			// ----- Event wiring -----

			// Show-full / Hide-full toggle for long-text fields (event delegation)
			$dialog.on( 'click', '.opti-ab-diff-show-full', function () {
				var $wrap = $( this ).closest( '.opti-ab-diff-cell-content' );
				$wrap.find( '.opti-ab-diff-truncated' ).hide();
				$wrap.find( '.opti-ab-diff-full' ).show();
			} );
			$dialog.on( 'click', '.opti-ab-diff-hide-full', function () {
				var $wrap = $( this ).closest( '.opti-ab-diff-cell-content' );
				$wrap.find( '.opti-ab-diff-full' ).hide();
				$wrap.find( '.opti-ab-diff-truncated' ).show();
			} );

			// Close: X button and Cancel button
			$dialog.on( 'click', '.opti-ab-diff-close, .opti-ab-diff-btn--cancel', function () {
				optiABDiff._destroy();
			} );

			// Close on overlay click (click on the dark backdrop, not the white dialog)
			$dialog.on( 'click', function ( e ) {
				if ( $( e.target ).is( '#opti-ab-diff-dialog' ) ) {
					optiABDiff._destroy();
				}
			} );

			// ESC key closes the dialog
			$( document ).on( 'keydown.optiABDiff', function ( e ) {
				if ( e.key === 'Escape' || e.keyCode === 27 ) {
					optiABDiff._destroy();
				}
			} );

			// Commit button
			$dialog.on( 'click', '.opti-ab-diff-btn--commit', function () {
				var $commitBtn = $( this );
				$commitBtn.prop( 'disabled', true );
				optiABDiff._destroy();
				optiABDiff._commit( testId, idempotencyKey, $originBtn, onSuccess );
			} );
		},

		_commit: function ( testId, idempotencyKey, $originBtn, onSuccess ) {
			if ( $originBtn && $originBtn.length ) {
				$originBtn.prop( 'disabled', true );
			}

			$.post( ajaxUrl, {
				action:          'opti_behavior_ab_apply_winner',
				nonce:           nonce,
				test_id:         testId,
				idempotency_key: idempotencyKey
			}, function ( res ) {
				if ( res.success ) {
					var changedCount = ( res.data && res.data.changed_count ) ? res.data.changed_count : 0;
					var toastMsg = changedCount > 0
						? ( changedCount === 1 ? ( strings.diff_applied_one || 'Applied \u2014 %s field updated.' ) : ( strings.diff_applied_many || 'Applied \u2014 %s fields updated.' ) ).replace( '%s', changedCount )
						: ( strings.diff_winner_applied || 'Winner applied permanently.' );
					showToast( toastMsg, 'success' );

					if ( typeof onSuccess === 'function' ) {
						onSuccess( res.data );
					} else {
						window.location.reload();
					}
				} else {
					var msg = ( res.data && res.data.message ) ? res.data.message : strings.error;
					showToast( msg, 'error' );
					if ( $originBtn && $originBtn.length ) {
						$originBtn.prop( 'disabled', false );
					}
				}
			} ).fail( function () {
				showToast( strings.error, 'error' );
				if ( $originBtn && $originBtn.length ) {
					$originBtn.prop( 'disabled', false );
				}
			} );
		},

		_destroy: function () {
			$( '#opti-ab-diff-dialog' ).remove();
			$( 'body' ).removeClass( 'opti-ab-diff-open' );
			$( document ).off( 'keydown.optiABDiff' );
		},

		_fieldLabel: function ( field ) {
			var labels = {
				title            : ( strings.diff_field_title || 'Title' ),
				content          : ( strings.diff_field_content || 'Content' ),
				excerpt          : ( strings.diff_field_excerpt || 'Excerpt' ),
				short_description: ( strings.diff_field_short_description || 'Short Description' ),
				full_description : ( strings.diff_field_full_description || 'Full Description' ),
				regular_price    : ( strings.diff_field_regular_price || 'Regular Price' ),
				sale_price       : ( strings.diff_field_sale_price || 'Sale Price' ),
				price            : ( strings.diff_field_price || 'Price' ),
				image_id         : ( strings.diff_field_image_id || 'Featured Image' ),
				gallery_image_ids: ( strings.diff_field_gallery_image_ids || 'Gallery Images' ),
				add_to_cart_text : ( strings.diff_field_add_to_cart_text || 'Add to Cart Text' ),
				element_changes  : ( strings.diff_field_element_changes || 'Visual Changes' ),
				redirect         : ( strings.diff_field_redirect || 'Redirect URL' ),
				redirect_url     : ( strings.diff_field_redirect || 'Redirect URL' ),
			};
			return labels[ field ] || field.replace( /_/g, ' ' ).replace( /\b\w/g, function ( c ) { return c.toUpperCase(); } );
		},

		_typeLabel: function ( testType ) {
			var labels = {
				headline   : ( strings.diff_type_headline || 'Headline Test' ),
				element    : ( strings.diff_type_element || 'Element Test' ),
				page_split : ( strings.diff_type_page_split || 'Page Split Test' ),
				woocommerce: ( strings.diff_type_woocommerce || 'WooCommerce Product' ),
			};
			return labels[ testType ] || ( testType ? testType.charAt( 0 ).toUpperCase() + testType.slice( 1 ) + ( strings.diff_type_generic_suffix || ' Test' ) : ( strings.diff_type_ab || 'A/B Test' ) );
		},

		_typeAwareSentence: function ( testType, postTitle ) {
			var name = postTitle || ( strings.diff_sentence_default_name || 'the winning variant' );
			switch ( testType ) {
				case 'woocommerce':
					return ( strings.diff_sentence_woocommerce || 'This will update the product page content (title, price, image, CTA) for \u201c%s\u201d permanently.' ).replace( '%s', name );
				case 'page_split':
					return ( strings.diff_sentence_page_split || 'This will register a permanent 301 redirect from the control URL to the winning page for \u201c%s\u201d.' ).replace( '%s', name );
				case 'element':
					return ( strings.diff_sentence_element || 'This will permanently apply the winning variant\u2019s visual changes to \u201c%s\u201d.' ).replace( '%s', name );
				default:
					return ( strings.diff_sentence_default || 'This will permanently apply the winning variant content to \u201c%s\u201d.' ).replace( '%s', name );
			}
		},

		_renderValue: function ( field, val, entry ) {
			// Wrap in a cell-content div for show/hide toggling
			var inner = optiABDiff._renderInner( field, val, entry );
			return '<div class="opti-ab-diff-cell-content">' + inner + '</div>';
		},

		_renderInner: function ( field, val, entry ) {
			if ( val === null || val === undefined || val === '' ) {
				return '<em class="opti-ab-diff-empty">' + escHtml( strings.diff_empty || '(empty)' ) + '</em>';
			}

			// Image field: Pro will enhance this with thumbnail; fall back to ID/value
			if ( field === 'image_id' ) {
				// If Pro has added from_url/to_url via preview(), _renderImage will be overridden;
				// for now just show the numeric ID.
				var imgIdStr = Array.isArray( val ) ? val.join( ', ' ) : String( val );
				return '<span class="opti-ab-diff-numeric">' + escHtml( imgIdStr ) + '</span>';
			}

			// Array values (gallery_image_ids)
			if ( Array.isArray( val ) ) {
				if ( val.length === 0 ) {
					return '<em class="opti-ab-diff-empty">' + escHtml( strings.diff_none || '(none)' ) + '</em>';
				}
				return '<span class="opti-ab-diff-array">[' + val.map( function ( v ) { return escHtml( String( v ) ); } ).join( ', ' ) + ']</span>';
			}

			// Object values (element_changes)
			if ( val !== null && typeof val === 'object' ) {
				var objStr = JSON.stringify( val );
				if ( objStr.length > 120 ) {
					return '<span class="opti-ab-diff-truncated">'
						+ escHtml( objStr.substring( 0, 120 ) ) + '\u2026'
						+ '</span>';
				}
				return '<code class="opti-ab-diff-code">' + escHtml( objStr ) + '</code>';
			}

			var strVal = String( val );

			// Numeric fields: monospace tabular
			var numericFields = [ 'regular_price', 'sale_price', 'price' ];
			if ( numericFields.indexOf( field ) !== -1 ) {
				return '<span class="opti-ab-diff-numeric">' + escHtml( strVal ) + '</span>';
			}

			// Long text: truncate with toggle
			var longFields = [ 'content', 'full_description', 'short_description', 'excerpt' ];
			if ( longFields.indexOf( field ) !== -1 && strVal.length > 120 ) {
				return '<span class="opti-ab-diff-truncated">'
					+ escHtml( strVal.substring( 0, 120 ) ) + '\u2026'
					+ '<button class="opti-ab-diff-show-full" type="button">' + escHtml( strings.diff_show_full || 'Show full' ) + '</button>'
					+ '</span>'
					+ '<span class="opti-ab-diff-full" style="display:none">'
					+ escHtml( strVal )
					+ '<button class="opti-ab-diff-hide-full" type="button">' + escHtml( strings.diff_show_less || 'Show less' ) + '</button>'
					+ '</span>';
			}

			return escHtml( strVal );
		}
	};

	// Expose globally for Pro plugin integration and external scripts.
	window.optiABDiff = optiABDiff;

	function applyWinner(testId) {
		// Two-phase apply: preflight diff preview → Review & Commit.
		// The Pro plugin can intercept via window.optiABDiffEnhance($dialog, payload).
		optiABDiff.open( testId );
	}

	// =========================================================================
	// Utilities
	// =========================================================================

	function showToast(message, type) {
		type = type || 'success';
		var $toast = $('<div class="opti-ab-toast opti-ab-toast--' + type + '">' + escHtml(message) + '</div>');
		$('body').append($toast);
		setTimeout(function() { $toast.addClass('show'); }, 10);
		setTimeout(function() {
			$toast.removeClass('show');
			setTimeout(function() { $toast.remove(); }, 300);
		}, 3000);
	}

	// Expose showToast globally so that the Pro plugin can use non-blocking
	// toast notifications instead of falling back to alert().
	window.showToast = showToast;

	function escHtml(str) {
		if ( ! str ) return '';
		return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	function escAttr(str) {
		if ( ! str ) return '';
		return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
	}

	function ucfirst(s) {
		if ( ! s ) return '';
		return s.charAt(0).toUpperCase() + s.slice(1);
	}

	function formatNumber(n) {
		n = parseInt(n, 10) || 0;
		if ( n >= 1000000 ) return (n / 1000000).toFixed(1) + 'M';
		if ( n >= 1000 ) return (n / 1000).toFixed(1) + 'K';
		return String(n);
	}

	function formatDate(str) {
		if ( ! str ) return '—';
		var d = new Date(str);
		if ( isNaN(d.getTime()) ) return str;
		return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
	}

	function debounce(fn, delay) {
		var timer;
		return function() {
			var args = arguments;
			var self = this;
			clearTimeout(timer);
			timer = setTimeout(function() { fn.apply(self, args); }, delay);
		};
	}

	/**
	 * Animated count-up from 0 to endVal using requestAnimationFrame.
	 *
	 * @param {Element} el       Target DOM element.
	 * @param {number}  endVal   Final numeric value.
	 * @param {string}  suffix   Text appended after the number (e.g. '%', ' days').
	 * @param {number}  duration Animation duration in milliseconds.
	 */
	function animateCountUp(el, endVal, suffix, duration) {
		if ( ! el ) return;
		suffix = suffix || '';
		var startTime = null;
		var isFloat   = (endVal % 1 !== 0);
		var finished  = false;
		function finalText() {
			return isFloat
				? endVal.toFixed(2) + suffix
				: formatNumber(Math.round(endVal)) + suffix;
		}
		function step(timestamp) {
			if ( finished ) { return; }
			if ( ! startTime ) { startTime = timestamp; }
			var progress = Math.min((timestamp - startTime) / duration, 1);
			var eased    = 1 - Math.pow(1 - progress, 3); // Cubic ease-out.
			var current  = endVal * eased;
			el.textContent = isFloat
				? current.toFixed(2) + suffix
				: formatNumber(Math.round(current)) + suffix;
			if ( progress < 1 ) {
				requestAnimationFrame(step);
			} else {
				// Snap to precise final value.
				finished = true;
				el.textContent = finalText();
			}
		}
		requestAnimationFrame(step);
		// Fallback: requestAnimationFrame is throttled or paused in hidden /
		// background tabs, so the animation may never run. Always make sure
		// the final value renders.
		setTimeout(function() {
			if ( ! finished ) {
				finished = true;
				el.textContent = finalText();
			}
		}, duration + 200);
	}

	/**
	 * Convert a 6-digit hex colour to an rgba() string.
	 *
	 * @param  {string} hex   Hex colour, e.g. '#6366f1'.
	 * @param  {number} alpha Opacity between 0 and 1.
	 * @return {string}
	 */
	function hexToRgba(hex, alpha) {
		var r = parseInt(hex.slice(1, 3), 16);
		var g = parseInt(hex.slice(3, 5), 16);
		var b = parseInt(hex.slice(5, 7), 16);
		return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
	}

})(jQuery);
