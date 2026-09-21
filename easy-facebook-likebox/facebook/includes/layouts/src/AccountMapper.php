<?php
/**
 * Facebook Page → AccountSummary mapper.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts
 * @since 6.7.9
 */

namespace EasySocialFeed\Facebook\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a row from `wp_esf_facebook_pages` to an AccountSummary VO.
 *
 * @since 6.7.9
 */
final class AccountMapper {

	/**
	 * Build an AccountSummary from a pages table row.
	 *
	 * Accepts either an object (as returned by `$wpdb->get_row`) or an array.
	 *
	 * @param mixed $row Page row.
	 */
	public static function from_row( $row ): ?AccountSummary {
		if ( ! is_object( $row ) && ! is_array( $row ) ) {
			return null;
		}

		$data = is_object( $row ) ? get_object_vars( $row ) : $row;
		if ( empty( $data ) ) {
			return null;
		}

		$page_id   = isset( $data['page_id'] ) ? (string) $data['page_id'] : '';
		$page_name = isset( $data['page_name'] ) ? (string) $data['page_name'] : '';
		if ( '' === $page_name && '' !== $page_id ) {
			$page_name = $page_id;
		}

		$username  = isset( $data['username'] ) ? trim( (string) $data['username'] ) : '';
		$page_link = isset( $data['page_link'] ) ? trim( (string) $data['page_link'] ) : '';

		if ( '' === $username && '' !== $page_link ) {
			$username = self::username_from_facebook_url( $page_link );
		}

		$handle = '' !== $username ? $username : $page_id;

		$profile = '';
		if ( '' !== $page_link && preg_match( '#^https?://#i', $page_link ) ) {
			$profile = esc_url_raw( $page_link );
		}
		if ( '' === $profile && '' !== $handle ) {
			$profile = 'https://www.facebook.com/' . rawurlencode( $handle ) . '/';
		}

		// Prefer Graph `about` for the header bio (legacy show_bio). Category is
		// a separate meta toggle — do not fall back into bio or it duplicates.
		$about    = isset( $data['about'] ) ? trim( (string) $data['about'] ) : '';
		$category = isset( $data['category'] ) ? trim( (string) $data['category'] ) : '';

		// Page website (Graph `website`) — used for the Pro header link, not the FB profile URL.
		$website = self::normalize_http_url( isset( $data['website'] ) ? (string) $data['website'] : '' );

		$cover = self::normalize_http_url(
			isset( $data['cover_photo_url'] ) ? (string) $data['cover_photo_url'] : ''
		);

		return new AccountSummary(
			array(
				'id'          => isset( $data['id'] ) ? (int) $data['id'] : 0,
				'name'        => $page_name,
				'handle'      => $handle,
				'avatar_url'  => isset( $data['profile_image_url'] ) ? (string) $data['profile_image_url'] : '',
				'profile_url' => $profile,
				'bio'         => $about,
				'website'     => $website,
				'is_verified' => false,
				'stats'       => array(
					'followers' => isset( $data['fan_count'] ) ? (int) $data['fan_count'] : 0,
				),
				'extras'      => array(
					'page_id'         => $page_id,
					'username'        => $username,
					'page_link'       => $page_link,
					'category'        => $category,
					'about'           => $about,
					'website'         => $website,
					'cover_photo_url' => $cover,
					'status'          => isset( $data['status'] ) ? (string) $data['status'] : '',
				),
			)
		);
	}

	/**
	 * Normalize a stored URL to an absolute http(s) value.
	 *
	 * @since 6.7.9
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function normalize_http_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$normalized = esc_url_raw( $url );
		if ( ! is_string( $normalized ) || '' === $normalized || ! preg_match( '#^https?://#i', $normalized ) ) {
			return '';
		}
		return $normalized;
	}

	/**
	 * Derive vanity username from a facebook.com URL when DB username is empty.
	 *
	 * @since 6.7.9
	 * @param string $url Absolute Facebook URL.
	 * @return string
	 */
	private static function username_from_facebook_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '';
		}

		$path     = trim( (string) $parts['path'], '/' );
		$segments = array_values( array_filter( explode( '/', $path ) ) );
		if ( array() === $segments ) {
			return '';
		}

		$first = strtolower( (string) $segments[0] );
		$skip  = array( 'pages', 'profile.php', 'people', 'pg', 'groups', 'events', 'watch', 'story.php', 'permalink.php' );
		if ( in_array( $first, $skip, true ) ) {
			if ( 'pages' === $first && isset( $segments[1] ) ) {
				$candidate = sanitize_text_field( (string) $segments[1] );
				if ( '' !== $candidate && ! ctype_digit( $candidate ) ) {
					return $candidate;
				}
			}
			return '';
		}

		$candidate = sanitize_text_field( (string) $segments[0] );
		if ( '' === $candidate || ctype_digit( $candidate ) ) {
			return '';
		}

		return $candidate;
	}
}
