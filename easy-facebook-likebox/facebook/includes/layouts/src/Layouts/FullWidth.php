<?php

/**
 * Facebook Full Width layout (Free).
 *
 * Stacked-card layout. Extends shared AbstractStackedLayout.
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
use EasySocialFeed\Layouts\AbstractStackedLayout;
use EasySocialFeed\Layouts\Contracts\PostItem;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Facebook Full Width layout class.
 *
 * @since 6.7.9
 */
final class FullWidth extends AbstractStackedLayout {
    use FacebookHeaderSupport;
    use FacebookHoverOverlaySupport;
    use FacebookLoadMoreSupport;
    use FacebookFooterSupport;
    use FacebookPopupSupport;
    /**
     * {@inheritdoc}
     */
    protected function get_layout_settings_slug() : string {
        return 'full_width';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_shell_class() : string {
        return 'esf-facebook-feed__full-width';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_inner_class() : string {
        return 'esf-facebook-feed__full-width-inner esf-facebook-feed__stacked-inner';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_cell_class() : string {
        return 'esf-facebook-feed__full-width-cell esf-facebook-feed__stacked-cell';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_card_shadow_modifier_class() : string {
        return 'esf-facebook-feed__full-width--card-shadow';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_gap() : int {
        return 16;
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_card_radius() : int {
        return 12;
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_card_shadow() : bool {
        // Free locked off; Pro ON when the setting is unset.
        return $this->has_pro_plan();
    }

    /**
     * {@inheritdoc}
     */
    protected function has_pro_plan() : bool {
        return function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan();
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string,string>
     */
    protected function get_shell_geometry_style_vars() : array {
        $slug = $this->get_layout_settings_slug();
        $scope = $this->setting( 'layout.' . $slug, array() );
        if ( !is_array( $scope ) ) {
            $scope = array();
        }
        if ( !function_exists( 'esf_layout_dimension_style_vars' ) ) {
            return array();
        }
        return esf_layout_dimension_style_vars( $scope, '--esf-facebook' );
    }

    /**
     * Media aspect ratio is Pro on Full Width.
     *
     * Free keeps Original (`auto`); fixed ratios require a Facebook plan.
     *
     * {@inheritdoc}
     *
     * @return string
     */
    protected function resolve_stacked_media_aspect_ratio() : string {
        if ( !$this->has_pro_plan() ) {
            return 'auto';
        }
        $raw = (string) $this->setting( 'layout.' . $this->get_layout_settings_slug() . '.media_aspect_ratio', 'auto' );
        if ( 'auto' === $raw ) {
            return 'auto';
        }
        return parent::resolve_stacked_media_aspect_ratio();
    }

    /**
     * {@inheritdoc}
     *
     * @param string $ratio Aspect ratio slug.
     * @return string
     */
    protected function stacked_media_aspect_ratio_to_css( string $ratio ) : string {
        if ( 'auto' === $ratio ) {
            return 'auto';
        }
        return parent::stacked_media_aspect_ratio_to_css( $ratio );
    }

    /**
     * {@inheritdoc}
     *
     * @param string $ratio Resolved aspect ratio slug.
     * @return string
     */
    protected function get_media_ratio_modifier_class( string $ratio ) : string {
        return ( 'auto' === $ratio ? 'esf-facebook-feed__stacked--media-auto' : '' );
    }

    /**
     * {@inheritdoc}
     *
     * @param int  $gap         Gap in pixels.
     * @param int  $card_radius Radius in pixels.
     * @param bool $card_shadow Whether shadow is enabled.
     * @return array{class:string,style:string}
     */
    protected function build_stacked_shell_attributes( int $gap, int $card_radius, bool $card_shadow ) : array {
        $shell = parent::build_stacked_shell_attributes( $gap, $card_radius, $card_shadow );
        $slug = $this->get_layout_settings_slug();
        $scope = $this->setting( 'layout.' . $slug, array() );
        if ( function_exists( 'esf_layout_has_feed_height_limit' ) && is_array( $scope ) && esf_layout_has_feed_height_limit( $scope ) ) {
            $shell['class'] .= ' esf-facebook-feed--has-feed-height';
        }
        $shell['style'] = $this->append_hover_overlay_style_vars( (string) ($shell['style'] ?? '') );
        return $shell;
    }

    /**
     * {@inheritdoc}
     *
     * @return array{header_html:string,stories_row:string,stories_attr:string}
     */
    protected function render_feed_chrome() : array {
        return array(
            'header_html'  => $this->render_facebook_header(),
            'stories_row'  => '',
            'stories_attr' => '',
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function can_render_load_more() : bool {
        return $this->can_render_facebook_load_more();
    }

    /**
     * {@inheritdoc}
     *
     * @param string $load_more_html Escaped Load More markup.
     * @return string
     */
    protected function compose_feed_footer( string $load_more_html ) : string {
        return $this->compose_facebook_feed_footer( $load_more_html );
    }

    /**
     * {@inheritdoc}
     *
     * @param PostItem $post Post being rendered.
     * @return array{options:array<string,mixed>,cell_class:string,popup_attr:string}
     */
    protected function prepare_stacked_card( PostItem $post ) : array {
        $options = $this->build_base_stacked_options();
        $cell_class = $this->get_cell_class();
        $popup_attr = '';
        $options['metric_labels'] = $this->facebook_metric_labels();
        $options['permalink'] = $post->get_permalink();
        $extras = ( method_exists( $post, 'get_extras' ) ? $post->get_extras() : array() );
        if ( !empty( $extras['is_live'] ) ) {
            $cell_class .= ' esf-facebook-feed__full-width-cell--live';
        }
        if ( !empty( $extras['album_list'] ) ) {
            $cell_class .= ' esf-facebook-feed__full-width-cell--album';
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
        $use_collage = $this->has_pro_plan() && (bool) $this->setting( 'feed.show_media_collage', true );
        $options['media_options']['use_collage'] = $use_collage;
        // Full Width: Smash-style 2+3 collage with large "+N" when 5+ photos.
        if ( $use_collage ) {
            $options['media_options']['max_visible'] = \EasySocialFeed\Layouts\Primitives\MediaCollage::MAX_VISIBLE_FIVE;
            $options['media_options']['more_format'] = 'count';
            $options['media_options']['show_media_type_icon'] = false;
        } elseif ( isset( $extras['carousel_count'] ) && (int) $extras['carousel_count'] > 1 ) {
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
        if ( !empty( $options['show_likes'] ) && class_exists( '\\EasySocialFeed\\Facebook\\Layouts\\FacebookReactionIcons' ) ) {
            $metrics = ( method_exists( $post, 'get_metrics' ) ? $post->get_metrics() : array() );
            $reactions = ( isset( $extras['reactions'] ) && is_array( $extras['reactions'] ) ? $extras['reactions'] : array() );
            $reaction_html = \EasySocialFeed\Facebook\Layouts\FacebookReactionIcons::render_metric_html(
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
        $view_html = $this->render_facebook_view_on_facebook_link( (string) $post->get_permalink(), !empty( $options['new_tab'] ) );
        if ( '' !== $view_html ) {
            $options['footer_extra'] = $view_html;
        }
        $popup_payload = null;
        $popup_attr = '';
        if ( $this->has_pro_plan() && method_exists( $this, 'build_facebook_popup_payload__premium_only' ) ) {
            $popup_payload = $this->build_facebook_popup_payload__premium_only( $post );
            if ( method_exists( $this, 'facebook_popup_data_attribute__premium_only' ) ) {
                $popup_attr = $this->facebook_popup_data_attribute__premium_only( $popup_payload );
            }
        } elseif ( method_exists( $this, 'build_facebook_free_popup_payload' ) ) {
            $popup_payload = $this->build_facebook_free_popup_payload( $post );
            if ( method_exists( $this, 'facebook_free_popup_data_attribute' ) ) {
                $popup_attr = $this->facebook_free_popup_data_attribute( $popup_payload );
            }
        }
        // Text-only posts have no media tile to attach a trigger attribute to,
        // so the whole card becomes the click target instead (like LinkedIn / X).
        $has_media = !empty( $post->get_media() );
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
            'cell_class' => $cell_class,
            'popup_attr' => $popup_attr,
        );
    }

}
