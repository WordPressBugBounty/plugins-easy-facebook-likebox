<?php

/**
 * Facebook Graph API client for Page content.
 *
 * Lists Pages and fetches feed, media, live videos, and pictures.
 * Classifies Graph errors and validates paging hosts.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Facebook_Graph_Client
 *
 * @since 6.7.9
 */
class ESF_Facebook_Graph_Client {
    /**
     * Default Graph API version.
     *
     * @var string
     */
    const API_VERSION = 'v18.0';

    /**
     * Default limit for nested `attachments.subattachments` on feed posts.
     *
     * 15 keeps the first page small for speed while leaving a 2-digit "+N"
     * room after Half Width’s 3-cell collage (or Full Width’s 5-cell grid).
     * Graph has no reliable `subattachments` total_count — collage uses
     * story/"added N photos" when present, otherwise an "N+ more" affordance
     * when paging continues. Pro loads further pages on demand in the lightbox.
     *
     * @since 6.7.9
     * @var int
     */
    const ALBUM_SUBATTACHMENTS_LIMIT = 15;

    /**
     * Graph fields for Page `/photos` and album `/{album-id}/photos` nodes.
     *
     * Photo nodes are not feed posts: caption is `name`, permalink is `link`.
     * Do not request Post-only fields (`permalink_url`, `message`, `reactions`) —
     * Graph rejects the whole request and the feed caches empty.
     *
     * @since 6.7.9
     * @var string
     */
    const PHOTO_EDGE_FIELDS = 'id,name,created_time,link,picture,images,from,album,likes.summary(true),comments.summary(true)';

    /**
     * Graph fields for Page `/videos` edge nodes.
     *
     * Exact previously working set — feed-style `reactions.type(LIKE).as(LIKE)`
     * forms made Graph reject `/videos` entirely ("No posts to display yet").
     * Caption comes from `description`/`title`; share from `permalink_url`
     * (watch-URL fallback in PostMapper when missing). Likes/comments use the
     * same `*.summary(true)` pattern as PHOTO_EDGE_FIELDS.
     *
     * @since 6.7.9
     * @var string
     */
    const VIDEO_EDGE_FIELDS = 'created_time,description,id,format,from,permalink_url,picture,source,title,status,likes.summary(true),comments.summary(true)';

    /**
     * Graph fields for Page `/events` edge nodes.
     *
     * Events use `description` as caption and a constructed facebook.com/events/{id} URL.
     * `interested_count` / `attending_count` stand in for engagement when likes are unavailable.
     *
     * @since 6.7.9
     * @var string
     */
    const EVENT_EDGE_FIELDS = 'id,name,description,start_time,end_time,place,cover,picture,ticket_uri,updated_time,interested_count,attending_count';

    /**
     * Graph fields for Page `/albums` edge nodes.
     *
     * Album filter renders these as feed cards (cover + name + photo count).
     * Lightbox loads photos via `/{album-id}/photos` (same Pro load-more path
     * as timeline multi-photo posts).
     *
     * @since 6.7.9
     * @var string
     */
    const ALBUM_EDGE_FIELDS = 'id,name,count,link,created_time,description,cover_photo{source},picture';

    /**
     * Graph API version (filterable).
     *
     * @since 6.7.9
     * @return string
     */
    public static function get_api_version() {
        $v = apply_filters( 'esf_facebook_graph_api_version', self::API_VERSION );
        $v = ( is_string( $v ) ? trim( $v ) : self::API_VERSION );
        if ( '' === $v || !preg_match( '/^v\\d+(\\.\\d+)*$/', $v ) ) {
            return self::API_VERSION;
        }
        return $v;
    }

    /**
     * Max subattachments requested with each feed / tagged post.
     *
     * @since 6.7.9
     * @return int Clamped 1–100.
     */
    public static function get_album_subattachments_limit() {
        $limit = (int) apply_filters( 'esf_facebook_album_subattachments_limit', self::ALBUM_SUBATTACHMENTS_LIMIT );
        return max( 1, min( 100, $limit ) );
    }

    /**
     * Default Graph fields for timeline / tagged feed posts.
     *
     * Includes `subattachments.limit(N)` so multi-photo albums are not silently
     * truncated to Graph's default page size (~12).
     *
     * @since 6.7.9
     * @return string
     */
    public static function get_feed_post_fields() {
        $sub_limit = self::get_album_subattachments_limit();
        return 'id,message,story,created_time,full_picture,permalink_url,status_type,attachments{media,type,url,title,description,unshimmed_url,target,subattachments.limit(' . $sub_limit . ')},shares,reactions.summary(true),reactions.type(LIKE).summary(true).as(LIKE),reactions.type(LOVE).summary(true).as(LOVE),reactions.type(CARE).summary(true).as(CARE),reactions.type(HAHA).summary(true).as(HAHA),reactions.type(WOW).summary(true).as(WOW),reactions.type(SAD).summary(true).as(SAD),reactions.type(ANGRY).summary(true).as(ANGRY),comments.summary(true)';
    }

    /**
     * Follow a Graph pagination URL (SSRF-safe host allowlist).
     *
     * Used by Pro album-photo load-more to page `subattachments.paging.next`.
     *
     * @since 6.7.9
     * @param string $url Absolute Graph paging URL.
     * @return array<string,mixed>|WP_Error
     */
    public static function fetch_paged_url( $url ) {
        $next = esc_url_raw( (string) $url );
        if ( '' === $next || !self::url_host_allowed_for_paging( $next ) ) {
            return new WP_Error('esf_fb_paging_blocked', __( 'Pagination URL host is not allowed.', 'easy-facebook-likebox' ));
        }
        return self::request_get( $next );
    }

