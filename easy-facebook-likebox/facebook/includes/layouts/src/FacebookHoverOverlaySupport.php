<?php
/**
 * Facebook hover overlay color/size CSS vars (shared by tile + split + stacked).
 *
 * Design panel Feed controls write `feed.hover_plus_*` / `feed.hover_stats_*`.
 * Tile layouts already emitted these vars; Half Width / Thumbnail / Full Width
 * reuse the same helpers so + icon and hover stats style apply on every layout.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts
 * @since 6.7.9
 */

namespace EasySocialFeed\Facebook\Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hover overlay style helpers for Facebook layouts.
 *
 * @since 6.7.9
 */
trait FacebookHoverOverlaySupport {

	/**
	 * Default hover-plus icon size (px) for this layout.
	 *
	 * @since 6.7.9
	 * @return int
	 */
	protected function get_default_hover_plus_size(): int {
		return 42;
	}

	/**
	 * Default hover-stats text size (px) for this layout.
	 *
	 * @since 6.7.9
	 * @return int
	 */
	protected function get_default_hover_stats_size(): int {
		return 14;
	}

	/**
	 * Append Pro hover overlay color/size CSS custom properties.
	 *
	 * @since 6.7.9
	 *
	 * @param string $style Existing inline style string.
	 * @return string
	 */
	protected function append_hover_overlay_style_vars( string $style ): string {
		if ( ! $this->has_pro_plan() ) {
			return $style;
		}

		$color_map = array(
			'hover_plus_color'  => '--esf-facebook-hover-plus-color',
			'hover_stats_color' => '--esf-facebook-hover-stats-color',
		);

		foreach ( $color_map as $setting_key => $css_var ) {
			$raw       = trim( (string) $this->setting( 'feed.' . $setting_key, '' ) );
			$sanitized = $this->sanitize_hover_overlay_color( $raw );
			if ( '' !== $sanitized ) {
				$style .= ';' . $css_var . ':' . $sanitized;
			}
		}

		$size_map = array(
			'hover_plus_size'  => array(
				'var'     => '--esf-facebook-hover-plus-size',
				'default' => $this->get_default_hover_plus_size(),
				'min'     => 20,
				'max'     => 72,
			),
			'hover_stats_size' => array(
				'var'     => '--esf-facebook-hover-stats-size',
				'default' => $this->get_default_hover_stats_size(),
				'min'     => 10,
				'max'     => 24,
			),
		);

		foreach ( $size_map as $setting_key => $config ) {
			$size   = (int) $this->setting( 'feed.' . $setting_key, $config['default'] );
			$size   = max( $config['min'], min( $config['max'], $size ) );
			$style .= ';' . $config['var'] . ':' . $size . 'px';
		}

		return $style;
	}

	/**
	 * Sanitize a hover overlay color (hex or rgba). Invalid values are ignored.
	 *
	 * @since 6.7.9
	 *
	 * @param string $color Raw color.
	 * @return string Sanitized color or empty string.
	 */
	protected function sanitize_hover_overlay_color( string $color ): string {
		$color = trim( $color );
		if ( '' === $color ) {
			return '';
		}

		$hex = sanitize_hex_color( $color );
		if ( is_string( $hex ) && '' !== $hex ) {
			return $hex;
		}

		if ( preg_match( '/^rgba?\(\s*[\d.%]+\s*,\s*[\d.%]+\s*,\s*[\d.%]+(?:\s*,\s*[\d.]+\s*)?\)$/i', $color ) ) {
			return $color;
		}

		return '';
	}
}
