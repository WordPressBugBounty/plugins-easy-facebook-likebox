<?php

/**
 * Social Wall helper functions.
 *
 * Free-safe helpers (plan detection). Pro aggregation lives under includes/premium/social-wall/.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Whether Social Wall Pro features are available right now.
 *
 * @since 6.8.2
 * @return bool
 */
function esf_social_wall_can_use_pro() {
    if ( !function_exists( 'efl_fs' ) ) {
        return false;
    }
    $fs = efl_fs();
    if ( !is_object( $fs ) || !method_exists( $fs, 'can_use_premium_code__premium_only' ) ) {
        return false;
    }
    return false;
    return function_exists( 'esf_social_wall_has_combo_plan__premium_only' ) && esf_social_wall_has_combo_plan__premium_only();
}

/**
 * Whether the current user can manage Social Wall (missing-feed recovery UI).
 *
 * @since 6.8.5
 * @return bool
 */
function esf_social_wall_user_can_manage() {
    $capability = apply_filters( 'esf_social_wall_manage_capability', 'manage_options' );
    return current_user_can( $capability );
}

/**
 * Module slugs Social Wall can aggregate when activated.
 *
 * @since 6.8.2
 * @return string[]
 */
function esf_social_wall_supported_modules() {
    $modules = array(
        'facebook',
        'instagram',
        'twitter',
        'youtube',
        'tiktok'
    );
    /**
     * Filter supported Social Wall source modules.
     *
     * @since 6.8.2
     * @param string[] $modules Module slugs.
     */
    $modules = apply_filters( 'esf_social_wall_supported_modules', $modules );
    if ( !is_array( $modules ) ) {
        return array();
    }
    $clean = array();
    foreach ( $modules as $slug ) {
        $slug = sanitize_key( (string) $slug );
        if ( '' !== $slug ) {
            $clean[] = $slug;
        }
    }
    return array_values( array_unique( $clean ) );
}

/**
 * Whether a hub module is activated for Social Wall sources.
 *
 * @since 6.8.2
 * @param string $module Module slug.
 * @return bool
 */
function esf_social_wall_is_module_active(  $module  ) {
    $module = sanitize_key( (string) $module );
    if ( '' === $module ) {
        return false;
    }
    if ( !in_array( $module, esf_social_wall_supported_modules(), true ) ) {
        return false;
    }
    if ( class_exists( 'ESF_Settings' ) ) {
        return 'activated' === ESF_Settings::get_module_status( $module );
    }
    return true;
}

/**
 * Human-readable network label for a module slug.
 *
 * @since 6.8.2
 * @param string $module Module slug.
 * @return string
 */
function esf_social_wall_module_label(  $module  ) {
    $module = sanitize_key( (string) $module );
    $labels = array(
        'facebook'  => __( 'Facebook', 'easy-facebook-likebox' ),
        'instagram' => __( 'Instagram', 'easy-facebook-likebox' ),
        'twitter'   => __( 'X', 'easy-facebook-likebox' ),
        'youtube'   => __( 'YouTube', 'easy-facebook-likebox' ),
        'tiktok'    => __( 'TikTok', 'easy-facebook-likebox' ),
    );
    if ( isset( $labels[$module] ) ) {
        return $labels[$module];
    }
    return ucfirst( $module );
}

/**
 * Network label for Social Wall filter pills and admin Sources UI.
 *
 * Same as {@see esf_social_wall_module_label()} except X is shown as
 * "X/Twitter" so Sources and filter tabs are clearer beside the X glyph.
 * Compact tile badges keep the shorter {@see esf_social_wall_module_label()}.
 *
 * @since 6.8.3
 * @param string $module Module slug.
 * @return string
 */
function esf_social_wall_module_filter_label(  $module  ) {
    $module = sanitize_key( (string) $module );
    if ( 'twitter' === $module ) {
        return __( 'X/Twitter', 'easy-facebook-likebox' );
    }
    return esf_social_wall_module_label( $module );
}

/**
 * Inline SVG glyph for a network badge (matches admin BrandIcon paths).
 *
 * @since 6.8.2
 * @param string $module Module slug.
 * @return string Safe SVG markup, or empty string when unknown.
 */
