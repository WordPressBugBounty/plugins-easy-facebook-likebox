<?php
/**
 * TikTok account → AccountSummary mapper.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\TikTok\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a row from `wp_esf_tiktok_accounts` to an AccountSummary VO.
 *
 * Does not download media — use {@see \ESF_TikTok_Local_Media::with_localized_avatar()}
 * in the renderer (Instagram pattern).
 *
 * @since 6.9.0
 */
final class AccountMapper {

	/**
	 * Build an AccountSummary from an accounts table row.
	 *
	 * @param mixed $row Account row (object or array).
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
		$name     = isset( $data['display_name'] ) ? (string) $data['display_name'] : '';
		if ( '' === $name ) {
			$name = $username;
		}

		$avatar = isset( $data['avatar_url'] ) ? (string) $data['avatar_url'] : '';
		if ( class_exists( '\ESF_TikTok_Local_Media' ) ) {
			$resolved = \ESF_TikTok_Local_Media::resolve_avatar_display_url( $row );
			if ( '' !== $resolved ) {
				$avatar = $resolved;
			}
		}

		$profile = isset( $data['profile_deep_link'] ) ? trim( (string) $data['profile_deep_link'] ) : '';
		if ( '' === $profile && '' !== $username ) {
			$profile = 'https://www.tiktok.com/@' . rawurlencode( ltrim( $username, '@' ) );
		}

		$bio = isset( $data['bio_description'] ) ? (string) $data['bio_description'] : '';

		return new AccountSummary(
			array(
				'id'          => isset( $data['id'] ) ? (int) $data['id'] : 0,
				'name'        => $name,
				'handle'      => $username,
				'avatar_url'  => $avatar,
				'profile_url' => $profile,
				'bio'         => $bio,
				'website'     => $profile,
				'is_verified' => ! empty( $data['is_verified'] ),
				'stats'       => array(
					'followers' => isset( $data['follower_count'] ) ? (int) $data['follower_count'] : 0,
					'following' => isset( $data['following_count'] ) ? (int) $data['following_count'] : 0,
					'likes'     => isset( $data['likes_count'] ) ? (int) $data['likes_count'] : 0,
					'videos'    => isset( $data['video_count'] ) ? (int) $data['video_count'] : 0,
				),
				'extras'      => array(
					'open_id' => isset( $data['open_id'] ) ? (string) $data['open_id'] : '',
				),
			)
		);
	}
}
