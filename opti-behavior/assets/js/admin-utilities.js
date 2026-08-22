/**
 * Admin Utilities Scripts
 * Handles confirmation dialogs and other common admin UI interactions
 * 
 * @package OptiBehavior
 */

(function() {
	'use strict';

	// Handle confirmation buttons
	document.addEventListener('click', function(e) {
		var btn = e.target.closest('.optibehavior-confirm-btn');
		if (btn) {
			var msg = btn.getAttribute('data-confirm');
			if (msg && !confirm(msg)) {
				e.preventDefault();
				e.stopPropagation();
				return false;
			}
		}
	});

})();

