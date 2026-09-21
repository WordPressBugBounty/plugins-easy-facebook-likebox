<?php
/**
 * Facebook link / event details card for feed layouts.
 *
 * Renders attachment or Events-edge metadata (title, description, time, place,
 * ticket/map links) in the caption column — legacy half-width parity.
 *
 * Events use a calendar “strip” (not a nested grey box). Regular link / album
 * cards keep the classic inset panel.
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
 * Link / event details HTML for split and stacked cards.
 *
 * @since 6.7.9
 */
final class FacebookLinkCard {

	/**
	 * Render a link or event details block from extras.link_card.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $card    Link card payload.
	 * @param array<string,mixed> $options new_tab (bool), module (string),
	 *                                     caption (string) for Events desc dedupe.
	 * @return string
	 */
	public static function render( array $card, array $options = array() ): string {
		$module  = isset( $options['module'] ) ? sanitize_key( (string) $options['module'] ) : 'facebook';
		$new_tab = ! isset( $options['new_tab'] ) || (bool) $options['new_tab'];
		$kind    = isset( $card['kind'] ) ? sanitize_key( (string) $card['kind'] ) : 'link';

		if ( 'album' === $kind ) {
			return self::render_album_card( $card, $module, $new_tab );
		}

		if ( 'event' === $kind ) {
			return self::render_event_strip( $card, $module, $new_tab, $options );
		}

		return self::render_link_panel( $card, $module, $new_tab );
	}

	/**
	 * Classic inset panel for shared links (non-event).
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $card    Link card payload.
	 * @param string              $module  Module slug.
	 * @param bool                $new_tab Open links in a new tab.
	 * @return string
	 */
	private static function render_link_panel( array $card, string $module, bool $new_tab ): string {
		$target      = $new_tab ? ' target="_blank" rel="noopener noreferrer nofollow"' : ' rel="nofollow"';
		$title       = isset( $card['title'] ) ? trim( (string) $card['title'] ) : '';
		$description = isset( $card['description'] ) ? trim( (string) $card['description'] ) : '';
		$url         = isset( $card['url'] ) ? trim( (string) $card['url'] ) : '';

		if ( '' === $title && '' === $description && '' === $url ) {
			return '';
		}

		$html = sprintf(
			'<div class="esf-%1$s-feed__link-card esf-%1$s-feed__link-card--link">',
			esc_attr( $module )
		);

		if ( '' !== $title ) {
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				$html .= sprintf(
					'<a class="esf-%1$s-feed__link-card-title" href="%2$s"%3$s>%4$s</a>',
					esc_attr( $module ),
					esc_url( $url ),
					$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attrs.
					esc_html( $title )
				);
			} else {
				$html .= sprintf(
					'<span class="esf-%1$s-feed__link-card-title">%2$s</span>',
					esc_attr( $module ),
					esc_html( $title )
				);
			}
		}

