<?php
/**
 * MediaCollage primitive.
 *
 * Multi-image collage for carousel/album posts (X/Twitter-style layout):
 * 2 items → equal split; 3+ → large left + stacked right with "+N more".
 *
 * Used by Instagram Half/Full Width cards and tile layouts; reusable by other modules.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.5
 */

namespace EasySocialFeed\Layouts\Primitives;

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
	 * Maximum collage cells (left + two right).
	 *
	 * @since 6.9.5
	 * @var int
	 */
	const MAX_VISIBLE = 3;

	/**
	 * Render a collage from PostMedia items.
	 *
	 * Falls back to {@see MediaTile::render()} when fewer than 2 displayable items.
	 *
	 * Options:
	 * - new_tab (bool, default true)
	 * - prefer_preview (bool, default true)
	 * - popup_trigger (bool, default false)
	 * - show_overlay (bool, default true): hover plus + gallery badge
	 * - show_hover_plus (bool, default true)
	 * - show_media_type_icon (bool, default true): gallery badge
	 * - more_label (string): label after "+N" (default: translated "more")
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
			'new_tab'              => true,
			'prefer_preview'       => true,
			'popup_trigger'        => false,
			'show_overlay'         => true,
			'show_hover_plus'      => true,
			'show_media_type_icon' => true,
			'more_label'           => '',
			'variant'              => 'card',
		);
		$options  = array_replace( $defaults, $options );

		$grid_items = self::collect_display_items( $media, (bool) $options['prefer_preview'] );
		if ( count( $grid_items ) < 2 ) {
			$primary = empty( $media ) ? null : $media[0];
			if ( ! $primary instanceof PostMedia ) {
				return '';
			}
			return MediaTile::render(
				$module,
				$primary,
				max( 0, count( $media ) - 1 ),
				$permalink,
				$alt_text,
				$options
			);
		}

		$visible     = array_slice( $grid_items, 0, self::MAX_VISIBLE );
		$extra_count = max( 0, count( $grid_items ) - self::MAX_VISIBLE );
		$is_two      = 2 === count( $visible );
		$variant     = 'tile' === $options['variant'] ? 'tile' : 'card';

		$root_class = "esf-{$module}-feed__media-collage-link";
		if ( ! empty( $options['popup_trigger'] ) ) {
			$root_class .= " esf-{$module}-feed__media-collage-link--popup";
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
		if ( $is_two ) {
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

		if ( $is_two ) {
			$html .= self::render_cell( $module, $visible[0], $alt_text, false, 0, $more_label );
			$html .= self::render_cell( $module, $visible[1], $alt_text, false, 0, $more_label );
		} else {
			$html .= sprintf(
				'<div class="esf-%1$s-feed__media-collage-left">%2$s</div>',
				esc_attr( $module ),
				self::render_cell_inner( $module, $visible[0], $alt_text ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			$html .= sprintf( '<div class="esf-%1$s-feed__media-collage-right">', esc_attr( $module ) );
			if ( isset( $visible[1] ) ) {
				$html .= self::render_cell( $module, $visible[1], $alt_text, false, 0, $more_label );
			}
			if ( isset( $visible[2] ) ) {
				$html .= self::render_cell( $module, $visible[2], $alt_text, true, $extra_count, $more_label );
			}
			$html .= '</div>';
		}

		$html .= '</div>';

		$show_plus    = ! empty( $options['show_overlay'] ) && ! empty( $options['show_hover_plus'] );
		$show_gallery = ! empty( $options['show_media_type_icon'] );
		if ( $show_plus || $show_gallery ) {
			// Hover reveal only when "+" is enabled; otherwise keep a persistent corner badge.
			$html .= self::render_overlay( $module, $show_plus, $show_gallery, $show_plus );
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
	 * Render one collage cell wrapper.
	 *
	 * @param string               $module      Module slug.
	 * @param array{src:string,alt:string} $item Cell data.
	 * @param string               $alt_text    Fallback alt.
	 * @param bool                 $with_more   Whether to show +N more overlay.
	 * @param int                  $extra_count Extra items beyond visible.
	 * @param string               $more_label  "more" label.
	 * @return string
	 */
	private static function render_cell(
		string $module,
		array $item,
		string $alt_text,
		bool $with_more,
		int $extra_count,
		string $more_label
	): string {
		$inner = self::render_cell_inner( $module, $item, $alt_text );
		if ( $with_more && $extra_count > 0 ) {
			$inner .= sprintf(
				'<span class="esf-%1$s-feed__media-collage-more">+%2$s %3$s</span>',
				esc_attr( $module ),
				esc_html( (string) $extra_count ),
				esc_html( $more_label )
			);
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
	 * Overlay (+ on hover and/or always-visible gallery badge).
	 *
	 * @param string $module       Module slug.
	 * @param bool   $show_plus    Centered plus (shown when hover overlay is enabled).
	 * @param bool   $show_gallery Gallery badge.
	 * @param bool   $hover_mode   When true, wrap content in a hover-reveal overlay (X-style).
	 * @return string
	 */
	private static function render_overlay( string $module, bool $show_plus, bool $show_gallery, bool $hover_mode ): string {
		$gallery = '';
		if ( $show_gallery ) {
			$gallery = sprintf(
				'<span class="esf-%1$s-feed__media-collage-overlay-icon esf-%1$s-feed__media-collage-overlay-icon--gallery" aria-hidden="true">'
				. '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
				. '<rect x="8" y="8" width="12" height="12" rx="2"></rect>'
				. '<path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"></path>'
				. '</svg></span>',
				esc_attr( $module )
			);
		}

		if ( $hover_mode && ( $show_plus || $show_gallery ) ) {
			$inner = '';
			if ( $show_plus ) {
				$inner .= sprintf(
					'<span class="esf-%1$s-feed__media-collage-overlay-plus" aria-hidden="true">+</span>',
					esc_attr( $module )
				);
			}
			$inner .= $gallery;
			return sprintf(
				'<span class="esf-%1$s-feed__media-collage-overlay esf-%1$s-feed__media-collage-overlay--hover" aria-hidden="true">%2$s</span>',
				esc_attr( $module ),
				$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static / escaped markup.
			);
		}

		if ( '' === $gallery ) {
			return '';
		}

		return sprintf(
			'<span class="esf-%1$s-feed__media-collage-overlay esf-%1$s-feed__media-collage-overlay--corner" aria-hidden="true">%2$s</span>',
			esc_attr( $module ),
			$gallery // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static / escaped markup.
		);
	}
}
