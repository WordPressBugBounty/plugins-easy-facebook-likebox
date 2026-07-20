/**
 * Shared missing-feed admin recovery UI for the public frontend.
 *
 * Injects admin tooling into cache-safe placeholders rendered by
 * EasySocialFeed\Layouts\Primitives\MissingFeedState.
 *
 * @package Easy_Social_Feed
 * @since 6.9.0
 */

( function () {
	'use strict';

	const config = window.esfMissingFeedAdmin;
	if ( ! config || ! config.restUrl || ! config.nonce ) {
		return;
	}

	const roots = document.querySelectorAll( '[data-esf-missing-feed="1"]' );
	if ( ! roots.length ) {
		return;
	}

	const text = ( key, fallback ) => {
		if ( config[ key ] ) {
			return config[ key ];
		}
		return fallback;
	};

	const restHeaders = () => ( {
		'X-WP-Nonce': config.nonce,
	} );

	const buildShortcode = ( tag, feedId ) => `[${ tag } id="${ feedId }"]`;

	const resolveOutputShortcodeTag = ( module, shortcodeTag ) => {
		if ( shortcodeTag === 'my-instagram-feed' && module === 'instagram' ) {
			return 'esf_instagram_feed';
		}
		return shortcodeTag;
	};

	const fetchFeeds = async ( feedsPath ) => {
		const url = `${ config.restUrl }${ feedsPath }`;
		const response = await fetch( url, { headers: restHeaders() } );
		if ( ! response.ok ) {
			throw new Error( 'feeds_fetch_failed' );
		}
		const data = await response.json();
		return Array.isArray( data ) ? data : [];
	};

	const fetchPreview = async ( previewPath, feedId ) => {
		const path = previewPath.replace( '{id}', String( feedId ) );
		const url = `${ config.restUrl }${ path }`;
		const response = await fetch( url, { headers: restHeaders() } );
		if ( ! response.ok ) {
			throw new Error( 'preview_fetch_failed' );
		}
		return response.json();
	};

	const replaceShortcode = async ( payload ) => {
		const url = `${ config.restUrl }${ config.replaceShortcodePath || '/missing-feed/replace-shortcode' }`;
		const response = await fetch( url, {
			method: 'POST',
			headers: {
				...restHeaders(),
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( payload ),
		} );
		const data = await response.json();
		if ( ! response.ok ) {
			const message = data && data.message ? data.message : text( 'saveErrorText', 'Could not update the shortcode on this page.' );
			throw new Error( message );
		}
		return data;
	};

	const copyToClipboard = async ( value ) => {
		try {
			await window.navigator.clipboard.writeText( value );
			return true;
		} catch ( err ) {
			return false;
		}
	};

	const createAdminPanel = ( root ) => {
		const module = root.getAttribute( 'data-esf-module' ) || '';
		const feedId = parseInt( root.getAttribute( 'data-esf-feed-id' ) || '0', 10 );
		const shortcodeTag = root.getAttribute( 'data-esf-shortcode-tag' ) || '';
		const postId = parseInt( root.getAttribute( 'data-esf-post-id' ) || '0', 10 );
		const previewPath = root.getAttribute( 'data-esf-preview-path' ) || '';
		const feedsPath = root.getAttribute( 'data-esf-feeds-path' ) || '';
		const dashboardUrl = root.getAttribute( 'data-esf-dashboard-url' ) || '';

		const panel = document.createElement( 'div' );
		panel.className = 'esf-missing-feed__admin';
		panel.innerHTML = `
			<div class="esf-missing-feed__admin-header">
				<strong class="esf-missing-feed__admin-title"></strong>
				<p class="esf-missing-feed__admin-intro"></p>
			</div>
			<div class="esf-missing-feed__admin-controls">
				<label class="esf-missing-feed__label">
					<span class="esf-missing-feed__label-text"></span>
					<select class="esf-missing-feed__select"></select>
				</label>
				<div class="esf-missing-feed__actions">
					<button type="button" class="esf-missing-feed__button esf-missing-feed__button--preview"></button>
					<button type="button" class="esf-missing-feed__button esf-missing-feed__button--save"></button>
					<button type="button" class="esf-missing-feed__button esf-missing-feed__button--copy"></button>
					<a class="esf-missing-feed__button esf-missing-feed__button--dashboard" target="_blank" rel="noopener noreferrer"></a>
				</div>
			</div>
			<p class="esf-missing-feed__notice esf-missing-feed__notice--hidden" role="status" aria-live="polite"></p>
			<div class="esf-missing-feed__preview-wrap esf-missing-feed__preview-wrap--hidden">
				<div class="esf-missing-feed__preview"></div>
			</div>
		`;

		panel.querySelector( '.esf-missing-feed__admin-title' ).textContent =
			shortcodeTag === 'my-instagram-feed'
				? text( 'legacyTitleText', 'Legacy Instagram shortcode' )
				: text( 'titleText', 'Feed not found' );
		panel.querySelector( '.esf-missing-feed__admin-intro' ).textContent =
			shortcodeTag === 'my-instagram-feed'
				? text(
						'legacyIntroText',
						'This legacy Instagram shortcode needs updating after migration. Choose a feed to preview, then save the new shortcode to this page.'
				  )
				: text(
						'adminIntroText',
						'This shortcode points to a feed that was deleted.'
				  );
		panel.querySelector( '.esf-missing-feed__label-text' ).textContent = text( 'selectFeedText', 'Select a feed' );
		panel.querySelector( '.esf-missing-feed__button--preview' ).textContent = text( 'previewText', 'Preview feed' );
		panel.querySelector( '.esf-missing-feed__button--save' ).textContent = text( 'saveText', 'Save to this page' );
		panel.querySelector( '.esf-missing-feed__button--copy' ).textContent = text( 'copyShortcodeText', 'Copy shortcode' );
		const dashboardLink = panel.querySelector( '.esf-missing-feed__button--dashboard' );
		dashboardLink.textContent = text( 'dashboardText', 'Open dashboard' );
		dashboardLink.href = dashboardUrl;

		const select = panel.querySelector( '.esf-missing-feed__select' );
		const notice = panel.querySelector( '.esf-missing-feed__notice' );
		const previewWrap = panel.querySelector( '.esf-missing-feed__preview-wrap' );
		const preview = panel.querySelector( '.esf-missing-feed__preview' );
		const previewBtn = panel.querySelector( '.esf-missing-feed__button--preview' );
		const saveBtn = panel.querySelector( '.esf-missing-feed__button--save' );
		const copyBtn = panel.querySelector( '.esf-missing-feed__button--copy' );

		const showNotice = ( message, isError ) => {
			notice.textContent = message;
			notice.classList.toggle( 'esf-missing-feed__notice--error', !! isError );
			notice.classList.remove( 'esf-missing-feed__notice--hidden' );
		};

		const getSelectedFeedId = () => parseInt( select.value || '0', 10 );

		if ( postId <= 0 ) {
			saveBtn.disabled = true;
			showNotice( text( 'noPostText', 'Copy the new shortcode and paste it where needed.' ), false );
		}

		fetchFeeds( feedsPath )
			.then( ( feeds ) => {
				const placeholder = document.createElement( 'option' );
				placeholder.value = '';
				placeholder.textContent = text( 'selectFeedText', 'Select a feed' );
				select.appendChild( placeholder );

				feeds.forEach( ( feed ) => {
					if ( ! feed || ! feed.id ) {
						return;
					}
					const option = document.createElement( 'option' );
					option.value = String( feed.id );
					option.textContent = feed.name ? `${ feed.name } (#${ feed.id })` : `#${ feed.id }`;
					if ( feedId > 0 && Number( feed.id ) === feedId ) {
						option.selected = true;
					}
					select.appendChild( option );
				} );

				if ( feeds.length === 0 ) {
					showNotice( text( 'noFeedsText', 'No feeds available.' ), true );
					previewBtn.disabled = true;
					saveBtn.disabled = true;
					copyBtn.disabled = true;
				}
			} )
			.catch( () => {
				showNotice( text( 'noFeedsText', 'No feeds available.' ), true );
			} );

		previewBtn.addEventListener( 'click', async () => {
			const selectedId = getSelectedFeedId();
			if ( selectedId <= 0 ) {
				return;
			}
			previewBtn.disabled = true;
			previewBtn.textContent = text( 'loadingText', 'Loading…' );
			previewWrap.classList.remove( 'esf-missing-feed__preview-wrap--hidden' );
			preview.innerHTML = `<p class="esf-missing-feed__loading">${ text( 'loadingText', 'Loading…' ) }</p>`;

			try {
				const payload = await fetchPreview( previewPath, selectedId );
				preview.innerHTML = payload && payload.html ? payload.html : '';
				if ( payload && payload.css_url ) {
					const linkId = `esf-missing-feed-preview-css-${ module }-${ selectedId }`;
					if ( ! document.getElementById( linkId ) ) {
						const link = document.createElement( 'link' );
						link.id = linkId;
						link.rel = 'stylesheet';
						link.href = payload.css_url;
						document.head.appendChild( link );
					}
				}
				if ( payload && payload.layout_css_url ) {
					const linkId = `esf-missing-feed-preview-layout-css-${ module }-${ selectedId }`;
					if ( ! document.getElementById( linkId ) ) {
						const link = document.createElement( 'link' );
						link.id = linkId;
						link.rel = 'stylesheet';
						link.href = payload.layout_css_url;
						document.head.appendChild( link );
					}
				}
			} catch ( err ) {
				showNotice( text( 'previewErrorText', 'Could not load feed preview.' ), true );
				preview.innerHTML = '';
			} finally {
				previewBtn.disabled = false;
				previewBtn.textContent = text( 'previewText', 'Preview feed' );
			}
		} );

		saveBtn.addEventListener( 'click', async () => {
			const selectedId = getSelectedFeedId();
			if ( selectedId <= 0 || postId <= 0 ) {
				return;
			}
			saveBtn.disabled = true;
			saveBtn.textContent = text( 'loadingText', 'Loading…' );

			try {
				await replaceShortcode( {
					module,
					post_id: postId,
					old_feed_id: feedId,
					new_feed_id: selectedId,
					shortcode_tag: shortcodeTag,
				} );
				showNotice( text( 'savedText', 'Shortcode updated. Reloading…' ), false );
				window.setTimeout( () => window.location.reload(), 600 );
			} catch ( err ) {
				showNotice( err.message || text( 'saveErrorText', 'Could not update the shortcode on this page.' ), true );
				saveBtn.disabled = false;
				saveBtn.textContent = text( 'saveText', 'Save to this page' );
			}
		} );

		copyBtn.addEventListener( 'click', async () => {
			const selectedId = getSelectedFeedId();
			if ( selectedId <= 0 || ! shortcodeTag ) {
				return;
			}
			const ok = await copyToClipboard(
				buildShortcode( resolveOutputShortcodeTag( module, shortcodeTag ), selectedId )
			);
			if ( ok ) {
				copyBtn.textContent = text( 'copiedText', 'Copied!' );
				window.setTimeout( () => {
					copyBtn.textContent = text( 'copyShortcodeText', 'Copy shortcode' );
				}, 2000 );
			}
		} );

		root.classList.add( 'esf-missing-feed--admin' );
		root.appendChild( panel );
	};

	roots.forEach( ( root ) => {
		if ( root instanceof HTMLElement ) {
			createAdminPanel( root );
		}
	} );
} )();
