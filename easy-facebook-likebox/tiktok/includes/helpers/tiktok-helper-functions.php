<?php

/**
 * TikTok Module Helper Functions
 *
 * Global helper functions for the TikTok module.
 * These functions provide a WordPress-like API for common operations.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/Helpers
 * @since 6.8.0
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Check if the TikTok module is currently active.
 *
 * @since 6.8.0
 * @return bool True if active, false otherwise.
 */
function esf_is_tiktok_active() {
    $fta_settings = get_option( 'fta_settings', array() );
    $status = ( isset( $fta_settings['plugins']['tiktok']['status'] ) ? $fta_settings['plugins']['tiktok']['status'] : 'deactivated' );
    return 'activated' === $status;
}

/**
 * Get TikTok module settings.
 *
 * Applies defaults, whitelist validation, and free-plan cache clamp so TTL /
 * cron stay aligned with the Settings UI (7 days on free).
 *
 * Can return a specific setting if a key is provided.
 *
 * @since 6.8.0
 * @param string|null $key Optional. Specific setting key to retrieve.
 * @return mixed Array of all settings or specific setting value.
 */
function esf_get_tiktok_settings(  $key = null  ) {
    $saved = get_option( 'esf_tiktok_settings', array() );
    if ( !is_array( $saved ) ) {
        $saved = array();
    }
    $settings = array_merge( array(
        'cache_duration'         => 43200,
        'notify_token_reconnect' => true,
    ), $saved );
    $allowed = array(43200, 86400, 604800);
    if ( !in_array( (int) $settings['cache_duration'], $allowed, true ) ) {
        $settings['cache_duration'] = 43200;
    }
    $has_plan = function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan();
    if ( !$has_plan ) {
        $settings['cache_duration'] = 604800;
    }
    if ( null !== $key ) {
        return ( array_key_exists( $key, $settings ) ? $settings[$key] : $settings );
    }
    return $settings;
}

/**
 * Update TikTok module settings.
 *
 * Updates a specific setting or merges an array of settings.
 *
 * @since 6.8.0
 * @param string|array $key   Setting key or array of settings.
 * @param mixed        $value Setting value (ignored when $key is an array).
 * @return bool True on success, false on failure.
 */
function esf_update_tiktok_settings(  $key, $value = null  ) {
    $settings = esf_get_tiktok_settings();
    if ( is_array( $key ) ) {
        $settings = array_merge( $settings, $key );
    } else {
        $settings[$key] = $value;
    }
    return update_option( 'esf_tiktok_settings', $settings );
}

/**
 * Check if the current user has permission to manage the TikTok module.
 *
 * @since 6.8.0
 * @return bool True if the user can manage, false otherwise.
 */
function esf_tiktok_user_can_manage() {
    /**
     * Filters the capability required to manage the TikTok module.
     *
     * @since 6.8.0
     * @param string $capability Required capability.
     */
    $capability = apply_filters( 'esf_tiktok_manage_capability', 'manage_options' );
    return current_user_can( $capability );
}

/**
 * Check whether the site has an active TikTok Pro plan.
 *
 * Returns true when premium code is available and the user has
 * tiktok_premium or combo_premium. Used to gate Pro features when they ship.
 *
 * @since 6.8.0
 * @return bool True if TikTok Pro plan is active, false otherwise.
 */
function esf_tiktok_has_tiktok_plan() {
    if ( !function_exists( 'efl_fs' ) ) {
        return false;
    }
    $fs = efl_fs();
    return false;
    return $fs->is_plan( 'tiktok_premium', true ) || $fs->is_plan( 'combo_premium', true );
}

/**
 * Whether a TikTok account row needs reconnect (expired/invalid or past expiry).
 *
 * @since 6.8.0
 * @param object|null $row Account row from esf_tiktok_accounts.
 * @return bool
 */
