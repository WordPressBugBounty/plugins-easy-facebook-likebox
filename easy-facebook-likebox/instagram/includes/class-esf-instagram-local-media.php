<?php

/**
 * Instagram local media helper.
 *
 * Mirrors Twitter's ensure_local_media_for_tweets() by persisting Graph media
 * and profile images under uploads/esf-instagram. Pro plans also generate grid
 * thumbnails via the shared esf_serve_media_locally_sized() helpers.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.9.0
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Instagram_Local_Media
 *
 * @since 6.9.0
 */
class ESF_Instagram_Local_Media {
    /**
     * Default max width for grid tile JPEG variants (Pro).
     *
     * @since 6.9.0
     * @var int
     */
    const DEFAULT_GRID_THUMB_WIDTH = 600;

    /**
     * Default max width for localized account avatar thumbnails.
     *
     * @since 6.9.0
     * @var int
     */
    const DEFAULT_AVATAR_THUMB_WIDTH = 96;

    /**
     * Module slug passed to core media helpers.
     *
     * @since 6.9.0
     * @var string
     */
    const MODULE = 'instagram';

    /**
     * WP-Cron hook for batched background media downloads.
     *
     * @since 6.9.0
     * @var string
     */
    const WARM_CRON_HOOK = 'esf_instagram_warm_local_media';

    /**
     * Default number of media nodes processed per warm batch.
     *
     * @since 6.9.0
     * @var int
     */
    const DEFAULT_WARM_BATCH_SIZE = 8;

