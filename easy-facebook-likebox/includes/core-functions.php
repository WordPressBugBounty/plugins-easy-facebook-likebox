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
 * Canonical Pro upgrade / pricing URL for all CTAs.
 *
 * Pass a module context so UTM `utm_content` tracks where the CTA came from.
 * Supported contexts: `instagram`, `facebook`, `youtube`, `twitter`, `general`.
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
            'general'
        );
        $context = sanitize_key( (string) $context );
        if ( !in_array( $context, $allowed, true ) ) {
            $context = 'general';
        }
        return add_query_arg( array(
            'utm_source'  => 'easy_social_feed',
            'utm_medium'  => 'wordpress_plugin_install',
            'utm_content' => $context,
        ), 'https://easysocialfeed.com/pricing/' );
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
 * Format social caption/description text into safe HTML with URLs and hashtags linked.
 *
 * @since 6.9.0
 *
 * @param string               $text     Raw caption text.
 * @param string               $platform Platform slug passed to {@see esf_hashtags_to_links()}.
 * @param array<string, mixed> $args     {
 *     @type array  $protocols       Protocols for {@see esf_convert_to_hyperlinks()}. Default http, https, mail.
 *     @type array  $link_attributes Anchor attributes for auto-linked URLs.
 *     @type array  $hashtag_args    Extra args for {@see esf_hashtags_to_links()}.
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
        $modules = apply_filters( 'esf_local_media_account_folder_modules', array('instagram', 'youtube', 'twitter') );
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
if ( !function_exists( 'esf_serve_media_locally' ) ) {
    /**
     * Download remote media into uploads/esf-{module} and return its local URL.
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
        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $image_data = wp_remote_retrieve_body( $response );
        if ( '' === $image_data ) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same pattern as legacy helper; uploads dir is writable.
        if ( false === file_put_contents( $file, $image_data ) ) {
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
if ( !function_exists( 'esf_ensure_local_media_variant__premium_only' ) ) {
}
if ( !function_exists( 'esf_serve_media_locally_sized' ) ) {
    /**
     * Serve media locally, optionally returning a resized variant URL.
     *
     * When $max_width is 0, behaves like esf_serve_media_locally(). When $max_width
     * is set, downloads the full image first, then creates/returns a variant via
     * esf_ensure_local_media_variant__premium_only() when that function exists.
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
        if ( function_exists( 'esf_ensure_local_media_variant__premium_only' ) ) {
            $variant = esf_ensure_local_media_variant__premium_only(
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
if ( !function_exists( 'esf_readable_time_ago' ) ) {
    /**
     * Convert a date/time string to a relative "time ago" string.
     *
     * Wrapper around human_time_diff() that accepts common date formats
     * and appends a translated "ago" suffix. Intended for feed items
     * (Facebook, Instagram, YouTube, etc.).
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
        $diff = human_time_diff( $timestamp, current_time( 'timestamp' ) );
        return sprintf( 
            /* translators: %s: human readable time difference, e.g. "2 hours" */
            __( '%s ago', 'easy-facebook-likebox' ),
            $diff
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
        $default_email = strtolower( sanitize_email( (string) get_option( 'admin_email' ) ) );
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
                        'is_default' => $email === $default_email,
                    );
                }
            }
        }
        if ( empty( $options ) && '' !== $default_email && is_email( $default_email ) ) {
            $options[] = array(
                'email'      => $default_email,
                'label'      => $default_email,
                'is_default' => true,
            );
        }
        return $options;
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
        if ( !defined( 'FTA_PLUGIN_DIR' ) || !defined( 'FTA_PLUGIN_URL' ) ) {
            return;
        }
        $path = FTA_PLUGIN_DIR . 'frontend/assets/css/esf-fancybox-controls.css';
        if ( !file_exists( $path ) ) {
            return;
        }
        wp_enqueue_style(
            'esf-fancybox-controls',
            FTA_PLUGIN_URL . 'frontend/assets/css/esf-fancybox-controls.css',
            array('jquery.fancybox.min'),
            (string) filemtime( $path )
        );
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