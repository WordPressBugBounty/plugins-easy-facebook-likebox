<?php
/**
 * TikTok layout header support trait.
 *
 * Shared by tile layouts so header toggles stay consistent.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok\Layouts
 * @since 6.13.0
 */

namespace EasySocialFeed\TikTok\Layouts;

use EasySocialFeed\Layouts\Primitives\AccountHeader;
use EasySocialFeed\Layouts\Primitives\ExpandableCaption;
use EasySocialFeed\Layouts\Support\NumberFormatter;
use EasySocialFeed\Layouts\Support\Tooltip;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Header rendering helpers for TikTok layouts.
 *
 * @since 6.13.0
 */
trait TikTokHeaderSupport {

	/**
	 * Render the account header when enabled.
	 *
	 * @since 6.13.0
	 * @return string
	 */
	protected function render_tiktok_header(): string {
		if ( null === $this->account || ! (bool) $this->setting( 'header.show', true ) ) {
			return '';
		}

		$meta_html = $this->build_tiktok_header_meta_icons_html();

		$header   = $this->setting( 'header', array() );
		$header   = is_array( $header ) ? $header : array();
		$show_bio = true;
		if ( array_key_exists( 'show_bio', $header ) ) {
			$show_bio = (bool) $header['show_bio'];
		} elseif ( array_key_exists( 'show_description', $header ) ) {
			$show_bio = (bool) $header['show_description'];
		}

		if ( $show_bio ) {
			$bio = trim( (string) $this->account->get_bio() );
			if ( '' !== $bio ) {
				$see_more = function_exists( 'esf_get_translated_string' )
					? __( esf_get_translated_string( 'see_more' ), 'easy-facebook-likebox' ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
					: __( 'See more', 'easy-facebook-likebox' );
				$see_less = function_exists( 'esf_get_translated_string' )
					? __( esf_get_translated_string( 'see_less' ), 'easy-facebook-likebox' ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
					: __( 'See less', 'easy-facebook-likebox' );

				$meta_html .= '<div class="esf-tiktok-feed__header-bio">'
					. ExpandableCaption::render(
						$this->module,
						'<p>' . esc_html( $bio ) . '</p>',
						array(
							'words_limit'    => 28,
							'post_id'        => 'header-bio-' . (string) $this->account->get_id(),
							'see_more_label' => $see_more,
							'see_less_label' => $see_less,
						)
					)
					. '</div>';
			}
		}

		$has_plan = method_exists( $this, 'has_pro_plan' )
			? (bool) $this->has_pro_plan()
			: ( function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan() );

		$profile_url = trim( (string) $this->account->get_profile_url() );

		$header_options = array(
			'show_avatar'        => (bool) $this->setting( 'header.show_avatar', true ),
			'show_name'          => (bool) $this->setting( 'header.show_name', true ),
			'show_handle'        => (bool) $this->setting( 'header.show_handle', true ),
			'show_bio'           => false,
			'show_stats'         => array(),
			'meta_html'          => $meta_html,
			'show_link'          => $has_plan
				&& (bool) $this->setting( 'header.show_link', true )
				&& '' !== $profile_url,
			'banner_url'         => '',
			'show_follow_button' => (bool) $this->setting( 'header.show_follow_button', true ),
			'follow_label'       => esf_translate_registry_string( 'tt_follow_on_tiktok', 'Follow on TikTok' ),
		);

		if ( $has_plan ) {
			$header_options['rounded']     = (bool) $this->setting( 'header.header_rounded', true );
			$header_options['show_border'] = (bool) $this->setting( 'header.show_border', true );
			if ( ! (bool) $this->setting( 'header.avatar_round', true ) ) {
				$header_options['avatar_wrapper_class'] = 'esf-tiktok-feed__header-avatar-wrap--square';
			}
			$padding = max( 0, min( 48, (int) $this->setting( 'header.padding', 16 ) ) );
			if ( 16 !== $padding ) {
				$header_options['padding'] = $padding;
			}

			$border_color = sanitize_hex_color( (string) $this->setting( 'header.border_color', '' ) );
			if ( is_string( $border_color ) && '' !== $border_color ) {
				$header_options['border_color'] = $border_color;
			}

			$bg_color = sanitize_hex_color( (string) $this->setting( 'header.bg_color', '' ) );
			if ( is_string( $bg_color ) && '' !== $bg_color ) {
				$header_options['bg_color'] = $bg_color;
			}

			$text_color = sanitize_hex_color( (string) $this->setting( 'header.text_color', '' ) );
			if ( is_string( $text_color ) && '' !== $text_color ) {
				$header_options['text_color'] = $text_color;
			}
		}

		return AccountHeader::render(
			$this->module,
			$this->account,
			$header_options
		);
	}

	/**
	 * Icon + value meta row (followers / following / videos) with tooltips.
	 *
	 * @since 6.13.0
	 * @return string
	 */
	protected function build_tiktok_header_meta_icons_html(): string {
		if ( null === $this->account ) {
			return '';
		}

		$items = array();

		if ( (bool) $this->setting( 'header.show_followers', true ) ) {
			$followers = (int) $this->account->get_stat( 'followers' );
			if ( $followers > 0 ) {
				$items[] = array(
					'slug'  => 'followers',
					'label' => esf_translate_registry_string( 'followers', 'Followers' ),
					'value' => NumberFormatter::compact( $followers ),
					'icon'  => $this->tiktok_header_meta_icon_svg( 'followers' ),
				);
			}
		}

		if ( (bool) $this->setting( 'header.show_following', false ) ) {
			$following = (int) $this->account->get_stat( 'following' );
			if ( $following > 0 ) {
				$items[] = array(
					'slug'  => 'following',
					'label' => esf_translate_registry_string( 'tt_following', 'Following' ),
					'value' => NumberFormatter::compact( $following ),
					'icon'  => $this->tiktok_header_meta_icon_svg( 'following' ),
				);
			}
		}

		if ( (bool) $this->setting( 'header.show_videos', true ) ) {
			$videos = (int) $this->account->get_stat( 'videos' );
			if ( $videos > 0 ) {
				$items[] = array(
					'slug'  => 'videos',
					'label' => esf_translate_registry_string( 'tt_videos', 'Videos' ),
					'value' => NumberFormatter::compact( $videos ),
					'icon'  => $this->tiktok_header_meta_icon_svg( 'videos' ),
				);
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		$parts = '';
		foreach ( $items as $item ) {
			$parts .= sprintf(
				'<span class="esf-tiktok-feed__header-meta-icon esf-tiktok-feed__header-meta-icon--%1$s esf-tiktok-tooltip-target" %2$s>'
				. '<span class="esf-tiktok-feed__header-meta-icon-svg" aria-hidden="true">%3$s</span>'
				. '<span class="esf-tiktok-feed__header-meta-icon-value">%4$s</span>'
				. '</span>',
				esc_attr( $item['slug'] ),
				Tooltip::attributes( $item['label'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$item['icon'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
				esc_html( $item['value'] )
			);
		}

		return sprintf(
			'<div class="esf-tiktok-feed__header-meta-icons">%1$s</div>',
			$parts // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Inline SVG for a header meta icon.
	 *
	 * @since 6.13.0
	 * @param string $slug Icon slug.
	 * @return string
	 */
	protected function tiktok_header_meta_icon_svg( string $slug ): string {
		switch ( $slug ) {
			case 'followers':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>';
			case 'following':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>';
			case 'videos':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
			default:
				return '';
		}
	}
}
