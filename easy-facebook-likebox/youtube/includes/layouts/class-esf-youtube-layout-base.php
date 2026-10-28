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

use EasySocialFeed\Layouts\Contracts\Layout;
use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\Primitives\ExpandableCaption;
use EasySocialFeed\Layouts\Primitives\PostMeta;
use EasySocialFeed\Layouts\Primitives\ShowcaseStage;
use EasySocialFeed\Layouts\Support\NumberFormatter;
use EasySocialFeed\Layouts\Support\Tooltip;
use EasySocialFeed\Layouts\ValueObjects\LayoutDefinition;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\YouTube\Layouts\PostMapper;
use EasySocialFeed\YouTube\Layouts\AccountMapper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Layout_Base
 *
 * @since 6.7.5
 */
abstract class ESF_YouTube_Layout_Base implements Layout {

	/**
	 * Module slug.
	 *
	 * @since 6.9.0
	 * @var string
	 */
	protected $module;

	/**
	 * Layout definition.
	 *
	 * @since 6.9.0
	 * @var LayoutDefinition
	 */
	protected $definition;

	/**
	 * Feed object (id, name, account_id, feed_type, source_id, settings).
	 *
	 * @since 6.7.5
	 * @var object
	 */
	protected $feed;

	/**
	 * Post value objects.
	 *
	 * @since 6.9.0
	 * @var PostItem[]
	 */
	protected $posts;

	/**
	 * Normalized video items (legacy).
	 *
	 * @since 6.7.5
	 * @var array
	 */
	protected $videos;

	/**
	 * Account summary.
	 *
	 * @since 6.9.0
	 * @var AccountSummary|null
	 */
	protected $account;

	/**
	 * True after the showcase stage rendered and until the first item consumes it.
	 *
	 * Lets every layout mark the featured (first) item without tracking position,
	 * and keeps Load More batches (no stage) from marking another active item.
	 *
	 * @since 6.18.0
	 * @var bool
	 */
	protected $showcase_active_pending = false;

