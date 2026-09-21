<?php

/**
 * Twitter Module Helper Functions
 *
 * Global helper functions for the Twitter module.
 * These functions provide a WordPress-like API for common operations.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Helpers
 * @since 6.7.6
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Check if the Twitter module is currently active.
 *
 * @since 6.7.6
 * @return bool True if active, false otherwise.
 */
function esf_is_twitter_active() {
    $fta_settings = get_option( 'fta_settings', array() );
    $status = ( isset( $fta_settings['plugins']['twitter']['status'] ) ? $fta_settings['plugins']['twitter']['status'] : 'deactivated' );
    return 'activated' === $status;
}

/**
 * Get Twitter module settings.
 *
 * Can return a specific setting if a key is provided.
 *
 * @since 6.7.6
 * @param string|null $key Optional. Specific setting key to retrieve.
 * @return mixed Array of all settings or specific setting value.
 */
function esf_get_twitter_settings(  $key = null  ) {
    $saved = get_option( 'esf_twitter_settings', array() );
    if ( !is_array( $saved ) ) {
        $saved = array();
    }
    $settings = esf_twitter_normalize_settings( $saved );
    if ( null !== $key ) {
        return ( array_key_exists( $key, $settings ) ? $settings[$key] : null );
    }
    return $settings;
}

/**
 * Merge saved Twitter settings with module defaults.
 *
 * @since 6.9.0
 * @param array<string, mixed> $saved Raw option values.
 * @return array<string, mixed>
 */
function esf_twitter_normalize_settings(  array $saved  ) {
    $settings = array_merge( array(
        'cache_duration'                => 43200,
        'notify_token_reconnect'        => true,
        'notify_token_reconnect_emails' => array(),
    ), $saved );
    $allowed = array(43200, 86400, 604800);
    if ( !in_array( (int) $settings['cache_duration'], $allowed, true ) ) {
        $settings['cache_duration'] = 43200;
    }
    $has_plan = function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan();
    if ( !$has_plan ) {
        $settings['cache_duration'] = 604800;
    }
    $emails = ( isset( $settings['notify_token_reconnect_emails'] ) && is_array( $settings['notify_token_reconnect_emails'] ) ? $settings['notify_token_reconnect_emails'] : array() );
    $settings['notify_token_reconnect_emails'] = ( function_exists( 'esf_sanitize_notify_emails' ) ? esf_sanitize_notify_emails( $emails ) : array() );
    if ( !empty( $settings['notify_token_reconnect'] ) && empty( $settings['notify_token_reconnect_emails'] ) && function_exists( 'esf_default_notify_emails' ) ) {
        $settings['notify_token_reconnect_emails'] = esf_default_notify_emails();
    }
    return $settings;
}

/**
 * Whether a Twitter account row needs manual reconnect.
 *
 * @since 6.9.0
 * @param object|null $row Account DB row.
 * @return bool
 */
function esf_twitter_account_needs_reconnect(  $row  ) {
    if ( !is_object( $row ) ) {
        return false;
    }
    $status = ( isset( $row->status ) ? sanitize_key( (string) $row->status ) : '' );
    if ( in_array( $status, array('expired', 'invalid'), true ) ) {
        return true;
    }
    if ( 'active' !== $status || 'connected' !== (( isset( $row->account_type ) ? sanitize_key( (string) $row->account_type ) : '' )) ) {
        return false;
    }
    $exp = ( isset( $row->token_expires_at ) ? trim( (string) $row->token_expires_at ) : '' );
    if ( '' === $exp ) {
        return false;
    }
    $ts = strtotime( $exp . ' UTC' );
    if ( false === $ts ) {
        return false;
    }
    return time() > $ts;
}

/**
 * Update Twitter module settings.
 *
 * Updates a specific setting or merges an array of settings.
 *
 * @since 6.7.6
 * @param string|array $key   Setting key or array of settings.
 * @param mixed        $value Setting value (ignored when $key is an array).
 * @return bool True on success, false on failure.
 */
function esf_update_twitter_settings(  $key, $value = null  ) {
    $settings = esf_get_twitter_settings();
    if ( is_array( $key ) ) {
        $settings = array_merge( $settings, $key );
    } else {
        $settings[$key] = $value;
    }
    return update_option( 'esf_twitter_settings', $settings );
}

