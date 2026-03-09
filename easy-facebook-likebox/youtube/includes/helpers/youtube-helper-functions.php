<?php

/**
 * YouTube Module Helper Functions
 *
 * Global helper functions for the YouTube module.
 * These functions provide a WordPress-like API for common operations.
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
 * Returns the current activation status of the YouTube module.
 *
 * @since 6.7.5
 * @return string Module status ('activated' or 'deactivated').
 */
function esf_get_youtube_status() {
    $fta_settings = get_option( 'fta_settings', array() );
    return ( isset( $fta_settings['plugins']['youtube']['status'] ) ? $fta_settings['plugins']['youtube']['status'] : 'activated' );
}

/**
 * Get YouTube module settings.
 *
 * Retrieves all settings for the YouTube module.
 * Can return specific setting if key is provided.
 *
 * @since 6.7.5
 * @param string|null $key Optional. Specific setting key to retrieve.
 * @return mixed Array of all settings or specific setting value.
 */
function esf_get_youtube_settings(  $key = null  ) {
    $settings = get_option( 'esf_youtube_settings', array() );
    if ( null !== $key && isset( $settings[$key] ) ) {
        return $settings[$key];
    }
    return $settings;
}

/**
 * Update YouTube module settings.
 *
 * Updates specific setting or merges array of settings.
 *
 * @since 6.7.5
 * @param string|array $key Setting key or array of settings.
 * @param mixed        $value Setting value (ignored if $key is array).
 * @return bool True on success, false on failure.
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
 * Check if user has permission to manage YouTube module.
 *
 * Wrapper function for capability check with filter support.
 *
 * @since 6.7.5
 * @return bool True if user can manage, false otherwise.
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
 * Check if the current plan includes YouTube Pro (shared condition for all YouTube Pro features).
 *
 * Returns true when premium code is available and the user has youtube_premium or combo_premium.
 * Use this for Load More, Subscribe button, and any future Pro features so the condition lives in one place.
 *
 * @since 6.7.5
 * @return bool True if YouTube Pro plan is active, false otherwise.
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
 * Apply moderation filter to feed videos.
 *
 * Used by initial render and Load More so hidden videos are excluded consistently.
 * Hook: esf_youtube_feed_videos (future moderation will filter by hidden video_ids).
 *
 * @since 6.7.5
 * @param array $videos  Normalized video items.
 * @param int   $feed_id Feed ID.
 * @return array Filtered videos (same structure).
 */
function esf_youtube_apply_moderation_filter(  $videos, $feed_id  ) {
    if ( !is_array( $videos ) ) {
        return array();
    }
    return apply_filters( 'esf_youtube_feed_videos', $videos, (int) $feed_id );
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
 * Convert hashtags in text to YouTube search links.
 *
 * Turns #tag into clickable links to YouTube search results.
 * Mirrors the behavior of Facebook/Instagram caption hashtag conversion.
 *
 * @since 6.7.5
 * @param string $text Plain or HTML text containing #hashtags.
 * @return string Text with #hashtags wrapped in anchor tags to YouTube search.
 */
function esf_youtube_hashtags_to_links(  $text  ) {
    if ( !is_string( $text ) || '' === trim( $text ) ) {
        return $text;
    }
    return preg_replace_callback( '/(^|\\s)(#[\\w]+)/', function ( $m ) {
        $hash = $m[2];
        $url = 'https://www.youtube.com/results?search_query=' . rawurlencode( $hash );
        return $m[1] . '<a href="' . esc_url( $url ) . '" class="esf-yt-hash" target="_blank" rel="noopener noreferrer">' . esc_html( $hash ) . '</a>';
    }, $text );
}

/**
 * Format date for display.
 *
 * Converts database datetime to WordPress formatted date.
 *
 * @since 6.7.5
 * @param string $datetime Database datetime string.
 * @param string $format Optional. Date format. Default is WordPress date format.
 * @return string Formatted date string.
 */
function esf_youtube_format_date(  $datetime, $format = ''  ) {
    if ( empty( $format ) ) {
        $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
    }
    return mysql2date( $format, $datetime );
}

/**
 * Log YouTube module errors.
 *
 * Logs errors for debugging purposes.
 *
 * @since 6.7.5
 * @param string $message Error message.
 * @param array  $context Additional context data.
 * @return void
 */
function esf_youtube_log_error(  $message, $context = array()  ) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future logging implementation.
    // Intentionally left as a no-op in production to avoid logging
    // sensitive tokens or user data to debug.log. Hook here if you
    // want to route errors to a safe, redacted logging system.
}

/**
 * Get YouTube admin page URL.
 *
 * Returns the URL to the YouTube admin dashboard.
 *
 * @since 6.7.5
 * @param array $args Optional. Additional query args.
 * @return string Admin page URL.
 */
function esf_get_youtube_admin_url(  $args = array()  ) {
    $default_args = array(
        'page' => 'esf-youtube',
    );
    $args = wp_parse_args( $args, $default_args );
    return add_query_arg( $args, admin_url( 'admin.php' ) );
}
