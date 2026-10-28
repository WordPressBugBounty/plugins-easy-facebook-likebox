/**
 * Shared feed loading overlay + staggered post reveal.
 *
 * Hides {@see esf_get_simple_loader_html()} once images are ready, then fades
 * posts in one-by-one. Row-wave layouts keep their own entrance animation.
 *
 * Covers Facebook, Instagram, Twitter, YouTube, TikTok, and Social Wall feed roots.
 *
 * Public API:
 *   window.esfFeedRevealItems( scope, { onlyNew?: boolean } )
 *
 * @package Easy_Social_Feed
 * @subpackage Shared/Frontend
 * @since 6.7.9
 */

( function () {
	'use strict';

	var FEED_ROOT_SELECTOR = [
		'[id^="esf-facebook-feed-"]',
		'[id^="esf-instagram-feed-"]',
		'[id^="esf-twitter-feed-"]',
		'[id^="esf-youtube-feed-"]',
		'[id^="esf-tiktok-feed-"]',
		'[id^="esf-social_wall-feed-"]',
	].join( ', ' );

	// Module cards use <article data-post-id>; Social Wall tile cells use a div.
	var ITEM_SELECTOR =
		'article[data-post-id], .esf-social-wall-feed__tile-cell[data-post-id]';
	var WAVE_ANCESTOR_SELECTOR = '[class*="__row--wave"]';
	var REVEAL_ITEM_CLASS = 'esf-feed-reveal-item';
	var PENDING_CLASS = 'esf-feed--reveal-pending';
	var REVEALING_CLASS = 'esf-feed--revealing';
	var REVEALED_CLASS = 'is-revealed';
	var INIT_ATTR = 'data-esf-feed-loading';

	function prefersReducedMotion() {
		return (
			window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		);
	}

	/**
	 * General setting (default on) + OS reduced-motion preference.
	 *
	 * @return {boolean}
	 */
	function shouldAnimateEntrance() {
		if ( prefersReducedMotion() ) {
			return false;
		}

		var cfg =
			typeof esfFeedLoading !== 'undefined' && esfFeedLoading
				? esfFeedLoading
				: null;
		if ( ! cfg ) {
			return true;
		}

		var flag = cfg.entranceAnimation;
		// Real boolean from wp_add_inline_script, or legacy localize strings.
		if (
			flag === false ||
			flag === 0 ||
			flag === '0' ||
			flag === '' ||
			flag === 'false' ||
			flag === 'no' ||
			flag === 'off'
		) {
			return false;
		}

		return true;
	}

	/**
	 * Instantly show items without staggered classes/animation.
	 *
	 * @param {HTMLElement[]} items
	 * @param {HTMLElement|null} container
	 * @return {void}
	 */
	function revealItemsImmediately( items, container ) {
		var i;
		for ( i = 0; i < items.length; i++ ) {
			items[ i ].classList.remove( REVEAL_ITEM_CLASS );
			items[ i ].classList.add( REVEALED_CLASS );
			items[ i ].style.removeProperty( '--esf-reveal-index' );
		}
		if ( container && container.classList ) {
			container.classList.remove( PENDING_CLASS, REVEALING_CLASS );
		}
	}

	function getContentRoot( container ) {
		var children = container.children;
		var i;
		for ( i = 0; i < children.length; i++ ) {
			if ( ! children[ i ].classList.contains( 'esf-loading-overlay' ) ) {
				return children[ i ];
			}
		}
		return null;
	}

	function isInsideWave( el ) {
		return !!( el && el.closest && el.closest( WAVE_ANCESTOR_SELECTOR ) );
	}

	/**
	 * Collect revealable post cells inside a scope.
	 *
	 * @param {ParentNode} scope
	 * @param {boolean}    onlyNew Only items not yet marked revealed.
	 * @return {HTMLElement[]}
	 */
	function collectRevealItems( scope, onlyNew ) {
		if ( ! scope || ! scope.querySelectorAll ) {
			return [];
		}

		var nodes = scope.querySelectorAll( ITEM_SELECTOR );
		var items = [];
		var i;
		var el;

		for ( i = 0; i < nodes.length; i++ ) {
			el = nodes[ i ];
			if ( isInsideWave( el ) ) {
				continue;
			}
			if ( onlyNew && el.classList.contains( REVEALED_CLASS ) ) {
				continue;
			}
			items.push( el );
		}

		return items;
	}

	/**
	 * Prepare items (hidden under overlay) before the spinner is dismissed.
	 *
	 * @param {HTMLElement} container Feed root.
	 * @return {HTMLElement[]}
	 */
	function prepareReveal( container ) {
		var items = collectRevealItems( container, false );
		var i;

		if ( ! items.length ) {
			return items;
		}

		if ( ! shouldAnimateEntrance() ) {
			revealItemsImmediately( items, container );
			return items;
		}

		for ( i = 0; i < items.length; i++ ) {
			items[ i ].classList.add( REVEAL_ITEM_CLASS );
			items[ i ].classList.remove( REVEALED_CLASS );
			items[ i ].style.setProperty( '--esf-reveal-index', String( i ) );
		}

		container.classList.add( PENDING_CLASS );

		return items;
	}

	/**
	 * Play staggered entrance for items in a scope.
	 *
	 * @param {ParentNode}          scope
	 * @param {{ onlyNew?: boolean }} [opts]
	 */
	function revealItems( scope, opts ) {
		opts = opts || {};
		var onlyNew = !! opts.onlyNew;
		var container =
			scope && scope.nodeType === 1
				? scope.closest
					? scope.closest( FEED_ROOT_SELECTOR ) || scope
					: scope
				: null;

		if ( ! container || ! container.classList ) {
			container = scope;
		}

		var items = collectRevealItems( scope || container, onlyNew );
		var i;
		var pending;

		if ( ! items.length ) {
			if ( container && container.classList ) {
				container.classList.remove( PENDING_CLASS, REVEALING_CLASS );
			}
			return;
		}

		for ( i = 0; i < items.length; i++ ) {
			items[ i ].classList.add( REVEAL_ITEM_CLASS );
			items[ i ].classList.remove( REVEALED_CLASS );
			items[ i ].style.setProperty( '--esf-reveal-index', String( i ) );
		}

		if ( ! shouldAnimateEntrance() ) {
			revealItemsImmediately( items, container );
			return;
		}

		if ( container && container.classList ) {
			container.classList.add( PENDING_CLASS );
			container.classList.remove( REVEALING_CLASS );
		}

		// Force reflow so animation restarts for Load More batches.
		void items[ 0 ].offsetWidth;

		if ( container && container.classList ) {
			// Keep pending until revealing is applied so opacity never snaps to 1.
			container.classList.add( REVEALING_CLASS );
			container.classList.remove( PENDING_CLASS );
		}

		pending = items.length;

		function onItemDone( el ) {
			el.classList.add( REVEALED_CLASS );
			pending -= 1;
			if ( pending <= 0 && container && container.classList ) {
				container.classList.remove( REVEALING_CLASS );
			}
		}

		for ( i = 0; i < items.length; i++ ) {
			( function ( el, index ) {
				var settled = false;
				function finish() {
					if ( settled ) {
						return;
					}
					settled = true;
					el.removeEventListener( 'animationend', finish );
					onItemDone( el );
				}
				el.addEventListener( 'animationend', finish );
				// Fallback: duration (450ms) + delay step (120ms) × index, plus buffer.
				window.setTimeout( finish, 450 + index * 120 + 200 );
			} )( items[ i ], i );
		}
	}

	function hideLoader( loader, container ) {
		if ( ! loader || loader.classList.contains( 'is-hidden' ) ) {
			return;
		}

		// Start the fade-in first so posts are held at opacity 0 (fill-mode: both)
		// before the overlay becomes transparent — prevents flash-then-re-animate.
		if ( container ) {
			if (
				typeof window.esfMasonryPack !== 'undefined' &&
				typeof window.esfMasonryPack.sync === 'function'
			) {
				window.esfMasonryPack.sync( container );
			}
			revealItems( container, { onlyNew: false } );
		}

		loader.style.opacity = '0';

		window.setTimeout( function () {
			loader.classList.add( 'is-hidden' );
			loader.style.display = 'none';
		}, 280 );
	}

	function initFeedLoader( container ) {
		if ( ! container || container.getAttribute( INIT_ATTR ) === '1' ) {
			return;
		}
		container.setAttribute( INIT_ATTR, '1' );

		var loader = container.querySelector( '.esf-loading-overlay' );
		var content = getContentRoot( container );

		prepareReveal( container );

		if ( ! loader ) {
			revealItems( container, { onlyNew: false } );
			return;
		}

		if ( content ) {
			var images = content.querySelectorAll( 'img' );
			var loadedCount = 0;
			var totalImages = images.length;
			var done = false;

			function finish() {
				if ( done ) {
					return;
				}
				done = true;
				hideLoader( loader, container );
			}

			if ( totalImages === 0 ) {
				finish();
				return;
			}

			function onImageSettled() {
				loadedCount += 1;
				if ( loadedCount >= totalImages ) {
					finish();
				}
			}

			Array.prototype.forEach.call( images, function ( img ) {
				if ( img.complete ) {
					onImageSettled();
				} else {
					img.addEventListener( 'load', onImageSettled );
					img.addEventListener( 'error', onImageSettled );
				}
			} );

			window.setTimeout( finish, 1500 );
		} else {
			window.setTimeout( function () {
				hideLoader( loader, container );
			}, 1000 );
		}
	}

	function initAll() {
		var containers = document.querySelectorAll( FEED_ROOT_SELECTOR );
		Array.prototype.forEach.call( containers, initFeedLoader );
	}

	window.esfFeedRevealItems = revealItems;

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	if ( typeof MutationObserver !== 'undefined' ) {
		var observer = new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				Array.prototype.forEach.call( mutation.addedNodes, function ( node ) {
					if ( node.nodeType !== 1 ) {
						return;
					}
					if ( node.matches && node.matches( FEED_ROOT_SELECTOR ) ) {
						initFeedLoader( node );
					} else if ( node.querySelectorAll ) {
						Array.prototype.forEach.call(
							node.querySelectorAll( FEED_ROOT_SELECTOR ),
							initFeedLoader
						);
					}
				} );
			} );
		} );

		observer.observe( document.documentElement, {
			childList: true,
			subtree: true,
		} );
	}
}() );
