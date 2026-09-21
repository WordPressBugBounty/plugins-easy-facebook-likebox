<?php
/**
 * Instagram layout footer support trait.
 *
 * Renders the free "Follow on Instagram" CTA below the feed (next to Load More
 * when Pro is active), matching the legacy footer placement.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Instagram\Layouts;

use EasySocialFeed\Layouts\Primitives\FollowButton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Footer follow button helpers for Instagram layouts.
 *
 * @since 6.9.0
 */
trait InstagramFooterSupport {

	/**
	 * Compose the feed footer: optional Load More + Follow CTA.
	 *
	 * @since 6.9.0
	 *
	 * @param string $load_more_html Escaped Load More markup (may be empty).
	 * @return string
	 */
	protected function compose_instagram_feed_footer( string $load_more_html = '' ): string {
		$follow_html = $this->render_instagram_follow_button();

		if ( '' === $follow_html && '' === $load_more_html ) {
			return '';
		}

		if ( '' === $follow_html ) {
			return $load_more_html;
		}

		return sprintf(
			'<div class="esf-instagram-feed__footer-actions">%1$s%2$s</div>',
			$load_more_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped Load More markup.
			$follow_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
		);
	}

	/**
	 * Render the footer "Follow on Instagram" button when enabled.
	 *
	 * Available in free. Distinct from the header follow toggle
	 * (`header.show_follow_button`).
	 *
	 * @since 6.9.0
	 * @return string
	 */
	protected function render_instagram_follow_button(): string {
		if ( ! (bool) $this->setting( 'feed.show_follow_button', true ) ) {
			return '';
		}

		// Multifeed combines accounts — no single profile to follow.
		if (
			function_exists( 'esf_instagram_feed_is_multifeed' )
			&& esf_instagram_feed_is_multifeed( $this->feed )
		) {
			return '';
		}

		if ( null === $this->account ) {
			return '';
		}

		$url = '';
		if ( method_exists( $this->account, 'get_profile_url' ) ) {
			$url = \esc_url_raw( (string) $this->account->get_profile_url() );
		}

		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$custom_label = trim( (string) $this->setting( 'feed.follow_button_label', '' ) );
		$label        = '' !== $custom_label
			? $custom_label
			: esf_translate_registry_string( 'follow_on_instagram', 'Follow on Instagram' );

		$settings = $this->get_settings();
		$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();

		return FollowButton::render(
			$this->module,
			array(
				'url'              => $url,
				'label'            => $label,
				'new_tab'          => (bool) $this->setting( 'feed.links_new_tab', true ),
				'icon_html'        => $this->render_instagram_follow_icon(),
				'bg_color'         => isset( $feed_cfg['follow_button_bg_color'] ) ? (string) $feed_cfg['follow_button_bg_color'] : '',
				'text_color'       => isset( $feed_cfg['follow_button_text_color'] ) ? (string) $feed_cfg['follow_button_text_color'] : '',
				'hover_bg_color'   => isset( $feed_cfg['follow_button_hover_bg_color'] ) ? (string) $feed_cfg['follow_button_hover_bg_color'] : '',
				'hover_text_color' => isset( $feed_cfg['follow_button_hover_text_color'] ) ? (string) $feed_cfg['follow_button_hover_text_color'] : '',
			)
		);
	}

	/**
	 * Inline Instagram camera icon for the footer follow CTA.
	 *
	 * @since 6.9.0
	 * @return string
	 */
	protected function render_instagram_follow_icon(): string {
		return '<svg class="esf-instagram-feed__follow-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>';
	}
}
