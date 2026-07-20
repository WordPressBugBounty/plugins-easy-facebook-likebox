<?php
/**
 * StackedPostCard primitive.
 *
 * Vertical post card: full-width media on top, post header + expandable caption
 * below, engagement footer. Powers the modern Instagram Full Width layout and
 * is reusable by Facebook Full Width later.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Primitives;

use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stacked (full-width / timeline-style) post card.
 *
 * @since 6.9.0
 */
final class StackedPostCard {

	/**
	 * Render a stacked post card.
	 *
	 * Options:
	 * - show_header (bool, default true)
	 * - show_time (bool, default true)
	 * - show_caption (bool, default true)
	 * - caption_words (int, default 25)
	 * - show_likes / show_comments / show_share (bool)
	 * - metric_labels (array<string,string>)
	 * - media_options (array): forwarded to MediaTile / MediaCollage
	 * - media_html (string): optional pre-rendered media (skips MediaTile / MediaCollage)
	 * - permalink (string): override post permalink for media / share
	 * - new_tab (bool)
	 * - see_more_label / see_less_label (string)
	 * - share_label (string)
	 * - share_networks (array): forwarded to PostShareMenu
	 * - time_label (string): preformatted relative time
	 * - footer_extra (string): optional HTML after metrics (e.g. CTA)
	 * - media_position (string): `header_first` (default) or `media_first`
	 *
	 * @param string              $module  Module slug.
	 * @param PostItem            $post    Post value object.
	 * @param array<string,mixed> $options Rendering options.
	 * @return string
	 */
	public static function render( string $module, PostItem $post, array $options = array() ): string {
		$module = sanitize_key( $module );

		$show_header   = ! isset( $options['show_header'] ) || (bool) $options['show_header'];
		$show_time     = ! isset( $options['show_time'] ) || (bool) $options['show_time'];
		$show_caption  = ! isset( $options['show_caption'] ) || (bool) $options['show_caption'];
		$show_likes    = ! isset( $options['show_likes'] ) || (bool) $options['show_likes'];
		$show_comments = ! isset( $options['show_comments'] ) || (bool) $options['show_comments'];
		$show_share    = ! empty( $options['show_share'] );
		$caption_words = isset( $options['caption_words'] ) ? max( 0, (int) $options['caption_words'] ) : 25;
		$new_tab       = ! isset( $options['new_tab'] ) || (bool) $options['new_tab'];

		$permalink = isset( $options['permalink'] ) && is_string( $options['permalink'] ) && '' !== $options['permalink']
			? (string) $options['permalink']
			: $post->get_permalink();

		$author = $post->get_author();
		$name   = $author ? $author->get_name() : '';
		$avatar = $author ? $author->get_avatar_url() : '';

		$media_html = isset( $options['media_html'] ) && is_string( $options['media_html'] )
			? $options['media_html']
			: self::render_media( $module, $post, $permalink, $name, $options );

		$header_html = '';
		if ( $show_header && ( '' !== $name || '' !== $avatar || ( $show_time && '' !== $post->get_created_at() ) ) ) {
			$header_html = self::render_header( $module, $name, $avatar, $post, $show_time, $options, $permalink, $new_tab );
		}

		$caption_html = '';
		if ( $show_caption && '' !== $post->get_text_html() ) {
			$caption_html = ExpandableCaption::render(
				$module,
				$post->get_text_html(),
				array(
					'words_limit'    => $caption_words,
					'post_id'        => $post->get_id(),
					'see_more_label' => isset( $options['see_more_label'] ) ? (string) $options['see_more_label'] : '',
					'see_less_label' => isset( $options['see_less_label'] ) ? (string) $options['see_less_label'] : '',
				)
			);
		}

		$media_position = isset( $options['media_position'] ) && 'media_first' === $options['media_position']
			? 'media_first'
			: 'header_first';

		$media_block = '' !== $media_html
			? sprintf( '<div class="esf-%1$s-feed__stacked-media">%2$s</div>', esc_attr( $module ), $media_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			: '';

		$header_block = '' !== $header_html
			? sprintf(
				'<div class="esf-%1$s-feed__stacked-content esf-%1$s-feed__stacked-content--header">%2$s</div>',
				esc_attr( $module ),
				$header_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			)
			: '';

		$caption_block = '' !== $caption_html
			? sprintf(
				'<div class="esf-%1$s-feed__stacked-content esf-%1$s-feed__stacked-content--caption">%2$s</div>',
				esc_attr( $module ),
				$caption_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			)
			: '';

		$combined_content_block = '';
		if ( '' !== $header_html || '' !== $caption_html ) {
			$combined_content_block = sprintf(
				'<div class="esf-%1$s-feed__stacked-content">%2$s%3$s</div>',
				esc_attr( $module ),
				$header_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$caption_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		$footer_html = self::render_footer( $module, $post, $permalink, $show_likes, $show_comments, $show_share, $options );

		$card_class = 'esf-' . $module . '-feed__stacked-card esf-' . $module . '-feed__stacked-card--' . $media_position;

		if ( 'media_first' === $media_position ) {
			$main_html = $media_block . $combined_content_block;
		} else {
			$main_html = $header_block . $media_block . $caption_block;
		}

		return sprintf(
			'<article class="%1$s" data-post-id="%2$s">%3$s%4$s</article>',
			esc_attr( $card_class ),
			esc_attr( $post->get_id() ),
			$main_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$footer_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Render media via MediaCollage (multi) or MediaTile (single).
	 *
	 * @param string              $module    Module slug.
	 * @param PostItem            $post      Post.
	 * @param string              $permalink Permalink.
	 * @param string              $alt_text  Image alt.
	 * @param array<string,mixed> $options   Card options.
	 * @return string
	 */
	private static function render_media( string $module, PostItem $post, string $permalink, string $alt_text, array $options ): string {
		$media = $post->get_media();
		if ( empty( $media ) ) {
			return '';
		}

		$media_options = isset( $options['media_options'] ) && is_array( $options['media_options'] )
			? $options['media_options']
			: array();

		$metrics = $post->get_metrics();
		if ( ! isset( $media_options['likes_count'] ) ) {
			$media_options['likes_count'] = isset( $metrics['likes'] ) ? (int) $metrics['likes'] : 0;
		}
		if ( ! isset( $media_options['comments_count'] ) ) {
			$media_options['comments_count'] = isset( $metrics['comments'] ) ? (int) $metrics['comments'] : 0;
		}
		if ( ! isset( $media_options['variation_seed'] ) ) {
			$media_options['variation_seed'] = $post->get_id();
		}
		if ( ! isset( $media_options['variant'] ) ) {
			$media_options['variant'] = 'card';
		}
		if ( ! isset( $media_options['show_hover_plus'] ) ) {
			$media_options['show_hover_plus'] = true;
		}

		$use_collage = ! isset( $media_options['use_collage'] ) || (bool) $media_options['use_collage'];

		if ( $use_collage && count( $media ) > 1 ) {
			return MediaCollage::render(
				$module,
				$media,
				$permalink,
				$alt_text,
				$media_options
			);
		}

		$primary = $media[0];
		if ( ! $primary instanceof PostMedia ) {
			return '';
		}

		return MediaTile::render(
			$module,
			$primary,
			max( 0, count( $media ) - 1 ),
			$permalink,
			$alt_text,
			$media_options
		);
	}

	/**
	 * Render authorship header.
	 *
	 * @param string              $module    Module slug.
	 * @param string              $name      Author name.
	 * @param string              $avatar    Avatar URL.
	 * @param PostItem            $post      Post.
	 * @param bool                $show_time Whether to show relative time.
	 * @param array<string,mixed> $options   Card options.
	 * @param string              $permalink Profile / post link target.
	 * @param bool                $new_tab   Open links in new tab.
	 * @return string
	 */
	private static function render_header(
		string $module,
		string $name,
		string $avatar,
		PostItem $post,
		bool $show_time,
		array $options,
		string $permalink,
		bool $new_tab
	): string {
		$avatar_html = '';
		if ( '' !== $avatar ) {
			$img = sprintf(
				'<img class="esf-%1$s-feed__stacked-avatar" src="%2$s" alt="%3$s" width="40" height="40" loading="lazy" decoding="async" />',
				esc_attr( $module ),
				esc_url( $avatar ),
				esc_attr( $name )
			);
			$avatar_html = '' !== $permalink
				? sprintf(
					'<a class="esf-%1$s-feed__stacked-avatar-link" href="%2$s"%3$s>%4$s</a>',
					esc_attr( $module ),
					esc_url( $permalink ),
					$new_tab ? ' target="_blank" rel="noopener noreferrer"' : '',
					$img // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				)
				: $img;
		}

		$name_html = '';
		if ( '' !== $name ) {
			$name_inner = sprintf(
				'<span class="esf-%1$s-feed__stacked-name">%2$s</span>',
				esc_attr( $module ),
				esc_html( $name )
			);
			$name_html = '' !== $permalink
				? sprintf(
					'<a class="esf-%1$s-feed__stacked-name-link" href="%2$s"%3$s>%4$s</a>',
					esc_attr( $module ),
					esc_url( $permalink ),
					$new_tab ? ' target="_blank" rel="noopener noreferrer"' : '',
					$name_inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				)
				: $name_inner;
		}

		$time_html = '';
		if ( $show_time ) {
			$time_label = isset( $options['time_label'] ) ? (string) $options['time_label'] : '';
			if ( '' === $time_label && '' !== $post->get_created_at() && function_exists( 'esf_readable_time_ago' ) ) {
				$time_label = (string) esf_readable_time_ago( $post->get_created_at() );
			}
			if ( '' !== $time_label ) {
				$time_html = sprintf(
					'<time class="esf-%1$s-feed__stacked-time" datetime="%2$s">%3$s</time>',
					esc_attr( $module ),
					esc_attr( $post->get_created_at() ),
					esc_html( $time_label )
				);
			}
		}

		$meta_html = sprintf(
			'<div class="esf-%1$s-feed__stacked-author">%2$s%3$s</div>',
			esc_attr( $module ),
			$name_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$time_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		return sprintf(
			'<header class="esf-%1$s-feed__stacked-header">%2$s%3$s</header>',
			esc_attr( $module ),
			$avatar_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$meta_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Render card footer (metrics + share).
	 *
	 * @param string              $module        Module slug.
	 * @param PostItem            $post          Post.
	 * @param string              $permalink     Share URL.
	 * @param bool                $show_likes    Show likes.
	 * @param bool                $show_comments Show comments.
	 * @param bool                $show_share    Show share menu.
	 * @param array<string,mixed> $options       Card options.
	 * @return string
	 */
	private static function render_footer(
		string $module,
		PostItem $post,
		string $permalink,
		bool $show_likes,
		bool $show_comments,
		bool $show_share,
		array $options
	): string {
		$metrics = $post->get_metrics();
		$labels  = isset( $options['metric_labels'] ) && is_array( $options['metric_labels'] )
			? $options['metric_labels']
			: array();

		$meta_html = PostMeta::render(
			$module,
			$metrics,
			$labels,
			array(
				'likes'    => $show_likes,
				'comments' => $show_comments,
			),
			'div'
		);

		$share_html = '';
		if ( $show_share && '' !== $permalink ) {
			$share_opts = array(
				'label' => isset( $options['share_label'] ) ? (string) $options['share_label'] : '',
			);
			if ( isset( $options['share_networks'] ) && is_array( $options['share_networks'] ) ) {
				$share_opts['networks'] = $options['share_networks'];
			}
			$share_html = PostShareMenu::render( $module, $permalink, $share_opts );
		}

		$extra = isset( $options['footer_extra'] ) && is_string( $options['footer_extra'] )
			? $options['footer_extra']
			: '';

		if ( '' === $meta_html && '' === $share_html && '' === $extra ) {
			return '';
		}

		return sprintf(
			'<footer class="esf-%1$s-feed__stacked-footer">' .
			'<div class="esf-%1$s-feed__stacked-footer-metrics">%2$s</div>' .
			'<div class="esf-%1$s-feed__stacked-footer-actions">%3$s%4$s</div>' .
			'</footer>',
			esc_attr( $module ),
			$meta_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller-supplied pre-escaped.
			$share_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}
