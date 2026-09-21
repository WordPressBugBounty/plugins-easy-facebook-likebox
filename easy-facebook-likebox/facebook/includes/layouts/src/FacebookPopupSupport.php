<?php

/**
 * Facebook layout popup/lightbox helpers.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts
 * @since 6.7.9
 */
namespace EasySocialFeed\Facebook\Layouts;

use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Popup support for Facebook layouts.
 *
 * @since 6.7.9
 */
trait FacebookPopupSupport
{
    /**
     * Whether popup/lightbox should be skipped for restricted shared embeds.
     *
     * @since 6.7.9
     *
     * @param PostItem $post Post value object.
     * @return bool
     */
    protected function facebook_popup_is_disabled_for_post( PostItem $post ) : bool {
        if ( !method_exists( $post, 'get_extras' ) ) {
            return false;
        }
        $extras = $post->get_extras();
        if ( !is_array( $extras ) ) {
            return false;
        }
        if ( class_exists( FacebookEmbedSupport::class ) ) {
            return FacebookEmbedSupport::should_disable_popup( $extras );
        }
        return !empty( $extras['embed_restricted'] );
    }

    /**
     * Whether Free simple popup is enabled for the current feed.
     *
     * @since 6.7.9
     * @return bool
     */
    protected function is_facebook_free_popup_enabled() : bool {
        $has_plan = function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan();
        if ( $has_plan ) {
            return false;
        }
        $feed_cfg = $this->setting( 'feed', array() );
        if ( !is_array( $feed_cfg ) ) {
            $feed_cfg = array();
        }
        return !isset( $feed_cfg['show_popup'] ) || !empty( $feed_cfg['show_popup'] );
    }

    /**
     * Build Free simple-popup payload { media, caption, permalink }.
     *
     * @since 6.7.9
     *
     * @param PostItem $post Post value object.
     * @return array<string,mixed>|null
     */
    protected function build_facebook_free_popup_payload( PostItem $post ) : ?array {
        if ( $this->facebook_popup_is_disabled_for_post( $post ) ) {
            return null;
        }
        if ( !$this->is_facebook_free_popup_enabled() ) {
            return null;
        }
        $media_items = array();
        $permalink = esc_url_raw( $post->get_permalink() );
        foreach ( $post->get_media() as $item ) {
            if ( !$item instanceof PostMedia ) {
                continue;
            }
            $poster = $item->get_display_url();
            if ( '' === $poster ) {
                $poster = $item->get_url();
            }
            $video_url = '';
            $video_embed_url = '';
            if ( $item->is_video() ) {
                $video_url = ( method_exists( $item, 'get_video_url' ) ? trim( (string) $item->get_video_url() ) : '' );
                if ( method_exists( $item, 'get_video_embed_url' ) ) {
                    $video_embed_url = trim( (string) $item->get_video_embed_url() );
                }
                // Many Graph video posts have no raw MP4 — fall back to FB plugin embed.
                if ( '' === $video_embed_url && '' !== $permalink && class_exists( '\\EasySocialFeed\\Facebook\\Layouts\\PostMapper' ) ) {
                    $video_embed_url = \EasySocialFeed\Facebook\Layouts\PostMapper::build_facebook_video_embed_url( $permalink );
                }
            }
            $src = $poster;
            if ( $item->is_video() && '' === $src ) {
                $src = ( '' !== $video_url ? $video_url : $video_embed_url );
            }
            if ( '' === $src && '' === $video_url && '' === $video_embed_url ) {
                continue;
            }
            $entry = array(
                'src'  => esc_url_raw( $src ),
                'type' => ( $item->is_video() ? 'video' : 'image' ),
            );
            if ( $item->is_video() ) {
                if ( '' !== $poster ) {
                    $entry['poster'] = esc_url_raw( $poster );
                }
                if ( '' !== $video_url ) {
                    $entry['video_url'] = esc_url_raw( $video_url );
                }
                if ( '' !== $video_embed_url ) {
                    $entry['video_embed_url'] = esc_url_raw( $video_embed_url );
                }
            }
            $media_items[] = $entry;
            break;
        }
        $caption = wp_strip_all_tags( (string) $post->get_text_html() );
        $caption = preg_replace( '/\\s+/u', ' ', $caption );
        $caption = ( is_string( $caption ) ? trim( $caption ) : '' );
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $caption ) > 220 ) {
                $caption = rtrim( mb_substr( $caption, 0, 217 ) ) . '…';
            }
        } elseif ( strlen( $caption ) > 220 ) {
            $caption = rtrim( substr( $caption, 0, 217 ) ) . '…';
        }
        if ( empty( $media_items ) && '' === $caption ) {
            return null;
        }
        if ( function_exists( 'esf_facebook_flag_free_popup_script' ) ) {
            esf_facebook_flag_free_popup_script();
        }
        return array(
            'media'     => ( isset( $media_items[0] ) ? $media_items[0] : null ),
            'caption'   => $caption,
            'permalink' => $permalink,
        );
    }

    /**
     * Render escaped `data-esf-simple-popup` attribute.
     *
     * @since 6.7.9
     *
     * @param array<string,mixed>|null $payload Payload.
     * @return string
     */
    protected function facebook_free_popup_data_attribute( ?array $payload ) : string {
        if ( empty( $payload ) ) {
            return '';
        }
        $json = wp_json_encode( $payload );
        if ( !is_string( $json ) || '' === $json ) {
            return '';
        }
        return ' data-esf-simple-popup="' . esc_attr( $json ) . '"';
    }

}