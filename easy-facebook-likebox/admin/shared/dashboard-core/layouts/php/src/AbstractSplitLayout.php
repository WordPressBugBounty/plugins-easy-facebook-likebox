<?php

/**
 * Shared base for split (half-width) post-card layouts.
 *
 * Unlike MediaTile grids, these layouts render full post cards via
 * {@see \EasySocialFeed\Layouts\Primitives\SplitPostCard}. Module-specific
 * subclasses (Instagram HalfWidth today; Facebook later) only declare BEM
 * class names, settings slug, and optional Pro feature hooks (popup,
 * shoppable, load-more).
 *
 * Kept in the shared Layouts namespace so Facebook can extend it without
 * depending on the Instagram module (which may be disabled).
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts
 * @since 6.9.0
 */
namespace EasySocialFeed\Layouts;

use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\Contracts\RendersTiles;
use EasySocialFeed\Layouts\Primitives\LoadMoreButton;
use EasySocialFeed\Layouts\Primitives\SplitPostCard;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Abstract split / half-width layout.
 *
 * Implements {@see RendersTiles} so Load More can append cards using the same
 * contract as Grid/Row (method name kept for Freemius stripping).
 *
 * @since 6.9.0
 */
abstract class AbstractSplitLayout extends AbstractLayout implements RendersTiles {
    /**
     * Layout settings slug under `settings.layout.{slug}` (e.g. `half_width`).
     *
     * @since 6.9.0
     * @return string
     */
    protected abstract function get_layout_settings_slug() : string;

    /**
     * BEM root class for the layout shell.
     *
     * @since 6.9.0
     * @return string
     */
    protected abstract function get_shell_class() : string;

    /**
     * BEM class for the cards inner wrapper.
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
     * Modifier class when card shadow is enabled.
     *
     * @since 6.9.0
     * @return string
     */
    protected abstract function get_card_shadow_modifier_class() : string;

    /**
     * Default gap between cards in pixels.
     *
     * @since 6.9.0
     * @return int
     */
    protected abstract function get_default_gap() : int;

    /**
     * Default card corner radius in pixels.
     *
     * @since 6.9.0
     * @return int
     */
    protected abstract function get_default_card_radius() : int;

    /**
     * Default card shadow when the setting is unset.
     *
     * @since 6.9.0
     * @return bool
     */
    protected abstract function get_default_card_shadow() : bool;

    /**
     * Whether the current site may use Pro chrome (gap/radius/shadow/etc.).
     *
     * Modules override with their plan helper (e.g. esf_instagram_has_instagram_plan).
     *
     * @since 6.9.0
     * @return bool
     */
    protected abstract function has_pro_plan() : bool;

    /**
     * Render feed-level account header + optional stories markup.
     *
     * @since 6.9.0
     * @return array{header_html:string,stories_row:string,stories_attr:string}
     */
    protected abstract function render_feed_chrome() : array;

    /**
     * Whether Load More is allowed for this feed request.
     *
     * @since 6.9.0
     * @return bool
     */
    protected abstract function can_render_load_more() : bool;

    /**
     * Build SplitPostCard options for one post (may add popup / shoppable).
     *
     * @since 6.9.0
     *
     * @param PostItem $post Post being rendered.
     * @return array{options:array<string,mixed>,cell_class:string,popup_attr:string}
     */
    protected abstract function prepare_split_card( PostItem $post ) : array;

    /**
     * Extra CSS custom properties for the shell.
     *
     * @since 6.9.0
     * @return array<string,string>
     */
    protected function get_shell_geometry_style_vars() : array {
        return array();
    }

