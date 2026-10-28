<?php
/**
 * Social Wall feed repository.
 *
 * CRUD for `{prefix}esf_social_wall_feeds`.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Social_Wall_Feed_Repository
 *
 * @since 6.8.2
 */
class ESF_Social_Wall_Feed_Repository {

	/**
	 * Singleton instance.
	 *
	 * @since 6.8.2
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Allowed status values.
	 *
	 * @since 6.8.2
	 * @var string[]
	 */
	private static $allowed_statuses = array( 'active', 'inactive' );

	/**
	 * Max sources per wall.
	 *
	 * @since 6.8.2
	 * @var int
	 */
	const MAX_SOURCES = 10;

	/**
	 * Get singleton.
	 *
	 * @since 6.8.2
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Feeds table name.
	 *
	 * @since 6.8.2
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'esf_social_wall_feeds';
	}

	/**
	 * Default wall settings.
	 *
	 * @since 6.8.2
	 * @return array<string,mixed>
	 */
	public static function get_default_settings() {
		$layout_dimensions = function_exists( 'esf_layout_dimension_defaults' )
			? esf_layout_dimension_defaults()
			: array();

		return array(
			'sources' => array(),
			'feed'    => array(
				'per_page'                     => 9,
				'show_filter'                  => true,
				'filter_bg_color'              => '',
				'filter_hover_bg_color'        => '',
				'filter_active_bg_color'       => '',
				'filter_text_color'            => '',
				'filter_active_text_color'     => '',
				'filter_show_icons'            => true,
				'filter_icon_color'            => '',
				'show_badge'                   => true,
				'load_more'                    => true,
				'load_more_mode'               => 'button',
				'load_more_bg_color'           => '',
				'load_more_text_color'         => '',
				'load_more_hover_bg_color'     => '',
				'load_more_hover_text_color'   => '',
				'show_popup'                   => true,
				'popup_show_header'            => true,
				'popup_show_caption'           => true,
				'popup_show_meta'              => true,
				'popup_show_view_on_network'   => true,
				'popup_show_comments_list'     => true,
				'popup_comments_per_page'      => 20,
				'popup_show_gallery_nav'       => true,
				'popup_show_gallery_thumbs'    => true,
				'popup_gallery_nav_bg_color'   => '',
				'popup_gallery_nav_icon_color' => '',
				'popup_show_date'              => true,
				'popup_show_follow'            => true,
				'popup_show_strip'             => true,
				'popup_show_views'             => true,
				'popup_autoplay'               => true,
				'popup_mute'                   => false,
				'sort'                         => 'random',
				'hover_overlay'                => true,
				'show_hover_plus'              => true,
				// Brand wall header (default OFF — existing walls unchanged).
				'show_wall_header'             => false,
				'header_title'                 => '',
				'header_show_description'      => true,
				'header_description'           => '',
				'header_show_cta'              => false,
				'header_cta_label'             => 'Follow us',
				'header_cta_url'               => '',
				'header_cta_bg_color'          => '',
				'header_cta_text_color'        => '',
				'header_cta_hover_bg_color'    => '',
				'header_cta_hover_text_color'  => '',
				'header_show_network_icons'    => true,
				'header_icon_color'            => '',
				'header_icon_bg_color'         => '',
				'header_icon_size'             => 32,
				'header_align'                 => 'left',
			),
			'layout'  => array(
				'type'     => 'grid',
				'grid'     => array_merge(
					array(
						'columns'              => 3,
						'columns_tablet'       => 2,
						'columns_mobile'       => 1,
						'gap'                  => 8,
						'tile_border_radius'   => 8,
						'tile_shadow'          => true,
						'media_aspect_ratio'   => '1:1',
						'display_mode'         => 'media_only',
						'show_caption'         => true,
						'show_meta'            => true,
						'show_time'            => true,
						'caption_words'        => 25,
						'details_bg_color'     => '#ffffff',
						'details_text_color'   => '#1c1e21',
						'details_accent_color' => '#1877f2',
						'details_meta_color'   => '#65676b',
					),
					$layout_dimensions
				),
				'row'      => array_merge(
					array(
						'columns'              => 6,
						'columns_tablet'       => 3,
						'columns_mobile'       => 2,
						'gap'                  => 8,
						'tile_border_radius'   => 8,
						'tile_shadow'          => true,
						'media_aspect_ratio'   => '1:1',
						'row_style'            => '',
						'wave_offset'          => 20,
						'display_mode'         => 'media_only',
						'show_caption'         => true,
						'show_meta'            => true,
						'show_time'            => true,
						'caption_words'        => 25,
						'details_bg_color'     => '#ffffff',
						'details_text_color'   => '#1c1e21',
						'details_accent_color' => '#1877f2',
						'details_meta_color'   => '#65676b',
					),
					$layout_dimensions
				),
				'masonry'  => array_merge(
					array(
						'columns'              => 3,
						'columns_tablet'       => 2,
						'columns_mobile'       => 1,
						'gap'                  => 8,
						'tile_border_radius'   => 8,
						'tile_shadow'          => true,
						'media_aspect_ratio'   => 'auto',
						'display_mode'         => 'media_only',
						'show_caption'         => true,
						'show_meta'            => true,
						'show_time'            => true,
						'caption_words'        => 25,
						'details_bg_color'     => '#ffffff',
						'details_text_color'   => '#1c1e21',
						'details_accent_color' => '#1877f2',
						'details_meta_color'   => '#65676b',
					),
					$layout_dimensions
				),
				'carousel' => array_merge(
					array(
						'columns'              => 3,
						'columns_tablet'       => 2,
						'columns_mobile'       => 1,
						'gap'                  => 8,
						'tile_border_radius'   => 8,
						'tile_shadow'          => true,
						'media_aspect_ratio'   => '1:1',
						'autoplay'             => true,
						'autoplay_speed'       => 3000,
						'loop'                 => true,
						'show_arrows'          => true,
						'show_dots'            => true,
						'display_mode'         => 'media_only',
						'show_caption'         => true,
						'show_meta'            => true,
						'show_time'            => true,
						'caption_words'        => 25,
						'details_bg_color'     => '#ffffff',
						'details_text_color'   => '#1c1e21',
						'details_accent_color' => '#1877f2',
						'details_meta_color'   => '#65676b',
					),
					$layout_dimensions
				),
				'half_width' => array_merge(
					array(
						'gap'                   => 16,
						'card_border_radius'    => 12,
						'card_shadow'           => true,
						'card_shadow_color'     => 'rgba(0,0,0,0.08)',
						'bg_color'              => '#ffffff',
						'footer_bg_color'       => '#f0f2f5',
						'text_color'            => '#1c1e21',
						'accent_color'          => '#1877f2',
						'media_aspect_ratio'    => '1:1',
						'caption_words'         => 25,
						'show_post_header'      => true,
						'show_post_time'        => true,
						'show_share'            => true,
						'show_view_on_network'  => true,
					),
					$layout_dimensions
				),
				'full_width' => array_merge(
					array(
						'gap'                   => 16,
						'card_border_radius'    => 12,
						'card_shadow'           => true,
						'card_shadow_color'     => 'rgba(0,0,0,0.08)',
						'bg_color'              => '#ffffff',
						'footer_bg_color'       => '#f3f6f8',
						'text_color'            => '#1c1e21',
						'accent_color'          => '#1877f2',
						'media_aspect_ratio'    => 'auto',
						'media_position'        => 'header_first',
						'caption_words'         => 25,
						'show_post_header'      => true,
						'show_post_time'        => true,
						'show_share'            => true,
						'show_view_on_network'  => true,
					),
					$layout_dimensions
				),
			),
			'style'   => array(
				'custom_css' => '',
			),
		);
	}

