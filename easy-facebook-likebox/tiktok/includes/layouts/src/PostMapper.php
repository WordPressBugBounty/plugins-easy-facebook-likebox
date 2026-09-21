<?php
/**
 * TikTok Display API video node → Post mapper.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\TikTok\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\Post;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a TikTok `/v2/video/list/` element to a Post VO.
 *
 * @since 6.9.0
 */
final class PostMapper {

	/**
	 * Convert an API video node to a Post value object.
	 *
	 * @param array<string,mixed> $node    Raw video node (+ local_media_url when warmed).
	 * @param AccountSummary|null $account Owning account (default author).
	 */
	public static function from_api_node( array $node, ?AccountSummary $account = null ): ?Post {
		$id = isset( $node['id'] ) ? (string) $node['id'] : '';
		if ( '' === $id ) {
			return null;
		}

		$caption  = self::extract_caption( $node );
		$author   = $account;
		$alt_name = $account instanceof AccountSummary ? $account->get_name() : '';
		// TikTok Display API returns duration in seconds (not milliseconds).
		$duration_secs = isset( $node['duration'] ) ? (int) $node['duration'] : 0;

		$extras = array(
			'media_type' => 'VIDEO',
		);

		if ( $duration_secs > 0 ) {
			$extras['duration']           = $duration_secs;
			$extras['duration_formatted'] = self::format_duration_seconds( $duration_secs );
		}

		if ( isset( $node['embed_link'] ) && is_string( $node['embed_link'] ) && '' !== trim( $node['embed_link'] ) ) {
			$extras['embed_link'] = (string) $node['embed_link'];
		}

		return new Post(
			array(
				'id'         => $id,
				'permalink'  => self::build_permalink( $node, $account ),
				'created_at' => self::normalize_created_at( $node ),
				'text_html'  => self::format_caption_html( $caption ),
				'author'     => $author,
				'media'      => self::extract_media( $node, $caption, $alt_name ),
				'metrics'    => array(
					'likes'    => isset( $node['like_count'] ) ? (int) $node['like_count'] : 0,
					'comments' => isset( $node['comment_count'] ) ? (int) $node['comment_count'] : 0,
					'shares'   => isset( $node['share_count'] ) ? (int) $node['share_count'] : 0,
					'views'    => isset( $node['view_count'] ) ? (int) $node['view_count'] : 0,
				),
				'extras'     => $extras,
			)
		);
	}

	/**
	 * Format seconds as mm:ss for tile badges.
	 *
	 * @param int $seconds Duration in seconds (TikTok Display API).
	 * @return string
	 */
	public static function format_duration_seconds( $seconds ): string {
		$total_secs = max( 0, (int) $seconds );
		$mins       = (int) floor( $total_secs / 60 );
		$secs       = $total_secs % 60;

		return sprintf( '%d:%02d', $mins, $secs );
	}

	/**
	 * @deprecated 6.13.0 Use format_duration_seconds(); TikTok returns seconds.
	 *
	 * @param int $ms Mistakenly treated as milliseconds; now treated as seconds.
	 * @return string
	 */
	public static function format_duration_ms( $ms ): string {
		return self::format_duration_seconds( $ms );
	}

	/**
	 * @param array<string,mixed> $node Post node.
	 * @return string
	 */
	private static function extract_caption( array $node ): string {
		if ( isset( $node['title'] ) && is_string( $node['title'] ) ) {
			$title = trim( $node['title'] );
			if ( '' !== $title ) {
				return $title;
			}
		}
		if ( isset( $node['video_description'] ) && is_string( $node['video_description'] ) ) {
			return trim( $node['video_description'] );
		}
		return '';
	}

	/**
	 * @param string $caption Raw caption.
	 * @return string
	 */
	private static function format_caption_html( string $caption ): string {
		if ( '' === $caption ) {
			return '';
		}
		if ( function_exists( 'esf_tiktok_format_caption_html' ) ) {
			return esf_tiktok_format_caption_html( $caption );
		}
		return wpautop( make_clickable( esc_html( $caption ) ) );
	}

