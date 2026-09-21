<?php

/**
 * Shared base for TikTok MediaTile layouts (Carousel, future Grid/Row).
 *
 * Follows Instagram's AbstractTileLayout pattern, adapted for TikTok.
 * Owns tile rendering, shell CSS vars so concrete layouts only declare geometry.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok\Layouts\Layouts
 * @since 6.13.0
 */
namespace EasySocialFeed\TikTok\Layouts\Layouts;

use EasySocialFeed\Layouts\AbstractLayout;
use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\Contracts\RendersTiles;
use EasySocialFeed\Layouts\Primitives\LoadMoreButton;
use EasySocialFeed\Layouts\Primitives\MediaTile;
use EasySocialFeed\Layouts\Primitives\PostMeta;
use EasySocialFeed\TikTok\Layouts\TikTokHeaderSupport;
use EasySocialFeed\TikTok\Layouts\TikTokPopupSupport;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Abstract TikTok tile layout.
 *
 * @since 6.13.0
 */
abstract class AbstractTileLayout extends AbstractLayout implements RendersTiles {
    use TikTokHeaderSupport;
    use TikTokPopupSupport;
    /**
     * Layout settings slug under `settings.layout.{slug}` (e.g. `carousel`).
     *
     * @since 6.13.0
     * @return string
     */
    protected abstract function get_layout_settings_slug() : string;

    /**
     * BEM root class for the layout shell (e.g. `esf-tiktok-feed__carousel`).
     *
     * @since 6.13.0
     * @return string
     */
    protected abstract function get_shell_class() : string;

    /**
     * BEM class for the posts inner wrapper.
     *
     * @since 6.13.0
     * @return string
     */
    protected abstract function get_inner_class() : string;

    /**
     * BEM class for one post cell.
     *
     * @since 6.13.0
     * @return string
     */
    protected abstract function get_cell_class() : string;

    /**
     * Modifier class when tile shadow is enabled.
     *
     * @since 6.13.0
     * @return string
     */
    protected abstract function get_tile_shadow_modifier_class() : string;

    /**
     * Default free-tier gap in pixels.
     *
     * @since 6.13.0
     * @return int
     */
    protected abstract function get_default_gap() : int;

    /**
     * Default free-tier tile corner radius in pixels.
     *
     * @since 6.13.0
     * @return int
     */
    protected abstract function get_default_tile_radius() : int;

    /**
     * Default Pro-tier tile shadow when the setting is unset.
     *
     * @since 6.13.0
     * @return bool
     */
    protected abstract function get_default_tile_shadow() : bool;

    /**
     * Whether gap is customizable without a Pro plan.
     *
     * @since 6.13.0
     * @return bool
     */
    protected abstract function is_gap_free() : bool;

    /**
     * Extra CSS custom properties for the shell (layout geometry).
     *
     * @since 6.13.0
     *
     * @return array<string,string> Map of CSS variable name => value (already unit-suffixed).
     */
    protected abstract function get_shell_geometry_style_vars() : array;

    /**
     * Check whether the site has an active TikTok Pro plan.
     *
     * @since 6.13.0
     * @return bool
     */
    protected function has_pro_plan() : bool {
        return function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan();
    }

    /**
     * Resolve media aspect ratio from layout settings.
     *
     * @since 6.13.0
     * @return string One of `1:1`, `4:5`, `3:4`, or `9:16`.
     */
    protected function resolve_tile_media_aspect_ratio() : string {
        $slug = $this->get_layout_settings_slug();
        $ratio = (string) $this->setting( 'layout.' . $slug . '.media_aspect_ratio', '1:1' );
        $ratio = trim( $ratio );
        if ( in_array( $ratio, array(
            '1:1',
            '4:5',
            '3:4',
            '9:16'
        ), true ) ) {
            return $ratio;
        }
        return '1:1';
    }

