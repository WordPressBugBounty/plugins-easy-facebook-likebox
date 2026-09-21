<?php
/**
 * Twitter Row Layout.
 *
 * Single horizontal band of tweet tiles (Instagram-style Row). Free layout;
 * text-only posts remain visible as text tiles. Wave stagger is Pro-only
 * ({@see is_row_wave_style_enabled()}).
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Layouts
 * @since 6.15.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EasySocialFeed\Layouts\Primitives\MediaCollage;
use EasySocialFeed\Layouts\Primitives\MediaTile;
use EasySocialFeed\Layouts\ValueObjects\LayoutDefinition;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

/**
 * Class ESF_Twitter_Layout_Row
 *
 * @since 6.15.0
 */
class ESF_Twitter_Layout_Row extends ESF_Twitter_Layout_Base {

	/**
	 * WordPress style handle.
	 *
	 * @since 6.15.0
	 * @var string
	 */
	const CSS_HANDLE = 'esf-twitter-layout-row';

	/**
	 * WordPress script handle for wave animation.
	 *
	 * @since 6.15.0
	 * @var string
	 */
	const JS_HANDLE = 'esf-twitter-layout-row-wave';

	/**
	 * Wave style: horizontal gap between tiles (px).
	 *
	 * @since 6.15.0
	 * @var int
	 */
	const WAVE_GAP = 12;

	/**
	 * Wave style: rounded-square corner radius (px).
	 *
	 * @since 6.15.0
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
	 * @since 6.15.0
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
	 * @since 6.15.0
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
	 * @since 6.15.0
	 * @return bool
	 */
	private function is_row_wave_style_enabled() {
		if ( ! function_exists( 'esf_twitter_has_twitter_plan' ) || ! esf_twitter_has_twitter_plan() ) {
			return false;
		}

		return 'wave' === (string) $this->get_row_setting( 'row_style', 'standard' );
	}

	/**
	 * Resolve uniform tile aspect ratio (Instagram tokens). Wave forces 1:1.
	 *
	 * @since 6.15.0
	 * @return string
	 */
	private function resolve_media_aspect_ratio() {
		if ( $this->is_row_wave_style_enabled() ) {
			return '1:1';
		}

		$ratio = trim( (string) $this->get_row_setting( 'media_aspect_ratio', '1:1' ) );
		if ( in_array( $ratio, array( '1:1', '4:5', '3:4' ), true ) ) {
			return $ratio;
		}
		return '1:1';
	}

	/**
	 * Convert aspect ratio token to CSS.
	 *
	 * @since 6.15.0
	 * @param string $ratio Ratio token.
	 * @return string
	 */
	private function aspect_ratio_to_css( $ratio ) {
		$map = array(
			'1:1' => '1 / 1',
			'4:5' => '4 / 5',
			'3:4' => '3 / 4',
		);
		return isset( $map[ $ratio ] ) ? $map[ $ratio ] : '1 / 1';
	}

	/**
	 * Line clamp for text-only tiles from the selected aspect ratio.
	 *
	 * @since 6.15.0
	 * @return int
	 */
	private function resolve_text_tile_line_clamp() {
		switch ( $this->resolve_media_aspect_ratio() ) {
			case '4:5':
				return 10;
			case '3:4':
				return 9;
			case '1:1':
			default:
				return 8;
		}
	}

