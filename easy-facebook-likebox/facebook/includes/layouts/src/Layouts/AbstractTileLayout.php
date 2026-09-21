<?php

/**
 * Shared base for Facebook MediaTile layouts (Row, Thumbnail, Grid, …).
 *
 * Module-local AbstractTileLayout — shared framework only has stacked/split bases.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts\Layouts
 * @since 6.7.9
 */
namespace EasySocialFeed\Facebook\Layouts\Layouts;

use EasySocialFeed\Facebook\Layouts\FacebookHeaderSupport;
use EasySocialFeed\Facebook\Layouts\FacebookHoverOverlaySupport;
use EasySocialFeed\Facebook\Layouts\FacebookLoadMoreSupport;
use EasySocialFeed\Facebook\Layouts\FacebookFooterSupport;
use EasySocialFeed\Facebook\Layouts\FacebookPopupSupport;
use EasySocialFeed\Facebook\Layouts\FacebookReactionIcons;
use EasySocialFeed\Layouts\AbstractLayout;
use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\Contracts\RendersTiles;
use EasySocialFeed\Layouts\Primitives\MediaCollage;
use EasySocialFeed\Layouts\Primitives\MediaTile;
use EasySocialFeed\Layouts\Primitives\StackedPostCard;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Abstract Facebook tile layout.
 *
 * @since 6.7.9
 */
abstract class AbstractTileLayout extends AbstractLayout implements RendersTiles {
    use FacebookHeaderSupport;
    use FacebookHoverOverlaySupport;
    use FacebookLoadMoreSupport;
    use FacebookFooterSupport;
    use FacebookPopupSupport;
    /**
     * @return string
     */
    protected abstract function get_layout_settings_slug() : string;

    /**
     * @return string
     */
    protected abstract function get_shell_class() : string;

    /**
     * @return string
     */
    protected abstract function get_inner_class() : string;

    /**
     * @return string
     */
    protected abstract function get_cell_class() : string;

    /**
     * @return string
     */
    protected abstract function get_tile_shadow_modifier_class() : string;

    /**
     * @return int
     */
    protected abstract function get_default_gap() : int;

    /**
     * @return int
     */
    protected abstract function get_default_tile_radius() : int;

    /**
     * @return bool
     */
    protected abstract function get_default_tile_shadow() : bool;

    /**
     * @return bool
     */
    protected abstract function is_gap_free() : bool;

    /**
     * @return array<string,string>
     */
    protected abstract function get_shell_geometry_style_vars() : array;

    /**
     * @return bool
     */
    protected function has_pro_plan() : bool {
        return function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan();
    }

    /**
     * {@inheritdoc}
     */
    public function render() : string {
        $header_html = $this->render_facebook_header();
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
        $shell['class'] .= ' esf-facebook-feed__tiles';
        $state = $this->get_display_state();
        if ( empty( $state['posts'] ) ) {
            $empty_message = trim( (string) $this->setting( 'feed.empty_message', '' ) );
            $error_message = trim( (string) $this->setting( 'feed.media_error_message', '' ) );
            if ( '' !== $empty_message ) {
                $empty = $this->render_empty_state( $empty_message );
            } elseif ( '' !== $error_message ) {
                $empty = sprintf( '<div class="esf-facebook-feed__empty esf-facebook-feed__empty--error"><p class="esf-facebook-feed__empty-text">%s</p></div>', esc_html( $error_message ) );
            } else {
                $fallback = ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'fb_no_posts', 'No posts to display yet.' ) : __( 'No posts to display yet.', 'easy-facebook-likebox' ) );
                $empty = $this->render_empty_state( $fallback );
            }
            return sprintf(
                '<div class="%1$s" style="%2$s">%3$s%4$s</div>',
                esc_attr( $shell['class'] ),
                esc_attr( $shell['style'] ),
                $header_html,
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
        $load_more = $this->maybe_render_facebook_load_more( count( $state['posts'] ) );
        $footer = $this->compose_facebook_feed_footer( $load_more );
        return sprintf(
            '<div class="%1$s" style="%2$s">%3$s<div class="%4$s">%5$s</div>%6$s</div>',
            esc_attr( $shell['class'] ),
            esc_attr( $shell['style'] ),
            $header_html,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            esc_attr( $this->get_inner_class() ),
            $tiles,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $footer
        );
    }

