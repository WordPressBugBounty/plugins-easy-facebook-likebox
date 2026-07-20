<?php

/**
 * Instagram Module Helper Functions
 *
 * Shared helpers for the modern Instagram module stack.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/Helpers
 * @since 6.8.0
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Check if Instagram module is active in plugin settings.
 *
 * @since 6.8.0
 * @return bool
 */
function esf_is_instagram_active() {
    $fta_settings = get_option( 'fta_settings', array() );
    $status = ( isset( $fta_settings['plugins']['instagram']['status'] ) ? $fta_settings['plugins']['instagram']['status'] : 'deactivated' );
    return 'activated' === $status;
}

/**
 * Determine if current site should use modern Instagram system.
 *
 * Existing sites with legacy Instagram connections remain on legacy mode
 * until explicitly migrated. Fresh installs default to the modern system.
 *
 * @since 6.8.0
 * @return bool
 */
function esf_instagram_use_new_system() {
    if ( !class_exists( 'ESF_Module_System' ) ) {
        $mode = get_option( 'esf_instagram_use_new_system', null );
        if ( 'new' === $mode ) {
            return true;
        }
        if ( defined( 'ESF_INSTAGRAM_USE_LEGACY' ) && ESF_INSTAGRAM_USE_LEGACY ) {
            return false;
        }
        if ( 'legacy' === $mode ) {
            return false;
        }
        if ( esf_instagram_has_legacy_data() ) {
            return false;
        }
        update_option( 'esf_instagram_use_new_system', 'new' );
        return true;
    }
    return ESF_Module_System::resolve_use_new_system( 'instagram', 'esf_instagram_has_legacy_data', array(
        'force_legacy_constant' => 'ESF_INSTAGRAM_USE_LEGACY',
    ) );
}

/**
 * Return Instagram legacy/modern module-system status for REST/admin UI.
 *
 * @since 6.9.0
 * @return array<string,mixed>
 */
function esf_instagram_get_module_system_status() {
    if ( !class_exists( 'ESF_Module_System' ) ) {
        return array(
            'mode'                 => ( esf_instagram_has_legacy_data() ? 'legacy' : 'new' ),
            'legacy_present'       => esf_instagram_has_legacy_data(),
            'needs_migration'      => esf_instagram_has_legacy_data() && !esf_instagram_use_new_system(),
            'can_switch_to_legacy' => esf_instagram_has_legacy_data() && 'new' === get_option( 'esf_instagram_use_new_system', null ),
            'using_modern'         => esf_instagram_use_new_system(),
            'legacy_admin_url'     => admin_url( 'admin.php?page=mif' ),
            'modern_admin_url'     => admin_url( 'admin.php?page=esf-instagram' ),
        );
    }
    return ESF_Module_System::get_status( 'instagram', 'esf_instagram_has_legacy_data' );
}

/**
 * Resolve the default feed created during legacy → modern Instagram migration.
 *
 * @since 6.9.0
 * @return int Feed id or 0 when unavailable.
 */
function esf_instagram_get_migration_default_feed_id() {
    $stored_id = (int) get_option( 'esf_instagram_migration_default_feed_id', 0 );
    if ( $stored_id > 0 && class_exists( 'ESF_Instagram_Feed_Repository' ) ) {
        $row = ESF_Instagram_Feed_Repository::get_instance()->get_by_id( $stored_id );
        if ( $row ) {
            return $stored_id;
        }
    }
    if ( !class_exists( 'ESF_Instagram_Feed_Repository' ) ) {
        return 0;
    }
    $feeds = ESF_Instagram_Feed_Repository::get_instance()->get_all();
    if ( !empty( $feeds ) && is_object( $feeds[0] ) && !empty( $feeds[0]->id ) ) {
        return (int) $feeds[0]->id;
    }
    return 0;
}

/**
 * Check whether legacy Instagram data exists in fta_settings.
 *
 * @since 6.8.0
 * @return bool
 */