	/**
	 * @param array<string,mixed> $node    Video node.
	 * @param AccountSummary|null $account Account VO.
	 * @return string
	 */
	private static function build_permalink( array $node, ?AccountSummary $account ): string {
		if ( isset( $node['share_url'] ) && is_string( $node['share_url'] ) ) {
			$url = trim( $node['share_url'] );
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				return $url;
			}
		}

		$id = isset( $node['id'] ) ? (string) $node['id'] : '';
		if ( '' === $id ) {
			return '';
		}

		$profile = '';
		if ( $account instanceof AccountSummary ) {
			$profile = trim( $account->get_profile_url() );
		}
		if ( '' === $profile && isset( $node['profile_deep_link'] ) && is_string( $node['profile_deep_link'] ) ) {
			$profile = trim( $node['profile_deep_link'] );
		}

		if ( '' !== $profile ) {
			return rtrim( $profile, '/' ) . '/video/' . rawurlencode( $id );
		}

		return 'https://www.tiktok.com/';
	}

	/**
	 * Normalize create_time (unix seconds) to ISO-8601.
	 *
	 * @param array<string,mixed> $node Video node.
	 * @return string
	 */
	private static function normalize_created_at( array $node ): string {
		if ( ! isset( $node['create_time'] ) || ! is_numeric( $node['create_time'] ) ) {
			return '';
		}

		$secs = (int) $node['create_time'];
		if ( $secs <= 0 ) {
			return '';
		}

		return gmdate( 'c', $secs );
	}

	/**
	 * @param array<string,mixed> $node        Video node.
	 * @param string              $caption     Caption text.
	 * @param string              $author_name Account display name.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_media( array $node, string $caption, string $author_name ): array {
		$local = isset( $node['local_media_url'] ) && is_string( $node['local_media_url'] )
			? trim( (string) $node['local_media_url'] )
			: '';
		$cover = isset( $node['cover_image_url'] ) && is_string( $node['cover_image_url'] )
			? trim( (string) $node['cover_image_url'] )
			: '';

		$image_url = '' !== $local ? $local : $cover;
		if ( '' !== $image_url && ! preg_match( '#^https?://#i', $image_url ) ) {
			$image_url = '';
		}
		if ( '' === $image_url ) {
			return array();
		}

		$media = array(
			'type'        => PostMedia::TYPE_VIDEO,
			'url'         => $image_url,
			'preview_url' => $image_url,
		);

		if ( isset( $node['width'] ) && is_numeric( $node['width'] ) && (int) $node['width'] > 0 ) {
			$media['width'] = (int) $node['width'];
		}
		if ( isset( $node['height'] ) && is_numeric( $node['height'] ) && (int) $node['height'] > 0 ) {
			$media['height'] = (int) $node['height'];
		}

		return array(
			self::apply_alt( $media, $caption, $author_name ),
		);
	}

	/**
	 * Attach SEO-friendly alt text.
	 *
	 * @param array<string,mixed> $media       Media init.
	 * @param string              $caption     Caption.
	 * @param string              $author_name Author.
	 * @return array<string,mixed>
	 */
	private static function apply_alt( array $media, string $caption, string $author_name ): array {
		if ( class_exists( '\EasySocialFeed\SEO\SeoHelper' ) ) {
			$media['alt'] = \EasySocialFeed\SEO\SeoHelper::derive_media_alt( $caption, $author_name, 'video' );
			return $media;
		}
		if ( function_exists( 'esf_seo_media_alt' ) ) {
			$media['alt'] = esf_seo_media_alt( $caption, $author_name, 'video' );
			return $media;
		}

		$snippet = trim( wp_strip_all_tags( $caption ) );
		if ( '' !== $snippet ) {
			$media['alt'] = function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 120 ) : substr( $snippet, 0, 120 );
			return $media;
		}
		if ( '' !== $author_name ) {
			/* translators: 1: account name */
			$media['alt'] = sprintf( __( '%1$s TikTok video', 'easy-facebook-likebox' ), $author_name );
			return $media;
		}
		$media['alt'] = __( 'TikTok video', 'easy-facebook-likebox' );
		return $media;
	}
}