    /**
     * @param int  $gap         Gap px.
     * @param int  $tile_radius Radius px.
     * @param bool $tile_shadow Shadow on.
     * @return array{class:string,style:string}
     */
    protected function build_tile_shell_attributes( int $gap, int $tile_radius, bool $tile_shadow ) : array {
        $class = $this->get_shell_class();
        if ( $tile_shadow ) {
            $class .= ' ' . $this->get_tile_shadow_modifier_class();
        }
        $extra = trim( $this->get_extra_shell_modifier_classes() );
        if ( '' !== $extra ) {
            $class .= ' ' . $extra;
        }
        $style_vars = array_merge( array(
            '--esf-facebook-tile-gap'    => $gap . 'px',
            '--esf-facebook-tile-radius' => $tile_radius . 'px',
        ), $this->get_shell_geometry_style_vars() );
        $scope = $this->setting( 'layout.' . $this->get_layout_settings_slug(), array() );
        if ( !is_array( $scope ) ) {
            $scope = array();
        }
        if ( function_exists( 'esf_layout_dimension_style_vars' ) ) {
            $style_vars = array_merge( $style_vars, esf_layout_dimension_style_vars( $scope, '--esf-facebook' ) );
        }
        if ( function_exists( 'esf_layout_has_feed_height_limit' ) && esf_layout_has_feed_height_limit( $scope ) ) {
            $class .= ' esf-facebook-feed--has-feed-height';
        }
        $ratio = $this->resolve_tile_media_aspect_ratio();
        $style_vars['--esf-facebook-tile-ratio'] = $this->tile_media_aspect_ratio_to_css( $ratio );
        if ( $tile_shadow && $this->has_pro_plan() ) {
            $shadow_color = $this->sanitize_tile_shadow_color( (string) $this->setting( 'layout.' . $this->get_layout_settings_slug() . '.tile_shadow_color', 'rgba(0,0,0,0.12)' ) );
            if ( '' !== $shadow_color ) {
                $style_vars['--esf-facebook-tile-shadow-color'] = $shadow_color;
            }
            if ( $this->is_post_cards_mode() ) {
                $class .= ' esf-facebook-feed__stacked--card-shadow';
                if ( '' !== $shadow_color ) {
                    $style_vars['--esf-stacked-card-shadow-color'] = $shadow_color;
                }
            }
        }
        $style = '';
        foreach ( $style_vars as $var => $val ) {
            $style .= esc_attr( $var ) . ':' . esc_attr( $val ) . ';';
        }
        $style = $this->append_hover_overlay_style_vars( $style );
        return array(
            'class' => $class,
            'style' => rtrim( $style, ';' ),
        );
    }

    /**
     * @return string
     */
    protected function get_extra_shell_modifier_classes() : string {
        return '';
    }

    /**
     * Resolve tile media aspect ratio from `layout.{slug}.media_aspect_ratio`.
     *
     * Plan-gated: Free always stays on `1:1`. Subclasses (Row wave, Masonry)
     * may override for layout-specific rules.
     *
     * @since 6.7.9
     * @return string
     */
    protected function resolve_tile_media_aspect_ratio() : string {
        if ( !$this->has_pro_plan() ) {
            return '1:1';
        }
        $slug = $this->get_layout_settings_slug();
        $ratio = trim( (string) $this->setting( 'layout.' . $slug . '.media_aspect_ratio', '1:1' ) );
        if ( in_array( $ratio, array(
            '1:1',
            '4:5',
            '3:4',
            '16:9'
        ), true ) ) {
            return $ratio;
        }
        return '1:1';
    }

    /**
     * @param string $ratio Ratio slug.
     * @return string
     */
    protected function tile_media_aspect_ratio_to_css( string $ratio ) : string {
        switch ( $ratio ) {
            case '4:5':
                return '4 / 5';
            case '3:4':
                return '3 / 4';
            case '16:9':
                return '16 / 9';
            case '1:1':
            default:
                return '1 / 1';
        }
    }