	/**
	 * Merge saved settings with defaults.
	 *
	 * @since 6.8.2
	 * @param array<string,mixed> $saved Saved settings.
	 * @return array<string,mixed>
	 */
	public static function merge_settings_with_defaults( array $saved ) {
		return array_replace_recursive( self::get_default_settings(), $saved );
	}

	/**
	 * Sanitize sources list.
	 *
	 * @since 6.8.2
	 * @param mixed $sources Raw sources.
	 * @return array<int,array{module:string,feed_id:int}>
	 */
	public static function sanitize_sources( $sources ) {
		if ( ! is_array( $sources ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $sources as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$module  = isset( $row['module'] ) ? sanitize_key( (string) $row['module'] ) : '';
			$feed_id = isset( $row['feed_id'] ) ? absint( $row['feed_id'] ) : 0;

			if ( '' === $module || $feed_id <= 0 ) {
				continue;
			}

			if ( function_exists( 'esf_social_wall_supported_modules' )
				&& ! in_array( $module, esf_social_wall_supported_modules(), true ) ) {
				continue;
			}

			$key = $module . ':' . $feed_id;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$clean[] = array(
				'module'  => $module,
				'feed_id' => $feed_id,
			);

			if ( count( $clean ) >= self::MAX_SOURCES ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Sanitize full settings payload.
	 *
	 * @since 6.8.2
	 * @param mixed $settings Raw settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $settings ) {
		$defaults = self::get_default_settings();
		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		$merged              = self::merge_settings_with_defaults( $settings );
		$merged['sources']   = self::sanitize_sources( isset( $merged['sources'] ) ? $merged['sources'] : array() );
		$layout_type         = isset( $merged['layout']['type'] ) ? sanitize_key( (string) $merged['layout']['type'] ) : 'grid';
		$merged['layout']['type'] = in_array(
			$layout_type,
			array( 'grid', 'row', 'masonry', 'carousel', 'half_width', 'full_width' ),
			true
		)
			? $layout_type
			: 'grid';

		$feed = isset( $merged['feed'] ) && is_array( $merged['feed'] ) ? $merged['feed'] : array();
		$merged['feed']['per_page']    = max( 1, min( 50, isset( $feed['per_page'] ) ? (int) $feed['per_page'] : 9 ) );
		$merged['feed']['show_filter'] = ! empty( $feed['show_filter'] );
		$merged['feed']['filter_bg_color']          = self::sanitize_hex_or_empty( isset( $feed['filter_bg_color'] ) ? $feed['filter_bg_color'] : '' );
		$merged['feed']['filter_hover_bg_color']    = self::sanitize_hex_or_empty( isset( $feed['filter_hover_bg_color'] ) ? $feed['filter_hover_bg_color'] : '' );
		$merged['feed']['filter_active_bg_color']   = self::sanitize_hex_or_empty( isset( $feed['filter_active_bg_color'] ) ? $feed['filter_active_bg_color'] : '' );
		$merged['feed']['filter_text_color']        = self::sanitize_hex_or_empty( isset( $feed['filter_text_color'] ) ? $feed['filter_text_color'] : '' );
		$merged['feed']['filter_active_text_color'] = self::sanitize_hex_or_empty( isset( $feed['filter_active_text_color'] ) ? $feed['filter_active_text_color'] : '' );
		$merged['feed']['filter_show_icons']        = ! isset( $feed['filter_show_icons'] ) || ! empty( $feed['filter_show_icons'] );
		$merged['feed']['filter_icon_color']        = self::sanitize_hex_or_empty( isset( $feed['filter_icon_color'] ) ? $feed['filter_icon_color'] : '' );
		$merged['feed']['show_badge']  = ! isset( $feed['show_badge'] ) || ! empty( $feed['show_badge'] );
		$merged['feed']['load_more']   = ! empty( $feed['load_more'] );
		$mode = isset( $feed['load_more_mode'] ) ? sanitize_key( (string) $feed['load_more_mode'] ) : 'button';
		$merged['feed']['load_more_mode'] = in_array( $mode, array( 'button', 'infinite' ), true ) ? $mode : 'button';
		$merged['feed']['load_more_bg_color']         = self::sanitize_hex_or_empty( isset( $feed['load_more_bg_color'] ) ? $feed['load_more_bg_color'] : '' );
		$merged['feed']['load_more_text_color']       = self::sanitize_hex_or_empty( isset( $feed['load_more_text_color'] ) ? $feed['load_more_text_color'] : '' );
		$merged['feed']['load_more_hover_bg_color']   = self::sanitize_hex_or_empty( isset( $feed['load_more_hover_bg_color'] ) ? $feed['load_more_hover_bg_color'] : '' );
		$merged['feed']['load_more_hover_text_color'] = self::sanitize_hex_or_empty( isset( $feed['load_more_hover_text_color'] ) ? $feed['load_more_hover_text_color'] : '' );
		$merged['feed']['show_popup'] = ! isset( $feed['show_popup'] ) || ! empty( $feed['show_popup'] );
		$merged['feed']['popup_show_header']          = ! isset( $feed['popup_show_header'] ) || ! empty( $feed['popup_show_header'] );
		$merged['feed']['popup_show_caption']         = ! isset( $feed['popup_show_caption'] ) || ! empty( $feed['popup_show_caption'] );
		$merged['feed']['popup_show_meta']            = ! isset( $feed['popup_show_meta'] ) || ! empty( $feed['popup_show_meta'] );
		$merged['feed']['popup_show_view_on_network'] = ! isset( $feed['popup_show_view_on_network'] ) || ! empty( $feed['popup_show_view_on_network'] );
		$merged['feed']['popup_show_comments_list']   = ! isset( $feed['popup_show_comments_list'] ) || ! empty( $feed['popup_show_comments_list'] );
		$merged['feed']['popup_comments_per_page']    = max( 1, min( 50, isset( $feed['popup_comments_per_page'] ) ? (int) $feed['popup_comments_per_page'] : 20 ) );
		$merged['feed']['popup_show_gallery_nav']     = ! isset( $feed['popup_show_gallery_nav'] ) || ! empty( $feed['popup_show_gallery_nav'] );
		$merged['feed']['popup_show_gallery_thumbs']  = ! isset( $feed['popup_show_gallery_thumbs'] ) || ! empty( $feed['popup_show_gallery_thumbs'] );
		$merged['feed']['popup_gallery_nav_bg_color'] = self::sanitize_css_color_or_empty( isset( $feed['popup_gallery_nav_bg_color'] ) ? $feed['popup_gallery_nav_bg_color'] : '' );
		$merged['feed']['popup_gallery_nav_icon_color'] = self::sanitize_css_color_or_empty( isset( $feed['popup_gallery_nav_icon_color'] ) ? $feed['popup_gallery_nav_icon_color'] : '' );
		$merged['feed']['popup_show_date']   = ! isset( $feed['popup_show_date'] ) || ! empty( $feed['popup_show_date'] );
		$merged['feed']['popup_show_follow'] = ! isset( $feed['popup_show_follow'] ) || ! empty( $feed['popup_show_follow'] );
		$merged['feed']['popup_show_strip']  = ! isset( $feed['popup_show_strip'] ) || ! empty( $feed['popup_show_strip'] );
		$merged['feed']['popup_show_views']  = ! isset( $feed['popup_show_views'] ) || ! empty( $feed['popup_show_views'] );
		$merged['feed']['popup_autoplay']    = ! isset( $feed['popup_autoplay'] ) || ! empty( $feed['popup_autoplay'] );
		$merged['feed']['popup_mute']        = ! empty( $feed['popup_mute'] );
		$merged['feed']['hover_overlay']   = ! isset( $feed['hover_overlay'] ) || ! empty( $feed['hover_overlay'] );
		$merged['feed']['show_hover_plus'] = ! isset( $feed['show_hover_plus'] ) || ! empty( $feed['show_hover_plus'] );
		$sort                          = isset( $feed['sort'] ) ? sanitize_key( (string) $feed['sort'] ) : 'random';
		$merged['feed']['sort']        = in_array( $sort, array( 'newest', 'oldest', 'balanced', 'random' ), true ) ? $sort : 'random';

		$merged['feed']['show_wall_header']          = ! empty( $feed['show_wall_header'] );
		$merged['feed']['header_title']              = isset( $feed['header_title'] ) ? sanitize_text_field( (string) $feed['header_title'] ) : '';
		$merged['feed']['header_show_description']   = ! isset( $feed['header_show_description'] ) || ! empty( $feed['header_show_description'] );
		$merged['feed']['header_description']        = isset( $feed['header_description'] )
			? sanitize_textarea_field( wp_strip_all_tags( (string) $feed['header_description'] ) )
			: '';
		$merged['feed']['header_show_cta']           = ! empty( $feed['header_show_cta'] );
		$merged['feed']['header_cta_label']          = isset( $feed['header_cta_label'] )
			? sanitize_text_field( (string) $feed['header_cta_label'] )
			: 'Follow us';
		if ( '' === $merged['feed']['header_cta_label'] ) {
			$merged['feed']['header_cta_label'] = 'Follow us';
		}
		$merged['feed']['header_cta_url'] = isset( $feed['header_cta_url'] )
			? esc_url_raw( (string) $feed['header_cta_url'] )
			: '';
		$merged['feed']['header_cta_bg_color']         = self::sanitize_hex_or_empty( isset( $feed['header_cta_bg_color'] ) ? $feed['header_cta_bg_color'] : '' );
		$merged['feed']['header_cta_text_color']       = self::sanitize_hex_or_empty( isset( $feed['header_cta_text_color'] ) ? $feed['header_cta_text_color'] : '' );
		$merged['feed']['header_cta_hover_bg_color']   = self::sanitize_hex_or_empty( isset( $feed['header_cta_hover_bg_color'] ) ? $feed['header_cta_hover_bg_color'] : '' );
		$merged['feed']['header_cta_hover_text_color'] = self::sanitize_hex_or_empty( isset( $feed['header_cta_hover_text_color'] ) ? $feed['header_cta_hover_text_color'] : '' );
		$merged['feed']['header_show_network_icons'] = ! isset( $feed['header_show_network_icons'] ) || ! empty( $feed['header_show_network_icons'] );
		$merged['feed']['header_icon_color']         = self::sanitize_hex_or_empty( isset( $feed['header_icon_color'] ) ? $feed['header_icon_color'] : '' );
		$merged['feed']['header_icon_bg_color']      = self::sanitize_hex_or_empty( isset( $feed['header_icon_bg_color'] ) ? $feed['header_icon_bg_color'] : '' );
		$icon_size = isset( $feed['header_icon_size'] ) ? (int) $feed['header_icon_size'] : 32;
		$merged['feed']['header_icon_size']          = max( 20, min( 56, $icon_size ) );
		$align = isset( $feed['header_align'] ) ? sanitize_key( (string) $feed['header_align'] ) : 'left';
		$merged['feed']['header_align']              = in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left';

		$merged['layout']['grid']     = self::sanitize_tile_layout_scope( isset( $merged['layout']['grid'] ) ? $merged['layout']['grid'] : array(), array(
			'columns'        => 3,
			'columns_tablet' => 2,
			'columns_mobile' => 1,
			'max_columns'    => 6,
		) );
		$merged['layout']['masonry']  = self::sanitize_tile_layout_scope( isset( $merged['layout']['masonry'] ) ? $merged['layout']['masonry'] : array(), array(
			'columns'        => 3,
			'columns_tablet' => 2,
			'columns_mobile' => 1,
			'max_columns'    => 6,
		) );
		$merged['layout']['row']      = self::sanitize_tile_layout_scope( isset( $merged['layout']['row'] ) ? $merged['layout']['row'] : array(), array(
			'columns'             => 6,
			'columns_tablet'      => 3,
			'columns_mobile'      => 2,
			'max_columns'         => 8,
			'min_columns'         => 2,
			'include_responsive'  => true,
			'include_row_options' => true,
		) );
		$carousel = self::sanitize_tile_layout_scope( isset( $merged['layout']['carousel'] ) ? $merged['layout']['carousel'] : array(), array(
			'columns'            => 3,
			'columns_tablet'     => 2,
			'columns_mobile'     => 1,
			'max_columns'        => 6,
			'include_responsive' => true,
		) );
		$carousel_raw = isset( $merged['layout']['carousel'] ) && is_array( $merged['layout']['carousel'] )
			? $merged['layout']['carousel']
			: array();
		$carousel['autoplay']       = ! isset( $carousel_raw['autoplay'] ) || ! empty( $carousel_raw['autoplay'] );
		$carousel['autoplay_speed'] = max( 2000, min( 15000, isset( $carousel_raw['autoplay_speed'] ) ? (int) $carousel_raw['autoplay_speed'] : 3000 ) );
		$carousel['loop']           = ! isset( $carousel_raw['loop'] ) || ! empty( $carousel_raw['loop'] );
		$carousel['show_arrows']    = ! isset( $carousel_raw['show_arrows'] ) || ! empty( $carousel_raw['show_arrows'] );
		$carousel['show_dots']      = ! isset( $carousel_raw['show_dots'] ) || ! empty( $carousel_raw['show_dots'] );
		$merged['layout']['carousel'] = $carousel;

		$merged['layout']['half_width'] = self::sanitize_card_layout_scope(
			isset( $merged['layout']['half_width'] ) ? $merged['layout']['half_width'] : array(),
			array(
				'media_aspect_ratio' => '1:1',
				'include_media_position' => false,
			)
		);
		$merged['layout']['full_width'] = self::sanitize_card_layout_scope(
			isset( $merged['layout']['full_width'] ) ? $merged['layout']['full_width'] : array(),
			array(
				'media_aspect_ratio'     => 'auto',
				'include_media_position' => true,
			)
		);

		$style = isset( $merged['style'] ) && is_array( $merged['style'] ) ? $merged['style'] : array();
		$css   = isset( $style['custom_css'] ) ? (string) $style['custom_css'] : '';
		// Strip tags without requiring WP stubs in unit tests.
		$merged['style'] = array(
			'custom_css' => wp_strip_all_tags( $css ),
		);

		return $merged;
	}

	/**
	 * Sanitize Half Width / Full Width card layout scope.
	 *
	 * @since 6.8.4
	 * @param mixed               $scope Raw scope.
	 * @param array<string,mixed> $args  Defaults and flags.
	 * @return array<string,mixed>
	 */
	private static function sanitize_card_layout_scope( $scope, array $args ) {
		$scope = is_array( $scope ) ? $scope : array();
		$default_ratio = isset( $args['media_aspect_ratio'] ) ? (string) $args['media_aspect_ratio'] : '1:1';

		$ratio = isset( $scope['media_aspect_ratio'] ) ? (string) $scope['media_aspect_ratio'] : $default_ratio;
		$allowed_ratios = array( 'auto', '16:9', '1:1', '4:5', '3:4', '9:16' );
		if ( ! in_array( $ratio, $allowed_ratios, true ) ) {
			$ratio = $default_ratio;
		}

		$out = array(
			'gap'                  => max( 0, min( 100, isset( $scope['gap'] ) ? (int) $scope['gap'] : 16 ) ),
			'card_border_radius'   => max( 0, min( 32, isset( $scope['card_border_radius'] ) ? (int) $scope['card_border_radius'] : 12 ) ),
			'card_shadow'          => ! isset( $scope['card_shadow'] ) || ! empty( $scope['card_shadow'] ),
			'card_shadow_color'    => self::sanitize_css_color_or_empty( isset( $scope['card_shadow_color'] ) ? $scope['card_shadow_color'] : '' ),
			'bg_color'             => self::sanitize_hex_or_empty( isset( $scope['bg_color'] ) ? $scope['bg_color'] : '' ),
			'footer_bg_color'      => self::sanitize_hex_or_empty( isset( $scope['footer_bg_color'] ) ? $scope['footer_bg_color'] : '' ),
			'text_color'           => self::sanitize_hex_or_empty( isset( $scope['text_color'] ) ? $scope['text_color'] : '' ),
			'accent_color'         => self::sanitize_hex_or_empty( isset( $scope['accent_color'] ) ? $scope['accent_color'] : '' ),
			'media_aspect_ratio'   => $ratio,
			'caption_words'        => max( 5, min( 100, isset( $scope['caption_words'] ) ? (int) $scope['caption_words'] : 25 ) ),
			'show_post_header'     => ! isset( $scope['show_post_header'] ) || ! empty( $scope['show_post_header'] ),
			'show_post_time'       => ! isset( $scope['show_post_time'] ) || ! empty( $scope['show_post_time'] ),
			'show_share'           => ! isset( $scope['show_share'] ) || ! empty( $scope['show_share'] ),
			'show_view_on_network' => ! isset( $scope['show_view_on_network'] ) || ! empty( $scope['show_view_on_network'] ),
		);

		if ( ! empty( $args['include_media_position'] ) ) {
			$pos = isset( $scope['media_position'] ) ? sanitize_key( (string) $scope['media_position'] ) : 'header_first';
			$out['media_position'] = in_array( $pos, array( 'header_first', 'media_first' ), true )
				? $pos
				: 'header_first';
		}

		return self::merge_layout_dimensions( $out, $scope );
	}

	/**
	 * Merge shared feed/media dimension keys onto a layout scope (0 = automatic).
	 *
	 * Uses {@see esf_layout_dimension_*} so Social Wall matches other modules
	 * without duplicating clamp logic.
	 *
	 * @since 6.8.4
	 * @param array<string,mixed> $out   Sanitized scope.
	 * @param array<string,mixed> $scope Raw scope (for user values).
	 * @return array<string,mixed>
	 */
	private static function merge_layout_dimensions( array $out, array $scope ) {
		if ( ! function_exists( 'esf_layout_dimension_defaults' ) ) {
			return $out;
		}

		$dims = esf_layout_dimension_defaults();
		foreach ( $dims as $key => $default ) {
			$dims[ $key ] = isset( $scope[ $key ] ) ? (int) $scope[ $key ] : (int) $default;
		}

		if ( function_exists( 'esf_layout_normalize_dimension_scope' ) ) {
			$dims = esf_layout_normalize_dimension_scope( $dims );
		}

		return array_merge( $out, $dims );
	}

	/**
	 * Sanitize hex or rgba color (empty allowed). Used for card shadow color.
	 *
	 * @since 6.8.4
	 * @param mixed $value Raw color.
	 * @return string
	 */
	private static function sanitize_css_color_or_empty( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		$hex = sanitize_hex_color( $value );
		if ( is_string( $hex ) && '' !== $hex ) {
			return $hex;
		}
		if ( preg_match( '/^rgba?\(\s*[\d.%]+\s*,\s*[\d.%]+\s*,\s*[\d.%]+(?:\s*,\s*[\d.]+\s*)?\)$/i', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Sanitize optional hex color (empty string allowed).
	 *
	 * @since 6.8.2
	 * @param mixed $value Raw color.
	 * @return string
	 */
	private static function sanitize_hex_or_empty( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		$hex = sanitize_hex_color( $value );
		return is_string( $hex ) ? $hex : '';
	}

	/**
	 * Sanitize a layout scope (grid/row/masonry/carousel).
	 *
	 * @since 6.8.2
	 * @param mixed                $scope Raw scope.
	 * @param array<string,mixed>  $args  Defaults and flags.
	 * @return array<string,mixed>
	 */
	private static function sanitize_tile_layout_scope( $scope, array $args ) {
		$scope = is_array( $scope ) ? $scope : array();
		$min_columns = isset( $args['min_columns'] ) ? (int) $args['min_columns'] : 1;
		$max_columns = isset( $args['max_columns'] ) ? (int) $args['max_columns'] : 6;
		$default_columns = isset( $args['columns'] ) ? (int) $args['columns'] : 3;
		$include_responsive = ! isset( $args['include_responsive'] ) || ! empty( $args['include_responsive'] );

		$out = array(
			'columns'            => max( $min_columns, min( $max_columns, isset( $scope['columns'] ) ? (int) $scope['columns'] : $default_columns ) ),
			'gap'                => max( 0, min( 48, isset( $scope['gap'] ) ? (int) $scope['gap'] : 8 ) ),
			'tile_border_radius' => max( 0, min( 48, isset( $scope['tile_border_radius'] ) ? (int) $scope['tile_border_radius'] : 8 ) ),
			'tile_shadow'        => ! isset( $scope['tile_shadow'] ) || ! empty( $scope['tile_shadow'] ),
		);

		$ratio = isset( $scope['media_aspect_ratio'] ) ? (string) $scope['media_aspect_ratio'] : '1:1';
		$out['media_aspect_ratio'] = in_array( $ratio, array( '1:1', '4:5', '3:4', '9:16', '16:9', 'auto' ), true )
			? $ratio
			: '1:1';

		if ( $include_responsive ) {
			$out['columns_tablet'] = max( 1, min( $max_columns, isset( $scope['columns_tablet'] ) ? (int) $scope['columns_tablet'] : (int) ( $args['columns_tablet'] ?? 2 ) ) );
			$out['columns_mobile'] = max( 1, min( 2, isset( $scope['columns_mobile'] ) ? (int) $scope['columns_mobile'] : (int) ( $args['columns_mobile'] ?? 1 ) ) );
		}

		if ( ! empty( $args['include_row_options'] ) ) {
			$row_style = isset( $scope['row_style'] ) ? sanitize_key( (string) $scope['row_style'] ) : '';
			$out['row_style']   = 'wave' === $row_style ? 'wave' : '';
			$out['wave_offset'] = max( 8, min( 48, isset( $scope['wave_offset'] ) ? (int) $scope['wave_offset'] : 20 ) );
		}

		/*
		 * Card style (Images only / Image + details). Default media_only so
		 * existing walls keep image-first tiles until the merchant opts in.
		 */
		$mode = isset( $scope['display_mode'] ) ? sanitize_key( (string) $scope['display_mode'] ) : 'media_only';
		$out['display_mode']  = 'detailed' === $mode ? 'detailed' : 'media_only';
		$out['show_caption']  = ! isset( $scope['show_caption'] ) || ! empty( $scope['show_caption'] );
		$out['show_meta']     = ! isset( $scope['show_meta'] ) || ! empty( $scope['show_meta'] );
		$out['show_time']     = ! isset( $scope['show_time'] ) || ! empty( $scope['show_time'] );
		$out['caption_words'] = max( 5, min( 100, isset( $scope['caption_words'] ) ? (int) $scope['caption_words'] : 25 ) );

		$details_bg     = self::sanitize_hex_or_empty( isset( $scope['details_bg_color'] ) ? $scope['details_bg_color'] : '#ffffff' );
		$details_text   = self::sanitize_hex_or_empty( isset( $scope['details_text_color'] ) ? $scope['details_text_color'] : '#1c1e21' );
		$details_accent = self::sanitize_hex_or_empty( isset( $scope['details_accent_color'] ) ? $scope['details_accent_color'] : '#1877f2' );
		$details_meta   = self::sanitize_hex_or_empty( isset( $scope['details_meta_color'] ) ? $scope['details_meta_color'] : '#65676b' );

		$out['details_bg_color']     = '' !== $details_bg ? $details_bg : '#ffffff';
		$out['details_text_color']   = '' !== $details_text ? $details_text : '#1c1e21';
		$out['details_accent_color'] = '' !== $details_accent ? $details_accent : '#1877f2';
		$out['details_meta_color']   = '' !== $details_meta ? $details_meta : '#65676b';

		return self::merge_layout_dimensions( $out, $scope );
	}

	/**
	 * Get a wall by ID.
	 *
	 * @since 6.8.2
	 * @param int $id Wall ID.
	 * @return object|null
	 */
	public function get_by_id( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 ) {
			return null;
		}

		$table = $this->get_table_name();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id )
		);

		return $row ? $this->hydrate_row( $row ) : null;
	}

	/**
	 * List walls.
	 *
	 * @since 6.8.2
	 * @param array<string,mixed> $args Query args.
	 * @return object[]
	 */
	public function get_all( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'  => '',
			'orderby' => 'created_at',
			'order'   => 'DESC',
			'limit'   => 100,
			'offset'  => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$table   = $this->get_table_name();
		$where   = '1=1';
		$params  = array();
		$status  = sanitize_key( (string) $args['status'] );
		if ( in_array( $status, self::$allowed_statuses, true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		$orderby = sanitize_key( (string) $args['orderby'] );
		if ( ! in_array( $orderby, array( 'id', 'name', 'created_at', 'updated_at' ), true ) ) {
			$orderby = 'created_at';
		}
		$order = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$limit  = max( 1, min( 200, (int) $args['limit'] ) );
		$offset = max( 0, (int) $args['offset'] );

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_row' ), $rows );
	}

	/**
	 * Create a wall.
	 *
	 * @since 6.8.2
	 * @param array<string,mixed> $data Wall data.
	 * @return int|WP_Error New ID or error.
	 */
	public function create( array $data ) {
		global $wpdb;

		$name     = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		$status   = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'active';
		$settings = self::sanitize_settings( isset( $data['settings'] ) ? $data['settings'] : array() );

		if ( '' === $name ) {
			$name = __( 'Social Wall', 'easy-facebook-likebox' );
		}
		if ( ! in_array( $status, self::$allowed_statuses, true ) ) {
			$status = 'active';
		}

		$now    = current_time( 'mysql' );
		$table  = $this->get_table_name();
		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'name'       => $name,
				'settings'   => wp_json_encode( $settings ),
				'status'     => $status,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'esf_sw_create_failed', __( 'Could not create social wall.', 'easy-facebook-likebox' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a wall.
	 *
	 * @since 6.8.2
	 * @param int                 $id   Wall ID.
	 * @param array<string,mixed> $data Fields to update.
	 * @return true|WP_Error
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$id  = absint( $id );
		$row = $this->get_by_id( $id );
		if ( ! $row ) {
			return new WP_Error( 'esf_sw_not_found', __( 'Social wall not found.', 'easy-facebook-likebox' ) );
		}

		$update = array();
		$format = array();

		if ( isset( $data['name'] ) ) {
			$update['name'] = sanitize_text_field( (string) $data['name'] );
			$format[]       = '%s';
		}
		if ( isset( $data['status'] ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( in_array( $status, self::$allowed_statuses, true ) ) {
				$update['status'] = $status;
				$format[]         = '%s';
			}
		}
		if ( isset( $data['settings'] ) ) {
			$update['settings'] = wp_json_encode( self::sanitize_settings( $data['settings'] ) );
			$format[]           = '%s';
		}

		if ( empty( $update ) ) {
			return true;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'esf_sw_update_failed', __( 'Could not update social wall.', 'easy-facebook-likebox' ) );
		}

		return true;
	}

	/**
	 * Delete a wall.
	 *
	 * @since 6.8.2
	 * @param int $id Wall ID.
	 * @return true|WP_Error
	 */
	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 ) {
			return new WP_Error( 'esf_sw_not_found', __( 'Social wall not found.', 'easy-facebook-likebox' ) );
		}

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'esf_sw_delete_failed', __( 'Could not delete social wall.', 'easy-facebook-likebox' ) );
		}

		return true;
	}

	/**
	 * Hydrate a DB row (decode settings JSON to array on a clone-friendly object).
	 *
	 * @since 6.8.2
	 * @param object $row Raw DB row.
	 * @return object
	 */
	private function hydrate_row( $row ) {
		$settings_raw = isset( $row->settings ) ? (string) $row->settings : '';
		$decoded      = json_decode( $settings_raw, true );
		$row->settings = self::merge_settings_with_defaults( is_array( $decoded ) ? $decoded : array() );
		$row->id       = isset( $row->id ) ? (int) $row->id : 0;
		$row->name     = isset( $row->name ) ? (string) $row->name : '';
		$row->status   = isset( $row->status ) ? (string) $row->status : 'active';

		return $row;
	}
}
