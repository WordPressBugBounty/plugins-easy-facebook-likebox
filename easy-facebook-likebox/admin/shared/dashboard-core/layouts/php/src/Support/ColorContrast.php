<?php
/**
 * Relative luminance / contrast helpers for theme colors.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Support
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCAG-ish contrast utilities for design-panel theme vars.
 */
final class ColorContrast {

	/**
	 * Minimum contrast ratio before a custom text color is considered usable.
	 */
	const MIN_RATIO = 3.0;

	/**
	 * Parse a CSS hex/rgb/rgba color into 0–255 RGB channels.
	 *
	 * @param string $color Color string.
	 * @return array{0:int,1:int,2:int}|null
	 */
	public static function parse_rgb( string $color ): ?array {
		$color = trim( $color );
		if ( '' === $color ) {
			return null;
		}

		if ( preg_match( '/^#([0-9a-f]{3})$/i', $color, $m ) ) {
			$h = $m[1];
			return array(
				hexdec( str_repeat( $h[0], 2 ) ),
				hexdec( str_repeat( $h[1], 2 ) ),
				hexdec( str_repeat( $h[2], 2 ) ),
			);
		}

		if ( preg_match( '/^#([0-9a-f]{6})([0-9a-f]{2})?$/i', $color, $m ) ) {
			$h = $m[1];
			return array(
				hexdec( substr( $h, 0, 2 ) ),
				hexdec( substr( $h, 2, 2 ) ),
				hexdec( substr( $h, 4, 2 ) ),
			);
		}

		if ( preg_match(
			'/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*[\d.]+\s*)?\)$/i',
			$color,
			$m
		) ) {
			return array(
				(int) round( (float) $m[1] ),
				(int) round( (float) $m[2] ),
				(int) round( (float) $m[3] ),
			);
		}

		return null;
	}

	/**
	 * Relative luminance (0–1) per WCAG 2.x.
	 *
	 * @param string $color Color string.
	 * @return float|null
	 */
	public static function relative_luminance( string $color ): ?float {
		$rgb = self::parse_rgb( $color );
		if ( null === $rgb ) {
			return null;
		}

		$channels = array_map(
			static function ( int $channel ): float {
				$c = $channel / 255;
				return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			},
			$rgb
		);

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	/**
	 * Contrast ratio between two colors (1–21).
	 *
	 * @param string $a Foreground.
	 * @param string $b Background.
	 * @return float|null
	 */
	public static function ratio( string $a, string $b ): ?float {
		$l1 = self::relative_luminance( $a );
		$l2 = self::relative_luminance( $b );
		if ( null === $l1 || null === $l2 ) {
			return null;
		}

		$lighter = max( $l1, $l2 );
		$darker  = min( $l1, $l2 );

		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * Whether $fg is readable on $bg.
	 *
	 * @param string $fg Foreground.
	 * @param string $bg Background.
	 * @return bool
	 */
	public static function is_readable( string $fg, string $bg ): bool {
		$ratio = self::ratio( $fg, $bg );
		return null !== $ratio && $ratio >= self::MIN_RATIO;
	}

	/**
	 * Pick a foreground that reads on $bg.
	 *
	 * Prefers $preferred when it has enough contrast; otherwise light or dark fallback
	 * based on background luminance.
	 *
	 * @param string      $bg         Background.
	 * @param string|null $preferred  Optional preferred foreground (e.g. text_color).
	 * @param string      $dark_fg    Fallback on light backgrounds.
	 * @param string      $light_fg   Fallback on dark backgrounds.
	 * @return string
	 */
	public static function readable_on(
		string $bg,
		?string $preferred = null,
		string $dark_fg = '#56687a',
		string $light_fg = '#f3f6f8'
	): string {
		if ( is_string( $preferred ) && '' !== $preferred && self::is_readable( $preferred, $bg ) ) {
			return $preferred;
		}

		$lum = self::relative_luminance( $bg );
		if ( null === $lum ) {
			return $dark_fg;
		}

		return $lum > 0.45 ? $dark_fg : $light_fg;
	}
}
