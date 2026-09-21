<?php

/**
 * Facebook Post Comments REST API.
 *
 * Returns paginated comments for a single Page post, with DB caching tied to
 * the module cache duration from Facebook settings. Uses the same flat append +
 * offset slice model for Instagram post comments.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
use EasySocialFeed\Facebook\Layouts\CommentMapper;
/**
 * Class ESF_Facebook_API_Post_Comments
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Post_Comments {
    use Trait_ESF_Facebook_Popup_Api_Support;
    /**
     * Maximum comment nodes kept in the local post comments cache.
     *
     * @since 6.7.9
     * @var int
     */
    const MAX_LOCAL_CACHE_COMMENTS = 200;

    /**
     * Maximum Graph round-trips per request when filling a page window.
     *
     * @since 6.7.9
     * @var int
     */
    const MAX_FETCH_ROUNDS = 5;

    /**
     * Build a cache key for a post's comments bucket.
     *
     * @since 6.7.9
     *
     * @param string $post_id Sanitized post ID.
     * @return string
     */
    public static function comments_cache_key( $post_id ) {
        $post_id = preg_replace( '/[^0-9_]/', '', (string) $post_id );
        $post_id = substr( (string) $post_id, 0, 200 );
        return 'esf_fb_comments_' . $post_id;
    }

    /**
     * Merge mapped comment rows by ID without duplicates.
     *
     * @since 6.7.9
     *
     * @param array<int,array<string,mixed>> $existing Existing mapped comments.
     * @param array<int,array<string,mixed>> $incoming New mapped comments.
     * @return array<int,array<string,mixed>>
     */
    public static function merge_comments_by_id( array $existing, array $incoming ) {
        $merged = $existing;
        $seen = array();
        foreach ( $merged as $comment ) {
            if ( !is_array( $comment ) ) {
                continue;
            }
            $id = ( isset( $comment['id'] ) ? (string) $comment['id'] : '' );
            if ( '' !== $id ) {
                $seen[$id] = true;
            }
        }
        foreach ( $incoming as $comment ) {
            if ( !is_array( $comment ) ) {
                continue;
            }
            $id = ( isset( $comment['id'] ) ? (string) $comment['id'] : '' );
            if ( '' === $id || isset( $seen[$id] ) ) {
                continue;
            }
            $merged[] = $comment;
            $seen[$id] = true;
        }
        if ( count( $merged ) > self::MAX_LOCAL_CACHE_COMMENTS ) {
            $merged = array_slice( $merged, 0, self::MAX_LOCAL_CACHE_COMMENTS );
        }
        return array_values( $merged );
    }

    /**
     * Normalize a post comments cache bucket.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed>|null $cached Raw cache payload.
     * @return array{comments:array<int,array<string,mixed>>,cursor:string,replies:array<string,array<string,mixed>>}
     */
    public static function normalize_comments_cache( $cached ) {
        $comments = array();
        $cursor = '';
        $replies = array();
        if ( is_array( $cached ) ) {
            if ( isset( $cached['comments'] ) && is_array( $cached['comments'] ) ) {
                $comments = array_values( $cached['comments'] );
            }
            if ( isset( $cached['cursor'] ) ) {
                $cursor = sanitize_text_field( (string) $cached['cursor'] );
            }
            if ( isset( $cached['replies'] ) && is_array( $cached['replies'] ) ) {
                $replies = $cached['replies'];
            }
        }
        return array(
            'comments' => $comments,
            'cursor'   => $cursor,
            'replies'  => $replies,
        );
    }

    /**
     * Load a post comments cache bucket, optionally including expired rows.
     *
     * @since 6.7.9
     *
     * @param string $post_id      Sanitized post ID.
     * @param bool   $allow_stale  When true, fall back to expired cache rows.
     * @return array{comments:array<int,array<string,mixed>>,cursor:string,replies:array<string,array<string,mixed>>}
     */
    public static function load_comments_cache_bucket( $post_id, $allow_stale = false ) {
        if ( !class_exists( 'ESF_Facebook_Cache' ) ) {
            return self::normalize_comments_cache( null );
        }
        $cache_key = self::comments_cache_key( $post_id );
        $cached = ESF_Facebook_Cache::get( $cache_key );
        if ( !is_array( $cached ) && $allow_stale ) {
            $cached = ESF_Facebook_Cache::get_stale( $cache_key );
        }
        return self::normalize_comments_cache( ( is_array( $cached ) ? $cached : null ) );
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
     * @param string $api_cursor Graph tail cursor, if any.
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
     * Whether popup comment list is disabled for the feed settings slice.
     *
     * Accepts both `popup_show_comments_list` and legacy `popup_show_comments`.
     * Disabled when either is explicitly set false.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed> $feed_cfg Feed settings slice.
     * @return bool
     */
    public static function is_comments_list_disabled( array $feed_cfg ) {
        if ( isset( $feed_cfg['popup_show_comments_list'] ) && empty( $feed_cfg['popup_show_comments_list'] ) ) {
            return true;
        }
        if ( isset( $feed_cfg['popup_show_comments'] ) && empty( $feed_cfg['popup_show_comments'] ) ) {
            return true;
        }
        return false;
    }

    /**
     * Format the REST response payload.
     *
     * @since 6.7.9
     *
     * @param array<int,array<string,mixed>> $comments    Comment rows for this page.
     * @param bool                           $has_more    Whether more comments exist.
     * @param int                            $next_offset Next client offset when has_more is true.
     * @return array<string,mixed>
     */
    public static function format_response( array $comments, $has_more, $next_offset ) {
        return array(
            'comments'      => $comments,
            'has_more'      => (bool) $has_more,
            'next_offset'   => max( 0, (int) $next_offset ),
            'total_fetched' => count( $comments ),
        );
    }

    /**
     * Resolve comments-per-page for a feed request.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed> $feed_cfg Feed settings slice.
     * @param WP_REST_Request     $request  REST request.
     * @return int
     */
    private static function resolve_comments_per_page( array $feed_cfg, $request ) {
        $per_page = ( isset( $feed_cfg['popup_comments_per_page'] ) ? max( 1, min( 50, (int) $feed_cfg['popup_comments_per_page'] ) ) : 20 );
        $is_preview = method_exists( $request, 'get_param' ) && in_array( (string) $request->get_param( 'preview' ), array('1', 'true'), true );
        if ( $is_preview && current_user_can( 'edit_posts' ) && method_exists( $request, 'has_param' ) && $request->has_param( 'per_page' ) ) {
            $preview_per_page = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
            if ( $preview_per_page > 0 ) {
                $per_page = $preview_per_page;
            }
        }
        return $per_page;
    }

    /**
     * Resolve integrator override from esf_facebook_post_comments_payload.
     *
     * @since 6.7.9
     *
     * @param mixed $payload Filter return value.
     * @return array{rest_response:array<string,mixed>|null,bucket:array{comments:array<int,array<string,mixed>>,cursor:string,replies:array<string,array<string,mixed>>},short_circuited:bool}
     */
    public static function resolve_comments_payload_override( $payload ) {
        $result = array(
            'rest_response'   => null,
            'bucket'          => self::normalize_comments_cache( null ),
            'short_circuited' => false,
        );
        if ( !is_array( $payload ) ) {
            return $result;
        }
        if ( array_key_exists( 'comments', $payload ) && array_key_exists( 'has_more', $payload ) ) {
            $comments = ( isset( $payload['comments'] ) && is_array( $payload['comments'] ) ? $payload['comments'] : array() );
            $result['rest_response'] = self::format_response( $comments, !empty( $payload['has_more'] ), ( isset( $payload['next_offset'] ) ? (int) $payload['next_offset'] : count( $comments ) ) );
            $result['short_circuited'] = true;
            return $result;
        }
        $bucket = self::normalize_comments_cache( $payload );
        $explicit = !empty( $payload['has_local_cache'] );
        $has_bucket_data = !empty( $bucket['comments'] ) || !empty( $bucket['replies'] );
        if ( $explicit || $has_bucket_data ) {
            $result['bucket'] = $bucket;
            $result['short_circuited'] = true;
        }
        return $result;
    }

    /**
     * Persist the post comments cache bucket.
     *
     * @since 6.7.9
     *
     * @param string                            $cache_key   Cache key.
     * @param array<int,array<string,mixed>>    $comments    Flat comment list.
     * @param string                            $cursor      Graph API cursor for the tail.
     * @param array<string,array<string,mixed>> $replies     Nested reply buckets.
     * @param int                               $feed_id     Feed ID.
     * @param int                               $page_row_id Internal page row ID.
     * @return void
     */
    public static function persist_comments_cache(
        $cache_key,
        array $comments,
        $cursor,
        array $replies,
        $feed_id,
        $page_row_id
    ) {
        if ( !class_exists( 'ESF_Facebook_Cache' ) ) {
            return;
        }
        $ttl = ( function_exists( 'esf_facebook_get_cache_ttl_secs' ) ? esf_facebook_get_cache_ttl_secs() : ESF_Facebook_Cache::DEFAULT_TTL );
        ESF_Facebook_Cache::set(
            $cache_key,
            array(
                'comments' => array_values( $comments ),
                'cursor'   => sanitize_text_field( (string) $cursor ),
                'replies'  => $replies,
            ),
            $ttl,
            'api',
            (int) $page_row_id,
            (int) $feed_id
        );
    }

}
