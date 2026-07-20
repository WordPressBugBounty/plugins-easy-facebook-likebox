<?php
/**
 * Instagram account → AccountSummary mapper.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Instagram\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a row from `wp_esf_instagram_accounts` to an AccountSummary VO.
 *
 * @since 6.9.0
 */
final class AccountMapper {

	/**
	 * Build an AccountSummary from a row of `wp_esf_instagram_accounts`.
	 *
	 * Accepts either an object (as returned by `$wpdb->get_row`) or an array.
	 *
	 * @param mixed $row Account row.
	 */
	public static function from_row( $row ): ?AccountSummary {
		if ( ! is_object( $row ) && ! is_array( $row ) ) {
			return null;
		}

		$data = is_object( $row ) ? get_object_vars( $row ) : $row;
		if ( empty( $data ) ) {
			return null;
		}

		$username = isset( $data['username'] ) ? (string) $data['username'] : '';
		$name     = isset( $data['display_name'] ) && '' !== (string) $data['display_name']
			? (string) $data['display_name']
			: $username;

		return new AccountSummary(
			array(
				'id'          => isset( $data['id'] ) ? (int) $data['id'] : 0,
				'name'        => $name,
				'handle'      => $username,
				'avatar_url'  => isset( $data['profile_image_url'] ) ? (string) $data['profile_image_url'] : '',
				'profile_url' => '' !== $username ? 'https://www.instagram.com/' . rawurlencode( $username ) . '/' : '',
				'bio'         => isset( $data['biography'] ) ? (string) $data['biography'] : '',
				'website'     => isset( $data['website'] ) ? \esc_url_raw( (string) $data['website'] ) : '',
				'is_verified' => ! empty( $data['is_verified'] ),
				'stats'       => array(
					'followers' => isset( $data['followers_count'] ) ? (int) $data['followers_count'] : 0,
					'media'     => isset( $data['media_count'] ) ? (int) $data['media_count'] : 0,
				),
			)
		);
	}

	/**
	 * Build a display AccountSummary for hashtag feeds.
	 *
	 * @since 6.9.0
	 *
	 * @param string              $tag     Normalized hashtag (no #).
	 * @param AccountSummary|null $linked  Linked account for avatar fallback.
	 */
	public static function for_hashtag( string $tag, ?AccountSummary $linked = null ): AccountSummary {
		$normalized = function_exists( 'esf_instagram_normalize_hashtag' )
			? esf_instagram_normalize_hashtag( $tag )
			: '';
		$label      = '' !== $normalized ? $normalized : '';

		return new AccountSummary(
			array(
				'id'          => null !== $linked ? $linked->get_id() : 0,
				'name'        => $label,
				'handle'      => '',
				'avatar_url'  => '',
				'profile_url' => '' !== $normalized
					? 'https://www.instagram.com/explore/tags/' . rawurlencode( $normalized ) . '/'
					: '',
				'bio'         => '',
				'is_verified' => false,
				'stats'       => array(),
			)
		);
	}
}
