<?php
/**
 * LoadMore button primitive.
 *
 * Renders a button the frontend JS listens to for paginating a feed. Load
 * More is a Pro feature for every modern module; `infinite` mode (auto-fetch
 * via IntersectionObserver) is an alternate Pro pagination mode — the markup
 * and endpoint contract stay identical.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Primitives;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LoadMoreButton {

	/**
	 * Render the button.
	 *
	 * Args:
	 * - feed_id (int) required
	 * - offset (int) required — next item index
	 * - next_token (string) optional API cursor
	 * - label (string) required — translated button label
	 * - aria_label (string) optional
	 * - bg_color (string) optional hex
	 * - text_color (string) optional hex
	 * - hover_bg_color (string) optional hex
	 * - hover_text_color (string) optional hex
	 * - mode (string) optional `button` (default) or `infinite` (Pro pagination)
	 *
	 * @since 6.9.0
	 * @since 6.16.0 Added `mode` arg for Pro infinite-scroll pagination.
	 *
	 * @param string              $module Module slug.
	 * @param array<string,mixed> $args   Render args.
	 * @return string Button markup, or '' when required args are missing.
	 */
	public static function render( string $module, array $args ): string {
		$module     = sanitize_key( $module );
		$feed_id    = isset( $args['feed_id'] ) ? (int) $args['feed_id'] : 0;
		$offset     = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		$next_token = isset( $args['next_token'] ) ? sanitize_text_field( (string) $args['next_token'] ) : '';
		$label      = isset( $args['label'] ) ? (string) $args['label'] : '';
		$aria_label = isset( $args['aria_label'] ) ? (string) $args['aria_label'] : $label;
		$bg_color        = isset( $args['bg_color'] ) ? sanitize_hex_color( (string) $args['bg_color'] ) : '';
		$text_color      = isset( $args['text_color'] ) ? sanitize_hex_color( (string) $args['text_color'] ) : '';
		$hover_bg_color  = isset( $args['hover_bg_color'] ) ? sanitize_hex_color( (string) $args['hover_bg_color'] ) : '';
		$hover_text_color = isset( $args['hover_text_color'] ) ? sanitize_hex_color( (string) $args['hover_text_color'] ) : '';
		$mode             = isset( $args['mode'] ) && 'infinite' === $args['mode'] ? 'infinite' : 'button';

		if ( $feed_id <= 0 || '' === $label ) {
			return '';
		}

		$style_parts = array();
		if ( '' !== $bg_color ) {
			$style_parts[] = '--esf-lm-bg:' . $bg_color;
		}
		if ( '' !== $text_color ) {
			$style_parts[] = '--esf-lm-text:' . $text_color;
		}
		if ( '' !== $hover_bg_color ) {
			$style_parts[] = '--esf-lm-bg-hover:' . $hover_bg_color;
		}
		if ( '' !== $hover_text_color ) {
			$style_parts[] = '--esf-lm-text-hover:' . $hover_text_color;
		}
		$style_attr = ! empty( $style_parts )
			? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"'
			: '';

		$data_attrs = '';
		if ( '' !== $hover_bg_color ) {
			$data_attrs .= ' data-esf-lm-has-hover-bg="1"';
		}

		$wrap_class = 'esf-' . $module . '-feed__load-more-wrap';
		if ( 'infinite' === $mode ) {
			$wrap_class .= ' esf-' . $module . '-feed__load-more-wrap--infinite';
		}

		return sprintf(
			'<div class="%9$s" data-esf-lm-mode="%10$s">'
			. '<button type="button" class="esf-%1$s-feed__load-more" data-feed-id="%2$d" data-offset="%3$d" data-next-token="%4$s" aria-label="%5$s"%6$s%8$s>%7$s</button>'
			. '</div>',
			esc_attr( $module ),
			$feed_id,
			$offset,
			esc_attr( $next_token ),
			esc_attr( $aria_label ),
			$style_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
			esc_html( $label ),
			$data_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static data attribute.
			esc_attr( $wrap_class ),
			esc_attr( $mode )
		);
	}
}
