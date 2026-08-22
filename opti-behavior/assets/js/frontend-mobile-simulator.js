/**
 * Frontend Mobile Simulator
 * Creates a mobile device frame for viewing heatmaps on mobile viewports
 * 
 * @package OptiBehavior
 */

(function() {
	'use strict';

	// Mobile viewport and simulator functionality
	if (typeof opti_behavior_heatmap !== 'undefined' && opti_behavior_heatmap.mobile_viewport) {
		document.addEventListener('DOMContentLoaded', function() {
			// Don't create simulator if we're already inside an iframe (popup simulator)
			if (window.self !== window.top) {
				// console.log('[Mobile Simulator] Already inside iframe, skipping simulator creation');
				return;
			}
			createMobileDeviceSimulator();
			// Ensure heatmap overlay is inside the device screen and visible
			(function placeHeatmap(){
				var screen = document.querySelector('.device-screen');
				var hm = document.querySelector('.optibehavior-heatmap-container');
				if(!screen || !hm){ setTimeout(placeHeatmap, 200); return; }
				screen.appendChild(hm);
				// Mark simulator active and constrain overlay to device
				document.body.classList.add('mobile-simulator-active');
				screen.style.position = 'relative';
				hm.style.position = 'absolute';
				hm.style.top = '0';
				hm.style.left = '0';
				hm.style.right = '0';
				hm.style.bottom = '0';
				hm.style.width = '100%';
				hm.style.height = '100%';
				hm.style.background = 'transparent';
				hm.style.pointerEvents = 'none';
				// Expand heatmap flow to full page height inside simulator and sync scroll
				var oc = document.querySelector('.original-content');
				function bindFlowSync(){
					var flow = hm.querySelector('.optibehavior-heatmap-flow');
					if (!flow || !oc) return false;

					// Ensure count bars cover the full scrollable page height
					var segmentHeight = 40;
					var requiredSegments = Math.ceil(oc.scrollHeight / segmentHeight);
					var existingBars = flow.querySelectorAll('.count-bar');

					// Add missing count bars if needed
					if (existingBars.length < requiredSegments) {
						for (var i = existingBars.length; i < requiredSegments; i++) {
							var bar = document.createElement('div');
							bar.className = 'count-bar';
							bar.textContent = '0';
							bar.style.top = (i * segmentHeight) + 'px';
							flow.appendChild(bar);
						}
					}

					// Height to cover full scrollable content
					flow.style.height = oc.scrollHeight + 'px';
					flow.style.position = 'relative';
					flow.style.zIndex = '2';
					// Also sync all heatmap canvases (do NOT change intrinsic size to avoid clearing drawing)
					var canvases = hm.querySelectorAll('canvas');
					var w = hm.clientWidth || (hm.getBoundingClientRect().width|0);
					canvases.forEach(function(cv){
						cv.style.position = 'absolute';
						cv.style.left = '0';
						cv.style.top = '0';
						cv.style.pointerEvents = 'none';
						cv.style.zIndex = '1';
						// only CSS sizing to fit the device; keep existing bitmap content
						cv.style.height = oc.scrollHeight + 'px';
						cv.style.width = w + 'px';
					});
					// Keep overlay aligned with inner scroll (bars + canvases)
					var sync = function(){
						var ty = 'translateY(' + (-oc.scrollTop) + 'px)';
						flow.style.transform = ty;
						canvases.forEach(function(cv){ cv.style.transform = ty; });
					};
					sync();
					oc.addEventListener('scroll', sync, { passive: true });
					// Update height on resize and ensure bars cover full height
					window.addEventListener('resize', function(){
						if (flow && oc) {
							flow.style.height = oc.scrollHeight + 'px';
							// Ensure count bars cover the full height after resize
							var segmentHeight = 40;
							var requiredSegments = Math.ceil(oc.scrollHeight / segmentHeight);
							var existingBars = flow.querySelectorAll('.count-bar');
							if (existingBars.length < requiredSegments) {
								for (var i = existingBars.length; i < requiredSegments; i++) {
									var bar = document.createElement('div');
									bar.className = 'count-bar';
									bar.textContent = '0';
									bar.style.top = (i * segmentHeight) + 'px';
									flow.appendChild(bar);
								}
							}
						}
						var cvs = hm.querySelectorAll('canvas');
						cvs.forEach(function(cv){ cv.style.height = oc.scrollHeight + 'px'; cv.style.width = (hm.clientWidth||hm.getBoundingClientRect().width|0) + 'px'; });
					});
					return true;
				}

					// Fallback: if native heatmap canvases are blank, paint minimal visible blobs so users see color
					(function(){
						try{
							var canvases = hm.querySelectorAll('canvas.heatmap-canvas');
							var hasColor=false;
							if (canvases && canvases.length){
								var ctx0 = canvases[0].getContext('2d');
								// scan a grid for any non-zero alpha
								outer: for (var y=0; y<Math.min(2000, canvases[0].height); y+=100){
									for (var x=20; x<Math.min(360, canvases[0].width); x+=50){
										if (ctx0.getImageData(x,y,1,1).data[3] > 0){ hasColor=true; break outer; }
									}
								}
							}
							if (!hasColor && window.opti_behavior_heatmap && window.opti_behavior_heatmap.data){
								var data = null; try{ data = JSON.parse(window.opti_behavior_heatmap.data); }catch(e){}
								if (data && Array.isArray(data.points) && data.points.length){
									var fb = document.createElement('canvas');
									fb.className = 'optibehavior-fallback-canvas';
									fb.style.position='absolute'; fb.style.left='0'; fb.style.top='0'; fb.style.pointerEvents='none'; fb.style.zIndex='3';
									var w = hm.clientWidth || (hm.getBoundingClientRect().width|0);
									fb.width = w; fb.height = oc.scrollHeight;
									fb.style.width = w + 'px'; fb.style.height = oc.scrollHeight + 'px';
									hm.appendChild(fb);
									var ctx = fb.getContext('2d');

									// Function to draw heatmap points
									var drawPoints = function(){
										ctx.clearRect(0, 0, fb.width, fb.height);
										ctx.save();
										ctx.globalAlpha = 1;
										data.points.forEach(function(p){
											var x = Math.round(p.x), y = Math.round(p.y);
											var rad = 28;
											var grd = ctx.createRadialGradient(x,y,0,x,y,rad);
											grd.addColorStop(0.00,'rgba(255,0,0,0.95)');
											grd.addColorStop(0.35,'rgba(255,255,0,0.75)');
											grd.addColorStop(0.70,'rgba(0,255,0,0.55)');
											grd.addColorStop(1.00,'rgba(0,0,255,0.35)');
											ctx.fillStyle = grd;
											ctx.beginPath(); ctx.arc(x,y,rad,0,Math.PI*2); ctx.closePath(); ctx.fill();
										});
										ctx.restore();
									};

									// Initial draw
									drawPoints();

									// scroll-sync fallback canvas
									var syncFb = function(){ fb.style.transform = 'translateY(' + (-oc.scrollTop) + 'px)'; };
									syncFb(); oc.addEventListener('scroll', syncFb, {passive:true});
									window.addEventListener('resize', function(){
										var w2 = hm.clientWidth || (hm.getBoundingClientRect().width|0);
										fb.width = w2; fb.height = oc.scrollHeight;
										fb.style.width = w2 + 'px'; fb.style.height = oc.scrollHeight + 'px';
										drawPoints(); // Redraw after resize
										syncFb();
									});
								}
							}
						}catch(err){ /* no-op */ }
					})();

				if (!bindFlowSync()){
					var mo = new MutationObserver(function(){ if (bindFlowSync()) { mo.disconnect(); } });
					mo.observe(hm, { childList: true, subtree: true });
				}
			})();
		});

		function createMobileDeviceSimulator() {
			// Get device dimensions from configuration
			const deviceWidth = window.opti_behaviorMobileSimulator.deviceWidth;
			const deviceHeight = window.opti_behaviorMobileSimulator.deviceHeight;

			// Set page title to indicate simulator mode
			document.title = '📱 Mobile Simulator - ' + document.title;

			// Create simulator styles
			const simulatorStyles = document.createElement('style');
			simulatorStyles.innerHTML =
				'body { margin: 0 !important; padding: 40px 20px !important; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; min-height: 100vh !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important; overflow-x: auto !important; }' +
				'.mobile-device-frame { width: ' + (deviceWidth + 40) + 'px; height: ' + (deviceHeight + 80) + 'px; margin: 0 auto; background: #1a1a1a; border-radius: 35px; padding: 20px; box-shadow: 0 0 0 8px #333, 0 0 0 12px #666, 0 20px 60px rgba(0,0,0,0.4), inset 0 0 0 2px #555; position: relative; overflow: hidden; }' +
				'.device-info { position: absolute; top: -30px; left: 50%; transform: translateX(-50%); color: #fff; font-size: 14px; font-weight: 600; }' +
				'.simulator-controls { position: absolute; top: -60px; left: 50%; transform: translateX(-50%); color: #fff; font-size: 12px; text-align: center; }' +
				'.device-screen { width: ' + deviceWidth + 'px; height: ' + deviceHeight + 'px; background: #fff; border-radius: 25px; overflow: hidden; position: relative; }' +
				'.device-notch { width: 150px; height: 25px; background: #000; border-radius: 0 0 15px 15px; position: absolute; top: 0; left: 50%; transform: translateX(-50%); z-index: 1000; }' +
				'.device-home-indicator { width: 134px; height: 5px; background: #fff; border-radius: 3px; position: absolute; bottom: 8px; left: 50%; transform: translateX(-50%); opacity: 0.6; }' +
				'.original-content { width: 100%; height: 100%; overflow: auto; background: #fff; }';
			document.head.appendChild(simulatorStyles);

			// Get original body content
			const originalContent = document.body.innerHTML;

			// Create device simulator structure
			document.body.innerHTML =
				'<div class="mobile-device-frame">' +
				'<div class="device-info">iPhone 12 Pro</div>' +
				'<div class="simulator-controls">' +
				'<div style="font-weight: bold; margin-bottom: 5px;">📱 Mobile Simulator</div>' +
				'<div>iPhone 8 — ' + deviceWidth + '×' + deviceHeight + '</div>' +
				'<div style="margin-top: 5px; font-size: 11px;">' + deviceWidth + ' × ' + deviceHeight + 'px</div>' +
				'</div>' +
				'<div class="device-screen">' +
				'<div class="device-notch"></div>' +
				'<div class="original-content">' + originalContent + '</div>' +
				'<div class="device-home-indicator"></div>' +
				'</div>' +
				'</div>';

			// Set viewport for mobile simulation
			let viewport = document.querySelector('meta[name=viewport]');
			if (!viewport) {
				viewport = document.createElement('meta');
				viewport.name = 'viewport';
				document.head.appendChild(viewport);
			}
			viewport.content = 'width=' + deviceWidth + ', initial-scale=1.0, user-scalable=no';
		}
	}
})();

