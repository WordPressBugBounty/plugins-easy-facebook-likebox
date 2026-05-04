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
    $settings = get_option( 'esf_twitter_settings', array() );
    if ( null !== $key && isset( $settings[$key] ) ) {
        return $settings[$key];
    }
    return $settings;
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
