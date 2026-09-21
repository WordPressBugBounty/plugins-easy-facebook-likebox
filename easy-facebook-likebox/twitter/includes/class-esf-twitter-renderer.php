<?php

/**
 * Twitter Feed Renderer
 *
 * Orchestrates feed loading, tweet fetching (with DB cache), and layout rendering.
 * Entry point called by the shortcode and the REST preview endpoint.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter
 * @since 6.7.6
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
use EasySocialFeed\Layouts\Primitives\MissingFeedState;
use EasySocialFeed\Layouts\ValueObjects\MissingFeedContext;
use EasySocialFeed\Layouts\Renderer as SharedRenderer;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Twitter\Layouts\AccountMapper;
use EasySocialFeed\Twitter\Layouts\PostMapper;
/**
 * Class ESF_Twitter_Renderer
 *
 * @since 6.7.6
 */
class ESF_Twitter_Renderer {
    /**
     * Module slug for shared missing-feed placeholders.
     *
     * @since 6.11.0
     * @var string
     */
    const MODULE = 'twitter';

    /**
     * Whether the historical BEM wrapper-class filter has been registered.
     *
     * @since 6.14.0
     * @var bool
     */
    private static $bem_wrapper_filter_registered = false;

    /**
     * Current render context: `preview` or `frontend`.
     *
     * @since 6.14.1
     * @var string
     */
    private $media_context = 'frontend';

    /**
     * Set the local-media download context for subsequent tweet fetches.
     *
     * @since 6.9.0
     *
     * @param string $media_context `preview` or `frontend`.
     * @return void
     */
    public function set_media_context( $media_context ) {
        $this->media_context = ( in_array( $media_context, array('preview', 'frontend'), true ) ? $media_context : 'frontend' );
    }

    /**
     * Render a feed by its internal ID.
     *
     * @since 6.7.6
     * @param int    $feed_id             Feed ID.
     * @param array  $settings_override   Optional settings to merge (e.g. for live preview).
     * @param bool   $enqueue_assets      Whether to enqueue CSS/JS. Set false for REST preview.
     * @param int    $account_id_override Optional account ID override for unsaved source changes.
     * @param string $media_context       `preview` (dashboard) or `frontend`.
     * @return string HTML output, or empty string on failure.
     */
    public function render(
        $feed_id,
        $settings_override = array(),
        $enqueue_assets = true,
        $account_id_override = 0,
        $media_context = 'frontend'
    ) {
        $this->media_context = ( in_array( $media_context, array('preview', 'frontend'), true ) ? $media_context : 'frontend' );
        $rendered = $this->render_internal( (int) $feed_id, ( is_array( $settings_override ) ? $settings_override : array() ), (int) $account_id_override );
        if ( '' === $rendered['html'] ) {
            return '';
        }
        if ( $enqueue_assets && null !== $rendered['definition'] ) {
            ( new SharedRenderer() )->enqueue_assets( $rendered['definition'] );
        }
        $html = $rendered['html'];
        if ( !empty( $rendered['admin_notice_html'] ) ) {
            $html = (string) $rendered['admin_notice_html'] . $html;
        }
        return $html;
    }

    /**
     * Resolve AccountSummary for a live or disconnected account.
     *
     * @since 6.7.9
     *
     * @param int         $account_id  Internal account id.
     * @param object|null $account_row Live account row when connected.
     * @return AccountSummary|null
     */
    public static function resolve_twitter_account( $account_id, $account_row = null ) : ?AccountSummary {
        $account_id = (int) $account_id;
        if ( $account_id <= 0 ) {
            return null;
        }
        if ( is_object( $account_row ) ) {
            return AccountMapper::from_row( $account_row );
        }
        $snapshot = ( class_exists( 'ESF_Twitter_Cache' ) ? ESF_Twitter_Cache::get_account_profile_snapshot( $account_id ) : null );
        if ( !is_array( $snapshot ) || empty( $snapshot ) ) {
            return null;
        }
        return AccountMapper::from_row( (object) $snapshot );
    }

    /**
     * Whether a feed still has cached tweet items (including expired rows).
     *
     * @since 6.7.9
     *
     * @param object $feed_obj Feed object.
     * @return bool
     */
    public static function feed_has_posts_cache( $feed_obj ) : bool {
        if ( !class_exists( 'ESF_Twitter_Cache' ) || !is_object( $feed_obj ) ) {
            return false;
        }
        // For multifeed, check if any account has cache.
        if ( function_exists( 'esf_twitter_is_multifeed_feed__premium_only' ) && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && esf_twitter_is_multifeed_feed__premium_only( $feed_obj ) ) {
            $settings = ( is_string( $feed_obj->settings ) ? json_decode( $feed_obj->settings, true ) : (array) $feed_obj->settings );
            $account_ids = ( isset( $settings['feed']['account_ids'] ) && is_array( $settings['feed']['account_ids'] ) ? $settings['feed']['account_ids'] : array() );
            foreach ( $account_ids as $id ) {
                $id = (int) $id;
                if ( $id > 0 && self::account_has_cache( $id, (int) $feed_obj->id ) ) {
                    return true;
                }
            }
            return false;
        }
        $account_id = ( isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0 );
        if ( $account_id <= 0 ) {
            return false;
        }
        return self::account_has_cache( $account_id, (int) $feed_obj->id );
    }

    /**
     * Whether a single account has any cache (including expired rows).
     *
     * @since 6.15.0
     *
     * @param int $account_id Account ID.
     * @param int $feed_id    Feed ID (for legacy keys).
     * @return bool
     */
    private static function account_has_cache( $account_id, $feed_id = 0 ) {
        $account_id = (int) $account_id;
        if ( $account_id <= 0 ) {
            return false;
        }
        $cache_key = ( function_exists( 'esf_twitter_tweets_cache_key' ) ? esf_twitter_tweets_cache_key( $account_id ) : 'esf_tw_tweets_' . $account_id );
        if ( '' === $cache_key ) {
            return false;
        }
        $cached = ESF_Twitter_Cache::get( $cache_key );
        if ( !is_array( $cached ) ) {
            $cached = ESF_Twitter_Cache::get_stale( $cache_key );
        }
        return is_array( $cached ) && !empty( $cached );
    }