		if ( '' !== $description ) {
			$html .= sprintf(
				'<p class="esf-%1$s-feed__link-card-desc">%2$s</p>',
				esc_attr( $module ),
				esc_html( $description )
			);
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Event calendar strip: date chip + title + when/where + CTA (no nested box clutter).
	 *
	 * Description is omitted when it duplicates the post caption (Events edge
	 * puts the same text in both places).
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $card    Event link_card payload.
	 * @param string              $module  Module slug.
	 * @param bool                $new_tab Open links in a new tab.
	 * @param array<string,mixed> $options Optional caption for dedupe.
	 * @return string
	 */
	private static function render_event_strip( array $card, string $module, bool $new_tab, array $options = array() ): string {
		$target        = $new_tab ? ' target="_blank" rel="noopener noreferrer nofollow"' : ' rel="nofollow"';
		$title         = isset( $card['title'] ) ? trim( (string) $card['title'] ) : '';
		$description   = isset( $card['description'] ) ? trim( (string) $card['description'] ) : '';
		$url           = isset( $card['url'] ) ? trim( (string) $card['url'] ) : '';
		$start_raw     = isset( $card['start_time'] ) ? (string) $card['start_time'] : '';
		$end_raw       = isset( $card['end_time'] ) ? (string) $card['end_time'] : '';
		$when_label    = self::format_event_range_label( $start_raw, $end_raw );
		$place_name    = isset( $card['place_name'] ) ? trim( (string) $card['place_name'] ) : '';
		$place_city    = isset( $card['place_city'] ) ? trim( (string) $card['place_city'] ) : '';
		$place_country = isset( $card['place_country'] ) ? trim( (string) $card['place_country'] ) : '';
		$place_label   = self::format_place_label( $place_name, $place_city, $place_country );
		$ticket_uri    = isset( $card['ticket_uri'] ) ? trim( (string) $card['ticket_uri'] ) : '';
		$lat           = isset( $card['latitude'] ) ? (string) $card['latitude'] : '';
		$lng           = isset( $card['longitude'] ) ? (string) $card['longitude'] : '';
		$place_id      = isset( $card['place_id'] ) ? trim( (string) $card['place_id'] ) : '';
		$cal           = self::calendar_chip_parts( $start_raw );

		$caption = isset( $options['caption'] ) ? (string) $options['caption'] : '';
		if ( '' !== $description ) {
			$is_dupe = class_exists( __NAMESPACE__ . '\FacebookEmbedSupport' )
				? FacebookEmbedSupport::texts_are_duplicate( $caption, $description )
				: self::text_essentially_same( $description, $caption );
			if ( $is_dupe ) {
				$description = '';
			}
		}

		if (
			'' === $title
			&& '' === $description
			&& '' === $when_label
			&& '' === $place_label
			&& '' === $url
			&& empty( $cal )
		) {
			return '';
		}

		$html  = sprintf(
			'<div class="esf-%1$s-feed__link-card esf-%1$s-feed__link-card--event esf-%1$s-feed__event-strip">',
			esc_attr( $module )
		);
		$html .= sprintf(
			'<div class="esf-%1$s-feed__event-strip-inner">',
			esc_attr( $module )
		);

		if ( ! empty( $cal['month'] ) && ! empty( $cal['day'] ) ) {
			$html .= sprintf(
				'<div class="esf-%1$s-feed__event-cal" aria-hidden="true">'
				. '<span class="esf-%1$s-feed__event-cal-month">%2$s</span>'
				. '<span class="esf-%1$s-feed__event-cal-day">%3$s</span>'
				. '</div>',
				esc_attr( $module ),
				esc_html( $cal['month'] ),
				esc_html( $cal['day'] )
			);
		}

		$html .= sprintf(
			'<div class="esf-%1$s-feed__event-strip-body">',
			esc_attr( $module )
		);

		if ( '' !== $title ) {
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				$html .= sprintf(
					'<a class="esf-%1$s-feed__link-card-title esf-%1$s-feed__event-title" href="%2$s"%3$s>%4$s</a>',
					esc_attr( $module ),
					esc_url( $url ),
					$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attrs.
					esc_html( $title )
				);
			} else {
				$html .= sprintf(
					'<span class="esf-%1$s-feed__link-card-title esf-%1$s-feed__event-title">%2$s</span>',
					esc_attr( $module ),
					esc_html( $title )
				);
			}
		}

		$meta_parts = array();
		if ( '' !== $when_label ) {
			$meta_parts[] = esc_html( $when_label );
		}

		if ( '' !== $place_label ) {
			$place_text = esc_html( $place_label );
			if ( '' !== $place_id ) {
				$meta_parts[] = sprintf(
					'<a class="esf-%1$s-feed__event-place" href="%2$s"%3$s>%4$s</a>',
					esc_attr( $module ),
					esc_url( 'https://www.facebook.com/' . rawurlencode( $place_id ) ),
					$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$place_text
				);
			} else {
				$meta_parts[] = '<span class="esf-' . esc_attr( $module ) . '-feed__event-place">' . $place_text . '</span>';
			}
		}

		if ( array() !== $meta_parts ) {
			$joined = '';
			foreach ( $meta_parts as $i => $part ) {
				if ( $i > 0 ) {
					$joined .= '<span class="esf-' . esc_attr( $module ) . '-feed__event-meta-sep" aria-hidden="true"> · </span>';
				}
				$joined .= $part;
			}
			$html .= sprintf(
				'<p class="esf-%1$s-feed__event-meta">%2$s</p>',
				esc_attr( $module ),
				$joined // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Parts escaped above.
			);
		}

		if ( '' !== $description ) {
			$html .= sprintf(
				'<p class="esf-%1$s-feed__link-card-desc esf-%1$s-feed__event-desc">%2$s</p>',
				esc_attr( $module ),
				esc_html( $description )
			);
		}

		// Title already links to the event; footer has View on Facebook — no
		// extra "View event" CTA. Buy Tickets / View Map stay as text links.
		$actions = '';
		if ( '' !== $ticket_uri && preg_match( '#^https?://#i', $ticket_uri ) ) {
			$label    = function_exists( 'esf_get_translated_string' )
				? (string) esf_get_translated_string( 'buy_tickets' )
				: __( 'Buy Tickets', 'easy-facebook-likebox' );
			$actions .= sprintf(
				'<a class="esf-%1$s-feed__link-card-action esf-%1$s-feed__event-action" href="%2$s"%3$s>%4$s</a>',
				esc_attr( $module ),
				esc_url( $ticket_uri ),
				$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $label )
			);
		}
		if ( '' !== $lat && '' !== $lng ) {
			$map_label = function_exists( 'esf_get_translated_string' )
				? (string) esf_get_translated_string( 'view_map' )
				: __( 'View Map', 'easy-facebook-likebox' );
			$map_url   = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $lat . ',' . $lng );
			$actions  .= sprintf(
				'<a class="esf-%1$s-feed__link-card-action esf-%1$s-feed__event-action" href="%2$s"%3$s>%4$s</a>',
				esc_attr( $module ),
				esc_url( $map_url ),
				$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $map_label )
			);
		}
		if ( '' !== $actions ) {
			$html .= sprintf(
				'<div class="esf-%1$s-feed__link-card-actions esf-%1$s-feed__event-actions">%2$s</div>',
				esc_attr( $module ),
				$actions // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		$html .= '</div></div></div>';
		return $html;
	}

	/**
	 * Album filter card: name + photo count (legacy albums list parity).
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $card    Album link_card payload.
	 * @param string              $module  Module slug.
	 * @param bool                $new_tab Open links in a new tab.
	 * @return string
	 */
	private static function render_album_card( array $card, string $module, bool $new_tab ): string {
		$title       = isset( $card['title'] ) ? trim( (string) $card['title'] ) : '';
		$description = isset( $card['description'] ) ? trim( (string) $card['description'] ) : '';
		$url         = isset( $card['url'] ) ? trim( (string) $card['url'] ) : '';
		$photo_count = isset( $card['photo_count'] ) ? max( 0, (int) $card['photo_count'] ) : 0;
		$target      = $new_tab ? ' target="_blank" rel="noopener noreferrer nofollow"' : ' rel="nofollow"';

		if ( '' === $title && $photo_count <= 0 && '' === $description ) {
			return '';
		}

		$html = sprintf(
			'<div class="esf-%1$s-feed__link-card esf-%1$s-feed__link-card--album">',
			esc_attr( $module )
		);

		if ( '' !== $title ) {
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				$html .= sprintf(
					'<a class="esf-%1$s-feed__link-card-title" href="%2$s"%3$s>%4$s</a>',
					esc_attr( $module ),
					esc_url( $url ),
					$target, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attrs.
					esc_html( $title )
				);
			} else {
				$html .= sprintf(
					'<span class="esf-%1$s-feed__link-card-title">%2$s</span>',
					esc_attr( $module ),
					esc_html( $title )
				);
			}
		}

		if ( $photo_count > 0 ) {
			$count_label = function_exists( 'esf_translate_registry_string' )
				? esf_translate_registry_string( 'fb_album_photo_count', '%d photos' )
				: __( '%d photos', 'easy-facebook-likebox' );
			$html       .= sprintf(
				'<p class="esf-%1$s-feed__link-card-album-count"><span class="esf-%1$s-feed__link-card-album-count-icon" aria-hidden="true">'
				. '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>'
				. '</span><span class="esf-%1$s-feed__link-card-album-count-text">%2$s</span></p>',
				esc_attr( $module ),
				esc_html( sprintf( $count_label, $photo_count ) )
			);
		}

		if ( '' !== $description ) {
			$html .= sprintf(
				'<p class="esf-%1$s-feed__link-card-desc">%2$s</p>',
				esc_attr( $module ),
				esc_html( $description )
			);
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Month + day parts for the calendar chip.
	 *
	 * @since 6.7.9
	 * @param string $raw ISO-ish Graph time.
	 * @return array{month?:string,day?:string}
	 */
	private static function calendar_chip_parts( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		$ts = strtotime( $raw );
		if ( false === $ts || $ts <= 0 ) {
			return array();
		}

		$month = function_exists( 'wp_date' )
			? (string) wp_date( 'M', $ts )
			: (string) date_i18n( 'M', $ts );
		$day   = function_exists( 'wp_date' )
			? (string) wp_date( 'j', $ts )
			: (string) date_i18n( 'j', $ts );

		$month = strtoupper( $month );

		return array(
			'month' => $month,
			'day'   => $day,
		);
	}

	/**
	 * Whether two strings are the same for display (ignore whitespace/case).
	 *
	 * @since 6.7.9
	 * @param string $a First.
	 * @param string $b Second.
	 * @return bool
	 */
	private static function text_essentially_same( string $a, string $b ): bool {
		$norm  = static function ( $s ) {
			$s = wp_strip_all_tags( (string) $s );
			$s = preg_replace( '/\s+/u', ' ', $s );
			return strtolower( trim( (string) $s ) );
		};
		$left  = $norm( $a );
		$right = $norm( $b );
		return '' !== $left && $left === $right;
	}

	/**
	 * Single-line when label: same-day ranges collapse the repeated date.
	 *
	 * Examples:
	 * - `October 24, 2019 · 2:00 pm – 5:00 pm`
	 * - `October 24, 2019 2:00 pm – October 25, 2019 5:00 pm`
	 * - `October 24, 2019 · 2:00 pm` (start only)
	 *
	 * @since 6.7.9
	 * @param string $start_raw Graph start_time.
	 * @param string $end_raw   Graph end_time.
	 * @return string
	 */
	private static function format_event_range_label( string $start_raw, string $end_raw ): string {
		$start_ts = self::parse_event_timestamp( $start_raw );
		if ( null === $start_ts ) {
			return '';
		}

		$date_fmt = (string) get_option( 'date_format' );
		$time_fmt = (string) get_option( 'time_format' );
		if ( '' === $date_fmt ) {
			$date_fmt = 'F j, Y';
		}
		if ( '' === $time_fmt ) {
			$time_fmt = 'g:i a';
		}

		$start_date = self::format_timestamp( $date_fmt, $start_ts );
		$start_time = self::format_timestamp( $time_fmt, $start_ts );
		$end_ts     = self::parse_event_timestamp( $end_raw );

		if ( null === $end_ts ) {
			return $start_date . ' · ' . $start_time;
		}

		$end_date = self::format_timestamp( $date_fmt, $end_ts );
		$end_time = self::format_timestamp( $time_fmt, $end_ts );

		if ( self::format_timestamp( 'Y-m-d', $start_ts ) === self::format_timestamp( 'Y-m-d', $end_ts ) ) {
			return $start_date . ' · ' . $start_time . ' – ' . $end_time;
		}

		return $start_date . ' ' . $start_time . ' – ' . $end_date . ' ' . $end_time;
	}

	/**
	 * Build a place label without repeating city/country already in the name.
	 *
	 * @since 6.7.9
	 * @param string $name    Place name.
	 * @param string $city    City.
	 * @param string $country Country.
	 * @return string
	 */
	private static function format_place_label( string $name, string $city, string $country ): string {
		$parts = array();
		if ( '' !== $name ) {
			$parts[] = $name;
		}

		$haystack = strtolower( implode( ', ', $parts ) );
		if ( '' !== $city && ( '' === $haystack || false === strpos( $haystack, strtolower( $city ) ) ) ) {
			$parts[]  = $city;
			$haystack = strtolower( implode( ', ', $parts ) );
		}
		if ( '' !== $country && ( '' === $haystack || false === strpos( $haystack, strtolower( $country ) ) ) ) {
			$parts[] = $country;
		}

		return implode( ', ', $parts );
	}

	/**
	 * @param string $raw Graph time.
	 * @return int|null Unix timestamp or null.
	 */
	private static function parse_event_timestamp( string $raw ): ?int {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$ts = strtotime( $raw );
		if ( false === $ts || $ts <= 0 ) {
			return null;
		}
		return (int) $ts;
	}

	/**
	 * @param string $format PHP date format.
	 * @param int    $ts     Unix timestamp.
	 * @return string
	 */
	private static function format_timestamp( string $format, int $ts ): string {
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( $format, $ts );
		}
		return (string) date_i18n( $format, $ts );
	}
}
