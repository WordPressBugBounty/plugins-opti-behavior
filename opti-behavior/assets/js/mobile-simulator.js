/**
 * Mobile Device Simulator
 * Opens a popup window with a mobile device frame for viewing heatmaps
 * 
 * @package OptiBehavior
 */

// Mobile simulator functionality - defined at global scope
function openMobileSimulator(url) {
	var S = (window.optiBehaviorMobileSimulator && window.optiBehaviorMobileSimulator.strings) ? window.optiBehaviorMobileSimulator.strings : {};
	// Remove mobile_view, vw, and vh parameters from URL since the popup provides the frame
	let cleanUrl = url.replace(/&mobile_view=1/g, '')
	                  .replace(/&vw=\d+/g, '')
	                  .replace(/&vh=\d+/g, '');

	// Clean up any double ampersands or trailing ampersands
	cleanUrl = cleanUrl.replace(/&&+/g, '&').replace(/&$/, '');

	// Create mobile device simulator window
	const simulatorWindow = window.open(
		'',
		'mobile_simulator',
		'width=500,height=900,scrollbars=no,resizable=yes,toolbar=no,menubar=no,location=no,status=no'
	);

	if (simulatorWindow) {
		// Create mobile device simulator HTML
		const simulatorHTML = `
			<!DOCTYPE html>
			<html lang="en">
			<head>
				<meta charset="UTF-8">
				<meta name="viewport" content="width=device-width, initial-scale=1.0">
				<title>📱 ${S.mobileDeviceSimulator || 'Mobile Device Simulator'}</title>
				<style>
					* {
						margin: 0;
						padding: 0;
						box-sizing: border-box;
					}

					body {
						background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
						font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
						display: flex;
						justify-content: center;
						align-items: center;
						min-height: 100vh;
						padding: 20px;
					}

					.device-container {
						position: relative;
						width: 415px;
						height: 747px;
						background: #1a1a1a;
						border-radius: 35px;
						padding: 20px;
						box-shadow:
							0 0 0 8px #333,
							0 0 0 12px #666,
							0 20px 60px rgba(0,0,0,0.4),
							inset 0 0 0 2px #555;
					}

					.device-info {
						position: absolute;
						top: -40px;
						left: 0;
						right: 0;
						text-align: center;
						color: white;
						font-size: 14px;
						font-weight: 600;
						text-shadow: 0 1px 3px rgba(0,0,0,0.3);
					}

					.device-screen {
						width: 375px;
						height: 667px;
						background: white;
						border-radius: 25px;
						overflow: hidden;
						position: relative;
						box-shadow: inset 0 0 0 1px rgba(255,255,255,0.1);
					}

					.device-notch {
						position: absolute;
						top: 0;
						left: 50%;
						transform: translateX(-50%);
						width: 150px;
						height: 25px;
						background: #1a1a1a;
						border-radius: 0 0 15px 15px;
						z-index: 1000;
					}

					.device-home-indicator {
						position: absolute;
						bottom: 8px;
						left: 50%;
						transform: translateX(-50%);
						width: 134px;
						height: 5px;
						background: rgba(255,255,255,0.3);
						border-radius: 3px;
						z-index: 1000;
					}

					.mobile-iframe {
						width: 100%;
						height: 100%;
						border: none;
						border-radius: 25px;
					}

					.simulator-controls {
						position: fixed;
						top: 10px;
						right: 10px;
						background: rgba(0,0,0,0.8);
						color: white;
						padding: 10px 15px;
						border-radius: 8px;
						font-size: 12px;
						z-index: 10000;
						backdrop-filter: blur(10px);
					}

					.close-button {
						position: fixed;
						top: 10px;
						left: 10px;
						background: rgba(255,0,0,0.8);
						color: white;
						border: none;
						padding: 8px 12px;
						border-radius: 6px;
						font-size: 12px;
						cursor: pointer;
						z-index: 10000;
						backdrop-filter: blur(10px);
					}

					.close-button:hover {
						background: rgba(255,0,0,1);
					}
				</style>
			</head>
			<body>
				<div class="simulator-controls">
					📱 ${S.mobileDeviceSimulator || 'Mobile Device Simulator'}<br>
					<small>375 × 667px</small>
				</div>

				<button class="close-button" onclick="window.close()">✕ ${S.close || 'Close'}</button>

				<div class="device-container">
					<div class="device-info">iPhone 12 Pro</div>
					<div class="device-screen">
						<div class="device-notch"></div>
						<iframe class="mobile-iframe" src="${cleanUrl}" title="${S.mobileHeatmap || 'Mobile Heatmap'}"></iframe>
						<div class="device-home-indicator"></div>
					</div>
				</div>
			</body>
			</html>
		`;

		// Write the HTML to the new window
		simulatorWindow.document.write(simulatorHTML);
		simulatorWindow.document.close();
		simulatorWindow.focus();
	}
}

// Heatmap viewer click handlers
document.addEventListener('DOMContentLoaded', function() {
	let w;
	document.querySelectorAll('.optibehavior-view').forEach((e) => {
		e.addEventListener('click', (ev) => {
			if ( w && w.outerWidth !== parseInt( e.dataset.width ) ) {
				w.close();
			}
			w = window.open(e.dataset.url, 'opti-behavior Heatmap Viewer', `scrollbars=yes, resizable=no, location=yes, width=${e.dataset.width}, height=600`);
			ev.preventDefault();
		}, { passive: false });
	});
});

