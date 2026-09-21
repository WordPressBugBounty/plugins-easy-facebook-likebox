<?php

/**
 * Facebook Feeds REST API
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Facebook_API_Feeds
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Feeds {
    /**
     * Register REST routes.
     *
     * @since 6.7.9
     * @return void
     */
    public static function register_routes() {
        register_rest_route( 'esf/v1', '/facebook/feeds', array(array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'get_feeds'),
            'permission_callback' => array(__CLASS__, 'read_permissions_check'),
        ), array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'create_feed'),
            'permission_callback' => array(__CLASS__, 'write_permissions_check'),
        )) );
        register_rest_route( 'esf/v1', '/facebook/feeds/(?P<id>[\\d]+)', array(array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'get_feed'),
            'permission_callback' => array(__CLASS__, 'read_permissions_check'),
        ), array(
            'methods'             => 'PUT,PATCH',
            'callback'            => array(__CLASS__, 'update_feed'),
            'permission_callback' => array(__CLASS__, 'write_permissions_check'),
        ), array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => array(__CLASS__, 'delete_feed'),
            'permission_callback' => array(__CLASS__, 'write_permissions_check'),
        )) );
        register_rest_route( 'esf/v1', '/facebook/feeds/(?P<id>[\\d]+)/duplicate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'duplicate_feed'),
            'permission_callback' => array(__CLASS__, 'write_permissions_check'),
        ) );
        register_rest_route( 'esf/v1', '/facebook/feeds/(?P<id>[\\d]+)/clear-cache', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'clear_feed_cache'),
            'permission_callback' => array(__CLASS__, 'write_permissions_check'),
        ) );
        register_rest_route( 'esf/v1', '/facebook/feeds/(?P<id>[\\d]+)/pin-cache', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'pin_feed_cache'),
            'permission_callback' => array(__CLASS__, 'write_permissions_check'),
        ) );
        register_rest_route( 'esf/v1', '/facebook/feeds/(?P<id>[\\d]+)/sync-live', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'sync_feed_live'),
            'permission_callback' => array(__CLASS__, 'write_permissions_check'),
        ) );
    }

    /**
     * Read permission callback.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    public static function read_permissions_check( $request ) {
        if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
            return false;
        }
        return esf_facebook_user_can_manage();
    }

    /**
     * Write permission callback.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    public static function write_permissions_check( $request ) {
        if ( !esf_facebook_user_can_manage() ) {
            return false;
        }
        $nonce = $request->get_header( 'X-WP-Nonce' );
        return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
    }

    /**
     * List feeds.
     *
     * Optional `content_type` query arg (`feed` | `likebox`) filters the list
     * so the dashboard Feeds / Like Box tabs stay separate.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function get_feeds( $request ) {
        $args = array(
            'status'       => $request->get_param( 'status' ),
            'content_type' => $request->get_param( 'content_type' ),
        );
        $feeds = ESF_Facebook_Feed_Repository::get_instance()->get_all( $args );
        $out = array();
        foreach ( $feeds as $feed ) {
            $out[] = self::format_feed( $feed );
        }
        return rest_ensure_response( $out );
    }

    /**
     * Get one feed.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function get_feed( $request ) {
        $feed = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( (int) $request['id'] );
        if ( !$feed ) {
            return new WP_Error('not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        return rest_ensure_response( self::format_feed( $feed ) );
    }

    /**
     * Create feed.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function create_feed( $request ) {
        $params = $request->get_json_params();
        if ( !is_array( $params ) ) {
            $params = $request->get_params();
        }
        $content_type = ( isset( $params['content_type'] ) ? sanitize_key( (string) $params['content_type'] ) : 'feed' );
        if ( 'likebox' === $content_type ) {
            return new WP_Error('unsupported_content_type', __( 'Like Box is not saved as a feed. Use the Like Box tab to generate a shortcode, or append a Like Box below a feed in Design.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $page_id = ( isset( $params['page_id'] ) ? (int) $params['page_id'] : 0 );
        $page = ( $page_id > 0 ? ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_id ) : null );
        $name = ( isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '' );
        if ( '' === $name && $page ) {
            $name = ESF_Feed_Name::build_auto_feed_name_for_source(
                $page,
                ( isset( $params['feed_type'] ) ? (string) $params['feed_type'] : 'timeline' ),
                ( isset( $params['source_id'] ) ? (string) $params['source_id'] : '' ),
                self::get_feed_name_config()
            );
        }
        if ( '' === $name ) {
            $name = __( 'Facebook Feed', 'easy-facebook-likebox' );
        }
        $settings = ( isset( $params['settings'] ) && is_array( $params['settings'] ) ? $params['settings'] : array() );
        $params['name'] = $name;
        $params['settings'] = ESF_Feed_Name::set_name_meta( $settings, 'auto', $page_id );
        $id = ESF_Facebook_Feed_Repository::get_instance()->create( ( is_array( $params ) ? $params : array() ) );
        if ( false === $id ) {
            return new WP_Error('create_failed', __( 'Could not create feed.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $feed = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $id );
        if ( method_exists( __CLASS__, 'maybe_refresh_live_status_for_feed__premium_only' ) ) {
            self::maybe_refresh_live_status_for_feed__premium_only( $feed );
        }
        if ( function_exists( 'esf_review_request_record_milestone' ) ) {
            esf_review_request_record_milestone( 'feed_saved', 'facebook' );
        }
        return rest_ensure_response( self::format_feed( $feed ) );
    }

    /**
     * Update feed.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function update_feed( $request ) {
        $id = (int) $request['id'];
        $repo = ESF_Facebook_Feed_Repository::get_instance();
        $feed = $repo->get_by_id( $id );
        if ( !$feed ) {
            return new WP_Error('not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        $params = $request->get_json_params();
        if ( !is_array( $params ) ) {
            $params = $request->get_params();
        }
        if ( is_array( $params ) && isset( $params['content_type'] ) && 'likebox' === sanitize_key( (string) $params['content_type'] ) ) {
            return new WP_Error('unsupported_content_type', __( 'Like Box is not saved as a feed. Use the Like Box tab to generate a shortcode, or append a Like Box below a feed in Design.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $data = array();
        $current_settings = self::decode_feed_settings( $feed );
        $next_settings = $current_settings;
        $name_mode = ESF_Feed_Name::get_name_mode( $current_settings );
        $current_name = ( isset( $feed->name ) ? sanitize_text_field( (string) $feed->name ) : '' );
        $current_page = ( isset( $feed->page_id ) ? (int) $feed->page_id : 0 );
        $current_feed_type = ( isset( $feed->feed_type ) ? (string) $feed->feed_type : 'timeline' );
        $current_source_id = ( isset( $feed->source_id ) ? (string) $feed->source_id : '' );
        $next_page = $current_page;
        $next_feed_type = $current_feed_type;
        $next_source_id = $current_source_id;
        $page_changed = false;
        $feed_type_changed = false;
        $source_id_changed = false;
        $name_updated = false;
        foreach ( array(
            'name',
            'page_id',
            'feed_type',
            'source_id',
            'status',
            'settings'
        ) as $key ) {
            if ( null === $request->get_param( $key ) ) {
                continue;
            }
            if ( 'settings' === $key ) {
                $incoming_settings = $request->get_param( $key );
                if ( is_array( $incoming_settings ) ) {
                    $next_settings = self::merge_incoming_settings( $current_settings, $incoming_settings );
                    $data[$key] = $next_settings;
                    $name_mode = ESF_Feed_Name::get_name_mode( $next_settings );
                }
                continue;
            }
            if ( 'page_id' === $key ) {
                $page_id = (int) $request->get_param( $key );
                if ( $page_id > 0 && !ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_id ) ) {
                    return new WP_Error('invalid_page', __( 'Selected Page not found.', 'easy-facebook-likebox' ), array(
                        'status' => 400,
                    ));
                }
                $data[$key] = ( $page_id > 0 ? $page_id : null );
                $next_page = $page_id;
                $page_changed = $current_page !== $next_page;
                continue;
            }
            $value = sanitize_text_field( (string) $request->get_param( $key ) );
            if ( 'feed_type' === $key ) {
                $next_feed_type = $value;
                $feed_type_changed = $current_feed_type !== $value;
            }
            if ( 'source_id' === $key ) {
                $next_source_id = $value;
                $source_id_changed = $current_source_id !== $value;
            }
            $data[$key] = $value;
            if ( 'name' === $key ) {
                $name_updated = $value !== $current_name;
            }
        }
        $source_context_changed = $page_changed || $feed_type_changed || $source_id_changed;
        if ( ESF_Feed_Name::should_switch_to_manual_mode( $name_updated, $name_mode, $source_context_changed ) ) {
            $next_settings = ESF_Feed_Name::set_name_meta( $next_settings, 'manual', $next_page );
            $data['settings'] = $next_settings;
            $name_mode = 'manual';
        }
        $page = ( $next_page > 0 ? ESF_Facebook_Page_Repository::get_instance()->get_by_id( $next_page ) : null );
        ESF_Feed_Name::apply_auto_name_on_source_change(
            $data,
            $next_settings,
            $name_mode,
            $page,
            $next_feed_type,
            $next_source_id,
            $next_page,
            array_merge( self::get_feed_name_config(), array(
                'source_context_changed' => $source_context_changed,
            ) )
        );
        $ok = $repo->update( $id, $data );
        if ( !$ok ) {
            return new WP_Error('update_failed', __( 'Could not update feed.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $feed = $repo->get_by_id( $id );
        if ( method_exists( __CLASS__, 'maybe_refresh_live_status_for_feed__premium_only' ) ) {
            self::maybe_refresh_live_status_for_feed__premium_only( $feed );
        }
        return rest_ensure_response( self::format_feed( $feed ) );
    }

    /**
     * Delete feed.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function delete_feed( $request ) {
        $id = (int) $request['id'];
        $ok = ESF_Facebook_Feed_Repository::get_instance()->delete( $id );
        if ( !$ok ) {
            return new WP_Error('delete_failed', __( 'Could not delete feed.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        return rest_ensure_response( array(
            'deleted' => true,
            'id'      => $id,
        ) );
    }

    /**
     * Duplicate feed.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function duplicate_feed( $request ) {
        $id = (int) $request['id'];
        $repo = ESF_Facebook_Feed_Repository::get_instance();
        $feed = $repo->get_by_id( $id );
        if ( !$feed ) {
            return new WP_Error('not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        $new_id = $repo->create( array(
            'name'         => (( isset( $feed->name ) ? (string) $feed->name : 'Feed' )) . ' (copy)',
            'page_id'      => ( isset( $feed->page_id ) ? $feed->page_id : null ),
            'content_type' => ( isset( $feed->content_type ) ? (string) $feed->content_type : 'feed' ),
            'feed_type'    => ( isset( $feed->feed_type ) ? (string) $feed->feed_type : 'timeline' ),
            'source_id'    => ( isset( $feed->source_id ) ? $feed->source_id : null ),
            'settings'     => ( isset( $feed->settings ) && is_array( $feed->settings ) ? $feed->settings : array() ),
            'status'       => ( isset( $feed->status ) ? (string) $feed->status : 'active' ),
        ) );
        if ( false === $new_id ) {
            return new WP_Error('duplicate_failed', __( 'Could not duplicate feed.', 'easy-facebook-likebox' ), array(
                'status' => 500,
            ));
        }
        $created = $repo->get_by_id( $new_id );
        if ( method_exists( __CLASS__, 'maybe_refresh_live_status_for_feed__premium_only' ) ) {
            self::maybe_refresh_live_status_for_feed__premium_only( $created );
        }
        return rest_ensure_response( self::format_feed( $created ) );
    }

    /**
     * Clear cache for a feed's page.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function clear_feed_cache( $request ) {
        $id = (int) $request['id'];
        $feed = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $id );
        if ( !$feed ) {
            return new WP_Error('not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        $deleted = 0;
        if ( class_exists( 'ESF_Facebook_Cache' ) && !empty( $feed->page_id ) ) {
            $deleted = (int) ESF_Facebook_Cache::flush_page_cache( (int) $feed->page_id );
        }
        // Repopulate live status after a manual clear when Live Display is on.
        if ( method_exists( __CLASS__, 'maybe_refresh_live_status_for_feed__premium_only' ) ) {
            self::maybe_refresh_live_status_for_feed__premium_only( $feed );
        }
        return rest_ensure_response( array(
            'cleared' => true,
            'deleted' => $deleted,
        ) );
    }

    /**
     * Pin a disconnected feed's cached posts permanently (no TTL expiry).
     *
     * @since 6.7.9
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function pin_feed_cache( $request ) {
        $id = (int) $request['id'];
        $feed = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $id );
        if ( !$feed ) {
            return new WP_Error('not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        if ( empty( $feed->page_id ) ) {
            return new WP_Error('no_page', __( 'This feed has no linked Page to pin cache for.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $pinned = ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ? ESF_Feed_Disconnected_Cache::pin_feed_cache_for_module( 'facebook', $id ) : class_exists( 'ESF_Facebook_Cache' ) && false !== ESF_Facebook_Cache::pin_feed_cache( $id ) );
        if ( !$pinned ) {
            return new WP_Error('pin_failed', __( 'Could not pin feed cache.', 'easy-facebook-likebox' ), array(
                'status' => 500,
            ));
        }
        $updated = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $id );
        return rest_ensure_response( array(
            'pinned' => true,
            'feed'   => ( $updated ? self::format_feed( $updated ) : null ),
        ) );
    }

    /**
     * Explicitly refresh live_videos status for a Prefer live / Live only feed.
     *
     * Hits Graph now (admin-only), writes the live cache row, and returns
     * whether the Page is currently broadcasting — Instagram “Sync stories”
     * parity for Facebook Live Display.
     *
     * @since 6.7.9
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function sync_feed_live( $request ) {
        $id = (int) $request['id'];
        $feed = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $id );
        if ( !$feed ) {
            return new WP_Error('not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        if ( function_exists( 'esf_facebook_has_facebook_plan' ) && !esf_facebook_has_facebook_plan() ) {
            return new WP_Error('esf_facebook_pro_required', __( 'Syncing live status requires Facebook Pro.', 'easy-facebook-likebox' ), array(
                'status' => 403,
            ));
        }
        $settings = ( isset( $feed->settings ) && is_array( $feed->settings ) ? $feed->settings : array() );
        $feed_cfg = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
        $uses_live = ( function_exists( 'esf_facebook_feed_uses_live_status' ) ? esf_facebook_feed_uses_live_status( $feed_cfg ) : !empty( $feed_cfg['live_stream_only'] ) );
        if ( !$uses_live ) {
            return new WP_Error('esf_facebook_live_display_off', __( 'Enable Prefer live or Live only Display before syncing.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $page_row_id = ( isset( $feed->page_id ) ? (int) $feed->page_id : 0 );
        if ( $page_row_id <= 0 ) {
            return new WP_Error('esf_facebook_no_page', __( 'Connect a Facebook Page before syncing live status.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        if ( !class_exists( 'ESF_Facebook_Renderer' ) ) {
            return new WP_Error('esf_facebook_renderer_missing', __( 'Live sync is unavailable.', 'easy-facebook-likebox' ), array(
                'status' => 500,
            ));
        }
        $renderer = new ESF_Facebook_Renderer();
        if ( !method_exists( $renderer, 'refresh_live_status_for_page__premium_only' ) ) {
            return new WP_Error('esf_facebook_live_unavailable', __( 'Live sync is unavailable.', 'easy-facebook-likebox' ), array(
                'status' => 403,
            ));
        }
        $video = $renderer->refresh_live_status_for_page__premium_only( $page_row_id, $id );
        $is_live = null !== $video && is_array( $video );
        return rest_ensure_response( array(
            'success' => true,
            'id'      => $id,
            'is_live' => $is_live,
            'video'   => ( $is_live ? $video : null ),
        ) );
    }

    /**
     * Feed auto-naming config for Facebook Pages.
     *
     * @since 6.7.9
     * @return array<string,mixed>
     */
    private static function get_feed_name_config() {
        return array(
            'account_fallback' => __( 'Facebook Feed', 'easy-facebook-likebox' ),
            'name_builder'     => static function (
                $page,
                $feed_type,
                $source_id,
                $config
            ) {
                unset($feed_type, $source_id);
                return ESF_Feed_Name::build_page_name_feed_name( $page, ( isset( $config['account_fallback'] ) ? (string) $config['account_fallback'] : __( 'Facebook Feed', 'easy-facebook-likebox' ) ) );
            },
        );
    }

    /**
     * Decode stored feed settings for REST update logic.
     *
     * @since 6.7.9
     * @param object $feed Feed row.
     * @return array<string,mixed>
     */
    private static function decode_feed_settings( $feed ) {
        if ( !is_object( $feed ) ) {
            return ESF_Facebook_Feed_Repository::normalize_settings( array() );
        }
        $settings = ( isset( $feed->settings ) ? $feed->settings : array() );
        return ESF_Facebook_Feed_Repository::normalize_settings( $settings );
    }

    /**
     * Merge incoming customize settings without dropping unknown keys.
     *
     * @since 6.7.9
     * @param array<string,mixed> $current  Current settings.
     * @param array<string,mixed> $incoming Incoming settings.
     * @return array<string,mixed>
     */
    private static function merge_incoming_settings( array $current, array $incoming ) {
        $merged = array_replace_recursive( $current, $incoming );
        /*
         * array_replace_recursive keeps leftover numeric keys when shrinking
         * list settings (e.g. page_ids [1,2] + [1] => [1,2]). Force-replace
         * Multifeed page_ids from the incoming payload whenever it is present.
         */
        if ( isset( $incoming['feed'] ) && is_array( $incoming['feed'] ) && array_key_exists( 'page_ids', $incoming['feed'] ) ) {
            if ( !isset( $merged['feed'] ) || !is_array( $merged['feed'] ) ) {
                $merged['feed'] = array();
            }
            $merged['feed']['page_ids'] = ( is_array( $incoming['feed']['page_ids'] ) ? $incoming['feed']['page_ids'] : array() );
        }
        if ( isset( $incoming['meta'] ) && is_array( $incoming['meta'] ) ) {
            if ( !isset( $merged['meta'] ) || !is_array( $merged['meta'] ) ) {
                $merged['meta'] = array();
            }
            $merged['meta'] = array_replace( $current['meta'] ?? array(), $incoming['meta'] );
        }
        return self::preserve_rest_managed_settings( $current, $merged );
    }

    /**
     * Keep REST-owned settings keys when the feed editor autosaves customize panels.
     *
     * Moderation is written by the shared moderation REST controller; feed
     * autosave must not wipe `settings.moderation`.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed> $current_settings Stored settings.
     * @param array<string,mixed> $incoming_settings  Incoming merged settings.
     * @return array<string,mixed>
     */
    private static function preserve_rest_managed_settings( array $current_settings, array $incoming_settings ) {
        /**
         * Settings keys owned by dedicated REST controllers (not feed autosave).
         *
         * @since 6.7.9
         *
         * @param array<int,string> $keys Keys to preserve from the stored feed.
         */
        $managed_keys = apply_filters( 'esf_feed_settings_rest_managed_keys', array('moderation') );
        if ( !is_array( $managed_keys ) ) {
            return $incoming_settings;
        }
        foreach ( $managed_keys as $managed_key ) {
            $managed_key = sanitize_key( (string) $managed_key );
            if ( '' === $managed_key ) {
                continue;
            }
            if ( isset( $current_settings[$managed_key] ) && is_array( $current_settings[$managed_key] ) ) {
                $incoming_settings[$managed_key] = $current_settings[$managed_key];
            }
        }
        return $incoming_settings;
    }

    /**
     * Format feed for REST.
     *
     * @since 6.7.9
     * @param object|null $feed Feed row.
     * @return array<string,mixed>
     */
    private static function format_feed( $feed ) {
        if ( !is_object( $feed ) ) {
            return array();
        }
        $preview_is_cold = ( function_exists( 'esf_facebook_feed_preview_is_cold' ) ? esf_facebook_feed_preview_is_cold( $feed ) : true );
        return array(
            'id'              => ( isset( $feed->id ) ? (int) $feed->id : 0 ),
            'name'            => ( isset( $feed->name ) ? (string) $feed->name : '' ),
            'page_id'         => ( isset( $feed->page_id ) ? (int) $feed->page_id : null ),
            'content_type'    => ( isset( $feed->content_type ) ? (string) $feed->content_type : 'feed' ),
            'feed_type'       => ( isset( $feed->feed_type ) ? (string) $feed->feed_type : 'timeline' ),
            'source_id'       => ( isset( $feed->source_id ) ? (string) $feed->source_id : '' ),
            'settings'        => ( isset( $feed->settings ) && is_array( $feed->settings ) ? $feed->settings : ESF_Facebook_Feed_Repository::normalize_settings( array() ) ),
            'status'          => ( isset( $feed->status ) ? (string) $feed->status : 'active' ),
            'created_at'      => ( isset( $feed->created_at ) ? (string) $feed->created_at : '' ),
            'updated_at'      => ( isset( $feed->updated_at ) ? (string) $feed->updated_at : '' ),
            'preview_is_cold' => (bool) $preview_is_cold,
        );
    }

}
