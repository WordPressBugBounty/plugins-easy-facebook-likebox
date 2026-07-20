<?php
/**
 * ExpandableCaption primitive.
 *
 * Renders post caption HTML with an optional word-limited preview and a
 * "See more" / "See less" toggle. Used by split / timeline-style layouts
 * (Instagram Half Width today; Facebook Split later).
 *
 * Truncation operates on plain-text word count; the full escaped HTML is
 * preserved for expand. The companion `layout_js` (see more toggle) lives
 * on the layout definition so it only loads when this layout is used.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Primitives;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expandable caption markup.
 *
 * @since 6.9.0
 */
final class ExpandableCaption {

	/**
	 * Render an expandable caption block.
	 *
	 * Options:
	 * - words_limit (int, default 0): truncate when text exceeds this many words (0 = never).
	 * - see_more_label (string)
	 * - see_less_label (string)
	 * - post_id (string): stable id for a11y controls.
	 *
	 * @param string              $module     Module slug.
	 * @param string              $text_html  Escaped caption HTML from PostItem::get_text_html().
	 * @param array<string,mixed> $options    Rendering options.
	 * @return string Empty string when there is no caption.
	 */
	public static function render( string $module, string $text_html, array $options = array() ): string {
		$text_html = trim( $text_html );
		if ( '' === $text_html ) {
			return '';
		}

		$module      = sanitize_key( $module );
		$words_limit = isset( $options['words_limit'] ) ? max( 0, (int) $options['words_limit'] ) : 0;
		$post_id     = isset( $options['post_id'] ) ? sanitize_html_class( (string) $options['post_id'] ) : '';
		$see_more    = isset( $options['see_more_label'] ) && '' !== (string) $options['see_more_label']
			? (string) $options['see_more_label']
			: __( 'See more', 'easy-facebook-likebox' );
		$see_less    = isset( $options['see_less_label'] ) && '' !== (string) $options['see_less_label']
			? (string) $options['see_less_label']
			: __( 'See less', 'easy-facebook-likebox' );

		$plain = trim( wp_strip_all_tags( $text_html ) );
		$words = '' === $plain ? array() : preg_split( '/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			$words = array();
		}

		$needs_toggle = $words_limit > 0 && count( $words ) > $words_limit;

		if ( ! $needs_toggle ) {
			return sprintf(
				'<div class="esf-%1$s-feed__caption">%2$s</div>',
				esc_attr( $module ),
				$text_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PostItem / mapper guarantee escaped HTML.
			);
		}

		$preview_plain = implode( ' ', array_slice( $words, 0, $words_limit ) );
		$suffix        = '' !== $post_id ? $post_id : ( function_exists( 'wp_unique_id' ) ? wp_unique_id() : uniqid( 'c', false ) );
		$control_id    = 'esf-' . $module . '-caption-' . $suffix;

		// Class + hidden + aria-hidden: theme CSS often overrides [hidden] with display:block.
		return sprintf(
			'<div class="esf-%1$s-feed__caption esf-%1$s-feed__caption--expandable is-collapsed" data-esf-caption-expandable="1">' .
			'<div class="esf-%1$s-feed__caption-preview" id="%2$s-preview">%3$s</div>' .
			'<div class="esf-%1$s-feed__caption-full" id="%2$s-full" hidden aria-hidden="true">%4$s</div>' .
			'<button type="button" class="esf-%1$s-feed__caption-toggle" data-esf-caption-toggle aria-expanded="false" aria-controls="%2$s-full" data-label-more="%5$s" data-label-less="%6$s">%7$s</button>' .
			'</div>',
			esc_attr( $module ),
			esc_attr( $control_id ),
			esc_html( $preview_plain ) . '&hellip;',
			$text_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped caption HTML.
			esc_attr( $see_more ),
			esc_attr( $see_less ),
			esc_html( $see_more )
		);
	}
}
