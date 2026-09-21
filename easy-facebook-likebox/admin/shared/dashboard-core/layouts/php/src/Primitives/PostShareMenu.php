<?php
/**
 * PostShareMenu primitive.
 *
 * Native `<details>` share popover (same pattern as the X/Twitter feed share
 * menu). Modules pass network configs so Instagram / Facebook / X can share
 * the markup without duplicating intent URLs.
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
 * Share menu for a post permalink.
 *
 * @since 6.9.0
 */
final class PostShareMenu {

	/**
	 * Render a share menu.
	 *
	 * Options:
	 * - label (string): summary label (default "Share").
	 * - icon_only (bool): icon button without visible label (aria-label preserved).
	 * - networks (array<int,array{slug:string,label:string,url:string}>): share targets.
	 * - new_tab (bool, default true).
	 *
	 * @param string              $module    Module slug.
	 * @param string              $permalink Post URL to share.
	 * @param array<string,mixed> $options   Rendering options.
	 * @return string Empty when permalink or networks are missing.
	 */
	public static function render( string $module, string $permalink, array $options = array() ): string {
		$permalink = esc_url_raw( $permalink );
		if ( '' === $permalink ) {
			return '';
		}

		$module   = sanitize_key( $module );
		$networks = isset( $options['networks'] ) && is_array( $options['networks'] )
			? $options['networks']
			: self::default_networks( $permalink );

		$links = '';
		foreach ( $networks as $network ) {
			if ( ! is_array( $network ) ) {
				continue;
			}
			$slug  = isset( $network['slug'] ) ? sanitize_key( (string) $network['slug'] ) : '';
			$label = isset( $network['label'] ) ? (string) $network['label'] : '';
			$url   = isset( $network['url'] ) ? esc_url_raw( (string) $network['url'] ) : '';
			if ( '' === $slug || '' === $label || '' === $url ) {
				continue;
			}
			$links .= sprintf(
				'<a class="esf-%1$s-feed__share-link esf-%1$s-feed__share-link--%2$s" href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a>',
				esc_attr( $module ),
				esc_attr( $slug ),
				esc_url( $url ),
				esc_html( $label )
			);
		}

		if ( '' === $links ) {
			return '';
		}

		$label     = isset( $options['label'] ) && '' !== (string) $options['label']
			? (string) $options['label']
			: __( 'Share', 'easy-facebook-likebox' );
		$icon_only = ! empty( $options['icon_only'] );
		$icon      = self::share_icon();

		$summary_class = 'esf-' . $module . '-feed__share-summary';
		if ( $icon_only ) {
			$summary_class .= ' esf-' . $module . '-feed__share-summary--icon-only';
		}

		$summary_attrs = $icon_only
			? sprintf( ' aria-label="%s" title="%s"', esc_attr( $label ), esc_attr( $label ) )
			: '';

		$label_markup = $icon_only
			? ''
			: sprintf(
				'<span class="esf-%1$s-feed__share-label">%2$s</span>',
				esc_attr( $module ),
				esc_html( $label )
			);

		return sprintf(
			'<details class="esf-%1$s-feed__share-menu%5$s">' .
			'<summary class="%6$s"%7$s>' .
			'<span class="esf-%1$s-feed__share-icon" aria-hidden="true">%2$s</span>' .
			'%3$s' .
			'</summary>' .
			'<div class="esf-%1$s-feed__share-popover">%4$s</div>' .
			'</details>',
			esc_attr( $module ),
			$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
			$label_markup, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with escaping above.
			$links, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with escaping above.
			$icon_only ? ' esf-' . esc_attr( $module ) . '-feed__share-menu--icon-only' : '',
			esc_attr( $summary_class ),
			$summary_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with escaping above.
		);
	}

	/**
	 * Default Facebook / X / LinkedIn / WhatsApp share targets.
	 *
	 * @param string $permalink Absolute post URL.
	 * @return array<int,array{slug:string,label:string,url:string}>
	 */
	public static function default_networks( string $permalink ): array {
		$encoded = rawurlencode( $permalink );

		return array(
			array(
				'slug'  => 'facebook',
				'label' => function_exists( 'esf_get_translated_string' )
					? __( esf_get_translated_string( 'tw_share_on_facebook' ), 'easy-facebook-likebox' ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic registry string.
					: __( 'Share on Facebook', 'easy-facebook-likebox' ),
				'url'   => 'https://www.facebook.com/sharer/sharer.php?u=' . $encoded,
			),
			array(
				'slug'  => 'x',
				'label' => function_exists( 'esf_get_translated_string' )
					? __( esf_get_translated_string( 'tw_share_on_x' ), 'easy-facebook-likebox' ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
					: __( 'Share on X', 'easy-facebook-likebox' ),
				'url'   => 'https://x.com/intent/tweet?url=' . $encoded,
			),
			array(
				'slug'  => 'linkedin',
				'label' => function_exists( 'esf_get_translated_string' )
					? __( esf_get_translated_string( 'tw_share_on_linkedin' ), 'easy-facebook-likebox' ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
					: __( 'Share on LinkedIn', 'easy-facebook-likebox' ),
				'url'   => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encoded,
			),
			array(
				'slug'  => 'whatsapp',
				'label' => function_exists( 'esf_get_translated_string' )
					? __( esf_get_translated_string( 'tw_share_on_whatsapp' ), 'easy-facebook-likebox' ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
					: __( 'Share on WhatsApp', 'easy-facebook-likebox' ),
				'url'   => 'https://wa.me/?text=' . $encoded,
			),
		);
	}

	/**
	 * Inline share icon SVG.
	 *
	 * @return string
	 */
	private static function share_icon(): string {
		return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>';
	}
}
