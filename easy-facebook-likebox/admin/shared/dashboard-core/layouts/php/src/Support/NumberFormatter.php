<?php
/**
 * Number formatter helper.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Support
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NumberFormatter {

	/**
	 * Compact-format an integer (1.2K, 3.4M, 5B).
	 *
	 * Mirrors the formatting style currently used by per-module helpers so
	 * existing visuals remain identical when modules migrate.
	 */
	public static function compact( int $value ): string {
		$abs = abs( $value );

		if ( $abs >= 1000000000 ) {
			return self::format_unit( $value, 1000000000, 'B' );
		}
		if ( $abs >= 1000000 ) {
			return self::format_unit( $value, 1000000, 'M' );
		}
		if ( $abs >= 1000 ) {
			return self::format_unit( $value, 1000, 'K' );
		}

		return (string) $value;
	}

	/**
	 * Build a "1.2K"-style label from a value, divisor and suffix.
	 */
	private static function format_unit( int $value, int $divisor, string $suffix ): string {
		$scaled = $value / $divisor;
		$decimals = abs( $scaled ) >= 100 || (int) $scaled == $scaled ? 0 : 1;
		$rounded = round( $scaled, $decimals );

		if ( 0 === $decimals ) {
			return ( (int) $rounded ) . $suffix;
		}

		$formatted = number_format( $rounded, $decimals );
		$formatted = preg_replace( '/\.0$/', '', $formatted );

		return $formatted . $suffix;
	}
}
