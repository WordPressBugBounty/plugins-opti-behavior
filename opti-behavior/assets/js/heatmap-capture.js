/**
 * Heatmap Capture Script
 *
 * This script runs on the frontend when the page is opened with capture mode parameters.
 * It captures the page content along with the heatmap overlay and sends the result
 * back to the parent window via postMessage.
 */
(function() {
	'use strict';

	// Check if we're in capture mode
	const urlParams = new URLSearchParams(window.location.search);
	if (urlParams.get('opti_capture_mode') !== '1') {
		return; // Not in capture mode, do nothing
	}

	// Get capture parameters
	const width = parseInt(urlParams.get('opti_width')) || document.body.scrollWidth;
	const height = parseInt(urlParams.get('opti_height')) || document.body.scrollHeight;
	const scale = parseInt(urlParams.get('opti_scale')) || 2;
	const assetsUrl = urlParams.get('opti_assets_url') || '';
	const captureId = urlParams.get('opti_capture_id');

	// Get heatmap data from localStorage (shared across same origin)
	let heatmapDataUrl = null;
	if (captureId) {
		try {
			heatmapDataUrl = localStorage.getItem(captureId);
			// Clean up
			localStorage.removeItem(captureId);
		} catch (e) {
			if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.warning('[Opti-Capture] Could not access localStorage: ' + e, 'heatmap-capture');
		}
	}

	if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('[Opti-Capture] Starting capture mode', 'heatmap-capture', { width, height, scale, hasHeatmap: !!heatmapDataUrl });

	// Wait for page to fully load, then wait for the layout to SETTLE instead of
	// a single fixed delay: dynamic pages (lazy lists, async widgets) keep
	// changing height after `load`, which used to bake spinners / wrong page
	// length into the export. Height is polled until two consecutive readings
	// match (±2px) or a hard 8s budget elapses.
	window.addEventListener('load', function() {
		var lastH = -1;
		var stableCount = 0;
		var started = Date.now();

		function measure() {
			return Math.max(
				document.body ? document.body.scrollHeight : 0,
				document.documentElement ? document.documentElement.scrollHeight : 0
			);
		}

		function waitForSettle() {
			var h = measure();
			if (Math.abs(h - lastH) <= 2) {
				stableCount++;
			} else {
				stableCount = 0;
			}
			lastH = h;

			if (stableCount >= 2 || (Date.now() - started) > 8000) {
				startCapture();
				return;
			}
			setTimeout(waitForSettle, 400);
		}

		// Keep a small initial delay for images/fonts before the settle loop.
		setTimeout(waitForSettle, 600);
	});

	async function startCapture() {
		try {
			if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('[Opti-Capture] Loading html2canvas...', 'heatmap-capture');

			// Load html2canvas if not already loaded
			if (typeof html2canvas !== 'function') {
				await loadScript(assetsUrl + 'js/html2canvas.min.js');
			}

			if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('[Opti-Capture] html2canvas loaded', 'heatmap-capture');

			// Add heatmap overlay if we have it
			if (heatmapDataUrl) {
				if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('[Opti-Capture] Adding heatmap overlay...', 'heatmap-capture');

				const overlay = document.createElement('div');
				overlay.id = 'opti-heatmap-capture-overlay';
				overlay.style.cssText = `
					position: absolute;
					top: 0;
					left: 0;
					width: ${width}px;
					height: ${height}px;
					pointer-events: none;
					z-index: 999999;
				`;

				const heatmapImg = document.createElement('img');
				heatmapImg.src = heatmapDataUrl;
				heatmapImg.style.cssText = 'width: 100%; height: 100%;';
				overlay.appendChild(heatmapImg);

				// Make sure body is positioned relatively
				document.body.style.position = 'relative';
				document.body.appendChild(overlay);

				// Wait for heatmap image to load
				await new Promise((resolve) => {
					if (heatmapImg.complete) {
						resolve();
					} else {
						heatmapImg.onload = resolve;
						heatmapImg.onerror = resolve; // Continue even if image fails
					}
				});

				if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('[Opti-Capture] Heatmap overlay added', 'heatmap-capture');
			}

			// Small delay for DOM to settle
			await new Promise(resolve => setTimeout(resolve, 500));

			if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('[Opti-Capture] Starting html2canvas capture...', 'heatmap-capture');

			// Capture with html2canvas
			const canvas = await html2canvas(document.body, {
				useCORS: true,
				allowTaint: true,
				backgroundColor: '#ffffff',
				scale: scale,
				logging: false,
				width: width,
				height: height,
				windowWidth: width,
				windowHeight: height,
				scrollX: 0,
				scrollY: 0,
				onclone: function(clonedDoc) {
					// Remove the capture parameters from any links in the cloned document
					const links = clonedDoc.querySelectorAll('a[href*="opti_capture_mode"]');
					links.forEach(link => {
						const url = new URL(link.href);
						url.searchParams.delete('opti_capture_mode');
						url.searchParams.delete('opti_width');
						url.searchParams.delete('opti_height');
						url.searchParams.delete('opti_scale');
						url.searchParams.delete('opti_assets_url');
						url.searchParams.delete('opti_capture_id');
						link.href = url.toString();
					});
				}
			});

			const dataUrl = canvas.toDataURL('image/png');
			if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('[Opti-Capture] Capture complete, sending result to opener...', 'heatmap-capture');

			// Send result back to opener
			if (window.opener) {
				window.opener.postMessage({
					type: 'heatmapCaptureResult',
					dataUrl: dataUrl
				}, '*');
			}

			// Close this window after a short delay
			setTimeout(() => {
				window.close();
			}, 500);

		} catch (error) {
			if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('[Opti-Capture] Capture failed: ' + error, 'heatmap-capture');

			// Send error back to opener
			if (window.opener) {
				window.opener.postMessage({
					type: 'heatmapCaptureResult',
					dataUrl: null,
					error: error.message
				}, '*');
			}

			// Close this window
			setTimeout(() => {
				window.close();
			}, 500);
		}
	}

	function loadScript(src) {
		return new Promise((resolve, reject) => {
			const script = document.createElement('script');
			script.src = src;
			script.onload = resolve;
			script.onerror = reject;
			document.head.appendChild(script);
		});
	}

})();
