<?php

/**
 * Shared base for Instagram MediaTile layouts (Grid, Row, …).
 *
 * Owns tile rendering, load-more, shoppable/popup hooks, and shell CSS vars so
 * concrete layouts only declare geometry (class names + layout-specific vars).
 * Facebook can later mirror this pattern under its own module namespace while
 * still using shared MediaTile / AccountHeader primitives.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram\Layouts\Layouts
 * @since 6.9.0
 */
namespace EasySocialFeed\Instagram\Layouts\Layouts;

use EasySocialFeed\Instagram\Layouts\InstagramLayoutDimensionsSupport;
use EasySocialFeed\Instagram\Layouts\InstagramHeaderSupport;
use EasySocialFeed\Instagram\Layouts\InstagramPopupSupport;
use EasySocialFeed\Instagram\Layouts\InstagramShoppableSupport;
use EasySocialFeed\Layouts\AbstractLayout;
use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\Contracts\RendersTiles;
use EasySocialFeed\Layouts\Primitives\LoadMoreButton;
use EasySocialFeed\Layouts\Primitives\MediaCollage;
use EasySocialFeed\Layouts\Primitives\MediaTile;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Abstract Instagram tile layout.
 *
 * @since 6.9.0
 */
abstract class AbstractTileLayout extends AbstractLayout implements RendersTiles {
    use InstagramLayoutDimensionsSupport;
    use InstagramPopupSupport;
    use InstagramHeaderSupport;
    use InstagramShoppableSupport;
    /**
     * Layout settings slug under `settings.layout.{slug}` (e.g. `grid`, `row`).
     *
     * @since 6.9.0
     * @return string
     */
    protected abstract function get_layout_settings_slug() : string;

    /**
     * BEM root class for the layout shell (e.g. `esf-instagram-feed__grid`).
     *
     * @since 6.9.0
     * @return string
     */
    protected abstract function get_shell_class() : string;

    /**
     * BEM class for the posts inner wrapper.
     *
     * @since 6.9.0
     * @return string
     */
    protected abstract function get_inner_class() : string;

    /**
     * BEM class for one post cell.
     *
     * @since 6.9.0
     * @return string
     */
    protected abstract function get_cell_class() : string;

    /**
     * Modifier class when tile shadow is enabled.
     *
     * @since 6.9.0
     * @return string
     */
    protected abstract function get_tile_shadow_modifier_class() : string;

    /**
     * Default free-tier gap in pixels.
     *
     * @since 6.9.0
     * @return int
     */
    protected abstract function get_default_gap() : int;

    /**
     * Default free-tier tile corner radius in pixels.
     *
     * @since 6.9.0
     * @return int
     */
    protected abstract function get_default_tile_radius() : int;

    /**
     * Default Pro-tier tile shadow when the setting is unset.
     *
     * @since 6.9.0
     * @return bool
     */
    protected abstract function get_default_tile_shadow() : bool;

    /**
     * Whether gap is customizable without a Pro plan.
     *
     * @since 6.9.0
     * @return bool
     */
    protected abstract function is_gap_free() : bool;

    /**
     * Extra CSS custom properties for the shell (layout geometry).
     *
     * @since 6.9.0
     *
     * @return array<string,string> Map of CSS variable name => value (already unit-suffixed).
     */
    protected abstract function get_shell_geometry_style_vars() : array;

