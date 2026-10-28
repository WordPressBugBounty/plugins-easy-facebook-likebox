<?php
/**
 * Facebook layout footer support trait.
 *
 * Renders the free "View all events" CTA below Events feeds (next to Load More
 * when Pro is active), matching legacy footer placement.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts
 * @since 6.7.9
 */

namespace EasySocialFeed\Facebook\Layouts;

use EasySocialFeed\Layouts\Primitives\FollowButton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Footer CTA helpers for Facebook layouts.
 *
 * @since 6.7.9
 */
trait FacebookFooterSupport {

	/**
	 * Metric tooltip labels from the shared translation registry.
	 *
	 * Used by Full Width / Half Width / Thumbnail so Settings → Translations
	 * (and .po/.mo) apply; shared AbstractStacked/Split defaults stay generic
	 * for Instagram and other modules.
	 *
	 * @since 6.7.9
	 * @return array{likes:string,comments:string,shares:string}
	 */
	protected function facebook_metric_labels(): array {
		return array(
			'likes'    => function_exists( 'esf_translate_registry_string' )
				? esf_translate_registry_string( 'fb_likes', 'Likes' )
				: __( 'Likes', 'easy-facebook-likebox' ),
			'comments' => function_exists( 'esf_translate_registry_string' )
				? esf_translate_registry_string( 'fb_comments', 'Comments' )
				: __( 'Comments', 'easy-facebook-likebox' ),
			'shares'   => function_exists( 'esf_translate_registry_string' )
				? esf_translate_registry_string( 'fb_shares', 'Shares' )
				: __( 'Shares', 'easy-facebook-likebox' ),
		);
	}

	/**
	 * Compose the feed footer: optional Load More + View all events CTA.
	 *
	 * @since 6.7.9
	 *
	 * @param string $load_more_html Escaped Load More markup (may be empty).
	 * @return string
	 */
	protected function compose_facebook_feed_footer( string $load_more_html = '' ): string {
		$view_all_html = $this->render_view_all_events_button();

		if ( '' === $view_all_html && '' === $load_more_html ) {
			return '';
		}

		if ( '' === $view_all_html ) {
			return $load_more_html;
		}

		return sprintf(
			'<div class="esf-facebook-feed__footer-actions">%1$s%2$s</div>',
			$load_more_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped Load More markup.
			$view_all_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
		);
	}

	/**
	 * Render the footer "View all events" button when enabled on an Events feed.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	protected function render_view_all_events_button(): string {
		$feed_type = isset( $this->feed->feed_type )
			? sanitize_key( (string) $this->feed->feed_type )
			: 'timeline';
		if ( 'events' !== $feed_type ) {
			return '';
		}

		if ( ! (bool) $this->setting( 'feed.show_view_all_events', true ) ) {
			return '';
		}

		$url = $this->resolve_view_all_events_url();
		if ( '' === $url ) {
			return '';
		}

		$custom_label = trim( (string) $this->setting( 'feed.view_all_events_label', '' ) );
		$label        = '' !== $custom_label
			? $custom_label
			: ( function_exists( 'esf_translate_registry_string' )
				? esf_translate_registry_string( 'view_all_events', 'View all events' )
				: __( 'View all events', 'easy-facebook-likebox' ) );

		$settings = $this->get_settings();
		$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();

		return FollowButton::render(
			$this->module,
			array(
				'url'              => $url,
				'label'            => $label,
				'new_tab'          => (bool) $this->setting( 'feed.links_new_tab', true ),
				'bg_color'         => isset( $feed_cfg['view_all_events_bg_color'] ) ? (string) $feed_cfg['view_all_events_bg_color'] : '',
				'text_color'       => isset( $feed_cfg['view_all_events_text_color'] ) ? (string) $feed_cfg['view_all_events_text_color'] : '',
				'hover_bg_color'   => isset( $feed_cfg['view_all_events_hover_bg_color'] ) ? (string) $feed_cfg['view_all_events_hover_bg_color'] : '',
				'hover_text_color' => isset( $feed_cfg['view_all_events_hover_text_color'] ) ? (string) $feed_cfg['view_all_events_hover_text_color'] : '',
			)
		);
	}

	/**
	 * Build the Facebook Page events URL for the connected source.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	protected function resolve_view_all_events_url(): string {
		if ( null !== $this->account && method_exists( $this->account, 'get_profile_url' ) ) {
			$profile = trim( (string) $this->account->get_profile_url() );
			if ( '' !== $profile && preg_match( '#^https?://#i', $profile ) ) {
				return untrailingslashit( $profile ) . '/events';
			}
		}

		$page_id = '';
		if ( is_object( $this->feed ) && ! empty( $this->feed->page_id ) && class_exists( '\ESF_Facebook_Page_Repository' ) ) {
			$page = \ESF_Facebook_Page_Repository::get_instance()->get_by_id( (int) $this->feed->page_id );
			if ( is_object( $page ) ) {
				if ( ! empty( $page->page_link ) && preg_match( '#^https?://#i', (string) $page->page_link ) ) {
					return untrailingslashit( (string) $page->page_link ) . '/events';
				}
				if ( ! empty( $page->username ) ) {
					$page_id = sanitize_text_field( (string) $page->username );
				} elseif ( ! empty( $page->page_id ) ) {
					$page_id = sanitize_text_field( (string) $page->page_id );
				}
			}
		}

		if ( '' === $page_id ) {
			return '';
		}

		return 'https://www.facebook.com/' . rawurlencode( $page_id ) . '/events';
	}
}
