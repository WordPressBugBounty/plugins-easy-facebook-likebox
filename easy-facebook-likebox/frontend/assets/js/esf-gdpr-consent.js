/**
 * Easy Social Feed - GDPR Consent Checking
 * Shared across all modules (Facebook, Instagram, etc.)
 *
 * @package EasySocialFeed
 * @since 6.8.0
 */

(function($) {
	'use strict';

	/**
	 * Check if user has given consent through various GDPR plugins
	 *
	 * @param {jQuery} container Feed container element
	 * @return {boolean} True if consent given, false otherwise
	 */
	function checkConsent(container) {
		// Get flags from data attribute.
		var flags = container.attr('data-esf-flags') || '';
		var hasGdpr = flags.indexOf('gdpr') > -1;

		// If GDPR not enabled, return true (allow all).
		if (!hasGdpr) {
			return true;
		}

		var consentGiven = false;

		// Check WPConsent.
		if (typeof window.WPConsent !== 'undefined') {
			consentGiven = window.WPConsent.hasConsent('marketing');
			if (consentGiven !== null && consentGiven !== undefined) {
				return consentGiven;
			}
		}

		// Check CookieYes.
		if (typeof window.cookieyes !== 'undefined') {
			try {
				if (typeof window.cookieyes._ckyConsentStore !== 'undefined' &&
					typeof window.cookieyes._ckyConsentStore.get !== 'undefined') {
					consentGiven = window.cookieyes._ckyConsentStore.get('functional') === 'yes';
					if (consentGiven !== null && consentGiven !== undefined) {
						return consentGiven;
					}
				}
			} catch (e) {
				// Continue to next method.
			}
		}

		// Check GDPR Cookie Consent (WebToffee).
		if (typeof CLI_Cookie !== 'undefined') {
			try {
				if (CLI_Cookie.read(CLI_ACCEPT_COOKIE_NAME) !== null) {
					// Backwards compatibility.
					if (CLI_Cookie.read('cookielawinfo-checkbox-non-necessary') !== null) {
						consentGiven = CLI_Cookie.read('cookielawinfo-checkbox-non-necessary') === 'yes';
						if (consentGiven !== null && consentGiven !== undefined) {
							return consentGiven;
						}
					}
					if (CLI_Cookie.read('cookielawinfo-checkbox-necessary') !== null) {
						consentGiven = CLI_Cookie.read('cookielawinfo-checkbox-necessary') === 'yes';
						if (consentGiven !== null && consentGiven !== undefined) {
							return consentGiven;
						}
					}
				}
			} catch (e) {
				// Continue to next method.
			}
		}

		// Check Cookie Notice (dFactory).
		if (typeof window.cnArgs !== 'undefined') {
			try {
				var value = '; ' + document.cookie;
				var parts = value.split('; cookie_notice_accepted=');
				if (parts.length === 2) {
					var val = parts.pop().split(';').shift();
					consentGiven = (val === 'true');
					if (consentGiven !== null && consentGiven !== undefined) {
						return consentGiven;
					}
				}
			} catch (e) {
				// Continue to next method.
			}
		}

		// Check Complianz.
		if (typeof window.complianz !== 'undefined' || typeof window.cookieconsent !== 'undefined') {
			try {
				consentGiven = esfGetCookie('cmplz_marketing') === 'allow';
				if (consentGiven !== null && consentGiven !== undefined) {
					return consentGiven;
				}
			} catch (e) {
				// Continue to next method.
			}
		}

		// Check Cookiebot.
		if (typeof window.Cookiebot !== 'undefined') {
			try {
				consentGiven = Cookiebot.consented;
				if (consentGiven !== null && consentGiven !== undefined) {
					return consentGiven;
				}
			} catch (e) {
				// Continue to next method.
			}
		}

		// Check Borlabs Cookie.
		if (typeof window.BorlabsCookie !== 'undefined') {
			try {
				consentGiven = window.BorlabsCookie.checkCookieConsent('facebook') ||
					window.BorlabsCookie.checkCookieConsent('marketing');
				if (consentGiven !== null && consentGiven !== undefined) {
					return consentGiven;
				}
			} catch (e) {
				// Continue to next method.
			}
		}

		// Check Moove GDPR Popup.
		try {
			var mooveCookie = esfGetCookie('moove_gdpr_popup');
			if (mooveCookie) {
				var moove_gdpr_popup = JSON.parse(decodeURIComponent(mooveCookie));
				consentGiven = typeof moove_gdpr_popup.thirdparty !== 'undefined' &&
					moove_gdpr_popup.thirdparty === '1';
				if (consentGiven !== null && consentGiven !== undefined) {
					return consentGiven;
				}
			}
		} catch (e) {
			// Continue to next method.
		}

		// Check Real Cookie Banner (async, handled via events).
		if (typeof window.consentApi !== 'undefined') {
			try {
				// This is async, so we'll handle it separately.
			} catch (e) {
				// Continue.
			}
		}

		// Default: no consent.
		return false;
	}

	/**
	 * Determine GDPR mode for a container based on its flags.
	 *
	 * @param {jQuery} container Feed container element
	 * @return {string} 'yes' | 'auto' | 'none'
	 */
	function getGdprMode(container) {
		var flagsAttr = container.attr('data-esf-flags') || '';
		var flags = flagsAttr ? flagsAttr.split(',') : [];

		if (flags.indexOf('gdpr_yes') > -1) {
			return 'yes';
		}

		if (flags.indexOf('gdpr_auto') > -1) {
			return 'auto';
		}

		return 'none';
	}

	/**
	 * Helper function to read cookies.
	 *
	 * @param {string} cname Cookie name
	 * @return {string} Cookie value or empty string
	 */
	function esfGetCookie(cname) {
		var name = cname + '=';
		var cArr = document.cookie.split(';');

		for (var i = 0; i < cArr.length; i++) {
			var c = cArr[i].trim();
			if (c.indexOf(name) === 0) {
				return c.substring(name.length, c.length);
			}
		}

		return '';
	}

	/**
	 * Enable full features when consent is given.
	 *
	 * @param {jQuery} container Feed container
	 */
	function enableFullFeatures(container) {
		var mode = getGdprMode(container);

		// In "yes" mode we never load real images from Facebook/CDN,
		// and we also keep Load More disabled to avoid additional API calls.
		if (mode === 'yes') {
			return;
		}

		// Remove admin notices.
		container.find('.esf-gdpr-notice').remove();

		// Replace images.
		container.find('.esf-no-consent').each(function() {
			var $element = $(this);
			var realImageUrl = $element.attr('data-image-url');

			if (realImageUrl) {
				// Update <img> tag if present.
				var $img = $element.is('img') ? $element : $element.find('img');
				if ($img.length) {
					$img.attr('src', realImageUrl);
				}

				// Always update background-image on the container as well.
				$element.css('background-image', 'url(' + realImageUrl + ')');

				$element.removeClass('esf-no-consent');
				$element.removeAttr('data-image-url');
			}
		});

		// Show load more button if present.
		container.find('.efbl_load_more_holder').show();
	}

	/**
	 * Initial consent check on page load.
	 */
	function initConsentCheck() {
		var $containers = $('.efbl_feed_wraper, .esf_insta_feed_wraper');

		// Initial check for all feed containers.
		$containers.each(function() {
			var $container = $(this);
			var flags = $container.attr('data-esf-flags') || '';
			var mode  = getGdprMode($container);

			if (flags.indexOf('gdpr') > -1) {
				// If mode is "yes" then GDPR is always enforced and
				// consent does not change behaviour. Hide Load More
				// permanently and skip consent checks.
				if (mode === 'yes') {
					// Always enforced: hide Load More permanently and never load external media.
					$container.find('.efbl_load_more_holder').hide();
					return;
				}

				// For "auto" mode, first check immediately if consent is already present
				// (e.g. user accepted on a previous visit). This avoids a visible flash
				// of placeholder images when the page is reloaded after consent.
				var immediateConsent = checkConsent($container);
				if (immediateConsent) {
					// Consent already given: enable full features right away and keep
					// Load More visible. No need to hide it or wait.
					enableFullFeatures($container);
					return;
				}

				// No consent yet: hide Load More by default until consent is confirmed.
				$container.find('.efbl_load_more_holder').hide();

				// Delay to allow consent plugins to initialize, then re-check.
				setTimeout(function() {
					var consent = checkConsent($container);
					if (consent) {
						enableFullFeatures($container);
					}
				}, 250);
			}
		});

		// Fallback polling: some consent plugins do not fire JS events,
		// so periodically re-check consent for a short period.
		var attempts = 0;
		var maxAttempts = 30; // ~60 seconds if interval is 2000ms.
		var pollInterval = 2000;

		var intervalId = setInterval(function() {
			attempts++;

			$containers.each(function() {
				var $container = $(this);
				var flags = $container.attr('data-esf-flags') || '';
				var mode  = getGdprMode($container);

				if (flags.indexOf('gdpr') === -1) {
					return;
				}

				// Skip "yes" mode: it is always GDPR-limited, consent
				// does not unlock external media.
				if (mode === 'yes') {
					return;
				}

				// Only bother if placeholders are still present.
				if ($container.find('.esf-no-consent').length === 0) {
					return;
				}

				if (checkConsent($container)) {
					enableFullFeatures($container);
				}
			});

			if (attempts >= maxAttempts) {
				clearInterval(intervalId);
			}
		}, pollInterval);
	}


	/**
	 * Callback when consent is toggled.
	 *
	 * @param {boolean} isConsent Whether consent was given
	 */
	function afterConsentToggled(isConsent) {
		if (isConsent) {
			$('.efbl_feed_wraper, .esf_insta_feed_wraper').each(function() {
				var $container = $(this);
				var flags      = $container.attr('data-esf-flags') || '';
				var mode       = getGdprMode($container);

				if (flags.indexOf('gdpr') === -1) {
					return;
				}

				// Skip "yes" mode: it always stays GDPR-limited.
				if (mode === 'yes') {
					return;
				}

				enableFullFeatures($container);
			});
		}
	}

	// Initialize on document ready.
	$(document).ready(function() {
		initConsentCheck();

		// Helper to re-enable full features after consent from various plugins.
		function triggerAfterConsent() {
			// Small delay so consent plugin can update its cookies/state.
			setTimeout(function() {
				afterConsentToggled(true);
			}, 1000);
		}

		// Cookie Notice by dFactory.
		$('#cookie-notice a').on('click', function() {
			triggerAfterConsent();
		});

		// GDPR Cookie Consent by WebToffee (Cookie Law Info bar).
		$('#cookie-law-info-bar a').on('click', function() {
			triggerAfterConsent();
		});

		// GDPR Cookie Consent by WebToffee (preference checkboxes / notice buttons).
		$('.cli-user-preference-checkbox, .cky-notice button').on('click', function() {
			triggerAfterConsent();
		});

		// Cookiebot by Cybot A/S.
		$(window).on('CookiebotOnAccept', function() {
			triggerAfterConsent();
		});

		// Complianz by Really Simple Plugins (buttons).
		$('.cmplz-btn').on('click', function() {
			if (typeof window.cmplz_accepted_categories === 'function') {
				setTimeout(function() {
					var accepted = window.cmplz_accepted_categories();
					if ($.isArray(accepted) && accepted.indexOf('marketing') > -1) {
						afterConsentToggled(true);
					}
				}, 1000);
			} else {
				triggerAfterConsent();
			}
		});

		// Complianz events.
		$(document).on('cmplzEnableScripts', function(event) {
			if (event.detail === 'marketing') {
				afterConsentToggled(true);
			}
		});

		$(document).on('cmplzFireCategories', function(event) {
			if (event.detail && event.detail.category === 'marketing') {
				afterConsentToggled(true);
			}
		});

		// Borlabs Cookie by Borlabs.
		$(document).on('borlabs-cookie-consent-saved', function() {
			triggerAfterConsent();
		});

		// Real Cookie Banner by devowl.io.
		if (typeof window.consentApi !== 'undefined') {
			window.consentApi.consent('easy-social-feed').then(function() {
				triggerAfterConsent();
			}).catch(function() {
				// Ignore errors from consentApi.
			});
		}

		// Moove GDPR Popup.
		$('.moove-gdpr-infobar-allow-all').on('click', function() {
			triggerAfterConsent();
		});

		// WPConsent events.
		if (typeof window.addEventListener !== 'undefined') {
			window.addEventListener('wpconsent_consent_saved', function() {
				setTimeout(function() {
					if (typeof window.WPConsent !== 'undefined') {
						afterConsentToggled(window.WPConsent.hasConsent('marketing'));
					}
				}, 1000);
			});

			window.addEventListener('wpconsent_consent_updated', function() {
				setTimeout(function() {
					if (typeof window.WPConsent !== 'undefined') {
						afterConsentToggled(window.WPConsent.hasConsent('marketing'));
					}
				}, 1000);
			});
		}
	});

	// Event listeners for consent changes (examples).
	if (typeof window.addEventListener !== 'undefined') {
		// WPConsent.
		window.addEventListener('wpconsent_consent_saved', function() {
			setTimeout(function() {
				if (typeof window.WPConsent !== 'undefined') {
					afterConsentToggled(window.WPConsent.hasConsent('marketing'));
				}
			}, 1000);
		});

		window.addEventListener('wpconsent_consent_updated', function() {
			setTimeout(function() {
				if (typeof window.WPConsent !== 'undefined') {
					afterConsentToggled(window.WPConsent.hasConsent('marketing'));
				}
			}, 1000);
		});
	}

	// Complianz.
	$(document).on('cmplzEnableScripts', function(event) {
		if (event.detail === 'marketing') {
			afterConsentToggled(true);
		}
	});

	// Borlabs Cookie.
	$(document).on('borlabs-cookie-consent-saved', function() {
		afterConsentToggled(true);
	});

	// Real Cookie Banner.
	if (typeof window.consentApi !== 'undefined') {
		window.consentApi.consent('easy-social-feed').then(function() {
			setTimeout(function() {
				afterConsentToggled(true);
			}, 1000);
		});
	}

	// Moove Agency.
	$(document).on('click', '.moove-gdpr-infobar-allow-all', function() {
		setTimeout(function() {
			afterConsentToggled(true);
		}, 1000);
	});

	// Expose functions globally for potential module-specific scripts.
	window.ESFGDPR = {
		checkConsent: checkConsent,
		enableFullFeatures: enableFullFeatures,
		afterConsentToggled: afterConsentToggled
	};

})(jQuery);

