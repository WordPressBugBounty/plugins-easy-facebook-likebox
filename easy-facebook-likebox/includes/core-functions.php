<?php
/**
 * Define all the global functions for ESF modules
 */

/**
 * Check if elementor is active and in preview mode
 *
 * @since 6.3.0
 *
 * @return bool
 */
if ( ! function_exists( 'esf_is_elementor_preview' ) ) {
	function esf_is_elementor_preview() {
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			return true;
		} else {
			return false;
		}
	}
}

/**
 * Convert caption links to actual links
 *
 * @since 6.3.2
 *
 * @return $text
 */
if ( ! function_exists( 'esf_convert_to_hyperlinks' ) ) {
	function esf_convert_to_hyperlinks(
		$value,
		$protocols = array(
			'http',
			'mail',
			'https',
		),
		array $attributes = array()
	) {
		// Link attributes
		$attr = '';
		foreach ( $attributes as $key => $val ) {
			$attr .= ' ' . $key . '="' . htmlentities( $val ) . '"';
		}

		$links = array();

		// Extract existing links and tags
		$value = preg_replace_callback(
			'~(<a .*?>.*?</a>|<.*?>)~i',
			function ( $match ) use ( &$links ) {
				return '<' . array_push( $links, $match[1] ) . '>';
			},
			$value
		);

		// Extract text links for each protocol
		foreach ( (array) $protocols as $protocol ) {
			switch ( $protocol ) {
				case 'http':
				case 'https':
					$value = preg_replace_callback(
						'~(?:(https?)://([^\s<]+)|(www\.[^\s<]+?\.[^\s<]+))(?<![\.,:])~i',
						function ( $match ) use ( $protocol, &$links, $attr ) {
							if ( $match[1] ) {
								$protocol = $match[1];
							}
							$link = $match[2] ?: $match[3];

							return '<' . array_push( $links, "<a $attr href=\"$protocol://$link\">$link</a>" ) . '>';
						},
						$value
					);
					break;
				case 'mail':
					$value = preg_replace_callback(
						'~([^\s<]+?@[^\s<]+?\.[^\s<]+)(?<![\.,:])~',
						function ( $match ) use ( &$links, $attr ) {
							return '<' . array_push( $links, "<a $attr href=\"mailto:{$match[1]}\">{$match[1]}</a>" ) . '>';
						},
						$value
					);
					break;
				case 'twitter':
					$value = preg_replace_callback(
						'~(?<!\w)[@#](\w++)~',
						function ( $match ) use ( &$links, $attr ) {
							return '<' . array_push( $links, "<a $attr href=\"https://twitter.com/" . ( $match[0][0] == '@' ? '' : 'search/%23' ) . $match[1] . "\">{$match[0]}</a>" ) . '>';
						},
						$value
					);
					break;
				default:
					$value = preg_replace_callback(
						'~' . preg_quote( $protocol, '~' ) . '://([^\s<]+?)(?<![\.,:])~i',
						function ( $match ) use ( $protocol, &$links, $attr ) {
							return '<' . array_push( $links, "<a $attr href=\"$protocol://{$match[1]}\">{$match[1]}</a>" ) . '>';
						},
						$value
					);
					break;
			}
		}

		// Insert all link
		return preg_replace_callback(
			'/<(\d+)>/',
			function ( $match ) use ( &$links ) {
				return $links[ $match[1] - 1 ];
			},
			$value
		);
	}
}

if ( ! function_exists( 'jws_fetchUrl' ) ) {
	//Get JSON object of feed data
	function jws_fetchUrl( $url ) {

		$args     = array(
			'timeout'   => 150,
			'sslverify' => false,
		);
		$feedData = wp_remote_get( $url, $args );

		if ( $feedData && ! is_wp_error( $feedData ) ) {
			return $feedData['body'];
		} else {
			return $feedData;
		}
	}
}

