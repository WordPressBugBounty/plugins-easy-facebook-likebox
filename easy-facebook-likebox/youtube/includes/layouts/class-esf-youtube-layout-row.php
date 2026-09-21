<?php
/**
 * YouTube Row Layout.
 *
 * Single horizontal band of video tiles (Twitter/Instagram-style Row). Free
 * layout; wave stagger is Pro-only ({@see is_row_wave_style_enabled()}).
 *
 * Historical BEM shell: `.esf-yt-feed__row*`. Tiles use shared MediaTile →
 * `.esf-youtube-feed__tile*` (module slug `youtube`).
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Layouts
 * @since 6.17.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EasySocialFeed\Layouts\Primitives\MediaTile;
use EasySocialFeed\Layouts\ValueObjects\LayoutDefinition;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

/**
 * Class ESF_YouTube_Layout_Row
 *
 * @since 6.17.0
 */
class ESF_YouTube_Layout_Row extends ESF_YouTube_Layout_Base {

	/**
	 * WordPress style handle.
	 *
	 * @since 6.17.0
	 * @var string
	 */
	const CSS_HANDLE = 'esf-youtube-layout-row';

	/**
	 * WordPress script handle for wave animation.
	 *
	 * @since 6.17.0
	 * @var string
	 */
	const JS_HANDLE = 'esf-youtube-layout-row-wave';

	/**
	 * Wave style: horizontal gap between tiles (px).
	 *
	 * @since 6.17.0
	 * @var int
	 */
	const WAVE_GAP = 12;

	/**
	 * Wave style: rounded-square corner radius (px).
	 *
	 * @since 6.17.0
	 * @var int
	 */
	const WAVE_TILE_RADIUS = 16;

	/**
	 * {@inheritdoc}
	 */
	public function get_css_handle() {
		return self::CSS_HANDLE;
	}

	/**
	 * Sanitize a CSS color (hex or rgba).
	 *
	 * @since 6.17.0
	 * @param string $color    Raw color.
	 * @param string $fallback Fallback when invalid.
	 * @return string
	 */
	private function sanitize_css_color( $color, $fallback = '' ) {
		$color = trim( (string) $color );
		if ( '' === $color ) {
			return $fallback;
		}

		if ( function_exists( 'sanitize_hex_color' ) && 0 === strpos( $color, '#' ) ) {
			$sanitized = sanitize_hex_color( $color );
			return is_string( $sanitized ) && '' !== $sanitized ? $sanitized : $fallback;
		}

		if ( preg_match( '/^rgba?\([\d\s,\.]+\)$/i', $color ) ) {
			return $color;
		}

		return $fallback;
	}

	/**
	 * Read a layout.row setting with default.
	 *
	 * @since 6.17.0
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	private function get_row_setting( $key, $default = null ) {
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$layout   = isset( $settings['layout'] ) && is_array( $settings['layout'] )
			? $settings['layout']
			: array();
		$row      = isset( $layout['row'] ) && is_array( $layout['row'] )
			? $layout['row']
			: array();

		return array_key_exists( $key, $row ) ? $row[ $key ] : $default;
	}

	/**
	 * Whether the wave stagger style is active.
	 *
	 * Requires a YouTube plan; without one, `row_style=wave` is ignored.
	 *
	 * @since 6.17.0
	 * @return bool
	 */
	private function is_row_wave_style_enabled() {
		if ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() ) {
			return false;
		}

