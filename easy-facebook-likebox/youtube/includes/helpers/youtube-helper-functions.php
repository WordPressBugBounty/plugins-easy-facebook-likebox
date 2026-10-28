<?php

/**
 * YouTube module helpers.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Helpers
 * @since 6.7.5
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Check if YouTube module is currently active.
 *
 * @since 6.7.5
 * @return bool True if active, false otherwise.
 */
function esf_is_youtube_active() {
    $fta_settings = get_option( 'fta_settings', array() );
    $status = ( isset( $fta_settings['plugins']['youtube']['status'] ) ? $fta_settings['plugins']['youtube']['status'] : 'activated' );
    return 'activated' === $status;
}

/**
 * Get YouTube module status.
 *
 * @since 6.7.5
 * @return string 'activated' or 'deactivated'.
 */
function esf_get_youtube_status() {
    $fta_settings = get_option( 'fta_settings', array() );
    return ( isset( $fta_settings['plugins']['youtube']['status'] ) ? $fta_settings['plugins']['youtube']['status'] : 'activated' );
}

/**
 * Get YouTube settings, or one key when provided.
 *
 * @since 6.7.5
 * @param string|null $key Optional setting key.
 * @return mixed Settings array or a single value.
 */
function esf_get_youtube_settings(  $key = null  ) {
    $saved = get_option( 'esf_youtube_settings', array() );
    if ( !is_array( $saved ) ) {
        $saved = array();
    }
    $settings = esf_youtube_normalize_settings( $saved );
    if ( null !== $key ) {
        return ( array_key_exists( $key, $settings ) ? $settings[$key] : null );
    }
    return $settings;
}

/**
 * Merge saved YouTube settings with module defaults.
 *
 * @since 6.9.0
 * @param array<string, mixed> $saved Raw option values.
 * @return array<string, mixed>
 */
function esf_youtube_normalize_settings(  array $saved  ) {
    $settings = array_merge( array(
        'cache_duration'                => 43200,
        'notify_token_reconnect'        => true,
        'notify_token_reconnect_emails' => array(),
    ), $saved );
    $allowed = array(
        3600,
        10800,
        21600,
        43200,
        86400,
        604800
    );
    if ( !in_array( (int) $settings['cache_duration'], $allowed, true ) ) {
        $settings['cache_duration'] = 43200;
    }
    $emails = ( isset( $settings['notify_token_reconnect_emails'] ) && is_array( $settings['notify_token_reconnect_emails'] ) ? $settings['notify_token_reconnect_emails'] : array() );
    $settings['notify_token_reconnect_emails'] = ( function_exists( 'esf_sanitize_notify_emails' ) ? esf_sanitize_notify_emails( $emails ) : array() );
    if ( !empty( $settings['notify_token_reconnect'] ) && empty( $settings['notify_token_reconnect_emails'] ) && function_exists( 'esf_default_notify_emails' ) ) {
        $settings['notify_token_reconnect_emails'] = esf_default_notify_emails();
    }
    return $settings;
}

/**
 * Whether a YouTube account row needs manual reconnect.
 *
 * @since 6.9.0
 * @param object|null $row Account DB row.
 * @return bool
 */
