<?php
/**
 * Instagram Graph media node → Post mapper.
 *
 * Normalizes the three IG media types (IMAGE / VIDEO / CAROUSEL_ALBUM) into
 * the canonical Post + PostMedia shape so layouts never touch raw API data.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Instagram\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\Post;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps an Instagram Graph media node to a canonical Post value object.
 *
 * @since 6.9.0
 */
final class PostMapper {

	/**
	 * Convert a Graph API media node to a Post value object.
	 *
	 * Expected (canonical Graph fields):
	 *  - id, caption, media_type, media_url, thumbnail_url, permalink, timestamp
	 *  - like_count, comments_count
	 *  - children.data[]  (for CAROUSEL_ALBUM)
	 *
	 * @param array<string,mixed> $node     Raw Graph media node.
	 * @param AccountSummary|null $account  Owning account (used as default author).
	 */
	public static function from_graph_node( array $node, ?AccountSummary $account = null ): ?Post {
		$id = isset( $node['id'] ) ? (string) $node['id'] : '';
		if ( '' === $id ) {
			return null;
		}

		$media_type = strtoupper( isset( $node['media_type'] ) ? (string) $node['media_type'] : 'IMAGE' );
		$caption    = isset( $node['caption'] ) ? (string) $node['caption'] : '';
		$author_name = $account instanceof AccountSummary ? $account->get_name() : '';

		return new Post(
			array(
				'id'         => $id,
				'permalink'  => isset( $node['permalink'] ) ? (string) $node['permalink'] : '',
				'created_at' => isset( $node['timestamp'] ) ? (string) $node['timestamp'] : '',
				'text_html'  => function_exists( 'esf_instagram_format_caption_html' )
					? esf_instagram_format_caption_html( $caption )
					: ( '' !== $caption ? wpautop( make_clickable( esc_html( $caption ) ) ) : '' ),
				'author'     => $account,
				'media'      => self::extract_media( $node, $media_type, $caption, $author_name ),
				'metrics'    => array(
					'likes'    => isset( $node['like_count'] ) ? (int) $node['like_count'] : 0,
					'comments' => isset( $node['comments_count'] ) ? (int) $node['comments_count'] : 0,
				),
				'extras'     => array(
					'media_type' => $media_type,
					'is_reel'    => 'REELS' === $media_type || 'REEL' === $media_type,
				),
			)
		);
	}

