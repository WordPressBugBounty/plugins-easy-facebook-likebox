/**
 * Twitter Feed - public frontend (share menus + Load More).
 *
 * Load More REST client is Freemius-stripped from free builds.
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

	

	document.addEventListener( 'click', function ( evt ) {
		// Auto-close open share popovers when clicking outside them.
		closeShareMenusOutsideTarget( evt.target );

		
	} );

	document.addEventListener( 'keydown', function ( evt ) {
		if ( 'Escape' === evt.key ) {
			closeShareMenusOutsideTarget( null );
		}
	} );
}() );
