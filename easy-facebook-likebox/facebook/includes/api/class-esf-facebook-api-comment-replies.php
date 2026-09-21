<?php

/**
 * Facebook Comment Replies REST API.
 *
 * Returns paginated replies for a single comment using Graph cursor pagination
 * (JS sends `cursor`, not offset). Optionally caches under the parent post
 * comments bucket when `post_id` is provided.
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
 * Class ESF_Facebook_API_Comment_Replies
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Comment_Replies {
    /**
     * Maximum reply nodes kept per comment thread in cache.
     *
     * @since 6.7.9
     * @var int
     */
    const MAX_LOCAL_CACHE_REPLIES = 100;

    /**
     * Normalize a single comment's reply cache bucket.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed>|null $bucket Raw reply bucket.
     * @return array{items:array<int,array<string,mixed>>,cursor:string}
     */
    public static function normalize_reply_bucket( $bucket ) {
        $items = array();
        $cursor = '';
        if ( is_array( $bucket ) ) {
            if ( isset( $bucket['items'] ) && is_array( $bucket['items'] ) ) {
                $items = array_values( $bucket['items'] );
            }
            if ( isset( $bucket['cursor'] ) ) {
                $cursor = sanitize_text_field( (string) $bucket['cursor'] );
            }
        }
        return array(
            'items'  => $items,
            'cursor' => $cursor,
        );
    }

    /**
     * Format the REST response payload expected by the lightbox JS.
     *
     * @since 6.7.9
     *
     * @param array<int,array<string,mixed>> $replies     Reply rows for this page.
     * @param bool                           $has_more    Whether more replies exist.
     * @param string                         $next_cursor Graph after cursor for the next page.
     * @return array<string,mixed>
     */
    public static function format_response( array $replies, $has_more, $next_cursor ) {
        return array(
            'replies'     => $replies,
            'has_more'    => (bool) $has_more,
            'next_cursor' => sanitize_text_field( (string) $next_cursor ),
        );
    }

    /**
     * Map raw Graph reply nodes to normalized reply rows.
     *
     * @since 6.7.9
     *
     * @param array<int,array<string,mixed>> $nodes Raw Graph nodes.
     * @return array<int,array<string,mixed>>
     */
    private static function map_reply_nodes( array $nodes ) {
        $replies = array();
        foreach ( $nodes as $node ) {
            if ( !is_array( $node ) ) {
                continue;
            }
            $mapped = CommentMapper::from_graph_node( $node );
            if ( null === $mapped ) {
                continue;
            }
            $mapped['replies'] = array();
            $mapped['reply_count'] = 0;
            $mapped['replies_has_more'] = false;
            $mapped['replies_next_cursor'] = '';
            $replies[] = $mapped;
        }
        return $replies;
    }

    /**
     * Resolve replies-per-page for a feed request.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed> $feed_cfg Feed settings slice.
     * @param WP_REST_Request     $request  REST request.
     * @return int
     */
    private static function resolve_replies_per_page( array $feed_cfg, $request ) {
        $per_page = ( isset( $feed_cfg['popup_comments_per_page'] ) ? max( 1, min( 50, (int) $feed_cfg['popup_comments_per_page'] ) ) : 10 );
        $is_preview = method_exists( $request, 'get_param' ) && in_array( (string) $request->get_param( 'preview' ), array('1', 'true'), true );
        if ( method_exists( $request, 'has_param' ) && $request->has_param( 'per_page' ) && (( $is_preview ? current_user_can( 'edit_posts' ) : true )) ) {
            $requested = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
            if ( $requested > 0 ) {
                $per_page = $requested;
            }
        }
        return $per_page;
    }

}
