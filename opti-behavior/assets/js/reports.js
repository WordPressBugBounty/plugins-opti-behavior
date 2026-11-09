/**
 * "Reports" screen (OPT-1342): shows the bands of the chosen report, keeps the
 * quick periods and the two dates in step, counts the pages in annex (10 at
 * most, chosen pages as chips, any page of the site found by the search:
 * OPT-1421) and turns the button into « Generating… » while the report opens
 * in a new tab. One request only: the annex search; the form opens the report.
 */
( function () {
	'use strict';

	var form = document.querySelector( '[data-ob-reports]' );
	if ( ! form ) {
		return;
	}
	var cfg = window.optiBehaviorReports || {};
	var maxAnnex = parseInt( cfg.maxAnnex, 10 ) || 10;
	var fromInput = form.querySelector( '[data-ob-from]' );
	var toInput = form.querySelector( '[data-ob-to]' );
	var hint = form.querySelector( '[data-ob-hint]' );
	var go = form.querySelector( '[data-ob-go]' );
	var note = form.querySelector( '[data-ob-note]' );
	var goHtml = go ? go.innerHTML : '';
	var noteText = note ? note.textContent : '';
	var spamOut = '1' === ( form.querySelector( 'input[name="exclude_spam"]' ) || {} ).value;

	function value( name ) {
		var checked = form.querySelector( 'input[name="' + name + '"]:checked' );
		return checked ? checked.value : '';
	}

	function format( text, args ) {
		var i = 0;
		return String( text || '' ).replace( /%(\d+\$)?[sd]/g, function ( match, pos ) {
			var index = pos ? parseInt( pos, 10 ) - 1 : i++;
			return undefined !== args[ index ] ? String( args[ index ] ) : match;
		} );
	}

	/* Days are Y-m-d texts of the site's calendar, read as local dates (never UTC). */
	function toDay( text ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( text || '' );
		return m ? new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] ) : null;
	}

	function ymd( date ) {
		var mm = date.getMonth() + 1;
		var dd = date.getDate();
		return date.getFullYear() + '-' + ( mm < 10 ? '0' : '' ) + mm + '-' + ( dd < 10 ? '0' : '' ) + dd;
	}

	function shift( date, days ) {
		return new Date( date.getFullYear(), date.getMonth(), date.getDate() + days );
	}

	function label( date, withYear ) {
		var opts = { day: 'numeric', month: 'short' };
		if ( withYear ) {
			opts.year = 'numeric';
		}
		if ( 'object' === typeof window.OptiBehaviorLocale && window.OptiBehaviorLocale ) {
			return window.OptiBehaviorLocale.date( date, opts );
		}
		return ymd( date );
	}

	/* Bands: the page picker for one page, the version for the site, the annex for the full version. */
	function bands() {
		var type = value( 'type' ) || 'site';
		var full = 'full' === value( 'version' );
		form.querySelectorAll( '[data-ob-show]' ).forEach( function ( row ) {
			var show = row.getAttribute( 'data-ob-show' );
			row.hidden = ! ( show === type || ( 'full' === show && 'site' === type && full ) );
		} );
		var n = 0;
		form.querySelectorAll( '.ob-reports__row' ).forEach( function ( row ) {
			var step = row.querySelector( '.ob-reports__step' );
			if ( step && ! row.hidden ) {
				step.textContent = String( ++n );
			}
		} );
	}

	/* The line under the period: both periods + the spam rule. */
	function period() {
		var a = toDay( fromInput && fromInput.value );
		var b = toDay( toInput && toInput.value );
		var spam = spamOut ? cfg.spamOut : cfg.spamIn;
		if ( ! hint ) {
			return;
		}
		if ( ! a || ! b ) {
			hint.textContent = spam || '';
			return;
		}
		if ( a > b ) {
			var swap = a;
			a = b;
			b = swap;
		}
		var days = Math.round( ( b - a ) / 86400000 ) + 1;
		var pb = shift( a, -1 );
		var pa = shift( a, -days );
		hint.textContent = format( cfg.hint, [
			label( a, a.getFullYear() !== b.getFullYear() ),
			label( b, true ),
			label( pa, pa.getFullYear() !== pb.getFullYear() ),
			label( pb, false ),
		] ) + ' ' + ( spam || '' );
	}

	function pill( input ) {
		var today = toDay( cfg.today );
		var days = parseInt( input.getAttribute( 'data-days' ), 10 );
		if ( today && days && fromInput && toInput ) {
			toInput.value = ymd( today );
			fromInput.value = ymd( shift( today, 1 - days ) );
		}
		period();
	}

	/* A date typed by hand is a custom period: no quick period stays chosen. */
	function dates() {
		form.querySelectorAll( 'input[name="period"]' ).forEach( function ( input ) {
			input.checked = false;
		} );
		period();
	}

	/*
	 * Pages in annex (OPT-1421): the most visited are check boxes; a page found
	 * by the search that is not one of them is an "extra" with its own hidden
	 * field (pages[] = a page Opti-Behavior knows, posts[] = a WordPress post it
	 * never saw). Every chosen page shows as a removable chip on top.
	 */
	var scfg = cfg.search || {};
	var chips = form.querySelector( '[data-ob-chips]' );
	var searchBox = form.querySelector( '[data-ob-search-box]' );
	var search = form.querySelector( '[data-ob-search]' );
	var results = form.querySelector( '[data-ob-results]' );
	var extras = [];
	var found = [];
	var seq = 0;
	var timer = 0;
	var known = ( function () {
		var node = form.querySelector( '[data-ob-visited-pages]' );
		try {
			var list = JSON.parse( node ? node.textContent : '[]' );
			return Array.isArray( list ) ? list : [];
		} catch ( err ) {
			return [];
		}
	} )();

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function boxOf( id ) {
		var hit = null;
		form.querySelectorAll( '[data-ob-annex]' ).forEach( function ( box ) {
			if ( String( box.value ) === String( id ) ) {
				hit = box;
			}
		} );
		return hit;
	}

	function chosenCount() {
		return form.querySelectorAll( '[data-ob-annex]:checked' ).length + extras.length;
	}

	function isChosen( item ) {
		if ( 'page' === item.kind ) {
			var box = boxOf( item.id );
			if ( box ) {
				return box.checked;
			}
		}
		return extras.some( function ( x ) {
			return x.kind === item.kind && String( x.id ) === String( item.id );
		} );
	}

	function visitsText( n ) {
		if ( ! n ) {
			return scfg.noVisits || '';
		}
		var num = 'object' === typeof window.OptiBehaviorLocale && window.OptiBehaviorLocale ? window.OptiBehaviorLocale.number( n ) : String( n );
		return format( 1 === n ? scfg.visit1 : scfg.visitN, [ num ] );
	}

	function chip( title, onRemove, field ) {
		var li = el( 'li', 'ob-reports__chip' );
		li.appendChild( el( 'span', '', title ) );
		var btn = el( 'button', '', '×' );
		btn.type = 'button';
		btn.setAttribute( 'aria-label', format( scfg.remove, [ title ] ) );
		btn.addEventListener( 'click', onRemove );
		li.appendChild( btn );
		if ( field ) {
			li.appendChild( field );
		}
		return li;
	}

	function drawChips() {
		if ( ! chips ) {
			return;
		}
		chips.textContent = '';
		form.querySelectorAll( '[data-ob-annex]:checked' ).forEach( function ( box ) {
			chips.appendChild( chip( box.getAttribute( 'data-title' ) || box.value, function () {
				box.checked = false;
				annex();
			} ) );
		} );
		extras.forEach( function ( x, i ) {
			var field = document.createElement( 'input' );
			field.type = 'hidden';
			field.name = 'page' === x.kind ? 'pages[]' : 'posts[]';
			field.value = String( x.id );
			chips.appendChild( chip( x.title, function () {
				extras.splice( i, 1 );
				annex();
				if ( search ) {
					search.focus();
				}
			}, field ) );
		} );
		chips.hidden = ! chips.firstChild;
	}

	function drawResults( state ) {
		if ( ! results ) {
			return;
		}
		results.textContent = '';
		if ( state ) {
			results.appendChild( el( 'li', 'ob-reports__result-state', state ) );
			results.hidden = false;
			return;
		}
		if ( ! found.length ) {
			results.hidden = true;
			return;
		}
		var full = chosenCount() >= maxAnnex;
		found.forEach( function ( item, i ) {
			var li = el( 'li' );
			var btn = el( 'button', 'ob-reports__result' );
			var on = isChosen( item );
			btn.type = 'button';
			btn.setAttribute( 'data-ob-pick', String( i ) );
			btn.disabled = on || full;
			btn.appendChild( el( 'b', '', item.title ) );
			btn.appendChild( el( 'span', 'ob-reports__result-path', item.path + ( item.type ? ' · ' + item.type : '' ) ) );
			btn.appendChild( el( 'span', item.visits ? '' : 'is-none', on ? scfg.chosen : visitsText( item.visits ) ) );
			li.appendChild( btn );
			results.appendChild( li );
		} );
		if ( full ) {
			results.appendChild( el( 'li', 'ob-reports__result-state', format( scfg.full, [ maxAnnex ] ) ) );
		}
		results.hidden = false;
	}

	function annex() {
		var boxes = form.querySelectorAll( '[data-ob-annex]' );
		var count = form.querySelector( '[data-ob-count]' );
		var chosen = chosenCount();
		boxes.forEach( function ( box ) {
			box.disabled = ! box.checked && chosen >= maxAnnex;
			var row = box.closest( '.ob-reports__page' );
			if ( row ) {
				row.classList.toggle( 'is-checked', box.checked );
			}
		} );
		if ( count ) {
			count.textContent = format( cfg.count, [ chosen, maxAnnex ] );
		}
		drawChips();
		if ( results && ! results.hidden && found.length ) {
			drawResults();
		}
	}

	function pick( item ) {
		if ( ! item || isChosen( item ) || chosenCount() >= maxAnnex ) {
			return;
		}
		var box = 'page' === item.kind ? boxOf( item.id ) : null;
		if ( box ) {
			box.checked = true;
		} else {
			extras.push( item );
		}
		annex();
	}

	/* Visited pages that match the text: title or path, case and accents kept simple. */
	function localMatches( q ) {
		var needle = q.toLowerCase();
		return known.filter( function ( p ) {
			return String( p.t ).toLowerCase().indexOf( needle ) > -1 || String( p.p ).toLowerCase().indexOf( needle ) > -1;
		} ).slice( 0, 6 ).map( function ( p ) {
			return { kind: 'page', id: p.id, post: p.post, title: p.t, path: p.p, visits: p.v };
		} );
	}

	/* A WordPress post is the visited page that carries its id, else a post without visits. */
	function merge( local, posts ) {
		var out = local.slice();
		var has = function ( kind, id ) {
			return out.some( function ( x ) {
				return x.kind === kind && String( x.id ) === String( id );
			} );
		};
		posts.forEach( function ( post ) {
			var page = null;
			known.forEach( function ( p ) {
				if ( ! page && p.post && String( p.post ) === String( post.post_id ) ) {
					page = p;
				}
			} );
			if ( page ) {
				if ( ! has( 'page', page.id ) ) {
					out.push( { kind: 'page', id: page.id, post: page.post, title: page.t, path: page.p, visits: page.v } );
				}
			} else if ( ! has( 'post', post.post_id ) ) {
				out.push( { kind: 'post', id: post.post_id, title: post.title, path: post.path, type: post.kind, visits: 0 } );
			}
		} );
		return out;
	}

	function runSearch() {
		var q = search ? search.value.trim() : '';
		var mine = ++seq;
		if ( q.length < 2 && '/' !== q ) {
			found = [];
			drawResults();
			return;
		}
		found = localMatches( q );
		drawResults( found.length ? '' : scfg.searching );
		var body = new window.FormData();
		body.append( 'action', scfg.action || '' );
		body.append( 'nonce', scfg.nonce || '' );
		body.append( 'q', q );
		window.fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				if ( mine !== seq ) {
					return;
				}
				var posts = json && json.success && json.data && Array.isArray( json.data.posts ) ? json.data.posts : [];
				found = merge( found, posts );
				drawResults( found.length ? '' : scfg.none );
			} )
			.catch( function () {
				if ( mine === seq ) {
					drawResults( found.length ? '' : scfg.failed );
				}
			} );
	}

	if ( searchBox && search && results && window.fetch && window.FormData ) {
		searchBox.hidden = false;
		search.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( runSearch, 250 );
		} );
		search.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				// Never sends the report form: Enter takes the first free result.
				e.preventDefault();
				var first = results.querySelector( '[data-ob-pick]:not(:disabled)' );
				if ( first ) {
					first.click();
				}
			} else if ( 'Escape' === e.key ) {
				found = [];
				drawResults();
			}
		} );
		results.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-ob-pick]' );
			if ( btn && ! btn.disabled ) {
				pick( found[ parseInt( btn.getAttribute( 'data-ob-pick' ), 10 ) ] );
			}
		} );
	}

	function restore() {
		if ( go ) {
			go.disabled = false;
			go.innerHTML = goHtml;
		}
		if ( note ) {
			note.textContent = noteText;
		}
	}

	form.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( 'type' === t.name || 'version' === t.name ) {
			bands();
		} else if ( 'period' === t.name ) {
			pill( t );
		} else if ( t.hasAttribute( 'data-ob-from' ) || t.hasAttribute( 'data-ob-to' ) ) {
			dates();
		} else if ( t.hasAttribute( 'data-ob-annex' ) ) {
			annex();
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		if ( ! go || go.disabled ) {
			e.preventDefault();
			return;
		}
		// The form opens the new tab first; the button changes right after. The
		// tab shows its own waiting page at once (OPT-1862): the button stays
		// off until this tab gets the focus back, 3 s at most (no double click).
		window.setTimeout( function () {
			go.disabled = true;
			go.innerHTML = '<span class="ob-reports__spin" aria-hidden="true"></span><span></span>';
			go.lastChild.textContent = cfg.generating || '';
			if ( note ) {
				note.textContent = cfg.buildNote || '';
			}
			var back = function () {
				window.removeEventListener( 'focus', back );
				restore();
			};
			window.setTimeout( function () {
				window.addEventListener( 'focus', back );
			}, 1500 );
			window.setTimeout( back, 3000 );
		}, 0 );
	} );

	bands();
	annex();
} )();
