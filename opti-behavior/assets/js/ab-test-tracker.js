/**
 * Opti-Behavior A/B Test Frontend Tracker
 *
 * Lightweight tracker (< 5KB) for impression recording, click tracking,
 * page visit goals, form submission goals, scroll depth goals,
 * event batching, and sendBeacon on page unload.
 *
 * @package opti-behavior
 * @since   1.3.0
 */
(function() {
	'use strict';

	// Bug #3 fix: be resilient to optimization plugins (LiteSpeed Cache,
	// Autoptimize, WP Rocket, SG Optimizer) that can move our inline config
	// blocks (optiBehaviorAB, optiBehaviorABConfig) into a deferred bundle
	// that loads AFTER this file. Instead of bailing synchronously, we poll
	// for the globals and also re-check on DOMContentLoaded / load.
	// `booted` guards against double-initialization if multiple triggers fire.

	var config;
	var variants;
	var tracked  = { impressions: {}, conversions: {} };
	var queue    = [];
	var flushTimer = null;
	var flushDelayTimer = null;
	var FLUSH_DELAY_MS = 1500;
	var booted = false;
	var attempts = 0;
	var MAX_ATTEMPTS = 50; // ~5 seconds at 100ms polling.
	// Bug #3 — a single tab-close can fire visibilitychange AND pagehide AND
	// beforeunload; the queue is already drained by the first flush, but we
	// guard with an explicit flag so no lingering sendBeacon/XHR can trigger
	// a redundant network call that produces a race-condition duplicate
	// impression row on the server side.
	var hasFlushedOnUnload = false;

	// -------------------------------------------------------------------------
	// Dead-endpoint guard — permanent stop when the admin-ajax handler is NOT
	// registered but a page-cache plugin still serves cached HTML that loads
	// this tracker. WordPress core answers an unknown admin-ajax action with
	// wp_die('0', 400): HTTP 400 + body exactly "0". That is PERMANENT (not
	// transient): drop the queue, stop the flush timer, and never retry — so a
	// cached page can't flood the endpoint or retry on every batch. Kept strict
	// (400 + trimmed "0") so real handler 400s and 403 nonce rejections don't
	// trip it. Nonce-independent: identical on cached and fresh pages.
	// -------------------------------------------------------------------------
	var _deadEndpoint = false;

	/**
	 * True only for WordPress core's "no such action" reply: HTTP 400 + body
	 * exactly "0".
	 *
	 * @param {number} status   HTTP status.
	 * @param {string} bodyText Raw response body.
	 * @return {boolean}
	 */
	function isDeadEndpoint( status, bodyText ) {
		return status === 400 && typeof bodyText === 'string' && bodyText.trim() === '0';
	}

	/**
	 * Permanently disable the AB tracker for this page load: drop the queue and
	 * stop the periodic flush timer so no further admin-ajax requests are made.
	 * Idempotent.
	 */
	function handleDeadEndpoint() {
		if ( _deadEndpoint ) { return; }
		_deadEndpoint = true;
		queue = [];
		if ( flushTimer !== null ) {
			clearInterval( flushTimer );
			flushTimer = null;
		}
	}

	// -------------------------------------------------------------------------
	// Nonce refresh — one refresh per page load to handle cached-page expiry.
	// Same pattern as opti-behavior-heatmap-simple.js / Pro's errors-tracker.js
	// and form-analytics-tracker.js: on a 403 (nonce rejected by
	// check_ajax_referer() in ajax_ab_track_batch()/ajax_ab_track_conversion(),
	// see trait-opti-behavior-ab-tests-ajax.php), fetch a fresh nonce from the
	// shared opti_behavior_refresh_nonces endpoint and retry the original send
	// exactly once. admin-ajax.php is always excluded from full-page caches, so
	// this endpoint always executes PHP and returns a valid nonce even when the
	// page itself was served from a cache plugin's stale HTML.
	// -------------------------------------------------------------------------
	var _nonceRefreshed = false;

	/**
	 * Request a fresh AB-tracker nonce from the server and invoke retryFn once
	 * it has been applied to `config.nonce`. Both ajax_ab_track_batch() and
	 * ajax_ab_track_conversion() verify the same 'opti_behavior_ab_frontend_nonce'
	 * action, so either nonce_ab / nonce_ab_batch field from the response works.
	 *
	 * @param {Function} retryFn Called after config.nonce has been updated.
	 */
	function refreshNonceAndRetry( retryFn ) {
		if ( _nonceRefreshed ) { return; }
		_nonceRefreshed = true;

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', config.ajax_url, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onload = function() {
			if ( xhr.status !== 200 ) { return; }
			try {
				var resp = JSON.parse( xhr.responseText );
				var freshNonce = resp.success && resp.data && ( resp.data.nonce_ab_batch || resp.data.nonce_ab );
				if ( freshNonce ) {
					config.nonce = freshNonce;
					retryFn();
				}
			} catch ( e ) {
				// Malformed response — give up silently; _nonceRefreshed already
				// guards against a second refresh attempt (no infinite loop).
			}
		};
		xhr.send( 'action=opti_behavior_refresh_nonces' );
	}

	function globalsReady() {
		return typeof window.optiBehaviorAB !== 'undefined'
			&& typeof window.optiBehaviorABConfig !== 'undefined';
	}

	function bootstrap() {
		if ( booted ) { return; }
		if ( globalsReady() ) { init(); return; }
		if ( attempts++ >= MAX_ATTEMPTS ) { return; }
		setTimeout( bootstrap, 100 );
	}

	// =========================================================================
	// Public API
	// =========================================================================
	window.optiBehavior = window.optiBehavior || {};

	/**
	 * Track a custom conversion event.
	 *
	 * @param {number} testId Test ID (optional if only one test active).
	 * @param {number} goalId Goal ID.
	 * @param {object} data   Optional data { revenue: 49.99, ... }.
	 */
	window.optiBehavior.trackConversion = function( testId, goalId, data ) {
		// Bug #3 fix: public API may be invoked by third-party integrations
		// BEFORE init() has populated `variants`/`config`. No-op silently.
		if ( ! variants ) { return; }
		if ( typeof goalId === 'undefined' && variants.length === 1 ) {
			goalId = testId;
			testId = variants[0].test_id;
		}

		var variant = getVariant( testId );
		if ( ! variant ) return;

		var key = testId + '_' + goalId;
		if ( tracked.conversions[key] ) return;
		tracked.conversions[key] = true;

		addToQueue( 'conversion', {
			test_id:    testId,
			variant_id: variant.variant_id,
			goal_id:    goalId,
			revenue:    ( data && data.revenue ) ? data.revenue : 0
		});
	};

	/**
	 * Track a click goal bound to a CSS selector.
	 *
	 * @param {string} selector CSS selector.
	 */
	window.optiBehavior.trackClickGoal = function( selector ) {
		document.addEventListener( 'click', function( e ) {
			// Bug #3 fix: guard in case globals aren't ready yet when this fires.
			if ( ! variants || ! config ) { return; }
			if ( e.target.matches( selector ) || e.target.closest( selector ) ) {
				// Find the matching goal.
				variants.forEach(function( v ) {
					if ( config.goals ) {
						config.goals.forEach(function( goal ) {
							if ( goal.test_id !== v.test_id ) { return; }
							if ( goal.goal_type === 'click' ) {
								var goalConfig = typeof goal.goal_config === 'string' ? JSON.parse( goal.goal_config ) : goal.goal_config;
								// Match against both legacy { selector } and new { selectors } format.
								var matched = goalConfig.selector === selector;
								if ( !matched && goalConfig.selectors ) {
									for ( var si = 0; si < goalConfig.selectors.length; si++ ) {
										if ( goalConfig.selectors[si] === selector ) { matched = true; break; }
									}
								}
								if ( matched ) {
									window.optiBehavior.trackConversion( v.test_id, goal.id );
								}
							}
						});
					}
				});
			}
		});
	};

	/**
	 * Get the variant assigned for a specific test.
	 *
	 * @param {number} testId Test ID.
	 * @return {object|null} Variant data or null.
	 */
	window.optiBehavior.getVariant = function( testId ) {
		if ( ! variants ) { return null; }
		return getVariant( testId );
	};

	// =========================================================================
	// Initialization
	// =========================================================================

	function init() {
		// Idempotency: make sure init runs at most once even if several
		// trigger paths fire (synchronous, poll-hit, DOMContentLoaded, load).
		if ( booted ) { return; }
		if ( ! globalsReady() ) { return; }
		booted = true;

		config   = window.optiBehaviorABConfig;
		variants = window.optiBehaviorAB;

		// Anonymous Mode: make sure the shared cookieless ID broker is
		// initialized even when the heatmap reporter (its usual initializer) is
		// not enqueued on this page. init() is idempotent — if the reporter or
		// the Pro recorder already booted the broker, this is a no-op. Uses the
		// same server-derived values (anon_vid = daily hash / wp_user_<id>,
		// anon_sid_seed = sess_<hash>_<utc30minbucket>) so render-time PHP
		// bucketing and this tracker agree without cookies or client storage.
		ensureAnonBroker();

		// Record impressions for all active tests.
		// DEF-AB-PV-FIX: Skip variants emitted only because the current page
		// matches a page_visit goal URL (`v.goal_only === true`). Recording
		// an impression on the goal page would inflate the variant audience
		// (one extra visitor per goal-page view) and corrupt the conversion
		// rate. The real impression is recorded on the variant page itself.
		variants.forEach(function( v ) {
			if ( v.goal_only ) { return; }
			recordImpression( v.test_id, v.variant_id );
		});

		// Bind goal trackers.
		bindGoalTrackers();

		// Start flush timer (batch send every 30 seconds).
		flushTimer = setInterval( flushQueue, 30000 );

		// Flush on page unload.
		bindUnloadHandler();

		// Remove anti-flicker class.
		document.documentElement.classList.remove( 'opti-ab-loading' );
	}

	// =========================================================================
	// Impression Tracking
	// =========================================================================

	function recordImpression( testId, variantId ) {
		var key = 'imp_' + testId;
		if ( tracked.impressions[key] ) return;
		tracked.impressions[key] = true;

		addToQueue( 'impression', {
			test_id:    testId,
			variant_id: variantId
		});
	}

	// =========================================================================
	// Goal Tracking
	// =========================================================================

	function bindGoalTrackers() {
		if ( ! config.goals || ! config.goals.length ) return;

		config.goals.forEach(function( goal ) {
			var goalConfig;
			try {
				goalConfig = typeof goal.goal_config === 'string' ? JSON.parse( goal.goal_config ) : goal.goal_config;
			} catch(e) {
				goalConfig = {};
			}

			switch ( goal.goal_type ) {
				case 'page_visit':
					trackPageVisitGoal( goal, goalConfig );
					break;
				case 'click':
					trackClickGoal( goal, goalConfig );
					break;
				case 'form_submit':
					trackFormSubmitGoal( goal, goalConfig );
					break;
				case 'scroll_depth':
					trackScrollDepthGoal( goal, goalConfig );
					break;
				case 'time_on_page':
					trackTimeOnPageGoal( goal, goalConfig );
					break;
				case 'bounce_rate':
					trackBounceRateGoal( goal, goalConfig );
					break;
			}
		});
	}

	function trackPageVisitGoal( goal, goalConfig ) {
		if ( ! goalConfig.url ) return;

		// DEF-AB-013 fix: for WP installs in a subdirectory (e.g. /wordpress/),
		// a goal URL entered as a path like "/shop/" was being resolved against
		// window.location.origin (=http://host) producing http://host/shop/,
		// which never matched the actual browser URL http://host/wordpress/shop/.
		// Resolve against config.home_url (the WP home_url exposed to the runtime)
		// so path-only goal URLs correctly include the subdirectory prefix.
		var base = ( config && config.home_url ) ? config.home_url : window.location.origin;
		var targetPath = new URL( goalConfig.url, base ).pathname.replace( /\/$/, '' );
		var currentPath = window.location.pathname.replace( /\/$/, '' );

		if ( targetPath === currentPath ) {
			variants.forEach(function( v ) {
				if ( v.test_id !== goal.test_id ) { return; }
				window.optiBehavior.trackConversion( v.test_id, goal.id );
			});
		}
	}

	function trackClickGoal( goal, goalConfig ) {

		// --- Per-variant mode: each variant has its own CSS selector ---
		if ( goalConfig.selectors_per_variant ) {
			// Find this visitor's assigned variant for this test.
			var assignedVariant = null;
			for ( var vi = 0; vi < variants.length; vi++ ) {
				if ( variants[ vi ].test_id === goal.test_id ) {
					assignedVariant = variants[ vi ];
					break;
				}
			}
			if ( ! assignedVariant ) return;

			var perVarSel = goalConfig.selectors_per_variant[ String( assignedVariant.sort_order ) ];
			if ( ! perVarSel || ! perVarSel.trim() ) return; // no selector configured for this variant

			document.addEventListener( 'click', function( e ) {
				try {
					if ( e.target.matches( perVarSel ) || e.target.closest( perVarSel ) ) {
						window.optiBehavior.trackConversion( assignedVariant.test_id, goal.id );
					}
				} catch ( ex ) {
					// Invalid selector — ignore.
				}
			}, { passive: true } );
			return;
		}

		// --- Flat selectors mode (default): same selector(s) for all variants ---
		// Support both new { selectors: [...] } and legacy { selector: "..." } format.
		var selectors = goalConfig.selectors && goalConfig.selectors.length
			? goalConfig.selectors
			: ( goalConfig.selector ? [ goalConfig.selector ] : [] );

		if ( ! selectors.length ) return;

		document.addEventListener( 'click', function( e ) {
			for ( var i = 0; i < selectors.length; i++ ) {
				try {
					if ( e.target.matches( selectors[i] ) || e.target.closest( selectors[i] ) ) {
						variants.forEach(function( v ) {
							if ( v.test_id !== goal.test_id ) { return; }
							window.optiBehavior.trackConversion( v.test_id, goal.id );
						});
						return; // Fire only once per click event.
					}
				} catch ( ex ) {
					// Invalid selector — skip.
				}
			}
		}, { passive: true });
	}

	function trackFormSubmitGoal( goal, goalConfig ) {

		// --- Per-variant mode: each variant has its own form CSS selector ---
		if ( goalConfig.selector_per_variant ) {
			var assignedVariant = null;
			for ( var vi = 0; vi < variants.length; vi++ ) {
				if ( variants[ vi ].test_id === goal.test_id ) {
					assignedVariant = variants[ vi ];
					break;
				}
			}
			if ( ! assignedVariant ) return;

			var perVarSel = goalConfig.selector_per_variant[ String( assignedVariant.sort_order ) ];
			if ( ! perVarSel || ! perVarSel.trim() ) return;

			document.addEventListener( 'submit', function( e ) {
				try {
					if ( e.target.matches( perVarSel ) || !! e.target.closest( perVarSel ) ) {
						window.optiBehavior.trackConversion( assignedVariant.test_id, goal.id );
						// Page may navigate right after submit — use sendBeacon (see flushQueue jsdoc).
						flushQueue( true );
					}
				} catch ( ex ) {}
			});
			return;
		}

		// --- Same for all variants (existing code) ---
		var selector = goalConfig.selector || 'form';
		var formId   = goalConfig.form_id   || '';

		document.addEventListener( 'submit', function( e ) {
			var matched = false;
			var target  = e.target;

			// If a specific form_id is set (by Pro Form Picker), try attribute-based
			// identification first — more precise than a generic CSS selector.
			if ( formId ) {
				matched = (
					target.getAttribute( 'data-form-id' ) === formId ||
					target.getAttribute( 'id' )           === formId ||
					target.getAttribute( 'name' )         === formId
				);
			}

			// Fallback: match by CSS selector (also the only path when no form_id).
			if ( ! matched ) {
				try {
					matched = target.matches( selector ) || !! target.closest( selector );
				} catch ( ex ) {
					matched = false;
				}
			}

			if ( matched ) {
				variants.forEach(function( v ) {
					if ( v.test_id !== goal.test_id ) { return; }
					window.optiBehavior.trackConversion( v.test_id, goal.id );
				});
				// Flush immediately on form submit (page may navigate; see flushQueue jsdoc).
				flushQueue( true );
			}
		});
	}

	function trackScrollDepthGoal( goal, goalConfig ) {
		var threshold = parseInt( goalConfig.depth, 10 ) || 75;
		var fired = false;

		window.addEventListener( 'scroll', function() {
			if ( fired ) return;

			var scrollPercent = Math.round(
				( window.scrollY / ( document.documentElement.scrollHeight - window.innerHeight ) ) * 100
			);

			if ( scrollPercent >= threshold ) {
				fired = true;
				variants.forEach(function( v ) {
					if ( v.test_id !== goal.test_id ) { return; }
					window.optiBehavior.trackConversion( v.test_id, goal.id );
				});
			}
		}, { passive: true });
	}

	function trackTimeOnPageGoal( goal, goalConfig ) {
		var seconds = parseInt( goalConfig.seconds, 10 ) || 30;

		setTimeout(function() {
			variants.forEach(function( v ) {
				if ( v.test_id !== goal.test_id ) { return; }
				window.optiBehavior.trackConversion( v.test_id, goal.id );
			});
		}, seconds * 1000 );
	}

	/**
	 * Track a bounce rate goal.
	 *
	 * When inverse === true (default):
	 *   Conversion fires if the visitor STAYS past the threshold (engaged, didn't bounce).
	 *
	 * When inverse === false:
	 *   Conversion fires if the visitor LEAVES before the threshold (they bounced).
	 *
	 * @param {object} goal       Goal object { id, goal_type, goal_config }.
	 * @param {object} goalConfig Parsed goal_config { threshold_seconds, inverse }.
	 */
	function trackBounceRateGoal( goal, goalConfig ) {
		var thresholdMs = ( parseInt( goalConfig.threshold_seconds, 10 ) || 10 ) * 1000;
		var inverse     = goalConfig.inverse !== false; // default: true (stayed = conversion)
		var fired       = false;

		if ( inverse ) {
			// inverse === true: conversion fires when visitor STAYS past threshold.
			setTimeout( function() {
				if ( fired ) return;
				fired = true;
				variants.forEach( function( v ) {
					if ( v.test_id !== goal.test_id ) { return; }
					window.optiBehavior.trackConversion( v.test_id, goal.id );
				});
			}, thresholdMs );
		} else {
			// inverse === false: conversion fires when visitor BOUNCES (leaves before threshold).
			var stayedPastThreshold = false;

			// Once threshold passes, mark that the visitor is no longer considered a bounce.
			setTimeout( function() {
				stayedPastThreshold = true;
			}, thresholdMs );

			function handleBounceExit() {
				if ( fired || stayedPastThreshold ) return;
				fired = true;
				variants.forEach( function( v ) {
					if ( v.test_id !== goal.test_id ) { return; }
					window.optiBehavior.trackConversion( v.test_id, goal.id );
				});
				// Flush immediately since the page is being left.
				flushQueue( true );
			}

			document.addEventListener( 'visibilitychange', function() {
				if ( document.visibilityState === 'hidden' ) {
					handleBounceExit();
				}
			});

			window.addEventListener( 'beforeunload', handleBounceExit );
			window.addEventListener( 'pagehide', handleBounceExit );
		}
	}

	// =========================================================================
	// Event Queue & Batching
	// =========================================================================

	function addToQueue( type, data ) {
		queue.push({
			type:      type,
			data:      data,
			timestamp: Date.now()
		});

		// Auto-flush if queue gets large.
		if ( queue.length >= 10 ) {
			clearScheduledFlush();
			flushQueue();
			return;
		}

		// DEF-AB-STATS-FLUSH: do not leave a normal one-visit queue waiting
		// up to 30 seconds for the interval or until the visitor navigates away.
		// A short delay lets session-recorder cookies/localStorage settle, then
		// sends the batch so the results dashboard reflects each new visit.
		scheduleFlush();
	}

	function scheduleFlush() {
		if ( flushDelayTimer ) {
			return;
		}

		flushDelayTimer = setTimeout(function() {
			flushDelayTimer = null;
			flushQueue();
		}, FLUSH_DELAY_MS );
	}

	function clearScheduledFlush() {
		if ( ! flushDelayTimer ) {
			return;
		}

		clearTimeout( flushDelayTimer );
		flushDelayTimer = null;
	}

	/**
	 * Send whatever is queued to the batch-tracking endpoint.
	 *
	 * @param {boolean} [isUnload] True when called from a page-unload path
	 *                             (visibilitychange/beforeunload/pagehide or a
	 *                             goal that navigates the page away, e.g. form
	 *                             submit / bounce exit). Only THAT case uses
	 *                             sendBeacon — sendBeacon's return value only
	 *                             confirms the browser accepted the request for
	 *                             delivery, never the server's response, so a
	 *                             stale/expired nonce can never be detected or
	 *                             retried through it. Every other (in-page) flush
	 *                             goes through sendXHR() so a 403 nonce failure
	 *                             can be detected and retried once.
	 */
	function flushQueue( isUnload ) {
		clearScheduledFlush();

		// Dead endpoint detected earlier this page load: send nothing.
		if ( _deadEndpoint ) { queue = []; return; }

		if ( queue.length === 0 ) return;

		var payload = {
			action:  'opti_behavior_ab_track_batch',
			nonce:   config.nonce,
			events:  JSON.stringify( queue )
		};

		// Include the JS-side visitor_id so the PHP handler stores the same ID
		// that the session recorder uses (avoids httpOnly-cookie format mismatch).
		var jsVisitorId = getJsVisitorId();
		if ( jsVisitorId ) {
			payload.visitor_id = jsVisitorId;
		}

		// Include the current tracking session_id so the PHP handler stores a
		// value that can be joined directly against Free heatmap/session rows
		// and Pro recordings rows.
		var jsSessionId = getJsSessionId();
		if ( jsSessionId ) {
			payload.session_id = jsSessionId;
		}

		// Clear queue before sending.
		queue = [];

		if ( isUnload && navigator.sendBeacon ) {
			var formData = new FormData();
			for ( var key in payload ) {
				formData.append( key, payload[key] );
			}
			var sent = navigator.sendBeacon( config.ajax_url, formData );
			if ( ! sent ) {
				sendXHR( payload );
			}
		} else {
			sendXHR( payload );
		}
	}

	// DEF-AB-RETRY: bounded delivery retries. A single in-page flush used to be
	// one-shot (plus a single stale-nonce refresh), so any transient failure —
	// host WAF burst-throttling parallel admin-ajax POSTs (observed on
	// Hostinger/LiteSpeed staging), a flaky mobile connection, a 5xx hiccup —
	// silently dropped the impression/conversion batch. Server-side dedup makes
	// retries safe: impressions are UNIQUE(test_id, visitor_id) and conversions
	// are UNIQUE(test_id, goal_id, visitor_id), so a re-sent batch can never
	// double-count.
	var XHR_MAX_ATTEMPTS  = 3;    // total tries per batch payload
	var XHR_RETRY_BASE_MS = 2000; // backoff: 2s, then 4s

	function sendXHR( payload, attempt ) {
		attempt = attempt || 1;

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', config.ajax_url, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

		function scheduleRetry() {
			if ( attempt >= XHR_MAX_ATTEMPTS ) { return; }
			setTimeout( function() {
				// Pick up any nonce refreshed by another request in the meantime.
				payload.nonce = config.nonce;
				sendXHR( payload, attempt + 1 );
			}, XHR_RETRY_BASE_MS * attempt );
		}

		xhr.onload = function() {
			if ( xhr.status === 200 ) { return; }

			// Permanent dead endpoint (handler unregistered on a cached page):
			// HTTP 400 + body exactly "0". Stop cleanly, no retry.
			if ( isDeadEndpoint( xhr.status, xhr.responseText ) ) {
				handleDeadEndpoint();
				return;
			}

			// Handle nonce expiry on cache-plugin-served pages: check_ajax_referer()
			// in ajax_ab_track_batch() sends wp_send_json_error( ..., 403 ) on a bad
			// nonce. Refresh once and retry this exact payload with the new nonce
			// (does not consume a backoff attempt).
			if ( xhr.status === 403 && ! _nonceRefreshed ) {
				refreshNonceAndRetry( function() {
					payload.nonce = config.nonce;
					sendXHR( payload, attempt );
				} );
				return;
			}

			// WAF burst / transient server error: bounded backoff retry.
			scheduleRetry();
		};

		// Network-level failure (connection reset, offline blip): same bounded retry.
		xhr.onerror = scheduleRetry;

		var body = [];
		for ( var key in payload ) {
			body.push( encodeURIComponent( key ) + '=' + encodeURIComponent( payload[key] ) );
		}

		xhr.send( body.join( '&' ) );
	}

	function bindUnloadHandler() {
		// Bug #3 — Single-shot unload flush. Modern browsers fire
		// visibilitychange, pagehide, and beforeunload in rapid succession
		// on tab close / navigation; running flushQueue() multiple times
		// (even with an already-drained queue) risks emitting a redundant
		// sendBeacon request whose impression event can race another batch
		// on the server and create duplicate rows. Gate on hasFlushedOnUnload
		// so only the first event actually hits the network.
		function onUnloadFlush() {
			if ( hasFlushedOnUnload ) { return; }
			hasFlushedOnUnload = true;
			flushQueue( true );
		}

		// visibilitychange (more reliable than beforeunload on mobile).
		document.addEventListener( 'visibilitychange', function() {
			if ( document.visibilityState === 'hidden' ) {
				onUnloadFlush();
			} else {
				// Tab became visible again — the earlier hidden-flush was a tab
				// switch, NOT a real unload. Re-arm the gate so events queued
				// from now on (e.g. a click-goal conversion immediately followed
				// by link navigation) can still be flushed on the next
				// hidden/beforeunload/pagehide. Without this reset the flag
				// stayed true forever, permanently blocking every later unload
				// flush and silently dropping any conversion queued within
				// FLUSH_DELAY_MS of leaving the page. Server-side dedup
				// (UNIQUE(test_id, goal_id, visitor_id)) keeps re-flushes safe.
				hasFlushedOnUnload = false;
			}
		});

		// bfcache restore (back/forward navigation): the page returns alive
		// after a pagehide flush — re-arm for the same reason as above.
		window.addEventListener( 'pageshow', function() {
			hasFlushedOnUnload = false;
		});

		// beforeunload fallback for desktop browsers.
		window.addEventListener( 'beforeunload', onUnloadFlush );

		// pagehide for Safari / iOS.
		window.addEventListener( 'pagehide', onUnloadFlush );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Ensure the shared cookieless anonymous ID broker is initialized.
	 *
	 * The broker (window.OptiBehaviorAnonBroker, shipped by Free and declared as
	 * a script dependency of this tracker) is normally initialized by the
	 * heatmap reporter or the Pro session recorder — but neither is guaranteed
	 * to be present (heatmap tracking disabled, Pro not installed). Split and
	 * Element tests must work with the Free plugin alone, so this tracker can
	 * boot the broker itself. init() is idempotent: first caller wins, later
	 * callers reuse the same in-memory ids and BroadcastChannel.
	 *
	 * Only runs in Anonymous Mode — full mode uses the consent-gated cookie /
	 * localStorage identity, and stale-cookie cleanup must not run before the
	 * full-mode consent-migration reader has consumed any legacy anon cookies.
	 *
	 * @return {Object|null} The broker, or null when unavailable.
	 */
	function ensureAnonBroker() {
		var broker = window.OptiBehaviorAnonBroker;
		if ( ! broker ) { return null; }
		var cfg = window.optiBehaviorABConfig || {};
		if ( cfg.privacy_mode === 'full' ) { return broker; }
		try {
			if ( ! broker.isInitialized() ) {
				try { broker.cleanupStaleCookies(); } catch ( e ) {}
				broker.init( {
					vid: cfg.anon_vid || '',
					sidSeed: cfg.anon_sid_seed || ''
				} );
			}
		} catch ( e ) { /* broker unavailable */ }
		return broker;
	}

	/**
	 * Return the visitor ID that the session recorder has established for this
	 * browser, so the A/B batch PHP handler stores the same value that the
	 * session recording system uses in wp_optibehavior_sessions.visitor_id.
	 *
	 * Priority:
	 *   1. localStorage  — set by session-recorder.js (non-anonymous mode)
	 *   2. Cookieless broker (window.OptiBehaviorAnonBroker) — anonymous mode.
	 *      Holds the server daily-rotating hash (anon_{sha256}) in memory only:
	 *      no cookie, no client storage. Same value stored in
	 *      wp_optibehavior_sessions.visitor_id, so the recording JOIN matches.
	 *      Checked BEFORE the full-mode cookie.
	 *   3. Cookie optibehavior_vid  — full-mode (persistent, JS-readable after
	 *      the httponly=false fix in class-opti-behavior-heatmap-session.php).
	 *   4. null → PHP handler falls back to its own cookie / session system.
	 *
	 * @return {string|null}
	 */
	function getJsVisitorId() {
		// 1. localStorage (full/non-anonymous mode — session-recorder.js stores it here).
		try {
			var lsVal = localStorage.getItem( 'optibehavior_vid' );
			if ( lsVal ) { return lsVal; }
		} catch ( e ) { /* private browsing */ }

		// 2. Anonymous mode: the shared cookieless broker holds the server
		//    daily-rotating hash in memory (no cookie, no client storage). This is
		//    the same value the PHP handler stores in wp_optibehavior_sessions.
		try {
			var vidBroker = ensureAnonBroker();
			if ( vidBroker && vidBroker.isInitialized() ) {
				var brokerVid = vidBroker.getVisitorId();
				if ( brokerVid ) { return brokerVid; }
			}
		} catch ( e ) { /* broker unavailable */ }

		// 3. Full-mode cookie (persistent, JS-readable). Anon vid is no longer a
		//    cookie, so only the full-mode key is checked here.
		var cookieNames = [ 'optibehavior_vid' ];
		var cookies     = document.cookie.split( ';' );
		for ( var n = 0; n < cookieNames.length; n++ ) {
			var prefix = cookieNames[ n ] + '=';
			for ( var i = 0; i < cookies.length; i++ ) {
				var c = cookies[ i ].trim();
				if ( c.indexOf( prefix ) === 0 ) {
					return c.substring( prefix.length );
				}
			}
		}

		return null;
	}

	/**
	 * Return the current tracking session ID.
	 *
	 * Free heatmap tracking stores the canonical session in `optibehavior_sid`
	 * (or legacy `opti_behavior_session_id`). Pro session replay may store it
	 * in the session-data blob. Passing the resolved value prevents A/B rows
	 * from being stored under request-local UUIDs that cannot join to the
	 * Free session table for spam/human classification.
	 *
	 * @return {string|null}
	 */
	function getJsSessionId() {
		var cookies = document.cookie.split( ';' );
		var cookieNames = [ 'optibehavior_sid', 'opti_behavior_session_id' ];
		for ( var n = 0; n < cookieNames.length; n++ ) {
			var prefix = cookieNames[ n ] + '=';
			for ( var i = 0; i < cookies.length; i++ ) {
				var c = cookies[ i ].trim();
				if ( c.indexOf( prefix ) === 0 ) {
					return c.substring( prefix.length );
				}
			}
		}

		// Anonymous mode: shared cookieless broker holds the in-memory sid
		// (no cookie, no sessionStorage).
		try {
			var sidBroker = ensureAnonBroker();
			if ( sidBroker && sidBroker.isInitialized() ) {
				var brokerSid = sidBroker.getSessionId();
				if ( brokerSid ) { return brokerSid; }
			}
		} catch ( e ) { /* broker unavailable */ }

		try {
			var raw = localStorage.getItem( 'opti_behavior_pro_session_data' );
			if ( raw ) {
				var parsed = JSON.parse( raw );
				if ( parsed && parsed.sessionId ) { return parsed.sessionId; }
			}
		} catch ( e ) { /* private browsing or invalid JSON */ }

		// Also check sessionStorage (anonymous mode uses sessionStorage).
		try {
			var ssRaw = sessionStorage.getItem( 'opti_behavior_pro_session_data' );
			if ( ssRaw ) {
				var ssParsed = JSON.parse( ssRaw );
				if ( ssParsed && ssParsed.sessionId ) { return ssParsed.sessionId; }
			}
		} catch ( e ) { /* unavailable */ }

		return null;
	}

	function getVariant( testId ) {
		if ( ! variants ) { return null; }
		for ( var i = 0; i < variants.length; i++ ) {
			if ( variants[i].test_id === testId ) {
				return variants[i];
			}
		}
		return null;
	}

	// Test hook — lets QA harnesses exercise the nonce-refresh/retry logic
	// (config/queue plumbing) without booting the full tracker via polling for
	// window.optiBehaviorAB / optiBehaviorABConfig. Not a public API.
	if ( typeof window !== 'undefined' ) {
		window.__optiBehaviorTestables = window.__optiBehaviorTestables || {};
		window.__optiBehaviorTestables.abTracker = {
			setConfig:   function( c ) { config = c; },
			setVariants: function( v ) { variants = v; },
			addToQueue:  addToQueue,
			flushQueue:  flushQueue,
			getQueueLength: function() { return queue.length; },
			getQueue:    function() { return queue.slice(); },
			bindGoalTrackers: bindGoalTrackers,
			isNonceRefreshed: function() { return _nonceRefreshed; },
			isDeadEndpoint: isDeadEndpoint,
			isDeadEndpointTripped: function() { return _deadEndpoint; }
		};
	}

	// Bug #3 fix: trigger init via multiple paths so that whichever wins
	// first (synchronous-ready, poll-hit, DOMContentLoaded, load) boots the
	// tracker exactly once (`booted` guards against double-init).
	if ( globalsReady() ) {
		init();
	} else {
		bootstrap();
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function() { if ( globalsReady() ) init(); } );
	}
	window.addEventListener( 'load', function() { if ( globalsReady() ) init(); } );

})();
