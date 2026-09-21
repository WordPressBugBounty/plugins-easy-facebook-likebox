<?php

/**
 * Pro event enrichment for feed posts with restricted attachment embeds.
 *
 * When Graph returns "This content is currently unavailable." for a shared
 * event, attempt a direct GET /{event-id} fetch and merge real metadata
 * into the cached feed node before PostMapper runs.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Best-effort enrichment for restricted shared-event attachments.
 *
 * @since 6.7.9
 */
class ESF_Facebook_Embed_Enricher {
    /**
     * Max event fetches per feed render (avoid slow pages).
     *
     * @var int
     */
    const MAX_FETCHES_PER_REQUEST = 5;

    /**
     * Request-scoped event fetch counter.
     *
     * @var int
     */
    private static $fetch_count = 0;

    /**
     * Request-scoped cache of fetched event nodes keyed by event id.
     *
     * @var array<string,array<string,mixed>|null>
     */
    private static $event_cache = array();

    /**
     * Enrich feed nodes that need event metadata before PostMapper.
     *
     * @since 6.7.9
     *
     * @param array<int,array<string,mixed>> $nodes     Graph post nodes.
     * @param object|null                    $page_row  Page DB row.
     * @param string                         $feed_type Canonical feed type.
     * @return array<int,array<string,mixed>>
     */
    public static function enrich_nodes( array $nodes, $page_row, string $feed_type = 'timeline' ) : array {
        if ( !self::can_enrich() || !is_object( $page_row ) ) {
            return $nodes;
        }
        $token = ( function_exists( 'esf_facebook_resolve_content_graph_token' ) ? esf_facebook_resolve_content_graph_token( $page_row, $feed_type ) : '' );
        if ( '' === trim( $token ) ) {
            return $nodes;
        }
        foreach ( $nodes as $index => $node ) {
            if ( !is_array( $node ) ) {
                continue;
            }
            if ( !class_exists( '\\EasySocialFeed\\Facebook\\Layouts\\FacebookEmbedSupport' ) ) {
                break;
            }
            if ( !\EasySocialFeed\Facebook\Layouts\FacebookEmbedSupport::node_needs_event_enrichment( $node ) ) {
                continue;
            }
            if ( self::$fetch_count >= self::MAX_FETCHES_PER_REQUEST ) {
                break;
            }
            if ( !method_exists( __CLASS__, 'enrich_node__premium_only' ) ) {
                break;
            }
            $nodes[$index] = self::enrich_node__premium_only( $node, $token );
        }
        return $nodes;
    }

    /**
     * Whether Pro event enrichment is available in this build.
     *
     * @since 6.7.9
     * @return bool
     */
    private static function can_enrich() : bool {
        if ( !function_exists( 'efl_fs' ) || !efl_fs()->can_use_premium_code__premium_only() ) {
            return false;
        }
        if ( !function_exists( 'esf_facebook_has_facebook_plan' ) || !esf_facebook_has_facebook_plan() ) {
            return false;
        }
        if ( !class_exists( 'ESF_Facebook_Graph_Client' ) ) {
            return false;
        }
        return true;
    }

    /**
     * Fetch one Event node (request-scoped cache + transient).
     *
     * @since 6.7.9
     *
     * @param string $event_id Numeric event id.
     * @param string $token    Page access token.
     * @return array<string,mixed>|null
     */
    private static function fetch_event_node( string $event_id, string $token ) : ?array {
        $event_id = preg_replace( '/\\D/', '', $event_id );
        if ( '' === $event_id ) {
            return null;
        }
        if ( array_key_exists( $event_id, self::$event_cache ) ) {
            return self::$event_cache[$event_id];
        }
        $transient_key = 'esf_fb_event_' . $event_id;
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            if ( !empty( $cached['failed'] ) ) {
                self::$event_cache[$event_id] = null;
                return null;
            }
            if ( !empty( $cached['id'] ) ) {
                self::$event_cache[$event_id] = $cached;
                return $cached;
            }
        }
        if ( self::$fetch_count >= self::MAX_FETCHES_PER_REQUEST ) {
            self::$event_cache[$event_id] = null;
            return null;
        }
        ++self::$fetch_count;
        $body = ESF_Facebook_Graph_Client::fetch_event_node__premium_only( $event_id, $token );
        if ( is_wp_error( $body ) || !is_array( $body ) || empty( $body['id'] ) ) {
            self::$event_cache[$event_id] = null;
            set_transient( $transient_key, array(
                'failed' => true,
            ), HOUR_IN_SECONDS );
            return null;
        }
        set_transient( $transient_key, $body, DAY_IN_SECONDS );
        self::$event_cache[$event_id] = $body;
        return $body;
    }

}
