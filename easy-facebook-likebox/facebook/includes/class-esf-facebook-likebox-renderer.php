<?php
/**
 * Facebook Likebox / Page Plugin renderer.
 *
 * Renders the official Facebook Page Plugin iframe for feeds with content_type=likebox,
 * and can append an inline Like Box under a regular feed when show_like_box is on.
 * Does not use LayoutRegistry.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Likebox_Renderer
 *
 * @since 6.7.9
 */
class ESF_Facebook_Likebox_Renderer {

	/**
	 * Default Likebox settings.
	 *
	 * @since 6.7.9
	 * @return array<string,mixed>
	 */
	public static function get_default_settings() {
		$defaults = class_exists( 'ESF_Facebook_Feed_Repository' )
			? ESF_Facebook_Feed_Repository::get_default_settings()
			: array();

		$likebox = isset( $defaults['likebox'] ) && is_array( $defaults['likebox'] )
			? $defaults['likebox']
			: array(
				'width'        => 340,
				'height'       => 214,
				'show_faces'   => true,
				'hide_cover'   => false,
				'small_header' => false,
				'hide_cta'     => false,
				'show_posts'   => false,
				'locale'       => 'en_US',
				'tabs'         => '',
				'responsive'   => true,
				'page_url'     => '',
			);

		return $likebox;
	}

