<?php
/**
 * PostCard primitive.
 *
 * Renders a generic post card built from a PostItem. Tile-style layouts
 * (Instagram Grid, YouTube Grid) typically call MediaTile directly instead;
 * this primitive is used by feed-style layouts (Timeline, list) and as the
 * popup body.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Primitives;

use EasySocialFeed\Layouts\Contracts\PostItem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PostCard {

	/**
	 * Render a post card.
	 *
	 * Options:
	 * - show_text (bool)
	 * - show_media (bool)
	 * - show_meta (bool)
	 * - metric_labels (array<string,string>)
	 * - metric_show (array<string,bool>)
	 *
	 * @param string  $module  Module slug.
	 * @param PostItem $post    The post.
	 * @param array<string,mixed> $options Rendering options.
	 */
	public static function render( string $module, PostItem $post, array $options = array() ): string {
		$module     = sanitize_key( $module );
		$show_text  = ! isset( $options['show_text'] ) || (bool) $options['show_text'];
		$show_media = ! isset( $options['show_media'] ) || (bool) $options['show_media'];
		$show_meta  = ! isset( $options['show_meta'] ) || (bool) $options['show_meta'];

		$permalink = $post->get_permalink();
		$author    = $post->get_author();
		$avatar    = $author ? $author->get_avatar_url() : '';
		$name      = $author ? $author->get_name() : '';

		$header_html = '';
		if ( '' !== $name || '' !== $avatar ) {
			$avatar_html = '' !== $avatar
				? sprintf(
					'<img class="esf-%1$s-feed__card-avatar" src="%2$s" alt="%3$s" width="36" height="36" loading="lazy" />',
					esc_attr( $module ),
					esc_url( $avatar ),
					esc_attr( $name )
				)
				: '';
			$header_html = sprintf(
				'<header class="esf-%1$s-feed__card-header">%2$s<div class="esf-%1$s-feed__card-author"><span class="esf-%1$s-feed__card-name">%3$s</span></div></header>',
				esc_attr( $module ),
				$avatar_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
				esc_html( $name )
			);
		}

		$text_html = '';
		if ( $show_text && '' !== $post->get_text_html() ) {
			$text_html = sprintf(
				'<div class="esf-%1$s-feed__card-text">%2$s</div>',
				esc_attr( $module ),
				$post->get_text_html() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PostItem guarantees escaped HTML.
			);
		}

		$media_html = '';
		if ( $show_media && $post->get_media() ) {
			$primary     = $post->get_media()[0];
			$extra_count = max( 0, count( $post->get_media() ) - 1 );
			$media_html  = sprintf(
				'<div class="esf-%1$s-feed__card-media">%2$s</div>',
				esc_attr( $module ),
				MediaTile::render( $module, $primary, $extra_count, $permalink, $name ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaTile returns escaped markup.
			);
		}

		$meta_html = '';
		if ( $show_meta ) {
			$meta_html = PostMeta::render(
				$module,
				$post->get_metrics(),
				isset( $options['metric_labels'] ) && is_array( $options['metric_labels'] ) ? $options['metric_labels'] : array(),
				isset( $options['metric_show'] ) && is_array( $options['metric_show'] ) ? $options['metric_show'] : array()
			);
		}

		return sprintf(
			'<article class="esf-%1$s-feed__card">%2$s%3$s%4$s%5$s</article>',
			esc_attr( $module ),
			$header_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
			$text_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
			$media_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
			$meta_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
		);
	}
}
