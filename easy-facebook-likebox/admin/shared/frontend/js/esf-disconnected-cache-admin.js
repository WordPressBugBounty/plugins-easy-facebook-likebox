/**
 * Frontend admin notice: pin disconnected feed cache via REST (no page reload).
 *
 * @package Easy_Social_Feed
 * @since 6.7.9
 */

( function () {
	'use strict';

	const config = window.esfDisconnectedCacheAdmin;
	if ( ! config || ! config.restUrl || ! config.nonce ) {
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

	const pinPathForModule = ( module, feedId ) => {
		const slug = String( module || '' ).trim();
		const id = parseInt( String( feedId ), 10 );
		if ( ! slug || id <= 0 ) {
			return '';
		}
		return `${ config.restUrl }/${ slug }/feeds/${ id }/pin-cache`;
	};

	const setNoticeSuccess = ( notice ) => {
		if ( ! notice ) {
			return;
		}
		notice.classList.add( 'esf-gdpr-admin-notice--success' );
		const eyebrow = notice.querySelector( '.esf-gdpr-admin-notice__eyebrow' );
		const body = notice.querySelector( '.esf-gdpr-admin-notice__text' );
		const pinBtn = notice.querySelector( '[data-esf-pin-cache="1"]' );
		if ( eyebrow ) {
			eyebrow.textContent = text( 'successEyebrow', 'Success' );
		}
		if ( body ) {
			body.textContent = text(
				'pinnedMessage',
				'Cached posts will be kept permanently until you clear cache or reconnect.'
			);
		}
		if ( pinBtn ) {
			pinBtn.remove();
		}
	};

	const setNoticeError = ( notice, message ) => {
		if ( ! notice ) {
			return;
		}
		let errorEl = notice.querySelector( '.esf-gdpr-admin-notice__error' );
		if ( ! errorEl ) {
			errorEl = document.createElement( 'p' );
			errorEl.className = 'esf-gdpr-admin-notice__error';
			errorEl.setAttribute( 'role', 'alert' );
			notice.appendChild( errorEl );
		}
		errorEl.textContent =
			message ||
			text( 'pinErrorText', 'Could not keep cache permanently.' );
	};

	document.querySelectorAll( '[data-esf-pin-cache="1"]' ).forEach( ( button ) => {
		if ( ! ( button instanceof HTMLButtonElement ) ) {
			return;
		}

		button.addEventListener( 'click', async ( event ) => {
			event.preventDefault();

			const notice = button.closest( '.esf-gdpr-admin-notice' );
			const module = button.getAttribute( 'data-esf-module' ) || '';
			const feedId = button.getAttribute( 'data-esf-feed-id' ) || '0';
			const url = pinPathForModule( module, feedId );

			if ( ! url ) {
				setNoticeError( notice, text( 'pinErrorText', 'Could not keep cache permanently.' ) );
				return;
			}

			const previousLabel = button.textContent;
			button.disabled = true;
			button.textContent = text( 'loadingText', 'Saving…' );

			const errorEl = notice
				? notice.querySelector( '.esf-gdpr-admin-notice__error' )
				: null;
			if ( errorEl ) {
				errorEl.remove();
			}

			try {
				const response = await fetch( url, {
					method: 'POST',
					headers: restHeaders(),
				} );
				const data = await response.json();
				if ( ! response.ok ) {
					const message =
						data && data.message
							? data.message
							: text( 'pinErrorText', 'Could not keep cache permanently.' );
					throw new Error( message );
				}
				setNoticeSuccess( notice );
			} catch ( err ) {
				button.disabled = false;
				button.textContent = previousLabel;
				setNoticeError(
					notice,
					err && err.message
						? err.message
						: text( 'pinErrorText', 'Could not keep cache permanently.' )
				);
			}
		} );
	} );
} )();
