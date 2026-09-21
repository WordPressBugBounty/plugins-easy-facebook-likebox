/**
 * Facebook feed — caption See more / See less (ExpandableCaption).
 *
 * Shared across layouts via LayoutDefinition.assets.layout_js and injected
 * into the customize preview via PreviewResponseBuilder `js_url`. Uses event
 * delegation so Load More / preview HTML works without re-init.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Frontend
 * @since 6.7.8
 */
( function () {
	'use strict';

	// Preview asset re-injection must not stack duplicate document listeners.
	if ( window.esfFacebookCaptionBound ) {
		return;
	}
	window.esfFacebookCaptionBound = true;

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

		var preview = wrap.querySelector( '.esf-facebook-feed__caption-preview' );
		var full = wrap.querySelector( '.esf-facebook-feed__caption-full' );
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
		if ( ! button || ! button.closest( '.esf-facebook-feed' ) ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		toggleCaption( button );
	} );
}() );
