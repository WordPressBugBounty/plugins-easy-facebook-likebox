<?php
/**
 * MediaCollage primitive.
 *
 * Multi-image collage for carousel/album posts:
 * - Default (3): large left + stacked right with "+N more" (Half Width / tiles).
 * - Five (5): 2 on top + 3 on bottom with a large "+N" on the last cell (Full Width).
 *
 * Used by Instagram Half/Full Width cards and tile layouts; reusable by other modules.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.5
 */

namespace EasySocialFeed\Layouts\Primitives;

use EasySocialFeed\Layouts\Support\NumberFormatter;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-image media collage renderer.
 *
 * @since 6.9.5
 */
final class MediaCollage {

	/**
	 * Default collage cells (left + two right).
	 *
	 * @since 6.9.5
	 * @var int
	 */
	const MAX_VISIBLE = 3;

	/**
	 * Full-width style collage cells (2 top + 3 bottom).
	 *
	 * @since 6.7.10
	 * @var int
	 */
	const MAX_VISIBLE_FIVE = 5;

	/**
	 * Render a collage from PostMedia items.
	 *
	 * Falls back to {@see MediaTile::render()} when fewer than 2 displayable items.
	 *
	 * Options:
	 * - new_tab (bool, default true)
	 * - prefer_preview (bool, default true)
	 * - popup_trigger (bool, default false)
	 * - show_overlay (bool, default true): hover darken (+ optional plus / stats)
	 * - show_hover_plus (bool, default true)
	 * - show_hover_likes (bool, default false): likes count on hover overlay
	 * - show_hover_comments (bool, default false): comments count on hover overlay
	 * - likes_count (int, default 0)
	 * - comments_count (int, default 0)
	 * - show_media_type_icon (bool, default true): gallery badge
	 * - more_label (string): label after "+N" (default: translated "more"; ignored when more_format=count)
	 * - more_format (string): `labeled` ("+N more") or `count` ("+N") — five layout defaults to count
	 * - total_count (int): optional album total for "+N more" (overrides mapped length)
	 * - more_count (int): optional direct override for items beyond visible cells
	 * - more_is_minimum (bool): when true, show "N+" / "N+ more" (at least N more)
	 * - extra_count (int): forwarded to MediaTile fallback (additional items beyond primary)
	 * - max_visible (int): 3 (default) or 5 (full-width grid when enough media)
	 * - variant (string): `card` (landscape) or `tile` (square) — CSS modifier only
	 *
	 * @since 6.9.5
	 * @param string               $module    Module slug (e.g. `instagram`).
	 * @param array<int,PostMedia> $media     Media items (carousel children).
	 * @param string               $permalink Permalink wrapping the collage.
	 * @param string               $alt_text  Fallback alt text.
	 * @param array<string,mixed>  $options   Rendering options.
	 * @return string Collage HTML, or empty string when no media.
	 */
	public static function render(
		string $module,
		array $media,
		string $permalink,
		string $alt_text = '',
		array $options = array()
	): string {
		$module = sanitize_key( $module );

		$defaults = array(
			'new_tab'                  => true,
			'prefer_preview'           => true,
			'popup_trigger'            => false,
			'show_overlay'             => true,
			'show_hover_plus'          => true,
			'show_hover_likes'         => false,
			'show_hover_comments'      => false,
			'include_zero_hover_stats' => false,
			'likes_count'              => 0,
			'comments_count'           => 0,
			'show_media_type_icon'     => true,
			'more_label'               => '',
			'more_format'              => '',
			'total_count'              => 0,
			'more_count'               => null,
			'more_is_minimum'          => false,
			'max_visible'              => self::MAX_VISIBLE,
			'variant'                  => 'card',
		);
		$options  = array_replace( $defaults, $options );

		$grid_items = self::collect_display_items( $media, (bool) $options['prefer_preview'] );
		if ( count( $grid_items ) < 2 ) {
			$primary = empty( $media ) ? null : $media[0];
			if ( ! $primary instanceof PostMedia ) {
				return '';
			}
			$tile_extra = isset( $options['extra_count'] ) && null !== $options['extra_count']
				? max( 0, (int) $options['extra_count'] )
				: max( 0, count( $media ) - 1 );
			return MediaTile::render(
				$module,
				$primary,
				$tile_extra,
				$permalink,
				$alt_text,
				$options
			);
		}

		$max_visible = self::resolve_max_visible( $options, count( $grid_items ) );
		$use_five    = self::MAX_VISIBLE_FIVE === $max_visible;
		$visible     = array_slice( $grid_items, 0, $max_visible );
		$extra_count = self::resolve_extra_count( $grid_items, $options, $max_visible );
		$is_two      = 2 === count( $visible );
		$variant     = 'tile' === $options['variant'] ? 'tile' : 'card';
		$more_is_min = ! empty( $options['more_is_minimum'] );
		$more_format = self::resolve_more_format( $options, $use_five );

		$include_zero   = ! empty( $options['include_zero_hover_stats'] );
		$likes_count    = (int) $options['likes_count'];
		$comments_count = (int) $options['comments_count'];
		$show_plus      = ! empty( $options['show_overlay'] ) && ! empty( $options['show_hover_plus'] );
		$show_likes     = ! empty( $options['show_overlay'] )
			&& (bool) $options['show_hover_likes']
			&& ( $likes_count > 0 || $include_zero );
		$show_comments  = ! empty( $options['show_overlay'] )
			&& (bool) $options['show_hover_comments']
			&& ( $comments_count > 0 || $include_zero );
		$show_hover_stats = $show_likes || $show_comments;

		$root_class = "esf-{$module}-feed__media-collage-link";
		if ( ! empty( $options['popup_trigger'] ) ) {
			$root_class .= " esf-{$module}-feed__media-collage-link--popup";
		}
		if ( ! empty( $options['show_overlay'] ) ) {
			$root_class .= " esf-{$module}-feed__media-collage-link--hover-overlay";
		}
		if ( $show_plus ) {
			$root_class .= " esf-{$module}-feed__media-collage-link--hover-plus";
		}
		if ( $show_hover_stats ) {
			$root_class .= " esf-{$module}-feed__media-collage-link--hover-stats";
		}

		$popup_attr = '';
		if ( ! empty( $options['popup_trigger'] ) ) {
			$popup_attr = sprintf( ' data-esf-%1$s-popup-trigger="1"', esc_attr( $module ) );
		}

		$target_rel = '';
		$open_tag   = '';
		$close_tag  = '';
		if ( '' !== $permalink ) {
			$target_rel = $options['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : ' target="_self" rel="noopener"';
			$open_tag   = sprintf(
				'<a class="%1$s" href="%2$s"%3$s%4$s>',
				esc_attr( $root_class ),
				esc_url( $permalink ),
				$target_rel,
				$popup_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static data attributes.
			);
			$close_tag = '</a>';
		} else {
			$open_tag  = sprintf( '<div class="%1$s">', esc_attr( $root_class ) );
			$close_tag = '</div>';
		}

		$grid_class = "esf-{$module}-feed__media-collage esf-{$module}-feed__media-collage--{$variant}";
		if ( $use_five ) {
			$grid_class .= " esf-{$module}-feed__media-collage--five";
		} elseif ( $is_two ) {
			$grid_class .= " esf-{$module}-feed__media-collage--two";
		}

		$more_label = trim( (string) $options['more_label'] );
		if ( '' === $more_label ) {
			$more_label = function_exists( 'esf_get_translated_string' )
				? (string) esf_get_translated_string( 'media_more' )
				: __( 'more', 'easy-facebook-likebox' );
		}

		$html  = $open_tag;
		$html .= sprintf( '<div class="%s">', esc_attr( $grid_class ) );

		if ( $use_five ) {
			$html .= self::render_five_grid( $module, $visible, $alt_text, $extra_count, $more_label, $more_format, $more_is_min );
		} elseif ( $is_two ) {
			$html .= self::render_cell( $module, $visible[0], $alt_text, false, 0, $more_label, $more_format, false );
			$html .= self::render_cell( $module, $visible[1], $alt_text, false, 0, $more_label, $more_format, false );
		} else {
			$html .= sprintf(
				'<div class="esf-%1$s-feed__media-collage-left">%2$s</div>',
				esc_attr( $module ),
				self::render_cell_inner( $module, $visible[0], $alt_text ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			$html .= sprintf( '<div class="esf-%1$s-feed__media-collage-right">', esc_attr( $module ) );
			if ( isset( $visible[1] ) ) {
				$html .= self::render_cell( $module, $visible[1], $alt_text, false, 0, $more_label, $more_format, false );
			}
			if ( isset( $visible[2] ) ) {
				$html .= self::render_cell( $module, $visible[2], $alt_text, true, $extra_count, $more_label, $more_format, $more_is_min );
			}
			$html .= '</div>';
		}

		$html .= '</div>';

		$show_gallery = ! empty( $options['show_media_type_icon'] );
		if ( $show_gallery ) {
			// Always-visible corner badge (parity with MediaTile media-type icons).
			$html .= self::render_corner_gallery( $module );
		}

		// Hover darken + centered "+" whenever overlay is on (same as MediaTile),
		// including multi-image posts that already show "+N more" on a cell.
		if ( ! empty( $options['show_overlay'] ) ) {
			$html .= self::render_hover_overlay(
				$module,
				$show_plus,
				$show_likes,
				$show_comments,
				$likes_count,
				$comments_count
			);
		}

		$shoppable_label = isset( $options['shoppable_label'] ) ? trim( (string) $options['shoppable_label'] ) : '';
		if ( '' !== $shoppable_label ) {
			$html .= sprintf(
				'<span class="esf-%1$s-feed__tile-shoppable-badge">%2$s</span>',
				esc_attr( $module ),
				esc_html( $shoppable_label )
			);
		}

		$html .= $close_tag;

		return $html;
	}

	/**
	 * Resolve how many cells to show (3 default; 5 only when enough media).
	 *
	 * @param array<string,mixed> $options    Options.
	 * @param int                 $item_count Displayable items.
	 * @return int
	 */
	private static function resolve_max_visible( array $options, int $item_count ): int {
		$requested = isset( $options['max_visible'] ) ? (int) $options['max_visible'] : self::MAX_VISIBLE;
		if ( $requested >= self::MAX_VISIBLE_FIVE && $item_count >= self::MAX_VISIBLE_FIVE ) {
			return self::MAX_VISIBLE_FIVE;
		}
		return self::MAX_VISIBLE;
	}

	/**
	 * @param array<string,mixed> $options  Options.
	 * @param bool                $use_five Five-cell layout active.
	 * @return string labeled|count
	 */
	private static function resolve_more_format( array $options, bool $use_five ): string {
		$format = isset( $options['more_format'] ) ? sanitize_key( (string) $options['more_format'] ) : '';
		if ( 'count' === $format || 'labeled' === $format ) {
			return $format;
		}
		return $use_five ? 'count' : 'labeled';
	}

	/**
	 * 2+3 full-width collage grid.
	 *
	 * @param string                              $module      Module.
	 * @param array<int,array{src:string,alt:string}> $visible Visible cells (5).
	 * @param string                              $alt_text    Alt fallback.
	 * @param int                                 $extra_count Extra beyond visible.
	 * @param string                              $more_label  More label.
	 * @param string                              $more_format count|labeled.
	 * @param bool                                $more_is_min Minimum indicator.
	 * @return string
	 */
	private static function render_five_grid(
		string $module,
		array $visible,
		string $alt_text,
		int $extra_count,
		string $more_label,
		string $more_format,
		bool $more_is_min
	): string {
		$html = '';
		$count = count( $visible );
		for ( $i = 0; $i < $count; $i++ ) {
			$is_last_more = ( $i === $count - 1 ) && $extra_count > 0;
			$html        .= self::render_cell(
				$module,
				$visible[ $i ],
				$alt_text,
				$is_last_more,
				$extra_count,
				$more_label,
				$more_format,
				$more_is_min
			);
		}
		return $html;
	}

	/**
	 * Collect displayable collage cells (url + alt).
	 *
	 * @param array<int,PostMedia> $media          Media list.
	 * @param bool                 $prefer_preview Prefer preview_url for videos.
	 * @return array<int,array{src:string,alt:string}>
	 */
	private static function collect_display_items( array $media, bool $prefer_preview ): array {
		$items = array();
		foreach ( $media as $item ) {
			if ( ! $item instanceof PostMedia ) {
				continue;
			}
			$src = '';
			if ( $prefer_preview ) {
				$preview = $item->get_preview_url();
				if ( '' !== $preview ) {
					$src = $preview;
				}
			}
			if ( '' === $src ) {
				$src = $item->get_display_url();
			}
			if ( '' === $src ) {
				continue;
			}
			$items[] = array(
				'src' => $src,
				'alt' => $item->get_alt(),
			);
		}
		return $items;
	}

	/**
	 * Resolve "+N more" count from mapped items and optional overrides.
	 *
	 * @param array<int,array{src:string,alt:string}> $grid_items  Display items.
	 * @param array<string,mixed>                     $options     Render options.
	 * @param int                                     $max_visible Visible cells.
	 * @return int
	 */
	private static function resolve_extra_count( array $grid_items, array $options, int $max_visible ): int {
		$total = isset( $options['total_count'] ) ? (int) $options['total_count'] : 0;
		if ( $total > 0 ) {
			return max( 0, $total - $max_visible );
		}
		if ( array_key_exists( 'more_count', $options ) && null !== $options['more_count'] ) {
			return max( 0, (int) $options['more_count'] );
		}
		return max( 0, count( $grid_items ) - $max_visible );
	}

	/**
	 * Render one collage cell wrapper.
	 *
	 * @param string                       $module      Module slug.
	 * @param array{src:string,alt:string} $item        Cell data.
	 * @param string                       $alt_text    Fallback alt.
	 * @param bool                         $with_more   Whether to show +N more overlay.
	 * @param int                          $extra_count Extra items beyond visible.
	 * @param string                       $more_label  "more" label.
	 * @param string                       $more_format count|labeled.
	 * @param bool                         $is_minimum  When true, render "N+" / "N+ more".
	 * @return string
	 */
	private static function render_cell(
		string $module,
		array $item,
		string $alt_text,
		bool $with_more,
		int $extra_count,
		string $more_label,
		string $more_format = 'labeled',
		bool $is_minimum = false
	): string {
		$inner = self::render_cell_inner( $module, $item, $alt_text );
		if ( $with_more && $extra_count > 0 ) {
			$count_label = $is_minimum
				? (string) $extra_count . '+'
				: (string) $extra_count;
			if ( 'count' === $more_format ) {
				// Minimum: "12+" — exact: "+12" (avoid "+12+").
				$display = $is_minimum ? $count_label : ( '+' . $count_label );
				$inner  .= sprintf(
					'<span class="esf-%1$s-feed__media-collage-more esf-%1$s-feed__media-collage-more--count">%2$s</span>',
					esc_attr( $module ),
					esc_html( $display )
				);
			} else {
				// Minimum: "12+ more" — exact: "+12 more" (avoid "+12+ more").
				$display = $is_minimum
					? ( $count_label . ' ' . $more_label )
					: ( '+' . $count_label . ' ' . $more_label );
				$inner  .= sprintf(
					'<span class="esf-%1$s-feed__media-collage-more">%2$s</span>',
					esc_attr( $module ),
					esc_html( $display )
				);
			}
		}

		return sprintf(
			'<div class="esf-%1$s-feed__media-collage-cell">%2$s</div>',
			esc_attr( $module ),
			$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped fragments.
		);
	}

	/**
	 * Render the img inside a cell.
	 *
	 * @param string                       $module   Module slug.
	 * @param array{src:string,alt:string} $item     Cell data.
	 * @param string                       $alt_text Fallback alt.
	 * @return string
	 */
	private static function render_cell_inner( string $module, array $item, string $alt_text ): string {
		$alt = '' !== $item['alt'] ? $item['alt'] : $alt_text;
		return sprintf(
			'<img class="esf-%1$s-feed__media-collage-img" src="%2$s" alt="%3$s" loading="lazy" />',
			esc_attr( $module ),
			esc_url( $item['src'] ),
			esc_attr( $alt )
		);
	}

	/**
	 * Always-visible gallery badge (top-right), separate from the hover darken.
	 *
	 * @param string $module Module slug.
	 * @return string
	 */
	private static function render_corner_gallery( string $module ): string {
		return sprintf(
			'<span class="esf-%1$s-feed__media-collage-overlay esf-%1$s-feed__media-collage-overlay--corner" aria-hidden="true">'
			. '<span class="esf-%1$s-feed__media-collage-overlay-icon esf-%1$s-feed__media-collage-overlay-icon--gallery" aria-hidden="true">'
			. '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
			. '<rect x="8" y="8" width="12" height="12" rx="2"></rect>'
			. '<path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"></path>'
			. '</svg></span></span>',
			esc_attr( $module )
		);
	}

	/**
	 * Hover darken with optional centered "+" and likes/comments stats.
	 *
	 * @param string $module         Module slug.
	 * @param bool   $show_plus      Centered plus.
	 * @param bool   $show_likes     Likes stat enabled.
	 * @param bool   $show_comments  Comments stat enabled.
	 * @param int    $likes_count    Likes value.
	 * @param int    $comments_count Comments value.
	 * @return string
	 */
	private static function render_hover_overlay(
		string $module,
		bool $show_plus,
		bool $show_likes,
		bool $show_comments,
		int $likes_count,
		int $comments_count
	): string {
		$inner = '';
		if ( $show_plus ) {
			$inner .= sprintf(
				'<span class="esf-%1$s-feed__media-collage-overlay-plus" aria-hidden="true">+</span>',
				esc_attr( $module )
			);
		}

		$show_likes_stat    = $show_likes;
		$show_comments_stat = $show_comments;
		if ( $show_likes_stat || $show_comments_stat ) {
			$items = '';
			if ( $show_likes_stat ) {
				$items .= sprintf(
					'<span class="esf-%1$s-feed__tile-hover-stat esf-%1$s-feed__tile-hover-stat--likes" data-hover-stat="likes" aria-hidden="true">'
					. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
					. '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>'
					. '</svg><span class="esf-%1$s-feed__tile-hover-stat-count">%2$s</span></span>',
					esc_attr( $module ),
					esc_html( NumberFormatter::compact( $likes_count ) )
				);
			}
			if ( $show_comments_stat ) {
				$items .= sprintf(
					'<span class="esf-%1$s-feed__tile-hover-stat esf-%1$s-feed__tile-hover-stat--comments" data-hover-stat="comments" aria-hidden="true">'
					. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
					. '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'
					. '</svg><span class="esf-%1$s-feed__tile-hover-stat-count">%2$s</span></span>',
					esc_attr( $module ),
					esc_html( NumberFormatter::compact( $comments_count ) )
				);
			}
			$inner .= sprintf(
				'<span class="esf-%1$s-feed__tile-hover-stats" aria-hidden="true">%2$s</span>',
				esc_attr( $module ),
				$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped fragments.
			);
		}

		return sprintf(
			'<span class="esf-%1$s-feed__media-collage-overlay esf-%1$s-feed__media-collage-overlay--hover" aria-hidden="true">'
			. '<span class="esf-%1$s-feed__media-collage-overlay-inner" aria-hidden="true">%2$s</span>'
			. '</span>',
			esc_attr( $module ),
			$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped / static markup.
		);
	}
}