function esf_tiktok_account_needs_reconnect(  $row  ) {
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
 * Log a TikTok module error.
 *
 * Intentionally a no-op in production to avoid logging sensitive tokens
 * or PII. Reserved for future structured logging.
 *
 * @since 6.8.0
 * @param string              $message Log message.
 * @param array<string,mixed> $context Optional context (unused).
 * @return void
 */
function esf_tiktok_log_error(  $message, $context = array()  ) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future logging implementation.
}

/**
 * Admin URL for the TikTok dashboard.
 *
 * @since 6.8.0
 * @param array<string,mixed> $args Optional query args.
 * @return string
 */
function esf_get_tiktok_admin_url(  $args = array()  ) {
    $default_args = array(
        'page' => 'esf-tiktok',
    );
    $args = wp_parse_args( $args, $default_args );
    return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Strip Pro-only TikTok feed settings when TikTok Pro is unavailable.
 *
 * Free layouts are `grid` and `row`. Pro layouts (carousel, masonry, etc.)
 * fall back to Grid. Pro hover colors, popup, load more, and Row wave
 * reset to free defaults.
 *
 * @since 6.9.0
 * @param array $settings Feed settings.
 * @return array
 */
function esf_tiktok_lock_free_hover_styles(  $settings  ) {
    if ( !is_array( $settings ) ) {
        return array();
    }
    if ( function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan() ) {
        return $settings;
    }
    $defaults = ( class_exists( 'ESF_TikTok_Feed_Repository' ) ? ESF_TikTok_Feed_Repository::get_default_settings() : array() );
    if ( !isset( $settings['feed'] ) || !is_array( $settings['feed'] ) ) {
        $settings['feed'] = array();
    }
    $default_size = ( isset( $defaults['feed']['hover_plus_size'] ) ? (int) $defaults['feed']['hover_plus_size'] : 42 );
    $settings['feed']['hover_plus_color'] = '';
    $settings['feed']['hover_plus_size'] = $default_size;
    $settings['feed']['show_popup'] = false;
    $settings['feed']['load_more'] = false;
    if ( !isset( $settings['layout'] ) || !is_array( $settings['layout'] ) ) {
        $settings['layout'] = array();
    }
    $layout_type = ( isset( $settings['layout']['type'] ) ? sanitize_key( (string) $settings['layout']['type'] ) : 'grid' );
    $free_layouts = array('grid', 'row');
    if ( !in_array( $layout_type, $free_layouts, true ) ) {
        $layout_type = 'grid';
    }
    $settings['layout']['type'] = $layout_type;
    if ( isset( $settings['layout']['row'] ) && is_array( $settings['layout']['row'] ) ) {
        $row_defaults = ( isset( $defaults['layout']['row'] ) && is_array( $defaults['layout']['row'] ) ? $defaults['layout']['row'] : array(
            'tile_border_radius' => 0,
            'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
            'wave_offset'        => 16,
        ) );
        $settings['layout']['row']['tile_border_radius'] = ( isset( $row_defaults['tile_border_radius'] ) ? (int) $row_defaults['tile_border_radius'] : 0 );
        $settings['layout']['row']['tile_shadow'] = false;
        $settings['layout']['row']['tile_shadow_color'] = ( isset( $row_defaults['tile_shadow_color'] ) ? (string) $row_defaults['tile_shadow_color'] : 'rgba(0,0,0,0.12)' );
        $settings['layout']['row']['row_style'] = 'standard';
        $settings['layout']['row']['wave_offset'] = ( isset( $row_defaults['wave_offset'] ) ? max( 8, min( 48, (int) $row_defaults['wave_offset'] ) ) : 16 );
    }
    return $settings;
}

/**
 * Escape, linkify, and hashtag/mention-link TikTok captions.
 *
 * @since 6.16.0
 *
 * @param string               $caption Raw caption text.
 * @param array<string, mixed> $args    Optional args for {@see esf_format_caption_html()}.
 * @return string Sanitized HTML.
 */
function esf_tiktok_format_caption_html(  $caption, array $args = array()  ) {
    if ( !function_exists( 'esf_format_caption_html' ) ) {
        return esc_html( (string) $caption );
    }
    $defaults = array(
        'hashtag_args' => array(
            'class' => 'esf-tiktok-feed__hashtag',
        ),
    );
    $args = ( function_exists( 'wp_parse_args' ) ? wp_parse_args( $args, $defaults ) : array_merge( $defaults, $args ) );
    return esf_format_caption_html( (string) $caption, 'tiktok', $args );
}

/**
 * Flag that the TikTok feed script should be enqueued.
 *
 * Set when a feed is rendered so the frontend conditionally enqueues
 * assets in wp_footer.
 *
 * @since 6.9.0
 * @return void
 */
function esf_tiktok_flag_feed_script() {
    global $esf_tiktok_feed_script_needed;
    $esf_tiktok_feed_script_needed = true;
}

/**
 * Localized strings for the TikTok popup lightbox script.
 *
 * Shared by the public frontend and the admin customize preview.
 *
 * @since 6.9.0
 * @return array<string,mixed>
 */
function esf_tiktok_get_lightbox_localize_data() {
    $admin = ( class_exists( 'ESF_GDPR_Integrations' ) ? ESF_GDPR_Integrations::get_lightbox_admin_localize_data() : array(
        'adminMediaBlockedText' => '',
        'adminsOnlyText'        => __( 'Admins only', 'easy-facebook-likebox' ),
    ) );
    return array_merge( array(
        'viewOnTikTokText' => ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'tt_watch_on_tiktok', 'Watch on TikTok' ) : __( 'Watch on TikTok', 'easy-facebook-likebox' ) ),
        'feedNavHintText'  => ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'tt_feed_nav_hint', 'Scroll, swipe, or press ↑ ↓ for the next video' ) : __( 'Scroll, swipe, or press ↑ ↓ for the next video', 'easy-facebook-likebox' ) ),
        'likesText'        => __( 'Likes', 'easy-facebook-likebox' ),
        'commentsText'     => __( 'Comments', 'easy-facebook-likebox' ),
        'sharesText'       => __( 'Shares', 'easy-facebook-likebox' ),
        'viewsText'        => __( 'Views', 'easy-facebook-likebox' ),
        'numberSuffixes'   => array(
            'k' => __( 'K', 'easy-facebook-likebox' ),
            'm' => __( 'M', 'easy-facebook-likebox' ),
            'b' => __( 'B', 'easy-facebook-likebox' ),
        ),
    ), $admin );
}