if ( ! function_exists( 'esf_get_uploads_directory' ) ) {
	/**
	 * Return the modules uploads directory
	 *
	 * @param string $module
	 *
	 * @return string
	 *
	 * @since 6.4.4
	 */
	function esf_get_uploads_directory( $module = 'facebook' ) {
		$upload_dir = wp_upload_dir();
		$upload_dir = $upload_dir['basedir'];
		return $upload_dir . '/esf-' . esc_attr( $module );
	}
}
if ( ! function_exists( 'esf_serve_media_locally' ) ) {
	/**
	 * Save media locally
	 *
	 * @param        $id
	 * @param        $url
	 * @param string $module
	 *
	 * @return false|string|void
	 */
	function esf_serve_media_locally( $id, $url, $module = 'facebook' ) {

		if ( ! $id || ! $url ) {
			return false;
		}

		$directory = esf_get_uploads_directory( $module );
		$file      = $directory . '/' . esc_attr( $id ) . '.jpg';

		$upload_dir = wp_upload_dir();
		$upload_url = $upload_dir['baseurl'];
		$img_url    = $upload_url . '/esf-' . esc_attr( $module ) . '/' . esc_attr( $id ) . '.jpg';

		if ( ! file_exists( $file ) ) {
			$response = wp_remote_get( $url );

			if ( ! is_wp_error( $response ) ) {
				$image_data = wp_remote_retrieve_body( $response );
				$saved      = file_put_contents( $file, $image_data );

				if ( false === $saved ) {
					return false;
				} else {
					return $img_url;
				}
			}
		} else {
			return $img_url;
		}
	}
}

if ( ! function_exists( 'esf_is_valid_image_url' ) ) {
	/**
	 * Check if an image URL is reachable (HTTP 200).
	 *
	 * Central helper so URL validity checks are consistent across the plugin.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	function esf_is_valid_image_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}

		$args = array(
			'timeout'   => 5,
			'sslverify' => false,
			'method'    => 'HEAD',
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		return ( 200 === $code );
	}
}

if ( ! function_exists( 'esf_is_local_media_url' ) ) {
	/**
	 * Check if a URL points to media served from the site's ESF uploads folder.
	 * Local media does not trigger third-party requests, so GDPR placeholder can be skipped.
	 *
	 * @param string $url    Image URL to check.
	 * @param string $module Module slug (e.g. 'facebook', 'instagram').
	 * @return bool True if the URL is from this site's uploads/esf-{module} folder.
	 */
	function esf_is_local_media_url( $url, $module = 'facebook' ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}
		$upload_dir = wp_upload_dir();
		$baseurl    = untrailingslashit( $upload_dir['baseurl'] );
		$path       = '/esf-' . $module . '/';
		return ( strpos( $url, $baseurl . $path ) === 0 );
	}
}

if ( ! function_exists( 'esf_readable_count' ) ) {
	/**
	 * Format large numbers into short, human-readable strings.
	 *
	 * Shared helper for all modules so follower counts, views, likes, etc.
	 * are displayed consistently (e.g. 1.2K, 3.4M).
	 *
	 * @since 6.5.0
	 *
	 * @param int|float|string $number Raw numeric value.
	 * @return string Human-readable representation.
	 */
	function esf_readable_count( $number ) {
		if ( ! is_numeric( $number ) ) {
			$number = (int) $number;
		}

		$number = (float) $number;

		if ( $number >= 1000000000 ) {
			return round( $number / 1000000000, 1 ) . __( 'B', 'easy-facebook-likebox' );
		}

		if ( $number >= 1000000 ) {
			return round( $number / 1000000, 1 ) . __( 'M', 'easy-facebook-likebox' );
		}

		if ( $number >= 1000 ) {
			return round( $number / 1000, 1 ) . __( 'K', 'easy-facebook-likebox' );
		}

		return number_format_i18n( (int) $number );
	}
}