/**
 * Check if the current user has permission to manage the Twitter module.
 *
 * @since 6.7.6
 * @return bool True if the user can manage, false otherwise.
 */
function esf_twitter_user_can_manage() {
    /**
     * Filters the capability required to manage the Twitter module.
     *
     * @since 6.7.6
     * @param string $capability Required capability.
     */
    $capability = apply_filters( 'esf_twitter_manage_capability', 'manage_options' );
    return current_user_can( $capability );
}

/**
 * Check if the current plan includes Twitter Pro features.
 *
 * Returns true when premium code is available and the user has
 * x_premium or combo_premium. Use this for public-account feeds
 * and any future Pro features so the condition lives in one place.
 *
 * @since 6.7.6
 * @return bool True if Twitter Pro plan is active, false otherwise.
 */
function esf_twitter_has_twitter_plan() {
    if ( !function_exists( 'efl_fs' ) ) {
        return false;
    }
    $fs = efl_fs();
    return false;
    return $fs->is_plan( 'x_premium', true ) || $fs->is_plan( 'combo_premium', true );
}

/**
 * Flag that the Twitter feed script should be enqueued.
 *
 * Set when a feed is rendered so the frontend conditionally enqueues
 * assets in wp_footer.
 *
 * @since 6.7.6
 * @return void
 */
function esf_twitter_flag_feed_script() {
    global $esf_twitter_feed_script_needed;
    $esf_twitter_feed_script_needed = true;
}

/**
 * Flag that the Twitter Load More script should be enqueued.
 *
 * Set when a feed renders the Load More button on the page.
 *
 * @since 6.7.6
 * @return void
 */
function esf_twitter_flag_load_more_script() {
    global $esf_twitter_load_more_script_needed;
    $esf_twitter_load_more_script_needed = true;
}

/**
 * Flag that the Twitter lightbox script should be enqueued.
 *
 * Set when a feed renders popup-enabled media on the page.
 *
 * @since 6.7.6
 * @return void
 */
function esf_twitter_flag_lightbox_script() {
    global $esf_twitter_lightbox_script_needed;
    $esf_twitter_lightbox_script_needed = true;
}

/**
 * Convert tweet text entities to HTML links.
 *
 * Turns URLs, @mentions, and #hashtags in tweet text into clickable
 * anchor tags pointing to X.com.
 *
 * @since 6.7.6
 * @param string $text           Raw tweet text (must already be esc_html'd before passing).
 * @param array  $url_entities   Array of URL entity objects from X API.
 * @param array  $mention_entities Array of mention entity objects from X API.
 * @param array  $hashtag_entities Array of hashtag entity objects from X API.
 * @return string HTML tweet text with clickable links.
 */
function esf_twitter_format_tweet_text(
    $text,
    $url_entities = array(),
    $mention_entities = array(),
    $hashtag_entities = array()
) {
    if ( !is_string( $text ) || '' === $text ) {
        return '';
    }
    // Replace t.co URLs with display_url linked to expanded_url.
    if ( !empty( $url_entities ) && is_array( $url_entities ) ) {
        foreach ( $url_entities as $entity ) {
            if ( empty( $entity['url'] ) ) {
                continue;
            }
            $display = esc_html( ( !empty( $entity['display_url'] ) ? $entity['display_url'] : $entity['url'] ) );
            $expanded = esc_url( ( !empty( $entity['expanded_url'] ) ? $entity['expanded_url'] : $entity['url'] ) );
            $encoded = esc_html( $entity['url'] );
            $text = str_replace( $encoded, '<a href="' . $expanded . '" target="_blank" rel="noopener noreferrer" class="esf-tw-link">' . $display . '</a>', $text );
        }
    }
    // Replace @mentions with links to X.com profiles.
    if ( !empty( $mention_entities ) && is_array( $mention_entities ) ) {
        foreach ( $mention_entities as $entity ) {
            if ( empty( $entity['username'] ) ) {
                continue;
            }
            $handle = esc_html( $entity['username'] );
            $text = str_replace( '@' . $handle, '<a href="https://x.com/' . esc_attr( $entity['username'] ) . '" target="_blank" rel="noopener noreferrer" class="esf-tw-mention">@' . $handle . '</a>', $text );
        }
    }
    // Replace #hashtags with links to X.com hashtag search.
    if ( !empty( $hashtag_entities ) && is_array( $hashtag_entities ) ) {
        foreach ( $hashtag_entities as $entity ) {
            if ( empty( $entity['tag'] ) ) {
                continue;
            }
            $tag = esc_html( $entity['tag'] );
            $text = str_replace( '#' . $tag, '<a href="https://x.com/hashtag/' . esc_attr( $entity['tag'] ) . '" target="_blank" rel="noopener noreferrer" class="esf-tw-hashtag">#' . $tag . '</a>', $text );
        }
    }
    return nl2br( $text );
}

