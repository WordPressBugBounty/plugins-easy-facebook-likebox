<?php
/**
 * Facebook layout header support trait.
 *
 * Builds AccountHeader options: name and stats, handle, about, Page cover,
 * website link, and an outbound Follow on Facebook CTA.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts
 * @since 6.7.9
 */

namespace EasySocialFeed\Facebook\Layouts;

use EasySocialFeed\Layouts\Primitives\AccountHeader;
use EasySocialFeed\Layouts\Primitives\ExpandableCaption;
use EasySocialFeed\Layouts\Support\Tooltip;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Header rendering helpers for Facebook layouts.
 *
 * @since 6.7.9
 */
trait FacebookHeaderSupport {

	/**
	 * Render the page header when enabled.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	protected function render_facebook_header(): string {
		if ( null === $this->account || ! (bool) $this->setting( 'header.show', true ) ) {
			return '';
		}

		$has_plan = method_exists( $this, 'has_pro_plan' )
			? (bool) $this->has_pro_plan()
			: ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() );

		$website = '';
		if ( method_exists( $this->account, 'get_website' ) ) {
			$website = trim( (string) $this->account->get_website() );
		}
		if ( '' !== $website && ! preg_match( '#^https?://#i', $website ) ) {
			$website = '';
		}

		$header_stats = array();
		if ( (bool) $this->setting( 'header.show_followers', true ) ) {
			$header_stats[] = 'followers';
		}

		$follow_label = function_exists( 'esf_translate_registry_string' )
			? esf_translate_registry_string( 'fb_follow_on_facebook', 'Follow on Facebook' )
			: __( 'Follow on Facebook', 'easy-facebook-likebox' );

		$followers_label = function_exists( 'esf_translate_registry_string' )
			? esf_translate_registry_string( 'followers', 'Followers' )
			: __( 'Followers', 'easy-facebook-likebox' );

		$header_options = array(
			'show_avatar'        => (bool) $this->setting( 'header.show_avatar', true ),
			'show_name'          => (bool) $this->setting( 'header.show_name', true ),
			'show_handle'        => (bool) $this->setting( 'header.show_handle', false ),
			// About is rendered below via ExpandableCaption (Instagram/LinkedIn pattern).
			'show_bio'           => false,
			'show_stats'         => $header_stats,
			'stat_display'       => 'icons',
			'stat_labels'        => array(
				'followers' => $followers_label,
			),
			'stats_extra_html'   => $this->build_facebook_header_category_stat_html(),
			'meta_html'          => $this->build_facebook_header_below_info_html(),
			'show_link'          => false,
			'banner_url'         => '',
			'show_follow_button' => (bool) $this->setting( 'header.show_follow_button', true ),
			'follow_label'       => $follow_label,
			// Free: square header corners (Pro toggle defaults on).
			'rounded'            => false,
		);

		if ( $has_plan ) {
			if ( (bool) $this->setting( 'header.show_banner', true ) ) {
				$cover = method_exists( $this->account, 'get_extra' )
					? trim( (string) $this->account->get_extra( 'cover_photo_url', '' ) )
					: '';
				if ( '' !== $cover && preg_match( '#^https?://#i', $cover ) ) {
					$header_options['banner_url']              = $cover;
					$header_options['banner_lightbox']         = true;
					$header_options['banner_lightbox_label']   = sprintf(
						/* translators: %s: Facebook Page name */
						__( 'View cover photo for %s', 'easy-facebook-likebox' ),
						(string) $this->account->get_name()
					);
					if ( function_exists( 'esf_facebook_flag_lightbox_script__premium_only' ) ) {
						esf_facebook_flag_lightbox_script__premium_only();
					}
				}
			}

			$header_options['show_link'] = (bool) $this->setting( 'header.show_link', true )
				&& '' !== $website;
			if ( ! empty( $header_options['show_link'] ) ) {
				$header_options['link_url'] = $website;
			}
			$header_options['rounded']     = (bool) $this->setting( 'header.header_rounded', true );
			$header_options['show_border'] = (bool) $this->setting( 'header.show_border', true );

			$avatar_round = (bool) $this->setting( 'header.avatar_round', true );
			if ( ! $avatar_round ) {
				$header_options['avatar_wrapper_class'] = 'esf-facebook-feed__header-avatar-wrap--square';
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
	 * About (expandable), rendered under the name/handle row.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	protected function build_facebook_header_below_info_html(): string {
		if ( null === $this->account || ! (bool) $this->setting( 'header.show_bio', true ) ) {
			return '';
		}

		$about = trim( (string) $this->account->get_bio() );
		if ( '' === $about ) {
			return '';
		}

		$see_more = function_exists( 'esf_translate_registry_string' )
			? esf_translate_registry_string( 'see_more', 'See more' )
			: __( 'See more', 'easy-facebook-likebox' );
		$see_less = function_exists( 'esf_translate_registry_string' )
			? esf_translate_registry_string( 'see_less', 'See less' )
			: __( 'See less', 'easy-facebook-likebox' );

		return '<div class="esf-facebook-feed__header-bio">'
			. ExpandableCaption::render(
				$this->module,
				'<p>' . esc_html( $about ) . '</p>',
				array(
					'words_limit'    => 28,
					'post_id'        => 'header-bio-' . (string) $this->account->get_id(),
					'see_more_label' => $see_more,
					'see_less_label' => $see_less,
				)
			)
			. '</div>';
	}

	/**
	 * Category chip for the stats row (next to followers), with shared tooltip.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	protected function build_facebook_header_category_stat_html(): string {
		if ( null === $this->account || ! (bool) $this->setting( 'header.show_category', true ) ) {
			return '';
		}

		$category = method_exists( $this->account, 'get_extra' )
			? trim( (string) $this->account->get_extra( 'category', '' ) )
			: '';
		if ( '' === $category ) {
			return '';
		}

		$label = function_exists( 'esf_translate_registry_string' )
			? esf_translate_registry_string( 'fb_category', 'Category' )
			: __( 'Category', 'easy-facebook-likebox' );
		$icon  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>';

		return sprintf(
			'<span class="esf-facebook-feed__header-stat esf-facebook-feed__header-stat--category esf-facebook-tooltip-target" %1$s>'
			. '%2$s<span class="esf-facebook-feed__header-stat-count">%3$s</span>'
			. '</span>',
			Tooltip::attributes( $label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
			esc_html( $category )
		);
	}

	/**
	 * Apply album total / "+N+ more" options for MediaCollage and MediaTile.
	 *
	 * @param array<string,mixed> $media_options Media options (by ref).
	 * @param mixed               $post          Post item.
	 * @param array<string,mixed> $extras        Post extras.
	 * @return void
	 */
	protected function apply_album_count_media_options( array &$media_options, $post, array $extras ): void {
		if (
			empty( $extras['album_total_count'] )
			&& empty( $extras['album_has_more'] )
			&& empty( $extras['album_count_is_minimum'] )
			&& ( ! isset( $extras['content_source'] ) || 'album' !== $extras['content_source'] )
		) {
			if ( isset( $extras['carousel_count'] ) && (int) $extras['carousel_count'] > 1 ) {
				$media_options['extra_count'] = max( 0, (int) $extras['carousel_count'] - 1 );
				$media_options['total_count'] = (int) $extras['carousel_count'];
			}
			return;
		}

		$mapped = ( is_object( $post ) && method_exists( $post, 'get_media' ) )
			? count( (array) $post->get_media() )
			: 0;
		$opts   = PostMapper::album_media_options_from_extras( $extras, $mapped );

		$media_options['total_count']     = $opts['total_count'];
		$media_options['extra_count']     = $opts['extra_count'];
		$media_options['more_is_minimum'] = $opts['more_is_minimum'];
	}

	/**
	 * Footer "View on Facebook" link (legacy `show_feed_view_on_facebook` parity).
	 *
	 * Renders beside the Share control in split/stacked card footers.
	 *
	 * @since 6.7.9
	 * @param string $permalink Post permalink.
	 * @param bool   $new_tab   Open in a new tab.
	 * @return string Empty when disabled or permalink is missing.
	 */
	protected function render_facebook_view_on_facebook_link( string $permalink, bool $new_tab = true, bool $icon_only = false ): string {
		$permalink = trim( $permalink );
		if ( '' === $permalink || ! preg_match( '#^https?://#i', $permalink ) ) {
			return '';
		}

		$slug = method_exists( $this, 'get_layout_settings_slug' )
			? (string) $this->get_layout_settings_slug()
			: 'half_width';

		if ( ! (bool) $this->setting( 'layout.' . $slug . '.show_view_on_facebook', true ) ) {
			return '';
		}

		$label = function_exists( 'esf_translate_registry_string' )
			? esf_translate_registry_string( 'view_on_facebook', 'View on Facebook' )
			: ( function_exists( 'esf_get_translated_string' )
				? (string) esf_get_translated_string( 'view_on_facebook' )
				: __( 'View on Facebook', 'easy-facebook-likebox' ) );

		$target = $new_tab ? ' target="_blank" rel="noopener noreferrer nofollow"' : ' rel="nofollow"';

		if ( $icon_only ) {
			return sprintf(
				'<a class="esf-facebook-feed__view-on-fb esf-facebook-feed__view-on-fb--icon" href="%1$s"%2$s aria-label="%3$s" title="%3$s">' .
				'<span class="esf-facebook-feed__view-on-fb-icon" aria-hidden="true">%4$s</span>' .
				'</a>',
				esc_url( $permalink ),
				$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attributes.
				esc_attr( $label ),
				self::facebook_view_on_facebook_icon() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
			);
		}

		return sprintf(
			'<a class="esf-facebook-feed__view-on-fb" href="%1$s"%2$s>%3$s</a>',
			esc_url( $permalink ),
			$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attributes.
			esc_html( $label )
		);
	}

	/**
	 * Inline external-link icon for icon-only "View on Facebook" controls.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	private static function facebook_view_on_facebook_icon(): string {
		return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">' .
			'<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>' .
			'<polyline points="15 3 21 3 21 9"/>' .
			'<line x1="10" y1="14" x2="21" y2="3"/>' .
			'</svg>';
	}
}
