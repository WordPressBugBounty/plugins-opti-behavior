/**
 * Waiting page of a report (OPT-1862), printed by
 * Opti_Behavior_Report::render_wait_page().
 *
 * Asks admin-ajax for the job of the report (`build` without token), then
 * builds it (`build` with the token, a long request) while it reads the
 * job state every 1.5 s (`status`) and moves the four steps. Done = opens
 * the stored document in this tab (`&job=<token>`). An error answer, a
 * server error or no new step for 5 minutes = the error box + "Try again".
 * With `cfg.capture` (filter opti_behavior_report_wait_capture), a hidden frame
 * runs first, as the first step; whatever it answers, or at its time limit, the
 * report is built.
 * No library, no outside call.
 */
( function () {
	'use strict';

	var box = document.querySelector( '[data-ob-report-wait]' );
	if ( ! box || ! window.fetch || ! window.URLSearchParams ) {
		return;
	}
	var cfg;
	try {
		cfg = JSON.parse( box.getAttribute( 'data-ob-config' ) || '{}' );
	} catch ( e ) {
		return;
	}
	var texts = cfg.texts || {};
	var steps = box.querySelectorAll( '[data-ob-step]' );
	var count = box.querySelector( '.obw-count' );
	var errBox = box.querySelector( '.obw-error' );
	var errText = box.querySelector( '[data-ob-error]' );
	var retry = box.querySelector( '[data-ob-retry]' );
	var bar = box.querySelector( '.obw-bar' );
	var fill = box.querySelector( '.obw-bar__fill' );
	var errDefault = errText ? errText.textContent : '';

	var POLL_MS = 1500;
	var STALL_MS = 5 * 60 * 1000;
	// OPT-2981: the shown place in the list moves on its own between two
	// answers (never past the step the server is in), and the steps left are
	// ticked one by one before the report opens.
	var TICK_MS = 150;
	var TICK_STEP = 0.006;
	var CASCADE_MS = 350;
	var STEP_CEILING = 0.92;
	var token = '';
	var timer = 0;
	var tick = 0;
	var last = '';
	var lastChange = 0;
	var over = false;
	// Place in the list, in steps (2.4 = third step at 40 %). Only grows.
	var aim = 0;
	var shown = 0;
	var shownPct = 0;
	// `cfg.capture` adds a first step: the steps of the server start after it.
	var offset = cfg.capture && cfg.capture.url ? 1 : 0;

	function endpoint( action, query ) {
		var q = new URLSearchParams( query || {} );
		q.set( 'action', action );
		return cfg.ajax + ( cfg.ajax.indexOf( '?' ) === -1 ? '?' : '&' ) + q.toString();
	}

	// The action rides in the address (see Opti_Behavior_Report::ajax_build()).
	function post( fields ) {
		var body = new URLSearchParams();
		body.set( '_wpnonce', cfg.nonce );
		Object.keys( fields ).forEach( function ( k ) {
			body.set( k, fields[ k ] );
		} );
		return fetch( endpoint( cfg.build ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( read );
	}

	function read( res ) {
		return res.json().then( function ( json ) {
			return { status: res.status, json: json };
		}, function () {
			return { status: res.status, json: null };
		} );
	}

	function code( r ) {
		return r && r.json && r.json.data && r.json.data.code ? r.json.data.code : '';
	}

	// Bar = steps reached on the server; inside "problems" the written
	// sections move it on. OPT-2981: it NEVER goes back (a job answering
	// `queued` between two page batches used to put it at 0 again).
	function progress( pct ) {
		if ( ! fill || ! bar ) {
			return;
		}
		shownPct = Math.max( shownPct, Math.round( Math.max( 0, Math.min( 100, pct ) ) * 10 ) / 10 );
		fill.style.width = shownPct + '%';
		bar.setAttribute( 'aria-valuenow', String( Math.round( shownPct ) ) );
	}

	/**
	 * Place of a job answer in the list, in steps (OPT-2981).
	 *
	 * Scoring the pages of the document is real work on two steps (reading the
	 * sessions, then the clicks and scroll), so it fills the first two: the
	 * list moves while the pages are read instead of waiting for the end.
	 */
	function place( view ) {
		var stage = Math.max( 0, ( parseInt( view.stage, 10 ) || 0 ) + offset );
		var total = parseInt( view.total, 10 ) || 0;
		var part  = total > 0 ? Math.max( 0, Math.min( 1, ( parseInt( view.done, 10 ) || 0 ) / total ) ) : 0;

		if ( 'done' === view.state || 'finish' === view.step ) {
			return steps.length;
		}
		if ( 'pages' === view.step ) {
			return Math.min( offset + part * 2, steps.length - 1 );
		}
		if ( 'section' === view.step && total > 0 ) {
			return Math.min( stage + part, steps.length - 1 );
		}
		return Math.min( stage, steps.length - 1 );
	}

	// One step ticked at a time, a ticked step stays ticked.
	function paint() {
		var now = Math.max( 0, Math.min( steps.length - 1, Math.floor( shown ) ) );
		Array.prototype.forEach.call( steps, function ( li, i ) {
			li.classList.toggle( 'is-done', i < now );
			li.classList.toggle( 'is-now', i === now );
		} );
		progress( steps.length ? shown / steps.length * 100 : 0 );
	}

	// Server quiet: crawl inside the step it is in, never into the next one.
	function crawl() {
		tick = window.setTimeout( function () {
			tick = 0;
			if ( over ) {
				return;
			}
			var ceiling = Math.min( Math.floor( shown ) + STEP_CEILING, steps.length - ( 1 - STEP_CEILING ) );
			if ( shown < ceiling ) {
				shown = Math.min( ceiling, shown + TICK_STEP );
				paint();
			}
			crawl();
		}, TICK_MS );
	}

	function show( view ) {
		aim = Math.max( aim, place( view ) );
		// A step the server really finished is ticked at once; what is left of
		// the step in progress is crawled by crawl(). The end is NOT ticked
		// here: cascade() walks the steps left one by one (OPT-2981).
		if ( 'done' !== view.state && 'finish' !== view.step ) {
			shown = Math.max( shown, aim );
		}
		paint();
		var counted = ( 'section' === view.step || 'pages' === view.step ) && view.total > 0;
		if ( count ) {
			// OPT-2876: the pages being scored count like the sections, so the
			// reader sees "Page 15 of 20" instead of a still bar.
			var pattern = 'pages' === view.step ? texts.pages : texts.section;
			count.textContent = counted && pattern
				? pattern.replace( '%1$s', String( view.done ) ).replace( '%2$s', String( view.total ) )
				: '';
		}
		var sig = view.state + '|' + view.step + '|' + view.done;
		if ( sig !== last ) {
			last = sig;
			lastChange = Date.now();
		}
	}

	function stop() {
		over = true;
		if ( timer ) {
			window.clearTimeout( timer );
			timer = 0;
		}
		if ( tick ) {
			window.clearTimeout( tick );
			tick = 0;
		}
	}

	function fail( message, canRetry ) {
		if ( over ) {
			return;
		}
		stop();
		box.classList.add( 'is-error' );
		if ( errText ) {
			errText.textContent = message || errDefault;
		}
		if ( retry ) {
			retry.hidden = false === canRetry;
		}
		if ( errBox ) {
			errBox.hidden = false;
		}
		if ( retry && ! retry.hidden && retry.focus ) {
			retry.focus();
		}
	}

	function answer( r ) {
		if ( 'expired' === code( r ) ) {
			fail( texts.expired, false );
			return null;
		}
		if ( ! r.json || ! r.json.success || ! r.json.data ) {
			fail();
			return null;
		}
		return r.json.data;
	}

	function open() {
		// Already sent to this job and shown the waiting page again: never loop.
		if ( window.location.search.indexOf( 'job=' + token ) !== -1 ) {
			fail();
			return;
		}
		stop();
		cascade();
	}

	/**
	 * OPT-2981: the server went faster than the list. The steps left are
	 * ticked one by one (CASCADE_MS each), then the report opens — never four
	 * ticks at once.
	 */
	function cascade() {
		if ( shown >= steps.length ) {
			Array.prototype.forEach.call( steps, function ( li ) {
				li.classList.add( 'is-done' );
				li.classList.remove( 'is-now' );
			} );
			progress( 100 );
			window.location.replace( cfg.report + '&job=' + encodeURIComponent( token ) );
			return;
		}
		shown = Math.floor( shown ) + 1;
		paint();
		window.setTimeout( cascade, CASCADE_MS );
	}

	function settle( view ) {
		show( view );
		if ( 'done' === view.state ) {
			open();
		} else if ( 'error' === view.state ) {
			fail();
		}
	}

	function poll() {
		timer = window.setTimeout( function () {
			if ( over ) {
				return;
			}
			fetch( endpoint( cfg.status, { token: token, _wpnonce: cfg.nonce } ), { credentials: 'same-origin' } )
				.then( read )
				.then( function ( r ) {
					if ( over ) {
						return;
					}
					var view = answer( r );
					if ( ! view ) {
						return;
					}
					settle( view );
					if ( over ) {
						return;
					}
					if ( Date.now() - lastChange > STALL_MS ) {
						fail();
						return;
					}
					poll();
				}, function () {
					fail();
				} );
		}, POLL_MS );
	}

	function build() {
		post( { token: token } ).then( function ( r ) {
			if ( over ) {
				return;
			}
			if ( r.status >= 500 || ! r.json ) {
				fail();
				return;
			}
			var view = answer( r );
			if ( view ) {
				settle( view );
				// OPT-2876: one step done, more pages to score: ask for the next
				// one at once. The user clicks only the first time.
				if ( ! over && view.more ) {
					build();
				}
			}
		}, function () {
			// Connection cut (proxy time limit): the build may go on, the state says.
		} );
	}

	function start() {
		over = false;
		last = '';
		aim = offset;
		shown = offset;
		shownPct = 0;
		lastChange = Date.now();
		box.classList.remove( 'is-error' );
		if ( errBox ) {
			errBox.hidden = true;
		}
		show( { stage: 0, state: 'queued', step: '', done: 0, total: 0 } );
		crawl();
		post( cfg.args || {} ).then( function ( r ) {
			var view = answer( r );
			if ( ! view ) {
				return;
			}
			token = view.token;
			settle( view );
			if ( over ) {
				return;
			}
			build();
			poll();
		}, function () {
			fail();
		} );
	}

	function capture( next ) {
		var done = false;
		var frame = document.createElement( 'iframe' );
		var limit = 0;
		function finish() {
			if ( done ) {
				return;
			}
			done = true;
			window.removeEventListener( 'message', listen );
			window.clearTimeout( limit );
			if ( frame.parentNode ) {
				frame.parentNode.removeChild( frame );
			}
			next();
		}
		function listen( event ) {
			if ( event.origin === window.location.origin && event.source === frame.contentWindow && event.data && 'ob-xray-capture' === event.data.type ) {
				finish();
			}
		}
		frame.setAttribute( 'aria-hidden', 'true' );
		frame.setAttribute( 'tabindex', '-1' );
		frame.title = '';
		// Off screen but laid out at a desktop width: the page in it needs a real size.
		frame.style.position = 'absolute';
		frame.style.left = '-10000px';
		frame.style.top = '0';
		frame.style.width = '1280px';
		frame.style.height = '900px';
		frame.style.border = '0';
		frame.style.visibility = 'hidden';
		window.addEventListener( 'message', listen );
		limit = window.setTimeout( finish, Number( cfg.capture.timeout ) > 0 ? Number( cfg.capture.timeout ) : 45000 );
		frame.src = cfg.capture.url;
		document.body.appendChild( frame );
	}

	if ( retry ) {
		retry.addEventListener( 'click', start );
	}
	if ( offset ) {
		// First step on screen (already `is-now` in the markup), bar at its start.
		capture( start );
	} else {
		start();
	}
} )();
