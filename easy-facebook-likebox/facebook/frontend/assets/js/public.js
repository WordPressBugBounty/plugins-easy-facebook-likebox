jQuery(document).ready(function($) {

  jQuery('.efbl-popup-carousel-container img').removeAttr('srcset');

  /*
  *	Check if the like box has loaded. If yes then remove loader and add animation class!
  */

  if ($('.efbl_feed_wraper .efbl_custom_likebox')[0] ||
      $('.widget_easy_facebook_page_plugin .efbl-like-box')[0]) {

    if (typeof FB === 'undefined' || FB === null) {

      setTimeout(function() { $('.efbl-loader').remove(); }, 3000);

    }
    else {

      FB.Event.subscribe('xfbml.render', function(response) {

        var animclasses = $('.efbl-like-box .fb-page').data('animclass');

        $('.efbl-loader').remove();

        $('.efbl-like-box iframe').addClass('animated ' + animclasses);

      });

    }
  }

  // Sanitize content to prevent XSS attacks
  function sanitizeContent(content) {
    if (!content) return '';
    
    // Create a temporary div to safely parse and sanitize HTML
    var tempDiv = document.createElement('div');
    tempDiv.innerHTML = content;
    
    // Remove script tags and event handlers
    var scripts = tempDiv.querySelectorAll('script');
    for (var i = 0; i < scripts.length; i++) {
      scripts[i].parentNode.removeChild(scripts[i]);
    }
    
    // Remove event handlers from all elements
    var allElements = tempDiv.querySelectorAll('*');
    for (var i = 0; i < allElements.length; i++) {
      var element = allElements[i];
      var attributes = element.attributes;
      for (var j = attributes.length - 1; j >= 0; j--) {
        var attr = attributes[j];
        if (attr.name.toLowerCase().startsWith('on') || 
            attr.name.toLowerCase() === 'javascript:' ||
            attr.value.toLowerCase().includes('javascript:')) {
          element.removeAttribute(attr.name);
        }
      }
    }
    
    return tempDiv.innerHTML;
  }

  // Magic function that will prepare and render markup.
  function efbl_render_poup_markup(object) {

    var $story_link = object.data('storylink'),
        $story_link_text = object.data('linktext'),
        $caption = object.data('caption'),
        $image_url = object.data('imagelink'),
        $iframe_vid_url = object.data('videolink'),
        $video_url = object.data('video'),
        $itemnumber = object.data('itemnumber'),
        $windowWidth = window.innerWidth,
        $windowHeight = window.innerHeight - 200;

    // Detect GDPR mode and consent for this feed container.
    var $feedContainer = object.closest('.efbl_feed_wraper');
    var flagsAttr = $feedContainer.length ? ($feedContainer.attr('data-esf-flags') || '') : '';
    var flags = flagsAttr ? flagsAttr.split(',') : [];
    var hasGdpr = flags.indexOf('gdpr') > -1;
    var mode = 'none';

    if (flags.indexOf('gdpr_yes') > -1) {
      mode = 'yes';
    } else if (flags.indexOf('gdpr_auto') > -1) {
      mode = 'auto';
    }

    var consentGiven = false;
    if (window.ESFGDPR && typeof window.ESFGDPR.checkConsent === 'function' && $feedContainer.length) {
      consentGiven = window.ESFGDPR.checkConsent($feedContainer);
    }

    // Media is allowed only when:
    // - GDPR is not enabled, OR
    // - mode is "auto" and consent has been given.
    var mediaAllowed = true;
    if (hasGdpr) {
      if (mode === 'yes') {
        mediaAllowed = false;
      } else if (mode === 'auto' && !consentGiven) {
        mediaAllowed = false;
      }
    }

    $('.white-popup .efbl_popup_left_container').css({
      'width': 'auto',
      'height': 'auto',
    });

    $('.efbl_popup_image').css('height', 'auto');

    // Helper to get a placeholder image URL from the clicked element, if available.
    function getPlaceholderFromElement(el) {
      var bg = el.css('background-image') || '';
      if (!bg || bg === 'none') {
        return '';
      }
      // Expect format: url("...") or url('...') or url(...)
      bg = bg.replace(/^url\((['"]?)/, '').replace(/(['"]?)\)$/, '');
      return bg;
    }

    // IMAGES
    if ($image_url) {
      if (mediaAllowed) {
        $('#efblcf_holder .efbl_popup_image').attr('src', $image_url);
        $('#efblcf_holder .efbl_popup_image').css('display', 'block');
      } else {
        var placeholderImg = getPlaceholderFromElement(object);
        if (placeholderImg) {
          $('#efblcf_holder .efbl_popup_image').attr('src', placeholderImg);
          $('#efblcf_holder .efbl_popup_image').css('display', 'block');
        } else {
          // No safe placeholder, hide image completely.
          $('#efblcf_holder .efbl_popup_image').attr('src', '');
          $('#efblcf_holder .efbl_popup_image').css('display', 'none');
        }
      }
    }

    // IFRAME VIDEO
    if ($iframe_vid_url) {
      if (mediaAllowed) {
        $('#efblcf_holder .efbl_popup_if_video').attr('src', $iframe_vid_url);
        $('#efblcf_holder .efbl_popup_if_video').css({
          'display': 'block',
          'width': '720px',
          'height': '400px',
        });
      } else {
        $('#efblcf_holder .efbl_popup_if_video').attr('src', '');
        $('#efblcf_holder .efbl_popup_if_video').css('display', 'none');
      }

    }

    // HTML5 VIDEO
    if ($video_url) {
      if (mediaAllowed) {
        $('#efblcf_holder .efbl_popup_video').attr('src', $video_url);
        $('#efblcf_holder .efbl_popup_video').css('display', 'block');
        setTimeout(function() {
          $('#efblcf_holder .efbl_popup_video')[0].play();
        }, 500);
      } else {
        $('#efblcf_holder .efbl_popup_video').attr('src', '');
        $('#efblcf_holder .efbl_popup_video').css('display', 'none');
      }

    }

    //$('.efbl-popup-next').attr('data-itemnumber', $itemnumber+1);
    //$('.efbl-popup-prev').attr('data-itemnumber', $itemnumber-1);

    $('.efbl_feed_wraper #item_number').val($itemnumber);

    if ($caption) {
      // Sanitize caption and link text to prevent XSS
      var sanitizedCaption = sanitizeContent($caption);
      var sanitizedLinkText = sanitizeContent($story_link_text);
      
      $('#efblcf_holder .efbl_popupp_footer').
          html(
              '<p>' + sanitizedCaption + ' <br> <a class="efbl_popup_readmore" href="' +
              $story_link + '" target="_blank">' + sanitizedLinkText +
              '</a></p>');
      $('#efblcf_holder .efbl_popupp_footer').css('display', 'block');
    }

  }

  function reset_popup_holder() {
    //Clear the container for new instance
    $('#efblcf_holder .efbl_popup_image').attr('src', '');
    $('#efblcf_holder .efbl_popup_image').css('display', 'none');

    $('#efblcf_holder .efbl_popup_if_video').attr('src', '');
    $('#efblcf_holder .efbl_popup_if_video').css('display', 'none');

    $('#efblcf_holder .efbl_popup_video').attr('src', '');
    $('#efblcf_holder .efbl_popup_video').css('display', 'none');

    $('#efblcf_holder .efbl_popupp_footer').html('');
    $('#efblcf_holder .efbl_popupp_footer').css('display', 'none');
  }

  $('.efbl_feed_popup').esfFreePopup({
    type: 'ajax',
    tLoading: 'Loading...',
    preloader: false,
    mainClass: 'esfp-fade',

    callbacks: {

      ajaxContentAdded: function() {
        // Ajax content is loaded and appended to DOM

        efbl_render_poup_markup(this.st.el);

        // GDPR: if consent is already given and popup content contains
        // placeholder images (esf-no-consent), swap them to real URLs.
        if (window.ESFGDPR && typeof window.ESFGDPR.checkConsent === 'function') {
          try {
            // Use the first feed wrapper on the page to evaluate consent state.
            var $feedContainer = jQuery('.efbl_feed_wraper').first();
            if ($feedContainer.length && window.ESFGDPR.checkConsent($feedContainer)) {
              // Target the most recently added popup.
              var $popup = jQuery('.efbl-popup').last();
              if ($popup.length) {
                $popup.find('.esf-no-consent').each(function() {
                  var $element = jQuery(this);
                  var realImageUrl = $element.attr('data-image-url');

                  if (realImageUrl) {
                    // Update <img> tag if present.
                    var $img = $element.is('img') ? $element : $element.find('img');
                    if ($img.length) {
                      $img.attr('src', realImageUrl);
                    }

                    // Also update background-image if used.
                    $element.css('background-image', 'url(' + realImageUrl + ')');

                    $element.removeClass('esf-no-consent');
                    $element.removeAttr('data-image-url');
                  }
                });
              }
            }
          } catch (e) {
            // Fail silently; do not break popup if something goes wrong.
          }
        }
      },

      beforeOpen: function() {
        // console.log(this.st.el);

        // efbl_render_poup_markup(this.st.el);

      },

      beforeClose: function() {

        reset_popup_holder();

      },
    },

  });

  $('.efbl_share_links').click(function() {
    $(this).next('.efbl_links_container').slideToggle('slow');
  });

  $('.efbl_info').click(function() {
    $(this).siblings('.efbl_comments_wraper').slideToggle('slow');
  });

  jQuery(document).
      on('click', 'div[data-class=\'efbl_redirect_home\']', function(event) {

        window.open(
            'https://easysocialfeed.com/?utm_campaign=powered-by&utm_medium=link&utm_source=plugin',
            '_blank');
      });

  $('.esf-share').click(function(e) {
    e.preventDefault();
    $(this).next().slideToggle();
  });

});