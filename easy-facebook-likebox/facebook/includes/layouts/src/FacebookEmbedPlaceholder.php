<?php
/**
 * Placeholder UI for restricted Facebook attachment embeds.
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
 * Muted embed-unavailable card shown when Graph withholds shared content.
 *
 * @since 6.7.9
 */
final class FacebookEmbedPlaceholder {

	/**
	 * Render a contextual placeholder for a restricted embed.
	 *
	 * @since 6.7.9
	 *
	 * @param string              $kind      event|share|link.
	 * @param string              $permalink Post permalink (CTA target).
	 * @param array<string,mixed> $options   module (string), new_tab (bool).
	 * @return string
	 */
	public static function render( string $kind, string $permalink, array $options = array() ): string {
		$module  = isset( $options['module'] ) ? sanitize_key( (string) $options['module'] ) : 'facebook';
		$new_tab = ! isset( $options['new_tab'] ) || (bool) $options['new_tab'];
		$target  = $new_tab ? ' target="_blank" rel="noopener noreferrer nofollow"' : ' rel="nofollow"';
		$kind    = sanitize_key( $kind );

		if ( ! in_array( $kind, array( 'event', 'share', 'link', 'video' ), true ) ) {
			$kind = 'link';
		}

		$labels = self::labels_for_kind( $kind );
		$cta    = $labels['cta'];
		$href   = trim( $permalink );
		if ( '' === $href || ! preg_match( '#^https?://#i', $href ) ) {
			$href = '';
		}

		$html = sprintf(
			'<div class="esf-%1$s-feed__embed-placeholder esf-%1$s-feed__embed-placeholder--%2$s" role="note">',
			esc_attr( $module ),
			esc_attr( $kind )
		);

		$html .= sprintf(
			'<div class="esf-%1$s-feed__embed-placeholder-icon" aria-hidden="true">%2$s</div>',
			esc_attr( $module ),
			self::icon_svg( $kind ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup.
		);

		$html .= sprintf(
			'<p class="esf-%1$s-feed__embed-placeholder-label">%2$s</p>',
			esc_attr( $module ),
			esc_html( $labels['label'] )
		);

		$html .= sprintf(
			'<p class="esf-%1$s-feed__embed-placeholder-body">%2$s</p>',
			esc_attr( $module ),
			esc_html( $labels['body'] )
		);

		if ( '' !== $href ) {
			$html .= sprintf(
				'<a class="esf-%1$s-feed__embed-placeholder-action" href="%2$s"%3$s>%4$s</a>',
				esc_attr( $module ),
				esc_url( $href ),
				$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attrs.
				esc_html( $cta )
			);
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Translated copy for each embed kind.
	 *
	 * @since 6.7.9
	 * @param string $kind event|share|link.
	 * @return array{label:string,body:string,cta:string}
	 */
	private static function labels_for_kind( string $kind ): array {
		$view_fb = function_exists( 'esf_translate_registry_string' )
			? esf_translate_registry_string( 'view_on_facebook', 'View on Facebook' )
			: __( 'View on Facebook', 'easy-facebook-likebox' );

		switch ( $kind ) {
			case 'event':
				return array(
					'label' => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'fb_embed_shared_event', 'Shared event' )
						: __( 'Shared event', 'easy-facebook-likebox' ),
					'body'  => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'fb_embed_unavailable_body', 'Full details aren\'t available in the feed preview.' )
						: __( 'Full details aren\'t available in the feed preview.', 'easy-facebook-likebox' ),
					'cta'   => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'fb_embed_view_event', 'View event on Facebook' )
						: __( 'View event on Facebook', 'easy-facebook-likebox' ),
				);
			case 'video':
				return array(
					'label' => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'fb_embed_shared_video', 'Shared video' )
						: __( 'Shared video', 'easy-facebook-likebox' ),
					'body'  => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'fb_embed_unavailable_body', 'Full details aren\'t available in the feed preview.' )
						: __( 'Full details aren\'t available in the feed preview.', 'easy-facebook-likebox' ),
					'cta'   => $view_fb,
				);
			case 'share':
				return array(
					'label' => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'fb_embed_shared_post', 'Shared post' )
						: __( 'Shared post', 'easy-facebook-likebox' ),
					'body'  => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'fb_embed_unavailable_body', 'Full details aren\'t available in the feed preview.' )
						: __( 'Full details aren\'t available in the feed preview.', 'easy-facebook-likebox' ),
					'cta'   => $view_fb,
				);
			default:
				return array(
					'label' => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'fb_embed_link_preview', 'Link preview' )
						: __( 'Link preview', 'easy-facebook-likebox' ),
					'body'  => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'fb_embed_unavailable_body', 'Full details aren\'t available in the feed preview.' )
						: __( 'Full details aren\'t available in the feed preview.', 'easy-facebook-likebox' ),
					'cta'   => $view_fb,
				);
		}
	}

	/**
	 * Inline SVG icon per embed kind.
	 *
	 * @since 6.7.9
	 * @param string $kind event|share|link.
	 * @return string
	 */
	private static function icon_svg( string $kind ): string {
		if ( 'event' === $kind ) {
			return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
		}
		if ( 'video' === $kind ) {
			return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="8 5 19 12 8 19 8 5" fill="currentColor" stroke="none"/></svg>';
		}
		if ( 'share' === $kind ) {
			return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M16 6l-4-4-4 4"/><path d="M12 2v14"/></svg>';
		}
		return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
	}
}
