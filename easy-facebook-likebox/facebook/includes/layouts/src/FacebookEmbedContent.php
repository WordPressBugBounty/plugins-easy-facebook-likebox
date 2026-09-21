<?php
/**
 * Unified renderer for link cards and restricted-embed placeholders.
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
 * Chooses between a real link_card and a graceful embed placeholder.
 *
 * @since 6.7.9
 */
final class FacebookEmbedContent {

	/**
	 * Render link/event metadata or a restricted-embed placeholder from post extras.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $extras    Post extras from PostMapper.
	 * @param string              $permalink Post permalink for CTA fallback.
	 * @param array<string,mixed> $options   module (string), new_tab (bool).
	 * @return string
	 */
	public static function render_from_extras( array $extras, string $permalink, array $options = array() ): string {
		if (
			isset( $extras['link_card'] )
			&& is_array( $extras['link_card'] )
			&& class_exists( '\EasySocialFeed\Facebook\Layouts\FacebookLinkCard' )
		) {
			$html = FacebookLinkCard::render( $extras['link_card'], $options );
			if ( '' !== $html ) {
				return $html;
			}
		}

		if ( ! empty( $extras['embed_restricted'] ) && class_exists( '\EasySocialFeed\Facebook\Layouts\FacebookEmbedPlaceholder' ) ) {
			$kind = isset( $extras['embed_kind'] ) ? sanitize_key( (string) $extras['embed_kind'] ) : 'link';
			return FacebookEmbedPlaceholder::render( $kind, $permalink, $options );
		}

		return '';
	}
}
