<?php
/**
 * Tooltip attribute helper.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Support
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tooltip {

	/**
	 * Build `data-tooltip` + `aria-label` attribute string for any element.
	 *
	 * Returns an empty string when the label is empty so callers can echo
	 * the result unconditionally.
	 */
	public static function attributes( string $label ): string {
		$label = sanitize_text_field( $label );
		if ( '' === $label ) {
			return '';
		}

		return 'data-tooltip="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '"';
	}
}
