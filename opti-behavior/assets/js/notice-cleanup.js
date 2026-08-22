/**
 * Notice Cleanup Script
 * Removes third-party admin notices from opti-behavior plugin pages
 * 
 * @package OptiBehavior
 */

(function() {
	'use strict';

	/**
	 * Remove third-party admin notices
	 */
	function removeThirdPartyNotices() {
		// Selectors for common notice patterns
		const noticeSelectors = [
			'.notice:not([class*="opti-behavior"])',
			'.updated:not([class*="opti-behavior"])',
			'.error:not([class*="opti-behavior"])',
			'.update-nag',
			'[data-dismissible]',
			'.notice-error',
			'.notice-warning',
			'.notice-success',
			'.notice-info',
			'.wp-pointer',
			'div[id^="message"]:not([id*="opti-behavior"])',
			'div[class*="notice"]:not([class*="opti-behavior"]):not([class^="ob-"])',
			'div[class*="message"]:not([class*="opti-behavior"]):not([class^="ob-"])',
			'div[class*="notification"]:not([class*="opti-behavior"]):not([class^="ob-"])',
			'div[class*="alert"]:not([class*="opti-behavior"]):not([class^="ob-"])',
			// Plugin-specific
			'.jetpack-message',
			'.rank-math-notice',
			'.elementor-message',
			'.woocommerce-message',
			'.yoast-notification',
			'.acf-notice',
			'.wordfence-notice'
		];

		// Remove notices from wpbody-content
		const wpbodyContent = document.getElementById('wpbody-content');
		if (wpbodyContent) {
			noticeSelectors.forEach(function(selector) {
				const notices = wpbodyContent.querySelectorAll(selector);
				notices.forEach(function(notice) {
					// Double-check it's not an opti-behavior notice (full name or ob- prefix)
					// Also preserve WordPress settings_errors() output — it always carries the
					// 'settings-error' class and no third-party plugin uses that class.
					if (!notice.className.includes('opti-behavior') &&
						!notice.id.includes('opti-behavior') &&
						!notice.className.includes('settings-error') &&
						!notice.className.startsWith('ob-') &&
						!notice.className.includes(' ob-')) {
						notice.remove();
					}
				});
			});
		}
	}

	// Run immediately
	removeThirdPartyNotices();

	// Run after DOM is fully loaded
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', removeThirdPartyNotices);
	}

	// Run after window load (catches late-loading notices)
	window.addEventListener('load', function() {
		removeThirdPartyNotices();

		// Continue monitoring for 5 seconds after load
		let checkCount = 0;
		const maxChecks = 10;
		const checkInterval = setInterval(function() {
			removeThirdPartyNotices();
			checkCount++;
			if (checkCount >= maxChecks) {
				clearInterval(checkInterval);
			}
		}, 500);
	});

	// Monitor for dynamically added notices using MutationObserver
	if (window.MutationObserver) {
		const observer = new MutationObserver(function(mutations) {
			let shouldCheck = false;
			mutations.forEach(function(mutation) {
				if (mutation.addedNodes.length > 0) {
					shouldCheck = true;
				}
			});
			if (shouldCheck) {
				removeThirdPartyNotices();
			}
		});

		// Observe wpbody-content for changes
		const wpbodyContent = document.getElementById('wpbody-content');
		if (wpbodyContent) {
			observer.observe(wpbodyContent, {
				childList: true,
				subtree: true
			});
		}
	}
})();

