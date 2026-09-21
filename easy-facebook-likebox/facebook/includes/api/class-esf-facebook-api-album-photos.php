<?php

/**
 * Facebook Album Photos REST API (Pro popup gallery load-more).
 *
 * Returns paginated subattachment photos for a multi-photo Page post, with DB
 * caching tied to the module cache duration. Mirrors the flat append + offset
 * slice model of ESF_Facebook_API_Post_Comments.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
use EasySocialFeed\Facebook\Layouts\PostMapper;
/**
 * Class ESF_Facebook_API_Album_Photos
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Album_Photos {
    use Trait_ESF_Facebook_Popup_Api_Support;
    /**
     * Maximum photo tuples kept in the local album cache bucket.
     *
     * @since 6.7.9
     * @var int
     */
    const MAX_LOCAL_CACHE_PHOTOS = 200;

    /**
     * Maximum Graph round-trips per request when filling a page window.
     *
     * @since 6.7.9
     * @var int
     */
    const MAX_FETCH_ROUNDS = 5;

    /**
     * Build a cache key for a post's album photos bucket.
     *
     * @since 6.7.9
     *
     * @param string $post_id Sanitized post ID.
     * @return string
     */
    public static function album_cache_key( $post_id ) {
        $post_id = preg_replace( '/[^0-9_]/', '', (string) $post_id );
        $post_id = substr( (string) $post_id, 0, 200 );
        return 'esf_fb_album_' . $post_id;
    }

    /**
     * Normalize an album photos cache bucket.
     *
     * `cursor` is a Graph paging *URL* for the (often absent) `subattachments`
     * connection; `photos_after` is a plain cursor string for the
     * `/{album-id}/photos` edge fallback. `total_count` is that album edge's
     * own `photos.summary=true` count — **not necessarily trustworthy** for
     * this specific post (see `declared_total`). `direct_fetch_done` marks
     * whether the one-time higher-limit per-post `subattachments` refetch
     * (ESF_Facebook_Graph_Client::fetch_post_subattachments__premium_only())
     * has already run, so it is never repeated on every Load More click.
     * `declared_total` is the authoritative, **post-specific** ceiling —
     * `attachment.target.count` or a parsed "added N new photos" story —
     * used to bound (or disable entirely) the `/{album-id}/photos` fallback,
     * because `target.id` can resolve to a shared/recurring Page album (e.g.
     * "Timeline Photos") whose own total reflects that album's *entire*
     * history, not just this post.
     *
     * @since 6.7.9
     * @since 6.7.9 Added `declared_total`.
     *
     * @param array<string,mixed>|null $cached Raw cache payload.
     * @return array{photos:array<int,array<string,mixed>>,cursor:string,album_id:string,photos_after:string,total_count:int,direct_fetch_done:bool,declared_total:int}
     */
    public static function normalize_album_cache( $cached ) {
        $photos = array();
        $cursor = '';
        $album_id = '';
        $photos_after = '';
        $total_count = 0;
        $direct_fetch_done = false;
        $declared_total = 0;
        if ( is_array( $cached ) ) {
            if ( isset( $cached['photos'] ) && is_array( $cached['photos'] ) ) {
                $photos = array_values( $cached['photos'] );
            }
            if ( isset( $cached['cursor'] ) ) {
                $cursor = sanitize_text_field( (string) $cached['cursor'] );
            }
            if ( isset( $cached['album_id'] ) ) {
                $candidate = sanitize_text_field( (string) $cached['album_id'] );
                if ( preg_match( '/^\\d+$/', $candidate ) ) {
                    $album_id = $candidate;
                }
            }
            if ( isset( $cached['photos_after'] ) ) {
                $photos_after = sanitize_text_field( (string) $cached['photos_after'] );
            }
            if ( isset( $cached['total_count'] ) ) {
                $total_count = max( 0, (int) $cached['total_count'] );
            }
            if ( isset( $cached['direct_fetch_done'] ) ) {
                $direct_fetch_done = (bool) $cached['direct_fetch_done'];
            }
            if ( isset( $cached['declared_total'] ) ) {
                $declared_total = max( 0, (int) $cached['declared_total'] );
            }
        }
        return array(
            'photos'            => $photos,
            'cursor'            => $cursor,
            'album_id'          => $album_id,
            'photos_after'      => $photos_after,
            'total_count'       => $total_count,
            'direct_fetch_done' => $direct_fetch_done,
            'declared_total'    => $declared_total,
        );
    }

    /**
     * Merge mapped photo tuples by URL without duplicates.
     *
     * @since 6.7.9
     *
     * @param array<int,array<string,mixed>> $existing Existing mapped photos.
     * @param array<int,array<string,mixed>> $incoming New mapped photos.
     * @return array<int,array<string,mixed>>
     */
    public static function merge_photos_by_url( array $existing, array $incoming ) {
        $merged = $existing;
        $seen = array();
        foreach ( $merged as $photo ) {
            if ( !is_array( $photo ) ) {
                continue;
            }
            $url = ( isset( $photo['url'] ) ? (string) $photo['url'] : (( isset( $photo['src'] ) ? (string) $photo['src'] : '' )) );
            if ( '' !== $url ) {
                $seen[$url] = true;
            }
        }
        foreach ( $incoming as $photo ) {
            if ( !is_array( $photo ) ) {
                continue;
            }
            $url = ( isset( $photo['url'] ) ? (string) $photo['url'] : (( isset( $photo['src'] ) ? (string) $photo['src'] : '' )) );
            if ( '' === $url || isset( $seen[$url] ) ) {
                continue;
            }
            $merged[] = $photo;
            $seen[$url] = true;
        }
        if ( count( $merged ) > self::MAX_LOCAL_CACHE_PHOTOS ) {
            $merged = array_slice( $merged, 0, self::MAX_LOCAL_CACHE_PHOTOS );
        }
        return array_values( $merged );
    }

    /**
     * Whether another Graph API request is needed for the requested window.
     *
     * @since 6.7.9
     *
     * @param int    $total      Items currently stored locally.
     * @param int    $offset     Client offset.
     * @param int    $count      Items available in the current slice.
     * @param int    $per_page   Requested page size.
     * @param string $api_cursor Graph paging URL / cursor, if any.
     * @return bool
     */
    public static function needs_remote_fetch(
        $total,
        $offset,
        $count,
        $per_page,
        $api_cursor
    ) {
        if ( 0 === $total && '' === $api_cursor ) {
            return true;
        }
        return '' !== $api_cursor && ($offset >= $total || $count < $per_page);
    }

    /**
     * Format the REST response payload.
     *
     * @since 6.7.9
     *
     * @param array<int,array<string,mixed>> $photos      Photo rows for this page.
     * @param bool                           $has_more    Whether more photos exist.
     * @param int                            $next_offset Next client offset when has_more is true.
     * @param int                            $total_count Authoritative album total when known (0 = unknown).
     * @return array<string,mixed>
     */
    public static function format_response(
        array $photos,
        $has_more,
        $next_offset,
        $total_count = 0
    ) {
        return array(
            'photos'        => $photos,
            'has_more'      => (bool) $has_more,
            'next_offset'   => max( 0, (int) $next_offset ),
            'total_fetched' => count( $photos ),
            'total_count'   => max( 0, (int) $total_count ),
        );
    }

    /**
     * Map PostMedia init tuples into lightbox-friendly media items.
     *
     * @since 6.7.9
     * @since 6.7.9 Preserve `type`/`video_embed_url` instead of forcing every
     *               item to `'photo'` — mixed-media multi-photo posts can
     *               include an embedded video subattachment, rendered via
     *               Facebook's own video plugin embed (no raw `<video>`
     *               source, no extra Graph call).
     *
     * @param array<int,array<string,mixed>> $tuples Mapped media tuples.
     * @return array<int,array<string,mixed>>
     */
    public static function to_popup_media_items( array $tuples ) {
        $items = array();
        foreach ( $tuples as $tuple ) {
            if ( !is_array( $tuple ) ) {
                continue;
            }
            $src = '';
            if ( isset( $tuple['url'] ) && is_string( $tuple['url'] ) ) {
                $src = trim( $tuple['url'] );
            }
            if ( '' === $src && isset( $tuple['preview_url'] ) && is_string( $tuple['preview_url'] ) ) {
                $src = trim( $tuple['preview_url'] );
            }
            if ( '' === $src || !preg_match( '#^https?://#i', $src ) ) {
                continue;
            }
            $is_video = isset( $tuple['type'] ) && 'video' === $tuple['type'];
            $video_embed_url = '';
            if ( $is_video && isset( $tuple['video_embed_url'] ) && is_string( $tuple['video_embed_url'] ) ) {
                $candidate = trim( $tuple['video_embed_url'] );
                if ( preg_match( '#^https?://#i', $candidate ) ) {
                    $video_embed_url = $candidate;
                }
            }
            $items[] = array(
                'type'            => ( $is_video ? 'video' : 'photo' ),
                'src'             => esc_url_raw( $src ),
                'url'             => esc_url_raw( $src ),
                'video_embed_url' => ( '' !== $video_embed_url ? esc_url_raw( $video_embed_url ) : '' ),
                'alt'             => ( isset( $tuple['alt'] ) ? sanitize_text_field( (string) $tuple['alt'] ) : '' ),
                'width'           => ( isset( $tuple['width'] ) ? (int) $tuple['width'] : 0 ),
                'height'          => ( isset( $tuple['height'] ) ? (int) $tuple['height'] : 0 ),
            );
        }
        return $items;
    }

    /**
     * Extract mapped photo tuples + next cursor + album id + declared total
     * from a cached feed node (or a fresh direct per-post refetch — same shape).
     *
     * @since 6.7.9
     * @since 6.7.9 Added `declared_total`.
     *
     * @param array<string,mixed> $node Graph post node.
     * @return array{photos:array<int,array<string,mixed>>,cursor:string,album_id:string,declared_total:int}
     */
    public static function seed_from_feed_node( array $node ) {
        $photos = array();
        $cursor = '';
        $album_id = '';
        // Albums-filter cards: the node id *is* the album id; `count` is the
        // authoritative photo total for lightbox load-more via /{album-id}/photos.
        if ( isset( $node['count'] ) && is_numeric( $node['count'] ) && isset( $node['name'] ) && !isset( $node['attachments'] ) && !isset( $node['status_type'] ) && !isset( $node['message'] ) && !isset( $node['start_time'] ) ) {
            $album_node_id = '';
            if ( isset( $node['id'] ) ) {
                $candidate = sanitize_text_field( (string) $node['id'] );
                if ( preg_match( '/^\\d+$/', $candidate ) ) {
                    $album_node_id = $candidate;
                }
            }
            return array(
                'photos'         => array(),
                'cursor'         => '',
                'album_id'       => $album_node_id,
                'declared_total' => max( 0, (int) $node['count'] ),
            );
        }
        $attachment = ( isset( $node['attachments']['data'][0] ) && is_array( $node['attachments']['data'][0] ) ? $node['attachments']['data'][0] : null );
        $subs = array();
        if ( null !== $attachment && isset( $attachment['subattachments']['data'] ) && is_array( $attachment['subattachments']['data'] ) ) {
            $subs = $attachment['subattachments']['data'];
        }
        foreach ( $subs as $sub ) {
            if ( !is_array( $sub ) ) {
                continue;
            }
            $tuple = PostMapper::media_tuple_from_subattachment( $sub );
            if ( null !== $tuple ) {
                $photos[] = $tuple;
            }
        }
        if ( null !== $attachment && isset( $attachment['subattachments']['paging']['next'] ) && is_string( $attachment['subattachments']['paging']['next'] ) ) {
            $candidate = trim( (string) $attachment['subattachments']['paging']['next'] );
            if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
                $cursor = $candidate;
            }
        }
        if ( null !== $attachment && isset( $attachment['type'] ) && 'album' === $attachment['type'] && isset( $attachment['target']['id'] ) && is_string( $attachment['target']['id'] ) ) {
            $candidate_id = trim( (string) $attachment['target']['id'] );
            if ( preg_match( '/^\\d+$/', $candidate_id ) ) {
                $album_id = $candidate_id;
            }
        }
        $declared_total = ( class_exists( '\\EasySocialFeed\\Facebook\\Layouts\\PostMapper' ) ? PostMapper::resolve_album_total_count( $node, $attachment ) : 0 );
        return array(
            'photos'         => $photos,
            'cursor'         => $cursor,
            'album_id'       => $album_id,
            'declared_total' => $declared_total,
        );
    }

    /**
     * Locate a post node inside the feed's cached Graph payload.
     *
     * @since 6.7.9
     *
     * @param int    $feed_id Feed ID.
     * @param string $post_id Sanitized post ID.
     * @return array<string,mixed>|null
     */
    public static function find_post_node_in_feed_cache( $feed_id, $post_id ) {
        $feed_id = (int) $feed_id;
        $post_id = (string) $post_id;
        if ( $feed_id <= 0 || '' === $post_id ) {
            return null;
        }
        if ( !class_exists( 'ESF_Facebook_Local_Media' ) ) {
            return null;
        }
        $nodes = ESF_Facebook_Local_Media::get_feed_media_nodes( $feed_id );
        foreach ( $nodes as $node ) {
            if ( !is_array( $node ) ) {
                continue;
            }
            $id = ( isset( $node['id'] ) ? (string) $node['id'] : '' );
            if ( $id === $post_id ) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Map a Graph subattachments page response into media tuples + next cursor.
     *
     * Graph may return either `{ data: [...], paging }` (edge) or
     * `{ attachments: { data: [ { subattachments: { data, paging } } ] } }`
     * when following a nested field paging URL.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed> $response Graph JSON body.
     * @return array{photos:array<int,array<string,mixed>>,cursor:string}
     */
    public static function map_graph_page( array $response ) {
        $photos = array();
        $cursor = '';
        $rows = array();
        if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $rows = $response['data'];
            if ( isset( $response['paging']['next'] ) && is_string( $response['paging']['next'] ) ) {
                $candidate = trim( (string) $response['paging']['next'] );
                if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
                    $cursor = $candidate;
                }
            }
        } elseif ( isset( $response['attachments']['data'][0]['subattachments'] ) && is_array( $response['attachments']['data'][0]['subattachments'] ) ) {
            $sub = $response['attachments']['data'][0]['subattachments'];
            $rows = ( isset( $sub['data'] ) && is_array( $sub['data'] ) ? $sub['data'] : array() );
            if ( isset( $sub['paging']['next'] ) && is_string( $sub['paging']['next'] ) ) {
                $candidate = trim( (string) $sub['paging']['next'] );
                if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
                    $cursor = $candidate;
                }
            }
        }
        foreach ( $rows as $row ) {
            if ( !is_array( $row ) ) {
                continue;
            }
            $tuple = PostMapper::media_tuple_from_subattachment( $row );
            if ( null !== $tuple ) {
                $photos[] = $tuple;
            }
        }
        return array(
            'photos' => $photos,
            'cursor' => $cursor,
        );
    }

    /**
     * Persist the album photos cache bucket.
     *
     * @since 6.7.9
     *
     * @param string                         $cache_key         Cache key.
     * @param array<int,array<string,mixed>> $photos            Flat photo list (media tuples).
     * @param string                         $cursor            Graph paging URL for the (often absent) subattachments tail.
     * @param int                            $feed_id           Feed ID.
     * @param int                            $page_row_id       Internal page row ID.
     * @param string                         $album_id          Facebook album id, when known.
     * @param string                         $photos_after      Cursor for the `/{album-id}/photos` edge fallback ('DONE' = exhausted).
     * @param int                            $total_count       Album edge's own `summary=true` total (not necessarily post-specific).
     * @param bool                           $direct_fetch_done Whether the one-time higher-limit per-post refetch already ran.
     * @param int                            $declared_total    Authoritative post-specific total (`target.count` / story), when known.
     * @return void
     */
    public static function persist_album_cache(
        $cache_key,
        array $photos,
        $cursor,
        $feed_id,
        $page_row_id,
        $album_id = '',
        $photos_after = '',
        $total_count = 0,
        $direct_fetch_done = false,
        $declared_total = 0
    ) {
        if ( !class_exists( 'ESF_Facebook_Cache' ) ) {
            return;
        }
        $ttl = ( function_exists( 'esf_facebook_get_cache_ttl_secs' ) ? esf_facebook_get_cache_ttl_secs() : ESF_Facebook_Cache::DEFAULT_TTL );
        ESF_Facebook_Cache::set(
            $cache_key,
            array(
                'photos'            => array_values( $photos ),
                'cursor'            => sanitize_text_field( (string) $cursor ),
                'album_id'          => sanitize_text_field( (string) $album_id ),
                'photos_after'      => sanitize_text_field( (string) $photos_after ),
                'total_count'       => max( 0, (int) $total_count ),
                'direct_fetch_done' => (bool) $direct_fetch_done,
                'declared_total'    => max( 0, (int) $declared_total ),
            ),
            $ttl,
            'api',
            (int) $page_row_id,
            (int) $feed_id
        );
    }

}
