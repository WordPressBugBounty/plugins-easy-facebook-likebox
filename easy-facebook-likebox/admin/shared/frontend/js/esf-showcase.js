/**
 * Shared showcase player — featured embed above a feed.
 *
 * Contract:
 * - Feed root: `[data-esf-playback="showcase"]`
 * - Stage:     `[data-esf-showcase]` with optional `data-esf-showcase-active` JSON
 * - Facade:    `[data-esf-showcase-play]` creates the iframe on first click
 * - Items:     `[data-esf-showcase-item]` JSON payload `{ title, embed_url, watch_url, aspect_ratio, poster_url }`
 *
 * Module-agnostic: embed host is validated client-side; the module supplies URLs.
 *
 * @package Easy_Social_Feed
 * @since 6.18.0
 */
( function () {
	'use strict';

	if ( window.esfShowcaseBound ) {
		return;
	}
	window.esfShowcaseBound = true;

	var ROOT_SEL = '[data-esf-playback="showcase"]';
	var ITEM_SEL = '[data-esf-showcase-item]';
	var ACTIVE_CLASS = 'is-esf-showcase-active';

	/**
	 * @param {Element} el
	 * @return {Object|null}
	 */
	function parseItem( el ) {
		if ( ! el ) {
			return null;
		}
		var raw =
			el.getAttribute( 'data-esf-showcase-item' ) ||
			el.getAttribute( 'data-esf-showcase-active' ) ||
			'';
		if ( ! raw ) {
			return null;
		}
		try {
			var data = JSON.parse( raw );
			return data && typeof data === 'object' ? data : null;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Hosts the module allows for this stage (`data-esf-showcase-hosts`).
	 * Nothing is allowed when the module supplies none.
	 *
	 * @param {Element|null} showcase
	 * @return {string[]}
	 */
	function getAllowedHosts( showcase ) {
		var raw = showcase ? showcase.getAttribute( 'data-esf-showcase-hosts' ) : '';
		return raw ? raw.toLowerCase().split( ',' ) : [];
	}

	/**
	 * @param {string} url
	 * @param {Element|null} showcase Stage root carrying the host allowlist.
	 * @return {boolean}
	 */
	function isAllowedEmbedUrl( url, showcase ) {
		if ( ! url || typeof url !== 'string' ) {
			return false;
		}
		if ( url.indexOf( 'https://' ) !== 0 ) {
			return false;
		}
		try {
			var host = new URL( url ).hostname.toLowerCase();
			return getAllowedHosts( showcase ).indexOf( host ) !== -1;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * @param {Element} root
	 * @return {Element|null}
	 */
	function getShowcase( root ) {
		return root ? root.querySelector( '[data-esf-showcase]' ) : null;
	}

	/**
	 * @param {Element} showcase
	 * @param {string} aspect
	 */
	function setAspect( showcase, aspect ) {
		if ( ! showcase || ! aspect ) {
			return;
		}
		var parts = String( aspect ).split( ':' );
		var w = parseFloat( parts[ 0 ] );
		var h = parseFloat( parts[ 1 ] );
		if ( ! ( w > 0 ) || ! ( h > 0 ) ) {
			return;
		}
		showcase.style.setProperty( '--esf-showcase-ratio', w + ' / ' + h );
		showcase.style.setProperty(
			'--esf-showcase-ratio-num',
			String( Math.round( ( w / h ) * 10000 ) / 10000 )
		);
		showcase.setAttribute(
			'data-esf-showcase-orient',
			w < h ? 'portrait' : 'landscape'
		);
	}

	/**
	 * @param {Element} showcase
	 * @param {Object} item
	 */
	function updateMeta( showcase, item ) {
		var titleEl = showcase.querySelector( '[data-esf-showcase-title]' );
		if ( titleEl ) {
			var title = item && item.title ? String( item.title ) : '';
			titleEl.textContent = title;
			if ( title ) {
				titleEl.removeAttribute( 'hidden' );
			} else {
				titleEl.setAttribute( 'hidden', '' );
			}
		}
		var watchEl = showcase.querySelector( '[data-esf-showcase-watch]' );
		if ( watchEl && item && item.watch_url ) {
			watchEl.setAttribute( 'href', String( item.watch_url ) );
		}
	}

	/**
	 * @param {Element} stage
	 * @param {Object} item
	 * @param {boolean} autoplay
	 */
	function ensureIframe( stage, item, autoplay ) {
		var frame = stage.querySelector( '[data-esf-showcase-frame]' );
		var showcase = stage.closest ? stage.closest( '[data-esf-showcase]' ) : null;
		if ( ! frame || ! item || ! isAllowedEmbedUrl( item.embed_url, showcase ) ) {
			return null;
		}

		var src = String( item.embed_url );
		if ( autoplay && src.indexOf( 'autoplay=1' ) === -1 ) {
			src += ( src.indexOf( '?' ) === -1 ? '?' : '&' ) + 'autoplay=1';
		}

		var iframe = frame.querySelector( 'iframe' );
		if ( ! iframe ) {
			iframe = document.createElement( 'iframe' );
			iframe.setAttribute( 'title', item.title ? String( item.title ) : 'Video' );
			iframe.setAttribute( 'allowfullscreen', '' );
			iframe.setAttribute(
				'allow',
				'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share'
			);
			iframe.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
			iframe.setAttribute( 'loading', 'lazy' );
			frame.appendChild( iframe );
		}

		if ( iframe.getAttribute( 'src' ) !== src ) {
			iframe.setAttribute( 'src', src );
		}
		frame.hidden = false;

		var facade = stage.querySelector( '[data-esf-showcase-play], [class$="-feed__showcase-facade"]' );
		if ( facade ) {
			facade.hidden = true;
		}

		try {
			stage.setAttribute( 'data-esf-showcase-active', JSON.stringify( item ) );
		} catch ( e ) {
			// Ignore serialization errors.
		}

		return iframe;
	}

	/**
	 * @param {Element} root
	 * @param {Element} activeItem
	 */
	function markActive( root, activeItem ) {
		// Looping carousels clone slides, so the same video can exist more than
		// once. Match by video id so every copy shows the active state.
		var activeData = activeItem ? parseItem( activeItem ) : null;
		var activeId = activeData && activeData.video_id ? String( activeData.video_id ) : '';
		var items = root.querySelectorAll( ITEM_SEL );
		for ( var i = 0; i < items.length; i++ ) {
			var el = items[ i ];
			var isActive = el === activeItem;
			if ( ! isActive && activeId ) {
				var data = parseItem( el );
				isActive = !! data && String( data.video_id || '' ) === activeId;
			}
			if ( isActive ) {
				el.classList.add( ACTIVE_CLASS );
				el.setAttribute( 'aria-current', 'true' );
				addBadge( root, el );
			} else {
				el.classList.remove( ACTIVE_CLASS );
				el.removeAttribute( 'aria-current' );
				removeBadge( el );
			}
		}
	}

	/**
	 * Stop a carousel's auto-advance once the viewer picked a video, so the
	 * strip doesn't slide away from the item that is playing.
	 *
	 * @param {Element} root
	 */
	function pauseCarousel( root ) {
		var carousels = root.querySelectorAll( '.esf-carousel' );
		for ( var i = 0; i < carousels.length; i++ ) {
			var car = carousels[ i ];
			car.setAttribute( 'data-esf-autoplay-paused', '1' );
			var state = car.esfCarouselState;
			if ( state && state.autoplayTimer ) {
				window.clearInterval( state.autoplayTimer );
				state.autoplayTimer = null;
			}
		}
	}

	/**
	 * Thumbnail container of a feed item (badge host), falling back to the item.
	 *
	 * @param {Element} item
	 * @return {Element}
	 */
	function getBadgeHost( item ) {
		return (
			item.querySelector( '[class*="__card-thumb"], [class*="__tile-thumb"], [class*="__thumb"]' ) ||
			// MediaTile anchor (Row / Masonry / Half & Full Width) and carousel media box.
			item.querySelector( '[class$="__tile"], [class*="__tile "], [class*="__carousel-media"]' ) ||
			item
		);
	}

	/**
	 * @param {Element} item
	 */
	function removeBadge( item ) {
		var badge = item.querySelector( '.esf-showcase-badge' );
		if ( badge && badge.parentNode ) {
			badge.parentNode.classList.remove( 'esf-showcase-badge-host' );
			badge.parentNode.removeChild( badge );
		}
	}

	/**
	 * Add a "Now playing" badge to the active item (label comes from the stage).
	 *
	 * @param {Element} root
	 * @param {Element} item
	 */
	function addBadge( root, item ) {
		var showcase = getShowcase( root );
		var label = showcase ? showcase.getAttribute( 'data-esf-showcase-now-label' ) : '';
		if ( ! label || item.querySelector( '.esf-showcase-badge' ) ) {
			return;
		}
		var host = getBadgeHost( item );
		var badge = document.createElement( 'span' );
		badge.className = 'esf-showcase-badge';
		badge.setAttribute( 'aria-hidden', 'true' );

		var eq = document.createElement( 'span' );
		eq.className = 'esf-showcase-eq';
		for ( var i = 0; i < 3; i++ ) {
			eq.appendChild( document.createElement( 'i' ) );
		}
		badge.appendChild( eq );
		badge.appendChild( document.createTextNode( label ) );

		host.classList.add( 'esf-showcase-badge-host' );
		host.appendChild( badge );
	}

	/**
	 * Badge the server-rendered active item on load.
	 */
	function init() {
		var roots = document.querySelectorAll( ROOT_SEL );
		for ( var i = 0; i < roots.length; i++ ) {
			// All matches: looping carousels render the active slide's clones too.
			var actives = roots[ i ].querySelectorAll( '.' + ACTIVE_CLASS );
			for ( var j = 0; j < actives.length; j++ ) {
				addBadge( roots[ i ], actives[ j ] );
			}
		}
	}

	/**
	 * Breathing room kept above the stage when it is scrolled into view, so the
	 * player never sits flush against the top of the viewport.
	 * Override per stage with `data-esf-showcase-scroll-offset="<px>"`.
	 */
	var SCROLL_TOP_OFFSET = 25;

	/**
	 * @param {Element} showcase
	 * @return {number} Offset in px (>= 0).
	 */
	function getScrollTopOffset( showcase ) {
		var raw = showcase.getAttribute( 'data-esf-showcase-scroll-offset' );
		var offset = raw === null ? NaN : parseInt( raw, 10 );
		return isNaN( offset ) || offset < 0 ? SCROLL_TOP_OFFSET : offset;
	}

	/**
	 * Bring the stage into view, leaving SCROLL_TOP_OFFSET px above it.
	 *
	 * Does nothing when the stage is already fully visible with that gap, so
	 * the page never jumps needlessly.
	 *
	 * @param {Element} showcase
	 */
	function scrollToStage( showcase ) {
		if ( ! showcase || typeof showcase.scrollIntoView !== 'function' ) {
			return;
		}

		var offset = getScrollTopOffset( showcase );
		var rect = showcase.getBoundingClientRect();
		var viewport =
			window.innerHeight || document.documentElement.clientHeight || 0;
		if ( rect.top >= offset && rect.bottom <= viewport ) {
			return;
		}

		// scrollIntoView honours scroll-margin-top (works for any scroll
		// container); browsers without support fall back to a flush scroll.
		showcase.style.scrollMarginTop = offset + 'px';

		var reduce =
			window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		try {
			showcase.scrollIntoView( {
				behavior: reduce ? 'auto' : 'smooth',
				block: 'start',
			} );
		} catch ( e ) {
			showcase.scrollIntoView( true );
		}
	}

	/**
	 * @param {Element} root
	 * @param {Object} item
	 * @param {Element|null} sourceItem
	 * @param {boolean} autoplay
	 */
	function activate( root, item, sourceItem, autoplay ) {
		var showcase = getShowcase( root );
		if ( ! showcase || ! item ) {
			return;
		}
		var stage = showcase.querySelector( '[class*="__showcase-stage"]' ) || showcase.firstElementChild;
		if ( ! stage ) {
			return;
		}

		if ( item.aspect_ratio ) {
			setAspect( showcase, item.aspect_ratio );
		}
		updateMeta( showcase, item );
		if ( ensureIframe( stage, item, !! autoplay ) ) {
			root.classList.add( 'is-esf-showcase-playing' );
		}
		markActive( root, sourceItem );
		pauseCarousel( root );
		scrollToStage( showcase );
	}

	/**
	 * @param {Event} event
	 */
	function onClick( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}

		var root = target.closest( ROOT_SEL );
		if ( ! root ) {
			return;
		}

		var playBtn = target.closest( '[data-esf-showcase-play]' );
		if ( playBtn && root.contains( playBtn ) ) {
			event.preventDefault();
			var showcase = getShowcase( root );
			var stage = showcase
				? showcase.querySelector( '[class*="__showcase-stage"]' )
				: null;
			var item = parseItem( stage );
			if ( item ) {
				activate( root, item, root.querySelector( '.' + ACTIVE_CLASS ) || root.querySelector( ITEM_SEL ), true );
			}
			return;
		}

		var itemEl = target.closest( ITEM_SEL );
		if ( ! itemEl || ! root.contains( itemEl ) ) {
			return;
		}

		var item = parseItem( itemEl );
		if ( ! item || ! isAllowedEmbedUrl( item.embed_url, getShowcase( root ) ) ) {
			// Let the native link open when embeds are blocked / missing.
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		activate( root, item, itemEl, true );
	}

	/**
	 * @param {KeyboardEvent} event
	 */
	function onKeydown( event ) {
		if ( event.key !== 'Enter' && event.key !== ' ' ) {
			return;
		}
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var itemEl = target.closest( ITEM_SEL );
		if ( ! itemEl ) {
			return;
		}
		var root = itemEl.closest( ROOT_SEL );
		if ( ! root ) {
			return;
		}
		var item = parseItem( itemEl );
		if ( ! item || ! isAllowedEmbedUrl( item.embed_url, getShowcase( root ) ) ) {
			return;
		}
		event.preventDefault();
		activate( root, item, itemEl, true );
	}

	/*
	 * Bubble phase on purpose: the carousel swallows the click that ends a drag
	 * with a capture listener on its root; a capture listener here would run
	 * first and start a video after every swipe.
	 */
	document.addEventListener( 'click', onClick, false );
	document.addEventListener( 'keydown', onKeydown, true );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	/*
	 * Feeds can be (re)rendered after load (dashboard live preview, AJAX
	 * refresh). Re-badge the server-rendered active item when that happens.
	 * init() is idempotent, so its own DOM changes do not retrigger the loop.
	 */
	function observeRerenders() {
		if ( ! ( 'MutationObserver' in window ) || ! document.body ) {
			return;
		}
		var pending = false;
		new MutationObserver( function () {
			if ( pending ) {
				return;
			}
			pending = true;
			// setTimeout (not rAF) so background tabs still re-badge.
			window.setTimeout( function () {
				pending = false;
				init();
			}, 60 );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', observeRerenders );
	} else {
		observeRerenders();
	}
} )();
