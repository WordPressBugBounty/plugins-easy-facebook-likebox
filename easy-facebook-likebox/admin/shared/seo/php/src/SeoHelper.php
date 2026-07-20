<?php
/**
 * Shared SEO / AEO helper utilities for modern feed modules.
 *
 * @package Easy_Social_Feed
 * @subpackage SEO
 * @since 6.9.0
 */

namespace EasySocialFeed\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cross-module SEO helpers (alt text, landmarks, crawlable snippets).
 *
 * @since 6.9.0
 */
final class SeoHelper {

	/**
	 * Derive accessible, SEO-friendly image alt text from caption and author.
	 *
	 * @since 6.9.0
	 *
	 * @param string $caption_plain Plain or HTML caption / tweet text.
	 * @param string $author_name   Account or channel display name.
	 * @param string $media_type    `photo`, `video`, or `reel`.
	 * @return string Sanitized alt text.
	 */
	public static function derive_media_alt( string $caption_plain, string $author_name = '', string $media_type = 'photo' ): string {
		$caption_plain = trim( self::strip_tags( $caption_plain ) );
		if ( '' !== $caption_plain ) {
			return self::trim_words( $caption_plain, 15 );
		}

		$author_name = trim( $author_name );
		if ( '' !== $author_name ) {
			if ( in_array( $media_type, array( 'video', 'reel' ), true ) ) {
				/* translators: %s: social account display name. */
				return \sprintf( \__( 'Video by %s', 'easy-facebook-likebox' ), $author_name );
			}

			/* translators: %s: social account display name. */
			return \sprintf( \__( 'Photo by %s', 'easy-facebook-likebox' ), $author_name );
		}

		return (string) \__( 'Social media post', 'easy-facebook-likebox' );
	}

	/**
	 * Normalize a social timestamp to ISO-8601 for `<time datetime>` and schema.
	 *
	 * @since 6.9.0
	 *
	 * @param string $datetime Raw timestamp from API.
	 * @return string ISO-8601 string or empty when invalid.
	 */
	public static function normalize_datetime_iso( string $datetime ): string {
		$datetime = trim( $datetime );
		if ( '' === $datetime ) {
			return '';
		}

		$timestamp = \strtotime( $datetime );
		if ( false === $timestamp ) {
			return '';
		}

		return \gmdate( 'c', $timestamp );
	}

	/**
	 * Build wrapper attributes for a social feed landmark.
	 *
	 * @since 6.9.0
	 *
	 * @param string $feed_name Human-readable feed title.
	 * @return array<string,string>
	 */
	public static function feed_landmark_attributes( string $feed_name ): array {
		$feed_name = trim( $feed_name );
		if ( '' === $feed_name ) {
			$feed_name = (string) \__( 'Social feed', 'easy-facebook-likebox' );
		}

		return array(
			'role'       => 'feed',
			'aria-label' => $feed_name,
		);
	}

	/**
	 * Render visually hidden but DOM-present text for crawlers / screen readers.
	 *
	 * @since 6.9.0
	 *
	 * @param string $text Plain text content.
	 * @return string HTML fragment or empty string.
	 */
	public static function render_screen_reader_text( string $text ): string {
		$text = trim( self::strip_tags( $text ) );
		if ( '' === $text ) {
			return '';
		}

		return '<span class="screen-reader-text">' . \esc_html( $text ) . '</span>';
	}

	/**
	 * Build a `<noscript>` fallback with plain permalinks for no-JS users and bots.
	 *
	 * @since 6.9.0
	 *
	 * @param array<int,array{url:string,label:string}> $links Link rows.
	 * @return string HTML fragment or empty string.
	 */
	public static function render_noscript_link_list( array $links ): string {
		$items = array();

		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$url = isset( $link['url'] ) ? \esc_url( (string) $link['url'] ) : '';
			if ( '' === $url || '#' === $url ) {
				continue;
			}
			$label = isset( $link['label'] ) ? trim( (string) $link['label'] ) : $url;
			if ( '' === $label ) {
				$label = $url;
			}

			$items[] = '<li><a href="' . $url . '">' . \esc_html( $label ) . '</a></li>';
		}

		if ( empty( $items ) ) {
			return '';
		}

		return '<noscript class="esf-feed-noscript">'
			. '<p>' . \esc_html__( 'Posts in this feed:', 'easy-facebook-likebox' ) . '</p>'
			. '<ul>' . \implode( '', $items ) . '</ul>'
			. '</noscript>';
	}

	/**
	 * Encode schema data as a JSON-LD script tag.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,mixed>|array<int,array<string,mixed>> $schema Schema graph or node.
	 * @return string HTML script tag or empty string.
	 */
	public static function render_json_ld_script( $schema ): string {
		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return '';
		}

		$json = \wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		return '<script type="application/ld+json">' . $json . '</script>';
	}

	/**
	 * Strip HTML tags with a WordPress fallback.
	 *
	 * @since 6.9.0
	 *
	 * @param string $text Input text.
	 * @return string Plain text.
	 */
	private static function strip_tags( string $text ): string {
		return \strip_tags( $text );
	}

	/**
	 * Trim text to a word limit.
	 *
	 * @since 6.9.0
	 *
	 * @param string $text  Input text.
	 * @param int    $limit Maximum words.
	 * @return string
	 */
	private static function trim_words( string $text, int $limit ): string {
		$words = \preg_split( '/\s+/', \trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! \is_array( $words ) || \count( $words ) <= $limit ) {
			return \trim( $text );
		}

		return \implode( ' ', \array_slice( $words, 0, $limit ) ) . '…';
	}
}
