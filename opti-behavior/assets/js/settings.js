/**
 * Settings Page Scripts
 * Handles debug log viewer and cleanup button functionality
 *
 * @package OptiBehavior
 */

(function() {
	var S = (typeof opti_behaviorSettings !== 'undefined' && opti_behaviorSettings.strings) ? opti_behaviorSettings.strings : {};
	/**
	 * Display a styled dismissible admin notice instead of native alert().
	 *
	 * @param {string} message  Text to show.
	 * @param {string} type     'success' | 'error' | 'warning' | 'info'
	 */
	function showOptiNotice(message, type) {
		var borderColors = { success: '#00a32a', error: '#d63638', warning: '#dba617', info: '#72aee6' };
		var border = borderColors[type] || borderColors.info;
		var notice = document.createElement('div');
		notice.style.cssText = 'position:fixed;top:40px;right:20px;z-index:999999;padding:12px 20px;'
			+ 'border-left:4px solid ' + border + ';background:#fff;'
			+ 'box-shadow:0 2px 8px rgba(0,0,0,.18);max-width:400px;font-size:13px;line-height:1.5;'
			+ 'border-radius:2px;word-wrap:break-word;';
		notice.textContent = message;
		document.body.appendChild(notice);
		setTimeout(function() {
			notice.style.transition = 'opacity .3s';
			notice.style.opacity = '0';
			setTimeout(function() { if (notice.parentNode) { notice.parentNode.removeChild(notice); } }, 350);
		}, 5000);
	}

	function keepActiveSettingsTabVisible() {
		var nav = document.querySelector('.settings-tab-nav');
		var active = nav ? nav.querySelector('.settings-tab-link.active') : null;
		if (!nav || !active || window.innerWidth > 1024) {
			return;
		}

		var navRect = nav.getBoundingClientRect();
		var activeRect = active.getBoundingClientRect();
		var offset = (activeRect.left + activeRect.width / 2) - (navRect.left + navRect.width / 2);
		nav.scrollTo({
			left: nav.scrollLeft + offset,
			behavior: 'smooth'
		});
	}

	// Debug Log Viewer Function
	function viewDebugLog() {
		var modal = document.getElementById("opti-behavior-log-viewer");
		var content = document.getElementById("opti-behavior-log-content");

		if (!modal || !content) {
			// console.error("Debug log viewer elements not found");
			return;
		}

		// Show modal
		modal.style.display = "block";
		content.textContent = S.loadingDebugLog || 'Loading debug log...';

		// Setup close button handler (if not already set)
		var closeBtn = modal.querySelector('.opti-behavior-log-close-btn');
		if (closeBtn && !closeBtn.hasAttribute('data-handler-attached')) {
			closeBtn.addEventListener('click', function() {
				modal.style.display = 'none';
			});
			closeBtn.setAttribute('data-handler-attached', 'true');
		}

		// Fetch log content via AJAX
		jQuery.ajax({
			url: ajaxurl,
			type: "POST",
			data: {
				action: "opti_behavior_get_debug_log",
				nonce: opti_behaviorSettings.viewLogNonce
			},
			success: function(response) {
				if (response.success) {
					content.textContent = response.data.log_content || (S.logFileEmpty || 'Log file is empty');
				} else {
					content.textContent = (S.errorLoadingLog || 'Error loading log: %s').replace('%s', response.data || (S.unknownError || 'Unknown error'));
				}
			},
			error: function(xhr, status, error) {
				content.textContent = (S.errorLoadingLog || 'Error loading log: %s').replace('%s', error);
			}
		});
	}

	// Attach event listener to view log button
	document.addEventListener('DOMContentLoaded', function() {
		var viewLogBtn = document.getElementById('opti-behavior-view-log-btn');
		if (viewLogBtn) {
			viewLogBtn.addEventListener('click', viewDebugLog);
		}
		keepActiveSettingsTabVisible();
	});

	window.addEventListener('resize', function() {
		window.clearTimeout(window.optiBehaviorSettingsTabTimer);
		window.optiBehaviorSettingsTabTimer = window.setTimeout(keepActiveSettingsTabVisible, 120);
	});

	// Handle delete form confirmations
	document.addEventListener('submit', function(e){
		// e.target is the form element when submit event fires
		var form = e.target;
		if(form && form.classList && form.classList.contains('optibehavior-delete-form')){
			var msg = form.getAttribute('data-confirm');
			if(msg && !confirm(msg)){
				e.preventDefault();
			} else {
				// Show success message after form submission
				var successMsg = form.getAttribute('data-success');
				if(successMsg) {
					// Store success message in sessionStorage to show after page reload
					sessionStorage.setItem('opti_behavior_success_msg', successMsg);
				}
			}
		}
	});

	// Display success message if stored in sessionStorage
	document.addEventListener('DOMContentLoaded', function() {
		var successMsg = sessionStorage.getItem('opti_behavior_success_msg');
		if(successMsg) {
			// Remove from sessionStorage
			sessionStorage.removeItem('opti_behavior_success_msg');
			// Show styled notice instead of native alert
			showOptiNotice(successMsg, 'success');
		}
	});

	// Cleanup Buttons Event Handlers
	jQuery(document).ready(function($) {
		// Delete Old Recordings by Date
		$("#cleanup_by_date_btn").on("click", function() {
			var date = $("#cleanup_before_date").val();
			if (!date) {
				showOptiNotice(S.selectDate || 'Please select a date', 'warning');
				return;
			}
			if (!confirm((S.confirmDeleteBefore || 'Are you sure you want to delete all recordings before %s?').replace('%s', date))) {
				return;
			}
			$(this).prop("disabled", true).text(S.deleting || 'Deleting...');
			$.ajax({
				url: ajaxurl,
				type: "POST",
				data: {
					action: "opti_behavior_cleanup_by_date",
					date: date,
					nonce: opti_behaviorSettings.cleanupNonce
				},
				success: function(response) {
					if (response.success) {
						showOptiNotice(response.data.message || (S.recordingsDeleted || 'Recordings deleted successfully'), 'success');
						location.reload();
					} else {
						showOptiNotice((S.errorColon || 'Error:') + ' ' + (response.data || (S.unknownError || 'Unknown error')), 'error');
						$("#cleanup_by_date_btn").prop("disabled", false).html("<span class=\"btn-icon\"><i data-lucide=\"trash-2\"></i></span> " + (S.deleteOldRecordings || 'Delete Old Recordings'));
						if (typeof lucide !== 'undefined') lucide.createIcons();
					}
				},
				error: function() {
					showOptiNotice(S.errorDeletingRecordings || 'Error deleting recordings', 'error');
					$("#cleanup_by_date_btn").prop("disabled", false).html("<span class=\"btn-icon\"><i data-lucide=\"trash-2\"></i></span> " + (S.deleteOldRecordings || 'Delete Old Recordings'));
					if (typeof lucide !== 'undefined') lucide.createIcons();
				}
			});
		});

		// Delete Short Recordings by Duration
		$("#cleanup_by_duration_btn").on("click", function() {
			var duration = $("#min_duration_seconds").val();
			if (!duration || duration < 1) {
				showOptiNotice(S.enterValidDuration || 'Please enter a valid duration', 'warning');
				return;
			}
			if (!confirm((S.confirmDeleteShorterThan || 'Are you sure you want to delete all recordings shorter than %s seconds?').replace('%s', duration))) {
				return;
			}
			$(this).prop("disabled", true).text(S.deleting || 'Deleting...');
			$.ajax({
				url: ajaxurl,
				type: "POST",
				data: {
					action: "opti_behavior_cleanup_by_duration",
					duration: duration,
					nonce: opti_behaviorSettings.cleanupNonce
				},
				success: function(response) {
					if (response.success) {
						showOptiNotice(response.data.message || (S.recordingsShortDeleted || 'Short recordings deleted successfully'), 'success');
						location.reload();
					} else {
						showOptiNotice((S.errorColon || 'Error:') + ' ' + (response.data || (S.unknownError || 'Unknown error')), 'error');
						$("#cleanup_by_duration_btn").prop("disabled", false).html("<span class=\"btn-icon\"><i data-lucide=\"trash-2\"></i></span> " + (S.deleteShortRecordings || 'Delete Short Recordings'));
						if (typeof lucide !== 'undefined') lucide.createIcons();
					}
				},
				error: function() {
					showOptiNotice(S.errorDeletingRecordings || 'Error deleting recordings', 'error');
					$("#cleanup_by_duration_btn").prop("disabled", false).html("<span class=\"btn-icon\"><i data-lucide=\"trash-2\"></i></span> " + (S.deleteShortRecordings || 'Delete Short Recordings'));
					if (typeof lucide !== 'undefined') lucide.createIcons();
				}
			});
		});

		// Delete Orphaned Files
		$("#cleanup_orphaned_files_btn").on("click", function() {
			if (!confirm(S.confirmDeleteOrphaned || 'Are you sure you want to delete all orphaned recording files? This cannot be undone.')) {
				return;
			}
			$(this).prop("disabled", true).text(S.deleting || 'Deleting...');
			$.ajax({
				url: ajaxurl,
				type: "POST",
				data: {
					action: "opti_behavior_cleanup_orphaned",
					nonce: opti_behaviorSettings.cleanupNonce
				},
				success: function(response) {
					if (response.success) {
						showOptiNotice(response.data.message || (S.orphanedFilesDeleted || 'Orphaned files deleted successfully'), 'success');
						location.reload();
					} else {
						showOptiNotice((S.errorColon || 'Error:') + ' ' + (response.data || (S.unknownError || 'Unknown error')), 'error');
						$("#cleanup_orphaned_files_btn").prop("disabled", false).html("<span class=\"btn-icon\"><i data-lucide=\"trash-2\"></i></span> " + (S.deleteOrphanedFiles || 'Delete Orphaned Files'));
						if (typeof lucide !== 'undefined') lucide.createIcons();
					}
				},
				error: function() {
					showOptiNotice(S.errorDeletingOrphaned || 'Error deleting orphaned files', 'error');
					$("#cleanup_orphaned_files_btn").prop("disabled", false).html("<span class=\"btn-icon\"><i data-lucide=\"trash-2\"></i></span> " + (S.deleteOrphanedFiles || 'Delete Orphaned Files'));
					if (typeof lucide !== 'undefined') lucide.createIcons();
				}
			});
		});

		// =========================================================
		// Spam Recalculation with Progress Bar
		// =========================================================

		// Store original spam settings values to detect changes
		var originalSpamSettings = {
			duration: $('input[name="opti_behavior_traffic[spam_duration_threshold]"]').val(),
			minScrolls: $('input[name="opti_behavior_traffic[spam_min_scrolls_threshold]"]').val(),
			minClicks: $('input[name="opti_behavior_traffic[spam_min_clicks_threshold]"]').val(),
			enabled: $('input[name="opti_behavior_traffic[spam_detection_enabled]"]').is(':checked')
		};

		// Handle traffic settings form submission
		$('button[name="opti_behavior_traffic_settings_submit"]').closest('form').on('submit', function(e) {
			// Get current values
			var currentSettings = {
				duration: $('input[name="opti_behavior_traffic[spam_duration_threshold]"]').val(),
				minScrolls: $('input[name="opti_behavior_traffic[spam_min_scrolls_threshold]"]').val(),
				minClicks: $('input[name="opti_behavior_traffic[spam_min_clicks_threshold]"]').val(),
				enabled: $('input[name="opti_behavior_traffic[spam_detection_enabled]"]').is(':checked')
			};

			// Check if spam settings changed
			var spamSettingsChanged = (
				currentSettings.duration !== originalSpamSettings.duration ||
				currentSettings.minScrolls !== originalSpamSettings.minScrolls ||
				currentSettings.minClicks !== originalSpamSettings.minClicks ||
				currentSettings.enabled !== originalSpamSettings.enabled
			);

			// If spam detection is enabled and settings changed, show progress bar
			if (currentSettings.enabled && spamSettingsChanged) {
				e.preventDefault();

				// Save settings first via AJAX, then recalculate.
				// jQuery serialize() does not include the clicked submit button, but the
				// PHP save handler uses opti_behavior_traffic_settings_submit to detect
				// this form. Add it explicitly so changed spam thresholds are persisted.
				var formData = $(this).serialize();
				if (formData.indexOf('opti_behavior_traffic_settings_submit=') === -1) {
					formData += '&opti_behavior_traffic_settings_submit=1';
				}

				// Show progress bar
				$('#spam-recalc-container').show();
				$('#spam-recalc-success').hide();

				// Disable submit button
				$('button[name="opti_behavior_traffic_settings_submit"]').prop('disabled', true).text(S.saving || 'Saving...');

				// First save the settings
				$.ajax({
					url: window.location.href,
					type: 'POST',
					data: formData,
					success: function() {
						// Settings saved, now start recalculation
						startSpamRecalculation();
					},
					error: function() {
						showOptiNotice(S.error || S.errorSavingSettings || 'Error saving settings. Please try again.', 'error');
						$('button[name="opti_behavior_traffic_settings_submit"]').prop('disabled', false).text(S.saveTrafficSettings || 'Save Traffic Settings');
						$('#spam-recalc-container').hide();
					}
				});
			}
		});

		/**
		 * Start spam recalculation process
		 */
		function startSpamRecalculation() {
			$('#spam-recalc-status').text(S.initializing || 'Initializing...');
			$('#spam-recalc-progress-bar').css('width', '0%');
			$('#spam-recalc-percent').text('0%');

			// Start the recalculation
			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_recalculate_spam',
					nonce: opti_behaviorSettings.settingsNonce,
					action_type: 'start',
					offset: 0,
					batch_size: 500
				},
				success: function(response) {
					if (response.success) {
						if (response.data.status === 'started') {
							// Begin batch processing
							processSpamBatch(0, response.data.total);
						} else if (response.data.status === 'completed') {
							// No sessions to process
							spamRecalcComplete(response.data.message);
						}
					} else {
						spamRecalcError(response.data ? response.data.message : (S.unknownError || 'Unknown error'));
					}
				},
				error: function(xhr, status, error) {
					spamRecalcError('AJAX error: ' + error);
				}
			});
		}

		/**
		 * Process a batch of sessions
		 */
		function processSpamBatch(offset, total) {
			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_recalculate_spam',
					nonce: opti_behaviorSettings.settingsNonce,
					action_type: 'recalculate',
					offset: offset,
					batch_size: 500
				},
				success: function(response) {
					if (response.success) {
						if (response.data.status === 'processing') {
							// Update progress bar
							var progress = response.data.progress;
							var processed = response.data.processed;
							var totalSessions = response.data.total;

							$('#spam-recalc-progress-bar').css('width', progress + '%');
							$('#spam-recalc-percent').text(progress + '%');
							$('#spam-recalc-status').text('Processing ' + processed.toLocaleString() + ' of ' + totalSessions.toLocaleString() + ' sessions...');

							// Process next batch
							processSpamBatch(response.data.next_offset, totalSessions);
						} else if (response.data.status === 'completed') {
							spamRecalcComplete(response.data.message);
						}
					} else {
						spamRecalcError(response.data ? response.data.message : (S.unknownError || 'Unknown error'));
					}
				},
				error: function(xhr, status, error) {
					spamRecalcError('AJAX error: ' + error);
				}
			});
		}

		/**
		 * Handle successful completion
		 */
		function spamRecalcComplete(message) {
			$('#spam-recalc-progress-bar').css('width', '100%');
			$('#spam-recalc-percent').text('100%');
			$('#spam-recalc-status').text(S.complete || 'Complete!');

			// Hide progress bar after a moment
			setTimeout(function() {
				$('#spam-recalc-container').hide();
				$('#spam-recalc-success').show();
				$('#spam-recalc-success-msg').text(message || S.completed);

				// Update original settings to current values
				originalSpamSettings = {
					duration: $('input[name="opti_behavior_traffic[spam_duration_threshold]"]').val(),
					minScrolls: $('input[name="opti_behavior_traffic[spam_min_scrolls_threshold]"]').val(),
					minClicks: $('input[name="opti_behavior_traffic[spam_min_clicks_threshold]"]').val(),
					enabled: $('input[name="opti_behavior_traffic[spam_detection_enabled]"]').is(':checked')
				};

				// Re-enable submit button
				$('button[name="opti_behavior_traffic_settings_submit"]').prop('disabled', false).text(S.saveTrafficSettings || 'Save Traffic Settings');
			}, 500);
		}

		/**
		 * Handle error
		 */
		function spamRecalcError(message) {
			$('#spam-recalc-container').hide();
			showOptiNotice((S.error || 'Error') + ' ' + message, 'error');
			$('button[name="opti_behavior_traffic_settings_submit"]').prop('disabled', false).text(S.saveTrafficSettings || 'Save Traffic Settings');
		}

		// =========================================================
		// Delete All Data with Progress Modal
		// =========================================================

		var $deleteModal = $('#opti-behavior-delete-modal');
		var $deleteBtn = $('#opti-behavior-delete-all-btn');
		var $confirmBtn = $('#opti-behavior-confirm-delete-btn');
		var $confirmStep = $('#opti-behavior-delete-confirm');
		var $progressStep = $('#opti-behavior-delete-progress');
		var $completeStep = $('#opti-behavior-delete-complete');
		var $toggleAllLink = $('#opti-behavior-toggle-all-categories');
		var $categoryChecks = $('.danger-category-check');
		var selectedCategories = [];
		var $sizeBadges = $('.danger-category-size');
		var $totalSizeEl = $('#opti-behavior-danger-total-size');

		/**
		 * Fetch per-category storage sizes (DB + files) asynchronously so the
		 * settings page paints instantly. Server response is transient-cached.
		 *
		 * @param {boolean} force Bypass the server-side cache.
		 */
		function loadDangerZoneSizes(force) {
			if (!$sizeBadges.length || typeof opti_behaviorSettings === 'undefined' || !opti_behaviorSettings.dangerSizesNonce) {
				return;
			}

			$sizeBadges.addClass('is-loading').text('…');

			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_get_danger_zone_sizes',
					nonce: opti_behaviorSettings.dangerSizesNonce,
					refresh: force ? '1' : '0'
				},
				success: function(response) {
					if (!response || !response.success || !response.data || !response.data.categories) {
						$sizeBadges.removeClass('is-loading').text('');
						return;
					}

					var categories = response.data.categories;
					$sizeBadges.removeClass('is-loading').each(function() {
						var $badge = $(this);
						var info = categories[$badge.data('category')];
						if (!info) {
							$badge.text('');
							return;
						}

						var parts = [(S.sizeDbLabel || 'DB') + ' ' + info.db_human];
						if (info.has_files) {
							parts.push((S.sizeFilesLabel || 'Files') + ' ' + info.files_human);
						}
						$badge.text(parts.join(' · '));
					});

					var totals = response.data.totals;
					if ($totalSizeEl.length && totals && totals.total_human) {
						$totalSizeEl.text(
							(S.sizeTotalLabel || 'Total storage:') + ' ' + totals.total_human +
							' (' + (S.sizeDbLabel || 'DB') + ' ' + totals.db_human +
							' · ' + (S.sizeFilesLabel || 'Files') + ' ' + totals.files_human + ')'
						);
					}
				},
				error: function() {
					// Silent fail: sizes are informational, never break the page.
					$sizeBadges.removeClass('is-loading').text('');
				}
			});
		}

		// Kick off the async size fetch once the danger-zone markup is present.
		loadDangerZoneSizes(false);

		// =========================================================
		// Global "Repair Heatmap Sync" (Danger Zone -> Smart Data Cleanup)
		// Option A: recompute-only. The AJAX handler bounds each request to
		// (by default) 100 pages ordered by page_id; `has_more` just flags
		// that more pages exist beyond that bound (it is not a resumable
		// cursor), so this is a single request per click, not a chained loop.
		// =========================================================
		var $repairBtn = $('#opti-behavior-repair-heatmap-sync-btn');
		var $repairStatus = $('#opti-behavior-repair-heatmap-sync-status');

		$repairBtn.on('click', function() {
			if ($repairBtn.prop('disabled')) { return; }
			$repairBtn.prop('disabled', true);
			$repairStatus.text(S.repairing || 'Repairing…');

			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'opti_behavior_repair_heatmap_sync',
					nonce: opti_behaviorSettings.dashboardNonce
				},
				success: function(response) {
					$repairBtn.prop('disabled', false);
					$repairStatus.text('');

					if (!response || !response.success || !response.data) {
						showOptiNotice(S.repairError || 'Error repairing heatmap sync. Please try again.', 'error');
						return;
					}

					var counts = response.data.counts || {};
					var pagesChecked = Object.keys(counts).length;
					var message = (S.repairComplete || 'Heatmap sync repaired for %d page(s).').replace('%d', pagesChecked);
					if (response.data.has_more) {
						message += ' ' + (S.repairHasMore || 'More pages remain; run again to continue.');
					}
					showOptiNotice(message, 'success');
				},
				error: function(xhr, status, error) {
					$repairBtn.prop('disabled', false);
					$repairStatus.text('');
					showOptiNotice((S.ajaxError || 'AJAX error') + ': ' + error, 'error');
				}
			});
		});

		// =========================================================
		// "Rebuild Heatmap Registry From Files" (Danger Zone -> Smart Data
		// Cleanup). Admin-triggered background pass: start/pause/resume/abort
		// AJAX actions only flip options and schedule bounded cron ticks; this
		// UI polls a progress OPTION every few seconds (zero disk cost) and
		// renders a bar + final summary. Safe to navigate away mid-run.
		// =========================================================
		var $rebuildWidget = $('#opti-behavior-heatmap-rebuild-widget');
		if ($rebuildWidget.length) {
			var $rbStart = $('#opti-behavior-heatmap-rebuild-start-btn');
			var $rbPause = $('#opti-behavior-heatmap-rebuild-pause-btn');
			var $rbResume = $('#opti-behavior-heatmap-rebuild-resume-btn');
			var $rbAbort = $('#opti-behavior-heatmap-rebuild-abort-btn');
			var $rbRow = $('#opti-behavior-heatmap-rebuild-progress-row');
			var $rbFill = $('#opti-behavior-heatmap-rebuild-progress-fill');
			var $rbText = $('#opti-behavior-heatmap-rebuild-progress-text');
			var $rbSummary = $('#opti-behavior-heatmap-rebuild-summary');
			var rbPollTimer = null;
			var RB_POLL_MS = 4000;

			function rbSummaryText(p) {
				return (S.rebuildSummary || 'Pages inserted: %1$d · spam-excluded: %2$d · bot-only skipped: %3$d · already registered: %4$d · no valid data: %5$d')
					.replace('%1$d', p.inserted || 0)
					.replace('%2$d', p.skipped_spam || 0)
					.replace('%3$d', p.skipped_bot || 0)
					.replace('%4$d', p.skipped_existing || 0)
					.replace('%5$d', p.skipped_invalid || 0);
			}

			function rbRender(p) {
				p = p || {};
				var state = p.state || 'idle';
				var total = parseInt(p.total_dirs, 10) || 0;
				var scanned = parseInt(p.scanned, 10) || 0;
				var running = (state === 'running' || state === 'pending');
				var pct = total > 0 ? Math.min(100, Math.round((scanned / total) * 100)) : (state === 'complete' ? 100 : 0);

				$rbStart.toggle(!running && state !== 'paused');
				$rbPause.toggle(running);
				$rbResume.toggle(state === 'paused');
				$rbAbort.toggle(running || state === 'paused');
				$rbRow.css('display', (running || state === 'paused' || state === 'complete' || state === 'aborted') ? 'flex' : 'none');
				$rbFill.css('width', pct + '%');

				if (running) {
					$rbText.text((S.rebuildScanning || 'Scanning %1$d of %2$d folders…')
						.replace('%1$d', scanned)
						.replace('%2$d', total > 0 ? total : '…'));
				} else if (state === 'paused') {
					$rbText.text(S.rebuildPaused || 'Paused — progress is saved.');
				} else if (state === 'aborted') {
					$rbText.text(S.rebuildAborted || 'Aborted.');
				} else if (state === 'complete') {
					$rbText.text(S.rebuildComplete || 'Rebuild complete.');
				} else {
					$rbText.text('');
				}

				var showSummary = (state === 'complete' || state === 'aborted' || running || state === 'paused') && scanned > 0;
				$rbSummary.toggle(showSummary).text(showSummary ? rbSummaryText(p) : '');

				if (running) {
					rbSchedulePoll();
				} else {
					rbStopPoll();
				}
			}

			function rbStopPoll() {
				if (rbPollTimer) {
					clearTimeout(rbPollTimer);
					rbPollTimer = null;
				}
			}

			function rbSchedulePoll() {
				rbStopPoll();
				rbPollTimer = setTimeout(rbPoll, RB_POLL_MS);
			}

			function rbPoll() {
				$.ajax({
					url: opti_behaviorSettings.ajaxUrl || ajaxurl,
					type: 'POST',
					data: {
						action: 'optibehavior_heatmap_rebuild_progress',
						nonce: opti_behaviorSettings.dashboardNonce
					},
					success: function(response) {
						if (response && response.success && response.data && response.data.progress) {
							rbRender(response.data.progress);
						} else {
							rbSchedulePoll(); // Transient hiccup: keep polling.
						}
					},
					error: function() {
						rbSchedulePoll(); // Network blip: keep polling.
					}
				});
			}

			function rbAction(action, extra, onDone) {
				$.ajax({
					url: opti_behaviorSettings.ajaxUrl || ajaxurl,
					type: 'POST',
					data: $.extend({
						action: action,
						nonce: opti_behaviorSettings.dashboardNonce
					}, extra || {}),
					success: function(response) {
						if (response && response.success && response.data && response.data.progress) {
							rbRender(response.data.progress);
							if (onDone) { onDone(true); }
							return;
						}
						var msg = (response && response.data && response.data.message) ? response.data.message : (S.rebuildError || 'Rebuild request failed. Please try again.');
						showOptiNotice(msg, 'error');
						if (onDone) { onDone(false); }
					},
					error: function(xhr, status, error) {
						showOptiNotice((S.ajaxError || 'AJAX error') + ': ' + error, 'error');
						if (onDone) { onDone(false); }
					}
				});
			}

			$rbStart.on('click', function() {
				if (!window.confirm(S.rebuildConfirmStart || 'Start rebuilding the heatmap registry from files? This runs in the background and never deletes any data.')) {
					return;
				}
				$rbStart.prop('disabled', true);
				$rbSummary.hide().text('');
				rbAction('optibehavior_heatmap_rebuild_start', {}, function() {
					$rbStart.prop('disabled', false);
					rbSchedulePoll();
				});
			});

			$rbPause.on('click', function() {
				rbAction('optibehavior_heatmap_rebuild_control', { op: 'pause' });
			});

			$rbResume.on('click', function() {
				rbAction('optibehavior_heatmap_rebuild_control', { op: 'resume' }, function(ok) {
					if (ok) { rbSchedulePoll(); }
				});
			});

			$rbAbort.on('click', function() {
				if (!window.confirm(S.rebuildConfirmAbort || 'Abort the rebuild? Progress made so far is kept; a new start rescans from the beginning.')) {
					return;
				}
				rbAction('optibehavior_heatmap_rebuild_control', { op: 'abort' });
			});

			// Initial state from the server-rendered snapshot; resumes polling
			// automatically when a pass is already running in the background.
			var rbInitial = {};
			try {
				rbInitial = JSON.parse($rebuildWidget.attr('data-progress') || '{}');
			} catch (e) {
				rbInitial = {};
			}
			rbRender(rbInitial);
		}

		// =========================================================
		// "Archived Heatmap Data Report" (`_orphaned/` dry-run, Option C
		// phase 1). REPORT-ONLY background pass: same start/pause/resume/abort
		// + option-polling shape as the rebuild widget above, but nothing is
		// ever restored, moved, or written to the database. Renders the final
		// report (eligible dirs, session totals, unknown share, URL sample).
		// =========================================================
		var $orWidget = $('#opti-behavior-heatmap-orphan-report-widget');
		if ($orWidget.length) {
			var $orStart = $('#opti-behavior-heatmap-orphan-report-start-btn');
			var $orPause = $('#opti-behavior-heatmap-orphan-report-pause-btn');
			var $orResume = $('#opti-behavior-heatmap-orphan-report-resume-btn');
			var $orAbort = $('#opti-behavior-heatmap-orphan-report-abort-btn');
			var $orRow = $('#opti-behavior-heatmap-orphan-report-progress-row');
			var $orFill = $('#opti-behavior-heatmap-orphan-report-progress-fill');
			var $orText = $('#opti-behavior-heatmap-orphan-report-progress-text');
			var $orSummary = $('#opti-behavior-heatmap-orphan-report-summary');
			var $orSample = $('#opti-behavior-heatmap-orphan-report-sample');
			var orPollTimer = null;
			var OR_POLL_MS = 4000;

			function orSummaryText(p) {
				var sessionsTotal = parseInt(p.sessions_total, 10) || 0;
				var sessionsUnknown = parseInt(p.sessions_unknown, 10) || 0;
				var unknownPct = sessionsTotal > 0 ? Math.round((sessionsUnknown / sessionsTotal) * 100) : 0;
				return (S.orphanSummary || 'Eligible for restore: %1$d folders · still live (skipped): %2$d · unreadable: %3$d · sessions: %4$d (%5$d%% unknown to the database)')
					.replace('%1$d', p.eligible || 0)
					.replace('%2$d', p.skipped_surviving || 0)
					.replace('%3$d', p.skipped_invalid || 0)
					.replace('%4$d', sessionsTotal)
					.replace('%5$d', unknownPct);
			}

			function orRenderSample(p) {
				var sample = (p && p.sample && p.sample.length) ? p.sample : [];
				$orSample.empty();
				if (!sample.length) {
					$orSample.hide();
					return;
				}
				var $title = $('<p class="smart-cleanup-description" style="margin-bottom:4px;"></p>')
					.text(S.orphanSampleTitle || 'Sample of restorable pages:');
				var $list = $('<ul style="margin:0 0 0 18px;list-style:disc;"></ul>');
				$.each(sample, function(i, item) {
					var line = (S.orphanSampleItem || '%1$s — %2$d session(s), %3$d unknown')
						.replace('%1$s', String(item.url || item.dir || ''))
						.replace('%2$d', parseInt(item.sessions, 10) || 0)
						.replace('%3$d', parseInt(item.unknown, 10) || 0);
					$list.append($('<li class="smart-cleanup-description" style="margin:0;"></li>').text(line));
				});
				$orSample.append($title).append($list).show();
			}

			function orRender(p) {
				p = p || {};
				var state = p.state || 'idle';
				var total = parseInt(p.total_dirs, 10) || 0;
				var scanned = parseInt(p.scanned, 10) || 0;
				var running = (state === 'running' || state === 'pending');
				var pct = total > 0 ? Math.min(100, Math.round((scanned / total) * 100)) : (state === 'complete' ? 100 : 0);

				$orStart.toggle(!running && state !== 'paused');
				$orPause.toggle(running);
				$orResume.toggle(state === 'paused');
				$orAbort.toggle(running || state === 'paused');
				$orRow.css('display', (running || state === 'paused' || state === 'complete' || state === 'aborted') ? 'flex' : 'none');
				$orFill.css('width', pct + '%');

				if (running) {
					$orText.text((S.orphanScanning || 'Scanning %1$d of %2$d archived folders…')
						.replace('%1$d', scanned)
						.replace('%2$d', total > 0 ? total : '…'));
				} else if (state === 'paused') {
					$orText.text(S.orphanPaused || 'Paused — progress is saved.');
				} else if (state === 'aborted') {
					$orText.text(S.orphanAborted || 'Aborted.');
				} else if (state === 'complete') {
					$orText.text(S.orphanComplete || 'Archive scan complete. Nothing was restored or modified.');
				} else {
					$orText.text('');
				}

				var showSummary = (state === 'complete' || state === 'aborted' || running || state === 'paused') && scanned > 0;
				$orSummary.toggle(showSummary).text(showSummary ? orSummaryText(p) : '');
				if (showSummary) {
					orRenderSample(p);
				} else {
					$orSample.hide().empty();
				}

				if (running) {
					orSchedulePoll();
				} else {
					orStopPoll();
				}
			}

			function orStopPoll() {
				if (orPollTimer) {
					clearTimeout(orPollTimer);
					orPollTimer = null;
				}
			}

			function orSchedulePoll() {
				orStopPoll();
				orPollTimer = setTimeout(orPoll, OR_POLL_MS);
			}

			function orPoll() {
				$.ajax({
					url: opti_behaviorSettings.ajaxUrl || ajaxurl,
					type: 'POST',
					data: {
						action: 'optibehavior_heatmap_orphan_report_progress',
						nonce: opti_behaviorSettings.dashboardNonce
					},
					success: function(response) {
						if (response && response.success && response.data && response.data.progress) {
							orRender(response.data.progress);
						} else {
							orSchedulePoll(); // Transient hiccup: keep polling.
						}
					},
					error: function() {
						orSchedulePoll(); // Network blip: keep polling.
					}
				});
			}

			function orAction(action, extra, onDone) {
				$.ajax({
					url: opti_behaviorSettings.ajaxUrl || ajaxurl,
					type: 'POST',
					data: $.extend({
						action: action,
						nonce: opti_behaviorSettings.dashboardNonce
					}, extra || {}),
					success: function(response) {
						if (response && response.success && response.data && response.data.progress) {
							orRender(response.data.progress);
							if (onDone) { onDone(true); }
							return;
						}
						var msg = (response && response.data && response.data.message) ? response.data.message : (S.orphanError || 'Archive scan request failed. Please try again.');
						showOptiNotice(msg, 'error');
						if (onDone) { onDone(false); }
					},
					error: function(xhr, status, error) {
						showOptiNotice((S.ajaxError || 'AJAX error') + ': ' + error, 'error');
						if (onDone) { onDone(false); }
					}
				});
			}

			$orStart.on('click', function() {
				if (!window.confirm(S.orphanConfirmStart || 'Scan the archived heatmap data? This is a report only — it runs in the background and never restores, moves, or deletes anything.')) {
					return;
				}
				$orStart.prop('disabled', true);
				$orSummary.hide().text('');
				$orSample.hide().empty();
				orAction('optibehavior_heatmap_orphan_report_start', {}, function() {
					$orStart.prop('disabled', false);
					orSchedulePoll();
				});
			});

			$orPause.on('click', function() {
				orAction('optibehavior_heatmap_orphan_report_control', { op: 'pause' });
			});

			$orResume.on('click', function() {
				orAction('optibehavior_heatmap_orphan_report_control', { op: 'resume' }, function(ok) {
					if (ok) { orSchedulePoll(); }
				});
			});

			$orAbort.on('click', function() {
				if (!window.confirm(S.orphanConfirmAbort || 'Abort the archive scan? The partial report is kept; a new scan starts from the beginning.')) {
					return;
				}
				orAction('optibehavior_heatmap_orphan_report_control', { op: 'abort' });
			});

			// Initial state from the server-rendered snapshot; resumes polling
			// automatically when a pass is already running in the background.
			var orInitial = {};
			try {
				orInitial = JSON.parse($orWidget.attr('data-progress') || '{}');
			} catch (e) {
				orInitial = {};
			}
			orRender(orInitial);
		}

		// =========================================================
		// "Restore Archived Heatmap Data" (`_orphaned/` restore, Phase C
		// recovery tooling). Client-driven batched loop: each AJAX request
		// moves a bounded number of archive folders back into the live tree
		// and returns the persisted progress; the loop repeats until the
		// server reports the pass complete. The server-side cursor makes the
		// pass resumable — restarting after an interruption continues where
		// it stopped. On completion the SERVER marks restored pages stale,
		// flushes caches, and starts the registry rebuild.
		// =========================================================
		var $resWidget = $('#opti-behavior-heatmap-orphan-restore-widget');
		if ($resWidget.length) {
			var $resStart = $('#opti-behavior-heatmap-orphan-restore-start-btn');
			var $resRow = $('#opti-behavior-heatmap-orphan-restore-progress-row');
			var $resFill = $('#opti-behavior-heatmap-orphan-restore-progress-fill');
			var $resText = $('#opti-behavior-heatmap-orphan-restore-progress-text');
			var $resSummary = $('#opti-behavior-heatmap-orphan-restore-summary');

			function resSummaryText(p) {
				return (S.orphanRestoreSummary || 'Folders restored: %1$d · merged into live data: %2$d · files moved: %3$d · files kept live (skipped): %4$d · failed: %5$d')
					.replace('%1$d', parseInt(p.dirs_restored, 10) || 0)
					.replace('%2$d', parseInt(p.dirs_merged, 10) || 0)
					.replace('%3$d', parseInt(p.files_restored, 10) || 0)
					.replace('%4$d', parseInt(p.files_skipped, 10) || 0)
					.replace('%5$d', parseInt(p.failed, 10) || 0);
			}

			function resRender(p, running) {
				p = p || {};
				var state = p.state || 'idle';
				var total = parseInt(p.total_dirs, 10) || 0;
				var scanned = parseInt(p.scanned, 10) || 0;
				var pct = total > 0 ? Math.min(100, Math.round((scanned / total) * 100)) : (state === 'complete' ? 100 : 0);

				$resRow.css('display', (running || state === 'running' || state === 'complete') ? 'flex' : 'none');
				$resFill.css('width', pct + '%');

				if (running) {
					$resText.text((S.orphanRestoreRunning || 'Restoring %1$d of %2$d archived folders…')
						.replace('%1$d', scanned)
						.replace('%2$d', total > 0 ? total : '…'));
				} else if (state === 'complete') {
					$resText.text(S.orphanRestoreComplete || 'Restore complete. Restored pages are being refreshed and the registry rebuild has started.');
				} else {
					$resText.text('');
				}

				var showSummary = scanned > 0 && (running || state === 'complete' || state === 'running');
				$resSummary.toggle(showSummary).text(showSummary ? resSummaryText(p) : '');
			}

			function resFail(message) {
				$resStart.prop('disabled', false);
				showOptiNotice(message || S.orphanRestoreError || 'Archive restore request failed. Please try again.', 'error');
			}

			function resBatch(op) {
				$.ajax({
					url: opti_behaviorSettings.ajaxUrl || ajaxurl,
					type: 'POST',
					data: {
						action: 'optibehavior_heatmap_orphan_restore_batch',
						nonce: opti_behaviorSettings.dashboardNonce,
						op: op
					},
					success: function(response) {
						if (!response || !response.success || !response.data || !response.data.result) {
							resFail(response && response.data && response.data.message ? response.data.message : null);
							return;
						}
						var result = response.data.result;
						var progress = response.data.progress || {};
						if (result.locked) {
							// Another maintenance pass holds the lock — retry shortly.
							$resText.text(S.orphanRestoreWaiting || 'Waiting for another maintenance task to finish…');
							setTimeout(function() { resBatch('batch'); }, 5000);
							return;
						}
						if (result.complete) {
							resRender(progress, false);
							$resStart.prop('disabled', false);
							showOptiNotice(S.orphanRestoreComplete || 'Restore complete. Restored pages are being refreshed and the registry rebuild has started.', 'success');
							return;
						}
						resRender(progress, true);
						resBatch('batch');
					},
					error: function(xhr, status, error) {
						resFail((S.ajaxError || 'AJAX error') + ': ' + error);
					}
				});
			}

			$resStart.on('click', function() {
				if (!window.confirm(S.orphanRestoreConfirm || 'Restore the archived heatmap data back into the live data directory? Existing live files are never overwritten, and nothing is deleted. This may take a while on large archives.')) {
					return;
				}
				$resStart.prop('disabled', true);
				$resSummary.hide().text('');
				resRender({ state: 'running' }, true);
				resBatch('start');
			});

			// Initial state from the server-rendered snapshot (e.g. a completed
			// or interrupted earlier pass).
			var resInitial = {};
			try {
				resInitial = JSON.parse($resWidget.attr('data-progress') || '{}');
			} catch (e) {
				resInitial = {};
			}
			resRender(resInitial, false);
		}

		// =========================================================
		// "Re-evaluate Spam Flags" (Phase C recovery tooling). Client-driven
		// batched loop over sessions flagged spam for `few_clicks`: each AJAX
		// request re-classifies one bounded batch through the canonical
		// classifier and returns a compound (time, id) cursor; the loop chains
		// requests until the scan is exhausted. Downgrades to human only —
		// never deletes anything.
		// =========================================================
		var $rsWidget = $('#opti-behavior-reevaluate-spam-widget');
		if ($rsWidget.length) {
			var $rsStart = $('#opti-behavior-reevaluate-spam-start-btn');
			var $rsRow = $('#opti-behavior-reevaluate-spam-progress-row');
			var $rsFill = $('#opti-behavior-reevaluate-spam-progress-fill');
			var $rsText = $('#opti-behavior-reevaluate-spam-progress-text');
			var $rsSummary = $('#opti-behavior-reevaluate-spam-summary');
			var rsTotals = { total: 0, processed: 0, changed: 0 };

			function rsRenderProgress() {
				var pct = rsTotals.total > 0 ? Math.min(100, Math.round((rsTotals.processed / rsTotals.total) * 100)) : 0;
				$rsRow.css('display', 'flex');
				$rsFill.css('width', pct + '%');
				$rsText.text((S.reevalSpamProcessing || 'Re-evaluating %1$d of %2$d flagged sessions…')
					.replace('%1$d', rsTotals.processed)
					.replace('%2$d', rsTotals.total > 0 ? rsTotals.total : '…'));
			}

			function rsFail(message) {
				$rsStart.prop('disabled', false);
				showOptiNotice(message || S.reevalSpamError || 'Spam re-evaluation request failed. Please try again.', 'error');
			}

			function rsBatch(op, cursor) {
				var data = {
					action: 'optibehavior_reevaluate_spam_flags',
					nonce: opti_behaviorSettings.dashboardNonce,
					op: op
				};
				if (cursor && cursor.time && cursor.id) {
					data.cursor_time = cursor.time;
					data.cursor_id = cursor.id;
				}
				$.ajax({
					url: opti_behaviorSettings.ajaxUrl || ajaxurl,
					type: 'POST',
					data: data,
					success: function(response) {
						if (!response || !response.success || !response.data) {
							rsFail(response && response.data && response.data.message ? response.data.message : null);
							return;
						}
						var d = response.data;
						if (typeof d.total !== 'undefined') {
							rsTotals.total = parseInt(d.total, 10) || 0;
						}
						rsTotals.processed += parseInt(d.processed, 10) || 0;
						rsTotals.changed += parseInt(d.changed, 10) || 0;
						rsRenderProgress();
						if (d.done) {
							$rsFill.css('width', '100%');
							$rsText.text(S.reevalSpamComplete || 'Re-evaluation complete.');
							$rsSummary.show().text((S.reevalSpamSummary || 'Sessions re-checked: %1$d · restored to human: %2$d')
								.replace('%1$d', rsTotals.processed)
								.replace('%2$d', rsTotals.changed));
							$rsStart.prop('disabled', false);
							showOptiNotice((S.reevalSpamSummary || 'Sessions re-checked: %1$d · restored to human: %2$d')
								.replace('%1$d', rsTotals.processed)
								.replace('%2$d', rsTotals.changed), 'success');
							return;
						}
						rsBatch('batch', d.cursor);
					},
					error: function(xhr, status, error) {
						rsFail((S.ajaxError || 'AJAX error') + ': ' + error);
					}
				});
			}

			$rsStart.on('click', function() {
				if (!window.confirm(S.reevalSpamConfirm || 'Re-evaluate the sessions flagged as spam for having too few clicks? Sessions with real click evidence are restored to human; nothing is deleted.')) {
					return;
				}
				$rsStart.prop('disabled', true);
				$rsSummary.hide().text('');
				rsTotals = { total: 0, processed: 0, changed: 0 };
				rsRenderProgress();
				rsBatch('start', null);
			});
		}

		// =========================================================
		// "Archived Heatmap Data Retention" (Danger Zone). Saving only writes
		// the retention option and re-arms/clears the daily purge cron —
		// nothing is scanned or deleted from this request.
		// =========================================================
		var $opSaveBtn = $('#opti-behavior-heatmap-orphan-purge-save-btn');
		if ($opSaveBtn.length) {
			$opSaveBtn.on('click', function() {
				var days = parseInt($('#sc-orphan-purge-retention').val(), 10);
				if (isNaN(days) || days < 0) {
					days = 0;
				}
				var $opStatus = $('#opti-behavior-heatmap-orphan-purge-status');
				$opSaveBtn.prop('disabled', true);
				$.ajax({
					url: opti_behaviorSettings.ajaxUrl || ajaxurl,
					type: 'POST',
					data: {
						action: 'optibehavior_heatmap_orphan_purge_save',
						nonce: opti_behaviorSettings.dashboardNonce,
						days: days
					},
					success: function(response) {
						$opSaveBtn.prop('disabled', false);
						if (response && response.success && response.data) {
							$('#sc-orphan-purge-retention').val(parseInt(response.data.days, 10) || 0);
							showOptiNotice(S.orphanPurgeSaved || 'Archive retention setting saved.', 'success');
							$opStatus.text('');
							return;
						}
						var msg = (response && response.data && response.data.message) ? response.data.message : (S.orphanPurgeError || 'Could not save the archive retention setting. Please try again.');
						showOptiNotice(msg, 'error');
					},
					error: function(xhr, status, error) {
						$opSaveBtn.prop('disabled', false);
						showOptiNotice((S.ajaxError || 'AJAX error') + ': ' + error, 'error');
					}
				});
			});
		}

		// =========================================================
		// "Data Retention" master setting (Danger Zone). Saving only writes
		// the retention options — the daily cleanup crons pick them up on
		// their next run; nothing is scanned or deleted from this request.
		// =========================================================
		var $drSaveBtn = $('#opti-behavior-data-retention-save-btn');
		if ($drSaveBtn.length) {
			$drSaveBtn.on('click', function() {
				var days = parseInt($('#sc-data-retention-days').val(), 10);
				if (isNaN(days) || days < 0) {
					days = 0;
				}
				var insightsMonths = parseInt($('#sc-insights-prune-months').val(), 10);
				if (isNaN(insightsMonths) || insightsMonths < 0) {
					insightsMonths = 0;
				}
				// Tiered Retention (round 2) fields. Client-side clamp of the
				// files ≤ raw invariant mirrors the authoritative server-side
				// validation (Opti_Behavior_Retention_Policy::validate_tiers()).
				var filesDays = parseInt($('#sc-files-retention-days').val(), 10);
				if (isNaN(filesDays) || filesDays < 0) {
					filesDays = 0;
				}
				if (days > 0 && filesDays > days) {
					filesDays = days;
					$('#sc-files-retention-days').val(filesDays);
					showOptiNotice(S.filesRetentionClamped || 'Heavy-files retention cannot exceed the detailed-data window — value adjusted.', 'warning');
				}
				var spamDaily = $('#sc-spam-daily-enabled').is(':checked') ? 1 : 0;
				var aggMonths = parseInt($('#sc-aggregates-retention-months').val(), 10);
				if (isNaN(aggMonths) || aggMonths < 0) {
					aggMonths = 0;
				}
				var $drStatus = $('#opti-behavior-data-retention-status');
				$drSaveBtn.prop('disabled', true);
				$.ajax({
					url: opti_behaviorSettings.ajaxUrl || ajaxurl,
					type: 'POST',
					data: {
						action: 'optibehavior_data_retention_save',
						nonce: opti_behaviorSettings.dashboardNonce,
						days: days,
						insights_months: insightsMonths,
						spam_daily: spamDaily,
						files_days: filesDays,
						aggregates_months: aggMonths
					},
					success: function(response) {
						$drSaveBtn.prop('disabled', false);
						if (response && response.success && response.data) {
							$('#sc-data-retention-days').val(parseInt(response.data.days, 10) || 0);
							$('#sc-insights-prune-months').val(parseInt(response.data.insights_months, 10) || 0);
							if (typeof response.data.files_days !== 'undefined') {
								$('#sc-files-retention-days').val(parseInt(response.data.files_days, 10) || 0);
							}
							if (typeof response.data.spam_daily !== 'undefined') {
								$('#sc-spam-daily-enabled').prop('checked', !!parseInt(response.data.spam_daily, 10));
							}
							if (typeof response.data.aggregates_months !== 'undefined') {
								var savedAgg = parseInt(response.data.aggregates_months, 10) || 0;
								$('#sc-aggregates-retention-months').val(savedAgg);
								var $aggLabel = $('#sc-aggregates-retention-label');
								if ($aggLabel.length) {
									$aggLabel.text(savedAgg > 0 ? (S.aggregatesMonths || '%d months').replace('%d', savedAgg) : (S.aggregatesForever || 'Kept forever'));
								}
							}
							showOptiNotice(S.dataRetentionSaved || 'Data retention setting saved.', 'success');
							$drStatus.text('');
							return;
						}
						var msg = (response && response.data && response.data.message) ? response.data.message : (S.dataRetentionError || 'Could not save the data retention setting. Please try again.');
						showOptiNotice(msg, 'error');
					},
					error: function(xhr, status, error) {
						$drSaveBtn.prop('disabled', false);
						showOptiNotice((S.ajaxError || 'AJAX error') + ': ' + error, 'error');
					}
				});
			});
		}

		// Open modal when delete button is clicked
		$deleteBtn.on('click', function() {
			showDeleteModal();
		});

		// Close modal handlers
		$deleteModal.on('click', '.opti-behavior-modal-close, .opti-behavior-modal-cancel, .opti-behavior-modal-overlay', function(e) {
			if ($(e.target).is('.opti-behavior-modal-overlay') || $(e.target).is('.opti-behavior-modal-close') || $(e.target).is('.opti-behavior-modal-cancel')) {
				hideDeleteModal();
			}
		});

		// Done button after completion
		$deleteModal.on('click', '.opti-behavior-modal-done', function() {
			location.reload();
		});

		// Start deletion when confirm button is clicked
		$confirmBtn.on('click', function() {
			startDeletion();
		});

		// Select All / Deselect All toggle
		$toggleAllLink.on('click', function(e) {
			e.preventDefault();
			var allChecked = $categoryChecks.filter(':checked').length === $categoryChecks.length;
			$categoryChecks.prop('checked', !allChecked);
			updateDeleteButtonState();
		});

		// Update state whenever a category checkbox changes
		$categoryChecks.on('change', function() {
			updateDeleteButtonState();
		});

		/**
		 * Update delete button state based on checked categories
		 */
		function updateDeleteButtonState() {
			var checkedCount = $categoryChecks.filter(':checked').length;
			var allChecked = checkedCount === $categoryChecks.length;

			// Update toggle link text
			$toggleAllLink.text(allChecked ? (S.deselectAll || 'Deselect All') : (S.selectAll || 'Select All'));

			// Disable button when nothing is checked
			$deleteBtn.prop('disabled', checkedCount === 0);

			// Update button label while preserving the icon element
			if (checkedCount > 0) {
				var btnText = allChecked ? (S.deleteAllData || 'Delete All Analytics Data') : (S.deleteSelectedData || 'Delete Selected Data');
				$deleteBtn.contents().filter(function() {
					return this.nodeType === 3; // text nodes only
				}).last().replaceWith(document.createTextNode(' ' + btnText));
			}
		}

		/**
		 * Show the delete modal
		 */
		function showDeleteModal() {
			var checkedCount = $categoryChecks.filter(':checked').length;
			var allChecked = checkedCount === $categoryChecks.length;

			// Populate modal category list with checked categories
			var $categoryList = $('#opti-behavior-delete-category-list');
			$categoryList.empty();
			$categoryChecks.filter(':checked').each(function() {
				var label = $(this).closest('label').find('.condition-label strong').text();
				$categoryList.append('<li>' + label + '</li>');
			});

			// Update modal warning body text
			var warningText = allChecked
				? (S.warningDeleteAll || 'This will permanently delete ALL analytics data from your database. This action cannot be undone.')
				: (S.warningDeleteSelected || 'This will permanently delete the selected analytics data from your database. This action cannot be undone.');
			$('#opti-behavior-modal-warning-body').text(warningText);

			// Update confirm button text (preserve icon)
			var confirmText = allChecked ? (S.confirmDeleteAll || 'Yes, Delete All Data') : (S.confirmDeleteSelected || 'Yes, Delete Selected Data');
			$confirmBtn.contents().filter(function() {
				return this.nodeType === 3; // text nodes only
			}).last().replaceWith(document.createTextNode(' ' + confirmText));

			// Reset to confirm step
			$confirmStep.show();
			$progressStep.hide();
			$completeStep.hide();
			$deleteModal.fadeIn(200);
			// Reinitialize Lucide icons in modal
			if (typeof lucide !== 'undefined') {
				lucide.createIcons();
			}
		}

		/**
		 * Hide the delete modal
		 */
		function hideDeleteModal() {
			$deleteModal.fadeOut(200);
		}

		/**
		 * Start the deletion process
		 */
		function startDeletion() {
			// Collect checked category keys before starting AJAX loop
			selectedCategories = [];
			$categoryChecks.filter(':checked').each(function() {
				selectedCategories.push($(this).data('category'));
			});

			// Show progress step
			$confirmStep.hide();
			$progressStep.show();
			$completeStep.hide();

			// Reset progress
			$('#opti-behavior-delete-progress-bar').css('width', '0%');
			$('#opti-behavior-delete-percent').text('0%');
			$('#opti-behavior-delete-status').text(S.initializing || 'Initializing...');

			// Start processing
			processDeleteStep(0);
		}

		/**
		 * Process deletion step by step
		 */
		function processDeleteStep(step) {
			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_delete_all_data',
					nonce: opti_behaviorSettings.deleteAllNonce,
					step: step,
					categories: selectedCategories
				},
				success: function(response) {
					if (response.success) {
						if (response.data.status === 'processing') {
							// Update progress
							$('#opti-behavior-delete-progress-bar').css('width', response.data.progress + '%');
							$('#opti-behavior-delete-percent').text(response.data.progress + '%');
							$('#opti-behavior-delete-status').text(response.data.message);

							// Process next step
							processDeleteStep(response.data.step);
						} else if (response.data.status === 'completed') {
							// Show completion
							deletionComplete(response.data.message);
						}
					} else {
						deletionError(response.data ? response.data.message : (S.unknownError || 'Unknown error'));
					}
				},
				error: function(xhr, status, error) {
					deletionError('AJAX error: ' + error);
				}
			});
		}

		/**
		 * Handle successful completion
		 */
		function deletionComplete(message) {
			$('#opti-behavior-delete-progress-bar').css('width', '100%');
			$('#opti-behavior-delete-percent').text('100%');
			$('#opti-behavior-delete-status').text(S.complete || 'Complete!');

			// Refresh storage size badges behind the modal (cache was invalidated server-side).
			loadDangerZoneSizes(true);

			setTimeout(function() {
				$progressStep.hide();
				$completeStep.show();
				$('#opti-behavior-delete-result').text(message || opti_behaviorSettings.strings.deleteComplete);
				// Reinitialize Lucide icons
				if (typeof lucide !== 'undefined') {
					lucide.createIcons();
				}
			}, 500);
		}

		/**
		 * Handle deletion error
		 */
		function deletionError(message) {
			hideDeleteModal();
			showOptiNotice((opti_behaviorSettings.strings.deleteError || 'Delete error') + ' ' + message, 'error');
		}

		// =========================================================
		// Delete Data by Date Range with Progress Modal
		// =========================================================

		var $rangeModal = $('#opti-behavior-delete-range-modal');
		var $rangeBtn = $('#opti-behavior-delete-range-btn');
		var $rangeConfirmBtn = $('#opti-behavior-confirm-range-delete-btn');
		var $rangeConfirmStep = $('#opti-behavior-range-confirm');
		var $rangeProgressStep = $('#opti-behavior-range-progress');
		var $rangeCompleteStep = $('#opti-behavior-range-complete');
		var $rangeStartInput = $('#opti-behavior-range-start');
		var $rangeEndInput = $('#opti-behavior-range-end');

		// Open modal when date range delete button is clicked
		$rangeBtn.on('click', function() {
			showRangeModal();
		});

		// Close modal handlers
		$rangeModal.on('click', '.opti-behavior-modal-close, .opti-behavior-modal-cancel, .opti-behavior-modal-overlay', function(e) {
			if ($(e.target).is('.opti-behavior-modal-overlay') || $(e.target).is('.opti-behavior-modal-close') || $(e.target).is('.opti-behavior-modal-cancel')) {
				hideRangeModal();
			}
		});

		// Done button after completion
		$rangeModal.on('click', '.opti-behavior-modal-done', function() {
			location.reload();
		});

		// Start deletion when confirm button is clicked
		$rangeConfirmBtn.on('click', function() {
			startRangeDeletion();
		});

		/**
		 * Show the date range delete modal
		 */
		function showRangeModal() {
			var startDate = $rangeStartInput.val();
			var endDate = $rangeEndInput.val();

			// Validate date range
			if (!startDate || !endDate) {
				showOptiNotice(opti_behaviorSettings.strings.invalidDateRange || 'Invalid date range', 'warning');
				return;
			}

			if (new Date(startDate) > new Date(endDate)) {
				showOptiNotice(opti_behaviorSettings.strings.invalidDateRange || 'Invalid date range', 'warning');
				return;
			}

			// Format dates for display
			var startFormatted = formatDate(startDate);
			var endFormatted = formatDate(endDate);

			$('#opti-behavior-range-display-start').text(startFormatted);
			$('#opti-behavior-range-display-end').text(endFormatted);

			// Reset to confirm step
			$rangeConfirmStep.show();
			$rangeProgressStep.hide();
			$rangeCompleteStep.hide();
			$rangeModal.fadeIn(200);

			// Reinitialize Lucide icons in modal
			if (typeof lucide !== 'undefined') {
				lucide.createIcons();
			}
		}

		/**
		 * Hide the date range modal
		 */
		function hideRangeModal() {
			$rangeModal.fadeOut(200);
		}

		/**
		 * Format date for display
		 */
		function formatDate(dateStr) {
			var date = new Date(dateStr);
			var options = { year: 'numeric', month: 'short', day: 'numeric' };
			return date.toLocaleDateString(undefined, options);
		}

		/**
		 * Start the date range deletion process
		 */
		function startRangeDeletion() {
			// Show progress step
			$rangeConfirmStep.hide();
			$rangeProgressStep.show();
			$rangeCompleteStep.hide();

			// Reset progress
			$('#opti-behavior-range-progress-bar').css('width', '0%');
			$('#opti-behavior-range-percent').text('0%');
			$('#opti-behavior-range-status').text(S.initializing || 'Initializing...');

			// Start processing
			processRangeDeleteStep(0);
		}

		/**
		 * Process date range deletion step by step
		 */
		function processRangeDeleteStep(step) {
			var startDate = $rangeStartInput.val();
			var endDate = $rangeEndInput.val();

			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_delete_data_by_range',
					nonce: opti_behaviorSettings.deleteRangeNonce,
					step: step,
					start_date: startDate,
					end_date: endDate
				},
				success: function(response) {
					if (response.success) {
						if (response.data.status === 'processing') {
							// Update progress
							$('#opti-behavior-range-progress-bar').css('width', response.data.progress + '%');
							$('#opti-behavior-range-percent').text(response.data.progress + '%');
							$('#opti-behavior-range-status').text(response.data.message);

							// Process next step
							processRangeDeleteStep(response.data.step);
						} else if (response.data.status === 'completed') {
							// Show completion
							rangeDeletionComplete(response.data.message);
						}
					} else {
						rangeDeletionError(response.data ? response.data.message : (S.unknownError || 'Unknown error'));
					}
				},
				error: function(xhr, status, error) {
					rangeDeletionError('AJAX error: ' + error);
				}
			});
		}

		/**
		 * Handle successful date range deletion completion
		 */
		function rangeDeletionComplete(message) {
			$('#opti-behavior-range-progress-bar').css('width', '100%');
			$('#opti-behavior-range-percent').text('100%');
			$('#opti-behavior-range-status').text(S.complete || 'Complete!');

			setTimeout(function() {
				$rangeProgressStep.hide();
				$rangeCompleteStep.show();
				$('#opti-behavior-range-result').text(message);
				// Reinitialize Lucide icons
				if (typeof lucide !== 'undefined') {
					lucide.createIcons();
				}
			}, 500);
		}

		/**
		 * Handle date range deletion error
		 */
		function rangeDeletionError(message) {
			hideRangeModal();
			showOptiNotice((opti_behaviorSettings.strings.deleteError || 'Delete error') + ' ' + message, 'error');
		}

		// =========================================================
		// Smart Data Cleanup
		// =========================================================

		var $botCleanupBtn = $('#opti-behavior-bot-cleanup-btn');
		var $botModal = $('#opti-behavior-bot-cleanup-modal');
		var $smartPreviewBtn = $('#opti-behavior-smart-preview-btn');
		var $smartDeleteBtn = $('#opti-behavior-smart-delete-btn');
		var $smartModal = $('#opti-behavior-smart-cleanup-modal');
		var $saveConditionsBtn = $('#opti-behavior-save-conditions-btn');
		var $saveScheduleBtn = $('#opti-behavior-save-schedule-btn');
		var lastSmartPreview = null;

		// Enable/disable condition inputs based on checkbox state
		$('.condition-check').on('change', function() {
			var $row = $(this).closest('.condition-row');
			var isChecked = $(this).is(':checked');
			$row.find('.condition-input').prop('disabled', !isChecked);
			resetSmartPreview();
		});

		$('.condition-input, #sc-delete-orphans').on('input change', function() {
			resetSmartPreview();
		});

		// Auto-cleanup toggle
		$('#sc-auto-enabled').on('change', function() {
			var enabled = $(this).is(':checked');
			$('#sc-frequency-group, #sc-schedule-limits-group').css({
				'opacity': enabled ? 1 : 0.5,
				'pointer-events': enabled ? 'auto' : 'none'
			});
		});

		/**
		 * Collect active conditions from the form
		 */
		function collectConditions() {
			var conditions = {};

			$('.condition-check:checked').each(function() {
				var conditionKey = $(this).data('condition');
				if (!conditionKey) return;

				switch (conditionKey) {
					case 'older_than_days':
						conditions.older_than_days = parseInt($('#sc-older-than-days').val()) || 0;
						break;
					case 'inactive_visitor_days':
						conditions.inactive_visitor_days = parseInt($('#sc-inactive-days').val()) || 0;
						break;
					case 'min_duration':
						conditions.min_duration = parseInt($('#sc-min-duration').val()) || 0;
						conditions.min_duration_age_days = parseInt($('#sc-min-duration-age').val()) || 0;
						break;
					case 'max_duration':
						conditions.max_duration = parseInt($('#sc-max-duration').val()) || 0;
						conditions.max_duration_age_days = parseInt($('#sc-max-duration-age').val()) || 0;
						break;
					case 'min_events':
						conditions.min_events = parseInt($('#sc-min-events').val()) || 0;
						conditions.min_events_age_days = parseInt($('#sc-min-events-age').val()) || 0;
						break;
					case 'max_events':
						conditions.max_events = parseInt($('#sc-max-events').val()) || 0;
						break;
					case 'min_page_views':
						conditions.min_page_views = parseInt($('#sc-min-pageviews').val()) || 0;
						break;
					case 'bounce_only':
						conditions.bounce_only = true;
						conditions.bounce_age_days = parseInt($('#sc-bounce-age').val()) || 0;
						break;
					case 'include_spam_traffic':
						conditions.include_spam_traffic = true;
						// Back-compat alias for saved settings created before the
						// canonical spam-traffic condition key existed.
						conditions.include_bots = true;
						break;
					case 'single_visit_only':
						conditions.single_visit_only = true;
						conditions.single_visit_age_days = parseInt($('#sc-single-visit-age').val()) || 0;
						break;
				}
			});

			// Add orphaned visitors option
			conditions.delete_orphaned_visitors = $('#sc-delete-orphans').is(':checked');

			return conditions;
		}

		/**
		 * Collect scheduled cleanup settings from the form.
		 */
		function collectAutoCleanupSettings() {
			return {
				enabled: $('#sc-auto-enabled').is(':checked'),
				frequency: $('#sc-auto-frequency').val(),
				conditions: collectConditions(),
				delete_orphaned_visitors: $('#sc-delete-orphans').is(':checked'),
				max_rows_per_run: parseInt($('#sc-max-rows-per-run').val(), 10) || 50000,
				optimize_after_cleanup: $('#sc-schedule-optimize-after-cleanup').is(':checked'),
				recalculate_spam_before_cleanup: $('#sc-recalculate-spam-before-cleanup').is(':checked'),
				// Daily heatmap sync auto-repair toggle (separate option server-side, default ON).
				auto_repair_heatmap_sync: $('#sc-auto-repair-heatmap-sync').is(':checked')
			};
		}

		/**
		 * Check if any condition is selected
		 */
		function hasActiveConditions() {
			return $('.condition-check:checked').length > 0;
		}

		/**
		 * Escape a value before writing AJAX data into HTML.
		 */
		function escapeHtml(value) {
			return $('<div>').text(value === null || typeof value === 'undefined' ? '' : String(value)).html();
		}

		/**
		 * Format a number with the browser locale.
		 */
		function formatNumber(value) {
			var number = parseInt(value, 10);
			if (isNaN(number)) {
				number = 0;
			}
			return number.toLocaleString();
		}

		/**
		 * Format bytes for compact preview and result summaries.
		 */
		function formatBytes(bytes) {
			var value = parseInt(bytes, 10) || 0;
			if (value <= 0) {
				return '0 B';
			}
			var units = ['B', 'KB', 'MB', 'GB', 'TB'];
			var index = 0;
			while (value >= 1024 && index < units.length - 1) {
				value = value / 1024;
				index++;
			}
			return (index === 0 ? value : value.toFixed(value >= 10 ? 1 : 2)) + ' ' + units[index];
		}

		/**
		 * Render rows-by-table as an HTML table.
		 */
		function renderRowsByTable(rowsByTable) {
			var rows = [];
			$.each(rowsByTable || {}, function(tableName, rowCount) {
				rowCount = parseInt(rowCount, 10) || 0;
				if (rowCount > 0) {
					rows.push('<tr><td><code>' + escapeHtml(tableName) + '</code></td><td>' + formatNumber(rowCount) + '</td></tr>');
				}
			});

			if (!rows.length) {
				return '<p class="smart-cleanup-empty">' + (S.noTableRows || 'No table rows are currently matched.') + '</p>';
			}

			return '<table class="widefat striped smart-cleanup-impact-table"><thead><tr><th>' + (S.tableHeader || 'Table') + '</th><th>' + (S.rowsHeader || 'Rows') + '</th></tr></thead><tbody>' + rows.join('') + '</tbody></table>';
		}

		/**
		 * Render traffic breakdown summary.
		 */
		function renderTrafficBreakdown(trafficBreakdown) {
			var items = [];
			$.each(trafficBreakdown || {}, function(trafficType, count) {
				items.push('<li><strong>' + escapeHtml(trafficType || 'unknown') + ':</strong> ' + formatNumber(count) + '</li>');
			});

			return items.length ? '<ul class="smart-cleanup-traffic-list">' + items.join('') + '</ul>' : '<p class="smart-cleanup-empty">' + (S.noMatchingTraffic || 'No matching traffic types.') + '</p>';
		}

		/**
		 * Render per-condition (OR rule) match counts.
		 */
		function renderConditionMatches(conditionMatches) {
			var rows = [];
			$.each(conditionMatches || {}, function(label, count) {
				rows.push('<tr><td>' + escapeHtml(label) + '</td><td>' + formatNumber(count) + '</td></tr>');
			});

			if (!rows.length) {
				return '';
			}

			return '<details open><summary>' + (S.conditionMatches || 'Matches per condition (OR rules)') + '</summary>'
				+ '<table class="widefat striped smart-cleanup-impact-table"><thead><tr><th>' + (S.conditionHeader || 'Condition') + '</th><th>' + (S.sessionsHeader || 'Sessions') + '</th></tr></thead><tbody>' + rows.join('') + '</tbody></table>'
				+ '<p class="smart-cleanup-empty">' + (S.conditionMatchesNote || 'Each condition is an independent OR rule; a session matching several conditions is counted in each row, so the sum can exceed the total.') + '</p>'
				+ '</details>';
		}

		/**
		 * Render warning list.
		 */
		function renderWarnings(warnings) {
			if (!warnings || !warnings.length) {
				return '';
			}

			var items = warnings.map(function(warning) {
				return '<li>' + escapeHtml(warning) + '</li>';
			});

			return '<div class="ob-smart-cleanup-cautions"><ul>' + items.join('') + '</ul></div>';
		}

		/**
		 * Build enhanced Smart Cleanup preview details markup.
		 */
		function renderSmartPreviewDetailsHtml(data) {
			var optimizationCandidates = data.optimization_candidates || [];
			var optimizationText = optimizationCandidates.length ? (S.affectedTables || '%d affected table(s)').replace('%d', formatNumber(optimizationCandidates.length)) : (S.noneCurrentlyFlagged || 'None currently flagged');
			return ''
				+ '<div class="smart-cleanup-impact-card">'
				+ '<h5>' + (S.previewImpact || 'Preview impact') + '</h5>'
				+ '<div class="smart-cleanup-impact-grid">'
				+ '<span><strong>' + (S.sessions || 'Sessions:') + '</strong> ' + formatNumber(data.sessions || data.count || 0) + '</span>'
				+ '<span><strong>' + (S.recordingFiles || 'Recording files:') + '</strong> ' + formatNumber(data.recording_files || 0) + '</span>'
				+ '<span><strong>' + (S.orphanVisitorEstimate || 'Orphan visitor estimate:') + '</strong> ' + formatNumber(data.orphaned_visitors || 0) + '</span>'
				+ '<span><strong>' + (S.approxAffectedBytes || 'Approx. affected bytes:') + '</strong> ' + formatBytes(data.estimated_bytes || 0) + '</span>'
				+ '<span><strong>' + (S.optimizationCandidates || 'Optimization candidates:') + '</strong> ' + escapeHtml(optimizationText) + '</span>'
				+ '</div>'
				+ renderConditionMatches(data.condition_matches)
				+ '<details open><summary>' + (S.trafficBreakdown || 'Traffic breakdown') + '</summary>' + renderTrafficBreakdown(data.traffic_breakdown) + '</details>'
				+ '<details><summary>' + (S.rowsByTable || 'Rows by table') + '</summary>' + renderRowsByTable(data.rows_by_table) + '</details>'
				+ renderWarnings(data.warnings)
				+ '</div>';
		}

		/**
		 * Render enhanced Smart Cleanup preview details.
		 */
		function renderSmartPreviewDetails(data) {
			var $details = $('#opti-behavior-smart-preview-details');

			$details
				.html(renderSmartPreviewDetailsHtml(data))
				.removeAttr('hidden')
				.attr('aria-hidden', 'false')
				.addClass('is-visible')
				.css({
					display: 'block',
					visibility: 'visible',
					opacity: 1,
					height: 'auto'
				});

			return isSmartPreviewDetailsVisible();
		}

		/**
		 * Check whether the destructive preview details are actually visible.
		 */
		function isSmartPreviewDetailsVisible() {
			var element = document.getElementById('opti-behavior-smart-preview-details');
			var rect;
			var styles;

			if (!element || !element.innerHTML.trim()) {
				return false;
			}

			styles = window.getComputedStyle(element);
			rect = element.getBoundingClientRect();

			return styles.display !== 'none'
				&& styles.visibility !== 'hidden'
				&& parseFloat(styles.opacity || '1') > 0
				&& rect.width > 0
				&& rect.height > 0;
		}

		/**
		 * Render enhanced execution result details.
		 */
		function renderSmartExecutionDetails(data) {
			var optimizedTables = data.optimized_tables || [];
			var html = ''
				+ '<div class="smart-cleanup-result-card">'
				+ '<div class="smart-cleanup-impact-grid">'
				+ '<span><strong>' + (S.filesDeleted || 'Files deleted:') + '</strong> ' + formatNumber(data.files_deleted || 0) + '</span>'
				+ '<span><strong>' + (S.orphanVisitorsDeleted || 'Orphan visitors deleted:') + '</strong> ' + formatNumber(data.orphaned_visitors_deleted || 0) + '</span>'
				+ '<span><strong>' + (S.optimizedTables || 'Optimized tables:') + '</strong> ' + formatNumber(optimizedTables.length) + '</span>'
				+ '<span><strong>' + (S.approxAffectedBytes || 'Approx. affected bytes:') + '</strong> ' + formatBytes(data.estimated_bytes_reclaimed || 0) + '</span>'
				+ '</div>'
				+ '<details><summary>' + (S.rowsDeletedByTable || 'Rows deleted by table') + '</summary>' + renderRowsByTable(data.rows_deleted_by_table || data.rows_by_table) + '</details>'
				+ renderWarnings(data.warnings)
				+ '</div>';

			$('#opti-behavior-smart-result-details').html(html);
		}

		/**
		 * Reset preview state any time conditions change.
		 */
		function resetSmartPreview() {
			lastSmartPreview = null;
			$('#opti-behavior-preview-count').hide().text('');
			$('#opti-behavior-smart-preview-details')
				.removeClass('is-visible')
				.attr('aria-hidden', 'true')
				.css({
					display: 'none',
					visibility: '',
					opacity: '',
					height: ''
				})
				.empty();
			$smartDeleteBtn.prop('disabled', true);
		}

		// ---- Save Conditions ----
		$saveConditionsBtn.on('click', function() {
			var $btn = $(this);
			$btn.prop('disabled', true).find('.btn-text').text(S.saving || 'Saving...');

			var settings = collectAutoCleanupSettings();

			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_save_auto_cleanup',
					nonce: opti_behaviorSettings.smartCleanupNonce,
					settings: settings
				},
				success: function(response) {
					$btn.prop('disabled', false).find('.btn-text').text(S.saveConditions || 'Save Conditions');
					if (typeof lucide !== 'undefined') { lucide.createIcons(); }
					if (response.success) {
						var $notice = $('<div class="notice notice-success is-dismissible" style="position:fixed;top:40px;right:20px;z-index:99999;padding:10px 15px;border-left:4px solid #46b450;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,0.15);"><p>' + (response.data.message || (S.conditionsSaved || 'Conditions saved successfully.')) + '</p></div>');
						$('body').append($notice);
						setTimeout(function() { $notice.fadeOut(300, function() { $(this).remove(); }); }, 3000);
					} else {
						showOptiNotice(response.data ? response.data.message : (S.errorSavingConditions || 'Error saving conditions'), 'error');
					}
				},
				error: function() {
					$btn.prop('disabled', false).find('.btn-text').text(S.saveConditions || 'Save Conditions');
					if (typeof lucide !== 'undefined') { lucide.createIcons(); }
					showOptiNotice(S.ajaxError || 'AJAX error', 'error');
				}
			});
		});

		// ---- Preview ----
		$smartPreviewBtn.on('click', function() {
			if (!hasActiveConditions()) {
				showOptiNotice(S.noConditions || 'Please select at least one condition.', 'warning');
				return;
			}

			var $btn = $(this);
			var $countSpan = $('#opti-behavior-preview-count');
			resetSmartPreview();
			$btn.prop('disabled', true).find('.btn-text').text(S.previewing || 'Checking...');

			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_smart_cleanup_preview',
					nonce: opti_behaviorSettings.smartCleanupNonce,
					conditions: collectConditions()
				},
				success: function(response) {
					$btn.prop('disabled', false).find('.btn-text').text(S.preview || 'Preview');
					if (response.success) {
						lastSmartPreview = response.data || {};
						var sessionCount = parseInt(lastSmartPreview.count || lastSmartPreview.sessions || 0, 10) || 0;
						$countSpan.text('(' + formatNumber(sessionCount) + ' sessions)').show();
						var previewVisible = renderSmartPreviewDetails(lastSmartPreview);
						$smartDeleteBtn.prop('disabled', sessionCount === 0 || !previewVisible);
						if (sessionCount > 0 && !previewVisible) {
							showOptiNotice(S.previewDetailsHidden || 'Preview impact details could not be displayed, so deletion remains disabled.', 'error');
						}
					} else {
						lastSmartPreview = null;
						$smartDeleteBtn.prop('disabled', true);
						showOptiNotice(response.data ? response.data.message : (S.errorGeneric || 'Error'), 'error');
					}
				},
				error: function() {
					$btn.prop('disabled', false).find('.btn-text').text(S.preview || 'Preview');
					lastSmartPreview = null;
					$smartDeleteBtn.prop('disabled', true);
					showOptiNotice(S.ajaxError || 'AJAX error', 'error');
				}
			});
		});

		// ---- Smart Cleanup Delete ----
		$smartDeleteBtn.on('click', function() {
			if (!lastSmartPreview) {
				showOptiNotice(S.previewRequired || 'Please run a valid preview before deleting.', 'warning');
				$smartDeleteBtn.prop('disabled', true);
				return;
			}

			$('#opti-behavior-smart-preview-summary').text(
				$('#opti-behavior-preview-count').text()
			);
			$('#opti-behavior-smart-preview-summary-details').html(renderSmartPreviewDetailsHtml(lastSmartPreview));
			$('#opti-behavior-smart-confirm').show();
			$('#opti-behavior-smart-progress').hide();
			$('#opti-behavior-smart-complete').hide();
			$smartModal.fadeIn(200);
			if (typeof lucide !== 'undefined') { lucide.createIcons(); }
		});

		// Smart modal close
		$smartModal.on('click', '.opti-behavior-modal-close, .opti-behavior-modal-cancel, .opti-behavior-modal-overlay', function(e) {
			if ($(e.target).is('.opti-behavior-modal-overlay, .opti-behavior-modal-close, .opti-behavior-modal-cancel')) {
				$smartModal.fadeOut(200);
			}
		});
		$smartModal.on('click', '.opti-behavior-modal-done', function() { location.reload(); });

		// Confirm smart cleanup
		$('#opti-behavior-confirm-smart-cleanup-btn').on('click', function() {
			$('#opti-behavior-smart-confirm').hide();
			$('#opti-behavior-smart-progress').show();
			$('#opti-behavior-smart-progress-bar').css('width', '0%');
			$('#opti-behavior-smart-percent').text('0%');
			$('#opti-behavior-smart-status').text(S.initializing || 'Initializing...');
			processSmartCleanupStep(0);
		});

		function processSmartCleanupStep(step) {
			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_smart_cleanup_execute',
					nonce: opti_behaviorSettings.smartCleanupNonce,
					conditions: collectConditions(),
					optimize_after_cleanup: $('#sc-optimize-after-cleanup').is(':checked'),
					step: step
				},
				success: function(response) {
					if (response.success) {
						if (response.data.status === 'processing') {
							$('#opti-behavior-smart-progress-bar').css('width', response.data.progress + '%');
							$('#opti-behavior-smart-percent').text(response.data.progress + '%');
							$('#opti-behavior-smart-status').text(response.data.message);
							processSmartCleanupStep(response.data.step);
						} else if (response.data.status === 'completed') {
							$('#opti-behavior-smart-progress-bar').css('width', '100%');
							$('#opti-behavior-smart-percent').text('100%');
							setTimeout(function() {
								$('#opti-behavior-smart-progress').hide();
								$('#opti-behavior-smart-complete').show();
								$('#opti-behavior-smart-result').text(
									(S.sessionsDeleted || '%d sessions deleted successfully.').replace('%d', response.data.deleted || 0)
								);
								renderSmartExecutionDetails(response.data);
								if (typeof lucide !== 'undefined') { lucide.createIcons(); }
							}, 500);
						}
					} else {
						$smartModal.fadeOut(200);
						showOptiNotice(response.data ? response.data.message : (S.errorGeneric || 'Error'), 'error');
					}
				},
				error: function(xhr, status, error) {
					$smartModal.fadeOut(200);
					showOptiNotice((S.ajaxError || 'AJAX error') + ': ' + error, 'error');
				}
			});
		}

		// ---- Bot Cleanup ----
		$botCleanupBtn.on('click', function() {
			$('#opti-behavior-bot-confirm').show();
			$('#opti-behavior-bot-progress').hide();
			$('#opti-behavior-bot-complete').hide();
			$botModal.fadeIn(200);
			if (typeof lucide !== 'undefined') { lucide.createIcons(); }
		});

		// Bot modal close
		$botModal.on('click', '.opti-behavior-modal-close, .opti-behavior-modal-cancel, .opti-behavior-modal-overlay', function(e) {
			if ($(e.target).is('.opti-behavior-modal-overlay, .opti-behavior-modal-close, .opti-behavior-modal-cancel')) {
				$botModal.fadeOut(200);
			}
		});
		$botModal.on('click', '.opti-behavior-modal-done', function() { location.reload(); });

		// Confirm bot cleanup
		$('#opti-behavior-confirm-bot-cleanup-btn').on('click', function() {
			$('#opti-behavior-bot-confirm').hide();
			$('#opti-behavior-bot-progress').show();
			$('#opti-behavior-bot-progress-bar').css('width', '0%');
			$('#opti-behavior-bot-percent').text('0%');
			$('#opti-behavior-bot-status').text(S.initializing || 'Initializing...');
			processBotCleanupStep(0);
		});

		function processBotCleanupStep(step) {
			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_bot_cleanup',
					nonce: opti_behaviorSettings.smartCleanupNonce,
					step: step
				},
				success: function(response) {
					if (response.success) {
						if (response.data.status === 'processing') {
							$('#opti-behavior-bot-progress-bar').css('width', response.data.progress + '%');
							$('#opti-behavior-bot-percent').text(response.data.progress + '%');
							$('#opti-behavior-bot-status').text(response.data.message);
							processBotCleanupStep(response.data.step);
						} else if (response.data.status === 'completed') {
							$('#opti-behavior-bot-progress-bar').css('width', '100%');
							$('#opti-behavior-bot-percent').text('100%');
							setTimeout(function() {
								$('#opti-behavior-bot-progress').hide();
								$('#opti-behavior-bot-complete').show();
								$('#opti-behavior-bot-result').text(
									(S.botSessionsCleaned || '%d bot/spam sessions cleaned successfully.').replace('%d', response.data.deleted || 0)
								);
								if (typeof lucide !== 'undefined') { lucide.createIcons(); }
							}, 500);
						}
					} else {
						$botModal.fadeOut(200);
						showOptiNotice(response.data ? response.data.message : (S.errorGeneric || 'Error'), 'error');
					}
				},
				error: function(xhr, status, error) {
					$botModal.fadeOut(200);
					showOptiNotice((S.ajaxError || 'AJAX error') + ': ' + error, 'error');
				}
			});
		}

		// ---- Save Schedule ----
		$saveScheduleBtn.on('click', function() {
			var $btn = $(this);
			$btn.prop('disabled', true).text(S.saving || 'Saving...');

			var settings = collectAutoCleanupSettings();

			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_save_auto_cleanup',
					nonce: opti_behaviorSettings.smartCleanupNonce,
					settings: settings
				},
				success: function(response) {
					$btn.prop('disabled', false).html('<span class="btn-icon"><i data-lucide="save"></i></span> ' + (S.saveSchedule || 'Save Schedule'));
					if (typeof lucide !== 'undefined') { lucide.createIcons(); }
					if (response.success) {
						// Show a brief success notification
						var $notice = $('<div class="notice notice-success is-dismissible" style="position:fixed;top:40px;right:20px;z-index:99999;padding:10px 15px;"><p>' + response.data.message + '</p></div>');
						$('body').append($notice);
						setTimeout(function() { $notice.fadeOut(300, function() { $(this).remove(); }); }, 3000);
					} else {
						showOptiNotice(response.data ? response.data.message : (S.errorSavingSettings || 'Error saving settings'), 'error');
					}
				},
				error: function() {
					$btn.prop('disabled', false).html('<span class="btn-icon"><i data-lucide="save"></i></span> ' + (S.saveSchedule || 'Save Schedule'));
					if (typeof lucide !== 'undefined') { lucide.createIcons(); }
					showOptiNotice(S.ajaxError || 'AJAX error', 'error');
				}
			});
		});

		// ---- Auto-repair heatmap sync toggle (Repair & Archive card) ----
		// Relocated from the Auto Schedule tab (round 3). Saves immediately on
		// change through the SAME schedule-save AJAX path/option wiring; the
		// full current form state is sent so no other setting is reset.
		$('#sc-auto-repair-heatmap-sync').on('change', function() {
			var $toggle = $(this);
			$toggle.prop('disabled', true);
			$.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: {
					action: 'optibehavior_save_auto_cleanup',
					nonce: opti_behaviorSettings.smartCleanupNonce,
					settings: collectAutoCleanupSettings()
				},
				success: function(response) {
					$toggle.prop('disabled', false);
					if (response && response.success) {
						showOptiNotice(S.autoRepairSaved || 'Auto-repair setting saved.', 'success');
					} else {
						// Revert the visual state so the UI never shows an unsaved value.
						$toggle.prop('checked', !$toggle.is(':checked'));
						showOptiNotice(response && response.data && response.data.message ? response.data.message : (S.errorSavingSettings || 'Error saving settings'), 'error');
					}
				},
				error: function(xhr, status, error) {
					$toggle.prop('disabled', false);
					$toggle.prop('checked', !$toggle.is(':checked'));
					showOptiNotice((S.ajaxError || 'AJAX error') + ': ' + error, 'error');
				}
			});
		});

		// ---- Conditional rule ↔ retention tier redundancy notice (round 3) ----
		// Non-blocking: when the deprecated "Sessions older than X days" rule is
		// active it always overlaps the detailed-data retention tier (which runs
		// automatically every day), so surface a small informational notice.
		function updateConditionalRedundancyNotice() {
			var $notice = $('#sc-conditional-redundancy-notice');
			if (!$notice.length) {
				return;
			}
			var rawDays = parseInt($('#sc-data-retention-days').val(), 10) || 0;
			var message = '';
			var $olderCheck = $('.condition-check[data-condition="older_than_days"]');
			if (rawDays > 0 && $olderCheck.length && $olderCheck.is(':checked')) {
				var olderDays = parseInt($('#sc-older-than-days').val(), 10) || 0;
				if (olderDays > 0) {
					message = (S.olderThanRedundant || 'Note: the "Sessions older than %1$s days" rule overlaps the Data Retention detailed-data window (%2$s days), which already runs automatically every day — consider removing this rule and relying on the Data Retention tier instead.')
						.replace('%1$s', olderDays)
						.replace('%2$s', rawDays);
				}
			}
			if (message) {
				$notice.text(message).show();
			} else {
				$notice.hide().text('');
			}
		}
		$(document).on('change input', '.condition-check[data-condition="older_than_days"], #sc-older-than-days, #sc-data-retention-days', updateConditionalRedundancyNotice);
		updateConditionalRedundancyNotice();

		// Danger Zone sub-tabs
		$('.danger-zone-tab').on('click', function() {
			var tab = $(this).data('danger-tab');
			$('.danger-zone-tab').removeClass('active');
			$(this).addClass('active');
			$('.danger-tab-panel').removeClass('active');
			$('.danger-tab-panel[data-danger-tab="' + tab + '"]').addClass('active');
			if (typeof lucide !== 'undefined') { lucide.createIcons(); }
		});

	});

	// Privacy mode toggle — show/hide Consent Banner Configuration card
	(function() {
		function obToggleConsentCard() {
			var selected = document.querySelector('input[name="privacy_mode"]:checked');
			var card = document.querySelector('.ob-consent-card');
			if (!card) { return; }
			if (selected && selected.value === 'full') {
				card.classList.remove('ob-consent-card--hidden');
			} else {
				card.classList.add('ob-consent-card--hidden');
			}
		}
		var privacyRadios = document.querySelectorAll('input[name="privacy_mode"]');
		privacyRadios.forEach(function(r) {
			r.addEventListener('change', obToggleConsentCard);
		});

		// Update hex label when color picker changes
		var colorPickers = document.querySelectorAll('.ob-color-picker-item input[type="color"]');
		colorPickers.forEach(function(picker) {
			picker.addEventListener('input', function() {
				var hexSpan = picker.parentElement.querySelector('.ob-color-hex');
				if (hexSpan) { hexSpan.textContent = picker.value; }
			});
		});
	}());

	// =========================================================
	// Storage Stats tab — async progressive loading
	// The PHP renders only a static shell (skeletons); this module
	// fetches table sizes + file stats in parallel, then loads
	// exact row counts in sequential batches so slow COUNT(*)
	// queries never block the page.
	// =========================================================
	jQuery(function($) {
		var $root = $('#ob-storage-stats');
		if (!$root.length || typeof opti_behaviorSettings === 'undefined' || !opti_behaviorSettings.storageStatsNonce) {
			return;
		}

		var COUNT_BATCH_SIZE = 5;
		var $tablesBody = $('#ob-storage-tables-body');
		var countsDone = false;
		var exactRows = {};   // suffix -> exact count (raw)
		var estimateRows = {}; // suffix -> estimate (raw)

		function escapeHtml(text) {
			return String(text === undefined || text === null ? '' : text)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}

		function refreshIcons() {
			if (typeof lucide !== 'undefined') { lucide.createIcons(); }
		}

		function setStat(name, value) {
			$root.find('[data-stat="' + name + '"]').removeClass('ob-skeleton').text(value);
		}

		function setFileStat(name, value) {
			$root.find('[data-file-stat="' + name + '"]').removeClass('ob-skeleton').text(value);
		}

		function ajaxRequest(action, data) {
			return $.ajax({
				url: opti_behaviorSettings.ajaxUrl || ajaxurl,
				type: 'POST',
				data: $.extend({
					action: action,
					nonce: opti_behaviorSettings.storageStatsNonce
				}, data || {})
			});
		}

		function skeletonRowsHtml(count) {
			var row = '<tr class="ob-skeleton-row">'
				+ '<td class="storage-table-name"><span class="ob-skeleton ob-skeleton-line ob-skeleton-wide"></span></td>'
				+ '<td class="storage-table-rows"><span class="ob-skeleton ob-skeleton-line"></span></td>'
				+ '<td class="storage-table-data"><span class="ob-skeleton ob-skeleton-line"></span></td>'
				+ '<td class="storage-table-index"><span class="ob-skeleton ob-skeleton-line"></span></td>'
				+ '<td class="storage-table-total"><span class="ob-skeleton ob-skeleton-line"></span></td>'
				+ '<td class="storage-table-bar"><span class="ob-skeleton ob-skeleton-line"></span></td>'
				+ '</tr>';
			return new Array((count || 6) + 1).join(row);
		}

		function errorRowHtml() {
			return '<tr class="ob-storage-error-row"><td colspan="6">'
				+ '<div class="ob-storage-error">'
				+ '<i data-lucide="alert-circle"></i>'
				+ '<span>' + escapeHtml(S.storageLoadFailed || 'Could not load storage data.') + '</span>'
				+ '<button type="button" class="button ob-storage-retry" data-retry="tables">' + escapeHtml(S.storageRetry || 'Retry') + '</button>'
				+ '</div>'
				+ '</td></tr>';
		}

		/**
		 * Build one table row. Row count starts as the information_schema
		 * estimate marked with "≈" + spinner; the exact count patches it later.
		 */
		function buildTableRow(table, maxSize) {
			var pct = maxSize > 0 ? (table.total_size / maxSize) * 100 : 0;
			var barClass = pct > 75 ? 'high' : (pct > 40 ? 'medium' : 'low');
			var approx = escapeHtml(S.storageApprox || '≈');

			return '<tr data-table-suffix="' + escapeHtml(table.suffix) + '">'
				+ '<td class="storage-table-name">'
				+ '<div class="table-name-wrapper">'
				+ '<i data-lucide="' + escapeHtml(table.icon) + '" class="table-icon"></i>'
				+ '<div class="table-name-text">'
				+ '<span class="table-friendly-name">' + escapeHtml(table.friendly_name) + '</span>'
				+ '<span class="table-technical-name">' + escapeHtml(table.table_name) + '</span>'
				+ '</div>'
				+ '</div>'
				+ '</td>'
				+ '<td class="storage-table-rows">'
				+ '<span class="ob-count-approx">' + approx + ' ' + escapeHtml(table.rows_estimate_fmt) + '</span>'
				+ '<span class="ob-count-spinner" aria-hidden="true"></span>'
				+ '</td>'
				+ '<td class="storage-table-data">' + escapeHtml(table.data_size_fmt) + '</td>'
				+ '<td class="storage-table-index">' + escapeHtml(table.index_size_fmt) + '</td>'
				+ '<td class="storage-table-total"><strong>' + escapeHtml(table.total_size_fmt) + '</strong></td>'
				+ '<td class="storage-table-bar">'
				+ '<div class="storage-bar-wrapper">'
				+ '<div class="storage-bar storage-bar-' + barClass + '" style="width: ' + Math.max(2, pct) + '%;"></div>'
				+ '</div>'
				+ '</td>'
				+ '</tr>';
		}

		/** Patch the Total Records card: exact where known, estimate otherwise. */
		function updateRecordsTotal() {
			var total = 0;
			var allExact = true;
			$.each(estimateRows, function(suffix, estimate) {
				if (Object.prototype.hasOwnProperty.call(exactRows, suffix)) {
					total += exactRows[suffix];
				} else {
					total += estimate;
					allExact = false;
				}
			});
			var fmt = total.toLocaleString();
			setStat('records', allExact ? fmt : (S.storageApprox || '≈') + ' ' + fmt);
		}

		/** Sequential batched exact-count loop (5 suffixes/request). */
		function loadCounts(queue) {
			if (countsDone || !queue.length) {
				countsDone = true;
				$tablesBody.find('.ob-count-spinner').remove();
				updateRecordsTotal();
				return;
			}

			var batch = queue.slice(0, COUNT_BATCH_SIZE);
			var rest = queue.slice(COUNT_BATCH_SIZE);

			ajaxRequest('optibehavior_storage_stats_counts', { tables: batch })
				.done(function(response) {
					if (!response || !response.success || !response.data || !response.data.counts) {
						countsFailed();
						return;
					}
					$.each(response.data.counts, function(suffix, info) {
						exactRows[suffix] = parseInt(info.rows, 10) || 0;
						$tablesBody.find('tr[data-table-suffix="' + suffix + '"] .storage-table-rows')
							.empty()
							.text(info.rows_fmt);
					});
					updateRecordsTotal();
					loadCounts(rest);
				})
				.fail(countsFailed);
		}

		/** Non-blocking count failure: keep "≈" estimates, drop spinners. */
		function countsFailed() {
			countsDone = true;
			$tablesBody.find('.ob-count-spinner').remove();
			updateRecordsTotal();
			showOptiNotice(S.storageCountsFailed || 'Exact row counts could not be loaded — showing estimates (≈).', 'warning');
		}

		function loadTables() {
			$tablesBody.html(skeletonRowsHtml(6));

			ajaxRequest('optibehavior_storage_stats_tables')
				.done(function(response) {
					if (!response || !response.success || !response.data || !response.data.tables) {
						tablesFailed();
						return;
					}

					var tables = response.data.tables;
					var totals = response.data.totals || {};

					setStat('db_size', totals.db_size_fmt || '');
					setStat('tables', (totals.table_count || 0).toLocaleString());

					estimateRows = {};
					exactRows = {};
					countsDone = false;

					if (!tables.length) {
						$tablesBody.html('<tr><td colspan="6" class="ob-storage-empty">' + escapeHtml(S.storageNoTables || 'No database tables found.') + '</td></tr>');
						setStat('records', '0');
						return;
					}

					var maxSize = 0;
					var suffixes = [];
					var html = '';
					$.each(tables, function(_, table) {
						if (table.total_size > maxSize) { maxSize = table.total_size; }
					});
					$.each(tables, function(_, table) {
						estimateRows[table.suffix] = parseInt(table.rows_estimate, 10) || 0;
						suffixes.push(table.suffix);
						html += buildTableRow(table, maxSize);
					});

					$tablesBody.html(html);
					refreshIcons();
					updateRecordsTotal();
					loadCounts(suffixes);
				})
				.fail(tablesFailed);
		}

		function tablesFailed() {
			$root.find('[data-stat]').removeClass('ob-skeleton').filter('[data-stat!="file_size"]').text('—');
			$tablesBody.html(errorRowHtml());
			refreshIcons();
		}

		function loadFiles() {
			$root.find('[data-file-stat]').addClass('ob-skeleton').html('&nbsp;');
			$root.find('.ob-storage-error--files').remove();

			ajaxRequest('optibehavior_storage_stats_files')
				.done(function(response) {
					if (!response || !response.success || !response.data) {
						filesFailed();
						return;
					}

					var data = response.data;
					setStat('file_size', data.total_size_fmt || '');
					setFileStat('path', data.path || '');
					setFileStat('total_size', data.total_size_fmt || '');
					setFileStat('file_count', data.file_count_fmt || '');
					setFileStat('folder_count', data.folder_count_fmt || '');

					$root.find('[data-file-stat="status"]')
						.removeClass('ob-skeleton file-stat-success file-stat-muted')
						.addClass(data.exists ? 'file-stat-success' : 'file-stat-muted')
						.text(data.exists ? (S.storageStatusActive || 'Active') : (S.storageStatusEmpty || 'Empty'));
					$root.find('[data-file-stat-icon="status"]').html('<i data-lucide="' + (data.exists ? 'check-circle' : 'x-circle') + '"></i>');
					$('#ob-storage-file-notice').toggle(!data.exists);
					refreshIcons();
				})
				.fail(filesFailed);
		}

		function filesFailed() {
			setStat('file_size', '—');
			$root.find('[data-file-stat]').removeClass('ob-skeleton').text('—');
			$root.find('.storage-file-info').append(
				'<div class="ob-storage-error ob-storage-error--files">'
				+ '<i data-lucide="alert-circle"></i>'
				+ '<span>' + escapeHtml(S.storageLoadFailed || 'Could not load storage data.') + '</span>'
				+ '<button type="button" class="button ob-storage-retry" data-retry="files">' + escapeHtml(S.storageRetry || 'Retry') + '</button>'
				+ '</div>'
			);
			refreshIcons();
		}

		// Retry re-fires only the failed request.
		$root.on('click', '.ob-storage-retry', function() {
			if ($(this).data('retry') === 'files') {
				loadFiles();
			} else {
				$root.find('[data-stat!="file_size"][data-stat]').addClass('ob-skeleton').html('&nbsp;');
				loadTables();
			}
		});

		// Fire both requests in parallel on load.
		loadTables();
		loadFiles();
	});

})();
