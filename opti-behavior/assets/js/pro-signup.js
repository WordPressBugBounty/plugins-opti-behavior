/**
 * One-click Pro trial form (OPT-3054).
 *
 * The form is a normal POST form: without this script it still posts to
 * admin-ajax.php and WordPress answers in JSON. With the script the click
 * stays on the page and shows a readable message: "check your inbox", the
 * refusal of the API, or a fallback link to the trial page.
 *
 * Nothing is sent before the click.
 */
( function () {
	'use strict';

	var cfg = window.optiBehaviorProSignup || {};

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
			return;
		}
		document.addEventListener( 'DOMContentLoaded', fn );
	}

	function show( box, text, kind, fallback ) {
		box.className = 'ob-pro-signup__msg is-' + kind;
		box.textContent = text;
		if ( fallback ) {
			box.appendChild( document.createTextNode( ' ' ) );
			var link = document.createElement( 'a' );
			link.href = fallback;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.textContent = cfg.i18n && cfg.i18n.openPage ? cfg.i18n.openPage : 'Open the trial page';
			box.appendChild( link );
		}
		box.hidden = false;
	}

	/**
	 * Success: the message + the button to the customer account, where Pro is
	 * downloaded (OPT-3109). The new tab is opened by the admin's own click.
	 */
	function showDone( box, text, url, label ) {
		box.className = 'ob-pro-signup__msg is-ok';
		box.textContent = text;
		if ( url ) {
			var link = document.createElement( 'a' );
			link.href = url;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.className = 'ob-pro-signup__account';
			link.textContent = label || 'Open my account';
			box.appendChild( document.createTextNode( ' ' ) );
			box.appendChild( link );
		}
		box.hidden = false;
	}

	ready( function () {
		var form = document.getElementById( 'ob-pro-trial-form' );
		if ( ! form || ! form.classList.contains( 'ob-pro-signup' ) ) {
			return;
		}

		var button = form.querySelector( 'button[type="submit"]' );
		var box = form.querySelector( '.ob-pro-signup__msg' );
		if ( ! button || ! box ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.fetch || ! window.FormData ) {
				return; // Plain POST, WordPress answers in its own page.
			}
			event.preventDefault();

			if ( button.disabled ) {
				return;
			}
			button.disabled = true;
			var label = button.textContent;
			button.textContent = cfg.i18n && cfg.i18n.sending ? cfg.i18n.sending : label;
			show( box, cfg.i18n && cfg.i18n.sending ? cfg.i18n.sending : '', 'wait' );

			var data = new FormData( form );

			window.fetch( cfg.ajaxUrl || form.action, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} )
				.then( function ( response ) {
					return response.json().catch( function () {
						return null;
					} );
				} )
				.then( function ( answer ) {
					button.disabled = false;
					button.textContent = label;

					if ( ! answer || ! answer.data ) {
						show( box, cfg.i18n && cfg.i18n.failed ? cfg.i18n.failed : '', 'error', cfg.fallbackUrl || '' );
						return;
					}
					if ( answer.success ) {
						showDone( box, answer.data.message || '', answer.data.accountUrl || '', answer.data.accountLabel || '' );
						button.disabled = true;
						return;
					}
					show( box, answer.data.message || '', 'error', answer.data.fallback || '' );
				} )
				.catch( function () {
					button.disabled = false;
					button.textContent = label;
					show( box, cfg.i18n && cfg.i18n.failed ? cfg.i18n.failed : '', 'error', cfg.fallbackUrl || '' );
				} );
		} );
	} );
}() );