	/**
	 * Build shell class + inline style for the row root.
	 *
	 * @since 6.15.0
	 * @return array{class:string,style:string}
	 */
	private function get_row_shell_attributes() {
		$wave = $this->is_row_wave_style_enabled();

		$cols_desktop = max( 1, min( 6, (int) $this->get_row_setting( 'columns', 6 ) ) );
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

		$feed_settings    = isset( $this->feed->settings['feed'] ) && is_array( $this->feed->settings['feed'] )
			? $this->feed->settings['feed']
			: array();
		$hover_plus_size  = isset( $feed_settings['hover_plus_size'] )
			? max( 20, min( 72, (int) $feed_settings['hover_plus_size'] ) )
			: 28;
		$hover_plus_color = $this->sanitize_css_color(
			isset( $feed_settings['hover_plus_color'] ) ? (string) $feed_settings['hover_plus_color'] : '',
			''
		);

		$class = 'esf-tw-feed__row';
		if ( $wave ) {
			$class .= ' esf-tw-feed__row--wave';
		}

		$style_parts = array(
			'--esf-tw-row-cols-desktop:' . $cols_desktop,
			'--esf-tw-row-cols-tablet:' . $cols_tablet,
			'--esf-tw-row-cols-mobile:' . $cols_mobile,
			'--esf-tw-row-gap:' . $gap . 'px',
			'--esf-tw-tile-radius:' . $tile_radius . 'px',
			'--esf-tw-tile-ratio:' . $this->aspect_ratio_to_css( $this->resolve_media_aspect_ratio() ),
			'--esf-tw-row-text-lines:' . $this->resolve_text_tile_line_clamp(),
			'--esf-tw-hover-plus-size:' . $hover_plus_size . 'px',
			'--esf-tw-tile-shadow:' . ( $tile_shadow ? '0 4px 12px ' . $shadow_color : 'none' ),
		);

		if ( $wave ) {
			$wave_offset   = max( 8, min( 48, (int) $this->get_row_setting( 'wave_offset', 16 ) ) );
			$style_parts[] = '--esf-tw-wave-offset:' . $wave_offset . 'px';
		}

		if ( '' !== $hover_plus_color ) {
			$style_parts[] = '--esf-tw-hover-plus-color:' . $hover_plus_color;
		}

		$row_scope = isset( $this->feed->settings['layout']['row'] ) && is_array( $this->feed->settings['layout']['row'] )
			? $this->feed->settings['layout']['row']
			: array();
		if ( function_exists( 'esf_layout_dimension_style_vars' ) ) {
			foreach ( esf_layout_dimension_style_vars( $row_scope, '--esf-tw' ) as $var => $value ) {
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
	 * @since 6.15.0
	 * @return string HTML output.
	 */
	public function render(): string {
		$header_html = $this->render_header();
		$shell       = $this->get_row_shell_attributes();

		$display_state = $this->get_display_state();
		$tweets        = isset( $display_state['tweets'] ) && is_array( $display_state['tweets'] )
			? $display_state['tweets']
			: array();

		if ( empty( $tweets ) ) {
			return '<div class="' . esc_attr( $shell['class'] ) . '" style="' . esc_attr( $shell['style'] ) . '">'
				. $header_html
				. $this->render_empty_state()
				. '</div>';
		}

		$cells = '';
		foreach ( $tweets as $tweet ) {
			$cells .= $this->render_row_cell( $tweet );
		}

		$load_more_html = isset( $display_state['load_more_html'] ) ? (string) $display_state['load_more_html'] : '';

		return $header_html
			. '<div class="' . esc_attr( $shell['class'] ) . '" style="' . esc_attr( $shell['style'] ) . '">'
			. '<div class="esf-tw-feed__row-inner">'
			. $cells
			. '</div>'
			. '</div>'
			. $load_more_html;
	}

	/**
	 * Render cell markup for appended Load More batches.
	 *
	 * @since 6.15.0
	 * @param array $tweets Tweet items.
	 * @return string
	 */
	public function render_cards_for_tweets( $tweets ) {
		if ( ! is_array( $tweets ) ) {
			return '';
		}

		$html = '';
		foreach ( $tweets as $tweet ) {
			$html .= $this->render_row_cell( $tweet );
		}
		return $html;
	}

	/**
	 * Render one row cell.
	 *
	 * Text-only tweets stay in the row as text tiles.
	 *
	 * @since 6.15.0
	 * @param array $tweet Normalized tweet item.
	 * @return string
	 */
	private function render_row_cell( $tweet ) {
		if ( ! is_array( $tweet ) ) {
			return '';
		}

		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$card     = isset( $settings['card'] ) && is_array( $settings['card'] ) ? $settings['card'] : array();
		$feed     = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();

		$show_image    = ! isset( $card['show_image'] ) || ! empty( $card['show_image'] );
		$show_likes    = ! isset( $card['show_likes'] ) || ! empty( $card['show_likes'] );
		$show_retweets = ! isset( $card['show_retweets'] ) || ! empty( $card['show_retweets'] );
		$show_replies  = ! isset( $card['show_replies'] ) || ! empty( $card['show_replies'] );
		$show_quotes   = ! isset( $card['show_quotes'] ) || ! empty( $card['show_quotes'] );

		$popup_show_author        = ! isset( $feed['popup_show_author'] ) || ! empty( $feed['popup_show_author'] );
		$popup_show_date          = ! isset( $feed['popup_show_date'] ) || ! empty( $feed['popup_show_date'] );
		$popup_show_follow        = ! isset( $feed['popup_show_follow'] ) || ! empty( $feed['popup_show_follow'] );
		$popup_show_text          = ! isset( $feed['popup_show_text'] ) || ! empty( $feed['popup_show_text'] );
		$popup_show_metrics       = ! isset( $feed['popup_show_metrics'] ) || ! empty( $feed['popup_show_metrics'] );
		$popup_show_view_on_x     = ! isset( $feed['popup_show_view_on_x'] ) || ! empty( $feed['popup_show_view_on_x'] );
		$popup_show_comments_list = ! isset( $feed['popup_show_comments_list'] ) || ! empty( $feed['popup_show_comments_list'] );
		$popup_comments_per_page  = isset( $feed['popup_comments_per_page'] )
			? max( 1, min( 50, (int) $feed['popup_comments_per_page'] ) )
			: 20;

		$text_html  = isset( $tweet['text_html'] ) ? (string) $tweet['text_html'] : '';
		$created_at = isset( $tweet['created_at'] ) ? (string) $tweet['created_at'] : '';
		$tweet_url  = isset( $tweet['tweet_url'] ) ? (string) $tweet['tweet_url'] : '';
		$media      = isset( $tweet['media'] ) && is_array( $tweet['media'] ) ? $tweet['media'] : array();

		$author             = isset( $tweet['author'] ) && is_array( $tweet['author'] ) ? $tweet['author'] : array();
		$author_name        = ! empty( $author['display_name'] ) ? (string) $author['display_name']
			: ( $this->account ? (string) $this->account->display_name : '' );
		$author_handle      = ! empty( $author['username'] ) ? (string) $author['username']
			: ( $this->account ? (string) $this->account->username : '' );
		$author_is_verified = $this->is_user_verified( $author );
		$author_avatar      = ! empty( $author['profile_image_url'] ) ? (string) $author['profile_image_url']
			: ( $this->account ? (string) $this->account->profile_image_url : '' );
		if ( function_exists( 'esf_twitter_get_best_profile_image_url' ) ) {
			$author_avatar = esf_twitter_get_best_profile_image_url( $author_avatar );
		}
		$profile_url = $author_handle ? 'https://x.com/' . $author_handle : 'https://x.com';

		$media_items = $show_image ? $this->to_post_media_items( $media ) : array();
		$has_media   = ! empty( $media_items );
		$date_human  = function_exists( 'esf_twitter_format_date' ) ? esf_twitter_format_date( $created_at ) : $created_at;
		$like_count  = isset( $tweet['like_count'] ) ? (int) $tweet['like_count'] : 0;
		$rt_count    = isset( $tweet['retweet_count'] ) ? (int) $tweet['retweet_count'] : 0;
		$reply_count = isset( $tweet['reply_count'] ) ? (int) $tweet['reply_count'] : 0;
		$quote_count = isset( $tweet['quote_count'] ) ? (int) $tweet['quote_count'] : 0;

		if ( $has_media && '' !== $text_html ) {
			$text_html = $this->strip_media_url_links_from_text_html( $text_html );
		}

		$popup_payload      = null;
		$popup_payload_attr = '';
		$cell_class         = 'esf-tw-feed__row-cell';

		if ( $this->is_popup_enabled_for_feed() ) {
			$popup_payload = $this->build_popup_payload(
				array(
					'tweet'                    => $tweet,
					'tweet_url'                => $tweet_url,
					'text_html'                => $text_html,
					'created_at'               => $created_at,
					'date_human'               => $date_human,
					'author_name'              => $author_name,
					'author_handle'            => $author_handle,
					'author_avatar'            => $author_avatar,
					'author_is_verified'       => $author_is_verified,
					'profile_url'              => $profile_url,
					'media'                    => $media,
					'like_count'               => $like_count,
					'reply_count'              => $reply_count,
					'retweet_count'            => $rt_count,
					'quote_count'              => $quote_count,
					'show_likes'               => $show_likes,
					'show_replies'             => $show_replies,
					'show_retweets'            => $show_retweets,
					'show_quotes'              => $show_quotes,
					'popup_show_author'        => $popup_show_author,
					'popup_show_date'          => $popup_show_date,
					'popup_show_follow'        => $popup_show_follow,
					'popup_show_text'          => $popup_show_text,
					'popup_show_metrics'       => $popup_show_metrics,
					'popup_show_view_on_x'     => $popup_show_view_on_x,
					'popup_show_comments_list' => $popup_show_comments_list,
					'popup_comments_per_page'  => $popup_comments_per_page,
					'feed_id'                  => isset( $this->feed->id ) ? (int) $this->feed->id : 0,
				)
			);
		}

		if ( ! empty( $popup_payload ) ) {
			$cell_class        .= ' esf-tw-feed__row-cell--popup';
			$popup_json         = wp_json_encode( $popup_payload );
			$popup_payload_attr = is_string( $popup_json ) && '' !== $popup_json
				? ' data-esf-tw-popup="' . esc_attr( $popup_json ) . '"'
				: '';
			if ( '' !== $popup_payload_attr && function_exists( 'esf_twitter_flag_lightbox_script' ) ) {
				esf_twitter_flag_lightbox_script();
			}
		}

		$media_alt = function_exists( 'esf_seo_media_alt' )
			? esf_seo_media_alt( $text_html, $author_name, 'photo' )
			: $author_name;

		if ( $has_media ) {
			$tile_html = $this->render_media_tile(
				$media_items,
				$tweet_url,
				$media_alt,
				! empty( $popup_payload ),
				$feed,
				$like_count
			);
			if ( '' === $tile_html ) {
				$cell_class .= ' esf-tw-feed__row-cell--text';
				$tile_html   = $this->render_text_tile( $text_html, $tweet_url, ! empty( $popup_payload ) );
			}
		} else {
			$cell_class .= ' esf-tw-feed__row-cell--text';
			$tile_html   = $this->render_text_tile( $text_html, $tweet_url, ! empty( $popup_payload ) );
		}

		return '<article class="' . esc_attr( $cell_class ) . '"' . $this->get_post_id_attr( $tweet ) . $popup_payload_attr . '>'
			. $tile_html
			. '</article>';
	}

	/**
	 * Convert tweet media arrays to PostMedia objects for shared primitives.
	 *
	 * @since 6.15.0
	 * @param array $media Raw media items.
	 * @return PostMedia[]
	 */
	private function to_post_media_items( array $media ) {
		$items = array();

		foreach ( $media as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$raw_type = isset( $item['type'] ) ? (string) $item['type'] : 'photo';
			$is_video = in_array( $raw_type, array( 'video', 'animated_gif' ), true );

			$url         = isset( $item['url'] ) ? (string) $item['url'] : '';
			$preview_url = isset( $item['preview_url'] ) ? (string) $item['preview_url'] : '';
			$video_url   = isset( $item['video_url'] ) ? (string) $item['video_url'] : '';

			if ( $is_video && '' === $video_url && '' !== $url ) {
				$video_url = $url;
			}

			$post_media = new PostMedia(
				array(
					'type'        => $is_video ? PostMedia::TYPE_VIDEO : PostMedia::TYPE_PHOTO,
					'url'         => $url,
					'preview_url' => $preview_url,
					'video_url'   => $video_url,
					'width'       => isset( $item['width'] ) ? (int) $item['width'] : 0,
					'height'      => isset( $item['height'] ) ? (int) $item['height'] : 0,
					'alt'         => isset( $item['alt'] ) ? (string) $item['alt'] : '',
				)
			);

			if ( '' === $post_media->get_display_url() && ! $post_media->has_placeholder_poster() ) {
				continue;
			}

			$items[] = $post_media;
		}

		return $items;
	}

	/**
	 * Render media via shared MediaTile / MediaCollage primitives (module slug `tw`).
	 *
	 * @since 6.15.0
	 * @param PostMedia[] $media_items Converted media items.
	 * @param string      $tweet_url   Permalink.
	 * @param string      $alt         Alt text.
	 * @param bool        $has_popup   Whether popup payload is present.
	 * @param array       $feed        Feed settings section.
	 * @param int         $like_count  Like count (hover likes off for row).
	 * @return string
	 */
	private function render_media_tile( array $media_items, $tweet_url, $alt, $has_popup, array $feed, $like_count = 0 ) {
		if ( empty( $media_items ) ) {
			return '';
		}

		$wave                 = $this->is_row_wave_style_enabled();
		$hover_overlay        = ! $wave && ( ! isset( $feed['hover_overlay'] ) || ! empty( $feed['hover_overlay'] ) );
		$show_hover_plus      = ! $wave && ( ! isset( $feed['show_hover_plus'] ) || ! empty( $feed['show_hover_plus'] ) );
		$show_media_type_icon = ! isset( $feed['show_media_type_icon'] ) || ! empty( $feed['show_media_type_icon'] );
		$show_media_collage   = ! empty( $feed['show_media_collage'] );

		$options = array(
			'prefer_preview'       => true,
			'show_overlay'         => $hover_overlay,
			'show_hover_plus'      => $hover_overlay && $show_hover_plus,
			'show_media_type_icon' => $show_media_type_icon,
			'popup_trigger'        => (bool) $has_popup,
			'show_hover_likes'     => false,
			'show_hover_comments'  => false,
			'likes_count'          => (int) $like_count,
		);

		$primary     = $media_items[0];
		$extra_count = max( 0, count( $media_items ) - 1 );
		$permalink   = (string) $tweet_url;

		if ( $show_media_collage && count( $media_items ) > 1 ) {
			$options['variant'] = 'tile';
			return MediaCollage::render( 'tw', $media_items, $permalink, $alt, $options );
		}

		return MediaTile::render( 'tw', $primary, $extra_count, $permalink, $alt, $options );
	}

	/**
	 * Render a text-only tweet tile so posts without media still appear.
	 *
	 * @since 6.15.0
	 * @param string $text_html Raw tweet HTML.
	 * @param string $tweet_url Permalink.
	 * @param bool   $has_popup Whether popup is enabled.
	 * @return string
	 */
	private function render_text_tile( $text_html, $tweet_url, $has_popup ) {
		$text = function_exists( 'wp_strip_all_tags' )
			? trim( wp_strip_all_tags( $text_html ) )
			: trim( strip_tags( $text_html ) );

		if ( '' === $text ) {
			$text = __( 'View on X', 'easy-facebook-likebox' );
		}

		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $words ) && count( $words ) > 80 ) {
			$text = implode( ' ', array_slice( $words, 0, 80 ) ) . '…';
		}

		$inner = '<span class="esf-tw-feed__row-text-tile-label">' . esc_html__( 'Post', 'easy-facebook-likebox' ) . '</span>'
			. '<span class="esf-tw-feed__row-text-tile-body">' . esc_html( $text ) . '</span>';

		$trigger_attr = $has_popup ? ' data-esf-tw-popup-trigger="1"' : '';

		if ( '' !== $tweet_url && ! $has_popup ) {
			return '<a class="esf-tw-feed__row-text-tile" href="' . esc_url( $tweet_url ) . '" target="_blank" rel="noopener noreferrer">'
				. $inner
				. '</a>';
		}

		return '<div class="esf-tw-feed__row-text-tile"' . $trigger_attr . '>' . $inner . '</div>';
	}

	/**
	 * Get layout definition.
	 *
	 * @since 6.15.0
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
						'css' => ESF_TWITTER_URL . 'frontend/assets/css/layouts/row.css',
						'js'  => ESF_TWITTER_URL . 'frontend/assets/js/esf-twitter-row-wave.js',
					),
				)
			);
		}
		return $this->definition;
	}
}
