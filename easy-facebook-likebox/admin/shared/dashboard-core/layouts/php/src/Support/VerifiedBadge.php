<?php
/**
 * Verified badge SVG renderer.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Support
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VerifiedBadge {

	/**
	 * Render reusable verified-badge SVG with optional extra classes.
	 *
	 * Returns an empty string when the account is not verified so callers can
	 * concatenate the result safely.
	 */
	public static function render( bool $is_verified, string $extra_classes = '', string $tooltip_label = '' ): string {
		if ( ! $is_verified ) {
			return '';
		}

		$classes = array( 'esf-feed__verified-badge' );
		foreach ( preg_split( '/\s+/', trim( $extra_classes ) ) as $class ) {
			if ( '' === $class ) {
				continue;
			}
			$classes[] = sanitize_html_class( $class );
		}
		$class_attr   = implode( ' ', array_filter( $classes ) );
		$tooltip_attr = '' !== $tooltip_label ? ' ' . Tooltip::attributes( $tooltip_label ) : '';

		return sprintf(
			'<span class="%1$s"%2$s>'
			. '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
			. '<path fill="#1d9bf0" d="M22.5 12l-2.3 2.6.3 3.4-3.3.8-1.7 2.9L12 20.2l-3.5 1.5-1.7-2.9-3.3-.8.3-3.4L1.5 12l2.3-2.6-.3-3.4 3.3-.8 1.7-2.9L12 3.8l3.5-1.5 1.7 2.9 3.3.8-.3 3.4z"></path>'
			. '<path fill="#ffffff" d="M10.4 15.6l-3-3 1.4-1.4 1.6 1.6 4.8-4.8 1.4 1.4z"></path>'
			. '</svg>'
			. '</span>',
			esc_attr( $class_attr ),
			$tooltip_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Tooltip::attributes returns escaped attributes.
		);
	}
}