	/**
	 * Constructor (shared framework signature).
	 *
	 * @since 6.9.0
	 * @param string              $module     Module slug.
	 * @param LayoutDefinition    $definition Layout definition.
	 * @param object              $feed       Feed object with settings.
	 * @param PostItem[]          $posts      Post value objects.
	 * @param AccountSummary|null $account  Account summary. Optional.
	 */
	public function __construct( string $module, LayoutDefinition $definition, $feed, array $posts, ?AccountSummary $account = null ) {
		$this->module     = $module;
		$this->definition = $definition;
		$this->feed       = $feed;
		$this->account    = $account;

		// Filter and store Post objects
		$filtered_posts = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof PostItem ) {
				$filtered_posts[] = $post;
			}
		}
		$this->posts = $filtered_posts;

		// Extract raw video data from Post objects for backwards compatibility with existing render logic
		$this->videos = array();
		foreach ( $this->posts as $post ) {
			$extras = $post->get_extras();
			if ( isset( $extras['raw_video'] ) && is_array( $extras['raw_video'] ) ) {
				$this->videos[] = $extras['raw_video'];
			}
		}
	}

	/**
	 * Render the layout output.
	 *
	 * @since 6.7.5
	 * @return string HTML output.
	 */
	abstract public function render(): string;

	/**
	 * Get the layout definition (required by Layout interface).
	 *
	 * @since 6.9.0
	 * @return LayoutDefinition
	 */
	abstract public function get_definition(): LayoutDefinition;

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
		$feed_id  = isset( $this->feed->id ) ? (int) $this->feed->id : 0;
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: null;
		return esf_youtube_apply_moderation_filter( $this->videos, $feed_id, $settings );
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

		$per_page         = isset( $feed['per_page'] ) ? max( 1, min( 50, (int) $feed['per_page'] ) ) : 12;
		$has_youtube_plan = function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();
		// Default on when unset (matches Design defaults / LoadMorePanel).
		$wants_load_more = ! array_key_exists( 'load_more', $feed ) || ! empty( $feed['load_more'] );
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
			$hover_bg     = isset( $feed['load_more_hover_bg_color'] ) && is_string( $feed['load_more_hover_bg_color'] )
				? sanitize_hex_color( $feed['load_more_hover_bg_color'] )
				: '';
			$hover_text   = isset( $feed['load_more_hover_text_color'] ) && is_string( $feed['load_more_hover_text_color'] )
				? sanitize_hex_color( $feed['load_more_hover_text_color'] )
				: '';
			$style_parts  = array();
			if ( '' !== $bg_color ) {
				$style_parts[] = '--esf-lm-bg:' . $bg_color;
			}
			if ( '' !== $text_color ) {
				$style_parts[] = '--esf-lm-text:' . $text_color;
			}
			if ( '' !== $hover_bg ) {
				$style_parts[] = '--esf-lm-bg-hover:' . $hover_bg;
			}
			if ( '' !== $hover_text ) {
				$style_parts[] = '--esf-lm-text-hover:' . $hover_text;
			}
			$load_more_style = ! empty( $style_parts ) ? implode( ';', $style_parts ) : '';
			$load_more_aria  = esf_get_translated_string( 'yt_load_more_videos_aria' );
			$load_more_label = esf_get_translated_string( 'yt_load_more_videos_button' );
			$load_more_html  = sprintf(
				'<div class="esf-yt-feed__load-more-wrap"><button type="button" class="esf-yt-feed__load-more" data-feed-id="%1$d" data-offset="%2$d" aria-label="%3$s"%5$s%6$s>%4$s</button></div>',
				$feed_id_attr,
				$per_page,
				esc_attr( $load_more_aria ),
				esc_html( $load_more_label ),
				'' !== $load_more_style ? ' style="' . esc_attr( $load_more_style ) . '"' : '',
				'' !== $hover_bg ? ' data-esf-lm-has-hover-bg="1"' : ''
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
	 * Render the empty state notice (no videos for the current feed source).
	 *
	 * Message depends on feed type so playlist/search empty states are not
	 * mistaken for an empty channel.
	 *
	 * @since 6.7.5
	 * @return string HTML output.
	 */
	protected function render_empty_state() {
		$message = $this->get_empty_state_message();
		return '<div class="esf-yt-feed__empty"><p class="esf-yt-feed__empty-text">' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Resolve the empty-state message for the current feed source.
	 *
	 * @since 6.7.6
	 * @return string
	 */
	protected function get_empty_state_message() {
		$feed_type      = isset( $this->feed->feed_type ) ? sanitize_key( (string) $this->feed->feed_type ) : 'channel';
		$source_id      = isset( $this->feed->source_id ) ? trim( (string) $this->feed->source_id ) : '';
		$settings       = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$content_filter = function_exists( 'esf_youtube_content_filter_from_settings' )
			? esf_youtube_content_filter_from_settings( $settings )
			: 'all';

		if ( 'shorts' === $content_filter ) {
			return function_exists( 'esf_get_translated_string' )
				? esf_get_translated_string( 'yt_no_shorts_found' )
				: __( 'No Shorts found for this channel.', 'easy-facebook-likebox' );
		}

		if ( 'long' === $content_filter ) {
			return function_exists( 'esf_get_translated_string' )
				? esf_get_translated_string( 'yt_no_long_form_videos_found' )
				: __( 'No long-form videos found for this channel.', 'easy-facebook-likebox' );
		}

		if ( 'playlist' === $feed_type ) {
			if ( '' === $source_id ) {
				return function_exists( 'esf_get_translated_string' )
					? esf_get_translated_string( 'yt_playlist_not_selected' )
					: __( 'Select a playlist to display its videos.', 'easy-facebook-likebox' );
			}
			return function_exists( 'esf_get_translated_string' )
				? esf_get_translated_string( 'yt_playlist_has_no_videos' )
				: __( 'This playlist has no videos yet.', 'easy-facebook-likebox' );
		}

		if ( 'search' === $feed_type ) {
			if ( '' === $source_id ) {
				return function_exists( 'esf_get_translated_string' )
					? esf_get_translated_string( 'yt_search_not_entered' )
					: __( 'Enter a search keyword to find videos.', 'easy-facebook-likebox' );
			}
			return function_exists( 'esf_get_translated_string' )
				? esf_get_translated_string( 'yt_search_no_results' )
				: __( 'No videos matched this search.', 'easy-facebook-likebox' );
		}

		return function_exists( 'esf_get_translated_string' )
			? esf_get_translated_string( 'yt_channel_has_no_videos' )
			: __( 'This channel has no videos yet.', 'easy-facebook-likebox' );
	}

	/**
	 * Public header HTML for layouts that compose Grid (e.g. Half Width).
	 *
	 * @since 6.16.0
	 * @return string
	 */
	public function render_account_header_html() {
		return $this->render_header();
	}

	/**
	 * Public empty-state HTML for layouts that compose Grid (e.g. Half Width).
	 *
	 * @since 6.16.0
	 * @return string
	 */
	public function render_empty_state_html() {
		return $this->render_empty_state();
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
		$has_plan           = function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();

		$header_classes        = array( 'esf-yt-feed__header' );
		$banner_lightbox_label = '';
		if ( $can_show_banner ) {
			$header_classes[] = 'esf-yt-feed__header--has-banner';
			// Cover lightbox reuses the same FancyBox assets as the video popup.
			if ( function_exists( 'esf_youtube_flag_lightbox_script' ) ) {
				esf_youtube_flag_lightbox_script();
			}
			$banner_lightbox_label = '' !== $name
				? sprintf(
					/* translators: %s: YouTube channel name */
					__( 'View cover photo for %s', 'easy-facebook-likebox' ),
					$name
				)
				: __( 'View cover photo', 'easy-facebook-likebox' );
		}

		$style_parts = array();
		if ( $has_plan ) {
			$show_border  = ! isset( $header['show_border'] ) || ! empty( $header['show_border'] );
			$bg_color     = isset( $header['bg_color'] ) ? sanitize_hex_color( (string) $header['bg_color'] ) : '';
			$text_color   = isset( $header['text_color'] ) ? sanitize_hex_color( (string) $header['text_color'] ) : '';
			$border_color = isset( $header['border_color'] ) ? sanitize_hex_color( (string) $header['border_color'] ) : '';
			$padding_raw  = isset( $header['padding'] ) ? (int) $header['padding'] : 16;
			$padding      = max( 0, min( 48, $padding_raw ) );

			if ( is_string( $bg_color ) && '' !== $bg_color ) {
				$style_parts[] = 'background-color:' . $bg_color;
			}
			if ( is_string( $text_color ) && '' !== $text_color ) {
				$style_parts[]    = 'color:' . $text_color;
				$header_classes[] = 'esf-yt-feed__header--custom-text';
			}
			if ( ! $show_border ) {
				$style_parts[]    = 'border:none';
				$style_parts[]    = 'box-shadow:none';
				$header_classes[] = 'esf-yt-feed__header--no-border';
			} elseif ( is_string( $border_color ) && '' !== $border_color ) {
				$style_parts[] = 'border-color:' . $border_color;
			}
			if ( 16 !== $padding ) {
				$style_parts[] = 'padding:' . $padding . 'px';
			}
		}

		$header_style_attr = ! empty( $style_parts )
			? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"'
			: '';

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $header_classes ) ); ?>"<?php echo $header_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>>
			<?php if ( $can_show_banner ) : ?>
				<a
					class="esf-yt-feed__header-banner-wrap"
					href="<?php echo esc_url( $banner_url ); ?>"
					data-esf-cover-lightbox="1"
					aria-label="<?php echo esc_attr( $banner_lightbox_label ); ?>"
				>
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
						<?php
						$header_stats_html = $this->render_header_stats();
						if ( '' !== $header_stats_html ) {
							echo $header_stats_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
						}
						?>
					</div>
					<?php if ( $can_show_subscribe && $subscribe_url ) : ?>
						<?php $subscribe_label = esf_get_translated_string( 'yt_subscribe_button' ); ?>
						<a class="esf-yt-feed__header-subscribe" href="<?php echo esc_url( $subscribe_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $subscribe_label ); ?></a>
					<?php endif; ?>
				</div>
				<?php
				$description = isset( $this->account->description ) ? trim( (string) $this->account->description ) : '';
				if ( $show_description && '' !== $description ) :
					$see_more = function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'see_more', 'See more' )
						: __( 'See more', 'easy-facebook-likebox' );
					$see_less = function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'see_less', 'See less' )
						: __( 'See less', 'easy-facebook-likebox' );
					$channel_key = '' !== (string) $channel_id ? (string) $channel_id : (string) ( $this->account->id ?? '0' );
					?>
					<div class="esf-yt-feed__header-description">
						<?php
						$caption_html = ExpandableCaption::render(
							'youtube',
							'<p>' . nl2br( esc_html( $description ) ) . '</p>',
							array(
								'words_limit'    => 28,
								'post_id'        => 'header-bio-' . sanitize_html_class( $channel_key ),
								'see_more_label' => $see_more,
								'see_less_label' => $see_less,
							)
						);
						echo $caption_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ExpandableCaption escapes labels and receives pre-escaped HTML.
						?>
					</div>
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
	 * @param array $video              Normalized video (id, video_id, title, description, thumbnail_url, published_at, view_count).
	 * @param bool  $is_showcase_active Whether this card is the currently featured showcase item.
	 * @return string HTML output.
	 */
	protected function render_video_card( $video, $is_showcase_active = false ) {
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings ) ? $this->feed->settings : array();
		$card     = isset( $settings['card'] ) && is_array( $settings['card'] ) ? $settings['card'] : array();
		$popup    = isset( $settings['popup'] ) && is_array( $settings['popup'] ) ? $settings['popup'] : array();
		$header   = isset( $settings['header'] ) && is_array( $settings['header'] ) ? $settings['header'] : array();

		$show_thumb = ! isset( $card['show_thumbnail'] ) || ! empty( $card['show_thumbnail'] );
		$show_title = ! isset( $card['show_title'] ) || ! empty( $card['show_title'] );
		$show_desc  = ! isset( $card['show_description'] ) || ! empty( $card['show_description'] );
		$show_date  = ! isset( $card['show_date'] ) || ! empty( $card['show_date'] );
		// Engagement icons follow card.show_views (Pro-only; default on when unlocked).
		$show_views    = ( ! isset( $card['show_views'] ) || ! empty( $card['show_views'] ) )
			&& function_exists( 'esf_youtube_has_youtube_plan' )
			&& esf_youtube_has_youtube_plan();
		$show_duration = ! isset( $card['show_duration'] ) || ! empty( $card['show_duration'] );

		$playback_mode    = $this->get_playback_mode();
		$popup_enabled    = ( 'popup' === $playback_mode );
		$showcase_enabled = ( 'showcase' === $playback_mode );

		$popup_show_description = ! array_key_exists( 'show_description', $popup ) || ! empty( $popup['show_description'] );
		$popup_show_stats       = ! array_key_exists( 'show_stats', $popup ) || ! empty( $popup['show_stats'] );
		$popup_autoplay         = ! array_key_exists( 'autoplay', $popup ) || ! empty( $popup['autoplay'] );
		// Unmuted by default; only mute when explicitly enabled in Design.
		$popup_mute               = array_key_exists( 'mute', $popup ) && ! empty( $popup['mute'] );
		$popup_show_comments_list = ! array_key_exists( 'show_comments_list', $popup ) || ! empty( $popup['show_comments_list'] );
		$popup_comments_per_page  = isset( $popup['comments_per_page'] )
			? max( 1, min( 50, (int) $popup['comments_per_page'] ) )
			: 20;

		$video_id       = isset( $video['video_id'] ) ? $video['video_id'] : ( isset( $video['id'] ) ? $video['id'] : '' );
		$title          = isset( $video['title'] ) ? $video['title'] : '';
		$desc           = isset( $video['description'] ) ? $video['description'] : '';
		$thumb          = isset( $video['thumbnail_url'] ) ? $video['thumbnail_url'] : '';
		$thumb_full     = isset( $video['thumbnail_url_full'] ) && '' !== (string) $video['thumbnail_url_full']
			? (string) $video['thumbnail_url_full']
			: (string) $thumb;
		$published      = isset( $video['published_at'] ) ? $video['published_at'] : '';
		$view_count     = isset( $video['view_count'] ) ? (int) $video['view_count'] : 0;
		$like_count     = isset( $video['like_count'] ) ? (int) $video['like_count'] : 0;
		$comment_count  = isset( $video['comment_count'] ) ? (int) $video['comment_count'] : 0;
		$duration_label = '';
		if ( $show_duration && isset( $video['duration_formatted'] ) ) {
			$duration_label = (string) $video['duration_formatted'];
		}

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

			$popup_account = function_exists( 'esf_youtube_account_for_video' )
				? esf_youtube_account_for_video( is_array( $video ) ? $video : array(), $this->account )
				: $this->account;

			if ( $popup_account ) {
				$channel_name  = isset( $popup_account->channel_name ) ? (string) $popup_account->channel_name : '';
				$channel_thumb = isset( $popup_account->channel_thumbnail ) ? (string) $popup_account->channel_thumbnail : '';
				$channel_id    = isset( $popup_account->channel_id ) ? (string) $popup_account->channel_id : '';
				if ( $channel_id ) {
					$channel_url   = 'https://www.youtube.com/channel/' . $channel_id;
					$subscribe_url = add_query_arg( 'sub_confirmation', 1, $channel_url );
					if ( $show_subscribe && function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan() ) {
						$can_show_subscribe = true;
					}
				}
			}

			// Description with URLs, hashtags, and @handles converted to links.
			$description_html = '';
			if ( $popup_show_description && '' !== (string) $desc ) {
				$description_html = function_exists( 'esf_youtube_format_description_html' )
					? esf_youtube_format_description_html( $desc )
					: '';
			}

			$content_filter = function_exists( 'esf_youtube_content_filter_from_settings' )
				? esf_youtube_content_filter_from_settings( $settings )
				: 'all';
			$is_short_video = function_exists( 'esf_youtube_should_render_as_short' )
				? esf_youtube_should_render_as_short( is_array( $video ) ? $video : array(), $content_filter )
				: ( 'shorts' === $content_filter );

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
		}

		if ( $popup_enabled && function_exists( 'esf_youtube_flag_lightbox_script' ) ) {
			esf_youtube_flag_lightbox_script();
		}
		if ( $showcase_enabled && function_exists( 'esf_youtube_flag_showcase_script' ) ) {
			esf_youtube_flag_showcase_script();
		}

		$article_classes = array( 'esf-yt-feed__card' );
		if ( $popup_enabled && $video_id ) {
			$article_classes[] = 'esf-yt-feed__card--popup';
		}
		if ( $showcase_enabled && $video_id ) {
			// The first item rendered after the stage is the featured one. Load More
			// batches never render a stage, so they never mark an active card.
			$is_showcase_active = $is_showcase_active || $this->take_showcase_active();
			$article_classes[]  = 'esf-yt-feed__card--showcase';
			if ( $is_showcase_active ) {
				$article_classes[] = 'is-esf-showcase-active';
			}
		}

		$data_attrs = $this->get_post_id_attr( is_array( $video ) ? $video : array() );
		if ( $popup_enabled && ! empty( $popup_payload ) ) {
			if ( class_exists( '\ESF_GDPR_Integrations' ) ) {
				$popup_payload = \ESF_GDPR_Integrations::sanitize_popup_payload( $popup_payload, 'youtube' );
			}
			$encoded = wp_json_encode( $popup_payload );
			if ( is_string( $encoded ) && '' !== $encoded ) {
				$data_attrs .= ' data-esf-yt-video="' . esc_attr( $encoded ) . '"';
			}
		}
		if ( $showcase_enabled && is_array( $video ) ) {
			$data_attrs .= $this->get_showcase_item_attrs( $video );
		}

		$link_attrs = ' href="' . esc_url( $video_url ) . '"';

		$aria_current = ( $showcase_enabled && $is_showcase_active ) ? ' aria-current="true"' : '';

		ob_start();
		?>
		<article class="<?php echo esc_attr( implode( ' ', $article_classes ) ); ?>"<?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $aria_current; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute. ?>>
			<a class="esf-yt-feed__card-link"<?php echo $link_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> target="_blank" rel="noopener noreferrer">
				<?php if ( $show_thumb && $thumb ) : ?>
					<div class="esf-yt-feed__card-thumb">
						<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" />
						<?php if ( '' !== $duration_label ) : ?>
							<span class="esf-yt-feed__card-duration"><?php echo esc_html( $duration_label ); ?></span>
						<?php endif; ?>
						<span class="esf-yt-feed__card-play" aria-hidden="true">
							<?php // Official YouTube icon — do not change fills to currentColor (III.E.4). ?>
							<svg width="22" height="22" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
								<path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814z" fill="#FF0000"/>
								<path d="M9.545 15.568V8.432L15.818 12l-6.273 3.568z" fill="#ffffff"/>
							</svg>
						</span>
					</div>
				<?php endif; ?>
				<div class="esf-yt-feed__card-body">
					<?php if ( $show_title && $title ) : ?>
						<h3 class="esf-yt-feed__card-title"><?php echo esc_html( $title ); ?></h3>
					<?php endif; ?>
					<?php if ( $show_desc && $desc ) : ?>
						<p class="esf-yt-feed__card-desc"><?php echo esc_html( wp_trim_words( $desc, 15 ) ); ?></p>
					<?php endif; ?>
					<?php
					$meta_html = $this->render_video_meta_row(
						array(
							'published'     => $published,
							'show_date'     => $show_date,
							'view_count'    => $view_count,
							'like_count'    => $like_count,
							'comment_count' => $comment_count,
							'show_metrics'  => $show_views,
						)
					);
					if ( '' !== $meta_html ) {
						echo $meta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					}
					?>
				</div>
			</a>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * Header stats as icon + compact count with tooltips (LinkedIn / X style).
	 *
	 * @since 6.7.5
	 * @return string
	 */
	protected function render_header_stats(): string {
		if ( ! $this->account ) {
			return '';
		}

		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$header   = isset( $settings['header'] ) && is_array( $settings['header'] )
			? $settings['header']
			: array();

		// Prefer split toggles; fall back to legacy show_stats when missing.
		$legacy_stats       = array_key_exists( 'show_stats', $header )
			? ! empty( $header['show_stats'] )
			: true;
		$show_subscribers   = array_key_exists( 'show_subscribers', $header )
			? ! empty( $header['show_subscribers'] )
			: $legacy_stats;
		$show_video_count   = array_key_exists( 'show_video_count', $header )
			? ! empty( $header['show_video_count'] )
			: $legacy_stats;
		$show_channel_views = ! isset( $header['show_channel_views'] ) || ! empty( $header['show_channel_views'] );
		$can_show_views     = $show_channel_views
			&& function_exists( 'esf_youtube_has_youtube_plan' )
			&& esf_youtube_has_youtube_plan();

		$items = '';

		$subscribers = isset( $this->account->subscriber_count ) ? (int) $this->account->subscriber_count : 0;
		if ( $show_subscribers && $subscribers > 0 ) {
			$label  = __( 'Subscribers', 'easy-facebook-likebox' );
			$items .= sprintf(
				'<span class="esf-yt-feed__header-stat esf-yt-feed__header-stat--subscribers esf-yt-tooltip-target" %1$s>%2$s<span class="esf-yt-feed__header-stat-count">%3$s</span></span>',
				Tooltip::attributes( $label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$this->header_stat_icon_svg( 'subscribers' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
				esc_html( NumberFormatter::compact( $subscribers ) )
			);
		}

		$videos      = isset( $this->account->video_count ) ? (int) $this->account->video_count : 0;
		$video_label = __( 'Videos', 'easy-facebook-likebox' );
		$feed_type   = isset( $this->feed->feed_type ) ? sanitize_key( (string) $this->feed->feed_type ) : 'channel';
		if ( 'search' === $feed_type && isset( $this->feed->search_total_results ) ) {
			$videos      = max( 0, (int) $this->feed->search_total_results );
			$video_label = __( 'Matching videos', 'easy-facebook-likebox' );
		}
		if (
			$show_video_count
			&& ( $videos > 0 || ( 'search' === $feed_type && isset( $this->feed->search_total_results ) ) )
		) {
			$items .= sprintf(
				'<span class="esf-yt-feed__header-stat esf-yt-feed__header-stat--videos esf-yt-tooltip-target" %1$s>%2$s<span class="esf-yt-feed__header-stat-count">%3$s</span></span>',
				Tooltip::attributes( $video_label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$this->header_stat_icon_svg( 'videos' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
				esc_html( NumberFormatter::compact( $videos ) )
			);
		}

		// Pro: channel all-time view count from YouTube statistics.viewCount.
		$channel_views = isset( $this->account->view_count ) ? (int) $this->account->view_count : 0;
		if ( $can_show_views && $channel_views > 0 ) {
			$views_label = __( 'Views', 'easy-facebook-likebox' );
			$items      .= sprintf(
				'<span class="esf-yt-feed__header-stat esf-yt-feed__header-stat--views esf-yt-tooltip-target" %1$s>%2$s<span class="esf-yt-feed__header-stat-count">%3$s</span></span>',
				Tooltip::attributes( $views_label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$this->header_stat_icon_svg( 'views' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
				esc_html( NumberFormatter::compact( $channel_views ) )
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return '<div class="esf-yt-feed__header-stats">' . $items . '</div>';
	}

	/**
	 * Inline SVG for a header stat icon.
	 *
	 * @since 6.7.5
	 * @param string $slug Icon slug.
	 * @return string
	 */
	protected function header_stat_icon_svg( string $slug ): string {
		switch ( $slug ) {
			case 'subscribers':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>';
			case 'videos':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 16 12 10 15 10 9" fill="currentColor" stroke="none"/></svg>';
			case 'views':
				// YouTube-style ascending chart icon for all-time channel views.
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 14l4-4 4 4 5-6"/><path d="M17 8h3v3"/></svg>';
			default:
				return '';
		}
	}

	/**
	 * Combined meta row: date + engagement icons (YouTube-style single muted line).
	 *
	 * @since 6.7.5
	 * @param array $args {
	 *     @type string $published     ISO date string.
	 *     @type bool   $show_date     Whether to show the date.
	 *     @type int    $view_count    Views.
	 *     @type int    $like_count    Likes.
	 *     @type int    $comment_count Comments.
	 *     @type bool   $show_metrics  Whether to show engagement icons.
	 *     @type string $time_class    Optional time element class override.
	 * }
	 * @return string
	 */
	protected function render_video_meta_row( array $args ): string {
		$published     = isset( $args['published'] ) ? (string) $args['published'] : '';
		$show_date     = ! empty( $args['show_date'] );
		$view_count    = isset( $args['view_count'] ) ? (int) $args['view_count'] : 0;
		$like_count    = isset( $args['like_count'] ) ? (int) $args['like_count'] : 0;
		$comment_count = isset( $args['comment_count'] ) ? (int) $args['comment_count'] : 0;
		$show_metrics  = ! empty( $args['show_metrics'] );
		$time_class    = isset( $args['time_class'] ) ? (string) $args['time_class'] : 'esf-yt-feed__meta-date';

		$parts = '';

		if ( $show_date && '' !== $published ) {
			$parts .= sprintf(
				'<time class="%1$s" datetime="%2$s">%3$s</time>',
				esc_attr( $time_class ),
				esc_attr( $published ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $published ) ) )
			);
		}

		if ( $show_metrics ) {
			$metrics_html = $this->render_video_metrics(
				array(
					'views'    => $view_count,
					'likes'    => $like_count,
					'comments' => $comment_count,
				),
				array(
					'views'    => $view_count > 0,
					'likes'    => $like_count > 0,
					'comments' => $comment_count > 0,
				)
			);
			$parts       .= $metrics_html;
		}

		if ( '' === $parts ) {
			return '';
		}

		return '<div class="esf-yt-feed__meta">' . $parts . '</div>';
	}

	/**
	 * Video engagement metrics as icons + tooltips.
	 *
	 * @since 6.7.5
	 * @param array<string,int>  $metrics Metric values.
	 * @param array<string,bool> $show    Which metrics to render.
	 * @return string
	 */
	protected function render_video_metrics( array $metrics, array $show ): string {
		return PostMeta::render(
			'yt',
			$metrics,
			array(
				'views'    => __( 'Views', 'easy-facebook-likebox' ),
				'likes'    => __( 'Likes', 'easy-facebook-likebox' ),
				'comments' => __( 'Comments', 'easy-facebook-likebox' ),
			),
			$show,
			'div'
		);
	}

	/**
	 * Escaped data-post-id attribute for moderation / free-teaser parsers.
	 *
	 * @since 6.7.9
	 *
	 * @param array $video Video row.
	 * @return string Attribute including leading space, or empty string.
	 */
	protected function get_post_id_attr( array $video ) {
		$id = '';
		if ( ! empty( $video['video_id'] ) ) {
			$id = (string) $video['video_id'];
		} elseif ( ! empty( $video['id'] ) ) {
			$id = (string) $video['id'];
		}

		if ( '' === $id ) {
			return '';
		}

		return ' data-post-id="' . esc_attr( $id ) . '"';
	}

	/**
	 * Effective playback mode for this layout instance.
	 *
	 * @since 6.18.0
	 * @return string new_tab|showcase|popup
	 */
	protected function get_playback_mode() {
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$slug     = ( null !== $this->definition && method_exists( $this->definition, 'get_slug' ) )
			? (string) $this->definition->get_slug()
			: '';

		if ( function_exists( 'esf_youtube_resolve_playback_mode' ) ) {
			return esf_youtube_resolve_playback_mode( $settings, $slug );
		}

		return 'new_tab';
	}

	/**
	 * Build a lightweight showcase item payload for a video.
	 *
	 * @since 6.18.0
	 *
	 * @param array $video Normalized video row.
	 * @return array<string,mixed>
	 */
	protected function build_showcase_item_payload( array $video ) {
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();

		$video_id = isset( $video['video_id'] ) ? (string) $video['video_id'] : ( isset( $video['id'] ) ? (string) $video['id'] : '' );
		$title    = isset( $video['title'] ) ? (string) $video['title'] : '';
		$thumb    = isset( $video['thumbnail_url'] ) ? (string) $video['thumbnail_url'] : '';
		$thumb_full = isset( $video['thumbnail_url_full'] ) && '' !== (string) $video['thumbnail_url_full']
			? (string) $video['thumbnail_url_full']
			: $thumb;

		$content_filter = function_exists( 'esf_youtube_content_filter_from_settings' )
			? esf_youtube_content_filter_from_settings( $settings )
			: 'all';
		$is_short       = function_exists( 'esf_youtube_should_render_as_short' )
			? esf_youtube_should_render_as_short( $video, $content_filter )
			: ( 'shorts' === $content_filter );

		$embed_url = function_exists( 'esf_youtube_build_showcase_embed_url' )
			? esf_youtube_build_showcase_embed_url(
				$video_id,
				array(
					'autoplay' => true,
					'mute'     => false,
				)
			)
			: '';

		$payload = array(
			'video_id'     => $video_id,
			'title'        => $title,
			'embed_url'    => $embed_url,
			'watch_url'    => $video_id ? 'https://www.youtube.com/watch?v=' . $video_id : '',
			'poster_url'   => $thumb_full,
			'aspect_ratio' => function_exists( 'esf_youtube_resolve_showcase_aspect' )
				? esf_youtube_resolve_showcase_aspect( $settings )
				: ( 'shorts' === $content_filter ? '9:16' : '16:9' ),
			'is_short'     => (bool) $is_short,
		);

		if ( class_exists( '\ESF_GDPR_Integrations' ) ) {
			$payload = \ESF_GDPR_Integrations::sanitize_popup_payload( $payload, 'youtube' );
		}

		return $payload;
	}

	/**
	 * Escaped data-esf-showcase-item attribute for a video card/tile.
	 *
	 * @since 6.18.0
	 *
	 * @param array $video Normalized video.
	 * @return string Attribute including leading space, or empty string.
	 */
	protected function get_showcase_item_attrs( array $video ) {
		$payload = $this->build_showcase_item_payload( $video );
		if ( empty( $payload['video_id'] ) ) {
			return '';
		}

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return '';
		}

		return ' data-esf-showcase-item="' . esc_attr( $encoded ) . '"';
	}

	/**
	 * Whether this feed currently plays videos in the inline Video Showcase.
	 *
	 * @since 6.18.0
	 * @return bool
	 */
	public function is_showcase_mode() {
		return 'showcase' === $this->get_playback_mode();
	}

	/**
	 * Consume the "next rendered item is the featured one" flag.
	 *
	 * @since 6.18.0
	 * @return bool True once, right after the stage was rendered.
	 */
	protected function take_showcase_active() {
		$pending                       = $this->showcase_active_pending;
		$this->showcase_active_pending = false;
		return $pending;
	}

	/**
	 * Showcase attributes/classes for layouts that render their own item markup.
	 *
	 * Shared by every layout so cards, tiles, cells and slides all expose the
	 * same `data-esf-showcase-item` contract to `esf-showcase.js`. Returns empty
	 * values outside showcase mode so existing popup / new-tab output is unchanged.
	 *
	 * @since 6.18.0
	 *
	 * @param array $video Normalized video row.
	 * @return array{attrs:string,classes:string[],active:bool}
	 */
	public function get_showcase_cell_markup( array $video ) {
		$empty = array(
			'attrs'   => '',
			'classes' => array(),
			'active'  => false,
		);
		if ( ! $this->is_showcase_mode() ) {
			return $empty;
		}

		$attrs = $this->get_showcase_item_attrs( $video );
		if ( '' === $attrs ) {
			return $empty;
		}

		$active  = $this->take_showcase_active();
		$classes = array( 'esf-yt-feed__card--showcase' );
		if ( $active ) {
			$classes[] = 'is-esf-showcase-active';
			$attrs    .= ' aria-current="true"';
		}

		if ( function_exists( 'esf_youtube_flag_showcase_script' ) ) {
			esf_youtube_flag_showcase_script();
		}

		return array(
			'attrs'   => $attrs,
			'classes' => $classes,
			'active'  => $active,
		);
	}

	/**
	 * Render the shared Video Showcase stage for the first visible video.
	 *
	 * @since 6.18.0
	 *
	 * @param array $videos Videos about to be displayed (already sliced).
	 * @return string
	 */
	public function render_showcase_stage_html( array $videos ) {
		if ( ! $this->is_showcase_mode() || empty( $videos ) ) {
			return '';
		}

		$first = null;
		foreach ( $videos as $video ) {
			if ( is_array( $video ) ) {
				$first = $video;
				break;
			}
		}
		if ( null === $first ) {
			return '';
		}

		$item = $this->build_showcase_item_payload( $first );
		if ( empty( $item['video_id'] ) ) {
			return '';
		}

		// sanitize_popup_payload() blanks embed_url when third-party media is blocked.
		$blocked = empty( $item['embed_url'] );

		$play_label = function_exists( 'esf_translate_registry_string' )
			? esf_translate_registry_string( 'yt_showcase_play', 'Play video' )
			: __( 'Play video', 'easy-facebook-likebox' );
		if ( ! empty( $item['title'] ) ) {
			$play_label = sprintf(
				/* translators: %s: video title */
				__( 'Play %s', 'easy-facebook-likebox' ),
				(string) $item['title']
			);
		}

		if ( function_exists( 'esf_youtube_flag_showcase_script' ) ) {
			esf_youtube_flag_showcase_script();
		}

		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$cfg      = function_exists( 'esf_youtube_get_showcase_settings' )
			? esf_youtube_get_showcase_settings( $settings )
			: array(
				'max_width'   => 0,
				'align'       => 'center',
				'radius'      => -1,
				'now_playing' => true,
			);

		$now_playing_label = '';
		if ( ! empty( $cfg['now_playing'] ) ) {
			$now_playing_label = function_exists( 'esf_translate_registry_string' )
				? esf_translate_registry_string( 'yt_showcase_now_playing', 'Now playing' )
				: __( 'Now playing', 'easy-facebook-likebox' );
		}

		$stage_html = ShowcaseStage::render(
			'youtube',
			array(
				'poster_url'        => isset( $item['poster_url'] ) ? (string) $item['poster_url'] : '',
				'embed_url'         => isset( $item['embed_url'] ) ? (string) $item['embed_url'] : '',
				'play_label'        => $play_label,
				'aspect_ratio'      => isset( $item['aspect_ratio'] ) ? (string) $item['aspect_ratio'] : '16:9',
				'item'              => $item,
				'blocked'           => $blocked,
				'max_width'         => (int) $cfg['max_width'],
				'align'             => (string) $cfg['align'],
				'radius'            => (int) $cfg['radius'],
				'now_playing_label' => $now_playing_label,
				'allowed_hosts'     => function_exists( 'esf_youtube_showcase_embed_hosts' )
					? esf_youtube_showcase_embed_hosts()
					: array(),
			)
		);

		// The next item rendered is the one the stage is showing.
		$this->showcase_active_pending = ( '' !== $stage_html );

		return $stage_html;
	}
}
