<?php
/**
 * Facebook reaction type icons + labels for feed/popup metrics.
 *
 * Similar to LinkedIn's reaction system, renders Facebook reaction icons
 * (stacked summary) with hover breakdown.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts
 * @since 6.7.9
 */

namespace EasySocialFeed\Facebook\Layouts;

use EasySocialFeed\Layouts\Support\NumberFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Facebook reaction icons (stacked summary) with hover breakdown.
 *
 * Feed/popup: up to 3 colored Facebook badges + one total. Hover/focus reveals
 * a compact chip strip with per-type counts.
 *
 * @since 6.7.9
 */
final class FacebookReactionIcons {

	/**
	 * Display order matching Facebook's common reaction strip.
	 *
	 * @var array<int,string>
	 */
	const ORDER = array(
		'LIKE',
		'LOVE',
		'CARE',
		'HAHA',
		'WOW',
		'SAD',
		'ANGRY',
	);

	/**
	 * Normalize a reactions map into an ordered list of {type,count}.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,int>|array<int,array{type?:string,count?:int}> $reactions Raw map or list.
	 * @return array<int,array{type:string,count:int}>
	 */
	public static function normalize( array $reactions ): array {
		$map = array();

		foreach ( $reactions as $key => $value ) {
			$type  = '';
			$count = 0;
			if ( is_array( $value ) ) {
				if ( isset( $value['type'] ) && is_string( $value['type'] ) ) {
					$type = strtoupper( trim( $value['type'] ) );
				}
				if ( isset( $value['count'] ) ) {
					$count = (int) $value['count'];
				}
			} elseif ( is_string( $key ) && is_numeric( $value ) ) {
				$type  = strtoupper( trim( $key ) );
				$count = (int) $value;
			}
			if ( '' === $type || $count <= 0 ) {
				continue;
			}
			if ( ! isset( $map[ $type ] ) ) {
				$map[ $type ] = 0;
			}
			$map[ $type ] += $count;
		}

		$ordered = array();
		foreach ( self::ORDER as $type ) {
			if ( isset( $map[ $type ] ) ) {
				$ordered[] = array(
					'type'  => $type,
					'count' => (int) $map[ $type ],
				);
				unset( $map[ $type ] );
			}
		}
		foreach ( $map as $type => $count ) {
			$ordered[] = array(
				'type'  => (string) $type,
				'count' => (int) $count,
			);
		}

		return $ordered;
	}

	/**
	 * Sum reaction counts.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,int>|array<int,array{type?:string,count?:int}> $reactions Reactions.
	 * @return int
	 */
	public static function total( array $reactions ): int {
		$total = 0;
		foreach ( self::normalize( $reactions ) as $item ) {
			$total += (int) $item['count'];
		}
		return $total;
	}

	/**
	 * Human label for a reaction type.
	 *
	 * @since 6.7.9
	 * @param string $type Reaction enum.
	 * @return string
	 */
	public static function label_for( string $type ): string {
		switch ( strtoupper( $type ) ) {
			case 'LIKE':
				return esf_translate_registry_string( 'fb_reaction_like', 'Like' );
			case 'LOVE':
				return esf_translate_registry_string( 'fb_reaction_love', 'Love' );
			case 'CARE':
				return esf_translate_registry_string( 'fb_reaction_care', 'Care' );
			case 'HAHA':
				return esf_translate_registry_string( 'fb_reaction_haha', 'Haha' );
			case 'WOW':
				return esf_translate_registry_string( 'fb_reaction_wow', 'Wow' );
			case 'SAD':
				return esf_translate_registry_string( 'fb_reaction_sad', 'Sad' );
			case 'ANGRY':
				return esf_translate_registry_string( 'fb_reaction_angry', 'Angry' );
			default:
				return esf_translate_registry_string( 'fb_reactions', 'Reactions' );
		}
	}

