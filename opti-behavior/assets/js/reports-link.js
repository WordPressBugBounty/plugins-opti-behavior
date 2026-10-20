/**
 * « Site report » link of the Traffic and Smart Insights headers (OPT-1342):
 * at the click, the Reports screen opens with the period shown on the screen
 * (a quick period the report knows, else its two dates). No request.
 */
( function () {
	'use strict';

	var NAMED = [ 'last7days', 'last14days', 'last30days', 'last90days' ];
	var FIELDS = {
		traffic: { period: '#dashboard-period', start: '#start-date', end: '#end-date', spam: '#exclude-spam-toggle' },
		insights: { period: '.ob-smart-insights-period', start: '.ob-smart-insights-start-date', end: '.ob-smart-insights-end-date', spam: '#smart-insights-exclude-spam-toggle' },
	};

	function ymd( date ) {
		var mm = date.getMonth() + 1;
		var dd = date.getDate();
		return date.getFullYear() + '-' + ( mm < 10 ? '0' : '' ) + mm + '-' + ( dd < 10 ? '0' : '' ) + dd;
	}

	function read( selector ) {
		var el = selector ? document.querySelector( selector ) : null;
		return el && el.value ? String( el.value ) : '';
	}

	/* Days of a named period the report does not know, in the browser's calendar. */
	function range( period ) {
		var now = new Date();
		var today = new Date( now.getFullYear(), now.getMonth(), now.getDate() );
		if ( 'today' === period ) {
			return [ today, today ];
		}
		if ( 'yesterday' === period ) {
			var y = new Date( today.getFullYear(), today.getMonth(), today.getDate() - 1 );
			return [ y, y ];
		}
		if ( 'thismonth' === period ) {
			return [ new Date( today.getFullYear(), today.getMonth(), 1 ), today ];
		}
		return null;
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target && e.target.closest ? e.target.closest( 'a[data-ob-site-report]' ) : null;
		if ( ! link ) {
			return;
		}
		var fields = FIELDS[ link.getAttribute( 'data-ob-site-report' ) ];
		if ( ! fields ) {
			return;
		}
		var url;
		try {
			url = new URL( link.href, window.location.href );
		} catch ( err ) {
			return;
		}
		var period = read( fields.period );
		var start = read( fields.start );
		var end = read( fields.end );
		var days = range( period );
		[ 'period', 'from', 'to' ].forEach( function ( key ) {
			url.searchParams.delete( key );
		} );
		if ( -1 !== NAMED.indexOf( period ) ) {
			url.searchParams.set( 'period', period );
		} else if ( days ) {
			url.searchParams.set( 'from', ymd( days[ 0 ] ) );
			url.searchParams.set( 'to', ymd( days[ 1 ] ) );
		} else if ( /^\d{4}-\d{2}-\d{2}$/.test( start ) && /^\d{4}-\d{2}-\d{2}$/.test( end ) ) {
			url.searchParams.set( 'from', start );
			url.searchParams.set( 'to', end );
		}
		var spam = fields.spam ? document.querySelector( fields.spam ) : null;
		if ( spam && spam.hasAttribute( 'aria-pressed' ) ) {
			if ( 'false' === spam.getAttribute( 'aria-pressed' ) ) {
				url.searchParams.set( 'exclude_spam', '0' );
			} else {
				url.searchParams.delete( 'exclude_spam' );
			}
		}
		link.href = url.toString();
	} );
} )();
