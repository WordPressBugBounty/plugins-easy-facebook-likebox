<?php
/**
 * MediaTile primitive.
 *
 * Renders the single-tile media slot for a post (used by grid-style layouts
 * such as Instagram Grid and YouTube Grid). Video posts get a play-icon
 * overlay; multi-photo posts get a stack icon.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Primitives;

use EasySocialFeed\Layouts\Support\NumberFormatter;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MediaTile {

	/**
	 * Render a tile for a post's primary media item.
	 *
	 * @param string         $module      Module slug.
	 * @param PostMedia|null $primary     Primary media item.
	 * @param int            $extra_count Number of additional media items (for carousel/album badge).
	 * @param string         $permalink   Permalink to wrap the tile in.
	 * @param string         $alt_text    Fallback alt text (e.g. account name).
	 * @param array          $options     Optional flags:
	 *                                      - new_tab (bool, default true): open links in new tab.
	 *                                      - show_overlay (bool, default true): hover darken on tile.
	 *                                      - show_hover_plus (bool, default false): centered "+" on hover.
	 *                                      - show_hover_likes (bool, default false): likes count on hover overlay.
	 *                                      - show_hover_comments (bool, default false): comments count on hover overlay.
	 *                                      - likes_count (int, default 0): likes metric for hover overlay.
	 *                                      - comments_count (int, default 0): comments metric for hover overlay.
	 *                                      - show_media_type_icon (bool, default true): show video/carousel icon.
	 *                                      - prefer_preview (bool, default false): use preview_url for tile img when set.
	 *                                      - popup_trigger (bool, default false): mark tile as opening a lightbox.
	 *                                      - variation_seed (string, default ''): stable seed for CSS poster variation.
	 * @return string Tile HTML, or empty string when no media.
	 */
	public static function render(
		string $module,
		?PostMedia $primary,
		int $extra_count,
		string $permalink,
		string $alt_text = '',
		array $options = array()
	): string {
		if ( null === $primary ) {
			return '';
		}

		$module = sanitize_key( $module );

		$defaults = array(
			'new_tab'              => true,
			'show_overlay'         => true,
			'show_hover_plus'      => false,
			'show_hover_likes'     => false,
			'show_hover_comments'  => false,
			'likes_count'          => 0,
			'comments_count'       => 0,
			'show_media_type_icon' => true,
			'prefer_preview'       => false,
			'popup_trigger'        => false,
			'shoppable_label'      => '',
		);
		$options  = array_replace( $defaults, $options );

		$show_hover_likes    = (bool) $options['show_hover_likes'];
		$show_hover_comments = (bool) $options['show_hover_comments'];
		$likes_count         = (int) $options['likes_count'];
		$comments_count      = (int) $options['comments_count'];
		$show_likes_stat     = $show_hover_likes && $likes_count > 0;
		$show_comments_stat  = $show_hover_comments && $comments_count > 0;
		$has_hover_content   = (bool) $options['show_overlay']
			&& (
				(bool) $options['show_hover_plus']
				|| $show_likes_stat
				|| $show_comments_stat
			);

		$image_url = '';
		if ( ! empty( $options['prefer_preview'] ) ) {
			$preview = $primary->get_preview_url();
			if ( '' !== $preview ) {
				$image_url = $preview;
			}
		}
		if ( '' === $image_url ) {
			$image_url = $primary->get_display_url();
		}

		$is_video       = $primary->is_video();
		$is_no_poster   = $primary->has_placeholder_poster();
		$is_carousel    = $extra_count > 0;
		$variation_seed = isset( $options['variation_seed'] ) ? (string) $options['variation_seed'] : '';

		if ( $is_no_poster ) {
			if ( ! $is_video || '' === $primary->get_video_url() ) {
				return '';
			}
		} elseif ( '' === $image_url ) {
			return '';
		}

		$tile_class = "esf-{$module}-feed__tile";
		if ( $is_video ) {
			$tile_class .= " esf-{$module}-feed__tile--video";
		}
		if ( $is_no_poster ) {
			$tile_class .= " esf-{$module}-feed__tile--no-poster";
			$tile_class .= ' esf-' . $module . '-feed__tile--no-poster-v' . self::no_poster_variation_index( $variation_seed );
		}
		if ( $is_carousel ) {
			$tile_class .= " esf-{$module}-feed__tile--carousel";
		}
		if ( $options['show_overlay'] ) {
			$tile_class .= " esf-{$module}-feed__tile--hover-overlay";
		}
		if ( $options['show_overlay'] && $options['show_hover_plus'] ) {
			$tile_class .= " esf-{$module}-feed__tile--hover-plus";
		}
		if ( $options['show_overlay'] && ( $show_likes_stat || $show_comments_stat ) ) {
			$tile_class .= " esf-{$module}-feed__tile--hover-stats";
		}

		$show_type_icon = (bool) $options['show_media_type_icon'];
		$show_video_icon = $show_type_icon && $is_video && ! $is_no_poster;
		$overlay        = '';
		if ( $has_hover_content ) {
			$overlay = self::render_hover_overlay(
				$module,
				$show_video_icon,
				$is_carousel && $show_type_icon,
				(bool) $options['show_hover_plus'],
				$show_likes_stat,
				$show_comments_stat,
				$likes_count,
				$comments_count
			);
		} elseif ( $show_type_icon && ( ( $is_video && ! $is_no_poster ) || $is_carousel ) ) {
			$overlay = self::render_corner_overlay( $module, $is_video, $is_carousel );
		}

		$popup_attr = '';
		if ( ! empty( $options['popup_trigger'] ) ) {
			$popup_attr = sprintf( ' data-esf-%1$s-popup-trigger="1"', esc_attr( $module ) );
		}

		$aria_label_attr = '';
		if ( $is_no_poster ) {
			$aria_label_attr = ' aria-label="' . esc_attr( __( 'Video', 'easy-facebook-likebox' ) ) . '"';
		}

		if ( '' !== $permalink ) {
			$target_rel = $options['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : ' target="_self" rel="noopener"';
			$open_tag   = sprintf(
				'<a class="%1$s" href="%2$s"%3$s%4$s%5$s>',
				esc_attr( $tile_class ),
				esc_url( $permalink ),
				$target_rel,
				$popup_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static data attribute.
				$aria_label_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped attribute fragment.
			);
			$close_tag = '</a>';
		} else {
			$open_tag  = sprintf( '<div class="%1$s"%2$s>', esc_attr( $tile_class ), $aria_label_attr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped attribute fragment.
			$close_tag = '</div>';
		}

		$img_alt = '' !== $primary->get_alt() ? $primary->get_alt() : $alt_text;

		$dimension_attrs = '';
		$width           = $primary->get_width();
		$height          = $primary->get_height();
		if ( $width > 0 && $height > 0 ) {
			$dimension_attrs = sprintf( ' width="%d" height="%d"', $width, $height );
		}

		$shoppable_badge = '';
		$shoppable_label = isset( $options['shoppable_label'] ) ? trim( (string) $options['shoppable_label'] ) : '';
		if ( '' !== $shoppable_label ) {
			$shoppable_badge = sprintf(
				'<span class="esf-%1$s-feed__tile-shoppable-badge">%2$s</span>',
				esc_attr( $module ),
				esc_html( $shoppable_label )
			);
		}

		$media_markup = '';
		if ( $is_no_poster ) {
			$media_markup = self::render_no_poster_surface(
				$module,
				self::no_poster_variation_index( $variation_seed )
			);
		} else {
			$media_markup = sprintf(
				'<img class="esf-%1$s-feed__tile-img" src="%2$s" alt="%3$s" loading="lazy"%4$s />',
				esc_attr( $module ),
				esc_url( $image_url ),
				esc_attr( $img_alt ),
				$dimension_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer dimensions.
			);
		}

		return $open_tag
			. $media_markup
			. $overlay
			. $shoppable_badge
			. $close_tag;
	}

	/**
	 * Render hover overlay with optional "+" and engagement stats.
	 */
	private static function render_hover_overlay(
		string $module,
		bool $is_video,
		bool $is_carousel,
		bool $show_plus,
		bool $show_likes,
		bool $show_comments,
		int $likes_count,
		int $comments_count
	): string {
		$icons = self::render_type_icons_markup( $module, $is_video, $is_carousel );

		$inner = '';
		if ( $show_plus ) {
			$inner .= sprintf(
				'<span class="esf-%1$s-feed__tile-hover-overlay-plus" aria-hidden="true">+</span>',
				esc_attr( $module )
			);
		}

		$stats = self::render_hover_stats_markup(
			$module,
			$likes_count,
			$comments_count,
			$show_likes,
			$show_comments
		);
		if ( '' !== $stats ) {
			$inner .= $stats;
		}

		if ( '' !== $inner ) {
			$inner = sprintf(
				'<span class="esf-%1$s-feed__tile-hover-overlay-inner" aria-hidden="true">%2$s</span>',
				esc_attr( $module ),
				$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
			);
		}

		return sprintf(
			'<span class="esf-%1$s-feed__tile-hover-overlay" aria-hidden="true">%2$s%3$s'
			. '</span>',
			esc_attr( $module ),
			$inner, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
			$icons // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG markup.
		);
	}

	/**
	 * Render likes/comments row for the hover overlay.
	 */
	private static function render_hover_stats_markup(
		string $module,
		int $likes_count,
		int $comments_count,
		bool $show_likes,
		bool $show_comments
	): string {
		$items = '';

		if ( $show_likes && $likes_count > 0 ) {
			$items .= sprintf(
				'<span class="esf-%1$s-feed__tile-hover-stat esf-%1$s-feed__tile-hover-stat--likes" data-hover-stat="likes" aria-hidden="true">%2$s<span class="esf-%1$s-feed__tile-hover-stat-count">%3$s</span></span>',
				esc_attr( $module ),
				self::hover_likes_icon(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG markup.
				esc_html( NumberFormatter::compact( $likes_count ) )
			);
		}

		if ( $show_comments && $comments_count > 0 ) {
			$items .= sprintf(
				'<span class="esf-%1$s-feed__tile-hover-stat esf-%1$s-feed__tile-hover-stat--comments" data-hover-stat="comments" aria-hidden="true">%2$s<span class="esf-%1$s-feed__tile-hover-stat-count">%3$s</span></span>',
				esc_attr( $module ),
				self::hover_comments_icon(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG markup.
				esc_html( NumberFormatter::compact( $comments_count ) )
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return sprintf(
			'<span class="esf-%1$s-feed__tile-hover-stats" aria-hidden="true">%2$s</span>',
			esc_attr( $module ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
		);
	}

	/**
	 * Heart icon for hover likes stat.
	 */
	private static function hover_likes_icon(): string {
		return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
	}

	/**
	 * Comment bubble icon for hover comments stat.
	 */
	private static function hover_comments_icon(): string {
		return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
	}

	/**
	 * Render always-visible corner badges (video / carousel) when hover overlay is off.
	 */
	private static function render_corner_overlay( string $module, bool $is_video, bool $is_carousel ): string {
		$icons = self::render_type_icons_markup( $module, $is_video, $is_carousel );
		if ( '' === $icons ) {
			return '';
		}

		return sprintf(
			'<span class="esf-%1$s-feed__tile-overlay" aria-hidden="true">%2$s</span>',
			esc_attr( $module ),
			$icons // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG markup.
		);
	}

	/**
	 * SVG markup for video / carousel type badges.
	 */
	private static function render_type_icons_markup( string $module, bool $is_video, bool $is_carousel ): string {
		$icons = '';
		if ( $is_video ) {
			$icons .= sprintf(
				'<span class="esf-%1$s-feed__tile-overlay-icon esf-%1$s-feed__tile-overlay-icon--video" aria-hidden="true">'
				. '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="8 5 19 12 8 19 8 5"></polygon></svg>'
				. '</span>',
				esc_attr( $module )
			);
		}
		if ( $is_carousel ) {
			$icons .= sprintf(
				'<span class="esf-%1$s-feed__tile-overlay-icon esf-%1$s-feed__tile-overlay-icon--carousel" aria-hidden="true">'
				. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="8" y="8" width="12" height="12" rx="2"></rect><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"></path></svg>'
				. '</span>',
				esc_attr( $module )
			);
		}

		return $icons;
	}

	/**
	 * Stable variation bucket (0–5) for CSS poster surfaces.
	 *
	 * @param string $seed Post/media identifier.
	 */
	private static function no_poster_variation_index( string $seed ): int {
		if ( '' === $seed ) {
			return 0;
		}

		return (int) ( hexdec( substr( md5( $seed ), 0, 8 ) ) % 6 );
	}

	/**
	 * Render a theme-aware CSS poster surface for videos without thumbnails.
	 *
	 * @param string $module    Module slug.
	 * @param int    $variation Variation bucket (0–5).
	 */
	private static function render_no_poster_surface( string $module, int $variation ): string {
		$variation = max( 0, min( 5, $variation ) );

		return sprintf(
			'<span class="esf-%1$s-feed__tile-poster-surface esf-%1$s-feed__tile-poster-surface--v%2$d" aria-hidden="true">'
			. '<span class="esf-%1$s-feed__tile-poster-play">%3$s</span>'
			. '</span>',
			esc_attr( $module ),
			$variation,
			self::centered_play_icon() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG markup.
		);
	}

	/**
	 * Centered play icon for CSS poster surfaces.
	 */
	private static function centered_play_icon(): string {
		return '<svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
			. '<polygon points="8 5 19 12 8 19 8 5"></polygon>'
			. '</svg>';
	}
}
