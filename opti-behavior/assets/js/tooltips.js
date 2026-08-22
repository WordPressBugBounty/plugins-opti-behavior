/**
 * Opti-Behavior Smart Tooltip Positioning
 *
 * Positions tooltip popups with fixed viewport coordinates so WordPress editor
 * panels, metabox wrappers, sticky headers, and scroll containers cannot crop
 * or hide the popup.
 *
 * @package OptiBehavior
 * @version 1.2.0
 */

(function() {
	'use strict';

	const ACTIVE_CLASS = 'is-active';
	const PINNED_CLASS = 'is-pinned';
	const PLACEMENT_CLASSES = [
		'ob-tooltip-bottom',
		'ob-tooltip-left',
		'ob-tooltip-right'
	];
	const CONTENT_PLACEMENT_CLASSES = [
		'ob-tooltip-content-top',
		'ob-tooltip-content-bottom',
		'ob-tooltip-content-left',
		'ob-tooltip-content-right'
	];

	let activeTooltip = null;

	function getPreferredPlacement( tooltip ) {
		if ( ! tooltip.dataset.obPreferredPlacement ) {
			if ( tooltip.classList.contains( 'ob-tooltip-bottom' ) ) {
				tooltip.dataset.obPreferredPlacement = 'bottom';
			} else if ( tooltip.classList.contains( 'ob-tooltip-left' ) ) {
				tooltip.dataset.obPreferredPlacement = 'left';
			} else if ( tooltip.classList.contains( 'ob-tooltip-right' ) ) {
				tooltip.dataset.obPreferredPlacement = 'right';
			} else {
				tooltip.dataset.obPreferredPlacement = 'top';
			}
		}

		return tooltip.dataset.obPreferredPlacement;
	}

	function restorePreferredPlacement( tooltip ) {
		const preferredPlacement = getPreferredPlacement( tooltip );
		tooltip.classList.remove.apply( tooltip.classList, PLACEMENT_CLASSES );

		if ( preferredPlacement !== 'top' ) {
			tooltip.classList.add( 'ob-tooltip-' + preferredPlacement );
		}
	}

	function setActivePlacement( tooltip, placement ) {
		const content = getTooltipContent( tooltip );

		tooltip.classList.remove.apply( tooltip.classList, PLACEMENT_CLASSES );

		if ( placement !== 'top' ) {
			tooltip.classList.add( 'ob-tooltip-' + placement );
		}

		if ( content ) {
			content.classList.remove.apply( content.classList, CONTENT_PLACEMENT_CLASSES );
			content.classList.add( 'ob-tooltip-content-' + placement );
		}

		tooltip.dataset.obActivePlacement = placement;
	}

	function getTooltipContent( tooltip ) {
		return tooltip._obTooltipContent || tooltip.querySelector( '.ob-tooltip-content' );
	}

	function moveContentToBody( tooltip, content ) {
		if ( content.parentNode === document.body ) {
			return;
		}

		tooltip._obTooltipContent = content;
		tooltip._obTooltipNextSibling = content.nextSibling;
		document.body.appendChild( content );
		content.classList.add( 'ob-tooltip-content-portal' );
	}

	function restoreContentToTooltip( tooltip, content ) {
		if ( ! content || content.parentNode !== document.body ) {
			return;
		}

		if ( tooltip._obTooltipNextSibling && tooltip._obTooltipNextSibling.parentNode === tooltip ) {
			tooltip.insertBefore( content, tooltip._obTooltipNextSibling );
		} else {
			tooltip.appendChild( content );
		}

		content.classList.remove( 'ob-tooltip-content-portal' );
		content.classList.remove.apply( content.classList, CONTENT_PLACEMENT_CLASSES );
	}

	function resetTooltipContent( content ) {
		[
			'display',
			'position',
			'top',
			'right',
			'bottom',
			'left',
			'z-index',
			'opacity',
			'pointer-events',
			'transform',
			'max-width',
			'visibility'
		].forEach( function( property ) {
			content.style.removeProperty( property );
		} );

		content.style.removeProperty( '--arrow-position' );
		content.style.removeProperty( '--arrow-top' );
	}

	function closeTooltip( tooltip ) {
		const tooltipToClose = tooltip || activeTooltip;

		if ( ! tooltipToClose ) {
			return;
		}

		const content = getTooltipContent( tooltipToClose );
		const parentCard = tooltipToClose.closest( '.storage-stat-card, .stat-card, .optibehavior-analytics-column, .optibehavior-behavior-stat' );

		tooltipToClose.classList.remove( ACTIVE_CLASS, PINNED_CLASS );
		tooltipToClose.removeAttribute( 'aria-expanded' );
		delete tooltipToClose.dataset.obActivePlacement;
		restorePreferredPlacement( tooltipToClose );

		if ( content ) {
			resetTooltipContent( content );
			restoreContentToTooltip( tooltipToClose, content );
		}

		if ( parentCard ) {
			parentCard.classList.remove( 'tooltip-active' );
		}

		if ( tooltipToClose === activeTooltip ) {
			activeTooltip = null;
		}
	}

	function clamp( value, min, max ) {
		return Math.max( min, Math.min( value, max ) );
	}

	function calculatePlacement( preferredPlacement, triggerRect, contentRect, viewportWidth, viewportHeight, padding, gap ) {
		const enoughAbove = triggerRect.top >= contentRect.height + gap + padding;
		const enoughBelow = viewportHeight - triggerRect.bottom >= contentRect.height + gap + padding;
		const enoughRight = viewportWidth - triggerRect.right >= contentRect.width + gap + padding;
		const enoughLeft = triggerRect.left >= contentRect.width + gap + padding;

		if ( preferredPlacement === 'right' && enoughRight ) {
			return 'right';
		}

		if ( preferredPlacement === 'left' && enoughLeft ) {
			return 'left';
		}

		if ( preferredPlacement === 'bottom' && enoughBelow ) {
			return 'bottom';
		}

		if ( preferredPlacement === 'top' && enoughAbove ) {
			return 'top';
		}

		if ( enoughBelow ) {
			return 'bottom';
		}

		if ( enoughAbove ) {
			return 'top';
		}

		if ( enoughRight ) {
			return 'right';
		}

		if ( enoughLeft ) {
			return 'left';
		}

		return 'bottom';
	}

	function calculateCoordinates( placement, triggerRect, contentRect, viewportWidth, viewportHeight, padding, gap ) {
		const triggerCenterX = triggerRect.left + ( triggerRect.width / 2 );
		const triggerCenterY = triggerRect.top + ( triggerRect.height / 2 );
		let top;
		let left;

		if ( placement === 'right' ) {
			top = triggerCenterY - ( contentRect.height / 2 );
			left = triggerRect.right + gap;
		} else if ( placement === 'left' ) {
			top = triggerCenterY - ( contentRect.height / 2 );
			left = triggerRect.left - contentRect.width - gap;
		} else if ( placement === 'bottom' ) {
			top = triggerRect.bottom + gap;
			left = triggerCenterX - ( contentRect.width / 2 );
		} else {
			top = triggerRect.top - contentRect.height - gap;
			left = triggerCenterX - ( contentRect.width / 2 );
		}

		return {
			top: clamp( top, padding, viewportHeight - contentRect.height - padding ),
			left: clamp( left, padding, viewportWidth - contentRect.width - padding )
		};
	}

	function applyArrowPosition( tooltip, content, placement, triggerRect, coordinates, contentRect ) {
		const triggerCenterX = triggerRect.left + ( triggerRect.width / 2 );
		const triggerCenterY = triggerRect.top + ( triggerRect.height / 2 );
		const arrowPadding = 16;

		if ( placement === 'left' || placement === 'right' ) {
			const arrowTop = clamp(
				triggerCenterY - coordinates.top,
				arrowPadding,
				contentRect.height - arrowPadding
			);

			content.style.setProperty( '--arrow-top', arrowTop + 'px' );
			return;
		}

		const arrowPosition = clamp(
			triggerCenterX - coordinates.left,
			arrowPadding,
			contentRect.width - arrowPadding
		);

		content.style.setProperty( '--arrow-position', arrowPosition + 'px' );
	}

	function positionTooltip( tooltip ) {
		const content = getTooltipContent( tooltip );
		const icon = tooltip.querySelector( '.ob-tooltip-icon' ) || tooltip;

		if ( ! content ) {
			return;
		}

		const preferredPlacement = getPreferredPlacement( tooltip );
		const parentCard = tooltip.closest( '.storage-stat-card, .stat-card, .optibehavior-analytics-column, .optibehavior-behavior-stat' );
		const padding = 16;
		const gap = 12;
		const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
		const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
		const maxWidth = Math.min( 320, Math.max( 220, viewportWidth - ( padding * 2 ) ) );

		if ( activeTooltip && activeTooltip !== tooltip ) {
			closeTooltip( activeTooltip );
		}

		tooltip.classList.add( ACTIVE_CLASS );
		tooltip.setAttribute( 'aria-expanded', 'true' );
		activeTooltip = tooltip;

		if ( parentCard ) {
			parentCard.classList.add( 'tooltip-active' );
		}

		moveContentToBody( tooltip, content );

		content.style.setProperty( 'display', 'block', 'important' );
		content.style.setProperty( 'visibility', 'visible', 'important' );
		content.style.setProperty( 'position', 'fixed', 'important' );
		content.style.setProperty( 'right', 'auto', 'important' );
		content.style.setProperty( 'bottom', 'auto', 'important' );
		content.style.setProperty( 'max-width', maxWidth + 'px', 'important' );
		content.style.setProperty( 'z-index', '1000000', 'important' );
		content.style.setProperty( 'opacity', '1', 'important' );
		content.style.setProperty( 'pointer-events', 'auto', 'important' );
		content.style.setProperty( 'transform', 'none', 'important' );

		// Use the icon rectangle so the arrow points to the visible trigger.
		const triggerRect = icon.getBoundingClientRect();
		let contentRect = content.getBoundingClientRect();
		const placement = calculatePlacement( preferredPlacement, triggerRect, contentRect, viewportWidth, viewportHeight, padding, gap );

		setActivePlacement( tooltip, placement );

		// Re-read after placement class changes, because arrow orientation can affect size.
		contentRect = content.getBoundingClientRect();
		const coordinates = calculateCoordinates( placement, triggerRect, contentRect, viewportWidth, viewportHeight, padding, gap );

		content.style.setProperty( 'left', Math.round( coordinates.left ) + 'px', 'important' );
		content.style.setProperty( 'top', Math.round( coordinates.top ) + 'px', 'important' );

		applyArrowPosition( tooltip, content, placement, triggerRect, coordinates, contentRect );
	}

	function isInteractiveEventTarget( event ) {
		return event.target.closest( 'a, button, input, select, textarea' );
	}

	function bindTooltip( tooltip ) {
		if ( tooltip.dataset.obTooltipBound === '1' ) {
			return;
		}

		tooltip.dataset.obTooltipBound = '1';
		tooltip._obTooltipContent = tooltip.querySelector( '.ob-tooltip-content' );
		getPreferredPlacement( tooltip );
		tooltip.setAttribute( 'aria-expanded', 'false' );

		tooltip.addEventListener( 'mouseenter', function() {
			if ( ! tooltip.classList.contains( PINNED_CLASS ) ) {
				positionTooltip( tooltip );
			}
		} );

		tooltip.addEventListener( 'mouseleave', function() {
			if ( ! tooltip.classList.contains( PINNED_CLASS ) ) {
				closeTooltip( tooltip );
			}
		} );

		tooltip.addEventListener( 'focusin', function() {
			positionTooltip( tooltip );
		} );

		tooltip.addEventListener( 'focusout', function( event ) {
			if ( tooltip.classList.contains( PINNED_CLASS ) ) {
				return;
			}

			if ( event.relatedTarget && tooltip.contains( event.relatedTarget ) ) {
				return;
			}

			closeTooltip( tooltip );
		} );

		tooltip.addEventListener( 'click', function( event ) {
			if ( event.target.closest( '.ob-tooltip-content' ) ) {
				return;
			}

			// Do not block normal links/buttons that happen to be near a tooltip.
			if ( isInteractiveEventTarget( event ) && ! event.target.closest( '.ob-tooltip-icon' ) ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			if ( tooltip.classList.contains( PINNED_CLASS ) ) {
				closeTooltip( tooltip );
				return;
			}

			positionTooltip( tooltip );
			tooltip.classList.add( PINNED_CLASS );
		} );
	}

	function initSmartTooltips() {
		document.querySelectorAll( '.ob-tooltip' ).forEach( bindTooltip );
	}

	window.reinitSmartTooltips = initSmartTooltips;

	// Auto-bind tooltips injected after page load (AJAX-rendered stat cards,
	// tables, etc.), otherwise they fall back to CSS-only positioning and can
	// be clipped at the viewport edge.
	function observeInjectedTooltips() {
		if ( ! document.body || typeof MutationObserver === 'undefined' ) {
			return;
		}

		const observer = new MutationObserver( function( mutations ) {
			for ( let i = 0; i < mutations.length; i++ ) {
				const addedNodes = mutations[ i ].addedNodes;

				for ( let j = 0; j < addedNodes.length; j++ ) {
					const node = addedNodes[ j ];

					if ( node.nodeType !== 1 ) {
						continue;
					}

					if ( node.classList && node.classList.contains( 'ob-tooltip' ) ) {
						bindTooltip( node );
					}

					if ( node.querySelectorAll ) {
						node.querySelectorAll( '.ob-tooltip' ).forEach( bindTooltip );
					}
				}
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	document.addEventListener( 'click', function( event ) {
		if ( ! activeTooltip ) {
			return;
		}

		const activeContent = getTooltipContent( activeTooltip );

		if ( activeTooltip.contains( event.target ) || ( activeContent && activeContent.contains( event.target ) ) ) {
			return;
		}

		closeTooltip( activeTooltip );
	} );

	document.addEventListener( 'keydown', function( event ) {
		if ( event.key === 'Escape' ) {
			closeTooltip();
		}
	} );

	window.addEventListener( 'resize', function() {
		if ( activeTooltip ) {
			positionTooltip( activeTooltip );
		}
	} );

	window.addEventListener( 'scroll', function() {
		if ( activeTooltip ) {
			positionTooltip( activeTooltip );
		}
	}, true );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function() {
			initSmartTooltips();
			observeInjectedTooltips();
		} );
	} else {
		initSmartTooltips();
		observeInjectedTooltips();
	}

	setTimeout( initSmartTooltips, 1000 );
	setTimeout( initSmartTooltips, 3000 );
})();
