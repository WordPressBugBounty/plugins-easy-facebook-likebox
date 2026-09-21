<?php

/**
 * Facebook Row layout (Free; wave stagger is Pro).
 *
 * Single horizontal band of equal tiles (flush by default). Text-only posts
 * (status updates with no media) render as caption tiles — same behaviour as
 * X/Twitter Row. Pro wave style: alternating up/down stagger + entrance animation.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts\Layouts
 * @since 6.7.9
 */
namespace EasySocialFeed\Facebook\Layouts\Layouts;

use EasySocialFeed\Layouts\Contracts\PostItem;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Facebook Row layout class.
 *
 * @since 6.7.9
 */
final class Row extends AbstractTileLayout {
    /**
     * Pro wave style: horizontal gap between tiles (px).
     */
    private const WAVE_GAP = 12;

    /**
     * Pro wave style: rounded-square corner radius (px).
     */
    private const WAVE_TILE_RADIUS = 16;

    /**
     * {@inheritdoc}
     */
    protected function get_layout_settings_slug() : string {
        return 'row';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_shell_class() : string {
        $class = 'esf-facebook-feed__row';
        if ( method_exists( $this, 'get_row_wave_shell_modifier__premium_only' ) ) {
            $wave_class = $this->get_row_wave_shell_modifier__premium_only();
            if ( '' !== $wave_class ) {
                $class .= ' ' . $wave_class;
            }
        }
        return $class;
    }

    /**
     * {@inheritdoc}
     */
    protected function get_inner_class() : string {
        return 'esf-facebook-feed__row-inner esf-facebook-feed__tiles-inner';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_cell_class() : string {
        return 'esf-facebook-feed__row-cell esf-facebook-feed__tile-cell';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_tile_shadow_modifier_class() : string {
        return 'esf-facebook-feed__row--tile-shadow';
    }

    /**
     * {@inheritdoc}
     *
     * Competitor-style flush band: zero gap by default.
     */
    protected function get_default_gap() : int {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_tile_radius() : int {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_tile_shadow() : bool {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Gap is free to customize on Row.
     */
    protected function is_gap_free() : bool {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string,string>
     */
    protected function get_shell_geometry_style_vars() : array {
        $cols_desktop = (int) $this->setting( 'layout.row.columns', 6 );
        $cols_tablet = (int) $this->setting( 'layout.row.columns_tablet', 3 );
        $cols_mobile = (int) $this->setting( 'layout.row.columns_mobile', 2 );
        $cols_desktop = max( 1, min( 6, $cols_desktop ) );
        $cols_tablet = max( 1, min( 6, $cols_tablet ) );
        $cols_mobile = max( 1, min( 6, $cols_mobile ) );
        $vars = array(
            '--esf-facebook-cols-desktop'   => (string) $cols_desktop,
            '--esf-facebook-cols-tablet'    => (string) $cols_tablet,
            '--esf-facebook-cols-mobile'    => (string) $cols_mobile,
            '--esf-facebook-row-text-lines' => (string) $this->resolve_text_tile_line_clamp(),
        );
        if ( method_exists( $this, 'apply_row_wave_geometry_vars__premium_only' ) ) {
            $vars = $this->apply_row_wave_geometry_vars__premium_only( $vars );
        }
        return $vars;
    }

    /**
     * Line-clamp for text-only tiles, derived from the active aspect ratio.
     *
     * @since 6.7.9
     * @return int
     */
    private function resolve_text_tile_line_clamp() : int {
        switch ( $this->resolve_tile_media_aspect_ratio() ) {
            case '16:9':
                return 5;
            case '4:5':
                return 10;
            case '3:4':
                return 9;
            case '1:1':
            default:
                return 8;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Wave always uses 1:1. Standard ratios go through the shared Pro gate
     * on {@see AbstractTileLayout::resolve_tile_media_aspect_ratio()}.
     *
     * @since 6.7.9
     * @return string
     */
    protected function resolve_tile_media_aspect_ratio() : string {
        if ( method_exists( $this, 'resolve_row_wave_media_aspect_ratio__premium_only' ) ) {
            $wave_ratio = $this->resolve_row_wave_media_aspect_ratio__premium_only();
            if ( '' !== $wave_ratio ) {
                return $wave_ratio;
            }
        }
        return parent::resolve_tile_media_aspect_ratio();
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_hover_plus_size() : int {
        return 22;
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_hover_stats_size() : int {
        return 10;
    }

    /**
     * {@inheritdoc}
     *
     * Wave forces gap, radius, and shadow so the stagger has breathing room.
     */
    protected function build_tile_shell_attributes( int $gap, int $tile_radius, bool $tile_shadow ) : array {
        if ( method_exists( $this, 'apply_row_wave_shell_presets__premium_only' ) ) {
            $presets = $this->apply_row_wave_shell_presets__premium_only( $gap, $tile_radius, $tile_shadow );
            $gap = $presets['gap'];
            $tile_radius = $presets['tile_radius'];
            $tile_shadow = $presets['tile_shadow'];
        }
        return parent::build_tile_shell_attributes( $gap, $tile_radius, $tile_shadow );
    }

    /**
     * {@inheritdoc}
     *
     * Wave tiles skip hover chrome so the stagger stays readable.
     */
    protected function build_tile_options() : array {
        $options = parent::build_tile_options();
        if ( method_exists( $this, 'apply_row_wave_tile_options__premium_only' ) ) {
            $options = $this->apply_row_wave_tile_options__premium_only( $options );
        }
        return $options;
    }

    /**
     * {@inheritdoc}
     *
     * Text-only posts render as caption tiles (X/Twitter pattern) so status
     * updates stay in the horizontal flow instead of being dropped.
     */
    protected function render_tile( PostItem $post, array $tile_options ) : string {
        $media = $post->get_media();
        $extras = ( method_exists( $post, 'get_extras' ) ? $post->get_extras() : array() );
        if ( empty( $media ) && empty( $extras['album_list'] ) ) {
            return $this->wrap_text_tile_cell( $post, $this->render_text_tile( $post ) );
        }
        return parent::render_tile( $post, $tile_options );
    }

    /**
     * Render a text-only tile so posts without media still appear (X pattern).
     *
     * @since 6.7.9
     * @param PostItem $post Post item.
     * @return string
     */
    protected function render_text_tile( PostItem $post ) : string {
        $raw = (string) $post->get_text_html();
        $text = ( function_exists( 'wp_strip_all_tags' ) ? trim( wp_strip_all_tags( $raw ) ) : trim( strip_tags( $raw ) ) );
        if ( '' === $text ) {
            $text = ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'view_on_facebook', 'View on Facebook' ) : __( 'View on Facebook', 'easy-facebook-likebox' ) );
        }
        $word_limit = (int) $this->setting( 'layout.row.caption_words', 25 );
        $word_limit = max( 5, min( 100, $word_limit ) );
        $words = preg_split(
            '/\\s+/',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ( is_array( $words ) && count( $words ) > $word_limit ) {
            $text = implode( ' ', array_slice( $words, 0, $word_limit ) ) . '…';
        }
        $label = ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'fb_post', 'Post' ) : __( 'Post', 'easy-facebook-likebox' ) );
        $inner = '<span class="esf-facebook-feed__row-text-tile-label">' . esc_html( $label ) . '</span>' . '<span class="esf-facebook-feed__row-text-tile-body">' . esc_html( $text ) . '</span>';
        $popup_payload = $this->resolve_text_tile_popup_payload( $post );
        $has_popup = !empty( $popup_payload );
        $permalink = (string) $post->get_permalink();
        $new_tab = (bool) $this->setting( 'feed.links_new_tab', true );
        $trigger_attr = ( $has_popup ? ' data-esf-facebook-popup-trigger="1"' : '' );
        if ( '' !== $permalink && !$has_popup ) {
            $target = ( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' );
            return '<a class="esf-facebook-feed__row-text-tile" href="' . esc_url( $permalink ) . '"' . $target . '>' . $inner . '</a>';
        }
        return '<div class="esf-facebook-feed__row-text-tile"' . $trigger_attr . '>' . $inner . '</div>';
    }

    /**
     * Wrap a text-only tile and mark the cell for layout CSS + popup.
     *
     * @since 6.7.9
     * @param PostItem $post      Post item.
     * @param string   $tile_html Text tile HTML.
     * @return string
     */
    protected function wrap_text_tile_cell( PostItem $post, string $tile_html ) : string {
        if ( '' === $tile_html ) {
            return '';
        }
        $cell_class = $this->get_cell_class() . ' esf-facebook-feed__row-cell--text esf-facebook-feed__tile-cell--text';
        $popup_payload = $this->resolve_text_tile_popup_payload( $post );
        $popup_attr = '';
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && $this->has_pro_plan() ) {
            if ( method_exists( $this, 'facebook_popup_data_attribute__premium_only' ) ) {
                $popup_attr = $this->facebook_popup_data_attribute__premium_only( $popup_payload );
            }
        } elseif ( method_exists( $this, 'facebook_free_popup_data_attribute' ) ) {
            $popup_attr = $this->facebook_free_popup_data_attribute( $popup_payload );
        }
        if ( '' !== $popup_attr ) {
            $cell_class .= ' esf-facebook-feed__row-cell--popup-text esf-facebook-feed__tile-cell--popup';
        }
        return '<article class="' . esc_attr( $cell_class ) . '" data-post-id="' . esc_attr( $post->get_id() ) . '"' . $popup_attr . '>' . $tile_html . '</article>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Resolve Free or Pro popup payload for a text-only post.
     *
     * @since 6.7.9
     * @param PostItem $post Post item.
     * @return array<string,mixed>|null
     */
    protected function resolve_text_tile_popup_payload( PostItem $post ) : ?array {
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && $this->has_pro_plan() ) {
            if ( method_exists( $this, 'build_facebook_popup_payload__premium_only' ) ) {
                $payload = $this->build_facebook_popup_payload__premium_only( $post );
                return ( is_array( $payload ) ? $payload : null );
            }
        }
        if ( method_exists( $this, 'build_facebook_free_popup_payload' ) ) {
            $payload = $this->build_facebook_free_popup_payload( $post );
            return ( is_array( $payload ) ? $payload : null );
        }
        return null;
    }

}
