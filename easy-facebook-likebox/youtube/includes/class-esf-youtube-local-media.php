<?php
/**
 * YouTube local media helper.
 *
 * Resolves channel logos/banners from `uploads/esf-youtube/{account_id}/`
 * (warmed on OAuth/upsert via {@see ESF_YouTube_Account_Repository}).
 * Display paths must prefer local files — yt3.ggpht.com CDN URLs often fail
 * as hotlinked `<img>` sources in the admin dashboard.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube
 * @since 6.9.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Local_Media
 *
 * @since 6.9.5
 */
class ESF_YouTube_Local_Media {

	/**
	 * Module slug for shared media helpers.
	 *
	 * @var string
	 */
	const MODULE = 'youtube';

	/**
	 * Default max width for feed/grid thumbnail JPEG variants.
	 *
	 * Full-size local files power lightbox covers when embeds are blocked;
	 * feed cards use the smaller variant for faster loads (IG/LinkedIn pattern).
	 *
	 * @since 6.7.9
	 * @var int
	 */
	const DEFAULT_GRID_THUMB_WIDTH = 600;

	/**
	 * Whether sized grid thumbnails should be generated.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	public static function should_generate_grid_variants(): bool {
		return function_exists( 'esf_ensure_local_media_variant' )
			|| function_exists( 'esf_serve_media_locally_sized' );
	}

	/**
	 * Max width (px) for feed thumbnail variants.
	 *
	 * @since 6.7.9
	 * @return int
	 */
	public static function get_grid_thumb_width(): int {
		$width = (int) apply_filters( 'esf_youtube_local_media_grid_width', self::DEFAULT_GRID_THUMB_WIDTH );
		return max( 120, min( 1200, $width ) );
	}

	/**
	 * Resolve a displayable channel logo URL (prefer local uploads).
	 *
	 * Best-effort: if the local file is missing, re-download from the remote
	 * CDN URL stored on the account row (or `channel_data` JSON backup).
	 *
	 * @since 6.9.5
	 *
	 * @param object|array<string,mixed>|null $account Account row.
	 * @return string Local or remote URL, or empty string.
	 */
	public static function resolve_avatar_display_url( $account ) {
		return self::resolve_channel_image_display_url( $account, 'thumbnail' );
	}

	/**
	 * Resolve a displayable channel banner URL (prefer local uploads).
	 *
	 * @since 6.9.5
	 *
	 * @param object|array<string,mixed>|null $account Account row.
	 * @return string Local or remote URL, or empty string.
	 */
	public static function resolve_banner_display_url( $account ) {
		return self::resolve_channel_image_display_url( $account, 'banner' );
	}

	/**
	 * Localize channel logo/banner fields on a REST account row in place.
	 *
	 * Mutates `$row` so `channel_thumbnail` / `channel_banner` point at local
	 * uploads when available. Safe to call when those keys are absent.
	 *
	 * @since 6.9.5
	 *
	 * @param array<string,mixed> $row Account row (ARRAY_A).
	 * @return array<string,mixed>
	 */
	public static function localize_account_row_images( array $row ) {
		if ( isset( $row['channel_thumbnail'] ) ) {
			$resolved = self::resolve_avatar_display_url( $row );
			if ( '' !== $resolved ) {
				$row['channel_thumbnail'] = $resolved;
			}
		}

		if ( isset( $row['channel_banner'] ) ) {
			$resolved = self::resolve_banner_display_url( $row );
			if ( '' !== $resolved ) {
				$row['channel_banner'] = $resolved;
			}
		}

		return $row;
	}

	/**
	 * Delete the account media folder under uploads/esf-youtube/.
	 *
	 * @since 6.9.5
	 *
	 * @param int $account_id Internal account id.
	 * @return void
	 */
	public static function purge_account_media( $account_id ) {
		$account_id = function_exists( 'esf_normalize_local_media_account_id' )
			? esf_normalize_local_media_account_id( $account_id )
			: max( 0, (int) $account_id );
		if ( $account_id <= 0 || ! function_exists( 'esf_delete_media_account_folder' ) ) {
			return;
		}
		esf_delete_media_account_folder( self::MODULE, $account_id );
	}

