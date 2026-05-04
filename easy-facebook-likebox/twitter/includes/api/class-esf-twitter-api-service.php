<?php

/**
 * Twitter API Service
 *
 * Handles all direct X API v2 calls and communication with the
 * ESF proxy server for token refresh and public-account fetches.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/API
 * @since 6.7.6
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Twitter_API_Service
 *
 * @since 6.7.6
 */
class ESF_Twitter_API_Service {
    use ESF_Twitter_Singleton;
    /**
     * X API v2 base URL.
     *
     * @since 6.7.6
     * @var string
     */
    const API_BASE = 'https://api.x.com/2';

    /**
     * X OAuth 2.0 token endpoint.
     *
     * @since 6.7.6
     * @var string
     */
    const TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    /**
     * Default OAuth bridge callback URI used for code exchange.
     *
     * @since 6.7.6
     * @var string
     */
    const OAUTH_BRIDGE_REDIRECT_URI = 'https://easysocialfeed.com/apps/x/index.php';

    /**
     * Maximum tweets to fetch per request (X API v2 max is 100).
     *
     * @since 6.7.6
     * @var int
     */
    const MAX_TWEET_FETCH = 100;

    /**
     * Maximum proxy payload bytes to accept for public timeline requests.
     *
     * Guards against oversized snapshot responses exhausting PHP memory.
     *
     * @since 6.7.6
     * @var int
     */
    const MAX_PROXY_RESPONSE_BYTES = 8388608;

    // 8 MB.
    /**
     * Common tweet fields to request from every timeline call.
     *
     * @since 6.7.6
     * @var string
     */
    const TWEET_FIELDS = 'created_at,public_metrics,entities,attachments,referenced_tweets';

    /**
     * User fields to expand on timeline calls.
     *
     * @since 6.7.6
     * @var string
     */
    const USER_FIELDS = 'name,username,profile_image_url,verified,verified_type';

    /**
     * Media fields to expand on timeline calls.
     *
     * @since 6.7.6
     * @var string
     */
    const MEDIA_FIELDS = 'url,preview_image_url,type,width,height,alt_text,variants';

