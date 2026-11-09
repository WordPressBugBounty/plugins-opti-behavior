/**
 * Opti-Behavior: "Calculating statistics…" indicator, shared by every screen with
 * filters (Free + Pro).
 *
 * After a filter is used, the figures can take many seconds on a large site and
 * the old ones stay on screen: the owner reads "nothing happens" or "no data".
 *
 * Rule: a filter control arms the indicator for a short moment. It is shown only
 * if that control really starts a request (admin-ajax) or leaves the page (screens
 * whose filter reloads), and stays until the last request of the chain has ended.
 * It never decides anything about the figures and sends nothing.
 *
 * Config: window.optiBehaviorLoadingConfig = { text }.
 * Test: node tests/filter-loading-indicator-test.js
 */
( function ( window, document ) {
	'use strict';

	if ( window.optiBehaviorLoadingReady ) {
		return;
	}
	window.optiBehaviorLoadingReady = true;

	var ARM_MS = 800; // A control must start its request within this moment.
	var SHOW_AFTER_MS = 200; // A cached answer never makes the indicator blink.
	var QUIET_MS = 600; // Traffic loads its widgets list after list: wait for the next one.
	var MAX_MS = 180000; // Never a spinner forever, whatever happens to a request.

	var BUTTONS = [
		'#apply-advanced-filters', '#reset-advanced-filters', '#apply-range', '#apply-custom-range',
		'#apply-heatmap-range', '#funnel-detail-apply-range', '#funnel-detail-refresh',
		'#refresh-dashboard', '#refresh-heatmap', '#reset-filters', '#apply-custom-date',
		'#apply-date-filter', '#apply-journey-filter', '#clear-all-filters', '#fa-apply-filter',
		'#fa-refresh', '#refresh-errors', '#refresh-journey', '#refresh-recordings', '.device-btn',
		'[id$="exclude-spam-toggle"]', '#journey-exclude-spam',
		'[data-optibehavior-table] th', '[data-paging] .pagination-btn'
	].join( ',' );

	// A select of these places only prepares something: no figure is being recalculated.
	var NOT_A_FILTER = '#advanced-filters-panel, #errors-advanced-filters-panel, dialog, [role="dialog"], [aria-modal="true"], .opti-ab-modal';

	var PANELS = '.advanced-filters-panel, [id$="advanced-filters-panel"]';

	var optionsInFlight = 0;
	var optionsMaxTimer = null;
	var armedUntil = 0;
	var inFlight = 0;
	var shown = false;
	var box = null;
	var showTimer = null;
	var hideTimer = null;
	var maxTimer = null;

	function bodyText( body ) {
		if ( ! body ) {
			return '';
		}
		if ( 'string' === typeof body ) {
			return body;
		}
		if ( 'function' === typeof body.get ) { // FormData, URLSearchParams.
			return 'action=' + ( body.get( 'action' ) || '' ) + '&widget=' + ( body.get( 'widget' ) || '' );
		}
		return '';
	}

	/** A request of the screen's figures, not a poll that runs by itself. */
	function isDataRequest( url, body ) {
		if ( -1 === String( url || '' ).indexOf( 'admin-ajax.php' ) ) {
			return false;
		}
		return ! /action=heartbeat|widget=realtime|notification/.test( String( url ) + '&' + bodyText( body ) );
	}

	/** The figures of the lists of a filter panel ("Direct (1499)"). */
	function isOptionsRequest( url, body ) {
		return /filter_options/.test( String( url || '' ) + '&' + bodyText( body ) );
	}

	/**
	 * Opening a filter panel starts its own request, 8 s on a large site, and the
	 * lists stay without figures meanwhile: the panel itself says it is coming.
	 */
	function setOptionsLoading( loading ) {
		document.documentElement.classList.toggle( 'ob-filter-options-loading', loading );
		Array.prototype.forEach.call( document.querySelectorAll( PANELS ), function ( panel ) {
			var line = panel.querySelector( '.ob-filter-options-status' );
			if ( ! loading ) {
				if ( line ) {
					line.parentNode.removeChild( line );
				}
				return;
			}
			if ( line ) {
				return;
			}
			var config = window.optiBehaviorLoadingConfig || {};
			line = document.createElement( 'div' );
			line.className = 'ob-filter-options-status';
			line.setAttribute( 'role', 'status' );
			var icon = document.createElement( 'span' );
			icon.className = 'ob-stats-loading__icon';
			icon.setAttribute( 'aria-hidden', 'true' );
			var text = document.createElement( 'span' );
			text.textContent = config.optionsText || '';
			line.appendChild( icon );
			line.appendChild( text );
			panel.insertBefore( line, panel.firstChild );
		} );
	}

	function optionsStarted() {
		optionsInFlight++;
		setOptionsLoading( true );
		clearTimeout( optionsMaxTimer );
		optionsMaxTimer = setTimeout( function () {
			optionsInFlight = 0;
			setOptionsLoading( false );
		}, MAX_MS );
	}

	function optionsEnded() {
		optionsInFlight = Math.max( 0, optionsInFlight - 1 );
		if ( 0 === optionsInFlight ) {
			clearTimeout( optionsMaxTimer );
			setOptionsLoading( false );
		}
	}

	function ensureBox() {
		if ( box || ! document.body ) {
			return box;
		}
		var config = window.optiBehaviorLoadingConfig || {};
		box = document.createElement( 'div' );
		box.className = 'ob-stats-loading';
		box.setAttribute( 'role', 'status' );
		box.setAttribute( 'aria-live', 'polite' );
		box.hidden = true;
		var icon = document.createElement( 'span' );
		icon.className = 'ob-stats-loading__icon';
		icon.setAttribute( 'aria-hidden', 'true' );
		var text = document.createElement( 'span' );
		text.className = 'ob-stats-loading__text';
		text.textContent = config.text || '';
		box.appendChild( icon );
		box.appendChild( text );
		document.body.appendChild( box );
		return box;
	}

	function show() {
		if ( shown || ( window.optiBehaviorLoadingConfig || {} ).noStatsBox ) {
			return;
		}
		if ( ! ensureBox() ) {
			return;
		}
		shown = true;
		box.hidden = false;
		document.documentElement.classList.add( 'ob-stats-updating' );
	}

	function hide() {
		clearTimeout( showTimer );
		clearTimeout( hideTimer );
		clearTimeout( maxTimer );
		showTimer = hideTimer = maxTimer = null;
		shown = false;
		inFlight = 0;
		armedUntil = 0;
		if ( box ) {
			box.hidden = true;
		}
		document.documentElement.classList.remove( 'ob-stats-updating' );
	}

	function arm() {
		armedUntil = Date.now() + ARM_MS;
	}

	function requestStarted() {
		inFlight++;
		clearTimeout( hideTimer );
		hideTimer = null;
		if ( ! shown && ! showTimer ) {
			showTimer = setTimeout( function () {
				showTimer = null;
				if ( inFlight > 0 ) {
					show();
				}
			}, SHOW_AFTER_MS );
		}
		if ( ! maxTimer ) {
			maxTimer = setTimeout( hide, MAX_MS );
		}
	}

	function requestEnded() {
		inFlight = Math.max( 0, inFlight - 1 );
		if ( inFlight > 0 ) {
			return;
		}
		clearTimeout( hideTimer );
		hideTimer = setTimeout( function () {
			if ( 0 === inFlight ) {
				hide();
			}
		}, QUIET_MS );
	}

	/** The chain of one filter: armed by the control, or following a counted request. */
	function shouldTrack( url, body ) {
		var following = inFlight > 0 || null !== hideTimer;
		return ( following || Date.now() <= armedUntil ) && isDataRequest( url, body ) && ! isOptionsRequest( url, body );
	}

	if ( 'function' === typeof window.fetch ) {
		var nativeFetch = window.fetch;
		window.fetch = function ( input, init ) {
			var url = input && input.url ? input.url : input;
			var body = init ? init.body : null;
			var result = nativeFetch.apply( this, arguments );
			if ( isOptionsRequest( url, body ) ) {
				optionsStarted();
				result.then( optionsEnded, optionsEnded );
			} else if ( shouldTrack( url, body ) ) {
				requestStarted();
				var ended = false;
				var end = function () {
					if ( ! ended ) {
						ended = true;
						requestEnded();
					}
				};
				result.then( end, end );
			}
			return result;
		};
	}

	if ( window.XMLHttpRequest && window.XMLHttpRequest.prototype ) { // jQuery screens.
		var proto = window.XMLHttpRequest.prototype;
		var nativeOpen = proto.open;
		var nativeSend = proto.send;
		proto.open = function ( method, url ) {
			this.obLoadingUrl = url;
			return nativeOpen.apply( this, arguments );
		};
		proto.send = function ( body ) {
			if ( isOptionsRequest( this.obLoadingUrl, body ) ) {
				optionsStarted();
				this.addEventListener( 'loadend', optionsEnded );
			} else if ( shouldTrack( this.obLoadingUrl, body ) ) {
				requestStarted();
				this.addEventListener( 'loadend', requestEnded );
			}
			return nativeSend.apply( this, arguments );
		};
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( target && target.closest && target.closest( BUTTONS ) ) {
			arm();
		}
	}, true );

	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( target && 'SELECT' === target.tagName && ! target.closest( NOT_A_FILTER ) ) {
			arm();
		}
	}, true );

	// Screens whose filter reloads the page: the new page takes the time.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || event.defaultPrevented || '_blank' === form.getAttribute( 'target' ) ) {
			return;
		}
		if ( 'get' === String( form.getAttribute( 'method' ) || 'get' ).toLowerCase() ) {
			arm();
		}
	} );

	window.addEventListener( 'beforeunload', function () {
		if ( Date.now() <= armedUntil ) {
			show();
			if ( ! maxTimer ) {
				maxTimer = setTimeout( hide, MAX_MS );
			}
		}
	} );

	// Back button: the browser can bring the page back as it was left.
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			hide();
		}
	} );
}( window, document ) );
