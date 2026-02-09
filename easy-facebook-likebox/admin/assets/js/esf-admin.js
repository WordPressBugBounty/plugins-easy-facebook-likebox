/**
 * Show notification at the screen bottom
 * @since 6.2.8
 * @param text
 * @param delay
 */
function esfShowNotification(text, delay = 4000){

	if ( ! text) {

		text = fta.copied;
	}

	jQuery( ".esf-notification-holder" ).html( ' ' ).html( text ).css( 'opacity', 1 ).animate( {bottom: '0'} );

	setTimeout( function(){ jQuery( ".esf-notification-holder" ).animate( {bottom: '-=100%'} ) }, delay );
}
/**
 * Remove any notification at the screen bottom
 * @since 6.2.8
 */
function esfRemoveNotification(){

	jQuery( ".esf-notification-holder" ).animate( {bottom: '-=100%'} );
}

jQuery( document ).on(
	'click',
	'.collapsible-header',
	function() {
		jQuery( this ).toggleClass( 'active' );
		jQuery( this ).next( '.collapsible-body' ).slideToggle( 'fast' );
	}
);

/*
* Show/Hide modal
*/
function esfModal(id){

	const opened_modal = document.getElementsByClassName( "esf-modal open" );

	if ( opened_modal.length ) {
		opened_modal.style.display = "none";
	}

	const modal         = document.getElementById( id );
	modal.style.display = "block";
	modal.classList.add( "open" );

}

window.onclick = function(event) {
	let opened_modal_id = document.getElementsByClassName( "esf-modal open" );

	if ( opened_modal_id.length ) {
		opened_modal_id = document.getElementsByClassName( "esf-modal open" )[0].id;
	}
	const modal = document.getElementById( opened_modal_id );

	if (event.target === modal) {
		modal.style.display = "none";
		modal.classList.remove( "open" );
		var additionalData = {
			modal: modal,
			modalID: opened_modal_id
		};
		// Dispatch a custom event when the modal is closed
		var closeModalEvent = new CustomEvent('eiModalClosed', {
			detail: additionalData
		});
		document.dispatchEvent(closeModalEvent);
	}
}

/**
 * Strip the script tags from the code
 * @since 6.5.8
 * @param {*} code 
 * @returns 
 */
function esf_strip_js_code( code ) {
	return code.replace( /<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '' );
}

