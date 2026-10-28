<?php

/**
 * Facebook Graph post node → Post mapper.
 *
 * Normalizes Page feed posts (status, photo, album, video, live, shared/link,
 * events, mentioned, reels) into the canonical Post + PostMedia shape so
 * layouts never touch raw Graph attachment trees.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts
 * @since 6.7.9
 */
namespace EasySocialFeed\Facebook\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\Post;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Maps a Facebook Graph feed post to a canonical Post value object.
 *
 * @since 6.7.9
 */
final class PostMapper {
    /**
     * Convert a Graph Page feed node to a Post value object.
     *
     * Expected fields (defensive — all optional except id):
     *  - id, message, story, created_time, permalink_url, full_picture, status_type
     *  - attachments{media,type,url,title,description,subattachments,target}
     *  - shares, reactions.summary, comments.summary
     *
     * Nodes missing `id` are dropped (null). Malformed attachments fall back
     * to text-only (empty media), never a fatal.
     *
     * @param array<string,mixed> $node    Raw Graph post node.
     * @param AccountSummary|null $account Owning page (default author).
     */
    public static function from_graph_node( array $node, ?AccountSummary $account = null ) : ?Post {
        $id = ( isset( $node['id'] ) ? (string) $node['id'] : '' );
        if ( '' === $id ) {
            return null;
        }
        // Page `/events` edge nodes use name/start_time instead of feed fields.
        if ( self::is_events_edge_node( $node ) ) {
            return self::from_events_edge_node( $node, $account );
        }
        // Page `/albums` edge — album filter cards (cover + name + photo count).
        if ( self::is_albums_edge_node( $node ) ) {
            return self::from_albums_edge_node( $node, $account );
        }
        $caption = self::extract_caption( $node );
        $author_name = ( $account instanceof AccountSummary ? $account->get_name() : '' );
        $status_type = ( isset( $node['status_type'] ) ? (string) $node['status_type'] : '' );
        $attachment = self::primary_attachment( $node );
        $media_kind = self::resolve_media_kind( $node, $attachment, $status_type );
        $media = self::extract_media(
            $node,
            $attachment,
            $media_kind,
            $caption,
            $author_name
        );
        $extras = self::build_extras(
            $node,
            $attachment,
            $media_kind,
            $status_type,
            $media,
            $author_name
        );
        return new Post(array(
            'id'         => $id,
            'permalink'  => self::extract_permalink( $node, $account ),
            'created_at' => ( isset( $node['created_time'] ) ? (string) $node['created_time'] : '' ),
            'text_html'  => self::format_caption_html( $caption ),
            'author'     => $account,
            'media'      => $media,
            'metrics'    => self::extract_metrics( $node ),
            'extras'     => $extras,
        ));
    }

    /**
     * Whether the node is a Page Events edge object (not a feed post).
     *
     * @param array<string,mixed> $node Graph node.
     * @return bool
     */
    private static function is_events_edge_node( array $node ) : bool {
        if ( !isset( $node['start_time'] ) || !isset( $node['name'] ) ) {
            return false;
        }
        if ( isset( $node['status_type'] ) || isset( $node['attachments'] ) || isset( $node['message'] ) ) {
            return false;
        }
        return true;
    }

    /**
     * Map a Page `/events` edge node into a Post with event link_card extras.
     *
     * @param array<string,mixed> $node    Event node.
     * @param AccountSummary|null $account Owning page.
     * @return Post|null
     */
    private static function from_events_edge_node( array $node, ?AccountSummary $account = null ) : ?Post {
        $id = ( isset( $node['id'] ) ? (string) $node['id'] : '' );
        if ( '' === $id ) {
            return null;
        }
        $title = ( isset( $node['name'] ) ? trim( (string) $node['name'] ) : '' );
        $description = ( isset( $node['description'] ) ? trim( (string) $node['description'] ) : '' );
        $permalink = 'https://www.facebook.com/events/' . rawurlencode( $id );
        // Header "X ago" must reflect when the Page published/updated the event
        // listing (`updated_time`), not the event's future `start_time` (shown on the card).
        $created_at = ( isset( $node['updated_time'] ) ? (string) $node['updated_time'] : '' );
        if ( '' === $created_at && isset( $node['start_time'] ) ) {
            $created_at = (string) $node['start_time'];
        }
        $cover_url = '';
        if ( isset( $node['cover']['source'] ) && is_string( $node['cover']['source'] ) ) {
            $cover_url = trim( $node['cover']['source'] );
        } elseif ( isset( $node['picture']['data']['url'] ) && is_string( $node['picture']['data']['url'] ) ) {
            $cover_url = trim( $node['picture']['data']['url'] );
        } elseif ( isset( $node['picture'] ) && is_string( $node['picture'] ) ) {
            $cover_url = trim( $node['picture'] );
        }
        $author_name = ( $account instanceof AccountSummary ? $account->get_name() : '' );
        $media = array();
        if ( '' !== $cover_url && preg_match( '#^https?://#i', $cover_url ) ) {
            $tuple = array(
                'type'        => PostMedia::TYPE_PHOTO,
                'url'         => $cover_url,
                'preview_url' => $cover_url,
            );
            $media[] = self::apply_media_alt(
                $tuple,
                $description,
                $author_name,
                'photo'
            );
        }
        // Caption column already shows `$description` — keep the strip to
        // title / when / where / ticket+map only (no repeated body copy).
        $link_card = self::build_link_card_from_event_fields(
            $node,
            $title,
            '',
            $permalink
        );
        $metrics = self::extract_metrics( $node );
        if ( 0 === $metrics['likes'] ) {
            if ( isset( $node['interested_count'] ) ) {
                $metrics['likes'] = (int) $node['interested_count'];
            } elseif ( isset( $node['attending_count'] ) ) {
                $metrics['likes'] = (int) $node['attending_count'];
            }
        }
        return new Post(array(
            'id'         => $id,
            'permalink'  => $permalink,
            'created_at' => $created_at,
            'text_html'  => self::format_caption_html( $description ),
            'author'     => $account,
            'media'      => $media,
            'metrics'    => $metrics,
            'extras'     => array(
                'status_type'    => 'created_event',
                'media_type'     => 'EVENT',
                'is_live'        => false,
                'is_reel'        => false,
                'content_source' => 'event',
                'link_card'      => $link_card,
            ),
        ));
    }

    /**
     * Whether the node is a Page `/albums` edge object.
     *
     * @since 6.7.9
     * @param array<string,mixed> $node Graph node.
     * @return bool
     */
    private static function is_albums_edge_node( array $node ) : bool {
        if ( isset( $node['status_type'] ) || isset( $node['attachments'] ) || isset( $node['message'] ) || isset( $node['full_picture'] ) || isset( $node['source'] ) || isset( $node['start_time'] ) || isset( $node['images'] ) ) {
            return false;
        }
        if ( !isset( $node['name'] ) || !is_string( $node['name'] ) || '' === trim( (string) $node['name'] ) ) {
            return false;
        }
        if ( !isset( $node['count'] ) || !is_numeric( $node['count'] ) ) {
            return false;
        }
        // Albums list cards include album-edge markers. Photos-edge nodes can
        // have `name` (caption) but should not match without these.
        return isset( $node['cover_photo'] ) || isset( $node['link'] ) && is_string( $node['link'] );
    }