function esf_youtube_account_needs_reconnect(  $row  ) {
    if ( !is_object( $row ) ) {
        return false;
    }
    $status = ( isset( $row->status ) ? sanitize_key( (string) $row->status ) : '' );
    if ( in_array( $status, array('expired', 'invalid'), true ) ) {
        return true;
    }
    if ( 'active' !== $status ) {
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
 * Update YouTube settings (one key or a merge of an array).
 *
 * @since 6.7.5
 * @param string|array $key   Setting key, or array of settings to merge.
 * @param mixed        $value Value when $key is a string.
 * @return bool
 */
function esf_update_youtube_settings(  $key, $value = null  ) {
    $settings = esf_get_youtube_settings();
    if ( is_array( $key ) ) {
        $settings = array_merge( $settings, $key );
    } else {
        $settings[$key] = $value;
    }
    return update_option( 'esf_youtube_settings', $settings );
}

/**
 * Whether the current user can manage YouTube.
 *
 * @since 6.7.5
 * @return bool
 */
function esf_youtube_user_can_manage() {
    /**
     * Filters the capability required to manage YouTube module.
     *
     * @since 6.7.5
     * @param string $capability Required capability.
     */
    $capability = apply_filters( 'esf_youtube_manage_capability', 'manage_options' );
    return current_user_can( $capability );
}

/**
 * Whether the site has YouTube Pro (or combo).
 *
 * @since 6.7.5
 * @return bool
 */
function esf_youtube_has_youtube_plan() {
    if ( !function_exists( 'efl_fs' ) ) {
        return false;
    }
    $fs = efl_fs();
    return false;
    return $fs->is_plan( 'youtube_premium', true ) || $fs->is_plan( 'combo_premium', true );
}

/**
 * Whether a feed type requires YouTube Pro (playlist / search).
 *
 * @since 6.7.6
 *
 * @param string $feed_type Raw feed type.
 * @return bool
 */
function esf_youtube_is_pro_feed_type(  $feed_type  ) {
    $feed_type = sanitize_key( (string) $feed_type );
    return in_array( $feed_type, array('playlist', 'search'), true );
}

/**
 * Whether Multifeed is available for YouTube feeds.
 *
 * Multifeed capability allows multiple channels (accounts) in a single feed.
 * It is unlocked by:
 * - YouTube Pro plan (youtube_premium / combo_premium), or
 * - Standalone Multifeed addon (class_exists Esf_Multifeed_YouTube_Modern), or
 * - Shared ESF_Multifeed::is_available( 'youtube' ).
 *
 * @since 6.14.0
 * @return bool
 */
function esf_youtube_can_use_multifeed() {
    if ( !function_exists( 'efl_fs' ) ) {
        return false;
    }
    // Marker class from standalone addon.
    if ( class_exists( 'Esf_Multifeed_YouTube_Modern', false ) ) {
        return true;
    }
    // Shared Multifeed detector (includes Pro plans + addon).
    if ( class_exists( 'ESF_Multifeed' ) && ESF_Multifeed::is_available( 'youtube' ) ) {
        return true;
    }
    return false;
}

/**
 * Allowed Multifeed sort modes.
 *
 * @since 6.14.0
 * @return string[] newest|balanced
 */
function esf_youtube_multifeed_sort_modes() {
    return ( class_exists( 'ESF_Multifeed' ) ? ESF_Multifeed::sort_modes() : array('newest', 'balanced') );
}

/**
 * Resolve a Multifeed sort mode (newest|balanced).
 *
 * @since 6.14.0
 *
 * @param array|string $feed_cfg Feed settings or sort value.
 * @return string newest|balanced
 */
function esf_youtube_resolve_multifeed_sort(  $feed_cfg  ) {
    if ( class_exists( 'ESF_Multifeed' ) ) {
        return ESF_Multifeed::resolve_sort( $feed_cfg );
    }
    if ( is_array( $feed_cfg ) ) {
        $feed_cfg = ( array_key_exists( 'multifeed_sort', $feed_cfg ) ? $feed_cfg['multifeed_sort'] : 'newest' );
    }
    if ( !is_scalar( $feed_cfg ) || '' === (string) $feed_cfg ) {
        return 'newest';
    }
    $sort = sanitize_key( (string) $feed_cfg );
    return ( in_array( $sort, esf_youtube_multifeed_sort_modes(), true ) ? $sort : 'newest' );
}

/**
 * Sort Multifeed nodes by sort mode.
 *
 * @since 6.14.0
 *
 * @param array<int,array<string,mixed>> $nodes Nodes (videos).
 * @param string                         $sort  newest|balanced.
 * @return array<int,array<string,mixed>>
 */
function esf_youtube_sort_multifeed_nodes(  array $nodes, $sort = 'newest'  ) {
    if ( class_exists( 'ESF_Multifeed' ) ) {
        return ESF_Multifeed::sort_nodes( $nodes, $sort, '_esf_account_id' );
    }
    // Fallback: sort newest-first by published_at timestamp.
    $decorated = array();
    foreach ( $nodes as $index => $node ) {
        if ( !is_array( $node ) ) {
            continue;
        }
        $time = 0;
        if ( !empty( $node['published_at'] ) && is_string( $node['published_at'] ) ) {
            $parsed = strtotime( $node['published_at'] );
            if ( false !== $parsed ) {
                $time = (int) $parsed;
            }
        }
        $decorated[] = array(
            'node'  => $node,
            'time'  => $time,
            'id'    => ( isset( $node['id'] ) ? (string) $node['id'] : '' ),
            'index' => (int) $index,
        );
    }
    usort( $decorated, static function ( array $left, array $right ) {
        $left_dated = ( $left['time'] > 0 ? 1 : 0 );
        $right_dated = ( $right['time'] > 0 ? 1 : 0 );
        if ( $left_dated !== $right_dated ) {
            return $right_dated <=> $left_dated;
        }
        if ( $left['time'] !== $right['time'] ) {
            return $right['time'] <=> $left['time'];
        }
        $id_cmp = strcmp( $right['id'], $left['id'] );
        if ( 0 !== $id_cmp ) {
            return $id_cmp;
        }
        return $left['index'] <=> $right['index'];
    } );
    $out = array();
    foreach ( $decorated as $row ) {
        $out[] = $row['node'];
    }
    return $out;
}

/**
 * Resolve account IDs for a YouTube feed (primary + Multifeed extras).
 *
 * Returns the primary account_id from the feed row, plus additional account_ids
 * from settings.feed.account_ids when Multifeed is available.
 *
 * @since 6.14.0
 *
 * @param object|array $feed_obj Feed object or array with account_id and settings.
 * @return int[] Array of account IDs (deduplicated, primary first).
 */
function esf_youtube_resolve_account_ids(  $feed_obj  ) {
    $ids = array();
    if ( !is_object( $feed_obj ) && !is_array( $feed_obj ) ) {
        return $ids;
    }
    $feed_obj = (object) $feed_obj;
    $primary = ( isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0 );
    if ( $primary > 0 ) {
        $ids[] = $primary;
    }
    if ( !esf_youtube_can_use_multifeed() ) {
        return $ids;
    }
    $settings = ( isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array() );
    $account_ids = ( isset( $settings['feed']['account_ids'] ) && is_array( $settings['feed']['account_ids'] ) ? $settings['feed']['account_ids'] : array() );
    foreach ( $account_ids as $id ) {
        $id = (int) $id;
        if ( $id > 0 && !in_array( $id, $ids, true ) ) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Whether a YouTube feed is configured as Multifeed (2+ source accounts).
 *
 * @since 6.14.0
 *
 * @param object|array|null $feed_obj Feed object or array.
 * @return bool
 */
function esf_youtube_feed_is_multifeed(  $feed_obj  ) {
    return count( esf_youtube_resolve_account_ids( $feed_obj ) ) > 1;
}

/**
 * Resolve the AccountSummary that owns a video (Multifeed-aware).
 *
 * Prefers `_esf_account_id` stamped during Multifeed merge; falls back to
 * the primary account summary when the stamp is missing.
 *
 * @since 6.14.0
 *
 * @param array<string,mixed>                                                 $video            Normalized video.
 * @param \EasySocialFeed\Layouts\ValueObjects\AccountSummary|null            $fallback_account Primary feed account.
 * @param array<int,\EasySocialFeed\Layouts\ValueObjects\AccountSummary|null> $account_cache Optional id → summary cache (mutated).
 * @return \EasySocialFeed\Layouts\ValueObjects\AccountSummary|null
 */
function esf_youtube_account_for_video(  array $video, $fallback_account = null, array &$account_cache = array()  ) {
    $owner_id = ( isset( $video['_esf_account_id'] ) ? (int) $video['_esf_account_id'] : 0 );
    // Fallback: match connected account by YouTube channel ID on the video node.
    if ( $owner_id <= 0 ) {
        $channel_id = '';
        if ( !empty( $video['channel_id'] ) ) {
            $channel_id = (string) $video['channel_id'];
        } elseif ( !empty( $video['snippet']['channelId'] ) ) {
            $channel_id = (string) $video['snippet']['channelId'];
        }
        if ( '' !== $channel_id && class_exists( 'ESF_YouTube_Account_Repository' ) ) {
            $row = ESF_YouTube_Account_Repository::get_instance()->get_by_channel_id( $channel_id );
            if ( $row && isset( $row->id ) ) {
                $owner_id = (int) $row->id;
            }
        }
    }
    if ( $owner_id <= 0 ) {
        return $fallback_account;
    }
    if ( array_key_exists( $owner_id, $account_cache ) ) {
        $cached = $account_cache[$owner_id];
        return ( null !== $cached ? $cached : $fallback_account );
    }
    $resolved = null;
    if ( class_exists( 'ESF_YouTube_Renderer' ) ) {
        $row = ( class_exists( 'ESF_YouTube_Account_Repository' ) ? ESF_YouTube_Account_Repository::get_instance()->get_by_id( $owner_id ) : null );
        $resolved = ESF_YouTube_Renderer::resolve_youtube_account( $owner_id, $row );
    }
    $account_cache[$owner_id] = $resolved;
    return ( null !== $resolved ? $resolved : $fallback_account );
}

/**
 * Normalize feed content filter (all | shorts | long).
 *
 * @since 6.7.6
 *
 * @param mixed $raw Raw filter value.
 * @return string One of all|shorts|long.
 */
function esf_youtube_normalize_content_filter(  $raw  ) {
    $filter = sanitize_key( (string) $raw );
    if ( in_array( $filter, array('all', 'shorts', 'long'), true ) ) {
        return $filter;
    }
    return 'all';
}

/**
 * Whether content_filter requires YouTube Pro.
 *
 * @since 6.7.6
 *
 * @param string $filter Normalized content filter.
 * @return bool
 */
function esf_youtube_is_pro_content_filter(  $filter  ) {
    return 'all' !== esf_youtube_normalize_content_filter( $filter );
}

/**
 * Normalize a YouTube `status.privacyStatus` value.
 *
 * @since 6.8.3
 *
 * @param mixed $raw Raw privacy status from the API or cache.
 * @return string One of public|unlisted|private, or empty string when unknown.
 */
function esf_youtube_normalize_privacy_status(  $raw  ) {
    $status = sanitize_key( (string) $raw );
    if ( in_array( $status, array('public', 'unlisted', 'private'), true ) ) {
        return $status;
    }
    return '';
}

/**
 * Normalize feed video visibility (public | all | unlisted | private).
 *
 * Defaults to `public` so a feed never exposes unlisted/private videos unless
 * a Pro user explicitly opts in.
 *
 * @since 6.8.3
 *
 * @param mixed $raw Raw visibility value.
 * @return string One of public|all|unlisted|private.
 */
function esf_youtube_normalize_video_visibility(  $raw  ) {
    $visibility = sanitize_key( (string) $raw );
    if ( in_array( $visibility, array(
        'public',
        'all',
        'unlisted',
        'private'
    ), true ) ) {
        return $visibility;
    }
    return 'public';
}

/**
 * Whether a video visibility value requires YouTube Pro.
 *
 * @since 6.8.3
 *
 * @param string $visibility Visibility value.
 * @return bool True for every option except `public`.
 */
function esf_youtube_is_pro_video_visibility(  $visibility  ) {
    return 'public' !== esf_youtube_normalize_video_visibility( $visibility );
}

/**
 * Resolve the effective video visibility from feed settings.
 *
 * Free sites are always forced to `public`, regardless of what is stored.
 *
 * @since 6.8.3
 *
 * @param array|null $settings Feed settings.
 * @return string public|all|unlisted|private.
 */
function esf_youtube_video_visibility_from_settings(  $settings  ) {
    $visibility = 'public';
    if ( is_array( $settings ) && isset( $settings['feed'] ) && is_array( $settings['feed'] ) && isset( $settings['feed']['video_visibility'] ) ) {
        $visibility = esf_youtube_normalize_video_visibility( $settings['feed']['video_visibility'] );
    }
    if ( esf_youtube_is_pro_video_visibility( $visibility ) && (!function_exists( 'esf_youtube_has_youtube_plan' ) || !esf_youtube_has_youtube_plan()) ) {
        return 'public';
    }
    return $visibility;
}

/**
 * Filter normalized videos by privacy status.
 *
 * Videos without a known `privacy_status` (e.g. caches created before this
 * field existed, or a failed statistics call) are treated as public so a
 * missing value never makes an existing video disappear. The renderer
 * backfills the field for cached lists before filtering.
 *
 * Non-public modes are Pro-only; without a YouTube plan the filter always
 * behaves as `public`.
 *
 * @since 6.8.3
 *
 * @param array  $videos     Normalized video items.
 * @param string $visibility public|all|unlisted|private.
 * @return array
 */
function esf_youtube_filter_videos_by_visibility(  $videos, $visibility = 'public'  ) {
    if ( !is_array( $videos ) ) {
        return array();
    }
    $visibility = esf_youtube_normalize_video_visibility( $visibility );
    if ( 'public' !== $visibility && (!function_exists( 'esf_youtube_has_youtube_plan' ) || !esf_youtube_has_youtube_plan()) ) {
        $visibility = 'public';
    }
    if ( 'all' === $visibility ) {
        return $videos;
    }
    $out = array();
    foreach ( $videos as $video ) {
        $status = '';
        if ( is_array( $video ) && isset( $video['privacy_status'] ) ) {
            $status = esf_youtube_normalize_privacy_status( $video['privacy_status'] );
        }
        if ( '' === $status ) {
            $status = 'public';
        }
        if ( $status === $visibility ) {
            $out[] = $video;
        }
    }
    return $out;
}

/**
 * Derive YouTube's channel Shorts playlist ID (UUSH…) from a channel ID (UC…).
 *
 * This is the playlist behind the channel Shorts tab — the most reliable
 * Shorts signal available without a dedicated API flag.
 *
 * @since 6.7.6
 *
 * @param mixed $channel_id YouTube channel ID (UC…).
 * @return string Shorts playlist ID, or empty string when not derivable.
 */
function esf_youtube_channel_shorts_playlist_id(  $channel_id  ) {
    $channel_id = trim( (string) $channel_id );
    if ( !preg_match( '/^UC[a-zA-Z0-9_-]{20,}$/', $channel_id ) ) {
        return '';
    }
    return 'UUSH' . substr( $channel_id, 2 );
}

/**
 * Cache key for the account's Shorts video-ID set (from the UUSH playlist).
 *
 * @since 6.7.6
 *
 * @param int $account_id Account ID.
 * @return string Empty when invalid.
 */
function esf_youtube_shorts_ids_cache_key(  $account_id  ) {
    $account_id = (int) $account_id;
    if ( $account_id <= 0 ) {
        return '';
    }
    return 'esf_yt_shorts_ids_' . $account_id;
}

/**
 * Extract video_id values from normalized video items as a lookup map.
 *
 * @since 6.7.6
 *
 * @param array $videos Normalized videos.
 * @return array<string,bool> Map of video_id => true.
 */
function esf_youtube_video_ids_lookup(  $videos  ) {
    $lookup = array();
    if ( !is_array( $videos ) ) {
        return $lookup;
    }
    foreach ( $videos as $video ) {
        if ( !is_array( $video ) ) {
            continue;
        }
        $id = '';
        if ( !empty( $video['video_id'] ) ) {
            $id = (string) $video['video_id'];
        } elseif ( !empty( $video['id'] ) ) {
            $id = (string) $video['id'];
        }
        $id = trim( $id );
        if ( '' !== $id ) {
            $lookup[$id] = true;
        }
    }
    return $lookup;
}

/**
 * Resolve content_filter from feed settings array.
 *
 * @since 6.7.6
 *
 * @param array|null $settings Feed settings.
 * @return string all|shorts|long.
 */
function esf_youtube_content_filter_from_settings(  $settings  ) {
    if ( !is_array( $settings ) || !isset( $settings['feed'] ) || !is_array( $settings['feed'] ) ) {
        return 'all';
    }
    return esf_youtube_normalize_content_filter( ( isset( $settings['feed']['content_filter'] ) ? $settings['feed']['content_filter'] : 'all' ) );
}

/**
 * Resolve the effective videos source for cache/fetch, applying Shorts playlist rewrite.
 *
 * Channel + Shorts-only (Pro) fetches YouTube's UUSH Shorts playlist instead of uploads.
 *
 * @since 6.7.6
 *
 * @param string $feed_type      channel|playlist|search.
 * @param string $source_id      Playlist ID or search query.
 * @param string $content_filter all|shorts|long.
 * @param string $channel_id     Connected channel ID (UC…).
 * @return array{feed_type:string,source_id:string,content_filter:string,shorts_playlist:bool}
 */
function esf_youtube_resolve_videos_source(
    $feed_type,
    $source_id,
    $content_filter = 'all',
    $channel_id = ''
) {
    $feed_type = sanitize_key( (string) $feed_type );
    $source_id = trim( (string) $source_id );
    $content_filter = esf_youtube_normalize_content_filter( $content_filter );
    $channel_id = trim( (string) $channel_id );
    if ( '' === $feed_type ) {
        $feed_type = 'channel';
    }
    $shorts_playlist = false;
    if ( 'shorts' === $content_filter && 'channel' === $feed_type && function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan() ) {
        $shorts_pl = esf_youtube_channel_shorts_playlist_id( $channel_id );
        if ( '' !== $shorts_pl ) {
            $feed_type = 'playlist';
            $source_id = $shorts_pl;
            $shorts_playlist = true;
        }
    }
    return array(
        'feed_type'       => $feed_type,
        'source_id'       => $source_id,
        'content_filter'  => $content_filter,
        'shorts_playlist' => $shorts_playlist,
    );
}

/**
 * Parse ISO 8601 duration (or numeric seconds) to total seconds.
 *
 * @since 6.7.6
 *
 * @param mixed $duration ISO 8601 (PT1M30S) or numeric seconds.
 * @return int Seconds (0 when unknown/invalid).
 */
function esf_youtube_parse_duration_seconds(  $duration  ) {
    if ( is_numeric( $duration ) ) {
        return max( 0, (int) $duration );
    }
    $duration = trim( (string) $duration );
    if ( '' === $duration ) {
        return 0;
    }
    try {
        $interval = new DateInterval($duration);
    } catch ( Exception $e ) {
        return 0;
    }
    return (int) $interval->d * 86400 + (int) $interval->h * 3600 + (int) $interval->i * 60 + (int) $interval->s;
}

/**
 * Heuristic Shorts detection (fallback when UUSH ID set is unavailable).
 *
 * Prefer {@see esf_youtube_channel_shorts_playlist_id()} / Shorts ID membership.
 * Fallback signals: `#shorts` in title/description, or a `/shorts/` URL.
 * Duration is intentionally not used — short long-form clips caused false positives.
 *
 * @since 6.7.6
 *
 * @param array $video Normalized video item.
 * @return bool
 */
function esf_youtube_is_short_video(  $video  ) {
    if ( !is_array( $video ) ) {
        return false;
    }
    $title = ( isset( $video['title'] ) ? (string) $video['title'] : '' );
    $desc = ( isset( $video['description'] ) ? (string) $video['description'] : '' );
    if ( false !== stripos( $title, '#shorts' ) || false !== stripos( $desc, '#shorts' ) ) {
        return true;
    }
    $url = '';
    if ( isset( $video['video_url'] ) ) {
        $url = (string) $video['video_url'];
    } elseif ( isset( $video['url'] ) ) {
        $url = (string) $video['url'];
    }
    return '' !== $url && false !== strpos( $url, '/shorts/' );
}

/**
 * Max duration (seconds) treated as a Short for lightbox / tile chrome.
 *
 * YouTube's original Shorts limit. Longer Shorts (up to 3 minutes) are still
 * classified via UUSH membership or `#shorts` / `/shorts/` so 2–3 minute
 * long-form clips are not forced into portrait chrome.
 *
 * @since 6.16.0
 *
 * @return int
 */
function esf_youtube_short_layout_max_duration_seconds() {
    return 60;
}

/**
 * Resolve duration in seconds from a normalized video item.
 *
 * @since 6.16.0
 *
 * @param array $video Normalized video.
 * @return int
 */
function esf_youtube_video_duration_seconds(  $video  ) {
    if ( !is_array( $video ) ) {
        return 0;
    }
    if ( isset( $video['duration_seconds'] ) && is_numeric( $video['duration_seconds'] ) ) {
        return max( 0, (int) $video['duration_seconds'] );
    }
    if ( isset( $video['duration'] ) ) {
        return esf_youtube_parse_duration_seconds( $video['duration'] );
    }
    return 0;
}

/**
 * Whether a video is a Short, preferring an official UUSH ID lookup when provided.
 *
 * Membership in `$short_ids` is authoritative true. IDs missing from a
 * (possibly truncated) UUSH fetch still fall back to
 * {@see esf_youtube_is_short_video()} so `#shorts` / `/shorts/` are not lost.
 *
 * @since 6.7.6
 *
 * @param array                   $video     Normalized video.
 * @param array<string,bool>|null $short_ids Optional map of Short video_id => true.
 * @return bool
 */
function esf_youtube_video_is_short(  $video, $short_ids = null  ) {
    if ( !is_array( $video ) ) {
        return false;
    }
    if ( is_array( $short_ids ) ) {
        $id = '';
        if ( !empty( $video['video_id'] ) ) {
            $id = (string) $video['video_id'];
        } elseif ( !empty( $video['id'] ) ) {
            $id = (string) $video['id'];
        }
        $id = trim( $id );
        if ( '' !== $id && isset( $short_ids[$id] ) ) {
            return true;
        }
    }
    return esf_youtube_is_short_video( $video );
}

/**
 * Whether a video should use Shorts (9:16) chrome in the lightbox / tiles.
 *
 * Priority: Shorts-only feed → cached `is_short` stamp → UUSH ID membership →
 * `#shorts` / `/shorts/` → duration 1–60s (layout only, not content filtering).
 *
 * @since 6.16.0
 *
 * @param array                   $video          Normalized video.
 * @param string                  $content_filter all|shorts|long.
 * @param array<string,bool>|null $short_ids      Optional official Shorts ID lookup.
 * @return bool
 */
function esf_youtube_should_render_as_short(  $video, $content_filter = 'all', $short_ids = null  ) {
    $content_filter = esf_youtube_normalize_content_filter( $content_filter );
    if ( 'shorts' === $content_filter ) {
        return true;
    }
    if ( !is_array( $video ) ) {
        return false;
    }
    if ( array_key_exists( 'is_short', $video ) ) {
        $flag = $video['is_short'];
        if ( true === $flag || 1 === $flag || '1' === $flag ) {
            return true;
        }
    }
    if ( esf_youtube_video_is_short( $video, $short_ids ) ) {
        return true;
    }
    $seconds = esf_youtube_video_duration_seconds( $video );
    $max = esf_youtube_short_layout_max_duration_seconds();
    return $seconds > 0 && $seconds <= $max;
}

/**
 * Stamp `is_short` on normalized videos for lightbox / tile consumers.
 *
 * @since 6.16.0
 *
 * @param array                   $videos         Normalized videos.
 * @param array<string,bool>|null $short_ids      Optional official Shorts ID lookup.
 * @param bool                    $all_are_shorts True when items already come from UUSH.
 * @return array
 */
function esf_youtube_mark_short_videos(  $videos, $short_ids = null, $all_are_shorts = false  ) {
    if ( !is_array( $videos ) ) {
        return array();
    }
    $filter = ( $all_are_shorts ? 'shorts' : 'all' );
    $out = array();
    foreach ( $videos as $video ) {
        if ( !is_array( $video ) ) {
            continue;
        }
        $video['is_short'] = esf_youtube_should_render_as_short( $video, $filter, $short_ids );
        $out[] = $video;
    }
    return $out;
}

/**
 * Filter videos by content_filter setting (Pro: shorts | long).
 *
 * Prefer `$short_ids` from the channel UUSH playlist. When that map is null,
 * falls back to {@see esf_youtube_is_short_video()}. Without a YouTube plan,
 * non-all filters are ignored.
 *
 * @since 6.7.6
 *
 * @param array                   $videos    Normalized video items.
 * @param string                  $filter    all|shorts|long.
 * @param array<string,bool>|null $short_ids Optional official Shorts ID lookup.
 * @return array
 */
function esf_youtube_apply_content_filter(  $videos, $filter, $short_ids = null  ) {
    if ( !is_array( $videos ) ) {
        return array();
    }
    $filter = esf_youtube_normalize_content_filter( $filter );
    if ( 'all' === $filter ) {
        return $videos;
    }
    if ( !function_exists( 'esf_youtube_has_youtube_plan' ) || !esf_youtube_has_youtube_plan() ) {
        return $videos;
    }
    $out = array();
    foreach ( $videos as $video ) {
        $is_short = esf_youtube_video_is_short( ( is_array( $video ) ? $video : array() ), $short_ids );
        if ( 'shorts' === $filter && $is_short ) {
            $out[] = $video;
        } elseif ( 'long' === $filter && !$is_short ) {
            $out[] = $video;
        }
    }
    return $out;
}

/**
 * Shared videos cache key for an account + source.
 *
 * Channel uploads are account-scoped (shared across sibling feeds). Playlist
 * and search keys include type/source so they stay separate.
 *
 * @since 6.7.6
 *
 * @param int    $account_id Account ID.
 * @param string $feed_type  channel|playlist|search.
 * @param string $source_id  Playlist ID or search query.
 * @return string Empty when account_id is invalid.
 */
function esf_youtube_videos_cache_key(  $account_id, $feed_type = 'channel', $source_id = ''  ) {
    $account_id = (int) $account_id;
    if ( $account_id <= 0 ) {
        return '';
    }
    $feed_type = sanitize_key( (string) $feed_type );
    if ( '' === $feed_type ) {
        $feed_type = 'channel';
    }
    if ( 'channel' === $feed_type ) {
        return 'esf_yt_videos_' . $account_id;
    }
    $source_id = trim( (string) $source_id );
    return 'esf_yt_videos_' . $account_id . '_' . md5( $feed_type . '|' . $source_id );
}

/**
 * Pre-shared (feed-scoped) video cache keys for one-time read fallback.
 *
 * @since 6.7.6
 * @see esf_youtube_videos_cache_key()
 *
 * @param int    $feed_id    Feed ID.
 * @param int    $account_id Account ID.
 * @param string $feed_type  Feed type.
 * @param string $source_id  Playlist ID or search query.
 * @return array<int,string>
 */
function esf_youtube_videos_cache_legacy_keys(
    $feed_id,
    $account_id,
    $feed_type = 'channel',
    $source_id = ''
) {
    $feed_id = (int) $feed_id;
    $account_id = (int) $account_id;
    if ( $feed_id <= 0 || $account_id <= 0 ) {
        return array();
    }
    $feed_type = sanitize_key( (string) $feed_type );
    if ( '' === $feed_type ) {
        $feed_type = 'channel';
    }
    $source_id = trim( (string) $source_id );
    $suffix = md5( $feed_type . '|' . $source_id );
    return array('esf_yt_videos_' . $feed_id . '_' . $account_id . '_' . $suffix, 'esf_yt_videos_' . $feed_id . '_' . $account_id);
}

/**
 * Normalize a playlist ID or playlist URL to a YouTube playlist ID.
 *
 * Accepts bare IDs (e.g. PLxxxx) or watch/playlist URLs with a `list=` param.
 *
 * @since 6.7.6
 *
 * @param mixed $raw Raw playlist ID or URL.
 * @return string Normalized playlist ID, or empty string when invalid.
 */
function esf_youtube_normalize_playlist_id(  $raw  ) {
    $value = trim( (string) $raw );
    if ( '' === $value ) {
        return '';
    }
    if ( preg_match( '/[?&]list=([a-zA-Z0-9_-]+)/', $value, $matches ) ) {
        $value = (string) $matches[1];
    }
    $value = sanitize_text_field( $value );
    if ( '' === $value ) {
        return '';
    }
    // YouTube playlist IDs are typically PL… / UU… / LL… / OL… (alphanumeric + _-).
    if ( !preg_match( '/^[a-zA-Z0-9_-]{2,128}$/', $value ) ) {
        return '';
    }
    return $value;
}

/**
 * Normalize a YouTube search query for feed source_id storage.
 *
 * @since 6.7.6
 *
 * @param mixed $raw Raw search query.
 * @return string Trimmed sanitized query (max 100 chars), or empty string.
 */
function esf_youtube_normalize_search_query(  $raw  ) {
    $value = sanitize_text_field( (string) $raw );
    $value = trim( $value );
    if ( '' === $value ) {
        return '';
    }
    if ( function_exists( 'mb_substr' ) ) {
        return mb_substr( $value, 0, 100 );
    }
    return substr( $value, 0, 100 );
}

/**
 * Whether a videos cache payload uses the versioned envelope.
 *
 * @since 6.7.6
 *
 * @param mixed $cached Cache payload.
 * @return bool
 */
function esf_youtube_is_videos_cache_envelope(  $cached  ) {
    return is_array( $cached ) && isset( $cached['v'], $cached['items'] ) && is_array( $cached['items'] );
}

/**
 * Wrap video items for cache storage (optional search total).
 *
 * @since 6.7.6
 *
 * @param array    $items         Normalized video items.
 * @param int|null $total_results Optional total matches (search).
 * @return array{v:int,items:array,total_results?:int}
 */
function esf_youtube_pack_videos_cache(  array $items, $total_results = null  ) {
    $payload = array(
        'v'     => 1,
        'items' => array_values( $items ),
    );
    if ( null !== $total_results ) {
        $payload['total_results'] = max( 0, (int) $total_results );
    }
    return $payload;
}

/**
 * Unpack a videos cache payload (envelope or legacy flat list).
 *
 * @since 6.7.6
 *
 * @param mixed $cached Cache payload.
 * @return array{items:array,total_results:int|null}
 */
function esf_youtube_unpack_videos_cache(  $cached  ) {
    if ( !is_array( $cached ) ) {
        return array(
            'items'         => array(),
            'total_results' => null,
        );
    }
    if ( esf_youtube_is_videos_cache_envelope( $cached ) ) {
        return array(
            'items'         => $cached['items'],
            'total_results' => ( isset( $cached['total_results'] ) ? max( 0, (int) $cached['total_results'] ) : null ),
        );
    }
    return array(
        'items'         => $cached,
        'total_results' => null,
    );
}

/**
 * Apply moderation filter to feed videos.
 *
 * Used by {@see ESF_YouTube_Renderer} (frontend + admin preview), layout
 * {@see ESF_YouTube_Layout_Base::get_filtered_videos()}, and Load More so
 * hide/show selections stay consistent. Relies on {@see ESF_Moderation}
 * (feed-scoped `settings.moderation`). Pro content_filter (Shorts / Exclude
 * Shorts) is applied earlier in {@see ESF_YouTube_Renderer::get_videos_for_feed()}.
 *
 * @since 6.7.5
 * @param array      $videos   Normalized video items.
 * @param int        $feed_id  Feed ID.
 * @param array|null $settings Optional feed settings (unused; kept for callers).
 * @return array Filtered videos (same structure).
 */
function esf_youtube_apply_moderation_filter(  $videos, $feed_id, $settings = null  ) {
    unset($settings);
    if ( !is_array( $videos ) ) {
        return array();
    }
    $feed_id = (int) $feed_id;
    $filtered = array_values( $videos );
    if ( $feed_id > 0 && class_exists( 'ESF_Moderation' ) ) {
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan() ) {
                $filtered = ESF_Moderation::filter_raw_nodes( $filtered, 'youtube', $feed_id );
            }
        }
    }
    return apply_filters( 'esf_youtube_feed_videos', $filtered, $feed_id );
}

if ( function_exists( 'add_filter' ) && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
    add_filter(
        'esf_moderation_get_feed_settings',
        'esf_youtube_moderation_get_feed_settings__premium_only',
        10,
        3
    );
    add_filter(
        'esf_moderation_save_feed_settings',
        'esf_youtube_moderation_save_feed_settings__premium_only',
        10,
        4
    );
    add_filter(
        'esf_moderation_feed_exists',
        'esf_youtube_moderation_feed_exists__premium_only',
        10,
        3
    );
}
/**
 * Flag that a YouTube feed has rendered on the page.
 *
 * Set when any feed shortcode renders so the frontend class can
 * conditionally enqueue CSS/JS in wp_footer.
 *
 * @since 6.9.0
 * @return void
 */
function esf_youtube_flag_feed_script() {
    global $esf_youtube_feed_script_needed;
    $esf_youtube_feed_script_needed = true;
}

/**
 * Flag that Load More script should be enqueued (used when a feed outputs the Load More button).
 *
 * @since 6.7.5
 * @return void
 */
function esf_youtube_flag_load_more_script() {
    global $esf_youtube_load_more_script_needed;
    $esf_youtube_load_more_script_needed = true;
}

/**
 * Flag that the YouTube lightbox script should be enqueued.
 *
 * Set when a feed renders video cards that use the FancyBox-based popup
 * so the frontend can conditionally enqueue assets in wp_footer.
 *
 * @since 6.7.5
 * @return void
 */
function esf_youtube_flag_lightbox_script() {
    global $esf_youtube_lightbox_script_needed;
    $esf_youtube_lightbox_script_needed = true;
}

/**
 * Flag that the shared showcase player script should be enqueued.
 *
 * @since 6.18.0
 * @return void
 */
function esf_youtube_flag_showcase_script() {
    global $esf_youtube_showcase_script_needed;
    $esf_youtube_showcase_script_needed = true;
}

/**
 * Whether a YouTube layout slug supports the Video Showcase playback mode.
 *
 * @since 6.18.0
 *
 * @param string $layout_slug Layout type (grid, row, …).
 * @return bool
 */
function esf_youtube_layout_supports_showcase(  $layout_slug  ) {
    $layout_slug = sanitize_key( (string) $layout_slug );
    if ( '' === $layout_slug ) {
        return false;
    }
    if ( class_exists( '\\EasySocialFeed\\Layouts\\LayoutRegistry' ) ) {
        $definition = \EasySocialFeed\Layouts\LayoutRegistry::for( 'youtube' )->get( $layout_slug );
        if ( $definition && method_exists( $definition, 'supports' ) ) {
            return (bool) $definition->supports( 'showcase' );
        }
    }
    // Fallback before layouts register (unit tests / early bootstrap).
    return in_array( $layout_slug, array(
        'grid',
        'row',
        'carousel',
        'masonry',
        'half_width',
        'full_width'
    ), true );
}

/**
 * Resolve the effective video playback mode for a feed.
 *
 * Modes:
 * - `new_tab`  — card links open youtube.com (default free behaviour)
 * - `showcase` — inline featured player (free + Pro; layout must support it)
 * - `popup`    — FancyBox lightbox (Pro)
 *
 * `playback.mode = auto` (default) derives from legacy `popup.enabled` + plan
 * so existing feeds keep their current behaviour.
 *
 * @since 6.18.0
 *
 * @param array  $settings    Feed settings.
 * @param string $layout_slug Layout type. Empty = read from settings.
 * @return string One of new_tab|showcase|popup.
 */
function esf_youtube_resolve_playback_mode(  $settings, $layout_slug = ''  ) {
    $settings = ( is_array( $settings ) ? $settings : array() );
    $layout = ( isset( $settings['layout'] ) && is_array( $settings['layout'] ) ? $settings['layout'] : array() );
    $popup = ( isset( $settings['popup'] ) && is_array( $settings['popup'] ) ? $settings['popup'] : array() );
    $playback = ( isset( $settings['playback'] ) && is_array( $settings['playback'] ) ? $settings['playback'] : array() );
    if ( '' === (string) $layout_slug ) {
        $layout_slug = ( isset( $layout['type'] ) ? sanitize_key( (string) $layout['type'] ) : 'grid' );
    } else {
        $layout_slug = sanitize_key( (string) $layout_slug );
    }
    if ( '' === $layout_slug ) {
        $layout_slug = 'grid';
    }
    $requested = ( isset( $playback['mode'] ) ? sanitize_key( (string) $playback['mode'] ) : 'auto' );
    if ( !in_array( $requested, array(
        'auto',
        'new_tab',
        'showcase',
        'popup'
    ), true ) ) {
        $requested = 'auto';
    }
    $has_plan = function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();
    $supports = esf_youtube_layout_supports_showcase( $layout_slug );
    if ( 'showcase' === $requested ) {
        return ( $supports ? 'showcase' : 'new_tab' );
    }
    if ( 'popup' === $requested ) {
        return ( $has_plan ? 'popup' : 'new_tab' );
    }
    if ( 'new_tab' === $requested ) {
        return 'new_tab';
    }
    // auto — preserve historical popup.enabled + plan behaviour.
    $popup_wants = !array_key_exists( 'enabled', $popup ) || !empty( $popup['enabled'] );
    if ( $has_plan && $popup_wants ) {
        return 'popup';
    }
    return 'new_tab';
}

/**
 * Hostnames the YouTube Video Showcase may embed.
 *
 * Passed to the shared `ShowcaseStage` primitive, which holds no module hosts.
 *
 * @since 6.18.0
 *
 * @return string[]
 */
function esf_youtube_showcase_embed_hosts() {
    return array(
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'www.youtube.com',
        'youtube.com'
    );
}

/**
 * Build a youtube-nocookie embed URL for the showcase player.
 *
 * @since 6.18.0
 *
 * @param string              $video_id YouTube video id.
 * @param array<string,mixed> $args     Optional: autoplay (bool), mute (bool).
 * @return string Empty when video id is blank.
 */
function esf_youtube_build_showcase_embed_url(  $video_id, $args = array()  ) {
    $video_id = trim( (string) $video_id );
    if ( '' === $video_id ) {
        return '';
    }
    $args = ( is_array( $args ) ? $args : array() );
    $autoplay = !array_key_exists( 'autoplay', $args ) || !empty( $args['autoplay'] );
    $mute = !empty( $args['mute'] );
    $query = array(
        'rel'            => '0',
        'playsinline'    => '1',
        'modestbranding' => '1',
        'autoplay'       => ( $autoplay ? '1' : '0' ),
    );
    if ( $mute ) {
        $query['mute'] = '1';
    }
    return 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $video_id ) . '?' . http_build_query(
        $query,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

/**
 * Normalised Video Showcase player settings (`playback.showcase`).
 *
 * - aspect:      auto|16:9|21:9|4:3|1:1|4:5|3:4|9:16. `auto` = 9:16 for Shorts-only
 *                feeds, otherwise 16:9. It is intentionally *not* decided per video
 *                (duration is not a reliable Shorts signal), so the stage never jumps.
 * - max_width:   player max width in px (0 = automatic, container width capped by height).
 * - align:       center|left.
 * - radius:      corner radius in px (-1 = default).
 * - now_playing: show the "Now playing" badge on the active card and above the title.
 *
 * @since 6.18.0
 *
 * @param array $settings Feed settings.
 * @return array{aspect:string,max_width:int,align:string,radius:int,now_playing:bool}
 */
function esf_youtube_get_showcase_settings(  $settings  ) {
    $settings = ( is_array( $settings ) ? $settings : array() );
    $playback = ( isset( $settings['playback'] ) && is_array( $settings['playback'] ) ? $settings['playback'] : array() );
    $raw = ( isset( $playback['showcase'] ) && is_array( $playback['showcase'] ) ? $playback['showcase'] : array() );
    $aspect = ( isset( $raw['aspect'] ) ? (string) $raw['aspect'] : 'auto' );
    if ( !in_array( $aspect, array(
        'auto',
        '16:9',
        '21:9',
        '4:3',
        '1:1',
        '4:5',
        '3:4',
        '9:16'
    ), true ) ) {
        $aspect = 'auto';
    }
    $max_width = ( isset( $raw['max_width'] ) ? (int) $raw['max_width'] : 0 );
    $max_width = max( 0, min( 1600, $max_width ) );
    $align = ( isset( $raw['align'] ) && 'left' === $raw['align'] ? 'left' : 'center' );
    $radius = ( isset( $raw['radius'] ) && '' !== $raw['radius'] ? (int) $raw['radius'] : -1 );
    $radius = ( $radius < 0 ? -1 : min( 48, $radius ) );
    return array(
        'aspect'      => $aspect,
        'max_width'   => $max_width,
        'align'       => $align,
        'radius'      => $radius,
        'now_playing' => !array_key_exists( 'now_playing', $raw ) || !empty( $raw['now_playing'] ),
    );
}

/**
 * Resolve the showcase stage aspect ratio for a feed.
 *
 * @since 6.18.0
 *
 * @param array $settings Feed settings.
 * @return string Aspect token, e.g. 16:9.
 */
function esf_youtube_resolve_showcase_aspect(  $settings  ) {
    $cfg = esf_youtube_get_showcase_settings( $settings );
    if ( 'auto' !== $cfg['aspect'] ) {
        return $cfg['aspect'];
    }
    return ( 'shorts' === esf_youtube_content_filter_from_settings( $settings ) ? '9:16' : '16:9' );
}

/**
 * Localize data for the YouTube FancyBox lightbox (including comments).
 *
 * @since 6.7.9
 * @return array<string,mixed>
 */
function esf_youtube_get_lightbox_localize_data() {
    $admin = ( class_exists( 'ESF_GDPR_Integrations' ) ? ESF_GDPR_Integrations::get_lightbox_admin_localize_data() : array(
        'adminMediaBlockedText' => '',
        'adminsOnlyText'        => __( 'Admins only', 'easy-facebook-likebox' ),
    ) );
    $subscribe = ( function_exists( 'esf_get_translated_string' ) ? esf_get_translated_string( 'yt_subscribe_button' ) : __( 'Subscribe', 'easy-facebook-likebox' ) );
    $watch_on_youtube = ( function_exists( 'esf_get_translated_string' ) ? esf_get_translated_string( 'yt_watch_on_youtube' ) : __( 'Watch on YouTube', 'easy-facebook-likebox' ) );
    return array_merge( array(
        'subscribe'                 => $subscribe,
        'watchOnYouTube'            => $watch_on_youtube,
        'restUrl'                   => rest_url( 'esf/v1' ),
        'nonce'                     => wp_create_nonce( 'wp_rest' ),
        'likesText'                 => __( 'Likes', 'easy-facebook-likebox' ),
        'commentsText'              => __( 'Comments', 'easy-facebook-likebox' ),
        'commentsShowingText'       => __( 'Showing %1$s of %2$s', 'easy-facebook-likebox' ),
        'loadMoreCommentsText'      => __( 'Load more comments', 'easy-facebook-likebox' ),
        'loadMoreRepliesText'       => __( 'Load more replies', 'easy-facebook-likebox' ),
        'loadingCommentsText'       => __( 'Loading comments…', 'easy-facebook-likebox' ),
        'noCommentsText'            => __( 'No comments yet.', 'easy-facebook-likebox' ),
        'commentsErrorText'         => __( 'Could not load comments.', 'easy-facebook-likebox' ),
        'viewRepliesText'           => __( 'View all %d replies', 'easy-facebook-likebox' ),
        'viewReplyText'             => __( 'View %d reply', 'easy-facebook-likebox' ),
        'hideRepliesText'           => __( 'Hide replies', 'easy-facebook-likebox' ),
        'writeCommentOnYouTubeText' => __( 'Add a comment on YouTube…', 'easy-facebook-likebox' ),
        'anonymousUserText'         => __( 'YouTube user', 'easy-facebook-likebox' ),
        'numberSuffixes'            => array(
            'k' => __( 'K', 'easy-facebook-likebox' ),
            'm' => __( 'M', 'easy-facebook-likebox' ),
            'b' => __( 'B', 'easy-facebook-likebox' ),
        ),
    ), $admin );
}

/**
 * Demo / marketing YouTube video IDs (optional allow-list helpers).
 *
 * Kept for demos/filters; author masking defaults off
 * ({@see esf_youtube_should_mask_comment_authors}).
 *
 * @since 6.7.9
 * @return array<int,string>
 */
function esf_youtube_demo_video_ids() {
    $ids = array(
        // Easy Social Feed — Custom Facebook Feed and Instagram Feed Demo.
        '9ZvHmlozcHA',
    );
    /**
     * Filter demo video IDs.
     *
     * @since 6.7.9
     *
     * @param array<int,string> $ids Video IDs.
     */
    $ids = apply_filters( 'esf_youtube_demo_video_ids', $ids );
    return array_values( array_filter( array_map( static function ( $id ) {
        return ( is_string( $id ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', $id ) : '' );
    }, (array) $ids ) ) );
}

/**
 * Whether to mask comment authors for a video (filter default: off).
 *
 * @since 6.7.9
 *
 * @param string $video_id YouTube video ID.
 * @return bool
 */
function esf_youtube_should_mask_comment_authors(  $video_id  ) {
    $video_id = ( is_string( $video_id ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', $video_id ) : '' );
    $mask = false;
    /**
     * Filter whether to mask comment authors for a video.
     *
     * @since 6.7.9
     *
     * @param bool   $mask     Whether to mask.
     * @param string $video_id Video ID.
     */
    return (bool) apply_filters( 'esf_youtube_mask_comment_authors', $mask, $video_id );
}

/**
 * Clear avatar/username on comments for demo display.
 *
 * @since 6.7.9
 *
 * @param array<int,array<string,mixed>> $comments Normalized comments or replies.
 * @return array<int,array<string,mixed>>
 */
function esf_youtube_mask_comment_author_fields(  array $comments  ) {
    $label = __( 'YouTube user', 'easy-facebook-likebox' );
    $out = array();
    foreach ( $comments as $comment ) {
        if ( !is_array( $comment ) ) {
            continue;
        }
        $comment['avatar_url'] = '';
        $comment['username'] = $label;
        if ( isset( $comment['replies'] ) && is_array( $comment['replies'] ) ) {
            $comment['replies'] = esf_youtube_mask_comment_author_fields( $comment['replies'] );
        }
        $out[] = $comment;
    }
    return $out;
}

/**
 * Format a DB datetime with WP date/time settings.
 *
 * @since 6.7.5
 * @param string $datetime Database datetime string.
 * @param string $format   Optional. Defaults to WP date + time formats.
 * @return string
 */
function esf_youtube_format_date(  $datetime, $format = ''  ) {
    if ( empty( $format ) ) {
        $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
    }
    return mysql2date( $format, $datetime );
}

/**
 * Whether a feed's customize preview is expected to be a cold (slow) first load.
 *
 * Cold when both the videos cache and local media files are missing.
 * Used to show the first-load loader notice only for that case.
 *
 * @since 6.9.6
 *
 * @param int    $account_id Account ID.
 * @param string $feed_type  channel|playlist|search.
 * @param string $source_id  Playlist ID or search query.
 * @param array  $settings   Feed settings (for Shorts content_filter resolve).
 * @return bool True when neither videos cache nor local media are present.
 */
function esf_youtube_feed_preview_is_cold(
    $account_id,
    $feed_type = 'channel',
    $source_id = '',
    $settings = array()
) {
    $account_id = max( 0, (int) $account_id );
    if ( $account_id <= 0 ) {
        return true;
    }
    $feed_type = sanitize_key( (string) $feed_type );
    $source_id = trim( (string) $source_id );
    $content_filter = 'all';
    if ( is_array( $settings ) && isset( $settings['feed']['content_filter'] ) ) {
        $content_filter = (string) $settings['feed']['content_filter'];
    }
    $channel_id = '';
    if ( class_exists( 'ESF_YouTube_Account_Repository' ) && method_exists( 'ESF_YouTube_Account_Repository', 'get_instance' ) ) {
        $row = ESF_YouTube_Account_Repository::get_instance()->get_by_id( $account_id );
        if ( is_object( $row ) && !empty( $row->channel_id ) ) {
            $channel_id = (string) $row->channel_id;
        }
    }
    if ( function_exists( 'esf_youtube_resolve_videos_source' ) ) {
        $resolved = esf_youtube_resolve_videos_source(
            $feed_type,
            $source_id,
            $content_filter,
            $channel_id
        );
        $feed_type = $resolved['feed_type'];
        $source_id = $resolved['source_id'];
    }
    $has_videos_cache = false;
    if ( class_exists( 'ESF_YouTube_Cache' ) && function_exists( 'esf_youtube_videos_cache_key' ) ) {
        $cache_key = esf_youtube_videos_cache_key( $account_id, $feed_type, $source_id );
        if ( '' !== $cache_key ) {
            $cached = ESF_YouTube_Cache::get( $cache_key );
            if ( !is_array( $cached ) && method_exists( 'ESF_YouTube_Cache', 'get_stale' ) ) {
                $cached = ESF_YouTube_Cache::get_stale( $cache_key );
            }
            if ( is_array( $cached ) && function_exists( 'esf_youtube_unpack_videos_cache' ) ) {
                $unpacked = esf_youtube_unpack_videos_cache( $cached );
                $has_videos_cache = !empty( $unpacked['items'] ) && is_array( $unpacked['items'] );
            } elseif ( is_array( $cached ) && !empty( $cached ) ) {
                $has_videos_cache = true;
            }
        }
    }
    $has_local_media = false;
    if ( function_exists( 'esf_get_uploads_directory' ) ) {
        $dir = esf_get_uploads_directory( 'youtube', $account_id );
        if ( is_string( $dir ) && '' !== $dir && is_dir( $dir ) ) {
            $entries = @scandir( $dir );
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort existence probe.
            if ( is_array( $entries ) ) {
                foreach ( $entries as $entry ) {
                    if ( '.' === $entry || '..' === $entry ) {
                        continue;
                    }
                    $path = $dir . '/' . $entry;
                    if ( is_file( $path ) || is_dir( $path ) ) {
                        $has_local_media = true;
                        break;
                    }
                }
            }
        }
    }
    return !$has_videos_cache && !$has_local_media;
}

/**
 * Error hook placeholder — no-op so tokens never hit debug.log.
 *
 * @since 6.7.5
 * @param string $message Error message.
 * @param array  $context Optional context.
 * @return void
 */
function esf_youtube_log_error(  $message, $context = array()  ) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future logging implementation.
}

/**
 * YouTube admin screen URL.
 *
 * @since 6.7.5
 * @param array $args Optional query args.
 * @return string
 */
function esf_get_youtube_admin_url(  $args = array()  ) {
    $default_args = array(
        'page' => 'esf-youtube',
    );
    $args = wp_parse_args( $args, $default_args );
    return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Wrapper data attributes + CSS vars for global hover overlay / play icon.
 *
 * Applied to `.esf-yt-feed` so Grid, Carousel, Half Width, Full Width, and
 * Masonry share one hover-play control. Preview JS updates the same
 * attributes without refetch.
 *
 * @since 6.16.0
 *
 * @param array  $attrs Existing wrapper attributes.
 * @param object $feed  Feed record.
 * @return array<string,string>
 */
function esf_youtube_hover_overlay_wrapper_attributes(  $attrs, $feed  ) {
    $attrs = ( is_array( $attrs ) ? $attrs : array() );
    $settings = ( is_object( $feed ) && isset( $feed->settings ) && is_array( $feed->settings ) ? $feed->settings : array() );
    $feed_cfg = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
    $layout_type = ( isset( $settings['layout']['type'] ) ? (string) $settings['layout']['type'] : '' );
    $layout_key = ( in_array( $layout_type, array('full_width', 'masonry'), true ) ? $layout_type : 'half_width' );
    $layout_cfg = ( isset( $settings['layout'][$layout_key] ) && is_array( $settings['layout'][$layout_key] ) ? $settings['layout'][$layout_key] : array() );
    $overlay = !array_key_exists( 'hover_overlay', $feed_cfg ) || !empty( $feed_cfg['hover_overlay'] );
    $play = !array_key_exists( 'show_hover_play', $feed_cfg ) || !empty( $feed_cfg['show_hover_play'] );
    $attrs['data-hover-overlay'] = ( $overlay ? 'true' : 'false' );
    $attrs['data-hover-play'] = ( $overlay && $play ? 'true' : 'false' );
    $attrs['data-hover-show-likes'] = ( $overlay && !empty( $layout_cfg['hover_show_likes'] ) ? 'true' : 'false' );
    $attrs['data-hover-show-comments'] = ( $overlay && !empty( $layout_cfg['hover_show_comments'] ) ? 'true' : 'false' );
    $style_parts = array();
    $existing = ( isset( $attrs['style'] ) ? trim( (string) $attrs['style'] ) : '' );
    if ( '' !== $existing ) {
        $style_parts[] = rtrim( $existing, ';' );
    }
    $color = ( isset( $feed_cfg['hover_play_color'] ) ? trim( (string) $feed_cfg['hover_play_color'] ) : '' );
    $color = esf_youtube_sanitize_css_hex_color( $color );
    if ( '' !== $color ) {
        $style_parts[] = '--esf-yt-hover-play-color:' . $color;
    }
    $icon_color = ( isset( $feed_cfg['hover_play_icon_color'] ) ? trim( (string) $feed_cfg['hover_play_icon_color'] ) : '' );
    $icon_color = esf_youtube_sanitize_css_hex_color( $icon_color );
    if ( '' !== $icon_color ) {
        $style_parts[] = '--esf-yt-hover-play-icon-color:' . $icon_color;
    }
    if ( isset( $feed_cfg['hover_play_size'] ) && '' !== $feed_cfg['hover_play_size'] && null !== $feed_cfg['hover_play_size'] ) {
        $size = max( 20, min( 72, (int) $feed_cfg['hover_play_size'] ) );
        $style_parts[] = '--esf-yt-hover-play-size:' . $size . 'px';
    }
    if ( !empty( $style_parts ) ) {
        $attrs['style'] = implode( ';', $style_parts );
    }
    return $attrs;
}

/**
 * Sanitize a CSS hex color for hover-play CSS variables.
 *
 * @since 6.16.0
 *
 * @param string $color Raw color string.
 * @return string Safe `#rgb` / `#rrggbb` or empty.
 */
function esf_youtube_sanitize_css_hex_color(  $color  ) {
    $color = trim( (string) $color );
    if ( '' === $color ) {
        return '';
    }
    if ( function_exists( 'sanitize_hex_color' ) ) {
        $safe = sanitize_hex_color( $color );
        return ( is_string( $safe ) ? $safe : '' );
    }
    return ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ? $color : '' );
}

/**
 * Format a YouTube video description into safe HTML.
 *
 * URLs, hashtags (YouTube search), and @handles (youtube.com/@handle) become links.
 *
 * @since 6.16.0
 *
 * @param string $description Video description.
 * @return string Sanitized HTML.
 */
function esf_youtube_format_description_html(  $description  ) {
    $description = ( is_string( $description ) ? $description : '' );
    if ( '' === $description ) {
        return '';
    }
    $caption_args = array(
        'hashtag_args' => array(
            'class' => 'esf-yt-hash',
        ),
        'mention_args' => array(
            'class' => 'esf-yt-mention',
        ),
    );
    if ( function_exists( 'esf_format_caption_html' ) ) {
        return esf_format_caption_html( $description, 'youtube', $caption_args );
    }
    $safe = esc_html( $description );
    if ( function_exists( 'esf_convert_to_hyperlinks' ) ) {
        $safe = esf_convert_to_hyperlinks( $safe, array('http', 'https', 'mail'), array(
            'target' => '_blank',
            'rel'    => 'noopener noreferrer',
        ) );
    } elseif ( function_exists( 'make_clickable' ) ) {
        $safe = make_clickable( $safe );
    }
    if ( function_exists( 'esf_hashtags_to_links' ) ) {
        $safe = esf_hashtags_to_links( $safe, 'youtube', $caption_args['hashtag_args'] );
    }
    if ( function_exists( 'esf_mentions_to_links' ) ) {
        $safe = esf_mentions_to_links( $safe, 'youtube', $caption_args['mention_args'] );
    }
    if ( function_exists( 'wpautop' ) ) {
        $safe = wpautop( $safe );
    }
    return $safe;
}

/**
 * Format a YouTube video title and description into safe HTML.
 *
 * Title stays escaped text. Description gets clickable URLs, hashtags, and @handles
 * via {@see esf_youtube_format_description_html()}.
 *
 * @since 6.16.0
 *
 * @param string $title       Video title.
 * @param string $description Video description.
 * @return string Sanitized HTML.
 */
function esf_youtube_format_video_html(  $title, $description  ) {
    $title = ( is_string( $title ) ? $title : '' );
    $description = ( is_string( $description ) ? $description : '' );
    $html = '';
    if ( '' !== $title ) {
        $html .= '<strong>' . esc_html( $title ) . '</strong>';
    }
    if ( '' === $description ) {
        return $html;
    }
    if ( '' !== $html ) {
        $html .= '<br><br>';
    }
    $html .= esf_youtube_format_description_html( $description );
    return $html;
}