    /**
     * Theme color CSS custom properties for the split shell.
     *
     * Empty saved values fall back to layout CSS defaults (no inline override).
     *
     * @since 6.9.0
     * @return array<string,string>
     */
    protected function get_shell_theme_style_vars() : array {
        if ( !$this->has_pro_plan() ) {
            return array();
        }
        $slug = $this->get_layout_settings_slug();
        $path = 'layout.' . $slug . '.';
        $vars = array();
        $bg = $this->resolve_split_setting_color( $path, 'bg_color', array('content_bg_color', 'card_bg_color') );
        if ( '' !== $bg ) {
            $vars['--esf-ig-split-bg'] = $bg;
        }
        $footer_bg = $this->resolve_split_setting_color( $path, 'footer_bg_color' );
        if ( '' !== $footer_bg ) {
            $vars['--esf-ig-split-footer-bg'] = $footer_bg;
        }
        $text = $this->resolve_split_setting_color( $path, 'text_color' );
        if ( '' !== $text ) {
            $vars['--esf-ig-split-text'] = $text;
        }
        $accent = $this->resolve_split_setting_color( $path, 'accent_color', array('caption_link_color') );
        if ( '' !== $accent ) {
            $vars['--esf-ig-primary'] = $accent;
        }
        // Legacy overrides when older feeds customized derived colors directly.
        $muted = $this->resolve_split_setting_color( $path, 'muted_text_color' );
        if ( '' !== $muted ) {
            $vars['--esf-ig-split-muted'] = $muted;
        }
        $border = $this->resolve_split_setting_color( $path, 'border_color' );
        if ( '' !== $border ) {
            $vars['--esf-ig-split-border'] = $border;
        }
        $link = $this->resolve_split_setting_color( $path, 'caption_link_color' );
        if ( '' !== $link ) {
            $vars['--esf-ig-split-link'] = $link;
        }
        $content_bg = $this->resolve_split_setting_color( $path, 'content_bg_color' );
        if ( '' !== $content_bg && (!isset( $vars['--esf-ig-split-bg'] ) || $content_bg !== $vars['--esf-ig-split-bg']) ) {
            $vars['--esf-ig-split-content-bg'] = $content_bg;
        }
        return $vars;
    }

    /**
     * Read and sanitize one split theme color, with optional legacy fallbacks.
     *
     * @since 6.9.0
     *
     * @param string        $path          Settings path prefix (e.g. `layout.half_width.`).
     * @param string        $key           Primary setting key.
     * @param array<string> $legacy_keys   Older keys to read when primary is empty.
     * @return string Sanitized color or empty string.
     */
    protected function resolve_split_setting_color( string $path, string $key, array $legacy_keys = array() ) : string {
        $raw = trim( (string) $this->setting( $path . $key, '' ) );
        if ( '' === $raw ) {
            foreach ( $legacy_keys as $legacy_key ) {
                $raw = trim( (string) $this->setting( $path . $legacy_key, '' ) );
                if ( '' !== $raw ) {
                    break;
                }
            }
        }
        if ( '' === $raw ) {
            return '';
        }
        return $this->sanitize_css_color( $raw );
    }

    /**
     * Map split layout setting keys to CSS custom property names.
     *
     * @since 6.9.0
     * @return array<string,string>
     */
    protected function get_split_theme_var_map() : array {
        return array(
            'bg_color'        => '--esf-ig-split-bg',
            'footer_bg_color' => '--esf-ig-split-footer-bg',
            'text_color'      => '--esf-ig-split-text',
            'accent_color'    => '--esf-ig-primary',
        );
    }

