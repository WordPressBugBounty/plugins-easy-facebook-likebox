<?php
/**
 * PostMeta primitive.
 *
 * Renders the metrics row (likes, comments, …) for a post card. Each metric
 * is opt-in; modules pass a labels map so "comments" can become "replies" or
 * stay "comments" depending on the network.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Primitives;

use EasySocialFeed\Layouts\Support\NumberFormatter;
use EasySocialFeed\Layouts\Support\Tooltip;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PostMeta {

	/**
	 * Render the meta row.
	 *
	 * @param string               $module  Module slug.
	 * @param array<string,int>    $metrics Metric values keyed by slug (likes, comments, …).
	 * @param array<string,string> $labels  Optional label override per metric slug.
	 * @param array<string,bool>   $show    Which metrics to render (keyed by slug).
	 * @param string               $tag     Wrapper element (`footer` or `div`). Use `div`
	 *                                      when nesting inside another footer (split cards).
	 * @param array<string,mixed>  $options Optional overrides (`metric_html` => slug => HTML).
	 */
	public static function render(
		string $module,
		array $metrics,
		array $labels = array(),
		array $show = array(),
		string $tag = 'footer',
		array $options = array()
	): string {
		$module      = sanitize_key( $module );
		$tag         = 'div' === $tag ? 'div' : 'footer';
		$metric_html = '';
		$overrides   = isset( $options['metric_html'] ) && is_array( $options['metric_html'] )
			? $options['metric_html']
			: array();

		foreach ( $metrics as $slug => $value ) {
			if ( isset( $show[ $slug ] ) && ! $show[ $slug ] ) {
				continue;
			}

			$slug = sanitize_key( (string) $slug );
			if ( isset( $overrides[ $slug ] ) && is_string( $overrides[ $slug ] ) && '' !== $overrides[ $slug ] ) {
				$metric_html .= $overrides[ $slug ];
				continue;
			}

			$value = (int) $value;
			$label = isset( $labels[ $slug ] ) ? (string) $labels[ $slug ] : ucfirst( str_replace( '_', ' ', $slug ) );
			$icon  = self::icon_for( $slug, $module );

			$metric_html .= sprintf(
				'<span class="esf-%1$s-feed__metric esf-%1$s-feed__metric--%2$s esf-%1$s-tooltip-target" %3$s>%4$s<span class="esf-%1$s-feed__metric-count">%5$s</span></span>',
				esc_attr( $module ),
				esc_attr( $slug ),
				Tooltip::attributes( $label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Tooltip::attributes returns escaped attributes.
				$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG markup.
				esc_html( NumberFormatter::compact( $value ) )
			);
		}

		if ( '' === $metric_html ) {
			return '';
		}

		return sprintf(
			'<%1$s class="esf-%2$s-feed__metrics">%3$s</%1$s>',
			$tag,
			esc_attr( $module ),
			$metric_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
		);
	}

	/**
	 * Inline SVG icon for a known metric slug. Falls back to a generic dot.
	 *
	 * Facebook uses a thumbs-up for likes; Instagram and others keep the heart.
	 *
	 * @param string $slug   Metric slug.
	 * @param string $module Module slug.
	 * @return string
	 */
	private static function icon_for( string $slug, string $module = '' ): string {
		switch ( $slug ) {
			case 'likes':
				if ( 'facebook' === $module ) {
					return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>';
				}
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
			case 'comments':
			case 'replies':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
			case 'views':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
			case 'shares':
			case 'retweets':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';
			case 'quotes':
				return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-8 8H8l-4 3v-5a8 8 0 0 1 0-12 8 8 0 0 1 8-2 8 8 0 0 1 9 8z"/><path d="M10.5 10H9v3h2.5v-1.3H10l.5-1.7z"/><path d="M15.5 10H14v3h2.5v-1.3H15l.5-1.7z"/></svg>';
			default:
				return '<svg width="6" height="6" viewBox="0 0 6 6" aria-hidden="true"><circle cx="3" cy="3" r="3" fill="currentColor"/></svg>';
		}
	}
}