    /**
     * Map a Page `/albums` edge node into a Post card.
     *
     * @since 6.7.9
     * @param array<string,mixed> $node    Album node.
     * @param AccountSummary|null $account Owning page.
     * @return Post|null
     */
    private static function from_albums_edge_node( array $node, ?AccountSummary $account = null ) : ?Post {
        $id = ( isset( $node['id'] ) ? sanitize_text_field( (string) $node['id'] ) : '' );
        if ( '' === $id || !preg_match( '/^\\d+$/', $id ) ) {
            return null;
        }
        $title = ( isset( $node['name'] ) ? trim( (string) $node['name'] ) : '' );
        $description = ( isset( $node['description'] ) && is_string( $node['description'] ) ? trim( (string) $node['description'] ) : '' );
        $photo_count = ( isset( $node['count'] ) ? max( 0, (int) $node['count'] ) : 0 );
        $created_at = ( isset( $node['created_time'] ) ? (string) $node['created_time'] : '' );
        $permalink = '';
        if ( isset( $node['link'] ) && is_string( $node['link'] ) ) {
            $candidate = trim( (string) $node['link'] );
            if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
                $permalink = $candidate;
            }
        }
        if ( '' === $permalink ) {
            $permalink = 'https://www.facebook.com/' . rawurlencode( $id );
        }
        $cover_url = '';
        if ( isset( $node['cover_photo']['source'] ) && is_string( $node['cover_photo']['source'] ) ) {
            $cover_url = trim( (string) $node['cover_photo']['source'] );
        } elseif ( isset( $node['picture']['data']['url'] ) && is_string( $node['picture']['data']['url'] ) ) {
            $cover_url = trim( (string) $node['picture']['data']['url'] );
        } elseif ( isset( $node['picture'] ) && is_string( $node['picture'] ) ) {
            $cover_url = trim( (string) $node['picture'] );
        }
        $author_name = ( $account instanceof AccountSummary ? $account->get_name() : '' );
        $media = array();
        if ( '' !== $cover_url && preg_match( '#^https?://#i', $cover_url ) ) {
            $media[] = self::apply_media_alt(
                array(
                    'type'        => PostMedia::TYPE_PHOTO,
                    'url'         => $cover_url,
                    'preview_url' => $cover_url,
                ),
                ( $title !== '' ? $title : $description ),
                $author_name,
                'photo'
            );
        }
        $link_card = array(
            'kind'        => 'album',
            'title'       => $title,
            'description' => $description,
            'url'         => $permalink,
            'photo_count' => $photo_count,
        );
        return new Post(array(
            'id'         => $id,
            'permalink'  => $permalink,
            'created_at' => $created_at,
            'text_html'  => self::format_caption_html( $description ),
            'author'     => $account,
            'media'      => $media,
            'metrics'    => array(
                'likes'    => 0,
                'comments' => 0,
                'shares'   => 0,
            ),
            'extras'     => array(
                'status_type'       => 'album',
                'media_type'        => 'ALBUM',
                'is_live'           => false,
                'is_reel'           => false,
                'content_source'    => 'album_list',
                'album_id'          => $id,
                'album_total_count' => $photo_count,
                'album_has_more'    => $photo_count > count( $media ),
                'album_list'        => true,
                'link_card'         => $link_card,
            ),
        ));
    }

    /**
     * Caption is the user-written text for the card body.
     *
     * Sources by Graph shape:
     * - Feed / tagged: `message`
     * - Videos edge: `description` (then `title` / `name`)
     * - Photos edge: `name`
     *
     * Graph `story` ("Page created an event.") is authorship status, not caption —
     * it is mapped to extras.story_status for the header name suffix.
     *
     * @param array<string,mixed> $node Graph post.
     * @return string
     */
    private static function extract_caption( array $node ) : string {
        if ( isset( $node['message'] ) && is_string( $node['message'] ) && '' !== trim( $node['message'] ) ) {
            return trim( $node['message'] );
        }
        // Videos edge — description is the caption; title/name are the video title.
        if ( isset( $node['description'] ) && is_string( $node['description'] ) && '' !== trim( $node['description'] ) ) {
            return trim( $node['description'] );
        }
        if ( isset( $node['title'] ) && is_string( $node['title'] ) && '' !== trim( $node['title'] ) ) {
            return trim( $node['title'] );
        }
        // Photos-edge nodes (images / album filters) store the caption in `name`.
        if ( isset( $node['name'] ) && is_string( $node['name'] ) && '' !== trim( $node['name'] ) ) {
            return trim( $node['name'] );
        }
        return '';
    }

    /**
     * Status fragment shown after the page name (e.g. "created an event.").
     *
     * Strips a leading page-name prefix from Graph `story` so the header can
     * render "Innovations created an event." as name + suffix.
     *
     * @param array<string,mixed> $node        Graph post.
     * @param string              $author_name Page display name.
     * @return string
     */
    private static function extract_story_status( array $node, string $author_name ) : string {
        if ( !isset( $node['story'] ) || !is_string( $node['story'] ) ) {
            return '';
        }
        $story = trim( $node['story'] );
        if ( '' === $story ) {
            return '';
        }
        $name = trim( $author_name );
        if ( '' !== $name && 0 === stripos( $story, $name ) ) {
            $story = trim( substr( $story, strlen( $name ) ) );
        }
        return $story;
    }

    /**
     * Best-effort album photo total (Graph has no subattachments.summary).
     *
     * Prefer attachment `target.count` when present, else parse Graph `story`
     * ("… added 25 new photos"). Returns 0 when unknown.
     *
     * Public (not just used by feed-render `build_extras()`): the Pro album
     * load-more endpoint reuses this as an authoritative, **post-specific**
     * ceiling — critical because `attachment.target.id` can resolve to a
     * shared/recurring Page album (e.g. "Timeline Photos") whose own
     * `/photos?summary=true` total reflects the *entire album's* history, not
     * just this post. Without this cap, paginating that edge can pull in
     * unrelated photos from other posts indefinitely.
     *
     * @since 6.7.9
     * @since 6.7.9 Made public; `$mapped` now optional (pass 0 for the raw
     *               declared total, uncoupled from how many items are mapped
     *               so far).
     *
     * @param array<string,mixed>      $node       Graph post.
     * @param array<string,mixed>|null $attachment Primary attachment.
     * @param int                      $mapped     Mapped media count so far (0 = no gating).
     * @return int
     */
    public static function resolve_album_total_count( array $node, ?array $attachment, int $mapped = 0 ) : int {
        if ( null !== $attachment && isset( $attachment['target']['count'] ) ) {
            $count = (int) $attachment['target']['count'];
            if ( $count > 0 && $count >= $mapped ) {
                return $count;
            }
        }
        $story = ( isset( $node['story'] ) && is_string( $node['story'] ) ? (string) $node['story'] : '' );
        $from_story = self::extract_album_count_from_story( $story );
        if ( $from_story > 0 && $from_story >= $mapped ) {
            return $from_story;
        }
        return 0;
    }

    /**
     * Parse "added 25 new photos" / "added a new photo" from Graph story text.
     *
     * @param string $story Raw story.
     * @return int
     */
    private static function extract_album_count_from_story( string $story ) : int {
        $story = trim( $story );
        if ( '' === $story ) {
            return 0;
        }
        if ( preg_match( '/\\badded\\s+(\\d+)\\s+new\\s+photos?\\b/i', $story, $matches ) ) {
            return max( 0, (int) $matches[1] );
        }
        if ( preg_match( '/\\badded\\s+a\\s+new\\s+photo\\b/i', $story ) ) {
            return 1;
        }
        return 0;
    }

    /**
     * MediaCollage / MediaTile options for album count display.
     *
     * Exact total when known; otherwise mapped count with `more_is_minimum`
     * so the collage can show "7+ more" when further Graph pages exist.
     *
     * @param array<string,mixed> $extras       Post extras.
     * @param int                 $mapped_count Mapped media items.
     * @return array{total_count:int,extra_count:int,more_is_minimum:bool}
     */
    public static function album_media_options_from_extras( array $extras, int $mapped_count ) : array {
        $mapped_count = max( 0, $mapped_count );
        $total = ( isset( $extras['album_total_count'] ) ? (int) $extras['album_total_count'] : 0 );
        if ( $total <= 0 && isset( $extras['carousel_count'] ) ) {
            $total = (int) $extras['carousel_count'];
        }
        if ( $total <= 0 ) {
            $total = $mapped_count;
        }
        $is_minimum = !empty( $extras['album_count_is_minimum'] ) || !empty( $extras['album_has_more'] ) && empty( $extras['album_total_count'] );
        return array(
            'total_count'     => $total,
            'extra_count'     => max( 0, $total - 1 ),
            'more_is_minimum' => $is_minimum,
        );
    }

    /**
     * @param string $caption Raw caption.
     * @return string
     */
    private static function format_caption_html( string $caption ) : string {
        if ( '' === $caption ) {
            return '';
        }
        if ( function_exists( 'esf_facebook_format_caption_html' ) ) {
            return esf_facebook_format_caption_html( $caption );
        }
        return wpautop( make_clickable( esc_html( $caption ) ) );
    }

    /**
     * Resolve a public Facebook URL for View on Facebook / share / lightbox.
     *
     * Mobile note: tapping a facebook.com link on a phone usually opens the
     * Facebook app via Universal / App Links. Graph’s own `permalink_url` is
     * unreliable there — it may use a legacy/migrated numeric page id that
     * desktop browsers redirect, while the app shows “This isn’t available”.
     * Bare `facebook.com/{pageId}_{postId}` and opaque `pfbid…` links fail
     * the same way.
     *
     * For feed posts with a composite Graph id (`{pageId}_{postId}`), always
     * build `/{pageId|vanity}/posts/{postId}/` from that id (plus the Page
     * vanity username when we have one). Never treat an external share `link`
     * (cnn.com, etc.) as the post permalink.
     *
     * @param array<string,mixed> $node    Graph post.
     * @param AccountSummary|null $account Owning page (vanity username).
     * @return string
     */
    private static function extract_permalink( array $node, ?AccountSummary $account = null ) : string {
        $id = ( isset( $node['id'] ) ? sanitize_text_field( (string) $node['id'] ) : '' );
        $stable = self::permalink_from_composite_graph_id( $id, $account );
        // Composite feed posts: always prefer the id-derived URL so we never
        // ship Graph’s mismatched legacy page id in the path.
        if ( '' !== $stable ) {
            return $stable;
        }
        $from_graph = '';
        foreach ( array('permalink_url', 'link') as $key ) {
            if ( !isset( $node[$key] ) || !is_string( $node[$key] ) ) {
                continue;
            }
            $url = self::absolutize_facebook_permalink_candidate( trim( (string) $node[$key] ) );
            if ( '' === $url || !self::is_facebook_public_url( $url ) ) {
                continue;
            }
            $from_graph = $url;
            break;
        }
        if ( '' !== $from_graph ) {
            return self::rewrite_bare_composite_facebook_url( $from_graph );
        }
        if ( '' === $id ) {
            return '';
        }
        // Videos edge often omits permalink_url.
        if ( self::is_videos_edge_node( $node ) ) {
            return 'https://www.facebook.com/watch/?v=' . rawurlencode( $id );
        }
        // Photos edge (images / album filters) — share menu needs a URL.
        if ( self::is_photos_edge_node( $node ) ) {
            return 'https://www.facebook.com/photo/?fbid=' . rawurlencode( $id );
        }
        return '';
    }

    /**
     * Build a mobile-app-safe post URL from a Page feed composite Graph id.
     *
     * @since 6.9.0
     * @param string              $id      Graph object id (`{pageId}_{postId}`).
     * @param AccountSummary|null $account Owning page (optional vanity slug).
     * @return string Empty when the id is not a numeric composite.
     */
    private static function permalink_from_composite_graph_id( string $id, ?AccountSummary $account = null ) : string {
        if ( !preg_match( '/^(\\d+)_(\\d+)$/', $id, $matches ) ) {
            return '';
        }
        $page_id = $matches[1];
        $post_id = $matches[2];
        $slug = self::resolve_page_slug_for_permalink( $page_id, $account );
        return 'https://www.facebook.com/' . rawurlencode( $slug ) . '/posts/' . rawurlencode( $post_id ) . '/';
    }

    /**
     * Prefer a non-numeric Page vanity username for deep links when available.
     *
     * @since 6.9.0
     * @param string              $page_id Graph Page id from the composite post id.
     * @param AccountSummary|null $account Owning page summary.
     * @return string Vanity username or the numeric page id.
     */
    private static function resolve_page_slug_for_permalink( string $page_id, ?AccountSummary $account = null ) : string {
        if ( !$account instanceof AccountSummary ) {
            return $page_id;
        }
        $candidates = array($account->get_extra( 'username', '' ), $account->get_handle());
        foreach ( $candidates as $candidate ) {
            $candidate = trim( (string) $candidate );
            if ( '' === $candidate || ctype_digit( $candidate ) || $candidate === $page_id ) {
                continue;
            }
            // Reject path-like or reserved Graph path segments.
            if ( !preg_match( '/^[A-Za-z0-9.]+$/', $candidate ) ) {
                continue;
            }
            return $candidate;
        }
        return $page_id;
    }

    /**
     * Turn Graph permalink candidates into absolute https URLs.
     *
     * Videos-edge `permalink_url` is often a path only (`/Page/videos/123/`);
     * legacy templates prepend `https://www.facebook.com` for that reason.
     *
     * @since 6.9.0
     * @param string $url Raw Graph `permalink_url` or `link`.
     * @return string Absolute URL or empty when unusable.
     */
    private static function absolutize_facebook_permalink_candidate( string $url ) : string {
        if ( '' === $url ) {
            return '';
        }
        // Path-only Facebook permalinks.
        if ( '/' === $url[0] && 0 !== strpos( $url, '//' ) ) {
            $url = 'https://www.facebook.com' . $url;
        } elseif ( 0 === strpos( $url, '//' ) ) {
            $url = 'https:' . $url;
        } elseif ( preg_match( '#^(www\\.)?(facebook|fb)\\.com/#i', $url ) ) {
            $url = 'https://' . ltrim( $url, '/' );
        }
        if ( !preg_match( '#^https?://#i', $url ) ) {
            return '';
        }
        return $url;
    }

    /**
     * Whether a URL points at a Facebook / FB host (not an external share target).
     *
     * @since 6.9.0
     * @param string $url Absolute URL.
     * @return bool
     */
    private static function is_facebook_public_url( string $url ) : bool {
        $host = ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        if ( !is_string( $host ) || '' === $host ) {
            return false;
        }
        $host = strtolower( $host );
        if ( in_array( $host, array('facebook.com', 'fb.com', 'fb.watch'), true ) ) {
            return true;
        }
        return (bool) preg_match( '#(?:^|\\.)facebook\\.com$#', $host ) || (bool) preg_match( '#(?:^|\\.)fb\\.com$#', $host );
    }

    /**
     * Rewrite bare `facebook.com/{pageId}_{postId}` to `/{pageId}/posts/{postId}/`.
     *
     * @since 6.9.0
     * @param string $url Absolute Facebook URL.
     * @return string
     */
    private static function rewrite_bare_composite_facebook_url( string $url ) : string {
        if ( !preg_match( '~^https?://(?:www\\.)?(?:facebook|fb)\\.com/(\\d+)_(\\d+)/?(?:[?#].*)?$~i', $url, $matches ) ) {
            return $url;
        }
        return 'https://www.facebook.com/' . rawurlencode( $matches[1] ) . '/posts/' . rawurlencode( $matches[2] ) . '/';
    }

    /**
     * Whether the node is a Page `/photos` or album photos edge object.
     *
     * @param array<string,mixed> $node Graph node.
     * @return bool
     */
    private static function is_photos_edge_node( array $node ) : bool {
        if ( isset( $node['status_type'] ) || isset( $node['attachments'] ) || isset( $node['full_picture'] ) || isset( $node['start_time'] ) || self::is_videos_edge_node( $node ) ) {
            return false;
        }
        if ( isset( $node['images'] ) && is_array( $node['images'] ) && array() !== $node['images'] ) {
            return true;
        }
        return isset( $node['picture'] ) && is_string( $node['picture'] ) && '' !== trim( $node['picture'] );
    }

    /**
     * @param array<string,mixed> $node Graph post.
     * @return array{likes:int,comments:int,shares:int}
     */
    private static function extract_metrics( array $node ) : array {
        $likes = 0;
        if ( isset( $node['reactions']['summary']['total_count'] ) ) {
            $likes = (int) $node['reactions']['summary']['total_count'];
        } elseif ( isset( $node['likes']['summary']['total_count'] ) ) {
            $likes = (int) $node['likes']['summary']['total_count'];
        }
        $comments = 0;
        if ( isset( $node['comments']['summary']['total_count'] ) ) {
            $comments = (int) $node['comments']['summary']['total_count'];
        }
        $shares = 0;
        if ( isset( $node['shares']['count'] ) ) {
            $shares = (int) $node['shares']['count'];
        } elseif ( isset( $node['shares'] ) && is_numeric( $node['shares'] ) ) {
            $shares = (int) $node['shares'];
        }
        return array(
            'likes'    => $likes,
            'comments' => $comments,
            'shares'   => $shares,
        );
    }

    /**
     * Extract Facebook reaction type breakdown.
     *
     * Graph fields use `.as(LIKE)` aliases as **sibling** keys on the post node
     * (LIKE, LOVE, …), not nested under `reactions`. Also accepts nested maps
     * and falls back to a single LIKE count from reactions.summary.
     *
     * @since 6.7.9
     * @param array<string,mixed> $node Graph post.
     * @return array<string,int>
     */
    private static function extract_reaction_types( array $node ) : array {
        $known = array(
            'LIKE',
            'LOVE',
            'CARE',
            'HAHA',
            'WOW',
            'SAD',
            'ANGRY'
        );
        $reactions = array();
        foreach ( $known as $type ) {
            if ( !isset( $node[$type] ) || !is_array( $node[$type] ) ) {
                continue;
            }
            if ( !isset( $node[$type]['summary']['total_count'] ) ) {
                continue;
            }
            $count = (int) $node[$type]['summary']['total_count'];
            if ( $count > 0 ) {
                $reactions[$type] = $count;
            }
        }
        if ( isset( $node['reactions'] ) && is_array( $node['reactions'] ) ) {
            foreach ( $node['reactions'] as $key => $value ) {
                if ( !is_array( $value ) || !isset( $value['summary']['total_count'] ) ) {
                    continue;
                }
                $type = strtoupper( (string) $key );
                if ( '' === $type || in_array( $type, array('DATA', 'SUMMARY', 'PAGING'), true ) ) {
                    continue;
                }
                $count = (int) $value['summary']['total_count'];
                if ( $count > 0 ) {
                    $reactions[$type] = $count;
                }
            }
        }
        if ( array() === $reactions && isset( $node['reactions']['summary']['total_count'] ) ) {
            $total = (int) $node['reactions']['summary']['total_count'];
            if ( $total > 0 ) {
                $reactions['LIKE'] = $total;
            }
        }
        // Photos / legacy edges may only expose likes.summary — still seed a LIKE
        // so the LinkedIn-style reaction strip can render instead of a generic heart.
        if ( array() === $reactions && isset( $node['likes']['summary']['total_count'] ) ) {
            $total = (int) $node['likes']['summary']['total_count'];
            if ( $total > 0 ) {
                $reactions['LIKE'] = $total;
            }
        }
        return $reactions;
    }

    /**
     * First attachment object, or null when missing/malformed.
     *
     * @param array<string,mixed> $node Graph post.
     * @return array<string,mixed>|null
     */
    private static function primary_attachment( array $node ) : ?array {
        if ( !isset( $node['attachments']['data'] ) || !is_array( $node['attachments']['data'] ) ) {
            return null;
        }
        $first = $node['attachments']['data'][0] ?? null;
        return ( is_array( $first ) ? $first : null );
    }

    /**
     * Whether the post shares external content (not a native Page upload).
     *
     * Graph often omits `status_type=shared_story` when the Page shares without
     * a custom caption — using `mobile_status_update` and story text like
     * "… updated their status." while still returning `video_inline` on the
     * attachment. Those must map to link/event cards, not native video tiles.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed>      $node        Graph post.
     * @param array<string,mixed>|null $attachment  Primary attachment.
     * @param string                   $status_type Graph status_type.
     * @return bool
     */
    private static function is_shared_feed_post( array $node, ?array $attachment, string $status_type ) : bool {
        if ( 'shared_story' === $status_type ) {
            return true;
        }
        $att_type = ( null !== $attachment && isset( $attachment['type'] ) ? strtolower( (string) $attachment['type'] ) : '' );
        if ( in_array( $att_type, array(
            'share',
            'link',
            'video_share_youtube',
            'video_share_other'
        ), true ) ) {
            return true;
        }
        $story = ( isset( $node['story'] ) ? strtolower( trim( (string) $node['story'] ) ) : '' );
        if ( '' !== $story ) {
            $share_hints = array(
                'updated their status',
                'shared a post',
                'shared a link',
                'shared a photo',
                'shared a video',
                'shared an event'
            );
            foreach ( $share_hints as $hint ) {
                if ( false !== strpos( $story, $hint ) ) {
                    return true;
                }
            }
        }
        if ( 'added_video' !== $status_type && in_array( $att_type, array(
            'video_inline',
            'video_autoplay',
            'video',
            'native_templates'
        ), true ) ) {
            return true;
        }
        return false;
    }

    /**
     * Classify the post into a media kind for mapping.
     *
     * @param array<string,mixed>      $node        Graph post.
     * @param array<string,mixed>|null $attachment  Primary attachment.
     * @param string                   $status_type Graph status_type.
     * @return string One of: status, photo, album, video, live, link, event, mentioned, reel
     */
    private static function resolve_media_kind( array $node, ?array $attachment, string $status_type ) : string {
        $att_type = ( null !== $attachment && isset( $attachment['type'] ) ? strtolower( (string) $attachment['type'] ) : '' );
        if ( self::is_live_post( $node, $status_type ) ) {
            return 'live';
        }
        if ( self::is_reel_post( $node, $attachment, $att_type ) ) {
            return 'reel';
        }
        // Page `/videos` edge (videos filter) — not a feed attachment tree.
        if ( self::is_videos_edge_node( $node ) ) {
            return 'video';
        }
        if ( in_array( $att_type, array('event', 'map'), true ) || 'created_event' === $status_type ) {
            return 'event';
        }
        if ( self::is_shared_feed_post( $node, $attachment, $status_type ) ) {
            if ( in_array( $att_type, array('event', 'map'), true ) ) {
                return 'event';
            }
            return 'link';
        }
        if ( in_array( $att_type, array(
            'video_inline',
            'video_autoplay',
            'video',
            'native_templates'
        ), true ) || 'added_video' === $status_type ) {
            return 'video';
        }
        if ( 'tagged_in_photo' === $status_type || self::has_mentioned_signal( $node, $status_type ) ) {
            return 'mentioned';
        }
        $sub = ( null !== $attachment && isset( $attachment['subattachments']['data'] ) && is_array( $attachment['subattachments']['data'] ) ? $attachment['subattachments']['data'] : array() );
        if ( count( $sub ) > 1 || 'album' === $att_type ) {
            return 'album';
        }
        if ( 'photo' === $att_type || 'added_photos' === $status_type || isset( $node['full_picture'] ) && is_string( $node['full_picture'] ) && '' !== trim( $node['full_picture'] ) || '' !== self::image_url_from_photos_edge( $node ) ) {
            return 'photo';
        }
        return 'status';
    }

    /**
     * Whether the node is a Page `/videos` edge object (not a feed post).
     *
     * Videos edge nodes have video-specific fields (source, format, length) but
     * lack feed-specific ones (status_type, attachments). Check for positive
     * indicators first before rejecting based on ambiguous fields like full_picture.
     *
     * @param array<string,mixed> $node Graph node.
     * @return bool
     */
    private static function is_videos_edge_node( array $node ) : bool {
        // Feed posts are never `/videos` edge rows — `source` can appear on shared
        // video posts and must not preempt is_shared_feed_post().
        if ( isset( $node['status_type'] ) || isset( $node['attachments'] ) || isset( $node['images'] ) || isset( $node['start_time'] ) ) {
            return false;
        }
        // Strong positive indicators: video source URL or format array.
        $has_source = isset( $node['source'] ) && is_string( $node['source'] ) && '' !== trim( $node['source'] );
        $has_format = isset( $node['format'] ) && is_array( $node['format'] ) && array() !== $node['format'];
        if ( $has_source || $has_format ) {
            return true;
        }
        // Thumbnail-only video rows still expose picture + permalink/description.
        $has_picture = isset( $node['picture'] ) && is_string( $node['picture'] ) && '' !== trim( $node['picture'] );
        if ( !$has_picture ) {
            return false;
        }
        return isset( $node['description'] ) || isset( $node['title'] ) || isset( $node['permalink_url'] ) && is_string( $node['permalink_url'] );
    }

    /**
     * @param array<string,mixed> $node        Graph post.
     * @param string              $status_type Status type.
     * @return bool
     */
    private static function is_live_post( array $node, string $status_type ) : bool {
        $story = ( isset( $node['story'] ) ? strtolower( (string) $node['story'] ) : '' );
        if ( '' !== $story && false !== strpos( $story, 'is live' ) ) {
            return true;
        }
        if ( isset( $node['is_live'] ) && $node['is_live'] ) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed>      $node      Graph post.
     * @param array<string,mixed>|null $attachment Attachment.
     * @param string                   $att_type  Attachment type.
     * @return bool
     */
    private static function is_reel_post( array $node, ?array $attachment, string $att_type ) : bool {
        $permalink = self::extract_permalink( $node );
        if ( '' !== $permalink && false !== stripos( $permalink, '/reel' ) ) {
            return true;
        }
        if ( in_array( $att_type, array('reel', 'video_reel'), true ) ) {
            return true;
        }
        if ( null !== $attachment && isset( $attachment['target']['url'] ) ) {
            $target = (string) $attachment['target']['url'];
            if ( false !== stripos( $target, '/reel' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $node        Graph post.
     * @param string              $status_type Status type.
     * @return bool
     */
    private static function has_mentioned_signal( array $node, string $status_type ) : bool {
        return 'tagged_in_photo' === $status_type || isset( $node['story'] ) && is_string( $node['story'] ) && false !== stripos( (string) $node['story'], 'tagged' );
    }

    /**
     * Build PostMedia list for the classified kind.
     *
     * Malformed attachment trees return [] (text-only), never throw.
     *
     * @param array<string,mixed>      $node        Graph post.
     * @param array<string,mixed>|null $attachment  Primary attachment.
     * @param string                   $media_kind  Classified kind.
     * @param string                   $caption     Caption for alt.
     * @param string                   $author_name Author for alt.
     * @return array<int,array<string,mixed>>
     */
    private static function extract_media(
        array $node,
        ?array $attachment,
        string $media_kind,
        string $caption,
        string $author_name
    ) : array {
        switch ( $media_kind ) {
            case 'status':
                return array();
            case 'album':
                return self::extract_album_media(
                    $node,
                    $attachment,
                    $caption,
                    $author_name
                );
            case 'video':
            case 'live':
            case 'reel':
                return self::extract_video_media(
                    $node,
                    $attachment,
                    $caption,
                    $author_name,
                    $media_kind
                );
            case 'link':
            case 'event':
                return self::extract_link_preview_media(
                    $node,
                    $attachment,
                    $caption,
                    $author_name
                );
            case 'photo':
            case 'mentioned':
            default:
                return self::extract_photo_media(
                    $node,
                    $attachment,
                    $caption,
                    $author_name
                );
        }
    }

    /**
     * @param array<string,mixed>      $node        Graph post.
     * @param array<string,mixed>|null $attachment  Attachment.
     * @param string                   $caption     Caption.
     * @param string                   $author_name Author.
     * @return array<int,array<string,mixed>>
     */
    private static function extract_photo_media(
        array $node,
        ?array $attachment,
        string $caption,
        string $author_name
    ) : array {
        $url = self::resolve_image_url( $node, $attachment );
        if ( '' === $url ) {
            return array();
        }
        $media = array(
            'type'        => PostMedia::TYPE_PHOTO,
            'url'         => $url,
            'preview_url' => self::resolve_preview_url( $node, $url ),
        );
        $media = self::apply_attachment_dimensions( $media, $attachment );
        $media = self::apply_photos_edge_dimensions( $media, $node );
        return array(self::apply_media_alt(
            $media,
            $caption,
            $author_name,
            'photo'
        ));
    }

    /**
     * @param array<string,mixed>      $node        Graph post.
     * @param array<string,mixed>|null $attachment  Attachment.
     * @param string                   $caption     Caption.
     * @param string                   $author_name Author.
     * @return array<int,array<string,mixed>>
     */
    private static function extract_album_media(
        array $node,
        ?array $attachment,
        string $caption,
        string $author_name
    ) : array {
        $items = array();
        $subs = ( null !== $attachment && isset( $attachment['subattachments']['data'] ) && is_array( $attachment['subattachments']['data'] ) ? $attachment['subattachments']['data'] : array() );
        foreach ( $subs as $sub ) {
            if ( !is_array( $sub ) ) {
                continue;
            }
            $tuple = self::media_tuple_from_subattachment( $sub, $caption, $author_name );
            if ( null !== $tuple ) {
                $items[] = $tuple;
            }
        }
        if ( empty( $items ) ) {
            // Fall back to a single full_picture / primary image.
            return self::extract_photo_media(
                $node,
                $attachment,
                $caption,
                $author_name
            );
        }
        return $items;
    }

    /**
     * Map a single Graph subattachment node to a PostMedia init array.
     *
     * Shared by feed mapping and Pro album-photo load-more so both paths
     * produce identical media tuples. A grouped multi-photo post can include
     * a mixed-in video subattachment (`type: video_inline`/`video_autoplay`) —
     * previously always forced to `TYPE_PHOTO`, silently losing playability.
     *
     * Video subattachments never expose a raw MP4 `source` from Graph by
     * default, and resolving one requires an extra, fragile per-video Graph
     * call. Instead — matching the legacy plugin's proven approach — this
     * builds a **Facebook video plugin embed URL** (`/plugins/video.php`)
     * from the subattachment's own permalink (`target.url`/`url`, already
     * present with zero extra requests). No token, no extra round trip, no
     * expiring signed URL — Facebook's own player handles playback natively.
     *
     * @since 6.7.9
     * @since 6.7.9 Detect video-type subattachments; embed via Facebook's
     *               video plugin (`video_embed_url`) instead of a raw
     *               `<video>` source.
     *
     * @param array<string,mixed> $sub         Subattachment node.
     * @param string              $caption     Caption for alt text.
     * @param string              $author_name Author for alt text.
     * @return array<string,mixed>|null Null when the subattachment has no usable image URL.
     */
    public static function media_tuple_from_subattachment( array $sub, string $caption = '', string $author_name = '' ) : ?array {
        $url = self::image_url_from_attachment( $sub );
        if ( '' === $url ) {
            return null;
        }
        if ( self::is_video_subattachment_type( $sub ) ) {
            $tuple = array(
                'type'        => PostMedia::TYPE_VIDEO,
                'url'         => $url,
                'preview_url' => $url,
                'video_url'   => '',
            );
            $permalink = self::video_permalink_from_subattachment( $sub );
            if ( '' !== $permalink ) {
                $tuple['video_embed_url'] = self::build_facebook_video_embed_url( $permalink );
            }
            $tuple = self::apply_attachment_dimensions( $tuple, $sub );
            return self::apply_media_alt(
                $tuple,
                $caption,
                $author_name,
                'video'
            );
        }
        $tuple = array(
            'type'        => PostMedia::TYPE_PHOTO,
            'url'         => $url,
            'preview_url' => $url,
        );
        $tuple = self::apply_attachment_dimensions( $tuple, $sub );
        return self::apply_media_alt(
            $tuple,
            $caption,
            $author_name,
            'photo'
        );
    }

    /**
     * Whether a subattachment node's `type` indicates embedded video.
     *
     * @since 6.7.9
     * @param array<string,mixed> $sub Subattachment node.
     * @return bool
     */
    private static function is_video_subattachment_type( array $sub ) : bool {
        $type = ( isset( $sub['type'] ) ? strtolower( (string) $sub['type'] ) : '' );
        return in_array( $type, array('video_inline', 'video_autoplay', 'video'), true );
    }

    /**
     * Public Facebook permalink for a video subattachment, for embedding via
     * the Facebook video plugin (never a raw MP4 source).
     *
     * @since 6.7.9
     * @param array<string,mixed> $sub Subattachment node.
     * @return string
     */
    private static function video_permalink_from_subattachment( array $sub ) : string {
        if ( isset( $sub['target']['url'] ) && is_string( $sub['target']['url'] ) ) {
            $candidate = trim( (string) $sub['target']['url'] );
            if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
                return $candidate;
            }
        }
        if ( isset( $sub['url'] ) && is_string( $sub['url'] ) ) {
            $candidate = trim( (string) $sub['url'] );
            if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Build a Facebook video plugin embed URL for a public video permalink.
     *
     * Same approach as the legacy plugin's video rendering (`/plugins/video.php`)
     * — Facebook's own iframe player, needing only the public permalink, no
     * access token and no raw source resolution.
     *
     * @since 6.7.9
     * @param string $permalink Public Facebook video permalink.
     * @return string
     */
    public static function build_facebook_video_embed_url( string $permalink ) : string {
        $permalink = trim( $permalink );
        if ( '' === $permalink || !preg_match( '#^https?://#i', $permalink ) ) {
            return '';
        }
        return 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode( $permalink ) . '&show_text=0';
    }

    /**
     * Map a Photos-edge node (`/{album-id}/photos`) into a media tuple.
     *
     * Used by the Pro album load-more fallback when Graph's nested
     * `subattachments.paging` is unavailable (common — see
     * ESF_Facebook_Graph_Client::fetch_album_photos()), so pagination
     * continues via the album's own `/photos` edge instead. Shape differs from
     * subattachments: `images[]` / `picture` rather than `media.image.src`.
     *
     * @since 6.7.9
     * @param array<string,mixed> $node        Graph Photo node.
     * @param string              $caption     Fallback alt caption.
     * @param string              $author_name Fallback alt author.
     * @return array<string,mixed>|null
     */
    public static function media_tuple_from_photos_edge_node( array $node, string $caption = '', string $author_name = '' ) : ?array {
        $url = self::image_url_from_photos_edge( $node );
        if ( '' === $url ) {
            return null;
        }
        $tuple = array(
            'type'        => PostMedia::TYPE_PHOTO,
            'url'         => $url,
            'preview_url' => $url,
        );
        $tuple = self::apply_photos_edge_dimensions( $tuple, $node );
        return self::apply_media_alt(
            $tuple,
            $caption,
            $author_name,
            'photo'
        );
    }

    /**
     * @param array<string,mixed>      $node        Graph post.
     * @param array<string,mixed>|null $attachment  Attachment.
     * @param string                   $caption     Caption.
     * @param string                   $author_name Author.
     * @param string                   $media_kind  video|live|reel.
     * @return array<int,array<string,mixed>>
     */
    private static function extract_video_media(
        array $node,
        ?array $attachment,
        string $caption,
        string $author_name,
        string $media_kind
    ) : array {
        $poster = self::resolve_image_url( $node, $attachment );
        $video = '';
        if ( isset( $node['source'] ) && is_string( $node['source'] ) ) {
            $candidate = trim( $node['source'] );
            if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
                $video = $candidate;
            }
        }
        if ( '' === $video && null !== $attachment ) {
            if ( isset( $attachment['media']['source'] ) && is_string( $attachment['media']['source'] ) ) {
                $video = trim( (string) $attachment['media']['source'] );
            } elseif ( isset( $attachment['url'] ) && is_string( $attachment['url'] ) ) {
                $candidate = trim( (string) $attachment['url'] );
                if ( preg_match( '#^https?://#i', $candidate ) ) {
                    $video = $candidate;
                }
            }
        }
        $type = ( 'reel' === $media_kind ? PostMedia::TYPE_REEL : PostMedia::TYPE_VIDEO );
        // Build Facebook video plugin embed URL from post permalink.
        // Matches the legacy plugin's proven approach and the multi-photo
        // subattachment handling — Facebook's own iframe player, needing
        // only the public permalink, no token, no fragile raw source fetch.
        $permalink = self::extract_permalink( $node );
        $video_embed = ( '' !== $permalink ? self::build_facebook_video_embed_url( $permalink ) : '' );
        if ( '' !== $poster ) {
            $media = array(
                'type'        => $type,
                'url'         => $poster,
                'preview_url' => self::resolve_preview_url( $node, $poster ),
                'video_url'   => $video,
            );
            if ( '' !== $video_embed ) {
                $media['video_embed_url'] = $video_embed;
            }
            $media = self::apply_attachment_dimensions( $media, $attachment );
            return array(self::apply_media_alt(
                $media,
                $caption,
                $author_name,
                'video'
            ));
        }
        // No thumbnail: CSS poster so tiles never break.
        if ( '' === $video && '' === $permalink ) {
            return array();
        }
        $media = array(
            'type'                  => $type,
            'url'                   => '',
            'preview_url'           => '',
            'video_url'             => $video,
            'is_placeholder_poster' => true,
        );
        if ( '' !== $video_embed ) {
            $media['video_embed_url'] = $video_embed;
        }
        return array(self::apply_media_alt(
            $media,
            $caption,
            $author_name,
            'video'
        ));
    }

    /**
     * Link / event preview image (optional).
     *
     * @param array<string,mixed>      $node        Graph post.
     * @param array<string,mixed>|null $attachment  Attachment.
     * @param string                   $caption     Caption.
     * @param string                   $author_name Author.
     * @return array<int,array<string,mixed>>
     */
    private static function extract_link_preview_media(
        array $node,
        ?array $attachment,
        string $caption,
        string $author_name
    ) : array {
        $url = self::resolve_image_url( $node, $attachment );
        if ( '' === $url ) {
            return array();
        }
        $att_type = ( null !== $attachment && isset( $attachment['type'] ) ? strtolower( (string) $attachment['type'] ) : '' );
        $is_shared_video = in_array( $att_type, array(
            'video_inline',
            'video_autoplay',
            'video',
            'video_share_youtube',
            'video_share_other'
        ), true );
        $media = array(
            'type'        => ( $is_shared_video ? PostMedia::TYPE_VIDEO : PostMedia::TYPE_PHOTO ),
            'url'         => $url,
            'preview_url' => self::resolve_preview_url( $node, $url ),
        );
        if ( $is_shared_video && null !== $attachment ) {
            $video_permalink = '';
            if ( isset( $attachment['url'] ) && is_string( $attachment['url'] ) ) {
                $video_permalink = trim( (string) $attachment['url'] );
            } elseif ( isset( $attachment['target']['url'] ) && is_string( $attachment['target']['url'] ) ) {
                $video_permalink = trim( (string) $attachment['target']['url'] );
            }
            if ( '' !== $video_permalink && preg_match( '#^https?://#i', $video_permalink ) ) {
                $embed_url = self::build_facebook_video_embed_url( $video_permalink );
                if ( '' !== $embed_url ) {
                    $media['video_embed_url'] = $embed_url;
                }
            }
        }
        $media = self::apply_attachment_dimensions( $media, $attachment );
        return array(self::apply_media_alt(
            $media,
            $caption,
            $author_name,
            ( $is_shared_video ? 'video' : 'photo' )
        ));
    }

    /**
     * Prefer localized media, then full_picture, then attachment media image src.
     *
     * ESF_Facebook_Local_Media may set `local_media_url` / rewrite
     * `full_picture` at display time when General → Download images locally
     * is enabled for facebook.
     *
     * @param array<string,mixed>      $node       Graph post.
     * @param array<string,mixed>|null $attachment Attachment.
     * @return string
     */
    private static function resolve_image_url( array $node, ?array $attachment ) : string {
        if ( isset( $node['local_media_url'] ) && is_string( $node['local_media_url'] ) ) {
            $url = trim( $node['local_media_url'] );
            if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
                return $url;
            }
        }
        if ( isset( $node['full_picture'] ) && is_string( $node['full_picture'] ) ) {
            $url = trim( $node['full_picture'] );
            if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
                return $url;
            }
        }
        $photos_url = self::image_url_from_photos_edge( $node );
        if ( '' !== $photos_url ) {
            return $photos_url;
        }
        if ( null !== $attachment ) {
            return self::image_url_from_attachment( $attachment );
        }
        return '';
    }

    /**
     * Largest image from a Photos-edge node (`images[]` / `picture`).
     *
     * @param array<string,mixed> $node Graph photo.
     * @return string
     */
    private static function image_url_from_photos_edge( array $node ) : string {
        $best = '';
        $best_w = -1;
        $images = ( isset( $node['images'] ) && is_array( $node['images'] ) ? $node['images'] : array() );
        foreach ( $images as $img ) {
            if ( !is_array( $img ) || !isset( $img['source'] ) || !is_string( $img['source'] ) ) {
                continue;
            }
            $url = trim( $img['source'] );
            if ( '' === $url || !preg_match( '#^https?://#i', $url ) ) {
                continue;
            }
            $width = ( isset( $img['width'] ) ? (int) $img['width'] : 0 );
            if ( $width >= $best_w ) {
                $best_w = $width;
                $best = $url;
            }
        }
        if ( '' !== $best ) {
            return $best;
        }
        if ( isset( $node['picture'] ) && is_string( $node['picture'] ) ) {
            $url = trim( $node['picture'] );
            if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
                return $url;
            }
        }
        return '';
    }

    /**
     * Preview/tile URL: prefer grid thumb variant when localized.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed> $node Graph post.
     * @param string              $full Full-size URL fallback.
     * @return string
     */
    private static function resolve_preview_url( array $node, string $full ) : string {
        if ( isset( $node['local_media_thumb_url'] ) && is_string( $node['local_media_thumb_url'] ) ) {
            $thumb = trim( $node['local_media_thumb_url'] );
            if ( '' !== $thumb && preg_match( '#^https?://#i', $thumb ) ) {
                return $thumb;
            }
        }
        return $full;
    }

    /**
     * @param array<string,mixed> $attachment Attachment or subattachment.
     * @return string
     */
    private static function image_url_from_attachment( array $attachment ) : string {
        if ( isset( $attachment['media']['image']['src'] ) && is_string( $attachment['media']['image']['src'] ) ) {
            $url = trim( (string) $attachment['media']['image']['src'] );
            if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
                return $url;
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed>      $media      Media init.
     * @param array<string,mixed>|null $attachment Attachment.
     * @return array<string,mixed>
     */
    private static function apply_attachment_dimensions( array $media, ?array $attachment ) : array {
        if ( null === $attachment ) {
            return $media;
        }
        if ( isset( $attachment['media']['image']['width'] ) && (int) $attachment['media']['image']['width'] > 0 ) {
            $media['width'] = (int) $attachment['media']['image']['width'];
        }
        if ( isset( $attachment['media']['image']['height'] ) && (int) $attachment['media']['image']['height'] > 0 ) {
            $media['height'] = (int) $attachment['media']['image']['height'];
        }
        return $media;
    }

    /**
     * Apply width/height from a Photos-edge `images[]` entry matching the chosen URL.
     *
     * @param array<string,mixed> $media Media init.
     * @param array<string,mixed> $node  Graph photo.
     * @return array<string,mixed>
     */
    private static function apply_photos_edge_dimensions( array $media, array $node ) : array {
        if ( isset( $media['width'], $media['height'] ) ) {
            return $media;
        }
        if ( !isset( $node['images'] ) || !is_array( $node['images'] ) ) {
            return $media;
        }
        $chosen = ( isset( $media['url'] ) ? (string) $media['url'] : '' );
        foreach ( $node['images'] as $img ) {
            if ( !is_array( $img ) || !isset( $img['source'] ) ) {
                continue;
            }
            if ( '' !== $chosen && (string) $img['source'] !== $chosen ) {
                continue;
            }
            if ( isset( $img['width'] ) && (int) $img['width'] > 0 ) {
                $media['width'] = (int) $img['width'];
            }
            if ( isset( $img['height'] ) && (int) $img['height'] > 0 ) {
                $media['height'] = (int) $img['height'];
            }
            if ( isset( $media['width'], $media['height'] ) ) {
                return $media;
            }
        }
        return $media;
    }

    /**
     * @param array<string,mixed>            $node        Graph post.
     * @param array<string,mixed>|null       $attachment  Attachment.
     * @param string                         $media_kind  Kind.
     * @param string                         $status_type Status type.
     * @param array<int,array<string,mixed>> $media       Mapped media.
     * @param string                         $author_name Page display name.
     * @return array<string,mixed>
     */
    private static function build_extras(
        array $node,
        ?array $attachment,
        string $media_kind,
        string $status_type,
        array $media,
        string $author_name = ''
    ) : array {
        $extras = array(
            'status_type'    => ( '' !== $status_type ? $status_type : (( 'status' === $media_kind ? 'status' : $media_kind )) ),
            'media_type'     => strtoupper( $media_kind ),
            'is_live'        => 'live' === $media_kind,
            'is_reel'        => 'reel' === $media_kind,
            'content_source' => '',
        );
        $story_status = self::extract_story_status( $node, $author_name );
        if ( '' !== $story_status ) {
            $extras['story_status'] = $story_status;
        }
        if ( in_array( $media_kind, array('event', 'album', 'mentioned'), true ) ) {
            $extras['content_source'] = $media_kind;
        }
        if ( count( $media ) > 1 ) {
            $extras['carousel_count'] = count( $media );
        }
        if ( 'album' === $media_kind ) {
            $cursor = '';
            if ( null !== $attachment && isset( $attachment['subattachments']['paging']['next'] ) && is_string( $attachment['subattachments']['paging']['next'] ) ) {
                $candidate = trim( (string) $attachment['subattachments']['paging']['next'] );
                if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
                    $cursor = $candidate;
                }
            }
            // Graph frequently omits `subattachments.paging` entirely for nested
            // field-expansion (known Graph limitation — no `next`/summary on this
            // edge). Treat "we fetched exactly the requested cap" as a signal
            // there may be more, even without a paging cursor.
            $mapped = count( $media );
            $limit = ( class_exists( '\\ESF_Facebook_Graph_Client' ) ? (int) \ESF_Facebook_Graph_Client::get_album_subattachments_limit() : 0 );
            $hit_cap = $limit > 0 && $mapped >= $limit;
            $album_id = '';
            if ( null !== $attachment && isset( $attachment['target']['id'] ) && is_string( $attachment['target']['id'] ) ) {
                $candidate_id = trim( (string) $attachment['target']['id'] );
                if ( '' !== $candidate_id && preg_match( '/^\\d+$/', $candidate_id ) ) {
                    $album_id = $candidate_id;
                }
            }
            if ( '' !== $album_id ) {
                $extras['album_id'] = $album_id;
            }
            $extras['album_more_cursor'] = $cursor;
            $extras['album_has_more'] = '' !== $cursor || $hit_cap;
            $total = self::resolve_album_total_count( $node, $attachment, $mapped );
            if ( $total > 0 ) {
                // A known total (story text / target.count) is trusted verbatim.
                $extras['album_total_count'] = $total;
                $extras['carousel_count'] = $total;
                $extras['album_count_is_minimum'] = false;
            } else {
                $extras['album_count_is_minimum'] = $extras['album_has_more'];
                if ( $mapped > 1 ) {
                    $extras['carousel_count'] = $mapped;
                }
            }
        }
        if ( in_array( $media_kind, array('link', 'event'), true ) && null !== $attachment ) {
            $card = self::build_link_card_from_attachment( $attachment, $media_kind );
            $caption = self::extract_caption( $node );
            $finalized = FacebookEmbedSupport::finalize_link_card(
                $card,
                $caption,
                $media_kind,
                $status_type,
                $attachment
            );
            $extras['link_card'] = $finalized['card'];
            if ( !empty( $finalized['embed_restricted'] ) ) {
                $extras['embed_restricted'] = true;
                $extras['embed_kind'] = $finalized['embed_kind'];
            }
            if ( '' !== $finalized['event_id'] ) {
                $extras['embed_event_id'] = $finalized['event_id'];
            }
        } elseif ( null !== $attachment && FacebookEmbedSupport::node_has_unavailable_attachment( $node ) ) {
            // Shared / cross-Page embeds sometimes map as video/photo but still
            // carry Meta's unavailable attachment copy — treat like link embeds.
            $extras['embed_restricted'] = true;
            $extras['embed_kind'] = FacebookEmbedSupport::resolve_embed_kind( $media_kind, $status_type, $attachment );
        }
        // Extract reaction type breakdown
        $reaction_types = self::extract_reaction_types( $node );
        if ( !empty( $reaction_types ) ) {
            $extras['reactions'] = $reaction_types;
        }
        return $extras;
    }

    /**
     * Build link/event card from a feed attachment.
     *
     * @param array<string,mixed> $attachment Attachment.
     * @param string              $media_kind link|event.
     * @return array<string,mixed>
     */
    private static function build_link_card_from_attachment( array $attachment, string $media_kind ) : array {
        $title = ( isset( $attachment['title'] ) ? trim( (string) $attachment['title'] ) : '' );
        $desc = ( isset( $attachment['description'] ) ? trim( (string) $attachment['description'] ) : '' );
        $url = ( isset( $attachment['url'] ) ? trim( (string) $attachment['url'] ) : '' );
        if ( '' === $url && isset( $attachment['target']['url'] ) && is_string( $attachment['target']['url'] ) ) {
            $url = trim( (string) $attachment['target']['url'] );
        }
        if ( '' === $url && isset( $attachment['unshimmed_url'] ) && is_string( $attachment['unshimmed_url'] ) ) {
            $url = trim( (string) $attachment['unshimmed_url'] );
        }
        $card = array(
            'kind'        => ( 'event' === $media_kind ? 'event' : 'link' ),
            'title'       => $title,
            'description' => $desc,
            'url'         => $url,
        );
        // Some Graph payloads nest richer event fields on target.
        if ( isset( $attachment['target'] ) && is_array( $attachment['target'] ) ) {
            $card = array_merge( $card, self::extract_event_meta_from_node( $attachment['target'] ) );
            if ( '' === $card['title'] && isset( $attachment['target']['name'] ) ) {
                $card['title'] = trim( (string) $attachment['target']['name'] );
            }
        }
        return $card;
    }

    /**
     * Build link_card from an Events-edge node.
     *
     * @param array<string,mixed> $node        Event node.
     * @param string              $title       Event name.
     * @param string              $description Event description.
     * @param string              $permalink   Event URL.
     * @return array<string,mixed>
     */
    private static function build_link_card_from_event_fields(
        array $node,
        string $title,
        string $description,
        string $permalink
    ) : array {
        $card = array(
            'kind'        => 'event',
            'title'       => $title,
            'description' => $description,
            'url'         => $permalink,
        );
        return array_merge( $card, self::extract_event_meta_from_node( $node ) );
    }

    /**
     * Pull start/end/place/ticket fields from an event-shaped node.
     *
     * @param array<string,mixed> $node Graph node.
     * @return array<string,mixed>
     */
    private static function extract_event_meta_from_node( array $node ) : array {
        $meta = array();
        if ( isset( $node['start_time'] ) && is_string( $node['start_time'] ) && '' !== trim( $node['start_time'] ) ) {
            $meta['start_time'] = trim( $node['start_time'] );
        }
        if ( isset( $node['end_time'] ) && is_string( $node['end_time'] ) && '' !== trim( $node['end_time'] ) ) {
            $meta['end_time'] = trim( $node['end_time'] );
        }
        if ( isset( $node['ticket_uri'] ) && is_string( $node['ticket_uri'] ) && '' !== trim( $node['ticket_uri'] ) ) {
            $meta['ticket_uri'] = trim( $node['ticket_uri'] );
        }
        if ( isset( $node['place'] ) && is_array( $node['place'] ) ) {
            if ( isset( $node['place']['id'] ) ) {
                $meta['place_id'] = (string) $node['place']['id'];
            }
            if ( isset( $node['place']['name'] ) && is_string( $node['place']['name'] ) ) {
                $meta['place_name'] = trim( $node['place']['name'] );
            }
            if ( isset( $node['place']['location'] ) && is_array( $node['place']['location'] ) ) {
                $loc = $node['place']['location'];
                if ( isset( $loc['city'] ) && is_string( $loc['city'] ) ) {
                    $meta['place_city'] = trim( $loc['city'] );
                }
                if ( isset( $loc['country'] ) && is_string( $loc['country'] ) ) {
                    $meta['place_country'] = trim( $loc['country'] );
                }
                if ( isset( $loc['latitude'] ) && is_numeric( $loc['latitude'] ) ) {
                    $meta['latitude'] = (string) $loc['latitude'];
                }
                if ( isset( $loc['longitude'] ) && is_numeric( $loc['longitude'] ) ) {
                    $meta['longitude'] = (string) $loc['longitude'];
                }
            }
        }
        return $meta;
    }

    /**
     * @param array<string,mixed> $media       Media init.
     * @param string              $caption     Caption.
     * @param string              $author_name Author.
     * @param string              $alt_type    photo|video.
     * @return array<string,mixed>
     */
    private static function apply_media_alt(
        array $media,
        string $caption,
        string $author_name,
        string $alt_type
    ) : array {
        if ( class_exists( '\\EasySocialFeed\\SEO\\SeoHelper' ) ) {
            $media['alt'] = \EasySocialFeed\SEO\SeoHelper::derive_media_alt( $caption, $author_name, $alt_type );
            return $media;
        }
        if ( function_exists( 'esf_seo_media_alt' ) ) {
            $media['alt'] = esf_seo_media_alt( $caption, $author_name, $alt_type );
            return $media;
        }
        $snippet = trim( wp_strip_all_tags( $caption ) );
        if ( '' !== $snippet ) {
            $media['alt'] = ( function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 120 ) : substr( $snippet, 0, 120 ) );
            return $media;
        }
        if ( '' !== $author_name ) {
            /* translators: 1: page name */
            $template = ( function_exists( 'esf_translate_registry_string' ) ? (string) esf_translate_registry_string( 'fb_post_alt_named', '%1$s Facebook post' ) : __( '%1$s Facebook post', 'easy-facebook-likebox' ) );
            $media['alt'] = sprintf( $template, $author_name );
            return $media;
        }
        $media['alt'] = ( function_exists( 'esf_translate_registry_string' ) ? (string) esf_translate_registry_string( 'fb_post_alt', 'Facebook post' ) : __( 'Facebook post', 'easy-facebook-likebox' ) );
        return $media;
    }

}
