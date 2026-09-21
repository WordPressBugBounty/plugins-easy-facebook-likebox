<?php
/**
 * YouTube channel → AccountSummary mapper.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\YouTube\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a row from `wp_esf_youtube_accounts` to an AccountSummary VO.
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

		$channel_id = isset( $data['channel_id'] ) ? (string) $data['channel_id'] : '';
		// DB columns are channel_name / channel_thumbnail / handle (not title / thumbnail_url).
		$name = isset( $data['channel_name'] ) ? (string) $data['channel_name'] : '';
		if ( '' === $name && isset( $data['title'] ) ) {
			$name = (string) $data['title'];
		}
		$handle = isset( $data['handle'] ) ? (string) $data['handle'] : '';
		if ( '' === $handle && isset( $data['custom_url'] ) ) {
			$handle = (string) $data['custom_url'];
		}
		$thumbnail = isset( $data['channel_thumbnail'] ) ? (string) $data['channel_thumbnail'] : '';
		if ( '' === $thumbnail && isset( $data['thumbnail_url'] ) ) {
			$thumbnail = (string) $data['thumbnail_url'];
		}

		$account_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$backup     = self::remote_images_from_channel_data(
			isset( $data['channel_data'] ) ? (string) $data['channel_data'] : ''
		);

		if ( class_exists( '\ESF_YouTube_Local_Media' ) ) {
			$resolved = \ESF_YouTube_Local_Media::resolve_avatar_display_url( $row );
			if ( '' !== $resolved ) {
				$thumbnail = $resolved;
			}
		} else {
			$thumbnail = self::resolve_channel_image_url(
				$thumbnail,
				$channel_id,
				$account_id,
				$backup['thumbnail']
			);
		}

		$profile_url = '';
		if ( '' !== $handle ) {
			$normalized_handle = ltrim( $handle, '/@' );
			$profile_url       = '' !== $normalized_handle
				? 'https://www.youtube.com/@' . $normalized_handle
				: '';
		} elseif ( '' !== $channel_id ) {
			$profile_url = 'https://www.youtube.com/channel/' . rawurlencode( $channel_id );
		}

		return new AccountSummary(
			array(
				'id'          => $account_id,
				'name'        => $name,
				'handle'      => $handle,
				'avatar_url'  => $thumbnail,
				'profile_url' => $profile_url,
				'bio'         => isset( $data['description'] ) ? (string) $data['description'] : '',
				'website'     => '',
				'is_verified' => false,
				'stats'       => array(
					'followers' => isset( $data['subscriber_count'] ) ? (int) $data['subscriber_count'] : 0,
				),
				'extras'      => self::extract_extras( $data, $account_id, $channel_id, $backup ),
			)
		);
	}

	/**
	 * Resolve a displayable channel image URL (local when available, else remote CDN).
	 *
	 * @since 6.9.5
	 *
	 * @param string $stored_url     URL from the account row.
	 * @param string $media_key      Local media key.
	 * @param int    $account_id     Internal account id.
	 * @param string $fallback_remote Remote CDN fallback (from channel_data backup).
	 * @return string
	 */
	private static function resolve_channel_image_url( string $stored_url, string $media_key, int $account_id, string $fallback_remote ): string {
		if ( '' === $stored_url && '' === $fallback_remote ) {
			return '';
		}

		$remote = $stored_url;
		if ( function_exists( 'esf_is_local_media_url' ) && '' !== $stored_url && esf_is_local_media_url( $stored_url, 'youtube' ) ) {
			if ( function_exists( 'esf_local_media_url' ) ) {
				$existing = esf_local_media_url( $media_key, 'youtube', 0, $account_id );
				if ( '' !== $existing ) {
					return $existing;
				}
			}
			$remote = $fallback_remote;
		}

		if ( '' === $remote ) {
			return '';
		}

		if ( '' !== $media_key && function_exists( 'esf_serve_media_locally' ) ) {
			$local = esf_serve_media_locally( $media_key, $remote, 'youtube', $account_id );
			if ( is_string( $local ) && '' !== $local ) {
				return $local;
			}
		}

		return $remote;
	}

	/**
	 * Extract remote thumbnail/banner from channel_data JSON backup.
	 *
	 * @since 6.9.5
	 *
	 * @param string $channel_data_json JSON blob.
	 * @return array{thumbnail:string,banner:string}
	 */
	private static function remote_images_from_channel_data( string $channel_data_json ): array {
		$empty = array(
			'thumbnail' => '',
			'banner'    => '',
		);
		if ( '' === $channel_data_json ) {
			return $empty;
		}

		$data = json_decode( $channel_data_json, true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		$thumb = isset( $data['thumbnail'] ) ? (string) $data['thumbnail'] : '';
		if ( '' === $thumb && isset( $data['snippet']['thumbnails']['high']['url'] ) ) {
			$thumb = (string) $data['snippet']['thumbnails']['high']['url'];
		} elseif ( '' === $thumb && isset( $data['snippet']['thumbnails']['default']['url'] ) ) {
			$thumb = (string) $data['snippet']['thumbnails']['default']['url'];
		}

		$banner = isset( $data['banner'] ) ? (string) $data['banner'] : '';

		if ( function_exists( 'esf_is_local_media_url' ) ) {
			if ( '' !== $thumb && esf_is_local_media_url( $thumb, 'youtube' ) ) {
				$thumb = '';
			}
			if ( '' !== $banner && esf_is_local_media_url( $banner, 'youtube' ) ) {
				$banner = '';
			}
		}

		return array(
			'thumbnail' => $thumb,
			'banner'    => $banner,
		);
	}

	/**
	 * Extract YouTube-specific extras.
	 *
	 * @param array<string,mixed>                   $data       Account data.
	 * @param int                                   $account_id Internal account id.
	 * @param string                                $channel_id YouTube channel id.
	 * @param array{thumbnail:string,banner:string} $backup Remote backup URLs.
	 * @return array<string,mixed>
	 */
	private static function extract_extras( array $data, int $account_id = 0, string $channel_id = '', array $backup = array(
		'thumbnail' => '',
		'banner'    => '',
	) ): array {
		$extras = array();

		if ( isset( $data['video_count'] ) ) {
			$extras['video_count'] = (int) $data['video_count'];
		}

		if ( isset( $data['view_count'] ) ) {
			$extras['view_count'] = (int) $data['view_count'];
		}

		if ( isset( $data['channel_id'] ) && '' !== (string) $data['channel_id'] ) {
			$extras['channel_id'] = (string) $data['channel_id'];
		}

		$banner = '';
		if ( isset( $data['channel_banner'] ) && '' !== (string) $data['channel_banner'] ) {
			$banner = (string) $data['channel_banner'];
		} elseif ( isset( $data['banner_url'] ) && '' !== (string) $data['banner_url'] ) {
			$banner = (string) $data['banner_url'];
		}

		if ( class_exists( '\ESF_YouTube_Local_Media' ) ) {
			$resolved_banner = \ESF_YouTube_Local_Media::resolve_banner_display_url(
				array_merge(
					$data,
					array(
						'id'             => $account_id,
						'channel_id'     => $channel_id,
						'channel_banner' => $banner,
					)
				)
			);
			if ( '' !== $resolved_banner ) {
				$banner = $resolved_banner;
			}
		} else {
			$fallback_banner = isset( $backup['banner'] ) ? (string) $backup['banner'] : '';
			$banner          = self::resolve_channel_image_url(
				$banner,
				'' !== $channel_id ? $channel_id . '_banner' : '',
				$account_id,
				$fallback_banner
			);
		}

		if ( '' !== $banner ) {
			$extras['banner_url']     = $banner;
			$extras['channel_banner'] = $banner;
		}

		return $extras;
	}
}