    /**
     * Render the layout HTML.
     *
     * @since 6.9.0
     * @return string
     */
    public function render() : string {
        $chrome = $this->render_feed_chrome();
        $header_html = $chrome['header_html'];
        $stories_row = $chrome['stories_row'];
        $stories_attr = $chrome['stories_attr'];
        $settings_slug = $this->get_layout_settings_slug();
        $gap = $this->get_default_gap();
        $card_radius = $this->get_default_card_radius();
        $card_shadow = false;
        if ( $this->has_pro_plan() ) {
            $gap = (int) $this->setting( 'layout.' . $settings_slug . '.gap', $this->get_default_gap() );
            $card_radius = (int) $this->setting( 'layout.' . $settings_slug . '.card_border_radius', $this->get_default_card_radius() );
            $card_shadow = (bool) $this->setting( 'layout.' . $settings_slug . '.card_shadow', $this->get_default_card_shadow() );
        }
        $gap = max( 0, min( 100, $gap ) );
        $card_radius = max( 0, min( 32, $card_radius ) );
        $shell = $this->build_split_shell_attributes( $gap, $card_radius, $card_shadow );
        $state = $this->get_display_state();
        if ( empty( $state['posts'] ) ) {
            $error_message = trim( (string) $this->setting( 'feed.media_error_message', '' ) );
            if ( '' !== $error_message ) {
                $empty = sprintf( '<div class="esf-%1$s-feed__empty esf-%1$s-feed__empty--error"><p class="esf-%1$s-feed__empty-text">%2$s</p></div>', esc_attr( $this->module ), esc_html( $error_message ) );
            } else {
                $empty = $this->render_empty_state( __( 'No posts to display yet.', 'easy-facebook-likebox' ) );
            }
            return sprintf(
                '<div class="%1$s"%2$s style="%3$s">%4$s%5$s%6$s</div>',
                esc_attr( $shell['class'] ),
                $stories_attr,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped.
                esc_attr( $shell['style'] ),
                $header_html,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $stories_row,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $empty
            );
        }
        $cards = '';
        foreach ( $state['posts'] as $post ) {
            $cards .= $this->render_split_cell( $post );
        }
        $load_more = '';
        // This entire code block will be removed from the free version.
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( $this->can_render_load_more() && method_exists( $this, 'render_split_load_more__premium_only' ) ) {
                $load_more = $this->render_split_load_more__premium_only( count( $state['posts'] ), __( 'Load more', 'easy-facebook-likebox' ), __( 'Load more posts', 'easy-facebook-likebox' ) );
            }
        }
        return sprintf(
            '<div class="%1$s"%2$s style="%3$s">%4$s%5$s<div class="%6$s esf-instagram-feed__items-inner">%7$s</div>%8$s</div>',
            esc_attr( $shell['class'] ),
            $stories_attr,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            esc_attr( $shell['style'] ),
            $header_html,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $stories_row,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            esc_attr( $this->get_inner_class() ),
            $cards,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $load_more
        );
    }

    /**
     * Render one split cell.
     *
     * @since 6.9.0
     *
     * @param PostItem $post Post to render.
     * @return string
     */
    protected function render_split_cell( PostItem $post ) : string {
        $prepared = $this->prepare_split_card( $post );
        $options = ( isset( $prepared['options'] ) && is_array( $prepared['options'] ) ? $prepared['options'] : array() );
        $cell_class = ( isset( $prepared['cell_class'] ) ? (string) $prepared['cell_class'] : $this->get_cell_class() );
        $popup_attr = ( isset( $prepared['popup_attr'] ) ? (string) $prepared['popup_attr'] : '' );
        $card = SplitPostCard::render( $this->module, $post, $options );
        if ( '' === $card ) {
            return '';
        }
        return '<div class="' . esc_attr( $cell_class ) . '" data-post-id="' . esc_attr( $post->get_id() ) . '"' . $popup_attr . '>' . $card . '</div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Card escaped; popup_attr pre-escaped.
    }

    /**
     * Build wrapper class + CSS variables.
     *
     * @since 6.9.0
     *
     * @param int  $gap         Gap in pixels.
     * @param int  $card_radius Radius in pixels.
     * @param bool $card_shadow Whether shadow is enabled.
     * @return array{class:string,style:string}
     */
    protected function build_split_shell_attributes( int $gap, int $card_radius, bool $card_shadow ) : array {
        $class = $this->get_shell_class() . ' esf-' . $this->module . '-feed__split';
        $parts = array('--esf-split-gap:' . $gap . 'px', '--esf-split-card-radius:' . $card_radius . 'px');
        foreach ( $this->get_shell_geometry_style_vars() as $var => $value ) {
            $parts[] = $var . ':' . $value;
        }
        foreach ( $this->get_shell_theme_style_vars() as $var => $value ) {
            $parts[] = $var . ':' . $value;
        }
        $style = implode( ';', $parts );
        if ( $card_shadow ) {
            $class .= ' ' . $this->get_card_shadow_modifier_class() . ' esf-' . $this->module . '-feed__split--card-shadow';
            $shadow_color = 'rgba(0,0,0,0.08)';
            if ( $this->has_pro_plan() ) {
                $shadow_color = $this->sanitize_css_color( (string) $this->setting( 'layout.' . $this->get_layout_settings_slug() . '.card_shadow_color', 'rgba(0,0,0,0.08)' ) );
            }
            $style .= ';--esf-split-card-shadow-color:' . $shadow_color;
        }
        return array(
            'class' => $class,
            'style' => $style,
        );
    }

    /**
     * Sanitize a CSS color (hex or rgba).
     *
     * @since 6.9.0
     *
     * @param string $color Raw color.
     * @return string
     */
    protected function sanitize_css_color( string $color ) : string {
        $color = trim( $color );
        if ( '' === $color ) {
            return 'rgba(0,0,0,0.08)';
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
        return 'rgba(0,0,0,0.08)';
    }

    /**
     * Convert #RRGGBBAA to rgba().
     *
     * @since 6.9.0
     *
     * @param string $hex Eight-digit hex.
     * @return string
     */
    protected function hex8_to_rgba( string $hex ) : string {
        $hex = ltrim( $hex, '#' );
        if ( 8 !== strlen( $hex ) || !ctype_xdigit( $hex ) ) {
            return 'rgba(0,0,0,0.08)';
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
     * Shared SplitPostCard option defaults from feed/post settings.
     *
     * @since 6.9.0
     * @return array<string,mixed>
     */
    protected function build_base_split_options() : array {
        $caption_words = (int) $this->setting( 'layout.' . $this->get_layout_settings_slug() . '.caption_words', 25 );
        $caption_words = max( 5, min( 100, $caption_words ) );
        $see_more = ( function_exists( 'esf_get_translated_string' ) ? __( esf_get_translated_string( 'see_more' ), 'easy-facebook-likebox' ) : __( 'See more', 'easy-facebook-likebox' ) );
        $see_less = ( function_exists( 'esf_get_translated_string' ) ? __( esf_get_translated_string( 'see_less' ), 'easy-facebook-likebox' ) : __( 'See less', 'easy-facebook-likebox' ) );
        $share = ( function_exists( 'esf_get_translated_string' ) ? __( esf_get_translated_string( 'share' ), 'easy-facebook-likebox' ) : __( 'Share', 'easy-facebook-likebox' ) );
        return array(
            'show_header'    => (bool) $this->setting( 'layout.' . $this->get_layout_settings_slug() . '.show_post_header', true ),
            'show_time'      => (bool) $this->setting( 'layout.' . $this->get_layout_settings_slug() . '.show_post_time', true ),
            'show_caption'   => !isset( $this->get_settings()['post']['show_caption'] ) || (bool) $this->setting( 'post.show_caption', true ),
            'caption_words'  => $caption_words,
            'show_likes'     => !isset( $this->get_settings()['post']['show_likes'] ) || (bool) $this->setting( 'post.show_likes', true ),
            'show_comments'  => !isset( $this->get_settings()['post']['show_comments'] ) || (bool) $this->setting( 'post.show_comments', true ),
            'show_share'     => (bool) $this->setting( 'layout.' . $this->get_layout_settings_slug() . '.show_share', true ),
            'new_tab'        => (bool) $this->setting( 'feed.links_new_tab', true ),
            'see_more_label' => $see_more,
            'see_less_label' => $see_less,
            'share_label'    => $share,
            'metric_labels'  => array(
                'likes'    => __( 'Likes', 'easy-facebook-likebox' ),
                'comments' => __( 'Comments', 'easy-facebook-likebox' ),
            ),
            'media_options'  => array(
                'new_tab'              => (bool) $this->setting( 'feed.links_new_tab', true ),
                'show_overlay'         => (bool) $this->setting( 'feed.hover_overlay', true ),
                'show_hover_plus'      => (bool) $this->setting( 'feed.hover_overlay', true ) && (bool) $this->setting( 'feed.show_hover_plus', true ),
                'show_media_type_icon' => (bool) $this->setting( 'feed.show_media_type_icon', true ),
                'use_collage'          => (bool) $this->setting( 'feed.show_media_collage', true ),
                'prefer_preview'       => true,
                'show_hover_likes'     => false,
                'show_hover_comments'  => false,
            ),
        );
    }

}