function esf_instagram_has_legacy_data() {
    $fta_settings = get_option( 'fta_settings', array() );
    $personal = ( isset( $fta_settings['plugins']['instagram']['instagram_connected_account'] ) ? $fta_settings['plugins']['instagram']['instagram_connected_account'] : array() );
    if ( is_array( $personal ) && !empty( $personal ) ) {
        return true;
    }
    $pages = ( isset( $fta_settings['plugins']['facebook']['approved_pages'] ) ? $fta_settings['plugins']['facebook']['approved_pages'] : array() );
    if ( is_array( $pages ) ) {
        foreach ( $pages as $page ) {
            if ( is_array( $page ) && !empty( $page['instagram_connected_account'] ) ) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Cache key for account-scoped Instagram Stories API data.
 *
 * @since 6.9.0
 *
 * @param int $account_id Account ID.
 * @return string
 */
function esf_instagram_stories_cache_key(  $account_id  ) {
    return sprintf( 'esf_ig_stories_%d', max( 0, (int) $account_id ) );
}

/**
 * Whether a feed's customize preview is expected to be a cold (slow) first load.
 *
 * Cold when both the Graph post cache and local media files are missing.
 * Used to show the first-load loader notice only for that case.
 *
 * @since 6.9.1
 *
 * @param int    $account_id Account ID.
 * @param string $feed_type  Feed type (`user_timeline`|`hashtag`).
 * @param string $source_id  Hashtag source (when applicable).
 * @param array  $settings   Feed settings (for hashtag media type).
 * @return bool True when neither posts cache nor local media are present.
 */
function esf_instagram_feed_preview_is_cold(
    $account_id,
    $feed_type = 'user_timeline',
    $source_id = '',
    $settings = array()
) {
    $account_id = max( 0, (int) $account_id );
    if ( $account_id <= 0 ) {
        return true;
    }
    $feed_type = sanitize_key( (string) $feed_type );
    $has_posts_cache = false;
    if ( class_exists( 'ESF_Instagram_Cache' ) ) {
        if ( 'hashtag' === $feed_type ) {
            $media_type = 'top_media';
            if ( is_array( $settings ) && function_exists( 'esf_instagram_normalize_hashtag_media_type' ) ) {
                $feed_cfg = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
                $media_type = esf_instagram_normalize_hashtag_media_type( ( isset( $feed_cfg['hashtag_media_type'] ) ? (string) $feed_cfg['hashtag_media_type'] : 'top_media' ) );
            }
            $cache_key = ( function_exists( 'esf_instagram_hashtag_cache_key' ) ? esf_instagram_hashtag_cache_key( $source_id, $media_type ) : '' );
        } else {
            $cache_key = ( class_exists( 'ESF_Instagram_Renderer' ) ? ESF_Instagram_Renderer::media_cache_key( $account_id ) : sprintf( 'esf_ig_media_%d', $account_id ) );
        }
        if ( is_string( $cache_key ) && '' !== $cache_key ) {
            $cached = ESF_Instagram_Cache::get( $cache_key );
            $has_posts_cache = is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) && !empty( $cached['data'] );
        }
    }
    $has_local_media = false;
    if ( function_exists( 'esf_get_uploads_directory' ) ) {
        $dir = '';
        if ( 'hashtag' === $feed_type && function_exists( 'esf_instagram_normalize_hashtag' ) ) {
            $tag = esf_instagram_normalize_hashtag( $source_id );
            if ( '' !== $tag ) {
                $dir = esf_get_uploads_directory( 'instagram', 0, $tag );
            }
        } else {
            $dir = esf_get_uploads_directory( 'instagram', $account_id );
        }
        if ( is_string( $dir ) && '' !== $dir && is_dir( $dir ) ) {
            $entries = @scandir( $dir );
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort existence probe.
            if ( is_array( $entries ) ) {
                foreach ( $entries as $entry ) {
                    if ( '.' === $entry || '..' === $entry ) {
                        continue;
                    }
                    $path = $dir . '/' . $entry;
                    if ( is_file( $path ) || is_dir( $path ) && !in_array( $entry, array('.', '..'), true ) ) {
                        $has_local_media = true;
                        break;
                    }
                }
            }
        }
    }
    return !$has_posts_cache && !$has_local_media;
}

/**
 * Delete cached stories for an account.
 *
 * @since 6.9.0
 *
 * @param int $account_id Account ID.
 * @return int|false Rows deleted, or false on failure.
 */
function esf_instagram_flush_stories_cache_for_account(  $account_id  ) {
    if ( !class_exists( 'ESF_Instagram_Cache' ) ) {
        return false;
    }
    return ESF_Instagram_Cache::delete_by_key( esf_instagram_stories_cache_key( $account_id ) );
}

/**
 * Normalize a hashtag string for storage and cache keys.
 *
 * Strips `#`, lowercases, and keeps only alphanumeric/underscore characters.
 *
 * @since 6.9.0
 *
 * @param string $tag Raw hashtag input.
 * @return string Normalized tag without `#`, or empty when invalid.
 */
function esf_instagram_normalize_hashtag(  $tag  ) {
    $tag = strtolower( trim( (string) $tag ) );
    $tag = ltrim( $tag, '#' );
    $tag = preg_replace( '/[^a-z0-9_]/', '', $tag );
    return substr( (string) $tag, 0, 100 );
}

/**
 * Normalize a hashtag media-type (edge) to a Graph-supported value.
 *
 * Hashtag feeds can read either the ranked `top_media` edge or the
 * chronological `recent_media` edge. Anything unrecognized falls back to
 * `top_media` so legacy feeds keep their existing behavior.
 *
 * @since 6.9.0
 *
 * @param mixed $media_type Raw media-type value.
 * @return string Either 'top_media' or 'recent_media'.
 */
function esf_instagram_normalize_hashtag_media_type(  $media_type  ) {
    $media_type = ( is_string( $media_type ) ? strtolower( trim( $media_type ) ) : '' );
    return ( 'recent_media' === $media_type ? 'recent_media' : 'top_media' );
}

/**
 * Read the normalized hashtag media-type from a feed object's settings.
 *
 * @since 6.9.0
 *
 * @param object|null $feed_obj Feed object with a `settings` array.
 * @return string Either 'top_media' or 'recent_media'.
 */
function esf_instagram_feed_hashtag_media_type(  $feed_obj  ) {
    $settings = ( is_object( $feed_obj ) && isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array() );
    $feed_cfg = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
    return esf_instagram_normalize_hashtag_media_type( ( isset( $feed_cfg['hashtag_media_type'] ) ? $feed_cfg['hashtag_media_type'] : 'top_media' ) );
}

/**
 * Cache key for tag-scoped hashtag media (shared across feeds/skins).
 *
 * The `recent_media` edge is cached separately from `top_media` so the two
 * modes never overwrite each other for the same tag.
 *
 * @since 6.9.0
 *
 * @param string $tag        Raw or normalized hashtag.
 * @param string $media_type Hashtag edge ('top_media'|'recent_media').
 * @return string Cache key, or empty when the tag is invalid.
 */
function esf_instagram_hashtag_cache_key(  $tag, $media_type = 'top_media'  ) {
    $normalized = esf_instagram_normalize_hashtag( $tag );
    if ( '' === $normalized ) {
        return '';
    }
    $key = 'esf_ig_hashtag_' . $normalized;
    if ( 'recent_media' === esf_instagram_normalize_hashtag_media_type( $media_type ) ) {
        $key .= '__recent';
    }
    return $key;
}

/**
 * Delete cached hashtag media for a tag (both Top and Recent edges).
 *
 * @since 6.9.0
 *
 * @param string $tag Raw or normalized hashtag.
 * @return int|false Rows deleted for the Top edge, or false on failure.
 */
function esf_instagram_flush_hashtag_cache(  $tag  ) {
    if ( !class_exists( 'ESF_Instagram_Cache' ) ) {
        return false;
    }
    $deleted = 0;
    foreach ( array('top_media', 'recent_media') as $media_type ) {
        $removed = esf_instagram_flush_hashtag_cache_variant( $tag, $media_type );
        if ( 'top_media' === $media_type ) {
            $deleted = $removed;
        }
    }
    return $deleted;
}

/**
 * Build a cache key for hashtag media files (local storage).
 *
 * Since hashtag media is stored in tag+type-specific folders, we use
 * a simpler key format: ig_hashtag_{tag}_{type}@{media_id}
 *
 * @since 6.9.0
 *
 * @param string $tag        Raw or normalized hashtag.
 * @param string $media_id   Instagram media ID.
 * @param string $media_type Hashtag edge ('top_media'|'recent_media').
 * @param string $prefix     Optional prefix ('media' or 'thumb'). Default 'media'.
 * @return string Cache key for local media storage, or empty if invalid.
 */
function esf_instagram_build_hashtag_media_key(
    $tag,
    $media_id,
    $media_type = 'top_media',
    $prefix = 'media'
) {
    $normalized = esf_instagram_normalize_hashtag( $tag );
    if ( '' === $normalized ) {
        return '';
    }
    $media_id = sanitize_key( (string) $media_id );
    if ( '' === $media_id ) {
        return '';
    }
    $media_type_suffix = ( function_exists( 'esf_instagram_normalize_hashtag_media_type' ) && 'recent_media' === esf_instagram_normalize_hashtag_media_type( $media_type ) ? 'recent' : 'top' );
    $prefix = sanitize_key( (string) $prefix );
    if ( '' === $prefix ) {
        $prefix = 'media';
    }
    return sprintf(
        'ig_hashtag_%s_%s@%s_%s',
        $normalized,
        $media_type_suffix,
        $prefix,
        $media_id
    );
}

/**
 * Delete cached hashtag media for one tag and media-type edge.
 *
 * @since 6.9.0
 *
 * @param string $tag        Raw or normalized hashtag.
 * @param string $media_type Hashtag edge ('top_media'|'recent_media').
 * @return int|false Rows deleted for the media cache row, or false on failure.
 */
function esf_instagram_flush_hashtag_cache_variant(  $tag, $media_type = 'top_media'  ) {
    if ( !class_exists( 'ESF_Instagram_Cache' ) || !function_exists( 'esf_instagram_hashtag_cache_key' ) ) {
        return false;
    }
    $key = esf_instagram_hashtag_cache_key( $tag, $media_type );
    if ( '' === $key ) {
        return false;
    }
    $deleted = ESF_Instagram_Cache::delete_by_key( $key );
    ESF_Instagram_Cache::delete_by_key( $key . '_meta' );
    return $deleted;
}

/**
 * Whether a feed object represents a hashtag feed.
 *
 * @since 6.9.0
 *
 * @param object|null $feed_obj Feed object.
 * @return bool
 */
function esf_instagram_feed_is_hashtag(  $feed_obj  ) {
    return is_object( $feed_obj ) && isset( $feed_obj->feed_type ) && 'hashtag' === sanitize_key( (string) $feed_obj->feed_type ) && function_exists( 'esf_instagram_normalize_hashtag' ) && '' !== esf_instagram_normalize_hashtag( ( isset( $feed_obj->source_id ) ? (string) $feed_obj->source_id : '' ) );
}

/**
 * Get all feed IDs using a specific hashtag and media type combination.
 *
 * Used for reference tracking when deleting hashtag media folders.
 *
 * @since 6.9.0
 *
 * @param string $tag        Raw or normalized hashtag.
 * @param string $media_type Hashtag edge ('top_media'|'recent_media').
 * @return int[] Array of feed IDs using this hashtag+media_type.
 */
function esf_instagram_get_feeds_using_hashtag(  $tag, $media_type = 'top_media'  ) {
    $normalized = esf_instagram_normalize_hashtag( $tag );
    if ( '' === $normalized ) {
        return array();
    }
    if ( !class_exists( 'ESF_Instagram_Feed_Repository' ) ) {
        return array();
    }
    $media_type = ( function_exists( 'esf_instagram_normalize_hashtag_media_type' ) ? esf_instagram_normalize_hashtag_media_type( $media_type ) : 'top_media' );
    $repo = ESF_Instagram_Feed_Repository::get_instance();
    $feeds = $repo->get_all();
    if ( !is_array( $feeds ) || empty( $feeds ) ) {
        return array();
    }
    $feed_ids = array();
    foreach ( $feeds as $feed ) {
        if ( !esf_instagram_feed_is_hashtag( $feed ) ) {
            continue;
        }
        $feed_tag = ( isset( $feed->source_id ) ? esf_instagram_normalize_hashtag( (string) $feed->source_id ) : '' );
        if ( $feed_tag !== $normalized ) {
            continue;
        }
        $feed_media_type = ( function_exists( 'esf_instagram_feed_hashtag_media_type' ) ? esf_instagram_feed_hashtag_media_type( $feed ) : 'top_media' );
        if ( $feed_media_type === $media_type ) {
            $feed_ids[] = (int) $feed->id;
        }
    }
    return $feed_ids;
}

/**
 * Whether debug logging is enabled for Instagram hashtag/media diagnostics.
 *
 * Opt-in only via `ESF_INSTAGRAM_DEBUG` — do not tie to `WP_DEBUG` alone or
 * Local sites with debug logging fill `debug.log` on every preview/render.
 *
 * @since 6.9.0
 * @return bool
 */
function esf_instagram_debug_enabled() {
    return defined( 'ESF_INSTAGRAM_DEBUG' ) && ESF_INSTAGRAM_DEBUG;
}

/**
 * Write a diagnostic line to the PHP error log when debug logging is enabled.
 *
 * @since 6.9.0
 *
 * @param string               $channel Short context label.
 * @param string               $message Human-readable message.
 * @param array<string, mixed> $context Optional structured context.
 * @return void
 */
function esf_instagram_debug_log(  $channel, $message, $context = array()  ) {
    if ( !function_exists( 'esf_instagram_debug_enabled' ) || !esf_instagram_debug_enabled() ) {
        return;
    }
    $line = sprintf( '[ESF Instagram:%s] %s', sanitize_key( (string) $channel ), (string) $message );
    if ( !empty( $context ) ) {
        $encoded = wp_json_encode( $context );
        if ( is_string( $encoded ) ) {
            $line .= ' ' . $encoded;
        }
    }
    error_log( $line );
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Resolve the Facebook user token required for hashtag Graph API calls.
 *
 * Falls back to the legacy global Facebook user token stored in `fta_settings`
 * when the account row does not have `facebook_user_token` populated yet.
 *
 * @since 6.9.0
 *
 * @param object|null $row Account row.
 * @return string
 */
function esf_instagram_resolve_facebook_user_token(  $row  ) {
    if ( is_object( $row ) ) {
        $token = ( isset( $row->facebook_user_token ) ? trim( (string) $row->facebook_user_token ) : '' );
        if ( '' !== $token ) {
            return $token;
        }
    }
    $settings = get_option( 'fta_settings', array() );
    if ( is_array( $settings ) && isset( $settings['plugins']['facebook']['access_token'] ) ) {
        return trim( (string) $settings['plugins']['facebook']['access_token'] );
    }
    return '';
}

/**
 * Whether an account row can power hashtag Graph API calls.
 *
 * @since 6.9.0
 *
 * @param object|null $row Account row.
 * @return bool
 */
function esf_instagram_account_supports_hashtag(  $row  ) {
    if ( !is_object( $row ) ) {
        return false;
    }
    if ( function_exists( 'esf_instagram_auth_source_for_account_row' ) && 'facebook_page' !== esf_instagram_auth_source_for_account_row( $row ) ) {
        return false;
    }
    $ig_id = ( isset( $row->instagram_user_id ) ? trim( (string) $row->instagram_user_id ) : '' );
    $token = ( function_exists( 'esf_instagram_resolve_facebook_user_token' ) ? esf_instagram_resolve_facebook_user_token( $row ) : '' );
    return '' !== $ig_id && '' !== $token;
}

/**
 * Check if the current user can manage modern Instagram module.
 *
 * @since 6.8.0
 * @return bool
 */
function esf_instagram_user_can_manage() {
    $capability = apply_filters( 'esf_instagram_manage_capability', 'manage_options' );
    return current_user_can( $capability );
}

/**
 * Check if the current plan includes Instagram Pro features.
 *
 * Returns true when premium code is available and the user has
 * instagram_premium or combo_premium. Use this for load more, popup,
 * multi-account limits, and any future Pro features so the condition
 * lives in one place (mirrors {@see esf_twitter_has_twitter_plan()}).
 *
 * @since 6.9.0
 * @return bool True if Instagram Pro plan is active, false otherwise.
 */
function esf_instagram_has_instagram_plan() {
    if ( !function_exists( 'efl_fs' ) ) {
        return false;
    }
    $fs = efl_fs();
    return false;
    return $fs->is_plan( 'instagram_premium', true ) || $fs->is_plan( 'combo_premium', true );
}

/**
 * Whether Instagram Multifeed support is loaded.
 *
 * Modern Instagram: only the Multifeed addon (`Esf_Multifeed_Instagram_Modern`).
 * Legacy Instagram: Multifeed addon or parent Pro bundled fallback
 * (`Esf_Multifeed_Instagram_Frontend`).
 *
 * @since 6.9.2
 * @return bool
 */
function esf_instagram_multifeed_active() {
    if ( function_exists( 'esf_instagram_use_new_system' ) && esf_instagram_use_new_system() ) {
        return class_exists( 'Esf_Multifeed_Instagram_Modern', false );
    }
    return class_exists( 'Esf_Multifeed_Instagram_Frontend', false );
}

/**
 * Sanitize a list of Instagram account IDs for Multifeed settings.
 *
 * @since 6.9.2
 *
 * @param mixed $ids Raw account ID list.
 * @return int[] Unique positive IDs in the order provided.
 */
function esf_instagram_sanitize_account_ids(  $ids  ) {
    if ( !is_array( $ids ) ) {
        return array();
    }
    $out = array();
    foreach ( $ids as $id ) {
        $id = (int) $id;
        if ( $id > 0 && !in_array( $id, $out, true ) ) {
            $out[] = $id;
        }
    }
    return $out;
}

/**
 * Resolve account IDs for a modern Instagram feed (Multifeed-aware).
 *
 * Returns the primary `account_id` plus any Multifeed `settings.feed.account_ids`
 * when Multifeed is active. Hashtag feeds stay single-account (legacy parity).
 *
 * @since 6.9.2
 *
 * @param object|null $feed_obj Feed object with account_id / settings / feed_type.
 * @return int[] Positive unique account IDs (primary first when present).
 */
function esf_instagram_resolve_feed_account_ids(  $feed_obj  ) {
    $primary = ( is_object( $feed_obj ) && isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0 );
    $ids = array();
    if ( $primary > 0 ) {
        $ids[] = $primary;
    }
    if ( !esf_instagram_multifeed_active() ) {
        return $ids;
    }
    if ( function_exists( 'esf_instagram_feed_is_hashtag' ) && esf_instagram_feed_is_hashtag( $feed_obj ) ) {
        return $ids;
    }
    $settings = ( is_object( $feed_obj ) && isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array() );
    $feed_cfg = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
    $extra = esf_instagram_sanitize_account_ids( ( isset( $feed_cfg['account_ids'] ) ? $feed_cfg['account_ids'] : array() ) );
    foreach ( $extra as $id ) {
        if ( !in_array( $id, $ids, true ) ) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Whether a modern feed is configured as Multifeed (2+ source accounts).
 *
 * @since 6.9.2
 *
 * @param object|null $feed_obj Feed object.
 * @return bool
 */
function esf_instagram_feed_is_multifeed(  $feed_obj  ) {
    return count( esf_instagram_resolve_feed_account_ids( $feed_obj ) ) > 1;
}

/**
 * Format an Instagram caption into safe HTML with clickable URLs and hashtags.
 *
 * Thin wrapper around shared {@see esf_format_caption_html()}.
 *
 * @since 6.9.0
 * @param string $caption Raw caption text.
 * @return string Sanitized HTML.
 */
function esf_instagram_format_caption_html(  $caption  ) {
    if ( !function_exists( 'esf_format_caption_html' ) ) {
        return '';
    }
    return esf_format_caption_html( $caption, 'instagram' );
}

/**
 * Clamp optional layout feed/media dimension settings.
 *
 * @since 6.9.0
 *
 * @param array $scope Layout settings scope.
 * @return array
 */
function esf_instagram_normalize_layout_dimension_scope(  array $scope  ) {
    $keys = array(
        'feed_width',
        'feed_width_tablet',
        'feed_width_mobile',
        'feed_height',
        'feed_height_tablet',
        'feed_height_mobile',
        'media_max_height',
        'media_max_height_tablet',
        'media_max_height_mobile'
    );
    foreach ( $keys as $key ) {
        if ( array_key_exists( $key, $scope ) ) {
            $scope[$key] = max( 0, min( 2000, (int) $scope[$key] ) );
        }
    }
    return $scope;
}

/**
 * Strip Pro-only grid layout settings when the site has no Instagram plan.
 *
 * @since 6.9.0
 * @param array $settings Feed settings array.
 * @return array
 */
function esf_instagram_normalize_feed_settings(  array $settings  ) {
    $defaults = ( class_exists( 'ESF_Instagram_Feed_Repository' ) ? ESF_Instagram_Feed_Repository::get_default_settings() : array(
        'layout' => array(
            'grid'     => array(
                'gap'                => 8,
                'tile_border_radius' => 8,
                'tile_shadow'        => false,
                'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
            ),
            'row'      => array(
                'gap'                => 0,
                'row_style'          => 'standard',
                'wave_offset'        => 16,
                'tile_border_radius' => 0,
                'tile_shadow'        => false,
                'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
            ),
            'masonry'  => array(
                'columns'              => 4,
                'columns_tablet'       => 3,
                'columns_mobile'       => 2,
                'gap'                  => 8,
                'tile_border_radius'   => 8,
                'tile_shadow'          => true,
                'tile_shadow_color'    => 'rgba(0,0,0,0.12)',
                'display_mode'         => 'media_only',
                'show_caption'         => true,
                'show_meta'            => true,
                'show_time'            => true,
                'caption_words'        => 18,
                'details_bg_color'     => '#ffffff',
                'details_text_color'   => '#262626',
                'details_accent_color' => '#e1306c',
                'details_meta_color'   => '#8e8e8e',
            ),
            'carousel' => array(
                'columns'              => 4,
                'columns_tablet'       => 3,
                'columns_mobile'       => 1,
                'gap'                  => 8,
                'tile_border_radius'   => 8,
                'display_mode'         => 'media_only',
                'media_aspect_ratio'   => '1:1',
                'show_caption'         => true,
                'show_meta'            => true,
                'show_time'            => true,
                'caption_words'        => 18,
                'details_bg_color'     => '#ffffff',
                'details_text_color'   => '#262626',
                'details_accent_color' => '#e1306c',
                'details_meta_color'   => '#8e8e8e',
                'autoplay'             => true,
                'loop'                 => true,
                'autoplay_speed'       => 3000,
                'show_arrows'          => true,
                'show_dots'            => true,
                'nav_color'            => '#d6d6d6',
                'nav_active_color'     => '#869791',
            ),
        ),
    ) );
    $migrated_from_post = array('show_media_type_icon', 'hover_overlay', 'show_hover_plus');
    $feed_incoming = ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array() );
    $legacy_post_tile = array();
    if ( isset( $settings['post'] ) && is_array( $settings['post'] ) ) {
        foreach ( $migrated_from_post as $key ) {
            if ( array_key_exists( $key, $settings['post'] ) && !array_key_exists( $key, $feed_incoming ) ) {
                $legacy_post_tile[$key] = $settings['post'][$key];
            }
        }
    }
    $settings = array_replace_recursive( $defaults, $settings );
    foreach ( array(
        'grid',
        'row',
        'half_width',
        'full_width',
        'masonry',
        'carousel'
    ) as $layout_slug ) {
        if ( isset( $settings['layout'][$layout_slug] ) && is_array( $settings['layout'][$layout_slug] ) ) {
            $settings['layout'][$layout_slug] = esf_instagram_normalize_layout_dimension_scope( $settings['layout'][$layout_slug] );
        }
    }
    if ( !empty( $legacy_post_tile ) ) {
        if ( !isset( $settings['feed'] ) || !is_array( $settings['feed'] ) ) {
            $settings['feed'] = array();
        }
        foreach ( $legacy_post_tile as $key => $value ) {
            $settings['feed'][$key] = $value;
        }
    }
    if ( isset( $settings['post'] ) && is_array( $settings['post'] ) ) {
        foreach ( $migrated_from_post as $key ) {
            unset($settings['post'][$key]);
        }
    }
    if ( isset( $settings['feed'] ) && is_array( $settings['feed'] ) ) {
        $feed =& $settings['feed'];
        $feed['hashtag_media_type'] = esf_instagram_normalize_hashtag_media_type( ( isset( $feed['hashtag_media_type'] ) ? $feed['hashtag_media_type'] : 'top_media' ) );
        if ( '' === trim( (string) ($feed['hover_stats_color'] ?? '') ) ) {
            if ( !empty( $feed['hover_likes_color'] ) ) {
                $feed['hover_stats_color'] = $feed['hover_likes_color'];
            } elseif ( !empty( $feed['hover_comments_color'] ) ) {
                $feed['hover_stats_color'] = $feed['hover_comments_color'];
            }
        }
        if ( isset( $feed['hover_likes_size'] ) ) {
            $feed['hover_stats_size'] = $feed['hover_likes_size'];
        } elseif ( isset( $feed['hover_comments_size'] ) ) {
            $feed['hover_stats_size'] = $feed['hover_comments_size'];
        }
        unset(
            $feed['hover_likes_color'],
            $feed['hover_comments_color'],
            $feed['hover_likes_size'],
            $feed['hover_comments_size']
        );
        $feed['account_ids'] = esf_instagram_sanitize_account_ids( ( isset( $feed['account_ids'] ) ? $feed['account_ids'] : array() ) );
    }
    if ( isset( $settings['layout']['full_width'] ) && is_array( $settings['layout']['full_width'] ) ) {
        $full_width_ratio = ( isset( $settings['layout']['full_width']['media_aspect_ratio'] ) ? (string) $settings['layout']['full_width']['media_aspect_ratio'] : '' );
        if ( '4:5' === $full_width_ratio ) {
            $settings['layout']['full_width']['media_aspect_ratio'] = '16:9';
        } elseif ( !in_array( $full_width_ratio, array('16:9', '1:1', '3:4'), true ) ) {
            $settings['layout']['full_width']['media_aspect_ratio'] = '16:9';
        }
    }
    if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
        if ( isset( $settings['layout']['type'] ) && 'carousel' === $settings['layout']['type'] ) {
            if ( !isset( $settings['feed'] ) || !is_array( $settings['feed'] ) ) {
                $settings['feed'] = array();
            }
            if ( !array_key_exists( 'hover_show_likes', $feed_incoming ) ) {
                $settings['feed']['hover_show_likes'] = false;
            }
            if ( !array_key_exists( 'hover_show_comments', $feed_incoming ) ) {
                $settings['feed']['hover_show_comments'] = false;
            }
            if ( !array_key_exists( 'hover_plus_size', $feed_incoming ) ) {
                $settings['feed']['hover_plus_size'] = 34;
            }
        }
        if ( isset( $settings['layout']['row'] ) && is_array( $settings['layout']['row'] ) ) {
            $row_style = ( isset( $settings['layout']['row']['row_style'] ) ? (string) $settings['layout']['row']['row_style'] : 'standard' );
            $settings['layout']['row']['row_style'] = ( 'wave' === $row_style ? 'wave' : 'standard' );
            $wave_offset = ( isset( $settings['layout']['row']['wave_offset'] ) ? (int) $settings['layout']['row']['wave_offset'] : 16 );
            $settings['layout']['row']['wave_offset'] = max( 8, min( 48, $wave_offset ) );
            if ( 'wave' === $settings['layout']['row']['row_style'] ) {
                $settings['layout']['row']['gap'] = 12;
                $settings['layout']['row']['tile_border_radius'] = 16;
                $settings['layout']['row']['tile_shadow'] = true;
                $settings['layout']['row']['media_aspect_ratio'] = '1:1';
                $settings['layout']['row']['columns_tablet'] = 1;
                $settings['layout']['row']['columns_mobile'] = 1;
                if ( !isset( $settings['layout']['row']['tile_shadow_color'] ) || '' === trim( (string) $settings['layout']['row']['tile_shadow_color'] ) ) {
                    $settings['layout']['row']['tile_shadow_color'] = 'rgba(0,0,0,0.12)';
                }
                if ( !isset( $settings['feed'] ) || !is_array( $settings['feed'] ) ) {
                    $settings['feed'] = array();
                }
                $settings['feed']['hover_overlay'] = false;
                $settings['feed']['show_hover_plus'] = false;
                $settings['feed']['hover_show_likes'] = false;
                $settings['feed']['hover_show_comments'] = false;
            }
        }
        return $settings;
    }
    if ( isset( $settings['layout']['grid'] ) && is_array( $settings['layout']['grid'] ) ) {
        $settings['layout']['grid']['gap'] = $defaults['layout']['grid']['gap'];
        $settings['layout']['grid']['tile_border_radius'] = $defaults['layout']['grid']['tile_border_radius'];
        $settings['layout']['grid']['tile_shadow'] = false;
        $settings['layout']['grid']['tile_shadow_color'] = $defaults['layout']['grid']['tile_shadow_color'];
    }
    if ( isset( $settings['layout']['row'] ) && is_array( $settings['layout']['row'] ) ) {
        // Gap stays free-customizable for Row; only Pro tile chrome is locked.
        $row_defaults = ( isset( $defaults['layout']['row'] ) && is_array( $defaults['layout']['row'] ) ? $defaults['layout']['row'] : array(
            'tile_border_radius' => 0,
            'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
        ) );
        $settings['layout']['row']['tile_border_radius'] = $row_defaults['tile_border_radius'];
        $settings['layout']['row']['tile_shadow'] = false;
        $settings['layout']['row']['tile_shadow_color'] = $row_defaults['tile_shadow_color'];
        $settings['layout']['row']['row_style'] = 'standard';
        $settings['layout']['row']['wave_offset'] = ( isset( $row_defaults['wave_offset'] ) ? max( 8, min( 48, (int) $row_defaults['wave_offset'] ) ) : 16 );
    }
    // Half Width / Full Width / Masonry / Carousel are Pro-only — fall back to Grid when the plan is unavailable.
    if ( isset( $settings['layout']['type'] ) && in_array( $settings['layout']['type'], array(
        'half_width',
        'full_width',
        'masonry',
        'carousel'
    ), true ) ) {
        $settings['layout']['type'] = 'grid';
    }
    if ( isset( $settings['layout']['masonry'] ) && is_array( $settings['layout']['masonry'] ) ) {
        $masonry_defaults = ( isset( $defaults['layout']['masonry'] ) && is_array( $defaults['layout']['masonry'] ) ? $defaults['layout']['masonry'] : array(
            'columns'              => 4,
            'columns_tablet'       => 3,
            'columns_mobile'       => 2,
            'gap'                  => 8,
            'tile_border_radius'   => 8,
            'tile_shadow'          => true,
            'tile_shadow_color'    => 'rgba(0,0,0,0.12)',
            'display_mode'         => 'media_only',
            'show_caption'         => true,
            'show_meta'            => true,
            'show_time'            => true,
            'caption_words'        => 18,
            'details_bg_color'     => '#ffffff',
            'details_text_color'   => '#262626',
            'details_accent_color' => '#e1306c',
            'details_meta_color'   => '#8e8e8e',
        ) );
        $settings['layout']['masonry']['columns'] = ( isset( $settings['layout']['masonry']['columns'] ) ? max( 2, min( 6, (int) $settings['layout']['masonry']['columns'] ) ) : $masonry_defaults['columns'] );
        $settings['layout']['masonry']['columns_tablet'] = ( isset( $settings['layout']['masonry']['columns_tablet'] ) ? max( 1, min( 5, (int) $settings['layout']['masonry']['columns_tablet'] ) ) : $masonry_defaults['columns_tablet'] );
        $settings['layout']['masonry']['columns_mobile'] = ( isset( $settings['layout']['masonry']['columns_mobile'] ) ? max( 1, min( 4, (int) $settings['layout']['masonry']['columns_mobile'] ) ) : $masonry_defaults['columns_mobile'] );
        $settings['layout']['masonry']['display_mode'] = ( isset( $settings['layout']['masonry']['display_mode'] ) && 'detailed' === $settings['layout']['masonry']['display_mode'] ? 'detailed' : 'media_only' );
        $settings['layout']['masonry']['show_caption'] = ( array_key_exists( 'show_caption', $settings['layout']['masonry'] ) ? (bool) $settings['layout']['masonry']['show_caption'] : (bool) $masonry_defaults['show_caption'] );
        $settings['layout']['masonry']['show_meta'] = ( array_key_exists( 'show_meta', $settings['layout']['masonry'] ) ? (bool) $settings['layout']['masonry']['show_meta'] : (bool) $masonry_defaults['show_meta'] );
        $settings['layout']['masonry']['show_time'] = ( array_key_exists( 'show_time', $settings['layout']['masonry'] ) ? (bool) $settings['layout']['masonry']['show_time'] : (bool) $masonry_defaults['show_time'] );
        $settings['layout']['masonry']['caption_words'] = ( isset( $settings['layout']['masonry']['caption_words'] ) ? max( 5, min( 100, (int) $settings['layout']['masonry']['caption_words'] ) ) : (int) $masonry_defaults['caption_words'] );
        $settings['layout']['masonry']['details_bg_color'] = ( isset( $settings['layout']['masonry']['details_bg_color'] ) ? (string) $settings['layout']['masonry']['details_bg_color'] : (string) $masonry_defaults['details_bg_color'] );
        $settings['layout']['masonry']['details_text_color'] = ( isset( $settings['layout']['masonry']['details_text_color'] ) ? (string) $settings['layout']['masonry']['details_text_color'] : (string) $masonry_defaults['details_text_color'] );
        $settings['layout']['masonry']['details_accent_color'] = ( isset( $settings['layout']['masonry']['details_accent_color'] ) ? (string) $settings['layout']['masonry']['details_accent_color'] : (string) $masonry_defaults['details_accent_color'] );
        $settings['layout']['masonry']['details_meta_color'] = ( isset( $settings['layout']['masonry']['details_meta_color'] ) ? (string) $settings['layout']['masonry']['details_meta_color'] : (string) $masonry_defaults['details_meta_color'] );
        $settings['layout']['masonry']['gap'] = $masonry_defaults['gap'];
        $settings['layout']['masonry']['tile_border_radius'] = $masonry_defaults['tile_border_radius'];
        $settings['layout']['masonry']['tile_shadow'] = false;
        $settings['layout']['masonry']['tile_shadow_color'] = $masonry_defaults['tile_shadow_color'];
    }
    if ( isset( $settings['layout']['carousel'] ) && is_array( $settings['layout']['carousel'] ) ) {
        $carousel_defaults = ( isset( $defaults['layout']['carousel'] ) && is_array( $defaults['layout']['carousel'] ) ? $defaults['layout']['carousel'] : array(
            'columns'              => 4,
            'columns_tablet'       => 3,
            'columns_mobile'       => 1,
            'gap'                  => 8,
            'tile_border_radius'   => 8,
            'display_mode'         => 'media_only',
            'media_aspect_ratio'   => '1:1',
            'show_caption'         => true,
            'show_meta'            => true,
            'show_time'            => true,
            'caption_words'        => 18,
            'details_bg_color'     => '#ffffff',
            'details_text_color'   => '#262626',
            'details_accent_color' => '#e1306c',
            'details_meta_color'   => '#8e8e8e',
            'autoplay'             => true,
            'loop'                 => true,
            'autoplay_speed'       => 3000,
            'show_arrows'          => true,
            'show_dots'            => true,
            'nav_color'            => '#d6d6d6',
            'nav_active_color'     => '#869791',
        ) );
        $settings['layout']['carousel']['columns'] = ( isset( $settings['layout']['carousel']['columns'] ) ? max( 1, min( 6, (int) $settings['layout']['carousel']['columns'] ) ) : $carousel_defaults['columns'] );
        $settings['layout']['carousel']['columns_tablet'] = ( isset( $settings['layout']['carousel']['columns_tablet'] ) ? max( 1, min( 5, (int) $settings['layout']['carousel']['columns_tablet'] ) ) : $carousel_defaults['columns_tablet'] );
        $settings['layout']['carousel']['columns_mobile'] = ( isset( $settings['layout']['carousel']['columns_mobile'] ) ? max( 1, min( 4, (int) $settings['layout']['carousel']['columns_mobile'] ) ) : $carousel_defaults['columns_mobile'] );
        $settings['layout']['carousel']['display_mode'] = ( isset( $settings['layout']['carousel']['display_mode'] ) && 'detailed' === $settings['layout']['carousel']['display_mode'] ? 'detailed' : 'media_only' );
        $carousel_ratio = ( isset( $settings['layout']['carousel']['media_aspect_ratio'] ) ? (string) $settings['layout']['carousel']['media_aspect_ratio'] : (string) $carousel_defaults['media_aspect_ratio'] );
        if ( !in_array( $carousel_ratio, array(
            '16:9',
            '1:1',
            '4:5',
            '3:4'
        ), true ) ) {
            $carousel_ratio = (string) $carousel_defaults['media_aspect_ratio'];
        }
        $settings['layout']['carousel']['media_aspect_ratio'] = $carousel_ratio;
        $settings['layout']['carousel']['show_caption'] = ( array_key_exists( 'show_caption', $settings['layout']['carousel'] ) ? (bool) $settings['layout']['carousel']['show_caption'] : (bool) $carousel_defaults['show_caption'] );
        $settings['layout']['carousel']['show_meta'] = ( array_key_exists( 'show_meta', $settings['layout']['carousel'] ) ? (bool) $settings['layout']['carousel']['show_meta'] : (bool) $carousel_defaults['show_meta'] );
        $settings['layout']['carousel']['show_time'] = ( array_key_exists( 'show_time', $settings['layout']['carousel'] ) ? (bool) $settings['layout']['carousel']['show_time'] : (bool) $carousel_defaults['show_time'] );
        $settings['layout']['carousel']['caption_words'] = ( isset( $settings['layout']['carousel']['caption_words'] ) ? max( 5, min( 100, (int) $settings['layout']['carousel']['caption_words'] ) ) : (int) $carousel_defaults['caption_words'] );
        $settings['layout']['carousel']['details_bg_color'] = ( isset( $settings['layout']['carousel']['details_bg_color'] ) ? (string) $settings['layout']['carousel']['details_bg_color'] : (string) $carousel_defaults['details_bg_color'] );
        $settings['layout']['carousel']['details_text_color'] = ( isset( $settings['layout']['carousel']['details_text_color'] ) ? (string) $settings['layout']['carousel']['details_text_color'] : (string) $carousel_defaults['details_text_color'] );
        $settings['layout']['carousel']['details_accent_color'] = ( isset( $settings['layout']['carousel']['details_accent_color'] ) ? (string) $settings['layout']['carousel']['details_accent_color'] : (string) $carousel_defaults['details_accent_color'] );
        $settings['layout']['carousel']['details_meta_color'] = ( isset( $settings['layout']['carousel']['details_meta_color'] ) ? (string) $settings['layout']['carousel']['details_meta_color'] : (string) $carousel_defaults['details_meta_color'] );
        $settings['layout']['carousel']['autoplay'] = ( array_key_exists( 'autoplay', $settings['layout']['carousel'] ) ? (bool) $settings['layout']['carousel']['autoplay'] : (bool) $carousel_defaults['autoplay'] );
        $settings['layout']['carousel']['loop'] = ( array_key_exists( 'loop', $settings['layout']['carousel'] ) ? (bool) $settings['layout']['carousel']['loop'] : (bool) $carousel_defaults['loop'] );
        $settings['layout']['carousel']['autoplay_speed'] = ( isset( $settings['layout']['carousel']['autoplay_speed'] ) ? max( 2000, min( 15000, (int) $settings['layout']['carousel']['autoplay_speed'] ) ) : (int) $carousel_defaults['autoplay_speed'] );
        $settings['layout']['carousel']['show_arrows'] = ( array_key_exists( 'show_arrows', $settings['layout']['carousel'] ) ? (bool) $settings['layout']['carousel']['show_arrows'] : (bool) $carousel_defaults['show_arrows'] );
        $settings['layout']['carousel']['show_dots'] = ( array_key_exists( 'show_dots', $settings['layout']['carousel'] ) ? (bool) $settings['layout']['carousel']['show_dots'] : (bool) $carousel_defaults['show_dots'] );
        $settings['layout']['carousel']['nav_color'] = ( isset( $settings['layout']['carousel']['nav_color'] ) ? (string) $settings['layout']['carousel']['nav_color'] : (string) $carousel_defaults['nav_color'] );
        $settings['layout']['carousel']['nav_active_color'] = ( isset( $settings['layout']['carousel']['nav_active_color'] ) ? (string) $settings['layout']['carousel']['nav_active_color'] : (string) $carousel_defaults['nav_active_color'] );
        $settings['layout']['carousel']['gap'] = $carousel_defaults['gap'];
        $settings['layout']['carousel']['tile_border_radius'] = $carousel_defaults['tile_border_radius'];
        unset($settings['layout']['carousel']['tile_shadow'], $settings['layout']['carousel']['tile_shadow_color']);
    }
    // Free plan keeps core header content toggles; Pro locks below are forced.
    $free_header_defaults = array(
        'show_avatar'        => true,
        'show_name'          => true,
        'show_handle'        => true,
        'show_bio'           => true,
        'show_followers'     => true,
        'show_media'         => true,
        'show_follow_button' => true,
    );
    $pro_locked_header = array(
        'show_link'      => false,
        'avatar_round'   => true,
        'header_rounded' => true,
        'show_border'    => true,
        'border_color'   => '',
        'padding'        => 16,
        'bg_color'       => '',
        'text_color'     => '',
        'show_stories'   => false,
        'stories_ring'   => false,
        'stories_row'    => false,
    );
    $incoming_header = ( isset( $settings['header'] ) && is_array( $settings['header'] ) ? $settings['header'] : array() );
    // Feeds saved under the old free strip (content toggles forced off except name)
    // should pick up the new free defaults instead of staying locked-off forever.
    $looks_like_legacy_free_header = array_key_exists( 'show_avatar', $incoming_header ) && empty( $incoming_header['show_avatar'] ) && array_key_exists( 'show_handle', $incoming_header ) && empty( $incoming_header['show_handle'] ) && array_key_exists( 'show_bio', $incoming_header ) && empty( $incoming_header['show_bio'] ) && array_key_exists( 'show_followers', $incoming_header ) && empty( $incoming_header['show_followers'] ) && array_key_exists( 'show_media', $incoming_header ) && empty( $incoming_header['show_media'] ) && array_key_exists( 'show_follow_button', $incoming_header ) && empty( $incoming_header['show_follow_button'] );
    if ( $looks_like_legacy_free_header ) {
        foreach ( array_keys( $free_header_defaults ) as $free_header_key ) {
            unset($incoming_header[$free_header_key]);
        }
    }
    $header_show = ( array_key_exists( 'show', $incoming_header ) ? (bool) $incoming_header['show'] : !isset( $defaults['header']['show'] ) || (bool) $defaults['header']['show'] );
    $settings['header'] = array_merge(
        ( isset( $defaults['header'] ) && is_array( $defaults['header'] ) ? $defaults['header'] : array() ),
        $free_header_defaults,
        $incoming_header,
        $pro_locked_header,
        array(
            'show' => $header_show,
        )
    );
    if ( !isset( $settings['feed'] ) || !is_array( $settings['feed'] ) ) {
        $settings['feed'] = array();
    }
    $settings['feed']['hover_show_likes'] = false;
    $settings['feed']['hover_show_comments'] = false;
    $settings['feed']['hover_plus_color'] = '';
    $settings['feed']['hover_plus_size'] = $defaults['feed']['hover_plus_size'] ?? 42;
    $settings['feed']['hover_stats_color'] = '';
    $settings['feed']['hover_stats_size'] = $defaults['feed']['hover_stats_size'] ?? 14;
    unset(
        $settings['feed']['hover_likes_color'],
        $settings['feed']['hover_comments_color'],
        $settings['feed']['hover_likes_size'],
        $settings['feed']['hover_comments_size']
    );
    return $settings;
}

/**
 * Flag that the Instagram frontend feed CSS/JS should be enqueued.
 *
 * Set when {@see ESF_Instagram_Frontend::render_shortcode()} renders at
 * least one feed during the current request. Read again in `wp_footer`
 * so we only ship assets to pages that actually need them.
 *
 * @since 6.9.0
 * @return void
 */
function esf_instagram_flag_feed_script() {
    global $esf_instagram_feed_script_needed;
    $esf_instagram_feed_script_needed = true;
}

/**
 * Normalize REST/URL auth flow to a canonical value (not Instagram profile `account_type`).
 *
 * Accepts legacy aliases: `basic` → instagram_login, `business` → facebook_page.
 *
 * @since 6.8.0
 * @param string $type Raw type from query or bridge.
 * @return string `instagram_login`|`facebook_page`
 */
function esf_instagram_normalize_auth_flow_type(  $type  ) {
    $type = sanitize_key( (string) $type );
    if ( 'instagram_login' === $type || 'basic' === $type ) {
        return 'instagram_login';
    }
    return 'facebook_page';
}

/**
 * Resolved `auth_source` for a DB row (stored column, or inferred from tokens).
 *
 * @since 6.8.0
 * @param object|null $row Account row.
 * @return string `instagram_login`|`facebook_page`
 */
function esf_instagram_auth_source_for_account_row(  $row  ) {
    if ( is_object( $row ) && isset( $row->auth_source ) ) {
        $src = sanitize_key( (string) $row->auth_source );
        if ( in_array( $src, array('instagram_login', 'facebook_page'), true ) ) {
            return $src;
        }
    }
    if ( !is_object( $row ) ) {
        return 'facebook_page';
    }
    $page_tok = ( isset( $row->page_access_token ) ? trim( (string) $row->page_access_token ) : '' );
    $page_id = ( isset( $row->facebook_page_id ) ? trim( (string) $row->facebook_page_id ) : '' );
    if ( '' !== $page_tok || '' !== $page_id ) {
        return 'facebook_page';
    }
    $user_tok = ( isset( $row->access_token ) ? trim( (string) $row->access_token ) : '' );
    if ( '' !== $user_tok ) {
        return 'instagram_login';
    }
    return 'facebook_page';
}

/**
 * Whether the account row should prompt the user to reconnect (manual OAuth).
 *
 * True when status is `expired` or `invalid`, or when still `active` but the stored
 * `token_expires_at` (UTC) is in the past (automatic refresh likely failed or never ran).
 *
 * @since 6.8.0
 * @param object|null $row Account row from {@see ESF_Instagram_Account_Repository}.
 * @return bool
 */
function esf_instagram_account_needs_reconnect(  $row  ) {
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
 * Mark an Instagram account invalid and notify admins when a Graph error needs reconnect.
 *
 * Thin wrapper around {@see ESF_Meta_Graph_Error::maybe_mark_reconnect()} so media,
 * hashtag, stories, comments, and token-refresh paths share one side-effect path.
 * Facebook modern module can call the shared class with its own callables later.
 *
 * @since 6.9.4
 * @param object|null                        $account_row Account row (needs numeric `id`).
 * @param array<string,mixed>|WP_Error|mixed $error       Graph `error` object or WP_Error.
 * @return bool True when the account was marked for reconnect.
 */
function esf_instagram_maybe_mark_account_reconnect_from_graph_error(  $account_row, $error  ) {
    if ( !class_exists( 'ESF_Meta_Graph_Error' ) || !is_object( $account_row ) ) {
        return false;
    }
    $account_id = ( isset( $account_row->id ) ? (int) $account_row->id : 0 );
    if ( $account_id <= 0 ) {
        return false;
    }
    return ESF_Meta_Graph_Error::maybe_mark_reconnect( $error, array(
        'account_id'  => $account_id,
        'status'      => 'invalid',
        'mark_status' => static function ( $id, $status ) {
            if ( !class_exists( 'ESF_Instagram_Account_Repository' ) ) {
                return false;
            }
            return ESF_Instagram_Account_Repository::get_instance()->update_status( (int) $id, (string) $status );
        },
        'notify'      => static function ( $id ) {
            if ( class_exists( 'ESF_Instagram_Token_Notifications' ) ) {
                ESF_Instagram_Token_Notifications::maybe_notify_reconnect_required( (int) $id );
            }
        },
    ) );
}

/**
 * Read Instagram modern settings.
 *
 * @since 6.8.0
 * @param string|null $key Optional setting key.
 * @return mixed
 */
function esf_get_instagram_settings(  $key = null  ) {
    $settings = get_option( 'esf_instagram_settings', array() );
    if ( null !== $key ) {
        return ( isset( $settings[$key] ) ? $settings[$key] : null );
    }
    return $settings;
}

/**
 * Default module settings merged when options are unset.
 *
 * @since 6.9.0
 * @return array<string, mixed>
 */
function esf_instagram_get_settings_defaults() {
    return array(
        'cache_duration'                => 43200,
        'notify_token_reconnect'        => true,
        'notify_token_reconnect_emails' => array(),
    );
}

/**
 * Sanitize reconnect-notification recipient emails against site administrators.
 *
 * @since 6.9.0
 * @param mixed $emails Raw email list.
 * @return string[]
 */
function esf_instagram_sanitize_notify_emails(  $emails  ) {
    if ( !is_array( $emails ) ) {
        return array();
    }
    $allowed = ( function_exists( 'esf_get_site_administrator_emails' ) ? esf_get_site_administrator_emails() : array() );
    if ( empty( $allowed ) ) {
        $fallback = sanitize_email( (string) get_option( 'admin_email' ) );
        if ( '' !== $fallback && is_email( $fallback ) ) {
            $allowed = array(strtolower( $fallback ));
        }
    }
    $allowed_lookup = array_fill_keys( array_map( 'strtolower', $allowed ), true );
    $out = array();
    foreach ( $emails as $email ) {
        $email = strtolower( sanitize_email( (string) $email ) );
        if ( '' !== $email && is_email( $email ) && isset( $allowed_lookup[$email] ) ) {
            $out[] = $email;
        }
    }
    return array_values( array_unique( $out ) );
}

/**
 * Default reconnect-notification recipients (site admin email).
 *
 * @since 6.9.0
 * @return string[]
 */
function esf_instagram_default_notify_emails() {
    $options = ( function_exists( 'esf_get_site_administrator_email_options' ) ? esf_get_site_administrator_email_options() : array() );
    foreach ( $options as $option ) {
        if ( !empty( $option['is_default'] ) && !empty( $option['email'] ) ) {
            return array((string) $option['email']);
        }
    }
    if ( !empty( $options[0]['email'] ) ) {
        return array((string) $options[0]['email']);
    }
    $fallback = sanitize_email( (string) get_option( 'admin_email' ) );
    return ( '' !== $fallback && is_email( $fallback ) ? array(strtolower( $fallback )) : array() );
}

/**
 * Merge saved Instagram settings with module defaults.
 *
 * @since 6.9.0
 * @param array<string, mixed> $saved Raw option values.
 * @return array<string, mixed>
 */
function esf_instagram_normalize_settings(  array $saved  ) {
    $settings = array_merge( esf_instagram_get_settings_defaults(), $saved );
    $emails = ( isset( $settings['notify_token_reconnect_emails'] ) && is_array( $settings['notify_token_reconnect_emails'] ) ? $settings['notify_token_reconnect_emails'] : array() );
    $settings['notify_token_reconnect_emails'] = esf_instagram_sanitize_notify_emails( $emails );
    if ( !empty( $settings['notify_token_reconnect'] ) && empty( $settings['notify_token_reconnect_emails'] ) ) {
        $settings['notify_token_reconnect_emails'] = esf_instagram_default_notify_emails();
    }
    return $settings;
}

/**
 * Resolved Instagram settings for runtime (cron, mailers, cache TTL).
 *
 * Applies defaults without requiring the Settings tab to be saved first.
 *
 * @since 6.9.0
 * @return array<string, mixed>
 */
function esf_instagram_get_resolved_settings() {
    $saved = get_option( 'esf_instagram_settings', array() );
    return esf_instagram_normalize_settings( ( is_array( $saved ) ? $saved : array() ) );
}

/**
 * Update Instagram modern settings.
 *
 * @since 6.8.0
 * @param string|array $key Setting key or array to merge.
 * @param mixed        $value Optional setting value.
 * @return bool
 */
function esf_update_instagram_settings(  $key, $value = null  ) {
    $settings = esf_get_instagram_settings();
    if ( is_array( $key ) ) {
        $settings = array_merge( $settings, $key );
    } else {
        $settings[$key] = $value;
    }
    return update_option( 'esf_instagram_settings', $settings );
}

/**
 * Resolve the module cache TTL in seconds from Instagram settings.
 *
 * Reads `cache_duration` from `esf_instagram_settings` (Settings tab) and
 * falls back to {@see ESF_Instagram_Cache::DEFAULT_TTL} when unset or below
 * the minimum (1 hour). Media, comments, and other API rows should all use
 * this helper so cache duration stays consistent site-wide.
 *
 * @since 6.9.0
 *
 * @param object|null $feed_obj   Optional feed context for filters.
 * @param int         $account_id Optional account context for filters.
 * @return int TTL in seconds.
 */
function esf_instagram_get_cache_ttl_secs(  $feed_obj = null, $account_id = 0  ) {
    $saved = get_option( 'esf_instagram_settings', array() );
    $saved = ( is_array( $saved ) ? $saved : array() );
    $ttl = ( isset( $saved['cache_duration'] ) ? (int) $saved['cache_duration'] : (int) esf_instagram_get_settings_defaults()['cache_duration'] );
    if ( $ttl < 3600 ) {
        $ttl = ( class_exists( 'ESF_Instagram_Cache' ) ? ESF_Instagram_Cache::DEFAULT_TTL : 43200 );
    }
    /**
     * Filter the Instagram cache TTL used when writing rows to `wp_esf_instagram_cache`.
     *
     * @since 6.9.0
     *
     * @param int         $ttl        TTL in seconds.
     * @param object|null $feed_obj   Feed object when available.
     * @param int         $account_id Account ID when available.
     */
    return (int) apply_filters(
        'esf_instagram_media_cache_ttl',
        $ttl,
        $feed_obj,
        $account_id
    );
}

/**
 * Localized strings and REST config for the Instagram popup lightbox script.
 *
 * Shared by the public frontend and the admin customize preview so comment
 * loading behaves the same in both contexts.
 *
 * @since 6.9.0
 * @return array<string,mixed>
 */
function esf_instagram_get_lightbox_localize_data() {
    return array(
        'restUrl'                     => rest_url( 'esf/v1' ),
        'nonce'                       => wp_create_nonce( 'wp_rest' ),
        'viewOnInstagramText'         => __( 'View on Instagram', 'easy-facebook-likebox' ),
        'replyOnInstagramText'        => __( 'View comments on Instagram', 'easy-facebook-likebox' ),
        'writeCommentOnInstagramText' => __( 'Add a comment on Instagram…', 'easy-facebook-likebox' ),
        'videoText'                   => __( 'Video', 'easy-facebook-likebox' ),
        'galleryText'                 => __( 'Gallery', 'easy-facebook-likebox' ),
        'previousMediaText'           => __( 'Previous', 'easy-facebook-likebox' ),
        'nextMediaText'               => __( 'Next', 'easy-facebook-likebox' ),
        'imageText'                   => __( 'Image', 'easy-facebook-likebox' ),
        'likesText'                   => __( 'Likes', 'easy-facebook-likebox' ),
        'commentsText'                => __( 'Comments', 'easy-facebook-likebox' ),
        'commentsShowingText'         => __( 'Showing %1$s of %2$s', 'easy-facebook-likebox' ),
        'loadMoreCommentsText'        => __( 'Load more comments', 'easy-facebook-likebox' ),
        'loadMoreRepliesText'         => __( 'Load more replies', 'easy-facebook-likebox' ),
        'loadingCommentsText'         => __( 'Loading comments…', 'easy-facebook-likebox' ),
        'noCommentsText'              => __( 'No comments yet.', 'easy-facebook-likebox' ),
        'commentsErrorText'           => __( 'Could not load comments.', 'easy-facebook-likebox' ),
        'viewRepliesText'             => __( 'View all %d replies', 'easy-facebook-likebox' ),
        'viewReplyText'               => __( 'View %d reply', 'easy-facebook-likebox' ),
        'hideRepliesText'             => __( 'Hide replies', 'easy-facebook-likebox' ),
        'anonymousUserText'           => __( 'Instagram user', 'easy-facebook-likebox' ),
        'pauseStoriesText'            => __( 'Pause stories', 'easy-facebook-likebox' ),
        'playStoriesText'             => __( 'Play stories', 'easy-facebook-likebox' ),
        'previousStoryText'           => __( 'Previous story', 'easy-facebook-likebox' ),
        'nextStoryText'               => __( 'Next story', 'easy-facebook-likebox' ),
        'muteStoriesText'             => __( 'Mute video', 'easy-facebook-likebox' ),
        'unmuteStoriesText'           => __( 'Unmute video', 'easy-facebook-likebox' ),
        'closeStoriesText'            => __( 'Close stories', 'easy-facebook-likebox' ),
        'numberSuffixes'              => array(
            'k' => __( 'K', 'easy-facebook-likebox' ),
            'm' => __( 'M', 'easy-facebook-likebox' ),
            'b' => __( 'B', 'easy-facebook-likebox' ),
        ),
    );
}

/**
 * Render legacy migration notice in old Instagram admin screen.
 *
 * Shown whenever the site is on the legacy Instagram admin (`page=mif` and
 * modern mode is off). That covers both unmigrated sites with leftover
 * `fta_settings` data and support/QA force-switches via `?esf_force_legacy=1`
 * on fresh modern installs (no legacy data).
 *
 * @since 6.8.0
 * @return void
 */
function esf_instagram_render_legacy_migration_notice() {
    if ( !is_admin() || esf_instagram_use_new_system() ) {
        return;
    }
    if ( !isset( $_GET['page'] ) || 'mif' !== sanitize_key( (string) wp_unslash( $_GET['page'] ) ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    if ( !current_user_can( 'manage_options' ) ) {
        return;
    }
    static $esf_instagram_migrate_overlay_registered = false;
    if ( !$esf_instagram_migrate_overlay_registered ) {
        $esf_instagram_migrate_overlay_registered = true;
        add_action( 'admin_print_footer_scripts', 'esf_instagram_print_legacy_migrate_overlay_script', 20 );
    }
    $has_legacy_data = esf_instagram_has_legacy_data();
    $migrate_url = wp_nonce_url( add_query_arg( array(
        'page'                  => 'mif',
        'esf_instagram_migrate' => '1',
    ), admin_url( 'admin.php' ) ), 'esf_instagram_migrate_legacy', 'esf_instagram_migrate_nonce' );
    ?>
	<div class="notice notice-warning fs-notice fs-slug-easy-facebook-likebox">
		<p><strong><?php 
    esc_html_e( 'A new Instagram experience is here', 'easy-facebook-likebox' );
    ?></strong></p>
		<?php 
    if ( $has_legacy_data ) {
        ?>
			<p>
				<?php 
        esc_html_e( 'We have rebuilt Instagram from the ground up so it is faster, easier to set up, and simpler to manage. Connecting accounts, customizing how feeds look, and showing them on your site has been redesigned to save you time.', 'easy-facebook-likebox' );
        ?>
			</p>
			<p>
				<?php 
        esc_html_e( 'The screens you are using now belong to the older version. They still work for now, but they will be removed in a future release and only the new system will receive updates, fixes, and new features. Switch over to copy your connected accounts into the new system. You will need to create new feeds and replace old shortcodes on your site afterward. You can switch back temporarily from the new Instagram admin if needed.', 'easy-facebook-likebox' );
        ?>
			</p>
		<?php 
    } else {
        ?>
			<p>
				<?php 
        esc_html_e( 'You are viewing the older Instagram admin. This is usually opened for support or testing. Switch back to the new Instagram dashboard to continue managing accounts and feeds.', 'easy-facebook-likebox' );
        ?>
			</p>
		<?php 
    }
    ?>
		<p>
			<a id="esf-instagram-migrate-btn" class="button button-primary" href="<?php 
    echo esc_url( $migrate_url );
    ?>">
				<?php 
    esc_html_e( 'Switch to the new Instagram', 'easy-facebook-likebox' );
    ?>
			</a>
		</p>
	</div>
	<?php 
}

/**
 * Full-screen loader while legacy Instagram migration request runs.
 *
 * @since 6.8.0
 * @return void
 */
function esf_instagram_print_legacy_migrate_overlay_script() {
    if ( !isset( $_GET['page'] ) || 'mif' !== sanitize_key( (string) wp_unslash( $_GET['page'] ) ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    if ( !current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
	<style id="esf-instagram-migrate-overlay-style">
		#esf-instagram-migrate-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:none;align-items:center;justify-content:center;}
		#esf-instagram-migrate-overlay.is-visible{display:flex;}
		#esf-instagram-migrate-overlay .esf-instagram-migrate-overlay-inner{background:#fff;padding:28px 36px;border-radius:4px;box-shadow:0 4px 24px rgba(0,0,0,.2);text-align:center;max-width:360px;}
		#esf-instagram-migrate-overlay .spinner{float:none;margin:0 auto 12px;visibility:visible;}
	</style>
	<div id="esf-instagram-migrate-overlay" aria-hidden="true">
		<div class="esf-instagram-migrate-overlay-inner">
			<span class="spinner is-active"></span>
			<p style="margin:0;font-size:14px;"><?php 
    esc_html_e( 'Switching your Instagram setup…', 'easy-facebook-likebox' );
    ?></p>
			<p style="margin:12px 0 0;font-size:13px;color:#646970;"><?php 
    esc_html_e( 'Please wait. You will be redirected when migration finishes.', 'easy-facebook-likebox' );
    ?></p>
		</div>
	</div>
	<script>
	(function () {
		var btn = document.getElementById('esf-instagram-migrate-btn');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', function (e) {
			var overlay = document.getElementById('esf-instagram-migrate-overlay');
			if (!overlay) {
				return;
			}
			e.preventDefault();
			overlay.classList.add('is-visible');
			overlay.setAttribute('aria-hidden', 'false');
			window.location.href = btn.getAttribute('href');
		});
	}());
	</script>
	<?php 
}

/**
 * Handle legacy-to-modern migration action from admin notice.
 *
 * @since 6.8.0
 * @return void
 */
function esf_instagram_handle_legacy_migration_action() {
    if ( !is_admin() ) {
        return;
    }
    if ( !isset( $_GET['page'], $_GET['esf_instagram_migrate'] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    $page = sanitize_key( (string) wp_unslash( $_GET['page'] ) );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( 'mif' !== $page ) {
        return;
    }
    if ( '1' !== sanitize_text_field( (string) wp_unslash( $_GET['esf_instagram_migrate'] ) ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    if ( !current_user_can( 'manage_options' ) ) {
        return;
    }
    $nonce = ( isset( $_GET['esf_instagram_migrate_nonce'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['esf_instagram_migrate_nonce'] ) ) : '' );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( '' === $nonce || !wp_verify_nonce( $nonce, 'esf_instagram_migrate_legacy' ) ) {
        return;
    }
    if ( !trait_exists( 'ESF_Instagram_Singleton', false ) ) {
        require_once ESF_INSTAGRAM_DIR . 'includes/traits/trait-esf-instagram-singleton.php';
    }
    if ( !class_exists( 'ESF_Instagram_DB_Installer' ) ) {
        require_once ESF_INSTAGRAM_DIR . 'includes/database/class-esf-instagram-db-installer.php';
    }
    if ( !class_exists( 'ESF_Instagram_Account_Repository' ) ) {
        require_once ESF_INSTAGRAM_DIR . 'includes/models/class-esf-instagram-account-repository.php';
    }
    if ( !class_exists( 'ESF_Instagram_Migrator' ) ) {
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-migrator.php';
    }
    if ( !ESF_Instagram_DB_Installer::tables_exist() ) {
        ESF_Instagram_DB_Installer::create_tables();
    }
    ESF_Instagram_Migrator::get_instance()->migrate();
    wp_safe_redirect( add_query_arg( array(
        'page'            => 'esf-instagram',
        'esf_ig_migrated' => '1',
    ), admin_url( 'admin.php' ) ) );
    exit;
}

/**
 * Run Instagram feed post filters (moderation, multifeed, integrators) on raw nodes.
 *
 * Load More and other paginated endpoints must slice the filtered list so offsets
 * match the initial frontend render. Raw-cache offsets duplicate tiles when posts
 * are hidden or when show-only mode is active.
 *
 * @since 6.9.0
 *
 * @param array<int,array<string,mixed>> $nodes    Raw Graph nodes.
 * @param object                         $feed_obj Feed object.
 * @param object|null                    $account  Owning account VO.
 * @return array<int,array<string,mixed>>
 */
function esf_instagram_filter_feed_raw_nodes(  array $nodes, $feed_obj, $account = null  ) {
    $nodes = apply_filters(
        'esf_instagram_filter_raw_posts',
        $nodes,
        $feed_obj,
        $account
    );
    $nodes = apply_filters(
        'esf_layouts_filter_raw_posts',
        $nodes,
        'instagram',
        $feed_obj,
        $account
    );
    return ( is_array( $nodes ) ? array_values( $nodes ) : array() );
}

if ( function_exists( 'add_filter' ) && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
    add_filter(
        'esf_moderation_get_feed_settings',
        'esf_instagram_moderation_get_feed_settings__premium_only',
        10,
        3
    );
    add_filter(
        'esf_moderation_save_feed_settings',
        'esf_instagram_moderation_save_feed_settings__premium_only',
        10,
        4
    );
    add_filter(
        'esf_moderation_feed_exists',
        'esf_instagram_moderation_feed_exists__premium_only',
        10,
        3
    );
}
if ( function_exists( 'add_filter' ) && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
    add_filter(
        'esf_shoppable_get_feed_settings',
        'esf_instagram_shoppable_get_feed_settings__premium_only',
        10,
        3
    );
    add_filter(
        'esf_shoppable_save_feed_settings',
        'esf_instagram_shoppable_save_feed_settings__premium_only',
        10,
        4
    );
    add_filter(
        'esf_shoppable_feed_exists',
        'esf_instagram_shoppable_feed_exists__premium_only',
        10,
        3
    );
}