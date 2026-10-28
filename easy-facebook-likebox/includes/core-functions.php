<?php

/**
 * Define all the global functions for ESF modules
 */
/**
 * Shared Pro promo offer (discount + coupon).
 *
 * Change values here only — upgrade banners, REST payloads, and React CTAs
 * should read through this helper (or {@see ESF_Admin::esf_upgrade_banner()}).
 *
 * @since 6.9.0
 * @return array{discount:string,coupon:string}
 */
if ( !function_exists( 'esf_get_pro_promo_offer' ) ) {
    function esf_get_pro_promo_offer() {
        return array(
            'discount' => '17%',
            'coupon'   => 'ESPF17',
        );
    }

}
/**
 * Freemius plan slug currently active for this install.
 *
 * Prefers Combo when present. Returns empty string for free / no premium.
 *
 * @since 6.9.2
 * @return string Plan slug (e.g. `facebook_premium`, `combo_premium`) or ''.
 */
if ( !function_exists( 'esf_get_active_plan_slug' ) ) {
    function esf_get_active_plan_slug() {
        if ( !function_exists( 'efl_fs' ) ) {
            return '';
        }
        $fs = efl_fs();
        if ( !is_object( $fs ) || !method_exists( $fs, 'can_use_premium_code__premium_only' ) ) {
            return '';
        }
        return '';
        if ( !method_exists( $fs, 'is_plan' ) ) {
            return '';
        }
        // Combo first so multi-module installs resolve to one clear slug.
        $candidates = array(
            'combo_premium',
            'facebook_premium',
            'instagram_premium',
            'youtube_premium',
            'x_premium',
            'tiktok_premium'
        );
        foreach ( $candidates as $slug ) {
            if ( $fs->is_plan( $slug, true ) ) {
                return $slug;
            }
        }
        return '';
    }

}
/**
 * Whether the user needs Combo to unlock Pro for a given module.
 *
 * True when they already own a *different* single-module plan (not Combo)
 * and do not have Pro for `$module`.
 *
 * @since 6.9.2
 * @param string $module Module slug (`facebook`, `instagram`, …).
 * @return bool
 */
if ( !function_exists( 'esf_needs_combo_upgrade_for_module' ) ) {
    function esf_needs_combo_upgrade_for_module(  $module  ) {
        $module = sanitize_key( (string) $module );
        $owned = esf_get_active_plan_slug();
        if ( '' === $owned || 'combo_premium' === $owned ) {
            return false;
        }
        $module_plan_map = array(
            'facebook'  => 'facebook_premium',
            'instagram' => 'instagram_premium',
            'youtube'   => 'youtube_premium',
            'twitter'   => 'x_premium',
            'tiktok'    => 'tiktok_premium',
        );
        if ( !isset( $module_plan_map[$module] ) ) {
            return false;
        }
        // Already on this module's single plan.
        if ( $owned === $module_plan_map[$module] ) {
            return false;
        }
        $has_helpers = array(
            'facebook'  => 'esf_facebook_has_facebook_plan',
            'instagram' => 'esf_instagram_has_instagram_plan',
            'youtube'   => 'esf_youtube_has_youtube_plan',
            'twitter'   => 'esf_twitter_has_twitter_plan',
            'tiktok'    => 'esf_tiktok_has_tiktok_plan',
        );
        $helper = ( isset( $has_helpers[$module] ) ? $has_helpers[$module] : '' );
        if ( '' !== $helper && function_exists( $helper ) && call_user_func( $helper ) ) {
            return false;
        }
        return true;
    }

}
/**
 * Canonical Pro upgrade / pricing URL for all CTAs.
 *
 * Pass a module context so UTM `utm_content` tracks where the CTA came from.
 * Supported contexts: `instagram`, `facebook`, `youtube`, `twitter`, `tiktok`, `addons`, `general`.
 * When a paid plan is active, appends `current_plan` so the pricing site can
 * emphasize Combo for multi-module unlocks.
 *
 * @since 6.9.1
 * @param string $context Module or surface context (default `general`).
 * @return string Absolute upgrade URL with UTM params.
 */
if ( !function_exists( 'esf_get_upgrade_url' ) ) {
    function esf_get_upgrade_url(  $context = 'general'  ) {
        $allowed = array(
            'instagram',
            'facebook',
            'youtube',
            'twitter',
            'tiktok',
            'addons',
            'general'
        );
        $context = sanitize_key( (string) $context );
        if ( !in_array( $context, $allowed, true ) ) {
            $context = 'general';
        }
        $args = array(
            'utm_source'  => 'easy_social_feed',
            'utm_medium'  => 'wordpress_plugin_install',
            'utm_content' => $context,
        );
        $current_plan = ( function_exists( 'esf_get_active_plan_slug' ) ? esf_get_active_plan_slug() : '' );
        if ( is_string( $current_plan ) && '' !== $current_plan ) {
            $args['current_plan'] = sanitize_key( $current_plan );
        }
        return add_query_arg( $args, 'https://easysocialfeed.com/pricing/' );
    }

}
// Point Freemius Upgrade/pricing submenu + SDK upgrade links at the general URL.
if ( function_exists( 'efl_fs' ) && !function_exists( 'esf_filter_freemius_pricing_url' ) ) {
    /**
     * Freemius `pricing_url` callback — keeps the Upgrade submenu on the site pricing page.
     *
     * @since 6.9.1
     * @return string
     */
    function esf_filter_freemius_pricing_url() {
        return esf_get_upgrade_url( 'general' );
    }

    efl_fs()->add_filter( 'pricing_url', 'esf_filter_freemius_pricing_url' );
}
/**
 * Pricing page URL for the Add-Ons section (`#add-ons`).
 *
 * @deprecated 6.10.0 Multifeed is now included in Pro; addons tab removed.
 * @since 6.9.2
 * @return string
 */
if ( !function_exists( 'esf_get_addons_url' ) ) {
    function esf_get_addons_url() {
        // Redirect to Pro pricing since Multifeed (the only addon) is now native.
        return ( function_exists( 'esf_get_upgrade_url' ) ? esf_get_upgrade_url( 'combo_premium' ) : 'https://easysocialfeed.com/pricing/' );
    }

}
/**
 * Freemius Add-Ons admin page slug (menu slug + `-addons`).
 *
 * @deprecated 6.10.0 Addons tab removed; Multifeed is now native in Pro.
 * @since 6.9.2
 * @return string
 */
if ( !function_exists( 'esf_get_freemius_addons_page_slug' ) ) {
    function esf_get_freemius_addons_page_slug() {
        return 'easy-social-feed-addons';
    }

}
/**
 * Hide Freemius’s built-in Add-Ons page from the admin menu.
 *
 * Multifeed (the only addon) is now included in Pro plans as of 6.10.0.
 * The external "Add-Ons" menu link has been removed; Freemius's built-in
 * page remains hidden for consistency.
 *
 * @deprecated 6.10.0 Addons tab removed.
 * @since 6.9.2
 *
 * @param bool   $is_visible Whether the submenu item is visible.
 * @param string $id         Submenu item id.
 * @return bool
 */
if ( !function_exists( 'esf_hide_freemius_addons_submenu' ) ) {
    function esf_hide_freemius_addons_submenu(  $is_visible, $id  ) {
        if ( 'addons' === $id ) {
            return false;
        }
        return $is_visible;
    }

}
/**
 * Register Add-Ons as an external Freemius submenu link that opens in a new tab.
 *
 * @deprecated 6.10.0 Addons menu removed; Multifeed is now native in Pro.
 * @since 6.9.2
 * @return void
 */
if ( !function_exists( 'esf_register_freemius_addons_external_link' ) ) {
    function esf_register_freemius_addons_external_link() {
        // No longer register the addons menu item.
        // Multifeed (the only addon) is now included in Pro plans.
        return;
    }

}
if ( function_exists( 'efl_fs' ) ) {
    $esf_fs = efl_fs();
    if ( is_object( $esf_fs ) && method_exists( $esf_fs, 'add_filter' ) ) {
        $esf_fs->add_filter(
            'is_submenu_visible',
            'esf_hide_freemius_addons_submenu',
            10,
            2
        );
    }
}
if ( function_exists( 'add_action' ) ) {
    // Before Freemius `_prepare_admin_menu` (priority 999999999) so the link is embedded with other FS items.
    add_action( 'admin_menu', 'esf_register_freemius_addons_external_link', 1 );
}
/**
 * Redirect direct hits to the Freemius Add-Ons admin page to pricing.
 *
 * @deprecated 6.10.0 Addons menu removed; redirects to Pro pricing.
 * @since 6.9.2
 * @return void
 */
