/**
 * Heatmap Detail Page Controller
 *
 * Handles the heatmap detail page interactions, data loading, and visualization.
 *
 * @package OptiBehaviorPro
 * @since 1.5.0
 */

(function($) {
	'use strict';

	/**
	 * Canvas size-limit detection (runtime, browser-agnostic).
	 *
	 * Browsers impose a maximum 2D canvas width/height, and sometimes a maximum
	 * total pixel area, that varies by browser engine, version, OS, and even
	 * available device memory (e.g. Firefox ~32,767px per axis; Chromium
	 * engines vary by version/platform; mobile Safari additionally caps total
	 * area well below what its per-axis limit alone would allow). None of this
	 * is exposed through a JS API, so it's detected here by actually attempting
	 * to draw into a candidate-sized canvas and reading the pixel back:
	 * unsupported sizes either throw or (on some engines) silently fail to
	 * allocate/draw without an error, so a successful readback is the only
	 * reliable signal either way.
	 *
	 * The per-axis limit is a fixed property of the current browser/device, so
	 * it's probed once and cached for the life of the page.
	 */
	var _maxCanvasAxisCache = null;

	function canvasCanDraw(w, h) {
		if (!(w > 0) || !(h > 0)) {
			return false;
		}
		try {
			var testCanvas = document.createElement('canvas');
			testCanvas.width = w;
			testCanvas.height = h;
			var ctx = testCanvas.getContext('2d');
			if (!ctx) {
				return false;
			}
			ctx.fillStyle = '#fff';
			ctx.fillRect(w - 1, h - 1, 1, 1);
			var pixel = ctx.getImageData(w - 1, h - 1, 1, 1).data;
			return pixel[0] === 255 && pixel[3] === 255;
		} catch (e) {
			return false;
		}
	}

	/**
	 * Detect this browser's maximum supported single-axis canvas dimension.
	 * Cached for the lifetime of the page load.
	 *
	 * @returns {number} Largest confirmed-drawable width/height, in pixels.
	 */
	function getMaxCanvasAxis() {
		if (_maxCanvasAxisCache !== null) {
			return _maxCanvasAxisCache;
		}

		var lo = 4096; // Universally-supported baseline across every known engine.
		var hi = 200000; // Far beyond any known engine's per-axis limit.

		if (!canvasCanDraw(lo, 2)) {
			// Extremely defensive fallback for an unknown/constrained engine that
			// can't even manage the conservative baseline - shrink until it works.
			while (lo > 16 && !canvasCanDraw(lo, 2)) {
				lo = Math.floor(lo / 2);
			}
			_maxCanvasAxisCache = Math.max(lo, 16);
			return _maxCanvasAxisCache;
		}

		if (canvasCanDraw(hi, 2)) {
			// Extremely generous engine - nothing to cap in practice.
			_maxCanvasAxisCache = hi;
			return hi;
		}

		// Binary search the boundary between `lo` (known good) and `hi` (known bad).
		while (hi - lo > 1) {
			var mid = Math.floor((lo + hi) / 2);
			if (canvasCanDraw(mid, 2)) {
				lo = mid;
			} else {
				hi = mid;
			}
		}

		_maxCanvasAxisCache = lo;
		return lo;
	}

	/**
	 * Compute the largest safe (width, height) scale for a canvas that needs to
	 * represent a `realWidth` x `realHeight` page in this browser, given both
	 * this browser's per-axis canvas limit AND any additional total-pixel-area
	 * limit (some engines cap area independently of either axis, e.g. mobile
	 * Safari). Independent per-axis capping is tried first (keeps whichever
	 * axis doesn't need shrinking at full resolution); only if the resulting
	 * canvas still fails to draw is an additional isotropic correction applied.
	 *
	 * @param {number} realWidth  Real (uncapped) content width in pixels.
	 * @param {number} realHeight Real (uncapped) content height in pixels.
	 * @returns {{scaleX: number, scaleY: number}} Scale factors in (0, 1], 1 meaning "no cap needed".
	 */
	function getMaxSafeCanvasScale(realWidth, realHeight) {
		var safeWidth = Math.max(1, Math.ceil(realWidth || 1));
		var safeHeight = Math.max(1, Math.ceil(realHeight || 1));
		var maxAxis = getMaxCanvasAxis();

		var scaleX = safeWidth > maxAxis ? maxAxis / safeWidth : 1;
		var scaleY = safeHeight > maxAxis ? maxAxis / safeHeight : 1;

		var candidateW = Math.max(1, Math.round(safeWidth * scaleX));
		var candidateH = Math.max(1, Math.round(safeHeight * scaleY));

		// ALWAYS probe the exact final candidate size - even when neither axis
		// exceeded the detected per-axis limit (scaleX === scaleY === 1). Some
		// engines (e.g. Firefox) happily draw a thin 200000x2 probe (so the
		// detected per-axis limit is huge) yet fail to ALLOCATE a large-AREA
		// canvas like 1366x79566 (~434MB RGBA), leaving it permanently in an
		// error state that makes every later drawImage() throw. Only a probe at
		// the real combined size catches such area/allocation caps.
		if (!canvasCanDraw(candidateW, candidateH)) {
			// Per-axis dimensions are individually within the detected limit, but
			// this engine additionally rejects the combined size (area cap).
			// Shrink both axes together (preserving their aspect ratio) until the
			// exact candidate size actually draws.
			var extraLo = 0;
			var extraHi = 1;
			for (var i = 0; i < 25 && (extraHi - extraLo) > 0.001; i++) {
				var mid = (extraLo + extraHi) / 2;
				var w = Math.max(1, Math.round(candidateW * mid));
				var h = Math.max(1, Math.round(candidateH * mid));
				if (canvasCanDraw(w, h)) {
					extraLo = mid;
				} else {
					extraHi = mid;
				}
			}
			var extra = Math.max(extraLo, 0.01);
			scaleX *= extra;
			scaleY *= extra;
		}

		return { scaleX: scaleX, scaleY: scaleY };
	}

	/**
	 * Heatmap Detail Controller Class
	 */
	class HeatmapDetailController {
		/**
		 * Constructor
		 */
		constructor() {
			this.config = window.optiHeatmapDetail || {};
			this.currentDevice = this.config.device || 'desktop';
			this.currentType = this.config.type || 'click';
			this.pageId = parseInt(this.config.page_id, 10) || 0;
			this.pageIds = Array.isArray(this.config.page_ids) && this.config.page_ids.length
				? this.config.page_ids.map(function(id) { return parseInt(id, 10) || 0; }).filter(Boolean)
				: (this.pageId ? [this.pageId] : []);
			this.pageIdsParam = this.pageIds.join(',');
			// Coerce to integer — wp_localize_script emits integers as JS strings,
			// and `"0"` is truthy, which previously caused variant checks to
			// misfire on plain heatmap views.
			this.abTestId = parseInt(this.config.ab_test_id, 10) || 0;
			this.variantId = parseInt(this.config.variant_id, 10) || 0;
			this.heatmapInstance = null;
			this.isLoading = false;
			this.stopLoadingRequested = false; // Flag to stop batch loading on user request
			this.heatmapAlreadyRendered = false; // Flag to prevent double rendering in iframe onload
			this.pendingResetForm = null; // Form waiting for modal confirmation
			this.activeLoadId = 0; // Monotonic ID used to ignore stale async callbacks
			this.currentLoad = null; // Tracks pending data/iframe/render phases for the active load
			this.activeXhrRequests = {}; // jqXHR handles grouped by load ID so superseded loads can be aborted
			this.pendingOverlayVisible = false;

			// Interactive preview mode (default OFF). When ON the user may click
			// inside the preview iframe to reveal hidden UI (accordions, menus,
			// tabs) so recorded hotspots realign. Clicks are strictly
			// non-navigational — see _installPreviewNavigationGuard().
			this.interactiveMode = false;
			this._previewNavGuard = null;             // active navigation-guard bundle (listeners + refs)
			this._previewNavGuardUnloadPending = false; // set by beforeunload; triggers second-load restore
			this.currentPreviewUrl = '';              // preview URL currently loaded in the iframe (for restore)
			this._interactivePreviewObservers = null; // Mutation/Resize observer bundle (interactive live re-render)
			this._interactivePreviewContext = null;   // last settled render context (iframe/container/size/data) for re-render

			// Phase 1 scroll-perf state: decouple heatmap repaints from wrapper
			// scroll. While a scroll is "in flight" we pause incremental addData
			// chunks and interactive re-projection, resuming on scroll-stop.
			this._scrollPerfInstalled = false;         // guard: install handlers once
			this._scrollWrapperEl = null;              // cached .heatmap-canvas-wrapper
			this._scrollInFlight = false;              // true between scroll start and scroll-stop debounce
			this._scrollStopTimer = null;              // scroll-stop debounce timer
			this._pendingChunkResume = null;           // { loadId, fn } paused updateHeatmapOverlay chunk
			this._boundOnWrapperScroll = null;         // bound scroll listener (for teardown)
			this._interactiveRepaintPendingDuringScroll = false; // deferred interactive re-render flag

			// Scroll-depth indicator state (VWO-style hover line + tooltip on the
			// Scroll heatmap). Bands are kept in REFERENCE (page px) space and are
			// only populated for scroll_metric === 'reach' data.
			this._scrollDepthBands = null;               // sorted [{y: referencePx, value: 0-100}]
			this._scrollDepthTotalSessions = 0;          // denominator behind the reach %
			this._scrollDepthIndicatorInstalled = false; // guard: install handlers once
			this._scrollDepthLineEl = null;              // horizontal indicator line element
			this._scrollDepthTooltipEl = null;           // tooltip bubble element
			this._scrollDepthHoverLayerEl = null;        // transparent hover-capture layer over the preview iframe

			// Attention-time indicator state (Clarity-style hover line + tooltip
			// on the Attention heatmap). Density bands are cached at render time
			// (canvas/display space, with the scale factor kept alongside) and a
			// prefix-sum array makes the per-hover viewport-window share O(1).
			this._attentionBands = null;                 // { density, prefix, bandHeight, scaleY, viewportH, total }
			this._attentionTooltipEl = null;             // "Avg time spent / % of session length" tooltip box
			this._lastAvgTime = 0;                       // stats.avg_time (seconds) from the last stats payload

			// Click-count hover state ("Count Bar Display" setting): hovering a
			// click hotspot on the Click heatmap shows how many clicks landed in
			// that area. Counting runs against allCoordinates (REFERENCE px space).
			// Pro-only: the PHP config sends count_bar = 0 when Pro is inactive.
			this._clickCountContainerEl = null;          // container the click-count hover listeners are bound to
			this._clickCountHoverDoc = null;             // preview iframe document the inner hover listener is bound to
			this._clickCountTooltipEl = null;            // "N clicks in this area" tooltip bubble

			// Phase 2 scroll-perf state: viewport-windowed h337 canvas (click type).
			// Instead of one canvas covering the whole page height (hundreds of MB
			// on tall pages), the h337 canvas only spans a window of a few
			// viewports around the visible area. On scroll-stop, if the viewport
			// left the rendered window, the window is recentered and repainted
			// from _canvasSpacePoints. All values are in CANVAS pixel space (i.e.
			// after coordScale × canvasWidth/HeightScale scaling).
			this._hmWindowEl = null;         // window container div inside #heatmap-overlay
			this._hmWindowTop = 0;           // canvas-space Y of the window top
			this._hmWindowHeight = 0;        // canvas-space window height (0 = windowing off)
			this._canvasSpacePoints = null;  // ALL click points in full canvas space (pre-window)
			this._hmDataMax = 10;            // global data max so window repaints keep identical colors

			// Tab visibility handling - prevents crash when switching tabs during load
			this.isTabVisible = !document.hidden;
			this.isPausedDueToVisibility = false;
			this.pendingBatchNumber = null;
			this.pendingBatchLoadId = null;
			this.batchRetryCount = 0;
			this.maxBatchRetries = 3;

			// Initialize filter values
			// country and browser support multi-select (arrays stored as comma-separated strings)
			this.filters = {
				date_range: this.normalizeDateRange(this.config.date_range || 'all'),
				country: '',      // comma-separated country codes (e.g., "US,FR,DE")
				browser: '',      // comma-separated browser names (e.g., "Chrome,Firefox")
				visitor_type: 'guest',  // Default to Guest (logged-in users can see different page content)
				os: '',               // comma-separated OS names (e.g., "Windows,Android")
				utm_campaign: '',
				utm_source: '',
				utm_medium: '',
				duration_min: '',
				duration_max: '',
				entry_page: '',
				exit_page: '',
				referrer: '',
				traffic_channel: ''
			};
			this.customStartDate = this.normalizeDateOnly(this.config.start_date || this.config.date_from);
			this.customEndDate = this.normalizeDateOnly(this.config.end_date || this.config.date_to);
			// Only treat the range as "custom" when the URL explicitly requested
			// custom dates via query-string params. Never auto-promote "All time"
			// to a clamped custom range just because date bounds are computable
			// (e.g. prefilled From/To inputs for UX).
			const urlDateParams = new URLSearchParams(window.location.search);
			const explicitDatesRequested =
				!!(urlDateParams.get('start_date') || urlDateParams.get('date_from')) &&
				!!(urlDateParams.get('end_date') || urlDateParams.get('date_to'));
			if (explicitDatesRequested && this.customStartDate && this.customEndDate && (this.filters.date_range === 'all' || this.filters.date_range === 'custom')) {
				this.filters.date_range = 'custom';
			} else if (this.filters.date_range !== 'custom') {
				this.customStartDate = '';
				this.customEndDate = '';
			}

			if (!this.pageId) {
				this.debug('error', 'No page ID provided');
				return;
			}

			// Setup visibility change listener to handle tab switching
			this.setupVisibilityChangeHandler();

			this.init();
		}

		/**
		 * Setup visibility change handler to pause/resume loading when tab is hidden/visible
		 * This prevents the page from crashing when users switch tabs during large data loads
		 */
		setupVisibilityChangeHandler() {
			document.addEventListener('visibilitychange', () => {
				const wasVisible = this.isTabVisible;
				this.isTabVisible = !document.hidden;

				if (this.isTabVisible && !wasVisible) {
					// Tab became visible again
					this.debug('debug', 'Tab became visible - resuming operations');

					// Resume batch loading if it was paused
					if (this.isPausedDueToVisibility && this.pendingBatchNumber !== null) {
						const pendingLoadId = this.pendingBatchLoadId || this.activeLoadId;
						if (this.isStaleLoad(pendingLoadId)) {
							this.isPausedDueToVisibility = false;
							this.pendingBatchNumber = null;
							this.pendingBatchLoadId = null;
							return;
						}
						this.debug('debug', `Resuming batch loading from batch ${this.pendingBatchNumber}`);
						this.isPausedDueToVisibility = false;

						// Show a brief message to user
						this.updatePendingState(this.config.i18n.resuming_loading || 'Resuming heatmap loading...', pendingLoadId);
						this.showBatchProgress(
							this.pendingBatchNumber - 1,
							this.totalBatches || 10,
							this.allCoordinates ? this.allCoordinates.length : 0,
							this.config.i18n.resuming || 'Resuming...'
						);

						// Small delay before resuming to let the browser settle
						setTimeout(() => {
							if (!this.stopLoadingRequested && !this.isStaleLoad(pendingLoadId)) {
								this.loadHeatmapBatch(this.pendingBatchNumber, pendingLoadId);
							}
						}, 100);
					}
				} else if (!this.isTabVisible && wasVisible) {
					// Tab became hidden
					this.debug('debug', 'Tab became hidden - operations may be throttled');
				}
			});

			// Also handle page show event (for bfcache restoration)
			window.addEventListener('pageshow', (event) => {
				if (event.persisted) {
					// Page was restored from bfcache
					this.debug('debug', 'Page restored from bfcache');
					this.isTabVisible = true;

					// Check if we need to resume loading
					if (this.isPausedDueToVisibility && this.pendingBatchNumber !== null) {
						const pendingLoadId = this.pendingBatchLoadId || this.activeLoadId;
						this.isPausedDueToVisibility = false;
						setTimeout(() => {
							if (!this.stopLoadingRequested && !this.isStaleLoad(pendingLoadId)) {
								this.loadHeatmapBatch(this.pendingBatchNumber, pendingLoadId);
							}
						}, 100);
					}
				}
			});
		}

		/**
		 * Debug helper - safely calls OptiBehaviorDebug if available
		 */
		debug(level, message, data = null) {
			if (typeof window.OptiBehaviorDebug !== 'undefined' && window.OptiBehaviorDebug[level]) {
				if (data !== null) {
					window.OptiBehaviorDebug[level](message, 'heatmap-detail', data);
				} else {
					window.OptiBehaviorDebug[level](message, 'heatmap-detail');
				}
			}
		}

		/**
		 * Start a new pending overlay lifecycle and return its load ID.
		 *
		 * @param {string} reason  Short reason used for debug output.
		 * @param {string} message Pending overlay message.
		 * @returns {number} Active load ID.
		 */
		beginPendingState(reason = 'load', message = null) {
			if (this.currentLoad && !this.currentLoad.finished) {
				this.cancelActiveLoad(reason);
			}

			this.activeLoadId++;
			this.currentLoad = {
				id: this.activeLoadId,
				reason: reason,
				dataComplete: false,
				iframeComplete: false,
				renderComplete: false,
				stopped: false,
				failed: false,
				finished: false
			};
			this.pendingOverlayVisible = true;
			this.disableDownloadButton();

			const pendingMessage = message || this.config.i18n.loading || 'Loading heatmap data...';
			const $wrapper = $('.heatmap-canvas-wrapper');
			$wrapper.addClass('is-pending').attr('aria-busy', 'true');
			$('#heatmap-container').attr('aria-hidden', 'true');
			$('.heatmap-no-data').hide();
			$('.heatmap-loading')
				.addClass('opti-heatmap-pending-overlay')
				.css('display', 'flex')
				.attr({
					'role': 'status',
					'aria-live': 'polite'
				});
			$('.heatmap-loading p').text(pendingMessage);

			this.debug('debug', 'Started pending heatmap load #' + this.activeLoadId + ' (' + reason + ')');
			return this.activeLoadId;
		}

		/**
		 * Update the active pending overlay message.
		 *
		 * @param {string} message Message to display.
		 * @param {number} loadId  Optional load ID guard.
		 */
		updatePendingState(message, loadId = null) {
			const guardedLoadId = loadId || this.activeLoadId;
			if (this.isStaleLoad(guardedLoadId)) {
				return;
			}

			if (message) {
				$('.heatmap-loading p').text(message);
			}
		}

		/**
		 * Determine whether an async callback belongs to a superseded load.
		 *
		 * @param {number} loadId Load ID associated with the callback.
		 * @returns {boolean} True when the callback should no-op.
		 */
		isStaleLoad(loadId) {
			return !loadId || !this.currentLoad || this.currentLoad.id !== loadId || this.activeLoadId !== loadId;
		}

		/**
		 * Track a jqXHR handle so it can be aborted when a newer load starts.
		 *
		 * @param {number} loadId  Load ID associated with the request.
		 * @param {Object} request jqXHR object.
		 */
		trackXhr(loadId, request) {
			if (!loadId || !request) {
				return;
			}

			if (!this.activeXhrRequests[loadId]) {
				this.activeXhrRequests[loadId] = [];
			}
			this.activeXhrRequests[loadId].push(request);
		}

		/**
		 * Stop tracking a completed jqXHR handle.
		 *
		 * @param {number} loadId  Load ID associated with the request.
		 * @param {Object} request jqXHR object.
		 */
		untrackXhr(loadId, request) {
			if (!loadId || !request || !this.activeXhrRequests[loadId]) {
				return;
			}

			this.activeXhrRequests[loadId] = this.activeXhrRequests[loadId].filter(function(activeRequest) {
				return activeRequest !== request;
			});

			if (!this.activeXhrRequests[loadId].length) {
				delete this.activeXhrRequests[loadId];
			}
		}

		/**
		 * Mark the active load's data phase complete.
		 *
		 * @param {number} loadId Load ID to update.
		 */
		markDataComplete(loadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}
			this.currentLoad.dataComplete = true;
		}

		/**
		 * Mark the active load's iframe/background phase complete.
		 *
		 * @param {number} loadId Load ID to update.
		 */
		markIframeComplete(loadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}
			this.currentLoad.iframeComplete = true;
		}

		/**
		 * Mark the active load's render phase complete.
		 *
		 * @param {number} loadId Load ID to update.
		 */
		markRenderComplete(loadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}
			this.currentLoad.renderComplete = true;
		}

		/**
		 * Finish the pending state only when the active load has reached a safe UI state.
		 *
		 * @param {number} loadId Load ID to finish.
		 * @param {string} status Optional status: complete, stopped, error.
		 * @returns {boolean} True when the overlay was hidden.
		 */
		finishPendingState(loadId, status = 'complete') {
			if (this.isStaleLoad(loadId)) {
				return false;
			}

			const load = this.currentLoad;
			if (load.finished) {
				return false;
			}
			if (status === 'error') {
				load.failed = true;
			}
			if (status === 'stopped') {
				load.stopped = true;
			}

			const canFinish = load.failed || ((load.dataComplete || load.stopped) && load.iframeComplete && load.renderComplete);
			if (!canFinish) {
				return false;
			}

			load.finished = true;
			this.pendingOverlayVisible = false;
			$('.heatmap-canvas-wrapper').removeClass('is-pending').attr('aria-busy', 'false');
			$('#heatmap-container').attr('aria-hidden', 'false');
			$('.heatmap-loading').hide();
			$('.opti-heatmap-stop-loading-btn').remove();
			this.isLoading = false;

			if (!load.failed && this.allCoordinates && this.allCoordinates.length > 0) {
				this.enableDownloadButton();
			}

			this.debug('debug', 'Finished pending heatmap load #' + loadId + ' (' + status + ')');
			return true;
		}

		/**
		 * Cancel the current load and abort outstanding AJAX requests when feasible.
		 *
		 * @param {string} reason Cancellation reason for debug output.
		 */
		cancelActiveLoad(reason = 'cancelled') {
			const load = this.currentLoad;
			if (load && !load.finished) {
				load.stopped = true;
				load.finished = true;
			}

			Object.keys(this.activeXhrRequests).forEach((loadKey) => {
				(this.activeXhrRequests[loadKey] || []).forEach((request) => {
					if (request && request.readyState !== 4 && typeof request.abort === 'function') {
						try {
							request.abort();
						} catch (abortError) {
							this.debug('warn', 'Could not abort heatmap request:', abortError);
						}
					}
				});
			});
			this.activeXhrRequests = {};

			this.activeLoadId++;
			this.currentLoad = null;
			this.isLoading = false;
			this.isPausedDueToVisibility = false;
			this.pendingBatchNumber = null;
			this.pendingBatchLoadId = null;
			this.iframeRenderPending = false;
			this.pendingHeatmapData = null;
			this.debug('debug', 'Cancelled active heatmap load (' + reason + ')');
		}


		/**
		 * Normalize Smart Insights date ranges into heatmap filter keys.
		 */
		normalizeDateRange(value) {
			const key = String(value || '').toLowerCase().replace(/[^a-z0-9_]/g, '');
			const map = {
				last_7_days: 'last7days',
				last7days: 'last7days',
				'7days': 'last7days',
				last_30_days: 'last30days',
				last30days: 'last30days',
				'30days': 'last30days',
				today: 'today',
				yesterday: 'yesterday',
				custom: 'custom',
				all: 'all'
			};
			return map[key] || 'all';
		}

		/**
		 * Normalize a date or datetime to YYYY-MM-DD.
		 */
		normalizeDateOnly(value) {
			const match = String(value || '').trim().match(/^(\d{4}-\d{2}-\d{2})/);
			return match ? match[1] : '';
		}

		/**
		 * Add custom Smart Insights date bounds to an AJAX payload when present.
		 */
		addDatePayload(data) {
			if (this.filters.date_range === 'custom' && this.customStartDate && this.customEndDate) {
				data.start_date = this.customStartDate;
				data.end_date = this.customEndDate;
			}

			// Append advanced filters so every AJAX call site sends them.
			const advancedKeys = [
				'os', 'utm_campaign', 'utm_source', 'utm_medium',
				'duration_min', 'duration_max',
				'entry_page', 'exit_page', 'referrer', 'traffic_channel'
			];
			advancedKeys.forEach((key) => {
				const value = this.filters[key];
				if (value !== undefined && value !== null && String(value) !== '') {
					data[key] = value;
				}
			});

			return data;
		}

		/**
		 * Measure the real iframe document height, including late footer/theme wrappers.
		 *
		 * scrollHeight can under-report on some themes when footers are positioned inside
		 * flex/page wrappers, so also inspect bottom edges for common WordPress wrappers.
		 */
		_measureIframeContentHeight(doc, minimumHeight = 0) {
			if (!doc || !doc.body || !doc.documentElement) {
				return minimumHeight || 0;
			}

			const win = doc.defaultView || {};
			const body = doc.body;
			const html = doc.documentElement;
			const scrollTop = win.pageYOffset || html.scrollTop || body.scrollTop || 0;
			const heights = [
				minimumHeight || 0,
				body.scrollHeight || 0,
				html.scrollHeight || 0,
				body.offsetHeight || 0,
				html.offsetHeight || 0,
				body.clientHeight || 0,
				html.clientHeight || 0
			];

			const selector = [
				'footer',
				'#footer',
				'.site-footer',
				'.main-footer',
				'.elementor-location-footer',
				'#page',
				'#wrap',
				'#wrapper',
				'.site',
				'.site-content',
				'#content',
				'main',
				'body > *'
			].join(',');

			try {
				doc.querySelectorAll(selector).forEach(function(el) {
					const rect = el.getBoundingClientRect ? el.getBoundingClientRect() : null;
					if (!rect || rect.height <= 0) {
						return;
					}

					let marginBottom = 0;
					if (win.getComputedStyle) {
						const style = win.getComputedStyle(el);
						if (style && style.display === 'none') {
							return;
						}
						marginBottom = parseFloat(style && style.marginBottom) || 0;
					}

					const bottom = rect.bottom + scrollTop + marginBottom;
					if (isFinite(bottom) && bottom > 0) {
						heights.push(bottom);
					}
				});
			} catch (selectorErr) {
				this.debug('warn', 'Footer/wrapper height scan failed:', selectorErr);
			}

			return Math.ceil(Math.max.apply(Math, heights));
		}

		/**
		 * Apply iframe/container dimensions consistently and keep coordinate mapping in sync.
		 */
		_applyIframeContentSize(iframe, container, actualWidth, actualHeight, containerWidth) {
			const safeWidth = Math.max(Math.ceil(actualWidth || 0), 1);
			const safeHeight = Math.max(Math.ceil(actualHeight || 0), 1);
			const availableWidth = this._getVisibleHeatmapWrapperWidth(container, containerWidth || safeWidth);
			const fitScale = safeWidth > availableWidth ? availableWidth / safeWidth : 1;
			const viewportWidth = window.innerWidth || document.documentElement.clientWidth || availableWidth;
			let minimumPreviewScale = 0;

			if (viewportWidth <= 430) {
				minimumPreviewScale = this.currentDevice === 'desktop' ? 0.6 : 0.62;
			} else if (viewportWidth <= 782) {
				minimumPreviewScale = this.currentDevice === 'desktop' ? 0.56 : 0.72;
			}

			const scale = fitScale < 1 ? Math.max(fitScale, minimumPreviewScale) : fitScale;
			const scaledWidth = safeWidth * scale;
			const minimumScaleFloorApplied = fitScale < 1 && minimumPreviewScale > fitScale;
			const displayWidth = minimumScaleFloorApplied ? Math.ceil(scaledWidth) : availableWidth;

			this.idealWidth = safeWidth;
			this.idealHeight = safeHeight;
			this.canvasScaleFactor = scale;

			iframe.style.width = safeWidth + 'px';
			iframe.style.height = safeHeight + 'px';

			if (scale < 1) {
				iframe.style.transformOrigin = 'top left';
				iframe.style.transform = 'scale(' + scale + ')';
				container.style.width = displayWidth + 'px';
				container.style.height = Math.ceil(safeHeight * scale) + 'px';
			} else {
				iframe.style.transform = 'none';
				container.style.width = safeWidth + 'px';
				container.style.height = safeHeight + 'px';
			}

			// Center a device-matched preview when it is narrower than the panel
			// (mobile/tablet widths). The heatmap overlay is absolutely positioned
			// inside the container, so centering the container keeps the canvas
			// aligned pixel-for-pixel over the iframe.
			container.style.marginLeft = 'auto';
			container.style.marginRight = 'auto';

			return scale;
		}

		/**
		 * Get the visible wrapper width, not the scroll-expanded width created by the heatmap preview.
		 */
		_getVisibleHeatmapWrapperWidth(container, fallbackWidth) {
			const widths = [];
			const fallback = parseFloat(fallbackWidth);

			if (isFinite(fallback) && fallback > 0) {
				widths.push(fallback);
			}

			const wrapper = container && container.parentElement ? container.parentElement : null;

			if (wrapper) {
				if (wrapper.clientWidth > 0) {
					widths.push(wrapper.clientWidth);
				}

				if (wrapper.getBoundingClientRect) {
					const rect = wrapper.getBoundingClientRect();
					if (rect && rect.width > 0) {
						widths.push(rect.width);
					}
				}

				try {
					const style = window.getComputedStyle(wrapper);
					const paddingLeft = parseFloat(style.paddingLeft) || 0;
					const paddingRight = parseFloat(style.paddingRight) || 0;
					const contentWidth = wrapper.clientWidth - paddingLeft - paddingRight;

					if (contentWidth > 0) {
						widths.push(contentWidth);
					}
				} catch (styleErr) {
					this.debug('warn', 'Could not measure heatmap wrapper padding:', styleErr);
				}
			}

			if (!widths.length) {
				return Math.max(Math.ceil(fallback) || 1, 1);
			}

			return Math.max(Math.floor(Math.min.apply(Math, widths)), 1);
		}

		/**
		 * Measure late-loading iframe content without letting 100vh / min-height wrappers
		 * track the iframe's current height and create a runaway resize feedback loop.
		 *
		 * The iframe is temporarily shrunk to 100px during measurement, so any
		 * viewport-relative sizing (100vh, min-height:100%, flex-grow stretch) in ANY
		 * theme collapses naturally — the measured value is independent of the height
		 * currently applied to the iframe. That independence is what makes bidirectional
		 * (shrink + grow) correction safe: re-measuring always converges to the same
		 * settled content height instead of chasing the applied height.
		 *
		 * @param {HTMLIFrameElement} iframe
		 * @param {number}  fallbackHeight Height returned when the document is unreadable.
		 * @param {boolean} allowShrink    When true, the fallback is NOT used as a floor,
		 *                                 so the returned value can be smaller than the
		 *                                 previously applied height (dynamic pages that
		 *                                 settle shorter: collapsed lists, resolved
		 *                                 spinners, removed placeholders).
		 */
		_measureStableLateIframeHeight(iframe, fallbackHeight = 0, allowShrink = false) {
			const doc = iframe.contentDocument || iframe.contentWindow.document;
			if (!doc || !doc.body || !doc.documentElement) {
				return fallbackHeight || 0;
			}

			const previousHeight = iframe.style.height;
			const previousVisibility = iframe.style.visibility;
			const measureStyle = doc.createElement('style');
			measureStyle.textContent = 'html, body { height: auto !important; min-height: 0 !important; overflow: visible !important; } ' +
				'rs-fullwidth-wrap, rs-module-wrap, ' +
				'.slick-slider, .flexslider, ' +
				'.swiper-container, .swiper, ' +
				'.ls-wp-container, .ls-container, ' +
				'.n2-ss-slider, ' +
				'.owl-carousel, ' +
				'.elementor-slides-wrapper, .elementor-widget-slides ' +
				'{ display: none !important; }';

			let measuredHeight = fallbackHeight || 0;

			try {
				iframe.style.visibility = 'hidden';
				iframe.style.height = '100px';
				doc.head.appendChild(measureStyle);
				void doc.body.offsetHeight;

				// When shrinking is allowed the previous height must NOT floor the
				// result, otherwise an early over-measurement (stretched 100vh
				// wrappers, unresolved spinners) becomes permanent trailing
				// whitespace. A 200px sanity floor guards against measuring a
				// half-torn-down document.
				const shrinkFloor = allowShrink ? 200 : (fallbackHeight || 0);
				measuredHeight = Math.max(
					shrinkFloor,
					doc.body.scrollHeight || 0,
					doc.documentElement.scrollHeight || 0,
					doc.body.offsetHeight || 0,
					doc.documentElement.offsetHeight || 0,
					this._measureIframeContentHeight(doc, allowShrink ? 0 : (fallbackHeight || 0))
				);
			} finally {
				if (measureStyle.parentNode) {
					measureStyle.parentNode.removeChild(measureStyle);
				}
				iframe.style.height = previousHeight;
				iframe.style.visibility = previousVisibility;
			}

			return Math.ceil(measuredHeight);
		}

		/**
		 * Watch for late iframe layout growth from lazy images, Elementor, or theme footers.
		 */
		_startIframeHeightWatcher(iframe, container, actualWidth, containerWidth, initialHeight, data, viewport, loadId = this.activeLoadId) {
			const self = this;
			let lastMeasuredH = initialHeight || 0;
			let debounceTimer = null;
			let stopped = false;
			let resizeObserver = null;
			let mutationObserver = null;
			const referenceHeight = data && data.reference_height ? parseFloat(data.reference_height) : 0;
			const maxAllowedHeight = Math.max(
				(initialHeight || 0) * 1.6,
				(initialHeight || 0) + 3000,
				referenceHeight * 1.6,
				8000
			);

			// Remember this settled render context so the interactive-preview
			// observers can re-measure + reproject against the SAME iframe/
			// container/size when the visitor expands hidden UI. Reuses the
			// height cap above to stay inside the 100vh feedback-loop guard.
			this._interactivePreviewContext = {
				iframe: iframe,
				container: container,
				actualWidth: actualWidth,
				containerWidth: containerWidth,
				height: initialHeight || 0,
				data: data,
				viewport: viewport,
				loadId: loadId,
				maxAllowedHeight: maxAllowedHeight
			};

			const stopWatching = function() {
				stopped = true;
				if (debounceTimer) {
					clearTimeout(debounceTimer);
				}
				if (resizeObserver) {
					resizeObserver.disconnect();
				}
				if (mutationObserver) {
					mutationObserver.disconnect();
				}
			};

			// Bidirectional settle correction: dynamic pages (lazy lists, async
			// widgets, resolved spinners) can settle SHORTER than the first
			// measurement, so shrinking must be allowed or the preview keeps
			// permanent trailing whitespace below the real footer. Convergence is
			// safe because _measureStableLateIframeHeight measures at a fixed
			// 100px iframe height (independent of the applied height), so
			// repeated re-measures cannot feed back on themselves. A bounded
			// apply-count is kept as a belt-and-suspenders oscillation guard.
			let appliesLeft = 15;
			const remeasure = function(reason) {
				if (stopped || self.isStaleLoad(loadId)) {
					return;
				}

				try {
					const rDoc = iframe.contentDocument || iframe.contentWindow.document;
					if (!rDoc || !rDoc.body) {
						return;
					}

					let measuredH = self._measureStableLateIframeHeight(iframe, lastMeasuredH, true);
					if (measuredH > maxAllowedHeight) {
						self.debug('warn', 'Late iframe height capped to prevent viewport feedback loop (' + reason + '): ' + measuredH + ' -> ' + maxAllowedHeight, 'heatmap-detail');
						measuredH = maxAllowedHeight;
						stopped = true;
					}

					if (Math.abs(measuredH - lastMeasuredH) > 50 && measuredH >= 200 && appliesLeft > 0) {
						appliesLeft--;
						self.debug('debug', 'Late iframe height correction (' + reason + '): ' + measuredH + ' vs ' + lastMeasuredH, 'heatmap-detail');
						lastMeasuredH = measuredH;
						if (self._interactivePreviewContext && self._interactivePreviewContext.iframe === iframe) {
							self._interactivePreviewContext.height = measuredH;
						}
						self._applyIframeContentSize(iframe, container, actualWidth, measuredH, containerWidth);
						self.createOverlayAndRenderHeatmap(data, viewport, loadId);
					}
				} catch (watchErr) {
					self.debug('warn', 'Late iframe height check failed (' + reason + '):', watchErr);
				}
			};

			const scheduleRemeasure = function(reason) {
				if (stopped) {
					return;
				}
				if (debounceTimer) {
					clearTimeout(debounceTimer);
				}
				debounceTimer = setTimeout(function() {
					remeasure(reason);
				}, 200);
			};

			try {
				const watchedDoc = iframe.contentDocument || iframe.contentWindow.document;
				// Debounced observers ARE safe now: _measureStableLateIframeHeight
				// measures at a fixed 100px iframe height, so the measured value is
				// independent of the applied height and 100vh/min-height wrappers
				// cannot create a resize feedback loop. The remeasure() hysteresis
				// (>50px) plus the bounded apply-count keep repaints rare.
				if (watchedDoc && watchedDoc.body) {
					if (typeof ResizeObserver !== 'undefined') {
						resizeObserver = new ResizeObserver(function() {
							scheduleRemeasure('resize-observer');
						});
						resizeObserver.observe(watchedDoc.documentElement);
						resizeObserver.observe(watchedDoc.body);
					}

					if (typeof MutationObserver !== 'undefined') {
						mutationObserver = new MutationObserver(function() {
							scheduleRemeasure('mutation-observer');
						});
						mutationObserver.observe(watchedDoc.body, {
							childList: true,
							subtree: true,
							attributes: true,
							attributeFilter: ['class', 'style', 'height']
						});
					}
				}
			} catch (observerErr) {
				this.debug('warn', 'Could not start iframe height observers:', observerErr);
			}

			[2000, 5000, 8000, 12000, 16000, 22000].forEach(function(delay) {
				setTimeout(function() {
					if (stopped || self.isStaleLoad(loadId)) {
						return;
					}
					// Safety net for async widgets whose loader never resolves
					// inside the preview: after a generous budget, collapse
					// still-spinning loader placeholders so they stop reserving
					// indeterminate height, then re-measure.
					if (delay >= 12000) {
						try {
							const nDoc = iframe.contentDocument || iframe.contentWindow.document;
							if (nDoc && nDoc.body) {
								self._neutralizeStuckLoaders(nDoc);
							}
						} catch (loaderErr) {
							self.debug('warn', 'Stuck-loader neutralization failed:', loaderErr);
						}
					}
					remeasure(delay + 'ms');
				}, delay);
			});

			setTimeout(stopWatching, 30000);
		}

		/**
		 * Collapse loading spinners/placeholders that are still visible long after
		 * the preview settled. Root causes are fixed server-side where possible
		 * (preview context is now propagated to admin-ajax/REST so page-minted
		 * nonces stay valid), but ANY third-party widget can still stall inside an
		 * embedded preview — this last-resort net keeps a stuck loader from
		 * reserving indeterminate height and being baked into the render.
		 *
		 * Deliberately conservative: only hides small, leaf-ish elements whose
		 * class names look like loaders AND that are still visible. Never touches
		 * html/body or large content blocks.
		 *
		 * @param {Document} doc Preview iframe document.
		 */
		_neutralizeStuckLoaders(doc) {
			const self = this;
			const viewportH = Math.max(window.innerHeight || 800, 800);
			let candidates;
			try {
				candidates = doc.querySelectorAll('[class*="load" i], [class*="spinner" i], [class*="preload" i]');
			} catch (qErr) {
				// Older engines without the "i" attribute flag.
				candidates = doc.querySelectorAll('[class*="load"], [class*="spinner"], [class*="preload"]');
			}

			candidates.forEach(function(el) {
				try {
					if (el === doc.body || el === doc.documentElement) {
						return;
					}
					if (el.getAttribute('data-opti-loader-neutralized')) {
						return;
					}
					const cls = String(el.className || '').toLowerCase();
					// Class must actually look like a loader, not e.g. "download" or "payload".
					if (!/(^|[\s_-])(pre)?load(er|ing)?([\s_-]|$)|spinner|[\s_-]spin([\s_-]|$)/.test(cls)) {
						return;
					}
					const rect = el.getBoundingClientRect ? el.getBoundingClientRect() : null;
					if (!rect || rect.width <= 0 || rect.height <= 0) {
						return; // Already hidden — nothing to do.
					}
					// Skip anything that looks like real content rather than a placeholder.
					if (rect.height > viewportH * 0.6 || el.querySelectorAll('*').length > 30) {
						return;
					}
					el.setAttribute('data-opti-loader-neutralized', '1');
					el.style.setProperty('display', 'none', 'important');
					self.debug('debug', 'Neutralized stuck loader element: ' + cls.slice(0, 80), 'heatmap-detail');
				} catch (elErr) {
					// Best-effort per element.
				}
			});
		}

		/**
		 * Initialize controller
		 */
		init() {
			this.bindEvents();
			this._installScrollPerfHandlers();
			this._installScrollDepthIndicatorHandlers();
			this.updateStatItemsVisibility();

			// Set initial filter values in UI
			$('#filter-date-range').val(this.filters.date_range).toggleClass('has-value', this.filters.date_range !== 'all');
			// Mirror the active preset in the From/To inputs on initial load.
			// "custom" keeps the PHP-rendered values (explicit URL dates).
			if (this.filters.date_range !== 'custom') {
				this.prefillDateInputs(this.filters.date_range);
			}
			// Guest is the default visitor-type: no "active filter" styling for it.
			$('#filter-visitor-type').val(this.filters.visitor_type).toggleClass('has-value', this.filters.visitor_type !== 'guest');

			// Set initial A/B variant selector value if loaded from URL params
			if (this.abTestId > 0 && this.variantId > 0) {
				$('#filter-ab-variant').val(this.abTestId + ':' + this.variantId).addClass('has-value');
			}

			// Initialize Lucide icons
			this.debug('debug', 'Initializing Lucide icons...');
			this.debug('debug', 'typeof lucide:', typeof lucide);
			if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
				this.debug('debug', 'Calling lucide.createIcons()...');
				lucide.createIcons();
				this.debug('debug', 'Lucide icons initialized successfully');
			} else {
				this.debug('error', 'Lucide library not loaded!');
			}

			// Launch parallel async operations (two-thread approach):
			// Thread 1 (Fast): Load filter options from filenames - populates dropdowns quickly
			// Thread 2 (Slower): Load heatmap data and stats - renders heatmap progressively
			this.loadFilterOptions();  // Fast - scans filenames only
			this.loadTotalRecordingsCount(); // Header total, scoped to current view + filters
			this.loadDeviceCounts();
			this.loadHeatmap();        // Slower - reads file contents
			this.loadStats();
		}

		/**
		 * Update stat items visibility and labels based on current type
		 */
		updateStatItemsVisibility() {
			// Attention heatmap uses scroll data, so show scroll stats for both scroll and attention types
			if (this.currentType === 'scroll' || this.currentType === 'attention') {
				$('.stat-clicks-item').hide();
				$('.stat-scroll-item').show();
				$('.stat-move-item').hide();
			} else if (this.currentType === 'move') {
				$('.stat-clicks-item').hide();
				$('.stat-scroll-item').hide();
				$('.stat-move-item').show();
			} else {
				$('.stat-clicks-item').show();
				$('.stat-scroll-item').hide();
				$('.stat-move-item').hide();
			}
		}

		/**
		 * Bind UI event handlers
		 */
		bindEvents() {
			// Device switcher
			$('.device-btn').on('click', (e) => {
				const device = $(e.currentTarget).data('device');
				if (device && device !== this.currentDevice) {
					this.switchDevice(device);
				}
			});

			// Type tabs
			$('.heatmap-tab').on('click', (e) => {
				const $btn = $(e.currentTarget);
				if ($btn.hasClass('disabled') || $btn.hasClass('pro-only')) {
					return;
				}
				const type = $btn.data('type');
				if (type && type !== this.currentType) {
					this.switchType(type);
				}
			});

			// Refresh button
			$('#refresh-heatmap').on('click', () => {
				this.refreshHeatmap();
			});

			// Strict matching toggle (element-anchored clicks). ON by default:
			// anchored clicks whose element no longer exists in the preview are
			// hidden instead of falling back to absolute coordinates.
			$('#strict-matching-toggle').on('click', () => {
				this.toggleStrictMatching();
			});

			// Interactive preview toggle. Default OFF. When ON, pointer events are
			// enabled on the preview iframe so the user can expand hidden UI
			// (accordions/menus) and the hotspots realign. Clicks are non-navigational.
			$('#interactive-preview-toggle').on('click', () => {
				this.toggleInteractivePreview();
			});

			// Download button
			$('#download-heatmap').on('click', () => {
				this.downloadHeatmapImage();
			});

			this.initResetHeatmapModal();

			// View toggle (Simple/Detailed)
			$('.view-btn').on('click', (e) => {
				const view = $(e.currentTarget).data('view');
				$('.view-btn').removeClass('active');
				$(e.currentTarget).addClass('active');
				this.statsView = view === 'detailed' ? 'detailed' : 'simple';
				if (this.lastTopElements && this.lastTopElements.length) {
					this.renderTopElements(this.lastTopElements);
				}
			});

			// Filter dropdowns (single-select)
			$('.filter-select').on('change', (e) => {
				const $select = $(e.currentTarget);
				const filterName = $select.data('filter');
				const filterValue = $select.val();

				// A/B variant selector: parse "test_id:variant_id" and reload
				if (filterName === 'ab_variant') {
					this.switchVariant(filterValue);
					return;
				}

				// Custom period: wait for the Apply button (needs both dates).
				if (filterName === 'date_range' && filterValue === 'custom') {
					if (!this.normalizeDateOnly($('#heatmap-start-date').val()) || !this.normalizeDateOnly($('#heatmap-end-date').val())) {
						$('#heatmap-start-date').trigger('focus');
						return;
					}
					this.applyDateRangeSelection();
					return;
				}

				// Dashboard-style: reflect the selected period in the From/To inputs.
				if (filterName === 'date_range') {
					this.prefillDateInputs(filterValue);
				}

				this.updateFilter(filterName, filterValue);
			});

			// Manually editing a date input switches the period to "Custom range"
			// so the Apply button uses the edited dates instead of the preset.
			$('#heatmap-start-date, #heatmap-end-date').on('change', () => {
				$('#filter-date-range').val('custom').addClass('has-value');
			});

			// Multi-select dropdowns
			this.initMultiSelectDropdowns();

			// Reset filters button
			$('#reset-filters').on('click', () => {
				this.resetFilters();
			});

			// Advanced filters panel toggle (dashboard-style)
			$('#toggle-advanced-filters').on('click', () => {
				const $panel = $('#advanced-filters-panel');
				const isOpen = $panel.is(':visible');
				$panel.slideToggle(150);
				$('#toggle-advanced-filters')
					.attr('aria-expanded', String(!isOpen))
					.toggleClass('active', !isOpen);
			});

			// Apply custom date range (dashboard-style toolbar)
			$('#apply-heatmap-range').on('click', () => {
				this.applyDateRangeSelection();
			});

			// Hero refresh button mirrors the filters-bar #refresh-heatmap.
			$(document).on('click', '.opti-hero-refresh', () => {
				$('#refresh-heatmap').trigger('click');
			});

			// Apply/Reset advanced filters panel
			$('#apply-advanced-filters').on('click', () => {
				this.applyAdvancedFilters();
			});
			$('#reset-advanced-filters').on('click', () => {
				this.resetAdvancedFilters();
			});
		}

		/**
		 * Apply the toolbar period + custom date inputs.
		 * Custom range takes effect when both dates are set; otherwise
		 * the selected period is applied as-is.
		 */
		applyDateRangeSelection() {
			const period = $('#filter-date-range').val() || 'all';
			const start = this.normalizeDateOnly($('#heatmap-start-date').val());
			const end = this.normalizeDateOnly($('#heatmap-end-date').val());

			if (period === 'custom') {
				if (!start || !end) {
					$('#heatmap-start-date').trigger('focus');
					return;
				}
				this.customStartDate = start;
				this.customEndDate = end;
			} else {
				this.customStartDate = '';
				this.customEndDate = '';
			}

			this.updateFilter('date_range', period);
			this.loadFilterOptions();
			this.loadTotalRecordingsCount();
			this.loadDeviceCounts();
		}

		/**
		 * Dashboard-style autofill of the From/To inputs for a non-custom
		 * period ("All time" shows the recorded first -> last session dates).
		 */
		prefillDateInputs(period) {
			if (period === 'custom') {
				return;
			}

			const fmt = (d) => {
				const p = (n) => String(n).padStart(2, '0');
				return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
			};
			const today = new Date();
			let start = null;
			let end = today;

			switch (period) {
				case 'today':
					start = today;
					break;
				case 'yesterday':
					start = new Date(today);
					start.setDate(start.getDate() - 1);
					end = start;
					break;
				case '7days':
				case 'last7days':
					start = new Date(today);
					start.setDate(start.getDate() - 6);
					break;
				case '30days':
				case 'last30days':
					start = new Date(today);
					start.setDate(start.getDate() - 29);
					break;
				default: {
					// "All time": recorded first session -> today.
					const $controls = $('.opti-heatmap-hero-controls');
					$('#heatmap-start-date').val($controls.data('firstSession') || '');
					$('#heatmap-end-date').val(fmt(today));
					return;
				}
			}

			$('#heatmap-start-date').val(fmt(start));
			$('#heatmap-end-date').val(fmt(end));
		}

		/**
		 * Collect all advanced panel values into filter state.
		 */
		collectAdvancedFilters() {
			this.filters.visitor_type = $('#filter-visitor-type').val() || '';
			this.filters.duration_min = $('#filter-duration-min').val() || '';
			this.filters.duration_max = $('#filter-duration-max').val() || '';
			this.filters.entry_page = ($('#filter-entry-page').val() || '').trim();
			this.filters.exit_page = ($('#filter-exit-page').val() || '').trim();
			this.filters.referrer = ($('#filter-referrer').val() || '').trim();
			this.filters.traffic_channel = $('#filter-traffic-channel').val() || '';
			this.filters.utm_campaign = this.getMultiSelectValues('utm_campaign');
			this.filters.utm_source = this.getMultiSelectValues('utm_source');
			this.filters.utm_medium = this.getMultiSelectValues('utm_medium');
			this.filters.browser = this.getMultiSelectValues('browser');
			this.filters.country = this.getMultiSelectValues('country');
			this.filters.os = this.getMultiSelectValues('os');
		}

		/**
		 * Apply all advanced filters and reload data.
		 */
		applyAdvancedFilters() {
			this.collectAdvancedFilters();
			this.updateAdvancedFiltersBadge();
			this.loadDependentFilterOptions();
			this.loadTotalRecordingsCount();
			this.loadDeviceCounts();
			this.loadHeatmap();
			this.loadStats();
		}

		/**
		 * Reset only the advanced panel filters and reload data.
		 */
		resetAdvancedFilters() {
			$('#filter-duration-min, #filter-duration-max, #filter-entry-page, #filter-exit-page, #filter-referrer').val('');
			$('#filter-traffic-channel').val('');
			$('#filter-visitor-type').val('guest');

			$('#advanced-filters-panel .multiselect-dropdown').each((index, dropdown) => {
				const $dropdown = $(dropdown);
				$dropdown.find('input[type="checkbox"]').prop('checked', false);
				$dropdown.removeClass('has-selection');
				this.updateMultiSelectLabel($dropdown);
			});

			this.filters.country = '';
			this.filters.browser = '';
			this.filters.os = '';
			this.filters.visitor_type = 'guest';
			this.filters.utm_campaign = '';
			this.filters.utm_source = '';
			this.filters.utm_medium = '';
			this.filters.duration_min = '';
			this.filters.duration_max = '';
			this.filters.entry_page = '';
			this.filters.exit_page = '';
			this.filters.referrer = '';
			this.filters.traffic_channel = '';

			this.updateAdvancedFiltersBadge();
			this.loadFilterOptions();
			this.loadTotalRecordingsCount();
			this.loadDeviceCounts();
			this.loadHeatmap();
			this.loadStats();
		}

		/**
		 * Show an active-count badge on the Filters toggle button.
		 */
		updateAdvancedFiltersBadge() {
			const keys = [
				'country', 'browser', 'os',
				'utm_campaign', 'utm_source', 'utm_medium',
				'duration_min', 'duration_max',
				'entry_page', 'exit_page', 'referrer', 'traffic_channel'
			];
			let count = keys.reduce((n, k) => n + (this.filters[k] && String(this.filters[k]) !== '' ? 1 : 0), 0);
			// Guest is the default: badge only when the user picked All Visitors ('')
			// or Logged In explicitly.
			if (this.filters.visitor_type !== 'guest') {
				count++;
			}

			const $btn = $('#toggle-advanced-filters');
			$btn.toggleClass('has-active-filters', count > 0);
			let $badge = $btn.find('.adv-filter-count');
			if (count > 0) {
				if (!$badge.length) {
					$badge = $('<span class="adv-filter-count"></span>');
					$btn.append($badge);
				}
				$badge.text(count);
			} else {
				$badge.remove();
			}
		}

		/**
		 * Initialize the custom reset confirmation modal.
		 */
		initResetHeatmapModal() {
			const self = this;
			const $modal = $('#opti-heatmap-reset-modal');

			$('.opti-reset-heatmap-form').on('submit', function(e) {
				e.preventDefault();
				self.pendingResetForm = this;
				self.showResetHeatmapModal();
			});

			$('#opti-heatmap-reset-modal-cancel').on('click', () => {
				this.hideResetHeatmapModal();
			});

			$('#opti-heatmap-reset-modal-confirm').on('click', () => {
				const form = this.pendingResetForm;
				this.hideResetHeatmapModal();

				if (form) {
					HTMLFormElement.prototype.submit.call(form);
				}
			});

			$modal.on('click', (e) => {
				if (e.target === e.currentTarget) {
					this.hideResetHeatmapModal();
				}
			});

			$(document).on('keydown.optiHeatmapResetModal', (e) => {
				if (e.key !== 'Escape' && e.keyCode !== 27) {
					return;
				}

				if ($modal.hasClass('is-visible')) {
					this.hideResetHeatmapModal();
					e.preventDefault();
					e.stopPropagation();
				}
			});
		}

		/**
		 * Show the custom reset confirmation modal.
		 */
		showResetHeatmapModal() {
			$('#opti-heatmap-reset-modal')
				.removeAttr('style')
				.addClass('opti-ab-confirm-overlay is-visible');
			$('#opti-heatmap-reset-modal-cancel').trigger('focus');

			if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
				lucide.createIcons();
			}
		}

		/**
		 * Hide the custom reset confirmation modal.
		 */
		hideResetHeatmapModal() {
			$('#opti-heatmap-reset-modal')
				.removeClass('is-visible opti-ab-confirm-overlay')
				.css('display', 'none');
			this.pendingResetForm = null;
		}

		/**
		 * Initialize multi-select dropdown components
		 */
		initMultiSelectDropdowns() {
			const self = this;

			// Toggle dropdown on trigger click
			$(document).on('click', '.multiselect-trigger', function(e) {
				e.stopPropagation();
				const $dropdown = $(this).closest('.multiselect-dropdown');
				const wasOpen = $dropdown.hasClass('open');

				// Close all other dropdowns
				$('.multiselect-dropdown').removeClass('open');

				// Toggle this dropdown
				if (!wasOpen) {
					$dropdown.addClass('open');
				}
			});

			// Close dropdowns when clicking outside
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.multiselect-dropdown').length) {
					$('.multiselect-dropdown').removeClass('open');
				}
			});

			// Search functionality
			$(document).on('input', '.multiselect-search-input', function() {
				const searchTerm = $(this).val().toLowerCase();
				const $options = $(this).closest('.multiselect-menu').find('.multiselect-option');

				$options.each(function() {
					const text = $(this).text().toLowerCase();
					$(this).toggle(text.includes(searchTerm));
				});
			});

			// Clear button
			$(document).on('click', '.multiselect-clear', function(e) {
				e.stopPropagation();
				const $dropdown = $(this).closest('.multiselect-dropdown');
				$dropdown.find('input[type="checkbox"]').prop('checked', false);
				self.updateMultiSelectLabel($dropdown);
			});

			// Apply button - applies the filter and closes dropdown
			$(document).on('click', '.multiselect-apply', function(e) {
				e.stopPropagation();
				const $dropdown = $(this).closest('.multiselect-dropdown');
				const filterName = $dropdown.data('filter');

				// Get selected values
				const selectedValues = [];
				$dropdown.find('input[type="checkbox"]:checked').each(function() {
					selectedValues.push($(this).val());
				});

				// Update label
				self.updateMultiSelectLabel($dropdown);

				// Close dropdown
				$dropdown.removeClass('open');

				// Apply filter (comma-separated values)
				self.updateFilterWithDependencies(filterName, selectedValues.join(','));
			});

			// Checkbox change - update label in real-time but don't apply filter
			$(document).on('change', '.multiselect-option input[type="checkbox"]', function(e) {
				e.stopPropagation();
				const $dropdown = $(this).closest('.multiselect-dropdown');
				self.updateMultiSelectLabel($dropdown);
			});

			// Prevent menu clicks from closing dropdown
			$(document).on('click', '.multiselect-menu', function(e) {
				e.stopPropagation();
			});
		}

		/**
		 * Update multi-select dropdown label based on selections
		 */
		updateMultiSelectLabel($dropdown) {
			const $label = $dropdown.find('.multiselect-label');
			const filterName = $dropdown.data('filter');
			const $checked = $dropdown.find('input[type="checkbox"]:checked');
			const count = $checked.length;

			if (count === 0) {
				let defaultLabel;
				if (filterName === 'country') {
					defaultLabel = optiHeatmapDetail.i18n.all_countries || 'All Countries';
				} else if (filterName === 'os') {
					defaultLabel = optiHeatmapDetail.i18n.all_os || 'All Operating Systems';
				} else if (filterName === 'utm_campaign') {
					defaultLabel = optiHeatmapDetail.i18n.all_campaigns || 'All Campaigns';
				} else if (filterName === 'utm_source') {
					defaultLabel = optiHeatmapDetail.i18n.all_sources || 'All Sources';
				} else if (filterName === 'utm_medium') {
					defaultLabel = optiHeatmapDetail.i18n.all_mediums || 'All Mediums';
				} else {
					defaultLabel = optiHeatmapDetail.i18n.all_browsers || 'All Browsers';
				}
				$label.text(defaultLabel);
				$dropdown.removeClass('has-selection');
			} else if (count === 1) {
				const text = $checked.first().closest('.multiselect-option').find('.option-label').text();
				$label.text(text);
				$dropdown.addClass('has-selection');
			} else {
				$label.text(count + ' ' + (optiHeatmapDetail.i18n.selected || 'selected'));
				$dropdown.addClass('has-selection');
			}
		}

		/**
		 * Get selected values from a multi-select dropdown
		 */
		getMultiSelectValues(filterName) {
			const $dropdown = $(`.multiselect-dropdown[data-filter="${filterName}"]`);
			const selectedValues = [];
			$dropdown.find('input[type="checkbox"]:checked').each(function() {
				selectedValues.push($(this).val());
			});
			return selectedValues.join(',');
		}

		/**
		 * Update a filter value and reload data
		 */
		updateFilter(name, value) {
			if (name === 'date_range' && value === 'custom' && (!this.customStartDate || !this.customEndDate)) {
				value = 'all';
			}
			this.filters[name] = value;
			if (name === 'date_range' && value !== 'custom') {
				this.customStartDate = '';
				this.customEndDate = '';
			}

			// Update visual state of select
			const $select = $(`.filter-select[data-filter="${name}"]`);
			if (value && value !== '' && (name !== 'date_range' || value !== 'all')) {
				$select.addClass('has-value');
			} else {
				$select.removeClass('has-value');
			}

			this.updateAdvancedFiltersBadge();

			// Reload heatmap and stats with new filters
			this.loadHeatmap();
			this.loadStats();
		}

		/**
		 * Update a filter value, reload data, and update dependent filter options
		 * This is called when Apply button is clicked on multi-select dropdowns
		 */
		updateFilterWithDependencies(name, value) {
			this.filters[name] = value;

			// Update visual state of dropdown
			const $dropdown = $(`.multiselect-dropdown[data-filter="${name}"]`);
			if (value && value !== '') {
				$dropdown.addClass('has-selection');
			} else {
				$dropdown.removeClass('has-selection');
			}

			this.updateAdvancedFiltersBadge();

			// Load dependent filter options (updates other dropdowns based on current selection)
			this.loadDependentFilterOptions();

			// Reload heatmap and stats with new filters
			this.loadHeatmap();
			this.loadStats();
		}

		/**
		 * Load dependent filter options based on current filter selections
		 * When a user selects a country, this updates the browser dropdown to show only
		 * browsers that have data for that country (and vice versa)
		 */
		async loadDependentFilterOptions() {
			this.debug('debug', 'Loading dependent filter options...');

			try {
				const response = await $.ajax({
					url: this.config.ajax_url,
					type: 'POST',
					data: this.addDatePayload({
						// The standalone dependent-options endpoint was folded into
						// ajax_get_filter_options during the 2026-08 heatmap refactor:
						// the same handler now narrows the opposite dimension via the
						// interdependent country_filter/browser_filter params (a
						// selected country narrows the browser list and vice versa).
						action: 'opti_behavior_get_filter_options',
						nonce: this.config.nonce,
						page_id: this.pageId,
						page_ids: this.pageIdsParam,
						device: this.currentDevice,
						type: this.currentType,
						date_range: this.filters.date_range,
						// Current selections drive the interdependent narrowing.
						country_filter: this.filters.country,
						browser_filter: this.filters.browser,
						visitor_type: this.filters.visitor_type,
						ab_test_id: this.abTestId || '',
						variant_id: this.variantId || ''
					})
				});

				if (response.success && response.data) {
					this.updateDependentDropdowns(response.data);
				} else {
					this.debug('warning', 'No dependent filter options available');
				}
			} catch (error) {
				this.debug('error', 'Failed to load dependent filter options:', error);
			}
		}

		/**
		 * Update dropdown options based on dependent filter data
		 * Preserves current selections if they are still valid
		 */
		updateDependentDropdowns(options) {
			this.debug('debug', 'Updating dependent dropdowns:', options);

			// Get currently selected values
			const currentCountries = this.filters.country ? this.filters.country.split(',') : [];
			const currentBrowsers = this.filters.browser ? this.filters.browser.split(',') : [];

			const dependentCounts = options.counts || {};

			// Update country dropdown (only if browser filter is active or we have data)
			if (options.countries) {
				this.updateCountryDropdown(options.countries, currentCountries, dependentCounts.countries);
			}

			// Update browser dropdown (only if country filter is active or we have data)
			if (options.browsers) {
				this.updateBrowserDropdown(options.browsers, currentBrowsers, dependentCounts.browsers);
			}

			// Update device counts
			if (options.device_counts) {
				this.updateDeviceCounts(options.device_counts);
			}

			this.debug('debug', 'Dependent dropdowns updated successfully');
		}

		/**
		 * Update country dropdown with available options
		 * Preserves valid selections and clears invalid ones
		 */
		updateCountryDropdown(countries, currentSelections, countsByValue) {
			const $countryOptions = $('#filter-country-dropdown .multiselect-options');
			const $dropdown = $('#filter-country-dropdown');

			// Remember scroll position
			const scrollTop = $countryOptions.scrollTop();

			$countryOptions.empty();

			const availableCountryCodes = Object.keys(countries);

			if (availableCountryCodes.length > 0) {
				// countries is an object: { code: name, ... }
				Object.entries(countries).forEach(([code, name]) => {
					const flag = this.getCountryFlag(code);
					const isChecked = currentSelections.includes(code) ? 'checked' : '';
					const $option = $(`
						<label class="multiselect-option" data-value="${this.escapeHtml(code)}">
							<input type="checkbox" value="${this.escapeHtml(code)}" ${isChecked} />
							${flag}
							<span class="option-label">${this.escapeHtml(name)}</span>
							${this.optionCountHtml(countsByValue, code)}
						</label>
					`);
					$countryOptions.append($option);
				});

				// Update internal filter state to remove any invalid selections
				const validSelections = currentSelections.filter(code => availableCountryCodes.includes(code));
				this.filters.country = validSelections.join(',');
			} else {
				$countryOptions.append('<div class="multiselect-empty">' + (optiHeatmapDetail.i18n.no_countries_available || 'No countries available') + '</div>');
				this.filters.country = '';
			}

			// Update label
			this.updateMultiSelectLabel($dropdown);

			// Restore scroll position
			$countryOptions.scrollTop(scrollTop);

			// Render newly injected browser icons
			if (typeof lucide !== 'undefined' && lucide.createIcons) {
				lucide.createIcons();
			}
		}

		/**
		 * Update browser dropdown with available options
		 * Preserves valid selections and clears invalid ones
		 */
		updateBrowserDropdown(browsers, currentSelections, countsByValue) {
			const $browserOptions = $('#filter-browser-dropdown .multiselect-options');
			const $dropdown = $('#filter-browser-dropdown');

			// Remember scroll position
			const scrollTop = $browserOptions.scrollTop();

			$browserOptions.empty();

			if (browsers && browsers.length > 0) {
				browsers.forEach(browser => {
					const icon = this.getBrowserIcon(browser);
					const isChecked = currentSelections.includes(browser) ? 'checked' : '';
					const $option = $(`
						<label class="multiselect-option" data-value="${this.escapeHtml(browser)}">
							<input type="checkbox" value="${this.escapeHtml(browser)}" ${isChecked} />
							<i data-lucide="${this.escapeHtml(icon)}" class="browser-icon"></i>
							<span class="option-label">${this.escapeHtml(browser)}</span>
							${this.optionCountHtml(countsByValue, browser)}
						</label>
					`);
					$browserOptions.append($option);
				});

				// Update internal filter state to remove any invalid selections
				const validSelections = currentSelections.filter(b => browsers.includes(b));
				this.filters.browser = validSelections.join(',');
			} else {
				$browserOptions.append('<div class="multiselect-empty">' + (optiHeatmapDetail.i18n.no_browsers_available || 'No browsers available') + '</div>');
				this.filters.browser = '';
			}

			// Update label
			this.updateMultiSelectLabel($dropdown);

			// Restore scroll position
			$browserOptions.scrollTop(scrollTop);

			// Render newly injected browser icons
			if (typeof lucide !== 'undefined' && lucide.createIcons) {
				lucide.createIcons();
			}
		}

		/**
		 * Reset all filters to default values
		 */
		resetFilters() {
			this.filters = {
				date_range: 'all',
				country: '',
				browser: '',
				visitor_type: 'guest',  // Default to Guest (logged-in users can see different page content)
				os: '',
				utm_campaign: '',
				utm_source: '',
				utm_medium: '',
				duration_min: '',
				duration_max: '',
				entry_page: '',
				exit_page: '',
				referrer: '',
				traffic_channel: ''
			};
			this.customStartDate = '';
			this.customEndDate = '';

			// Reset toolbar date inputs to the full recorded range; clear advanced panel fields
			this.prefillDateInputs('all');
			$('#filter-duration-min, #filter-duration-max, #filter-entry-page, #filter-exit-page, #filter-referrer').val('');
			$('#filter-traffic-channel').val('');
			this.updateAdvancedFiltersBadge();

			// Reset A/B variant filter
			this.abTestId = 0;
			this.variantId = 0;
			$('#filter-ab-variant').val('').removeClass('has-value');

			// Remove variant params from URL
			const url = new URL(window.location.href);
			url.searchParams.delete('ab_test_id');
			url.searchParams.delete('variant_id');
			window.history.pushState({}, '', url.toString());

			// Reset single-select elements
			$('#filter-date-range').val('all').removeClass('has-value');
			$('#filter-visitor-type').val('guest').removeClass('has-value');

			// Reset multi-select dropdowns
			$('.multiselect-dropdown').each((index, dropdown) => {
				const $dropdown = $(dropdown);
				$dropdown.find('input[type="checkbox"]').prop('checked', false);
				$dropdown.removeClass('has-selection');
				this.updateMultiSelectLabel($dropdown);
			});

			// Reload full filter options (shows all available countries/browsers)
			this.loadFilterOptions();

			// Reload header total and device counts
			this.loadTotalRecordingsCount();
			this.loadDeviceCounts();

			// Reload data
			this.loadHeatmap();
			this.loadStats();
		}

		/**
		 * Load device counts
		 */
		async loadDeviceCounts() {
			try {
				const response = await $.ajax({
					url: this.config.ajax_url,
					type: 'POST',
					data: this.addDatePayload({
						action: 'opti_behavior_get_device_counts',
						nonce: this.config.nonce,
						page_id: this.pageId,
						page_ids: this.pageIdsParam,
						type: this.currentType,  // Send current heatmap type filter
						date_range: this.filters.date_range,
						country: this.filters.country,
						browser: this.filters.browser,
						visitor_type: this.filters.visitor_type,
						ab_test_id: this.abTestId || '',
						variant_id: this.variantId || ''
					})
				});

				if (response.success && response.data) {
					this.updateDeviceCounts(response.data);
				}
			} catch (error) {
				this.debug('error', 'Failed to load device counts:', error);
			}
		}

		/**
		 * Update device count badges
		 */
		updateDeviceCounts(counts) {
			$('.device-count[data-device="desktop"]').text(counts.desktop || 0);
			$('.device-count[data-device="mobile"]').text(counts.mobile || 0);
			$('.device-count[data-device="tablet"]').text(counts.tablet || 0);
			// Note: Header total recordings count is set by loadTotalRecordingsCount() (scoped to current view + filters)
		}

		/**
		 * Load the total sessions count for the header display.
		 * All-devices, all-types total for the page (same active date/visitor
		 * filters as the badges) so the header matches the Heatmaps list total.
		 */
		async loadTotalRecordingsCount() {
			try {
				const response = await $.ajax({
					url: this.config.ajax_url,
					type: 'POST',
					data: this.addDatePayload({
						action: 'opti_behavior_get_total_recordings',
						nonce: this.config.nonce,
						page_id: this.pageId,
						page_ids: this.pageIdsParam,
						date_range: this.filters.date_range,
						country: this.filters.country,
						browser: this.filters.browser,
						visitor_type: this.filters.visitor_type,
						ab_test_id: this.abTestId || '',
						variant_id: this.variantId || ''
						// Intentionally NO device/type here: the header total is the
						// ALL-DEVICES, all-types session count for the page, matching
						// the Heatmaps list total. Per-device numbers live on the
						// device badges.
					})
				});

				if (response.success && response.data) {
					$(".recordings-count .count-num").text(response.data.total || 0);

					// "Delete Heatmap Data" dual display: "(45 / 108 sessions)" —
					// all-time suffix + explaining tooltip, only when the server
					// says the page has a reset floor and the counts differ.
					var resetAllTime = response.data.sessions_all_time;
					var $resetSuffix = $('.recordings-count .recordings-reset-alltime');
					if (resetAllTime !== null && typeof resetAllTime !== 'undefined') {
						$resetSuffix.find('.all-time-num').text(resetAllTime);
						$resetSuffix.show();
						if (response.data.reset_scope_tooltip) {
							$('.recordings-count').attr('title', response.data.reset_scope_tooltip);
						}
					} else {
						$resetSuffix.hide();
						$('.recordings-count').removeAttr('title');
					}
				}
			} catch (error) {
				this.debug('error', 'Failed to load total recordings count:', error);
			}
		}

	/**
	 * Load filter options from filenames (FAST - Thread 1)
	 * This scans filenames only, not file contents, so it completes quickly
	 * Allows users to see and interact with filters while heatmap data loads
	 */
		async loadFilterOptions() {
			this.debug('debug', 'Loading filter options from filenames...');

			// Show loading state in dropdowns
			this.showFilterLoading();

			try {
				const response = await $.ajax({
					url: this.config.ajax_url,
					type: 'POST',
					data: this.addDatePayload({
						action: 'opti_behavior_get_filter_options',
						nonce: this.config.nonce,
						page_id: this.pageId,
						page_ids: this.pageIdsParam,
						device: this.currentDevice,  // Filter by current device
						type: this.currentType,
						date_range: this.filters.date_range,
						// Scope option counts to the active A/B variant so they
						// never exceed the variant session total shown in the hero.
						ab_test_id: this.abTestId || '',
						variant_id: this.variantId || '',
						// Match the hero session scope: counts honor the current
						// visitor-type selection.
						visitor_type: this.filters.visitor_type || ''
					})
				});

				if (response.success && response.data) {
					this.populateFilterDropdowns(response.data);
				} else {
					this.debug('warning', 'No filter options available');
					this.hideFilterLoading();
				}
			} catch (error) {
				this.debug('error', 'Failed to load filter options:', error);
				this.hideFilterLoading();
			}
		}

		/**
		 * Show loading state in filter dropdowns
		 */
		showFilterLoading() {
			// Add loading class to multiselect dropdowns
			$('.multiselect-dropdown').addClass('loading');

			// Update labels to show loading
			$('#filter-country-dropdown .multiselect-label').text(optiHeatmapDetail.i18n.loading_countries || 'Loading countries...');
			$('#filter-browser-dropdown .multiselect-label').text(optiHeatmapDetail.i18n.loading_browsers || 'Loading browsers...');
		}

		/**
		 * Hide loading state in filter dropdowns
		 */
		hideFilterLoading() {
			$('.multiselect-dropdown').removeClass('loading');

			// Reset labels
			$('#filter-country-dropdown .multiselect-label').text(optiHeatmapDetail.i18n.all_countries || 'All Countries');
			$('#filter-browser-dropdown .multiselect-label').text(optiHeatmapDetail.i18n.all_browsers || 'All Browsers');
			$('#filter-os-dropdown .multiselect-label').text(optiHeatmapDetail.i18n.all_os || 'All Operating Systems');
		}

		/**
		 * Populate filter dropdowns with options from filenames
		 */
		populateFilterDropdowns(options) {
			this.debug('debug', 'Populating filter dropdowns:', options);

			// Per-option unique-session counts keyed by dimension then value
			// (e.g. counts.browsers.Chrome = 12), like the dashboard filters.
			const counts = options.counts || {};

			// Populate country dropdown
			const $countryOptions = $('#filter-country-dropdown .multiselect-options');
			$countryOptions.empty();

			if (options.countries && Object.keys(options.countries).length > 0) {
				// countries is an object: { code: name, ... }
				Object.entries(options.countries).forEach(([code, name]) => {
					const flag = this.getCountryFlag(code);
					const $option = $(`
						<label class="multiselect-option" data-value="${this.escapeHtml(code)}">
							<input type="checkbox" value="${this.escapeHtml(code)}" />
							${flag}
							<span class="option-label">${this.escapeHtml(name)}</span>
							${this.optionCountHtml(counts.countries, code)}
						</label>
					`);
					$countryOptions.append($option);
				});
			} else {
				$countryOptions.append('<div class="multiselect-empty">' + (optiHeatmapDetail.i18n.no_countries_found || 'No countries found') + '</div>');
			}

			// Populate browser dropdown
			const $browserOptions = $('#filter-browser-dropdown .multiselect-options');
			$browserOptions.empty();

			if (options.browsers && options.browsers.length > 0) {
				options.browsers.forEach(browser => {
					const icon = this.getBrowserIcon(browser);
					const $option = $(`
						<label class="multiselect-option" data-value="${this.escapeHtml(browser)}">
							<input type="checkbox" value="${this.escapeHtml(browser)}" />
							<i data-lucide="${this.escapeHtml(icon)}" class="browser-icon"></i>
							<span class="option-label">${this.escapeHtml(browser)}</span>
							${this.optionCountHtml(counts.browsers, browser)}
						</label>
					`);
					$browserOptions.append($option);
				});
			} else {
				$browserOptions.append('<div class="multiselect-empty">' + (optiHeatmapDetail.i18n.no_browsers_found || 'No browsers found') + '</div>');
			}

			// Populate OS dropdown (advanced panel)
			const currentOsSelections = this.filters.os ? this.filters.os.split(',') : [];
			const $osOptions = $('#filter-os-dropdown .multiselect-options');
			$osOptions.empty();

			if (options.os && options.os.length > 0) {
				options.os.forEach(osName => {
					const icon = this.getOSIcon(osName);
					const isChecked = currentOsSelections.includes(osName) ? 'checked' : '';
					const $option = $(`
						<label class="multiselect-option" data-value="${this.escapeHtml(osName)}">
							<input type="checkbox" value="${this.escapeHtml(osName)}" ${isChecked} />
							<i data-lucide="${this.escapeHtml(icon)}" class="browser-icon"></i>
							<span class="option-label">${this.escapeHtml(osName)}</span>
							${this.optionCountHtml(counts.os, osName)}
						</label>
					`);
					$osOptions.append($option);
				});
			} else {
				$osOptions.append('<div class="multiselect-empty">' + (optiHeatmapDetail.i18n.no_os_found || 'No OS data available') + '</div>');
			}
			// Populate UTM multiselect dropdowns (advanced panel)
			this.populateUtmMultiselect('#filter-utm-campaign-dropdown', options.utm_campaigns, this.filters.utm_campaign, counts.utm_campaigns, optiHeatmapDetail.i18n.no_campaigns_found || 'No campaign data available');
			this.populateUtmMultiselect('#filter-utm-source-dropdown', options.utm_sources, this.filters.utm_source, counts.utm_sources, optiHeatmapDetail.i18n.no_sources_found || 'No source data available');
			this.populateUtmMultiselect('#filter-utm-medium-dropdown', options.utm_mediums, this.filters.utm_medium, counts.utm_mediums, optiHeatmapDetail.i18n.no_mediums_found || 'No medium data available');

			// Annotate static selects with per-option session counts.
			this.annotateSelectCounts('#filter-visitor-type', counts.visitor_types || {});
			this.annotateSelectCounts('#filter-traffic-channel', this.valueCountListToMap(options.traffic_channels));

			// Store Pages & Traffic suggestions ({value,count} lists) and wire the
			// suggestion dropdowns for the entry/exit/referrer text inputs.
			this.filterSuggestions = {
				entry_page: options.entry_pages || [],
				exit_page: options.exit_pages || [],
				referrer: options.referrers || []
			};
			this.setupSuggestInputs();

			// Remove loading state and reset labels
			this.hideFilterLoading();

			// Restore OS/UTM labels after loading-state reset (selections are preserved)
			this.updateMultiSelectLabel($('#filter-os-dropdown'));
			this.updateMultiSelectLabel($('#filter-utm-campaign-dropdown'));
			this.updateMultiSelectLabel($('#filter-utm-source-dropdown'));
			this.updateMultiSelectLabel($('#filter-utm-medium-dropdown'));

			// Render newly injected browser icons
			if (typeof lucide !== 'undefined' && lucide.createIcons) {
				lucide.createIcons();
			}

			this.debug('debug', 'Filter dropdowns populated successfully');
		}

		/**
		 * Populate a UTM multiselect dropdown with values, preserving current
		 * selections (CSV). Option rows carry the unique-session count "(n)".
		 */
		populateUtmMultiselect(dropdownSelector, values, currentCsv, countsByValue, emptyMessage) {
			const $dropdown = $(dropdownSelector);
			if (!$dropdown.length) {
				return;
			}
			const currentSelections = currentCsv ? currentCsv.split(',') : [];
			const $optionsContainer = $dropdown.find('.multiselect-options');
			$optionsContainer.empty();

			if (values && values.length > 0) {
				values.forEach(value => {
					const isChecked = currentSelections.includes(value) ? 'checked' : '';
					const $option = $(`
						<label class="multiselect-option" data-value="${this.escapeHtml(value)}">
							<input type="checkbox" value="${this.escapeHtml(value)}" ${isChecked} />
							<i data-lucide="tag" class="browser-icon"></i>
							<span class="option-label">${this.escapeHtml(value)}</span>
							${this.optionCountHtml(countsByValue, value)}
						</label>
					`);
					$optionsContainer.append($option);
				});
			} else {
				$optionsContainer.append('<div class="multiselect-empty">' + emptyMessage + '</div>');
			}
		}

		/**
		 * Append a session count to an option label ("Chrome (1732)").
		 */
		withCount(label, count) {
			return (count === null || typeof count === 'undefined') ? label : label + ' (' + count + ')';
		}

		/**
		 * Look up a per-value count from a counts map (null when unknown).
		 */
		lookupCount(countsByValue, value) {
			if (!countsByValue || !Object.prototype.hasOwnProperty.call(countsByValue, value)) {
				return null;
			}
			return countsByValue[value];
		}

		/**
		 * Right-aligned count badge markup for multiselect option rows.
		 */
		optionCountHtml(countsByValue, value) {
			const count = this.lookupCount(countsByValue, value);
			if (count === null) {
				return '';
			}
			return '<span class="option-count">(' + count + ')</span>';
		}

		/**
		 * Convert a [{value, count}] list into a { value: count } map.
		 */
		valueCountListToMap(list) {
			const map = {};
			(list || []).forEach(item => {
				if (item && typeof item.value !== 'undefined') {
					map[item.value] = item.count;
				}
			});
			return map;
		}

		/**
		 * Annotate a static select's options with "(n)" session counts.
		 * The original label is cached on the option so repeated refreshes
		 * never stack counts ("Guest (3) (5)"). Options without data show (0);
		 * the placeholder option (empty value) stays plain.
		 */
		annotateSelectCounts(selector, countsByValue) {
			const $select = $(selector);
			if (!$select.length) {
				return;
			}
			$select.find('option').each(function () {
				const opt = this;
				if (!opt.value) {
					return; // Keep "All ..." plain.
				}
				if (!opt.dataset.obBaseLabel) {
					opt.dataset.obBaseLabel = opt.textContent;
				}
				const count = Object.prototype.hasOwnProperty.call(countsByValue || {}, opt.value) ? countsByValue[opt.value] : 0;
				opt.textContent = opt.dataset.obBaseLabel + ' (' + count + ')';
			});
		}

		/**
		 * Display form for a suggestion value: full URLs collapse to path+query
		 * (like the dashboard entry/exit suggestions), other values unchanged.
		 */
		suggestDisplayValue(raw) {
			const value = String(raw === null || typeof raw === 'undefined' ? '' : raw);
			if (/^https?:\/\//i.test(value)) {
				try {
					const url = new URL(value);
					return (url.pathname || '/') + (url.search || '');
				} catch (e) {
					return value;
				}
			}
			return value;
		}

		/**
		 * Wire suggestion dropdowns (with session counts) for the Pages &
		 * Traffic text inputs. Idempotent: the DOM wrap + handlers are created
		 * once; each render reads the latest this.filterSuggestions data.
		 */
		setupSuggestInputs() {
			this.setupSuggestInput('#filter-entry-page', 'entry_page');
			this.setupSuggestInput('#filter-exit-page', 'exit_page');
			this.setupSuggestInput('#filter-referrer', 'referrer');
		}

		setupSuggestInput(selector, suggestKey) {
			const $input = $(selector);
			if (!$input.length || $input.data('optiSuggestReady')) {
				return;
			}
			$input.data('optiSuggestReady', true);
			$input.attr('autocomplete', 'off');

			$input.wrap('<div class="opti-suggest"></div>');
			const $wrap = $input.parent();
			const $menu = $('<div class="opti-suggest-menu" style="display:none"></div>').appendTo($wrap);
			const self = this;

			const render = function () {
				const items = (self.filterSuggestions && self.filterSuggestions[suggestKey]) || [];
				const query = String($input.val() || '').toLowerCase();
				const matches = items.filter(function (item) {
					return !query || String(item.value).toLowerCase().indexOf(query) !== -1;
				}).slice(0, 15);

				$menu.empty();
				if (!matches.length) {
					$menu.hide();
					return;
				}
				matches.forEach(function (item) {
					$('<div class="opti-suggest-item"></div>')
						.attr('data-value', item.value)
						.attr('title', item.value)
						.append($('<span class="opti-suggest-label"></span>').text(self.suggestDisplayValue(item.value)))
						.append($('<span class="option-count"></span>').text('(' + item.count + ')'))
						.appendTo($menu);
				});
				$menu.show();
			};

			$input.on('focus input', render);
			$input.on('blur', function () {
				// Delay so a mousedown pick on the menu lands first.
				setTimeout(function () {
					$menu.hide();
				}, 120);
			});
			$menu.on('mousedown', '.opti-suggest-item', function (event) {
				event.preventDefault();
				$input.val($(this).attr('data-value')).trigger('change');
				$menu.hide();
			});
		}

		/**
		 * Get a lucide icon name for an operating system.
		 */
		getOSIcon(os) {
			const key = String(os || '').toLowerCase();
			if (key.includes('android') || key.includes('ios') || key.includes('iphone')) {
				return 'smartphone';
			}
			if (key.includes('mac') || key.includes('linux') || key.includes('windows') || key.includes('chrome')) {
				return 'monitor';
			}
			return 'cpu';
		}

		/**
		 * Load heatmap data and render (with progressive batched loading)
		 */
		async loadHeatmap() {
			if (this.isLoading) {
				this.cancelActiveLoad('new heatmap request');
			}

			const loadId = this.beginPendingState('heatmap', this.config.i18n.loading || 'Loading heatmap data...');
			this.isLoading = true;
			this.stopLoadingRequested = false; // Reset stop flag for new load
			this.showLoading(this.config.i18n.loading || 'Loading heatmap data...', loadId);
			this.allCoordinates = []; // Reset accumulated coordinates
			this.allTrajectories = []; // Reset accumulated trajectories (for move heatmaps)
			this.allDwellSpots = []; // Reset accumulated dwell-time spots (for move heatmaps)
			this.currentBatch = 1;
			this.batchSize = 100; // Batch size for progressive loading

			// Reset render state flags for new load
			this.heatmapAlreadyRendered = false;
			this.iframeRenderPending = false;
			this.pendingHeatmapData = null;
			this.firstBatchRendered = false; // Track if we've rendered the first time
			this.lastRenderedIndex = 0; // Reset incremental render index

			// Destroy old heatmap instance to prevent memory leaks
			if (this.heatmapInstance) {
				this.heatmapInstance = null;
			}

			// Remove old heatmap overlay to ensure fresh render
			const oldOverlay = document.getElementById('heatmap-overlay');
			if (oldOverlay) {
				oldOverlay.remove();
			}

			try {
				// Load first batch
				await this.loadHeatmapBatch(1, loadId);
			} catch (error) {
				if (this.isStaleLoad(loadId)) {
					return;
				}
				this.debug('error', 'Failed to load heatmap:', error);
				this.showError(this.config.i18n.error, loadId);
				this.isLoading = false;
			}
		}

		/**
		 * Load a single batch of heatmap data
		 * Uses small batches (20 files) and async delays to keep UI responsive
		 * Handles tab visibility changes to prevent crashes when switching tabs
		 */
		async loadHeatmapBatch(batchNumber, loadId = this.activeLoadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}

			this.debug('debug', `Loading heatmap batch ${batchNumber}...`);

			// Check if tab is hidden - if so, pause loading and resume when visible
			if (!this.isTabVisible) {
				this.debug('debug', `Tab is hidden, pausing at batch ${batchNumber}`);
				this.isPausedDueToVisibility = true;
				this.pendingBatchNumber = batchNumber;
				this.pendingBatchLoadId = loadId;

				// Update UI to show paused state
				this.updatePendingState(this.config.i18n.loading_paused_hidden || 'Loading paused (tab hidden)...', loadId);
				if (this.firstBatchRendered) {
					this.showBatchProgress(
						batchNumber - 1,
						this.totalBatches || 10,
						this.allCoordinates ? this.allCoordinates.length : 0,
						'Paused (tab hidden)'
					);
				}
				return; // Will resume from visibility change handler
			}

			// Reset pause state
			this.isPausedDueToVisibility = false;
			this.pendingBatchNumber = null;
			this.pendingBatchLoadId = null;

			// Update loading message with progress
			this.updatePendingState((this.config.i18n.loading_batch || 'Loading heatmap data... (batch %s)').replace('%s', batchNumber), loadId);

			let request = null;
			try {
				request = $.ajax({
					url: this.config.ajax_url,
					type: 'POST',
					timeout: 60000, // 60 second timeout to handle slow connections
					data: this.addDatePayload({
						action: 'opti_behavior_get_heatmap_batch',
						nonce: this.config.nonce,
						page_id: this.pageId,
						page_ids: this.pageIdsParam,
						device: this.currentDevice,
						type: this.currentType,
						date_range: this.filters.date_range,
						country: this.filters.country,
						browser: this.filters.browser,
						visitor_type: this.filters.visitor_type,
						ab_test_id: this.abTestId,
						variant_id: this.variantId,
						batch: batchNumber,
						batch_size: this.batchSize
					})
				});
				this.trackXhr(loadId, request);
				const response = await request;
				this.untrackXhr(loadId, request);

				if (this.isStaleLoad(loadId)) {
					return;
				}

				// Reset retry count on success
				this.batchRetryCount = 0;

				if (response.success && response.data) {
					const data = response.data;

					// Accumulate coordinates from this batch
					if (data.coordinates && data.coordinates.length > 0) {
						this.allCoordinates.push(...data.coordinates);
							this.debug('debug', `Batch ${batchNumber}: Added ${data.coordinates.length} coordinates (total: ${this.allCoordinates.length})`);
					}

					// Accumulate trajectories from this batch (for move heatmaps)
					if (data.trajectories && data.trajectories.length > 0) {
						if (!this.allTrajectories) {
							this.allTrajectories = [];
						}
						this.allTrajectories.push(...data.trajectories);
							this.debug('debug', `Batch ${batchNumber}: Added ${data.trajectories.length} trajectories (total: ${this.allTrajectories.length})`);
					}

					// Accumulate dwell-time spots from this batch (for move heatmaps)
					if (data.dwell_spots && data.dwell_spots.length > 0) {
						if (!this.allDwellSpots) {
							this.allDwellSpots = [];
						}
						this.allDwellSpots.push(...data.dwell_spots);
						this.debug('debug', `Batch ${batchNumber}: Added ${data.dwell_spots.length} dwell spots (total: ${this.allDwellSpots.length})`);
					}

					// Store viewport/reference info from first batch
					if (batchNumber === 1) {
						this.heatmapViewport = data.viewport;
						this.heatmapReferenceWidth = data.reference_width;
						this.heatmapReferenceHeight = data.reference_height;
						// Scroll-reach marker: when 'reach', coordinates carry cumulative
						// % of sessions reaching each depth (0-100) instead of raw counts.
						this.heatmapScrollMetric = data.scroll_metric || null;
						// Denominator behind the reach % — used by the scroll-depth
						// hover indicator to show "<N> views (<P>%) reached ...".
						this._scrollDepthTotalSessions = data.total_sessions || 0;
					}

					// Render after first batch, but keep pending overlay visible until all data/render phases complete.
					if (!this.firstBatchRendered && this.allCoordinates.length > 0) {
						this.firstBatchRendered = true;
							this.debug('debug', `First batch complete - rendering heatmap immediately with ${this.allCoordinates.length} points`);

						// Show container beneath the pending overlay.
						$('#heatmap-container').show();

						// Render the heatmap with current data
						this.renderHeatmapWithData({
							coordinates: this.allCoordinates,
							trajectories: this.allTrajectories || [],
							viewport: this.heatmapViewport,
							reference_width: this.heatmapReferenceWidth,
							reference_height: this.heatmapReferenceHeight,
							recordings_processed: data.recordings_processed,
							loading_more: data.has_more,
							current_batch: batchNumber,
							total_batches: data.total_batches
						}, loadId);

						// Show progress indicator
						if (data.has_more) {
							this.showBatchProgress(batchNumber, data.total_batches, this.allCoordinates.length);
						}
					}

					// Check if there are more batches to load
					if (data.has_more && batchNumber < data.total_batches) {
						// Store total batches for visibility handler
						this.totalBatches = data.total_batches;

						// Check if user requested to stop loading
						if (this.stopLoadingRequested) {
									this.debug('debug', 'Loading stopped by user after batch ' + batchNumber);
							this.isLoading = false;
							this.markDataComplete(loadId);
							this.finishPendingState(loadId, 'stopped');
							return; // Exit without loading more batches
						}

						// Update progress overlay if heatmap already rendered
						if (this.firstBatchRendered) {
							this.showBatchProgress(batchNumber, data.total_batches, this.allCoordinates.length);

							// Incrementally update heatmap (non-blocking)
							if (this.heatmapAlreadyRendered) {
								if (this.currentType === 'move' && this.trajectoryCanvas) {
									// For move heatmaps, re-render trajectory canvas with all accumulated data
									this.updateTrajectoryCanvas();
								} else if (this.heatmapInstance) {
									// For click/scroll heatmaps, add points incrementally
									this.updateHeatmapOverlay(loadId);
								}
							}
						}

						// CRITICAL: Yield control to browser with setTimeout to prevent page freeze
						// This allows the UI to update and prevents "page not responding"
						await new Promise(resolve => setTimeout(resolve, 50));

						if (this.isStaleLoad(loadId)) {
							return;
						}

						// Check again after yield in case stop was requested during yield
						if (this.stopLoadingRequested) {
									this.debug('debug', 'Loading stopped by user during yield after batch ' + batchNumber);
							this.isLoading = false;
							this.markDataComplete(loadId);
							this.finishPendingState(loadId, 'stopped');
							return;
						}

						// Check if tab became hidden during yield - pause instead of continuing
						if (!this.isTabVisible) {
							this.debug('debug', `Tab hidden during batch ${batchNumber}, pausing for next batch`);
							this.isPausedDueToVisibility = true;
							this.pendingBatchNumber = batchNumber + 1;
							this.pendingBatchLoadId = loadId;
							return; // Will resume from visibility change handler
						}

						// Load next batch
						await this.loadHeatmapBatch(batchNumber + 1, loadId);
					} else {
						// All batches loaded - DO NOT re-render everything (causes freeze)
							this.debug('debug', `All batches loaded! Total coordinates: ${this.allCoordinates.length}`);
						this.isLoading = false;
						$('#heatmap-container').show();

						// Hide the batch progress indicator
						this.hideBatchProgress();

						if (this.allCoordinates.length > 0) {
							// Only do a lightweight update of the heatmap overlay
							// Skip full re-render to avoid freezing with 15000+ points
							if (this.heatmapAlreadyRendered && this.heatmapInstance) {
								// Just update the heatmap data incrementally
									this.debug('debug', 'Updating heatmap overlay with final data (no full re-render)');
								this.updateHeatmapOverlay(loadId, () => {
									this.markDataComplete(loadId);
									this.finishPendingState(loadId);
									this.showBatchComplete(this.allCoordinates.length);
								});
							} else if (!this.firstBatchRendered) {
								this.markDataComplete(loadId);
								// Only render if we never rendered before
								this.renderHeatmapWithData({
									coordinates: this.allCoordinates,
									trajectories: this.allTrajectories || [],
									viewport: this.heatmapViewport,
									reference_width: this.heatmapReferenceWidth,
									reference_height: this.heatmapReferenceHeight,
									recordings_processed: data.total_files || data.recordings_processed,
									loading_more: false
								}, loadId);
							} else {
								this.markDataComplete(loadId);
								if (this.iframeRenderPending && !this.heatmapAlreadyRendered) {
									this.renderHeatmapWithData({
										coordinates: this.allCoordinates,
										trajectories: this.allTrajectories || [],
										viewport: this.heatmapViewport,
										reference_width: this.heatmapReferenceWidth,
										reference_height: this.heatmapReferenceHeight,
										recordings_processed: data.total_files || data.recordings_processed,
										loading_more: false
									}, loadId);
								}
								this.finishPendingState(loadId);
								this.showBatchComplete(this.allCoordinates.length);
							}
						} else {
							this.markDataComplete(loadId);
							this.showNoData(loadId);
						}
					}
				} else {
					// No data or error
					if (batchNumber === 1) {
						// First batch failed - try fallback to non-batched endpoint
						this.debug('debug', 'Batched loading returned no data, trying fallback...');
						await this.loadHeatmapFallback(loadId);
					} else {
						// Subsequent batch failed - render what we have
						this.debug('debug', `Batch ${batchNumber} returned no data, rendering accumulated data`);
						this.isLoading = false;
						if (this.allCoordinates.length > 0 || (this.allTrajectories && this.allTrajectories.length > 0)) {
							this.markDataComplete(loadId);
							this.renderHeatmapWithData({
								coordinates: this.allCoordinates,
								trajectories: this.allTrajectories || [],
								viewport: this.heatmapViewport,
								reference_width: this.heatmapReferenceWidth,
								reference_height: this.heatmapReferenceHeight,
								loading_more: false
							}, loadId);
							this.showBatchComplete(this.allCoordinates.length);
						} else {
							this.markDataComplete(loadId);
							this.showNoData(loadId);
						}
					}
				}
			} catch (error) {
				this.untrackXhr(loadId, request);
				if (this.isStaleLoad(loadId) || (error && error.statusText === 'abort')) {
					return;
				}
				this.debug('error', `Failed to load batch ${batchNumber}:`, error);

				// Check if this is a timeout or network error that might be due to tab switching
				const isTimeoutOrNetworkError = error.statusText === 'timeout' ||
					error.status === 0 ||
					error.statusText === 'error' ||
					(error.message && error.message.includes('timeout'));

				// If tab is hidden and we got a network error, pause and retry when visible
				if (!this.isTabVisible && isTimeoutOrNetworkError) {
					this.debug('debug', `Network error while tab hidden, will retry batch ${batchNumber} when visible`);
					this.isPausedDueToVisibility = true;
					this.pendingBatchNumber = batchNumber;
					this.pendingBatchLoadId = loadId;
					this.batchRetryCount = 0; // Reset retry count for when we resume
					this.updatePendingState(this.config.i18n.loading_paused_retry || 'Loading paused (will retry)...', loadId);

					if (this.firstBatchRendered) {
						this.showBatchProgress(
							batchNumber - 1,
							this.totalBatches || 10,
							this.allCoordinates ? this.allCoordinates.length : 0,
							'Paused (will retry)'
						);
					}
					return; // Will resume from visibility change handler
				}

				// Retry logic for transient errors (timeout, network issues)
				if (isTimeoutOrNetworkError && this.batchRetryCount < this.maxBatchRetries) {
					this.batchRetryCount++;
					this.debug('debug', `Retrying batch ${batchNumber} (attempt ${this.batchRetryCount}/${this.maxBatchRetries})`);

					// Show retry message
					if (this.firstBatchRendered) {
						this.showBatchProgress(
							batchNumber - 1,
							this.totalBatches || 10,
							this.allCoordinates ? this.allCoordinates.length : 0,
							(this.config.i18n.retrying_count || 'Retrying... (%1$s/%2$s)').replace('%1$s', this.batchRetryCount).replace('%2$s', this.maxBatchRetries)
						);
					} else {
						this.updatePendingState((this.config.i18n.retrying_batch || 'Retrying batch %1$s... (attempt %2$s)').replace('%1$s', batchNumber).replace('%2$s', this.batchRetryCount), loadId);
					}

					// Wait a bit before retrying (exponential backoff)
					const retryDelay = Math.min(1000 * Math.pow(2, this.batchRetryCount - 1), 5000);
					await new Promise(resolve => setTimeout(resolve, retryDelay));

					if (this.isStaleLoad(loadId)) {
						return;
					}

					// Check if we should still retry (user might have stopped or tab hidden)
					if (!this.stopLoadingRequested && this.isTabVisible) {
						await this.loadHeatmapBatch(batchNumber, loadId);
					} else if (!this.isTabVisible) {
						// Tab became hidden during retry delay - pause
						this.isPausedDueToVisibility = true;
						this.pendingBatchNumber = batchNumber;
						this.pendingBatchLoadId = loadId;
					}
					return;
				}

				// Reset retry count for next batch
				this.batchRetryCount = 0;

				if (batchNumber === 1) {
					// First batch failed - try fallback
					await this.loadHeatmapFallback(loadId);
				} else {
					// Render what we have
					this.isLoading = false;
					if (this.allCoordinates.length > 0 || (this.allTrajectories && this.allTrajectories.length > 0)) {
						this.markDataComplete(loadId);
						this.renderHeatmapWithData({
							coordinates: this.allCoordinates,
							trajectories: this.allTrajectories || [],
							viewport: this.heatmapViewport,
							reference_width: this.heatmapReferenceWidth,
							reference_height: this.heatmapReferenceHeight,
							loading_more: false
						}, loadId);
						this.showBatchComplete(this.allCoordinates.length);
					} else {
						this.showError(this.config.i18n.error, loadId);
					}
				}
			}
		}

		/**
		 * Fallback to non-batched heatmap loading (for compatibility)
		 */
		async loadHeatmapFallback(loadId = this.activeLoadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}

			this.debug('debug', 'Using fallback non-batched heatmap loading...');
			this.updatePendingState(this.config.i18n.loading_fallback || 'Loading heatmap data with fallback...', loadId);

			let request = null;
			try {
				request = $.ajax({
					url: this.config.ajax_url,
					type: 'POST',
					data: this.addDatePayload({
						action: 'opti_behavior_get_heatmap_data',
						nonce: this.config.nonce,
						page_id: this.pageId,
						page_ids: this.pageIdsParam,
						device: this.currentDevice,
						type: this.currentType,
						date_range: this.filters.date_range,
						country: this.filters.country,
						browser: this.filters.browser,
						visitor_type: this.filters.visitor_type,
						ab_test_id: this.abTestId,
						variant_id: this.variantId
					})
				});
				this.trackXhr(loadId, request);
				const response = await request;
				this.untrackXhr(loadId, request);

				if (this.isStaleLoad(loadId)) {
					return;
				}

				if (response.success && response.data) {
					this.markDataComplete(loadId);
					this.renderHeatmap(response.data, loadId);
				} else {
					this.showError(response.data?.message || this.config.i18n.error, loadId);
				}
			} catch (error) {
				this.untrackXhr(loadId, request);
				if (this.isStaleLoad(loadId) || (error && error.statusText === 'abort')) {
					return;
				}
				this.debug('error', 'Fallback loading also failed:', error);
				this.showError(this.config.i18n.error, loadId);
			} finally {
				if (!this.isStaleLoad(loadId)) {
					this.isLoading = false;
				}
			}
		}

		/**
		 * Render heatmap with provided data (used by batched loading)
		 * For progressive rendering, we need to update the overlay without destroying the iframe
		 */
		renderHeatmapWithData(data, loadId = this.activeLoadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}

			// Check if this is a progressive update (iframe already exists)
			const existingIframe = document.getElementById('heatmap-iframe');

			if (existingIframe && this.heatmapAlreadyRendered) {
				// Progressive update - just update the heatmap overlay, don't recreate iframe
				this.debug('debug', 'Progressive update - updating heatmap overlay only');
				const viewport = data.viewport || { width: 1366, height: 768 };
				this.createOverlayAndRenderHeatmap(data, viewport, loadId);
			} else if (existingIframe && this.iframeRenderPending) {
				// Iframe exists but first render is still pending - store data for when iframe loads
				this.debug('debug', 'Iframe render pending - storing data for later');
				this.pendingHeatmapData = data;
			} else {
				// Initial render - use full renderHeatmap which creates iframe
				this.iframeRenderPending = true; // Mark that we're waiting for iframe
				this.renderHeatmap(data, loadId);
			}
		}

		/**
		 * Render heatmap visualization
		 * Uses measurement iframe to get correct page height BEFORE rendering heatmap
		 * This ensures the canvas is created with correct dimensions from the start
		 */
		renderHeatmap(data, loadId = this.activeLoadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}

			// Interactive preview binds a navigation guard + observers to the
			// CURRENT iframe document. A full render replaces that document
			// (filter change, refresh, variant switch), which would leave the
			// guard/observers bound to the stale doc. Reset the toggle to OFF so
			// the user re-enables it against the fresh preview.
			if (this.interactiveMode) {
				this.setInteractiveMode(false);
				this._syncInteractivePreviewButton();
			}

			const container = document.getElementById('heatmap-container');

			if (!container) {
				this.debug('error', 'Heatmap container not found');
				this.showError(this.config.i18n.error, loadId);
				return;
			}

			this.debug('debug', 'Rendering heatmap with data:', data);

			// Track if we have actual data to render
			const hasData = data.coordinates && data.coordinates.length > 0;
			if (!hasData) {
				this.debug('info', 'No coordinates found - will show page without heatmap overlay');
			}

			// Clear previous heatmap
			$(container).empty();

			// Set container dimensions based on reference dimensions from backend (for normalized coordinates)
			// OR viewport for legacy data
			const viewport = data.viewport || { width: 1366, height: 768 };
			const referenceWidth = data.reference_width || viewport.width;
			const referenceHeight = data.reference_height || viewport.height;

			this.debug('debug', 'Using dimensions:', {
				viewport: viewport,
				reference: { width: referenceWidth, height: referenceHeight },
				hasNormalizedData: !!(data.reference_width && data.reference_height)
			});

			// Get available width from parent container (admin page content area)
			const containerWidth = container.parentElement ? container.parentElement.clientWidth : referenceWidth;

			// Device-matched preview viewport: size the iframe to the selected
			// device's CSS breakpoint width so responsive media queries render THAT
			// device's layout (mobile/tablet stacked) instead of always desktop.
			// Desktop / "all devices" fall back to the reference width unchanged.
			const deviceViewportWidth = this._getDeviceViewportWidth(referenceWidth);

			// Use reference dimensions for container sizing (normalized system)
			// This ensures the iframe/canvas matches the size of the denormalized coordinates
			container.style.position = 'relative';
			container.style.width = referenceWidth + 'px';
			container.style.overflow = 'hidden'; // Clip content to container bounds
			container.style.backgroundColor = '#f5f5f5';
			container.style.border = '1px solid #ddd';

			// No need for inner wrapper anymore
			const innerWrapper = container; // Use container directly

			// Get page URL and modify based on visitor_type filter
			let pageUrl = this.config.page_url;

			// Always mark URL as heatmap iframe preview so the server removes
			// X-Frame-Options headers that would otherwise block iframe loading.
			if (pageUrl) {
				const sep0 = pageUrl.includes('?') ? '&' : '?';
				pageUrl = pageUrl + sep0 + 'opti_heatmap_preview=1';
			}

			// If filtering by "guest" visitors, append parameter to show page as logged-out user
			if (this.filters.visitor_type === 'guest' && pageUrl) {
				pageUrl = pageUrl + '&opti_preview_as_guest=1';
			}

			// A/B test variant preview: apply variant changes in the iframe
			// so the page looks exactly like what that variant's visitors saw.
			if (this.abTestId && this.variantId && pageUrl) {
				pageUrl = pageUrl + '&opti_ab_preview_variant=' + this.variantId + '&opti_ab_preview_test=' + this.abTestId;
			}

			const self = this;
			this.heatmapAlreadyRendered = false;
			this._proxyAttempted = false; // Reset proxy fallback flag for each new render

			// Remember the exact preview URL so the interactive-mode navigation
			// guard can restore it if the page navigates away despite the guard.
			this.currentPreviewUrl = pageUrl || '';

			// Create iframe for page background (if page URL available)
			if (pageUrl) {
				const iframe = document.createElement('iframe');
				this.updatePendingState(this.config.i18n.loading_preview || 'Loading page preview...', loadId);

				// Set onload handler BEFORE setting src to ensure we catch the load event
				iframe.onload = function() {
					if (self.isStaleLoad(loadId)) {
						return;
					}
					if (self.heatmapAlreadyRendered) {
						self.debug('debug', 'Heatmap already rendered, skipping onload handler');
						return;
					}
					self.debug('debug', 'Iframe onload fired!');

					const waitForPageReady = function() {
						if (self.isStaleLoad(loadId)) {
							return;
						}

						try {
							const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;

							if (!iframeDoc || !iframeDoc.body) {
								self.debug('debug', 'Iframe document not ready yet, waiting...');
								setTimeout(waitForPageReady, 100);
								return;
							}

							if (iframeDoc.readyState !== 'complete') {
								self.debug('debug', 'Iframe readyState: ' + iframeDoc.readyState + ' - waiting for complete...');
								setTimeout(waitForPageReady, 100);
								return;
							}

							// Detect blank/blocked iframe caused by X-Frame-Options or CSP frame-ancestors
							// headers set at the server/CDN level (PHP header_remove() cannot strip these).
							// When blocked, the browser silently loads about:blank — body is empty.
							if (!self._proxyAttempted &&
								iframeDoc.body.children.length === 0 &&
								iframeDoc.title === '' &&
								iframeDoc.body.innerHTML.trim() === '') {
								self.debug('warn', 'Iframe appears blank — X-Frame-Options/CSP blocked direct load. Falling back to server-side proxy...');
								self._loadIframeViaProxy(iframe, pageUrl, data, viewport, container, innerWrapper, containerWidth, referenceWidth, referenceHeight, loadId);
								return;
							}

							self.debug('debug', 'Iframe loaded! Measuring page height...');
							self.updatePendingState(self.config.i18n.measuring_preview || 'Measuring page preview...', loadId);

							const doc = iframe.contentDocument || iframe.contentWindow.document;

							// Wait a bit for page content to settle, then measure from actual iframe
							setTimeout(() => {
								if (self.isStaleLoad(loadId)) {
									return;
								}

								try {
									// FIX: Inject a temporary <style> tag with !important to override
									// stylesheet rules like min-height:100% and flex-grow that cause page
									// elements to stretch to fill the iframe's initial 10000px height.
									// Must target html, body AND common theme wrappers that also use
									// min-height:100% + flexbox, otherwise scrollHeight stays inflated.
									// SLIDER-SAFE: shrink iframe so min-height:100% wrappers become small
									var origIframeH = iframe.style.height;
									iframe.style.height = '100px';
									var measureStyle = doc.createElement('style');
									measureStyle.textContent = 'html, body { height: auto !important; min-height: 0 !important; overflow: visible !important; } ' +
											'rs-fullwidth-wrap, rs-module-wrap, ' + // RevSlider 6
											'.slick-slider, .flexslider, ' + // Slick, Flexslider
											'.swiper-container, .swiper, ' + // Swiper
											'.ls-wp-container, .ls-container, ' + // LayerSlider
											'.n2-ss-slider, ' + // Smart Slider 3
											'.owl-carousel, ' + // Owl Carousel
											'.elementor-slides-wrapper, .elementor-widget-slides ' + // Elementor
											'{ display: none !important; }';
									doc.head.appendChild(measureStyle);
									void doc.body.offsetHeight; // force reflow

									// Measure actual page dimensions from iframe
									const bodyWidth = doc.body.scrollWidth;
									const docWidth = doc.documentElement.scrollWidth;
									const bodyHeight = doc.body.scrollHeight;

									// Remove temporary style
									doc.head.removeChild(measureStyle);
									iframe.style.height = origIframeH;

									// Use bodyHeight as primary since it reflects true content size.
									// Floor the width at the device-matched viewport width (not the
									// desktop reference) so a mobile/tablet layout is not stretched
									// back up to desktop width after measurement.
									const actualWidth = Math.max(bodyWidth, docWidth, deviceViewportWidth);
									let actualHeight = bodyHeight > 100 ? bodyHeight : referenceHeight;

										self.debug('debug', 'Actual page size: ' + actualWidth + 'x' + actualHeight + ' (body: ' + bodyWidth + ', doc: ' + docWidth + ', container: ' + containerWidth + ')', 'heatmap-detail');

									const scale = self._applyIframeContentSize(iframe, container, actualWidth, actualHeight, containerWidth);

										self.debug('debug', 'Scale factor: ' + scale.toFixed(3), 'heatmap-detail');
									if (doc && doc.body) {
										// body overflow preserved for slider compatibility
										doc.documentElement.style.overflow = 'hidden';
									}

									// FIX v6: UNIVERSAL slider/hero height cap.
									// Many slider frameworks (RevSlider, Swiper, Slick, etc.) and hero
									// sections read the container/viewport height during init and stretch
									// to fill it. Inside an iframe, "viewport" = iframe height, so if the
									// iframe was tall during init, these elements become huge gray blocks.
									// We apply: (1) CSS caps for known frameworks, (2) generic JS scan
									// for ANY element with inflated inline height.
									var sliderViewportH = Math.max(window.innerHeight || 800, 800);
									try {
										var sliderFixStyle = doc.createElement('style');
										sliderFixStyle.id = 'opti-slider-fix';
										sliderFixStyle.textContent =
											// RevSlider 6
											'rs-fullwidth-wrap, rs-module-wrap { max-height: ' + sliderViewportH + 'px !important; } ' +
											'rs-module { max-height: ' + sliderViewportH + 'px !important; overflow: hidden !important; } ' +
											// Swiper
											'.swiper-container, .swiper { max-height: ' + sliderViewportH + 'px !important; overflow: hidden !important; } ' +
											// Slick
											'.slick-slider, .slick-list { max-height: ' + sliderViewportH + 'px !important; overflow: hidden !important; } ' +
											// Flexslider
											'.flexslider { max-height: ' + sliderViewportH + 'px !important; overflow: hidden !important; } ' +
											// LayerSlider
											'.ls-wp-container, .ls-container { max-height: ' + sliderViewportH + 'px !important; overflow: hidden !important; } ' +
											// Smart Slider 3
											'.n2-ss-slider { max-height: ' + sliderViewportH + 'px !important; overflow: hidden !important; } ' +
											// Owl Carousel
											'.owl-carousel, .owl-stage-outer { max-height: ' + sliderViewportH + 'px !important; overflow: hidden !important; } ' +
											// Elementor slides
											'.elementor-slides-wrapper, .elementor-widget-slides { max-height: ' + sliderViewportH + 'px !important; overflow: hidden !important; }';
										doc.head.appendChild(sliderFixStyle);

										// GENERIC: Scan ALL elements for inflated inline heights.
										// Any element with style.height > 1.5x viewport was likely inflated
										// by JS reading the iframe height during init. Cap it.
										// This catches unknown/custom slider frameworks automatically.
										var inflatedThreshold = sliderViewportH * 1.5;
										var allEls = doc.querySelectorAll('*');
										allEls.forEach(function(el) {
											var inlineH = parseInt(el.style.height);
											var elWidth = el.offsetWidth || el.clientWidth || 0;
											if (!isNaN(inlineH) && inlineH > inflatedThreshold) {
												// Only cap elements that are reasonably wide (likely full-width heroes/sliders)
												// Skip narrow elements like sidebars, tall scrollable lists, etc.
												if (elWidth > sliderViewportH * 0.4) {
													el.style.maxHeight = sliderViewportH + 'px';
													el.style.overflow = 'hidden';
												}
											}

											try {
												var computedStyle = iframe.contentWindow.getComputedStyle(el);
												var computedMinHeight = parseFloat(computedStyle && computedStyle.minHeight) || 0;
												var className = String(el.className || '').toLowerCase();
												var hasViewportHeightClass =
													className.indexOf('elementor-section-height-full') !== -1 ||
													className.indexOf('elementor-section-height-min-height') !== -1 ||
													className.indexOf('min-vh-100') !== -1 ||
													className.indexOf('vh-100') !== -1 ||
													className.indexOf('min-h-screen') !== -1 ||
													className.indexOf('h-screen') !== -1;
												var minHeightTracksIframe = computedMinHeight > inflatedThreshold && computedMinHeight >= Math.max(actualHeight * 0.75, inflatedThreshold);

												if (elWidth > sliderViewportH * 0.4 && (minHeightTracksIframe || hasViewportHeightClass)) {
													el.style.minHeight = sliderViewportH + 'px';
												}
											} catch(minHeightCapErr) {
												// Ignore per-element style read failures; the broad scan must stay best-effort.
											}
										});

										// RevSlider-specific: rescale layer positions proportionally.
										// (Only runs if rs-layer-wrap elements exist - harmless on non-RS pages)
										if (actualHeight > sliderViewportH) {
											var layerScaleFactor = sliderViewportH / actualHeight;
											var layerWraps = doc.querySelectorAll('rs-layer-wrap');
											layerWraps.forEach(function(wrap) {
												var origTop = parseFloat(wrap.style.top);
												if (!isNaN(origTop) && origTop > sliderViewportH) {
													wrap.style.top = (origTop * layerScaleFactor) + 'px';
												}
											});
										}
									// NOTE: Do NOT call revredraw() or dispatchEvent('resize') here.
									// Those cause a feedback loop with slider frameworks.
									} catch(sliderFixErr) {}

									// FIX v5: Re-measure height with sliders VISIBLE (but capped).
									// The initial measurement hid sliders with display:none, so
									// actualHeight doesn't include slider space. Now that sliders
									// are capped to viewport height, measure again to get the TRUE
									// page height including the capped sliders.
										try {
											var postSliderH = self._measureIframeContentHeight(doc, actualHeight);
											if (postSliderH > actualHeight + 50) {
												actualHeight = postSliderH;
												self._applyIframeContentSize(iframe, container, actualWidth, actualHeight, containerWidth);
											}
										} catch(postSliderErr) {
											self.debug('warn', 'Post-slider height re-measurement failed:', postSliderErr);
										}

									// ELEMENT-ANCHORED CLICKS: the preview iframe layout is now
									// measured and sized, so element rects are safe to read for
									// anchored click reprojection (see _reprojectAnchoredClicks).
									self.anchorLayoutSettledLoadId = loadId;

									self.createOverlayAndRenderHeatmap(data, viewport, loadId);

									self._startIframeHeightWatcher(iframe, container, actualWidth, containerWidth, actualHeight, data, viewport, loadId);

									// Interactive preview is ON by default: make the settled
									// iframe clickable now so the admin can reveal hidden UI
									// and gated hotspots realign without toggling anything.
									self._autoEnableInteractivePreview();
								} catch (e) {
										self.debug('error', 'Error measuring page dimensions:', e);
									// Fallback: use container width without scaling
									self.idealWidth = containerWidth;
									self.idealHeight = referenceHeight;
									self.canvasScaleFactor = 1;
									iframe.style.width = containerWidth + 'px';
									iframe.style.height = referenceHeight + 'px';
									container.style.width = containerWidth + 'px';
									container.style.height = referenceHeight + 'px';

									if (doc && doc.body) {
										// body overflow preserved for slider compatibility
										doc.documentElement.style.overflow = 'hidden';
									}

									self.createOverlayAndRenderHeatmap(data, viewport, loadId);
								}
							}, 800);
						} catch (e) {
							self.debug('error', 'Error accessing iframe content:', e);
							// SecurityError or cross-origin error — likely X-Frame-Options: DENY
							// or CSP frame-ancestors blocking the iframe load.
							// Try proxy fallback before rendering without page background.
							if (!self._proxyAttempted && pageUrl) {
								self.debug('warn', 'Iframe access blocked (SecurityError) — falling back to server-side proxy...');
								self._loadIframeViaProxy(iframe, pageUrl, data, viewport, container, innerWrapper, containerWidth, referenceWidth, referenceHeight, loadId);
								return;
							}
							self.idealWidth = containerWidth;
							self.idealHeight = referenceHeight;
							self.canvasScaleFactor = 1;
							iframe.style.width = containerWidth + 'px';
							iframe.style.height = referenceHeight + 'px';
							container.style.width = containerWidth + 'px';
							container.style.height = referenceHeight + 'px';
							self.createOverlayAndRenderHeatmap(data, viewport, loadId);
						}
					};

					waitForPageReady();
				};

				// Set iframe styles and load directly via src. The iframe width IS the
				// CSS viewport the page lays out against, so use the device-matched
				// width to trigger the correct responsive layout before measurement.
				iframe.style.width = deviceViewportWidth + 'px';
				iframe.style.height = Math.max(window.innerHeight || 800, 800) + 'px';
				iframe.style.border = 'none';
				iframe.style.position = 'absolute';
				iframe.style.top = '0';
				iframe.style.left = '0';
				iframe.style.pointerEvents = 'none';
				iframe.style.zIndex = '1';
				iframe.id = 'heatmap-iframe';
				iframe.scrolling = 'no';
				iframe.setAttribute('scrolling', 'no');

				// Load the page directly via iframe.src for pixel-perfect rendering.
				// The opti_heatmap_preview=1 parameter (already added to pageUrl)
				// triggers PHP to remove X-Frame-Options headers and skip tracking
				// scripts, so the page loads cleanly inside the iframe.
				self.debug('debug', 'Loading iframe via direct src: ' + pageUrl);
				iframe.src = pageUrl;

				innerWrapper.appendChild(iframe);
			}

			// If there's no iframe, create overlay and render immediately
			if (!pageUrl) {
				this.debug('debug', 'No page URL, rendering heatmap in parent document...');
				this.idealWidth = referenceWidth;
				this.idealHeight = referenceHeight;
				this.canvasScaleFactor = 1;
				container.style.height = referenceHeight + 'px';
				this.createOverlayAndRenderHeatmap(data, viewport, loadId);
			} else {
				this.debug('debug', 'Page URL present, waiting for iframe to load before rendering heatmap...');
			}
		}

		/**
		 * Fallback rendering method when measurement fails (kept for compatibility)
		 * This method is no longer used but kept for safety
		 */
		renderHeatmapWithFallback(data, viewport, container, innerWrapper, containerWidth, referenceWidth, referenceHeight, pageUrl, loadId = this.activeLoadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}

			// Device-matched preview viewport width (mobile/tablet render their own
			// responsive layout; desktop / all fall back to the reference width).
			const deviceViewportWidth = this._getDeviceViewportWidth(referenceWidth);

			// Store dimensions - use actual page size
			this.idealWidth = deviceViewportWidth;
			this.idealHeight = referenceHeight;
			this.canvasScaleFactor = 1;

			// Set container dimensions to actual page size
			container.style.height = referenceHeight + 'px';
			container.style.width = deviceViewportWidth + 'px';

			this.debug('debug', 'Fallback dimensions: ' + deviceViewportWidth + 'x' + referenceHeight);

			if (pageUrl) {
				// Create iframe with actual page dimensions
				const iframe = document.createElement('iframe');
				iframe.src = pageUrl;
				iframe.style.width = deviceViewportWidth + 'px';
				iframe.style.height = referenceHeight + 'px';
				iframe.style.border = 'none';
				iframe.style.position = 'absolute';
				iframe.style.top = '0';
				iframe.style.left = '0';
				iframe.style.pointerEvents = 'none';
				iframe.style.zIndex = '1';
				iframe.id = 'heatmap-iframe';
				iframe.scrolling = 'no';
				iframe.setAttribute('scrolling', 'no');

				iframe.onload = function() {
					try {
						const doc = iframe.contentDocument || iframe.contentWindow.document;
						if (doc && doc.body) {
							// body overflow preserved for slider compatibility
							doc.documentElement.style.overflow = 'hidden';
						}
					} catch (e) {
						// Cross-origin, ignore
					}
				};

				innerWrapper.appendChild(iframe);
			}

			// Render heatmap
			this.createOverlayAndRenderHeatmap(data, viewport, loadId);
		}

		/**
		 * Load the iframe background via server-side proxy (srcdoc fallback).
		 *
		 * Called automatically when the direct iframe.src load is blocked by
		 * X-Frame-Options or CSP frame-ancestors headers added at the server/CDN
		 * level (which PHP's header_remove() cannot strip). Using iframe.srcdoc
		 * bypasses these restrictions because the browser treats the content as
		 * same-origin inline HTML — no HTTP response headers apply.
		 *
		 * After setting srcdoc the existing iframe.onload fires again and handles
		 * all measurement and rendering — no additional code is required here.
		 *
		 * @param {HTMLIFrameElement} iframe
		 * @param {string}            pageUrl           URL originally loaded (includes opti_heatmap_preview=1)
		 * @param {Object}            data              Heatmap data object
		 * @param {Object}            viewport          Viewport dimensions
		 * @param {HTMLElement}       container         Heatmap container element
		 * @param {HTMLElement}       innerWrapper      Inner wrapper element
		 * @param {number}            containerWidth    Available width in px
		 * @param {number}            referenceWidth    Reference/capture width in px
		 * @param {number}            referenceHeight   Reference/capture height in px
		 */
		_loadIframeViaProxy(iframe, pageUrl, data, viewport, container, innerWrapper, containerWidth, referenceWidth, referenceHeight, loadId = this.activeLoadId) {
			const self = this;
			if (self.isStaleLoad(loadId)) {
				return;
			}
			self._proxyAttempted = true; // Prevent recursive proxy attempts
			// Device-matched preview viewport width (same rule as the direct path);
			// the reused iframe already carries this width from the direct attempt.
			const deviceViewportWidth = self._getDeviceViewportWidth(referenceWidth);
			self.debug('info', 'Loading iframe via server-side proxy (blob URL) — URL: ' + pageUrl);
			self.updatePendingState(self.config.i18n.loading_preview_proxy || 'Loading page preview through proxy...', loadId);

			const request = $.ajax({
				url:     self.config.ajax_url,
				type:    'POST',
				timeout: 30000,
				data: {
					action: 'opti_behavior_proxy_page',
					nonce:  self.config.nonce,
					url:    pageUrl
				},
				success: function(response) {
					self.untrackXhr(loadId, request);
					if (self.isStaleLoad(loadId)) {
						return;
					}

					if (response.success && response.data && response.data.html) {
						self.debug('info', 'Proxy returned HTML (' + response.data.html.length + ' bytes). Injecting via blob URL...');
						// Use blob URL instead of srcdoc. srcdoc creates a null-origin document
						// where jQuery, Elementor, and theme scripts fail with "jQuery is not defined".
						// Blob URLs inherit the parent page's origin, so all scripts execute normally
						// AND X-Frame-Options does not apply (blob: scheme, no HTTP response headers).
						var blob = new Blob([response.data.html], { type: 'text/html' });
						var blobUrl = URL.createObjectURL(blob);
						// Set onload to handle measurement after blob page loads
						iframe.onload = function() {
							if (self.isStaleLoad(loadId)) {
								URL.revokeObjectURL(blobUrl);
								return;
							}

							// Revoke blob URL to free memory (page is already loaded)
							URL.revokeObjectURL(blobUrl);
							// Re-run the page ready check (inline, since free plugin uses closures)
							if (self.heatmapAlreadyRendered) {
								self.debug('debug', 'Heatmap already rendered, skipping proxy onload');
								return;
							}
							self.debug('debug', 'Proxy blob iframe onload fired!');
							var waitForPageReady = function() {
								if (self.isStaleLoad(loadId)) {
									return;
								}

								try {
									var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
									if (!iframeDoc || !iframeDoc.body) {
										setTimeout(waitForPageReady, 100);
										return;
									}
									if (iframeDoc.readyState !== 'complete') {
										setTimeout(waitForPageReady, 100);
										return;
									}
									// Proceed with normal measurement — delegate to the same
									// measurement logic by re-triggering the existing onload path.
									// Since _proxyAttempted is true, blank detection is skipped.
									self.debug('debug', 'Proxy blob page ready, measuring...');
									self.updatePendingState(self.config.i18n.measuring_preview_proxy || 'Measuring proxied page preview...', loadId);
									var doc = iframeDoc;
									setTimeout(function() {
										if (self.isStaleLoad(loadId)) {
											return;
										}

										try {
											var origIframeH = iframe.style.height;
											iframe.style.visibility = 'hidden';
											iframe.style.height = '100px';
											var measureStyle = doc.createElement('style');
											measureStyle.textContent = 'html, body { height: auto !important; min-height: 0 !important; overflow: visible !important; }';
											doc.head.appendChild(measureStyle);
											var bodyWidth = Math.max(doc.body.scrollWidth, doc.body.offsetWidth);
											var bodyHeight = Math.max(doc.body.scrollHeight, doc.body.offsetHeight);
											var docWidth = Math.max(doc.documentElement.scrollWidth, doc.documentElement.offsetWidth);
											var docHeight = Math.max(doc.documentElement.scrollHeight, doc.documentElement.offsetHeight);
											doc.head.removeChild(measureStyle);
											iframe.style.height = origIframeH;
											iframe.style.visibility = 'visible';
											var actualWidth = Math.max(bodyWidth, docWidth, deviceViewportWidth);
											var measuredHeight = bodyHeight > 100 ? bodyHeight : Math.max(bodyHeight, docHeight);
											var actualHeight = self._measureIframeContentHeight(doc, measuredHeight > 100 ? measuredHeight : referenceHeight);
											self._applyIframeContentSize(iframe, container, actualWidth, actualHeight, containerWidth);
											self.contentOffsetX = 0;
											try {
												var bodyStyle = iframe.contentWindow.getComputedStyle(doc.body);
												var marginLeft = parseInt(bodyStyle.marginLeft) || 0;
												var paddingLeft = parseInt(bodyStyle.paddingLeft) || 0;
												self.contentOffsetX = marginLeft + paddingLeft;
											} catch(oE) {}
											if (doc && doc.body) {
												doc.documentElement.style.overflow = 'hidden';
											}
											// ELEMENT-ANCHORED CLICKS: proxied preview measured and
											// sized — element rects safe to read for reprojection.
											self.anchorLayoutSettledLoadId = loadId;
											self.createOverlayAndRenderHeatmap(data, viewport, loadId);
											self._startIframeHeightWatcher(iframe, container, actualWidth, containerWidth, actualHeight, data, viewport, loadId);
											// Interactive preview ON by default (proxy path too).
											self._autoEnableInteractivePreview();
										} catch(mErr) {
											self.debug('error', 'Proxy measurement error:', mErr);
											self.idealWidth = containerWidth;
											self.idealHeight = referenceHeight;
											self.canvasScaleFactor = 1;
											self.createOverlayAndRenderHeatmap(data, viewport, loadId);
										}
									}, 800);
								} catch(e) {
									self.debug('error', 'Proxy iframe access error:', e);
									self.idealWidth = containerWidth;
									self.idealHeight = referenceHeight;
									self.canvasScaleFactor = 1;
									self.createOverlayAndRenderHeatmap(data, viewport, loadId);
								}
							};
							waitForPageReady();
						};
						iframe.src = blobUrl;
					} else {
						self.debug('error', 'Proxy returned no usable HTML. Rendering heatmap without page background.', response);
						// Last resort: render heatmap dots without a page background.
						self.idealWidth  = referenceWidth;
						self.idealHeight = referenceHeight;
						self.canvasScaleFactor = 1;
						self.createOverlayAndRenderHeatmap(data, viewport, loadId);
					}
				},
				error: function(xhr, status, err) {
					self.untrackXhr(loadId, request);
					if (self.isStaleLoad(loadId) || status === 'abort') {
						return;
					}

					self.debug('error', 'Proxy AJAX request failed (status: ' + status + '). Rendering without page background.', err);
					// Last resort: render heatmap dots without a page background.
					self.idealWidth  = referenceWidth;
					self.idealHeight = referenceHeight;
					self.canvasScaleFactor = 1;
					self.createOverlayAndRenderHeatmap(data, viewport, loadId);
				}
			});
			self.trackXhr(loadId, request);
		}

		/**
		 * ============================================================
		 * INTERACTIVE PREVIEW MODE + NAVIGATION GUARD
		 * ============================================================
		 * When interactive mode is ON, pointer events are enabled on the preview
		 * iframe so the admin can click inside it to reveal hidden UI (collapsed
		 * accordions, closed menus, tabs). Revealing that UI lets the existing
		 * anchor reprojection realign recorded hotspots against the layout the
		 * visitor actually saw.
		 *
		 * Because the preview is a live copy of the real page, every click is
		 * routed through a capture-phase navigation guard that neutralises any
		 * link/form/window.open navigation WITHOUT calling stopPropagation, so the
		 * page's own toggle handlers (accordion/menu JS) still run.
		 */

		/**
		 * Toggle interactive preview mode.
		 *
		 * ON  -> iframe.pointerEvents = 'auto', install navigation guard.
		 * OFF -> iframe.pointerEvents = 'none', remove navigation guard.
		 *
		 * The heatmap canvas/overlay stays pointer-events:none (set at render
		 * time), so clicks pass through the overlay to the iframe beneath.
		 *
		 * Live re-render on interaction (observers) and the collapsed-overlay
		 * restore are added by a later step; this method calls the optional
		 * hooks `_startInteractivePreviewObservers` / `_stopInteractivePreviewObservers`
		 * if they exist, so wiring them in requires no change here.
		 *
		 * @param {boolean} on
		 * @return {boolean} the resulting interactiveMode state
		 */
		setInteractiveMode(on) {
			const enabled = !!on;
			if (this.interactiveMode === enabled) {
				return this.interactiveMode;
			}
			this.interactiveMode = enabled;

			const iframe = document.getElementById('heatmap-iframe');
			if (iframe) {
				// Toggle pointer events on the preview iframe. This covers BOTH the
				// direct-src and proxy/blob iframes because they share the same
				// element id ('heatmap-iframe').
				iframe.style.pointerEvents = enabled ? 'auto' : 'none';
				if (iframe.classList) {
					// CSS hook (cursor hint / active state) added by a later step.
					iframe.classList.toggle('opti-interactive-preview', enabled);
				}
			}

			let doc = null;
			if (iframe) {
				try {
					doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
				} catch (e) {
					// Cross-origin/detached — guard cannot attach; pointer toggle still applied.
					doc = null;
				}
			}

			if (enabled) {
				this._installPreviewNavigationGuard(doc);
				// Hook: start live re-render observers (added by a later step).
				if (typeof this._startInteractivePreviewObservers === 'function') {
					this._startInteractivePreviewObservers(doc);
				}
			} else {
				// Hook: stop observers + re-render the default collapsed overlay
				// (added by a later step).
				if (typeof this._stopInteractivePreviewObservers === 'function') {
					this._stopInteractivePreviewObservers();
				}
				this._removePreviewNavigationGuard(doc);
			}

			this.debug('debug', 'Interactive preview mode ' + (enabled ? 'ON' : 'OFF'));
			return this.interactiveMode;
		}

		/**
		 * Start the live re-render observers for interactive preview mode.
		 *
		 * While ON, expanding hidden UI (accordion answers, dropdowns, tabs)
		 * changes the iframe layout. A debounced MutationObserver + ResizeObserver
		 * on the iframe document detect that change and re-measure height, then
		 * reproject + repaint the overlay so recorded hotspots realign with the
		 * now-revealed elements.
		 *
		 * Feedback-loop safety: the re-render helper temporarily mutates the
		 * iframe document (measurement <style>, height/visibility toggles), so
		 * both observers are SUSPENDED around every re-render — otherwise those
		 * self-inflicted mutations would retrigger the observers unbounded. The
		 * re-measured height is also capped by the same guard the height-watcher
		 * uses (_interactivePreviewContext.maxAllowedHeight).
		 *
		 * Idempotent: any existing observer bundle is torn down first.
		 *
		 * @param {Document} [doc] the preview iframe document (from setInteractiveMode)
		 */
		_startInteractivePreviewObservers(doc) {
			try {
				if (!this.interactiveMode) {
					return;
				}
				// Tear down any prior bundle WITHOUT the collapsed re-render that
				// _stopInteractivePreviewObservers performs (we are (re)starting).
				this._teardownInteractivePreviewObservers();

				let watchedDoc = doc || null;
				if (!watchedDoc) {
					const iframe0 = document.getElementById('heatmap-iframe');
					try {
						watchedDoc = iframe0 && (iframe0.contentDocument || (iframe0.contentWindow && iframe0.contentWindow.document));
					} catch (e) {
						watchedDoc = null;
					}
				}
				if (!watchedDoc || !watchedDoc.body) {
					this.debug('debug', 'Interactive observers: no accessible iframe document, skipping');
					return;
				}

				const self = this;
				const bundle = {
					doc: watchedDoc,
					mutation: null,
					resize: null,
					debounce: null,
					suspended: false
				};

				const schedule = function(reason) {
					if (bundle.suspended || !self.interactiveMode || bundle !== self._interactivePreviewObservers) {
						return;
					}
					// Phase 1 scroll-perf: defer live re-render while the user is
					// actively scrolling the wrapper. Remember that a change was
					// observed so _onWrapperScrollStop() can run it once scroll ends.
					if (self._scrollInFlight) {
						self._interactiveRepaintPendingDuringScroll = true;
						return;
					}
					if (bundle.debounce) {
						clearTimeout(bundle.debounce);
					}
					bundle.debounce = setTimeout(function() {
						bundle.debounce = null;
						self._reprojectInteractivePreview(reason);
					}, 200);
				};
				// Exposed so the scroll-stop handler can resume a deferred re-render.
				bundle.__schedule = schedule;

				this._interactivePreviewObservers = bundle;

				if (typeof MutationObserver !== 'undefined') {
					bundle.mutation = new MutationObserver(function() {
						schedule('mutation');
					});
				}
				if (typeof ResizeObserver !== 'undefined') {
					bundle.resize = new ResizeObserver(function() {
						schedule('resize');
					});
				}
				this._connectInteractiveObservers(bundle);

				this.debug('debug', 'Interactive preview observers started');
			} catch (e) {
				this.debug('warn', 'Could not start interactive preview observers:', e);
			}
		}

		/**
		 * (Re)connect the bundle's Mutation/Resize observers to their document.
		 * Clears the suspended flag. Safe to call repeatedly.
		 *
		 * @param {Object} bundle
		 */
		_connectInteractiveObservers(bundle) {
			if (!bundle || bundle !== this._interactivePreviewObservers) {
				return;
			}
			const doc = bundle.doc;
			if (!doc || !doc.body) {
				return;
			}
			try {
				if (bundle.mutation) {
					bundle.mutation.observe(doc.body, {
						childList: true,
						subtree: true,
						attributes: true,
						attributeFilter: ['class', 'style', 'hidden', 'aria-expanded', 'aria-hidden', 'open']
					});
				}
			} catch (e) {}
			try {
				if (bundle.resize) {
					if (doc.documentElement) {
						bundle.resize.observe(doc.documentElement);
					}
					if (doc.body) {
						bundle.resize.observe(doc.body);
					}
				}
			} catch (e) {}
			bundle.suspended = false;
		}

		/**
		 * Suspend the bundle's observers so re-render self-mutations of the iframe
		 * document do not retrigger them. Disconnecting drops any queued records.
		 *
		 * @param {Object} bundle
		 */
		_suspendInteractiveObservers(bundle) {
			if (!bundle) {
				return;
			}
			bundle.suspended = true;
			if (bundle.debounce) {
				clearTimeout(bundle.debounce);
				bundle.debounce = null;
			}
			try { if (bundle.mutation) { bundle.mutation.disconnect(); } } catch (e) {}
			try { if (bundle.resize) { bundle.resize.disconnect(); } } catch (e) {}
		}

		/**
		 * Re-measure the interactive preview height (feedback-loop-capped),
		 * resize the iframe/container if it changed, then reproject anchored
		 * clicks + repaint the overlay against the revealed layout.
		 *
		 * Observers are suspended for the whole operation and reconnected a tick
		 * later, so the temporary measurement mutations are ignored.
		 *
		 * @param {string} reason debug label for the trigger
		 */
		_reprojectInteractivePreview(reason) {
			const bundle = this._interactivePreviewObservers;
			const ctx = this._interactivePreviewContext;
			if (!this.interactiveMode || !bundle || bundle !== this._interactivePreviewObservers || !ctx) {
				return;
			}
			if (this.isStaleLoad(ctx.loadId)) {
				return;
			}

			this._suspendInteractiveObservers(bundle);

			// Preserve the user's scroll position: measurement temporarily
			// mutates heights (clamping scroll) and createOverlayAndRenderHeatmap
			// auto-scrolls to center, which would yank the view away from the
			// element the user just interacted with.
			const scrollWrapper = document.querySelector('.heatmap-canvas-wrapper');
			const savedScrollTop = scrollWrapper ? scrollWrapper.scrollTop : 0;
			const savedScrollLeft = scrollWrapper ? scrollWrapper.scrollLeft : 0;
			try {
				const iframe = document.getElementById('heatmap-iframe');
				if (!iframe) {
					return;
				}
				let rDoc = null;
				try {
					rDoc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
				} catch (e) {
					rDoc = null;
				}
				if (!rDoc || !rDoc.body) {
					return;
				}

				// Re-measure with the same helper the height-watcher uses (hides
				// runaway 100vh/slider wrappers) and cap to the stored guard.
				// allowShrink: collapsing UI must be able to shrink the preview too.
				let measuredH = this._measureStableLateIframeHeight(iframe, ctx.height || 0, true);
				const cap = ctx.maxAllowedHeight || Math.max((ctx.height || 0) * 1.6, (ctx.height || 0) + 3000, 8000);
				if (measuredH > cap) {
					this.debug('warn', 'Interactive re-render height capped (' + reason + '): ' + measuredH + ' -> ' + cap, 'heatmap-detail');
					measuredH = cap;
				}

				// Resize on any real change (grow when expanding, shrink when
				// collapsing) so the overlay canvas matches the revealed layout.
				if (measuredH > 0 && Math.abs(measuredH - (ctx.height || 0)) > 2) {
					ctx.height = measuredH;
					this._applyIframeContentSize(iframe, ctx.container, ctx.actualWidth, measuredH, ctx.containerWidth);
				}

				// Reproject anchored clicks + repaint. createOverlayAndRenderHeatmap
				// runs _reprojectAnchoredClicks internally against the (now settled)
				// layout, so previously-hidden anchors become visible and realign.
				this._suppressScrollToCenterOnce = true;
				this.createOverlayAndRenderHeatmap(ctx.data, ctx.viewport, ctx.loadId);
				this.debug('debug', 'Interactive preview re-rendered (' + reason + '), height=' + measuredH);
			} catch (e) {
				this.debug('warn', 'Interactive preview re-render failed (' + reason + '):', e);
			} finally {
				// Restore the pre-render scroll position (measurement/render may
				// have clamped it; scrollToCenter itself is suppressed via the
				// one-shot flag). Restore synchronously only — a delayed restore
				// would clobber any scroll the user performs right after.
				if (scrollWrapper) {
					scrollWrapper.scrollTop = savedScrollTop;
					scrollWrapper.scrollLeft = savedScrollLeft;
				}
				// Reconnect on a short delay so mutations from our own
				// measurement/render are flushed before observing resumes.
				const self = this;
				setTimeout(function() {
					if (self.interactiveMode && bundle === self._interactivePreviewObservers) {
						self._connectInteractiveObservers(bundle);
					}
				}, 50);
			}
		}

		/**
		 * Disconnect + drop the interactive observer bundle. Does NOT re-render.
		 * Internal helper shared by start (idempotent reset) and stop.
		 */
		_teardownInteractivePreviewObservers() {
			const bundle = this._interactivePreviewObservers;
			if (bundle) {
				if (bundle.debounce) {
					try { clearTimeout(bundle.debounce); } catch (e) {}
					bundle.debounce = null;
				}
				try { if (bundle.mutation) { bundle.mutation.disconnect(); } } catch (e) {}
				try { if (bundle.resize) { bundle.resize.disconnect(); } } catch (e) {}
			}
			this._interactivePreviewObservers = null;
		}

		/**
		 * Stop interactive live re-render: remove observers and repaint the
		 * overlay once against the current layout (the default, non-interactive
		 * state). Called when interactive mode is toggled OFF.
		 */
		_stopInteractivePreviewObservers() {
			this._teardownInteractivePreviewObservers();

			// Repaint the default overlay against the current layout so toggling
			// off matches the non-interactive render. Third-party widgets that the
			// visitor expanded cannot be force-collapsed, so this reprojects onto
			// whatever layout is currently shown.
			const ctx = this._interactivePreviewContext;
			if (ctx && !this.isStaleLoad(ctx.loadId)) {
				try {
					this.createOverlayAndRenderHeatmap(ctx.data, ctx.viewport, ctx.loadId);
				} catch (e) {
					this.debug('warn', 'Collapsed overlay re-render failed:', e);
				}
			}
		}

		/**
		 * Walk up from an event target to the nearest enclosing <a>, stopping at
		 * the document. Returns the anchor element or null.
		 *
		 * @param {Node} node
		 * @param {Document} doc
		 * @return {HTMLAnchorElement|null}
		 */
		_findPreviewAnchor(node, doc) {
			let cur = node;
			while (cur && cur !== doc) {
				if (cur.tagName && String(cur.tagName).toLowerCase() === 'a') {
					return cur;
				}
				cur = cur.parentNode;
			}
			return null;
		}

		/**
		 * Decide whether a click on/inside the given anchor should have its
		 * navigation blocked. Pure `#fragment` links and `javascript:` links are
		 * allowed (they don't navigate away / are used by widgets); anything with
		 * a non-self `target` or a real href is blocked.
		 *
		 * Factored out (pure, side-effect-free) so it can be unit-tested directly.
		 *
		 * @param {HTMLAnchorElement|null} anchor
		 * @return {boolean} true => call preventDefault()
		 */
		_shouldPreventPreviewNavigation(anchor) {
			if (!anchor || !anchor.getAttribute) {
				return false;
			}
			// New-window / named-target links always navigate away -> block.
			const target = anchor.getAttribute('target');
			if (target && String(target).toLowerCase() !== '_self') {
				return true;
			}
			const hrefRaw = anchor.getAttribute('href');
			if (hrefRaw === null || typeof hrefRaw === 'undefined') {
				return false; // no href -> pure JS handler, allow
			}
			const href = String(hrefRaw).trim();
			if (href === '') {
				return false;
			}
			if (href.charAt(0) === '#') {
				return false; // pure in-page fragment, allow
			}
			if (/^javascript:/i.test(href)) {
				return false; // javascript: pseudo-URL, allow (widget hooks)
			}
			return true; // real navigation target -> block
		}

		/**
		 * Attach the capture-phase navigation guard to the preview document +
		 * window. Idempotent per document. Neutralises:
		 *   - anchor navigation (except pure #fragment / javascript:),
		 *   - form submits,
		 *   - window.open,
		 *   - and, as a safety net, restores the preview URL if the iframe
		 *     navigates anyway (beforeunload flag + second 'load' restore).
		 *
		 * Never calls stopPropagation(), so the page's own toggle JS still runs.
		 *
		 * @param {Document} doc
		 */
		_installPreviewNavigationGuard(doc) {
			try {
				if (!doc) {
					return;
				}
				if (this._previewNavGuard && this._previewNavGuard.doc === doc) {
					return; // already guarding this exact document
				}
				if (this._previewNavGuard) {
					// Guarding a stale document — tear it down first.
					this._removePreviewNavigationGuard();
				}

				const self = this;
				const iframe = document.getElementById('heatmap-iframe');

				const clickHandler = function(e) {
					try {
						const anchor = self._findPreviewAnchor(e.target, doc);
						if (self._shouldPreventPreviewNavigation(anchor)) {
							// Block navigation only. Do NOT stopPropagation — the
							// page's accordion/menu handlers must still fire.
							e.preventDefault();
						}
					} catch (err) {
						// Never let the guard throw into page code.
					}
				};

				const submitHandler = function(e) {
					try {
						e.preventDefault();
					} catch (err) {}
				};

				const beforeUnloadHandler = function() {
					// The page attempted to navigate/unload despite the guard.
					// Flag it so the next 'load' restores the preview URL.
					self._previewNavGuardUnloadPending = true;
				};

				// Second-load restore net: if the iframe navigates anyway, reload
				// the original preview URL and re-bind the guard to the fresh doc.
				let loadHandler = null;
				if (iframe) {
					loadHandler = function() {
						if (!self.interactiveMode) {
							return;
						}
						if (self._previewNavGuardUnloadPending) {
							self._previewNavGuardUnloadPending = false;
							try {
								if (self.currentPreviewUrl) {
									iframe.src = self.currentPreviewUrl; // triggers another load
									return;
								}
							} catch (rErr) {}
						}
						// Re-bind doc-level listeners to the (possibly new) document.
						let freshDoc = null;
						try {
							freshDoc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
						} catch (dErr) {
							freshDoc = null;
						}
						if (freshDoc && self._previewNavGuard && self._previewNavGuard.doc !== freshDoc) {
							self._bindPreviewGuardToDocument(freshDoc);
						}
					};
					iframe.addEventListener('load', loadHandler);
				}

				this._previewNavGuard = {
					doc: null,
					win: null,
					iframe: iframe,
					click: clickHandler,
					submit: submitHandler,
					beforeunload: beforeUnloadHandler,
					load: loadHandler,
					origWindowOpen: null
				};
				this._previewNavGuardUnloadPending = false;

				this._bindPreviewGuardToDocument(doc);
			} catch (e) {
				this.debug('error', 'Failed to install preview navigation guard:', e);
			}
		}

		/**
		 * (Re)bind the guard's capture listeners + window.open override to a
		 * document, detaching from any previously-bound document first. Used on
		 * install and on the second-load restore path.
		 *
		 * @param {Document} doc
		 */
		_bindPreviewGuardToDocument(doc) {
			const g = this._previewNavGuard;
			if (!g || !doc) {
				return;
			}
			// Detach from the previous document/window if different.
			if (g.doc && g.doc !== doc) {
				try { g.doc.removeEventListener('click', g.click, true); } catch (e) {}
				try { g.doc.removeEventListener('submit', g.submit, true); } catch (e) {}
				if (g.win) {
					try { g.win.removeEventListener('beforeunload', g.beforeunload); } catch (e) {}
					try { if (g.origWindowOpen) { g.win.open = g.origWindowOpen; } } catch (e) {}
				}
			}

			const win = doc.defaultView || null;
			try { doc.addEventListener('click', g.click, true); } catch (e) {}
			try { doc.addEventListener('submit', g.submit, true); } catch (e) {}
			if (win) {
				try {
					g.origWindowOpen = win.open;
					win.open = function() { return null; };
				} catch (e) {}
				try { win.addEventListener('beforeunload', g.beforeunload); } catch (e) {}
			}

			g.doc = doc;
			g.win = win;
		}

		/**
		 * Remove the navigation guard: detach all listeners, restore window.open,
		 * and drop the guard bundle. Safe to call when no guard is installed.
		 *
		 * @param {Document} [doc] unused; kept for signature symmetry with install.
		 */
		_removePreviewNavigationGuard(doc) {
			const g = this._previewNavGuard;
			if (!g) {
				return;
			}
			try { if (g.doc) { g.doc.removeEventListener('click', g.click, true); } } catch (e) {}
			try { if (g.doc) { g.doc.removeEventListener('submit', g.submit, true); } } catch (e) {}
			if (g.win) {
				try { g.win.removeEventListener('beforeunload', g.beforeunload); } catch (e) {}
				try { if (g.origWindowOpen) { g.win.open = g.origWindowOpen; } } catch (e) {}
			}
			if (g.iframe && g.load) {
				try { g.iframe.removeEventListener('load', g.load); } catch (e) {}
			}
			this._previewNavGuard = null;
			this._previewNavGuardUnloadPending = false;
		}

		/**
		 * ============================================================
		 * ELEMENT-ANCHORED CLICK REPROJECTION (settle-gated)
		 * ============================================================
		 * Click coordinates captured by the simple tracker may carry an element
		 * anchor: xp (absolute XPath of the clicked element), rx / ry (click
		 * position inside the element box, 0..1). When the anchor element can be
		 * found in the preview iframe, the click point is reprojected onto the
		 * element's CURRENT position, so dots stay glued to the element even when
		 * the visitor's layout (fonts, ads, responsive breakpoints, dynamic
		 * content) differed from the admin preview.
		 *
		 * Hard safety rules (learned from the reverted R6 attempt):
		 *   1. NEVER read element rects before the preview iframe layout has been
		 *      measured and sized (anchorLayoutSettledLoadId gate) — early reads
		 *      produced dots hundreds of pixels off.
		 *   2. Anchor missing / element not found / anything throws -> keep the
		 *      absolute x/y exactly as before (zero-regression fallback).
		 *   3. Element found but currently hidden (collapsed menu, closed popup)
		 *      -> skip the point instead of drawing it at a wrong position.
		 */

		/**
		 * Return the preview iframe document, but ONLY when the layout for this
		 * load has been measured and sized (settle gate). Null otherwise.
		 */
		_getSettledAnchorDocument(loadId) {
			try {
				const id = (typeof loadId !== 'undefined') ? loadId : this.activeLoadId;
				if (this.anchorLayoutSettledLoadId !== id) {
					return null;
				}
				const iframe = document.getElementById('heatmap-iframe');
				if (!iframe) {
					return null;
				}
				const doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
				if (!doc || !doc.body || !doc.documentElement || !doc.body.children || doc.body.children.length === 0) {
					return null;
				}
				return doc;
			} catch (e) {
				// Cross-origin / detached iframe — anchors unavailable, absolute fallback.
				return null;
			}
		}

		/**
		 * Convert the tracker's absolute XPath ('/html/body/div[2]/p') into an
		 * equivalent CSS path ('html > body > div:nth-of-type(2) > p').
		 * The tracker indexes siblings per tag name, which maps 1:1 to
		 * :nth-of-type(). Returns '' when the XPath has an unexpected shape.
		 */
		_anchorXPathToCss(xp) {
			try {
				if (!xp || xp.charAt(0) !== '/') {
					return '';
				}
				const parts = String(xp).split('/');
				const out = [];
				for (let i = 0; i < parts.length; i++) {
					if (!parts[i]) {
						continue;
					}
					const m = /^([a-zA-Z][a-zA-Z0-9-]*)(?:\[(\d+)\])?$/.exec(parts[i]);
					if (!m) {
						return '';
					}
					out.push(m[2] ? m[1] + ':nth-of-type(' + m[2] + ')' : m[1]);
				}
				return out.length ? out.join(' > ') : '';
			} catch (e) {
				return '';
			}
		}

		/**
		 * Resolve an anchor XPath in the preview document.
		 * Primary: document.evaluate. Fallback: derived CSS path.
		 */
		_findAnchorElement(doc, xp) {
			try {
				if (doc.evaluate && typeof XPathResult !== 'undefined') {
					const result = doc.evaluate(xp, doc, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null);
					const node = result ? result.singleNodeValue : null;
					if (node && node.nodeType === 1) {
						return node;
					}
				}
			} catch (xpathErr) {
				// Malformed/unsupported XPath — fall through to the CSS fallback.
			}
			try {
				const css = this._anchorXPathToCss(xp);
				if (css) {
					return doc.querySelector(css);
				}
			} catch (cssErr) {
				// Invalid selector — treated as "element not found".
			}
			return null;
		}

		/**
		 * True when the anchor element is currently rendered and visible.
		 * Hidden ancestors collapse the rect to 0x0, so the rect check also
		 * covers display:none anywhere up the tree.
		 */
		_isAnchorElementVisible(element, doc) {
			try {
				const win = doc.defaultView;
				let style = null;
				if (win && win.getComputedStyle) {
					style = win.getComputedStyle(element);
					if (style && (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity) === 0)) {
						return false;
					}
				}
				// offsetParent === null means the element (or ANY ancestor) is
				// display:none / detached — a universal, theme-agnostic visibility
				// gate that walks the whole ancestor chain for free. Exceptions:
				// position:fixed elements and <body>/<html> report a null
				// offsetParent while visible, so fall through to the rect check for
				// those. offsetParent === undefined (non-DOM test stubs) is ignored.
				const isFixed = style && style.position === 'fixed';
				const tag = element.tagName ? element.tagName.toLowerCase() : '';
				if (!isFixed && tag !== 'body' && tag !== 'html' &&
					typeof element.offsetParent !== 'undefined' && element.offsetParent === null) {
					return false;
				}
				const rect = element.getBoundingClientRect();
				if (!rect || rect.width <= 0 || rect.height <= 0) {
					return false;
				}
				// Universal overflow-clip gate. The most common CSS accordion/menu
				// collapses content WITHOUT display:none: an ancestor keeps
				// overflow:hidden (or clip) and shrinks its own box to ~0 while the
				// inner content retains a real rect and a non-null offsetParent
				// (verified live on a WP <details> FAQ: the closed item clips to the
				// summary height, the answer sits just past the clipped edge). Gate
				// such clipped-out anchors by geometry only — no theme selectors:
				// walk clipping ancestors and require a meaningful fraction of the
				// element to remain inside each one's box on the clipped axis.
				// Only overflow:hidden / clip are treated as collapse mechanisms;
				// scroll/auto containers are left alone (legitimate scrolling).
				if (win && win.getComputedStyle) {
					const VISIBLE_FRACTION = 0.5;
					let anc = element.parentElement;
					let guard = 0;
					while (anc && anc.nodeType === 1 && guard < 60) {
						const atag = anc.tagName ? anc.tagName.toLowerCase() : '';
						if (atag === 'body' || atag === 'html') {
							break;
						}
						const acs = win.getComputedStyle(anc);
						if (acs) {
							const clipsX = acs.overflowX === 'hidden' || acs.overflowX === 'clip';
							const clipsY = acs.overflowY === 'hidden' || acs.overflowY === 'clip';
							if (clipsX || clipsY) {
								const ar = anc.getBoundingClientRect();
								if (ar) {
									if (clipsY && rect.height > 0) {
										const iy = Math.max(0, Math.min(rect.bottom, ar.bottom) - Math.max(rect.top, ar.top));
										if (iy / rect.height < VISIBLE_FRACTION) {
											return false;
										}
									}
									if (clipsX && rect.width > 0) {
										const ix = Math.max(0, Math.min(rect.right, ar.right) - Math.max(rect.left, ar.left));
										if (ix / rect.width < VISIBLE_FRACTION) {
											return false;
										}
									}
								}
							}
						}
						anc = anc.parentElement;
						guard++;
					}
				}
				return true;
			} catch (e) {
				return false;
			}
		}

		/**
		 * True when an anchored click was registered at (x, y) within a ±1px
		 * box. Tolerance exists only because server-side (int) casts and
		 * client/serve-time rounding can shift one twin of the same physical
		 * click by a single pixel after width normalization; it is NOT fuzzy
		 * radius matching (a 3px-away click is a different click and survives).
		 */
		_hasAnchoredTwin(anchoredKeys, x, y) {
			x = Number(x) || 0;
			y = Number(y) || 0;
			for (let dx = -1; dx <= 1; dx++) {
				for (let dy = -1; dy <= 1; dy++) {
					if (anchoredKeys[(x + dx) + '_' + (y + dy)]) {
						return true;
					}
				}
			}
			return false;
		}

		/**
		 * Reproject anchored click coordinates onto the settled preview layout.
		 *
		 * Returns a NEW data object (original input, incl. this.allCoordinates,
		 * is never mutated). Per coordinate:
		 *   - no anchor keys           -> passed through untouched
		 *   - gate closed / no doc     -> passed through untouched (absolute)
		 *   - element not found        -> passed through untouched (absolute)
		 *   - element found, hidden    -> point skipped (popup/menu gate, D4)
		 *   - element found, visible   -> x/y replaced by element rect + rx/ry
		 *
		 * xUndoFactor / yUndoFactor are the ref->display scale factors the click
		 * branch will multiply back in; reprojected values are already in display
		 * space, so they are divided out here to survive that multiplication.
		 */
		_reprojectAnchoredClicks(data, loadId, xUndoFactor = 1, yUndoFactor = 1) {
			try {
				if (this.currentType !== 'click' || !data || !data.coordinates || !data.coordinates.length) {
					return data;
				}

				const coords = data.coordinates;
				let hasAnchors = false;
				// Anchored click positions, keyed by their STORED absolute x/y.
				// Pro session recording (rrweb) writes anchor-free duplicates of
				// the same physical click at the same page coordinates; strict
				// matching uses this set to drop those duplicates so a skipped
				// anchored click cannot "reappear" through its rrweb copy.
				const anchoredKeys = {};
				for (let i = 0; i < coords.length; i++) {
					const c = coords[i];
					if (c && c.xp && isFinite(parseFloat(c.rx)) && isFinite(parseFloat(c.ry))) {
						hasAnchors = true;
						anchoredKeys[(c.x || 0) + '_' + (c.y || 0)] = true;
					}
				}
				if (!hasAnchors) {
					return data;
				}

				const doc = this._getSettledAnchorDocument(loadId);
				if (!doc) {
					// Settle gate closed (or preview document unavailable):
					// keep absolute coordinates — identical to legacy behaviour.
					return data;
				}

				const win = doc.defaultView;
				const scrollX = win ? (win.pageXOffset || 0) : 0;
				const scrollY = win ? (win.pageYOffset || 0) : 0;
				const undoX = (isFinite(xUndoFactor) && xUndoFactor > 0) ? xUndoFactor : 1;
				const undoY = (isFinite(yUndoFactor) && yUndoFactor > 0) ? yUndoFactor : 1;

				const cache = {};
				const projected = [];
				let reprojectedCount = 0;
				let hiddenCount = 0;
				let missingCount = 0;
				let dedupedCount = 0;

				for (let i = 0; i < coords.length; i++) {
					const coord = coords[i];
					const rx = coord ? parseFloat(coord.rx) : NaN;
					const ry = coord ? parseFloat(coord.ry) : NaN;

					if (!coord || !coord.xp || !isFinite(rx) || !isFinite(ry)) {
						// Anchor-free coordinate. In strict mode, drop it when an
						// anchored coordinate exists at the exact same stored x/y:
						// it is an rrweb-derived duplicate of that anchored click
						// (Pro-only data shape; pure Free data has no duplicates,
						// so this is a no-op there).
						// Match with a ±1px tolerance: server-side int casts vs
						// client-side rounding can shift either twin by one pixel
						// after width normalization. This is drift tolerance only,
						// NOT fuzzy radius matching.
						if (this.strictAnchorMatching !== false && coord &&
							this._hasAnchoredTwin(anchoredKeys, coord.x || 0, coord.y || 0)) {
							dedupedCount++;
							continue;
						}
						projected.push(coord);
						continue;
					}

					let entry = cache[coord.xp];
					if (!entry) {
						const element = this._findAnchorElement(doc, coord.xp);
						if (element && this._isAnchorElementVisible(element, doc)) {
							entry = { state: 'visible', rect: element.getBoundingClientRect() };
						} else if (element) {
							entry = { state: 'hidden' };
						} else {
							entry = { state: 'missing' };
						}
						cache[coord.xp] = entry;
					}

					if (entry.state === 'missing') {
						// Element no longer exists in the preview layout.
						// Strict matching ON (default): only clicks verified
						// against a live element are drawn -> skip the point.
						// OFF: absolute-coordinate fallback (legacy D4).
						if (this.strictAnchorMatching !== false) {
							missingCount++;
							continue;
						}
						projected.push(coord);
						continue;
					}

					if (entry.state === 'hidden') {
						// Element exists but is invisible right now (collapsed menu /
						// closed popup). Drawing it anywhere would be wrong — skip (D4).
						hiddenCount++;
						continue;
					}

					const rect = entry.rect;
					const px = rect.left + scrollX + (Math.min(1, Math.max(0, rx)) * rect.width);
					const py = rect.top + scrollY + (Math.min(1, Math.max(0, ry)) * rect.height);

					if (!isFinite(px) || !isFinite(py) || px < 0 || py < 0) {
						projected.push(coord);
						continue;
					}

					const copy = {};
					for (const key in coord) {
						if (Object.prototype.hasOwnProperty.call(coord, key)) {
							copy[key] = coord[key];
						}
					}
					copy.x = px / undoX;
					copy.y = py / undoY;
					projected.push(copy);
					reprojectedCount++;
				}

				if (!reprojectedCount && !hiddenCount && !missingCount && !dedupedCount) {
					return data;
				}

				this.debug('debug', 'Anchored click reprojection: ' + reprojectedCount + ' reprojected, ' + hiddenCount + ' hidden (skipped), ' + missingCount + ' missing (strict-skipped), ' + dedupedCount + ' duplicates (strict-deduped), ' + (projected.length - reprojectedCount) + ' absolute');

				const out = {};
				for (const key in data) {
					if (Object.prototype.hasOwnProperty.call(data, key)) {
						out[key] = data[key];
					}
				}
				out.coordinates = projected;
				return out;
			} catch (reprojectErr) {
				this.debug('warn', 'Anchored click reprojection failed; using absolute coordinates:', reprojectErr);
				return data;
			}
		}

		/**
		 * Toggle strict anchor matching (element-anchored clicks) and re-render.
		 *
		 * ON (default): anchored click points whose element no longer exists in
		 * the preview document are hidden (only verified position<->element
		 * matches are drawn). OFF: those points fall back to their absolute
		 * page coordinates (legacy D4 behaviour). Non-anchored (legacy) points
		 * are unaffected either way — they carry no element to verify.
		 */
		toggleStrictMatching() {
			this.strictAnchorMatching = (this.strictAnchorMatching === false);
			const $btn = $('#strict-matching-toggle');
			$btn.attr('aria-pressed', this.strictAnchorMatching !== false ? 'true' : 'false');
			$btn.toggleClass('strict-matching-off', this.strictAnchorMatching === false);
			this.debug('debug', 'Strict anchor matching: ' + (this.strictAnchorMatching !== false ? 'ON' : 'OFF'));
			if (this._lastAnchorRenderArgs) {
				const args = this._lastAnchorRenderArgs;
				this.createOverlayAndRenderHeatmap(args.data, args.viewport, args.loadId);
			}
		}

		/**
		 * Toggle interactive preview mode and sync the toolbar button.
		 *
		 * Delegates the heavy lifting (pointer-events, navigation guard, live
		 * re-render observers) to setInteractiveMode(); this method only flips
		 * the state and reflects it on the #interactive-preview-toggle button.
		 *
		 * @return {boolean} the resulting interactiveMode state.
		 */
		toggleInteractivePreview() {
			const enabled = this.setInteractiveMode(!this.interactiveMode);
			this._syncInteractivePreviewButton();
			return enabled;
		}

		/**
		 * Reflect the current interactiveMode state on the toolbar button
		 * (aria-pressed, active class, title). Safe no-op if the button or
		 * jQuery is unavailable.
		 */
		_syncInteractivePreviewButton() {
			if (typeof $ === 'undefined') {
				return;
			}
			const $btn = $('#interactive-preview-toggle');
			if (!$btn.length) {
				return;
			}
			const on = this.interactiveMode === true;
			const i18n = (this.config && this.config.i18n) ? this.config.i18n : {};
			$btn.attr('aria-pressed', on ? 'true' : 'false');
			$btn.toggleClass('interactive-preview-on', on);
			const title = on
				? (i18n.interactive_on || 'Interactive preview ON')
				: (i18n.interactive_hint || i18n.interactive_off || 'Interactive preview OFF');
			$btn.attr('title', title);
		}

		/**
		 * Auto-enable interactive preview once the settled iframe is ready.
		 *
		 * Interactive mode is ON by DEFAULT: the preview iframe is clickable the
		 * moment it loads so the admin can reveal hidden UI (accordions, menus,
		 * dropdowns) WITHOUT first toggling anything. Visibility gating then keeps
		 * click hotspots hidden while their anchor element is off screen and shows
		 * them (reprojected) as soon as the element is revealed.
		 *
		 * A full re-render (filter/refresh/variant switch) resets the mode to OFF
		 * in renderHeatmap() because the guard/observers were bound to the old
		 * iframe document; this re-arms interactive mode against the fresh, settled
		 * document. It only runs once per settle, so an explicit user opt-out via
		 * the toolbar still holds for the current document.
		 */
		_autoEnableInteractivePreview() {
			try {
				if (this.interactiveMode) {
					return;
				}
				this.setInteractiveMode(true);
				this._syncInteractivePreviewButton();
				this.debug('debug', 'Interactive preview auto-enabled (default ON)');
			} catch (e) {
				this.debug('warn', 'Auto-enable interactive preview failed:', e);
			}
		}

		/**
		 * Device-matched preview viewport width.
		 *
		 * The iframe ELEMENT width is the CSS viewport the embedded page lays out
		 * against, so responsive media queries fire off THIS width. To render the
		 * page the way the selected device filter saw it, size the iframe to a
		 * canonical CSS breakpoint width per device class:
		 *   mobile  -> 375px  (phone portrait)
		 *   tablet  -> 768px  (tablet portrait)
		 *   desktop / all / unknown -> the passed container/reference width.
		 *
		 * Recorded viewport widths are deliberately NOT used to size mobile/tablet:
		 * they are captured in physical/device pixels (DPR-scaled), so a phone
		 * routinely reports 700-2300px, which would wrongly trigger the DESKTOP
		 * layout. CSS breakpoint widths are the universal, theme-agnostic choice.
		 * "All devices" keeps the desktop container width (a sane single layout) —
		 * anchored clicks element-match onto whatever layout renders, and the
		 * visibility gate + anchorless scaling stay correct at any width.
		 *
		 * @param {number} fallbackWidth Desktop / all-devices width in px.
		 * @return {number} Target CSS viewport width for the preview iframe.
		 */
		_getDeviceViewportWidth(fallbackWidth) {
			const fallback = Math.max(parseInt(fallbackWidth, 10) || 0, 1);
			const device = (this.currentDevice || 'desktop').toString().toLowerCase();
			if (device === 'mobile') {
				return 375;
			}
			if (device === 'tablet') {
				return 768;
			}
			// desktop, all, unknown -> keep the full container/reference width.
			return fallback;
		}

		/**
		 * Create overlay and render heatmap
		 */
		createOverlayAndRenderHeatmap(data, viewport, loadId = this.activeLoadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}

			this.updatePendingState(this.config.i18n.rendering_heatmap || 'Rendering heatmap overlay...', loadId);

			// Clear the pending render flag since we're now rendering
			this.iframeRenderPending = false;

			// If we have pending data from a newer batch, use that instead
			if (this.pendingHeatmapData && this.pendingHeatmapData.coordinates &&
				this.pendingHeatmapData.coordinates.length > data.coordinates.length) {
				this.debug('debug', 'Using pending data with more coordinates: ' + this.pendingHeatmapData.coordinates.length + ' vs ' + data.coordinates.length);
				data = this.pendingHeatmapData;
				viewport = data.viewport || viewport;
			}
			this.pendingHeatmapData = null; // Clear pending data

			// Remember the latest render inputs so the strict-matching toggle can
			// re-run this render (and its anchored-click reprojection) in place.
			this._lastAnchorRenderArgs = { data: data, viewport: viewport, loadId: loadId };

			this.debug('debug', 'createOverlayAndRenderHeatmap called with:', {
				hasData: !!data,
				hasCoordinates: data && data.coordinates ? data.coordinates.length : 0,
				viewportWidth: viewport ? viewport.width : 0,
				viewportHeight: viewport ? viewport.height : 0
			});

			// Always use the parent container, not iframe document
			// We want the heatmap overlay to be on top of the iframe, not inside it
			const container = document.getElementById('heatmap-container');
			if (!container) {
				this.debug('error', 'Heatmap container not found');
				this.showError(this.config.i18n.error, loadId);
				return;
			}

			// Create heatmap overlay in parent document (not iframe)
			let heatmapOverlay = document.getElementById('heatmap-overlay');
			if (!heatmapOverlay) {
				heatmapOverlay = document.createElement('div');
				heatmapOverlay.id = 'heatmap-overlay';
				heatmapOverlay.style.position = 'absolute';
				heatmapOverlay.style.top = '0';
				heatmapOverlay.style.left = '0';
				heatmapOverlay.style.pointerEvents = 'none';
				heatmapOverlay.style.zIndex = '10';

				container.appendChild(heatmapOverlay);
				this.debug('debug', 'Created heatmap overlay in parent container');
			} else {
				// Clear existing overlay
				heatmapOverlay.innerHTML = '';
				this.debug('debug', 'Cleared existing heatmap overlay');
			}

			// Add scroll heatmap legend if this is a scroll heatmap
			if (this.currentType === 'scroll') {
				this.createScrollHeatmapLegend(container);
			}

			// Set explicit pixel dimensions on overlay BEFORE creating heatmap
			// This ensures h337.create() creates the canvas with correct dimensions

			// Use idealWidth/idealHeight for the overlay dimensions (actual page size, not scaled)
			// These are the REAL, uncapped page dimensions - kept as-is because several
			// coordinate-scaling calculations elsewhere in this file (coordScaleX/Y) are
			// defined relative to this "real" pixel space.
			let overlayWidth = this.idealWidth || viewport.width;
			let overlayHeight = this.idealHeight || viewport.height;

			// CANVAS SIZE LIMIT GUARD (any page height, any browser):
			// Browsers cap the maximum width/height (and sometimes total pixel area) of a
			// single 2D canvas. This varies by browser/engine/device and isn't exposed by
			// any API, so detect it at runtime by actually trying to draw at candidate
			// sizes (see getMaxSafeCanvasScale()/getMaxCanvasAxis() above). When the real
			// page size exceeds what THIS browser can safely allocate, render the overlay
			// into a smaller canvas and stretch it back to the correct on-screen size with
			// a CSS transform, so click/position accuracy is preserved for pages of any
			// height while never exceeding the detected safe limit.
			const canvasCap = getMaxSafeCanvasScale(overlayWidth, overlayHeight);
			this.canvasWidthScale = canvasCap.scaleX;
			this.canvasHeightScale = canvasCap.scaleY;

			if (canvasCap.scaleX < 1 || canvasCap.scaleY < 1) {
				this.debug('warn', 'Page size ' + overlayWidth + 'x' + overlayHeight + 'px exceeds this browser\'s safe canvas limit; ' +
					'downscaling overlay canvas by (' + canvasCap.scaleX.toFixed(4) + ', ' + canvasCap.scaleY.toFixed(4) + ') and compensating with CSS scale.', 'heatmap-detail');
			}

			overlayWidth = Math.max(1, Math.round(overlayWidth * this.canvasWidthScale));
			overlayHeight = Math.max(1, Math.round(overlayHeight * this.canvasHeightScale));

			heatmapOverlay.style.width = overlayWidth + 'px';
			heatmapOverlay.style.height = overlayHeight + 'px';
			heatmapOverlay.style.maxWidth = 'none';
			heatmapOverlay.style.maxHeight = 'none';
			heatmapOverlay.style.boxSizing = 'content-box';

			// Apply same scale transform as iframe if scaling is active, PLUS the inverse
			// of the canvas-size cap above so the (possibly smaller) overlay canvas is
			// stretched back to line up pixel-for-pixel with the iframe underneath.
			// When canvasWidthScale/canvasHeightScale are both 1 (the overwhelming common
			// case - page fits comfortably within the browser's canvas limit), this is
			// mathematically identical to the original `scale(fitScale)` transform, so
			// normal-height pages render exactly as before.
			const fitScale = this.canvasScaleFactor || 1;
			const overlayTransformX = fitScale / this.canvasWidthScale;
			const overlayTransformY = fitScale / this.canvasHeightScale;
			if (overlayTransformX !== 1 || overlayTransformY !== 1) {
				heatmapOverlay.style.transformOrigin = 'top left';
				heatmapOverlay.style.transform = 'scale(' + overlayTransformX + ', ' + overlayTransformY + ')';
			} else {
				heatmapOverlay.style.transform = 'none';
			}

			this.debug('debug', 'Set overlay dimensions: ' + overlayWidth + 'x' + overlayHeight + ', transform: ' + overlayTransformX.toFixed(3) + ',' + overlayTransformY.toFixed(3), 'heatmap-detail');

			// Check if heatmap.js library is loaded
			if (!window.h337) {
				this.debug('error', 'Heatmap.js library not loaded');
				this.handleOverlayRenderFailure('Heatmap library not loaded', loadId);
				return;
			}

			try {
				// Create heatmap instance with different settings for scroll heatmap
				this.debug('debug', 'Creating heatmap instance for type: ' + this.currentType);

				// If the canvas had to be downscaled to fit this browser's safe canvas
				// limit, shrink dot/blur radii by the same factor before the CSS
				// stretch-back (above) enlarges everything again. Net effect: dots look
				// the same visual size on screen whether the canvas was capped or not.
				const radiusScale = Math.min(this.canvasWidthScale, this.canvasHeightScale);
				const scaleRadius = function(r) {
					return Math.max(2, Math.round(r * radiusScale));
				};

				// Phase 2 scroll-perf: for click heatmaps, mount h337 into a
				// viewport-sized "window" div instead of the full-page overlay so
				// the canvas backing store stays small (few viewports, not the
				// whole page). Other types keep their existing full-size path.
				let heatmapMountEl = heatmapOverlay;
				if (this.currentType === 'click') {
					heatmapMountEl = this._createHeatmapWindow(heatmapOverlay, overlayWidth, overlayHeight);
				} else {
					this._hmWindowEl = null;
					this._hmWindowTop = 0;
					this._hmWindowHeight = 0;
					this._canvasSpacePoints = null;
				}

				let config = {
					container: heatmapMountEl,
					radius: scaleRadius(40),
					maxOpacity: 0.8,
					minOpacity: 0.05,
					blur: 0.85,
					gradient: {
						0.0: 'rgba(0, 0, 255, 0)',
						0.2: 'rgba(0, 0, 255, 1)',
						0.4: 'rgba(0, 255, 255, 1)',
						0.6: 'rgba(0, 255, 0, 1)',
						0.8: 'rgba(255, 255, 0, 1)',
						1.0: 'rgba(255, 0, 0, 1)'
					}
				};

				// Customize settings for scroll heatmap
				if (this.currentType === 'scroll') {
					config.radius = scaleRadius(60); // Balanced radius for full coverage without excessive horizontal bleeding
					config.blur = 0.5; // Lower blur to maintain horizontal uniformity while allowing vertical gradients
					config.maxOpacity = 0.75; // Good opacity for clear visibility
					config.minOpacity = 0.4; // Higher minimum to ensure all areas show color
					config.gradient = {
						0.0: 'rgba(173, 216, 230, 0.5)',  // Light blue (low scroll reach)
						0.1: 'rgba(135, 206, 250, 0.55)', // Sky blue
						0.2: 'rgba(100, 149, 237, 0.6)',  // Cornflower blue
						0.35: 'rgba(64, 224, 208, 0.65)', // Turquoise
						0.5: 'rgba(50, 205, 50, 0.7)',    // Lime green
						0.65: 'rgba(255, 255, 0, 0.75)',  // Yellow
						0.8: 'rgba(255, 140, 0, 0.8)',    // Dark orange
						0.9: 'rgba(255, 69, 0, 0.85)',    // Orange-red
						1.0: 'rgba(220, 20, 20, 0.9)'     // Hot intense red (high scroll reach)
					};
				} else if (this.currentType === 'move') {
					// Move heatmap - show continuous mouse trajectory paths
					config.radius = scaleRadius(8); // Small radius for thin, precise path lines
					config.blur = 0.75; // Moderate blur for smooth but visible lines
					config.maxOpacity = 0.7; // Good visibility
					config.minOpacity = 0.15; // Show all paths clearly
					config.gradient = {
						0.0: 'rgba(100, 200, 100, 0)',     // Transparent (no movement)
						0.15: 'rgba(120, 255, 120, 0.4)',  // Light green (light traffic)
						0.3: 'rgba(150, 255, 100, 0.5)',   // Yellow-green
						0.45: 'rgba(200, 255, 80, 0.6)',   // Yellow (medium traffic)
						0.6: 'rgba(255, 220, 0, 0.7)',     // Gold
						0.75: 'rgba(255, 180, 0, 0.8)',    // Orange (high traffic)
						0.9: 'rgba(255, 100, 0, 0.85)',    // Deep orange
						1.0: 'rgba(255, 50, 0, 0.9)'       // Red-orange (very high traffic)
					};
				}

				this.heatmapInstance = window.h337.create(config);

				// Prepare data with proper format
				let heatmapData;

				// Scale coordinates if we applied canvas scaling
				const scaleFactor = this.canvasScaleFactor || 1;
				const normalizedScaleY = this.normalizedScaleY || scaleFactor;

				// Calculate coordinate scale factors from reference dimensions to display dimensions
				// Coordinates from backend are in reference_width x reference_height space
				// Display canvas is in idealWidth x idealHeight space
				const refWidth = this.heatmapReferenceWidth || overlayWidth;
				const refHeight = this.heatmapReferenceHeight || overlayHeight;
				const displayWidth = this.idealWidth || overlayWidth;
				const displayHeight = this.idealHeight || overlayHeight;

				// Scale factors to convert from reference space to display space
				const coordScaleX = displayWidth / refWidth;
				const coordScaleY = displayHeight / refHeight;

				// Only apply X scaling if viewport widths differ significantly (>10%)
				const shouldScaleX = Math.abs(coordScaleX - 1) > 0.1;

				this.debug('debug', `Coordinate scaling for heatmap: ref(${refWidth}x${refHeight}) -> display(${displayWidth}x${displayHeight}), X scale: ${shouldScaleX ? coordScaleX.toFixed(3) : 'none'}, Y scale: none (absolute coords)`);

				// ELEMENT-ANCHORED CLICKS: reproject anchored click points onto the
				// current, settled preview layout. Non-anchored points and every
				// non-click heatmap type pass through completely untouched.
				// (Y passes 1: click Y is absolute, no ref->display scaling below.)
				data = this._reprojectAnchoredClicks(data, loadId, shouldScaleX ? coordScaleX : 1, 1);

				if (this.currentType === 'scroll') {
					// SCROLL HEATMAP: Draw uniform horizontal bands using Canvas
					// This creates homogeneous color bands that only vary vertically based on scroll depth
						this.debug('debug', 'Rendering scroll heatmap with ' + data.coordinates.length + ' scroll events');
					this.debug('debug', '[Before Render] overlayWidth: ' + overlayWidth + ', overlayHeight: ' + overlayHeight + ', normalizedScaleY: ' + normalizedScaleY);
					this.debug('debug', '[Before Render] idealWidth: ' + this.idealWidth + ', idealHeight: ' + this.idealHeight + ', viewport: ' + (this.viewport ? (this.viewport.width + 'x' + this.viewport.height) : 'N/A'));
					this.renderScrollHeatmap(data.coordinates, heatmapOverlay, overlayWidth, overlayHeight, normalizedScaleY);

					// Skip heatmap.js for scroll type - we use canvas bands instead
					this.heatmapInstance = null;
				} else if (this.currentType === 'attention') {
					// ATTENTION HEATMAP: Show where users spent the most time
					// Uses scroll coordinates to create time-based density zones
					// RED = High engagement, BLUE = Low engagement
					if (data.coordinates && data.coordinates.length > 0) {
							this.debug('debug', 'Rendering attention heatmap with ' + data.coordinates.length + ' scroll events');
						// Pass viewport height to correctly map scroll positions to viewing areas
						this.renderAttentionHeatmap(data.coordinates, heatmapOverlay, overlayWidth, overlayHeight, scaleFactor, viewport);
					} else {
						// No data for this filter combination: drop any stale
						// attention-time hover cache and hide the indicator.
						this._attentionBands = null;
						this._hideScrollDepthHoverLayer();
					}

					// Skip heatmap.js for attention type - we use custom attention canvas instead
					this.heatmapInstance = null;
				} else if (this.currentType === 'move' && this.allTrajectories && this.allTrajectories.length > 0) {

					// MOVE HEATMAP: Draw actual trajectory lines using Canvas


					this.renderMoveTrajectories(this.allTrajectories, heatmapOverlay, overlayWidth, overlayHeight, scaleFactor);

					this.heatmapInstance = null;

				} else if (this.currentType === 'move') {

					// Fallback: Show mouse movement as heatmap points if no trajectories
					// NO Y scaling relative to the real page - coordinates are absolute
					// page positions. They ARE additionally scaled by canvasWidthScale/
					// canvasHeightScale to land inside the (possibly downscaled) canvas.


					const scaledCoordinates = data.coordinates.map(coord => {
						return {
							// X: Scale for differing viewport widths, then for the canvas cap
							x: Math.round((coord.x || 0) * (shouldScaleX ? coordScaleX : 1) * this.canvasWidthScale),
							// Y: absolute page position, scaled only for the canvas cap
							y: Math.round((coord.y || 0) * this.canvasHeightScale),
							value: coord.value || 1
						};
					}).filter(coord => coord.x >= 0 && coord.x < overlayWidth && coord.y >= 0 && coord.y < overlayHeight);



					this.heatmapInstance = h337.create({

						container: heatmapOverlay,

						radius: scaleRadius(40),

						maxOpacity: 0.6,

						minOpacity: 0,

						blur: 0.75

					});



					this.heatmapInstance.setData({

						max: 100,

						data: scaledCoordinates

					});

				} else if (data.coordinates && data.coordinates.length > 0) {
					// For click heatmaps from simple tracker:
					// Coordinates are ABSOLUTE page coordinates (pageX/pageY) - NO Y scaling
					// relative to the real page. They ARE additionally scaled by
					// canvasWidthScale/canvasHeightScale to land inside the (possibly
					// downscaled, see canvas size limit guard above) canvas.
					// Only apply X scaling if viewport widths differ significantly

					// Check if we should apply X scaling (for different viewport widths)
					const shouldScaleX = Math.abs(coordScaleX - 1) > 0.1; // Only scale if >10% difference

					const scaledCoordinates = data.coordinates.map(coord => {
						return {
							// X: Scale for differing viewport widths, then for the canvas cap
							x: Math.round((coord.x || 0) * (shouldScaleX ? coordScaleX : 1) * this.canvasWidthScale),
							// Y: absolute page position, scaled only for the canvas cap
							y: Math.round((coord.y || 0) * this.canvasHeightScale),
							value: coord.value || 1
						};
					}).filter(coord => coord.x >= 0 && coord.x < overlayWidth && coord.y >= 0 && coord.y < overlayHeight);

					// Phase 2 scroll-perf: keep the FULL canvas-space point list so
					// window repaints (scroll-stop) can re-derive any window subset,
					// and keep a GLOBAL max so colors stay identical across windows.
					this._canvasSpacePoints = scaledCoordinates.slice();
					this._hmDataMax = Math.max(10, this.getMaxValue(scaledCoordinates));

					heatmapData = {
						max: this._hmDataMax,
						data: this._windowSubset(scaledCoordinates)
					};
					this.debug('debug', 'Click heatmap: Using ' + data.coordinates.length + ' absolute coordinates (X scale: ' + (shouldScaleX ? coordScaleX.toFixed(3) : 'none') + ', Y scale: none)');

					this.debug('debug', 'Setting heatmap data: ' + heatmapData.data.length + ' points (' + scaledCoordinates.length + ' total in canvas space)');
					this.heatmapInstance.setData(heatmapData);

					// Count Bar Display: capture hover over the preview so admins can
					// see "N clicks in this area" on click hotspots (gated by the
					// Settings → Data Collection "Count Bar Display" toggle; Pro only).
					this._ensureClickCountHoverLayer();
				} else {
					// No data - just show the page without heatmap overlay
					this.debug('info', 'No heatmap data to render - showing page only');
					this.heatmapInstance = null;
					this._hideClickCountHoverLayer();
				}

				// Track how many points we've rendered for incremental updates
				// (Not applicable for move trajectories which don't use heatmapData)
				if (heatmapData && heatmapData.data) {
					// Phase 2: when windowing is active heatmapData.data is only the
					// in-window subset, so index by the CONSUMED source count instead
					// (incremental updates index into allCoordinates).
					this.lastRenderedIndex = (this._hmWindowHeight > 0 && data.coordinates)
						? data.coordinates.length
						: heatmapData.data.length;
				}

				// Show container beneath the pending overlay until all phases complete.
				$('.heatmap-no-data').hide();
				$('#heatmap-container').show();

				this.debug('debug', 'Heatmap rendered successfully');

				// CRITICAL: Mark heatmap as rendered so incremental updates can happen
				this.heatmapAlreadyRendered = true;
				this.firstBatchRendered = true;
				this.markIframeComplete(loadId);
				this.markRenderComplete(loadId);
				this.finishPendingState(loadId);

				if (this.pendingNoDataNoticeLoadId === loadId && (!data.coordinates || data.coordinates.length === 0)) {
					this.pendingNoDataNoticeLoadId = null;
					this.showBatchComplete(0, true);
				}

				// Auto-scroll to center of heatmap
				this.scrollToCenter();
			} catch (error) {
				this.debug('error', 'Error creating heatmap:', error);
				this.handleOverlayRenderFailure('Failed to render heatmap: ' + error.message, loadId);
			}
		}

	/**
	 * Get adaptive trajectory color based on website background
	 * Analyzes background color and returns contrasting color for trajectories
	 *
	 * @returns {Object} Color object with r, g, b values
	 */
	getAdaptiveTrajectoryColor() {
		try {
			// Get the iframe content background color
			const iframe = document.getElementById('heatmap-iframe');
			if (!iframe || !iframe.contentDocument) {
				// Default to yellow if we can't access iframe
				return { r: 255, g: 255, b: 100 };
			}

			const iframeBody = iframe.contentDocument.body;
			const computedStyle = window.getComputedStyle(iframeBody);
			let bgColor = computedStyle.backgroundColor;

			// If body is transparent, check html element
			if (bgColor === 'rgba(0, 0, 0, 0)' || bgColor === 'transparent') {
				const htmlElement = iframe.contentDocument.documentElement;
				bgColor = window.getComputedStyle(htmlElement).backgroundColor;
			}

			// Parse RGB/RGBA color
			const rgbMatch = bgColor.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
			if (!rgbMatch) {
				// Default to yellow if parsing fails
				return { r: 255, g: 255, b: 100 };
			}

			const r = parseInt(rgbMatch[1]);
			const g = parseInt(rgbMatch[2]);
			const b = parseInt(rgbMatch[3]);

			// Calculate relative luminance (perceived brightness)
			// Formula: https://www.w3.org/TR/WCAG20/#relativeluminancedef
			const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

			this.debug('debug', `Background color: rgb(${r}, ${g}, ${b}), luminance: ${luminance.toFixed(2)}`);

			// Choose trajectory color based on background brightness
			if (luminance > 0.6) {
				// Light background -> use dark/saturated colors for visibility
				// Deep magenta/purple stands out well on light backgrounds
				return { r: 200, g: 0, b: 200 };
			} else if (luminance < 0.3) {
				// Dark background -> use bright/light colors
				// Bright yellow/lime for high visibility on dark backgrounds
				return { r: 255, g: 255, b: 100 };
			} else {
				// Medium background -> check color temperature
				// Use color that contrasts with background hue
				const isWarmBackground = r > b; // More red than blue = warm
				if (isWarmBackground) {
					// Warm background -> use cool color (cyan/turquoise)
					return { r: 0, g: 255, b: 255 };
				} else {
					// Cool background -> use warm color (orange/coral)
					return { r: 255, g: 150, b: 0 };
				}
			}
		} catch (error) {
			this.debug('error', 'Error detecting background color:', error);
			// Default to yellow on error
			return { r: 255, g: 255, b: 100 };
		}
	}

		/**
		 * Render mouse movement trajectories as connected lines
		 * Uses Canvas 2D API to draw actual paths showing mouse movement
		 *
		 * @param {Array} trajectories Array of trajectory paths, each containing {x, y} points
		 * @param {HTMLElement} overlay The overlay element to render into
		 * @param {number} width Canvas width
		 * @param {number} height Canvas height
		 * @param {number} scaleFactor Scale factor for coordinates
		 */
	renderMoveTrajectories(trajectories, overlay, width, height, scaleFactor) {
			// Clear any existing content in overlay
			overlay.innerHTML = '';

			// For move heatmaps from simple tracker/file storage:
			// Coordinates are ABSOLUTE page coordinates relative to the real page -
			// NO Y scaling for viewport differences. They ARE additionally scaled by
			// canvasWidthScale/canvasHeightScale (see canvas size limit guard in
			// createOverlayAndRenderHeatmap) to land inside `width`x`height`, which may
			// be a downscaled canvas on pages taller than this browser's safe limit.
			// Only apply X scaling if viewport widths differ significantly
			const refWidth = this.heatmapReferenceWidth || width;
			const displayWidth = this.idealWidth || width;
			const canvasWidthScale = this.canvasWidthScale || 1;
			const canvasHeightScale = this.canvasHeightScale || 1;

			// Check if we should apply X scaling (for different viewport widths)
			const coordScaleX = displayWidth / refWidth;
			const shouldScaleX = Math.abs(coordScaleX - 1) > 0.1; // Only scale if >10% difference
			const radiusScale = Math.min(canvasWidthScale, canvasHeightScale);

			this.debug('debug', `Move heatmap: ref width ${refWidth} -> display width ${displayWidth}, X scale: ${shouldScaleX ? coordScaleX.toFixed(3) : 'none'}, canvas cap scale: ${canvasWidthScale.toFixed(3)},${canvasHeightScale.toFixed(3)}`);

			// STEP 1: Render dwell-time heatmap layer FIRST (underneath trajectories)
			// This shows where mouse stayed longest - red = most time, blue = less time
			if (this.allDwellSpots && this.allDwellSpots.length > 0) {
				const scaledDwellSpots = this.allDwellSpots.map(spot => {
					return {
						x: Math.round((spot.x || 0) * (shouldScaleX ? coordScaleX : 1) * canvasWidthScale),
						y: Math.round((spot.y || 0) * canvasHeightScale),
						value: spot.value || 1
					};
				}).filter(spot => spot.x >= 0 && spot.x < width && spot.y >= 0 && spot.y < height);

				// Create heatmap instance with blue-to-red gradient for dwell time
				const heatmapConfig = {
					container: overlay,
					radius: Math.max(2, Math.round(40 * radiusScale)),  // Slightly larger radius for dwell spots
					maxOpacity: 0.7,
					minOpacity: 0,
					blur: 0.85,
					gradient: {
						0.0: 'rgba(0, 0, 255, 0)',      // Transparent (no dwell time)
						0.2: 'rgba(0, 0, 255, 0.4)',    // Blue (low dwell time)
						0.4: 'rgba(0, 255, 255, 0.5)',  // Cyan
						0.6: 'rgba(0, 255, 0, 0.6)',    // Green
						0.8: 'rgba(255, 255, 0, 0.7)',  // Yellow
						1.0: 'rgba(255, 0, 0, 0.85)'    // Red (high dwell time)
					}
				};

				this.heatmapInstance = window.h337.create(heatmapConfig);
				this.heatmapInstance.setData({
					max: 100,
					data: scaledDwellSpots
				});

				this.debug('debug', 'Move heatmap dwell-time layer rendered with ' + scaledDwellSpots.length + ' spots');
			}

			// STEP 2: Create trajectory canvas layer ON TOP of heatmap
			// This shows mouse movement paths in yellow/light thin lines
			const canvas = document.createElement('canvas');
			canvas.id = 'trajectory-canvas';
			canvas.width = width;
			canvas.height = height;
			canvas.style.position = 'absolute';
			canvas.style.top = '0';
			canvas.style.left = '0';
			canvas.style.pointerEvents = 'none';
			canvas.style.zIndex = '10'; // Ensure trajectories appear above heatmap

			overlay.appendChild(canvas);

			const ctx = canvas.getContext('2d');

			// Count total points for intensity calculation
			let totalPoints = 0;
			trajectories.forEach(traj => {
				totalPoints += traj.length;
			});

		// Get adaptive color based on background
		const trajectoryColor = this.getAdaptiveTrajectoryColor();

		// Opacity varies based on number of trajectories to prevent over-saturation
		const baseOpacity = Math.min(0.6, Math.max(0.2, 40 / trajectories.length));

		this.debug('debug', `Drawing ${trajectories.length} trajectories with adaptive color rgb(${trajectoryColor.r}, ${trajectoryColor.g}, ${trajectoryColor.b}), opacity: ${baseOpacity.toFixed(2)}`);

	// Draw each trajectory with adaptive color
	// Coordinates are absolute page positions, scaled only for the canvas cap
	trajectories.forEach((trajectory, index) => {
		if (trajectory.length < 2) return;

				ctx.beginPath();

				// Use adaptive color that contrasts with background
				ctx.strokeStyle = `rgba(${trajectoryColor.r}, ${trajectoryColor.g}, ${trajectoryColor.b}, ${baseOpacity})`;
				ctx.lineWidth = 2; // Slightly thicker for better visibility
				ctx.lineCap = 'round';
				ctx.lineJoin = 'round';

				// Move to first point - X scaling for viewport diff + canvas cap
				const firstPoint = trajectory[0];
				const startX = Math.round((firstPoint.x || 0) * (shouldScaleX ? coordScaleX : 1) * canvasWidthScale);
				const startY = Math.round((firstPoint.y || 0) * canvasHeightScale);
				ctx.moveTo(startX, startY);

				// Draw line to each subsequent point
				for (let i = 1; i < trajectory.length; i++) {
					const point = trajectory[i];
					const x = Math.round((point.x || 0) * (shouldScaleX ? coordScaleX : 1) * canvasWidthScale);
					const y = Math.round((point.y || 0) * canvasHeightScale);
					ctx.lineTo(x, y);
				}

				ctx.stroke();
			});

			// Store reference to canvas for later updates
			this.trajectoryCanvas = canvas;

		this.debug('debug', 'Move trajectories rendered successfully with adaptive colors and heatmap density layer');
		}

		/**
		 * Render scroll heatmap with uniform horizontal bands using canvas
		 * This creates perfectly homogeneous horizontal color bands based on scroll depth
		 * Unlike radial gradients, this produces consistent colors across the X-axis
		 */
		renderScrollHeatmap(scrollCoordinates, overlay, width, height, scaleFactor) {
			// For scroll heatmaps:
			// Coordinates are absolute page positions - NO Y scaling needed!
			// The reference_height from backend should match page height, so scaling would be ~1 anyway

			// Debug: Log scroll heatmap dimensions
			const refHeight = this.heatmapReferenceHeight || height;
			const displayHeight = this.idealHeight || height;
			this.debug('debug', '[Scroll Heatmap] Rendering with dimensions: ' + width + 'x' + height);
			this.debug('debug', '[Scroll Heatmap] refHeight: ' + refHeight + ', displayHeight: ' + displayHeight + ' (NO Y scaling applied)');
			this.debug('debug', '[Scroll Heatmap] idealHeight: ' + this.idealHeight + ', viewport height: ' + (this.viewport ? this.viewport.height : 'N/A'));

			// Check max scroll Y in coordinates
			if (scrollCoordinates && scrollCoordinates.length > 0) {
				const maxScrollY = Math.max(...scrollCoordinates.map(c => c.y || 0));
				const minScrollY = Math.min(...scrollCoordinates.map(c => c.y || 0));
				this.debug('debug', '[Scroll Heatmap] Scroll Y range in data: ' + minScrollY + ' to ' + maxScrollY + ', canvas height: ' + height);
			}

			// Create canvas element for scroll heatmap rendering
			const canvas = document.createElement('canvas');
			canvas.id = 'scroll-heatmap-canvas';
			canvas.width = width;
			canvas.height = height;
			canvas.style.position = 'absolute';
			canvas.style.top = '0';
			canvas.style.left = '0';
			canvas.style.width = width + 'px';
			canvas.style.height = height + 'px';
			canvas.style.maxWidth = 'none';
			canvas.style.maxHeight = 'none';
			canvas.style.boxSizing = 'content-box';
			canvas.style.pointerEvents = 'none';

			// Clear any existing content in overlay
			overlay.innerHTML = '';
			overlay.appendChild(canvas);

			const ctx = canvas.getContext('2d');

			// Helper function to interpolate between colors smoothly
			const interpolateColor = (normalizedIntensity) => {
				// RED = High % of sessions reached this depth (top of page)
				// BLUE = Low % of sessions reached this depth (deep down)
				// normalizedIntensity = value/100 (reach %)

				let r, g, b, a;

				if (normalizedIntensity < 0.2) {
					// Light blue to sky blue - very low engagement
					const t = normalizedIntensity / 0.2;
					r = 173 + (135 - 173) * t;
					g = 216 + (206 - 216) * t;
					b = 230 + (250 - 230) * t;
					a = 0.40 + (0.45 - 0.40) * t;
				} else if (normalizedIntensity < 0.4) {
					// Sky blue to turquoise - low engagement
					const t = (normalizedIntensity - 0.2) / 0.2;
					r = 135 + (64 - 135) * t;
					g = 206 + (224 - 206) * t;
					b = 250 + (208 - 250) * t;
					a = 0.45 + (0.50 - 0.45) * t;
				} else if (normalizedIntensity < 0.6) {
					// Turquoise to lime green - moderate engagement
					const t = (normalizedIntensity - 0.4) / 0.2;
					r = 64 + (50 - 64) * t;
					g = 224 + (205 - 224) * t;
					b = 208 + (50 - 208) * t;
					a = 0.50 + (0.58 - 0.50) * t;
				} else if (normalizedIntensity < 0.75) {
					// Lime green to yellow - good engagement
					const t = (normalizedIntensity - 0.6) / 0.15;
					r = 50 + (255 - 50) * t;
					g = 205 + (255 - 255) * t;
					b = 50 + (0 - 50) * t;
					a = 0.58 + (0.65 - 0.58) * t;
				} else if (normalizedIntensity < 0.9) {
					// Yellow to orange - high engagement
					const t = (normalizedIntensity - 0.75) / 0.15;
					r = 255;
					g = 255 + (165 - 255) * t;
					b = 0;
					a = 0.65 + (0.70 - 0.65) * t;
				} else {
					// Orange to red - maximum engagement/time spent
					const t = (normalizedIntensity - 0.9) / 0.1;
					r = 255;
					g = 165 + (80 - 165) * t;
					b = 0 + (0 - 0) * t;
					a = 0.70 + (0.75 - 0.70) * t;
				}

				return `rgba(${Math.round(r)}, ${Math.round(g)}, ${Math.round(b)}, ${a})`;
			};

			// SCROLL REACH mode: backend coordinates are { y: <page px ascending>,
			// value: <cumulative % of sessions reaching this depth, 0-100> }.
			// Paint full-width bands straight from the reach value (no density,
			// no maxIntensity normalize, no nearest-neighbour, no exp fade).
			const isReach = (this.heatmapScrollMetric === 'reach');

			if (isReach && scrollCoordinates && scrollCoordinates.length > 0) {
				// Keep an UNSCALED (reference/page px) copy of the reach bands for
				// the scroll-depth hover indicator. Stored separately from the
				// canvas-space 'bands' below so the indicator lookup stays correct
				// regardless of canvas-size-cap / fit-scale state.
				this._scrollDepthBands = scrollCoordinates
					.map(coord => ({
						y: coord.y || 0,
						value: Math.max(0, Math.min(100, coord.value || 0))
					}))
					.sort((a, b) => a.y - b.y);

				// Hover events over the preview land inside its IFRAME document,
				// so a capture layer above the iframe is required for the
				// indicator to receive mousemove at all.
				this._ensureScrollDepthHoverLayer();

				// Map band Y from reference (page) space to canvas space.
				const refHeightLocal = this.heatmapReferenceHeight || height;
				const scaleY = refHeightLocal > 0 ? (height / refHeightLocal) : 1;

				const bands = scrollCoordinates
					.map(coord => ({
						cy: (coord.y || 0) * scaleY,
						value: Math.max(0, Math.min(100, coord.value || 0))
					}))
					.sort((a, b) => a.cy - b.cy);

				// Linear interpolation of reach % at a given canvas Y.
				const valueAt = (cy) => {
					if (cy <= bands[0].cy) return bands[0].value;
					const last = bands[bands.length - 1];
					if (cy >= last.cy) return last.value;
					for (let i = 1; i < bands.length; i++) {
						if (cy <= bands[i].cy) {
							const a = bands[i - 1];
							const b = bands[i];
							const span = (b.cy - a.cy) || 1;
							const t = (cy - a.cy) / span;
							return a.value + (b.value - a.value) * t;
						}
					}
					return last.value;
				};

				const sliceHeight = 4; // thin slices -> smooth gradient
				for (let y = 0; y < height; y += sliceHeight) {
					const actualSliceHeight = Math.min(sliceHeight, height - y);
					const reachPct = valueAt(y + actualSliceHeight / 2);
					// reach % (0-100) maps directly: 100% -> hot/red at top, 0% -> cool/blue.
					const color = interpolateColor(Math.max(0, Math.min(1, reachPct / 100)));
					ctx.fillStyle = color;
					ctx.fillRect(0, y, width, actualSliceHeight);
				}

				this.debug('debug', 'Scroll reach heatmap rendered (' + bands.length + ' bands, top=hot/high-reach)');
				return;
			}

			// LEGACY fallback (old/absent scroll_metric): keep prior density rendering
			// so cached responses or unexpected payloads still display something.
			// Legacy density data has no well-defined "% reached" — keep the
			// scroll-depth hover indicator inactive for it.
			this._scrollDepthBands = null;
			this._hideScrollDepthHoverLayer();
			const scrollDepthMap = new Map();
			scrollCoordinates.forEach(coord => {
				const y = coord.y || 0; // NO scaling - absolute coords
				const value = coord.value || 1;

				if (!scrollDepthMap.has(y)) {
					scrollDepthMap.set(y, { count: 0, totalValue: 0 });
				}

				const entry = scrollDepthMap.get(y);
				entry.count++;
				entry.totalValue += value;
			});

			if (scrollDepthMap.size === 0) {
				this.debug('debug', 'Scroll heatmap: no coordinates to render');
				return;
			}

			const maxScrollY = Math.max(...Array.from(scrollDepthMap.keys()));
			let maxIntensity = 0;
			scrollDepthMap.forEach((data) => {
				const avgValue = data.totalValue / data.count;
				if (avgValue > maxIntensity) maxIntensity = avgValue;
			});

			const sliceHeight = 10; // Smaller slices for smoother gradient
			for (let y = 0; y < height; y += sliceHeight) {
				const actualSliceHeight = Math.min(sliceHeight, height - y);

				// Calculate intensity for this Y position
				let intensity = 0;

				if (y <= maxScrollY) {
					// Within scrolled area: find nearest scroll data
					let nearestValue = 0;
					let minDistance = Infinity;

					scrollDepthMap.forEach((data, scrollY) => {
						const distance = Math.abs(scrollY - y);
						if (distance < minDistance) {
							minDistance = distance;
							nearestValue = data.totalValue / data.count;
						}
					});

					intensity = nearestValue;

					// Gradual decay as we get further from actual scroll data
					if (minDistance > 500) {
						intensity = intensity * (1 - (minDistance - 500) / maxScrollY);
					}
				} else {
					// Beyond max scroll: create gradient that fades out
					const beyondRatio = (y - maxScrollY) / (height - maxScrollY);
					intensity = maxIntensity * Math.exp(-beyondRatio * 3);
				}

				// Normalize intensity to 0-1 range
				const normalizedIntensity = Math.max(0, Math.min(1, intensity / (maxIntensity || 1)));

				// Get smoothly interpolated color for this intensity
				const color = interpolateColor(normalizedIntensity);

				// Draw horizontal slice with uniform color across entire width
				ctx.fillStyle = color;
				ctx.fillRect(0, y, width, actualSliceHeight);
			}

			this.debug('debug', 'Scroll heatmap rendered with uniform horizontal bands (legacy density)');
		}

		/**
		 * Render attention heatmap showing where users spent the most time
		 * Uses time-based density to create colored heat zones
		 * RED = Users spent most time here (high engagement)
		 * BLUE = Users spent least time here (low engagement)
		 */
		renderAttentionHeatmap(scrollCoordinates, overlay, width, height, scaleFactor, viewport) {
			// ATTENTION HEATMAP: Uses scroll data to show where users spent time viewing
			// Creates full-width horizontal colored bands based on scroll dwell time
			// Uses Gaussian distribution to create smooth gradients

			// Reset the attention-time hover cache; repopulated below once the
			// density map is built (stale bands from a previous filter render
			// must not leak into this one).
			this._attentionBands = null;

			// For attention heatmaps:
			// Coordinates are absolute page positions relative to the real page - NO Y
			// scaling for viewport differences. They ARE additionally scaled by
			// canvasHeightScale (see canvas size limit guard in
			// createOverlayAndRenderHeatmap) to land inside `height`, which may be a
			// downscaled canvas on pages taller than this browser's safe canvas limit.
			const refHeight = this.heatmapReferenceHeight || height;
			const displayHeight = this.idealHeight || height;
			const canvasHeightScale = this.canvasHeightScale || 1;
			this.debug('debug', 'Attention heatmap: refHeight: ' + refHeight + ', displayHeight: ' + displayHeight + ', canvas cap scale: ' + canvasHeightScale.toFixed(3));

			// Create canvas element for attention heatmap rendering
			const canvas = document.createElement('canvas');
			canvas.id = 'attention-heatmap-canvas';
			canvas.width = width;
			canvas.height = height;
			canvas.style.position = 'absolute';
			canvas.style.top = '0';
			canvas.style.left = '0';
			canvas.style.width = width + 'px';
			canvas.style.height = height + 'px';
			canvas.style.maxWidth = 'none';
			canvas.style.maxHeight = 'none';
			canvas.style.boxSizing = 'content-box';
			canvas.style.pointerEvents = 'none';

			// Clear any existing content in overlay
			overlay.innerHTML = '';
			overlay.appendChild(canvas);

			// Create floating legend for attention heatmap (append to document body, not overlay/iframe)
			const legend = this.createAttentionLegend();
			// Append to document body so it floats outside the iframe like scroll legend
			document.body.appendChild(legend);

			const ctx = canvas.getContext('2d');

			// STEP 1: Fill entire page with base cold blue color (never seen areas)
			// This ensures all areas of the page are visible, even if not viewed
			ctx.fillStyle = 'rgba(173, 216, 230, 0.25)'; // Light cold blue with low opacity
			ctx.fillRect(0, 0, width, height);

			// Get actual browser window height for attention heatmap rendering
			// Priority: 1) wh from individual coordinates, 2) fallback to 900px for old data
			// NOTE: viewport.height contains the full PAGE height (scrollHeight), NOT the browser window height
			let attentionViewportHeight = 0;
			const whValues = scrollCoordinates.filter(c => c.wh && c.wh > 0).map(c => c.wh);
			if (whValues.length > 0) {
				attentionViewportHeight = Math.round(whValues.reduce((a, b) => a + b, 0) / whValues.length);
			}
			if (!attentionViewportHeight) {
				attentionViewportHeight = 900; // Reasonable default for old data without wh field
			}
			// Scale the (real-page-space) window height into canvas space so the
			// Gaussian window size stays proportionally correct on a downscaled canvas.
			const viewportHeight = attentionViewportHeight * canvasHeightScale;

			this.debug('debug', 'Attention heatmap: Using browser window height ' + viewportHeight + 'px (from ' + whValues.length + ' wh values)');

			// STEP 2: Create vertical density map (scroll positions are Y coordinates)
			// Each row represents a horizontal band across the full page width
			const bandHeight = 2; // Smaller bands = smoother gradients
			const numBands = Math.ceil(height / bandHeight);
			const densityMap = new Array(numBands).fill(0);

			// Populate density map from scroll coordinates
			// Apply Gaussian distribution for smooth blending
			scrollCoordinates.forEach(coord => {
				// coord.y is the BOTTOM of the visible viewport (scrollTop + innerHeight)
				// The viewing region extends from (y - windowHeight) to y
				// Scaled into canvas space (see canvas size limit guard, above)
				const scrollY = (coord.y || 0) * canvasHeightScale;
				const value = coord.value || 1;

				// Calculate the viewing region - NO scaling needed
				const viewingStartY = Math.max(0, scrollY - viewportHeight);
				const viewingEndY = scrollY;
				const viewingCenterY = (viewingStartY + viewingEndY) / 2;

				// Sigma for smooth gradients within the viewport
				// Using viewport/2.5 gives smooth transitions while keeping most weight in viewport
				const viewportSigma = viewportHeight / 2.5;

				// Apply heat primarily within the viewport with small extension for smoothness
				const startBand = Math.floor(viewingStartY / bandHeight);
				const endBand = Math.ceil(viewingEndY / bandHeight);

				// Small extension (20% of viewport) for smooth blending at edges
				const extension = Math.ceil(viewportHeight * 0.2 / bandHeight);
				const extendedStart = Math.max(0, startBand - extension);
				const extendedEnd = Math.min(numBands - 1, endBand + extension);

				for (let band = extendedStart; band <= extendedEnd; band++) {
					const bandY = band * bandHeight;

					// Distance from center of viewing region
					const distance = Math.abs(bandY - viewingCenterY);

					// Gaussian weight - smooth falloff from center
					const gaussianWeight = Math.exp(-(distance * distance) / (2 * viewportSigma * viewportSigma));

					densityMap[band] += value * gaussianWeight;
				}
			});

			// Find max density for normalization
			const maxDensity = Math.max(...densityMap, 1);

			// Cache the density bands (canvas space + the canvas cap scale) with
			// prefix sums for the attention-time hover tooltip: the share of
			// total attention inside a viewport-height window around the cursor
			// is then an O(1) lookup per mousemove.
			{
				let totalDensity = 0;
				const prefix = new Array(densityMap.length + 1);
				prefix[0] = 0;
				for (let i = 0; i < densityMap.length; i++) {
					totalDensity += densityMap[i];
					prefix[i + 1] = totalDensity;
				}
				if (totalDensity > 0) {
					this._attentionBands = {
						density: densityMap,
						prefix: prefix,
						bandHeight: bandHeight,
						scaleY: canvasHeightScale || 1,
						viewportH: attentionViewportHeight,
						total: totalDensity
					};
					// Hover events over the preview land inside its IFRAME
					// document, so the transparent capture layer (shared with
					// the scroll-depth indicator) is required for mousemove.
					this._ensureScrollDepthHoverLayer();
				}
			}

			// Helper function to get color based on attention density
			const getAttentionColor = (normalizedDensity) => {
				// Color scale: Blue (low) -> Green -> Yellow -> Orange -> Red (high)
				let r, g, b, a;

				if (normalizedDensity < 0.15) {
					// Very low attention - light blue
					const t = normalizedDensity / 0.15;
					r = 173 + (135 - 173) * t;
					g = 216 + (206 - 216) * t;
					b = 230 + (250 - 230) * t;
					a = 0.25 + (0.30 - 0.25) * t;
				} else if (normalizedDensity < 0.35) {
					// Low attention - blue to turquoise
					const t = (normalizedDensity - 0.15) / 0.2;
					r = 135 + (64 - 135) * t;
					g = 206 + (224 - 206) * t;
					b = 250 + (208 - 250) * t;
					a = 0.30 + (0.40 - 0.30) * t;
				} else if (normalizedDensity < 0.55) {
					// Moderate attention - turquoise to green
					const t = (normalizedDensity - 0.35) / 0.2;
					r = 64 + (50 - 64) * t;
					g = 224 + (205 - 224) * t;
					b = 208 + (50 - 208) * t;
					a = 0.40 + (0.50 - 0.40) * t;
				} else if (normalizedDensity < 0.70) {
					// Good attention - green to yellow
					const t = (normalizedDensity - 0.55) / 0.15;
					r = 50 + (255 - 50) * t;
					g = 205 + (255 - 205) * t;
					b = 50 + (0 - 50) * t;
					a = 0.50 + (0.60 - 0.50) * t;
				} else if (normalizedDensity < 0.85) {
					// High attention - yellow to orange
					const t = (normalizedDensity - 0.70) / 0.15;
					r = 255;
					g = 255 + (165 - 255) * t;
					b = 0;
					a = 0.60 + (0.70 - 0.60) * t;
				} else {
					// Maximum attention - orange to red
					const t = (normalizedDensity - 0.85) / 0.15;
					r = 255;
					g = 165 + (80 - 165) * t;
					b = 0;
					a = 0.70 + (0.80 - 0.70) * t;
				}

				return `rgba(${Math.round(r)}, ${Math.round(g)}, ${Math.round(b)}, ${a})`;
			};

			// Draw full-width horizontal bands for attention heatmap
			for (let band = 0; band < numBands; band++) {
				const density = densityMap[band];
				if (density === 0) continue; // Skip empty bands

				const normalizedDensity = density / maxDensity;
				const color = getAttentionColor(normalizedDensity);
				const y = band * bandHeight;

				ctx.fillStyle = color;
				ctx.fillRect(0, y, width, bandHeight);
			}

			this.debug('debug', 'Attention heatmap rendered with ' + scrollCoordinates.length + ' scroll events across full page width');
		}

		/**
		 * Create floating attention heatmap legend as DOM element
		 * Displays vertical gradient from High (red) to Low (blue)
		 * Positioned fixed in top-right corner, stays visible when scrolling
		 */
		createAttentionLegend() {
			const legend = document.createElement('div');
			legend.className = 'attention-heatmap-legend';
			legend.style.cssText = `
				position: fixed;
				top: 50%;
				right: 30px;
				transform: translateY(-50%);
				width: 35px;
				background: rgba(255, 255, 255, 0.95);
				border: 1px solid rgba(0, 0, 0, 0.2);
				border-radius: 6px;
				padding: 6px 4px;
				box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
				z-index: 1000;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
			`;

			// High label
			const highLabel = document.createElement('div');
			highLabel.textContent = (this.config.i18n && this.config.i18n.high) ? this.config.i18n.high : 'High';
			highLabel.style.cssText = `
				text-align: center;
				font-size: 9px;
				font-weight: bold;
				color: #333;
				margin-bottom: 4px;
			`;
			legend.appendChild(highLabel);

			// Gradient container
			const gradientContainer = document.createElement('div');
			gradientContainer.style.cssText = `
				width: 100%;
				height: 120px;
				border: 1px solid rgba(0, 0, 0, 0.3);
				border-radius: 3px;
				background: linear-gradient(to bottom,
					rgba(255, 80, 0, 0.8) 0%,
					rgba(255, 165, 0, 0.7) 15%,
					rgba(255, 255, 0, 0.6) 30%,
					rgba(50, 205, 50, 0.5) 45%,
					rgba(64, 224, 208, 0.4) 65%,
					rgba(135, 206, 250, 0.3) 85%,
					rgba(173, 216, 230, 0.25) 100%
				);
			`;
			legend.appendChild(gradientContainer);

			// Low label
			const lowLabel = document.createElement('div');
			lowLabel.textContent = (this.config.i18n && this.config.i18n.low) ? this.config.i18n.low : 'Low';
			lowLabel.style.cssText = `
				text-align: center;
				font-size: 9px;
				font-weight: bold;
				color: #333;
				margin-top: 4px;
			`;
			legend.appendChild(lowLabel);

			// Attention label
			const attentionLabel = document.createElement('div');
			attentionLabel.textContent = (this.config.i18n && this.config.i18n.attention) ? this.config.i18n.attention : 'Attention';
			attentionLabel.style.cssText = `
				text-align: center;
				font-size: 8px;
				color: #666;
				margin-top: 6px;
				padding-top: 6px;
				border-top: 1px solid rgba(0, 0, 0, 0.1);
			`;
			legend.appendChild(attentionLabel);

			return legend;
		}


		/**
		 * Update trajectory canvas with new data (for incremental batch updates)
		 * Re-renders all trajectories to include newly loaded data
		 */
		updateTrajectoryCanvas() {
			if (!this.trajectoryCanvas || !this.allTrajectories || this.allTrajectories.length === 0) {
				return;
			}

			const canvas = this.trajectoryCanvas;
			const ctx = canvas.getContext('2d');

			// Clear canvas
			ctx.clearRect(0, 0, canvas.width, canvas.height);

			// For move heatmaps: NO Y scaling - coordinates are absolute page positions
			// Only apply X scaling if viewport widths differ significantly
			const refWidth = this.heatmapReferenceWidth || canvas.width;
			const displayWidth = this.idealWidth || canvas.width;
			const coordScaleX = displayWidth / refWidth;
			const shouldScaleX = Math.abs(coordScaleX - 1) > 0.1; // Only scale if >10% difference

			// Calculate opacity based on number of trajectories
			const baseOpacity = Math.min(0.6, Math.max(0.15, 50 / this.allTrajectories.length));

			this.debug('debug', 'Updating trajectory canvas with ' + this.allTrajectories.length + ' trajectories, X scale: ' + (shouldScaleX ? coordScaleX.toFixed(3) : 'none') + ', Y scale: none');

			// Draw each trajectory - NO Y scaling (absolute coords)
			this.allTrajectories.forEach((trajectory, index) => {
				if (trajectory.length < 2) return;

				ctx.beginPath();
				const hue = (index * 137.5) % 360;
				ctx.strokeStyle = `hsla(${hue}, 70%, 50%, ${baseOpacity})`;
				ctx.lineWidth = 2;
				ctx.lineCap = 'round';
				ctx.lineJoin = 'round';

				const firstPoint = trajectory[0];
				const startX = Math.round((firstPoint.x || 0) * (shouldScaleX ? coordScaleX : 1));
				const startY = firstPoint.y || 0; // NO Y scaling
				ctx.moveTo(startX, startY);

				for (let i = 1; i < trajectory.length; i++) {
					const point = trajectory[i];
					const x = Math.round((point.x || 0) * (shouldScaleX ? coordScaleX : 1));
					const y = point.y || 0; // NO Y scaling
					ctx.lineTo(x, y);
				}

				ctx.stroke();
			});

			// Add glow effect - NO Y scaling
			ctx.globalCompositeOperation = 'lighter';
			this.allTrajectories.forEach((trajectory, index) => {
				if (trajectory.length < 2) return;

				ctx.beginPath();
				const hue = (index * 137.5) % 360;
				ctx.strokeStyle = `hsla(${hue}, 70%, 60%, ${baseOpacity * 0.3})`;
				ctx.lineWidth = 6;

				const firstPoint = trajectory[0];
				const startX = Math.round((firstPoint.x || 0) * (shouldScaleX ? coordScaleX : 1));
				const startY = firstPoint.y || 0; // NO Y scaling
				ctx.moveTo(startX, startY);

				for (let i = 1; i < trajectory.length; i++) {
					const point = trajectory[i];
					const x = Math.round((point.x || 0) * (shouldScaleX ? coordScaleX : 1));
					const y = point.y || 0; // NO Y scaling
					ctx.lineTo(x, y);
				}

				ctx.stroke();
			});

			ctx.globalCompositeOperation = 'source-over';
		}

		/**
		 * Get maximum value from coordinates
		 */
		getMaxValue(coordinates) {
			if (!coordinates || coordinates.length === 0) {
				return 1;
			}

			return Math.max(...coordinates.map(c => c.value || 1));
		}

		/**
		 * Generate scroll heatmap data that covers the full page height
		 * Creates horizontal bands across the entire canvas based on scroll depth
		 */
		generateScrollHeatmapData(scrollCoordinates, canvasWidth, canvasHeight, scaleFactor = 1) {
			if (!scrollCoordinates || scrollCoordinates.length === 0) {
				return [];
			}

			// Group scroll events by Y position to calculate scroll depth/intensity
			// Apply scale factor to coordinates
			const scrollDepthMap = new Map();

			scrollCoordinates.forEach(coord => {
				const y = Math.round((coord.y || 0) * scaleFactor);
				const value = coord.value || 1;

				if (!scrollDepthMap.has(y)) {
					scrollDepthMap.set(y, { count: 0, totalValue: 0 });
				}

				const entry = scrollDepthMap.get(y);
				entry.count++;
				entry.totalValue += value;
			});

			// Find the maximum scroll depth (highest Y coordinate users reached) - already scaled
			const maxScrollY = Math.max(...Array.from(scrollDepthMap.keys()));

			// Generate data points for FULL canvas height
			const enhancedData = [];
			const bandHeight = 50; // Create a band every 50px
			const pointsPerBand = 100; // Dense point distribution for seamless horizontal coverage

			// Create horizontal bands from top (0) to full canvas height
			for (let y = 0; y < canvasHeight; y += bandHeight) {
				// Calculate the intensity value for this Y position
				// Areas above maxScrollY get decreasing values (fewer users scrolled there)
				let intensity;

				if (y <= maxScrollY) {
					// Within scrolled area: check if we have actual data for this Y
					let nearestValue = 0;
					let minDistance = Infinity;

					// Find the nearest scroll data point
					scrollDepthMap.forEach((data, scrollY) => {
						const distance = Math.abs(scrollY - y);
						if (distance < minDistance) {
							minDistance = distance;
							nearestValue = data.totalValue / data.count;
						}
					});

					// Use interpolated value with fallback
					intensity = nearestValue > 0 ? nearestValue : 10;

					// Gradual decay as we get further from actual scroll data
					if (minDistance > 500) {
						intensity = intensity * (1 - (minDistance - 500) / maxScrollY);
					}
				} else {
					// Beyond max scroll: create gradient that fades out
					const beyondRatio = (y - maxScrollY) / (canvasHeight - maxScrollY);
					// Exponential decay for smooth gradient to bottom
					intensity = 10 * Math.exp(-beyondRatio * 3);
				}

				// Ensure minimum visibility
				intensity = Math.max(intensity, 1);

				// Create points across the width for this horizontal band
				for (let i = 0; i < pointsPerBand; i++) {
					const x = (canvasWidth / (pointsPerBand - 1)) * i;
					enhancedData.push({
						x: Math.round(x),
						y: y,
						value: Math.round(intensity)
					});
				}
			}

			return enhancedData;
		}

		/**
		 * Regenerate scroll heatmap with new height
		 * Called after iframe loads and we know the full page height
		 * IMPORTANT: Must recreate the heatmap instance because heatmap.js doesn't resize canvas
		 */
		regenerateScrollHeatmap(newOverlayHeight) {
			if (!this.allCoordinates || this.allCoordinates.length === 0) {
				return;
			}

			this.debug('debug', 'Regenerating scroll heatmap with full page height: ' + newOverlayHeight);

			const overlay = document.getElementById('heatmap-overlay');
			if (!overlay) return;

			const overlayWidth = parseInt(overlay.style.width) || 1138;
			const scaleFactor = this.canvasScaleFactor || 1;

			// CRITICAL FIX: Canvas has a maximum size limit in browsers
			// Cap the canvas height and scale coordinates accordingly
			const MAX_CANVAS_HEIGHT = 16000;
			let actualHeight = newOverlayHeight;
			let canvasHeightScale = 1;

			if (newOverlayHeight > MAX_CANVAS_HEIGHT) {
				this.debug('debug', 'Canvas height ' + newOverlayHeight + 'px exceeds maximum ' + MAX_CANVAS_HEIGHT + 'px, scaling down');
				canvasHeightScale = MAX_CANVAS_HEIGHT / newOverlayHeight;
				actualHeight = MAX_CANVAS_HEIGHT;
				this.canvasHeightScale = canvasHeightScale;
				this.originalOverlayHeight = newOverlayHeight;
			} else {
				this.canvasHeightScale = 1;
				this.originalOverlayHeight = newOverlayHeight;
			}

			// Clear the overlay
			overlay.innerHTML = '';
			overlay.style.height = Math.round(actualHeight) + 'px';

			// Destroy old instance (we're not using heatmap.js for scroll anymore)
			this.heatmapInstance = null;

			// Render scroll heatmap using custom canvas renderer
			// Apply the canvas height scale to coordinates
			const combinedScale = scaleFactor * canvasHeightScale;
			this.renderScrollHeatmap(
				this.allCoordinates,
				overlay,
				overlayWidth,
				actualHeight,
				combinedScale
			);

			this.debug('debug', 'Scroll heatmap regenerated with ' + this.allCoordinates.length + ' scroll events, canvas height: ' + actualHeight + (canvasHeightScale < 1 ? ' (scaled from ' + newOverlayHeight + ')' : ''), 'heatmap-detail');
		}

		/**
		 * Regenerate click heatmap with new dimensions
		 * Called after iframe loads and we know the full page height
		 */
		regenerateClickHeatmap(newOverlayHeight, newOverlayWidth) {
			if (!this.allCoordinates || this.allCoordinates.length === 0) {
				return;
			}

			this.debug('debug', 'Regenerating click heatmap with dimensions: ' + newOverlayWidth + ' x ' + newOverlayHeight);

			const overlay = document.getElementById('heatmap-overlay');
			if (!overlay) return;

			// CRITICAL FIX: Canvas has a maximum size limit in browsers
			// Cap the canvas height and scale coordinates accordingly
			const MAX_CANVAS_HEIGHT = 16000;
			let actualHeight = newOverlayHeight;
			let canvasHeightScale = 1;

			if (newOverlayHeight > MAX_CANVAS_HEIGHT) {
				this.debug('debug', 'Canvas height ' + newOverlayHeight + 'px exceeds maximum ' + MAX_CANVAS_HEIGHT + 'px, scaling down');
				canvasHeightScale = MAX_CANVAS_HEIGHT / newOverlayHeight;
				actualHeight = MAX_CANVAS_HEIGHT;
				this.canvasHeightScale = canvasHeightScale;
				this.originalOverlayHeight = newOverlayHeight;
			} else {
				this.canvasHeightScale = 1;
				this.originalOverlayHeight = newOverlayHeight;
			}

			// CRITICAL: Clear the overlay and recreate the heatmap instance
			overlay.innerHTML = '';
			overlay.style.width = Math.round(newOverlayWidth) + 'px';
			overlay.style.height = Math.round(actualHeight) + 'px';

			// CRITICAL FIX: Force browser reflow BEFORE creating heatmap instance
			// heatmap.js reads container.offsetWidth/offsetHeight at creation time
			const _forceReflow = overlay.offsetHeight;
			this.debug('debug', 'Forced reflow, overlay offsetHeight now: ' + _forceReflow);

			// Destroy old instance
			this.heatmapInstance = null;

			// Create new heatmap instance with correct dimensions
			const config = {
				container: overlay,
				radius: 40,
				maxOpacity: 0.8,
				minOpacity: 0.05,
				blur: 0.85,
				gradient: {
					0.0: 'rgba(0, 0, 255, 0)',
					0.2: 'rgba(0, 0, 255, 1)',
					0.4: 'rgba(0, 255, 255, 1)',
					0.6: 'rgba(0, 255, 0, 1)',
					0.8: 'rgba(255, 255, 0, 1)',
					1.0: 'rgba(255, 0, 0, 1)'
				}
			};

			this.heatmapInstance = window.h337.create(config);

			// Verify the canvas was created with correct dimensions
			const canvas = overlay.querySelector('canvas');
			if (canvas) {
				this.debug('debug', 'Heatmap canvas created with dimensions: ' + canvas.width + ' x ' + canvas.height);
				// If canvas height is still wrong, manually resize it (but respect the max)
				const targetHeight = Math.min(actualHeight, MAX_CANVAS_HEIGHT);
				if (canvas.height < targetHeight * 0.9) {
					this.debug('debug', 'Canvas height mismatch! Manually resizing canvas to: ' + newOverlayWidth + ' x ' + Math.round(targetHeight), 'heatmap-detail');
					canvas.width = Math.round(newOverlayWidth);
					canvas.height = Math.round(targetHeight);
				}
			}

			// Scale coordinates - apply both iframe scale factor and canvas height scale
			const scaleFactor = this.canvasScaleFactor || 1;
			const offsetX = this.contentOffsetX || 0;
			const yScale = scaleFactor * canvasHeightScale;

			const scaledCoordinates = this.allCoordinates.map(coord => ({
				x: Math.round((coord.x - offsetX) * scaleFactor),
				y: Math.round(coord.y * yScale),
				value: coord.value || 1
			})).filter(coord =>
				coord.x >= 0 && coord.x < newOverlayWidth &&
				coord.y >= 0 && coord.y < actualHeight
			);

			const heatmapData = {
				max: Math.max(10, this.getMaxValue(scaledCoordinates)),
				data: scaledCoordinates
			};

			this.heatmapInstance.setData(heatmapData);
			this.debug('debug', 'Click heatmap regenerated with ' + scaledCoordinates.length + ' points' + (canvasHeightScale < 1 ? ' (height scaled from ' + newOverlayHeight + ')' : ''), 'heatmap-detail');
		}

		/**
		 * Regenerate move heatmap (trajectory canvas) with new dimensions
		 * Called after iframe loads and we know the full page height
		 */
		regenerateMoveHeatmap(newOverlayHeight, newOverlayWidth) {
			const overlay = document.getElementById('heatmap-overlay');
			if (!overlay) return;

			// CRITICAL FIX: Canvas has a maximum size limit in browsers
			// Cap the canvas height and scale coordinates accordingly
			const MAX_CANVAS_HEIGHT = 16000;
			let actualHeight = newOverlayHeight;
			let canvasHeightScale = 1;

			if (newOverlayHeight > MAX_CANVAS_HEIGHT) {
				this.debug('debug', 'Canvas height ' + newOverlayHeight + 'px exceeds maximum ' + MAX_CANVAS_HEIGHT + 'px, scaling down');
				canvasHeightScale = MAX_CANVAS_HEIGHT / newOverlayHeight;
				actualHeight = MAX_CANVAS_HEIGHT;
				this.canvasHeightScale = canvasHeightScale;
				this.originalOverlayHeight = newOverlayHeight;
			} else {
				this.canvasHeightScale = 1;
				this.originalOverlayHeight = newOverlayHeight;
			}

			// Update overlay dimensions
			overlay.style.width = Math.round(newOverlayWidth) + 'px';
			overlay.style.height = Math.round(actualHeight) + 'px';

			// CRITICAL FIX: Force browser reflow BEFORE creating canvas/heatmap instance
			const _forceReflow = overlay.offsetHeight;
			this.debug('debug', 'Forced reflow, overlay offsetHeight now: ' + _forceReflow);

			// Check if we have trajectories to render
			if (this.allTrajectories && this.allTrajectories.length > 0) {
				this.debug('debug', 'Regenerating move heatmap with dimensions: ' + newOverlayWidth + ' x ' + actualHeight + (canvasHeightScale < 1 ? ' (scaled from ' + newOverlayHeight + ')' : ''), 'heatmap-detail');

				// Clear overlay and re-render trajectories with new dimensions
				overlay.innerHTML = '';
				const scaleFactor = this.canvasScaleFactor || 1;
				// Apply both scale factors for trajectory rendering
				this.renderMoveTrajectories(this.allTrajectories, overlay, newOverlayWidth, actualHeight, 1);
			} else if (this.allCoordinates && this.allCoordinates.length > 0) {
				// Fallback: render as heat points if no trajectories
				this.debug('debug', 'Regenerating move heatmap (fallback) with ' + this.allCoordinates.length + ' points');

				overlay.innerHTML = '';

				const config = {
					container: overlay,
					radius: 8,
					blur: 0.75,
					maxOpacity: 0.7,
					minOpacity: 0.15,
					gradient: {
						0.0: 'rgba(100, 200, 100, 0)',
						0.15: 'rgba(120, 255, 120, 0.4)',
						0.3: 'rgba(150, 255, 100, 0.5)',
						0.45: 'rgba(200, 255, 80, 0.6)',
						0.6: 'rgba(255, 220, 0, 0.7)',
						0.75: 'rgba(255, 180, 0, 0.8)',
						0.9: 'rgba(255, 100, 0, 0.85)',
						1.0: 'rgba(255, 50, 0, 0.9)'
					}
				};

				this.heatmapInstance = window.h337.create(config);

				// Verify the canvas was created with correct dimensions
				const canvas = overlay.querySelector('canvas');
				if (canvas) {
						this.debug('debug', 'Heatmap canvas created with dimensions: ' + canvas.width + ' x ' + canvas.height);
					// If canvas height is still wrong, manually resize it (but respect the max)
					const targetHeight = Math.min(actualHeight, MAX_CANVAS_HEIGHT);
					if (canvas.height < targetHeight * 0.9) {
							this.debug('debug', 'Canvas height mismatch! Manually resizing canvas to: ' + newOverlayWidth + ' x ' + Math.round(targetHeight), 'heatmap-detail');
						canvas.width = Math.round(newOverlayWidth);
						canvas.height = Math.round(targetHeight);
					}
				}

				const scaleFactor = this.canvasScaleFactor || 1;
				const offsetX = this.contentOffsetX || 0;
				const yScale = scaleFactor * canvasHeightScale;

				const scaledCoordinates = this.allCoordinates.map(coord => ({
					x: Math.round((coord.x - offsetX) * scaleFactor),
					y: Math.round(coord.y * yScale),
					value: coord.value || 1
				})).filter(coord =>
					coord.x >= 0 && coord.x < newOverlayWidth &&
					coord.y >= 0 && coord.y < actualHeight
				);

				const heatmapData = {
					max: Math.max(10, this.getMaxValue(scaledCoordinates)),
					data: scaledCoordinates
				};

				this.heatmapInstance.setData(heatmapData);
			}
		}

		/**
		 * Load statistics
		 */
		async loadStats() {
			$('.stats-loading').show();
			$('#top-elements-list').empty();

			try {
				const response = await $.ajax({
					url: this.config.ajax_url,
					type: 'POST',
					data: this.addDatePayload({
						action: 'opti_behavior_get_heatmap_stats',
						nonce: this.config.nonce,
						page_id: this.pageId,
						page_ids: this.pageIdsParam,
						device: this.currentDevice,
						type: this.currentType,
						date_range: this.filters.date_range,
						country: this.filters.country,
						browser: this.filters.browser,
						visitor_type: this.filters.visitor_type,
						ab_test_id: this.abTestId,
						variant_id: this.variantId
					})
				});

				if (response.success && response.data) {
					this.renderStats(response.data);
				} else {
					// Show coming soon message when no data
					this.showComingSoonMessage();
				}
			} catch (error) {
				this.debug('error', 'Failed to load stats:', error);
				// Show coming soon message on error
				this.showComingSoonMessage();
			} finally {
				$('.stats-loading').hide();
			}
		}

		/**
		 * Show the Top Clicked Elements empty state.
		 *
		 * Click heatmaps: element data is only collected from new visits, so
		 * show a "collecting" message. Other heatmap types: point the user to
		 * the click heatmap.
		 */
		showComingSoonMessage() {
			const $list = $('#top-elements-list');
			const i18n = this.config.i18n || {};
			const isClick = this.currentType === 'click';
			const title = isClick
				? (i18n.top_elements_empty || 'No element data yet')
				: (i18n.top_elements_wrong_type || 'Available on click heatmaps');
			const hint = isClick
				? (i18n.top_elements_empty_hint || 'Element details are collected from new visits — check back soon.')
				: (i18n.top_elements_wrong_type_hint || 'Switch to the Click heatmap type to see the top clicked elements.');
			const icon = isClick
				? '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="top-elements-empty-icon"><path d="M14 4.1 12 6"/><path d="m5.1 8-2.9-.8"/><path d="m6 12-1.9 2"/><path d="M7.2 2.2 8 5.1"/><path d="M9.037 9.69a.498.498 0 0 1 .653-.653l11 4.5a.5.5 0 0 1-.074.949l-4.349 1.041a1 1 0 0 0-.74.739l-1.04 4.35a.5.5 0 0 1-.95.074z"/></svg>'
				: '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="top-elements-empty-icon"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
			$list.html(`
				<div class="top-elements-empty">
					${icon}
					<p class="top-elements-empty-title">${this.escapeHtml(title)}</p>
					<p class="top-elements-empty-hint">${this.escapeHtml(hint)}</p>
				</div>
			`);
		}

		/**
		 * Render statistics
		 */
		renderStats(stats) {
			// Update quick stats bar
			$("#stat-views").text(stats.total_views || 0);

			// Show/hide appropriate stats based on heatmap type
			// Attention heatmap uses scroll data, so show scroll stats for both scroll and attention types
			if (this.currentType === 'scroll' || this.currentType === 'attention') {
				$('.stat-clicks-item').hide();
				$('.stat-scroll-item').show();
				$('.stat-move-item').hide();
				$('#stat-scrolls').text(stats.total_scrolls || 0);
			} else if (this.currentType === 'move') {
				$('.stat-clicks-item').hide();
				$('.stat-scroll-item').hide();
				$('.stat-move-item').show();
				$('#stat-moves').text(stats.total_moves || 0);
			} else {
				$('.stat-clicks-item').show();
				$('.stat-scroll-item').hide();
				$('.stat-move-item').hide();
				$('#stat-clicks').text(stats.total_clicks || 0);
			}

			$('#stat-avg-time').text(this.formatTime(stats.avg_time || 0));

			// Cache the average session time (seconds) for the attention-time
			// hover tooltip ("Avg time spent" = avg_time x attention share).
			this._lastAvgTime = parseFloat(stats.avg_time) || 0;

			// Render top elements list
			this.lastTopElements = (stats.top_elements && stats.top_elements.length > 0) ? stats.top_elements : null;
			if (stats.top_elements_locked) {
				this.lastTopElements = null;
				this.showTopElementsLocked();
			} else if (this.lastTopElements) {
				this.renderTopElements(this.lastTopElements);
			} else {
				this.showComingSoonMessage();
			}
		}

		/**
		 * Show the Pro-locked state for Top Clicked Elements.
		 *
		 * Rendered when the server returns top_elements_locked (free tier /
		 * no valid Pro license). Purely cosmetic — the element stats are
		 * gated server-side and never reach the browser.
		 */
		showTopElementsLocked() {
			const $list = $('#top-elements-list');
			const i18n = this.config.i18n || {};
			const title = i18n.top_elements_locked || 'Top Clicked Elements is a Pro feature';
			const hint = i18n.top_elements_locked_hint || 'Upgrade to Opti-Behavior Pro to see which elements your visitors click the most.';
			const cta = i18n.upgrade_cta || 'Upgrade to Pro';
			const url = this.config.upgrade_url || 'https://optiuser.com/';
			const icon = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="top-elements-empty-icon"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
			$list.html(`
				<div class="top-elements-empty top-elements-locked">
					${icon}
					<p class="top-elements-empty-title">${this.escapeHtml(title)}</p>
					<p class="top-elements-empty-hint">${this.escapeHtml(hint)}</p>
					<a class="button button-primary top-elements-upgrade-btn" href="${this.escapeHtml(url)}">${this.escapeHtml(cta)}</a>
				</div>
			`);
		}

		/**
		 * Map an element type slug to its icon + translated label.
		 */
		getElementTypeMeta(type) {
			const slug = String(type || 'element');
			const icons = {
				'button': 'mouse-pointer-click',
				'menu': 'menu',
				'link': 'link',
				'search': 'search',
				'login': 'log-in',
				'comment': 'message-square',
				'captcha': 'shield-check',
				'popup': 'layers',
				'pagination': 'more-horizontal',
				'breadcrumb': 'chevrons-right',
				'image': 'image',
				'video': 'video',
				'form-field': 'text-cursor-input',
				'checkbox': 'check-square',
				'radio': 'circle-dot',
				'dropdown': 'chevron-down',
				'accordion': 'list',
				'tab': 'folder',
				'slider': 'sliders-horizontal',
				'list': 'list',
				'heading': 'heading',
				'text': 'type',
				'section': 'layout-grid',
				'element': 'mouse-pointer'
			};
			const i18nTypes = (this.config.i18n && this.config.i18n.element_types) || {};
			const fallbackLabel = slug.charAt(0).toUpperCase() + slug.slice(1).replace(/-/g, ' ');
			return {
				icon: icons[slug] || 'mouse-pointer',
				label: i18nTypes[slug] || fallbackLabel
			};
		}

		/**
		 * URL context line for a top-element row (link href / image src).
		 * Renders as a clickable link only for safe http(s)/relative URLs when
		 * `clickable` is true; otherwise plain text. Executable URL schemes
		 * are never rendered (defence in depth — the tracker already drops them).
		 *
		 * @param {string}  href      Stored element_href value.
		 * @param {boolean} clickable Render as <a> when the URL is safe.
		 * @return {string} HTML string ('' when no usable href).
		 */
		buildElementHrefHtml(href, clickable) {
			const raw = String(href || '').trim();
			if (!raw || /^(javascript|data|vbscript):/i.test(raw)) {
				return '';
			}
			const text = this.escapeHtml(raw);
			if (clickable && /^(https?:\/\/|\/(?!\/)|\.\/|#|\?)/i.test(raw)) {
				return `<a class="element-href" href="${text}" target="_blank" rel="noopener noreferrer" title="${text}">&rarr;&nbsp;${text}</a>`;
			}
			return `<span class="element-href" title="${text}">&rarr;&nbsp;${text}</span>`;
		}

		/**
		 * Render top elements list (Simple + Detailed views).
		 */
		renderTopElements(elements) {
			const list = $('#top-elements-list');
			list.empty();

			this.lastTopElements = elements;
			const view = this.statsView === 'detailed' ? 'detailed' : 'simple';
			const i18n = this.config.i18n || {};
			const clicksLabel = i18n.clicks_label || 'clicks';
			const ofClicksLabel = i18n.of_clicks || 'of clicks';
			const maxClicks = Math.max.apply(null, elements.map((el) => Number(el.clicks) || 0).concat([1]));

			elements.forEach((el, index) => {
				const meta = this.getElementTypeMeta(el.type);
				const clicks = Number(el.clicks) || 0;
				const percentage = Number(el.percentage) || 0;
				const label = el.label || el.selector || (i18n.unknown || 'Unknown');
				const item = $(`<div class="element-item ${view === 'detailed' ? 'is-detailed' : 'is-simple'}"></div>`);

				if (view === 'simple') {
					item.html(`
						<div class="element-rank">${index + 1}</div>
						<div class="element-type-icon" title="${this.escapeHtml(meta.label)}">
							<i data-lucide="${this.escapeHtml(meta.icon)}"></i>
						</div>
						<div class="element-details">
							<div class="element-label">${this.escapeHtml(label)}</div>
							<div class="element-stats">
								<span class="element-type-label">${this.escapeHtml(meta.label)}</span>
								<span class="element-stats-sep">&middot;</span>
								${clicks} ${this.escapeHtml(clicksLabel)} (${percentage}%)
								${this.buildElementHrefHtml(el.href, false)}
							</div>
						</div>
						<div class="element-clicks-badge">${clicks}</div>
					`);
				} else {
					const barWidth = Math.max(2, Math.round((clicks / maxClicks) * 100));
					const tagChip = el.tag ? `<span class="element-tag-chip">${this.escapeHtml(el.tag)}</span>` : '';
					const builderBadge = el.builder ? `<span class="element-builder-badge">${this.escapeHtml(el.builder)}</span>` : '';
					item.html(`
						<div class="element-rank">${index + 1}</div>
						<div class="element-type-icon" title="${this.escapeHtml(meta.label)}">
							<i data-lucide="${this.escapeHtml(meta.icon)}"></i>
						</div>
						<div class="element-details">
							<div class="element-label">
								${this.escapeHtml(label)}
								${tagChip}
								${builderBadge}
							</div>
							<div class="element-selector">${this.escapeHtml(el.selector || (i18n.unknown || 'Unknown'))}</div>
							${this.buildElementHrefHtml(el.href, true)}
							<div class="element-progress">
								<div class="element-progress-bar" style="width: ${barWidth}%"></div>
							</div>
							<div class="element-stats">
								<span class="element-type-label">${this.escapeHtml(meta.label)}</span>
								<span class="element-stats-sep">&middot;</span>
								${clicks} ${this.escapeHtml(clicksLabel)}
								<span class="element-stats-sep">&middot;</span>
								${percentage}% ${this.escapeHtml(ofClicksLabel)}
							</div>
						</div>
					`);
				}

				list.append(item);
			});

			if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
				lucide.createIcons();
			}
		}

		/**
		 * Switch A/B test variant filter
		 * @param {string} value - Format "testId:variantId" or empty for all variants
		 */
		switchVariant(value) {
			if (value && value.includes(':')) {
				const parts = value.split(':');
				this.abTestId = parseInt(parts[0], 10) || 0;
				this.variantId = parseInt(parts[1], 10) || 0;
			} else {
				this.abTestId = 0;
				this.variantId = 0;
			}

			// Update visual state of select
			const $select = $('#filter-ab-variant');
			if (this.variantId) {
				$select.addClass('has-value');
			} else {
				$select.removeClass('has-value');
			}

			// Update URL with variant params
			const url = new URL(window.location.href);
			if (this.abTestId > 0 && this.variantId > 0) {
				url.searchParams.set('ab_test_id', this.abTestId);
				url.searchParams.set('variant_id', this.variantId);
			} else {
				url.searchParams.delete('ab_test_id');
				url.searchParams.delete('variant_id');
			}
			window.history.pushState({}, '', url.toString());

			// Reload heatmap (which rebuilds iframe with variant preview) and stats
			this.loadHeatmap();
			this.loadStats();
			// Session counts and filter-option counts are variant-scoped
			// server-side: reload them too so the header pill, device badges and
			// dropdown "(N)" counts all reflect the newly selected variant scope.
			this.loadTotalRecordingsCount();
			this.loadDeviceCounts();
			this.loadFilterOptions();
		}

		/**
		 * Switch device
		 */
		switchDevice(device) {
			this.currentDevice = device;

			// Update UI
			$('.device-btn').removeClass('active');
			$(`.device-btn[data-device="${device}"]`).addClass('active');

			// Update URL
			this.updateUrl();

			// Clear current filter selections since they may not be valid for the new device
			this.filters.country = '';
			this.filters.browser = '';

			// Reset multi-select dropdowns UI
			$('.multiselect-dropdown').each((index, dropdown) => {
				const $dropdown = $(dropdown);
				$dropdown.find('input[type="checkbox"]').prop('checked', false);
				$dropdown.removeClass('has-selection');
				this.updateMultiSelectLabel($dropdown);
			});

			// Reload filter options for the new device
			this.loadFilterOptions();

			// Header total is device-scoped, refresh it for the new device
			this.loadTotalRecordingsCount();

			// Reload data
			this.loadHeatmap();
			this.loadStats();
		}

		/**
		 * Switch heatmap type
		 */
		switchType(type) {
			// Remove scroll heatmap legend when switching away from scroll type
			if (this.currentType === 'scroll' && type !== 'scroll') {
				const existingLegend = document.querySelector('.scroll-heatmap-legend');
				if (existingLegend) {
					existingLegend.remove();
					this.debug('debug', 'Removed scroll heatmap legend on type switch');
				}
				// Hide the scroll-depth hover indicator + capture layer and drop
				// the reach-band cache so no stale hover state (or pointer
				// capture) leaks into other heatmap types.
				this._hideScrollDepthHoverLayer();
				this._scrollDepthBands = null;
			}

			// Hide the click-count hover layer/tooltip when leaving the Click tab
			// so its pointer capture doesn't leak into other heatmap types.
			if (this.currentType === 'click' && type !== 'click') {
				this._hideClickCountHoverLayer();
			}

			// Remove attention heatmap legend when switching away from attention type
			if (this.currentType === 'attention' && type !== 'attention') {
				const existingAttentionLegend = document.querySelector('.attention-heatmap-legend');
				if (existingAttentionLegend) {
					existingAttentionLegend.remove();
					this.debug('debug', 'Removed attention heatmap legend on type switch');
				}
				// Hide the attention-time hover indicator + capture layer and
				// drop the density-band cache so no stale hover state (or
				// pointer capture) leaks into other heatmap types.
				this._hideScrollDepthHoverLayer();
				this._attentionBands = null;
			}

			this.currentType = type;

			// Update UI
			$('.heatmap-tab').removeClass('active');
			$(`.heatmap-tab[data-type="${type}"]`).addClass('active');

			// Update container data attribute
			$('#heatmap-container').attr('data-type', type);

			// Update stat items visibility
			this.updateStatItemsVisibility();

			// Update URL
			this.updateUrl();

			// Header total and device badges are type-scoped, refresh them
			this.loadTotalRecordingsCount();
			this.loadDeviceCounts();

			// Reload heatmap and stats
			this.loadHeatmap();
			this.loadStats();
		}

		/**
		 * Refresh heatmap (clear cache and reload)
		 */
		async refreshHeatmap() {
			const $btn = $('#refresh-heatmap');
			$btn.prop('disabled', true);
			if (this.isLoading) {
				this.cancelActiveLoad('refresh');
			}
			const refreshLoadId = this.beginPendingState('refresh', this.config.i18n.refreshing_cache || 'Refreshing heatmap cache...');

			let request = null;
			try {
				request = $.ajax({
					url: this.config.ajax_url,
					type: 'POST',
					data: {
						action: 'opti_behavior_refresh_heatmap_cache',
						nonce: this.config.nonce,
						page_id: this.pageId,
						page_ids: this.pageIdsParam
					}
				});
				this.trackXhr(refreshLoadId, request);
				await request;
				this.untrackXhr(refreshLoadId, request);

				// Reload data
				await this.loadHeatmap();
				await this.loadStats();
			} catch (error) {
				this.untrackXhr(refreshLoadId, request);
				if (this.isStaleLoad(refreshLoadId) || (error && error.statusText === 'abort')) {
					return;
				}
				this.debug('error', 'Failed to refresh:', error);
				this.showError(this.config.i18n.error, refreshLoadId);
			} finally {
				$btn.prop('disabled', false);
			}
		}

		/**
		 * Update browser URL without reload
		 */
		updateUrl() {
			const url = new URL(window.location.href);
			url.searchParams.set('device', this.currentDevice);
			url.searchParams.set('type', this.currentType);
			window.history.pushState({}, '', url.toString());
		}

		/**
		 * Show batch progress indicator on the rendered heatmap
		 * This shows which batch just completed while more are loading
		 * @param {number} currentBatch - Current batch number
		 * @param {number} totalBatches - Total number of batches
		 * @param {number} totalPoints - Total points loaded so far
		 * @param {string} statusMessage - Optional status message (e.g., "Paused", "Resuming...")
		 */
		showBatchProgress(currentBatch, totalBatches, totalPoints, statusMessage = null) {
			// Remove any existing progress indicator
			$('#batch-progress-indicator').remove();

			// Determine the text to display
			let progressText;
			if (statusMessage) {
				progressText = (this.config.i18n.points_loaded_status || '%1$s - %2$s points loaded')
					.replace('%1$s', statusMessage)
					.replace('%2$s', totalPoints.toLocaleString());
			} else {
				progressText = (this.config.i18n.batch_loaded || 'Batch %1$s/%2$s loaded (%3$s points)')
					.replace('%1$s', currentBatch)
					.replace('%2$s', totalBatches)
					.replace('%3$s', totalPoints.toLocaleString());
			}
			if (this.pendingOverlayVisible) {
				this.updatePendingState(progressText, this.activeLoadId);
				if (!$('.heatmap-loading .opti-heatmap-stop-loading-btn').length) {
					$('.heatmap-loading').append(
						'<button type="button" class="opti-heatmap-stop-loading-btn" style="margin-top:10px;">' + (this.config.i18n.stop_loading || 'Stop Loading') + '</button>'
					);
				}
				$('.opti-heatmap-stop-loading-btn').off('click').on('click', () => {
					this.stopLoading();
				});
			}

			// Determine spinner animation based on status
			const isPaused = statusMessage && (statusMessage.includes('Paused') || statusMessage.includes('hidden'));
			const spinnerStyle = isPaused
				? 'animation: none; border-top-color: #ffc107;' // Yellow, no spin when paused
				: 'animation: batch-spin 1s linear infinite;';

			// Create a small progress indicator that overlays on the heatmap with Stop button
			const progressHtml = `
				<div id="batch-progress-indicator" style="
					position: absolute;
					top: 10px;
					left: 50%;
					transform: translateX(-50%);
					background: rgba(0, 0, 0, 0.85);
					color: #fff;
					padding: 8px 12px;
					border-radius: 6px;
					font-size: 12px;
					z-index: 100;
					display: flex;
					align-items: center;
					gap: 10px;
				">
					<span class="batch-spinner" style="
						width: 14px;
						height: 14px;
						border: 2px solid rgba(255,255,255,0.3);
						border-top-color: #fff;
						border-radius: 50%;
						${spinnerStyle}
					"></span>
					<span class="batch-progress-text">${progressText}</span>
					<button id="stop-loading-btn" style="
						background: #dc3545;
						color: #fff;
						border: none;
						padding: 4px 10px;
						border-radius: 3px;
						cursor: pointer;
						font-size: 9px;
						font-weight: 500;
						display: flex;
						align-items: center;
						gap: 4px;
						transition: background 0.2s;
					" title="${this.config.i18n.stop_loading_more || 'Stop loading more data'}">
						<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
							<rect x="6" y="6" width="12" height="12" rx="1"/>
						</svg>
						${this.config.i18n.stop || 'Stop'}
					</button>
				</div>
				<style>
					@keyframes batch-spin {
						to { transform: rotate(360deg); }
					}
					#stop-loading-btn:hover {
						background: #c82333 !important;
					}
				</style>
			`;

			$('#heatmap-container').append(progressHtml);

			// Bind stop button click handler
			$('#stop-loading-btn').off('click').on('click', () => {
				this.stopLoading();
			});
		}

		/**
		 * Stop the batch loading process
		 * Called when user clicks the Stop button
		 */
		stopLoading() {
			this.debug('debug', 'User requested to stop loading');
			this.stopLoadingRequested = true;
			this.isLoading = false;
			const loadId = this.activeLoadId;
			this.updatePendingState(this.config.i18n.loading_stopped || 'Stopping after current render...', loadId);

			// Update the progress indicator to show stopped state
			const $indicator = $('#batch-progress-indicator');
			$indicator.find('.batch-spinner').css({
				'animation': 'none',
				'border-color': '#ffc107',
				'border-top-color': '#ffc107'
			});
			$indicator.find('.batch-progress-text').text(this.config.i18n.loading_stopped_final || 'Loading stopped');
			$indicator.find('#stop-loading-btn').remove();

			// Show a brief "stopped" message then hide the indicator
			setTimeout(() => {
				this.hideBatchProgress();
				this.showBatchComplete(this.allCoordinates ? this.allCoordinates.length : 0, true);
				if (!this.isStaleLoad(loadId)) {
					this.markDataComplete(loadId);
					this.finishPendingState(loadId, 'stopped');
				}
			}, 1500);
		}

		/**
		 * Update heatmap overlay incrementally without freezing
		 * Adds new points in small chunks using requestAnimationFrame
		 */
		updateHeatmapOverlay(loadId = this.activeLoadId, onComplete = null) {
			if (this.isStaleLoad(loadId)) {
				return;
			}

			if (!this.heatmapInstance || !this.allCoordinates || this.allCoordinates.length === 0) {
				this.debug('debug', 'No heatmap instance or coordinates to update');
				if (typeof onComplete === 'function') {
					onComplete();
				}
				return;
			}

			// Get scaling values
			const scaleFactor = this.canvasScaleFactor || 0.78;
			const offsetX = this.contentOffsetX || 0;
			// Apply canvas height scale for very tall pages
			const canvasHeightScale = this.canvasHeightScale || 1;
			const yScale = scaleFactor * canvasHeightScale;

			// Start from where we left off (after the initial 500 points)
			const startIndex = this.lastRenderedIndex || 500;

			if (startIndex >= this.allCoordinates.length) {
				this.debug('debug', 'All coordinates already rendered');
				return;
			}

			this.debug('debug', `Incrementally adding points from ${startIndex} to ${this.allCoordinates.length}`);

			// Process in small chunks to avoid freeze
			const chunkSize = 200;
			let currentIndex = startIndex;
			const self = this;

			// Get canvas dimensions for bounds checking
			// Use the overlay dimensions which may be capped for very tall pages
			const overlay = document.getElementById('heatmap-overlay');
			const maxX = overlay ? parseInt(overlay.style.width) || 2000 : 2000;
			const maxY = overlay ? parseInt(overlay.style.height) || 2000 : 2000;

			const processChunk = () => {
				if (self.isStaleLoad(loadId)) {
					return;
				}

				// Phase 1 scroll-perf: pause incremental repaints while the user
				// scrolls the wrapper. Stash the resume point; _onWrapperScrollStop
				// re-schedules this exact chunk once scrolling stops.
				if (self._scrollInFlight) {
					self._pendingChunkResume = { loadId: loadId, fn: processChunk };
					return;
				}

				if (currentIndex >= self.allCoordinates.length) {
					this.debug('debug', 'Incremental heatmap update complete');
					self.lastRenderedIndex = self.allCoordinates.length;
					if (typeof onComplete === 'function') {
						onComplete();
					}
					return;
				}

				const endIndex = Math.min(currentIndex + chunkSize, self.allCoordinates.length);
				const chunk = self.allCoordinates.slice(currentIndex, endIndex);

				// Scale data and filter out points outside canvas bounds to prevent IndexSizeError
				// Apply canvas height scale for Y coordinates on very tall pages
				const scaledData = chunk
					.map(point => ({
						x: Math.round((point.x - offsetX) * scaleFactor),
						y: Math.round(point.y * yScale),
						value: point.value || 1
					}))
					.filter(point => point.x >= 0 && point.x < maxX && point.y >= 0 && point.y < maxY);

				// Phase 2 scroll-perf: record every point in full canvas space so
				// scroll-stop window repaints include incrementally loaded points.
				if (self._hmWindowHeight > 0 && self._canvasSpacePoints) {
					Array.prototype.push.apply(self._canvasSpacePoints, scaledData);
				}

				// Add data incrementally (don't clear existing data). When windowing
				// is active, only paint the points inside the current window, offset
				// into window-local coordinates (points outside are recorded above
				// and painted by _repaintVisibleWindow() when the window moves).
				const drawData = (self._hmWindowHeight > 0)
					? self._windowSubset(scaledData)
					: scaledData;
				if (drawData.length > 0) {
					try {
						self.heatmapInstance.addData(drawData);
					} catch (e) {
						// Silently ignore canvas errors for out-of-bounds points
					}
				}

				currentIndex = endIndex;
				self.lastRenderedIndex = currentIndex;

				// Schedule next chunk with requestAnimationFrame to keep UI responsive
				if (currentIndex < self.allCoordinates.length) {
					requestAnimationFrame(processChunk);
				} else if (typeof onComplete === 'function') {
					onComplete();
				}
			};

			// Start processing
			requestAnimationFrame(processChunk);
		}

		/**
		 * Hide batch progress indicator (called when all batches complete)
		 */
		hideBatchProgress() {
			$('#batch-progress-indicator').remove();
			$('.opti-heatmap-stop-loading-btn').remove();
		}

		/**
		 * Show completion message when all batches are loaded or stopped
		 * @param {number} totalPoints - Total points loaded
		 * @param {boolean} stopped - Whether loading was stopped by user
		 */
		showBatchComplete(totalPoints, stopped = false) {
			// Remove any existing progress indicator
			$('#batch-progress-indicator').remove();

			// Different colors/messages for complete vs stopped vs no data
			let bgColor, textColor, icon, message;

			if (totalPoints === 0) {
				// No data case - show info message
				bgColor = 'rgba(23, 162, 184, 0.9)'; // Info blue
				textColor = '#fff';
				icon = '&#9432;'; // Info icon
				message = this.config.i18n.no_data_yet || 'No heatmap data yet for this page';
			} else if (stopped) {
				bgColor = 'rgba(255, 193, 7, 0.9)';
				textColor = '#212529';
				icon = '&#9632;'; // Square for stop
				message = (this.config.i18n.stopped_points_loaded || 'Stopped - %s points loaded').replace('%s', totalPoints.toLocaleString());
			} else {
				bgColor = 'rgba(40, 167, 69, 0.9)';
				textColor = '#fff';
				icon = '&#10003;'; // Checkmark for complete
				message = (this.config.i18n.complete_points_loaded || 'Complete! %s points loaded').replace('%s', totalPoints.toLocaleString());
			}

			// Create a completion indicator that fades out
			const completeHtml = `
				<div id="batch-progress-indicator" style="
					position: absolute;
					top: 10px;
					left: 50%;
					transform: translateX(-50%);
					background: ${bgColor};
					color: ${textColor};
					padding: 8px 12px;
					border-radius: 6px;
					font-size: 12px;
					z-index: 100;
					display: flex;
					align-items: center;
					gap: 8px;
					transition: opacity 0.5s ease;
				">
					<span style="font-size: 14px;">${icon}</span>
					<span>${message}</span>
				</div>
			`;

			$('#heatmap-container').append(completeHtml);

			// Fade out and remove after 3 seconds
			setTimeout(() => {
				$('#batch-progress-indicator').css('opacity', '0');
				setTimeout(() => {
					$('#batch-progress-indicator').remove();
				}, 500);
			}, 3000);
		}

		/**
		 * Show loading state
		 */
		showLoading(message = (this.config.i18n.loading || 'Loading heatmap data...'), loadId = this.activeLoadId) {
			if (!this.isStaleLoad(loadId)) {
				this.updatePendingState(message, loadId);
			}
			$('.heatmap-loading').css('display', 'flex');
			$('.heatmap-no-data').hide();
			$('#heatmap-container').attr('aria-hidden', 'true');
			this.disableDownloadButton();
		}

		/**
		 * Show no data state - still displays the page but with a notification
		 */
		showNoData(loadId = this.activeLoadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}

			this.updatePendingState(this.config.i18n.rendering_preview || 'Rendering page preview...', loadId);
			this.pendingNoDataNoticeLoadId = loadId;

			// Still show the page even with no heatmap data
			// Call renderHeatmap directly (not renderHeatmapWithData to avoid loop)
			this.renderHeatmap({
				coordinates: [],
				trajectories: [],
				viewport: this.heatmapViewport || { width: 1920, height: 1080 },
				reference_width: this.heatmapReferenceWidth || 1920,
				reference_height: this.heatmapReferenceHeight || 5000,
				loading_more: false
			}, loadId);
		}

		/**
		 * Show error state
		 */
		showError(message, loadId = this.activeLoadId) {
			if (loadId && this.isStaleLoad(loadId)) {
				return;
			}
			if (loadId && this.currentLoad && this.currentLoad.id === loadId && this.currentLoad.finished) {
				return;
			}

			$('.heatmap-loading').hide();
			$('#heatmap-container').hide();
			$('.heatmap-no-data p').text(message || this.config.i18n.error);
			$('.heatmap-no-data').css('display', 'flex');
			if (this.currentLoad && (!loadId || this.currentLoad.id === loadId)) {
				this.finishPendingState(this.currentLoad.id, 'error');
			}
		}

		/**
		 * Handle a failure that happens while building/rendering the heatmap
		 * OVERLAY only (canvas creation, h337 init, coordinate drawing, etc).
		 *
		 * Unlike showError(), this must NEVER hide the #heatmap-container when
		 * the iframe already finished loading the real page - the user should
		 * still see the actual page preview, just without the heatmap dots, plus
		 * a small non-blocking warning explaining that the overlay could not be
		 * drawn. Only falls back to the full-page showError() behavior when
		 * there is no iframe to show (nothing else to render).
		 */
		handleOverlayRenderFailure(message, loadId = this.activeLoadId) {
			if (loadId && this.isStaleLoad(loadId)) {
				return;
			}
			if (loadId && this.currentLoad && this.currentLoad.id === loadId && this.currentLoad.finished) {
				return;
			}

			const iframe = document.getElementById('heatmap-iframe');
			if (!iframe) {
				// Nothing else to show - fall back to the original full-hide error state.
				this.showError(message, loadId);
				return;
			}

			this.debug('warn', 'Overlay render failure (iframe kept visible): ' + message);

			// Clear out any partial/broken overlay canvas so it can't show garbled pixels.
			const heatmapOverlay = document.getElementById('heatmap-overlay');
			if (heatmapOverlay) {
				heatmapOverlay.innerHTML = '';
				heatmapOverlay.style.transform = 'none';
			}
			this.heatmapInstance = null;

			// Keep the container (and iframe inside it) visible.
			$('.heatmap-loading').hide();
			$('.heatmap-no-data').hide();
			$('#heatmap-container').show();
			$('#heatmap-container').attr('aria-hidden', 'false');

			this.showOverlayWarning(message);

			// Resolve the load state machine as "complete" (not "error") since the
			// page preview itself loaded fine - only the dot overlay failed.
			this.heatmapAlreadyRendered = true;
			this.firstBatchRendered = true;
			if (loadId) {
				this.markIframeComplete(loadId);
				this.markRenderComplete(loadId);
				this.finishPendingState(loadId);
			}
		}

		/**
		 * Show (or update) a small dismissible warning banner inside
		 * #heatmap-container telling the user the heatmap overlay could not be
		 * drawn, without blocking the underlying page preview.
		 */
		showOverlayWarning(message) {
			const container = document.getElementById('heatmap-container');
			if (!container) {
				return;
			}
			let $banner = $('#heatmap-overlay-warning');
			if ($banner.length === 0) {
				$banner = $(
					'<div id="heatmap-overlay-warning" class="notice notice-warning heatmap-overlay-warning" role="alert">' +
						'<span class="heatmap-overlay-warning-text"></span>' +
						'<button type="button" class="heatmap-overlay-warning-dismiss" aria-label="' + ((window.optiHeatmapDetail && optiHeatmapDetail.i18n && optiHeatmapDetail.i18n.dismiss) || 'Dismiss') + '">&times;</button>' +
					'</div>'
				);
				$banner.on('click', '.heatmap-overlay-warning-dismiss', function() {
					$banner.hide();
				});
				$(container).prepend($banner);
			}
			$banner.find('.heatmap-overlay-warning-text').text(
				(this.config.i18n && this.config.i18n.overlay_render_failed) ||
				'Heatmap overlay could not be drawn for this page (it may be unusually tall). The page preview below is still accurate.'
			);
			$banner.css('display', 'flex');
		}

		/**
		 * Format time in seconds to human readable
		 */
		formatTime(seconds) {
			if (seconds < 60) {
				return seconds + 's';
			}
			const minutes = Math.floor(seconds / 60);
			const secs = seconds % 60;
			return minutes + 'm ' + secs + 's';
		}

		/**
		 * Escape HTML
		 */
		escapeHtml(text) {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return text.replace(/[&<>"']/g, m => map[m]);
		}

		/**
		 * Get country flag <img> markup from country code (flagcdn PNG).
		 * Matches the User Journey rendering for cross-page consistency.
		 * @param {string} countryCode - Two-letter country code (e.g., "US", "FR")
		 * @returns {string} <img class="country-flag"> markup, or '' if invalid
		 */
		getCountryFlag(countryCode) {
			if (!countryCode || countryCode.length !== 2) {
				return '';
			}
			return `<img src="https://flagcdn.com/16x12/${this.escapeHtml(countryCode.toLowerCase())}.png" alt="" class="country-flag">`;
		}

		/**
		 * Map a browser name to a Lucide icon name.
		 * Mirrors User Journey getBrowserIcon for visual consistency.
		 * @param {string} browserName - Browser name (e.g., "Chrome", "Firefox")
		 * @returns {string} Lucide icon name
		 */
		getBrowserIcon(browserName) {
			const name = (browserName || '').toLowerCase();
			if (name.includes('chrome')) return 'chrome';
			if (name.includes('firefox')) return 'globe';
			if (name.includes('safari')) return 'compass';
			if (name.includes('edge')) return 'globe';
			if (name.includes('opera')) return 'globe';
			return 'globe';
		}

		/**
		 * Legacy brand-colored SVG browser icons (no longer used for filter
		 * dropdowns — kept only for any other callers).
		 * @param {string} browserName - Browser name
		 * @returns {string} SVG icon HTML or default globe icon
		 */
		getBrowserIconSvg(browserName) {
			const name = (browserName || '').toLowerCase();

			// Browser icon mapping with brand colors
			const browserIcons = {
				// Major browsers
				'chrome': '<svg class="browser-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="11" fill="#fff"/><path d="M12 1a11 11 0 1 0 11 11A11 11 0 0 0 12 1zm0 2a9 9 0 0 1 7.87 4.5H12a4.5 4.5 0 0 0-3.9 2.25L4.65 5.4A9 9 0 0 1 12 3z" fill="#DB4437"/><path d="M4.65 5.4l3.45 6.35A4.5 4.5 0 0 0 12 16.5a4.47 4.47 0 0 0 2.25-.6l-3.45 6A9 9 0 0 1 4.65 5.4z" fill="#0F9D58"/><path d="M19.87 7.5A9 9 0 0 1 10.8 21.9l3.45-6A4.5 4.5 0 0 0 16.5 12a4.47 4.47 0 0 0-.6-2.25z" fill="#F4B400"/><circle cx="12" cy="12" r="4.5" fill="#4285F4"/><circle cx="12" cy="12" r="3" fill="#fff"/></svg>',
				'firefox': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><radialGradient id="ffGrad1"><stop offset="0%" stop-color="#FFBD4F"/><stop offset="100%" stop-color="#FF9640"/></radialGradient><radialGradient id="ffGrad2"><stop offset="0%" stop-color="#FF9640"/><stop offset="100%" stop-color="#E63950"/></radialGradient></defs><circle cx="12" cy="12" r="11" fill="url(#ffGrad1)"/><path d="M12 2C8 2 5 4 4 7c0 0 2-1 4-1 0-2 2-3 4-3 3 0 5 2 5 5 0 2-1 3-2 4-2 1-3 2-3 4v1h3v-1c0-1 1-2 2-3 2-1 3-3 3-5 0-4-3-7-8-7z" fill="url(#ffGrad2)"/><ellipse cx="12" cy="19" rx="2" ry="2.5" fill="#fff"/><path d="M8 10c1-2 3-3 5-3 1 0 2 0 3 1-1-2-3-3-5-3-3 0-5 2-5 5 0 1 0 2 1 3 0-1 0-2 1-3z" fill="#FFF44F" opacity="0.8"/></svg>',
				'safari': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="safGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#1AC8FC"/><stop offset="100%" stop-color="#0D66D0"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#safGrad)"/><circle cx="12" cy="12" r="9" fill="none" stroke="#fff" stroke-width="0.5"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2" stroke="#fff" stroke-width="0.8"/><path d="M5.5 5.5l1.4 1.4M17.1 17.1l1.4 1.4M5.5 18.5l1.4-1.4M17.1 6.9l1.4-1.4" stroke="#fff" stroke-width="0.5"/><path d="M12 12L8 16" fill="#fff"/><path d="M12 12L16 8" fill="#E63950"/><polygon points="12,12 8,16 10,14 12,12" fill="#fff"/><polygon points="12,12 16,8 14,10 12,12" fill="#E63950"/></svg>',
				'edge': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="edgeGrad1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0078D4"/><stop offset="100%" stop-color="#1490DF"/></linearGradient><linearGradient id="edgeGrad2" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2DCCFF"/><stop offset="100%" stop-color="#00BCF2"/></linearGradient></defs><path d="M3 12c0-2 1-4 2-5 2-2 5-4 9-4 3 0 5 1 7 3-1-2-3-3-6-3-5 0-9 4-9 9 0 3 1 5 3 7 1 1 3 2 5 2h1c-3 0-6-1-8-3-2-2-4-4-4-6z" fill="url(#edgeGrad1)"/><path d="M21 9c1 1 2 3 2 5 0 5-4 9-9 9-2 0-4-1-6-2 2 1 4 2 6 2 5 0 9-4 9-9 0-2-1-4-2-5z" fill="url(#edgeGrad2)"/><path d="M12 7c-3 0-5 2-5 5h10c0-3-2-5-5-5z" fill="#0078D4"/></svg>',
				'opera': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><radialGradient id="opGrad"><stop offset="0%" stop-color="#FF1B2D"/><stop offset="100%" stop-color="#A02020"/></radialGradient></defs><circle cx="12" cy="12" r="11" fill="url(#opGrad)"/><ellipse cx="12" cy="12" rx="5" ry="8" fill="none" stroke="#fff" stroke-width="1.5"/><path d="M12 4c-2.5 0-4.5 3.5-4.5 8s2 8 4.5 8 4.5-3.5 4.5-8-2-8-4.5-8z" fill="#fff" opacity="0.3"/></svg>',
				'brave': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="braveGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FB542B"/><stop offset="100%" stop-color="#CD3A1F"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#braveGrad)"/><path d="M12 4l-1 3-2-1-1 3-3 1 1 2-2 2 3 1v3l2-1 2 1v-3l3-1-2-2 1-2-3-1-1-3z" fill="#fff"/><circle cx="12" cy="12" r="2" fill="#FB542B"/></svg>',
				'vivaldi': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="vivGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF3939"/><stop offset="100%" stop-color="#C72828"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#vivGrad)"/><path d="M6 12 Q12 6 18 12" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/><circle cx="12" cy="14" r="3" fill="#fff"/></svg>',
				'yandex': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="yandexGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF0000"/><stop offset="100%" stop-color="#CC0000"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#yandexGrad)"/><path d="M10 6h2c2 0 3 1 3 3 0 1-1 2-2 2l3 7h-2l-3-7h-1v7H8V6h2zm0 2v3h1c1 0 2 0 2-1s-1-2-2-2h-1z" fill="#fff"/></svg>',
				'samsung': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="samsungGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#7B4FFF"/><stop offset="100%" stop-color="#5A2FD6"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#samsungGrad)"/><path d="M8 8h8v8H8z" fill="none" stroke="#fff" stroke-width="1.5" rx="1"/><circle cx="12" cy="12" r="3" fill="#fff"/><circle cx="12" cy="12" r="1.5" fill="url(#samsungGrad)"/></svg>',
				'android': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="androidGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#A4C639"/><stop offset="100%" stop-color="#7FA82E"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#androidGrad)"/><path d="M8 9h8v6c0 1-1 2-2 2h-4c-1 0-2-1-2-2V9z" fill="#fff"/><circle cx="10" cy="11" r="0.8" fill="url(#androidGrad)"/><circle cx="14" cy="11" r="0.8" fill="url(#androidGrad)"/><path d="M9 7l-1-2M15 7l1-2" stroke="#fff" stroke-width="1" stroke-linecap="round"/><rect x="7" y="15" width="1.5" height="3" rx="0.5" fill="#fff"/><rect x="15.5" y="15" width="1.5" height="3" rx="0.5" fill="#fff"/></svg>',
				'webview': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="webviewGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#A4C639"/><stop offset="100%" stop-color="#7FA82E"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#webviewGrad)"/><path d="M8 9h8v6c0 1-1 2-2 2h-4c-1 0-2-1-2-2V9z" fill="#fff"/><circle cx="10" cy="11" r="0.8" fill="url(#webviewGrad)"/><circle cx="14" cy="11" r="0.8" fill="url(#webviewGrad)"/><path d="M9 7l-1-2M15 7l1-2" stroke="#fff" stroke-width="1" stroke-linecap="round"/><rect x="7" y="15" width="1.5" height="3" rx="0.5" fill="#fff"/><rect x="15.5" y="15" width="1.5" height="3" rx="0.5" fill="#fff"/></svg>',
				'huawei': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="huaweiGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF0000"/><stop offset="100%" stop-color="#C00000"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#huaweiGrad)"/><path d="M12 6l-3 6h2v6l3-6h-2V6z" fill="#fff"/><circle cx="12" cy="12" r="1" fill="url(#huaweiGrad)"/></svg>',
				// Additional browsers
				'tor': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="torGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#7D4698"/><stop offset="100%" stop-color="#59316B"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#torGrad)"/><circle cx="12" cy="12" r="8" fill="none" stroke="#fff" stroke-width="1"/><circle cx="12" cy="12" r="5" fill="none" stroke="#fff" stroke-width="1"/><circle cx="12" cy="12" r="2" fill="#fff"/></svg>',
				'uc': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="ucGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF6600"/><stop offset="100%" stop-color="#CC5200"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#ucGrad)"/><path d="M8 7v6c0 2 2 4 4 4s4-2 4-4V7h-2v6c0 1-1 2-2 2s-2-1-2-2V7H8z" fill="#fff"/></svg>',
				'maxthon': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="maxGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#00A2E8"/><stop offset="100%" stop-color="#0078A8"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#maxGrad)"/><path d="M12 5l-5 7h3v5l5-7h-3V5z" fill="#fff"/></svg>',
				'seamonkey': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="seaGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0099CC"/><stop offset="100%" stop-color="#006699"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#seaGrad)"/><path d="M7 12c0-3 2-5 5-5s5 2 5 5-2 5-5 5-5-2-5-5zm2 0c0 2 1 3 3 3s3-1 3-3-1-3-3-3-3 1-3 3z" fill="#fff"/></svg>',
				'pale moon': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="paleGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4A90D9"/><stop offset="100%" stop-color="#2E5C8A"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#paleGrad)"/><path d="M12 5l-2 4-4 1 3 3-1 4 4-2 4 2-1-4 3-3-4-1z" fill="#fff"/></svg>',
				'waterfox': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="waterGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0099FF"/><stop offset="100%" stop-color="#0066CC"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#waterGrad)"/><path d="M12 4c-2 0-4 1-5 3 0 0 1-1 2-1 0-1 1-2 3-2 2 0 3 1 3 3s-1 2-2 3c-1 1-2 1-2 2v1h2v-1c0-1 1-1 2-2 1-1 2-2 2-3 0-3-2-5-5-5z" fill="#fff"/><circle cx="12" cy="17" r="1.5" fill="#fff"/></svg>',
				'qutebrowser': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="quteGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#00AA00"/><stop offset="100%" stop-color="#007700"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#quteGrad)"/><path d="M12 5c-4 0-7 3-7 7s3 7 7 7c2 0 4-1 5-2l-2-2c-1 1-2 1-3 1-3 0-5-2-5-5s2-5 5-5c1 0 2 0 3 1l2-2c-1-1-3-2-5-2z" fill="#fff"/></svg>',
				'falkon': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="falkGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#3DAEE9"/><stop offset="100%" stop-color="#1D99D2"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#falkGrad)"/><path d="M12 5l-4 7h3v5l4-7h-3V5z" fill="#fff"/></svg>',
				'midori': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="midGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#8BC34A"/><stop offset="100%" stop-color="#689F38"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#midGrad)"/><path d="M12 5c-4 0-7 3-7 7s3 7 7 7 7-3 7-7-3-7-7-7zm0 2c3 0 5 2 5 5s-2 5-5 5-5-2-5-5 2-5 5-5z" fill="#fff"/></svg>',
				'epiphany': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="epiGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4A86CF"/><stop offset="100%" stop-color="#2E5C8A"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#epiGrad)"/><circle cx="12" cy="12" r="7" fill="none" stroke="#fff" stroke-width="1.5"/><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="1.5"/></svg>',
				'konqueror': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="konqGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0057AE"/><stop offset="100%" stop-color="#003D7A"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#konqGrad)"/><path d="M8 7h8l-4 10-4-10z" fill="#fff"/></svg>',
				'lynx': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="lynxGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#666666"/><stop offset="100%" stop-color="#333333"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#lynxGrad)"/><path d="M7 9h10v2H7zm0 4h10v2H7z" fill="#fff"/></svg>',
				'links': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="linksGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#888888"/><stop offset="100%" stop-color="#555555"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#linksGrad)"/><path d="M7 8h10v2H7zm0 4h10v2H7zm0 4h10v2H7z" fill="#fff"/></svg>',
				'w3m': '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="w3mGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#777777"/><stop offset="100%" stop-color="#444444"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#w3mGrad)"/><path d="M6 8l2 8h2l2-6 2 6h2l2-8h-2l-1 6-2-6h-2l-2 6-1-6H6z" fill="#fff"/></svg>',
				'ie': '<svg class="browser-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="11" fill="#1EBBEE"/><path d="M6 12h12M12 8c-2 0-4 1.5-4 4s2 4 4 4" stroke="#FFFFFF" stroke-width="2"/></svg>'
			};

			// Check if we have a predefined icon for this browser
			let iconSvg = null;
			for (const [key, icon] of Object.entries(browserIcons)) {
				if (name.includes(key)) {
					iconSvg = icon;
					break;
				}
			}

			// Default browser icon (generic compass with gradient)
			if (!iconSvg) {
				iconSvg = '<svg class="browser-icon" viewBox="0 0 24 24"><defs><linearGradient id="defGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#9CA3AF"/><stop offset="100%" stop-color="#6B7280"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#defGrad)"/><circle cx="12" cy="12" r="8" fill="none" stroke="#fff" stroke-width="0.5"/><path d="M12 4v3M12 17v3M4 12h3M17 12h3" stroke="#fff" stroke-width="1"/><circle cx="12" cy="12" r="2" fill="#fff"/></svg>';
			}

			// Uniquify gradient/defs IDs per rendered copy: inline SVG IDs are
			// document-global, and duplicate IDs (especially inside hidden
			// containers such as closed dropdowns or inactive tabs) make
			// url(#grad) fills render blank.
			window.optiBehaviorSvgUid = (window.optiBehaviorSvgUid || 0) + 1;
			const obSvgSuffix = '-ob' + window.optiBehaviorSvgUid;
			return iconSvg
				.replace(/id="([^"]+)"/g, 'id="$1' + obSvgSuffix + '"')
				.replace(/url\(#([^)]+)\)/g, 'url(#$1' + obSvgSuffix + ')');
		}

		/**
		 * Scroll heatmap to center position
		 */
		scrollToCenter() {
			// Interactive-preview re-renders preserve the user's scroll position
			// instead of recentering (the user is mid-interaction with the page).
			if (this._suppressScrollToCenterOnce) {
				this._suppressScrollToCenterOnce = false;
				return;
			}
			// Use setTimeout to ensure the DOM is fully updated
			setTimeout(() => {
				const wrapper = $('.heatmap-canvas-wrapper')[0];
				const container = $('#heatmap-container')[0];

				if (!wrapper || !container) {
					return;
				}

				// Calculate center position
				const scrollWidth = container.scrollWidth;
				const wrapperWidth = wrapper.clientWidth;
				const scrollHeight = container.scrollHeight;
				const wrapperHeight = wrapper.clientHeight;

				// Scroll to center horizontally
				const centerX = (scrollWidth - wrapperWidth) / 2;
				wrapper.scrollLeft = centerX;

				// Scroll to center vertically (or top 1/3 for better visibility)
				const centerY = (scrollHeight - wrapperHeight) / 3; // Top third instead of exact center
				wrapper.scrollTop = centerY;

				this.debug('debug', 'Scrolled to center:', {
					scrollLeft: centerX,
					scrollTop: centerY,
					scrollWidth: scrollWidth,
					scrollHeight: scrollHeight
				});
			}, 100);
		}

		/**
		 * Linear-interpolate the scroll-reach % at an arbitrary reference-space Y.
		 *
		 * @param {number} referenceY Y in reference (page) pixel space.
		 * @return {number|null} Reach % (0-100), or null when no reach data loaded.
		 */
		_getScrollReachAt(referenceY) {
			const bands = this._scrollDepthBands;
			if (!bands || bands.length === 0) {
				return null;
			}
			if (referenceY <= bands[0].y) {
				return bands[0].value;
			}
			const last = bands[bands.length - 1];
			if (referenceY >= last.y) {
				return last.value;
			}
			for (let i = 1; i < bands.length; i++) {
				if (referenceY <= bands[i].y) {
					const a = bands[i - 1];
					const b = bands[i];
					const span = (b.y - a.y) || 1;
					const t = (referenceY - a.y) / span;
					return a.value + (b.value - a.value) * t;
				}
			}
			return last.value;
		}

		/**
		 * Install the VWO-style scroll-depth hover indicator (horizontal line +
		 * "<N> views (<P>%) reached up to this point" tooltip) for the Scroll
		 * heatmap. Listeners live on .heatmap-canvas-wrapper (stable element,
		 * never rebuilt per render); the line/tooltip elements are created lazily
		 * and appended to #heatmap-container so they scroll/scale with the
		 * heatmap. Active only when currentType === 'scroll' and reach bands are
		 * loaded (scroll_metric === 'reach').
		 */
		_installScrollDepthIndicatorHandlers() {
			if (this._scrollDepthIndicatorInstalled) {
				return;
			}
			const wrapper = document.querySelector('.heatmap-canvas-wrapper');
			if (!wrapper) {
				return;
			}
			this._scrollDepthIndicatorInstalled = true;
			wrapper.addEventListener('mousemove', (event) => this._onScrollDepthHover(event));
			wrapper.addEventListener('mouseleave', () => this._hideScrollDepthIndicator());
			this.debug('debug', 'Scroll-depth indicator handlers installed on wrapper');
		}

		/**
		 * Create/show the transparent hover-capture layer over the preview.
		 *
		 * The preview is an IFRAME (pointer-events: auto) and the heatmap canvas
		 * above it is pointer-events: none, so mouse moves over the heatmap land
		 * INSIDE the iframe document and never reach the wrapper's mousemove
		 * listener — the indicator would otherwise only work on the container's
		 * side margins. This layer sits above the iframe/canvas (z-index 14,
		 * below the line/tooltip at 15/16) and captures the hover for the
		 * Scroll tab only; it is hidden again on type switch / legacy data so
		 * other tabs keep their normal pointer behavior.
		 */
		_ensureScrollDepthHoverLayer() {
			const container = document.getElementById('heatmap-container');
			if (!container) {
				return;
			}
			let layer = this._scrollDepthHoverLayerEl;
			if (!layer || !layer.isConnected) {
				layer = document.createElement('div');
				layer.className = 'scroll-depth-hover-layer';
				layer.addEventListener('mousemove', (event) => this._onScrollDepthHover(event));
				layer.addEventListener('mouseleave', () => this._hideScrollDepthIndicator());
				container.appendChild(layer);
				this._scrollDepthHoverLayerEl = layer;
			}
			layer.style.display = 'block';
		}

		/**
		 * Hide the hover-capture layer (and any visible indicator). Used when
		 * leaving the Scroll tab or when legacy (non-reach) data renders.
		 */
		_hideScrollDepthHoverLayer() {
			if (this._scrollDepthHoverLayerEl) {
				this._scrollDepthHoverLayerEl.style.display = 'none';
			}
			this._hideScrollDepthIndicator();
		}

		/**
		 * Whether the "Count Bar Display" setting (Settings → Data Collection)
		 * allows the click-count hover bubble. This is a Pro-only feature: the
		 * PHP config sends count_bar = 0 when the Pro plugin is inactive, and
		 * a missing config value defaults to OFF in the Free plugin.
		 */
		_isClickCountBarEnabled() {
			if (!this.config || typeof this.config.count_bar === 'undefined') {
				return false;
			}
			return parseInt(this.config.count_bar, 10) !== 0;
		}

		/**
		 * Bind the click-count hover listeners for the Click tab.
		 *
		 * No capture overlay is used: an overlay above the iframe would also
		 * swallow clicks and break Interactive Preview (menus, accordions).
		 * Instead mousemove is observed in BOTH places events can land:
		 *  - the preview iframe DOCUMENT (interactive mode ON — the iframe has
		 *    pointer-events: auto and receives the mouse natively), and
		 *  - the #heatmap-container (interactive mode OFF — the iframe has
		 *    pointer-events: none, so events fall through to the container).
		 * Only one of the two fires at a time, and neither intercepts clicks.
		 */
		_ensureClickCountHoverLayer() {
			if (this.currentType !== 'click' || !this._isClickCountBarEnabled()) {
				return;
			}
			const container = document.getElementById('heatmap-container');
			if (!container) {
				return;
			}

			// Remove any stale capture layer from a previous implementation.
			const staleLayer = container.querySelector('.click-count-hover-layer');
			if (staleLayer && staleLayer.parentNode) {
				staleLayer.parentNode.removeChild(staleLayer);
			}

			// Container listeners (non-interactive mode path). Bound once per
			// container element; guards inside the handler make them inert on
			// other tabs.
			if (this._clickCountContainerEl !== container) {
				container.addEventListener('mousemove', (event) => this._onClickCountHover(event));
				container.addEventListener('mouseleave', () => this._hideClickCountTooltip());
				this._clickCountContainerEl = container;
			}

			// Iframe-document listener (interactive mode path). Re-bound when a
			// re-render swaps in a fresh document; old listeners die with the
			// discarded document.
			const iframe = document.getElementById('heatmap-iframe');
			let doc = null;
			try {
				doc = iframe && (iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document));
			} catch (e) {
				doc = null; // cross-origin preview: container path still works when non-interactive
			}
			if (doc && doc.documentElement && this._clickCountHoverDoc !== doc) {
				doc.addEventListener('mousemove', (event) => this._onClickCountInnerHover(event, iframe), { passive: true });
				doc.documentElement.addEventListener('mouseleave', () => this._hideClickCountTooltip());
				this._clickCountHoverDoc = doc;
			}
		}

		/**
		 * Hide the click-count tooltip. Used when leaving the Click tab or when
		 * a load renders with no data. Listeners stay bound (their handlers are
		 * inert off the Click tab).
		 */
		_hideClickCountHoverLayer() {
			this._hideClickCountTooltip();
		}

		/**
		 * mousemove inside the preview iframe (interactive mode): translate the
		 * inner viewport position to top-window coordinates (the iframe is
		 * transform-scaled from its top-left corner) and reuse the main hover
		 * handler.
		 */
		_onClickCountInnerHover(event, iframe) {
			if (this.currentType !== 'click' || !iframe || !iframe.isConnected) {
				return;
			}
			const rect = iframe.getBoundingClientRect();
			const scale = this.canvasScaleFactor || 1;
			this._onClickCountHover({
				clientX: rect.left + (event.clientX * scale),
				clientY: rect.top + (event.clientY * scale)
			});
		}

		/**
		 * mousemove: count the clicks within a hotspot-sized radius around the
		 * cursor and show "N clicks in this area". Counting happens in
		 * REFERENCE (page px) space against allCoordinates — the complete,
		 * batch-accumulated point list — so it works under any fit-scale /
		 * canvas-size-cap combination and stays fresh as batches stream in.
		 */
		_onClickCountHover(event) {
			if (this.currentType !== 'click' || !this._isClickCountBarEnabled() ||
				!this.allCoordinates || this.allCoordinates.length === 0) {
				this._hideClickCountTooltip();
				return;
			}
			const container = document.getElementById('heatmap-container');
			if (!container) {
				this._hideClickCountTooltip();
				return;
			}
			const rect = container.getBoundingClientRect();
			if (!rect.width || !rect.height ||
				event.clientX < rect.left || event.clientX > rect.right ||
				event.clientY < rect.top || event.clientY > rect.bottom) {
				this._hideClickCountTooltip();
				return;
			}

			// Cursor -> reference (page) pixel space. The container CSS box
			// renders the full reference width/height, so a proportional
			// mapping is exact regardless of fit-scale or canvas capping.
			const offsetX = event.clientX - rect.left;
			const offsetY = event.clientY - rect.top;
			const refWidth = this.heatmapReferenceWidth || rect.width;
			const refHeight = this.heatmapReferenceHeight || rect.height;
			const refX = (offsetX / rect.width) * refWidth;
			const refY = (offsetY / rect.height) * refHeight;

			// Radius matches the h337 dot radius (40 canvas px ≈ 40 reference
			// px at the common 1:1 coordinate scale), so the counted area is
			// the hotspot the admin visually hovers.
			const RADIUS = 40;
			const radiusSq = RADIUS * RADIUS;
			let count = 0;
			const points = this.allCoordinates;
			for (let i = 0; i < points.length; i++) {
				const dx = (points[i].x || 0) - refX;
				if (dx > RADIUS || dx < -RADIUS) {
					continue;
				}
				const dy = (points[i].y || 0) - refY;
				if ((dx * dx + dy * dy) <= radiusSq) {
					count += Math.max(1, Math.round(points[i].value || 1));
				}
			}

			if (count <= 0) {
				this._hideClickCountTooltip();
				return;
			}

			// Lazily create the tooltip, re-appending if a re-render replaced
			// the container's children.
			if (!this._clickCountTooltipEl || !this._clickCountTooltipEl.isConnected) {
				const tip = document.createElement('div');
				tip.className = 'click-count-tooltip';
				container.appendChild(tip);
				this._clickCountTooltipEl = tip;
			}
			const tip = this._clickCountTooltipEl;

			// Tooltip text: "<N> clicks in this area" with the count bolded
			// (locally computed number, not user input, so innerHTML is safe).
			const template = this.t('click_count_tooltip', '{count} clicks in this area');
			tip.innerHTML = template.replace('{count}', '<strong>' + count.toLocaleString() + '</strong>');
			tip.style.display = 'block';

			// Position above the cursor (below when clipped at the top),
			// horizontally centered on it, clamped inside the container.
			const tipHeight = tip.offsetHeight || 28;
			const tipWidth = tip.offsetWidth || 160;
			const above = offsetY - tipHeight - 12;
			tip.style.top = (above >= 4 ? above : offsetY + 14) + 'px';
			const clampedX = Math.max(8, Math.min(offsetX - tipWidth / 2, rect.width - tipWidth - 8));
			tip.style.left = clampedX + 'px';
		}

		/**
		 * Hide the click-count tooltip (kept in the DOM for cheap reuse).
		 */
		_hideClickCountTooltip() {
			if (this._clickCountTooltipEl) {
				this._clickCountTooltipEl.style.display = 'none';
			}
		}

		/**
		 * mousemove handler: position the line under the cursor and update the
		 * tooltip with the interpolated reach at that depth.
		 */
		_onScrollDepthHover(event) {
			// Dispatcher: the wrapper/capture-layer listeners are shared between
			// the Scroll (reach) and Attention (time) indicators; route by tab.
			if (this.currentType === 'attention') {
				this._onAttentionTimeHover(event);
				return;
			}
			if (this.currentType !== 'scroll' || !this._scrollDepthBands) {
				this._hideScrollDepthIndicator();
				return;
			}
			const container = document.getElementById('heatmap-container');
			if (!container) {
				this._hideScrollDepthIndicator();
				return;
			}
			const rect = container.getBoundingClientRect();
			if (!rect.height ||
				event.clientX < rect.left || event.clientX > rect.right ||
				event.clientY < rect.top || event.clientY > rect.bottom) {
				// Cursor inside the wrapper but outside the container (short pages,
				// side margins, fixed legend overlay area).
				this._hideScrollDepthIndicator();
				return;
			}

			// Container CSS height renders the full reference height, so a plain
			// proportional mapping converts cursor Y to reference space correctly
			// under any fit-scale / canvas-size-cap combination.
			const offsetY = event.clientY - rect.top;
			const refHeight = this.heatmapReferenceHeight || 0;
			const referenceY = refHeight > 0 ? (offsetY / rect.height) * refHeight : offsetY;
			const pct = this._getScrollReachAt(referenceY);
			if (pct === null) {
				this._hideScrollDepthIndicator();
				return;
			}
			const views = Math.round((this._scrollDepthTotalSessions || 0) * pct / 100);

			// Lazily create the line/tooltip, re-appending if a re-render replaced
			// the container's children (elements are cheap; container itself is
			// stable but defensive re-append costs nothing).
			if (!this._scrollDepthLineEl || !this._scrollDepthLineEl.isConnected) {
				const line = document.createElement('div');
				line.className = 'scroll-depth-indicator-line';
				container.appendChild(line);
				this._scrollDepthLineEl = line;
			}
			if (!this._scrollDepthTooltipEl || !this._scrollDepthTooltipEl.isConnected) {
				const tip = document.createElement('div');
				tip.className = 'scroll-depth-indicator-tooltip';
				container.appendChild(tip);
				this._scrollDepthTooltipEl = tip;
			}

			const line = this._scrollDepthLineEl;
			const tip = this._scrollDepthTooltipEl;

			line.style.top = offsetY + 'px';
			line.style.display = 'block';

			// Tooltip text: "<N> views (<P.P>%) reached up to this point" with the
			// count and percent bolded (values are locally computed numbers, not
			// user input, so innerHTML is safe here).
			const template = (this.config && this.config.i18n && this.config.i18n.scroll_depth_tooltip) ||
				'{views} views ({percent}%) reached up to this point';
			tip.innerHTML = template
				.replace('{views}', '<strong>' + views.toLocaleString() + '</strong>')
				.replace('{percent}', '<strong>' + pct.toFixed(1) + '</strong>');
			tip.style.display = 'block';

			// Position tooltip above the line (below when clipped at the top),
			// horizontally near the cursor, clamped inside the container.
			const tipHeight = tip.offsetHeight || 30;
			const tipWidth = tip.offsetWidth || 220;
			const above = offsetY - tipHeight - 10;
			tip.style.top = (above >= 4 ? above : offsetY + 10) + 'px';
			const cursorX = event.clientX - rect.left;
			const clampedX = Math.max(8, Math.min(cursorX - tipWidth / 2, rect.width - tipWidth - 8));
			tip.style.left = clampedX + 'px';
		}

		/**
		 * Hide the scroll-depth indicator line and tooltip (kept in the DOM for
		 * cheap reuse).
		 */
		_hideScrollDepthIndicator() {
			if (this._scrollDepthLineEl) {
				this._scrollDepthLineEl.style.display = 'none';
			}
			if (this._scrollDepthTooltipEl) {
				this._scrollDepthTooltipEl.style.display = 'none';
			}
			if (this._attentionTooltipEl) {
				this._attentionTooltipEl.style.display = 'none';
			}
		}

		/**
		 * Share (0-1) of the page's total attention-density mass that falls
		 * inside a viewport-height window centered on the given reference-space
		 * Y. O(1) via the prefix sums cached by renderAttentionHeatmap().
		 *
		 * @param {number} referenceY Y in reference (page) pixel space.
		 * @return {number|null} Share 0-1, or null when no attention data.
		 */
		_getAttentionShareAt(referenceY) {
			const ab = this._attentionBands;
			if (!ab || !ab.total || ab.total <= 0) {
				return null;
			}
			const dispY = referenceY * ab.scaleY;
			const halfWin = (ab.viewportH * ab.scaleY) / 2;
			const n = ab.density.length;
			let startBand = Math.floor((dispY - halfWin) / ab.bandHeight);
			let endBand = Math.ceil((dispY + halfWin) / ab.bandHeight);
			startBand = Math.max(0, Math.min(n, startBand));
			endBand = Math.max(0, Math.min(n, endBand));
			if (endBand <= startBand) {
				return 0;
			}
			return (ab.prefix[endBand] - ab.prefix[startBand]) / ab.total;
		}

		/**
		 * Zero-padded HH:MM:SS formatter for the attention tooltip's
		 * "Avg time spent" value (formatTime() renders "3m 39s" style and is
		 * left untouched for the header stat).
		 */
		_formatHMS(totalSeconds) {
			const sec = Math.max(0, Math.round(totalSeconds || 0));
			const h = Math.floor(sec / 3600);
			const m = Math.floor((sec % 3600) / 60);
			const s = sec % 60;
			const pad = (v) => (v < 10 ? '0' : '') + v;
			return pad(h) + ':' + pad(m) + ':' + pad(s);
		}

		/**
		 * mousemove handler for the Attention tab: position the shared
		 * indicator line under the cursor and show a Clarity-style tooltip with
		 * the average time spent in the viewport-sized area at that depth and
		 * its share of the average session length.
		 */
		_onAttentionTimeHover(event) {
			if (this.currentType !== 'attention' || !this._attentionBands ||
				!(this._lastAvgTime > 0)) {
				this._hideScrollDepthIndicator();
				return;
			}
			const container = document.getElementById('heatmap-container');
			if (!container) {
				this._hideScrollDepthIndicator();
				return;
			}
			const rect = container.getBoundingClientRect();
			if (!rect.height ||
				event.clientX < rect.left || event.clientX > rect.right ||
				event.clientY < rect.top || event.clientY > rect.bottom) {
				this._hideScrollDepthIndicator();
				return;
			}

			// Cursor -> reference (page) pixel space: container CSS height
			// renders the full reference height, so proportional mapping is
			// exact under any fit-scale / canvas-size-cap combination.
			const offsetY = event.clientY - rect.top;
			const refHeight = this.heatmapReferenceHeight || 0;
			const referenceY = refHeight > 0 ? (offsetY / rect.height) * refHeight : offsetY;
			const share = this._getAttentionShareAt(referenceY);
			if (share === null) {
				this._hideScrollDepthIndicator();
				return;
			}
			const avgSeconds = this._lastAvgTime * share;
			const pct = share * 100;

			// Reuse the scroll-depth indicator line; lazily (re)create it and
			// the attention tooltip if a re-render replaced container children.
			if (!this._scrollDepthLineEl || !this._scrollDepthLineEl.isConnected) {
				const line = document.createElement('div');
				line.className = 'scroll-depth-indicator-line';
				container.appendChild(line);
				this._scrollDepthLineEl = line;
			}
			if (!this._attentionTooltipEl || !this._attentionTooltipEl.isConnected) {
				const tip = document.createElement('div');
				tip.className = 'attention-time-tooltip';
				container.appendChild(tip);
				this._attentionTooltipEl = tip;
			}
			// Never show the scroll tooltip variant on this tab.
			if (this._scrollDepthTooltipEl) {
				this._scrollDepthTooltipEl.style.display = 'none';
			}

			const line = this._scrollDepthLineEl;
			const tip = this._attentionTooltipEl;

			line.style.top = offsetY + 'px';
			line.style.display = 'block';

			// Two stacked label/value rows (locally computed values, not user
			// input, so innerHTML is safe).
			const i18n = (this.config && this.config.i18n) || {};
			const timeLabel = i18n.attention_avg_time_label || 'Avg time spent';
			const pctLabel = i18n.attention_session_pct_label || '% of session length';
			tip.innerHTML =
				'<span class="attention-tooltip-label">' + timeLabel + '</span>' +
				'<span class="attention-tooltip-value">' + this._formatHMS(avgSeconds) + '</span>' +
				'<span class="attention-tooltip-label">' + pctLabel + '</span>' +
				'<span class="attention-tooltip-value">' + pct.toFixed(2) + '%</span>';
			tip.style.display = 'block';

			// Position above the line (below when clipped at the top),
			// horizontally near the cursor, clamped inside the container.
			const tipHeight = tip.offsetHeight || 88;
			const tipWidth = tip.offsetWidth || 170;
			const above = offsetY - tipHeight - 10;
			tip.style.top = (above >= 4 ? above : offsetY + 10) + 'px';
			const cursorX = event.clientX - rect.left;
			const clampedX = Math.max(8, Math.min(cursorX - tipWidth / 2, rect.width - tipWidth - 8));
			tip.style.left = clampedX + 'px';
		}

		/**
		 * Phase 1 scroll-perf: install a passive scroll listener on the
		 * .heatmap-canvas-wrapper scroller that flags "scroll in flight" and,
		 * on a debounced scroll-stop, resumes work that was paused during scroll.
		 *
		 * The listener itself does NO per-event layout/paint work — it only
		 * toggles a boolean and resets a timer. Heavy repaint sources
		 * (incremental addData chunks, interactive re-projection) check that
		 * boolean and defer, so scrolling composites cheaply. Idempotent.
		 */
		_installScrollPerfHandlers() {
			if (this._scrollPerfInstalled) {
				return;
			}
			const wrapper = document.querySelector('.heatmap-canvas-wrapper');
			if (!wrapper) {
				return;
			}
			this._scrollPerfInstalled = true;
			this._scrollWrapperEl = wrapper;
			this._boundOnWrapperScroll = this._onWrapperScroll.bind(this);
			// Passive listener: never calls preventDefault, so the browser keeps
			// the scroll on the fast (compositor) path.
			wrapper.addEventListener('scroll', this._boundOnWrapperScroll, { passive: true });
			this.debug('debug', 'Scroll-perf handlers installed on wrapper');
		}

		/**
		 * Wrapper scroll handler. Cheap: set the in-flight flag and (re)arm the
		 * scroll-stop debounce. No rendering here.
		 */
		_onWrapperScroll() {
			this._scrollInFlight = true;
			if (this._scrollStopTimer) {
				clearTimeout(this._scrollStopTimer);
			}
			this._scrollStopTimer = setTimeout(() => {
				this._scrollStopTimer = null;
				this._onWrapperScrollStop();
			}, 140);
		}

		/**
		 * Debounced scroll-stop: clear the in-flight flag and resume anything
		 * that was paused during the scroll (incremental addData chunk, and a
		 * deferred interactive re-projection if a layout change was observed
		 * mid-scroll).
		 */
		_onWrapperScrollStop() {
			this._scrollInFlight = false;

			// Phase 2: if the viewport left the rendered canvas window while
			// scrolling, recenter the window and repaint it. Runs BEFORE the
			// paused chunk resume below: the stashed chunk has not recorded its
			// points yet (the in-flight check precedes recording), so the repaint
			// and the resumed chunk never double-paint the same points.
			this._repaintVisibleWindow();

			// Resume the paused updateHeatmapOverlay() chunk from exactly where
			// it stopped (guarded against a superseded load).
			if (this._pendingChunkResume) {
				const resume = this._pendingChunkResume;
				this._pendingChunkResume = null;
				if (!this.isStaleLoad(resume.loadId) && typeof resume.fn === 'function') {
					requestAnimationFrame(resume.fn);
				}
			}

			// Resume a deferred interactive live re-render (interactive mode only).
			if (this._interactiveRepaintPendingDuringScroll) {
				this._interactiveRepaintPendingDuringScroll = false;
				const bundle = this._interactivePreviewObservers;
				if (this.interactiveMode && bundle && typeof bundle.__schedule === 'function') {
					bundle.__schedule('scroll-stop');
				}
			}
		}

		/**
		 * Phase 2 scroll-perf: create the viewport-sized window container the
		 * h337 canvas mounts into (click heatmaps only). The window spans a few
		 * viewports of CANVAS-space pixels centered on the current scroll
		 * position and is absolutely positioned inside #heatmap-overlay, so it
		 * scrolls naturally with the page content — no per-frame JS needed.
		 *
		 * Falls back to the full overlay (previous behavior) when the scroll
		 * wrapper cannot be found or the page is short enough that a window
		 * would not save anything.
		 *
		 * @param {HTMLElement} overlay       #heatmap-overlay element.
		 * @param {number}      overlayWidth  Canvas-space overlay width in px.
		 * @param {number}      overlayHeight Canvas-space overlay height in px.
		 * @return {HTMLElement} Element to use as the h337 container.
		 */
		_createHeatmapWindow(overlay, overlayWidth, overlayHeight) {
			this._hmWindowEl = null;
			this._hmWindowTop = 0;
			this._hmWindowHeight = 0;

			const wrapper = this._scrollWrapperEl || document.querySelector('.heatmap-canvas-wrapper');
			if (!wrapper || !wrapper.clientHeight) {
				return overlay; // fallback: previous full-height behavior
			}

			// One on-screen viewport expressed in canvas-space pixels.
			const fitScale = this.canvasScaleFactor || 1;
			const chs = this.canvasHeightScale || 1;
			const viewCanvasPx = Math.max(200, Math.round((wrapper.clientHeight / fitScale) * chs));

			// Window = 6 viewports (visible + ~2.5 above + ~2.5 below). If the
			// whole page fits in that budget, windowing buys nothing — use the
			// full overlay as before.
			const windowHeight = viewCanvasPx * 6;
			if (windowHeight >= overlayHeight) {
				return overlay;
			}

			const scrollCanvasPx = Math.round(((wrapper.scrollTop || 0) / fitScale) * chs);
			const windowTop = Math.max(0, Math.min(
				overlayHeight - windowHeight,
				scrollCanvasPx + Math.round(viewCanvasPx / 2) - Math.round(windowHeight / 2)
			));

			const winEl = document.createElement('div');
			winEl.id = 'heatmap-window';
			winEl.style.position = 'absolute';
			winEl.style.left = '0';
			winEl.style.top = windowTop + 'px';
			winEl.style.width = overlayWidth + 'px';
			winEl.style.height = windowHeight + 'px';
			winEl.style.pointerEvents = 'none';
			overlay.appendChild(winEl);

			this._hmWindowEl = winEl;
			this._hmWindowTop = windowTop;
			this._hmWindowHeight = windowHeight;

			this.debug('debug', 'Windowed heatmap canvas: ' + overlayWidth + 'x' + windowHeight +
				' @y=' + windowTop + ' (page ' + overlayHeight + 'px, viewport ' + viewCanvasPx + 'px canvas-space)');

			return winEl;
		}

		/**
		 * Phase 2 scroll-perf: return the subset of full-canvas-space points that
		 * fall inside the current window, converted to window-local Y. When
		 * windowing is off, returns the input unchanged.
		 *
		 * @param {Array<{x:number,y:number,value:number}>} points Full canvas-space points.
		 * @return {Array<{x:number,y:number,value:number}>} Window-local points.
		 */
		_windowSubset(points) {
			if (!(this._hmWindowHeight > 0)) {
				return points;
			}
			const top = this._hmWindowTop;
			const bottom = top + this._hmWindowHeight;
			const out = [];
			for (let i = 0; i < points.length; i++) {
				const p = points[i];
				if (p.y >= top && p.y < bottom) {
					out.push({ x: p.x, y: p.y - top, value: p.value });
				}
			}
			return out;
		}

		/**
		 * Phase 2 scroll-perf: on scroll-stop, recenter the canvas window on the
		 * viewport and repaint it from _canvasSpacePoints — but only when the
		 * viewport has drifted within one viewport of a window edge (or past
		 * it). setData() on the small window canvas is cheap (few MB, one
		 * colorize pass) compared to any full-page repaint.
		 *
		 * @param {number} loadId Load generation guard (defaults to the active load).
		 */
		_repaintVisibleWindow(loadId = this.activeLoadId) {
			if (this.isStaleLoad(loadId)) {
				return;
			}
			if (!this._hmWindowEl || !(this._hmWindowHeight > 0) ||
				!this.heatmapInstance || !this._canvasSpacePoints) {
				return;
			}
			const wrapper = this._scrollWrapperEl || document.querySelector('.heatmap-canvas-wrapper');
			if (!wrapper) {
				return;
			}
			const overlay = this._hmWindowEl.parentNode;
			const overlayHeight = overlay ? (parseInt(overlay.style.height, 10) || 0) : 0;
			if (!overlayHeight) {
				return;
			}

			const fitScale = this.canvasScaleFactor || 1;
			const chs = this.canvasHeightScale || 1;
			const viewCanvasPx = Math.max(200, Math.round((wrapper.clientHeight / fitScale) * chs));
			const scrollCanvasPx = Math.round(((wrapper.scrollTop || 0) / fitScale) * chs);

			const winTop = this._hmWindowTop;
			const winBottom = winTop + this._hmWindowHeight;
			const visTop = scrollCanvasPx;
			const visBottom = scrollCanvasPx + viewCanvasPx;

			// Still comfortably inside the window (≥ 1 viewport of margin on each
			// side, or the window already touches that document edge)? Nothing to do.
			const margin = viewCanvasPx;
			const topOk = (visTop - winTop >= margin) || winTop <= 0;
			const bottomOk = (winBottom - visBottom >= margin) || winBottom >= overlayHeight;
			if (topOk && bottomOk) {
				return;
			}

			const newTop = Math.max(0, Math.min(
				overlayHeight - this._hmWindowHeight,
				scrollCanvasPx + Math.round(viewCanvasPx / 2) - Math.round(this._hmWindowHeight / 2)
			));
			if (newTop === winTop) {
				return;
			}

			this._hmWindowTop = newTop;
			this._hmWindowEl.style.top = newTop + 'px';

			try {
				this.heatmapInstance.setData({
					max: this._hmDataMax,
					data: this._windowSubset(this._canvasSpacePoints)
				});
			} catch (e) {
				this.debug('error', 'Window repaint failed:', e);
			}
		}

		/**
		 * Create scroll heatmap legend (color scale indicator)
		 * Styled to match the Attention Heatmap legend with fixed positioning
		 */
		createScrollHeatmapLegend(container) {
			// Remove existing legend if present
			const existingLegend = document.querySelector('.scroll-heatmap-legend');
			if (existingLegend) {
				existingLegend.remove();
			}

			// Create legend element with fixed positioning (matches Attention Heatmap)
			const legend = document.createElement('div');
			legend.className = 'scroll-heatmap-legend';
			legend.style.cssText = `
				position: fixed;
				top: 50%;
				right: 30px;
				transform: translateY(-50%);
				width: 35px;
				background: rgba(255, 255, 255, 0.95);
				border: 1px solid rgba(0, 0, 0, 0.2);
				border-radius: 6px;
				padding: 8px 6px;
				box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
				z-index: 1000;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
			`;

			// High label (100% of visitors reached this depth)
			const highLabel = document.createElement('div');
			highLabel.textContent = '100%';
			highLabel.style.cssText = `
				text-align: center;
				font-size: 9px;
				font-weight: bold;
				color: #333;
				margin-bottom: 4px;
			`;
			legend.appendChild(highLabel);

			// Gradient container with scroll heatmap colors
			const gradientContainer = document.createElement('div');
			gradientContainer.style.cssText = `
				width: 100%;
				height: 120px;
				border: 1px solid rgba(0, 0, 0, 0.3);
				border-radius: 3px;
				background: linear-gradient(to bottom,
					rgba(220, 20, 20, 0.9) 0%,
					rgba(255, 69, 0, 0.85) 10%,
					rgba(255, 140, 0, 0.8) 20%,
					rgba(255, 255, 0, 0.75) 35%,
					rgba(50, 205, 50, 0.7) 50%,
					rgba(64, 224, 208, 0.65) 65%,
					rgba(100, 149, 237, 0.6) 80%,
					rgba(135, 206, 250, 0.55) 90%,
					rgba(173, 216, 230, 0.5) 100%
				);
			`;
			legend.appendChild(gradientContainer);

			// Low label (0% of visitors reached this depth)
			const lowLabel = document.createElement('div');
			lowLabel.textContent = '0%';
			lowLabel.style.cssText = `
				text-align: center;
				font-size: 9px;
				font-weight: bold;
				color: #333;
				margin-top: 4px;
			`;
			legend.appendChild(lowLabel);

			// Scroll label: % of visitors who scrolled this far
			const scrollLabel = document.createElement('div');
			scrollLabel.textContent = (this.config.i18n && this.config.i18n.scroll_label) ? this.config.i18n.scroll_label : '% Scrolled';
			scrollLabel.style.cssText = `
				text-align: center;
				font-size: 8px;
				color: #666;
				margin-top: 6px;
				padding-top: 6px;
				border-top: 1px solid rgba(0, 0, 0, 0.1);
			`;
			legend.appendChild(scrollLabel);

			// Append legend to document body (not container) for fixed positioning
			document.body.appendChild(legend);
			this.debug('debug', 'Created scroll heatmap legend with fixed positioning');
		}

		/**
		 * Download heatmap as PNG image
		 * Opens the page URL in a new window, injects heatmap data, and captures
		 */
		async downloadHeatmapImage() {
			const container = document.getElementById('heatmap-container');
			const iframe = document.getElementById('heatmap-iframe');
			const overlay = document.getElementById('heatmap-overlay');

			if (!container) {
				this.debug('error', 'Heatmap container not found for download');
				return;
			}

			const $downloadBtn = $('#download-heatmap');
			const originalHtml = $downloadBtn.html();

			// Set loading state
			$downloadBtn.prop('disabled', true).html('<i data-lucide="loader-2" class="spin"></i> ' + (this.config.i18n.downloading || 'Downloading...'));
			if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
				lucide.createIcons();
			}

			try {
				this.debug('debug', 'Starting heatmap image capture...');

				// Get dimensions
				const width = overlay ? parseInt(overlay.style.width) || container.scrollWidth : container.scrollWidth;
				const height = overlay ? parseInt(overlay.style.height) || container.scrollHeight : container.scrollHeight;
				const scale = 2;

				// Create final canvas
				const finalCanvas = document.createElement('canvas');
				finalCanvas.width = width * scale;
				finalCanvas.height = height * scale;
				const ctx = finalCanvas.getContext('2d');

				// Fill with white background
				ctx.fillStyle = '#ffffff';
				ctx.fillRect(0, 0, finalCanvas.width, finalCanvas.height);

				let iframeCaptured = false;

				// Try to capture iframe content by injecting html2canvas INTO the iframe
				if (iframe && iframe.contentWindow && iframe.contentDocument) {
					this.debug('debug', 'Attempting to capture iframe content...');

					try {
						const iframeDoc = iframe.contentDocument;
						const iframeWin = iframe.contentWindow;

						// Inject html2canvas if not already present
						if (typeof iframeWin.html2canvas !== 'function') {
							this.debug('debug', 'Injecting html2canvas into iframe...');

							const script = iframeDoc.createElement('script');
							script.src = this.config.assets_url + 'js/html2canvas.min.js';

							await new Promise((resolve, reject) => {
								script.onload = resolve;
								script.onerror = reject;
								iframeDoc.head.appendChild(script);
							});

							this.debug('debug', 'html2canvas injected successfully');
						}

						// Run html2canvas inside the iframe on the documentElement
						if (typeof iframeWin.html2canvas === 'function') {
							this.debug('debug', 'Running html2canvas inside iframe on documentElement...');

							// Reset scroll position for capture
							const originalScrollTop = iframeWin.scrollY;
							iframeWin.scrollTo(0, 0);

							// Wait a moment for scroll to settle
							await new Promise(resolve => setTimeout(resolve, 100));

							// Monkey-patch getComputedStyle to sanitize CSS Color Level 4 color() functions
							// html2canvas 1.4.1 doesn't support color() function which modern browsers
							// return in computed styles for certain CSS features (even if not in stylesheets)
							const originalGetComputedStyle = iframeWin.getComputedStyle;
							iframeWin.getComputedStyle = function(element, pseudoElement) {
								const computedStyle = originalGetComputedStyle.call(iframeWin, element, pseudoElement);

								// Create a proxy that sanitizes color() functions in computed style values
								return new Proxy(computedStyle, {
									get(target, prop) {
										const value = target[prop];

										// Handle function properties (like getPropertyValue)
										if (typeof value === 'function') {
											return function(...args) {
												const result = value.apply(target, args);
												if (typeof result === 'string' && result.includes('color(')) {
													return result.replace(/color\s*\([^)]+\)/gi, 'rgb(0, 0, 0)');
												}
												return result;
											};
										}

										// Handle string properties that might contain color()
										if (typeof value === 'string' && value.includes('color(')) {
											return value.replace(/color\s*\([^)]+\)/gi, 'rgb(0, 0, 0)');
										}

										return value;
									}
								});
							};

							let iframeCanvas;
							try {
								iframeCanvas = await iframeWin.html2canvas(iframeDoc.documentElement, {
									useCORS: true,
									allowTaint: true,
									backgroundColor: '#ffffff',
									scale: scale,
									logging: false,
									width: width,
									height: height,
									scrollX: 0,
									scrollY: 0,
									windowWidth: width,
									windowHeight: height,
									x: 0,
									y: 0
								});
							} finally {
								// Always restore original getComputedStyle
								iframeWin.getComputedStyle = originalGetComputedStyle;
							}

							// Restore scroll position
							iframeWin.scrollTo(0, originalScrollTop);

							// Check if canvas has content by sampling multiple regions
							// The page might have white areas at certain coordinates
							const tempCtx = iframeCanvas.getContext('2d');
							let hasContent = false;

							// Sample multiple regions across the canvas
							const sampleRegions = [
								{ x: 50, y: 50 },                                    // Top-left area
								{ x: Math.floor(iframeCanvas.width / 2), y: 100 },   // Top-center
								{ x: 100, y: Math.floor(iframeCanvas.height / 4) },  // Upper-left quarter
								{ x: Math.floor(iframeCanvas.width / 2), y: Math.floor(iframeCanvas.height / 4) } // Center-upper
							];

							for (const region of sampleRegions) {
								if (hasContent) break;

								const sampleX = Math.min(region.x, iframeCanvas.width - 100);
								const sampleY = Math.min(region.y, iframeCanvas.height - 100);
								const imageData = tempCtx.getImageData(sampleX, sampleY, 100, 100);

								for (let i = 0; i < imageData.data.length; i += 4) {
									if (imageData.data[i] !== 255 || imageData.data[i+1] !== 255 || imageData.data[i+2] !== 255) {
										hasContent = true;
										break;
									}
								}
							}

							this.debug('debug', 'Iframe canvas has content:', hasContent, 'Size:', iframeCanvas.width, 'x', iframeCanvas.height);

							if (hasContent) {
								ctx.drawImage(iframeCanvas, 0, 0, finalCanvas.width, finalCanvas.height);
								iframeCaptured = true;
								this.debug('debug', 'Iframe content captured successfully');
							} else {
								this.debug('warn', 'Iframe canvas appears empty/white');
							}
						}
					} catch (iframeError) {
						this.debug('warn', 'Iframe capture failed:', iframeError);
					}
				}

				if (!iframeCaptured) {
					this.debug('warn', 'Could not capture iframe content - downloading heatmap overlay only');
				}

				// Draw heatmap overlay on top
				if (overlay) {
					this.debug('debug', 'Drawing heatmap overlay...');
					const heatmapCanvas = overlay.querySelector('canvas');
					if (heatmapCanvas) {
						ctx.drawImage(heatmapCanvas, 0, 0, finalCanvas.width, finalCanvas.height);
						this.debug('debug', 'Heatmap canvas drawn');
					}
				}

				// Generate filename and download
				const filename = this.generateFilename();

				finalCanvas.toBlob((blob) => {
					if (!blob) {
						this.debug('error', 'Failed to generate blob');
						alert(this.config.i18n.download_error || 'Failed to generate image');
						$downloadBtn.prop('disabled', false).html(originalHtml);
						if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
							lucide.createIcons();
						}
						return;
					}

					const url = URL.createObjectURL(blob);
					const link = document.createElement('a');
					link.href = url;
					link.download = filename;
					document.body.appendChild(link);
					link.click();
					document.body.removeChild(link);
					URL.revokeObjectURL(url);

					this.debug('debug', 'Heatmap image downloaded:', filename);

					$downloadBtn.prop('disabled', false).html(originalHtml);
					if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
						lucide.createIcons();
					}
				}, 'image/png');

			} catch (error) {
				this.debug('error', 'Failed to generate heatmap image:', error);
				alert(error.message || this.config.i18n.download_error || 'Failed to generate image');

				$downloadBtn.prop('disabled', false).html(originalHtml);
				if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
					lucide.createIcons();
				}
			}
		}

		/**
		 * Clone a node with all computed styles inlined
		 */
		async cloneNodeWithStyles(node, doc, win) {
			const clone = node.cloneNode(false);

			// Copy computed styles as inline style
			if (node.nodeType === Node.ELEMENT_NODE) {
				const computedStyle = win.getComputedStyle(node);
				let styleText = '';
				for (let i = 0; i < computedStyle.length; i++) {
					const prop = computedStyle[i];
					styleText += `${prop}:${computedStyle.getPropertyValue(prop)};`;
				}
				clone.setAttribute('style', styleText);

				// Handle special elements
				if (node.tagName === 'CANVAS') {
					// Convert canvas to image
					try {
						const dataUrl = node.toDataURL('image/png');
						const img = doc.createElement('img');
						img.src = dataUrl;
						img.setAttribute('style', styleText);
						return img;
					} catch (e) {
						// Canvas might be tainted
					}
				}

				if (node.tagName === 'INPUT' || node.tagName === 'TEXTAREA') {
					clone.setAttribute('value', node.value);
				}

				if (node.tagName === 'SELECT') {
					clone.value = node.value;
				}
			}

			// Recursively clone children
			for (const child of node.childNodes) {
				if (child.nodeType === Node.TEXT_NODE) {
					clone.appendChild(child.cloneNode(true));
				} else if (child.nodeType === Node.ELEMENT_NODE) {
					// Skip script tags and hidden elements
					if (child.tagName !== 'SCRIPT' && child.tagName !== 'NOSCRIPT') {
						const computedDisplay = win.getComputedStyle(child).display;
						if (computedDisplay !== 'none') {
							clone.appendChild(await this.cloneNodeWithStyles(child, doc, win));
						}
					}
				}
			}

			return clone;
		}

		/**
		 * Convert all images in an element to data URLs
		 */
		async convertImagesToDataUrls(element) {
			const images = element.querySelectorAll('img');

			for (const img of images) {
				if (img.src && !img.src.startsWith('data:')) {
					try {
						const dataUrl = await this.imageToDataUrl(img.src);
						img.src = dataUrl;
					} catch (e) {
						// If we can't convert, use a placeholder or leave as is
						this.debug('warn', 'Could not convert image to data URL:', img.src);
					}
				}
			}

			// Also convert background images in inline styles
			const allElements = element.querySelectorAll('*');
			for (const el of allElements) {
				const style = el.getAttribute('style') || '';
				const bgMatch = style.match(/background-image:\s*url\(['"]?([^'")\s]+)['"]?\)/);
				if (bgMatch && bgMatch[1] && !bgMatch[1].startsWith('data:')) {
					try {
						const dataUrl = await this.imageToDataUrl(bgMatch[1]);
						const newStyle = style.replace(bgMatch[0], `background-image:url(${dataUrl})`);
						el.setAttribute('style', newStyle);
					} catch (e) {
						// Skip if can't convert
					}
				}
			}
		}

		/**
		 * Collect all styles from stylesheets
		 */
		async collectAllStyles(doc) {
			let cssText = '';

			for (const sheet of doc.styleSheets) {
				try {
					for (const rule of sheet.cssRules) {
						cssText += rule.cssText + '\n';
					}
				} catch (e) {
					// Cross-origin stylesheet, try to fetch it
					if (sheet.href) {
						try {
							const response = await fetch(sheet.href);
							const text = await response.text();
							cssText += text + '\n';
						} catch (fetchError) {
							this.debug('warn', 'Could not fetch stylesheet:', sheet.href);
						}
					}
				}
			}

			return cssText;
		}

		/**
		 * Capture iframe content using html2canvas injection
		 */
		async captureIframeWithHtml2Canvas(iframe, ctx, finalCanvas, width, height, scale) {
			const iframeDoc = iframe.contentDocument;
			const iframeWin = iframe.contentWindow;

			// Check if html2canvas is already in iframe, if not inject it
			if (typeof iframeWin.html2canvas !== 'function') {
				this.debug('debug', 'Injecting html2canvas into iframe...');

				const script = iframeDoc.createElement('script');
				script.src = this.config.assets_url + 'js/html2canvas.min.js';

				await new Promise((resolve, reject) => {
					script.onload = resolve;
					script.onerror = reject;
					iframeDoc.head.appendChild(script);
				});

				this.debug('debug', 'html2canvas injected successfully');
			}

			if (typeof iframeWin.html2canvas === 'function') {
				this.debug('debug', 'Running html2canvas inside iframe...');

				const iframeCanvas = await iframeWin.html2canvas(iframeDoc.documentElement, {
					useCORS: true,
					allowTaint: true,
					backgroundColor: '#ffffff',
					scale: scale,
					logging: false,
					width: width,
					height: height,
					scrollX: 0,
					scrollY: 0,
					windowWidth: width,
					windowHeight: height
				});

				// Check if canvas has content
				const tempCtx = iframeCanvas.getContext('2d');
				const imageData = tempCtx.getImageData(0, 0, Math.min(100, iframeCanvas.width), Math.min(100, iframeCanvas.height));
				let hasContent = false;
				for (let i = 0; i < imageData.data.length; i += 4) {
					if (imageData.data[i] !== 255 || imageData.data[i+1] !== 255 || imageData.data[i+2] !== 255) {
						hasContent = true;
						break;
					}
				}

				if (hasContent) {
					ctx.drawImage(iframeCanvas, 0, 0, finalCanvas.width, finalCanvas.height);
					this.debug('debug', 'Iframe content captured successfully via injected html2canvas');
					return true;
				}
			}

			return false;
		}

		/**
		 * Convert image URL to data URL
		 */
		async imageToDataUrl(url) {
			return new Promise((resolve, reject) => {
				const img = new Image();
				img.crossOrigin = 'anonymous';
				img.onload = () => {
					try {
						const canvas = document.createElement('canvas');
						canvas.width = img.naturalWidth;
						canvas.height = img.naturalHeight;
						const ctx = canvas.getContext('2d');
						ctx.drawImage(img, 0, 0);
						resolve(canvas.toDataURL('image/png'));
					} catch (e) {
						reject(e);
					}
				};
				img.onerror = reject;
				img.src = url;
				// Timeout after 3 seconds
				setTimeout(() => reject(new Error('Timeout')), 3000);
			});
		}

		/**
		 * Generate filename for downloaded heatmap image
		 * Format: {page-slug}-{type}-{device}-{YYYY-MM-DD}.png
		 */
		generateFilename() {
			// Get page slug from URL or use page ID
			let pageSlug = 'heatmap';
			if (this.config.page_url) {
				try {
					const url = new URL(this.config.page_url);
					pageSlug = url.pathname.replace(/\//g, '-').replace(/^-|-$/g, '') || 'home';
				} catch (e) {
					pageSlug = 'page-' + this.pageId;
				}
			} else {
				pageSlug = 'page-' + this.pageId;
			}

			// Sanitize slug (remove special chars, limit length)
			pageSlug = pageSlug.toLowerCase()
				.replace(/[^a-z0-9-]/g, '-')
				.replace(/-+/g, '-')
				.substring(0, 30);

			const type = this.currentType || 'click';
			const device = this.currentDevice || 'desktop';
			const date = new Date().toISOString().split('T')[0]; // YYYY-MM-DD

			return `${pageSlug}-${type}-${device}-${date}.png`;
		}

		/**
		 * Enable download button (called after heatmap is rendered)
		 */
		enableDownloadButton() {
			$('#download-heatmap').prop('disabled', false);
			this.debug('debug', 'Download button enabled');
		}

		/**
		 * Disable download button (called when heatmap is loading)
		 */
		disableDownloadButton() {
			$('#download-heatmap').prop('disabled', true);
			this.debug('debug', 'Download button disabled');
		}
	}

	// Initialize on document ready
	$(document).ready(function() {
		if (window.optiHeatmapDetail && window.optiHeatmapDetail.page_id) {
			new HeatmapDetailController();
		}
	});

})(jQuery);
