<?php
/**
 * YouTube Layout Base.
 *
 * Abstract base class for feed layout renderers.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Layouts
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Layout_Base
 *
 * @since 6.7.5
 */
abstract class ESF_YouTube_Layout_Base {

	/**
	 * Feed object (id, name, account_id, feed_type, source_id, settings).
	 *
	 * @since 6.7.5
	 * @var object
	 */
	protected $feed;

	/**
	 * Normalized video items (id, video_id, title, description, thumbnail_url, published_at, view_count).
	 *
	 * @since 6.7.5
	 * @var array
	 */
	protected $videos;

	/**
	 * Account object for header (channel_name, channel_thumbnail, etc.).
	 *
	 * @since 6.7.5
	 * @var object|null
	 */
	protected $account;

	/**
	 * Constructor.
	 *
	 * @since 6.7.5
	 *
	 * @param object      $feed    Feed object with settings.
	 * @param array       $videos  Normalized video items.
	 * @param object|null $account Account object for header. Optional.
	 */
	public function __construct( $feed, $videos, $account = null ) {
		$this->feed    = $feed;
		$this->videos  = is_array( $videos ) ? $videos : array();
		$this->account = $account;
	}

	/**
	 * Render the layout output.
	 *
	 * @since 6.7.5
	 * @return string HTML output.
	 */
	abstract public function render();

	/**
	 * Get the CSS handle for this layout's stylesheet.
	 *
	 * @since 6.7.5
	 * @return string WordPress style handle.
	 */
	abstract public function get_css_handle();

	/**
	 * Get the JS handle for this layout's script (optional).
	 *
	 * @since 6.7.5
	 * @return string|null WordPress script handle or null if no JS.
	 */
	public function get_js_handle() {
		return null;
	}

	/**
	 * Get videos after applying moderation filter.
	 *
	 * Used by initial render and Load More so hidden videos are excluded consistently.
	 *
	 * @since 6.7.5
	 * @return array Filtered video items.
	 */
	protected function get_filtered_videos() {
		$feed_id = isset( $this->feed->id ) ? (int) $this->feed->id : 0;
		return esf_youtube_apply_moderation_filter( $this->videos, $feed_id );
	}

	/**
	 * Get display state for initial render: videos to show and Load More button HTML.
	 *
	 * Shared by all layouts (grid, masonry, carousel, etc.) so Load More is layout-agnostic.
	 * Returns sliced videos when Load More is on and there are more than per_page items.
	 *
	 * @since 6.7.5
	 * @return array {
	 *     @type array  $videos        Videos to display (sliced or full).
	 *     @type string $load_more_html Load More button wrapper HTML, or empty string.
	 * }
	 */
	protected function get_display_state() {
		$filtered = $this->get_filtered_videos();
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings ) ? $this->feed->settings : array();
		$feed     = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();

		$per_page        = isset( $feed['per_page'] ) ? max( 1, min( 50, (int) $feed['per_page'] ) ) : 12;
		$has_youtube_plan = function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();
		$wants_load_more  = ! empty( $feed['load_more'] );
		$total           = count( $filtered );
		$videos          = $filtered;
		$load_more_html  = '';

		if ( $total > $per_page ) {
			$videos = array_slice( $filtered, 0, $per_page );
		}

		if ( $has_youtube_plan && $wants_load_more && $total > $per_page ) {
			if ( function_exists( 'esf_youtube_flag_load_more_script' ) ) {
				esf_youtube_flag_load_more_script();
			}
			$feed_id_attr = isset( $this->feed->id ) ? (int) $this->feed->id : 0;
			$bg_color     = isset( $feed['load_more_bg_color'] ) && is_string( $feed['load_more_bg_color'] )
				? sanitize_hex_color( $feed['load_more_bg_color'] )
				: '';
			$text_color   = isset( $feed['load_more_text_color'] ) && is_string( $feed['load_more_text_color'] )
				? sanitize_hex_color( $feed['load_more_text_color'] )
				: '';
			$style_parts  = array();
			if ( '' !== $bg_color ) {
				$style_parts[] = 'background-color:' . esc_attr( $bg_color );
			}
			if ( '' !== $text_color ) {
				$style_parts[] = 'color:' . esc_attr( $text_color );
			}
			$load_more_style = ! empty( $style_parts ) ? implode( ';', $style_parts ) : '';
			$load_more_aria  = esf_get_translated_string( 'yt_load_more_videos_aria' );
			$load_more_label = esf_get_translated_string( 'yt_load_more_videos_button' );
			$load_more_html  = sprintf(
				'<div class="esf-yt-feed__load-more-wrap"><button type="button" class="esf-yt-feed__load-more" data-feed-id="%1$d" data-offset="%2$d" aria-label="%3$s" %5$s>%4$s</button></div>',
				$feed_id_attr,
				$per_page,
				esc_attr( $load_more_aria ),
				esc_html( $load_more_label ),
				'' !== $load_more_style ? 'style="' . esc_attr( $load_more_style ) . '"' : ''
			);
		}

