/**
 * Frontend Page Title Override
 * Ensures page title is always taken from document.title for accurate tracking
 * 
 * @package OptiBehavior
 */

(function() {
	'use strict';

	// Override page title to use document.title
	if (typeof optiBehaviorHeatmapConfig !== "undefined") {
		optiBehaviorHeatmapConfig.page_title = document.title;
	}
})();

