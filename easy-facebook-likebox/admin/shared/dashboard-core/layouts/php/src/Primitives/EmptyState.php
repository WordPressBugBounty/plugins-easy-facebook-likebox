<?php
/**
 * Empty-state primitive.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Primitives;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EmptyState {

	/**
	 * Render the empty-state notice with a `esf-{module}-feed` namespaced wrapper.
	 *
	 * @param string $module  Module slug (instagram|twitter|youtube).
	 * @param string $message Pre-translated empty-state copy.
	 */
	public static function render( string $module, string $message ): string {
		$module = sanitize_key( $module );
		return sprintf(
			'<div class="esf-%1$s-feed__empty"><p class="esf-%1$s-feed__empty-text">%2$s</p></div>',
			esc_attr( $module ),
			esc_html( $message )
		);
	}
}