/**
 * Format a number for human-readable display (e.g. 1200 → 1.2K).
 *
 * @since 6.7.6
 * @param int $number Raw integer.
 * @return string Formatted number string.
 */
function esf_twitter_format_number(  $number  ) {
    $number = (int) $number;
    if ( $number >= 1000000 ) {
        return round( $number / 1000000, 1 ) . 'M';
    }
    if ( $number >= 1000 ) {
        return round( $number / 1000, 1 ) . 'K';
    }
    return (string) $number;
}

/**
 * Get the best available profile image URL for X avatars.
 *
 * X profile image URLs often end with size suffixes like `_normal`.
 * For sharper display in larger avatar slots, strip known size suffixes
 * and use the original image when available.
 *
 * @since 6.7.6
 * @param string $url Raw profile image URL.
 * @return string Optimized profile image URL.
 */
function esf_twitter_get_best_profile_image_url(  $url  ) {
    $url = ( is_string( $url ) ? trim( $url ) : '' );
    if ( '' === $url ) {
        return '';
    }
    // Convert known X avatar size variants to original image path.
    $optimized = preg_replace( '/(?:_normal|_bigger|_mini)(\\.[a-z0-9]+(?:\\?.*)?)$/i', '$1', $url );
    return ( is_string( $optimized ) && '' !== $optimized ? $optimized : $url );
}

/**
 * Format a tweet date string as a human-readable time-ago string.
 *
 * @since 6.7.6
 * @param string $date_string ISO 8601 date string from X API (e.g. '2026-03-25T14:30:00.000Z').
 * @return string Human-readable date (e.g. '2h', '3d', 'Mar 25, 2026').
 */
function esf_twitter_format_date(  $date_string  ) {
    if ( empty( $date_string ) || !is_string( $date_string ) ) {
        return '';
    }
    try {
        $dt = new DateTime($date_string, new DateTimeZone('UTC'));
        $diff = time() - $dt->getTimestamp();
        if ( $diff < 60 ) {
            return __( 'just now', 'easy-facebook-likebox' );
        }
        if ( $diff < 3600 ) {
            /* translators: %dm = number of minutes ago */
            return sprintf( __( '%dm', 'easy-facebook-likebox' ), (int) floor( $diff / 60 ) );
        }
        if ( $diff < 86400 ) {
            /* translators: %dh = number of hours ago */
            return sprintf( __( '%dh', 'easy-facebook-likebox' ), (int) floor( $diff / 3600 ) );
        }
        if ( $diff < 604800 ) {
            /* translators: %dd = number of days ago */
            return sprintf( __( '%dd', 'easy-facebook-likebox' ), (int) floor( $diff / 86400 ) );
        }
        return $dt->format( 'M j, Y' );
    } catch ( Exception $e ) {
        return '';
    }
}

/**
 * Log a Twitter module error.
 *
 * Intentionally a no-op in production to avoid logging sensitive tokens
 * or user data to debug.log. Hook here to route errors to a safe,
 * redacted logging system.
 *
 * @since 6.7.6
 * @param string $message Error message.
 * @param array  $context Additional context data.
 * @return void
 */
function esf_twitter_log_error(  $message, $context = array()  ) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future logging implementation.
}

/**
 * Get the Twitter admin page URL.
 *
 * @since 6.7.6
 * @param array $args Optional. Additional query args.
 * @return string Admin page URL.
 */
