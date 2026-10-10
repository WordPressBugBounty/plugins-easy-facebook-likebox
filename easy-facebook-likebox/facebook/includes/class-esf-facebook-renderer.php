<?php

/**
 * Facebook feed renderer.
 *
 * Loads feed/page rows, fetches+caches Graph posts (filters/multifeed/live),
 * maps via PostMapper, and delegates HTML to the shared Layouts Renderer.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */
use EasySocialFeed\Facebook\Layouts\AccountMapper;
use EasySocialFeed\Facebook\Layouts\PostMapper;
use EasySocialFeed\Layouts\PreviewResponseBuilder;
use EasySocialFeed\Layouts\Primitives\EmptyState;
use EasySocialFeed\Layouts\Primitives\MissingFeedState;
use EasySocialFeed\Layouts\Renderer as SharedRenderer;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\MissingFeedContext;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Facebook_Renderer
 *
 * @since 6.7.9
 */
class ESF_Facebook_Renderer {
    /**
     * Module slug.
     *
     * @var string
     */
    const MODULE = 'facebook';

    /**
     * Max posts kept in local cache for load-more reuse.
     *
     * @var int
     */
    const MAX_LOCAL_CACHE_POSTS = 120;

    /**
     * Cron hook for chained live-status Graph checks while a Page may be live.
     *
     * @var string
     */
    const LIVE_STATUS_FOLLOW_UP_HOOK = 'esf_facebook_refresh_live_status_page';

    /**
     * Render a feed by ID.
     *
     * @param int   $feed_id             Feed ID.
     * @param array $settings_override   Optional settings merge (preview).
     * @param bool  $enqueue_assets      Whether layout CSS enqueue should run.
     * @param int   $page_id_override    Optional internal page row override.
     * @return string
     */
    public function render(
        $feed_id,
        $settings_override = array(),
        $enqueue_assets = true,
        $page_id_override = 0
    ) {
        $rendered = $this->render_internal( (int) $feed_id, ( is_array( $settings_override ) ? $settings_override : array() ), (int) $page_id_override );
        if ( '' === $rendered['html'] ) {
            return '';
        }
        if ( $enqueue_assets && null !== $rendered['definition'] ) {
            ( new SharedRenderer() )->enqueue_assets( $rendered['definition'], self::MODULE );
            $layout_slug = $rendered['definition']->get_slug();
            $settings = ( isset( $rendered['settings'] ) && is_array( $rendered['settings'] ) ? $rendered['settings'] : array() );
            if ( function_exists( 'esf_facebook_enqueue_stacked_post_card_css' ) ) {
                esf_facebook_enqueue_stacked_post_card_css( $layout_slug, $settings );
            }
        }
        $html = $rendered['html'];
        if ( !empty( $rendered['admin_notice_html'] ) ) {
            $html = (string) $rendered['admin_notice_html'] . $html;
        }
        if ( !empty( $rendered['likebox_append_html'] ) ) {
            $html .= (string) $rendered['likebox_append_html'];
        }
        return $html;
    }

    /**
     * Build REST preview payload.
     *
     * @param int   $feed_id             Feed ID.
     * @param array $settings_override   Settings override.
     * @param int   $page_id_override    Page row override.
     * @param array $feed_overrides      Optional feed_type/content_type overrides.
     * @return array<string,mixed>
     */
    public function build_preview_payload(
        $feed_id,
        $settings_override = array(),
        $page_id_override = 0,
        array $feed_overrides = array()
    ) {
        $render_options = array(
            'media_context' => 'preview',
        );
        if ( isset( $feed_overrides['feed_type'] ) && is_string( $feed_overrides['feed_type'] ) ) {
            $render_options['feed_type'] = $feed_overrides['feed_type'];
        }
        if ( array_key_exists( 'album_id', $feed_overrides ) ) {
            $render_options['album_id'] = sanitize_text_field( (string) $feed_overrides['album_id'] );
        }
        if ( array_key_exists( 'events_filter', $feed_overrides ) ) {
            $render_options['events_filter'] = sanitize_key( (string) $feed_overrides['events_filter'] );
        }
        $rendered = $this->render_internal(
            (int) $feed_id,
            ( is_array( $settings_override ) ? $settings_override : array() ),
            (int) $page_id_override,
            $render_options
        );
        if ( !empty( $rendered['likebox_append_html'] ) && is_array( $rendered['renderer_output'] ) ) {
            $base_html = ( isset( $rendered['renderer_output']['html'] ) ? (string) $rendered['renderer_output']['html'] : '' );
            $rendered['renderer_output']['html'] = $base_html . (string) $rendered['likebox_append_html'];
        }
        $payload = PreviewResponseBuilder::build( $rendered['renderer_output'], $rendered['has_local_cache'] );
        if ( isset( $rendered['notice'] ) && is_string( $rendered['notice'] ) && '' !== $rendered['notice'] ) {
            $payload['notice'] = array(
                'message' => $rendered['notice'],
            );
        }
        if ( !empty( $rendered['events_setup_required'] ) ) {
            $payload['events_setup_required'] = true;
        }
        $base = ( defined( 'ESF_FACEBOOK_URL' ) ? ESF_FACEBOOK_URL : (( defined( 'EFBL_PLUGIN_URL' ) ? EFBL_PLUGIN_URL : '' )) );
        if ( '' !== $base ) {
            $settings = ( isset( $rendered['settings'] ) && is_array( $rendered['settings'] ) ? $rendered['settings'] : (( is_array( $settings_override ) ? $settings_override : array() )) );
            $layout_slug = '';
            if ( !empty( $rendered['renderer_output']['layout_slug'] ) ) {
                $layout_slug = sanitize_key( (string) $rendered['renderer_output']['layout_slug'] );
            } elseif ( isset( $settings['layout']['type'] ) ) {
                $layout_slug = sanitize_key( (string) $settings['layout']['type'] );
            }
            if ( '' !== $layout_slug && function_exists( 'esf_facebook_needs_stacked_post_card_css' ) && esf_facebook_needs_stacked_post_card_css( $layout_slug, $settings ) && function_exists( 'esf_facebook_get_stacked_post_card_css_asset' ) ) {
                $stacked_asset = esf_facebook_get_stacked_post_card_css_asset();
                if ( null !== $stacked_asset && !empty( $stacked_asset['src'] ) ) {
                    $stacked_url = (string) $stacked_asset['src'];
                    if ( !empty( $stacked_asset['ver'] ) && function_exists( 'add_query_arg' ) ) {
                        $stacked_url = (string) add_query_arg( 'ver', $stacked_asset['ver'], $stacked_url );
                    }
                    $layout_css_urls = array();
                    if ( !empty( $payload['layout_css_url'] ) ) {
                        $layout_css_urls[] = (string) $payload['layout_css_url'];
                    }
                    if ( !in_array( $stacked_url, $layout_css_urls, true ) ) {
                        $layout_css_urls[] = $stacked_url;
                    }
                    if ( count( $layout_css_urls ) > 1 ) {
                        $payload['layout_css_urls'] = $layout_css_urls;
                    }
                }
            }
            $caption_js = $base . 'frontend/assets/js/esf-facebook-caption.js';
            $js_url = ( isset( $payload['js_url'] ) ? (string) $payload['js_url'] : '' );
            $js_urls = array();
            if ( '' !== $caption_js && false === strpos( $js_url, 'esf-facebook-caption.js' ) ) {
                $js_urls[] = $caption_js;
            }
            if ( '' !== $js_url ) {
                $js_urls[] = $js_url;
            }
            if ( !empty( $js_urls ) ) {
                $payload['js_urls'] = $js_urls;
            }
        }
        if ( !empty( $payload['css_url'] ) ) {
            return $payload;
        }
        if ( '' !== $base && empty( $payload['css_url'] ) ) {
            $payload['css_url'] = $base . 'frontend/assets/css/esf-facebook-feed.css';
        }
        return $payload;
    }