if ( ! function_exists( 'esf_readable_time_ago' ) ) {
	/**
	 * Convert a date/time string to a relative "time ago" string.
	 *
	 * Wrapper around human_time_diff() that accepts common date formats
	 * and appends a translated "ago" suffix. Intended for feed items
	 * (Facebook, Instagram, YouTube, etc.).
	 *
	 * @since 6.5.0
	 *
	 * @param string|int $datetime Datetime string or Unix timestamp.
	 * @return string Human-readable relative time.
	 */
	function esf_readable_time_ago( $datetime ) {
		if ( empty( $datetime ) ) {
			return '';
		}

		$timestamp = is_numeric( $datetime ) ? (int) $datetime : strtotime( $datetime );
		if ( ! $timestamp ) {
			return '';
		}

		$diff = human_time_diff( $timestamp, current_time( 'timestamp' ) );

		return sprintf(
			/* translators: %s: human readable time difference, e.g. "2 hours" */
			__( '%s ago', 'easy-facebook-likebox' ),
			$diff
		);
	}
}

if ( ! function_exists( 'esf_delete_media' ) ) {
	/**
	 * Delete media locally
	 *
	 * @param        $id
	 * @param string $module
	 *
	 * @return bool
	 */
	function esf_delete_media( $id, $module = 'facebook' ) {
		if ( ! $id ) {
			return false;
		}

		$directory = esf_get_uploads_directory( $module );
		$file      = $directory . '/' . esc_attr( $id ) . '.jpg';

		if ( file_exists( $file ) ) {
			unlink( $file );
		} else {
			return false;
		}
	}
}
if ( ! function_exists( 'esf_delete_media_folder' ) ) {
	/**
	 * Delete media local folder
	 *
	 * @param        $id
	 * @param string $module
	 *
	 * @return bool
	 */
	function esf_delete_media_folder( $module = 'facebook' ) {
		$directory = esf_get_uploads_directory( $module );
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$file_system_direct = new WP_Filesystem_Direct( false );
		$file_system_direct->rmdir( $directory, true );
	}
}

if ( ! function_exists( 'esf_sort_by_created_time' ) ) {
	/**
	 * Sort data to created_time
	 *
	 * @param null $data
	 *
	 * @return false|mixed
	 *
	 * @since 6.4.9
	 */
	function esf_sort_by_created_time( $data = null ) {

		if ( ! $data ) {
			return false;
		}
		$order = array();
		foreach ( $data as $single_post ) {
			$order[] = strtotime( $single_post->created_time );
		}
		array_multisort( $order, SORT_DESC, $data );
		return $data;
	}
}