		return 'wave' === (string) $this->get_row_setting( 'row_style', 'standard' );
	}

	/**
	 * Resolve uniform tile aspect ratio. Wave forces 1:1.
	 *
	 * @since 6.17.0
	 * @return string
	 */
	private function resolve_media_aspect_ratio() {
		if ( $this->is_row_wave_style_enabled() ) {
			return '1:1';
		}

		$ratio = trim( (string) $this->get_row_setting( 'media_aspect_ratio', '1:1' ) );
		if ( in_array( $ratio, array( '16:9', '1:1', '3:4', '9:16' ), true ) ) {
			return $ratio;
		}
		return '1:1';
	}

	/**
	 * Convert aspect ratio token to CSS.
	 *
	 * @since 6.17.0
	 * @param string $ratio Ratio token.
	 * @return string
	 */
	private function aspect_ratio_to_css( $ratio ) {
		$map = array(
			'16:9' => '16 / 9',
			'1:1'  => '1 / 1',
			'3:4'  => '3 / 4',
			'9:16' => '9 / 16',
		);
		return isset( $map[ $ratio ] ) ? $map[ $ratio ] : '1 / 1';
	}

	/**
	 * Build shell class + inline style for the row root.
	 *
	 * @since 6.17.0
	 * @return array{class:string,style:string}
	 */
	private function get_row_shell_attributes() {
		$wave = $this->is_row_wave_style_enabled();

		$cols_desktop = max( 1, min( 6, (int) $this->get_row_setting( 'columns', 4 ) ) );
		$cols_tablet  = max( 1, min( 6, (int) $this->get_row_setting( 'columns_tablet', 3 ) ) );
		$cols_mobile  = max( 1, min( 6, (int) $this->get_row_setting( 'columns_mobile', 2 ) ) );
		$gap          = max( 0, min( 100, (int) $this->get_row_setting( 'gap', 0 ) ) );
		$tile_radius  = max( 0, min( 32, (int) $this->get_row_setting( 'tile_border_radius', 0 ) ) );

		$tile_shadow = isset( $this->feed->settings['layout']['row']['tile_shadow'] )
			? ! empty( $this->get_row_setting( 'tile_shadow', false ) )
			: false;

		if ( $wave ) {
			$gap         = self::WAVE_GAP;
			$tile_radius = self::WAVE_TILE_RADIUS;
			$tile_shadow = true;
			$cols_tablet = 1;
			$cols_mobile = 1;
		}

		$shadow_color = $this->sanitize_css_color(
			(string) $this->get_row_setting( 'tile_shadow_color', 'rgba(0,0,0,0.12)' ),
			'rgba(0,0,0,0.12)'
		);

		$feed_settings   = isset( $this->feed->settings['feed'] ) && is_array( $this->feed->settings['feed'] )
			? $this->feed->settings['feed']
			: array();
		$hover_play_size = isset( $feed_settings['hover_play_size'] ) && '' !== $feed_settings['hover_play_size'] && null !== $feed_settings['hover_play_size']
			? (int) $feed_settings['hover_play_size']
			: 36;
		if ( 52 === $hover_play_size ) {
			$hover_play_size = 36;
		}
		$hover_play_size = max( 20, min( 72, $hover_play_size ) );

		$class = 'esf-yt-feed__row';
		if ( $wave ) {
			$class .= ' esf-yt-feed__row--wave';
		}

		$style_parts = array(
			'--esf-yt-row-cols-desktop:' . $cols_desktop,
			'--esf-yt-row-cols-tablet:' . $cols_tablet,
			'--esf-yt-row-cols-mobile:' . $cols_mobile,
			'--esf-yt-row-gap:' . $gap . 'px',
			'--esf-yt-tile-radius:' . $tile_radius . 'px',
			'--esf-yt-tile-ratio:' . $this->aspect_ratio_to_css( $this->resolve_media_aspect_ratio() ),
			'--esf-yt-hover-play-size:' . $hover_play_size . 'px',
			'--esf-yt-tile-shadow:' . ( $tile_shadow ? '0 4px 12px ' . $shadow_color : 'none' ),
		);

		if ( $wave ) {
			$wave_offset   = max( 8, min( 48, (int) $this->get_row_setting( 'wave_offset', 16 ) ) );
			$style_parts[] = '--esf-yt-wave-offset:' . $wave_offset . 'px';
		}

		$row_scope = isset( $this->feed->settings['layout']['row'] ) && is_array( $this->feed->settings['layout']['row'] )
			? $this->feed->settings['layout']['row']
			: array();
		if ( function_exists( 'esf_layout_dimension_style_vars' ) ) {
			foreach ( esf_layout_dimension_style_vars( $row_scope, '--esf-yt' ) as $var => $value ) {
				$style_parts[] = $var . ':' . $value;
			}
		}

		return array(
			'class' => $class,
			'style' => implode( ';', $style_parts ),
		);
	}

	/**
	 * Render the row layout.
	 *
	 * @since 6.17.0
	 * @return string HTML output.
	 */
	public function render(): string {
		$header_html = $this->render_header();
		$shell       = $this->get_row_shell_attributes();

		$display_state = $this->get_display_state();
		$videos        = isset( $display_state['videos'] ) && is_array( $display_state['videos'] )
			? $display_state['videos']
			: array();

		if ( empty( $videos ) ) {
			return '<div class="' . esc_attr( $shell['class'] ) . '" style="' . esc_attr( $shell['style'] ) . '">'
				. $header_html
				. $this->render_empty_state()
				. '</div>';
		}

		$cells = $this->render_cards_for_videos( $videos );

		$load_more_html = isset( $display_state['load_more_html'] ) ? (string) $display_state['load_more_html'] : '';

		return $header_html
			. '<div class="' . esc_attr( $shell['class'] ) . '" style="' . esc_attr( $shell['style'] ) . '">'
			. '<div class="esf-yt-feed__row-inner">'
			. $cells
			. '</div>'
			. '</div>'
			. $load_more_html;
	}

	/**
	 * Render cell markup for appended Load More batches.
	 *
	 * @since 6.17.0
	 * @param array $videos Normalized video items.
	 * @return string
	 */
	public function render_cards_for_videos( $videos ) {
		if ( ! is_array( $videos ) ) {
			return '';
		}

		$html = '';
		foreach ( $videos as $video ) {
			$html .= $this->render_row_cell( $video );
		}
		return $html;
	}

	/**
	 * Render one row cell.
	 *
	 * @since 6.17.0
	 * @param array $video Normalized video item.
	 * @return string
	 */
	private function render_row_cell( $video ) {
		if ( ! is_array( $video ) ) {
			return '';
		}

		$ctx = $this->get_cell_context( $video );
		if ( '' === $ctx['thumb'] && '' === $ctx['title'] ) {
			return '';
		}

		$media = new PostMedia(
			array(
				'type'        => PostMedia::TYPE_VIDEO,
				'url'         => $ctx['thumb'],
				'preview_url' => $ctx['thumb'],
				'video_url'   => $ctx['video_url'],
				'alt'         => $ctx['title'],
			)
		);

		$feed  = isset( $this->feed->settings['feed'] ) && is_array( $this->feed->settings['feed'] )
			? $this->feed->settings['feed']
			: array();
		$wave  = $this->is_row_wave_style_enabled();
		$hover = ! $wave && ( ! isset( $feed['hover_overlay'] ) || ! empty( $feed['hover_overlay'] ) );
		$play  = ! $wave && ( ! isset( $feed['show_hover_play'] ) || ! empty( $feed['show_hover_play'] ) );

		$options = array(
			'prefer_preview'       => true,
			'show_overlay'         => $hover,
			'show_hover_plus'      => false,
			'show_media_type_icon' => false,
			'show_hover_play'      => $hover && $play,
			'show_hover_likes'     => false,
			'show_hover_comments'  => false,
			'likes_count'          => $ctx['like_count'],
			'comments_count'       => $ctx['comment_count'],
			'popup_trigger'        => '' !== $ctx['data_attrs'],
			'duration_label'       => $ctx['duration_label'],
		);

		$tile_html = MediaTile::render( 'youtube', $media, 0, $ctx['video_url'], $ctx['title'], $options );
		if ( '' === $tile_html ) {
			return '';
		}

		$cell_class = 'esf-yt-feed__row-cell';
		if ( '' !== $ctx['data_attrs'] ) {
			$cell_class .= ' esf-yt-feed__row-cell--popup';
		}

		return '<article class="' . esc_attr( $cell_class ) . '"' . $this->get_post_id_attr( is_array( $video ) ? $video : array() ) . $ctx['data_attrs'] . '>'
			. $tile_html
			. '</article>';
	}

	/**
	 * Shared cell context: popup payload, counts, duration, permalink.
	 *
	 * @since 6.17.0
	 * @param array $video Normalized video.
	 * @return array{data_attrs:string,video_url:string,title:string,thumb:string,like_count:int,comment_count:int,duration_label:string}
	 */
	private function get_cell_context( $video ) {
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings ) ? $this->feed->settings : array();
		$popup    = isset( $settings['popup'] ) && is_array( $settings['popup'] ) ? $settings['popup'] : array();
		$header   = isset( $settings['header'] ) && is_array( $settings['header'] ) ? $settings['header'] : array();
		$card     = isset( $settings['card'] ) && is_array( $settings['card'] ) ? $settings['card'] : array();

		$content_filter = function_exists( 'esf_youtube_content_filter_from_settings' )
			? esf_youtube_content_filter_from_settings( $settings )
			: 'all';
		$is_short_video = function_exists( 'esf_youtube_should_render_as_short' )
			? esf_youtube_should_render_as_short( is_array( $video ) ? $video : array(), $content_filter )
			: ( 'shorts' === $content_filter );

		$popup_enabled = ! empty( $popup['enabled'] ) && function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();

		$popup_show_description   = ! array_key_exists( 'show_description', $popup ) || ! empty( $popup['show_description'] );
		$popup_show_stats         = ! array_key_exists( 'show_stats', $popup ) || ! empty( $popup['show_stats'] );
		$popup_autoplay           = ! array_key_exists( 'autoplay', $popup ) || ! empty( $popup['autoplay'] );
		$popup_mute               = array_key_exists( 'mute', $popup ) && ! empty( $popup['mute'] );
		$popup_show_comments_list = ! array_key_exists( 'show_comments_list', $popup ) || ! empty( $popup['show_comments_list'] );
		$popup_comments_per_page  = isset( $popup['comments_per_page'] )
			? max( 1, min( 50, (int) $popup['comments_per_page'] ) )
			: 20;

		$video_id      = isset( $video['video_id'] ) ? $video['video_id'] : ( isset( $video['id'] ) ? $video['id'] : '' );
		$title         = isset( $video['title'] ) ? $video['title'] : '';
		$desc          = isset( $video['description'] ) ? $video['description'] : '';
		$thumb         = isset( $video['thumbnail_url'] ) ? (string) $video['thumbnail_url'] : '';
		$thumb_full    = isset( $video['thumbnail_url_full'] ) && '' !== (string) $video['thumbnail_url_full']
			? (string) $video['thumbnail_url_full']
			: $thumb;
		$published     = isset( $video['published_at'] ) ? $video['published_at'] : '';
		$view_count    = isset( $video['view_count'] ) ? (int) $video['view_count'] : 0;
		$like_count    = isset( $video['like_count'] ) ? (int) $video['like_count'] : 0;
		$comment_count = isset( $video['comment_count'] ) ? (int) $video['comment_count'] : 0;
		$video_url     = $video_id ? 'https://www.youtube.com/watch?v=' . $video_id : '#';
		$feed_id_attr  = isset( $this->feed->id ) ? (int) $this->feed->id : 0;

		$show_duration  = ! isset( $card['show_duration'] ) || ! empty( $card['show_duration'] );
		$duration_label = '';
		if ( $show_duration && ! empty( $video['duration_formatted'] ) ) {
			$duration_label = (string) $video['duration_formatted'];
		}

		$data_attrs = '';

		if ( $popup_enabled && $video_id ) {
			$published_human = '';
			if ( $published && function_exists( 'esf_readable_time_ago' ) ) {
				$published_human = esf_readable_time_ago( $published );
			}

			$view_count_human    = $view_count > 0 && function_exists( 'esf_readable_count' ) ? esf_readable_count( $view_count ) : '';
			$like_count_human    = $like_count > 0 && function_exists( 'esf_readable_count' ) ? esf_readable_count( $like_count ) : '';
			$comment_count_human = $comment_count > 0 && function_exists( 'esf_readable_count' ) ? esf_readable_count( $comment_count ) : '';

			$channel_name       = '';
			$channel_thumb      = '';
			$subscribe_url      = '';
			$show_subscribe     = ! empty( $header['show_subscribe_button'] );
			$can_show_subscribe = false;

			if ( $this->account ) {
				$channel_name  = isset( $this->account->channel_name ) ? (string) $this->account->channel_name : '';
				$channel_thumb = isset( $this->account->channel_thumbnail ) ? (string) $this->account->channel_thumbnail : '';
				$channel_id    = isset( $this->account->channel_id ) ? (string) $this->account->channel_id : '';
				if ( $channel_id ) {
					$channel_url   = 'https://www.youtube.com/channel/' . $channel_id;
					$subscribe_url = add_query_arg( 'sub_confirmation', 1, $channel_url );
					if ( $show_subscribe && function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan() ) {
						$can_show_subscribe = true;
					}
				}
			}

			$description_html = '';
			if ( $popup_show_description && '' !== (string) $desc ) {
				$description_html = function_exists( 'esf_youtube_format_description_html' )
					? esf_youtube_format_description_html( $desc )
					: '';
			}

			$popup_payload = array(
				'feed_id'                  => $feed_id_attr,
				'video_id'                 => $video_id,
				'title'                    => (string) $title,
				'description'              => (string) $desc,
				'description_html'         => (string) $description_html,
				'thumbnail_url'            => $thumb_full,
				'popup_show_description'   => (bool) $popup_show_description,
				'popup_show_stats'         => (bool) $popup_show_stats,
				'popup_autoplay'           => (bool) $popup_autoplay,
				'popup_mute'               => (bool) $popup_mute,
				'popup_show_comments_list' => (bool) $popup_show_comments_list,
				'popup_comments_per_page'  => (int) $popup_comments_per_page,
				'published_at'             => (string) $published,
				'published_human'          => (string) $published_human,
				'view_count'               => $view_count,
				'view_count_human'         => $view_count_human,
				'like_count'               => $like_count,
				'like_count_human'         => $like_count_human,
				'comment_count'            => $comment_count,
				'comment_count_human'      => $comment_count_human,
				'channel_name'             => $channel_name,
				'channel_thumbnail'        => $channel_thumb,
				'can_show_subscribe'       => $can_show_subscribe,
				'subscribe_url'            => $can_show_subscribe ? (string) $subscribe_url : '',
				'is_short'                 => $is_short_video,
				'aspect_ratio'             => $is_short_video ? '9:16' : '16:9',
				'duration_seconds'         => function_exists( 'esf_youtube_parse_duration_seconds' ) && ! empty( $video['duration'] )
					? esf_youtube_parse_duration_seconds( $video['duration'] )
					: 0,
			);

			if ( class_exists( '\ESF_GDPR_Integrations' ) ) {
				$popup_payload = \ESF_GDPR_Integrations::sanitize_popup_payload( $popup_payload, 'youtube' );
			}

			$encoded = wp_json_encode( $popup_payload );
			if ( is_string( $encoded ) && '' !== $encoded ) {
				$data_attrs = ' data-esf-yt-video="' . esc_attr( $encoded ) . '"';
			}

			if ( function_exists( 'esf_youtube_flag_lightbox_script' ) ) {
				esf_youtube_flag_lightbox_script();
			}
		}

		return array(
			'data_attrs'     => $data_attrs,
			'video_url'      => $video_url,
			'title'          => $title,
			'thumb'          => $thumb,
			'like_count'     => $like_count,
			'comment_count'  => $comment_count,
			'duration_label' => $duration_label,
		);
	}

	/**
	 * Get layout definition.
	 *
	 * @since 6.17.0
	 * @return LayoutDefinition
	 */
	public function get_definition(): LayoutDefinition {
		if ( null === $this->definition ) {
			$this->definition = new LayoutDefinition(
				array(
					'slug'        => 'row',
					'label'       => __( 'Row', 'easy-facebook-likebox' ),
					'description' => __( 'A sleek single-row strip — great for headers and sections', 'easy-facebook-likebox' ),
					'icon'        => 'row',
					'class_name'  => self::class,
					'is_pro'      => false,
					'supports'    => array( 'header', 'metrics', 'load_more' ),
					'assets'      => array(
						'css' => ESF_YOUTUBE_URL . 'frontend/assets/css/layouts/row.css',
						'js'  => ESF_YOUTUBE_URL . 'frontend/assets/js/esf-youtube-row-wave.js',
					),
				)
			);
		}
		return $this->definition;
	}
}