	/**
	 * Build the PostMedia[] list for a Graph node, expanding CAROUSEL_ALBUM children.
	 *
	 * @param array<string,mixed> $node       Graph media node.
	 * @param string              $media_type Uppercased media_type.
	 * @param string              $caption    Post caption for alt text derivation.
	 * @param string              $author_name Account display name for alt fallback.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_media( array $node, string $media_type, string $caption = '', string $author_name = '' ): array {
		if ( 'CAROUSEL_ALBUM' === $media_type ) {
			$children = isset( $node['children']['data'] ) && is_array( $node['children']['data'] )
				? $node['children']['data']
				: array();

			$items = array();
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}
				$child_type = strtoupper( isset( $child['media_type'] ) ? (string) $child['media_type'] : 'IMAGE' );
				$items[]    = self::media_array_from_node( $child, $child_type, $caption, $author_name );
			}

			return array_values( array_filter( $items ) );
		}

		$tuple = self::media_array_from_node( $node, $media_type, $caption, $author_name );
		return null === $tuple ? array() : array( $tuple );
	}

	/**
	 * Convert a single Graph media node to a PostMedia init array.
	 *
	 * @param array<string,mixed> $node       Graph media node.
	 * @param string              $media_type Uppercased media_type for the node.
	 * @param string              $caption    Post caption for alt text derivation.
	 * @param string              $author_name Account display name for alt fallback.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function media_array_from_node( array $node, string $media_type, string $caption = '', string $author_name = '' ): ?array {
		$media_url     = isset( $node['media_url'] ) ? (string) $node['media_url'] : '';
		$thumbnail_url = isset( $node['thumbnail_url'] ) ? (string) $node['thumbnail_url'] : '';

		if ( 'VIDEO' === $media_type || 'REELS' === $media_type || 'REEL' === $media_type ) {
			// A video poster must be an image. Hashtag edges don't return `thumbnail_url`,
			// so render a theme-aware CSS tile surface instead of the raw video file
			// (which would break as an `<img>`) or a repeated static SVG poster.
			if ( '' !== $thumbnail_url ) {
				$media = array(
					'type'        => PostMedia::TYPE_VIDEO,
					'url'         => $thumbnail_url,
					'preview_url' => $thumbnail_url,
					'video_url'   => $media_url,
				);
				if ( isset( $node['width'] ) && (int) $node['width'] > 0 ) {
					$media['width'] = (int) $node['width'];
				}
				if ( isset( $node['height'] ) && (int) $node['height'] > 0 ) {
					$media['height'] = (int) $node['height'];
				}
				return self::apply_media_alt( $media, $caption, $author_name, $media_type );
			}

			if ( '' === $media_url ) {
				return null;
			}

			$default_placeholder = self::default_video_placeholder_url();
			$placeholder_url     = self::video_placeholder_url();
			if ( '' !== $placeholder_url && $placeholder_url !== $default_placeholder ) {
				/**
				 * Filter override: keep a custom image poster when the site replaces
				 * the bundled SVG via {@see self::video_placeholder_url()}.
				 */
				return self::apply_media_alt(
					array(
						'type'        => PostMedia::TYPE_VIDEO,
						'url'         => $placeholder_url,
						'preview_url' => $placeholder_url,
						'video_url'   => $media_url,
					),
					$caption,
					$author_name,
					$media_type
				);
			}

			return self::apply_media_alt(
				array(
					'type'                  => PostMedia::TYPE_VIDEO,
					'url'                   => '',
					'preview_url'           => '',
					'video_url'             => $media_url,
					'is_placeholder_poster' => true,
				),
				$caption,
				$author_name,
				$media_type
			);
		}

		if ( '' === $media_url ) {
			return null;
		}

		$media = array(
			'type'        => PostMedia::TYPE_PHOTO,
			'url'         => $media_url,
			'preview_url' => '' !== $thumbnail_url ? $thumbnail_url : $media_url,
		);

		if ( isset( $node['width'] ) && (int) $node['width'] > 0 ) {
			$media['width'] = (int) $node['width'];
		}
		if ( isset( $node['height'] ) && (int) $node['height'] > 0 ) {
			$media['height'] = (int) $node['height'];
		}

		return self::apply_media_alt( $media, $caption, $author_name, $media_type );
	}

	/**
	 * Attach SEO-friendly alt text to a PostMedia init array.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,mixed> $media       Media init array.
	 * @param string              $caption     Post caption.
	 * @param string              $author_name Account display name.
	 * @param string              $media_type  Uppercased Graph media type.
	 * @return array<string,mixed>
	 */
	private static function apply_media_alt( array $media, string $caption, string $author_name, string $media_type ): array {
		$alt_type = in_array( $media_type, array( 'VIDEO', 'REELS', 'REEL' ), true ) ? 'video' : 'photo';

		if ( class_exists( '\EasySocialFeed\SEO\SeoHelper' ) ) {
			$media['alt'] = \EasySocialFeed\SEO\SeoHelper::derive_media_alt( $caption, $author_name, $alt_type );
		} elseif ( function_exists( 'esf_seo_media_alt' ) ) {
			$media['alt'] = esf_seo_media_alt( $caption, $author_name, $alt_type );
		}

		return $media;
	}

	/**
	 * Bundled SVG poster used by admin grids and filter overrides.
	 *
	 * @return string Placeholder image URL, or empty string when unavailable.
	 */
	private static function default_video_placeholder_url(): string {
		return defined( 'ESF_INSTA_PLUGIN_URL' )
			? ESF_INSTA_PLUGIN_URL . 'frontend/assets/images/esf-insta-video-placeholder.svg'
			: '';
	}

	/**
	 * Placeholder poster used for videos that have no `thumbnail_url`.
	 *
	 * @return string Placeholder image URL, or empty string when unavailable.
	 */
	private static function video_placeholder_url(): string {
		$default = self::default_video_placeholder_url();

		/**
		 * Filter the placeholder poster shown for videos without a thumbnail.
		 *
		 * Return a custom image URL to keep image-based posters. The default
		 * bundled SVG triggers the CSS poster surface in grid layouts.
		 *
		 * @param string $default Placeholder image URL.
		 */
		$url = apply_filters( 'esf_instagram_video_placeholder_url', $default );

		return is_string( $url ) ? $url : '';
	}
}