	/**
	 * Whether a URL host is an allowed Facebook / fb.com host.
	 *
	 * @since 6.7.9
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_allowed_facebook_page_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback only when WP is unavailable (never true at runtime).
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		$host = preg_replace( '/^www\./', '', $host );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		return (
			'facebook.com' === $host
			|| 'fb.com' === $host
			|| 'm.facebook.com' === $host
			|| (bool) preg_match( '/\.facebook\.com$/', $host )
		);
	}

	/**
	 * Sanitize a public Page URL for Like Box embeds.
	 *
	 * Non-Facebook hosts are dropped silently (never fatal).
	 *
	 * @since 6.7.9
	 * @param string $url Raw URL.
	 * @return string Sanitized URL or empty string.
	 */
	public static function sanitize_page_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! self::is_allowed_facebook_page_url( $url ) ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$sanitized = esc_url_raw( $url );
		return is_string( $sanitized ) ? $sanitized : '';
	}

	/**
	 * Whether the Page Plugin will show a timeline / tabs stream.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $likebox Settings.
	 * @return bool
	 */
	public static function likebox_has_stream( array $likebox ): bool {
		$tabs = isset( $likebox['tabs'] ) ? trim( (string) $likebox['tabs'] ) : '';
		return '' !== $tabs || ! empty( $likebox['show_posts'] );
	}

	/**
	 * Compact iframe height for header / facepile chrome only.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $likebox Settings.
	 * @return int
	 */
	public static function get_compact_height( array $likebox ): int {
		$small = ! empty( $likebox['small_header'] );
		$faces = ! empty( $likebox['show_faces'] );
		if ( $small && ! $faces ) {
			return 70;
		}
		if ( $small || ! $faces ) {
			return 130;
		}
		return 214;
	}

	/**
	 * Effective Page Plugin iframe height (70–1000).
	 *
	 * Header-only embeds must not keep the legacy 500px default — that leaves
	 * empty white space under the widget. Timeline/tabs keep the taller default.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $likebox Settings.
	 * @return int
	 */
	public static function resolve_iframe_height( array $likebox ): int {
		$raw        = isset( $likebox['height'] ) ? (int) $likebox['height'] : 0;
		$has_stream = self::likebox_has_stream( $likebox );

		if ( $has_stream ) {
			$height = $raw > 0 ? $raw : 500;
			return max( 70, min( 1000, $height ) );
		}

		// Missing or legacy 500 default → compact (header-only).
		if ( $raw <= 0 || 500 === $raw ) {
			return self::get_compact_height( $likebox );
		}

		return max( 70, min( 1000, $raw ) );
	}

	/**
	 * Normalize Likebox settings (dimensions, locale, Pro tabs gating, page_url).
	 *
	 * @since 6.7.9
	 * @param array<string,mixed>|null $settings Raw likebox settings.
	 * @return array<string,mixed>
	 */
	public static function normalize_settings( $settings ) {
		$defaults = self::get_default_settings();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings = array_merge( $defaults, $settings );

		$settings['show_faces']   = ! empty( $settings['show_faces'] );
		$settings['hide_cover']   = ! empty( $settings['hide_cover'] );
		$settings['small_header'] = ! empty( $settings['small_header'] );
		$settings['hide_cta']     = ! empty( $settings['hide_cta'] );
		$settings['show_posts']   = ! empty( $settings['show_posts'] );
		$settings['responsive']   = ! isset( $settings['responsive'] ) || ! empty( $settings['responsive'] );

		$tabs     = isset( $settings['tabs'] ) ? sanitize_text_field( (string) $settings['tabs'] ) : '';
		$has_plan = function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan();
		if ( ! $has_plan ) {
			$tabs = '';
		} else {
			$allowed_tabs = array( 'timeline', 'events', 'messages' );
			$parts        = array_filter( array_map( 'sanitize_key', explode( ',', $tabs ) ) );
			$parts        = array_values( array_intersect( $parts, $allowed_tabs ) );
			$tabs         = implode( ',', $parts );
		}
		$settings['tabs'] = $tabs;

		$width  = isset( $settings['width'] ) ? (int) $settings['width'] : 340;
		$height = isset( $settings['height'] ) ? (int) $settings['height'] : 0;
		// Meta Page Plugin: width 180–500, height ≥ 70.
		$settings['width'] = $width > 0 ? max( 180, min( 500, $width ) ) : 340;
		if ( $height > 0 ) {
			$settings['height'] = max( 70, min( 1000, $height ) );
		} else {
			$settings['height'] = self::resolve_iframe_height( $settings );
		}

		$locale = isset( $settings['locale'] ) ? sanitize_text_field( (string) $settings['locale'] ) : 'en_US';
		if ( ! preg_match( '/^[a-z]{2}_[A-Z]{2}$/', $locale ) ) {
			$locale = function_exists( 'esf_get_effective_api_locale' )
				? esf_get_effective_api_locale()
				: 'en_US';
		}
		$settings['locale'] = $locale;

		$app_id = isset( $settings['app_id'] ) ? sanitize_text_field( (string) $settings['app_id'] ) : '';
		if ( '' === $app_id ) {
			$app_id = '395202813876688';
		}
		$settings['app_id'] = $app_id;

		$page_url             = isset( $settings['page_url'] ) ? (string) $settings['page_url'] : '';
		$settings['page_url'] = self::sanitize_page_url( $page_url );

		return $settings;
	}

	/**
	 * Render a likebox feed by ID.
	 *
	 * @since 6.7.9
	 * @param int   $feed_id           Feed ID.
	 * @param array $settings_override Optional settings merge.
	 * @return string
	 */
	public function render( $feed_id, $settings_override = array() ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
			return '';
		}

		$feed_row = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return '';
		}

		$content_type = isset( $feed_row->content_type ) ? sanitize_key( (string) $feed_row->content_type ) : '';
		if ( 'likebox' !== $content_type ) {
			return '';
		}

		$status = isset( $feed_row->status ) ? sanitize_key( (string) $feed_row->status ) : 'active';
		if ( 'active' !== $status ) {
			$can_manage = function_exists( 'esf_facebook_user_can_manage' ) && esf_facebook_user_can_manage();
			if ( ! $can_manage ) {
				return '';
			}
		}

		$settings = isset( $feed_row->settings ) && is_array( $feed_row->settings ) ? $feed_row->settings : array();
		if ( ! empty( $settings_override ) && is_array( $settings_override ) ) {
			$settings = array_replace_recursive( $settings, $settings_override );
		}

		$likebox = isset( $settings['likebox'] ) && is_array( $settings['likebox'] ) ? $settings['likebox'] : array();
		$likebox = self::normalize_settings( $likebox );

		$page_href = $this->resolve_page_href( $feed_row, $likebox );
		if ( '' === $page_href ) {
			return '';
		}

		return $this->build_markup( $feed_id, $page_href, $likebox );
	}

	/**
	 * Render an inline Like Box for a connected page (append-below-feed use case).
	 *
	 * Does not require the parent feed's content_type to be likebox.
	 *
	 * @since 6.7.9
	 * @param int                 $page_row_id      Internal esf_facebook_pages.id.
	 * @param array<string,mixed> $likebox_settings Likebox settings blob.
	 * @param int                 $feed_id          Optional parent feed id for markup data attribute.
	 * @return string
	 */
	public function render_inline_for_page( $page_row_id, array $likebox_settings = array(), $feed_id = 0 ) {
		$page_row_id = (int) $page_row_id;
		$feed_id     = (int) $feed_id;
		$likebox     = self::normalize_settings( $likebox_settings );

		$page_href = '';
		if ( ! empty( $likebox['page_url'] ) ) {
			$page_href = (string) $likebox['page_url'];
		} elseif ( $page_row_id > 0 && class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			$page = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_row_id );
			if ( $page ) {
				$page_href = self::page_row_to_href( $page );
			}
		}

		if ( '' === $page_href ) {
			return '';
		}

		$wrapper_id = $feed_id > 0 ? $feed_id : $page_row_id;
		return '<div class="esf-facebook-likebox-append">'
			. $this->build_markup( $wrapper_id, $page_href, $likebox )
			. '</div>';
	}

	/**
	 * Map legacy shortcode / widget attributes to normalized Like Box settings.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $atts Legacy `[efb_likebox]` / widget instance attrs.
	 * @return array<string,mixed>
	 */
	public static function map_legacy_atts_to_settings( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();

		$width  = isset( $atts['box_width'] ) ? (int) $atts['box_width'] : ( isset( $atts['width'] ) ? (int) $atts['width'] : 0 );
		$height = isset( $atts['box_height'] ) ? (int) $atts['box_height'] : ( isset( $atts['height'] ) ? (int) $atts['height'] : 0 );

		$locale = isset( $atts['locale'] ) ? (string) $atts['locale'] : 'en_US';
		if ( ! empty( $atts['locale_other'] ) ) {
			$locale = (string) $atts['locale_other'];
		}

		$tabs = isset( $atts['tabs'] ) ? (string) $atts['tabs'] : '';
		if ( '' === $tabs ) {
			$tab_parts = array();
			if ( ! empty( $atts['efbl_tabs_timeline'] ) ) {
				$tab_parts[] = 'timeline';
			}
			if ( ! empty( $atts['efbl_tabs_events'] ) ) {
				$tab_parts[] = 'events';
			}
			if ( ! empty( $atts['efbl_tabs_messages'] ) ) {
				$tab_parts[] = 'messages';
			}
			$tabs = implode( ',', $tab_parts );
		}

		$show_stream = ! empty( $atts['show_stream'] ) || ! empty( $atts['show_posts'] );
		$show_faces  = ! empty( $atts['show_faces'] );

		return self::normalize_settings(
			array(
				'width'        => $width > 0 ? $width : 340,
				'height'       => $height > 0 ? $height : 0,
				'show_faces'   => $show_faces,
				'hide_cover'   => ! empty( $atts['hide_cover'] ),
				'small_header' => ! empty( $atts['small_header'] ),
				'hide_cta'     => ! empty( $atts['hide_cta'] ),
				'show_posts'   => $show_stream,
				'locale'       => $locale,
				'tabs'         => $tabs,
				'responsive'   => ! isset( $atts['responsive'] ) || ! empty( $atts['responsive'] ),
				'app_id'       => isset( $atts['fb_appid'] ) ? (string) $atts['fb_appid'] : ( isset( $atts['app_id'] ) ? (string) $atts['app_id'] : '' ),
				'page_url'     => isset( $atts['page_url'] ) ? (string) $atts['page_url'] : '',
			)
		);
	}

	/**
	 * Resolve a Facebook Page URL from legacy shortcode / widget attributes.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $atts Legacy attributes.
	 * @return string
	 */
	public static function resolve_page_href_from_atts( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();

		if ( ! empty( $atts['page_url'] ) ) {
			$url = self::sanitize_page_url( (string) $atts['page_url'] );
			if ( '' !== $url ) {
				return $url;
			}
		}

		$fanpage = '';
		if ( ! empty( $atts['fanpage_url'] ) ) {
			$fanpage = trim( (string) $atts['fanpage_url'] );
		} elseif ( ! empty( $atts['fanpage_id'] ) ) {
			$fanpage = trim( (string) $atts['fanpage_id'] );
		}

		if ( '' === $fanpage ) {
			return '';
		}

		if ( self::is_allowed_facebook_page_url( $fanpage ) ) {
			return self::sanitize_page_url( $fanpage );
		}

		return 'https://www.facebook.com/' . rawurlencode( $fanpage );
	}

	/**
	 * Render Like Box markup from legacy shortcode attributes (no feed row).
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_from_legacy_atts( $atts ) {
		$atts      = is_array( $atts ) ? $atts : array();
		$page_href = self::resolve_page_href_from_atts( $atts );
		if ( '' === $page_href ) {
			return '';
		}

		$likebox = self::map_legacy_atts_to_settings( $atts );

		return $this->build_markup( 0, $page_href, $likebox );
	}

	/**
	 * Render Like Box markup from a Page URL and modern settings blob.
	 *
	 * @since 6.7.9
	 * @param string              $page_href        Facebook Page URL.
	 * @param array<string,mixed> $likebox_settings Settings (normalized internally).
	 * @param int                 $wrapper_id       Optional wrapper id for data-feed-id.
	 * @return string
	 */
	public function render_for_page_href( $page_href, array $likebox_settings = array(), $wrapper_id = 0 ) {
		$page_href = self::sanitize_page_url( (string) $page_href );
		if ( '' === $page_href ) {
			return '';
		}

		$likebox = self::normalize_settings( $likebox_settings );

		return $this->build_markup( (int) $wrapper_id, $page_href, $likebox );
	}

	/**
	 * Resolve Facebook page URL/ID for the embed.
	 *
	 * @param object              $feed_row Feed row.
	 * @param array<string,mixed> $likebox  Normalized settings.
	 * @return string
	 */
	private function resolve_page_href( $feed_row, array $likebox ) {
		if ( ! empty( $likebox['page_url'] ) ) {
			$url = (string) $likebox['page_url'];
			if ( '' !== $url ) {
				return $url;
			}
		}

		$page_row_id = isset( $feed_row->page_id ) ? (int) $feed_row->page_id : 0;
		if ( $page_row_id > 0 && class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			$page = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_row_id );
			if ( $page ) {
				return self::page_row_to_href( $page );
			}
		}

		return '';
	}

	/**
	 * Prefer stored vanity link / username over numeric page_id for embeds.
	 *
	 * @since 6.7.9
	 * @param object $page Page row.
	 * @return string
	 */
	public static function page_row_to_href( $page ) {
		if ( ! is_object( $page ) ) {
			return '';
		}
		if ( ! empty( $page->page_link ) ) {
			$link = self::sanitize_page_url( (string) $page->page_link );
			if ( '' !== $link ) {
				return $link;
			}
		}
		$slug = '';
		if ( ! empty( $page->username ) ) {
			$slug = sanitize_text_field( (string) $page->username );
		}
		if ( '' === $slug && ! empty( $page->page_id ) ) {
			$slug = (string) $page->page_id;
		}
		if ( '' === $slug ) {
			return '';
		}
		return 'https://www.facebook.com/' . rawurlencode( $slug );
	}

	/**
	 * Build the official Facebook Page Plugin iframe URL (no JS SDK required).
	 *
	 * Mirrors the admin preview URL builder and Meta's plugins/page.php embed.
	 *
	 * @since 6.7.9
	 * @param string              $page_href Facebook Page URL.
	 * @param array<string,mixed> $likebox   Normalized settings.
	 * @return string
	 */
	public static function build_iframe_url( string $page_href, array $likebox ): string {
		$page_href = self::sanitize_page_url( $page_href );
		if ( '' === $page_href ) {
			return '';
		}

		$width  = max( 180, min( 500, (int) ( $likebox['width'] ?? 340 ) ) );
		$height = self::resolve_iframe_height( $likebox );

		$params = array(
			'href'                  => $page_href,
			'width'                 => (string) $width,
			'height'                => (string) $height,
			'small_header'          => ! empty( $likebox['small_header'] ) ? 'true' : 'false',
			'adapt_container_width' => ! isset( $likebox['responsive'] ) || ! empty( $likebox['responsive'] ) ? 'true' : 'false',
			'hide_cover'            => ! empty( $likebox['hide_cover'] ) ? 'true' : 'false',
			'show_facepile'         => ! empty( $likebox['show_faces'] ) ? 'true' : 'false',
			'hide_cta'              => ! empty( $likebox['hide_cta'] ) ? 'true' : 'false',
		);

		$tabs       = isset( $likebox['tabs'] ) ? trim( (string) $likebox['tabs'] ) : '';
		$show_posts = ! empty( $likebox['show_posts'] );
		if ( '' !== $tabs ) {
			$params['tabs'] = $tabs;
		} elseif ( $show_posts ) {
			$params['tabs'] = 'timeline';
		}

		$locale = isset( $likebox['locale'] ) ? (string) $likebox['locale'] : 'en_US';
		if ( preg_match( '/^[a-z]{2}_[A-Z]{2}$/', $locale ) && 'en_US' !== $locale ) {
			$params['locale'] = $locale;
		}

		$app_id = isset( $likebox['app_id'] ) ? trim( (string) $likebox['app_id'] ) : '';
		if ( '' === $app_id ) {
			$app_id = '395202813876688';
		}
		if ( '' !== $app_id ) {
			$params['appId'] = $app_id;
		}

		return 'https://www.facebook.com/plugins/page.php?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Build Page Plugin iframe markup (no SDK — loads only when shortcode is on the page).
	 *
	 * @param int                 $feed_id   Feed ID.
	 * @param string              $page_href Page URL.
	 * @param array<string,mixed> $likebox   Settings.
	 * @return string
	 */
	private function build_markup( int $feed_id, string $page_href, array $likebox ): string {
		$width        = (int) $likebox['width'];
		$height       = self::resolve_iframe_height( $likebox );
		$iframe_src   = self::build_iframe_url( $page_href, $likebox );
		$iframe_title = function_exists( '__' )
			? __( 'Facebook Page Plugin', 'easy-facebook-likebox' )
			: 'Facebook Page Plugin';

		if ( '' === $iframe_src ) {
			return '';
		}

		return sprintf(
			'<div class="esf-facebook-likebox" data-feed-id="%1$d" style="--esf-likebox-width:%2$dpx;--esf-likebox-height:%3$dpx;width:min(100%%, var(--esf-likebox-width));">'
			. '<iframe class="esf-facebook-likebox__iframe" title="%4$s" src="%5$s" width="%2$d" height="%3$d" style="border:none;overflow:hidden;width:100%%;max-width:%2$dpx;height:%3$dpx;" scrolling="no" frameborder="0" allowfullscreen="true" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" loading="lazy"></iframe>'
			. '</div>',
			$feed_id,
			$width,
			$height,
			esc_attr( $iframe_title ),
			esc_url( $iframe_src )
		);
	}
}