	/**
	 * Render feed/card metric HTML: stacked icons + total, hover chip breakdown.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,int>|array<int,array{type?:string,count?:int}> $reactions Reactions.
	 * @param string                                                      $module    Module slug.
	 * @param string                                                      $variant   `feed` or `carousel`.
	 * @param array{total?:int}                                           $options   Optional total override.
	 * @return string Empty when nothing to show.
	 */
	public static function render_metric_html( array $reactions, string $module = 'facebook', string $variant = 'feed', array $options = array() ): string {
		$list    = self::normalize( $reactions );
		$module  = sanitize_key( $module );
		$variant = 'carousel' === $variant ? 'carousel' : 'feed';
		$total   = isset( $options['total'] ) ? max( 0, (int) $options['total'] ) : self::total( $list );
		if ( $total <= 0 && array() !== $list ) {
			$total = self::total( $list );
		}

		// Zero / missing breakdown: still show a Like outline + count so
		// empty posts match the comments-style metric affordance.
		if ( array() === $list ) {
			if ( empty( $options['fallback_like'] ) ) {
				return '';
			}
			$list  = array(
				array(
					'type'  => 'LIKE',
					'count' => $total,
				),
			);
			$total = max( 0, $total );
		}

		$aria_bits = array();
		foreach ( $list as $item ) {
			$aria_bits[] = NumberFormatter::compact( $item['count'] ) . ' ' . self::label_for( $item['type'] );
		}
		$aria_label = implode( ', ', $aria_bits );

		// Feed cluster: every present reaction type as a stroke outline
		// (currentColor) so Design text_color / footer colors recolor them —
		// same weight as the comments metric icon.
		$stack = '<span class="esf-facebook-feed__reaction-stack" aria-hidden="true">';
		foreach ( $list as $item ) {
			$stack .= self::icon_svg( $item['type'], 'simple' );
		}
		$stack .= '</span>';

		$count_html = '<span class="esf-' . esc_attr( $module ) . '-feed__metric-count esf-facebook-feed__reactions-total" data-metric="likes" data-value="' .
			esc_attr( (string) $total ) . '">' .
			esc_html( NumberFormatter::compact( $total ) ) .
			'</span>';

		// Breakdown hover is Pro-only. Free keeps the stacked icons + total.
		$chips            = '';
		$can_show_popover = function_exists( 'esf_facebook_has_facebook_plan' )
			&& esf_facebook_has_facebook_plan();
		if ( $can_show_popover && count( $list ) > 1 && $total > 0 ) {
			$chips = '<span class="esf-facebook-feed__reactions-popover" role="tooltip">';
			foreach ( $list as $item ) {
				$chips .= sprintf(
					'<span class="esf-facebook-feed__reactions-chip" title="%1$s">%2$s<span class="esf-facebook-feed__reactions-chip-count">%3$s</span></span>',
					esc_attr( self::label_for( $item['type'] ) ),
					self::icon_svg( $item['type'], 'simple' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( NumberFormatter::compact( $item['count'] ) )
				);
			}
			$chips .= '</span>';
		}

		$metric_class = 'carousel' === $variant
			? sprintf(
				'esf-%1$s-feed__carousel-metric esf-%1$s-feed__reactions-summary',
				esc_attr( $module )
			)
			: sprintf(
				'esf-%1$s-feed__metric esf-%1$s-feed__reactions-summary',
				esc_attr( $module )
			);

		if ( '' === $chips ) {
			$metric_class .= ' esf-facebook-feed__reactions-summary--no-popover';
		}

		return sprintf(
			'<span class="%1$s" tabindex="0" aria-label="%2$s">%3$s%4$s%5$s</span>',
			esc_attr( $metric_class ),
			esc_attr( $aria_label ),
			$stack, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$count_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$chips // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Payload list for popup JSON / JS.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,int>|array<int,array{type?:string,count?:int}> $reactions Reactions.
	 * @return array<int,array{type:string,count:int,label:string}>
	 */
	public static function to_popup_list( array $reactions ): array {
		$out = array();
		foreach ( self::normalize( $reactions ) as $item ) {
			$out[] = array(
				'type'  => $item['type'],
				'count' => $item['count'],
				'label' => self::label_for( $item['type'] ),
			);
		}
		return $out;
	}

	/**
	 * Reaction icon SVG.
	 *
	 * @since 6.7.9
	 * @param string $type  Reaction enum.
	 * @param string $style `simple` (currentColor outline, feed default) or `color` (Facebook badge).
	 * @return string
	 */
	public static function icon_svg( string $type, string $style = 'simple' ): string {
		$type  = strtoupper( $type );
		$style = 'color' === $style ? 'color' : 'simple';
		if ( 'simple' === $style ) {
			return self::icon_svg_simple( $type );
		}
		return self::icon_svg_color( $type );
	}

	/**
	 * Stroke-only outline icons matching comments/shares metrics (feed summary).
	 *
	 * Pure `currentColor` strokes — no solid badge fills — so Design text /
	 * footer colors recolor them cleanly.
	 *
	 * @since 6.7.9
	 * @param string $type Reaction enum.
	 * @return string
	 */
	private static function icon_svg_simple( string $type ): string {
		$wrap = static function ( string $mod, string $inner ) {
			return '<span class="esf-facebook-reaction-icon esf-facebook-reaction-icon--simple esf-facebook-reaction-icon--' .
				esc_attr( $mod ) .
				'" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">' .
				$inner .
				'</svg></span>';
		};

		switch ( $type ) {
			case 'LIKE':
				// Same Lucide thumbs-up path as PostMeta likes metric.
				return $wrap(
					'like',
					'<path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>'
				);
			case 'LOVE':
				return $wrap(
					'love',
					'<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>'
				);
			case 'CARE':
				// Outline heart + soft smile — still stroke-only.
				return $wrap(
					'care',
					'<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/><path d="M9 13.5s1.2 1.2 3 1.2 3-1.2 3-1.2"/>'
				);
			case 'HAHA':
				return $wrap(
					'haha',
					'<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2.5 4 2.5 4-2.5 4-2.5"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>'
				);
			case 'WOW':
				return $wrap(
					'wow',
					'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="15" r="1.6"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/><path d="M8 7.5c.8-.9 1.8-.9 2.5 0M13.5 7.5c.8-.9 1.8-.9 2.5 0"/>'
				);
			case 'SAD':
				return $wrap(
					'sad',
					'<circle cx="12" cy="12" r="9"/><path d="M8 16s1.5-2 4-2 4 2 4 2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>'
				);
			case 'ANGRY':
				return $wrap(
					'angry',
					'<circle cx="12" cy="12" r="9"/><path d="M8 16s1.5-1.5 4-1.5 4 1.5 4 1.5"/><path d="M7.5 8.5l2.5 1M14 9.5l2.5-1"/><line x1="9" y1="11" x2="9.01" y2="11"/><line x1="15" y1="11" x2="15.01" y2="11"/>'
				);
			default:
				return $wrap(
					'like',
					'<path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>'
				);
		}
	}

	/**
	 * Colored Facebook-style badge icons (24×24 artboard for crisp 16px display).
	 *
	 * @since 6.7.9
	 * @param string $type Reaction enum.
	 * @return string
	 */
	private static function icon_svg_color( string $type ): string {
		$wrap = static function ( string $mod, string $inner ) {
			return '<span class="esf-facebook-reaction-icon esf-facebook-reaction-icon--color esf-facebook-reaction-icon--' .
				esc_attr( $mod ) .
				'" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" focusable="false">' .
				$inner .
				'</svg></span>';
		};

		switch ( $type ) {
			case 'LIKE':
				// Solid Material-style thumbs-up (same L→R orientation as the
				// simple Lucide outline). Fill beats stroke at 16px badge size —
				// matches how LOVE/WOW already render on colored circles.
				return $wrap(
					'like',
					'<circle cx="12" cy="12" r="12" fill="#1877F2"/><path fill="#fff" transform="translate(2.4 1.8) scale(0.8)" d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/>'
				);
			case 'LOVE':
				// Heart scaled + nudged so it sits centered in the red badge
				// (raw 24×24 path sits low/large at 16px display size).
				return $wrap(
					'love',
					'<circle cx="12" cy="12" r="12" fill="#F33E58"/><path fill="#fff" transform="translate(12 12.2) scale(0.58) translate(-12 -12)" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>'
				);
			case 'CARE':
				return $wrap(
					'care',
					'<circle cx="12" cy="12" r="12" fill="#F7B125"/><path fill="#fff" d="M12 17.3 10.6 16C7.4 13.1 5.3 11.2 5.3 8.9c0-1.8 1.4-3.2 3.2-3.2 1 0 2 .5 2.6 1.2.6-.7 1.6-1.2 2.6-1.2 1.8 0 3.2 1.4 3.2 3.2 0 2.3-2.1 4.2-5.3 7.1L12 17.3z"/><rect x="10.5" y="8.2" width="3" height="5.2" rx="1.5" fill="#F7B125"/><circle cx="12" cy="15.6" r="1.1" fill="#F7B125"/>'
				);
			case 'HAHA':
				return $wrap(
					'haha',
					'<circle cx="12" cy="12" r="12" fill="#F7B125"/><path fill="#4E2900" d="M12 6.2a5.8 5.8 0 1 0 0 11.6 5.8 5.8 0 0 0 0-11.6zm-2.2 3.3a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8zm4.4 0a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8zM12 15.6c-1.5 0-2.7-.8-3.2-1.9h6.4c-.5 1.1-1.7 1.9-3.2 1.9z"/>'
				);
			case 'WOW':
				// Facebook Wow: yellow face, raised brows, dark eyes, open oval mouth.
				return $wrap(
					'wow',
					'<circle cx="12" cy="12" r="12" fill="#F7B125"/><path fill="none" stroke="#4E2900" stroke-width="1.5" stroke-linecap="round" d="M5.8 7.4c1.4-1.5 3.2-1.5 4.6 0M13.6 7.4c1.4-1.5 3.2-1.5 4.6 0"/><circle cx="8.3" cy="10.2" r="1.25" fill="#4E2900"/><circle cx="15.7" cy="10.2" r="1.25" fill="#4E2900"/><ellipse cx="12" cy="15.8" rx="2.35" ry="3.4" fill="#4E2900"/>'
				);
			case 'SAD':
				return $wrap(
					'sad',
					'<circle cx="12" cy="12" r="12" fill="#F7B125"/><circle cx="8.5" cy="10" r="1.2" fill="#4E2900"/><circle cx="15.5" cy="10" r="1.2" fill="#4E2900"/><path fill="none" stroke="#4E2900" stroke-width="1.5" stroke-linecap="round" d="M8.2 15.5s1.5-1.5 3.8-1.5 3.8 1.5 3.8 1.5"/><path fill="#4E2900" d="M15.4 8.2l.6-1.1c.2-.3 0-.6-.3-.7-.3-.2-.6 0-.7.3l-.6 1.1c-.2.3 0 .6.3.7.3.2.6 0 .7-.3zM8.6 8.2 8 7.1c-.2-.3-.5-.4-.7-.3-.3.2-.4.5-.3.7l.6 1.1c.2.3.5.4.7.3.3-.1.4-.4.3-.7z"/>'
				);
			case 'ANGRY':
				return $wrap(
					'angry',
					'<circle cx="12" cy="12" r="12" fill="#E9710F"/><path fill="#fff" d="M6.8 8.2 9 9.4l.6-1.2-2.2-1.2-.6 1.2zM15 9.4l2.2-1.2-.6-1.2-2.2 1.2.6 1.2z"/><circle cx="8.2" cy="10.2" r="1.2" fill="#fff"/><circle cx="15.8" cy="10.2" r="1.2" fill="#fff"/><path fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" d="M8.2 15.5s1.5-1.5 3.8-1.5 3.8 1.5 3.8 1.5"/>'
				);
			default:
				return $wrap(
					'like',
					'<circle cx="12" cy="12" r="12" fill="#1877F2"/><path fill="#fff" transform="translate(2.4 1.8) scale(0.8)" d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/>'
				);
		}
	}
}