    /**
     * Extract the opaque `after` cursor for continuing a Graph connection.
     *
     * Prefers `paging.cursors.after` — a short opaque token we can safely
     * replay against a **request we build ourselves** (same edge + fields +
     * limit, just adding `after=<cursor>`) — over `paging.next`, the absolute
     * URL Graph computed. Facebook's Graph API is documented (see
     * `facebook/DEPRECATED-API-FIX.md`) to sometimes emit that `next` URL in
     * a deprecated/malformed singular-ID format for `/feed` and other edges,
     * which 400s with `"(#12) singular statuses API is deprecated"`. Blindly
     * following it is what silently stalls Load More after one page; reusing
     * the cursor against a self-built request sidesteps the malformed URL
     * entirely (the same pattern already used by fetch_album_photos()).
     *
     * Only returns a cursor when Graph also advertises `paging.next`. An
     * `after` token alone is often present on the final page and must not
     * imply has_more (same rule as comments pagination).
     *
     * @since 6.7.9
     * @param array<string,mixed>|mixed $response Decoded Graph response.
     * @return string
     */
    public static function extract_after_cursor( $response ) {
        if ( !is_array( $response ) ) {
            return '';
        }
        // Graph often leaves `cursors.after` on the final page. Only treat an
        // after token as continuable when Graph also advertises `paging.next`
        // (same rule as comments pagination).
        $has_next = isset( $response['paging']['next'] ) && is_string( $response['paging']['next'] ) && '' !== trim( $response['paging']['next'] );
        if ( !$has_next ) {
            return '';
        }
        if ( isset( $response['paging']['cursors']['after'] ) && is_string( $response['paging']['cursors']['after'] ) ) {
            $after = trim( $response['paging']['cursors']['after'] );
            if ( '' !== $after ) {
                return $after;
            }
        }
        return '';
    }

    /**
     * Default on-demand `subattachments` cap for the Pro popup load-more's
     * direct per-post refetch (higher than the feed's small default page-load
     * cap). Meta's official docs don't document a hard ceiling for this
     * field's `limit()` — only community reports of ~50 being "commonly
     * reliable" — so this defaults to the same 100 hard sanity ceiling this
     * class already enforces on the filter, to close the gap on posts with
     * 50+ attached photos in a single request rather than silently stopping
     * short. Still a single, bounded, on-demand call — never eager, never
     * repeated per post (see `direct_fetch_done`).
     *
     * @since 6.7.9
     * @var int
     */
    const DIRECT_SUBATTACHMENTS_FETCH_LIMIT = 100;

    /**
     * Build absolute Graph URL.
     *
     * @since 6.7.9
     * @param string $path Relative path after version.
     * @return string
     */
    public static function graph_url( $path ) {
        $path = trim( (string) $path, '/' );
        return sprintf( 'https://graph.facebook.com/%s/%s', self::get_api_version(), $path );
    }

    /**
     * Only follow pagination URLs on Facebook graph hosts.
     *
     * @since 6.7.9
     * @param string $url Next URL from Graph.
     * @return bool
     */
    public static function url_host_allowed_for_paging( $url ) {
        $parts = wp_parse_url( $url );
        if ( !is_array( $parts ) || empty( $parts['host'] ) ) {
            return false;
        }
        $host = strtolower( (string) $parts['host'] );
        return 'graph.facebook.com' === $host || 'graph.fb.com' === $host;
    }

    /**
     * GET `me` profile (id + name) for the user represented by the token.
     *
     * @since 6.7.9
     * @param string $user_access_token User access token.
     * @return array{id:string,name:string}|WP_Error
     */
    public static function fetch_facebook_user( $user_access_token ) {
        $user_access_token = trim( (string) $user_access_token );
        if ( '' === $user_access_token ) {
            return new WP_Error('esf_fb_empty_token', __( 'Missing Facebook user token.', 'easy-facebook-likebox' ));
        }
        $url = add_query_arg( array(
            'fields'       => 'id,name',
            'access_token' => $user_access_token,
        ), self::graph_url( 'me' ) );
        $response = self::request_get( $url );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body = $response;
        if ( isset( $body['error'] ) || empty( $body['id'] ) ) {
            return self::wp_error_from_graph_body( $body, 'esf_fb_me_invalid', __( 'Facebook returned an unexpected response for this token.', 'easy-facebook-likebox' ) );
        }
        return array(
            'id'   => sanitize_text_field( (string) $body['id'] ),
            'name' => ( isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '' ),
        );
    }

    /**
     * GET `me` id for the user represented by the token.
     *
     * @since 6.7.9
     * @param string $user_access_token User access token.
     * @return string|WP_Error
     */
    public static function fetch_facebook_user_id( $user_access_token ) {
        $user = self::fetch_facebook_user( $user_access_token );
        if ( is_wp_error( $user ) ) {
            return $user;
        }
        return $user['id'];
    }

