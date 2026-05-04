/**
 * Twitter Feed - public frontend (Load More).
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Frontend
 * @since 6.7.6
 */

( function () {
	'use strict';

	var restUrl = typeof esfTwitterPublic !== 'undefined' ? esfTwitterPublic.restUrl : '';

	function closeShareMenusOutsideTarget( target ) {
		var openMenus = document.querySelectorAll( '.esf-tw-feed__share-menu[open]' );
		if ( ! openMenus.length ) {
			return;
		}

		openMenus.forEach( function ( menu ) {
			if ( target && menu.contains( target ) ) {
				return;
			}
			menu.removeAttribute( 'open' );
		} );
	}

	function onLoadMoreClick( evt ) {
		var btn = evt.target.closest( '.esf-tw-feed__load-more' );
		if ( ! btn || btn.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}

		var feedId = btn.getAttribute( 'data-feed-id' );
		var offset = parseInt( btn.getAttribute( 'data-offset' ), 10 );
		var nextToken = btn.getAttribute( 'data-next-token' ) || '';
		var timeline = btn.closest( '.esf-tw-feed__timeline' );
		var listEl = timeline ? timeline.querySelector( '.esf-tw-feed__timeline-list' ) : null;

		if ( ! feedId || isNaN( offset ) || ! listEl || ! restUrl ) {
			return;
		}

		evt.preventDefault();
		btn.setAttribute( 'aria-busy', 'true' );
		btn.disabled = true;
		var originalText = btn.textContent;
		btn.textContent = typeof esfTwitterPublic !== 'undefined' && esfTwitterPublic.loadingText
			? esfTwitterPublic.loadingText
			: 'Loading…';

		var url = restUrl + '/twitter/feeds/' + feedId + '/load-more?offset=' + offset;
		if ( nextToken ) {
			url += '&next_token=' + encodeURIComponent( nextToken );
		}

		fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': typeof esfTwitterPublic !== 'undefined' ? ( esfTwitterPublic.nonce || '' ) : '',
			},
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				if ( data && data.html ) {
					listEl.insertAdjacentHTML( 'beforeend', data.html );
				}
				if ( data && data.next_offset !== undefined ) {
					btn.setAttribute( 'data-offset', String( data.next_offset ) );
				}
				if ( data && data.next_token !== undefined ) {
					btn.setAttribute( 'data-next-token', String( data.next_token || '' ) );
				}
				if ( ! data || ! data.has_more ) {
					var wrap = btn.closest( '.esf-tw-feed__load-more-wrap' );
					if ( wrap ) {
						wrap.style.display = 'none';
					}
				}
			} )
			.catch( function () {
				btn.setAttribute( 'aria-busy', 'false' );
				btn.disabled = false;
				btn.textContent = originalText;
			} )
			.then( function () {
				btn.setAttribute( 'aria-busy', 'false' );
				btn.disabled = false;
				btn.textContent = originalText;
			} );
	}

	document.addEventListener( 'click', function ( evt ) {
		// Auto-close open share popovers when clicking outside them.
		closeShareMenusOutsideTarget( evt.target );

		if ( evt.target.closest( '.esf-tw-feed__load-more' ) ) {
			onLoadMoreClick( evt );
		}
	} );

	document.addEventListener( 'keydown', function ( evt ) {
		if ( 'Escape' === evt.key ) {
			closeShareMenusOutsideTarget( null );
		}
	} );
}() );