function esf_get_twitter_admin_url(  $args = array()  ) {
    $default_args = array(
        'page' => 'esf-twitter',
    );
    $args = wp_parse_args( $args, $default_args );
    return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Localize strings for the X/Twitter FancyBox lightbox.
 *
 * Shared by the public frontend and the dashboard preview.
 *
 * @since 6.9.5
 * @return array<string,mixed>
 */
function esf_twitter_get_lightbox_localize_data() {
    $admin = ( class_exists( 'ESF_GDPR_Integrations' ) ? ESF_GDPR_Integrations::get_lightbox_admin_localize_data() : array(
        'adminMediaBlockedText' => '',
        'adminsOnlyText'        => __( 'Admins only', 'easy-facebook-likebox' ),
    ) );
    $is_admin_preview = is_admin() && current_user_can( 'edit_posts' );
    return array_merge( array(
        'restUrl'                 => rest_url( 'esf/v1' ),
        'nonce'                   => wp_create_nonce( 'wp_rest' ),
        'isAdminPreview'          => (bool) $is_admin_preview,
        'viewOnXText'             => __( esf_get_translated_string( 'tw_view_on_x' ), 'easy-facebook-likebox' ),
        'videoText'               => __( 'Video', 'easy-facebook-likebox' ),
        'galleryText'             => __( 'Gallery', 'easy-facebook-likebox' ),
        'previousMediaText'       => __( 'Previous', 'easy-facebook-likebox' ),
        'nextMediaText'           => __( 'Next', 'easy-facebook-likebox' ),
        'imageText'               => __( 'Image', 'easy-facebook-likebox' ),
        'viewImageText'           => __( 'View image', 'easy-facebook-likebox' ),
        'verifiedAccountText'     => __( esf_get_translated_string( 'tw_verified_account' ), 'easy-facebook-likebox' ),
        'repliesText'             => __( esf_get_translated_string( 'tw_replies' ), 'easy-facebook-likebox' ),
        'likesText'               => __( esf_get_translated_string( 'tw_likes' ), 'easy-facebook-likebox' ),
        'retweetsText'            => __( esf_get_translated_string( 'tw_retweets' ), 'easy-facebook-likebox' ),
        'quotesText'              => __( esf_get_translated_string( 'tw_quotes' ), 'easy-facebook-likebox' ),
        'followText'              => __( esf_get_translated_string( 'tw_follow' ), 'easy-facebook-likebox' ),
        'commentsText'            => __( 'Comments', 'easy-facebook-likebox' ),
        'commentsShowingText'     => __( 'Showing %1$s of %2$s', 'easy-facebook-likebox' ),
        'loadMoreCommentsText'    => __( 'Load more comments', 'easy-facebook-likebox' ),
        'loadMoreRepliesText'     => __( 'Load more replies', 'easy-facebook-likebox' ),
        'loadingCommentsText'     => __( 'Loading comments…', 'easy-facebook-likebox' ),
        'noCommentsText'          => __( 'No comments yet.', 'easy-facebook-likebox' ),
        'commentsUnavailableText' => __( 'X only shares recent comments and replies here. View all comments and replies on X.', 'easy-facebook-likebox' ),
        'commentsErrorText'       => __( 'Could not load comments.', 'easy-facebook-likebox' ),
        'viewRepliesText'         => __( 'View all %d replies', 'easy-facebook-likebox' ),
        'hideRepliesText'         => __( 'Hide replies', 'easy-facebook-likebox' ),
        'writeCommentOnXText'     => __( 'Add a comment on X…', 'easy-facebook-likebox' ),
        'anonymousUserText'       => __( 'Anonymous', 'easy-facebook-likebox' ),
    ), $admin );
}

/**
 * Cache key for an account timeline.
 *
 * @since 6.14.1
 * @param int $account_id Account ID.
 * @return string Empty when the account ID is invalid.
 */
function esf_twitter_tweets_cache_key(  $account_id  ) {
    $account_id = (int) $account_id;
    return ( $account_id > 0 ? 'esf_tw_tweets_' . $account_id : '' );
}

/**
 * Whether an account row authenticates with a BYOK (manually pasted) token.
 *
 * BYOK accounts paste a token generated in the site owner's own X Developer
 * App. Reads made with that token are billed to their app, not ESF's shared
 * OAuth app, so BYOK accounts are exempt from the plan-gated cache throttle.
 * See {@see esf_twitter_resolve_tweets_cache_ttl()} and `twitter/PLAN.md`.
 *
 * @since 6.16.0
 * @param object|null $account Account row (expects an `auth_source` property).
 * @return bool
 */
function esf_twitter_account_is_byok(  $account  ) {
    if ( !is_object( $account ) || !isset( $account->auth_source ) ) {
        return false;
    }
    if ( !class_exists( 'ESF_Manual_Access_Token' ) ) {
        return false;
    }
    return ESF_Manual_Access_Token::AUTH_MANUAL === ESF_Manual_Access_Token::normalize_auth_source( (string) $account->auth_source );
}

/**
 * Resolve the tweets-cache TTL (seconds) to use when storing an account's timeline.
 *
 * BYOK accounts (see {@see esf_twitter_account_is_byok()}) always use a short,
 * plan-independent TTL because refreshing them costs ESF nothing. Every other
 * account (OAuth-connected via ESF's shared app, or Pro public/proxy
 * accounts) uses the site's plan-gated `cache_duration` setting, exactly as
 * before this helper existed.
 *
 * @since 6.16.0
 * @param object|null $account Account row (expects an `auth_source` property).
 * @return int TTL in seconds, always greater than zero.
 */
function esf_twitter_resolve_tweets_cache_ttl(  $account  ) {
    if ( esf_twitter_account_is_byok( $account ) ) {
        /**
         * Filter the tweets-cache TTL (seconds) used for BYOK accounts.
         *
         * @since 6.16.0
         * @param int $ttl Default TTL in seconds.
         */
        $ttl = (int) apply_filters( 'esf_twitter_manual_account_cache_ttl', ESF_Twitter_Cache::MANUAL_ACCOUNT_CACHE_TTL );
        return ( $ttl > 0 ? $ttl : ESF_Twitter_Cache::MANUAL_ACCOUNT_CACHE_TTL );
    }
    $ttl = (int) esf_get_twitter_settings( 'cache_duration' );
    return ( $ttl > 0 ? $ttl : 43200 );
}

/**
 * Older feed-scoped cache keys kept for a one-time read fallback.
 *
 * @since 6.14.1
 * @param int $feed_id    Feed ID.
 * @param int $account_id Account ID.
 * @return array<int,string>
 */
function esf_twitter_tweets_cache_legacy_keys(  $feed_id, $account_id  ) {
    $feed_id = (int) $feed_id;
    $account_id = (int) $account_id;
    if ( $feed_id <= 0 || $account_id <= 0 ) {
        return array();
    }
    return array('esf_tw_tweets_' . $feed_id . '_' . $account_id);
}

/**
 * Tweet items from a cache payload.
 *
 * Accepts a flat list or `{ tweets: [] }`. Stale rows still count — customize
 * can render them without a first-load API fetch.
 *
 * @since 6.14.1
 * @param mixed $cached Cache payload.
 * @return array<int,array<string,mixed>>
 */
function esf_twitter_unwrap_cached_tweets(  $cached  ) {
    if ( !is_array( $cached ) || empty( $cached ) ) {
        return array();
    }
    $tweets = $cached;
    if ( isset( $cached['tweets'] ) && is_array( $cached['tweets'] ) ) {
        $tweets = $cached['tweets'];
    }
    if ( empty( $tweets ) || !isset( $tweets[0] ) || !is_array( $tweets[0] ) ) {
        return array();
    }
    return $tweets;
}

/**
 * Whether customize should treat this feed as a first preview.
 *
 * Cold when the Twitter cache table has no row for this account.
 *
 * @since 6.9.6
 * @param int $feed_id    Feed ID.
 * @param int $account_id Account ID.
 * @return bool
 */
function esf_twitter_feed_preview_is_cold(  $feed_id, $account_id  ) {
    $feed_id = max( 0, (int) $feed_id );
    $account_id = max( 0, (int) $account_id );
    if ( $feed_id <= 0 || $account_id <= 0 ) {
        return true;
    }
    if ( !class_exists( 'ESF_Twitter_Cache' ) || !method_exists( 'ESF_Twitter_Cache', 'account_has_cache' ) ) {
        return true;
    }
    return !ESF_Twitter_Cache::account_has_cache( $account_id, $feed_id );
}

/**
 * Whether a cached tweet payload can be used without refetching.
 *
 * Rows that still store local upload URLs are treated as stale.
 *
 * @since 6.14.1
 * @param mixed $cached Cache payload.
 * @return bool
 */
function esf_twitter_tweets_cache_is_warm(  $cached  ) {
    if ( !is_array( $cached ) || empty( $cached ) ) {
        return false;
    }
    $tweets = $cached;
    if ( isset( $cached['tweets'] ) && is_array( $cached['tweets'] ) ) {
        $tweets = $cached['tweets'];
    }
    if ( empty( $tweets ) || !isset( $tweets[0] ) || !is_array( $tweets[0] ) ) {
        return false;
    }
    if ( !function_exists( 'esf_is_local_media_url' ) ) {
        return true;
    }
    foreach ( $tweets as $tweet ) {
        if ( !is_array( $tweet ) ) {
            continue;
        }
        if ( !empty( $tweet['author'] ) && is_array( $tweet['author'] ) ) {
            $avatar = ( isset( $tweet['author']['profile_image_url'] ) ? (string) $tweet['author']['profile_image_url'] : '' );
            if ( '' !== $avatar && esf_is_local_media_url( $avatar, 'twitter' ) ) {
                return false;
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
                    return false;
                }
            }
        }
    }
    return true;
}

