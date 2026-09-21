/**
 * Shared lightweight Free popup (legacy Facebook design).
 *
 * Single media on top, white caption footer, "Read full story", and an inline-
 * styled "Powered by Easy Social Feed" badge (no gallery next/prev).
 *
 * Input via data-esf-simple-popup JSON: { media, caption, permalink }.
 * media may be a string URL or { src, type: 'image'|'video' }.
 *
 * Config (optional): window.esfSimplePopupData
 * { brandLabel, readMoreLabel, closeLabel, brandUrl, brandIcon }
 *
 * @package Easy_Social_Feed
 * @since 6.7.7
 */

( function () {
	'use strict';

	var FOCUSABLE =
		'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';
	var openDialog = null;
	var lastFocus = null;
	var brandGuardTimer = null;
	var closeTimer = null;

	function cfg() {
		return window.esfSimplePopupData && typeof window.esfSimplePopupData === 'object'
			? window.esfSimplePopupData
			: {};
	}

	function parsePayload( el ) {
		var raw = el.getAttribute( 'data-esf-simple-popup' );
		if ( ! raw ) {
			return null;
		}
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	function normalizeMedia( media ) {
		if ( ! media ) {
			return null;
		}
		if ( typeof media === 'string' ) {
			return { src: media, type: 'image' };
		}
		if ( media.src || media.video_url || media.video_embed_url ) {
			return {
				src: media.src || '',
				type: media.type === 'video' ? 'video' : 'image',
				poster: media.poster || '',
				video_url: media.video_url || '',
				video_embed_url: media.video_embed_url || '',
			};
		}
		if ( Array.isArray( media ) && media[ 0 ] ) {
			return normalizeMedia( media[ 0 ] );
		}
		return null;
	}

	function looksLikeVideoFile( url ) {
		if ( ! url || typeof url !== 'string' ) {
			return false;
		}
		return /\.(mp4|webm|ogg|mov)(\?|#|$)/i.test( url );
	}

	function buildFacebookVideoEmbedUrl( permalink ) {
		if ( ! permalink ) {
			return '';
		}
		return (
			'https://www.facebook.com/plugins/video.php?href=' +
			encodeURIComponent( permalink ) +
			'&show_text=0&autoplay=1'
		);
	}

	function appendVideoMedia( mediaWrap, media, permalink ) {
		var mp4 =
			media.video_url ||
			( looksLikeVideoFile( media.src ) ? media.src : '' );
		var embed =
			media.video_embed_url ||
			( String( media.src || '' ).indexOf( 'plugins/video.php' ) !== -1
				? media.src
				: '' );

		if ( ! embed && ! mp4 && permalink ) {
			embed = buildFacebookVideoEmbedUrl( permalink );
		}

		if ( mp4 ) {
			var video = document.createElement( 'video' );
			video.src = mp4;
			video.controls = true;
			video.playsInline = true;
			video.setAttribute( 'playsinline', 'playsinline' );
			if ( media.poster || ( media.src && ! looksLikeVideoFile( media.src ) ) ) {
				video.poster = media.poster || media.src;
			}
			mediaWrap.appendChild( video );
			var playPromise = video.play();
			if ( playPromise && typeof playPromise.catch === 'function' ) {
				playPromise.catch( function () {
					video.muted = true;
					var mutedPlay = video.play();
					if ( mutedPlay && typeof mutedPlay.catch === 'function' ) {
						mutedPlay.catch( function () {} );
					}
				} );
			}
			return;
		}

		if ( embed ) {
			var frame = document.createElement( 'iframe' );
			frame.className = 'esf-simple-popup__video-embed';
			frame.src = embed.indexOf( 'autoplay=' ) === -1
				? embed + ( embed.indexOf( '?' ) === -1 ? '?' : '&' ) + 'autoplay=1'
				: embed;
			frame.setAttribute( 'frameborder', '0' );
			frame.setAttribute( 'scrolling', 'no' );
			frame.setAttribute( 'allowfullscreen', 'allowfullscreen' );
			frame.setAttribute(
				'allow',
				'autoplay; encrypted-media; picture-in-picture; web-share'
			);
			frame.title = 'Facebook video';
			mediaWrap.appendChild( frame );
			return;
		}

		if ( media.src ) {
			var img = document.createElement( 'img' );
			img.src = media.src;
			img.alt = '';
			mediaWrap.appendChild( img );
		}
	}

	/**
	 * Critical CSS injected into the dialog so theme/custom CSS cannot easily
	 * restyle or hide the free branding shell.
	 *
	 * @return {string}
	 */
	function criticalCss() {
		return [
			'.esf-simple-popup{position:fixed!important;inset:0!important;z-index:100000!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:28px 32px!important;box-sizing:border-box!important;margin:0!important}',
			'.esf-simple-popup__backdrop{position:absolute!important;inset:0!important;border:0!important;padding:0!important;margin:0!important;background:rgba(10,10,10,.82)!important;cursor:pointer!important;opacity:0!important;transition:opacity .22s ease!important}',
			'.esf-simple-popup__shell{position:relative!important;z-index:1!important;width:min(780px,100%)!important;max-width:100%!important;display:flex!important;flex-direction:column!important;align-items:stretch!important;margin:0!important;overflow:visible!important;opacity:0!important;transform:translateY(10px) scale(.97)!important;transition:opacity .26s ease,transform .26s cubic-bezier(.22,1,.36,1)!important}',
			'.esf-simple-popup.is-open .esf-simple-popup__backdrop,.esf-simple-popup.is-open .esf-simple-popup__shell{opacity:1!important}',
			'.esf-simple-popup.is-open .esf-simple-popup__shell{transform:translateY(0) scale(1)!important}',
			'.esf-simple-popup.is-closing .esf-simple-popup__backdrop{opacity:0!important}',
			'.esf-simple-popup.is-closing .esf-simple-popup__shell{opacity:0!important;transform:translateY(6px) scale(.98)!important}',
			'.esf-simple-popup__panel{position:relative!important;z-index:1!important;width:100%!important;max-height:calc(100vh - 72px)!important;overflow:auto!important;background:#000!important;color:#111!important;border-radius:5px!important;outline:none!important;box-shadow:0 16px 48px rgba(0,0,0,.45)!important;display:block!important;visibility:visible!important}',
			'.esf-simple-popup__panel--has-caption{background:#fff!important}',
			'.esf-simple-popup__close{position:absolute!important;top:-14px!important;right:-14px!important;z-index:5!important;width:36px!important;height:36px!important;border:2px solid rgba(255,255,255,.95)!important;border-radius:999px!important;background:rgba(0,0,0,.55)!important;color:#fff!important;font-size:22px!important;line-height:1!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;box-shadow:0 2px 8px rgba(0,0,0,.35)!important}',
			'.esf-simple-popup__close:hover,.esf-simple-popup__close:focus{background:rgba(0,0,0,.75)!important;outline:none!important}',
			'.esf-simple-popup__media{display:flex!important;align-items:center!important;justify-content:center!important;background:#000!important;min-width:0!important;width:100%!important}',
			'.esf-simple-popup__media img,.esf-simple-popup__media video{display:block!important;max-width:100%!important;width:100%!important;height:auto!important;max-height:min(82vh,720px)!important;object-fit:contain!important;margin:0!important;border:0!important;background:#000!important}',
			'.esf-simple-popup__video-embed{display:block!important;width:100%!important;min-height:min(70vh,560px)!important;height:min(70vh,560px)!important;max-height:min(82vh,720px)!important;border:0!important;background:#000!important}',
			'.esf-simple-popup__footer{padding:10px 14px 14px!important;background:#fff!important;color:#000!important;display:block!important;visibility:visible!important}',
			'.esf-simple-popup__caption{font-size:14px!important;line-height:1.3em!important;margin:0 0 8px!important;white-space:pre-wrap!important;color:#000!important;display:block!important}',
			'.esf-simple-popup__permalink{color:#1877f2!important;font-size:13px!important;text-decoration:none!important;display:inline-block!important}',
			'.esf-simple-popup__permalink:hover,.esf-simple-popup__permalink:focus{text-decoration:underline!important}',
			'.esf-simple-popup__brand{margin:10px auto 0!important;border-radius:10px!important;background-color:rgba(0,0,0,.35)!important;width:225px!important;max-width:calc(100% - 24px)!important;padding:5px 8px!important;cursor:pointer!important;opacity:.6!important;transition:all ease-in-out .5s!important;text-align:center!important;position:relative!important;display:block!important;visibility:visible!important;z-index:99!important;box-sizing:border-box!important}',
			'.esf-simple-popup__brand:hover,.esf-simple-popup__brand:focus{opacity:1!important;outline:none!important}',
			'.esf-simple-popup__brand-inner{display:block!important;visibility:visible!important;z-index:99!important;opacity:1!important;overflow:hidden!important}',
			'.esf-simple-popup__brand-icon{float:left!important;width:25px!important;height:auto!important;margin:0!important;display:block!important;visibility:visible!important;z-index:99!important;opacity:1!important;box-shadow:none!important;border-radius:3px!important;border:0!important}',
			'.esf-simple-popup__brand-label{font-size:12px!important;color:#fff!important;float:left!important;margin-top:4px!important;margin-left:5px!important;display:block!important;visibility:visible!important;z-index:99!important;opacity:1!important;line-height:1.2!important;font-weight:400!important}',
			'@media (max-width:480px){.esf-simple-popup{padding:24px 20px!important}.esf-simple-popup__shell{width:min(100%,calc(100vw - 40px))!important}.esf-simple-popup__panel{max-height:calc(100vh - 80px)!important}.esf-simple-popup__close{top:-12px!important;right:-8px!important}.esf-simple-popup__media img,.esf-simple-popup__media video{max-height:min(70vh,560px)!important}.esf-simple-popup__video-embed{min-height:min(50vh,420px)!important;height:min(50vh,420px)!important;max-height:min(70vh,560px)!important}.esf-simple-popup__brand{width:min(225px,100%)!important}}',
			'@media (prefers-reduced-motion:reduce){.esf-simple-popup__backdrop,.esf-simple-popup__shell{transition:none!important;opacity:1!important;transform:none!important}}',
		].join( '' );
	}

	function buildBrand( data ) {
		var brand = document.createElement( 'a' );
		brand.className = 'esf-simple-popup__brand';
		brand.href = data.brandUrl || 'https://easysocialfeed.com/?utm_campaign=powered-by&utm_medium=link&utm_source=plugin';
		brand.target = '_blank';
		brand.rel = 'noopener noreferrer';
		brand.setAttribute( 'aria-label', data.brandLabel || 'Powered by Easy Social Feed' );
		// Legacy-parity inline styles (harder to override than stylesheet alone).
		brand.setAttribute(
			'style',
			'margin:10px auto 0!important;border-radius:10px!important;background-color:rgba(0,0,0,.35)!important;width:225px!important;max-width:calc(100% - 24px)!important;padding:5px 8px!important;cursor:pointer!important;opacity:.6;transition:all ease-in-out .5s;text-align:center;position:relative!important;display:block!important;visibility:visible!important;z-index:99!important;box-sizing:border-box!important;text-decoration:none!important'
		);

		var inner = document.createElement( 'span' );
		inner.className = 'esf-simple-popup__brand-inner';
		inner.setAttribute(
			'style',
			'display:block!important;visibility:visible!important;z-index:99!important;opacity:1!important;overflow:hidden!important'
		);

		if ( data.brandIcon ) {
			var img = document.createElement( 'img' );
			img.className = 'esf-simple-popup__brand-icon';
			img.src = data.brandIcon;
			img.alt = '';
			img.width = 25;
			img.height = 25;
			img.setAttribute(
				'style',
				'float:left!important;width:25px!important;height:auto!important;margin:0!important;display:block!important;visibility:visible!important;z-index:99!important;opacity:1!important;box-shadow:none!important;border-radius:3px!important;border:0!important'
			);
			inner.appendChild( img );
		}

		var label = document.createElement( 'span' );
		label.className = 'esf-simple-popup__brand-label';
		label.textContent = data.brandLabel || 'Powered by Easy Social Feed';
		label.setAttribute(
			'style',
			'font-size:12px!important;color:#fff!important;float:left!important;margin-top:4px!important;margin-left:5px!important;display:block!important;visibility:visible!important;z-index:99!important;opacity:1!important;line-height:1.2!important'
		);
		inner.appendChild( label );
		brand.appendChild( inner );
		return brand;
	}

	function buildDialog( payload ) {
		var data = cfg();
		var media = normalizeMedia( payload.media );
		var captionRaw = payload.caption || payload.text_html || '';
		var captionText = String( captionRaw ).replace( /<[^>]+>/g, '' ).trim();
		var hasCaption = captionText !== '';
		var permalink = payload.permalink || '';

		var overlay = document.createElement( 'div' );
		overlay.className = 'esf-simple-popup';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-label', 'Media preview' );

		var styleTag = document.createElement( 'style' );
		styleTag.setAttribute( 'data-esf-simple-popup-critical', '1' );
		styleTag.appendChild( document.createTextNode( criticalCss() ) );
		overlay.appendChild( styleTag );

		var backdrop = document.createElement( 'button' );
		backdrop.type = 'button';
		backdrop.className = 'esf-simple-popup__backdrop';
		backdrop.setAttribute( 'aria-label', data.closeLabel || 'Close' );

		var shell = document.createElement( 'div' );
		shell.className = 'esf-simple-popup__shell';

		var panel = document.createElement( 'div' );
		panel.className = hasCaption
			? 'esf-simple-popup__panel esf-simple-popup__panel--has-caption'
			: 'esf-simple-popup__panel';
		panel.setAttribute( 'tabindex', '-1' );

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'esf-simple-popup__close';
		closeBtn.setAttribute( 'aria-label', data.closeLabel || 'Close' );
		closeBtn.innerHTML = '&times;';

		var mediaWrap = document.createElement( 'div' );
		mediaWrap.className = 'esf-simple-popup__media';

		if ( media && media.type === 'video' ) {
			appendVideoMedia( mediaWrap, media, permalink );
		} else if ( media ) {
			var img = document.createElement( 'img' );
			img.src = media.src;
			img.alt = '';
			mediaWrap.appendChild( img );
		}

		// Close sits on the shell so it can sit slightly outside the panel.
		shell.appendChild( closeBtn );

		if ( mediaWrap.childNodes.length ) {
			panel.appendChild( mediaWrap );
		}

		// Caption + "Read full story" only when there is real caption text.
		if ( hasCaption ) {
			var footer = document.createElement( 'div' );
			footer.className = 'esf-simple-popup__footer';
			footer.setAttribute(
				'style',
				'padding:10px 14px 14px!important;background:#fff!important;color:#000!important;display:block!important'
			);

			var cap = document.createElement( 'div' );
			cap.className = 'esf-simple-popup__caption';
			cap.textContent = captionText;
			cap.setAttribute(
				'style',
				'font-size:14px!important;line-height:1.3em!important;margin:0 0 8px!important;white-space:pre-wrap!important;color:#000!important;display:block!important'
			);
			footer.appendChild( cap );

			if ( permalink ) {
				var link = document.createElement( 'a' );
				link.className = 'esf-simple-popup__permalink';
				link.href = permalink;
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				link.textContent = data.readMoreLabel || 'Read full story';
				link.setAttribute(
					'style',
					'color:#1877f2!important;font-size:13px!important;text-decoration:none!important;display:inline-block!important'
				);
				footer.appendChild( link );
			}

			panel.appendChild( footer );
		}

		shell.appendChild( panel );
		shell.appendChild( buildBrand( data ) );

		overlay.appendChild( backdrop );
		overlay.appendChild( shell );

		function onClose() {
			closePopup();
		}
		backdrop.addEventListener( 'click', onClose );
		closeBtn.addEventListener( 'click', onClose );

		return overlay;
	}

	function ensureBrand( dialog ) {
		if ( ! dialog ) {
			return;
		}
		var shell = dialog.querySelector( '.esf-simple-popup__shell' );
		if ( ! shell ) {
			return;
		}
		if ( ! shell.querySelector( '.esf-simple-popup__brand' ) ) {
			shell.appendChild( buildBrand( cfg() ) );
		}
		if ( ! dialog.querySelector( 'style[data-esf-simple-popup-critical]' ) ) {
			var styleTag = document.createElement( 'style' );
			styleTag.setAttribute( 'data-esf-simple-popup-critical', '1' );
			styleTag.appendChild( document.createTextNode( criticalCss() ) );
			dialog.insertBefore( styleTag, dialog.firstChild );
		}
	}

	function trapFocus( evt ) {
		if ( ! openDialog || evt.key !== 'Tab' ) {
			return;
		}
		var nodes = openDialog.querySelectorAll( FOCUSABLE );
		if ( ! nodes.length ) {
			return;
		}
		var first = nodes[ 0 ];
		var last = nodes[ nodes.length - 1 ];
		if ( evt.shiftKey && document.activeElement === first ) {
			evt.preventDefault();
			last.focus();
		} else if ( ! evt.shiftKey && document.activeElement === last ) {
			evt.preventDefault();
			first.focus();
		}
	}

	function onKeydown( evt ) {
		if ( evt.key === 'Escape' ) {
			closePopup();
			return;
		}
		trapFocus( evt );
	}

	function prefersReducedMotion() {
		return (
			window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		);
	}

	function closePopup( immediate ) {
		if ( ! openDialog ) {
			return;
		}
		if ( brandGuardTimer ) {
			window.clearInterval( brandGuardTimer );
			brandGuardTimer = null;
		}
		if ( closeTimer ) {
			window.clearTimeout( closeTimer );
			closeTimer = null;
		}
		document.removeEventListener( 'keydown', onKeydown, true );

		var dialog = openDialog;
		var finish = function () {
			if ( dialog.parentNode ) {
				dialog.parentNode.removeChild( dialog );
			}
			if ( openDialog === dialog ) {
				openDialog = null;
			}
			document.documentElement.classList.remove( 'esf-simple-popup-open' );
			if ( lastFocus && typeof lastFocus.focus === 'function' ) {
				lastFocus.focus();
			}
			lastFocus = null;
		};

		if ( immediate || prefersReducedMotion() ) {
			finish();
			return;
		}

		dialog.classList.remove( 'is-open' );
		dialog.classList.add( 'is-closing' );
		closeTimer = window.setTimeout( finish, 240 );
	}

	function openPopup( payload, trigger ) {
		closePopup( true );
		lastFocus = trigger || document.activeElement;
		openDialog = buildDialog( payload );
		document.body.appendChild( openDialog );
		document.documentElement.classList.add( 'esf-simple-popup-open' );
		document.addEventListener( 'keydown', onKeydown, true );
		brandGuardTimer = window.setInterval( function () {
			ensureBrand( openDialog );
		}, 750 );

		var panel = openDialog.querySelector( '.esf-simple-popup__panel' );
		if ( panel ) {
			panel.focus();
		}

		if ( prefersReducedMotion() ) {
			openDialog.classList.add( 'is-open' );
			return;
		}

		// Next frame so the closed styles paint before the open transition.
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				if ( openDialog ) {
					openDialog.classList.add( 'is-open' );
				}
			} );
		} );
	}

	function onClick( evt ) {
		var clickEl = evt.target;
		if ( clickEl && clickEl.nodeType !== 1 ) {
			clickEl = clickEl.parentElement;
		}
		if ( ! clickEl || typeof clickEl.closest !== 'function' ) {
			return;
		}

		var host = clickEl.closest( '[data-esf-simple-popup]' );
		if ( ! host ) {
			return;
		}
		// Prefer Pro FancyBox when both attributes exist.
		if ( host.hasAttribute( 'data-esf-facebook-popup' ) ) {
			return;
		}

		// Payload may sit on a split/stacked cell while the open trigger is only
		// on the media tile/collage (Instagram Free/Pro lightbox parity).
		var mediaTriggerSelector =
			'[data-esf-facebook-popup-trigger="1"], [data-esf-instagram-popup-trigger="1"], [data-esf-tiktok-popup-trigger="1"]';
		var hasMediaTrigger = !! host.querySelector( mediaTriggerSelector );
		if ( hasMediaTrigger ) {
			var onMedia = clickEl.closest( mediaTriggerSelector );
			if ( ! onMedia ) {
				return;
			}
			if (
				clickEl.closest(
					'[data-esf-caption-toggle], .esf-facebook-feed__split-footer, .esf-facebook-feed__stacked-footer, .esf-facebook-feed__share-menu'
				)
			) {
				return;
			}
		}

		var payload = parsePayload( host );
		if ( ! payload ) {
			return;
		}
		evt.preventDefault();
		openPopup( payload, host );
	}

	document.addEventListener( 'click', onClick );

	window.esfSimplePopup = {
		open: openPopup,
		close: closePopup,
	};
}() );