    /**
     * @param string $color Raw color.
     * @return string
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
        if ( preg_match( '/^rgba?\\(\\s*[\\d.%]+\\s*,\\s*[\\d.%]+\\s*,\\s*[\\d.%]+(?:\\s*,\\s*[\\d.]+\\s*)?\\)$/i', $color ) ) {
            return $color;
        }
        return 'rgba(0,0,0,0.12)';
    }

    /**
     * @return array<string,mixed>
     */
    protected function build_tile_options() : array {
        $hover_overlay = (bool) $this->setting( 'feed.hover_overlay', true );
        $has_plan = $this->has_pro_plan();
        $options = array(
            'new_tab'                  => (bool) $this->setting( 'feed.links_new_tab', true ),
            'show_overlay'             => $hover_overlay,
            'show_hover_plus'          => $hover_overlay && (bool) $this->setting( 'feed.show_hover_plus', true ),
            'show_media_type_icon'     => (bool) $this->setting( 'feed.show_media_type_icon', true ),
            'use_collage'              => $has_plan && (bool) $this->setting( 'feed.show_media_collage', true ),
            'prefer_preview'           => true,
            'include_zero_hover_stats' => true,
            'show_hover_likes'         => false,
            'show_hover_comments'      => false,
        );
        if ( $has_plan ) {
            $options['show_hover_likes'] = $hover_overlay && (bool) $this->setting( 'feed.hover_show_likes', false );
            $options['show_hover_comments'] = $hover_overlay && (bool) $this->setting( 'feed.hover_show_comments', false );
        }
        return $options;
    }

    /**
     * @param PostItem            $post         Post.
     * @param array<string,mixed> $tile_options Options.
     * @return string
     */
    protected function render_tile( PostItem $post, array $tile_options ) : string {
        $media = $post->get_media();
        $extras = ( method_exists( $post, 'get_extras' ) ? $post->get_extras() : array() );
        $is_album_list = !empty( $extras['album_list'] );
        if ( empty( $media ) && !$is_album_list ) {
            return $this->wrap_text_tile_cell( $post, $this->render_text_tile( $post ) );
        }
        $primary = ( !empty( $media ) ? $media[0] : null );
        $extra_count = max( 0, count( $media ) - 1 );
        if ( isset( $extras['carousel_count'] ) || isset( $extras['album_total_count'] ) || !empty( $extras['album_has_more'] ) ) {
            $album_opts = \EasySocialFeed\Facebook\Layouts\PostMapper::album_media_options_from_extras( $extras, count( $media ) );
            $extra_count = $album_opts['extra_count'];
            $tile_options['total_count'] = $album_opts['total_count'];
            $tile_options['more_is_minimum'] = $album_opts['more_is_minimum'];
            $tile_options['extra_count'] = $album_opts['extra_count'];
        }
        $metrics = $post->get_metrics();
        $tile_options['likes_count'] = ( isset( $metrics['likes'] ) ? (int) $metrics['likes'] : 0 );
        $tile_options['comments_count'] = ( isset( $metrics['comments'] ) ? (int) $metrics['comments'] : 0 );
        $tile_options['variation_seed'] = $post->get_id();
        $popup_payload = null;
        $permalink = $post->get_permalink();
        $cell_class = $this->get_cell_class();
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && $this->has_pro_plan() ) {
            if ( method_exists( $this, 'build_facebook_popup_payload__premium_only' ) ) {
                $popup_payload = $this->build_facebook_popup_payload__premium_only( $post );
            }
            if ( !empty( $popup_payload ) ) {
                $tile_options['popup_trigger'] = true;
            }
        } elseif ( method_exists( $this, 'build_facebook_free_popup_payload' ) ) {
            $popup_payload = $this->build_facebook_free_popup_payload( $post );
            if ( !empty( $popup_payload ) ) {
                $tile_options['popup_trigger'] = true;
            }
        }
        $alt = $this->resolve_tile_alt_fallback( $post );
        $use_collage = !isset( $tile_options['use_collage'] ) || (bool) $tile_options['use_collage'];
        $tile = '';
        if ( null !== $primary ) {
            if ( $use_collage && count( $media ) > 1 ) {
                $tile_options['variant'] = 'tile';
                $tile_options['show_media_type_icon'] = false;
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
        }
        $album_meta = '';
        if ( $is_album_list && isset( $extras['link_card'] ) && is_array( $extras['link_card'] ) && class_exists( '\\EasySocialFeed\\Facebook\\Layouts\\FacebookLinkCard' ) ) {
            $album_meta = \EasySocialFeed\Facebook\Layouts\FacebookLinkCard::render( $extras['link_card'], array(
                'module'  => 'facebook',
                'new_tab' => !empty( $tile_options['new_tab'] ),
            ) );
        }
        if ( '' === $tile && '' === $album_meta ) {
            return '';
        }
        $popup_attr = '';
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && $this->has_pro_plan() ) {
            if ( method_exists( $this, 'facebook_popup_data_attribute__premium_only' ) ) {
                $popup_attr = $this->facebook_popup_data_attribute__premium_only( $popup_payload );
            }
        } elseif ( method_exists( $this, 'facebook_free_popup_data_attribute' ) ) {
            $popup_attr = $this->facebook_free_popup_data_attribute( $popup_payload );
        }
        if ( '' !== $popup_attr ) {
            $cell_class .= ' esf-facebook-feed__tile-cell--popup';
        }
        if ( !empty( $extras['is_live'] ) ) {
            $cell_class .= ' esf-facebook-feed__tile-cell--live';
        }
        if ( $is_album_list ) {
            $cell_class .= ' esf-facebook-feed__tile-cell--album';
        }
        return '<article class="' . esc_attr( $cell_class ) . '" data-post-id="' . esc_attr( $post->get_id() ) . '"' . $popup_attr . '>' . $tile . $album_meta . '</article>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render a text-only tile so status updates with no media still appear
     * (X / LinkedIn pattern). Word limit comes from the layout's caption_words.
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
        $word_limit = $this->get_text_tile_word_limit();
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
        $inner = '<span class="esf-facebook-feed__text-tile-label">' . esc_html( $label ) . '</span>' . '<span class="esf-facebook-feed__text-tile-body">' . esc_html( $text ) . '</span>';
        $popup_payload = $this->resolve_text_tile_popup_payload( $post );
        $has_popup = !empty( $popup_payload );
        $permalink = (string) $post->get_permalink();
        $new_tab = (bool) $this->setting( 'feed.links_new_tab', true );
        $trigger_attr = ( $has_popup ? ' data-esf-facebook-popup-trigger="1"' : '' );
        if ( '' !== $permalink && !$has_popup ) {
            $target = ( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' );
            return '<a class="esf-facebook-feed__text-tile" href="' . esc_url( $permalink ) . '"' . $target . '>' . $inner . '</a>';
        }
        return '<div class="esf-facebook-feed__text-tile"' . $trigger_attr . '>' . $inner . '</div>';
    }

