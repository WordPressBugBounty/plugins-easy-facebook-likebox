/**
 * Instagram Row wave layout — staggered entrance on load and load more.
 *
 * @package Easy_Social_Feed
 */
( function () {
	'use strict';

	var ANIMATING_CLASS = 'esf-instagram-feed__row--wave-animating';
	var SETTLED_CLASS = 'esf-instagram-feed__row--wave-settled';
	var CELL_SELECTOR = '.esf-instagram-feed__row-cell';
	var ROW_WAVE_SELECTOR = '.esf-instagram-feed__row--wave';

	function prefersReducedMotion() {
		return (
			window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		);
	}

	function restartRowWaveAnimation( row ) {
		if ( ! row || ! row.classList.contains( 'esf-instagram-feed__row--wave' ) ) {
			return;
		}

		row.classList.remove( ANIMATING_CLASS, SETTLED_CLASS );

		if ( prefersReducedMotion() ) {
			row.classList.add( SETTLED_CLASS );
			return;
		}

		var cells = row.querySelectorAll( CELL_SELECTOR );
		if ( ! cells.length ) {
			row.classList.add( SETTLED_CLASS );
			return;
		}

		cells.forEach( function ( cell, index ) {
			cell.style.setProperty( '--esf-ig-wave-stagger-index', String( index ) );
		} );

		void row.offsetWidth;

		row.classList.add( ANIMATING_CLASS );

		var pending = cells.length;
		function onCellAnimationEnd() {
			pending -= 1;
			if ( pending > 0 ) {
				return;
			}
			row.classList.remove( ANIMATING_CLASS );
			row.classList.add( SETTLED_CLASS );
		}

		cells.forEach( function ( cell ) {
			cell.addEventListener( 'animationend', onCellAnimationEnd, { once: true } );
		} );
	}

	function playInstagramRowWave( scope ) {
		var root = scope && scope.querySelectorAll ? scope : document;
		root.querySelectorAll( ROW_WAVE_SELECTOR ).forEach( function ( row ) {
			restartRowWaveAnimation( row );
		} );
	}

	window.esfInstagramPlayRowWave = playInstagramRowWave;

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			playInstagramRowWave();
		} );
	} else {
		playInstagramRowWave();
	}
}() );