/**
 * Whether a media target is an X video file.
 *
 * @since 6.14.1
 * @param mixed  $id  Media cache key.
 * @param string $url Remote URL.
 * @return bool
 */
function esf_twitter_is_video_media_target(  $id, $url  ) {
    $id = (string) $id;
    $url = (string) $url;
    if ( 0 === strpos( $id, 'tw_video_' ) ) {
        return true;
    }
    return false !== strpos( $url, 'video.twimg.com' ) || false !== strpos( $url, 'video.twitter.com' );
}

/**
 * Disable local downloads for X video files.
 *
 * @since 6.14.1
 * @param bool        $enabled Whether downloads are enabled.
 * @param string      $module  Module slug.
 * @param mixed       $id      Media cache key.
 * @param string|null $url     Remote URL.
 * @return bool
 */
function esf_twitter_should_serve_media_locally(
    $enabled,
    $module,
    $id = null,
    $url = null
) {
    if ( 'twitter' === $module && esf_twitter_is_video_media_target( $id, (string) $url ) ) {
        return false;
    }
    return (bool) $enabled;
}

if ( function_exists( 'add_filter' ) ) {
    add_filter(
        'esf_should_serve_media_locally',
        'esf_twitter_should_serve_media_locally',
        10,
        4
    );
}
/**
 * X embedded player URL for a tweet video.
 *
 * @since 6.14.1
 * @param string $tweet_id Tweet ID.
 * @return string Empty when the ID is invalid.
 */