function esf_social_wall_module_badge_icon(  $module  ) {
    $module = sanitize_key( (string) $module );
    $icons = array(
        'facebook'  => '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M14.5 9V7.5c0-.55.45-1 1-1H17V3h-2.5C12.567 3 11 4.567 11 6.5V9H8v3.5h3V21h3.5v-8.5h2.625L17.75 9H14.5z"/></svg>',
        'instagram' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="4"/><circle cx="17.25" cy="6.75" r="1" fill="currentColor" stroke="none"/></svg>',
        'twitter'   => '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M17.751 3h2.94l-6.42 7.342L21.832 21h-5.918l-4.633-6.061L5.984 21H3.04l6.866-7.852L2.5 3h6.07l4.187 5.534L17.75 3zm-1.033 16.252h1.626L7.358 4.66H5.612l11.106 14.59z"/></svg>',
        'youtube'   => '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="M21.58 7.19a2.5 2.5 0 0 0-1.76-1.77C18.25 5 12 5 12 5s-6.25 0-7.82.42A2.5 2.5 0 0 0 2.42 7.19C2 8.77 2 12 2 12s0 3.23.42 4.81a2.5 2.5 0 0 0 1.76 1.77C5.75 19 12 19 12 19s6.25 0 7.82-.42a2.5 2.5 0 0 0 1.76-1.77C22 15.23 22 12 22 12s0-3.23-.42-4.81zM10 15V9l5.2 3L10 15z"/></svg>',
        'tiktok'    => '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .56.04.82.12V9.01a6.27 6.27 0 0 0-.82-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.54a8.22 8.22 0 0 0 4.78 1.52V6.61a4.85 4.85 0 0 1-1.01.08z"/></svg>',
    );
    return ( isset( $icons[$module] ) ? $icons[$module] : '' );
}

/**
 * Brand / wall header HTML for Social Wall (multi-network chrome).
 *
 * Not a single-account header — title, optional description/CTA, and
 * network icons derived from connected sources. Default OFF via
 * feed.show_wall_header so existing walls are unchanged.
 *
 * Layout matches other modules' account headers: title + description in a
 * main column, CTA on the right of that row, network icons below.
 *
 * @since 6.8.4
 * @param mixed    $feed_settings Feed settings slice (settings.feed).
 * @param string[] $modules       Unique source module slugs.
 * @return string Escaped HTML, or empty when header is off / has no content.
 */
