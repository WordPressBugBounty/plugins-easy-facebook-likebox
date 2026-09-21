<?php
/**
 * GDPR Integrations Class
 *
 * Shared class for all modules (Facebook, Instagram, Twitter/X, YouTube,
 * TikTok). Handles consent-plugin detection, GDPR mode, and the
 * fail-closed zero-third-party-request mode (competitive gap P1-3).
 *
 * @package EasySocialFeed
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ESF_GDPR_Integrations {

	/**
	 * Cache for detected plugin (avoid multiple checks)
	 *
	 * @var string|false
	 */
	private static $detected_plugin = null;

	/**
	 * Whether the layouts wrapper filter has been registered.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Register shared layout filters used by every modern module renderer.
	 *
	 * @since 6.9.5
	 * @return void
	 */
	public static function init() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_filter( 'esf_layouts_render_wrapper_attributes', array( __CLASS__, 'filter_layout_wrapper_attributes' ), 10, 2 );
		add_filter( 'esf_layouts_render_layout_html', array( __CLASS__, 'filter_layout_html_admin_notice' ), 10, 2 );
	}

	/**
	 * Resolve the settings array used for GDPR decisions.
	 *
	 * Prefers modern `ESF_Settings` storage; falls back to legacy FTA.
	 *
	 * @since 6.9.5
	 *
	 * @param array<string,mixed>|null $settings Optional preloaded settings.
	 * @return array<string,mixed>
	 */
	public static function get_settings_array( $settings = null ) {
		if ( is_array( $settings ) ) {
			return $settings;
		}

		if ( class_exists( 'ESF_Settings' ) ) {
			try {
				return ESF_Settings::get();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Unit tests may not stub get_option.
				// Fall through to legacy / empty defaults.
			}
		}

		if ( class_exists( 'Feed_Them_All' ) ) {
			try {
				$fta = new Feed_Them_All();
				$raw = $fta->fta_get_settings();
				return is_array( $raw ) ? $raw : array();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through.
			}
		}

		return array(
			'gdpr'                  => 'no',
			'gdpr_zero_third_party' => 0,
		);
	}

	/**
	 * Whether fail-closed zero-third-party mode is enabled.
	 *
	 * When active, browser HTML must never emit remote CDN/embed URLs —
	 * only local media or placeholders. Consent cannot unlock remotes.
	 *
	 * @since 6.9.5
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return bool
	 */
	public static function is_zero_third_party_active( $settings = null ) {
		$settings = self::get_settings_array( $settings );
		return ! empty( $settings['gdpr_zero_third_party'] );
	}

	/**
	 * Whether third-party embeds (YouTube/TikTok iframes, remote video) may load.
	 *
	 * @since 6.9.5
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return bool
	 */
	public static function allows_third_party_embeds( $settings = null ) {
		$settings = self::get_settings_array( $settings );

		if ( self::is_zero_third_party_active( $settings ) ) {
			return false;
		}

		if ( ! self::is_gdpr_active( $settings ) ) {
			return true;
		}

		$mode = isset( $settings['gdpr'] ) ? (string) $settings['gdpr'] : 'auto';
		if ( 'yes' === $mode ) {
			return false;
		}
		if ( 'auto' === $mode ) {
			return self::has_marketing_consent_server();
		}

		return true;
	}

	/**
	 * Whether remote (non-local) media should be blocked from the HTML output.
	 *
	 * @since 6.9.5
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return bool
	 */
	public static function should_block_remote_media( $settings = null ) {
		$settings = self::get_settings_array( $settings );

		if ( self::is_zero_third_party_active( $settings ) ) {
			return true;
		}

		if ( ! self::is_gdpr_active( $settings ) ) {
			return false;
		}

		$mode = isset( $settings['gdpr'] ) ? (string) $settings['gdpr'] : 'auto';
		if ( 'no' === $mode ) {
			return false;
		}
		if ( 'yes' === $mode ) {
			return true;
		}

		// auto: block until marketing consent is present.
		return ! self::has_marketing_consent_server();
	}

	/**
	 * Build data-esf-flags tokens for a feed wrapper.
	 *
	 * @since 6.9.5
	 *
	 * @param string                   $module   Module slug.
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return string[]
	 */
	public static function get_feed_flags( $module = '', $settings = null ) {
		$settings = self::get_settings_array( $settings );
		$flags    = array();
		$mode     = isset( $settings['gdpr'] ) ? (string) $settings['gdpr'] : 'auto';

		if ( self::is_zero_third_party_active( $settings ) ) {
			$flags[] = 'gdpr';
			$flags[] = 'gdpr_zero_third_party';
		} elseif ( self::is_gdpr_active( $settings ) ) {
			$flags[] = 'gdpr';
			if ( 'yes' === $mode ) {
				$flags[] = 'gdpr_yes';
			} elseif ( 'auto' === $mode ) {
				$flags[] = 'gdpr_auto';
			}
		}

		/**
		 * Filter GDPR/zero-TP data-esf-flags for a feed wrapper.
		 *
		 * @since 6.9.5
		 *
		 * @param string[]               $flags    Flag tokens.
		 * @param string                 $module   Module slug.
		 * @param array<string,mixed>    $settings Settings array.
		 */
		return (array) apply_filters( 'esf_gdpr_feed_flags', $flags, sanitize_key( (string) $module ), $settings );
	}

	/**
	 * Prepare an image URL for public HTML output.
	 *
	 * Local uploads always pass through. Remote URLs are replaced with a
	 * placeholder when zero-third-party or consent-GDPR blocks them. Consent
	 * mode may attach `data-image-url` for JS unlock; zero-TP never does.
	 *
	 * @since 6.9.5
	 *
	 * @param string $url    Candidate media URL.
	 * @param string $module Module slug (for local-media path detection).
	 * @return array{src:string,class:string,attrs:string,is_restricted:bool}
	 */
	public static function prepare_img_src( $url, $module = '' ) {
		$url    = is_string( $url ) ? trim( $url ) : '';
		$module = sanitize_key( (string) $module );
		$result = array(
			'src'           => $url,
			'class'         => '',
			'attrs'         => '',
			'is_restricted' => false,
		);

		if ( '' === $url ) {
			return $result;
		}

		if ( self::is_local_media_url( $url, $module ) ) {
			return $result;
		}

		$settings = self::get_settings_array();
		if ( ! self::should_block_remote_media( $settings ) ) {
			return $result;
		}

		$result['src']           = self::get_placeholder_image();
		$result['class']         = 'esf-no-consent';
		$result['is_restricted'] = true;

		// Fail closed for zero-TP: never emit the remote URL in HTML.
		if ( self::is_zero_third_party_active( $settings ) ) {
			return $result;
		}

		$mode = isset( $settings['gdpr'] ) ? (string) $settings['gdpr'] : 'auto';
		// Always-enabled consent mode also omits data-image-url (JS never unlocks).
		if ( 'yes' !== $mode ) {
			$result['attrs'] = ' data-image-url="' . esc_url( $url ) . '"';
		}

		return $result;
	}

	/**
	 * Prefer a local URL; otherwise apply GDPR/zero-TP rules to the remote.
	 *
	 * Convenience for PostMappers that resolve `local ?: remote`.
	 *
	 * @since 6.9.5
	 *
	 * @param string $local_url  Local uploads URL (may be empty).
	 * @param string $remote_url Remote CDN URL (may be empty).
	 * @param string $module     Module slug.
	 * @return string Display URL (local, remote, or placeholder).
	 */
	public static function resolve_display_url( $local_url, $remote_url, $module = '' ) {
		$local_url  = is_string( $local_url ) ? trim( $local_url ) : '';
		$remote_url = is_string( $remote_url ) ? trim( $remote_url ) : '';

		if ( '' !== $local_url && self::is_local_media_url( $local_url, $module ) ) {
			return $local_url;
		}

		$candidate = '' !== $local_url ? $local_url : $remote_url;
		$prepared  = self::prepare_img_src( $candidate, $module );

		return isset( $prepared['src'] ) ? (string) $prepared['src'] : '';
	}

	/**
	 * Whether a URL points at plugin-local media (uploads), not a CDN.
	 *
	 * @since 6.9.5
	 *
	 * @param string $url    URL to check.
	 * @param string $module Module slug.
	 * @return bool
	 */
	public static function is_local_media_url( $url, $module = '' ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url ) {
			return false;
		}

		if ( 0 === strpos( $url, 'data:' ) ) {
			return true;
		}

		if ( function_exists( 'esf_is_local_media_url' ) && function_exists( 'wp_upload_dir' ) ) {
			if ( '' !== $module && esf_is_local_media_url( $url, $module ) ) {
				return true;
			}
			// Some helpers require a module; also accept any esf-* uploads path.
			$modules = array( 'facebook', 'instagram', 'twitter', 'youtube', 'tiktok' );
			foreach ( $modules as $slug ) {
				if ( esf_is_local_media_url( $url, $slug ) ) {
					return true;
				}
			}
		}

		return (bool) preg_match( '#/uploads/esf-[a-z0-9_-]+/#i', $url );
	}

	/**
	 * Filter shared layout wrapper attributes to emit GDPR flags.
	 *
	 * @since 6.9.5
	 *
	 * @param array<string,string> $attrs  Existing attributes.
	 * @param string               $module Module slug.
	 * @return array<string,string>
	 */
	public static function filter_layout_wrapper_attributes( $attrs, $module = '' ) {
		if ( ! is_array( $attrs ) ) {
			$attrs = array();
		}

		$module = sanitize_key( (string) $module );
		$flags  = self::get_feed_flags( $module );
		if ( ! empty( $flags ) ) {
			$attrs['data-esf-flags']  = implode( ',', $flags );
			$attrs['data-esf-module'] = $module;
		}

		if ( ! self::allows_third_party_embeds() ) {
			$attrs['data-esf-block-embeds'] = '1';
		}

		return $attrs;
	}

	/**
	 * Blank remote media URLs inside a lightbox/popup payload (fail-closed).
	 *
	 * Keeps local uploads; clears CDN/embed fields when remote media is blocked
	 * so the HTML data attribute never ships third-party URLs.
	 *
	 * @since 6.9.5
	 *
	 * @param array<string,mixed> $payload Popup payload.
	 * @param string              $module  Module slug.
	 * @return array<string,mixed>
	 */
	public static function sanitize_popup_payload( array $payload, $module = '' ) {
		$module = sanitize_key( (string) $module );
		if ( ! self::should_block_remote_media() ) {
			return $payload;
		}

		$placeholder = self::get_placeholder_image();

		// Display images → placeholder (keeps lightbox/comments UI mountable).
		// Embeds / playable remote video → hard-clear (would be third-party requests).
		$display_keys = array(
			'author_avatar',
			'channel_thumbnail',
			'cover_url',
			'thumbnail_url',
			'poster_url',
		);
		$clear_keys   = array(
			'embed_url',
			'embed_link',
		);

		foreach ( $display_keys as $key ) {
			if ( ! isset( $payload[ $key ] ) || ! is_string( $payload[ $key ] ) || '' === $payload[ $key ] ) {
				continue;
			}
			if ( ! self::is_local_media_url( $payload[ $key ], $module ) ) {
				$payload[ $key ] = $placeholder;
			}
		}

		foreach ( $clear_keys as $key ) {
			if ( ! isset( $payload[ $key ] ) || ! is_string( $payload[ $key ] ) ) {
				continue;
			}
			if ( ! self::is_local_media_url( $payload[ $key ], $module ) ) {
				$payload[ $key ] = '';
			}
		}

		if ( isset( $payload['media'] ) && is_array( $payload['media'] ) ) {
			foreach ( $payload['media'] as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				foreach ( array( 'src', 'url', 'preview_url', 'poster' ) as $media_key ) {
					if ( ! isset( $item[ $media_key ] ) || ! is_string( $item[ $media_key ] ) || '' === $item[ $media_key ] ) {
						continue;
					}
					if ( ! self::is_local_media_url( $item[ $media_key ], $module ) ) {
						$item[ $media_key ] = $placeholder;
					}
				}
				// Remote video playback is a third-party request — never keep CDN video URLs.
				if ( isset( $item['video_url'] ) && is_string( $item['video_url'] ) && '' !== $item['video_url'] ) {
					if ( ! self::is_local_media_url( $item['video_url'], $module ) ) {
						$item['video_url'] = '';
					}
				}
				// Facebook video plugin embeds are always remote facebook.com iframes.
				if ( isset( $item['video_embed_url'] ) && is_string( $item['video_embed_url'] ) && '' !== $item['video_embed_url'] ) {
					$item['video_embed_url'] = '';
				}
				$payload['media'][ $index ] = $item;
			}
		}

		// YouTube embeds are driven by video_id → clear when embeds are blocked.
		if ( ! self::allows_third_party_embeds() && isset( $payload['video_id'] ) ) {
			$payload['video_id'] = '';
		}

		/**
		 * Filter a sanitized popup payload under GDPR / zero-TP.
		 *
		 * @since 6.9.5
		 *
		 * @param array<string,mixed> $payload Payload.
		 * @param string              $module  Module slug.
		 */
		return (array) apply_filters( 'esf_gdpr_sanitize_popup_payload', $payload, $module );
	}

	/**
	 * Enqueue the shared consent/unlock script when GDPR or zero-TP flags are active.
	 *
	 * Consent auto mode needs JS to unlock after the visitor accepts cookies.
	 * Zero-TP / always-enabled modes still enqueue so Load More stay hidden and
	 * the script treats `gdpr_zero_third_party` / `gdpr_yes` as never-unlock.
	 *
	 * @since 6.9.5
	 * @return void
	 */
	public static function maybe_enqueue_consent_script() {
		$settings = self::get_settings_array();
		if ( ! self::is_gdpr_active( $settings ) && ! self::is_zero_third_party_active( $settings ) ) {
			return;
		}

		$handle = 'esf-gdpr-consent';
		if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'done' ) ) {
			return;
		}

		$src = defined( 'FTA_PLUGIN_URL' )
			? FTA_PLUGIN_URL . 'frontend/assets/js/esf-gdpr-consent.js'
			: '';
		if ( '' === $src ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			$src,
			array( 'jquery' ),
			defined( 'FTA_VERSION' ) ? FTA_VERSION : '6.9.5',
			true
		);
	}

	/**
	 * Whether or not consent plugins that Easy Social Feed
	 * is compatible with are active.
	 *
	 * @return bool|string Returns plugin name if active, false otherwise
	 * @since 6.8.0
	 */
	public static function gdpr_plugins_active() {
		// Return cached result if available
		if ( self::$detected_plugin !== null ) {
			return self::$detected_plugin;
		}

		// WPConsent by the WPConsent team
		if ( function_exists( 'WPConsent' ) ) {
			self::$detected_plugin = 'WPConsent by the WPConsent team';
			return self::$detected_plugin;
		}
		
		// Real Cookie Banner by devowl.io
		if ( defined( 'RCB_ROOT_SLUG' ) ) {
			self::$detected_plugin = 'Real Cookie Banner by devowl.io';
			return self::$detected_plugin;
		}
		
		// GDPR Cookie Compliance by Moove Agency
		if ( function_exists( 'gdpr_cookie_is_accepted' ) ) {
			self::$detected_plugin = 'GDPR Cookie Compliance by Moove Agency';
			return self::$detected_plugin;
		}
		
		// Cookie Notice by dFactory
		if ( class_exists( 'Cookie_Notice' ) ) {
			self::$detected_plugin = 'Cookie Notice by dFactory';
			return self::$detected_plugin;
		}
		
		// GDPR Cookie Consent by WebToffee
		if ( function_exists( 'run_cookie_law_info' ) || class_exists( 'Cookie_Law_Info' ) ) {
			self::$detected_plugin = 'GDPR Cookie Consent by WebToffee';
			return self::$detected_plugin;
		}
		
		// CookieYes | GDPR Cookie Consent by CookieYes
		if ( defined( 'CKY_APP_ASSETS_URL' ) ) {
			self::$detected_plugin = 'CookieYes | GDPR Cookie Consent by CookieYes';
			return self::$detected_plugin;
		}
		
		// Cookiebot by Cybot A/S
		if ( class_exists( 'Cookiebot_WP' ) ) {
			self::$detected_plugin = 'Cookiebot by Cybot A/S';
			return self::$detected_plugin;
		}
		
		// Complianz by Really Simple Plugins
		if ( class_exists( 'COMPLIANZ' ) ) {
			self::$detected_plugin = 'Complianz by Really Simple Plugins';
			return self::$detected_plugin;
		}
		
		// Borlabs Cookie by Borlabs
		if ( function_exists( 'BorlabsCookieHelper' ) || 
		     ( defined( 'BORLABS_COOKIE_VERSION' ) && version_compare( BORLABS_COOKIE_VERSION, '3.0', '>=' ) ) ) {
			self::$detected_plugin = 'Borlabs Cookie by Borlabs';
			return self::$detected_plugin;
		}
		
		self::$detected_plugin = false;
		return false;
	}

	/**
	 * Determine if GDPR mode should be active (applies to all modules).
	 *
	 * GDPR can be automatic, forced enabled, or forced disabled globally.
	 * Zero-third-party mode also forces GDPR-active behaviour.
	 *
	 * @param array|null $settings Full settings array, or null to load automatically.
	 * @return bool Whether GDPR mode should be active
	 * @since 6.8.0
	 */
	public static function is_gdpr_active( $settings = null ) {
		$settings = self::get_settings_array( $settings );

		if ( self::is_zero_third_party_active( $settings ) ) {
			return true;
		}

		$gdpr_setting = isset( $settings['gdpr'] ) ? $settings['gdpr'] : 'auto';

		if ( $gdpr_setting === 'no' ) {
			return false;
		}
		if ( $gdpr_setting === 'yes' ) {
			return true;
		}
		return ( self::gdpr_plugins_active() !== false );
	}

	/**
	 * Get placeholder image URL
	 * 
	 * @return string Placeholder image URL
	 * @since 6.8.0
	 */
	public static function get_placeholder_image() {
		// Check if placeholder exists in Facebook module (shared asset)
		$placeholder_path = FTA_PLUGIN_DIR . 'facebook/frontend/assets/images/feed-placeholder-img.png';
		
		if ( file_exists( $placeholder_path ) ) {
			return FTA_PLUGIN_URL . 'facebook/frontend/assets/images/feed-placeholder-img.png';
		}
		
		// Fallback: 1x1 transparent PNG (data URI)
		return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
	}

	/**
	 * Whether permanent remote-media / embed restrictions are active.
	 *
	 * Zero-TP and GDPR "Always enabled" never unlock via consent — those are
	 * the modes where editors need an explicit "not broken" explanation.
	 *
	 * @since 6.9.5
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return bool
	 */
	public static function has_permanent_media_restriction( $settings = null ) {
		$settings = self::get_settings_array( $settings );
		if ( self::is_zero_third_party_active( $settings ) ) {
			return true;
		}
		if ( ! self::is_gdpr_active( $settings ) ) {
			return false;
		}
		$mode = isset( $settings['gdpr'] ) ? (string) $settings['gdpr'] : 'auto';
		return 'yes' === $mode;
	}

	/**
	 * Whether the current user should see GDPR/zero-TP admin diagnostics.
	 *
	 * @since 6.9.5
	 * @return bool
	 */
	public static function current_user_can_see_admin_notice() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! function_exists( 'current_user_can' ) ) {
			return false;
		}
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * Localize strings for lightbox admin media-blocked notices.
	 *
	 * Empty `adminMediaBlockedText` for public visitors so the notice never
	 * appears for them.
	 *
	 * @since 6.9.5
	 * @return array{adminMediaBlockedText:string,adminsOnlyText:string}
	 */
	public static function get_lightbox_admin_localize_data() {
		$message = '';
		if ( self::current_user_can_see_admin_notice() ) {
			$message = self::get_restricted_media_admin_message();
		}

		return array(
			'adminMediaBlockedText' => $message,
			'adminsOnlyText'        => __( 'Admins only', 'easy-facebook-likebox' ),
		);
	}

	/**
	 * Plain-text explanation for admins when remote video/embeds are blocked.
	 *
	 * @since 6.9.5
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return string Empty when no permanent restriction applies.
	 */
	public static function get_restricted_media_admin_message( $settings = null ) {
		$settings = self::get_settings_array( $settings );
		if ( ! self::has_permanent_media_restriction( $settings ) ) {
			return '';
		}

		if ( self::is_zero_third_party_active( $settings ) ) {
			return __(
				'Zero third-party requests is enabled. Remote videos and embeds will not play unless the file is already stored as local media — placeholders are expected, not a broken feed. Change this under Easy Social Feed → Settings → GDPR.',
				'easy-facebook-likebox'
			);
		}

		return __(
			'GDPR is set to Always enabled. Remote videos and embeds stay blocked for everyone. Enable local media, or change the GDPR setting under Easy Social Feed → Settings → GDPR.',
			'easy-facebook-likebox'
		);
	}

	/**
	 * Whether admin-notice CSS has been inlined this request.
	 *
	 * @var bool
	 */
	private static $admin_notice_css_printed = false;

	/**
	 * Admin-only banner explaining why remote videos/embeds are blocked.
	 *
	 * Visible to users who can edit posts (frontend + dashboard preview).
	 * Public visitors never see this markup.
	 *
	 * @since 6.9.5
	 *
	 * @param string                   $module   Module slug.
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return string HTML notice, or empty string.
	 */
	public static function get_restricted_media_admin_notice_html( $module = '', $settings = null ) {
		if ( ! self::current_user_can_see_admin_notice() ) {
			return '';
		}

		$message = self::get_restricted_media_admin_message( $settings );
		if ( '' === $message ) {
			return '';
		}

		$settings_url = '';
		if ( function_exists( 'admin_url' ) ) {
			$settings_url = admin_url( 'admin.php?page=esf-settings&tab=gdpr' );
		}

		return self::render_admin_notice_html(
			$message,
			$module,
			$settings_url,
			__( 'Open GDPR settings', 'easy-facebook-likebox' )
		);
	}

	/**
	 * Render a reusable admin-only notice banner (shared styling with GDPR notices).
	 *
	 * @since 6.7.9
	 *
	 * @param string $message   Notice body copy.
	 * @param string $module    Module slug for data attribute.
	 * @param string $link_url  Optional action URL.
	 * @param string $link_text Optional action label.
	 * @param string $secondary_link_url  Optional second action URL.
	 * @param string $secondary_link_text Optional second action label.
	 * @param string $variant           Notice variant: `warning` (default) or `success`.
	 * @param int    $pin_feed_id       When set, render a REST pin-cache button instead of secondary link.
	 * @return string HTML notice, or empty when the viewer is not an admin.
	 */
	public static function render_admin_notice_html( $message, $module = '', $link_url = '', $link_text = '', $secondary_link_url = '', $secondary_link_text = '', $variant = 'warning', $pin_feed_id = 0 ) {
		if ( ! self::current_user_can_see_admin_notice() ) {
			return '';
		}

		$message = is_string( $message ) ? trim( $message ) : '';
		if ( '' === $message ) {
			return '';
		}

		self::maybe_enqueue_admin_notice_assets();

		$variant = sanitize_key( (string) $variant );
		if ( ! in_array( $variant, array( 'warning', 'success' ), true ) ) {
			$variant = 'warning';
		}

		$module   = sanitize_key( (string) $module );
		$link_url = is_string( $link_url ) ? trim( $link_url ) : '';
		$link_text = is_string( $link_text ) ? trim( $link_text ) : '';
		$secondary_link_url  = is_string( $secondary_link_url ) ? trim( $secondary_link_url ) : '';
		$secondary_link_text = is_string( $secondary_link_text ) ? trim( $secondary_link_text ) : '';
		$pin_feed_id         = max( 0, (int) $pin_feed_id );
		$css      = '';
		if ( ! self::$admin_notice_css_printed ) {
			self::$admin_notice_css_printed = true;
			// Inline once so dashboard preview (REST HTML) still styles correctly
			// even when wp_enqueue_style does not print on that response.
			$css = '<style id="esf-gdpr-admin-notice-inline">.esf-gdpr-admin-notice{margin:0 0 12px;padding:12px 14px;border:1px solid #c3c4c7;border-left:4px solid #dba617;border-radius:4px;background:#fcf9e8;color:#1d2327;text-align:left;box-sizing:border-box}.esf-gdpr-admin-notice--success{border-left-color:#00a32a;background:#edfaef}.esf-gdpr-admin-notice__eyebrow{margin:0 0 4px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#646970}.esf-gdpr-admin-notice--success .esf-gdpr-admin-notice__eyebrow{color:#007017}.esf-gdpr-admin-notice__text{margin:0;font-size:13px;line-height:1.45}.esf-gdpr-admin-notice__actions{margin:8px 0 0}.esf-gdpr-admin-notice__link{font-size:13px;font-weight:600;color:#135e96;text-decoration:underline}.esf-gdpr-admin-notice__link+.esf-gdpr-admin-notice__link,.esf-gdpr-admin-notice__link+.esf-gdpr-admin-notice__pin-btn{margin-left:12px}.esf-gdpr-admin-notice__pin-btn{display:inline;padding:0;border:0;background:transparent;font:inherit;font-size:13px;font-weight:600;color:#135e96;text-decoration:underline;cursor:pointer}.esf-gdpr-admin-notice__pin-btn:hover:not(:disabled),.esf-gdpr-admin-notice__pin-btn:focus:not(:disabled){color:#0a4b78}.esf-gdpr-admin-notice__pin-btn:disabled{opacity:.65;cursor:wait}.esf-gdpr-admin-notice__error{margin:8px 0 0;font-size:13px;line-height:1.45;color:#b32d2e}.esf-gdpr-admin-notice--popup{margin:8px;position:absolute;left:0;right:0;bottom:0;z-index:2;max-width:calc(100% - 16px);box-shadow:0 2px 8px rgba(0,0,0,.12)}</style>';
		}

		$notice_class = 'esf-gdpr-admin-notice';
		if ( 'success' === $variant ) {
			$notice_class .= ' esf-gdpr-admin-notice--success';
		}
		$eyebrow = 'success' === $variant
			? __( 'Success', 'easy-facebook-likebox' )
			: __( 'Admins only', 'easy-facebook-likebox' );

		ob_start();
		echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS only.
		?>
		<div class="<?php echo esc_attr( $notice_class ); ?>" role="status"<?php echo '' !== $module ? ' data-esf-module="' . esc_attr( $module ) . '"' : ''; ?>>
			<p class="esf-gdpr-admin-notice__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
			<p class="esf-gdpr-admin-notice__text"><?php echo esc_html( $message ); ?></p>
			<?php if ( ( '' !== $link_url && '' !== $link_text ) || ( $pin_feed_id > 0 && '' !== $secondary_link_text ) || ( '' !== $secondary_link_url && '' !== $secondary_link_text ) ) : ?>
				<p class="esf-gdpr-admin-notice__actions">
					<?php if ( '' !== $link_url && '' !== $link_text ) : ?>
						<a class="esf-gdpr-admin-notice__link" href="<?php echo esc_url( $link_url ); ?>">
							<?php echo esc_html( $link_text ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $pin_feed_id > 0 && '' !== $secondary_link_text ) : ?>
						<button
							type="button"
							class="esf-gdpr-admin-notice__pin-btn"
							data-esf-pin-cache="1"
							data-esf-module="<?php echo esc_attr( $module ); ?>"
							data-esf-feed-id="<?php echo esc_attr( (string) $pin_feed_id ); ?>"
						>
							<?php echo esc_html( $secondary_link_text ); ?>
						</button>
					<?php elseif ( '' !== $secondary_link_url && '' !== $secondary_link_text ) : ?>
						<a class="esf-gdpr-admin-notice__link" href="<?php echo esc_url( $secondary_link_url ); ?>">
							<?php echo esc_html( $secondary_link_text ); ?>
						</a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Prepend the admin media-restriction notice inside modern layout HTML.
	 *
	 * @since 6.9.5
	 *
	 * @param string $layout_html Layout inner HTML.
	 * @param string $module      Module slug.
	 * @return string
	 */
	public static function filter_layout_html_admin_notice( $layout_html, $module = '' ) {
		$notice = self::get_restricted_media_admin_notice_html( $module );
		if ( '' === $notice ) {
			return (string) $layout_html;
		}
		return $notice . (string) $layout_html;
	}

	/**
	 * Enqueue styles for the admin GDPR/zero-TP notice.
	 *
	 * @since 6.9.5
	 * @return void
	 */
	public static function maybe_enqueue_admin_notice_assets() {
		$handle = 'esf-gdpr-admin-notice';
		if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'done' ) ) {
			return;
		}

		$src = defined( 'FTA_PLUGIN_URL' )
			? FTA_PLUGIN_URL . 'frontend/assets/css/esf-gdpr-admin-notice.css'
			: '';
		if ( '' === $src ) {
			return;
		}

		$path = defined( 'FTA_PLUGIN_DIR' )
			? FTA_PLUGIN_DIR . 'frontend/assets/css/esf-gdpr-admin-notice.css'
			: '';
		$ver  = ( '' !== $path && file_exists( $path ) ) ? (string) filemtime( $path ) : ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '6.9.5' );

		wp_enqueue_style( $handle, $src, array(), $ver );
	}

	/**
	 * Get GDPR admin notice HTML
	 *
	 * @param string $module Module name
	 * @param string $element_name Name of the element (e.g., 'Feed', 'Like Box')
	 * @return string HTML notice (empty if user is not admin)
	 * @since 6.8.0
	 */
	public static function get_gdpr_notice_html( $module, $element_name = '' ) {
		if ( ! self::current_user_can_see_admin_notice() ) {
			return '';
		}

		// Prefer the clearer zero-TP / always-enabled explanation when applicable.
		$restricted = self::get_restricted_media_admin_notice_html( $module );
		if ( '' !== $restricted ) {
			return $restricted;
		}

		$element_name = ! empty( $element_name ) ? $element_name : ucfirst( $module ) . ' Feed';

		ob_start();
		?>
		<div class="esf-gdpr-notice">
			<i class="fa fa-lock" aria-hidden="true"></i>
			<?php echo esc_html__( 'This notice is visible to admins only.', 'easy-facebook-likebox' ); ?><br/>
			<?php echo esc_html( $element_name ) . ' ' . esc_html__( 'disabled due to GDPR setting.', 'easy-facebook-likebox' ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get information about detected consent plugin
	 *
	 * @return array Plugin info or empty array
	 * @since 6.8.0
	 */
	public static function get_consent_plugin_info() {
		$plugin_name = self::gdpr_plugins_active();
		
		if ( ! $plugin_name ) {
			return array(
				'name' => '',
				'detected' => false,
				'message' => __( 'No GDPR consent plugin detected.', 'easy-facebook-likebox' ),
			);
		}

		return array(
			'name' => $plugin_name,
			'detected' => true,
			'message' => sprintf( __( 'Detected: %s', 'easy-facebook-likebox' ), $plugin_name ),
		);
	}

	/**
	 * Get list of supported GDPR / cookie consent plugin names for display in admin.
	 * WPConsent by the WPConsent team is always at the end of the list.
	 *
	 * @return array List of translatable plugin name strings in display order.
	 * @since 6.8.0
	 */
	public static function get_supported_plugins_display_list() {
		return array(
			__( 'Real Cookie Banner by devowl.io', 'easy-facebook-likebox' ),
			__( 'GDPR Cookie Compliance by Moove Agency', 'easy-facebook-likebox' ),
			__( 'Cookie Notice by dFactory', 'easy-facebook-likebox' ),
			__( 'GDPR Cookie Consent by WebToffee', 'easy-facebook-likebox' ),
			__( 'CookieYes | GDPR Cookie Consent by CookieYes', 'easy-facebook-likebox' ),
			__( 'Cookiebot by Cybot A/S', 'easy-facebook-likebox' ),
			__( 'Complianz by Really Simple Plugins', 'easy-facebook-likebox' ),
			__( 'Borlabs Cookie by Borlabs', 'easy-facebook-likebox' ),
			__( 'WPConsent by the WPConsent team', 'easy-facebook-likebox' ),
		);
	}

	/**
	 * Attempt to determine, on the server side, whether the visitor has
	 * already given consent for marketing / third-party cookies.
	 *
	 * This mirrors (in a simplified way) the checks used in the frontend
	 * JavaScript, but using PHP-accessible cookies. It is primarily used
	 * to avoid a visible "flash" of placeholder images when the page is
	 * reloaded after consent has already been granted.
	 *
	 * Currently supported (server-side) plugins:
	 * - GDPR Cookie Compliance by Moove Agency
	 * - WPConsent / WP Consent API (wp_has_consent or wp_consent_level cookie)
	 *
	 * For unsupported plugins this will safely return false and normal
	 * placeholder behaviour will apply.
	 *
	 * @return bool True if consent appears to have been granted, false otherwise.
	 * @since 6.8.0
	 */
	public static function has_marketing_consent_server() {
		// Moove GDPR Cookie Compliance: cookie "moove_gdpr_popup" (JSON).
		if ( isset( $_COOKIE['moove_gdpr_popup'] ) ) {
			$raw = wp_unslash( $_COOKIE['moove_gdpr_popup'] );

			// The cookie value is URL-encoded JSON.
			$decoded = json_decode( urldecode( $raw ), true );

			if ( is_array( $decoded ) && isset( $decoded['thirdparty'] ) ) {
				// "1" means third-party cookies (including marketing) are allowed.
				if ( (string) $decoded['thirdparty'] === '1' ) {
					return true;
				}
			}
		}

		// WPConsent / WP Consent API: PHP function or wp_consent_level cookie.
		if ( function_exists( 'wp_has_consent' ) && wp_has_consent( 'marketing' ) ) {
			return true;
		}
		if ( isset( $_COOKIE['wp_consent_level'] ) ) {
			$raw = wp_unslash( $_COOKIE['wp_consent_level'] );
			$decoded = json_decode( stripslashes( $raw ), true );
			if ( is_array( $decoded ) && isset( $decoded['marketing'] ) && (string) $decoded['marketing'] === 'allow' ) {
				return true;
			}
			// Some implementations use a simple string per category.
			if ( is_string( $raw ) && strtolower( $raw ) === 'allow' ) {
				return true;
			}
		}

		// Fallback: no positive consent detected on the server.
		return false;
	}
}