    /**
     * Exchange an OAuth authorization code for X access tokens.
     *
     * Uses PKCE (code_verifier) and client_id. client_secret is optional.
     *
     * @since 6.7.6
     * @param string $code          Authorization code from X callback.
     * @param string $code_verifier PKCE code verifier stored by plugin.
     * @param string $redirect_uri  Redirect URI used during authorize request.
     * @return array|WP_Error Token data array or WP_Error on failure.
     */
    public function exchange_authorization_code( $code, $code_verifier, $redirect_uri = '' ) {
        $code = trim( (string) $code );
        $code_verifier = trim( (string) $code_verifier );
        $redirect_uri = trim( (string) $redirect_uri );
        if ( '' === $code || '' === $code_verifier ) {
            return new WP_Error('invalid_oauth_code', __( 'Missing OAuth authorization data.', 'easy-facebook-likebox' ));
        }
        $client_id = $this->get_oauth_client_id();
        if ( '' === $client_id ) {
            return new WP_Error('missing_client_id', __( 'X OAuth client ID is not configured.', 'easy-facebook-likebox' ));
        }
        if ( '' === $redirect_uri ) {
            $redirect_uri = apply_filters( 'esf_twitter_oauth_redirect_uri', self::OAUTH_BRIDGE_REDIRECT_URI );
        }
        $body = array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'code_verifier' => $code_verifier,
        );
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body'    => $body,
            'timeout' => 20,
        );
        $client_secret = $this->get_oauth_client_secret();
        if ( '' !== $client_secret ) {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            unset($args['body']['client_id']);
        }
        $response = wp_remote_post( self::TOKEN_URL, $args );
        if ( is_wp_error( $response ) ) {
            return new WP_Error('oauth_token_request_failed', sprintf( 
                /* translators: %s: error message */
                __( 'OAuth token request failed: %s', 'easy-facebook-likebox' ),
                $response->get_error_message()
             ));
        }
        $code_http = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw_body, true );
        if ( !is_array( $data ) ) {
            return new WP_Error('oauth_token_invalid_json', __( 'Invalid OAuth token response from X.', 'easy-facebook-likebox' ));
        }
        if ( 200 !== (int) $code_http || empty( $data['access_token'] ) ) {
            $message = ( isset( $data['error_description'] ) ? (string) $data['error_description'] : (( isset( $data['error'] ) ? (string) $data['error'] : sprintf( 'HTTP %d', (int) $code_http ) )) );
            return new WP_Error('oauth_token_exchange_failed', $message);
        }
        return array(
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => ( isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '' ),
            'expires_in'    => ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 7200 ),
            'token_type'    => ( isset( $data['token_type'] ) ? sanitize_text_field( $data['token_type'] ) : 'bearer' ),
        );
    }

    /**
     * Refresh an OAuth 2.0 access token via the ESF proxy server.
     *
     * The client_secret lives on the ESF server, never in the plugin.
     *
     * @since 6.7.6
     * @param string $refresh_token The refresh token to exchange.
     * @return array|WP_Error New token data array on success, WP_Error on failure.
     *                        Success keys: access_token, refresh_token, expires_in.
     */
    public function refresh_access_token( $refresh_token ) {
        if ( empty( $refresh_token ) ) {
            return new WP_Error('invalid_refresh_token', __( 'Refresh token is required.', 'easy-facebook-likebox' ));
        }
        $refresh_url = apply_filters( 'esf_twitter_oauth_refresh_url', 'https://easysocialfeed.com/apps/x/refresh.php' );
        $response = wp_remote_post( $refresh_url, array(
            'body'    => array(
                'refresh_token' => $refresh_token,
                'site_url'      => home_url(),
            ),
            'timeout' => 15,
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error('refresh_request_failed', sprintf( 
                /* translators: %s: error message */
                __( 'Failed to refresh token: %s', 'easy-facebook-likebox' ),
                $response->get_error_message()
             ));
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( !is_array( $body ) ) {
            $body = array();
        }
        $data = $body;
        if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
            $data = $body['data'];
        } elseif ( isset( $body['result'] ) && is_array( $body['result'] ) ) {
            $data = $body['result'];
        }
        // Some proxy deployments respond with 202 Accepted while still returning token payload.
        if ( !in_array( (int) $code, array(200, 202), true ) ) {
            $msg = ( isset( $body['error'] ) ? $body['error'] : (( isset( $body['message'] ) ? $body['message'] : sprintf( 'HTTP %d', $code ) )) );
            return new WP_Error('refresh_error', $msg);
        }
        if ( empty( $data['access_token'] ) ) {
            return new WP_Error('refresh_invalid_response', __( 'Invalid token refresh response from server.', 'easy-facebook-likebox' ));
        }
        return array(
            'access_token'  => $data['access_token'],
            'refresh_token' => ( isset( $data['refresh_token'] ) ? $data['refresh_token'] : $refresh_token ),
            'expires_in'    => ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 7200 ),
        );
    }

    /**
     * Validate an access token and fetch the authenticated user's profile.
     *
     * Called during account connect/reconnect to populate DB fields.
     *
     * @since 6.7.6
     * @param string $access_token Valid OAuth 2.0 access token.
     * @return array|WP_Error Normalized user data array, or WP_Error on failure.
     */
    public function validate_token_and_fetch_user( $access_token ) {
        $url = add_query_arg( array(
            'user.fields' => 'id,name,username,profile_image_url,description,public_metrics,verified,verified_type',
        ), self::API_BASE . '/users/me' );
        $response = $this->api_request( $url, $access_token );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $user = ( isset( $response['data'] ) ? $response['data'] : array() );
        if ( empty( $user['id'] ) ) {
            return new WP_Error('invalid_user_response', __( 'Could not retrieve X user data.', 'easy-facebook-likebox' ));
        }
        return $this->normalize_user( $user );
    }

    /**
     * Look up a public X user by username (Pro-only gateway).
     *
     * Free builds keep this wrapper method, while premium-only internals may be
     * stripped. Always guard usage through this method.
     *
     * @since 6.7.6
     * @param string $username X username (without @).
     * @return array|WP_Error Normalized user data array, or WP_Error on failure.
     */
    public function fetch_public_user( $username ) {
        if ( !function_exists( 'esf_twitter_has_twitter_plan' ) || !esf_twitter_has_twitter_plan() ) {
            return new WP_Error('pro_required', __( 'Public account feeds require the Twitter Pro plan.', 'easy-facebook-likebox' ));
        }
        if ( !method_exists( $this, 'fetch_public_user__premium_only' ) ) {
            return new WP_Error('pro_unavailable', __( 'Public account support is unavailable in this build.', 'easy-facebook-likebox' ));
        }
        return $this->fetch_public_user__premium_only( $username );
    }

    /**
     * Fetch tweets for a connected account's timeline.
     *
     * @since 6.7.6
     * @param string $x_user_id     X platform user ID.
     * @param string $access_token  Valid OAuth 2.0 access token.
     * @param int    $max_results   Number of tweets to fetch (1–100).
     * @param array  $exclude       Types to exclude: 'retweets', 'replies'.
     * @return array|WP_Error Normalized tweet array, or WP_Error on failure.
     */
    public function fetch_user_timeline(
        $x_user_id,
        $access_token,
        $max_results = 20,
        $exclude = array()
    ) {
        $page = $this->fetch_user_timeline_page(
            $x_user_id,
            $access_token,
            $max_results,
            $exclude
        );
        if ( is_wp_error( $page ) ) {
            return $page;
        }
        return ( isset( $page['tweets'] ) && is_array( $page['tweets'] ) ? $page['tweets'] : array() );
    }

    /**
     * Fetch a single user-timeline page with pagination metadata.
     *
     * @since 6.7.6
     * @param string $x_user_id        X platform user ID.
     * @param string $access_token     Valid OAuth 2.0 access token.
     * @param int    $max_results      Number of tweets to fetch (1–100).
     * @param array  $exclude          Types to exclude: 'retweets', 'replies'.
     * @param string $pagination_token Optional X API next_token for pagination.
     * @return array|WP_Error Page payload with tweets and next_token.
     */
    public function fetch_user_timeline_page(
        $x_user_id,
        $access_token,
        $max_results = 20,
        $exclude = array(),
        $pagination_token = ''
    ) {
        $max_results = max( 5, min( self::MAX_TWEET_FETCH, (int) $max_results ) );
        $params = array(
            'max_results'  => $max_results,
            'tweet.fields' => self::TWEET_FIELDS,
            'expansions'   => 'author_id,attachments.media_keys',
            'user.fields'  => self::USER_FIELDS,
            'media.fields' => self::MEDIA_FIELDS,
        );
        $valid_exclude = array('retweets', 'replies');
        $exclude = array_intersect( (array) $exclude, $valid_exclude );
        if ( !empty( $exclude ) ) {
            $params['exclude'] = implode( ',', $exclude );
        }
        if ( '' !== $pagination_token ) {
            $params['pagination_token'] = sanitize_text_field( (string) $pagination_token );
        }
        $url = add_query_arg( $params, self::API_BASE . '/users/' . rawurlencode( $x_user_id ) . '/tweets' );
        $response = $this->api_request( $url, $access_token );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $next_token = ( isset( $response['meta']['next_token'] ) ? sanitize_text_field( (string) $response['meta']['next_token'] ) : '' );
        return array(
            'tweets'     => $this->normalize_tweets_response( $response ),
            'next_token' => $next_token,
        );
    }

    /**
     * Fetch tweets for a public account via the ESF proxy (Pro-only gateway).
     *
     * @since 6.7.6
     * @param string $x_user_id   X platform user ID.
     * @param int    $max_results Number of tweets to fetch (1–100).
     * @param array  $exclude     Types to exclude: 'retweets', 'replies'.
     * @return array|WP_Error Normalized tweet array, or WP_Error on failure.
     */
    public function fetch_public_timeline( $x_user_id, $max_results = 20, $exclude = array() ) {
        if ( !function_exists( 'esf_twitter_has_twitter_plan' ) || !esf_twitter_has_twitter_plan() ) {
            return new WP_Error('pro_required', __( 'Public account feeds require the Twitter Pro plan.', 'easy-facebook-likebox' ));
        }
        if ( !method_exists( $this, 'fetch_public_timeline__premium_only' ) ) {
            return new WP_Error('pro_unavailable', __( 'Public timeline support is unavailable in this build.', 'easy-facebook-likebox' ));
        }
        $page = $this->fetch_public_timeline_page__premium_only( $x_user_id, $max_results, $exclude );
        if ( is_wp_error( $page ) ) {
            return $page;
        }
        return ( isset( $page['tweets'] ) && is_array( $page['tweets'] ) ? $page['tweets'] : array() );
    }

    /**
     * Fetch a single public-timeline page with pagination metadata.
     *
     * @since 6.7.6
     * @param string $x_user_id        X platform user ID.
     * @param int    $max_results      Number of tweets to fetch (1–100).
     * @param array  $exclude          Types to exclude: 'retweets', 'replies'.
     * @param string $pagination_token Optional X API next_token for pagination.
     * @return array|WP_Error Page payload with tweets and next_token.
     */
    public function fetch_public_timeline_page(
        $x_user_id,
        $max_results = 20,
        $exclude = array(),
        $pagination_token = ''
    ) {
        if ( !function_exists( 'esf_twitter_has_twitter_plan' ) || !esf_twitter_has_twitter_plan() ) {
            return new WP_Error('pro_required', __( 'Public account feeds require the Twitter Pro plan.', 'easy-facebook-likebox' ));
        }
        if ( !method_exists( $this, 'fetch_public_timeline_page__premium_only' ) ) {
            return new WP_Error('pro_unavailable', __( 'Public timeline support is unavailable in this build.', 'easy-facebook-likebox' ));
        }
        return $this->fetch_public_timeline_page__premium_only(
            $x_user_id,
            $max_results,
            $exclude,
            $pagination_token
        );
    }

    /**
     * Normalize the raw X API tweets response into a consistent array.
     *
     * Merges in author and media data from includes.
     *
     * @since 6.7.6
     * @param array $response Decoded X API JSON response.
     * @return array Normalized tweet items.
     */
    public function normalize_tweets_response( $response ) {
        if ( !is_array( $response ) || empty( $response['data'] ) ) {
            return array();
        }
        // Index includes by key for constant-time lookups.
        $users = array();
        $media = array();
        if ( !empty( $response['includes']['users'] ) && is_array( $response['includes']['users'] ) ) {
            foreach ( $response['includes']['users'] as $u ) {
                if ( !empty( $u['id'] ) ) {
                    $users[$u['id']] = $u;
                }
            }
        }
        if ( !empty( $response['includes']['media'] ) && is_array( $response['includes']['media'] ) ) {
            foreach ( $response['includes']['media'] as $m ) {
                if ( !empty( $m['media_key'] ) ) {
                    $media[$m['media_key']] = $m;
                }
            }
        }
        $tweets = array();
        foreach ( $response['data'] as $raw ) {
            if ( empty( $raw['id'] ) ) {
                continue;
            }
            $author_id = ( isset( $raw['author_id'] ) ? $raw['author_id'] : '' );
            $author = ( !empty( $author_id ) && isset( $users[$author_id] ) ? $users[$author_id] : array() );
            $tweet_media = array();
            if ( !empty( $raw['attachments']['media_keys'] ) && is_array( $raw['attachments']['media_keys'] ) ) {
                foreach ( $raw['attachments']['media_keys'] as $mk ) {
                    if ( isset( $media[$mk] ) ) {
                        $tweet_media[] = $this->normalize_media_item( $media[$mk] );
                    }
                }
            }
            $entities = ( isset( $raw['entities'] ) && is_array( $raw['entities'] ) ? $raw['entities'] : array() );
            $metrics = ( isset( $raw['public_metrics'] ) && is_array( $raw['public_metrics'] ) ? $raw['public_metrics'] : array() );
            $text_raw = ( isset( $raw['text'] ) ? (string) $raw['text'] : '' );
            $url_entities = ( isset( $entities['urls'] ) && is_array( $entities['urls'] ) ? $entities['urls'] : array() );
            $text_parts = $this->strip_media_urls_from_text( $text_raw, $url_entities, !empty( $tweet_media ) );
            $text_raw = $text_parts['text'];
            $url_entities = $text_parts['urls'];
            $text_safe = esc_html( $text_raw );
            $tweets[] = array(
                'tweet_id'      => (string) $raw['id'],
                'text'          => esc_html( $text_raw ),
                'text_html'     => esf_twitter_format_tweet_text(
                    $text_safe,
                    $url_entities,
                    ( isset( $entities['mentions'] ) ? $entities['mentions'] : array() ),
                    ( isset( $entities['hashtags'] ) ? $entities['hashtags'] : array() )
                ),
                'created_at'    => ( isset( $raw['created_at'] ) ? $raw['created_at'] : '' ),
                'like_count'    => ( isset( $metrics['like_count'] ) ? (int) $metrics['like_count'] : 0 ),
                'retweet_count' => ( isset( $metrics['retweet_count'] ) ? (int) $metrics['retweet_count'] : 0 ),
                'reply_count'   => ( isset( $metrics['reply_count'] ) ? (int) $metrics['reply_count'] : 0 ),
                'quote_count'   => ( isset( $metrics['quote_count'] ) ? (int) $metrics['quote_count'] : 0 ),
                'is_retweet'    => !empty( $raw['referenced_tweets'] ) && $this->has_type( $raw['referenced_tweets'], 'retweeted' ),
                'is_reply'      => !empty( $raw['referenced_tweets'] ) && $this->has_type( $raw['referenced_tweets'], 'replied_to' ),
                'media'         => $tweet_media,
                'author'        => $this->normalize_user( $author ),
                'tweet_url'     => ( !empty( $author['username'] ) ? 'https://x.com/' . $author['username'] . '/status/' . $raw['id'] : 'https://x.com/i/web/status/' . $raw['id'] ),
            );
        }
        return $tweets;
    }

    /**
     * Normalize a single user object from the X API.
     *
     * @since 6.7.6
     * @param array $user Raw user object from X API includes or /users/me.
     * @return array Normalized user array (safe to store and render).
     */
    public function normalize_user( $user ) {
        if ( !is_array( $user ) || empty( $user['id'] ) ) {
            return array();
        }
        $metrics = ( isset( $user['public_metrics'] ) && is_array( $user['public_metrics'] ) ? $user['public_metrics'] : array() );
        return array(
            'x_user_id'         => (string) $user['id'],
            'username'          => ( isset( $user['username'] ) ? sanitize_text_field( $user['username'] ) : '' ),
            'display_name'      => ( isset( $user['name'] ) ? sanitize_text_field( $user['name'] ) : '' ),
            'profile_image_url' => ( isset( $user['profile_image_url'] ) ? esc_url_raw( $user['profile_image_url'] ) : '' ),
            'description'       => ( isset( $user['description'] ) ? sanitize_text_field( $user['description'] ) : '' ),
            'followers_count'   => ( isset( $metrics['followers_count'] ) ? (int) $metrics['followers_count'] : 0 ),
            'following_count'   => ( isset( $metrics['following_count'] ) ? (int) $metrics['following_count'] : 0 ),
            'tweet_count'       => ( isset( $metrics['tweet_count'] ) ? (int) $metrics['tweet_count'] : 0 ),
            'is_verified'       => !empty( $user['verified'] ),
            'verified_type'     => ( isset( $user['verified_type'] ) ? sanitize_text_field( (string) $user['verified_type'] ) : '' ),
        );
    }

    /**
     * Normalize a single media object from the X API includes.
     *
     * @since 6.7.6
     * @param array $media_item Raw media object.
     * @return array Normalized media item.
     */
    private function normalize_media_item( $media_item ) {
        $type = ( isset( $media_item['type'] ) ? $media_item['type'] : 'photo' );
        $url = '';
        $preview_url = '';
        $video_url = '';
        if ( 'photo' === $type ) {
            $url = ( isset( $media_item['url'] ) ? esc_url_raw( $media_item['url'] ) : '' );
        } else {
            // video / animated_gif — use preview_image_url as thumbnail.
            $preview_url = ( isset( $media_item['preview_image_url'] ) ? esc_url_raw( $media_item['preview_image_url'] ) : '' );
            $video_url = $this->extract_best_video_variant_url( $media_item );
        }
        return array(
            'type'        => $type,
            'url'         => $url,
            'preview_url' => $preview_url,
            'video_url'   => $video_url,
            'width'       => ( isset( $media_item['width'] ) ? (int) $media_item['width'] : 0 ),
            'height'      => ( isset( $media_item['height'] ) ? (int) $media_item['height'] : 0 ),
            'alt_text'    => ( isset( $media_item['alt_text'] ) ? sanitize_text_field( $media_item['alt_text'] ) : '' ),
        );
    }

    /**
     * Extract best playable MP4 variant URL from media variants.
     *
     * @since 6.7.6
     * @param array $media_item Raw media object.
     * @return string
     */
    private function extract_best_video_variant_url( $media_item ) {
        if ( empty( $media_item['variants'] ) || !is_array( $media_item['variants'] ) ) {
            return '';
        }
        $best_url = '';
        $best_bitrate = -1;
        foreach ( $media_item['variants'] as $variant ) {
            if ( !is_array( $variant ) ) {
                continue;
            }
            $content_type = ( isset( $variant['content_type'] ) ? (string) $variant['content_type'] : '' );
            $variant_url = ( isset( $variant['url'] ) ? esc_url_raw( (string) $variant['url'] ) : '' );
            if ( '' === $variant_url || 'video/mp4' !== strtolower( $content_type ) ) {
                continue;
            }
            $bitrate = ( isset( $variant['bit_rate'] ) ? (int) $variant['bit_rate'] : 0 );
            if ( $bitrate > $best_bitrate ) {
                $best_bitrate = $bitrate;
                $best_url = $variant_url;
            }
        }
        return $best_url;
    }

    /**
     * Remove media-only URL entities from tweet text.
     *
     * X includes media attachment links in text (e.g., pic.x.com/...), which
     * are redundant when media is already rendered as cards.
     *
     * @since 6.7.6
     *
     * @param string $text         Raw tweet text.
     * @param array  $url_entities URL entities from tweet entities.
     * @param bool   $has_media    True when tweet has attached media.
     * @return array{text:string,urls:array}
     */
    private function strip_media_urls_from_text( $text, $url_entities, $has_media ) {
        $text = ( is_string( $text ) ? $text : '' );
        if ( !$has_media || !is_array( $url_entities ) || empty( $url_entities ) ) {
            return array(
                'text' => $text,
                'urls' => ( is_array( $url_entities ) ? $url_entities : array() ),
            );
        }
        $filtered_urls = array();
        foreach ( $url_entities as $entity ) {
            if ( $this->is_media_url_entity( $entity ) ) {
                $entity_url = ( isset( $entity['url'] ) ? (string) $entity['url'] : '' );
                if ( '' !== $entity_url ) {
                    $text = str_replace( $entity_url, '', $text );
                }
                continue;
            }
            $filtered_urls[] = $entity;
        }
        $text = trim( preg_replace( '/\\s{2,}/', ' ', $text ) );
        return array(
            'text' => $text,
            'urls' => $filtered_urls,
        );
    }

    /**
     * Check whether a URL entity points to attached media.
     *
     * @since 6.7.6
     *
     * @param array $entity URL entity from X API.
     * @return bool
     */
    private function is_media_url_entity( $entity ) {
        if ( !is_array( $entity ) ) {
            return false;
        }
        $display_url = ( isset( $entity['display_url'] ) ? strtolower( (string) $entity['display_url'] ) : '' );
        $expanded_url = ( isset( $entity['expanded_url'] ) ? strtolower( (string) $entity['expanded_url'] ) : '' );
        if ( '' !== $display_url && (0 === strpos( $display_url, 'pic.x.com/' ) || 0 === strpos( $display_url, 'pic.twitter.com/' )) ) {
            return true;
        }
        return false !== strpos( $expanded_url, 'pic.x.com/' ) || false !== strpos( $expanded_url, 'pic.twitter.com/' );
    }

    /**
     * Check whether a referenced_tweets array contains an entry of the given type.
     *
     * @since 6.7.6
     * @param array  $referenced_tweets Array of {type, id} objects.
     * @param string $type              Type to look for ('retweeted', 'replied_to', 'quoted').
     * @return bool
     */
    private function has_type( $referenced_tweets, $type ) {
        if ( !is_array( $referenced_tweets ) ) {
            return false;
        }
        foreach ( $referenced_tweets as $ref ) {
            if ( isset( $ref['type'] ) && $ref['type'] === $type ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Make an authenticated GET request to the X API.
     *
     * @since 6.7.6
     * @param string $url          Full URL including query string.
     * @param string $access_token Bearer token (OAuth user token or app-only token).
     * @return array|WP_Error Decoded response body, or WP_Error on failure.
     */
    private function api_request( $url, $access_token ) {
        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'User-Agent'    => 'EasySocialFeed-WordPress/' . (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0' )),
            ),
            'timeout' => 15,
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $code ) {
            $msg = ( isset( $body['detail'] ) ? $body['detail'] : (( isset( $body['title'] ) ? $body['title'] : sprintf( 'HTTP %d', $code ) )) );
            // 401 = expired/invalid token; flag it distinctly.
            if ( 401 === $code ) {
                return new WP_Error('token_expired', __( 'X access token has expired. Please reconnect your account.', 'easy-facebook-likebox' ));
            }
            return new WP_Error('api_error', $msg);
        }
        return ( is_array( $body ) ? $body : array() );
    }

    /**
     * Get OAuth client ID from filter/constant.
     *
     * @since 6.7.6
     * @return string
     */
    private function get_oauth_client_id() {
        $default = ( defined( 'ESF_TWITTER_OAUTH_CLIENT_ID' ) ? ESF_TWITTER_OAUTH_CLIENT_ID : '' );
        $value = apply_filters( 'esf_twitter_oauth_client_id', $default );
        return ( is_string( $value ) ? trim( $value ) : '' );
    }

    /**
     * Get optional OAuth client secret from filter/constant.
     *
     * @since 6.7.6
     * @return string
     */
    private function get_oauth_client_secret() {
        $default = ( defined( 'ESF_TWITTER_OAUTH_CLIENT_SECRET' ) ? ESF_TWITTER_OAUTH_CLIENT_SECRET : '' );
        $value = apply_filters( 'esf_twitter_oauth_client_secret', $default );
        return ( is_string( $value ) ? trim( $value ) : '' );
    }

}
