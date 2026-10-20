/**
 * Shop screen (OPT-1228): sortable tables and "Show more" of the payment errors.
 *
 * Sorting: a click on `th[data-sortable] .ob-shop__sort` sorts the rows of its
 * table by the `data-sort` value of that column (numbers when both are
 * numbers, else text), first click = descending. Tables inside
 * `.ob-shop__pay` keep the rows after the 5th hidden while folded (sort ALL
 * rows, then hide again). No request, no library.
 *
 * @package Opti_Behavior
 */
( function () {
	'use strict';

	var VISIBLE = 5;
	var cfg = window.optiBehaviorShop || {};

	function cellValue( row, index ) {
		var cell = row.children[ index ];
		var raw = cell ? cell.getAttribute( 'data-sort' ) : '';
		var num = parseFloat( raw );
		return { raw: raw || '', num: num, isNum: raw !== '' && ! isNaN( num ) };
	}

	function foldRows( table ) {
		var pay = table.closest( '.ob-shop__pay' );
		if ( ! pay ) {
			return;
		}
		var button = pay.querySelector( '.ob-shop__pay-more' );
		var open = button && 'true' === button.getAttribute( 'aria-expanded' );
		Array.prototype.forEach.call( table.tBodies[ 0 ].rows, function ( row, i ) {
			row.hidden = ! open && i >= VISIBLE;
		} );
	}

	function sortBy( th ) {
		var table = th.closest( 'table' );
		if ( ! table || ! table.tBodies.length ) {
			return;
		}
		var index = Array.prototype.indexOf.call( th.parentNode.children, th );
		var dir = 'descending' === th.getAttribute( 'aria-sort' ) ? 'ascending' : 'descending';
		Array.prototype.forEach.call( th.parentNode.children, function ( other ) {
			other.removeAttribute( 'aria-sort' );
		} );
		th.setAttribute( 'aria-sort', dir );

		var body = table.tBodies[ 0 ];
		var rows = Array.prototype.slice.call( body.rows );
		rows.sort( function ( a, b ) {
			var x = cellValue( a, index );
			var y = cellValue( b, index );
			var d = ( x.isNum && y.isNum ) ? x.num - y.num : x.raw.localeCompare( y.raw );
			return 'ascending' === dir ? d : -d;
		} );
		rows.forEach( function ( row ) {
			body.appendChild( row );
		} );
		foldRows( table );
	}

	function moreButton( button ) {
		var pay = button.closest( '.ob-shop__pay' );
		var table = pay ? pay.querySelector( '.ob-shop__table' ) : null;
		if ( ! table ) {
			return;
		}
		var open = 'true' !== button.getAttribute( 'aria-expanded' );
		button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		var label = button.querySelector( '.ob-shop__pay-more-label' );
		var hidden = Math.max( 0, table.tBodies[ 0 ].rows.length - VISIBLE );
		if ( label ) {
			label.textContent = open ? ( cfg.showLess || 'Show fewer' ) : String( cfg.showMore || 'Show %d more error codes' ).replace( '%d', hidden );
		}
		foldRows( table );
	}

	document.addEventListener( 'click', function ( event ) {
		var sort = event.target.closest ? event.target.closest( '.ob-shop__sort' ) : null;
		if ( sort ) {
			var th = sort.closest( 'th[data-sortable]' );
			if ( th ) {
				sortBy( th );
			}
			return;
		}
		var more = event.target.closest ? event.target.closest( '.ob-shop__pay-more' ) : null;
		if ( more ) {
			moreButton( more );
		}
	} );
}() );