    /**
     * Whether Load More should render.
     *
     * Load More (button pagination) is a Free feature for every feed; it only
     * requires the per-feed `feed.load_more` setting and layout support.
     * Infinite Scroll (auto-fetch) stays Pro-only and is resolved client-side
     * via {@see resolve_load_more_mode()}.
     *
     * @since 6.9.0
     * @since 6.16.0 Wired to the real `feed.load_more` setting + layout
     *               support — Load More moved to Free.
     * @return bool
     */
    protected function can_render_load_more() : bool {
        if ( !$this->has_pro_plan() ) {
            return false;
        }
        $feed_cfg = (array) $this->setting( 'feed', array() );
        return (!array_key_exists( 'load_more', $feed_cfg ) || !empty( $feed_cfg['load_more'] )) && $this->definition->supports( 'load_more' );
    }

    /**
     * Render the layout HTML.
     *
     * @since 6.9.0
     * @return string
     */
    public function render() : string {
        $header_html = $this->render_tiktok_header();
        $settings_slug = $this->get_layout_settings_slug();
        $gap = $this->get_default_gap();
        $tile_radius = $this->get_default_tile_radius();
        $tile_shadow = false;
        $has_plan = $this->has_pro_plan();
        if ( $this->is_gap_free() || $has_plan ) {
            $gap = (int) $this->setting( 'layout.' . $settings_slug . '.gap', $this->get_default_gap() );
        }
        if ( $has_plan ) {
            $tile_radius = (int) $this->setting( 'layout.' . $settings_slug . '.tile_border_radius', $this->get_default_tile_radius() );
            $tile_shadow = (bool) $this->setting( 'layout.' . $settings_slug . '.tile_shadow', $this->get_default_tile_shadow() );
        }
        $gap = max( 0, min( 100, $gap ) );
        $tile_radius = max( 0, min( 32, $tile_radius ) );
        $shell = $this->build_tile_shell_attributes( $gap, $tile_radius, $tile_shadow );
        $shell['class'] .= ' esf-tiktok-feed__tiles';
        $state = $this->get_display_state();
        if ( empty( $state['posts'] ) ) {
            $error_message = trim( (string) $this->setting( 'feed.media_error_message', '' ) );
            if ( '' !== $error_message ) {
                $empty = sprintf( '<div class="esf-tiktok-feed__empty esf-tiktok-feed__empty--error"><p class="esf-tiktok-feed__empty-text">%s</p></div>', esc_html( $error_message ) );
            } else {
                $empty = $this->render_empty_state( esf_translate_registry_string( 'tt_no_videos_to_display', 'No videos to display yet.' ) );
            }
            return sprintf(
                '<div class="%1$s" style="%2$s">%3$s%4$s</div>',
                esc_attr( $shell['class'] ),
                esc_attr( $shell['style'] ),
                $header_html,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
                $empty
            );
        }
        $tile_options = $this->build_tile_options();
        $tiles = '';
        foreach ( $state['posts'] as $post ) {
            if ( $post instanceof PostItem ) {
                $tiles .= $this->render_tile( $post, $tile_options );
            }
        }
        $load_more = '';
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( $this->can_render_load_more() && method_exists( $this, 'render_tiktok_load_more__premium_only' ) ) {
                $load_more = $this->render_tiktok_load_more__premium_only( count( $state['posts'] ), esf_translate_registry_string( 'tt_load_more_videos_button', 'Load more' ), esf_translate_registry_string( 'tt_load_more_videos_aria', 'Load more TikTok videos' ) );
            }
        }
        return sprintf(
            '<div class="%1$s" style="%2$s">%3$s<div class="%4$s">%5$s</div>%6$s</div>',
            esc_attr( $shell['class'] ),
            esc_attr( $shell['style'] ),
            $header_html,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
            esc_attr( $this->get_inner_class() ),
            $tiles,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
            $load_more
        );
    }

    /**
     * Resolve the pagination mode (`button` free default, `infinite` Pro).
     *
     * @since 6.16.0
     *
     * @param array<string,mixed> $feed_cfg Feed settings slice.
     * @return string `button` or `infinite`.
     */
    protected function resolve_load_more_mode( array $feed_cfg ) : string {
        if ( !$this->has_pro_plan() ) {
            return 'button';
        }
        return ( isset( $feed_cfg['load_more_mode'] ) && 'infinite' === $feed_cfg['load_more_mode'] ? 'infinite' : 'button' );
    }

    /**
     * Read the stored TikTok API pagination cursor for this feed's account.
     *
     * @since 6.16.0
     * @return string
     */
    protected function get_video_pagination_cursor() : string {
        if ( !class_exists( '\\ESF_TikTok_Cache' ) ) {
            return '';
        }
        $account_id = ( isset( $this->feed->account_id ) ? (int) $this->feed->account_id : 0 );
        if ( $account_id <= 0 ) {
            return '';
        }
        $meta_key = \ESF_TikTok_Cache::posts_meta_cache_key( $account_id );
        $meta_cache = \ESF_TikTok_Cache::get( $meta_key );
        if ( !is_array( $meta_cache ) || empty( $meta_cache['cursor'] ) ) {
            return '';
        }
        return sanitize_text_field( (string) $meta_cache['cursor'] );
    }

    /**
     * Convert ratio slug to CSS aspect-ratio value.
     *
     * @since 6.13.0
     * @param string $ratio Ratio slug.
     * @return string
     */
    protected function tile_media_aspect_ratio_to_css( string $ratio ) : string {
        if ( '4:5' === $ratio ) {
            return '4 / 5';
        }
        if ( '3:4' === $ratio ) {
            return '3 / 4';
        }
        if ( '9:16' === $ratio ) {
            return '9 / 16';
        }
        return '1 / 1';
    }

    /**
     * Sanitize a hex/rgba color for tile shadow.
     *
     * @since 6.13.0
     * @param string $color Raw color string.
     * @return string
     */
    protected function sanitize_tile_shadow_color( string $color ) : string {
        $color = trim( $color );
        if ( '' === $color ) {
            return '';
        }
        if ( function_exists( 'sanitize_hex_color' ) && 0 === strpos( $color, '#' ) ) {
            $sanitized = sanitize_hex_color( $color );
            return ( is_string( $sanitized ) ? $sanitized : '' );
        }
        if ( preg_match( '/^rgba?\\([\\d\\s,\\.]+\\)$/i', $color ) ) {
            return $color;
        }
        return '';
    }

    /**
     * Build tile shell attributes (class + style).
     *
     * @since 6.13.0
     * @param int  $gap          Gap in pixels.
     * @param int  $tile_radius  Tile radius in pixels.
     * @param bool $tile_shadow  Whether shadow is enabled.
     * @return array{class:string,style:string}
     */
    protected function build_tile_shell_attributes( int $gap, int $tile_radius, bool $tile_shadow ) : array {
        $class = $this->get_shell_class();
        if ( $tile_shadow ) {
            $class .= ' ' . $this->get_tile_shadow_modifier_class();
        }
        $style_vars = array_merge( array(
            '--esf-tiktok-tile-gap'    => $gap . 'px',
            '--esf-tiktok-tile-radius' => $tile_radius . 'px',
        ), $this->get_shell_geometry_style_vars(), $this->get_hover_overlay_style_vars() );
        $scope = $this->setting( 'layout.' . $this->get_layout_settings_slug(), array() );
        if ( !is_array( $scope ) ) {
            $scope = array();
        }
        if ( function_exists( 'esf_layout_dimension_style_vars' ) ) {
            $style_vars = array_merge( $style_vars, esf_layout_dimension_style_vars( $scope, '--esf-tiktok' ) );
        }
        if ( function_exists( 'esf_layout_has_feed_height_limit' ) && esf_layout_has_feed_height_limit( $scope ) ) {
            $class .= ' esf-tiktok-feed--has-feed-height';
        }
        $ratio = $this->resolve_tile_media_aspect_ratio();
        $style_vars['--esf-tiktok-tile-ratio'] = $this->tile_media_aspect_ratio_to_css( $ratio );
        $style = '';
        foreach ( $style_vars as $var => $val ) {
            $style .= esc_attr( $var ) . ':' . esc_attr( $val ) . ';';
        }
        return array(
            'class' => $class,
            'style' => rtrim( $style, ';' ),
        );
    }

    /**
     * CSS custom properties for hover + icon (Pro color/size).
     *
     * @since 6.13.0
     * @return array<string,string>
     */
    protected function get_hover_overlay_style_vars() : array {
        $vars = array();
        if ( !$this->has_pro_plan() ) {
            return $vars;
        }
        $color = trim( (string) $this->setting( 'feed.hover_play_color', '' ) );
        if ( '' === $color ) {
            $color = trim( (string) $this->setting( 'feed.hover_plus_color', '' ) );
        }
        if ( '' !== $color ) {
            $sanitized = $this->sanitize_tile_shadow_color( $color );
            if ( '' !== $sanitized ) {
                $vars['--esf-tiktok-hover-play-color'] = $sanitized;
            }
        }
        $icon_color = trim( (string) $this->setting( 'feed.hover_play_icon_color', '' ) );
        if ( '' !== $icon_color ) {
            $sanitized = $this->sanitize_tile_shadow_color( $icon_color );
            if ( '' !== $sanitized ) {
                $vars['--esf-tiktok-hover-play-icon-color'] = $sanitized;
            }
        }
        $size = (int) $this->setting( 'feed.hover_play_size', 52 );
        if ( $size <= 0 ) {
            $size = (int) $this->setting( 'feed.hover_plus_size', 52 );
        }
        $size = max( 28, min( 72, $size ) );
        $vars['--esf-tiktok-hover-play-size'] = $size . 'px';
        return $vars;
    }

    /**
     * Whether hover play icon is enabled for video tiles.
     *
     * @since 6.16.0
     * @return bool
     */
    protected function resolve_show_hover_play() : bool {
        $feed_cfg = (array) $this->setting( 'feed', array() );
        if ( array_key_exists( 'show_hover_play', $feed_cfg ) ) {
            return !empty( $feed_cfg['show_hover_play'] );
        }
        if ( array_key_exists( 'show_hover_plus', $feed_cfg ) ) {
            return !empty( $feed_cfg['show_hover_plus'] );
        }
        return true;
    }

    /**
     * Whether grid tiles should render caption/meta below the cover.
     *
     * @since 6.16.0
     * @return bool
     */
    protected function should_render_grid_tile_details() : bool {
        if ( 'grid' !== $this->get_layout_settings_slug() ) {
            return false;
        }
        $mode = (string) $this->setting( 'layout.grid.display_mode', 'detailed' );
        return 'detailed' === $mode;
    }

    /**
     * Build base tile options for MediaTile / MediaCollage rendering.
     *
     * @since 6.13.0
     * @return array<string,mixed>
     */
    protected function build_tile_options() : array {
        $hover_overlay = (bool) $this->setting( 'feed.hover_overlay', true );
        $show_hover_play = $hover_overlay && $this->resolve_show_hover_play();
        $options = array(
            'module'               => $this->module,
            'new_tab'              => (bool) $this->setting( 'feed.links_new_tab', true ),
            'prefer_preview'       => true,
            'show_overlay'         => $hover_overlay,
            'show_hover_plus'      => false,
            'show_hover_play'      => $show_hover_play,
            'show_media_type_icon' => false,
            'popup_trigger'        => false,
            'show_caption'         => false,
            'show_meta'            => false,
            'show_time'            => false,
            'use_collage'          => false,
        );
        return $options;
    }

    /**
     * Render one tile for a post.
     *
     * Media posts use MediaCollage (multi-image) or MediaTile (single). Text-only
     * posts get a caption tile so they still appear in carousel / future tile
     * layouts (TikTok has many commentary-only updates).
     *
     * @since 6.13.0
     * @param PostItem            $post    Post to render.
     * @param array<string,mixed> $options MediaTile / MediaCollage options.
     * @return string
     */
    protected function render_tile( PostItem $post, array $options ) : string {
        $media = $post->get_media();
        if ( empty( $media ) || !isset( $media[0] ) ) {
            return '';
        }
        $primary = $media[0];
        $permalink = $post->get_permalink();
        $alt = $this->resolve_tile_alt_fallback( $post );
        $options['variation_seed'] = $post->get_id();
        $options['popup_trigger'] = false;
        $options['duration_label'] = $this->get_video_duration_label( $post );
        $popup_payload = null;
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan() ) {
                if ( method_exists( $this, 'build_tiktok_popup_payload__premium_only' ) ) {
                    $popup_payload = $this->build_tiktok_popup_payload__premium_only( $post );
                }
                if ( !empty( $popup_payload ) ) {
                    $options['popup_trigger'] = true;
                }
            }
        }
        $tile_html = MediaTile::render(
            $this->module,
            $primary,
            0,
            $permalink,
            $alt,
            $options
        );
        if ( '' === $tile_html ) {
            return '';
        }
        return $this->wrap_tile_cell( $post, $tile_html, $popup_payload );
    }

    /**
     * Formatted mm:ss label when duration badge is enabled and present.
     *
     * @since 6.16.0
     *
     * @param PostItem $post Post value object.
     * @return string
     */
    protected function get_video_duration_label( PostItem $post ) : string {
        if ( !(bool) $this->setting( 'feed.show_duration', true ) ) {
            return '';
        }
        if ( !method_exists( $post, 'get_extra' ) ) {
            return '';
        }
        return trim( (string) $post->get_extra( 'duration_formatted', '' ) );
    }

    /**
     * @deprecated 6.16.0 Use get_video_duration_label(); badge renders inside MediaTile.
     *
     * @param PostItem $post Post value object.
     * @return string
     */
    protected function render_duration_badge( PostItem $post ) : string {
        $formatted = $this->get_video_duration_label( $post );
        if ( '' === $formatted ) {
            return '';
        }
        return sprintf( '<span class="esf-tiktok-feed__tile-duration" aria-hidden="true">%s</span>', esc_html( $formatted ) );
    }

    /**
     * Wrap tile markup in the layout cell article.
     *
     * @since 6.13.0
     *
     * @param PostItem                 $post          Post item.
     * @param string                   $tile_html     Inner tile HTML.
     * @param array<string,mixed>|null $popup_payload Optional popup payload.
     * @return string
     */
    protected function wrap_tile_cell( PostItem $post, string $tile_html, $popup_payload = null ) : string {
        if ( '' === $tile_html ) {
            return '';
        }
        $cell_class = $this->get_cell_class();
        $popup_attr = '';
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan() ) {
                if ( null === $popup_payload && method_exists( $this, 'build_tiktok_popup_payload__premium_only' ) ) {
                    $popup_payload = $this->build_tiktok_popup_payload__premium_only( $post );
                }
                if ( method_exists( $this, 'tiktok_popup_data_attribute__premium_only' ) ) {
                    $popup_attr = $this->tiktok_popup_data_attribute__premium_only( $popup_payload );
                    if ( '' !== $popup_attr ) {
                        $cell_class .= ' esf-tiktok-feed__tile-cell--popup';
                    }
                }
            }
        }
        $details_html = '';
        if ( $this->should_render_grid_tile_details() ) {
            $details_html = $this->render_grid_tile_details( $post );
            if ( '' !== $details_html ) {
                $cell_class .= ' esf-tiktok-feed__grid-cell--card';
            }
        }
        return sprintf(
            '<article class="%1$s" data-post-id="%2$s"%3$s>%4$s%5$s</article>',
            esc_attr( $cell_class ),
            esc_attr( $post->get_id() ),
            $popup_attr,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-built popup data.
            $tile_html,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Tile markup is escaped upstream.
            $details_html
        );
    }

    /**
     * Render caption, time, and engagement metrics below a grid cover tile.
     *
     * @since 6.16.0
     *
     * @param PostItem $post Post value object.
     * @return string
     */
    protected function render_grid_tile_details( PostItem $post ) : string {
        $post_cfg = (array) $this->setting( 'post', array() );
        $show_caption = !array_key_exists( 'show_caption', $post_cfg ) || !empty( $post_cfg['show_caption'] );
        $show_time = !array_key_exists( 'show_time', $post_cfg ) || !empty( $post_cfg['show_time'] );
        // Views / likes / comments are TikTok Pro only (YouTube card metrics parity).
        $can_show_engagement = $this->has_pro_plan();
        $show_views = $can_show_engagement && (!array_key_exists( 'show_views', $post_cfg ) || !empty( $post_cfg['show_views'] ));
        $show_likes = $can_show_engagement && (!array_key_exists( 'show_likes', $post_cfg ) || !empty( $post_cfg['show_likes'] ));
        $show_comments = $can_show_engagement && (!array_key_exists( 'show_comments', $post_cfg ) || !empty( $post_cfg['show_comments'] ));
        $show_shares = !array_key_exists( 'show_shares', $post_cfg ) || !empty( $post_cfg['show_shares'] );
        $caption = '';
        if ( $show_caption ) {
            $caption = $this->format_grid_caption_html( $post );
        }
        $meta_parts = array();
        if ( $show_time ) {
            $created = (string) $post->get_created_at();
            if ( '' !== $created ) {
                $time_label = ( function_exists( 'esf_readable_time_ago' ) ? (string) esf_readable_time_ago( $created ) : $created );
                if ( '' !== $time_label ) {
                    $datetime = ( class_exists( '\\EasySocialFeed\\SEO\\SeoHelper' ) ? \EasySocialFeed\SEO\SeoHelper::normalize_datetime_iso( $created ) : $created );
                    $meta_parts[] = sprintf( '<time class="esf-tiktok-feed__grid-time" datetime="%1$s">%2$s</time>', esc_attr( (string) $datetime ), esc_html( $time_label ) );
                }
            }
        }
        if ( $show_views || $show_likes || $show_comments || $show_shares ) {
            $metrics = $post->get_metrics();
            $metrics_html = PostMeta::render(
                $this->module,
                array(
                    'views'    => ( isset( $metrics['views'] ) ? (int) $metrics['views'] : 0 ),
                    'likes'    => ( isset( $metrics['likes'] ) ? (int) $metrics['likes'] : 0 ),
                    'comments' => ( isset( $metrics['comments'] ) ? (int) $metrics['comments'] : 0 ),
                    'shares'   => ( isset( $metrics['shares'] ) ? (int) $metrics['shares'] : 0 ),
                ),
                array(
                    'views'    => esf_translate_registry_string( 'tt_views', 'Views' ),
                    'likes'    => esf_translate_registry_string( 'tt_likes', 'Likes' ),
                    'comments' => esf_translate_registry_string( 'tt_comments', 'Comments' ),
                    'shares'   => esf_translate_registry_string( 'tt_shares', 'Shares' ),
                ),
                array(
                    'views'    => $show_views,
                    'likes'    => $show_likes,
                    'comments' => $show_comments,
                    'shares'   => $show_shares,
                ),
                'div'
            );
            if ( '' !== $metrics_html ) {
                $meta_parts[] = $metrics_html;
            }
        }
        if ( '' === $caption && empty( $meta_parts ) ) {
            return '';
        }
        $html = '<div class="esf-tiktok-feed__grid-details">';
        if ( '' !== $caption ) {
            $html .= '<div class="esf-tiktok-feed__grid-caption">' . $caption . '</div>';
        }
        if ( !empty( $meta_parts ) ) {
            $html .= '<div class="esf-tiktok-feed__grid-meta">' . implode( '', $meta_parts ) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Build truncated, linkified caption HTML for grid card details.
     *
     * @since 6.16.0
     *
     * @param PostItem $post Post value object.
     * @return string
     */
    protected function format_grid_caption_html( PostItem $post ) : string {
        $raw = ( function_exists( 'wp_strip_all_tags' ) ? trim( wp_strip_all_tags( (string) $post->get_text_html() ) ) : trim( strip_tags( (string) $post->get_text_html() ) ) );
        if ( '' === $raw ) {
            return '';
        }
        $limit_words = max( 5, min( 100, (int) $this->setting( 'layout.grid.caption_words', 20 ) ) );
        $words = preg_split(
            '/\\s+/',
            $raw,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ( is_array( $words ) && count( $words ) > $limit_words ) {
            $raw = implode( ' ', array_slice( $words, 0, $limit_words ) ) . '…';
        }
        if ( function_exists( 'esf_tiktok_format_caption_html' ) ) {
            return esf_tiktok_format_caption_html( $raw, array(
                'paragraphs'  => false,
                'line_breaks' => false,
            ) );
        }
        return esc_html( $raw );
    }

    /**
     * Wrap a text-only tile and mark the cell for layout CSS.
     *
     * @since 6.13.0
     *
     * @param PostItem                 $post          Post item.
     * @param string                   $tile_html     Text tile HTML.
     * @param array<string,mixed>|null $popup_payload Optional popup payload.
     * @return string
     */
    protected function wrap_text_tile_cell( PostItem $post, string $tile_html, $popup_payload = null ) : string {
        if ( '' === $tile_html ) {
            return '';
        }
        $cell_class = $this->get_cell_class() . ' esf-tiktok-feed__tile-cell--text';
        $popup_attr = '';
        if ( null === $popup_payload && method_exists( $this, 'build_tiktok_popup_payload__premium_only' ) ) {
            $popup_payload = $this->build_tiktok_popup_payload__premium_only( $post );
        }
        if ( method_exists( $this, 'tiktok_popup_data_attribute__premium_only' ) ) {
            $popup_attr = $this->tiktok_popup_data_attribute__premium_only( $popup_payload );
            if ( '' !== $popup_attr ) {
                $cell_class .= ' esf-tiktok-feed__tile-cell--popup';
            }
        }
        return sprintf(
            '<article class="%1$s"%2$s>%3$s</article>',
            esc_attr( $cell_class ),
            $popup_attr,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-built popup data.
            $tile_html
        );
    }

    /**
     * Render a text-only tile (no media) for carousel / tile layouts.
     *
     * @since 6.13.0
     *
     * @param PostItem $post Post item.
     * @return string
     */
    protected function render_text_tile( PostItem $post ) : string {
        $raw = (string) $post->get_text_html();
        $text = ( function_exists( 'wp_strip_all_tags' ) ? trim( wp_strip_all_tags( $raw ) ) : trim( strip_tags( $raw ) ) );
        if ( '' === $text ) {
            $text = esf_translate_registry_string( 'tt_view_on_tiktok', 'View on TikTok' );
        }
        // Height fill is driven by CSS line-clamp on the aspect-ratio tile — do not
        // apply the short details `caption_words` limit here. Keep a generous safety
        // cap so extremely long posts don't dump huge markup into the feed.
        $words = preg_split(
            '/\\s+/',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ( is_array( $words ) && count( $words ) > 160 ) {
            $text = implode( ' ', array_slice( $words, 0, 160 ) ) . '…';
        }
        $permalink = (string) $post->get_permalink();
        $inner = sprintf( '<span class="esf-tiktok-feed__carousel-text-tile-label">%1$s</span>' . '<span class="esf-tiktok-feed__carousel-text-tile-body">%2$s</span>', esc_html__( 'Post', 'easy-facebook-likebox' ), esc_html( $text ) );
        $popup_payload = null;
        if ( method_exists( $this, 'build_tiktok_popup_payload__premium_only' ) ) {
            $popup_payload = $this->build_tiktok_popup_payload__premium_only( $post );
        }
        $trigger_attr = ( !empty( $popup_payload ) ? ' data-esf-tiktok-popup-trigger="1"' : '' );
        if ( '' !== $permalink && empty( $popup_payload ) ) {
            return sprintf(
                '<a class="esf-tiktok-feed__carousel-text-tile" href="%1$s" target="_blank" rel="noopener noreferrer"%2$s>%3$s</a>',
                esc_url( $permalink ),
                $trigger_attr,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
                $inner
            );
        }
        return sprintf( 
            '<div class="esf-tiktok-feed__carousel-text-tile"%1$s>%2$s</div>',
            $trigger_attr,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
            $inner
         );
    }

    /**
     * Resolve tile alt text fallback.
     *
     * @since 6.13.0
     * @param PostItem $post Post item.
     * @return string
     */
    protected function resolve_tile_alt_fallback( PostItem $post ) : string {
        $account_summary = $this->get_display_state()['account_summary'] ?? null;
        if ( null === $account_summary && method_exists( $this, 'get_account_summary' ) ) {
            $account_summary = $this->get_account_summary();
        }
        if ( null !== $account_summary ) {
            return (string) $account_summary->get_name();
        }
        return '';
    }

    /**
     * Render empty state message.
     *
     * @since 6.13.0
     * @param string $message Empty message.
     * @return string
     */
    protected function render_empty_state( string $message ) : string {
        return sprintf( '<div class="esf-tiktok-feed__empty"><p class="esf-tiktok-feed__empty-text">%s</p></div>', esc_html( $message ) );
    }

}
