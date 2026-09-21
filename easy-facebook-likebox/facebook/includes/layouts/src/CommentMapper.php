<?php
/**
 * Facebook Graph comment node mapper.
 *
 * Normalizes raw Graph API comment payloads into a stable array shape for
 * REST responses and the Pro popup lightbox.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Layouts
 * @since 6.7.9
 */

namespace EasySocialFeed\Facebook\Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps Facebook Graph comment nodes to normalized arrays.
 *
 * @since 6.7.9
 */
final class CommentMapper {

	/**
	 * Map a list of Graph comment nodes.
	 *
	 * @param array<int,array<string,mixed>> $nodes Raw Graph nodes.
	 * @return array<int,array<string,mixed>>
	 */
	public static function from_graph_nodes( array $nodes ): array {
		$comments = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$mapped = self::from_graph_node( $node );
			if ( null !== $mapped ) {
				$comments[] = $mapped;
			}
		}
		return $comments;
	}

	/**
	 * Map one Graph comment node.
	 *
	 * Graph fields: `message`, `created_time`, `from{name,id,picture}`,
	 * `like_count`, `comment_count`, nested `comments` edge for replies,
	 * `attachment.media.image.src` / `attachment.url` for images.
	 *
	 * @param array<string,mixed> $node Raw Graph node.
	 * @return array<string,mixed>|null
	 */
	public static function from_graph_node( array $node ): ?array {
		$id = isset( $node['id'] ) ? (string) $node['id'] : '';
		if ( '' === $id ) {
			return null;
		}

		$text       = isset( $node['message'] ) ? (string) $node['message'] : '';
		$username   = self::resolve_username( $node );
		$avatar_url = self::resolve_avatar_url( $node );
		$media      = self::resolve_media( $node );
		$timestamp  = isset( $node['created_time'] ) ? (string) $node['created_time'] : '';
		$date_human = '';
		if ( '' !== $timestamp && function_exists( 'esf_readable_time_ago' ) ) {
			$date_human = esf_readable_time_ago( $timestamp );
		}

		$replies_meta = self::parse_comments_edge( $node );
		$permalink    = self::resolve_permalink( $node );

		return array(
			'id'                  => sanitize_text_field( $id ),
			'username'            => sanitize_text_field( $username ),
			'avatar_url'          => $avatar_url,
			'permalink'           => $permalink,
			'text'                => $text,
			'timestamp'           => sanitize_text_field( $timestamp ),
			'date_human'          => sanitize_text_field( $date_human ),
			'like_count'          => isset( $node['like_count'] ) ? max( 0, (int) $node['like_count'] ) : 0,
			// `media_url` stays the display/poster image for backward compatibility;
			// `media_type` + `media_video_url` let the popup JS play video attachments.
			'media_url'           => $media['url'],
			'media_type'          => $media['type'],
			'media_video_url'     => $media['video_url'],
			'replies'             => $replies_meta['replies'],
			'reply_count'         => $replies_meta['reply_count'],
			'replies_has_more'    => $replies_meta['replies_has_more'],
			'replies_next_cursor' => $replies_meta['replies_next_cursor'],
		);
	}

	/**
	 * Parse the nested comments edge for reply preview data and pagination.
	 *
	 * Facebook nests replies under `comments` (not Instagram's `replies`).
	 * Falls back to top-level `comment_count` when the edge is absent.
	 *
	 * @param array<string,mixed> $node Raw Graph node.
	 * @return array{replies:array<int,array<string,mixed>>,reply_count:int,replies_has_more:bool,replies_next_cursor:string}
	 */
	private static function parse_comments_edge( array $node ): array {
		$comments_edge = isset( $node['comments'] ) && is_array( $node['comments'] ) ? $node['comments'] : array();
		$replies       = array();

		if ( isset( $comments_edge['data'] ) && is_array( $comments_edge['data'] ) ) {
			foreach ( $comments_edge['data'] as $reply ) {
				if ( ! is_array( $reply ) ) {
					continue;
				}
				$mapped_reply = self::from_graph_node( $reply );
				if ( null !== $mapped_reply ) {
					$mapped_reply['replies']             = array();
					$mapped_reply['reply_count']         = 0;
					$mapped_reply['replies_has_more']    = false;
					$mapped_reply['replies_next_cursor'] = '';
					$replies[]                           = $mapped_reply;
				}
			}
		}

		$paging = isset( $comments_edge['paging'] ) && is_array( $comments_edge['paging'] ) ? $comments_edge['paging'] : array();

		$summary_count = 0;
		if ( isset( $comments_edge['summary']['total_count'] ) ) {
			$summary_count = max( 0, (int) $comments_edge['summary']['total_count'] );
		}
		if ( isset( $paging['summary']['total_count'] ) ) {
			$summary_count = max( $summary_count, (int) $paging['summary']['total_count'] );
		}

		$top_level_count = isset( $node['comment_count'] ) ? max( 0, (int) $node['comment_count'] ) : 0;
		$mapped_count    = count( $replies );
		$graph_total     = max( $summary_count, $top_level_count );
		// Graph often returns `cursors.after` on the last page. Only `paging.next`
		// means another page exists.
		$has_next = isset( $paging['next'] ) && is_string( $paging['next'] ) && '' !== trim( $paging['next'] );
		$cursor   = '';

		if ( $has_next ) {
			// More pages exist — keep a total at least one above what we mapped.
			$reply_count = max( $graph_total, $mapped_count + 1 );
			$has_more    = true;
			if ( isset( $paging['cursors']['after'] ) ) {
				$cursor = sanitize_text_field( (string) $paging['cursors']['after'] );
			}
		} elseif ( $mapped_count > 0 ) {
			// Complete edge with no continuation: trust what Graph actually returned.
			// `comment_count` / summary totals often include ranked/hidden replies that
			// never appear in "All comments" either until filters change — and they
			// inflate labels like "View all 2 replies" when only one is fetchable.
			$reply_count = $mapped_count;
			$has_more    = false;
		} elseif ( $graph_total > 0 ) {
			// Nested edge empty but Graph claims replies — offer a dedicated fetch.
			$reply_count = $graph_total;
			$has_more    = true;
		} else {
			$reply_count = 0;
			$has_more    = false;
		}

		return array(
			'replies'             => $replies,
			'reply_count'         => $reply_count,
			'replies_has_more'    => $has_more,
			'replies_next_cursor' => $cursor,
		);
	}

	/**
	 * Resolve the Facebook permalink for a comment / reply.
	 *
	 * @param array<string,mixed> $node Raw Graph node.
	 * @return string
	 */
	private static function resolve_permalink( array $node ): string {
		$url = '';
		if ( isset( $node['permalink_url'] ) ) {
			$url = (string) $node['permalink_url'];
		} elseif ( isset( $node['permalink'] ) ) {
			$url = (string) $node['permalink'];
		}

		return self::sanitize_url( $url );
	}

	/**
	 * Resolve a display name from Graph comment `from` fields.
	 *
	 * @param array<string,mixed> $node Raw Graph node.
	 * @return string
	 */
	private static function resolve_username( array $node ): string {
		if ( ! isset( $node['from'] ) || ! is_array( $node['from'] ) ) {
			return '';
		}

		$from_name = isset( $node['from']['name'] ) ? trim( (string) $node['from']['name'] ) : '';
		if ( '' !== $from_name ) {
			return $from_name;
		}

		return isset( $node['from']['id'] ) ? trim( (string) $node['from']['id'] ) : '';
	}

	/**
	 * Resolve avatar URL from `from.picture`.
	 *
	 * @param array<string,mixed> $node Raw Graph node.
	 * @return string
	 */
	private static function resolve_avatar_url( array $node ): string {
		if ( ! isset( $node['from'] ) || ! is_array( $node['from'] ) ) {
			return '';
		}

		$from = $node['from'];
		if ( ! isset( $from['picture'] ) ) {
			return '';
		}

		$picture = $from['picture'];
		$url     = '';

		if ( is_array( $picture ) ) {
			if ( isset( $picture['data']['url'] ) ) {
				$url = (string) $picture['data']['url'];
			} elseif ( isset( $picture['url'] ) ) {
				$url = (string) $picture['url'];
			}
		} elseif ( is_string( $picture ) ) {
			$url = $picture;
		}

		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$sanitized = esc_url_raw( $url );
		return is_string( $sanitized ) ? $sanitized : '';
	}

	/**
	 * Graph comment attachment `type` values treated as playable video.
	 *
	 * @var array<int,string>
	 */
	private const VIDEO_ATTACHMENT_TYPES = array( 'video_inline', 'video', 'animated_image_video' );

	/**
	 * Resolve display/poster image + optional video source from a comment attachment.
	 *
	 * Photo attachments: `attachment.media.image.src` (fallback `attachment.url`).
	 * Video / animated GIF attachments: `attachment.media.image.src` is the poster
	 * frame and `attachment.media.source` (when Graph exposes it) is the playable
	 * MP4 URL. Absent a playable source, the poster still renders as a static image
	 * so malformed/permission-limited attachments degrade gracefully instead of
	 * disappearing.
	 *
	 * @param array<string,mixed> $node Raw Graph node.
	 * @return array{type:string,url:string,video_url:string}
	 */
	private static function resolve_media( array $node ): array {
		$result = array(
			'type'      => '',
			'url'       => '',
			'video_url' => '',
		);

		if ( ! isset( $node['attachment'] ) || ! is_array( $node['attachment'] ) ) {
			return $result;
		}

		$attachment = $node['attachment'];
		$type       = isset( $attachment['type'] ) ? sanitize_key( (string) $attachment['type'] ) : '';

		$image_url = '';
		if ( isset( $attachment['media']['image']['src'] ) ) {
			$image_url = (string) $attachment['media']['image']['src'];
		} elseif ( isset( $attachment['url'] ) ) {
			$image_url = (string) $attachment['url'];
		}

		$video_url = isset( $attachment['media']['source'] ) ? (string) $attachment['media']['source'] : '';

		$is_video = '' !== trim( $video_url ) || in_array( $type, self::VIDEO_ATTACHMENT_TYPES, true );

		$result['url']       = self::sanitize_url( $image_url );
		$result['video_url'] = $is_video ? self::sanitize_url( $video_url ) : '';
		if ( $is_video ) {
			$result['type'] = 'video';
		} elseif ( '' !== $result['url'] ) {
			$result['type'] = 'photo';
		}

		return $result;
	}

	/**
	 * Sanitize an absolute media URL, dropping empty/invalid values.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function sanitize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$sanitized = esc_url_raw( $url );
		return is_string( $sanitized ) ? $sanitized : '';
	}
}