    /**
     * Return mapped Post objects for a feed by ID (cron warm / Social Wall / external callers).
     *
     * Wraps {@see fetch_posts()} after loading the feed row and page account.
     *
     * @since 6.7.9
     *
     * @param int                 $feed_id Feed ID.
     * @param array<string,mixed> $args    Optional: limit (int), media_context (preview|frontend), cache_only (bool), force_refresh (bool).
     * @return array<int,\EasySocialFeed\Layouts\ValueObjects\Post>
     */
    public function get_posts_for_feed_by_id( $feed_id, $args = array() ) {
        $feed_id = (int) $feed_id;
        if ( $feed_id <= 0 || !class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
            return array();
        }
        $args = ( is_array( $args ) ? $args : array() );
        $media_context = ( isset( $args['media_context'] ) ? sanitize_key( (string) $args['media_context'] ) : 'frontend' );
        if ( !in_array( $media_context, array('preview', 'frontend'), true ) ) {
            $media_context = 'frontend';
        }
        $cache_only = !empty( $args['cache_only'] );
        $force_refresh = !empty( $args['force_refresh'] );
        $feed_row = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $feed_id );
        if ( !$feed_row ) {
            return array();
        }
        $content_type = ( isset( $feed_row->content_type ) ? sanitize_key( (string) $feed_row->content_type ) : 'feed' );
        if ( 'likebox' === $content_type ) {
            return array();
        }
        $page_row_id = ( isset( $feed_row->page_id ) ? (int) $feed_row->page_id : 0 );
        $settings = ( isset( $feed_row->settings ) && is_array( $feed_row->settings ) ? $feed_row->settings : ESF_Facebook_Feed_Repository::get_default_settings() );
        $settings = ESF_Facebook_Feed_Repository::normalize_settings( $settings );
        $status = ( isset( $feed_row->status ) ? sanitize_key( (string) $feed_row->status ) : 'active' );
        $page_row = null;
        if ( class_exists( 'ESF_Facebook_Page_Repository' ) && $page_row_id > 0 ) {
            $page_row = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_row_id );
        }
        $seed = (object) array(
            'id'        => (int) $feed_row->id,
            'page_id'   => $page_row_id,
            'feed_type' => ( isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'timeline' ),
            'settings'  => $settings,
        );
        $query = ( class_exists( 'ESF_Facebook_Feed_Query' ) ? ESF_Facebook_Feed_Query::from_feed( $seed ) : null );
        $feed_type = ( $query ? $query->get_feed_type() : (( isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'timeline' )) );
        $feed_obj = (object) array(
            'id'           => (int) $feed_row->id,
            'name'         => ( isset( $feed_row->name ) ? (string) $feed_row->name : '' ),
            'page_id'      => $page_row_id,
            'content_type' => 'feed',
            'feed_type'    => $feed_type,
            'source_id'    => ( isset( $feed_row->source_id ) ? (string) $feed_row->source_id : '' ),
            'settings'     => $settings,
            'status'       => $status,
        );
        if ( $query ) {
            $feed_obj = $query->apply_to_feed_object( $feed_obj );
            $feed_obj->page_id = $page_row_id;
        }
        $account = self::resolve_page_account( $page_row_id, $page_row, $feed_obj );
        $result = $this->fetch_posts(
            $feed_obj,
            $account,
            $media_context,
            $cache_only,
            $force_refresh
        );
        $posts = ( isset( $result['posts'] ) && is_array( $result['posts'] ) ? $result['posts'] : array() );
        $limit = ( isset( $args['limit'] ) ? (int) $args['limit'] : 0 );
        if ( $limit > 0 && count( $posts ) > $limit ) {
            $posts = array_slice( $posts, 0, $limit );
        }
        return $posts;
    }

    /**
     * Resolve posts + meta cache keys for a feed.
     *
     * @param object $feed_obj Feed object.
     * @return array{cache_key:string,meta_key:string}
     */
    public static function resolve_posts_cache_keys( $feed_obj ) : array {
        if ( class_exists( 'ESF_Facebook_Feed_Query' ) ) {
            return ESF_Facebook_Feed_Query::from_feed( $feed_obj )->get_cache_keys();
        }
        $page_row_id = ( is_object( $feed_obj ) && isset( $feed_obj->page_id ) ? (int) $feed_obj->page_id : 0 );
        $feed_type = ( function_exists( 'esf_facebook_resolve_effective_feed_type' ) ? esf_facebook_resolve_effective_feed_type( $feed_obj ) : 'timeline' );
        $cache_key = ( class_exists( 'ESF_Facebook_Cache' ) ? ESF_Facebook_Cache::posts_cache_key( $page_row_id, $feed_type ) : sprintf( 'esf_fb_posts_%d_%s', $page_row_id, $feed_type ) );
        return array(
            'cache_key' => $cache_key,
            'meta_key'  => $cache_key . '_meta',
        );
    }

    /**
     * Whether a feed still has cached Graph post nodes (including expired rows).
     *
     * @since 6.7.9
     *
     * @param object $feed_obj Feed object.
     * @return bool
     */
    public static function feed_has_posts_cache( $feed_obj ) : bool {
        if ( !class_exists( 'ESF_Facebook_Cache' ) || !is_object( $feed_obj ) ) {
            return false;
        }
        $keys = self::resolve_posts_cache_keys( $feed_obj );
        $cache_key = ( isset( $keys['cache_key'] ) ? (string) $keys['cache_key'] : '' );
        if ( '' === $cache_key ) {
            return false;
        }
        $cached = ESF_Facebook_Cache::get( $cache_key );
        if ( !is_array( $cached ) ) {
            $cached = ESF_Facebook_Cache::get_stale( $cache_key );
        }
        return is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) && !empty( $cached['data'] );
    }

    /**
     * Resolve AccountSummary for a page row, snapshot, or cached-post inference.
     *
     * Keeps feed + post headers intact after disconnect when cache still exists.
     *
     * @since 6.7.9
     *
     * @param int         $page_row_id Internal pages.id.
     * @param object|null $page_row    Live page row when connected.
     * @param object|null $feed_obj    Optional feed object for cache-key inference.
     * @return AccountSummary|null
     */
    public static function resolve_page_account( $page_row_id, $page_row = null, $feed_obj = null ) : ?AccountSummary {
        $page_row_id = (int) $page_row_id;
        if ( $page_row_id <= 0 ) {
            return null;
        }
        if ( is_object( $page_row ) ) {
            $account = AccountMapper::from_row( $page_row );
            if ( null !== $account && class_exists( 'ESF_Facebook_Local_Media' ) ) {
                $account = ESF_Facebook_Local_Media::with_localized_page_avatar( $account, $page_row );
                $account = ESF_Facebook_Local_Media::with_localized_page_cover( $account, $page_row );
            }
            return $account;
        }
        $snapshot = ( class_exists( 'ESF_Facebook_Cache' ) ? ESF_Facebook_Cache::get_page_profile_snapshot( $page_row_id ) : null );
        if ( !is_array( $snapshot ) || empty( $snapshot ) ) {
            $snapshot = self::infer_page_profile_from_feed_cache( $page_row_id, $feed_obj );
        }
        if ( !is_array( $snapshot ) || empty( $snapshot ) ) {
            return null;
        }
        if ( empty( $snapshot['id'] ) ) {
            $snapshot['id'] = $page_row_id;
        }
        $account = AccountMapper::from_row( $snapshot );
        if ( null === $account ) {
            return null;
        }
        if ( class_exists( 'ESF_Facebook_Local_Media' ) ) {
            $account = ESF_Facebook_Local_Media::with_localized_page_avatar( $account, (object) $snapshot );
            $account = ESF_Facebook_Local_Media::with_localized_page_cover( $account, (object) $snapshot );
        }
        return $account;
    }

    /**
     * Best-effort page profile from cached Graph nodes when no snapshot exists yet.
     *
     * @since 6.7.9
     *
     * @param int         $page_row_id Internal pages.id.
     * @param object|null $feed_obj    Feed object for cache key resolution.
     * @return array<string,mixed>|null
     */
    private static function infer_page_profile_from_feed_cache( $page_row_id, $feed_obj = null ) : ?array {
        if ( !class_exists( 'ESF_Facebook_Cache' ) || $page_row_id <= 0 ) {
            return null;
        }
        $seed = ( is_object( $feed_obj ) ? $feed_obj : (object) array(
            'page_id'   => $page_row_id,
            'feed_type' => 'timeline',
            'settings'  => array(),
        ) );
        $keys = self::resolve_posts_cache_keys( $seed );
        $cached = ESF_Facebook_Cache::get( $keys['cache_key'] );
        if ( !is_array( $cached ) ) {
            $cached = ESF_Facebook_Cache::get_stale( $keys['cache_key'] );
        }
        if ( !is_array( $cached ) || empty( $cached['data'] ) || !is_array( $cached['data'] ) ) {
            return null;
        }
        $page_name = '';
        $graph_page_id = '';
        foreach ( $cached['data'] as $node ) {
            if ( !is_array( $node ) ) {
                continue;
            }
            if ( '' === $page_name && isset( $node['story'] ) && is_string( $node['story'] ) ) {
                $page_name = self::infer_page_name_from_story( $node['story'] );
            }
            if ( '' === $graph_page_id && isset( $node['id'] ) ) {
                $parts = explode( '_', (string) $node['id'], 2 );
                if ( 2 === count( $parts ) && ctype_digit( (string) $parts[0] ) ) {
                    $graph_page_id = (string) $parts[0];
                }
            }
            if ( '' !== $page_name && '' !== $graph_page_id ) {
                break;
            }
        }
        if ( '' === $page_name && '' === $graph_page_id ) {
            return null;
        }
        $profile_image_url = '';
        if ( '' !== $graph_page_id && class_exists( 'ESF_Facebook_Local_Media' ) && function_exists( 'esf_local_media_url' ) ) {
            $avatar_key = 'fb_page_avatar_' . $graph_page_id;
            $thumb = esf_local_media_url(
                $avatar_key,
                'facebook',
                ESF_Facebook_Local_Media::get_avatar_thumb_width(),
                $page_row_id
            );
            if ( '' !== $thumb ) {
                $profile_image_url = $thumb;
            } else {
                $full = esf_local_media_url(
                    $avatar_key,
                    'facebook',
                    0,
                    $page_row_id
                );
                if ( '' !== $full ) {
                    $profile_image_url = $full;
                }
            }
        }
        return array(
            'id'                => $page_row_id,
            'page_id'           => $graph_page_id,
            'page_name'         => $page_name,
            'profile_image_url' => $profile_image_url,
        );
    }

    /**
     * Parse a Graph `story` line for the leading page name.
     *
     * @since 6.7.9
     *
     * @param string $story Graph story text.
     * @return string
     */
    private static function infer_page_name_from_story( $story ) {
        $story = trim( (string) $story );
        if ( '' === $story ) {
            return '';
        }
        if ( preg_match( '/^(.+?)\\s+(added|shared|updated|created|posted|is|was)\\b/i', $story, $matches ) ) {
            return trim( (string) $matches[1] );
        }
        return '';
    }

    /**
     * Shared render pipeline.
     *
     * @param int   $feed_id           Feed ID.
     * @param array $settings_override Settings override.
     * @param int   $page_id_override  Page override.
     * @param array $render_options    Optional media_context / feed_type.
     * @return array<string,mixed>
     */
    private function render_internal(
        int $feed_id,
        array $settings_override,
        int $page_id_override,
        array $render_options = array()
    ) : array {
        $empty = array(
            'html'                => '',
            'likebox_append_html' => '',
            'renderer_output'     => array(
                'html'        => '',
                'assets'      => array(
                    'base_css'   => null,
                    'layout_css' => null,
                    'layout_js'  => null,
                ),
                'inline_css'  => '',
                'layout_slug' => '',
            ),
            'definition'          => null,
            'has_local_cache'     => false,
        );
        if ( $feed_id <= 0 || !class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
            return $empty;
        }
        $feed_row = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $feed_id );
        if ( !$feed_row ) {
            return $this->missing_feed_response( $feed_id, $empty );
        }
        $content_type = ( isset( $feed_row->content_type ) ? sanitize_key( (string) $feed_row->content_type ) : 'feed' );
        if ( 'likebox' === $content_type ) {
            // Likebox is handled by ESF_Facebook_Likebox_Renderer — never LayoutRegistry.
            return $empty;
        }
        $status = ( isset( $feed_row->status ) ? sanitize_key( (string) $feed_row->status ) : 'active' );
        $page_row_id = ( isset( $feed_row->page_id ) ? (int) $feed_row->page_id : 0 );
        if ( $page_id_override > 0 ) {
            $page_row_id = $page_id_override;
        }
        $settings = ( isset( $feed_row->settings ) && is_array( $feed_row->settings ) ? $feed_row->settings : ESF_Facebook_Feed_Repository::get_default_settings() );
        if ( !empty( $settings_override ) ) {
            $settings = array_replace_recursive( $settings, $settings_override );
        }
        $settings = ESF_Facebook_Feed_Repository::normalize_settings( $settings );
        $page_row = null;
        if ( class_exists( 'ESF_Facebook_Page_Repository' ) && $page_row_id > 0 ) {
            $page_row = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_row_id );
        }
        $seed_for_cache = (object) array(
            'id'        => (int) $feed_row->id,
            'page_id'   => $page_row_id,
            'feed_type' => ( isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'timeline' ),
            'settings'  => $settings,
        );
        $source_disconnected = ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ? ESF_Feed_Disconnected_Cache::facebook_source_is_disconnected( $feed_row, $page_row ) : 'invalid' === $status );
        $has_posts_cache = ( $source_disconnected ? self::feed_has_posts_cache( $seed_for_cache ) : false );
        if ( 'active' !== $status && empty( $render_options['allow_inactive'] ) ) {
            $can_manage = function_exists( 'esf_facebook_user_can_manage' ) && esf_facebook_user_can_manage();
            $allow_public_cache = 'invalid' === $status && $has_posts_cache;
            if ( !$allow_public_cache && !$can_manage ) {
                return $empty;
            }
        }
        $query_overrides = array(
            'settings' => $settings,
            'page_id'  => $page_row_id,
        );
        if ( isset( $render_options['feed_type'] ) && is_string( $render_options['feed_type'] ) && '' !== $render_options['feed_type'] ) {
            $query_overrides['feed_type'] = sanitize_key( (string) $render_options['feed_type'] );
        }
        if ( array_key_exists( 'album_id', $render_options ) ) {
            $query_overrides['album_id'] = sanitize_text_field( (string) $render_options['album_id'] );
        }
        if ( array_key_exists( 'events_filter', $render_options ) ) {
            $query_overrides['events_filter'] = sanitize_key( (string) $render_options['events_filter'] );
        }
        $seed = (object) array(
            'id'        => (int) $feed_row->id,
            'page_id'   => $page_row_id,
            'feed_type' => ( isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'timeline' ),
            'settings'  => $settings,
        );
        $query = ( class_exists( 'ESF_Facebook_Feed_Query' ) ? ESF_Facebook_Feed_Query::from_feed( $seed, $query_overrides ) : null );
        $feed_type = ( $query ? $query->get_feed_type() : 'timeline' );
        $feed_obj = (object) array(
            'id'           => (int) $feed_row->id,
            'name'         => ( isset( $feed_row->name ) ? (string) $feed_row->name : '' ),
            'page_id'      => $page_row_id,
            'content_type' => 'feed',
            'feed_type'    => $feed_type,
            'source_id'    => ( isset( $feed_row->source_id ) ? (string) $feed_row->source_id : '' ),
            'settings'     => $settings,
            'status'       => $status,
        );
        if ( $query ) {
            $feed_obj = $query->apply_to_feed_object( $feed_obj );
            $feed_obj->page_id = $page_row_id;
        }
        $account = self::resolve_page_account( $page_row_id, $page_row, $feed_obj );
        // Multifeed: hide the single-Page header (same UX as Instagram / legacy).
        $is_multifeed = function_exists( 'esf_facebook_feed_is_multifeed' ) && esf_facebook_feed_is_multifeed( $feed_obj );
        if ( $is_multifeed ) {
            if ( !isset( $feed_obj->settings ) || !is_array( $feed_obj->settings ) ) {
                $feed_obj->settings = array();
            }
            if ( !isset( $feed_obj->settings['header'] ) || !is_array( $feed_obj->settings['header'] ) ) {
                $feed_obj->settings['header'] = array();
            }
            $feed_obj->settings['header']['show'] = false;
            $settings = $feed_obj->settings;
        }
        $feed_cfg = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
        $has_plan = function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan();
        $live_display = ( function_exists( 'esf_facebook_resolve_live_display' ) ? esf_facebook_resolve_live_display( $feed_cfg ) : (( !empty( $feed_cfg['live_stream_only'] ) ? 'prefer_live' : 'posts' )) );
        // Multifeed soft limit: Live Display is single-Page only.
        $wants_live = !$is_multifeed && $has_plan && (( function_exists( 'esf_facebook_feed_uses_live_status' ) ? esf_facebook_feed_uses_live_status( $feed_cfg ) : in_array( $live_display, array('prefer_live', 'live_only'), true ) ));
        $media_context = ( isset( $render_options['media_context'] ) ? sanitize_key( (string) $render_options['media_context'] ) : 'frontend' );
        $live_video = null;
        $live_active = false;
        if ( $wants_live ) {
            // Public: cache-only (cron / save / preview populate). Preview may hit Graph.
            $live_video = $this->get_live_video_for_feed( $feed_obj, array(
                'allow_network' => 'preview' === $media_context,
            ) );
            $live_active = null !== $live_video;
        }
        if ( function_exists( 'esf_facebook_flag_feed_script' ) ) {
            esf_facebook_flag_feed_script();
        }
        $posts = array();
        $has_cache = false;
        $cache_only = $source_disconnected;
        $events_setup_required = false;
        if ( $live_active && is_array( $live_video ) ) {
            // Live card in the selected layout (no Load More while broadcasting).
            $settings['feed']['load_more'] = false;
            $settings['feed']['load_more_mode'] = 'button';
            $feed_obj->settings = $settings;
            if ( method_exists( PostMapper::class, 'from_live_video__premium_only' ) ) {
                $live_video = self::enrich_live_video_from_cache( $live_video, $page_row_id );
                $live_post = PostMapper::from_live_video__premium_only( $live_video, $account );
                if ( $live_post ) {
                    $posts = array($live_post);
                }
            }
        } elseif ( 'live_only' === $live_display && $has_plan && !$is_multifeed ) {
            // Strict live: do not fall back to Page posts when offline.
            $posts = array();
            $settings['feed']['empty_message'] = ( function_exists( 'esf_translate_registry_string' ) ? (string) esf_translate_registry_string( 'fb_no_live_stream', 'This Page is not broadcasting live right now.' ) : __( 'This Page is not broadcasting live right now.', 'easy-facebook-likebox' ) );
            $feed_obj->settings = $settings;
        } else {
            $fetch = $this->fetch_posts(
                $feed_obj,
                $account,
                $media_context,
                $cache_only
            );
            $posts = ( isset( $fetch['posts'] ) && is_array( $fetch['posts'] ) ? $fetch['posts'] : array() );
            $has_cache = !empty( $fetch['has_local_cache'] );
            if ( !empty( $fetch['events_setup_required'] ) ) {
                $events_setup_required = true;
                // Public visitors: neutral empty copy. Admin preview replaces this
                // with the Events setup workspace (inline Page token paste).
                $settings['feed']['empty_message'] = ( function_exists( 'esf_translate_registry_string' ) ? (string) esf_translate_registry_string( 'fb_no_events', 'No events to show right now.' ) : __( 'No events to show right now.', 'easy-facebook-likebox' ) );
                $feed_obj->settings = $settings;
            }
        }
        // Pass the full cached post list to the layout (Instagram pattern).
        // Layouts slice via get_display_state() for the first page, but keep
        // count( $this->posts ) for Load More visibility. Pre-slicing here
        // hid the button on refresh whenever the meta cursor was empty even
        // though more posts were already sitting in cache.
        $show_popup = !isset( $feed_cfg['show_popup'] ) || !empty( $feed_cfg['show_popup'] );
        if ( $show_popup && !$has_plan && function_exists( 'esf_facebook_flag_free_popup_script' ) ) {
            esf_facebook_flag_free_popup_script();
        }
        $layout_type = ( function_exists( 'esf_facebook_normalize_layout_type' ) ? esf_facebook_normalize_layout_type( ( isset( $settings['layout']['type'] ) ? (string) $settings['layout']['type'] : 'half_width' ) ) : 'half_width' );
        $settings['layout']['type'] = $layout_type;
        $feed_obj->settings = $settings;
        $renderer_output = ( new SharedRenderer() )->render(
            self::MODULE,
            $feed_obj,
            $account,
            $posts,
            array()
        );
        if ( empty( $renderer_output['html'] ) && empty( $posts ) ) {
            $empty_message = ( function_exists( 'esf_translate_registry_string' ) ? (string) esf_translate_registry_string( 'fb_no_posts', 'No posts to display yet.' ) : __( 'No Facebook posts to display.', 'easy-facebook-likebox' ) );
            $renderer_output['html'] = EmptyState::render( self::MODULE, $empty_message );
        }
        $definition = null;
        if ( !empty( $renderer_output['layout_slug'] ) ) {
            $registry = \EasySocialFeed\Layouts\LayoutRegistry::for( self::MODULE );
            $definition = $registry->get( (string) $renderer_output['layout_slug'] );
        }
        $likebox_append = '';
        if ( !empty( $feed_cfg['show_like_box'] ) && class_exists( 'ESF_Facebook_Likebox_Renderer' ) ) {
            $likebox_settings = ( isset( $settings['likebox'] ) && is_array( $settings['likebox'] ) ? $settings['likebox'] : array() );
            $likebox_append = ( new ESF_Facebook_Likebox_Renderer() )->render_inline_for_page( $page_row_id, $likebox_settings, (int) $feed_row->id );
        }
        $admin_notice_html = '';
        $notice = null;
        if ( $source_disconnected && $has_cache && class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
            $cache_pinned = ESF_Feed_Disconnected_Cache::is_feed_cache_effectively_pinned( self::MODULE, (int) $feed_row->id, !empty( $settings['feed']['cache_pinned'] ) );
            $admin_notice_html = ESF_Feed_Disconnected_Cache::get_admin_notice_html( self::MODULE, (int) $feed_row->id, array(
                'cache_pinned' => $cache_pinned,
            ) );
            $notice = ESF_Feed_Disconnected_Cache::get_notice_message( $cache_pinned );
        } elseif ( !$source_disconnected && $has_cache && 'events' === $feed_type && is_object( $page_row ) && function_exists( 'esf_facebook_page_events_token_is_expired' ) && esf_facebook_page_events_token_is_expired( $page_row ) && class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
            $cache_pinned = ESF_Feed_Disconnected_Cache::is_feed_cache_effectively_pinned( self::MODULE, (int) $feed_row->id, !empty( $settings['feed']['cache_pinned'] ) );
            $events_update_url = ( function_exists( 'esf_facebook_get_feed_editor_admin_url' ) ? esf_facebook_get_feed_editor_admin_url( (int) $feed_row->id, ESF_FACEBOOK_EVENTS_TOKEN_PANEL_FRAGMENT ) : '' );
            $admin_notice_html = ESF_Feed_Disconnected_Cache::get_admin_notice_html( self::MODULE, (int) $feed_row->id, array(
                'cache_pinned' => $cache_pinned,
                'message'      => ESF_Feed_Disconnected_Cache::get_events_token_expired_notice_message( $cache_pinned ),
                'link_text'    => __( 'Update Events token', 'easy-facebook-likebox' ),
                'link_url'     => $events_update_url,
            ) );
            $notice = ESF_Feed_Disconnected_Cache::get_events_token_expired_notice_message( $cache_pinned );
        }
        return array(
            'html'                  => ( isset( $renderer_output['html'] ) ? (string) $renderer_output['html'] : '' ),
            'likebox_append_html'   => $likebox_append,
            'renderer_output'       => $renderer_output,
            'definition'            => $definition,
            'has_local_cache'       => $has_cache,
            'settings'              => $settings,
            'admin_notice_html'     => $admin_notice_html,
            'notice'                => $notice,
            'events_setup_required' => $events_setup_required,
        );
    }

    /**
     * Resolve live video for a feed from cache (and optionally Graph).
     *
     * Public frontend is cache-only: status is filled by the existing token
     * cron (only when Live Display feeds exist), feed save, admin preview, or
     * chained follow-up checks while a Page may be broadcasting.
     * Expired positive rows are never served from stale cache (Prefer live falls
     * back to Page posts; Live only shows the offline message).
     *
     * @param object              $feed_obj Feed object.
     * @param array<string,mixed> $options  Optional. `allow_network` (bool) for preview/cron refresh.
     * @return array{id:string,status:string,permalink_url:string,embed_html:string}|null
     */
    public function get_live_video_for_feed( $feed_obj, array $options = array() ) {
        if ( !is_object( $feed_obj ) || !class_exists( 'ESF_Facebook_Page_Repository' ) ) {
            return null;
        }
        $page_row_id = ( isset( $feed_obj->page_id ) ? (int) $feed_obj->page_id : 0 );
        if ( $page_row_id <= 0 ) {
            return null;
        }
        $allow_network = !empty( $options['allow_network'] );
        $cache_key = ( class_exists( 'ESF_Facebook_Cache' ) ? ESF_Facebook_Cache::live_cache_key( $page_row_id ) : 'esf_fb_live_' . $page_row_id );
        if ( class_exists( 'ESF_Facebook_Cache' ) ) {
            $cached_video = self::read_live_video_from_cache( $cache_key );
            if ( null !== $cached_video || self::has_confirmed_not_live_cache( $cache_key ) ) {
                return $cached_video;
            }
        }
        if ( !$allow_network ) {
            return null;
        }
        $feed_id = ( isset( $feed_obj->id ) ? (int) $feed_obj->id : null );
        if ( !method_exists( $this, 'refresh_live_status_for_page__premium_only' ) ) {
            return null;
        }
        return $this->refresh_live_status_for_page__premium_only( $page_row_id, ( $feed_id > 0 ? $feed_id : null ) );
    }

    /**
     * Read a live video from a non-expired live cache row only.
     *
     * @since 6.7.9
     * @param string $cache_key Live cache key.
     * @return array<string,mixed>|null Live video payload or null when offline/unknown.
     */
    public static function read_live_video_from_cache( $cache_key ) {
        if ( !class_exists( 'ESF_Facebook_Cache' ) ) {
            return null;
        }
        $cached = ESF_Facebook_Cache::get( $cache_key );
        if ( !is_array( $cached ) || !array_key_exists( 'is_live', $cached ) ) {
            return null;
        }
        if ( !empty( $cached['is_live'] ) && isset( $cached['video'] ) && is_array( $cached['video'] ) ) {
            return $cached['video'];
        }
        return null;
    }

    /**
     * Whether cache confirms the Page is not live (fresh or stale offline row).
     *
     * @since 6.7.9
     * @param string $cache_key Live cache key.
     * @return bool
     */
    public static function has_confirmed_not_live_cache( $cache_key ) {
        if ( !class_exists( 'ESF_Facebook_Cache' ) ) {
            return false;
        }
        $cached = ESF_Facebook_Cache::get( $cache_key );
        if ( is_array( $cached ) && array_key_exists( 'is_live', $cached ) ) {
            return empty( $cached['is_live'] );
        }
        $cached = ESF_Facebook_Cache::get_stale( $cache_key );
        return is_array( $cached ) && array_key_exists( 'is_live', $cached ) && empty( $cached['is_live'] );
    }

    /**
     * Schedule a near-term Graph re-check while a Page may be (or was) live.
     *
     * @since 6.7.9
     * @param int $page_row_id Internal page row id.
     * @return void
     */
    public static function schedule_live_status_follow_up( $page_row_id ) {
        $page_row_id = (int) $page_row_id;
        if ( $page_row_id <= 0 || !function_exists( 'wp_schedule_single_event' ) ) {
            return;
        }
        $delay = ( function_exists( 'esf_facebook_get_live_follow_up_interval' ) ? esf_facebook_get_live_follow_up_interval() : 300 );
        $args = array($page_row_id);
        if ( wp_next_scheduled( self::LIVE_STATUS_FOLLOW_UP_HOOK, $args ) ) {
            return;
        }
        wp_schedule_single_event( time() + max( 60, (int) $delay ), self::LIVE_STATUS_FOLLOW_UP_HOOK, $args );
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    /**
     * Cron callback: refresh live status for one Page when Live Display is enabled.
     *
     * @since 6.7.9
     * @param int $page_row_id Internal page row id.
     * @return void
     */
    public static function run_live_status_follow_up( $page_row_id ) {
        $page_row_id = (int) $page_row_id;
        if ( $page_row_id <= 0 || !class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
            return;
        }
        if ( function_exists( 'esf_facebook_has_facebook_plan' ) && !esf_facebook_has_facebook_plan() ) {
            return;
        }
        $page_ids = ESF_Facebook_Feed_Repository::get_instance()->get_page_row_ids_with_live_stream();
        if ( empty( $page_ids ) || !in_array( $page_row_id, array_map( 'intval', $page_ids ), true ) ) {
            return;
        }
        $renderer = new self();
        if ( !method_exists( $renderer, 'refresh_live_status_for_page__premium_only' ) ) {
            return;
        }
        $renderer->refresh_live_status_for_page__premium_only( $page_row_id );
    }

    /**
     * Ensure cached live payloads keep a stable broadcast start time.
     *
     * Graph may omit timestamps on some refreshes; reuse the prior value for the
     * same live video id, or stamp first detection once (never per page view).
     *
     * @since 6.7.9
     * @param array<string,mixed> $video     Parsed live video payload.
     * @param string              $cache_key Live cache key.
     * @return array<string,mixed>
     */
    private static function finalize_live_video_cache_payload( array $video, $cache_key ) {
        $video_id = ( isset( $video['id'] ) ? sanitize_text_field( (string) $video['id'] ) : '' );
        if ( '' === $video_id ) {
            return $video;
        }
        $started_at = ( isset( $video['started_at'] ) ? sanitize_text_field( (string) $video['started_at'] ) : '' );
        if ( '' === $started_at && class_exists( 'ESF_Facebook_Cache' ) ) {
            $stale = ESF_Facebook_Cache::get_stale( $cache_key );
            if ( is_array( $stale ) && isset( $stale['video'] ) && is_array( $stale['video'] ) ) {
                $stale_video = $stale['video'];
                if ( isset( $stale_video['id'] ) && (string) $stale_video['id'] === $video_id ) {
                    $started_at = ( isset( $stale_video['started_at'] ) ? sanitize_text_field( (string) $stale_video['started_at'] ) : '' );
                }
            }
        }
        if ( '' === $started_at ) {
            $started_at = gmdate( 'c' );
        }
        $video['started_at'] = $started_at;
        return $video;
    }

    /**
     * Fill missing live timestamps from the cached live row (legacy rows).
     *
     * @since 6.7.9
     * @param array<string,mixed> $video       Live video payload.
     * @param int                 $page_row_id Internal page row id.
     * @return array<string,mixed>
     */
    private static function enrich_live_video_from_cache( array $video, $page_row_id ) {
        if ( !empty( $video['started_at'] ) || !class_exists( 'ESF_Facebook_Cache' ) ) {
            return $video;
        }
        $cache_key = ESF_Facebook_Cache::live_cache_key( (int) $page_row_id );
        $cached = ESF_Facebook_Cache::get( $cache_key );
        if ( !is_array( $cached ) ) {
            $cached = ESF_Facebook_Cache::get_stale( $cache_key );
        }
        if ( is_array( $cached ) && isset( $cached['video'] ) && is_array( $cached['video'] ) ) {
            $cached_video = $cached['video'];
            if ( !empty( $cached_video['started_at'] ) ) {
                $video['started_at'] = sanitize_text_field( (string) $cached_video['started_at'] );
                return $video;
            }
        }
        if ( is_array( $cached ) && !empty( $cached['checked_at'] ) ) {
            $video['started_at'] = sanitize_text_field( (string) $cached['checked_at'] );
        }
        return $video;
    }

    /**
     * Fetch and map posts (cache-aware), including Pro filters + multifeed merge.
     *
     * Localizes post images at display time via ESF_Facebook_Local_Media
     * (respects General → Download images locally for `facebook`).
     *
     * @param object              $feed_obj      Feed object.
     * @param AccountSummary|null $account       Primary page summary.
     * @param string              $media_context  `preview` or `frontend`.
     * @param bool                $cache_only       Serve cache only (disconnected / invalid).
     * @param bool                $force_refresh    Skip stale fallback and fetch from Graph when cache is expired.
     * @return array{posts:array<int,\EasySocialFeed\Layouts\ValueObjects\Post>,has_local_cache:bool,nodes:array<int,array>,cursor:string}
     */
    public function fetch_posts(
        $feed_obj,
        ?AccountSummary $account = null,
        string $media_context = 'frontend',
        bool $cache_only = false,
        bool $force_refresh = false
    ) : array {
        $result = array(
            'posts'           => array(),
            'has_local_cache' => false,
            'nodes'           => array(),
            'cursor'          => '',
        );
        $preset = array(
            'posts'           => array(),
            'has_local_cache' => false,
            'nodes'           => array(),
            'cursor'          => '',
        );
        /**
         * Filter the Facebook post payload before cache/API fetch.
         *
         * Returning posts/nodes short-circuits the Graph fetch. Prefer Graph node
         * arrays in `posts` or `nodes` (same shape as the posts cache `data` key).
         *
         * @since 6.7.9
         *
         * @param array               $value         Default empty payload.
         * @param object              $feed_obj      Feed object.
         * @param AccountSummary|null $account       Primary page summary.
         * @param string              $media_context Display context (`frontend`|`preview`).
         */
        $payload = apply_filters(
            'esf_facebook_render_posts_payload',
            $preset,
            $feed_obj,
            $account,
            $media_context
        );
        $nodes = array();
        $cursor = '';
        $fetched_limit = 0;
        $short_circuited = false;
        $settings = ( isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array() );
        $feed_cfg = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
        $per_page = ( isset( $feed_cfg['per_page'] ) ? max( 1, min( 50, (int) $feed_cfg['per_page'] ) ) : 9 );
        if ( function_exists( 'esf_resolve_render_payload_override' ) ) {
            $override = esf_resolve_render_payload_override( $payload, 'posts' );
            if ( !$override['short_circuited'] && is_array( $payload ) && (isset( $payload['nodes'] ) || array_key_exists( 'has_local_cache', $payload )) ) {
                $override = esf_resolve_render_payload_override( $payload, 'nodes' );
            }
            if ( $override['short_circuited'] ) {
                $nodes = $override['items'];
                $result['has_local_cache'] = !empty( $override['has_local_cache'] ) || !empty( $nodes );
                $cursor = ( is_array( $payload ) && isset( $payload['cursor'] ) ? (string) $payload['cursor'] : '' );
                $fetched_limit = count( $nodes );
                $short_circuited = true;
            }
        }
        if ( !$short_circuited ) {
            if ( !$cache_only ) {
                $page_row_id = ( is_object( $feed_obj ) && isset( $feed_obj->page_id ) ? (int) $feed_obj->page_id : 0 );
                $feed_status = ( is_object( $feed_obj ) && isset( $feed_obj->status ) ? sanitize_key( (string) $feed_obj->status ) : 'active' );
                if ( 'invalid' === $feed_status ) {
                    $cache_only = true;
                } elseif ( $page_row_id > 0 && class_exists( 'ESF_Facebook_Page_Repository' ) ) {
                    $page_row = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_row_id );
                    if ( !$page_row ) {
                        $cache_only = true;
                    }
                }
            }
            $keys = self::resolve_posts_cache_keys( $feed_obj );
            $cache_key = $keys['cache_key'];
            $meta_key = $keys['meta_key'];
            $cached = ( class_exists( 'ESF_Facebook_Cache' ) ? ESF_Facebook_Cache::get( $cache_key ) : null );
            if ( !is_array( $cached ) && !$force_refresh && class_exists( 'ESF_Facebook_Cache' ) ) {
                $cached = ESF_Facebook_Cache::get_stale( $cache_key );
            }
            // Specific-album feeds must contain photos, not album-list cards. Discard
            // poisoned cache rows left from when album_id was ignored at Graph fetch.
            if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
                $feed_cfg_check = ( isset( $feed_obj->settings['feed'] ) && is_array( $feed_obj->settings['feed'] ) ? $feed_obj->settings['feed'] : array() );
                $selected_album = ( isset( $feed_cfg_check['album_id'] ) ? sanitize_text_field( (string) $feed_cfg_check['album_id'] ) : '' );
                $feed_type_check = ( isset( $feed_obj->feed_type ) ? sanitize_key( (string) $feed_obj->feed_type ) : '' );
                if ( 'albums' === $feed_type_check && '' !== $selected_album && self::cached_nodes_look_like_album_list( $cached['data'] ) ) {
                    $cached = null;
                    if ( class_exists( 'ESF_Facebook_Cache' ) ) {
                        ESF_Facebook_Cache::delete_by_key( $cache_key );
                        ESF_Facebook_Cache::delete_by_key( $meta_key );
                    }
                }
            }
            if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
                $nodes = $cached['data'];
                $result['has_local_cache'] = true;
                if ( !empty( $cached['pagination']['next'] ) ) {
                    $cursor = (string) $cached['pagination']['next'];
                }
                if ( isset( $cached['fetched_limit'] ) ) {
                    $fetched_limit = max( 0, (int) $cached['fetched_limit'] );
                } else {
                    // Legacy thin caches were fetched at display per_page (often 9).
                    $fetched_limit = count( $nodes );
                }
            } elseif ( !$cache_only ) {
                $fetched = $this->fetch_and_cache_posts( $feed_obj );
                $nodes = ( isset( $fetched['nodes'] ) ? $fetched['nodes'] : array() );
                $cursor = ( isset( $fetched['cursor'] ) ? (string) $fetched['cursor'] : '' );
                $fetched_limit = ( isset( $fetched['fetched_limit'] ) ? (int) $fetched['fetched_limit'] : count( $nodes ) );
                if ( !empty( $fetched['events_setup_required'] ) ) {
                    $result['events_setup_required'] = true;
                }
            }
            $meta = ( class_exists( 'ESF_Facebook_Cache' ) ? ESF_Facebook_Cache::get( $meta_key ) : null );
            if ( is_array( $meta ) && !empty( $meta['cursor'] ) ) {
                $cursor = (string) $meta['cursor'];
            }
            if ( is_array( $meta ) && isset( $meta['fetched_limit'] ) ) {
                $fetched_limit = max( $fetched_limit, (int) $meta['fetched_limit'] );
            }
            /*
             * Thin legacy caches were stored at the old Graph page size (== per_page,
             * often 9). When Posts per page is raised above that buffer, refetch with
             * the Instagram-parity prefetch limit so the first page can grow.
             */
            if ( !$cache_only && count( $nodes ) < $per_page && $fetched_limit < $per_page ) {
                if ( class_exists( 'ESF_Facebook_Cache' ) ) {
                    ESF_Facebook_Cache::delete_by_key( $cache_key );
                    ESF_Facebook_Cache::delete_by_key( $meta_key );
                }
                $fetched = $this->fetch_and_cache_posts( $feed_obj );
                $nodes = ( isset( $fetched['nodes'] ) ? $fetched['nodes'] : array() );
                $cursor = ( isset( $fetched['cursor'] ) ? (string) $fetched['cursor'] : '' );
                $result['has_local_cache'] = !empty( $nodes );
                if ( !empty( $fetched['events_setup_required'] ) ) {
                    $result['events_setup_required'] = true;
                }
            }
        }
        $feed_id = ( isset( $feed_obj->id ) ? (int) $feed_obj->id : 0 );
        if ( $feed_id > 0 && function_exists( 'esf_facebook_apply_moderation_filter' ) ) {
            $nodes = esf_facebook_apply_moderation_filter( $nodes, $feed_id, $settings );
        }
        $page_row_id = ( isset( $feed_obj->page_id ) ? (int) $feed_obj->page_id : 0 );
        if ( class_exists( 'ESF_Facebook_Local_Media' ) && $page_row_id > 0 && !empty( $nodes ) ) {
            $nodes = ESF_Facebook_Local_Media::prepare_nodes_for_display(
                $nodes,
                $page_row_id,
                $media_context,
                $per_page
            );
            $feed_id = ( isset( $feed_obj->id ) ? (int) $feed_obj->id : 0 );
            if ( $feed_id > 0 && ESF_Facebook_Local_Media::visible_page_needs_media_recovery( $nodes, $page_row_id, $per_page ) ) {
                ESF_Facebook_Local_Media::schedule_warm_for_feed( $feed_id, $page_row_id );
            }
        }
        $feed_type_for_enrich = ( isset( $feed_obj->feed_type ) ? sanitize_key( (string) $feed_obj->feed_type ) : 'timeline' );
        if ( function_exists( 'esf_facebook_enrich_feed_nodes' ) && $page_row_id > 0 && !empty( $nodes ) ) {
            $page_row_for_enrich = ( class_exists( 'ESF_Facebook_Page_Repository' ) ? ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_row_id ) : null );
            if ( $page_row_for_enrich ) {
                $nodes = esf_facebook_enrich_feed_nodes( $nodes, $page_row_for_enrich, $feed_type_for_enrich );
            }
        }
        $posts = array();
        $page_repo = ( class_exists( 'ESF_Facebook_Page_Repository' ) ? ESF_Facebook_Page_Repository::get_instance() : null );
        foreach ( $nodes as $node ) {
            if ( !is_array( $node ) ) {
                continue;
            }
            // Multifeed: per-post AccountSummary (localized avatar) from _esf_page_row_id.
            $node_account = $account;
            if ( isset( $node['_esf_page_row_id'] ) ) {
                $node_page_row_id = (int) $node['_esf_page_row_id'];
                if ( $node_page_row_id > 0 ) {
                    $node_page_row = ( $page_repo ? $page_repo->get_by_id( $node_page_row_id ) : null );
                    $resolved = self::resolve_page_account( $node_page_row_id, $node_page_row, $feed_obj );
                    if ( null !== $resolved ) {
                        $node_account = $resolved;
                    }
                }
            }
            $post = PostMapper::from_graph_node( $node, $node_account );
            if ( null === $post ) {
                continue;
            }
            $posts[] = $post;
        }
        if ( $feed_id > 0 && function_exists( 'esf_facebook_filter_unavailable_embed_posts__premium_only' ) ) {
            $posts = esf_facebook_filter_unavailable_embed_posts__premium_only( $posts, $feed_id );
        }
        $result['posts'] = $posts;
        $result['nodes'] = $nodes;
        $result['cursor'] = $cursor;
        return $result;
    }

    /**
     * Force-refresh one feed from Graph, bypassing warm cache.
     *
     * Writes cache only when Graph returns posts. On failure/empty, existing
     * cache rows are left untouched.
     *
     * @since 6.8.2
     *
     * @param object $feed_obj Feed object.
     * @return true|WP_Error
     */
    public function force_refresh_feed_posts( $feed_obj ) {
        if ( !is_object( $feed_obj ) ) {
            return new WP_Error('invalid_feed', __( 'Invalid Facebook feed.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $content_type = ( isset( $feed_obj->content_type ) ? sanitize_key( (string) $feed_obj->content_type ) : 'feed' );
        if ( 'likebox' === $content_type ) {
            return new WP_Error('skipped_likebox', __( 'Likebox feeds do not use post cache.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $fetched = $this->fetch_and_cache_posts( $feed_obj );
        $nodes = ( isset( $fetched['nodes'] ) && is_array( $fetched['nodes'] ) ? $fetched['nodes'] : array() );
        if ( empty( $nodes ) ) {
            return new WP_Error('empty_feed', __( 'No Facebook posts were returned. Existing cache was kept.', 'easy-facebook-likebox' ), array(
                'status' => 502,
            ));
        }
        return true;
    }

    /**
     * Fetch from Graph (single page or multifeed merge) and write cache.
     *
     * @param object $feed_obj Feed object.
     * @return array{nodes:array<int,array>,cursor:string}
     */
    private function fetch_and_cache_posts( $feed_obj ) : array {
        $out = array(
            'nodes'  => array(),
            'cursor' => '',
        );
        if ( !class_exists( 'ESF_Facebook_Page_Repository' ) || !class_exists( 'ESF_Facebook_Graph_Client' ) ) {
            return $out;
        }
        $page_ids = ( function_exists( 'esf_facebook_resolve_page_row_ids' ) ? esf_facebook_resolve_page_row_ids( $feed_obj ) : array(( isset( $feed_obj->page_id ) ? (int) $feed_obj->page_id : 0 )) );
        $query = ( class_exists( 'ESF_Facebook_Feed_Query' ) ? ESF_Facebook_Feed_Query::from_feed( $feed_obj ) : null );
        $feed_type = ( $query ? $query->get_feed_type() : (( function_exists( 'esf_facebook_resolve_effective_feed_type' ) ? esf_facebook_resolve_effective_feed_type( $feed_obj ) : 'timeline' )) );
        $args = ( $query ? $query->get_graph_args() : array(
            'limit'         => ( class_exists( 'ESF_Facebook_Feed_Query' ) ? ESF_Facebook_Feed_Query::GRAPH_PREFETCH_LIMIT : 100 ),
            'events_filter' => ( isset( $feed_obj->settings['feed']['events_filter'] ) ? (string) $feed_obj->settings['feed']['events_filter'] : '' ),
            'album_id'      => ( isset( $feed_obj->settings['feed']['album_id'] ) ? (string) $feed_obj->settings['feed']['album_id'] : '' ),
        ) );
        $requested_limit = ( isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 100 );
        if ( $query ) {
            $page_ids = $query->get_page_row_ids();
            if ( empty( $page_ids ) && isset( $feed_obj->page_id ) ) {
                $page_ids = array((int) $feed_obj->page_id);
            }
        }
        $merged = array();
        $cursor = '';
        $had_success = false;
        $events_setup_required = false;
        $repo = ESF_Facebook_Page_Repository::get_instance();
        $is_multi = count( $page_ids ) > 1;
        foreach ( $page_ids as $page_row_id ) {
            $page_row_id = (int) $page_row_id;
            if ( $page_row_id <= 0 ) {
                continue;
            }
            $page_row = $repo->get_by_id( $page_row_id );
            if ( !$page_row || empty( $page_row->page_id ) ) {
                continue;
            }
            $content_token = ( function_exists( 'esf_facebook_resolve_content_graph_token' ) ? esf_facebook_resolve_content_graph_token( $page_row, $feed_type ) : (( isset( $page_row->page_access_token ) ? trim( (string) $page_row->page_access_token ) : '' )) );
            if ( '' === $content_token ) {
                if ( 'events' === $feed_type && function_exists( 'esf_facebook_events_token_required_for_feed' ) && esf_facebook_events_token_required_for_feed( $page_row, $feed_type ) ) {
                    $events_setup_required = true;
                }
                continue;
            }
            $response = ESF_Facebook_Graph_Client::fetch_page_content(
                (string) $page_row->page_id,
                $content_token,
                $feed_type,
                $args
            );
            if ( is_wp_error( $response ) ) {
                if ( 'events' === $feed_type && function_exists( 'esf_facebook_page_has_dedicated_events_token' ) && !esf_facebook_page_has_dedicated_events_token( $page_row ) && function_exists( 'esf_facebook_graph_error_suggests_events_token' ) && esf_facebook_graph_error_suggests_events_token( $response ) ) {
                    $events_setup_required = true;
                }
                continue;
            }
            if ( !is_array( $response ) ) {
                continue;
            }
            $had_success = true;
            $data = ( isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array() );
            foreach ( $data as $node ) {
                if ( !is_array( $node ) ) {
                    continue;
                }
                $node['_esf_page_row_id'] = $page_row_id;
                $merged[] = $node;
            }
            if ( !$is_multi ) {
                // Prefer the opaque `after` cursor (safe to replay against a
                // request we build ourselves) over Graph's absolute `next`
                // URL, which is sometimes malformed/deprecated for `/feed`
                // and other edges — see extract_after_cursor() docblock.
                $after = ESF_Facebook_Graph_Client::extract_after_cursor( $response );
                if ( '' !== $after ) {
                    $cursor = $after;
                } elseif ( !empty( $response['paging']['next'] ) && is_string( $response['paging']['next'] ) ) {
                    $cursor = (string) $response['paging']['next'];
                }
            }
        }
        if ( $is_multi && count( $merged ) > 1 ) {
            $feed_cfg_sort = ( isset( $feed_obj->settings['feed'] ) && is_array( $feed_obj->settings['feed'] ) ? $feed_obj->settings['feed'] : array() );
            $sort_mode = ( $query && method_exists( $query, 'get_multifeed_sort' ) ? $query->get_multifeed_sort() : (( function_exists( 'esf_facebook_resolve_multifeed_sort' ) ? esf_facebook_resolve_multifeed_sort( $feed_cfg_sort ) : 'newest' )) );
            $merged = ( function_exists( 'esf_facebook_sort_multifeed_nodes' ) ? esf_facebook_sort_multifeed_nodes( $merged, $sort_mode ) : $this->sort_nodes_by_created_time( $merged ) );
        }
        if ( count( $merged ) > self::MAX_LOCAL_CACHE_POSTS ) {
            $merged = array_slice( $merged, 0, self::MAX_LOCAL_CACHE_POSTS );
        }
        // Never cache failed Graph calls or empty payloads — both lock filters into
        // "No posts" until TTL expires (images/videos/events/albums/mentioned).
        if ( !$had_success || array() === $merged ) {
            $out['nodes'] = array();
            $out['cursor'] = '';
            if ( 'events' === $feed_type && $events_setup_required ) {
                $out['events_setup_required'] = true;
            }
            return $out;
        }
        $keys = self::resolve_posts_cache_keys( $feed_obj );
        $ttl = ( function_exists( 'esf_facebook_get_cache_ttl_secs' ) ? esf_facebook_get_cache_ttl_secs() : 43200 );
        $page_scope = ( isset( $feed_obj->page_id ) ? (int) $feed_obj->page_id : null );
        $feed_id = ( isset( $feed_obj->id ) ? (int) $feed_obj->id : null );
        if ( class_exists( 'ESF_Facebook_Cache' ) ) {
            ESF_Facebook_Cache::set(
                $keys['cache_key'],
                array(
                    'data'          => $merged,
                    'pagination'    => array(
                        'next' => $cursor,
                    ),
                    'fetched_limit' => $requested_limit,
                ),
                $ttl,
                'feed',
                $page_scope,
                $feed_id
            );
            ESF_Facebook_Cache::set(
                $keys['meta_key'],
                array(
                    'cursor'        => $cursor,
                    'fetched_limit' => $requested_limit,
                ),
                $ttl,
                'feed',
                $page_scope,
                $feed_id
            );
        }
        $out['nodes'] = $merged;
        $out['cursor'] = $cursor;
        $out['fetched_limit'] = $requested_limit;
        return $out;
    }

    /**
     * Whether cached Graph nodes look like Page `/albums` list cards.
     *
     * Used to detect poisoned specific-album cache rows that actually stored
     * the album list (from when album_id was ignored during Graph fetch).
     *
     * @since 6.7.9
     * @param array<int,mixed> $nodes Cached data nodes.
     * @return bool
     */
    private static function cached_nodes_look_like_album_list( array $nodes ) : bool {
        $checked = 0;
        $albumish = 0;
        foreach ( $nodes as $node ) {
            if ( !is_array( $node ) ) {
                continue;
            }
            ++$checked;
            if ( $checked > 5 ) {
                break;
            }
            $has_name_count = isset( $node['name'], $node['count'] ) && is_numeric( $node['count'] );
            $has_album_marker = isset( $node['cover_photo'] ) || isset( $node['link'] ) && is_string( $node['link'] );
            $has_photo_marker = isset( $node['images'] ) || isset( $node['picture'] ) && is_string( $node['picture'] );
            if ( $has_name_count && $has_album_marker && !$has_photo_marker ) {
                ++$albumish;
            }
        }
        return $checked > 0 && $albumish === $checked;
    }

    /**
     * Sort Graph nodes newest-first by created_time (multifeed merge).
     *
     * @param array<int,array<string,mixed>> $nodes Nodes.
     * @return array<int,array<string,mixed>>
     */
    private function sort_nodes_by_created_time( array $nodes ) : array {
        if ( function_exists( 'esf_facebook_sort_nodes_by_created_time' ) ) {
            return esf_facebook_sort_nodes_by_created_time( $nodes );
        }
        usort( $nodes, static function ( $a, $b ) {
            $ta = ( isset( $a['created_time'] ) ? strtotime( (string) $a['created_time'] ) : 0 );
            $tb = ( isset( $b['created_time'] ) ? strtotime( (string) $b['created_time'] ) : 0 );
            if ( false === $ta ) {
                $ta = 0;
            }
            if ( false === $tb ) {
                $tb = 0;
            }
            return $tb <=> $ta;
        } );
        return $nodes;
    }

    /**
     * Missing-feed placeholder for deleted shortcode IDs.
     *
     * @param int                 $feed_id Feed ID.
     * @param array<string,mixed> $empty   Empty template.
     * @return array<string,mixed>
     */
    private function missing_feed_response( int $feed_id, array $empty ) : array {
        // Load base feed CSS so the public message matches other modules' empty state.
        if ( function_exists( 'esf_facebook_flag_feed_script' ) ) {
            esf_facebook_flag_feed_script();
        }
        if ( !class_exists( MissingFeedState::class ) || !class_exists( MissingFeedContext::class ) ) {
            return $empty;
        }
        $can_manage = function_exists( 'esf_facebook_user_can_manage' ) && esf_facebook_user_can_manage();
        $post_id = ( function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0 );
        $context = new MissingFeedContext(
            'facebook',
            $feed_id,
            'esf_facebook_feed',
            $post_id,
            $can_manage,
            MissingFeedState::default_public_message( 'facebook' ),
            admin_url( 'admin.php?page=esf-facebook&screen=feeds' ),
            '/facebook/feeds/{id}/preview',
            '/facebook/feeds'
        );
        $html = MissingFeedState::render( $context );
        $empty['html'] = $html;
        $empty['renderer_output']['html'] = $html;
        return $empty;
    }

}