function esf_social_wall_render_wall_header(  $feed_settings, array $modules = array()  ) {
    $feed = ( is_array( $feed_settings ) ? $feed_settings : array() );
    if ( empty( $feed['show_wall_header'] ) ) {
        return '';
    }
    $title = ( isset( $feed['header_title'] ) ? trim( (string) $feed['header_title'] ) : '' );
    $show_desc = !isset( $feed['header_show_description'] ) || !empty( $feed['header_show_description'] );
    $description = ( $show_desc && isset( $feed['header_description'] ) ? trim( (string) $feed['header_description'] ) : '' );
    $show_cta = !empty( $feed['header_show_cta'] );
    $cta_label = ( isset( $feed['header_cta_label'] ) ? trim( (string) $feed['header_cta_label'] ) : '' );
    $cta_url = ( isset( $feed['header_cta_url'] ) ? trim( (string) $feed['header_cta_url'] ) : '' );
    if ( '' === $cta_label ) {
        $cta_label = __( 'Follow us', 'easy-facebook-likebox' );
    }
    $show_icons = !isset( $feed['header_show_network_icons'] ) || !empty( $feed['header_show_network_icons'] );
    $align_raw = ( isset( $feed['header_align'] ) ? sanitize_key( (string) $feed['header_align'] ) : 'left' );
    $align = ( in_array( $align_raw, array('left', 'center', 'right'), true ) ? $align_raw : 'left' );
    $icon_color = ( isset( $feed['header_icon_color'] ) && function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( (string) $feed['header_icon_color'] ) : '' );
    $icon_bg = ( isset( $feed['header_icon_bg_color'] ) && function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( (string) $feed['header_icon_bg_color'] ) : '' );
    $icon_size = ( isset( $feed['header_icon_size'] ) ? (int) $feed['header_icon_size'] : 32 );
    if ( $icon_size < 20 ) {
        $icon_size = 20;
    } elseif ( $icon_size > 56 ) {
        $icon_size = 56;
    }
    $cta_bg = ( isset( $feed['header_cta_bg_color'] ) && function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( (string) $feed['header_cta_bg_color'] ) : '' );
    $cta_text = ( isset( $feed['header_cta_text_color'] ) && function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( (string) $feed['header_cta_text_color'] ) : '' );
    $cta_hover_bg = ( isset( $feed['header_cta_hover_bg_color'] ) && function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( (string) $feed['header_cta_hover_bg_color'] ) : '' );
    $cta_hover_text = ( isset( $feed['header_cta_hover_text_color'] ) && function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( (string) $feed['header_cta_hover_text_color'] ) : '' );
    $icons_html = '';
    if ( $show_icons && !empty( $modules ) ) {
        $parts = array();
        foreach ( $modules as $module ) {
            $module = sanitize_key( (string) $module );
            if ( '' === $module ) {
                continue;
            }
            $icon = esf_social_wall_module_badge_icon( $module );
            if ( '' === $icon ) {
                continue;
            }
            $label = ( function_exists( 'esf_social_wall_module_label' ) ? esf_social_wall_module_label( $module ) : ucfirst( $module ) );
            // Decorative network chips — CTA carries the follow URL (no fake platform home links).
            $parts[] = sprintf(
                '<span class="esf-social-wall-feed__wall-header-network esf-social-wall-feed__wall-header-network--%1$s" aria-label="%2$s">%3$s</span>',
                esc_attr( $module ),
                esc_attr( $label ),
                $icon
            );
        }
        if ( !empty( $parts ) ) {
            $networks_class = 'esf-social-wall-feed__wall-header-networks';
            if ( is_string( $icon_color ) && '' !== $icon_color ) {
                $networks_class .= ' esf-social-wall-feed__wall-header-networks--custom-color';
            }
            $icons_html = '<div class="' . esc_attr( $networks_class ) . '" role="list">' . implode( '', $parts ) . '</div>';
        }
    }
    $cta_html = '';
    if ( $show_cta && '' !== $cta_url ) {
        $cta_html = sprintf( '<a class="esf-social-wall-feed__wall-header-cta" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $cta_url ), esc_html( $cta_label ) );
    }
    if ( '' === $title && '' === $description && '' === $cta_html && '' === $icons_html ) {
        return '';
    }
    $main = '';
    if ( '' !== $title ) {
        $main .= '<h2 class="esf-social-wall-feed__wall-header-title">' . esc_html( $title ) . '</h2>';
    }
    if ( '' !== $description ) {
        $main .= '<p class="esf-social-wall-feed__wall-header-description">' . esc_html( $description ) . '</p>';
    }
    $body = '';
    if ( '' !== $main || '' !== $cta_html ) {
        $body .= '<div class="esf-social-wall-feed__wall-header-top">';
        if ( '' !== $main ) {
            $body .= '<div class="esf-social-wall-feed__wall-header-main">' . $main . '</div>';
        }
        if ( '' !== $cta_html ) {
            $body .= $cta_html;
        }
        $body .= '</div>';
    }
    if ( '' !== $icons_html ) {
        $body .= $icons_html;
    }
    $vars = array();
    if ( is_string( $cta_bg ) && '' !== $cta_bg ) {
        $vars[] = '--esf-sw-wall-header-cta-bg:' . $cta_bg;
    }
    if ( is_string( $cta_text ) && '' !== $cta_text ) {
        $vars[] = '--esf-sw-wall-header-cta-text:' . $cta_text;
    }
    if ( is_string( $cta_hover_bg ) && '' !== $cta_hover_bg ) {
        $vars[] = '--esf-sw-wall-header-cta-hover-bg:' . $cta_hover_bg;
    }
    if ( is_string( $cta_hover_text ) && '' !== $cta_hover_text ) {
        $vars[] = '--esf-sw-wall-header-cta-hover-text:' . $cta_hover_text;
    }
    if ( is_string( $icon_color ) && '' !== $icon_color ) {
        $vars[] = '--esf-sw-wall-header-icon-color:' . $icon_color;
    }
    if ( is_string( $icon_bg ) && '' !== $icon_bg ) {
        $vars[] = '--esf-sw-wall-header-icon-bg:' . $icon_bg;
    }
    if ( 32 !== $icon_size ) {
        $vars[] = '--esf-sw-wall-header-icon-size:' . $icon_size . 'px';
        $vars[] = '--esf-sw-wall-header-icon-svg:' . max( 10, (int) round( $icon_size * 0.4375 ) ) . 'px';
    }
    $style_attr = '';
    if ( !empty( $vars ) ) {
        $style_attr = ' style="' . esc_attr( implode( ';', $vars ) ) . '"';
    }
    return sprintf(
        '<header class="esf-social-wall-feed__wall-header esf-social-wall-feed__wall-header--%1$s"%2$s>%3$s</header>',
        esc_attr( $align ),
        $style_attr,
        $body
    );
}

/**
 * Option key for Social Wall global settings.
 *
 * @since 6.8.2
 * @return string
 */
function esf_social_wall_settings_option_key() {
    return 'esf_social_wall_settings';
}

/**
 * Default Social Wall global settings.
 *
 * @since 6.8.2
 * @return array<string,mixed>
 */
function esf_social_wall_default_settings() {
    return array(
        'default_layout'      => 'grid',
        'default_per_page'    => 9,
        'default_show_filter' => true,
        'default_show_badge'  => true,
        'default_load_more'   => true,
    );
}

/**
 * Get Social Wall global settings (merged with defaults).
 *
 * @since 6.8.2
 * @return array<string,mixed>
 */
function esf_social_wall_get_settings() {
    $stored = get_option( esf_social_wall_settings_option_key(), array() );
    if ( !is_array( $stored ) ) {
        $stored = array();
    }
    return esf_social_wall_sanitize_settings( array_merge( esf_social_wall_default_settings(), $stored ) );
}

/**
 * Sanitize Social Wall global settings.
 *
 * @since 6.8.2
 * @param mixed $raw Raw settings.
 * @return array<string,mixed>
 */
function esf_social_wall_sanitize_settings(  $raw  ) {
    $defaults = esf_social_wall_default_settings();
    if ( !is_array( $raw ) ) {
        return $defaults;
    }
    $layout = ( isset( $raw['default_layout'] ) ? sanitize_key( (string) $raw['default_layout'] ) : $defaults['default_layout'] );
    $allowed_layouts = array(
        'grid',
        'row',
        'masonry',
        'carousel',
        'half_width',
        'full_width'
    );
    if ( !in_array( $layout, $allowed_layouts, true ) ) {
        $layout = $defaults['default_layout'];
    }
    $per_page = ( isset( $raw['default_per_page'] ) ? absint( $raw['default_per_page'] ) : (int) $defaults['default_per_page'] );
    $per_page = max( 1, min( 50, $per_page ) );
    // Missing keys keep product defaults (filter/badge/load more ON for new walls).
    // Explicit false/empty from Settings still saves off.
    $bool_or_default = static function ( $key ) use($raw, $defaults) {
        if ( !array_key_exists( $key, $raw ) ) {
            return !empty( $defaults[$key] );
        }
        return !empty( $raw[$key] );
    };
    return array(
        'default_layout'      => $layout,
        'default_per_page'    => $per_page,
        'default_show_filter' => $bool_or_default( 'default_show_filter' ),
        'default_show_badge'  => $bool_or_default( 'default_show_badge' ),
        'default_load_more'   => $bool_or_default( 'default_load_more' ),
    );
}

/**
 * Update Social Wall global settings.
 *
 * @since 6.8.2
 * @param mixed $raw Raw settings.
 * @return array<string,mixed>
 */
function esf_social_wall_update_settings(  $raw  ) {
    $clean = esf_social_wall_sanitize_settings( $raw );
    update_option( esf_social_wall_settings_option_key(), $clean, false );
    return $clean;
}

/**
 * Clear cached posts for every source feed attached to a Social Wall.
 *
 * Delegates to each module's feed-cache flusher so the next wall render
 * can pull fresh posts. Returns the number of source caches cleared.
 *
 * @since 6.8.2
 * @param int $wall_id Wall ID.
 * @return int|false Number cleared, or false when the wall is missing.
 */
function esf_social_wall_clear_wall_cache(  $wall_id  ) {
    $wall_id = absint( $wall_id );
    if ( $wall_id <= 0 || !class_exists( 'ESF_Social_Wall_Feed_Repository' ) ) {
        return false;
    }
    $wall = ESF_Social_Wall_Feed_Repository::get_instance()->get_by_id( $wall_id );
    if ( !$wall || !is_object( $wall ) ) {
        return false;
    }
    $settings = ( isset( $wall->settings ) && is_array( $wall->settings ) ? $wall->settings : array() );
    $sources = ( isset( $settings['sources'] ) && is_array( $settings['sources'] ) ? $settings['sources'] : array() );
    $cleared = 0;
    foreach ( $sources as $source ) {
        if ( !is_array( $source ) ) {
            continue;
        }
        $module = ( isset( $source['module'] ) ? sanitize_key( (string) $source['module'] ) : '' );
        $feed_id = ( isset( $source['feed_id'] ) ? absint( $source['feed_id'] ) : 0 );
        if ( '' === $module || $feed_id <= 0 ) {
            continue;
        }
        $deleted = esf_social_wall_clear_module_feed_cache( $module, $feed_id );
        if ( false !== $deleted ) {
            ++$cleared;
        }
    }
    /**
     * Fires after a Social Wall's source caches are cleared.
     *
     * @since 6.8.2
     * @param int $wall_id Wall ID.
     * @param int $cleared Number of source caches cleared.
     */
    do_action( 'esf_social_wall_cleared_cache', $wall_id, $cleared );
    return $cleared;
}

/**
 * Whether a module feed should be hidden from Social Wall Sources.
 *
 * Hides marketing-demo feeds (when the demo registry is available) without
 * affecting real connected accounts. Adapters may add module-specific checks
 * (e.g. orphaned TikTok account rows).
 *
 * @since 6.8.2
 * @param int    $feed_id Feed ID.
 * @param string $module  Module slug.
 * @param object|null $feed Optional feed row for richer checks.
 * @return bool True when the feed must not appear in Sources.
 */
function esf_social_wall_is_excluded_source_feed(  $feed_id, $module, $feed = null  ) {
    $feed_id = absint( $feed_id );
    $module = sanitize_key( (string) $module );
    if ( $feed_id <= 0 || '' === $module ) {
        return true;
    }
    $excluded = false;
    if ( class_exists( 'ESF_Demo_Registry' ) && ESF_Demo_Registry::is_demo_feed( $feed_id, $module ) ) {
        $excluded = true;
    }
    /**
     * Filter whether a module feed is excluded from Social Wall Sources.
     *
     * @since 6.8.2
     * @param bool        $excluded Whether to exclude.
     * @param int         $feed_id  Feed ID.
     * @param string      $module   Module slug.
     * @param object|null $feed     Feed row when available.
     */
    return (bool) apply_filters(
        'esf_social_wall_is_excluded_source_feed',
        $excluded,
        $feed_id,
        $module,
        $feed
    );
}

/**
 * Clear one module feed cache by module slug + feed ID.
 *
 * @since 6.8.2
 * @param string $module  Module slug.
 * @param int    $feed_id Feed ID.
 * @return int|false Rows deleted, or false when no flusher is available.
 */
function esf_social_wall_clear_module_feed_cache(  $module, $feed_id  ) {
    $module = sanitize_key( (string) $module );
    $feed_id = absint( $feed_id );
    if ( '' === $module || $feed_id <= 0 ) {
        return false;
    }
    $flushers = array(
        'facebook'  => array('ESF_Facebook_Cache', 'delete_by_feed_id'),
        'instagram' => array('ESF_Instagram_Cache', 'flush_feed_cache'),
        'twitter'   => array('ESF_Twitter_Cache', 'flush_feed_cache'),
        'youtube'   => array('ESF_YouTube_Cache', 'flush_feed_cache'),
        'tiktok'    => array('ESF_TikTok_Cache', 'flush_feed_cache'),
    );
    if ( !isset( $flushers[$module] ) ) {
        return false;
    }
    $callback = $flushers[$module];
    // Module caches are optional at runtime; Social Wall PHPStan does not load them.
    if ( !is_callable( $callback ) ) {
        return false;
    }
    return (int) call_user_func( $callback, $feed_id );
}
