/**
 * YouTube feed — caption / header description See more / See less (ExpandableCaption).
 *
 * Shared across layouts (header on every layout; card captions on Half/Full Width).
 * Uses event delegation so Load More / preview HTML works without re-init.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Frontend
 * @since 6.7.5
 */
( function () {
	'use strict';

	// Preview asset re-injection must not stack duplicate document listeners.
	if ( window.esfYouTubeCaptionBound ) {
		return;
	}
	window.esfYouTubeCaptionBound = true;

	/**
	 * Toggle caption preview / full markup.
	 *
	 * @param {HTMLElement} button Toggle control.
	 * @return {void}
	 */
	function toggleCaption( button ) {
		var wrap = button.closest( '[data-esf-caption-expandable]' );
		if ( ! wrap ) {
			return;
		}

		var preview = wrap.querySelector( '.esf-youtube-feed__caption-preview' );
		var full = wrap.querySelector( '.esf-youtube-feed__caption-full' );
		if ( ! preview || ! full ) {
			return;
		}

		var expanded = button.getAttribute( 'aria-expanded' ) === 'true';
		var nextExpanded = ! expanded;
		var moreLabel = button.getAttribute( 'data-label-more' ) || 'See more';
		var lessLabel = button.getAttribute( 'data-label-less' ) || 'See less';

		button.setAttribute( 'aria-expanded', nextExpanded ? 'true' : 'false' );
		button.textContent = nextExpanded ? lessLabel : moreLabel;

		if ( nextExpanded ) {
			wrap.classList.remove( 'is-collapsed' );
			wrap.classList.add( 'is-expanded' );
			preview.hidden = true;
			preview.setAttribute( 'aria-hidden', 'true' );
			full.hidden = false;
			full.setAttribute( 'aria-hidden', 'false' );
		} else {
			wrap.classList.add( 'is-collapsed' );
			wrap.classList.remove( 'is-expanded' );
			preview.hidden = false;
			preview.setAttribute( 'aria-hidden', 'false' );
			full.hidden = true;
			full.setAttribute( 'aria-hidden', 'true' );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var button = target.closest( '[data-esf-caption-toggle]' );
		if ( ! button || ! button.closest( '.esf-yt-feed, .esf-youtube-feed' ) ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		toggleCaption( button );
	} );
}() );