    /**
     * Field candidates for `me/accounts` (Pages only — no Instagram fields).
     *
     * @since 6.7.9
     * @return array<int,string>
     */
    private static function get_me_accounts_field_candidates() {
        $defaults = array(
            'id,name,username,link,access_token,category,fan_count,about,website,cover{source},picture.type(large){url}',
            'id,name,link,access_token,category,fan_count,about,website,cover{source},picture.type(large){url}',
            'id,name,username,link,access_token,category,fan_count,about,picture.type(large){url}',
            'id,name,link,access_token,category,fan_count,about,picture.type(large){url}',
            'id,name,access_token,category,fan_count,about,picture.type(large){url}',
            'id,name,access_token,category,fan_count,picture.type(large){url}',
            'id,name,access_token,category,fan_count',
            'id,name,access_token'
        );
        $filtered = apply_filters( 'esf_facebook_me_accounts_fields', $defaults );
        if ( !is_array( $filtered ) || array() === $filtered ) {
            return $defaults;
        }
        $out = array();
        foreach ( $filtered as $row ) {
            if ( is_string( $row ) && '' !== trim( $row ) ) {
                $out[] = trim( $row );
            }
        }
        return ( array() !== $out ? $out : $defaults );
    }

    /**
     * Parse `me/accounts` JSON into page candidate rows (tokens retained for staging).
     *
     * @since 6.7.9
     * @param array<string,mixed> $decoded Top-level JSON.
     * @return array<int,array<string,mixed>>
     */
    public static function parse_me_accounts_response( array $decoded ) {
        $rows = ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array() );
        $out = array();
        foreach ( $rows as $page ) {
            if ( !is_array( $page ) ) {
                continue;
            }
            $page_id = ( isset( $page['id'] ) ? sanitize_text_field( (string) $page['id'] ) : '' );
            $page_name = ( isset( $page['name'] ) ? sanitize_text_field( (string) $page['name'] ) : '' );
            $page_token = ( isset( $page['access_token'] ) ? sanitize_text_field( (string) $page['access_token'] ) : '' );
            if ( '' === $page_id || '' === $page_token ) {
                continue;
            }
            $pic = '';
            if ( isset( $page['picture']['data']['url'] ) ) {
                $pic = esc_url_raw( (string) $page['picture']['data']['url'] );
            } elseif ( isset( $page['picture']['url'] ) ) {
                $pic = esc_url_raw( (string) $page['picture']['url'] );
            }
            $out[] = array(
                'page_id'           => $page_id,
                'page_name'         => $page_name,
                'page_access_token' => $page_token,
                'username'          => self::extract_page_username( $page ),
                'page_link'         => self::extract_page_link( $page ),
                'category'          => self::extract_page_category( $page ),
                'about'             => self::extract_page_about( $page ),
                'fan_count'         => self::extract_page_fan_count( $page ),
                'website'           => self::extract_page_website( $page ),
                'cover_photo_url'   => self::extract_page_cover_url( $page ),
                'profile_image_url' => $pic,
            );
        }
        return $out;
    }

    /**
     * Extract Page username / vanity slug from a Graph Page node.
     *
     * Prefers Graph `username` when present; otherwise derives from `link`.
     *
     * @since 6.7.9
     * @param array<string,mixed> $page Page node.
     * @return string
     */
    public static function extract_page_username( array $page ) {
        if ( isset( $page['username'] ) && is_string( $page['username'] ) ) {
            $username = sanitize_text_field( $page['username'] );
            if ( '' !== $username && !ctype_digit( $username ) ) {
                return $username;
            }
        }
        $link = self::extract_page_link( $page );
        if ( '' === $link ) {
            return '';
        }
        return self::username_from_facebook_url( $link );
    }

    /**
     * Extract absolute Page URL from a Graph Page node.
     *
     * @since 6.7.9
     * @param array<string,mixed> $page Page node.
     * @return string
     */
    public static function extract_page_link( array $page ) {
        if ( !isset( $page['link'] ) || !is_string( $page['link'] ) ) {
            return '';
        }
        $link = esc_url_raw( trim( $page['link'] ) );
        return ( is_string( $link ) ? $link : '' );
    }

    /**
     * Derive a vanity username from a facebook.com / fb.com URL path.
     *
     * @since 6.7.9
     * @param string $url Absolute Facebook URL.
     * @return string Empty when path is numeric-only or a reserved Graph path.
     */
    public static function username_from_facebook_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }
        if ( !preg_match( '#^https?://#i', $url ) ) {
            $url = 'https://' . ltrim( $url, '/' );
        }
        $parts = ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        if ( !is_array( $parts ) || empty( $parts['path'] ) ) {
            return '';
        }
        $path = trim( (string) $parts['path'], '/' );
        if ( '' === $path ) {
            return '';
        }
        $segments = array_values( array_filter( explode( '/', $path ) ) );
        if ( array() === $segments ) {
            return '';
        }
        $first = strtolower( (string) $segments[0] );
        $skip = array(
            'pages',
            'profile.php',
            'people',
            'pg',
            'groups',
            'events',
            'watch',
            'story.php',
            'permalink.php'
        );
        if ( in_array( $first, $skip, true ) ) {
            // /pages/Page-Name/123456 → try Page-Name when not numeric.
            if ( 'pages' === $first && isset( $segments[1] ) ) {
                $candidate = sanitize_text_field( (string) $segments[1] );
                if ( '' !== $candidate && !ctype_digit( $candidate ) ) {
                    return $candidate;
                }
            }
            return '';
        }
        $candidate = sanitize_text_field( (string) $segments[0] );
        if ( '' === $candidate || ctype_digit( $candidate ) ) {
            return '';
        }
        return $candidate;
    }

    /**
     * Extract Page website URL from a Graph Page node.
     *
     * Graph may return a single URL or a comma-separated list — first valid URL wins.
     *
     * @since 6.7.9
     * @param array<string,mixed> $page Page node.
     * @return string Absolute http(s) URL or empty.
     */
    public static function extract_page_website( array $page ) {
        if ( !isset( $page['website'] ) || !is_string( $page['website'] ) ) {
            return '';
        }
        $raw = trim( $page['website'] );
        if ( '' === $raw ) {
            return '';
        }
        $parts = preg_split( '/\\s*,\\s*/', $raw );
        $first = ( is_array( $parts ) && isset( $parts[0] ) ? trim( (string) $parts[0] ) : $raw );
        if ( '' === $first ) {
            return '';
        }
        if ( !preg_match( '#^https?://#i', $first ) ) {
            $first = 'https://' . ltrim( $first, '/' );
        }
        $url = esc_url_raw( $first );
        if ( !is_string( $url ) || '' === $url || !preg_match( '#^https?://#i', $url ) ) {
            return '';
        }
        return $url;
    }

    /**
     * Extract Page cover photo source URL from a Graph Page node.
     *
     * @since 6.7.9
     * @param array<string,mixed> $page Page node.
     * @return string Absolute http(s) URL or empty.
     */
    public static function extract_page_cover_url( array $page ) {
        if ( !isset( $page['cover']['source'] ) || !is_string( $page['cover']['source'] ) ) {
            return '';
        }
        $url = esc_url_raw( trim( $page['cover']['source'] ) );
        if ( !is_string( $url ) || '' === $url || !preg_match( '#^https?://#i', $url ) ) {
            return '';
        }
        return $url;
    }

    /**
     * Extract category from a Graph Page node.
     *
     * @since 6.7.9
     * @param array<string,mixed> $page Page node.
     * @return string
     */
    private static function extract_page_category( array $page ) {
        if ( isset( $page['category'] ) ) {
            return sanitize_text_field( (string) $page['category'] );
        }
        if ( isset( $page['category_list'][0]['name'] ) ) {
            return sanitize_text_field( (string) $page['category_list'][0]['name'] );
        }
        return '';
    }

    /**
     * Extract short about/description from a Graph Page node.
     *
     * @since 6.7.9
     * @param array<string,mixed> $page Page node.
     * @return string
     */
    private static function extract_page_about( array $page ) {
        if ( isset( $page['about'] ) && is_string( $page['about'] ) ) {
            return sanitize_textarea_field( $page['about'] );
        }
        if ( isset( $page['description'] ) && is_string( $page['description'] ) ) {
            return sanitize_textarea_field( $page['description'] );
        }
        return '';
    }

    /**
     * Extract fan/follower count from a Graph Page node.
     *
     * @since 6.7.9
     * @param array<string,mixed> $page Page node.
     * @return int
     */
    private static function extract_page_fan_count( array $page ) {
        if ( isset( $page['fan_count'] ) ) {
            return max( 0, (int) $page['fan_count'] );
        }
        if ( isset( $page['followers_count'] ) ) {
            return max( 0, (int) $page['followers_count'] );
        }
        return 0;
    }

    /**
     * Paginate `me/accounts` and merge all Page candidates.
     *
     * @since 6.7.9
     * @param string $user_access_token User access token.
     * @return array<int,array<string,mixed>>|WP_Error
     */
    public static function fetch_all_page_candidates( $user_access_token ) {
        $user_access_token = trim( (string) $user_access_token );
        if ( '' === $user_access_token ) {
            return new WP_Error('esf_fb_empty_token', __( 'Missing Facebook user token.', 'easy-facebook-likebox' ));
        }
        $candidates = self::get_me_accounts_field_candidates();
        $field_index = 0;
        $allow_field_retry = true;
        $merged = array();
        $url = self::build_me_accounts_url( $user_access_token, $candidates[0] );
        while ( '' !== $url ) {
            $response = wp_remote_get( $url, array(
                'timeout' => 25,
            ) );
            if ( is_wp_error( $response ) ) {
                return self::classify_transport_error( $response );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $body, true );
            $has_err = is_array( $decoded ) && isset( $decoded['error'] );
            $bad_http = $code >= 400;
            if ( $bad_http || $has_err ) {
                if ( $allow_field_retry && $field_index + 1 < count( $candidates ) ) {
                    ++$field_index;
                    $url = self::build_me_accounts_url( $user_access_token, $candidates[$field_index] );
                    continue;
                }
                $err = self::wp_error_from_graph_body( ( is_array( $decoded ) ? $decoded : array() ), ( $has_err && !$bad_http ? 'esf_fb_accounts_invalid' : 'esf_fb_accounts_failed' ), __( 'Could not list Facebook Pages for this account.', 'easy-facebook-likebox' ) );
                return $err;
            }
            if ( !is_array( $decoded ) ) {
                return new WP_Error('esf_fb_accounts_invalid', __( 'Facebook returned an unexpected response when listing Pages.', 'easy-facebook-likebox' ), array(
                    'status' => 400,
                ));
            }
            $allow_field_retry = false;
            foreach ( self::parse_me_accounts_response( $decoded ) as $c ) {
                $merged[] = $c;
            }
            $url = '';
            if ( !empty( $decoded['paging']['next'] ) && is_string( $decoded['paging']['next'] ) ) {
                $next = esc_url_raw( $decoded['paging']['next'] );
                if ( '' !== $next && self::url_host_allowed_for_paging( $next ) ) {
                    $url = $next;
                }
            }
        }
        return $merged;
    }

    /**
     * Fetch Page posts (timeline) with optional paging next URL / cursor.
     *
     * @since 6.7.9
     * @since 6.7.9 Added `after` cursor support (preferred over `next_url`).
     * @param string $page_id           Facebook Page id.
     * @param string $page_access_token Page token.
     * @param array  $args              limit, fields, after, next_url.
     * @return array<string,mixed>|WP_Error Decoded Graph response.
     */
    public static function fetch_page_feed( $page_id, $page_access_token, array $args = array() ) {
        $page_id = sanitize_text_field( (string) $page_id );
        $page_access_token = trim( (string) $page_access_token );
        if ( '' === $page_id || '' === $page_access_token ) {
            return new WP_Error('esf_fb_feed_bad_args', __( 'Missing page id or token.', 'easy-facebook-likebox' ));
        }
        if ( !empty( $args['next_url'] ) && is_string( $args['next_url'] ) ) {
            $next = esc_url_raw( $args['next_url'] );
            if ( '' === $next || !self::url_host_allowed_for_paging( $next ) ) {
                return new WP_Error('esf_fb_paging_blocked', __( 'Pagination URL host is not allowed.', 'easy-facebook-likebox' ));
            }
            return self::request_get( $next );
        }
        $limit = ( isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 10 );
        $fields = ( isset( $args['fields'] ) && is_string( $args['fields'] ) && '' !== trim( $args['fields'] ) ? trim( $args['fields'] ) : self::get_feed_post_fields() );
        $query = array(
            'fields'       => $fields,
            'limit'        => $limit,
            'access_token' => $page_access_token,
        );
        if ( isset( $args['after'] ) && is_string( $args['after'] ) && '' !== trim( $args['after'] ) ) {
            $query['after'] = trim( $args['after'] );
        }
        $url = add_query_arg( $query, self::graph_url( $page_id . '/feed' ) );
        return self::request_get( $url );
    }

    /**
     * Fetch currently LIVE videos for a Page.
     *
     * @since 6.7.9
     * @param string $page_id           Facebook Page id.
     * @param string $page_access_token Page token.
     * @return array<string,mixed>|WP_Error
     */
    public static function fetch_live_videos( $page_id, $page_access_token ) {
        $page_id = sanitize_text_field( (string) $page_id );
        $page_access_token = trim( (string) $page_access_token );
        if ( '' === $page_id || '' === $page_access_token ) {
            return new WP_Error('esf_fb_live_bad_args', __( 'Missing page id or token.', 'easy-facebook-likebox' ));
        }
        $url = add_query_arg( array(
            'fields'           => 'id,status,permalink_url,embed_html,title,description,creation_time,broadcast_start_time,video{picture}',
            'broadcast_status' => '["LIVE"]',
            'access_token'     => $page_access_token,
        ), self::graph_url( $page_id . '/live_videos' ) );
        return self::request_get( $url );
    }

    /**
     * Parse a live_videos Graph response into a normalized LIVE entry (or null).
     *
     * @since 6.7.9
     * @param array<string,mixed>|WP_Error|mixed $response Graph response.
     * @return array{id:string,status:string,permalink_url:string,embed_html:string,title:string,description:string,picture_url:string,started_at:string}|null
     */
    public static function parse_live_videos_response( $response ) {
        if ( is_wp_error( $response ) || !is_array( $response ) ) {
            return null;
        }
        $rows = ( isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array() );
        foreach ( $rows as $row ) {
            if ( !is_array( $row ) ) {
                continue;
            }
            $id = ( isset( $row['id'] ) ? sanitize_text_field( (string) $row['id'] ) : '' );
            if ( '' === $id ) {
                continue;
            }
            $status = ( isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '' );
            if ( '' !== $status && 'live' !== $status ) {
                continue;
            }
            $picture_url = '';
            if ( isset( $row['video'] ) && is_array( $row['video'] ) && !empty( $row['video']['picture'] ) ) {
                $picture_url = esc_url_raw( (string) $row['video']['picture'] );
            } elseif ( !empty( $row['picture'] ) && is_string( $row['picture'] ) ) {
                $picture_url = esc_url_raw( (string) $row['picture'] );
            }
            return array(
                'id'            => $id,
                'status'        => ( '' !== $status ? $status : 'live' ),
                'permalink_url' => ( isset( $row['permalink_url'] ) ? esc_url_raw( (string) $row['permalink_url'] ) : '' ),
                'embed_html'    => ( isset( $row['embed_html'] ) ? (string) $row['embed_html'] : '' ),
                'title'         => ( isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '' ),
                'description'   => ( isset( $row['description'] ) ? sanitize_textarea_field( (string) $row['description'] ) : '' ),
                'picture_url'   => $picture_url,
                'started_at'    => self::resolve_live_started_at( $row ),
            );
        }
        return null;
    }

    /**
     * Resolve when a live broadcast started from Graph LiveVideo fields.
     *
     * Prefers `broadcast_start_time` (actual go-live) over `creation_time`.
     *
     * @since 6.7.9
     * @param array<string,mixed> $row LiveVideo node.
     * @return string ISO-8601 datetime or empty when unavailable.
     */
    private static function resolve_live_started_at( array $row ) {
        foreach ( array('broadcast_start_time', 'creation_time') as $field ) {
            if ( empty( $row[$field] ) || !is_string( $row[$field] ) ) {
                continue;
            }
            $raw = trim( (string) $row[$field] );
            if ( '' === $raw ) {
                continue;
            }
            $timestamp = strtotime( $raw );
            if ( $timestamp ) {
                return gmdate( 'c', $timestamp );
            }
        }
        return '';
    }

    /**
     * Fetch Page content by feed_type filter (timeline / Pro filters).
     *
     * Pagination: prefer `$args['after']` (an opaque `paging.cursors.after`
     * cursor) — it re-dispatches through the same feed_type switch below, so
     * the *same* edge/fields/type/time_filter as the first page are rebuilt
     * fresh with `after=<cursor>` appended, exactly like fetch_album_photos().
     * This never touches Graph's own absolute `next` URL, which Facebook is
     * documented to sometimes emit in a deprecated singular-ID format that
     * 400s (see `facebook/DEPRECATED-API-FIX.md`). `$args['next_url']` is kept
     * only for backward compatibility with cache rows written before 6.7.12
     * and still degrades to an empty page on that Graph error rather than a
     * hard failure.
     *
     * @since 6.7.9
     * @since 6.7.9 `after` cursor pagination (preferred); `next_url` now legacy-only.
     * @param string              $page_id           Facebook Page id.
     * @param string              $page_access_token Page token.
     * @param string              $feed_type         timeline|images|videos|events|albums|mentioned.
     * @param array<string,mixed> $args              limit, after, next_url (legacy), events_filter, album_id, fields.
     * @return array<string,mixed>|WP_Error
     */
    public static function fetch_page_content(
        $page_id,
        $page_access_token,
        $feed_type = 'timeline',
        array $args = array()
    ) {
        $feed_type = sanitize_key( (string) $feed_type );
        if ( '' === $feed_type ) {
            $feed_type = 'timeline';
        }
        if ( !empty( $args['next_url'] ) && is_string( $args['next_url'] ) ) {
            $next = esc_url_raw( $args['next_url'] );
            if ( '' === $next || !self::url_host_allowed_for_paging( $next ) ) {
                return new WP_Error('esf_fb_paging_blocked', __( 'Pagination URL host is not allowed.', 'easy-facebook-likebox' ));
            }
            $result = self::request_get( $next );
            // Legacy path only (see docblock) — Graph frequently returns
            // deprecated-format paging URLs (e.g., singular post IDs without
            // page_id prefix for v2.4+), triggering "(#12) singular statuses
            // API is deprecated" errors. Return an empty result rather than
            // propagating the error, allowing the feed to continue without
            // this page.
            if ( is_wp_error( $result ) ) {
                return array(
                    'data'   => array(),
                    'paging' => array(),
                );
            }
            return $result;
        }
        switch ( $feed_type ) {
            case 'images':
                $args['fields'] = ( isset( $args['fields'] ) ? $args['fields'] : self::PHOTO_EDGE_FIELDS );
                $args['type'] = 'uploaded';
                return self::fetch_page_edge(
                    $page_id,
                    $page_access_token,
                    'photos',
                    $args
                );
            case 'videos':
                $args['fields'] = ( isset( $args['fields'] ) ? $args['fields'] : self::VIDEO_EDGE_FIELDS );
                return self::fetch_page_edge(
                    $page_id,
                    $page_access_token,
                    'videos',
                    $args
                );
            case 'albums':
                // Empty album_id → Page album list cards. Set album_id → that album's photos.
                $album_id = ( isset( $args['album_id'] ) ? sanitize_text_field( (string) $args['album_id'] ) : '' );
                if ( '' !== $album_id && preg_match( '/^\\d+$/', $album_id ) ) {
                    $photo_args = $args;
                    $photo_args['fields'] = ( isset( $args['fields'] ) ? $args['fields'] : self::PHOTO_EDGE_FIELDS );
                    unset($photo_args['album_id'], $photo_args['type'], $photo_args['events_filter']);
                    return self::fetch_album_photos( $album_id, $page_access_token, $photo_args );
                }
                $args['fields'] = ( isset( $args['fields'] ) ? $args['fields'] : self::ALBUM_EDGE_FIELDS );
                unset($args['album_id'], $args['type']);
                return self::fetch_page_edge(
                    $page_id,
                    $page_access_token,
                    'albums',
                    $args
                );
            case 'events':
                $args['fields'] = ( isset( $args['fields'] ) ? $args['fields'] : self::EVENT_EDGE_FIELDS );
                $events_filter = ( isset( $args['events_filter'] ) ? sanitize_key( (string) $args['events_filter'] ) : '' );
                if ( in_array( $events_filter, array('upcoming', 'past'), true ) ) {
                    $args['time_filter'] = $events_filter;
                }
                return self::fetch_page_edge(
                    $page_id,
                    $page_access_token,
                    'events',
                    $args
                );
            case 'mentioned':
                $args['fields'] = ( isset( $args['fields'] ) ? $args['fields'] : self::get_feed_post_fields() );
                return self::fetch_page_edge(
                    $page_id,
                    $page_access_token,
                    'tagged',
                    $args
                );
            case 'timeline':
            default:
                return self::fetch_page_feed( $page_id, $page_access_token, $args );
        }
    }

    /**
     * Fetch photos for a specific album.
     *
     * The `/{album-id}/photos` edge is a normal top-level connection — unlike
     * nested `attachments{subattachments}`, it reliably returns `paging.next`
     * (or at least `paging.cursors.after`) and supports `summary=true` for an
     * authoritative `summary.total_count`. Used both by the Pro "albums" feed
     * filter and, as of 6.7.10, as the Pro popup load-more fallback once the
     * feed-cached `subattachments` window is exhausted.
     *
     * @since 6.7.9
     * @param string              $album_id          Facebook album id.
     * @param string              $page_access_token Page token.
     * @param array<string,mixed> $args              Query args: limit, fields, after (cursor), summary (bool).
     * @return array<string,mixed>|WP_Error
     */
    public static function fetch_album_photos( $album_id, $page_access_token, array $args = array() ) {
        $album_id = sanitize_text_field( (string) $album_id );
        $page_access_token = trim( (string) $page_access_token );
        if ( '' === $album_id || '' === $page_access_token ) {
            return new WP_Error('esf_fb_album_bad_args', __( 'Missing album id or token.', 'easy-facebook-likebox' ));
        }
        $limit = ( isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 10 );
        $fields = ( isset( $args['fields'] ) && is_string( $args['fields'] ) && '' !== trim( $args['fields'] ) ? trim( $args['fields'] ) : self::PHOTO_EDGE_FIELDS );
        $query = array(
            'fields'       => $fields,
            'limit'        => $limit,
            'access_token' => $page_access_token,
        );
        if ( isset( $args['after'] ) && is_string( $args['after'] ) && '' !== trim( $args['after'] ) ) {
            $query['after'] = trim( $args['after'] );
        }
        if ( !empty( $args['summary'] ) ) {
            $query['summary'] = 'true';
        }
        $url = add_query_arg( $query, self::graph_url( $album_id . '/photos' ) );
        return self::request_get( $url );
    }

    /**
     * Fetch a named Page edge (photos, videos, albums, events, tagged).
     *
     * @since 6.7.9
     * @since 6.7.9 Added `after` cursor support so every Pro filter edge can
     *               paginate the same reliable way as the feed/albums-photos edges.
     * @param string              $page_id           Facebook Page id.
     * @param string              $page_access_token Page token.
     * @param string              $edge              Edge name.
     * @param array<string,mixed> $args              Query args (limit, fields, type, time_filter, after).
     * @return array<string,mixed>|WP_Error
     */
    public static function fetch_page_edge(
        $page_id,
        $page_access_token,
        $edge,
        array $args = array()
    ) {
        $page_id = sanitize_text_field( (string) $page_id );
        $page_access_token = trim( (string) $page_access_token );
        $edge = sanitize_key( (string) $edge );
        $allowed_edges = array(
            'photos',
            'videos',
            'albums',
            'events',
            'tagged',
            'feed'
        );
        if ( '' === $page_id || '' === $page_access_token || !in_array( $edge, $allowed_edges, true ) ) {
            return new WP_Error('esf_fb_edge_bad_args', __( 'Missing page id, token, or invalid edge.', 'easy-facebook-likebox' ));
        }
        $limit = ( isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 10 );
        $fields = ( isset( $args['fields'] ) && is_string( $args['fields'] ) && '' !== trim( $args['fields'] ) ? trim( $args['fields'] ) : 'id' );
        $query = array(
            'fields'       => $fields,
            'limit'        => $limit,
            'access_token' => $page_access_token,
        );
        if ( isset( $args['type'] ) && is_string( $args['type'] ) && '' !== $args['type'] ) {
            $query['type'] = sanitize_key( $args['type'] );
        }
        if ( isset( $args['time_filter'] ) && is_string( $args['time_filter'] ) && '' !== $args['time_filter'] ) {
            $query['time_filter'] = sanitize_key( $args['time_filter'] );
        }
        if ( isset( $args['after'] ) && is_string( $args['after'] ) && '' !== trim( $args['after'] ) ) {
            $query['after'] = trim( $args['after'] );
        }
        $url = add_query_arg( $query, self::graph_url( $page_id . '/' . $edge ) );
        return self::request_get( $url );
    }

    /**
     * Fetch page picture URL.
     *
     * @since 6.7.9
     * @param string $page_id           Facebook Page id.
     * @param string $page_access_token Page token.
     * @return string|WP_Error
     */
    public static function fetch_page_picture_url( $page_id, $page_access_token ) {
        $page_id = sanitize_text_field( (string) $page_id );
        $page_access_token = trim( (string) $page_access_token );
        if ( '' === $page_id || '' === $page_access_token ) {
            return new WP_Error('esf_fb_picture_bad_args', __( 'Missing page id or token.', 'easy-facebook-likebox' ));
        }
        $url = add_query_arg( array(
            'redirect'     => '0',
            'type'         => 'large',
            'access_token' => $page_access_token,
        ), self::graph_url( $page_id . '/picture' ) );
        $body = self::request_get( $url );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        if ( !empty( $body['data']['url'] ) ) {
            return esc_url_raw( (string) $body['data']['url'] );
        }
        return '';
    }

    /**
     * Inspect a token via Graph `debug_token` (expiry / validity).
     *
     * Uses the token as both `input_token` and `access_token` so a pasted Page
     * token from the site owner's Facebook app can be inspected without our app secret.
     * Failures are returned as WP_Error; callers must not treat this as fatal when
     * the token already passed Events Graph probes.
     *
     * @since 6.7.9
     * @param string $access_token Access token to inspect.
     * @return array<string,mixed>|WP_Error Decoded Graph JSON (`data.expires_at`, …).
     */
    public static function inspect_access_token( $access_token ) {
        $access_token = trim( (string) $access_token );
        if ( '' === $access_token ) {
            return new WP_Error('esf_fb_empty_token', __( 'Missing access token.', 'easy-facebook-likebox' ));
        }
        $url = add_query_arg( array(
            'input_token'  => $access_token,
            'access_token' => $access_token,
        ), self::graph_url( 'debug_token' ) );
        return self::request_get( $url );
    }

    /**
     * Fetch Page profile fields used by the Accounts table (name, about, stats, picture).
     *
     * @since 6.7.9
     * @param string $page_id           Facebook Page id.
     * @param string $page_access_token Page token.
     * @return array{page_name:string,username:string,page_link:string,category:string,about:string,fan_count:int,website:string,cover_photo_url:string,profile_image_url:string}|WP_Error
     */
    public static function fetch_page_profile( $page_id, $page_access_token ) {
        $page_id = sanitize_text_field( (string) $page_id );
        $page_access_token = trim( (string) $page_access_token );
        if ( '' === $page_id || '' === $page_access_token ) {
            return new WP_Error('esf_fb_profile_bad_args', __( 'Missing page id or token.', 'easy-facebook-likebox' ));
        }
        $field_sets = array(
            'id,name,username,link,category,about,fan_count,website,cover{source},picture.type(large){url}',
            'id,name,link,category,about,fan_count,website,cover{source},picture.type(large){url}',
            'id,name,username,link,category,about,fan_count,picture.type(large){url}',
            'id,name,link,category,about,fan_count,picture.type(large){url}',
            'id,name,category,about,fan_count,picture.type(large){url}',
            'id,name,category,fan_count,picture.type(large){url}',
            'id,name,category,fan_count'
        );
        $body = null;
        foreach ( $field_sets as $fields ) {
            $url = add_query_arg( array(
                'fields'       => $fields,
                'access_token' => $page_access_token,
            ), self::graph_url( $page_id ) );
            $response = self::request_get( $url );
            if ( is_wp_error( $response ) ) {
                $body = $response;
                continue;
            }
            if ( isset( $response['error'] ) ) {
                $body = self::wp_error_from_graph_body( $response, 'esf_fb_page_profile_failed', __( 'Could not refresh Facebook Page details.', 'easy-facebook-likebox' ) );
                continue;
            }
            $body = $response;
            break;
        }
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        if ( !is_array( $body ) || empty( $body['id'] ) ) {
            return new WP_Error('esf_fb_page_profile_empty', __( 'Could not refresh Facebook Page details.', 'easy-facebook-likebox' ));
        }
        $pic = '';
        if ( isset( $body['picture']['data']['url'] ) ) {
            $pic = esc_url_raw( (string) $body['picture']['data']['url'] );
        } elseif ( isset( $body['picture']['url'] ) ) {
            $pic = esc_url_raw( (string) $body['picture']['url'] );
        }
        return array(
            'page_name'         => ( isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '' ),
            'username'          => self::extract_page_username( $body ),
            'page_link'         => self::extract_page_link( $body ),
            'category'          => self::extract_page_category( $body ),
            'about'             => self::extract_page_about( $body ),
            'fan_count'         => self::extract_page_fan_count( $body ),
            'website'           => self::extract_page_website( $body ),
            'cover_photo_url'   => self::extract_page_cover_url( $body ),
            'profile_image_url' => $pic,
        );
    }

    /**
     * Build first-page `me/accounts` URL.
     *
     * @since 6.7.9
     * @param string $user_access_token User token.
     * @param string $fields            Graph fields.
     * @return string
     */
    private static function build_me_accounts_url( $user_access_token, $fields ) {
        return add_query_arg( array(
            'fields'       => $fields,
            'access_token' => $user_access_token,
            'limit'        => 100,
        ), self::graph_url( 'me/accounts' ) );
    }

    /**
     * Perform a GET and decode JSON; classify Graph errors.
     *
     * @since 6.7.9
     * @param string $url Absolute URL.
     * @return array<string,mixed>|WP_Error
     */
    private static function request_get( $url ) {
        $response = wp_remote_get( $url, array(
            'timeout' => 25,
        ) );
        if ( is_wp_error( $response ) ) {
            return self::classify_transport_error( $response );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );
        if ( !is_array( $body ) ) {
            return new WP_Error('esf_fb_graph_invalid', __( 'Facebook returned an unexpected response.', 'easy-facebook-likebox' ), array(
                'status' => $code,
            ));
        }
        if ( $code >= 400 || isset( $body['error'] ) ) {
            return self::wp_error_from_graph_body( $body, 'esf_fb_graph_error', __( 'Facebook Graph request failed.', 'easy-facebook-likebox' ) );
        }
        return $body;
    }

    /**
     * Build WP_Error from Graph JSON, attaching classification data.
     *
     * @since 6.7.9
     * @param array<string,mixed> $body    Decoded body.
     * @param string              $code    Error code.
     * @param string              $fallback Fallback message.
     * @return WP_Error
     */
    private static function wp_error_from_graph_body( array $body, $code, $fallback ) {
        $msg = $fallback;
        $data = array(
            'status' => 400,
        );
        if ( isset( $body['error'] ) && is_array( $body['error'] ) ) {
            $data['detail'] = $body['error'];
            if ( !empty( $body['error']['message'] ) ) {
                $msg = sanitize_text_field( (string) $body['error']['message'] );
            }
            if ( class_exists( 'ESF_Meta_Graph_Error' ) ) {
                $data['esf_graph_action'] = ESF_Meta_Graph_Error::classify( $body['error'] );
            }
        }
        return new WP_Error($code, $msg, $data);
    }

    /**
     * Classify transport WP_Error via ESF_Meta_Graph_Error when possible.
     *
     * @since 6.7.9
     * @param WP_Error $error Transport error.
     * @return WP_Error
     */
    private static function classify_transport_error( WP_Error $error ) {
        if ( class_exists( 'ESF_Meta_Graph_Error' ) ) {
            $action = ESF_Meta_Graph_Error::classify_wp_error( $error );
            $data = $error->get_error_data();
            if ( !is_array( $data ) ) {
                $data = array();
            }
            $data['esf_graph_action'] = $action;
            return new WP_Error($error->get_error_code(), $error->get_error_message(), $data);
        }
        return $error;
    }

}
