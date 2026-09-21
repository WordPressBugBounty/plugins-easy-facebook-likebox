<?php
/**
 * YouTube API video → Post mapper.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\YouTube\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\Post;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a YouTube API video node (or normalized cache item) to a Post value object.
 *
 * @since 6.9.0
 */
final class PostMapper {

	/**
	 * Convert a video array to a Post value object.
	 *
	 * Accepts both raw YouTube Data API shape (`snippet` / `statistics`) and the
	 * module's normalized cache shape (`video_id`, `title`, `thumbnail_url`, …).
	 *
	 * @param array<string,mixed> $video   Normalized video from cache/API.
	 * @param AccountSummary|null $account Owning channel.
	 * @return Post|null
	 */
	public static function from_api_node( array $video, ?AccountSummary $account = null ): ?Post {
		$id = self::resolve_video_id( $video );
		if ( '' === $id ) {
			return null;
		}

		$snippet     = isset( $video['snippet'] ) && is_array( $video['snippet'] ) ? $video['snippet'] : array();
		$statistics  = isset( $video['statistics'] ) && is_array( $video['statistics'] ) ? $video['statistics'] : array();
		$title       = isset( $video['title'] ) ? (string) $video['title'] : ( isset( $snippet['title'] ) ? (string) $snippet['title'] : '' );
		$description = isset( $video['description'] ) ? (string) $video['description'] : ( isset( $snippet['description'] ) ? (string) $snippet['description'] : '' );
		$published   = isset( $video['published_at'] ) ? (string) $video['published_at'] : ( isset( $snippet['publishedAt'] ) ? (string) $snippet['publishedAt'] : '' );

		$likes    = isset( $video['like_count'] ) ? (int) $video['like_count'] : ( isset( $statistics['likeCount'] ) ? (int) $statistics['likeCount'] : 0 );
		$comments = isset( $video['comment_count'] ) ? (int) $video['comment_count'] : ( isset( $statistics['commentCount'] ) ? (int) $statistics['commentCount'] : 0 );

		return new Post(
			array(
				'id'         => $id,
				'permalink'  => 'https://www.youtube.com/watch?v=' . rawurlencode( $id ),
				'created_at' => $published,
				'text_html'  => self::format_description_html( $title, $description ),
				'author'     => $account,
				'media'      => self::extract_media( $video, $id, $title ),
				'metrics'    => array(
					'likes'    => $likes,
					'comments' => $comments,
					'shares'   => 0,
				),
				'extras'     => self::extract_extras( $video ),
			)
		);
	}

	/**
	 * Resolve the YouTube video ID from normalized or raw payloads.
	 *
	 * @param array<string,mixed> $video Video data.
	 * @return string
	 */
	private static function resolve_video_id( array $video ): string {
		if ( isset( $video['video_id'] ) && '' !== (string) $video['video_id'] ) {
			return (string) $video['video_id'];
		}
		if ( isset( $video['id'] ) && is_string( $video['id'] ) && '' !== $video['id'] ) {
			return (string) $video['id'];
		}
		if ( isset( $video['id']['videoId'] ) && '' !== (string) $video['id']['videoId'] ) {
			return (string) $video['id']['videoId'];
		}
		return '';
	}

	/**
	 * Format video title and description as HTML.
	 *
	 * @param string $title       Video title.
	 * @param string $description Video description.
	 * @return string
	 */
	private static function format_description_html( string $title, string $description ): string {
		if ( function_exists( 'esf_youtube_format_video_html' ) ) {
			return esf_youtube_format_video_html( $title, $description );
		}

		$html = '';
		if ( '' !== $title ) {
			$html .= '<strong>' . \esc_html( $title ) . '</strong>';
		}
		if ( '' !== $description ) {
			if ( '' !== $html ) {
				$html .= '<br><br>';
			}
			$desc_html = \make_clickable( \esc_html( $description ) );
			if ( function_exists( 'esf_hashtags_to_links' ) ) {
				$desc_html = esf_hashtags_to_links(
					$desc_html,
					'youtube',
					array(
						'class' => 'esf-yt-hash',
					)
				);
			}
			if ( function_exists( 'esf_mentions_to_links' ) ) {
				$desc_html = esf_mentions_to_links(
					$desc_html,
					'youtube',
					array(
						'class' => 'esf-yt-mention',
					)
				);
			}
			$html .= \wpautop( $desc_html );
		}

		return $html;
	}