    /**
     * Whether Pro grid image variants should be generated for this request.
     *
     * Free Freemius builds strip `__premium_only` methods, so callers must go through
     * a `can_use_premium_code__premium_only()` gate rather than invoking those methods directly.
     *
     * @since 6.9.4
     * @return bool
     */
    private static function should_use_grid_variants() : bool {
        $use_variants = false;
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
                $use_variants = self::should_generate_grid_variants__premium_only();
            }
        }
        return $use_variants;
    }

    /**
     * Max width (px) for grid tile variants.
     *
     * @since 6.9.0
     * @return int
     */
    public static function get_grid_thumb_width() : int {
        $width = (int) apply_filters( 'esf_instagram_local_media_grid_width', self::DEFAULT_GRID_THUMB_WIDTH );
        return max( 120, min( 1200, $width ) );
    }

    /**
     * Target max width for localized avatar thumbnails (admin lists, feed headers).
     *
     * @since 6.9.0
     * @return int
     */
    public static function get_avatar_thumb_width() : int {
        $width = (int) apply_filters( 'esf_instagram_avatar_thumb_width', self::DEFAULT_AVATAR_THUMB_WIDTH );
        return max( 48, min( 512, $width ) );
    }

    /**
     * Ensure Graph media nodes use first-party image URLs when possible.
     *
     * Sets media_url to the full local file and thumbnail_url to a smaller
     * variant when Pro variants are enabled (grid tiles use thumbnail_url).
     *
     * @since 6.9.0
     *
     * @param array<int,array<string,mixed>> $nodes      Raw Graph media nodes.
     * @param int                            $account_id Internal account id (DB primary key).
     * @param array<string,mixed>            $args       Optional: limit (int), offset (int), skip_local (bool), hashtag (string).
     * @return array<int,array<string,mixed>>
     */
    public static function ensure_local_media_for_nodes( array $nodes, int $account_id = 0, array $args = array() ) : array {
        if ( empty( $nodes ) || !function_exists( 'esf_serve_media_locally' ) ) {
            return $nodes;
        }
        $account_id = ( function_exists( 'esf_normalize_local_media_account_id' ) ? esf_normalize_local_media_account_id( $account_id ) : max( 0, (int) $account_id ) );
        $limit = ( isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0 );
        $offset = ( isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0 );
        $skip_local = !empty( $args['skip_local'] );
        $hashtag_folder = ( isset( $args['hashtag_folder'] ) ? sanitize_key( (string) $args['hashtag_folder'] ) : '' );
        $use_variants = self::should_use_grid_variants();
        $thumb_width = ( $use_variants ? self::get_grid_thumb_width() : 0 );
        $processed = 0;
        foreach ( $nodes as $index => $node ) {
            if ( !is_array( $node ) ) {
                continue;
            }
            if ( $index < $offset ) {
                continue;
            }
            if ( $limit > 0 && $processed >= $limit ) {
                break;
            }
            if ( $skip_local && self::node_is_fully_local(
                $node,
                $account_id,
                $use_variants,
                $thumb_width,
                $hashtag_folder
            ) ) {
                ++$processed;
                continue;
            }
            $nodes[$index] = self::localize_node(
                $node,
                $use_variants,
                $thumb_width,
                $account_id,
                $hashtag_folder
            );
            ++$processed;
        }
        return $nodes;
    }

    /**
     * Swap display URLs to existing local files without downloading (read-only).
     *
     * @since 6.9.0
     *
     * @param array<int,array<string,mixed>> $nodes          Graph nodes.
     * @param int                            $account_id     Account id.
     * @param string                         $hashtag_folder Optional hashtag folder name (tag_type), e.g., "cats_top".
     * @return array<int,array<string,mixed>>
     */
    public static function resolve_nodes_display_urls( array $nodes, int $account_id = 0, string $hashtag_folder = '' ) : array {
        if ( empty( $nodes ) ) {
            return $nodes;
        }
        $account_id = ( function_exists( 'esf_normalize_local_media_account_id' ) ? esf_normalize_local_media_account_id( $account_id ) : max( 0, (int) $account_id ) );
        $hashtag_folder = sanitize_key( (string) $hashtag_folder );
        foreach ( $nodes as $index => $node ) {
            if ( !is_array( $node ) ) {
                continue;
            }
            $nodes[$index] = self::resolve_node_display_urls( $node, $account_id, $hashtag_folder );
        }
        return $nodes;
    }

    /**
     * Prepare nodes for a render context (preview vs frontend).
     *
     * Preview localizes at most per_page items synchronously; everything else
     * can be warmed via {@see schedule_warm_for_feed()}.
     *
     * @since 6.9.0
     *
     * @param array<int,array<string,mixed>> $nodes        Graph nodes.
     * @param int                            $account_id   Account id.
     * @param string                         $context      "preview" or "frontend".
     * @param int                            $per_page     Feed per_page setting.
     * @param string                         $hashtag_folder Hashtag folder name (tag_type), e.g., "cats_top".
     * @return array<int,array<string,mixed>>
     */
    public static function prepare_nodes_for_display(
        array $nodes,
        int $account_id,
        string $context,
        int $per_page,
        string $hashtag_folder = ''
    ) : array {
        $nodes = self::resolve_nodes_display_urls( $nodes, $account_id, $hashtag_folder );
        if ( in_array( $context, array('preview', 'frontend'), true ) ) {
            $limit = max( 1, min( 50, $per_page ) );
            $nodes = self::ensure_local_media_for_nodes( $nodes, $account_id, array(
                'limit'          => $limit,
                'skip_local'     => true,
                'hashtag_folder' => $hashtag_folder,
            ) );
        }
        return $nodes;
    }

    /**
     * Count nodes that still need a local download for an account (or hashtag folder).
     *
     * @since 6.9.0
     *
     * @param array<int,array<string,mixed>> $nodes          Graph nodes.
     * @param int                            $account_id     Account id.
     * @param string                         $hashtag_folder Optional hashtag folder (tag_type), e.g. "cats_top".
     * @return int
     */
    public static function count_nodes_needing_local( array $nodes, int $account_id = 0, string $hashtag_folder = '' ) : int {
        $account_id = ( function_exists( 'esf_normalize_local_media_account_id' ) ? esf_normalize_local_media_account_id( $account_id ) : max( 0, (int) $account_id ) );
        $hashtag_folder = sanitize_key( (string) $hashtag_folder );
        $use_variants = self::should_use_grid_variants();
        $thumb_width = ( $use_variants ? self::get_grid_thumb_width() : 0 );
        $pending = 0;
        foreach ( $nodes as $node ) {
            if ( !is_array( $node ) ) {
                continue;
            }
            if ( !self::node_is_fully_local(
                $node,
                $account_id,
                $use_variants,
                $thumb_width,
                $hashtag_folder
            ) ) {
                ++$pending;
            }
        }
        return $pending;
    }

    /**
     * Schedule batched background downloads for all cached media on a feed.
     *
     * @since 6.9.0
     *
     * @param int $feed_id    Feed id.
     * @param int $account_id Account id.
     * @return array{scheduled:bool,total:int,pending:int,offset:int}
     */
    public static function schedule_warm_for_feed( int $feed_id, int $account_id ) : array {
        $feed_id = max( 0, (int) $feed_id );
        $account_id = ( function_exists( 'esf_normalize_local_media_account_id' ) ? esf_normalize_local_media_account_id( $account_id ) : max( 0, (int) $account_id ) );
        $result = array(
            'scheduled' => false,
            'total'     => 0,
            'pending'   => 0,
            'offset'    => 0,
        );
        if ( $feed_id <= 0 || $account_id <= 0 || !function_exists( 'esf_serve_media_locally' ) ) {
            return $result;
        }
        $context = self::resolve_feed_warm_context( $feed_id );
        $hashtag_folder = $context['hashtag_folder'];
        $nodes = self::get_feed_media_nodes( $feed_id, $account_id, $context['hashtag_cache_key'] );
        $total = count( $nodes );
        $pending = self::count_nodes_needing_local( $nodes, $account_id, $hashtag_folder );
        $result['total'] = $total;
        $result['pending'] = $pending;
        if ( $pending <= 0 ) {
            self::clear_warm_state( $feed_id, $account_id );
            return $result;
        }
        self::set_warm_state( $feed_id, $account_id, array(
            'offset'         => 0,
            'total'          => $total,
            'pending'        => $pending,
            'hashtag_folder' => $hashtag_folder,
        ) );
        $args = array($feed_id, $account_id);
        if ( !wp_next_scheduled( self::WARM_CRON_HOOK, $args ) ) {
            wp_schedule_single_event( time() + 1, self::WARM_CRON_HOOK, $args );
        }
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
        $result['scheduled'] = true;
        return $result;
    }

    /**
     * Cron callback: process the next warm batch for a feed/account.
     *
     * @since 6.9.0
     *
     * @param int $feed_id    Feed id.
     * @param int $account_id Account id.
     * @return void
     */
    public static function run_warm_cron( int $feed_id, int $account_id ) : void {
        $feed_id = max( 0, (int) $feed_id );
        $account_id = ( function_exists( 'esf_normalize_local_media_account_id' ) ? esf_normalize_local_media_account_id( $account_id ) : max( 0, (int) $account_id ) );
        if ( $feed_id <= 0 || $account_id <= 0 || !function_exists( 'esf_serve_media_locally' ) ) {
            return;
        }
        $batch_size = (int) apply_filters( 'esf_instagram_local_media_warm_batch_size', self::DEFAULT_WARM_BATCH_SIZE );
        $batch_size = max( 1, min( 25, $batch_size ) );
        // Resolve hashtag context; stored in state so we don't re-look up the feed on every batch.
        $state = self::get_warm_state( $feed_id, $account_id );
        $hashtag_folder = ( isset( $state['hashtag_folder'] ) ? sanitize_key( (string) $state['hashtag_folder'] ) : '' );
        // If no state yet (first run), derive context fresh.
        if ( empty( $state ) ) {
            $context = self::resolve_feed_warm_context( $feed_id );
            $hashtag_folder = $context['hashtag_folder'];
            $nodes = self::get_feed_media_nodes( $feed_id, $account_id, $context['hashtag_cache_key'] );
        } else {
            $context = self::resolve_feed_warm_context( $feed_id );
            $nodes = self::get_feed_media_nodes( $feed_id, $account_id, $context['hashtag_cache_key'] );
        }
        if ( empty( $nodes ) ) {
            self::clear_warm_state( $feed_id, $account_id );
            return;
        }
        $offset = ( isset( $state['offset'] ) ? max( 0, (int) $state['offset'] ) : 0 );
        $total = count( $nodes );
        if ( $offset >= $total ) {
            self::clear_warm_state( $feed_id, $account_id );
            return;
        }
        $slice = array_slice( $nodes, $offset, $batch_size );
        self::ensure_local_media_for_nodes( $slice, $account_id, array(
            'skip_local'     => true,
            'hashtag_folder' => $hashtag_folder,
        ) );
        $offset += count( $slice );
        $pending = self::count_nodes_needing_local( $nodes, $account_id, $hashtag_folder );
        if ( $offset < $total && $pending > 0 ) {
            self::set_warm_state( $feed_id, $account_id, array(
                'offset'         => $offset,
                'total'          => $total,
                'pending'        => $pending,
                'hashtag_folder' => $hashtag_folder,
            ) );
            $args = array($feed_id, $account_id);
            if ( !wp_next_scheduled( self::WARM_CRON_HOOK, $args ) ) {
                wp_schedule_single_event( time() + 2, self::WARM_CRON_HOOK, $args );
            }
            return;
        }
        self::clear_warm_state( $feed_id, $account_id );
    }

    /**
     * Load cached Graph nodes for a feed, fetching from the API when empty.
     *
     * For hashtag feeds, pass a non-empty $hashtag_cache_key so nodes are read
     * from the hashtag cache row instead of the account cache. The hashtag API
     * requires Facebook user auth and a known tag ID, so automatic re-fetching
     * on a cache miss is not performed here — the warm path only downloads
     * images for what is already in the cache.
     *
     * @since 6.9.0
     *
     * @param int    $feed_id          Feed id.
     * @param int    $account_id       Account id.
     * @param string $hashtag_cache_key Optional cache key for hashtag feeds (e.g. "esf_ig_hashtag_cats").
     * @return array<int,array<string,mixed>>
     */
    public static function get_feed_media_nodes( int $feed_id, int $account_id, string $hashtag_cache_key = '' ) : array {
        if ( !class_exists( 'ESF_Instagram_Cache' ) ) {
            return array();
        }
        // Hashtag feed: read from hashtag cache only — no auto-fetch.
        if ( '' !== $hashtag_cache_key ) {
            $cached = ESF_Instagram_Cache::get( $hashtag_cache_key );
            return ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ? array_values( $cached['data'] ) : array() );
        }
        // Account feed: try cache, then fall back to a live API fetch.
        if ( !class_exists( 'ESF_Instagram_Renderer' ) ) {
            return array();
        }
        $cache_key = ESF_Instagram_Renderer::media_cache_key( $account_id );
        $cached = ESF_Instagram_Cache::get( $cache_key );
        if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
            return array_values( $cached['data'] );
        }
        if ( !class_exists( 'ESF_Instagram_Feed_Repository' ) || !class_exists( 'ESF_Instagram_Account_Repository' ) ) {
            return array();
        }
        $feed_row = ESF_Instagram_Feed_Repository::get_instance()->get_by_id( $feed_id );
        if ( !$feed_row ) {
            return array();
        }
        $account_row = ESF_Instagram_Account_Repository::get_instance()->get_by_id( $account_id );
        if ( !$account_row || !class_exists( 'ESF_Instagram_API_Media' ) ) {
            return array();
        }
        $fetch_limit = min( ESF_Instagram_Renderer::MAX_LOCAL_CACHE_POSTS, ESF_Instagram_API_Media::MAX_LIMIT );
        $response = ESF_Instagram_API_Media::get_instance()->fetch_user_media( $account_row, $fetch_limit );
        if ( is_wp_error( $response ) ) {
            return array();
        }
        $nodes = ( isset( $response['data'] ) && is_array( $response['data'] ) ? array_values( $response['data'] ) : array() );
        if ( empty( $nodes ) ) {
            return array();
        }
        $ttl = esf_instagram_get_cache_ttl_secs( (object) array(
            'id' => $feed_id,
        ), $account_id );
        ESF_Instagram_Cache::set(
            $cache_key,
            array(
                'data'       => $nodes,
                'pagination' => ( isset( $response['pagination'] ) && is_array( $response['pagination'] ) ? $response['pagination'] : array() ),
            ),
            $ttl,
            'account',
            $account_id
        );
        return $nodes;
    }

    /**
     * Transient key for warm progress.
     *
     * @since 6.9.0
     *
     * @param int $feed_id    Feed id.
     * @param int $account_id Account id.
     * @return string
     */
    private static function warm_state_key( int $feed_id, int $account_id ) : string {
        return 'esf_ig_warm_' . $feed_id . '_' . $account_id;
    }

    /**
     * Resolve the hashtag warm context for a feed.
     *
     * Returns the hashtag folder name and cache key for hashtag feeds so the
     * warm cron can read from the correct cache row and download images into
     * the correct local folder. Returns empty strings for account feeds.
     *
     * @since 6.9.0
     *
     * @param int $feed_id Feed id.
     * @return array{hashtag_folder:string,hashtag_cache_key:string}
     */
    private static function resolve_feed_warm_context( int $feed_id ) : array {
        $empty = array(
            'hashtag_folder'    => '',
            'hashtag_cache_key' => '',
        );
        if ( !class_exists( 'ESF_Instagram_Feed_Repository' ) || !function_exists( 'esf_instagram_feed_is_hashtag' ) || !function_exists( 'esf_instagram_normalize_hashtag' ) ) {
            return $empty;
        }
        $feed_obj = ESF_Instagram_Feed_Repository::get_instance()->get_by_id( $feed_id );
        if ( !$feed_obj || !esf_instagram_feed_is_hashtag( $feed_obj ) ) {
            return $empty;
        }
        $tag = esf_instagram_normalize_hashtag( (string) ($feed_obj->source_id ?? '') );
        if ( '' === $tag ) {
            return $empty;
        }
        $media_type = ( function_exists( 'esf_instagram_feed_hashtag_media_type' ) ? esf_instagram_feed_hashtag_media_type( $feed_obj ) : 'top_media' );
        $suffix = ( function_exists( 'esf_instagram_normalize_hashtag_media_type' ) && 'recent_media' === esf_instagram_normalize_hashtag_media_type( $media_type ) ? 'recent' : 'top' );
        $cache_key = ( function_exists( 'esf_instagram_hashtag_cache_key' ) ? esf_instagram_hashtag_cache_key( $tag, $media_type ) : '' );
        if ( '' === $cache_key ) {
            return $empty;
        }
        return array(
            'hashtag_folder'    => $tag . '_' . $suffix,
            'hashtag_cache_key' => $cache_key,
        );
    }

    /**
     * @since 6.9.0
     *
     * @param int $feed_id    Feed id.
     * @param int $account_id Account id.
     * @return array<string,mixed>
     */
    private static function get_warm_state( int $feed_id, int $account_id ) : array {
        $state = get_transient( self::warm_state_key( $feed_id, $account_id ) );
        return ( is_array( $state ) ? $state : array() );
    }

    /**
     * @since 6.9.0
     *
     * @param int                  $feed_id    Feed id.
     * @param int                  $account_id Account id.
     * @param array<string,mixed>  $state      Progress state.
     * @return void
     */
    private static function set_warm_state( int $feed_id, int $account_id, array $state ) : void {
        set_transient( self::warm_state_key( $feed_id, $account_id ), $state, HOUR_IN_SECONDS );
    }

    /**
     * @since 6.9.0
     *
     * @param int $feed_id    Feed id.
     * @param int $account_id Account id.
     * @return void
     */
    private static function clear_warm_state( int $feed_id, int $account_id ) : void {
        delete_transient( self::warm_state_key( $feed_id, $account_id ) );
    }

    /**
     * Clear warm-cache progress for one feed/account pair.
     *
     * @since 6.9.0
     *
     * @param int $feed_id    Feed id.
     * @param int $account_id Account id.
     * @return void
     */
    public static function clear_feed_warm_transient( int $feed_id, int $account_id ) : void {
        self::clear_warm_state( $feed_id, $account_id );
    }

    /**
     * Whether a node's image files are already stored locally.
     *
     * @since 6.9.0
     *
     * @param array<string,mixed> $node         Graph node.
     * @param int               $account_id   Account id.
     * @param bool              $use_variants Pro grid variants enabled.
     * @param int               $thumb_width  Variant width.
     * @param string            $hashtag_folder Optional normalized hashtag for hashtag feeds.
     * @return bool
     */
    private static function node_is_fully_local(
        array $node,
        int $account_id,
        bool $use_variants,
        int $thumb_width,
        string $hashtag_folder = ''
    ) : bool {
        $id = ( isset( $node['id'] ) ? (string) $node['id'] : '' );
        if ( '' === $id ) {
            return true;
        }
        $hashtag_folder = sanitize_key( (string) $hashtag_folder );
        $media_type = strtoupper( ( isset( $node['media_type'] ) ? (string) $node['media_type'] : 'IMAGE' ) );
        if ( in_array( $media_type, array('VIDEO', 'REELS', 'REEL'), true ) ) {
            $thumb_key = ( '' !== $hashtag_folder ? 'ht_' . $hashtag_folder . '_thumb_' . $id : 'ig_thumb_' . $id );
            $thumb_url = ( isset( $node['thumbnail_url'] ) ? (string) $node['thumbnail_url'] : '' );
            if ( '' === $thumb_url ) {
                return true;
            }
            if ( function_exists( 'esf_is_local_media_url' ) && esf_is_local_media_url( $thumb_url, self::MODULE ) ) {
                return true;
            }
            return '' !== esf_local_media_url(
                $thumb_key,
                self::MODULE,
                0,
                $account_id
            );
        }
        $media_key = ( '' !== $hashtag_folder ? 'ht_' . $hashtag_folder . '_' . $id : 'ig_media_' . $id );
        if ( '' === esf_local_media_url(
            $media_key,
            self::MODULE,
            0,
            $account_id
        ) ) {
            return false;
        }
        if ( $use_variants && $thumb_width > 0 ) {
            return '' !== esf_local_media_url(
                $media_key,
                self::MODULE,
                $thumb_width,
                $account_id
            );
        }
        return true;
    }

    /**
     * Resolve display URLs from disk without downloading.
     *
     * @since 6.9.0
     *
     * @param array<string,mixed> $node          Graph node.
     * @param int                 $account_id    Account id.
     * @param string              $hashtag_folder Optional hashtag folder name (tag_type), e.g., "cats_top".
     * @return array<string,mixed>
     */
    private static function resolve_node_display_urls( array $node, int $account_id, string $hashtag_folder = '' ) : array {
        if ( !function_exists( 'esf_local_media_url' ) ) {
            return $node;
        }
        $id = ( isset( $node['id'] ) ? (string) $node['id'] : '' );
        if ( '' === $id ) {
            return $node;
        }
        $hashtag_folder = sanitize_key( (string) $hashtag_folder );
        $use_variants = self::should_use_grid_variants();
        $thumb_width = ( $use_variants ? self::get_grid_thumb_width() : 0 );
        $media_type = strtoupper( ( isset( $node['media_type'] ) ? (string) $node['media_type'] : 'IMAGE' ) );
        if ( 'CAROUSEL_ALBUM' === $media_type && isset( $node['children']['data'] ) && is_array( $node['children']['data'] ) ) {
            foreach ( $node['children']['data'] as $child_index => $child ) {
                if ( !is_array( $child ) ) {
                    continue;
                }
                $node['children']['data'][$child_index] = self::resolve_node_display_urls( $child, $account_id, $hashtag_folder );
            }
            return self::resolve_album_cover_from_children( $node );
        }
        if ( in_array( $media_type, array('VIDEO', 'REELS', 'REEL'), true ) ) {
            if ( empty( $node['thumbnail_url'] ) ) {
                return $node;
            }
            $thumb_key = ( '' !== $hashtag_folder ? 'ht_' . $hashtag_folder . '_thumb_' . $id : 'ig_thumb_' . $id );
            $local_thumb = esf_local_media_url(
                $thumb_key,
                self::MODULE,
                0,
                $account_id
            );
            if ( '' !== $local_thumb ) {
                $node['thumbnail_url'] = $local_thumb;
            }
            return $node;
        }
        $media_key = ( '' !== $hashtag_folder ? 'ht_' . $hashtag_folder . '_' . $id : 'ig_media_' . $id );
        $full_local = esf_local_media_url(
            $media_key,
            self::MODULE,
            0,
            $account_id
        );
        if ( '' !== $full_local ) {
            $node['media_url'] = $full_local;
            if ( $use_variants && $thumb_width > 0 ) {
                $thumb_local = esf_local_media_url(
                    $media_key,
                    self::MODULE,
                    $thumb_width,
                    $account_id
                );
                if ( '' !== $thumb_local ) {
                    $node['thumbnail_url'] = $thumb_local;
                } else {
                    $node['thumbnail_url'] = $full_local;
                }
            } else {
                $node['thumbnail_url'] = $full_local;
            }
        }
        return $node;
    }

    /**
     * Resolve avatar cache metadata from an account row.
     *
     * @since 6.9.0
     *
     * @param object|array<string,mixed>|null $account_row Account row.
     * @return array{key:string,account_id:int,cdn_url:string}|null
     */
    private static function avatar_row_meta( $account_row ) : ?array {
        if ( null === $account_row ) {
            return null;
        }
        $data = ( is_object( $account_row ) ? get_object_vars( $account_row ) : $account_row );
        if ( !is_array( $data ) ) {
            return null;
        }
        $cdn_url = ( isset( $data['profile_image_url'] ) ? (string) $data['profile_image_url'] : '' );
        if ( '' === $cdn_url ) {
            return null;
        }
        $ig_id = ( isset( $data['instagram_user_id'] ) ? (string) $data['instagram_user_id'] : '' );
        if ( '' === $ig_id && isset( $data['id'] ) ) {
            $ig_id = 'acct_' . (int) $data['id'];
        }
        if ( '' === $ig_id ) {
            return null;
        }
        return array(
            'key'        => 'ig_avatar_' . $ig_id,
            'account_id' => ( isset( $data['id'] ) ? (int) $data['id'] : 0 ),
            'cdn_url'    => $cdn_url,
        );
    }

    /**
     * Resolve a profile image URL for read-only display (admin lists, REST).
     *
     * Returns a local thumbnail when available (most UI uses small avatars),
     * then the full local copy, otherwise the stored CDN URL without downloading.
     *
     * @since 6.9.0
     *
     * @param object|array<string,mixed>|null $account_row   Account row.
     * @param bool                            $prefer_thumb  When true, prefer the small variant.
     * @return string
     */
    public static function resolve_avatar_display_url( $account_row, bool $prefer_thumb = true ) : string {
        $meta = self::avatar_row_meta( $account_row );
        if ( null === $meta ) {
            return '';
        }
        if ( !function_exists( 'esf_local_media_url' ) ) {
            return $meta['cdn_url'];
        }
        if ( $prefer_thumb ) {
            $thumb = esf_local_media_url(
                $meta['key'],
                self::MODULE,
                self::get_avatar_thumb_width(),
                $meta['account_id']
            );
            if ( '' !== $thumb ) {
                return $thumb;
            }
        }
        $local = esf_local_media_url(
            $meta['key'],
            self::MODULE,
            0,
            $meta['account_id']
        );
        if ( '' !== $local ) {
            return $local;
        }
        return $meta['cdn_url'];
    }

    /**
     * Localize a profile image URL from an account DB row.
     *
     * Saves full-size and a small thumbnail in the account folder (not legacy root).
     *
     * @since 6.9.0
     *
     * @param object|array<string,mixed>|null $account_row Account row.
     * @return string Local URL when available, otherwise the original URL.
     */
    public static function localize_avatar_from_row( $account_row ) : string {
        $meta = self::avatar_row_meta( $account_row );
        if ( null === $meta ) {
            return '';
        }
        self::ensure_avatar_full_in_account_folder( $meta );
        self::ensure_avatar_thumb_variant( $meta['key'], $meta['account_id'] );
        return self::resolve_avatar_display_url( $account_row );
    }

    /**
     * Whether an avatar file exists in a specific account folder (not legacy root).
     *
     * @since 6.9.0
     *
     * @param string $key        Avatar cache key.
     * @param int    $account_id Internal account id.
     * @param int    $max_width  0 for full size, otherwise variant width.
     * @return bool
     */
    private static function avatar_file_exists_in_account_folder( string $key, int $account_id, int $max_width = 0 ) : bool {
        if ( !function_exists( 'esf_local_media_file_path' ) ) {
            return false;
        }
        $account_id = ( function_exists( 'esf_normalize_local_media_account_id' ) ? esf_normalize_local_media_account_id( $account_id ) : max( 0, (int) $account_id ) );
        if ( $account_id <= 0 ) {
            return false;
        }
        $file = esf_local_media_file_path(
            $key,
            self::MODULE,
            $max_width,
            $account_id
        );
        return '' !== $file && file_exists( $file );
    }

    /**
     * Copy a legacy root avatar into the account folder when present.
     *
     * @since 6.9.0
     *
     * @param array{key:string,account_id:int,cdn_url:string} $meta Avatar metadata.
     * @return bool True when the account folder now has the full-size file.
     */
    private static function promote_avatar_to_account_folder( array $meta ) : bool {
        if ( self::avatar_file_exists_in_account_folder( $meta['key'], $meta['account_id'], 0 ) ) {
            return true;
        }
        if ( !function_exists( 'esf_local_media_file_path' ) ) {
            return false;
        }
        $legacy = esf_local_media_file_path(
            $meta['key'],
            self::MODULE,
            0,
            0
        );
        if ( '' === $legacy || !file_exists( $legacy ) ) {
            return false;
        }
        $dest = esf_local_media_file_path(
            $meta['key'],
            self::MODULE,
            0,
            $meta['account_id']
        );
        if ( '' === $dest ) {
            return false;
        }
        $dest_dir = dirname( $dest );
        if ( !wp_mkdir_p( $dest_dir ) ) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Same uploads-dir pattern as core media helpers.
        if ( !@copy( $legacy, $dest ) ) {
            return false;
        }
        return file_exists( $dest );
    }

    /**
     * Ensure the full-size avatar exists in the account folder.
     *
     * @since 6.9.0
     *
     * @param array{key:string,account_id:int,cdn_url:string} $meta Avatar metadata.
     * @return void
     */
    private static function ensure_avatar_full_in_account_folder( array $meta ) : void {
        if ( self::avatar_file_exists_in_account_folder( $meta['key'], $meta['account_id'], 0 ) ) {
            return;
        }
        if ( self::promote_avatar_to_account_folder( $meta ) ) {
            return;
        }
        if ( !function_exists( 'esf_local_media_file_path' ) || !function_exists( 'esf_local_media_url' ) ) {
            return;
        }
        $account_id = ( function_exists( 'esf_normalize_local_media_account_id' ) ? esf_normalize_local_media_account_id( $meta['account_id'] ) : max( 0, (int) $meta['account_id'] ) );
        if ( $account_id <= 0 ) {
            return;
        }
        $file = esf_local_media_file_path(
            $meta['key'],
            self::MODULE,
            0,
            $account_id
        );
        if ( '' === $file ) {
            return;
        }
        $directory = dirname( $file );
        if ( !wp_mkdir_p( $directory ) ) {
            return;
        }
        $response = wp_remote_get( $meta['cdn_url'] );
        if ( is_wp_error( $response ) ) {
            return;
        }
        $image_data = wp_remote_retrieve_body( $response );
        if ( '' === $image_data ) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same pattern as esf_serve_media_locally.
        if ( false === file_put_contents( $file, $image_data ) ) {
            return;
        }
    }

    /**
     * Create (or return) the small avatar variant in the account folder.
     *
     * @since 6.9.0
     *
     * @param string $key        Avatar cache key.
     * @param int    $account_id Internal account id.
     * @return void
     */
    private static function ensure_avatar_thumb_variant( string $key, int $account_id ) : void {
        // This entire code block will be removed from the free version.
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
                if ( function_exists( 'esf_ensure_local_media_variant__premium_only' ) ) {
                    esf_ensure_local_media_variant__premium_only(
                        $key,
                        self::MODULE,
                        self::get_avatar_thumb_width(),
                        $account_id
                    );
                }
            }
        }
    }

    /**
     * Remove all locally cached media for an account.
     *
     * @since 6.9.0
     *
     * @param int $account_id Internal account id.
     * @return void
     */
    public static function purge_account_media( int $account_id ) : void {
        $account_id = ( function_exists( 'esf_normalize_local_media_account_id' ) ? esf_normalize_local_media_account_id( $account_id ) : max( 0, (int) $account_id ) );
        if ( $account_id <= 0 ) {
            return;
        }
        if ( function_exists( 'esf_delete_media_account_folder' ) ) {
            esf_delete_media_account_folder( self::MODULE, $account_id );
        }
    }

    /**
     * Remove all locally cached media for a hashtag + media_type combination.
     *
     * @since 6.9.0
     *
     * @param string $hashtag    Raw or normalized hashtag.
     * @param string $media_type Hashtag edge ('top_media'|'recent_media').
     * @return void
     */
    public static function purge_hashtag_media( string $hashtag, string $media_type = 'top_media' ) : void {
        if ( !function_exists( 'esf_instagram_normalize_hashtag' ) ) {
            return;
        }
        $normalized = esf_instagram_normalize_hashtag( $hashtag );
        if ( '' === $normalized ) {
            return;
        }
        $media_type_suffix = ( function_exists( 'esf_instagram_normalize_hashtag_media_type' ) && 'recent_media' === esf_instagram_normalize_hashtag_media_type( $media_type ) ? 'recent' : 'top' );
        $folder_name = $normalized . '_' . $media_type_suffix;
        if ( function_exists( 'esf_delete_media_hashtag_folder' ) ) {
            esf_delete_media_hashtag_folder( self::MODULE, $folder_name );
        }
    }

    /**
     * Rebuild an AccountSummary with a localized avatar when possible.
     *
     * @since 6.9.0
     *
     * @param \EasySocialFeed\Layouts\ValueObjects\AccountSummary|null $account     Account VO.
     * @param object|array<string,mixed>|null                          $account_row Optional DB row.
     * @return \EasySocialFeed\Layouts\ValueObjects\AccountSummary|null
     */
    public static function with_localized_avatar( ?\EasySocialFeed\Layouts\ValueObjects\AccountSummary $account, $account_row = null ) : ?\EasySocialFeed\Layouts\ValueObjects\AccountSummary {
        if ( null === $account ) {
            return null;
        }
        $localized = self::localize_avatar_from_row( $account_row );
        if ( '' === $localized ) {
            $localized = self::localize_avatar_from_row( $account->to_array() );
        }
        if ( '' === $localized || $localized === $account->get_avatar_url() ) {
            return $account;
        }
        $data = $account->to_array();
        $data['avatar_url'] = $localized;
        return new \EasySocialFeed\Layouts\ValueObjects\AccountSummary($data);
    }

    /**
     * Copy album cover URLs from the first resolved child (read-only).
     *
     * @since 6.9.0
     *
     * @param array<string,mixed> $node Album node.
     * @return array<string,mixed>
     */
    private static function resolve_album_cover_from_children( array $node ) : array {
        $first = ( isset( $node['children']['data'][0] ) && is_array( $node['children']['data'][0] ) ? $node['children']['data'][0] : array() );
        if ( empty( $node['media_url'] ) && !empty( $first['media_url'] ) ) {
            $node['media_url'] = (string) $first['media_url'];
        }
        if ( empty( $node['thumbnail_url'] ) && !empty( $first['thumbnail_url'] ) ) {
            $node['thumbnail_url'] = (string) $first['thumbnail_url'];
        } elseif ( empty( $node['thumbnail_url'] ) && !empty( $first['media_url'] ) ) {
            $node['thumbnail_url'] = (string) $first['media_url'];
        }
        return $node;
    }

    /**
     * Localize one Graph media node (and carousel children).
     *
     * @since 6.9.0
     *
     * @param array<string,mixed> $node         Graph node.
     * @param bool                $use_variants Whether to build grid thumb variants.
     * @param int                 $thumb_width  Variant max width.
     * @param int                 $account_id   Account id.
     * @param string              $hashtag_folder Optional normalized hashtag for hashtag feeds.
     * @return array<string,mixed>
     */
    private static function localize_node(
        array $node,
        bool $use_variants,
        int $thumb_width,
        int $account_id,
        string $hashtag_folder = ''
    ) : array {
        $media_type = strtoupper( ( isset( $node['media_type'] ) ? (string) $node['media_type'] : 'IMAGE' ) );
        if ( 'CAROUSEL_ALBUM' === $media_type && isset( $node['children']['data'] ) && is_array( $node['children']['data'] ) ) {
            foreach ( $node['children']['data'] as $child_index => $child ) {
                if ( !is_array( $child ) ) {
                    continue;
                }
                $node['children']['data'][$child_index] = self::localize_single_media_fields(
                    $child,
                    $use_variants,
                    $thumb_width,
                    $account_id,
                    $hashtag_folder
                );
            }
            $node = self::localize_album_cover(
                $node,
                $use_variants,
                $thumb_width,
                $account_id,
                $hashtag_folder
            );
            return $node;
        }
        return self::localize_single_media_fields(
            $node,
            $use_variants,
            $thumb_width,
            $account_id,
            $hashtag_folder
        );
    }

    /**
     * Set album-level media_url / thumbnail_url from the first child when missing.
     *
     * @since 6.9.0
     *
     * @param array<string,mixed> $node          Album node.
     * @param bool                $use_variants  Whether to build grid thumb variants.
     * @param int                 $thumb_width   Variant max width.
     * @param int                 $account_id    Account id.
     * @param string              $hashtag_folder Optional hashtag folder name (tag_type), e.g., "cats_top".
     * @return array<string,mixed>
     */
    private static function localize_album_cover(
        array $node,
        bool $use_variants,
        int $thumb_width,
        int $account_id,
        string $hashtag_folder = ''
    ) : array {
        $first = ( isset( $node['children']['data'][0] ) && is_array( $node['children']['data'][0] ) ? $node['children']['data'][0] : array() );
        if ( empty( $node['media_url'] ) && !empty( $first['media_url'] ) ) {
            $node['media_url'] = (string) $first['media_url'];
        }
        if ( empty( $node['thumbnail_url'] ) && !empty( $first['thumbnail_url'] ) ) {
            $node['thumbnail_url'] = (string) $first['thumbnail_url'];
        } elseif ( empty( $node['thumbnail_url'] ) && !empty( $first['media_url'] ) ) {
            $node['thumbnail_url'] = (string) $first['media_url'];
        }
        return $node;
    }

    /**
     * Localize media_url and thumbnail_url on a single Graph node.
     *
     * @since 6.9.0
     *
     * @param array<string,mixed> $node           Graph node.
     * @param bool                $use_variants   Whether to build grid thumb variants.
     * @param int                 $thumb_width    Variant max width.
     * @param int                 $account_id     Account id.
     * @param string              $hashtag_folder Optional hashtag folder name (tag_type), e.g., "cats_top".
     * @return array<string,mixed>
     */
    private static function localize_single_media_fields(
        array $node,
        bool $use_variants,
        int $thumb_width,
        int $account_id,
        string $hashtag_folder = ''
    ) : array {
        $id = ( isset( $node['id'] ) ? (string) $node['id'] : '' );
        if ( '' === $id ) {
            return $node;
        }
        $hashtag_folder = sanitize_key( (string) $hashtag_folder );
        $media_type = strtoupper( ( isset( $node['media_type'] ) ? (string) $node['media_type'] : 'IMAGE' ) );
        $media_url = ( isset( $node['media_url'] ) ? (string) $node['media_url'] : '' );
        $thumbnail_url = ( isset( $node['thumbnail_url'] ) ? (string) $node['thumbnail_url'] : '' );
        $is_video = in_array( $media_type, array('VIDEO', 'REELS', 'REEL'), true );
        $media_key = ( '' !== $hashtag_folder ? 'ht_' . $hashtag_folder . '_' . $id : 'ig_media_' . $id );
        $thumb_key = ( '' !== $hashtag_folder ? 'ht_' . $hashtag_folder . '_thumb_' . $id : 'ig_thumb_' . $id );
        if ( $is_video ) {
            if ( '' !== $thumbnail_url ) {
                $local_thumb = self::serve_image(
                    $thumb_key,
                    $thumbnail_url,
                    $use_variants,
                    $thumb_width,
                    $account_id
                );
                if ( is_string( $local_thumb ) && '' !== $local_thumb ) {
                    $node['thumbnail_url'] = $local_thumb;
                }
            }
            return $node;
        }
        if ( '' === $media_url ) {
            return $node;
        }
        $full_local = esf_serve_media_locally(
            $media_key,
            $media_url,
            self::MODULE,
            $account_id
        );
        if ( !is_string( $full_local ) || '' === $full_local ) {
            return $node;
        }
        $node['media_url'] = $full_local;
        $node = self::attach_image_dimensions_from_local_file( $node, $media_key, $account_id );
        $thumb_remote = ( '' !== $thumbnail_url ? $thumbnail_url : $media_url );
        if ( $thumb_remote === $media_url ) {
            $node['thumbnail_url'] = self::grid_thumb_from_full_key(
                $media_key,
                $full_local,
                $use_variants,
                $thumb_width,
                $account_id
            );
            return $node;
        }
        $thumb_local = self::serve_image(
            $thumb_key,
            $thumb_remote,
            $use_variants,
            $thumb_width,
            $account_id
        );
        if ( is_string( $thumb_local ) && '' !== $thumb_local ) {
            $node['thumbnail_url'] = $thumb_local;
        } else {
            $node['thumbnail_url'] = $full_local;
        }
        return $node;
    }

    /**
     * Build a grid thumbnail URL from an already-local full-size file key.
     *
     * @since 6.9.0
     *
     * @param string $media_key    Full-size cache key (ig_media_{id}).
     * @param string $full_local   Full-size local URL fallback.
     * @param bool   $use_variants Whether to build a resized variant.
     * @param int    $thumb_width  Variant max width.
     * @return string
     */
    private static function grid_thumb_from_full_key(
        string $media_key,
        string $full_local,
        bool $use_variants,
        int $thumb_width,
        int $account_id
    ) : string {
        if ( $use_variants && $thumb_width > 0 ) {
            if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
                if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
                    if ( function_exists( 'esf_ensure_local_media_variant__premium_only' ) ) {
                        $variant = esf_ensure_local_media_variant__premium_only(
                            $media_key,
                            self::MODULE,
                            $thumb_width,
                            $account_id
                        );
                        if ( is_string( $variant ) && '' !== $variant ) {
                            return $variant;
                        }
                    }
                }
            }
        }
        return $full_local;
    }

    /**
     * Download (and optionally resize) one image.
     *
     * @since 6.9.0
     *
     * @param string $cache_key    Stable cache key.
     * @param string $remote_url   Remote image URL.
     * @param bool   $use_variants Whether to return a sized variant.
     * @param int    $thumb_width  Variant width.
     * @return false|string
     */
    /**
     * Attach intrinsic width/height from a localized full-size image file.
     *
     * @since 6.9.0
     *
     * @param array<string,mixed> $node       Graph media node.
     * @param string              $media_key  Local media cache key.
     * @param int                 $account_id Account id.
     * @return array<string,mixed>
     */
    private static function attach_image_dimensions_from_local_file( array $node, string $media_key, int $account_id ) : array {
        if ( !function_exists( 'esf_local_media_file_path' ) ) {
            return $node;
        }
        $file = esf_local_media_file_path(
            $media_key,
            self::MODULE,
            0,
            $account_id
        );
        if ( '' === $file || !is_readable( $file ) ) {
            return $node;
        }
        $size = ( function_exists( 'getimagesize' ) ? @getimagesize( $file ) : false );
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( !is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
            return $node;
        }
        $node['width'] = max( 1, (int) $size[0] );
        $node['height'] = max( 1, (int) $size[1] );
        return $node;
    }

    private static function serve_image(
        string $cache_key,
        string $remote_url,
        bool $use_variants,
        int $thumb_width,
        int $account_id
    ) {
        if ( '' === $remote_url ) {
            return false;
        }
        if ( $use_variants && $thumb_width > 0 && function_exists( 'esf_serve_media_locally_sized' ) ) {
            return esf_serve_media_locally_sized(
                $cache_key,
                $remote_url,
                self::MODULE,
                $thumb_width,
                $account_id
            );
        }
        return esf_serve_media_locally(
            $cache_key,
            $remote_url,
            self::MODULE,
            $account_id
        );
    }

}
