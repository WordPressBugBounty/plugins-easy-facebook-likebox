<?php
/**
 * AccountHeader primitive.
 *
 * Renders the feed header (avatar, name, handle, optional stats and bio).
 * Each module passes its own profile/follow URL via the account so the
 * primitive stays network-agnostic.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Primitives;

use EasySocialFeed\Layouts\Support\NumberFormatter;
use EasySocialFeed\Layouts\Support\Tooltip;
use EasySocialFeed\Layouts\Support\VerifiedBadge;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccountHeader {

	/**
	 * Render the header.
	 *
	 * Options:
	 * - show_avatar (bool)
	 * - show_name (bool)
	 * - show_handle (bool)
	 * - show_bio (bool)
	 * - show_stats (string[] of stat keys to render in shown order)
	 * - stat_display (string) `icons` (icon + count + tooltip, X-style) or `labels` (count + text label).
	 * - show_follow_button (bool)
	 * - follow_label (string)
	 * - show_link (bool) When true, render the account website link below the header top row.
	 * - link_url (string) Optional override; defaults to account website.
	 * - link_label (string) Optional visible label; defaults to a host/path derived from link_url.
	 * - stat_labels (array<string,string>)
	 * - avatar_link (array{href?:string,class?:string,attrs?:string,target?:string})
	 * - avatar_wrapper_class (string) Extra class on the avatar link wrapper.
	 * - avatar_badge_html (string) Optional HTML rendered over the avatar (escaped by caller).
	 *
	 * @param string                                                  $module  Module slug.
	 * @param AccountSummary                                          $account Account data.
	 * @param array{
	 *     show_avatar?:bool,
	 *     show_name?:bool,
	 *     show_handle?:bool,
	 *     show_bio?:bool,
	 *     show_stats?:string[],
	 *     stat_display?:string,
	 *     show_follow_button?:bool,
	 *     follow_label?:string,
	 *     show_link?:bool,
	 *     link_url?:string,
	 *     link_label?:string,
	 *     stat_labels?:array<string,string>,
	 *     bg_color?:string,
	 *     text_color?:string,
	 *     rounded?:bool,
	 *     show_border?:bool,
	 *     border_color?:string,
	 *     padding?:int
	 * } $options Rendering options.
	 */
	public static function render( string $module, AccountSummary $account, array $options = array() ): string {
		$module             = sanitize_key( $module );
		$show_avatar        = ! isset( $options['show_avatar'] ) || (bool) $options['show_avatar'];
		$show_name          = ! isset( $options['show_name'] ) || (bool) $options['show_name'];
		$show_handle        = ! isset( $options['show_handle'] ) || (bool) $options['show_handle'];
		$show_bio           = ! isset( $options['show_bio'] ) || (bool) $options['show_bio'];
		$show_stats         = isset( $options['show_stats'] ) && is_array( $options['show_stats'] )
			? array_values( array_map( 'sanitize_key', $options['show_stats'] ) )
			: array();
		$stat_display       = isset( $options['stat_display'] ) ? sanitize_key( (string) $options['stat_display'] ) : 'labels';
		$show_follow_button = ! empty( $options['show_follow_button'] );
		$follow_label       = isset( $options['follow_label'] ) ? (string) $options['follow_label'] : '';
		$show_link          = ! empty( $options['show_link'] );
		$link_url           = isset( $options['link_url'] ) ? \esc_url_raw( (string) $options['link_url'] ) : '';
		if ( '' === $link_url && $show_link && method_exists( $account, 'get_website' ) ) {
			$link_url = \esc_url_raw( $account->get_website() );
		}
		$link_label = isset( $options['link_label'] ) ? (string) $options['link_label'] : '';
		if ( '' === $link_label && '' !== $link_url ) {
			$link_label = self::format_link_label( $link_url );
		}
		$stat_labels        = isset( $options['stat_labels'] ) && is_array( $options['stat_labels'] )
			? $options['stat_labels']
			: array();
		$use_stat_icons     = 'icons' === $stat_display;

		$name        = $account->get_name();
		$handle      = $account->get_handle();
		$avatar_url  = $account->get_avatar_url();
		$profile_url = $account->get_profile_url();
		$bio         = $account->get_bio();
		$verified    = VerifiedBadge::render( $account->is_verified() );

		$avatar_html = '';
		$avatar_icon = isset( $options['avatar_icon_html'] ) ? (string) $options['avatar_icon_html'] : '';
		if ( $show_avatar && '' !== $avatar_url ) {
			$avatar_link = isset( $options['avatar_link'] ) && is_array( $options['avatar_link'] )
				? $options['avatar_link']
				: array();

			$link_href  = isset( $avatar_link['href'] ) && '' !== (string) $avatar_link['href']
				? (string) $avatar_link['href']
				: $profile_url;
			$link_class = 'esf-' . $module . '-feed__header-avatar-link';
			if ( isset( $avatar_link['class'] ) && '' !== trim( (string) $avatar_link['class'] ) ) {
				$extra_link_classes = preg_split( '/\s+/', trim( (string) $avatar_link['class'] ) );
				if ( is_array( $extra_link_classes ) ) {
					foreach ( $extra_link_classes as $extra_class ) {
						$extra_class = sanitize_html_class( (string) $extra_class );
						if ( '' !== $extra_class ) {
							$link_class .= ' ' . $extra_class;
						}
					}
				}
			}

			$use_external_target = '#' !== $link_href && 0 !== strpos( $link_href, 'javascript' );
			$link_target         = isset( $avatar_link['target'] )
				? (string) $avatar_link['target']
				: ( $use_external_target ? '_blank' : '' );
			$link_rel            = '_blank' === $link_target ? 'noopener noreferrer' : '';
			$link_attrs          = isset( $avatar_link['attrs'] ) ? (string) $avatar_link['attrs'] : '';

			$wrapper_class = 'esf-' . $module . '-feed__header-avatar-wrap';
			if ( isset( $options['avatar_wrapper_class'] ) && '' !== trim( (string) $options['avatar_wrapper_class'] ) ) {
				$extra_classes = preg_split( '/\s+/', trim( (string) $options['avatar_wrapper_class'] ) );
				if ( is_array( $extra_classes ) ) {
					foreach ( $extra_classes as $extra_class ) {
						$extra_class = sanitize_html_class( (string) $extra_class );
						if ( '' !== $extra_class ) {
							$wrapper_class .= ' ' . $extra_class;
						}
					}
				}
			}

			$badge_html = isset( $options['avatar_badge_html'] ) ? (string) $options['avatar_badge_html'] : '';

			$target_attr = '' !== $link_target ? ' target="' . esc_attr( $link_target ) . '"' : '';
			$rel_attr    = '' !== $link_rel ? ' rel="' . esc_attr( $link_rel ) . '"' : '';

			$avatar_html = sprintf(
				'<span class="%1$s">'
				. '<a class="%2$s" href="%3$s"%4$s%5$s%6$s>'
				. '<img class="esf-%7$s-feed__header-avatar" src="%8$s" alt="%9$s" width="48" height="48" loading="lazy" />'
				. '%10$s'
				. '</a>'
				. '</span>',
				esc_attr( $wrapper_class ),
				esc_attr( $link_class ),
				esc_url( $link_href ),
				$target_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr above.
				$rel_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr above.
				$link_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller supplies vetted attrs.
				esc_attr( $module ),
				esc_url( $avatar_url ),
				esc_attr( $name ),
				$badge_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by trusted module callers.
			);
		} elseif ( $show_avatar && '' !== $avatar_icon ) {
			$avatar_link = isset( $options['avatar_link'] ) && is_array( $options['avatar_link'] )
				? $options['avatar_link']
				: array();

			$link_href  = isset( $avatar_link['href'] ) && '' !== (string) $avatar_link['href']
				? (string) $avatar_link['href']
				: $profile_url;
			$link_class = 'esf-' . $module . '-feed__header-avatar-link';
			$use_external_target = '#' !== $link_href && 0 !== strpos( $link_href, 'javascript' );
			$link_target         = isset( $avatar_link['target'] )
				? (string) $avatar_link['target']
				: ( $use_external_target ? '_blank' : '' );
			$link_rel            = '_blank' === $link_target ? 'noopener noreferrer' : '';
			$target_attr         = '' !== $link_target ? ' target="' . esc_attr( $link_target ) . '"' : '';
			$rel_attr            = '' !== $link_rel ? ' rel="' . esc_attr( $link_rel ) . '"' : '';

			$avatar_html = sprintf(
				'<span class="esf-%1$s-feed__header-avatar-wrap">'
				. '<a class="%2$s" href="%3$s"%4$s%5$s>'
				. '%6$s'
				. '</a>'
				. '</span>',
				esc_attr( $module ),
				esc_attr( $link_class ),
				esc_url( $link_href ),
				$target_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr above.
				$rel_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr above.
				$avatar_icon // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by trusted module callers.
			);
		}

		$name_html = '';
		if ( $show_name && '' !== $name ) {
			$name_html = sprintf(
				'<span class="esf-%1$s-feed__header-name-wrap">'
				. '<a class="esf-%1$s-feed__header-name" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>%4$s'
				. '</span>',
				esc_attr( $module ),
				esc_url( $profile_url ),
				esc_html( $name ),
				$verified // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- VerifiedBadge returns escaped markup.
			);
		}

		$handle_html = '';
		if ( $show_handle && '' !== $handle ) {
			$handle_html = sprintf(
				'<span class="esf-%1$s-feed__header-handle">@%2$s</span>',
				esc_attr( $module ),
				esc_html( $handle )
			);
		}

		$stats_html = self::render_stats( $module, $account, $show_stats, $stat_labels, $use_stat_icons );

		$info_class = 'esf-' . $module . '-feed__header-info';
		if ( $use_stat_icons && '' !== $stats_html ) {
			$info_class .= ' esf-' . $module . '-feed__header-info--stats-icons';
		}

		if ( $use_stat_icons ) {
			$info_inner = $name_html . $stats_html . $handle_html;
		} else {
			$info_inner = $name_html . $handle_html . $stats_html;
		}

		$follow_html = '';
		if ( $show_follow_button && '' !== $follow_label ) {
			$follow_html = sprintf(
				'<a class="esf-%1$s-feed__header-follow" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
				esc_attr( $module ),
				esc_url( $profile_url ),
				esc_html( $follow_label )
			);
		}

		$link_html = '';
		if ( $show_link && '' !== $link_url && '' !== $link_label ) {
			$link_html = sprintf(
				'<a class="esf-%1$s-feed__header-link" href="%2$s" target="_blank" rel="noopener noreferrer">'
				. '<span class="esf-%1$s-feed__header-link-icon" aria-hidden="true">%4$s</span>'
				. '<span class="esf-%1$s-feed__header-link-label">%3$s</span>'
				. '</a>',
				esc_attr( $module ),
				esc_url( $link_url ),
				esc_html( $link_label ),
				self::render_link_icon()
			);
		}

		$bio_html = '';
		if ( $show_bio && '' !== $bio ) {
			$bio_html = sprintf(
				'<p class="esf-%1$s-feed__header-bio">%2$s</p>',
				esc_attr( $module ),
				esc_html( $bio )
			);
		}

		$style_attr = '';
		$style_parts = array();
		$bg_color     = isset( $options['bg_color'] ) ? self::sanitize_css_color( (string) $options['bg_color'] ) : '';
		$text_color   = isset( $options['text_color'] ) ? self::sanitize_css_color( (string) $options['text_color'] ) : '';
		$border_color = isset( $options['border_color'] ) ? self::sanitize_css_color( (string) $options['border_color'] ) : '';
		$show_border  = ! isset( $options['show_border'] ) || (bool) $options['show_border'];
		$padding      = isset( $options['padding'] ) ? (int) $options['padding'] : null;

		if ( '' !== $bg_color ) {
			$style_parts[] = 'background-color:' . $bg_color;
		}
		if ( '' !== $text_color ) {
			$style_parts[] = 'color:' . $text_color;
		}
		if ( ! $show_border ) {
			$style_parts[] = 'border:none';
		} elseif ( '' !== $border_color ) {
			$style_parts[] = 'border-color:' . $border_color;
		}
		if ( null !== $padding ) {
			$padding = max( 0, min( 48, $padding ) );
			$style_parts[] = 'padding:' . $padding . 'px';
		}
		if ( ! empty( $style_parts ) ) {
			$style_attr = ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"';
		}

		$rounded      = ! isset( $options['rounded'] ) || (bool) $options['rounded'];
		$header_class = 'esf-' . $module . '-feed__header';
		if ( ! $rounded ) {
			$header_class .= ' esf-' . $module . '-feed__header--square-corners';
		}
		if ( ! $show_border ) {
			$header_class .= ' esf-' . $module . '-feed__header--no-border';
		}
		if ( '' !== $text_color ) {
			$header_class .= ' esf-' . $module . '-feed__header--custom-text';
		}

		return sprintf(
			'<header class="%1$s"%2$s>'
			. '<div class="esf-%3$s-feed__header-inner">%4$s'
			. '<div class="esf-%3$s-feed__header-meta">'
			. '<div class="esf-%3$s-feed__header-top">'
			. '<div class="%5$s">%6$s</div>'
			. '%7$s'
			. '</div>'
			. '%8$s'
			. '%9$s'
			. '</div>'
			. '</div>'
			. '</header>',
			esc_attr( $header_class ),
			$style_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr above.
			esc_attr( $module ),
			$avatar_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
			esc_attr( $info_class ),
			$info_inner, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
			$follow_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
			$bio_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
			$link_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
		);
	}

	/**
	 * Format a URL for display as a header link label (host + optional path).
	 *
	 * @param string $url Absolute URL.
	 * @return string Display label without protocol.
	 */
	private static function format_link_label( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parsed = \wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return preg_replace( '#^https?://#i', '', $url ) ?? $url;
		}

		$label = (string) $parsed['host'];
		if ( isset( $parsed['path'] ) && '' !== $parsed['path'] && '/' !== $parsed['path'] ) {
			$label .= \untrailingslashit( (string) $parsed['path'] );
		}

		return $label;
	}

	/**
	 * Inline SVG for the header website link icon.
	 */
	private static function render_link_icon(): string {
		return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
	}

	/**
	 * Sanitize a hex color for inline CSS.
	 *
	 * @param string $color Raw color value.
	 * @return string Sanitized `#rgb` or `#rrggbb`, or empty string.
	 */
	private static function sanitize_css_color( string $color ): string {
		$color = trim( $color );
		if ( preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ) {
			return $color;
		}

		return '';
	}

	/**
	 * Render the stats row inside the header.
	 *
	 * @param string               $module          Module slug.
	 * @param AccountSummary       $account         Account data.
	 * @param string[]             $show_stats      Stat keys to render in order.
	 * @param array<string,string> $stat_labels     Optional label overrides.
	 * @param bool                 $use_stat_icons  When true, render icon + count with tooltip (X-style).
	 */
	private static function render_stats(
		string $module,
		AccountSummary $account,
		array $show_stats,
		array $stat_labels,
		bool $use_stat_icons = false
	): string {
		if ( empty( $show_stats ) ) {
			return '';
		}

		$items = '';
		foreach ( $show_stats as $key ) {
			$value = $account->get_stat( $key );
			if ( $value <= 0 ) {
				continue;
			}

			$label = isset( $stat_labels[ $key ] ) ? (string) $stat_labels[ $key ] : ucfirst( str_replace( '_', ' ', $key ) );

			if ( $use_stat_icons ) {
				$tooltip = Tooltip::attributes( $label );
				$icon    = self::render_stat_icon( $key );
				$items  .= sprintf(
					'<span class="esf-%1$s-feed__header-stat esf-%1$s-feed__header-stat--%2$s esf-%1$s-tooltip-target" %3$s>'
					. '%4$s<span class="esf-%1$s-feed__header-stat-count">%5$s</span>'
					. '</span>',
					esc_attr( $module ),
					esc_attr( sanitize_key( $key ) ),
					$tooltip, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Tooltip::attributes returns escaped attrs.
					$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG markup.
					esc_html( NumberFormatter::compact( $value ) )
				);
				continue;
			}

			$items .= sprintf(
				'<span class="esf-%1$s-feed__header-stat esf-%1$s-feed__header-stat--%2$s"><span class="esf-%1$s-feed__header-stat-count">%3$s</span><span class="esf-%1$s-feed__header-stat-label">%4$s</span></span>',
				esc_attr( $module ),
				esc_attr( sanitize_key( $key ) ),
				esc_html( NumberFormatter::compact( $value ) ),
				esc_html( $label )
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return sprintf(
			'<div class="esf-%1$s-feed__header-stats">%2$s</div>',
			esc_attr( $module ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
		);
	}

	/**
	 * Inline SVG for a header stat icon.
	 */
	private static function render_stat_icon( string $key ): string {
		switch ( sanitize_key( $key ) ) {
			case 'followers':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>';
			case 'following':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
			case 'media':
			case 'posts':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>';
			default:
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/></svg>';
		}
	}
}
