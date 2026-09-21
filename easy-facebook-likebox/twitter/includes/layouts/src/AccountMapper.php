<?php
/**
 * Twitter account → AccountSummary mapper.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Twitter\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a row from `wp_esf_twitter_accounts` to an AccountSummary VO.
 *
 * @since 6.9.0
 */
final class AccountMapper {

	/**
	 * Build an AccountSummary from an accounts table row.
	 *
	 * @param mixed $row Account row (object or array).
	 * @return AccountSummary|null
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
		// DB column is display_name; accept name as a soft fallback for API-shaped rows.
		$name   = isset( $data['display_name'] ) && '' !== (string) $data['display_name']
			? (string) $data['display_name']
			: ( isset( $data['name'] ) && '' !== (string) $data['name'] ? (string) $data['name'] : $username );
		$avatar = isset( $data['profile_image_url'] ) ? (string) $data['profile_image_url'] : '';
		if ( '' === $avatar && isset( $data['avatar_url'] ) ) {
			$avatar = (string) $data['avatar_url'];
		}

		if ( class_exists( '\ESF_Twitter_Local_Media' ) && method_exists( '\ESF_Twitter_Local_Media', 'resolve_avatar_display_url' ) ) {
			$resolved = \ESF_Twitter_Local_Media::resolve_avatar_display_url( $row );
			if ( '' !== $resolved ) {
				$avatar = $resolved;
			} elseif ( function_exists( 'esf_is_local_media_url' ) && esf_is_local_media_url( $avatar, 'twitter' ) ) {
				$avatar = '';
			}
		}

		$profile_url = '' !== $username ? 'https://twitter.com/' . rawurlencode( $username ) : '';

		$metrics = isset( $data['public_metrics'] ) && is_array( $data['public_metrics'] )
			? $data['public_metrics']
			: array();

		$followers_count = 0;
		$following_count = 0;
		$tweet_count     = 0;

		if ( ! empty( $metrics ) ) {
			$followers_count = isset( $metrics['followers_count'] ) ? (int) $metrics['followers_count'] : 0;
			$following_count = isset( $metrics['following_count'] ) ? (int) $metrics['following_count'] : 0;
			$tweet_count     = isset( $metrics['tweet_count'] ) ? (int) $metrics['tweet_count'] : 0;
		} else {
			$followers_count = isset( $data['followers_count'] ) ? (int) $data['followers_count'] : 0;
			$following_count = isset( $data['following_count'] ) ? (int) $data['following_count'] : 0;
			$tweet_count     = isset( $data['tweet_count'] ) ? (int) $data['tweet_count'] : 0;
		}

		$is_verified = false;
		if ( isset( $data['is_verified'] ) ) {
			$is_verified = (bool) $data['is_verified'];
		} elseif ( isset( $data['verified'] ) ) {
			$is_verified = (bool) $data['verified'];
		}

		return new AccountSummary(
			array(
				'id'          => isset( $data['id'] ) ? (int) $data['id'] : 0,
				'name'        => $name,
				'handle'      => $username,
				'avatar_url'  => $avatar,
				'profile_url' => $profile_url,
				'bio'         => isset( $data['description'] ) ? (string) $data['description'] : '',
				'website'     => isset( $data['url'] ) ? (string) $data['url'] : '',
				'is_verified' => $is_verified,
				'stats'       => array(
					'followers' => $followers_count,
					'following' => $following_count,
					'tweets'    => $tweet_count,
				),
				'extras'      => self::extract_extras( $data ),
			)
		);
	}

	/**
	 * Extract Twitter-specific extras.
	 *
	 * @param array<string,mixed> $data Account data.
	 * @return array<string,mixed>
	 */
	private static function extract_extras( array $data ): array {
		$extras = array();

		if ( isset( $data['location'] ) && '' !== $data['location'] ) {
			$extras['location'] = (string) $data['location'];
		}

		if ( isset( $data['created_at'] ) ) {
			$extras['created_at'] = (string) $data['created_at'];
		}

		if ( isset( $data['profile_banner_url'] ) && '' !== (string) $data['profile_banner_url'] ) {
			$extras['banner_url'] = (string) $data['profile_banner_url'];
		}

		if ( isset( $data['verified_type'] ) && '' !== (string) $data['verified_type'] ) {
			$extras['verified_type'] = (string) $data['verified_type'];
		}

		$metrics = isset( $data['public_metrics'] ) && is_array( $data['public_metrics'] )
			? $data['public_metrics']
			: array();

		if ( isset( $data['x_user_id'] ) && '' !== (string) $data['x_user_id'] ) {
			$extras['x_user_id'] = (string) $data['x_user_id'];
		}

		if ( isset( $metrics['tweet_count'] ) ) {
			$extras['tweet_count'] = (int) $metrics['tweet_count'];
		}

		return $extras;
	}
}