jQuery( document ).ready(
	function($) {

		setInterval(
			function () {
				moveRight();
			},
			6000
		);

		var slideCount    = $( '#esf-carousel-wrap ul li' ).length;
		var slideWidth    = $( '#esf-carousel-wrap ul li' ).width();
		var slideHeight   = $( '#esf-carousel-wrap ul li' ).height();
		var sliderUlWidth = slideCount * slideWidth;

		$( '#esf-carousel-wrap' ).css( { width: slideWidth, height: slideHeight } );

		$( '#esf-carousel-wrap ul' ).css( { width: sliderUlWidth, marginLeft: - slideWidth } );

		$( '#esf-carousel-wrap ul li:last-child' ).prependTo( '#esf-carousel-wrap ul' );

		function moveRight() {
			$( '#esf-carousel-wrap ul' ).animate(
				{
					left: - slideWidth
				},
				600,
				function () {
					$( '#esf-carousel-wrap ul li:first-child' ).appendTo( '#esf-carousel-wrap ul' );
					$( '#esf-carousel-wrap ul' ).css( 'left', '' );
				}
			);
		}

		// Close modal
		jQuery( document ).on(
			'click',
			'.modal-close',
			function() {
				const modal = jQuery( this ).closest( '.esf-modal' );
				const modalID = modal.attr( 'id' );

				jQuery( this ).closest( '.esf-modal' ).removeClass( 'open' ).css( 'display', 'none' );

				var additionalData = {
					modal: modal,
					modalID: modalID
				};
				// Dispatch a custom event when the modal is closed
				var closeModalEvent = new CustomEvent('eiModalClosed', {
					detail: additionalData
				});
				document.dispatchEvent(closeModalEvent);
			}
		);

		jQuery( '.fta_wrap_outer' ).on(
			'click',
			'.esf-modal-trigger',
			function(e) {
				e.preventDefault();
				let id = jQuery( this ).attr( 'href' ).replace( '#', '' );

				if ( ! id) {
					id = jQuery( this ).attr( 'id' );
				}

				esfModal( id );
			}
		);

		jQuery( '.espf_HideblackFridayMsg' ).click(
			function() {
				var data = {'action': 'espf_black_friday_dismiss'};
				jQuery.ajax(
					{

						url: fta.ajax_url,
						type: 'post',
						data: data,
						dataType: 'json',
						async: ! 0,
						success: function(e) {

							if (e === 'success') {
								jQuery( '.espf_black_friday_msg' ).slideUp( 'fast' );

							}
						},
					}
				);
			}
		);

		/*
		* Activate/deactivate the module.
		*/
		jQuery( '.fta_tab_c_holder .fta_all_plugs .card .fta_plug_activate' ).
		click(
			function(event) {

				event.preventDefault();

				var plugin = jQuery( this ).data( 'plug' );

				var status = jQuery( this ).data( 'status' );

				var toast_msg;

				if (status === 'activated') {
						toast_msg = 'Deactivating';
						status    = 'deactivated';
				} else {
						toast_msg = 'Activating';
						status    = 'activated';
				}
				esfShowNotification( toast_msg, 40000 );

				var data = {
					action: 'esf_change_module_status',
					plugin: plugin,
					status: status,
					nonce: fta.nonce,
				};

				jQuery.ajax(
					{
						url: fta.ajax_url,
						type: 'post',
						data: data,
						dataType: 'json',
						success: function(response) {
							if (response.success) {
								window.location.href = window.location.href;
							} else {
								esfShowNotification( response.data, 4000 );
							}
						},
					}
				);
			}
		);/* mif_auth_sub func ends here. */

		jQuery( '.esf_HideRating' ).click(
			function() {

				var data = {'action': 'esf_hide_rating_notice'};

				jQuery.ajax(
					{

						url: fta.ajax_url,
						type: 'post',
						data: data,
						dataType: 'json',
						async: ! 0,
						success: function(e) {

							if (e === 'success') {
								jQuery( '.fta_msg' ).slideUp( 'fast' );

							}
						},
					}
				);
			}
		);

		jQuery( '.esf_hide_updated_notice' ).click(
			function() {

				var data = {'action': 'esf_hide_updated_notice'};

				jQuery.ajax(
					{

						url: fta.ajax_url,
						type: 'post',
						data: data,
						dataType: 'json',
						async: ! 0,
						success: function(e) {

							if (e === 'success') {
								jQuery( '.fta_upgraded_msg' ).slideUp( 'fast' );

							}
						},
					}
				);
			}
		);

		/*
		* Delete account or page the plugin.
		*/
		jQuery( document ).on(
			'click',
			'.efbl_delete_at_confirmed',
			function() {

				esfShowNotification( fta.deleting, 40000 );

				var data = {
					action: 'esf_remove_access_token',
					nonce: fta.nonce,
				};

				jQuery.ajax(
					{
						url: fta.ajax_url,
						type: 'post',
						data: data,
						dataType: 'json',
						success: function(response) {
							if (response.success) {
								esfShowNotification( response.data, 4000 );
								jQuery( '.efbl_all_pages' ).slideUp( 'slow' ).remove();
								jQuery( '.fta_noti_holder' ).fadeIn( 'slow' );
							} else {
								esfShowNotification( response.data, 4000 );
							}
						},
					}
				);
			}
		);

		/*
		* Copying Page ID.
		*/

		/*
		* Hiding the create new button to make look and feel awesome.
		*/
		var page_id = new ClipboardJS( '.efbl_copy_id' );

		page_id.on(
			'success',
			function() {
				esfShowNotification( fta.copied, 4000 );
			}
		);
		page_id.on(
			'error',
			function() {
				esfShowNotification( fta.error, 4000 );
			}
		);

		function getUrlVars() {
			var vars   = [], hash;
			var hashes = window.location.href.slice(
				window.location.href.indexOf( '?' ) + 1
			).split( '&' );
			for (var i = 0; i < hashes.length; i++) {
				hash = hashes[i].split( '=' );
				vars.push( hash[0] );
				vars[hash[0]] = hash[1];
			}
			return vars;
		}

		var url_vars = getUrlVars();

		/*
		* Activate sub tab according to the URL
		*/
		if (url_vars['sub_tab']) {

			var sub_tab = url_vars['sub_tab'];

			var items = sub_tab.split( '#' );

			sub_tab = items['0'];

			jQuery( '.efbl-tabs-vertical .efbl_si_tabs_name_holder li a' ).
			removeClass( 'active' );

			jQuery(
				'.efbl-tabs-vertical .efbl_si_tabs_name_holder li a[href^="#' +
				sub_tab + '"]'
			).addClass( 'active' );

			jQuery( '.efbl-tabs-vertical .tab-content' ).removeClass( 'active' ).hide();

			jQuery( '.efbl-tabs-vertical #' + sub_tab ).addClass( 'active' ).fadeIn( 'slow' );

		}

		/*
		 * General settings (Settings page) - preserve settings on uninstall
		 */
		jQuery( document ).on( 'click', '.esf-save-general-settings', function() {
			var $btn = jQuery( this );
			var origText = $btn.html();
			$btn.prop( 'disabled', true ).html( typeof fta !== 'undefined' && fta.saving ? fta.saving : 'Saving…' );
			jQuery.ajax({
				url: typeof fta !== 'undefined' ? fta.ajax_url : '',
				type: 'POST',
				data: {
					action: 'esf_save_general_settings',
					preserve_settings_on_uninstall: jQuery( '#esf_preserve_settings_on_uninstall' ).is( ':checked' ) ? '1' : '0',
					nonce: typeof fta !== 'undefined' ? fta.nonce : '',
				},
				dataType: 'json',
				success: function( response ) {
					esfShowNotification( response.data || ( response.success ? 'Saved.' : ( typeof fta !== 'undefined' ? fta.error : 'Error' ) ), 3000 );
					if ( response.success ) {
						jQuery( '#toast-container' ).addClass( 'efbl_green' );
					} else {
						jQuery( '#toast-container' ).addClass( 'esf-failed-notification' );
					}
					$btn.prop( 'disabled', false ).html( origText );
				},
				error: function() {
					esfShowNotification( typeof fta !== 'undefined' ? fta.error : 'Something went wrong.', 3000 );
					jQuery( '#toast-container' ).addClass( 'esf-failed-notification' );
					$btn.prop( 'disabled', false ).html( origText );
				},
			});
		});

		/*
		 * Global GDPR settings (Settings page)
		 */
		if ( jQuery( '#esf_gdpr' ).length ) {
			function esfUpdateGdprDescription() {
				var mode = jQuery( '#esf_gdpr' ).val();
				jQuery( '.esf-gdpr-mode-description' ).hide();
				jQuery( '.esf-gdpr-mode-description[data-mode="' + mode + '"]' ).show();
				if ( mode === 'auto' ) {
					jQuery( '.esf-gdpr-plugin-detected' ).show();
				} else {
					jQuery( '.esf-gdpr-plugin-detected' ).hide();
				}
			}
			esfUpdateGdprDescription();
			jQuery( '#esf_gdpr' ).on( 'change', esfUpdateGdprDescription );

			jQuery( document ).on( 'click', '.esf-save-gdpr-settings', function() {
				var $btn = jQuery( this );
				var origText = $btn.html();
				$btn.prop( 'disabled', true ).html( typeof fta !== 'undefined' && fta.saving ? fta.saving : 'Saving…' );
				jQuery.ajax({
					url: fta.ajax_url,
					type: 'POST',
					data: {
						action: 'esf_save_gdpr_settings',
						gdpr: jQuery( '#esf_gdpr' ).val(),
						nonce: fta.nonce,
					},
					dataType: 'json',
					success: function( response ) {
						esfShowNotification( response.data || ( response.success ? 'Saved.' : fta.error ), 3000 );
						if ( response.success ) {
							jQuery( '#toast-container' ).addClass( 'efbl_green' );
						} else {
							jQuery( '#toast-container' ).addClass( 'esf-failed-notification' );
						}
						$btn.prop( 'disabled', false ).html( origText );
					},
					error: function() {
						esfShowNotification( fta.error, 3000 );
						jQuery( '#toast-container' ).addClass( 'esf-failed-notification' );
						$btn.prop( 'disabled', false ).html( origText );
					},
				});
			});

			var $gdprTooltipTpl = jQuery( '#esf-gdpr-tooltip-template' );
			var $gdprTooltip;
			jQuery( document ).on( 'mouseenter', '.esf-gdpr-help-icon', function() {
				if ( ! $gdprTooltipTpl.length ) { return; }
				if ( $gdprTooltip ) { $gdprTooltip.remove(); $gdprTooltip = null; }
				$gdprTooltip = jQuery( '<div class="esf-gdpr-tooltip"></div>' ).html( $gdprTooltipTpl.html() );
				jQuery( 'body' ).append( $gdprTooltip );
				var offset = jQuery( this ).offset();
				$gdprTooltip.css( { top: offset.top + 20, left: offset.left } );
			});
			jQuery( document ).on( 'mouseleave', '.esf-gdpr-help-icon', function() {
				if ( $gdprTooltip ) {
					setTimeout( function() {
						if ( $gdprTooltip ) { $gdprTooltip.remove(); $gdprTooltip = null; }
					}, 150 );
				}
			});
			jQuery( document ).on( 'click', function( e ) {
				if ( $gdprTooltip && ! jQuery( e.target ).closest( '.esf-gdpr-help-icon, .esf-gdpr-tooltip' ).length ) {
					$gdprTooltip.remove();
					$gdprTooltip = null;
				}
			});

			jQuery( document ).on( 'click', '.esf-supported-plugins-link', function( e ) {
				e.preventDefault();
				if ( jQuery( '#esf_gdpr' ).val() !== 'auto' ) { return; }
				jQuery( this ).closest( '.esf-gdpr-plugin-detected' ).next( '.esf-supported-plugins-list' ).slideToggle( 'fast' );
			});
		}

		/*
		 * Custom Text / Translation settings (Settings page)
		 */
		jQuery( document ).on( 'click', '.esf-save-translation-settings', function() {
			var $btn = jQuery( this );
			var origText = $btn.html();
			$btn.prop( 'disabled', true ).html( typeof fta !== 'undefined' && fta.saving ? fta.saving : 'Saving…' );
			var trans = {};
			jQuery( '#esf-settings-translation input[name^="esf_translation["]' ).each( function() {
				var name = jQuery( this ).attr( 'name' );
				var match = name && name.match( /esf_translation\[(.+)\]/ );
				if ( match ) {
					trans[ match[1] ] = jQuery( this ).val();
				}
			} );
			jQuery.ajax({
				url: typeof fta !== 'undefined' ? fta.ajax_url : '',
				type: 'POST',
				data: {
					action: 'esf_save_translation_settings',
					nonce: typeof fta !== 'undefined' ? fta.nonce : '',
					esf_translation: trans,
				},
				dataType: 'json',
				success: function( response ) {
					esfShowNotification( response.data || ( response.success ? 'Saved.' : ( typeof fta !== 'undefined' ? fta.error : 'Error' ) ), 3000 );
					if ( response.success ) {
						jQuery( '#toast-container' ).addClass( 'efbl_green' );
					} else {
						jQuery( '#toast-container' ).addClass( 'esf-failed-notification' );
					}
					$btn.prop( 'disabled', false ).html( origText );
				},
				error: function() {
					esfShowNotification( typeof fta !== 'undefined' ? fta.error : 'Something went wrong!', 3000 );
					jQuery( '#toast-container' ).addClass( 'esf-failed-notification' );
					$btn.prop( 'disabled', false ).html( origText );
				},
			});
		});

	}
);
