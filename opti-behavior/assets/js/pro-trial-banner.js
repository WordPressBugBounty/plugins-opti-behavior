/**
 * Pro Trial Banner JavaScript
 *
 * "Try Pro free for 6 months" notice: dismiss only (AJAX, stored on this
 * site, nothing sent elsewhere). The one-click trial button, its AJAX call
 * and the countdown were removed in OPT-763: the notice only links to the
 * trial page on optiuser.com.
 *
 * Expects `optiBehaviorTrial` localized object with:
 *   ajaxUrl, dismissNonce
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

	/* Fire-and-forget POST to admin-ajax (this site only). */
	function post( action, nonce ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', nonce );

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.ajaxUrl, true );
		xhr.send( fd );
	}

	/* ---------- Init on DOM ready ---------- */
	document.addEventListener( 'DOMContentLoaded', function () {
		var banner = qs( '.opti-behavior-trial-notice' );
		if ( ! banner ) {
			return;
		}

		initDismissButton( banner );
	});

	/* ===============================================
	 * DISMISS BANNER
	 * =============================================== */
	function initDismissButton( banner ) {
		var btn = qs( '.opti-behavior-trial-btn-dismiss', banner );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			/* Animate out */
			banner.classList.add( 'opti-behavior-trial-dismissing' );

			/* Fire AJAX (fire-and-forget) */
			post( 'opti_behavior_dismiss_trial_banner', cfg.dismissNonce );

			/* Remove from DOM after animation completes.
			   Must use removeChild instead of style.display='none'
			   because admin-notices.css forces display with !important
			   on .opti-behavior-notice. */
			setTimeout( function () {
				if ( banner.parentNode ) {
					banner.parentNode.removeChild( banner );
				}
			}, 400 );
		});
	}

})();