    /**
     * Word limit for text-only tiles (status updates with no media).
     *
     * Defaults to the layout's `caption_words` setting. Carousel overrides
     * this so CSS line-clamp fills the aspect box (X / LinkedIn pattern).
     *
     * @since 6.7.9
     * @return int
     */
    protected function get_text_tile_word_limit() : int {
        $word_limit = (int) $this->setting( 'layout.' . $this->get_layout_settings_slug() . '.caption_words', 25 );
        return max( 5, min( 100, $word_limit ) );
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
        $cell_class = $this->get_cell_class() . ' esf-facebook-feed__tile-cell--text';
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
            $cell_class .= ' esf-facebook-feed__tile-cell--popup';
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

    /**
     * @param PostItem $post Post.
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
     * @param string $message Empty message.
     * @return string
     */
    protected function render_empty_state( string $message ) : string {
        return sprintf( '<div class="esf-facebook-feed__empty"><p class="esf-facebook-feed__empty-text">%s</p></div>', esc_html( $message ) );
    }

    /**
     * Whether this layout renders legacy-style full post cards.
     *
     * Masonry / Carousel override for `display_mode=post_cards`.
     *
     * @since 6.7.9
     * @return bool
     */
    protected function is_post_cards_mode() : bool {
        return false;
    }

    /**
     * Render one legacy-style stacked post card inside a layout cell.
     *
     * @since 6.7.9
     * @param PostItem $post Post value object.
     * @return string
     */
    protected function render_stacked_post_card_cell( PostItem $post ) : string {
        if ( !class_exists( StackedPostCard::class ) ) {
            return '';
        }
        $prepared = $this->prepare_stacked_post_card( $post );
        $card = StackedPostCard::render( 'facebook', $post, $prepared['options'] );
        if ( '' === $card ) {
            return '';
        }
        $slug = $this->get_layout_settings_slug();
        $cell_class = trim( $this->get_cell_class() . ' esf-facebook-feed__' . $slug . '-cell--post-card ' . $prepared['cell_class'] );
        return sprintf(
            '<article class="%1$s"%2$s>%3$s</article>',
            esc_attr( $cell_class ),
            $prepared['popup_attr'],
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in trait.
            $card
        );
    }

    /**
     * Build StackedPostCard options for Full post cards mode.
     *
     * Mirrors Full Width card plumbing (header, See more, reactions, popup, share).
     *
     * @since 6.7.9
     * @param PostItem $post Post value object.
     * @return array{options:array<string,mixed>,cell_class:string,popup_attr:string}
     */
    protected function prepare_stacked_post_card( PostItem $post ) : array {
        $slug = $this->get_layout_settings_slug();
        $prefix = 'layout.' . $slug . '.';
        $caption_words = max( 5, min( 100, (int) $this->setting( $prefix . 'caption_words', 25 ) ) );
        $see_more = ( function_exists( 'esf_get_translated_string' ) ? __( esf_get_translated_string( 'see_more' ), 'easy-facebook-likebox' ) : __( 'See more', 'easy-facebook-likebox' ) );
        $see_less = ( function_exists( 'esf_get_translated_string' ) ? __( esf_get_translated_string( 'see_less' ), 'easy-facebook-likebox' ) : __( 'See less', 'easy-facebook-likebox' ) );
        $share = ( function_exists( 'esf_get_translated_string' ) ? __( esf_get_translated_string( 'share' ), 'easy-facebook-likebox' ) : __( 'Share', 'easy-facebook-likebox' ) );
        $show_meta = (bool) $this->setting( $prefix . 'show_meta', true );
        $options = array(
            'show_header'      => (bool) $this->setting( $prefix . 'show_post_header', true ),
            'show_time'        => (bool) $this->setting( $prefix . 'show_time', true ),
            'show_caption'     => (bool) $this->setting( $prefix . 'show_caption', true ),
            'caption_words'    => $caption_words,
            'show_likes'       => $show_meta,
            'show_comments'    => $show_meta,
            'show_shares'      => $show_meta,
            'show_share'       => (bool) $this->setting( $prefix . 'show_share', true ),
            'new_tab'          => (bool) $this->setting( 'feed.links_new_tab', true ),
            'see_more_label'   => $see_more,
            'see_less_label'   => $see_less,
            'share_label'      => $share,
            'footer_icon_only' => true,
            'permalink'        => $post->get_permalink(),
            'media_position'   => $this->resolve_post_card_media_position(),
            'metric_labels'    => array(
                'likes'    => ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'fb_likes', 'Likes' ) : __( 'Likes', 'easy-facebook-likebox' ) ),
                'comments' => ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'fb_comments', 'Comments' ) : __( 'Comments', 'easy-facebook-likebox' ) ),
                'shares'   => ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'fb_shares', 'Shares' ) : __( 'Shares', 'easy-facebook-likebox' ) ),
            ),
            'media_options'    => array(
                'new_tab'              => (bool) $this->setting( 'feed.links_new_tab', true ),
                'show_overlay'         => (bool) $this->setting( 'feed.hover_overlay', true ),
                'show_hover_plus'      => (bool) $this->setting( 'feed.hover_overlay', true ) && (bool) $this->setting( 'feed.show_hover_plus', true ),
                'show_media_type_icon' => (bool) $this->setting( 'feed.show_media_type_icon', true ),
                'use_collage'          => false,
                'prefer_preview'       => false,
                'show_hover_likes'     => false,
                'show_hover_comments'  => false,
            ),
        );
        $cell_class = '';
        $popup_attr = '';
        $extras = ( method_exists( $post, 'get_extras' ) ? $post->get_extras() : array() );
        if ( !empty( $extras['is_live'] ) ) {
            $cell_class .= ' esf-facebook-feed__' . $slug . '-cell--live';
        }
        if ( !empty( $extras['album_list'] ) ) {
            $cell_class .= ' esf-facebook-feed__' . $slug . '-cell--album';
            $options['show_likes'] = false;
            $options['show_comments'] = false;
            $options['show_shares'] = false;
        }
        if ( isset( $extras['story_status'] ) && is_string( $extras['story_status'] ) ) {
            $story_status = trim( $extras['story_status'] );
            if ( '' !== $story_status ) {
                $options['name_suffix'] = $story_status;
            }
        }
        if ( isset( $extras['carousel_count'] ) && (int) $extras['carousel_count'] > 1 ) {
            $options['media_options']['show_media_type_icon'] = true;
            $options['media_options']['force_carousel_icon'] = true;
        }
        $this->apply_album_count_media_options( $options['media_options'], $post, $extras );
        if ( isset( $extras['link_card'] ) || !empty( $extras['embed_restricted'] ) ) {
            $link_html = \EasySocialFeed\Facebook\Layouts\FacebookEmbedContent::render_from_extras( $extras, (string) $post->get_permalink(), array(
                'module'  => 'facebook',
                'new_tab' => !empty( $options['new_tab'] ),
                'caption' => ( method_exists( $post, 'get_text_html' ) ? (string) $post->get_text_html() : '' ),
            ) );
            if ( '' !== $link_html ) {
                $options['content_extra'] = $link_html;
            }
        }
        if ( $show_meta && class_exists( FacebookReactionIcons::class ) ) {
            $metrics = ( method_exists( $post, 'get_metrics' ) ? $post->get_metrics() : array() );
            $reactions = ( isset( $extras['reactions'] ) && is_array( $extras['reactions'] ) ? $extras['reactions'] : array() );
            $reaction_html = FacebookReactionIcons::render_metric_html(
                $reactions,
                'facebook',
                'feed',
                array(
                    'total'         => ( isset( $metrics['likes'] ) ? (int) $metrics['likes'] : 0 ),
                    'fallback_like' => true,
                )
            );
            if ( '' !== $reaction_html ) {
                $options['metric_html'] = array(
                    'likes' => $reaction_html,
                );
            }
        }
        $view_html = $this->render_facebook_view_on_facebook_link( (string) $post->get_permalink(), !empty( $options['new_tab'] ), true );
        if ( '' !== $view_html ) {
            $options['footer_extra'] = $view_html;
        }
        $popup_payload = null;
        $has_media = !empty( $post->get_media() );
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && $this->has_pro_plan() ) {
            if ( method_exists( $this, 'build_facebook_popup_payload__premium_only' ) ) {
                $popup_payload = $this->build_facebook_popup_payload__premium_only( $post );
            }
            if ( method_exists( $this, 'facebook_popup_data_attribute__premium_only' ) ) {
                $popup_attr = $this->facebook_popup_data_attribute__premium_only( $popup_payload );
            }
        } elseif ( method_exists( $this, 'build_facebook_free_popup_payload' ) ) {
            $popup_payload = $this->build_facebook_free_popup_payload( $post );
            if ( method_exists( $this, 'facebook_free_popup_data_attribute' ) ) {
                $popup_attr = $this->facebook_free_popup_data_attribute( $popup_payload );
            }
        }
        if ( !empty( $popup_payload ) ) {
            if ( $has_media ) {
                $options['media_options']['popup_trigger'] = true;
            } else {
                $options['card_attrs'] = ' data-esf-facebook-popup-trigger="1"';
            }
        }
        if ( '' !== $popup_attr ) {
            $cell_class .= ' esf-facebook-feed__tile-cell--popup';
            if ( !$has_media ) {
                $cell_class .= ' esf-facebook-feed__stacked-cell--popup-text';
            }
        }
        return array(
            'options'    => $options,
            'cell_class' => trim( $cell_class ),
            'popup_attr' => $popup_attr,
        );
    }

    /**
     * Stacked card media order for Full post cards mode.
     *
     * Pro-only. Free stays on `header_first` (same gate as AbstractStackedLayout).
     *
     * @since 6.7.9
     * @return string `header_first` or `media_first`
     */
    protected function resolve_post_card_media_position() : string {
        if ( !$this->has_pro_plan() ) {
            return 'header_first';
        }
        $raw = (string) $this->setting( 'layout.' . $this->get_layout_settings_slug() . '.media_position', 'header_first' );
        return ( 'media_first' === $raw ? 'media_first' : 'header_first' );
    }

}
