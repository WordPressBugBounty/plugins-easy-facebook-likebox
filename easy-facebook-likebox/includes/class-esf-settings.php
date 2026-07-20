<?php
/**
 * Modern plugin settings store with one-time migration from `fta_settings`.
 *
 * Global hub/settings data lives in `esf_settings`. Legacy Facebook and Instagram
 * module data remains in `fta_settings['plugins'][…]` and is kept in sync when
 * module status or shared global keys change.
 *
 * @package Easy_Social_Feed
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Settings' ) ) {

	/**
	 * Read/write helpers for `esf_settings` with legacy sync.
	 *
	 * @since 6.9.0
	 */
	class ESF_Settings {

		/**
		 * Modern settings option key.
		 *
		 * @since 6.9.0
		 * @var string
		 */
		const OPTION_KEY = 'esf_settings';

		/**
		 * Legacy settings option key (Facebook/Instagram data + backward compat).
		 *
		 * @since 6.9.0
		 * @var string
		 */
		const LEGACY_OPTION_KEY = 'fta_settings';

		/**
		 * Migration flag option.
		 *
		 * @since 6.9.0
		 * @var string
		 */
		const MIGRATED_FLAG_KEY = 'esf_settings_migrated_v1';

		/**
		 * Known module slugs managed from the hub.
		 *
		 * @since 6.9.0
		 * @var array<int,string>
		 */
		const MODULE_SLUGS = array( 'facebook', 'instagram', 'youtube', 'twitter' );

		/**
		 * Run migration once per site.
		 *
		 * @since 6.9.0
		 * @return void
		 */
		public static function maybe_migrate() {
			if ( get_option( self::MIGRATED_FLAG_KEY, false ) ) {
				return;
			}

			$legacy = get_option( self::LEGACY_OPTION_KEY, array() );
			if ( ! is_array( $legacy ) ) {
				$legacy = array();
			}

			$modern = self::build_defaults();

			if ( isset( $legacy['version'] ) ) {
				$modern['version'] = (string) $legacy['version'];
			}
			if ( isset( $legacy['installDate'] ) ) {
				$modern['install_date'] = (string) $legacy['installDate'];
			}

			$modern['general'] = array(
				'preserve_settings_on_uninstall' => isset( $legacy['preserve_settings_on_uninstall'] )
					? (int) (bool) $legacy['preserve_settings_on_uninstall']
					: 0,
				'api_locale'                     => isset( $legacy['api_locale'] ) ? (string) $legacy['api_locale'] : '',
			);

			$modern['gdpr'] = isset( $legacy['gdpr'] ) && in_array( $legacy['gdpr'], array( 'auto', 'yes', 'no' ), true )
				? (string) $legacy['gdpr']
				: 'auto';

			if ( isset( $legacy['translation'] ) && is_array( $legacy['translation'] ) ) {
				$modern['translation'] = $legacy['translation'];
			}
			if ( isset( $legacy['translation_ai_used'] ) && is_array( $legacy['translation_ai_used'] ) ) {
				$modern['translation_ai_used'] = $legacy['translation_ai_used'];
			}
			if ( isset( $legacy['welcome'] ) && is_array( $legacy['welcome'] ) ) {
				$modern['welcome'] = $legacy['welcome'];
			}

			foreach ( self::MODULE_SLUGS as $slug ) {
				$status = 'activated';
				if (
					isset( $legacy['plugins'][ $slug ]['status'] )
					&& in_array( $legacy['plugins'][ $slug ]['status'], array( 'activated', 'deactivated' ), true )
				) {
					$status = (string) $legacy['plugins'][ $slug ]['status'];
				}
				$modern['modules'][ $slug ] = array( 'status' => $status );
			}

			update_option( self::OPTION_KEY, $modern );
			update_option( self::MIGRATED_FLAG_KEY, '1' );
		}

		/**
		 * Default modern settings shape.
		 *
		 * @since 6.9.0
		 * @return array<string,mixed>
		 */
		public static function build_defaults() {
			$modules = array();
			foreach ( self::MODULE_SLUGS as $slug ) {
				$modules[ $slug ] = array( 'status' => 'activated' );
			}

			return array(
				'version'              => defined( 'FTA_VERSION' ) ? FTA_VERSION : '6.9.0',
				'install_date'         => '',
				'modules'              => $modules,
				'general'              => array(
					'preserve_settings_on_uninstall' => 0,
					'api_locale'                     => '',
				),
				'gdpr'                 => 'auto',
				'translation'          => array(),
				'translation_ai_used'  => array(),
				'welcome'              => array(),
			);
		}

		/**
		 * Read the modern settings array (migrating first when needed).
		 *
		 * @since 6.9.0
		 * @return array<string,mixed>
		 */
		public static function get() {
			self::maybe_migrate();

			$settings = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $settings ) ) {
				$settings = self::build_defaults();
				update_option( self::OPTION_KEY, $settings );
			}

			return array_replace_recursive( self::build_defaults(), $settings );
		}

		/**
		 * Persist modern settings and mirror shared keys into legacy storage.
		 *
		 * @since 6.9.0
		 *
		 * @param array<string,mixed> $settings Settings payload.
		 * @return bool
		 */
		public static function save( array $settings ) {
			$merged   = array_replace_recursive( self::build_defaults(), $settings );
			$previous = get_option( self::OPTION_KEY, null );
			$updated  = update_option( self::OPTION_KEY, $merged );
			self::sync_legacy_from_modern( $merged );

			// update_option() returns false when the value is unchanged; treat that as success.
			if ( $updated ) {
				return true;
			}

			return maybe_serialize( $previous ) === maybe_serialize( $merged );
		}

		/**
		 * Get a module activation status.
		 *
		 * @since 6.9.0
		 *
		 * @param string $slug Module slug.
		 * @return string `activated` or `deactivated`.
		 */
		public static function get_module_status( $slug ) {
			$slug     = sanitize_key( (string) $slug );
			$settings = self::get();
			$status   = isset( $settings['modules'][ $slug ]['status'] )
				? (string) $settings['modules'][ $slug ]['status']
				: 'activated';

			return in_array( $status, array( 'activated', 'deactivated' ), true ) ? $status : 'activated';
		}

		/**
		 * Save General tab fields (feed language, preserve on uninstall).
		 *
		 * @since 6.9.0
		 *
		 * @param bool        $preserve_on_uninstall Whether to keep data on uninstall.
		 * @param string|null $api_locale            Selected API locale or null to skip.
		 * @return bool
		 */
		public static function save_general( $preserve_on_uninstall, $api_locale = null ) {
			$settings            = self::get();
			$previous_api_locale = isset( $settings['general']['api_locale'] ) ? (string) $settings['general']['api_locale'] : '';

			$settings['general']['preserve_settings_on_uninstall'] = $preserve_on_uninstall ? 1 : 0;

			if ( null !== $api_locale && function_exists( 'esf_get_supported_api_locales' ) ) {
				$supported = array_keys( esf_get_supported_api_locales() );
				$locale    = in_array( $api_locale, $supported, true ) ? (string) $api_locale : '';
				$settings['general']['api_locale'] = $locale;
			}

			$new_api_locale = isset( $settings['general']['api_locale'] ) ? (string) $settings['general']['api_locale'] : '';
			if ( $new_api_locale !== $previous_api_locale ) {
				if ( function_exists( 'esf_clear_feed_transients' ) ) {
					esf_clear_feed_transients();
				}
				if ( class_exists( 'ESF_YouTube_Cache' ) && method_exists( 'ESF_YouTube_Cache', 'flush_api_cache' ) ) {
					ESF_YouTube_Cache::flush_api_cache();
				}
			}

			return self::save( $settings );
		}

		/**
		 * Save GDPR mode.
		 *
		 * @since 6.9.0
		 *
		 * @param string $gdpr One of `auto`, `yes`, `no`.
		 * @return bool
		 */
		public static function save_gdpr( $gdpr ) {
			$gdpr = sanitize_text_field( (string) $gdpr );
			if ( ! in_array( $gdpr, array( 'auto', 'yes', 'no' ), true ) ) {
				return false;
			}

			$settings = self::get();
			if ( isset( $settings['gdpr'] ) && (string) $settings['gdpr'] === $gdpr ) {
				return true;
			}

			$settings['gdpr'] = $gdpr;

			return self::save( $settings );
		}

		/**
		 * Save custom translation strings (whitelisted keys only).
		 *
		 * @since 6.9.0
		 *
		 * @param array<string,string> $posted Raw key => value map from the client.
		 * @return bool
		 */
		public static function save_translation( array $posted ) {
			if ( ! class_exists( 'ESF_Translation_Strings' ) ) {
				return false;
			}

			$allowed_keys = array();
			$all_strings  = ESF_Translation_Strings::get_all_strings();
			foreach ( $all_strings as $category ) {
				if ( ! isset( $category['strings'] ) || ! is_array( $category['strings'] ) ) {
					continue;
				}
				foreach ( $category['strings'] as $item ) {
					if ( ! empty( $item['key'] ) ) {
						$allowed_keys[ (string) $item['key'] ] = true;
					}
				}
			}

			$saved = array();
			foreach ( $posted as $key => $value ) {
				$key = sanitize_text_field( (string) $key );
				if ( isset( $allowed_keys[ $key ] ) && is_string( $value ) ) {
					$saved[ $key ] = sanitize_text_field( $value );
				}
			}

			$settings                  = self::get();
			$settings['translation']   = $saved;

			return self::save( $settings );
		}

		/**
		 * Mark a locale as having used the AI autofill banner.
		 *
		 * @since 6.9.0
		 *
		 * @param string $locale Locale code.
		 * @return void
		 */
		public static function mark_translation_ai_used( $locale ) {
			$locale = sanitize_text_field( (string) $locale );
			if ( '' === $locale ) {
				return;
			}

			$settings = self::get();
			if ( ! isset( $settings['translation_ai_used'] ) || ! is_array( $settings['translation_ai_used'] ) ) {
				$settings['translation_ai_used'] = array();
			}
			$settings['translation_ai_used'][ $locale ] = true;
			self::save( $settings );
		}

		/**
		 * Fetch AI translation suggestions for empty custom strings.
		 *
		 * @since 6.9.0
		 *
		 * @param string              $locale Target locale.
		 * @param array<int,string>   $keys   String keys to translate.
		 * @return array<string,string>|WP_Error
		 */
		public static function fetch_translation_suggestions( $locale, array $keys ) {
			if ( ! class_exists( 'ESF_Translation_Strings' ) ) {
				return new WP_Error( 'esf_missing_strings', __( 'Translation registry is unavailable.', 'easy-facebook-likebox' ) );
			}

			$locale = sanitize_text_field( (string) $locale );
			$keys   = array_values( array_filter( array_map( 'sanitize_text_field', $keys ) ) );
			if ( empty( $keys ) ) {
				return array();
			}

			$defaults_by_key = ESF_Translation_Strings::get_defaults_for_keys( $keys );
			if ( empty( $defaults_by_key ) ) {
				return array();
			}

			$response = wp_remote_post(
				'https://api.easysocialfeed.com/wp-json/esf-translate/v1/strings',
				array(
					'timeout' => 10,
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'locale'  => $locale,
							'strings' => $defaults_by_key,
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'esf_translate_request_failed',
					__( 'Unable to fetch translation suggestions at the moment.', 'easy-facebook-likebox' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( 200 !== (int) $code || ! is_array( $data ) || empty( $data ) ) {
				return new WP_Error(
					'esf_translate_invalid_response',
					__( 'Unable to fetch translation suggestions at the moment.', 'easy-facebook-likebox' )
				);
			}

			self::mark_translation_ai_used( $locale );

			return $data;
		}

		/**
		 * Build the REST payload for the React settings page.
		 *
		 * @since 6.9.0
		 * @return array<string,mixed>
		 */
		public static function build_admin_payload() {
			$settings = self::get();

			$locales = array();
			if ( function_exists( 'esf_get_supported_api_locales' ) ) {
				foreach ( esf_get_supported_api_locales() as $code => $label ) {
					$locales[] = array(
						'value' => (string) $code,
						'label' => (string) $label,
					);
				}
			}

			$detected_locale = function_exists( 'esf_get_effective_api_locale' ) ? esf_get_effective_api_locale() : '';
			$locale_label    = '';
			if ( $detected_locale && function_exists( 'efbl_get_locales' ) ) {
				$all_locales = efbl_get_locales();
				if ( isset( $all_locales[ $detected_locale ] ) ) {
					$locale_label = (string) $all_locales[ $detected_locale ];
				}
			}

			$ai_used = isset( $settings['translation_ai_used'] ) && is_array( $settings['translation_ai_used'] )
				? $settings['translation_ai_used']
				: array();

			$can_premium = function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only();
			$autofill_available = $detected_locale
				&& 'en_US' !== $detected_locale
				&& '' !== $locale_label
				&& $can_premium;
			$show_autofill = $autofill_available && empty( $ai_used[ $detected_locale ] );

			$consent_plugin = class_exists( 'ESF_GDPR_Integrations' )
				? ESF_GDPR_Integrations::get_consent_plugin_info()
				: array(
					'name'     => '',
					'detected' => false,
					'message'  => '',
				);

			$supported_plugins = class_exists( 'ESF_GDPR_Integrations' )
				? ESF_GDPR_Integrations::get_supported_plugins_display_list()
				: array();

			$translation_strings = class_exists( 'ESF_Translation_Strings' )
				? ESF_Translation_Strings::get_all_strings()
				: array();

			$translation_values = isset( $settings['translation'] ) && is_array( $settings['translation'] )
				? $settings['translation']
				: array();

			return array(
				'general'     => array(
					'preserve_settings_on_uninstall' => ! empty( $settings['general']['preserve_settings_on_uninstall'] ),
					'api_locale'                     => isset( $settings['general']['api_locale'] ) ? (string) $settings['general']['api_locale'] : '',
				),
				'gdpr'        => isset( $settings['gdpr'] ) ? (string) $settings['gdpr'] : 'auto',
				'gdpr_meta'   => array(
					'consent_plugin'    => $consent_plugin,
					'supported_plugins' => $supported_plugins,
					'mode_descriptions' => array(
						'auto' => __( 'Automatic: Feeds will respect your consent plugin. Features (images, videos, popups, Load More) are limited until visitors give consent, then automatically enabled.', 'easy-facebook-likebox' ),
						'yes'  => __( 'Always Enabled: GDPR restrictions are always on for all visitors. External media and Load More remain disabled, even if visitors give consent.', 'easy-facebook-likebox' ),
						'no'   => __( 'Disabled: GDPR controls are turned off. All images, videos, popups, and Load More will always load normally for visitors.', 'easy-facebook-likebox' ),
					),
					'tooltip'           => array(
						'always_enabled' => array(
							'title' => __( 'If set to "Always Enabled":', 'easy-facebook-likebox' ),
							'text'  => __( 'Prevents all images and videos from being loaded directly from Facebook/Instagram to stop external requests. Some plugin features will be disabled or limited for all visitors.', 'easy-facebook-likebox' ),
						),
						'disabled'       => array(
							'title' => __( 'If set to "Disabled":', 'easy-facebook-likebox' ),
							'text'  => __( 'The plugin will load and display images and videos directly from Facebook/Instagram for everyone, without applying additional GDPR restrictions.', 'easy-facebook-likebox' ),
						),
						'automatic'      => array(
							'title' => __( 'If set to "Automatic":', 'easy-facebook-likebox' ),
							'text'  => __( 'The plugin will only load images and videos directly from Facebook/Instagram after consent has been given by a supported GDPR / cookie consent plugin.', 'easy-facebook-likebox' ),
						),
					),
				),
				'translation' => array(
					'strings'         => $translation_strings,
					'values'          => $translation_values,
					'autofill'        => array(
						'visible'      => $show_autofill,
						'available'    => $autofill_available,
						'locale'       => (string) $detected_locale,
						'locale_label' => (string) $locale_label,
					),
					'can_use_premium' => $can_premium,
				),
				'locales'     => $locales,
				'upgrade'     => self::get_upgrade_payload(),
			);
		}

		/**
		 * Upgrade banner metadata for free-plan users.
		 *
		 * @since 6.9.0
		 * @return array<string,mixed>
		 */
		private static function get_upgrade_payload() {
			$is_free     = function_exists( 'efl_fs' ) && efl_fs()->is_free_plan();
			$upgrade_url = esf_get_upgrade_url( 'general' );
			$promo       = function_exists( 'esf_get_pro_promo_offer' )
				? esf_get_pro_promo_offer()
				: array(
					'discount' => '17%',
					'coupon'   => 'ESPF17',
				);

			return array(
				'visible'     => $is_free,
				'discount'    => isset( $promo['discount'] ) ? (string) $promo['discount'] : '17%',
				'coupon'      => isset( $promo['coupon'] ) ? (string) $promo['coupon'] : 'ESPF17',
				'button_text' => __( 'Upgrade Now', 'easy-facebook-likebox' ),
				'button_url'  => esc_url_raw( (string) $upgrade_url ),
				'target'      => '_blank',
			);
		}

		/**
		 * Persist a module activation status (modern + legacy sync).
		 *
		 * @since 6.9.0
		 *
		 * @param string $slug   Module slug.
		 * @param string $status `activated` or `deactivated`.
		 * @return bool
		 */
		public static function set_module_status( $slug, $status ) {
			$slug   = sanitize_key( (string) $slug );
			$status = sanitize_key( (string) $status );

			if ( '' === $slug || ! in_array( $status, array( 'activated', 'deactivated' ), true ) ) {
				return false;
			}

			$settings = self::get();
			if ( ! isset( $settings['modules'][ $slug ] ) || ! is_array( $settings['modules'][ $slug ] ) ) {
				$settings['modules'][ $slug ] = array();
			}
			$settings['modules'][ $slug ]['status'] = $status;

			return self::save( $settings );
		}

		/**
		 * Legacy-compatible read used by {@see Feed_Them_All::fta_get_settings()}.
		 *
		 * @since 6.9.0
		 * @return array<string,mixed>
		 */
		public static function get_legacy_compatible_array() {
			self::maybe_migrate();

			$legacy = get_option( self::LEGACY_OPTION_KEY, array() );
			if ( ! is_array( $legacy ) ) {
				$legacy = array();
			}

			$modern = self::get();
			self::sync_legacy_from_modern( $modern, $legacy );

			return $legacy;
		}

		/**
		 * Mirror modern global/module fields into `fta_settings` without removing
		 * legacy Facebook/Instagram plugin payloads.
		 *
		 * @since 6.9.0
		 *
		 * @param array<string,mixed>      $modern Modern settings.
		 * @param array<string,mixed>|null $legacy Optional legacy array to mutate.
		 * @return void
		 */
		private static function sync_legacy_from_modern( array $modern, $legacy = null ) {
			if ( null === $legacy ) {
				$legacy = get_option( self::LEGACY_OPTION_KEY, array() );
			}
			if ( ! is_array( $legacy ) ) {
				$legacy = array();
			}

			if ( isset( $modern['version'] ) ) {
				$legacy['version'] = (string) $modern['version'];
			}
			if ( isset( $modern['install_date'] ) ) {
				$legacy['installDate'] = (string) $modern['install_date'];
			}

			if ( isset( $modern['general'] ) && is_array( $modern['general'] ) ) {
				if ( array_key_exists( 'preserve_settings_on_uninstall', $modern['general'] ) ) {
					$legacy['preserve_settings_on_uninstall'] = (int) (bool) $modern['general']['preserve_settings_on_uninstall'];
				}
				if ( array_key_exists( 'api_locale', $modern['general'] ) ) {
					$legacy['api_locale'] = (string) $modern['general']['api_locale'];
				}
			}

			if ( isset( $modern['gdpr'] ) ) {
				$legacy['gdpr'] = (string) $modern['gdpr'];
			}
			if ( isset( $modern['translation'] ) && is_array( $modern['translation'] ) ) {
				$legacy['translation'] = $modern['translation'];
			}
			if ( isset( $modern['translation_ai_used'] ) && is_array( $modern['translation_ai_used'] ) ) {
				$legacy['translation_ai_used'] = $modern['translation_ai_used'];
			}
			if ( isset( $modern['welcome'] ) && is_array( $modern['welcome'] ) ) {
				$legacy['welcome'] = $modern['welcome'];
			}

			if ( ! isset( $legacy['plugins'] ) || ! is_array( $legacy['plugins'] ) ) {
				$legacy['plugins'] = array();
			}

			if ( isset( $modern['modules'] ) && is_array( $modern['modules'] ) ) {
				foreach ( $modern['modules'] as $slug => $module ) {
					$slug = sanitize_key( (string) $slug );
					if ( '' === $slug || ! is_array( $module ) ) {
						continue;
					}
					if ( ! isset( $legacy['plugins'][ $slug ] ) || ! is_array( $legacy['plugins'][ $slug ] ) ) {
						$legacy['plugins'][ $slug ] = array();
					}
					if ( isset( $module['status'] ) ) {
						$legacy['plugins'][ $slug ]['status'] = (string) $module['status'];
					}
				}
			}

			update_option( self::LEGACY_OPTION_KEY, $legacy );
		}
	}
}