function esf_twitter_video_embed_url(  $tweet_id  ) {
    $tweet_id = (string) preg_replace( '/\\D+/', '', (string) $tweet_id );
    return ( '' !== $tweet_id ? 'https://twitter.com/i/videos/tweet/' . $tweet_id : '' );
}

/**
 * Apply shared moderation hide/show rules to normalized tweets.
 *
 * Account-scoped cache stays unfiltered; each feed applies its own rules
 * after cache/API fetch so sibling feeds can moderate differently.
 *
 * @since 6.9.0
 *
 * @param array<int,array<string,mixed>> $tweets  Normalized tweet items.
 * @param int                            $feed_id Feed id.
 * @return array<int,array<string,mixed>>
 */
function esf_twitter_apply_moderation_filter(  $tweets, $feed_id  ) {
    if ( !is_array( $tweets ) ) {
        return array();
    }
    $feed_id = (int) $feed_id;
    if ( $feed_id <= 0 || !class_exists( 'ESF_Moderation' ) ) {
        return array_values( $tweets );
    }
    if ( !function_exists( 'efl_fs' ) || !efl_fs()->can_use_premium_code__premium_only() ) {
        return array_values( $tweets );
    }
    if ( !function_exists( 'esf_twitter_has_twitter_plan' ) || !esf_twitter_has_twitter_plan() ) {
        return array_values( $tweets );
    }
    return ESF_Moderation::filter_raw_nodes( $tweets, 'twitter', $feed_id );
}

if ( function_exists( 'add_filter' ) && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
    add_filter(
        'esf_moderation_get_feed_settings',
        'esf_twitter_moderation_get_feed_settings__premium_only',
        10,
        3
    );
    add_filter(
        'esf_moderation_save_feed_settings',
        'esf_twitter_moderation_save_feed_settings__premium_only',
        10,
        4
    );
    add_filter(
        'esf_moderation_feed_exists',
        'esf_twitter_moderation_feed_exists__premium_only',
        10,
        3
    );
}
/* </fs_premium_only> */