    /**
     * Render the layout HTML.
     *
     * @since 6.9.0
     * @return string
     */
    public function render() : string {
        $stories_section = $this->render_instagram_stories_section();
        $header_html = $this->render_instagram_header();
        $stories_row = $stories_section['row_html'];
        $stories_attr = $stories_section['payload_attr'];
        $settings_slug = $this->get_layout_settings_slug();
        $gap = $this->get_default_gap();
        $tile_radius = $this->get_default_tile_radius();
        $tile_shadow = false;
        $has_plan = function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan();
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
        $state = $this->get_display_state();
        if ( empty( $state['posts'] ) ) {
            $error_message = trim( (string) $this->setting( 'feed.media_error_message', '' ) );
            if ( '' !== $error_message ) {
                $empty = sprintf( '<div class="esf-instagram-feed__empty esf-instagram-feed__empty--error"><p class="esf-instagram-feed__empty-text">%s</p></div>', esc_html( $error_message ) );
            } else {
                $empty = $this->render_empty_state( __( 'No posts to display yet.', 'easy-facebook-likebox' ) );
            }
            return sprintf(
                '<div class="%1$s"%2$s style="%3$s">%4$s%5$s%6$s</div>',
                esc_attr( $shell['class'] ),
                $stories_attr,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped attribute.
                esc_attr( $shell['style'] ),
                $header_html,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
                $stories_row,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
                $empty
            );
        }
        $tile_options = $this->build_tile_options();
        $tiles = '';
        foreach ( $state['posts'] as $post ) {
            $tiles .= $this->render_tile( $post, $tile_options );
        }
        $can_load_more = (bool) $this->setting( 'feed.load_more', false ) && $this->definition->supports( 'load_more' ) && (bool) apply_filters(
            'esf_instagram_layout_can_load_more',
            false,
            $this->feed,
            $this->account,
            $this
        );
        $load_more = '';
        if ( $can_load_more && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
                if ( method_exists( $this, 'render_instagram_load_more__premium_only' ) ) {
                    $load_more = $this->render_instagram_load_more__premium_only( count( $state['posts'] ), __( 'Load more', 'easy-facebook-likebox' ), __( 'Load more Instagram posts', 'easy-facebook-likebox' ) );
                }
            }
        }
        return sprintf(
            '<div class="%1$s"%2$s style="%3$s">%4$s%5$s<div class="%6$s esf-instagram-feed__items-inner">%7$s</div>%8$s</div>',
            esc_attr( $shell['class'] ),
            $stories_attr,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped attribute.
            esc_attr( $shell['style'] ),
            $header_html,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
            $stories_row,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
            esc_attr( $this->get_inner_class() ),
            $tiles,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Primitive returns escaped markup.
            $load_more
        );
    }

    /**
     * Render one cell using MediaCollage (multi-image) or MediaTile (single).
     *
     * @since 6.9.0
     *
     * @param PostItem            $post         Post to render.
     * @param array<string,mixed> $tile_options Options forwarded to MediaTile / MediaCollage.
     * @return string
     */
    protected function render_tile( PostItem $post, array $tile_options ) : string {
        $media = $post->get_media();
        if ( empty( $media ) ) {
            return '';
        }
        $primary = $media[0];
        $extra_count = max( 0, count( $media ) - 1 );
        $metrics = $post->get_metrics();
        $tile_options['likes_count'] = ( isset( $metrics['likes'] ) ? (int) $metrics['likes'] : 0 );
        $tile_options['comments_count'] = ( isset( $metrics['comments'] ) ? (int) $metrics['comments'] : 0 );
        $tile_options['variation_seed'] = $post->get_id();
        $popup_payload = null;
        $permalink = $post->get_permalink();
        $cell_class = $this->get_cell_class();
        // This entire code block will be removed from the free version.
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
                if ( method_exists( $this, 'build_instagram_popup_payload__premium_only' ) ) {
                    $popup_payload = $this->build_instagram_popup_payload__premium_only( $post );
                }
                if ( !empty( $popup_payload ) ) {
                    $tile_options['popup_trigger'] = true;
                }
                if ( method_exists( $this, 'apply_instagram_shoppable_tile__premium_only' ) ) {
                    $shoppable = $this->apply_instagram_shoppable_tile__premium_only( $post, $tile_options );
                    $permalink = $shoppable['permalink'];
                    $tile_options = $shoppable['tile_options'];
                    if ( !empty( $shoppable['cell_class'] ) ) {
                        $cell_class .= ' ' . $shoppable['cell_class'];
                    }
                    if ( !empty( $popup_payload ) && is_array( $shoppable['destination'] ) && !empty( $shoppable['destination']['is_shoppable'] ) && 'popup' === $shoppable['destination']['click_action'] ) {
                        $popup_payload['shoppable_url'] = esc_url_raw( (string) $shoppable['destination']['url'] );
                        $popup_payload['shoppable_button_text'] = sanitize_text_field( (string) $shoppable['destination']['button_text'] );
                        $popup_payload['shoppable_open_new_tab'] = (bool) $shoppable['destination']['open_new_tab'];
                    }
                }
            }
        }
        $alt = $this->resolve_tile_alt_fallback( $post );
        $use_collage = !isset( $tile_options['use_collage'] ) || (bool) $tile_options['use_collage'];
        if ( $use_collage && count( $media ) > 1 ) {
            $tile_options['variant'] = 'tile';
            $tile = MediaCollage::render(
                $this->module,
                $media,
                $permalink,
                $alt,
                $tile_options
            );
        } else {
            $tile = MediaTile::render(
                $this->module,
                $primary,
                $extra_count,
                $permalink,
                $alt,
                $tile_options
            );
        }
        if ( '' === $tile ) {
            return '';
        }
        $popup_attr = '';
        // This entire code block will be removed from the free version.
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
                if ( method_exists( $this, 'instagram_popup_data_attribute__premium_only' ) ) {
                    $popup_attr = $this->instagram_popup_data_attribute__premium_only( $popup_payload );
                    if ( '' !== $popup_attr ) {
                        // Shared modifier only — layout-specific `--popup` classes dual-match
                        // jQuery delegated selectors and open Fancybox twice.
                        $cell_class .= ' esf-instagram-feed__tile-cell--popup';
                    }
                }
            }
        }
        $crawlable_caption = $this->render_crawlable_caption_snippet( $post );
        return '<article class="' . esc_attr( $cell_class ) . '" data-post-id="' . esc_attr( $post->get_id() ) . '"' . $popup_attr . '>' . $tile . $crawlable_caption . '</article>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Tile HTML is escaped; popup_attr is pre-escaped.
    }

    /**
     * Resolve fallback alt text when PostMedia alt is empty.
     *
     * @since 6.9.0
     *
     * @param PostItem $post Post value object.
     * @return string
     */
    protected function resolve_tile_alt_fallback( PostItem $post ) : string {
        $author_name = ( $this->account ? $this->account->get_name() : '' );
        $caption = (string) $post->get_text_html();
        if ( class_exists( '\\EasySocialFeed\\SEO\\SeoHelper' ) ) {
            return \EasySocialFeed\SEO\SeoHelper::derive_media_alt( $caption, $author_name, 'photo' );
        }
        return ( function_exists( 'esf_seo_media_alt' ) ? esf_seo_media_alt( $caption, $author_name, 'photo' ) : $author_name );
    }

    /**
     * Hidden caption snippet for tile layouts (AEO / no-JS crawlability).
     *
     * @since 6.9.0
     *
     * @param PostItem $post Post value object.
     * @return string
     */
    protected function render_crawlable_caption_snippet( PostItem $post ) : string {
        $caption = trim( strip_tags( (string) $post->get_text_html() ) );
        if ( '' === $caption ) {
            return '';
        }
        $words = preg_split(
            '/\\s+/',
            $caption,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        $excerpt = ( is_array( $words ) && count( $words ) > 20 ? implode( ' ', array_slice( $words, 0, 20 ) ) . '…' : $caption );
        if ( class_exists( '\\EasySocialFeed\\SEO\\SeoHelper' ) ) {
            return \EasySocialFeed\SEO\SeoHelper::render_screen_reader_text( $excerpt );
        }
        return '<span class="screen-reader-text">' . esc_html( $excerpt ) . '</span>';
    }

    /**
     * Build MediaTile options from feed settings.
     *
     * @since 6.9.0
     *
     * @return array<string,mixed>
     */
    protected function build_tile_options() : array {
        $hover_overlay = (bool) $this->setting( 'feed.hover_overlay', true );
        $has_plan = function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan();
        $options = array(
            'new_tab'              => (bool) $this->setting( 'feed.links_new_tab', true ),
            'show_overlay'         => $hover_overlay,
            'show_hover_plus'      => $hover_overlay && (bool) $this->setting( 'feed.show_hover_plus', true ),
            'show_media_type_icon' => (bool) $this->setting( 'feed.show_media_type_icon', true ),
            'use_collage'          => (bool) $this->setting( 'feed.show_media_collage', true ),
            'prefer_preview'       => true,
            'show_hover_likes'     => false,
            'show_hover_comments'  => false,
        );
        if ( $has_plan ) {
            $options['show_hover_likes'] = $hover_overlay && (bool) $this->setting( 'feed.hover_show_likes', true );
            $options['show_hover_comments'] = $hover_overlay && (bool) $this->setting( 'feed.hover_show_comments', true );
        }
        return $options;
    }

    /**
     * Build the layout wrapper class list and CSS custom properties.
     *
     * @since 6.9.0
     *
     * @param int  $gap         Tile gap in pixels.
     * @param int  $tile_radius Tile corner radius in pixels.
     * @param bool $tile_shadow Whether per-tile drop shadow is enabled.
     * @return array{class: string, style: string}
     */
    protected function build_tile_shell_attributes( int $gap, int $tile_radius, bool $tile_shadow ) : array {
        $class = $this->get_shell_class() . ' esf-instagram-feed__tiles';
        $settings_slug = $this->get_layout_settings_slug();
        $settings_path = 'layout.' . $settings_slug;
        $parts = array('--esf-ig-gap:' . $gap . 'px', '--esf-ig-tile-radius:' . $tile_radius . 'px');
        foreach ( $this->get_shell_geometry_style_vars() as $var => $value ) {
            $parts[] = $var . ':' . $value;
        }
        foreach ( $this->get_layout_dimension_style_vars( $settings_path ) as $var => $value ) {
            $parts[] = $var . ':' . $value;
        }
        if ( $this->layout_has_feed_height_limit( $settings_path ) ) {
            $class .= ' esf-instagram-feed--has-feed-height';
        }
        $extra_shell_class = trim( $this->get_extra_shell_modifier_classes() );
        if ( '' !== $extra_shell_class ) {
            $class .= ' ' . $extra_shell_class;
        }
        $aspect_ratio = $this->resolve_tile_media_aspect_ratio();
        if ( '1:1' !== $aspect_ratio ) {
            $parts[] = '--esf-ig-tile-ratio:' . $this->tile_media_aspect_ratio_to_css( $aspect_ratio );
        }
        $style = implode( ';', $parts );
        if ( $tile_shadow ) {
            $class .= ' ' . $this->get_tile_shadow_modifier_class() . ' esf-instagram-feed__tiles--tile-shadow';
            $shadow_color = 'rgba(0,0,0,0.12)';
            if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
                $shadow_color = $this->sanitize_tile_shadow_color( (string) $this->setting( $settings_path . '.tile_shadow_color', 'rgba(0,0,0,0.12)' ) );
            }
            $style .= ';--esf-ig-tile-shadow-color:' . $shadow_color;
        }
        $style = $this->append_hover_overlay_style_vars( $style );
        $style = $this->append_video_poster_style_vars( $style );
        return array(
            'class' => $class,
            'style' => $style,
        );
    }

    /**
     * Optional BEM modifier classes appended to the layout shell.
     *
     * @since 6.9.0
     * @return string Space-separated modifier class names.
     */
    protected function get_extra_shell_modifier_classes() : string {
        return '';
    }

    /**
     * Resolve tile media aspect ratio (Pro-only).
     *
     * Tile layouts historically defaulted to square tiles; keep that default for
     * backwards-compatibility and only allow explicit, Pro-gated overrides.
     *
     * @since 6.9.0
     *
     * @return string One of `1:1`, `4:5`, or `3:4`.
     */
    protected function resolve_tile_media_aspect_ratio() : string {
        if ( !function_exists( 'esf_instagram_has_instagram_plan' ) || !esf_instagram_has_instagram_plan() ) {
            return '1:1';
        }
        $slug = $this->get_layout_settings_slug();
        $ratio = (string) $this->setting( 'layout.' . $slug . '.media_aspect_ratio', '1:1' );
        $ratio = trim( $ratio );
        if ( in_array( $ratio, array('1:1', '4:5', '3:4'), true ) ) {
            return $ratio;
        }
        return '1:1';
    }

    /**
     * Convert a ratio token to a CSS aspect-ratio value.
     *
     * @since 6.9.0
     *
     * @param string $ratio Ratio token.
     * @return string CSS `aspect-ratio` value.
     */
    protected function tile_media_aspect_ratio_to_css( string $ratio ) : string {
        switch ( $ratio ) {
            case '4:5':
                return '4 / 5';
            case '3:4':
                return '3 / 4';
            case '1:1':
            default:
                return '1 / 1';
        }
    }

    /**
     * Sanitize a tile shadow color (hex or rgba).
     *
     * @since 6.9.0
     *
     * @param string $color Raw color value.
     * @return string Safe CSS color.
     */
    protected function sanitize_tile_shadow_color( string $color ) : string {
        $color = trim( $color );
        if ( '' === $color ) {
            return 'rgba(0,0,0,0.12)';
        }
        $hex = sanitize_hex_color( $color );
        if ( is_string( $hex ) && '' !== $hex ) {
            return $hex;
        }
        if ( preg_match( '/^#([a-f0-9]{8})$/i', $color ) ) {
            return $this->hex8_to_rgba( $color );
        }
        if ( preg_match( '/^rgba?\\(\\s*[\\d.%]+\\s*,\\s*[\\d.%]+\\s*,\\s*[\\d.%]+(?:\\s*,\\s*[\\d.]+\\s*)?\\)$/i', $color ) ) {
            return $color;
        }
        return 'rgba(0,0,0,0.12)';
    }

    /**
     * Convert #RRGGBBAA to rgba() for inline CSS variables.
     *
     * @since 6.9.0
     *
     * @param string $hex Eight-digit hex color.
     * @return string CSS rgba() or six-digit hex when fully opaque.
     */
    protected function hex8_to_rgba( string $hex ) : string {
        $hex = ltrim( $hex, '#' );
        if ( 8 !== strlen( $hex ) || !ctype_xdigit( $hex ) ) {
            return 'rgba(0,0,0,0.12)';
        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        $a = hexdec( substr( $hex, 6, 2 ) ) / 255;
        if ( $a >= 0.999 ) {
            return sprintf(
                '#%02x%02x%02x',
                $r,
                $g,
                $b
            );
        }
        $a = round( $a, 3 );
        $a_string = rtrim( rtrim( sprintf( '%.3F', $a ), '0' ), '.' );
        return sprintf(
            'rgba(%d, %d, %d, %s)',
            $r,
            $g,
            $b,
            $a_string
        );
    }

    /**
     * Default hover-plus icon size (px) for this layout.
     *
     * @since 6.9.0
     * @return int
     */
    protected function get_default_hover_plus_size() : int {
        return 42;
    }

    /**
     * Default hover-stats text size (px) for this layout.
     *
     * @since 6.9.0
     * @return int
     */
    protected function get_default_hover_stats_size() : int {
        return 14;
    }

    /**
     * Append Pro hover overlay color/size CSS custom properties.
     *
     * @since 6.9.0
     *
     * @param string $style Existing inline style string.
     * @return string
     */
    protected function append_hover_overlay_style_vars( string $style ) : string {
        if ( !function_exists( 'esf_instagram_has_instagram_plan' ) || !esf_instagram_has_instagram_plan() ) {
            return $style;
        }
        $color_map = array(
            'hover_plus_color'  => '--esf-ig-hover-plus-color',
            'hover_stats_color' => '--esf-ig-hover-stats-color',
        );
        foreach ( $color_map as $setting_key => $css_var ) {
            $raw = trim( (string) $this->setting( 'feed.' . $setting_key, '' ) );
            if ( '' !== $raw ) {
                $style .= ';' . $css_var . ':' . $this->sanitize_tile_shadow_color( $raw );
            }
        }
        $size_map = array(
            'hover_plus_size'  => array(
                'var'     => '--esf-ig-hover-plus-size',
                'default' => $this->get_default_hover_plus_size(),
                'min'     => 20,
                'max'     => 72,
            ),
            'hover_stats_size' => array(
                'var'     => '--esf-ig-hover-stats-size',
                'default' => $this->get_default_hover_stats_size(),
                'min'     => 10,
                'max'     => 24,
            ),
        );
        foreach ( $size_map as $setting_key => $config ) {
            $size = (int) $this->setting( 'feed.' . $setting_key, $config['default'] );
            $size = max( $config['min'], min( $config['max'], $size ) );
            $style .= ';' . $config['var'] . ':' . $size . 'px';
        }
        return $style;
    }

    /**
     * Append CSS custom properties for theme-aware video poster tiles.
     *
     * @since 6.9.0
     *
     * @param string $style Existing inline style string.
     * @return string
     */
    protected function append_video_poster_style_vars( string $style ) : string {
        $accent = '';
        if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
            foreach ( array('feed.hover_plus_color', 'feed.load_more_bg_color') as $setting_key ) {
                $raw = trim( (string) $this->setting( $setting_key, '' ) );
                if ( '' !== $raw ) {
                    $accent = $this->sanitize_tile_shadow_color( $raw );
                    break;
                }
            }
        }
        if ( '' !== $accent ) {
            $style .= ';--esf-ig-video-poster-accent:' . $accent;
        }
        return $style;
    }

}
