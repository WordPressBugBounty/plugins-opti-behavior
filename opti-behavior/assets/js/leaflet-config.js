/**
 * Leaflet Configuration
 * Configures Leaflet image path for marker icons
 * 
 * @package OptiBehavior
 */

(function() {
	'use strict';

	// Configure Leaflet image path
	if (typeof L !== "undefined" && L.Icon && L.Icon.Default) {
		// Image path will be set via wp_localize_script()
		if (typeof opti_behaviorLeafletConfig !== "undefined" && opti_behaviorLeafletConfig.imagePath) {
			L.Icon.Default.imagePath = opti_behaviorLeafletConfig.imagePath;
		}
	}
})();