	/**
	 * Resolve thumbnail or banner for display.
	 *
	 * @since 6.9.5
	 *
	 * @param object|array<string,mixed>|null $account Account row.
	 * @param string                          $kind    thumbnail|banner.
	 * @return string
	 */
	private static function resolve_channel_image_display_url( $account, $kind ) {
		$meta = self::channel_image_row_meta( $account, $kind );
		if ( null === $meta ) {
			return '';
		}

		$media_key  = $meta['key'];
		$account_id = $meta['account_id'];
		$stored     = $meta['stored_url'];
		$remote     = $meta['remote_url'];

		// Fast path: local file already on disk.
		if ( '' !== $media_key && $account_id > 0 && function_exists( 'esf_local_media_url' ) ) {
			$existing = esf_local_media_url( $media_key, self::MODULE, 0, $account_id );
			if ( is_string( $existing ) && '' !== $existing ) {
				return $existing;
			}
		}

		// Stored value is a dead local upload path — use remote backup only.
		if (
			'' !== $stored
			&& function_exists( 'esf_is_local_media_url' )
			&& esf_is_local_media_url( $stored, self::MODULE )
		) {
			$stored = '';
		}

		if ( '' === $remote ) {
			$remote = $stored;
		}

		if ( '' === $remote || '' === $media_key || $account_id <= 0 ) {
			return $remote;
		}

		if ( function_exists( 'esf_serve_media_locally' ) ) {
			$local = esf_serve_media_locally( $media_key, $remote, self::MODULE, $account_id );
			if ( is_string( $local ) && '' !== $local ) {
				return $local;
			}
		}

		return $remote;
	}

	/**
	 * Extract media key + remote/source URLs from an account row.
	 *
	 * @since 6.9.5
	 *
	 * @param object|array<string,mixed>|null $account Account row.
	 * @param string                          $kind    thumbnail|banner.
	 * @return array{key:string,account_id:int,stored_url:string,remote_url:string}|null
	 */
	private static function channel_image_row_meta( $account, $kind = 'thumbnail' ) {
		if ( null === $account ) {
			return null;
		}

		$data = is_object( $account ) ? get_object_vars( $account ) : $account;
		if ( ! is_array( $data ) ) {
			return null;
		}

		$kind       = ( 'banner' === $kind ) ? 'banner' : 'thumbnail';
		$field      = ( 'banner' === $kind ) ? 'channel_banner' : 'channel_thumbnail';
		$stored     = isset( $data[ $field ] ) ? trim( (string) $data[ $field ] ) : '';
		$account_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$channel_id = isset( $data['channel_id'] ) ? (string) $data['channel_id'] : '';

		if ( '' === $channel_id && '' === $stored ) {
			return null;
		}

		$backup = self::remote_images_from_channel_data(
			isset( $data['channel_data'] ) ? (string) $data['channel_data'] : ''
		);
		$remote = isset( $backup[ $kind ] ) ? (string) $backup[ $kind ] : '';

		// Prefer a non-local stored URL as the download source.
		if (
			'' !== $stored
			&& ( ! function_exists( 'esf_is_local_media_url' ) || ! esf_is_local_media_url( $stored, self::MODULE ) )
		) {
			$remote = $stored;
		}

		$key = '' !== $channel_id
			? ( ( 'banner' === $kind ) ? $channel_id . '_banner' : $channel_id )
			: '';

		if ( '' === $key && '' === $stored && '' === $remote ) {
			return null;
		}

		return array(
			'key'        => $key,
			'account_id' => max( 0, $account_id ),
			'stored_url' => $stored,
			'remote_url' => $remote,
		);
	}

	/**
	 * Pull remote thumbnail/banner URLs from the channel_data JSON backup.
	 *
	 * @since 6.9.5
	 *
	 * @param string $channel_data_json JSON blob.
	 * @return array{thumbnail:string,banner:string}
	 */
	private static function remote_images_from_channel_data( $channel_data_json ) {
		$empty = array(
			'thumbnail' => '',
			'banner'    => '',
		);
		if ( ! is_string( $channel_data_json ) || '' === $channel_data_json ) {
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
			if ( '' !== $thumb && esf_is_local_media_url( $thumb, self::MODULE ) ) {
				$thumb = '';
			}
			if ( '' !== $banner && esf_is_local_media_url( $banner, self::MODULE ) ) {
				$banner = '';
			}
		}

		return array(
			'thumbnail' => $thumb,
			'banner'    => $banner,
		);
	}
}
