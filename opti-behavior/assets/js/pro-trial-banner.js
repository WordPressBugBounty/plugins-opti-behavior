/**
 * Pro Trial Banner JavaScript
 *
 * Handles AJAX interactions for the "Try Pro FREE for 6 Months" banner:
 * - Start free trial (AJAX + UI update)
 * - Dismiss banner (AJAX + smooth animation)
 * - Countdown timer for active trials
 *
 * Expects `optiBehaviorTrial` localized object with:
 *   ajaxUrl, startNonce, dismissNonce, trialState, trialDaysLeft, downloadUrl
 *
 * @package opti-behavior
 * @version 1.1.3
 */
(function () {
	'use strict';

	/* ---------- Guard: localized data must exist ---------- */
	if ( typeof window.optiBehaviorTrial === 'undefined' ) {
		return;
	}
	var cfg = window.optiBehaviorTrial;

	/* ---------- Helpers ---------- */
	function qs( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function post( action, nonce, extraData, onSuccess, onError ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', nonce );
		if ( extraData ) {
			Object.keys( extraData ).forEach( function ( k ) {
				fd.append( k, extraData[ k ] );
			});
		}

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.ajaxUrl, true );
		xhr.onload = function () {
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				try {
					var resp = JSON.parse( xhr.responseText );
					if ( resp.success ) {
						onSuccess( resp.data );
					} else {
						onError( resp.data ? resp.data.message || ( cfg.i18n.unknownError || 'Unknown error' ) : ( cfg.i18n.requestFailed || 'Request failed' ) );
					}
				} catch ( e ) {
					onError( cfg.i18n.invalidResponse || 'Invalid response' );
				}
			} else {
				onError( 'HTTP ' + xhr.status );
			}
		};
		xhr.onerror = function () {
			onError( cfg.i18n.networkError || 'Network error' );
		};
		xhr.send( fd );
	}

	/* ---------- Init on DOM ready ---------- */
	document.addEventListener( 'DOMContentLoaded', function () {
		var banner = qs( '.opti-behavior-trial-banner' );
		if ( ! banner ) {
			return;
		}

		initStartButton( banner );
		initDismissButton( banner );
		initCountdown( banner );
	});

	/* ===============================================
	 * START FREE TRIAL
	 * =============================================== */
	function initStartButton( banner ) {
		var btn = qs( '.opti-behavior-trial-btn-start', banner );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			// Prevent double-click
			if ( btn.classList.contains( 'opti-behavior-trial-loading' ) ) {
				return;
			}

			btn.classList.add( 'opti-behavior-trial-loading' );

			post(
				'opti_behavior_start_pro_trial',
				cfg.startNonce,
				null,
				function ( data ) {
					/* -- Success: morph banner to "active" state -- */
					btn.classList.remove( 'opti-behavior-trial-loading' );
					morphToActive( banner, data );
				},
				function ( errMsg ) {
					btn.classList.remove( 'opti-behavior-trial-loading' );
					/* Show inline error briefly */
					var desc = qs( '.opti-behavior-trial-banner-description', banner );
					if ( desc ) {
						var original = desc.textContent;
						desc.textContent = '⚠ ' + errMsg + '. ' + ( cfg.i18n.pleaseTryAgain || 'Please try again.' );
						desc.style.color = '#fecaca';
						setTimeout( function () {
							desc.textContent = original;
							desc.style.color = '';
						}, 4000 );
					}
				}
			);
		});
	}

	/**
	 * Morph banner from "invite" state to "trial active" state
	 */
	function morphToActive( banner, data ) {
		/* Swap class */
		banner.classList.add( 'opti-behavior-trial-active' );

		/* Replace icon with success check */
		var iconWrap = qs( '.opti-behavior-trial-banner-icon', banner );
		if ( iconWrap ) {
			iconWrap.outerHTML =
				'<div class="opti-behavior-trial-success-check">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' +
				'</div>';
		}

		/* Update title */
		var title = qs( '.opti-behavior-trial-banner-title', banner );
		if ( title ) {
			title.innerHTML = cfg.i18n.trialActivated || 'Pro Trial Activated!';
		}

		/* Update description */
		var desc = qs( '.opti-behavior-trial-banner-description', banner );
		if ( desc ) {
			desc.textContent = data.message || cfg.i18n.trialActiveDesc || 'You have 6 months of free access to all Pro features. Download the Pro plugin to get started!';
		}

		/* Hide feature pills */
		var pills = qs( '.opti-behavior-trial-features', banner );
		if ( pills ) {
			pills.style.display = 'none';
		}

		/* Replace actions */
		var actions = qs( '.opti-behavior-trial-banner-actions', banner );
		if ( actions ) {
			var daysLeft = data.days_left || 180;
			var downloadUrl = data.download_url || cfg.downloadUrl || '#';

			actions.innerHTML =
				'<span class="opti-behavior-trial-banner-countdown">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
				daysLeft + ' ' + ( cfg.i18n.daysLeft || 'days left' ) +
				'</span>' +
				'<a href="' + downloadUrl + '" class="opti-behavior-trial-btn-download" target="_blank" rel="noopener noreferrer">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
				( cfg.i18n.downloadPro || 'Download Pro Plugin' ) +
				'</a>' +
				'<button type="button" class="opti-behavior-trial-btn-dismiss" title="' + ( cfg.i18n.dismiss || 'Dismiss' ) + '">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
				'</button>';

			/* Rebind dismiss on the new button */
			initDismissButton( banner );
		}
	}

	/* ===============================================
	 * DISMISS BANNER
	 * =============================================== */
	function initDismissButton( banner ) {
		var btn = qs( '.opti-behavior-trial-btn-dismiss', banner );
		if ( ! btn ) {
			return;
		}

		/* Remove old listener (for re-binding after morph) */
		var newBtn = btn.cloneNode( true );
		btn.parentNode.replaceChild( newBtn, btn );

		newBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			/* Animate out */
			banner.classList.add( 'opti-behavior-trial-dismissing' );

			/* Fire AJAX (fire-and-forget) */
			post(
				'opti_behavior_dismiss_trial_banner',
				cfg.dismissNonce,
				null,
				function () { /* silent success */ },
				function () { /* silent failure — banner is already hidden */ }
			);

			/* Remove from DOM after animation completes.
			   Must use removeChild instead of style.display='none'
			   because admin-notices.css has display:flex !important
			   which overrides inline styles without !important. */
			setTimeout( function () {
				if ( banner.parentNode ) {
					banner.parentNode.removeChild( banner );
				}
			}, 400 );
		});
	}

	/* ===============================================
	 * COUNTDOWN TIMER (for active trials)
	 * =============================================== */
	function initCountdown( banner ) {
		var countdownEl = qs( '.opti-behavior-trial-banner-countdown', banner );
		if ( ! countdownEl || ! cfg.trialExpiresTimestamp ) {
			return;
		}

		function updateCountdown() {
			var now = Math.floor( Date.now() / 1000 );
			var diff = cfg.trialExpiresTimestamp - now;

			if ( diff <= 0 ) {
				countdownEl.innerHTML =
					'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
					( cfg.i18n.trialExpired || 'Trial expired' );
				return;
			}

			var days = Math.floor( diff / 86400 );
			countdownEl.innerHTML =
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
				days + ' ' + ( cfg.i18n.daysLeft || 'days left' );

			/* Update once daily is fine for days-level granularity */
			setTimeout( updateCountdown, 3600000 );
		}

		updateCountdown();
	}

})();