    /**
     * Internal render implementation using SharedRenderer.
     *
     * @param int   $feed_id            Feed ID.
     * @param array $settings_override  Settings override.
     * @param int   $account_id_override Account override.
     * @return array{html:string,renderer_output:array,definition:?\EasySocialFeed\Layouts\ValueObjects\LayoutDefinition,admin_notice_html?:string}
     */
    private function render_internal( int $feed_id, array $settings_override, int $account_id_override ) : array {
        self::ensure_bem_wrapper_filter();
        $empty = array(
            'html'              => '',
            'renderer_output'   => array(
                'html'        => '',
                'assets'      => array(),
                'inline_css'  => '',
                'layout_slug' => '',
            ),
            'definition'        => null,
            'admin_notice_html' => '',
        );
        if ( $feed_id <= 0 ) {
            return $empty;
        }
        $feed_repo = ESF_Twitter_Feed_Repository::get_instance();
        $feed_row = $feed_repo->get_by_id( $feed_id );
        if ( !$feed_row ) {
            return array(
                'html'            => $this->render_missing_feed( $feed_id ),
                'renderer_output' => $empty['renderer_output'],
                'definition'      => null,
            );
        }
        $saved = json_decode( $feed_row->settings, true );
        $settings = ESF_Twitter_Feed_Repository::merge_settings_with_defaults( ( is_array( $saved ) ? $saved : array() ) );
        if ( !empty( $settings_override ) ) {
            $settings = ESF_Twitter_Feed_Repository::merge_settings_with_defaults( array_merge( $settings, $settings_override ) );
        }
        $feed_obj = (object) array(
            'id'         => $feed_row->id,
            'name'       => $feed_row->name,
            'account_id' => (int) $feed_row->account_id,
            'feed_type'  => $feed_row->feed_type,
            'source_id'  => $feed_row->source_id,
            'settings'   => $settings,
        );
        if ( $account_id_override > 0 ) {
            $feed_obj->account_id = $account_id_override;
        }
        $account_repo = ESF_Twitter_Account_Repository::get_instance();
        $account_row = $account_repo->get_by_id( $feed_obj->account_id );
        $source_disconnected = ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ? ESF_Feed_Disconnected_Cache::account_source_is_disconnected( $feed_row, $account_row ) : $feed_obj->account_id > 0 && null === $account_row );
        $has_posts_cache = ( $source_disconnected ? self::feed_has_posts_cache( $feed_obj ) : false );
        $fetch_error = null;
        // Check if this is a multifeed (Pro feature).
        $is_multifeed = function_exists( 'esf_twitter_is_multifeed_feed__premium_only' ) && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && esf_twitter_is_multifeed_feed__premium_only( $feed_obj );
        if ( $is_multifeed ) {
            // Multifeed: fetch and merge tweets from multiple accounts.
            $tweets = ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && method_exists( $this, 'get_tweets_for_multifeed__premium_only' ) ? $this->get_tweets_for_multifeed__premium_only( $feed_id, $feed_obj ) : array() );
            $has_local_cache = self::feed_has_posts_cache( $feed_obj );
            $account = null;
            // Multifeed uses per-tweet account info.
        } else {
            // Single account feed.
            $account = self::resolve_twitter_account( (int) $feed_obj->account_id, $account_row );
            if ( null === $account && !$has_posts_cache ) {
                return $empty;
            }
            // Fetch tweets
            $tweets = $this->get_tweets_for_feed( $feed_id, $feed_obj, $account_row );
            if ( is_wp_error( $tweets ) ) {
                $fetch_error = $tweets;
                $tweets = array();
            }
            $has_local_cache = self::feed_has_posts_cache( $feed_obj );
        }
        // Map tweets to Post value objects
        $posts = array();
        if ( $is_multifeed ) {
            // Multifeed: resolve account per tweet from _esf_account_id
            foreach ( $tweets as $tweet ) {
                if ( !is_array( $tweet ) ) {
                    continue;
                }
                $tweet_account_id = ( isset( $tweet['_esf_account_id'] ) ? (int) $tweet['_esf_account_id'] : 0 );
                if ( $tweet_account_id <= 0 ) {
                    continue;
                }
                $tweet_account_row = $account_repo->get_by_id( $tweet_account_id );
                $tweet_account = self::resolve_twitter_account( $tweet_account_id, $tweet_account_row );
                if ( null === $tweet_account ) {
                    continue;
                }
                $post = PostMapper::from_api_node( $tweet, $tweet_account );
                if ( null !== $post ) {
                    $posts[] = $post;
                }
            }
        } else {
            // Single account: use the resolved account
            foreach ( $tweets as $tweet ) {
                if ( !is_array( $tweet ) ) {
                    continue;
                }
                $post = PostMapper::from_api_node( $tweet, $account );
                if ( null !== $post ) {
                    $posts[] = $post;
                }
            }
        }
        // Use shared renderer
        $renderer_output = ( new SharedRenderer() )->render(
            'twitter',
            $feed_obj,
            $account,
            $posts,
            $settings_override
        );
        $definition = null;
        if ( '' !== $renderer_output['layout_slug'] ) {
            $registry = \EasySocialFeed\Layouts\LayoutRegistry::for( 'twitter' );
            $definition = $registry->get( $renderer_output['layout_slug'] );
        }
        $notice = null;
        $admin_notice_html = '';
        if ( $source_disconnected && $has_local_cache && class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
            $cache_pinned = ESF_Feed_Disconnected_Cache::is_feed_cache_effectively_pinned( 'twitter', (int) $feed_row->id, !empty( $settings['feed']['cache_pinned'] ) );
            $admin_notice_html = ESF_Feed_Disconnected_Cache::get_admin_notice_html( 'twitter', (int) $feed_row->id, array(
                'cache_pinned' => $cache_pinned,
            ) );
            $notice = ESF_Feed_Disconnected_Cache::get_notice_message( $cache_pinned );
        } elseif ( $fetch_error instanceof WP_Error && 'preview' === $this->media_context ) {
            $notice = $fetch_error->get_error_message();
        }
        $html = $renderer_output['html'];
        // Preview has no separate notice channel for token/API failures — bake
        // the message above the empty feed so customize is not a silent blank.
        if ( is_string( $notice ) && '' !== $notice && 'preview' === $this->media_context && empty( $admin_notice_html ) ) {
            $html = '<div class="esf-tw-feed__fetch-notice" role="alert"><p>' . esc_html( $notice ) . '</p></div>' . $html;
        }
        return array(
            'html'              => $html,
            'renderer_output'   => array_merge( $renderer_output, array(
                'html' => $html,
            ) ),
            'definition'        => $definition,
            'has_local_cache'   => $has_local_cache,
            'notice'            => $notice,
            'admin_notice_html' => $admin_notice_html,
        );
    }

    /**
     * Get tweets for a feed (from integrator payload, cache, or API).
     *
     * Pipeline:
     *   1. Allow integrators to short-circuit via `esf_twitter_render_tweets_payload`.
     *   2. When no override, read from {@see ESF_Twitter_Cache} or fetch via API.
     *   3. Optionally apply per-feed moderation (Pro) after cache/API — never when writing cache.
     *
     * Public for use by the cron cache-refresh job and Load More REST endpoint.
     *
     * @since 6.7.6
     * @param int    $feed_id           Feed ID (used in the cache key).
     * @param object $feed_obj          Feed object.
     * @param object $account           Account object.
     * @param bool   $apply_moderation  Whether to apply hide/show rules (false for moderate grid).
     * @return array|WP_Error Normalized tweet array, or WP_Error.
     */
    public function get_tweets_for_feed(
        $feed_id,
        $feed_obj,
        $account,
        $apply_moderation = true
    ) {
        $preset = array(
            'tweets'          => array(),
            'has_local_cache' => false,
        );
        /**
         * Filter the Twitter tweet payload before cache/API fetch.
         *
         * Returning tweets skips the cache and API fetch.
         *
         * Listeners may return either:
         *   - `array{tweets:array,has_local_cache?:bool}` (preferred shape), or
         *   - a flat `array<int, array>` of normalized tweet items.
         *
         * @since 6.7.8
         *
         * @param array  $value    Default empty payload.
         * @param int    $feed_id  Feed ID.
         * @param object $feed_obj Feed object.
         * @param object $account  Owning account row.
         */
        $payload = apply_filters(
            'esf_twitter_render_tweets_payload',
            $preset,
            (int) $feed_id,
            $feed_obj,
            $account
        );
        if ( function_exists( 'esf_resolve_render_payload_override' ) ) {
            $override = esf_resolve_render_payload_override( $payload, 'tweets' );
            if ( $override['short_circuited'] ) {
                return $this->maybe_filter_moderated_tweets(
                    $override['items'],
                    $feed_id,
                    $feed_obj,
                    $account,
                    $apply_moderation
                );
            }
        }
        $account_id = (int) $feed_obj->account_id;
        $account_row = ( is_object( $account ) ? $account : null );
        if ( $account_id > 0 && null === $account_row && class_exists( 'ESF_Twitter_Account_Repository' ) ) {
            $account_row = ESF_Twitter_Account_Repository::get_instance()->get_by_id( $account_id );
        }
        $source_disconnected = $account_id > 0 && null === $account_row;
        $cache_key = ( function_exists( 'esf_twitter_tweets_cache_key' ) ? esf_twitter_tweets_cache_key( $account_id ) : 'esf_tw_tweets_' . $account_id );
        $meta_key = ( '' !== $cache_key ? $cache_key . '_meta' : '' );
        $cached = $this->read_tweets_cache( $cache_key, (int) $feed_id, $account_id );
        // Empty arrays are a cache miss — never treat them as a warm timeline.
        if ( is_array( $cached ) && !empty( $cached ) ) {
            // Cached rows that still store local upload URLs need a fresh fetch.
            if ( $this->tweets_cache_contains_localized_urls( $cached ) ) {
                ESF_Twitter_Cache::flush_account_cache( $account_id );
            } else {
                $tweets = $this->prepare_tweets_for_display( $cached, $account_id, $feed_obj );
                return $this->maybe_filter_moderated_tweets(
                    $tweets,
                    $feed_id,
                    $feed_obj,
                    $account,
                    $apply_moderation
                );
            }
        }
        if ( $source_disconnected ) {
            return array();
        }
        if ( !is_object( $account ) ) {
            return new WP_Error('account_missing', __( 'X account is unavailable.', 'easy-facebook-likebox' ));
        }
        $api_service = ESF_Twitter_API_Service::get_instance();
        $settings = ( isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array() );
        $feed_cfg = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
        $tweet_count = ( isset( $feed_cfg['tweet_count'] ) ? max( 5, min( ESF_Twitter_API_Service::MAX_TWEET_FETCH, (int) $feed_cfg['tweet_count'] ) ) : 20 );
        $exclude = array();
        if ( !empty( $feed_cfg['exclude_retweets'] ) ) {
            $exclude[] = 'retweets';
        }
        if ( !empty( $feed_cfg['exclude_replies'] ) ) {
            $exclude[] = 'replies';
        }
        $x_user_id = ( isset( $account->x_user_id ) ? (string) $account->x_user_id : '' );
        if ( '' === $x_user_id ) {
            return new WP_Error('x_user_id_missing', __( 'This X account is missing its platform user id. Reconnect the account.', 'easy-facebook-likebox' ));
        }
        if ( 'public' === $account->account_type ) {
            // Pro: use ESF proxy with Bearer Token.
            $page = $api_service->fetch_public_timeline_page( $x_user_id, $tweet_count, $exclude );
        } else {
            // Connected account: use OAuth access token.
            $token_manager = ESF_Twitter_Token_Manager::get_instance();
            $access_token = $token_manager->get_valid_access_token( $account_id );
            if ( !$access_token ) {
                return new WP_Error('token_missing', __( 'Access token not available. Reconnect this X account to refresh tweets.', 'easy-facebook-likebox' ));
            }
            $page = $api_service->fetch_user_timeline_page(
                $x_user_id,
                $access_token,
                $tweet_count,
                $exclude
            );
        }
        if ( is_wp_error( $page ) ) {
            return $page;
        }
        $tweets = ( isset( $page['tweets'] ) && is_array( $page['tweets'] ) ? $page['tweets'] : array() );
        $next_token = ( isset( $page['next_token'] ) ? sanitize_text_field( (string) $page['next_token'] ) : '' );
        if ( is_array( $tweets ) && !empty( $tweets ) ) {
            $this->cache_account_tweets(
                $account,
                $account_id,
                $tweets,
                $next_token,
                $cache_key,
                $meta_key
            );
            if ( class_exists( 'ESF_Twitter_Local_Media' ) && (int) $feed_id > 0 ) {
                ESF_Twitter_Local_Media::schedule_warm_for_feed( (int) $feed_id, $account_id );
            }
            $tweets = $this->prepare_tweets_for_display( $tweets, $account_id, $feed_obj );
            return $this->maybe_filter_moderated_tweets(
                $tweets,
                $feed_id,
                $feed_obj,
                $account,
                $apply_moderation
            );
        }
        return array();
    }

    /**
     * Store a fetched timeline page in the account-scoped tweets cache.
     *
     * TTL honors {@see esf_twitter_resolve_tweets_cache_ttl()} so BYOK
     * (manually pasted token) accounts keep refreshing on their own short,
     * plan-independent cadence instead of the managed `cache_duration`
     * setting. See `twitter/PLAN.md`.
     *
     * @since 6.16.0
     *
     * @param object|null $account    Account row (used to resolve the TTL).
     * @param int         $account_id Account ID.
     * @param array       $tweets     Normalized tweet items.
     * @param string      $next_token Pagination token for Load More, if any.
     * @param string      $cache_key  Account-scoped tweets cache key.
     * @param string      $meta_key   Account-scoped tweets metadata cache key.
     * @return int TTL (seconds) used to store the cache rows.
     */
    private function cache_account_tweets(
        $account,
        $account_id,
        array $tweets,
        $next_token,
        $cache_key,
        $meta_key
    ) {
        $ttl = ( function_exists( 'esf_twitter_resolve_tweets_cache_ttl' ) ? (int) esf_twitter_resolve_tweets_cache_ttl( $account ) : 43200 );
        if ( $ttl <= 0 ) {
            $ttl = 43200;
            // 12 hours.
        }
        // Store remote URLs. Local copies are built at render time.
        if ( '' !== $cache_key ) {
            ESF_Twitter_Cache::set(
                $cache_key,
                $tweets,
                $ttl,
                'api',
                $account_id
            );
        }
        if ( '' !== $meta_key ) {
            ESF_Twitter_Cache::set(
                $meta_key,
                array(
                    'next_token' => (string) $next_token,
                ),
                $ttl,
                'api',
                $account_id
            );
        }
        return $ttl;
    }

    /**
     * Force-refresh one account's cached timeline, bypassing the tweets cache.
     *
     * Used by the dashboard's manual "Refresh now" action. Fetches a fresh
     * page directly from X (OAuth-connected) or the ESF public-account proxy,
     * then re-stores the account-scoped cache with
     * {@see esf_twitter_resolve_tweets_cache_ttl()}. Callers are responsible
     * for plan/rate-limit gating (see `ESF_Twitter_API_Accounts::refresh_cache()`).
     *
     * @since 6.16.0
     *
     * @param object $account Account row.
     * @return array{success:bool,updated_at:string,expires_at:string}|WP_Error
     */
    public function force_refresh_account_tweets( $account ) {
        if ( !is_object( $account ) || empty( $account->id ) ) {
            return new WP_Error('invalid_account', __( 'Invalid account.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $account_id = (int) $account->id;
        $x_user_id = ( isset( $account->x_user_id ) ? (string) $account->x_user_id : '' );
        if ( '' === $x_user_id ) {
            return new WP_Error('missing_x_user_id', __( 'This account is missing its X user ID. Try reconnecting it.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $cache_key = ( function_exists( 'esf_twitter_tweets_cache_key' ) ? esf_twitter_tweets_cache_key( $account_id ) : '' );
        if ( '' === $cache_key ) {
            return new WP_Error('invalid_account', __( 'Invalid account.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        $api_service = ESF_Twitter_API_Service::get_instance();
        $tweet_count = 20;
        $account_type = ( isset( $account->account_type ) ? (string) $account->account_type : 'connected' );
        if ( 'public' === $account_type ) {
            $page = $api_service->fetch_public_timeline_page( $x_user_id, $tweet_count );
        } else {
            $token_manager = ESF_Twitter_Token_Manager::get_instance();
            $access_token = $token_manager->get_valid_access_token( $account_id );
            if ( !$access_token ) {
                return ( function_exists( 'esf_oauth_reconnect_required_error' ) ? esf_oauth_reconnect_required_error() : new WP_Error('no_access_token', __( 'Reconnect this account to refresh its feed.', 'easy-facebook-likebox' ), array(
                    'status' => 400,
                )) );
            }
            $page = $api_service->fetch_user_timeline_page( $x_user_id, $access_token, $tweet_count );
        }
        if ( is_wp_error( $page ) ) {
            return $page;
        }
        $tweets = ( isset( $page['tweets'] ) && is_array( $page['tweets'] ) ? $page['tweets'] : array() );
        if ( empty( $tweets ) ) {
            return new WP_Error('empty_timeline', __( 'No tweets were returned to refresh the feed with.', 'easy-facebook-likebox' ), array(
                'status' => 502,
            ));
        }
        $next_token = ( isset( $page['next_token'] ) ? sanitize_text_field( (string) $page['next_token'] ) : '' );
        $meta_key = $cache_key . '_meta';
        $ttl = $this->cache_account_tweets(
            $account,
            $account_id,
            $tweets,
            $next_token,
            $cache_key,
            $meta_key
        );
        if ( class_exists( 'ESF_Twitter_Feed_Repository' ) ) {
            foreach ( ESF_Twitter_Feed_Repository::get_instance()->get_by_account_id( $account_id ) as $feed_row ) {
                if ( class_exists( 'ESF_Twitter_Local_Media' ) && isset( $feed_row->id ) ) {
                    ESF_Twitter_Local_Media::schedule_warm_for_feed( (int) $feed_row->id, $account_id );
                }
            }
        }
        return array(
            'success'    => true,
            'updated_at' => current_time( 'mysql', true ),
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
        );
    }

    /* </fs_premium_only> */
    /**
     * Optionally apply Pro moderation and the public raw-tweet filter.
     *
     * @since 6.9.0
     *
     * @param array  $tweets           Normalized tweets.
     * @param int    $feed_id          Feed ID.
     * @param object $feed_obj         Feed object.
     * @param object $account          Account row.
     * @param bool   $apply_moderation Whether moderation should run.
     * @return array
     */
    private function maybe_filter_moderated_tweets(
        $tweets,
        $feed_id,
        $feed_obj,
        $account,
        $apply_moderation
    ) {
        if ( !is_array( $tweets ) ) {
            return array();
        }
        if ( $apply_moderation && function_exists( 'esf_twitter_apply_moderation_filter' ) ) {
            $tweets = esf_twitter_apply_moderation_filter( $tweets, (int) $feed_id );
        }
        /**
         * Filter normalized tweets after cache/API (and optional moderation).
         *
         * Prefer this over mutating the account-scoped tweets cache. The Moderate
         * admin grid passes `$apply_moderation = false` upstream so every tweet
         * remains available for selection.
         *
         * @since 6.9.0
         *
         * @param array  $tweets   Normalized tweet items.
         * @param object $feed_obj Feed object.
         * @param object $account  Owning account row.
         */
        $filtered = apply_filters(
            'esf_twitter_filter_raw_tweets',
            $tweets,
            $feed_obj,
            $account
        );
        return ( is_array( $filtered ) ? array_values( $filtered ) : array() );
    }

    /**
     * Swap in local media URLs for a tweet list (used by Load More).
     *
     * @since 6.9.5
     * @param array $tweets     Normalized tweet items.
     * @param int   $account_id Account ID.
     * @param int   $sync_limit Max new image downloads. `0` skips downloads.
     * @return array
     */
    public function localize_tweets_for_display( $tweets, $account_id = 0, $sync_limit = 0 ) {
        return $this->ensure_local_media_for_tweets(
            $tweets,
            $account_id,
            'frontend',
            max( 0, (int) $sync_limit )
        );
    }

    /**
     * Apply local media URLs for the current render context.
     *
     * @since 6.14.1
     * @param array       $tweets     Normalized tweet items.
     * @param int         $account_id Account ID.
     * @param object|null $feed_obj   Feed object.
     * @return array
     */
    private function prepare_tweets_for_display( $tweets, $account_id, $feed_obj = null ) {
        return $this->ensure_local_media_for_tweets(
            $tweets,
            $account_id,
            $this->media_context,
            $this->resolve_local_media_sync_limit( $feed_obj )
        );
    }

    /**
     * How many image downloads this request may start.
     *
     * Preview downloads the current page. The frontend only uses files already
     * on disk; the rest are filled by the warm cron.
     *
     * @since 6.14.1
     * @param object|null $feed_obj Feed object.
     * @return int
     */
    private function resolve_local_media_sync_limit( $feed_obj ) {
        if ( 'frontend' === $this->media_context ) {
            return 0;
        }
        $settings = ( is_object( $feed_obj ) && isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array() );
        $feed_cfg = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
        $count = ( isset( $feed_cfg['per_page'] ) ? (int) $feed_cfg['per_page'] : 0 );
        if ( $count <= 0 ) {
            $count = ( isset( $feed_cfg['tweet_count'] ) ? (int) $feed_cfg['tweet_count'] : 10 );
        }
        return max( 1, min( 50, $count ) );
    }

    /**
     * Read account-scoped tweets cache, falling back to legacy feed-scoped keys.
     *
     * @since 6.14.1
     *
     * @param string $cache_key  Account-scoped key.
     * @param int    $feed_id    Feed ID (legacy fallback).
     * @param int    $account_id Account ID.
     * @return array|null
     */
    private function read_tweets_cache( $cache_key, $feed_id, $account_id ) {
        $cached = ( '' !== $cache_key ? ESF_Twitter_Cache::get( $cache_key ) : null );
        if ( !is_array( $cached ) && '' !== $cache_key && method_exists( 'ESF_Twitter_Cache', 'get_stale' ) ) {
            $cached = ESF_Twitter_Cache::get_stale( $cache_key );
        }
        if ( is_array( $cached ) ) {
            return $cached;
        }
        if ( !function_exists( 'esf_twitter_tweets_cache_legacy_keys' ) ) {
            return null;
        }
        foreach ( esf_twitter_tweets_cache_legacy_keys( $feed_id, $account_id ) as $legacy_key ) {
            $legacy = ESF_Twitter_Cache::get( $legacy_key );
            if ( !is_array( $legacy ) && method_exists( 'ESF_Twitter_Cache', 'get_stale' ) ) {
                $legacy = ESF_Twitter_Cache::get_stale( $legacy_key );
            }
            if ( !is_array( $legacy ) ) {
                continue;
            }
            if ( '' !== $cache_key && !$this->tweets_cache_contains_localized_urls( $legacy ) ) {
                $account_row = ( class_exists( 'ESF_Twitter_Account_Repository' ) ? ESF_Twitter_Account_Repository::get_instance()->get_by_id( $account_id ) : null );
                $ttl = ( function_exists( 'esf_twitter_resolve_tweets_cache_ttl' ) ? (int) esf_twitter_resolve_tweets_cache_ttl( $account_row ) : 43200 );
                if ( $ttl <= 0 ) {
                    $ttl = 43200;
                }
                ESF_Twitter_Cache::set(
                    $cache_key,
                    $legacy,
                    $ttl,
                    'api',
                    $account_id
                );
            }
            return $legacy;
        }
        return null;
    }

    /**
     * Detect legacy tweet caches that stored localized upload URLs.
     *
     * @since 6.9.5
     * @param array $tweets Cached tweet items.
     * @return bool
     */
    protected function tweets_cache_contains_localized_urls( array $tweets ) {
        if ( !function_exists( 'esf_is_local_media_url' ) ) {
            return false;
        }
        foreach ( $tweets as $tweet ) {
            if ( !is_array( $tweet ) ) {
                continue;
            }
            if ( !empty( $tweet['author'] ) && is_array( $tweet['author'] ) ) {
                $avatar = ( isset( $tweet['author']['profile_image_url'] ) ? (string) $tweet['author']['profile_image_url'] : '' );
                if ( '' !== $avatar && esf_is_local_media_url( $avatar, 'twitter' ) ) {
                    return true;
                }
            }
            if ( empty( $tweet['media'] ) || !is_array( $tweet['media'] ) ) {
                continue;
            }
            foreach ( $tweet['media'] as $media_item ) {
                if ( !is_array( $media_item ) ) {
                    continue;
                }
                foreach ( array(
                    'url',
                    'preview_url',
                    'video_url',
                    'url_full'
                ) as $field ) {
                    $url = ( isset( $media_item[$field] ) ? (string) $media_item[$field] : '' );
                    if ( '' !== $url && esf_is_local_media_url( $url, 'twitter' ) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Resolve the remote CDN/source URL for a media field (never a local upload URL).
     *
     * @since 6.9.5
     * @param array  $media_item Media item.
     * @param string $url_field  Field to read (`url`, `preview_url`, `video_url`).
     * @return string
     */
    protected function resolve_twitter_media_remote_url( array $media_item, $url_field ) {
        if ( !empty( $media_item['remote_url'] ) && is_string( $media_item['remote_url'] ) ) {
            $stored = (string) $media_item['remote_url'];
            if ( !function_exists( 'esf_is_local_media_url' ) || !esf_is_local_media_url( $stored, 'twitter' ) ) {
                return $stored;
            }
        }
        $candidate = ( isset( $media_item[$url_field] ) ? (string) $media_item[$url_field] : '' );
        if ( '' === $candidate ) {
            return '';
        }
        if ( function_exists( 'esf_is_local_media_url' ) && esf_is_local_media_url( $candidate, 'twitter' ) ) {
            return '';
        }
        return $candidate;
    }

    /**
     * Resolve a remote avatar URL (never a local upload URL).
     *
     * @since 6.9.5
     * @param string $url Candidate avatar URL.
     * @return string
     */
    protected function resolve_twitter_avatar_remote_url( $url ) {
        $url = (string) $url;
        if ( '' === $url ) {
            return '';
        }
        if ( function_exists( 'esf_is_local_media_url' ) && esf_is_local_media_url( $url, 'twitter' ) ) {
            return '';
        }
        return $url;
    }

    /**
     * Replace remote image URLs with local copies when they exist.
     *
     * Avatars and photos are downloaded. Video files are left remote.
     *
     * @since 6.7.6
     * @param array  $tweets        Normalized tweet items.
     * @param int    $account_id    Account ID.
     * @param string $media_context `preview` or `frontend`.
     * @param int    $sync_limit    Max new image downloads. `0` skips downloads.
     * @return array
     */
    protected function ensure_local_media_for_tweets(
        $tweets,
        $account_id = 0,
        $media_context = 'frontend',
        $sync_limit = null
    ) {
        $account_id = ( function_exists( 'esf_normalize_local_media_account_id' ) ? esf_normalize_local_media_account_id( $account_id ) : max( 0, (int) $account_id ) );
        if ( !is_array( $tweets ) || empty( $tweets ) ) {
            return $tweets;
        }
        if ( !function_exists( 'esf_serve_media_locally' ) ) {
            return $tweets;
        }
        $media_context = ( in_array( $media_context, array('preview', 'frontend'), true ) ? $media_context : 'frontend' );
        if ( null === $sync_limit ) {
            $sync_limit = ( 'preview' === $media_context ? count( $tweets ) : 0 );
        }
        $sync_limit = max( 0, (int) $sync_limit );
        $downloads = 0;
        $thumb_width = (int) apply_filters( 'esf_twitter_local_media_grid_width', 600 );
        $thumb_width = max( 120, min( 1200, $thumb_width ) );
        foreach ( $tweets as $index => $tweet ) {
            if ( !is_array( $tweet ) ) {
                continue;
            }
            $tweet_id = ( isset( $tweet['tweet_id'] ) ? (string) $tweet['tweet_id'] : '' );
            // Localize card author avatar (covers retweets/quoted authors too).
            if ( !empty( $tweet['author'] ) && is_array( $tweet['author'] ) ) {
                $author_avatar = ( isset( $tweet['author']['profile_image_url'] ) ? (string) $tweet['author']['profile_image_url'] : '' );
                $remote_avatar = $this->resolve_twitter_avatar_remote_url( $author_avatar );
                if ( '' !== $remote_avatar ) {
                    if ( function_exists( 'esf_twitter_get_best_profile_image_url' ) ) {
                        $remote_avatar = esf_twitter_get_best_profile_image_url( $remote_avatar );
                    }
                    $author_id = ( isset( $tweet['author']['x_user_id'] ) ? (string) $tweet['author']['x_user_id'] : '' );
                    if ( '' === $author_id ) {
                        $author_id = 'tweet_' . $tweet_id . '_author';
                    }
                    $tweets[$index]['author']['profile_image_url'] = $this->resolve_or_download_image(
                        'tw_author_' . $author_id,
                        $remote_avatar,
                        $account_id,
                        $downloads,
                        $sync_limit
                    );
                }
            }
            // Localize media thumbnails/photos for tweet cards.
            if ( empty( $tweet['media'] ) || !is_array( $tweet['media'] ) ) {
                continue;
            }
            foreach ( $tweet['media'] as $media_index => $media_item ) {
                if ( !is_array( $media_item ) ) {
                    continue;
                }
                $type = ( isset( $media_item['type'] ) ? (string) $media_item['type'] : 'photo' );
                $url_field = ( 'photo' === $type ? 'url' : 'preview_url' );
                $remote_url = $this->resolve_twitter_media_remote_url( $media_item, $url_field );
                if ( '' === $remote_url ) {
                    if ( 'photo' !== $type ) {
                        $tweets[$index]['media'][$media_index]['video_url'] = $this->resolve_or_download_video(
                            $tweet_id,
                            $media_index,
                            $media_item,
                            $account_id,
                            $downloads,
                            $sync_limit
                        );
                    }
                    continue;
                }
                $media_key = 'tw_media_' . $tweet_id . '_' . (int) $media_index;
                $local_media_url = $this->resolve_or_download_image(
                    $media_key,
                    $remote_url,
                    $account_id,
                    $downloads,
                    $sync_limit
                );
                $is_local = $local_media_url !== $remote_url;
                if ( $is_local ) {
                    if ( 'photo' === $type ) {
                        $tweets[$index]['media'][$media_index]['url_full'] = $local_media_url;
                        $feed_url = $local_media_url;
                        if ( $thumb_width > 0 && function_exists( 'esf_ensure_local_media_variant' ) ) {
                            $variant = esf_ensure_local_media_variant(
                                $media_key,
                                'twitter',
                                $thumb_width,
                                $account_id
                            );
                            if ( is_string( $variant ) && '' !== $variant ) {
                                $feed_url = $variant;
                            }
                        }
                        $tweets[$index]['media'][$media_index]['url'] = $feed_url;
                    } else {
                        $preview_url = $local_media_url;
                        if ( $thumb_width > 0 && function_exists( 'esf_serve_media_locally_sized' ) && $sync_limit > 0 ) {
                            $sized_preview = esf_serve_media_locally_sized(
                                $media_key,
                                $remote_url,
                                'twitter',
                                $thumb_width,
                                $account_id
                            );
                            if ( is_string( $sized_preview ) && '' !== $sized_preview ) {
                                $preview_url = $sized_preview;
                            }
                        } elseif ( $thumb_width > 0 && function_exists( 'esf_ensure_local_media_variant' ) ) {
                            $variant = esf_ensure_local_media_variant(
                                $media_key,
                                'twitter',
                                $thumb_width,
                                $account_id
                            );
                            if ( is_string( $variant ) && '' !== $variant ) {
                                $preview_url = $variant;
                            }
                        }
                        $tweets[$index]['media'][$media_index][$url_field] = $preview_url;
                    }
                } elseif ( 'photo' === $type ) {
                    $tweets[$index]['media'][$media_index]['url'] = $remote_url;
                    unset($tweets[$index]['media'][$media_index]['url_full']);
                } else {
                    $tweets[$index]['media'][$media_index][$url_field] = $remote_url;
                }
                if ( 'photo' !== $type ) {
                    $tweets[$index]['media'][$media_index]['video_url'] = $this->resolve_or_download_video(
                        $tweet_id,
                        $media_index,
                        $media_item,
                        $account_id,
                        $downloads,
                        $sync_limit
                    );
                }
            }
        }
        return $tweets;
    }

    /**
     * Return a video URL, reusing a local file if one already exists.
     *
     * @since 6.14.1
     * @param string              $tweet_id    Tweet ID.
     * @param int                 $media_index Media index.
     * @param array<string,mixed> $media_item  Media item.
     * @param int                 $account_id  Account ID.
     * @param int                 $downloads   Unused.
     * @param int                 $sync_limit  Unused.
     * @return string
     */
    private function resolve_or_download_video(
        $tweet_id,
        $media_index,
        $media_item,
        $account_id,
        &$downloads,
        $sync_limit
    ) {
        unset($downloads, $sync_limit);
        $existing = ( isset( $media_item['video_url'] ) ? (string) $media_item['video_url'] : '' );
        $remote = $this->resolve_twitter_media_remote_url( $media_item, 'video_url' );
        if ( '' === $remote ) {
            return $existing;
        }
        $video_key = 'tw_video_' . $tweet_id . '_' . (int) $media_index;
        if ( function_exists( 'esf_local_media_url' ) ) {
            $local = esf_local_media_url(
                $video_key,
                'twitter',
                0,
                $account_id
            );
            if ( is_string( $local ) && '' !== $local ) {
                return $local;
            }
        }
        return $remote;
    }

    /**
     * Return an existing local file, or download when the sync budget allows.
     *
     * @since 6.14.1
     * @param string $media_key   Cache key.
     * @param string $remote_url  Remote CDN URL.
     * @param int    $account_id  Account id.
     * @param int    $downloads   Downloads used so far (by reference).
     * @param int    $sync_limit  Max new downloads.
     * @return string Local or remote URL.
     */
    private function resolve_or_download_image(
        $media_key,
        $remote_url,
        $account_id,
        &$downloads,
        $sync_limit
    ) {
        if ( function_exists( 'esf_local_media_url' ) ) {
            $existing = esf_local_media_url(
                $media_key,
                'twitter',
                0,
                $account_id
            );
            if ( is_string( $existing ) && '' !== $existing ) {
                return $existing;
            }
        }
        if ( $downloads >= $sync_limit ) {
            return $remote_url;
        }
        ++$downloads;
        $local = esf_serve_media_locally(
            $media_key,
            $remote_url,
            'twitter',
            $account_id
        );
        return ( is_string( $local ) && '' !== $local ? $local : $remote_url );
    }

    /**
     * Get tweets for a feed by ID only (used by cron and REST load-more).
     *
     * @since 6.7.6
     * @param int $feed_id Feed ID.
     * @return array Normalized tweet items (empty on failure).
     */
    public function get_tweets_for_feed_by_id( $feed_id ) {
        $feed_id = (int) $feed_id;
        $feed_repo = ESF_Twitter_Feed_Repository::get_instance();
        $feed_row = $feed_repo->get_by_id( $feed_id );
        if ( !$feed_row ) {
            return array();
        }
        $account_repo = ESF_Twitter_Account_Repository::get_instance();
        $account = $account_repo->get_by_id( (int) $feed_row->account_id );
        if ( !$account ) {
            return array();
        }
        $saved = json_decode( $feed_row->settings, true );
        $feed_obj = (object) array(
            'id'         => $feed_row->id,
            'account_id' => (int) $feed_row->account_id,
            'feed_type'  => $feed_row->feed_type,
            'source_id'  => $feed_row->source_id,
            'settings'   => ESF_Twitter_Feed_Repository::merge_settings_with_defaults( ( is_array( $saved ) ? $saved : array() ) ),
        );
        $tweets = $this->get_tweets_for_feed( $feed_id, $feed_obj, $account );
        if ( is_wp_error( $tweets ) ) {
            return array();
        }
        return ( is_array( $tweets ) ? $tweets : array() );
    }

    /**
     * Enqueue base feed CSS and layout-specific CSS.
     *
     * @since 6.7.6
     * @param ESF_Twitter_Layout_Base $layout Layout instance.
     * @return void
     */
    protected function enqueue_layout_assets( $layout ) {
        $base_handle = 'esf-twitter-feed';
        $base_path = ESF_TWITTER_DIR . 'frontend/assets/css/esf-twitter-feed.css';
        $base_url = ESF_TWITTER_URL . 'frontend/assets/css/esf-twitter-feed.css';
        if ( file_exists( $base_path ) ) {
            wp_enqueue_style(
                $base_handle,
                $base_url,
                array(),
                (string) filemtime( $base_path )
            );
        }
        $layout_handle = $layout->get_css_handle();
        $layout_slug = str_replace( 'esf-twitter-layout-', '', $layout_handle );
        $layout_path = ESF_TWITTER_DIR . 'frontend/assets/css/layouts/' . $layout_slug . '.css';
        $layout_url = ESF_TWITTER_URL . 'frontend/assets/css/layouts/' . $layout_slug . '.css';
        if ( function_exists( 'esf_locate_premium_only_asset' ) ) {
            $located = esf_locate_premium_only_asset(
                ESF_TWITTER_DIR . 'frontend/assets/css/layouts',
                ESF_TWITTER_URL . 'frontend/assets/css/layouts',
                $layout_slug,
                'css'
            );
            if ( '' !== $located['path'] ) {
                $layout_path = $located['path'];
                $layout_url = $located['url'];
            }
        }
        if ( file_exists( $layout_path ) ) {
            wp_enqueue_style(
                $layout_handle,
                $layout_url,
                array($base_handle),
                (string) filemtime( $layout_path )
            );
        }
        $js_handle = $layout->get_js_handle();
        if ( $js_handle ) {
            $js_src = ( method_exists( $layout, 'get_js_src' ) ? (string) $layout->get_js_src() : '' );
            $js_path = '';
            $js_url = '';
            if ( '' !== $js_src ) {
                $js_url = $js_src;
                // Map FTA_PLUGIN_URL / plugin-root URL back to a filesystem path for filemtime.
                $plugin_url = ( defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : (( defined( 'ESF_TWITTER_URL' ) ? trailingslashit( dirname( ESF_TWITTER_URL ) ) : '' )) );
                $plugin_dir = ( defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : (( defined( 'ESF_TWITTER_DIR' ) ? trailingslashit( dirname( ESF_TWITTER_DIR ) ) : '' )) );
                if ( '' !== $plugin_url && '' !== $plugin_dir && 0 === strpos( $js_src, $plugin_url ) ) {
                    $js_path = $plugin_dir . substr( $js_src, strlen( $plugin_url ) );
                }
            } else {
                $js_slug = str_replace( 'esf-twitter-layout-', '', $js_handle );
                $js_path = ESF_TWITTER_DIR . 'frontend/assets/js/layouts/' . $js_slug . '.js';
                $js_url = ESF_TWITTER_URL . 'frontend/assets/js/layouts/' . $js_slug . '.js';
            }
            if ( '' !== $js_url && ('' === $js_path || file_exists( $js_path )) ) {
                $ver = ( '' !== $js_path && file_exists( $js_path ) ? (string) filemtime( $js_path ) : (( defined( 'FTA_PLUGIN_VERSION' ) ? (string) FTA_PLUGIN_VERSION : '1.0' )) );
                $js_deps = array();
                if ( false !== strpos( $js_handle, 'masonry' ) && function_exists( 'esf_register_masonry_pack_script' ) ) {
                    esf_register_masonry_pack_script();
                    $js_deps[] = 'esf-masonry-pack';
                }
                wp_enqueue_script(
                    $js_handle,
                    $js_url,
                    $js_deps,
                    $ver,
                    true
                );
            }
        }
    }

    /**
     * Extract and sanitize custom CSS from feed settings.
     *
     * Custom CSS is treated as trusted admin input and output inside a
     * <style> tag. Any existing <style> tags are stripped to prevent nesting.
     *
     * @since 6.7.6
     * @param int   $feed_id  Feed ID (unused, reserved for future scoping).
     * @param array $settings Merged feed settings.
     * @return string Custom CSS or empty string.
     */
    protected function get_custom_css( $feed_id, $settings ) {
        // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
        if ( !is_array( $settings ) ) {
            return '';
        }
        $style = ( isset( $settings['style'] ) && is_array( $settings['style'] ) ? $settings['style'] : array() );
        $css = ( isset( $style['custom_css'] ) ? (string) $style['custom_css'] : '' );
        $css = trim( $css );
        if ( '' === $css ) {
            return '';
        }
        return preg_replace( '#</?style[^>]*>#i', '', $css );
    }

    /**
     * Get the URL for the base feed CSS (for the REST preview endpoint).
     *
     * @since 6.7.6
     * @return string URL or empty string.
     */
    public function get_base_css_url() {
        $path = ESF_TWITTER_DIR . 'frontend/assets/css/esf-twitter-feed.css';
        return ( file_exists( $path ) ? ESF_TWITTER_URL . 'frontend/assets/css/esf-twitter-feed.css' : '' );
    }

    /**
     * Get the URL for a layout's CSS (for the REST preview endpoint).
     *
     * @since 6.7.6
     * @param string $layout_type Layout slug.
     * @return string URL or empty string.
     */
    public function get_layout_css_url( $layout_type ) {
        $layout_type = ( is_string( $layout_type ) ? trim( $layout_type ) : 'timeline' );
        // Asset filenames use hyphens; layout slugs use underscores (half_width).
        $file_slug = str_replace( '_', '-', $layout_type );
        if ( function_exists( 'esf_locate_premium_only_asset' ) ) {
            $located = esf_locate_premium_only_asset(
                ESF_TWITTER_DIR . 'frontend/assets/css/layouts',
                ESF_TWITTER_URL . 'frontend/assets/css/layouts',
                $file_slug,
                'css'
            );
            return $located['url'];
        }
        $path = ESF_TWITTER_DIR . 'frontend/assets/css/layouts/' . $file_slug . '.css';
        return ( file_exists( $path ) ? ESF_TWITTER_URL . 'frontend/assets/css/layouts/' . $file_slug . '.css' : '' );
    }

    /**
     * Get the URL for a layout's JS (for the REST preview endpoint).
     *
     * Carousel uses the shared esf-carousel__premium_only.js; other layouts may ship module JS.
     *
     * @since 6.13.0
     * @param string $layout_type Layout slug.
     * @return string URL or empty string.
     */
    public function get_layout_js_url( $layout_type ) {
        $layout_type = ( is_string( $layout_type ) ? trim( $layout_type ) : 'timeline' );
        if ( 'carousel' === $layout_type ) {
            $plugin_url = ( defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : trailingslashit( dirname( ESF_TWITTER_URL ) ) );
            $path = ( defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR . 'admin/shared/frontend/js/esf-carousel__premium_only.js' : dirname( ESF_TWITTER_DIR ) . '/admin/shared/frontend/js/esf-carousel__premium_only.js' );
            return ( file_exists( $path ) ? $plugin_url . 'admin/shared/frontend/js/esf-carousel__premium_only.js' : '' );
        }
        if ( 'half_width' === $layout_type ) {
            $path = ESF_TWITTER_DIR . 'frontend/assets/js/esf-twitter-half-width__premium_only.js';
            return ( file_exists( $path ) ? ESF_TWITTER_URL . 'frontend/assets/js/esf-twitter-half-width__premium_only.js' : '' );
        }
        if ( 'masonry' === $layout_type ) {
            $path = ESF_TWITTER_DIR . 'frontend/assets/js/esf-twitter-masonry__premium_only.js';
            return ( file_exists( $path ) ? ESF_TWITTER_URL . 'frontend/assets/js/esf-twitter-masonry__premium_only.js' : '' );
        }
        $path = ESF_TWITTER_DIR . 'frontend/assets/js/layouts/' . $layout_type . '.js';
        return ( file_exists( $path ) ? ESF_TWITTER_URL . 'frontend/assets/js/layouts/' . $layout_type . '.js' : '' );
    }

    /**
     * Render the shared missing-feed placeholder for deleted/invalid feed IDs.
     *
     * @since 6.11.0
     * @param int $feed_id Missing feed ID from the shortcode.
     * @return string HTML output.
     */
    private function render_missing_feed( int $feed_id ) : string {
        $post_id = ( function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0 );
        $can_manage = function_exists( 'esf_twitter_user_can_manage' ) && esf_twitter_user_can_manage();
        $context = new MissingFeedContext(
            self::MODULE,
            $feed_id,
            'esf_twitter_feed',
            $post_id,
            $can_manage,
            MissingFeedState::default_public_message( self::MODULE ),
            admin_url( 'admin.php?page=esf-twitter&screen=feeds' ),
            '/twitter/feeds/{id}/preview',
            '/twitter/feeds'
        );
        return MissingFeedState::render( $context );
    }

    /**
     * Map shared wrapper classes to the historical `.esf-tw-feed` BEM root.
     *
     * @since 6.14.0
     * @return void
     */
    private static function ensure_bem_wrapper_filter() : void {
        if ( self::$bem_wrapper_filter_registered ) {
            return;
        }
        self::$bem_wrapper_filter_registered = true;
        add_filter(
            'esf_twitter_layout_render_wrapper_classes',
            array(__CLASS__, 'filter_wrapper_classes'),
            10,
            3
        );
    }

    /**
     * Map shared-renderer wrapper classes to the historical Twitter BEM root.
     *
     * @since 6.14.0
     *
     * @param array                                                      $classes    Proposed wrapper classes.
     * @param object                                                     $feed       Feed object.
     * @param \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition|null $definition Layout definition.
     * @return array<int,string>
     */
    public static function filter_wrapper_classes( $classes, $feed = null, $definition = null ) {
        $slug = 'timeline';
        if ( is_object( $definition ) && method_exists( $definition, 'get_slug' ) ) {
            $slug = (string) $definition->get_slug();
        } elseif ( is_object( $feed ) && isset( $feed->settings['layout']['type'] ) ) {
            $slug = (string) $feed->settings['layout']['type'];
        }
        $slug = sanitize_html_class( $slug );
        if ( '' === $slug ) {
            $slug = 'timeline';
        }
        return array('esf-tw-feed', 'esf-tw-feed--' . $slug);
    }

}