if ( ! function_exists( 'esf_check_ajax_referer' ) ) {
	/**
	 * Check ajax referer
	 *
	 * @since 6.5.0
	 */
	function esf_check_ajax_referer() {
		if ( ! check_ajax_referer( 'esf-ajax-nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( "Nonce not verified or you don't have appropriate permissions", 'easy-facebook-likebox' ) );
		}
	}
}

if ( ! function_exists( 'esf_get_design_value' ) ) {
	/**
	 * Get design value
	 *
	 * @param array $skin
	 * @param string $key
	 * @param string $default
	 *
	 * @since 6.6.1
	 */
	function esf_get_design_value( $skin, $key, $default = '' ) {
		if ( ! $key || ! $skin ) {
			return $default;
		}
		return isset( $skin['design'][ $key ] ) ? $skin['design'][ $key ] : $default;
	}
}
/**
 * Safe wrapper for esf_safe_strpos() that handles null values
 *
 * @param mixed $haystack The string to search in
 * @param string $needle The string to search for
 * @param int $offset The optional offset parameter
 */
if ( ! function_exists( 'esf_safe_strpos' ) ) {
	function esf_safe_strpos( $haystack, $needle, $offset = 0 ) {
		// Check if haystack is null or not a string or not defined
		if ( ! isset( $haystack ) || $haystack === null || ! is_string( $haystack ) ) {
			return false;
		}

		return strpos( $haystack, $needle, $offset );
	}
}

/**
 * Compile and filter the list of locales for Facebook/Instagram API and widgets.
 * Used by feed API language setting, like box locale, and page plugin widget.
 *
 * @return array<string, string> Locale code => label.
 */
if ( ! function_exists( 'efbl_get_locales' ) ) {
	function efbl_get_locales() {
		$locales = array(
			'af_ZA' => 'Afrikaans',
			'ar_AR' => 'Arabic',
			'az_AZ' => 'Azeri',
			'be_BY' => 'Belarusian',
			'bg_BG' => 'Bulgarian',
			'bn_IN' => 'Bengali',
			'bs_BA' => 'Bosnian',
			'ca_ES' => 'Catalan',
			'cs_CZ' => 'Czech',
			'cy_GB' => 'Welsh',
			'da_DK' => 'Danish',
			'de_DE' => 'German',
			'el_GR' => 'Greek',
			'en_US' => 'English (US)',
			'en_GB' => 'English (UK)',
			'eo_EO' => 'Esperanto',
			'es_ES' => 'Spanish (Spain)',
			'es_LA' => 'Spanish',
			'et_EE' => 'Estonian',
			'eu_ES' => 'Basque',
			'fa_IR' => 'Persian',
			'fb_LT' => 'Leet Speak',
			'fi_FI' => 'Finnish',
			'fo_FO' => 'Faroese',
			'fr_FR' => 'French (France)',
			'fr_CA' => 'French (Canada)',
			'fy_NL' => 'NETHERLANDS (NL)',
			'ga_IE' => 'Irish',
			'gl_ES' => 'Galician',
			'hi_IN' => 'Hindi',
			'hr_HR' => 'Croatian',
			'hu_HU' => 'Hungarian',
			'hy_AM' => 'Armenian',
			'id_ID' => 'Indonesian',
			'is_IS' => 'Icelandic',
			'it_IT' => 'Italian',
			'ja_JP' => 'Japanese',
			'ka_GE' => 'Georgian',
			'km_KH' => 'Khmer',
			'ko_KR' => 'Korean',
			'ku_TR' => 'Kurdish',
			'la_VA' => 'Latin',
			'lt_LT' => 'Lithuanian',
			'lv_LV' => 'Latvian',
			'mk_MK' => 'Macedonian',
			'ml_IN' => 'Malayalam',
			'ms_MY' => 'Malay',
			'nb_NO' => 'Norwegian (bokmal)',
			'ne_NP' => 'Nepali',
			'nl_NL' => 'Dutch',
			'nn_NO' => 'Norwegian (nynorsk)',
			'pa_IN' => 'Punjabi',
			'pl_PL' => 'Polish',
			'ps_AF' => 'Pashto',
			'pt_PT' => 'Portuguese (Portugal)',
			'pt_BR' => 'Portuguese (Brazil)',
			'ro_RO' => 'Romanian',
			'ru_RU' => 'Russian',
			'sk_SK' => 'Slovak',
			'sl_SI' => 'Slovenian',
			'sq_AL' => 'Albanian',
			'sr_RS' => 'Serbian',
			'sv_SE' => 'Swedish',
			'sw_KE' => 'Swahili',
			'ta_IN' => 'Tamil',
			'te_IN' => 'Telugu',
			'th_TH' => 'Thai',
			'tl_PH' => 'Filipino',
			'tr_TR' => 'Turkish',
			'uk_UA' => 'Ukrainian',
			'ur_PK' => 'Urdu',
			'vi_VN' => 'Vietnamese',
			'zh_CN' => 'Simplified Chinese (China)',
			'zh_HK' => 'Traditional Chinese (Hong Kong)',
			'zh_TW' => 'Traditional Chinese (Taiwan)',
		);

		return apply_filters( 'efbl_locale_names', $locales );
	}
}

/**
 * Supported locales for Facebook and Instagram Graph API (feed language).
 * Default option plus efbl_get_locales() for the admin dropdown.
 *
 * @since 6.8.0
 * @return array<string, string>
 */
if ( ! function_exists( 'esf_get_supported_api_locales' ) ) {
	function esf_get_supported_api_locales() {
		return array_merge(
			array( '' => __( 'Default (use site language)', 'easy-facebook-likebox' ) ),
			efbl_get_locales()
		);
	}
}

/**
 * Normalize a WordPress/Feed language locale to one of the supported EFBL locales.
 *
 * Example: "ar" -> "ar_AR" (if that key exists), "es" -> "es_ES", etc.
 *
 * @since 6.7.6
 *
 * @param string $locale Raw locale (e.g. from get_locale() or saved setting).
 * @return string Normalized locale key from efbl_get_locales(), or empty string if not found.
 */
if ( ! function_exists( 'esf_normalize_locale_to_supported' ) ) {
	function esf_normalize_locale_to_supported( $locale ) {
		$locale = (string) $locale;
		if ( '' === $locale ) {
			return '';
		}

		$supported = efbl_get_locales();

		// Exact match first.
		if ( isset( $supported[ $locale ] ) ) {
			return $locale;
		}

		// Try to map based on the language code only (first two letters).
		$lang2 = substr( $locale, 0, 2 );
		if ( ! $lang2 ) {
			return '';
		}

		foreach ( $supported as $key => $label ) {
			if ( 0 === strpos( $key, $lang2 . '_' ) || $key === strtoupper( $lang2 ) . '_' ) {
				return $key;
			}
		}

		return '';
	}
}

/**
 * Effective API locale for Facebook/Instagram requests.
 * Uses saved setting, or WordPress site language, or en_US.
 *
 * @since 6.8.0
 * @return string Locale code (e.g. en_US, fr_FR).
 */
if ( ! function_exists( 'esf_get_effective_api_locale' ) ) {
	function esf_get_effective_api_locale() {
		$settings = get_option( 'fta_settings', array() );
		$saved    = isset( $settings['api_locale'] ) ? $settings['api_locale'] : '';

		// 1) Saved Feed language from General tab.
		if ( '' !== $saved && is_string( $saved ) ) {
			$normalized_saved = esf_normalize_locale_to_supported( $saved );
			if ( '' !== $normalized_saved ) {
				return $normalized_saved;
			}
		}

		// 2) Site language (used when "Default" is selected).
		$wp_locale = get_locale();
		if ( ! empty( $wp_locale ) ) {
			$normalized_wp = esf_normalize_locale_to_supported( $wp_locale );
			if ( '' !== $normalized_wp ) {
				return $normalized_wp;
			}
		}

		// 3) Fallback.
		return 'en_US';
	}
}

/**
 * Clear all Facebook and Instagram feed transients (cache).
 * Safe to call when API language or other feed-affecting settings change.
 *
 * @since 6.8.0
 * @return int|false Number of rows deleted, or false on error.
 */
if ( ! function_exists( 'esf_clear_feed_transients' ) ) {
	function esf_clear_feed_transients() {
		global $wpdb;

		if ( ! isset( $wpdb->options ) || ! $wpdb->options ) {
			return false;
		}

		$option_table = $wpdb->options;

		$like_efbl         = $wpdb->esc_like( '_transient_efbl_' ) . '%';
		$like_efbl_timeout = $wpdb->esc_like( '_transient_timeout_efbl_' ) . '%';
		$like_esf          = $wpdb->esc_like( '_transient_esf_' ) . '%';
		$like_esf_timeout  = $wpdb->esc_like( '_transient_timeout_esf_' ) . '%';

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$option_table} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$like_efbl,
				$like_efbl_timeout,
				$like_esf,
				$like_esf_timeout
			)
		);

		return $deleted;
	}
}

