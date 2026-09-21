<?php
/**
 * Detect and normalize restricted Facebook attachment embeds.
 *
 * Graph often returns Meta's "This content is currently unavailable." copy
 * inside attachment title/description for shared events and cross-Page posts.
 * Layouts should never surface that text as user-facing feed chrome.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts
 * @since 6.7.9
 */

namespace EasySocialFeed\Facebook\Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitization helpers for link/event attachment cards.
 *
 * @since 6.7.9
 */
final class FacebookEmbedSupport {

	/**
	 * Whether Graph copy is Meta's unavailable-content placeholder.
	 *
	 * @since 6.7.9
	 * @param string $text Attachment title or description.
	 * @return bool
	 */
	public static function is_unavailable_graph_text( string $text ): bool {
		$text = trim( $text );
		if ( '' === $text ) {
			return false;
		}

		$lower   = strtolower( $text );
		$needles = array(
			'this content is currently unavailable',
			"this content isn't available right now",
			'this content is not available',
			'when this happens, it\'s usually because',
			'when this happens, it is usually because',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Strip unavailable markers; return empty string when the whole value is noise.
	 *
	 * @since 6.7.9
	 * @param string $text Raw attachment field.
	 * @return string
	 */
	public static function sanitize_attachment_text( string $text ): string {
		if ( self::is_unavailable_graph_text( $text ) ) {
			return '';
		}
		return trim( $text );
	}

	/**
	 * Whether two caption strings are duplicates for dedupe purposes.
	 *
	 * @since 6.7.9
	 * @param string $a First plain-text caption.
	 * @param string $b Second plain-text caption.
	 * @return bool
	 */
	public static function texts_are_duplicate( string $a, string $b ): bool {
		$a = trim( wp_strip_all_tags( $a ) );
		$b = trim( wp_strip_all_tags( $b ) );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		if ( strlen( $a ) > 20 && false !== strpos( $b, $a ) ) {
			return true;
		}
		if ( strlen( $b ) > 20 && false !== strpos( $a, $b ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether a link_card has enough metadata to render.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $card Link card payload.
	 * @return bool
	 */
	public static function is_link_card_renderable( array $card ): bool {
		$title       = isset( $card['title'] ) ? trim( (string) $card['title'] ) : '';
		$description = isset( $card['description'] ) ? trim( (string) $card['description'] ) : '';
		$url         = isset( $card['url'] ) ? trim( (string) $card['url'] ) : '';
		$kind        = isset( $card['kind'] ) ? sanitize_key( (string) $card['kind'] ) : 'link';

		if ( 'album' === $kind ) {
			$photo_count = isset( $card['photo_count'] ) ? max( 0, (int) $card['photo_count'] ) : 0;
			return '' !== $title || $photo_count > 0 || '' !== $description;
		}

		$start_label = isset( $card['start_time'] ) ? trim( (string) $card['start_time'] ) : '';
		$place_name  = isset( $card['place_name'] ) ? trim( (string) $card['place_name'] ) : '';

		return '' !== $title
			|| '' !== $description
			|| '' !== $start_label
			|| '' !== $place_name;
	}

	/**
	 * Whether popup/lightbox should be disabled for a mapped post.
	 *
	 * Restricted shared embeds and cross-Page shared videos are not useful
	 * lightbox targets — the card already shows a placeholder + View on Facebook.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $extras PostMapper extras.
	 * @return bool
	 */
	public static function should_disable_popup( array $extras ): bool {
		if ( ! empty( $extras['embed_restricted'] ) ) {
			return true;
		}

		$embed_kind = isset( $extras['embed_kind'] ) ? sanitize_key( (string) $extras['embed_kind'] ) : '';
		$media_type = isset( $extras['media_type'] ) ? strtoupper( (string) $extras['media_type'] ) : '';

		return 'video' === $embed_kind && 'LINK' === $media_type;
	}

	/**
	 * Resolve placeholder kind for a restricted embed.
	 *
	 * @since 6.7.9
	 * @param string                   $media_kind  PostMapper media kind.
	 * @param string                   $status_type Graph status_type.
	 * @param array<string,mixed>|null $attachment  Primary attachment.
	 * @return string event|share|link
	 */
	public static function resolve_embed_kind( string $media_kind, string $status_type, ?array $attachment ): string {
		if ( 'event' === $media_kind || 'created_event' === $status_type ) {
			return 'event';
		}

		$att_type = ( null !== $attachment && isset( $attachment['type'] ) )
			? strtolower( (string) $attachment['type'] )
			: '';

		if ( in_array( $att_type, array( 'event', 'map' ), true ) ) {
			return 'event';
		}
		if ( in_array( $att_type, array( 'video_inline', 'video_autoplay', 'video', 'video_share_youtube', 'video_share_other' ), true ) ) {
			return 'video';
		}
		if ( 'shared_story' === $status_type || 'share' === $att_type ) {
			return 'share';
		}

		return 'link';
	}

	/**
	 * Sanitize link_card fields and decide whether a placeholder is needed.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed>      $card        Raw link card.
	 * @param string                   $caption     Post caption (plain).
	 * @param string                   $media_kind  PostMapper media kind.
	 * @param string                   $status_type Graph status_type.
	 * @param array<string,mixed>|null $attachment  Primary attachment.
	 * @return array{card:array<string,mixed>,embed_restricted:bool,embed_kind:string,event_id:string,had_unavailable:bool}
	 */
	public static function finalize_link_card(
		array $card,
		string $caption,
		string $media_kind,
		string $status_type,
		?array $attachment
	): array {
		$raw_title = isset( $card['title'] ) ? trim( (string) $card['title'] ) : '';
		$raw_desc  = isset( $card['description'] ) ? trim( (string) $card['description'] ) : '';

		$had_unavailable = self::is_unavailable_graph_text( $raw_title )
			|| self::is_unavailable_graph_text( $raw_desc );

		$card['title']       = self::sanitize_attachment_text( $raw_title );
		$card['description'] = self::sanitize_attachment_text( $raw_desc );

		if ( self::texts_are_duplicate( $caption, $card['description'] ) ) {
			$card['description'] = '';
		}

		$embed_kind = self::resolve_embed_kind( $media_kind, $status_type, $attachment );
		$event_id   = self::extract_event_id_from_attachment( $attachment );

		$renderable       = self::is_link_card_renderable( $card );
		$embed_restricted = $had_unavailable && ! $renderable;

		return array(
			'card'             => $card,
			'embed_restricted' => $embed_restricted,
			'embed_kind'       => $embed_kind,
			'event_id'         => $event_id,
			'had_unavailable'  => $had_unavailable,
		);
	}

	/**
	 * Extract a numeric Facebook Event id from an attachment payload.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed>|null $attachment Primary attachment.
	 * @return string
	 */
	public static function extract_event_id_from_attachment( ?array $attachment ): string {
		if ( null === $attachment ) {
			return '';
		}

		if ( isset( $attachment['target']['id'] ) ) {
			$id = trim( (string) $attachment['target']['id'] );
			if ( '' !== $id && preg_match( '/^\d+$/', $id ) ) {
				return $id;
			}
		}

		$url_keys = array( 'url', 'unshimmed_url' );
		foreach ( $url_keys as $key ) {
			if ( ! isset( $attachment[ $key ] ) || ! is_string( $attachment[ $key ] ) ) {
				continue;
			}
			$event_id = self::extract_event_id_from_url( (string) $attachment[ $key ] );
			if ( '' !== $event_id ) {
				return $event_id;
			}
		}

		if ( isset( $attachment['target']['url'] ) && is_string( $attachment['target']['url'] ) ) {
			return self::extract_event_id_from_url( (string) $attachment['target']['url'] );
		}

		return '';
	}

	/**
	 * Whether a feed node should attempt Pro event enrichment.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $node Graph post node.
	 * @return bool
	 */
	public static function node_needs_event_enrichment( array $node ): bool {
		if ( ! empty( $node['_esf_embed_enriched'] ) || ! empty( $node['_esf_embed_enrich_failed'] ) ) {
			return false;
		}

		$attachment = self::primary_attachment_from_node( $node );
		if ( null === $attachment ) {
			return false;
		}

		$event_id = self::extract_event_id_from_attachment( $attachment );
		if ( '' === $event_id ) {
			return false;
		}

		$status_type = isset( $node['status_type'] ) ? (string) $node['status_type'] : '';
		$att_type    = isset( $attachment['type'] ) ? strtolower( (string) $attachment['type'] ) : '';

		$is_eventish = in_array( $att_type, array( 'event', 'map', 'share' ), true )
			|| in_array( $status_type, array( 'shared_story', 'created_event' ), true );

		if ( ! $is_eventish ) {
			return false;
		}

		$title = isset( $attachment['title'] ) ? trim( (string) $attachment['title'] ) : '';
		$desc  = isset( $attachment['description'] ) ? trim( (string) $attachment['description'] ) : '';

		if ( self::is_unavailable_graph_text( $title ) || self::is_unavailable_graph_text( $desc ) ) {
			return true;
		}

		$has_start = isset( $attachment['target']['start_time'] )
			&& is_string( $attachment['target']['start_time'] )
			&& '' !== trim( $attachment['target']['start_time'] );

		if ( ! $has_start ) {
			return true;
		}

		// Native Page events may include an id but sparse attachment copy.
		if ( 'created_event' === $status_type && '' === $title ) {
			return true;
		}

		return false;
	}

	/**
	 * Merge a fetched Event node into a feed post's primary attachment.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $node  Feed post node.
	 * @param array<string,mixed> $event Event Graph node.
	 * @return array<string,mixed>
	 */
	public static function merge_event_into_node( array $node, array $event ): array {
		if (
			! isset( $node['attachments']['data'] )
			|| ! is_array( $node['attachments']['data'] )
			|| ! isset( $node['attachments']['data'][0] )
			|| ! is_array( $node['attachments']['data'][0] )
		) {
			return $node;
		}

		$attachment = $node['attachments']['data'][0];
		$message    = isset( $node['message'] ) ? trim( (string) $node['message'] ) : '';

		if ( isset( $event['name'] ) && is_string( $event['name'] ) && '' !== trim( $event['name'] ) ) {
			$attachment['title'] = trim( $event['name'] );
		}

		if ( isset( $event['description'] ) && is_string( $event['description'] ) ) {
			$desc = trim( $event['description'] );
			if ( '' !== $desc && ! self::texts_are_duplicate( $message, $desc ) && ! self::is_unavailable_graph_text( $desc ) ) {
				$attachment['description'] = $desc;
			}
		}

		if ( ! isset( $attachment['target'] ) || ! is_array( $attachment['target'] ) ) {
			$attachment['target'] = array();
		}

		foreach ( array( 'start_time', 'end_time', 'ticket_uri', 'place' ) as $field ) {
			if ( isset( $event[ $field ] ) ) {
				$attachment['target'][ $field ] = $event[ $field ];
			}
		}

		if ( isset( $event['id'] ) ) {
			$attachment['target']['id'] = (string) $event['id'];
		}

		$event_url = 'https://www.facebook.com/events/' . rawurlencode( (string) ( $event['id'] ?? '' ) );
		if ( '' === trim( (string) ( $attachment['url'] ?? '' ) ) ) {
			$attachment['url'] = $event_url;
		}

		$cover_url = self::resolve_event_cover_url( $event );
		if ( '' !== $cover_url ) {
			if ( ! isset( $attachment['media'] ) || ! is_array( $attachment['media'] ) ) {
				$attachment['media'] = array();
			}
			if ( ! isset( $attachment['media']['image'] ) || ! is_array( $attachment['media']['image'] ) ) {
				$attachment['media']['image'] = array();
			}
			$attachment['media']['image']['src'] = $cover_url;

			$has_picture = isset( $node['full_picture'] )
				&& is_string( $node['full_picture'] )
				&& '' !== trim( $node['full_picture'] );
			if ( ! $has_picture ) {
				$node['full_picture'] = $cover_url;
			}
		}

		$node['attachments']['data'][0] = $attachment;
		$node['_esf_embed_enriched']    = true;

		return $node;
	}

	/**
	 * Mark a node so enrichment is not retried after a failed Graph fetch.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $node Feed post node.
	 * @return array<string,mixed>
	 */
	public static function mark_enrichment_failed( array $node ): array {
		$node['_esf_embed_enrich_failed'] = true;
		return $node;
	}

	/**
	 * Resolve cover/picture URL from an Event node.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $event Event Graph node.
	 * @return string
	 */
	public static function resolve_event_cover_url( array $event ): string {
		if ( isset( $event['cover']['source'] ) && is_string( $event['cover']['source'] ) ) {
			$url = trim( $event['cover']['source'] );
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				return $url;
			}
		}

		if ( isset( $event['picture'] ) && is_string( $event['picture'] ) ) {
			$url = trim( $event['picture'] );
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * First attachment object from a feed node.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $node Graph post.
	 * @return array<string,mixed>|null
	 */
	private static function primary_attachment_from_node( array $node ): ?array {
		if ( ! isset( $node['attachments']['data'] ) || ! is_array( $node['attachments']['data'] ) ) {
			return null;
		}
		$first = $node['attachments']['data'][0] ?? null;
		return is_array( $first ) ? $first : null;
	}

	/**
	 * Whether a raw Graph node still has Meta's unavailable attachment copy.
	 *
	 * Used by the moderation grid as a safety net when PostMapper media_kind
	 * skips the link-card finalize path (e.g. some shared video shapes).
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $node Graph post node.
	 * @return bool
	 */
	public static function node_has_unavailable_attachment( array $node ): bool {
		$attachment = self::primary_attachment_from_node( $node );
		if ( null === $attachment ) {
			return false;
		}

		$title = isset( $attachment['title'] ) ? trim( (string) $attachment['title'] ) : '';
		$desc  = isset( $attachment['description'] ) ? trim( (string) $attachment['description'] ) : '';

		return self::is_unavailable_graph_text( $title ) || self::is_unavailable_graph_text( $desc );
	}

	/**
	 * Parse a numeric event id from a facebook.com/events URL.
	 *
	 * @since 6.7.9
	 * @param string $url URL.
	 * @return string
	 */
	private static function extract_event_id_from_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#facebook\.com/events/(\d+)#i', $url, $matches ) ) {
			return (string) $matches[1];
		}
		return '';
	}
}