if ( !function_exists( 'esf_redirect_freemius_addons_page' ) ) {
    function esf_redirect_freemius_addons_page() {
        if ( !is_admin() ) {
            return;
        }
        if ( empty( $_GET['page'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $page = sanitize_key( wp_unslash( $_GET['page'] ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( esf_get_freemius_addons_page_slug() !== $page ) {
            return;
        }
        if ( !current_user_can( 'manage_options' ) ) {
            return;
        }
        // Redirect to Pro pricing (Multifeed is now native).
        $redirect_url = ( function_exists( 'esf_get_upgrade_url' ) ? esf_get_upgrade_url( 'combo_premium' ) : 'https://easysocialfeed.com/pricing/' );
        wp_redirect( $redirect_url );
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    if ( function_exists( 'add_action' ) ) {
        add_action( 'admin_init', 'esf_redirect_freemius_addons_page' );
    }
}
/**
 * Check if elementor is active and in preview mode
 *
 * @since 6.3.0
 *
 * @return bool
 */
if ( !function_exists( 'esf_is_elementor_preview' ) ) {
    function esf_is_elementor_preview() {
        if ( class_exists( '\\Elementor\\Plugin' ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
            return true;
        } else {
            return false;
        }
    }

}
/**
 * Convert caption links to actual links
 *
 * @since 6.3.2
 *
 * @return $text
 */
if ( !function_exists( 'esf_convert_to_hyperlinks' ) ) {
    function esf_convert_to_hyperlinks(  $value, $protocols = array('http', 'mail', 'https'), array $attributes = array()  ) {
        // Link attributes
        $attr = '';
        foreach ( $attributes as $key => $val ) {
            $attr .= ' ' . $key . '="' . htmlentities( $val ) . '"';
        }
        $links = array();
        // Extract existing links and tags
        $value = preg_replace_callback( '~(<a .*?>.*?</a>|<.*?>)~i', function ( $match ) use(&$links) {
            return '<' . array_push( $links, $match[1] ) . '>';
        }, $value );
        // Extract text links for each protocol
        foreach ( (array) $protocols as $protocol ) {
            switch ( $protocol ) {
                case 'http':
                case 'https':
                    $value = preg_replace_callback( '~(?:(https?)://([^\\s<]+)|(www\\.[^\\s<]+?\\.[^\\s<]+))(?<![\\.,:])~i', function ( $match ) use($protocol, &$links, $attr) {
                        if ( $match[1] ) {
                            $protocol = $match[1];
                        }
                        $link = ( $match[2] ?: $match[3] );
                        return '<' . array_push( $links, "<a {$attr} href=\"{$protocol}://{$link}\">{$link}</a>" ) . '>';
                    }, $value );
                    break;
                case 'mail':
                    $value = preg_replace_callback( '~([^\\s<]+?@[^\\s<]+?\\.[^\\s<]+)(?<![\\.,:])~', function ( $match ) use(&$links, $attr) {
                        return '<' . array_push( $links, "<a {$attr} href=\"mailto:{$match[1]}\">{$match[1]}</a>" ) . '>';
                    }, $value );
                    break;
                case 'twitter':
                    $value = preg_replace_callback( '~(?<!\\w)[@#](\\w++)~', function ( $match ) use(&$links, $attr) {
                        return '<' . array_push( $links, "<a {$attr} href=\"https://twitter.com/" . (( $match[0][0] == '@' ? '' : 'search/%23' )) . $match[1] . "\">{$match[0]}</a>" ) . '>';
                    }, $value );
                    break;
                default:
                    $value = preg_replace_callback( '~' . preg_quote( $protocol, '~' ) . '://([^\\s<]+?)(?<![\\.,:])~i', function ( $match ) use($protocol, &$links, $attr) {
                        return '<' . array_push( $links, "<a {$attr} href=\"{$protocol}://{$match[1]}\">{$match[1]}</a>" ) . '>';
                    }, $value );
                    break;
            }
        }
        // Insert all link
        return preg_replace_callback( '/<(\\d+)>/', function ( $match ) use(&$links) {
            return $links[$match[1] - 1];
        }, $value );
    }

}
/**
 * Convert hashtags in text to platform-specific links.
 *
 * Shared by Instagram, YouTube, Facebook, X/Twitter, and other modules.
 * Pass a known platform slug or supply a custom `url_template` in $args.
 *
 * @since 6.9.0
 *
 * @param string               $text     Plain or partially HTML text containing #hashtags.
 * @param string               $platform Platform slug: instagram, youtube, facebook, twitter (x).
 * @param array<string, mixed> $args     {
 *     @type string $url_template Custom URL with `{tag}` (no #) and/or `{hash}` (#included) placeholders.
 *     @type string $class        Optional CSS class on the anchor element.
 *     @type bool   $new_tab      Open links in a new tab. Default true.
 *     @type string $rel          rel attribute when $new_tab is true. Default `noopener noreferrer`.
 * }
 * @return string Text with hashtags wrapped in anchor tags.
 */
if ( !function_exists( 'esf_hashtags_to_links' ) ) {
    function esf_hashtags_to_links(  $text, $platform = 'instagram', array $args = array()  ) {
        if ( !is_string( $text ) || '' === trim( $text ) ) {
            return $text;
        }
        $platform = ( function_exists( 'sanitize_key' ) ? sanitize_key( (string) $platform ) : strtolower( (string) $platform ) );
        if ( 'x' === $platform ) {
            $platform = 'twitter';
        }
        $defaults = array(
            'url_template' => '',
            'class'        => '',
            'new_tab'      => true,
            'rel'          => 'noopener noreferrer',
        );
        $args = ( function_exists( 'wp_parse_args' ) ? wp_parse_args( $args, $defaults ) : array_merge( $defaults, $args ) );
        $templates = array(
            'instagram' => 'https://www.instagram.com/explore/tags/{tag}',
            'youtube'   => 'https://www.youtube.com/results?search_query={hash}',
            'facebook'  => 'https://www.facebook.com/hashtag/{tag}',
            'twitter'   => 'https://x.com/hashtag/{tag}',
            'tiktok'    => 'https://www.tiktok.com/tag/{tag}',
        );
        $template = ( '' !== (string) $args['url_template'] ? (string) $args['url_template'] : $templates[$platform] ?? '' );
        if ( '' === $template ) {
            return $text;
        }
        $class_attr = ( '' !== (string) $args['class'] ? ' class="' . esc_attr( (string) $args['class'] ) . '"' : '' );
        $target_attr = ( !empty( $args['new_tab'] ) ? ' target="_blank"' : '' );
        $rel_attr = ( !empty( $args['new_tab'] ) && '' !== (string) $args['rel'] ? ' rel="' . esc_attr( (string) $args['rel'] ) . '"' : '' );
        return preg_replace_callback( '/(?<!&)#+([a-zA-Z0-9_]+)/', static function ( $matches ) use(
            $template,
            $class_attr,
            $target_attr,
            $rel_attr
        ) {
            $tag = $matches[1];
            $full = $matches[0];
            $url = str_replace( array('{tag}', '{hash}'), array(rawurlencode( $tag ), rawurlencode( '#' . $tag )), $template );
            return '<a href="' . esc_url( $url ) . '"' . $class_attr . $target_attr . $rel_attr . '>' . esc_html( $full ) . '</a>';
        }, $text );
    }

}
/**
 * Convert @handles in text into profile links for a social platform.
 *
 * Skips email-style `user@domain` and `@` already inside a path (`youtube.com/@x`).
 *
 * @since 6.16.0
 *
 * @param string $text     Text that may contain @handles.
 * @param string $platform Platform slug (instagram, youtube, twitter, tiktok, facebook).
 * @param array  $args     {
 *     @type string $url_template Override URL with `{handle}` placeholder.
 *     @type string $class        Optional CSS class on the anchor element.
 *     @type bool   $new_tab      Open links in a new tab. Default true.
 *     @type string $rel          rel attribute when $new_tab is true. Default `noopener noreferrer`.
 * }
 * @return string Text with mentions wrapped in anchor tags.
 */
if ( !function_exists( 'esf_mentions_to_links' ) ) {
    function esf_mentions_to_links(  $text, $platform = 'instagram', array $args = array()  ) {
        if ( !is_string( $text ) || '' === trim( $text ) ) {
            return $text;
        }
        $platform = ( function_exists( 'sanitize_key' ) ? sanitize_key( (string) $platform ) : strtolower( (string) $platform ) );
        if ( 'x' === $platform ) {
            $platform = 'twitter';
        }
        $defaults = array(
            'url_template' => '',
            'class'        => '',
            'new_tab'      => true,
            'rel'          => 'noopener noreferrer',
        );
        $args = ( function_exists( 'wp_parse_args' ) ? wp_parse_args( $args, $defaults ) : array_merge( $defaults, $args ) );
        $templates = array(
            'instagram' => 'https://www.instagram.com/{handle}/',
            'youtube'   => 'https://www.youtube.com/@{handle}',
            'twitter'   => 'https://x.com/{handle}',
            'tiktok'    => 'https://www.tiktok.com/@{handle}',
            'facebook'  => 'https://www.facebook.com/{handle}',
        );
        $template = ( '' !== (string) $args['url_template'] ? (string) $args['url_template'] : $templates[$platform] ?? '' );
        if ( '' === $template ) {
            return $text;
        }
        $class_attr = ( '' !== (string) $args['class'] ? ' class="' . esc_attr( (string) $args['class'] ) . '"' : '' );
        $target_attr = ( !empty( $args['new_tab'] ) ? ' target="_blank"' : '' );
        $rel_attr = ( !empty( $args['new_tab'] ) && '' !== (string) $args['rel'] ? ' rel="' . esc_attr( (string) $args['rel'] ) . '"' : '' );
        return preg_replace_callback( '/(?<![A-Za-z0-9._\\/])@([A-Za-z0-9._-]{1,30})/', static function ( $matches ) use(
            $template,
            $class_attr,
            $target_attr,
            $rel_attr
        ) {
            $raw = $matches[1];
            $handle = rtrim( $raw, '.' );
            if ( '' === $handle ) {
                return $matches[0];
            }
            $suffix = ( strlen( $raw ) > strlen( $handle ) ? substr( $raw, strlen( $handle ) ) : '' );
            $url = str_replace( '{handle}', rawurlencode( $handle ), $template );
            return '<a href="' . esc_url( $url ) . '"' . $class_attr . $target_attr . $rel_attr . '>@' . esc_html( $handle ) . '</a>' . $suffix;
        }, $text );
    }

}
/**
 * Format social caption/description text into safe HTML with URLs, hashtags, and mentions linked.
 *
 * @since 6.9.0
 *
 * @param string               $text     Raw caption text.
 * @param string               $platform Platform slug passed to {@see esf_hashtags_to_links()}.
 * @param array<string, mixed> $args     {
 *     @type array  $protocols       Protocols for {@see esf_convert_to_hyperlinks()}. Default http, https, mail.
 *     @type array  $link_attributes Anchor attributes for auto-linked URLs.
 *     @type array  $hashtag_args    Extra args for {@see esf_hashtags_to_links()}.
 *     @type array  $mention_args    Extra args for {@see esf_mentions_to_links()}.
 *     @type bool   $line_breaks     Run nl2br(). Default true.
 *     @type bool   $paragraphs      Run wpautop(). Default true.
 * }
 * @return string Sanitized HTML.
 */
if ( !function_exists( 'esf_format_caption_html' ) ) {
    function esf_format_caption_html(  $text, $platform = 'instagram', array $args = array()  ) {
        $text = ( is_string( $text ) ? $text : '' );
        if ( '' === $text ) {
            return '';
        }
        // Graph/API captions can arrive with pre-encoded entities (for example `&#039;`).
        // Decode first so we do not render literal entities after escaping/linking.
        if ( function_exists( 'wp_specialchars_decode' ) ) {
            $text = wp_specialchars_decode( $text, ENT_QUOTES );
        }
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $defaults = array(
            'protocols'       => array('http', 'https', 'mail'),
            'link_attributes' => array(
                'target' => '_blank',
                'rel'    => 'noopener noreferrer',
            ),
            'hashtag_args'    => array(),
            'mention_args'    => array(),
            'line_breaks'     => true,
            'paragraphs'      => true,
        );
        $args = ( function_exists( 'wp_parse_args' ) ? wp_parse_args( $args, $defaults ) : array_merge( $defaults, $args ) );
        $html = esc_html( $text );
        if ( function_exists( 'esf_convert_to_hyperlinks' ) ) {
            $html = esf_convert_to_hyperlinks( $html, $args['protocols'], $args['link_attributes'] );
        } else {
            $html = make_clickable( $html );
        }
        $html = esf_hashtags_to_links( $html, $platform, $args['hashtag_args'] );
        if ( function_exists( 'esf_mentions_to_links' ) ) {
            $html = esf_mentions_to_links( $html, $platform, $args['mention_args'] );
        }
        if ( !empty( $args['line_breaks'] ) ) {
            $html = nl2br( $html );
        }
        if ( !empty( $args['paragraphs'] ) ) {
            $html = wpautop( $html );
        }
        return $html;
    }

}
if ( !function_exists( 'jws_fetchUrl' ) ) {
    //Get JSON object of feed data
    function jws_fetchUrl(  $url  ) {
        $args = array(
            'timeout'   => 150,
            'sslverify' => false,
        );
        $feedData = wp_remote_get( $url, $args );
        if ( $feedData && !is_wp_error( $feedData ) ) {
            return $feedData['body'];
        } else {
            return $feedData;
        }
    }

}
if ( !function_exists( 'esf_module_uses_account_media_folders' ) ) {
    /**
     * Whether a module stores cached media under per-account subfolders.
     *
     * @since 6.9.0
     *
     * @param string $module Module slug.
     * @return bool
     */
    function esf_module_uses_account_media_folders(  $module  ) {
        $module = sanitize_key( (string) $module );
        $modules = apply_filters( 'esf_local_media_account_folder_modules', array(
            'facebook',
            'instagram',
            'youtube',
            'twitter',
            'tiktok'
        ) );
        return is_array( $modules ) && in_array( $module, array_map( 'sanitize_key', $modules ), true );
    }

}
if ( !function_exists( 'esf_normalize_local_media_account_id' ) ) {
    /**
     * Normalize a WordPress DB account id for media folder paths.
     *
     * @since 6.9.0
     *
     * @param mixed $account_id Account row id.
     * @return int Positive id, or 0 when not usable.
     */
    function esf_normalize_local_media_account_id(  $account_id  ) {
        $account_id = (int) $account_id;
        return ( $account_id > 0 ? $account_id : 0 );
    }

}
if ( !function_exists( 'esf_is_hashtag_media_key' ) ) {
    /**
     * Check if a cache key is for hashtag media.
     *
     * Hashtag media keys use the "ht_" prefix for cleaner filenames.
     *
     * @since 6.9.0
     *
     * @param string $cache_key Media cache key.
     * @return bool
     */
    function esf_is_hashtag_media_key(  $cache_key  ) {
        $cache_key = (string) $cache_key;
        return 0 === strpos( $cache_key, 'ht_' );
    }

}
if ( !function_exists( 'esf_extract_hashtag_from_key' ) ) {
    /**
     * Extract the hashtag folder name from a hashtag media cache key.
     *
     * Format: ht_{folder}_{id} or ht_{folder}_thumb_{id}
     * Example: ht_cats_top_18600134521016012 => cats_top
     *
     * @since 6.9.0
     *
     * @param string $cache_key Hashtag media cache key.
     * @return string Folder name (tag_type), or empty if not a valid hashtag key.
     */
    function esf_extract_hashtag_from_key(  $cache_key  ) {
        $cache_key = (string) $cache_key;
        if ( !esf_is_hashtag_media_key( $cache_key ) ) {
            return '';
        }
        $without_prefix = substr( $cache_key, strlen( 'ht_' ) );
        $parts = explode( '_', $without_prefix, 3 );
        if ( count( $parts ) < 2 ) {
            return '';
        }
        return $parts[0] . '_' . $parts[1];
    }

}
if ( !function_exists( 'esf_get_uploads_directory' ) ) {
    /**
     * Return the module uploads directory (optionally scoped to one account or hashtag).
     *
     * Account-based: uploads/esf-{module}/{account_id}/
     * Hashtag-based: uploads/esf-{module}/hashtags/{normalized_tag}/
     * Legacy/root:   uploads/esf-{module}/
     *
     * @since 6.4.4
     *
     * @param string $module     Module slug.
     * @param int    $account_id Optional internal account id (DB primary key).
     * @param string $hashtag    Optional normalized hashtag for hashtag-specific folders.
     * @return string Absolute directory path without trailing slash.
     */
    function esf_get_uploads_directory(  $module = 'facebook', $account_id = 0, $hashtag = ''  ) {
        $upload_dir = wp_upload_dir();
        $base = $upload_dir['basedir'] . '/esf-' . sanitize_key( (string) $module );
        $hashtag = sanitize_key( (string) $hashtag );
        if ( '' !== $hashtag && 'instagram' === $module ) {
            return $base . '/hashtags/' . $hashtag;
        }
        $account_id = esf_normalize_local_media_account_id( $account_id );
        if ( $account_id > 0 && esf_module_uses_account_media_folders( $module ) ) {
            return $base . '/' . $account_id;
        }
        return $base;
    }

}
if ( !function_exists( 'esf_local_media_resolve_account_ids' ) ) {
    /**
     * Account ids to check when resolving an existing local file (newest layout first).
     *
     * @since 6.9.0
     *
     * @param string $module     Module slug.
     * @param int    $account_id Preferred account id.
     * @return int[] Ordered account ids (specific account, then 0 for legacy root).
     */
    function esf_local_media_resolve_account_ids(  $module, $account_id = 0  ) {
        $account_id = esf_normalize_local_media_account_id( $account_id );
        $ids = array();
        if ( $account_id > 0 && esf_module_uses_account_media_folders( $module ) ) {
            $ids[] = $account_id;
        }
        $ids[] = 0;
        return $ids;
    }

}
if ( !function_exists( 'esf_sanitize_local_media_id' ) ) {
    /**
     * Sanitize a media cache key for safe use in filenames.
     *
     * @since 6.9.0
     *
     * @param mixed $id Raw media identifier.
     * @return string Sanitized id, or empty when invalid.
     */
    function esf_sanitize_local_media_id(  $id  ) {
        $id = preg_replace( '/[^a-zA-Z0-9_\\-]/', '', (string) $id );
        return ( is_string( $id ) ? $id : '' );
    }

}
if ( !function_exists( 'esf_local_media_basename' ) ) {
    /**
     * Build the on-disk filename for a cached media item.
     *
     * Full-size files use `{id}.jpg`. Sized variants use `{id}_w{width}.jpg`.
     *
     * @since 6.9.0
     *
     * @param mixed $id        Media identifier.
     * @param int   $max_width Optional max width for a resized variant (0 = full).
     * @return string Basename including extension, or empty when id is invalid.
     */
    function esf_local_media_basename(  $id, $max_width = 0  ) {
        $id = esf_sanitize_local_media_id( $id );
        if ( '' === $id ) {
            return '';
        }
        $max_width = (int) $max_width;
        if ( $max_width > 0 ) {
            return $id . '_w' . $max_width . '.jpg';
        }
        return $id . '.jpg';
    }

}
if ( !function_exists( 'esf_local_media_file_path' ) ) {
    /**
     * Absolute filesystem path for a cached media file.
     *
     * @since 6.9.0
     *
     * @param mixed  $id        Media identifier.
     * @param string $module    Module slug (facebook, instagram, twitter, youtube).
     * @param int    $max_width Optional max width variant (0 = full size).
     * @param int    $account_id Optional account id for account-scoped files.
     * @return string
     */
    function esf_local_media_file_path(
        $id,
        $module = 'facebook',
        $max_width = 0,
        $account_id = 0
    ) {
        $basename = esf_local_media_basename( $id, $max_width );
        if ( '' === $basename ) {
            return '';
        }
        $hashtag = '';
        if ( function_exists( 'esf_is_hashtag_media_key' ) && function_exists( 'esf_extract_hashtag_from_key' ) ) {
            if ( esf_is_hashtag_media_key( $id ) ) {
                $hashtag = esf_extract_hashtag_from_key( $id );
            }
        }
        $account_id = esf_normalize_local_media_account_id( $account_id );
        $directory = esf_get_uploads_directory( $module, $account_id, $hashtag );
        return $directory . '/' . $basename;
    }

}
if ( !function_exists( 'esf_local_media_public_url' ) ) {
    /**
     * Build the public URL for a file inside a module/account/hashtag uploads directory.
     *
     * @since 6.9.0
     *
     * @param string $module     Module slug.
     * @param int    $account_id Account id (0 = module root).
     * @param string $basename   File basename.
     * @param string $hashtag    Optional normalized hashtag for hashtag folders.
     * @return string
     */
    function esf_local_media_public_url(
        $module,
        $account_id,
        $basename,
        $hashtag = ''
    ) {
        $upload_dir = wp_upload_dir();
        $baseurl = untrailingslashit( $upload_dir['baseurl'] );
        $module = sanitize_key( (string) $module );
        $path = '/esf-' . $module . '/';
        $hashtag = sanitize_key( (string) $hashtag );
        if ( '' !== $hashtag && 'instagram' === $module ) {
            $path .= 'hashtags/' . $hashtag . '/';
            return $baseurl . $path . $basename;
        }
        $account_id = esf_normalize_local_media_account_id( $account_id );
        if ( $account_id > 0 && esf_module_uses_account_media_folders( $module ) ) {
            $path .= $account_id . '/';
        }
        return $baseurl . $path . $basename;
    }

}
if ( !function_exists( 'esf_local_media_url' ) ) {
    /**
     * Public URL for a cached media file when it exists on disk.
     *
     * Checks hashtag folder first (for hashtag keys), then account subfolder, then legacy root.
     *
     * @since 6.9.0
     *
     * @param mixed  $id         Media identifier.
     * @param string $module     Module slug.
     * @param int    $max_width  Optional max width variant (0 = full size).
     * @param int    $account_id Optional internal account id.
     * @return string URL when the file exists, otherwise empty string.
     */
    function esf_local_media_url(
        $id,
        $module = 'facebook',
        $max_width = 0,
        $account_id = 0
    ) {
        $basename = esf_local_media_basename( $id, $max_width );
        if ( '' === $basename ) {
            return '';
        }
        $hashtag = '';
        if ( function_exists( 'esf_is_hashtag_media_key' ) && function_exists( 'esf_extract_hashtag_from_key' ) ) {
            if ( esf_is_hashtag_media_key( $id ) ) {
                $hashtag = esf_extract_hashtag_from_key( $id );
                if ( '' !== $hashtag ) {
                    $file = esf_local_media_file_path(
                        $id,
                        $module,
                        $max_width,
                        0
                    );
                    if ( '' !== $file && file_exists( $file ) ) {
                        return esf_local_media_public_url(
                            $module,
                            0,
                            $basename,
                            $hashtag
                        );
                    }
                }
            }
        }
        foreach ( esf_local_media_resolve_account_ids( $module, $account_id ) as $resolved_account_id ) {
            $file = esf_local_media_file_path(
                $id,
                $module,
                $max_width,
                $resolved_account_id
            );
            if ( '' !== $file && file_exists( $file ) ) {
                return esf_local_media_public_url( $module, $resolved_account_id, $basename );
            }
        }
        return '';
    }

}
if ( !function_exists( 'esf_get_local_media_module_slugs' ) ) {
    /**
     * Module slugs that participate in local media downloads.
     *
     * @since 6.9.6
     * @return array<int,string>
     */
    function esf_get_local_media_module_slugs() {
        return array(
            'facebook',
            'instagram',
            'youtube',
            'twitter',
            'tiktok'
        );
    }

}
if ( !function_exists( 'esf_default_download_media_locally_map' ) ) {
    /**
     * Default per-module map: downloads enabled for every module.
     *
     * @since 6.9.6
     * @return array<string,bool>
     */
    function esf_default_download_media_locally_map() {
        $map = array();
        foreach ( esf_get_local_media_module_slugs() as $slug ) {
            $map[$slug] = true;
        }
        return $map;
    }

}
if ( !function_exists( 'esf_normalize_download_media_locally_map' ) ) {
    /**
     * Sanitize a per-module download-media map (unknown keys dropped; missing keys default on).
     *
     * @since 6.9.6
     *
     * @param mixed $map Raw map from settings or REST.
     * @return array<string,bool>
     */
    function esf_normalize_download_media_locally_map(  $map  ) {
        $normalized = esf_default_download_media_locally_map();
        if ( !is_array( $map ) ) {
            return $normalized;
        }
        foreach ( $normalized as $slug => $default ) {
            if ( array_key_exists( $slug, $map ) ) {
                $normalized[$slug] = (bool) $map[$slug];
            }
        }
        return $normalized;
    }

}
if ( !function_exists( 'esf_is_local_media_download_enabled' ) ) {
    /**
     * Whether new local media downloads are enabled for a module (settings only).
     *
     * Existing files on disk are still served when present; this only gates new downloads.
     *
     * @since 6.9.6
     *
     * @param string $module Module slug (facebook, instagram, youtube, …).
     * @return bool
     */
    function esf_is_local_media_download_enabled(  $module = 'facebook'  ) {
        $module = ( function_exists( 'sanitize_key' ) ? sanitize_key( (string) $module ) : strtolower( (string) preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $module ) ) );
        if ( '' === $module ) {
            $module = 'facebook';
        }
        $map = esf_default_download_media_locally_map();
        if ( class_exists( 'ESF_Settings' ) ) {
            $settings = ESF_Settings::get();
            if ( isset( $settings['general']['download_media_locally'] ) ) {
                $map = esf_normalize_download_media_locally_map( $settings['general']['download_media_locally'] );
            }
        } else {
            $settings = get_option( 'esf_settings', array() );
            if ( is_array( $settings ) && isset( $settings['general']['download_media_locally'] ) ) {
                $map = esf_normalize_download_media_locally_map( $settings['general']['download_media_locally'] );
            }
        }
        if ( !array_key_exists( $module, $map ) ) {
            return true;
        }
        return !empty( $map[$module] );
    }

}
if ( !function_exists( 'esf_should_serve_media_locally' ) ) {
    /**
     * Whether ESF may download remote media into uploads for this request.
     *
     * Honours General → “Download images locally” per module, then
     * `esf_should_serve_media_locally` for programmatic overrides.
     *
     * @since 6.9.6
     *
     * @param string      $module     Module slug.
     * @param mixed       $id         Media cache key (optional context for filters).
     * @param string|null $url        Remote URL (optional context for filters).
     * @param int         $account_id Internal account id (optional context for filters).
     * @return bool
     */
    function esf_should_serve_media_locally(
        $module = 'facebook',
        $id = null,
        $url = null,
        $account_id = 0
    ) {
        $enabled = esf_is_local_media_download_enabled( $module );
        /**
         * Filter whether Easy Social Feed may download remote media into uploads.
         *
         * Return false to skip the download for this call. Existing local files
         * are still returned by {@see esf_serve_media_locally()} when present.
         *
         * @since 6.9.6
         *
         * @param bool        $enabled    Whether downloads are enabled for the module.
         * @param string      $module     Module slug.
         * @param mixed       $id         Media cache key.
         * @param string|null $url        Remote image URL.
         * @param int         $account_id Internal account id.
         */
        return (bool) apply_filters(
            'esf_should_serve_media_locally',
            $enabled,
            $module,
            $id,
            $url,
            $account_id
        );
    }

}
if ( !function_exists( 'esf_local_media_max_download_bytes' ) ) {
    /**
     * Maximum bytes to accept when downloading remote media into uploads.
     *
     * Guards against oversized CDN/video URLs exhausting PHP memory. Requests
     * stream to disk and abort once this cap is reached.
     *
     * @since 6.14.1
     *
     * @param string      $module     Module slug.
     * @param mixed       $id         Media cache key.
     * @param string|null $url        Remote URL.
     * @param int         $account_id Internal account id.
     * @return int
     */
    function esf_local_media_max_download_bytes(
        $module = 'facebook',
        $id = null,
        $url = null,
        $account_id = 0
    ) {
        $default = 8388608;
        // 8 MB.
        /**
         * Filter the local-media download size cap.
         *
         * @since 6.14.1
         *
         * @param int         $default    Default cap in bytes.
         * @param string      $module     Module slug.
         * @param mixed       $id         Media cache key.
         * @param string|null $url        Remote URL.
         * @param int         $account_id Internal account id.
         */
        $max = (int) apply_filters(
            'esf_local_media_max_download_bytes',
            $default,
            $module,
            $id,
            $url,
            $account_id
        );
        return ( $max > 0 ? $max : $default );
    }

}
if ( !function_exists( 'esf_delete_local_media_file' ) ) {
    /**
     * Delete a local media file path (partial downloads included).
     *
     * @since 6.14.1
     *
     * @param string $file Absolute filesystem path.
     * @return void
     */
    function esf_delete_local_media_file(  $file  ) {
        $file = ( is_string( $file ) ? $file : '' );
        if ( '' === $file || !file_exists( $file ) ) {
            return;
        }
        if ( function_exists( 'wp_delete_file' ) ) {
            wp_delete_file( $file );
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback when wp_delete_file() is unavailable (unit tests).
        unlink( $file );
    }

}
if ( !function_exists( 'esf_serve_media_locally' ) ) {
    /**
     * Download remote media into uploads/esf-{module} and return its local URL.
     *
     * When downloads are disabled for the module (settings or filter), existing
     * local files are still returned; new downloads are skipped (returns false).
     *
     * Downloads stream to disk and are capped so a single oversized CDN/video
     * URL cannot exhaust PHP memory (Requests buffers the body otherwise).
     *
     * @since 6.4.4
     *
     * @param mixed  $id     Unique media cache key.
     * @param string $url    Remote image URL.
     * @param string $module     Module slug.
     * @param int    $account_id Optional internal account id (instagram/youtube/twitter).
     * @return false|string Local URL on success, false on failure.
     */
    function esf_serve_media_locally(
        $id,
        $url,
        $module = 'facebook',
        $account_id = 0
    ) {
        if ( !$id || !$url || !is_string( $url ) ) {
            return false;
        }
        $existing = esf_local_media_url(
            $id,
            $module,
            0,
            $account_id
        );
        if ( '' !== $existing ) {
            return $existing;
        }
        if ( !esf_should_serve_media_locally(
            $module,
            $id,
            $url,
            $account_id
        ) ) {
            return false;
        }
        $account_id = esf_normalize_local_media_account_id( $account_id );
        $file = esf_local_media_file_path(
            $id,
            $module,
            0,
            $account_id
        );
        if ( '' === $file ) {
            return false;
        }
        $hashtag = '';
        if ( function_exists( 'esf_is_hashtag_media_key' ) && function_exists( 'esf_extract_hashtag_from_key' ) ) {
            if ( esf_is_hashtag_media_key( $id ) ) {
                $hashtag = esf_extract_hashtag_from_key( $id );
            }
        }
        $directory = esf_get_uploads_directory( $module, $account_id, $hashtag );
        if ( !wp_mkdir_p( $directory ) ) {
            return false;
        }
        $max_bytes = ( function_exists( 'esf_local_media_max_download_bytes' ) ? esf_local_media_max_download_bytes(
            $module,
            $id,
            $url,
            $account_id
        ) : 8388608 );
        $headers = array(
            'User-Agent' => 'Mozilla/5.0 (compatible; EasySocialFeed/' . (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0' )) . '; +https://easysocialfeed.com)',
            'Accept'     => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        );
        /**
         * Filter HTTP headers used when downloading remote media to disk.
         *
         * @since 6.14.1
         *
         * @param array       $headers    Request headers.
         * @param string      $module     Module slug.
         * @param mixed       $id         Media cache key.
         * @param string      $url        Remote URL.
         * @param int         $account_id Internal account id.
         */
        $headers = (array) apply_filters(
            'esf_local_media_download_headers',
            $headers,
            $module,
            $id,
            $url,
            $account_id
        );
        $response = wp_remote_get( $url, array(
            'timeout'             => 30,
            'redirection'         => 5,
            'stream'              => true,
            'filename'            => $file,
            'limit_response_size' => $max_bytes,
            'headers'             => $headers,
        ) );
        if ( is_wp_error( $response ) ) {
            esf_delete_local_media_file( $file );
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            esf_delete_local_media_file( $file );
            return false;
        }
        if ( !file_exists( $file ) ) {
            return false;
        }
        $file_size = (int) filesize( $file );
        if ( $file_size <= 0 ) {
            esf_delete_local_media_file( $file );
            return false;
        }
        $content_length = (int) wp_remote_retrieve_header( $response, 'content-length' );
        $looks_truncated = $content_length > $max_bytes || $file_size >= $max_bytes && ($content_length <= 0 || $content_length > $file_size);
        if ( $looks_truncated ) {
            esf_delete_local_media_file( $file );
            return false;
        }
        return esf_local_media_url(
            $id,
            $module,
            0,
            $account_id
        );
    }

}
if ( !function_exists( 'esf_ensure_local_media_variant' ) ) {
    /**
     * Create (or return) a max-width JPEG variant from an existing full-size local file.
     *
     * Used for feed/grid thumbnails while the full-size file powers lightbox/popup views.
     *
     * @since 6.9.0
     *
     * @param mixed  $id         Media identifier (same key as full-size file).
     * @param string $module     Module slug.
     * @param int    $max_width  Target max width in pixels.
     * @param int    $account_id Optional internal account id.
     * @return false|string Local variant URL, or false when unavailable.
     */
    function esf_ensure_local_media_variant(
        $id,
        $module = 'facebook',
        $max_width = 0,
        $account_id = 0
    ) {
        $max_width = (int) $max_width;
        if ( $max_width <= 0 ) {
            return false;
        }
        $existing = esf_local_media_url(
            $id,
            $module,
            $max_width,
            $account_id
        );
        if ( '' !== $existing ) {
            return $existing;
        }
        $write_account_id = esf_normalize_local_media_account_id( $account_id );
        $source = '';
        $source_account_id = 0;
        foreach ( esf_local_media_resolve_account_ids( $module, $account_id ) as $resolved_account_id ) {
            $candidate = esf_local_media_file_path(
                $id,
                $module,
                0,
                $resolved_account_id
            );
            if ( '' !== $candidate && file_exists( $candidate ) ) {
                $source = $candidate;
                $source_account_id = $resolved_account_id;
                break;
            }
        }
        if ( '' === $source ) {
            return false;
        }
        $dest_account_id = $write_account_id;
        if ( $dest_account_id <= 0 || !esf_module_uses_account_media_folders( $module ) ) {
            $dest_account_id = $source_account_id;
        }
        $dest = esf_local_media_file_path(
            $id,
            $module,
            $max_width,
            $dest_account_id
        );
        if ( '' === $dest ) {
            return false;
        }
        $dest_dir = dirname( $dest );
        if ( !wp_mkdir_p( $dest_dir ) ) {
            return false;
        }
        if ( !function_exists( 'wp_get_image_editor' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $editor = wp_get_image_editor( $source );
        if ( is_wp_error( $editor ) ) {
            return false;
        }
        $editor->resize( $max_width, null, false );
        $saved = $editor->save( $dest, 'image/jpeg' );
        if ( is_wp_error( $saved ) ) {
            return false;
        }
        return esf_local_media_url(
            $id,
            $module,
            $max_width,
            $dest_account_id
        );
    }

}
if ( !function_exists( 'esf_serve_media_locally_sized' ) ) {
    /**
     * Serve media locally, optionally returning a resized variant URL.
     *
     * When $max_width is 0, behaves like esf_serve_media_locally(). When $max_width
     * is set, downloads the full image first, then creates/returns a variant via
     * esf_ensure_local_media_variant().
     *
     * @since 6.9.0
     *
     * @param mixed  $id        Media identifier.
     * @param string $url       Remote image URL.
     * @param string $module    Module slug.
     * @param int    $max_width  Target max width (0 = full only).
     * @param int    $account_id Optional internal account id.
     * @return false|string Local URL.
     */
    function esf_serve_media_locally_sized(
        $id,
        $url,
        $module = 'facebook',
        $max_width = 0,
        $account_id = 0
    ) {
        $full_url = esf_serve_media_locally(
            $id,
            $url,
            $module,
            $account_id
        );
        if ( !is_string( $full_url ) || '' === $full_url ) {
            return false;
        }
        $max_width = (int) $max_width;
        if ( $max_width <= 0 ) {
            return $full_url;
        }
        if ( function_exists( 'esf_ensure_local_media_variant' ) ) {
            $variant = esf_ensure_local_media_variant(
                $id,
                $module,
                $max_width,
                $account_id
            );
            if ( is_string( $variant ) && '' !== $variant ) {
                return $variant;
            }
        }
        return $full_url;
    }

}
if ( !function_exists( 'esf_is_valid_image_url' ) ) {
    /**
     * Check if an image URL is reachable (HTTP 200).
     *
     * Central helper so URL validity checks are consistent across the plugin.
     *
     * @param string $url Image URL.
     * @return bool
     */
    function esf_is_valid_image_url(  $url  ) {
        if ( empty( $url ) || !is_string( $url ) ) {
            return false;
        }
        $args = array(
            'timeout'   => 5,
            'sslverify' => false,
            'method'    => 'HEAD',
        );
        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = wp_remote_retrieve_response_code( $response );
        return 200 === $code;
    }

}
if ( !function_exists( 'esf_is_local_media_url' ) ) {
    /**
     * Check if a URL points to media served from the site's ESF uploads folder.
     * Local media does not trigger third-party requests, so GDPR placeholder can be skipped.
     *
     * @param string $url    Image URL to check.
     * @param string $module Module slug (e.g. 'facebook', 'instagram').
     * @return bool True if the URL is from this site's uploads/esf-{module} folder.
     */
    function esf_is_local_media_url(  $url, $module = 'facebook'  ) {
        if ( empty( $url ) || !is_string( $url ) ) {
            return false;
        }
        $upload_dir = wp_upload_dir();
        $baseurl = untrailingslashit( $upload_dir['baseurl'] );
        $path = '/esf-' . $module . '/';
        return strpos( $url, $baseurl . $path ) === 0;
    }

}
if ( !function_exists( 'esf_readable_count' ) ) {
    /**
     * Format large numbers into short, human-readable strings.
     *
     * Shared helper for all modules so follower counts, views, likes, etc.
     * are displayed consistently (e.g. 1.2K, 3.4M).
     *
     * @since 6.5.0
     *
     * @param int|float|string $number Raw numeric value.
     * @return string Human-readable representation.
     */
    function esf_readable_count(  $number  ) {
        if ( !is_numeric( $number ) ) {
            $number = (int) $number;
        }
        $number = (float) $number;
        if ( $number >= 1000000000 ) {
            return round( $number / 1000000000, 1 ) . __( 'B', 'easy-facebook-likebox' );
        }
        if ( $number >= 1000000 ) {
            return round( $number / 1000000, 1 ) . __( 'M', 'easy-facebook-likebox' );
        }
        if ( $number >= 1000 ) {
            return round( $number / 1000, 1 ) . __( 'K', 'easy-facebook-likebox' );
        }
        return number_format_i18n( (int) $number );
    }

}
if ( !function_exists( 'esf_get_time_unit_labels' ) ) {
    /**
     * Localized time-unit labels (+ "ago") from Settings → Translation → Time.
     *
     * Shared by modern and legacy relative-time helpers so every module reads
     * the same registry keys. Blank fields fall back to English defaults via
     * esf_get_translated_string(), then through __() for .po/.mo.
     *
     * @since 6.9.0
     *
     * @return array<string,string> Map of registry key => display text.
     */
    function esf_get_time_unit_labels() {
        $keys = array(
            'second',
            'seconds',
            'minute',
            'minutes',
            'hour',
            'hours',
            'day',
            'days',
            'week',
            'weeks',
            'month',
            'months',
            'year',
            'years',
            'decade',
            'ago'
        );
        $labels = array();
        foreach ( $keys as $key ) {
            $text = ( function_exists( 'esf_get_translated_string' ) ? esf_get_translated_string( $key ) : $key );
            $labels[$key] = __( $text, 'easy-facebook-likebox' );
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Registry defaults / custom strings.
        }
        return $labels;
    }

}
if ( !function_exists( 'esf_format_granular_time_ago' ) ) {
    /**
     * Build a multi-unit relative time string (legacy feed calculator).
     *
     * Shared implementation for efbl_time_ago() and esf_insta_readable_time().
     * Preserves the historical floor-based periods and granularity behaviour.
     *
     * @since 6.9.0
     *
     * @param int $date_timestamp Unix timestamp of the event.
     * @param int $granularity    Max number of units to include (default 2).
     * @return string Relative time ending with the localized "ago" label.
     */
    function esf_format_granular_time_ago(  $date_timestamp, $granularity = 2  ) {
        $date_timestamp = (int) $date_timestamp;
        $granularity = (int) $granularity;
        $labels = esf_get_time_unit_labels();
        $ago_text = ( isset( $labels['ago'] ) ? $labels['ago'] : 'ago' );
        $retval = '';
        $difference = time() - $date_timestamp;
        $periods = array(
            'decade' => 315360000,
            'year'   => 31536000,
            'month'  => 2628000,
            'week'   => 604800,
            'day'    => 86400,
            'hour'   => 3600,
            'minute' => 60,
            'second' => 1,
        );
        foreach ( $periods as $key => $value ) {
            if ( $difference >= $value ) {
                $time = (int) floor( $difference / $value );
                $difference %= $value;
                $unit_key = ( $time > 1 ? $key . 's' : $key );
                // Registry has "decade" but no "decades"; fall back to singular.
                if ( !isset( $labels[$unit_key] ) ) {
                    $unit_key = $key;
                }
                $retval .= (( $retval ? ' ' : '' )) . $time . ' ' . $labels[$unit_key];
                --$granularity;
            }
            if ( 0 === $granularity ) {
                break;
            }
        }
        return '' . $retval . ' ' . $ago_text;
    }

}
if ( !function_exists( 'esf_human_time_diff_parts' ) ) {
    /**
     * Resolve count + unit keys using the same buckets as WordPress human_time_diff().
     *
     * Kept in sync with wp-includes/formatting.php::human_time_diff() so blank
     * Translation → Time fields continue to produce identical output.
     *
     * @since 6.9.0
     *
     * @param int $diff Absolute difference in seconds.
     * @return array{0:int,1:string,2:string} Count, singular registry key, plural registry key.
     */
    function esf_human_time_diff_parts(  $diff  ) {
        $diff = (int) abs( $diff );
        if ( $diff < MINUTE_IN_SECONDS ) {
            $secs = ( $diff <= 1 ? 1 : $diff );
            return array($secs, 'second', 'seconds');
        }
        if ( $diff < HOUR_IN_SECONDS ) {
            $mins = (int) round( $diff / MINUTE_IN_SECONDS );
            $mins = ( $mins <= 1 ? 1 : $mins );
            return array($mins, 'minute', 'minutes');
        }
        if ( $diff < DAY_IN_SECONDS ) {
            $hours = (int) round( $diff / HOUR_IN_SECONDS );
            $hours = ( $hours <= 1 ? 1 : $hours );
            return array($hours, 'hour', 'hours');
        }
        if ( $diff < WEEK_IN_SECONDS ) {
            $days = (int) round( $diff / DAY_IN_SECONDS );
            $days = ( $days <= 1 ? 1 : $days );
            return array($days, 'day', 'days');
        }
        if ( $diff < MONTH_IN_SECONDS ) {
            $weeks = (int) round( $diff / WEEK_IN_SECONDS );
            $weeks = ( $weeks <= 1 ? 1 : $weeks );
            return array($weeks, 'week', 'weeks');
        }
        if ( $diff < YEAR_IN_SECONDS ) {
            $months = (int) round( $diff / MONTH_IN_SECONDS );
            $months = ( $months <= 1 ? 1 : $months );
            return array($months, 'month', 'months');
        }
        $years = (int) round( $diff / YEAR_IN_SECONDS );
        $years = ( $years <= 1 ? 1 : $years );
        return array($years, 'year', 'years');
    }

}
if ( !function_exists( 'esf_readable_time_ago' ) ) {
    /**
     * Convert a date/time string to a relative "time ago" string.
     *
     * Wrapper around human_time_diff() that accepts common date formats
     * and appends a translated "ago" suffix. Intended for feed items
     * (Facebook, Instagram, YouTube, etc.).
     *
     * Honors Settings → Translation → Time custom strings when set. Blank
     * fields keep the previous human_time_diff() + "%s ago" behaviour so
     * WordPress core and plugin gettext translations are unchanged.
     *
     * @since 6.5.0
     *
     * @param string|int $datetime Datetime string or Unix timestamp.
     * @return string Human-readable relative time.
     */
    function esf_readable_time_ago(  $datetime  ) {
        if ( empty( $datetime ) ) {
            return '';
        }
        $timestamp = ( is_numeric( $datetime ) ? (int) $datetime : strtotime( $datetime ) );
        if ( !$timestamp ) {
            return '';
        }
        $now = current_time( 'timestamp' );
        $custom_ago = ( function_exists( 'esf_get_custom_translated_string' ) ? esf_get_custom_translated_string( 'ago' ) : '' );
        $custom_unit = '';
        $count = 0;
        if ( function_exists( 'esf_get_custom_translated_string' ) ) {
            list( $count, $singular_key, $plural_key ) = esf_human_time_diff_parts( (int) abs( $now - $timestamp ) );
            $unit_key = ( 1 === (int) $count ? $singular_key : $plural_key );
            $custom_unit = esf_get_custom_translated_string( $unit_key );
        }
        // Custom unit text is used as-is (do not re-run through __()).
        $phrase = ( '' !== $custom_unit ? $count . ' ' . $custom_unit : human_time_diff( $timestamp, $now ) );
        if ( '' !== $custom_ago ) {
            // Custom "ago" text is used as-is (do not re-run through __()).
            return $phrase . ' ' . $custom_ago;
        }
        return sprintf( 
            /* translators: %s: human readable time difference, e.g. "2 hours" */
            __( '%s ago', 'easy-facebook-likebox' ),
            $phrase
         );
    }

}
if ( !function_exists( 'esf_delete_media' ) ) {
    /**
     * Delete media locally
     *
     * @param        $id
     * @param string $module
     * @param int    $account_id
     *
     * @return bool
     */
    function esf_delete_media(  $id, $module = 'facebook', $account_id = 0  ) {
        $id_safe = esf_sanitize_local_media_id( $id );
        if ( '' === $id_safe ) {
            return false;
        }
        $deleted = false;
        $hashtag = '';
        if ( function_exists( 'esf_is_hashtag_media_key' ) && function_exists( 'esf_extract_hashtag_from_key' ) ) {
            if ( esf_is_hashtag_media_key( $id ) ) {
                $hashtag = esf_extract_hashtag_from_key( $id );
            }
        }
        if ( '' !== $hashtag ) {
            $directory = esf_get_uploads_directory( $module, 0, $hashtag );
            $patterns = array($directory . '/' . $id_safe . '.jpg', $directory . '/' . $id_safe . '_w*.jpg');
            foreach ( $patterns as $pattern ) {
                $files = glob( $pattern );
                if ( !is_array( $files ) ) {
                    continue;
                }
                foreach ( $files as $file ) {
                    if ( is_string( $file ) && file_exists( $file ) ) {
                        if ( unlink( $file ) ) {
                            $deleted = true;
                        }
                    }
                }
            }
            return $deleted;
        }
        foreach ( esf_local_media_resolve_account_ids( $module, $account_id ) as $resolved_account_id ) {
            $directory = esf_get_uploads_directory( $module, $resolved_account_id );
            $patterns = array($directory . '/' . $id_safe . '.jpg', $directory . '/' . $id_safe . '_w*.jpg');
            foreach ( $patterns as $pattern ) {
                $files = glob( $pattern );
                if ( !is_array( $files ) ) {
                    continue;
                }
                foreach ( $files as $file ) {
                    if ( is_string( $file ) && file_exists( $file ) ) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing plugin-owned cache files.
                        if ( unlink( $file ) ) {
                            $deleted = true;
                        }
                    }
                }
            }
        }
        return $deleted;
    }

}
if ( !function_exists( 'esf_delete_media_account_folder' ) ) {
    /**
     * Delete all cached media for one account (instagram/youtube/twitter).
     *
     * @since 6.9.0
     *
     * @param string $module     Module slug.
     * @param int    $account_id Internal account id.
     * @return bool True when the folder existed and was removed.
     */
    function esf_delete_media_account_folder(  $module, $account_id  ) {
        $account_id = esf_normalize_local_media_account_id( $account_id );
        if ( $account_id <= 0 || !esf_module_uses_account_media_folders( $module ) ) {
            return false;
        }
        $directory = esf_get_uploads_directory( $module, $account_id );
        if ( !is_dir( $directory ) ) {
            return false;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $file_system_direct = new WP_Filesystem_Direct(false);
        return (bool) $file_system_direct->rmdir( $directory, true );
    }

}
if ( !function_exists( 'esf_delete_media_hashtag_folder' ) ) {
    /**
     * Delete all cached media for one hashtag (Instagram only).
     *
     * @since 6.9.0
     *
     * @param string $module Module slug.
     * @param string $hashtag Normalized hashtag.
     * @return bool True when the folder existed and was removed.
     */
    function esf_delete_media_hashtag_folder(  $module, $hashtag  ) {
        $hashtag = sanitize_key( (string) $hashtag );
        if ( '' === $hashtag || 'instagram' !== $module ) {
            return false;
        }
        $directory = esf_get_uploads_directory( $module, 0, $hashtag );
        if ( !is_dir( $directory ) ) {
            return false;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $file_system_direct = new WP_Filesystem_Direct(false);
        return (bool) $file_system_direct->rmdir( $directory, true );
    }

}
if ( !function_exists( 'esf_delete_media_folder' ) ) {
    /**
     * Delete an entire module media folder (all accounts; legacy/admin use).
     *
     * @since 6.4.4
     *
     * @param string $module Module slug.
     * @return void
     */
    function esf_delete_media_folder(  $module = 'facebook'  ) {
        $directory = esf_get_uploads_directory( $module, 0 );
        if ( !is_dir( $directory ) ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $file_system_direct = new WP_Filesystem_Direct(false);
        $file_system_direct->rmdir( $directory, true );
    }

}
if ( !function_exists( 'esf_sort_by_created_time' ) ) {
    /**
     * Sort data to created_time
     *
     * @param null $data
     *
     * @return false|mixed
     *
     * @since 6.4.9
     */
    function esf_sort_by_created_time(  $data = null  ) {
        if ( !$data ) {
            return false;
        }
        $order = array();
        foreach ( $data as $single_post ) {
            $order[] = strtotime( $single_post->created_time );
        }
        array_multisort( $order, SORT_DESC, $data );
        return $data;
    }

}
if ( !function_exists( 'esf_check_ajax_referer' ) ) {
    /**
     * Check ajax referer
     *
     * @since 6.5.0
     */
    function esf_check_ajax_referer() {
        if ( !check_ajax_referer( 'esf-ajax-nonce', 'nonce', false ) || !current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( "Nonce not verified or you don't have appropriate permissions", 'easy-facebook-likebox' ) );
        }
    }

}
if ( !function_exists( 'esf_get_design_value' ) ) {
    /**
     * Get design value
     *
     * @param array $skin
     * @param string $key
     * @param string $default
     *
     * @since 6.6.1
     */
    function esf_get_design_value(  $skin, $key, $default = ''  ) {
        if ( !$key || !$skin ) {
            return $default;
        }
        return ( isset( $skin['design'][$key] ) ? $skin['design'][$key] : $default );
    }

}
/**
 * Safe wrapper for esf_safe_strpos() that handles null values
 *
 * @param mixed $haystack The string to search in
 * @param string $needle The string to search for
 * @param int $offset The optional offset parameter
 */
if ( !function_exists( 'esf_safe_strpos' ) ) {
    function esf_safe_strpos(  $haystack, $needle, $offset = 0  ) {
        // Check if haystack is null or not a string or not defined
        if ( !isset( $haystack ) || $haystack === null || !is_string( $haystack ) ) {
            return false;
        }
        return strpos( $haystack, $needle, $offset );
    }

}
/**
 * Compile and filter the list of locales for Facebook/Instagram API and widgets.
 * Used by feed API language setting, like box locale, and page plugin widget.
 *
 * @return array<string, string> Locale code => label.
 */
if ( !function_exists( 'efbl_get_locales' ) ) {
    function efbl_get_locales() {
        $locales = array(
            'af_ZA' => 'Afrikaans',
            'ar_AR' => 'Arabic',
            'az_AZ' => 'Azeri',
            'be_BY' => 'Belarusian',
            'bg_BG' => 'Bulgarian',
            'bn_IN' => 'Bengali',
            'bs_BA' => 'Bosnian',
            'ca_ES' => 'Catalan',
            'cs_CZ' => 'Czech',
            'cy_GB' => 'Welsh',
            'da_DK' => 'Danish',
            'de_DE' => 'German',
            'el_GR' => 'Greek',
            'en_US' => 'English (US)',
            'en_GB' => 'English (UK)',
            'eo_EO' => 'Esperanto',
            'es_ES' => 'Spanish (Spain)',
            'es_LA' => 'Spanish',
            'et_EE' => 'Estonian',
            'eu_ES' => 'Basque',
            'fa_IR' => 'Persian',
            'fb_LT' => 'Leet Speak',
            'fi_FI' => 'Finnish',
            'fo_FO' => 'Faroese',
            'fr_FR' => 'French (France)',
            'fr_CA' => 'French (Canada)',
            'fy_NL' => 'NETHERLANDS (NL)',
            'ga_IE' => 'Irish',
            'gl_ES' => 'Galician',
            'hi_IN' => 'Hindi',
            'hr_HR' => 'Croatian',
            'hu_HU' => 'Hungarian',
            'hy_AM' => 'Armenian',
            'id_ID' => 'Indonesian',
            'is_IS' => 'Icelandic',
            'it_IT' => 'Italian',
            'ja_JP' => 'Japanese',
            'ka_GE' => 'Georgian',
            'km_KH' => 'Khmer',
            'ko_KR' => 'Korean',
            'ku_TR' => 'Kurdish',
            'la_VA' => 'Latin',
            'lt_LT' => 'Lithuanian',
            'lv_LV' => 'Latvian',
            'mk_MK' => 'Macedonian',
            'ml_IN' => 'Malayalam',
            'ms_MY' => 'Malay',
            'nb_NO' => 'Norwegian (bokmal)',
            'ne_NP' => 'Nepali',
            'nl_NL' => 'Dutch',
            'nn_NO' => 'Norwegian (nynorsk)',
            'pa_IN' => 'Punjabi',
            'pl_PL' => 'Polish',
            'ps_AF' => 'Pashto',
            'pt_PT' => 'Portuguese (Portugal)',
            'pt_BR' => 'Portuguese (Brazil)',
            'ro_RO' => 'Romanian',
            'ru_RU' => 'Russian',
            'sk_SK' => 'Slovak',
            'sl_SI' => 'Slovenian',
            'sq_AL' => 'Albanian',
            'sr_RS' => 'Serbian',
            'sv_SE' => 'Swedish',
            'sw_KE' => 'Swahili',
            'ta_IN' => 'Tamil',
            'te_IN' => 'Telugu',
            'th_TH' => 'Thai',
            'tl_PH' => 'Filipino',
            'tr_TR' => 'Turkish',
            'uk_UA' => 'Ukrainian',
            'ur_PK' => 'Urdu',
            'vi_VN' => 'Vietnamese',
            'zh_CN' => 'Simplified Chinese (China)',
            'zh_HK' => 'Traditional Chinese (Hong Kong)',
            'zh_TW' => 'Traditional Chinese (Taiwan)',
        );
        return apply_filters( 'efbl_locale_names', $locales );
    }

}
/**
 * Supported locales for Facebook and Instagram Graph API (feed language).
 * Default option plus efbl_get_locales() for the admin dropdown.
 *
 * @since 6.8.0
 * @return array<string, string>
 */
if ( !function_exists( 'esf_get_supported_api_locales' ) ) {
    function esf_get_supported_api_locales() {
        return array_merge( array(
            '' => __( 'Default (use site language)', 'easy-facebook-likebox' ),
        ), efbl_get_locales() );
    }

}
/**
 * Normalize a WordPress/Feed language locale to one of the supported EFBL locales.
 *
 * Example: "ar" -> "ar_AR" (if that key exists), "es" -> "es_ES", etc.
 *
 * @since 6.7.6
 *
 * @param string $locale Raw locale (e.g. from get_locale() or saved setting).
 * @return string Normalized locale key from efbl_get_locales(), or empty string if not found.
 */
if ( !function_exists( 'esf_normalize_locale_to_supported' ) ) {
    function esf_normalize_locale_to_supported(  $locale  ) {
        $locale = (string) $locale;
        if ( '' === $locale ) {
            return '';
        }
        $supported = efbl_get_locales();
        // Exact match first.
        if ( isset( $supported[$locale] ) ) {
            return $locale;
        }
        // Try to map based on the language code only (first two letters).
        $lang2 = substr( $locale, 0, 2 );
        if ( !$lang2 ) {
            return '';
        }
        foreach ( $supported as $key => $label ) {
            if ( 0 === strpos( $key, $lang2 . '_' ) || $key === strtoupper( $lang2 ) . '_' ) {
                return $key;
            }
        }
        return '';
    }

}
/**
 * Effective API locale for Facebook/Instagram requests.
 * Uses saved setting, or WordPress site language, or en_US.
 *
 * @since 6.8.0
 * @return string Locale code (e.g. en_US, fr_FR).
 */
if ( !function_exists( 'esf_get_effective_api_locale' ) ) {
    function esf_get_effective_api_locale() {
        $settings = get_option( 'fta_settings', array() );
        $saved = ( isset( $settings['api_locale'] ) ? $settings['api_locale'] : '' );
        // 1) Saved Feed language from General tab.
        if ( '' !== $saved && is_string( $saved ) ) {
            $normalized_saved = esf_normalize_locale_to_supported( $saved );
            if ( '' !== $normalized_saved ) {
                return $normalized_saved;
            }
        }
        // 2) Site language (used when "Default" is selected).
        $wp_locale = get_locale();
        if ( !empty( $wp_locale ) ) {
            $normalized_wp = esf_normalize_locale_to_supported( $wp_locale );
            if ( '' !== $normalized_wp ) {
                return $normalized_wp;
            }
        }
        // 3) Fallback.
        return 'en_US';
    }

}
/**
 * Clear all Facebook and Instagram feed transients (cache).
 * Safe to call when API language or other feed-affecting settings change.
 *
 * @since 6.8.0
 * @return int|false Number of rows deleted, or false on error.
 */
if ( !function_exists( 'esf_clear_feed_transients' ) ) {
    function esf_clear_feed_transients() {
        global $wpdb;
        if ( !isset( $wpdb->options ) || !$wpdb->options ) {
            return false;
        }
        $option_table = $wpdb->options;
        $like_efbl = $wpdb->esc_like( '_transient_efbl_' ) . '%';
        $like_efbl_timeout = $wpdb->esc_like( '_transient_timeout_efbl_' ) . '%';
        $like_esf = $wpdb->esc_like( '_transient_esf_' ) . '%';
        $like_esf_timeout = $wpdb->esc_like( '_transient_timeout_esf_' ) . '%';
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$option_table} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $like_efbl,
            $like_efbl_timeout,
            $like_esf,
            $like_esf_timeout
        ) );
        return $deleted;
    }

}
/**
 * Register a missing-feed placeholder for admin recovery tooling.
 *
 * @since 6.9.0
 *
 * @param array<string, mixed> $entry Queue entry (module, feed_id, root_id, etc.).
 * @return void
 */
if ( !function_exists( 'esf_missing_feed_register' ) ) {
    function esf_missing_feed_register(  array $entry  ) {
        global $esf_missing_feeds_queue;
        if ( !is_array( $esf_missing_feeds_queue ) ) {
            $esf_missing_feeds_queue = array();
        }
        $esf_missing_feeds_queue[] = $entry;
    }

}
/**
 * Return queued missing-feed placeholders for the current request.
 *
 * @since 6.9.0
 *
 * @return array<int, array<string, mixed>>
 */
if ( !function_exists( 'esf_missing_feed_get_queue' ) ) {
    function esf_missing_feed_get_queue() {
        global $esf_missing_feeds_queue;
        return ( is_array( $esf_missing_feeds_queue ) ? $esf_missing_feeds_queue : array() );
    }

}
/**
 * Enqueue shared missing-feed admin recovery assets on the public frontend.
 *
 * @since 6.11.0
 *
 * @param array<int, string> $shortcode_prefixes Shortcode opening tags to scan in singular post content (e.g. `[esf_twitter_feed`).
 * @return void
 */
if ( !function_exists( 'esf_enqueue_missing_feed_admin_assets' ) ) {
    function esf_enqueue_missing_feed_admin_assets(  array $shortcode_prefixes  ) {
        $should_enqueue = false;
        if ( function_exists( 'esf_missing_feed_get_queue' ) && !empty( esf_missing_feed_get_queue() ) ) {
            $should_enqueue = true;
        }
        if ( !$should_enqueue && function_exists( 'is_singular' ) && is_singular() ) {
            $post = get_post();
            if ( $post instanceof WP_Post ) {
                $content = (string) $post->post_content;
                foreach ( $shortcode_prefixes as $prefix ) {
                    $prefix = (string) $prefix;
                    if ( '' !== $prefix && false !== strpos( $content, $prefix ) ) {
                        $should_enqueue = true;
                        break;
                    }
                }
            }
        }
        if ( !$should_enqueue ) {
            return;
        }
        $script_path = FTA_PLUGIN_DIR . 'admin/shared/frontend/js/esf-missing-feed-admin.js';
        if ( !file_exists( $script_path ) ) {
            return;
        }
        $style_path = FTA_PLUGIN_DIR . 'admin/shared/frontend/css/esf-missing-feed.css';
        if ( file_exists( $style_path ) && !wp_style_is( 'esf-missing-feed', 'enqueued' ) ) {
            wp_enqueue_style(
                'esf-missing-feed',
                FTA_PLUGIN_URL . 'admin/shared/frontend/css/esf-missing-feed.css',
                array(),
                (string) filemtime( $style_path )
            );
        }
        if ( wp_script_is( 'esf-missing-feed-admin', 'enqueued' ) ) {
            return;
        }
        wp_enqueue_script(
            'esf-missing-feed-admin',
            FTA_PLUGIN_URL . 'admin/shared/frontend/js/esf-missing-feed-admin.js',
            array(),
            (string) filemtime( $script_path ),
            true
        );
        wp_localize_script( 'esf-missing-feed-admin', 'esfMissingFeedAdmin', array(
            'restUrl'              => rest_url( 'esf/v1' ),
            'nonce'                => wp_create_nonce( 'wp_rest' ),
            'loadingText'          => __( 'Loading…', 'easy-facebook-likebox' ),
            'titleText'            => __( 'Feed not found', 'easy-facebook-likebox' ),
            'adminIntroText'       => __( 'This shortcode points to a feed that was deleted. Choose another feed to preview or update this page.', 'easy-facebook-likebox' ),
            'legacyIntroText'      => __( 'This legacy Instagram shortcode needs updating after migration. Choose a feed to preview, then save the new shortcode to this page.', 'easy-facebook-likebox' ),
            'legacyTitleText'      => __( 'Legacy Instagram shortcode', 'easy-facebook-likebox' ),
            'selectFeedText'       => __( 'Select a feed', 'easy-facebook-likebox' ),
            'previewText'          => __( 'Preview feed', 'easy-facebook-likebox' ),
            'saveText'             => __( 'Save to this page', 'easy-facebook-likebox' ),
            'savedText'            => __( 'Shortcode updated. Reloading…', 'easy-facebook-likebox' ),
            'copyShortcodeText'    => __( 'Copy shortcode', 'easy-facebook-likebox' ),
            'copiedText'           => __( 'Copied!', 'easy-facebook-likebox' ),
            'dashboardText'        => __( 'Open dashboard', 'easy-facebook-likebox' ),
            'noFeedsText'          => __( 'No feeds available. Create one in the dashboard first.', 'easy-facebook-likebox' ),
            'noPostText'           => __( 'This shortcode is not inside editable page content. Copy the new shortcode and paste it where needed.', 'easy-facebook-likebox' ),
            'previewErrorText'     => __( 'Could not load feed preview.', 'easy-facebook-likebox' ),
            'saveErrorText'        => __( 'Could not update the shortcode on this page.', 'easy-facebook-likebox' ),
            'replaceShortcodePath' => '/missing-feed/replace-shortcode',
        ) );
    }

}
/**
 * Enqueue shared disconnected-feed pin-cache admin JS on the public frontend.
 *
 * No-op unless a disconnected feed rendered the pin button this request.
 *
 * @since 6.7.9
 * @return void
 */
if ( !function_exists( 'esf_enqueue_disconnected_cache_admin_assets' ) ) {
    function esf_enqueue_disconnected_cache_admin_assets() {
        if ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
            ESF_Feed_Disconnected_Cache::maybe_enqueue_frontend_pin_script();
        }
    }

}
/**
 * Derive SEO-friendly image alt text for social feed media.
 *
 * Thin wrapper around the shared SEO helper for procedural module code.
 *
 * @since 6.9.0
 *
 * @param string $caption_plain Plain or HTML caption / tweet text.
 * @param string $author_name   Account or channel display name.
 * @param string $media_type    `photo`, `video`, or `reel`.
 * @return string
 */
if ( !function_exists( 'esf_seo_media_alt' ) ) {
    function esf_seo_media_alt(  $caption_plain, $author_name = '', $media_type = 'photo'  ) {
        if ( class_exists( '\\EasySocialFeed\\SEO\\SeoHelper' ) ) {
            return \EasySocialFeed\SEO\SeoHelper::derive_media_alt( (string) $caption_plain, (string) $author_name, (string) $media_type );
        }
        $caption_plain = trim( wp_strip_all_tags( (string) $caption_plain ) );
        if ( '' !== $caption_plain ) {
            return wp_trim_words( $caption_plain, 15, '…' );
        }
        $author_name = trim( (string) $author_name );
        if ( '' !== $author_name ) {
            return $author_name;
        }
        return '';
    }

}
/**
 * Return sanitized email addresses for users with the administrator role.
 *
 * Used by module settings UIs when admins choose who receives token-reconnect
 * notifications. Falls back to `admin_email` when no administrator users exist.
 *
 * @since 6.9.0
 *
 * @return string[] Unique, valid email addresses.
 */
if ( !function_exists( 'esf_get_site_administrator_emails' ) ) {
    function esf_get_site_administrator_emails() {
        $emails = array();
        if ( function_exists( 'get_users' ) ) {
            $users = get_users( array(
                'role'   => 'administrator',
                'fields' => array('user_email'),
            ) );
            if ( is_array( $users ) ) {
                foreach ( $users as $user ) {
                    if ( !is_object( $user ) || empty( $user->user_email ) ) {
                        continue;
                    }
                    $email = sanitize_email( (string) $user->user_email );
                    if ( '' !== $email && is_email( $email ) ) {
                        $emails[] = strtolower( $email );
                    }
                }
            }
        }
        $emails = array_values( array_unique( $emails ) );
        if ( !empty( $emails ) ) {
            return $emails;
        }
        $fallback = sanitize_email( (string) get_option( 'admin_email' ) );
        if ( '' !== $fallback && is_email( $fallback ) ) {
            return array(strtolower( $fallback ));
        }
        return array();
    }

}
/**
 * Administrator email choices for module settings UIs.
 *
 * @since 6.9.0
 *
 * @return array<int, array{email: string, label: string, is_default: bool}>
 */
if ( !function_exists( 'esf_get_site_administrator_email_options' ) ) {
    function esf_get_site_administrator_email_options() {
        $suggested = ( function_exists( 'esf_resolve_default_notify_email' ) ? esf_resolve_default_notify_email( true ) : strtolower( sanitize_email( (string) get_option( 'admin_email' ) ) ) );
        $options = array();
        $seen = array();
        if ( function_exists( 'get_users' ) ) {
            $users = get_users( array(
                'role'   => 'administrator',
                'fields' => array('user_email', 'display_name'),
            ) );
            if ( is_array( $users ) ) {
                foreach ( $users as $user ) {
                    if ( !is_object( $user ) || empty( $user->user_email ) ) {
                        continue;
                    }
                    $email = strtolower( sanitize_email( (string) $user->user_email ) );
                    if ( '' === $email || !is_email( $email ) || isset( $seen[$email] ) ) {
                        continue;
                    }
                    $seen[$email] = true;
                    $name = trim( (string) $user->display_name );
                    $label = ( '' !== $name ? sprintf( '%s (%s)', $name, $email ) : $email );
                    $options[] = array(
                        'email'      => $email,
                        'label'      => $label,
                        'is_default' => '' !== $suggested && $email === $suggested,
                    );
                }
            }
        }
        if ( empty( $options ) && '' !== $suggested && is_email( $suggested ) ) {
            $options[] = array(
                'email'      => $suggested,
                'label'      => $suggested,
                'is_default' => true,
            );
        }
        return $options;
    }

}
/**
 * Sanitize reconnect-alert recipient emails against site administrators.
 *
 * @since 6.9.0
 * @param mixed $emails Raw email list.
 * @return string[]
 */
if ( !function_exists( 'esf_sanitize_notify_emails' ) ) {
    function esf_sanitize_notify_emails(  $emails  ) {
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

}
if ( !function_exists( 'esf_resolve_default_notify_email' ) ) {
    /**
     * Pick one administrator email for reconnect alerts.
     *
     * Prefers the logged-in administrator (e.g. installer activating the plugin), then
     * the site `admin_email` option.
     *
     * @since 6.9.0
     * @param bool $prefer_current_user When true, use wp_get_current_user() when they can manage_options.
     * @return string Lowercase email or empty string.
     */
    function esf_resolve_default_notify_email(  $prefer_current_user = true  ) {
        $normalize = static function ( $email ) {
            $email = strtolower( sanitize_email( (string) $email ) );
            return ( '' !== $email && is_email( $email ) ? $email : '' );
        };
        if ( $prefer_current_user && function_exists( 'wp_get_current_user' ) ) {
            $user = wp_get_current_user();
            if ( $user instanceof WP_User && $user->exists() && user_can( $user, 'manage_options' ) ) {
                $current = $normalize( $user->user_email );
                if ( '' !== $current ) {
                    return $current;
                }
            }
        }
        return $normalize( (string) get_option( 'admin_email' ) );
    }

}
/**
 * Default reconnect-notification recipients (single administrator).
 *
 * @since 6.9.0
 * @return string[]
 */
if ( !function_exists( 'esf_default_notify_emails' ) ) {
    function esf_default_notify_emails() {
        $email = esf_resolve_default_notify_email( true );
        return ( '' !== $email ? array($email) : array() );
    }

}
if ( !function_exists( 'esf_get_module_settings_option_key' ) ) {
    /**
     * Map a module slug to its per-module settings option key.
     *
     * @since 6.9.0
     * @param string $module_slug Module slug.
     * @return string Option key or empty string when unsupported.
     */
    function esf_get_module_settings_option_key(  $module_slug  ) {
        $module_slug = sanitize_key( (string) $module_slug );
        $map = array(
            'facebook'  => 'esf_facebook_settings',
            'instagram' => 'esf_instagram_settings',
            'youtube'   => 'esf_youtube_settings',
            'twitter'   => 'esf_twitter_settings',
            'tiktok'    => 'esf_tiktok_settings',
        );
        return ( isset( $map[$module_slug] ) ? $map[$module_slug] : '' );
    }

}
if ( !function_exists( 'esf_seed_module_reconnect_alert_settings' ) ) {
    /**
     * Seed reconnect-alert defaults when a module is activated.
     *
     * Persists the activator (or site admin_email) before Settings is opened. Does not
     * overwrite recipients the site owner already configured.
     *
     * @since 6.9.0
     * @param string $module_slug Module slug.
     * @return bool Whether settings were updated.
     */
    function esf_seed_module_reconnect_alert_settings(  $module_slug  ) {
        $option_key = esf_get_module_settings_option_key( $module_slug );
        if ( '' === $option_key ) {
            return false;
        }
        $settings = get_option( $option_key, array() );
        if ( !is_array( $settings ) ) {
            $settings = array();
        }
        $existing = ( isset( $settings['notify_token_reconnect_emails'] ) && is_array( $settings['notify_token_reconnect_emails'] ) ? array_values( array_filter( $settings['notify_token_reconnect_emails'] ) ) : array() );
        if ( !empty( $existing ) ) {
            return false;
        }
        if ( array_key_exists( 'notify_token_reconnect', $settings ) && empty( $settings['notify_token_reconnect'] ) ) {
            return false;
        }
        $emails = esf_default_notify_emails();
        if ( empty( $emails ) ) {
            return false;
        }
        $settings['notify_token_reconnect'] = true;
        $settings['notify_token_reconnect_emails'] = $emails;
        return (bool) update_option( $option_key, $settings );
    }

}
if ( !function_exists( 'esf_seed_activated_module_reconnect_alerts' ) ) {
    /**
     * Seed reconnect-alert defaults for every activated module.
     *
     * @since 6.9.0
     * @param array<string,mixed>|null $legacy_settings Optional `fta_settings` payload.
     * @return void
     */
    function esf_seed_activated_module_reconnect_alerts(  $legacy_settings = null  ) {
        $slugs = ( class_exists( 'ESF_Settings' ) ? ESF_Settings::MODULE_SLUGS : array(
            'facebook',
            'instagram',
            'youtube',
            'twitter',
            'tiktok'
        ) );
        foreach ( $slugs as $slug ) {
            $status = 'activated';
            if ( is_array( $legacy_settings ) && isset( $legacy_settings['plugins'][$slug]['status'] ) && in_array( (string) $legacy_settings['plugins'][$slug]['status'], array('activated', 'deactivated'), true ) ) {
                $status = (string) $legacy_settings['plugins'][$slug]['status'];
            } elseif ( class_exists( 'ESF_Settings' ) ) {
                $status = ESF_Settings::get_module_status( $slug );
            }
            if ( 'activated' === $status ) {
                esf_seed_module_reconnect_alert_settings( $slug );
            }
        }
    }

}
/**
 * Admin screen IDs for ESF plugin pages.
 *
 * @since 6.9.0
 * @return string[]
 */
function esf_get_admin_review_footer_screen_ids() {
    if ( class_exists( 'ESF_Review_Request' ) ) {
        return ESF_Review_Request::get_esf_admin_screen_ids();
    }
    return array('toplevel_page_easy-social-feed', 'admin_page_esf_welcome');
}

/**
 * Race-safe wrapper around {@see dbDelta()}.
 *
 * On activate/init races (or when DESCRIBE briefly misses an existing table),
 * core dbDelta may re-execute CREATE TABLE and log "Table already exists".
 * Rewrite those CREATE statements to CREATE TABLE IF NOT EXISTS for the
 * duration of dbDelta so the install stays idempotent.
 *
 * @since 6.9.1
 * @param string|string[] $queries CREATE TABLE SQL (or list of statements).
 * @return array<string, string> dbDelta report.
 */
if ( !function_exists( 'esf_dbdelta' ) ) {
    function esf_dbdelta(  $queries  ) {
        if ( !function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $rewrite_create = static function ( $query ) {
            if ( !is_string( $query ) || '' === $query ) {
                return $query;
            }
            if ( preg_match( '/^\\s*CREATE\\s+TABLE\\s+(?!IF\\s+NOT\\s+EXISTS)/i', $query ) ) {
                return preg_replace(
                    '/^\\s*CREATE\\s+TABLE\\s+/i',
                    'CREATE TABLE IF NOT EXISTS ',
                    $query,
                    1
                );
            }
            return $query;
        };
        add_filter( 'query', $rewrite_create, 0 );
        $result = dbDelta( $queries );
        // phpcs:ignore WordPress.DB.SchemaChange.SchemaChange
        remove_filter( 'query', $rewrite_create, 0 );
        return ( is_array( $result ) ? $result : array() );
    }

}
/**
 * Whether a DB table has a given column.
 *
 * @since 6.7.9
 * @param string $table  Full table name (with prefix).
 * @param string $column Column name.
 * @return bool
 */
if ( !function_exists( 'esf_db_table_has_column' ) ) {
    function esf_db_table_has_column(  $table, $column  ) {
        global $wpdb;
        $table = (string) $table;
        $column = (string) $column;
        if ( '' === $table || '' === $column ) {
            return false;
        }
        $table_escaped = esc_sql( $table );
        $found = $wpdb->get_results( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( 
                "SHOW COLUMNS FROM `{$table_escaped}` LIKE %s",
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $column
             )
         );
        return !empty( $found );
    }

}
/**
 * Add a column to an existing table when missing (idempotent).
 *
 * Prefer this over relying on dbDelta alone for critical token-manager columns.
 *
 * @since 6.7.9
 * @param string $table      Full table name (with prefix).
 * @param string $column     Column name.
 * @param string $definition Column definition without the name (e.g. "enum('oauth','manual') NOT NULL DEFAULT 'oauth'").
 * @param string $after      Optional existing column to place this after.
 * @return bool True when the column exists after the call.
 */
if ( !function_exists( 'esf_db_maybe_add_column' ) ) {
    function esf_db_maybe_add_column(
        $table,
        $column,
        $definition,
        $after = ''
    ) {
        global $wpdb;
        $table = (string) $table;
        $column = preg_replace( '/[^a-z0-9_]/i', '', (string) $column );
        $definition = trim( (string) $definition );
        $after = preg_replace( '/[^a-z0-9_]/i', '', (string) $after );
        if ( '' === $table || '' === $column || '' === $definition ) {
            return false;
        }
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            return false;
        }
        if ( esf_db_table_has_column( $table, $column ) ) {
            return true;
        }
        $table_escaped = esc_sql( $table );
        $sql = "ALTER TABLE `{$table_escaped}` ADD COLUMN `{$column}` {$definition}";
        if ( '' !== $after && esf_db_table_has_column( $table, $after ) ) {
            $sql .= " AFTER `{$after}`";
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $sql );
        return esf_db_table_has_column( $table, $column );
    }

}
/**
 * Absolute path to a shared Pro vendor asset (FancyBox, Owl, controls).
 *
 * These files live in `frontend/assets/premium/` so Freemius strips the
 * whole directory from free zips via `@fs_premium_only`.
 *
 * @since 6.7.10
 * @param string $filename File name only, e.g. `jquery.fancybox.min.js`.
 * @return string Absolute path, or empty when plugin constants are missing.
 */
function esf_premium_vendor_path(  $filename  ) {
    if ( !defined( 'FTA_PLUGIN_DIR' ) ) {
        return '';
    }
    $filename = basename( (string) $filename );
    if ( '' === $filename ) {
        return '';
    }
    return FTA_PLUGIN_DIR . 'frontend/assets/premium/' . $filename;
}

/**
 * Plugin URL for a shared Pro vendor asset.
 *
 * @since 6.7.10
 * @param string $filename File name only.
 * @return string URL, or empty when plugin constants are missing.
 */
function esf_premium_vendor_url(  $filename  ) {
    if ( !defined( 'FTA_PLUGIN_URL' ) ) {
        return '';
    }
    $filename = basename( (string) $filename );
    if ( '' === $filename ) {
        return '';
    }
    return FTA_PLUGIN_URL . 'frontend/assets/premium/' . $filename;
}

/**
 * Locate a plugin file that may use the Freemius `__premium_only` suffix.
 *
 * Checks `name__premium_only.ext` first, then `name.ext`, so callers can keep
 * using the logical basename (e.g. `carousel`) after a Pro file is renamed.
 *
 * @since 6.7.10
 * @param string $dir      Directory containing the file.
 * @param string $basename Name without extension.
 * @param string $ext      Extension without a leading dot.
 * @return string Absolute path, or empty when neither candidate exists.
 */
function esf_locate_premium_only_file(  $dir, $basename, $ext  ) {
    $dir = (string) $dir;
    $basename = (string) $basename;
    $ext = ltrim( (string) $ext, '.' );
    if ( '' === $dir || '' === $basename || '' === $ext ) {
        return '';
    }
    $dir = rtrim( $dir, '/\\' ) . '/';
    $candidates = array($dir . $basename . '__premium_only.' . $ext, $dir . $basename . '.' . $ext);
    foreach ( $candidates as $path ) {
        if ( file_exists( $path ) ) {
            return $path;
        }
    }
    return '';
}

/**
 * Locate a plugin asset and return both filesystem path and URL.
 *
 * @since 6.7.10
 * @param string $dir      Directory containing the file.
 * @param string $url      URL for that directory (trailing slash optional).
 * @param string $basename Name without extension.
 * @param string $ext      Extension without a leading dot.
 * @return array{path:string,url:string}
 */
function esf_locate_premium_only_asset(
    $dir,
    $url,
    $basename,
    $ext
) {
    $path = esf_locate_premium_only_file( $dir, $basename, $ext );
    if ( '' === $path ) {
        return array(
            'path' => '',
            'url'  => '',
        );
    }
    return array(
        'path' => $path,
        'url'  => rtrim( (string) $url, '/\\' ) . '/' . basename( $path ),
    );
}

/**
 * Attach a one-time FancyBox beforeClose handler that stops nested media.
 *
 * Pro lightboxes use FancyBox `type: 'html'` with nested `<iframe>` / `<video>`
 * players. FancyBox's HTML reset only pauses video/audio and does not blank
 * iframe src, so cross-origin players can keep playing audio after a quick
 * close. This registers a single document-level `beforeClose.fb` listener via
 * `wp_add_inline_script` on `jquery.fancybox.min` (no extra HTTP request).
 *
 * Safe to call multiple times; the print filter + static flag ensure the
 * inline script is added at most once, even when FancyBox is enqueued during
 * shortcode render after `wp_enqueue_scripts`.
 *
 * @since 6.7.9
 * @return void
 */
if ( !function_exists( 'esf_enqueue_fancybox_stop_media_script' ) ) {
    function esf_enqueue_fancybox_stop_media_script() {
        static $hooked = false;
        if ( $hooked ) {
            return;
        }
        $hooked = true;
        $attach = static function () {
            static $done = false;
            if ( $done ) {
                return;
            }
            if ( !function_exists( 'wp_script_is' ) || !function_exists( 'wp_add_inline_script' ) ) {
                return;
            }
            if ( !wp_script_is( 'jquery.fancybox.min', 'registered' ) && !wp_script_is( 'jquery.fancybox.min', 'enqueued' ) ) {
                return;
            }
            $done = true;
            /*
             * Keep this compact: runs on every Pro FancyBox close across modules.
             * Blank iframes first (cross-origin audio), then hard-stop HTML5 media.
             */
            $js = <<<'JS'
(function ($) {
	if (!$ || window.esfFancyboxStopMediaBound) {
		return;
	}
	window.esfFancyboxStopMediaBound = true;

	function esfStopFancyboxMedia(root) {
		if (!root || !root.querySelectorAll) {
			return;
		}
		var iframes = root.querySelectorAll("iframe");
		for (var i = 0; i < iframes.length; i++) {
			try {
				// Match FancyBox native iframe reset — blanking stops cross-origin audio.
				iframes[i].setAttribute("src", "about:blank");
			} catch (e) {}
		}
		var media = root.querySelectorAll("video,audio");
		for (var j = 0; j < media.length; j++) {
			var el = media[j];
			try {
				el.pause();
				el.muted = true;
				el.removeAttribute("src");
				while (el.firstChild) {
					el.removeChild(el.firstChild);
				}
				el.load();
			} catch (e) {}
		}
	}

	$(document).on("beforeClose.fb", function (_event, instance) {
		if (!instance) {
			return;
		}
		if (instance.opts) {
			instance.opts.esfClosed = true;
		}
		if (instance.$refs && instance.$refs.container && instance.$refs.container.length) {
			esfStopFancyboxMedia(instance.$refs.container.get(0));
			return;
		}
		if (instance.slides && instance.slides.length) {
			for (var s = 0; s < instance.slides.length; s++) {
				var slide = instance.slides[s];
				if (slide && slide.$slide && slide.$slide.length) {
					esfStopFancyboxMedia(slide.$slide.get(0));
				}
			}
		}
	});
})(window.jQuery);
JS;
            wp_add_inline_script( 'jquery.fancybox.min', $js, 'after' );
        };
        // Immediate attempt when the handle is already registered.
        $attach();
        /*
         * Shortcode / render paths enqueue FancyBox after wp_enqueue_scripts.
         * print_scripts_array runs immediately before a handle is printed.
         */
        add_filter( 'print_scripts_array', static function ( $todo ) use($attach) {
            if ( is_array( $todo ) && in_array( 'jquery.fancybox.min', $todo, true ) ) {
                $attach();
            }
            return $todo;
        }, 1 );
    }

}
/**
 * Enqueue shared FancyBox control CSS (theme/button resets) after FancyBox.
 *
 * Call immediately after enqueueing `jquery.fancybox.min` style so toolbar
 * and navigation buttons stay consistent across modern modules.
 *
 * @since 6.7.8
 * @return void
 */
if ( !function_exists( 'esf_enqueue_fancybox_controls_style' ) ) {
    function esf_enqueue_fancybox_controls_style() {
        $path = esf_premium_vendor_path( 'esf-fancybox-controls.css' );
        $url = esf_premium_vendor_url( 'esf-fancybox-controls.css' );
        if ( '' === $path || '' === $url || !file_exists( $path ) ) {
            return;
        }
        wp_enqueue_style(
            'esf-fancybox-controls',
            $url,
            array('jquery.fancybox.min'),
            (string) filemtime( $path )
        );
        // Same call sites as controls CSS — stop nested media audio on close (issue #299).
        if ( function_exists( 'esf_enqueue_fancybox_stop_media_script' ) ) {
            esf_enqueue_fancybox_stop_media_script();
        }
    }

}
/**
 * Resolve whether a render payload filter short-circuited cache/API fetch.
 *
 * Integrator hooks (`esf_*_render_*_payload`) may return either:
 *   - `array{ $items_key: array, has_local_cache?: bool }`, or
 *   - a flat list of item arrays/objects.
 *
 * @since 6.7.8
 *
 * @param mixed  $payload   Filter return value.
 * @param string $items_key Payload key for shaped arrays (`posts`, `tweets`, `videos`).
 * @return array{items:array<int,mixed>,short_circuited:bool,has_local_cache:bool}
 */
if ( !function_exists( 'esf_resolve_render_payload_override' ) ) {
    function esf_resolve_render_payload_override(  $payload, $items_key = 'items'  ) {
        $result = array(
            'items'           => array(),
            'short_circuited' => false,
            'has_local_cache' => false,
        );
        if ( !is_array( $payload ) ) {
            return $result;
        }
        $items_key = ( is_string( $items_key ) ? $items_key : 'items' );
        if ( array_key_exists( $items_key, $payload ) || array_key_exists( 'has_local_cache', $payload ) ) {
            $result['items'] = ( isset( $payload[$items_key] ) && is_array( $payload[$items_key] ) ? $payload[$items_key] : array() );
            $result['has_local_cache'] = !empty( $payload['has_local_cache'] );
            $result['short_circuited'] = !empty( $result['items'] );
            return $result;
        }
        $result['items'] = $payload;
        $result['short_circuited'] = !empty( $payload );
        return $result;
    }

}
/**
 * Whether the active DB connection charset supports 4-byte UTF-8 (emoji).
 *
 * @since 6.9.2
 * @return bool
 */
if ( !function_exists( 'esf_db_supports_utf8mb4' ) ) {
    function esf_db_supports_utf8mb4() {
        global $wpdb;
        $charset = ( isset( $wpdb ) && isset( $wpdb->charset ) ? strtolower( (string) $wpdb->charset ) : '' );
        return '' !== $charset && false !== strpos( $charset, 'utf8mb4' );
    }

}
/**
 * Make text/array/object values safe to persist on non-utf8mb4 databases.
 *
 * On utf8mb4 connections this is a no-op. On utf8 (3-byte) connections it
 * strips 4-byte UTF-8 characters (emoji / supplementary planes) so
 * {@see update_option()} and custom table writes do not fail with invalid
 * `option_value` / column data. Prefer stripping over {@see wp_encode_emoji()}
 * because admin/frontend often pass stored strings through {@see esc_html()}.
 *
 * @since 6.9.2
 *
 * @param mixed $value Scalar, array, or object to sanitize recursively.
 * @return mixed
 */
if ( !function_exists( 'esf_db_safe_text' ) ) {
    function esf_db_safe_text(  $value  ) {
        if ( esf_db_supports_utf8mb4() ) {
            return $value;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[$key] = esf_db_safe_text( $item );
            }
            return $value;
        }
        if ( is_object( $value ) ) {
            foreach ( get_object_vars( $value ) as $key => $item ) {
                $value->{$key} = esf_db_safe_text( $item );
            }
            return $value;
        }
        if ( !is_string( $value ) || '' === $value ) {
            return $value;
        }
        $clean = preg_replace( '/[\\x{10000}-\\x{10FFFF}]/u', '', $value );
        if ( !is_string( $clean ) ) {
            $clean = '';
        }
        if ( function_exists( 'wp_check_invalid_utf8' ) ) {
            $checked = wp_check_invalid_utf8( $clean, true );
            if ( is_string( $checked ) ) {
                $clean = $checked;
            }
        }
        return $clean;
    }

}