/**
 * Whether a feed's customize preview is expected to be a cold (slow) first load.
 *
 * Cold when both the posts cache and local media files are missing.
 *
 * @since 6.9.6
 *
 * @param int $account_id Account ID.
 * @return bool True when neither posts cache nor local media are present.
 */
function esf_tiktok_feed_preview_is_cold(  $account_id  ) {
    $account_id = max( 0, (int) $account_id );
    if ( $account_id <= 0 ) {
        return true;
    }
    $has_posts_cache = false;
    if ( class_exists( 'ESF_TikTok_Cache' ) ) {
        $cache_key = ESF_TikTok_Cache::posts_cache_key( $account_id );
        $cached = ESF_TikTok_Cache::get( $cache_key );
        if ( !is_array( $cached ) && method_exists( 'ESF_TikTok_Cache', 'get_stale' ) ) {
            $cached = ESF_TikTok_Cache::get_stale( $cache_key );
        }
        $has_posts_cache = is_array( $cached ) && isset( $cached['posts'] ) && is_array( $cached['posts'] ) && !empty( $cached['posts'] );
    }
    $has_local_media = false;
    if ( function_exists( 'esf_get_uploads_directory' ) ) {
        $dir = esf_get_uploads_directory( 'tiktok', $account_id );
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
    return !$has_posts_cache && !$has_local_media;
}