	/**
	 * Extract media from video.
	 *
	 * @param array<string,mixed> $video Video data.
	 * @param string              $video_id Resolved video ID.
	 * @param string              $title    Video title for alt text.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_media( array $video, string $video_id, string $title ): array {
		$snippet    = isset( $video['snippet'] ) && is_array( $video['snippet'] ) ? $video['snippet'] : array();
		$thumbnails = isset( $snippet['thumbnails'] ) && is_array( $snippet['thumbnails'] ) ? $snippet['thumbnails'] : array();

		$thumbnail_url = '';
		$preview_url   = '';

		// Normalized cache / local media first.
		if ( isset( $video['thumbnail_url'] ) && '' !== (string) $video['thumbnail_url'] ) {
			$thumbnail_url = (string) $video['thumbnail_url'];
			$preview_url   = $thumbnail_url;
		} elseif ( isset( $video['local_thumbnail_url'] ) && '' !== (string) $video['local_thumbnail_url'] ) {
			$thumbnail_url = (string) $video['local_thumbnail_url'];
			$preview_url   = $thumbnail_url;
		} elseif ( isset( $thumbnails['maxres']['url'] ) ) {
			$thumbnail_url = (string) $thumbnails['maxres']['url'];
		} elseif ( isset( $thumbnails['high']['url'] ) ) {
			$thumbnail_url = (string) $thumbnails['high']['url'];
		} elseif ( isset( $thumbnails['medium']['url'] ) ) {
			$thumbnail_url = (string) $thumbnails['medium']['url'];
		} elseif ( isset( $thumbnails['default']['url'] ) ) {
			$thumbnail_url = (string) $thumbnails['default']['url'];
		}

		if ( '' === $preview_url ) {
			if ( isset( $thumbnails['medium']['url'] ) ) {
				$preview_url = (string) $thumbnails['medium']['url'];
			} else {
				$preview_url = $thumbnail_url;
			}
		}

		if ( '' === $thumbnail_url ) {
			return array();
		}

		return array(
			array(
				'type'        => PostMedia::TYPE_VIDEO,
				'url'         => $thumbnail_url,
				'preview_url' => $preview_url,
				'video_url'   => '' !== $video_id ? 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id ) : '',
				'alt'         => '' !== $title ? $title : 'YouTube video',
			),
		);
	}

	/**
	 * Extract YouTube-specific extras.
	 *
	 * @param array<string,mixed> $video Video data.
	 * @return array<string,mixed>
	 */
	private static function extract_extras( array $video ): array {
		$extras = array(
			// Keep the original payload for layout code still reading flat fields.
			'raw_video' => $video,
		);

		$snippet         = isset( $video['snippet'] ) && is_array( $video['snippet'] ) ? $video['snippet'] : array();
		$statistics      = isset( $video['statistics'] ) && is_array( $video['statistics'] ) ? $video['statistics'] : array();
		$content_details = isset( $video['contentDetails'] ) && is_array( $video['contentDetails'] ) ? $video['contentDetails'] : array();

		if ( isset( $video['title'] ) ) {
			$extras['title'] = (string) $video['title'];
		} elseif ( isset( $snippet['title'] ) ) {
			$extras['title'] = (string) $snippet['title'];
		}

		if ( isset( $content_details['duration'] ) ) {
			$extras['duration']           = (string) $content_details['duration'];
			$extras['duration_formatted'] = self::format_duration( (string) $content_details['duration'] );
		} elseif ( isset( $video['duration'] ) ) {
			$extras['duration'] = (string) $video['duration'];
			if ( isset( $video['duration_formatted'] ) ) {
				$extras['duration_formatted'] = (string) $video['duration_formatted'];
			} else {
				$extras['duration_formatted'] = self::format_duration( (string) $video['duration'] );
			}
		}

		if ( isset( $video['view_count'] ) ) {
			$extras['views'] = (int) $video['view_count'];
		} elseif ( isset( $statistics['viewCount'] ) ) {
			$extras['views'] = (int) $statistics['viewCount'];
		}

		if ( isset( $snippet['tags'] ) && is_array( $snippet['tags'] ) ) {
			$extras['tags'] = $snippet['tags'];
		}

		if ( isset( $snippet['categoryId'] ) ) {
			$extras['category_id'] = (string) $snippet['categoryId'];
		}

		return $extras;
	}

	/**
	 * Format ISO 8601 duration to human-readable format.
	 *
	 * @param string $duration ISO 8601 duration (e.g., PT1H2M10S).
	 * @return string
	 */
	private static function format_duration( string $duration ): string {
		if ( function_exists( 'esf_youtube_format_duration' ) ) {
			return esf_youtube_format_duration( $duration );
		}

		try {
			$interval = new \DateInterval( $duration );
		} catch ( \Exception $e ) {
			return '';
		}

		$parts = array();

		if ( $interval->h > 0 ) {
			$parts[] = $interval->h . ':' . str_pad( (string) $interval->i, 2, '0', STR_PAD_LEFT );
		} else {
			$parts[] = (string) $interval->i;
		}

		$parts[] = str_pad( (string) $interval->s, 2, '0', STR_PAD_LEFT );

		return implode( ':', $parts );
	}
}