		return array(
			'videos'         => $videos,
			'load_more_html' => $load_more_html,
		);
	}

	/**
	 * Render video cards only (no wrapper or header).
	 * Public so REST Load More can request cards HTML for a slice.
	 *
	 * @since 6.7.5
	 * @param array $videos Normalized video items.
	 * @return string HTML output.
	 */
	public function render_cards_for_videos( $videos ) {
		return $this->render_video_cards( $videos );
	}

	/**
	 * Render video cards only (no wrapper or header).
	 *
	 * @since 6.7.5
	 * @param array $videos Normalized video items.
	 * @return string HTML output.
	 */
	protected function render_video_cards( $videos ) {
		if ( ! is_array( $videos ) ) {
			return '';
		}
		$html = '';
		foreach ( $videos as $video ) {
			$html .= $this->render_video_card( $video );
		}
		return $html;
	}

	/**
	 * Render the empty state notice (no videos).
	 *
	 * @since 6.7.5
	 * @return string HTML output.
	 */
	protected function render_empty_state() {
		$message = esf_get_translated_string( 'yt_channel_has_no_videos' );
		return '<div class="esf-yt-feed__empty"><p class="esf-yt-feed__empty-text">' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Render the channel header partial.
	 *
	 * Includes optional channel banner (Pro), logo, name, stats, subscribe button, and description.
	 *
	 * @since 6.7.5
	 * @return string HTML output.
	 */
	protected function render_header() {
		$settings              = isset( $this->feed->settings ) && is_array( $this->feed->settings ) ? $this->feed->settings : array();
		$header                = isset( $settings['header'] ) && is_array( $settings['header'] ) ? $settings['header'] : array();
		$show                  = ! empty( $header['show'] );
		$show_logo             = ! isset( $header['show_logo'] ) || ! empty( $header['show_logo'] );
		$show_name             = ! isset( $header['show_name'] ) || ! empty( $header['show_name'] );
		$show_stats            = ! empty( $header['show_stats'] );
		$show_description      = ! empty( $header['show_description'] );
		$show_subscribe_button = ! empty( $header['show_subscribe_button'] );
		$show_banner           = ! isset( $header['show_banner'] ) || ! empty( $header['show_banner'] );

		if ( ! $show || ! $this->account ) {
			return '';
		}

		$name               = isset( $this->account->channel_name ) ? $this->account->channel_name : '';
		$thumb              = isset( $this->account->channel_thumbnail ) ? $this->account->channel_thumbnail : '';
		$channel_id         = isset( $this->account->channel_id ) ? $this->account->channel_id : '';
		$channel_url        = $channel_id ? 'https://www.youtube.com/channel/' . $channel_id : 'https://www.youtube.com';
		$subscribe_url      = $channel_id ? add_query_arg( 'sub_confirmation', 1, $channel_url ) : '';
		$can_show_subscribe = $show_subscribe_button && $channel_id && function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();
		$banner_url         = isset( $this->account->channel_banner ) ? $this->account->channel_banner : '';
		$can_show_banner    = $show_banner && ! empty( $banner_url ) && function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();

		ob_start();
		?>
		<div class="esf-yt-feed__header">
			<?php if ( $can_show_banner ) : ?>
				<a class="esf-yt-feed__header-banner-wrap" href="<?php echo esc_url( $channel_url ); ?>" target="_blank" rel="noopener noreferrer" aria-hidden="true">
					<img class="esf-yt-feed__header-banner" src="<?php echo esc_url( $banner_url ); ?>" alt="" role="presentation" loading="lazy" />
				</a>
			<?php endif; ?>
			<div class="esf-yt-feed__header-inner">
			<?php if ( $show_logo && $thumb ) : ?>
				<a class="esf-yt-feed__header-logo" href="<?php echo esc_url( $channel_url ); ?>" target="_blank" rel="noopener noreferrer">
					<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $name ); ?>" width="80" height="80" loading="lazy" />
				</a>
			<?php endif; ?>
			<div class="esf-yt-feed__header-meta">
				<div class="esf-yt-feed__header-top">
					<div class="esf-yt-feed__header-info">
						<?php if ( $show_name && $name ) : ?>
							<a class="esf-yt-feed__header-name" href="<?php echo esc_url( $channel_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $name ); ?></a>
						<?php endif; ?>
						<?php if ( $show_stats && ( isset( $this->account->subscriber_count ) || isset( $this->account->video_count ) ) ) : ?>
							<div class="esf-yt-feed__header-stats">
								<?php
								if ( isset( $this->account->subscriber_count ) && $this->account->subscriber_count > 0 ) {
									/* translators: %s: number of subscribers */
									echo '<span class="esf-yt-feed__header-stat">' . esc_html( sprintf( _n( '%s subscriber', '%s subscribers', $this->account->subscriber_count, 'easy-facebook-likebox' ), number_format_i18n( $this->account->subscriber_count ) ) ) . '</span>';
								}
								if ( isset( $this->account->video_count ) && $this->account->video_count > 0 ) {
									/* translators: %s: number of videos */
									echo '<span class="esf-yt-feed__header-stat">' . esc_html( sprintf( _n( '%s video', '%s videos', $this->account->video_count, 'easy-facebook-likebox' ), number_format_i18n( $this->account->video_count ) ) ) . '</span>';
								}
								?>
							</div>
						<?php endif; ?>
					</div>
					<?php if ( $can_show_subscribe && $subscribe_url ) : ?>
						<?php $subscribe_label = esf_get_translated_string( 'yt_subscribe_button' ); ?>
						<a class="esf-yt-feed__header-subscribe" href="<?php echo esc_url( $subscribe_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $subscribe_label ); ?></a>
					<?php endif; ?>
				</div>
				<?php
				$description = isset( $this->account->description ) ? trim( (string) $this->account->description ) : '';
				if ( $show_description && '' !== $description ) :
					?>
					<div class="esf-yt-feed__header-description"><?php echo wp_kses_post( nl2br( esc_html( $description ) ) ); ?></div>
				<?php endif; ?>
			</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single video card.
	 *
	 * @since 6.7.5
	 *
	 * @param array $video Normalized video (id, video_id, title, description, thumbnail_url, published_at, view_count).
	 * @return string HTML output.
	 */
	protected function render_video_card( $video ) {
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings ) ? $this->feed->settings : array();
		$card     = isset( $settings['card'] ) && is_array( $settings['card'] ) ? $settings['card'] : array();
		$popup    = isset( $settings['popup'] ) && is_array( $settings['popup'] ) ? $settings['popup'] : array();
		$header   = isset( $settings['header'] ) && is_array( $settings['header'] ) ? $settings['header'] : array();

		$show_thumb = ! isset( $card['show_thumbnail'] ) || ! empty( $card['show_thumbnail'] );
		$show_title = ! isset( $card['show_title'] ) || ! empty( $card['show_title'] );
		$show_desc  = ! empty( $card['show_description'] );
		$show_date  = ! isset( $card['show_date'] ) || ! empty( $card['show_date'] );
		$show_views = ! empty( $card['show_views'] );

		$popup_enabled = ! empty( $popup['enabled'] ) && function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();

		$popup_show_description = ! array_key_exists( 'show_description', $popup ) || ! empty( $popup['show_description'] );
		$popup_show_stats       = ! array_key_exists( 'show_stats', $popup ) || ! empty( $popup['show_stats'] );
		$popup_autoplay         = ! array_key_exists( 'autoplay', $popup ) || ! empty( $popup['autoplay'] );
		$popup_mute             = ! array_key_exists( 'mute', $popup ) || ! empty( $popup['mute'] );

		$video_id      = isset( $video['video_id'] ) ? $video['video_id'] : ( isset( $video['id'] ) ? $video['id'] : '' );
		$title         = isset( $video['title'] ) ? $video['title'] : '';
		$desc          = isset( $video['description'] ) ? $video['description'] : '';
		$thumb         = isset( $video['thumbnail_url'] ) ? $video['thumbnail_url'] : '';
		$published     = isset( $video['published_at'] ) ? $video['published_at'] : '';
		$view_count    = isset( $video['view_count'] ) ? (int) $video['view_count'] : 0;
		$like_count    = isset( $video['like_count'] ) ? (int) $video['like_count'] : 0;
		$comment_count = isset( $video['comment_count'] ) ? (int) $video['comment_count'] : 0;

		$video_url = $video_id ? 'https://www.youtube.com/watch?v=' . $video_id : '#';

		$feed_id_attr = isset( $this->feed->id ) ? (int) $this->feed->id : 0;

		$published_human = '';
		if ( $published && function_exists( 'esf_readable_time_ago' ) ) {
			$published_human = esf_readable_time_ago( $published );
		}

		$view_count_human    = $view_count > 0 && function_exists( 'esf_readable_count' ) ? esf_readable_count( $view_count ) : '';
		$like_count_human    = $like_count > 0 && function_exists( 'esf_readable_count' ) ? esf_readable_count( $like_count ) : '';
		$comment_count_human = $comment_count > 0 && function_exists( 'esf_readable_count' ) ? esf_readable_count( $comment_count ) : '';

		$popup_payload = array();
		if ( $popup_enabled && $video_id ) {
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

			// Description with URLs and hashtags converted to links (same as FB popup caption).
			$description_html = '';
			if ( $popup_show_description && '' !== (string) $desc ) {
				$safe = esc_html( $desc );
				if ( function_exists( 'esf_convert_to_hyperlinks' ) ) {
					$with_urls = esf_convert_to_hyperlinks(
						$safe,
						array( 'http', 'https', 'mail' ),
						array(
							'target' => '_blank',
							'rel'    => 'noopener noreferrer',
						)
					);
				} else {
					$with_urls = $safe;
				}
				$description_html = nl2br( esf_youtube_hashtags_to_links( $with_urls ) );
			}

			$popup_payload = array(
				'feed_id'                => $feed_id_attr,
				'video_id'               => $video_id,
				'title'                  => (string) $title,
				'description'            => (string) $desc,
				'description_html'       => (string) $description_html,
				'popup_show_description' => (bool) $popup_show_description,
				'popup_show_stats'       => (bool) $popup_show_stats,
				'popup_autoplay'         => (bool) $popup_autoplay,
				'popup_mute'             => (bool) $popup_mute,
				'published_at'           => (string) $published,
				'published_human'        => (string) $published_human,
				'view_count'             => $view_count,
				'view_count_human'       => $view_count_human,
				'like_count'             => $like_count,
				'like_count_human'       => $like_count_human,
				'comment_count'          => $comment_count,
				'comment_count_human'    => $comment_count_human,
				'channel_name'           => $channel_name,
				'channel_thumbnail'      => $channel_thumb,
				'can_show_subscribe'     => $can_show_subscribe,
				'subscribe_url'          => $can_show_subscribe ? (string) $subscribe_url : '',
			);
		}

		if ( $popup_enabled && function_exists( 'esf_youtube_flag_lightbox_script' ) ) {
			esf_youtube_flag_lightbox_script();
		}

		$article_classes = array( 'esf-yt-feed__card' );
		if ( $popup_enabled && $video_id ) {
			$article_classes[] = 'esf-yt-feed__card--popup';
		}

		$data_attrs = '';
		if ( $popup_enabled && ! empty( $popup_payload ) ) {
			$encoded = wp_json_encode( $popup_payload );
			if ( is_string( $encoded ) && '' !== $encoded ) {
				$data_attrs = ' data-esf-yt-video="' . esc_attr( $encoded ) . '"';
			}
		}

		$link_attrs  = '';
		$link_target = '_blank';
		$link_rel    = 'noopener noreferrer';
		if ( $popup_enabled && $video_id ) {
			// Use "#" and rely on JS event.preventDefault(); do NOT set data-fancybox
			// so Fancybox does not auto-bind or alter the URL/hash.
			$link_attrs  = ' href="#"';
			$link_target = '';
			$link_rel    = '';
		} elseif ( $video_url ) {
			// Popup disabled: link directly to YouTube watch URL (previous behavior).
			$link_attrs = ' href="' . esc_url( $video_url ) . '"';
		}

		ob_start();
		?>
		<article class="<?php echo esc_attr( implode( ' ', $article_classes ) ); ?>"<?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<a class="esf-yt-feed__card-link"<?php echo $link_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $link_target ? ' target="' . esc_attr( $link_target ) . '"' : ''; ?><?php echo $link_rel ? ' rel="' . esc_attr( $link_rel ) . '"' : ''; ?>>
				<?php if ( $show_thumb && $thumb ) : ?>
					<div class="esf-yt-feed__card-thumb">
						<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" />
					</div>
				<?php endif; ?>
				<div class="esf-yt-feed__card-body">
					<?php if ( $show_title && $title ) : ?>
						<h3 class="esf-yt-feed__card-title"><?php echo esc_html( $title ); ?></h3>
					<?php endif; ?>
					<?php if ( $show_desc && $desc ) : ?>
						<p class="esf-yt-feed__card-desc"><?php echo esc_html( wp_trim_words( $desc, 15 ) ); ?></p>
					<?php endif; ?>
					<?php if ( $show_date && $published ) : ?>
						<time class="esf-yt-feed__card-date" datetime="<?php echo esc_attr( $published ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $published ) ) ); ?></time>
					<?php endif; ?>
					<?php if ( $show_views && $view_count > 0 ) : ?>
						<?php /* translators: %s: number of views */ ?>
						<span class="esf-yt-feed__card-views"><?php echo esc_html( sprintf( _n( '%s view', '%s views', $view_count, 'easy-facebook-likebox' ), number_format_i18n( $view_count ) ) ); ?></span>
					<?php endif; ?>
				</div>
			</a>
		</article>
		<?php
		return ob_get_clean();
	}
}
