/**
 * Parse XFBML after Facebook SDK loads for modern Likebox embeds.
 *
 * Scopes parse to `.esf-facebook-likebox` roots (not the whole document) so
 * re-renders / theme mutations don't leave the SDK looking for removed nodes.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Frontend
 * @since 6.7.7
 */

( function () {
	'use strict';

	var parseTimer = null;

	function parseLikeboxes() {
		if (
			! window.FB ||
			typeof window.FB.XFBML !== 'object' ||
			typeof window.FB.XFBML.parse !== 'function'
		) {
			return;
		}

		var roots = document.querySelectorAll( '.esf-facebook-likebox' );
		if ( ! roots.length ) {
			return;
		}

		Array.prototype.forEach.call( roots, function ( root ) {
			try {
				window.FB.XFBML.parse( root );
			} catch ( _err ) {
				// Non-fatal — Meta SDK may throw if a node was replaced mid-parse.
			}
		} );
	}

	function scheduleParse() {
		if ( parseTimer ) {
			window.clearTimeout( parseTimer );
		}
		parseTimer = window.setTimeout( parseLikeboxes, 50 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', scheduleParse );
	} else {
		scheduleParse();
	}

	window.addEventListener( 'load', scheduleParse );

	// SDK async: fbAsyncInit may fire after our first attempt.
	var previous = window.fbAsyncInit;
	window.fbAsyncInit = function () {
		if ( typeof previous === 'function' ) {
			previous.apply( this, arguments );
		}
		scheduleParse();
	};
}() );
