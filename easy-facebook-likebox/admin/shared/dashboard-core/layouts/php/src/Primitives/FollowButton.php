<?php
/**
 * Follow button primitive (feed footer CTA).
 *
 * Renders a profile follow link below the feed (alongside Load More). Modules
 * pass the profile URL and label; optional colors map to CSS custom properties.
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
 * Follow button primitive.
 *
 * @since 6.9.0
 */
final class FollowButton {

	/**
	 * Render the follow link.
	 *
	 * Args:
	 * - url (string) required — profile / explore URL
	 * - label (string) required — translated button label
	 * - new_tab (bool) optional — open in a new tab (default true)
	 * - bg_color (string) optional hex
	 * - text_color (string) optional hex
	 * - hover_bg_color (string) optional hex
	 * - hover_text_color (string) optional hex
	 * - icon_html (string) optional trusted SVG/HTML prepended before the label
	 *
	 * @since 6.9.0
	 *
	 * @param string              $module Module slug.
	 * @param array<string,mixed> $args   Render args.
	 * @return string
	 */
	public static function render( string $module, array $args ): string {
		$module = sanitize_key( $module );
		$url    = isset( $args['url'] ) ? \esc_url_raw( (string) $args['url'] ) : '';
		$label  = isset( $args['label'] ) ? trim( (string) $args['label'] ) : '';

		if ( '' === $module || '' === $url || '' === $label || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$new_tab          = ! isset( $args['new_tab'] ) || ! empty( $args['new_tab'] );
		$bg_color         = isset( $args['bg_color'] ) ? sanitize_hex_color( (string) $args['bg_color'] ) : '';
		$text_color       = isset( $args['text_color'] ) ? sanitize_hex_color( (string) $args['text_color'] ) : '';
		$hover_bg_color   = isset( $args['hover_bg_color'] ) ? sanitize_hex_color( (string) $args['hover_bg_color'] ) : '';
		$hover_text_color = isset( $args['hover_text_color'] ) ? sanitize_hex_color( (string) $args['hover_text_color'] ) : '';

		$style_parts = array();
		if ( is_string( $bg_color ) && '' !== $bg_color ) {
			$style_parts[] = '--esf-follow-bg:' . $bg_color;
		}
		if ( is_string( $text_color ) && '' !== $text_color ) {
			$style_parts[] = '--esf-follow-text:' . $text_color;
		}
		if ( is_string( $hover_bg_color ) && '' !== $hover_bg_color ) {
			$style_parts[] = '--esf-follow-bg-hover:' . $hover_bg_color;
		}
		if ( is_string( $hover_text_color ) && '' !== $hover_text_color ) {
			$style_parts[] = '--esf-follow-text-hover:' . $hover_text_color;
		}
		$style_attr = ! empty( $style_parts )
			? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"'
			: '';

		$target_rel = $new_tab
			? ' target="_blank" rel="noopener noreferrer"'
			: '';

		$data_attrs = '';
		if ( is_string( $hover_bg_color ) && '' !== $hover_bg_color ) {
			$data_attrs .= ' data-esf-follow-has-hover-bg="1"';
		}

		$icon_html = isset( $args['icon_html'] ) ? (string) $args['icon_html'] : '';
		$inner     = '' !== $icon_html
			? $icon_html . '<span class="esf-' . esc_attr( $module ) . '-feed__follow-label">' . esc_html( $label ) . '</span>'
			: esc_html( $label );

		return sprintf(
			'<div class="esf-%1$s-feed__follow-wrap">'
			. '<a class="esf-%1$s-feed__follow" href="%2$s"%3$s%4$s%5$s>%6$s</a>'
			. '</div>',
			esc_attr( $module ),
			esc_url( $url ),
			$target_rel, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute string.
			$style_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
			$data_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static data attribute.
			$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Label escaped; icon_html is trusted SVG from caller.
		);
	}
}
